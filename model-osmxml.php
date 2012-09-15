<?php

require_once("fileutils.php");
require_once("osmtypes.php");

//***************************************
//Low level changeset functions
//***************************************

class ChangesetDatabaseOsmXml
{
function Open($cid,$data,$displayName,$userId,$createTime)
{
	mkdir("changesets-open/".$cid,0777);
	chmod("changesets-open/".$cid,0777);

	$fi = fopen("changesets-open/".$cid."/putdata.xml","wt");
	$out = "<osm>".$data->ToXmlString()."</osm>";
	fwrite($fi,$out);

	$fi = fopen("changesets-open/".$cid."/details.txt","wt");
	fwrite($fi,$displayName.";".$userId.";".$createTime);

	return $cid;
}

function Update($cid,$data,$displayName,$userId)
{
	//Read existing data
	$filename = "changesets-open/".$cid."/putdata.xml";
	$fi = fopen($filename,"wt");	
	//$dataOld = ParseOsmXmlChangeset(fread($fi,filesize($filename)));

	$out = "<osm>".$data->ToXmlString()."</osm>";
	fwrite($fi,$out);
	fclose($fi);
	clearstatcache($filename);

	return 1;
}

function IsOpen($cid)
{
	//Check if the changeset is open
	if(!is_dir("changesets-open/".$cid))
	{
		if(is_dir("changesets-closed/".$cid))
		{
			return 0;
		}
		return -1;
	}
	return 1;
}

function GetContentObject($cid)
{
	$filename = "changesets-open/".$cid."/data.txt";
	if(!file_exists($filename))
	{
		$filename = "changesets-closed/".$cid."/data.txt";
		if(!file_exists($filename))
		{
			if($this->IsOpen($cid)==1) return new OsmChange();
			return null;
		}
	}

	$changeset = null;
	$fi = fopen($filename,"r+t");
	$changeset = unserialize(fread($fi,filesize($filename)));
	fclose($fi);
	return $changeset;
}

function GetSize($cid)
{
	$changeset = $this->GetContentObject($cid);
	if(is_null($changeset))
	{
		$isopen=$this->IsOpen($cid);
		if($isopen==1) return 0;
		if($isopen==-1) return null;
	}
	$count = 0;
	foreach($changeset->data as $data)
	{
		$action = $data[0];
		$els = $data[1];
		$count = $count + count($els);
	}
	return $count;
}

function AppendElement($cid, $action, $el)
{
	$filename = "changesets-open/".$cid."/data.txt";
	$changeset = $this->GetContentObject($cid);
	if(is_null($changeset)) $changeset = new OsmChange();

	array_push($changeset->data,array($action,array($el)));
	
	$serdata = serialize($changeset);
	$datalen = strlen($serdata);
	//echo $datalen.",";

	$fi = fopen($filename,"wt");
	fwrite($fi,$serdata);
	fflush($fi);
	fclose($fi);
	clearstatcache($filename);
	//echo filesize($filename).",";
}

function GetClosedTime($cid)
{
	//$lock=GetReadDatabaseLock();
	$fname = "changesets-closed/".$cid."/closetime.txt";
	if(file_exists($fname))
	{
		return (int)file_get_contents($fname);
	}

	return null;
}

function GetUid($cid)
{
	if(file_exists("changesets-open/".$cid."/details.txt"))
	{
		$details = explode(";",file_get_contents("changesets-open/".$cid."/details.txt"));
		return (int)$details[1];
	}
	if(file_exists("changesets-closed/".$cid."/details.txt"))
	{
		$details = explode(";",file_get_contents("changesets-closed/".$cid."/details.txt"));
		return (int)$details[1];
	}
	return null;
}

function GetMetadata($cid)
{
	$open = $this->IsOpen($cid);
	if($open == -1) return null;

	//Read tags
	if($open) $filename = "changesets-open/".$cid."/putdata.xml";
	else $filename = "changesets-closed/".$cid."/putdata.xml";
	$fi = fopen($filename,"rt");	
	$data = ParseOsmXmlChangeset(fread($fi,filesize($filename)));

	//Read constant details
	if($open) $detfinm = "changesets-open/".$cid."/details.txt";
	else $detfinm = "changesets-closed/".$cid."/details.txt";
	$details = explode(";",file_get_contents($detfinm));

	if($open) $data->attr['open'] = "true";
	else $data->attr['open'] = "false";
	$data->attr['user'] = $details[0];
	$data->attr['uid'] = $details[1];
	$data->attr['created_at'] = date("c", (int)$details[2]);

	if(!$open) $data->attr['closed_at'] = date("c", $this->GetClosedTime($cid));

	//TODO bounding box calculation
	
	return $data;
}

function Close($cid)
{
	if(is_dir("changesets-open/".$cid))
	{
		//Timestamp of closing
		$fi = fopen("changesets-open/".$cid."/closetime.txt","wt");
		fwrite($fi,time());
		
		//Move old files to separate folder
		rename("changesets-open/".$cid,"changesets-closed/".$cid);
	}
}

function Query($user,$open,$timerange)
{
	$out = array();
	$opencs = getDirectory('changesets-open');
	foreach($opencs as $cid)
	{ 
		if(!is_null($user) and $user != GetChangesetUid($cid)) continue;
		array_push($out, (int)$cid);
	}
	
	if(is_null($open) or $open!=1)
	{
		$closedcs = getDirectory('changesets-closed');
		foreach($closedcs as $cid)
		{ 
			if(!is_null($user) and $user != GetChangesetUid($cid)) continue;
			array_push($out, (int)$cid);
		}
	}
	return $out;
}

}//End of class

