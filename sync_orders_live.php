<?php
// sync_orders_live.php – LIVE refresh objednávek za posledních X dní
// - bere okno posledních LIVE_WINDOW_DAYS dní (aktuálně 7)
// - stránkuje po LIVE_LIMIT_PER_PAGE (max 100)
// - v jednom běhu max LIVE_MAX_REQUESTS_PER_RUN requestů
// - aktualizuje orders + order_items (mj. platbu, stav, dopravu, faktury...)
//   -> odsud čerpá modul Objednávky (objednavky.php), takže po každém běhu
//      tohoto skriptu má cfloat aktuální data z e-shopu (jednosměrně, e-shop -> cfloat)
// - používá sync_state pro denní limity, ale NEMĚNÍ last_after_ts (to nechává historickému skriptu)
//
// Spuštění (cron přes hosting panel, WEDOS nemá SSH):
//   https://cfloat.cz/sync_orders_live.php?token=TOKEN_ZE_SOUBORU_secrets/cron_run_token.php
//   (doporučeno každých 5 minut; token viz secrets/cron_run_token.php)
// Pokud skript spouští cron přímo přes php-cli, token se nekontroluje.

require __DIR__ . '/config.php'; // config musí vytvořit $pdo (PDO)

// --- Ochrana proti spuštění kýmkoliv přes veřejnou URL ---
require_once __DIR__ . '/_cron_guard.php';

// --- AUTO: doplnění EAN + nákupní ceny po syncu ---
require_once __DIR__ . '/fill_ean_auto.php';
require_once __DIR__ . '/fill_purchase_price_auto.php';


