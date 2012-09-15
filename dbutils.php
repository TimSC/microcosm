<?php

function SqliteGetTables(&$dbh)
{
	$sql = "SELECT * FROM sqlite_master WHERE type='table';";
	$ret = $dbh->query($sql);
	if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	$out = array();
	foreach($ret as $row)
	{
		//print_r($row);
		array_push($out,$row['name']);
	}
	return $out;
}

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
	if($ret===false) {$err= $dbh->errorInfo();throw new Exception($createSql.",".$err[2]);}
}

function SqliteDropTableIfExists(&$dbh,$name)
{
	$eleExist = SqliteCheckTableExists($dbh,$name);
	if(!$eleExist) return;

	$sql = 'DROP TABLE ['.$name.'];';
	$ret = $dbh->exec($sql);
	if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
}

function TableToHtml($dbh,$table)
{
	$sql = "SELECT * FROM [".sqlite_escape_string($table)."];";
	$ret = $dbh->query($sql);
	if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	foreach($ret as $row)
		print_r($row);
}

//***********************
//Generic sqlite table
//***********************

class GenericSqliteTable implements ArrayAccess
{
	var $keys=array('key'=>'STRING');
	var $dbname='generictable.db';
	var $tablename="table";
	var $dbh = null;
	var $transactionOpen = 0;
	var $useTransactions = 1;

	function __construct()
	{
		chdir(dirname(realpath (__FILE__)));
		$this->dbh = new PDO('sqlite:'.$this->dbname);
		$this->InitialiseSchema();
	}

	function __destruct()
	{
		$this->EndTransaction();
	}

	function InitialiseSchema()
	{
		//Create table spec object
		$spec = new TableSpecSqlite($this->tablename);
		$count = 0;
		foreach($this->keys as $key=>$type)
		{
			$primaryKey = ($count == 0);
			$unique = ($count != 0);
			$spec->Add($key,$type,$primaryKey,$unique);
			$count ++;
		}
		$spec->Add("value","BLOB");

		//Action depends if table already exists
		if($spec->TableExists($this->dbh))
		{
			//Check existing table schema
			$match = $spec->SchemaMatches($this->dbh);
			if($match) return;

			//Experimental: migrate the schema
			//Back up your data before trying this!
			//$spec->MigrateSchema($dbh);
			//if($spec->SchemaMatches($dbh)) return;

			throw new Exception("Database schema does not match for table ".$this->tablename);
		}
		else
		{
			//Create table
			$spec->CreateTable($this->dbh);
		}
	}

	function BeginTransactionIfNotAlready()
	{
		if(!$this->transactionOpen)
		{
			$sql = "BEGIN;";
			$ret = $this->dbh->exec($sql);//Begin transaction
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
			$this->transactionOpen = 1;
		}
	}

	function EndTransaction()
	{
		if($this->transactionOpen)
		{
			$sql = "END;";
			$ret = $this->dbh->exec($sql);//End transaction	
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
			$this->transactionOpen = 0;
		}
	}

	function Purge()
	{
		SqliteDropTableIfExists($this->dbh, $this->tablename);
		$this->InitialiseSchema();
	}

	function ValueToSql($value,$type)
	{
		if(is_null($value)) return "null";
		//echo $value." ".$type."\n";
		if(strcasecmp($type,"STRING")==0) 
			return "'".sqlite_escape_string($value)."'";
		if(strcasecmp($type,"INTEGER")==0) 
			return "'".((int)$value)."'";
		if(strcasecmp($type,"REAL")==0) 
			return "'".((float)$value)."'";
		if(strcasecmp($type,"BLOB")==0) 
			return "'".sqlite_escape_string($value)."'";
		
		throw new Exception('Cannot cast unknown SQL type '.$type);
	}