//**************************
//Utility Functions
//**************************

function CloneElement($from,$to)
{
	//Clear destination children

	//Clear destination attributes
	
	//Copy attributes
	foreach ($from->attributes() as $k => $v)
	{
		//echo $k." ".$v."\n";
		//fwrite($debug,$type." ".$value['id']." ".$k." ".$v."\n");
		$to[$k] = $v;
	}
	//Copy children
	foreach ($from as $k => $v)
	{
		$tov = $to->addChild($k);
		CloneElement($v, $tov);
	}
}

//****************************
//Map Model Stored as OSM file
//****************************

class OsmDatabaseOsmXml
{
	public $db = null;
	public $modified = 0;

	//Load state
	function __construct()
	{
		//Load database
		$this->db = simplexml_load_file("map.osm");
		if (!$this->db)
			throw new Exception("Failed to load internal database.");
	}	

	function __destruct()
	{
		//Save if database has changed
		if($this->modified) $this->Save();
	}

	//Save state
	public function Save()
	{
		$this->db->saveXML("map.osm");
		$this->modified = 0;
	}

	//**********************
	//Internal processing
	//**********************
	
	function GetElementXmlById($typeIn, $idIn)
	{
		foreach ($this->db as $type => $v)
		{
			if(strcmp($type,$typeIn)==0 and (int)$v['id'] == (int)$idIn) return $v;
		}
		return null;
	}

	function RemoveElementByIdLowLevel($typeIn, $idIn)
	{
		$i = 0;
		$target = null;

		if(strcmp($typeIn,"node")==0)
		{
			foreach ($this->db->node as $type => $v)
			{
				$id = $v['id'];
				if((int)$id == (int)$idIn)
				{
					$target=$i;
					break;
				}
				$i = $i + 1;
			}
			if(is_null($target)) return 0;
			unset($this->db->node[$target]);
		}

		if(strcmp($typeIn,"way")==0)
		{
			foreach ($this->db->way as $type => $v)
			{
				$id = $v['id'];
				if((int)$id == (int)$idIn)
				{
					$target=$i;
					break;
				}
				$i = $i + 1;
			}
			if(is_null($target)) return 0;
			unset($this->db->way[$target]);
		}

		if(strcmp($typeIn,"relation")==0)
		{
			foreach ($this->db->relation as $type => $v)
			{
				$id = $v['id'];
				if((int)$id == (int)$idIn)
				{
					$target=$i;
					break;
				}
				$i = $i + 1;
			}
			if(is_null($target)) return 0;
			unset($this->db->relation[$target]);
		}


		return 1;
	}

