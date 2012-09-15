<?
require_once("config.php");
require_once("querymap.php");
require_once('changeset.php');
require_once('capabilities.php');
require_once('fileutils.php');
include_once('userdetails.php');

//******************************
//Start up functions and logging
//******************************

//print_r($_SERVER);
CheckPermissions();

//Convert path to internally usable format
if(isset($_SERVER['PATH_INFO'])) 
	$pathInfo = $_SERVER['PATH_INFO'];
if(!isset($pathInfo) and isset($_SERVER['REDIRECT_URL'])) 
{
	$pathInfo = $_SERVER['REDIRECT_URL'];
	$pathInfoExp = explode("/",$pathInfo);
	$pathInfo = "/".implode("/",array_slice($pathInfoExp,INSTALL_FOLDER_DEPTH));
}
if(!isset($pathInfo)) die("Could not determine URL path");
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

//***********************
//User Authentication
//***********************

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
	$users = explode("\n",file_get_contents(".users.txt"));
	$displayName = null;
	//foreach($users as $user)
		
	foreach($users as $user)
	{
		$fields = explode(";",$user);

		if (strcmp($login,$fields[0])==0)
		{
			if(strcmp($_SERVER['PHP_AUTH_PW'],$fields[1])!=0)
			{
				//echo "password didn't match";
				RequestAuthFromUser();
			}

			$displayName = $fields[2];
			$userId = (int)$fields[3];
			break;
		}
	}

	if($displayName == null)
	{
		if(ENABLE_ANON_EDITS)
			return array(ANON_DISPLAY_NAME, ANON_UID);

		//echo "no such user";	
		RequestAuthFromUser();
	}

	return array($displayName, $userId);
}


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

//Output variables
$header = null;
$content = null;

//************************
//API Related Functions
//************************

//Get API capabilities
if(strcmp($pathInfo,"/capabilities")==0 or strcmp($pathInfo,"/0.6/capabilities")==0)
{
	RequireMethod("GET");
	$header =("Content-Type:text/xml");
	$content = GetCapabilities();
}

//All subsequent calls are only for API 0.6 - block access to any other
if(is_null($content) and strncmp($pathInfo,"/0.6/",5)!=0)
{
	header ('HTTP/1.1 404 Not Found');
	echo "URL not found.";
	return;
}

//*****************************
//User details and perferences
//*****************************

//Split URL for processing
$urlExp = explode("/",$pathInfo);

if(count($urlExp) >= 3 and strcmp($urlExp[2],"user")==0)
{
//Get user details
if(count($urlExp) == 4 && strcmp($urlExp[3],"details")==0)
{
	RequireMethod("GET");
	if($userId == null) list ($displayName, $userId) = RequireAuth();
	
	$header = ("Content-Type:text/xml");
	//$section = file_get_contents("details.xml",FILE_USE_INCLUDE_PATH);
	//echo($section);	
	$content = GetUserDetails($userId);
}

//User perferences GET
if(count($urlExp) == 4 && strcmp($urlExp[3],"perferences")==0)
{
	if($userId == null) list ($displayName, $userId) = RequireAuth();

	if(strcmp(GetServerRequestMethod(),"GET")==0)
	{
		RequireMethod("GET");
		$header = ("Content-Type:text/xml");
		$content = GetUserPreferences($userId);
	}

	if(strcmp(GetServerRequestMethod(),"PUT")==0)
	{
		RequireMethod("PUT");
		$data = $putDataStr;
		SetUserPreferences($userId,$data);
		return;
	}
}

//User perferences PUT
if(count($urlExp) == 5 && strcmp($urlExp[3],"perferences")==0)
{
	RequireMethod("PUT");
	$key = $urlExp[4];
	$value = $putDataStr;
	if(strlen($key)>255 or strlen($value)>255)
	{
		$header = 'HTTP/1.1 413 Request Entity Too Large';
		$content = "Key or value too large";		
		return;
	}
	
	$ret = SetUserPreferencesSingle($userId,$key,$value);
	if($ret!=1)
	{
		//TODO tidy
		print_r($ret);
		exit();
	}

	if($ret=="not-implemented")
	{
		header('HTTP/1.1 501 Not Implemented');
		echo "This feature has not been implemented.";
		return;
	}

	return;
}

}

//*************************
//Main map query function
//*************************

//Query map
//print_r($pathInfo);
if(strncmp($pathInfo,"/0.6/map",8)==0)
{
	//	array (
	//  'bbox' => '-0.5991502,51.2832874,-0.5941581,51.2861896',

	RequireMethod("GET");
	$bboxExp = explode(",",$_GET['bbox']);
	$bboxExp = array_map('floatval', $bboxExp);

	$header = ("Content-Type:text/xml");
	$content=MapQuery($bboxExp);
}

