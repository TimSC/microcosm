<?php

require_once('config.php');
require_once('osmtypes.php');
require_once('fileutils.php');
require_once('dbutils.php');

function UserDbFactory()
{
	//return new UserDbFile();
	return new UserDbSqlite();
}

function CheckLogin($user,$password)
{
	$lock=GetReadDatabaseLock();
	$db = UserDbFactory();
	return $db->CheckLogin($user,$password);
}

function AddUser($displayName, $email, $password, $uid = NULL)
{
	if(!ALLOW_USER_REGISTRATION) return "disabled";
	$lock=GetWriteDatabaseLock();
	if(strlen($password)<MIN_PASSWORD_LENGTH) return "password-too-short";
	if(!isValidEmail($email)) return "email-not-valid";
	$db = UserDbFactory();
	return $db->AddUser($displayName, $email, $password, $uid);
}

function GetUserDetails($uid)
{
	$lock=GetReadDatabaseLock();
	if($uid == null)
		throw new InvalidArgumentException('Argument is null.');
	$displayName = null;
	$accountCreated = null;
	$db = UserDbFactory();
	$fields = $db->Get('uid',$uid);

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

	$db = new UserPrefsDbSqlite();

	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$out = $out.'<osm version="0.6" generator="'.SERVER_NAME.'">'."\n";
	if(isset($db[(int)$uid]))
	{
		$prefs = $db[(int)$uid]['prefs'];
		$out .= $prefs->ToXmlString();
	}
	else
	{
		$out = $out."<preferences>\n";
		//$out = $out.'<preference k="somekey" v="somevalue" />'."\n";
		$out = $out."</preferences>\n";
	}
	$out = $out."</osm>\n";
	return $out;

}

function SetUserPreferences($userId,$data)
{
	//Parse and validate
	$prefs = new UserPreferences();
	$prefs->FromXmlString($data);

	//Write to file
	$lock=GetWriteDatabaseLock();
	$db = new UserPrefsDbSqlite();
	$db[(int)$userId] = array('prefs'=>$prefs);
}

function SetUserPreferencesSingle($userId,$key,$value)
{
	$lock=GetWriteDatabaseLock();
	$fname = "userpreferences/".(int)$userId.".xml";

	$key = html_entity_decode($key);
	$value = html_entity_decode($value);

	$db = new UserPrefsDbSqlite();	
	if(isset($db[(int)$userId]))
	{
		$prefs = $db[(int)$userId]['prefs'];
	}
	else
	{
		$prefs = new UserPreferences();
	}

	if(count($prefs->data)+1>MAX_USER_PERFS)
		return "too-many-preferences";

	//Check key doesn't exist, otherwise fail and return
	//print_r($prefs);
	if(isset($prefs->data[$key]))
		return "key-already-exists";

	//Set key
	$prefs->data[$key] = $value;

	//Write to db
	$db[(int)$userId] = array('prefs'=>$prefs);

	return 1;
}

//**************************
//Store User DB in text file
//**************************

class UserDbFile
{
	function GetUser($uid)
	{
		chdir(dirname(realpath (__FILE__)));
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
		chdir(dirname(realpath (__FILE__)));
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

	function AddUser($displayName, $email, $password, $uid = null)
	{
		//Check email is not used
		//Check displayName is not used

		if(strpos($displayName,";")!==false) return "invalid-character";
		if(strpos($email,";")!==false) return "invalid-character";
		if(strpos($password,";")!==false) return "invalid-character";

		chdir(dirname(realpath (__FILE__)));
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
			if(!is_null($uid) and $f['uid'] == $uid) return "uid-taken";
		}
		
		if(is_null($uid)) $uid = $maxUid + 1;
		$fi = fopen(".users.txt","at");
		fwrite($fi,$email.";".$password.";".$displayName.";".$uid.";".date('c')."\n");

		return $uid;
	}

	function Dump()
	{
		chdir(dirname(realpath (__FILE__)));
		$users = explode("\n",file_get_contents(".users.txt"));
		$maxUid = null;
		$out = array();
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
			array_push($out,$f);
		}
		return $out;
	}

	function RemoveUser($uid)
	{
		chdir(dirname(realpath (__FILE__)));
		$users = $this->Dump();
		$fi = fopen(".users.txt","wt");
		foreach($users as $user)
		{
			if((int)$user['uid'] == $uid) continue;
			fwrite($fi,$user['userName'].";".$user['password'].";");
			fwrite($fi,$user['displayName'].";".$user['uid'].";".$user['accountCreated']."\n");		
		}
	}
}

//********************************
//User database stored in sqlite
//********************************

class UserDbSqlite extends GenericSqliteTable
{
	var $keys=array('uid'=>'INTEGER', 'userName'=>'STRING', 'displayName'=>'STRING');
	var $dbname='sqlite/users.db';
	var $tablename="users";

	function __construct()
	{
		GenericSqliteTable::__construct();
	}

	function GetUser($uid)
	{
		return $this->Get("uid",$uid);
	}

	function CheckLogin($login,$password)
	{
		$user = $this->Get("userName",$login);
		if(is_null($user)) return 1; 
		//print_r($user);
		return array($user['displayName'], $user['uid']);
	}

	function AddUser($displayName, $email, $password, $uid = null)
	{
		//Check email is not used
		//Check displayName is not used
		if($this->IsRecordSet('displayName',$displayName)) return "display-name-taken";
		if(!is_null($uid) and $this->IsRecordSet('uid',$uid)) return "uid-taken";
		if($this->IsRecordSet('userName',$email)) return "email-taken";

		$f['userName'] = $email;
		$f['password'] = $password;
		$f['displayName'] = $displayName;
		$f['uid'] = $uid;
		$f['accountCreated'] = date('c');
		$this->Set("uid",$uid,$f);
		
	}

	function Dump()
	{
		$ids = $this->GetKeys();
		$out = array();
		foreach($ids as $id)
			array_push($out, $this[$id]);
		return $out;
	}

	function RemoveUser($uid)
	{
		unset($this[$uid]);
	}
}

//********************************
//User database stored in sqlite
//********************************

class UserPrefsDbSqlite extends GenericSqliteTable
{
	var $keys=array('uid'=>'INTEGER');
	var $dbname='sqlite/userprefs.db';
	var $tablename="users";


}



?>
