<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  cfloat-new/schindler/lib/schindler_eshop_api.php
 *
 *  Volání Eshop-rychle API pro potřeby modulu SCHINDLER:
 *   - ČTENÍ z produkčního e-shopu (kategorie, číselníky/dialy) - bezpečné,
 *     nic se tím nemění.
 *   - ZÁPIS (vytvoření produktu) POUZE proti testovacímu .dev e-shopu, přes
 *     stejný token, který je už uložený v cfloat-new/test-eshop/token.dat.
 *     Skutečný zápis do OSTRÉHO e-shopu tahle knihovna záměrně neumí, dokud
 *     nemáme na testu ověřený přesný formát POST /api-engine/products (viz
 *     poznámka v schindler_build_product_payload).
 * ===========================================================================
 */

function schindler_api_call(string $baseUrl, string $token, string $method, string $path, ?array $jsonBody = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'body' => null, 'error' => 'Na serveru chybí cURL.'];
    }
    $url = str_starts_with($path, 'http') ? $path : (rtrim($baseUrl, '/') . $path);
    $headers = ['X-AUTH-TOKEN: ' . $token, 'Accept: application/ld+json'];
    if ($jsonBody !== null) {
        $headers[] = ($method === 'PATCH') ? 'Content-Type: application/merge-patch+json' : 'Content-Type: application/ld+json';
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ];
    if ($jsonBody !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'http' => $http, 'body' => null, 'raw' => '', 'error' => 'cURL chyba: ' . $err];
    }
    $decoded = json_decode($raw, true);
    $ok = $http >= 200 && $http < 300;
    return ['ok' => $ok, 'http' => $http, 'body' => $decoded, 'raw' => $raw, 'error' => $ok ? '' : ('HTTP ' . $http)];
}

/** Načte produkční API config (base_url + token) ze secrets, nebo null pokud chybí. */
function schindler_prod_api_config(): ?array
{
    $path = __DIR__ . '/../../../secrets/eshop_new_api.php';
    if (!is_file($path)) return null;
    $cfg = include $path;
    if (!is_array($cfg) || empty($cfg['base_url']) || empty($cfg['token'])) return null;
    return $cfg;
}

