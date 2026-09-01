<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  cfloat-new/schindler/lib/schindler_feed.php
 *
 *  Stažení a rozparsování XML feedu dodavatele Schindler (formát Heureka.cz:
 *  SHOP > SHOPITEM). Feed je cca 45 MB / 10 000+ položek, proto se:
 *   - stahuje a parsuje STREAMOVANĚ (XMLReader), nikdy se nenačítá celý
 *     string do paměti přes SimpleXML/file_get_contents,
 *   - výsledek se uloží do lokální JSON cache (schindler/cache/), aby se
 *     stránky s výběrem výrobce/kategorie/produktů nemusely pokaždé
 *     prokousávat celým feedem znovu.
 *
 *  Položky se stejným ITEMGROUP_ID = jeden produkt s více variantami
 *  (velikost/barva) - to je klíč pro navázání na číselníky velikostí
 *  v Eshop-rychle.
 * ===========================================================================
 */

// Adresa feedu obsahuje přístupový klíč - stejně jako u ostatních dodavatelů
// (Vavrys SOAP login apod.) je natvrdo v kódu této knihovny, ne v cizí session.
const SCHINDLER_FEED_URL = 'https://b2b.schindler.cz/api/export/product/cz/cs/czk/?key=eExMb3BlT25ncUpGNHJYQkVWTDBiZz09';

function schindler_cache_dir(): string
{
    $dir = __DIR__ . '/../cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function schindler_raw_feed_path(): string { return schindler_cache_dir() . '/schindler_feed_raw.xml'; }
function schindler_products_cache_path(): string { return schindler_cache_dir() . '/schindler_products.json'; }
function schindler_meta_cache_path(): string { return schindler_cache_dir() . '/schindler_meta.json'; }

/**
 * Stáhne feed (gzip) a vrátí cestu k dekomprimovanému XML souboru na disku.
 * @throws RuntimeException
 */
function schindler_download_feed(): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Na serveru chybí cURL.');
    }
    $ch = curl_init(SCHINDLER_FEED_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $body === '') {
        throw new RuntimeException('Stažení feedu selhalo: ' . $err);
    }
    if ($http < 200 || $http >= 300) {
        throw new RuntimeException('Stažení feedu selhalo, HTTP ' . $http);
    }

    // Gzip magic bytes 0x1f 0x8b -> je to komprimované, i když by mime typ říkal jinak.
    $isGzip = strlen($body) > 2 && substr($body, 0, 2) === "\x1f\x8b";
    $xml = $isGzip ? @gzdecode($body) : $body;
    if ($xml === false || $xml === null || $xml === '') {
        throw new RuntimeException('Rozbalení / čtení feedu selhalo (gzdecode).');
    }

    $path = schindler_raw_feed_path();
    if (@file_put_contents($path, $xml, LOCK_EX) === false) {
        throw new RuntimeException('Nepodařilo se uložit stažený feed na disk (' . $path . ').');
    }
    return $path;
}

/**
 * Normalizace zápisu velikosti pro porovnávání (lokální kopie, ať je
 * knihovna feedu soběstačná - stejná pravidla jako schindler_normalize_size()).
 */
function schindler_feed_norm_size(string $s): string
{
    $out = trim($s);
    if ($out === '') return '';
    $out = preg_replace('/\s+/u', ' ', $out);
    $out = preg_replace('/(?<=\p{L})\s*-\s*(?=\p{L})/u', '/', $out);
    $out = preg_replace('/\s*\/\s*/u', '/', $out);
    $out = preg_replace('/(\d)\s*-\s*(\d)/u', '$1-$2', $out);
    $out = preg_replace('/(\d)\s*(cm|mm)\b/iu', '$1 $2', $out);
    $out = preg_replace('/\(\s*/u', '(', $out);
    $out = preg_replace('/\s*\)/u', ')', $out);
    return trim((string)$out);
}

/**
 * Zkusí vytáhnout velikost z KONCE názvu produktu.
 *
 * Někteří dodavatelé (ověřeno u části CRATONI helem) neposílají velikost ani
 * v tagu <VARIANT>, ani v parametrech - je jen v názvu ("... - S/M (54-58cm)").
 * Bereme jen tvary, které jsou jednoznačně velikost, ať omylem neuřízneme
 * kus názvu:
 *   - cokoliv s obvodem v závorce:      "S/M (54-58cm)", "XS-S (46-51 cm)"
 *   - známé konfekční velikosti:        "XL", "XXL", "3XL", "S/M", "M-L"
 *   - čísla bot:                        "42", "42.5"
 */
