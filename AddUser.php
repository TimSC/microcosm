<?php
require_once('userdetails.php');

if(isset($_POST['commit']))
{
	$ret = AddUser($_POST['displayName'],$_POST['email'],$_POST['password']);
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
Password: <input type="password" name="password" /><br />
<input name="commit" type="submit" value="Submit" />
</form>
</body>
</html>
<?php
}
?>
