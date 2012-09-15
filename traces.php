<?php

require_once('config.php');
require_once('dbutils.php');
require_once('fileutils.php');

class RawTraceTable extends GenericSqliteTable
{
	var $keys=array('tid'=>'INTEGER');
	var $dbname='private/rawtraces.db';
	var $tablename="rawtraces";
}

function GetTracesInBbox($userInfo,$get)
{
	$bboxExp = explode(",",$get['bbox']);
	$bbox = array_map('floatval', $bboxExp);
	$page = (int)$get['page'];

	//Validate BBOX
	//TODO, reuse code that validates map query bbox

	//Open DB
	$lock=GetReadDatabaseLock();
	$db = new PDO('sqlite:sqlite/traces.db');
	InitialiseTraceDbSchema($db);

	$startPoint = (int)$page * TRACE_PAGE_SIZE;
	$endPoint = ((int)$page + 1) * TRACE_PAGE_SIZE;

	$query = "SELECT * FROM position INNER JOIN data ON position.id = data.id" ;
	$query .= " WHERE minLat > ".(float)$bbox[1];
	$query .= " and maxLat < ".(float)$bbox[3];
	$query .= " and maxLon < ".(float)$bbox[2]." and minLon > ".(float)$bbox[0];
	$query .= " LIMIT ".$startPoint.", ".$endPoint;
	$query .= ";";
	//echo $query;

	$ret = $db->query($query);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($query.",".$err[2]);}
	$points = array();
	foreach($ret as $row)
	{
		//print_r( $row);
		$tid = $row['tid'];
		$segid = $row['segid'];
		$timestamp = $row['timestamp'];
		if(!isset($points[$tid])) $points[$tid] = array();
		if(!isset($points[$tid][$segid])) $points[$tid][$segid] = array();
		$points[$tid][$segid][$timestamp] = $row;
	}

	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$out .= '<gpx version="1.0" creator="'.SERVER_NAME.'" xmlns="http://www.topografix.com/GPX/1/0/">'."\n";
	foreach($points as $tid => $trk)
	{
	$out .= "  <trk>\n";

	$query = "SELECT * FROM meta WHERE tid = ".(int)$tid.";";
	$ret = $db->query($query);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($query.",".$err[2]);}
	$visible = null;
	foreach($ret as $row)
	{
		//print_r($row);
		$out .= '    <name>'.htmlentities($row['name'],ENT_QUOTES,"UTF-8").'</name>'."\n";
		$out .= '    <desc>'.htmlentities($row['description'],ENT_QUOTES,"UTF-8").'</desc>'."\n";
		$out .= '    <tags>'.htmlentities($row['tags'],ENT_QUOTES,"UTF-8").'</tags>'."\n";
		$visible = $row['visible'];
		if($visible == 4) $out .= '    <uid>'.(int)$row['uid'].'</uid>'."\n";
		//$out .= '    <url>http://www.openstreetmap.org/trace/834479/view</url>'."\n";
	}

	foreach($trk as $segid => $seg)
	{
		$ordered = (($visible == 2) or ($visible == 4));
		if($ordered) $out .= "    <trkseg>\n";

		foreach($seg as $pt)
		{
			if(!$ordered) $out .= "    <trkseg>\n";
			$out .= '    <trkpt lat="'.(float)($pt['minLat']).'" lon="'.(float)($pt['minLon']).'">'."\n";
			if(!is_null($pt['timestamp']) and $ordered) 
				$out .= '    <time>'.date('c',$pt['timestamp']).'</time>'."\n";
			if(!is_null($pt['ele'])) 
				$out .= '    <ele>'.(float)($pt['ele']).'</ele>'."\n";
			$out .= '    </trkpt>'."\n";

			if(!$ordered) $out .= "    </trkseg>\n";
		}
		if($ordered) $out .= "    </trkseg>\n";
	}
	$out .= "  </trk>\n";
	}
	$out .= "</gpx>\n";
	return array(1,array("Content-Type:text/xml"),$out);

}

