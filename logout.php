<?php
// Pokus o "odhlášení" z HTTP Basic Auth – pošleme nový 401
header('HTTP/1.1 401 Unauthorized');
header('WWW-Authenticate: Basic realm="cFloat-logout"');
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Odhlášení</title>
</head>
<body>
<p>Byl jste odhlášen. Zavřete okno prohlížeče, nebo se přihlaste znovu.</p>
</body>
</html>
