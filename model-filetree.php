<?php

include_once('fileutils.php');
include_once('model-common.php');

//Utility functions

function GetFileElements( $type="node", $level = 0 )
{
$path = 'filetree';
$out=array();
// Directories to ignore when listing output.
$ignore = array( '.', '..' );

// Open the directory to the handle $dh
$dh = @opendir( $path );

// Loop through the directory
while( false !== ( $file = readdir( $dh ) ) )
{
// Check that this file is not to be ignored
if( !in_array( $file, $ignore ) )
{
// Show directories only
//if(is_dir( "$path/$file" ) )
//{
	//echo $file."\n";
//	array_push($out,$file);
//}
if(is_file( "$path/$file" ) )
{
	//echo $file."\n";
	if(strncmp($file,$type,strlen($type))==0)
	{
		if(strlen($file)>=11)
		{//Ignore history files
		//echo substr($file,strlen($file)-11)."\n";
		if(strcmp(substr($file,strlen($file)-11),"history.xml")==0) continue;
		}
		$typelen = strlen($type);
		array_push($out,(int)substr($file,$typelen,$typelen-strlen($type)-1));
		//array_push($out,$file);
	}
}
}
}
// Close the directory handle
closedir( $dh );
return $out;
} 

//**********************************
//Map Model Stored in Multiple Files
//**********************************

function myclearstatcache($filename)
{
	clearstatcache();
}

class OsmDatabaseByFileTree extends OsmDatabaseCommon
{

	function __construct()
	{
	}	

	function __destruct()
	{
	}

	//******************
	//Utility functions
	//******************

	function ElementFilename($type,$id)
	{
		return "filetree/".$type.$id.".xml";
	}

	function ElementHistoryFilename($type,$id)
	{
		return "filetree/".$type.$id."history.xml";
	}

	function GetElementXmlString($type,$id)
	{
		if(!is_integer($id)) throw new Exception("ID must be an integer");
		$filename = $this->ElementFilename($type,$id);
		if(!file_exists($filename)) return null;
		return file_get_contents($filename);
	}

	function GetElementObject($type,$id)
	{
		$str = $this->GetElementXmlString($type,$id);
		if(is_null($str)) return null;
		$obj = SingleObjectFromXml("<osm>".$str."</osm>");
		return $obj;
	}

	public function CheckElementHasChild(&$parent,$type,$id)
	{
		if(strcmp($type,"node")==0)
			foreach($parent->nodes as $e)
			{
				//echo $e[0].",".$id.";";
				if($e[0] == $id) return 1;
			}
		if(strcmp($type,"way")==0)
			foreach($parent->ways as $e)
				if($e[0] == $id) return 1;
		if(strcmp($type,"relation")==0)
			foreach($parent->relations as $e)
				if($e[0] == $id) return 1;
		return 0;
	}

	function CheckPermissions()
	{
		$filesToCheck=array('filetree');

		foreach($filesToCheck as $f)
		if(!is_writable($f))
		{
			return $f;
		}
		return 1;
	}

	//**********************
	//General main query
	//**********************

	public function GetNodesInBbox($bbox)
	{
		$ids = GetFileElements("node");
		$out = array();
		foreach($ids as $id)
		{
			$obj = $this->GetElementObject("node",$id);
			//echo($obj->attr['id']."\n");
			if($obj==null) throw new Exception("Could not retrieve node ".$id." in query");
			$lat = $obj->attr['lat'];
			$lon = $obj->attr['lon'];
			if($lon < $bbox[0] or $lon > $bbox[2]) continue;
			if($lat < $bbox[1] or $lat > $bbox[3]) continue;
			array_push($out,$obj);
		}
		return $out;
	}

	public function GetParentWaysOfNodes(&$nodes)
	{
		$ids = GetFileElements("way");
		$out = array();
		foreach($ids as $id)
		{	
			$obj = $this->GetElementObject("way",$id);
			if(!$this->CheckElementHasAnyChild($obj,$nodes)) continue;
			array_push($out,$obj);
		}
		return $out;
	}
	
	public function CheckElementHasAnyChild(&$parent,&$children)
	{
		foreach($children as $child)
		{
			if(!is_object($child)) throw new Exception("Child must be an object");
			if(strcmp($child->GetType(),"node")==0)
				foreach($parent->nodes as $e)
					if($e[0] == $child->attr['id']) return 1;
			if(strcmp($child->GetType(),"way")==0)
				foreach($parent->ways as $e)
					if($e[0] == $child->attr['id']) return 1;
			if(strcmp($child->GetType(),"relation")==0)
				foreach($parent->relations as $e)
					if($e[0] == $child->attr['id']) return 1;
		}
		return 0;
	}

	public function GetParentRelations(&$elements)
	{
		$ids = GetFileElements("relation");
		$out = array();
		foreach($ids as $id)
		{	
			$obj = $this->GetElementObject("relation",$id);
			$match = $this->CheckElementHasAnyChild($obj,$elements);
			if(!$match) continue;
			array_push($out,$obj);
		}
		return $out;
	}

	//**********************************
	//Get specific info from database
	//**********************************

