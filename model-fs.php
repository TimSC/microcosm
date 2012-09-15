<?php

require_once("fileutils.php");

//***************************************
//Low level changeset functions
//***************************************

function ChangesetOpenLowLevel($cid,$data,$displayName,$userId)
{
	mkdir("changesets-open/".$cid,0777);
	chmod("changesets-open/".$cid,0777);

	$fi = fopen("changesets-open/".$cid."/putdata.xml","wt");
	$out = "<osm>".$data->ToXmlString()."</osm>";
	fwrite($fi,$out);

	$fi = fopen("changesets-open/".$cid."/details.txt","wt");
	fwrite($fi,$displayName.";".$userId.";".time());

	return $cid;
}

function ChangesetUpdateLowLevel($cid,$data,$displayName,$userId)
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

function CheckChangesetIsOpen($cid)
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

function GetChangesetContentObject($cid)
{
	$filename = "changesets-open/".$cid."/data.txt";
	if(!file_exists($filename))
	{
		$filename = "changesets-closed/".$cid."/data.txt";
		if(!file_exists($filename))
		{
			if(CheckChangesetIsOpen($cid)==1) return new OsmChange();
			return null;
		}
	}

	$changeset = null;
	$fi = fopen($filename,"r+t");
	$changeset = unserialize(fread($fi,filesize($filename)));
	fclose($fi);
	return $changeset;
}

function GetChangetsetSize($cid)
{
	$changeset = GetChangesetContentObject($cid);
	if(is_null($changeset))
	{
		$isopen=CheckChangesetIsOpen($cid);
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

function AppendElementToChangeset($cid, $action, $el)
{
	$filename = "changesets-open/".$cid."/data.txt";
	$changeset = GetChangesetContentObject($cid);
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

	//$serdataIn = $serdata;
	//$fi = fopen($filename,"r+t");
	//$serdataIn = fread($fi,$datalen);
	//echo strlen($serdataIn).",";
	//$changeset = unserialize($serdataIn);
}

function GetChangesetClosedTimeLowLevel($cid)
{
	//$lock=GetReadDatabaseLock();
	$fname = "changesets-closed/".$cid."/closetime.txt";
	if(file_exists($fname))
	{
		return (int)file_get_contents($fname);
	}

	return null;
}

function GetChangesetUid($cid)
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

function GetChangesetMetadataLowLevel($cid)
{
	$open = CheckChangesetIsOpen($cid);
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

	if(!$open) $data->attr['closed_at'] = date("c", GetChangesetClosedTimeLowLevel($cid));

	//TODO bounding box calculation
	
	return $data;
}

function ChangesetCloseLowLevel($cid)
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

function ChangesetsQuery($user,$open,$timerange)
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


class OsmDatabase
{
	public $db = null;

	function __construct()
	{
		//Load database
		$this->db = simplexml_load_file("map.osm");
		if (!$this->db)
			throw new Exception("Failed to load internal database.");
	}	

	public function Save()
	{
		$this->db->saveXML("map.osm");
	}

	//Internal processing
	
	function GetElementById($typeIn, $idIn)
	{
		foreach ($this->db as $type => $v)
		{
			if(strcmp($type,$typeIn)==0 and (int)$v['id'] == (int)$idIn) return $v;
		}
		return null;
	}

	function RemoveElementById($typeIn, $idIn)
	{
		$i = 0;
		$target = null;
		foreach ($this->db as $type => $v)
		{
			$id = $v['id'];
			//echo $type." ".$id." ".$typeIn." ".$idIn."\n";
			if(strcmp($type,$typeIn)==0)
			{
				if((int)$id == (int)$idIn)
				{
					$target=$i;
					break;
				}
				$i = $i + 1;
			}
		}
		if($target==null) return 0;
		if(strcmp($typeIn,"node")==0) unset($this->db->node[$i]);
		if(strcmp($typeIn,"way")==0) unset($this->db->way[$i]);
		if(strcmp($typeIn,"relation")==0) unset($this->db->relation[$i]);				
		return 1;
	}

	//General main query

	public function MapQuery($bbox)
	{
		$out = file_get_contents("map.osm",FILE_USE_INCLUDE_PATH);
		return $out;
	}

	//Get specific info from database

	public function GetCurentVerOfElement($type,$id)
	{
		$currentObj = $this->GetElementById($type,$id);
		if(is_null($currentObj)) return null;
		if(!isset($currentObj['version'])) 
			throw new Exception("Internal database has missing version attribute.");
		return (int)$currentObj['version'];
	}

	public function CheckElementExists($type,$id)
	{
		return !is_null($this->GetElementById($type,$id));
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
				array_push($matched,$r['id']);
		}		
		return $matched;
	}

	public function GetElementAsXmlString($type,$id)
	{
		$el = $this->GetElementById($type,$id);
		if(is_null($el)) return null;
		return $el->asXML();
	}

	//Modification functions

	public function CreateElement($type,$id,$el)
	{
		$value = simplexml_load_string($el->ToXmlString());
		$newdata = $this->db->addChild($type);
		CloneElement($value, $newdata);		
	}

	public function ModifyElement($type,$id,$el)
	{
		$value = simplexml_load_string($el->ToXmlString());
		$this->RemoveElementById($type, $id);
		$element = $this->db->addChild($type);
		CloneElement($value, $element);		
	}

	public function DeleteElement($type,$id,$el)
	{
		$this->RemoveElementById($type, $id);
	}

}




?>