	function UpdateRecord($key, $keyVal, $data)
	{

		//Get keys to specify separately in SQL
		$additionalKeys = array();
		foreach($this->keys as $keyExpected=>$type)
		{
			if(strcmp($keyExpected,$key)==0) continue;
			if(isset($data[$keyExpected]))
			{
				$additionalKeys[$keyExpected] = $data[$keyExpected];
				unset($data[$keyExpected]);
			}
			else $additionalKeys[$keyExpected] = null;
		}

		//Construct SQL
		$sql="UPDATE [".$this->tablename."] SET value='".sqlite_escape_string(serialize($data))."'";
		foreach($additionalKeys as $adKey => $adVal)
			$sql.= ", ".$adKey."=".$this->ValueToSql($adVal,$this->keys[$adKey]);

		$sql.=" WHERE ".$key."=";
		$sql.=$this->ValueToSql($keyVal,$this->keys[$key]);
		$sql.=";";

		//Execute SQL
		//echo $sql."\n";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		return $ret;
	}

	function CreateRecord($key, $keyVal = null, $data)
	{

		//Get keys to specify separately in SQL
		$additionalKeys = array();
		foreach($this->keys as $keyExpected=>$type)
		{
			if(isset($data[$keyExpected]))
			{
				$additionalKeys[$keyExpected] = $data[$keyExpected];
				unset($data[$keyExpected]);
			}
			else
				$additionalKeys[$keyExpected] = null;
		}
		$additionalKeys[$key] = $keyVal;

		//Construct SQL
		$sql="INSERT INTO [".$this->tablename."] (";
		$count = 0;
		foreach($additionalKeys as $adKey => $adVal)
		{
			if($count != 0) $sql.=", ";
			$sql .= $adKey;
			$count += 1;
		}
		$sql .= ", value";
		$sql .= ") VALUES (";

		$count = 0;
		foreach($additionalKeys as $adKey => $adVal)
		{
			if($count != 0) $sql.= ", ";
			$sql .= $this->ValueToSql($adVal,$this->keys[$adKey]);
			$count += 1;
		}
		$sql.=", '".sqlite_udf_encode_binary(serialize($data))."'";
		$sql.=");";
		//print_r(sqlite_udf_encode_binary(serialize($data)));

		//Execute SQL
		//echo $sql."\n";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		if(is_null($keyVal)) return $this->dbh->lastInsertId();
		return $keyVal;
	}

	public function Set($key, $keyVal, $data)
	{
		if($this->useTransactions)
			$this->BeginTransactionIfNotAlready();

		//echo "Attempting update db\n";
		$ret = $this->UpdateRecord($key, $keyVal, $data);
		if($ret===0)
		{
			//echo "ret".$ret."\n";
			//echo "Attempting create record in db\n";
			$createId = $this->CreateRecord($key, $keyVal, $data);
			if(is_null($createId)) 
				throw new Exception ("Failed to create record in database");			
			return $createId;
		}

		if($ret!==1) throw new Exception ("Failed to update record in database");
		return $keyVal;
	}
	
	public function Get($key, $keyVal)
	{
		$query = "SELECT * FROM [".$this->tablename."] WHERE ".$key."=".$this->ValueToSql($keyVal,$this->keys[$key]).";";
		//echo $query."\n";

		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach($ret as $row)
		{
			//print_r($row['value']);echo"\n";
			$record = unserialize(sqlite_udf_decode_binary($row['value']));
			foreach($this->keys as $exKey => $exType)
			{
				if(isset($row[$exKey]))
					$record[$exKey] = $row[$exKey];
				else
					$record[$exKey] = null;
			}
			//print_r($record);
			return $record;
		}
		return null;
	}

	public function IsRecordSet($key, $keyVal)
	{
		$query = "SELECT COUNT(value) FROM [".$this->tablename."] WHERE ".$key."=".$this->ValueToSql($keyVal,$this->keys[$key]).";";
		//echo $query."\n";
		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach($ret as $row) return($row[0]);
		return 0;
	}

	public function Clear($key, $keyVal)
	{
		if($this->useTransactions)
			$this->BeginTransactionIfNotAlready();

		$sql = "DELETE FROM [".$this->tablename."] WHERE ".$key."=".$this->ValueToSql($keyVal,$this->keys[$key]).";";

		//Execute SQL
		//echo $sql."\n";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		return $ret;
	}

	//*******************
	//Overload operators
	//*******************

