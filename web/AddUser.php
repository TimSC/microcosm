<?php
require_once('../userdetails.php');
define("ALLOW_USER_ID_SPECIFY",false);

if(isset($_POST['commit']))
{
	$uid = null;
	if(isset($_POST['uid']) and is_numeric($_POST['uid']) and ALLOW_USER_ID_SPECIFY) 
		$uid = $_POST['uid'];
	//print_r($uid);
	$ret = AddUser($_POST['displayName'],$_POST['email'],$_POST['password'],$uid);
	if ($ret === true)
		echo "Done";
	else
		print_r($ret);
}
else
{
?>
<html>
<header><title>Add User</title></header>
<body>
<h1>Add User</h1>
<form method="post">
Email: <input type="text" name="email" /><br />
Display name: <input type="text" name="displayName" /><br />
<?php if(ALLOW_USER_ID_SPECIFY)
{ ?>
User ID: <input type="text" name="uid" /><br />
<?php } ?>
Password: <input type="password" name="password" /><br />
<input name="commit" type="submit" value="Submit" />
</form>
<?php 
//$db = UserDbFactory();
//echo "Count users:".($db->Count())."<br/>\n";
?>
</body>
</html>
<?php
}
?>
