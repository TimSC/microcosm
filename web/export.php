<?php
//Handle log in
require_once('../requestprocessor.php');
require_once('../exportfuncs.php');

list ($displayName, $userId) = RequireAuth();
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