function InsertTraceIntoDb($userInfo, $args)
{
	list($files,$post) = $args;
	$name = $files['file']['name'];
	$tmpName = $files['file']['tmp_name'];
	$visible = $post['visibility'];
	if(isset($post['public'])) $public = $post['public'];
	else $public = null;
	$description = $post['description'];
	$tags = $post['tags'];
	$gpxString = file_get_contents($tmpName);
	$displayName = $userInfo['displayName'];
	$uid = $userInfo['userId'];

	//Sort out public and visible settings
	if(isset($public) and !isset($visibile))
	{
		if($public == 0) $visible = "private";
		if($public == 1) $visible = "public";
	}

	//Parse GPX
	$gpx = simplexml_load_string($gpxString);
	if (!$gpx)
	{
		$err = "Failed to parse XML upload diff.";
		foreach(libxml_get_errors() as $error) {
			$err = $err."\t".$error->message;
		}
		throw new InvalidArgumentException($err);
	}

	//Validate input
	$visibleNum = null;
	if(strcasecmp($visible,"private")==0) $visibleNum = 1;
	if(strcasecmp($visible,"trackable")==0) $visibleNum = 2;
	if(strcasecmp($visible,"public")==0) $visibleNum = 3;
	if(strcasecmp($visible,"identifiable")==0) $visibleNum = 4;
	if(is_null($visibleNum)) throw new Exception("Visible parameter not private, trackable, public or identifiable.");
	$uid = (int)$uid;

	//Validate text files
	if(strlen($name)> MAX_GPX_FIELD_LENGTH) throw new Exception("Filename too long");
	if(strlen($description)> MAX_GPX_FIELD_LENGTH) throw new Exception("Description too long");
	if(strlen($tags)> MAX_GPX_FIELD_LENGTH) throw new Exception("Tags too long");

	//Validate GPX
	$count = 0;
	foreach($gpx->trk as $trk)
	{
		foreach($trk->trkseg as $trkseg)
		{
			foreach($trkseg->trkpt as $trkpt)
			{
				$lat = (float)$trkpt['lat'];
				$lon = (float)$trkpt['lon'];
				if(isset($trkpt->ele[0])) $ele = (float)$trkpt->ele[0];
				else $ele = null;
				$time = (int)strtotime($trkpt->time[0]);
				//echo $lat.",".$lon." ".$ele." ".$time."\n";
				$count = $count + 1;
			}
		}
	}	
	if($count == 0) return array(0,Null,"Empty trace"); //We require at least one point

	//Insert raw data string into raw trace table
	$rawtracedb = new RawTraceTable();
	$record = array('gpx'=>bzcompress($gpxString));
	$tid = $rawtracedb->Set('tid',null,$record);

	//Open DB
	$lock=GetWriteDatabaseLock();
	$db = new PDO('sqlite:sqlite/traces.db');
	//DropTraceDb($db);
	InitialiseTraceDbSchema($db);

	//Insert metadata
	$sql = "INSERT INTO meta (tid,public,visible,uid,name,description,tags,pending) VALUES (".(int)$tid.",";
	$sql .= (int)$public;
	$sql .= ",".(int)$visibleNum;
	$sql .= ",".(int)$uid;
	$sql .= ",'".sqlite_escape_string($name)."'";
	$sql .= ",'".sqlite_escape_string($description)."'";
	$sql .= ",'".sqlite_escape_string($tags)."',1);";
	$ret = $db->exec($sql);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($sql.",".$err[2]);}
	
	ProcessPendingTrace($db,$tid,$gpx);

	return array(1,array("Content-Type:text/plain"),$tid);
}

