<?php
declare(strict_types=1);

function vip_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfgFile = __DIR__ . '/config.php';
        $cfg = file_exists($cfgFile) ? (include $cfgFile) : [];
        if (!is_array($cfg)) $cfg = [];
    }
    return $cfg;
}

function vip_allowed_origin(): ?string {
    $cfg = vip_config();
    $allowed = $cfg['allowed_origins'] ?? [];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin && in_array($origin, $allowed, true)) {
        return $origin;
    }
    return null;
}

function vip_send_cors_headers(): void {
    $origin = vip_allowed_origin();
    if ($origin) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
}

function vip_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function vip_log(string $file, string $message): void {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

function vip_pdo(): PDO {
    $cfg = vip_config();
    $db = $cfg['db'] ?? [];
    $host = (string)($db['host'] ?? '');
    $name = (string)($db['name'] ?? '');
    $user = (string)($db['user'] ?? '');
    $pass = (string)($db['pass'] ?? '');
    $charset = (string)($db['charset'] ?? 'utf8mb4');

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function vip_smtp_read($fp): string {
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) break;
        $data .= $line;
        if (preg_match('/^\d{3}\s/', $line)) break;
    }
    return $data;
}
function vip_smtp_write($fp, string $cmd): void { fwrite($fp, $cmd . "\r\n"); }
function vip_smtp_expect(string $resp, int $code): bool {
    return (bool)preg_match('/^' . preg_quote((string)$code, '/') . '/m', $resp);
}

function vip_send_mail(string $to, string $subject, string $body): bool {
    $cfg = vip_config();
    $mail = $cfg['mail'] ?? [];
    $logFile = (string)($mail['log_file'] ?? (__DIR__ . '/logs/vip_mail.log'));

    $hosts = $mail['smtp_hosts'] ?? [];
    $tries = $mail['smtp_tries'] ?? [];
    if (!is_array($hosts) || !$hosts || !is_array($tries) || !$tries) {
        vip_log($logFile, 'SMTP config chybí.');
        return false;
    }

    foreach ($hosts as $host) {
        foreach ($tries as $try) {
            $ok = vip_send_mail_smtp_once(
                (string)$host,
                (int)($try['port'] ?? 587),
                (string)($try['secure'] ?? 'tls'),
                $to,
                $subject,
                $body,
                $mail,
                $logFile
            );
            if ($ok) {
                return true;
            }
        }
    }

    // fallback na php mail()
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'From: ' . ($mail['from_name'] ?? 'C-Store.cz') . ' <' . ($mail['from_email'] ?? 'info@cfloat.cz') . '>';
    $headers[] = 'Reply-To: ' . ($mail['reply_to'] ?? 'obchod@c-store.cz');
    if (!empty($mail['bcc'])) {
        $headers[] = 'Bcc: ' . $mail['bcc'];
    }
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
    vip_log($logFile, 'Fallback php mail(): ' . ($ok ? 'OK' : 'FAIL'));
    return $ok;
}

