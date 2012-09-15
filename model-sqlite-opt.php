<?php

include_once('fileutils.php');
include_once('model-sqlite.php');

define("DB_VERSION_VALIDATION",false);

class ElementTableOpt extends ElementTable
{

/*	function __construct($type, $name, $latlon = 0)
	{
	}

	function __destruct()
	{
	}
*/
	function InitialiseSchema()
	{
		//Position table using rtree
		if($this->latlon)
		{
			$sql="CREATE VIRTUAL TABLE position USING rtree(id,minLat,maxLat,minLon,maxLon);";
			$this->CheckTableExistsOtherwiseCreate("position",$sql);
		}
	
		$sql="CREATE TABLE idmap (elid INTEGER PRIMARY KEY,rowid INTEGER);";
		$this->CheckTableExistsOtherwiseCreate("idmap",$sql);
		$sql="CREATE TABLE nodechildren (id INTEGER PRIMARY KEY,parents BLOB);";
		$this->CheckTableExistsOtherwiseCreate("nodechildren",$sql);
		$sql="CREATE TABLE waychildren (id INTEGER PRIMARY KEY,parents BLOB);";
		$this->CheckTableExistsOtherwiseCreate("waychildren",$sql);
		$sql="CREATE TABLE relationchildren (id INTEGER PRIMARY KEY,parents BLOB);";
		$this->CheckTableExistsOtherwiseCreate("relationchildren",$sql);
		$sql="CREATE TABLE history (id INTEGER PRIMARY KEY,history BLOB);";
		$this->CheckTableExistsOtherwiseCreate("history",$sql);

		ElementTable::InitialiseSchema();
	}

	//************************************
	//Insert element into optimised tables
	//************************************

	function InsertObjectIntoPositionTable($el)
	{
		if(!$this->latlon) return;

		$id = $el->attr['id'];
		$lat = $el->attr['lat'];
		$lon = $el->attr['lon'];

		//Try to update existing position		
		$sql="UPDATE position SET minLat=".(int)$lat.",maxLat=".(int)$lat;
		$sql.=",minLon=".(int)$lon.",maxLon=".(int)$lon." WHERE id=".(int)$id.";";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		
		if($ret===0) //If it doesn't exist, insert new data
		{
		$insertsql = "INSERT INTO position (id,minLat,maxLat,minLon,maxLon) VALUES (";
		$insertsql .= (int)$id.", ";
		$insertsql .= (float)$lat.", ";
		$insertsql .= (float)$lat.", ";
		$insertsql .= (float)$lon.", ";
		$insertsql .= (float)$lon;
		$insertsql .= ");\n";

		$ret = $this->dbh->exec($insertsql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($insertsql.",".$err[2]);}
		}

	}

	function InsertObjectIntoIdMapTable($el,$rowid)
	{
		$id = $el->attr['id'];

		//Try to update existing position	
		$sql="UPDATE idmap SET rowid=".(int)$rowid." WHERE elid=".(int)$id.";";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		
		if($ret===0) //If it doesn't exist, insert new data
		{
		$insertsql = "INSERT INTO idmap (elid,rowid) VALUES (";
		$insertsql .= (int)$id.", ";
		$insertsql .= (float)$rowid;
		$insertsql .= ");\n";

		$ret = $this->dbh->exec($insertsql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($insertsql.",".$err[2]);}
		}		
	}

	function ClearCurrentFlagInElementTable($id)
	{
		$rowid = $this->GetRowIdOfElementCurrent($id);
		if(is_null($rowid)) return null;

		//Set current version to not current
		$sql = "UPDATE elements SET current=0 WHERE i=".(int)$rowid.";";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		if($ret!==1) throw new Exception("Error clearing current flag");
	}
	
	//*****************************
	//Get and update children links
	//*****************************

	function GetParentsForChildElement($type,$id)
	{
		//Get existing parents of child
		$query = "SELECT * FROM ".$type."children WHERE id = ".(int)$id.";";
		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$parents = null;
		foreach($ret as $row)
		{
			$parents = unserialize($row['parents']);
		}
		return $parents;
	}

