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


function GetElementById($map, $typeIn, $idIn)
{
	foreach ($map as $type => $v)
	{
		$id = $v['id'];
		//echo $type." ".$id." ".$typeIn." ".$idIn."\n";
		if(strcmp($type,$typeIn)==0 and (int)$id == (int)$idIn)
		{
			return $v;
		}
	}
	return null;
}

function MapQuery($bbox)
{
	$lock=GetReadDatabaseLock();
	$area = ((float)$bbox[0] - (float)$bbox[2]) * ((float)$bbox[3] - (float)$bbox[1]);
	if($area > MAX_QUERY_AREA)
	{
		return "too-large";
	}

	$map = new OsmDatabase();
	return $map->MapQuery($bbox);
}

function MapObjectQuery($type,$id,$version=null)
{
	$lock=GetReadDatabaseLock();
	$map = simplexml_load_file("map.osm");
	$object = GetElementById($map, $type, $id);
	//This implementation can only return the current version
	if($version != null and (int)$object['version'] > (int)$version) return "not-implemented";
	if($version != null and (int)$object['version'] < (int)$version) return "not-found";
	if($object==null) return "not-found";
	return '<osm version="0.6" generator="'.SERVER_NAME.'">'.$object->asXML()."</osm>";
}

function MapObjectFullHistory($type,$id)
{
	return MapObjectQuery($type,$id); //Just get current version, for now
}

function MultiFetch($type,$ids)
{
	$lock=GetReadDatabaseLock();
	$out = '<osm version="0.6" generator="'.SERVER_NAME.'">';
	$map = simplexml_load_file("map.osm");

	$emptyQuery = 0;
	if(count($ids)<1 or (count($ids)==1 and strlen($ids[0])==0))
		$emptyQuery = 1;

	if(!$emptyQuery)
	foreach($ids as $id)
	{
		$object = GetElementById($map, $type, $id);
		if($object==null) return "not-found";
		//This implementation can only return the current version
		//if($version != null and (int)$object['version'] > (int)$version) return "not-implemented";
		//if($version != null and (int)$object['version'] < (int)$version) return "not-found";
		$out = $out.$object->asXML()."\n";
	}

	$out = $out."</osm>";
	return $out;
}

function GetRelationsForElement($type,$id)
{
	$lock=GetReadDatabaseLock();
	$out = '<osm version="0.6" generator="'.SERVER_NAME.'">';
	$map = simplexml_load_file("map.osm");

	$found = array();
	//if(strcmp($type,"node")==0)
	//{
		//print_r(GetWayIdsThatUseNode($map,$id));
	//}	

	//For each relation
	$matched = array();
	foreach ($map->relation as $rel)
	{
		$match = 0;
		//Check node ids
		foreach ($rel->member as $member)
		{
			if((string)$type == (string)$member['type'] and (int)$id == (int)$member['ref'])
			{
				$match = 1;
				break;
			}
		}
		if ($match == 1)
		{
			array_push($matched,$rel);
		}
	}
		
	foreach($matched as $rel)
	{
		$out = $out.$rel->asXML()."\n";
	}

	$out = $out."</osm>";
	return $out;

}

/*function GetWaysForNode($id)
{
	$lock=GetReadDatabaseLock();
	$out = '<osm version="0.6" generator="'.SERVER_NAME.'">';

	$map = new OsmDatabase();
	$waysMatched = $map->GetCitingWaysOfNode((int)$id);

	foreach($waysMatched as $way)
	{
		$out = $out.$map->GetElementAsXmlString("way",$way)."\n";
	}

	$out = $out."</osm>";
	return $out;
}*/

function ProcessFullWayDetails($map,$id,&$out)
{
	$o = GetElementById($map, "way", $id);		
	if($o==null) return 0;
	foreach($o->nd as $nd)
	{
		$n = GetElementById($map, "node", $nd['ref']);		
		if($n==null) return 0;
		$out = $out.$n->asXML()."\n";	
	}
	return 1;
}

function GetFullDetailsOfElement($type,$id)
{
	$lock=GetReadDatabaseLock();
	$out = '<osm version="0.6" generator="'.SERVER_NAME.'">';
	$map = simplexml_load_file("map.osm");

	if(strcmp($type,"relation")==0)
	{
		$object = GetElementById($map, $type, $id);		
		if($object==null) return "not-found";
		$out = $out.$object->asXML()."\n";

		foreach($object->member as $member)
		{
			$o = GetElementById($map, $member['type'], $member['ref']);		
			if($o==null) return "not-found";
			$out = $out.$o->asXML()."\n";

			if(strcmp($member['type'],"way")==0)
			{
				$ret = ProcessFullWayDetails($map,$member['ref'],$out);
				if($ret==0) return "not-found";
			}
		}
	}

	$out = $out."</osm>";
	return $out;


}

?>
