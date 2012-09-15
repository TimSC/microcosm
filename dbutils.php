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

function SqliteCheckTableExistsOtherwiseCreate(&$dbh,$name,$createSql)
{
	//If node table doesn't exist, create it
	if(SqliteCheckTableExists($dbh,$name)) return;

	$ret = $dbh->exec($createSql);
	if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
}

function SqliteDropTableIfExists(&$dbh,$name)
{
	$eleExist = SqliteCheckTableExists($dbh,$name);
	if(!$eleExist) return;

	$sql = 'DROP TABLE '.$name.';';
	$ret = $dbh->exec($sql);
	if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
}

?>
