<?php

include_once('fileutils.php');
include_once('model-filetree.php');

define("MAX_DB_WRITE_BUFFER",10000);

class NodeTable
{
	//Cache of recent writes and deletes
	var $writeBuffer = array();
	var $deleteBuffer = array();

	function __construct()
	{
		//Check database schema and connection
		$this->dbh = new PDO('sqlite:nodes.db');
		//chmod("nodes.db",0777);
		$this->InitialiseSchema();
	}

	function __destruct()
	{
		$this->FlushWrite();
	}

	function InitialiseSchema()
	{
		//If node table doesn't exist, create it
		$nodesExistRet = $this->dbh->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='nodes';");
		$nodesExist = 0;
		foreach($nodesExistRet as $row){
			//print_r($row);
			$nodesExist = ($row[0] > 0);
		}
		//echo $nodesExist;
		if(!$nodesExist)
		{
			//echo "here";
			$this->dbh->exec('CREATE TABLE nodes (id INT PRIMARY KEY, lat REAL, lon REAL, changeset INT, user STRING, uid INT, visible INT, timestamp INT, version INT)');
			//print_r($this->dbh->errorInfo());
		}
	}

	function InsertLowLevel($el)
	{
		//Delete any existing data
		$deletesql = "DELETE FROM nodes WHERE id=".(int)$el->attr['id'].";";
		//echo $deletesql;
		$this->dbh->exec($deletesql);

		//Insert new data
		//print_r($el);

		$insertsql = "INSERT INTO nodes (id, lat, lon, changeset, user, uid, visible, timestamp, version) VALUES (";
		$insertsql .= (int)$el->attr['id'].", ";
		$insertsql .= (float)$el->attr['lat'].", ";
		$insertsql .= (float)$el->attr['lon'].", ";
		$insertsql .= (int)$el->attr['changeset'].", ";
		$insertsql .= "'".sqlite_escape_string((string)$el->attr['user'])."', ";
		$insertsql .= ((int)$el->attr['uid']).", ";
		if(strcmp($el->attr['visible'],"true")==0) $insertsql .= "1, ";
		else $insertsql .= "0, ";
		$insertsql .= ((int)strtotime($el->attr['timestamp']).", ");
		$insertsql .= ((int)$el->attr['version']);
		$insertsql .= ");\n";

		//echo $insertsql;
		$this->dbh->exec($insertsql);
	}

	function DeleteLowLevel($id)
	{
		$deletesql = "DELETE FROM nodes WHERE id=".(int)$id.";";
		$this->dbh->exec($deletesql);
	}

	function FlushWrite()
	{
		if(count($this->writeBuffer) == 0 and count($this->deleteBuffer) == 0) return;
		//echo "Flushing write buffer\n";
		$this->dbh->exec("BEGIN;");//Begin transaction	

		foreach($this->writeBuffer as $id => $el)
		{
			$this->InsertLowLevel($el);
		}

		foreach($this->deleteBuffer as $id => $el)
		{
			$this->DeleteLowLevel($id);
		}

		$this->dbh->exec("END;");//End transaction	
		$this->writeBuffer = array();
		$this->deleteBuffer = array();
		//print_r($this->dbh->errorInfo());
	}

	public function Insert($el)
	{
		$id = $el->attr['id'];
		$this->writeBuffer[$id] = $el;
		if(isset($this->deleteBuffer[$id]))
			unset ($this->deleteBuffer[$id]);

		if(count($this->writeBuffer)+count($this->deleteBuffer)>MAX_DB_WRITE_BUFFER)
			$this->FlushWrite();
	}

	public function Delete($id)
	{
		$this->deleteBuffer[$id] = 1;
		if(isset($this->writeBuffer[$id]))
			unset ($this->writeBuffer[$id]);

		if(count($this->writeBuffer)+count($this->deleteBuffer)>MAX_DB_WRITE_BUFFER)
			$this->FlushWrite();
	}

	function DbRowToObj($row)
	{
		$node = new OsmNode();
		$node->attr['id'] = (int)$row['id'];
		$node->attr['lat'] = (float)$row['lat'];
		$node->attr['lon'] = (float)$row['lon'];
		$node->attr['changeset'] = (int)$row['changeset'];
		$node->attr['user'] = (string)$row['user'];
		if((int)$row['visible']==1) $node->attr['visible'] = "true";
		else $node->attr['visible'] = "false";
		$node->attr['timestamp'] = date('c',(int)$row['timestamp']);
		$node->attr['version'] = (int)$row['version'];
		return $node;
	}

