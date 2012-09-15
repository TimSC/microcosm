<?php
require_once('modelfactory.php');

$lock=GetReadDatabaseLock();
$db = OsmDatabase();

$pathInfo = GetRequestPath();
$urlExp = explode("/",$pathInfo);
$xapiArg = $urlExp[2];
$xapiArgs = explode("[",$xapiArg);

//Split predicates
$xapiType = $xapiArgs[0];
$predicates = array();
for($i=1;$i<count($xapiArgs);$i++)
{
	$temp = explode("]",$xapiArgs[$i]);
	array_push($predicates, $temp[0]);
}

if($xapiType != "*" and $xapiType != "node" and $xapiType != "way" and $xapiType != "relation")
{
	header("Content-Type:text/plain");
	echo "Type ".$xapiType." is not supported.\n";
}

//TODO Handle non-predicate bbox

//Process predicates into individual variables
$bbox = null;
$key = null;
$value = null;
foreach ($predicates as $pred)
{	
	$temp = explode("=",$pred);
	if(count($temp)!=2)
	{	
		//continue;
		header("Content-Type:text/plain");
		echo "Predicate not supported in this implementation.\n";
		exit(0);
	}
	$k = $temp[0];
	$v = $temp[1];
	if($k == "bbox") $bbox = $v;
	else {$key = $k; $value = $v;}

}

if($value == "*") $value = null;
if($key == "*")
{
	header("Content-Type:text/plain");
	echo "Key wildcard is not supported.\n";
	exit(0);
}

if(is_null($key))
{
	header("Content-Type:text/plain");
	echo "Key and value predicate must be specified in this XAPI implentation.\n";
	exit(0);
}

//Convert bbox into float array
$bboxAr= null;
if(!is_null($bbox))
{
	$bboxAr = explode(",",$bbox);
	$bboxAr = array_map('floatval', $bboxAr);
}

//TODO validate bbox



//Query database
try
{
	$refs = $db->QueryXapi($xapiType,$bboxAr,$key,$value);
	$kml = XapiQueryToKml($refs, $bbox, $db,"en");
	//header("Content-Type:application/vnd.google-earth.kml+xml");
	//header("Content-Type:text/plain");
	header("Content-Type:text/xml");
	
	echo $kml;
}
catch (Exception $e)
{
	header('HTTP/1.1 500 Internal Server Error');
	header("Content-Type:text/plain");
	echo "Internal server error: ".$e->getMessage()."\n";
	if(DEBUG_MODE) print_r($e->getTrace());
}

function WayListToPointsText($el,$db)
{
	$out = "";
	$ignoreMissing = 1;
	$totalLat = 0.0; $totalLon = 0.0; $totalCount = 0;
	foreach($el->nodes as $nd)
	{
		//For each referenced nodes,
		$id = $nd[0];
		$n = $db->GetElementById("node",(int)$id);
		if(!is_object($n) and !$ignoreMissing)
			throw new Exception("node needed in XAPI way not found, node ".$id);
		if(is_object($n))
		{
			$out = $out.$n->attr['lon'].",".$n->attr['lat']."\n";
			$totalLon += $n->attr['lon'];
			$totalLat += $n->attr['lat'];
			$totalCount ++;
		}
	}

	$centre = null;
	if($totalCount>0) $centre = array($totalLon/$totalCount,$totalLat/$totalCount);
	return array($out,$centre);	
}

function WayToKml($el,$db,$outer=1)
{
	$out = "";
	$lastNode = array_slice($el->nodes,-1);
	$closedWay = ($el->nodes[0][0]==$lastNode[0][0]);

	if($closedWay)
	{
		$out .= "<Polygon>\n";
		if($outer) $out .= "<outerBoundaryIs>";
		else $out .= "<innerBoundaryIs>";
		$out .= "<LinearRing><coordinates>\n";
	}
	else
	{
		$out .= "<LineString>";
		$out .= "<extrude>1</extrude>";
       		$out .= "<tessellate>1</tessellate>";
		//$out .= "<altitudeMode>absolute</altitudeMode>";
		$out .= "<coordinates>";
	}

	list($points,$centre) = WayListToPointsText($el,$db);
	$out .= $points;

	if($closedWay)
	{
		$out .= "</coordinates></LinearRing>";
		if($outer) $out .= "</outerBoundaryIs>\n";
		else $out .= "</innerBoundaryIs>\n";
		$out .= "</Polygon>\n";
	}
	else
	{
		$out .= "</coordinates>";
		$out .= "</LineString>";
	}
	return array($out,$centre);
}

