<?php

require_once("config.php");
require_once("osmtypes.php");
require_once('dbutils.php');

function FactoryBboxDatabase()
{
	return new BboxDatabaseSqlite();
}

class BboxDatabaseSqlite
{
	var $dbh;
	var $tables;
	var $transactionOpen;
	var $bboxindex;

	function __construct()
	{
		chdir(dirname(realpath (__FILE__)));
		$this->dbh = new PDO('sqlite:sqlite/bbox.db');
		$this->UpdateTablesList();
		$this->transactionOpen = 0;
		$this->bboxindex = new BboxIndex();
	}

	function __destruct()
	{
		$this->EndTransaction();
	}

	function SanitizeKey($key)
	{
		$key = rawurlencode($key);
		$key = str_replace("_","%95",$key);//Encode underscores

		//Make case all lower, R*Tree seems to be case insensitive.
		//Without this fix, duplicate tables create errors on SQL CREATE.
		$key = strtolower($key);

		return $key;
	}

	function InitialiseSchemaForKey($key)
	{		
		$posTableName = $key."_pos";
		$dataTableName = $key."_data";
		$tablesChanged = 0;

		if(!in_array($posTableName,$this->tables))
		{
		//echo "Creating table ".$posTableName."\n";
		$sql="CREATE VIRTUAL TABLE [".$this->dbh->quote($posTableName);
		$sql.="] USING rtree(rowid,minLat,maxLat,minLon,maxLon);";
		SqliteCheckTableExistsOtherwiseCreate($this->dbh,$posTableName,$sql);
		//echo "Done.\n";
		$tablesChanged = 1;
		}
		if(!in_array($dataTableName,$this->tables))
		{
		//echo "Creating table ".$dataTableName."\n";
		$sql="CREATE TABLE [".$this->dbh->quote($dataTableName);
		$sql.="] (rowid INTEGER PRIMARY KEY,elementid STRING UNIQUE, value STRING, ";
		$sql.="type INTEGER, hasNodes INTEGER, hasWays INTEGER);";
		SqliteCheckTableExistsOtherwiseCreate($this->dbh,$dataTableName,$sql);
		//echo "Done.\n";
		$tablesChanged = 1;
		}
		if($tablesChanged)
			$this->UpdateTablesList();
	}

	function UpdateTablesList()
	{
		//Remember to ignore the ghost tables used by R*Tree
		$sql = "SELECT * FROM sqlite_master WHERE type='table';";
		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$this->tables = array();
		foreach($ret as $row)
		{
			if(strlen($row['name'])>5 and substr($row['name'],-5) == "_node") 
				continue;
			if(strlen($row['name'])>6 and substr($row['name'],-6) == "_rowid") 
				continue;
			if(strlen($row['name'])>7 and substr($row['name'],-7) == "_parent") 
				continue;
			array_push($this->tables,$row['name']);
		}
	}

	function GetStoredKeys()
	{
		$out = array();
		foreach($this->tables as $table)
		{
			if(substr($table,-5) == "_data") continue;
			array_push($out, substr($table,0,-4));
		}
		return $out;
	}
	
	function Purge()
	{	
		//echo "Purging bbox db\n";
		//print_r($this->tables);
		//exit(0);
		foreach($this->tables as $table)
			SqliteDropTableIfExists($this->dbh,$table);
		$this->bboxindex->Purge();
		$this->UpdateTablesList();
	}

	function GetTableSizes()
	{	
		$out = array();
		foreach($this->tables as $table)
		{
			$query = "SELECT count(*) FROM [".$this->dbh->quote($table)."];";
			$ret = $this->dbh->query($query);
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}

			foreach($ret as $row)
			{
				$out[$table]=$row[0];
			}
		}	
		return $out;
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

