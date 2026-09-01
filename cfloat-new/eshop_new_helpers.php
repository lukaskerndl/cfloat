<?php
declare(strict_types=1);

/**
 * cfloat-new/lib/eshop_new_helpers.php
 *
 * Sdílené funkce pro práci s NOVÝM Eshop-rychle API. Vznikly proto, aby se
 * stejná logika (volání API, dohledání položek/faktury podle data, dohledání
 * skladu ke konkrétní velikosti) nekopírovala zvlášť do webhooku, detailu,
 * tisku a fallbacku - to je přesně situace, kdy se to časem rozejde a jedno
 * místo se opraví a druhé ne.
 */

/**
 * Obecné volání nového API. Vrací ['ok','http','body'].
 */
function eshop_new_api_call(string $baseUrl, string $token, string $path): array
{
    $url = str_starts_with($path, 'http') ? $path : ($baseUrl . $path);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-AUTH-TOKEN: ' . $token, 'Accept: application/ld+json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
    ]);
    $response = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = $response !== false ? json_decode($response, true) : null;
    return ['ok' => $http >= 200 && $http < 300, 'http' => $http, 'body' => $decoded];
}

/**
 * Zjistí čitelnou hodnotu (název) dial value (velikost/barva varianty) podle IRI.
 */
function eshop_new_lookup_dial_value(string $baseUrl, string $token, string $dialValueIri, array &$cache): string
{
    if ($dialValueIri === '') return '';
    if (array_key_exists($dialValueIri, $cache)) return $cache[$dialValueIri];
    $res = eshop_new_api_call($baseUrl, $token, $dialValueIri);
    $val = '';
    if ($res['ok'] && is_array($res['body'])) {
        foreach (['value', 'name', 'label', 'title'] as $k) {
            if (!empty($res['body'][$k])) { $val = (string)$res['body'][$k]; break; }
        }
    }
    $cache[$dialValueIri] = $val;
    return $val;
}

function eshop_new_norm_size(string $s): string
{
    return mb_strtoupper(trim($s));
}

/**
 * Vytáhne čitelnou velikost z pole "dial" (např. "Velikost XL" -> "XL").
 * Pokud dial neobsahuje mezeru, vrátí ho celý beze změny.
 */
function eshop_new_extract_size_from_dial(string $dial): string
{
    $dial = trim($dial);
    if ($dial === '') return '';
    $pos = strpos($dial, ' ');
    return $pos !== false ? trim(substr($dial, $pos + 1)) : $dial;
}

/**
 * Dohledá sklad + EAN pro položku objednávky. Pokud položka nese idProductItem
 * (přímé ID konkrétní varianty - nejspolehlivější cesta), dotáže se na ni přímo.
 * Jinak spadne na starší postup: kód produktu (number) + porovnání velikosti
 * (z pole "dial", případně oldVariantValue1) proti variantám podle kódu.
 * Vrací ['stock' => int|null, 'exact' => bool, 'ean' => string|null].
 */