	function SetParentsForChildElement($type,$childId,$parents,$exists)
	{
		//Get existing parents of child
		if($exists)
		{
			$sql = "UPDATE ".$type."children SET parents='";
			$sql .= sqlite_escape_string(serialize($parents))."' WHERE id=".(int)$childId.";";
		}
		else
		{
			$sql = "INSERT INTO ".$type."children (id,parents) VALUES (";
			$sql .= (int)$childId.",'".sqlite_escape_string(serialize($parents))."');";
		}

		//echo $sql."\n";
		//Delete entry for empty array
		//TODO

		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	}


	function AddParentForChild($type,$childId,$parentId)
	{
		//Get existing parents
		$parents = $this->GetParentsForChildElement($type,$childId);
		$found = !is_null($parents);
		if(is_null($parents)) $parents=array();

		//Append id to list
		//if(in_array($parentId,$parents)) return; //Duplicates SHOULD be retained
		array_push($parents,$parentId);

		//Update database
		$this->SetParentsForChildElement($type,$childId,$parents,$found);
	}

	function RemoveParentForChild($type,$childId,$parentId)
	{
		//echo "RemoveParentForChild".$childId." ".$parentId."\n";

		//Get existing parents
		$parents = $this->GetParentsForChildElement($type,$childId);
		$found = !is_null($parents);
		if(is_null($parents)) $parents=array();

		//Append id to list
		if(!in_array($parentId,$parents))
		{
			//print_r($parents);
			throw new Exception("Trying to remove non existant child link ".$childId." from ".$parentId.".");
		}
		$offset = array_search($parentId,$parents);
		//print_r($offset);
		if($offset===null) 
			throw new Exception("Trying to remove non existant child link ".$childId." from ".$parentId.".");
		unset($parents[$offset]);

		//Update database
		$this->SetParentsForChildElement($type,$childId,$parents,$found);
	}

	function AddChildrenLinksForElement($el)
	{
		if(!is_object($el)) throw new Exception ('Argument should be an object');

		foreach($el->nodes as $child)
			$this->AddParentForChild("node",$child[0],$el->attr['id']);
		foreach($el->ways as $child)
			$this->AddParentForChild("way",$child[0],$el->attr['id']);
		foreach($el->relations as $child)
			$this->AddParentForChild("relation",$child[0],$el->attr['id']);
	}

	function RemoveChildrenLinksForElement($el)
	{
		if(!is_object($el)) throw new Exception ('Argument should be an object');

		foreach($el->nodes as $child)
			$this->RemoveParentForChild("node",$child[0],$el->attr['id']);
		foreach($el->ways as $child)
			$this->RemoveParentForChild("way",$child[0],$el->attr['id']);
		foreach($el->relations as $child)
			$this->RemoveParentForChild("relation",$child[0],$el->attr['id']);
	}

	//**********************
	//History functions
	//**********************

	function GetHistoryRows($id)
	{
		$query = "SELECT history FROM history WHERE id = ".(int)$id.";";
		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach($ret as $row)
		{
			return unserialize($row['history']);
		}
		return null;
	}

	function AppendRowToHistory($el,$rowid)
	{
		//Get history
		$id = $el->attr['id'];
		$rowids = $this->GetHistoryRows($id);
		$found = 1;
		if(is_null($rowids)) {$found = 0; $rowids = array();}

		//Append row id
		$rowids[$el->attr['version']] = $rowid;

		//Write history
		if($found)
		{
			$sql = "UPDATE history SET history='".sqlite_escape_string(serialize($rowids));
			$sql .= "' WHERE id=".(int)$id.";";
		}
		else
		{
			$sql = "INSERT INTO history (id, history) VALUES (".(int)$id.",'";
			$sql .= sqlite_escape_string(serialize($rowids))."');";
		}

		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	}

	//***************************
	//Main modification functions
	//***************************

