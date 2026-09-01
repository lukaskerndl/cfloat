<?php
/**
 * _auth_guard.php
 *
 * Sdílený, bezpečný login pro celou administraci cFloat.
 * Nahrazuje dřívější kopie loginu a hesla natvrdo v jednotlivých souborech
 * (index.php, nastaveni.php, xmlfeedy.php, index_settings_final.php,
 * index_ean_from_order_items.php, index_objednavky_nakupni_cena.php
 * a jejich kopie ve vraceni-cstore/ a vb-vymenny-balik/).
 *
 * Použití – na začátek chráněného souboru místo starého loginového bloku:
 *   require_once __DIR__ . '/_auth_guard.php';
 *   // (v podsložkách vraceni-cstore/ a vb-vymenny-balik/:)
 *   require_once dirname(__DIR__) . '/_auth_guard.php';
 *
 * Po require jsou k dispozici stejné proměnné jako dřív:
 *   $loggedIn   (bool)
 *   $loginError (string)
 *
 * Co navíc oproti původnímu řešení:
 *  - heslo už není v souboru čitelné natvrdo (viz secrets/admin_login.php,
 *    heslo se automaticky převede na bcrypt hash)
 *  - ochrana proti hrubé síle: max 5 neúspěšných pokusů / 15 minut na IP adresu
 *  - bezpečnější session: HttpOnly, Secure (na HTTPS), SameSite=Lax
 *  - regenerace ID session po úspěšném přihlášení (ochrana proti session fixation)
 */

