<?php

//*******************
//User Authentication
//*******************

function RequestAuthFromUser()
{
	header('WWW-Authenticate: Basic realm="'.SERVER_NAME.'"');
	header('HTTP/1.0 401 Unauthorized');
	echo 'Authentication Cancelled';
	exit;
} 

function RequireAuth()
{
	if (!isset($_SERVER['PHP_AUTH_USER'])) {
		RequestAuthFromUser();
	}

	$login = $_SERVER['PHP_AUTH_USER'];

	$ret = CallFuncByMessage(Message::CHECK_LOGIN,array($login, $_SERVER['PHP_AUTH_PW']));
	if($ret===-1) RequestAuthFromUser();
	if($ret===0) RequestAuthFromUser();
	if(is_array($ret)) list($displayName, $userId) = $ret;
	return array($displayName, $userId);
}

?>