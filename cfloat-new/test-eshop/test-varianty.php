<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../../index.php');
    exit;
}

require_once __DIR__ . '/config-test.php';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$token = test_eshop_get_token();
$results = [];
$ran = false;

/**
 * Jedno testovací volání – vrací stručný výsledek.
 */
function tv_try(string $label, string $url, array $headers): array
{
    if (!function_exists('curl_init')) {
        return ['label' => $label, 'url' => $url, 'http' => 0, 'ok' => false, 'detail' => 'chybí cURL', 'headers_sent' => $headers];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array_merge($headers, ['Accept: application/ld+json']),
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $detail = '';
    if ($body === false) {
        $detail = 'cURL chyba: ' . $err;
    } else {
        $decoded = json_decode((string)$body, true);
        if (is_array($decoded)) {
            $detail = (string)($decoded['detail'] ?? $decoded['hydra:description'] ?? '');
            if ($detail === '' && isset($decoded['hydra:member'])) {
                $detail = 'OK – vráceno ' . count((array)$decoded['hydra:member']) . ' záznamů';
            }
        }
        if ($detail === '') $detail = substr((string)$body, 0, 160);
    }

    return [
        'label' => $label,
        'url' => $url,
        'http' => $http,
        'ok' => ($http >= 200 && $http < 300),
        'detail' => $detail,
        'headers_sent' => $headers,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run' && $token !== '') {
    @set_time_limit(180);
    $ran = true;

    $shopBase = rtrim(TEST_ESHOP_BASE_URL, '/');
    $apiBase = 'https://api.eshop-rychle.dev';
    $path = '/api-engine/order-states';

    // Rozklad tokenu na části (formát bývá "uuid:secret")
    $beforeColon = $token;
    $afterColon = '';
    if (strpos($token, ':') !== false) {
        [$beforeColon, $afterColon] = explode(':', $token, 2);
    }

    $variants = [
        ['X-AUTH-TOKEN, token tak jak je', $shopBase . $path, ['X-AUTH-TOKEN: ' . $token]],
        ['Bez tokenu (kontrolní test)', $shopBase . $path, []],
        ['X-AUTH-TOKEN jen část ZA dvojtečkou', $shopBase . $path, ['X-AUTH-TOKEN: ' . $afterColon]],
    ];

    foreach ($variants as [$label, $url, $headers]) {
        $results[] = tv_try($label, $url, $headers);
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Test variant tokenu – Eshop-rychle API</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: system-ui, sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; color:#1b1f23; }
.wrap { max-width:1000px; margin:0 auto; }
.card { background:#fff; border:1px solid #e7e9ec; border-radius:14px; padding:18px; margin-bottom:16px; }
.back-link { color:#666; font-size:12.5px; text-decoration:none; border:1px solid #e7e9ec; border-radius:999px; padding:6px 14px; display:inline-block; margin-bottom:14px; }
button { padding:11px 20px; border:none; border-radius:999px; background:linear-gradient(135deg,#24d84a,#00b52a); color:#fff; font-weight:800; font-size:14px; cursor:pointer; }
table { width:100%; border-collapse:collapse; font-size:12.5px; margin-top:10px; }
th { text-align:left; background:#f7f8f9; padding:8px; border-bottom:2px solid #e7e9ec; font-size:11px; text-transform:uppercase; color:#6b7280; }
td { padding:8px; border-bottom:1px solid #e7e9ec; vertical-align:top; }
tr.hit td { background:#eafbf0; font-weight:700; }
.badge-ok { background:#eafbf0; color:#0a7a34; padding:2px 9px; border-radius:999px; font-weight:800; font-size:11px; }
.badge-err { background:#fdeceb; color:#d93025; padding:2px 9px; border-radius:999px; font-weight:800; font-size:11px; }
code { background:#f7f8f9; padding:1px 5px; border-radius:4px; font-size:11.5px; word-break:break-all; }
.note { font-size:12.5px; color:#6b7280; line-height:1.5; }
</style>
</head>
<body>
<div class="wrap">
    <a class="back-link" href="index.php">&larr; Zpět na testovací modul</a>
    <h1 style="font-size:20px;">Test variant tokenu</h1>
    <p class="note">Vyzkouší stejný požadavek s různými způsoby odeslání tokenu. Cílem je najít, která varianta projde (HTTP 200) – podle toho pak upravím hlavní modul.</p>

    <?php if ($token === ''): ?>
        <div class="card" style="border-color:#f5c6c2;background:#fdeceb;color:#d93025;">
            Token není uložený. Nejdřív ho vlož na <a href="index.php">hlavní testovací stránce</a>.
        </div>
    <?php else: ?>
        <div class="card">
            <p class="note" style="margin-top:0;">
                Uložený token: délka <b><?php echo strlen($token); ?></b> znaků,
                začátek <code><?php echo h(substr($token, 0, 8)); ?>…</code>,
                konec <code>…<?php echo h(substr($token, -6)); ?></code>,
                obsahuje dvojtečku: <b><?php echo strpos($token, ':') !== false ? 'ano' : 'ne'; ?></b>
            </p>
            <form method="post">
                <input type="hidden" name="action" value="run">
                <button type="submit">Spustit test všech variant</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($ran): ?>
        <div class="card">
            <h2 style="font-size:15px;margin-top:0;">Výsledky</h2>
            <table>
                <thead>
                    <tr><th>Varianta</th><th>HTTP</th><th>Odpověď serveru</th></tr>
                </thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr class="<?php echo $r['ok'] ? 'hit' : ''; ?>">
                        <td>
                            <?php echo h($r['label']); ?>
                            <div style="font-size:10.5px;color:#9ca3af;margin-top:3px;"><?php echo h($r['url']); ?></div>
                        </td>
                        <td>
                            <?php if ($r['ok']): ?>
                                <span class="badge-ok"><?php echo (int)$r['http']; ?></span>
                            <?php else: ?>
                                <span class="badge-err"><?php echo (int)$r['http']; ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo h($r['detail']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $anyOk = false;
            foreach ($results as $r) { if ($r['ok']) { $anyOk = true; break; } }
            ?>
            <?php if ($anyOk): ?>
                <p class="note" style="margin-top:14px;color:#0a7a34;font-weight:700;">
                    ✓ Nějaká varianta prošla (zeleně zvýrazněná) – napiš mi která, a upravím podle ní hlavní modul.
                </p>
            <?php else: ?>
                <p class="note" style="margin-top:14px;">
                    Žádná varianta neprošla. Pokud i „Bez tokenu (kontrolní test)“ vrátil 401 se stejnou hláškou
                    <i>Invalid API token</i>, znamená to, že server odmítá dřív, než token vůbec porovná –
                    a chyba je tedy na straně Eshop-rychle (aktivace API pro tenhle konkrétní .dev e-shop),
                    ne v našem kódu. To je pak potřeba jim poslat jako důkaz.
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
