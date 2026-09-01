<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!headers_sent()) {
    if ($origin !== '' && in_array($origin, $VIP_ALLOWED_ORIGINS ?? [], true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Content-Type: application/json; charset=utf-8');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function vip_json(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    vip_json(['success' => false, 'message' => 'Použij POST.'], 405);
}

$fullName = trim((string)($_POST['full_name'] ?? ''));
$company  = trim((string)($_POST['company'] ?? ''));
$email    = trim((string)($_POST['email'] ?? ''));

if ($fullName === '' || $company === '' || $email === '') {
    vip_json(['success' => false, 'message' => 'Vyplňte prosím všechna pole.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    vip_json(['success' => false, 'message' => 'Zadejte prosím platný e-mail.'], 422);
}

try {
    $stmt = $pdo->prepare('INSERT INTO vip_registrace_requests (full_name, company, email, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute([
        $fullName,
        $company,
        $email,
        (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 65535),
    ]);
} catch (Throwable $e) {
    vip_json(['success' => false, 'message' => 'Nepodařilo se uložit registraci do databáze.'], 500);
}

$subject = 'Nová registrace zákazníka / Firemní, Oddílový | ' . $company . ' | ' . $fullName . ' | ' . $email;
$body = "VIP REGISTRACE\n"
      . "Jméno a příjmení: {$fullName}\n"
      . "Firma / Oddíl: {$company}\n"
      . "E-mail: {$email}\n"
      . "Datum: " . date('d.m.Y H:i:s') . "\n"
      . "IP: " . (string)($_SERVER['REMOTE_ADDR'] ?? '') . "\n";
$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'From: obchod@c-store.cz',
    'Reply-To: obchod@c-store.cz',
];
@mail($VIP_TO_EMAIL, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));

vip_json([
    'success' => true,
    'message' => 'Děkujeme. Váš účet bude aktivní do 24 hodin. Do emailu Vám přijde odkaz na vytvoření hesla.'
]);