function TagsToDescription($el)
{
	$out = "<description>";
	foreach($el->tags as $k => $v)
	{
		$out .= htmlspecialchars($k."=".$v."<br/>")."\n";
	}

	$out .= "</description>";
	return $out;
}

function ElementToKml($el,$db,$lang=null)
{
	//if(!isset($el->attr['lat']) or !isset($el->attr['lon'])) return "";
	//print_r($el);
	$ignoreMissing = 1;

	$name = null;
	$out = "";
	if(!is_null($lang) and is_null($name) and isset($el->tags['name:'.$lang])) 
		$name = $el->tags['name:'.$lang];
	if(is_null($name) and isset($el->tags['name'])) $name = $el->tags['name'];

	if($el->GetType() == "node")
	{
		$out .= "<Placemark>\n";
		if(!is_null($name)) $out .= "<name>".htmlspecialchars($name)."</name>\n";
		$out .= TagsToDescription($el);
		$out .= "<Point>\n";
		$out .= "<coordinates>".$el->attr['lon'].",".$el->attr['lat']."</coordinates>\n";
		$out .= "</Point>\n";
		$out .= "</Placemark>\n";
		return $out;
	}
	if($el->GetType() == "way" or $el->GetType() == "relation")
	{
		if($el->GetType() == "relation") 
		{
			//Only process multi polygon relations
			if(!isset($el->tags['type'])) return null;
			if($el->tags['type']!="multipolygon") return null;
		}

		$centre = null;
		$out .= "<Placemark>\n";
		if(!is_null($name)) $out .= "<name>".htmlspecialchars($name)."</name>\n";

		$out .= TagsToDescription($el);
		$out .= "<MultiGeometry>\n";

		if($el->GetType() == "way")
		{
			list($kml,$centre) = WayToKml($el,$db,1);
			$out .= $kml;
		}

		foreach($el->ways as $data)
		{
			list($wid,$role) = $data;
			$w = $db->GetElementById("way",(int)$wid);
			if(!is_object($w))
				throw new Exception("way needed in XAPI way not found, node ".$id);			
			//print_r($w);
			$outer = null;
			if($role == "inner") $outer = 0;
			if($role == "outer") $outer = 1;
			if(is_null($outer)) continue; //Role must be inner or outer
			if(count($w->nodes)==0) continue; //ignore empty ways
			list($kml,$centre) = WayToKml($w,$db,$outer);
			$out .= $kml;		
		}

		if(!is_null($centre))
		{
			$out .= "<Point>\n";
			$out .= "<coordinates>".$centre[0].",".$centre[1]."</coordinates>\n";
			$out .= "</Point>\n";
		}

		$out .= "</MultiGeometry>\n";
		$out .= "</Placemark>\n";
		return $out;
	}
	return null;
}

function XapiQueryToKml($refs,$bbox,&$db,$lang=null)
{
	//Extract needed elements from database
	$els = array();
	foreach($refs as $elidstr)
	{
		$elIdStrExp = explode("-",$elidstr);
		$type = $elIdStrExp[0];
		$id = (int)$elIdStrExp[1];
		$obj = $db->GetElementById($type,$id);
		if(is_null($obj)) throw new Exception("Could not get element needed to fulfil XAPI query");
		array_push($els, $obj);
	}

	//Return result
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$out .= '<kml xmlns="http://www.opengis.net/kml/2.2" generator="'.SERVER_NAME.'">'."\n";
	$out .= "<Document>\n";
	//$out .= "<Folder>\n";

	$ignoreMissing = 1;
	foreach($els as $el)
	{
		$problemFound = 0;

		//Output XAPI matched element
		if(is_object($el) and !$problemFound)
		{
			$kml = ElementToKml($el,$db,$lang);
			$out .= $kml;
		}
	}

	//$out .= "</Folder>\n";
	$out .= "</Document>\n";
	$out .= "</kml>\n";
	return $out;
}



?>