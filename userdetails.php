<?php

include_once('config.php');
include_once('osmtypes.php');

function GetUserDetails($uid)
{
	if($uid == null)
		throw new InvalidArgumentException('Argument is null.');
	$displayName = null;
	$accountCreated = null;
	$users = explode("\n",file_get_contents(".users.txt"));
	foreach($users as $user)
	{
		$fields = explode(";",$user);
		if(count($fields) < 4) continue;
		//print_r( $fields);

		if ((int)$uid==(int)$fields[3])
		{
			$displayName = $fields[2];
			$accountCreated = $fields[4];
			break;
		}
	}

	if(ENABLE_ANON_EDITS and $uid == ANON_UID)
	{

		$displayName = ANON_DISPLAY_NAME;
		$accountCreated = "2007-03-22T19:28:28Z";
	}

	if($displayName==null) return null;

	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$out = $out.'<osm version="0.6" generator="'.SERVER_NAME.'">'."\n";
	$out = $out.'  <user display_name="'.$displayName.'"';
	$out = $out." account_created=\"".$accountCreated."\"\n";
	$out = $out.' id="'.(int)$uid.'">'."\n";
	/*$out = $out.'    <description>http://www.sheerman-chase.org.uk/\nhttp://wiki.openstreetmap.org/index.php/User:TimSC</description>'."\n";
	$out = $out.'    <contributor-terms agreed="false" pd="false"/>'."\n";
	$out = $out.'    <home zoom="3" lat="51.248030855596" lon="-0.60195435165425"/>'."\n";
	$out = $out.'    <img href="http://api.openstreetmap.org/user/image/6809/CIMG3323verymini.jpg"/>'."\n";
	$out = $out.'    <languages>'."\n";
	$out = $out.'      <lang>en-GB</lang>'."\n";
	$out = $out.'      <lang>en</lang>'."\n";
	$out = $out.'    </languages>'."\n";*/
	$out = $out."  </user>\n";
	$out = $out."</osm>\n";
	
	return $out;

}

function GetUserPreferences($uid)
{
	if($uid == null)
		throw new InvalidArgumentException('Argument is null.');

	if(ENABLE_ANON_EDITS and $uid == ANON_UID)
	{
		$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$out = $out.'<osm version="0.6" generator="'.SERVER_NAME.'">'."\n";
		$out = $out."<preferences>\n";
		$out = $out."</preferences>\n";
		$out = $out."</osm>\n";
		return $out;
	}

	$fname = "userperferences/".(int)$uid.".xml";
	if(file_exists($fname))
	{
		$lock=GetReadDatabaseLock();
		$out = file_get_contents($fname);
		return $out;
	}
	else
	{
		$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$out = $out.'<osm version="0.6" generator="'.SERVER_NAME.'">'."\n";
		$out = $out."<preferences>\n";
		//$out = $out.'<preference k="somekey" v="somevalue" />'."\n";
		$out = $out."</preferences>\n";
		$out = $out."</osm>\n";
	}
	return $out;

}

function SetUserPreferences($userId,$data)
{
/*	//Validate XML
	$xml = simplexml_load_string($data);
	if (!$xml)
	{
		throw new Exception("Failed to parse XML.");
	}

	//Check key lengths and value lengths are ok
	//TODO	
	
	//Check for too many keys or malformed data
	//TODO
*/
	//Parse
	$prefs = new UserPreferences();
	$prefs->FromXmlString($data);

	//Write to file
	$lock=GetWriteDatabaseLock();
	$fname = "userpreferences/".(int)$userId.".xml";
	$fi = fopen($fname,"wt");
	fwrite($fi, '<osm>'.$prefs->ToXmlString().'</osm>');
}

function SetUserPreferencesSingle($userId,$key,$value)
{
	$lock=GetWriteDatabaseLock();
	$fname = "userpreferences/".(int)$userId.".xml";

	//$xml = simplexml_load_file($data);
	//if (!$xml)
	//{
	//	throw new Exception("Failed to parse XML.");
	//}

	$key = html_entity_decode($key);
	$value = html_entity_decode($value);

	$prefs = new UserPreferences();
	if(file_exists($fname))
	{
		$fi = fopen($fname,"rt");
		$prefs->FromXmlString(fread($fi,filesize($fname)));
		fclose($fi);
	}

	if(count($prefs->data)+1>MAX_USER_PERFS)
		return "too-many-preferences";

	//Check key doesn't exist, otherwise fail and return
	//print_r($prefs);
	if(isset($prefs->data[$key]))
		return "key-already-exists";

	//Set key
	$prefs->data[$key] = $value;

	//Write to file
	$fi = fopen($fname,"wt");
	fwrite($fi, '<osm>'.$prefs->ToXmlString().'</osm>');
	clearstatcache($fname);

	return 1;
}

?>
