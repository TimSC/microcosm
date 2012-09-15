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


//***********************
//Generic sqlite table
//***********************

class GenericSqliteTable implements ArrayAccess
{
	var $keys=array('key'=>'STRING');
	var $dbname='generictable.db';
	var $tablename="table";
	var $dbh = null;

	function __construct()
	{
		chdir(dirname(realpath (__FILE__)));
		$this->dbh = new PDO('sqlite:'.$this->dbname);
		$this->InitialiseSchema();
	}

	function InitialiseSchema()
	{
		$sql="CREATE TABLE ".$this->tablename." (";
		$keyCount = 0;
		foreach($this->keys as $key=>$type)
		{
			if($keyCount != 0) $sql.=", ";
			$sql.=$key." ".$type;
			if($keyCount == 0) $sql.=" PRIMARY KEY";
			else $sql.=" UNIQUE";
			$keyCount += 1;
		}
		$sql.=", value BLOB";
		$sql.=");";
		SqliteCheckTableExistsOtherwiseCreate($this->dbh,$this->tablename,$sql);
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
		$sql="UPDATE ".$this->tablename." SET value='".sqlite_escape_string(serialize($data))."'";
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
		$sql="INSERT INTO ".$this->tablename." (";
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
		$query = "SELECT * FROM ".$this->tablename." WHERE ".$key."=".$this->ValueToSql($keyVal,$this->keys[$key]).";";
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
		$query = "SELECT COUNT(value) FROM ".$this->tablename." WHERE ".$key."=".$this->ValueToSql($keyVal,$this->keys[$key]).";";
		//echo $query."\n";
		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach($ret as $row) return($row[0]);
		return 0;
	}

	public function Clear($key, $keyVal)
	{
		$sql = "DELETE FROM ".$this->tablename." WHERE ".$key."=".$this->ValueToSql($keyVal,$this->keys[$key]).";";

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


?>
