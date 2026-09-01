<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../index.php');
    exit;
}

$cfgCandidates = [__DIR__ . '/../config.php', __DIR__ . '/../../config.php'];
$pdo = null;
foreach ($cfgCandidates as $p) {
    if (is_file($p)) { require $p; break; }
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/lib/vavrys_katalog.php';

/**
 * ===========================================================================
 *  NÁHLED OBJEDNÁVKY U VAVRYS – POUZE UKÁZKA, NIC SE NEODESÍLÁ!
 * ===========================================================================
 *  Tahle stránka:
 *   1) Najde produkt v katalogu Vavrys (podle EAN) a vytáhne identifikátory
 *      (KatalogId, StrCislo, KarCislo, KarCisloId, IdX, IdY), které Vavrys
 *      API vyžaduje pro metodu NovaObjednavka.
 *   2) Sestaví XML tak, jak by se POSÍLALO – jen ho zobrazí na obrazovce.
 *   3) NIKAM NEVOLÁ ŽÁDNÉ API. Žádná objednávka se u Vavrys nezaloží.
 * ===========================================================================
 */

// ==== Zpracování SKUTEČNÉHO odeslání objednávky (POST) ====
// Token se generuje při zobrazení náhledu a je jednorázový - použije/smaže se
// při prvním POST bez ohledu na výsledek, aby nešlo omylem odeslat objednávku
// dvakrát (např. obnovením stránky po odeslání).
$orderSubmitResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['objednat_naostro'])) {
    $postToken = (string)($_POST['token'] ?? '');
    $sessToken = (string)($_SESSION['vpo_token'] ?? '');
    unset($_SESSION['vpo_token']);

    if ($postToken === '' || $sessToken === '' || !hash_equals($sessToken, $postToken)) {
        $orderSubmitResult = ['success' => false, 'error' => 'Neplatný nebo již použitý bezpečnostní token. Obnovte stránku, znovu vyhledejte položku a zkuste to prosím znovu (ochrana proti duplicitnímu odeslání).'];
    } elseif (empty($_POST['confirm_final'])) {
        $orderSubmitResult = ['success' => false, 'error' => 'Nebylo zaškrtnuto potvrzení. Objednávka nebyla odeslána.'];
    } elseif (!isset($VAVRYS_LOGIN, $VAVRYS_PASSWORD) || $VAVRYS_LOGIN === '' || $VAVRYS_PASSWORD === '') {
        $orderSubmitResult = ['success' => false, 'error' => 'Přihlašovací údaje $VAVRYS_LOGIN / $VAVRYS_PASSWORD nejsou k dispozici (nenačetly se z config.php).'];
    } else {
        $pCislo = (string)($_POST['p_cislo'] ?? '');
        $pDatum = (string)($_POST['p_datum'] ?? '');
        $pKatalogId = (string)($_POST['p_katalogId'] ?? '');
        $pStrCislo = (string)($_POST['p_strCislo'] ?? '');
        $pKarCislo = (string)($_POST['p_karCislo'] ?? '');
        $pKarCisloId = (string)($_POST['p_karCisloId'] ?? '');
        $pIdX = (string)($_POST['p_idX'] ?? '');
        $pIdY = (string)($_POST['p_idY'] ?? '');
        $pMnozstvi = max(1, (int)($_POST['p_mnozstvi'] ?? 1));
        $pCena = (float)($_POST['p_cena'] ?? 0);

        $objednavkaData = vpo_build_objednavka_data($pCislo, $pDatum, [[
            'katalogId' => $pKatalogId,
            'strCislo' => $pStrCislo,
            'karCislo' => $pKarCislo,
            'karCisloId' => $pKarCisloId,
            'idX' => $pIdX,
            'idY' => $pIdY,
            'mnozstvi' => $pMnozstvi,
            'cena' => $pCena,
        ]]);

        $orderSubmitResult = vpo_send_objednavka($VAVRYS_LOGIN, $VAVRYS_PASSWORD, $objednavkaData);
    }
}

