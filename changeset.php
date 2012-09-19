<?php

require_once("config.php");
require_once("querymap.php");
require_once("osmtypes.php");
require_once("modelfactory.php");
require_once("messagepump.php");

function GetNewObjectId($type)
{
	if(strcmp($type,"node")==0)
		return ReadAndIncrementFileNum("nextnodeid.txt");
	if(strcmp($type,"way")==0)
		return ReadAndIncrementFileNum("nextwayid.txt");
	if(strcmp($type,"relation")==0)
		return ReadAndIncrementFileNum("nextrelationid.txt");
	return null;
}

function ChangesetOpen($userInfo,$putData)
{
	$displayName = $userInfo['displayName'];
	$userId = $userInfo['userId'];

	$lock=GetWriteDatabaseLock();
	$csd = ChangesetDatabase();

	$data = ParseOsmXmlChangeset($putData);
	if(is_null($data)) return array(0,Null,"bad-input");

	$cid = ReadAndIncrementFileNum("nextchangesetid.txt");
	$data->attr['id'] = $cid;

	if($csd->IsOpen($cid)!=-1)
		return array(0,Null,"changeset-already-exists");

	return array(1,array("Content-Type:text/plain"),$csd->Open($cid,$data,$displayName,$userId,time()));
}

function ChangesetUpdate($userInfo, $args)
{
	$displayName = $userInfo['displayName'];
	$userId = $userInfo['userId'];
	list($urlExp, $putData) = $args;
	$cid = (int)$urlExp[3];

	$lock=GetWriteDatabaseLock();
	$csd = ChangesetDatabase();

	$data = ParseOsmXmlChangeset($putData);
	if(is_null($data)) return array(0,Null,"bad-input");

	//Check user is correct
	$xmlcid = $data->attr['id'];
	$cUser = $csd->GetUid($cid);
	if(is_null($cUser)) return array(0,Null,"not-found");
	if($userId != $cUser) return array(0,Null,"user-mismatch",$userId,$cUser);
	if($cid != $xmlcid) return array(0,Null,"conflict");

	if($csd->IsOpen($cid)!=1)
		return array(0,Null,"conflict");

	$ret = $csd->Update($cid,$data,$displayName,$userId);
	return GetChangesetMetadataLowLevel($csd,$cid);
}

function ChangesetClose($userInfo,$argExp)
{
	$displayName = $userInfo['displayName'];
	$userId = $userInfo['userId'];
	$cid = (int)$argExp[3];

	$lock=GetWriteDatabaseLock();
	$csd = ChangesetDatabase();

	//Validate user has rights
	$cUser = $csd->GetUid($cid);
	if(is_null($cUser)) return array(0,Null,"not-found");
	if($userId != $cUser) return array(0,Null,"conflict");
	
	//Do close	
	$csd->Close($cid);
	return array(1,array("Content-Type:text/plain"),"");
}

//**************************************
//Process User Uploaded Map Data
//**************************************

//<osmChange version='0.6' generator='JOSM'><delete><way id='171' action='delete' timestamp='2010-10-14T08:41:57Z' uid='6809' user='TimSC' visible='true' version='1' changeset='209'/>  <node id='681' action='delete' timestamp='2010-10-14T08:41:57Z' uid='6809' user='TimSC' visible='true' version='1' changeset='209' lat='51.26718186473299' lon='-0.5552857742301459'/></delete></osmChange>

function CheckChildrenExist(&$children, &$createdEls,$map)
{
	foreach($children as $child)
	{
		$childType = $child[0];
		$id = $child[1];
		if($id>0)
		{
			//Check the element exists (in a non deleted state)
			$exists = $map->CheckElementExists($childType,$id);
			if($exists!=true) return 0;
		}
		if($id<0 and !isset($createdEls[$childType.$id]))
		{
			echo $childType.$id;
			return 0;
		}
	}
	return 1;
}

function CheckElementInList(&$li, $type, $id)
{
	return isset($li[$type.$id]);
}