	public function GetElementById($type,$id,$version=null)
	{
		//Try to current current version
		$object = $this->GetElementObject($type, $id);

		if($object!=null)
		{
			if((int)$object->attr['version'] == (int)$version) return $object;
			if(is_null($version)) return $object;
		}

		//Fall back to history
		$historyName = $this->ElementHistoryFilename($type,$id);
		if(!file_exists($historyName)) return null;
		
		//If history exists, but version was not specified, the element has been deleted
		if(is_null($version)) return -2;

		$historyData = file_get_contents($historyName);
		$history = ParseOsmXml($historyData);	
		foreach($history as $object)
		{
			if((int)$object->attr['version'] == (int)$version) return $object;
		}

		return null;
	}

	public function GetElementFullHistory($type,$id)
	{
		$historyName = $this->ElementHistoryFilename($type,$id);
		if(!file_exists($historyName))
		{
			//Just return current state
			$obj = $this->GetElementById($type,$id);
			if(is_null($obj)) return null;
			return array($obj);
		}

		$historyData = file_get_contents($historyName);
		$history = ParseOsmXml($historyData);	
		return $history;
	}

	public function GetCitingWaysOfNode($id)
	{
		$obj = $this->GetElementById("node",$id);
		if($obj==null) return null;
		$idarray = array($obj);
		$ways = $this->GetParentWaysOfNodes($idarray);

		$ids = array();
		foreach($ways as $way)
			array_push($ids,$way->attr['id']);
		return $ids;
	}
/*
	public function GetCitingRelations($type,$id)
	{
		$ids = GetFileElements("relation");
		$out = array();
		foreach($ids as $idrel)
		{	
			$obj = $this->GetElementObject("relation",$idrel);
			$match = $this->CheckElementHasChild($obj,$type,$id);
			//echo $type,$id,$match;
			if(!$match) continue;
			array_push($out,$obj->attr['id']);
		}
		return $out;
	}

	public function GetElementAsXmlString($type,$id)
	{
		$obj = $this->GetElementById($type,$id);
		if($obj==null) return null;
		return $obj->ToXmlString();
	}
*/
	//***************************
	//Low level history functions
	//***************************

	function HistoryExists($type,$id)
	{
		$historyName = $this->ElementHistoryFilename($type,$id);
		return file_exists($historyName);
	}

	function CreateHistoryFile($type,$id)
	{
		$filename = $this->ElementFilename($type,$id);
		$historyName = $this->ElementHistoryFilename($type,$id);
		if(file_exists($historyName)) return 0;
		$fi = fopen($historyName,"wt");
		fwrite($fi,"<osm>\n");
		if(file_exists($filename))
		{
			$current = file_get_contents($filename);
			fwrite($fi,$current);
		}
		fwrite($fi,"</osm>\n");
		myclearstatcache($historyName);
		return 1;
	}

	function AppendToHistoryFile($type,$id,$el)
	{
		$historyName = $this->ElementHistoryFilename($type,$id);
		if(!file_exists($historyName))
			$this->CreateHistoryFile($type,$id);

		$historyData = file_get_contents($historyName);
		$history = ParseOsmXml($historyData);
		array_push($history,$el);
		
		//Save new history
		$fi = fopen($historyName,"wt");
		fwrite($fi,"<osm>\n");
		foreach($history as $item)
			fwrite($fi,$item->ToXmlString());
		fwrite($fi,"</osm>\n");
		myclearstatcache($historyName);
	}

	function DeleteHistoryFile($type,$id)
	{
		$historyName = $this->ElementHistoryFilename($type,$id);		
		if(file_exists($historyName))
			unlink($historyName);
		myclearstatcache($historyName);
	}

	//***********************
	//Modification functions
	//***********************

	public function CreateElement($type,$id,$el)
	{
		//Delete history, if it exists
		$this->DeleteHistoryFile($type,$id);

		//Delete previous state
		$filename = $this->ElementFilename($type,$id);
		if(file_exists($filename)) unlink($filename);
		myclearstatcache($filename);

		//Save to current state file
		$this->ModifyElement($type,$id,$el);
	}

	public function ModifyElement($type,$id,$el)
	{
		//If a version already exists, do proper history tracking
		$filename = $this->ElementFilename($type,$id);
		$prevVerExisted = file_exists($filename);
		if($prevVerExisted)
		{
			$this->CreateHistoryFile($type,$id);	
		}

		//Set current state to file
		$fi = fopen($filename, "wt");
		fwrite($fi, $el->ToXmlString());
		fclose($fi);
		myclearstatcache($filename);

		//Add this state to history
		if($prevVerExisted)	
			$this->AppendToHistoryFile($type,$id,$el);

	}

	public function DeleteElement($type,$id,$el)
	{
		//If no history, move state to history file
		$this->CreateHistoryFile($type,$id);
		$this->AppendToHistoryFile($type,$id,$el);

		//Delete current state file
		$filename = $this->ElementFilename($type,$id);
		unlink($filename);
		myclearstatcache($filename);
	}

	public function Purge()
	{
		RecursiveDeleteFolder("filetree");
		mkdir("filetree",0777);
		chmod("filetree",0777);
	}

}




?>