	function InsertRecord($key,$el,$bbox,$value)
	{
		$type = $el->GetType();
		$elementidStr = $type.'-'.$el->attr['id'];
		$posTableName = $key."_pos";
		$dataTableName = $key."_data";
		//Bbox input order: min_lon,min_lat,max_lon,max_lat
		
		$typeInt = null;
		if($type=="node") $typeInt = 1;
		if($type=="way") $typeInt = 2;
		if($type=="relation") $typeInt = 3;
		if(is_null($typeInt)) throw new Exception("Unknown type, can't convert to int");

		$sql = "INSERT INTO [".$this->dbh->quote($dataTableName)."] ";
		$sql .= "(rowid,elementid,value,type,hasNodes,hasWays) VALUES (null,?,?,?";
		$sql .= ",?";//hasNodes
		$sql .= ",?";//hasWays
		$sql.= ");";
		$sqlVals = array($elementidStr, $value, $typeInt, (int)(count($el->nodes)>0), (int)(count($el->ways)>0));

		$sth = $this->dbh->prepare($sql);
		if($sth===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$ret = $sth->execute($sqlVals);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$rowid = $this->dbh->lastInsertId();

		$sql = "INSERT INTO [".$this->dbh->quote($posTableName)."] (rowid,minLat,maxLat,minLon,maxLon) VALUES (";
		$sql .= $rowid.",".$bbox[1].','.$bbox[3].','.$bbox[0].','.$bbox[2].");";
		$ret = $this->dbh->exec($sql);
		//echo $sql."\n";
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	}

	function GetRowIdOfTable($table,$elementid)
	{
		$query = "SELECT rowid FROM [".$this->dbh->quote($table)."] WHERE elementid=?;";
		$sqlVals = array($elementid);

		$sth = $this->dbh->query($query);
		if($sth===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$ret = $sth->execute($sqlVals);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}

		foreach($sth->fetchAll() as $row)
		{
			return $row[0];
		}
		return null;
	}

	function RemoveElement($el)
	{
		//Check if element already exists, if so, return
		$elementidStr = $el->GetType().'-'.$el->attr['id'];
		//echo $elementidStr." ".isset($this->bboxindex[$elementidStr])."\n";
		$index = $this->bboxindex[$elementidStr];
		if(is_null($index)) return;
		$keys = $index['tags']; //Clear from tables as specified in the bbox index
		//$keys = $this->GetStoredKeys(); //Clear from all tables - SLOW
		//print_r($keys);

		//Go through tables and remove element
		foreach($keys as $key)
		{
			$posTableName = $key."_pos";
			$dataTableName = $key."_data";

			$row = $this->GetRowIdOfTable($dataTableName, $elementidStr);
			if(is_null($row)) continue;

			$sql = "DELETE FROM [".$this->dbh->quote($posTableName)."] WHERE rowid=".$row.";";
			$ret = $this->dbh->exec($sql);
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

			$sql = "DELETE FROM [".$this->dbh->quote($dataTableName)."] WHERE rowid=".$row.";";
			$ret = $this->dbh->exec($sql);
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		}

		//Remove element from index
		unset($this->bboxindex[$elementidStr]);
	}

	function Update($bboxModifiedEls,&$map,$verbose=0)
	{
		if($verbose>=1) echo "Updating bboxes...\n";
		$startTime = microtime(1);
		//Begin transaction
		$this->BeginTransactionIfNotAlready();
		$countInserts = 0;

		foreach($bboxModifiedEls as $t => $els) foreach($els as $el)
		{
			$type = $el->GetType();
			$id = $el->attr['id'];

			//Remove previous entry in database
			$this->RemoveElement($el);

			//Skip object if no tags
			if(count($el->tags)==0) continue;

			//Get bbox
			if($type!="node")
				$bbox = $map->GetBboxOfElement($type,$id);
			else
			{
				//Don't bother computing for node's, its the same as the node position
				//format: min_lon,min_lat,max_lon,max_lat
				$bbox = array($el->attr['lon'],$el->attr['lat'],$el->attr['lon'],$el->attr['lat']);
			}
			if(!is_array($bbox))
				throw new Exception("Bounding box should be array for ".$type." ".$id);

			//echo $type." ".$id;
			//print_r($bbox);
			//echo "\n";

			//Store in database
			//Record which keys are set in index table
			$eleInKeyTables = array();
			foreach($el->tags as $k => $v)
			{
				$k = $this->SanitizeKey($k);
				array_push($eleInKeyTables,$k);
			}
			$data = array();
			$data['tags'] = $eleInKeyTables;
			$elementidStr = $el->GetType().'-'.$el->attr['id'];
			$this->bboxindex[$elementidStr] = $data;

			//Add element to individual key tables
			foreach($el->tags as $k => $v)
			{
				$k = $this->SanitizeKey($k);

				//echo $k."\n";
				$this->InitialiseSchemaForKey($k);				
				$this->InsertRecord($k,$el,$bbox,$v);
				$countInserts ++;
			}

		}
		$timeDuration = microtime(1) - $startTime;
		if($verbose>=1) echo "done in ".$timeDuration." sec. Inserts=".$countInserts;
		if($verbose>=1 and $timeDuration > 0.0) echo " (".(float)$countInserts/(float)$timeDuration." /sec)";
		if($verbose>=1) echo "\n";
	}

	function QueryXapi($type=null, $bbox=null, $key, $value=null, $maxRecords=MAX_XAPI_ELEMENTS)
	{
		$keyExp = explode("|",$key);
		$out = array();
		foreach($keyExp as $keySingle)
		{
			$ret = $this->QueryXapiSingle($type,$bbox,$keySingle,$value,$maxRecords);
			foreach($ret as $i)
				if(!in_array($i,$out)) array_push($out,$i);
			if(count($out)>$maxRecords)
				return $out;
		}
		return $out;
	}

	function QueryXapiSingle($type=null, $bbox=null, $key, $value=null, $maxRecords=MAX_XAPI_ELEMENTS)
	{
		$keys = $this->GetStoredKeys();
		//print_r($keys);
		$keySanitized = $this->SanitizeKey($key);

		//Check this table exists
		if(!in_array($keySanitized,$keys))
		{
			return array(); //Empty result
		}
		
		//Query table
		$posTableName = $keySanitized."_pos";
		$dataTableName = $keySanitized."_data";
		if(is_null($bbox)) $bbox = array(-180,-90,180,90);

		$query = "SELECT elementid,value FROM [".$this->dbh->quote($posTableName);
		$query .= "] INNER JOIN [".$this->dbh->quote($dataTableName);
		$query .= "] ON [".$this->dbh->quote($posTableName);
		$query .= "].rowid=[".$this->dbh->quote($dataTableName)."].rowid";
		$query .= " WHERE minLat > ?";
		$query .= " and maxLat < ?";
		$query .= " and maxLon < ? and minLon > ?";
		$sqlVals = array((float)$bbox[1], (float)$bbox[3], (float)$bbox[2], (float)$bbox[0]);

		if(!is_null($value))
		{
			$valueExp = explode("|",$value);
			$query .= " and (value=?";
			array_push($sqlVals, $valueExp[0]);
			for($i=1;$i<count($valueExp);$i++)
			{
				$query .= " or value=?";
				array_push($sqlVals, $valueExp[$i]);
			}
			$query .= ")";
		}

		if($type=="node") $query .= " and type=1";
		if($type=="way") $query .= " and type=2";
		if($type=="relation") $query .= " and type=3";
		if(!is_null($maxRecords))
		{	
			$query .= " LIMIT 0,?";
			array_push($sqlVals, (int)$maxRecords);
		}
		$query .= ";";

		$sth = $this->dbh->prepare($query);
		if($sth===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$ret = $sth->execute($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}

		$out = array();

		foreach($sth->fetchAll() as $row)
		{
			//$expId = explode("-",$row['elementid']);
			//$type = $expId[0];
			//$id = (int)$expId[1];
			//$value = $row['value'];
			//array_push($out,array($type,$id,$value));
			array_push($out,$row['elementid']);
		}
		//print_r($out);
		return $out;
	}

}

class BboxIndex extends GenericSqliteTable
{
	var $keys=array('id'=>'STRING');
	var $dbname='sqlite/bboxindex.db';
	var $tablename="bboxindex";

}

function ModelBboxEventHandler($eventType, $content, $listenVars)
{
	global $xapiGlobal;
	if($xapiGlobal === Null)
		$xapiGlobal = new BboxDatabaseSqlite();

	if($eventType === Message::XAPI_QUERY)
	{
		return $xapiGlobal->QueryXapi($content[0], $content[1], $content[2], $content[3]);
	}

	if($eventType === Message::CREATE_ELEMENT)
	{
	}

	if($eventType === Message::MODIFY_ELEMENT)
	{
	}

	if($eventType === Message::DELETE_ELEMENT)
	{
	}

	if($eventType === Message::SCRIPT_END)
	{
		unset($xapiGlobal);
		$xapiGlobal = Null;
	}

}

?>