	public function Insert($el)
	{		
		$this->CheckTransactionIsOpen();
		$timers = array();
		$oldObj = $this->GetElement($el->attr['id']);

		//Check versions, only newer allowed
		if(DB_VERSION_VALIDATION and !is_null($oldObj) and $el->attr['version'] <= $oldObj->attr['version'])
		{
			$err = "Modified version must be newer for ".$el->GetType()." ".$el->attr['id'];
			$err .= " (".$el->attr['version']." vs. ".$oldObj->attr['version'].")";
			throw new Exception($err);
		}

		//Remove previous version data
		$startTimer = microtime(1);
		$this->ClearCurrentFlagInElementTable($el->attr['id']);
		$timers['clear current flag']=(microtime(1) - $startTimer);

		if(!is_null($oldObj))
		{
		$startTimer = microtime(1);
		$this->RemoveChildrenLinksForElement($oldObj);
		$timers['remove children']=(microtime(1) - $startTimer);
		}

		//Add new version to tables
		$startTimer = microtime(1);
		$rowId = $this->InsertObjectIntoElementTable($el);
		$timers['insert into element']=(microtime(1) - $startTimer);

		$startTimer = microtime(1);
		$this->InsertObjectIntoPositionTable($el);
		$timers['insert into position']=(microtime(1) - $startTimer);

		$startTimer = microtime(1);
		$this->InsertObjectIntoIdMapTable($el,$rowId);
		$timers['insert into idmap']=(microtime(1) - $startTimer);

		$startTimer = microtime(1);
		$this->AddChildrenLinksForElement($el);
		$timers['add children']=(microtime(1) - $startTimer);

		//Add element row to history
		$startTimer = microtime(1);
		$this->AppendRowToHistory($el,$rowId);
		$timers['update history']=(microtime(1) - $startTimer);

		//print_r($timers);
	}
/*
	function FlushWrite()
	{

	}
*/
	public function Delete($el)
	{

		$this->CheckTransactionIsOpen();
		$oldObj = $this->GetElement($el->attr['id']);
		//Check versions, only newer allowed
		if(DB_VERSION_VALIDATION and $el->attr['version'] <= $oldObj->attr['version'])
		{
			$err = "Modified version must be newer for ".$el->GetType()." ".$el->attr['id'];
			$err .= " (".$el->attr['version']." vs. ".$oldObj->attr['version'].")";
			throw new Exception($err);
		}
		
		if($this->latlon) 
		{
		//Delete from position table
		$sql = "DELETE FROM position WHERE id =".(int)$el->attr['id'].";";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		if($ret!==1) throw new Exception("Failed to remove element from position table");
		}

		//Delete children links
		if(!is_null($oldObj))
		{
		$startTimer = microtime(1);
		$this->RemoveChildrenLinksForElement($oldObj);
		$timers['remove children']=(microtime(1) - $startTimer);
		}

		//Delete from idmap table
		$sql = "DELETE FROM idmap WHERE elid =".(int)$el->attr['id'].";";
		//echo $sql."\n";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		if($ret!==1) throw new Exception("Failed to remove element from idmap table");

		//Add new version to element table
		$startTimer = microtime(1);
		$rowId = $this->InsertObjectIntoElementTable($el);
		$timers['insert into element']=(microtime(1) - $startTimer);
		
		//Add element row to history
		$startTimer = microtime(1);
		$this->AppendRowToHistory($el,$rowId);
		$timers['update history']=(microtime(1) - $startTimer);

	}
/*
	function DbRowToObj($row)
	{
	}
*/

	//*************************************
	//Get specific data functions
	//*************************************

	//Get row id for specific element
	function GetRowIdOfElementCurrent($id)
	{
		//Get the row ID for the current version of the element
		$query = "SELECT rowid FROM idmap WHERE elid = ".(int)$id.";";
		//echo "--".$query."\n";
		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$rowid = null;
		foreach($ret as $row)
		{
			//print_r($row);
			$rowid = $row['rowid'];
		}
		return $rowid;
	}
	
	//Optimised get current function
	public function GetElementCurrent($id)
	{
		$rowid = $this->GetRowIdOfElementCurrent($id);
		//echo "test".$rowid."\n";
		if(is_null($rowid)) return null;
		
		//Get the actual object
		$query = "SELECT * FROM elements WHERE i = ".(int)$rowid.";";
		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$rowid = null;
		foreach($ret as $row)
		{
			return $this->DbRowToObj($row);
		}
		return null;
	}

