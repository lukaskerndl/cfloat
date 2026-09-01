<?php

declare(strict_types=1);

function svf_source_paths(string $baseDir): array
{
    return [
        'main'    => $baseDir . '/Aktualizace_CSTORE/VAVRYS KOMPLET/hlavniproduktyvavryskomplet.csv',
        'variant' => $baseDir . '/Aktualizace_CSTORE/VAVRYS KOMPLET/variantyiproduktyvavryskomplet_var.csv',
    ];
}

function svf_read_csv_assoc(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return [];
    }

    $rows = [];
    $headers = null;

    while (($row = fgetcsv($fh, 0, ';')) !== false) {
        $row = array_map('svf_to_utf8', $row);
        if ($headers === null) {
            $headers = array_map(static fn($v) => trim((string)$v), $row);
            continue;
        }

        if (empty(array_filter($row, static fn($v) => trim((string)$v) !== ''))) {
            continue;
        }

        $assoc = [];
        foreach ($headers as $i => $header) {
            $assoc[$header] = isset($row[$i]) ? trim((string)$row[$i]) : '';
        }
        $rows[] = $assoc;
    }

    fclose($fh);
    return $rows;
}

function svf_to_utf8($value): string
{
    $value = (string)$value;
    if ($value === '') {
        return '';
    }

    // remove UTF-8 BOM in the first header cell etc.
    $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;

    if (preg_match('//u', $value)) {
        return $value;
    }

    $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $value);
    if ($converted !== false && $converted !== '') {
        return $converted;
    }

    $converted = @iconv('CP1250', 'UTF-8//IGNORE', $value);
    if ($converted !== false && $converted !== '') {
        return $converted;
    }

    return $value;
}


function svf_strlen(string $value): int
{
    return function_exists('mb_strlen') ? (int)mb_strlen($value, 'UTF-8') : strlen($value);
}

function svf_substr(string $value, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return $length === null ? (string)mb_substr($value, $start, null, 'UTF-8') : (string)mb_substr($value, $start, $length, 'UTF-8');
    }
    return $length === null ? substr($value, $start) : substr($value, $start, $length);
}

function svf_strtolower(string $value): string
{
    return function_exists('mb_strtolower') ? (string)mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function svf_strtoupper(string $value): string
{
    return function_exists('mb_strtoupper') ? (string)mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function svf_parse_decimal($value): ?float
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $raw = str_replace(["\xc2\xa0", ' '], '', $raw);
    $raw = str_replace(',', '.', $raw);
    $raw = preg_replace('/[^0-9.\-]/', '', $raw) ?? '';
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }

    return (float)$raw;
}

function svf_format_decimal(?float $value): ?string
{
    if ($value === null) {
        return null;
    }
    return number_format($value, 2, '.', '');
}

function svf_bool($value): bool
{
    return in_array(trim((string)$value), ['1', 'true', 'TRUE', 'yes', 'YES'], true);
}

function svf_parse_param_string(string $raw): array
{
    $out = [];
    $raw = trim($raw);
    if ($raw === '') {
        return $out;
    }

    foreach (explode('|-|', $raw) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }

        $parts = explode('#-#', $chunk, 2);
        $name  = trim($parts[0] ?? '');
        $value = trim($parts[1] ?? '');
        if ($name === '' || $value === '') {
            continue;
        }
        $out[$name] = $value;
    }

    return $out;
}

function svf_marketing_param_names(): array
{
    return [
        'Výměna' => true,
        'Dodání' => true,
        'Věrnostní sleva' => true,
        'Nákup bez rizika' => true,
        'Doprava zdarma' => true,
        'Specialisté' => true,
        '15 let na trhu' => true,
    ];
}

function svf_clean_name(string $value): string
{
    $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function svf_clean_description_html(string $html): string
{
    $html = trim((string)$html);
    if ($html === '') {
        return '';
    }

    $html = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html) ?? $html;
    $html = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $html) ?? $html;
    $html = preg_replace('~<img\b[^>]*>~i', '', $html) ?? $html;
    $html = preg_replace('~<a\b[^>]*>(.*?)</a>~is', '$1', $html) ?? $html;
    $html = preg_replace('~<p>\s*(?:&nbsp;|\x{00a0})*\s*</p>~iu', '', $html) ?? $html;
    $html = preg_replace('~\s+~u', ' ', $html) ?? $html;
    $html = trim($html);

    return $html;
}

