<?php
declare(strict_types=1);

/**
 * cfloat-new/nove-objednavky-fix-jedna.php
 *
 * Ruční, okamžitá oprava duplicitního skladu (VÝPRODEJ vs. normální produkt)
 * pro JEDNU konkrétní objednávku podle jejího čísla - nezávisí na webhooku
 * ani na tom, kdy byla objednávka naposledy zpracovaná.
 *
 * Použití: zadáš číslo objednávky (to, co je v e-shopu vidět jako "Číslo",
 * např. 1244107894), najde se odpovídající faktura/objednávka přes API,
 * u každé položky s idProductItem a EAN se spustí stejná bezpečná logika
 * jako automaticky ve webhooku (vyžaduje přesně JEDNU jinou variantu se
 * stejným EAN), a ukáže se přesně, co se stalo.
 */

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../index.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$cfgCandidates = [__DIR__ . '/../config.php', __DIR__ . '/../../config.php'];
$pdo = null;
foreach ($cfgCandidates as $p) {
    if (is_file($p)) { require $p; break; }
}
if (!isset($pdo) || !($pdo instanceof PDO)) die('Chybí config.php nebo $pdo.');

require_once __DIR__ . '/lib/eshop_new_helpers.php';

$secretsPath = __DIR__ . '/../secrets/eshop_new_api.php';
if (!is_file($secretsPath)) die('Chybí secrets/eshop_new_api.php.');
$cfg = include $secretsPath;
$baseUrl = rtrim((string)($cfg['base_url'] ?? ''), '/');
$token   = trim((string)($cfg['token'] ?? ''));

$orderNumber = trim((string)($_GET['cislo'] ?? ''));
$log = [];
$order = null;

if ($orderNumber !== '') {
    // Číslo objednávky = variableSymbol na faktuře. Nejdřív ho zkusíme najít v naší DB (rychlé).
    $stmt = $pdo->prepare("SELECT order_iri, created, raw_json FROM eshop_new_orders WHERE order_number = :n LIMIT 1");
    $stmt->execute([':n' => $orderNumber]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $order = json_decode((string)$row['raw_json'], true);
        $orderIri = $row['order_iri'];
        $orderCreated = $row['created'];
        $log[] = "Objednávka nalezena v databázi cFloatu (IRI: {$orderIri}).";
    } else {
        $log[] = "Objednávka č. {$orderNumber} nebyla nalezena v databázi cFloatu - zkuste ji nejdřív otevřít v Nových objednávkách, ať se stáhne.";
    }

    if ($order) {
        $items = eshop_new_fetch_order_items($baseUrl, $token, $orderIri, $orderCreated);
        if (empty($items)) {
            $log[] = "K téhle objednávce se nenašly žádné položky.";
        }
        foreach ($items as $it) {
            $name = (string)($it['name'] ?? '');
            $number = (string)($it['number'] ?? '');
            $pieces = (int)($it['pieces'] ?? 1);
            $idProductItem = (string)($it['idProductItem'] ?? '');
            $itemIri = (string)($it['@id'] ?? '');

            if ($idProductItem === '') {
                $log[] = "❌ {$name} ({$number}): chybí idProductItem, nelze bezpečně dohledat konkrétní variantu - přeskočeno.";
                continue;
            }

            // Zjistíme EAN přímo z téhle konkrétní varianty.
            $piRes = eshop_new_api_call($baseUrl, $token, '/api-engine/product-items/' . urlencode($idProductItem));
            $ean = $piRes['ok'] ? (string)($piRes['body']['ean'] ?? '') : '';

            if ($ean === '') {
                $log[] = "❌ {$name} ({$number}): tahle varianta nemá EAN, nelze dohledat duplicitu - přeskočeno.";
                continue;
            }

            // Kolikrát se tenhle EAN v katalogu vyskytuje (informace pro kontrolu)?
            $checkRes = eshop_new_api_call($baseUrl, $token, '/api-engine/product-items?ean=' . urlencode($ean));
            $matches = $checkRes['body']['hydra:member'] ?? [];
            $others = array_filter($matches, fn($m) => basename((string)($m['@id'] ?? '')) !== $idProductItem);
            $log[] = "🔎 {$name} ({$number}), EAN {$ean}: nalezeno " . count($others) . " jiných variant se stejným EAN (potřebujeme přesně 1, jinak se neopravuje).";

            // Zkontrolujeme, jestli už bylo pro tuhle položku provedeno dřív.
            $already = $pdo->prepare("SELECT old_stock, new_stock, duplicate_product_item_id FROM eshop_new_duplicate_stock_applied WHERE order_iri = :o AND item_iri = :i");
            $already->execute([':o' => $orderIri, ':i' => $itemIri]);
            $prev = $already->fetch(PDO::FETCH_ASSOC);
            if ($prev) {
                $log[] = "ℹ️ Už bylo provedeno dřív: sklad {$prev['old_stock']} → {$prev['new_stock']} (varianta {$prev['duplicate_product_item_id']}). Nic dalšího se nedělá.";
                continue;
            }

            eshop_new_sync_duplicate_stock($pdo, $baseUrl, $token, $orderIri, $itemIri, $ean, $idProductItem, $pieces);

            $checkAgain = $pdo->prepare("SELECT old_stock, new_stock, duplicate_product_item_id FROM eshop_new_duplicate_stock_applied WHERE order_iri = :o AND item_iri = :i");
            $checkAgain->execute([':o' => $orderIri, ':i' => $itemIri]);
            $result = $checkAgain->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $log[] = "✅ OPRAVENO: varianta {$result['duplicate_product_item_id']}, sklad {$result['old_stock']} → {$result['new_stock']}.";
            } else {
                $log[] = "⚠ Neopraveno (nesplnila se bezpečnostní podmínka - viz počet nalezených variant výše).";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Ruční oprava duplicitního skladu</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: system-ui, sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; }
.wrap { max-width:800px; margin:0 auto; }
.card { background:#fff; border:1px solid #e7e9ec; border-radius:14px; padding:18px; margin-bottom:14px; }
.back-link { color:#666; font-size:12.5px; text-decoration:none; border:1px solid #e7e9ec; border-radius:999px; padding:6px 14px; }
input[type=text] { padding:9px 12px; border:1px solid #ccc; border-radius:8px; font-size:14px; width:220px; }
button { padding:9px 16px; border:none; border-radius:8px; background:#00b52a; color:#fff; font-weight:700; cursor:pointer; }
pre { background:#f7f8f9; padding:12px; border-radius:10px; overflow:auto; font-size:13px; white-space:pre-wrap; }
</style>
</head>
<body>
<div class="wrap">
    <a class="back-link" href="nove-objednavky.php">&larr; Zpět na Nové objednávky</a>
    <h1>Ruční oprava duplicitního skladu</h1>
    <div class="card">
        <form method="get">
            <label>Číslo objednávky (to, co vidíte v e-shopu):</label><br><br>
            <input type="text" name="cislo" value="<?php echo h($orderNumber); ?>" placeholder="např. 1244107894">
            <button type="submit">Zkontrolovat a opravit</button>
        </form>
    </div>
    <?php if (!empty($log)): ?>
        <div class="card">
            <h2 style="font-size:15px;">Průběh</h2>
            <pre><?php echo h(implode("\n", $log)); ?></pre>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
