<?php
include_once ('modelfactory.php');
include_once ('querymap.php');

set_time_limit(60);

$db = OsmDatabase();

$db->Purge();

$fi = fopen("import.osm","rt");
$import = ParseOsmXml(fread($fi, filesize("import.osm")));

$lock=GetWriteDatabaseLock();
//echo count($import);
//print_r($import);
$count = 0;
foreach($import as $ele)
{
	echo $count." ".get_class($ele)."<br/>";
	$db->ModifyElement($ele->GetType(), $ele->attr['id'], $ele);
	$count ++;
}

unset($db); //Destructor acts better with unset, rather than letting it go out of scope
?>
