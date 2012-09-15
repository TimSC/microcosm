<?php
require_once ('model-osmxml.php');
require_once ('model-filetree.php');
require_once ('model-sqlite.php');
require_once ('model-sqlite-opt.php');
require_once ('model-changesets-sqlite.php');
require_once ("model-bbox.php");


//The backend database is implemented using a strategy software pattern. This makes
//them nice and modular. The specific strategy to use is determined in this factory.

function OsmDatabase()
{
	//$db = new OsmDatabaseOsmXml();
	//$db = new OsmDatabaseByFileTree();
	//$db = new OsmDatabaseSqlite();
	//$db = new OsmDatabaseSqliteOpt();
	$db = new OsmDatabaseMultiplexer();

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

class OsmDatabaseMultiplexer extends OsmDatabaseSqliteOpt
{
	var $bboxDb;
	var $modifiedEls = array();
	var $maxBufferSize = 1000;

	function __construct()
	{
		$this->modifiedEls['node'] = array();
		$this->modifiedEls['way'] = array();
		$this->modifiedEls['relation'] = array();

		OsmDatabaseSqliteOpt::__construct();
		$this->bboxDb = FactoryBboxDatabase();
	}

	function __destruct()
	{

		//Update bboxes of all modified elements, and their parents
		$modified = $this->FindModifiedElementsIncParents();
		$this->bboxDb->Update($modified,$this);

		unset($this->bboxDb);
		OsmDatabaseSqliteOpt::__destruct();
	}

	public function CheckModifiedQueue()
	{
		$totalMod = count($this->modifiedEls['node']) +
			count($this->modifiedEls['way']) + 
			count($this->modifiedEls['relation']);
		//Check if buffer is full
		if($totalMod>$this->maxBufferSize)
		{
			//Flush buffer
			$modified = $this->FindModifiedElementsIncParents();
			$this->bboxDb->Update($modified,$this);

			//Clear buffer
			$this->modifiedEls['node'] = array();
			$this->modifiedEls['way'] = array();
			$this->modifiedEls['relation'] = array();
		}
	}

	public function CreateElement($type,$id,$el)
	{
		$this->modifiedEls[$type][$id] = $el;
		$ret = OsmDatabaseSqliteOpt::CreateElement($type,$id,$el);
		$this->CheckModifiedQueue();
		return $ret;
	}

	public function ModifyElement($type,$id,$el)
	{
		$this->modifiedEls[$type][$id] = $el;
		$ret = OsmDatabaseSqliteOpt::ModifyElement($type,$id,$el);
		$this->CheckModifiedQueue();
		return $ret;
	}

	public function DeleteElement($type,$id,$el)
	{
		$this->modifiedEls[$type][$id] = $el;
		$ret = OsmDatabaseSqliteOpt::DeleteElement($type,$id,$el);
		$this->CheckModifiedQueue();
		return $ret;
	}

	public function Purge()
	{
		$this->bboxDb->Purge();
		return OsmDatabaseSqliteOpt::Purge();
	}	

	function FindModifiedElementsIncParents()
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
	}

	function QueryXapi($type=null,$bbox=null,$key,$value=null)
	{
		//Get ids of matching elements
		$refs = $this->bboxDb->QueryXapi($type,$bbox,$key,$value);
		return $refs;
	}

	function Dump($callback)
	{
		return OsmDatabaseSqliteOpt::Dump($callback);
	}

}

?>