function vip_send_mail_smtp_once(
    string $host,
    int $port,
    string $secure,
    string $to,
    string $subject,
    string $body,
    array $mail,
    string $logFile
): bool {
    $user = (string)($mail['smtp_user'] ?? '');
    $pass = (string)($mail['smtp_pass'] ?? '');
    $fromEmail = (string)($mail['from_email'] ?? '');
    $fromName = (string)($mail['from_name'] ?? 'C-Store.cz / VIP registrace');
    $replyTo = (string)($mail['reply_to'] ?? $fromEmail);
    $bcc = (string)($mail['bcc'] ?? '');

    if ($host === '' || $user === '' || $pass === '' || $fromEmail === '') {
        vip_log($logFile, "SMTP chybí host/user/pass/from");
        return false;
    }

    $remote = ($secure === 'ssl') ? ("ssl://{$host}:{$port}") : ("{$host}:{$port}");
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        vip_log($logFile, "SMTP connect FAIL {$remote} errno={$errno} err={$errstr}");
        return false;
    }

    stream_set_timeout($fp, 20);
    $resp = vip_smtp_read($fp);
    if (!vip_smtp_expect($resp, 220)) {
        vip_log($logFile, "SMTP greet FAIL {$remote} | " . trim($resp));
        fclose($fp);
        return false;
    }

    $hn = $_SERVER['HTTP_HOST'] ?? 'localhost';
    vip_smtp_write($fp, 'EHLO ' . $hn);
    $resp = vip_smtp_read($fp);
    if (!vip_smtp_expect($resp, 250)) {
        vip_smtp_write($fp, 'HELO ' . $hn);
        $resp = vip_smtp_read($fp);
        if (!vip_smtp_expect($resp, 250)) {
            vip_log($logFile, "SMTP EHLO/HELO FAIL {$remote} | " . trim($resp));
            fclose($fp);
            return false;
        }
    }

    if ($secure === 'tls') {
        vip_smtp_write($fp, 'STARTTLS');
        $resp = vip_smtp_read($fp);
        if (!vip_smtp_expect($resp, 220)) {
            vip_log($logFile, "SMTP STARTTLS FAIL {$remote} | " . trim($resp));
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
            vip_log($logFile, "SMTP TLS crypto FAIL {$remote}");
            fclose($fp);
            return false;
        }

        vip_smtp_write($fp, 'EHLO ' . $hn);
        $resp = vip_smtp_read($fp);
        if (!vip_smtp_expect($resp, 250)) {
            vip_log($logFile, "SMTP EHLO after TLS FAIL {$remote} | " . trim($resp));
            fclose($fp);
            return false;
        }
    }

    vip_smtp_write($fp, 'AUTH LOGIN');
    $resp = vip_smtp_read($fp);
    if (!vip_smtp_expect($resp, 334)) {
        vip_log($logFile, "SMTP AUTH LOGIN FAIL {$remote} | " . trim($resp));
        fclose($fp);
        return false;
    }

    vip_smtp_write($fp, base64_encode($user));
    $resp = vip_smtp_read($fp);
    if (!vip_smtp_expect($resp, 334)) {
        vip_log($logFile, "SMTP USER FAIL {$remote} | " . trim($resp));
        fclose($fp);
        return false;
    }

    vip_smtp_write($fp, base64_encode($pass));
    $resp = vip_smtp_read($fp);
    if (!vip_smtp_expect($resp, 235)) {
        vip_log($logFile, "SMTP PASS FAIL {$remote} | " . trim($resp));
        fclose($fp);
        return false;
    }

    vip_smtp_write($fp, 'MAIL FROM:<' . $fromEmail . '>');
    $resp = vip_smtp_read($fp);
    if (!vip_smtp_expect($resp, 250)) {
        vip_log($logFile, "SMTP MAIL FROM FAIL {$remote} | " . trim($resp));
        fclose($fp);
        return false;
    }

    vip_smtp_write($fp, 'RCPT TO:<' . $to . '>');
    $resp = vip_smtp_read($fp);
    if (!vip_smtp_expect($resp, 250) && !vip_smtp_expect($resp, 251)) {
        vip_log($logFile, "SMTP RCPT TO FAIL {$remote} | " . trim($resp));
        fclose($fp);
        return false;
    }

    if ($bcc !== '') {
        vip_smtp_write($fp, 'RCPT TO:<' . $bcc . '>');
        $resp = vip_smtp_read($fp);
        if (!vip_smtp_expect($resp, 250) && !vip_smtp_expect($resp, 251)) {
            vip_log($logFile, "SMTP BCC FAIL {$remote} | " . trim($resp));
        }
    }

    vip_smtp_write($fp, 'DATA');
    $resp = vip_smtp_read($fp);
    if (!vip_smtp_expect($resp, 354)) {
        vip_log($logFile, "SMTP DATA FAIL {$remote} | " . trim($resp));
        fclose($fp);
        return false;
    }

    $headers = [];
    $headers[] = 'Date: ' . date(DATE_RFC2822);
    $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Reply-To: ' . $replyTo;
    if ($bcc !== '') {
        $headers[] = 'Bcc: ' . $bcc;
    }
    $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    $bodySafe = preg_replace("/(?m)^\./", '..', str_replace(["\r\n", "\r"], "\n", $body));
    $payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $bodySafe) . "\r\n.";
    vip_smtp_write($fp, $payload);

    $resp = vip_smtp_read($fp);
    if (!vip_smtp_expect($resp, 250)) {
        vip_log($logFile, "SMTP SEND FAIL {$remote} | " . trim($resp));
        fclose($fp);
        return false;
    }

    vip_smtp_write($fp, 'QUIT');
    @fclose($fp);
    vip_log($logFile, "SMTP OK {$remote}");
    return true;
}
