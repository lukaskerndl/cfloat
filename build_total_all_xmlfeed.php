<?php
// build_total_all_xmlfeed.php
// Sloučený TOTAL_ALL_XMLfeed přes všechny dodavatelé podle mapování v tabulce xml_feeds.
// Navíc uloží výsledek do souboru /HromadnyXMLfeed/TOTAL_ALL_XMLfeed.xml
// Dostupnost normalizujeme: 0 = skladem, 1 = není skladem.

require __DIR__ . '/config.php'; // $pdo

function output_and_save_xml(SimpleXMLElement $xmlRoot) {
    $xmlOutput = $xmlRoot->asXML();

    // Uložení do souboru v adresáři HromadnyXMLfeed
    $targetDir = __DIR__ . '/HromadnyXMLfeed';
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0775, true);
    }
    if (is_dir($targetDir) && is_writable($targetDir)) {
        $filePath = $targetDir . '/TOTAL_ALL_XMLfeed.xml';
        @file_put_contents($filePath, $xmlOutput);
    }

    header('Content-Type: application/xml; charset=utf-8');
    echo $xmlOutput;
    exit;
}

$root = new SimpleXMLElement('<TOTAL_ALL_XMLfeed/>');

// Zkontrolujeme tabulku a mapovací sloupce
$mappingColumns = ['item_tag','ean_tag','stock_tag','qty_tag','price_tag'];

try {
    $stmtCheck = $pdo->query("SHOW TABLES LIKE 'xml_feeds'");
    if (!$stmtCheck || $stmtCheck->rowCount() === 0) {
        $err = new SimpleXMLElement('<error/>');
        $err->addChild('message', 'Tabulka xml_feeds neexistuje.');
        output_and_save_xml($err);
    }

    $existingCols = [];
    $colsStmt = $pdo->query("SHOW COLUMNS FROM xml_feeds");
    while ($col = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
        $existingCols[$col['Field']] = true;
    }
    foreach ($mappingColumns as $c) {
        if (!isset($existingCols[$c])) {
            $err = new SimpleXMLElement('<error/>');
            $err->addChild('message', 'Tabulka xml_feeds nemá všechny mapovací sloupce (item_tag, ean_tag, stock_tag, qty_tag, price_tag).');
            output_and_save_xml($err);
        }
    }
} catch (Throwable $e) {
    $err = new SimpleXMLElement('<error/>');
    $err->addChild('message', 'Chyba při kontrole tabulky xml_feeds.');
    output_and_save_xml($err);
}

// Načteme feedy
$stmt = $pdo->query("SELECT id, supplier_name, feed_url, item_tag, ean_tag, stock_tag, qty_tag, price_tag FROM xml_feeds");
$feeds = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($feeds)) {
    $err = new SimpleXMLElement('<error/>');
    $err->addChild('message', 'Žádné feedy v tabulce xml_feeds.');
    output_and_save_xml($err);
}

foreach ($feeds as $f) {
    $url       = trim((string)$f['feed_url']);
    $supplier  = $f['supplier_name'] !== '' ? $f['supplier_name'] : ('feed_' . $f['id']);
    $itemTag   = trim((string)$f['item_tag']);
    $eanTag    = trim((string)$f['ean_tag']);
    $stockTag  = trim((string)$f['stock_tag']);
    $qtyTag    = trim((string)$f['qty_tag']);
    $priceTag  = trim((string)$f['price_tag']);

    if ($url === '' || $itemTag === '' || $eanTag === '') {
        // Bez URL, itemTag nebo eanTag nedává smysl – přeskočíme
        continue;
    }

    $xmlContent = @file_get_contents($url);
    if ($xmlContent === false) {
        continue;
    }

    $xml = @simplexml_load_string($xmlContent);
    if ($xml === false) {
        continue;
    }

    $items = $xml->xpath('//' . $itemTag);
    if (!$items || count($items) === 0) {
        continue;
    }

    foreach ($items as $item) {
        $ean = isset($item->{$eanTag}) ? trim((string)$item->{$eanTag}) : '';
        if ($ean === '') {
            continue;
        }

        // Zjistíme hrubou hodnotu skladu / množství
        $rawStockVal = '';
        if ($stockTag !== '' && isset($item->{$stockTag})) {
            $rawStockVal = trim((string)$item->{$stockTag});
        }
        $rawQtyVal = '';
        if ($qtyTag !== '' && isset($item->{$qtyTag})) {
            $rawQtyVal = trim((string)$item->{$qtyTag});
        }

        // Priority: pokud máme explicitní qty_tag, použijeme ho jako primární ukazatel skladem
        $sourceVal = $rawQtyVal !== '' ? $rawQtyVal : $rawStockVal;

        $isInStock = false;
        if ($sourceVal !== '') {
            if (is_numeric($sourceVal)) {
                // 0 = není skladem, >0 = skladem
                $isInStock = ((float)$sourceVal > 0);
            } else {
                $vLower = mb_strtolower($sourceVal, 'UTF-8');
                $trueVals = ['skladem','yes','ano','true','available','in stock'];
                if (in_array($vLower, $trueVals, true)) {
                    $isInStock = true;
                }
            }
        }

        // Normalizace: 0 = skladem, 1 = není skladem
        $normalizedStock = $isInStock ? '0' : '1';

        $node = $root->addChild('item');
        $node->addChild('ean', htmlspecialchars($ean, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
        $node->addChild('stock', $normalizedStock);

        // Případně přidáme i původní počet kusů (qty), pokud existuje
        if ($rawQtyVal !== '') {
            $node->addChild('qty', htmlspecialchars($rawQtyVal, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
        }

        if ($priceTag !== '' && isset($item->{$priceTag})) {
            $node->addChild('price', htmlspecialchars((string)$item->{$priceTag}, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
        }
        $node->addChild('supplier', htmlspecialchars($supplier, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
        $node->addChild('feed_id', (int)$f['id']);
    }
}

// Výstup + uložení
output_and_save_xml($root);