function svf_make_short_description(string $html, int $limit = 250): string
{
    $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    if ($plain === '') {
        return '';
    }
    if (svf_strlen($plain) <= $limit) {
        return $plain;
    }
    return rtrim(svf_substr($plain, 0, $limit - 1)) . '…';
}

function svf_slug(string $value): string
{
    $value = svf_clean_name($value);
    $value = svf_strtolower($value);
    $map = [
        'á'=>'a','ä'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','ë'=>'e','í'=>'i','ľ'=>'l','ĺ'=>'l',
        'ň'=>'n','ó'=>'o','ô'=>'o','ö'=>'o','ř'=>'r','ŕ'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u',
        'ü'=>'u','ý'=>'y','ž'=>'z'
    ];
    $value = strtr($value, $map);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
    $value = trim($value, '-');
    return $value !== '' ? $value : 'item';
}

function svf_detect_group_id(string $rawId): string
{
    $rawId = trim($rawId);
    if ($rawId === '') {
        return '';
    }
    $parts = explode('_', $rawId, 2);
    return trim($parts[0]);
}

function svf_unique_non_empty(array $values): array
{
    $out = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }
        $out[$value] = true;
    }
    return array_keys($out);
}

function svf_build_category_type(array $main): string
{
    $type = svf_clean_name((string)($main['Druhy nazev vyrobku'] ?? ''));
    $manufacturer = svf_clean_name((string)($main['Jmeno vyrobce'] ?? ''));
    if ($type === '') {
        $type = svf_clean_name((string)($main['Nazev vyrobku'] ?? ''));
    }
    if ($manufacturer !== '') {
        $type = trim(preg_replace('/\b' . preg_quote($manufacturer, '/') . '\b/iu', '', $type) ?? $type, " -\t\n\r\0\x0B");
    }
    $type = preg_replace('/\s+/u', ' ', $type) ?? $type;
    return $type !== '' ? $type : 'Ostatní';
}

function svf_build_categories(string $manufacturer, string $type): array
{
    $manufacturer = svf_clean_name($manufacturer);
    $type = svf_clean_name($type);

    $categories = [];
    if ($manufacturer !== '') {
        $categories[] = 'Sport > ' . $manufacturer;
        if ($type !== '') {
            $categories[] = 'Sport > ' . $manufacturer . ' > ' . $type;
        }
    }

    if (empty($categories)) {
        $categories[] = 'Sport > Ostatní';
    }

    return $categories;
}

function svf_build_image_urls(array $main, string $groupId): array
{
    $urls = [];
    $imageFields = [
        'Obrazek (puvodni velikost)',
        'Obrazek 1',
        'Obrazek 2',
        'Obrazek 3',
        'Obrazek 4',
        'Obrazek 5',
        'Obrazek 6',
    ];

    foreach ($imageFields as $field) {
        $value = trim((string)($main[$field] ?? ''));
        if ($value === '') {
            continue;
        }

        if (preg_match('~^https?://~i', $value)) {
            $url = $value;
        } elseif ($groupId !== '') {
            $url = 'https://www.c-store.cz/fotky38463/fotos/38463_' . $groupId . '_' . ltrim($value, '/');
        } else {
            continue;
        }

        $url = str_replace(' ', '%20', $url);
        $urls[$url] = true;
    }

    return array_keys($urls);
}

function svf_size_sort_key(string $size): array
{
    static $map = [
        'XXS'=>1,'XS'=>2,'S'=>3,'M'=>4,'L'=>5,'XL'=>6,'XXL'=>7,'3XL'=>8,'4XL'=>9,'5XL'=>10,'6XL'=>11,
        'UNI'=>100,'ONE SIZE'=>101,
    ];
    $normalized = svf_strtoupper(trim($size));
    if (isset($map[$normalized])) {
        return [0, $map[$normalized], $normalized];
    }
    if (preg_match('/^(\d+(?:[.,]\d+)?)$/', $normalized, $m)) {
        return [1, (float)str_replace(',', '.', $m[1]), $normalized];
    }
    return [2, 9999, $normalized];
}