/** Stáhne VŠECHNY kategorie produkčního e-shopu (jen čtení, bezpečné). */
function schindler_fetch_categories(): array
{
    $cfg = schindler_prod_api_config();
    if ($cfg === null) return [];
    $baseUrl = rtrim((string)$cfg['base_url'], '/');
    $token = (string)$cfg['token'];

    $out = [];
    $page = 1;
    do {
        $res = schindler_api_call($baseUrl, $token, 'GET', '/api-engine/categories?itemsPerPage=300&page=' . $page);
        if (!$res['ok']) break;
        $members = $res['body']['hydra:member'] ?? [];
        foreach ($members as $c) {
            $out[] = ['iri' => (string)($c['@id'] ?? ''), 'name' => (string)($c['name'] ?? $c['title'] ?? ('#' . basename((string)($c['@id'] ?? ''))))];
        }
        $hasNext = isset($res['body']['hydra:view']['hydra:next']);
        $page++;
    } while ($hasNext && $page < 30);

    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** Stáhne VŠECHNY definice číselníků (dialů) - např. "Velikost bot", "Barva" apod. */
function schindler_fetch_dials(): array
{
    $cfg = schindler_prod_api_config();
    if ($cfg === null) return [];
    $baseUrl = rtrim((string)$cfg['base_url'], '/');
    $token = (string)$cfg['token'];

    $res = schindler_api_call($baseUrl, $token, 'GET', '/api-engine/product-dials?itemsPerPage=300');
    if (!$res['ok']) return [];
    $out = [];
    foreach (($res['body']['hydra:member'] ?? []) as $d) {
        $iri = (string)($d['@id'] ?? '');
        $out[] = [
            'iri' => $iri,
            'name' => (string)($d['name'] ?? $d['title'] ?? ('#' . basename($iri))),
            // V eshopu se několik číselníků jmenuje stejně ("Velikost"), takže
            // do popisku přidáváme i kus ID, aby šly rozlišit.
            'short_id' => substr(basename($iri), 0, 8),
        ];
    }
    return $out;
}

/**
 * Stáhne hodnoty daného číselníku (dialu) - pro namapování velikostí z feedu.
 *
 * DŮLEŽITÉ: serverový filtr "?productDial=..." se ukázal jako nespolehlivý
 * (vracel ~296 hodnot míchajících velikosti oblečení, bot i dětské rozsahy,
 * tj. zjevně VŠECHNY hodnoty napříč číselníky). Napojení varianty na hodnotu
 * z CIZÍHO číselníku pak na straně Eshop-rychle končí neošetřenou chybou 500.
 * Proto výsledky ještě filtrujeme na naší straně podle skutečné hodnoty pole
 * "productDial" v každém záznamu a bereme jen ty, které opravdu patří do
 * vybraného číselníku.
 *
 * Vrací ['map' => [text => IRI], 'total_seen' => int, 'kept' => int].
 */
/**
 * Stáhne hodnoty JEDNOHO konkrétního číselníku (dialu).
 *
 * OVĚŘENO diagnostikou proti živému API: ProductDialValue má pole
 *   @id, @type, dial, name, order
 * tedy vazba na číselník je "dial" (NE "productDial") a text hodnoty je
 * "name" (NE "value"). Serverový filtr "?productDial=..." nefunguje a vrací
 * všechny hodnoty napříč všemi číselníky (v ostrém eshopu 425 hodnot ve 14
 * číselnících, přičemž např. "XXL" existuje 4x v různých číselnících).
 * Napojení varianty na hodnotu z CIZÍHO číselníku končí neošetřenou chybou
 * HTTP 500 - proto filtrujeme na naší straně podle pole "dial" a bereme
 * výhradně hodnoty z vybraného číselníku.
 *
 * Vrací ['map' => [text => IRI], 'total_seen' => int, 'kept' => int].
 */
function schindler_fetch_dial_values_detailed(string $dialIri): array
{
    $cfg = schindler_prod_api_config();
    if ($cfg === null || $dialIri === '') return ['map' => [], 'total_seen' => 0, 'kept' => 0];
    $baseUrl = rtrim((string)$cfg['base_url'], '/');
    $token = (string)$cfg['token'];
    $dialId = basename($dialIri);

    $map = [];
    $totalSeen = 0;
    $page = 1;
    do {
        $res = schindler_api_call($baseUrl, $token, 'GET', '/api-engine/product-dial-values?itemsPerPage=200&page=' . $page);
        if (!$res['ok']) break;
        $members = $res['body']['hydra:member'] ?? [];
        if (empty($members)) break;

        foreach ($members as $v) {
            $totalSeen++;

            // Vazba na číselník: pole "dial" (může přijít jako IRI string
            // nebo jako vnořený objekt s @id).
            $owner = $v['dial'] ?? null;
            if (is_array($owner)) $owner = $owner['@id'] ?? '';
            $owner = (string)$owner;
            if ($owner === '' || basename($owner) !== $dialId) continue;

            $name = trim((string)($v['name'] ?? ''));
            $iri = (string)($v['@id'] ?? '');
            if ($name === '' || $iri === '') continue;

            // Klíčujeme podle NORMALIZOVANÉHO tvaru, aby se velikost z feedu
            // našla i když ji dodavatel píše jinak, než je uložená v eshopu
            // (S-M vs S/M, "58cm" vs "58 cm").
            $key = schindler_normalize_size($name);
            if ($key === '') continue;
            if (!isset($map[$key])) $map[$key] = $iri;
        }

        $hasNext = isset($res['body']['hydra:view']['hydra:next']);
        $page++;
    } while ($hasNext && $page < 30);

    return ['map' => $map, 'total_seen' => $totalSeen, 'kept' => count($map)];
}

/** Zpětně kompatibilní obal - vrací jen mapu [text velikosti => IRI hodnoty číselníku]. */
function schindler_fetch_dial_values(string $dialIri): array
{
    $d = schindler_fetch_dial_values_detailed($dialIri);
    return $d['map'];
}

/**
 * Očistí HTML popisek od atributů (class, dir, style...) - u pár produktů
 * (EXUSTAR, TUBOLITO, LIMOTEC) feed obsahuje zjevně vyexportovaný text z
 * AI chatu se zbytkovými CSS třídami typu "font-claude-response-body",
 * které by na e-shopu vypadaly rozbitě. Tagy samotné (odstavce, tučně,
 * seznamy) zůstávají zachované, jen se z nich odstraní atributy.
 */
function schindler_clean_description(string $html): string
{
    if ($html === '') return '';
    return preg_replace('/<(\/?)([a-zA-Z0-9]+)(\s+[^>]*)?>/', '<$1$2>', $html) ?? $html;
}

/**
 * Vytvoří samotný Product (bez variant).
 */
function schindler_create_product(string $baseUrl, string $token, array $productPayload): array
{
    return schindler_api_call($baseUrl, $token, 'POST', '/api-engine/products', $productPayload);
}

/**
 * Vytvoří jeden ProductItem (variantu/velikost) navázaný na existující Product.
 */
function schindler_create_product_item(string $baseUrl, string $token, array $itemPayload): array
{
    return schindler_api_call($baseUrl, $token, 'POST', '/api-engine/product-items', $itemPayload);
}

/**
 * Založí NOVOU hodnotu číselníku, i když podobná (stejný text) už existuje.
 * Používá se jako záchranný krok, když existující hodnota danou variantu
 * shazuje (ověřený případ: konkrétní poškozené hodnoty "XXL"/"3XL" v jednom
 * z číselníků) - obejde se tak poškozený záznam, aniž by se varianta musela
 * zakládat úplně BEZ navázání na číselník (což by ji nesprávně změnilo na
 * samostatný produkt místo varianty - ověřeno, API bez productVariantDefinitionList
 * vrátí isVariant:false, hasVariants:true).
 */
function schindler_force_create_dial_value(string $baseUrl, string $token, string $dialIri, string $sizeName): array
{
    if ($dialIri === '' || $sizeName === '') {
        return ['iri' => '', 'error' => 'Chybí číselník nebo velikost.'];
    }
    $res = schindler_api_call($baseUrl, $token, 'POST', '/api-engine/product-dial-values', [
        'productDial' => $dialIri,
        'value' => $sizeName,
    ]);
    if ($res['ok'] && !empty($res['body']['@id'])) {
        return ['iri' => (string)$res['body']['@id'], 'error' => ''];
    }
    $detail = is_array($res['body']) ? (string)($res['body']['detail'] ?? $res['body']['hydra:description'] ?? '') : '';
    return ['iri' => '', 'error' => 'HTTP ' . $res['http'] . ($detail !== '' ? ' - ' . $detail : '')];
}

/**
 * Zajistí IRI hodnoty číselníku (dial value) pro danou velikost:
 *  - pokud už existuje v $dialValueMap (načteno předem z API), vrátí ji rovnou,
 *  - jinak se POKUSÍ založit novou hodnotu POSTem - NENÍ jisté, že to tohle
 *    API přes zápis podporuje (otevřená otázka z podkladu), proto se chyba
 *    hlásí srozumitelně a import dané varianty se přeskočí, ne shodí celý běh.
 */
function schindler_ensure_dial_value(string $baseUrl, string $token, string $dialIri, string $sizeName, array &$dialValueMap): array
{
    if ($sizeName === '') return ['iri' => '', 'created' => false, 'error' => ''];
    if (isset($dialValueMap[$sizeName])) return ['iri' => $dialValueMap[$sizeName], 'created' => false, 'error' => ''];
    if ($dialIri === '') return ['iri' => '', 'created' => false, 'error' => 'Není vybraný číselník velikostí.'];

    $res = schindler_api_call($baseUrl, $token, 'POST', '/api-engine/product-dial-values', [
        'productDial' => $dialIri,
        'value' => $sizeName,
    ]);
    if ($res['ok'] && !empty($res['body']['@id'])) {
        $iri = (string)$res['body']['@id'];
        $dialValueMap[$sizeName] = $iri;
        return ['iri' => $iri, 'created' => true, 'error' => ''];
    }
    $detail = is_array($res['body']) ? (string)($res['body']['detail'] ?? $res['body']['hydra:description'] ?? '') : '';
    return ['iri' => '', 'created' => false, 'error' => 'HTTP ' . $res['http'] . ($detail !== '' ? ' - ' . $detail : '') . ' (hodnota "' . $sizeName . '" se nepodařilo založit v číselníku - možná to API nepodporuje).'];
}

/** Zajistí zařazení produktu do kategorie - samostatná entita, "category" pole na Product se tiše ignoruje. */
function schindler_link_product_to_category(string $baseUrl, string $token, string $productIri, string $categoryIri): array
{
    return schindler_api_call($baseUrl, $token, 'POST', '/api-engine/category-link-products', [
        'product' => $productIri,
        'category' => $categoryIri,
    ]);
}

/**
 * Přidá k produktu obrázek jako EXTERNÍ URL (obrázek zůstane na serveru
 * dodavatele, eshop si ho jen odkazuje).
 *
 * Ověřeno z OpenAPI specifikace (POST /api-engine/product-images, schéma
 * ProductHasImage-productImage.write):
 *   { "productItem": "<IRI>", "image": { "name": "<URL>", "order": <int> } }
 *
 * "order" má stejný číselník jako u uploadu:
 *   1  = hlavní obrázek (detail)
 *   2  = náhled
 *   11-16 = další obrázky do galerie
 * (3 a 4 generuje systém sám a nelze je posílat.)
 *
 * POZOR: u URL varianty je potřeba podle dodavatele nahrát URL jak pro
 * detail (1), tak pro náhled (2) - proto to volající u hlavní fotky posílá
 * dvakrát. U varianty s nahráním souboru se náhled generuje automaticky.
 */
function schindler_add_product_image_url(string $baseUrl, string $token, string $productItemIri, string $imageUrl, int $order = 1): array
{
    return schindler_api_call($baseUrl, $token, 'POST', '/api-engine/product-images', [
        'productItem' => $productItemIri,
        'image' => [
            'name' => $imageUrl,
            'order' => $order,
        ],
    ]);
}

/**
 * Přidá jednu fotku k produktu - OVĚŘENO z oficiální Hydra dokumentace API
 * (třída "UploadProductImage"): jde o MULTIPART upload SKUTEČNÉHO SOUBORU
 * (pole "file"), navázaný na konkrétní ProductItem (pole "productItem",
 * povinné) - NE na Product jako celek, a NE jako URL string. Proto se
 * obrázek musí nejdřív stáhnout ze zdrojové URL (Schindler) a teprve pak
 * nahrát jako soubor na Eshop-rychle.
 *
 * $productItemIri musí být IRI konkrétní ProductItem (u variantního produktu
 * bereme "hlavní"/sběrný řádek, u produktu bez variant tu jedinou položku).
 */
function schindler_add_product_image(string $baseUrl, string $token, string $productItemIri, string $sourceImageUrl, int $position = 1): array
{
    // 1) Stáhnout obrázek ze zdrojové URL (b2b.schindler.cz) na disk
    $tmpFile = tempnam(sys_get_temp_dir(), 'schindler_img_');
    $ch = curl_init($sourceImageUrl);
    $fp = fopen($tmpFile, 'wb');
    if ($fp === false) {
        return ['ok' => false, 'http' => 0, 'body' => null, 'error' => 'Nepodařilo se vytvořit dočasný soubor pro obrázek.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
    ]);
    $downloadOk = curl_exec($ch);
    $downloadHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    fclose($fp);

    if ($downloadOk === false || $downloadHttp < 200 || $downloadHttp >= 300 || filesize($tmpFile) === 0) {
        @unlink($tmpFile);
        return ['ok' => false, 'http' => 0, 'body' => null, 'error' => 'Stažení obrázku z ' . $sourceImageUrl . ' selhalo (HTTP ' . $downloadHttp . ').'];
    }

    // Odvodit příponu/mime typ podle Content-Type, nebo podle koncovky URL
    $ext = 'jpg';
    $mime = 'image/jpeg';
    if (stripos($contentType, 'png') !== false) { $ext = 'png'; $mime = 'image/png'; }
    elseif (stripos($contentType, 'webp') !== false) { $ext = 'webp'; $mime = 'image/webp'; }
    $filename = pathinfo(parse_url($sourceImageUrl, PHP_URL_PATH) ?: 'image', PATHINFO_FILENAME);
    $filename = 'schindler_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $filename) . '.' . $ext;

    // 2) Multipart POST na Eshop-rychle
    //
    // POZOR na cestu: upload jde na /api-engine/upload-product-images
    // (operace "api_upload-product-images_post", třída UploadProductImage),
    // NE na /api-engine/product-images - to je zdroj ProductImage pro čtení
    // a dávkové úpravy. Posílání uploadu na /product-images vracelo
    // neošetřenou chybu HTTP 500.
    //
    // Pole podle oficiální specifikace UploadProductImage:
    //   productItem (povinné, IRI ProductItem)
    //   file        (povinné, soubor)
    //   position    (nepovinné, string nebo integer)
    $url = rtrim($baseUrl, '/') . '/api-engine/upload-product-images';
    $ch2 = curl_init($url);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['X-AUTH-TOKEN: ' . $token, 'Accept: application/ld+json'],
        CURLOPT_POSTFIELDS => [
            'productItem' => $productItemIri,
            'position' => (string)$position,
            'file' => new CURLFile($tmpFile, $mime, $filename),
        ],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch2);
    $err = curl_error($ch2);
    $http = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    @unlink($tmpFile);

    if ($raw === false) {
        return ['ok' => false, 'http' => $http, 'body' => null, 'error' => 'cURL chyba: ' . $err];
    }
    $decoded = json_decode($raw, true);
    $ok = $http >= 200 && $http < 300;
    return ['ok' => $ok, 'http' => $http, 'body' => $decoded, 'error' => $ok ? '' : ('HTTP ' . $http)];
}

