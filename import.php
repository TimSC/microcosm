<?php
include_once ('modelfactory.php');
include_once ('querymap.php');
include_once ('osmtypesstream.php');

//print_r($_SERVER);
if(!isset($_SERVER['TERM'])) die('This script can only be run locally, not via the web server.'."\n");
set_time_limit(0);

$filename = "import.osm.bz2";
if(isset($_SERVER['argv'][1]))
	$filename = $_SERVER['argv'][1];

//Create source object
$xml = new OsmTypesStream();
$xml->callback = 'ElementExtracted';

//Create destination object
$db = OsmDatabase();
$lock=GetWriteDatabaseLock();

//Nuke the databae
$db->Purge();

//Do extraction
$done = 0;
if(strcasecmp(substr($filename,strlen($filename)-4),".bz2")==0) {ExtractBz2($filename,$xml);$done = 1;}
if(strcasecmp(substr($filename,strlen($filename)-4),".osm")==0) {ExtractOsmXml($filename,$xml);$done = 1;}

if(!$done)
{
	//echo substr($filename,strlen($filename)-4);
	echo "Could not import file of unknown extension.\n";
	exit(0);
}


function ElementExtracted($el)
{
	if(is_null($el)) return;
	echo $el->GetType()." ".$el->attr['id']."\n";
	global $db;
	$db->ModifyElement($el->GetType(), $el->attr['id'], $el);
}

unset($db); //Destructor acts better with unset, rather than letting it go out of scope
echo"\n";
?>
