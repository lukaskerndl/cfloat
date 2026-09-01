<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../../index.php');
    exit;
}

unset($_SESSION['schindler_selected']);
header('Location: index.php?cleared=1');
exit;
