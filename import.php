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

$db->Purge();

//$fi = fopen("import.osm","rt");
//$import = ParseOsmXml(fread($fi, filesize("import.osm")));



//Do extraction
ExtractBz2($filename,$xml);



function ElementExtracted($el)
{
	if(is_null($el)) return;
	echo $el->GetType()." ".$el->attr['id']."\n";
	global $db;
	$db->ModifyElement($el->GetType(), $el->attr['id'], $el);
}


//echo count($import);
//print_r($import);
/*$count = 0;
foreach($import as $ele)
{
	echo $count." ".get_class($ele)."<br/>";
	$db->ModifyElement($ele->GetType(), $ele->attr['id'], $ele);
	$count ++;
}*/

unset($db); //Destructor acts better with unset, rather than letting it go out of scope
echo"\n";
?>
