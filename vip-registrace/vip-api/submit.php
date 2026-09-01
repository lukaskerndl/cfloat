<?php
error_reporting(0);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function vip_json($arr, $code) {
    if (function_exists('http_response_code')) {
        http_response_code((int)$code);
    } else {
        header('X-PHP-Response-Code: ' . (int)$code, true, (int)$code);
    }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function vip_log($file, $msg) {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function smtp_read($fp) {
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) break;
        $data .= $line;
        if (preg_match('/^\d{3}\s/', $line)) break;
    }
    return $data;
}

function smtp_write($fp, $cmd) {
    fwrite($fp, $cmd . "\r\n");
}

function smtp_expect($resp, $code) {
    return (bool)preg_match('/^' . preg_quote((string)$code, '/') . '/m', (string)$resp);
}

function vip_smtp_send($cfg, $to, $subject, $body, $hostOverride, $portOverride, $secureOverride) {
    $host      = (string)$hostOverride;
    $port      = (int)$portOverride;
    $secure    = strtolower((string)$secureOverride);
    $user      = isset($cfg['smtp_user']) ? (string)$cfg['smtp_user'] : '';
    $pass      = isset($cfg['smtp_pass']) ? (string)$cfg['smtp_pass'] : '';
    $fromEmail = isset($cfg['from_email']) ? (string)$cfg['from_email'] : $user;
    $fromName  = isset($cfg['from_name']) ? (string)$cfg['from_name'] : '';
    $replyTo   = isset($cfg['reply_to']) ? (string)$cfg['reply_to'] : '';
    $logFile   = isset($cfg['log_file']) ? (string)$cfg['log_file'] : (__DIR__ . '/logs/vip_mail.log');

    if ($host === '' || $user === '' || $pass === '' || $fromEmail === '') {
        vip_log($logFile, 'SMTP missing config');
        return false;
    }

    $remote = ($secure === 'ssl') ? ('ssl://' . $host . ':' . $port) : ($host . ':' . $port);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        vip_log($logFile, 'SMTP connect fail ' . $remote . ' errno=' . $errno . ' err=' . $errstr);
        return false;
    }
    stream_set_timeout($fp, 20);

    $r = smtp_read($fp);
    if (!smtp_expect($r, 220)) {
        vip_log($logFile, 'SMTP greet fail: ' . trim($r));
        fclose($fp);
        return false;
    }

    $hn = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    smtp_write($fp, 'EHLO ' . $hn);
    $r = smtp_read($fp);
    if (!smtp_expect($r, 250)) {
        smtp_write($fp, 'HELO ' . $hn);
        $r = smtp_read($fp);
        if (!smtp_expect($r, 250)) {
            vip_log($logFile, 'SMTP EHLO/HELO fail: ' . trim($r));
            fclose($fp);
            return false;
        }
    }

    if ($secure === 'tls') {
        smtp_write($fp, 'STARTTLS');
        $r = smtp_read($fp);
        if (!smtp_expect($r, 220)) {
            vip_log($logFile, 'SMTP STARTTLS fail: ' . trim($r));
            fclose($fp);
            return false;
        }

        $method = 0;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
        if ($method === 0 && defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')) $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;

        $cryptoOk = @stream_socket_enable_crypto($fp, true, $method);
        if (!$cryptoOk) {
            vip_log($logFile, 'SMTP TLS crypto fail');
            fclose($fp);
            return false;
        }

        smtp_write($fp, 'EHLO ' . $hn);
        $r = smtp_read($fp);
        if (!smtp_expect($r, 250)) {
            vip_log($logFile, 'SMTP EHLO after TLS fail: ' . trim($r));
            fclose($fp);
            return false;
        }
    }

    smtp_write($fp, 'AUTH LOGIN');
    $r = smtp_read($fp);
    if (!smtp_expect($r, 334)) {
        vip_log($logFile, 'SMTP AUTH LOGIN fail: ' . trim($r));
        fclose($fp);
        return false;
    }

    smtp_write($fp, base64_encode($user));
    $r = smtp_read($fp);
    if (!smtp_expect($r, 334)) {
        vip_log($logFile, 'SMTP user reject: ' . trim($r));
        fclose($fp);
        return false;
    }

    smtp_write($fp, base64_encode($pass));
    $r = smtp_read($fp);
    if (!smtp_expect($r, 235)) {
        vip_log($logFile, 'SMTP pass reject: ' . trim($r));
        fclose($fp);
        return false;
    }

    smtp_write($fp, 'MAIL FROM:<' . $fromEmail . '>');
    $r = smtp_read($fp);
    if (!smtp_expect($r, 250)) {
        vip_log($logFile, 'SMTP MAIL FROM fail: ' . trim($r));
        fclose($fp);
        return false;
    }

    $rcptList = array($to);
    if (!empty($cfg['mail_bcc']) && filter_var($cfg['mail_bcc'], FILTER_VALIDATE_EMAIL)) {
        $rcptList[] = trim((string)$cfg['mail_bcc']);
    }

    foreach ($rcptList as $rcpt) {
        smtp_write($fp, 'RCPT TO:<' . $rcpt . '>');
        $r = smtp_read($fp);
        if (!smtp_expect($r, 250) && !smtp_expect($r, 251)) {
            vip_log($logFile, 'SMTP RCPT TO fail for ' . $rcpt . ': ' . trim($r));
            fclose($fp);
            return false;
        }
    }

    smtp_write($fp, 'DATA');
    $r = smtp_read($fp);
    if (!smtp_expect($r, 354)) {
        vip_log($logFile, 'SMTP DATA fail: ' . trim($r));
        fclose($fp);
        return false;
    }

    if (function_exists('mb_encode_mimeheader')) {
        $subjectEnc = mb_encode_mimeheader($subject, 'UTF-8', 'B');
    } else {
        $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    $fromHeader = $fromEmail;
    if ($fromName !== '') {
        $fromHeader = $fromName . ' <' . $fromEmail . '>';
    }

    $headers = array();
    $headers[] = 'From: ' . $fromHeader;
    if ($replyTo !== '') $headers[] = 'Reply-To: ' . $replyTo;
    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Subject: ' . $subjectEnc;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    $msg = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $msg = preg_replace("/\r\n\./", "\r\n..", $msg);

    smtp_write($fp, $msg . "\r\n.");
    $r = smtp_read($fp);
    if (!smtp_expect($r, 250)) {
        vip_log($logFile, 'SMTP send fail: ' . trim($r));
        fclose($fp);
        return false;
    }

    smtp_write($fp, 'QUIT');
    fclose($fp);
    vip_log($logFile, 'SMTP OK to=' . $to . ' subj=' . $subject);
    return true;
}

function vip_send_mail($cfg, $subject, $body) {
    $to = isset($cfg['mail_to']) ? (string)$cfg['mail_to'] : 'obchod@c-store.cz';
    $logFile = isset($cfg['log_file']) ? (string)$cfg['log_file'] : (__DIR__ . '/logs/vip_mail.log');

    $hosts = isset($cfg['smtp_hosts']) && is_array($cfg['smtp_hosts']) ? $cfg['smtp_hosts'] : array();
    if (!$hosts && !empty($cfg['smtp_host'])) $hosts = array($cfg['smtp_host']);
    $tries = isset($cfg['smtp_tries']) && is_array($cfg['smtp_tries']) ? $cfg['smtp_tries'] : array(
        array('secure' => 'tls', 'port' => 587),
        array('secure' => 'ssl', 'port' => 465),
    );

    foreach ($hosts as $host) {
        $host = trim((string)$host);
        if ($host === '') continue;
        foreach ($tries as $try) {
            $secure = isset($try['secure']) ? $try['secure'] : 'tls';
            $port = isset($try['port']) ? (int)$try['port'] : 587;
            if (vip_smtp_send($cfg, $to, $subject, $body, $host, $port, $secure)) {
                return true;
            }
        }
    }

    $fromEmail = isset($cfg['from_email']) ? (string)$cfg['from_email'] : '';
    $fromName  = isset($cfg['from_name']) ? (string)$cfg['from_name'] : '';
    $replyTo   = isset($cfg['reply_to']) ? (string)$cfg['reply_to'] : '';

    $headers = array();
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    if ($fromEmail !== '') {
        $headers[] = 'From: ' . ($fromName !== '' ? ($fromName . ' <' . $fromEmail . '>') : $fromEmail);
        if ($replyTo !== '') $headers[] = 'Reply-To: ' . $replyTo;
    }

    if (function_exists('mb_encode_mimeheader')) {
        $subjectEnc = mb_encode_mimeheader($subject, 'UTF-8', 'B');
    } else {
        $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    if ($fromEmail !== '') {
        $ok = @mail($to, $subjectEnc, $body, implode("\r\n", $headers), '-f ' . $fromEmail);
    } else {
        $ok = @mail($to, $subjectEnc, $body, implode("\r\n", $headers));
    }
    vip_log($logFile, 'Fallback mail() ' . ($ok ? 'OK' : 'FAILED') . ' to=' . $to);
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vip_json(array('success' => false, 'message' => 'Použij POST.'), 405);
}

require __DIR__ . '/../config.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    vip_json(array('success' => false, 'message' => 'Chybí DB připojení.'), 500);
}

$raw = file_get_contents('php://input');
$data = array();
$contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string)$_SERVER['CONTENT_TYPE']) : '';

