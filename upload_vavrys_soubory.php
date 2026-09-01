<?php
// Ruční nahrání VAVRYS CSV souborů do složky /RucniNahraniAktualizace/.
session_start();
const UVAV_FOLDER = 'RucniNahraniAktualizace';

if (empty($_SESSION['logged_in'])) {
    http_response_code(403);
    echo 'Nepřihlášeno.';
    exit;
}

function uvav_upload_redirect(array $params, string $anchor = 'vavrys-aktualizace'): void {
    $base = 'index.php?view=xmlfeedy';
    if ($params) $base .= '&' . http_build_query($params);
    if ($anchor !== '') $base .= '#' . rawurlencode($anchor);
    header('Location: ' . $base);
    exit;
}

function uvav_upload_safe_filename(string $name): string {
    $name = basename($name);
    $name = preg_replace('~[^A-Za-z0-9._-]+~u', '_', $name);
    $name = trim((string)$name, '._-');
    return $name !== '' ? $name : 'vavrys.csv';
}

function uvav_upload_output_name(string $fileName): string {
    $base = basename($fileName);
    if (preg_match('~^(.*)_var\.csv$~i', $base, $m)) {
        return $m[1] . '_IMPORT_DO_ESHOPU_var.csv';
    }
    return preg_replace('~\.csv$~i', '_IMPORT_DO_ESHOPU.csv', $base);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    uvav_upload_redirect(['vavrys_upload' => 'err', 'msg' => 'Soubor nebyl odeslán.']);
}

if (empty($_FILES['vavrys_files']) || !is_array($_FILES['vavrys_files'])) {
    uvav_upload_redirect(['vavrys_upload' => 'err', 'msg' => 'Vyber aspoň jeden CSV soubor.']);
}

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
if ($docRoot === '') $docRoot = realpath(__DIR__) ?: __DIR__;
$targetDir = $docRoot . '/' . UVAV_FOLDER;
if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true)) {
    uvav_upload_redirect(['vavrys_upload' => 'err', 'msg' => 'Nelze vytvořit složku /' . UVAV_FOLDER . '/.']);
}
if (!is_writable($targetDir)) {
    uvav_upload_redirect(['vavrys_upload' => 'err', 'msg' => 'Složka /' . UVAV_FOLDER . '/ není zapisovatelná.']);
}

$names = $_FILES['vavrys_files']['name'] ?? [];
$tmpNames = $_FILES['vavrys_files']['tmp_name'] ?? [];
$errors = $_FILES['vavrys_files']['error'] ?? [];
$count = is_array($names) ? count($names) : 0;
$saved = 0;

for ($i = 0; $i < $count; $i++) {
    $err = $errors[$i] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) continue;
    if ($err !== UPLOAD_ERR_OK) continue;

    $orig = uvav_upload_safe_filename((string)($names[$i] ?? 'vavrys.csv'));
    if (!preg_match('~\.csv$~i', $orig)) continue;
    if (stripos($orig, '_IMPORT_DO_ESHOPU') !== false) continue;

    $tmp = (string)($tmpNames[$i] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) continue;

    $dst = $targetDir . '/' . $orig;
    @unlink($dst);
    if (@move_uploaded_file($tmp, $dst)) {
        @chmod($dst, 0664);
        // Když se přepíše původní _var, smaž starý výstup, aby se neukazovalo staré „připraveno“.
        if (preg_match('~_var\.csv$~i', $orig)) {
            $out = $targetDir . '/' . uvav_upload_output_name($orig);
            @unlink($out);
            @unlink($out . '.stats.json');
        }
        $saved++;
    }
}

if ($saved <= 0) {
    uvav_upload_redirect(['vavrys_upload' => 'err', 'msg' => 'Nepodařilo se nahrát žádný CSV soubor.']);
}

uvav_upload_redirect(['vavrys_upload' => 'ok', 'files' => (string)$saved]);
