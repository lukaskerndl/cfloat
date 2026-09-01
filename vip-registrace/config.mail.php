<?php
// /vip-registrace/config.mail.php
//
// Skutečné SMTP údaje byly přesunuty do /secrets/vip_registrace_mail.php,
// který není dostupný z webu. Tento soubor je jen přesměrování,
// aby nebylo nutné měnit volající kód.

return include dirname(__DIR__) . '/secrets/vip_registrace_mail.php';
