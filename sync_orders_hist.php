<?php
// sync_orders_hist.php – historické tahání objednávek od 1. 9. 2025
// 1 request / běh, limit 50, pojistky na čas a denní limit
//
// Spuštění (cron přes hosting panel, WEDOS nemá SSH):
//   https://cfloat.cz/sync_orders_hist.php?token=TOKEN_ZE_SOUBORU_secrets/cron_run_token.php
// Pokud skript spouští cron přímo přes php-cli, token se nekontroluje.

require __DIR__ . '/config.php'; // config musí vytvořit $pdo (PDO připojení)

// --- Ochrana proti spuštění kýmkoliv přes veřejnou URL (dřív tu žádná nebyla!) ---
require_once __DIR__ . '/_cron_guard.php';

// Automatické doplnění EAN a nákupních cen po historickém syncu.
require_once __DIR__ . '/fill_ean_auto.php';
require_once __DIR__ . '/fill_purchase_price_auto.php';

/**
 * KONFIG
 */

// URL API (objednávky v2)
// Heslo k e-shop API (dřív natvrdo v kódu) – viz secrets/eshop_api_password.php
$__cfloatEshopPass = (string) include __DIR__ . '/secrets/eshop_api_password.php';
define('BASE_API_URL', 'https://www.c-store.cz/request.php?action=GetOrders&version=v2.0&password=' . rawurlencode($__cfloatEshopPass));

// Historický start – od 1. 9. 2025 00:00
if (!defined('START_AFTER_TS')) {
    define('START_AFTER_TS', strtotime('2022-09-01 00:00:00'));
}

// Kolik objednávek na jeden request
const HIST_LIMIT = 90;

// Bezpečnostní limity pro API
const MIN_SECONDS_BETWEEN_CALLS = 15;     // nesahat na API častěji než jednou za 15 s
const DAILY_REQUEST_SOFT_LIMIT  = 1900;   // soft limit pod 2000 / den

/**
 * POMOCNÉ FUNKCE PRO SYNC
 */

function loadSyncState(PDO $pdo): array {
    $stmt = $pdo->query("SELECT * FROM sync_state WHERE id = 1");
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->exec("
            INSERT IGNORE INTO sync_state (id, last_after_ts, last_api_call_at, daily_requests, daily_date)
            VALUES (1, 0, 0, 0, '1970-01-01')
        ");
        $row = [
            'id' => 1,
            'last_after_ts' => 0,
            'last_api_call_at' => 0,
            'daily_requests' => 0,
            'daily_date' => '1970-01-01',
        ];
    }
    return $row;
}

function saveSyncState(PDO $pdo, array $state): void {
    $stmt = $pdo->prepare("
        UPDATE sync_state
        SET last_after_ts     = :last_after_ts,
            last_api_call_at  = :last_api_call_at,
            daily_requests    = :daily_requests,
            daily_date        = :daily_date
        WHERE id = 1
    ");
    $stmt->execute([
        ':last_after_ts'    => (int)$state['last_after_ts'],
        ':last_api_call_at' => (int)$state['last_api_call_at'],
        ':daily_requests'   => (int)$state['daily_requests'],
        ':daily_date'       => $state['daily_date'],
    ]);
}

function logSync(PDO $pdo, string $status, string $message, int $apiRequests, int $inserted, int $updated): void {
    $stmt = $pdo->prepare("
        INSERT INTO sync_log (run_at, run_type, status, message, api_requests, orders_inserted, orders_updated)
        VALUES (NOW(), 'HIST', :status, :message, :api_requests, :inserted, :updated)
    ");
    $stmt->execute([
        ':status'       => $status,
        ':message'      => $message,
        ':api_requests' => $apiRequests,
        ':inserted'     => $inserted,
        ':updated'      => $updated,
    ]);
}

function callOrdersApi(int $afterTs): array {
    $url = BASE_API_URL
        . '&limit=' . HIST_LIMIT
        . '&after=' . $afterTs
        . '&orderby=date'
        . '&order=1'; // vzestupně podle data

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error: ' . $err);
    }
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpStatus !== 200) {
        throw new RuntimeException('HTTP status ' . $httpStatus . ' při volání API.');
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Neplatná JSON odpověď API.');
    }
    return $data;
}

