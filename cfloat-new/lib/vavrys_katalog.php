<?php
declare(strict_types=1);

/**
 * ===========================================================================
 *  Sdílená knihovna pro práci s katalogem Vavrys a odesílání objednávek.
 *  Používá ji vavrys-objednavka-nahled.php (ruční náhled/odeslání 1 položky)
 *  i objednavky.php (hromadné odeslání označených položek z modulu Objednávky).
 * ===========================================================================
 */

const VAVRYS_IMPORT_WSDL = 'https://b2b.vavrys.cz/ws/ImportService.asmx?WSDL';

/**
 * ==== Údaje odběratele u Vavrys ====
 * Ověřené kódy ZpusobyPlatby/ZpusobyDopravy (potvrzeno přes SOAP test 21.8.2026):
 *   PP5 = "Bankovním převodem do 5-ti dnů", PPL = "službou PPL"
 */
const VAVRYS_ODBERATEL_ID   = '09730761_interface'; // stejné jako přihlašovací login
const VAVRYS_ZPUSOB_PLATBY  = 'PP5';     // ověřeno
const VAVRYS_ZPUSOB_DOPRAVY = 'PPL';     // ověřeno
const VAVRYS_NAZEV = 'AleaSport CZ, s.r.o.';
// Fakturační adresa
const VAVRYS_FAKT_ULICE = 'Modřínová 453';
const VAVRYS_FAKT_MESTO = 'Třebíč';
const VAVRYS_FAKT_PSC   = '67401';
// Dodací adresa (jiná než fakturační!)
const VAVRYS_DOD_ULICE = 'Hrotovická 1202/27';
const VAVRYS_DOD_MESTO = 'Třebíč';
const VAVRYS_DOD_PSC   = '67401';
const VAVRYS_ADR_ZEME = 'CZ';
const VAVRYS_ICO     = '09730761';
const VAVRYS_DIC     = 'CZ09730761';
const VAVRYS_TELEFON = '608883892';
const VAVRYS_EMAIL   = 'obchod@c-store.cz';

// Text poznámky posílaný do objednávky u Vavrys. Nikde se v datech odesílaných
// třetí straně nesmí objevit slovo "cfloat" - jde jen o interní název administrace.
const VAVRYS_POZNAMKA_TEXT = 'C-Store, oficiální obj.';

// Značky, které se u Vavrys smí objednávat. Cokoliv jiného se při hromadném
// odeslání odmítne s jasným upozorněním, nikdy se tiše nepřeskočí.
const VAVRYS_POVOLENE_ZNACKY = ['craft', 'haglöfs', 'haglofs', 'inov-8', 'inov8', 'silva', 'primus'];

/** Rozpozná, zda daný název produktu patří mezi dodavatele Vavrys. */
function vpo_is_allowed_brand(string $productName): bool
{
    $n = mb_strtolower($productName, 'UTF-8');
    foreach (VAVRYS_POVOLENE_ZNACKY as $kw) {
        if (mb_stripos($n, $kw) !== false) return true;
    }
    return false;
}

/** Překlad návratového kódu NovaObjednavka na srozumitelný text (dle oficiální dokumentace). */
function vpo_import_result_code_text(int $code): string
{
    $map = [
        0 => 'OK – objednávka byla v pořádku založena.',
        1 => 'Nedefinovaná objednávka.',
        2 => 'Nedefinovaný způsob platby.',
        3 => 'Nedefinovaný způsob dopravy.',
        4 => 'Nekompletní fakturační adresa.',
        5 => 'Neplatný sklad.',
        6 => 'Neplatná skladová karta.',
        7 => 'Neplatný sortiment.',
        8 => 'Duplicitní sortiment.',
        9 => 'Nulová cena.',
        10 => 'Objednávka neobsahuje položky.',
        11 => 'Neexistuje disponibilní zásoba.',
        12 => 'Chybějící označení objednávky u odběratele.',
        13 => 'Chybějící odběratel.',
        14 => 'Chybějící identifikátor odběratele.',
        15 => 'Nekompletní dodací adresa.',
        16 => 'Nedefinovaná sleva.',
        17 => 'Konflikt slev v rámci skladové položky.',
        18 => 'Nulové množství.',
        19 => 'Neplatný katalog.',
        20 => 'Na katalog jsou dočasně zakázány objednávky.',
        21 => 'Nedefinovaná země.',
        98 => 'Chybné přihlašovací údaje.',
        99 => 'Jiná chyba.',
    ];
    return $map[$code] ?? "Neznámý kód odpovědi ({$code}).";
}

