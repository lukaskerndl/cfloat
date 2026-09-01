<?php
declare(strict_types=1);

/**
 * cfloat-new/schindler/diagnostika-ciselniky.php
 *
 * ČISTĚ DIAGNOSTICKÝ nástroj (jen čtení, nic nezapisuje). Vypíše syrové
 * odpovědi API pro číselníky a jejich hodnoty, ať zjistíme:
 *  - jaká pole vlastně ProductDial a ProductDialValue mají,
 *  - jestli existuje víc hodnot se stejným textem ("XXL") v různých
 *    číselnících (podezření na příčinu chyb HTTP 500 při zakládání variant),
 *  - jak se dá spolehlivě určit, do kterého číselníku hodnota patří.
 */

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../../index.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/lib/schindler_eshop_api.php';

$cfg = schindler_prod_api_config();
$baseUrl = $cfg ? rtrim((string)$cfg['base_url'], '/') : '';
$token = $cfg ? (string)$cfg['token'] : '';

$hledanyText = trim((string)($_GET['text'] ?? 'XXL'));
$dialIdParam = trim((string)($_GET['dial'] ?? ''));

function dumpJson($data): string
{
    return h(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>SCHINDLER – diagnostika číselníků</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: system-ui, sans-serif; background:#fafbfb; margin:0; padding:20px; color:#1b1f23; }
.wrap { max-width:1100px; margin:0 auto; }
h1 { font-size:19px; }
h2 { font-size:15px; margin-top:24px; border-bottom:2px solid #e7e9ec; padding-bottom:6px; }
.card { background:#fff; border:1px solid #e7e9ec; border-radius:12px; padding:16px; margin-bottom:14px; }
pre { background:#f7f8f9; padding:12px; border-radius:8px; overflow:auto; font-size:11.5px; max-height:400px; }
table { width:100%; border-collapse:collapse; font-size:12.5px; }
th { text-align:left; background:#f7f8f9; padding:8px; border-bottom:2px solid #e7e9ec; font-size:11px; text-transform:uppercase; color:#6b7280; }
td { padding:7px 8px; border-bottom:1px solid #e7e9ec; }
.hit { background:#fff8e6; }
input[type=text] { padding:8px 12px; border:1px solid #ccc; border-radius:8px; font-size:13px; }
button { background:#00b52a; color:#fff; border:none; border-radius:999px; padding:9px 18px; font-weight:700; cursor:pointer; }
a.back { font-size:12.5px; color:#666; }
</style>
</head>
<body>
<div class="wrap">
<a class="back" href="index.php">&larr; Zpět na SCHINDLER</a>
<h1>Diagnostika číselníků (jen čtení, nic se nezapisuje)</h1>

<?php if ($cfg === null): ?>
    <div class="card" style="color:#d93025;">Nepodařilo se načíst produkční API config (secrets/eshop_new_api.php).</div>
<?php else: ?>

<form method="get" class="card">
    <label style="font-size:12.5px;font-weight:700;display:block;margin-bottom:6px;">Hledat hodnoty s tímto textem (např. XXL):</label>
    <input type="text" name="text" value="<?php echo h($hledanyText); ?>">
    <button type="submit">Zobrazit</button>
</form>

<h2>1) Seznam číselníků (product-dials) – syrová odpověď</h2>
<div class="card">
<?php
$dialsRes = schindler_api_call($baseUrl, $token, 'GET', '/api-engine/product-dials?itemsPerPage=100');
echo '<p style="font-size:12px;color:#6b7280;">HTTP ' . (int)$dialsRes['http'] . '</p>';
$dials = $dialsRes['body']['hydra:member'] ?? [];
echo '<p style="font-size:12.5px;">Nalezeno číselníků: <b>' . count($dials) . '</b></p>';
if (!empty($dials)) {
    echo '<p style="font-size:12px;color:#6b7280;">Pole u PRVNÍHO číselníku: <code>' . h(implode(', ', array_keys($dials[0]))) . '</code></p>';
    echo '<table><thead><tr><th>Název</th><th>@id</th></tr></thead><tbody>';
    foreach ($dials as $d) {
        echo '<tr><td>' . h((string)($d['name'] ?? $d['title'] ?? '?')) . '</td><td style="font-size:11px;">' . h((string)($d['@id'] ?? '')) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p style="font-size:12px;color:#6b7280;margin-top:10px;">Celá odpověď pro první číselník:</p>';
    echo '<pre>' . dumpJson($dials[0]) . '</pre>';
}
?>
</div>

<h2>2) Detail jednoho číselníku – nese si svoje hodnoty?</h2>
<div class="card">
<?php
$targetDial = $dialIdParam !== '' ? $dialIdParam : (!empty($dials[0]['@id']) ? basename((string)$dials[0]['@id']) : '');
if ($targetDial === '') {
    echo '<p>Není z čeho vybrat.</p>';
} else {
    echo '<p style="font-size:12.5px;">Detail číselníku <code>' . h($targetDial) . '</code>:</p>';
    $detailRes = schindler_api_call($baseUrl, $token, 'GET', '/api-engine/product-dials/' . urlencode($targetDial));
    echo '<p style="font-size:12px;color:#6b7280;">HTTP ' . (int)$detailRes['http'] . '</p>';
    echo '<pre>' . dumpJson($detailRes['body']) . '</pre>';
}
?>
</div>

<h2>3) Všechny hodnoty s textem „<?php echo h($hledanyText); ?>“ – jsou duplicitní?</h2>
<div class="card">
<?php
$matches = [];
$totalValues = 0;
$firstValueSample = null;
$page = 1;
do {
    $res = schindler_api_call($baseUrl, $token, 'GET', '/api-engine/product-dial-values?itemsPerPage=200&page=' . $page);
    if (!$res['ok']) { echo '<p style="color:#d93025;">HTTP ' . (int)$res['http'] . '</p>'; break; }
    $members = $res['body']['hydra:member'] ?? [];
    foreach ($members as $v) {
        $totalValues++;
        if ($firstValueSample === null) $firstValueSample = $v;
        $name = (string)($v['value'] ?? $v['name'] ?? $v['label'] ?? '');
        if (mb_strtoupper($name) === mb_strtoupper($hledanyText)) $matches[] = $v;
    }
    $hasNext = isset($res['body']['hydra:view']['hydra:next']);
    $page++;
} while ($hasNext && $page < 30);

echo '<p style="font-size:12.5px;">Celkem hodnot v API: <b>' . $totalValues . '</b>, z toho s textem „' . h($hledanyText) . '“: <b>' . count($matches) . '</b></p>';
if (count($matches) > 1) {
    echo '<p style="font-size:12.5px;color:#d93025;font-weight:700;">⚠ Existuje VÍC hodnot se stejným textem - to je pravděpodobná příčina chyb: podle textu nejde jednoznačně určit, kterou použít.</p>';
}
if ($firstValueSample !== null) {
    echo '<p style="font-size:12px;color:#6b7280;">Pole u hodnoty číselníku: <code>' . h(implode(', ', array_keys($firstValueSample))) . '</code></p>';
    echo '<p style="font-size:12px;color:#6b7280;">Ukázka jedné hodnoty:</p><pre>' . dumpJson($firstValueSample) . '</pre>';
}
foreach ($matches as $m) {
    echo '<p style="font-size:12px;font-weight:700;margin:10px 0 2px;">' . h((string)($m['@id'] ?? '')) . '</p>';
    echo '<pre>' . dumpJson($m) . '</pre>';
}
?>
</div>

<h2>4) Existující produkt s variantami – jak to má správně vypadat?</h2>
<div class="card">
<p style="font-size:12.5px;color:#6b7280;">Načte pár existujících variant z eshopu, které mají navázanou velikost - podle nich uvidíme, jaké IRI hodnot číselníku se reálně používají u funkčních produktů.</p>
<?php
$piRes = schindler_api_call($baseUrl, $token, 'GET', '/api-engine/product-items?isVariant=true&itemsPerPage=5');
echo '<p style="font-size:12px;color:#6b7280;">HTTP ' . (int)$piRes['http'] . '</p>';
foreach (($piRes['body']['hydra:member'] ?? []) as $pi) {
    $defs = $pi['productVariantDefinitionList'] ?? [];
    if (empty($defs)) continue;
    echo '<p style="font-size:12px;font-weight:700;margin:10px 0 2px;">' . h((string)($pi['name'] ?? '')) . ' (' . h((string)($pi['number'] ?? '')) . ')</p>';
    echo '<pre>' . dumpJson($defs) . '</pre>';
}
?>
</div>

<?php endif; ?>
</div>
</body>
</html>