$ean = isset($_GET['ean']) ? vpo_norm_ean((string)$_GET['ean']) : '';
$kod = isset($_GET['kod']) ? trim((string)$_GET['kod']) : '';
$velikost = isset($_GET['velikost']) ? trim((string)$_GET['velikost']) : '';
$pocetKs = max(1, (int)($_GET['ks'] ?? 1));
$objNumber = isset($_GET['obj']) ? trim((string)$_GET['obj']) : '';

// --- Volitelně: načíst položky reálné objednávky z naší DB (order_items.EAN) ---
$objItems = [];
$objError = '';
if ($objNumber !== '' && $pdo instanceof PDO) {
    try {
        $st = $pdo->prepare("SELECT oi.product_number, oi.product_name, oi.variant_description, oi.EAN, oi.count
            FROM order_items oi
            JOIN orders o ON o.id_order = oi.id_order
            WHERE o.number = :n
            ORDER BY oi.id ASC");
        $st->execute([':n' => $objNumber]);
        $objItems = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($objItems)) $objError = "Objednávka č. {$objNumber} nebyla nalezena nebo nemá žádné položky.";
    } catch (Throwable $e) {
        $objError = 'Chyba při čtení objednávky z databáze: ' . $e->getMessage();
    }
} elseif ($objNumber !== '') {
    $objError = 'Nepodařilo se připojit k produkční databázi (config.php).';
}

// pokud přišel klik na konkrétní položku z objednávky, EAN se předvyplní přes GET (viz odkazy níže)


$file = vpo_find_vavrys_file();
$result = null;
$searchError = '';
$codeChoices = [];

if ($file === null) {
    $searchError = 'Soubor katalogu Vavrys (vavrys_katalog.xml) nebyl na serveru nalezen.';
} elseif ($ean !== '') {
    @set_time_limit(60);
    $result = vpo_find_by_ean($file, $ean);
    if ($result === null) $searchError = "EAN {$ean} nebyl v katalogu Vavrys nalezen.";
} elseif ($kod !== '') {
    @set_time_limit(60);
    $candidates = vpo_find_by_code($file, $kod);
    if (empty($candidates)) {
        $searchError = "Kód „{$kod}“ nebyl v katalogu Vavrys nalezen.";
    } elseif (count($candidates) === 1) {
        $result = $candidates[0];
    } else {
        // víc velikostí stejné barvy – zkusíme najít tu správnou podle velikosti z objednávky
        $velNorm = vpo_norm_velikost($velikost);
        foreach ($candidates as $c) {
            if (vpo_norm_velikost($c['ozn_velikost']) === $velNorm && $velNorm !== '') {
                $result = $c;
                break;
            }
        }
        if ($result === null) {
            // nenašli jsme přesnou velikost -> ukážeme uživateli všechny možnosti k výběru
            $codeChoices = $candidates;
        }
    }
}