// --- Zajistí sloupec order_items.vavrys_check (auto-migrace, hosting bez SSH) ---
// Nezávisle na objednavky.php, aby na pořadí nahrání souborů nezáleželo.
try {
    $colCheck = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'vavrys_check' LIMIT 1");
    $colCheck->execute();
    if (!$colCheck->fetchColumn()) {
        $pdo->exec("ALTER TABLE `order_items` ADD COLUMN `vavrys_check` TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Throwable $e) {
    // Necháme běžet dál - v nejhorším se zaškrtnutí u změněných objednávek jednou neuloží.
}

/**
 * KONFIG
 */

// API URL (objednávky v2)
// Heslo k e-shop API (dřív natvrdo v kódu) – viz secrets/eshop_api_password.php
$__cfloatEshopPass = (string) include __DIR__ . '/secrets/eshop_api_password.php';
define('BASE_API_URL', 'https://www.c-store.cz/request.php?action=GetOrders&version=v2.0&password=' . rawurlencode($__cfloatEshopPass));


// okno live refresh – v dnech (můžeš změnit na 4, když budeš chtít)
const LIVE_WINDOW_DAYS = 7;

// kolik objednávek na stránku (max 100 dle API)
const LIVE_LIMIT_PER_PAGE = 100;

// max počet requestů za jeden běh live skriptu
const LIVE_MAX_REQUESTS_PER_RUN = 5;

// denní limity (sdílené s historickým skriptem)
const LIVE_MIN_SECONDS_BETWEEN_RUNS = 15;      // pojistka mezi běhy (hist + live) - při cronu po 5 min v pohodě
const DAILY_REQUEST_SOFT_LIMIT       = 1900;   // celkový soft limit (hist + live) - cron po 5 min = max 288 běhů/den, v pohodě

/**
 * POMOCNÉ FUNKCE – sync_state, log, API
 */

function live_loadSyncState(PDO $pdo): array {
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

function live_saveSyncState(PDO $pdo, array $state): void {
    // V LIVE skriptu NEMĚNÍME last_after_ts – to patří HIST skriptu
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

function live_logSync(PDO $pdo, string $status, string $message, int $apiRequests, int $inserted, int $updated): void {
    $stmt = $pdo->prepare("
        INSERT INTO sync_log (run_at, run_type, status, message, api_requests, orders_inserted, orders_updated)
        VALUES (NOW(), 'LIVE', :status, :message, :api_requests, :inserted, :updated)
    ");
    $stmt->execute([
        ':status'       => $status,
        ':message'      => $message,
        ':api_requests' => $apiRequests,
        ':inserted'     => $inserted,
        ':updated'      => $updated,
    ]);
}

function live_callOrdersApi(int $afterTs, int $limit): array {
    $url = BASE_API_URL
        . '&limit=' . $limit
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

/**
 * STEJNÉ funkce jako v HIST skriptu – upsert orders + items
 */

function upsertOrder(PDO $pdo, array $order): ?bool {
    // true = INSERT, false = UPDATE, null = BEZE ZMĚNY (SKIP)

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
    $stmt = $pdo->prepare("SELECT id, raw_json FROM orders WHERE id_order = :id_order");
    $stmt->execute([':id_order' => $id_order]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $existingId = $existing['id'] ?? false;

    // Pokud order v DB existuje a API payload je IDENTICKÝ, tak nic neděláme (žádné UPDATE, žádné mazání itemů)
    if ($existingId !== false) {
        $existingRaw = (string)($existing['raw_json'] ?? '');
        if ($existingRaw !== '' && hash_equals($existingRaw, $rawJson)) {
            return null; // BEZE ZMĚNY
        }
    }

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

    // Pokud API vrátí prázdný seznam, NIC nemažeme (ochrana proti vymazání položek)
    if (empty($rowList)) {
        return;
    }

    // 1) Zachovej doplněné sloupce (EAN + nakupni_cena + zaškrtnutí k Vavrys) pro tuto objednávku
    //    Klíč: product_id_variant_id (fallback: product_number)
    $keepPV = [];
    $keepPN = [];
    $stmtKeep = $pdo->prepare("SELECT product_id, variant_id, product_number, EAN, nakupni_cena, vavrys_check FROM order_items WHERE id_order = :id_order");
    $stmtKeep->execute([':id_order' => $id_order]);
    while ($r = $stmtKeep->fetch(PDO::FETCH_ASSOC)) {
        $pid = isset($r['product_id']) ? (string)$r['product_id'] : '';
        $vid = isset($r['variant_id']) ? (string)$r['variant_id'] : '';
        $pn  = isset($r['product_number']) ? (string)$r['product_number'] : '';

        $ean = isset($r['EAN']) ? trim((string)$r['EAN']) : '';
        $nc  = $r['nakupni_cena']; // může být NULL
        $vc  = !empty($r['vavrys_check']) ? 1 : 0;

        if ($pid !== '') {
            $key = $pid . '_' . $vid;
            $keepPV[$key] = ['EAN' => ($ean !== '' ? $ean : null), 'nakupni_cena' => $nc, 'vavrys_check' => $vc];
        }
        if ($pn !== '') {
            $keepPN[$pn] = ['EAN' => ($ean !== '' ? $ean : null), 'nakupni_cena' => $nc, 'vavrys_check' => $vc];
        }
    }

    // 2) Smaž staré položky jen pro tuto objednávku (DB se nemaže celá)
    $stmt = $pdo->prepare("DELETE FROM order_items WHERE id_order = :id_order");
    $stmt->execute([':id_order' => $id_order]);

    // 3) Vlož nové položky z API + vrať EAN/nakupni_cena/zaškrtnutí, pokud už byly doplněné dříve
    $sql = "
        INSERT INTO order_items (
          id_order,
          product_number, product_name, variant_description,
          price_per_unit, price_per_unit_with_vat,
          price_total, price_total_with_vat, price_vat, vat,
          `count`, unit,
          product_id, variant_id,
          raw_json,
          EAN,
          nakupni_cena,
          vavrys_check
        ) VALUES (
          :id_order,
          :product_number, :product_name, :variant_description,
          :price_per_unit, :price_per_unit_with_vat,
          :price_total, :price_total_with_vat, :price_vat, :vat,
          :count, :unit,
          :product_id, :variant_id,
          :raw_json,
          :EAN,
          :nakupni_cena,
          :vavrys_check
        )
    ";
    $stmtIns = $pdo->prepare($sql);

    foreach ($rowList as $item) {
        $product_number = isset($item['product_number']) ? (string)$item['product_number'] : '';
        $product_id = isset($item['product_id']) ? (string)$item['product_id'] : '';
        $variant_id = isset($item['variant_id']) ? (string)$item['variant_id'] : '';

        $key = ($product_id !== '' ? ($product_id . '_' . $variant_id) : '');

        $pres = null;
        if ($key !== '' && isset($keepPV[$key])) {
            $pres = $keepPV[$key];
        } elseif ($product_number !== '' && isset($keepPN[$product_number])) {
            $pres = $keepPN[$product_number];
        }

        $ean_pres = $pres['EAN'] ?? null;
        $nakup_pres = $pres['nakupni_cena'] ?? null;
        $vavrys_check_pres = $pres['vavrys_check'] ?? 0;

        $stmtIns->execute([
            ':id_order' => $id_order,
            ':product_number' => $product_number,
            ':product_name' => isset($item['product_name']) ? (string)$item['product_name'] : null,
            ':variant_description' => isset($item['variant_description']) ? (string)$item['variant_description'] : null,
            ':price_per_unit' => isset($item['price_per_unit']) ? (string)$item['price_per_unit'] : null,
            ':price_per_unit_with_vat' => isset($item['price_per_unit_with_vat']) ? (string)$item['price_per_unit_with_vat'] : null,
            ':price_total' => isset($item['price_total']) ? (string)$item['price_total'] : null,
            ':price_total_with_vat' => isset($item['price_total_with_vat']) ? (string)$item['price_total_with_vat'] : null,
            ':price_vat' => isset($item['price_vat']) ? (string)$item['price_vat'] : null,
            ':vat' => isset($item['vat']) ? (string)$item['vat'] : null,
            ':count' => isset($item['count']) ? (string)$item['count'] : null,
            ':unit' => isset($item['unit']) ? (string)$item['unit'] : null,
            ':product_id' => ($product_id !== '' ? $product_id : null),
            ':variant_id' => ($variant_id !== '' ? $variant_id : null),
            ':raw_json' => isset($item['raw_json']) ? (string)$item['raw_json'] : null,
            ':EAN' => $ean_pres,
            ':nakupni_cena' => $nakup_pres,
            ':vavrys_check' => $vavrys_check_pres,
        ]);
    }
}

/**
 * HLAVNÍ BĚH LIVE SYNC
 */

global $pdo;

$state = live_loadSyncState($pdo);
$now   = time();
$today = date('Y-m-d', $now);

// reset denního limitu při změně dne
if ($state['daily_date'] !== $today) {
    $state['daily_date']    = $today;
    $state['daily_requests'] = 0;
}

// pojistka: moc brzo po posledním volání (společné pro HIST + LIVE)
if ($now - (int)$state['last_api_call_at'] < LIVE_MIN_SECONDS_BETWEEN_RUNS) {
    live_logSync($pdo, 'SKIPPED_TOO_SOON', 'Příliš brzy po posledním volání API (LIVE).', 0, 0, 0);
    live_saveSyncState($pdo, $state);
    exit("SKIPPED: too soon (LIVE)\n");
}

// pojistka: denní soft limit
if ((int)$state['daily_requests'] >= DAILY_REQUEST_SOFT_LIMIT) {
    live_logSync($pdo, 'SKIPPED_DAILY_LIMIT', 'Denní soft limit API dosažen (LIVE).', 0, 0, 0);
    $state['last_api_call_at'] = $now;
    live_saveSyncState($pdo, $state);
    exit("SKIPPED: daily limit (LIVE)\n");
}

// okno: posledních LIVE_WINDOW_DAYS od teď
$windowStartTs = $now - LIVE_WINDOW_DAYS * 24 * 60 * 60;
$afterTs       = $windowStartTs;

$apiRequests = 0;
$inserted = 0;
$updated = 0;
$skipped = 0;
$totalOrders = 0;

try {
    $pages = 0;

    while ($pages < LIVE_MAX_REQUESTS_PER_RUN) {
        // kontrola denního limitu před každým requestem
        if ((int)$state['daily_requests'] >= DAILY_REQUEST_SOFT_LIMIT) {
            break;
        }

        $apiRequests++;
        $pages++;

        $data = live_callOrdersApi($afterTs, LIVE_LIMIT_PER_PAGE);

        $state['daily_requests']++;
        $state['last_api_call_at'] = time();

        if (!($data['success'] ?? false)) {
            $msg = 'API success = false; report: ' . ($data['report'] ?? '');
            live_logSync($pdo, 'API_ERROR', $msg, $apiRequests, $inserted, $updated);
            live_saveSyncState($pdo, $state);
            exit("ERROR: $msg\n");
        }

        $params    = $data['params'] ?? [];
        $orderList = $params['orderList'] ?? [];

        if (empty($orderList)) {
            if ($apiRequests === 1 && $totalOrders === 0) {
                live_logSync($pdo, 'NO_NEW_ORDERS', 'LIVE: API vrátilo prázdný orderList v daném okně.', $apiRequests, 0, 0);
                live_saveSyncState($pdo, $state);
                exit("OK: no orders in LIVE window\n");
            }
            break;
        }

        $pdo->beginTransaction();

        $maxCreatedTs = $afterTs;

        foreach ($orderList as $order) {
            $id_order = (int)($order['id_order'] ?? 0);

            // pro stránkování posouváme maxCreatedTs bez ohledu na to, jestli se objednávka změnila
            $createdRaw = $order['created']['date'] ?? null;
            if ($createdRaw) {
                $ts = strtotime($createdRaw);
                if ($ts !== false && $ts > $maxCreatedTs) {
                    $maxCreatedTs = $ts;
                }
            }

            $isInsert = upsertOrder($pdo, $order);
            if ($isInsert === null) {
                $skipped++;
                // BEZE ZMĚNY → nesaháme na order_items (žádné mazání/vkládání)
                continue;
            } elseif ($isInsert) {
                $inserted++;
            } else {
                $updated++;
            }

            $rowList = $order['row_list'] ?? [];
            replaceOrderItems($pdo, $id_order, $rowList);
        }

        $pdo->commit();

        $countThisPage = count($orderList);
        $totalOrders  += $countThisPage;

        // stránkování – posuneme afterTs na nejvyšší created z této stránky
        if ($maxCreatedTs > $afterTs) {
            $afterTs = $maxCreatedTs;
        } else {
            // bezpečnost proti nekonečné smyčce
            break;
        }

        // pokud stránka nebyla plná, končíme
        if ($countThisPage < LIVE_LIMIT_PER_PAGE) {
            break;
        }

        // respektování limitu 1 request / 10 s – pokud následuje další request
        if ($pages < LIVE_MAX_REQUESTS_PER_RUN) {
            sleep(11);
        }
    }

    live_saveSyncState($pdo, $state);

    if ($totalOrders === 0) {
        live_logSync($pdo, 'NO_NEW_ORDERS', 'LIVE: žádné objednávky v okně nebo vše již pokryto.', $apiRequests, $inserted, $updated);
        echo "OK: no orders in LIVE window\n";
    } else {
        $msg = "LIVE: zpracováno objednávek: $totalOrders, insert: $inserted, update: $updated, skip: $skipped, req: $apiRequests";
        live_logSync($pdo, 'OK', $msg, $apiRequests, $inserted, $updated);
        echo "OK: $msg\n";
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $msg = 'LIVE výjimka: ' . $e->getMessage();
    live_logSync($pdo, 'EXCEPTION', $msg, $apiRequests, $inserted, $updated);
    live_saveSyncState($pdo, $state);
    echo "ERROR: $msg\n";
}


// Druhý průchod po stažení objednávek: doplní EAN i do právě vložených položek.
try {
    if (function_exists('cfloat_fill_ean_auto')) {
        cfloat_fill_ean_auto($pdo, false);
    }
} catch (Throwable $e) {
    error_log('sync_orders_live.php - doplnění EAN po syncu: ' . $e->getMessage());
}


// Hned po doplnění EAN načteme aktuální nákupní ceny a doplníme je do nových položek.
try {
    if (function_exists('cfloat_fill_purchase_price_auto')) {
        cfloat_fill_purchase_price_auto($pdo, true, false);
    }
} catch (Throwable $e) {
    error_log('sync_orders_live.php - doplnění nákupních cen po syncu: ' . $e->getMessage());
}