function vpo_find_vavrys_file(): ?string
{
    $candidates = [
        __DIR__ . '/../../Dodavatele/Vavrys/vavrys_katalog.xml',
        __DIR__ . '/../../Dodavatele/Vavrys/vavrys_katalog_soap.xml',
        __DIR__ . '/../Dodavatele/Vavrys/vavrys_katalog.xml',
        __DIR__ . '/../Dodavatele/Vavrys/vavrys_katalog_soap.xml',
    ];
    foreach ($candidates as $p) {
        if (is_file($p) && filesize($p) > 0) return $p;
    }
    return null;
}

function vpo_norm_ean(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    return $digits;
}

/** Streamované hledání v (velkém) katalogu Vavrys podle EAN. Vrací nalezená data, nebo null. */
function vpo_find_by_ean(string $file, string $ean): ?array
{
    $reader = new XMLReader();
    if (!$reader->open($file, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return null;

    $doc = new DOMDocument('1.0', 'UTF-8');
    $found = null;

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) continue;
        if (strtolower($reader->localName) !== 'katalogpolozka') continue;

        $node = $reader->expand($doc);
        if (!$node instanceof DOMElement) continue;

        $xpath = new DOMXPath($doc);
        $sortItems = $xpath->query(".//*[local-name()='SortimentPolozka']", $node);
        foreach ($sortItems as $sortItem) {
            $carKodNode = $xpath->query(".//*[local-name()='CarKod']", $sortItem)->item(0);
            $carKod = $carKodNode ? vpo_norm_ean(trim((string)$carKodNode->textContent)) : '';
            if ($carKod !== $ean) continue;

            $get = function (string $tag) use ($xpath, $sortItem) {
                $n = $xpath->query(".//*[local-name()='{$tag}']", $sortItem)->item(0);
                return $n ? trim((string)$n->textContent) : '';
            };
            $getFromParent = function (string $tag) use ($xpath, $node) {
                $n = $xpath->query(".//*[local-name()='{$tag}']", $node)->item(0);
                return $n ? trim((string)$n->textContent) : '';
            };

            $found = [
                'ean' => $carKod,
                'idX' => $get('IdX'),
                'idY' => $get('IdY'),
                'cena' => $get('Cena'),
                'mnozstviSklad' => $get('Mnozstvi'),
                'pozY2' => $get('PozY2'),
                'ozn_barva' => $get('PozY1'),
                'ozn_velikost' => $get('PozX1'),
                'katalogId' => $getFromParent('KatalogId'),
                'strCislo' => $getFromParent('StrCislo'),
                'karCislo' => $getFromParent('KarCislo'),
                'karCisloId' => $getFromParent('KarCisloId'),
                'nazev' => $getFromParent('Nazev'),
            ];
            break 2;
        }
    }
    $reader->close();
    return $found;
}

/** Ořízne případnou závorku na konci kódu, např. "1914792-376000(3760)" -> "1914792-376000". */
function vpo_strip_paren_suffix(string $code): string
{
    return preg_replace('/\s*\([^)]*\)\s*$/', '', $code) ?? $code;
}

function vpo_norm_code(string $raw): string
{
    $raw = trim($raw);
    $raw = str_replace(["\xC2\xA0"], ' ', $raw);
    $raw = preg_replace('/\s+/u', '', $raw) ?? '';
    return function_exists('mb_strtoupper') ? mb_strtoupper($raw, 'UTF-8') : strtoupper($raw);
}

