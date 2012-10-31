<?php
require_once('config.php');
require_once('oauthfuncs.php');
//*******************
//User Authentication
//*******************

function GetUsernameAndPassword()
{
	global $PROG_ARG_LONG;
	$options = getopt(PROG_ARG_STRING, $PROG_ARG_LONG);

	$login = Null;
	if(isset($_SERVER['PHP_AUTH_USER'])) $login = $_SERVER['PHP_AUTH_USER'];
	if(isset($options['user'])) $login = $options['user'];

	$pass = Null;
	if(isset($_SERVER['PHP_AUTH_PW'])) $pass = $_SERVER['PHP_AUTH_PW'];
	if(isset($options['password'])) $pass = $options['password'];

	return array($login, $pass);
}

function RequestAuthFromUser()
{
	CallFuncByMessage(Message::WEB_RESPONSE_TO_CLIENT, array("Authentication Cancelled",
			array('WWW-Authenticate: Basic realm="'.SERVER_NAME.'"','HTTP/1.0 401 Unauthorized')));
} 

function RequireAuth($login, $pass)
{
	//HTTP Authentication
	if($login !== null)
	{
		$ret = CallFuncByMessage(Message::CHECK_LOGIN,array($login, $pass));
		if($ret===-1) {RequestAuthFromUser(); return -1;}
		if($ret===0) {RequestAuthFromUser(); return -1;}
		if(is_array($ret)) list($displayName, $userId) = $ret;
		return array($displayName, $userId);
	}

	//OAuth Authentication
	$requestHeaders = getallheaders();
	if(isset($requestHeaders['Authorization']))
	{
		$oam = new OAuthMicrocosm();
		$ret = $oam->Verify();	
		if($ret[0] === True)
		{
			return array($ret[1], $ret[2]);
		}
	}

	//Prompt for HTTP username and password
	RequestAuthFromUser();
	return -1;
}

?>
