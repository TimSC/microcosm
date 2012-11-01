<?php

class Message
{
	//Map data messages
	const MAP_QUERY = 0;
	const GET_OBJECT_BY_ID = 1;
	const GET_FULL_HISTORY = 2;
	const GET_RELATIONS_FOR_ELEMENT = 3;
	const GET_WAY_IDS_FOR_NODE = 4;
	const CHECK_ELEMENT_EXISTS = 5;
	const GET_CURRENT_ELEMENT_VER = 6;
	const GET_ELEMENT_BBOX = 7;
	const CREATE_ELEMENT = 8;
	const MODIFY_ELEMENT = 9;
	const DELETE_ELEMENT = 10;
	const DUMP = 11;
	const PURGE_MAP = 12;
	const ELEMENT_UPDATE_PRE_APPLY = 13;
	const ELEMENT_UPDATE_DONE = 14;
	const ELEMENT_UPDATE_PARENTS = 15;
	const ELEMENT_UPDATE_PRE_APPLY_RICH_DATA = 16;
	const GET_ELEMENT_FULL_DATA = 17; //CHILD DATA
	const GET_ELEMENT_FULL_PARENT_DATA = 18;

	//Changeset messages
	const CHANGESET_IS_OPEN = 100;
	const OPEN_CHANGESET = 101;
	const UPDATE_CHANGESET = 102;
	const CLOSE_CHANGESET = 103;
	const GET_CHANGESET_UID = 104;
	const GET_CHANGESET_METADATA = 105;
	const GET_CHANGESET_SIZE = 106;
	const EXPAND_BBOX = 107;
	const CHANGESET_APPEND_ELEMENT = 108;
	const CHANGESET_QUERY = 109;
	const GET_CHANGESET_CONTENT = 110;
	const GET_CHANGESET_CLOSE_TIME = 111;

	//Execution events
	const SCRIPT_START = 200;
	const SCRIPT_END = 201;

	//XAPI (eXtended API)
	const XAPI_QUERY = 300;

	//User Events
	const CHECK_LOGIN = 400;
	const USER_ADD = 401;
	const GET_USER_INFO = 402;
	const GET_USER_PERFERENCES = 403;
	const SET_USER_PERFERENCES = 404;
	const SET_USER_PERFERENCES_SINGLE = 405;
	const GET_USER_PERMISSIONS = 406;

	//Trace events
	const GET_TRACES_IN_BBOX = 500;
	const GET_TRACE_FOR_USER = 501;
	const INSERT_TRACE_INTO_DB = 502;
	const GET_TRACE_DETAILS = 503;
	const GET_TRACE_DATA = 504;
	const IS_TRACE_PRIVATE = 505;

	//OSM API
	const API_EVENT = 600;
	const WEB_RESPONSE_TO_CLIENT = 601;
	const FLUSH_RESPONSE_TO_CLIENT = 602;

	//Map modification functions
	const API_CHANGESET_OPEN = 700;
	const API_CHANGESET_UPDATE = 701;
	const API_CHANGESET_CLOSE = 702;
	const API_CHANGESET_UPLOAD = 703;
	const API_GET_CHANGESET_CONTENTS = 704;
	const API_PROCESS_SINGLE_OBJECT = 705;
	const API_CHANGESET_EXPAND = 706;

	//OAuth functions
	const OAUTH_LOOKUP_CONSUMER = 800;
	const OAUTH_LOOKUP_TOKEN = 801;
	const OAUTH_LOOKUP_NONCE = 802;
	const OAUTH_NEW_ACCESS_TOKEN = 803;
	const OAUTH_NEW_REQUEST_TOKEN = 804;
	const OAUTH_GET_USER_FROM_ACCESS_TOKEN = 805;
	const OAUTH_AUTH_REQUEST_TOKEN = 806;
	
	function __construct($type, $content)
	{
		$this->type = $type;
		$this->content = $content;
	}
}

class MessagePump
{
	function __construct()
	{
		$this->buffer = array();
		$this->listeners = array();

		if(DEBUG_MODE)
		{
			$this->log = fopen("messagelog.txt","at");
			if($this->log) fwrite($this->log,"-------\n");
		}
		else $this->log = False;
	}

	function __destruct()
	{
		$this->Process();
		if($this->log) fflush($this->log);
	}

	function Add($event)
	{
		array_push($this->buffer, $event);
	}

	function AddListener($eventType, $listenerFunc, $listenVars)
	{
		//echo $eventType." ".$listenerFunc."\n";
		if(!isset($this->listeners[$eventType]))
			$this->listeners[$eventType] = array();
		array_push($this->listeners[$eventType], array($listenerFunc, $listenVars));
	}

	function ProcessSingleEvent($event)
	{
		if($this->log) fwrite($this->log, $event->type."\n");
		//echo $event->type."\n";
		if(!isset($this->listeners[$event->type])) return Null;
		$ret = Null;
		foreach($this->listeners[$event->type] as $li)
		{
			$liFunc = $li[0];
			//echo $event->type." ".$liFunc."\n";
			//print_r($event->content);
			$ret = $liFunc($event->type, $event->content, $li[1]);
		}
		return $ret;
	}

	function Process()
	{
		$ret = Null;
		$event = array_shift($this->buffer);
		while($event !== Null)
		{
			$retval = $this->ProcessSingleEvent($event);
			if($retval !== null) $ret = $retval;
			$event = array_shift($this->buffer);
		}
		return $ret;
	}

	function CountListeners($eventType)
	{
		if(!isset($this->listeners[$eventType]))
			return 0;
		return count($this->listeners[$eventType]);
	}

}

function CallFuncByMessage($messageType, $content)
{
	$queryEvent = new Message($messageType, $content);
	global $messagePump;
	$messagePump->Add($queryEvent);
	return $messagePump->Process();
}


?>
