<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  CFLOAT-NEW / price_engine_xml_only.php   (v2 – výkonově bezpečná verze)
 * ===========================================================================
 *  Nová logika doplňování NÁKUPNÍ CENY (s DPH) do order_items.nakupni_cena.
 *
 *  DŮLEŽITÉ – liší se od staré logiky (fill_purchase_price_auto.php):
 *   - Cena se NIKDY nebere z DB tabulek Nakupni_ceny / Nakupni_ceny_kody /
 *     vavrys_variants. Bere se VÝHRADNĚ z XML feedů dodavatelů.
 *   - Tento soubor NIC neupravuje ve starém indexu ani ve starých modulech.
 *   - order_items.nakupni_cena je sdílený sloupec s existující DB, takže
 *     jakmile se cena doplní, uvidí ji i stará administrace – ale doplňuje ji
 *     výhradně nová logika níže.
 *
 *  VERZE 2 – OPRAVA VÝKONU:
 *   DEVOLD feed má ~54 MB / 30 800 položek, VAVRYS katalog ~20 MB / 1 500
 *   položek. Načtení celého souboru do DOMDocument (v1) na sdíleném hostingu
 *   spadalo na limitu paměti/CPU (503 Service Temporarily Unavailable).
 *   Proto se nyní VŽDY parsuje/zálohuje přes soubor na disku a čte se
 *   STREAMOVANĚ (XMLReader), nikdy se celý feed nenahrává do jedné velké
 *   PHP proměnné.
 *
 *  ZÁLOHOVÁNÍ FEEDŮ:
 *   Při každém spuštění se aktuální feed (soubor na disku, u SILVINI se
 *   nejdřív stáhne do dočasného souboru) porovná (md5_file) s poslední
 *   uloženou zálohou. Pokud se liší, zkopíruje se (copy(), ne přes paměť)
 *   nová záloha do cfloat-new/feed-archive/<DODAVATEL>/.
 *   Když se cena nenajde v aktuálním feedu (např. poslední kus byl objednán
 *   a dodavatel ho mezitím z feedu odebral), prohledají se zálohy od
 *   nejnovější po nejstarší.
 *
 *  ZDROJE:
 *   - DEVOLD  : Dodavatele/DEVOLD/XML/c.xml       -> <ean>, <code>/<katalog>/<varianta>, <voc>
 *               cena = voc * 1.21
 *   - VAVRYS  : Dodavatele/Vavrys/vavrys_katalog.xml (příp. _soap.xml)
 *               -> <CarKod> (EAN) i <PozY2> (kód, např. "1914792-376000(3760)",
 *               závorka s velikostí se ořízne), <Cena>   cena = Cena * 0.97 * 1.21
 *               (zahrnuje i další značky distribuované přes Vavrys, např. Haglöfs, CRAFT)
 *   - SILVINI : živé XML http://95.80.221.202:3380/REPORTS_SALESPRICE_CZK.XML
 *               (element <SERVICE_REPORTS_SALESPRICE_CZK>, jen EAN – bez kódu)
 *               cena = CZ_BUY_PROMO*1.21 pokud CZ_BUY_PROMO < CZ_BUY_BASIC (akce),
 *               jinak CZ_BUY_BASIC*0.78*1.21
 *   - ISADORE : Dodavatele/ISADORE/isadore.xml     -> <g:ean>, <g:price>
 *               cena (nákupní s DPH) = g:price * 0.60   (dle zadání: -40 % z MOC)
 *
 *   - SPORTIMPORT : živé XML https://www.sportimport.cz/export/xml/catalogue/all
 *               (element <item>, cena = první <dealerPrices>/<price> (CZK bez DPH) × 1.21,
 *               EAN a kód z atributů <variant ean=".." code="..">)
 *
 * ===========================================================================
 */

const CFLOAT2_SILVINI_URL = 'http://95.80.221.202:3380/REPORTS_SALESPRICE_CZK.XML';
const CFLOAT2_SPORTIMPORT_URL = 'https://www.sportimport.cz/export/xml/catalogue/all';
const CFLOAT2_SUPPLIERS = ['DEVOLD', 'VAVRYS', 'SILVINI', 'ISADORE', 'SPORTIMPORT'];
// Živé feedy (SILVINI, SPORTIMPORT) se znovu stahují z internetu jen když je
// uložená kopie starší než tohle – jinak se použije to, co už máme na disku.
const CFLOAT2_LIVE_FEED_MAX_AGE_SECONDS = 86400; // 24 hodin

