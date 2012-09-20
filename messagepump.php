<?php

class Message
{
    const MAP_QUERY = 0;

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
		if(!isset($this->listeners[$eventType]))
			$this->listeners[$eventType] = array();
		array_push($this->listeners[$eventType], array($listenerFunc, $listenVars));
	}

	function ProcessSingleEvent($event)
	{
		if(!isset($this->listeners[$event->type])) return Null;
		$ret = Null;
		foreach($this->listeners[$event->type] as $li)
		{
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

$messagePump = new MessagePump();

?>
