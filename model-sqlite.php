<?php

include_once('fileutils.php');
include_once('dbutils.php');
include_once('model-common.php');

class ElementTable
{
	var $latlon = 0;
	var $transactionOpen = 0;
	var $type = "element";

	function __construct($type, $name, $latlon = 0)
	{
		chdir(dirname(realpath (__FILE__)));

		//Check database schema and connection
		$this->dbh = new PDO('sqlite:'.$name);
		//chmod($name,0777);
		$this->latlon = $latlon;
		$this->type = $type;
		$this->InitialiseSchema();
	}

	function __destruct()
	{
		$this->FlushWrite();
	}

	function CheckTableExists($name)
	{
		return SqliteCheckTableExists($this->dbh,$name);
	}

	function CheckTableExistsOtherwiseCreate($name,$createSql)
	{
		return SqliteCheckTableExistsOtherwiseCreate($this->dbh,$name,$createSql);
	}

	function InitialiseSchema()
	{
		$sql = 'CREATE TABLE elements (i INTEGER PRIMARY KEY, id INTEGER';
		if($this->latlon) $sql .= ', lat REAL, lon REAL';
		$sql .= ', changeset INTEGER, user STRING, uid INTEGER, ';
		$sql .= 'visible INTEGER, timestamp INTEGER, version INTEGER';
		$sql .= ', current INTEGER, tags BLOB, nodes BLOB, ways BLOB, relations BLOB';
		$sql .= ');';
		$this->CheckTableExistsOtherwiseCreate("elements",$sql);
	}

	function ClearCurrentFlagInElementTable($id)
	{
		//Set existing historic versions to not current
		$sql = "UPDATE elements SET current=0 WHERE id=".(int)$id.";";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	}

	function CheckTransactionIsOpen()
	{
		if(!$this->transactionOpen)
		{
			$sql = "BEGIN;";
			$ret = $this->dbh->exec($sql);//Begin transaction
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
			$this->transactionOpen = 1;
		}
	}

	function InsertObjectIntoElementTable($el)
	{
		//Insert new data
		$insertsql = "INSERT INTO elements (i, id";
		if($this->latlon) $insertsql .= ", lat, lon";
		$insertsql .= ", changeset";
		$insertsql .= ", user";
		$insertsql .= ", uid";
		$insertsql .= ", visible, timestamp, version, current, tags, nodes, ways, relations) VALUES (";
		$insertsql .= "null, ";
		$insertsql .= (int)$el->attr['id'].", ";
		if($this->latlon) $insertsql .= (float)$el->attr['lat'].", ";
		if($this->latlon) $insertsql .= (float)$el->attr['lon'].", ";
		$insertsql .= (int)$el->attr['changeset'].", ";
		if(isset($el->attr['user'])) $insertsql .= "'".sqlite_escape_string((string)$el->attr['user'])."', ";
		else $insertsql .= "null, ";
		if(isset($el->attr['uid'])) $insertsql .= ((int)$el->attr['uid']).", ";
		else $insertsql .= "null, ";
		if(strcmp($el->attr['visible'],"true")==0) $insertsql .= "1, ";
		else $insertsql .= "0, ";
		if(isset($el->attr['timestamp']))
			$insertsql .= ((int)strtotime($el->attr['timestamp']).", ");
		else
			$insertsql .= ("null, ");
		$insertsql .= ((int)$el->attr['version']);
		$insertsql .= ", 1";
		$insertsql .= ", '".sqlite_escape_string(serialize($el->tags))."'";
		$insertsql .= ", '".sqlite_escape_string(serialize($el->nodes))."'";
		$insertsql .= ", '".sqlite_escape_string(serialize($el->ways))."'";
		$insertsql .= ", '".sqlite_escape_string(serialize($el->relations))."'";
		$insertsql .= ");\n";

		$ret = $this->dbh->exec($insertsql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($insertsql.",".$err[2]);}
		return $this->dbh->lastInsertId();
	}

	function Insert($el)
	{
		$this->CheckTransactionIsOpen();

		$this->ClearCurrentFlagInElementTable($el->attr['id']);
	
		$this->InsertObjectIntoElementTable($el);	
	}

	/*function DeleteLowLevel($id)
	{
		$deletesql = "DELETE FROM elements WHERE id=".(int)$id.";";
		$ret = $this->dbh->exec($deletesql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($deletesql.",".$err[2]);}
	}*/