function cfloat2_www_root(): string
{
    // cfloat-new/lib -> cfloat-new -> www
    return dirname(__DIR__, 2);
}

function cfloat2_tmp_dir(): string
{
    $dir = __DIR__ . '/../tmp-feed';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function cfloat2_archive_dir(string $supplier): string
{
    $dir = __DIR__ . '/../feed-archive/' . $supplier;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

// ---------------------------------------------------------------------------
// Normalizace / parsování hodnot
// ---------------------------------------------------------------------------

function cfloat2_norm_ean($value): ?string
{
    $s = trim((string)$value);
    if ($s === '') return null;
    $digits = preg_replace('/\D+/', '', $s) ?? '';
    $len = strlen($digits);
    if ($len < 8 || $len > 14) return null;
    return $digits;
}

function cfloat2_norm_code($value): ?string
{
    $s = trim((string)$value);
    if ($s === '') return null;
    $s = str_replace(["\xC2\xA0", "\r", "\n", "\t"], [' ', ' ', ' ', ' '], $s);
    $s = preg_replace('/\s+/u', '', $s) ?? '';
    if ($s === '') return null;
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}

function cfloat2_parse_number($value): ?float
{
    $s = trim((string)$value);
    if ($s === '') return null;
    $s = preg_replace('/[A-Za-z\x{00A0}\s]+/u', '', $s) ?? ''; // odstraní měnu / mezery, např. "4475.00 CZK"
    $s = str_replace(',', '.', $s);
    $s = preg_replace('/[^0-9.\-]/', '', $s) ?? '';
    if ($s === '' || !is_numeric($s)) return null;
    $v = (float)$s;
    if (!is_finite($v) || $v <= 0) return null;
    return $v;
}

/** Rychlé čtení hodnoty PŘÍMÉHO potomka (bez drahého XPath) – feedy jsou ploché. */
function cfloat2_child_text(DOMElement $el, array $names): ?string
{
    $names = array_map('strtolower', $names);
    foreach ($el->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE) continue;
        if (in_array(strtolower($child->localName ?? $child->nodeName), $names, true)) {
            $v = trim((string)$child->textContent);
            if ($v !== '') return $v;
        }
    }
    return null;
}

/**
 * Vavrys kódy (pole PozY2) mají někdy tvar "1914792-376000(3760)", kde
 * číslo v závorce označuje velikost/variantu, ne barvu/styl. Cena je ale
 * pro všechny velikosti stejné barvy stejná, takže pro párování ceny
 * podle kódu se závorka ořízne pryč a bere se jen část před ní.
 */
function cfloat2_strip_paren_suffix(string $code): string
{
    return preg_replace('/\s*\([^)]*\)\s*$/', '', $code) ?? $code;
}

/**
 * SILVINI interní kód (pole CISLO_MAT) má tvar "3120-MJ1612-22222":
 *  - "3120-"   = číselná sezónní předpona, kterou v našem vlastním kódu nemáme
 *  - "MJ1612"  = modelový kód
 *  - "22222"   = barva/velikost, poslední číslice značí velikost a nás
 *                nezajímá (cena je pro všechny velikosti stejná)
 * Náš vlastní formát kódu je pak "MJ1612-2222" (o jednu číslici kratší,
 * bez sezónní předpony).
 */
function cfloat2_silvini_derive_own_code(string $cisloMat): ?string
{
    $s = trim($cisloMat);
    if ($s === '') return null;
    $s = preg_replace('/^\d+-/', '', $s) ?? $s; // pryč se sezónní předponou
    if (preg_match('/^(.*-)(\d+)$/', $s, $m) && strlen($m[2]) > 1) {
        $s = $m[1] . substr($m[2], 0, -1); // pryč s poslední číslicí (velikost)
    }
    return $s !== '' ? $s : null;
}

/** Všechny hodnoty přímých potomků odpovídajících jménům (pro alternativní kódy). */
function cfloat2_child_texts(DOMElement $el, array $names): array
{
    $names = array_map('strtolower', $names);
    $out = [];
    foreach ($el->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE) continue;
        if (in_array(strtolower($child->localName ?? $child->nodeName), $names, true)) {
            $v = trim((string)$child->textContent);
            if ($v !== '' && !isset($out[$v])) $out[$v] = $v;
        }
    }
    return array_values($out);
}

