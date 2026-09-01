<?php
/**
 * Cfloat MAIL – full-screen modul bez MySQL CREATE práv.
 * Úložiště je v /www/modules/mail/data/*.json, takže nevyžaduje vytváření DB tabulek.
 */
if (session_status() === PHP_SESSION_NONE) @session_start();
if (!function_exists('h')) { function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

const CFMAIL_VERSION = '2026-06-26-full-4';
$cfMailRoot = __DIR__;
$cfMailData = $cfMailRoot . '/data';
$cfMailLogs = $cfMailRoot . '/logs';
@mkdir($cfMailData, 0775, true);
@mkdir($cfMailLogs, 0775, true);
if (!is_file($cfMailData . '/.htaccess')) @file_put_contents($cfMailData . '/.htaccess', "Deny from all\n");
if (!is_file($cfMailLogs . '/.htaccess')) @file_put_contents($cfMailLogs . '/.htaccess', "Deny from all\n");

$cfMailUsers = [
    'sarka' => ['name' => 'Šárka', 'initial' => 'Š', 'color' => '#8b5cf6', 'signature' => "Šárka | C-Store.cz"],
    'lukas' => ['name' => 'Lukáš', 'initial' => 'L', 'color' => '#2563eb', 'signature' => "Lukáš | C-Store.cz"],
    'kamil' => ['name' => 'Kamil', 'initial' => 'K', 'color' => '#16a34a', 'signature' => "Kamil | C-Store.cz"],
];

$cfMailDefaultSettings = [
    'account_email' => 'obchod@c-store.cz',
    'imap_host' => 'imap.golemos.com',
    'imap_port' => 993,
    'imap_flags' => '/imap/ssl',
    'imap_user' => 'obchod@c-store.cz',
    'imap_pass' => '',
    'smtp_host' => 'smtpc.golemos.com',
    'smtp_port' => 587,
    'smtp_secure' => 'tls',
    'smtp_user' => 'obchod@c-store.cz',
    'smtp_pass' => '',
    'from_name' => 'C-Store.cz',
    'reply_to' => 'obchod@c-store.cz',
    'sync_days' => 7,
    'sync_limit' => 180,
    'folders' => [
        'inbox' => 'INBOX',
        'sent' => 'Sent',
        'done' => 'Cfloat/Vyrizene',
        'accounting_review' => 'Cfloat/Faktury_ke_kontrole',
        'accounting_approved' => 'Cfloat/Faktury_pro_ucetni',
        'trash' => 'Trash',
    ],
    'invoice_keywords' => 'faktura,faktury,invoice,inovice,daňový doklad,danovy doklad,doklad,účtenka,uctenka,receipt,proforma,zálohová faktura,zalohova faktura,vyúčtování,vyuctovani',
];

$cfMailTemplates = [
    'return_link' => [
        'label' => 'Vrácení zboží – odkaz na formulář',
        'text' => "Dobrý den,\n\npro vrácení zboží prosím vyplňte náš formulář zde:\n\nhttps://www.c-store.cz/odstoupeni-od-smlouvy\n\nPo vyplnění formuláře budeme mít všechny potřebné údaje k vyřízení vrácení.\n\nS pozdravem\n{signature}"
    ],
    'complaint' => [
        'label' => 'Reklamace – formulář a adresa',
        'text' => "Dobrý den,\n\npro vyřízení reklamace prosím vyplňte reklamační formulář zde:\n\nhttps://www.c-store.cz/REKLAMACE-a10_33.htm\n\nZboží prosím odešlete na naši adresu:\n\nC-Store.cz\nHrotovická 1202\nTřebíč\n\nPo doručení zboží a formuláře reklamaci zaevidujeme a budeme vás informovat o dalším postupu.\n\nS pozdravem\n{signature}"
    ],
    'exchange' => [
        'label' => 'Výměna zboží',
        'text' => "Dobrý den,\n\nvýměnu zboží můžeme provést.\n\nZboží prosím odešlete na naši adresu:\n\nC-Store.cz\nHrotovická 1202\nTřebíč\n\nDo balíčku prosím přiložte číslo objednávky a informaci, za jakou velikost nebo produkt chcete zboží vyměnit.\n\nS pozdravem\n{signature}"
    ],
    'missing_order' => [
        'label' => 'Chybí číslo objednávky',
        'text' => "Dobrý den,\n\nprosíme o zaslání čísla objednávky, abychom mohli požadavek dohledat a rychle vyřídit.\n\nS pozdravem\n{signature}"
    ],
    'photo_request' => [
        'label' => 'Požadavek na fotku vady',
        'text' => "Dobrý den,\n\nprosíme o zaslání fotografií vady a případně také fotografii štítku zboží. Jakmile podklady obdržíme, reklamaci prověříme.\n\nS pozdravem\n{signature}"
    ],
    'wait_supplier' => [
        'label' => 'Čekáme na dodavatele',
        'text' => "Dobrý den,\n\npožadavek jsme předali k ověření dodavateli. Jakmile budeme mít vyjádření, budeme vás informovat.\n\nS pozdravem\n{signature}"
    ],
];

function cfmail_file(string $name): string { return __DIR__ . '/data/' . $name . '.json'; }
function cfmail_log_file(): string { return __DIR__ . '/logs/mail.log'; }
function cfmail_now(): string { return date('Y-m-d H:i:s'); }
function cfmail_log(string $msg): void { @file_put_contents(cfmail_log_file(), '[' . cfmail_now() . '] ' . $msg . "\n", FILE_APPEND); }
function cfmail_load_json(string $name, $default) {
    $file = cfmail_file($name);
    if (!is_file($file)) return $default;
    $raw = @file_get_contents($file);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : $default;
}
function cfmail_save_json(string $name, $data): bool {
    $file = cfmail_file($name);
    @mkdir(dirname($file), 0775, true);
    $tmp = $file . '.tmp';
    $ok = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    if ($ok === false) return false;
    return @rename($tmp, $file);
}
function cfmail_next_id(string $key): int {
    $c = cfmail_load_json('counters', []);
    $n = (int)($c[$key] ?? 0) + 1;
    $c[$key] = $n;
    cfmail_save_json('counters', $c);
    return $n;
}
function cfmail_load_settings(array $defaults): array {
    $data = cfmail_load_json('settings', []);
    return array_replace_recursive($defaults, is_array($data) ? $data : []);
}
function cfmail_save_settings(array $settings): bool { return cfmail_save_json('settings', $settings); }
function cfmail_load_threads(): array { return cfmail_load_json('threads', []); }
function cfmail_save_threads(array $x): bool { return cfmail_save_json('threads', $x); }
function cfmail_load_messages(): array { return cfmail_load_json('messages', []); }
function cfmail_save_messages(array $x): bool { return cfmail_save_json('messages', $x); }
function cfmail_load_notes(): array { return cfmail_load_json('notes', []); }
function cfmail_save_notes(array $x): bool { return cfmail_save_json('notes', $x); }
function cfmail_load_actions(): array { return cfmail_load_json('actions', []); }
function cfmail_save_actions(array $x): bool { return cfmail_save_json('actions', $x); }

function cfmail_load_contacts(): array { return cfmail_load_json('contacts', []); }
function cfmail_save_contacts(array $x): bool { return cfmail_save_json('contacts', $x); }
function cfmail_load_signatures(): array { return cfmail_load_json('signatures', []); }
function cfmail_save_signatures(array $x): bool { return cfmail_save_json('signatures', $x); }
function cfmail_signature_key(?array $worker): string { return $worker && !empty($worker['key']) ? (string)$worker['key'] : 'default'; }
function cfmail_default_signature_text(string $key, ?array $worker = null): string {
    if ($worker && !empty($worker['signature'])) return (string)$worker['signature'];
    return 'C-Store.cz';
}
function cfmail_signature_for(string $key, ?array $worker = null): array {
    $all = cfmail_load_signatures();
    $sig = is_array($all[$key] ?? null) ? $all[$key] : [];
    return [
        'key' => $key,
        'text' => (string)($sig['text'] ?? cfmail_default_signature_text($key, $worker)),
        'image_file' => (string)($sig['image_file'] ?? ''),
        'image_url' => (string)($sig['image_url'] ?? ''),
    ];
}
function cfmail_public_url(array $params = []): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'cfloat.cz');
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    return ($https ? 'https://' : 'http://') . $host . $script . ($params ? '?' . http_build_query($params) : '');
}
function cfmail_signature_image_url(array $sig): string {
    $url = trim((string)($sig['image_url'] ?? ''));
    if ($url !== '') return $url;
    $file = trim((string)($sig['image_file'] ?? ''));
    if ($file === '') return '';
    return cfmail_public_url(['view'=>'mail','cfmail_sig_image'=>$sig['key'] ?? 'default']);
}
function cfmail_ensure_signature_text(string $body, array $sig): string {
    $text = trim((string)($sig['text'] ?? ''));
    if ($text === '') return $body;
    if (stripos($body, $text) !== false) return $body;
    return rtrim($body) . "\n\n" . $text;
}
function cfmail_text_to_html(string $text): string { return nl2br(h($text), false); }
function cfmail_serve_signature_image(): void {
    $key = preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['cfmail_sig_image'] ?? ''));
    if ($key === '') { http_response_code(404); echo 'nenalezeno'; return; }
    $sig = cfmail_signature_for($key, null);
    $file = basename((string)($sig['image_file'] ?? ''));
    $path = __DIR__ . '/data/' . $file;
    if ($file === '' || !is_file($path)) { http_response_code(404); echo 'nenalezeno'; return; }
    $mime = 'image/png';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'jpg' || $ext === 'jpeg') $mime='image/jpeg';
    elseif ($ext === 'gif') $mime='image/gif';
    elseif ($ext === 'webp') $mime='image/webp';
    while (ob_get_level()) @ob_end_clean();
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($path));
    readfile($path);
}
function cfmail_clean_email(string $email): string {
    $email = trim($email);
    if (preg_match('/<([^>]+)>/', $email, $m)) $email = trim($m[1]);
    $email = trim($email, " \t\r\n\0\x0B,;<>\"'");
    return strtolower($email);
}
function cfmail_split_emails(string $value): array {
    $out = [];
    foreach (preg_split('/[,;]+/', $value) ?: [] as $part) {
        $email = cfmail_clean_email($part);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $out[$email] = $email;
    }
    return array_values($out);
}
function cfmail_contact_label(array $c): string {
    $name = trim((string)($c['first_name'] ?? '') . ' ' . (string)($c['last_name'] ?? ''));
    $email = (string)($c['email'] ?? '');
    return $name !== '' ? $name . ' <' . $email . '>' : $email;
}
function cfmail_sorted_contacts(): array {
    $contacts = cfmail_load_contacts();
    usort($contacts, function($a,$b){
        $an = trim((string)($a['first_name'] ?? '') . ' ' . (string)($a['last_name'] ?? ''));
        $bn = trim((string)($b['first_name'] ?? '') . ' ' . (string)($b['last_name'] ?? ''));
        if ($an === '') $an = (string)($a['email'] ?? '');
        if ($bn === '') $bn = (string)($b['email'] ?? '');
        return strcasecmp($an, $bn);
    });
    return $contacts;
}
function cfmail_frequent_contacts(int $limit = 8): array {
    $counts = [];
    $names = [];
    foreach (cfmail_load_messages() as $m) {
        $dir = (string)($m['direction'] ?? 'in');
        $field = $dir === 'out' ? (string)($m['to_email'] ?? '') : (string)($m['from_email'] ?? '');
        foreach (cfmail_split_emails($field) as $email) {
            if ($email === '' || strcasecmp($email, (string)($m['account_email'] ?? '')) === 0) continue;
            $counts[$email] = ($counts[$email] ?? 0) + ($dir === 'out' ? 3 : 1);
            if (empty($names[$email])) $names[$email] = trim((string)($m['from_name'] ?? ''));
        }
    }
    arsort($counts);
    $out=[];
    foreach ($counts as $email=>$count) {
        $name = trim((string)($names[$email] ?? ''));
        $label = $name !== '' ? $name . ' <' . $email . '>' : $email;
        $out[] = ['email'=>$email,'label'=>$label,'source'=>'frequent','count'=>(int)$count,'name'=>$name];
        if (count($out) >= $limit) break;
    }
    return $out;
}
function cfmail_contact_suggestions(int $limit = 120): array {
    $out = [];
    $used = [];
    foreach (cfmail_frequent_contacts(8) as $s) { $out[]=$s; $used[$s['email']]=true; }
    foreach (cfmail_sorted_contacts() as $c) {
        $email = cfmail_clean_email((string)($c['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($used[$email])) continue;
        $c['email'] = $email;
        $out[] = ['email'=>$email, 'label'=>cfmail_contact_label($c), 'source'=>'contact', 'count'=>0, 'name'=>trim((string)($c['first_name'] ?? '') . ' ' . (string)($c['last_name'] ?? ''))];
        $used[$email]=true;
        if (count($out) >= $limit) break;
    }
    return $out;
}

function cfmail_worker(): ?array {
    global $cfMailUsers;
    $key = $_SESSION['cfmail_worker'] ?? '';
    if ($key !== '' && isset($cfMailUsers[$key])) { $u = $cfMailUsers[$key]; $u['key'] = $key; return $u; }
    return null;
}
function cfmail_normalize_charset(string $charset): string {
    $charset = trim($charset, " \t\r\n\0\x0B\"'");
    if ($charset === '' || strtoupper($charset) === 'DEFAULT') return '';

    $key = strtolower($charset);
    $key = str_replace(['_', ' '], ['-', ''], $key);

    $map = [
        'utf-8' => 'UTF-8',
        'utf8' => 'UTF-8',
        'us-ascii' => 'UTF-8',
        'ascii' => 'UTF-8',
        'iso-8859-1' => 'ISO-8859-1',
        'iso8859-1' => 'ISO-8859-1',
        'iso-8859-2' => 'ISO-8859-2',
        'iso8859-2' => 'ISO-8859-2',
        'windows-1250' => 'Windows-1250',
        'windows1250' => 'Windows-1250',
        'cp-1250' => 'Windows-1250',
        'cp1250' => 'Windows-1250',
        'win-1250' => 'Windows-1250',
        'win1250' => 'Windows-1250',
        'windows-1251' => 'Windows-1251',
        'windows1251' => 'Windows-1251',
        'windows-1252' => 'Windows-1252',
        'windows1252' => 'Windows-1252',
    ];
    return $map[$key] ?? $charset;
}
function cfmail_can_mb_charset(string $charset): bool {
    if (!function_exists('mb_list_encodings')) return false;
    static $encs = null;
    if ($encs === null) {
        $encs = [];
        foreach ((array)@mb_list_encodings() as $e) $encs[strtolower((string)$e)] = true;
    }
    return isset($encs[strtolower($charset)]);
}
function cfmail_convert_to_utf8(string $text, string $charset): string {
    $charset = cfmail_normalize_charset($charset);
    if ($text === '' || $charset === '' || strtoupper($charset) === 'UTF-8') return $text;

    // První zkusíme iconv, protože na některých hostinzích mb_convert_encoding nebere windows-1250.
    if (function_exists('iconv')) {
        try {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if ($converted !== false && $converted !== '') return $converted;
        } catch (Throwable $e) {}
    }

    // mb_convert_encoding použijeme jen když dané kódování opravdu podporuje, jinak PHP 8 hází ValueError.
    if (function_exists('mb_convert_encoding') && cfmail_can_mb_charset($charset)) {
        try {
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
            if ($converted !== false && $converted !== '') return $converted;
        } catch (Throwable $e) {}
    }

    // Poslední pojistka pro české windows-1250.
    if (preg_match('/^(windows-1250|cp1250)$/i', $charset) && function_exists('iconv')) {
        try {
            $converted = @iconv('CP1250', 'UTF-8//IGNORE', $text);
            if ($converted !== false && $converted !== '') return $converted;
        } catch (Throwable $e) {}
    }
    return $text;
}
function cfmail_decode_header_value($value): string {
    $value = (string)$value;
    if ($value === '') return '';
    if (!function_exists('imap_mime_header_decode')) return $value;
    $decoded = @imap_mime_header_decode($value);
    if (!is_array($decoded)) return $value;
    $out = '';
    foreach ($decoded as $part) {
        $text = (string)($part->text ?? '');
        $charset = (string)($part->charset ?? '');
        $text = cfmail_convert_to_utf8($text, $charset);
        $out .= $text;
    }
    return trim($out);
}
function cfmail_decode_body(string $data, int $encoding, string $charset = ''): string {
    if (defined('ENCBASE64') && $encoding === ENCBASE64) $data = (string)base64_decode($data);
    elseif (defined('ENCQUOTEDPRINTABLE') && $encoding === ENCQUOTEDPRINTABLE) $data = quoted_printable_decode($data);
    return cfmail_convert_to_utf8($data, $charset);
}
function cfmail_part_charset($part): string {
    foreach (['parameters','dparameters'] as $prop) {
        if (!empty($part->$prop) && is_array($part->$prop)) {
            foreach ($part->$prop as $p) if (strtolower((string)($p->attribute ?? '')) === 'charset') return (string)($p->value ?? '');
        }
    }
    return '';
}
function cfmail_part_filename($part): string {
    foreach (['dparameters','parameters'] as $prop) {
        if (!empty($part->$prop) && is_array($part->$prop)) {
            foreach ($part->$prop as $p) {
                $a = strtolower((string)($p->attribute ?? ''));
                if ($a === 'filename' || $a === 'name') return cfmail_decode_header_value((string)($p->value ?? ''));
            }
        }
    }
    return '';
}

function cfmail_part_cid($part): string {
    $cid = trim((string)($part->id ?? ''));
    $cid = trim($cid, " <>\t\r\n\0\x0B");
    return $cid;
}
function cfmail_mime_from_part($part): string {
    $type = (int)($part->type ?? 0);
    $subtype = strtolower((string)($part->subtype ?? 'octet-stream'));
    $top = ['text','multipart','message','application','audio','image','video','other'][$type] ?? 'application';
    if ($top === 'other') $top = 'application';
    if ($subtype === '') $subtype = 'octet-stream';
    return $top . '/' . $subtype;
}
function cfmail_decode_transfer(string $data, int $encoding): string {
    if ((defined('ENCBASE64') && $encoding === ENCBASE64) || $encoding === 3) return (string)base64_decode($data);
    if ((defined('ENCQUOTEDPRINTABLE') && $encoding === ENCQUOTEDPRINTABLE) || $encoding === 4) return quoted_printable_decode($data);
    return $data;
}
function cfmail_attachment_url(array $m, int $idx, bool $inline=false): string {
    $url = 'index.php?view=mail&cfmail_download=1&message_id=' . (int)($m['id'] ?? 0) . '&att=' . $idx;
    if ($inline) $url .= '&inline=1';
    return $url;
}
function cfmail_find_attachment_part_info($part, string $wantedName='', string $wantedCid='', string $partNo='') {
    $filename = cfmail_part_filename($part);
    $cid = cfmail_part_cid($part);
    $disp = strtolower((string)($part->disposition ?? ''));
    $mime = cfmail_mime_from_part($part);
    $match = false;
    if ($wantedCid !== '' && $cid !== '' && strcasecmp(trim($wantedCid, '<>'), $cid) === 0) $match = true;
    if (!$match && $wantedName !== '' && $filename !== '' && $filename === $wantedName) $match = true;
    if (!$match && $wantedName !== '' && $filename !== '' && strcasecmp($filename, $wantedName) === 0) $match = true;
    if ($match) return ['part'=>$partNo === '' ? '1' : $partNo, 'encoding'=>(int)($part->encoding ?? 0), 'mime'=>$mime, 'filename'=>$filename, 'cid'=>$cid, 'disposition'=>$disp];
    if (!empty($part->parts) && is_array($part->parts)) {
        $i = 1;
        foreach ($part->parts as $sub) {
            $res = cfmail_find_attachment_part_info($sub, $wantedName, $wantedCid, $partNo === '' ? (string)$i : $partNo . '.' . $i);
            if ($res) return $res;
            $i++;
        }
    }
    return null;
}
function cfmail_fetch_body_parts($imap, int $uid): array {
    $plain = ''; $html = ''; $attachments = [];
    if (!function_exists('imap_fetchstructure')) return ['plain'=>'','html'=>'','attachments'=>[]];
    $structure = @imap_fetchstructure($imap, $uid, FT_UID);
    $walk = function($part, string $partNo) use (&$walk, $imap, $uid, &$plain, &$html, &$attachments) {
        $type = (int)($part->type ?? 0);
        $subtype = strtoupper((string)($part->subtype ?? ''));
        $disp = strtolower((string)($part->disposition ?? ''));
        $filename = cfmail_part_filename($part);
        $cid = cfmail_part_cid($part);
        $mime = cfmail_mime_from_part($part);
        $isAttachment = ($filename !== '' || $disp === 'attachment' || ($cid !== '' && strpos($mime, 'image/') === 0));
        if (!empty($part->parts) && is_array($part->parts)) {
            $i = 1;
            foreach ($part->parts as $sub) { $walk($sub, $partNo === '' ? (string)$i : $partNo . '.' . $i); $i++; }
            return;
        }
        $usePart = $partNo === '' ? '1' : $partNo;
        if ($isAttachment) {
            $attachments[] = [
                'filename'=>$filename ?: ($cid !== '' ? $cid : 'priloha'),
                'size'=>(int)($part->bytes ?? 0),
                'type'=>$subtype,
                'mime'=>$mime,
                'part'=>$usePart,
                'encoding'=>(int)($part->encoding ?? 0),
                'cid'=>$cid,
                'inline'=>($disp === 'inline' || ($cid !== '' && strpos($mime, 'image/') === 0)) ? 1 : 0,
                'disposition'=>$disp,
            ];
            return;
        }
        $body = @imap_fetchbody($imap, $uid, $usePart, FT_UID | FT_PEEK);
        $charset = cfmail_part_charset($part);
        $decoded = cfmail_decode_body((string)$body, (int)($part->encoding ?? 0), $charset);
        if ($type === 0 && $subtype === 'PLAIN') $plain .= "\n" . $decoded;
        elseif ($type === 0 && $subtype === 'HTML') $html .= "\n" . $decoded;
    };
    if ($structure) $walk($structure, '');
    if (trim($plain) === '' && $html !== '') $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($html)));
    return ['plain'=>trim($plain), 'html'=>trim($html), 'attachments'=>$attachments];
}
function cfmail_normalize_subject(string $subject): string {
    $s = trim($subject);
    for ($i=0;$i<5;$i++) { $s2 = preg_replace('/^\s*((re|fw|fwd|odp|odpověď)\s*[:\]]\s*)+/iu', '', $s); if ($s2 === $s) break; $s = trim((string)$s2); }
    $s = preg_replace('/\s+/u', ' ', $s);
    return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s);
}
function cfmail_find_order_number(string $text): string {
    if (preg_match('/\b(\d{10})\b/u', $text, $m)) return $m[1];
    if (preg_match('/(?:objedn[aá]vka|obj\.?|order)\D{0,20}(\d{6,12})/iu', $text, $m)) return $m[1];
    return '';
}
function cfmail_thread_key(array $msg): string {
    $refs = trim((string)($msg['references'] ?? $msg['message_references'] ?? ''));
    $irt = trim((string)($msg['in_reply_to'] ?? ''));
    $mid = trim((string)($msg['message_id'] ?? ''));
    if ($refs !== '') { preg_match_all('/<[^>]+>/', $refs, $m); if (!empty($m[0][0])) return sha1('ref:' . $m[0][0]); }
    if ($irt !== '') return sha1('irt:' . $irt);
    $order = cfmail_find_order_number(($msg['subject'] ?? '') . ' ' . ($msg['body_text'] ?? ''));
    if ($order !== '') return sha1('order:' . $order);
    $sub = cfmail_normalize_subject((string)($msg['subject'] ?? ''));
    $from = function_exists('mb_strtolower') ? mb_strtolower((string)($msg['from_email'] ?? ''), 'UTF-8') : strtolower((string)($msg['from_email'] ?? ''));
    if ($sub !== '') return sha1('sub:' . $sub . '|from:' . $from);
    return sha1('mid:' . ($mid !== '' ? $mid : uniqid('', true)));
}
function cfmail_keyword_hit(string $haystack, string $csvKeywords): bool {
    $h = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
    foreach (explode(',', $csvKeywords) as $kw) { $kw = trim($kw); if ($kw === '') continue; $needle = function_exists('mb_strtolower') ? mb_strtolower($kw, 'UTF-8') : strtolower($kw); if (strpos($h, $needle) !== false) return true; }
    return false;
}
function cfmail_action(int $threadId, ?int $messageId, ?string $worker, string $action, string $note=''): void {
    $actions = cfmail_load_actions();
    $actions[] = ['id'=>cfmail_next_id('action_id'), 'thread_id'=>$threadId, 'message_id'=>$messageId, 'worker'=>$worker, 'action'=>$action, 'note'=>$note, 'created_at'=>cfmail_now()];
    if (count($actions) > 5000) $actions = array_slice($actions, -5000);
    cfmail_save_actions($actions);
}
function cfmail_open_imap(array $s, string $folder='INBOX') {
    if (!function_exists('imap_open')) return false;
    $folder = trim($folder) !== '' ? trim($folder) : 'INBOX';
    $mailbox = '{' . $s['imap_host'] . ':' . (int)$s['imap_port'] . (string)$s['imap_flags'] . '}' . $folder;
    return @imap_open($mailbox, (string)$s['imap_user'], (string)$s['imap_pass']);
}
function cfmail_ensure_folder($imap, array $s, string $folder): void {
    if (!$imap || $folder === '' || strtoupper($folder) === 'INBOX') return;
    $base = '{' . $s['imap_host'] . ':' . (int)$s['imap_port'] . (string)$s['imap_flags'] . '}';
    $encoded = function_exists('imap_utf7_encode') ? imap_utf7_encode($folder) : $folder;
    @imap_createmailbox($imap, $base . $encoded);
}
function cfmail_find_thread_by_key(array $threads, string $key): ?int {
    foreach ($threads as $id=>$t) if (($t['thread_key'] ?? '') === $key) return (int)$id;
    return null;
}
function cfmail_upsert_message(array $settings, array $msg): int {
    $threads = cfmail_load_threads();
    $messages = cfmail_load_messages();
    $account = (string)($msg['account_email'] ?? $settings['account_email']);
    $folder = (string)($msg['folder'] ?? 'INBOX');
    $uid = isset($msg['uid']) && $msg['uid'] !== null ? (int)$msg['uid'] : null;
    foreach ($messages as &$m) {
        if ($uid !== null && (string)($m['account_email'] ?? '') === $account && (string)($m['folder'] ?? '') === $folder && (int)($m['uid'] ?? 0) === $uid) {
            $m['seen'] = (int)($msg['seen'] ?? $m['seen'] ?? 0);
            $m['answered'] = (int)($msg['answered'] ?? $m['answered'] ?? 0);
            $m['updated_at'] = cfmail_now();
            cfmail_save_messages($messages);
            return (int)$m['id'];
        }
    }
    unset($m);
    $threadKey = cfmail_thread_key($msg);
    $threadId = cfmail_find_thread_by_key($threads, $threadKey);
    $orderNo = cfmail_find_order_number(($msg['subject'] ?? '') . ' ' . ($msg['body_text'] ?? ''));
    $isInvoice = cfmail_keyword_hit(($msg['subject'] ?? '') . ' ' . ($msg['body_text'] ?? ''), (string)$settings['invoice_keywords']);
    if (!$threadId) {
        $threadId = cfmail_next_id('thread_id');
        $threads[(string)$threadId] = [
            'id'=>$threadId, 'thread_key'=>$threadKey, 'subject'=>(string)($msg['subject'] ?? ''), 'last_message_at'=>(string)($msg['date_at'] ?? cfmail_now()),
            'last_from_email'=>(string)($msg['from_email'] ?? ''), 'status'=>$isInvoice ? 'invoice_check' : 'new', 'assigned_to'=>'', 'last_worker'=>'', 'order_number'=>$orderNo,
            'accounting'=>$isInvoice ? 1 : 0, 'accounting_approved'=>0, 'accounting_by'=>'', 'accounting_at'=>'', 'created_at'=>cfmail_now(), 'updated_at'=>cfmail_now()
        ];
    } else {
        $t = $threads[(string)$threadId];
        if (trim((string)($t['subject'] ?? '')) === '') $t['subject'] = (string)($msg['subject'] ?? '');
        $incomingDate = (string)($msg['date_at'] ?? cfmail_now());
        if (strtotime($incomingDate) >= strtotime((string)($t['last_message_at'] ?? '1970-01-01 00:00:00'))) {
            $t['last_message_at'] = $incomingDate;
            $t['last_from_email'] = (string)($msg['from_email'] ?? '');
        }
        if ($orderNo !== '' && trim((string)($t['order_number'] ?? '')) === '') $t['order_number'] = $orderNo;
        if ($isInvoice) $t['accounting'] = 1;
        if (($t['status'] ?? '') === 'done' && (string)($msg['direction'] ?? 'in') === 'in') $t['status'] = 'new';
        $t['updated_at'] = cfmail_now();
        $threads[(string)$threadId] = $t;
    }
    $msgId = cfmail_next_id('message_id');
    $messages[] = [
        'id'=>$msgId, 'thread_id'=>$threadId, 'account_email'=>$account, 'folder'=>$folder, 'uid'=>$uid,
        'message_id'=>(string)($msg['message_id'] ?? ''), 'in_reply_to'=>(string)($msg['in_reply_to'] ?? ''), 'message_references'=>(string)($msg['references'] ?? $msg['message_references'] ?? ''),
        'direction'=>(string)($msg['direction'] ?? 'in'), 'worker'=>(string)($msg['worker'] ?? ''), 'subject'=>(string)($msg['subject'] ?? ''),
        'from_name'=>(string)($msg['from_name'] ?? ''), 'from_email'=>(string)($msg['from_email'] ?? ''), 'to_email'=>(string)($msg['to_email'] ?? ''), 'cc_email'=>(string)($msg['cc_email'] ?? ''),
        'date_at'=>(string)($msg['date_at'] ?? cfmail_now()), 'seen'=>(int)($msg['seen'] ?? 0), 'answered'=>(int)($msg['answered'] ?? 0),
        'has_attachments'=>(int)($msg['has_attachments'] ?? 0), 'attachments_json'=>json_encode($msg['attachments'] ?? [], JSON_UNESCAPED_UNICODE),
        'body_text'=>(string)($msg['body_text'] ?? ''), 'body_html'=>(string)($msg['body_html'] ?? ''), 'raw_headers'=>(string)($msg['raw_headers'] ?? ''),
        'created_at'=>cfmail_now(), 'updated_at'=>cfmail_now()
    ];
    cfmail_save_threads($threads); cfmail_save_messages($messages);
    cfmail_action($threadId, $msgId, $msg['worker'] ?? null, 'import', 'Zpráva načtena z pošty.');
    return $msgId;
}
function cfmail_existing_uid_map(): array {
    $map = [];
    foreach (cfmail_load_messages() as $m) {
        if (!empty($m['uid'])) $map[(string)($m['account_email'] ?? '') . '|' . (string)($m['folder'] ?? '') . '|' . (int)$m['uid']] = true;
    }
    return $map;
}
function cfmail_sync(array $settings, array $folders): array {
    if (!function_exists('imap_open')) return ['ok'=>false, 'message'=>'Na hostingu není povolené PHP rozšíření IMAP.'];
    if (trim((string)$settings['imap_pass']) === '') return ['ok'=>false, 'message'=>'Chybí IMAP heslo v Nastavení.'];
    $since = date('d-M-Y', strtotime('-' . max(1, (int)$settings['sync_days']) . ' days'));
    $limit = max(20, min(500, (int)$settings['sync_limit']));
    $imported = 0; $skipped = 0; $errors = [];
    $existing = cfmail_existing_uid_map();
    foreach ($folders as $folder) {
        if (trim($folder) === '') continue;
        $imap = cfmail_open_imap($settings, $folder);
        if (!$imap) { $errors[] = $folder . ': ' . imap_last_error(); continue; }
        $uids = @imap_search($imap, 'SINCE "' . $since . '"', SE_UID);
        if (!is_array($uids)) { @imap_close($imap); continue; }
        rsort($uids, SORT_NUMERIC); $uids = array_slice($uids, 0, $limit);
        foreach (array_reverse($uids) as $uid) {
            $key = (string)$settings['account_email'] . '|' . $folder . '|' . (int)$uid;
            if (isset($existing[$key])) { $skipped++; continue; }
            $ov = @imap_fetch_overview($imap, (string)$uid, FT_UID);
            $o = is_array($ov) && isset($ov[0]) ? $ov[0] : null;
            $headersRaw = (string)@imap_fetchheader($imap, $uid, FT_UID);
            $parsedHeaders = @imap_rfc822_parse_headers($headersRaw);
            $parts = cfmail_fetch_body_parts($imap, (int)$uid);
            $fromEmail = '';$fromName='';$toEmail='';$ccEmail='';
            if (!empty($parsedHeaders->from[0])) { $f=$parsedHeaders->from[0]; $fromEmail = trim((string)($f->mailbox ?? '') . '@' . (string)($f->host ?? ''), '@'); $fromName = cfmail_decode_header_value((string)($f->personal ?? '')); }
            if (!empty($parsedHeaders->to) && is_array($parsedHeaders->to)) { $arr=[]; foreach($parsedHeaders->to as $r){$arr[]=trim((string)($r->mailbox ?? '').'@'.(string)($r->host ?? ''),'@');} $toEmail=implode(', ', array_filter($arr)); }
            if (!empty($parsedHeaders->cc) && is_array($parsedHeaders->cc)) { $arr=[]; foreach($parsedHeaders->cc as $r){$arr[]=trim((string)($r->mailbox ?? '').'@'.(string)($r->host ?? ''),'@');} $ccEmail=implode(', ', array_filter($arr)); }
            $subject = cfmail_decode_header_value((string)($o->subject ?? $parsedHeaders->subject ?? ''));
            $dateAt = !empty($o->date) ? date('Y-m-d H:i:s', strtotime((string)$o->date)) : (!empty($parsedHeaders->date) ? date('Y-m-d H:i:s', strtotime((string)$parsedHeaders->date)) : cfmail_now());
            $msg = [
                'account_email'=>(string)$settings['account_email'], 'folder'=>$folder, 'uid'=>(int)$uid,
                'message_id'=>(string)($parsedHeaders->message_id ?? ''), 'in_reply_to'=>(string)($parsedHeaders->in_reply_to ?? ''), 'references'=>(string)($parsedHeaders->references ?? ''),
                'direction'=>strcasecmp($folder, (string)($settings['folders']['sent'] ?? 'Sent')) === 0 ? 'out' : 'in', 'worker'=>'',
                'subject'=>$subject, 'from_name'=>$fromName, 'from_email'=>$fromEmail, 'to_email'=>$toEmail, 'cc_email'=>$ccEmail, 'date_at'=>$dateAt,
                'seen'=>!empty($o->seen) ? 1 : 0, 'answered'=>!empty($o->answered) ? 1 : 0, 'has_attachments'=>count($parts['attachments']) ? 1 : 0,
                'attachments'=>$parts['attachments'], 'body_text'=>$parts['plain'], 'body_html'=>$parts['html'], 'raw_headers'=>$headersRaw,
            ];
            cfmail_upsert_message($settings, $msg); $existing[$key]=true; $imported++;
        }
        @imap_close($imap);
    }
    return ['ok'=>true, 'message'=>'Načteno: ' . $imported . ', přeskočeno duplicit: ' . $skipped . (count($errors) ? '. Chyby: ' . implode(' | ', $errors) : ''), 'imported'=>$imported, 'skipped'=>$skipped, 'errors'=>$errors];
}
function cfmail_smtp_send(array $settings, string $to, string $subject, string $body, string $workerName='', string $inReplyTo='', string $references='', array $signature = []): array {
    $host=(string)$settings['smtp_host']; $port=(int)$settings['smtp_port']; $secure=strtolower((string)$settings['smtp_secure']); $user=(string)$settings['smtp_user']; $pass=(string)$settings['smtp_pass'];
    if ($host==='' || $port<=0 || $user==='' || $pass==='') return ['ok'=>false,'message'=>'Chybí SMTP nastavení nebo heslo.'];
    $errno=0;$errstr=''; $remote=($secure==='ssl'?'ssl://':'').$host;
    $fp=@stream_socket_client($remote.':'.$port,$errno,$errstr,20,STREAM_CLIENT_CONNECT); if(!$fp) return ['ok'=>false,'message'=>'SMTP připojení selhalo: '.$errstr];
    stream_set_timeout($fp,20);
    $read=function() use($fp){$data=''; while(($line=fgets($fp,515))!==false){$data.=$line; if(strlen($line)<4 || substr($line,3,1)===' ') break;} return $data;};
    $cmd=function(string $c) use($fp,$read){fwrite($fp,$c."\r\n"); return $read();};
    $ok=function(string $r,array $codes):bool{return in_array(substr(trim($r),0,3),$codes,true);};
    $resp=$read(); if(!$ok($resp,['220'])){fclose($fp);return ['ok'=>false,'message'=>'SMTP banner chyba: '.trim($resp)];}
    $resp=$cmd('EHLO cfloat.cz'); if(!$ok($resp,['250'])){fclose($fp);return ['ok'=>false,'message'=>'SMTP EHLO chyba: '.trim($resp)];}
    if($secure==='tls'||$secure==='starttls'){ $resp=$cmd('STARTTLS'); if(!$ok($resp,['220'])){fclose($fp);return ['ok'=>false,'message'=>'SMTP STARTTLS chyba: '.trim($resp)];} if(!@stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)){fclose($fp);return ['ok'=>false,'message'=>'SMTP TLS nelze zapnout.'];} $resp=$cmd('EHLO cfloat.cz'); if(!$ok($resp,['250'])){fclose($fp);return ['ok'=>false,'message'=>'SMTP EHLO po TLS chyba: '.trim($resp)];} }
    $resp=$cmd('AUTH LOGIN'); if(!$ok($resp,['334'])){fclose($fp);return ['ok'=>false,'message'=>'SMTP AUTH chyba: '.trim($resp)];}
    $resp=$cmd(base64_encode($user)); if(!$ok($resp,['334'])){fclose($fp);return ['ok'=>false,'message'=>'SMTP user chyba: '.trim($resp)];}
    $resp=$cmd(base64_encode($pass)); if(!$ok($resp,['235'])){fclose($fp);return ['ok'=>false,'message'=>'SMTP heslo chyba: '.trim($resp)];}
    $fromEmail=(string)$settings['account_email']; $fromName=trim((string)$settings['from_name'].($workerName!==''?' – '.$workerName:'')); $replyTo=(string)$settings['reply_to'];
    $messageId='<'.bin2hex(random_bytes(12)).'@cfloat.cz>'; $encSub='=?UTF-8?B?'.base64_encode($subject).'?='; $encFrom='=?UTF-8?B?'.base64_encode($fromName).'?=';
    $headers=['Date: '.date('r'),'From: '.$encFrom.' <'.$fromEmail.'>','To: <'.$to.'>','Subject: '.$encSub,'Message-ID: '.$messageId];
    if($replyTo!=='') $headers[]='Reply-To: <'.$replyTo.'>'; if($inReplyTo!=='') $headers[]='In-Reply-To: '.$inReplyTo; if($references!=='') $headers[]='References: '.$references;
    $headers[]='MIME-Version: 1.0';
    $sigImage = cfmail_signature_image_url($signature);
    if ($sigImage !== '') {
        $boundary = 'b_' . bin2hex(random_bytes(10));
        $headers[]='Content-Type: multipart/alternative; boundary="'.$boundary.'"';
        $html = cfmail_text_to_html($body) . '<br><br><img src="' . h($sigImage) . '" alt="C-Store.cz" style="max-width:220px;height:auto;border:0;">';
        $data=implode("\r\n",$headers)."\r\n\r\n".
            '--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($body))."\r\n".
            '--'.$boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode('<html><body>'.$html.'</body></html>'))."\r\n".
            '--'.$boundary.'--'."\r\n";
    } else {
        $headers[]='Content-Type: text/plain; charset=UTF-8'; $headers[]='Content-Transfer-Encoding: base64';
        $data=implode("\r\n",$headers)."\r\n\r\n".chunk_split(base64_encode($body));
    }
    $resp=$cmd('MAIL FROM:<'.$user.'>'); if(!$ok($resp,['250'])){fclose($fp);return ['ok'=>false,'message'=>'MAIL FROM chyba: '.trim($resp)];}
    $recipients=cfmail_split_emails($to); if(!$recipients) $recipients=[$to];
    foreach($recipients as $rcpt){ $resp=$cmd('RCPT TO:<'.$rcpt.'>'); if(!$ok($resp,['250','251'])){fclose($fp);return ['ok'=>false,'message'=>'RCPT TO chyba: '.trim($resp)];} }
    $resp=$cmd('DATA'); if(!$ok($resp,['354'])){fclose($fp);return ['ok'=>false,'message'=>'DATA chyba: '.trim($resp)];}
    fwrite($fp,$data."\r\n.\r\n"); $resp=$read(); $cmd('QUIT'); fclose($fp); if(!$ok($resp,['250'])) return ['ok'=>false,'message'=>'Odeslání selhalo: '.trim($resp)];
    return ['ok'=>true,'message'=>'E-mail odeslán.','message_id'=>$messageId];
}
function cfmail_set_seen_imap(array $settings, array $messages, bool $seen): void {
    if (!function_exists('imap_open') || trim((string)$settings['imap_pass']) === '') return;
    $by=[]; foreach($messages as $m){ if(!empty($m['uid'])) $by[(string)$m['folder']][]=(int)$m['uid']; }
    foreach($by as $folder=>$uids){ $imap=cfmail_open_imap($settings,$folder); if(!$imap) continue; $seq=implode(',',$uids); if($seen) @imap_setflag_full($imap,$seq,'\\Seen',ST_UID); else @imap_clearflag_full($imap,$seq,'\\Seen',ST_UID); @imap_close($imap); }
}
function cfmail_move_thread_imap(array $settings, array $messages, string $targetFolder): void {
    if (!function_exists('imap_open') || trim((string)$settings['imap_pass']) === '' || $targetFolder === '') return;
    $by=[]; foreach($messages as $m){ if(!empty($m['uid']) && (string)$m['folder']!==$targetFolder) $by[(string)$m['folder']][]=(int)$m['uid']; }
    foreach($by as $folder=>$uids){ $imap=cfmail_open_imap($settings,$folder); if(!$imap) continue; cfmail_ensure_folder($imap,$settings,$targetFolder); @imap_mail_move($imap,implode(',',$uids),$targetFolder,CP_UID); @imap_expunge($imap); @imap_close($imap); }
}
function cfmail_thread_messages(int $threadId): array { $m=cfmail_load_messages(); $out=[]; foreach($m as $x) if((int)($x['thread_id']??0)===$threadId) $out[]=$x; usort($out, fn($a,$b)=>strcmp((string)($b['date_at']??$b['created_at']??''),(string)($a['date_at']??$a['created_at']??'')) ?: ((int)($b['id']??0)<=> (int)($a['id']??0))); return $out; }
function cfmail_thread_notes(int $threadId): array { $out=[]; foreach(cfmail_load_notes() as $n) if((int)($n['thread_id']??0)===$threadId) $out[]=$n; usort($out, fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??''))); return array_slice($out,0,20); }
function cfmail_thread_actions(int $threadId): array { $out=[]; foreach(cfmail_load_actions() as $n) if((int)($n['thread_id']??0)===$threadId) $out[]=$n; usort($out, fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??''))); return array_slice($out,0,40); }
function cfmail_status_label(string $status): string { $map=['new'=>'Nové','open'=>'Řeší se','waiting_customer'=>'Řeší se','waiting_supplier'=>'Čeká na dodavatele','done'=>'Vyřízeno','invoice_check'=>'Faktura ke kontrole','accounting_approved'=>'Schváleno pro účetní','complaint'=>'Reklamace','exchange'=>'Výměna','return'=>'Vrácení']; return $map[$status] ?? $status; }