if (!defined('CFLOAT_AUTH_GUARD_LOADED')) {
    define('CFLOAT_AUTH_GUARD_LOADED', true);

    // ---------- Bezpečné nastavení session (musí proběhnout PŘED session_start) ----------
    if (session_status() === PHP_SESSION_NONE) {
        $__cfloatSecure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443)
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

        // Server nesmí přijmout ID session, které sám nevydal (session fixation).
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $__cfloatSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        // Pozn.: záměrně NEmění se session_name() – řada dalších souborů
        // v systému (cfloat-new/index.php a další) volá obyčejné
        // session_start() a čeká výchozí název cookie. Vlastní název by
        // je odpojil od hlavního přihlášení (zjištěno v provozu 27.8.2026).
        session_start();
    }

    // ---------- Automatické odhlášení po nečinnosti / po 12 hodinách ----------
    if (!empty($_SESSION['logged_in'])) {
        $__idleLimit  = 8 * 3600;   // 8 hodin nečinnosti
        $__totalLimit = 12 * 3600;  // 12 hodin celkem, i při aktivitě

        $__now = time();
        $__last = (int)($_SESSION['last_activity'] ?? $__now);
        $__start = (int)($_SESSION['login_time'] ?? $__now);

        if (($__now - $__last) > $__idleLimit || ($__now - $__start) > $__totalLimit) {
            $_SESSION = [];
            @session_destroy();
            @session_start();
        } else {
            $_SESSION['last_activity'] = $__now;
        }
    }

    $loginError = '';

    // ---------- Načtení přihlašovacích údajů ----------
    $__cfloatAuthFile = __DIR__ . '/secrets/admin_login.php';
    $__cfloatAuthData = is_file($__cfloatAuthFile) ? (include $__cfloatAuthFile) : [];
    if (!is_array($__cfloatAuthData)) {
        $__cfloatAuthData = [];
    }

    /**
     * Přepíše secrets/admin_login.php s novými daty (použito pro automatický
     * přechod z čitelného hesla na bcrypt hash).
     */
    function cfloat_auth_save_login_data(string $file, array $data): void
    {
        $export = "<?php\n"
            . "/**\n * secrets/admin_login.php – automaticky aktualizováno _auth_guard.php.\n"
            . " * Hesla jsou uložena jako bezpečné hashe (password_hash), ne čitelný text.\n */\n"
            . 'return ' . var_export($data, true) . ";\n";
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $export, LOCK_EX) !== false) {
            @rename($tmp, $file);
            @chmod($file, 0600);
        }
    }

    /**
     * Ověří heslo proti hashi; pokud hash ještě neexistuje, ověří proti
     * dočasnému čitelnému heslu (bootstrap) a rovnou ho zahashuje a uloží.
     */
    function cfloat_auth_verify_and_migrate(
        array &$authData,
        string $hashKey,
        string $bootstrapKey,
        string $plainInput,
        string $authFile
    ): bool {
        $hash = (string)($authData[$hashKey] ?? '');

        if ($hash !== '') {
            if (!password_verify($plainInput, $hash)) {
                return false;
            }
            // Heslo sedí. Pokud byl hash vytvořen slabším nastavením,
            // tiše ho přepočítáme na současný standard.
            if (password_needs_rehash($hash, PASSWORD_DEFAULT, ['cost' => 12])) {
                $authData[$hashKey] = password_hash($plainInput, PASSWORD_DEFAULT, ['cost' => 12]);
                cfloat_auth_save_login_data($authFile, $authData);
            }
            return true;
        }

        // Hash zatím neexistuje – ověř proti dočasnému čitelnému heslu.
        $bootstrap = (string)($authData[$bootstrapKey] ?? '');
        if ($bootstrap === '' || !hash_equals($bootstrap, $plainInput)) {
            return false;
        }

        // Správné heslo poprvé po nasazení -> vytvoř bcrypt hash a ulož,
        // čitelné heslo ze souboru zmiz.
        $authData[$hashKey] = password_hash($plainInput, PASSWORD_DEFAULT, ['cost' => 12]);
        $authData[$bootstrapKey] = null;
        cfloat_auth_save_login_data($authFile, $authData);

        return true;
    }

    // ---------- Ochrana proti hrubé síle (max 5 pokusů / 15 minut na IP) ----------
    function cfloat_auth_client_ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    function cfloat_auth_attempts_file(): string
    {
        return __DIR__ . '/secrets/login_attempts.json';
    }

    function cfloat_auth_is_locked(string $ip): bool
    {
        $file = cfloat_auth_attempts_file();
        if (!is_file($file)) {
            return false;
        }
        $fh = @fopen($file, 'r');
        if (!$fh) {
            return false;
        }
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        $data = json_decode((string)$raw, true);
        if (!is_array($data) || !isset($data[$ip])) {
            return false;
        }

        $windowStart = time() - 15 * 60;
        if ((int)($data[$ip]['first'] ?? 0) < $windowStart) {
            return false; // okno vypršelo
        }
        return (int)($data[$ip]['count'] ?? 0) >= 5;
    }

    function cfloat_auth_seconds_to_unlock(string $ip): int
    {
        $file = cfloat_auth_attempts_file();
        if (!is_file($file)) {
            return 0;
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || !isset($data[$ip]['first'])) {
            return 0;
        }
        $unlockAt = (int)$data[$ip]['first'] + 15 * 60;
        return max(0, $unlockAt - time());
    }

    function cfloat_auth_register_failed_attempt(string $ip): void
    {
        $file = cfloat_auth_attempts_file();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $fh = @fopen($file, 'c+');
        if (!$fh) {
            return;
        }
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            $data = [];
        }

        $now = time();
        $windowStart = $now - 15 * 60;
        foreach ($data as $k => $v) {
            if ((int)($v['first'] ?? 0) < $windowStart) {
                unset($data[$k]);
            }
        }

        if (!isset($data[$ip]) || (int)($data[$ip]['first'] ?? 0) < $windowStart) {
            $data[$ip] = ['count' => 0, 'first' => $now];
        }
        $data[$ip]['count'] = (int)$data[$ip]['count'] + 1;

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        @chmod($file, 0600);
    }

    function cfloat_auth_clear_attempts(string $ip): void
    {
        $file = cfloat_auth_attempts_file();
        if (!is_file($file)) {
            return;
        }
        $fh = @fopen($file, 'c+');
        if (!$fh) {
            return;
        }
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            $data = [];
        }
        unset($data[$ip]);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    // ---------- ODHLÁŠENÍ ----------
    if (isset($_GET['logout'])) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }

    // ---------- PŘIHLÁŠENÍ ----------
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        $__ip = cfloat_auth_client_ip();

        if (cfloat_auth_is_locked($__ip)) {
            $__wait = (int)ceil(cfloat_auth_seconds_to_unlock($__ip) / 60);
            $loginError = "Příliš mnoho neúspěšných pokusů o přihlášení. Zkuste to znovu za {$__wait} min.";
        } else {
            $u = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
            $p = isset($_POST['password']) ? (string)$_POST['password'] : '';

            $expectedUser = (string)($__cfloatAuthData['admin_user'] ?? '');
            $userOk = ($expectedUser !== '') && hash_equals($expectedUser, $u);
            $passOk = $userOk && cfloat_auth_verify_and_migrate(
                $__cfloatAuthData,
                'admin_pass_hash',
                'admin_pass_bootstrap',
                $p,
                $__cfloatAuthFile
            );

            if ($userOk && $passOk) {
                cfloat_auth_clear_attempts($__ip);
                session_regenerate_id(true);
                $_SESSION = [];
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();
                header('Location: ' . basename($_SERVER['PHP_SELF']));
                exit;
            }

            cfloat_auth_register_failed_attempt($__ip);
            $loginError = 'Neplatné přihlašovací údaje.';
        }
    }

    $loggedIn = !empty($_SESSION['logged_in']);
}
