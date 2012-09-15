<?php

require_once('config.php');
require_once('dbutils.php');
require_once('fileutils.php');

function GetTracesInBbox($bbox,$page)
{

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
	return $out;

}

function InsertTraceIntoDb($gpxString, $uid, $public, $visible, $name, $description, $tags)
{
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
	if($count == 0) return 0; //We require at least one point

	//Open DB
	$lock=GetWriteDatabaseLock();
	$db = new PDO('sqlite:sqlite/traces.db');
	//DropTraceDb($db);
	InitialiseTraceDbSchema($db);

	//Start transaction
	$sql = "BEGIN;";
	$ret = $db->exec($sql);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($sql.",".$err[2]);}
	
	//Insert metadata
	$sql = "INSERT INTO meta (tid,public,visible,uid,name,description,tags) VALUES (null,";
	$sql .= (int)$public;
	$sql .= ",".(int)$visibleNum;
	$sql .= ",".(int)$uid;
	$sql .= ",'".sqlite_escape_string($name)."'";
	$sql .= ",'".sqlite_escape_string($description)."'";
	$sql .= ",'".sqlite_escape_string($tags)."');";
	$ret = $db->exec($sql);
	if($ret===false) {$err= $db->errorInfo();throw new Exception($sql.",".$err[2]);}
	$tid = $db->lastInsertId();

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

	//End transaction
	$sql = "END;";
	$ret = $db->exec($sql);//Begin transaction
	if($ret===false) {$err= $db->errorInfo();throw new Exception($sql.",".$err[2]);}

	return $tid;
}

function InitialiseTraceDbSchema($db)
{
	$sql="CREATE VIRTUAL TABLE position USING rtree(id,minLat,maxLat,minLon,maxLon);";
	SqliteCheckTableExistsOtherwiseCreate($db,"position",$sql);
	$sql="CREATE TABLE data (id INTEGER PRIMARY KEY,ele REAL, timestamp INTEGER, tid INTEGER, segid INTEGER);";
	SqliteCheckTableExistsOtherwiseCreate($db,"data",$sql);
	$sql="CREATE TABLE meta (tid INTEGER PRIMARY KEY, public INTEGER, visible INTEGER, uid INTEGER, name STRING, description STRING, tags STRING);";
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
	$out .= ' visibility="'.GetTraceVisibilityString($data['visible']).'" pending="false" '."\n";
	//$out .= '            timestamp="2010-10-09T09:24:19Z"';
	$out .= '>'."\n";
	$out .= '    <description>'.htmlentities($data['description'],ENT_QUOTES,"UTF-8").'</description>'."\n";
	$tagExp = explode(",",$data['tags']);
	foreach($tagExp as $tag)
		$out .= '    <tag>'.htmlentities($tag,ENT_QUOTES,"UTF-8").'</tag>'."\n";
	$out .= "  </gpx_file>\n";
	return $out;
}

function GetTraceDetails($tid)
{
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
	if(is_null($data)) return null;

	//print_r($data);
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$out .= '<osm version="0.6" generator="'.SERVER_NAME.'">'."\n";
	$out .= TraceMetaToHtml($data);
	$out .= "</osm>\n";

	return $out;
}

function GetTraceData($tid)
{
	//TODO Implement this
	header ('HTTP/1.1 501 Not Implemented');
	echo "This feature has not been implemented.";
	exit();
}

function GetTraceForUser($uid)
{
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
	return $out;
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

}

?>
