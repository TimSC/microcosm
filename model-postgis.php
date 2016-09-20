<?php

require_once('model-common.php');

class ElementTablePostgis
{
	var $latlon = 0;
	var $transactionOpen = 0;
	var $type = "element";
	var $tablename = "tablename";

	function __construct($type, $tablename, $latlon = false)
	{
		//Check database schema and connection
		$this->dbh = new PDO('pgsql:host='.POSTGIS_SERVER.';port='.POSTGIS_PORT.
			';dbname='.POSTGIS_DB_NAME.';user='.POSTGIS_USER.';password='.POSTGIS_PASSWORD);
		$this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->latlon = $latlon;
		$this->type = $type;
		$this->tablename = $tablename;
		$this->InitialiseSchema();
	}

	function __destruct()
	{
		$this->FlushWrite();
	}

	function InitialiseSchema()
	{
		if($this->latlon)
		{
			$sql = "CREATE TABLE IF NOT EXISTS ".$this->tablename." (id BIGINT, changeset BIGINT, username TEXT, uid INTEGER, visible BOOLEAN, timestamp BIGINT, version INTEGER, current BOOLEAN, tags TEXT, geom GEOMETRY(Point, 4326));";
		}
		else
		{
			$sql = "CREATE TABLE IF NOT EXISTS ".$this->tablename." (id BIGINT, changeset BIGINT, username TEXT, uid INTEGER, visible BOOLEAN, timestamp BIGINT, version INTEGER, current BOOLEAN, tags TEXT, members TEXT);";
		}
		$qry = $this->dbh->prepare($sql);
		$ret = $qry->execute();
	}

	function ClearCurrentFlagInElementTable($id)
	{
		//Set existing historic versions to not current
		$sql = "UPDATE \"".$this->tablename."\" SET current=false WHERE id=".(int)$id.";";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	}

	function CheckTransactionIsOpen()
	{
		if(!$this->transactionOpen)
		{
			$sql = "BEGIN TRANSACTION;";
			$ret = $this->dbh->exec($sql);//Begin transaction
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
			$this->transactionOpen = 1;
		}
	}

	function InsertObjectIntoElementTable($el)
	{
		//Insert new data
		$invertVals = array();
		$insertsql = "INSERT INTO \"".$this->tablename."\" (id";
		if($this->latlon) $insertsql .= ", lat, lon";
		$insertsql .= ", changeset";
		$insertsql .= ", username";
		$insertsql .= ", uid";
		$insertsql .= ", visible, timestamp, version, current, tags, members) VALUES (";
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
		if(strcmp($el->attr['visible'],"true")==0) $insertsql .= "true, ";
		else $insertsql .= "false, ";
		if(isset($el->attr['timestamp']))
			$insertsql .= ((int)strtotime($el->attr['timestamp']).", ");
		else
			$insertsql .= ("null, ");
		$insertsql .= ((int)$el->attr['version']);
		$insertsql .= ", true";
		$insertsql .= ", ?";
		$insertsql .= ", ?";
		$insertsql .= ");\n";
		array_push($invertVals,json_encode($el->tags));
		array_push($invertVals,json_encode($el->members));

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

	function FlushWrite()
	{
		if($this->transactionOpen)
		{
			$sql = "COMMIT;";
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
		if($this->type == "node")
		{
			$el->attr['lat'] = (float)$row['lat'];
			$el->attr['lon'] = (float)$row['lon'];
		}
		$el->attr['changeset'] = (int)$row['changeset'];
		if(isset($row['username'])) $el->attr['user'] = (string)$row['username'];
		if(isset($row['uid'])) $el->attr['uid'] = (int)$row['uid'];
		if((int)$row['visible']==1) $el->attr['visible'] = "true";
		else $el->attr['visible'] = "false";
		if(!is_null($row['timestamp'])) $el->attr['timestamp'] = date('c',(int)$row['timestamp']);
		$el->attr['version'] = (int)$row['version'];

		//Tags and members
		$el->tags = json_decode($row['tags']);
		if(array_key_exists('members', $row))
		{
			if($this->type == "way")
			{
				$mems = json_decode($row['members']);
				$el->members = array();
				foreach($mems as $mem)
					array_push($el->members, array("node",$mem,null));
			}
			else
				$el->members = json_decode($row['members']);
		}
		else
			$el->members = array();
		return $el;
	}

	public function GetElement($id, $version = null)
	{
		//$this->FlushWrite();

		$latestVerObj = null;
		$latestVer = null;

		$selectSql = "SELECT *";
		if($this->latlon)
			$selectSql = "SELECT *, ST_X(geom) as lon, ST_Y(geom) as lat";
		$query = $selectSql." FROM ".$this->tablename." WHERE id=".(int)$id."";
		if(!is_null($version)) $query .= " AND version=".(int)$version."";
		if(is_null($version)) $query .= " AND current=true";
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
		$selectSql = "SELECT *";
		if($this->latlon)
			$selectSql = "SELECT *, ST_X(geom) as lon, ST_Y(geom) as lat";
		$query = $selectSql." FROM ".$this->tablename." WHERE current=true AND visible=true;";

		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}

		foreach($ret as $row){
			//print_r($row);
			$obj = $this->DbRowToObj($row);

			//Verify latest version is used
			$query = "SELECT version FROM ".$this->tablename." WHERE id=".$obj->attr['id'].";";
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
		$selectSql = "SELECT *";
		if($this->latlon)
			$selectSql = "SELECT *, ST_X(geom) as lon, ST_Y(geom) as lat";
		$query = $selectSql." FROM ".$this->tablename." WHERE id=".(int)$id."";
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
		$selectSql = "SELECT *";
		if($this->latlon)
			$selectSql = "SELECT *, ST_X(geom) as lon, ST_Y(geom) as lat";
		$sql = $selectSql." FROM ".$this->tablename." WHERE current = true and visible = true;";
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
		$query = "SELECT *, ST_X(geom) as lon, ST_Y(geom) AS lat FROM ".$this->tablename ;
		$query .= " WHERE geom && ST_MakeEnvelope(?, ?, ?, ?, 4326) ";
		$query .= " and visible=true and current=true;";
		//echo $query;
		$qry = $this->dbh->prepare($query);
		$ret = $qry->execute($bbox);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}

		//print_r($this->dbh->errorInfo());
		$out = array();
		while($row = $qry->fetch())
		{
			array_push($out,$this->DbRowToObj($row));		
			//print_r($out);
		}
		return $out;
	}
	
	public function DropTableIfExists($name)
	{
		$sql = 'DROP TABLE IF EXISTS '.$name.';';
		$qry = $this->dbh->prepare($sql);
		$ret = $qry->execute();
	}

	public function Count()
	{
		$query = "SELECT COUNT(id) FROM ".$this->tablename." WHERE current = true;";
		$ret = $this->dbh->query($query);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach($ret as $row)
			return $row[0];
		return null;
	}

	public function Purge()
	{
		$this->DropTableIfExists($this->tablename);
		$this->InitialiseSchema();
	}

}

//****************************
//Model Storage
//****************************

class OsmDatabasePostgis extends OsmDatabaseCommon
{
	function __construct()
	{
		$this->nodeTable = new ElementTablePostgis("node",POSTGIS_PREFIX."nodes",1);
		$this->wayTable = new ElementTablePostgis("way",POSTGIS_PREFIX."ways");
		$this->relationTable = new ElementTablePostgis("relation",POSTGIS_PREFIX."relations");
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

?>
