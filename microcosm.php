<?
require_once('fileutils.php');
require_once('querymap.php');
require_once('system.php');
require_once('auth.php');


//******************************
//Start up functions and logging
//******************************

CallFuncByMessage(Message::SCRIPT_START,Null); 

////-------------------------
if(DEBUG_MODE) dprint("$_SERVER",$_SERVER);

global $PROG_ARG_STRING;
$options = getopt(PROG_ARG_STRING, $PROG_ARG_LONG);
$_GET = CommandLineOptionsSetVar($options['g'], $_GET);

//print_r($_GET);
//print_r($_SERVER);

CheckPermissions();

//Split URL for processing
$pathInfo = GetRequestPath();
$urlExp = explode("/",$pathInfo);

if(DEBUG_MODE) dprint("pathinfo",$pathInfo);

//Log the request
$fi = fopen("log.txt","at");
if($fi===False)
	throw new Exception("Failed to open log.txt file for writing: check permission.");
flock($fi, LOCK_EX);
fwrite($fi,GetServerRequestMethod());
fwrite($fi,"\t");
fwrite($fi,$pathInfo);
fwrite($fi,"\t");
if(isset($_SERVER['QUERY_STRING'])) fwrite($fi,$_SERVER['QUERY_STRING']);

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
{
	list ($displayName, $userId) = RequireAuth();
}
else
{
	$displayName = null;
	$userId = null;
}
exit(0);
//This function determines with function to call based on the URL and, if it can, responds to the client.
$processed = CallFuncByMessage(Message::API_EVENT,array($pathInfo,$urlExp,$putDataStr,$_GET,$_POST,$_FILES));
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
