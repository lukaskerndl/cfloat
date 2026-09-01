<?php
// chat.php – cFloat Chat (STRICT READ-ONLY + Reporting/Statistika položek + Dynamické tabulky)
// ✅ Odpovědi jen z DB cFloat (jen SELECT/CTE). ✅ Žádné zápisy.
//
// Co umí (pokud jsou data ve DB):
// - Objednávky + obrat: dnes / tento týden / tento měsíc / tento rok + volitelně detekovaný rozsah z dotazu
// - Zisk ze zboží (profit): pokud jde spočítat z order_items (prodejní cena + nákupní cena) nebo je už sloupec zisk
// - Průměrný denní zisk pro daný rozsah (profit / počet dní)
// - TOP produkty + kusy (dnes / tento týden / tento měsíc / detekovaný rozsah)
// - TOP zákazníci (tento měsíc) + dnešní zákazníci
// - Náklady z monthly_costs (tento měsíc) + čistý zisk (profit - costs), pokud profit dostupný
// - Když se ptáš mimo moduly: dynamicky vytáhne přehled vybraných tabulek (sloupce + počet řádků + vzorek)

session_start();
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Prague');

if (empty($_SESSION['logged_in'])) { echo json_encode(['reply'=>'K chatu máš přístup jen po přihlášení do aplikace.']); exit; }

require __DIR__ . '/config.php';