/**
 * Založí CELÝ produkt (Product VČETNĚ všech ProductItem variant) JEDNÍM POST
 * požadavkem na /api-engine/products s vnořeným polem "productItemList" -
 * to je jediný způsob, který API přijme (ověřeno chybovou odpovědí 422 při
 * pokusu poslat Product bez variant zvlášť, viz komentář u
 * schindler_build_product_payload).
 *
 * Před sestavením payloadu se nejdřív pro každou velikost, která ještě není
 * v $dialValueMap, zkusí založit nová hodnota číselníku (jen přidání,
 * nic nemaže) - teprve pak se s doplněnou mapou sestaví finální payload.
 */
/** Odstraní interní pomocná pole (začínající "_"), než se payload pošle na API. */
function schindler_strip_internal_fields(array $item): array
{
    foreach (array_keys($item) as $k) {
        if (str_starts_with($k, '_')) unset($item[$k]);
    }
    return $item;
}

function schindler_import_full_product(string $baseUrl, string $token, array $group, string $targetCategoryIri, string $dialIri, array $dialValueMap, bool $includeImages = true, bool $hideOutOfStock = true, float $priceDiscountPct = 0.0, bool $uploadImagesAsFiles = false): array
{
    $hasVariants = schindler_group_needs_variants($group);
    $itemNotes = [];

    if ($hasVariants) {
        // POZOR: záměrně NIC nezakládáme do číselníku automaticky. Jen
        // zkontrolujeme, jestli velikost v mapě je, a když ne, poznamenáme
        // to - doplnění hodnot do číselníku se dělá vědomě a odděleně
        // (viz sekce "Chybějící velikosti v číselníku" na import.php).
        foreach ($group['items'] as $it) {
            if ($it['size'] === '') continue;
            $sizeKey = schindler_normalize_size($it['size']);
            if ($sizeKey === '' || !isset($dialValueMap[$sizeKey])) {
                $itemNotes[$it['code']] = 'velikost "' . $it['size'] . '" není ve vybraném číselníku - varianta se na velikost nenapojí';
            }
        }
    }

    $payload = schindler_build_product_payload($group, $targetCategoryIri, $dialValueMap, $includeImages, $hideOutOfStock, $priceDiscountPct);
    $allItems = $payload['product']['productItemList'];

    // KLÍČOVÁ ZMĚNA: embedded productItemList v POST /products podporuje
    // (podle opakovaného testování - viz komentář u funkce) jen JEDNU
    // položku najednou; s více položkami padá na neošetřenou chybu 500.
    // Pošleme tedy Product s PRVNÍ položkou embedded (splní to validaci
    // "alespoň 1 prvek"), a zbytek variant doplníme zvlášť přes
    // POST /api-engine/product-items s referencí "product" na nově
    // vytvořený produkt.
    $firstItem = $allItems[0];
    $restItems = array_slice($allItems, 1);

    $firstOriginalCode = $firstItem['_original_code'];
    $createPayload = $payload['product'];
    $createPayload['productItemList'] = [schindler_strip_internal_fields($firstItem)];

    $res = schindler_api_call($baseUrl, $token, 'POST', '/api-engine/products', $createPayload);

    $log = [
        'product' => [
            'ok' => $res['ok'],
            'http' => $res['http'],
            'sent' => $createPayload,
            'body' => $res['body'],
        ],
        'items' => [],
    ];

    if (!$res['ok'] || empty($res['body']['@id'])) {
        return $log;
    }
    $productIri = (string)$res['body']['@id'];

    // První položka je hotová (přišla v odpovědi na vytvoření produktu)
    $firstCreated = $res['body']['productItemList'][0] ?? null;
    $firstLogEntry = [
        'code' => $firstOriginalCode ?? $firstItem['number'],
        'sent_number' => $firstItem['number'],
        'ok' => $firstCreated !== null,
        'http' => $res['http'],
        'sent' => schindler_strip_internal_fields($firstItem),
        'body' => $firstCreated,
        'note' => $firstOriginalCode !== null ? ($itemNotes[$firstOriginalCode] ?? '') : '',
    ];
    if (!empty($firstItem['hasVariants'])) {
        $log['collector_row'] = ['number' => $firstItem['number'], 'ok' => $firstCreated !== null, 'body' => $firstCreated];
    } else {
        $firstLogEntry['size'] = $firstItem['_size'] ?? ($group['items'][0]['size'] ?? '');
        $log['items'][] = $firstLogEntry;
    }

    // Zbylé položky (varianty) zakládáme JEDNU PO DRUHÉ zvlášť.
    foreach ($restItems as $rawItem) {
        $originalCode = $rawItem['_original_code'];
        $size = $rawItem['_size'];
        $itemPayload = schindler_strip_internal_fields($rawItem);
        $itemPayload['product'] = $productIri;
        $itemRes = schindler_api_call($baseUrl, $token, 'POST', '/api-engine/product-items', $itemPayload);

        $note = $itemNotes[$originalCode] ?? '';

        // Žádný automatický záchranný pokus (nezakládáme nové hodnoty do
        // číselníku, ani nezkoušíme založit variantu bez navázání - to by
        // ji nesprávně změnilo na samostatný produkt, viz komentář výše
        // u schindler_force_create_dial_value). Pokud založení selže,
        // jednoduše se to poctivě nahlásí a nic se navíc nezakládá.
        $log['items'][] = [
            'code' => $originalCode,
            'sent_number' => $itemPayload['number'],
            'size' => $size,
            'ok' => $itemRes['ok'],
            'http' => $itemRes['http'],
            'sent' => $itemPayload,
            'body' => $itemRes['body'],
            'note' => $note,
        ];
    }

    // Produkt se založil - teď ho ještě potřeba SAMOSTATNĚ zařadit do kategorie
    // (pole "category" na Product se, jak se ukázalo, tiše ignoruje).
    if ($res['ok'] && !empty($res['body']['@id']) && $targetCategoryIri !== '') {
        $productIri = (string)$res['body']['@id'];
        $catRes = schindler_link_product_to_category($baseUrl, $token, $productIri, $targetCategoryIri);
        $log['category_link'] = [
            'ok' => $catRes['ok'],
            'http' => $catRes['http'],
            'body' => $catRes['body'],
        ];
    }

    // Fotky - dvě možné cesty (obě potvrzené dodavatelem i specifikací):
    //  A) URL varianta (VÝCHOZÍ, rychlá): pošle se jen odkaz na obrázek
    //     u dodavatele přes POST /api-engine/product-images. U hlavní fotky
    //     je potřeba poslat URL zvlášť pro detail (order 1) i pro náhled
    //     (order 2), náhled se tu negeneruje sám.
    //  B) Upload souboru: obrázek se stáhne a nahraje na úložiště eshopu
    //     (POST /api-engine/upload-product-images). Náhled si eshop
    //     vygeneruje sám, ale je to výrazně pomalejší (stahování + upload).
    if ($res['ok'] && !empty($firstCreated['@id']) && $includeImages) {
        $mainProductItemIri = (string)$firstCreated['@id'];
        $galleryOrders = [11, 12, 13, 14, 15, 16];
        $images = array_slice(schindler_collect_group_images($group), 0, 1 + count($galleryOrders));
        $log['images'] = [];

        foreach ($images as $idx => $img) {
            if ($idx === 0) {
                // Hlavní fotka: detail (1) + náhled (2)
                $orders = $uploadImagesAsFiles ? [1] : [1, 2];
            } else {
                $orders = [$galleryOrders[$idx - 1]];
            }

            foreach ($orders as $order) {
                if ($uploadImagesAsFiles) {
                    $imgRes = schindler_add_product_image($baseUrl, $token, $mainProductItemIri, $img['url'], $order);
                } else {
                    $imgRes = schindler_add_product_image_url($baseUrl, $token, $mainProductItemIri, $img['url'], $order);
                }
                $log['images'][] = [
                    'url' => $img['url'],
                    'position' => $order,
                    'role' => $order === 1 ? 'hlavní (detail)' : ($order === 2 ? 'náhled' : 'galerie'),
                    'mode' => $uploadImagesAsFiles ? 'soubor' : 'URL',
                    'ok' => $imgRes['ok'],
                    'http' => $imgRes['http'],
                    'body' => $imgRes['body'],
                    'error' => $imgRes['error'] ?? '',
                ];
            }
        }
    }

    return $log;
}