	function offsetExists($name) {
		//echo 'offsetExists'.$name."\n";
		$getkeys = array_keys($this->keys);
		return $this->IsRecordSet($getkeys[0],$name);
	}
	function offsetGet($name) {
		//echo 'offsetGet'.$name."\n";	
		$getkeys = array_keys($this->keys);
		return $this->Get($getkeys[0],$name);
	}
	function offsetSet($name, $id) {
		//echo 'offsetSet'.$name.",".$id."\n";
		$getkeys = array_keys($this->keys);
		return $this->Set($getkeys[0],$name,$id);
	}
	function offsetUnset($name) {
		//echo 'offsetUnset'.$name."\n";
		$getkeys = array_keys($this->keys);
		return $this->Clear($getkeys[0],$name);
	}

	function GetKeys($keyName=null)
	{
		if(is_null($keyName))
		{
			$getkeys = array_keys($this->keys);
			$keyName = $getkeys[0];
		}

		$query = "SELECT ".$keyName." FROM ".$this->tablename.";";
		//echo $query."\n";
		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$out = array();
		foreach($ret as $row)
		{
			array_push($out,$row[$keyName]);
			//print_r($row);
		}
		return $out;
	}
}


//**************************************
//Schema Checking and modification class
//**************************************

class TableSpecSqlite
{
	var $name = null;
	var $cols = array();

	function __construct($name)
	{
		$this->name = $name;
	}

	//Define a table column
	function Add($name, $type, $primaryKey=0, $unique = 0)
	{
		array_push($this->cols,array($name,$type,$primaryKey,$unique));
	}

	function GetColumnDef($col)
	{
		list($name,$type,$primaryKey,$unique) = $col;
		$sql = sqlite_escape_string($name)." ".sqlite_escape_string($type);
		if ($primaryKey) $sql .= " PRIMARY KEY";
		if ($unique) $sql .= " UNIQUE";
		return $sql;
	}

	function CreateTable(&$dbh,$ifNotExists=1)
	{
		if($ifNotExists and $this->TableExists($dbh)) return 0;

		$sql = 'CREATE TABLE '.sqlite_escape_string($this->name).' (';
		$count = 0;
		foreach($this->cols as $col)
		{
			if($count > 0) $sql .= ", ";
			$sql.= $this->GetColumnDef($col);
			$count ++;
		}

		$sql .= ');';
		//echo $sql;
		$ret = $dbh->exec($sql);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	}
	
	function DropTable(&$dbh,$ifExists=1,$tableName=null)
	{
		if(is_null($tableName)) $tableName = $this->name;
		if($ifExists and !$this->TableExists($dbh,$tableName)) return 0;

		$sql = 'DROP TABLE '.sqlite_escape_string($tableName).';';
		$ret = $dbh->exec($sql);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	}

	function TableExists(&$dbh, $tablename=null)
	{
		//Check if table exists
		if(is_null($tablename)) $tablename = sqlite_escape_string($this->name);
		$sql = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='".$tablename."';";
		$ret = $dbh->query($sql);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$tableExists = 0;
		foreach($ret as $row)
			$tableExists = ($row[0] > 0);
		return $tableExists;
	}

	function SchemaMatches(&$dbh)
	{
		$sql = "PRAGMA table_info(".sqlite_escape_string($this->name).");";
		$ret = $dbh->query($sql);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$count =0;
		$differenceFound = 0;
		$existing = array();		

		foreach($ret as $row)
		{
			array_push($existing,$row);
			if((int)$row['cid'] >= count($this->cols)) $differenceFound = 1;
			list($name,$type,$primaryKey,$unique) = $this->cols[(int)$row['cid']];

			//print_r($row);
			if($row['name'] != $name) $differenceFound = 2;
			if($row['type'] != $type) $differenceFound = 3;
			//echo $row['pk']." ".$primaryKey.",";
			if((int)$row['pk'] != ($primaryKey)) $differenceFound = 4;

			$count ++;
		}
		if($count != count($this->cols)) $differenceFound = 5;
	
		if($differenceFound != 0)
		{
			//print_r($differenceFound);
			return 0;
		}
		return 1;
	}

