<?php
require_once('system.php');

$lock=GetReadDatabaseLock();

$pathInfo = GetRequestPath();

/*if (isset($pathInfo))
  {
    print "Path info:". $pathInfo. "\n";
  } else   {
    print "no pathInfo";
  }
$urlExp = explode("/",$pathInfo);
$xapiArg = $urlExp[2];
print "xapiArg: " . $xapiArg ."\n";
$xapiArgs = explode("[",$xapiArg);

//Split predicates
$xapiType = $xapiArgs[0];
$predicates = array();
for($i=1;$i<count($xapiArgs);$i++)
{
  print "Check:" . $i . "=" . $xapiArgs[$i] . "\n";
  print_r($xapiArgs[$i]);
  $temp = explode("]",$xapiArgs[$i]);

  print ("\nExplode]:\n");

  print ("\ntemp:\n");
  print_r ($temp);

  array_push($predicates, $temp[0]);

#  print ("\npredicates:\n"); print_r ($predicates);
}

if($xapiType != "*" and $xapiType != "node" and $xapiType != "way" and $xapiType != "relation")
{
	header("Content-Type:text/plain");
	echo "Type ".$xapiType." is not supported, use node,way or relation.\n";
}*/

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
                print "Count is not 2:". count($temp);
                print_r($temp);
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
	$refs = CallFuncByMessage(Message::XAPI_QUERY, array($xapiType,$bboxAr,$key,$value));
	$kml = XapiQueryToKml($refs, $bbox,"en");
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

function WayListToPointsText($el)
{
	$out = "";
	$ignoreMissing = 1;
	$totalLat = 0.0; $totalLon = 0.0; $totalCount = 0;
	foreach($el->members as $mem)
	{
		if($mem[0]!="node") continue;
		//For each referenced nodes,
		$id = $mem[1];
		$n = CallFuncByMessage(Message::GET_OBJECT_BY_ID,array("node",(int)$id,Null));
		if(!is_object($n) and !$ignoreMissing)
			throw new Exception("node needed in XAPI way not found, node ".$id);
		if(is_object($n))
		{
			$out = $out.$n->attr['lon'].",".$n->attr['lat']."\n";

			//TODO: This type of average calculation is not really numerically stable
			$totalLon += $n->attr['lon'];
			$totalLat += $n->attr['lat'];
			$totalCount ++;
		}
	}
	$centre = null;
	if($totalCount>0) $centre = array($totalLon/$totalCount,$totalLat/$totalCount);
	return array($out,$centre);	
}

function WayToKml($el,$outer=1)
{
	$out = "";

	$closedWay = 0;
	$firstNode = array_slice($el->members,0,1);
	$lastNode = array_slice($el->members,-1,1);
	if($firstNode!==Null and $lastNode!=Null)
		$closedWay = ($firstNode[0][1]==$lastNode[0][1]);

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

	list($points,$centre) = WayListToPointsText($el);
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

function ElementToKml($el,$lang=null)
{
	//if(!isset($el->attr['lat']) or !isset($el->attr['lon'])) return "";
	//print_r($el);
	$ignoreMissing = 1;

	$name = null;
	$out = "";
	//print_r($el->tags);
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
			list($kml,$centre) = WayToKml($el,1);
			$out .= $kml;
		}

		foreach($el->members as $mem)
		{
			if($mem[0]!="way") continue;
			$wid = $mem[1];
			$role = $mem[2];

			$w = CallFuncByMessage(Message::GET_OBJECT_BY_ID,array("way",(int)$wid,Null));
			if(!is_object($w))
				throw new Exception("way needed in XAPI way not found, node ".$wid);			
			//print_r($w);
			$outer = null;
			if($role == "inner") $outer = 0;
			if($role == "outer") $outer = 1;
			if(is_null($outer)) continue; //Role must be inner or outer
			if(count($w->nodes)==0) continue; //ignore empty ways
			list($kml,$centre) = WayToKml($w,$outer);
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

function XapiQueryToKml($refs,$bbox,$lang=null)
{
	//Extract needed elements from database
	$els = array();
	foreach($refs as $elidstr)
	{
		$elIdStrExp = explode("-",$elidstr);
		$type = $elIdStrExp[0];
		$id = (int)$elIdStrExp[1];
		$obj = CallFuncByMessage(Message::GET_OBJECT_BY_ID,array($type,$id,Null));
		if(is_null($obj)) throw new Exception("Could not get element needed to fulfil XAPI query");
		array_push($els, $obj);
	}

    /*if (isset($refs)) {
          print( "\nRefs:");
          print_r($refs);
          print( "\n");
          
          foreach($refs as $elidstr)          
            {
              $elIdStrExp = explode("-",$elidstr);
              $type = $elIdStrExp[0];
              $id = (int)$elIdStrExp[1];
			  $obj = CallFuncByMessage(Message::GET_OBJECT_BY_ID,array($type,$id,Null));
              if(is_null($obj)) throw new Exception("Could not get element needed to fulfil XAPI query");
              array_push($els, $obj);
            }
        } else {
          print( "\nRefs undefined");
        }*/

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
			//print_r($el);
			$kml = ElementToKml($el,$lang);
			$out .= $kml;
		}
	}

	//$out .= "</Folder>\n";
	$out .= "</Document>\n";
	$out .= "</kml>\n";
	return $out;
}



?>
