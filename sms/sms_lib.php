<?php
// sms/sms_lib.php
// ver: 2026-01-27-SMS-001
// Jednoduchá SMS fronta (queued/sent/failed) – odeslání provádí Android appka přes sms_api/*

declare(strict_types=1);

function sms_settings_path(): string {
    return __DIR__ . '/sms_settings.json';
}

function sms_default_settings(): array {
    return [
        'enabled' => true,
        'test_mode' => true,
        'test_phone' => '+420604524524',
        'daily_limit' => 40,
        'api_token' => 'CHANGE_ME',
        'template' => 'Dobrý den, objednávka z C-Store.cz byla vyřízena. Číslo zásilky: {TRACKING}',
        'send_only_if_tracking' => true,
    ];
}

function sms_load_settings(): array {
    $p = sms_settings_path();
    $def = sms_default_settings();
    if (!is_file($p)) return $def;

    $raw = @file_get_contents($p);
    if ($raw === false || trim($raw) === '') return $def;

    $j = json_decode($raw, true);
    if (!is_array($j)) return $def;

    // merge defaults
    foreach ($def as $k => $v) {
        if (!array_key_exists($k, $j)) $j[$k] = $v;
    }

    // sanitize
    $j['enabled'] = !empty($j['enabled']);
    $j['test_mode'] = !empty($j['test_mode']);
    $j['daily_limit'] = max(1, (int)($j['daily_limit'] ?? 40));
    $j['api_token'] = trim((string)($j['api_token'] ?? ''));
    $j['template'] = trim((string)($j['template'] ?? $def['template']));
    $j['test_phone'] = sms_normalize_phone((string)($j['test_phone'] ?? $def['test_phone']));
    $j['send_only_if_tracking'] = !empty($j['send_only_if_tracking']);

    return $j;
}

function sms_save_settings(array $new): bool {
    $cur = sms_load_settings();
    $cur['enabled'] = !empty($new['enabled']);
    $cur['test_mode'] = !empty($new['test_mode']);
    $cur['daily_limit'] = max(1, (int)($new['daily_limit'] ?? $cur['daily_limit']));
    $oldToken = (string)$cur['api_token'];
    $cur['api_token'] = trim((string)($new['api_token'] ?? $cur['api_token']));
    if ($cur['api_token'] === '') $cur['api_token'] = $oldToken; // keep

    $cur['test_phone'] = sms_normalize_phone((string)($new['test_phone'] ?? $cur['test_phone']));
    if ($cur['test_phone'] === '') $cur['test_phone'] = sms_normalize_phone('+420604524524');

    $tpl = trim((string)($new['template'] ?? $cur['template']));
    if ($tpl !== '') $cur['template'] = $tpl;

    $cur['send_only_if_tracking'] = !empty($new['send_only_if_tracking']);

    $p = sms_settings_path();
    $json = json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) return false;

    return (@file_put_contents($p, $json) !== false);
}

function sms_normalize_phone(string $s): string {
    $s = trim($s);
    $s = str_replace([' ', "\t", "\n", "\r"], '', $s);
    if ($s === '') return '';
    // remove leading 00
    if (strpos($s, '00') === 0) $s = '+' . substr($s, 2);
    // keep + or digits
    $s = preg_replace('/(?!^\+)\D+/', '', $s);

    // if starts with 420... without plus
    if (preg_match('/^420\d{9}$/', $s)) return '+' . $s;
    // if 9 digits (CZ), add +420
    if (preg_match('/^\d{9}$/', $s)) return '+420' . $s;
    // if already +...
    if (preg_match('/^\+\d{9,15}$/', $s)) return $s;

    return $s; // best effort
}

function sms_require_pdo(?PDO $pdo): void {
    if (!$pdo) throw new RuntimeException('SMS: chybí $pdo.');
}

