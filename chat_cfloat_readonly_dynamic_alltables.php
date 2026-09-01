<?php
// chat.php – cFloat Chat (STRICT READ-ONLY, DB-only, Dynamic summaries across DB tables)
//
// ✅ Pouze DB cFloat (MySQL/MariaDB přes PDO).
// ✅ ŽÁDNÉ zápisy: jen SELECT/CTE (hlídá ro_select + doporučeno DB user pouze SELECT).
// ✅ Umí sumarizovat: objednávky, zákazníky, obraty (dnes/včera/měsíc), trend, top zákazníci, top produkty,
//    měsíční náklady (monthly_costs) a navíc dynamicky vytahuje přehled pro další tabulky v DB, pokud existují.
//
// Pozn.: „Odpoví na cokoliv“ = v rámci toho, co je fakticky v DB. Pokud DB tabulka/atribut neexistuje,
// asistent musí říct, že to v DB nemá.

// Sdílená bezpečná session (stejné nastavení cookie jako zbytek administrace) + $loggedIn.
require_once __DIR__ . '/_auth_guard.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Prague');

// pouze přihlášený uživatel
if (empty($loggedIn)) {
    echo json_encode(['reply' => 'K chatu máš přístup jen po přihlášení do aplikace.']);
    exit;
}

require __DIR__ . '/config.php';

if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) {
    echo json_encode(['reply' => 'OpenAI API klíč není nastavený v config.php (konstanta OPENAI_API_KEY).']);
    exit;
}

// input
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$userMessage = '';
if (is_array($data) && isset($data['message'])) $userMessage = trim((string)$data['message']);
if ($userMessage === '') { echo json_encode(['reply' => 'Zpráva je prázdná. Napiš dotaz.']); exit; }

// PHP < 8 kompatibilita
function starts_with(string $haystack, string $needle): bool {
    return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
}
function lower_str(string $s): string {
    if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
    return strtolower($s);
}
function fmt_num($n): string {
    if (!is_numeric($n)) return (string)$n;
    $f = (float)$n;
    if (abs($f - round($f)) < 0.0000001) return (string)((int)round($f));
    return rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
}

