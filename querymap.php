<?php

include_once('model-fs.php');

function GetReadDatabaseLock()
{
	//To unlock, let the returned object go out of scope
	$fp = fopen("db.lock", "w");
	$ret = flock($fp, LOCK_SH);
	return $fp;
}

function GetWriteDatabaseLock()
{
	//To unlock, let the returned object go out of scope
	$fp = fopen("db.lock", "w");
	$ret = flock($fp, LOCK_EX);
	return $fp;
}

function MapQuery($bbox)
{
	$lock=GetReadDatabaseLock();
	$area = ((float)$bbox[0] - (float)$bbox[2]) * ((float)$bbox[3] - (float)$bbox[1]);
	if($area > MAX_QUERY_AREA)
	{
		return "too-large";
	}

	$map = OsmDatabase();
	return $map->MapQuery($bbox);
}

function MapObjectQuery($type,$id,$version=null)
{
	$lock=GetReadDatabaseLock();
	$map = OsmDatabase();
	$obj = $map->GetElementById($type,$id,$version);
	if(!is_object($obj))
	{
		if($obj==0) return "not-found";
		if($obj==-1) return "not-implemented";
		return $obj;
	}
	return '<osm version="0.6" generator="'.SERVER_NAME.'">'.$obj->ToXmlString().'</osm>';
}

function MapObjectFullHistory($type,$id)
{
	$lock=GetReadDatabaseLock();
	$map = OsmDatabase();
	$objs = $map->GetElementFullHistory($type,$id);
	if(!is_array($objs))
	{
		if($objs==0) return "not-found";
		if($objs==-1) return "not-implemented";
		return $objs;
	}

	$out = '<osm version="0.6" generator="'.SERVER_NAME.'">';
	foreach($objs as $obj) $out = $out.$obj->ToXmlString();
	$out = $out.'</osm>';
	return $out;
}

function MultiFetch($type,$ids)
{
	$lock=GetReadDatabaseLock();
	$out = '<osm version="0.6" generator="'.SERVER_NAME.'">';
	$map = OsmDatabase();

	$emptyQuery = 0;
	if(count($ids)<1 or (count($ids)==1 and strlen($ids[0])==0))
		$emptyQuery = 1;

	if(!$emptyQuery)
	foreach($ids as $id)
	{
		$object = $map->GetElementById($type, $id);
		if($object==null) return "not-found";
		$out = $out.$object->ToXmlString()."\n";
	}

	$out = $out."</osm>";
	return $out;
}

function GetRelationsForElement($type,$id)
{
	$lock=GetReadDatabaseLock();
	$out = '<osm version="0.6" generator="'.SERVER_NAME.'">';
	$map = OsmDatabase();

	$rels = $map->GetCitingRelations($type,$id);

	//For each relation found to match	
	foreach($rels as $id)
	{
		$object = $map->GetElementById("relation", $id);
		if($object==null) return "not-found";		
		$out = $out.$object->ToXmlString()."\n";
	}

	$out = $out."</osm>";
	return $out;

}

function GetFullDetailsOfElement($type,$id)
{
	$lock=GetReadDatabaseLock();
	$out = '<osm version="0.6" generator="'.SERVER_NAME.'">';
	$map = OsmDatabase();
	$firstObj = $map->GetElementById($type,$id);
	if($firstObj==null) return "not-found";
	$out = $out.$firstObj->ToXmlString()."\n";

	//Get relations but don't go recursively
	foreach($firstObj->relations as $data)
	{
		$id = $data[0];
		$obj = $map->GetElementById("relation",$id);
		if($obj==null) return "not-found";
		$out = $out.$obj->ToXmlString()."\n";		
	}

	//Get ways
	foreach($firstObj->ways as $data)
	{
		$id = $data[0];
		$obj = $map->GetElementById("way",$id);
		if($obj==null) return "not-found";
		$out = $out.$obj->ToXmlString()."\n";

		//Get nodes of ways
		foreach($obj->nodes as $nd)
		{
			$nid = $nd[0];
			$n = $map->GetElementById("node",$nid);
			if($n==null) return "not-found";
			$out = $out.$n->ToXmlString()."\n";
		}
	}

	//Get nodes of ways
	foreach($firstObj->nodes as $nd)
	{
		$nid = $nd[0];
		$n = $map->GetElementById("node",$nid);
		if($n==null) return "not-found";
		$out = $out.$n->ToXmlString()."\n";
	}

	$out = $out."</osm>";
	return $out;


}

?>
