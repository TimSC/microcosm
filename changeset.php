<?php

require_once("config.php");
require_once("querymap.php");

function getDirectory( $path = '.', $level = 0 )
{
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
if(is_dir( "$path/$file" ) )
{
	//echo $file."\n";
	array_push($out,$file);
}
}
}
// Close the directory handle
closedir( $dh );
return $out;
} 

function ReadAndIncrementFileNum($filename)
{
	//This needs to be thread safe
	$fp = fopen($filename, "r+t");
	while (1) 
	{ 
		$wouldblock = null;
		$ret = flock($fp, LOCK_EX, $wouldblock);// do an exclusive lock
		if($ret == false) {throw new Exception('Lock failed.');}
		$out = (int)fread($fp,1024);
		if($out==0) $out = 1; //Disallow changeset to be zero
		fseek($fp,0);
		ftruncate($fp, 0); // truncate file
		fwrite($fp, $out+1);
		flock($fp, LOCK_UN); // release the lock
		fclose($fp);
		return $out;
	}
	return null;
}

function ChangesetOpen($putData,$displayName,$userId)
{
	$cid = ReadAndIncrementFileNum("nextchangesetid.txt");
	if(is_dir("changesets-open/".$cid))
	{
		throw new InvalidArgumentException('Changeset '.$cid.' already exists (open).');
	}
	if(is_dir("changesets-closed/".$cid))
	{
		throw new InvalidArgumentException('Changeset '.$cid.' already exists (closed).');
	}
	mkdir("changesets-open/".$cid,0777);
	chmod("changesets-open/".$cid,0777);

	$fi = fopen("changesets-open/".$cid."/putdata.xml","wt");
	fwrite($fi,$putData);
	$fi = fopen("changesets-open/".$cid."/details.txt","wt");
	fwrite($fi,$displayName.";".$userId.";".time());

	return $cid;
}

function GetNewObjectId($type)
{
	if(strcmp($type,"node")==0)
		return ReadAndIncrementFileNum("nextnodeid.txt");
	if(strcmp($type,"way")==0)
		return ReadAndIncrementFileNum("nextwayid.txt");
	if(strcmp($type,"relation")==0)
		return ReadAndIncrementFileNum("nextrelationid.txt");
	return null;
}

function RewriteIds($element,$type,$newids)
{
	if($element['id']>=0) return;
	if(isset($newids[(string)$type][(int)$element['id']]))
	{
		$element['old_id'] = $element['id'];
		$element['id'] = $newids[(string)$type][(int)$element['id']][0];
		return;
	}

	$nid = GetNewObjectId($type);
	if($nid == null)
		throw new Exception('Failed to assign new id to created object.');

	$element['old_id'] = (int)$element['id'];
	$element['id'] = (int)$nid;
}