	public function GetElementHistoric($id,$version)
	{
		$rowids = $this->GetHistoryRows($id);
		if(is_null($rowids)) return null;
		if(!isset($rowids[$version])) return null;
		$rowid = $rowids[$version];

		$query = "SELECT * FROM elements WHERE i = ".(int)$rowid.";";
		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$rowid = null;
		foreach($ret as $row)
		{
			return $this->DbRowToObj($row);
		}
		throw new Exception("History row id not found in element table");
	}

	public function GetElement($id, $version = null)
	{
		if(is_null($version))
			return $this->GetElementCurrent($id);
		return $this->GetElementHistoric($id, $version);
	}

	public function GetElementHistory($id)
	{
		$rowids = $this->GetHistoryRows($id);
		if(is_null($rowids)) return null;

		$out = array();
		foreach($rowids as $id)
		{
			$query = "SELECT * FROM elements WHERE i = ".(int)$id.";";
			$ret = $this->dbh->query($query);
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
			$rowid = null;
			foreach($ret as $row)
			{
				array_push($out,$this->DbRowToObj($row));
			}
		}
		return $out;
	}

	public function GetElementsWithMembers(&$queryObjs)
	{
		$foundIds = array();
		$out = array();
		foreach($queryObjs as $obj)
		{
			//print_r($obj);
			$parents = $this->GetParentsForChildElement($obj->GetType(),$obj->attr['id']);
			//print_r($parents);
			//die();
			if(is_null($parents)) continue;
			foreach($parents as $parId)
			{
				$parObj = $this->GetElement($parId);
				if(is_null($parObj)) throw new Exception("Could not retrieve parent object");

				if(in_array($parObj->attr['id'],$foundIds)) continue;
				array_push($foundIds,$parObj->attr['id']);
				array_push($out,$parObj);
			}
		}
		//print_r($out);
		//die();
		return $out;
	}

	public function GetElementsInBbox($bbox)
	{
		//Unoptimised version
		//return ElementTable::GetElementsInBbox($bbox);

		if(!$this->latlon) throw new Exception("Position was not enabled for this object.");
		$timers = array();

		//Use internal rtree to quickly find nodes
		//print_r($bbox);
		$query = "SELECT id FROM position" ;
		$query .= " WHERE minLat > ".(float)$bbox[1];
		$query .= " and maxLat < ".(float)$bbox[3];
		$query .= " and maxLon < ".(float)$bbox[2]." and minLon > ".(float)$bbox[0];
		$query .= ";";
		//echo $query;

		$startTimer = microtime(1);
		$ret = $this->dbh->query($query);
		$timers['position']=(microtime(1) - $startTimer);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$ids = array();
		foreach($ret as $row)
			array_push($ids,$row['id']);

		//For each id, get the data object
		$out = array();
		$startTimer = microtime(1);
		foreach($ids as $id)
		{
			$obj = $this->GetElement($id);
			if(is_null($obj)) 
				throw new Exception("Position table contains node ".$id." this is not in element table.");
			array_push($out,$obj);
		}
		$timers['nodes']=(microtime(1) - $startTimer);

		//print_r($timers); die();
		return $out;
	}

	public function Count()
	{
		$query = "SELECT COUNT(elid) FROM idmap;";
		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach($ret as $row)
			return $row[0];
		return null;
	}

	public function Purge()
	{
		$this->DropTableIfExists("position");
		$this->DropTableIfExists("idmap");
		$this->DropTableIfExists("nodechildren");
		$this->DropTableIfExists("waychildren");
		$this->DropTableIfExists("relationchildren");
		$this->DropTableIfExists("history");
		ElementTable::Purge();
	}
}

//*************************
//Test and repair functions
//*************************

