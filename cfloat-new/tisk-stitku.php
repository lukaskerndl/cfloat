<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['logged_in'])) {
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

// Token pro QZ Tray - volá label.php přímo z počítače obsluhy (bez session/cookie
// prohlížeče), takže potřebuje tenhle sdílený token navíc k přihlášení (viz
// _require_login.php / CFLOAT_ALLOW_CRON_TOKEN v label.php).
$__labelAccessToken = '';
foreach ([__DIR__ . '/../secrets/cron_run_token.php', __DIR__ . '/../../secrets/cron_run_token.php'] as $__tp) {
    if (is_file($__tp)) { $__labelAccessToken = (string) include $__tp; break; }
}

// ---------------------------------------------------------------------------
// Stejná logika vyhledání objednávky jako ve staré Tisk štítků (beze změny)
// ---------------------------------------------------------------------------
$ean = isset($_GET['ean']) ? trim($_GET['ean']) : '';
$codOverrideOn  = isset($_GET['cod_override_on']) && (string)$_GET['cod_override_on'] === '1';
$codOverrideRaw = isset($_GET['cod_override']) ? trim((string)$_GET['cod_override']) : '';

$status = '';
$message = '';
$customerName = '';
$isPaid = null;
$paidTextRaw = '';
$codAmount = '';
$printItems = [];
$printItemsError = '';

if ($ean !== '') {
    try {
        $stmt = $pdo->prepare("SELECT id_order, created_at, customer_name, zaplaceno, gopay_zaplaceno,
                gateway_payment_state, payment_name, payment_amount
            FROM orders WHERE number = :number LIMIT 1");
        $stmt->execute([':number' => $ean]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $customerName = trim((string)($row['customer_name'] ?? ''));
            $dbPaid    = isset($row['zaplaceno']) ? ((int)$row['zaplaceno'] === 1) : false;
            $gopayPaid = isset($row['gopay_zaplaceno']) ? ((int)$row['gopay_zaplaceno'] === 1) : false;
            $gwState   = isset($row['gateway_payment_state']) ? (string)$row['gateway_payment_state'] : '';
            $gwNorm    = mb_strtolower($gwState, 'UTF-8');

            if ($dbPaid || $gopayPaid || $gwNorm === 'paid') {
                $isPaid = true; $paidTextRaw = $gwState !== '' ? $gwState : 'paid';
            } else {
                $isPaid = false; $paidTextRaw = $gwState !== '' ? $gwState : 'unpaid';
            }
            if (isset($row['payment_amount']) && $row['payment_amount'] !== null) {
                $codAmount = (string)$row['payment_amount'];
            }

            $status = 'ok';
            $message = 'V pořádku se načetlo.';

            try {
                if (!empty($row['id_order'])) {
                    $stmtItems = $pdo->prepare("SELECT product_number, product_name, variant_description, count, price_total_with_vat
                        FROM order_items WHERE id_order = :id_order ORDER BY id ASC");
                    $stmtItems->execute([':id_order' => (int)$row['id_order']]);
                    $printItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            } catch (Throwable $e) {
                $printItemsError = 'Nepodařilo se načíst položky objednávky: ' . $e->getMessage();
            }
        } else {
            $status = 'notfound';
            $message = 'Objednávka s tímto kódem nebyla nalezena.';
        }
    } catch (Throwable $e) {
        $status = 'error';
        $message = 'Chyba při načítání objednávky z databáze: ' . $e->getMessage();
    }
}

// URL PDF štítku – generuje ho stále stejný, nezměněný label.php ve starém kořeni
$labelUrl = '';
$labelZplUrl = '';
if ($status === 'ok') {
    $codSuffix = $codOverrideOn ? ('&cod_override_on=1&cod_override=' . urlencode($codOverrideRaw)) : '';
    // Token navíc kvůli QZ Tray (viz komentář u $__labelAccessToken výše) - bez
    // něj by label.php vracel 403, protože QZ Tray nemá session prohlížeče.
    $tokenSuffix = $__labelAccessToken !== '' ? ('&token=' . urlencode($__labelAccessToken)) : '';
    $labelUrl = '../label.php?ean=' . urlencode($ean)
        . '&label_format=' . urlencode('A6 on A6')
        . '&gls_printer_type=' . urlencode('Thermo')
        . $codSuffix
        . $tokenSuffix;
    // ZPL varianta – přímý jazyk štítkové tiskárny, nejspolehlivější způsob
    // vyplnění celé plochy štítku. Zkouší se první; když selže/není podporovaná
    // (např. Česká pošta), použije se běžné PDF výše jako záloha.
    $labelZplUrl = '../label.php?ean=' . urlencode($ean) . '&output=zpl' . $codSuffix . $tokenSuffix;
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Tisk štítků – Nový Cfloat</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js"></script>
<style>
:root { --g1:#24d84a; --g2:#00b52a; --ink:#1b1f23; --muted:#6b7280; --border:#e7e9ec; --danger:#d93025; }
* { box-sizing:border-box; }
body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; color:var(--ink); }
.wrap { max-width:640px; margin:0 auto; }
.logo-top { text-align:center; margin-bottom:14px; }
.logo-top img { max-width:150px; height:auto; }
.logo-top a { text-decoration:none; }
.back-link { display:inline-block; color:var(--muted); font-size:12.5px; text-decoration:none; border:1px solid var(--border); border-radius:999px; padding:6px 14px; margin-bottom:14px; }
.back-link:hover { background:#fff; }
h1 { font-size:20px; margin:0 0 14px; font-weight:800; }
.card { background:#fff; border:1px solid var(--border); border-radius:16px; padding:20px; margin-bottom:16px; }
label { font-size:12px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; display:block; margin-bottom:5px; }
input[type=text] { width:100%; padding:11px 14px; border:1px solid var(--border); border-radius:10px; font-size:15px; }
.btn-full { width:100%; margin-top:14px; background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; border:none; border-radius:12px; padding:13px; font-size:14px; font-weight:800; cursor:pointer; }
.btn-full:hover { filter:brightness(1.05); }
.cod-wrap { margin-top:12px; }
.cod-hint { font-size:11.5px; color:var(--muted); margin-top:4px; }
.checkbox-row { display:flex; align-items:center; gap:8px; margin-top:12px; cursor:pointer; user-select:none; font-size:13.5px; }

.msg { border-radius:12px; padding:12px 16px; font-size:13px; margin-top:14px; }
.msg-ok { background:#eafbf0; color:#0a7a34; border:1px solid #bdeccb; }
.msg-error { background:#fdeceb; color:var(--danger); border:1px solid #f5c6c2; }
.msg-warn { background:#fff8e6; color:#8a5a00; border:1px solid #ffe1a8; }

.print-status { display:flex; align-items:center; gap:10px; padding:14px 16px; border-radius:12px; margin-top:14px; font-size:13.5px; font-weight:600; }
.print-status.pending { background:#f0f4ff; color:#33459a; border:1px solid #cbd6ff; }
.print-status.ok { background:#eafbf0; color:#0a7a34; border:1px solid #bdeccb; }
.print-status.error { background:#fdeceb; color:var(--danger); border:1px solid #f5c6c2; }
.spinner { width:16px; height:16px; border:2.5px solid rgba(0,0,0,0.15); border-top-color: currentColor; border-radius:50%; animation:spin .7s linear infinite; flex-shrink:0; }
@keyframes spin { to { transform: rotate(360deg); } }

.detail-row { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid var(--border); font-size:13.5px; }
.detail-row:last-child { border-bottom:none; }
.detail-row .label { color:var(--muted); font-weight:600; }
.badge { display:inline-block; padding:2px 9px; border-radius:999px; font-size:11.5px; font-weight:700; }
.badge-paid { background:#eafbf0; color:#0a7a34; }
.badge-unpaid { background:#fdeceb; color:var(--danger); }

table { width:100%; border-collapse:collapse; font-size:12.5px; margin-top:10px; }
th { text-align:left; font-size:10.5px; text-transform:uppercase; color:var(--muted); padding:6px 8px; border-bottom:2px solid var(--border); }
td { padding:6px 8px; border-bottom:1px solid var(--border); }

.btn-secondary { display:inline-block; margin-top:10px; background:#f7f8f9; color:var(--ink); border:1px solid var(--border); border-radius:10px; padding:9px 16px; font-size:13px; font-weight:700; text-decoration:none; cursor:pointer; }
.btn-secondary:hover { background:#eee; }
</style>
</head>
<body>
<div class="wrap">
    <div class="logo-top"><a href="index.php"><img src="../logo-1.png" alt="C-Store.cz"></a></div>
    <a class="back-link" href="index.php">&larr; Zpět na Nový Cfloat</a>
    <a class="back-link" href="zasilky.php" style="margin-left:8px;">📦 Zásilky (seznam + tracking)</a>
    <h1>Tisk štítků</h1>

    <div class="card">
        <form method="get" action="tisk-stitku.php" id="lookupForm">
            <label for="ean">EAN / číslo objednávky</label>
            <div style="position:relative;">
                <input type="text" id="ean" name="ean" value="<?php echo h($ean); ?>" autofocus autocomplete="off" style="padding-right:44px;">
                <button type="button" id="clearEanBtn" title="Vymazat" aria-label="Vymazat"
                    style="position:absolute; right:6px; top:50%; transform:translateY(-50%); width:32px; height:32px; border:none; background:#eee; border-radius:8px; font-size:16px; line-height:1; cursor:pointer; color:#666;">✕</button>
            </div>

            <label class="checkbox-row">
                <input type="checkbox" id="cod_override_on" name="cod_override_on" value="1" <?php echo $codOverrideOn ? 'checked' : ''; ?>>
                Upravit dobírku pro štítek
            </label>
            <div class="cod-wrap" id="cod_override_wrap" style="<?php echo $codOverrideOn ? '' : 'display:none;'; ?>">
                <label for="cod_override">Dobírka do štítku (CZK)</label>
                <input type="text" id="cod_override" name="cod_override" value="<?php echo h($codOverrideRaw); ?>" placeholder="např. 0">
                <div class="cod-hint">Zadej 0 pro vypnutí dobírky (i když je v objednávce dobírka).</div>
            </div>

            <button type="submit" class="btn-full">NAČÍST OBJEDNÁVKU</button>
        </form>

        <?php if ($message !== ''): ?>
            <div class="msg <?php echo $status === 'ok' ? 'msg-ok' : 'msg-error'; ?>"><?php echo h($message); ?></div>
        <?php endif; ?>

        <?php if ($status === 'ok'): ?>
            <div style="margin-top:14px;">
                <div class="detail-row"><span class="label">Objednávka</span><span><?php echo h($ean); ?></span></div>
                <div class="detail-row"><span class="label">Zákazník</span><span><?php echo $customerName !== '' ? h($customerName) : '—'; ?></span></div>
                <div class="detail-row">
                    <span class="label">Platba</span>
                    <span>
                    <?php if ($isPaid === true): ?><span class="badge badge-paid">Zaplaceno</span>
                    <?php elseif ($isPaid === false): ?><span class="badge badge-unpaid">Nezaplaceno</span>
                    <?php else: ?>Neznámý stav (<?php echo h($paidTextRaw); ?>)<?php endif; ?>
                    </span>
                </div>
                <?php if ($isPaid === false && $codAmount !== ''): ?>
                    <div class="detail-row"><span class="label">Dobírka</span><span><?php echo h($codAmount); ?></span></div>
                <?php endif; ?>
            </div>

            <div id="printStatus"></div>

            <?php if (!empty($printItems)): ?>
                <table>
                    <thead><tr><th>Kód</th><th>Produkt</th><th>Varianta</th><th>Ks</th><th>Řádek (s DPH)</th></tr></thead>
                    <tbody>
                    <?php foreach ($printItems as $it): ?>
                        <tr>
                            <td><?php echo h($it['product_number']); ?></td>
                            <td><?php echo h($it['product_name']); ?></td>
                            <td><?php echo h($it['variant_description']); ?></td>
                            <td><?php echo (float)$it['count']; ?></td>
                            <td><?php echo number_format((float)$it['price_total_with_vat'], 2, ',', ' '); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    // Podepisování QZ Tray požadavků nastavujeme hned při načtení stránky
    // (musí to proběhnout PŘED websocket.connect(), jinak QZ Tray bere
    // spojení jako nepodepsané a chová se nespolehlivě / se zasekává).
    if (typeof qz !== 'undefined') {
        qz.security.setCertificatePromise(function(resolve, reject) {
            fetch('qz-cert/digital-certificate.txt')
                .then(function(response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.text();
                })
                .then(resolve)
                .catch(function(e) { console.error('QZ certifikát se nepodařilo načíst:', e); reject(e); });
        });
        qz.security.setSignatureAlgorithm('SHA512');
        qz.security.setSignaturePromise(function(toSign) {
            return function(resolve, reject) {
                fetch('qz-cert/sign-message.php?request=' + encodeURIComponent(toSign))
                    .then(function(response) {
                        if (!response.ok) throw new Error('HTTP ' + response.status);
                        return response.text();
                    })
                    .then(function(sig) {
                        if (!sig) { throw new Error('sign-message.php vrátil prázdnou odpověď'); }
                        resolve(sig);
                    })
                    .catch(function(e) { console.error('QZ podpis se nepodařilo vytvořit:', e); reject(e); });
            };
        });
    }

    var codChk = document.getElementById('cod_override_on');
    var codWrap = document.getElementById('cod_override_wrap');
    if (codChk && codWrap) {
        codChk.addEventListener('change', function () {
            codWrap.style.display = this.checked ? '' : 'none';
        });
    }

    var clearBtn = document.getElementById('clearEanBtn');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            window.location.href = 'tisk-stitku.php';
        });
    }
    var eanInputEl = document.getElementById('ean');
    if (eanInputEl) {
        eanInputEl.addEventListener('focus', function () { this.select(); });
    }

    var status = <?php echo json_encode($status); ?>;
    var labelUrl = <?php echo json_encode($labelUrl); ?>;
    var labelZplUrl = <?php echo json_encode($labelZplUrl); ?>;
    var input = document.getElementById('ean');

    if (status !== 'ok' && input) {
        input.focus();
        input.select();
        return;
    }

    // Objednávka se úspěšně načetla (tisk běží na pozadí) – pole rovnou
    // vyčistíme a zaostříme, ať jde hned zadat/naskenovat další číslo,
    // bez čekání na kliknutí nebo na dokončení tisku.
    if (status === 'ok' && input) {
        input.value = '';
        input.focus();
    }

    if (status !== 'ok' || !labelUrl) return;

    var statusBox = document.getElementById('printStatus');

    function renderStatus(kind, html) {
        statusBox.innerHTML = '<div class="print-status ' + kind + '">' + html + '</div>';
    }

    function fallbackButtons(reason) {
        renderStatus('error',
            (reason ? ('⚠ ' + reason + '<br>') : '') +
            '<div style="margin-top:6px;">' +
            '<a class="btn-secondary" href="' + labelUrl + '" target="_blank" rel="noopener">Otevřít PDF a vytisknout ručně</a>' +
            '</div>'
        );
    }

    async function autoPrintToZebra() {
        renderStatus('pending', '<span class="spinner"></span> Připojuji se k tiskárně…');

        if (typeof qz === 'undefined') {
            fallbackButtons('QZ Tray knihovna se nenačetla (offline / blokováno).');
            return;
        }

        // Podepisování je už nastavené výše (hned při načtení stránky).

        try {
            if (!qz.websocket.isActive()) {
                await Promise.race([
                    qz.websocket.connect(),
                    new Promise(function(_, reject) {
                        setTimeout(function() { reject(new Error('Vypršel časový limit připojení (60 s)')); }, 60000);
                    })
                ]);
            }
        } catch (e) {
            console.error('QZ connect chyba:', e);
            fallbackButtons('QZ Tray neběží na tomto počítači, spojení bylo zamítnuto, nebo vypršel časový limit. Nainstaluj a spusť QZ Tray (qz.io), pak zkus znovu. (' + (e && e.message ? e.message : e) + ')');
            return;
        }

        var printers;
        try {
            printers = await qz.printers.find();
        } catch (e) {
            fallbackButtons('Nepodařilo se načíst seznam tiskáren.');
            return;
        }

        var zebra = (printers || []).find(function(p) {
            return typeof p === 'string' && p.toUpperCase().indexOf('ZD') === 0;
        });

        if (!zebra) {
            fallbackButtons('Tiskárna začínající "ZD" není teď připojená/nalezená (nalezeno: ' + (printers && printers.length ? printers.join(', ') : 'žádná') + ').');
            return;
        }

        renderStatus('pending', '<span class="spinner"></span> Tisknu na ' + zebra + '…');

        try {
            // PDF má správný vzhled (diakritika, celé jméno) a teď už i
            // rozumnou velikost (A6 on A6 / GLS Thermo) – ZPL zůstává
            // vypnuté, mělo problém s kódováním češtiny.
            var config = qz.configs.create(zebra);
            await qz.print(config, [{ type: 'pdf', data: labelUrl }]);
            renderStatus('ok', '✓ Štítek odeslán na tiskárnu ' + zebra + '.<br><div style="margin-top:8px;"><a class="btn-secondary" href="tisk-stitku.php">Nová objednávka →</a></div>');
        } catch (e) {
            fallbackButtons('Tisk selhal: ' + (e && e.message ? e.message : e));
        }
    }

    autoPrintToZebra();
})();
</script>
</body>
</html>
