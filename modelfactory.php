<?php
require_once ('model-osmxml.php');
require_once ('model-filetree.php');
require_once ('model-sqlite.php');
require_once ('model-mysql.php');
require_once ('model-sqlite-opt.php');
require_once ('model-changesets-sqlite.php');
require_once ("model-bbox.php");
require_once ("messagepump.php");
require_once ("config.php");

$dbGlobal = Null;
$changesetGlobal = Null;

//The backend database is implemented using a strategy software pattern. This makes
//them nice and modular. The specific strategy to use is determined in this factory.

function OsmDatabase()
{
	//$db = new OsmDatabaseOsmXml();
	//$db = new OsmDatabaseByFileTree();
	//$db = new OsmDatabaseSqlite();
	//$db = new OsmDatabaseSqliteOpt();
	$db = new OsmDatabaseMultiplexer();
	//if(BACKEND_DATABASE == "mysql")
	//	$db = new OsmDatabaseMysql();
	//if(BACKEND_DATABASE == "sqlite")
	//	$db = new OsmDatabaseSqliteOpt();

	$checkPermissions = $db->CheckPermissions();
	if($checkPermissions != 1)
	{
		header('HTTP/1.1 500 Internal Server Error');
		echo $checkPermissions.' is not writable';
		exit();
	}

	return $db;
}

function ChangesetDatabase()
{
	//return new ChangesetDatabaseOsmXml();
	return new ChangesetDatabaseSqlite();
}

class OsmDatabaseMultiplexer extends OsmDatabaseCommon
{

	function __construct()
	{
		$this->masterDb = new OsmDatabaseMysql();
		$this->events = fopen("events.txt","wt");
	}

	function __destruct()
	{
		unset($this->masterDb);
		fclose($this->events);
	}

	function GetElementById($type,$id,$version=null)
	{
		return $this->masterDb->GetElementById($type, $id, $version);
	}

	public function CreateElement($type,$id,$el)
	{
		fwrite($this->events, "Create ".$type." ".$id."\n");
		return $this->masterDb->CreateElement($type,$id,$el);
	}

	public function ModifyElement($type,$id,$el)
	{
		fwrite($this->events, "Modify ".$type." ".$id."\n");
		return $this->masterDb->ModifyElement($type,$id,$el);
	}

	public function DeleteElement($type,$id,$el)
	{
		fwrite($this->events, "Delete ".$type." ".$id."\n");
		return $this->masterDb->DeleteElement($type,$id,$el);
	}

	public function Purge()
	{
		fwrite($this->events, "Purge\n");
		return $this->masterDb->Purge();
	}

	/*function FindModifiedElementsIncParents()
	{
		$out = array('node'=>array(),'way'=>array(),'relation'=>array());

		//Add items in changset
		foreach($this->modifiedEls as $elgroup)
		foreach($elgroup as $el)
		{
			$type = $el->GetType();
			$id = $el->attr['id'];
			$out[$type][$id] = $el;
		}

		//Get parent ways
		$parents = $this->GetParentWaysOfNodes($out['node']);
		foreach($parents as $el)
		{
			$type = $el->GetType();
			$id = $el->attr['id'];
			$out[$type][$id] = $el;		
		}

		//Get parent relations
		//TODO should relations be done recursively?
		$parents = $this->GetParentRelations($out['node']);
		foreach($parents as $el) {$type = $el->GetType();$id = $el->attr['id'];$out[$type][$id] = $el;}	
		$parents = $this->GetParentRelations($out['way']);
		foreach($parents as $el) {$type = $el->GetType();$id = $el->attr['id'];$out[$type][$id] = $el;}	
		$parents = $this->GetParentRelations($out['relation']);
		foreach($parents as $el) {$type = $el->GetType();$id = $el->attr['id'];$out[$type][$id] = $el;}
	
		//print_r($out);
		return $out;
	}*/

	function QueryXapi($type=null,$bbox=null,$key,$value=null)
	{
		//Get ids of matching elements
		//$refs = $this->bboxDb->QueryXapi($type,$bbox,$key,$value);
		//return $refs;
	}