/** Normalizace textu velikosti - odstraní slovo "velikost" a přebytečné mezery/velká písmena. */
function vpo_norm_velikost(string $raw): string
{
    $raw = mb_strtolower(trim($raw), 'UTF-8');
    $raw = trim(str_ireplace('velikost', '', $raw));
    return $raw;
}

/**
 * Zobrazitelný text velikosti bez slova "Velikost" na začátku (pro UI, ne pro párování).
 * "Velikost: M" / "Velikost M" / "velikost   m" -> "M"
 */
function vpo_display_velikost(string $raw): string
{
    $t = trim($raw);
    $t = preg_replace('/^velikost\s*:?\s*/iu', '', $t) ?? $t;
    return trim($t);
}

/** @return array<int, array> všechny nalezené velikosti pro daný kód (barvu) */
function vpo_find_by_code(string $file, string $code): array
{
    $codeNorm = vpo_norm_code($code);

    $reader = new XMLReader();
    if (!$reader->open($file, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return [];

    $doc = new DOMDocument('1.0', 'UTF-8');
    $matches = [];

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) continue;
        if (strtolower($reader->localName) !== 'katalogpolozka') continue;

        $node = $reader->expand($doc);
        if (!$node instanceof DOMElement) continue;

        $xpath = new DOMXPath($doc);
        $sortItems = $xpath->query(".//*[local-name()='SortimentPolozka']", $node);
        foreach ($sortItems as $sortItem) {
            $get = function (string $tag) use ($xpath, $sortItem) {
                $n = $xpath->query(".//*[local-name()='{$tag}']", $sortItem)->item(0);
                return $n ? trim((string)$n->textContent) : '';
            };
            $pozY2 = $get('PozY2');
            if ($pozY2 === '') continue;
            $thisCode = vpo_norm_code(vpo_strip_paren_suffix($pozY2));
            if ($thisCode !== $codeNorm) continue;

            $getFromParent = function (string $tag) use ($xpath, $node) {
                $n = $xpath->query(".//*[local-name()='{$tag}']", $node)->item(0);
                return $n ? trim((string)$n->textContent) : '';
            };

            $matches[] = [
                'ean' => vpo_norm_ean($get('CarKod')),
                'idX' => $get('IdX'), 'idY' => $get('IdY'),
                'cena' => $get('Cena'), 'mnozstviSklad' => $get('Mnozstvi'),
                'pozY2' => $pozY2, 'ozn_barva' => $get('PozY1'), 'ozn_velikost' => $get('PozX1'),
                'katalogId' => $getFromParent('KatalogId'), 'strCislo' => $getFromParent('StrCislo'),
                'karCislo' => $getFromParent('KarCislo'), 'karCisloId' => $getFromParent('KarCisloId'),
                'nazev' => $getFromParent('Nazev'),
            ];
        }
        if (!empty($matches)) break; // kód je unikátní na barvu, dál v souboru už nic dalšího nebude
    }
    $reader->close();
    return $matches;
}

/**
 * Najde položku v katalogu Vavrys pro jednu položku objednávky (nejdřív podle EAN,
 * pak podle kódu produktu + velikosti). Vrací nalezenou katalogovou položku nebo null.
 */
function vpo_lookup_item(string $file, ?string $ean, ?string $productNumber, ?string $velikost): ?array
{
    $eanNorm = $ean !== null ? vpo_norm_ean($ean) : '';
    if ($eanNorm !== '') {
        $r = vpo_find_by_ean($file, $eanNorm);
        if ($r !== null) return $r;
    }
    $kod = trim((string)$productNumber);
    if ($kod === '') return null;

    $candidates = vpo_find_by_code($file, $kod);
    if (empty($candidates)) return null;
    if (count($candidates) === 1) return $candidates[0];

    $velNorm = vpo_norm_velikost((string)$velikost);
    foreach ($candidates as $c) {
        if ($velNorm !== '' && vpo_norm_velikost($c['ozn_velikost']) === $velNorm) return $c;
    }
    return null; // víc velikostí, nešlo jednoznačně určit - vyžaduje ruční rozhodnutí
}

