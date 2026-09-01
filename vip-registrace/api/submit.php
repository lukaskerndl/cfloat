<?php
declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

$MAILCFG = @include __DIR__ . '/../config.mail.php';
if (!is_array($MAILCFG)) $MAILCFG = [];
$MAILCFG['mail_to'] = 'obchod@c-store.cz';

function vipLog_(string $file, string $msg): void {
  @is_dir(dirname($file)) || @mkdir(dirname($file), 0775, true);
  @file_put_contents($file, '['.date('Y-m-d H:i:s').'] '.$msg."
", FILE_APPEND);
}
function smtpRead_($fp){
  $data = '';
  while (!feof($fp)) {
    $line = fgets($fp, 515);
    if ($line === false) break;
    $data .= $line;
    if (preg_match('/^\d{3}\s/', $line)) break;
  }
  return $data;
}
function smtpWrite_($fp, string $cmd): void { fwrite($fp, $cmd . "
"); }
function smtpExpectCode_($resp, $code): bool { return (bool)preg_match('/^'.preg_quote((string)$code,'/').'/m', $resp); }

function smtpSendMail_($cfg, string $to, string $subject, string $body, $hostOverride=null, $portOverride=null, $secureOverride=null): bool {
  $host   = (string)($hostOverride ?? ($cfg['smtp_host'] ?? ''));
  $port   = (int)($portOverride ?? ($cfg['smtp_port'] ?? 587));
  $secure = strtolower((string)($secureOverride ?? ($cfg['smtp_secure'] ?? 'tls')));
  $user   = (string)($cfg['smtp_user'] ?? '');
  $pass   = (string)($cfg['smtp_pass'] ?? '');
  $fromEmail = (string)($cfg['from_email'] ?? $user);
  $fromName  = (string)($cfg['from_name'] ?? '');
  $replyTo   = (string)($cfg['reply_to'] ?? '');
  $logFile   = (string)($cfg['log_file'] ?? (__DIR__.'/../logs/vip_mail.log'));
  $ctx = 'host='.$host.' port='.$port.' sec='.$secure;

  if ($host === '' || $user === '' || $pass === '' || $fromEmail === '') {
    vipLog_($logFile, 'SMTP: missing config '.$ctx);
    return false;
  }

  $remote = ($secure === 'ssl') ? ('ssl://'.$host.':'.$port) : ($host.':'.$port);
  $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
  if (!$fp) { vipLog_($logFile, 'SMTP: connect fail '.$remote.' errno='.$errno.' err='.$errstr.' '.$ctx); return false; }
  stream_set_timeout($fp, 20);

  $r = smtpRead_($fp);
  if (!smtpExpectCode_($r, 220)) { vipLog_($logFile, 'SMTP: no 220 greet '.trim($r).' '.$ctx); fclose($fp); return false; }

  $hn = $_SERVER['HTTP_HOST'] ?? 'localhost';
  smtpWrite_($fp, 'EHLO '.$hn);
  $r = smtpRead_($fp);
  if (!smtpExpectCode_($r, 250)) {
    smtpWrite_($fp, 'HELO '.$hn);
    $r = smtpRead_($fp);
    if (!smtpExpectCode_($r, 250)) { vipLog_($logFile, 'SMTP: EHLO/HELO fail '.trim($r).' '.$ctx); fclose($fp); return false; }
  }

  if ($secure === 'tls') {
    smtpWrite_($fp, 'STARTTLS');
    $r = smtpRead_($fp);
    if (!smtpExpectCode_($r, 220)) { vipLog_($logFile, 'SMTP: STARTTLS fail '.trim($r).' '.$ctx); fclose($fp); return false; }
    $method = 0;
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
    if ($method === 0 && defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')) $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
    $cryptoOk = @stream_socket_enable_crypto($fp, true, $method);
    if (!$cryptoOk) { vipLog_($logFile, 'SMTP: TLS crypto fail '.$ctx); fclose($fp); return false; }
    smtpWrite_($fp, 'EHLO '.$hn);
    $r = smtpRead_($fp);
    if (!smtpExpectCode_($r, 250)) { vipLog_($logFile, 'SMTP: EHLO after TLS fail '.trim($r).' '.$ctx); fclose($fp); return false; }
  }

  smtpWrite_($fp, 'AUTH LOGIN');
  $r = smtpRead_($fp);
  if (!smtpExpectCode_($r, 334)) { vipLog_($logFile, 'SMTP: AUTH LOGIN not accepted '.trim($r).' '.$ctx); fclose($fp); return false; }
  smtpWrite_($fp, base64_encode($user));
  $r = smtpRead_($fp);
  if (!smtpExpectCode_($r, 334)) { vipLog_($logFile, 'SMTP: user not accepted '.trim($r).' '.$ctx); fclose($fp); return false; }
  smtpWrite_($fp, base64_encode($pass));
  $r = smtpRead_($fp);
  if (!smtpExpectCode_($r, 235)) { vipLog_($logFile, 'SMTP: pass not accepted '.trim($r).' '.$ctx); fclose($fp); return false; }

  smtpWrite_($fp, 'MAIL FROM:<'.$fromEmail.'>');
  $r = smtpRead_($fp);
  if (!smtpExpectCode_($r, 250)) { vipLog_($logFile, 'SMTP: MAIL FROM fail '.trim($r).' '.$ctx); fclose($fp); return false; }

  $rcptList = [$to];
  $bcc = trim((string)($cfg['mail_bcc'] ?? ''));
  if ($bcc !== '' && filter_var($bcc, FILTER_VALIDATE_EMAIL)) $rcptList[] = $bcc;
  foreach ($rcptList as $rcpt) {
    smtpWrite_($fp, 'RCPT TO:<'.$rcpt.'>');
    $r = smtpRead_($fp);
    if (!smtpExpectCode_($r, 250) && !smtpExpectCode_($r, 251)) {
      vipLog_($logFile, 'SMTP: RCPT TO fail rcpt='.$rcpt.' resp='.trim($r).' '.$ctx);
      fclose($fp);
      return false;
    }
  }

  smtpWrite_($fp, 'DATA');
  $r = smtpRead_($fp);
  if (!smtpExpectCode_($r, 354)) { vipLog_($logFile, 'SMTP: DATA fail '.trim($r).' '.$ctx); fclose($fp); return false; }

  $encodedSubject = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8', 'B') : $subject;
  $fromHeader = ($fromName !== '') ? ($fromName.' <'.$fromEmail.'>') : $fromEmail;
  $headers = [];
  $headers[] = 'From: '.$fromHeader;
  if ($replyTo !== '') $headers[] = 'Reply-To: '.$replyTo;
  $headers[] = 'To: <'.$to.'>';
  $headers[] = 'Subject: '.$encodedSubject;
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: text/plain; charset=UTF-8';
  $headers[] = 'Content-Transfer-Encoding: 8bit';
  $msg = implode("
", $headers) . "

" . $body;
  $msg = preg_replace("/
\./", "
..", $msg);

  smtpWrite_($fp, $msg . "
.");
  $r = smtpRead_($fp);
  if (!smtpExpectCode_($r, 250)) { vipLog_($logFile, 'SMTP: send fail '.trim($r).' '.$ctx); fclose($fp); return false; }

  smtpWrite_($fp, 'QUIT');
  fclose($fp);
  vipLog_($logFile, 'SMTP: OK host='.$host.' port='.$port.' sec='.$secure.' to='.$to.' subj='.$subject);
  return true;
}

function sendVipEmail($cfg, string $subject, string $body): bool {
  $to = (string)($cfg['mail_to'] ?? 'obchod@c-store.cz');
  $logFile = (string)($cfg['log_file'] ?? (__DIR__.'/../logs/vip_mail.log'));
  $hosts = $cfg['smtp_hosts'] ?? null;
  if (!is_array($hosts) || !count($hosts)) {
    $one = (string)($cfg['smtp_host'] ?? '');
    $hosts = $one ? [$one] : [];
  }
  $tries = $cfg['smtp_tries'] ?? null;
  if (!is_array($tries) || !count($tries)) {
    $tries = [
      ['secure' => 'tls', 'port' => 587],
      ['secure' => 'ssl', 'port' => 465],
    ];
  }

  foreach ($hosts as $h) {
    $h = trim((string)$h);
    if ($h === '') continue;
    foreach ($tries as $t) {
      $sec = strtolower((string)($t['secure'] ?? 'tls'));
      $prt = (int)($t['port'] ?? 587);
      if (smtpSendMail_($cfg, $to, $subject, $body, $h, $prt, $sec)) return true;
    }
  }

  $fromEmail = (string)($cfg['from_email'] ?? '');
  $fromName  = (string)($cfg['from_name'] ?? '');
  $replyTo   = (string)($cfg['reply_to'] ?? '');
  $headers = [];
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: text/plain; charset=UTF-8';
  if ($fromEmail !== '') {
    $headers[] = 'From: ' . ($fromName ? ($fromName.' <'.$fromEmail.'>') : $fromEmail);
    if ($replyTo) $headers[] = 'Reply-To: ' . $replyTo;
  }
  $subj = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8', 'B') : $subject;
  $ok = false;
  if ($fromEmail !== '') {
    $ok = @mail($to, $subj, $body, implode("
", $headers), '-f '.$fromEmail);
  } else {
    $ok = @mail($to, $subj, $body, implode("
", $headers));
  }
  vipLog_($logFile, 'Fallback mail() '.($ok ? 'OK' : 'FAILED').' to='.$to.' subj='.$subject);
  return $ok;
}

function jsonOut(array $arr, int $code = 200): void {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  require __DIR__ . '/../../config.php';
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    jsonOut(['success' => false, 'message' => 'Chybí PDO ($pdo) v config.php.'], 500);
  }

  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonOut(['success' => false, 'message' => 'Neplatný požadavek.'], 405);
  }

  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  if (!is_array($data)) jsonOut(['success' => false, 'message' => 'Neplatný JSON.'], 400);

  $fullName    = trim((string)($data['fullName'] ?? ''));
  $companyName = trim((string)($data['companyName'] ?? ''));
  $email       = trim((string)($data['email'] ?? ''));

  if ($fullName === '' || $companyName === '' || $email === '') {
    jsonOut(['success' => false, 'message' => 'Vyplňte prosím všechna pole.'], 400);
  }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonOut(['success' => false, 'message' => 'Zadejte prosím platný e-mail.'], 400);
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS vip_registrace_requests (
    id INT NOT NULL AUTO_INCREMENT,
    status ENUM('NEW','PROCESSED') NOT NULL DEFAULT 'NEW',
    full_name VARCHAR(255) NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    email_sent TINYINT(1) NOT NULL DEFAULT 0,
    email_sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_created_at (created_at),
    KEY idx_status (status),
    KEY idx_email (email)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
  $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

  $st = $pdo->prepare("INSERT INTO vip_registrace_requests
    (status, full_name, company_name, email, ip_address, user_agent, email_sent, created_at, updated_at)
    VALUES
    ('NEW', :full_name, :company_name, :email, :ip_address, :user_agent, 0, NOW(), NOW())");
  $st->execute([
    ':full_name' => $fullName,
    ':company_name' => $companyName,
    ':email' => $email,
    ':ip_address' => $ip,
    ':user_agent' => $ua,
  ]);
  $requestId = (int)$pdo->lastInsertId();

  $subject = 'Nová registrace zákazníka /Firemní,Oddílový | ' . $companyName . ' | ' . $fullName . ' | ' . $email;
  $body = "VIP REGISTRACE
"
        . "==============================
"
        . "Jméno a příjmení: " . $fullName . "
"
        . "Firma / Oddíl: " . $companyName . "
"
        . "E-mail: " . $email . "
"
        . "
ID záznamu: " . $requestId . "
"
        . "Odesláno: " . date('d.m.Y H:i:s') . "
"
        . "IP: " . $ip . "
";

  $mailOk = sendVipEmail($MAILCFG, $subject, $body);
  if ($mailOk) {
    $stU = $pdo->prepare("UPDATE vip_registrace_requests SET email_sent = 1, email_sent_at = NOW() WHERE id = :id LIMIT 1");
    $stU->execute([':id' => $requestId]);
  } else {
    jsonOut(['success' => false, 'message' => 'Registrace byla uložena, ale nepodařilo se odeslat e-mail. Zkontroluj SMTP nastavení.'], 500);
  }

  jsonOut([
    'success' => true,
    'message' => 'Děkujeme. Váš účet bude aktivní do 24 hodin. Do emailu Vám přijde odkaz na vytvoření hesla.',
    'id' => $requestId
  ]);

} catch (Throwable $e) {
  jsonOut(['success' => false, 'message' => 'Chyba serveru: ' . $e->getMessage()], 500);
}
