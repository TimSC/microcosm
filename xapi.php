<?php
require_once('system.php');
require_once('fileutils.php');

if(!ENABLE_XAPI)
{
	header ('HTTP/1.1 503 Service Unavailable');
	echo "XAPI is disabled in config.php.";
	return;
}

CallFuncByMessage(Message::SCRIPT_START,Null); 

$lock=GetReadDatabaseLock();

//$fi = fopen("log.txt","at");
//fwrite($fi,$_SERVER['REQUEST_URI']."\n");

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
	$refs = CallFuncByMessage(Message::XAPI_QUERY, array($xapiType,$bboxAr,$key,$value));
	if($refs===null)
		throw new Exception("Null response from XAPI module (if it exists)");
	else
		$osmxml = XapiQueryToXml($refs, $bbox);
	header("Content-Type:text/xml");
	echo $osmxml;
}
catch (Exception $e)
{
	header('HTTP/1.1 500 Internal Server Error');
	header("Content-Type:text/plain");
	echo "Internal server error: ".$e->getMessage()."\n";
        dprint("getTrace",$e->getTrace());
}

function XapiQueryToXml($refs,$bbox)
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

	//Return result
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$out .= '<osm version="0.6" generator="'.SERVER_NAME.'">'."\n";

	//Specify bounds
	if(!is_null($bbox)) 
	{
	$out=$out.'<bounds minlat="'.$bbox[1].'" minlon="'.$bbox[0];
	$out=$out.'" maxlat="'.$bbox[3].'" maxlon="'.$bbox[2].'"/>'."\n";
	}

	$ignoreMissing = 1;
	foreach($els as $el)
	{
		$problemFound = 0;

		//Also get the elements associated nodes and ways
		foreach($el->members as $mem)
		{
		if($mem[0] == "node")
		{
			//For each referenced nodes,
			$id = $mem[1];
			$n = CallFuncByMessage(Message::GET_OBJECT_BY_ID,array("node",(int)$id, Null));
			if(!is_object($n) and !$ignoreMissing)
				throw new Exception("node needed in XAPI way not found, node ".$id);
			if(is_object($n)) $out = $out.$n->ToXmlString()."\n";
			else $problemFound = 1;
		}
		if($mem[0] == "way")
		{
			//For each referenced way,
			$id = $mem[1];
			$w = CallFuncByMessage(Message::GET_OBJECT_BY_ID,array("way",(int)$id, Null));
			if(!is_object($w)) throw new Exception("way needed in XAPI way not found, way ".$id);

			//Get the child nodes for this way also
			foreach($w->nodes as $nd)
			{
				$id = $nd[0];
				$n = CallFuncByMessage(Message::GET_OBJECT_BY_ID,array("node",(int)$id, Null));
				if(!is_object($n) and !$ignoreMissing) 
					throw new Exception("node of way needed in XAPI way not found ".$id);
					
				if(is_object($n)) $out = $out.$n->ToXmlString()."\n";
				else $problemFound = 1;
			}

			if(is_object($w) and !$problemFound) 
				$out = $out.$w->ToXmlString()."\n";
		}
		}

		//Output XAPI matched element
		if(is_object($el) and !$problemFound)
			$out .= $el->ToXmlString();
	}

	$out .= "</osm>\n";
	return $out;
}

CallFuncByMessage(Message::SCRIPT_END,Null); 

?>