function ProcessPendingTrace($db,$tid,$gpx)
{
	//Verify trace is still pending
	$query = "SELECT pending FROM meta WHERE tid = ".(int)$tid.";";
	$ret = $db->query($query);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($query.",".$err[2]);}
	foreach($ret as $row)
	{
		if((int)$row['pending']!=1) throw new Exception("Cannot process trace that is not pending");
	}

	//Start transaction
	$sql = "BEGIN;";
	$ret = $db->exec($sql);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($sql.",".$err[2]);}
		
	//Insert points and timestamps
	$segid = 0;
	foreach($gpx->trk as $trk)
	foreach($trk->trkseg as $trkseg)
	{
		foreach($trkseg->trkpt as $trkpt)
		{
			$lat = (float)$trkpt['lat'];
			$lon = (float)$trkpt['lon'];
			if(isset($trkpt->ele[0])) $ele = (float)$trkpt->ele[0];
			else $ele = null;
			$time = (int)strtotime($trkpt->time[0]);
			//echo $lat.",".$lon." ".$ele." ".$time."\n";

			//Insert point position
			$sql = "INSERT INTO position (id,minLat,maxLat,minLon,maxLon) VALUES (null,";
			$sql .= $lat.",".$lat.",".$lon.",".$lon.");";
			$ret = $db->exec($sql);
			if($ret===false) {$err= $db->errorInfo();throw new Exception($sql.",".$err[2]);}
			$id = $db->lastInsertId();

			//Insert point data
			$sql = "INSERT INTO data (id,ele,timestamp,tid,segid) VALUES (";
			$sql .= (int)$id;
			if(!is_null($ele)) $sql .= ",".(float)$ele; else $sql .= ",null";
			$sql .= ",".(int)$time;
			$sql .= ",".(int)$tid;
			$sql .= ",".(int)$segid.");";
			$ret = $db->exec($sql);
			if($ret===false) {$err= $db->errorInfo();throw new Exception($sql.",".$err[2]);}

		}
		$segid += 1;
	}	

	//Mark trace as processed and no longer pending
	$sql = "UPDATE meta SET pending=0 WHERE tid=".(int)$tid.";";
	$ret = $db->exec($sql);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($sql.",".$err[2]);}

	//End transaction
	$sql = "END;";
	$ret = $db->exec($sql);//Begin transaction
	if($ret===false) {$err= $db->errorInfo();throw new Exception($sql.",".$err[2]);}

}


function InitialiseTraceDbSchema($db)
{
	$sql="CREATE VIRTUAL TABLE position USING rtree(id,minLat,maxLat,minLon,maxLon);";
	SqliteCheckTableExistsOtherwiseCreate($db,"position",$sql);
	$sql="CREATE TABLE data (id INTEGER PRIMARY KEY,ele REAL, timestamp INTEGER, tid INTEGER, segid INTEGER);";
	SqliteCheckTableExistsOtherwiseCreate($db,"data",$sql);
	$sql="CREATE TABLE meta (tid INTEGER PRIMARY KEY, public INTEGER, visible INTEGER, uid INTEGER, name STRING, description STRING, tags STRING, pending INTEGER);";
	SqliteCheckTableExistsOtherwiseCreate($db,"meta",$sql);
}

function DropTraceDb($db)
{
	SqliteDropTableIfExists($db,"position");
	SqliteDropTableIfExists($db,"data");
	SqliteDropTableIfExists($db,"meta");
}

function IsTracePubliclyDownloadable($tid)
{
	//Open DB
	$lock=GetReadDatabaseLock();
	$db = new PDO('sqlite:sqlite/traces.db');
	InitialiseTraceDbSchema($db);

	//Check visibility
	$query = "SELECT * FROM meta WHERE tid = ".(int)$tid.";";
	$ret = $db->query($query);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($query.",".$err[2]);}
	$visible = null;
	foreach($ret as $row)
	{
		$visible = $row['visible'];
		if($visible == 3) return 1;
		if($visible == 4) return 1;
		return 0;
	}

	return null;
}

function GetTraceVisibilityString($visible)
{
	if((int)$visible===1) return "private";
	if((int)$visible===2) return "trackable";
	if((int)$visible===3) return "public";
	if((int)$visible===4) return "identifiable";
	return null;
}

function TraceMetaToHtml($data)
{
	//TODO Finish this
	$out = '  <gpx_file id="'.$data['tid'].'" name="'.$data['name'].'"';// lat="'..'" lon="'..'" '."\n";
	//$out .= '            user="Hartmut Holzgraefe"';
	$out .= ' visibility="'.GetTraceVisibilityString($data['visible']).'" pending="';
	if($data['pending']==1) $out.="true";
	else $out .="false";
	$out .= '" '."\n";
	//$out .= '            timestamp="2010-10-09T09:24:19Z"';
	$out .= '>'."\n";
	$out .= '    <description>'.htmlentities($data['description'],ENT_QUOTES,"UTF-8").'</description>'."\n";
	$tagExp = explode(",",$data['tags']);
	foreach($tagExp as $tag)
		$out .= '    <tag>'.htmlentities($tag,ENT_QUOTES,"UTF-8").'</tag>'."\n";
	$out .= "  </gpx_file>\n";
	return $out;
}

