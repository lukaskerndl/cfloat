<?php
// nastaveni.php – stránka Nastavení (sloučení CSV variant do AllVarianty.csv)

require_once __DIR__ . '/_auth_guard.php';

// ---------- AJAX: sloučení CSV souborů ve složce CStore/Varianty ----------
const VARIANTS_DIR = __DIR__ . '/CStore/Varianty';

if ($loggedIn && isset($_GET['ajax']) && $_GET['ajax'] === 'merge_variants') {
    header('Content-Type: application/json; charset=utf-8');

    $dir = VARIANTS_DIR;

    if (!is_dir($dir)) {
        echo json_encode([
            'ok'      => false,
            'message' => 'Složka s CSV soubory neexistuje: ' . $dir,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $files = glob($dir . '/*.csv');
    if ($files === false) {
        echo json_encode([
            'ok'      => false,
            'message' => 'Nelze načíst seznam CSV souborů.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $target = $dir . '/AllVarianty.csv';
    $out = fopen($target, 'w');
    if ($out === false) {
        echo json_encode([
            'ok'      => false,
            'message' => 'Nelze otevřít soubor AllVarianty.csv pro zápis.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $headerWritten = false;
    $totalRows     = 0;

    foreach ($files as $file) {
        // přeskočit cílový soubor, pokud už existuje
        if (basename($file) === 'AllVarianty.csv') {
            continue;
        }

        $h = fopen($file, 'r');
        if ($h === false) {
            continue;
        }

        $rowIndex = 0;
        while (($row = fgetcsv($h, 0, ';')) !== false) {
            if ($rowIndex === 0) {
                // hlavička – zapíšeme jen jednou
                if (!$headerWritten) {
                    fputcsv($out, $row, ';');
                    $headerWritten = true;
                }
            } else {
                fputcsv($out, $row, ';');
                $totalRows++;
            }
            $rowIndex++;
        }
        fclose($h);
    }

    fclose($out);

    echo json_encode([
        'ok'      => true,
        'message' => 'Varianty byly spojeny. Počet řádků (bez hlavičky): ' . $totalRows,
        'rows'    => $totalRows,
        'file'    => $target,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- HTML VÝSTUP ----------
?><!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <title>Nastavení – CFloat</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f5f5;
        }
        .page {
            max-width: 960px;
            margin: 32px auto;
            padding: 0 16px;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 24px 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        h1 {
            margin-top: 0;
            font-size: 26px;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            margin-bottom: 12px;
            font-size: 14px;
            text-decoration: none;
            color: #0b9150;
        }
        .back-link span {
            text-decoration: underline;
            margin-left: 4px;
        }
        .btn-full {
            display: inline-block;
            width: 100%;
            padding: 10px 14px;
            border-radius: 999px;
            border: none;
            background: #0b9150;
            color: #fff;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
        }
        .btn-full[disabled] {
            opacity: .6;
            cursor: default;
        }
        .stats-label {
            font-size: 13px;
            color: #555;
            margin-bottom: 4px;
        }
        .progress-bar {
            width: 100%;
            background: #eee;
            border-radius: 999px;
            overflow: hidden;
            height: 18px;
        }
        .progress-bar-inner {
            width: 0%;
            height: 100%;
            background: #0b9150;
            transition: width .3s ease;
        }
        .progress-text {
            margin-top: 4px;
            font-size: 13px;
        }
        .result {
            margin-top: 10px;
            font-size: 13px;
        }
        .logout-wrap {
            margin-top: 24px;
            text-align: center;
        }
        .btn-logout {
            border: none;
            background: #111;
            color: #fff;
            border-radius: 999px;
            padding: 8px 18px;
            cursor: pointer;
            font-size: 14px;
        }
        .login-card {
            max-width: 360px;
            margin: 48px auto;
        }
        .login-card h1 {
            font-size: 22px;
        }
        .login-field {
            margin-bottom: 10px;
        }
        .login-field label {
            font-size: 13px;
            display: block;
            margin-bottom: 4px;
        }
        .login-field input {
            width: 100%;
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid #ccc;
            font-size: 14px;
        }
        .login-error {
            color: #c00;
            font-size: 13px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>

<div class="page">
<?php if (!$loggedIn): ?>

    <div class="card login-card">
        <h1>Přihlášení</h1>

        <?php if ($loginError !== ''): ?>
            <div class="login-error"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" action="nastaveni.php">
            <input type="hidden" name="action" value="login">
            <div class="login-field">
                <label for="username">Uživatel</label>
                <input type="text" id="username" name="username" autocomplete="username">
            </div>
            <div class="login-field">
                <label for="password">Heslo</label>
                <input type="password" id="password" name="password" autocomplete="current-password">
            </div>
            <button type="submit" class="btn-full">Přihlásit se</button>
        </form>
    </div>

<?php else: ?>

    <div class="card">
        <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
        <h1>Nastavení</h1>

        <p>Sloučení všech CSV souborů ve složce <code>CStore/Varianty</code> do souboru <strong>AllVarianty.csv</strong>.</p>

        <div id="merge-box">
            <button id="btn-merge" type="button" class="btn-full">Sloučení souborů</button>

            <div id="merge-progress-wrap" style="margin-top:10px; display:none;">
                <div class="stats-label">Průběh sloučení:</div>
                <div class="progress-bar">
                    <div id="merge-progress-bar" class="progress-bar-inner"></div>
                </div>
                <div id="merge-progress-text" class="progress-text">0 %</div>
            </div>

            <div id="merge-result" class="result"></div>

            <button id="btn-merge-ok" type="button" class="btn-full" style="margin-top:16px; display:none;">OK</button>
        </div>

        <div class="logout-wrap">
            <form method="get" action="nastaveni.php">
                <input type="hidden" name="logout" value="1">
                <button type="submit" class="btn-logout">Odhlásit se</button>
            </form>
        </div>
    </div>

<?php endif; ?>
</div>

<script>
(function() {
    var btnMerge      = document.getElementById('btn-merge');
    var btnMergeOk    = document.getElementById('btn-merge-ok');
    var progressWrap  = document.getElementById('merge-progress-wrap');
    var progressBar   = document.getElementById('merge-progress-bar');
    var progressText  = document.getElementById('merge-progress-text');
    var resultEl      = document.getElementById('merge-result');

    if (!btnMerge) return;

    var timer = null;
    var current = 0;

    function startFakeProgress() {
        current = 0;
        progressBar.style.width = '0%';
        progressText.textContent = '0 %';
        progressWrap.style.display = 'block';
        resultEl.textContent = '';
        btnMerge.disabled = true;
        btnMergeOk.style.display = 'none';

        timer = setInterval(function() {
            if (current >= 90) return;
            current += Math.random() * 8;
            if (current > 90) current = 90;
            progressBar.style.width = Math.round(current) + '%';
            progressText.textContent = Math.round(current) + ' %';
        }, 400);
    }

    function finishProgress() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
        current = 100;
        progressBar.style.width = '100%';
        progressText.textContent = '100 %';
        btnMerge.disabled = false;
    }

    btnMerge.addEventListener('click', function() {
        startFakeProgress();

        fetch('nastaveni.php?ajax=merge_variants', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                finishProgress();
                if (data && data.ok) {
                    resultEl.textContent = data.message || 'Varianty byly spojeny.';
                } else {
                    resultEl.textContent = (data && data.message) ? data.message : 'Sloučení se nezdařilo.';
                }
                btnMergeOk.style.display = 'block';
            })
            .catch(function(err) {
                finishProgress();
                resultEl.textContent = 'Chyba při komunikaci se serverem.';
                btnMergeOk.style.display = 'block';
            });
    });

    btnMergeOk.addEventListener('click', function() {
        progressWrap.style.display = 'none';
        progressBar.style.width = '0%';
        progressText.textContent = '0 %';
        resultEl.textContent = '';
        btnMergeOk.style.display = 'none';
    });
})();
</script>

</body>
</html>
