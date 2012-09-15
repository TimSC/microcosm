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

//Get extracted size
$extractedSize = new ExtractGetSize();
$done = 0;
if(strcasecmp(substr($filename,strlen($filename)-4),".bz2")==0) {ExtractBz2($filename,$extractedSize);$done = 1;}
if(strcasecmp(substr($filename,strlen($filename)-4),".osm")==0) {ExtractOsmXml($filename,$extractedSize);$done = 1;}
if(!$done)
{
	echo "Could not import file of unknown extension.\n";
	exit(0);
}

echo "Determining size of extracted data...\n";
$totalSize = $extractedSize->size;
echo "Extracted size ".$totalSize."\n";


//Nuke the database
$db->Purge();

//Do extraction
$startTime = microtime(1);
$count = 0;
$lastPrintOutput = null;
if(strcasecmp(substr($filename,strlen($filename)-4),".bz2")==0) {ExtractBz2($filename,$xml);}
if(strcasecmp(substr($filename,strlen($filename)-4),".osm")==0) {ExtractOsmXml($filename,$xml);}

function GetTimeString($sec)
{
	if($sec>60.0*60.0*24.0*364.25)
		return (string)(round($sec/(60.0*60.0*24.0*364.25)))."yrs";
	if($sec>60.0*60.0*24.0*7.0)
		return (string)(round($sec/(60.0*60.0*24.0*7.0)))."wks";
	if($sec>60.0*60.0*24.0)
		return (string)(round($sec/(60.0*60.0*24.0)))."dys";
	if($sec>60.0*60.0)
		return (string)(round($sec/(60.0*60.0)))."hrs";
	if($sec>60.0)
		return (string)(round($sec/60.0))."min";
	return (string)(round($sec))."sec";
}

function ElementExtracted($el,$progress)
{
	global $totalSize, $startTime, $count, $lastPrintOutput;
	if(is_null($el)) return;
	if(is_null($lastPrintOutput) or $lastPrintOutput < microtime(1) - 1.0)
	{
		$progressFraction = $progress/$totalSize;
		$progressPercent = (round(100.0*$progressFraction,2));
		$elapseTime = microtime(1) - $startTime;
		if($progressFraction > 0.0)
			$remaining = (1.0 - $progressFraction) * $elapseTime / ($progressFraction);
		else
			$remaining = null;

		echo $el->GetType()." ".$el->attr['id']."\t".$progressPercent."%";
		if(isset($el->attr['name'])) echo "\t".$el->attr['name'];
		if(!is_null($remaining)) echo " ".GetTimeString($remaining);
		echo "\n";
		$lastPrintOutput = microtime(1);
	}
	global $db;
	$db->ModifyElement($el->GetType(), $el->attr['id'], $el);

	//Get element back to test
	//$obj = $db->GetElementById($el->GetType(), $el->attr['id'], $el->attr['version']);

	$count = $count + 1;
}

unset($db); //Destructor acts better with unset, rather than letting it go out of scope
echo"\n";
?>
