<?php
include_once ('modelfactory.php');
include_once ('querymap.php');
include_once ('osmtypesstream.php');

//Classes to handle outputs in plain or compressed forms (this is the strategy pattern)
class OutPlainText
{
	var $fi = null;

	function __construct($filename)
	{
		$this->fi = fopen($filename,"wt");
	}
	function __destruct()
	{
		fflush($this->fi);
		fclose($this->fi);
	}
	function Write($data)
	{
		fwrite($this->fi, $data);
	}
}

class OutBz2
{
	var $fi = null;

	function __construct($filename)
	{
		$this->fi = bzopen($filename,"w");
	}
	function __destruct()
	{
		bzflush($this->fi);
		bzclose($this->fi);
	}
	function Write($data)
	{
		bzwrite($this->fi, $data);
	}
}



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

$out->Write("<?xml version='1.0' encoding='UTF-8'?>\n");
$out->Write("<osm version='0.6' generator='".SERVER_NAME."'>\n");
//Connect to database
$db = OsmDatabase();
//$lock=GetWriteDatabaseLock(); //Can we avoid locking?


//Specify callback function to handle returned objects
function SaveToFile($el)
{
	global $out;
	$out->Write($el->ToXmlString());	
}

//Dump database to file

$db->Dump("SaveToFile");

$out->Write("</osm>\n");
unset($out);
unset($db); //Destructor acts better with unset, rather than letting it go out of scope
echo"\n";
?>