function cfmail_post_thread_ids(): array {
    $ids = [];
    $raw = trim((string)($_POST['selected_thread_ids'] ?? ''));
    if ($raw !== '') {
        foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $id) { $n=(int)$id; if($n>0) $ids[$n]=$n; }
    }
    $one = (int)($_POST['thread_id'] ?? 0);
    if (!$ids && $one > 0) $ids[$one]=$one;
    return array_values($ids);
}
function cfmail_bulk_messages(array $threadIds): array {
    $out=[];
    foreach($threadIds as $tid) foreach(cfmail_thread_messages((int)$tid) as $m) $out[]=$m;
    return $out;
}
function cfmail_bulk_label(array $threadIds): string {
    $n=count($threadIds);
    return $n>1 ? 'Hromadně upraveno zpráv: '.$n : '';
}

function cfmail_process_color(string $color): string {
    $color = strtolower(trim($color));
    return in_array($color, ['orange','red','green'], true) ? $color : '';
}
function cfmail_process_badge(string $color): string {
    $color = cfmail_process_color($color);
    if ($color === '') return '';
    return '<span class="rc-process-badge pc-' . h($color) . '">Zpracovat</span>';
}
function cfmail_worker_badge(?string $key): string { global $cfMailUsers; if(!$key || empty($cfMailUsers[$key])) return '<span class="mail-muted">—</span>'; $u=$cfMailUsers[$key]; return '<span class="worker-badge" style="--wcolor:'.h($u['color']).'"><span>'.h($u['initial']).'</span>'.h($u['name']).'</span>'; }
function cfmail_date_short($dt): string { if(!$dt) return '—'; $ts=strtotime((string)$dt); if(!$ts) return (string)$dt; if(date('Y-m-d')===date('Y-m-d',$ts)) return date('H:i',$ts); return date('d.m. H:i',$ts); }
function cfmail_date_full($dt): string { if(!$dt) return '—'; $ts=strtotime((string)$dt); if(!$ts) return (string)$dt; return date('d.m.Y H:i',$ts); }
function cfmail_short_text(string $text, int $len=250): string { return function_exists('mb_substr') ? mb_substr($text, 0, $len, 'UTF-8') : substr($text, 0, $len); }
function cfmail_update_thread(int $id, array $changes): void { $threads=cfmail_load_threads(); if(!isset($threads[(string)$id])) return; $threads[(string)$id]=array_replace($threads[(string)$id],$changes,['updated_at'=>cfmail_now()]); cfmail_save_threads($threads); }
function cfmail_update_messages_for_thread(int $threadId, array $changes): void { $msgs=cfmail_load_messages(); foreach($msgs as &$m) if((int)($m['thread_id']??0)===$threadId) $m=array_replace($m,$changes,['updated_at'=>cfmail_now()]); unset($m); cfmail_save_messages($msgs); }