function CheckCitationsIfDeleted($type, $el, $map, &$deletedEls, &$recentlyChanged)
{
	$id = $el->attr['id'];
	$type = $el->GetType();

	//Check ways would be ok
	if(strcmp($type,"node")==0)
	{

		$citingWays = $map->GetCitingWaysOfNode($id);
		//All citing ways must already be on the "to delete list"
		foreach($citingWays as $wayId)
		{
			//If this object has appeared in this changeset, skip here
			//and consider it separately
			if(CheckElementInList($recentlyChanged,"way",$wayId)) continue;

			//Ignore if this object have already been deleted
			if(CheckElementInList($deletedEls,"way",$wayId)) continue;

			//Found a way that would be broken if this node is deleted
			return "deleting-would-break,".$id.",way,".$wayId;
		}

		//Check if it is used by a recently changed element
		foreach($recentlyChanged as $el)
		{
			if(strcmp($el->GetType(),"way")!=0) continue;			

			$match = 0;
			//Check if this node is used
			foreach($el->members as $n)
			{
				if($n[0] == "node" and (int)$n[1]==(int)$id) {$match = $n[1];break;}
			}

			//Conflict found
			if($match!=0) return "deleting-would-break,".$id.",way,".$match;
		}
	}

	//Check relations would be ok
	$citingRels = $map->GetCitingRelations($type,$id);
	//All citing relations must already be on the "to delete list"
	foreach($citingRels as $relId)
	{
		//If this object has appeared in this changeset, skip here
		//and consider it separately
		if(CheckElementInList($recentlyChanged,"relation",$relId)) continue;

		//Ignore if this object have already been deleted
		if(CheckElementInList($deletedEls,"relation",$relId)) continue;

		//Found a relation that would be broken if this node is deleted
		return "deleting-would-break,".$id.",way,".$relId;
	}

	//Check if it is used by a recently changed element
	foreach($recentlyChanged as $el)
	{
		if(strcmp($el->GetType(),"relation")!=0) continue;			

		$match = 0;
		//Check if this element is used
		foreach($el->members as $n)
		{
			if($n[0] == $type and (int)$n[1]==(int)$id) {$match = $n[1];break;}
		}


		//Conflict found
		if($match!=0) return "deleting-would-break,".$id.",relation,".$match;
	}


	return 1;
}

$requiredAttributes = array('id','changeset');

