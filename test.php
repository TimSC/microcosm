<?php
include_once('modelfactory.php');
require_once('traces.php');
set_time_limit(0);

if(!isset($_SERVER['TERM'])) die('This script can only be run locally, not via the web server.'."\n");

$db = OsmDatabase();

//print_r($db->GetElementById("relation",113));
//print_r( $db->GetNodesInBbox(array(-0.636129,51.207553,-0.5081755,51.2828722)));
//$node = new OsmNode();
//$node->attr['id'] = 973;
//$node->attr['version'] = 7;

//$db->DeleteElement("node",973,$node);

echo $db->nodeTable->Count()."\n";
echo $db->wayTable->Count()."\n";
echo $db->relationTable->Count()."\n";

//CheckElementDatabaseSqliteOpt($db);
//RepairHistoryOfTable($db->wayTable,$db);
//DumpSqliteDatabase($db,"planet.osm.bz2");

//$gpx = file_get_contents("demo.gpx");
//InsertTraceIntoDb($gpx,5550199,1, "identifiable", "demo.gpx", "A test", "testing");
DeleteTrace(4);

?>
