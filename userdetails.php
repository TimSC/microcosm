<?php

include_once('config.php');

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





?>
