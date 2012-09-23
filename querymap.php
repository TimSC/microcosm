<?php
require_once('fileutils.php');
require_once('messagepump.php');
require_once('dprint.php');

function MapQuery($userInfo,$bboxStr)
{
	//echo gettype($bboxStr);
  if (!isset($bboxStr))   {
    debug_print_backtrace();
    throw new Exception("missing bboxStr array in MapQuery");
  }
  if (count($bboxStr) <1 )   {
    debug_print_backtrace();    
    throw new Exception("bboxStr array is empty in MapQuery");
  }
  
  dprint("bboxStr:",$bboxStr);

	$bbox = explode(",",$bboxStr['bbox']);
	$bbox = array_map('floatval', $bbox);

	//Validate bbox
	$ret = ValidateBbox($bbox);
	if($ret != 1) return array(0,Null,$ret);

	$lock=GetReadDatabaseLock();

	$queryEvent = new Message(Message::MAP_QUERY, $bbox);
	global $messagePump;
	$messagePump->Add($queryEvent);
	$ret = $messagePump->Process();

	return array(1,array("Content-Type:text/xml"),$ret);
}

function GetObjectByIdMessage($type, $id, $version=null)
{
	$queryEvent = new Message(Message::GET_OBJECT_BY_ID, array($type,(int)$id,$version));
	global $messagePump;
	$messagePump->Add($queryEvent);
	return $messagePump->Process();
}

function MapObjectQuery($userInfo,$expUrl)
{
	$type=$expUrl[2];
	$id=(int)$expUrl[3];
	if(isset($expUrl[4])) $version=$expUrl[4];
	else $version = Null;

	$lock = GetReadDatabaseLock();
	$obj = GetObjectByIdMessage($type, $id, $version);

	if(!is_object($obj))
	{
		if($obj==0) return array(0,Null,"not-found");
		if($obj==-1) return array(0,Null,"not-implemented");
		if($obj==-2) return array(0,Null,"gone",$type,$id);
		return $obj;
	}
	
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".
		'<osm version="0.6" generator="'.SERVER_NAME.'">'.$obj->ToXmlString().'</osm>';
	return array(1,array("Content-Type:text/xml"),$out);
}

function MapObjectFullHistory($userInfo,$expUrl)
{
	$type=$expUrl[2];
	$id=(int)$expUrl[3];

	$lock=GetReadDatabaseLock();

	$queryEvent = new Message(Message::GET_FULL_HISTORY, array($type,(int)$id));
	global $messagePump;
	$messagePump->Add($queryEvent);
	$objs = $messagePump->Process();

	if(!is_array($objs))
	{
		if($objs==0 or is_null($objs)) return array(0,Null,"not-found");
		if($objs==-1) return array(0,Null,"not-implemented");
		throw new Exception("Unrecognised return code");
	}

	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<osm version="0.6" generator="'.SERVER_NAME.'">';
	foreach($objs as $obj) $out = $out.$obj->ToXmlString();
	$out = $out.'</osm>';
	return array(1,array("Content-Type:text/xml"),$out);
}

function MultiFetch($userInfo, $args)
{
	list($urlExp,$get) = $args;
	$type = $urlExp[2];
	if(!isset($get[$type])) return array(0,Null,"bad-arguments");
	$ids = explode(",",$get[$type]);
	$type = substr($type,0,-1); //Change to singular type

	$lock=GetReadDatabaseLock();
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<osm version="0.6" generator="'.SERVER_NAME.'">';

	$emptyQuery = 0;
	if(count($ids)<1 or (count($ids)==1 and strlen($ids[0])==0))
		$emptyQuery = 1;

	if(!$emptyQuery)
	foreach($ids as $id)
	{
		$object = GetObjectByIdMessage($type, (int)$id);
		if($object==null) return array(0,Null,"not-found");
		$out = $out.$object->ToXmlString()."\n";
	}

	$out = $out."</osm>";
	return array(1,array("Content-Type:text/xml"),$out);
}

function GetRelationsForElement($userInfo,$urlExp)
{
	$type = $urlExp[2];
	$id = (int)$urlExp[3];

	$lock=GetReadDatabaseLock();
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<osm version="0.6" generator="'.SERVER_NAME.'">';

	$rels = $map->GetCitingRelations($type,(int)$id);

	//For each relation found to match	
	foreach($rels as $id)
	{
		if(!is_integer($id)) throw new Exception("Values in relation array should be ID integers");
		$object = GetObjectByIdMessage("relation", (int)$id);
		if($object==null) return array(0,Null,"not-found");		
		$out = $out.$object->ToXmlString()."\n";
	}

	$out = $out."</osm>";
	return array(1,array("Content-Type:text/xml"),$out);
}

function GetWaysForNode($userInfo,$urlExp)
{
	$id = (int)$urlExp[3];

	$lock=GetReadDatabaseLock();
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<osm version="0.6" generator="'.SERVER_NAME.'">';

	$queryEvent = new Message(Message::GET_WAYS_FOR_NODE, (int)$id);
	global $messagePump;
	$messagePump->Add($queryEvent);
	$ways = $messagePump->Process();

	//For each relation found to match	
	foreach($ways as $id)
	{
		if(!is_integer($id)) throw new Exception("Values in way array should be ID integers");
		$object = GetObjectByIdMessage("way", (int)$id);
		if($object==null) return array(0,Null,"not-found");		
		$out = $out.$object->ToXmlString()."\n";
	}

	$out = $out."</osm>";
	return array(1,array("Content-Type:text/xml"),$out);

}


function GetFullDetailsOfElement($userInfo,$urlExp)
{
	$type=$urlExp[2];
	$id=(int)$urlExp[3];

	$lock=GetReadDatabaseLock();
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<osm version="0.6" generator="'.SERVER_NAME.'">';
	$firstObj = GetObjectByIdMessage($type,(int)$id, Null);

	//print_r($firstObj);
	if($firstObj===null or $firstObj===0) return array(0,Null,"not-found");
	if($firstObj===-2) return array(0,Null,"gone",$type,$id);
	$out = $out.$firstObj->ToXmlString()."\n";

	//Get relations but don't go recursively
	foreach($firstObj->members as $data)
	{
		if($data[0] != "relation") continue;
		$id = $data[1];
		$obj = GetObjectByIdMessage("relation",(int)$id);
		if($obj==null) return array(0,Null,"not-found");
		$out = $out.$obj->ToXmlString()."\n";		
	}

	//Get ways
	foreach($firstObj->members as $data)
	{
		if($data[0] != "way") continue;
		$id = $data[1];
		$obj = GetObjectByIdMessage("way",(int)$id);
		if($obj==null) return array(0,Null,"not-found");
		$out = $out.$obj->ToXmlString()."\n";

		//Get nodes of ways
		foreach($obj->members as $mem)
		{
			if($mem[0] != "node") continue;
			$nid = $mem[1];
			$n = GetObjectByIdMessage("node",(int)$nid);
			if($n==null) return array(0,Null,"not-found");
			$out = $out.$n->ToXmlString()."\n";
		}
	}

	//Get nodes of ways
	foreach($firstObj->members as $nd)
	{
		if($data[0] != "node") continue;
		$nid = $nd[1];
		$n = GetObjectByIdMessage("node",(int)$nid);
		if($n==null) return array(0,Null,"not-found");
		$out = $out.$n->ToXmlString()."\n";
	}

	$out = $out."</osm>";
	return array(1,array("Content-Type:text/xml"),$out);


}

?>