function ValidateOsmChange($osmchange,$cid,$displayName,$userId,$map)
{
	$createdEls = array();
	$deletedEls = array();
	$recentlyChanged = array();
	$csd = ChangesetDatabase();

	//Check API version
	$ver = $osmchange->version;
	//if(strcmp($ver,"0.6")!=0) throw new Exception("OsmChange has wrong version"); //Merkaartor doesn't like that!

	//For each action,
	foreach($osmchange->data as $i => $data)
	{
		$action = $data[0];
		$els = $data[1];

		//Has at least one action?

		//Actions have at least one element?

		//Actions can only be create, modify, delete
		if(!(strcmp($action,"create")==0 or strcmp($action,"modify")==0
			or strcmp($action,"delete")==0))
			throw new Exception("Action ".$action." not supported");

		foreach($els as $i2 => $el)
		{
		$type = $el->GetType();
		$id = $el->attr['id'];
		$ver = null;
		if(isset($el->attr['version']))	$ver = $el->attr['version'];

		//Check the mandatory attributes are set
		global $requiredAttributes;
		foreach($requiredAttributes as $at)
		{
			if(!isset($el->attr[$at])) throw new Exception("Require attribute ".$at." not set");
		}

		//Check node has lat lon
		if(strcmp($type,"node")==0)
		{
			if(!isset($el->attr['lat']) or !isset($el->attr['lon']))
				throw new Exception("Require attribute not set");
		}

		//Action is create or version number is set
		if(strcmp($action,"create")!=0 and !isset($el->attr['version']))
		{
			throw new Exception("Version number of element must be specified");
		}

		//Check if changeset is open
		$cid = $el->attr['changeset'];
		if(!$csd->IsOpen($cid)) throw new Exception("Changeset is not open");

		//Check if user has permission to add to this changeset
		$ciduser = $csd->GetUid($cid);
		if($ciduser != $userId) 
			throw new Exception("Changeset belongs to a different user ".$ciduser.", not ".$userId);

		//Check we don't exceed number of max elements
		$cidsize = $csd->GetSize($cid);
		if(is_null($cidsize)) throw new Exception("Changeset size could not be determined ".$cid);
		if($cidsize + 1 > MAX_CHANGESET_SIZE)
			throw new Exception("Max changeset size exceeded");

		//Check if method is consistent

		//TODO work out what action really does?? JOSM specific?
		if(isset($el->attr['action']))
		{
		$objaction = $el->attr['action'];
		//if(strcmp($action,$objaction)!=0)
		//	throw new Exception("Action specified in object not consistent ".$action." vs. ".$objaction);
		}

		//Visibile attribute seems to be a "rails port" specific tag
		/*$visible = $el->attr['visible'];
		if(!(strcmp($visible,"true")==0 or strcmp($visible,"false")==0))
			throw new Exception("Visible attribute must be true or false");
		if(strcmp($action,"create")==0 or strcmp($action,"modify")==0)
			if(strcmp($visible,"false")==0) throw new Exception("Visiblity must be true for create or modify");
		*/
		//if(strcmp($action,"delete")==0)
		//	if(strcmp($visible,"true")==0) throw new Exception("Visiblity must be false for delete");

		//Object id zero not allowed?
		if($id == 0) throw new Exception("Object ID zero not allowed");

		//Object versions
		if($id >= 0)
		{
			$currentVer = $map->GetCurentVerOfElement($type,$id);
			if($currentVer===-1)
				return array(0,null,"not-found",$type,$id);
			if($currentVer===-2)
				return array(0,null,"gone",$type,$id);
			if($ver != $currentVer and VERSION_VALIDATION)
			{
				return array(0,null,"version-mismatch",(int)$ver,$currentVer,$type,$id);
			}
		}

		//Store created objects
		if(strcmp($action,"create")==0)
		{
			$createdEls[$type.$id] = 1;
			$recentlyChanged[$type.$id] = $el;

			//Created objects must have a negative ID
			if($id >= 0) throw new Exception("Created objects must have negative ID");
		}

		if(strcmp($action,"modify")==0)
			$recentlyChanged[$type.$id] = $el;

		//Store deleted objects
		if(strcmp($action,"delete")==0)
			$deletedEls[$type.$id] = 1;


		//Check if referenced elements actual exist
		$ret = CheckChildrenExist($el->members, $createdEls, $map);
		if($ret==0) return array(0,null,"object-not-found",$type,$id);
	
		//Check if deleting stuff will break ways
		if(strcmp($action,"delete")==0)
		{
		$ret = CheckCitationsIfDeleted($type, $el, $map, $deletedEls, $recentlyChanged);
		if($ret!=1) return $ret;
		}

		//Check if deleting stuff will break relations

		//Enforce max nodes in way and relations, etc
		if (count($el->members)>MAX_WAY_NODES)
			return array(0,null,"too-large");
		}
	}	

	return 1;
}

