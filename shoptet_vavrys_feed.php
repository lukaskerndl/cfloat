<?php

declare(strict_types=1);

require_once __DIR__ . '/shoptet_vavrys_feed_lib.php';

$data = svf_build_products(__DIR__);
$xml  = svf_render_xml($data['products']);

header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: inline; filename="shoptet-vavrys.xml"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

echo $xml;