function sms_ensure_schema(PDO $pdo): void {
    // Bezpečně: pokud CREATE TABLE nejde (práva), nic nepadá – jen log.
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sms_queue (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                order_no VARCHAR(64) NOT NULL,
                carrier VARCHAR(32) NOT NULL,
                tracking VARCHAR(64) NOT NULL,
                phone_original VARCHAR(32) NULL,
                message TEXT NOT NULL,
                status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
                attempts INT NOT NULL DEFAULT 0,
                last_attempt_at DATETIME NULL,
                sent_at DATETIME NULL,
                last_to_phone VARCHAR(32) NULL,
                error TEXT NULL,
                UNIQUE KEY uniq_sms (order_no, carrier, tracking),
                KEY idx_status (status),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    
            // Migrace: přidání statusu 'sending' (pokud už je, ALTER vyhodí chybu a ignorujeme)
            try {
                $pdo->exec("ALTER TABLE sms_queue MODIFY status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued'");
            } catch (Throwable $e2) {
                // ignore
            }
} catch (Throwable $e) {
        error_log('SMS schema: ' . $e->getMessage());
    }
}

function sms_template_render(string $tpl, array $vars): string {
    $out = $tpl;
    foreach ($vars as $k => $v) {
        $out = str_replace('{' . strtoupper($k) . '}', (string)$v, $out);
        $out = str_replace('{' . strtolower($k) . '}', (string)$v, $out);
        $out = str_replace('{' . $k . '}', (string)$v, $out);
    }
    return $out;
}

function sms_once_dir(): string {
    return __DIR__ . '/sent_once';
}

function sms_once_key(string $orderNo, string $carrier): string {
    $raw = trim($orderNo) . '|' . trim($carrier);
    return sha1($raw);
}

function sms_once_path(string $orderNo, string $carrier): string {
    return sms_once_dir() . '/' . sms_once_key($orderNo, $carrier) . '.lock';
}

function sms_once_is_marked(string $orderNo, string $carrier): bool {
    return is_file(sms_once_path($orderNo, $carrier));
}

function sms_once_mark(string $orderNo, string $carrier): void {
    $dir = sms_once_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $path = sms_once_path($orderNo, $carrier);
    if (!is_file($path)) {
        @file_put_contents($path, date('Y-m-d H:i:s') . "\n", LOCK_EX);
    }
}