function upsertOrder(PDO $pdo, array $order): bool {
    // true = INSERT, false = UPDATE

    $id_order  = (int)($order['id_order'] ?? 0);
    $number    = (string)($order['number'] ?? '');

    // datum vytvoření
    $createdRaw = $order['created']['date'] ?? null;
    $createdAt  = $createdRaw ? substr($createdRaw, 0, 19) : null; // "YYYY-MM-DD HH:MM:SS"

    // stav / platba
    $id_order_state        = $order['id_order_state'] ?? null;
    $idOrderState          = $order['idOrderState'] ?? null;
    $zaplaceno             = $order['zaplaceno'] ?? null;
    $gopay_zaplaceno       = $order['gopay_zaplaceno'] ?? null;
    $gateway_payment_state = $order['gateway_payment_state'] ?? null;

    // měna a součty
    $currency_code        = $order['selected_currency']['code'] ?? null;
    $total_price_with_vat = $order['total']['price_with_vat'] ?? null;
    $total_vat            = $order['total']['price_vat'] ?? null;
    $total_price          = $order['total']['price'] ?? null;

    // doprava / platba
    $delivery_name  = $order['delivery']['nazev_postovne'] ?? null;
    $payment_name   = $order['payment']['nazev_platba'] ?? null;
    $payment_amount = $order['payment']['castka_platba'] ?? null;

    // zákazník – dodací adresa
    $del = $order['customer']['delivery_information'] ?? [];
    $customer_name    = $del['name'] ?? null;
    $customer_company = $del['company'] ?? null;
    $customer_street  = $del['street'] ?? null;
    $customer_city    = $del['city'] ?? null;
    $customer_zip     = $del['zip'] ?? null;
    $customer_country = $del['country'] ?? null;
    $customer_email   = $del['email'] ?? null;
    $customer_phone   = $del['phone'] ?? null;

    // faktura
    $invoice_number          = $order['invoice_number'] ?? null;
    $invoice_variable_symbol = $order['invoice_variable_symbol'] ?? null;

    // další info
    $is_total_rounded  = $order['is_total_rounded'] ?? null;
    $is_vat_payer      = $order['is_vat_payer'] ?? null;
    $is_price_with_vat = $order['is_price_with_vat'] ?? null;
    $internal_note     = $order['internal_note'] ?? null;

    $rawJson = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // existuje?
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE id_order = :id_order");
    $stmt->execute([':id_order' => $id_order]);
    $existingId = $stmt->fetchColumn();

    if ($existingId === false) {
        // INSERT
        $sql = "
            INSERT INTO orders (
              id_order, number,
              created_at, created_raw,
              id_order_state, idOrderState, zaplaceno, gopay_zaplaceno, gateway_payment_state,
              currency_code, total_price, total_price_with_vat, total_vat,
              delivery_name, payment_name, payment_amount,
              customer_name, customer_company, customer_street, customer_city, customer_zip,
              customer_country, customer_email, customer_phone,
              invoice_number, invoice_variable_symbol,
              is_total_rounded, is_vat_payer, is_price_with_vat,
              internal_note,
              raw_json,
              updated_at
            ) VALUES (
              :id_order, :number,
              :created_at, :created_raw,
              :id_order_state, :idOrderState, :zaplaceno, :gopay_zaplaceno, :gateway_payment_state,
              :currency_code, :total_price, :total_price_with_vat, :total_vat,
              :delivery_name, :payment_name, :payment_amount,
              :customer_name, :customer_company, :customer_street, :customer_city, :customer_zip,
              :customer_country, :customer_email, :customer_phone,
              :invoice_number, :invoice_variable_symbol,
              :is_total_rounded, :is_vat_payer, :is_price_with_vat,
              :internal_note,
              :raw_json,
              NOW()
            )
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_order' => $id_order,
            ':number'   => $number,
            ':created_at' => $createdAt,
            ':created_raw' => $createdRaw,
            ':id_order_state' => $id_order_state,
            ':idOrderState'   => $idOrderState,
            ':zaplaceno'      => $zaplaceno,
            ':gopay_zaplaceno' => $gopay_zaplaceno,
            ':gateway_payment_state' => $gateway_payment_state,
            ':currency_code' => $currency_code,
            ':total_price'   => $total_price,
            ':total_price_with_vat' => $total_price_with_vat,
            ':total_vat'     => $total_vat,
            ':delivery_name' => $delivery_name,
            ':payment_name'  => $payment_name,
            ':payment_amount' => $payment_amount,
            ':customer_name' => $customer_name,
            ':customer_company' => $customer_company,
            ':customer_street'  => $customer_street,
            ':customer_city'    => $customer_city,
            ':customer_zip'     => $customer_zip,
            ':customer_country' => $customer_country,
            ':customer_email'   => $customer_email,
            ':customer_phone'   => $customer_phone,
            ':invoice_number'   => $invoice_number,
            ':invoice_variable_symbol' => $invoice_variable_symbol,
            ':is_total_rounded' => $is_total_rounded,
            ':is_vat_payer'     => $is_vat_payer,
            ':is_price_with_vat' => $is_price_with_vat,
            ':internal_note'    => $internal_note,
            ':raw_json'         => $rawJson,
        ]);
        return true;
    } else {
        // UPDATE
        $sql = "
            UPDATE orders SET
              number = :number,
              created_at = :created_at,
              created_raw = :created_raw,
              id_order_state = :id_order_state,
              idOrderState = :idOrderState,
              zaplaceno = :zaplaceno,
              gopay_zaplaceno = :gopay_zaplaceno,
              gateway_payment_state = :gateway_payment_state,
              currency_code = :currency_code,
              total_price = :total_price,
              total_price_with_vat = :total_price_with_vat,
              total_vat = :total_vat,
              delivery_name = :delivery_name,
              payment_name = :payment_name,
              payment_amount = :payment_amount,
              customer_name = :customer_name,
              customer_company = :customer_company,
              customer_street = :customer_street,
              customer_city = :customer_city,
              customer_zip = :customer_zip,
              customer_country = :customer_country,
              customer_email = :customer_email,
              customer_phone = :customer_phone,
              invoice_number = :invoice_number,
              invoice_variable_symbol = :invoice_variable_symbol,
              is_total_rounded = :is_total_rounded,
              is_vat_payer = :is_vat_payer,
              is_price_with_vat = :is_price_with_vat,
              internal_note = :internal_note,
              raw_json = :raw_json,
              updated_at = NOW()
            WHERE id_order = :id_order
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_order' => $id_order,
            ':number'   => $number,
            ':created_at' => $createdAt,
            ':created_raw' => $createdRaw,
            ':id_order_state' => $id_order_state,
            ':idOrderState'   => $idOrderState,
            ':zaplaceno'      => $zaplaceno,
            ':gopay_zaplaceno' => $gopay_zaplaceno,
            ':gateway_payment_state' => $gateway_payment_state,
            ':currency_code' => $currency_code,
            ':total_price'   => $total_price,
            ':total_price_with_vat' => $total_price_with_vat,
            ':total_vat'     => $total_vat,
            ':delivery_name' => $delivery_name,
            ':payment_name'  => $payment_name,
            ':payment_amount' => $payment_amount,
            ':customer_name' => $customer_name,
            ':customer_company' => $customer_company,
            ':customer_street'  => $customer_street,
            ':customer_city'    => $customer_city,
            ':customer_zip'     => $customer_zip,
            ':customer_country' => $customer_country,
            ':customer_email'   => $customer_email,
            ':customer_phone'   => $customer_phone,
            ':invoice_number'   => $invoice_number,
            ':invoice_variable_symbol' => $invoice_variable_symbol,
            ':is_total_rounded' => $is_total_rounded,
            ':is_vat_payer'     => $is_vat_payer,
            ':is_price_with_vat' => $is_price_with_vat,
            ':internal_note'    => $internal_note,
            ':raw_json'         => $rawJson,
        ]);
        return false;
    }
}

