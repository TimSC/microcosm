<?php
require_once('config.php');

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

function RequireAuth($login, $pass)
{
	if ($login === Null) {
		RequestAuthFromUser();
	}

	$ret = CallFuncByMessage(Message::CHECK_LOGIN,array($login, $pass));
	if($ret===-1) RequestAuthFromUser();
	if($ret===0) RequestAuthFromUser();
	if(is_array($ret)) list($displayName, $userId) = $ret;
	return array($displayName, $userId);
}

?>