// ---------------------------------------------------------------------------
// Zajištění lokálního SOUBORU s aktuálním feedem (žádné velké stringy v RAM)
//   -> vrací cestu k souboru, nebo null
// ---------------------------------------------------------------------------

function cfloat2_current_file(string $supplier): ?string
{
    $root = cfloat2_www_root();

    if ($supplier === 'DEVOLD') {
        foreach (['C.xml', 'c.xml'] as $name) {
            $p = $root . '/Dodavatele/DEVOLD/XML/' . $name;
            if (is_file($p) && filesize($p) > 0) return $p;
        }
        return null;
    }

    if ($supplier === 'VAVRYS') {
        foreach (['vavrys_katalog.xml', 'vavrys_katalog_soap.xml'] as $name) {
            $p = $root . '/Dodavatele/Vavrys/' . $name;
            if (is_file($p) && filesize($p) > 0) return $p;
        }
        return null;
    }

    if ($supplier === 'ISADORE') {
        $p = $root . '/Dodavatele/ISADORE/isadore.xml';
        if (is_file($p) && filesize($p) > 0) return $p;
        return null;
    }

    if ($supplier === 'SILVINI') {
        return cfloat2_download_url_to_file(CFLOAT2_SILVINI_URL, 'silvini_current.xml', CFLOAT2_LIVE_FEED_MAX_AGE_SECONDS);
    }

    if ($supplier === 'SPORTIMPORT') {
        // "all" = všechny značky, výchozí měna CZK (dle oficiální dokumentace SportImportu)
        return cfloat2_download_url_to_file(CFLOAT2_SPORTIMPORT_URL, 'sportimport_current.xml', CFLOAT2_LIVE_FEED_MAX_AGE_SECONDS);
    }

    return null;
}

/**
 * Obecné stažení libovolé URL přímo do souboru (ne do paměti).
 * Pokud už existuje čerstvá lokální kopie (mladší než $maxAgeSeconds),
 * použije se rovnou ta a na dodavatelský server se vůbec nesahá – stačí
 * tedy stahovat třeba jednou denně, ne při každém kliknutí.
 */
function cfloat2_download_url_to_file(string $url, string $filename, int $maxAgeSeconds = 0): ?string
{
    $dest = cfloat2_tmp_dir() . '/' . $filename;

    if ($maxAgeSeconds > 0 && is_file($dest) && filesize($dest) > 0) {
        $age = time() - (int)@filemtime($dest);
        if ($age >= 0 && $age < $maxAgeSeconds) {
            return $dest; // dost čerstvé, nestahujeme znovu
        }
    }

    if (!function_exists('curl_init')) return is_file($dest) ? $dest : null; // fallback na starou kopii, pokud nějaká je
    $tmp = $dest . '.part';
    $fh = @fopen($tmp, 'wb');
    if (!$fh) return is_file($dest) ? $dest : null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_USERAGENT => 'CFloat-New-Price-XML-Only/2.0',
        CURLOPT_HTTPHEADER => ['Accept: application/xml,text/xml,*/*'],
    ]);
    $ok = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if ($ok === false || $http >= 400 || $http === 0 || !is_file($tmp) || filesize($tmp) < 100) {
        @unlink($tmp);
        // stažení selhalo – radši použít starší uloženou kopii než nic
        return is_file($dest) ? $dest : null;
    }
    $head = @file_get_contents($tmp, false, null, 0, 256) ?: '';
    if (stripos(ltrim($head), '<html') === 0 || stripos(ltrim($head), '<!doctype html') === 0) {
        @unlink($tmp);
        return is_file($dest) ? $dest : null;
    }
    if (!@rename($tmp, $dest)) {
        if (!@copy($tmp, $dest)) { @unlink($tmp); return is_file($dest) ? $dest : null; }
        @unlink($tmp);
    }
    return $dest;
}

// ---------------------------------------------------------------------------
// Zálohování (archivace) feedů – vždy přes soubor, nikdy přes velký string
// ---------------------------------------------------------------------------