// Some stuff to translate errors to the correct HTML headers

function ProcessErrorsSendToClient(&$data,$changesetId)
{
	$header = null;
	$content = null;
	if(strcmp($data,"already-closed")==0)
	{	
		//Example: "The changeset 5960426 was closed at 2010-10-05 11:18:26 UTC"
		$header = 'HTTP/1.1 409 Conflict';
		$closedTime = date("c", GetChangesetClosedTime($changesetId)); //ISO 8601
		$content = "The changeset ".(int)$changesetId." was closed at ".$closedTime;
	}

	if(strcmp($data,"session-id-mismatch")==0)
	{	
		$header = 'HTTP/1.1 409 Conflict';
		$content = "Inconsistent changeset id.";
	}

	if(strcmp($data,"invalid-xml")==0)
	{
		$header = 'HTTP/1.1 400 Bad Request';
		$content = "Invalid XML input";
	}

	if(strcmp($data,"invalid-xml")==0)
	{	
		$header = 'HTTP/1.1 413 Request Entity Too Large';
		$content = "Request Entity Too Large";
	}

	if(strcmp($data,"no-such-changeset")==0)
	{	
		$header = 'HTTP/1.1 409 Conflict';
		$content = "No such changeset.";
	}

	if(strcmp($data,"not-found")==0)
	{
		$header = 'HTTP/1.1 404 Not Found';
		$content = "Object not found.";
	}

	if(strcmp($data,"object-not-found")==0)
	{	
		$header = 'HTTP/1.1 409 Conflict';
		$content = "Modified object not found in database.";
	}

	if(strcmp($data,"bad-request")==0)
	{	
		$header = 'HTTP/1.1 400 Bad Request';
		$content = "Bad request.";
	}

	if(strcmp($data,"gone")==0)
	{	
		$header = 'HTTP/1.1 410 Gone';
		$content = "Requested element has been deleted.";
	}
	
	if(strcmp($data,"not-implemented")==0)
	{
		$header = ('HTTP/1.1 501 Not Implemented');
		$content = "This feature has not been implemented.";
	}

	if(strncmp($data,"deleting-would-break,",21)==0)
	{
		//Example: Error: Precondition failed: Node 31567970 is still used by way 4733859.
		$errorinfo = explode(",",$data);
		$content = "Precondition failed: Node ".$errorinfo[1]." is still used by ".$errorinfo[2]." ".$errorinfo[3].".";
		$header = array('HTTP/1.1 412 Precondition failed', 'Error: '.$content);
	}

	if(strncmp($data,"version-mismatch,",17)==0)
	{	
		$mismatch = explode(",",$data);
		//Example: Version mismatch: Provided 1, server had: 2 of Node 354516541
		//header('HTTP/1.1 409 Conflict');
		
		//$header= ("Content-Type:text/plain");
		//echo "Version mismatch.".$ret;
		$content = "Version mismatch: Provided ".$mismatch[1].", server had: ".$mismatch[2]." of ".ucwords($mismatch[3])." ".$mismatch[4];
		$header = array('HTTP/1.1 409 Conflict', 'Error: '.$content);
		//header('Error: '.$content);
		#$content ="Version mismatch: Provided 1, server had: 2 of Node 354516541";
	}
	
	if($content != null) return array($header,$content);
	return null;
}

//************************
//Changetset API
//************************

//Get changsets data
if(strcmp($pathInfo,"/0.6/changesets")==0) ///0.6/changesets?user=6809&open=true
{
	RequireMethod("GET");
	require_once('changeset.php');
	$header = ("Content-Type:text/xml");
	$content = (GetChangesets($_GET));
}

//Create Changeset
if(strcmp($pathInfo,"/0.6/changeset/create")==0)
{
	RequireMethod("PUT");
	$header= ("Content-Type:text/plain");
	$newChangesetId = ChangesetOpen($putDataStr,$displayName,$userId);
	$content = $newChangesetId;
}

function OperateOnChangeset($changesetId,$action,$putDataStr,$displayName,$userId)
{
	if(strcmp($action,"upload")==0)
	{
		RequireMethod("POST");

		try
		{
		$ret = ChangesetUpload($changesetId,$putDataStr,$displayName,$userId);
		}
		catch (InvalidArgumentException $e)
		{
			return array('HTTP/1.1 400 Bad Request',$e->getMessage());
		}

		$errFound = ProcessErrorsSendToClient($ret,$changesetId);
		if($errFound != null) {return $errFound;}

		return array("Content-Type:text/xml",$ret);
	}

	//if(strcmp($action,"close")==0)
	//{
	//	ChangesetClose($changesetId);
	//	#Nothing returned
	//	return array(null,"");		
	//}
	return null;
}