function sms_queue_enqueue(PDO $pdo, array $row): bool {
    sms_ensure_schema($pdo);

    $orderNo = (string)$row['order_no'];
    $carrier = (string)$row['carrier'];
    $tracking = (string)$row['tracking'];
    $phoneOriginal = (string)($row['phone_original'] ?? '');
    $message = (string)$row['message'];

    // Tvrdá pojistka: pro stejnou objednávku + dopravce už znovu nequeueuj SMS,
    // ani když dojde k reprintu nebo opakovanému načtení EAN v modulu.
    if (sms_once_is_marked($orderNo, $carrier)) {
        return true;
    }

    // Důležité: zabránit duplicitám i při souběžném generování štítků (např. 2 requesty téměř současně).
    // Best-effort MySQL advisory lock (když není k dispozici, pokračujeme bez něj).
    $lockKey = 'smsq:' . $orderNo . ':' . $carrier;
    $hasLock = false;
    try {
        $lk = $pdo->prepare("SELECT GET_LOCK(:k, 2) AS l");
        $lk->execute([':k' => $lockKey]);
        $lr = $lk->fetch(PDO::FETCH_ASSOC);
        $hasLock = ((int)($lr['l'] ?? 0) === 1);
    } catch (Throwable $eLock) {
        $hasLock = false;
    }
    try {

    // Jen 1 SMS na objednávku + dopravce:
    // - pokud už existuje queued/sending/sent, další nevkládej
    // - pokud existuje failed, "oživ" ji zpět do queued (retry)
    try {
        $chk = $pdo->prepare("SELECT id, status FROM sms_queue WHERE order_no=:o AND carrier=:c ORDER BY id DESC LIMIT 1");
        $chk->execute([':o' => $orderNo, ':c' => $carrier]);
        $ex = $chk->fetch(PDO::FETCH_ASSOC);

        if ($ex && isset($ex['status'])) {
            $st = (string)$ex['status'];
            $id = (int)$ex['id'];

            if ($st === 'sent') {
                sms_once_mark($orderNo, $carrier);
                return true; // už posláno → neduplikovat
            }

            if (in_array($st, ['queued','sending'], true)) {
                // Už existuje ve frontě: neduplikovat, ale u queued dovol aktualizovat tracking/text
                // (např. když se mezitím vygeneroval nový tracking).
                if ($st === 'queued') {
                    $upd = $pdo->prepare("UPDATE sms_queue
                                          SET tracking=:t,
                                              phone_original=:p,
                                              message=:m
                                          WHERE id=:id");
                    $upd->execute([':id'=>$id, ':t'=>$tracking, ':p'=>$phoneOriginal, ':m'=>$message]);
                }
                sms_once_mark($orderNo, $carrier);
                return true;
            }
            if ($st === 'failed') {
                // DŮLEŽITÉ: po jednom pokusu už stejnou SMS pro objednávku+dopravce znovu
                // nevracej do fronty. Tím zabráníme opětovnému odeslání při reprintu štítku.
                sms_once_mark($orderNo, $carrier);
                return true;
            }
        }
    } catch (Throwable $e) {
        // když kontrola selže, pokračuj INSERTem
    }

    // fallback insert
    $sql = "INSERT IGNORE INTO sms_queue (order_no, carrier, tracking, phone_original, message, status)
            VALUES (:order_no, :carrier, :tracking, :phone_original, :message, 'queued')";
    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute([
        ':order_no' => $orderNo,
        ':carrier' => $carrier,
        ':tracking' => $tracking,
        ':phone_original' => $phoneOriginal,
        ':message' => $message,
    ]);
    if ($ok) {
        sms_once_mark($orderNo, $carrier);
    }
    return $ok;

    } finally {
        if ($hasLock) {
            try {
                $rl = $pdo->prepare("SELECT RELEASE_LOCK(:k)");
                $rl->execute([':k' => $lockKey]);
            } catch (Throwable $eRel) {
                // ignore
            }
        }
    }
}

/**
 * Volá se z label.php po vytvoření štítku.
 * Potřebuje orderData: order_number, phone (+ tracking získáme z cache dle dopravce).
 */
function sms_enqueue_after_label(string $carrier, array $orderData): void {
    if (!function_exists('posta_cache_get_parcel_code')) return; // label.php bez pošty
    if (!function_exists('packeta_cache_get_packet_id')) return;

    global $pdo;
    if (!isset($pdo) || !$pdo) return;

    $s = sms_load_settings();
    if (empty($s['enabled'])) return;

    $orderNo = trim((string)($orderData['order_number'] ?? ''));
    if ($orderNo === '') return;

    $phone = trim((string)($orderData['phone'] ?? ''));
    $phone = sms_normalize_phone($phone);

    // tracking podle dopravce (cache)
    $tracking = '';
    $carrierKey = $carrier;
    if ($carrier === 'posta') {
        $tracking = trim((string)posta_cache_get_parcel_code($orderNo));
        $carrierKey = 'posta';
    } elseif ($carrier === 'packeta') {
        $tracking = trim((string)packeta_cache_get_packet_id($orderNo));
        $carrierKey = 'packeta';
    } elseif ($carrier === 'gls') {
        if (function_exists('gls_cache_get_parcel_number')) {
            $tracking = trim((string)gls_cache_get_parcel_number($orderNo));
        }
        $carrierKey = 'gls';
    } else {
        $carrierKey = (string)$carrier;
    }

    if ($tracking === '') {
        if (!empty($s['test_mode'])) {
            $tracking = 'NEZJIŠTĚNO';
        } else {
            if (!empty($s['send_only_if_tracking'])) return;
        }
    }

    if ($phone === '' && empty($s['test_mode'])) {
        return;
    }

    $tpl = (string)($s['template'] ?? '');
    if ($tpl === '') $tpl = sms_default_settings()['template'];

    $msg = sms_template_render($tpl, [
        'TRACKING' => $tracking,
        'ORDER' => $orderNo,
        'ORDER_NO' => $orderNo,
        'CARRIER' => strtoupper((string)$carrierKey),
    ]);

    sms_queue_enqueue($pdo, [
        'order_no' => $orderNo,
        'carrier' => (string)$carrierKey,
        'tracking' => $tracking,
        'phone_original' => $phone,
        'message' => $msg,
    ]);
}

function sms_api_require_auth(string $token): void {
    $s = sms_load_settings();
    $expected = (string)($s['api_token'] ?? '');
    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function sms_get_sent_today(PDO $pdo): int {
    sms_ensure_schema($pdo);
    try {
        $stmt = $pdo->query("SELECT COUNT(*) c FROM sms_queue WHERE status='sent' AND DATE(sent_at)=CURDATE()");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function sms_queue_next(PDO $pdo): ?array {
    sms_ensure_schema($pdo);

    // Atomicky si "rezervuj" 1 zprávu, aby se nemohla poslat víckrát při pollingu.
    // Poznámka: na některých hostinzích může být tabulka MyISAM (FOR UPDATE se ignoruje) nebo
    // může dojít k souběžným requestům z více zařízení. Proto po UPDATE vždy kontrolujeme rowCount()
    // a v případě "prohry závodu" vybereme další zprávu.

    $maxTries = 6;
    for ($try = 0; $try < $maxTries; $try++) {
        try {
            $pdo->beginTransaction();

            // Vyber 1 queued, který nebyl pokus o odeslání v posledních 24h (fallback lock)
            $stmt = $pdo->prepare("
                SELECT * FROM sms_queue
                WHERE status='queued'
                  AND (last_attempt_at IS NULL OR last_attempt_at < (NOW() - INTERVAL 24 HOUR))
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $pdo->commit();
                return null;
            }

            $id = (int)$row['id'];

            // Zkus přepnout stav na sending (nejlepší řešení proti duplicitám)
            try {
                $upd = $pdo->prepare("UPDATE sms_queue
                                      SET status='sending',
                                          attempts = attempts + 1,
                                          last_attempt_at = NOW()
                                      WHERE id=:id
                                        AND status='queued'
                                        AND (last_attempt_at IS NULL OR last_attempt_at < (NOW() - INTERVAL 24 HOUR))");
                $upd->execute([':id' => $id]);

                // Pokud mezitím někdo jiný zprávu claimnul, nic nevracej → zkus další
                if ((int)$upd->rowCount() !== 1) {
                    $pdo->rollBack();
                    continue;
                }

                $pdo->commit();

                $row['status'] = 'sending';
                $row['attempts'] = (int)($row['attempts'] ?? 0) + 1;
                $row['last_attempt_at'] = date('Y-m-d H:i:s');
                return $row;

            } catch (Throwable $eSending) {
                // Fallback (když ALTER ENUM nepovolil 'sending'): zamkni přes last_attempt_at a attempts
                $upd = $pdo->prepare("UPDATE sms_queue
                                      SET attempts = attempts + 1,
                                          last_attempt_at = NOW()
                                      WHERE id=:id
                                        AND status='queued'
                                        AND (last_attempt_at IS NULL OR last_attempt_at < (NOW() - INTERVAL 24 HOUR))");
                $upd->execute([':id' => $id]);

                if ((int)$upd->rowCount() !== 1) {
                    $pdo->rollBack();
                    continue;
                }

                $pdo->commit();

                $row['attempts'] = (int)($row['attempts'] ?? 0) + 1;
                $row['last_attempt_at'] = date('Y-m-d H:i:s');
                return $row;
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('SMS queue_next error: ' . $e->getMessage());
            return null;
        }
    }

    return null;
}

function sms_queue_mark_attempt(PDO $pdo, int $id, string $toPhone): void {
    sms_ensure_schema($pdo);
    // POZOR: attempts už zvyšujeme v sms_queue_next() při claimu. Tady jen uložíme cílové číslo.
    $stmt = $pdo->prepare("UPDATE sms_queue SET last_to_phone=:to WHERE id=:id");
    $stmt->execute([':id' => $id, ':to' => $toPhone]);
}

function sms_queue_report(PDO $pdo, int $id, string $status, string $error = ''): void {
    sms_ensure_schema($pdo);
    if ($status === 'sent') {
        $stmt = $pdo->prepare("UPDATE sms_queue SET status='sent', sent_at=NOW(), error=NULL WHERE id=:id");
        $stmt->execute([':id' => $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE sms_queue SET status='failed', error=:err WHERE id=:id");
        $stmt->execute([':id' => $id, ':err' => $error]);
    }
}