function svf_normalize_size(string $value): string
{
    $value = svf_clean_name($value);
    if ($value === '') {
        return '';
    }

    $upper = svf_strtoupper($value);
    $upper = preg_replace('/\s+/u', ' ', $upper) ?? $upper;

    $map = [
        'ONE SIZE'    => 'UNI',
        'ONESIZE'     => 'UNI',
        'ONE-SIZE'    => 'UNI',
        'UNI SIZE'    => 'UNI',
        'UNIVERZÁLNÍ' => 'UNI',
        'UNIVERZALNI' => 'UNI',
        'UNIVERSAL'   => 'UNI',
    ];
    if (isset($map[$upper])) {
        $upper = $map[$upper];
    }

    $invalid = [
        '' => true,
        'ITEM' => true,
        '-' => true,
        '—' => true,
        'NULL' => true,
        'NEUVEDENO' => true,
        'N/A' => true,
        '0' => true,
    ];

    return isset($invalid[$upper]) ? '' : $upper;
}

function svf_build_variant_code(string $baseCode, string $size, string $ean, int $index): string
{
    $baseCode = trim($baseCode);
    $sizeSlug = svf_slug($size);
    $candidate = $baseCode !== '' ? $baseCode . '-' . strtoupper($sizeSlug) : '';
    if ($candidate === '' && $ean !== '') {
        $candidate = 'EAN-' . preg_replace('/\D+/', '', $ean);
    }
    if ($candidate === '') {
        $candidate = 'VAR-' . $index;
    }
    return svf_substr($candidate, 0, 64);
}

function svf_variant_parameter_value(array $variant, string $name): string
{
    $params = $variant['params'] ?? [];
    return trim((string)($params[$name] ?? ''));
}