/**
 * Zkusí zjistit, které z dodaných kódů (CODE z feedu = pole "number" v
 * Eshop-rychle) UŽ V ESHOPU EXISTUJÍ, a vrátí k nim aktuální sklad + IRI
 * konkrétní varianty i "rodičovského" produktu. Tohle je zdroj pravdy -
 * žádná vlastní evidence "co bylo naimportováno", vždycky se ptáme přímo
 * eshopu, takže se to nemůže rozejít s realitou.
 *
 * Nejdřív zkusí dávkový filtr (number[]=A&number[]=B...), což je v API
 * Platform obvyklé; pokud selže nebo vrátí nesmyslně málo/nic, spadne na
 * dotazy po jednom (pomalejší, ale funguje vždy). Vrací pole:
 * number => ['iri' => IRI product-item, 'product' => IRI product, 'stock' => int, 'ean' => string].
 */
function schindler_lookup_existing_by_numbers(string $baseUrl, string $token, array $numbers): array
{
    $numbers = array_values(array_unique(array_filter($numbers, fn($n) => $n !== '')));
    if (empty($numbers)) return [];

    // --- Pokus 1: dávkový filtr ---
    $found = [];
    $batchWorked = true;
    foreach (array_chunk($numbers, 30) as $chunk) {
        $qs = 'itemsPerPage=' . (count($chunk) + 5);
        foreach ($chunk as $n) $qs .= '&number[]=' . urlencode($n);
        $res = schindler_api_call($baseUrl, $token, 'GET', '/api-engine/product-items?' . $qs);
        if (!$res['ok'] || !is_array($res['body']['hydra:member'] ?? null)) { $batchWorked = false; break; }
        foreach ($res['body']['hydra:member'] as $pi) {
            $num = (string)($pi['number'] ?? '');
            if ($num === '') continue;
            $found[$num] = [
                'iri' => (string)($pi['@id'] ?? ''),
                'product' => (string)($pi['product'] ?? ''),
                'stock' => (int)($pi['stock'] ?? 0),
                'ean' => (string)($pi['ean'] ?? ''),
            ];
        }
    }
    if ($batchWorked) return $found;

    // --- Fallback: po jednom ---
    $found = [];
    foreach ($numbers as $n) {
        $res = schindler_api_call($baseUrl, $token, 'GET', '/api-engine/product-items?number=' . urlencode($n));
        if (!$res['ok']) continue;
        foreach (($res['body']['hydra:member'] ?? []) as $pi) {
            if ((string)($pi['number'] ?? '') !== $n) continue;
            $found[$n] = [
                'iri' => (string)($pi['@id'] ?? ''),
                'product' => (string)($pi['product'] ?? ''),
                'stock' => (int)($pi['stock'] ?? 0),
                'ean' => (string)($pi['ean'] ?? ''),
            ];
            break;
        }
    }
    return $found;
}

