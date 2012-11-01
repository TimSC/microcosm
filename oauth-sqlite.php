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
		return $tokenStore[$tokenKey]['secret']; //Return token secret
	}

	return Null;
}

function OAuthEventLookupNonce($userInfo,$argExp)
{
	return False;
}

function OAuthEventNewAccessToken($userInfo,$argExp)
{
	$key = uniqid($more_entropy=True);
	$secret = uniqid($more_entropy=True);

	$tokenStore = new OAuthTokensSqlite();
	while(isset($tokenStore[$key])) //Prevent key collision
		$key = uniqid($more_entropy=True);

	//Set key
	$tokenStore[$key] = array('secret'=>$secret,'type'=>'access');

	return array($key,$secret);
}

function OAuthEventNewRequestToken($userInfo,$argExp)
{
	$key = uniqid($more_entropy=True);
	$secret = uniqid($more_entropy=True);

	$tokenStore = new OAuthTokensSqlite();
	while(isset($tokenStore[$key])) //Prevent key collision
		$key = uniqid($more_entropy=True);

	//Set key
	$tokenStore[$key] = array('secret'=>$secret,'type'=>'request');

	return array($key,$secret);
}

function OAuthEventGetUserFromAccessToken($userInfo,$argExp)
{
	$tokenStore = new OAuthTokensSqlite();
	$tokenKey = $argExp[0];

	if(isset($tokenStore[$tokenKey]))
	{
		return array("TimSC",1);
	}
}

?>