/** @return array{created:bool, path:?string} */
function cfloat2_archive_snapshot_file(string $supplier, string $sourcePath): array
{
    $dir = cfloat2_archive_dir($supplier);

    $files = glob($dir . '/*.xml') ?: [];
    sort($files);
    $latest = $files ? end($files) : null;

    if ($latest !== null && is_file($latest)) {
        if (@md5_file($latest) === @md5_file($sourcePath)) {
            return ['created' => false, 'path' => $latest]; // beze změny
        }
    }

    $name = date('Ymd_His') . '_' . substr(md5_file($sourcePath) ?: uniqid(), 0, 8) . '.xml';
    $path = $dir . '/' . $name;
    if (!@copy($sourcePath, $path)) {
        return ['created' => false, 'path' => null];
    }
    return ['created' => true, 'path' => $path];
}

/** @return string[] cesty k zálohám, seřazené od NEJNOVĚJŠÍ po nejstarší */
function cfloat2_list_archive_desc(string $supplier): array
{
    $files = glob(cfloat2_archive_dir($supplier) . '/*.xml') ?: [];
    rsort($files);
    return $files;
}

// ---------------------------------------------------------------------------
// Streamované parsery (XMLReader) -> ['by_ean'=>[ean=>price], 'by_code'=>[code=>price]]
// Fungují stejně nad AKTUÁLNÍM souborem i nad ARCHIVOVANÝM souborem.
// ---------------------------------------------------------------------------

function cfloat2_parse_devold_file(string $path): array
{
    $byEan = [];
    $byCode = [];
    $reader = new XMLReader();
    if (!$reader->open($path, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        return ['by_ean' => $byEan, 'by_code' => $byCode];
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || strtolower($reader->localName) !== 'item') continue;
        $node = $reader->expand($doc);
        if (!$node instanceof DOMElement) continue;

        $voc = cfloat2_parse_number(cfloat2_child_text($node, ['voc']) ?? '');
        if ($voc === null) continue;
        $price = $voc * 1.21;

        $ean = cfloat2_norm_ean(cfloat2_child_text($node, ['ean', 'barcode', 'eancode']) ?? '');
        if ($ean !== null && !isset($byEan[$ean])) $byEan[$ean] = $price;

        foreach (cfloat2_child_texts($node, ['code', 'katalog', 'varianta', 'sku']) as $code) {
            $c = cfloat2_norm_code($code);
            if ($c !== null && !isset($byCode[$c])) $byCode[$c] = $price;
        }
    }
    $reader->close();
    return ['by_ean' => $byEan, 'by_code' => $byCode];
}

function cfloat2_parse_vavrys_file(string $path): array
{
    $byEan = [];
    $byCode = [];
    $byCodePrefix = [];
    $reader = new XMLReader();
    if (!$reader->open($path, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        return ['by_ean' => $byEan, 'by_code' => $byCode, 'by_code_prefix' => $byCodePrefix];
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || strtolower($reader->localName) !== 'sortimentpolozka') continue;
        $node = $reader->expand($doc);
        if (!$node instanceof DOMElement) continue;

        $ean = cfloat2_norm_ean(cfloat2_child_text($node, ['CarKod']) ?? '');
        $cena = cfloat2_parse_number(cfloat2_child_text($node, ['Cena']) ?? '');
        if ($cena === null) continue;
        $price = $cena * 0.97 * 1.21;

        if ($ean !== null && !isset($byEan[$ean])) $byEan[$ean] = $price;

        // Kód produktu (PozY2), např. "1914792-376000(3760)" – závorka s číslem
        // velikosti se ořízne, protože cena je pro všechny velikosti stejné
        // barvy/stylu stejná (funguje pro CRAFT, Haglöfs a další značky
        // distribuované přes Vavrys).
        $rawCode = cfloat2_child_text($node, ['PozY2']);
        if ($rawCode !== null) {
            $stripped = cfloat2_strip_paren_suffix($rawCode);
            $c = cfloat2_norm_code($stripped);
            if ($c !== null && !isset($byCode[$c])) $byCode[$c] = $price;

            // Záchranný fallback: jen část kódu PŘED první pomlčkou
            // (např. "1913615" z "1913615-629000") – použije se jen když
            // selže přesný EAN i přesný kód. Cena různých barev stejného
            // modelu se sice může lišit, ale je to poslední možnost, než
            // aby zůstala nákupní cena úplně prázdná.
            $dashPos = strpos($c, '-');
            if ($dashPos !== false && $dashPos > 0) {
                $prefix = substr($c, 0, $dashPos);
                if (!isset($byCodePrefix[$prefix])) $byCodePrefix[$prefix] = $price;
            }
        }
    }
    $reader->close();
    return ['by_ean' => $byEan, 'by_code' => $byCode, 'by_code_prefix' => $byCodePrefix];
}

