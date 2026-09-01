<?php
// config.php – napojení na databázi cfloat
//
// Citlivé údaje (DB, Vavrys B2B, OpenAI klíč) už NEJSOU natvrdo v tomto souboru,
// ale ve složce secrets/, která je z webu úplně nepřístupná (viz secrets/.htaccess).

$__cfloatSecrets = __DIR__ . '/secrets';

$__dbCfg = is_file($__cfloatSecrets . '/db_config.php') ? include $__cfloatSecrets . '/db_config.php' : [];
$DB_HOST = $__dbCfg['host'] ?? '';
$DB_NAME = $__dbCfg['name'] ?? '';
$DB_USER = $__dbCfg['user'] ?? '';
$DB_PASS = $__dbCfg['pass'] ?? '';

$dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    // Zpráva o chybě se nezobrazuje veřejně (nesmí prozradit detaily o DB útočníkovi).
    error_log('Chyba připojení k databázi (config.php): ' . $e->getMessage());
    die('Chyba připojení k databázi. Zkuste to prosím později.');
}


// -------------------------
// VAVRYS B2B (ExportService)
// -------------------------
$__vavrysCfg = is_file($__cfloatSecrets . '/vavrys_b2b.php') ? include $__cfloatSecrets . '/vavrys_b2b.php' : [];
$VAVRYS_BASE_URL = $VAVRYS_BASE_URL ?? ($__vavrysCfg['base_url'] ?? 'https://b2b.vavrys.cz');
$VAVRYS_LOGIN    = $VAVRYS_LOGIN    ?? ($__vavrysCfg['login'] ?? '');
$VAVRYS_PASSWORD = $VAVRYS_PASSWORD ?? ($__vavrysCfg['password'] ?? '');

// OpenAI API klíč (doporučeno přes proměnnou prostředí; jinak z secrets/openai_api_key.php)
if (!defined('OPENAI_API_KEY')) {
    // Varianta A (doporučeno): nastav na hostingu env proměnnou OPENAI_API_KEY
    $k = getenv('OPENAI_API_KEY');
    if ($k !== false && $k !== '') {
        define('OPENAI_API_KEY', $k);
    } elseif (is_file($__cfloatSecrets . '/openai_api_key.php')) {
        define('OPENAI_API_KEY', (string) include $__cfloatSecrets . '/openai_api_key.php');
    } else {
        define('OPENAI_API_KEY', '');
    }
}
