<?php
/**
 * _cron_guard.php
 *
 * Sdílená ochrana pro skripty, které se spouští jako cron úloha přes veřejnou
 * URL (typické pro hosting Wedos bez SSH). Bez tohoto guardu šel skript spustit
 * kýmkoliv, kdo znal/uhodl URL adresu.
 *
 * Použití na začátek chráněného skriptu:
 *   require_once __DIR__ . '/_cron_guard.php';               // skript přímo v /www
 *   require_once __DIR__ . '/../../_cron_guard.php';         // skript v podsložce (uprav dle hloubky)
 *
 * Pravidla:
 *  - Spuštění přes php-cli (skutečný cron na serveru) -> vždy povoleno, token se nekontroluje.
 *  - Spuštění přes web (URL) -> vyžaduje ?token=... odpovídající secrets/cron_run_token.php.
 *  - Pokud je skript vložen (include/require) z jiného skriptu, který už guard úspěšně
 *    prošel v rámci stejného requestu, další kontrola se přeskočí (CFLOAT_CRON_GUARD_OK).
 */

if (!defined('CFLOAT_CRON_GUARD_OK')) {
    if (php_sapi_name() !== 'cli') {
        // Najdi secrets/cron_run_token.php – zkusíme několik obvyklých hloubek,
        // protože tento guard může být require-ovaný z www/ i z www/Dodavatele/Vavrys/.
        $__cronTokenCandidates = [
            __DIR__ . '/secrets/cron_run_token.php',
            dirname(__DIR__) . '/secrets/cron_run_token.php',
            dirname(__DIR__, 2) . '/secrets/cron_run_token.php',
            dirname(__DIR__, 3) . '/secrets/cron_run_token.php',
        ];
        $__cronTokenFile = null;
        foreach ($__cronTokenCandidates as $__c) {
            if (is_file($__c)) {
                $__cronTokenFile = $__c;
                break;
            }
        }

        $__expectedToken = $__cronTokenFile ? (string)(include $__cronTokenFile) : '';
        $__givenToken = (string)($_GET['token'] ?? '');

        if ($__expectedToken === '' || $__givenToken === '' || !hash_equals($__expectedToken, $__givenToken)) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(403);
            echo "403 Forbidden\n";
            exit;
        }
    }

    define('CFLOAT_CRON_GUARD_OK', true);
}