function cfloat2_parse_silvini_file(string $path): array
{
    $byEan = [];
    $byCode = [];
    $reader = new XMLReader();
    if (!$reader->open($path, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        return ['by_ean' => $byEan, 'by_code' => $byCode];
    }
    $recordNames = ['service_reports_salesprice_czk', 'reports_salesprice_czk', 'reports_salesprice', 'salesprice', 'item', 'row', 'record'];
    $doc = new DOMDocument('1.0', 'UTF-8');

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) continue;
        $name = strtolower($reader->localName);
        if (!in_array($name, $recordNames, true)) continue;
        $node = $reader->expand($doc);
        if (!$node instanceof DOMElement) continue;

        $ean = cfloat2_norm_ean(cfloat2_child_text($node, ['ean', 'barcode', 'eancode']) ?? '');
        if ($ean === null) continue;

        $basic = cfloat2_parse_number(cfloat2_child_text($node, ['cz_buy_basic']) ?? '');
        $promo = cfloat2_parse_number(cfloat2_child_text($node, ['cz_buy_promo']) ?? '');

        // Pravidlo: pokud je CZ_BUY_PROMO nižší než CZ_BUY_BASIC (reálná akční
        // cena), bere se přímo × 1.21. Pokud jsou ceny stejné (žádná akce),
        // bere se CZ_BUY_BASIC × 0.78 × 1.21.
        $price = null;
        if ($basic !== null && $promo !== null && $promo < $basic) {
            $price = $promo * 1.21;
        } elseif ($basic !== null) {
            $price = $basic * 0.78 * 1.21;
        } elseif ($promo !== null) {
            $price = $promo * 0.78 * 1.21;
        }
        if ($price === null || $price <= 0) continue;

        if (!isset($byEan[$ean])) $byEan[$ean] = $price;

        // Odvozený vlastní kód z CISLO_MAT (bez sezónní předpony a bez
        // poslední číslice velikosti) – viz cfloat2_silvini_derive_own_code().
        $cisloMat = cfloat2_child_text($node, ['cislo_mat']);
        if ($cisloMat !== null) {
            $derived = cfloat2_silvini_derive_own_code($cisloMat);
            if ($derived !== null) {
                $dc = cfloat2_norm_code($derived);
                if ($dc !== null && !isset($byCode[$dc])) $byCode[$dc] = $price;
            }
        }

        foreach (cfloat2_child_texts($node, ['poslsort', 'cislo_mat', 'code', 'kod', 'product_code', 'sku']) as $code) {
            $c = cfloat2_norm_code($code);
            if ($c !== null && !isset($byCode[$c])) $byCode[$c] = $price;
        }
    }
    $reader->close();
    return ['by_ean' => $byEan, 'by_code' => $byCode];
}

function cfloat2_parse_isadore_file(string $path): array
{
    $byEan = [];
    $byCode = [];
    $reader = new XMLReader();
    if (!$reader->open($path, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        return ['by_ean' => $byEan, 'by_code' => $byCode];
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || strtolower($reader->localName) !== 'item') continue;
        $node = $reader->expand($doc);
        if (!$node instanceof DOMElement) continue;

        $ean = cfloat2_norm_ean(cfloat2_child_text($node, ['ean']) ?? '');
        $moc = cfloat2_parse_number(cfloat2_child_text($node, ['price']) ?? '');
        if ($ean === null || $moc === null) continue;
        // Zadání: nákupní cena s DPH = MOC - 40 %  =>  MOC * 0.60
        $price = $moc * 0.60;
        if (!isset($byEan[$ean])) $byEan[$ean] = $price;
    }
    $reader->close();
    return ['by_ean' => $byEan, 'by_code' => $byCode];
}