// READ-ONLY select helper
function ro_select(PDO $pdo, string $sql, array $params = []): array {
    $s = ltrim($sql);
    $u = strtoupper($s);
    $ok = starts_with($u, 'SELECT') || starts_with($u, 'WITH');
    if (!$ok) throw new RuntimeException('READ-ONLY: Povolené jsou pouze SELECT dotazy.');
    if (strpos($s, ';') !== false) throw new RuntimeException('READ-ONLY: Zakázány jsou více příkazy v jednom dotazu.');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function ro_one(PDO $pdo, string $sql, array $params = []): ?array {
    $rows = ro_select($pdo, $sql, $params);
    return $rows[0] ?? null;
}

// -------------------------------------------------------
// 0) DB název + schema index (tabulky/kolonky) – použijeme pro dynamické „odpovědi mimo modul“
// -------------------------------------------------------
$dbName = null;
try {
    $r = ro_one($pdo, "SELECT DATABASE() AS dbname");
    $dbName = $r['dbname'] ?? null;
} catch (Throwable $e) {}

function schema_tables(PDO $pdo, string $dbName): array {
    // Vrátí seznam tabulek v DB
    return ro_select($pdo, "
        SELECT TABLE_NAME AS t
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = :db
          AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME
    ", [':db' => $dbName]);
}

function schema_columns(PDO $pdo, string $dbName, array $tables): array {
    // Vrátí sloupce pro dané tabulky
    if (!$tables) return [];
    $placeholders = [];
    $params = [':db' => $dbName];
    foreach ($tables as $i => $t) {
        $ph = ":t{$i}";
        $placeholders[] = $ph;
        $params[$ph] = $t;
    }
    $in = implode(',', $placeholders);
    $rows = ro_select($pdo, "
        SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :db
          AND TABLE_NAME IN ($in)
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ", $params);

    $out = [];
    foreach ($rows as $r) {
        $tn = $r['TABLE_NAME'];
        if (!isset($out[$tn])) $out[$tn] = [];
        $out[$tn][] = [
            'col'  => $r['COLUMN_NAME'],
            'type' => $r['DATA_TYPE'],
        ];
    }
    return $out;
}

function pick_relevant_tables(PDO $pdo, ?string $dbName, string $userMessage): array {
    if (!$dbName) return ['tables' => [], 'reason' => 'no_db'];

    $msg = lower_str($userMessage);

    // tvrdé mapování (nejčastější témata)
    $want = [];
    $map = [
        'objedn' => 'orders',
        'orders' => 'orders',
        'polož'  => 'order_items',
        'poloz'  => 'order_items',
        'items'  => 'order_items',
        'náklad' => 'monthly_costs',
        'naklad' => 'monthly_costs',
        'cost'   => 'monthly_costs',
        'zákaz'  => 'orders',
        'zakaz'  => 'orders',
        'email'  => 'orders',
        'faktur' => 'orders',
        'dobír'  => 'orders',
        'dobir'  => 'orders',
        'dopr'   => 'orders',
        'platb'  => 'orders',
        'zisk'   => 'orders', // zisk může být v jiné tabulce, ale začneme orders
    ];
    foreach ($map as $k => $t) {
        if (strpos($msg, $k) !== false) $want[$t] = true;
    }

    // pokud se ptá na „tabulku X“ / „table X“ apod., zkus vytáhnout identifikátor
    if (preg_match('/\b(?:tabulka|table)\s+([a-zA-Z0-9_]+)\b/u', $userMessage, $m)) {
        $want[$m[1]] = true;
    }

    // heuristika: pokud nic nenajdeme, budeme hledat podle slov, která vypadají jako názvy tabulek
    $tokens = [];
    if (preg_match_all('/[a-zA-Z_][a-zA-Z0-9_]{2,}/u', $userMessage, $mm)) {
        foreach ($mm[0] as $t) $tokens[] = $t;
    }

    // načti tabulky (max 200) a zkus match
    $rows = ro_select($pdo, "
        SELECT TABLE_NAME AS t
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = :db AND TABLE_TYPE='BASE TABLE'
        ORDER BY TABLE_NAME
        LIMIT 200
    ", [':db' => $dbName]);

    $allTables = array_map(fn($r)=>$r['t'], $rows);

    foreach ($tokens as $tok) {
        $tokL = lower_str($tok);
        foreach ($allTables as $t) {
            if (strpos(lower_str($t), $tokL) !== false) {
                $want[$t] = true;
            }
        }
    }

    // finální limit – max 8 tabulek, a preferuj ty známé
    $preferred = ['orders','order_items','monthly_costs'];
    $out = [];
    foreach ($preferred as $p) if (isset($want[$p])) $out[] = $p;
    foreach (array_keys($want) as $t) {
        if (!in_array($t, $out, true)) $out[] = $t;
        if (count($out) >= 8) break;
    }
    return ['tables' => $out, 'reason' => 'ok'];
}

// -------------------------------------------------------
// 1) Vytvoření DB kontextu (široké sumarizace + dynamika)
// -------------------------------------------------------
$ctx = [];
$today = date('Y-m-d');
$ctx[] = "REŽIM: STRICT READ-ONLY (jen čtení z DB cFloat).";
$ctx[] = "Dnes: {$today} (Europe/Prague).";

if ($dbName) $ctx[] = "DB: {$dbName}";
else $ctx[] = "DB: (nezjištěno)";

// 1A) Objednávky – KPI (dnes/včera/měsíc) + trend 7 dní
try {
    $rowToday = ro_one($pdo, "
        SELECT COUNT(*) AS orders_cnt,
               COALESCE(SUM(o.total_price_with_vat),0) AS revenue_sum,
               COALESCE(AVG(o.total_price_with_vat),0) AS aov
        FROM orders o
        WHERE o.created_at >= CURDATE() AND o.created_at < (CURDATE() + INTERVAL 1 DAY)
    ");
    $rowY = ro_one($pdo, "
        SELECT COUNT(*) AS orders_cnt,
               COALESCE(SUM(o.total_price_with_vat),0) AS revenue_sum,
               COALESCE(AVG(o.total_price_with_vat),0) AS aov
        FROM orders o
        WHERE o.created_at >= (CURDATE() - INTERVAL 1 DAY) AND o.created_at < CURDATE()
    ");
    $rowM = ro_one($pdo, "
        SELECT COUNT(*) AS orders_cnt,
               COALESCE(SUM(o.total_price_with_vat),0) AS revenue_sum,
               COALESCE(AVG(o.total_price_with_vat),0) AS aov
        FROM orders o
        WHERE o.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND o.created_at <  (DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH)
    ");

    $ctx[] = "";
    $ctx[] = "KPI – objednávky a obrat:";
    $ctx[] = "- Dnes: objednávky=" . fmt_num($rowToday['orders_cnt'] ?? 0) . ", obrat=" . fmt_num($rowToday['revenue_sum'] ?? 0) . ", průměr=" . fmt_num($rowToday['aov'] ?? 0);
    $ctx[] = "- Včera: objednávky=" . fmt_num($rowY['orders_cnt'] ?? 0) . ", obrat=" . fmt_num($rowY['revenue_sum'] ?? 0) . ", průměr=" . fmt_num($rowY['aov'] ?? 0);
    $ctx[] = "- Tento měsíc: objednávky=" . fmt_num($rowM['orders_cnt'] ?? 0) . ", obrat=" . fmt_num($rowM['revenue_sum'] ?? 0) . ", průměr=" . fmt_num($rowM['aov'] ?? 0);

    // Unikátní zákazníci
    try {
        $uToday = ro_one($pdo, "
            SELECT COUNT(DISTINCT COALESCE(NULLIF(TRIM(o.customer_email),''), NULLIF(TRIM(o.customer_name),''))) AS uniq_customers
            FROM orders o
            WHERE o.created_at >= CURDATE() AND o.created_at < (CURDATE() + INTERVAL 1 DAY)
        ");
        $uMonth = ro_one($pdo, "
            SELECT COUNT(DISTINCT COALESCE(NULLIF(TRIM(o.customer_email),''), NULLIF(TRIM(o.customer_name),''))) AS uniq_customers
            FROM orders o
            WHERE o.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
              AND o.created_at <  (DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH)
        ");
        $ctx[] = "- Unikátní zákazníci dnes=" . fmt_num($uToday['uniq_customers'] ?? 0);
        $ctx[] = "- Unikátní zákazníci tento měsíc=" . fmt_num($uMonth['uniq_customers'] ?? 0);
    } catch (Throwable $e) {}

    // Trend 7 dní
    try {
        $trend = ro_select($pdo, "
            SELECT DATE(o.created_at) AS d,
                   COUNT(*) AS orders_cnt,
                   COALESCE(SUM(o.total_price_with_vat),0) AS revenue_sum
            FROM orders o
            WHERE o.created_at >= (CURDATE() - INTERVAL 6 DAY)
              AND o.created_at <  (CURDATE() + INTERVAL 1 DAY)
            GROUP BY DATE(o.created_at)
            ORDER BY d ASC
        ");
        if ($trend) {
            $ctx[] = "";
            $ctx[] = "Trend posledních 7 dní (datum | objednávky | obrat):";
            foreach ($trend as $t) {
                $ctx[] = "- " . ($t['d'] ?? '') . " | " . fmt_num($t['orders_cnt'] ?? 0) . " | " . fmt_num($t['revenue_sum'] ?? 0);
            }
        }
    } catch (Throwable $e) {}

    // Dnešní zákazníci (jména)
    try {
        $custToday = ro_select($pdo, "
            SELECT COALESCE(NULLIF(TRIM(o.customer_name),''), '(nevyplněno)') AS customer_name,
                   COALESCE(NULLIF(TRIM(o.customer_email),''), '') AS customer_email,
                   COUNT(*) AS orders_cnt,
                   COALESCE(SUM(o.total_price_with_vat),0) AS revenue_sum
            FROM orders o
            WHERE o.created_at >= CURDATE() AND o.created_at < (CURDATE() + INTERVAL 1 DAY)
            GROUP BY customer_name, customer_email
            ORDER BY revenue_sum DESC
            LIMIT 25
        ");
        if ($custToday) {
            $ctx[] = "";
            $ctx[] = "Zákazníci dnes (max 25) – jméno | email | objednávky | obrat:";
            foreach ($custToday as $c) {
                $ctx[] = "- " . ($c['customer_name'] ?? '') . " | " . ($c['customer_email'] ?? '') . " | " . fmt_num($c['orders_cnt'] ?? 0) . " | " . fmt_num($c['revenue_sum'] ?? 0);
            }
        }
    } catch (Throwable $e) {}

    // Top zákazníci tento měsíc
    try {
        $topCustomers = ro_select($pdo, "
            SELECT COALESCE(NULLIF(TRIM(o.customer_name),''), '(nevyplněno)') AS customer_name,
                   COALESCE(NULLIF(TRIM(o.customer_email),''), '') AS customer_email,
                   COUNT(*) AS orders_cnt,
                   COALESCE(SUM(o.total_price_with_vat),0) AS revenue_sum
            FROM orders o
            WHERE o.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
              AND o.created_at <  (DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH)
            GROUP BY customer_name, customer_email
            ORDER BY revenue_sum DESC
            LIMIT 15
        ");
        if ($topCustomers) {
            $ctx[] = "";
            $ctx[] = "TOP zákazníci tento měsíc (max 15) – jméno | email | objednávky | obrat:";
            foreach ($topCustomers as $c) {
                $ctx[] = "- " . ($c['customer_name'] ?? '') . " | " . ($c['customer_email'] ?? '') . " | " . fmt_num($c['orders_cnt'] ?? 0) . " | " . fmt_num($c['revenue_sum'] ?? 0);
            }
        }
    } catch (Throwable $e) {}

    // Top platby/dopravy dnes
    try {
        $pay = ro_select($pdo, "
            SELECT COALESCE(NULLIF(TRIM(o.payment_name),''), '(nevyplněno)') AS payment_name,
                   COUNT(*) AS cnt
            FROM orders o
            WHERE o.created_at >= CURDATE() AND o.created_at < (CURDATE() + INTERVAL 1 DAY)
            GROUP BY payment_name
            ORDER BY cnt DESC
            LIMIT 10
        ");
        if ($pay) {
            $ctx[] = "";
            $ctx[] = "TOP platby dnes (max 10):";
            foreach ($pay as $r) $ctx[] = "- " . ($r['payment_name'] ?? '') . ": " . fmt_num($r['cnt'] ?? 0);
        }
    } catch (Throwable $e) {}
    try {
        $del = ro_select($pdo, "
            SELECT COALESCE(NULLIF(TRIM(o.delivery_name),''), '(nevyplněno)') AS delivery_name,
                   COUNT(*) AS cnt
            FROM orders o
            WHERE o.created_at >= CURDATE() AND o.created_at < (CURDATE() + INTERVAL 1 DAY)
            GROUP BY delivery_name
            ORDER BY cnt DESC
            LIMIT 10
        ");
        if ($del) {
            $ctx[] = "";
            $ctx[] = "TOP dopravy dnes (max 10):";
            foreach ($del as $r) $ctx[] = "- " . ($r['delivery_name'] ?? '') . ": " . fmt_num($r['cnt'] ?? 0);
        }
    } catch (Throwable $e) {}

} catch (Throwable $e) {
    $ctx[] = "";
    $ctx[] = "KPI (orders): nelze načíst (tabulka/struktura chybí).";
}

// 1B) Náklady (monthly_costs) – tento měsíc + seznam položek
try {
    $costSum = ro_one($pdo, "
        SELECT COALESCE(SUM(amount),0) AS costs_sum
        FROM monthly_costs
        WHERE start_year = YEAR(CURDATE())
          AND start_month = MONTH(CURDATE())
    ");
    $ctx[] = "";
    $ctx[] = "Náklady (monthly_costs) – tento měsíc: " . fmt_num($costSum['costs_sum'] ?? 0);

    $costRows = ro_select($pdo, "
        SELECT description, amount, carry, start_year, start_month
        FROM monthly_costs
        WHERE start_year = YEAR(CURDATE())
          AND start_month = MONTH(CURDATE())
        ORDER BY carry DESC, id ASC
        LIMIT 50
    ");
    if ($costRows) {
        $ctx[] = "Položky nákladů (max 50):";
        foreach ($costRows as $c) {
            $ctx[] = "- " . ($c['description'] ?? '') . " | " . fmt_num($c['amount'] ?? 0) . " | carry=" . fmt_num($c['carry'] ?? 0);
        }
    }
} catch (Throwable $e) {
    // ignore – tabulka nemusí existovat
}

// 1C) Top produkty – tento měsíc (pokud vazba existuje)
try {
    $topProducts = ro_select($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(oi.product_name),''), '(nevyplněno)') AS product_name,
            COALESCE(SUM(oi.quantity),0) AS qty_sum,
            COALESCE(SUM(oi.price_with_vat * oi.quantity),0) AS revenue_sum
        FROM order_items oi
        JOIN orders o ON o.number = oi.order_number
        WHERE o.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND o.created_at <  (DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH)
        GROUP BY product_name
        ORDER BY qty_sum DESC
        LIMIT 15
    ");
    if ($topProducts) {
        $ctx[] = "";
        $ctx[] = "TOP produkty tento měsíc (max 15) – produkt | ks | obrat z položek:";
        foreach ($topProducts as $p) {
            $ctx[] = "- " . ($p['product_name'] ?? '') . " | " . fmt_num($p['qty_sum'] ?? 0) . " | " . fmt_num($p['revenue_sum'] ?? 0);
        }
    }
} catch (Throwable $e) {
    // ignore – pokud schema nesedí, jen nebude top products
}

// 1D) Posledních 20 objednávek + detail dle čísla v dotazu
try {
    $orders = ro_select($pdo, "
        SELECT o.number, o.created_at, o.customer_name, o.customer_email,
               o.total_price_with_vat, o.currency, o.payment_name, o.delivery_name
        FROM orders o
        ORDER BY o.created_at DESC
        LIMIT 20
    ");
    $ctx[] = "";
    $ctx[] = "Posledních 20 objednávek:";
    foreach ($orders as $o) {
        $ctx[] = "- " . ($o['number'] ?? '') . " | " . ($o['created_at'] ?? '') . " | " . ($o['customer_name'] ?? '') .
                 " | " . ($o['customer_email'] ?? '') . " | " . fmt_num($o['total_price_with_vat'] ?? 0) . " " . ($o['currency'] ?? '') .
                 " | " . ($o['payment_name'] ?? '') . " | " . ($o['delivery_name'] ?? '');
    }

    if (preg_match('/\b(\d{4,})\b/u', $userMessage, $m)) {
        $orderNo = $m[1];
        $d = ro_one($pdo, "
            SELECT o.number, o.created_at, o.customer_name, o.customer_email,
                   o.total_price_with_vat, o.currency, o.payment_name, o.delivery_name
            FROM orders o
            WHERE o.number = :no
            LIMIT 1
        ", [':no' => $orderNo]);

        if ($d) {
            $ctx[] = "";
            $ctx[] = "Detail objednávky {$orderNo}:";
            $ctx[] = "- Datum: " . ($d['created_at'] ?? '');
            $ctx[] = "- Zákazník: " . ($d['customer_name'] ?? '');
            $ctx[] = "- Email: " . ($d['customer_email'] ?? '');
            $ctx[] = "- Celkem: " . fmt_num($d['total_price_with_vat'] ?? 0) . " " . ($d['currency'] ?? '');
            $ctx[] = "- Platba: " . ($d['payment_name'] ?? '');
            $ctx[] = "- Doprava: " . ($d['delivery_name'] ?? '');

            try {
                $items = ro_select($pdo, "
                    SELECT oi.product_name, oi.quantity, oi.price_with_vat, oi.ean
                    FROM order_items oi
                    WHERE oi.order_number = :no
                    ORDER BY oi.id ASC
                    LIMIT 120
                ", [':no' => $orderNo]);
                if ($items) {
                    $ctx[] = "- Položky (max 120):";
                    foreach ($items as $it) {
                        $ctx[] = "  - " . ($it['product_name'] ?? '') .
                                 " | qty:" . fmt_num($it['quantity'] ?? 0) .
                                 " | cena:" . fmt_num($it['price_with_vat'] ?? 0) .
                                 " | EAN:" . ($it['ean'] ?? '');
                    }
                }
            } catch (Throwable $e2) {}
        }
    }
} catch (Throwable $e) {}

// -------------------------------------------------------
// 2) DYNAMICKÁ část: když se uživatel ptá na něco „mimo modul“, zkusíme vytáhnout přehled z dalších tabulek v DB
// -------------------------------------------------------
try {
    $pick = pick_relevant_tables($pdo, $dbName, $userMessage);
    $tables = $pick['tables'];

    if ($dbName && $tables) {
        // schema pro tyto tabulky
        $cols = schema_columns($pdo, $dbName, $tables);

        $ctx[] = "";
        $ctx[] = "Dynamický přehled relevantních tabulek (vybrané podle dotazu):";

        foreach ($tables as $t) {
            $ctx[] = "";
            $ctx[] = "Tabulka: {$t}";

            // sloupce (max 18)
            $colList = $cols[$t] ?? [];
            if ($colList) {
                $parts = [];
                $n = 0;
                foreach ($colList as $c) {
                    $parts[] = $c['col'] . ":" . $c['type'];
                    $n++;
                    if ($n >= 18) break;
                }
                $ctx[] = "Sloupce (max 18): " . implode(', ', $parts);
            } else {
                $ctx[] = "Sloupce: (nelze načíst)";
            }

            // počet řádků (pozor: u obřích tabulek je COUNT(*) náročnější, ale většinou ok)
            try {
                $cnt = ro_one($pdo, "SELECT COUNT(*) AS c FROM `{$t}`");
                $ctx[] = "Počet řádků: " . fmt_num($cnt['c'] ?? 0);
            } catch (Throwable $eCnt) {
                $ctx[] = "Počet řádků: (nelze načíst)";
            }

            // ukázka posledních 5 řádků – pokud existuje id/created_at/updated_at, řadíme podle něj
            try {
                $orderBy = '';
                $colNames = array_map(fn($x)=>$x['col'], $colList);
                if (in_array('created_at', $colNames, true)) $orderBy = 'ORDER BY created_at DESC';
                else if (in_array('updated_at', $colNames, true)) $orderBy = 'ORDER BY updated_at DESC';
                else if (in_array('id', $colNames, true)) $orderBy = 'ORDER BY id DESC';

                // vyber max 8 sloupců pro sample
                $sampleCols = [];
                foreach ($colNames as $cn) {
                    if (count($sampleCols) >= 8) break;
                    $sampleCols[] = "`{$cn}`";
                }
                if (!$sampleCols) throw new RuntimeException('no columns');

                $sampleSql = "SELECT " . implode(',', $sampleCols) . " FROM `{$t}` {$orderBy} LIMIT 5";
                $rows = ro_select($pdo, $sampleSql);
                if ($rows) {
                    $ctx[] = "Ukázka (posledních 5 řádků, max 8 sloupců):";
                    foreach ($rows as $r) {
                        // zjednodušené zobrazení key=value
                        $pairs = [];
                        foreach ($r as $k => $v) {
                            $sv = is_null($v) ? 'NULL' : (is_string($v) ? trim($v) : (string)$v);
                            if (strlen($sv) > 60) $sv = substr($sv, 0, 60) . '…';
                            $pairs[] = "{$k}=" . $sv;
                        }
                        $ctx[] = "- " . implode(' | ', $pairs);
                    }
                }
            } catch (Throwable $eSample) {
                // ignore
            }
        }
    }
} catch (Throwable $e) {
    // ignore
}

$contextText = implode("\n", $ctx);

// -------------------------------------------------------
// 3) OpenAI call – STRICT „jen z kontextu“
// -------------------------------------------------------
$systemPrompt =
    "Jsi interní asistent pro aplikaci cFloat. Jsi STRICTNĚ v režimu READ-ONLY." .
    " Neexistuje žádný zápis, změna, mazání ani spouštění akcí. Nikdy netvrď, že jsi něco změnil." .
    " Odpovídej POUZE na základě poskytnutého kontextu z databáze cFloat (KPI, top zákazníci, top produkty, náklady, vzorky tabulek)." .
    " Nevymýšlej čísla ani fakta, neodhaduje, nedopočítávej mimo kontext." .
    " Pokud odpověď není v kontextu nebo daná tabulka/sloupec neexistuje, řekni přesně: „Tohle v poskytnutých datech z databáze nemám.“" .
    " Preferuj stručné shrnutí + pár bodů. Odpovídej česky, věcně.";

$userInput =
    "KONTEXT Z DATABÁZE cFloat:\n" . $contextText . "\n\n" .
    "DOTAZ UŽIVATELE:\n" . $userMessage;

$payload = [
    'model' => 'gpt-5.1',
    'input' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userInput],
    ],
    'max_output_tokens' => 520,
];

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: ' . 'Bearer ' . OPENAI_API_KEY,
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 25,
]);

$result = curl_exec($ch);
if ($result === false) {
    $err = curl_error($ch);
    curl_close($ch);
    echo json_encode(['reply' => 'Chyba při volání OpenAI API: ' . $err]);
    exit;
}
curl_close($ch);

$resp = json_decode($result, true);
$replyText = null;

// Parsing Responses API – output_text
if (!empty($resp['output']) && is_array($resp['output'])) {
    foreach ($resp['output'] as $item) {
        if (($item['role'] ?? '') === 'assistant' && !empty($item['content']) && is_array($item['content'])) {
            foreach ($item['content'] as $part) {
                if (($part['type'] ?? '') === 'output_text') {
                    if (isset($part['text']) && is_string($part['text'])) {
                        $replyText = $part['text'];
                        break 2;
                    }
                }
            }
        }
    }
}

if ($replyText === null) $replyText = 'Nepodařilo se načíst odpověď z modelu. Zkus to prosím znovu.';

echo json_encode(['reply' => $replyText]);
?>