	function RemoveElementById($typeIn, $idIn)
	{
		//Delete element, and check for duplicates
		$ret = 0;
		do
		{
			$ret = $this->RemoveElementByIdLowLevel($typeIn, $idIn);
		} while($ret==1);
	}

	//**********************
	//General main query
	//**********************

	public function MapQuery($bbox)
	{
		$out = file_get_contents("map.osm",FILE_USE_INCLUDE_PATH);
		return $out;
	}

	function CheckPermissions()
	{
		$filesToCheck=array('map.osm');

		foreach($filesToCheck as $f)
		if(!is_writable($f))
		{
			return $f;
		}
		return 1;
	}

	//**********************************
	//Get specific info from database
	//**********************************

	public function GetCurentVerOfElement($type,$id)
	{
		$currentObj = $this->GetElementXmlById($type,$id);
		if(is_null($currentObj)) return null;
		if(!isset($currentObj['version'])) 
			throw new Exception("Internal database has missing version attribute.");
		return (int)$currentObj['version'];
	}

	public function GetElementById($type,$id,$version=null)
	{
		$object = $this->GetElementXmlById($type, $id);
		//print_r($object);

		//This implementation can only return the current version
		if($version != null and (int)$object['version'] > (int)$version) return -1;
		if($version != null and (int)$object['version'] < (int)$version) return 0;
		if($object==null) return 0;
		return SingleObjectFromXml("<osm>".$object->asXML()."</osm>");
	}

	public function GetElementFullHistory($type,$id)
	{
		//Just get current version, for this implementation
		$out = array($this->GetElementById($type,$id));
		return $out;
	}

	public function CheckElementExists($type,$id)
	{
		//Check the element exists (in a non deleted state)
		return !is_null($this->GetElementXmlById($type,$id));
	}

	public function GetCitingWaysOfNode($id)
	{
		//For each way
		$waysMatched = array();
		foreach ($this->db->way as $way)
		{
			$match = 0;
			foreach ($way->nd as $nd)
			{
				if((int)$id == (int)$nd['ref']) {$match = 1;break;}
			}
			if ($match == 1)
				array_push($waysMatched,$way['id']);
		}
		return $waysMatched;
	}

	public function GetCitingRelations($type,$id)
	{
		//For each way
		$matched = array();
		foreach ($this->db->relation as $r)
		{
			$match = 0;
			foreach ($r->member as $nd)
			{
				if((int)$id == (int)$nd['ref']
					and strcmp($type,$nd['type'])==0) {$match = 1;break;}
			}
			if ($match == 1)
			{
				array_push($matched,$r['id']);
			}
		}
		return $matched;
	}

	public function GetElementAsXmlString($type,$id)
	{
		$el = $this->GetElementXmlById($type,$id);
		if(is_null($el)) return null;
		return $el->asXML();
	}

	//***********************
	//Modification functions
	//***********************

	public function CreateElement($type,$id,$el)
	{
		$el->attr['visible'] = "true";
		$this->modified = 1;
		$value = simplexml_load_string($el->ToXmlString());
		$newdata = $this->db->addChild($type);
		CloneElement($value, $newdata);		
	}

	public function ModifyElement($type,$id,$el)
	{
		$el->attr['visible'] = "true";
		$this->modified = 1;
		$value = simplexml_load_string($el->ToXmlString());
		$this->RemoveElementById($type, $id);
		$element = $this->db->addChild($type);
		CloneElement($value, $element);		
	}

	public function DeleteElement($type,$id,$el)
	{
		$el->attr['visible'] = "false";
		$this->modified = 1;
		$this->RemoveElementById($type, $id);
	}

	public function Purge()
	{
		$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$out = $out.'<osm version="0.6" generator="'.SERVER_NAME.'"></osm>'."\n";
		$fi=fopen("map.osm","wt");
		fwrite($fi, $out);
		fclose($fi);

		//Reload internal state
		$this->db = simplexml_load_file("map.osm");
		$this->modified = 0;
	}
}




?>
