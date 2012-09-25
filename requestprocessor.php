<?php

require_once('messagepump.php');
require_once('auth.php');

class RequestProcessor
{
	var $methods = array();
	var $userId = Null;
	var $displayName = Null;

	function __construct($eventType, $content, $listenVars) {
	   /// TODO : 
	}	 

	function AddMethod($url, $method, $func, $authReq = 0, $arg = Null)
	{
		array_push($this->methods,array('url'=>$url,
			'method'=>$method,'func'=>$func,
			'authReq'=>$authReq,'arg'=>$arg));
	}

	function DoesUrlMatchPattern($url, $pattern)
	{
		//echo $url." ".$pattern."\n";
		if (strcmp($url, $pattern)==0) return 1;
		$urlExp = explode("/",$url);
		$patternExp = explode("/",$pattern);
		if(count($urlExp)!=count($patternExp)) return 0;

		for($i=0;$i<count($urlExp);$i++)
		{
			$urlTerm = $urlExp[$i];
			$patternTerm = $patternExp[$i];
			
			if($urlTerm == $patternTerm) continue;
			if($patternTerm == "STR" and is_string($urlTerm)) continue;
			if($patternTerm == "NUM" and is_numeric($urlTerm)) continue;
			if($patternTerm == "ELEMENT")
			{
				if($urlTerm=="node") continue;
				if($urlTerm=="way") continue;
				if($urlTerm=="relation") continue;
			}
			if($patternTerm == "ELEMENTS")
			{
				if($urlTerm=="nodes") continue;
				if($urlTerm=="ways") continue;
				if($urlTerm=="relations") continue;
			}

			return 0;
		}
		return 1;
	}

	function Process($url, $urlExp)
	{
		$urlButNotMethodMatched = 0;
		$urlMatchedAllowedMethod = null;

		foreach ($this->methods as $methodEntry)
		{
			$urlMatch = $this->DoesUrlMatchPattern($url, $methodEntry['url']);
			//echo $methodEntry['url'].$urlMatch."\n";
			if(!$urlMatch) continue;
			//echo $methodEntry[0];

			//Get HTTP method is correct
			$methodMatch = (strcmp(GetServerRequestMethod(),$methodEntry['method'])==0);
			//echo $methodEntry['url'].",".$methodEntry['method'].",".GetServerRequestMethod()."\n";
			if(!$methodMatch)
			{
				$urlButNotMethodMatched = 1;
				$urlMatchedAllowedMethod = $methodEntry['method'];
				continue;
			}

			//Do authentication if required
			if($this->userId == null and $methodEntry['authReq'])
				list ($this->displayName, $this->userId) = RequireAuth();
	
			try
			{
				$userInfo = array('userId'=>$this->userId, 'displayName'=>$this->displayName);
				$response = call_user_func($methodEntry['func'],$userInfo,$methodEntry['arg']);
			}
			catch (Exception $e)
			{
				header('HTTP/1.1 500 Internal Server Error');
				header("Content-Type:text/plain");
				echo "Internal server error: ".$e->getMessage()."\n";
				if(DEBUG_MODE) print_r($e->getTrace());
				return 1;
			}

			//Return normal response to client
			if(is_array($response) and $response[0] == 1)
			{
				foreach($response[1] as $headerline) header($headerline);
				echo $response[2];
				return 1;
			}
			
			//Translate error to correct http result
			if(is_array($response) and $response[0] == 0)
			{
				$changesetId=0; //TODO : where does this come from?
				TranslateErrorToHtml($response,$changesetId);
				return 1;
			}

			header('HTTP/1.1 500 Internal Server Error');
			header("Content-Type:text/plain");
			echo "Internal server error: Function needs to return array result starting with 0 or 1\n";
			return 1;
		}

		//Found URL string that matched but wrong http method specified
		if($urlButNotMethodMatched)
		{
			header('HTTP/1.1 405 Method Not Allowed');
			echo "Only method ".$urlMatchedAllowedMethod." is supported on this URI";
			return 1;
		}

	}
}


// Some stuff to translate errors to the correct HTML headers

