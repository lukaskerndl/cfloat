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
require_once __DIR__ . '/lib/vavrys_katalog.php';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function db_has_column(PDO $pdo, string $table, string $column, bool $forceRefresh = false): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!$forceRefresh && isset($cache[$key])) return $cache[$key];
    try {
        $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1");
        $st->execute([':t' => $table, ':c' => $column]);
        $ok = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        $ok = false;
    }
    return $cache[$key] = $ok;
}

/** Přidá sloupec, pokud ještě neexistuje. Vrátí true, pokud sloupec po zavolání existuje
 *  (ať už existoval už předtím, nebo se ho podařilo vytvořit teď). */
function db_ensure_column(PDO $pdo, string $table, string $column, string $definitionSql): bool {
    if (db_has_column($pdo, $table, $column)) return true;
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definitionSql}");
    } catch (Throwable $e) {
        // Typicky chybí právo ALTER na sdíleném hostingu - sloupec je pak potřeba
        // založit ručně přes phpMyAdmin (viz upozornění v UI níže).
        return false;
    }
    return db_has_column($pdo, $table, $column, true); // vynutit čerstvé ověření (obejít cache)
}

function fmt_money($amount, $currencyCode = 'CZK'): string {
    $cc = strtoupper(trim((string)$currencyCode));
    if ($cc === '') $cc = 'CZK';
    $suffix = ($cc === 'EUR') ? ' €' : ' Kč';
    return number_format((float)$amount, 2, ',', ' ') . $suffix;
}

/**
 * Výprodejové/akční zboží (podle textu v názvu produktu) je vždy skladem -
 * dokud nebude napojení na e-shop s reálnou skladovou dostupností, je tohle
 * jediný spolehlivý signál, že položku máme fyzicky na skladě.
 */
function cfloat_is_always_stock(string $productName): bool
{
    $n = mb_strtolower($productName, 'UTF-8');
    foreach (['výprodej', 'vyprodej', 'akce'] as $kw) {
        if (mb_stripos($n, $kw) !== false) return true;
    }
    return false;
}

/**
 * Spočítá, co z položek zaškrtnutých u dané objednávky (order_items.vavrys_check = 1)
 * jde a nejde odeslat u Vavrys. Volá se dvakrát - jednou pro náhled, podruhé znovu
 * (kvůli aktuálnosti) těsně před skutečným odesláním.
 */
