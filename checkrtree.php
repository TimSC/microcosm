<?php

function SqliteCheckTableExists(&$dbh,$name)
{
	//Check if table exists
	$sql = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='".$name."';";
	$ret = $dbh->query($sql);
	if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	$tableExists = 0;
	foreach($ret as $row)
		$tableExists = ($row[0] > 0);
	return $tableExists;
}

function SqliteDropTableIfExists(&$dbh,$name)
{
	$eleExist = SqliteCheckTableExists($dbh,$name);
	if(!$eleExist) return;

	$sql = 'DROP TABLE ['.$name.'];';
	$ret = $dbh->exec($sql);
	if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
}

chdir(dirname(realpath (__FILE__)));
$dbh = new PDO('sqlite:rtreetest.db');
try
{
SqliteDropTableIfExists($dbh, "test");
}
catch (Exception $e) {
    echo 'Caught exception: ',  ($e->getMessage()), "\n";
}

$sql="CREATE VIRTUAL TABLE position USING rtree(id,minLat,maxLat,minLon,maxLon);";
$ret = $dbh->exec($sql);

echo "Result:\n";
if($ret===false) {$err= $dbh->errorInfo();echo ($sql.",".$err[2]."\n"); exit(0);}
echo "rtree ok\n";

?>