	function Dump($callback)
	{
		//return OsmDatabaseMysql::Dump($callback);
		return $this->masterDb->Dump($callback);
	}

	function CheckPermissions()
	{
		return $this->masterDb->CheckPermissions();
	}

	function GetNodesInBbox($bbox)
	{
		return $this->masterDb->GetNodesInBbox($bbox);
	}

	function GetParentWaysOfNodes(&$nodes)
	{
		return $this->masterDb->GetParentWaysOfNodes($nodes);
	}

	function GetParentRelations(&$els)
	{
		return $this->masterDb->GetParentRelations($els);
	}

}



function MapDatabaseEventHandler($eventType, $content, $listenVars)
{
	global $dbGlobal;
	if($dbGlobal === Null)
		$dbGlobal = OsmDatabase();

	if($eventType === Message::MAP_QUERY)
		return $dbGlobal->MapQuery($content);

	if($eventType === Message::GET_OBJECT_BY_ID)
		return $dbGlobal->GetElementById($content[0],$content[1],$content[2]);

	if($eventType === Message::GET_FULL_HISTORY)
		return $dbGlobal->GetElementFullHistory($content[0], $content[1]);

	if($eventType === Message::GET_RELATIONS_FOR_ELEMENT)
		return $dbGlobal->GetCitingRelations($content[0], $content[1]);

	if($eventType === Message::GET_WAYS_FOR_NODE)
		return $dbGlobal->GetCitingWaysOfNode($content);

	if($eventType === Message::CHECK_ELEMENT_EXISTS)
		return $dbGlobal->CheckElementExists($content[0], $content[1]);

	if($eventType === Message::GET_CURRENT_ELEMENT_VER)
		return $dbGlobal->GetCurentVerOfElement($content[0], $content[1]);

	if($eventType === Message::GET_ELEMENT_BBOX)
		return $dbGlobal->GetBboxOfElement($content[0], $content[1]);

	if($eventType === Message::CREATE_ELEMENT)
		return $dbGlobal->CreateElement($content[0], $content[1], $content[2]);

	if($eventType === Message::MODIFY_ELEMENT)
		return $dbGlobal->ModifyElement($content[0], $content[1], $content[2]);

	if($eventType === Message::DELETE_ELEMENT)
		return $dbGlobal->DeleteElement($content[0], $content[1], $content[2]);

	if($eventType === Message::DUMP)
	{
		return $dbGlobal->Dump($content);
	}
}

function ChangesetDatabaseEventHandler($eventType, $content, $listenVars)
{

	global $changesetGlobal;
	if($changesetGlobal === Null)
		$changesetGlobal = ChangesetDatabase();

	if($eventType === Message::CHANGESET_IS_OPEN)
		return $changesetGlobal->IsOpen($content);

	if($eventType === Message::OPEN_CHANGESET)
		return $changesetGlobal->Open($content[0], $content[1], $content[2], $content[3], $content[4]);

	if($eventType === Message::UPDATE_CHANGESET)
		return $changesetGlobal->Open($content[0], $content[1], $content[2], $content[3]);

	if($eventType === Message::CLOSE_CHANGESET)
		return $changesetGlobal->Open($content);

	if($eventType === Message::GET_CHANGESET_UID)
		return $changesetGlobal->GetUid($content);

	if($eventType === Message::GET_CHANGESET_METADATA)
		return $changesetGlobal->GetMetadata($content);

}

$messagePump->AddListener(Message::MAP_QUERY, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_OBJECT_BY_ID, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_FULL_HISTORY, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_RELATIONS_FOR_ELEMENT, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_WAYS_FOR_NODE, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::CHECK_ELEMENT_EXISTS, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_CURRENT_ELEMENT_VER, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_ELEMENT_BBOX, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::CREATE_ELEMENT, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::MODIFY_ELEMENT, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::DELETE_ELEMENT, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::DUMP, "MapDatabaseEventHandler", Null);

$messagePump->AddListener(Message::CHANGESET_IS_OPEN, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::OPEN_CHANGESET, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::UPDATE_CHANGESET, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::CLOSE_CHANGESET, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_CHANGESET_UID, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_CHANGESET_METADATA, "ChangesetDatabaseEventHandler", Null);


?>
