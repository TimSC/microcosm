<?php

//Search for objects that meet a specified tag search and save them as point features in an OSM file.

if(!isset($_SERVER['TERM'])) die('This script can only be run locally, not via the web server.'."\n");

include_once ('../../osmtypesstream.php');
set_time_limit(0);
//$filename = "/var/www/egypt.osm.bz2";
//$filename = "/var/www/sharm.osm.bz2";
$filename = "/var/www/spain.osm.bz2";

$search = array();
$search['tourism'] = array('*');	
$search['historic'] = array('battlefield','castle','monument');
$search['leisure'] = array('golf_course','stadium','water_park','nature_reserve');
$search['amenity'] = array('restaurant','biergarten','bicycle_rental','car_rental','bureau_de_change','theatre');

//Determine extracted file size
echo "Determining size of extracted data...\n";
$extractedSize = new ExtractGetSize();
ExtractBz2($filename,$extractedSize);

//Extract Elements
$linknodes = array();
$linkways = array();
$linkrelations = array();
$matches = array();
$count = 0;

$searching = 1;
$passNum = 0;

//for($i=0;$i<50000;$i++)
//	$linknodes[$i] = 1;

while($searching)
{
	$passNum ++;
	echo "Search Pass ".$passNum."\n";
	ChangeMissingLinkFlags(1,2);//Flag missing links at start of pass

	$xml = new OsmTypesStream();
	$xml->callback = 'ElementSearch';
	$lastPercent = 0.0;
	ExtractBz2($filename,$xml);
	//print_r($linknodes);
	$missingLinks = CountMissingLinks();
	echo "Objects still to find: ".$missingLinks. "\n";
	if($missingLinks==0) $searching = 0;

	$fin = fopen("dumpnodes".$passNum.".dat","wt");
	fwrite($fin,serialize($linknodes));	
	$fiw = fopen("dumpways".$passNum.".dat","wt");
	fwrite($fiw,serialize($linkways));	
	$fir = fopen("dumprelations".$passNum.".dat","wt");
	fwrite($fir,serialize($linkrelations));	

	//If not found after a search pass, set flagged links to "not found"
	ChangeMissingLinkFlags(2,3);

}

echo "Output results\n";
$xml = new OsmTypesStream();
$xml->callback = 'ElementOutput';
$lastPercent = 0.0;
$fi = fopen("output.osm","wt");
fwrite($fi,"<osm version='0.6'>\n");
ExtractBz2($filename,$xml);
fwrite($fi,"</osm>\n");

//**********************************************

//Link value meaning
//0 Not a dependency
//1 New dependency found
//2 Dependency to be found in this pass
//3 Link not found
//4 Link found
//Array: Link found

function CountMissingLinks()
{
	$n = 0; $w = 0; $r = 0;
	global $linknodes, $linkways, $linkrelations;
	foreach ($linknodes as $id => $deps)
	{
		if ($deps===1 or $deps===2) $n++;
	}
	foreach ($linkways as $id => $deps)
	{
		if ($deps===1 or $deps===2) $w++;
	}
	foreach ($linkrelations as $id => $deps)
	{
		if ($deps===1 or $deps===2) $r++;
	}
	echo $n." ".$w." ".$r."\n";
	return $n + $w + $r;
}

function ChangeMissingLinkFlags($searchVal,$setVal)
{
	global $linknodes, $linkways, $linkrelations;
	foreach ($linknodes as $id => $deps)
	{
		if ($deps===$searchVal) $linknodes[$id] = $setVal;
	}
	foreach ($linkways as $id => $deps)
	{
		if ($deps===$searchVal) $linkways[$id] = $setVal;
	}
	foreach ($linkrelations as $id => $deps)
	{
		if ($deps===$searchVal) $linkrelations[$id] = $setVal;
	}	
}

function AddDepToMatch(&$match, &$foundEl)
{
	foreach ($foundEl->nodes as $k => $dep)
	{
		$id = (int)$dep[0];
		if(!in_array($id,$match['n'])) array_push($match['n'],$id);
	}
	foreach ($foundEl->ways as $k => $dep)
	{
		$id = (int)$dep[0];
		if(!in_array($id,$match['w'])) array_push($match['w'],$id);
	}
	foreach ($foundEl->relations as $k => $dep)
	{
		$id = (int)$dep[0];
		if(!in_array($id,$match['r'])) array_push($match['r'],$id);
	}
}

function AddDepToLinks(&$el)
{
	global $linknodes, $linkways, $linkrelations;

	if ($el->GetType() == "node" && !isset($linknodes[$el->attr['id']])) 
	{
		$linknodes[$el->attr['id']] = 1;
	}
	if ($el->GetType() == "way" && !isset($linkways[$el->attr['id']])) 
		$linkways[$el->attr['id']] = 1;
	if ($el->GetType() == "relation" && !isset($linkrelations[$el->attr['id']])) 
		$linkrelations[$el->attr['id']] = 1;
}

