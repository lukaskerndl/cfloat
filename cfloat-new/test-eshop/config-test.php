<?php
declare(strict_types=1);

/**
 * Konfigurace TESTOVACÍHO (.dev) Eshop-rychle API Engine.
 * Naprosto oddělené od produkčního system.php / config.php – žádné volání
 * odsud se nikdy nedotkne ostré databáze ani ostrého e-shopu.
 *
 * Token si vyplň sám poté, co ho vygeneruješ v administraci
 * api.eshop-rychle.dev (Vytvořit nový přístup -> zobrazí se JEN JEDNOU).
 */

const TEST_ESHOP_BASE_URL = 'https://656127849.s11.eshop-rychle.dev';

/**
 * Token se ukládá do samostatného souboru (ne přímo v kódu), aby šel
 * pohodlně nastavit/změnit přes formulář na stránce, bez nutnosti sahat
 * na FTP. Soubor token.dat je chráněný přes .htaccess (Deny from all).
 */
function test_eshop_token_file(): string
{
    return __DIR__ . '/token.dat';
}

function test_eshop_get_token(): string
{
    $path = test_eshop_token_file();
    if (is_file($path)) {
        $t = trim((string)@file_get_contents($path));
        if ($t !== '') return $t;
    }
    return ''; // žádný natvrdo zapsaný token v kódu
}

function test_eshop_clean_token(string $token): string
{
    // Odstraní běžné neviditelné znaky, které se občas přilepí při kopírování
    // z webu (nedělitelná mezera, zero-width space, BOM), a pak normální trim.
    $token = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"], '', $token);
    return trim($token);
}

function test_eshop_save_token(string $token): bool
{
    $token = test_eshop_clean_token($token);
    if ($token === '') return false;
    return @file_put_contents(test_eshop_token_file(), $token, LOCK_EX) !== false;
}

function test_eshop_clear_token(): void
{
    $path = test_eshop_token_file();
    if (is_file($path)) @unlink($path);
}
