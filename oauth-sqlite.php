<?php

/*class OAuthSqlite()
{
	function __construct()
	{
		
	}



}*/


function OAuthEventLookupConsumer($userInfo,$argExp)
{
	$consumerKey = $argExp[0];
	if($consumerKey == "AdCRxTpvnbmfV8aPqrTLyA") 
		return "XmYOiGY9hApytcBC3xCec3e28QBqOWz5g6DSb5UpE";
	return Null;
}

function OAuthEventLookupToken($userInfo,$argExp)
{
	$tokenType = $argExp[0];
	$token = $argExp[1];

	if($token=="requestkey") return "requestsecret";
	if($token=="accesskey") return "accesssecret";	
	return Null;
}

function OAuthEventLookupNonce($userInfo,$argExp)
{
	return False;
}

function OAuthEventNewAccessToken($userInfo,$argExp)
{
	return array("requestkey", "requestsecret");
	//return array(uniqid($more_entropy=True),uniqid($more_entropy=True));
}

function OAuthEventNewRequestToken($userInfo,$argExp)
{
	return array("accesskey", "accesssecret");
	//return array(uniqid($more_entropy=True),uniqid($more_entropy=True));	
}

function OAuthEventGetUserFromAccessToken($userInfo,$argExp)
{
	$tokenKey = $argExp[0];
	if($tokenKey == "accesskey")
	{
		return array("TimSC",1);
	}
}

?>
