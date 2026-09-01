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

require_once __DIR__ . '/lib/price_engine_xml_only.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$action = $_POST['action'] ?? '';
$runLog = [];
$result = null;
$feedDebug = null;
$feedDebugSupplier = null;

/** Rekurzivně vypíše strukturu elementu jako "tag: hodnota" (description zkrátí, ať nezahltí výpis). */
function cfloat2_debug_dump_element(DOMElement $el, int $depth = 0): string
{
    $out = '';
    $indent = str_repeat('  ', $depth);
    foreach ($el->attributes ?? [] as $attr) {
        $out .= $indent . '@' . $attr->nodeName . ' = ' . substr((string)$attr->nodeValue, 0, 120) . "\n";
    }
    foreach ($el->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE) continue;
        $hasElementChildren = false;
        foreach ($child->childNodes as $gc) {
            if ($gc->nodeType === XML_ELEMENT_NODE) { $hasElementChildren = true; break; }
        }
        if ($hasElementChildren) {
            $out .= $indent . '<' . $child->nodeName . ">\n";
            $out .= cfloat2_debug_dump_element($child, $depth + 1);
        } else {
            $val = trim((string)$child->textContent);
            if (strtolower($child->nodeName) === 'description') {
                $val = substr($val, 0, 150) . (strlen($val) > 150 ? '…(zkráceno)' : '');
            } else {
                $val = substr($val, 0, 300);
            }
            $out .= $indent . $child->nodeName . ' = ' . $val . "\n";
        }
    }
    return $out;
}

/**
 * Stáhne danou URL a vrátí diagnostické info: HTTP kód, počet bajtů,
 * náhled začátku, syrové XML prvního nalezeného záznamu a kolik
 * EAN/kódů s cenou z něj dokáže aktuální parser rozpoznat.
 */
