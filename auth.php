<?php
require_once('config.php');

//*******************
//User Authentication
//*******************

function RequestAuthFromUser()
{
	CallFuncByMessage(Message::WEB_RESPONSE_TO_CLIENT, array("Authentication Cancelled",
			array('WWW-Authenticate: Basic realm="'.SERVER_NAME.'"','HTTP/1.0 401 Unauthorized')));
} 

function RequireAuth($login, $pass)
{
	if ($login === Null) {
		RequestAuthFromUser();
		return -1;
	}

	$ret = CallFuncByMessage(Message::CHECK_LOGIN,array($login, $pass));
	if($ret===-1) {RequestAuthFromUser(); return -1;}
	if($ret===0) {RequestAuthFromUser(); return -1;}
	if(is_array($ret)) list($displayName, $userId) = $ret;
	return array($displayName, $userId);
}

?>