function TranslateErrorToHtml(&$response,$changesetId)
{
	header("Content-Type:text/plain");

	if(strcmp($response[2],"already-closed")==0)
	{	
		//Example: "The changeset 5960426 was closed at 2010-10-05 11:18:26 UTC"
		header('HTTP/1.1 409 Conflict');
		$closedTime = date("c", GetChangesetClosedTime($changesetId)); //ISO 8601
		$err =  "The changeset ".(int)$changesetId." was closed at ".$closedTime;
		header('Error: '.$err);
		echo $err;
		return;
	}

	if("bbox-too-large" == $response[2])
	{
		$err="The maximum bbox size is ".MAX_QUERY_AREA;
		$err.=", and your request was too large. Either request a smaller area, or use planet.osm";
		header('HTTP/1.1 400 Bad Request');
		header('Error: '.$err);
		//header("Error: Stuff")
		echo $err;
		return;
	}

	if("invalid-bbox" == $response[2])
	{
		$err="The latitudes must be between -90 and 90, longitudes between ";
		$err.="-180 and 180 and the minima must be less than the maxima.";
		header('Error: '.$err);
		header('HTTP/1.1 400 Bad Request');
		echo $err;
		return;
	}

	if(strcmp($response[2],"session-id-mismatch")==0)
	{	
		header('HTTP/1.1 409 Conflict');
		echo "Inconsistent changeset id.";
		return;
	}

	if(strcmp($response[2],"invalid-xml")==0)
	{
		header ('HTTP/1.1 400 Bad Request');
		echo "Invalid XML input";
		return;
	}

	if(strcmp($response[2],"invalid-xml")==0)
	{	
		header ('HTTP/1.1 413 Request Entity Too Large');
		echo "Request Entity Too Large";
		return;
	}

	if(strcmp($response[2],"no-such-changeset")==0)
	{	
		header('HTTP/1.1 409 Conflict');
		echo "No such changeset.";
		return;
	}

	if(strcmp($response[2],"not-found")==0)
	{
		header ('HTTP/1.1 404 Not Found');
		echo "Object not found.";
		return;
	}

	if(strcmp($response[2],"object-not-found")==0)
	{	
		header ('HTTP/1.1 409 Conflict');
		echo "Required object not found in database (something to do with ".$response[3].",".$response[4].").";
		return;
	}

	if(strcmp($response[2],"bad-request")==0)
	{	
		header ('HTTP/1.1 400 Bad Request');
		echo "Bad request.";
		return;
	}

	if(strcmp($response[2],"gone")==0)
	{	
		header ('HTTP/1.1 410 Gone');
		#echo "The ".$response[3]." with the id ".$response[4]." has already been deleted";
		return;
	}
	
	if(strcmp($response[2],"not-implemented")==0)
	{
		header ('HTTP/1.1 501 Not Implemented');
		echo "This feature has not been implemented.";
		return;
	}

	if(strcmp($response[2],"deleting-would-break")==0)
	{
		//Example: Error: Precondition failed: Node 31567970 is still used by way 4733859.
		header('HTTP/1.1 412 Precondition failed');
		$err = "Precondition failed: Node ".$response[3]." is still used by ".$response[4]." ".$response[5].".";
		header('Error: '.$err);
		echo $err;
		return;
	}

	if(strcmp($response[2],"version-mismatch")==0)
	{	
		//Example: Version mismatch: Provided 1, server had: 2 of Node 354516541
		header ('HTTP/1.1 409 Conflict');
		$err = "Version mismatch: Provided ".$response[3].", server had: ".$response[4]." of ".ucwords($response[5])." ".$response[6];
		header ('Error: '.$err);
		echo $err;
		return;
	}
	
	//Default error
	header('HTTP/1.1 500 Internal Server Error');
	header("Content-Type:text/plain");
	echo "Internal server error: ".$response[2];
	for($i=3;$i<count($response);$i++) echo ",".$response[$i];
	echo "\n";

}

function GetUserDetails($userInfo)
{
	return CallFuncByMessage(Message::GET_USER_INFO,$userInfo);
}

function GetUserPreferences($userInfo)
{
	return CallFuncByMessage(Message::GET_USER_PERFERENCES,$userInfo);
}

function SetUserPreferences($userInfo,$data)
{
	return CallFuncByMessage(Message::SET_USER_PERFERENCES,array($userInfo,$data));
}

function SetUserPreferencesSingle($userInfo,$data)
{
	return CallFuncByMessage(Message::SET_USER_PERFERENCES_SINGLE,array($userInfo,$data));
}

function GetUserPermissions($userInfo)
{
	return CallFuncByMessage(Message::GET_USER_PERMISSIONS,$userInfo);
}

//*************************************

function GetTracesInBbox($userInfo,$get)
{
	return CallFuncByMessage(Message::GET_TRACES_IN_BBOX,array($userInfo,$get));
}

