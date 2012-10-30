<?php

require_once('messagepump.php');
require_once('auth.php');

class RequestProcessor
{
	var $methods = array();

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

	function Process($url, $urlExp,$displayName,$userId)
	{
		$urlButNotMethodMatched = 0;
		$urlMatchedAllowedMethod = null;

		//If we are in read only mode, require GET method
		if(API_READ_ONLY and strcmp(GetServerRequestMethod(),"GET")!=0)
		{
			CallFuncByMessage(Message::WEB_RESPONSE_TO_CLIENT, array("API in read only mode.",
				array('HTTP/1.1 503 Service Unavailable',"Content-Type:text/plain")));
			return;
		}

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
			if($userId == null and $methodEntry['authReq'])
			{	
				RequestAuthFromUser();
				return 1;
			}
	
			try
			{
				$userInfo = array('userId'=>$userId, 'displayName'=>$displayName);
				$response = call_user_func($methodEntry['func'],$userInfo,$methodEntry['arg']);
			}
			catch (Exception $e)
			{
				$body = "Internal server error: ".($e->getMessage())."\n";
				CallFuncByMessage(Message::WEB_RESPONSE_TO_CLIENT, array($body,
					array("Content-Type:text/plain",'HTTP/1.1 500 Internal Server Error')));

				if(DEBUG_MODE) print_r($e->getTrace());
				return 1;
			}

			//Return normal response to client
			if(is_array($response) and $response[0] == 1)
			{
				//foreach($response[1] as $headerline) header($headerline);
				//echo $response[2];
				CallFuncByMessage(Message::WEB_RESPONSE_TO_CLIENT, array($response[2], $response[1]));
				return 1;
			}
			
			//Translate error to correct http result
			if(is_array($response) and $response[0] == 0)
			{
				TranslateErrorToHtml($response);
				return 1;
			}

			$body = "Internal server error: Function needs to return array result starting with 0 or 1\n";
			CallFuncByMessage(Message::WEB_RESPONSE_TO_CLIENT, array($body,
				array('HTTP/1.1 500 Internal Server Error',"Content-Type:text/plain")));

			return 1;
		}

		//Found URL string that matched but wrong http method specified
		if($urlButNotMethodMatched)
		{
			$body = "Only method ".$urlMatchedAllowedMethod." is supported on this URI";
			CallFuncByMessage(Message::WEB_RESPONSE_TO_CLIENT, array($body,
				array('HTTP/1.1 405 Method Not Allowed',"Content-Type:text/plain")));
			return 1;
		}

		CallFuncByMessage(Message::WEB_RESPONSE_TO_CLIENT, array("URL not found.",
			array("Content-Type:text/plain",'HTTP/1.1 404 Not Found')));

	}
}


// Some stuff to translate errors to the correct HTML headers

