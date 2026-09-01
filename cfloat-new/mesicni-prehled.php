<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth_guard.php';
if (!$loggedIn) {
    header('Location: ../index.php');
    exit;
}

$cfgCandidates = [__DIR__ . '/../config.php', __DIR__ . '/../../config.php'];
$pdo = null;
foreach ($cfgCandidates as $p) {
    if (is_file($p)) { require $p; break; }
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Chybí config.php nebo $pdo.');
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_kc($amount) { return number_format((float)$amount, 2, ',', ' ') . ' Kč'; }

// ---------------------------------------------------------------------------
// Heslo modulu (samostatné, stejné jako u staré verze – sdílená session,
// takže pokud jsi ho odemkl na staré stránce, funguje to i tady a naopak).
// Heslo se řeší přes sdílené secrets/admin_login.php (viz _auth_guard.php).
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'monthly_login') {
    $pw = (string)($_POST['monthly_pass'] ?? '');
    if (cfloat_auth_verify_and_migrate($__cfloatAuthData, 'monthly_pass_hash', 'monthly_pass_bootstrap', $pw, $__cfloatAuthFile)) {
        $_SESSION['monthly_ok'] = true;
    } else {
        $_SESSION['monthly_ok'] = false;
        $_SESSION['monthly_error'] = 'Neplatné heslo.';
    }
    header('Location: mesicni-prehled.php');
    exit;
}

