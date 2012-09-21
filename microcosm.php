<?
require_once("config.php");
require_once("querymap.php");
require_once('changeset.php');
require_once('fileutils.php');
require_once('traces.php');
require_once('userdetails.php');
require_once('requestprocessor.php');
require_once('messagepump.php');

//******************************
//Start up functions and logging
//******************************

CallFuncByMessage(Message::SCRIPT_START,Null); 

//print_r($_SERVER);
CheckPermissions();

//Split URL for processing
$pathInfo = GetRequestPath();
$urlExp = explode("/",$pathInfo);

//print_r($pathInfo);

//Log the request
$fi = fopen("log.txt","at");
flock($fi, LOCK_EX);
fwrite($fi,GetServerRequestMethod());
fwrite($fi,"\t");
fwrite($fi,$pathInfo);
fwrite($fi,"\t");
fwrite($fi,$_SERVER['QUERY_STRING']);

ob_start();
var_export($_SERVER);
$serverVarDump = ob_get_contents();
ob_end_clean();
//fwrite($fi,"\t");
//fwrite($fi,$serverVarDump);

ob_start();
var_export($_POST);
$postVarDump = ob_get_contents();
ob_end_clean();
fwrite($fi,"\t");
fwrite($fi,$postVarDump);

$putdata = fopen("php://input", "r");
$putDataStr = "";
while ($data = fread($putdata, 1024))
{
	$putDataStr = $putDataStr . $data;
}
fwrite($fi,"\t");
fwrite($fi,$putDataStr);

fwrite($fi,"\n");
fflush($fi);
fclose($fi);

//If we are in read only mode, require GET method
if(API_READ_ONLY and strcmp(GetServerRequestMethod(),"GET")!=0)
{
	header ('HTTP/1.1 503 Service Unavailable');
	echo "API in read only mode.";
	return;
}

//***********************
//User Authentication
//***********************

//Authentication, if there is a server username variable
if (isset($_SERVER['PHP_AUTH_USER'])) 
	list ($displayName, $userId) = RequireAuth();

//Only allow GET method or else request authentication
if(strcmp(GetServerRequestMethod(),"GET")!=0)
	list ($displayName, $userId) = RequireAuth();
else
{
	$displayName = null;
	$userId = null;
}

//print_r( $_SERVER);
//print_r( $pathInfo);

//*****************************
//URL Request Processor
//*****************************

//Define allowed API calls
$requestProcessor = new RequestProcessor();
$requestProcessor->AddMethod("/capabilities", "GET", 'GetCapabilities', 0);
$requestProcessor->AddMethod("/0.6/capabilities", "GET", 'GetCapabilities', 0);
$requestProcessor->AddMethod("/0.6/map", "GET", 'MapQuery', 0, $_GET);
$requestProcessor->AddMethod("/0.6/user/details", "GET", 'GetUserDetails', 1);
$requestProcessor->AddMethod("/0.6/user/preferences", "GET", 'GetUserPreferences', 1);
$requestProcessor->AddMethod("/0.6/user/preferences", "SET", 'SetUserPreferences', 1);
$requestProcessor->AddMethod("/0.6/user/preferences/STR", "SET", 'SetUserPreferencesSingle', 1, 
	array($urlExp, $putDataStr));

$requestProcessor->AddMethod("/0.6/changesets", "GET", 'GetChangesets', 0, $_GET);
$requestProcessor->AddMethod("/0.6/changeset/create", "PUT", 'ChangesetOpen', 1, $putDataStr);
$requestProcessor->AddMethod("/0.6/changeset/NUM", "GET", 'GetChangesetMetadata', 0, $urlExp);
$requestProcessor->AddMethod("/0.6/changeset/NUM", "PUT", 'ChangesetUpdate', 1, array($urlExp, $putDataStr));

$requestProcessor->AddMethod("/0.6/changeset/NUM/upload", "POST", 'ChangesetUpload', 1, array($urlExp, $putDataStr));
$requestProcessor->AddMethod("/0.6/changeset/NUM/expand_bbox", "POST", 'ChangesetExpandBbox', 1, 
	array($urlExp, $putDataStr));
$requestProcessor->AddMethod("/0.6/changeset/NUM/download", "GET", 'GetChangesetContents', 0, $urlExp);
$requestProcessor->AddMethod("/0.6/changeset/NUM/close", "PUT", 'ChangesetClose', 1, $urlExp);

$requestProcessor->AddMethod("/0.6/ELEMENT/NUM", "GET", 'MapObjectQuery', 0, $urlExp);
$requestProcessor->AddMethod("/0.6/ELEMENT/NUM", "PUT", 'ProcessSingleObject', 1, 
	array($urlExp,$putDataStr,"modify"));
$requestProcessor->AddMethod("/0.6/ELEMENT/NUM", "DELETE", 'ProcessSingleObject', 1, 
	array($urlExp,$putDataStr,"delete"));
$requestProcessor->AddMethod("/0.6/ELEMENT/create", "PUT", 'ProcessSingleObject', 1, 
	array($urlExp,$putDataStr,"create"));
$requestProcessor->AddMethod("/0.6/ELEMENT/NUM/NUM", "GET", 'MapObjectQuery', 0, $urlExp);
$requestProcessor->AddMethod("/0.6/ELEMENT/NUM/history", "GET", 'MapObjectFullHistory', 0, $urlExp);
$requestProcessor->AddMethod("/0.6/ELEMENT/NUM/full", "GET", 'GetFullDetailsOfElement', 0, $urlExp);
$requestProcessor->AddMethod("/0.6/ELEMENT/NUM/relations", "GET", 'GetRelationsForElement', 0, $urlExp);
$requestProcessor->AddMethod("/0.6/node/NUM/ways", "GET", 'GetWaysForNode', 0, $urlExp);
$requestProcessor->AddMethod("/0.6/ELEMENTS", "GET", 'MultiFetch', 0, array($urlExp,$_GET));

$requestProcessor->AddMethod("/0.6/trackpoints", "GET", 'GetTracesInBbox', 0, $_GET);
$requestProcessor->AddMethod("/0.6/user/gpx_files", "GET", 'GetTraceForUser', 1);

$requestProcessor->AddMethod("/0.6/gpx/create", "POST", 'InsertTraceIntoDb', 1, array($_FILES,$_POST));
$requestProcessor->AddMethod("/0.6/gpx/NUM/details", "GET", 'GetTraceDetails', 0, $urlExp);
$requestProcessor->AddMethod("/0.6/gpx/NUM/data", "GET", 'GetTraceData', 0, $urlExp);

//This function determines with function to call based on the URL and, if it can, responds to the client.
$processed = $requestProcessor->Process($pathInfo);
if(!$processed)
{
	header ('HTTP/1.1 404 Not Found');
	echo "URL not found.";
}

//Trigger destructors acts better, rather than letting database handle going out of scope
CallFuncByMessage(Message::SCRIPT_END,Null); 

//Housekeeping? Process traces?

//TODO Close changesets that time out

?>