function cfmail_find_uid_by_message_id($imap, string $messageId): int {
    $messageId = trim($messageId);
    if ($messageId === '' || !function_exists('imap_search')) return 0;
    $q = 'HEADER Message-ID "' . addcslashes($messageId, '\"') . '"';
    $uids = @imap_search($imap, $q, SE_UID);
    if (is_array($uids) && !empty($uids[0])) return (int)$uids[0];
    $q = 'TEXT "' . addcslashes($messageId, '\"') . '"';
    $uids = @imap_search($imap, $q, SE_UID);
    if (is_array($uids) && !empty($uids[0])) return (int)$uids[0];
    return 0;
}
function cfmail_download_attachment(array $settings): void {
    $messageId = (int)($_GET['message_id'] ?? 0);
    $attIdx = (int)($_GET['att'] ?? -1);
    $messages = cfmail_load_messages(); $msg = null;
    foreach ($messages as $m) { if ((int)($m['id'] ?? 0) === $messageId) { $msg = $m; break; } }
    if (!$msg) { http_response_code(404); echo 'Zpráva nenalezena.'; return; }
    $atts = json_decode((string)($msg['attachments_json'] ?? '[]'), true); if (!is_array($atts)) $atts=[];
    if (!isset($atts[$attIdx])) { http_response_code(404); echo 'Příloha nenalezena.'; return; }
    $att = $atts[$attIdx];
    $folder = (string)($msg['folder'] ?? 'INBOX'); $uid = (int)($msg['uid'] ?? 0);
    if ($uid <= 0) { http_response_code(404); echo 'Příloha není dostupná pro lokálně vytvořenou zprávu.'; return; }
    $imap = cfmail_open_imap($settings, $folder);
    if (!$imap) { http_response_code(500); echo 'Nelze otevřít IMAP složku.'; return; }
    $partNo = (string)($att['part'] ?? ''); $encoding = (int)($att['encoding'] ?? 0); $mime = (string)($att['mime'] ?? 'application/octet-stream');
    $actualUid = $uid;
    $structure = @imap_fetchstructure($imap, $actualUid, FT_UID);
    if (!$structure) {
        $foundUid = cfmail_find_uid_by_message_id($imap, (string)($msg['message_id'] ?? ''));
        if ($foundUid > 0) { $actualUid = $foundUid; $structure = @imap_fetchstructure($imap, $actualUid, FT_UID); }
    }
    if ($partNo === '') {
        $found = $structure ? cfmail_find_attachment_part_info($structure, (string)($att['filename'] ?? ''), (string)($att['cid'] ?? '')) : null;
        if ($found) { $partNo = (string)$found['part']; $encoding = (int)$found['encoding']; $mime = (string)$found['mime']; }
    }
    if ($partNo === '') { @imap_close($imap); http_response_code(404); echo 'Příloha nemá uloženou část. Zkus znovu načíst poštu.'; return; }
    $raw = @imap_fetchbody($imap, $actualUid, $partNo, FT_UID | FT_PEEK);
    if (($raw === false || $raw === '') && $actualUid === $uid) {
        $foundUid = cfmail_find_uid_by_message_id($imap, (string)($msg['message_id'] ?? ''));
        if ($foundUid > 0 && $foundUid !== $actualUid) { $actualUid = $foundUid; $raw = @imap_fetchbody($imap, $actualUid, $partNo, FT_UID | FT_PEEK); }
    }
    @imap_close($imap);
    if ($raw === false || $raw === '') { http_response_code(404); echo 'Přílohu se nepodařilo stáhnout.'; return; }
    $data = cfmail_decode_transfer((string)$raw, $encoding);
    $filename = (string)($att['filename'] ?? 'priloha');
    $filename = preg_replace('/[\r\n]+/', ' ', $filename);
    if ($mime === '') $mime = 'application/octet-stream';
    $inline = isset($_GET['inline']);
    while (ob_get_level()) @ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($data));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"','', $filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    echo $data;
}
function cfmail_sanitize_email_html(string $html, array $m): string {
    $atts = json_decode((string)($m['attachments_json'] ?? '[]'), true); if (!is_array($atts)) $atts=[];
    foreach ($atts as $idx=>$att) {
        $cid = trim((string)($att['cid'] ?? ''), '<>');
        if ($cid !== '') {
            $url = cfmail_attachment_url($m, (int)$idx, true);
            $html = preg_replace('/(["\'])cid:' . preg_quote($cid, '/') . '(["\'])/i', '$1' . $url . '$2', $html);
            $html = preg_replace('/cid:' . preg_quote($cid, '/') . '/i', $url, $html);
        }
    }
    $html = preg_replace('/<\s*(script|iframe|object|embed|form|input|button|meta|base)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html);
    $html = preg_replace('/<\s*(script|iframe|object|embed|form|input|button|meta|base)[^>]*\/?>/is', '', $html);
    $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/javascript\s*:/i', '', $html);
    $html = preg_replace('/<a\s+/i', '<a target="_blank" rel="noopener" ', $html);
    return '<div class="email-html">' . $html . '</div>';
}
function cfmail_render_message_body(array $m): string {
    $html = trim((string)($m['body_html'] ?? ''));
    $text = trim((string)($m['body_text'] ?? ''));
    if ($html !== '') return cfmail_sanitize_email_html($html, $m);
    if ($text !== '') return '<div class="email-text">' . nl2br(h($text)) . '</div>';
    return '<div class="email-text mail-muted">(bez textu)</div>';
}
function cfmail_filter_threads(array $threads, string $q, string $status, string $worker, string $mailbox = 'inbox'): array {
    $messages=cfmail_load_messages(); $msgByThread=[]; foreach($messages as $m) $msgByThread[(int)($m['thread_id']??0)][]=$m;
    $out=[]; $qLower=function_exists('mb_strtolower')?mb_strtolower($q,'UTF-8'):strtolower($q);
    foreach($threads as $t){
        $threadMessages = $msgByThread[(int)$t['id']] ?? [];
        if ($mailbox === 'sent') {
            $hasOut = false;
            foreach ($threadMessages as $mm) { if ((string)($mm['direction'] ?? 'in') === 'out') { $hasOut = true; break; } }
            if (!$hasOut) continue;
        } else {
            $hasIn = false;
            foreach ($threadMessages as $mm) { if ((string)($mm['direction'] ?? 'in') === 'in') { $hasIn = true; break; } }
            if (!$hasIn) continue;
        }
        if($status!=='' && (string)($t['status']??'')!==$status) continue;
        if($worker!=='' && (string)($t['assigned_to']??'')!==$worker && (string)($t['last_worker']??'')!==$worker) continue;
        if($q!==''){
            $hay=(string)($t['subject']??'').' '.(string)($t['order_number']??'').' '.(string)($t['last_from_email']??'');
            foreach($msgByThread[(int)$t['id']]??[] as $m) $hay.=' '.(string)($m['from_email']??'').' '.(string)($m['from_name']??'').' '.(string)($m['to_email']??'').' '.(string)($m['body_text']??'').' '.(string)($m['subject']??'');
            $hayLower=function_exists('mb_strtolower')?mb_strtolower($hay,'UTF-8'):strtolower($hay); if(strpos($hayLower,$qLower)===false) continue;
        }
        $unread=0;$msgCount=0;$hasAtt=0; foreach($msgByThread[(int)$t['id']]??[] as $m){$msgCount++; if((int)($m['seen']??0)===0 && (string)($m['direction']??'in')==='in') $unread++; if((int)($m['has_attachments']??0)===1) $hasAtt=1;}
        $t['unread_count']=$unread;$t['msg_count']=$msgCount;$t['has_att']=$hasAtt; $out[]=$t;
    }
    usort($out, fn($a,$b)=>strcmp((string)($b['last_message_at']??$b['updated_at']??''),(string)($a['last_message_at']??$a['updated_at']??'')));
    return array_slice($out,0,160);
}

$settings = cfmail_load_settings($cfMailDefaultSettings);
if (isset($_GET['cfmail_sig_image'])) { cfmail_serve_signature_image(); exit; }
if (isset($_GET['cfmail_download'])) { cfmail_download_attachment($settings); exit; }
$worker = cfmail_worker();
$flash = ''; $flashType = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cfmail_action'])) {
    $action=(string)$_POST['cfmail_action'];
    if($action==='login_worker'){ $wk=(string)($_POST['worker']??''); if(isset($cfMailUsers[$wk])) { $_SESSION['cfmail_worker']=$wk; $worker=cfmail_worker(); $flash='Přihlášen pracovník: '.$worker['name']; } }
    elseif($action==='logout_worker'){ unset($_SESSION['cfmail_worker']); $worker=null; $flash='Odhlášeno z MAIL modulu.'; }
    elseif($action==='save_settings'){
        $settings['account_email']=trim((string)($_POST['account_email']??$settings['account_email'])); $settings['imap_host']=trim((string)($_POST['imap_host']??$settings['imap_host'])); $settings['imap_port']=(int)($_POST['imap_port']??$settings['imap_port']); $settings['imap_flags']=trim((string)($_POST['imap_flags']??$settings['imap_flags'])); $settings['imap_user']=trim((string)($_POST['imap_user']??$settings['imap_user']));
        if((string)($_POST['imap_pass']??'')!=='') $settings['imap_pass']=(string)$_POST['imap_pass']; $settings['smtp_host']=trim((string)($_POST['smtp_host']??$settings['smtp_host'])); $settings['smtp_port']=(int)($_POST['smtp_port']??$settings['smtp_port']); $settings['smtp_secure']=trim((string)($_POST['smtp_secure']??$settings['smtp_secure'])); $settings['smtp_user']=trim((string)($_POST['smtp_user']??$settings['smtp_user'])); if((string)($_POST['smtp_pass']??'')!=='') $settings['smtp_pass']=(string)$_POST['smtp_pass'];
        $settings['from_name']=trim((string)($_POST['from_name']??$settings['from_name'])); $settings['reply_to']=trim((string)($_POST['reply_to']??$settings['reply_to'])); $settings['sync_days']=max(1,(int)($_POST['sync_days']??$settings['sync_days'])); $settings['sync_limit']=max(20,(int)($_POST['sync_limit']??$settings['sync_limit'])); $settings['invoice_keywords']=trim((string)($_POST['invoice_keywords']??$settings['invoice_keywords']));
        foreach(['inbox','sent','done','accounting_review','accounting_approved','trash'] as $f) $settings['folders'][$f]=trim((string)($_POST['folder_'.$f]??($settings['folders'][$f]??'')));
        cfmail_save_settings($settings); $flash='Nastavení uloženo.';
    } elseif($action==='sync_mail'){
        $syncFolders=[(string)($settings['folders']['inbox']??'INBOX')]; if(!empty($settings['folders']['sent'])) $syncFolders[]=(string)$settings['folders']['sent']; $res=cfmail_sync($settings,array_unique($syncFolders)); $flash=$res['message']??''; if(empty($res['ok'])) $flashType='error';
    } elseif($action==='add_contact'){
        if(!$worker){ $flash='Nejdřív se přihlas jako Šárka, Lukáš nebo Kamil.'; $flashType='error'; }
        else {
            $first=trim((string)($_POST['contact_first']??'')); $last=trim((string)($_POST['contact_last']??'')); $email=cfmail_clean_email((string)($_POST['contact_email']??''));
            if($email==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)){ $flash='Zadej platnou e-mailovou adresu kontaktu.'; $flashType='error'; }
            else { $contacts=cfmail_load_contacts(); $found=false; foreach($contacts as &$c){ if(cfmail_clean_email((string)($c['email']??''))===$email){ $c['first_name']=$first; $c['last_name']=$last; $c['email']=$email; $c['updated_at']=cfmail_now(); $found=true; break; } } unset($c); if(!$found) $contacts[]=['id'=>cfmail_next_id('contact_id'),'first_name'=>$first,'last_name'=>$last,'email'=>$email,'created_at'=>cfmail_now(),'updated_at'=>cfmail_now()]; cfmail_save_contacts($contacts); $flash=$found?'Kontakt upraven.':'Kontakt přidán.'; }
        }
    } elseif($action==='delete_contact'){
        if(!$worker){ $flash='Nejdřív se přihlas jako Šárka, Lukáš nebo Kamil.'; $flashType='error'; }
        else { $id=(int)($_POST['contact_id']??0); $contacts=[]; foreach(cfmail_load_contacts() as $c){ if((int)($c['id']??0)!==$id) $contacts[]=$c; } cfmail_save_contacts($contacts); $flash='Kontakt smazán.'; }
    } elseif($action==='save_signature'){
        $key=preg_replace('/[^a-z0-9_-]/i','',(string)($_POST['signature_key']??cfmail_signature_key($worker)));
        if($key==='' || ($key!=='default' && !isset($cfMailUsers[$key]))) $key='default';
        $sigs=cfmail_load_signatures(); $old=is_array($sigs[$key]??null)?$sigs[$key]:[];
        $sig=['text'=>trim((string)($_POST['signature_text']??'')),'image_url'=>trim((string)($_POST['signature_image_url']??($old['image_url']??''))),'image_file'=>(string)($old['image_file']??'')];
        if(isset($_POST['remove_signature_image'])) $sig['image_file']='';
        if(!empty($_FILES['signature_image']['tmp_name']) && is_uploaded_file($_FILES['signature_image']['tmp_name'])){
            $ext=strtolower(pathinfo((string)($_FILES['signature_image']['name']??''),PATHINFO_EXTENSION));
            if(!in_array($ext,['png','jpg','jpeg','gif','webp'],true)){ $flash='Obrázek podpisu musí být PNG, JPG, GIF nebo WEBP.'; $flashType='error'; }
            else { $file='signature_'.$key.'.'.$ext; if(@move_uploaded_file($_FILES['signature_image']['tmp_name'], __DIR__.'/data/'.$file)){ $sig['image_file']=$file; $flash='Podpis uložen.'; } else { $flash='Obrázek podpisu se nepodařilo uložit.'; $flashType='error'; } }
        }
        if($flashType!=='error'){ $sigs[$key]=$sig; cfmail_save_signatures($sigs); $flash='Podpis uložen.'; }
    } elseif($worker) {
        $threadIds=cfmail_post_thread_ids(); $threadId=(int)($threadIds[0]??0); $messages=$threadId>0?cfmail_thread_messages($threadId):[];
        if($action==='send_new_email'){
            $to=trim((string)($_POST['new_to']??'')); $subject=trim((string)($_POST['new_subject']??'')); $body=trim((string)($_POST['new_body']??''));
            $sig=cfmail_signature_for(cfmail_signature_key($worker), $worker); $body=cfmail_ensure_signature_text($body,$sig);
            if($to==='' || $subject==='' || trim($body)===''){ $flash='Vyplň příjemce, předmět i text e-mailu.'; $flashType='error'; }
            else { $res=cfmail_smtp_send($settings,$to,$subject,$body,$worker['name'],'','',$sig); if(!empty($res['ok'])){ cfmail_upsert_message($settings,['account_email'=>(string)$settings['account_email'],'folder'=>(string)($settings['folders']['sent']??'Sent'),'uid'=>null,'message_id'=>(string)($res['message_id']??''),'in_reply_to'=>'','references'=>'','direction'=>'out','worker'=>$worker['key'],'subject'=>$subject,'from_name'=>(string)$settings['from_name'].' – '.$worker['name'],'from_email'=>(string)$settings['account_email'],'to_email'=>$to,'cc_email'=>'','date_at'=>cfmail_now(),'seen'=>1,'answered'=>1,'has_attachments'=>0,'attachments'=>[],'body_text'=>$body,'body_html'=>'','raw_headers'=>'']); $flash='Nový e-mail odeslán.'; } else { $flash=$res['message']??'Odeslání selhalo.'; $flashType='error'; } }
        }
        elseif($threadId>0 && $action==='set_status'){ $status=(string)($_POST['status']??'open'); foreach($threadIds as $tid){ cfmail_update_thread((int)$tid,['status'=>$status,'last_worker'=>$worker['key']]); cfmail_action((int)$tid,null,$worker['key'],'status',cfmail_status_label($status)); } $flash=cfmail_bulk_label($threadIds) ?: 'Stav změněn.'; }
        elseif($threadId>0 && $action==='mark_process'){ $color=cfmail_process_color((string)($_POST['process_color']??'')); foreach($threadIds as $tid){ cfmail_update_thread((int)$tid,['process_color'=>$color,'last_worker'=>$worker['key']]); cfmail_action((int)$tid,null,$worker['key'],'process_color',$color!==''?'Označeno barvou: '.$color:'Barevné označení zrušeno.'); } $flash=$color!==''?'Označeno jako Zpracovat.':'Barevné označení zrušeno.'; if(count($threadIds)>1) $flash.=' Hromadně: '.count($threadIds).' zpráv.'; }
        elseif($threadId>0 && $action==='take_thread'){ foreach($threadIds as $tid){ cfmail_update_thread((int)$tid,['assigned_to'=>$worker['key'],'status'=>'open','last_worker'=>$worker['key']]); cfmail_action((int)$tid,null,$worker['key'],'take','Převzato pracovníkem.'); } $flash=count($threadIds)>1?'Zprávy převzaty: '.count($threadIds):'Zpráva převzata.'; }
        elseif($threadId>0 && $action==='mark_seen'){ $seen=(int)($_POST['seen']??1); $allMsgs=cfmail_bulk_messages($threadIds); cfmail_set_seen_imap($settings,$allMsgs,(bool)$seen); foreach($threadIds as $tid){ cfmail_update_messages_for_thread((int)$tid,['seen'=>$seen]); cfmail_action((int)$tid,null,$worker['key'],$seen?'seen':'unseen',$seen?'Označeno jako přečtené.':'Označeno jako nepřečtené.'); } $flash=$seen?'Označeno jako přečtené.':'Označeno jako nepřečtené.'; if(count($threadIds)>1) $flash.=' Hromadně: '.count($threadIds).' zpráv.'; }
        elseif($threadId>0 && $action==='approve_accounting'){ $target=(string)($settings['folders']['accounting_approved']??'Cfloat/Faktury_pro_ucetni'); foreach($threadIds as $tid){ $msgs=cfmail_thread_messages((int)$tid); cfmail_move_thread_imap($settings,$msgs,$target); cfmail_update_thread((int)$tid,['status'=>'accounting_approved','accounting'=>1,'accounting_approved'=>1,'accounting_by'=>$worker['key'],'accounting_at'=>cfmail_now(),'last_worker'=>$worker['key']]); cfmail_update_messages_for_thread((int)$tid,['folder'=>$target]); cfmail_action((int)$tid,null,$worker['key'],'approve_accounting','Schváleno a přesunuto pro účetní.'); } $flash=count($threadIds)>1?'Schváleno pro účetní: '.count($threadIds).' zpráv.':'Schváleno pro účetní.'; }
        elseif($threadId>0 && $action==='move_done'){ $target=(string)($settings['folders']['done']??'Cfloat/Vyrizene'); foreach($threadIds as $tid){ $msgs=cfmail_thread_messages((int)$tid); cfmail_move_thread_imap($settings,$msgs,$target); cfmail_update_thread((int)$tid,['status'=>'done','last_worker'=>$worker['key']]); cfmail_update_messages_for_thread((int)$tid,['folder'=>$target]); cfmail_action((int)$tid,null,$worker['key'],'move_done','Přesunuto do vyřízených.'); } $flash=count($threadIds)>1?'Přesunuto do vyřízených: '.count($threadIds).' zpráv.':'Přesunuto do vyřízených.'; }
        elseif($threadId>0 && $action==='delete_thread'){
            $target=(string)($settings['folders']['trash']??'Trash');
            $del=[]; foreach($threadIds as $tid){ $tid=(int)$tid; if($tid>0) $del[$tid]=true; }
            foreach(array_keys($del) as $tid){ $msgs=cfmail_thread_messages((int)$tid); cfmail_move_thread_imap($settings,$msgs,$target); }
            $threads=cfmail_load_threads(); foreach($threads as $k=>$t){ $tid=(int)($t['id']??$k); if(isset($del[$tid])) unset($threads[$k]); } cfmail_save_threads($threads);
            $msgsNew=[]; foreach(cfmail_load_messages() as $m){ $tid=(int)($m['thread_id']??0); if(!isset($del[$tid])) $msgsNew[]=$m; } cfmail_save_messages($msgsNew);
            $notesNew=[]; foreach(cfmail_load_notes() as $n){ $tid=(int)($n['thread_id']??0); if(!isset($del[$tid])) $notesNew[]=$n; } cfmail_save_notes($notesNew);
            $actionsNew=[]; foreach(cfmail_load_actions() as $a){ $tid=(int)($a['thread_id']??0); if(!isset($del[$tid])) $actionsNew[]=$a; } cfmail_save_actions($actionsNew);
            $flash=count($del)>1?'E-maily smazány: '.count($del):'E-mail smazán.';
            $selectedThreadId=0; $selectedThread=null; $selectedMessages=[];
        }
        elseif($threadId>0 && $action==='add_note'){ $note=trim((string)($_POST['note']??'')); if($note!==''){ $notes=cfmail_load_notes(); $notes[]=['id'=>cfmail_next_id('note_id'),'thread_id'=>$threadId,'worker'=>$worker['key'],'note'=>$note,'created_at'=>cfmail_now()]; cfmail_save_notes($notes); cfmail_action($threadId,null,$worker['key'],'note',cfmail_short_text($note,250)); $flash='Interní poznámka uložena.'; } }
        elseif($threadId>0 && $action==='send_reply'){
            $body=trim((string)($_POST['reply_body']??'')); $sig=cfmail_signature_for(cfmail_signature_key($worker), $worker); $body=cfmail_ensure_signature_text($body,$sig); $after=(string)($_POST['after_send']??'open'); if(trim($body)===''){ $flash='Odpověď je prázdná.'; $flashType='error'; }
            else { $threads=cfmail_load_threads(); $thread=$threads[(string)$threadId]??[]; $lastIncoming=null; for($i=count($messages)-1;$i>=0;$i--){ if(($messages[$i]['direction']??'in')==='in' && !empty($messages[$i]['from_email'])){ $lastIncoming=$messages[$i]; break; } }
                $to=(string)($lastIncoming['from_email']??''); $subject=(string)($thread['subject']??''); if(!preg_match('/^\s*Re:/i',$subject)) $subject='Re: '.$subject; $inReplyTo=trim((string)($lastIncoming['message_id']??'')); $refs=trim((string)($lastIncoming['message_references']??'')); if($inReplyTo!=='' && strpos($refs,$inReplyTo)===false) $refs=trim($refs.' '.$inReplyTo);
                if($to===''){ $flash='Nelze určit příjemce odpovědi.'; $flashType='error'; } else { $res=cfmail_smtp_send($settings,$to,$subject,$body,$worker['name'],$inReplyTo,$refs,$sig); if(!empty($res['ok'])){ cfmail_upsert_message($settings,['account_email'=>(string)$settings['account_email'],'folder'=>(string)($settings['folders']['sent']??'Sent'),'uid'=>null,'message_id'=>(string)($res['message_id']??''),'in_reply_to'=>$inReplyTo,'references'=>$refs,'direction'=>'out','worker'=>$worker['key'],'subject'=>$subject,'from_name'=>(string)$settings['from_name'].' – '.$worker['name'],'from_email'=>(string)$settings['account_email'],'to_email'=>$to,'cc_email'=>'','date_at'=>cfmail_now(),'seen'=>1,'answered'=>1,'has_attachments'=>0,'attachments'=>[],'body_text'=>$body,'body_html'=>'','raw_headers'=>'']); $newStatus=in_array($after,['invoice_check','done','open'],true)?$after:'open'; cfmail_update_thread($threadId,['status'=>$newStatus,'assigned_to'=>$worker['key'],'last_worker'=>$worker['key']]); cfmail_action($threadId,null,$worker['key'],'reply','Odeslána odpověď zákazníkovi.'); $flash='Odpověď odeslána.'; } else { $flash=$res['message']??'Odeslání selhalo.'; $flashType='error'; } }
            }
        }
    } else { $flash='Nejdřív se přihlas jako Šárka, Lukáš nebo Kamil.'; $flashType='error'; }
}