	function MigrateSchemaByAlter(&$dbh)
	{
		//Check schema really is different
		if($this->SchemaMatches($dbh)) return 0;

		//Existing columns must match
		//Return value of -1 means ALTER cannot be used to migrate
		$sql = "PRAGMA table_info(".sqlite_escape_string($this->name).");";
		$ret = $dbh->query($sql);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$count =0;
		foreach($ret as $row)
		{
			if((int)$row['cid'] >= count($this->cols)) return -1;
			list($name,$type,$primaryKey,$unique) = $this->cols[(int)$row['cid']];
			if($row['name'] != $name) return -1;
			if($row['type'] != $type) return -1;
			if((int)$row['pk'] != $primaryKey) return -1;
			$count ++;
		}

		//Add additional columns
		for($i=$count;$i < count($this->cols);$i++)
		{
			$col = $this->cols[$i];
			list($name,$type,$primaryKey,$unique) = $col;
			if($primaryKey or $unique) return -1; //Cannot add a new indexed column
			$sql = "ALTER TABLE ".sqlite_escape_string($this->name)." ADD COLUMN ".$this->GetColumnDef($col).";";
			//echo $sql;
			$ret = $dbh->exec($sql);
			if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		}
	
		//Check migration result
		if(!$this->SchemaMatches($dbh)) throw new Exception("Database migration failed");

		return 1;
	}

	function CountExistingRows(&$dbh)
	{
		//Get number of rows
		$query = "SELECT COUNT(*) FROM [".sqlite_escape_string($this->name)."];";
		$ret = $dbh->query($query);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$numRows = null;
		foreach($ret as $row)
		{
			$numRows = $row[0];
		}
		return $numRows;
	}

	function MigrateSchemaByRecreate(&$dbh)
	{
		//Check schema really is different
		if($this->SchemaMatches($dbh)) return 0;

		//Check temporary table doesn't already exist
		if($this->TableExists($dbh,"migrate_table")) 
			throw new Exception("Migrate table should not already exist");

		$numRowsBefore = $this->CountExistingRows($dbh);

		//Start transaction
		$sql = "BEGIN;";
		$ret = $dbh->exec($sql);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		//Rename table
		$sql = "ALTER TABLE [".sqlite_escape_string($this->name)."] RENAME TO migrate_table;";
		$ret = $dbh->exec($sql);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		//Recreate empty table
		$this->CreateTable($dbh,0);

		//Copy data from temp to new table
		$query = "SELECT * FROM migrate_table;";
		$ret = $dbh->query($query);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach($ret as $row)
		{
			$sql = "INSERT INTO [".sqlite_escape_string($this->name)."] (";
			$valueStr = "(";
			$count = 0;
			foreach($this->cols as $col)
			{
				list($name,$type,$primaryKey,$unique) = $col;
				if(isset($row[$name]))
				{
					if($count>0) {$sql.=", "; $valueStr.=", ";}
					$sql .= $name;
					if($type=="REAL" or $type=="INTEGER")
					{
						if($type=="REAL") $valueStr .= sqlite_escape_string((float)$row[$name]);
						if($type=="INTEGER") $valueStr .= sqlite_escape_string((int)$row[$name]);
					}
					else
						$valueStr .= "'".sqlite_escape_string($row[$name])."'";
					$count ++;
				}
			}
			$sql .= ") VALUES ".$valueStr.");";
			//echo $sql;
			$ret = $dbh->exec($sql);
			if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		}

		//Drop the old table
		$this->DropTable($dbh,0,"migrate_table");

		//End transaction
		$sql = "END;";
		$ret = $dbh->exec($sql);
		if($ret===false) {$err= $dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		//Check migration result
		if(!$this->SchemaMatches($dbh)) throw new Exception("Database migration failed");

		//Count rows to check migration
		$numRowsAfter = $this->CountExistingRows($dbh);
		if($numRowsBefore != $numRowsAfter)
			throw new Exception("Number of rows did not match ".$numRowsBefore." vs. ".$numRowsAfter);

		return 1;
	}

	function MigrateSchema(&$dbh)
	{
		$ret = $this->MigrateSchemaByAlter($dbh);
		if($ret != -1) return $ret; //Ret value of -1 means "unable"

		//Try recreating table
		return $this->MigrateSchemaByRecreate($dbh);
	}
}

?>
