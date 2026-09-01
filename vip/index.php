<?php
require_once __DIR__ . '/config.php';
$total = 0;
try {
    $total = (int)$pdo->query('SELECT COUNT(*) FROM vip_registrace_requests')->fetchColumn();
} catch (Throwable $e) {
    $total = 0;
}
?><!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>VIP registrace</title></head>
<body style="font-family:Arial,sans-serif;padding:24px;max-width:900px;margin:0 auto;">
<h1>VIP registrace</h1>
<p>Celkem registrací: <strong><?= htmlspecialchars((string)$total, ENT_QUOTES, 'UTF-8') ?></strong></p>
<p>API endpoint: <code>/VIP/api/submit.php</code></p>
</body></html>
