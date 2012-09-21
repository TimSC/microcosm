<?php

require_once('fileutils.php');
require_once('dbutils.php');
require_once('model-common.php');
require_once('config.php');

//****************************
//Model Storage
//****************************

class OsmDatabaseMysql extends OsmDatabaseCommon
{
	function __construct()
	{
		$this->inTransaction = false;
		try {
                  print_r(
                          'CONNECT :mysql:dbname='.MYSQL_DB_NAME.';host='.
                          MYSQL_SERVER.';' . MYSQL_USER . MYSQL_PASSWORD);

		$this->dbh = new PDO('mysql://dbname='.MYSQL_DB_NAME.';host='.MYSQL_SERVER.';', MYSQL_USER, MYSQL_PASSWORD);
		} catch (PDOException $e) {
		    throw new Exception ('Connection failed: ' . $e->getMessage());
		}
                $this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
		$this->InitialiseSchema();
	}

	function __destruct()
	{
		if ($this->inTransaction) {$this->dbh->commit(); $this->inTransaction = false;}
	}

	//******************
	//Utility functions
	//******************

	function CheckPermissions()
	{
		return 1;
	}

	function InitialiseSchema()
	{
          //mysql_select_db

		$sql = "CREATE TABLE IF NOT EXISTS ".MYSQL_DB_NAME.".meta (intid BIGINT PRIMARY KEY AUTO_INCREMENT, type INTEGER, id BIGINT, ver BIGINT, changeset BIGINT, user TEXT, uid BIGINT, timestamp INTEGER, visible INTEGER, INDEX(id,ver)) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin ENGINE=MyISAM;";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		$sql = "CREATE TABLE IF NOT EXISTS ".MYSQL_DB_NAME.".geom (intid BIGINT PRIMARY KEY, g GEOMETRY NOT NULL, SPATIAL INDEX(g), type INTEGER, id BIGINT, ver BIGINT, INDEX(id,ver)) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin ENGINE=MyISAM;";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		$sql = "CREATE TABLE IF NOT EXISTS ".MYSQL_DB_NAME.".tags (intid BIGINT PRIMARY KEY, type INTEGER, id BIGINT, ver BIGINT, tags BLOB, INDEX(id,ver)) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin ENGINE=MyISAM;";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		$sql = "CREATE TABLE IF NOT EXISTS ".MYSQL_DB_NAME.".members (linkid BIGINT PRIMARY KEY AUTO_INCREMENT, ptype INTEGER, pid BIGINT, pver BIGINT, ctype INTEGER, cid BIGINT, role TEXT, ord INTEGER, INDEX(pid,pver), INDEX(cid)) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin ENGINE=MyISAM;";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		$sql = "CREATE TABLE IF NOT EXISTS ".MYSQL_DB_NAME.".current (rowid BIGINT PRIMARY KEY AUTO_INCREMENT, type INTEGER, id BIGINT, currentver BIGINT, visible INTEGER, intid BIGINT, INDEX(id,type)) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin ENGINE=MyISAM;";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
	}

	function TypeToCode($ty)
	{
		if($ty=="node") return 0;
		if($ty=="way") return 1;
		if($ty=="relation") return 2;
		throw new Exception("Type ".$ty." not recognised");
	}

	function CodeToType($code)
	{
		if($code==0) return "node";
		if($code==1) return "way";
		if($code==2) return "relation";
		throw new Exception("Code ".$code." not recognised");
	}

	function InsertMembersIntoDatabase($el, $id, $mems, $ptype)
	{
		$ver = $this->dbh->quote($el->attr['version']);

		$count = 0;
		foreach($mems as $e)
		{
		$sql = "INSERT INTO members (ptype, pid, pver, ctype, cid, role, ord) VALUES (".
			$this->dbh->quote($this->TypeToCode($ptype)).",".
			$this->dbh->quote($id).",".$ver.",".
			$this->dbh->quote($this->TypeToCode($e[0])).",".
			$this->dbh->quote($e[1]).",".
			$this->dbh->quote($e[2]).",".
			$this->dbh->quote($count).
			");";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$count ++;
		}
	}

	function PointStrToLatLon($pt)
	{
		$nums = explode(" ",substr($pt,6,strlen($pt)-7));
		return array((float)$nums[0],(float)$nums[1]);

	}

