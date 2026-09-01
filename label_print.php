<?php
// label_print.php
// Wrapper page that loads label.php (PDF) and triggers print automatically.
// For silent printing, run Chrome with --kiosk-printing and set Zebra as default printer in Windows.

@ini_set('display_errors', '0');
@error_reporting(0);

$params = $_GET;
$back = isset($params['back']) ? (string)$params['back'] : 'index.php?view=print';

// remove internal params (not forwarded to label.php)
unset($params['back'], $params['autoprint'], $params['t']);

// Build PDF URL to label.php with the same params
$query = http_build_query($params);
$pdfUrl = 'label.php' . ($query ? ('?' . $query) : '');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?><!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tisk štítku</title>
  <style>
    html, body { height: 100%; margin: 0; font-family: Arial, sans-serif; }
    .topbar { padding: 10px 12px; background:#f5f5f5; border-bottom:1px solid #ddd; display:flex; gap:10px; align-items:center; }
    .btn { padding:8px 12px; border:1px solid #bbb; background:#fff; cursor:pointer; border-radius:6px; }
    .btn.primary { border-color:#666; }
    .hint { color:#444; font-size: 13px; }
    #pdfFrame { width: 100%; height: calc(100% - 52px); border:0; display:block; }
  </style>
</head>
<body>
  <div class="topbar">
    <button class="btn primary" id="btnPrint" type="button">Vytisknout</button>
    <button class="btn" id="btnBack" type="button">Zpět</button>
    <a class="btn" href="<?php echo htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Otevřít PDF</a>
    <span class="hint">Automatický tisk proběhne po načtení PDF.</span>
  </div>

  <iframe id="pdfFrame" src="<?php echo htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8'); ?>"></iframe>

  <script>
    (function(){
      const backUrl = <?php echo json_encode($back); ?>;
      const f = document.getElementById('pdfFrame');
      const btnPrint = document.getElementById('btnPrint');
      const btnBack = document.getElementById('btnBack');

      let printed = false;

      function goBack() {
        try { window.location.href = backUrl; } catch(e) {}
      }

      function doPrint() {
        if (printed) return;
        printed = true;

        // Try to print the iframe content (PDF). If blocked, fallback to printing the whole page.
        try {
          if (f && f.contentWindow) {
            f.contentWindow.focus();
            f.contentWindow.print();
          } else {
            window.print();
          }
        } catch (e) {
          try { window.print(); } catch (_) {}
        }

        // Return back after a short delay (print job is already queued)
        setTimeout(goBack, 1200);
      }

      btnPrint.addEventListener('click', function(){ printed = false; doPrint(); });
      btnBack.addEventListener('click', goBack);

      // Auto print: after iframe loads (with a small delay to let PDF render)
      if (f) {
        f.addEventListener('load', function(){
          setTimeout(doPrint, 450);
        });
      }

      // Safety fallback if load event doesn't fire
      setTimeout(function(){
        if (!printed) doPrint();
      }, 3500);
    })();
  </script>
</body>
</html>