function replaceOrderItems(PDO $pdo, int $id_order, array $rowList): void {
    // Preserve already filled EAN + nakupni_cena for this order
    $preserve = [];
    $stSel = $pdo->prepare("
        SELECT product_id, variant_id, product_number, variant_description, EAN, nakupni_cena
        FROM order_items
        WHERE id_order = :id_order
    ");
    $stSel->execute([':id_order' => $id_order]);
    while ($r = $stSel->fetch(PDO::FETCH_ASSOC)) {
        $pid = $r['product_id'];
        $vid = $r['variant_id'];
        if ($pid !== null && $vid !== null && $pid !== '' && $vid !== '') {
            $k = 'pv:' . $pid . '_' . $vid;
        } else {
            $pn = (string)($r['product_number'] ?? '');
            $vd = (string)($r['variant_description'] ?? '');
            $k = 'pn:' . $pn . '|' . $vd;
        }
        $preserve[$k] = [
            'ean' => $r['EAN'] ?? null,
            'nak' => $r['nakupni_cena'] ?? null,
        ];
    }

    // Replace rows (keeps history fields above via $preserve)
    $stmt = $pdo->prepare("DELETE FROM order_items WHERE id_order = :id_order");
    $stmt->execute([':id_order' => $id_order]);

    if (empty($rowList)) {
        return;
    }

    $sql = "
        INSERT INTO order_items (
          id_order,
          product_number, product_name, variant_description,
          price_per_unit, price_per_unit_with_vat,
          price_total, price_total_with_vat, price_vat, vat,
          `count`, unit,
          product_id, variant_id,
          raw_json,
          `EAN`, nakupni_cena
        ) VALUES (
          :id_order,
          :product_number, :product_name, :variant_description,
          :price_per_unit, :price_per_unit_with_vat,
          :price_total, :price_total_with_vat, :price_vat, :vat,
          :count, :unit,
          :product_id, :variant_id,
          :raw_json,
          :ean, :nakupni_cena
        )
    ";
    $s = $pdo->prepare($sql);

    foreach ($rowList as $row) {
        $rawJson = json_encode($row, JSON_UNESCAPED_UNICODE);

        $pid = $row['product_id'] ?? null;
        $vid = $row['variant_id'] ?? null;

        if ($pid !== null && $vid !== null && $pid !== '' && $vid !== '') {
            $k = 'pv:' . $pid . '_' . $vid;
        } else {
            $pn = (string)($row['product_number'] ?? '');
            $vd = (string)($row['variant_description'] ?? '');
            $k = 'pn:' . $pn . '|' . $vd;
        }

        $ean = $preserve[$k]['ean'] ?? null;
        $nak = $preserve[$k]['nak'] ?? null;

        $s->execute([
            ':id_order'   => $id_order,
            ':product_number' => $row['product_number'] ?? null,
            ':product_name'   => $row['product_name'] ?? null,
            ':variant_description' => $row['variant_description'] ?? null,
            ':price_per_unit' => $row['price_per_unit'] ?? null,
            ':price_per_unit_with_vat' => $row['price_per_unit_with_vat'] ?? null,
            ':price_total' => $row['price_total'] ?? null,
            ':price_total_with_vat' => $row['price_total_with_vat'] ?? null,
            ':price_vat'   => $row['price_vat'] ?? null,
            ':vat'         => $row['vat'] ?? null,
            ':count'       => $row['count'] ?? null,
            ':unit'        => $row['unit'] ?? null,
            ':product_id'  => $pid,
            ':variant_id'  => $vid,
            ':raw_json'    => $rawJson,
            ':ean'         => $ean,
            ':nakupni_cena'=> $nak,
        ]);
    }
}


/**
 * HLAVNÍ BĚH
 */

global $pdo; // z config.php

$state = loadSyncState($pdo);
$now   = time();
$today = date('Y-m-d', $now);

// reset denního limitu při změně dne
if ($state['daily_date'] !== $today) {
    $state['daily_date']   = $today;
    $state['daily_requests'] = 0;
}

// pojistka: moc brzo po posledním volání
if ($now - (int)$state['last_api_call_at'] < MIN_SECONDS_BETWEEN_CALLS) {
    logSync($pdo, 'SKIPPED_TOO_SOON', 'Příliš brzy po posledním volání API.', 0, 0, 0);
    saveSyncState($pdo, $state);
    exit("SKIPPED: too soon\n");
}

// pojistka: denní soft limit
if ((int)$state['daily_requests'] >= DAILY_REQUEST_SOFT_LIMIT) {
    logSync($pdo, 'SKIPPED_DAILY_LIMIT', 'Denní soft limit API dosažen.', 0, 0, 0);
    $state['last_api_call_at'] = $now;
    saveSyncState($pdo, $state);
    exit("SKIPPED: daily limit\n");
}

// after TS – start od 1.9.2025, pokud ještě není nastaveno
$afterTs = (int)$state['last_after_ts'];
if ($afterTs < START_AFTER_TS) {
    $afterTs = START_AFTER_TS;
}

$apiRequests = 0;
$inserted    = 0;
$updated     = 0;

try {
    $apiRequests++;
    $data = callOrdersApi($afterTs);

    $state['daily_requests']++;
    $state['last_api_call_at'] = $now;

    if (!($data['success'] ?? false)) {
        $msg = 'API success = false; report: ' . ($data['report'] ?? '');
        logSync($pdo, 'API_ERROR', $msg, $apiRequests, 0, 0);
        saveSyncState($pdo, $state);
        exit("ERROR: $msg\n");
    }

    $params    = $data['params'] ?? [];
    $orderList = $params['orderList'] ?? [];

    if (empty($orderList)) {
        logSync($pdo, 'NO_NEW_ORDERS', 'API vrátilo prázdný orderList.', $apiRequests, 0, 0);
        $state['last_after_ts'] = $afterTs;
        saveSyncState($pdo, $state);
        exit("OK: no new orders\n");
    }

    $pdo->beginTransaction();

    $maxCreatedTs = $afterTs;

    foreach ($orderList as $order) {
        $id_order = (int)($order['id_order'] ?? 0);

        $isInsert = upsertOrder($pdo, $order);
        if ($isInsert) {
            $inserted++;
        } else {
            $updated++;
        }

        $rowList = $order['row_list'] ?? [];
        replaceOrderItems($pdo, $id_order, $rowList);

        $createdRaw = $order['created']['date'] ?? null;
        if ($createdRaw) {
            $ts = strtotime($createdRaw);
            if ($ts !== false && $ts > $maxCreatedTs) {
                $maxCreatedTs = $ts;
            }
        }
    }

    $pdo->commit();

    $state['last_after_ts'] = $maxCreatedTs;
    saveSyncState($pdo, $state);

    $msg = "Staženo objednávek: " . count($orderList)
         . ", insert: $inserted, update: $updated, new_after_ts: $maxCreatedTs";
    logSync($pdo, 'OK', $msg, $apiRequests, $inserted, $updated);

    // Právě vloženým položkám doplníme nejdříve EAN a potom cenu z aktuálního zdroje.
    try {
        if (function_exists('cfloat_fill_ean_auto')) {
            cfloat_fill_ean_auto($pdo, false);
        }
        if (function_exists('cfloat_fill_purchase_price_auto')) {
            cfloat_fill_purchase_price_auto($pdo, true, false);
        }
    } catch (Throwable $fillError) {
        error_log('sync_orders_hist.php - doplnění EAN/cen po syncu: ' . $fillError->getMessage());
    }

    echo "OK: $msg\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $msg = 'Výjimka: ' . $e->getMessage();
    logSync($pdo, 'EXCEPTION', $msg, $apiRequests, $inserted, $updated);
    saveSyncState($pdo, $state);
    echo "ERROR: $msg\n";
}