if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) {
    echo json_encode(['reply'=>'OpenAI API klíč není nastavený v config.php (konstanta OPENAI_API_KEY).']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$userMessage = (is_array($data) && isset($data['message'])) ? trim((string)$data['message']) : '';
if ($userMessage === '') { echo json_encode(['reply'=>'Zpráva je prázdná. Napiš dotaz.']); exit; }

// ---------------- helpers ----------------
function starts_with(string $h, string $n): bool { return $n === '' || strncmp($h, $n, strlen($n)) === 0; }
function lower_str(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s,'UTF-8') : strtolower($s); }
function fmt_num($n): string {
    if (!is_numeric($n)) return (string)$n;
    $f = (float)$n;
    if (abs($f - round($f)) < 0.0000001) return (string)((int)round($f));
    return rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
}
function ro_select(PDO $pdo, string $sql, array $params = []): array {
    $s = ltrim($sql);
    $u = strtoupper($s);
    if (!(starts_with($u,'SELECT') || starts_with($u,'WITH'))) throw new RuntimeException('READ-ONLY: jen SELECT/CTE.');
    if (strpos($s,';') !== false) throw new RuntimeException('READ-ONLY: zakázány více příkazy.');
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function ro_one(PDO $pdo, string $sql, array $params=[]): ?array { $r = ro_select($pdo,$sql,$params); return $r[0] ?? null; }

function dbname(PDO $pdo): ?string {
    try { return ro_one($pdo,"SELECT DATABASE() AS db")['db'] ?? null; } catch (Throwable $e) { return null; }
}
function col_exists(PDO $pdo, string $db, string $table, string $col): bool {
    try{
        $r = ro_one($pdo, "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t AND COLUMN_NAME=:c",
            [':db'=>$db,':t'=>$table,':c'=>$col]
        );
        return ((int)($r['c'] ?? 0)) > 0;
    }catch(Throwable $e){ return false; }
}
function days_in_range(string $fromYmd, string $toYmd): int {
    $a = DateTime::createFromFormat('Y-m-d', $fromYmd) ?: new DateTime('today');
    $b = DateTime::createFromFormat('Y-m-d', $toYmd) ?: new DateTime('today');
    $a->setTime(0,0,0); $b->setTime(0,0,0);
    $diff = $a->diff($b);
    return (int)$diff->days + 1;
}

function range_from_msg(string $msg): array {
    // returns [from (Y-m-d), to (Y-m-d), label]
    $m = lower_str($msg);

    // explicit YYYY-MM-DD (1 or 2)
    if (preg_match_all('/\b(20\d{2}-\d{2}-\d{2})\b/u', $msg, $mm) && !empty($mm[1])) {
        $d = $mm[1];
        if (count($d) >= 2) return [$d[0], $d[1], "rozsah {$d[0]} až {$d[1]}"];
        return [$d[0], $d[0], "datum {$d[0]}"];
    }

    if (strpos($m,'dnes') !== false) {
        $d = date('Y-m-d');
        return [$d,$d,'dnes'];
    }
    if (strpos($m,'včera') !== false || strpos($m,'vcera') !== false) {
        $d = date('Y-m-d', strtotime('-1 day'));
        return [$d,$d,'včera'];
    }
    if (strpos($m,'tento týden') !== false || strpos($m,'tento tyden') !== false || preg_match('/\btento\s+tyd(en|en)\b/u',$m)) {
        $from = date('Y-m-d', strtotime('monday this week'));
        $to   = date('Y-m-d');
        return [$from,$to,'tento týden'];
    }
    if (strpos($m,'minulý týden') !== false || strpos($m,'minuly tyden') !== false) {
        $from = date('Y-m-d', strtotime('monday last week'));
        $to   = date('Y-m-d', strtotime('sunday last week'));
        return [$from,$to,'minulý týden'];
    }
    if (strpos($m,'tento měsíc') !== false || strpos($m,'tento mesic') !== false) {
        $from = date('Y-m-01');
        $to   = date('Y-m-d');
        return [$from,$to,'tento měsíc'];
    }
    if (strpos($m,'minulý měsíc') !== false || strpos($m,'minuly mesic') !== false) {
        $from = date('Y-m-01', strtotime('first day of last month'));
        $to   = date('Y-m-t', strtotime('last day of last month'));
        return [$from,$to,'minulý měsíc'];
    }
    if (strpos($m,'tento rok') !== false) {
        $from = date('Y-01-01');
        $to   = date('Y-m-d');
        return [$from,$to,'tento rok'];
    }
    if (strpos($m,'minulý rok') !== false || strpos($m,'minuly rok') !== false) {
        $y = (int)date('Y') - 1;
        return ["{$y}-01-01","{$y}-12-31",'minulý rok'];
    }

    // default: tento měsíc
    $from = date('Y-m-01');
    $to = date('Y-m-d');
    return [$from,$to,'tento měsíc'];
}

// ---------------- build context ----------------
$db = dbname($pdo);
$ctx = [];
$ctx[] = "REŽIM: STRICT READ-ONLY (jen čtení z DB cFloat).";
$ctx[] = "DB: " . ($db ?: '(nezjištěno)') . " | Dnes: " . date('Y-m-d') . " (Europe/Prague).";

// detect ranges
[$fromDet, $toDet, $labelDet] = range_from_msg($userMessage);
$detDays = days_in_range($fromDet, $toDet);

// Standard ranges
$fromToday = date('Y-m-d'); $toToday = $fromToday;
$fromWeek  = date('Y-m-d', strtotime('monday this week')); $toWeek = date('Y-m-d');
$fromMonth = date('Y-m-01'); $toMonth = date('Y-m-d');
$fromYear  = date('Y-01-01'); $toYear = date('Y-m-d');

$ctx[] = "Detekovaný rozsah z dotazu: {$labelDet} ({$fromDet} až {$toDet}), počet dní={$detDays}.";

// Helper for order aggregates by date range using created_at
function orders_agg(PDO $pdo, string $from, string $to): ?array {
    return ro_one($pdo, "
        SELECT COUNT(*) AS orders_cnt,
               COALESCE(SUM(o.total_price_with_vat),0) AS revenue_sum,
               COALESCE(AVG(o.total_price_with_vat),0) AS aov
        FROM orders o
        WHERE o.created_at >= :from
          AND o.created_at <  DATE_ADD(:to, INTERVAL 1 DAY)
    ", [':from'=>$from, ':to'=>$to]);
}

// Profit expression builder
$profitAvailable = false;
$profitHow = '';
$profitSqlExpr = '';
$profitJoinOrders = true; // profit uses order_items join orders for date filter
$qtyCol = 'quantity';
$sellCol = 'price_with_vat';
$buyCol = null;
$profitCol = null;

if ($db) {
    // identify columns
    $qtyCandidates  = ['quantity','qty','count','ks'];
    $sellCandidates = ['price_with_vat','unit_price_with_vat','price','unit_price','sell_price','total_price_with_vat'];
    $buyCandidates  = ['nakupni_cena_with_vat','nakupni_cena','purchase_price_with_vat','purchase_price','buy_price','cost','purchase_cost'];
    $profitCandidates = ['zisk','profit','gross_profit','profit_total'];

    foreach ($qtyCandidates as $c) { if (col_exists($pdo,$db,'order_items',$c)) { $qtyCol = $c; break; } }
    foreach ($sellCandidates as $c) { if (col_exists($pdo,$db,'order_items',$c)) { $sellCol = $c; break; } }
    foreach ($profitCandidates as $c) { if (col_exists($pdo,$db,'order_items',$c)) { $profitCol = $c; break; } }
    foreach ($buyCandidates as $c) { if (col_exists($pdo,$db,'order_items',$c)) { $buyCol = $c; break; } }

    // If profit column exists, prefer it (assume already total per row, but we detect if qty exists too)
    if ($profitCol) {
        $profitAvailable = true;
        $profitHow = "profit z order_items.{$profitCol} (SUM)";
        $profitSqlExpr = "COALESCE(SUM(oi.`{$profitCol}`),0)";
    } else if ($buyCol && $sellCol && $qtyCol) {
        $profitAvailable = true;
        $profitHow = "počítáno jako (order_items.{$sellCol} - order_items.{$buyCol}) * order_items.{$qtyCol}";
        $profitSqlExpr = "COALESCE(SUM((oi.`{$sellCol}` - oi.`{$buyCol}`) * oi.`{$qtyCol}`),0)";
    }
}

// Profit aggregate by date range (needs join to orders to filter by created_at)
function profit_agg(PDO $pdo, bool $available, string $profitExpr, string $from, string $to): ?array {
    if (!$available || !$profitExpr) return null;
    // prefer join by o.number = oi.order_number; if not exists, query will fail and we catch outside
    return ro_one($pdo, "
        SELECT {$profitExpr} AS profit_sum
        FROM order_items oi
        JOIN orders o ON o.number = oi.order_number
        WHERE o.created_at >= :from
          AND o.created_at <  DATE_ADD(:to, INTERVAL 1 DAY)
    ", [':from'=>$from, ':to'=>$to]);
}

// Top products by qty (join orders for date filter)
function top_products(PDO $pdo, string $from, string $to, int $limit=15): array {
    return ro_select($pdo, "
        SELECT COALESCE(NULLIF(TRIM(oi.product_name),''),'(nevyplněno)') AS product_name,
               COALESCE(SUM(oi.quantity),0) AS qty_sum
        FROM order_items oi
        JOIN orders o ON o.number = oi.order_number
        WHERE o.created_at >= :from
          AND o.created_at <  DATE_ADD(:to, INTERVAL 1 DAY)
        GROUP BY product_name
        ORDER BY qty_sum DESC
        LIMIT {$limit}
    ", [':from'=>$from, ':to'=>$to]);
}

// KPI blocks
try {
    $ctx[] = "";
    $ctx[] = "KPI – Objednávky a obrat (orders):";
    $a = orders_agg($pdo, $fromToday, $toToday);
    $ctx[] = "- Dnes ({$fromToday}): objednávky=" . fmt_num($a['orders_cnt'] ?? 0) . ", obrat=" . fmt_num($a['revenue_sum'] ?? 0) . ", průměr=" . fmt_num($a['aov'] ?? 0);
    $a = orders_agg($pdo, $fromWeek, $toWeek);
    $ctx[] = "- Tento týden ({$fromWeek} až {$toWeek}): objednávky=" . fmt_num($a['orders_cnt'] ?? 0) . ", obrat=" . fmt_num($a['revenue_sum'] ?? 0) . ", průměr=" . fmt_num($a['aov'] ?? 0);
    $a = orders_agg($pdo, $fromMonth, $toMonth);
    $ctx[] = "- Tento měsíc ({$fromMonth} až {$toMonth}): objednávky=" . fmt_num($a['orders_cnt'] ?? 0) . ", obrat=" . fmt_num($a['revenue_sum'] ?? 0) . ", průměr=" . fmt_num($a['aov'] ?? 0);
    $a = orders_agg($pdo, $fromYear, $toYear);
    $ctx[] = "- Tento rok ({$fromYear} až {$toYear}): objednávky=" . fmt_num($a['orders_cnt'] ?? 0) . ", obrat=" . fmt_num($a['revenue_sum'] ?? 0) . ", průměr=" . fmt_num($a['aov'] ?? 0);

    if (!($fromDet === $fromMonth && $toDet === $toMonth)) {
        $a = orders_agg($pdo, $fromDet, $toDet);
        $ctx[] = "- Detekovaný rozsah ({$fromDet} až {$toDet}): objednávky=" . fmt_num($a['orders_cnt'] ?? 0) . ", obrat=" . fmt_num($a['revenue_sum'] ?? 0) . ", průměr=" . fmt_num($a['aov'] ?? 0);
    }
} catch (Throwable $e) {
    $ctx[] = "";
    $ctx[] = "KPI (orders): nelze načíst (tabulka/struktura chybí).";
}

// Profit blocks
$ctx[] = "";
if ($profitAvailable) {
    $ctx[] = "ZISK ZE ZBOŽÍ (profit): dostupné – {$profitHow}.";
    try {
        $p = profit_agg($pdo, true, $profitSqlExpr, $fromToday, $toToday);
        $ctx[] = "- Dnes: zisk=" . fmt_num($p['profit_sum'] ?? 0) . " | průměr/den=" . fmt_num(($p['profit_sum'] ?? 0) / 1);
        $p = profit_agg($pdo, true, $profitSqlExpr, $fromWeek, $toWeek);
        $days = max(1, days_in_range($fromWeek, $toWeek));
        $ctx[] = "- Tento týden: zisk=" . fmt_num($p['profit_sum'] ?? 0) . " | průměr/den=" . fmt_num(($p['profit_sum'] ?? 0) / $days);
        $p = profit_agg($pdo, true, $profitSqlExpr, $fromMonth, $toMonth);
        $days = max(1, days_in_range($fromMonth, $toMonth));
        $ctx[] = "- Tento měsíc: zisk=" . fmt_num($p['profit_sum'] ?? 0) . " | průměr/den=" . fmt_num(($p['profit_sum'] ?? 0) / $days);
        $p = profit_agg($pdo, true, $profitSqlExpr, $fromYear, $toYear);
        $days = max(1, days_in_range($fromYear, $toYear));
        $ctx[] = "- Tento rok: zisk=" . fmt_num($p['profit_sum'] ?? 0) . " | průměr/den=" . fmt_num(($p['profit_sum'] ?? 0) / $days);

        if (!($fromDet === $fromMonth && $toDet === $toMonth)) {
            $p = profit_agg($pdo, true, $profitSqlExpr, $fromDet, $toDet);
            $days = max(1, days_in_range($fromDet, $toDet));
            $ctx[] = "- Detekovaný rozsah: zisk=" . fmt_num($p['profit_sum'] ?? 0) . " | průměr/den=" . fmt_num(($p['profit_sum'] ?? 0) / $days);
        }
    } catch (Throwable $e) {
        $ctx[] = "Zisk: nelze spočítat (pravděpodobně nesedí vazba order_items -> orders; uprav JOIN).";
    }
} else {
    $ctx[] = "ZISK ZE ZBOŽÍ (profit): NENÍ dostupné v DB (nenašel jsem sloupec zisk ani nákupní cenu v order_items).";
    $ctx[] = "Tip: doplň do order_items sloupec např. nakupni_cena nebo zisk, a pak to půjde počítat.";
}

// Top products blocks
try {
    $ctx[] = "";
    $ctx[] = "STATISTIKA POLOŽEK – TOP produkty podle kusů (order_items):";
    $tp = top_products($pdo, $fromToday, $toToday, 10);
    if ($tp) {
        $ctx[] = "- Dnes (TOP 10):";
        foreach ($tp as $r) $ctx[] = "  - " . ($r['product_name'] ?? '') . " = " . fmt_num($r['qty_sum'] ?? 0);
    } else $ctx[] = "- Dnes: (žádná data)";
    $tp = top_products($pdo, $fromWeek, $toWeek, 10);
    if ($tp) {
        $ctx[] = "- Tento týden (TOP 10):";
        foreach ($tp as $r) $ctx[] = "  - " . ($r['product_name'] ?? '') . " = " . fmt_num($r['qty_sum'] ?? 0);
    } else $ctx[] = "- Tento týden: (žádná data)";
    $tp = top_products($pdo, $fromMonth, $toMonth, 10);
    if ($tp) {
        $ctx[] = "- Tento měsíc (TOP 10):";
        foreach ($tp as $r) $ctx[] = "  - " . ($r['product_name'] ?? '') . " = " . fmt_num($r['qty_sum'] ?? 0);
    } else $ctx[] = "- Tento měsíc: (žádná data)";

    if (!($fromDet === $fromMonth && $toDet === $toMonth)) {
        $tp = top_products($pdo, $fromDet, $toDet, 10);
        $ctx[] = "- Detekovaný rozsah (TOP 10):";
        if ($tp) { foreach ($tp as $r) $ctx[] = "  - " . ($r['product_name'] ?? '') . " = " . fmt_num($r['qty_sum'] ?? 0); }
        else $ctx[] = "  - (žádná data)";
    }

} catch (Throwable $e) {
    $ctx[] = "";
    $ctx[] = "Top produkty: nelze načíst (pravděpodobně nesedí vazba order_items -> orders; uprav JOIN nebo názvy sloupců).";
}

// Customers today + top customers month
try {
    $custToday = ro_select($pdo, "
        SELECT COALESCE(NULLIF(TRIM(o.customer_name),''),'(nevyplněno)') AS customer_name,
               COALESCE(NULLIF(TRIM(o.customer_email),''),'') AS customer_email,
               COUNT(*) AS orders_cnt,
               COALESCE(SUM(o.total_price_with_vat),0) AS revenue_sum
        FROM orders o
        WHERE o.created_at >= CURDATE() AND o.created_at < (CURDATE()+INTERVAL 1 DAY)
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
    $topCust = ro_select($pdo, "
        SELECT COALESCE(NULLIF(TRIM(o.customer_name),''),'(nevyplněno)') AS customer_name,
               COALESCE(NULLIF(TRIM(o.customer_email),''),'') AS customer_email,
               COUNT(*) AS orders_cnt,
               COALESCE(SUM(o.total_price_with_vat),0) AS revenue_sum
        FROM orders o
        WHERE o.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND o.created_at < (DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH)
        GROUP BY customer_name, customer_email
        ORDER BY revenue_sum DESC
        LIMIT 15
    ");
    if ($topCust) {
        $ctx[] = "";
        $ctx[] = "TOP zákazníci tento měsíc (max 15) – jméno | email | objednávky | obrat:";
        foreach ($topCust as $c) {
            $ctx[] = "- " . ($c['customer_name'] ?? '') . " | " . ($c['customer_email'] ?? '') . " | " . fmt_num($c['orders_cnt'] ?? 0) . " | " . fmt_num($c['revenue_sum'] ?? 0);
        }
    }
} catch (Throwable $e) {}

// Costs (monthly_costs) + net profit month
try {
    $costSum = ro_one($pdo, "
        SELECT COALESCE(SUM(amount),0) AS costs_sum
        FROM monthly_costs
        WHERE start_year = YEAR(CURDATE()) AND start_month = MONTH(CURDATE())
    ");
    $ctx[] = "";
    $ctx[] = "Náklady (monthly_costs) – tento měsíc: " . fmt_num($costSum['costs_sum'] ?? 0);
    if ($profitAvailable) {
        try {
            $p = profit_agg($pdo, true, $profitSqlExpr, $fromMonth, $toMonth);
            $net = (float)($p['profit_sum'] ?? 0) - (float)($costSum['costs_sum'] ?? 0);
            $ctx[] = "Čistý zisk tento měsíc (zisk ze zboží - náklady): " . fmt_num($net);
        } catch (Throwable $e) {}
    }
} catch (Throwable $e) {}

// Latest orders + order detail if number in message
try {
    $rows = ro_select($pdo, "
        SELECT o.number, o.created_at, o.customer_name, o.customer_email, o.total_price_with_vat, o.currency, o.payment_name, o.delivery_name
        FROM orders o
        ORDER BY o.created_at DESC
        LIMIT 20
    ");
    $ctx[] = "";
    $ctx[] = "Posledních 20 objednávek:";
    foreach ($rows as $o) {
        $ctx[] = "- " . ($o['number'] ?? '') . " | " . ($o['created_at'] ?? '') . " | " . ($o['customer_name'] ?? '') .
                 " | " . ($o['customer_email'] ?? '') . " | " . fmt_num($o['total_price_with_vat'] ?? 0) . " " . ($o['currency'] ?? '') .
                 " | " . ($o['payment_name'] ?? '') . " | " . ($o['delivery_name'] ?? '');
    }

    if (preg_match('/\b(\d{4,})\b/u', $userMessage, $m)) {
        $orderNo = $m[1];
        $d = ro_one($pdo, "
            SELECT o.number, o.created_at, o.customer_name, o.customer_email, o.total_price_with_vat, o.currency, o.payment_name, o.delivery_name
            FROM orders o
            WHERE o.number = :no
            LIMIT 1
        ", [':no'=>$orderNo]);

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
                ", [':no'=>$orderNo]);
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

// ---------------- Dynamic tables (lightweight) ----------------
if ($db) {
    try{
        // pick a few tables if user mentions one; otherwise skip to keep context smaller
        $msg = lower_str($userMessage);
        $tables = [];
        if (preg_match('/\b(?:tabulka|table)\s+([a-zA-Z0-9_]+)\b/u', $userMessage, $m)) {
            $tables[] = $m[1];
        }
        // also if user says "statistika" include candidate statistics tables
        if (strpos($msg,'statistika') !== false) {
            foreach (['stats','statistika','product_stats','item_stats'] as $t) $tables[] = $t;
        }
        $tables = array_values(array_unique($tables));
        if ($tables) {
            $ctx[] = "";
            $ctx[] = "Dynamický přehled (podle dotazu) – tabulky:";
            foreach ($tables as $t) {
                // columns (max 18)
                $cols = ro_select($pdo, "
                    SELECT COLUMN_NAME AS c, DATA_TYPE AS t
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tab
                    ORDER BY ORDINAL_POSITION
                ", [':db'=>$db, ':tab'=>$t]);
                if (!$cols) { $ctx[] = "- {$t}: (tabulka neexistuje)"; continue; }
                $ctx[] = "- Tabulka: {$t}";
                $parts = [];
                foreach ($cols as $i=>$c){ $parts[] = $c['c'].":".$c['t']; if ($i>=17) break; }
                $ctx[] = "  Sloupce (max 18): " . implode(', ', $parts);
                // count
                try{ $cnt = ro_one($pdo, "SELECT COUNT(*) AS c FROM `{$t}`"); $ctx[] = "  Počet řádků: " . fmt_num($cnt['c'] ?? 0); } catch(Throwable $e3){}
            }
        }
    }catch(Throwable $e){}
}

// ---------------- OpenAI call ----------------
$contextText = implode("\n", $ctx);

$systemPrompt =
    "Jsi interní asistent pro aplikaci cFloat. Jsi STRICTNĚ v režimu READ-ONLY." .
    " Neexistuje žádný zápis, změna, mazání ani spouštění akcí. Nikdy netvrď, že jsi něco změnil." .
    " Odpovídej POUZE na základě poskytnutého kontextu z databáze cFloat." .
    " Nevymýšlej čísla ani fakta, neodhaduje, nedopočítávej mimo kontext." .
    " Pokud odpověď není v kontextu nebo daná tabulka/sloupec neexistuje, řekni přesně: „Tohle v poskytnutých datech z databáze nemám.“" .
    " Odpovídej česky, stručně, věcně. Když se uživatel ptá na průměry, použij přímo průměr/den z kontextu.";

$userInput = "KONTEXT Z DATABÁZE cFloat:\n" . $contextText . "\n\nDOTAZ UŽIVATELE:\n" . $userMessage;

$payload = [
    'model' => 'gpt-5.1',
    'input' => [
        ['role'=>'system','content'=>$systemPrompt],
        ['role'=>'user','content'=>$userInput],
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

if (!empty($resp['output']) && is_array($resp['output'])) {
    foreach ($resp['output'] as $item) {
        if (($item['role'] ?? '') === 'assistant' && !empty($item['content']) && is_array($item['content'])) {
            foreach ($item['content'] as $part) {
                if (($part['type'] ?? '') === 'output_text') {
                    if (isset($part['text']) && is_string($part['text'])) { $replyText = $part['text']; break 2; }
                }
            }
        }
    }
}
if ($replyText === null) $replyText = 'Nepodařilo se načíst odpověď z modelu. Zkus to prosím znovu.';

echo json_encode(['reply'=>$replyText]);
?>