function AssignIdsToOsmChange(&$osmchange,$displayName,$userId)
{
	$idmapping = array();
	$changes = array();

	foreach($osmchange->data as $i => &$eldata)
	{
		$action = $eldata[0];
		$els = &$eldata[1];

		//Set timestamp of object
		foreach($els as $i2 => $el)
		{
			$el->attr['timestamp'] = date('c'); //Set timestamp at creation

			//Visibility is a database model specific concept and doesn't belong here
			//if(strcmp($action,"delete")==0) $el->attr['visible'] ="false";
			//else $el->attr['visible'] ="true";
		}

		//Set username and UID for any changed elements
		foreach($els as $i2 => $el)
		{
			$el->attr['user']=(string)$displayName;
			$el->attr['uid']=(int)$userId;
			unset($el->attr['action']);
		}

		//Process create actions
		if(strcmp($action,"create")==0)
		{
			foreach($els as $i2 => $el)
			{
				$type = $el->GetType();

				//Initialise version
				$el->attr['version'] = 1;
				$newver = $el->attr['version'];
				$el->attr['visibility'] = "true";

				//Initilise element ID
				$oldid = (int)$el->attr['id'];
				if($oldid >= 0) throw new Exception("Created element has non-negative ID ".$oldid);
				$newid = GetNewObjectId($type);
				$el->attr['id'] = $newid;
				$idmapping[$type.",".$oldid] = array($newid,$el->attr['version']);

				//Store changes to return to editor
				array_push($changes,array($type,$oldid,$newid,$newver,$newver));
			}
		}

		//Process modify actions
		if(strcmp($action,"modify")==0)
		{
			foreach($els as $i2 => $el)
			{
				$type = $el->GetType();
				$oldid = (int)$el->attr['id'];
				$newid = $oldid;

				//Increment version
				$oldver = $el->attr['version'];
				$el->attr['version'] = $el->attr['version'] + 1;
				$newver = $el->attr['version'];
				$el->attr['visibility'] = "true";

				//Store changes to return to editor
				array_push($changes,array($type,$oldid,$newid,$newver,$newver));
			}
		}

		//Process delete actions
		if(strcmp($action,"delete")==0)
		{
			foreach($els as $i2 => $el)
			{
				$type = $el->GetType();
				$oldid = (int)$el->attr['id'];

				//Increment version
				$oldver = $el->attr['version'];
				$el->attr['version'] = $el->attr['version'] + 1;
				$newver = $el->attr['version'];
				$el->attr['visibility'] = "false";

				//Store changes to return to editor
				array_push($changes,array($type,$oldid,null,null,$newver));
			}
		}

		//Renumber members, for each element
		foreach($els as $i2 => $el)
		{
			//Renumber child members
			foreach($el->members as $i3 => $nd)
			{		
				$oldid = $nd[1];
				if($oldid >= 0) continue;
				$type = $nd[0];
				if(!isset($idmapping[$type.",".$oldid]))
					throw new Exception("ID not found in mapping ".$type.",".$oldid);
				$newid = $idmapping[$type.",".$oldid][0];
				$el->members[$i3][1] = $newid;
			}


		}

	}

	return $changes;
}

function GetBboxOfReferencedElements($osmchange,&$map)
{
	//For each element
	$bbox= null;
	foreach($osmchange->data as $data)
	{
		list($method, $els) = $data;
		//if(strcmp($method,"create")==0)
		foreach($els as $el)
		{
			//echo $method." ".$el->GetType()." ".$el->attr['id']."\n";
			$elBbox = $map->GetBboxOfElement($el->GetType(),(int)$el->attr['id']);
			//print_r($elBbox);
			UpdateBbox($bbox, $elBbox);
		}
	}
	return $bbox;
}

function ExpandChangesetBbox($cid,$bbox)
{
	if(!is_array($bbox)) return 0;
	$csd = ChangesetDatabase();
	//print_r($bbox);
	$csd->ExpandBbox($cid,$bbox);
	return 1;
}

function ApplyChangeToDatabase(&$osmchange,&$map)
{
	$csd = ChangesetDatabase();

	//For each element
	foreach($osmchange->data as $data)
	{
		list($method, $els) = $data;
		if(strcmp($method,"create")==0)
		foreach($els as $el)
		{
			$type = $el->GetType();
			$el->attr['visible'] = "true";

			//Create in main db
			$map->CreateElement($type,$el->attr['id'],$el);

			//Also store in changeset
			$csd->AppendElement($el->attr['changeset'], $method, $el);
		}

		if(strcmp($method,"modify")==0)
		foreach($els as $el)
		{
			$type = $el->GetType();
			$el->attr['visible'] = "true";

			//Modify element in main db
			$map->ModifyElement($type,$el->attr['id'],$el);

			//Also store in changeset
			$csd->AppendElement($el->attr['changeset'], $method, $el);
		}

		if(strcmp($method,"delete")==0)
		foreach($els as $el)
		{
			$type = $el->GetType();
			$value = simplexml_load_string($el->ToXmlString());
			$el->attr['visible'] = "false";

			//Delete object in main db
			$map->DeleteElement($type,$el->attr['id'],$el);

			//Also store in changeset
			$csd->AppendElement($el->attr['changeset'], $method, $el);
		}

	}
}