function eshop_new_resolve_item_stock(string $baseUrl, string $token, array $item, array &$stockCache, array &$dialCache): array
{
    $idProductItem = (string)($item['idProductItem'] ?? '');
    $number = (string)($item['number'] ?? '');

    // --- Nejspolehlivější cesta: přímé ID konkrétní varianty ---
    if ($idProductItem !== '') {
        $cacheKey = 'pi:' . $idProductItem;
        if (array_key_exists($cacheKey, $stockCache)) return $stockCache[$cacheKey];

        $res = eshop_new_api_call($baseUrl, $token, '/api-engine/product-items/' . urlencode($idProductItem));
        if ($res['ok'] && is_array($res['body'])) {
            $result = [
                'stock' => isset($res['body']['stock']) ? (int)$res['body']['stock'] : null,
                'exact' => true,
                'ean' => !empty($res['body']['ean']) ? (string)$res['body']['ean'] : null,
            ];
            $stockCache[$cacheKey] = $result;
            return $result;
        }
        // Pokud přímý dotaz selže (např. produkt mezitím smazán), spadneme na fallback níže.
    }

    // --- Fallback: kód produktu + porovnání velikosti ---
    $sizeWanted = trim((string)($item['oldVariantValue1'] ?? ''));
    if ($sizeWanted === '' && !empty($item['dial'])) {
        $sizeWanted = eshop_new_extract_size_from_dial((string)$item['dial']);
    }
    return eshop_new_lookup_stock_for_size($baseUrl, $token, $number, $sizeWanted, $stockCache, $dialCache);
}
function eshop_new_lookup_stock_for_size(string $baseUrl, string $token, string $number, string $sizeWanted, array &$stockCache, array &$dialCache): array
{
    if ($number === '') return ['stock' => null, 'exact' => false, 'ean' => null];
    $cacheKey = $number . '|' . $sizeWanted;
    if (array_key_exists($cacheKey, $stockCache)) return $stockCache[$cacheKey];

    $res = eshop_new_api_call($baseUrl, $token, '/api-engine/product-items?number=' . urlencode($number));
    $members = $res['body']['hydra:member'] ?? [];

    $wanted = eshop_new_norm_size($sizeWanted);
    $maxStock = null;
    $exactStock = null;
    $exactEan = null;

    foreach ($members as $pi) {
        $s = (int)($pi['stock'] ?? 0);
        if ($maxStock === null || $s > $maxStock) $maxStock = $s;

        if ($wanted === '') continue;
        if (!($pi['isVariant'] ?? false)) continue;

        foreach (($pi['productVariantDefinitionList'] ?? []) as $def) {
            $dialIri = (string)($def['productDialValue'] ?? '');
            if ($dialIri === '') continue;
            $dialVal = eshop_new_lookup_dial_value($baseUrl, $token, $dialIri, $dialCache);
            if (eshop_new_norm_size($dialVal) === $wanted) {
                $exactStock = $s;
                $exactEan = !empty($pi['ean']) ? (string)$pi['ean'] : null;
                break 2;
            }
        }
    }

    $result = $exactStock !== null ? ['stock' => $exactStock, 'exact' => true, 'ean' => $exactEan]
        : ($wanted === '' ? ['stock' => $maxStock, 'exact' => true, 'ean' => null] : ['stock' => $maxStock, 'exact' => false, 'ean' => null]);
    $stockCache[$cacheKey] = $result;
    return $result;
}

/**
 * Dohledá položky objednávky. API nemá přímý filtr "objednávka X" (order= je
 * v tomhle API řazení, ne filtr), takže bereme položky za den objednávky a
 * mezi nimi hledáme shodu v poli "order".
 */
function eshop_new_fetch_order_items(string $baseUrl, string $token, string $orderIri, ?string $orderCreated): array
{
    if (!$orderCreated) return [];
    $day = substr($orderCreated, 0, 10);
    $from = rawurlencode($day . 'T00:00:00+02:00');
    $to = rawurlencode($day . 'T23:59:59+02:00');
    $res = eshop_new_api_call($baseUrl, $token, "/api-engine/order-products?order.created[after]={$from}&order.created[before]={$to}&itemsPerPage=300");
    $items = [];
    foreach (($res['body']['hydra:member'] ?? []) as $it) {
        if (($it['order'] ?? '') === $orderIri) $items[] = $it;
    }
    return $items;
}

/**
 * Dohledá fakturu objednávky (stejný princip jako u položek - přes datum + shodu).
 */
function eshop_new_fetch_order_invoice(string $baseUrl, string $token, string $orderIri, ?string $orderCreated): ?array
{
    if (!$orderCreated) return null;
    $day = substr($orderCreated, 0, 10);
    $from = rawurlencode($day . 'T00:00:00+02:00');
    $to = rawurlencode($day . 'T23:59:59+02:00');
    $res = eshop_new_api_call($baseUrl, $token, "/api-engine/invoices?dateIssue[after]={$from}&dateIssue[before]={$to}&itemsPerPage=200");
    foreach (($res['body']['hydra:member'] ?? []) as $inv) {
        if (($inv['order'] ?? '') === $orderIri) return $inv;
    }
    return null;
}

