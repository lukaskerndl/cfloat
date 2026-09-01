<?php
declare(strict_types=1);

/**
 * VIP config
 * Primarne se snazi pouzit hlavni CFloat config z /www/config.php
 * Nic jineho mimo slozku VIP nemenime.
 */

$vipRootConfig = dirname(__DIR__) . '/config.php';
if (is_file($vipRootConfig)) {
    require_once $vipRootConfig; // ocekava vytvoreny $pdo
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    // Nouzova lokalni varianta - pokud by hlavni config nebyl dostupny.
    // Přístupové údaje se berou ze secrets/db_config.php (mimo dosah webu),
    // ne natvrdo z tohoto souboru.
    $__vipDbCfgFile = dirname(__DIR__) . '/secrets/db_config.php';
    $__vipDbCfg = is_file($__vipDbCfgFile) ? include $__vipDbCfgFile : [];
    $DB_HOST = $__vipDbCfg['host'] ?? '';
    $DB_NAME = $__vipDbCfg['name'] ?? '';
    $DB_USER = $__vipDbCfg['user'] ?? '';
    $DB_PASS = $__vipDbCfg['pass'] ?? '';
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
}

$VIP_TO_EMAIL = 'obchod@c-store.cz';
$VIP_ALLOWED_ORIGINS = [
    'https://www.c-store.cz',
    'https://c-store.cz',
    'https://cfloat.cz',
    'http://cfloat.cz',
];