function ProcessOsmChange($cid,$osmchange,$displayName,$userId)
{
	//Lock the database for writing
	$lock=GetWriteDatabaseLock();

	//Load database
	$map = OsmDatabase();

	//Validate change
	try
	{
	$valret = ValidateOsmChange($osmchange,$cid,$displayName,$userId,$map);
	if($valret != 1)
		return $valret;
	}
	catch (Exception $e)
	{
		return array(0,null,"bad-request",$e);
	}

	//Assign IDs to created objects
	$changes = AssignIdsToOsmChange($osmchange,$displayName,$userId);

	//Bbox for elements before change is applied
	//TODO relations should be handled differently
	$bbox = GetBboxOfReferencedElements($osmchange,$map);
	ExpandChangesetBbox($cid,$bbox);

	//Apply changes to database
	ApplyChangeToDatabase($osmchange,$map);

	//Bbox for elements after change is applied
	$bbox = GetBboxOfReferencedElements($osmchange,$map);
	ExpandChangesetBbox($cid,$bbox);

	//Cause the destructor of the map and bbox databases object to run
	unset($bboxdb);
	unset($map); 

	return array(1,$changes);
}

function ProcessSingleObject($userInfo, $args)
{
	list($urlExp,$data,$method) = $args;
	$displayName = $userInfo['displayName'];
	$userId = $userInfo['userId'];

	//$cid = 204;
	//$data = "<osm version='0.6' generator='JOSM'>  <node id='-7801' visible='true' changeset='220' lat='51.26493956255727' lon='-0.5636603245748466' /></osm>";

	//Construct an OsmChange from the single object
	$osmchange = new OsmChange();
	$osmchange->version = 0.6;
	$singleObj = SingleObjectFromXml($data);
	array_push($osmchange->data,array($method,array($singleObj)));
	$cid = null;
	
	//Process OsmChange
	$ret = ProcessOsmChange($cid,$osmchange,$displayName,$userId);
	if($ret[0]===0) return $ret;
	$changes = $ret[1];

	//Return version or new ID as appropriate
	$newobjid = $changes[0][2];
	$newversion = $changes[0][4];
	if(strcmp($method,"create")==0)
		return array(1,array("Content-Type:text/plain"),$newobjid);
	return array(1,array("Content-Type:text/xml"),$newversion);
}

function ChangesetUpload($userInfo, $args)
{
	$displayName = $userInfo['displayName'];
	$userId = $userInfo['userId'];
	$cid = (int)$args[0][3];
	$putDataStr = $args[1];
//	$cid=204;

//	$data="<osmChange version=\"0.6\" generator=\"PythonTest\">\n<modify>\n<way id='595' action='modify' timestamp='2009-03-01T14:21:58Z' uid='6809' user='TimSC' visible='true' version='1' changeset='204' lat='51.285184946434256' lon='-0.5986675534296598'><tag k='name' v='Belvidere'/><nd ref='555'/></way>\n</modify>\n</osmChange>\n";

	//$data="<osmChange version='0.6' generator='JOSM'><delete><way id='171' action='delete' timestamp='2010-10-14T08:41:57Z' uid='6809' user='TimSC' visible='true' version='1' changeset='209'/>  <node id='681' action='delete' timestamp='2010-10-14T08:41:57Z' uid='6809' user='TimSC' visible='true' version='1' changeset='209' lat='51.26718186473299' lon='-0.5552857742301459'/></delete></osmChange>";

	//Convert XML upload to native data
	$osmchange = new OsmChange();
	$osmchange->FromXmlString($putDataStr);

	//Process OsmChange
	$ret = ProcessOsmChange($cid,$osmchange,$displayName,$userId);
	if($ret[0]===0) return $ret;
	$changes = $ret[1];
	//echo gettype($changes);

	if(is_array($changes))
	{
	//Return difference info	
	$diff = '<diffResult generator="'.SERVER_NAME.'" version="0.6">'."\n";
	foreach($changes as $ch)
	{
		list($type,$oldid,$newid,$newvertoeditor,$newver) = $ch;
		$diff = $diff.' <'.$type.' old_id="'.$oldid.'"';
		if(!is_null($newid)) $diff = $diff.' new_id="'.$newid.'"';
		if(!is_null($newvertoeditor)) $diff = $diff.' new_version="'.$newvertoeditor.'"';
		$diff = $diff."/>\n";
	}
	$diff = $diff."</diffResult>\n";

	return array(1,array("Content-Type:text/xml"),$diff);
	}

	return array(0,Null,$changes);
}

