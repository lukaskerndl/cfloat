<?php
declare(strict_types=1);

/**
 * cfloat-new/schindler/import.php
 *
 * Krok 4: přehled vybraných produktů, výběr CÍLOVÉ eshopové kategorie a
 * číselníku (dialu) pro velikosti, náhled payloadu, který by se odeslal,
 * a bezpečný test na TESTOVACÍM (.dev) e-shopu.
 *
 * DŮLEŽITÉ: Skutečný zápis do OSTRÉHO produkčního e-shopu tahle stránka
 * záměrně NEDĚLÁ. Přesný formát POST /api-engine/products není ověřený
 * zápisem (jen z dokumentace/entrypointu) - viz otevřené otázky v podkladu.
 * Nejdřív je potřeba přes tlačítko "Test na .dev shopu" ověřit, že payload
 * projde, a teprve pak se sem doplní ostré volání.
 */

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../../index.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Vykreslí výsledek schindler_import_full_product() - použito jak pro ostrý import, tak pro .dev test. */
function schindler_render_full_import_log(array $log): void
{
    ?>
    <div style="margin-top:16px;">
        <p style="font-weight:700;">Výsledek importu – <?php echo h($log['name']); ?><?php if (!empty($log['target'])): ?> <span style="font-weight:400;color:var(--muted);">(<?php echo h($log['target']); ?>)</span><?php endif; ?></p>
        <p>Založení produktu: <b style="color:<?php echo $log['product']['ok'] ? '#0a7a34' : '#d93025'; ?>;">HTTP <?php echo (int)$log['product']['http']; ?></b></p>
        <pre><?php echo h(json_encode($log['product']['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); ?></pre>

        <?php if (isset($log['collector_row'])): ?>
            <p>Sběrný řádek (<code><?php echo h($log['collector_row']['number']); ?></code>): <b style="color:<?php echo $log['collector_row']['ok'] ? '#0a7a34' : '#d93025'; ?>;"><?php echo $log['collector_row']['ok'] ? 'založen' : 'NEnalezen v odpovědi'; ?></b></p>
        <?php endif; ?>

        <?php if (isset($log['category_link'])): ?>
            <p>Zařazení do kategorie: <b style="color:<?php echo $log['category_link']['ok'] ? '#0a7a34' : '#d93025'; ?>;">HTTP <?php echo (int)$log['category_link']['http']; ?></b></p>
            <?php if (!$log['category_link']['ok']): ?>
                <pre><?php echo h(json_encode($log['category_link']['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); ?></pre>
            <?php endif; ?>
        <?php elseif ($log['product']['ok']): ?>
            <p style="color:var(--danger);font-size:12.5px;">⚠ Zařazení do kategorie se vůbec nezkusilo (chybí cílová kategorie?).</p>
        <?php endif; ?>

        <?php if (isset($log['images'])): ?>
            <p style="font-weight:700;margin-top:12px;">Fotky (<?php echo count($log['images']); ?>):</p>
            <table class="sel">
                <thead><tr><th>Pozice</th><th>URL</th><th>HTTP</th></tr></thead>
                <tbody>
                <?php foreach ($log['images'] as $img): ?>
                    <tr>
                        <td style="white-space:nowrap;"><b><?php echo (int)($img['position'] ?? 0); ?></b> <span style="color:var(--muted);font-size:11px;"><?php echo h($img['role'] ?? ''); ?></span></td>
                        <td style="max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo h($img['url']); ?></td>
                        <td><b style="color:<?php echo $img['ok'] ? '#0a7a34' : '#d93025'; ?>;"><?php echo (int)$img['http']; ?></b></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php $anyImgFail = false; foreach ($log['images'] as $img) { if (!$img['ok']) { $anyImgFail = true; break; } } ?>
            <?php if ($anyImgFail): ?>
                <details style="margin-top:8px;">
                    <summary style="cursor:pointer;font-size:12px;color:var(--muted);">Zobrazit chybové odpovědi u fotek</summary>
                    <?php foreach ($log['images'] as $img): if ($img['ok']) continue; ?>
                        <pre><?php echo h(json_encode($img['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); ?></pre>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($log['items'])): ?>
            <p style="font-weight:700;margin-top:12px;">Varianty:</p>
            <table class="sel">
                <thead><tr><th>Kód</th><th>Velikost</th><th>HTTP</th><th>Poznámka</th></tr></thead>
                <tbody>
                <?php foreach ($log['items'] as $il): ?>
                    <tr>
                        <td><?php echo h($il['code']); ?></td>
                        <td><?php echo h($il['size']); ?></td>
                        <td><b style="color:<?php echo $il['ok'] ? '#0a7a34' : '#d93025'; ?>;"><?php echo (int)$il['http']; ?></b></td>
                        <td style="font-size:11px;color:var(--muted);"><?php echo h($il['note']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <details style="margin-top:10px;">
                <summary style="cursor:pointer;font-size:12px;color:var(--muted);">Zobrazit plné JSON odpovědi pro každou variantu</summary>
                <?php foreach ($log['items'] as $il): ?>
                    <p style="font-size:11.5px;font-weight:700;margin:8px 0 2px;"><?php echo h($il['code']); ?></p>
                    <pre><?php echo h(json_encode(['sent' => $il['sent'], 'response' => $il['body']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); ?></pre>
                <?php endforeach; ?>
            </details>
        <?php elseif ($log['product']['ok']): ?>
            <p style="color:var(--danger);font-size:12.5px;">Produkt se založil, ale žádná varianta se ani nezkusila poslat (zkontroluj log výše).</p>
        <?php endif; ?>
    </div>
    <?php
}

require_once __DIR__ . '/lib/schindler_feed.php';
require_once __DIR__ . '/lib/schindler_eshop_api.php';

if (!isset($_SESSION['schindler_selected'])) $_SESSION['schindler_selected'] = [];

// ---------------------------------------------------------------------------
// Akce
// ---------------------------------------------------------------------------
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove') {
    $gid = (string)($_POST['gid'] ?? '');
    unset($_SESSION['schindler_selected'][$gid]);
    $flash = ['ok' => true, 'msg' => 'Produkt odebrán z výběru.'];
}

$selectedGids = array_keys($_SESSION['schindler_selected']);
$selectedGroups = [];
foreach ($selectedGids as $gid) {
    $g = schindler_load_group($gid);
    if ($g !== null) $selectedGroups[$gid] = $g;
}

// Kategorie a číselníky - jen čtení z produkce, bezpečné
$categories = schindler_fetch_categories();
$dials = schindler_fetch_dials();
$prodCfgOk = schindler_prod_api_config() !== null;

$chosenCategoryIri = (string)($_POST['target_category'] ?? $_GET['target_category'] ?? $_SESSION['schindler_target_category'] ?? '');
$chosenDialIri = (string)($_POST['target_dial'] ?? $_GET['target_dial'] ?? $_SESSION['schindler_target_dial'] ?? '');

// Zapamatovat volbu kategorie/číselníku v session, ať se nemusí vybírat
// pořád dokola při každém kroku (test na .dev, náhled, ostrý import...).
// Prázdná volba (uživatel vybral "— vyber kategorii —") se NEUKLÁDÁ jako
// vymazání předchozí volby - jen se při skutečné akci ignoruje, viz níže.
if ($chosenCategoryIri !== '') $_SESSION['schindler_target_category'] = $chosenCategoryIri;
if ($chosenDialIri !== '') $_SESSION['schindler_target_dial'] = $chosenDialIri;

// Sleva z MOC s DPH v procentech - naše prodejní cena = MOC - X %.
// Pamatuje se v session, ať se nemusí zadávat u každého kroku znovu.
$priceDiscountPct = isset($_POST['price_discount']) ? (float)str_replace(',', '.', (string)$_POST['price_discount'])
    : (float)($_SESSION['schindler_price_discount'] ?? 0);
if ($priceDiscountPct < 0) $priceDiscountPct = 0;
if ($priceDiscountPct > 95) $priceDiscountPct = 95;
$_SESSION['schindler_price_discount'] = $priceDiscountPct;

$dialValueMap = [];
$dialDiag = null;
if ($chosenDialIri !== '') {
    $dialDiag = schindler_fetch_dial_values_detailed($chosenDialIri);
    $dialValueMap = $dialDiag['map'];
}

// Náhled payloadu (dry run - nic se nikam neodesílá)
$previewPayloads = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    $previewPayloads = [];
    foreach ($selectedGroups as $gid => $g) {
        $previewPayloads[$gid] = schindler_build_product_payload($g, $chosenCategoryIri, $dialValueMap, true, true, $priceDiscountPct);
        foreach ($previewPayloads[$gid]['product']['productItemList'] as $idx => $it) {
            $previewPayloads[$gid]['product']['productItemList'][$idx] = schindler_strip_internal_fields($it);
        }
    }
}

// Import CELÉHO produktu (Product + všechny ProductItem varianty) do OSTRÉHO
// produkčního eshopu. Bere jen PRVNÍ vybraný produkt (bezpečnostní opatření -
// dokud si nejsme jistí formátem, netestujeme to na víc kusech najednou).
$testResult = null;
$fullImportLog = null;
$prodImportError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_prod_full') {
    if (empty($_POST['confirm_prod'])) {
        $prodImportError = 'Chybí potvrzovací zaškrtnutí - nic se neposlalo.';
    } else {
        $prodCfg = schindler_prod_api_config();
        if ($prodCfg === null) {
            $prodImportError = 'Nepodařilo se načíst produkční API config (secrets/eshop_new_api.php).';
        } elseif (empty($selectedGroups)) {
            $prodImportError = 'Není vybraný žádný produkt k importu.';
        } elseif ($chosenCategoryIri === '') {
            $prodImportError = 'Nejdřív vyber cílovou eshopovou kategorii.';
        } else {
            $firstGid = array_key_first($selectedGroups);
            $baseUrl = rtrim((string)$prodCfg['base_url'], '/');
            $token = (string)$prodCfg['token'];
            $groupToImport = $selectedGroups[$firstGid];

            // Bezpečnostní pojistka PROTI DUPLICITĚ - než cokoliv založíme,
            // zkontrolujeme, jestli některý z kódů produktu už v eshopu
            // neexistuje (např. kvůli dvojkliku nebo obnovení stránky po
            // odeslání). Pokud ano, radši nic nezaložíme, než abychom
            // vytvořili duplicitu - uživatel musí zaškrtnout "i přesto
            // založit", pokud to fakt chce (např. záměrně druhá varianta).
            $codes = array_map(fn($it) => $it['code'], $groupToImport['items']);
            $existing = schindler_lookup_existing_by_numbers($baseUrl, $token, $codes);

            if (!empty($existing) && empty($_POST['force_despite_duplicate'])) {
                $prodImportError = 'STOP - kódy [' . implode(', ', array_keys($existing)) . '] už v eshopu existují. Nic se nezaložilo, aby nevznikla duplicita. Pokud to i přesto chceš založit, zaškrtni "i přesto založit" a odešli znovu.';
            } else {
                $fullImportLog = schindler_import_full_product($baseUrl, $token, $groupToImport, $chosenCategoryIri, $chosenDialIri, $dialValueMap, !empty($_POST['include_images']), !empty($_POST['hide_out_of_stock']), $priceDiscountPct, !empty($_POST['upload_images_as_files']));
                $fullImportLog['name'] = $groupToImport['name'];
            }
        }
    }
}

// Test proti .dev shopu (bere jen PRVNÍ vybraný produkt, jen POST/POST po potvrzení)
// Test na TESTOVACÍM (.dev) shopu - používá STEJNOU funkci jako ostrý import
// (schindler_import_full_product), jen s .dev přihlašovacími údaji, ať se
// logika (rozdělení první/zbylé položky, kategorie, fotky) neduplikuje a
// neodchyluje se od toho, co skutečně běží na ostro.
$devTestError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_dev_shop') {
    $testEshopConfigPath = __DIR__ . '/../test-eshop/config-test.php';
    if (!is_file($testEshopConfigPath)) {
        $devTestError = 'Chybí cfloat-new/test-eshop/config-test.php.';
    } else {
        require_once $testEshopConfigPath;
        $devToken = test_eshop_get_token();
        if ($devToken === '') {
            $devTestError = 'V test-eshop modulu není uložený žádný token. Nastav ho tam nejdřív.';
        } elseif (empty($selectedGroups)) {
            $devTestError = 'Není vybraný žádný produkt k otestování.';
        } else {
            $firstGid = array_key_first($selectedGroups);
            $fullImportLog = schindler_import_full_product(TEST_ESHOP_BASE_URL, $devToken, $selectedGroups[$firstGid], $chosenCategoryIri, $chosenDialIri, $dialValueMap, !empty($_POST['include_images']), !empty($_POST['hide_out_of_stock']), $priceDiscountPct, !empty($_POST['upload_images_as_files']));
            $fullImportLog['name'] = $selectedGroups[$firstGid]['name'];
            $fullImportLog['target'] = 'TEST (.dev)';
        }
    }
}

// --- Založení chybějících hodnot do vybraného číselníku ---------------------
// Zakládá VÝHRADNĚ hodnoty, které potřebují právě vybrané produkty, a jen do
// číselníku, který uživatel vybral. Nic navíc, nic jinam.
$dialCreateLog = null;
$dialCreateError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_missing_dial_values') {
    if ($chosenDialIri === '') {
        $dialCreateError = 'Nejdřív vyber číselník, do kterého se mají hodnoty založit.';
    } elseif (empty($_POST['confirm_dial'])) {
        $dialCreateError = 'Chybí potvrzovací zaškrtnutí - nic se nezaložilo.';
    } else {
        $prodCfg = schindler_prod_api_config();
        if ($prodCfg === null) {
            $dialCreateError = 'Nepodařilo se načíst produkční API config.';
        } else {
            $toCreate = $_POST['missing_size'] ?? [];
            if (!is_array($toCreate) || empty($toCreate)) {
                $dialCreateError = 'Nebyla zaškrtnutá žádná hodnota k založení.';
            } else {
                $dialCreateLog = schindler_create_dial_values(
                    rtrim((string)$prodCfg['base_url'], '/'),
                    (string)$prodCfg['token'],
                    $chosenDialIri,
                    $toCreate,
                    schindler_max_dial_order($chosenDialIri) + 10,
                    $dialValueMap
                );
                // Po založení znovu načíst mapu, ať je hned aktuální
                $dialDiag = schindler_fetch_dial_values_detailed($chosenDialIri);
                $dialValueMap = $dialDiag['map'];
            }
        }
    }
}

// --- HROMADNÝ import po DÁVKÁCH -------------------------------------------
// Wedos (a sdílené hostingy obecně) utnou dlouho běžící PHP požadavek chybou
// 503. Import proto běží po malých dávkách: zpracuje se pár produktů, uloží
// se postup do session a stránka se sama přesměruje na další dávku. Každý
// jednotlivý požadavek je tak krátký a nespadne.
$bulkLog = null;
$bulkError = null;

// Zahájení nového dávkového běhu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_bulk') {
    if (empty($_POST['confirm_bulk'])) {
        $bulkError = 'Chybí potvrzovací zaškrtnutí - nic se neposlalo.';
    } elseif ($chosenCategoryIri === '') {
        $bulkError = 'Nejdřív vyber cílovou eshopovou kategorii.';
    } elseif (empty($selectedGroups)) {
        $bulkError = 'Není vybraný žádný produkt.';
    } else {
        $_SESSION['schindler_bulk'] = [
            'queue' => array_keys($selectedGroups),
            'done' => [],
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'category' => $chosenCategoryIri,
            'dial' => $chosenDialIri,
            'include_images' => !empty($_POST['include_images']),
            'skip_existing' => !empty($_POST['skip_existing']),
            'hide_out_of_stock' => !empty($_POST['hide_out_of_stock']),
            'price_discount' => $priceDiscountPct,
            'upload_images_as_files' => !empty($_POST['upload_images_as_files']),
            'started_at' => time(),
        ];
        header('Location: import.php?bulk=run');
        exit;
    }
}

// Zrušení běhu
if (isset($_GET['bulk']) && $_GET['bulk'] === 'cancel') {
    unset($_SESSION['schindler_bulk']);
    header('Location: import.php');
    exit;
}

// Zpracování JEDNÉ dávky
const SCHINDLER_BULK_BATCH = 3; // produktů na jeden požadavek (malá dávka = žádné timeouty hostingu)
if (isset($_GET['bulk']) && $_GET['bulk'] === 'run' && !empty($_SESSION['schindler_bulk'])) {
    $st = &$_SESSION['schindler_bulk'];
    $prodCfg = schindler_prod_api_config();
    if ($prodCfg === null) {
        $bulkError = 'Nepodařilo se načíst produkční API config.';
        unset($_SESSION['schindler_bulk']);
    } elseif (!empty($st['queue'])) {
        @set_time_limit(120);
        $baseUrl = rtrim((string)$prodCfg['base_url'], '/');
        $token = (string)$prodCfg['token'];

        $batch = array_splice($st['queue'], 0, SCHINDLER_BULK_BATCH);
        foreach ($batch as $gid) {
            $g = schindler_load_group((string)$gid);
            if ($g === null) {
                $st['done'][] = ['name' => '#' . $gid, 'status' => 'failed', 'detail' => 'produkt nenalezen v cache feedu', 'variants_ok' => 0, 'variants_total' => 0, 'failed_variants' => []];
                $st['failed']++;
                continue;
            }

            $codes = array_map(fn($it) => $it['code'], $g['items']);
            $existing = !empty($st['skip_existing']) ? schindler_lookup_existing_by_numbers($baseUrl, $token, $codes) : [];
            if (!empty($existing)) {
                $st['done'][] = [
                    'name' => $g['name'],
                    'status' => 'skipped',
                    'detail' => 'už v eshopu (' . implode(', ', array_slice(array_keys($existing), 0, 2)) . (count($existing) > 2 ? '…' : '') . ')',
                    'variants_ok' => 0,
                    'variants_total' => count($g['items']),
                    'failed_variants' => [],
                ];
                $st['skipped']++;
                continue;
            }

            $log = schindler_import_full_product($baseUrl, $token, $g, (string)$st['category'], (string)$st['dial'], $dialValueMap, !empty($st['include_images']), !empty($st['hide_out_of_stock']), (float)($st['price_discount'] ?? 0), !empty($st['upload_images_as_files']));

            $variantsOk = 0;
            foreach ($log['items'] as $il) if ($il['ok']) $variantsOk++;
            $productOk = $log['product']['ok'];

            $st['done'][] = [
                'name' => $g['name'],
                'status' => $productOk ? 'created' : 'failed',
                'detail' => $productOk
                    ? ('kategorie: ' . (isset($log['category_link']) && $log['category_link']['ok'] ? 'OK' : 'CHYBA'))
                    : ('HTTP ' . (int)$log['product']['http']),
                'variants_ok' => $variantsOk,
                'variants_total' => count($log['items']),
                'failed_variants' => array_values(array_filter(array_map(
                    fn($il) => $il['ok'] ? null : ($il['code'] . ' (' . $il['size'] . ', HTTP ' . (int)$il['http'] . ')'),
                    $log['items']
                ))),
            ];
            if ($productOk) $st['created']++; else $st['failed']++;
        }
        unset($st);
    }
}

// Které velikosti z VYBRANÝCH produktů ve zvoleném číselníku chybí?
// Porovnáváme i zakládáme v NORMALIZOVANÉM tvaru, aby se v číselníku
// nezaložila stejná velikost víckrát jen kvůli jinému zápisu ve feedu
// ("S-M (54-58 cm)" vs "S/M (54-58cm)" => jedna hodnota "S/M (54-58 cm)").
$missingSizes = [];
if ($chosenDialIri !== '') {
    $tmp = [];
    foreach ($selectedGroups as $g) {
        if (count($g['items']) < 2) continue; // jednopoložkové produkty velikost neřeší
        foreach ($g['items'] as $it) {
            $s = schindler_normalize_size((string)$it['size']);
            if ($s === '') continue;
            if (!isset($dialValueMap[$s])) $tmp[$s] = true;
        }
    }
    $missingSizes = array_keys($tmp);
    sort($missingSizes, SORT_NATURAL | SORT_FLAG_CASE);
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>SCHINDLER – import</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root { --g1:#24d84a; --g2:#00b52a; --border:#e7e9ec; --muted:#6b7280; --warn:#e08b00; --danger:#d93025; }
* { box-sizing:border-box; }
body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; color:#1b1f23; }
.wrap { max-width:1100px; margin:0 auto; }
.back-link { color:#666; font-size:12.5px; text-decoration:none; border:1px solid var(--border); border-radius:999px; padding:6px 14px; display:inline-block; margin-bottom:14px; }
h1 { font-size:20px; margin:6px 0 2px; }
.subtitle { color:var(--muted); font-size:13px; margin-bottom:18px; }
.card { background:#fff; border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; }
.flash { border-radius:12px; padding:10px 16px; margin-bottom:14px; font-size:13px; font-weight:600; }
.flash-ok { background:#eafbf0; color:#0a7a34; border:1px solid #bdeccb; }
.flash-err { background:#fdeceb; color:#d93025; border:1px solid #f5c6c2; }
.warn-banner { background:#fff3cd; border:1.5px solid #ffe08a; color:#7a5600; border-radius:12px; padding:10px 16px; margin-bottom:16px; font-size:13px; }
.btn { background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; border:none; border-radius:999px; padding:10px 18px; font-size:13.5px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
.btn.secondary { background:#eee; color:#333; }
.btn.danger { background:var(--danger); }
select { padding:8px 10px; border:1px solid #ccc; border-radius:8px; font-size:13px; width:100%; max-width:420px; }
label.field-label { font-size:12px; font-weight:700; color:var(--muted); display:block; margin:10px 0 4px; }
table.sel { width:100%; border-collapse:collapse; font-size:12.5px; }
table.sel th { text-align:left; background:#f7f8f9; padding:8px; border-bottom:2px solid var(--border); font-size:10.5px; text-transform:uppercase; color:var(--muted); }
table.sel td { padding:8px; border-bottom:1px solid var(--border); vertical-align:top; }
.p-thumb { width:40px; height:40px; object-fit:cover; border-radius:8px; border:1px solid var(--border); }
pre { background:#f7f8f9; padding:12px; border-radius:10px; overflow:auto; font-size:11.5px; max-height:420px; }
.confirm-box { border:1.5px dashed var(--warn); border-radius:12px; padding:14px; margin-top:8px; background:#fffaf0; }
.empty-note { text-align:center; padding:30px; color:var(--muted); font-size:13.5px; }
</style>
</head>
<body>
<div class="wrap">
    <a class="back-link" href="index.php">&larr; Zpět na SCHINDLER</a>
    <h1>SCHINDLER – import vybraných produktů</h1>
    <p class="subtitle">Vybráno <?php echo count($selectedGroups); ?> produktů.</p>

    <?php if ($flash): ?><div class="flash <?php echo $flash['ok'] ? 'flash-ok' : 'flash-err'; ?>"><?php echo h($flash['msg']); ?></div><?php endif; ?>

    <div class="warn-banner">
        ℹ️ Ověřeno na produkci (30.8.2026): <code>POST /api-engine/products</code> vyžaduje, aby produkt i všechny jeho varianty (velikosti) přišly <b>najednou</b> v jednom požadavku, v poli <code>productItemList</code> - samostatné založení produktu a dodatečné přidání variant zvlášť API odmítá. Import teď posílá obojí najednou.
    </div>

    <?php if (empty($selectedGroups)): ?>
        <div class="card"><div class="empty-note">Zatím nemáš vybraný žádný produkt. <a href="index.php">Vyber výrobce a produkty</a>.</div></div>
    <?php else: ?>

    <div class="card">
        <h2 style="font-size:15px;margin-top:0;">Vybrané produkty</h2>
        <table class="sel">
            <thead><tr><th></th><th>Produkt</th><th>Výrobce</th><th>Variant</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($selectedGroups as $gid => $g): $sum = schindler_group_summary($g); ?>
                <tr>
                    <td><img class="p-thumb" src="<?php echo h($g['image']); ?>" alt=""></td>
                    <td><?php echo h($g['name']); ?><div style="color:var(--muted);font-size:11px;"><?php echo h($g['category']); ?></div></td>
                    <td><?php echo h($g['manufacturer']); ?></td>
                    <td><?php echo (int)$sum['variant_count']; ?> (<?php echo (int)$sum['in_stock_count']; ?> skladem)</td>
                    <td>
                        <form method="post" onsubmit="return confirm('Odebrat tento produkt z výběru?');">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="gid" value="<?php echo h($gid); ?>">
                            <button type="submit" class="btn secondary" style="padding:5px 12px;font-size:11.5px;">Odebrat</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 style="font-size:15px;margin-top:0;">Cílová kategorie a číselník velikostí</h2>
        <?php if (!$prodCfgOk): ?>
            <p style="color:var(--danger);font-size:13px;">Nepodařilo se načíst produkční API config (secrets/eshop_new_api.php) - kategorie a číselníky nejdou natáhnout.</p>
        <?php endif; ?>
        <form method="post" id="mapForm">
            <input type="hidden" name="action" value="preview">
            <label class="field-label">Eshopová kategorie, do které se mají produkty zařadit</label>
            <select name="target_category">
                <option value="">— vyber kategorii —</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?php echo h($c['iri']); ?>" <?php echo $c['iri'] === $chosenCategoryIri ? 'selected' : ''; ?>><?php echo h($c['name']); ?></option>
                <?php endforeach; ?>
            </select>

            <label class="field-label">Číselník (dial) pro velikosti (podle kterého se mají namapovat velikosti z feedu)</label>
            <select name="target_dial">
                <option value="">— bez mapování velikostí —</option>
                <?php foreach ($dials as $d): ?>
                    <option value="<?php echo h($d['iri']); ?>" <?php echo $d['iri'] === $chosenDialIri ? 'selected' : ''; ?>><?php echo h($d['name']); ?> <?php echo h('(' . ($d['short_id'] ?? '') . ')'); ?></option>
                <?php endforeach; ?>
            </select>

            <?php if ($chosenDialIri !== ''): ?>
                <p style="font-size:12px;color:var(--muted);margin-top:8px;">
                    Z <?php echo (int)($dialDiag['total_seen'] ?? 0); ?> hodnot napříč všemi číselníky patří do <b>tohoto</b> číselníku <b><?php echo (int)($dialDiag['kept'] ?? 0); ?></b>:
                    <?php echo h(implode(', ', array_slice(array_keys($dialValueMap), 0, 40))); ?><?php echo count($dialValueMap) > 40 ? '…' : ''; ?>
                </p>
                <?php if ((int)($dialDiag['kept'] ?? 0) === 0): ?>
                    <p style="font-size:12px;color:var(--danger);font-weight:600;margin-top:4px;">
                        ⚠ Tenhle číselník nemá žádné hodnoty - vyber jiný (v eshopu je jich několik se stejným názvem „Velikost“, liší se ID v závorce).
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <label class="field-label">Naše prodejní cena = MOC s DPH mínus sleva (%)</label>
            <input type="text" name="price_discount" value="<?php echo h(rtrim(rtrim(number_format($priceDiscountPct, 2, '.', ''), '0'), '.')); ?>" style="max-width:120px;padding:8px 10px;border:1px solid #ccc;border-radius:8px;font-size:13px;">
            <p style="font-size:11.5px;color:var(--muted);margin-top:5px;">
                Zadej např. <code>10</code> pro cenu o 10 % pod MOC. <code>0</code> = prodáváme za MOC.
                Do „Běžné ceny“ (přeškrtnuté) se vždy uloží plná MOC s DPH z feedu. Výsledek se zaokrouhluje na celé koruny.
                Hodnota platí pro celý následující import (i hromadný), takže si ji můžeš nastavit zvlášť pro každou kategorii či značku.
            </p>

            <button type="submit" class="btn" style="margin-top:14px;">Přepočítat ceny a zobrazit náhled</button>
        </form>
    </div>

    <?php if ($chosenDialIri !== ''): ?>
        <div class="card">
            <h2 style="font-size:15px;margin-top:0;">Chybějící velikosti v číselníku</h2>

            <?php if ($dialCreateError !== null): ?>
                <div class="flash flash-err"><?php echo h($dialCreateError); ?></div>
            <?php endif; ?>

            <?php if ($dialCreateLog !== null): ?>
                <?php $okCount = 0; foreach ($dialCreateLog as $dl) { if ($dl['ok']) $okCount++; } ?>
                <div class="flash <?php echo $okCount === count($dialCreateLog) ? 'flash-ok' : 'flash-err'; ?>">
                    Založeno <?php echo $okCount; ?> z <?php echo count($dialCreateLog); ?> hodnot.
                </div>
                <table class="sel" style="margin-bottom:14px;">
                    <thead><tr><th>Hodnota</th><th>HTTP</th></tr></thead>
                    <tbody>
                    <?php foreach ($dialCreateLog as $dl): ?>
                        <tr>
                            <td><?php echo h($dl['size']); ?></td>
                            <td>
                                <?php if (!empty($dl['skipped'])): ?>
                                    <span style="color:#8a8f98;font-weight:700;">už existovala</span>
                                <?php else: ?>
                                    <b style="color:<?php echo $dl['ok'] ? '#0a7a34' : '#d93025'; ?>;"><?php echo (int)$dl['http']; ?></b>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($okCount < count($dialCreateLog)): ?>
                    <details><summary style="cursor:pointer;font-size:12px;color:var(--muted);">Zobrazit chybové odpovědi</summary>
                    <?php foreach ($dialCreateLog as $dl): if ($dl['ok']) continue; ?>
                        <pre><?php echo h(json_encode($dl['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); ?></pre>
                    <?php endforeach; ?>
                    </details>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (empty($missingSizes)): ?>
                <p style="font-size:13px;color:#0a7a34;font-weight:600;">✓ Všechny velikosti vybraných produktů už v tomhle číselníku jsou. Můžeš rovnou importovat.</p>
            <?php else: ?>
                <p style="font-size:12.5px;color:var(--muted);">
                    Ve vybraných produktech je <b><?php echo count($missingSizes); ?></b> velikostí, které v tomhle číselníku zatím nejsou.
                    Bez nich se ty konkrétní varianty nenapojí na velikost. Zaškrtni ty, které chceš do číselníku doplnit.
                </p>
                <p style="font-size:11.5px;color:var(--warn);">
                    Zápis velikostí se sjednocuje (pomlčka/lomítko/mezery), takže „S-M (54-58 cm)“ i „S/M (54-58cm)“ z feedu se založí jako <b>jedna</b> hodnota. Různé rozměry se ale záměrně neslučují – „S/M (51-56 cm)“ a „S/M (54-58 cm)“ zůstávají oddělené, protože jde o jiný obvod hlavy.
                </p>

                <form method="post" onsubmit="if(!confirm('Založit zaškrtnuté hodnoty do vybraného číselníku?')){return false;} this.querySelector('button[type=submit]').disabled=true;">
                    <input type="hidden" name="action" value="create_missing_dial_values">
                    <input type="hidden" name="target_category" value="<?php echo h($chosenCategoryIri); ?>">
                    <input type="hidden" name="target_dial" value="<?php echo h($chosenDialIri); ?>">

                    <p style="margin:10px 0 6px;">
                        <label style="font-size:12px;font-weight:700;cursor:pointer;">
                            <input type="checkbox" onclick="document.querySelectorAll('.missing-size-check').forEach(function(el){el.checked=this.checked;}.bind(this));" checked>
                            Zaškrtnout / odškrtnout vše
                        </label>
                    </p>

                    <div style="max-height:280px;overflow:auto;border:1px solid var(--border);border-radius:8px;padding:10px;background:#fafbfb;">
                        <?php foreach ($missingSizes as $ms): ?>
                            <label style="display:block;font-size:12.5px;padding:3px 0;">
                                <input type="checkbox" class="missing-size-check" name="missing_size[]" value="<?php echo h($ms); ?>" checked>
                                <code><?php echo h($ms); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin-top:10px;">
                        <input type="checkbox" name="confirm_dial" value="1" required>
                        Rozumím, že se tyhle hodnoty zapíšou do vybraného číselníku v ostrém eshopu.
                    </label>
                    <button type="submit" class="btn" style="margin-top:10px;">Založit zaškrtnuté hodnoty do číselníku</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($previewPayloads !== null): ?>
        <div class="card">
            <h2 style="font-size:15px;margin-top:0;">Náhled – co by se odeslalo</h2>
            <?php foreach ($previewPayloads as $gid => $p): ?>
                <p style="font-weight:700;font-size:13px;margin-bottom:4px;"><?php echo h($selectedGroups[$gid]['name']); ?></p>
                <pre><?php echo h(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); ?></pre>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
    $firstGidForImport = array_key_first($selectedGroups);
    $firstGroupForImport = $firstGidForImport !== null ? $selectedGroups[$firstGidForImport] : null;
    $totalVariantsToImport = 0;
    foreach ($selectedGroups as $g) $totalVariantsToImport += count($g['items']);
    ?>

    <?php if (!empty($selectedGroups)): ?>
        <div class="card" style="border:2px solid var(--g2);">
            <h2 style="font-size:15px;margin-top:0;">🚀 HROMADNÝ import všech vybraných produktů</h2>
            <p style="font-size:12.5px;color:var(--muted);">
                Založí <b><?php echo count($selectedGroups); ?></b> produktů (celkem <b><?php echo $totalVariantsToImport; ?></b> variant) do vybrané kategorie.
                Ceny: <b>MOC − <?php echo h(rtrim(rtrim(number_format($priceDiscountPct,2,'.',''),'0'),'.')); ?> %</b> (běžná cena = plná MOC s DPH).
                Produkty se zakládají jeden po druhém; když některý selže, ostatní pokračují a na konci uvidíš přehled.
            </p>

            <?php if ($chosenCategoryIri === ''): ?>
                <p style="color:var(--danger);font-size:12.5px;">⚠ Nejdřív výše vyber cílovou eshopovou kategorii.</p>
            <?php elseif (!empty($missingSizes)): ?>
                <p style="color:var(--danger);font-size:12.5px;font-weight:600;">
                    ⚠ Ve vybraných produktech je ještě <?php echo count($missingSizes); ?> velikostí, které nejsou v číselníku (viz sekce výše).
                    Založ je nejdřív, jinak se tyhle varianty nenapojí na velikost.
                </p>
                <p style="font-size:12px;color:var(--muted);">Pokud to tak chceš i přesto, můžeš import spustit – jen ty konkrétní varianty zůstanou bez velikosti.</p>
            <?php endif; ?>

            <?php if (!empty($_SESSION['schindler_bulk']['queue'])): ?>
                <p style="font-size:12.5px;color:var(--muted);">Import právě probíhá – viz průběh níže.</p>
            <?php elseif ($chosenCategoryIri !== ''): ?>
                <form method="post" class="confirm-box" onsubmit="if(!confirm('Spustit HROMADNÝ import <?php echo count($selectedGroups); ?> produktů do OSTRÉHO eshopu?')){return false;} this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').textContent='Importuji… může to trvat několik minut, NEZAVÍREJ stránku';">
                    <input type="hidden" name="action" value="import_bulk">
                    <input type="hidden" name="target_category" value="<?php echo h($chosenCategoryIri); ?>">
                    <input type="hidden" name="target_dial" value="<?php echo h($chosenDialIri); ?>">

                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;">
                        <input type="checkbox" name="skip_existing" value="1" checked>
                        Přeskočit produkty, které už v eshopu jsou <span style="font-weight:400;color:var(--muted);">(doporučeno – brání duplicitám)</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin-top:6px;">
                        <input type="checkbox" name="hide_out_of_stock" value="1" checked>
                        Skrýt v eshopu varianty, které nejsou skladem <span style="font-weight:400;color:var(--muted);">(nastaví <code>isInvisible</code> u variant s 0 ks)</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin-top:6px;">
                        <input type="checkbox" name="include_images" value="1" checked>
                        Nahrát i fotky k hlavnímu produktu <span style="font-weight:400;color:var(--muted);">(max. 7 z feedu)</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin-top:6px;">
                        <input type="checkbox" name="upload_images_as_files" value="1">
                        Fotky nahrát jako soubory na eshop <span style="font-weight:400;color:var(--muted);">(pomalejší; odškrtnuté = jen odkaz na obrázek u dodavatele)</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin-top:6px;">
                        <input type="checkbox" name="confirm_bulk" value="1" required>
                        Rozumím, že tohle zapisuje <?php echo count($selectedGroups); ?> produktů přímo do ostrého eshopu.
                    </label>
                    <button type="submit" class="btn" style="margin-top:10px;">🚀 Importovat všech <?php echo count($selectedGroups); ?> produktů na OSTRÝ eshop</button>
                </form>
            <?php endif; ?>

            <?php if ($bulkError !== null): ?>
                <div class="flash flash-err" style="margin-top:12px;"><?php echo h($bulkError); ?></div>
            <?php endif; ?>

            <?php
            $bulkState = $_SESSION['schindler_bulk'] ?? null;
            if ($bulkState !== null):
                $totalBulk = count($bulkState['done']) + count($bulkState['queue']);
                $doneBulk = count($bulkState['done']);
                $pct = $totalBulk > 0 ? (int)round($doneBulk / $totalBulk * 100) : 100;
                $running = !empty($bulkState['queue']);
            ?>
                <div style="margin-top:16px;">
                    <?php if ($running): ?>
                        <div class="flash" style="background:#e8f2ff;border:1px solid #b6d4fe;color:#084298;">
                            ⏳ Import běží… zpracováno <b><?php echo $doneBulk; ?></b> z <b><?php echo $totalBulk; ?></b> produktů.
                            <b>Nezavírej tuhle stránku</b> – sama se posouvá dál po malých dávkách, aby ji hosting neutnul.
                        </div>
                    <?php else: ?>
                        <div class="flash <?php echo (int)$bulkState['failed'] === 0 ? 'flash-ok' : 'flash-err'; ?>">
                            ✓ Hotovo: <b><?php echo (int)$bulkState['created']; ?></b> založeno,
                            <b><?php echo (int)$bulkState['skipped']; ?></b> přeskočeno (už existovaly),
                            <b><?php echo (int)$bulkState['failed']; ?></b> selhalo.
                        </div>
                    <?php endif; ?>

                    <div style="background:#eceff1;border-radius:999px;height:14px;overflow:hidden;margin:10px 0;">
                        <div style="background:linear-gradient(90deg,var(--g1),var(--g2));height:100%;width:<?php echo $pct; ?>%;transition:width .3s;"></div>
                    </div>
                    <p style="font-size:12px;color:var(--muted);text-align:center;margin-top:-4px;"><?php echo $pct; ?> %</p>

                    <?php if ($running): ?>
                        <p style="text-align:center;">
                            <a class="btn secondary" href="import.php?bulk=cancel" onclick="return confirm('Opravdu zastavit import? Už založené produkty v eshopu zůstanou.');">Zastavit import</a>
                        </p>
                        <script>
                            // Automatické pokračování další dávkou.
                            setTimeout(function () { window.location.href = 'import.php?bulk=run'; }, 800);
                        </script>
                    <?php else: ?>
                        <p style="text-align:center;">
                            <a class="btn secondary" href="import.php?bulk=cancel">Zavřít výsledek</a>
                        </p>
                    <?php endif; ?>

                    <table class="sel">
                        <thead><tr><th>Produkt</th><th>Stav</th><th>Varianty</th><th>Detail</th></tr></thead>
                        <tbody>
                        <?php foreach (array_reverse($bulkState['done']) as $bi): ?>
                            <tr>
                                <td><?php echo h($bi['name']); ?></td>
                                <td>
                                    <?php if ($bi['status'] === 'created'): ?>
                                        <span style="color:#0a7a34;font-weight:700;">✓ založeno</span>
                                    <?php elseif ($bi['status'] === 'skipped'): ?>
                                        <span style="color:#8a8f98;font-weight:700;">— přeskočeno</span>
                                    <?php else: ?>
                                        <span style="color:#d93025;font-weight:700;">✗ chyba</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $bi['status'] === 'created' ? ((int)$bi['variants_ok'] . '/' . (int)$bi['variants_total']) : '—'; ?></td>
                                <td style="font-size:11px;color:var(--muted);">
                                    <?php echo h($bi['detail']); ?>
                                    <?php if (!empty($bi['failed_variants'])): ?>
                                        <br><span style="color:#d93025;">nezaložené: <?php echo h(implode('; ', $bi['failed_variants'])); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($firstGroupForImport !== null): ?>
        <div class="card">
            <h2 style="font-size:15px;margin-top:0;">Import 1 produktu (s variantami) do OSTRÉHO eshopu</h2>
            <p style="font-size:12.5px;color:var(--muted);">Vezme <b>první</b> produkt z výběru výše a založí ho VČETNĚ všech velikostí/variant. Nejdřív si ověř v tabulce níže, že jde skutečně o jeden produkt se správnými variantami.</p>

            <p style="font-weight:700;font-size:13.5px;margin:14px 0 4px;"><?php echo h($firstGroupForImport['name']); ?> <span style="font-weight:400;color:var(--muted);">(<?php echo h($firstGroupForImport['manufacturer']); ?> · <?php echo h($firstGroupForImport['category']); ?>)</span></p>

            <table class="sel">
                <thead><tr><th>Kód</th><th>EAN</th><th>Velikost</th><th>Barva</th><th>Sklad</th><th>MOC s DPH<br><span style="font-weight:400;text-transform:none;">(běžná cena)</span></th><th>Naše cena<br><span style="font-weight:400;text-transform:none;">(-<?php echo h(rtrim(rtrim(number_format($priceDiscountPct,2,'.',''),'0'),'.')); ?> %)</span></th></tr></thead>
                <tbody>
                <?php foreach ($firstGroupForImport['items'] as $it):
                    $codeLen = strlen($it['code']);
                    $codeTooLong = $codeLen > 20;
                ?>
                    <tr<?php echo $codeTooLong ? ' style="background:#fdeceb;"' : ''; ?>>
                        <td><?php echo h($it['code']); ?><?php if ($codeTooLong): ?> <span style="color:var(--danger);font-weight:700;" title="Kód má <?php echo $codeLen; ?> znaků - podezření na limit pole 'number' v Eshop-rychle (viz nahlášená chyba), tahle varianta pravděpodobně skončí HTTP 500">⚠ <?php echo $codeLen; ?> znaků</span><?php endif; ?></td>
                        <td><?php echo h($it['ean'] !== '' ? $it['ean'] : '—'); ?></td>
                        <td><?php echo h($it['size'] !== '' ? $it['size'] : '—'); ?></td>
                        <td><?php echo h($it['color'] !== '' ? $it['color'] : '—'); ?></td>
                        <td><?php echo (int)$it['stock']; ?> ks</td>
                        <td style="color:var(--muted);"><?php echo number_format((float)$it['customer_price'], 2, ',', ' '); ?> Kč</td>
                        <td><b><?php echo number_format(schindler_apply_discount((float)$it['customer_price'], $priceDiscountPct), 2, ',', ' '); ?> Kč</b></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="font-size:11px;color:var(--muted);margin-top:6px;">Ceny jsou s DPH. Do eshopu se uloží „Naše cena“ jako prodejní a „MOC s DPH“ jako běžná (přeškrtnutá). Celkem <?php echo count($firstGroupForImport['items']); ?> varianta(y).</p>
            <?php
            $anyLong = false;
            foreach ($firstGroupForImport['items'] as $it) { if (strlen($it['code']) > 20) { $anyLong = true; break; } }
            ?>
            <?php if ($anyLong): ?>
                <p style="font-size:12px;color:var(--danger);font-weight:600;margin-top:6px;">⚠ Aspoň jedna varianta má kód delší než 20 znaků - podle ověřeného případu (SANTINI TDF Leader XXL) to pravděpodobně skončí chybou HTTP 500 na straně Eshop-rychle. Import ostatních variant to neovlivní, jen tahle konkrétní zřejmě neprojde.</p>
            <?php endif; ?>

            <?php if ($chosenCategoryIri === ''): ?>
                <p style="color:var(--danger);font-size:12.5px;margin-top:10px;">⚠ Nejdřív výše vyber cílovou eshopovou kategorii (a klidně i číselník velikostí), jinak import nepůjde spustit.</p>
            <?php else: ?>
                <form method="post" class="confirm-box" onsubmit="if(!confirm('Opravdu založit tento produkt VČETNĚ variant na OSTRÉM eshopu? Tohle už není test.')){return false;} this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').textContent='Zakládám… (nekliknout znovu)';">
                    <input type="hidden" name="action" value="import_prod_full">
                    <input type="hidden" name="target_category" value="<?php echo h($chosenCategoryIri); ?>">
                    <input type="hidden" name="target_dial" value="<?php echo h($chosenDialIri); ?>">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;">
                        <input type="checkbox" name="include_images" value="1" checked>
                        Nahrát i fotky k hlavnímu produktu <span style="font-weight:400;color:var(--muted);">(max. 7 z feedu)</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin-top:6px;">
                        <input type="checkbox" name="hide_out_of_stock" value="1" checked>
                        Skrýt v eshopu varianty, které nejsou skladem
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin-top:6px;">
                        <input type="checkbox" name="force_despite_duplicate" value="1">
                        I přesto založit, i kdyby kód/EAN už v eshopu existoval <span style="font-weight:400;color:var(--muted);">(normálně nech odškrtnuté - je to pojistka proti duplicitám)</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin-top:6px;">
                        <input type="checkbox" name="confirm_prod" value="1" required>
                        Rozumím, že tohle zapisuje přímo do ostrého eshopu (ne testovacího).
                    </label>
                    <button type="submit" class="btn" style="margin-top:10px;">⚠ Založit tento produkt VČETNĚ variant na OSTRÉM eshopu</button>
                </form>
            <?php endif; ?>

            <?php if ($prodImportError !== null): ?>
                <div class="flash flash-err" style="margin-top:12px;"><?php echo h($prodImportError); ?></div>
            <?php endif; ?>

            <?php if ($fullImportLog !== null && ($fullImportLog['target'] ?? '') !== 'TEST (.dev)'): schindler_render_full_import_log($fullImportLog); endif; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2 style="font-size:15px;margin-top:0;">Bezpečný test na .dev shopu</h2>
        <p style="font-size:12.5px;color:var(--muted);">Založí vybraný produkt VČETNĚ variant na testovacím e-shopu (token z modulu „TEST Eshop-rychle API“), stejnou logikou jako ostrý import. Ostrého e-shopu se to vůbec netýká.</p>
        <form method="post" class="confirm-box" onsubmit="return confirm('Spustit test na .dev e-shop?');">
            <input type="hidden" name="action" value="test_dev_shop">
            <input type="hidden" name="target_category" value="<?php echo h($chosenCategoryIri); ?>">
            <input type="hidden" name="target_dial" value="<?php echo h($chosenDialIri); ?>">
            <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;margin-bottom:8px;">
                <input type="checkbox" name="include_images" value="1">
                Zahrnout fotku
            </label>
            <button type="submit" class="btn">⚠ Otestovat založení vybraného produktu na .dev shopu</button>
        </form>

        <?php if ($devTestError !== null): ?>
            <div class="flash flash-err" style="margin-top:12px;"><?php echo h($devTestError); ?></div>
        <?php endif; ?>

        <?php if ($fullImportLog !== null && ($fullImportLog['target'] ?? '') === 'TEST (.dev)'): schindler_render_full_import_log($fullImportLog); endif; ?>
    </div>

    <?php endif; ?>
</div>
</body>
</html>
