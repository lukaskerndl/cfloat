<?php
declare(strict_types=1);

/**
 * cfloat-new/nove-objednavky.php
 *
 * Seznam + detail objednávek z NOVÉHO API v jednom - stejná architektura jako
 * objednavky.php: všechna data se natáhnou najednou při načtení stránky,
 * rozkliknutí karty jen přepíná display:none/block přes JS (žádný další
 * request). Akce (zaškrtnutí, Vavrys, změna stavu) posílají POST na tuhle
 * samou stránku a po dokončení se vrací na stejné místo přes URL hash.
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

require_once __DIR__ . '/lib/vavrys_katalog.php';
require_once __DIR__ . '/lib/eshop_new_helpers.php';

$secretsPath = __DIR__ . '/../secrets/eshop_new_api.php';
$cfg = is_file($secretsPath) ? include $secretsPath : [];
$baseUrl = rtrim((string)($cfg['base_url'] ?? ''), '/');
$token   = trim((string)($cfg['token'] ?? ''));

// Seznam a barvy stavů - musí být načtené HNED, ať je k dispozici i v akcích
// níže (vavrys_odeslat potřebuje dohledat IRI cílového stavu).
$stateColors = [];
$orderStates = [];
$statesRes = eshop_new_api_call($baseUrl, $token, '/api-engine/order-states?itemsPerPage=200');
foreach (($statesRes['body']['hydra:member'] ?? []) as $st) {
    $stateColors[$st['@id']] = (string)($st['color'] ?? '');
    $orderStates[] = $st;
}

function row_id(string $orderIri): string { return md5($orderIri); }

// ---------------------------------------------------------------------------
// AJAX: přepnutí zaškrtnutí položky k Vavrys (perzistuje se hned, bez reloadu)
// ---------------------------------------------------------------------------
if (isset($_POST['ajax_toggle'])) {
    header('Content-Type: application/json');
    $orderIriReq = (string)($_POST['order_iri'] ?? '');
    $itemIri = (string)($_POST['item_iri'] ?? '');
    $checked = !empty($_POST['checked']) ? 1 : 0;
    $type = (string)($_POST['type'] ?? 'vavrys');
    $table = $type === 'stockfix' ? 'eshop_new_order_item_stockfix_checks' : 'eshop_new_order_item_checks';
    $checkCol = $type === 'stockfix' ? 'checked' : 'vavrys_check';
    if ($orderIriReq === '' || $itemIri === '') { echo json_encode(['ok' => false]); exit; }
    try {
        $pdo->prepare("
            INSERT INTO {$table} (order_iri, item_iri, {$checkCol}, updated_at)
            VALUES (:o, :i, :c, NOW())
            ON DUPLICATE KEY UPDATE {$checkCol} = VALUES({$checkCol}), updated_at = NOW()
        ")->execute([':o' => $orderIriReq, ':i' => $itemIri, ':c' => $checked]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false]);
    }
    exit;
}

// ---------------------------------------------------------------------------
// Příprava náhledu Vavrys pro konkrétní objednávku (POST/Redirect/GET)
// ---------------------------------------------------------------------------
$vavrysPreview = null; // ponecháno kvůli zpětné kompatibilitě proměnné níže, náhledy už se nepoužívají

function load_order_items_from_db(PDO $pdo, string $orderIri): array
{
    $checksStmt = $pdo->prepare("SELECT item_iri, vavrys_check FROM eshop_new_order_item_checks WHERE order_iri = :o");
    $checksStmt->execute([':o' => $orderIri]);
    $checksMap = [];
    foreach ($checksStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $checksMap[$r['item_iri']] = (bool)$r['vavrys_check'];

    $itemsStmt = $pdo->prepare("SELECT * FROM eshop_new_order_items WHERE order_iri = :o");
    $itemsStmt->execute([':o' => $orderIri]);
    $items = [];
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            '@id' => $r['item_iri'], 'name' => $r['name'], 'number' => $r['number'], 'ean' => $r['ean'],
            'oldVariantValue1' => $r['size'], 'pieces' => (int)$r['pieces'], 'priceWithVat' => (float)$r['price_with_vat'],
            '_stock' => $r['stock'] !== null ? (int)$r['stock'] : null, '_stock_exact' => (bool)$r['stock_exact'],
            '_zbyva' => $r['stock'] !== null ? (int)$r['stock'] - (int)$r['pieces'] : null,
            '_checked' => $checksMap[$r['item_iri']] ?? false,
        ];
    }
    return $items;
}

// ---------------------------------------------------------------------------
// SJEDNOCENÉ vyřízení objednávky - jedno tlačítko, jeden krok (jen JS confirm()
// na frontendu, žádný zvláštní token/checkbox navíc). V jednom průchodu:
//   - položky zaškrtnuté ve sloupci "Vavrys" a nalezené v katalogu -> odešle k Vavrys
//   - položky zaškrtnuté ve sloupci "Sklad" s nalezenou duplicitou -> opraví sklad
//   - položky se sklad >= 0 (i nezaškrtnuté) -> označí jako "Skladem", nic nezapisuje
//   - zbytek -> "neobjednáno"
// Stav objednávky se pak přepne PRÁVĚ JEDNOU, podle výsledku celého průchodu.
// ---------------------------------------------------------------------------
$flash = null;
if (isset($_SESSION['nn_flash'])) { $flash = $_SESSION['nn_flash']; unset($_SESSION['nn_flash']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vyridit_objednavku') {
    $orderIriReq = (string)($_POST['order_iri'] ?? '');
    $itemsForThis = load_order_items_from_db($pdo, $orderIriReq);
    $stockChecksMap = load_stockfix_checks($pdo, $orderIriReq);

    $orderRow = $pdo->prepare("SELECT order_number, payment_status, payment_name FROM eshop_new_orders WHERE order_iri = :o");
    $orderRow->execute([':o' => $orderIriReq]);
    $orderInfo = $orderRow->fetch(PDO::FETCH_ASSOC);
    $orderNumberForVavrys = (string)($orderInfo['order_number'] ?: substr($orderIriReq, -12));

    $file = vpo_find_vavrys_file();
    $noteLine = function (string $stav, string $code, string $name, string $velikost, int $qty): string {
        return ($code !== '' ? $code : '—') . ', ' . $name . ($velikost !== '' ? ' ' . $velikost : '') . ', ' . $qty . ' ks - ' . $stav;
    };

    // --- Krok 1: Vavrys - sesbírat položky k odeslání ---
    $polozky = [];
    $vavrysMatchedIris = [];
    foreach ($itemsForThis as $it) {
        if (empty($it['_checked'])) continue; // sloupec "Vavrys"
        $velikost = (string)($it['oldVariantValue1'] ?? '');
        $found = $file ? vpo_lookup_item($file, $it['ean'] ?? null, (string)($it['number'] ?? ''), $velikost) : null;
        if ($found) {
            $polozky[] = [
                'katalogId' => $found['katalogId'] ?? '', 'strCislo' => $found['strCislo'] ?? '',
                'karCislo' => $found['karCislo'] ?? '', 'karCisloId' => $found['karCisloId'] ?? '',
                'idX' => $found['idX'] ?? '', 'idY' => $found['idY'] ?? '',
                'mnozstvi' => (int)($it['pieces'] ?? 1), 'cena' => $found['cena'] ?? 0,
            ];
            $vavrysMatchedIris[$it['@id']] = true;
        }
    }
    $vavrysSendResult = null;
    if (!empty($polozky)) {
        if (!isset($VAVRYS_LOGIN, $VAVRYS_PASSWORD) || $VAVRYS_LOGIN === '' || $VAVRYS_PASSWORD === '') {
            $vavrysSendResult = ['success' => false, 'message' => 'Přihlašovací údaje k Vavrys nejsou k dispozici.'];
        } else {
            $data = vpo_build_objednavka_data($orderNumberForVavrys, date('Y-m-d'), $polozky);
            $vavrysSendResult = vpo_send_objednavka($VAVRYS_LOGIN, $VAVRYS_PASSWORD, $data);
        }
    }

    // --- Krok 2: sklad - projít VŠECHNY položky, opravit duplicity, poznačit stav ---
    $noteLines = [];
    $allResolved = true;
    $anyVavrysSent = false;
    $stockCorrectedCount = 0;

    foreach ($itemsForThis as $it) {
        $velikost = (string)($it['oldVariantValue1'] ?? '');
        $wasVavrysSent = !empty($vavrysMatchedIris[$it['@id']]) && !empty($vavrysSendResult['success']);

        if ($wasVavrysSent) {
            $noteLines[] = $noteLine('objednáno u Vavrys', (string)($it['number'] ?? ''), (string)($it['name'] ?? ''), $velikost, (int)$it['pieces']);
            $anyVavrysSent = true;
            continue;
        }

        $wantsStockFix = !empty($stockChecksMap[$it['@id']]);
        $cand = null;
        if ($wantsStockFix && !empty($it['ean']) && !empty($it['idProductItem'] ?? null)) {
            // idProductItem tady chybí u řádků z load_order_items_from_db - dohledáme z DB.
        }
        $dbItemRow = $pdo->prepare("SELECT ean, id_product_item FROM eshop_new_order_items WHERE order_iri = :o AND item_iri = :i");
        $dbItemRow->execute([':o' => $orderIriReq, ':i' => $it['@id']]);
        $dbItemInfo = $dbItemRow->fetch(PDO::FETCH_ASSOC);

        if ($wantsStockFix && $dbItemInfo && !empty($dbItemInfo['ean']) && !empty($dbItemInfo['id_product_item'])) {
            $cand = eshop_new_find_duplicate_candidate($baseUrl, $token, (string)$dbItemInfo['ean'], (string)$dbItemInfo['id_product_item'], (int)$it['pieces']);
        }

        if ($cand && $cand['candidate']) {
            $ok = eshop_new_write_stock($baseUrl, $token, $cand['candidate']['id'], $cand['candidate']['new_stock']);
            if ($ok) {
                $stockCorrectedCount++;
                $pdo->prepare("
                    INSERT INTO eshop_new_duplicate_stock_applied (order_iri, item_iri, ean, duplicate_product_item_id, old_stock, new_stock, applied_at)
                    VALUES (:o, :i, :e, :d, :os, :ns, NOW())
                    ON DUPLICATE KEY UPDATE old_stock = VALUES(old_stock), new_stock = VALUES(new_stock), applied_at = NOW()
                ")->execute([':o' => $orderIriReq, ':i' => $it['@id'], ':e' => $dbItemInfo['ean'], ':d' => $cand['candidate']['id'], ':os' => $cand['candidate']['old_stock'], ':ns' => $cand['candidate']['new_stock']]);
                $noteLines[] = $noteLine('Sklad upraven', (string)($it['number'] ?? ''), (string)($it['name'] ?? ''), $velikost, (int)$it['pieces']);
                continue;
            }
        }

        $zbyva = $it['_zbyva'] ?? null;
        if ($zbyva !== null && $zbyva >= 0) {
            $noteLines[] = $noteLine('Skladem', (string)($it['number'] ?? ''), (string)($it['name'] ?? ''), $velikost, (int)$it['pieces']);
        } else {
            $noteLines[] = $noteLine('neobjednáno', (string)($it['number'] ?? ''), (string)($it['name'] ?? ''), $velikost, (int)$it['pieces']);
            $allResolved = false;
        }
    }
    if ($vavrysSendResult !== null && empty($vavrysSendResult['success'])) {
        $noteLines[] = 'Odeslání k Vavrys selhalo (' . ($vavrysSendResult['message'] ?? ($vavrysSendResult['error'] ?? 'neznámá chyba')) . ').';
        $allResolved = false;
    }

    $autoNote = implode("\n", $noteLines);
    $existingNoteStmt = $pdo->prepare("SELECT vavrys_note FROM eshop_new_orders WHERE order_iri = :o");
    $existingNoteStmt->execute([':o' => $orderIriReq]);
    $existingNote = trim((string)($existingNoteStmt->fetchColumn() ?: ''));
    $finalNote = trim($existingNote !== '' ? ($existingNote . "\n\n" . $autoNote) : $autoNote);
    $vavrysStatus = $allResolved ? 'green' : 'yellow';
    if ($anyVavrysSent) {
        $pdo->prepare("UPDATE eshop_new_orders SET vavrys_note = :n, vavrys_status = :s, vavrys_sent_at = NOW(), resolved_at = NOW() WHERE order_iri = :o")
            ->execute([':n' => $finalNote, ':s' => $vavrysStatus, ':o' => $orderIriReq]);
    } else {
        $pdo->prepare("UPDATE eshop_new_orders SET vavrys_note = :n, vavrys_status = :s, resolved_at = NOW() WHERE order_iri = :o")
            ->execute([':n' => $finalNote, ':s' => $vavrysStatus, ':o' => $orderIriReq]);
    }

    // --- Krok 3: poznámka i do e-shopu ---
    $noteWriteOk = false; $noteWriteError = '';
    $freshForNote = eshop_new_api_call($baseUrl, $token, $orderIriReq);
    if ($freshForNote['ok'] && is_array($freshForNote['body'])) {
        $existingEshopNote = trim((string)($freshForNote['body']['internalNote'] ?? ''));
        $combinedEshopNote = trim($existingEshopNote !== '' ? ($existingEshopNote . "\n\n" . $autoNote) : $autoNote);
        $chNote = curl_init($baseUrl . $orderIriReq);
        curl_setopt_array($chNote, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode(['internalNote' => $combinedEshopNote], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['X-AUTH-TOKEN: ' . $token, 'Content-Type: application/merge-patch+json', 'Accept: application/ld+json'],
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
        ]);
        $noteResp = curl_exec($chNote);
        $noteHttp = (int)curl_getinfo($chNote, CURLINFO_HTTP_CODE);
        curl_close($chNote);
        $noteWriteOk = ($noteHttp >= 200 && $noteHttp < 300);
        if (!$noteWriteOk) $noteWriteError = "HTTP {$noteHttp}: " . substr((string)$noteResp, 0, 200);
    }

    // --- Krok 4: přepnutí stavu podle výsledku celého průchodu. Obvykle JEDEN
    // krok, ale pro "skladem, ale nezaplacené jinak než COD/hotově" (typicky
    // převod na účet) se provádí DVOJKROK: nejdřív "PŘIPRAVUJE SE K EXPEDICI"
    // (email), hned poté "NEZAPLACENO" (druhý email) - to je záměr, ne chyba.
    $stateSteps = []; // [['name' => ..., 'email' => bool], ...] - v tomhle pořadí
    if ($allResolved) {
        if ($anyVavrysSent) {
            $stateSteps[] = ['name' => 'ČEKÁ NA EXPEDICI', 'email' => true];
        } else {
            // Platbu ověřujeme ŽIVĚ z e-shopu, ne z (možná zastaralé) databáze -
            // stejná oprava, jakou jsme dřív dělali u "Ručně vyřídit objednávku".
            $liveOrderForPay = eshop_new_api_call($baseUrl, $token, $orderIriReq);
            $livePaymentName = (string)($liveOrderForPay['body']['orderDeliveryPayment']['namePayment'] ?? '');
            $isCod = stripos($livePaymentName, 'dobírk') !== false || stripos($livePaymentName, 'dobirk') !== false;
            $isCash = stripos($livePaymentName, 'hotov') !== false;

            $liveInvoice = eshop_new_fetch_order_invoice($baseUrl, $token, $orderIriReq, $liveOrderForPay['body']['created'] ?? null);
            $isPaid = $liveInvoice !== null && (int)($liveInvoice['paymentStatus'] ?? 0) === 1;

            if ($isPaid || $isCod || $isCash) {
                $stateSteps[] = ['name' => 'PŘIPRAVUJE SE K EXPEDICI', 'email' => true];
            } else {
                // Skladem dle našich podmínek, ale nezaplaceno (a není COD/hotově,
                // u těch se platí až při/po doručení, takže to sem nikdy nespadne).
                // Zákazník dostane napřed info, že je zboží připravené k expedici,
                // a hned poté (protože platba pořád chybí) info, že je nezaplacené.
                $stateSteps[] = ['name' => 'PŘIPRAVUJE SE K EXPEDICI', 'email' => true];
                $stateSteps[] = ['name' => 'NEZAPLACENO', 'email' => true];
            }
        }
    } else {
        $stateSteps[] = ['name' => 'ČEKÁ NA OBJEDNÁNÍ', 'email' => false];
    }

    foreach ($stateSteps as $step) {
        $targetStateIri = null;
        foreach ($orderStates as $st) {
            if (mb_strtoupper(trim((string)($st['name'] ?? '')), 'UTF-8') === $step['name']) { $targetStateIri = $st['@id']; break; }
        }
        if (!$targetStateIri) continue; // stav s tímhle názvem v e-shopu neexistuje - přeskočit, ne shodit celé vyřízení

        $ch2 = curl_init($baseUrl . '/api-engine/order-state-changes');
        $body2 = json_encode(['order' => $orderIriReq, 'orderState' => $targetStateIri, 'isSendNotifications' => $step['email']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => $body2,
            CURLOPT_HTTPHEADER => ['X-AUTH-TOKEN: ' . $token, 'Content-Type: application/ld+json', 'Accept: application/ld+json'],
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
        ]);
        curl_exec($ch2);
        $http2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        if ($http2 >= 200 && $http2 < 300) {
            $fresh2 = eshop_new_api_call($baseUrl, $token, $orderIriReq);
            if ($fresh2['ok'] && is_array($fresh2['body'])) eshop_new_persist_order($pdo, $baseUrl, $token, $fresh2['body']);
        }
    }

    $summaryBits = [];
    if (!empty($polozky)) $summaryBits[] = (!empty($vavrysSendResult['success']) ? 'odesláno k Vavrys (' . count($polozky) . ')' : 'Vavrys selhal');
    if ($stockCorrectedCount > 0) $summaryBits[] = "opraven sklad u {$stockCorrectedCount}";
    $summaryText = $summaryBits ? implode(', ', $summaryBits) : 'žádná položka nevyžadovala akci';

    $appliedStateNames = array_column($stateSteps, 'name');
    $_SESSION['nn_flash'] = ['ok' => $allResolved, 'text' => "Objednávka vyřízena: {$summaryText}."
        . ($noteWriteOk ? ' Poznámka zapsána do e-shopu.' : ' ⚠ Poznámku se nepodařilo zapsat do e-shopu.')
        . ($appliedStateNames ? ' Stav: ' . implode(' → ', $appliedStateNames) . '.' : '')];

    header('Location: nove-objednavky.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : '') . '#order-' . row_id($orderIriReq));
    exit;
}

// ---------------------------------------------------------------------------
// Změna stavu objednávky (zápis do e-shopu, s volbou e-mailu)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_note') {
    $orderIriReq = (string)($_POST['order_iri'] ?? '');
    $noteText = (string)($_POST['note_text'] ?? '');
    if ($orderIriReq !== '') {
        $ch4 = curl_init($baseUrl . $orderIriReq);
        curl_setopt_array($ch4, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode(['internalNote' => $noteText], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['X-AUTH-TOKEN: ' . $token, 'Content-Type: application/merge-patch+json', 'Accept: application/ld+json'],
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
        ]);
        curl_exec($ch4);
        $http4 = (int)curl_getinfo($ch4, CURLINFO_HTTP_CODE);
        curl_close($ch4);
        if ($http4 >= 200 && $http4 < 300) {
            $fresh4 = eshop_new_api_call($baseUrl, $token, $orderIriReq);
            if ($fresh4['ok'] && is_array($fresh4['body'])) eshop_new_persist_order($pdo, $baseUrl, $token, $fresh4['body']);
            $_SESSION['nn_flash'] = ['ok' => true, 'text' => 'Poznámka uložena do e-shopu.'];
        } else {
            $_SESSION['nn_flash'] = ['ok' => false, 'text' => "Uložení poznámky selhalo (HTTP {$http4})."];
        }
    }
    header('Location: nove-objednavky.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : '') . '#order-' . row_id($orderIriReq));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_payment') {
    $orderIriReq = (string)($_POST['order_iri'] ?? '');
    if ($orderIriReq !== '') {
        $fresh = eshop_new_api_call($baseUrl, $token, $orderIriReq);
        if ($fresh['ok'] && is_array($fresh['body'])) {
            eshop_new_persist_order($pdo, $baseUrl, $token, $fresh['body']);
            $_SESSION['nn_flash'] = ['ok' => true, 'text' => 'Platba aktualizována podle aktuálního stavu faktury.'];
        } else {
            $_SESSION['nn_flash'] = ['ok' => false, 'text' => 'Nepodařilo se objednávku načíst.'];
        }
    }
    header('Location: nove-objednavky.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : '') . '#order-' . row_id($orderIriReq));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_state') {
    $orderIriReq = (string)($_POST['order_iri'] ?? '');
    $newStateIri = (string)($_POST['new_state_iri'] ?? '');
    $sendEmail = !empty($_POST['send_email']);
    if ($newStateIri === '' || $orderIriReq === '') {
        $_SESSION['nn_flash'] = ['ok' => false, 'text' => 'Nebyl vybrán žádný stav.'];
    } else {
        $ch = curl_init($baseUrl . '/api-engine/order-state-changes');
        $body = json_encode(['order' => $orderIriReq, 'orderState' => $newStateIri, 'isSendNotifications' => $sendEmail], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['X-AUTH-TOKEN: ' . $token, 'Content-Type: application/ld+json', 'Accept: application/ld+json'],
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
        ]);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http >= 200 && $http < 300) {
            // Najdeme jméno stavu pro poznámku
            $newStateName = '';
            foreach ($orderStates as $st) {
                if (($st['@id'] ?? '') === $newStateIri) { $newStateName = (string)($st['name'] ?? ''); break; }
            }
            $autoNote = 'Stav ručně změněn na: ' . $newStateName . ($sendEmail ? ' (odeslán e-mail)' : ' (bez e-mailu)');

            $existingNoteStmt = $pdo->prepare("SELECT vavrys_note FROM eshop_new_orders WHERE order_iri = :o");
            $existingNoteStmt->execute([':o' => $orderIriReq]);
            $existingNote = trim((string)($existingNoteStmt->fetchColumn() ?: ''));
            $finalNote = trim($existingNote !== '' ? ($existingNote . "\n\n" . $autoNote) : $autoNote);
            $pdo->prepare("UPDATE eshop_new_orders SET vavrys_note = :n WHERE order_iri = :o")->execute([':n' => $finalNote, ':o' => $orderIriReq]);

            $fresh = eshop_new_api_call($baseUrl, $token, $orderIriReq);
            if ($fresh['ok'] && is_array($fresh['body'])) {
                $existingEshopNote = trim((string)($fresh['body']['internalNote'] ?? ''));
                $combinedEshopNote = trim($existingEshopNote !== '' ? ($existingEshopNote . "\n\n" . $autoNote) : $autoNote);
                $chN = curl_init($baseUrl . $orderIriReq);
                curl_setopt_array($chN, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PATCH',
                    CURLOPT_POSTFIELDS => json_encode(['internalNote' => $combinedEshopNote], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    CURLOPT_HTTPHEADER => ['X-AUTH-TOKEN: ' . $token, 'Content-Type: application/merge-patch+json', 'Accept: application/ld+json'],
                    CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
                ]);
                curl_exec($chN);
                curl_close($chN);
                // Znovu načíst čerstvá data (i s novou poznámkou) do naší DB.
                $fresh2 = eshop_new_api_call($baseUrl, $token, $orderIriReq);
                if ($fresh2['ok'] && is_array($fresh2['body'])) eshop_new_persist_order($pdo, $baseUrl, $token, $fresh2['body']);
            }
            $_SESSION['nn_flash'] = ['ok' => true, 'text' => 'Stav byl změněn' . ($sendEmail ? ' a e-mail odeslán.' : ', bez e-mailu.') . ' Poznámka zapsána.'];
        } else {
            $_SESSION['nn_flash'] = ['ok' => false, 'text' => "Změna stavu selhala (HTTP {$http})."];
        }
    }
    header('Location: nove-objednavky.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : '') . '#order-' . row_id($orderIriReq));
    exit;
}

// ---------------------------------------------------------------------------
// Příprava náhledu opravy duplicitního skladu (jen zaškrtnuté položky)
// ---------------------------------------------------------------------------
$stockfixPreview = null;

function load_stockfix_checks(PDO $pdo, string $orderIri): array
{
    $st = $pdo->prepare("SELECT item_iri, checked FROM eshop_new_order_item_stockfix_checks WHERE order_iri = :o");
    $st->execute([':o' => $orderIri]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[$r['item_iri']] = (bool)$r['checked'];
    return $map;
}


function fmt_money($amount): string { return number_format((float)$amount, 2, ',', ' ') . ' Kč'; }

function pick_text_color(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#1b1f23';
    $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
    $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $lum > 0.6 ? '#1b1f23' : '#ffffff';
}
function short_delivery(string $name): string
{
    $name = trim($name);
    if ($name === '') return '';
    $pos = mb_strpos($name, ' - ');
    return $pos !== false ? trim(mb_substr($name, 0, $pos)) : $name;
}

// (seznam a barvy stavů se načítají hned na začátku souboru)

$search = trim((string)($_GET['q'] ?? ''));
$dateFrom = trim((string)($_GET['od'] ?? ''));
$dateTo = trim((string)($_GET['do'] ?? ''));

$where = []; $params = [];
if ($search !== '') {
    $where[] = "(customer_email LIKE :q1 OR customer_phone LIKE :q2 OR order_number LIKE :q3 OR raw_json LIKE :q4)";
    $params[':q1'] = $params[':q2'] = $params[':q3'] = $params[':q4'] = '%' . $search . '%';
}
if ($dateFrom !== '') { $where[] = "created >= :df"; $params[':df'] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $where[] = "created <= :dt"; $params[':dt'] = $dateTo . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$perPage = 30;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM eshop_new_orders {$whereSql}");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$stmt = $pdo->prepare("SELECT * FROM eshop_new_orders {$whereSql} ORDER BY created DESC LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$itemsByOrder = []; $checksByOrder = []; $stockfixChecksByOrder = [];
if (!empty($rows)) {
    $iris = array_column($rows, 'order_iri');
    $ph = implode(',', array_fill(0, count($iris), '?'));
    $itemsStmt = $pdo->prepare("SELECT * FROM eshop_new_order_items WHERE order_iri IN ($ph)");
    $itemsStmt->execute($iris);
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $it) $itemsByOrder[$it['order_iri']][] = $it;

    $checksStmt = $pdo->prepare("SELECT order_iri, item_iri, vavrys_check FROM eshop_new_order_item_checks WHERE order_iri IN ($ph)");
    $checksStmt->execute($iris);
    foreach ($checksStmt->fetchAll(PDO::FETCH_ASSOC) as $c) $checksByOrder[$c['order_iri']][$c['item_iri']] = (bool)$c['vavrys_check'];

    $stockfixChecksStmt = $pdo->prepare("SELECT order_iri, item_iri, checked FROM eshop_new_order_item_stockfix_checks WHERE order_iri IN ($ph)");
    $stockfixChecksStmt->execute($iris);
    $stockfixChecksByOrder = [];
    foreach ($stockfixChecksStmt->fetchAll(PDO::FETCH_ASSOC) as $c) $stockfixChecksByOrder[$c['order_iri']][$c['item_iri']] = (bool)$c['checked'];
}

$thisPreviewGlobalId = null;
if ($vavrysPreview) $thisPreviewGlobalId = json_encode(row_id($vavrysPreview['order_iri']));
elseif ($stockfixPreview) $thisPreviewGlobalId = json_encode(row_id($stockfixPreview['order_iri']));

$lastWebhookLine = '';
$webhookLogPath = __DIR__ . '/webhook_log.txt';
if (is_file($webhookLogPath)) { $lines = file($webhookLogPath, FILE_IGNORE_NEW_LINES); if ($lines) $lastWebhookLine = end($lines); }
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Nové objednávky</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root { --g1:#24d84a; --g2:#00b52a; --ink:#1b1f23; --muted:#6b7280; --border:#e7e9ec; --bg-soft:#f7f8f9; --danger:#d93025; --danger-bg:#fdeceb; }
* { box-sizing:border-box; }
body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; color:var(--ink); }
.wrap { max-width:1400px; margin:0 auto; }
.topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; flex-wrap:wrap; gap:10px; }
.back-link { color:var(--muted); font-size:12.5px; text-decoration:none; border:1px solid var(--border); border-radius:999px; padding:7px 14px; }
h1 { font-size:22px; margin:0; font-weight:800; }
.flash { border-radius:12px; padding:12px 16px; font-size:13px; margin-bottom:14px; }
.flash-ok { background:#eafbf0; color:#0a7a34; border:1px solid #bdeccb; }
.flash-err { background:var(--danger-bg); color:var(--danger); border:1px solid #f5c6c2; }

.filter-card { background:#fff; border:1px solid var(--border); border-radius:16px; padding:16px 18px; margin-bottom:16px; box-shadow:0 1px 3px rgba(0,0,0,0.03); }
.filter-row { display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end; }
.field { display:flex; flex-direction:column; gap:4px; }
.field label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; }
.field input[type=text], .field input[type=date] { border:1px solid var(--border); border-radius:10px; padding:9px 12px; font-size:13.5px; min-width:160px; }
.field input[type=text] { min-width:260px; }
.date-range { display:flex; align-items:center; gap:6px; }
.btn { border:none; border-radius:999px; padding:9px 16px; font-size:12.5px; font-weight:700; cursor:pointer; white-space:nowrap; text-decoration:none; display:inline-block; }
.btn-primary { background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; }
.btn-row { display:flex; gap:8px; margin-left:auto; flex-wrap:wrap; }

.summary-bar { display:flex; gap:10px; flex-wrap:wrap; font-size:13px; color:var(--muted); margin-bottom:14px; align-items:center; }
.summary-chip { background:var(--bg-soft); border:1px solid var(--border); border-radius:10px; padding:6px 12px; }
.info-chip { background:#eef4fb; border:1.5px solid #cfe0f3; color:#2a4a6b; }

.orders-header-row {
    display:grid; grid-template-columns: 95px 1.2fr 1.1fr 1fr 100px 100px 155px 125px 100px;
    gap:8px; padding:9px 14px; font-size:10px; text-transform:uppercase; letter-spacing:.03em;
    color:#fff; font-weight:800; background:linear-gradient(135deg,var(--g1),var(--g2)); border-radius:12px; margin-bottom:6px;
}
.order-card { background:#fff; border:1px solid var(--border); border-radius:10px; margin-bottom:5px; overflow:hidden; }
.order-card:hover { box-shadow:0 3px 12px rgba(0,0,0,0.06); }
.order-card.expanded { border-color:#2b6cf6; box-shadow:0 4px 18px rgba(0,0,0,0.10); margin-bottom:14px; margin-top:4px; }
.order-head { display:grid; grid-template-columns: 95px 1.2fr 1.1fr 1fr 100px 100px 155px 125px 100px; gap:8px; align-items:center; padding:6px 14px; cursor:pointer; min-height:34px; }
.order-head:hover { background:var(--bg-soft); }
.order-card.expanded .order-head { background:var(--bg-soft); border-bottom:1px solid var(--border); }
.oh-value { font-size:11.5px; font-weight:600; line-height:1.25; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.oh-value.muted { color:var(--muted); font-weight:400; }
.paid-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:5px; vertical-align:middle; flex-shrink:0; }
.pill { display:inline-block; min-width:145px; text-align:center; padding:3px 8px; border-radius:8px; font-size:10px; font-weight:800; background:#eef6ee; color:#2a7a2a; line-height:1.4; }

.order-detail { display:none; border-top:1px solid var(--border); background:var(--bg-soft); padding:14px 16px; }
.items-table-scroll { width:100%; overflow-x:auto; border-radius:10px; background:#fff; }
.items-table { width:100%; min-width:680px; border-collapse:collapse; background:#fff; border-radius:10px; overflow:hidden; font-size:12px; }
.items-table th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.02em; color:var(--muted); background:#fff; padding:7px 9px; border-bottom:2px solid var(--border); }
.items-table td { padding:5px 9px; border-bottom:1px solid var(--border); vertical-align:middle; }
.items-table tr:last-child td { border-bottom:none; }
.items-table td.num { text-align:right; font-variant-numeric:tabular-nums; }
.neg { color:var(--danger); font-weight:700; }
.col-chk { width:24px; }
.state-form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:10px; font-size:12px; }
.state-form select { padding:6px 8px; border:1px solid var(--border); border-radius:8px; font-size:12px; }
.vavrys-box { margin-top:12px; padding-top:10px; border-top:1px dashed var(--border); font-size:12.5px; }
.confirm-box { border:1.5px dashed #e08b00; border-radius:10px; padding:10px 12px; margin-top:8px; background:#fffaf0; }
.warn { color:#e08b00; font-weight:700; }
.print-link { font-size:11.5px; color:var(--g2); text-decoration:none; font-weight:700; }

.pagination { display:flex; gap:10px; justify-content:center; margin-top:16px; font-size:13px; }
.pagination a { color:var(--g2); text-decoration:none; font-weight:700; }
.empty-msg { text-align:center; color:var(--muted); padding:40px 0; font-size:14px; }

@media (max-width: 1100px) { .order-head, .orders-header-row { grid-template-columns: 1fr 1fr; grid-auto-rows:auto; } }
</style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <a class="back-link" href="index.php">&larr; Zpět na Nový Cfloat</a>
        <h1>Nové objednávky</h1>
        <div></div>
    </div>

    <?php if ($flash): ?><div class="flash <?php echo $flash['ok'] ? 'flash-ok' : 'flash-err'; ?>"><?php echo h($flash['text']); ?></div><?php endif; ?>

    <div class="filter-card">
        <form method="get" class="filter-row">
            <div class="field"><label>Hledat</label><input type="text" name="q" value="<?php echo h($search); ?>" placeholder="Číslo objednávky, e-mail, telefon..."></div>
            <div class="field"><label>Období</label><div class="date-range">
                <input type="date" name="od" value="<?php echo h($dateFrom); ?>"> &ndash; <input type="date" name="do" value="<?php echo h($dateTo); ?>">
            </div></div>
            <div class="btn-row">
                <button type="submit" class="btn btn-primary">Hledat</button>
                <?php if ($search !== '' || $dateFrom !== '' || $dateTo !== ''): ?><a href="nove-objednavky.php" class="btn" style="background:var(--bg-soft);color:var(--ink);border:1px solid var(--border);">Zrušit filtr</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="summary-bar">
        <div class="summary-chip">Objednávek celkem: <b><?php echo $totalCount; ?></b>, strana <?php echo $page; ?>/<?php echo $totalPages; ?></div>
        <div class="summary-chip info-chip"><?php echo $lastWebhookLine !== '' ? 'Poslední webhook: ' . h($lastWebhookLine) : 'Webhook zatím nezaznamenal aktivitu.'; ?></div>
    </div>

    <div class="orders-header-row">
        <div>Datum</div><div>Zákazník</div><div>E-mail</div><div>Telefon</div><div>Objednávka</div><div>Faktura</div><div>Stav</div><div>Platba</div><div>Doprava</div>
    </div>

    <?php if (empty($rows)): ?><div class="empty-msg">Žádné objednávky k zobrazení.</div><?php endif; ?>

    <?php foreach ($rows as $r): ?>
        <?php
            $decoded = json_decode((string)$r['raw_json'], true);
            $name = is_array($decoded) ? ($decoded['name'] ?? '') : '';
            $created = $r['created'] ?? null;
            $createdDisp = $created ? date('d.m.Y H:i', strtotime($created)) : '-';
            $orderIri = $r['order_iri'];
            $rowId = row_id($orderIri);

            $payName = (string)($r['payment_name'] ?? '');
            $payStatus = $r['payment_status'];
            $isCod = (stripos($payName, 'dobírk') !== false || stripos($payName, 'dobirk') !== false);
            if ($payStatus === null) $dotColor = '#ccc';
            elseif ((int)$payStatus === 1) $dotColor = '#24d84a';
            elseif ($isCod) $dotColor = '#ccc';
            else $dotColor = '#d93025';

            $itemsRaw = $itemsByOrder[$orderIri] ?? [];
            $checksMap = $checksByOrder[$orderIri] ?? [];

            $thisPreview = ($vavrysPreview && ($vavrysPreview['order_iri'] ?? '') === $orderIri) ? $vavrysPreview : null;
            $thisStockfixPreview = ($stockfixPreview && ($stockfixPreview['order_iri'] ?? '') === $orderIri) ? $stockfixPreview : null;
            $stockfixChecksMap = $stockfixChecksByOrder[$orderIri] ?? [];
        ?>
        <div class="order-card" id="order-<?php echo $rowId; ?>">
            <div class="order-head" onclick="toggleOrderDetail('<?php echo $rowId; ?>')">
                <div><span class="oh-value muted"><?php echo h($createdDisp); ?></span></div>
                <div><span class="oh-value"><?php echo h($name ?: '-'); ?></span></div>
                <div><span class="oh-value muted"><?php echo h($r['customer_email'] ?: '-'); ?></span></div>
                <div><span class="oh-value muted"><?php echo h($r['customer_phone'] ?: '-'); ?></span></div>
                <div><span class="oh-value"><?php echo h($r['order_number'] ?: '-'); ?></span></div>
                <div><span class="oh-value"><?php echo h($r['invoice_number'] ?: '-'); ?></span></div>
                <div><?php
                    if (!empty($r['order_state_name'])) {
                        $stColor = $stateColors[$r['order_state_iri']] ?? '';
                        if ($stColor !== '') echo '<span class="pill" style="background:' . h($stColor) . ';color:' . h(pick_text_color($stColor)) . ';">' . h($r['order_state_name']) . '</span>';
                        else echo '<span class="pill">' . h($r['order_state_name']) . '</span>';
                    } else echo '-';
                ?></div>
                <div><span class="oh-value"><span class="paid-dot" style="background:<?php echo $dotColor; ?>;"></span><?php echo h($payName ?: '-'); ?></span></div>
                <div><span class="oh-value muted"><?php echo h(short_delivery((string)$r['delivery_name']) ?: '-'); ?></span></div>
            </div>
            <div id="order-detail-<?php echo $rowId; ?>" class="order-detail">
                <?php if (!empty($itemsRaw)): ?>
                <div class="items-table-scroll">
                    <table class="items-table">
                        <thead><tr><th class="col-chk">Vavrys</th><th class="col-chk">Sklad</th><th>Kód</th><th>EAN</th><th>Produkt</th><th>Velikost</th><th>Ks</th><th>Sklad</th><th>Zbývá</th></tr></thead>
                        <tbody>
                        <?php foreach ($itemsRaw as $it): ?>
                            <?php $zbyva = $it['stock'] !== null ? (int)$it['stock'] - (int)$it['pieces'] : null;
                                  $isChecked = $checksMap[$it['item_iri']] ?? false;
                                  $isStockfixChecked = $stockfixChecksMap[$it['item_iri']] ?? false;
                                  $canStockfix = !empty($it['ean']) && !empty($it['id_product_item']); ?>
                            <tr>
                                <td class="col-chk"><input type="checkbox" class="vavrys-chk" data-order-iri="<?php echo h($orderIri); ?>" data-item-iri="<?php echo h($it['item_iri']); ?>" <?php echo $isChecked ? 'checked' : ''; ?>></td>
                                <td class="col-chk">
                                    <?php if ($canStockfix): ?>
                                        <input type="checkbox" class="stockfix-chk" data-order-iri="<?php echo h($orderIri); ?>" data-item-iri="<?php echo h($it['item_iri']); ?>" <?php echo $isStockfixChecked ? 'checked' : ''; ?>>
                                    <?php else: ?>
                                        <span title="Chybí EAN nebo přesná varianta" style="color:#ccc;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h($it['number']); ?></td>
                                <td><?php echo h($it['ean'] ?: '-'); ?></td>
                                <td><?php echo h($it['name']); ?></td>
                                <td><?php echo h($it['size']); ?></td>
                                <td class="num"><?php echo (int)$it['pieces']; ?></td>
                                <td class="num"><?php echo $it['stock'] !== null ? (int)$it['stock'] : '?'; ?><?php echo (empty($it['stock_exact']) && $it['stock'] !== null) ? ' ⚠' : ''; ?></td>
                                <td class="num<?php echo ($zbyva !== null && $zbyva < 0) ? ' neg' : ''; ?>"><?php echo $zbyva !== null ? $zbyva : '?'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <p style="color:var(--muted);font-size:12px;">Položky se ještě nenačetly pro tuhle objednávku.</p>
                <?php endif; ?>

                <form method="post" style="margin-top:10px;" onclick="event.stopPropagation();">
                    <input type="hidden" name="action" value="save_note">
                    <input type="hidden" name="order_iri" value="<?php echo h($orderIri); ?>">
                    <label style="font-size:11px;font-weight:700;color:var(--muted);display:block;margin-bottom:4px;">Poznámka k objednávce (sdílená s e-shopem)</label>
                    <textarea name="note_text" rows="2" style="width:100%;max-width:600px;padding:8px;border:1px solid var(--border);border-radius:8px;font-size:12.5px;font-family:inherit;" onclick="event.stopPropagation();"><?php echo h((string)($decoded['internalNote'] ?? '')); ?></textarea>
                    <br>
                    <button type="submit" class="btn" style="margin-top:6px;background:var(--bg-soft);color:var(--ink);border:1px solid var(--border);">Uložit poznámku</button>
                </form>

                <form method="post" class="state-form" onsubmit="event.stopPropagation();">
                    <input type="hidden" name="action" value="change_state">
                    <input type="hidden" name="order_iri" value="<?php echo h($orderIri); ?>">
                    <select name="new_state_iri">
                        <option value="">-- změnit stav na --</option>
                        <?php foreach ($orderStates as $st): ?>
                            <?php $optColor = (string)($st['color'] ?? ''); $optStyle = $optColor !== '' ? " style=\"background:{$optColor};color:" . pick_text_color($optColor) . ";\"" : ''; ?>
                            <option value="<?php echo h($st['@id']); ?>"<?php echo $optStyle; ?>><?php echo h($st['name'] ?? ''); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label><input type="checkbox" name="send_email" value="1"> Odeslat e-mail</label>
                    <button type="submit" class="btn btn-primary">Uložit stav</button>
                    <a class="print-link" href="nove-objednavky-tisk.php?iri=<?php echo urlencode($orderIri); ?>" target="_blank" onclick="event.stopPropagation();">🖨 Tisk</a>
                    <form method="post" style="display:inline;" onclick="event.stopPropagation();">
                        <input type="hidden" name="action" value="refresh_payment">
                        <input type="hidden" name="order_iri" value="<?php echo h($orderIri); ?>">
                        <button type="submit" class="print-link" style="background:none;border:none;cursor:pointer;font-weight:700;">🔄 Aktualizovat platbu</button>
                    </form>
                </form>

                <div class="vavrys-box" onclick="event.stopPropagation();">
                    <?php if (!empty($r['resolved_at']) || !empty($r['vavrys_sent_at'])): ?>
                        <?php
                            $vStatus = (string)($r['vavrys_status'] ?? '');
                            $badgeBg = $vStatus === 'green' ? '#eafbf0' : ($vStatus === 'yellow' ? '#fff8e6' : '#fdeceb');
                            $badgeFg = $vStatus === 'green' ? '#0a7a34' : ($vStatus === 'yellow' ? '#8a5a00' : '#d93025');
                            $badgeIcon = $vStatus === 'green' ? '✓' : ($vStatus === 'yellow' ? '⚠' : '✕');
                            $badgeLabel = !empty($r['vavrys_sent_at']) ? 'Vavrys odesláno' : 'Vyřízeno';
                            $badgeTime = $r['vavrys_sent_at'] ?? $r['resolved_at'];
                        ?>
                        <div style="background:<?php echo $badgeBg; ?>;color:<?php echo $badgeFg; ?>;border-radius:8px;padding:6px 10px;font-size:12px;font-weight:700;margin-bottom:8px;display:inline-block;">
                            <?php echo $badgeIcon; ?> <?php echo h($badgeLabel); ?> <?php echo h(date('d.m.Y H:i', strtotime((string)$badgeTime))); ?>
                        </div>
                        <?php if (!empty($r['vavrys_note'])): ?>
                            <pre style="white-space:pre-wrap;background:#fff;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:12px;margin:0 0 8px;font-family:inherit;"><?php echo h($r['vavrys_note']); ?></pre>
                        <?php endif; ?>
                    <?php endif; ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Vyřídit objednávku podle zaškrtnutých položek (Vavrys + sklad) a přepnout stav?');">
                        <input type="hidden" name="action" value="vyridit_objednavku">
                        <input type="hidden" name="order_iri" value="<?php echo h($orderIri); ?>">
                        <button type="submit" class="btn" style="background:#111;color:#fff;">Vyřídit objednávku</button>
                    </form>
                    <p class="note" style="margin-top:6px;">Zaškrtněte položky ve sloupci "Vavrys" (objednat u dodavatele) a/nebo "Sklad" (opravit duplicitní výprodejový záznam). Nezaškrtnuté položky, které jsou i tak skladem, se do vyřízení počítají automaticky. Stav objednávky a e-mail se nastaví podle výsledku - viz pravidla výše v konverzaci.</p>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="pagination">
        <?php $qs = array_filter(['q' => $search, 'od' => $dateFrom, 'do' => $dateTo]); ?>
        <?php if ($page > 1): ?><a href="?<?php echo h(http_build_query($qs + ['page' => $page - 1])); ?>">&larr; Předchozí</a><?php endif; ?>
        <?php if ($page < $totalPages): ?><a href="?<?php echo h(http_build_query($qs + ['page' => $page + 1])); ?>">Další &rarr;</a><?php endif; ?>
    </div>
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
(function() {
    var forceOpenId = <?php echo $thisPreviewGlobalId ?? 'null'; ?>;
    var m = (window.location.hash || '').match(/^#order-([a-f0-9]+)$/);
    var targetId = forceOpenId || (m ? m[1] : null);
    if (targetId) {
        setTimeout(function() {
            var d = document.getElementById('order-detail-' + targetId);
            if (d) d.style.display = 'block';
            var c = document.getElementById('order-' + targetId);
            if (c) { c.classList.add('expanded'); c.scrollIntoView({ block: 'center' }); }
        }, 30);
    }
})();
document.querySelectorAll('.vavrys-chk').forEach(function(chk) {
    chk.addEventListener('click', function(e) { e.stopPropagation(); });
    chk.addEventListener('change', function() {
        var body = new URLSearchParams();
        body.set('ajax_toggle', '1');
        body.set('type', 'vavrys');
        body.set('order_iri', chk.dataset.orderIri);
        body.set('item_iri', chk.dataset.itemIri);
        body.set('checked', chk.checked ? '1' : '0');
        fetch('nove-objednavky.php', { method: 'POST', body: body })
            .then(function(r) { return r.json(); })
            .then(function(d) { if (!d || !d.ok) { chk.checked = !chk.checked; alert('Uložení se nepovedlo.'); } })
            .catch(function() { chk.checked = !chk.checked; alert('Chyba spojení.'); });
    });
});
document.querySelectorAll('.stockfix-chk').forEach(function(chk) {
    chk.addEventListener('click', function(e) { e.stopPropagation(); });
    chk.addEventListener('change', function() {
        var body = new URLSearchParams();
        body.set('ajax_toggle', '1');
        body.set('type', 'stockfix');
        body.set('order_iri', chk.dataset.orderIri);
        body.set('item_iri', chk.dataset.itemIri);
        body.set('checked', chk.checked ? '1' : '0');
        fetch('nove-objednavky.php', { method: 'POST', body: body })
            .then(function(r) { return r.json(); })
            .then(function(d) { if (!d || !d.ok) { chk.checked = !chk.checked; alert('Uložení se nepovedlo.'); } })
            .catch(function() { chk.checked = !chk.checked; alert('Chyba spojení.'); });
    });
});
</script>
</body>
</html>