/** Aktualizuje sklad u JEDNÉ existující varianty (ověřený PATCH vzor, stejný jako jinde v aplikaci). */
function schindler_update_stock(string $baseUrl, string $token, string $productItemIri, int $newStock): array
{
    return schindler_api_call($baseUrl, $token, 'PATCH', $productItemIri, ['stock' => $newStock]);
}

/**
 * Zkrátí kód produktu (number) na max. délku - Eshop-rychle vrací neošetřenou
 * HTTP 500 u kódů nad 20 znaků (ověřeno, viz bug report). Zachová začátek
 * (kvůli čitelnosti) a připojí krátký deterministický "otisk" z CELÉHO
 * původního kódu (CRC32, 6 hex znaků) - stejný vstup vždy dá stejný výstup,
 * což je důležité pro budoucí párování při aktualizaci skladu (stejný kód
 * z feedu musí vždy vygenerovat stejný zkrácený kód).
 */
function schindler_shorten_code(string $code, int $maxLen = 20): string
{
    if (strlen($code) <= $maxLen) return $code;
    $hash = strtoupper(substr(dechex(crc32($code)), 0, 6));
    $prefixLen = max(0, $maxLen - strlen($hash));
    return substr($code, 0, $prefixLen) . $hash;
}

/**
 * Založí do vybraného číselníku hodnoty, které v něm zatím chybí.
 * Zakládá VÝHRADNĚ to, co dostane v $sizes - nic dalšího si nevymýšlí.
 *
 * Povinná pole ProductDialValue (ověřeno chybovou odpovědí 422 z ostrého API):
 *   dial  - IRI číselníku
 *   name  - text hodnoty
 *   order - pořadí zobrazení, NESMÍ být prázdné
 *
 * $startOrder určuje, od jakého pořadí se začne (aby se nové hodnoty
 * zařadily za ty stávající). Krok je 10, ať jde později ručně něco vložit
 * mezi ně bez přečíslovávání.
 *
 * Vrací protokol: [['size' => 'XXL', 'ok' => true, 'http' => 201, 'iri' => '...'], ...]
 */
