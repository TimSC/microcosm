<?php

class Message
{
	//Map data messages
    const MAP_QUERY = 0;
	const GET_OBJECT_BY_ID = 1;
	const GET_FULL_HISTORY = 2;
	const GET_RELATIONS_FOR_ELEMENT = 3;
	const GET_WAYS_FOR_NODE = 4;
	const CHECK_ELEMENT_EXISTS = 5;
	const GET_CURRENT_ELEMENT_VER = 6;
	const GET_ELEMENT_BBOX = 7;
	const CREATE_ELEMENT = 8;
	const MODIFY_ELEMENT = 9;
	const DELETE_ELEMENT = 10;
	const DUMP = 11;
	const PURGE_MAP = 12;

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
	}

	function __destruct()
	{
		$this->Process();
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
		//echo $event->type."\n";
		if(!isset($this->listeners[$event->type])) return Null;
		$ret = Null;
		foreach($this->listeners[$event->type] as $li)
		{
			//echo $event->type." ".$liFunc."\n";
			$liFunc = $li[0];
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
			$ret = $this->ProcessSingleEvent($event);
			$event = array_shift($this->buffer);
		}
		return $ret;
	}

}

function CallFuncByMessage($messageType, $content)
{
	$queryEvent = new Message($messageType, $content);
	global $messagePump;
	$messagePump->Add($queryEvent);
	return $messagePump->Process();
}

$messagePump = new MessagePump();

?>