function CheckElementTableOptAgainstIdmap(&$table, &$db)
{
	$query = "SELECT * FROM elements;";
	$ret = $table->dbh->query($query);
	if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
	
	foreach($ret as $row)
	{
		$obj = $table->DbRowToObj($row);
		$rowid = $row['i'];
		$id = $obj->attr['id'];
		$visible = (strcmp($obj->attr['visible'],"true")==0);

		if($row['current']!=1) continue;
		//Check idmap
		$sql = "SELECT rowid FROM idmap WHERE elid=".$id.";";
		$ret = $table->dbh->query($sql);
		if($ret===false) {$err= $table->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		foreach($ret as $row)
		{
			//echo $row['rowid']." ". $rowid."\n";
			if($visible and $row['rowid'] != $rowid) 
			{
				echo "idmap incorrect for ".$obj->GetType()." ";
				echo $obj->attr['id']." ".$row['rowid']." ".$rowid."\n";
			}
			if(!$visible)
				echo "found deleted element in idmap\n";
		}
	}
}

function CheckElementTableOptAgainstPosition(&$table, &$db)
{
	if(!$table->latlon) return;

	$query = "SELECT * FROM elements;";
	$ret = $table->dbh->query($query);
	if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
	
	foreach($ret as $row)
	{
		$obj = $table->DbRowToObj($row);
		$rowid = $row['i'];
		$id = $obj->attr['id'];
		$visible = (strcmp($obj->attr['visible'],"true")==0);

		if($row['current']!=1) continue;

		//Check position table
		$sql = "SELECT * FROM position WHERE id=".$id.";";
		$ret = $table->dbh->query($sql);
		if($ret===false) {$err= $table->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$count = 0;
		foreach($ret as $row)
		{
			$tolerance = 1.0E-5;
			$error = 0;
			if(abs($row['minLat'] - $obj->attr['lat']) > $tolerance) $error = 1;
			if(abs($row['maxLat'] - $obj->attr['lat']) > $tolerance) $error = 1;
			if(abs($row['minLon'] - $obj->attr['lon']) > $tolerance) $error = 1;
			if(abs($row['maxLon'] - $obj->attr['lon']) > $tolerance) $error = 1;
			$count = $count + 1;
			if($error) echo ("Positions mismatch\n");
		}
		if($visible and $count != 1) 
			echo ("missing entry for position table ".$obj->GetType()." ".$id."\n");
		if(!$visible and $count != 0) echo "Deleted node found in position table"; 

	}	
}

function CheckElementTableOptAgainstChildren(&$table, &$db)
{

	$query = "SELECT * FROM elements;";
	$ret = $table->dbh->query($query);
	if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
	
	foreach($ret as $row)
	{
		$obj = $table->DbRowToObj($row);
		$rowid = $row['i'];
		$id = $obj->attr['id'];
		$visible = (strcmp($obj->attr['visible'],"true")==0);

		if($row['current']!=1) continue;
		//Check Children
		foreach($obj->nodes as $data)
		{
			$idchild = $data[0];
			$parentsOfChild = $table->GetParentsForChildElement("node",$idchild);
			if($visible)
			{
			if(is_null($parentsOfChild)) echo "Child links not defined\n";
			if(!in_array($id,$parentsOfChild)) echo "Child link broken\n";
			$childObj = $db->GetElementById("node",$idchild);
			if(!is_object($childObj)) 
				echo "Child object of ".$obj->GetType()." ".$id." doesn't exist ".$idchild."\n";
			}
			else
			{
			if(!is_null($parentsOfChild)) echo "Child links exist for delete element\n";
			}
		}

		foreach($obj->ways as $data)
		{
			$idchild = $data[0];
			$parentsOfChild = $table->GetParentsForChildElement("way",$idchild);
			if($visible)
			{
			if(is_null($parentsOfChild)) echo "Child links not defined\n";
			if(!in_array($id,$parentsOfChild)) echo "Child link broken\n";
			$childObj = $db->GetElementById("way",$idchild);
			if(!is_object($childObj))
				echo "Child object of ".$obj->GetType()." ".$id." doesn't exist ".$idchild."\n";
			}
			else
			{
			if(!is_null($parentsOfChild)) echo "Child links exist for delete element\n";
			}

		}
		foreach($obj->relations as $data)
		{
			$idchild = $data[0];
			$parentsOfChild = $table->GetParentsForChildElement("relation",$idchild);
			if($visible)
			{
			if(is_null($parentsOfChild)) echo "Child links not defined\n";
			if(!in_array($id,$parentsOfChild)) echo "Child link broken\n";
			$childObj = $db->GetElementById("relation",$idchild);
			if(!is_object($childObj))
				echo "Child object of ".$obj->GetType()." ".$id." doesn't exist ".$idchild."\n";
			}
			else
			{
			if(!is_null($parentsOfChild)) echo "Child links exist for delete element\n";
			}
		}
	}
}

function CheckElementTableOptAgainstHistory(&$table, &$db)
{

	$query = "SELECT * FROM elements;";
	$ret = $table->dbh->query($query);
	if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
	
	foreach($ret as $row)
	{
		$obj = $table->DbRowToObj($row);
		$rowid = $row['i'];
		$id = $obj->attr['id'];
		$visible = (strcmp($obj->attr['visible'],"true")==0);
		$version = $obj->attr['version'];

		//Check history
		$history = $table->GetHistoryRows($id);
		$historyRowId = $history[$version];
		if($rowid != $historyRowId)
		{
			echo "History row id incorrect for ".$obj->GetType()." ".$id." v";
			echo $version.":".$rowid."vs.".$historyRowId."\n";
		}
	}
}

function CheckIdmapAgainstElementTableOpt($table, $db)
{
	$query = "SELECT * FROM idmap;";
	$ret = $table->dbh->query($query);
	if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
	
	foreach($ret as $row)
	{
		//print_r($row);
		$rowid = $row['rowid'];
		$id = $row['elid'];
		$query = "SELECT * FROM elements WHERE i = ".$rowid.";";
		$ret2 = $table->dbh->query($query);
		if($ret2===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		$count = 0;
		foreach($ret2 as $row2)
		{
			$obj = $table->DbRowToObj($row2);
			//print_r($row2);
			if($obj->attr['id'] != $id) echo "Idmap entry points to incorrect element id\n";
			if($row2['current'] != 1) echo "Idmap entry is not current element\n";
			if($row2['visible'] != 1) echo "Idmap entry is not visible (it was deleted?)\n";
			$count = $count + 1;
		}
		if($count!==1) echo "Idmap entry points to one entry\n";
	}
}

function CheckElementDatabaseSqliteOpt(&$db)
{
	echo "Checking idmap\n";
	CheckElementTableOptAgainstIdmap($db->nodeTable,$db);
	CheckElementTableOptAgainstIdmap($db->wayTable,$db);
	CheckElementTableOptAgainstIdmap($db->relationTable,$db);
	echo "Checking position table\n";
	CheckElementTableOptAgainstPosition($db->nodeTable,$db);
	CheckElementTableOptAgainstPosition($db->wayTable,$db);
	CheckElementTableOptAgainstPosition($db->relationTable,$db);
	echo "Checking children\n";
	CheckElementTableOptAgainstChildren($db->nodeTable,$db);
	CheckElementTableOptAgainstChildren($db->wayTable,$db);
	CheckElementTableOptAgainstChildren($db->relationTable,$db);
	echo "Checking history\n";
	CheckElementTableOptAgainstHistory($db->nodeTable,$db);
	CheckElementTableOptAgainstHistory($db->wayTable,$db);
	CheckElementTableOptAgainstHistory($db->relationTable,$db);
	echo "Checking idmap (reverse)\n";
	CheckIdmapAgainstElementTableOpt($db->nodeTable,$db);
	CheckIdmapAgainstElementTableOpt($db->wayTable,$db);
	CheckIdmapAgainstElementTableOpt($db->relationTable,$db);
}

function RepairHistoryOfTable(&$table, &$db)
{
	$table->DropTableIfExists("history");
	$table->InitialiseSchema();

	$query = "SELECT * FROM elements;";
	$ret = $table->dbh->query($query);
	if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
	
	foreach($ret as $row)
	{
		$obj = $table->DbRowToObj($row);
		$rowid = $row['i'];
		$id = $obj->attr['id'];
		$version = $obj->attr['version'];
		//print_r($obj);
		$table->AppendRowToHistory($obj,$rowid);
	}
}

//****************************
//Model Storage
//****************************

class OsmDatabaseSqliteOpt extends OsmDatabaseSqlite
{
	function __construct()
	{
		$this->nodeTable = new ElementTableOpt("node","sqlite/nodeopt.db",1);
		$this->wayTable = new ElementTableOpt("way","sqlite/wayopt.db");
		$this->relationTable = new ElementTableOpt("relation","sqlite/relationopt.db");
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
		$filesToCheck=array('sqlite/nodeopt.db','sqlite/wayopt.db','sqlite/relationopt.db');

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




}




?>
