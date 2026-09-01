<?php
// sms_api/next.php
declare(strict_types=1);

$cfgCandidates = [__DIR__ . '/../config.php', __DIR__ . '/../PredSMS/config.php', __DIR__ . '/../../config.php'];
$cfgLoaded = false;
foreach ($cfgCandidates as $p) { if (is_file($p)) { require $p; $cfgLoaded = true; break; } }
if (!$cfgLoaded || !isset($pdo)) { http_response_code(500); echo '{"ok":false,"error":"missing_config"}'; exit; }

$lib = __DIR__ . '/../sms/sms_lib.php';
if (!is_file($lib)) { http_response_code(500); echo '{"ok":false,"error":"missing_sms_lib"}'; exit; }
require_once $lib;

$token = '';
if (isset($_SERVER['HTTP_X_API_KEY'])) $token = (string)$_SERVER['HTTP_X_API_KEY'];
if ($token === '' && isset($_GET['token'])) $token = (string)$_GET['token'];

sms_api_require_auth($token);

$settings = sms_load_settings();
if (empty($settings['enabled'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'message'=>null,'note'=>'disabled'], JSON_UNESCAPED_UNICODE);
    exit;
}

// denní limit
$sentToday = sms_get_sent_today($pdo);
$limit = (int)($settings['daily_limit'] ?? 40);
if ($sentToday >= $limit) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'message'=>null,'note'=>'daily_limit','sent_today'=>$sentToday,'limit'=>$limit], JSON_UNESCAPED_UNICODE);
    exit;
}

$row = sms_queue_next($pdo);
if (!$row) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'message'=>null], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int)$row['id'];
$to = '';
if (!empty($settings['test_mode'])) {
    $to = (string)($settings['test_phone'] ?? '');
} else {
    $to = (string)($row['phone_original'] ?? '');
}
$to = sms_normalize_phone($to);

if ($to === '') {
    // není kam poslat -> fail
    sms_queue_report($pdo, $id, 'failed', 'missing_to_phone');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'message'=>null,'note'=>'missing_to_phone'], JSON_UNESCAPED_UNICODE);
    exit;
}

sms_queue_mark_attempt($pdo, $id, $to);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'message' => [
        'id' => $id,
        'to' => $to,
        'text' => (string)$row['message'],
        'order_no' => (string)$row['order_no'],
        'carrier' => (string)$row['carrier'],
        'tracking' => (string)$row['tracking'],
        'test_mode' => !empty($settings['test_mode']),
    ],
    'sent_today' => $sentToday,
    'limit' => $limit,
], JSON_UNESCAPED_UNICODE);