function svf_build_products(string $baseDir): array
{
    $paths = svf_source_paths($baseDir);
    $mainRows = svf_read_csv_assoc($paths['main']);
    $variantRows = svf_read_csv_assoc($paths['variant']);

    $variantGroups = [];
    foreach ($variantRows as $row) {
        $code = trim((string)($row['Kod vyrobku'] ?? ''));
        if ($code === '') {
            continue;
        }

        $params = svf_parse_param_string((string)($row['Parametry'] ?? ''));
        $variantGroups[$code][] = [
            'row_id'         => trim((string)($row['ID'] ?? '')),
            'group_id'       => svf_detect_group_id((string)($row['ID'] ?? '')),
            'code'           => $code,
            'name'           => svf_clean_name((string)($row['Nazev vyrobku'] ?? '')),
            'price_vat'      => svf_parse_decimal($row['Nase cena'] ?? null),
            'stock'          => svf_parse_decimal($row['Skladem (pocet kusu)'] ?? null),
            'in_stock'       => svf_bool($row['Je skladem (1|0)'] ?? ''),
            'ean'            => trim((string)($row['EAN kod'] ?? '')),
            'purchase_price' => svf_parse_decimal($row['Nakupni cena'] ?? null),
            'unit'           => trim((string)($row['Prodejni jednotka'] ?? '')),
            'manufacturer'   => svf_clean_name((string)($row['Vyrobce'] ?? '')),
            'free_shipping'  => svf_bool($row['Doprava zdarma'] ?? ''),
            'params'         => $params,
        ];
    }

    $products = [];
    $stats = [
        'main_rows' => count($mainRows),
        'variant_rows' => count($variantRows),
        'shopitems' => 0,
        'variant_shopitems' => 0,
        'single_shopitems' => 0,
        'skipped_hidden' => 0,
        'skipped_zero_price' => 0,
        'missing_images' => 0,
    ];

    foreach ($mainRows as $main) {
        $hidden = svf_bool($main['Tento produkt nezobrazovat v eshopu'] ?? '') || svf_bool($main['Nezobrazovat ve feedu'] ?? '');
        if ($hidden) {
            $stats['skipped_hidden']++;
            continue;
        }

        $name = svf_clean_name((string)($main['Nazev vyrobku'] ?? ''));
        $baseCode = trim((string)($main['Kod vyrobku'] ?? ''));
        if ($name === '' || $baseCode === '') {
            continue;
        }

        $variantsRaw = $variantGroups[$baseCode] ?? [];
        $manufacturer = svf_clean_name((string)($main['Jmeno vyrobce'] ?? ''));
        if ($manufacturer === '' && !empty($variantsRaw[0]['manufacturer'])) {
            $manufacturer = $variantsRaw[0]['manufacturer'];
        }

        $groupId = '';
        if (!empty($variantsRaw)) {
            $groupId = (string)($variantsRaw[0]['group_id'] ?? '');
        }

        $imageUrls = svf_build_image_urls($main, $groupId);
        if (empty($imageUrls)) {
            $stats['missing_images']++;
        }

        $mainDescription = svf_clean_description_html((string)($main['Popis vyrobku'] ?? ''));
        $mainParams = svf_parse_param_string((string)($main['Parametry'] ?? ''));
        $color = !empty($variantsRaw) ? svf_variant_parameter_value($variantsRaw[0], 'Barva') : '';
        $type = svf_build_category_type($main);
        $categories = svf_build_categories($manufacturer, $type);

        $infoParams = [];
        $ignoreParams = svf_marketing_param_names();
        foreach ([$mainParams, !empty($variantsRaw) ? ($variantsRaw[0]['params'] ?? []) : []] as $paramSet) {
            foreach ($paramSet as $pName => $pValue) {
                $pName = svf_clean_name($pName);
                $pValue = svf_clean_name($pValue);
                if ($pName === '' || $pValue === '') {
                    continue;
                }
                if (isset($ignoreParams[$pName])) {
                    continue;
                }
                if ($pName === 'Velikost') {
                    continue;
                }
                if ($pName === 'Výrobce' && $manufacturer !== '') {
                    continue;
                }
                $infoParams[$pName] = $pValue;
            }
        }
        if ($color !== '' && !isset($infoParams['Barva'])) {
            $infoParams['Barva'] = $color;
        }

        $validVariants = [];
        $variantSeen = [];
        foreach ($variantsRaw as $idx => $variant) {
            $priceVat = $variant['price_vat'];
            $ean = trim((string)$variant['ean']);
            if (($priceVat === null || $priceVat <= 0) && $ean === '') {
                continue;
            }

            $size = svf_normalize_size(svf_variant_parameter_value($variant, 'Velikost'));
            if ($size === '') {
                continue;
            }

            $variantColor = svf_variant_parameter_value($variant, 'Barva');
            if ($color !== '' && $variantColor !== '' && svf_strtolower($variantColor) !== svf_strtolower($color)) {
                // exact code group should normally be same color, but keep only matching variants if color differs unexpectedly
                continue;
            }

            $variantKey = $size . '|' . $ean;
            if (isset($variantSeen[$variantKey])) {
                continue;
            }
            $variantSeen[$variantKey] = true;

            $validVariants[] = [
                'code'           => svf_build_variant_code($baseCode, $size, $ean, $idx + 1),
                'ean'            => $ean,
                'price_vat'      => $priceVat,
                'stock'          => $variant['stock'],
                'size'           => $size,
                'image_ref'      => $imageUrls[0] ?? '',
                'unit'           => $variant['unit'] !== '' ? $variant['unit'] : trim((string)($main['Prodejni jednotka'] ?? 'ks')),
                'free_shipping'  => (bool)$variant['free_shipping'],
                'purchase_price' => $variant['purchase_price'],
            ];
        }

        usort($validVariants, static function(array $a, array $b): int {
            return svf_size_sort_key($a['size']) <=> svf_size_sort_key($b['size']);
        });

        $uniqueSizes = svf_unique_non_empty(array_map(static fn($v) => (string)($v['size'] ?? ''), $validVariants));
        $hasVariants = count($validVariants) > 1 && count($uniqueSizes) > 1;

        if (!$hasVariants) {
            $singlePriceVat = svf_parse_decimal($main['Nase cena'] ?? null);
            $singleEan = trim((string)($main['EAN kod'] ?? ''));
            $singleStock = svf_parse_decimal($main['Skladem (pocet kusu)'] ?? null);
            $singleUnit = trim((string)($main['Prodejni jednotka'] ?? 'ks'));
            if (!empty($validVariants)) {
                $singlePriceVat = $validVariants[0]['price_vat'] ?? $singlePriceVat;
                $singleEan = $validVariants[0]['ean'] !== '' ? $validVariants[0]['ean'] : $singleEan;
                $singleStock = $validVariants[0]['stock'] ?? $singleStock;
                $singleUnit = $validVariants[0]['unit'] ?? $singleUnit;
            }

            if ($singlePriceVat === null || $singlePriceVat <= 0) {
                $stats['skipped_zero_price']++;
                continue;
            }

            $products[] = [
                'id' => $groupId !== '' ? $groupId : $baseCode,
                'name' => $name,
                'appendix' => $color,
                'short_description' => svf_make_short_description($mainDescription),
                'description' => $mainDescription,
                'manufacturer' => $manufacturer,
                'supplier' => 'Vavrys',
                'item_type' => 'product',
                'categories' => $categories,
                'default_category' => end($categories),
                'images' => $imageUrls,
                'info_params' => $infoParams,
                'free_shipping' => svf_bool($main['Doprava zdarma'] ?? ''),
                'flags' => [
                    'NEW' => svf_bool($main['Novinka (1|0)'] ?? ''),
                    'TIP' => svf_bool($main['Nejprodavanejsi (1|0)'] ?? ''),
                    'ACTION' => svf_bool($main['V akci (1|0)'] ?? ''),
                ],
                'mode' => 'single',
                'single' => [
                    'code' => svf_substr($baseCode, 0, 64),
                    'ean' => $singleEan,
                    'price_vat' => $singlePriceVat,
                    'stock' => $singleStock,
                    'unit' => $singleUnit !== '' ? $singleUnit : 'ks',
                    'image_ref' => $imageUrls[0] ?? '',
                ],
            ];
            $stats['single_shopitems']++;
            $stats['shopitems']++;
            continue;
        }

        $products[] = [
            'id' => $groupId !== '' ? $groupId : $baseCode,
            'name' => $name,
            'appendix' => $color,
            'short_description' => svf_make_short_description($mainDescription),
            'description' => $mainDescription,
            'manufacturer' => $manufacturer,
            'supplier' => 'Vavrys',
            'item_type' => 'product',
            'categories' => $categories,
            'default_category' => end($categories),
            'images' => $imageUrls,
            'info_params' => $infoParams,
            'free_shipping' => svf_bool($main['Doprava zdarma'] ?? ''),
            'flags' => [
                'NEW' => svf_bool($main['Novinka (1|0)'] ?? ''),
                'TIP' => svf_bool($main['Nejprodavanejsi (1|0)'] ?? ''),
                'ACTION' => svf_bool($main['V akci (1|0)'] ?? ''),
            ],
            'mode' => 'variants',
            'variants' => $validVariants,
            'unit' => trim((string)($main['Prodejni jednotka'] ?? 'ks')) ?: 'ks',
        ];
        $stats['variant_shopitems']++;
        $stats['shopitems']++;
    }

    return [
        'paths' => $paths,
        'products' => $products,
        'stats' => $stats,
    ];
}

