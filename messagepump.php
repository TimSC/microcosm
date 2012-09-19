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
		array_unshift($this->buffer, $event);
	}

	function AddListener($eventType, $listenerFunc)
	{
		
	}

	function ProcessSingleEvent($event)
	{
		if(!isset($this->listeners[$event->type])) return Null;
	}

	function Process()
	{
		$event = array_shift($this->buffer);
		while($event !== Null)
		{
			$this->ProcessSingleEvent($event);			

			$event = array_shift($this->buffer);
		}
	}

	function AddAndProcess($event)
	{
		$this->Process();
		return $this->ProcessSingleEvent($event);
	}
}

$messagePump = new MessagePump();

?>