function ChangesetExpandBbox($userInfo, $args)
{
	$displayName = $userInfo['displayName'];
	$userId = $userInfo['userId'];
	$cid = (int)$args[0][3];
	$putDataStr = $args[1];

	//Open database	
	$lock = GetWriteDatabaseLock();
	$csd = ChangesetDatabase();

	//Validate
	if($userId != $csd->GetUid($cid))
		return array(0,null,"forbidden");
	$xml = ParseOsmXml($putDataStr);	

	//Expand Bbox
	$bbox = null;
	foreach($xml as $el)
	{
		if($el->GetType() != "node") continue; //Ignore everything but nodes
		UpdateBbox($bbox,array($el->attr['lon'],$el->attr['lat'],
				$el->attr['lon'],$el->attr['lat']));
	}

	if(is_array($bbox)) $csd->ExpandBbox($cid,$bbox);

	//Return changeset
	return GetChangesetMetadataLowLevel($csd,$cid);
}

function GetChangesets($userInfo,$query)
{

	$lock=GetReadDatabaseLock();
	$csd = ChangesetDatabase();

	$user = null;
	if(isset($query['user'])) $user = (int)$query['user'];
	$open = null;
	if(isset($query['open'])) $open = (strcmp($query['open'],"true")==0);
	$timerange = null; //TODO implement this and other arguments

	$csids = $csd->Query($user,$open,$timerange);
	//print_r($csids);
	
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$out = $out.'<osm version="0.6" generator="'.SERVER_NAME.'">'."\n";

	foreach($csids as $cid)
	{ 
		if(isset($query['user']) and $query['user'] != $csd->GetUid($cid)) continue;
		#$out = $out.ChangesetToXml($cid);
		$out = $out.$csd->GetMetadata($cid)->ToXmlString();
	}
	
	$out = $out.'</osm>'."\n";
	return array(1,array("Content-Type:text/xml"),$out);
}

function GetChangesetMetadataLowLevel(&$csd,$cid)
{
	$data = $csd->GetMetadata($cid);
	if(is_null($data)) return array(0,Null,"not-found");
	return array(1,array("Content-Type:text/xml"),
		'<osm version="0.6" generator="'.SERVER_NAME.'">'.$data->ToXmlString().'</osm>');
}

function GetChangesetMetadata($userInfo, $urlExp)
{
	$cid = (int)$urlExp[3];

	$lock=GetReadDatabaseLock();
	$csd = ChangesetDatabase();
	return GetChangesetMetadataLowLevel($csd,$cid);
}

function GetChangesetUid($cid)
{
	$lock=GetReadDatabaseLock();
	$csd = ChangesetDatabase();
	return $csd->GetUid($cid);
}

function GetChangesetContents($userInfo, $urlExp) //Download changeset URL
{
	$cid = (int)$urlExp[3];

	$lock=GetReadDatabaseLock();
	$csd = ChangesetDatabase();

	$changeset = $csd->GetContentObject($cid);
	if(is_null($changeset)) return array(0,Null,"not-found");

	return array(1,array("Content-Type:text/xml"),$changeset->ToXmlString());

}

function GetChangesetClosedTime($cid)
{
	$lock=GetReadDatabaseLock();
	$csd = ChangesetDatabase();
	return $csd->GetClosedTime($cid);
}

?>
