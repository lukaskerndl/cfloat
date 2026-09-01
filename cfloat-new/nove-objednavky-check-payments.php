<?php
declare(strict_types=1);

/**
 * cfloat-new/nove-objednavky-check-payments.php
 *
 * CÍLENÝ, MALÝ cron - kontroluje POUZE objednávky za poslední 3 dny, které
 * máme uložené jako NEZAPLACENÉ (payment_status = 0 nebo NULL), a u nich
 * znovu zjistí stav platby z faktury. Nic jiného nedělá - nekontroluje
 * stav objednávky, sklad, nic - jen tohle jedno pole, u malého množství
 * objednávek (typicky pár desítek), takže je to levné a rychlé.
 *
 * Spuštění (cron, stejný princip jako sync_orders_live.php):
 *   https://cfloat.cz/cfloat-new/nove-objednavky-check-payments.php?token=TOKEN_ZE_secrets/cron_run_token.php
 *   doporučeno každých 5-10 minut
 */

$cfgCandidates = [__DIR__ . '/../config.php', __DIR__ . '/../../config.php'];
$pdo = null;
foreach ($cfgCandidates as $p) {
    if (is_file($p)) { require $p; break; }
}
if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(500); exit('config missing'); }

require_once __DIR__ . '/../_cron_guard.php';
require_once __DIR__ . '/lib/eshop_new_helpers.php';

$secretsPath = __DIR__ . '/../secrets/eshop_new_api.php';
$cfg = is_file($secretsPath) ? include $secretsPath : [];
$baseUrl = rtrim((string)($cfg['base_url'] ?? ''), '/');
$token   = trim((string)($cfg['token'] ?? ''));
if ($token === '') { http_response_code(500); exit('token missing'); }

// Jen nezaplacené objednávky za poslední 3 dny - malá dávka, ať se to vejde
// do časového limitu (cron-job.org zdarma má 30s). Běží jednou za minutu,
// takže i větší počet nezaplacených objednávek se prostřídá během pár běhů.
$stmt = $pdo->prepare("
    SELECT order_iri, created FROM eshop_new_orders
    WHERE (payment_status IS NULL OR payment_status = 0)
      AND created >= (NOW() - INTERVAL 3 DAY)
    ORDER BY updated_at ASC
    LIMIT 15
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$checked = 0;
$updated = 0;
$startTime = microtime(true);
$maxSeconds = 20; // bezpečná rezerva pod 30s limitem

foreach ($rows as $r) {
    if ((microtime(true) - $startTime) > $maxSeconds) break; // časová pojistka
    $checked++;
    $invoice = eshop_new_fetch_order_invoice($baseUrl, $token, $r['order_iri'], $r['created']);
    if ($invoice !== null && (int)($invoice['paymentStatus'] ?? 0) === 1) {
        $pdo->prepare("UPDATE eshop_new_orders SET payment_status = 1, updated_at = NOW() WHERE order_iri = :o")
            ->execute([':o' => $r['order_iri']]);
        $updated++;
    } else {
        // I když se nic nezměnilo, posuneme updated_at, ať se příště v pořadí
        // dostanou na řadu jiné objednávky (rovnoměrné prostřídání fronty).
        $pdo->prepare("UPDATE eshop_new_orders SET updated_at = NOW() WHERE order_iri = :o")
            ->execute([':o' => $r['order_iri']]);
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo "Zkontrolováno nezaplacených objednávek: {$checked}\n";
echo "Nově označeno jako zaplaceno: {$updated}\n";