function GetTraceForUser($userInfo)
{
	return CallFuncByMessage(Message::GET_TRACE_FOR_USER,array($userInfo));
}

function InsertTraceIntoDb($userInfo, $args)
{
	return CallFuncByMessage(Message::INSERT_TRACE_INTO_DB,array($userInfo,$args));
}

function GetTraceDetails($userInfo,$urlExp)
{
	return CallFuncByMessage(Message::GET_TRACE_DETAILS,array($userInfo,$urlExp));
}

function GetTraceData($userInfo,$urlExp)
{
	return CallFuncByMessage(Message::GET_TRACE_DATA,array($userInfo,$urlExp));
}

//**************************************

function ChangesetOpen($userInfo,$putData)
{
	return CallFuncByMessage(Message::API_CHANGESET_OPEN,array($userInfo,$putData));
}

function ChangesetUpdate($userInfo, $args)
{
	return CallFuncByMessage(Message::API_CHANGESET_UPDATE,array($userInfo,$args));
}

function ChangesetClose($userInfo,$argExp)
{
	return CallFuncByMessage(Message::API_CHANGESET_CLOSE,array($userInfo,$argExp));
}

function ChangesetUpload($userInfo, $args)
{
	return CallFuncByMessage(Message::API_CHANGESET_UPLOAD,array($userInfo,$args));
}

function GetChangesetContents($userInfo, $urlExp)
{
	return CallFuncByMessage(Message::API_GET_CHANGESET_CONTENTS,array($userInfo,$urlExp));
}

function ProcessSingleObject($userInfo, $args)
{
	return CallFuncByMessage(Message::API_PROCESS_SINGLE_OBJECT,array($userInfo,$args));
}

function ChangesetExpandBbox($userInfo, $args)
{
	return CallFuncByMessage(Message::API_CHANGESET_EXPAND,array($userInfo,$args));
}

//*****************************
//URL Request Processor
//*****************************

$requestProcessor = Null;
function ApiEventHandler($eventType, $content, $listenVars)
{
	global $requestProcessor;
	if($requestProcessor===Null)
	{
		//Define allowed API calls
		$requestProcessor = new RequestProcessor($eventType, $content, $listenVars);
	}

	if($eventType === Message::API_EVENT)
	{
	$url = $content[0];
	$urlExp = $content[1];
	$putDataStr = $content[2];
	$getData = $content[3];
	$postData = $content[4];
	$filesData = $content[5];

	$requestProcessor->methods = array();
	$requestProcessor->AddMethod("/capabilities", "GET", 'GetCapabilities', 0);
	$requestProcessor->AddMethod("/0.6/capabilities", "GET", 'GetCapabilities', 0);
	$requestProcessor->AddMethod("/0.6/map", "GET", 'MapQuery', 0, $getData);
	$requestProcessor->AddMethod("/0.6/user/details", "GET", 'GetUserDetails', 1);
	$requestProcessor->AddMethod("/0.6/user/preferences", "GET", 'GetUserPreferences', 1);
	$requestProcessor->AddMethod("/0.6/user/preferences", "SET", 'SetUserPreferences', 1);
	$requestProcessor->AddMethod("/0.6/user/preferences/STR", "SET", 'SetUserPreferencesSingle', 1, 
		array($urlExp, $putDataStr));
	$requestProcessor->AddMethod("/0.6/user/permissions", "GET", 'GetUserPermissions', 1);

	$requestProcessor->AddMethod("/0.6/changesets", "GET", 'GetChangesets', 0, $getData);
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
	$requestProcessor->AddMethod("/0.6/ELEMENTS", "GET", 'MultiFetch', 0, array($urlExp,$getData));

	$requestProcessor->AddMethod("/0.6/trackpoints", "GET", 'GetTracesInBbox', 0, $getData);
	$requestProcessor->AddMethod("/0.6/user/gpx_files", "GET", 'GetTraceForUser', 1);

	$requestProcessor->AddMethod("/0.6/gpx/create", "POST", 'InsertTraceIntoDb', 1, array($filesData, $postData));
	$requestProcessor->AddMethod("/0.6/gpx/NUM/details", "GET", 'GetTraceDetails', 0, $urlExp);
	$requestProcessor->AddMethod("/0.6/gpx/NUM/data", "GET", 'GetTraceData', 0, $urlExp);


	return $requestProcessor->Process($url,$urlExp);
	}

}
?>
