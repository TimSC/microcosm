<?php

require_once('dbutils.php');

class OAuthTokensSqlite extends GenericSqliteTable
{
	function __construct()
	{
		$this->dbname = "private/oauthtokens.db";
		chdir(dirname(realpath (__FILE__)));
		$this->dbh = new PDO('sqlite:'.$this->dbname);
		$this->InitialiseSchema();		
	}
}


function OAuthEventLookupConsumer($userInfo,$argExp)
{
	$consumerKey = $argExp[0];

	if($consumerKey == "AdCRxTpvnbmfV8aPqrTLyA") //JOSM
		return "XmYOiGY9hApytcBC3xCec3e28QBqOWz5g6DSb5UpE";

	if($consumerKey == "fiM1IoqnKJk4JCfcl63DA") //Potlatch 2
		return "7fYgJK9M4vB1CvBZ6jEsPGxYK9UD1hEnI6NqTxNGs";

	return Null;
}

function OAuthEventLookupToken($userInfo,$argExp)
{
	$tokenType = $argExp[0];
	$tokenKey = $argExp[1];

	$tokenStore = new OAuthTokensSqlite();
	if(isset($tokenStore[$tokenKey]))
	{
		//Verify type
		if ($tokenType !== Null and $tokenStore[$tokenKey]['type'] != $tokenType)
			return Null;

		return $tokenStore[$tokenKey]['secret']; //Return token secret
	}

	return Null;
}

function OAuthEventLookupNonce($userInfo,$argExp)
{
	//TODO Currently OAuth nonce is not checked, which prevents replay attacks
	return False;
}

function OAuthEventNewAccessToken($userInfo,$argExp)
{
	$key = hash("sha1",uniqid($more_entropy=True).mt_rand());
	$secret = hash("sha1",uniqid($more_entropy=True).mt_rand());
	$consumerKey = $argExp[0];
	$requestToken = $argExp[1];

	$tokenStore = new OAuthTokensSqlite();
	while(isset($tokenStore[$key])) //Prevent key collision
		$key = hash("sha1",uniqid($more_entropy=True).mt_rand());

	//Set key
	$tokenStore[$key] = array('secret'=>$secret,'type'=>'access','consumer'=>$consumerKey,
		'auth'=>True,
		'displayName'=>$requestToken['displayName'],
		'userId'=>$requestToken['userId']);

	return array($key,$secret);
}

function OAuthEventNewRequestToken($userInfo,$argExp)
{
	$key = hash("sha1",uniqid($more_entropy=True).mt_rand());
	$secret = hash("sha1",uniqid($more_entropy=True).mt_rand());
	$consumerKey = $argExp[0];

	$tokenStore = new OAuthTokensSqlite();
	while(isset($tokenStore[$key])) //Prevent key collision
		$key = hash("sha1",uniqid($more_entropy=True).mt_rand());

	//Set key
	$tokenStore[$key] = array('secret'=>$secret,'type'=>'request','consumer'=>$consumerKey);

	return array($key,$secret);
}

function OAuthEventGetInfoForToken($userInfo,$argExp)
{
	$tokenStore = new OAuthTokensSqlite();
	$tokenKey = $argExp[0];

	if(isset($tokenStore[$tokenKey]))
	{
		return $tokenStore[$tokenKey];
	}
	return Null;
}

function OAuthEventAuthRequestToken($userInfo,$argExp)
{
	$tokenStore = new OAuthTokensSqlite();
	$key = $argExp[0];
	$userId = $argExp[1];
	$username = $argExp[2];
	$displayName = $argExp[3];

	if(!isset($tokenStore[$key])) return False;
	$requestToken = $tokenStore[$key];
	if($requestToken['type'] != "request") return False;
	$requestToken["userId"] = $userId;
	$requestToken["username"] = $username;
	$requestToken["displayName"] = $displayName;
	$requestToken["auth"] = True;
	$tokenStore[$key] = $requestToken;
}


function OAuthEventUnAuthRequestToken($userInfo,$argExp)
{

}

?>
