<?php
/**
 * _require_login.php
 *
 * Lehká ochrana pro interní endpointy, které NEMAJÍ vlastní přihlašovací
 * formulář, ale musí být dostupné jen přihlášenému uživateli:
 * štítky, tiskové exporty, statistiky, lookupy, QZ podpis atd.
 *
 * Na rozdíl od _auth_guard.php nevykresluje login formulář – jen ověří,
 * že už přihlášení proběhlo (přes index.php / login.php), a pokud ne,
 * požadavek slušně odmítne. Díky tomu se nerozbijí PDF ani JSON výstupy.
 *
 * Použití – jako první věc v souboru (hned za <?php):
 *   require_once __DIR__ . '/_require_login.php';           // soubor v /www
 *   require_once dirname(__DIR__) . '/_require_login.php';  // o úroveň níž
 *   require_once dirname(__DIR__, 2) . '/_require_login.php';
 *
 * Volitelně lze povolit i přístup přes cron token (pro skripty, které
 * spouští plánovaná úloha) – stačí před require nastavit:
 *   define('CFLOAT_ALLOW_CRON_TOKEN', true);
 */

if (!defined('CFLOAT_REQUIRE_LOGIN_OK')) {

    // --- 1) Sdílená session se stejným nastavením jako hlavní login ---
    if (session_status() === PHP_SESSION_NONE) {
        $__rlSecure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443)
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $__rlSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_start();
    }

    $__rlLoggedIn = !empty($_SESSION['logged_in']);

    // --- 2) Volitelně: povolit spuštění cronem přes token ---
    if (!$__rlLoggedIn && defined('CFLOAT_ALLOW_CRON_TOKEN') && CFLOAT_ALLOW_CRON_TOKEN) {
        if (php_sapi_name() === 'cli') {
            $__rlLoggedIn = true;
        } else {
            $__rlCandidates = [
                __DIR__ . '/secrets/cron_run_token.php',
                dirname(__DIR__) . '/secrets/cron_run_token.php',
                dirname(__DIR__, 2) . '/secrets/cron_run_token.php',
                dirname(__DIR__, 3) . '/secrets/cron_run_token.php',
            ];
            foreach ($__rlCandidates as $__c) {
                if (is_file($__c)) {
                    $__rlExpected = (string) include $__c;
                    $__rlGiven = (string)($_GET['token'] ?? '');
                    if ($__rlExpected !== '' && $__rlGiven !== '' && hash_equals($__rlExpected, $__rlGiven)) {
                        $__rlLoggedIn = true;
                    }
                    break;
                }
            }
        }
    }

    // --- 3) Odmítnutí nepřihlášeného požadavku ---
    if (!$__rlLoggedIn) {
        // Nevypisujeme nic, co by prozradilo strukturu aplikace.
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow');
        }
        echo "403 Forbidden\n";
        exit;
    }

    define('CFLOAT_REQUIRE_LOGIN_OK', true);
}