function UpdateNodeDep(&$el)
{
	global $linknodes, $linkways, $linkrelations;
	if(!isset($linknodes[$el->attr['id']])) return;
	$linknodes[$el->attr['id']] = 4;
	//echo "Objects still to find: ".CountMissingLinks(). "\n";
	//print_r($linknodes);
}

function UpdateWayDep(&$el)
{
	global $linknodes, $linkways, $linkrelations;
	if(!isset($linkways[$el->attr['id']])) return;
	if(is_array($linkways[$el->attr['id']])) return;

	$linkways[$el->attr['id']] = array("n"=>array(),"w"=>array(),"r"=>array());

	foreach ($el->nodes as $k => $dep)
	{
		$id = (int)$dep[0];
		if(!isset($linknodes[$id])) 
		{
			//echo "x";
			$linknodes[$id] = 1;
		}

		if (!in_array($id,$linkways[$el->attr['id']]['n'])) 
			array_push($linkways[$el->attr['id']]['n'],$id);
	}
}

function UpdateRelationDep(&$el)
{
	global $linknodes, $linkways, $linkrelations;
	if(!isset($linkrelations[$el->attr['id']])) return;
	if(is_array($linkrelations[$el->attr['id']])) return;

	$linkrelations[$el->attr['id']] = array("n"=>array(),"w"=>array(),"r"=>array());

	foreach ($el->nodes as $k => $dep)
	{
		$id = (int)$dep[0];
		if(!isset($linknodes[$id])) 
			$linknodes[$id] = 1;

		if (!in_array($id,$linkrelations[$el->attr['id']]['n'])) 
			array_push($linkrelations[$el->attr['id']]['n'],$id);
	}

	foreach ($el->ways as $k => $dep)
	{
		$id = (int)$dep[0];
		if(!isset($linkways[$id])) 
			$linkways[$id] = 1;

		if (!in_array($id,$linkrelations[$el->attr['id']]['w'])) 
			array_push($linkrelations[$el->attr['id']]['w'],$id);
	}

	foreach ($el->relations as $k => $dep)
	{
		$id = (int)$dep[0];
		if(!isset($linkrelations[$id]))
			$linkrelations[$id] = 1;

		if (!in_array($id,$linkrelations[$el->attr['id']]['r'])) 
			array_push($linkrelations[$el->attr['id']]['r'],$id);
	}
}

function CheckElementMatches($el)
{


	global $search;
	foreach($search as $tag => $values)
	{
		if(!isset($el->tags[$tag])) continue;
		if(in_array("*", $values)) return 1;
		if(in_array($el->tags[$tag], $values)) return 1;
		//return isset($el->tags["tourism"]) or isset($el->tags["historic"]);

	}

	return 0;
	//if (!array_key_exists("type", $el->tags)) return 0;
	//return $el->tags['type'] == "multipolygon";
}

function ElementSearch($el,$progress)
{
	global $extractedSize, $count, $matches;
	global $lastPercent;

	if(is_null($el)) return;
	//echo $progress." ".$count." ".((float)$progress/(float)$extractedSize->size)."\n";

	$percent = (float)$progress / (float)$extractedSize->size;
	if ($percent - 0.001 > $lastPercent)
	{
		$missingLinks = CountMissingLinks();
		$percentToHundred = round($percent*1000.0)/10.0;
		$lastPercent = $percentToHundred / 100.0;
		echo $percentToHundred."%\n";
	}

	if(CheckElementMatches($el))
	{
		//print_r($el);
		$id = $el->attr['id'];
		$count = $count + 1;
		$match = array();
		$match['t'] = $el->GetType();
		$match['id'] = $id;
		$match['n'] = array();
		$match['w'] = array();
		$match['r'] = array();
		AddDepToMatch($match,$el);
		AddDepToLinks($el);

		//print_r($match);
	}

	$eltype = $el->GetType();
	if($eltype == "node") {UpdateNodeDep($el); return;}
	if($eltype == "way") {UpdateWayDep($el); return;}
	if($eltype == "relation") {UpdateRelationDep($el); return;}
}

//***************************************************


function ElementOutput($el,$progress)
{
	global $extractedSize, $count, $matches, $fi;
	global $linknodes, $linkways, $linkrelations;
	global $lastPercent;

	$percent = (float)$progress / (float)$extractedSize->size;
	if ($percent - 0.001 > $lastPercent)
	{
		$percentToHundred = round($percent*1000.0)/10.0;
		$lastPercent = $percentToHundred / 100.0;
		echo $percentToHundred."%\n";
	}

	if(is_null($el)) return;
	$id = (int)$el->attr['id'];
	if($el->GetType() == "node" and isset($linknodes[$id])) 
	{
		fwrite($fi,$el->ToXmlString());
	}
	if($el->GetType() == "way" and isset($linkways[$id])) 
	{
		fwrite($fi,$el->ToXmlString());
	}
	if($el->GetType() == "relation" and isset($linkrelations[$id])) 
	{
		fwrite($fi,$el->ToXmlString());
	}

}

?>