if (strpos($contentType, 'application/json') !== false) {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $data = $tmp;
} else {
    $data = $_POST;
    if (!is_array($data) || !$data) {
        parse_str((string)$raw, $tmp);
        if (is_array($tmp)) $data = $tmp;
    }
}

$fullName = isset($data['full_name']) ? trim((string)$data['full_name']) : '';
$company  = isset($data['company']) ? trim((string)$data['company']) : '';
$email    = isset($data['email']) ? trim((string)$data['email']) : '';
$website  = isset($data['website']) ? trim((string)$data['website']) : '';

if ($website !== '') {
    vip_json(array('success' => true, 'message' => 'Děkujeme. Váš účet bude aktivní do 24 hodin. Do emailu Vám přijde odkaz na vytvoření hesla.'), 200);
}

if ($fullName === '' || $company === '' || $email === '') {
    vip_json(array('success' => false, 'message' => 'Vyplňte prosím všechna pole.'), 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    vip_json(array('success' => false, 'message' => 'Zadejte prosím platný e-mail.'), 422);
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS vip_registrace_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        full_name VARCHAR(255) NOT NULL,
        company VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        ip_address VARCHAR(64) DEFAULT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        mail_sent TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_created_at (created_at),
        KEY idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $st = $pdo->prepare("INSERT INTO vip_registrace_requests
        (created_at, full_name, company, email, ip_address, user_agent, mail_sent)
        VALUES (NOW(), :full_name, :company, :email, :ip, :ua, 0)");
    $st->execute(array(
        ':full_name' => $fullName,
        ':company'   => $company,
        ':email'     => $email,
        ':ip'        => isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 64) : null,
        ':ua'        => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
    ));
    $requestId = (int)$pdo->lastInsertId();
} catch (Exception $e) {
    vip_json(array('success' => false, 'message' => 'Nepodařilo se uložit registraci.'), 500);
}

$mailCfg = array();
$existingCfg = @include __DIR__ . '/../../secrets/mail_smtp.php';
if (is_array($existingCfg)) {
    $mailCfg = $existingCfg;
}
$mailCfg['mail_to'] = 'obchod@c-store.cz';
$mailCfg['reply_to'] = 'obchod@c-store.cz';
$mailCfg['log_file'] = __DIR__ . '/logs/vip_mail.log';
if (empty($mailCfg['from_name'])) $mailCfg['from_name'] = 'C-Store.cz / VIP registrace';

$subject = 'Nová registrace zákazníka / Firemní,Oddílový | ' . $company . ' | ' . $fullName . ' | ' . $email;
$body = "VIP REGISTRACE\n"
      . "========================\n"
      . "Jméno a příjmení: " . $fullName . "\n"
      . "Firma / Oddíl: " . $company . "\n"
      . "E-mail: " . $email . "\n"
      . "Datum: " . date('d.m.Y H:i:s') . "\n"
      . "IP: " . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '') . "\n"
      . "ID záznamu: " . $requestId . "\n";

$mailOk = vip_send_mail($mailCfg, $subject, $body);

try {
    $pdo->prepare("UPDATE vip_registrace_requests SET mail_sent = :sent WHERE id = :id")
        ->execute(array(':sent' => $mailOk ? 1 : 0, ':id' => $requestId));
} catch (Exception $e) {
}

if (!$mailOk) {
    vip_json(array('success' => false, 'message' => 'Registrace se uložila, ale e-mail se nepodařilo odeslat. Zkontroluj /vip-api/logs/vip_mail.log'), 500);
}

vip_json(array(
    'success' => true,
    'message' => 'Děkujeme. Váš účet bude aktivní do 24 hodin. Do emailu Vám přijde odkaz na vytvoření hesla.'
), 200);