$q=trim((string)($_GET['q']??'')); $statusFilter=trim((string)($_GET['status']??'')); $workerFilter=trim((string)($_GET['worker']??'')); $mailboxFilter=(string)($_GET['mailbox']??'inbox'); if(!in_array($mailboxFilter,['inbox','sent'],true)) $mailboxFilter='inbox'; $selectedThreadId=(int)($_GET['thread']??0); $showSettings=isset($_GET['settings']); $showCompose=isset($_GET['compose']); $showContacts=isset($_GET['contacts']); $showSignature=isset($_GET['signature']);
$threadsAll=cfmail_load_threads(); $threads=cfmail_filter_threads($threadsAll,$q,$statusFilter,$workerFilter,$mailboxFilter); if($selectedThreadId<=0 && !$showCompose && !$showSettings && !$showContacts && !$showSignature && $threads) $selectedThreadId=(int)$threads[0]['id'];
if($_SERVER['REQUEST_METHOD'] !== 'POST' && $selectedThreadId>0 && isset($_GET['markread'])){ cfmail_update_messages_for_thread($selectedThreadId,['seen'=>1]); if($worker) cfmail_action($selectedThreadId,null,$worker['key'],'seen','Otevřeno kliknutím.'); $threadsAll=cfmail_load_threads(); $threads=cfmail_filter_threads($threadsAll,$q,$statusFilter,$workerFilter,$mailboxFilter); }
$selectedThread=(!$showCompose && !$showContacts && !$showSignature && $selectedThreadId>0 && isset($threadsAll[(string)$selectedThreadId])) ? $threadsAll[(string)$selectedThreadId] : null; $selectedMessages=$selectedThread?cfmail_thread_messages($selectedThreadId):[]; $selectedNotes=$selectedThread?cfmail_thread_notes($selectedThreadId):[]; $selectedActions=$selectedThread?cfmail_thread_actions($selectedThreadId):[];
$counts=['new'=>0,'open'=>0,'invoice_check'=>0,'accounting_approved'=>0,'done'=>0,'complaint'=>0,'exchange'=>0,'return'=>0,'unread'=>0,'inbox'=>0,'sent'=>0]; foreach($threadsAll as $t){$s=(string)($t['status']??'new'); $counts[$s]=($counts[$s]??0)+1;}
$allMessagesForUi=cfmail_load_messages(); $previewByThread=[]; foreach($allMessagesForUi as $m){ $tid=(int)($m['thread_id']??0); if($tid<=0) continue; if((int)($m['seen']??0)===0 && (string)($m['direction']??'in')==='in') $counts['unread']++; if((string)($m['direction']??'in')==='in') $counts['inbox']++; else $counts['sent']++; $txt=trim((string)($m['body_text']??'')); if($txt!=='' && (!isset($previewByThread[$tid]) || strcmp((string)($m['date_at']??$m['created_at']??''),(string)($previewByThread[$tid]['date']??''))>=0)){ $previewByThread[$tid]=['date'=>(string)($m['date_at']??$m['created_at']??''),'text'=>cfmail_short_text(preg_replace('/\s+/u',' ',$txt),120)]; }}
$contactSuggestions=cfmail_contact_suggestions(); $frequentContacts=cfmail_frequent_contacts(8); $savedContacts=cfmail_sorted_contacts(); $currentSignatureKey=cfmail_signature_key($worker); $currentSignature=cfmail_signature_for($currentSignatureKey,$worker); $currentSignatureText=(string)($currentSignature['text']??'');
?>
<style>
body.mail-fullscreen{display:block!important;background:#d5d9de!important;min-height:100vh;overflow:hidden}body.mail-fullscreen .wrap{width:100%!important;max-width:none!important;padding:0!important;margin:0!important}body.mail-fullscreen .logo-top{display:none!important}
.rcmail-app,.rcmail-app *{box-sizing:border-box}.rcmail-app{height:100vh;width:100%;display:grid;grid-template-columns:70px 305px 460px minmax(0,1fr);grid-template-rows:50px minmax(0,1fr);background:#e5e8ec;color:#263238;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.35;overflow:hidden}.rc-rail{grid-column:1;grid-row:1 / span 2;background:#1d2a31;color:#fff;display:flex;flex-direction:column;align-items:stretch;min-height:0}.rc-rail a{color:#fff;text-decoration:none;height:74px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;border-left:4px solid transparent;font-size:12px;font-weight:700}.rc-rail a .ico{font-size:24px;line-height:1}.rc-rail a:hover,.rc-rail a.active{background:#253943;border-left-color:#ff6b1a}.rc-rail a.compose{color:#ff6b1a}.rc-rail-spacer{flex:1}.rc-folders{grid-column:2;grid-row:1 / span 2;background:#fff;border-right:1px solid #cfd6dd;display:flex;flex-direction:column;min-width:0;min-height:0}.rc-account{height:50px;display:flex;align-items:center;justify-content:space-between;padding:0 14px;border-bottom:1px solid #dce2e7;font-weight:700;color:#101820;flex:0 0 auto}.rc-account .dots{font-size:24px;color:#263238}.rc-worker{padding:10px 12px;border-bottom:1px solid #e4e9ee;background:#fafafa;flex:0 0 auto}.rc-worker form{display:flex;gap:6px;align-items:center}.rc-worker select,.rc-worker button,.rc-search input,.rc-search select,.rc-settings input,.rc-settings select,.rc-compose input,.rc-compose textarea,.rc-reply textarea,.rc-note textarea{border:1px solid #bcc6cf;border-radius:3px;padding:7px 9px;font-size:14px;background:#fff;color:#111}.rc-worker select{min-width:115px}.rc-worker button,.rc-btn{border:0;background:#eef1f4;border-radius:3px;padding:7px 10px;cursor:pointer;text-decoration:none;color:#18242c;display:inline-flex;align-items:center;gap:5px;font-weight:600}.rc-btn:hover,.rc-worker button:hover{background:#dfe5eb}.rc-btn.primary{background:#ff6b1a;color:#fff}.rc-btn.blue{background:#2f7ebc;color:#fff}.rc-btn.green{background:#19965c;color:#fff}.rc-btn.warn{background:#f5a524;color:#111}.rc-folder-list{margin:0;padding:0;list-style:none;overflow:auto;flex:1;min-height:0}.rc-folder-list a{display:flex;align-items:center;gap:9px;padding:9px 12px;color:#18242c;text-decoration:none;border-bottom:1px solid #eef1f4}.rc-folder-list a:hover,.rc-folder-list a.active{background:#fff3eb}.rc-folder-list .ficon{width:20px;text-align:center;font-size:17px}.rc-folder-list .badge{margin-left:auto;background:#169b91;color:#fff;border-radius:9px;min-width:22px;height:18px;padding:1px 6px;font-size:12px;text-align:center;font-weight:700}.rc-folder-list .muted{margin-left:auto;color:#7b8790;font-size:12px}.rc-folder-item{position:relative}.rc-folder-submenu{display:none;position:absolute;left:100%;top:0;min-width:170px;background:#fff;border:1px solid #dce2e7;box-shadow:0 8px 18px rgba(0,0,0,.12);z-index:20}.rc-folder-submenu a{display:block;padding:8px 10px;border-bottom:1px solid #eef1f4;white-space:nowrap;background:#fff}.rc-folder-submenu a:last-child{border-bottom:0}.rc-folder-item:hover .rc-folder-submenu{display:block}.rc-mainbar{grid-column:3 / span 2;grid-row:1;background:#f4f6f8;border-bottom:1px solid #cfd6dd;height:50px;display:flex;align-items:center;justify-content:space-between;padding:0 12px;gap:10px;min-width:0}.rc-toolbar{display:flex;align-items:center;gap:8px;min-width:0;flex-wrap:nowrap}.rc-tool{border:0;background:transparent;color:#18242c;text-decoration:none;display:inline-flex;flex-direction:column;align-items:center;justify-content:center;gap:1px;min-width:58px;height:46px;font-size:12px;cursor:pointer}.rc-tool:hover{background:#e7edf2}.rc-tool .ico{font-size:20px;line-height:1;color:#23323b}.rc-tool.orange .ico{color:#f05a1a}.rc-tool.danger .ico{color:#b91c1c}.rc-tool form{margin:0}.rc-search{display:flex;align-items:center;gap:8px;min-width:0}.rc-search input{width:280px;max-width:36vw}.rc-search select{width:140px}.rc-listpane{grid-column:3;grid-row:2;background:#fff;border-right:1px solid #cfd6dd;display:flex;flex-direction:column;min-width:0;min-height:0}.rc-list-head{height:44px;display:flex;align-items:center;padding:0 10px;border-bottom:1px solid #dce2e7;background:#fff;flex:0 0 auto}.rc-list-head input{width:100%;border:1px solid #cfd6dd;border-radius:3px;padding:8px 10px;font-size:14px}.rc-message-list{overflow:auto;flex:1;background:#fff;min-height:0}.rc-empty{padding:18px;color:#6b7780}.rc-row{display:block;text-decoration:none;color:#18242c;border-bottom:1px solid #e8edf1;padding:8px 10px 8px 28px;min-height:68px;position:relative}.rc-row:hover{background:#f7fafc}.rc-row.mark-orange{background:#fff7ed;box-shadow:inset 4px 0 0 #fb923c}.rc-row.mark-red{background:#fef2f2;box-shadow:inset 4px 0 0 #ef4444}.rc-row.mark-green{background:#f0fdf4;box-shadow:inset 4px 0 0 #22c55e}.rc-row.mark-orange:hover{background:#ffedd5}.rc-row.mark-red:hover{background:#fee2e2}.rc-row.mark-green:hover{background:#dcfce7}.rc-row.active{background:#fff3eb;border-left:3px solid #ff6b1a;padding-left:25px}.rc-row.active.mark-orange{background:#ffedd5}.rc-row.active.mark-red{background:#fee2e2}.rc-row.active.mark-green{background:#dcfce7}.rc-row:before{content:'';position:absolute;left:10px;top:18px;width:7px;height:7px;border-radius:50%;background:#b7e1eb}.rc-row.unread .from,.rc-row.unread .subject{font-weight:700}.rc-row.read{color:#334}.rc-row.read:before{background:transparent;border:1px solid transparent}.rc-row .top{display:flex;align-items:center;justify-content:space-between;gap:8px}.rc-row .from{font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.rc-row .date{font-size:12px;color:#64717a;white-space:nowrap}.rc-row .subject{font-size:14px;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.rc-row .preview{font-size:12px;margin-top:3px;color:#7a858d;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.rc-row .meta{position:absolute;right:8px;bottom:5px;display:flex;gap:5px}.rc-pill{border-radius:10px;padding:1px 7px;background:#e9edf2;color:#46525b;font-size:11px;font-weight:700}.rc-pill.new{background:#dbeafe;color:#1d4ed8}.rc-pill.done{background:#dcfce7;color:#166534}.rc-pill.inv{background:#ffedd5;color:#9a3412}.rc-pill.red{background:#fee2e2;color:#991b1b}.rc-process-badge{border-radius:10px;padding:1px 7px;font-size:11px;font-weight:800}.rc-process-badge.pc-orange{background:#fed7aa;color:#9a3412}.rc-process-badge.pc-red{background:#fecaca;color:#991b1b}.rc-process-badge.pc-green{background:#bbf7d0;color:#166534}.rc-process-tools{display:inline-flex;align-items:center;gap:5px;border-left:1px solid #d1d5db;margin-left:4px;padding-left:9px}.rc-process-tools .lbl{font-size:12px;font-weight:700;color:#46525b}.rc-color-btn{width:24px;height:24px;min-width:24px;border-radius:50%;border:1px solid rgba(0,0,0,.18);padding:0;cursor:pointer}.rc-color-btn.orange{background:#fb923c}.rc-color-btn.red{background:#ef4444}.rc-color-btn.green{background:#22c55e}.rc-color-clear{border:0;background:transparent;color:#66737c;cursor:pointer;font-size:18px;line-height:1}.rc-color-btn:hover,.rc-color-clear:hover{filter:brightness(.92)}.rc-detailpane{grid-column:4;grid-row:2;background:#fff;min-width:0;overflow:auto;min-height:0}.rc-detail-empty{padding:24px;color:#6b7780}.rc-subject-head{padding:14px 18px;border-bottom:1px solid #e2e8ef;background:#fff}.rc-subject-head h1{margin:0;color:#17222b;font-size:22px;line-height:1.25;font-weight:700}.rc-meta-grid{display:grid;grid-template-columns:90px minmax(0,1fr);gap:3px 10px;margin-top:12px;color:#46525b;font-size:13px}.rc-meta-grid b{color:#6f7a82}.rc-msg{border-bottom:1px solid #edf1f4;background:#fff}.rc-msg.out{background:#fbfffc}.rc-msg-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:10px 18px;background:#f7f9fb;border-bottom:1px solid #edf1f4}.rc-msg.out .rc-msg-head{background:#ecfdf3}.rc-msg-title{font-weight:700}.rc-msg-to{font-size:12px;color:#68747c;margin-top:2px}.rc-msg-date{font-size:12px;color:#68747c;white-space:nowrap}.rc-msg-body{padding:18px;line-height:1.45;font-size:14px;overflow:auto}.email-text{white-space:pre-wrap}.email-html{width:100%;overflow:auto;word-break:normal}.email-html img{max-width:100%!important;height:auto!important;display:inline-block}.email-html table{max-width:100%!important}.email-html a{color:#ea5b1a}.rc-att{display:flex;gap:8px;flex-wrap:wrap;margin-top:13px}.rc-attach{padding:6px 10px;border-radius:12px;background:#eef3ff;font-size:12px;font-weight:700;text-decoration:none;color:#193a72}.rc-attach:hover{background:#dbeafe}.worker-badge{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:2px 7px;background:color-mix(in srgb,var(--wcolor) 14%,white);color:#111827;font-size:11px;font-weight:900;border:1px solid color-mix(in srgb,var(--wcolor) 35%,#e5e7eb)}.worker-badge span{display:inline-flex;align-items:center;justify-content:center;width:17px;height:17px;border-radius:50%;background:var(--wcolor);color:#fff;font-size:10px}.mail-muted{color:#64748b}.rc-reply,.rc-note,.rc-history,.rc-compose,.rc-settings{margin:16px;border:1px solid #dce2e7;background:#fff;border-radius:3px}.rc-box-head{padding:10px 12px;border-bottom:1px solid #dce2e7;background:#f7f9fb;font-weight:700}.rc-box-body{padding:12px}.rc-templates{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:10px}.rc-reply textarea,.rc-note textarea,.rc-compose textarea{width:100%;min-height:145px}.rc-compose input{width:100%;margin-bottom:9px}.rc-compose textarea{min-height:260px}.rc-settings{grid-column:3 / span 2;grid-row:2;align-self:stretch;overflow:auto;min-height:0;margin:0}.rc-settings-grid{display:grid;grid-template-columns:repeat(2,minmax(260px,1fr));gap:12px}.rc-settings label{display:flex;flex-direction:column;font-size:12px;font-weight:700;gap:5px;color:#334155}.msg{grid-column:3 / span 2;grid-row:1;align-self:end;margin:0 0 -1px 0;padding:10px 14px;font-weight:700;z-index:3}.msg-ok{background:#dcfce7;color:#166534}.msg-error{background:#fee2e2;color:#991b1b}.rc-mobile-title{display:none}.rc-mobile-tabs{display:none}@media(max-width:1350px){.rcmail-app{grid-template-columns:64px 250px 360px minmax(0,1fr)}.rc-search input{width:190px}}@media(max-width:980px){body.mail-fullscreen{overflow:auto}.rcmail-app{height:auto;min-height:100vh;grid-template-columns:56px 1fr;grid-template-rows:auto auto auto auto;overflow:visible}.rc-rail{grid-column:1;grid-row:1 / span 4}.rc-folders{grid-column:2;grid-row:1;max-height:none;border-right:0;border-bottom:1px solid #cfd6dd}.rc-mainbar{grid-column:2;grid-row:2;position:sticky;top:0;z-index:4;flex-wrap:wrap;height:auto;padding:8px 10px}.rc-listpane{grid-column:2;grid-row:3;min-height:38vh;border-right:0;border-bottom:1px solid #cfd6dd}.rc-detailpane,.rc-settings{grid-column:2;grid-row:4;min-height:45vh}.rc-detailpane{overflow:visible}.rc-search{width:100%;justify-content:flex-end;flex-wrap:wrap}.rc-search input{width:100%;max-width:none}.rc-search select{width:100%}.rc-tool span:not(.ico){display:none}.rc-tool{min-width:38px;height:38px}.rc-toolbar{flex-wrap:wrap}.rc-reply,.rc-note,.rc-compose,.rc-settings{margin:10px}.rc-settings-grid{grid-template-columns:1fr}.msg{grid-column:2;grid-row:2;margin:0}.rc-mobile-title{display:block}.rc-row{min-height:62px;padding-right:8px}.rc-subject-head h1{font-size:18px}.rc-msg-head{padding:10px 12px}.rc-msg-body{padding:12px}}
.rc-row.bulk-selected{outline:2px solid #2563eb;outline-offset:-2px;background:#eff6ff!important}.rc-bulkbar{display:none;align-items:center;gap:8px;padding:7px 10px;background:#eaf2ff;border-bottom:1px solid #c7d8f5;font-size:13px}.rc-bulkbar.show{display:flex}.rc-bulkbar b{color:#1d4ed8}.rc-signature{grid-column:3 / span 2;grid-row:2;overflow:auto;min-height:0;background:#fff}.rc-signature-wrap{padding:16px;max-width:860px}.rc-signature textarea{width:100%;min-height:130px;border:1px solid #bcc6cf;border-radius:3px;padding:9px;font-size:14px}.rc-signature input,.rc-signature select{border:1px solid #bcc6cf;border-radius:3px;padding:7px 9px;font-size:14px;background:#fff}.rc-signature label{display:flex;flex-direction:column;gap:5px;margin:10px 0;font-weight:700;font-size:12px;color:#45515a}.rc-signature-preview{border:1px solid #dce2e7;background:#fafafa;padding:12px;margin-top:12px}.rc-signature-preview img{max-width:220px;height:auto;display:block;margin-top:8px}@media(max-width:980px){.rc-signature{grid-column:2;grid-row:4}.rc-bulkbar{flex-wrap:wrap}}

/* Zmenšení odpovědi a šablon */
.rc-reply{margin:10px 12px!important;border-radius:3px!important}
.rc-reply .rc-box-head{padding:8px 12px!important;font-size:14px!important}
.rc-reply .rc-box-body{padding:12px 12px!important}
.rc-reply .rc-templates{gap:6px!important;margin-bottom:10px!important}
.rc-reply .rc-templates .rc-btn{font-size:13px!important;padding:6px 10px!important;font-weight:600!important;line-height:1.2!important}
.rc-reply textarea{min-height:260px!important;font-size:14px!important;padding:10px!important}
.rc-reply p{margin:10px 0 0!important}
.rc-reply select{font-size:13px!important;padding:7px 9px!important}
.rc-reply .primary{padding:8px 12px!important;font-size:14px!important}
.rc-note{display:none!important}
</style>
<style>
.rc-contacts{grid-column:3 / span 2;grid-row:2;overflow:auto;min-height:0;background:#fff}.rc-contact-wrap{padding:16px;max-width:1050px}.rc-contact-grid{display:grid;grid-template-columns:1fr 1fr 1.4fr auto;gap:8px;align-items:end}.rc-contact-grid label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:#45515a}.rc-contact-table{width:100%;border-collapse:collapse;margin-top:14px;background:#fff}.rc-contact-table th,.rc-contact-table td{border-bottom:1px solid #e8edf1;padding:9px 7px;text-align:left}.rc-contact-table th{background:#f7f9fb;color:#45515a;font-size:12px}.rc-contact-hint{color:#68747c;font-size:13px;margin-top:7px}.rc-select-contact{margin-bottom:9px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}.rc-select-contact select{border:1px solid #bcc6cf;border-radius:3px;padding:7px 9px;font-size:14px;background:#fff;color:#111;min-width:260px}.rc-rail a.contacts .ico{font-size:22px}@media(max-width:980px){.rc-contacts{grid-column:2;grid-row:4}.rc-contact-grid{grid-template-columns:1fr}.rc-select-contact select{width:100%;min-width:0}.rc-contact-table{font-size:13px}.rc-contact-table th:nth-child(1),.rc-contact-table td:nth-child(1){display:none}}
</style>
<script>
document.body.classList.add('mail-fullscreen');
var cfmailSelected={};
var cfmailCurrentThreadId=<?= (int)$selectedThreadId ?>;
var cfmailSignatureText=<?=json_encode($currentSignatureText,JSON_UNESCAPED_UNICODE)?>;
function cfmailAppendSignature(el){if(!el||!cfmailSignatureText)return;if((el.value||'').trim()===''){el.value='\n\n'+cfmailSignatureText;}}
function cfmailTemplate(txt){var el=document.getElementById('reply_body')||document.getElementById('new_body');if(!el)return;el.value=txt;el.focus();try{el.dispatchEvent(new Event('input',{bubbles:true}));}catch(e){}}
function cfmailUseContact(v){var el=document.getElementById('new_to');if(!el||!v)return;el.value=v;el.focus();}
function cfmailSelectedIds(){return Object.keys(cfmailSelected).filter(function(k){return cfmailSelected[k];});}
function cfmailUpdateBulk(){var ids=cfmailSelectedIds();document.querySelectorAll('.bulk-ids').forEach(function(i){i.value=ids.join(',');});var b=document.getElementById('bulkbar');var c=document.getElementById('bulkcount');if(b&&c){c.textContent=ids.length;b.classList.toggle('show',ids.length>0);}document.querySelectorAll('.rc-row').forEach(function(r){r.classList.toggle('bulk-selected',!!cfmailSelected[r.getAttribute('data-thread-id')]);});}
function cfmailClearBulk(){cfmailSelected={};cfmailUpdateBulk();}
function cfmailDeleteSelected(){var ids=cfmailSelectedIds();if(ids.length===0&&cfmailCurrentThreadId>0)ids=[String(cfmailCurrentThreadId)];if(ids.length===0)return;var f=document.getElementById('cfmail-delete-form');if(!f)return;f.querySelector('[name=thread_id]').value=ids[0];f.querySelector('[name=selected_thread_ids]').value=ids.join(',');f.submit();}
document.addEventListener('click',function(e){var row=e.target.closest('.rc-row[data-thread-id]');if(row&&(e.ctrlKey||e.metaKey)){e.preventDefault();var id=row.getAttribute('data-thread-id');cfmailSelected[id]=!cfmailSelected[id];cfmailUpdateBulk();return;}var b=e.target.closest('[data-template]');if(!b)return;e.preventDefault();cfmailTemplate(b.getAttribute('data-template')||'');var r=document.getElementById('reply-panel');if(r) r.scrollIntoView({behavior:'smooth',block:'start'});});
document.addEventListener('keydown',function(e){var tag=(e.target&&e.target.tagName?e.target.tagName.toLowerCase():'');if(e.target&&(e.target.isContentEditable||tag==='input'||tag==='textarea'||tag==='select'))return;if(e.key==='Delete'||e.key==='Del'){e.preventDefault();cfmailDeleteSelected();}});
document.addEventListener('DOMContentLoaded',function(){cfmailAppendSignature(document.getElementById('new_body'));cfmailAppendSignature(document.getElementById('reply_body'));cfmailUpdateBulk();});
</script>
<div class="rcmail-app">
    <form id="cfmail-delete-form" method="post" style="display:none"><input type="hidden" name="cfmail_action" value="delete_thread"><input type="hidden" name="thread_id" value="<?= (int)$selectedThreadId ?>"><input type="hidden" name="selected_thread_ids" value=""></form>
    <nav class="rc-rail">
        <a class="compose <?= $showCompose?'active':'' ?>" href="index.php?view=mail&compose=1"><span class="ico">✎</span><span>Napsat</span></a>
        <a class="<?= !$showSettings&&!$showContacts&&!$showSignature?'active':'' ?>" href="index.php?view=mail"><span class="ico">✉</span><span>E-mail</span></a>
        <a href="index.php?view=mail&status=invoice_check"><span class="ico">📎</span><span>Faktury</span></a>
        <a class="contacts <?= $showContacts?'active':'' ?>" href="index.php?view=mail&contacts=1"><span class="ico">👥</span><span>Kontakty</span></a>
        <a class="<?= $showSignature?'active':'' ?>" href="index.php?view=mail&signature=1"><span class="ico">✒</span><span>Podpis</span></a>
        <span class="rc-rail-spacer"></span>
        <a class="<?= $showSettings?'active':'' ?>" href="index.php?view=mail&settings=1"><span class="ico">⚙</span><span>Nastavení</span></a>
        <a href="index.php"><span class="ico">←</span><span>Zpět</span></a>
    </nav>
    <aside class="rc-folders">
        <div class="rc-account"><span><?=h($settings['account_email'])?></span><span class="dots">⋮</span></div>
        <div class="rc-worker">
        <?php if($worker): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px"><?=cfmail_worker_badge($worker['key'])?><form method="post"><input type="hidden" name="cfmail_action" value="logout_worker"><button>Odhlásit</button></form></div>
        <?php else: ?>
            <form method="post"><input type="hidden" name="cfmail_action" value="login_worker"><select name="worker"><?php foreach($cfMailUsers as $k=>$u): ?><option value="<?=h($k)?>"><?=h($u['name'])?></option><?php endforeach; ?></select><button>Přihlásit</button></form>
        <?php endif; ?>
        </div>
        <ul class="rc-folder-list">
            <li><a class="<?=$mailboxFilter==='inbox'&&$statusFilter===''&&!$showSettings&&!$showCompose&&!$showContacts&&!$showSignature?'active':''?>" href="index.php?view=mail&mailbox=inbox"><span class="ficon">📥</span><span>Příchozí pošta</span><span class="badge"><?= (int)($counts['unread']??0) ?></span></a></li>
            <li><a class="<?=$showCompose?'active':''?>" href="index.php?view=mail&compose=1"><span class="ficon">✎</span><span>Nový e-mail</span></a></li>
            <li><a class="<?=$mailboxFilter==='sent'&&$statusFilter===''&&!$showSettings&&!$showCompose&&!$showContacts&&!$showSignature?'active':''?>" href="index.php?view=mail&mailbox=sent"><span class="ficon">📤</span><span>Odeslané e-maily</span><span class="muted"><?= (int)($counts['sent']??0) ?></span></a></li>
            <li><a class="<?=$showContacts?'active':''?>" href="index.php?view=mail&contacts=1"><span class="ficon">👥</span><span>Kontakty</span></a></li>
            <li class="rc-folder-item"><a class="<?=$showSignature?'active':''?>" href="index.php?view=mail&signature=1"><span class="ficon">✒</span><span>Podpis</span></a><div class="rc-folder-submenu"><a href="index.php?view=mail&signature=1#sig-text">Upravit text</a><a href="index.php?view=mail&signature=1#sig-image">Přidat obrázek</a></div></li>
            <li><a href="index.php?view=mail&status=new" class="<?=$statusFilter==='new'?'active':''?>"><span class="ficon">•</span><span>Nové</span><span class="muted"><?= (int)($counts['new']??0) ?></span></a></li>
            <li><a href="index.php?view=mail&status=invoice_check" class="<?=$statusFilter==='invoice_check'?'active':''?>"><span class="ficon">📎</span><span>Faktury ke kontrole</span><span class="muted"><?= (int)($counts['invoice_check']??0) ?></span></a></li>
            <li><a href="index.php?view=mail&status=accounting_approved" class="<?=$statusFilter==='accounting_approved'?'active':''?>"><span class="ficon">📁</span><span>Faktury pro účetní</span><span class="muted"><?= (int)($counts['accounting_approved']??0) ?></span></a></li>
            <li><a href="index.php?view=mail&status=done" class="<?=$statusFilter==='done'?'active':''?>"><span class="ficon">✅</span><span>Vyřízené</span><span class="muted"><?= (int)($counts['done']??0) ?></span></a></li>
            <li><a href="index.php?view=mail&status=complaint" class="<?=$statusFilter==='complaint'?'active':''?>"><span class="ficon">🔥</span><span>Reklamace</span><span class="muted"><?= (int)($counts['complaint']??0) ?></span></a></li>
            <li><a href="index.php?view=mail&status=exchange" class="<?=$statusFilter==='exchange'?'active':''?>"><span class="ficon">↔</span><span>Výměna</span><span class="muted"><?= (int)($counts['exchange']??0) ?></span></a></li>
            <li><a href="index.php?view=mail&status=return" class="<?=$statusFilter==='return'?'active':''?>"><span class="ficon">↩</span><span>Vrácení</span><span class="muted"><?= (int)($counts['return']??0) ?></span></a></li>
            <li><a href="index.php?view=mail&settings=1" class="<?=$showSettings?'active':''?>"><span class="ficon">⚙</span><span>Nastavení</span></a></li>
        </ul>
    </aside>
    <header class="rc-mainbar">
        <div class="rc-toolbar">
            <form method="post" style="display:inline"><input type="hidden" name="cfmail_action" value="sync_mail"><button class="rc-tool orange"><span class="ico">↻</span><span>Obnovit</span></button></form>
            <?php if($selectedThread && !$showCompose && !$showSettings): ?>
            <a class="rc-tool" href="#reply-panel"><span class="ico">↩</span><span>Odpovědět</span></a>
            <form method="post"><input type="hidden" name="cfmail_action" value="take_thread"><input type="hidden" name="thread_id" value="<?=$selectedThreadId?>"><input type="hidden" name="selected_thread_ids" class="bulk-ids" value=""><button class="rc-tool"><span class="ico">☝</span><span>Převzít</span></button></form>
            <form method="post"><input type="hidden" name="cfmail_action" value="mark_seen"><input type="hidden" name="thread_id" value="<?=$selectedThreadId?>"><input type="hidden" name="selected_thread_ids" class="bulk-ids" value=""><input type="hidden" name="seen" value="0"><button class="rc-tool"><span class="ico">●</span><span>Nepřečtené</span></button></form>
            <form method="post"><input type="hidden" name="cfmail_action" value="approve_accounting"><input type="hidden" name="thread_id" value="<?=$selectedThreadId?>"><input type="hidden" name="selected_thread_ids" class="bulk-ids" value=""><button class="rc-tool"><span class="ico">🏷</span><span>Účetní</span></button></form>
            <form method="post"><input type="hidden" name="cfmail_action" value="move_done"><input type="hidden" name="thread_id" value="<?=$selectedThreadId?>"><input type="hidden" name="selected_thread_ids" class="bulk-ids" value=""><button class="rc-tool"><span class="ico">✓</span><span>Vyřízeno</span></button></form>
            <button type="button" class="rc-tool danger" onclick="cfmailDeleteSelected()" title="Smazat vybraný e-mail klávesou Delete"><span class="ico">🗑</span><span>Smazat</span></button>
            <div class="rc-process-tools" title="Barevné označení Zpracovat">
                <span class="lbl">Zpracovat</span>
                <form method="post"><input type="hidden" name="cfmail_action" value="mark_process"><input type="hidden" name="thread_id" value="<?=$selectedThreadId?>"><input type="hidden" name="selected_thread_ids" class="bulk-ids" value=""><input type="hidden" name="process_color" value="orange"><button class="rc-color-btn orange" title="Oranžová"></button></form>
                <form method="post"><input type="hidden" name="cfmail_action" value="mark_process"><input type="hidden" name="thread_id" value="<?=$selectedThreadId?>"><input type="hidden" name="selected_thread_ids" class="bulk-ids" value=""><input type="hidden" name="process_color" value="red"><button class="rc-color-btn red" title="Červená"></button></form>
                <form method="post"><input type="hidden" name="cfmail_action" value="mark_process"><input type="hidden" name="thread_id" value="<?=$selectedThreadId?>"><input type="hidden" name="selected_thread_ids" class="bulk-ids" value=""><input type="hidden" name="process_color" value="green"><button class="rc-color-btn green" title="Zelená"></button></form>
                <form method="post"><input type="hidden" name="cfmail_action" value="mark_process"><input type="hidden" name="thread_id" value="<?=$selectedThreadId?>"><input type="hidden" name="selected_thread_ids" class="bulk-ids" value=""><input type="hidden" name="process_color" value=""><button class="rc-color-clear" title="Zrušit označení">×</button></form>
            </div>
            <?php endif; ?>
        </div>
        <form class="rc-search" method="get">
            <input type="hidden" name="view" value="mail"><input type="hidden" name="status" value="<?=h($statusFilter)?>"><input type="hidden" name="mailbox" value="<?=h($mailboxFilter)?>">
            <input name="q" value="<?=h($q)?>" placeholder="Hledat...">
            <select name="worker"><option value="">Všichni</option><?php foreach($cfMailUsers as $k=>$u): ?><option value="<?=h($k)?>" <?=$workerFilter===$k?'selected':''?>><?=h($u['name'])?></option><?php endforeach; ?></select>
            <button class="rc-btn">Hledat</button>
        </form>
    </header>
    <?php if($flash): ?><div class="msg <?=$flashType==='error'?'msg-error':'msg-ok'?>"><?=h($flash)?></div><?php endif; ?>

<?php if($showSettings): ?>
    <section class="rc-settings">
        <div class="rc-box-head">Nastavení Cfloat MAIL</div>
        <div class="rc-box-body">
        <form method="post"><input type="hidden" name="cfmail_action" value="save_settings"><div class="rc-settings-grid">
        <label>E-mail schránky<input name="account_email" value="<?=h($settings['account_email'])?>"></label><label>Odesílatel<input name="from_name" value="<?=h($settings['from_name'])?>"></label><label>Reply-To<input name="reply_to" value="<?=h($settings['reply_to'])?>"></label><label>Načítat dní zpět<input type="number" name="sync_days" value="<?=h($settings['sync_days'])?>"></label><label>Limit zpráv / složka<input type="number" name="sync_limit" value="<?=h($settings['sync_limit'])?>"></label>
        <label>IMAP server<input name="imap_host" value="<?=h($settings['imap_host'])?>"></label><label>IMAP port<input name="imap_port" value="<?=h($settings['imap_port'])?>"></label><label>IMAP flags<input name="imap_flags" value="<?=h($settings['imap_flags'])?>"></label><label>IMAP uživatel<input name="imap_user" value="<?=h($settings['imap_user'])?>"></label><label>IMAP heslo<input type="password" name="imap_pass" placeholder="nechat prázdné = neměnit"></label>
        <label>SMTP server<input name="smtp_host" value="<?=h($settings['smtp_host'])?>"></label><label>SMTP port<input name="smtp_port" value="<?=h($settings['smtp_port'])?>"></label><label>SMTP zabezpečení<input name="smtp_secure" value="<?=h($settings['smtp_secure'])?>"></label><label>SMTP uživatel<input name="smtp_user" value="<?=h($settings['smtp_user'])?>"></label><label>SMTP heslo<input type="password" name="smtp_pass" placeholder="nechat prázdné = neměnit"></label>
        <?php foreach(['inbox'=>'INBOX','sent'=>'Odeslané','done'=>'Vyřízené','accounting_review'=>'Faktury ke kontrole','accounting_approved'=>'Faktury pro účetní','trash'=>'Koš'] as $k=>$lab): ?><label>Složka <?=$lab?><input name="folder_<?=h($k)?>" value="<?=h($settings['folders'][$k]??'')?>"></label><?php endforeach; ?>
        <label style="grid-column:1/-1">Klíčová slova pro faktury<input name="invoice_keywords" value="<?=h($settings['invoice_keywords'])?>"></label></div><p><button class="rc-btn primary">Uložit nastavení</button> <a class="rc-btn" href="index.php?view=mail">Zpět do MAIL</a></p></form>
        </div>
    </section>
<?php elseif($showContacts): ?>
    <section class="rc-contacts">
        <div class="rc-contact-wrap">
            <h2 style="margin:0 0 6px">Kontakty</h2>
            <div class="rc-contact-hint">Kontakty se budou nabízet při psaní nového e-mailu. Navíc se automaticky nabízí i adresy, na které často píšete.</div>
            <div class="rc-compose" style="margin:14px 0 0"><div class="rc-box-head">Přidat kontakt</div><div class="rc-box-body">
                <form method="post" class="rc-contact-grid">
                    <input type="hidden" name="cfmail_action" value="add_contact">
                    <label>Jméno<input name="contact_first" placeholder="Jméno"></label>
                    <label>Příjmení<input name="contact_last" placeholder="Příjmení"></label>
                    <label>E-mailová adresa<input name="contact_email" placeholder="email@domena.cz" required></label>
                    <button class="rc-btn primary">Přidat</button>
                </form>
            </div></div>
            <table class="rc-contact-table">
                <thead><tr><th>Jméno</th><th>E-mail</th><th>Akce</th></tr></thead><tbody>
                <?php if(!$savedContacts): ?><tr><td colspan="3" class="mail-muted">Zatím nejsou uložené žádné ruční kontakty.</td></tr><?php endif; ?>
                <?php foreach($savedContacts as $c): $email=cfmail_clean_email((string)($c['email']??'')); $name=trim((string)($c['first_name']??'').' '.(string)($c['last_name']??'')); ?>
                    <tr><td><?=h($name!==''?$name:'—')?></td><td><?=h($email)?></td><td><form method="post" onsubmit="return confirm('Smazat kontakt?')"><input type="hidden" name="cfmail_action" value="delete_contact"><input type="hidden" name="contact_id" value="<?= (int)($c['id']??0) ?>"><button class="rc-btn">Smazat</button></form></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="rc-compose" style="margin:18px 0 0"><div class="rc-box-head">TOP 8 nejčastějších adres</div><div class="rc-box-body">
                <?php if(!$frequentContacts): ?><div class="mail-muted">Zatím nejsou dostupné časté adresy.</div><?php else: ?>
                    <table class="rc-contact-table"><thead><tr><th>Kontakt</th><th>Použití</th></tr></thead><tbody>
                    <?php foreach($frequentContacts as $s): ?><tr><td><?=h($s['label']??$s['email'])?></td><td><?= (int)($s['count']??0) ?></td></tr><?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
            </div></div>
        </div>
    </section>
<?php elseif($showSignature): ?>
    <section class="rc-signature">
        <div class="rc-signature-wrap">
            <h2 style="margin:0 0 6px">Podpis</h2>
            <div class="rc-contact-hint">Podpis se automaticky přidá do nového e-mailu i do odpovědi. Každý přihlášený pracovník má svůj podpis. Bez přihlášení se použije výchozí podpis.</div>
            <form method="post" enctype="multipart/form-data" class="rc-compose" style="margin:14px 0 0">
                <div class="rc-box-head">Nastavení podpisu</div>
                <div class="rc-box-body">
                    <input type="hidden" name="cfmail_action" value="save_signature">
                    <label>Upravovaný podpis
                        <select name="signature_key">
                            <option value="default" <?=$currentSignatureKey==='default'?'selected':''?>>Výchozí / nepřihlášený</option>
                            <?php foreach($cfMailUsers as $k=>$u): ?><option value="<?=h($k)?>" <?=$currentSignatureKey===$k?'selected':''?>><?=h($u['name'])?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label id="sig-text">Text podpisu<textarea name="signature_text" placeholder="Např. Lukáš | C-Store.cz"><?=h($currentSignature['text']??'')?></textarea></label>
                    <label id="sig-image">Obrázek podpisu – URL<input name="signature_image_url" value="<?=h($currentSignature['image_url']??'')?>" placeholder="https://.../logo.png"></label>
                    <label>Nahrát obrázek podpisu<input type="file" name="signature_image" accept="image/png,image/jpeg,image/gif,image/webp"></label>
                    <?php if(!empty($currentSignature['image_file'])): ?><label style="display:block"><input type="checkbox" name="remove_signature_image" value="1"> Odebrat nahraný obrázek</label><?php endif; ?>
                    <p><button class="rc-btn primary">Uložit podpis</button></p>
                    <div class="rc-signature-preview"><b>Náhled:</b><br><?=nl2br(h($currentSignature['text']??''))?><?php $sigImg=cfmail_signature_image_url($currentSignature); if($sigImg!==''): ?><img src="<?=h($sigImg)?>" alt="Podpis"><?php endif; ?></div>
                </div>
            </form>
        </div>
    </section>
<?php else: ?>
    <section class="rc-listpane">
        <div class="rc-list-head"><form method="get" style="width:100%"><input type="hidden" name="view" value="mail"><input type="hidden" name="status" value="<?=h($statusFilter)?>"><input type="hidden" name="mailbox" value="<?=h($mailboxFilter)?>"><input name="q" value="<?=h($q)?>" placeholder="Hledat..."></form></div>
        <div class="rc-bulkbar" id="bulkbar"><b><span id="bulkcount">0</span> vybráno</b><span>Hromadně lze použít nahoře: Nepřečtené, Účetní, Vyřízeno, Zpracovat barvou.</span><button type="button" class="rc-btn" onclick="cfmailClearBulk()">Zrušit výběr</button></div>
        <div class="rc-message-list">
        <?php if(!$threads): ?><div class="rc-empty">Zatím nejsou načtené žádné e-maily. Klikni na <b>Obnovit</b>.</div><?php endif; ?>
        <?php foreach($threads as $t): $active=(int)$t['id']===$selectedThreadId; $unread=(int)($t['unread_count']??0)>0; $s=(string)($t['status']??'new'); $statusClass=$s==='done'?'done':(($s==='invoice_check'||$s==='accounting_approved')?'inv':($unread?'red':'new')); $preview=(string)($previewByThread[(int)$t['id']]['text']??''); $mark=cfmail_process_color((string)($t['process_color']??'')); ?>
            <a class="rc-row <?=$active?'active':''?> <?=$unread?'unread':'read'?> <?=$mark!==''?'mark-'.$mark:''?>" data-thread-id="<?=(int)$t['id']?>" href="index.php?view=mail&thread=<?=(int)$t['id']?>&markread=1&q=<?=urlencode($q)?>&status=<?=urlencode($statusFilter)?>&mailbox=<?=urlencode($mailboxFilter)?>&worker=<?=urlencode($workerFilter)?>">
                <div class="top"><div class="from"><?=h($t['last_from_email']??'')?></div><div class="date"><?=h(cfmail_date_short($t['last_message_at']??''))?></div></div>
                <div class="subject"><?=h($t['subject']?:'(bez předmětu)')?></div>
                <?php if($preview!==''): ?><div class="preview"><?=h($preview)?></div><?php endif; ?>
                <div class="meta"><?=cfmail_process_badge($mark)?><?php if(!empty($t['has_att'])): ?><span class="rc-pill">📎</span><?php endif; ?><?php if($unread): ?><span class="rc-pill red"><?= (int)$t['unread_count'] ?></span><?php endif; ?><?php if($s!=='new'): ?><span class="rc-pill <?=$statusClass?>"><?=h(cfmail_status_label($s))?></span><?php endif; ?></div>
            </a>
        <?php endforeach; ?>
        </div>
    </section>
    <section class="rc-detailpane">
    <?php if($showCompose): ?>
        <div class="rc-compose"><div class="rc-box-head">Nový e-mail</div><div class="rc-box-body"><div class="rc-templates"><?php foreach($cfMailTemplates as $tpl): $txt=str_replace('{signature}',$currentSignatureText,$tpl['text']); ?><button type="button" class="rc-btn" data-template="<?=h($txt)?>"><?=h($tpl['label'])?></button><?php endforeach; ?></div><form method="post"><input type="hidden" name="cfmail_action" value="send_new_email"><div class="rc-select-contact"><select onchange="cfmailUseContact(this.value);this.selectedIndex=0"><option value="">Vybrat z kontaktů / častých adres</option><?php foreach($contactSuggestions as $s): ?><option value="<?=h($s['email'])?>"><?=h($s['label'])?><?=($s['source']??'')==='frequent'?' – časté':''?></option><?php endforeach; ?></select><a class="rc-btn" href="index.php?view=mail&contacts=1">Přidat kontakt</a></div><input id="new_to" name="new_to" list="cfmail-contact-list" placeholder="Komu"><datalist id="cfmail-contact-list"><?php foreach($contactSuggestions as $s): ?><option value="<?=h($s['email'])?>"><?=h($s['label'])?></option><?php endforeach; ?></datalist><input name="new_subject" placeholder="Předmět"><textarea id="new_body" name="new_body" placeholder="Text e-mailu..."></textarea><p><button class="rc-btn primary">Odeslat nový e-mail</button> <a class="rc-btn" href="index.php?view=mail">Zrušit</a></p></form></div></div>
    <?php elseif(!$selectedThread): ?>
        <div class="rc-detail-empty">Vyber zprávu vlevo, nebo klikni na <b>Napsat</b>.</div>
    <?php else: ?>
        <div class="rc-subject-head"><h1><?=h($selectedThread['subject']?:'(bez předmětu)')?></h1><div class="rc-meta-grid"><b>Odesílatel</b><span><?=h($selectedThread['last_from_email']??'')?></span><b>Datum</b><span><?=h(cfmail_date_full($selectedThread['last_message_at']??''))?></span><b>Stav</b><span><span class="rc-pill <?=$statusClass??''?>"><?=h(cfmail_status_label($selectedThread['status']??'new'))?></span> <?=cfmail_process_badge((string)($selectedThread['process_color']??''))?></span><b>Řeší</b><span><?=cfmail_worker_badge($selectedThread['assigned_to']??'')?></span><?php if(!empty($selectedThread['order_number'])): ?><b>Objednávka</b><span><?=h($selectedThread['order_number'])?></span><?php endif; ?></div></div>
        <div class="rc-reply" id="reply-panel"><div class="rc-box-head">Odpověď</div><div class="rc-box-body"><div class="rc-templates"><?php foreach($cfMailTemplates as $tpl): $txt=str_replace('{signature}',$currentSignatureText,$tpl['text']); ?><button type="button" class="rc-btn" data-template="<?=h($txt)?>"><?=h($tpl['label'])?></button><?php endforeach; ?></div><form method="post"><input type="hidden" name="cfmail_action" value="send_reply"><input type="hidden" name="thread_id" value="<?=$selectedThreadId?>"><textarea id="reply_body" name="reply_body" placeholder="Napiš odpověď zákazníkovi..."></textarea><p><input type="hidden" name="after_send" value="open"><button class="rc-btn primary">Odeslat odpověď</button></p></form></div></div>
        <?php foreach($selectedMessages as $m): $atts=json_decode((string)($m['attachments_json']??'[]'),true); if(!is_array($atts))$atts=[]; ?>
            <article class="rc-msg <?=($m['direction']??'in')==='out'?'out':'in'?>"><div class="rc-msg-head"><div><div class="rc-msg-title"><?=($m['direction']??'in')==='out'?'Odesláno':'Přijato'?> <?=($m['direction']??'in')==='out'?cfmail_worker_badge($m['worker']??''):''?></div><div class="rc-msg-to"><?=h(($m['from_name']?:$m['from_email']).' → '.$m['to_email'])?></div></div><div class="rc-msg-date"><?=h(cfmail_date_full($m['date_at']??''))?></div></div><div class="rc-msg-body"><?=cfmail_render_message_body($m)?><?php if($atts): ?><div class="rc-att"><?php foreach($atts as $i=>$a): ?><a class="rc-attach" href="<?=h(cfmail_attachment_url($m,(int)$i,false))?>">📎 <?=h($a['filename']??'příloha')?> <?=!empty($a['size'])?'('.round(((int)$a['size'])/1024).' kB)':''?></a><?php endforeach; ?></div><?php endif; ?></div></article>
        <?php endforeach; ?>
        
        
    <?php endif; ?>
    </section>
<?php endif; ?>
</div>
