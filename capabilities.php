<?php

include_once('config.php');

function GetCapabilities()
{

$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
$out = $out. '<osm version="0.6" generator="'.SERVER_NAME.'">';
$out = $out. "  <api>\n";
$out = $out. '    <version minimum="0.6" maximum="0.6"/>'."\n";
$out = $out. '    <area maximum="0.25"/>'."\n";
$out = $out. '    <tracepoints per_page="5000"/>'."\n";
$out = $out. '    <waynodes maximum="2000"/>'."\n";
$out = $out. '    <changesets maximum_elements="50000"/>'."\n";
$out = $out. '    <timeout seconds="'.ini_get('max_execution_time').'"/>'."\n";
$out = $out. '  </api>'."\n";
$out = $out. '</osm>'."\n";

return $out;
}

?>
