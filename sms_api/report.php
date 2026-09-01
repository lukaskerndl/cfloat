<?php
// sms_api/report.php
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

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) $data = [];

$id = (int)($data['id'] ?? 0);
$status = (string)($data['status'] ?? '');
$error = (string)($data['error'] ?? '');

if ($id <= 0 || ($status !== 'sent' && $status !== 'failed')) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'bad_request'], JSON_UNESCAPED_UNICODE);
    exit;
}

sms_queue_report($pdo, $id, $status, $error);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