function vavrys_prepare_preview(PDO $pdo, int $idOrder): ?array
{
    if ($idOrder <= 0) return null;
    if (!db_has_column($pdo, 'order_items', 'vavrys_check')) return null; // sloupec chybí, viz upozornění nahoře na stránce
    try {
        $stO = $pdo->prepare('SELECT id_order, number FROM orders WHERE id_order = :id');
        $stO->execute([':id' => $idOrder]);
        $orderRow = $stO->fetch(PDO::FETCH_ASSOC);
        if (!$orderRow) return null;

        $stI = $pdo->prepare("SELECT id, product_number, product_name, variant_description, `count`, `EAN` AS ean
            FROM order_items WHERE id_order = :id AND vavrys_check = 1");
        $stI->execute([':id' => $idOrder]);
        $items = $stI->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Zbytek objednávky - položky, které JDOU k Vavrys (podle značky), ale nejsou zaškrtnuté.
        // Na tohle chceme staff ještě jednou upozornit před odesláním.
        $uncheckedEligible = [];
        $stU = $pdo->prepare("SELECT product_number, product_name, variant_description, `count`
            FROM order_items WHERE id_order = :id AND vavrys_check = 0");
        $stU->execute([':id' => $idOrder]);
        while ($u = $stU->fetch(PDO::FETCH_ASSOC)) {
            $uName = (string)($u['product_name'] ?? '');
            if (!vpo_is_allowed_brand($uName)) continue;
            $uQty = (int)round((float)($u['count'] ?: 1));
            if ($uQty < 1) $uQty = 1;
            $uncheckedEligible[] = [
                'code' => (string)($u['product_number'] ?? ''),
                'name' => $uName,
                'velikost' => vpo_display_velikost((string)($u['variant_description'] ?? '')),
                'qty' => $uQty,
            ];
        }
    } catch (Throwable $e) {
        return null;
    }

    $token = bin2hex(random_bytes(16));
    if (empty($items)) {
        return ['id_order' => $idOrder, 'cislo' => (string)$orderRow['number'], 'items' => [],
            'skip_other' => [], 'skip_notfound' => [], 'skip_stock' => [],
            'unchecked_eligible' => $uncheckedEligible, 'token' => $token];
    }

    $file = vpo_find_vavrys_file();
    $ready = []; $skipOther = []; $skipNotFound = []; $skipStock = [];

    foreach ($items as $row) {
        $code = (string)($row['product_number'] ?? '');
        $name = (string)($row['product_name'] ?? '');
        $velikostRaw = (string)($row['variant_description'] ?? '');
        $velikostDisp = vpo_display_velikost($velikostRaw);
        $qty = (int)round((float)($row['count'] ?: 1));
        if ($qty < 1) $qty = 1;

        if (!vpo_is_allowed_brand($name)) {
            $skipOther[] = ['code' => $code, 'name' => $name, 'velikost' => $velikostDisp];
            continue;
        }
        if ($file === null) {
            $skipNotFound[] = ['code' => $code, 'name' => $name, 'velikost' => $velikostDisp];
            continue;
        }
        $match = vpo_lookup_item($file, $row['ean'] ?? null, $row['product_number'] ?? null, $velikostRaw);
        if ($match === null) {
            $skipNotFound[] = ['code' => $code, 'name' => $name, 'velikost' => $velikostDisp];
            continue;
        }
        $sklad = (int)round((float)$match['mnozstviSklad']);
        if ($qty > $sklad) {
            $skipStock[] = ['code' => $code, 'name' => $name, 'velikost' => $velikostDisp, 'sklad' => $sklad, 'qty' => $qty];
            continue;
        }
        $ready[] = ['code' => $code, 'name' => $name, 'velikost' => $velikostDisp, 'qty' => $qty, 'match' => $match];
    }

    return [
        'id_order' => $idOrder,
        'cislo' => (string)$orderRow['number'],
        'items' => $ready,
        'skip_other' => $skipOther,
        'skip_notfound' => $skipNotFound,
        'skip_stock' => $skipStock,
        'unchecked_eligible' => $uncheckedEligible,
        'token' => $token,
    ];
}

// ---------------------------------------------------------------------------
// AUTO-MIGRACE: sloupce pro zaškrtávání položek k Vavrys a poznámku k objednávce
// (hosting je bez SSH, takže se sloupce doplní samy při prvním načtení stránky -
//  pokud na to má DB uživatel právo ALTER; pokud ne, zobrazí se dole návod na ruční SQL)
// ---------------------------------------------------------------------------
$vavrysMissingColumns = [];
$vavrysColumnDefs = [
    ['order_items', 'vavrys_check', 'TINYINT(1) NOT NULL DEFAULT 0'],
    ['orders', 'vavrys_note', 'TEXT NULL'],
    ['orders', 'vavrys_sent_at', 'DATETIME NULL'],
    ['orders', 'vavrys_status', 'VARCHAR(10) NULL'],
];
foreach ($vavrysColumnDefs as [$vTable, $vCol, $vDef]) {
    if (!db_ensure_column($pdo, $vTable, $vCol, $vDef)) {
        $vavrysMissingColumns[] = [$vTable, $vCol, $vDef];
    }
}
$vavrysHasCheckCol  = db_has_column($pdo, 'order_items', 'vavrys_check');
$vavrysHasNoteCol   = db_has_column($pdo, 'orders', 'vavrys_note');
$vavrysHasSentCol   = db_has_column($pdo, 'orders', 'vavrys_sent_at');
$vavrysHasStatusCol = db_has_column($pdo, 'orders', 'vavrys_status');

// ---------------------------------------------------------------------------
// AJAX: zaškrtnutí/odškrtnutí položky k Vavrys (persistuje se hned, i před
// finálním odesláním objednávky) a uložení vlastní poznámky k objednávce
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vavrys_toggle_ajax') {
    header('Content-Type: application/json; charset=UTF-8');
    $itemId = (int)($_POST['item_id'] ?? 0);
    $checked = !empty($_POST['checked']) ? 1 : 0;
    try {
        if ($itemId <= 0) throw new RuntimeException('Chybí ID položky.');
        $st = $pdo->prepare('UPDATE order_items SET vavrys_check = :c WHERE id = :id');
        $st->execute([':c' => $checked, ':id' => $itemId]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vavrys_ulozit_poznamku_ajax') {
    header('Content-Type: application/json; charset=UTF-8');
    $idOrderP = (int)($_POST['id_order'] ?? 0);
    $noteText = trim((string)($_POST['note'] ?? ''));
    try {
        if ($idOrderP <= 0) throw new RuntimeException('Chybí ID objednávky.');
        $st = $pdo->prepare('UPDATE orders SET vavrys_note = :n WHERE id_order = :id');
        $st->execute([':n' => ($noteText !== '' ? $noteText : null), ':id' => $idOrderP]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------------------------
// AKCE: Příprava odeslání objednávky Vavrys - spočítá, co jde a co nejde poslat,
// nic se ještě neodesílá. Zobrazí se náhled s finálním potvrzovacím tlačítkem.
// ---------------------------------------------------------------------------
$vavrysPreview = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vavrys_priprava') {
    $idOrderReq = (int)($_POST['id_order'] ?? 0);
    $vavrysPreview = vavrys_prepare_preview($pdo, $idOrderReq);
    if ($vavrysPreview !== null) {
        $_SESSION['vavrys_token_' . $idOrderReq] = $vavrysPreview['token'];
    }
}

// ---------------------------------------------------------------------------
// AKCE: Skutečné (závazné) odeslání objednávky Vavrys - jen po potvrzení náhledu.
// Jednorázový token + povinný checkbox + JS confirm, stejná pojistka jako
// u ruční objednávky v vavrys-objednavka-nahled.php. Post/Redirect/Get,
// aby nešlo omylem odeslat objednávku podruhé obnovením stránky.
// ---------------------------------------------------------------------------
$vavrysFlash = null;
if (isset($_SESSION['vavrys_flash'])) {
    $vavrysFlash = $_SESSION['vavrys_flash'];
    unset($_SESSION['vavrys_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vavrys_odeslat') {
    $idOrderReq = (int)($_POST['id_order'] ?? 0);
    $postToken = (string)($_POST['token'] ?? '');
    $sessKey = 'vavrys_token_' . $idOrderReq;
    $sessToken = (string)($_SESSION[$sessKey] ?? '');
    unset($_SESSION[$sessKey]);

    if ($idOrderReq <= 0 || $postToken === '' || $sessToken === '' || !hash_equals($sessToken, $postToken)) {
        $_SESSION['vavrys_flash'] = ['ok' => false, 'text' => 'Neplatný nebo už použitý bezpečnostní token. Otevřete přípravu objednávky znovu a zkuste to prosím ještě jednou.'];
    } elseif (empty($_POST['confirm_final'])) {
        $_SESSION['vavrys_flash'] = ['ok' => false, 'text' => 'Nebylo zaškrtnuto potvrzení, objednávka nebyla odeslána.'];
    } else {
        // Přepočítat čerstvě - nespoléhat na to, co bylo v prohlížeči při zobrazení náhledu.
        $preview = vavrys_prepare_preview($pdo, $idOrderReq);
        if ($preview === null || empty($preview['items'])) {
            $_SESSION['vavrys_flash'] = ['ok' => false, 'text' => 'Mezitím se nenašla žádná položka k odeslání (zaškrtnutí se asi změnilo). Zkuste přípravu objednávky znovu.'];
        } elseif (!empty($preview['unchecked_eligible']) && empty($_POST['confirm_unchecked'])) {
            $_SESSION['vavrys_flash'] = ['ok' => false, 'text' => 'Objednávka obsahuje další zboží od Vavrys, které jste nezaškrtli. Potvrďte prosím ve formuláři, že jde o záměr, a odešlete znovu.'];
        } elseif (!isset($VAVRYS_LOGIN, $VAVRYS_PASSWORD) || $VAVRYS_LOGIN === '' || $VAVRYS_PASSWORD === '') {
            $_SESSION['vavrys_flash'] = ['ok' => false, 'text' => 'Přihlašovací údaje k Vavrys nejsou k dispozici (nenačetly se z config.php).'];
        } else {
            $vlastniCislo = 'CSTORE-' . preg_replace('/[^A-Za-z0-9]/', '', $preview['cislo']) . '-' . date('His');
            $polozky = [];
            foreach ($preview['items'] as $it) {
                $polozky[] = [
                    'katalogId' => $it['match']['katalogId'],
                    'strCislo' => $it['match']['strCislo'],
                    'karCislo' => $it['match']['karCislo'],
                    'karCisloId' => $it['match']['karCisloId'],
                    'idX' => $it['match']['idX'],
                    'idY' => $it['match']['idY'],
                    'mnozstvi' => (int)$it['qty'],
                    'cena' => (float)$it['match']['cena'],
                ];
            }
            $objednavkaData = vpo_build_objednavka_data($vlastniCislo, date('Y-m-d'), $polozky);
            $sendResult = vpo_send_objednavka($VAVRYS_LOGIN, $VAVRYS_PASSWORD, $objednavkaData);

            // --- Sestavení poznámky k objednávce, formát: "<číslo produktu>, <produkt> <velikost> - <stav>" ---
            $noteLine = function (string $stav, string $code, string $name, string $velikost, ?int $qty = null): string {
                return ($code !== '' ? $code : '—') . ', ' . $name . ($velikost !== '' ? ' ' . $velikost : '')
                    . ($qty !== null ? ', ' . $qty . ' ks' : '') . ' - ' . $stav;
            };

            $noteLines = [];
            if (!empty($sendResult['success'])) {
                foreach ($preview['items'] as $it) {
                    $noteLines[] = $noteLine('objednáno u Vavrys', $it['code'], $it['name'], $it['velikost']);
                }
            } else {
                // Celé odeslání selhalo - položky, co měly jít k Vavrys, tedy fakticky neobjednány.
                foreach ($preview['items'] as $it) {
                    $noteLines[] = $noteLine('neobjednáno', $it['code'], $it['name'], $it['velikost']);
                }
            }
            foreach ($preview['skip_other'] as $s) {
                $noteLines[] = $noteLine('neobjednáno', $s['code'], $s['name'], $s['velikost']);
            }
            foreach ($preview['skip_stock'] as $s) {
                $noteLines[] = $noteLine('neobjednáno', $s['code'], $s['name'], $s['velikost']);
            }
            foreach ($preview['skip_notfound'] as $s) {
                $noteLines[] = $noteLine('zboží není', $s['code'], $s['name'], $s['velikost']);
            }
            foreach ($preview['unchecked_eligible'] as $s) {
                $noteLines[] = $noteLine('záměrně neobjednáno', $s['code'], $s['name'], $s['velikost'], $s['qty']);
            }
            if (empty($sendResult['success'])) {
                $noteLines[] = 'Odeslání k Vavrys selhalo (' . ($sendResult['message'] ?? ($sendResult['error'] ?? 'neznámá chyba')) . '), zkuste prosím znovu.';
            }
            $autoNote = implode("\n", $noteLines);

            // --- Barevný stav objednávky: zelená = vše v pořádku objednáno,
            // žlutá = objednávka má i zboží od jiného dodavatele (čeká zvlášť, bez ohledu na zaškrtnutí),
            // červená = k Vavrys se nepodařilo objednat nic. ---
            $allSentOk = !empty($sendResult['success']) && !empty($preview['items'])
                && empty($preview['skip_notfound']) && empty($preview['skip_stock']);

            $hasOtherSupplier = false;
            try {
                $stAll = $pdo->prepare('SELECT product_name FROM order_items WHERE id_order = :id');
                $stAll->execute([':id' => $idOrderReq]);
                while (($nm = $stAll->fetchColumn()) !== false) {
                    if (!vpo_is_allowed_brand((string)$nm)) { $hasOtherSupplier = true; break; }
                }
            } catch (Throwable $e) {}

            if ($hasOtherSupplier) {
                $vavrysStatus = 'yellow';
            } elseif ($allSentOk) {
                $vavrysStatus = 'green';
            } else {
                $vavrysStatus = 'red';
            }

            $existingNote = '';
            try {
                $stOrdN = $pdo->prepare('SELECT vavrys_note FROM orders WHERE id_order = :id');
                $stOrdN->execute([':id' => $idOrderReq]);
                $existingNote = trim((string)($stOrdN->fetchColumn() ?: ''));
            } catch (Throwable $e) {}

            $finalNote = trim($existingNote !== '' ? ($existingNote . "\n\n" . $autoNote) : $autoNote);
            try {
                $stSave = $pdo->prepare('UPDATE orders SET vavrys_note = :n, vavrys_sent_at = NOW(), vavrys_status = :s WHERE id_order = :id');
                $stSave->execute([':n' => $finalNote, ':s' => $vavrysStatus, ':id' => $idOrderReq]);
            } catch (Throwable $e) {}

            $_SESSION['vavrys_flash'] = !empty($sendResult['success'])
                ? ['ok' => true, 'text' => 'Objednávka byla úspěšně odeslána u Vavrys (' . count($preview['items']) . ' položek). Poznámka byla zapsána k objednávce.']
                : ['ok' => false, 'text' => 'Odeslání k Vavrys selhalo: ' . ($sendResult['message'] ?? ($sendResult['error'] ?? 'neznámá chyba'))];
        }
    }
    $redirectQuery = $_GET;
    header('Location: objednavky.php' . (!empty($redirectQuery) ? '?' . http_build_query($redirectQuery) : '') . '#order-' . $idOrderReq);
    exit;
}

// ---------------------------------------------------------------------------
// FILTRY
// ---------------------------------------------------------------------------
$ordersSearch      = trim((string)($_REQUEST['q'] ?? ''));
$ordersFilterEmail = trim((string)($_REQUEST['email'] ?? ''));
$ordersPage        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$ordersDateFromStr = trim((string)($_REQUEST['from'] ?? ''));
$ordersDateToStr   = trim((string)($_REQUEST['to'] ?? ''));
$ordersPerPage     = 100;
$ordersError       = '';
$fillFlash         = null; // ['ok'=>bool, 'summary'=>string, 'found'=>[], 'not_found'=>[]]

if ($ordersDateFromStr === '' && $ordersDateToStr === '') {
    try {
        $dtFrom = new DateTime('first day of this month 00:00:00');
        $dtTo   = new DateTime('tomorrow');
        $ordersDateFromStr = $dtFrom->format('Y-m-d');
        $ordersDateToStr   = $dtTo->format('Y-m-d');
    } catch (Exception $e) {
        $ordersDateFromStr = '';
        $ordersDateToStr   = '';
    }
}

$whereParts = [];
$params     = [];
$joinItems  = '';

$ordersCurrencyCol = '';
$ordersRateCol     = '';
$ordersInvUrlCol   = '';
$ordersInvHtmlCol  = '';
try {
    foreach (['currency_code','currency','selected_currency','selected_currency_code','mena','currencyCode'] as $c) {
        if (db_has_column($pdo, 'orders', $c)) { $ordersCurrencyCol = $c; break; }
    }
    foreach (['exchange_rate','exchangeRate','selected_currency_rate','currency_rate','rate','exchange_rate_value'] as $c) {
        if (db_has_column($pdo, 'orders', $c)) { $ordersRateCol = $c; break; }
    }
    foreach (['invoice_url','invoiceUrl','invoice_pdf_url','invoice_pdf','invoice_link'] as $c) {
        if (db_has_column($pdo, 'orders', $c)) { $ordersInvUrlCol = $c; break; }
    }
    foreach (['invoice_url_html','invoiceUrlHtml','invoice_html_url','invoice_html','invoice_link_html'] as $c) {
        if (db_has_column($pdo, 'orders', $c)) { $ordersInvHtmlCol = $c; break; }
    }
} catch (Throwable $e) {}

if ($ordersFilterEmail !== '') {
    $whereParts[] = 'LOWER(TRIM(o.customer_email)) = LOWER(TRIM(:email))';
    $params[':email'] = $ordersFilterEmail;
}
if ($ordersDateFromStr !== '') {
    $whereParts[] = 'o.created_at >= :from_date';
    $params[':from_date'] = $ordersDateFromStr . ' 00:00:00';
}
if ($ordersDateToStr !== '') {
    $whereParts[] = 'o.created_at <= :to_date';
    $params[':to_date'] = $ordersDateToStr . ' 23:59:59';
}
if ($ordersSearch !== '') {
    $joinItems = 'LEFT JOIN order_items oi ON oi.id_order = o.id_order';
    $searchVal = '%' . $ordersSearch . '%';
    $orParts = [];
    $i = 1;
    foreach (['number','invoice_number','customer_name','customer_email','customer_phone'] as $col) {
        if (db_has_column($pdo, 'orders', $col)) {
            $k = ':qs' . $i++; $orParts[] = "o.$col LIKE $k"; $params[$k] = $searchVal;
        }
    }
    if (db_has_column($pdo, 'order_items', 'product_name')) {
        $k = ':qs' . $i++; $orParts[] = "oi.product_name LIKE $k"; $params[$k] = $searchVal;
    }
    if (db_has_column($pdo, 'order_items', 'product_number')) {
        $k = ':qs' . $i++; $orParts[] = "oi.product_number LIKE $k"; $params[$k] = $searchVal;
    }
    if (!empty($orParts)) $whereParts[] = '(' . implode(' OR ', $orParts) . ')';
}
$whereSql = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// ---------------------------------------------------------------------------
// AKCE: Zobrazit nedoplněné ceny (jen náhled, nic se nezapisuje)
// ---------------------------------------------------------------------------
$missingResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'zobrazit_nedoplnene') {
    $missingResult = [];
    $mWhere = "(oi.nakupni_cena IS NULL OR oi.nakupni_cena = 0)";
    $mParams = [];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ordersDateFromStr)) {
        $mWhere .= ' AND o.created_at >= :d_from';
        $mParams[':d_from'] = $ordersDateFromStr . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ordersDateToStr)) {
        $mWhere .= ' AND o.created_at <= :d_to';
        $mParams[':d_to'] = $ordersDateToStr . ' 23:59:59';
    }
    try {
        $stM = $pdo->prepare("SELECT o.number, o.created_at, oi.product_number, oi.EAN AS ean,
                oi.product_name, oi.variant_description, oi.`count`
            FROM order_items oi INNER JOIN orders o ON o.id_order = oi.id_order
            WHERE {$mWhere} ORDER BY o.created_at DESC LIMIT 500");
        $stM->execute($mParams);
        while ($r = $stM->fetch(PDO::FETCH_ASSOC)) {
            $createdDisp = '';
            if (!empty($r['created_at'])) {
                try { $createdDisp = (new DateTime($r['created_at']))->format('d.m.Y H:i'); }
                catch (Exception $e) { $createdDisp = (string)$r['created_at']; }
            }
            $missingResult[] = [
                'number' => (string)($r['number'] ?? ''),
                'created_display' => $createdDisp,
                'product_number' => (string)($r['product_number'] ?? ''),
                'ean' => (string)($r['ean'] ?? ''),
                'product_name' => (string)($r['product_name'] ?? ''),
                'variant_description' => (string)($r['variant_description'] ?? ''),
                'count' => (string)($r['count'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $missingResult = [];
    }
}

// ---------------------------------------------------------------------------
// AKCE: Doplnit nákupní ceny (nová logika – jen z XML feedů)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'doplnit_nakupni_ceny') {
    @set_time_limit(300);
    $built = cfloat2_build_current_index();
    $currentIndex = $built['index'];

    $limit = 500;
    $fillWhere = "(oi.nakupni_cena IS NULL OR oi.nakupni_cena = 0)
                  AND ((oi.EAN IS NOT NULL AND TRIM(oi.EAN) <> '') OR (oi.product_number IS NOT NULL AND TRIM(oi.product_number) <> ''))";
    $fillParams = [];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ordersDateFromStr)) {
        $fillWhere .= ' AND o.created_at >= :d_from';
        $fillParams[':d_from'] = $ordersDateFromStr . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ordersDateToStr)) {
        $fillWhere .= ' AND o.created_at <= :d_to';
        $fillParams[':d_to'] = $ordersDateToStr . ' 23:59:59';
    }
    $rows = [];
    try {
        $st = $pdo->prepare("SELECT oi.id, oi.EAN, oi.product_number
            FROM order_items oi INNER JOIN orders o ON o.id_order = oi.id_order
            WHERE {$fillWhere} ORDER BY o.created_at DESC LIMIT {$limit}");
        $st->execute($fillParams);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $fillFlash = ['ok' => false, 'summary' => 'Chyba při načítání položek: ' . $e->getMessage(), 'found' => [], 'not_found' => []];
    }

    if ($fillFlash === null) {
        $updateStmt = $pdo->prepare("UPDATE order_items SET nakupni_cena = :price WHERE id = :id");
        $found = [];
        $notFound = [];
        $archiveCache = [];
        foreach ($rows as $row) {
            $hit = cfloat2_lookup_price($row['EAN'] ?? null, $row['product_number'] ?? null, $currentIndex, $archiveCache);
            if ($hit !== null) {
                $updateStmt->execute([':price' => round($hit['price'], 2), ':id' => (int)$row['id']]);
                $found[] = $row['id'];
            } else {
                $notFound[] = $row['id'];
            }
        }
        $fillFlash = [
            'ok' => true,
            'summary' => 'Zpracováno ' . count($rows) . ' položek – doplněno ' . count($found) . ', nenalezeno ' . count($notFound) . '.',
            'found' => $found,
            'not_found' => $notFound,
        ];
    }
}

// ---------------------------------------------------------------------------
// EXPORT SILVINI (zachováno beze změny)
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && (string)$_GET['export'] === 'silvini') {
    try {
        $exportEanParts = ["NULLIF(TRIM(oi.`EAN`), '')"];
        $exportEanJoins = '';
        if (db_has_column($pdo, 'order_items', 'product_id') && db_has_column($pdo, 'order_items', 'variant_id')
            && db_has_column($pdo, 'ean_map', 'product_id') && db_has_column($pdo, 'ean_map', 'variant_id') && db_has_column($pdo, 'ean_map', 'ean')) {
            $exportEanJoins .= " LEFT JOIN ean_map em ON em.product_id = oi.product_id AND em.variant_id = oi.variant_id";
            $exportEanParts[] = "NULLIF(TRIM(em.`ean`), '')";
        }
        if (db_has_column($pdo, 'order_items', 'product_id') && db_has_column($pdo, 'order_items', 'variant_id')
            && db_has_column($pdo, 'Kompletni_DatabazeVariantyEANProdejeCeny', 'product_id') && db_has_column($pdo, 'Kompletni_DatabazeVariantyEANProdejeCeny', 'variant_id') && db_has_column($pdo, 'Kompletni_DatabazeVariantyEANProdejeCeny', 'ean')) {
            $exportEanJoins .= " LEFT JOIN (SELECT product_id, variant_id, MAX(NULLIF(TRIM(ean), '')) AS ean
                FROM Kompletni_DatabazeVariantyEANProdejeCeny WHERE ean IS NOT NULL AND TRIM(ean) <> '' GROUP BY product_id, variant_id) kev
                ON kev.product_id = oi.product_id AND kev.variant_id = oi.variant_id";
            $exportEanParts[] = "NULLIF(TRIM(kev.`ean`), '')";
        }
        $exportEanSql = 'COALESCE(' . implode(', ', $exportEanParts) . ", '')";
        $sqlExport = "SELECT oi.product_name AS produkt, $exportEanSql AS ean,
                (CASE WHEN oi.`count` IS NULL OR oi.`count` = 0 THEN 1 ELSE oi.`count` END) AS ks
            FROM order_items oi $exportEanJoins
            JOIN (SELECT DISTINCT o.id_order FROM orders o $joinItems $whereSql) x ON x.id_order = oi.id_order
            WHERE oi.product_name IS NOT NULL AND LOWER(oi.product_name) LIKE '%silvini%'
            ORDER BY oi.product_name ASC, ean ASC";
        $stEx = $pdo->prepare($sqlExport);
        $stEx->execute($params);
        $exportRows = $stEx->fetchAll(PDO::FETCH_ASSOC) ?: [];

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="silvini_export.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Produkt', 'EAN', 'Ks'], ';');
        foreach ($exportRows as $r) fputcsv($out, [$r['produkt'], $r['ean'], $r['ks']], ';');
        fclose($out);
        exit;
    } catch (Throwable $e) {
        $ordersError = 'Chyba při exportu: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// NAČTENÍ OBJEDNÁVEK
// ---------------------------------------------------------------------------
$ordersTotal = 0;
$ordersRows = [];
$ordersItemsById = [];
$ordersPurchaseById = [];
$ordersProfitById = [];
$ordersMissingById = [];
$ordersEmailStats = [];
$ordersProfitSum = 0.0;

try {
    $stC = $pdo->prepare("SELECT COUNT(DISTINCT o.id_order) AS c FROM orders o $joinItems $whereSql");
    $stC->execute($params);
    $ordersTotal = (int)($stC->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $ordersError = 'Chyba při načítání počtu objednávek: ' . $e->getMessage();
}

if ($ordersError === '' && $ordersTotal > 0) {
    $offset = ($ordersPage - 1) * $ordersPerPage;
    try {
        $selectExtra = '';
        if ($ordersCurrencyCol !== '') $selectExtra .= ", o.`{$ordersCurrencyCol}` AS currency_code";
        if ($ordersRateCol !== '')     $selectExtra .= ", o.`{$ordersRateCol}` AS exchange_rate";
        if ($ordersInvUrlCol !== '')   $selectExtra .= ", o.`{$ordersInvUrlCol}` AS invoice_url";
        if ($ordersInvHtmlCol !== '')  $selectExtra .= ", o.`{$ordersInvHtmlCol}` AS invoice_url_html";
        if (db_has_column($pdo, 'orders', 'vavrys_note'))    $selectExtra .= ", o.`vavrys_note` AS vavrys_note";
        if (db_has_column($pdo, 'orders', 'vavrys_sent_at')) $selectExtra .= ", o.`vavrys_sent_at` AS vavrys_sent_at";
        if (db_has_column($pdo, 'orders', 'vavrys_status'))  $selectExtra .= ", o.`vavrys_status` AS vavrys_status";

        $sql = "SELECT o.id_order, o.number, o.created_at, o.customer_name, o.customer_email, o.customer_phone,
                    o.total_price_with_vat, o.zaplaceno, o.gopay_zaplaceno, o.payment_name, o.delivery_name, o.invoice_number
                    $selectExtra
                FROM orders o $joinItems $whereSql
                GROUP BY o.id_order ORDER BY o.created_at DESC LIMIT :limit OFFSET :offset";
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':limit', $ordersPerPage, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();
        $ordersRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $ordersError = 'Chyba při načítání objednávek: ' . $e->getMessage();
    }
}

if ($ordersError === '') {
    try {
        $stE = $pdo->query("SELECT LOWER(TRIM(customer_email)) AS email_key, COUNT(*) AS cnt FROM orders
            WHERE customer_email IS NOT NULL AND TRIM(customer_email) <> '' GROUP BY email_key");
        while ($row = $stE->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['email_key'] ?? '';
            if ($key === '') continue;
            $ordersEmailStats[$key] = (int)($row['cnt'] ?? 0);
        }
    } catch (Throwable $e) {}
}

if ($ordersError === '' && !empty($ordersRows)) {
    $orderIds = [];
    foreach ($ordersRows as $r) if (isset($r['id_order'])) $orderIds[] = (int)$r['id_order'];
    $orderIds = array_values(array_unique($orderIds));

    if (!empty($orderIds)) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        try {
            $vavrysCheckSelectPart = $vavrysHasCheckCol ? ', vavrys_check' : '';
            $stI = $pdo->prepare("SELECT id, id_order, product_number, product_name, variant_description, `count`,
                    price_total_with_vat, `EAN` AS ean, nakupni_cena AS price_s_dph{$vavrysCheckSelectPart}
                FROM order_items WHERE id_order IN ($placeholders) ORDER BY id_order, product_name");
            $stI->execute($orderIds);
            while ($row = $stI->fetch(PDO::FETCH_ASSOC)) {
                $oid = (int)($row['id_order'] ?? 0);
                if ($oid <= 0) continue;
                if (!isset($ordersItemsById[$oid])) $ordersItemsById[$oid] = [];
                if (!isset($ordersPurchaseById[$oid])) $ordersPurchaseById[$oid] = 0.0;

                $qty = 1.0;
                if (isset($row['count']) && $row['count'] !== null) {
                    $q = (float)$row['count'];
                    if ($q > 0) $qty = $q;
                }
                $rawPurchase = $row['price_s_dph'] ?? null;
                $priceSDph = ($rawPurchase !== null && $rawPurchase !== '' && (float)$rawPurchase > 0) ? (float)$rawPurchase : 0.0;
                $missingPurchase = !($rawPurchase !== null && $rawPurchase !== '' && (float)$rawPurchase > 0);

                if (!isset($ordersProfitById[$oid])) $ordersProfitById[$oid] = 0.0;
                if (!isset($ordersMissingById[$oid])) $ordersMissingById[$oid] = false;

                $lineTotal = isset($row['price_total_with_vat']) ? (float)$row['price_total_with_vat'] : 0.0;
                if (!$missingPurchase) {
                    $linePurchase = $priceSDph * $qty;
                    $ordersPurchaseById[$oid] += $linePurchase;
                    $ordersProfitById[$oid] += ($lineTotal - $linePurchase);
                } else {
                    $ordersMissingById[$oid] = true;
                }

                $ordersItemsById[$oid][] = [
                    'id' => (int)($row['id'] ?? 0),
                    'code' => (string)($row['product_number'] ?? ''),
                    'ean' => (string)($row['ean'] ?? ''),
                    'name' => (string)($row['product_name'] ?? ''),
                    'variant' => (string)($row['variant_description'] ?? ''),
                    'variant_disp' => vpo_display_velikost((string)($row['variant_description'] ?? '')),
                    'qty' => $qty,
                    'price_s_dph' => $priceSDph,
                    'missing_purchase' => $missingPurchase,
                    'total' => $lineTotal,
                    'vavrys_check' => !empty($row['vavrys_check']),
                    'vavrys_allowed' => vpo_is_allowed_brand((string)($row['product_name'] ?? '')),
                    'always_stock' => cfloat_is_always_stock((string)($row['product_name'] ?? '')),
                ];
            }
        } catch (Throwable $e) {
            $ordersError = 'Chyba při načítání položek objednávek: ' . $e->getMessage();
        }
    }
}

if ($ordersError === '') {
    try {
        $sqlProfit = "SELECT COALESCE(SUM(oi2.price_total_with_vat - (oi2.nakupni_cena * (CASE WHEN oi2.`count` IS NULL OR oi2.`count` = 0 THEN 1 ELSE oi2.`count` END))), 0) AS p
            FROM order_items oi2
            JOIN (SELECT DISTINCT o.id_order FROM orders o $joinItems $whereSql) x ON x.id_order = oi2.id_order
            WHERE oi2.nakupni_cena IS NOT NULL AND oi2.nakupni_cena > 0";
        $stP = $pdo->prepare($sqlProfit);
        $stP->execute($params);
        $ordersProfitSum = (float)($stP->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $ordersProfitSum = 0.0;
    }
}

$totalPages = $ordersPerPage > 0 ? (int)ceil($ordersTotal / $ordersPerPage) : 1;
if ($totalPages < 1) $totalPages = 1;
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Objednávky – Nový Cfloat</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root {
    --g1:#24d84a; --g2:#00b52a; --ink:#1b1f23; --muted:#6b7280;
    --border:#e7e9ec; --bg-soft:#f7f8f9; --danger:#d93025; --danger-bg:#fdeceb;
}
* { box-sizing:border-box; }
body {
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    background:#fafbfb; margin:0; padding:20px 16px 60px; color:var(--ink);
}
.wrap { max-width:1400px; margin:0 auto; }
.logo-top { text-align:center; margin-bottom:14px; }
.logo-top img { max-width:150px; height:auto; display:inline-block; }
.logo-top a { text-decoration:none; }
.topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; flex-wrap:wrap; gap:10px; }
.back-link { color:var(--muted); font-size:12.5px; text-decoration:none; border:1px solid var(--border); border-radius:999px; padding:7px 14px; }
.back-link:hover { background:#fff; border-color:#ccc; }
h1 { font-size:22px; margin:0; font-weight:800; }

.filter-card {
    background:#fff; border:1px solid var(--border); border-radius:16px;
    padding:16px 18px; margin-bottom:16px; box-shadow:0 1px 3px rgba(0,0,0,0.03);
}
.filter-row { display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end; }
.field { display:flex; flex-direction:column; gap:4px; }
.field label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; }
.field input[type=text], .field input[type=date] {
    border:1px solid var(--border); border-radius:10px; padding:9px 12px; font-size:13.5px; min-width:160px;
}
.field input[type=text] { min-width:280px; }
.date-range { display:flex; align-items:center; gap:6px; }
.btn {
    border:none; border-radius:999px; padding:10px 18px; font-size:13px; font-weight:700; cursor:pointer;
    white-space:nowrap;
}
.btn-primary { background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; }
.btn-primary:hover { filter:brightness(1.05); }
.btn-secondary { background:var(--bg-soft); color:var(--ink); border:1px solid var(--border); }
.btn-secondary:hover { background:#eee; }
.btn-green { background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; }
.btn-green:hover { filter:brightness(1.05); }
.btn-outline { background:#fff; color:var(--g2); border:1.5px solid var(--g2); }
.btn-outline:hover { background:#eafbf0; }
.btn-fill { background:#111; color:#fff; }
.btn-fill:hover { background:#000; }
.btn-row { display:flex; gap:8px; margin-left:auto; flex-wrap:wrap; }

.flash { border-radius:12px; padding:12px 16px; font-size:13px; margin-bottom:14px; }
.flash-ok { background:#eafbf0; color:#0a7a34; border:1px solid #bdeccb; }
.flash-error { background:var(--danger-bg); color:var(--danger); border:1px solid #f5c6c2; }

.summary-bar { display:flex; gap:10px; flex-wrap:wrap; font-size:13px; color:var(--muted); margin-bottom:14px; align-items:center; }
.summary-bar b { color:var(--ink); }
.summary-chip { background:var(--bg-soft); border:1px solid var(--border); border-radius:10px; padding:6px 12px; }
.summary-chip.profit { background:#eafbf0; border:1.5px solid var(--g2); color:#0a7a34; font-weight:800; font-size:14.5px; }

.orders-header-row {
    display:grid;
    grid-template-columns: 100px 110px 110px 110px 1.4fr 1.2fr 1fr 110px 130px 100px 120px;
    gap:10px; padding:9px 16px; font-size:10.5px; text-transform:uppercase; letter-spacing:.04em;
    color:#fff; font-weight:800; background:linear-gradient(135deg,var(--g1),var(--g2));
    border-radius:12px; margin-bottom:6px;
}
.order-card {
    background:#fff; border:1px solid var(--border); border-radius:10px; margin-bottom:5px; overflow:hidden;
    transition: box-shadow .12s ease, border-color .12s ease;
}
.order-card:hover { box-shadow:0 3px 12px rgba(0,0,0,0.06); }
.order-card.has-missing { border-left:4px solid var(--danger); }
.order-card.vavrys-status-green { background:#eafbf0; border-color:#bdeccb; }
.order-card.vavrys-status-yellow { background:#fff8e6; border-color:#ffe1a8; }
.order-card.vavrys-status-red { background:#fdeceb; border-color:#f5c6c2; }
.order-card.expanded {
    border-color:var(--accent, #2b6cf6); box-shadow:0 4px 18px rgba(0,0,0,0.10);
    margin-bottom:14px; margin-top:4px;
}
.order-head {
    display:grid;
    grid-template-columns: 100px 110px 110px 110px 1.4fr 1.2fr 1fr 110px 130px 100px 120px;
    gap:10px; align-items:center; padding:6px 16px; cursor:pointer; min-height:34px;
}
.order-head:hover { background:var(--bg-soft); }
.order-card.expanded .order-head { background:var(--bg-soft); border-bottom:1px solid var(--border); }
.oh-value { font-size:12px; font-weight:600; line-height:1.3; }
.oh-value.neg { color:var(--danger); }
.oh-value.muted { color:var(--muted); font-weight:400; }
.paid-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:5px; vertical-align:middle; }
.paid-dot.paid { background:var(--g2); }
.paid-dot.unpaid { background:#ccc; }
.email-badge {
    display:inline-block; background:#fff0d9; color:#8a5a00; font-size:10.5px; font-weight:800;
    border-radius:999px; padding:1px 7px; margin-left:5px; cursor:pointer;
}
.inv-links a { font-size:10.5px; color:var(--g2); text-decoration:none; margin-right:6px; }
.delivery-toggle { text-decoration:underline dotted; cursor:pointer; }

.order-detail { display:none; border-top:1px solid var(--border); background:var(--bg-soft); padding:14px 16px; }
.order-delivery-note { background:#fff; border:1px solid var(--border); border-radius:10px; padding:10px 12px; font-size:12.5px; margin-bottom:10px; display:none; }

.items-table-scroll {
    width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; border-radius:10px;
    background:#fff;
}
.items-table { width:100%; min-width:640px; border-collapse:collapse; background:#fff; border-radius:10px; overflow:hidden; font-size:12.5px; }
.items-table th {
    text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.03em; color:var(--muted);
    background:#fff; padding:8px 10px; border-bottom:2px solid var(--border);
}
.items-table td { padding:5px 10px; border-bottom:1px solid var(--border); vertical-align:top; }
.items-table tr:last-child td { border-bottom:none; }
.items-table tr.missing td { color:var(--danger); }
.items-table td.num { text-align:right; font-variant-numeric:tabular-nums; }
.neg-profit { color:var(--danger); font-weight:700; }

.th-short { display:none; }
.col-chk { width:26px; }
.vavrys-chk-label { display:flex; align-items:center; gap:4px; cursor:pointer; }
.vavrys-chk { width:16px; height:16px; cursor:pointer; }
.muted-hint { color:var(--muted); font-size:10px; white-space:nowrap; }
.stock-icon { font-size:11px; margin-left:2px; cursor:help; vertical-align:middle; }

.vavrys-box { margin-top:12px; padding-top:12px; border-top:1px dashed var(--border); }
.vavrys-sent-badge { display:inline-block; background:#eafbf0; color:#0a7a34; border:1px solid #bdeccb; border-radius:8px; padding:4px 10px; font-size:11.5px; font-weight:700; margin-bottom:8px; }
.vavrys-sent-badge.status-yellow { background:#fff8e6; color:#8a5a00; border-color:#ffe1a8; }
.vavrys-sent-badge.status-red { background:#fdeceb; color:var(--danger); border-color:#f5c6c2; }
.vavrys-preview { background:#fff; border:1px solid var(--border); border-radius:10px; padding:12px 14px; font-size:12.5px; }
.vavrys-preview-empty { color:var(--muted); margin:0; }
.vavrys-list { margin:4px 0 10px; padding-left:18px; }
.vavrys-list-warn { color:#8a5a00; }
.vavrys-warn { color:#8a5a00; font-weight:700; margin:10px 0 2px; }
.vavrys-final-confirm { display:block; font-size:12.5px; margin:10px 0; cursor:pointer; }
.vavrys-confirm-form, .vavrys-inline-form { margin-top:4px; }
.vavrys-note-box { margin-top:12px; display:flex; flex-wrap:wrap; align-items:flex-start; gap:8px; }
.vavrys-note-label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; width:100%; }
.vavrys-note-textarea { flex:1; min-width:220px; border:1px solid var(--border); border-radius:10px; padding:8px 10px; font-size:12.5px; font-family:inherit; resize:vertical; }
.vavrys-note-status { font-size:11px; color:var(--muted); align-self:center; }

.pagination { display:flex; gap:10px; justify-content:center; margin-top:16px; font-size:13px; }
.pagination a { color:var(--g2); text-decoration:none; font-weight:700; }

.empty-msg { text-align:center; color:var(--muted); padding:40px 0; font-size:14px; }

@media (max-width: 1100px) {
    .order-head, .orders-header-row { grid-template-columns: 1fr 1fr; grid-auto-rows:auto; }
}
@media (max-width: 700px) {
    .th-full { display:none; }
    .th-short { display:inline; }
}
</style>
</head>
<body>
<div class="wrap">
    <div class="logo-top">
        <a href="index.php">
            <img src="../logo-1.png" alt="C-Store.cz">
        </a>
    </div>
    <div class="topbar">
        <a class="back-link" href="index.php">&larr; Zpět na Nový Cfloat</a>
        <h1>Objednávky</h1>
        <div></div>
    </div>

    <?php if ($fillFlash !== null): ?>
        <div class="flash <?php echo $fillFlash['ok'] ? 'flash-ok' : 'flash-error'; ?>">
            <?php echo h($fillFlash['summary']); ?>
        </div>
    <?php endif; ?>

    <?php if ($vavrysFlash !== null): ?>
        <div class="flash <?php echo $vavrysFlash['ok'] ? 'flash-ok' : 'flash-error'; ?>">
            <?php echo h($vavrysFlash['text']); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($vavrysMissingColumns)): ?>
        <div class="flash flash-error">
            <strong>Zaškrtávání a poznámky k Vavrys zatím nefungují</strong> - databázový uživatel zřejmě nemá právo
            <code>ALTER TABLE</code>, takže se chybějící sloupce nepodařilo založit automaticky.
            Spusťte prosím jednou ručně v phpMyAdmin tenhle SQL (nic nesmaže, jen přidá sloupce):
            <pre style="white-space:pre-wrap;background:#fff;border:1px solid var(--border);border-radius:8px;padding:8px 10px;margin-top:6px;font-size:11.5px;"><?php
                foreach ($vavrysMissingColumns as [$mTable, $mCol, $mDef]) {
                    echo h("ALTER TABLE `{$mTable}` ADD COLUMN `{$mCol}` {$mDef};") . "\n";
                }
            ?></pre>
            Po spuštění stačí stránku znovu načíst.
        </div>
    <?php endif; ?>

    <?php if ($missingResult !== null): ?>
        <div id="missing-block">
        <div class="flash flash-ok" style="background:#fff8e6;border-color:#ffe1a8;color:#8a5a00; display:flex; align-items:center; justify-content:space-between; gap:10px;">
            <span>Nalezeno <?php echo count($missingResult); ?> položek bez nákupní ceny za období <?php echo h($ordersDateFromStr); ?> – <?php echo h($ordersDateToStr); ?> (max. 500 zobrazeno).</span>
            <button type="button" class="btn btn-secondary" style="padding:5px 12px;font-size:11.5px;" onclick="document.getElementById('missing-block').style.display='none';">Skrýt</button>
        </div>
        <?php if (!empty($missingResult)): ?>
            <div class="filter-card" style="padding:0; overflow:hidden;">
                <table class="items-table">
                    <thead><tr><th>Objednávka</th><th>Datum</th><th>Kód</th><th>EAN</th><th>Produkt</th><th>Varianta</th><th>Ks</th></tr></thead>
                    <tbody>
                    <?php foreach ($missingResult as $m): ?>
                        <tr>
                            <td><?php echo h($m['number']); ?></td>
                            <td><?php echo h($m['created_display']); ?></td>
                            <td><?php echo h($m['product_number']); ?></td>
                            <td><?php echo h($m['ean']); ?></td>
                            <td><?php echo h($m['product_name']); ?></td>
                            <td><?php echo h($m['variant_description']); ?></td>
                            <td class="num"><?php echo h($m['count']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="filter-card">
        <form method="get" action="objednavky.php">
            <?php if ($ordersFilterEmail !== ''): ?><input type="hidden" name="email" value="<?php echo h($ordersFilterEmail); ?>"><?php endif; ?>
            <div class="filter-row">
                <div class="field" style="flex:1;">
                    <label for="q">Vyhledat</label>
                    <input type="text" id="q" name="q" value="<?php echo h($ordersSearch); ?>" placeholder="Číslo objednávky, jméno, e-mail, telefon, produkt…">
                </div>
                <div class="field">
                    <label>Období</label>
                    <div class="date-range">
                        <input type="date" name="from" value="<?php echo h($ordersDateFromStr); ?>">
                        <span>–</span>
                        <input type="date" name="to" value="<?php echo h($ordersDateToStr); ?>">
                    </div>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn btn-secondary">Hledat</button>
                    <button type="submit" name="export" value="silvini" class="btn btn-green">Export Silvini dat</button>
                </div>
            </div>
        </form>
        <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;align-items:center;">
            <form method="post" action="objednavky.php">
                <input type="hidden" name="action" value="doplnit_nakupni_ceny">
                <input type="hidden" name="from" value="<?php echo h($ordersDateFromStr); ?>">
                <input type="hidden" name="to" value="<?php echo h($ordersDateToStr); ?>">
                <input type="hidden" name="q" value="<?php echo h($ordersSearch); ?>">
                <input type="hidden" name="email" value="<?php echo h($ordersFilterEmail); ?>">
                <button type="submit" class="btn btn-fill">⟳ Doplnit nákupní ceny</button>
            </form>
            <form method="post" action="objednavky.php">
                <input type="hidden" name="action" value="zobrazit_nedoplnene">
                <input type="hidden" name="from" value="<?php echo h($ordersDateFromStr); ?>">
                <input type="hidden" name="to" value="<?php echo h($ordersDateToStr); ?>">
                <input type="hidden" name="q" value="<?php echo h($ordersSearch); ?>">
                <input type="hidden" name="email" value="<?php echo h($ordersFilterEmail); ?>">
                <button type="submit" class="btn btn-outline">Zobrazit nedoplněné ceny</button>
            </form>
            <span style="font-size:11.5px;color:var(--muted);">Vždy pro vybrané období výše (max. 500 položek na klik)</span>
        </div>
    </div>

    <?php if ($ordersError !== ''): ?>
        <div class="flash flash-error"><?php echo h($ordersError); ?></div>
    <?php elseif ($ordersTotal > 0): ?>
        <div class="summary-bar">
            <div class="summary-chip">Objednávek celkem: <b><?php echo (int)$ordersTotal; ?></b>, strana <b><?php echo (int)$ordersPage; ?>/<?php echo $totalPages; ?></b></div>
            <div class="summary-chip">Období: <b><?php echo h($ordersDateFromStr); ?> – <?php echo h($ordersDateToStr); ?></b></div>
            <div class="summary-chip profit">Součet zisku: <?php echo number_format($ordersProfitSum, 2, ',', ' '); ?> Kč</div>
            <?php if ($ordersFilterEmail !== ''): ?><div class="summary-chip">Filtr zákazník: <b><?php echo h($ordersFilterEmail); ?></b></div><?php endif; ?>
        </div>

        <div class="orders-header-row">
            <div>Datum</div>
            <div>Zisk</div>
            <div>Celkem</div>
            <div>Nákupní cena</div>
            <div>Jméno</div>
            <div>E-mail</div>
            <div>Telefon</div>
            <div>Objednávka</div>
            <div>Faktura</div>
            <div>Platba</div>
            <div>Doprava</div>
        </div>

        <?php foreach ($ordersRows as $row): ?>
            <?php
            $idOrder = (int)($row['id_order'] ?? 0);
            $createdDisplay = '';
            if (!empty($row['created_at'])) {
                try { $createdDisplay = (new DateTime($row['created_at']))->format('d.m.Y H:i'); }
                catch (Exception $e) { $createdDisplay = (string)$row['created_at']; }
            }
            $name = trim((string)($row['customer_name'] ?? ''));
            $email = trim((string)($row['customer_email'] ?? ''));
            $phone = trim((string)($row['customer_phone'] ?? ''));
            $orderNumber = trim((string)($row['number'] ?? ''));
            $invoiceNumber = trim((string)($row['invoice_number'] ?? ''));
            $totalPrice = isset($row['total_price_with_vat']) ? (float)$row['total_price_with_vat'] : 0.0;

            $currencyCode = 'CZK';
            if (isset($row['currency_code']) && trim((string)$row['currency_code']) !== '') $currencyCode = strtoupper(trim((string)$row['currency_code']));
            $exchangeRate = isset($row['exchange_rate']) ? (float)$row['exchange_rate'] : 0.0;
            $fx = 1.0; $currencyDisplayCode = 'CZK';
            if ($currencyCode !== 'CZK' && $exchangeRate > 0) { $fx = 1.0 / $exchangeRate; $currencyDisplayCode = $currencyCode; }
            $totalPriceDisp = $totalPrice * $fx;

            $emailKey = mb_strtolower($email, 'UTF-8');
            $emailCnt = $emailKey !== '' && isset($ordersEmailStats[$emailKey]) ? (int)$ordersEmailStats[$emailKey] : 0;

            $isPaidOrder = (isset($row['zaplaceno']) && (string)$row['zaplaceno'] === 'A') || (isset($row['gopay_zaplaceno']) && (string)$row['gopay_zaplaceno'] === 'A');
            $paymentName = trim((string)($row['payment_name'] ?? ''));
            $deliveryRaw = trim((string)($row['delivery_name'] ?? ''));
            $deliveryLower = mb_strtolower($deliveryRaw, 'UTF-8');
            $isZasilkovna = $deliveryRaw !== '' && (mb_stripos($deliveryLower, 'zásilkovna') !== false || mb_stripos($deliveryLower, 'zasilkovna') !== false);
            // krátký název dopravce (např. "GLS", "Osobní odběr") – vezme se jen část
            // před první " - ", ať se řádek nenafukuje celou adresou/poznámkou
            $deliveryShort = $deliveryRaw;
            if ($deliveryRaw !== '') {
                $dashPos = mb_strpos($deliveryRaw, ' - ');
                if ($dashPos !== false) $deliveryShort = trim(mb_substr($deliveryRaw, 0, $dashPos));
            }

            $purchaseTotal = isset($ordersPurchaseById[$idOrder]) ? (float)$ordersPurchaseById[$idOrder] : 0.0;
            $profitTotal = isset($ordersProfitById[$idOrder]) ? (float)$ordersProfitById[$idOrder] : ($totalPrice - $purchaseTotal);
            $hasMissing = !empty($ordersMissingById[$idOrder]);
            $items = $ordersItemsById[$idOrder] ?? [];
            $vavrysNoteVal = trim((string)($row['vavrys_note'] ?? ''));
            $vavrysSentAt = trim((string)($row['vavrys_sent_at'] ?? ''));
            $vavrysStatusVal = trim((string)($row['vavrys_status'] ?? ''));
            $vavrysSentDisp = '';
            if ($vavrysSentAt !== '') {
                try { $vavrysSentDisp = (new DateTime($vavrysSentAt))->format('d.m.Y H:i'); }
                catch (Exception $e) { $vavrysSentDisp = $vavrysSentAt; }
            }
            $thisOrderPreview = ($vavrysPreview !== null && (int)$vavrysPreview['id_order'] === $idOrder) ? $vavrysPreview : null;
            ?>
            <div class="order-card<?php echo $hasMissing ? ' has-missing' : ''; ?><?php echo $vavrysStatusVal !== '' ? ' vavrys-status-' . h($vavrysStatusVal) : ''; ?>" id="order-<?php echo $idOrder; ?>">
                <div class="order-head" onclick="toggleOrderDetail(<?php echo $idOrder; ?>)">
                    <div><span class="oh-value muted"><?php echo h($createdDisplay); ?></span></div>
                    <div><span class="oh-value<?php echo ($purchaseTotal > 0 && $profitTotal < 0) ? ' neg' : ''; ?>"><?php echo $purchaseTotal > 0 ? fmt_money($profitTotal * $fx, $currencyDisplayCode) : '—'; ?></span></div>
                    <div><span class="oh-value"><?php echo fmt_money($totalPriceDisp, $currencyDisplayCode); ?></span></div>
                    <div><span class="oh-value muted"><?php echo $purchaseTotal > 0 ? fmt_money($purchaseTotal * $fx, $currencyDisplayCode) : '—'; ?></span></div>
                    <div><span class="oh-value"><?php echo h($name !== '' ? $name : '—'); ?><?php if ($emailKey !== '' && $emailCnt > 1): ?><span class="email-badge" data-email="<?php echo h($email); ?>"><?php echo (int)$emailCnt; ?>×</span><?php endif; ?></span></div>
                    <div><span class="oh-value muted"><?php echo h($email !== '' ? $email : '—'); ?></span></div>
                    <div><span class="oh-value muted"><?php echo h($phone !== '' ? $phone : '—'); ?></span></div>
                    <div><span class="oh-value"><?php echo h($orderNumber !== '' ? $orderNumber : '—'); ?></span></div>
                    <div>
                        <span class="oh-value"><?php echo h($invoiceNumber !== '' ? $invoiceNumber : '—'); ?></span>
                        <?php $invUrl = trim((string)($row['invoice_url'] ?? '')); $invHtml = trim((string)($row['invoice_url_html'] ?? '')); ?>
                        <?php if ($invUrl !== '' || $invHtml !== ''): ?>
                            <div class="inv-links">
                                <?php if ($invHtml !== ''): ?><a href="<?php echo h($invHtml); ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">HTML</a><?php endif; ?>
                                <?php if ($invUrl !== ''): ?><a href="<?php echo h($invUrl); ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">PDF</a><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div><span class="oh-value"><span class="paid-dot <?php echo $isPaidOrder ? 'paid' : 'unpaid'; ?>"></span><?php echo h($paymentName); ?></span></div>
                    <div>
                        <span class="oh-value">
                        <?php if ($isZasilkovna): ?>
                            <span class="delivery-toggle" data-target="delivery-<?php echo $idOrder; ?>" onclick="event.stopPropagation(); toggleDelivery(<?php echo $idOrder; ?>)">Zásilkovna</span>
                        <?php else: ?>
                            <?php echo h($deliveryShort !== '' ? $deliveryShort : '—'); ?>
                        <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div id="order-detail-<?php echo $idOrder; ?>" class="order-detail">
                    <?php if ($isZasilkovna && $deliveryRaw !== ''): ?>
                        <div id="delivery-<?php echo $idOrder; ?>" class="order-delivery-note"><?php echo nl2br(h($deliveryRaw)); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($items)): ?>
                        <div class="items-table-scroll">
                        <table class="items-table">
                            <thead><tr>
                                <th class="col-chk"></th>
                                <th>Kód</th>
                                <th>Produkt</th>
                                <th><span class="th-full">Varianta</span><span class="th-short">Vel.</span></th>
                                <th>Ks</th>
                                <th>Nákupní cena (s DPH)</th>
                                <th>Řádek (s DPH)</th>
                                <th>Zisk</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($items as $it): ?>
                                <?php
                                $lineQty = (float)$it['qty'];
                                $lineTotal = (float)$it['total'];
                                $linePurchase = (float)$it['price_s_dph'] * $lineQty;
                                $lineProfit = $lineTotal - $linePurchase;
                                ?>
                                <tr class="<?php echo !empty($it['missing_purchase']) ? 'missing' : ''; ?>">
                                    <td class="col-chk">
                                        <?php if ($vavrysHasCheckCol && !empty($it['id'])): ?>
                                        <label class="vavrys-chk-label" title="Objednat u Vavrys">
                                            <input type="checkbox" class="vavrys-chk" data-item-id="<?php echo (int)$it['id']; ?>" <?php echo $it['vavrys_check'] ? 'checked' : ''; ?>>
                                            <?php if (!$it['vavrys_allowed']): ?><small class="muted-hint">jiný dodavatel</small><?php endif; ?>
                                        </label>
                                        <?php endif; ?>
                                        <?php if (!empty($it['always_stock'])): ?>
                                            <span class="stock-icon" title="Výprodej/akce – vždy skladem">📦</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo h($it['code']); ?></td>
                                    <td><?php echo h($it['name']); ?></td>
                                    <td><?php echo h($it['variant_disp']); ?></td>
                                    <td class="num"><?php echo (float)$it['qty']; ?></td>
                                    <td class="num"><?php echo !empty($it['price_s_dph']) ? fmt_money(((float)$it['price_s_dph']) * $fx, $currencyDisplayCode) : '—'; ?></td>
                                    <td class="num"><?php echo fmt_money($lineTotal * $fx, $currencyDisplayCode); ?></td>
                                    <td class="num<?php echo ($linePurchase > 0 && $lineProfit < 0) ? ' neg-profit' : ''; ?>"><?php echo $linePurchase > 0 ? fmt_money($lineProfit * $fx, $currencyDisplayCode) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>

                        <div class="vavrys-box">
                            <?php if (!$vavrysHasCheckCol): ?>
                                <p class="vavrys-warn" style="margin:0;">⚠ V databázi zatím chybí sloupce pro zaškrtávání/poznámky k Vavrys - viz upozornění nahoře stránky.</p>
                            <?php else: ?>
                            <?php if ($vavrysSentDisp !== ''): ?>
                                <?php
                                $badgeIcon = $vavrysStatusVal === 'green' ? '✓' : ($vavrysStatusVal === 'yellow' ? '⚠' : ($vavrysStatusVal === 'red' ? '✕' : '✓'));
                                $badgeText = $vavrysStatusVal === 'green' ? 'Objednáno u Vavrys'
                                    : ($vavrysStatusVal === 'yellow' ? 'Objednáno u Vavrys, zbytek čeká na jiného dodavatele'
                                    : ($vavrysStatusVal === 'red' ? 'U Vavrys se nepodařilo objednat' : 'Odesláno u Vavrys'));
                                ?>
                                <div class="vavrys-sent-badge<?php echo $vavrysStatusVal !== '' && $vavrysStatusVal !== 'green' ? ' status-' . h($vavrysStatusVal) : ''; ?>"><?php echo $badgeIcon; ?> <?php echo h($badgeText); ?>: <?php echo h($vavrysSentDisp); ?></div>
                            <?php endif; ?>

                            <?php if ($thisOrderPreview !== null): ?>
                                <div class="vavrys-preview">
                                    <?php if (empty($thisOrderPreview['items'])): ?>
                                        <p class="vavrys-preview-empty">Žádná zaškrtnutá položka se nedá odeslat u Vavrys (viz upozornění níže). Zaškrtněte prosím nejdřív položky v tabulce výše.</p>
                                    <?php else: ?>
                                        <p><strong>K odeslání u Vavrys (<?php echo count($thisOrderPreview['items']); ?>):</strong></p>
                                        <ul class="vavrys-list">
                                            <?php foreach ($thisOrderPreview['items'] as $pit): ?>
                                                <li><?php echo h($pit['name']); ?><?php echo $pit['velikost'] !== '' ? ', ' . h($pit['velikost']) : ''; ?> — <?php echo (int)$pit['qty']; ?> ks (skladem u Vavrys <?php echo (int)$pit['match']['mnozstviSklad']; ?> ks)</li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>

                                    <?php if (!empty($thisOrderPreview['skip_other'])): ?>
                                        <p class="vavrys-warn">⚠ Tohle zboží nelze objednat u Vavrys (jiný dodavatel) – přeskočeno:</p>
                                        <ul class="vavrys-list vavrys-list-warn">
                                            <?php foreach ($thisOrderPreview['skip_other'] as $s): ?>
                                                <li><?php echo h($s['name']); ?><?php echo $s['velikost'] !== '' ? ', ' . h($s['velikost']) : ''; ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if (!empty($thisOrderPreview['skip_notfound'])): ?>
                                        <p class="vavrys-warn">⚠ Nenalezeno v katalogu Vavrys – přeskočeno:</p>
                                        <ul class="vavrys-list vavrys-list-warn">
                                            <?php foreach ($thisOrderPreview['skip_notfound'] as $s): ?>
                                                <li><?php echo h($s['name']); ?><?php echo $s['velikost'] !== '' ? ', ' . h($s['velikost']) : ''; ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if (!empty($thisOrderPreview['skip_stock'])): ?>
                                        <p class="vavrys-warn">⚠ Nedostatek skladu u Vavrys – rozhodni ručně, needobjednáno automaticky:</p>
                                        <ul class="vavrys-list vavrys-list-warn">
                                            <?php foreach ($thisOrderPreview['skip_stock'] as $s): ?>
                                                <li><?php echo h($s['name']); ?><?php echo $s['velikost'] !== '' ? ', ' . h($s['velikost']) : ''; ?> — skladem <?php echo (int)$s['sklad']; ?> ks, objednáno <?php echo (int)$s['qty']; ?> ks</li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if (!empty($thisOrderPreview['unchecked_eligible'])): ?>
                                        <p class="vavrys-warn">⚠ V objednávce je i další zboží od Vavrys, které jste nezaškrtli – nebude objednáno:</p>
                                        <ul class="vavrys-list vavrys-list-warn">
                                            <?php foreach ($thisOrderPreview['unchecked_eligible'] as $s): ?>
                                                <li><?php echo h($s['name']); ?><?php echo $s['velikost'] !== '' ? ', ' . h($s['velikost']) : ''; ?> — <?php echo (int)$s['qty']; ?> ks</li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>

                                    <?php if (!empty($thisOrderPreview['items'])): ?>
                                        <form method="post" action="objednavky.php<?php echo !empty($_GET) ? '?' . h(http_build_query($_GET)) : ''; ?>" onsubmit="return confirm('Opravdu chcete ZÁVAZNĚ odeslat tuto objednávku Vavrys? Tuto akci nelze vzít zpět.');" class="vavrys-confirm-form">
                                            <input type="hidden" name="action" value="vavrys_odeslat">
                                            <input type="hidden" name="id_order" value="<?php echo $idOrder; ?>">
                                            <input type="hidden" name="token" value="<?php echo h($thisOrderPreview['token']); ?>">
                                            <?php if (!empty($thisOrderPreview['unchecked_eligible'])): ?>
                                            <label class="vavrys-final-confirm">
                                                <input type="checkbox" name="confirm_unchecked" value="1" required>
                                                Rozumím, že zboží od Vavrys výše (nezaškrtnuté) se <strong>neobjedná</strong>, chci pokračovat jen s vybranými položkami.
                                            </label>
                                            <?php endif; ?>
                                            <label class="vavrys-final-confirm">
                                                <input type="checkbox" name="confirm_final" value="1" required>
                                                Potvrzuji náhled výše a chci objednávku <strong>ZÁVAZNĚ</strong> odeslat Vavrys.
                                            </label>
                                            <button type="submit" class="btn btn-fill" style="background:linear-gradient(135deg,#ff5b4d,var(--danger));">ODESLAT ZÁVAZNĚ U VAVRYS</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <form method="post" action="objednavky.php<?php echo !empty($_GET) ? '?' . h(http_build_query($_GET)) : ''; ?>#order-<?php echo $idOrder; ?>" class="vavrys-inline-form">
                                    <input type="hidden" name="action" value="vavrys_priprava">
                                    <input type="hidden" name="id_order" value="<?php echo $idOrder; ?>">
                                    <button type="submit" class="btn btn-secondary">Potvrdit objednávku u Vavrys</button>
                                </form>
                            <?php endif; ?>

                            <div class="vavrys-note-box">
                                <label class="vavrys-note-label" for="vavrys-note-<?php echo $idOrder; ?>">Poznámka k objednávce</label>
                                <textarea id="vavrys-note-<?php echo $idOrder; ?>" class="vavrys-note-textarea" data-order-id="<?php echo $idOrder; ?>" rows="2" placeholder="Vlastní poznámka…"><?php echo h($vavrysNoteVal); ?></textarea>
                                <button type="button" class="btn btn-secondary vavrys-note-save" data-order-id="<?php echo $idOrder; ?>" style="padding:5px 12px;font-size:11.5px;">Uložit poznámku</button>
                                <span class="vavrys-note-status" data-order-id="<?php echo $idOrder; ?>"></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <em>Žádné položky objednávky k zobrazení.</em>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($ordersPage > 1): ?><a href="objednavky.php?page=<?php echo $ordersPage - 1; ?>&amp;q=<?php echo urlencode($ordersSearch); ?>&amp;from=<?php echo h($ordersDateFromStr); ?>&amp;to=<?php echo h($ordersDateToStr); ?>">◀ Předchozí</a><?php endif; ?>
                <?php if ($ordersPage < $totalPages): ?><a href="objednavky.php?page=<?php echo $ordersPage + 1; ?>&amp;q=<?php echo urlencode($ordersSearch); ?>&amp;from=<?php echo h($ordersDateFromStr); ?>&amp;to=<?php echo h($ordersDateToStr); ?>">Další ▶</a><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty-msg">Žádné objednávky pro aktuální filtr.</div>
    <?php endif; ?>
</div>

<script>
function toggleOrderDetail(id) {
    var el = document.getElementById('order-detail-' + id);
    var card = document.getElementById('order-' + id);
    if (!el) return;
    var willOpen = (el.style.display !== 'block');
    el.style.display = willOpen ? 'block' : 'none';
    if (card) card.classList.toggle('expanded', willOpen);
}
function toggleDelivery(id) {
    var el = document.getElementById('delivery-' + id);
    if (!el) return;
    el.style.display = (el.style.display === 'block') ? 'none' : 'block';
}
document.querySelectorAll('.email-badge').forEach(function(el) {
    el.addEventListener('click', function(e) {
        e.stopPropagation();
        var email = el.getAttribute('data-email');
        if (!email) return;
        var url = new URL(window.location.href);
        url.searchParams.set('email', email);
        url.searchParams.delete('page');
        url.searchParams.delete('q');
        window.location.href = url.toString();
    });
});

// Po odeslání/přípravě objednávky Vavrys se stránka přesměruje na #order-ID -
// rovnou rozbalíme detail té objednávky, ať uživatel hned vidí výsledek/náhled.
(function() {
    var hash = window.location.hash || '';
    var m = hash.match(/^#order-(\d+)$/);
    if (m) {
        var detail = document.getElementById('order-detail-' + m[1]);
        if (detail) detail.style.display = 'block';
        var card = document.getElementById('order-' + m[1]);
        if (card) { card.classList.add('expanded'); card.scrollIntoView({ block: 'center' }); }
    }
})();

// Zaškrtávání položek k objednání u Vavrys - ukládá se hned při kliknutí.
document.querySelectorAll('.vavrys-chk').forEach(function(chk) {
    chk.addEventListener('click', function(e) { e.stopPropagation(); });
    chk.addEventListener('change', function() {
        var itemId = chk.getAttribute('data-item-id');
        var body = new URLSearchParams();
        body.set('action', 'vavrys_toggle_ajax');
        body.set('item_id', itemId);
        body.set('checked', chk.checked ? '1' : '0');
        fetch('objednavky.php', { method: 'POST', body: body })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.ok) {
                    chk.checked = !chk.checked; // vrátit zpět, pokud se uložení nepovedlo
                    alert('Zaškrtnutí se nepodařilo uložit, zkuste to prosím znovu.');
                }
            })
            .catch(function() {
                chk.checked = !chk.checked;
                alert('Zaškrtnutí se nepodařilo uložit (chyba spojení).');
            });
    });
});

// Uložení vlastní poznámky k objednávce.
document.querySelectorAll('.vavrys-note-save').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var orderId = btn.getAttribute('data-order-id');
        var textarea = document.getElementById('vavrys-note-' + orderId);
        var statusEl = document.querySelector('.vavrys-note-status[data-order-id="' + orderId + '"]');
        var body = new URLSearchParams();
        body.set('action', 'vavrys_ulozit_poznamku_ajax');
        body.set('id_order', orderId);
        body.set('note', textarea ? textarea.value : '');
        if (statusEl) statusEl.textContent = 'Ukládám…';
        fetch('objednavky.php', { method: 'POST', body: body })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (statusEl) statusEl.textContent = (data && data.ok) ? 'Uloženo ✓' : 'Chyba při ukládání';
                if (statusEl) setTimeout(function() { statusEl.textContent = ''; }, 3000);
            })
            .catch(function() {
                if (statusEl) statusEl.textContent = 'Chyba spojení';
            });
    });
});
document.querySelectorAll('.vavrys-note-textarea, .vavrys-inline-form, .vavrys-confirm-form').forEach(function(el) {
    el.addEventListener('click', function(e) { e.stopPropagation(); });
});
</script>
</body>
</html>
