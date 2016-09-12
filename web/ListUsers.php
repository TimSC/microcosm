<?php
require_once('../userdetails.php');
if(!DEBUG_MODE) die('Page disabled unless in debug mode.');

$lock=GetReadDatabaseLock();
$db = UserDbFactory();
$users = $db->Dump();
echo "<html>";
echo "<table border='1'>";

$count = 0;
$cols = array();
foreach($users as $user)
{
	if($count == 0)
	{
		$cols = array_keys($user);
		echo "<tr>";
		foreach($user as $key=>$field)
		{
			if(strcmp($key,"password")==0) echo "<td>Password</td>";
			else echo "<td>".$key."</td>";
		}
		echo "</tr>\n";
	}

	echo "<tr>";
	foreach($cols as $col)
	{
		if(strcmp($col,"password")==0) echo "<td>Hidden</td>";
		else echo "<td>".$user[$col]."</td>";
	}
	echo "</tr>\n";
	$count += 1;
}

echo "</table>";
echo "</html>";
//print_r();


?>
