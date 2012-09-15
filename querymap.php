<?php

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

function RemoveElementById($map, $typeIn, $idIn)
{
	$i = 0;
	$target = null;
	foreach ($map as $type => $v)
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
	if(strcmp($typeIn,"node")==0) unset($map->node[$i]);
	if(strcmp($typeIn,"way")==0) unset($map->way[$i]);
	if(strcmp($typeIn,"relation")==0) unset($map->relation[$i]);				
	return 1;
}

function MapQuery($bbox)
{
	$lock=GetReadDatabaseLock();
	$out = file_get_contents("map.osm",FILE_USE_INCLUDE_PATH);
	return $out;
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
	return "<osm>".$object->asXML()."</osm>";
}

function MapObjectFullHistory($type,$id)
{
	return MapObjectQuery($type,$id); //Just get current version, for now
}

?>