	function FlushWrite()
	{
		if($this->transactionOpen)
		{
			$sql = "END;";
			$ret = $this->dbh->exec($sql);//End transaction	
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
			$this->transactionOpen = 0;
		}

	}

	public function Delete($el)
	{
		ElementTable::Insert($el);
	}

	function DbRowToObj($row)
	{
		//print_r($row);
		$el = OsmElementFactory($this->type);
		
		//Attributes
		$el->attr['id'] = (int)$row['id'];
		if(isset($row['lat'])) $el->attr['lat'] = (float)$row['lat'];
		if(isset($row['lon'])) $el->attr['lon'] = (float)$row['lon'];
		$el->attr['changeset'] = (int)$row['changeset'];
		if(isset($row['user'])) $el->attr['user'] = (string)$row['user'];
		if(isset($row['uid'])) $el->attr['uid'] = (int)$row['uid'];
		if((int)$row['visible']==1) $el->attr['visible'] = "true";
		else $el->attr['visible'] = "false";
		if(!is_null($row['timestamp'])) $el->attr['timestamp'] = date('c',(int)$row['timestamp']);
		$el->attr['version'] = (int)$row['version'];

		//Tags and members
		$el->tags = unserialize($row['tags']);
		$el->nodes = unserialize($row['nodes']);
		$el->ways = unserialize($row['ways']);
		$el->relations = unserialize($row['relations']);
		return $el;
	}

	public function GetElement($id, $version = null)
	{
		//$this->FlushWrite();

		$latestVerObj = null;
		$latestVer = null;

		$query = "SELECT * FROM elements WHERE id=".(int)$id."";
		if(!is_null($version)) $query .= " AND version=".(int)$version."";
		if(is_null($version)) $query .= " AND current=1";
		$query .= ";";

		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}

		foreach($ret as $row){
			//print_r($row);
			$obj = $this->DbRowToObj($row);

			//Found specifically requested version
			if(!is_null($version) and (int)$obj->attr['version'] == (int)$version)
				return $obj;

			//Filter results and retain the highest version
			if(is_null($latestVer) or (int)$obj->attr['version'] > $latestVer)
			{
				$latestVer = $obj->attr['version'];
				$latestVerObj = $obj;
			}
		}
	
		//Check if this object is in a non-deleted state
		if(is_null($version) and !is_null($latestVerObj))
			if(strcmp($latestVerObj->attr['visible'],"false")==0) return -2;

		return $latestVerObj;
	}

	public function GetElementHistory($id)
	{
		//$this->FlushWrite();
		$query = "SELECT * FROM elements WHERE id=".(int)$id."";
		$query .= ";";

		$ret = $this->dbh->query($query);
		$out = array();

		foreach($ret as $row){
			//print_r($row);
			$obj = $this->DbRowToObj($row);
			array_push($out,$obj);
		}
		return $out;
	}

	function ElementMatchesQuery(&$el, &$children)
	{
		//echo $el->GetType().$el->attr['id']."\n";
		foreach($children as $child)
		{
			if(!is_object($child)) throw new Exception("Child must be an object");
			if(strcmp($child->GetType(),"node")==0)
				foreach($el->nodes as $e)
				{
					//echo $e[0]."\n";
					if($e[0] == $child->attr['id']) return 1;
				}
			if(strcmp($child->GetType(),"way")==0)
				foreach($el->ways as $e)
					if($e[0] == $child->attr['id']) return 1;
			if(strcmp($child->GetType(),"relation")==0)
				foreach($el->relations as $e)
					if($e[0] == $child->attr['id']) return 1;
		}
		return 0;
	}

	public function GetElementsWithMembers(&$queryObjs)
	{
		//Do exhaustive search
		$sql = "SELECT * FROM elements WHERE current = 1 and visible = 1;";		
		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$out = array();

		foreach($ret as $row)
		{
			$obj = $this->DbRowToObj($row);
			$match = $this->ElementMatchesQuery($obj, $queryObjs);
			if($match) array_push($out, $obj);
		}

		return $out;
	}

	public function GetElementsInBbox($bbox)
	{
		if(!$this->latlon) throw new Exception("Position was not enabled for this object.");

		//print_r($bbox);
		$query = "SELECT * FROM elements" ;
		$query .= " WHERE lat > ".(float)$bbox[1];
		$query .= " and lat < ".(float)$bbox[3];
		$query .= " and lon < ".(float)$bbox[2]." and lon > ".(float)$bbox[0];
		$query .= " and visible=1 and current=1";
		$query .= ";";
		//echo $query;
		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}

		//print_r($this->dbh->errorInfo());
		$out = array();
		foreach($ret as $row)
		{
			array_push($out,$this->DbRowToObj($row));		
			//echo $row['id'];
		}
		return $out;
	}
	
	public function DropTableIfExists($name)
	{
		SqliteDropTableIfExists($this->dbh,$name);
	}

	public function Count()
	{
		$query = "SELECT COUNT(id) FROM elements WHERE current = 1;";
		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach($ret as $row)
			return $row[0];
		return null;
	}

	public function Purge()
	{
		$this->DropTableIfExists("elements");
		$this->InitialiseSchema();
	}

}