/**
 * SPORTIMPORT – potvrzeno reálnými daty z feedu (formát "Sport Import v1.0"):
 *   <item id=".." code="..">
 *     <price>..</price>            <- maloobchodní (RRP), NEPOUŽÍVÁ SE
 *     <dealerPrices>
 *       <price>38550</price>       <- PRVNÍ price = CZK velkoobchodní cena bez DPH
 *       <rdp>38550</rdp>
 *       <price>1542</price>        <- další měny (EUR/HUF/PLN – nepoužívají se)
 *       ...
 *     </dealerPrices>
 *     <variants>
 *       <variant id=".." code="ASRAW33CSGLS-SH" ean="8720663232236">...</variant>
 *       ...
 *     </variants>
 *   </item>
 * Nákupní cena s DPH = (první dealerPrices/price) × 1.21.
 * EAN i kód se berou z atributů jednotlivých <variant> (každá velikost/verze
 * má svůj vlastní EAN a kód, cena je ale společná pro celý produkt).
 */
function cfloat2_parse_sportimport_file(string $path): array
{
    $byEan = [];
    $byCode = [];
    $reader = new XMLReader();
    if (!$reader->open($path, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        return ['by_ean' => $byEan, 'by_code' => $byCode];
    }
    $doc = new DOMDocument('1.0', 'UTF-8');

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || strtolower($reader->localName) !== 'item') continue;
        $node = $reader->expand($doc);
        if (!$node instanceof DOMElement) continue;

        // první <price> pod <dealerPrices> = CZK velkoobchodní cena bez DPH
        $dealerCzk = null;
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->localName) === 'dealerprices') {
                $dealerCzk = cfloat2_parse_number(cfloat2_child_text($child, ['price']) ?? '');
                break;
            }
        }
        if ($dealerCzk === null || $dealerCzk <= 0) continue;
        $price = $dealerCzk * 1.21;

        // kód produktu na úrovni item (fallback, kdyby nebyly varianty)
        $itemCode = cfloat2_norm_code(cfloat2_child_text($node, ['code']) ?? '');
        if ($itemCode !== null && !isset($byCode[$itemCode])) $byCode[$itemCode] = $price;

        // varianty (velikosti/verze) – EAN a kód jsou v atributech
        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE || strtolower($child->localName) !== 'variants') continue;
            foreach ($child->childNodes as $variant) {
                if ($variant->nodeType !== XML_ELEMENT_NODE || strtolower($variant->localName) !== 'variant') continue;
                if (!$variant instanceof DOMElement) continue;

                $vEan = cfloat2_norm_ean($variant->getAttribute('ean'));
                if ($vEan !== null && !isset($byEan[$vEan])) $byEan[$vEan] = $price;

                $vCode = cfloat2_norm_code($variant->getAttribute('code'));
                if ($vCode !== null && !isset($byCode[$vCode])) $byCode[$vCode] = $price;
            }
        }
    }
    $reader->close();
    return ['by_ean' => $byEan, 'by_code' => $byCode];
}

function cfloat2_parse_supplier_file(string $supplier, string $path): array
{
    switch ($supplier) {
        case 'DEVOLD':  return cfloat2_parse_devold_file($path);
        case 'VAVRYS':  return cfloat2_parse_vavrys_file($path);
        case 'SILVINI': return cfloat2_parse_silvini_file($path);
        case 'ISADORE': return cfloat2_parse_isadore_file($path);
        case 'SPORTIMPORT': return cfloat2_parse_sportimport_file($path);
        default:        return ['by_ean' => [], 'by_code' => []];
    }
}

// ---------------------------------------------------------------------------
// Sestavení aktuálního indexu (+ zálohování feedů)
// ---------------------------------------------------------------------------

/**
 * @return array{index: array<string, array{by_ean:array,by_code:array}|null>, log: array}
 */
