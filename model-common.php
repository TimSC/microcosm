<?php

include_once('osmtypes.php');

abstract class OsmDatabaseCommon
{

	//**********************
	//General main query
	//**********************

	public abstract function GetNodesInBbox($bbox);
	//{
		//Return node objects in array
	//	return array();
	//}

	public abstract function GetParentWaysOfNodes(&$nodes);
	//{
		//Return way objects in array
	//	return array();
	//}

	public function GetNodesToCompleteWays(&$nodes, &$ways)
	{
		//List node Ids
		$nids = array();
		foreach($nodes as $node)
		{
			array_push($nids,$node->attr['id']);
		}
		//print_r(count($nids));

		$additionalNodes = array();
		foreach($ways as $way)
		{
			//print_r($way);
			foreach($way->members as $data)
			{
				$id = $data[1];
				if(in_array($id,$nids)) continue;
				array_push($additionalNodes, $id);
			}
		}

		//print_r($additionalNodes);
		foreach($additionalNodes as $id)
		{
			$obj = $this->GetElementById("node",$id);
			if(!is_object($obj)) throw new Exception("Could not complete way, node ".$id." not found.");
			array_push($nodes,$obj);
		}
		
		return 1;
	}
	
	public abstract function GetParentRelations(&$els);
	//{
	//	//Return way objects in array
	//	return array();
	//}

	public function MapQuery($bbox)
	{
		//Get nodes
		$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$out = '<osm version="0.6" generator="'.SERVER_NAME.'">'."\n";

		//Specify bounds
		$bbox = ValidateBbox($bbox);
		$out=$out.'<bounds minlat="'.$bbox[1].'" minlon="'.$bbox[0];
		$out=$out.'" maxlat="'.$bbox[3].'" maxlon="'.$bbox[2].'"/>'."\n";

		$timers = array();
		$startTimer = microtime(1);
		$nodes = $this->GetNodesInBbox($bbox);
		$timers['nodes']=(microtime(1) - $startTimer);

		foreach($nodes as $n) if(!is_object($n)) 
			throw new Exception("Retrieved database object type ".gettype($n)." incorrect");

		$startTimer = microtime(1);
		$ways = $this->GetParentWaysOfNodes($nodes); 
		$timers['ways']=(microtime(1) - $startTimer);

		$startTimer = microtime(1);
		$this->GetNodesToCompleteWays($nodes, $ways);
		$timers['ways2']=(microtime(1) - $startTimer);

		foreach($nodes as $n) if(!is_object($n)) 
			throw new Exception("Retrieved database object type ".gettype($n)." incorrect");

		$elsQuery = array_merge($nodes, $ways);
		$startTimer = microtime(1);
		$relations = $this->GetParentRelations($elsQuery);
		$timers['relations']=(microtime(1) - $startTimer);
		//print_r($timers); die();

		foreach($nodes as $obj)
		{
			//print_r($obj); echo "\n";
			if(!is_object($obj)) 
				throw new Exception("Retrieved database object type ".gettype($obj)." incorrect");
			$out=$out.$obj->ToXmlString();
		}

		foreach($ways as $obj)
		{
			if(!is_object($obj)) 
				throw new Exception("Retrieved database object type ".gettype($obj)." incorrect");
			$out=$out.$obj->ToXmlString();
		}

		foreach($relations as $obj)
		{
			if(!is_object($obj)) 
				throw new Exception("Retrieved database object type ".gettype($obj)." incorrect");
			$out=$out.$obj->ToXmlString();
		}

		$out = $out."</osm>";
		//return array();
		return $out;
	}

	//**********************************
	//Get specific info from database
	//**********************************
	public function GetCurentVerOfElement($type,$id)
	{
		$obj = $this->GetElementById($type,$id);
		if(!is_object($obj)) return $obj; //Not found or gone
		if(!isset($obj->attr['version'])) 
			throw new Exception("Internal database has missing version attribute.");
		return $obj->attr['version'];
	}

	public abstract function GetElementById($type,$id,$version=null);

	public function GetElementAsXmlString($type,$id)
	{
		$obj = $this->GetElementById($type,$id);
		if(!is_object($obj)) return $obj;
		return $obj->ToXmlString();
	}

	public function CheckElementExists($type,$id)
	{
		$obj = $this->GetElementById($type,$id);
		return is_object($obj);
	}

	public function GetCitingWaysOfNode($id)
	{
		$obj = new OsmNode();
		$obj->attr['id'] = $id;
		$objarr = array($obj);
		$ret = $this->GetParentWaysOfNodes($objarr);
		$out = array();
		foreach($ret as $obj) array_push($out,$obj->attr['id']);
		return $out;
	}

	public function GetCitingRelations($type,$id)
	{
		$obj = OsmElementFactory($type);
		$obj->attr['id'] = $id;
		$objarr = array($obj);
		$ret = $this->GetParentRelations($objarr);
		$out = array();
		foreach($ret as $obj) array_push($out,$obj->attr['id']);
		return $out;
	}

	public function GetBboxOfElement($type,$id,$depth = 0)
	{
		//Get the bounding box of an element
		//Return format: min_lon,min_lat,max_lon,max_lat
		$bbox= null;

		//Prevent infinite recursion
		$maxDepth = 10;
		if($depth>$maxDepth) return null;

		//Get parent element
		$el = $this->GetElementById($type,$id);
		if(!is_object($el)) return null;

		//Use own position attribute
		if(isset($el->attr['lon']) and isset($el->attr['lat']))
			UpdateBbox($bbox,
				array($el->attr['lon'],$el->attr['lat'],
				$el->attr['lon'],$el->attr['lat']));

		//Recursively get member elements
		foreach($el->members as $member)
			UpdateBbox($bbox,$this->GetBboxOfElement($member[0],$member[1],$depth+1));

		return $bbox;
	}

	function GetFullDetailsOfElement($firstObj,$depth = 0,$maxDepth=5)
	{
		if(!is_object($firstObj)) throw new Exception("Parent object must be defined");
		//echo $type.$id."\n";
		//if($depth > $maxDepth) return Null;
		//$firstObj = $this->GetElementById($type,(int)$id, Null);

		//print_r($firstObj);
		if($firstObj===null or $firstObj===0) return "not-found";
		if($firstObj===-2) return "gone";

		//Get members recursively
		$out = array($firstObj);
		foreach($firstObj->members as $data)
		{
			$memtype = $data[0];
			$memid = $data[1];
			$memObj = $this->GetElementById($memtype,(int)$memid, Null);
			if(!is_object($memObj)) throw new Exception("Return type is not object, as expected");

			$obj = $this->GetFullDetailsOfElement($memObj,$depth,$maxDepth);
			if(!is_array($obj)) throw new Exception("Return type is not array, as expected");
			$out = array_merge($out,$obj);

			if(count($out) > 10000) throw new Exception("Buffer too large. Halting to protect data.");
		}
		return $out;
	}

	public abstract function CheckPermissions();

	//***********************
	//Modification functions
	//***********************

	public abstract function CreateElement($type,$id,$el);

	public abstract function ModifyElement($type,$id,$el);

	public abstract function DeleteElement($type,$id,$el);

	public abstract function Purge();
	
}



?>