function schindler_create_dial_values(string $baseUrl, string $token, string $dialIri, array $sizes, int $startOrder = 10, array $existingMap = []): array
{
    $log = [];
    $order = $startOrder;
    foreach ($sizes as $size) {
        $size = trim((string)$size);
        if ($size === '') continue;

        // Pojistka proti duplicitám: pokud hodnota (v normalizovaném tvaru)
        // v číselníku už je, nezakládáme ji znovu. Chrání to před dvojitým
        // odesláním formuláře nebo obnovením stránky po odeslání.
        $key = schindler_normalize_size($size);
        if ($key !== '' && isset($existingMap[$key])) {
            $log[] = [
                'size' => $size,
                'order' => null,
                'ok' => true,
                'http' => 0,
                'iri' => $existingMap[$key],
                'skipped' => true,
                'body' => null,
            ];
            continue;
        }

        $res = schindler_api_call($baseUrl, $token, 'POST', '/api-engine/product-dial-values', [
            'dial' => $dialIri,
            'name' => $size,
            'order' => $order,
        ]);
        $log[] = [
            'size' => $size,
            'order' => $order,
            'ok' => $res['ok'],
            'http' => $res['http'],
            'iri' => (string)($res['body']['@id'] ?? ''),
            'skipped' => false,
            'body' => $res['body'],
        ];
        $order += 10;
    }
    return $log;
}

/** Zjistí nejvyšší použité "order" ve vybraném číselníku (kvůli řazení nových hodnot za stávající). */
function schindler_max_dial_order(string $dialIri): int
{
    $cfg = schindler_prod_api_config();
    if ($cfg === null || $dialIri === '') return 0;
    $baseUrl = rtrim((string)$cfg['base_url'], '/');
    $token = (string)$cfg['token'];
    $dialId = basename($dialIri);

    $max = 0;
    $page = 1;
    do {
        $res = schindler_api_call($baseUrl, $token, 'GET', '/api-engine/product-dial-values?itemsPerPage=200&page=' . $page);
        if (!$res['ok']) break;
        $members = $res['body']['hydra:member'] ?? [];
        if (empty($members)) break;
        foreach ($members as $v) {
            $owner = $v['dial'] ?? null;
            if (is_array($owner)) $owner = $owner['@id'] ?? '';
            if ((string)$owner === '' || basename((string)$owner) !== $dialId) continue;
            $o = (int)($v['order'] ?? 0);
            if ($o > $max) $max = $o;
        }
        $hasNext = isset($res['body']['hydra:view']['hydra:next']);
        $page++;
    } while ($hasNext && $page < 30);

    return $max;
}

