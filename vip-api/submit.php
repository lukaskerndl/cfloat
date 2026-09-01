<?php
error_reporting(0);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function out($arr, $code = 200){
    if (function_exists('http_response_code')) http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(['success' => false, 'message' => 'Použij POST.'], 405);
}

$fullName = trim((string)($_POST['full_name'] ?? ''));
$company  = trim((string)($_POST['company'] ?? ''));
$email    = trim((string)($_POST['email'] ?? ''));

if ($fullName === '' || $company === '' || $email === '') {
    out(['success' => false, 'message' => 'Vyplňte prosím všechna pole.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    out(['success' => false, 'message' => 'Zadejte prosím platný e-mail.'], 422);
}

$cfgCandidates = [dirname(__DIR__) . '/config.php', __DIR__ . '/../config.php'];
$cfgLoaded = false;
foreach ($cfgCandidates as $p) { if (is_file($p)) { require $p; $cfgLoaded = true; break; } }
if (!$cfgLoaded || !isset($pdo) || !($pdo instanceof PDO)) {
    out(['success' => false, 'message' => 'Chybí DB config.'], 500);
}

// no CREATE here; table must exist
try {
    $st = $pdo->prepare('INSERT INTO vip_registrace_requests (full_name, company, email, ip_address, user_agent, created_at) VALUES (:full_name, :company, :email, :ip_address, :user_agent, NOW())');
    $st->execute([
        ':full_name' => $fullName,
        ':company' => $company,
        ':email' => $email,
        ':ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'doesn\'t exist') !== false || stripos($msg, 'Base table or view not found') !== false) {
        out(['success' => false, 'message' => 'V DB chybí tabulka vip_registrace_requests.'], 500);
    }
    out(['success' => false, 'message' => 'Nepodařilo se uložit registraci.'], 500);
}

$mailCfg = [];
$mailCfgPath = dirname(__DIR__) . '/secrets/mail_smtp.php';
if (is_file($mailCfgPath)) {
    $tmp = include $mailCfgPath;
    if (is_array($tmp)) $mailCfg = $tmp;
}
$to = 'obchod@c-store.cz';
$subject = 'Nová registrace zákazníka / Firemní, Oddílový | ' . $company . ' | ' . $fullName . ' | ' . $email;
$body = "VIP registrace\n\nFirma / Oddíl: {$company}\nJméno a příjmení: {$fullName}\nE-mail: {$email}\nDatum: ".date('d.m.Y H:i:s');

// simple mail() fallback using same sender if available
$fromEmail = (string)($mailCfg['from_email'] ?? 'info@cfloat.cz');
$fromName  = (string)($mailCfg['from_name'] ?? 'C-Store.cz / VIP registrace');
$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'From: ' . $fromName . ' <' . $fromEmail . '>',
    'Reply-To: obchod@c-store.cz',
];
$subj = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8', 'B') : $subject;
$ok = @mail($to, $subj, $body, implode("\r\n", $headers), '-f ' . $fromEmail);

out([
    'success' => true,
    'message' => 'Děkujeme. Váš účet bude aktivní do 24 hodin. Do emailu Vám přijde odkaz na vytvoření hesla.',
    'mail_sent' => (bool)$ok,
]);
