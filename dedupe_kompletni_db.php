<?php
/**
 * dedupe_kompletni_db.php
 *
 * Odstraní duplicitní řádky z tabulky:
 *   Kompletni_DatabazeVariantyEANProdejeCeny
 *
 * Za duplicitní považujeme řádky se stejnou kombinací:
 *   (id_order, product_key, price_total_with_vat, count)
 *
 * Z každé skupiny duplicit:
 *   - ponecháme JEDEN řádek (preferujeme ten, kde price_s_dph NENÍ NULL),
 *   - ostatní fyzicky smažeme.
 *
 * price_s_dph NIKDY nepřepisujeme – jen vybíráme vhodný řádek k ponechání.
 *
 * Použití:
 *   1) nahraj skript na server vedle config.php (kořen webu / cfloat.cz),
 *   2) přihlas se v administraci (index.php) a pak spusť:
 *      https://cfloat.cz/dedupe_kompletni_db.php
 *   3) po doběhnutí zkontroluj výpis a data v tabulce.
 *
 * BEZPEČNOST: tento skript maže řádky v databázi, proto je (stejně jako
 * zbytek administrace) chráněný přihlášením – viz _auth_guard.php.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/_auth_guard.php';
if (!$loggedIn) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(403);
    die("403 Forbidden – přihlas se prosím v administraci (index.php) a spusť skript znovu.\n");
}

require __DIR__ . '/config.php'; // vytvoří $pdo

echo "START\n";

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('DB ERROR: chybí $pdo z config.php');
}

echo "DB OK\n";

// 1) Načteme všechny řádky v pořadí vhodném pro skupinování
$sql = "SELECT
            id,
            id_order,
            product_key,
            price_s_dph,
            price_total_with_vat,
            `count`
        FROM Kompletni_DatabazeVariantyEANProdejeCeny
        ORDER BY id_order, product_key, price_total_with_vat, `count`, id";

$st  = $pdo->query($sql);

$groupsDeleted = 0;
$rowsDeleted   = 0;

$lastKey   = null;
$groupRows = [];

function flush_group(PDO $pdo, array $group, &$groupsDeleted, &$rowsDeleted) {
    if (count($group) <= 1) {
        return;
    }

    // vybereme řádek, který ponecháme
    $keepIndex = 0;
    foreach ($group as $i => $row) {
        $hasPrice = ($row['price_s_dph'] !== null);
        $keepHasPrice = ($group[$keepIndex]['price_s_dph'] !== null);
        if ($hasPrice && !$keepHasPrice) {
            $keepIndex = $i;
        }
    }

    $keepId = (int)$group[$keepIndex]['id'];
    $deleteIds = [];
    foreach ($group as $i => $row) {
        $id = (int)$row['id'];
        if ($id === $keepId) {
            continue;
        }
        $deleteIds[] = $id;
    }

    if (!empty($deleteIds)) {
        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $sqlDel = "DELETE FROM Kompletni_DatabazeVariantyEANProdejeCeny
                   WHERE id IN ($placeholders)";
        $stDel = $pdo->prepare($sqlDel);
        $stDel->execute($deleteIds);

        $groupsDeleted++;
        $rowsDeleted += count($deleteIds);

        echo "Skupina DUPLICIT (id_order={$group[0]['id_order']}, product_key={$group[0]['product_key']}, total={$group[0]['price_total_with_vat']}, qty={$group[0]['count']}): ";
        echo "ponechávám id=$keepId, mažu " . implode(',', $deleteIds) . "\n";
    }
}

// projdeme výsledek a skládáme skupiny
while ($row = $st->fetch()) {
    $key = $row['id_order'] . '|' . $row['product_key'] . '|' . $row['price_total_with_vat'] . '|' . $row['count'];

    if ($lastKey !== null && $key !== $lastKey) {
        flush_group($pdo, $groupRows, $groupsDeleted, $rowsDeleted);
        $groupRows = [];
    }

    $groupRows[] = $row;
    $lastKey = $key;
}

// dočistíme poslední skupinu
flush_group($pdo, $groupRows, $groupsDeleted, $rowsDeleted);

echo "HOTOVO\n";
echo "Skupin smazaných duplicit: $groupsDeleted\n";
echo "Celkem smazaných řádků: $rowsDeleted\n";

?>