function svf_xml_write_text(XMLWriter $xml, string $name, ?string $value): void
{
    if ($value === null) {
        return;
    }
    $value = trim($value);
    if ($value === '') {
        return;
    }
    $xml->writeElement($name, $value);
}

function svf_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function svf_xml_line(string $name, ?string $value, int $level = 0): string
{
    if ($value === null) {
        return '';
    }
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    return str_repeat("    ", $level) . '<' . $name . '>' . svf_xml_escape($value) . '</' . $name . ">
";
}

function svf_render_xml(array $products): string
{
    $out = [];
    $out[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $out[] = '<SHOP>';

    foreach ($products as $product) {
        $attrs = ' id="' . svf_xml_escape((string)$product['id']) . '" import-code="VAVRYS_SHOPTET"';
        $out[] = '    <SHOPITEM' . $attrs . '>';
        $out[] = svf_xml_line('NAME', $product['name'] ?? '', 2);
        $out[] = svf_xml_line('APPENDIX', $product['appendix'] ?? '', 2);
        $out[] = svf_xml_line('SHORT_DESCRIPTION', $product['short_description'] ?? '', 2);
        $out[] = svf_xml_line('DESCRIPTION', $product['description'] ?? '', 2);
        $out[] = svf_xml_line('MANUFACTURER', $product['manufacturer'] ?? '', 2);
        $out[] = svf_xml_line('SUPPLIER', $product['supplier'] ?? '', 2);
        $out[] = svf_xml_line('ADULT', 'false', 2);
        $out[] = svf_xml_line('ITEM_TYPE', $product['item_type'] ?? 'product', 2);

        if (!empty($product['categories'])) {
            $out[] = '        <CATEGORIES>';
            foreach ($product['categories'] as $category) {
                $out[] = svf_xml_line('CATEGORY', $category, 3);
            }
            $out[] = svf_xml_line('DEFAULT_CATEGORY', $product['default_category'] ?? '', 3);
            $out[] = '        </CATEGORIES>';
        }

        if (!empty($product['images'])) {
            $out[] = '        <IMAGES>';
            foreach ($product['images'] as $image) {
                $out[] = svf_xml_line('IMAGE', $image, 3);
            }
            $out[] = '        </IMAGES>';
        }

        if (!empty($product['info_params'])) {
            $out[] = '        <INFORMATION_PARAMETERS>';
            foreach ($product['info_params'] as $paramName => $paramValue) {
                $out[] = '            <INFORMATION_PARAMETER>';
                $out[] = svf_xml_line('NAME', (string)$paramName, 4);
                $out[] = svf_xml_line('VALUE', (string)$paramValue, 4);
                $out[] = '            </INFORMATION_PARAMETER>';
            }
            $out[] = '        </INFORMATION_PARAMETERS>';
        }
        if (!empty($product['free_shipping'])) {
            $out[] = svf_xml_line('FREE_SHIPPING', 'true', 2);
        }

        if (($product['mode'] ?? '') === 'variants') {
            $out[] = svf_xml_line('UNIT', (string)($product['unit'] ?? 'ks'), 2);
            $out[] = '        <VARIANTS>';
            foreach ((array)($product['variants'] ?? []) as $variant) {
                $out[] = '            <VARIANT>';
                $out[] = svf_xml_line('CODE', $variant['code'] ?? '', 4);
                $out[] = svf_xml_line('EAN', $variant['ean'] ?? '', 4);
                $out[] = svf_xml_line('VAT', '21.00', 4);
                $out[] = svf_xml_line('PRICE_VAT', svf_format_decimal($variant['price_vat'] ?? null), 4);
                if (($variant['purchase_price'] ?? null) !== null && (float)$variant['purchase_price'] > 0) {
                    $out[] = svf_xml_line('PURCHASE_PRICE', svf_format_decimal((float)$variant['purchase_price']), 4);
                }
                $out[] = '                <STOCK>';
                if (($variant['stock'] ?? null) !== null) {
                    $out[] = svf_xml_line('AMOUNT', number_format((float)$variant['stock'], 3, '.', ''), 5);
                }
                $out[] = '                </STOCK>';
                $out[] = svf_xml_line('AVAILABILITY_OUT_OF_STOCK', 'Na dotaz', 4);
                $out[] = svf_xml_line('AVAILABILITY_IN_STOCK', 'Skladem', 4);
                $out[] = svf_xml_line('IMAGE_REF', $variant['image_ref'] ?? '', 4);
                $out[] = '                <PARAMETERS>';
                $out[] = '                    <PARAMETER>';
                $out[] = svf_xml_line('NAME', 'Velikost', 6);
                $out[] = svf_xml_line('VALUE', $variant['size'] ?? 'UNI', 6);
                $out[] = '                    </PARAMETER>';
                $out[] = '                </PARAMETERS>';
                if (!empty($variant['free_shipping'])) {
                    $out[] = svf_xml_line('FREE_SHIPPING', 'true', 4);
                }
                $out[] = svf_xml_line('UNIT', $variant['unit'] ?? 'ks', 4);
                $out[] = '            </VARIANT>';
            }
            $out[] = '        </VARIANTS>';
        } else {
            $single = (array)($product['single'] ?? []);
            $out[] = svf_xml_line('CODE', $single['code'] ?? '', 2);
            $out[] = svf_xml_line('EAN', $single['ean'] ?? '', 2);
            $out[] = svf_xml_line('VAT', '21.00', 2);
            $out[] = svf_xml_line('PRICE_VAT', svf_format_decimal($single['price_vat'] ?? null), 2);
            $out[] = '        <STOCK>';
            if (($single['stock'] ?? null) !== null) {
                $out[] = svf_xml_line('AMOUNT', number_format((float)$single['stock'], 3, '.', ''), 3);
            }
            $out[] = '        </STOCK>';
            $out[] = svf_xml_line('AVAILABILITY_OUT_OF_STOCK', 'Na dotaz', 2);
            $out[] = svf_xml_line('AVAILABILITY_IN_STOCK', 'Skladem', 2);
            $out[] = svf_xml_line('IMAGE_REF', $single['image_ref'] ?? '', 2);
        }

        $out[] = '    </SHOPITEM>';
    }

    $out[] = '</SHOP>';
    return implode('', $out);
}

function svf_validate_relaxng(string $xmlString, string $baseDir): array
{
    return ['available' => false, 'ok' => false, 'errors' => ['Validace v PHP není v tomto prostředí dostupná.']];
}
