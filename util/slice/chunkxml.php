<?php 
//Take an osm XML file and split into multiple, valid XML chunk files.

if(!isset($_SERVER['TERM'])) die('This script can only be run locally, not via the web server.'."\n");

include_once ('../../osmtypesstream.php');
set_time_limit(0);
//$filename = "/var/www/egypt.osm.bz2";
//$filename = "/var/www/sharm.osm.bz2";
//$filename = "/var/www/ireland.osm.bz2";
$filename = "/var/www/united_kingdom.osm.bz2";

//Determine extracted file size
echo "Determining size of extracted data...\n";
$extractedSize = new ExtractGetSize();
ExtractBz2($filename,$extractedSize);
$inputSize = $extractedSize->size;

$xml = new OsmTypesStream();
$xml->callback = 'ElementSearch';
$lastPercent = 0.0;
$out = Null;
$outSize = 0;
$chunkNum = 0;
$outFileTargetSize = 100000000;

ExtractBz2($filename,$xml);

if ($out!=Null) {bzwrite($out, "</osm>\n"); bzclose($out);}
echo "All done!\n";

function ElementSearch($el,$progress)
{
	global $out, $chunkNum, $outSize, $inputSize, $outFileTargetSize;

	//Open a file to write data
	if ($out==Null)
	{	
		$outFilename = "out-".$chunkNum.".osm.bz2";
		echo "Starting ".$outFilename."\n";
		$out = bzopen($outFilename,"w");
		$outSize = 0;
		bzwrite($out, "<osm version='0.6'>\n");
	}
	$objStr = $el->ToXmlString();
	$outSize += strlen($objStr);
	//echo $outSize."\n";

	bzwrite($out, $objStr);

	//Check if this chunk is full
	if($outSize > $outFileTargetSize)
	{
		echo "Done ".((float)$progress/$inputSize)."\n";
		bzwrite($out, "</osm>\n");
		bzclose($out);
		$out = Null;
		$outSize = 0;
		$chunkNum ++;
	}
}

?>