function schindler_extract_size_from_name(string $name): string
{
    foreach ([' - ', ' – ', ' — '] as $sep) {
        $pos = mb_strrpos($name, $sep);
        if ($pos === false) continue;
        $tail = trim(mb_substr($name, $pos + mb_strlen($sep)));
        if ($tail === '' || mb_strlen($tail) > 30) continue;

        // a) obvod v závorce - jednoznačně velikost
        if (preg_match('/\(\s*\d+\s*[-–]\s*\d+\s*(cm|mm)\s*\)/iu', $tail)) return $tail;

        // b) konfekční velikost, případně dvojice ("S", "XL", "3XL", "S/M", "M-L")
        $sizeTok = '(?:X{0,3}[SML]|[2-6]XL|UNI)';
        if (preg_match('/^' . $sizeTok . '(?:\s*[\/\-]\s*' . $sizeTok . ')?$/iu', $tail)) return $tail;

        // c) číslo boty
        if (preg_match('/^\d{2}(?:[.,]5)?$/u', $tail)) return $tail;
    }
    return '';
}

/**
 * Odřízne z názvu koncovou velikost, porovnává se v normalizovaném tvaru
 * (dodavatel píše velikost v názvu jinak než v tagu VARIANT).
 */
function schindler_feed_strip_size(string $name, string $size): string
{
    $name = trim($name);
    $size = trim($size);
    if ($name === '' || $size === '') return $name;
    $normSize = schindler_feed_norm_size($size);
    foreach ([' - ', ' – ', ' — '] as $sep) {
        $pos = mb_strrpos($name, $sep);
        if ($pos === false) continue;
        $tail = mb_substr($name, $pos + mb_strlen($sep));
        if (schindler_feed_norm_size($tail) === $normSize) {
            return trim(mb_substr($name, 0, $pos));
        }
    }
    return $name;
}

/**
 * Odvodí název produktu (bez velikosti) ze VŠECH jeho variant.
 *
 * Postup: u každé varianty se nejdřív odřízne JEJÍ velikost, a teprve z takto
 * očištěných názvů se vezme společný začátek. Samotný společný začátek
 * nestačí - u variant "S (48-53cm)" a "S/M (51-56cm)" by zůstal viset kus
 * velikosti ("... Rose Matt - S").
 *
 * @param array $items položky skupiny (potřebují klíče 'name' a 'size')
 */
function schindler_derive_group_name(array $items): string
{
    $names = [];
    foreach ($items as $it) {
        $n = trim((string)($it['name'] ?? ''));
        if ($n === '') continue;
        $names[] = schindler_feed_strip_size($n, (string)($it['size'] ?? ''));
    }
    $names = array_values(array_filter($names, fn($n) => $n !== ''));
    if (empty($names)) return '';
    if (count($names) === 1) return $names[0];

    $prefix = $names[0];
    foreach (array_slice($names, 1) as $name) {
        $max = min(strlen($prefix), strlen($name));
        $i = 0;
        while ($i < $max && $prefix[$i] === $name[$i]) $i++;
        $prefix = substr($prefix, 0, $i);
        if ($prefix === '') break;
    }
    while ($prefix !== '' && !mb_check_encoding($prefix, 'UTF-8')) {
        $prefix = substr($prefix, 0, -1);
    }
    $prefix = preg_replace('/[\s\-–—\/,(]+$/u', '', $prefix);
    $prefix = trim((string)$prefix);

    if (mb_strlen($prefix) < 5) return $names[0];
    return $prefix;
}

function schindler_child_text(DOMElement $el, string $tag): string
{
    foreach ($el->childNodes as $c) {
        if ($c instanceof DOMElement && $c->localName === $tag) {
            return trim($c->textContent);
        }
    }
    return '';
}

/** Přečte texty VŠECH přímých potomků daného jména. */
function schindler_child_texts(DOMElement $el, string $tag): array
{
    $out = [];
    foreach ($el->childNodes as $c) {
        if ($c instanceof DOMElement && $c->localName === $tag) {
            $out[] = trim($c->textContent);
        }
    }
    return $out;
}

