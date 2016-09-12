<?php

require_once('dbutils.php');

$dbname = "test.db";
chdir(dirname(realpath (__FILE__)));
$dbh = new PDO('sqlite:'.$dbname);

$spec = new TableSpecSqlite("test");
$spec->Add("a","INTEGER",1);
$spec->Add("b","INTEGER");
$spec->Add("c","STRING");
$spec->Add("d","REAL");

$spec2 = new TableSpecSqlite("test");
$spec2->Add("a","INTEGER",1);
$spec2->Add("b","REAL");
$spec2->Add("c","STRING");
$spec2->Add("d","REAL");

$spec->DropTable($dbh);
echo $spec->TableExists($dbh)."\n";
$spec->CreateTable($dbh);
echo $spec->TableExists($dbh)."\n";

$sql = "INSERT INTO test (a,b,c,d) VALUES (11,22,'string',3.14);";
$ret = $dbh->exec($sql);
if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

echo $spec->MigrateSchemaByAlter($dbh)."\n";
echo $spec->SchemaMatches($dbh)."\n";
echo $spec2->MigrateSchema($dbh)."\n";
echo $spec2->SchemaMatches($dbh)."\n";
?>