	function IntIdToObj($intid)
	{
		//Return requested version, first get the meta data
		$out = null;
		$type = null;
		$id = null;
		$version = null;
		$sql = "SELECT * FROM meta WHERE intid = ".$this->dbh->quote($intid).";";
		//echo $sql."\n";
		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach ($ret as $row) {
			//print_r($row);
			$out = OsmElementFactory($this->CodeToType($row['type']));
			$out->attr['id'] = $row['id'];
			$out->attr['version'] = $row['ver'];
			$out->attr['changeset'] = $row['changeset'];
			$out->attr['user'] = $row['user'];
			$out->attr['uid'] = $row['uid'];
			$out->attr['timestamp'] = date('c',(int)$row['timestamp']);
			if($row['visible']) $out->attr['visible'] = "true";
			else $out->attr['visible'] = "false";
			//$intid = $row['intid'];
			$type = $this->CodeToType($row['type']);
			$id = $row['id'];
			$version = $row['ver'];
		}
		
		if($out == null) return 0;

		//If node, get exact position
		if($type=="node")
		{
		$sql = "SELECT AsText(g) FROM geom WHERE intid = ".$this->dbh->quote($intid).";";
		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach ($ret as $row) {
			//print_r($this->PointStrToLatLon($row['AsText(g)']));
			list($lat,$lon) = $this->PointStrToLatLon($row['AsText(g)']);
			$out->attr['lat'] = $lat;
			$out->attr['lon'] = $lon;
		}
		}		

		//Get tags
		$sql = "SELECT tags FROM tags WHERE intid = ".$this->dbh->quote($intid).";";
		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach ($ret as $row) {
			//print_r($row);
			$out->tags = json_decode($row['tags']);
		}

		//Get members
		$sql = "SELECT ctype,cid,role FROM members WHERE ptype = ".$this->dbh->quote($this->TypeToCode($type))." AND pid = ".
			$this->dbh->quote($id)." AND pver = ".$this->dbh->quote($version)." ORDER BY ord;";
		//echo $sql."\n";
		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach ($ret as $row) {
			//print_r($row);
			$ctype = $this->CodeToType($row['ctype']);
			array_push($out->members,array($ctype,$row['cid'],$row['role']));
		}

		return $out;

	}

	public function GetHighestVersionNum($type,$id)
	{
		if (0) //Uncached version, direct from meta table
		{
		$maxVer = null;
		$maxIntId = null;
		$maxVisible = null;
		$sql = "SELECT ver,intid,visible FROM meta WHERE type = ".$this->dbh->quote($this->TypeToCode($type))." AND id = ".$this->dbh->quote($id).";";
		//echo $sql."\n";
		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach ($ret as $row) {
			//print_r($row);
			//return array($row['intid'],$row['MAX(ver)'],$row['visible']);
			if($maxVer === null or $row['ver'] > $maxVer)
			{
				$maxVer = $row['ver'];
				$maxIntId = $row['intid'];
				$maxVisible = $row['visible'];
			}
		}
		if($maxVer === null) return 0; //Not found
		return array($maxIntId,$maxVer,$maxVisible);
		}

		//Retrieve latest version from current table

		$sql = "SELECT * FROM current WHERE id = ".$this->dbh->quote($id)." AND type = ".$this->dbh->quote($this->TypeToCode($type)).";";
		//echo $sql."\n";
		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach ($ret as $row) {
			//print_r($row);
			return array($row['intid'],$row['currentver'],$row['visible']);
		}
		return 0; //Not found
	}

	public function UpdateLatestVersionFromMeta($type,$id)
	{
		$maxVer = null;
		$maxIntId = null;
		$maxVisible = null;
		$sql = "SELECT ver,intid,visible FROM meta WHERE type = ".$this->dbh->quote($this->TypeToCode($type))." AND id = ".$this->dbh->quote($id).";";
		//echo $sql."\n";
		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach ($ret as $row) {
			//print_r($row);
			//return array($row['intid'],$row['MAX(ver)'],$row['visible']);
			if($maxVer === null or $row['ver'] > $maxVer)
			{
				$maxVer = $row['ver'];
				$maxIntId = $row['intid'];
				$maxVisible = $row['visible'];
			}
		}
		if($maxVer === null) return 0; //Not found

		//echo $maxVer."\n";
		//Try to update current table, see how many rows are affected
		$sql = "UPDATE current SET currentver=".$this->dbh->quote($maxVer).", visible=".$this->dbh->quote($maxVisible).
			", intid=".$this->dbh->quote($maxIntId)." WHERE id=".$this->dbh->quote($id).
			" AND type=".$this->dbh->quote($this->TypeToCode($type)).";";
		$stat = $this->dbh->prepare($sql);
		$ret = $stat->execute();
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}	
		$affected = $stat->rowCount();

		//echo $affected."\n";
	
		//If the record is not in the table, insert a fresh row
		if($affected == 0)
		{
			$sql = "INSERT INTO current (currentver, visible, intid, id, type) VALUES (".
			$this->dbh->quote($maxVer).",".
			$this->dbh->quote($maxVisible).",".
			$this->dbh->quote($maxIntId).",".
			$this->dbh->quote($id).",".
			$this->dbh->quote($this->TypeToCode($type)).");";
			$ret = $this->dbh->exec($sql);
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		}

