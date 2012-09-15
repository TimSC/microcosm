<?php 
//Take an osm XML file and split into multiple, valid XML chunk files.

if(!isset($_SERVER['TERM'])) die('This script can only be run locally, not via the web server.'."\n");

include_once ('../../osmtypesstream.php');
set_time_limit(0);
//$filename = "/var/www/egypt.osm.bz2";
//$filename = "/var/www/sharm.osm.bz2";
//$filename = "/var/www/ireland.osm.bz2";
//$filename = "/media/ExtDrive/earth-20111201130001.osm.bz2";
$filename = "/media/9bbba005-6a0b-4a29-82ff-c69573819aa0/fosm-earth/earth-20111201130001.osm.bz2";

//$filename = "test.osm.bz2";

//Determine extracted file size
//echo "Determining size of extracted data...\n";
//$extractedSize = new ExtractGetSize();
//ExtractBz2($filename,$extractedSize);
//$inputSize = $extractedSize->size;

$osmstr = new OsmDataStream();
$osmstr->callback = new ChunkWriter();
$datareader = new LineReader();
$datareader->callback = $osmstr;
ExtractBz2($filename,$datareader);

class LineReader
{
	function __construct()
	{
		$this->buffer = "";
		$this->callback = NULL;
	}

	function Process($data, $end=0)
	{
		$this->buffer .= $data;
		if (strpos($this->buffer,"\n") !== false)
		{
			$lines = explode("\n",$this->buffer);
			$numlines = count($lines);
			$this->ProcessLines(array_slice($lines,0,-1),0);
			$this->buffer = $lines[$numlines-1];
		}

		if ($end)
		{
			$this->ProcessLines(array($this->buffer),1);
			$this->buffer = "";
		}

		if(count($this->buffer) > 1000000)
		{
			print_r($this->buffer);
			print "LineReader Buffer ".count($this->buffer);
			exit(0);
		}

		//print "\n";
	}

	function ProcessLines($lines, $end)
	{		
		foreach ($lines as $line)
		{
			//print $line."\n";
			$this->callback->Process($line, 0);
		}

		if($end)
		{
			$this->callback->Process("", 1);
		}
	}
}

class OsmDataStream
{
	function __construct()
	{
		$this->depth = 0;
		$this->buffer = array();
		$this->callback = NULL;
	}

	function Process($data, $end=0)
	{
		$tdata = trim($data);
		$test = explode(" ",$tdata);
		if (substr($tdata,0,2) == "<?")
			return; //Ignore these tags
		$openTag = (substr($tdata,0,2) != "</");
		$selfContained = (substr($tdata,-2) == "/>");

		if(!$selfContained)
		{
			if($openTag) $this->depth++;
		}
		if($selfContained) $this->depth++;

		//print $data." ".$this->depth."\n";
		//print $test[0]." ".$this->depth."\n";

		if ($this->depth >= 2)
			array_push($this->buffer, $data);

		if ($this->depth > 4)
		{
			print "Disallowed XML tag depth ".$this->depth."\n";
			exit(0);
		}

		//Depth of 2 is end of object
		if (($selfContained or !$openTag) and $this->depth == 2)
		{
			//print "Object:\n";
			//print_r($this->buffer);
			//print "End\n";
			$this->callback->Process($this->buffer);
			$this->buffer = array();
		}

		if(!$selfContained)
		{
			if(!$openTag) $this->depth--;
		}
		if($selfContained) $this->depth--;

		if(count($this->buffer) > 100000)
		{
			print_r($this->buffer);
			print "OsmDataStream Buffer ".count($this->buffer);
			exit(0);
		}
	}
}

class ChunkWriter
{

	function __construct()
	{
		$this->out = NULL;
		$this->chunkNum = 0;
		$this->outSize = 0;
		$this->inputSize = 1; 
		$this->outFileTargetSize = 500 * 14000000;
	}

	function Process($data)
	{
		//print_r($data);

		//Open a file to write data
		if ($this->out==Null)
		{	
			$outFilename = "out-".$this->chunkNum.".osm.bz2";
			echo "Starting ".$outFilename."\n";
			$this->out = bzopen($outFilename,"w");
			$this->outSize = 0;
			bzwrite($this->out, "<osm version='0.6'>\n");
		}

		$objStr = "";
		foreach ($data as $line)
			$objStr .= $line."\n";

		$this->outSize += strlen($objStr);
		bzwrite($this->out, $objStr);

		//Check if this chunk is full
		if($this->outSize > $this->outFileTargetSize)
		{
			//echo "Done ".((float)$progress/$this->inputSize)."\n";
			bzwrite($this->out, "</osm>\n");
			bzclose($this->out);
			$this->out = Null;
			$this->outSize = 0;
			$this->chunkNum ++;
		}
	}

}

?>
