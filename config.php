<?php

//Administrator controlled server variables

define("SERVER_NAME","Microcosm");
define("DEBUG_MODE",false);
define("VERSION_VALIDATION",true);
define("LICENSE_HUMAN","TBC");
define("INSTALL_FOLDER_DEPTH",2);
set_time_limit(60);

define("BACKEND_DATABASE","sqlite");
#define("BACKEND_DATABASE","mysql");

define("MYSQL_DB_NAME","db_map");
define("MYSQL_SERVER","localhost");
define("MYSQL_USER","map_map");
define("MYSQL_PASSWORD","4yuy34udm"); #Keeping this as default is a bad idea

define("API_READ_ONLY",false);
define("ENABLE_ANON_EDITS",false);
define("ANON_DISPLAY_NAME","Anonymous");
define("ANON_UID",100);
define("ALLOW_USER_REGISTRATION",true);
define("MIN_PASSWORD_LENGTH",6);

define("MAX_KEY_LEN",255);
define("MAX_VALUE_LEN",255);
define("MAX_USER_PERFS",150);
define("MAX_GPX_FIELD_LENGTH",255);
define("TRACE_PAGE_SIZE",1000);
define("MAX_XAPI_ELEMENTS",10000000);

define("MAX_WAY_NODES",2000);
define("MAX_CHANGESET_SIZE",50000);
define("MAX_QUERY_AREA",0.25);

//Function to report server capabilities to the client
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

return array(1,array("Content-Type:text/xml"),$out);
}

?>