		//return array($maxIntId,$maxVer,$maxVisible);
	}

	public function GetParentsByType(&$els,$type)
	{
		$parentIds = array();
		//Find all parent ways
		foreach($els as $el)
		{
			$id = $el->attr['id'];

			//ptype INTEGER, pid INTEGER, pver INTEGER, ctype INTEGER, cid
			$sql = "SELECT * FROM members WHERE ptype = ".$this->dbh->quote($this->TypeToCode($type)).
				" AND ctype = ".$this->dbh->quote($this->TypeToCode($el->GetType())).
				" AND cid = ".$this->dbh->quote($id).";";
			//echo $sql."\n"; die();
			$ret = $this->dbh->query($sql);
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
			foreach ($ret as $row) {
				//print_r($row);
				$parentId = $row['pid'];
				$parentVer = $row['pver'];

				//Keep the highest version num
				if (!isset($parentIds[$parentId]))
					$parentIds[$parentId] = $parentVer;
				if($parentVer > $parentIds[$parentId])
					$parentIds[$parentId] = $parentVer;

			}			
		}
		//print_r($parentIds);

		//Check if each way is still an active object (and ignore the non-current objects)
		$highVerParents = array();
		foreach ($parentIds as $parentId=>$ver)
		{
			list($intid,$highestVer,$visible) = $this->GetHighestVersionNum($type,$parentId);
			//echo $type." ".$parentId." ".$intid." ".$highestVer."\n";
			if ($highestVer == $ver) $highVerParents[$parentId] = $intid;
			//echo "\n";

		}

		//print_r($highVerWays);
		//exit(0);

		///Check if object has been deleted

		//Get parent objects
		$out = array();
		foreach ($highVerParents as $parentId=>$intid)
		{
			$obj = $this->IntIdToObj($intid);
			if(!is_numeric($obj))
				array_push($out,$obj);
		}
		
		//exit(0);

		return $out;
	}

	//**********************
	//General main query
	//**********************

	public function GetNodesInBbox($bbox)
	{
		$startTime = microtime(TRUE);

		$sql = "SELECT geom.intid, meta.type, meta.id, meta.ver FROM geom INNER JOIN meta ON geom.intid = meta.intid".
			" WHERE Contains(GeomFromText('Polygon((".
			$bbox[1]." ".$bbox[0].",".$bbox[3]." ".$bbox[0].",".$bbox[3]." ".
			$bbox[2].",".$bbox[1]." ".$bbox[0].",".$bbox[1]." ".$bbox[0]."))'),geom.g) AND meta.visible = 1;";
		//echo $sql."\n"; exit(0);
		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}
		$hits = array();
		foreach ($ret as $row) {
			//print_r($row);
			$intid = $row['intid'];
			array_push($hits,array($intid,$this->CodeToType($row['type']),$row['id'],$row['ver']));
		}

		//print "Fetched nodes in bbox in ".(microtime(TRUE) - $startTime)."\n";

		//Check if this is the latest version
		$currentHits = array();
		foreach ($hits as $hit) {
			$startTime = microtime(TRUE);
			list($intid,$type,$id,$version) = $hit;

			list($maxIntId,$maxVer,$maxVisible) = $this->GetHighestVersionNum($type,$id);
			if($version == $maxVer)
			{
				array_push($currentHits, $hit);
			}

			//print "Fetched current ver in ".(microtime(TRUE) - $startTime)."\n";
		}

		//Get objects
		$out = array();
		foreach ($currentHits as $hit) {
			$startTime = microtime(TRUE);

			list($intid,$type,$id,$version) = $hit;
			$obj = $this->IntIdToObj($intid);
			if(!is_numeric($obj))
				array_push($out,$obj);

			//print "Fetched final obj in ".(microtime(TRUE) - $startTime)."\n";
		}

		return $out;
	}
	
	

	public function GetParentWaysOfNodes(&$nodes)
	{
		return $this->GetParentsByType($nodes,"way");
	}
	
	public function GetParentRelations(&$els)
	{
		return $this->GetParentsByType($els,"relation");
	}

	//**********************************
	//Get specific info from database
	//**********************************

	public function GetElementById($type,$id,$version=null)
	{
		$intid = Null;
		if($version == null)
		{
			//Automatically determine latest version
			$ret = $this->GetHighestVersionNum($type,$id);
			if($ret===0) return 0; //Not found
			list($intid,$version,$visible) = $ret;

			//Check if deleted (return -2, if so)
			if($visible == 0) return -2;
		}

		if($intid == Null)
		{
		//Return requested version, first the intid of the object
		$out = null;
		$sql = "SELECT intid FROM meta WHERE type = ".$this->dbh->quote($this->TypeToCode($type))." AND id = ".
			$this->dbh->quote($id)." AND ver = ".$this->dbh->quote($version).";";
		//echo $sql."\n";
		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach ($ret as $row) {
			$intid = $row['intid'];
		}
		}
		
		//Return object	
		return $this->IntIdToObj($intid);

	}
	
	public function GetElementFullHistory($type,$id)
	{
		//Find all version numbers
		$vers = array();
		$sql = "SELECT ver FROM meta WHERE type = ".$this->dbh->quote($this->TypeToCode($type))." AND id = ".$this->dbh->quote($id).";";
		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach ($ret as $row) {
			//print_r($row);
			array_push($vers,$row['ver']);
		}
		if (count($vers)==0) return 0; //Not found

		$out = array();
		foreach($vers as $ver)
		{
			$obj = $this->GetElementById($type,$id,$ver);
			array_push($out,$obj);
		}
		return $out;
	}

	public function Dump($callback)
	{
		//Dump current version of the database to a callback function

		//Dump types
		$tys = array('node','way','relation');
		foreach($tys as $ty)
		{
		$sql = "SELECT * FROM current WHERE type = ".$this->dbh->quote($this->TypeToCode($ty)).";";
		$ret = $this->dbh->query($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($query.",".$err[2]);}
		foreach ($ret as $row) {$obj = $this->IntIdToObj($row['intid']);$callback($obj);}
		}
	}
	
	//***********************
	//Modification functions
	//***********************

	/*public function GetInternalTable($type)
	{

	}*/

	public function CreateElement($type,$id,$el)
	{
		return $this->ModifyElement($type,$id,$el);
	}

	public function ModifyElement($type,$id,$el)
	{
		//if ($type=="way")
			//print_r($el);
		//Start transaction if we have not already
		if (!$this->inTransaction) {$this->dbh->beginTransaction(); $this->inTransaction = true;}

		//Insert into internal id table
		$ver = $this->dbh->quote($el->attr['version']);
		$changeset = $this->dbh->quote($el->attr['changeset']);
		$user = "NULL";
		if (isset($el->attr['user']))
			$user = $this->dbh->quote($el->attr['user']);
		$uid = "NULL";
		if (isset($el->attr['uid']))
			$uid = $this->dbh->quote($el->attr['uid']);
		$timestamp = 0;
		if (isset($el->attr['timestamp']))
			$timestamp = $this->dbh->quote((int)strtotime($el->attr['timestamp']));
		$visible = "NULL";
		if (isset($el->attr['visible']) and $el->attr['visible'] == "true") $visible = 1;
		if (isset($el->attr['visible']) and $el->attr['visible'] == "false") $visible = 0;
		if (!isset($el->attr['visible'])) $visible = 1;

		$sql ="INSERT INTO meta (type, id, ver, changeset, user, uid, timestamp, visible) VALUES (".$this->dbh->quote($this->TypeToCode($type)).",".
			$this->dbh->quote($id).",".$ver.",".$changeset.",".$user.",".$uid.",".$timestamp.",".$visible.")";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		$intid = $this->dbh->lastInsertId();

		//Store nodes in geometry optimised table too
		if($type == "node")
		{
			$lat = $el->attr['lat'];
			$lon = $el->attr['lon'];
			$sql = "INSERT INTO geom (intid, g, type, id, ver) VALUES (".$intid.",GeomFromText('POINT(".$lat." ".$lon.")'),".
				$this->dbh->quote($this->TypeToCode($type)).",".
				$this->dbh->quote($id).",".$ver.");";
			$ret = $this->dbh->exec($sql);
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}			
		}

		//Store, tags if any
		if(count($el->tags)>0)
		{
			$tagser = $this->dbh->quote(json_encode($el->tags));

			$sql = "INSERT INTO tags (intid, type, id, ver, tags) VALUES (".$intid.",".
				$this->dbh->quote($this->TypeToCode($type)).",".
				$this->dbh->quote($id).",".$ver.",".$tagser.");";
			$ret = $this->dbh->exec($sql);
			if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}				
		}

		//Store the references to child objects
		$this->InsertMembersIntoDatabase($el,$id,$el->members,$type);

		//Check if this is the latest version and update the "current" version table
		$this->UpdateLatestVersionFromMeta($type,$id);

	}

	public function DeleteElement($type,$id,$el)
	{
		return $this->ModifyElement($type,$id,$el);
	}

	public function Purge()
	{
		$sql = "DROP TABLE IF EXISTS geom;";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		$sql = "DROP TABLE IF EXISTS meta;";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		$sql = "DROP TABLE IF EXISTS tags;";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		$sql = "DROP TABLE IF EXISTS members;";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		$sql = "DROP TABLE IF EXISTS current;";
		$ret = $this->dbh->exec($sql);
		if($ret===false) {$err= $this->dbh->errorInfo();throw new Exception($sql.",".$err[2]);}

		$this->InitialiseSchema();
	}

}


?>
