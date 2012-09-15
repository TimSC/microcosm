<?php
require_once('modelfactory.php');
require_once('traces.php');
require_once('userdetails.php');
set_time_limit(0);

if(!isset($_SERVER['TERM'])) die('This script can only be run locally, not via the web server.'."\n");

$db = OsmDatabase();

//print_r($db->GetElementById("relation",113));
//print_r( $db->GetNodesInBbox(array(-0.636129,51.207553,-0.5081755,51.2828722)));
//$node = new OsmNode();
//$node->attr['id'] = 973;
//$node->attr['version'] = 7;

//$db->DeleteElement("node",973,$node);

//echo $db->nodeTable->Count()."\n";
//echo $db->wayTable->Count()."\n";
//echo $db->relationTable->Count()."\n";

//CheckElementDatabaseSqliteOpt($db);
//RepairHistoryOfTable($db->wayTable,$db);
//DumpSqliteDatabase($db,"planet.osm.bz2");

//$gpx = file_get_contents("demo.gpx");
//InsertTraceIntoDb($gpx,5550199,1, "identifiable", "demo.gpx", "A test", "testing");
//DeleteTrace(4);

//$lock=GetReadDatabaseLock();
//$db = UserDbFactory();
//$users = $db->RemoveUser(6000);
//
//print_r($db->AddUser("Mole3", "a34@b.com", "test123", 6000));
//print_r($db->Dump());
//print_r($db->CheckLogin("a@b.com", "test123"));
//print_r($db->GetKeys());
//$db = ChangesetDatabase();
//$db->Purge();
TableToHtml($db->nodeTable->dbh,"position");

//$ret = $db->QueryXapi(array(34.4761505,28.4682636,34.5385323,28.5733490),"highway","primary");
//$ret = $db->QueryXapi(null,"historic");
//print_r($ret);
//$ret = $db->bboxDb->GetTableSizes();
//$fi=fopen("debug.csv","wt");
//print_r($ret);
//foreach($ret as $k=>$c)
//	fwrite($fi,$k."\t".$c."\n");
?>