function GetTraceDetails($userInfo,$urlExp)
{
	$tid = (int)$urlExp[3];
	$userId = $userInfo['userId'];
	$isPublic = IsTracePubliclyDownloadable($tid);
	if($isPublic===0)
		list ($displayName, $userId) = RequireAuth();
	//TODO Check non public trace owner against ID

	//Open DB
	$lock=GetReadDatabaseLock();
	$db = new PDO('sqlite:sqlite/traces.db');
	InitialiseTraceDbSchema($db);

	//Get meta data
	$query = "SELECT * FROM meta WHERE tid = ".(int)$tid.";";
	$ret = $db->query($query);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($query.",".$err[2]);}
	$data = null;
	foreach($ret as $row)
	{
		$data = $row;
	}
	if(is_null($data)) return array(0,null,"not-found");

	//print_r($data);
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$out .= '<osm version="0.6" generator="'.SERVER_NAME.'">'."\n";
	$out .= TraceMetaToHtml($data);
	$out .= "</osm>\n";

	return array(1,array("Content-Type:text/xml"),$out);
}

function GetTraceData($userInfo,$urlExp)
{
	$tid = (int)$urlExp[3];
	$isPublic = IsTracePubliclyDownloadable($tid);
	if($isPublic===0)
		list ($displayName, $userId) = RequireAuth();
	//TODO Check non public trace owner against ID


	$rawtracedb = new RawTraceTable();
	if(!isset($rawtracedb[$tid]))
		return array(0,null,"not-found");

	$trace = $rawtracedb[$tid];
	return array(1,array('Content-Type:text/xml'),bzdecompress($trace['gpx']));
}

function GetTraceForUser($userInfo)
{
	$uid = $userInfo['userId'];

	//Open DB
	$lock=GetReadDatabaseLock();
	$db = new PDO('sqlite:sqlite/traces.db');
	InitialiseTraceDbSchema($db);

	//Get meta data
	$query = "SELECT * FROM meta WHERE uid = ".(int)$uid.";";
	$ret = $db->query($query);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($query.",".$err[2]);}
	$data = null;

	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$out .= '<osm version="0.6" generator="'.SERVER_NAME.'">'."\n";
	foreach($ret as $row)
		$out .= TraceMetaToHtml($row);
	$out .= "</osm>\n";
	return array(1,array("Content-Type:text/xml"),$out);
}

function DeleteTrace($tid)
{
	//TODO This uses a non indexed column for selection, which is probably slow
	//Open DB
	$lock=GetWriteDatabaseLock();
	$db = new PDO('sqlite:sqlite/traces.db');
	InitialiseTraceDbSchema($db);

	//Get Ids that need to be removed
	$query = "SELECT id FROM data WHERE tid = ".(int)$tid.";";
	$ret = $db->query($query);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($query.",".$err[2]);}
	$ids = array();
	foreach($ret as $row) array_push($ids, $row['id']);

	//Begin Transaction
	$sql = "BEGIN;";
	$ret = $db->exec($sql);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($sql.",".$err[2]);}

	//Delete data from tables
	$sql = "DELETE FROM data WHERE tid = ".(int)$tid.";";
	$ret = $db->exec($sql);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($sql.",".$err[2]);}

	$sql = "DELETE FROM meta WHERE tid = ".(int)$tid.";";
	$ret = $db->exec($sql);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($sql.",".$err[2]);}

	foreach($ids as $id)
	{
	$sql = "DELETE FROM position WHERE id = ".(int)$id.";";
	$ret = $db->exec($sql);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($sql.",".$err[2]);}
	}

	//End Transaction
	$sql = "END;";
	$ret = $db->exec($sql);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($sql.",".$err[2]);}

	//Delete raw trace
	$rawtracedb = new RawTraceTable();
	if(isset($rawtracedb[(int)$tid]))
		unset($rawtracedb[(int)$tid]);

}

?>