/**
 * Sjednotí ZÁPIS velikosti, aby se stejná velikost zapsaná dodavatelem
 * různě nezaložila v číselníku vícekrát. Sjednocuje VÝHRADNĚ formátování,
 * NIKDY neslučuje různé rozměry:
 *   "S-M (54-58 cm)", "S/M (54-58cm)", "S/M (54-58 cm)"  ->  "S/M (54-58 cm)"
 * ale "S/M (51-56 cm)" a "S/M (54-58 cm)" zůstávají oddělené, protože jde
 * o jiné obvody hlavy (u helem podstatný rozdíl).
 *
 * Pravidla:
 *  - pomlčka mezi PÍSMENY -> lomítko (S-M => S/M); číselné rozsahy si
 *    pomlčku ponechají (54-58)
 *  - sjednocené mezery kolem lomítka, pomlčky a závorek
 *  - vždy mezera před jednotkou ("58cm" => "58 cm")
 */
function schindler_normalize_size(string $size): string
{
    $out = trim($size);
    if ($out === '') return '';
    $out = preg_replace('/\s+/u', ' ', $out);
    $out = preg_replace('/(?<=\p{L})\s*-\s*(?=\p{L})/u', '/', $out);
    $out = preg_replace('/\s*\/\s*/u', '/', $out);
    $out = preg_replace('/(\d)\s*-\s*(\d)/u', '$1-$2', $out);
    $out = preg_replace('/(\d)\s*(cm|mm)\b/iu', '$1 $2', $out);
    $out = preg_replace('/\(\s*/u', '(', $out);
    $out = preg_replace('/\s*\)/u', ')', $out);
    return trim($out);
}

/**
 * Odřízne z názvu produktu koncovou velikost ("... - S/M (49-56cm)").
 *
 * Porovnává se v normalizovaném tvaru, protože dodavatel píše velikost
 * v názvu jinak než v tagu VARIANT ("- XS/S (46-51cm)" vs "XS-S (46-51 cm)").
 */
function schindler_strip_size_from_name(string $name, string $size): string
{
    $name = trim($name);
    $size = trim($size);
    if ($name === '' || $size === '') return $name;

    $normSize = schindler_normalize_size($size);
    // Projdeme možné dělicí body od konce a hledáme ten, za kterým je velikost.
    foreach ([' - ', ' – ', ' — '] as $sep) {
        $pos = mb_strrpos($name, $sep);
        if ($pos === false) continue;
        $tail = mb_substr($name, $pos + mb_strlen($sep));
        if (schindler_normalize_size($tail) === $normSize) {
            return trim(mb_substr($name, 0, $pos));
        }
    }
    return $name;
}

/**
 * Spočítá naši prodejní cenu z MOC s DPH po odečtení slevy v procentech.
 * Zaokrouhluje na celé koruny. Sleva 0 => cena zůstává rovna MOC.
 */
function schindler_apply_discount(float $mocWithVat, float $discountPct): float
{
    if ($discountPct <= 0) return round($mocWithVat, 2);
    $discountPct = min($discountPct, 95.0); // pojistka proti nesmyslné slevě
    return round($mocWithVat * (1 - $discountPct / 100));
}

/** Má se produkt v eshopu založit jako hlavní produkt + varianty? */
function schindler_group_needs_variants(array $group): bool
{
    if (count($group['items']) > 1) return true;
    return count($group['items']) === 1
        && trim((string)($group['items'][0]['size'] ?? '')) !== '';
}

/**
 * Sestaví PŘEDPOKLÁDANÝ payload pro založení jednoho produktu se všemi
 * variantami VČETNĚ - podle skutečné odpovědi API (ověřeno 422 na produkci,
 * 30.8.2026) musí POST /api-engine/products obsahovat rovnou pole
 * "productItemList" s alespoň jednou položkou; samostatné vytvoření Product
 * bez variant a jejich doplnění zvlášť přes POST /product-items NEFUNGUJE
 * (validace to odmítne: "productItemList: This collection should contain
 * 1 element or more."). Product a všechny jeho varianty se tedy zakládají
 * jedním jediným POST požadavkem.
 *
 * $dialValueMap: ['XXS' => '/api-engine/product-dial-values/123', ...] - namapované
 * hodnoty velikosti na existující číselník (viz schindler_fetch_dial_values).
 */