	public function GetNode($id)
	{
		if(isset($this->writeBuffer[$id]))
			return $this->writeBuffer[$id];
		if(isset($this->deleteBuffer[$id]))
			return null;

		$ret = $this->dbh->query("SELECT * FROM nodes WHERE id='".(int)$id."';");
		foreach($ret as $row){
			//print_r($row);
			return $this->DbRowToObj($row);
		}
		return null;
	}

	public function GetNodesInBbox($bbox)
	{
		//print_r($bbox);
		$query = "SELECT * FROM nodes" ;
		$query .= " WHERE lat > ".(float)$bbox[1];
		$query .= " and lat < ".(float)$bbox[3];
		$query .= " and lon < ".(float)$bbox[2]." and lon > ".(float)$bbox[0].";";
		//echo $query;
		$ret = $this->dbh->query($query);

		//print_r($this->dbh->errorInfo());
		$out = array();
		foreach($ret as $row)
		{
			array_push($out,$this->DbRowToObj($row));		
			//echo $row['id'];
		}
		return $out;
	}
	
	public function Purge()
	{
		$this->dbh->exec('DROP TABLE nodes;');
		$this->InitialiseSchema();
	}

}



//****************************
//Model Storage
//****************************

class OsmDatabaseByFTSqlite extends OsmDatabaseByFileTree
{
	function __construct()
	{
		OsmDatabaseByFileTree::__construct();

		$this->nodeTable = new NodeTable();
	}

	function __destruct()
	{
		OsmDatabaseByFileTree::__destruct();

		unset($this->nodeTable);
	}

	//******************
	//Utility functions
	//******************

	//**********************
	//General main query
	//**********************

	public function GetNodesInBbox($bbox)
	{
		$nodesNoTags = $this->nodeTable->GetNodesInBbox($bbox);
		$out = array();
		foreach($nodesNoTags as $obj)
		{
			$id = $obj->attr['id'];
			//Check if tags have been set, otherwise use local database version
			$tagDataFile = $this->ElementFilename("node",$id);
			$exists = file_exists($tagDataFile);
			if($exists)
				array_push($out,$this->GetElementObject("node",$id));
			else
				array_push($out,$obj);
		}
		return $out;
	}

	public function Debug()
	{
		print_r($this->nodeTable->GetNode(437));
		$this->nodeTable->Delete(437);
		//print_r($this->nodeTable->GetNode(437));
	}

	/*
	public function GetParentWaysOfNodes(&$nodes)
	{

	}

	public function GetNodesToCompleteWays(&$nodes, &$ways)
	{

	}
	
	public function CheckElementHasAnyChild(&$parent,&$children)
	{

	}

	public function GetParentRelations(&$nodes,&$ways)
	{

	}

	public function MapQuery($bbox)
	{

	}*/

	//**********************************
	//Get specific info from database
	//**********************************

	/*public function GetCurentVerOfElement($type,$id)
	{

	}
	
	public function GetElementById($type,$id,$version=null)
	{

	}

	public function GetElementFullHistory($type,$id)
	{

	}

	public function CheckElementExists($type,$id)
	{

	}

	public function GetCitingWaysOfNode($id)
	{

	}

	public function GetCitingRelations($type,$id)
	{

	}

	public function GetElementAsXmlString($type,$id)
	{

	}
*/
	//***********************
	//Modification functions
	//***********************

	public function CreateElement($type,$id,$el)
	{
		if(strcmp($type,"node")==0)
		{
			$this->nodeTable->Insert($el);	
		}
		OsmDatabaseByFileTree::CreateElement($type,$id,$el);
	}

	public function ModifyElement($type,$id,$el)
	{
		if(strcmp($type,"node")==0)
		{
			$this->nodeTable->Insert($el);	
			//print_r($this->nodeTable->GetNode($id));
		}
		OsmDatabaseByFileTree::ModifyElement($type,$id,$el);
	}

	public function DeleteElement($type,$id,$el)
	{
		if(strcmp($type,"node")==0)
		{
			$this->nodeTable->Delete($id);	
		}
		OsmDatabaseByFileTree::DeleteElement($type,$id,$el);
	}

	public function Purge()
	{
		$this->nodeTable->Purge();
		OsmDatabaseByFileTree::Purge();
	}

}




?>
