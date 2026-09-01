Nahraj pouze slozku VIP do /www/, aby na serveru existovalo presne:
/www/VIP/api/submit.php
/www/VIP/config.php
/www/VIP/index.php

Netvor dalsi mezivrstvu typu /www/VIP_modul.../VIP/

Test po nahrani:
https://cfloat.cz/VIP/api/submit.php
Musi vratit JSON: {"success":false,"message":"Použij POST."}
