<?php

if(!isset($_SERVER['TERM'])) die('This script can only be run locally, not via the web server.'."\n");

include_once ('../../osmtypesstream.php');
set_time_limit(0);

$xml = new OsmTypesStream();
$xml->callback = 'ElementOutput';
$lastPercent = 0.0;
$filename = "/var/www/timscmap2011-12-26.osm.bz2";

$idmapping = array('node'=>array(), 'way'=>array(), 'relation'=>array());
$nextId = array('node'=>-1, 'way'=>-1, 'relation'=>-1);
$out = fopen("output.osm","w");
fwrite($out,"<?xml version='1.0' encoding='UTF-8'?>\n<osm version='0.6' generator='Microcosm'>\n");

ExtractBz2($filename,$xml);

fwrite($out,"</osm>\n");
fflush($out);

function ElementOutput($el,$progress)
{
	global $idmapping, $nextId, $out;
	//print_r($el);

	//Assign new ID
	$typ = $el->GetType();
	$oldid = $el->attr['id'];

	$newid = $nextId[$typ];
	$nextId[$typ] = $nextId[$typ] - 1;
	$el->attr['id'] = $newid;
	print $typ." ".$oldid." ".((int)$newid)."\n";

	if(isset($el->attr['changeset']))
		unset($el->attr['changeset']);
	if(isset($el->attr['visible']))
		unset($el->attr['visible']);
	if(isset($el->attr['version']))
		unset($el->attr['version']);

	$idmapping[$typ][$oldid] = $newid;

	//Update members
	for($i=0; $i < count($el->members);$i++)
	{
		$m = $el->members[$i];
		//print_r($m);
		$n = $idmapping[$m[0]][$m[1]];
		//print $m[0]." ".$m[1]." ".$n."\n";
		$el->members[$i][1] = $n;
	}
	
	//Write to output
	print_r($el->ToXmlString());
	fwrite($out,$el->ToXmlString());
}


?>