function cfloat2_debug_fetch(string $url, string $tmpFilename, string $supplierForParser): array
{
    $debug = ['ok' => false];
    if (!function_exists('curl_init')) {
        $debug['error'] = 'Na serveru chybí rozšíření cURL – nelze feed stáhnout.';
        return $debug;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'CFloat-New-Price-XML-Only/2.0-debug',
        CURLOPT_HTTPHEADER => ['Accept: application/xml,text/xml,*/*'],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $debug['http_code'] = $http;
    $debug['curl_error'] = $err;
    $debug['bytes'] = $body !== false ? strlen($body) : 0;

    if ($body === false || $http >= 400 || $http === 0) {
        $debug['error'] = 'Stažení selhalo (HTTP ' . $http . ($err !== '' ? ', ' . $err : '') . ').';
        return $debug;
    }

    $debug['ok'] = true;
    $debug['preview'] = substr($body, 0, 1500);

    $tmpPath = cfloat2_tmp_dir() . '/' . $tmpFilename;
    @file_put_contents($tmpPath, $body);

    $parsed = cfloat2_parse_supplier_file($supplierForParser, $tmpPath);
    $debug['parsed_ean_count'] = count($parsed['by_ean']);
    $debug['parsed_code_count'] = count($parsed['by_code']);

    // Najdeme první element, který má aspoň jednoho "elementového" potomka
    // (tj. pravděpodobně skutečný záznam produktu, ne jen obalový <export>/<brand>),
    // a vypíšeme jeho kompletní strukturu polí.
    $doc = new DOMDocument('1.0', 'UTF-8');
    $sampleTags = [];
    $dump = '(nenalezen)';
    if (@$doc->load($tmpPath, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE)) {
        $xpath = new DOMXPath($doc);
        // zkusíme typické názvy záznamů, jinak vezmeme nejhlubší opakující se element
        $candidates = ['item', 'product', 'zbozi', 'polozka', 'sku', 'variant'];
        $found = null;
        foreach ($candidates as $tag) {
            $nodes = $xpath->query("//*[local-name()='{$tag}']");
            if ($nodes && $nodes->length > 0) { $found = $nodes->item(0); break; }
        }
        if ($found instanceof DOMElement) {
            $dump = '<' . $found->nodeName . ">\n" . cfloat2_debug_dump_element($found, 1);
        }

        // ukázka první 3 úrovní struktury (obalové elementy)
        $walker = $doc->documentElement;
        $d = 0;
        while ($walker instanceof DOMElement && $d < 4) {
            $sampleTags[] = str_repeat('  ', $d) . $walker->nodeName;
            $next = null;
            foreach ($walker->childNodes as $c) {
                if ($c->nodeType === XML_ELEMENT_NODE) { $next = $c; break; }
            }
            $walker = $next;
            $d++;
        }
    }
    $debug['first_record_xml'] = $dump;
    $debug['first_tags'] = $sampleTags;

    return $debug;
}

if ($action === 'debug_silvini') {
    @set_time_limit(120);
    $feedDebugSupplier = 'SILVINI';
    $feedDebug = cfloat2_debug_fetch(CFLOAT2_SILVINI_URL, 'silvini_debug.xml', 'SILVINI');
}

if ($action === 'debug_sportimport') {
    @set_time_limit(120);
    $feedDebugSupplier = 'SPORTIMPORT';
    $feedDebug = cfloat2_debug_fetch(CFLOAT2_SPORTIMPORT_URL, 'sportimport_debug.xml', 'SPORTIMPORT');
}

if ($action === 'archive_only' || $action === 'fill_prices') {
    @set_time_limit(300);

    $built = cfloat2_build_current_index();
    $currentIndex = $built['index'];
    $runLog = $built['log'];

    if ($action === 'fill_prices') {
        $limit = 500; // ochrana proti přetížení sdíleného hostingu, spusťte klidně vícekrát
        $from = trim((string)($_POST['from'] ?? ''));
        $to   = trim((string)($_POST['to'] ?? ''));

        $where = "(oi.nakupni_cena IS NULL OR oi.nakupni_cena = 0)
                  AND ((oi.EAN IS NOT NULL AND TRIM(oi.EAN) <> '') OR (oi.product_number IS NOT NULL AND TRIM(oi.product_number) <> ''))";
        $params = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where .= ' AND o.created_at >= :d_from';
            $params[':d_from'] = $from . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where .= ' AND o.created_at <= :d_to';
            $params[':d_to'] = $to . ' 23:59:59';
        }

        $sql = "SELECT oi.id, oi.EAN, oi.product_number
                FROM order_items oi
                INNER JOIN orders o ON o.id_order = oi.id_order
                WHERE {$where}
                ORDER BY o.created_at DESC
                LIMIT {$limit}";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $updateStmt = $pdo->prepare("UPDATE order_items SET nakupni_cena = :price WHERE id = :id");

        $found = [];
        $notFound = [];
        $archiveParseCache = [];

        foreach ($rows as $row) {
            $ean = $row['EAN'] ?? null;
            $code = $row['product_number'] ?? null;
            $hit = cfloat2_lookup_price($ean, $code, $currentIndex, $archiveParseCache);
            if ($hit !== null) {
                $updateStmt->execute([':price' => round($hit['price'], 2), ':id' => (int)$row['id']]);
                $found[] = [
                    'id' => (int)$row['id'],
                    'ean' => $ean,
                    'code' => $code,
                    'price' => round($hit['price'], 2),
                    'supplier' => $hit['supplier'],
                    'source' => $hit['source'],
                ];
            } else {
                $notFound[] = ['id' => (int)$row['id'], 'ean' => $ean, 'code' => $code];
            }
        }

        // kolik jich celkem ještě čeká (bez ohledu na LIMIT výše)
        $countSql = "SELECT COUNT(*) FROM order_items oi
                     INNER JOIN orders o ON o.id_order = oi.id_order
                     WHERE {$where}";
        $countSt = $pdo->prepare($countSql);
        $countSt->execute($params);
        $stillMissing = (int)$countSt->fetchColumn();

        $result = [
            'processed' => count($rows),
            'found' => $found,
            'not_found' => $notFound,
            'still_missing' => $stillMissing,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Doplnění nákupních cen z XML feedů – Nový Cfloat</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root { --g1:#24d84a; --g2:#00b52a; }
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#fff; margin:0; padding:24px 16px 48px; }
        .wrap { max-width:980px; margin:0 auto; }
        h1 { font-size:20px; color:var(--g2); margin-bottom:4px; }
        .sub { color:#666; font-size:12.5px; margin-bottom:20px; }
        .box { border:1.5px solid #e2e2e2; border-radius:12px; padding:16px 18px; margin-bottom:18px; }
        .box h2 { font-size:14px; margin:0 0 8px; }
        .box p { font-size:12.5px; color:#555; line-height:1.5; margin:0 0 12px; }
        label { font-size:12px; color:#444; display:block; margin-bottom:3px; }
        input[type=date] { padding:6px 8px; border:1px solid #ccc; border-radius:8px; font-size:13px; }
        .row { display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap; margin-bottom:12px; }
        button {
            background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; border:none;
            border-radius:999px; padding:10px 20px; font-size:13px; font-weight:700; cursor:pointer;
        }
        button.secondary { background:#eee; color:#333; }
        button:hover { filter:brightness(1.05); }
        .log { background:#f7f7f7; border-radius:10px; padding:10px 14px; font-size:11.5px; color:#444; white-space:pre-wrap; margin-top:10px; }
        table { border-collapse:collapse; width:100%; font-size:12px; margin-top:10px; }
        th, td { border:1px solid #eee; padding:5px 8px; text-align:left; }
        th { background:#fafafa; }
        .ok { color:var(--g2); font-weight:700; }
        .miss { color:#c0392b; font-weight:700; }
        .back-link { display:inline-block; margin-bottom:16px; color:#666; font-size:12px; text-decoration:none; border:1px solid #ccc; border-radius:999px; padding:6px 14px; }
        .back-link:hover { background:#f2f2f2; }
        .summary { font-size:13px; margin:10px 0; }
    </style>
</head>
<body>
<div class="wrap">
    <a class="back-link" href="index.php">&larr; Zpět na Nový Cfloat</a>
    <h1>Doplnění nákupní ceny – pouze z XML feedů</h1>
    <div class="sub">
        Zdroje: DEVOLD, VAVRYS a SILVINI (živý XML feed) a ISADORE (MOC × 0,60). Žádná DB cache tabulka se nepoužívá.
        Historie feedů se automaticky zálohuje, aby šlo dohledat cenu i u zboží, které mezitím dodavatel z feedu odebral.
    </div>

    <div class="box">
        <h2>1) Jen zálohovat aktuální feedy</h2>
        <p>Stáhne / načte aktuální feedy DEVOLD, VAVRYS, SILVINI a ISADORE a pokud se obsah od poslední zálohy liší, uloží novou časovou zálohu. Nic se přitom nezapisuje do objednávek.</p>
        <form method="post">
            <input type="hidden" name="action" value="archive_only">
            <button type="submit" class="secondary">Zálohovat feedy nyní</button>
        </form>
    </div>

    <div class="box">
        <h2>2) Zálohovat feedy a doplnit chybějící nákupní ceny</h2>
        <p>Nejprve se stejně jako výše zazálohují aktuální feedy, poté se pro objednávky (volitelně omezené obdobím) bez nákupní ceny zkusí najít cena podle EAN, pak podle objednacího čísla – nejdřív v aktuálním feedu, pak v historii záloh. Zpracovává se max. 500 položek na jedno spuštění (kvůli limitům hostingu) – při větším počtu spusťte akci vícekrát.</p>
        <form method="post">
            <input type="hidden" name="action" value="fill_prices">
            <div class="row">
                <div>
                    <label for="from">Od data objednávky (nepovinné)</label>
                    <input type="date" id="from" name="from" value="<?php echo h($_POST['from'] ?? ''); ?>">
                </div>
                <div>
                    <label for="to">Do data objednávky (nepovinné)</label>
                    <input type="date" id="to" name="to" value="<?php echo h($_POST['to'] ?? ''); ?>">
                </div>
                <button type="submit">Zálohovat a doplnit ceny</button>
            </div>
        </form>
    </div>

    <div class="box">
        <h2>3) Diagnostika SILVINI feedu</h2>
        <p>Stáhne živý feed a ukáže, jestli stažení funguje, kolik EAN/kódů se z něj podařilo rozpoznat, a jak vypadá první záznam v surovém XML – podle toho se dá přesně doladit parsování.</p>
        <form method="post">
            <input type="hidden" name="action" value="debug_silvini">
            <button type="submit" class="secondary">Otestovat SILVINI feed</button>
        </form>
    </div>

    <div class="box">
        <h2>4) Diagnostika SportImport feedu</h2>
        <p>SportImport zatím NENÍ zapojen do doplňování cen – neznám skutečnou strukturu jejich XML (feed jsem odtud nemohl stáhnout). Stáhne živý feed <code><?php echo h(CFLOAT2_SPORTIMPORT_URL); ?></code> a ukáže první záznam, ať podle něj doladím správná pole a vzorec ceny.</p>
        <form method="post">
            <input type="hidden" name="action" value="debug_sportimport">
            <button type="submit" class="secondary">Otestovat SportImport feed</button>
        </form>
    </div>

    <?php if ($feedDebug !== null): ?>
        <div class="box">
            <h2>Výsledek diagnostiky <?php echo h($feedDebugSupplier); ?></h2>
            <?php if (empty($feedDebug['ok'])): ?>
                <div class="log">CHYBA: <?php echo h($feedDebug['error'] ?? 'neznámá chyba'); ?>
HTTP kód: <?php echo h($feedDebug['http_code'] ?? '?'); ?></div>
            <?php else: ?>
                <div class="summary">
                    Staženo bajtů: <b><?php echo (int)$feedDebug['bytes']; ?></b> &nbsp;|&nbsp;
                    Rozpoznáno EAN s cenou: <b><?php echo (int)$feedDebug['parsed_ean_count']; ?></b> &nbsp;|&nbsp;
                    Rozpoznáno kódů s cenou: <b><?php echo (int)$feedDebug['parsed_code_count']; ?></b>
                </div>
                <p style="font-size:12px;color:#555;">Struktura (první 3 elementy):</p>
                <div class="log"><?php echo h(implode("\n", $feedDebug['first_tags'] ?? [])); ?></div>
                <p style="font-size:12px;color:#555;margin-top:10px;">První záznam (surové XML):</p>
                <div class="log"><?php echo h($feedDebug['first_record_xml']); ?></div>
                <p style="font-size:12px;color:#555;margin-top:10px;">Náhled začátku feedu:</p>
                <div class="log"><?php echo h($feedDebug['preview']); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($runLog)): ?>
        <div class="box">
            <h2>Log zálohování feedů</h2>
            <div class="log"><?php echo h(implode("\n", $runLog)); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <div class="box">
            <h2>Výsledek doplnění cen</h2>
            <div class="summary">
                Zpracováno položek: <b><?php echo (int)$result['processed']; ?></b> &nbsp;|&nbsp;
                <span class="ok">Doplněno: <?php echo count($result['found']); ?></span> &nbsp;|&nbsp;
                <span class="miss">Nenalezeno: <?php echo count($result['not_found']); ?></span> &nbsp;|&nbsp;
                Celkem stále bez ceny (bez ohledu na limit): <b><?php echo (int)$result['still_missing']; ?></b>
            </div>

            <?php if (!empty($result['found'])): ?>
                <table>
                    <thead><tr><th>ID položky</th><th>EAN</th><th>Kód</th><th>Doplněná cena</th><th>Dodavatel</th><th>Zdroj</th></tr></thead>
                    <tbody>
                    <?php foreach ($result['found'] as $r): ?>
                        <tr>
                            <td><?php echo (int)$r['id']; ?></td>
                            <td><?php echo h($r['ean']); ?></td>
                            <td><?php echo h($r['code']); ?></td>
                            <td><?php echo number_format((float)$r['price'], 2, ',', ' '); ?> Kč</td>
                            <td><?php echo h($r['supplier']); ?></td>
                            <td><?php echo h($r['source']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!empty($result['not_found'])): ?>
                <h2 style="margin-top:16px;">Nenalezeno (ani v aktuálním feedu, ani v záloze)</h2>
                <table>
                    <thead><tr><th>ID položky</th><th>EAN</th><th>Kód</th></tr></thead>
                    <tbody>
                    <?php foreach ($result['not_found'] as $r): ?>
                        <tr>
                            <td><?php echo (int)$r['id']; ?></td>
                            <td><?php echo h($r['ean']); ?></td>
                            <td><?php echo h($r['code']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