//Changeset API stuff
if(strncmp($pathInfo,"/0.6/changeset/",15)==0 and count($urlExp)>=4 and is_numeric($urlExp[3]))
{
	$changesetId = (int)$urlExp[3];

	$csUser = GetChangesetUid($changesetId);
	if($csUser == null)
	{
		header('HTTP/1.1 404 Not Found');
		echo "Changeset not found.";
		return;
	}

	//Only allow non-get methods on your own changesets
	if(strcmp(GetServerRequestMethod(),"GET")!=0)
		if($userId != $csUser)
		{
			header('HTTP/1.1 409 Conflict');
			echo "Your user id is not associated with that changeset.";
			return;
		}

	//Upload diff data to changeset
	if(is_numeric($urlExp[3]) and count($urlExp)==5 and strcmp($urlExp[4],"upload")==0)
	{
		$action=$urlExp[4];
		$ret = OperateOnChangeset($changesetId,$action,$putDataStr,$displayName,$userId);
		//echo is_array($ret);
		if(is_array($ret)) {list($header,$content) = $ret;}
	}

	//Upload changeset meta data
	if(is_numeric($urlExp[3]) and count($urlExp)==4 and strcmp(GetServerRequestMethod(),"PUT")==0)
	{
		$cid = (int)$urlExp[3];
		//Do update
		$ret = ChangesetUpdate($cid,$putDataStr,$displayName,$userId);
		//Handle error
		if($ret != 1)
		{
			echo $ret; exit();
			//TODO tidy
		}

		//Return updated changeset info		
		$cs = GetChangesetMetadata($cid);
		if(!is_null($cs))
		{
			$header = "Content-Type:text/xml";
			$content = $cs;
		}
	}

	//Download changeset meta data
	if(is_numeric($urlExp[3]) and count($urlExp)==4 and strcmp(GetServerRequestMethod(),"GET")==0)
	{
		$cs = GetChangesetMetadata((int)$urlExp[3]);
		if(!is_null($cs))
		{
			$header = "Content-Type:text/xml";
			$content = $cs;
		}
	}

	//Download changeset contents
	if(is_numeric($urlExp[3]) and count($urlExp)==5 and strcmp($urlExp[4],"download")==0)
	{
		$cs = GetChangesetContents((int)$urlExp[3]);
		if(!is_null($cs))
		{
			$header = "Content-Type:text/xml";
			$content = $cs;
		}
	}

	//Close changeset
	if(is_numeric($urlExp[3]) and count($urlExp)==5 and strcmp($urlExp[4],"close")==0)
	{
		ChangesetClose($changesetId);
		#Nothing returned
		return array(null,"");		
	}
}



//**********************************
//API for modifying single objects
//**********************************

if(count($urlExp)>=4)
if(strcmp($urlExp[2],"way")==0 or strcmp($urlExp[2],"node")==0 or strcmp($urlExp[2],"relation")==0)
{
	$type = $urlExp[2];
	$method = $urlExp[3];

	//Create a single map object
	if(strcmp($method,"create")==0 and count($urlExp)==4)
	{
		RequireMethod("PUT");
		try
		{
		$ret = ProcessSingleObject($method,$putDataStr,$displayName,$userId);
		}
		catch (InvalidArgumentException $e)
		{
			$header = ('HTTP/1.1 400 Bad Request');
			$content = $e->getMessage();
		}

		if(is_null($header))
		{
			$header = "Content-Type:text/plain";
			$content = $ret;
		}
	}

	//Get a single map object
	if(strcmp(GetServerRequestMethod(),"GET")==0)
	{
		$id = null;
		$version = null;

		//Get current version
		if (is_numeric($urlExp[3]) and count($urlExp)==4)
		{
			$id = (int)$urlExp[3];
			$version = null;
		}

		//Request a specific version of an object
		if (count($urlExp)==5 and is_numeric($urlExp[3]) and is_numeric($urlExp[4]))
		{
			$id = (int)$urlExp[3];
			$version = (int)$urlExp[4];		
		}

		//Process request
		$ret = null;
		if($id != null)
		{
			$ret = MapObjectQuery($type, $id, $version);
		}

		//Request a specific version of an object
		if (count($urlExp)==5 and is_numeric($urlExp[3]) and strcmp($urlExp[4],"history")==0)
		{
			$id = (int)$urlExp[3];
			$ret = MapObjectFullHistory($type, $id);
		}

		//Send response to client
		if($ret != null)
		{
			$errFound = ProcessErrorsSendToClient($ret,null);
			if($errFound != null) 
				list($header,$content)= $errFound;
			else
			{
				$header = ("Content-Type:text/xml");
				$content = $ret;
			}
		}


	}

	if (is_numeric($urlExp[3]) and count($urlExp)==4)
	{
	//Modify or delete a single map object
	if(strcmp(GetServerRequestMethod(),"PUT")==0 or strcmp(GetServerRequestMethod(),"DELETE")==0)
	{
		if(strcmp(GetServerRequestMethod(),"PUT")==0) $method = "modify";
		if(strcmp(GetServerRequestMethod(),"DELETE")==0) $method = "delete";

		try{
			$ret = ProcessSingleObject($method,$putDataStr,$displayName,$userId);
		}
		catch (InvalidArgumentException $e)
		{
			header('HTTP/1.1 400 Bad Request');
			echo $e->getMessage();
			return 1;			
		}

		$errFound = ProcessErrorsSendToClient($ret,null);
		if($errFound != null) 
			list($header,$content)= $errFound;
		else
		{
			$header = "Content-Type:text/plain";
			$content = $ret;
		}
	}
	}


}

