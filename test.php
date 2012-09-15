<?php
include_once('modelfactory.php');

if(!isset($_SERVER['TERM'])) die('This script can only be run locally, not via the web server.'."\n");

$db = OsmDatabase();

print_r($db->GetElementById("relation",113));
//print_r( $db->GetNodesInBbox(array(-0.5,50.0,-0.6,52.0)));


?>