function RewriteRefs($element,$type,$newids)
{
	//echo $element['ref'],(string)$type;
	if(!isset($element['ref'])) return;
	if($element['ref']>=0) return;
	if(isset($newids[(string)$type][(int)$element['ref']]))
	{
		//$element['old_id'] = $element['id'];
		$element['ref'] = $newids[(string)$type][(int)$element['ref']][0];
		return;
	}

	$nid = GetNewObjectId($type);
	if($nid == null)
		throw new Exception('Failed to assign new id to created object.');

	//$element['old_id'] = (int)$element['ref'];
	$element['ref'] = (int)$nid;
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

function LoadUntrustedXml($data)
{
	$diff = simplexml_load_string($data);
	if (!$diff)
	{
		$err = "Failed to parse XML upload diff.";
		foreach(libxml_get_errors() as $error) {
			$err = $err."\t".$error->message;
		}
		throw new InvalidArgumentException($err);
	}
	return $diff;
}

function ValidateUserXml($objects,$cid,$displayName,$userId)
{
	if($displayName==null) InvalidArgumentException("Null argument");
	if($userId==null) InvalidArgumentException("Null argument");

	foreach ($objects as $type => $object)
	{
		$objcid = $object['changeset'];
		if($cid != null)
		{
		if((int)$objcid!=(int)$cid)
			return "session-id-mismatch";
		}
		$object['user']=(string)$displayName;
		$object['uid']=(int)$userId;
	}
	return 1;
}

function ValidateVersions($method, $objects, $map)
{
	//echo "here";
	//print_r($objects);

	if(strcmp("create",$method)==0) return 1; //Don't check version for newly created objects
	foreach($objects as $type => $value) 
	{
		$currentObj = GetElementById($map, $type,$value['id']);
		if($currentObj==null)
		{
			return "object-not-found";
		}
		//echo $method." ".$type." ".$value['id']." ".$value['version']." ".$currentObj['version']."\n";
		if((int)$value['version']!=(int)$currentObj['version'])
		{
			//Version mismatch found
			return "version-mismatch,".(int)$value['version'].",".(int)$currentObj['version'].",".$type.",".$value['id'];
		}
	}
	return 1;
}

function AssignIdsToCreatedObjects(&$objects,$method,&$newids)
{
	if(strcmp($method,"create")==0)
	foreach($objects as $type => $value) 
	{
		//echo $type." ".$value['tempid']." ".$value['id'];

		RewriteIds($value,$type,$newids);
				
		foreach ($value as $k => $v)
		{
			if(strcmp($k,"nd")==0) RewriteRefs($v,"node",$newids);
			if(strcmp($k,"member")==0) RewriteRefs($v,$v['type'],$newids);
		}

		$value['version'] = 1;
		$newids[$type][(int)$value['old_id']] = array((int)$value['id'], (int)$value['version']);
		//fwrite($debug,$type." ".$value['tempid']." ".$value['id']);
	}

	if(strcmp($method,"modify")==0)
	foreach($objects as $type => $value) 
	{
		$value['version'] = (int)$value['version'] + 1;
		$newids[$type][(int)$value['id']] = array((int)$value['id'], (int)$value['version']);
		foreach ($value as $k => $v)
		{
			if(strcmp($k,"nd")==0) RewriteRefs($v,"node",$newids);
			if(strcmp($k,"member")==0) RewriteRefs($v,$v['type'],$newids);
		}
	}

	if(strcmp($method,"delete")==0)
	foreach($objects as $type => $value) 
	{
		$value['version'] = (int)$value['version'] + 1;
		$newids[$type][(int)$value['id']] = array(null, null);
	}
}

function ApplyChangeToDatabase($method,&$objects,&$map)
{
	if(strcmp($method,"create")==0)
	foreach($objects as $type => $value) 
	{
		//fwrite($debug,$type." ".$value['id']."\n");
		$newdata = $map->addChild($type);

		unset($value['old_id']);
		unset($value['action']);
		CloneElement($value, $newdata);
	}

	if(strcmp($method,"modify")==0)
	foreach($objects as $type => $value) 
	{
		//echo "modify ".$type." ".$value['id']."\n";
		//fwrite($debug,$type." ".$value['id']."\n");
		//$element = GetElementById($map, $type, $value['id']);
		RemoveElementById($map, $type, $value['id']);
		$element = $map->addChild($type);
		//print "found ".$value['id'];
		unset($value['action']);
		CloneElement($value, $element);
	}

	if(strcmp($method,"delete")==0)
	foreach($objects as $type => $value) 
	{
		unset($value['action']);
		//echo "delete ".$type." ".$value['id']."\n";
		RemoveElementById($map, $type, $value['id']);
	}

}


function ProcessSingleObject($method,$data,$displayName,$userId)
{
	$newids = array();
	$newids['node'] = array();
	$newids['way'] = array();
	$newids['relation'] = array();

	//******************************
	//Do initial validation
	//*****************************

	//$data = file_get_contents("changesets-open/126/create.xml");
	//$displayName = "TimSC";
	//$userId = 6809;

	$objects = LoadUntrustedXml($data);

	$cid = null;
	$type = null;
	foreach($objects as $ty => $obj)
	{
		$type = $ty;
		$cid = (int)$obj['changeset'];
	}

	$changesetIsOpen = CheckChangesetIsOpen($cid);
	if($changesetIsOpen==0) return "already-closed";
	if($changesetIsOpen==-1) return "no-such-changeset";
		
	if($cid==null) throw new Exception("Couldn't determine changeset id.");
	$fi = fopen("changesets-open/".(int)$cid."/data.xml","wt");
	if(!$fi) throw new Exception("Failed to open xml data file to save.");
	fwrite($fi, $data);

	$changesetIsOpen = CheckChangesetIsOpen($cid);
	if($changesetIsOpen==0) return "already-closed";
	if($changesetIsOpen==-1) return "no-such-changeset";
	
	//Check changeset and username is consistent in input
	$ret = ValidateUserXml($objects,$cid,$displayName,$userId);
	if($ret != 1) return $ret;

	//Check if user has permission to add to this changeset
	//TODO

	//***********************************
	//Validate the input against database
	//***********************************

	//Lock the database for writing
	$lock=GetWriteDatabaseLock();

	//Load database
	$map = simplexml_load_file("map.osm");
	if (!$map) throw new Exception("Failed to load internal database.");

	$ret = ValidateVersions($method, $objects, $map);
	if($ret != 1) return $ret;

	AssignIdsToCreatedObjects($objects,$method,$newids);

	$objects->saveXML("changesets-open/".(int)$cid."/uploadmod.xml");

	//**********************************
	// Apply the changes to the database
	//**********************************

	ApplyChangeToDatabase($method,$objects,$map);
	if ($map) $map->saveXML("map.osm");

	//*****************************
	//Generate ID diffs for output
	//*****************************
	
	//print_r($newids[$type]);
	$diffId = array_pop($newids[$type]);
	$newobjid = $diffId[0];
	$newversion = $diffId[1];
	if(strcmp($method,"create")==0)
		$result = $newobjid;
	else
		$result = $newversion;

	return $result;
}

function ChangesetUpload($cid,$data,$displayName,$userId)
{
	//$data = file_get_contents("changesets-open/112/upload.xml");
	//$map = simplexml_load_string("map.osm");
	$newids = array();
	$newids['node'] = array();
	$newids['way'] = array();
	$newids['relation'] = array();

	//******************************
	//Do initial validation
	//*****************************

	$changesetIsOpen = CheckChangesetIsOpen($cid);
	if($changesetIsOpen==0) return "already-closed";
	if($changesetIsOpen==-1) return "no-such-changeset";

	$fi = fopen("changesets-open/".$cid."/upload.xml","wt");
	//$debug = fopen("changesets-open/".$cid."/debug.txt","wt");
	fwrite($fi, $data);

	$diff = LoadUntrustedXml($data);

	//Check changeset and username is consistent in input
	foreach ($diff as $action => $objects)
	{
		$ret = ValidateUserXml($objects,$cid,$displayName,$userId);
		if($ret != 1) return $ret;
	}

	//Check if user has permission to add to this changeset
	//TODO

	//***********************************
	//Validate the input against database
	//***********************************

	//Lock the database for writing
	$lock=GetWriteDatabaseLock();

	//Load database
	$map = simplexml_load_file("map.osm");
	if (!$map)
	{
		throw new Exception("Failed to load internal database.");
	}

	//Check versions of objects are ok
	if(VERSION_VALIDATION)
	foreach($diff as $method => $objects) 
	{
		$ret = ValidateVersions($method, $objects, $map);
		if($ret != 1) return $ret;
	}

	//**********************************
	// Assign new object IDs
	//**********************************

	//Assign new ids to created objects
	foreach($diff->create as $key => $create) 
	{
		AssignIdsToCreatedObjects($create,"create",$newids);
	}

	foreach($diff->modify as $key => $modify) 
	{
		AssignIdsToCreatedObjects($modify,"modify",$newids);
	}

	foreach($diff->delete as $key => $delete) 
	{
		AssignIdsToCreatedObjects($delete,"delete",$newids);
	}

	$diff->saveXML("changesets-open/".$cid."/uploadmod.xml");
	
	//$diff = simplexml_load_file("changesets-open/".$cid."/uploadmod.xml");
	//$debug = fopen("changesets-open/".$cid."/debug.txt","wt");

	//**********************************
	// Apply the changes to the database
	//**********************************

	foreach($diff->create as $key => $create) 
	{
		ApplyChangeToDatabase("create",$create,$map);
	}

	foreach($diff->modify as $key => $modify) 
	{
		ApplyChangeToDatabase("modify",$modify,$map);
	}

	foreach($diff->delete as $key => $delete) 
	{
		ApplyChangeToDatabase("delete",$delete,$map);
	}

	if ($map) $map->saveXML("map.osm");
	
	//*****************************
	//Generate ID diffs for output
	//*****************************

	$result = '<diffResult generator="'.SERVER_NAME."\" version=\"0.6\">\n";
	foreach($newids as $type => $objects)
	{
		//echo $type,count($objects);
		foreach($objects as $oldid => $diff) 
		{
			list($new_id,$new_version) = $diff;
			$result = $result."<".$type." old_id=\"".$oldid.'"';
			if($new_id != null) $result = $result." new_id=\"".$new_id.'"';
			if($new_version != null) $result = $result." new_version=\"".$new_version.'"';
			$result = $result."/>\n";
		}
	}

	$result = $result."</diffResult>\n";
	//fwrite($debug,$result);
	return $result;
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

function ChangesetClose($cid)
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

function GetChangesetClosedTime($cid)
{
	$fname = "changesets-closed/".$cid."/closetime.txt";
	if(file_exists($fname))
	{
		return (int)file_get_contents($fname);
	}

	return null;
}

function ChangesetToXml($cid,$open)
{
	$path = "changesets-open/";
	if($open==false) $path = "changesets-closed/";
	
	$details = explode(";",file_get_contents($path.$cid."/details.txt"));
	$xml = simplexml_load_file($path.$cid."/putdata.xml");	

	//2010-10-02T11:43:37Z
	$da = date("c", (int)$details[2]);
	$out = '<changeset id="'.$cid.'" user="'.$details[0].'" uid="'.$details[1].'" created_at="'.$da;
	if($open==true) $out = $out.'" open="true"';
	if($open==false) $out = $out.'" open="false"';
	//$out = $out.' min_lon="7.4444867" min_lat="46.997839" max_lon="7.4774895" max_lat="47.014299"';
	$out = $out. '>'."\n";
	foreach ($xml as $type => $changsetxml)
	{
		foreach ($changsetxml as $type => $v)
		{
			//echo "here".$v['k'];
			if(strcmp($type,"tag")!=0) continue;
			$out = $out.'<tag k="'.$v['k'].'" v="'.$v['v'].'"/>'."\n";
		}
	}

	$out = $out.'</changeset>'."\n";
	return $out;
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

function GetChangesets($query)
{
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$out = $out.'<osm version="0.6" generator="'.SERVER_NAME.'">'."\n";

	$opencs = getDirectory('changesets-open');
	foreach($opencs as $cid)
	{ 
		if(isset($query['user']) and $query['user'] != GetChangesetUid($cid)) continue;
		$out = $out.ChangesetToXml($cid,1);
	}
	
	if(isset($query['open']) and strcmp($query['open'],"true")!=0)
	{
		$closedcs = getDirectory('changesets-closed');
		foreach($closedcs as $cid)
		{ 
			if(isset($query['user']) and $query['user'] != GetChangesetUid($cid)) continue;
			$out = $out.ChangesetToXml($cid,0);
		}
	}

	$out = $out.'</osm>'."\n";
	return $out;
}

function GetChangesetMetadata($cid)
{
	if(file_exists("changesets-open/".$cid."/putdata.xml"))
	{
		return file_get_contents("changesets-open/".$cid."/putdata.xml");
	}
	if(file_exists("changesets-closed/".$cid."/putdata.xml"))
	{
		return file_get_contents("changesets-closed/".$cid."/putdata.xml");
	}
	return null;
}

?>
