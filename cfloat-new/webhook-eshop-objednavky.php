<?php
declare(strict_types=1);

/**
 * cfloat-new/webhook-eshop-objednavky.php
 *
 * Přijímací endpoint pro webhooky "orderChange" a "orderCreate" z Eshop-rychle
 * (nové API). URL musí obsahovat ?token=... (secrets/eshop_new_api.php ->
 * webhook_secret), protože nemáme HMAC podepisování.
 *
 * Vlož v administraci e-shopu do OBOU polí "Webhook pro vytvoření objednávky"
 * i "Webhook pro změnu objednávky":
 *   https://TVOJE-DOMENA/cfloat-new/webhook-eshop-objednavky.php?token=...
 *
 * Při každém přijetí uloží kompletní objednávku VČETNĚ položek a skladu ke
 * konkrétní velikosti (viz lib/eshop_new_helpers.php - eshop_new_persist_order),
 * takže seznam a detail v cFloatu pak čtou z DB, ne živě z API.
 */

$cfgCandidates = [__DIR__ . '/../config.php', __DIR__ . '/../../config.php'];
$pdo = null;
foreach ($cfgCandidates as $p) {
    if (is_file($p)) { require $p; break; }
}
if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(500); exit('config missing'); }

require_once __DIR__ . '/lib/eshop_new_helpers.php';

$secretsPath = __DIR__ . '/../secrets/eshop_new_api.php';
$cfg = is_file($secretsPath) ? include $secretsPath : [];
$baseUrl = rtrim((string)($cfg['base_url'] ?? ''), '/');
$token   = trim((string)($cfg['token'] ?? ''));
$webhookSecret = trim((string)($cfg['webhook_secret'] ?? ''));

// --- Ochrana: token v URL musí sedět ---
$providedToken = (string)($_GET['token'] ?? '');
if ($webhookSecret === '' || !hash_equals($webhookSecret, $providedToken)) {
    http_response_code(403);
    exit('forbidden');
}

$rawBody = file_get_contents('php://input');
@file_put_contents(__DIR__ . '/webhook_last_payload.json', $rawBody);

$payload = json_decode($rawBody, true);

function extract_order_iris(?array $payload): array
{
    if (!$payload) return [];
    if (!empty($payload['orders']) && is_array($payload['orders'])) {
        return array_values(array_filter(array_map('strval', $payload['orders'])));
    }
    if (!empty($payload['data']) && is_array($payload['data'])) {
        $out = [];
        foreach ($payload['data'] as $item) {
            if (is_string($item)) $out[] = $item;
            elseif (is_array($item) && !empty($item['id'])) $out[] = (string)$item['id'];
            elseif (is_array($item) && !empty($item['@id'])) $out[] = (string)$item['@id'];
        }
        return $out;
    }
    if (array_is_list($payload)) {
        $out = [];
        foreach ($payload as $item) {
            if (is_string($item)) $out[] = $item;
            elseif (is_array($item) && !empty($item['id'])) $out[] = (string)$item['id'];
        }
        return $out;
    }
    if (!empty($payload['id'])) return [(string)$payload['id']];
    if (!empty($payload['@id'])) return [(string)$payload['@id']];
    return [];
}

$iris = extract_order_iris(is_array($payload) ? $payload : null);
$processed = 0;

foreach ($iris as $iri) {
    $path = str_starts_with($iri, '/api-engine/') ? $iri : ('/api-engine/orders/' . $iri);
    $res = eshop_new_api_call($baseUrl, $token, $path);
    if (!$res['ok'] || !is_array($res['body'])) continue;

    eshop_new_persist_order($pdo, $baseUrl, $token, $res['body']);
    $processed++;
}

@file_put_contents(
    __DIR__ . '/webhook_log.txt',
    date('Y-m-d H:i:s') . " - přijato, IRI nalezeno: " . count($iris) . ", zpracováno: {$processed}\n",
    FILE_APPEND
);

http_response_code(200);
echo json_encode(['ok' => true, 'received' => count($iris), 'processed' => $processed]);
