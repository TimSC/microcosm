<?php

require_once('model-common.php');

class ElementTablePostgis
{
	var $transactionOpen = 0;
	var $type = "element";
	var $tablename = "tablename";
	var $isNodeType = false;
	var $isWayType = false;
	var $isRelationType = false;
	var $membertables = "membertable";

	function __construct($type, $tablename, $membertables)
	{
		//Check database schema and connection
		$this->dbh = new PDO('pgsql:host='.POSTGIS_SERVER.';port='.POSTGIS_PORT.
			';dbname='.POSTGIS_DB_NAME.';user='.POSTGIS_USER.';password='.POSTGIS_PASSWORD);
		$this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->type = $type;
		if($this->type=="node")
			$this->isNodeType = true;
		if($this->type=="way")
			$this->isWayType = true;
		if($this->type=="relation")
			$this->isRelationType = true;
		$this->tablename = $tablename;
		$this->membertables = $membertables;
		$this->InitialiseSchema();
	}

	function __destruct()
	{
		$this->FlushWrite();
	}

	function InitialiseSchema()
	{
		if($this->isNodeType)
		{
			$sql = "CREATE TABLE IF NOT EXISTS ".$this->tablename." (id BIGINT, changeset BIGINT, username TEXT, uid INTEGER, visible BOOLEAN, timestamp BIGINT, version INTEGER, current BOOLEAN, tags JSONB, geom GEOMETRY(Point, 4326));";
		}
		if($this->isWayType)
		{
			$sql = "CREATE TABLE IF NOT EXISTS ".$this->tablename." (id BIGINT, changeset BIGINT, username TEXT, uid INTEGER, visible BOOLEAN, timestamp BIGINT, version INTEGER, current BOOLEAN, tags JSONB, members JSONB);";
		}
		if($this->isRelationType)
		{
			$sql = "CREATE TABLE IF NOT EXISTS ".$this->tablename." (id BIGINT, changeset BIGINT, username TEXT, uid INTEGER, visible BOOLEAN, timestamp BIGINT, version INTEGER, current BOOLEAN, tags JSONB, members JSONB, memberroles JSONB);";
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
		$elType = $el->GetType();

		//Insert new data
		$invertVals = array();
		$insertsql = "INSERT INTO \"".$this->tablename."\" (id";
		if($this->isNodeType) $insertsql .= ", geom";
		$insertsql .= ", changeset";
		$insertsql .= ", username";
		$insertsql .= ", uid";
		$insertsql .= ", visible, timestamp, version, current, tags";
		if($this->isWayType)
			$insertsql .= ", members";
		if($this->isRelationType)
			$insertsql .= ", members, memberroles";
		$insertsql .= ") VALUES (";
		$insertsql .= (int)$el->attr['id'].", ";
		if($this->isNodeType)
		{ 
			$insertsql .= "ST_GeomFromText('POINT(".(float)$el->attr['lon']." ".(float)$el->attr['lat'].")', 4326),";
		}
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
		array_push($invertVals,json_encode($el->tags));
		if($this->isWayType)
		{
			$insertsql .= ", ?";
			$nids = array(); //Way is special case
			foreach($el->members as $mem)
				array_push($nids, $mem[1]);
			array_push($invertVals,json_encode($nids));
		}
		if($this->isRelationType)
		{
			//Handle relation
			$insertsql .= ", ?, ?";
			$mems = array();
			$memRoles = array();
			foreach($el->members as $mem)
			{
				array_push($mems, array($mem[0], $mem[1]));
				array_push($memRoles, $mem[2]);
			}
			array_push($invertVals,json_encode($mems));
			array_push($invertVals,json_encode($memRoles));
		}

		$insertsql .= ");\n";

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
		if($this->isNodeType)
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
		if($this->isWayType)
		{
			$mems = json_decode($row['members']);
			$el->members = array();
			foreach($mems as $mem)
				array_push($el->members, array("node",$mem,null));
		}
		if($this->isRelationType)
		{
			$mems = json_decode($row['members']);
			$memRoles = json_decode($row['memberroles']);
			$el->members = array();
			for($i=0;$i<count($mems);$i++)
				array_push($el->members, array($mems[$i][0],$mems[$i][1],$memRoles[$i]));
		}
		return $el;
	}

	public function GetElement($id, $version = null)
	{
		//$this->FlushWrite();

		$latestVerObj = null;
		$latestVer = null;

		$selectSql = "SELECT *";
		if($this->isNodeType)
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
		if($this->isNodeType)
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
		if($this->isNodeType)
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

	public function GetElementsWithMembers(&$queryObjs)
	{
		if($this->isNodeType)
			return array(); //Nodes don't have members
		$out = array();
		$alreadyFound = array_unique(array());

		if($this->isWayType)
		{
			$cursor = 0;
			$step = 200;
			while ($cursor < count($queryObjs))
			{
				$qos = array_slice ($queryObjs, $cursor, $step);
				$cursor += $step;
				
				$sqlFrags = array(); 
				$sqlArg = array();
				foreach($qos as $qo)
				{
					array_push($sqlFrags, "greece_way_mems.member = ?");
					array_push($sqlArg, $qo->attr["id"]);
				}
				$sql = "SELECT ".$this->tablename.".* FROM ".$this->membertables." INNER JOIN ".$this->tablename." ON ".$this->membertables.".id = ".$this->tablename.".id AND ".$this->membertables.".version = ".$this->tablename.".version WHERE current = true and visible = true AND (".implode(" OR ", $sqlFrags).");";

				$qry = $this->dbh->prepare($sql);
				$ret = $qry->execute($sqlArg);
				if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

				while($row = $qry->fetch())
				{
					$obj = $this->DbRowToObj($row);
					if(in_array($obj->attr["id"], $alreadyFound)) continue;
					array_push($alreadyFound, $obj->attr["id"]); 

					array_push($out, $obj);
				}
			}
		}
		if($this->isRelationType)
		{
			$memNodes = array();
			$memWays = array();
			$memRelations = array();

			foreach($queryObjs as $qo)
			{
				if($qo->GetType()=="node")
					array_push($memNodes, $qo->attr["id"]);
				elseif($qo->GetType()=="way")
					array_push($memWays, $qo->attr["id"]);
				elseif($qo->GetType()=="relation")
					array_push($memRelations, $qo->attr["id"]);
			}

			$this->CheckRelationMemberTables("node", $memNodes, $this->membertables[0], $alreadyFound, $out);
			$this->CheckRelationMemberTables("way", $memWays, $this->membertables[1], $alreadyFound, $out);
			$this->CheckRelationMemberTables("relation", $memRelations, $this->membertables[2], $alreadyFound, $out);
		}

		return $out;
	}

	private function CheckRelationMemberTables($objType, &$objIds, $mt, &$alreadyFound, &$out)
	{
		$cursor = 0;
		$step = 50;
		while ($cursor < count($objIds))
		{
			$qids = array_slice ($objIds, $cursor, $step);
			$cursor += $step;
			
			$sqlFrags = array(); 
			$sqlArg = array();
			foreach($qids as $qid)
			{
				array_push($sqlFrags, $mt.".member=?");
				array_push($sqlArg, $qid);
			}
			$sql = "SELECT ".$this->tablename.".* FROM ".$mt." INNER JOIN ".$this->tablename." ON ".$mt.".id = ".$this->tablename.".id AND ".$mt.".version = ".$this->tablename.".version WHERE current = true and visible = true AND (".implode(" OR ", $sqlFrags).");";

			$qry = $this->dbh->prepare($sql);
			for($i=0; $i<count($sqlArg);$i++)
				$qry->bindValue($i+1, $sqlArg[$i]);
			$ret = $qry->execute($sqlArg);
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

			while($row = $qry->fetch())
			{
				$obj = $this->DbRowToObj($row);
				if(in_array($obj->attr["id"], $alreadyFound)) continue;
				array_push($alreadyFound, $obj->attr["id"]); 

				array_push($out, $obj);
			}
		}
	}

	public function GetElementsInBbox($bbox)
	{
		if(!$this->isNodeType) throw new Exception("Position was not enabled for this object.");

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
		$this->nodeTable = new ElementTablePostgis("node",POSTGIS_PREFIX."nodes", null);
		$this->wayTable = new ElementTablePostgis("way",POSTGIS_PREFIX."ways", POSTGIS_PREFIX."way_mems");
		$this->relationTable = new ElementTablePostgis("relation",POSTGIS_PREFIX."relations", array(POSTGIS_PREFIX."relation_mems_n", POSTGIS_PREFIX."relation_mems_w", POSTGIS_PREFIX."relation_mems_r"));
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