/**
 * Pokud má prodaná varianta duplicitní záznam se stejným EAN (typicky
 * "normální" produkt vs. "VÝPRODEJ" verze stejného zboží), odečte objednané
 * kusy i z toho druhého záznamu - je to fyzicky stejný sklad, jen zobrazený
 * dvakrát. Provede se NEJVÝŠE JEDNOU pro danou dvojici objednávka+položka
 * (hlídá tabulka eshop_new_duplicate_stock_applied), ať se při opakovaném
 * zpracování webhooku neodečte víckrát.
 *
 * Bezpečnostní pravidla (radši nic neudělat, než udělat špatně):
 * - musí existovat EAN (bez EAN nejde spolehlivě párovat)
 * - musí se najít přesně JEDNA jiná varianta se stejným EAN (ne 0, ne 2+)
 * - nikdy nezapisuje zápornou hodnotu skladu
 */
function eshop_new_sync_duplicate_stock(PDO $pdo, string $baseUrl, string $token, string $orderIri, string $itemIri, ?string $ean, ?string $ownProductItemId, int $pieces): void
{
    if (!$ean || !$ownProductItemId || $pieces <= 0) return;

    $already = $pdo->prepare("SELECT 1 FROM eshop_new_duplicate_stock_applied WHERE order_iri = :o AND item_iri = :i");
    $already->execute([':o' => $orderIri, ':i' => $itemIri]);
    if ($already->fetchColumn()) return; // už se to jednou udělalo - nic dalšího

    $res = eshop_new_api_call($baseUrl, $token, '/api-engine/product-items?ean=' . urlencode($ean));
    $members = $res['body']['hydra:member'] ?? [];

    $candidates = [];
    foreach ($members as $pi) {
        $piId = basename((string)($pi['@id'] ?? ''));
        if ($piId !== '' && $piId !== $ownProductItemId) $candidates[] = $pi;
    }

    // Bezpečnostní brzda: pokud se nenajde přesně jedna jiná varianta, radši nic neděláme.
    if (count($candidates) !== 1) return;

    $dup = $candidates[0];
    $dupId = basename((string)($dup['@id'] ?? ''));
    $oldStock = (int)($dup['stock'] ?? 0);
    $newStock = max(0, $oldStock - $pieces);

    $ch = curl_init($baseUrl . '/api-engine/product-items/' . urlencode($dupId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode(['stock' => $newStock], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['X-AUTH-TOKEN: ' . $token, 'Content-Type: application/merge-patch+json', 'Accept: application/ld+json'],
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
    ]);
    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http >= 200 && $http < 300) {
        $pdo->prepare("
            INSERT INTO eshop_new_duplicate_stock_applied (order_iri, item_iri, ean, duplicate_product_item_id, old_stock, new_stock, applied_at)
            VALUES (:o, :i, :e, :d, :os, :ns, NOW())
        ")->execute([':o' => $orderIri, ':i' => $itemIri, ':e' => $ean, ':d' => $dupId, ':os' => $oldStock, ':ns' => $newStock]);
    }
}
 * Volá se z webhooku, fallbacku i importu historie - jedno místo, které
 * rozhoduje, co se ukládá.
 */
function eshop_new_persist_order(PDO $pdo, string $baseUrl, string $token, array $order): void
{
    $orderIri = (string)($order['@id'] ?? '');
    if ($orderIri === '') return;

    $orderCreated = $order['created'] ?? null;
    $created = null;
    if ($orderCreated) {
        $ts = strtotime((string)$orderCreated);
        if ($ts !== false) $created = date('Y-m-d H:i:s', $ts);
    }
    $stateIri = $order['orderState']['@id'] ?? null;
    $stateName = $order['orderState']['name'] ?? null;
    $paymentName = $order['orderDeliveryPayment']['namePayment'] ?? null;

    $invoice = eshop_new_fetch_order_invoice($baseUrl, $token, $orderIri, $orderCreated);
    $paymentStatus = $invoice !== null ? (int)($invoice['paymentStatus'] ?? 0) : null;
    $orderNumber = $invoice !== null ? (string)($invoice['variableSymbol'] ?? '') : null;
    $invoiceNumber = $invoice !== null ? (string)($invoice['number'] ?? '') : null;
    $totalPrice = $invoice !== null ? (float)($invoice['totalPrice'] ?? 0) : null;
    $customerEmail = $order['email'] ?? null;
    $customerPhone = $order['phoneNumber'] ?? null;
    $deliveryName = $order['orderDeliveryPayment']['nameDelivery'] ?? null;

    $pdo->prepare("
        INSERT INTO eshop_new_orders (order_iri, created, order_state_iri, order_state_name, payment_status, payment_name,
            customer_email, customer_phone, order_number, invoice_number, total_price, delivery_name, raw_json, imported_at, updated_at)
        VALUES (:iri, :created, :state_iri, :state_name, :pay_status, :pay_name,
            :email, :phone, :onum, :inum, :total, :delivery, :raw, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            created = VALUES(created), order_state_iri = VALUES(order_state_iri), order_state_name = VALUES(order_state_name),
            payment_status = VALUES(payment_status), payment_name = VALUES(payment_name),
            customer_email = VALUES(customer_email), customer_phone = VALUES(customer_phone),
            order_number = VALUES(order_number), invoice_number = VALUES(invoice_number),
            total_price = VALUES(total_price), delivery_name = VALUES(delivery_name),
            raw_json = VALUES(raw_json), updated_at = NOW()
    ")->execute([
        ':iri' => $orderIri, ':created' => $created, ':state_iri' => $stateIri, ':state_name' => $stateName,
        ':pay_status' => $paymentStatus, ':pay_name' => $paymentName,
        ':email' => $customerEmail, ':phone' => $customerPhone, ':onum' => $orderNumber, ':inum' => $invoiceNumber,
        ':total' => $totalPrice, ':delivery' => $deliveryName,
        ':raw' => json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    // Položky + sklad k přesné velikosti - uložíme rovnou, ať to nemusí detail počítat živě.
    $items = eshop_new_fetch_order_items($baseUrl, $token, $orderIri, $orderCreated);
    $stockCache = []; $dialCache = [];
    $itemUpsert = $pdo->prepare("
        INSERT INTO eshop_new_order_items (order_iri, item_iri, name, number, size, pieces, price_with_vat, stock, stock_exact, ean, updated_at)
        VALUES (:o, :i, :name, :number, :size, :pieces, :price, :stock, :exact, :ean, NOW())
        ON DUPLICATE KEY UPDATE
            name = VALUES(name), number = VALUES(number), size = VALUES(size), pieces = VALUES(pieces),
            price_with_vat = VALUES(price_with_vat), stock = VALUES(stock), stock_exact = VALUES(stock_exact),
            ean = VALUES(ean), updated_at = NOW()
    ");
    foreach ($items as $it) {
        $number = (string)($it['number'] ?? '');
        $size = trim((string)($it['oldVariantValue1'] ?? ''));
        if ($size === '' && !empty($it['dial'])) $size = eshop_new_extract_size_from_dial((string)$it['dial']);
        $stockInfo = eshop_new_resolve_item_stock($baseUrl, $token, $it, $stockCache, $dialCache);
        $itemUpsert->execute([
            ':o' => $orderIri, ':i' => (string)($it['@id'] ?? ($orderIri . '#' . $number)),
            ':name' => $it['name'] ?? '', ':number' => $number, ':size' => $size,
            ':pieces' => (int)($it['pieces'] ?? 1), ':price' => (float)($it['priceWithVat'] ?? 0),
            ':stock' => $stockInfo['stock'], ':exact' => $stockInfo['exact'] ? 1 : 0, ':ean' => $stockInfo['ean'],
        ]);

        // Automatické odečtení duplicitní (např. výprodejové) položky se stejným EAN -
        // jen pokud známe EAN a přesnou variantu (idProductItem); jinak se nic neděje.
        if (!empty($stockInfo['ean']) && !empty($it['idProductItem'])) {
            try {
                eshop_new_sync_duplicate_stock(
                    $pdo, $baseUrl, $token, $orderIri,
                    (string)($it['@id'] ?? ($orderIri . '#' . $number)),
                    $stockInfo['ean'], (string)$it['idProductItem'], (int)($it['pieces'] ?? 1)
                );
            } catch (Throwable $e) {
                // Nikdy nesmí shodit uložení celé objednávky kvůli tomuhle doplňkovému kroku.
            }
        }
    }
}
