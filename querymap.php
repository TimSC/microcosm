<?php

require_once('modelfactory.php');
require_once('fileutils.php');

function MapQuery($bbox)
{
	$lock=GetReadDatabaseLock();
	$area = ((float)$bbox[0] - (float)$bbox[2]) * ((float)$bbox[3] - (float)$bbox[1]);
	if($area > MAX_QUERY_AREA)
	{
		return "too-large";
	}
	//Validate bbox
	$bbox = array_map('floatval', $bbox);

	$map = OsmDatabase();
	return $map->MapQuery($bbox);
}

function MapObjectQuery($type,$id,$version=null)
{
	$lock=GetReadDatabaseLock();
	$map = OsmDatabase();
	$obj = $map->GetElementById($type,(int)$id,$version);
	if(!is_object($obj))
	{
		if($obj==0) return "not-found";
		if($obj==-1) return "not-implemented";
		if($obj==-2) return "gone";
		return $obj;
	}

	return '<?xml version="1.0" encoding="UTF-8"?>'."\n".
		'<osm version="0.6" generator="'.SERVER_NAME.'">'.$obj->ToXmlString().'</osm>';
}

function MapObjectFullHistory($type,$id)
{
	$lock=GetReadDatabaseLock();
	$map = OsmDatabase();
	$objs = $map->GetElementFullHistory($type,$id);

	if(!is_array($objs))
	{
		if($objs==0 or is_null($objs)) return "not-found";
		if($objs==-1) return "not-implemented";
		return $objs;
	}

	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<osm version="0.6" generator="'.SERVER_NAME.'">';
	foreach($objs as $obj) $out = $out.$obj->ToXmlString();
	$out = $out.'</osm>';
	return $out;
}

function MultiFetch($type,$ids)
{
	$lock=GetReadDatabaseLock();
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<osm version="0.6" generator="'.SERVER_NAME.'">';
	$map = OsmDatabase();

	$emptyQuery = 0;
	if(count($ids)<1 or (count($ids)==1 and strlen($ids[0])==0))
		$emptyQuery = 1;

	if(!$emptyQuery)
	foreach($ids as $id)
	{
		$object = $map->GetElementById($type, (int)$id);
		if($object==null) return "not-found";
		$out = $out.$object->ToXmlString()."\n";
	}

	$out = $out."</osm>";
	return $out;
}

function GetRelationsForElement($type,$id)
{
	$lock=GetReadDatabaseLock();
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<osm version="0.6" generator="'.SERVER_NAME.'">';
	$map = OsmDatabase();

	$rels = $map->GetCitingRelations($type,(int)$id);

	//For each relation found to match	
	foreach($rels as $id)
	{
		if(!is_integer($id)) throw new Exception("Values in relation array should be ID integers");
		$object = $map->GetElementById("relation", (int)$id);
		if($object==null) return "not-found";		
		$out = $out.$object->ToXmlString()."\n";
	}

	$out = $out."</osm>";
	return $out;

}

function GetWaysForNode($id)
{
	$lock=GetReadDatabaseLock();
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<osm version="0.6" generator="'.SERVER_NAME.'">';
	$map = OsmDatabase();

	$ways = $map->GetCitingWaysOfNode((int)$id);

	//For each relation found to match	
	foreach($ways as $id)
	{
		if(!is_integer($id)) throw new Exception("Values in way array should be ID integers");
		$object = $map->GetElementById("way", (int)$id);
		if($object==null) return "not-found";		
		$out = $out.$object->ToXmlString()."\n";
	}

	$out = $out."</osm>";
	return $out;

}


function GetFullDetailsOfElement($type,$id)
{
	$lock=GetReadDatabaseLock();
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<osm version="0.6" generator="'.SERVER_NAME.'">';
	$map = OsmDatabase();
	$firstObj = $map->GetElementById($type,(int)$id);
	if($firstObj==null) return "not-found";
	$out = $out.$firstObj->ToXmlString()."\n";

	//Get relations but don't go recursively
	foreach($firstObj->relations as $data)
	{
		$id = $data[0];
		$obj = $map->GetElementById("relation",(int)$id);
		if($obj==null) return "not-found";
		$out = $out.$obj->ToXmlString()."\n";		
	}

	//Get ways
	foreach($firstObj->ways as $data)
	{
		$id = $data[0];
		$obj = $map->GetElementById("way",(int)$id);
		if($obj==null) return "not-found";
		$out = $out.$obj->ToXmlString()."\n";

		//Get nodes of ways
		foreach($obj->nodes as $nd)
		{
			$nid = $nd[0];
			$n = $map->GetElementById("node",(int)$nid);
			if($n==null) return "not-found";
			$out = $out.$n->ToXmlString()."\n";
		}
	}

	//Get nodes of ways
	foreach($firstObj->nodes as $nd)
	{
		$nid = $nd[0];
		$n = $map->GetElementById("node",(int)$nid);
		if($n==null) return "not-found";
		$out = $out.$n->ToXmlString()."\n";
	}

	$out = $out."</osm>";
	return $out;


}

?>