/** Rozparsuje jeden <SHOPITEM> uzel na plochou asociativní strukturu. */
function schindler_parse_shopitem(DOMElement $node): array
{
    $params = [];
    foreach ($node->childNodes as $c) {
        if ($c instanceof DOMElement && $c->localName === 'PARAM') {
            $pname = schindler_child_text($c, 'PARAM_NAME');
            $pval = schindler_child_text($c, 'VAL');
            if ($pname !== '') $params[$pname] = $pval;
        }
    }

    $size = $params['Velikost'] ?? '';
    $variantText = schindler_child_text($node, 'VARIANT');
    // Někteří dodavatelé (např. SIDI u tretr) neposílají PARAM_NAME="Velikost",
    // jen holý tag <VARIANT>36</VARIANT> - pak ho bereme jako velikost místo.
    if ($size === '' && $variantText !== '') {
        $size = $variantText;
    }
    // A část produktů (ověřeno u CRATONI helem) nemá velikost ani v tagu
    // VARIANT, ani v parametrech - je jen v názvu ("... - S/M (54-58cm)").
    if ($size === '') {
        $size = schindler_extract_size_from_name(schindler_child_text($node, 'PRODUCTNAME'));
    }

    return [
        'item_id'      => schindler_child_text($node, 'ITEM_ID'),
        'code'         => schindler_child_text($node, 'CODE'),
        'ean'          => schindler_child_text($node, 'EAN'),
        'name'         => schindler_child_text($node, 'PRODUCTNAME'),
        'desc_short'   => schindler_child_text($node, 'DESCRIPTION_SHORT'),
        'description'  => schindler_child_text($node, 'DESCRIPTION'),
        'manufacturer' => schindler_child_text($node, 'MANUFACTURER'),
        'variant'      => $variantText,
        'purchase_price'  => (float)schindler_child_text($node, 'PURCHASE_PRICE'),
        'customer_price'  => (float)schindler_child_text($node, 'CUSTOMER_PRICE'),
        'catalogue_price' => (float)schindler_child_text($node, 'CATALOGUE_PRICE'),
        'vat'          => (float)schindler_child_text($node, 'VAT'),
        'image'        => schindler_child_text($node, 'IMGURL'),
        'images_alt'   => schindler_child_texts($node, 'IMGURL_ALTERNATIVE'),
        'category'     => schindler_child_text($node, 'CATEGORYTEXT'),
        'stock'        => (int)schindler_child_text($node, 'STOCK_ITEM'),
        'itemgroup_id' => schindler_child_text($node, 'ITEMGROUP_ID'),
        'year'         => schindler_child_text($node, 'YEAR'),
        'params'       => $params,
        'size'         => $size,
        'color'        => $params['Barva'] ?? '',
    ];
}

/**
 * Streamovaně projde stažený XML soubor a vrátí:
 *  - 'groups'  => [itemgroup_id => ['manufacturer','category','name','image', 'items' => [...]]]
 *  - 'meta'    => ['manufacturers' => [name => count_groups], 'categories' => [manufacturer => [cat => count_groups]], 'generated_at', 'total_items', 'total_groups']
 *
 * @throws RuntimeException
 */
function schindler_parse_feed_file(string $xmlPath): array
{
    $reader = new XMLReader();
    if (!$reader->open($xmlPath, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        throw new RuntimeException('Nepodařilo se otevřít stažený XML soubor.');
    }

    $doc = new DOMDocument('1.0', 'UTF-8');
    $groups = [];
    $totalItems = 0;

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'SHOPITEM') continue;
        $node = $reader->expand($doc);
        if (!$node instanceof DOMElement) continue;

        $item = schindler_parse_shopitem($node);
        $totalItems++;

        $gid = $item['itemgroup_id'] !== '' ? $item['itemgroup_id'] : ('single_' . $item['item_id']);
        if (!isset($groups[$gid])) {
            $groups[$gid] = [
                'manufacturer' => $item['manufacturer'],
                'category'     => $item['category'],
                'name'         => $item['name'], // dopočítá se níže ze všech variant
                'image'        => $item['image'],
                'items'        => [],
            ];
        }
        unset($item['params']); // šetříme místo v cache - jednotlivá pole už jsme vytáhli výše
        $groups[$gid]['items'][] = $item;
    }
    $reader->close();

    // --- Název produktu bez velikosti -----------------------------------
    // Musí se počítat AŽ TEĎ, kdy jsou u skupiny všechny varianty pohromadě
    // (odvozuje se ze společného začátku jejich názvů).
    foreach ($groups as $gid => $g) {
        $groups[$gid]['name'] = schindler_derive_group_name($g['items']);
    }

    // --- Index výrobců a kategorií (počty na úrovni SKUPIN produktů, ne variant) ---
    $manufacturers = [];
    $categories = [];
    foreach ($groups as $g) {
        $m = $g['manufacturer'] !== '' ? $g['manufacturer'] : '(bez výrobce)';
        $cat = $g['category'] !== '' ? $g['category'] : '(bez kategorie)';
        $manufacturers[$m] = ($manufacturers[$m] ?? 0) + 1;
        $categories[$m][$cat] = ($categories[$m][$cat] ?? 0) + 1;
    }
    ksort($manufacturers, SORT_STRING | SORT_FLAG_CASE);

    return [
        'groups' => $groups,
        'meta' => [
            'manufacturers'  => $manufacturers,
            'categories'     => $categories,
            'generated_at'   => date('c'),
            'total_items'    => $totalItems,
            'total_groups'   => count($groups),
        ],
    ];
}

