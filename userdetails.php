<?php

include_once('config.php');
include_once('osmtypes.php');
require_once('fileutils.php');

function UserDbFactory()
{
	return new UserDbFile();
}

function CheckLogin($user,$password)
{
	$lock=GetReadDatabaseLock();
	$db = UserDbFactory();
	return $db->CheckLogin($user,$password);
}

function AddUser($displayName, $email, $password)
{
	if(!ALLOW_USER_REGISTRATION) return "disabled";
	$lock=GetWriteDatabaseLock();
	if(strlen($password)<MIN_PASSWORD_LENGTH) return "password-too-short";
	$db = UserDbFactory();
	return $db->AddUser($displayName, $email, $password);
}

function GetUserDetails($uid)
{
	$lock=GetReadDatabaseLock();
	if($uid == null)
		throw new InvalidArgumentException('Argument is null.');
	$displayName = null;
	$accountCreated = null;
	$db = UserDbFactory();
	$fields = $db->Get($uid);

	if(ENABLE_ANON_EDITS and $uid == ANON_UID)
	{
		$fields['displayName'] = ANON_DISPLAY_NAME;
		$fields['uid'] = ANON_UID;		
		$fields['accountCreated'] = "2007-03-22T19:28:28Z";
	}

	if($fields['displayName']==null) return null;

	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$out = $out.'<osm version="0.6" generator="'.SERVER_NAME.'">'."\n";
	$out = $out.'  <user display_name="'.$fields['displayName'].'"';
	$out = $out." account_created=\"".$fields['accountCreated']."\"\n";
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
	$lock=GetReadDatabaseLock();

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

//**************************
//Store User DB in text file
//**************************

class UserDbFile
{
	function Get($uid)
	{
		$users = explode("\n",file_get_contents(".users.txt"));
		$out = array();
		foreach($users as $user)
		{
			$fields = explode(";",$user);
			if(count($fields) < 4) continue;
			//print_r( $fields);
		
			if ((int)$uid==(int)$fields[3])
			{
				$out['userName'] = $fields[0];
				$out['password'] = $fields[1];
				$out['displayName'] = $fields[2];
				$out['uid'] = (int)$uid;
				$out['accountCreated'] = $fields[4];
				return $out;
			}
		}
		return null;
	}

	function CheckLogin($login,$password)
	{
		$users = explode("\n",file_get_contents(".users.txt"));
		$displayName = null;
		//foreach($users as $user)
		
		foreach($users as $user)
		{
			$fields = explode(";",$user);

			if (strcmp($login,$fields[0])==0)
			{
				if(strcmp($_SERVER['PHP_AUTH_PW'],$fields[1])!=0)
				{
					//echo "password didn't match";
					return 1;
				}

				$displayName = $fields[2];
				$userId = (int)$fields[3];
				return array($displayName, $userId);
			}
		}

		if(ENABLE_ANON_EDITS)
			return array(ANON_DISPLAY_NAME, ANON_UID);

		//echo "no such user";	
		return 1;

	}

	function AddUser($displayName, $email, $password)
	{
		//Check email is not used
		//Check displayName is not used

		if(!isValidEmail($email)) return "email-not-valid";
		if(strpos($displayName,";")!==false) return "invalid-character";
		if(strpos($email,";")!==false) return "invalid-character";
		if(strpos($password,";")!==false) return "invalid-character";

		$users = explode("\n",file_get_contents(".users.txt"));
		$maxUid = null;
		$f = array();
		foreach($users as $user)
		{
			$fields = explode(";",$user);
			if(count($fields) < 4) continue;
			//print_r( $fields);
		
			$f['userName'] = $fields[0];
			$f['password'] = $fields[1];
			$f['displayName'] = $fields[2];
			$f['uid'] = (int)$fields[3];
			$f['accountCreated'] = $fields[4];
			if(strcmp($displayName,$f['displayName'])==0) return "display-name-taken";
			if(strcmp($displayName,$f['userName'])==0) return "email-taken";
			if(is_null($maxUid) or $maxUid < $f['uid']) $maxUid = $f['uid'];
		}
		
		$uid = $maxUid + 1;
		$fi = fopen(".users.txt","at");
		fwrite($fi,$email.";".$password.";".$displayName.";".$uid.";".date('c')."\n");

		return $uid;
	}
}



?>