/**
 * Sestaví pole pro SOAP volání NovaObjednavka s libovolným počtem položek.
 * $polozky = list of ['katalogId','strCislo','karCislo','karCisloId','idX','idY','mnozstvi','cena']
 */
function vpo_build_objednavka_data(string $cislo, string $datum, array $polozky): array
{
    $items = [];
    foreach ($polozky as $p) {
        $items[] = [
            'Id' => [
                'KatalogId' => $p['katalogId'],
                'StrCislo' => $p['strCislo'],
                'KarCislo' => $p['karCislo'],
                'KarCisloId' => $p['karCisloId'],
            ],
            'IdX' => $p['idX'],
            'IdY' => $p['idY'],
            'Mnozstvi' => $p['mnozstvi'],
            'Cena' => $p['cena'],
        ];
    }
    return [
        'Cislo' => $cislo,
        'Datum' => $datum,
        'ZpusobPlatby' => VAVRYS_ZPUSOB_PLATBY,
        'ZpusobDopravy' => VAVRYS_ZPUSOB_DOPRAVY,
        'Poznamka' => VAVRYS_POZNAMKA_TEXT,
        'Odberatel' => [
            'Id' => VAVRYS_ODBERATEL_ID,
            'AdresaDodaci' => [
                'Nazev1' => VAVRYS_NAZEV,
                'Ulice' => VAVRYS_DOD_ULICE,
                'Mesto' => VAVRYS_DOD_MESTO,
                'Psc' => VAVRYS_DOD_PSC,
                'Zeme' => VAVRYS_ADR_ZEME,
            ],
            'AdresaFakturacni' => [
                'Nazev1' => VAVRYS_NAZEV,
                'Ulice' => VAVRYS_FAKT_ULICE,
                'Mesto' => VAVRYS_FAKT_MESTO,
                'Psc' => VAVRYS_FAKT_PSC,
                'Zeme' => VAVRYS_ADR_ZEME,
            ],
            'Ico' => VAVRYS_ICO,
            'Dic' => VAVRYS_DIC,
            'Telefon' => VAVRYS_TELEFON,
            'Email' => VAVRYS_EMAIL,
        ],
        'ObjednavkaPolozky' => [
            'ObjednavkaPolozka' => $items,
        ],
    ];
}

/**
 * Skutečně odešle objednávku (SOAP NovaObjednavka). Vrací pole se success/code/message,
 * případně request/response XML pro diagnostiku.
 */
function vpo_send_objednavka(string $login, string $password, array $objednavkaData): array
{
    $importClient = null;
    try {
        $importClient = new SoapClient(VAVRYS_IMPORT_WSDL, [
            'trace' => 1,
            'exceptions' => true,
            'connection_timeout' => 20,
        ]);
        $soapResp = $importClient->NovaObjednavka([
            'login' => $login,
            'password' => $password,
            'Objednavka' => $objednavkaData,
        ]);
        $resultCode = (int)($soapResp->NovaObjednavkaResult ?? -1);
        return [
            'success' => $resultCode === 0,
            'code' => $resultCode,
            'message' => vpo_import_result_code_text($resultCode),
            'request' => $importClient->__getLastRequest() ?? '',
            'response' => $importClient->__getLastResponse() ?? '',
        ];
    } catch (SoapFault $e) {
        return [
            'success' => false,
            'error' => 'SOAP CHYBA: ' . $e->getMessage(),
            'request' => $importClient ? ($importClient->__getLastRequest() ?? '') : '',
            'response' => $importClient ? ($importClient->__getLastResponse() ?? '') : '',
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'CHYBA: ' . $e->getMessage()];
    }
}
