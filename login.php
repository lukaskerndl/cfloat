<?php
// Sdílený login (stejná session i stejné heslo jako v index.php) –
// viz _auth_guard.php a secrets/admin_login.php.
require_once __DIR__ . '/_auth_guard.php';

// když už jsem přihlášený, jdu rovnou na index
if ($loggedIn) {
    header('Location: index.php');
    exit;
}

$error = $loginError;
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>cFloat – přihlášení</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: radial-gradient(circle at top left, rgba(79,124,255,.18), transparent 28%), linear-gradient(180deg, #edf3ff 0%, #f4f7fb 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .card {
            background: #ffffff;
            padding: 24px 28px;
            border-radius: 26px;
            box-shadow: 0 22px 50px rgba(24, 44, 88, 0.10);
            max-width: 400px;
            width: 100%;
        }
        .logo {
            text-align: center;
            margin-bottom: 10px;
        }
        .logo img {
            max-width: 160px;
            height: auto;
        }
        h1 {
            margin-top: 0;
            margin-bottom: 16px;
            font-size: 28px;
            text-align: center;
            color: #203053;
        }
        label {
            display: block;
            margin-bottom: 4px;
            font-size: 14px;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 8px 10px;
            border-radius: 14px;
            border: 1px solid rgba(122, 144, 185, 0.22);
            margin-bottom: 12px;
            font-size: 14px;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: none;
            background: linear-gradient(135deg, #4f9dff 0%, #2f74dd 100%);
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover {
            background: linear-gradient(135deg, #3c8ef6 0%, #245fc8 100%);
        }
        .error {
            color: #c62828;
            font-size: 13px;
            margin-bottom: 10px;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">
        <!-- volitelné logo -->
        <!-- <img src="cstore-logo.png" alt="C-Store.cz"> -->
    </div>
    <h1>cFloat</h1>
    <div style="text-align:center;color:#6d7b95;font-size:14px;margin:-4px 0 18px;">Moderní administrace C-Store.cz</div>
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form method="post" action="login.php">
        <input type="hidden" name="action" value="login">
        <label for="username">Uživatelské jméno</label>
        <input
            type="text"
            id="username"
            name="username"
            autocomplete="username"
            autocapitalize="off"
            autocorrect="off"
            spellcheck="false"
        >

        <label for="password">Heslo</label>
        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            autocapitalize="off"
            autocorrect="off"
            spellcheck="false"
        >

        <button type="submit">Přihlásit se</button>
    </form>
</div>

<script>
document.getElementById('username').focus();
</script>
</body>
</html>
