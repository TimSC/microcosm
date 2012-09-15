<?php
require_once ('querymap.php');
require_once ('osmtypesstream.php');
require_once ('modelfactory.php');

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

class OutPlainTextToConsole
{
	function __construct()
	{

	}
	function __destruct()
	{

	}
	function Write($data)
	{
		echo $data;
	}
}

class OutWriteBuffer
{
	function __construct()
	{
		$this->buffer = "";
	}
	function __destruct()
	{

	}
	function Write($data)
	{
		$this->buffer .= $data;
	}
}

function Export($out)
{
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
	unset($db); //Destructor acts better with unset, rather than letting it go out of scope
}
?>