/** Stáhne feed, rozparsuje ho a uloží do cache. Vrací meta info. */
function schindler_refresh_cache(): array
{
    $xmlPath = schindler_download_feed();
    $parsed = schindler_parse_feed_file($xmlPath);

    $ok1 = @file_put_contents(schindler_products_cache_path(), json_encode($parsed['groups'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    $ok2 = @file_put_contents(schindler_meta_cache_path(), json_encode($parsed['meta'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    if ($ok1 === false || $ok2 === false) {
        throw new RuntimeException('Feed se stáhl a zpracoval, ale nepodařilo se uložit cache na disk.');
    }
    return $parsed['meta'];
}

function schindler_has_cache(): bool
{
    return is_file(schindler_products_cache_path()) && is_file(schindler_meta_cache_path());
}

function schindler_load_meta(): ?array
{
    if (!is_file(schindler_meta_cache_path())) return null;
    $raw = @file_get_contents(schindler_meta_cache_path());
    $d = $raw !== false ? json_decode($raw, true) : null;
    return is_array($d) ? $d : null;
}

/** Načte VŠECHNY skupiny z cache (pro menší feedy OK; pro filtrování raději schindler_load_groups_filtered). */
function schindler_load_groups(): array
{
    if (!is_file(schindler_products_cache_path())) return [];
    $raw = @file_get_contents(schindler_products_cache_path());
    $d = $raw !== false ? json_decode($raw, true) : null;
    return is_array($d) ? $d : [];
}

/**
 * Vrátí jen skupiny odpovídající výrobci (a volitelně kategorii), seřazené podle názvu.
 * Pro tenhle rozsah dat (desítky tisíc řádků JSON) je i "načíst vše a odfiltrovat"
 * v PHP dostatečně rychlé (desítky ms), není potřeba SQLite/DB.
 */
function schindler_load_groups_filtered(string $manufacturer, ?string $category = null): array
{
    $all = schindler_load_groups();
    $out = [];
    foreach ($all as $gid => $g) {
        $m = $g['manufacturer'] !== '' ? $g['manufacturer'] : '(bez výrobce)';
        if ($m !== $manufacturer) continue;
        if ($category !== null) {
            $cat = $g['category'] !== '' ? $g['category'] : '(bez kategorie)';
            if ($cat !== $category) continue;
        }
        $g['id'] = $gid;
        $out[$gid] = $g;
    }
    uasort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

function schindler_load_group($gid): ?array
{
    $gid = (string)$gid;
    $all = schindler_load_groups();
    if (!isset($all[$gid])) return null;
    $g = $all[$gid];
    $g['id'] = $gid;
    return $g;
}

/** Souhrn skupiny za skladovost/cenu (pro zobrazení v seznamu bez nutnosti dalšího zpracování). */
function schindler_group_summary(array $group): array
{
    $items = $group['items'];
    $inStock = 0;
    $minPrice = null;
    $maxPrice = null;
    $sizes = [];
    foreach ($items as $it) {
        if ((int)$it['stock'] > 0) $inStock++;
        $p = (float)$it['customer_price'];
        if ($minPrice === null || $p < $minPrice) $minPrice = $p;
        if ($maxPrice === null || $p > $maxPrice) $maxPrice = $p;
        if ($it['size'] !== '') $sizes[] = $it['size'];
    }
    return [
        'variant_count' => count($items),
        'in_stock_count' => $inStock,
        'min_price' => $minPrice,
        'max_price' => $maxPrice,
        'sizes' => $sizes,
    ];
}