function TranslateErrorToHtml(&$response)
{
	print_r($response);
	$body = Null;
	$head = array("Content-Type:text/plain");

	if(strcmp($response[2],"already-closed")==0)
	{
		//Example: "The changeset 5960426 was closed at 2010-10-05 11:18:26 UTC"
		$changesetId = (int)$response[3];
		array_push($head,'HTTP/1.1 409 Conflict');
		$closedTime = date("c", GetChangesetClosedTime($changesetId)); //ISO 8601
		$err =  "The changeset ".(int)$changesetId." was closed at ".$closedTime;
		array_push($head,'Error: '.$err);
		$body = $err;
	}

	if("bbox-too-large" == $response[2])
	{
		$err="The maximum bbox size is ".MAX_QUERY_AREA;
		$err.=", and your request was too large. Either request a smaller area, or use planet.osm";
		array_push($head,'HTTP/1.1 400 Bad Request');
		array_push($head,'Error: '.$err);
		//array_push($head,"Error: Stuff")
		$body = $err;
	}

	if("invalid-bbox" == $response[2])
	{
		$err="The latitudes must be between -90 and 90, longitudes between ";
		$err.="-180 and 180 and the minima must be less than the maxima.";
		array_push($head,'Error: '.$err);
		array_push($head,'HTTP/1.1 400 Bad Request');
		$body = $err;
	}

	if(strcmp($response[2],"session-id-mismatch")==0)
	{	
		array_push($head,'HTTP/1.1 409 Conflict');
		$body = "Inconsistent changeset id.";
	}

	if(strcmp($response[2],"invalid-xml")==0)
	{
		array_push($head,'HTTP/1.1 400 Bad Request');
		$body = "Invalid XML input";
	}

	if(strcmp($response[2],"invalid-xml")==0)
	{	
		array_push($head,'HTTP/1.1 413 Request Entity Too Large');
		$body = "Request Entity Too Large";
	}

	if(strcmp($response[2],"no-such-changeset")==0)
	{	
		array_push($head,'HTTP/1.1 409 Conflict');
		$body = "No such changeset.";
	}

	if(strcmp($response[2],"not-found")==0)
	{
		array_push($head,'HTTP/1.1 404 Not Found');
		$body = "Object not found.";
	}

	if(strcmp($response[2],"object-not-found")==0)
	{	
		array_push($head,'HTTP/1.1 409 Conflict');
		$body = "Required object not found in database (something to do with ".$response[3].",".$response[4].").";
	}

	if(strcmp($response[2],"bad-request")==0)
	{	
		array_push($head,'HTTP/1.1 400 Bad Request');
		$body = "Bad request.";
	}

	if(strcmp($response[2],"gone")==0)
	{	
		array_push($head,'HTTP/1.1 410 Gone');
		#$body = "The ".$response[3]." with the id ".$response[4]." has already been deleted"; //Why is this commented out?
		$body = "";
	}
	
	if(strcmp($response[2],"not-implemented")==0)
	{
		array_push($head,'HTTP/1.1 501 Not Implemented');
		$body = "This feature has not been implemented.";
	}

	if(strcmp($response[2],"auth-required")==0)
	{
		array_push($head,'WWW-Authenticate: Basic realm="'.SERVER_NAME.'"','HTTP/1.0 401 Unauthorized');
		$body = "Authentication Cancelled";
	}

	if(strcmp($response[2],"deleting-would-break")==0)
	{
		//Example: Error: Precondition failed: Node 31567970 is still used by way 4733859.
		array_push($head,'HTTP/1.1 412 Precondition failed');
		$err = "Precondition failed: Node ".$response[3]." is still used by ".$response[4]." ".$response[5].".";
		array_push($head,'Error: '.$err);
		$body = $err;
	}

	if(strcmp($response[2],"version-mismatch")==0)
	{	
		//Example: Version mismatch: Provided 1, server had: 2 of Node 354516541
		array_push($head,'HTTP/1.1 409 Conflict');
		$err = "Version mismatch: Provided ".$response[3].", server had: ".$response[4]." of ".ucwords($response[5])." ".$response[6];
		array_push($head,'Error: '.$err);
		$body = $err;
	}
	
	if($body === Null)
	{
		//Default error
		$body = "Internal server error: ".$response[2];
		for($i=3;$i<count($response);$i++) $body .= ",".$response[$i];
		array_merge($head,array('HTTP/1.1 500 Internal Server Error',"Content-Type:text/plain"));
	}
	CallFuncByMessage(Message::WEB_RESPONSE_TO_CLIENT, array($body,$head));
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
	//Check if this trace needs authorisation to access it
	$tid = (int)$urlExp[3];
	$isPrivate = CallFuncByMessage(Message::IS_TRACE_PRIVATE,$tid);
	if($isPrivate and $userInfo['userId'] === Null) {return array(0,null,"auth-required");}

	return CallFuncByMessage(Message::GET_TRACE_DETAILS,array($userInfo,$urlExp));
}

function GetTraceData($userInfo,$urlExp)
{
	//Check if this trace needs authorisation to access it
	$tid = (int)$urlExp[3];
	$isPrivate = CallFuncByMessage(Message::IS_TRACE_PRIVATE,$tid);
	if($isPrivate and $userInfo['userId'] === Null) return array(0,null,"auth-required");

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
  // arguements :
  //	$displayName = $userInfo['displayName'];
  //	$userId = $userInfo['userId'];
  //	$cid = (int)$args[0][3];
  //	$putDataStr = $args[1];
	return CallFuncByMessage(Message::API_CHANGESET_EXPAND,array($userInfo,$args));
}

//***************************************

$globalHeaderBuffer = array();
$globalResponseBuffer = "";

function WebResponseEventHandler($eventType, $content, $listenVars)
{
	global $globalHeaderBuffer, $globalResponseBuffer;

	if($eventType === Message::WEB_RESPONSE_TO_CLIENT)
	{
		$globalHeaderBuffer = array_merge($globalHeaderBuffer, $content[1]);
		$globalResponseBuffer .= $content[0];
	}

	if($eventType === Message::FLUSH_RESPONSE_TO_CLIENT)
	{
		foreach($globalHeaderBuffer as $headerline) header($headerline);
		echo $globalResponseBuffer;

		$fi = fopen("webresponse.txt","wt");
		foreach($globalHeaderBuffer as $headerline) 
			fwrite($fi, $headerline);
		fwrite($fi, $globalResponseBuffer);
		fclose($fi);
	}	

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
	$displayName = $content[6];
	$userId = $content[7];

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


	return $requestProcessor->Process($url,$urlExp,$displayName,$userId);
	}

}
?>
