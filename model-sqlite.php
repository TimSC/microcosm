<?php

require_once('fileutils.php');
require_once('dbutils.php');
require_once('model-common.php');

class ElementTable
{
	var $latlon = 0;
	var $transactionOpen = 0;
	var $type = "element";

	function __construct($type, $name, $latlon = 0)
	{
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
		$sql .= ', current INTEGER, tags BLOB, members BLOB';
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
		$invertVals = array();
		$insertsql = "INSERT INTO elements (i, id";
		if($this->latlon) $insertsql .= ", lat, lon";
		$insertsql .= ", changeset";
		$insertsql .= ", user";
		$insertsql .= ", uid";
		$insertsql .= ", visible, timestamp, version, current, tags, members) VALUES (";
		$insertsql .= "null, ";
		$insertsql .= (int)$el->attr['id'].", ";
		if($this->latlon) $insertsql .= (float)$el->attr['lat'].", ";
		if($this->latlon) $insertsql .= (float)$el->attr['lon'].", ";
		$insertsql .= (int)$el->attr['changeset'].", ";
		if(isset($el->attr['user']))
		{
			$insertsql .= "?, ";
			array_push($invertVals, (string)$el->attr['user']);
		}
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
		$insertsql .= ", ?";
		$insertsql .= ", ?";
		$insertsql .= ");\n";
		array_push($invertVals,serialize($el->tags));
		array_push($invertVals,serialize($el->members));

		$qry = $this->dbh->prepare($insertsql);
		$ret = $qry->execute($invertVals);
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
		$this->Insert($el);
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
		$el->members = unserialize($row['members']);
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

	public function Dump($callback)
	{
		//Dump all non-deleted elements to a callback function
		//Warning: this could take a while
		$query = "SELECT * FROM elements WHERE current=1 AND visible=1;";

		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}

		foreach($ret as $row){
			//print_r($row);
			$obj = $this->DbRowToObj($row);

			//Verify latest version is used
			$query = "SELECT version FROM elements WHERE id=".$obj->attr['id'].";";
			$verret = $this->dbh->query($query);
			if($verret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
			$latest = null;
			foreach($verret as $verrow)
			{
				if($latest===null or $verrow['version'] > $latest)
					$latest = $verrow['version'];				
			}
			if($latest != $obj->attr['version'])
			{
				print "Warning: version found is not the latest obj v".$latest." vs. v".$obj->attr['version']."\n";
				continue;
			}

			call_user_func($callback,$obj);
		}
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
				foreach($el->members as $e)
				{
					//echo $e[0].count($e)."\n";
					if($e[0] == "node" && $e[1] == $child->attr['id']) return 1;
				}
			if(strcmp($child->GetType(),"way")==0)
				foreach($el->members as $e)
					if($e[0] == "way" && $e[1] == $child->attr['id']) return 1;
			if(strcmp($child->GetType(),"relation")==0)
				foreach($el->members as $e)
					if($e[0] == "relation" && $e[1] == $child->attr['id']) return 1;
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
		$this->nodeTable = new ElementTable("node",PATH_TO_SQLITE_DB."node.db",1);
		$this->wayTable = new ElementTable("way",PATH_TO_SQLITE_DB."way.db");
		$this->relationTable = new ElementTable("relation",PATH_TO_SQLITE_DB."relation.db");
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
		$filesToCheck=array('node.db','way.db','relation.db');

		foreach($filesToCheck as $f)
		if(!is_writable(PATH_TO_SQLITE_DB.$f))
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
		//Get requested version
		$ret = $this->GetInternalTable($type)->GetElement($id,$version);
		if(is_null($ret))
		{
			//See if version 1 exists
			if(!is_null($this->GetInternalTable($type)->GetElement($id,1)))
				return -2; //Deleted
			//return -1 //Not implmented (not needed here)
			return 0; //Not found
		}
		return $ret;
	}
	
	public function GetElementFullHistory($type,$id)
	{
		return $this->GetInternalTable($type)->GetElementHistory($id);
	}

	public function Dump($callback)
	{
		$this->nodeTable->Dump($callback);
		$this->wayTable->Dump($callback);
		$this->relationTable->Dump($callback);
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
	if($ret===false) {$err= $table->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
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
