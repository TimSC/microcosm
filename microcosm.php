<?
require_once('fileutils.php');
require_once('querymap.php');
require_once('system.php');

//*******************
//User Authentication
//*******************

function RequestAuthFromUser()
{
	header('WWW-Authenticate: Basic realm="'.SERVER_NAME.'"');
	header('HTTP/1.0 401 Unauthorized');
	echo 'Authentication Cancelled';
	exit;
} 

function RequireAuth()
{
	if (!isset($_SERVER['PHP_AUTH_USER'])) {
		RequestAuthFromUser();
	}

	$login = $_SERVER['PHP_AUTH_USER'];

	$ret = CallFuncByMessage(Message::CHECK_LOGIN,array($login, $_SERVER['PHP_AUTH_PW']));
	if($ret===-1) RequestAuthFromUser();
	if($ret===0) RequestAuthFromUser();
	if(is_array($ret)) list($displayName, $userId) = $ret;
	return array($displayName, $userId);
}

//******************************
//Start up functions and logging
//******************************
CallFuncByMessage(Message::SCRIPT_START,Null); 

//Allow GET args to be set by command line
$options = getopt(PROG_ARG_STRING, $PROG_ARG_LONG);
if(isset($options["g"]))
{
	$get= $options["g"];
	if(!is_array($get)) $get = array($get);
	foreach ($get as &$value)
	{
		$kv = explode("=",$value);
		if (isset($kv[1]))
			$_GET[$kv[0]]=$kv[1];
		else
			$_GET[$kv[0]]="";
	}
}

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

//print_r( $_SERVER);
//print_r( $pathInfo);

//This function determines with function to call based on the URL and, if it can, responds to the client.
$processed = CallFuncByMessage(Message::API_EVENT,array($pathInfo,$urlExp,$putDataStr,$_GET,$_POST,$_FILES));
if(!$processed)
{
	header ('HTTP/1.1 404 Not Found');
	echo "URL not found.";
	//print_r($pathInfo);
}

//Trigger destructors acts better, rather than letting database handle going out of scope
CallFuncByMessage(Message::SCRIPT_END,Null); 

//Housekeeping? Process traces?

//TODO Close changesets that time out

?>