function schindler_build_product_payload(array $group, string $targetCategoryIri, array $dialValueMap = [], bool $includeImages = true, bool $hideOutOfStock = true, float $priceDiscountPct = 0.0): array
{
    // Variantní strukturu (sběrný řádek + varianty) zakládáme nejen když má
    // produkt víc položek, ale i když má JEDINOU položku s vyplněnou
    // velikostí - i tehdy chceme v eshopu hlavní produkt BEZ velikosti
    // v názvu a pod ním jednu variantu s velikostí v roletce.
    $hasVariants = schindler_group_needs_variants($group);

    // Název hlavního produktu bez velikosti. U jednopoložkových produktů
    // musíme velikost odříznout tady (u vícepoložkových ji odstranil už
    // parser feedu podle společného začátku názvů variant).
    $productName = $group['name'];
    if (count($group['items']) === 1) {
        $productName = schindler_strip_size_from_name($productName, (string)($group['items'][0]['size'] ?? ''));
    }

    $itemsPayload = [];

    // U produktu S VARIANTAMI (velikostmi) přidáváme na začátek "sběrný" řádek
    // (hasVariants: true, isVariant: false) - podle dřívějšího průzkumu
    // datového modelu (čtení existujících produktů v eshopu) je tohle
    // řádek NAD jednotlivými variantami, nemá mít vlastní prodejní sklad.
    // Bez něj serverová strana zjevně padá na neošetřenou chybu (500) - u
    // produktů BEZE variant (jen 1 položka) tenhle řádek naopak nechceme.
    if ($hasVariants) {
        $first = $group['items'][0];
        $groupNumber = !empty($group['id']) ? ('GRP' . $group['id']) : ('GRP-' . preg_replace('/[^A-Za-z0-9]/', '', $group['name']));
        // Sběrný řádek skryjeme jen tehdy, když NENÍ skladem ANI JEDNA
        // varianta - jinak by se schoval celý produkt včetně velikostí,
        // které skladem jsou.
        $anyInStock = false;
        foreach ($group['items'] as $chk) { if ((int)$chk['stock'] > 0) { $anyInStock = true; break; } }
        $itemsPayload[] = [
            'name'          => $productName,
            'number'        => $groupNumber,
            'ean'           => '',
            'price'         => schindler_apply_discount((float)$first['customer_price'], $priceDiscountPct),
            'commonPrice'   => (float)$first['customer_price'],
            'purchasePrice' => (float)$first['purchase_price'],
            'stock'         => 0,
            'isOnStock'     => false,
            'isInvisible'   => $hideOutOfStock ? !$anyInStock : false,
            'isVariant'     => false,
            'hasVariants'   => true,
            'productVariantDefinitionList' => [],
            '_original_code' => null,
            '_size' => null,
        ];
    }

    foreach ($group['items'] as $it) {
        $variantDefs = [];
        if ($hasVariants && $it['size'] !== '') {
            $sizeKey = schindler_normalize_size($it['size']);
            if ($sizeKey !== '' && isset($dialValueMap[$sizeKey])) {
                $variantDefs[] = ['productDialValue' => $dialValueMap[$sizeKey]];
            }
        }

        $itemsPayload[] = [
            'name'          => $it['name'],
            'number'        => schindler_shorten_code($it['code']),
            'ean'           => $it['ean'],
            // Naše prodejní cena = MOC s DPH mínus zvolená sleva.
            // Běžná (přeškrtnutá) cena = MOC s DPH v původní výši.
            'price'         => schindler_apply_discount((float)$it['customer_price'], $priceDiscountPct),
            'commonPrice'   => (float)$it['customer_price'],
            'purchasePrice' => (float)$it['purchase_price'],
            'stock'         => (int)$it['stock'],
            'isOnStock'     => (int)$it['stock'] > 0,
            // Když není skladem, variantu v eshopu skryjeme (pole ověřené
            // v odpovědích API). Při pozdější aktualizaci skladu se to
            // přepíná zpět, viz aktualizace-skladu.
            'isInvisible'   => $hideOutOfStock ? ((int)$it['stock'] <= 0) : false,
            'isVariant'     => $hasVariants,
            'hasVariants'   => false,
            'productVariantDefinitionList' => $variantDefs,
            // Pomocná pole POUZE pro naši interní logiku (párování s
            // původním feedem, zobrazení) - odstraní se před odesláním,
            // viz schindler_strip_internal_fields().
            '_original_code' => $it['code'],
            '_size' => $it['size'],
        ];
    }

    $productPayload = [
        'productCommon' => [
            'description' => schindler_clean_description(schindler_pick_group_description($group)),
            'perex'       => $group['items'][0]['desc_short'] ?? '',
        ],
        'category' => $targetCategoryIri,
        'name'     => $group['name'],
        'productItemList'  => $itemsPayload,
    ];
    // POZN.: productImageList se sem záměrně NEDÁVÁ - ověřeno, že se na
    // Product tiše ignoruje. Fotky se přidávají zvlášť, viz
    // schindler_add_product_image() volané z schindler_import_full_product().

    return ['product' => $productPayload];
}

/** Vezme popis z první varianty, kde nějaký je vyplněný (feedy mají někdy popis jen u některé velikosti/barvy). */
function schindler_pick_group_description(array $group): string
{
    foreach ($group['items'] as $it) {
        if (!empty($it['description'])) return $it['description'];
    }
    return '';
}

/**
 * Posbírá VŠECHNY unikátní fotky napříč variantami skupiny (hlavní fotka
 * první varianty jako první) do jedné galerie na úrovni produktu. U feedu
 * jsme si ověřili, že se fotka může lišit i mezi variantami stejné skupiny
 * (typicky barevné varianty) - dokud nevíme, jestli API umí obrázek navázat
 * na konkrétní variantu zvlášť, posíláme je všechny na produkt jako celek.
 * Pole názvu ("url") je odhad podle běžné konvence - pokud ho API odmítne,
 * z chybové hlášky (stejně jako u productItemList) doladíme přesný název.
 */
function schindler_collect_group_images(array $group): array
{
    $urls = [];
    foreach ($group['items'] as $it) {
        if (!empty($it['image'])) $urls[] = $it['image'];
        foreach (($it['images_alt'] ?? []) as $alt) {
            if (!empty($alt)) $urls[] = $alt;
        }
    }
    $urls = array_values(array_unique($urls));
    return array_map(fn($u) => ['url' => $u], $urls);
}