// ---------------------------------------------------------------------------
// AJAX – okamžité ukládání nákladů (stejná logika/tabulka jako stará verze)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['monthly_cost_create', 'monthly_cost_update', 'monthly_cost_delete'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['monthly_ok'])) {
        echo json_encode(['ok' => false, 'error' => 'Neautorizováno (měsíční přehled).']);
        exit;
    }
    try {
        $pdo->query("SELECT 1 FROM monthly_costs LIMIT 1");
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Tabulka monthly_costs neexistuje nebo k ní nemáš přístup.']);
        exit;
    }

    $action = (string)$_POST['action'];
    $y = (int)($_POST['y'] ?? 0);
    $m = (int)($_POST['m'] ?? 0);

    if ($action === 'monthly_cost_create') {
        if ($y < 2000 || $y > 2100 || $m < 1 || $m > 12) { echo json_encode(['ok' => false, 'error' => 'Neplatný rok/měsíc.']); exit; }
        try {
            $st = $pdo->prepare("INSERT INTO monthly_costs (start_year, start_month, description, amount, carry) VALUES (:y,:m,'',0,0)");
            $st->execute([':y' => $y, ':m' => $m]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Nelze vytvořit řádek nákladu: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'monthly_cost_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Neplatné ID.']); exit; }
        try {
            $st = $pdo->prepare("DELETE FROM monthly_costs WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Nelze smazat náklad: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'monthly_cost_update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Neplatné ID.']); exit; }
        $desc = trim((string)($_POST['description'] ?? ''));
        $amountRaw = str_replace([' ', ','], ['', '.'], (string)($_POST['amount'] ?? '0'));
        $amount = (float)$amountRaw;
        $carry = ((int)($_POST['carry'] ?? 0)) ? 1 : 0;
        try {
            $st = $pdo->prepare("UPDATE monthly_costs SET description=:d, amount=:a, carry=:c WHERE id=:id LIMIT 1");
            $st->execute([':d' => $desc, ':a' => $amount, ':c' => $carry, ':id' => $id]);
            if ($carry === 1 && $y >= 2000 && $m >= 1) {
                $ny = $y; $nm = $m + 1;
                if ($nm > 12) { $ny++; $nm = 1; }
                if (in_array($ny, [2025, 2026, 2027], true)) {
                    $chk = $pdo->prepare("SELECT id FROM monthly_costs WHERE start_year=:y AND start_month=:m AND description=:d LIMIT 1");
                    $chk->execute([':y' => $ny, ':m' => $nm, ':d' => $desc]);
                    if (!$chk->fetchColumn()) {
                        $ins = $pdo->prepare("INSERT INTO monthly_costs (start_year, start_month, description, amount, carry) VALUES (:y,:m,:d,:a,:c)");
                        $ins->execute([':y' => $ny, ':m' => $nm, ':d' => $desc, ':a' => $amount, ':c' => $carry]);
                    }
                }
            }
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Nelze uložit náklad: ' . $e->getMessage()]);
        }
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Neznámá akce.']);
    exit;
}

// ---------------------------------------------------------------------------
// Zisk ze zboží za období – shodná logika jako stará verze
// ---------------------------------------------------------------------------
function mp_goods_profit(PDO $pdo, string $from, string $to): array {
    try {
        $sql = "SELECT
                COALESCE(SUM(CASE WHEN oi.nakupni_cena IS NOT NULL AND oi.nakupni_cena > 0
                    THEN (oi.price_total_with_vat - (oi.nakupni_cena * (CASE WHEN oi.`count` IS NULL OR oi.`count`=0 THEN 1 ELSE oi.`count` END)))
                    ELSE 0 END), 0) AS profit,
                COALESCE(SUM(CASE WHEN oi.nakupni_cena IS NULL OR oi.nakupni_cena <= 0 THEN 1 ELSE 0 END), 0) AS missing_cnt
            FROM orders o JOIN order_items oi ON oi.id_order = o.id_order
            WHERE o.created_at >= :from AND o.created_at <= :to";
        $st = $pdo->prepare($sql);
        $st->execute([':from' => $from, ':to' => $to]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['profit' => (float)($row['profit'] ?? 0), 'missing' => (int)($row['missing_cnt'] ?? 0)];
    } catch (Throwable $e) {
        return ['profit' => 0.0, 'missing' => 0];
    }
}

// ---------------------------------------------------------------------------
// Výběr roku / měsíce
// ---------------------------------------------------------------------------
$allowedYears = [2025, 2026, 2027];
$monthlyYear = (int)($_GET['y'] ?? date('Y'));
if (!in_array($monthlyYear, $allowedYears, true)) $monthlyYear = (int)date('Y');
$monthlyMonth = (int)($_GET['m'] ?? date('n'));
if ($monthlyMonth < 1 || $monthlyMonth > 12) $monthlyMonth = (int)date('n');

$months = [1=>'Leden',2=>'Únor',3=>'Březen',4=>'Duben',5=>'Květen',6=>'Červen',7=>'Červenec',8=>'Srpen',9=>'Září',10=>'Říjen',11=>'Listopad',12=>'Prosinec'];

$monthlyError = '';
$monthlyTableReady = true;
try { $pdo->query("SELECT 1 FROM monthly_costs LIMIT 1"); } catch (Throwable $e) { $monthlyTableReady = false; }

$monthlyErrorMsg = '';
if (!empty($_SESSION['monthly_error'])) { $monthlyErrorMsg = (string)$_SESSION['monthly_error']; unset($_SESSION['monthly_error']); }

$data = null;

if (!empty($_SESSION['monthly_ok'])) {
    $tz = new DateTimeZone('Europe/Prague');

    // rozsah vybraného měsíce
    $start = new DateTime(sprintf('%04d-%02d-01 00:00:00', $monthlyYear, $monthlyMonth), $tz);
    $end = clone $start; $end->modify('last day of this month 23:59:59');
    $monthlyFrom = $start->format('Y-m-d H:i:s');
    $monthlyTo = $end->format('Y-m-d H:i:s');

    // dnešek / včerejšek (vždy aktuální, bez ohledu na vybraný měsíc nahoře)
    $t0 = new DateTime('today 00:00:00', $tz); $t1 = new DateTime('today 23:59:59', $tz);
    $y0 = new DateTime('yesterday 00:00:00', $tz); $y1 = new DateTime('yesterday 23:59:59', $tz);

    $goodsMonth = mp_goods_profit($pdo, $monthlyFrom, $monthlyTo);
    $goodsToday = mp_goods_profit($pdo, $t0->format('Y-m-d H:i:s'), $t1->format('Y-m-d H:i:s'));
    $goodsYesterday = mp_goods_profit($pdo, $y0->format('Y-m-d H:i:s'), $y1->format('Y-m-d H:i:s'));

    // objednávky + obrat za měsíc
    $ordersCount = 0; $turnover = 0.0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(o.total_price_with_vat),0) AS t FROM orders o WHERE o.created_at >= :f AND o.created_at <= :to2");
        $st->execute([':f' => $monthlyFrom, ':to2' => $monthlyTo]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $ordersCount = (int)($row['c'] ?? 0);
        $turnover = (float)($row['t'] ?? 0);
    } catch (Throwable $e) {}

    // náklady za měsíc (+ auto-přenos ze zaškrtnutých položek)
    $costsRows = [];
    $costsTotal = 0.0;
    if ($monthlyTableReady) {
        try {
            $ym = ($monthlyYear * 100) + $monthlyMonth;
            $copy = $pdo->prepare("
                INSERT INTO monthly_costs (start_year, start_month, description, amount, carry)
                SELECT ?, ?, mc.description, mc.amount, mc.carry FROM monthly_costs mc
                JOIN (SELECT description, MAX((start_year*100)+start_month) AS maxym FROM monthly_costs
                    WHERE carry=1 AND ((start_year*100)+start_month) < ? GROUP BY description) t
                    ON t.description = mc.description AND ((mc.start_year*100)+mc.start_month) = t.maxym
                WHERE NOT EXISTS (SELECT 1 FROM monthly_costs x WHERE x.start_year=? AND x.start_month=? AND x.description=mc.description)
            ");
            $copy->execute([$monthlyYear, $monthlyMonth, $ym, $monthlyYear, $monthlyMonth]);

            $st = $pdo->prepare("SELECT id, start_year, start_month, description, amount, carry FROM monthly_costs WHERE start_year=:y AND start_month=:m ORDER BY id ASC");
            $st->execute([':y' => $monthlyYear, ':m' => $monthlyMonth]);
            $costsRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($costsRows as $r) $costsTotal += (float)($r['amount'] ?? 0);
        } catch (Throwable $e) {
            $monthlyError = 'Chyba při načítání nákladů: ' . $e->getMessage();
        }
    }

    $netProfit = $goodsMonth['profit'] - $costsTotal;

    $data = [
        'goodsMonth' => $goodsMonth, 'goodsToday' => $goodsToday, 'goodsYesterday' => $goodsYesterday,
        'ordersCount' => $ordersCount, 'turnover' => $turnover,
        'costsRows' => $costsRows, 'costsTotal' => $costsTotal, 'netProfit' => $netProfit,
    ];
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Měsíční přehled – Nový Cfloat</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root { --g1:#24d84a; --g2:#00b52a; --ink:#1b1f23; --muted:#6b7280; --border:#e7e9ec; --danger:#d93025; }
* { box-sizing:border-box; }
body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#fafbfb; margin:0; padding:16px 12px 60px; color:var(--ink); }
.wrap { max-width:1000px; margin:0 auto; }
.logo-top { text-align:center; margin-bottom:12px; }
.logo-top img { max-width:130px; }
.logo-top a { text-decoration:none; }
.back-link { display:inline-block; color:var(--muted); font-size:12.5px; text-decoration:none; border:1px solid var(--border); border-radius:999px; padding:6px 14px; margin-bottom:12px; }
h1 { font-size:20px; margin:0 0 14px; font-weight:800; }
.card { background:#fff; border:1px solid var(--border); border-radius:16px; padding:18px; margin-bottom:14px; }

input[type=password] { padding:11px 14px; border:1px solid var(--border); border-radius:10px; font-size:15px; width:100%; }
.btn { border:none; border-radius:999px; padding:11px 20px; font-size:14px; font-weight:800; cursor:pointer; }
.btn-green { background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; width:100%; margin-top:10px; }
.btn-mini { border:none; border-radius:999px; padding:6px 12px; font-size:12px; font-weight:700; cursor:pointer; background:#eee; color:#333; }
.msg-error { background:#fdeceb; color:var(--danger); border:1px solid #f5c6c2; border-radius:12px; padding:10px 14px; font-size:13px; margin-bottom:12px; }

.year-tabs { display:flex; gap:8px; margin-bottom:10px; overflow-x:auto; padding-bottom:2px; }
.year-tab { flex-shrink:0; padding:8px 16px; border-radius:999px; border:1.5px solid var(--border); color:var(--muted); text-decoration:none; font-weight:700; font-size:13px; }
.year-tab.active { background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; border-color:transparent; }

.month-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:7px; margin-bottom:16px; }
.month-btn { text-align:center; padding:10px 4px; border-radius:10px; border:1.5px solid var(--border); color:var(--ink); text-decoration:none; font-weight:700; font-size:12.5px; }
.month-btn.active { background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; border-color:transparent; }
@media (max-width:480px) { .month-grid { grid-template-columns:repeat(3, 1fr); } }

.section-label { font-size:11px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; margin:18px 0 8px; }
.section-label:first-child { margin-top:0; }

.kpi-grid { display:grid; grid-template-columns:repeat(2, 1fr); gap:10px; }
@media (min-width:600px) { .kpi-grid.wide { grid-template-columns:repeat(3, 1fr); } }
.kpi { background:var(--bg,#f7f8f9); border-radius:14px; padding:14px; }
.kpi.today { background:#eafbf0; border:1px solid #bdeccb; }
.kpi.service { background:#f0f4ff; border:1px solid #cbd6ff; }
.kpi .lbl { font-size:11px; color:var(--muted); font-weight:700; text-transform:uppercase; letter-spacing:.02em; margin-bottom:4px; }
.kpi .val { font-size:18px; font-weight:800; }
.kpi .val.positive { color:#0a7a34; }
.kpi .val.negative { color:var(--danger); }
.kpi .sub { font-size:10.5px; color:var(--muted); margin-top:3px; }

.net-card { background:linear-gradient(135deg,var(--g1),var(--g2)); border-radius:16px; padding:20px; color:#fff; margin-bottom:14px; }
.net-card .lbl { font-size:12px; opacity:.9; font-weight:700; text-transform:uppercase; letter-spacing:.03em; }
.net-card .val { font-size:30px; font-weight:900; margin-top:4px; }
.net-card .val.negative { color:#fff3cd; }

.missing-note { color:#b30000; font-weight:700; font-size:12.5px; margin-top:8px; }

.costs-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; }
.costs-head h2 { font-size:15px; margin:0; }
.cost-row { display:grid; grid-template-columns:1fr 110px 32px; gap:8px; align-items:center; padding:8px 0; border-bottom:1px solid var(--border); }
.cost-row input[type=text] { border:1px solid var(--border); border-radius:8px; padding:7px 9px; font-size:13px; width:100%; }
.cost-row input[type=text].amount { text-align:right; }
.cost-row .del-btn { background:#fdeceb; color:var(--danger); border:none; border-radius:8px; width:30px; height:30px; cursor:pointer; font-weight:800; }
.carry-row { display:flex; align-items:center; gap:6px; font-size:11px; color:var(--muted); margin-top:2px; grid-column:1/-1; }
.costs-total { display:flex; justify-content:space-between; font-weight:800; font-size:14px; margin-top:10px; padding-top:10px; border-top:2px solid var(--border); }
</style>
</head>
<body>
<div class="wrap">
    <div class="logo-top"><a href="index.php"><img src="../logo-1.png" alt="C-Store.cz"></a></div>
    <a class="back-link" href="index.php">&larr; Zpět na Nový Cfloat</a>
    <h1>Měsíční přehled</h1>

    <?php if ($monthlyErrorMsg !== ''): ?><div class="msg-error"><?php echo h($monthlyErrorMsg); ?></div><?php endif; ?>
    <?php if ($monthlyError !== ''): ?><div class="msg-error"><?php echo h($monthlyError); ?></div><?php endif; ?>

    <?php if (empty($_SESSION['monthly_ok'])): ?>
        <div class="card">
            <p style="font-size:13px;color:var(--muted);margin-top:0;">Pro vstup do modulu zadej heslo.</p>
            <form method="post" action="mesicni-prehled.php">
                <input type="hidden" name="action" value="monthly_login">
                <input type="password" name="monthly_pass" placeholder="Heslo" autocomplete="current-password" required>
                <button type="submit" class="btn btn-green">VSTOUPIT</button>
            </form>
        </div>
    <?php else: ?>

        <div class="card">
            <div class="year-tabs">
                <?php foreach ($allowedYears as $yy): ?>
                    <a class="year-tab <?php echo $yy === $monthlyYear ? 'active' : ''; ?>" href="mesicni-prehled.php?y=<?php echo $yy; ?>&m=<?php echo $monthlyMonth; ?>"><?php echo $yy; ?></a>
                <?php endforeach; ?>
            </div>
            <div class="month-grid">
                <?php foreach ($months as $mm => $label): ?>
                    <a class="month-btn <?php echo $mm === $monthlyMonth ? 'active' : ''; ?>" href="mesicni-prehled.php?y=<?php echo $monthlyYear; ?>&m=<?php echo $mm; ?>"><?php echo h($label); ?></a>
                <?php endforeach; ?>
            </div>

            <?php if ($data): ?>
                <?php
                $missingTotal = (int)$data['goodsMonth']['missing'];
                ?>
                <?php if ($missingTotal > 0): ?>
                    <div class="missing-note">⚠ <?php echo $missingTotal; ?> položek bez nákupní ceny (nepočítá se do zisku).</div>
                <?php endif; ?>

                <div class="section-label">Aktuální k dnešnímu dni</div>
                <div class="kpi-grid wide">
                    <div class="kpi today">
                        <div class="lbl">Dnešní den: Zisk ze zboží</div>
                        <div class="val positive"><?php echo fmt_kc($data['goodsToday']['profit']); ?></div>
                    </div>
                    <div class="kpi today">
                        <div class="lbl">Včerejší den: Zisk ze zboží</div>
                        <div class="val"><?php echo fmt_kc($data['goodsYesterday']['profit']); ?></div>
                    </div>
                </div>

                <div class="section-label"><?php echo h($months[$monthlyMonth]); ?> <?php echo $monthlyYear; ?> – souhrn</div>
                <div class="kpi-grid wide">
                    <div class="kpi"><div class="lbl">Objednávek</div><div class="val"><?php echo (int)$data['ordersCount']; ?></div></div>
                    <div class="kpi"><div class="lbl">Obrat</div><div class="val"><?php echo fmt_kc($data['turnover']); ?></div></div>
                    <div class="kpi"><div class="lbl">Zisk ze zboží</div><div class="val positive"><?php echo fmt_kc($data['goodsMonth']['profit']); ?></div></div>
                    <div class="kpi"><div class="lbl">Náklady celkem</div><div class="val negative"><?php echo fmt_kc($data['costsTotal']); ?></div></div>
                </div>

                <div class="net-card">
                    <div class="lbl">Čistý zisk (zisk ze zboží − náklady)</div>
                    <div class="val <?php echo $data['netProfit'] < 0 ? 'negative' : ''; ?>"><?php echo fmt_kc($data['netProfit']); ?></div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($monthlyTableReady && $data): ?>
        <div class="card">
            <div class="costs-head">
                <h2>Náklady (ukládají se okamžitě)</h2>
                <button type="button" class="btn-mini" id="btn-add-cost" style="background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;">+ Přidat</button>
            </div>
            <div id="costs-list">
                <?php foreach ($data['costsRows'] as $r): ?>
                    <?php
                    $sid = (int)($r['id'] ?? 0);
                    $sy = (int)($r['start_year'] ?? 0); $sm = (int)($r['start_month'] ?? 0);
                    $isCarried = !empty($r['carry']) && (($sy*100+$sm) < ($monthlyYear*100+$monthlyMonth));
                    ?>
                    <div class="cost-row" data-id="<?php echo $sid; ?>">
                        <input type="text" class="js-desc" value="<?php echo h($r['description'] ?? ''); ?>" placeholder="Popis nákladu">
                        <input type="text" class="js-amount amount" value="<?php echo h(number_format((float)($r['amount'] ?? 0), 2, ',', '')); ?>" placeholder="0,00">
                        <button type="button" class="del-btn js-delete">✕</button>
                        <div class="carry-row">
                            <label><input type="checkbox" class="js-carry" <?php echo !empty($r['carry']) ? 'checked' : ''; ?>> Přenášet do dalších měsíců</label>
                            <?php if ($isCarried): ?><span>(od <?php echo sprintf('%02d/%04d', $sm, $sy); ?>)</span><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="costs-total"><span>Celkem náklady</span><span id="costs-total-display"><?php echo fmt_kc($data['costsTotal']); ?></span></div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(function() {
    var Y = <?php echo (int)$monthlyYear; ?>, M = <?php echo (int)$monthlyMonth; ?>;
    var list = document.getElementById('costs-list');
    var addBtn = document.getElementById('btn-add-cost');
    var totalDisplay = document.getElementById('costs-total-display');

    function recalcTotal() {
        if (!list || !totalDisplay) return;
        var total = 0;
        list.querySelectorAll('.js-amount').forEach(function(inp) {
            var v = parseFloat((inp.value || '0').replace(/\s/g,'').replace(',', '.')) || 0;
            total += v;
        });
        totalDisplay.textContent = total.toLocaleString('cs-CZ', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' Kč';
    }

    function post(action, extra) {
        var body = new URLSearchParams(Object.assign({action: action, y: Y, m: M}, extra || {}));
        return fetch('mesicni-prehled.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body})
            .then(function(r) { return r.json(); });
    }

    var saveTimers = {};
    function scheduleSave(row) {
        var id = row.getAttribute('data-id');
        clearTimeout(saveTimers[id]);
        saveTimers[id] = setTimeout(function() {
            var desc = row.querySelector('.js-desc').value;
            var amount = row.querySelector('.js-amount').value;
            var carry = row.querySelector('.js-carry').checked ? 1 : 0;
            post('monthly_cost_update', {id: id, description: desc, amount: amount, carry: carry});
        }, 500);
    }

    if (list) {
        list.addEventListener('input', function(e) {
            if (e.target.classList.contains('js-amount')) recalcTotal();
            var row = e.target.closest('.cost-row');
            if (row) scheduleSave(row);
        });
        list.addEventListener('change', function(e) {
            if (e.target.classList.contains('js-carry')) {
                var row = e.target.closest('.cost-row');
                if (row) scheduleSave(row);
            }
        });
        list.addEventListener('click', function(e) {
            if (e.target.classList.contains('js-delete')) {
                var row = e.target.closest('.cost-row');
                if (!row) return;
                var id = row.getAttribute('data-id');
                if (!confirm('Smazat tento náklad?')) return;
                post('monthly_cost_delete', {id: id}).then(function(res) {
                    if (res.ok) { row.remove(); recalcTotal(); }
                });
            }
        });
    }

    if (addBtn) {
        addBtn.addEventListener('click', function() {
            post('monthly_cost_create', {}).then(function(res) {
                if (!res.ok) { alert(res.error || 'Chyba'); return; }
                var div = document.createElement('div');
                div.className = 'cost-row';
                div.setAttribute('data-id', res.id);
                div.innerHTML =
                    '<input type="text" class="js-desc" placeholder="Popis nákladu">' +
                    '<input type="text" class="js-amount amount" placeholder="0,00">' +
                    '<button type="button" class="del-btn js-delete">✕</button>' +
                    '<div class="carry-row"><label><input type="checkbox" class="js-carry"> Přenášet do dalších měsíců</label></div>';
                list.appendChild(div);
                div.querySelector('.js-desc').focus();
            });
        });
    }
})();
</script>
</body>
</html>
