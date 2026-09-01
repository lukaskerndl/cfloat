<?php
// ===========================================================================
//  NOVÝ CFLOAT – vývojová verze hlavní stránky
//  Tato stránka je samostatný scaffold, na kterém se bude dále vyvíjet nová
//  podoba administrace. V budoucnu se plánuje přesunout tuto stránku na
//  místo hlavní (index.php). Zatím jen odkazuje na stejné moduly jako
//  původní index.php (žádná funkčnost ani data nebyly odebrány).
// ===========================================================================

session_start();

// Vyžadujeme přihlášení ve stejné session jako hlavní aplikace
if (empty($_SESSION['logged_in'])) {
    header('Location: ../index.php');
    exit;
}

// Načtení configu / $pdo (kvůli počtu nových VB požadavků na odznaku dlaždice)
$cfgCandidates = [__DIR__ . '/../config.php', __DIR__ . '/../../config.php'];
$pdo = null;
foreach ($cfgCandidates as $p) {
    if (is_file($p)) {
        require $p;
        break;
    }
}

$vbNewCount = 0;
if ($pdo) {
    try {
        $vbNewCount = (int)$pdo->query("SELECT COUNT(*) FROM vb_requests WHERE status = 'NEW'")->fetchColumn();
    } catch (Throwable $e) {
        $vbNewCount = 0;
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Nový Cfloat – vývoj</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root { --g1:#24d84a; --g2:#00b52a; }
        * { box-sizing:border-box; }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:#fff; margin:0; min-height:100vh;
            display:flex; align-items:flex-start; justify-content:center;
        }
        .wrap { width:100%; max-width:1000px; padding:24px 16px 40px; }
        .logo-top { text-align:center; margin-bottom:8px; }
        .logo-top img { max-width:180px; height:auto; display:inline-block; }
        .logo-top a { text-decoration:none; }

        .page-title {
            text-align:center; font-size:20px; font-weight:800; color:var(--g2);
            margin: 4px 0 2px;
        }
        .page-subtitle {
            text-align:center; font-size:12px; color:#666; margin-bottom:22px;
        }

        /* DLAŽDICE JAKO KRUHY */
        .tiles-round {
            display:flex; flex-wrap:wrap; gap:18px;
            justify-content:center; margin-top:8px;
        }
        .tile-round {
            width:150px; height:150px; border-radius:50%;
            background:#ffffff;
            border:2px solid var(--g2);
            box-shadow:0 2px 8px rgba(0,0,0,0.08);
            display:flex; flex-direction:column; align-items:center; justify-content:center;
            text-decoration:none; color:var(--g2);
            text-align:center; padding:14px;
            position:relative;
            transition: transform .12s ease, box-shadow .12s ease, background-color .12s ease, color .12s ease;
        }
        .tile-round:hover, .tile-round:focus, .tile-round:active {
            background:linear-gradient(135deg,var(--g1),var(--g2));
            color:#fff !important;
            box-shadow:0 8px 22px rgba(0,0,0,0.20);
            transform:translateY(-2px);
            outline:none;
        }
        .tile-round:hover .tr-title, .tile-round:hover .tr-text,
        .tile-round:focus .tr-title, .tile-round:focus .tr-text,
        .tile-round:active .tr-title, .tile-round:active .tr-text {
            color:#fff;
        }
        .tile-round.tr-orange { background:#fff0d9; }
        .tile-round.tr-orange:hover { background:#ffe2bb; color:#111 !important; }
        .tile-round.tr-orange:hover .tr-title, .tile-round.tr-orange:hover .tr-text { color:#111; }

        .tile-round.tr-red { background:#ffe3e3; }
        .tile-round.tr-red:hover { background:#ffd0d0; color:#111 !important; }
        .tile-round.tr-red:hover .tr-title, .tile-round.tr-red:hover .tr-text { color:#111; }

        .tr-title {
            font-size:13px; font-weight:800; letter-spacing:0.02em;
            margin-bottom:3px; color:inherit;
        }
        .tr-text {
            font-size:9.5px; font-weight:400; color:inherit;
            display:-webkit-box; -webkit-line-clamp:4; -webkit-box-orient:vertical;
            overflow:hidden; line-height:1.25;
        }
        .tr-badge {
            position:absolute; top:8px; right:14px;
            background:#d93025; color:#fff;
            min-width:20px; height:20px; padding:0 5px;
            border-radius:999px; display:flex; align-items:center; justify-content:center;
            font-size:11px; font-weight:900; line-height:1;
            box-shadow:0 2px 6px rgba(0,0,0,0.18);
        }

        @media (max-width:600px) {
            .tile-round { width:120px; height:120px; padding:10px; }
            .tr-title { font-size:11.5px; }
            .tr-text { font-size:8.5px; -webkit-line-clamp:3; }
        }

        .back-wrap { text-align:center; margin-top:32px; }
        .back-link {
            display:inline-block; color:#666; font-size:12px; text-decoration:none;
            border:1px solid #ccc; border-radius:999px; padding:8px 18px;
        }
        .back-link:hover { background:#f2f2f2; }

        .logout-wrap { margin-top:14px; text-align:center; }
        .btn-logout {
            background:#000; color:#fff; border:none; border-radius:999px;
            padding:10px 22px; font-size:13px; font-weight:700; cursor:pointer;
        }
        .btn-logout:hover { background:#222; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="logo-top">
        <a href="../index.php">
            <img src="../logo-1.png" alt="C-Store.cz">
        </a>
    </div>

    <div class="page-title">Nový Cfloat</div>
    <div class="page-subtitle">Vývojová verze – stejná funkčnost jako hlavní administrace, jen v nové podobě.</div>

    <div class="tiles-round">

        <a class="tile-round tr-orange" href="https://1388739759.s1.eshop-rychle.cz/admin/script.php?svol=2&vol=eshop4">
            <div class="tr-title">C-Store.cz<br>Objednávky</div>
            <div class="tr-text">Administrace e-shopu – Objednávky</div>
        </a>

        <a class="tile-round tr-orange" href="https://1388739759.s1.eshop-rychle.cz/admin/script.php?svol=4&vol=orders_gains">
            <div class="tr-title">C-Store.cz<br>Statistika</div>
            <div class="tr-text">Administrace e-shopu – Statistika</div>
        </a>

        <a class="tile-round" href="tisk-stitku.php">
            <div class="tr-title">TISK ŠTÍTKŮ</div>
            <div class="tr-text">Načíst EAN / číslo objednávky. Automaticky se vytiskne na tiskárnu ZD (Zebra), pokud je připojená.</div>
        </a>

        <a class="tile-round" href="zasilky.php">
            <div class="tr-title">ZÁSILKY</div>
            <div class="tr-text">Seznam všech vytištěných zásilek – vyhledávání podle jména, trackingu, adresy. Klikatelné sledování u dopravce.</div>
        </a>

        <a class="tile-round" href="../index.php?view=stats">
            <div class="tr-title">STATISTIKA</div>
            <div class="tr-text">Statistika prodejů zboží dle značek</div>
        </a>

        <a class="tile-round" href="objednavky.php">
            <div class="tr-title">OBJEDNÁVKY</div>
            <div class="tr-text">Přehled objednávek s detailem položek a doplněním nákupních cen z XML feedů.</div>
        </a>

        <a class="tile-round" href="nove-objednavky.php">
            <div class="tr-title">NOVÉ OBJEDNÁVKY</div>
            <div class="tr-text">Objednávky z nového API - historie od 1.1.2025 + okamžité změny přes webhook.</div>
        </a>

        <a class="tile-round" href="doplnit-nakupni-ceny.php">
            <div class="tr-title">Nákupní ceny<br>z XML feedů</div>
            <div class="tr-text">Nová logika: doplnění nákupní ceny (s DPH) výhradně z XML feedů dodavatelů, se zálohováním historie.</div>
        </a>

        <a class="tile-round" href="../index.php?view=customers">
            <div class="tr-title">ZÁKAZNÍCI</div>
            <div class="tr-text">Top zákazníci podle počtu objednávek a útraty.</div>
        </a>

        <a class="tile-round" href="../index.php?view=service">
            <div class="tr-title">Servis</div>
            <div class="tr-text">Přehled servisních zakázek (C-servis).</div>
        </a>

        <a class="tile-round" href="../nastaveni.php">
            <div class="tr-title">Nastavení</div>
            <div class="tr-text">Sloučení CSV variant do AllVarianty.csv</div>
        </a>

        <a class="tile-round" href="dodavatele/index.php">
            <div class="tr-title">XML feed<br>Dodavatelé</div>
            <div class="tr-text">Nahrání a zpracování katalogových souborů (Vavrys, Silvini, ALÉ, ISADORE) – opravená verze bez chyby ve sloupci EAN.</div>
        </a>

        <a class="tile-round" href="schindler/index.php">
            <div class="tr-title">SCHINDLER<br>Nové produkty</div>
            <div class="tr-text">Import nových produktů z XML feedu Schindler – výběr podle výrobce, kategorie a produktu, zařazení do eshopové kategorie.</div>
        </a>

        <a class="tile-round tr-red" href="test-eshop/index.php">
            <div class="tr-title">TEST<br>Eshop-rychle API</div>
            <div class="tr-text">Testovací modul napojený na .dev testovací e-shop – čtení objednávek, testování zápisu poznámky/stavu/trackingu.</div>
        </a>

        <a class="tile-round tr-red" href="vavrys-objednavka-nahled.php">
            <div class="tr-title">Vavrys<br>náhled objednávky</div>
            <div class="tr-text">Vyhledání produktu v katalogu a náhled dat pro NovaObjednavka – nic se neodesílá.</div>
        </a>

        <a class="tile-round tr-red" href="mesicni-prehled.php">
            <div class="tr-title">MĚSÍČNÍ<br>PŘEHLED</div>
            <div class="tr-text">Zisk ze zboží i servisu, náklady a čistý zisk – měsíčně i aktuálně k dnešnímu dni.</div>
        </a>

    </div>

    <div class="back-wrap">
        <a class="back-link" href="../index.php">&larr; Zpět na původní administraci</a>
    </div>

    <div class="logout-wrap">
        <form method="get" action="../index.php">
            <input type="hidden" name="logout" value="1">
            <button type="submit" class="btn-logout">Odhlásit se</button>
        </form>
    </div>
</div>
</body>
</html>
