<?php

include_once('config.php');

function GetCapabilities()
{

$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
$out = $out. '<osm version="0.6" generator="'.SERVER_NAME.'">';
$out = $out. "  <api>\n";
$out = $out. '    <version minimum="0.6" maximum="0.6"/>'."\n";
$out = $out. '    <area maximum="'.MAX_QUERY_AREA.'"/>'."\n";
$out = $out. '    <tracepoints per_page="'.TRACE_PAGE_SIZE.'"/>'."\n";
$out = $out. '    <waynodes maximum="'.MAX_WAY_NODES.'"/>'."\n";
$out = $out. '    <changesets maximum_elements="'.MAX_CHANGESET_SIZE.'"/>'."\n";
$out = $out. '    <timeout seconds="'.ini_get('max_execution_time').'"/>'."\n";
$out = $out. '  </api>'."\n";
$out = $out. '</osm>'."\n";

return $out;
}

?>