//****************************
//Model Storage
//****************************

class OsmDatabaseSqlite extends OsmDatabaseCommon
{
	function __construct()
	{
		$this->nodeTable = new ElementTable("node","sqlite/node.db",1);
		$this->wayTable = new ElementTable("way","sqlite/way.db");
		$this->relationTable = new ElementTable("relation","sqlite/relation.db");
	}

	function __destruct()
	{
		unset($this->nodeTable);
		unset($this->wayTable);
		unset($this->relationTable);
	}

	//******************
	//Utility functions
	//******************

	function CheckPermissions()
	{
		$filesToCheck=array('sqlite/node.db','sqlite/way.db','sqlite/relation.db');

		foreach($filesToCheck as $f)
		if(!is_writable($f))
		{
			return $f;
		}
		return 1;
	}

	//**********************
	//General main query
	//**********************

	public function GetNodesInBbox($bbox)
	{
		return $this->nodeTable->GetElementsInBbox($bbox);
	}
	
	public function GetParentWaysOfNodes(&$nodes)
	{
		return $this->wayTable->GetElementsWithMembers($nodes);
	}
	
	public function GetParentRelations(&$els)
	{
		return $this->relationTable->GetElementsWithMembers($els);
	}

	//**********************************
	//Get specific info from database
	//**********************************

	public function GetElementById($type,$id,$version=null)
	{
		return $this->GetInternalTable($type)->GetElement($id,$version);
	}
	
	public function GetElementFullHistory($type,$id)
	{
		return $this->GetInternalTable($type)->GetElementHistory($id);
	}

	//***********************
	//Modification functions
	//***********************

	public function GetInternalTable($type)
	{
		if(strcmp($type,"node")==0) return $this->nodeTable;
		if(strcmp($type,"way")==0) return $this->wayTable;
		if(strcmp($type,"relation")==0) return $this->relationTable;
	}

	public function CreateElement($type,$id,$el)
	{
		$el->attr['visible'] = "true";
		$this->GetInternalTable($type)->Insert($el);	
	}

	public function ModifyElement($type,$id,$el)
	{
		$el->attr['visible'] = "true";
		$this->GetInternalTable($type)->Insert($el);
		//print($this->GetInternalTable($type)->GetElement($id)->ToXmlString());
	}

	public function DeleteElement($type,$id,$el)
	{
		$el->attr['visible'] = "false";
		$this->GetInternalTable($type)->Delete($el);	
	}

	public function Purge()
	{
		$this->nodeTable->Purge();
		$this->wayTable->Purge();
		$this->relationTable->Purge();
	}

}

//*********************
//Planet Dump functions
//*********************

function DumpTable(&$table, &$fi, $writefunc)
{
	$query = "SELECT * FROM elements WHERE current=1 AND visible=1;";
	$ret = $table->dbh->query($query);
	if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
	foreach($ret as $row)
	{
		$obj = $table->DbRowToObj($row);
		call_user_func($writefunc,$fi,$obj->ToXmlString());
	}
}

function DumpSqliteDatabase($db, $outFilename)
{
	if(strcasecmp(substr($outFilename,strlen($outFilename)-4),".bz2")==0)
	{
		$fi = bzopen($outFilename,"w");
		$writefunc = 'bzwrite';
	}
	else
	{
		$fi = fopen($outFilename,"wt");
		$writefunc = 'fwrite';
	}

	call_user_func($writefunc,$fi,"<?xml version='1.0' encoding='UTF-8'?>\n");
	call_user_func($writefunc,$fi,"<osm version='0.6' generator=\"".SERVER_NAME."\">\n");
	DumpTable($db->nodeTable,$fi,$writefunc);
	DumpTable($db->wayTable,$fi,$writefunc);
	DumpTable($db->relationTable,$fi,$writefunc);
	call_user_func($writefunc,$fi,"</osm>\n");
}

?>
