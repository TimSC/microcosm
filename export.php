<?php
require_once ('exportfuncs.php');

//print_r($_SERVER);
if(!isset($_SERVER['TERM'])) die('This script can only be run locally, not via the web server.'."\n");
set_time_limit(0);

//Open output file
$filename = "export.osm";
if(isset($_SERVER['argv'][1]))
	$filename = $_SERVER['argv'][1];

if(end(explode('.', $filename)) == "bz2")
	$out = new OutBz2($filename);
else 
	$out = new OutPlainText($filename);

Export($out);

unset($out);

echo"\n";
?>