// --- Sestavení náhledu XML pro NovaObjednavka (JEN ZOBRAZENÍ, NEODESÍLÁ SE) ---
// Struktura ověřena proti oficiální dokumentaci Vavrys (Benefit 2000 Plus, kap. 4.2).
$previewXml = '';
$missingOdberatel = [];
if ($result !== null) {
    // Cena bez DPH přímo z katalogu Vavrys (jejich vlastní pole "Cena" u položky).
    // POZN.: přepočet ×0,97×1,21 se používá jinde v projektu pro výpočet VAŠÍ nákupní ceny
    // (interní evidence marže) - sem do objednávky patří jejich vlastní prodejní cena bez DPH,
    // ne přepočtená nákupní cena. Pokud se ukáže, že to Vavrys validuje jinak, upravíme.
    $cenaBezDph = (float)$result['cena'];
    $vlastniCislo = 'CSTORE-' . date('Ymd-His'); // referenční číslo objednávky z naší strany
    $datumObj = date('Y-m-d');

    foreach ([
        'Odberatel.Id' => VAVRYS_ODBERATEL_ID,
        'Nazev firmy' => VAVRYS_NAZEV,
        'Fakt. ulice' => VAVRYS_FAKT_ULICE,
        'Fakt. mesto' => VAVRYS_FAKT_MESTO,
        'Fakt. PSC' => VAVRYS_FAKT_PSC,
        'Dod. ulice' => VAVRYS_DOD_ULICE,
        'Dod. mesto' => VAVRYS_DOD_MESTO,
        'Dod. PSC' => VAVRYS_DOD_PSC,
        'ICO' => VAVRYS_ICO,
        'DIC' => VAVRYS_DIC,
        'Telefon' => VAVRYS_TELEFON,
        'Email' => VAVRYS_EMAIL,
    ] as $label => $val) {
        if ($val === 'DOPLNIT') $missingOdberatel[] = $label;
    }

    $previewXml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
        . "<!-- login a password se posílají jako samostatné parametry SOAP metody NovaObjednavka, -->\n"
        . "<!-- nejsou součástí objektu Objednavka. Zde jen orientačně: login/password = •••••••• -->\n"
        . "<Objednavka>\n"
        . "  <Cislo>" . h($vlastniCislo) . "</Cislo>\n"
        . "  <Datum>" . h($datumObj) . "</Datum>\n"
        . "  <ZpusobPlatby>" . h(VAVRYS_ZPUSOB_PLATBY) . "</ZpusobPlatby>\n"
        . "  <ZpusobDopravy>" . h(VAVRYS_ZPUSOB_DOPRAVY) . "</ZpusobDopravy>\n"
        . "  <Poznamka>" . h(VAVRYS_POZNAMKA_TEXT) . "</Poznamka>\n"
        . "  <Odberatel>\n"
        . "    <Id>" . h(VAVRYS_ODBERATEL_ID) . "</Id>\n"
        . "    <AdresaDodaci>\n"
        . "      <Nazev1>" . h(VAVRYS_NAZEV) . "</Nazev1>\n"
        . "      <Ulice>" . h(VAVRYS_DOD_ULICE) . "</Ulice>\n"
        . "      <Mesto>" . h(VAVRYS_DOD_MESTO) . "</Mesto>\n"
        . "      <Psc>" . h(VAVRYS_DOD_PSC) . "</Psc>\n"
        . "      <Zeme>" . h(VAVRYS_ADR_ZEME) . "</Zeme>\n"
        . "    </AdresaDodaci>\n"
        . "    <AdresaFakturacni>\n"
        . "      <Nazev1>" . h(VAVRYS_NAZEV) . "</Nazev1>\n"
        . "      <Ulice>" . h(VAVRYS_FAKT_ULICE) . "</Ulice>\n"
        . "      <Mesto>" . h(VAVRYS_FAKT_MESTO) . "</Mesto>\n"
        . "      <Psc>" . h(VAVRYS_FAKT_PSC) . "</Psc>\n"
        . "      <Zeme>" . h(VAVRYS_ADR_ZEME) . "</Zeme>\n"
        . "    </AdresaFakturacni>\n"
        . "    <Ico>" . h(VAVRYS_ICO) . "</Ico>\n"
        . "    <Dic>" . h(VAVRYS_DIC) . "</Dic>\n"
        . "    <Telefon>" . h(VAVRYS_TELEFON) . "</Telefon>\n"
        . "    <Email>" . h(VAVRYS_EMAIL) . "</Email>\n"
        . "  </Odberatel>\n"
        . "  <ObjednavkaPolozky>\n"
        . "    <ObjednavkaPolozka>\n"
        . "      <Id>\n"
        . "        <KatalogId>" . h($result['katalogId']) . "</KatalogId>\n"
        . "        <StrCislo>" . h($result['strCislo']) . "</StrCislo>\n"
        . "        <KarCislo>" . h($result['karCislo']) . "</KarCislo>\n"
        . "        <KarCisloId>" . h($result['karCisloId']) . "</KarCisloId>\n"
        . "      </Id>\n"
        . "      <IdX>" . h($result['idX']) . "</IdX>\n"
        . "      <IdY>" . h($result['idY']) . "</IdY>\n"
        . "      <Mnozstvi>{$pocetKs}</Mnozstvi>\n"
        . "      <Cena>{$cenaBezDph}</Cena>\n"
        . "    </ObjednavkaPolozka>\n"
        . "  </ObjednavkaPolozky>\n"
        . "</Objednavka>";

    if (empty($missingOdberatel)) {
        $_SESSION['vpo_token'] = bin2hex(random_bytes(16));
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Objednávka Vavrys – náhled i závazné odeslání</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root { --g1:#24d84a; --g2:#00b52a; --danger:#d93025; }
body { font-family: system-ui, sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; color:#1b1f23; }
.wrap { max-width:900px; margin:0 auto; }
.warn-banner { background:#fff3cd; border:1.5px solid #ffe08a; color:#7a5600; border-radius:12px; padding:12px 16px; text-align:center; font-weight:800; font-size:14px; margin-bottom:18px; }
.back-link { color:#666; font-size:12.5px; text-decoration:none; border:1px solid #e7e9ec; border-radius:999px; padding:6px 14px; display:inline-block; margin-bottom:14px; }
.card { background:#fff; border:1px solid #e7e9ec; border-radius:14px; padding:18px; margin-bottom:16px; }
input[type=text], input[type=number] { padding:9px 12px; border:1px solid #ccc; border-radius:8px; font-size:14px; }
button { padding:10px 18px; border:none; border-radius:999px; background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; font-weight:800; cursor:pointer; }
table { width:100%; border-collapse:collapse; font-size:13px; margin-top:10px; }
td, th { padding:6px 10px; border-bottom:1px solid #e7e9ec; text-align:left; }
th { color:#6b7280; font-size:11px; text-transform:uppercase; }
.detail-table th { width:180px; }
pre { background:#f7f8f9; padding:14px; border-radius:10px; overflow:auto; font-size:12.5px; }
.err { color:var(--danger); font-weight:700; }
</style>
</head>
<body>
<div class="wrap">
    <a class="back-link" href="index.php">&larr; Zpět na přehled</a>
    <h1 style="font-size:20px;">Objednávka u Vavrys</h1>

    <div class="warn-banner">⚠ Vyhledání a náhled XML nic neodesílá. Skutečné odeslání proběhne jen po kliknutí na červené tlačítko dole a potvrzení.</div>

    <?php if ($orderSubmitResult !== null): ?>
        <div class="card" style="border:2px solid <?php echo $orderSubmitResult['success'] ? '#1a7f37' : 'var(--danger)'; ?>;">
            <h2 style="font-size:16px;margin-top:0;"><?php echo $orderSubmitResult['success'] ? '✓ Objednávka byla u Vavrys založena' : '✗ Objednávka NEBYLA založena'; ?></h2>
            <?php if (isset($orderSubmitResult['code'])): ?>
                <p style="font-size:14px;"><strong>Kód odpovědi:</strong> <?php echo (int)$orderSubmitResult['code']; ?> — <?php echo h($orderSubmitResult['message']); ?></p>
            <?php endif; ?>
            <?php if (isset($orderSubmitResult['error'])): ?>
                <p class="err"><?php echo h($orderSubmitResult['error']); ?></p>
            <?php endif; ?>
            <?php if (!empty($orderSubmitResult['request'])): ?>
                <details style="margin-top:10px;">
                    <summary style="cursor:pointer;font-size:12px;color:#6b7280;">Zobrazit SOAP request/response (ladění)</summary>
                    <p style="font-size:11px;color:#6b7280;margin-bottom:2px;">Request:</p>
                    <pre><?php echo h($orderSubmitResult['request']); ?></pre>
                    <p style="font-size:11px;color:#6b7280;margin-bottom:2px;">Response:</p>
                    <pre><?php echo h($orderSubmitResult['response'] ?? ''); ?></pre>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2 style="font-size:15px;margin-top:0;">1) Načíst položky z reálné objednávky (naše DB)</h2>
        <form method="get">
            <label style="font-size:12px;font-weight:700;color:#6b7280;">Číslo objednávky</label><br>
            <input type="text" name="obj" value="<?php echo h($objNumber); ?>" style="width:220px;" placeholder="např. 1119107606">
            <button type="submit">Načíst položky</button>
        </form>

        <?php if ($objError !== ''): ?>
            <p class="err" style="margin-top:10px;"><?php echo h($objError); ?></p>
        <?php endif; ?>

        <?php if (!empty($objItems)): ?>
            <table style="margin-top:14px;">
                <thead><tr><th style="width:auto;">Kód</th><th style="width:auto;">EAN</th><th style="width:auto;">Produkt</th><th style="width:auto;">Varianta</th><th style="width:auto;">Ks</th><th style="width:auto;"></th></tr></thead>
                <tbody>
                <?php foreach ($objItems as $it): ?>
                    <?php $itEan = trim((string)($it['EAN'] ?? '')); ?>
                    <tr>
                        <td><?php echo h($it['product_number']); ?></td>
                        <td><?php echo $itEan !== '' ? h($itEan) : '<span class="err">chybí</span>'; ?></td>
                        <td><?php echo h($it['product_name']); ?></td>
                        <td><?php echo h($it['variant_description']); ?></td>
                        <td><?php echo (float)$it['count']; ?></td>
                        <td>
                            <?php
                            $linkParams = ['obj' => $objNumber, 'ks' => (int)($it['count'] ?: 1), 'velikost' => (string)($it['variant_description'] ?? '')];
                            if ($itEan !== '') { $linkParams['ean'] = $itEan; }
                            else { $linkParams['kod'] = (string)($it['product_number'] ?? ''); }
                            ?>
                            <a href="?<?php echo http_build_query($linkParams); ?>">Hledat u Vavrys →</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 style="font-size:15px;margin-top:0;">2) Nebo zadat ručně</h2>
        <form method="get">
            <input type="hidden" name="obj" value="<?php echo h($objNumber); ?>">
            <label style="font-size:12px;font-weight:700;color:#6b7280;">EAN produktu (pokud ho známe)</label><br>
            <input type="text" name="ean" value="<?php echo h($ean); ?>" style="width:220px;"><br><br>
            <label style="font-size:12px;font-weight:700;color:#6b7280;">— nebo — Kód produktu (objednací číslo, bez EAN)</label><br>
            <input type="text" name="kod" value="<?php echo h($kod); ?>" style="width:220px;" placeholder="např. 1914792-376000"><br><br>
            <label style="font-size:12px;font-weight:700;color:#6b7280;">Velikost (jen pokud hledáš podle kódu)</label><br>
            <input type="text" name="velikost" value="<?php echo h($velikost); ?>" style="width:220px;" placeholder="např. Velikost S"><br><br>
            <label style="font-size:12px;font-weight:700;color:#6b7280;">Počet kusů k objednání</label><br>
            <input type="number" name="ks" value="<?php echo (int)$pocetKs; ?>" min="1" style="width:100px;"><br><br>
            <button type="submit">Vyhledat v katalogu Vavrys</button>
        </form>
    </div>

    <?php if (!empty($codeChoices)): ?>
        <div class="card">
            <h2 style="font-size:15px;margin-top:0;">Nalezeno víc velikostí – vyber správnou</h2>
            <p style="font-size:12.5px;color:#6b7280;">Kód „<?php echo h($kod); ?>“ odpovídá barvě, ale automaticky se nepodařilo spárovat přesnou velikost „<?php echo h($velikost); ?>“. Vyber ji ručně:</p>
            <table>
                <thead><tr><th>Velikost</th><th>EAN</th><th>Skladem</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($codeChoices as $c): ?>
                    <tr>
                        <td><?php echo h($c['ozn_velikost']); ?></td>
                        <td><?php echo h($c['ean']); ?></td>
                        <td><?php echo h($c['mnozstviSklad']); ?> ks</td>
                        <td><a href="?obj=<?php echo urlencode($objNumber); ?>&ean=<?php echo urlencode($c['ean']); ?>&ks=<?php echo (int)$pocetKs; ?>">Vybrat →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($searchError !== ''): ?>
        <div class="card"><span class="err"><?php echo h($searchError); ?></span></div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <div class="card">
            <h2 style="font-size:15px;margin-top:0;">✓ Produkt nalezen v katalogu Vavrys</h2>
            <table class="detail-table">
                <tr><th>Název</th><td><?php echo h($result['nazev']); ?></td></tr>
                <tr><th>Barva / Velikost</th><td><?php echo h($result['ozn_barva']); ?> / <?php echo h($result['ozn_velikost']); ?></td></tr>
                <tr><th>Náš kód (PozY2)</th><td><?php echo h($result['pozY2']); ?></td></tr>
                <tr><th>EAN</th><td><?php echo h($result['ean']); ?></td></tr>
                <tr><th>Skladem u Vavrys</th><td><?php echo h($result['mnozstviSklad']); ?> ks</td></tr>
                <tr><th>Cena bez DPH</th><td><?php echo h($result['cena']); ?> Kč</td></tr>
                <tr><th>KatalogId</th><td><?php echo h($result['katalogId']); ?></td></tr>
                <tr><th>StrCislo</th><td><?php echo h($result['strCislo']); ?></td></tr>
                <tr><th>KarCislo</th><td><?php echo h($result['karCislo']); ?></td></tr>
                <tr><th>KarCisloId</th><td><?php echo h($result['karCisloId']); ?></td></tr>
                <tr><th>IdX / IdY</th><td><?php echo h($result['idX']); ?> / <?php echo h($result['idY']); ?></td></tr>
            </table>
        </div>

        <div class="card">
            <h2 style="font-size:15px;margin-top:0;">Náhled XML, které by se poslalo (NEODESÍLÁ SE)</h2>
            <?php if (!empty($missingOdberatel)): ?>
                <p class="err" style="margin-top:0;">⚠ Chybí vyplnit údaje odběratele v hlavičce souboru (konstanty VAVRYS_*): <?php echo h(implode(', ', $missingOdberatel)); ?>. Bez nich Vavrys objednávku odmítne.</p>
            <?php endif; ?>
            <pre><?php echo h($previewXml); ?></pre>
            <p style="font-size:11.5px;color:#6b7280;">
                Pozn.: struktura ověřena proti oficiální dokumentaci Vavrys (SOAP spojení otestováno
                21.8.2026 – ZpusobyDopravy a ZpusobyPlatby vrátily OK). Cena v objednávce = cena bez
                DPH přímo z jejich katalogu; před ostrým odesláním ještě zvážit, zda to Vavrys
                validuje přesně proti jejich aktuální ceně.
            </p>
        </div>

        <?php if (empty($missingOdberatel) && isset($_SESSION['vpo_token'])): ?>
        <div class="card" style="border:2px solid var(--danger);">
            <h2 style="font-size:15px;margin-top:0;color:var(--danger);">⚠ Závazné odeslání objednávky Vavrys</h2>
            <p style="font-size:13px;">Tímto tlačítkem se objednávka <strong>skutečně odešle</strong> na Vavrys server a založí se v jejich systému. Nejde vzít zpět přes API (jen ručně, telefonicky/e-mailem s Vavrys).</p>
            <form method="post" onsubmit="return confirm('Opravdu chcete ZÁVAZNĚ odeslat tuto objednávku Vavrys? Tuto akci nelze vzít zpět.');">
                <input type="hidden" name="token" value="<?php echo h($_SESSION['vpo_token']); ?>">
                <input type="hidden" name="p_cislo" value="<?php echo h($vlastniCislo); ?>">
                <input type="hidden" name="p_datum" value="<?php echo h($datumObj); ?>">
                <input type="hidden" name="p_katalogId" value="<?php echo h($result['katalogId']); ?>">
                <input type="hidden" name="p_strCislo" value="<?php echo h($result['strCislo']); ?>">
                <input type="hidden" name="p_karCislo" value="<?php echo h($result['karCislo']); ?>">
                <input type="hidden" name="p_karCisloId" value="<?php echo h($result['karCisloId']); ?>">
                <input type="hidden" name="p_idX" value="<?php echo h($result['idX']); ?>">
                <input type="hidden" name="p_idY" value="<?php echo h($result['idY']); ?>">
                <input type="hidden" name="p_mnozstvi" value="<?php echo (int)$pocetKs; ?>">
                <input type="hidden" name="p_cena" value="<?php echo h($cenaBezDph); ?>">
                <label style="display:block;font-size:13px;margin:12px 0;cursor:pointer;">
                    <input type="checkbox" name="confirm_final" value="1" required>
                    Potvrzuji, že jsem zkontroloval/a náhled výše a chci tuto objednávku <strong>ZÁVAZNĚ</strong> odeslat Vavrys.
                </label>
                <button type="submit" name="objednat_naostro" value="1" style="background:linear-gradient(135deg,#ff5b4d,var(--danger));">OBJEDNAT NAOSTRO U VAVRYS</button>
            </form>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
