<?php
//Handle log in
require_once('../requestprocessor.php');
require_once('../exportfuncs.php');

$login = Null;
if(isset($_SERVER['PHP_AUTH_USER'])) $login = $_SERVER['PHP_AUTH_USER'];
if(isset($options['user'])) $login = $options['user'];

$pass = Null;
if(isset($_SERVER['PHP_AUTH_PW'])) $pass = $_SERVER['PHP_AUTH_PW'];
if(isset($options['password'])) $pass = $options['password'];

list ($displayName, $userId) = RequireAuth($login,$pass);
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