function cfloat2_build_current_index(): array
{
    $index = [];
    $log = [];

    foreach (CFLOAT2_SUPPLIERS as $supplier) {
        $path = cfloat2_current_file($supplier);
        if ($path === null) {
            $log[] = "{$supplier}: feed se nepodařilo načíst (soubor chybí / URL nedostupná) – použijí se jen zálohy.";
            $index[$supplier] = null;
            continue;
        }

        $arch = cfloat2_archive_snapshot_file($supplier, $path);
        if ($arch['created']) {
            $log[] = "{$supplier}: obsah feedu se změnil, uložena nová záloha (" . basename((string)$arch['path']) . ").";
        } else {
            $log[] = "{$supplier}: feed beze změny, záloha nebyla potřeba.";
        }

        $parsed = cfloat2_parse_supplier_file($supplier, $path);
        $index[$supplier] = $parsed;
        $log[] = "{$supplier}: v aktuálním feedu nalezeno " . count($parsed['by_ean']) . " EAN s cenou.";
    }

    return ['index' => $index, 'log' => $log];
}

// ---------------------------------------------------------------------------
// Vyhledání ceny pro konkrétní EAN / kód – nejdřív aktuální feedy, pak archiv
// ---------------------------------------------------------------------------

/**
 * @param array<string, array{by_ean:array,by_code:array}|null> $currentIndex
 * @param array<string, array> $archiveParseCache reference – cache parsovaných záloh (napříč voláními v rámci běhu)
 * @return array{price:float, supplier:string, source:string}|null
 */
function cfloat2_lookup_price(?string $ean, ?string $code, array $currentIndex, array &$archiveParseCache): ?array
{
    $ean = $ean !== null ? cfloat2_norm_ean($ean) : null;
    $code = $code !== null ? cfloat2_norm_code($code) : null;

    foreach (CFLOAT2_SUPPLIERS as $supplier) {
        $data = $currentIndex[$supplier] ?? null;
        if ($data === null) continue;
        if ($ean !== null && isset($data['by_ean'][$ean])) {
            return ['price' => $data['by_ean'][$ean], 'supplier' => $supplier, 'source' => 'aktuální feed'];
        }
    }
    foreach (CFLOAT2_SUPPLIERS as $supplier) {
        $data = $currentIndex[$supplier] ?? null;
        if ($data === null) continue;
        if ($code !== null && isset($data['by_code'][$code])) {
            return ['price' => $data['by_code'][$code], 'supplier' => $supplier, 'source' => 'aktuální feed'];
        }
    }

    // Záchranný fallback: jen u VAVRYS, jen část kódu před první pomlčkou
    // (např. "1913615" z "1913615-629000"). Použije se AŽ když selže přesný
    // EAN i přesný kód – cena se bere z libovolné jiné barvy stejného modelu,
    // takže je to jen přibližná shoda.
    if ($code !== null && strpos($code, '-') !== false) {
        $prefix = substr($code, 0, strpos($code, '-'));
        $vavrysData = $currentIndex['VAVRYS'] ?? null;
        if ($vavrysData !== null && $prefix !== '' && isset($vavrysData['by_code_prefix'][$prefix])) {
            return [
                'price' => $vavrysData['by_code_prefix'][$prefix],
                'supplier' => 'VAVRYS',
                'source' => 'aktuální feed (jen podle předčíslí kódu, přibližná shoda)',
            ];
        }
    }

    foreach (CFLOAT2_SUPPLIERS as $supplier) {
        foreach (cfloat2_list_archive_desc($supplier) as $path) {
            if (!isset($archiveParseCache[$path])) {
                $archiveParseCache[$path] = cfloat2_parse_supplier_file($supplier, $path);
            }
            $data = $archiveParseCache[$path];
            if ($ean !== null && isset($data['by_ean'][$ean])) {
                return ['price' => $data['by_ean'][$ean], 'supplier' => $supplier, 'source' => 'záloha ' . cfloat2_archive_label($path)];
            }
            if ($code !== null && isset($data['by_code'][$code])) {
                return ['price' => $data['by_code'][$code], 'supplier' => $supplier, 'source' => 'záloha ' . cfloat2_archive_label($path)];
            }
        }
    }

    return null;
}

function cfloat2_archive_label(string $path): string
{
    $base = basename($path, '.xml');
    $parts = explode('_', $base);
    if (count($parts) >= 2 && strlen($parts[0]) === 8 && strlen($parts[1]) === 6) {
        $d = $parts[0];
        $t = $parts[1];
        return substr($d, 6, 2) . '.' . substr($d, 4, 2) . '.' . substr($d, 0, 4)
            . ' ' . substr($t, 0, 2) . ':' . substr($t, 2, 2);
    }
    return $base;
}