//Get full details of an element
if(count($urlExp)==5 and (strcmp($urlExp[2],"way")==0 or strcmp($urlExp[2],"relation")==0))
{
	//Request full details of a specific object
	if (is_numeric($urlExp[3]) and strcmp($urlExp[4],"full")==0)
	{
		$type = $urlExp[2];
		$id = (int)$urlExp[3];
		$ret = GetFullDetailsOfElement($type,$id);

		//Send response to client
		if(!is_null($ret))
		{
			$errFound = ProcessErrorsSendToClient($ret,null);
			if(!is_null($errFound)) 
				list($header,$content)= $errFound;
			else
			{
				$header = ("Content-Type:text/xml");
				$content = $ret;
			}
		}

	}
}

//Get relations for element
if(count($urlExp)==5 and (strcmp($urlExp[2],"way")==0 or strcmp($urlExp[2],"relation")==0 or strcmp($urlExp[2],"node")==0))
{
	if (is_numeric($urlExp[3]) and strcmp($urlExp[4],"relations")==0)
	{
		$type = $urlExp[2];
		$id = (int)$urlExp[3];
		$ret = GetRelationsForElement($type,$id);

		//Send response to client
		if(!is_null($ret))
		{
			$errFound = ProcessErrorsSendToClient($ret,null);
			if(!is_null($errFound)) 
				list($header,$content)= $errFound;
			else
			{
				$header = ("Content-Type:text/xml");
				$content = $ret;
			}
		}

	}
}

//Get ways for node
if(count($urlExp)==5 and strcmp($urlExp[2],"node")==0)
{
	//Request full details of a specific object
	if (is_numeric($urlExp[3]) and strcmp($urlExp[4],"ways")==0)
	{
		$id = (int)$urlExp[3];
		$ret = GetWaysForNode($id);

		//Send response to client
		if(!is_null($ret))
		{
			$errFound = ProcessErrorsSendToClient($ret,null);
			if(!is_null($errFound)) 
				list($header,$content)= $errFound;
			else
			{
				$header = ("Content-Type:text/xml");
				$content = $ret;
			}
		}
	}
}

//Fetch multiple objects
if(count($urlExp)>=3 and (strcmp($urlExp[2],"ways")==0 
	or strcmp($urlExp[2],"nodes")==0 
	or strcmp($urlExp[2],"relations")==0))
{
	$type = $urlExp[2];
	if (!isset($_GET[$type]))
	{
		$header = 'HTTP/1.1 400 Bad Request';
		$content = "URL arguments are not consistent";
	}
	
	$ids = explode(",",$_GET[$type]);
	$singularType = substr($type,0,-1);
	$ret = MultiFetch($singularType, $ids);

	//Send response to client
	if(!is_null($ret))
	{
		$errFound = ProcessErrorsSendToClient($ret,null);
		if(!is_null($errFound)) 
			list($header,$content)= $errFound;
		else
		{
			$header = ("Content-Type:text/xml");
			$content = $ret;
		}
	}
}

//***************************
//Send response to client
//***************************

if(is_null($content))
{
	$header = 'HTTP/1.1 501 Not Implemented';
	$content = "This feature has not been implemented.";
}

if (!is_null($header) and is_array($header))
	foreach($header as $headerline) header($headerline);

if (!is_null($header) and !is_array($header)) header($header);
echo $content;

if(!DEBUG_MODE)
{
	//Logging server response
	$fi = fopen("log.txt","at");
	flock($fi, LOCK_EX);
	fwrite($fi,"Header:".$header."\n");
	fwrite($fi,"Response:".$content."\n");
}

//Housekeeping?

//TODO Close changesets that time out

?>
