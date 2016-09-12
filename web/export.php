<?php
//Handle log in
require_once('../requestprocessor.php');
require_once('../exportfuncs.php');
require_once('../auth.php');

list($login, $pass) = GetUsernameAndPassword();
$authRet = RequireAuth($login,$pass);
if($authRet==-1)
{
	RequestAuthFromUser();
	CallFuncByMessage(Message::FLUSH_RESPONSE_TO_CLIENT,Null); 
	die();
}

list ($displayName, $userId) = $authRet;
$userDb = UserDbFactory();
$userData = $userDb->GetUser($userId);
$userAdmin = $userData['admin'];
if(!$userAdmin) die("Need administrator access");

set_time_limit(0);

$out = new OutWriteBuffer();

header("Content-type: application/x-bzip2");

Export($out);

echo bzcompress($out->buffer);

?>
