<?php

require_once("config.php");
require_once("querymap.php");
require_once("osmtypes.php");
require_once("model-fs.php");

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

function ChangesetOpen($putData,$displayName,$userId)
{
	$lock=GetWriteDatabaseLock();

	$data = ParseOsmXmlChangeset($putData);
	if(is_null($data)) return "bad-input";

	$cid = ReadAndIncrementFileNum("nextchangesetid.txt");
	$data->attr['id'] = $cid;

	if(CheckChangesetIsOpen($cid)!=-1)
		return "changeset-already-exists";

	return ChangesetOpenLowLevel($cid,$data,$displayName,$userId);
}

function ChangesetUpdate($cid,$putData,$displayName,$userId)
{
	$lock=GetWriteDatabaseLock();
	$data = ParseOsmXmlChangeset($putData);
	if(is_null($data)) return "bad-input";

	//Check user is correct
	$xmlcid = $data->attr['id'];
	$cUser = GetChangesetUid($cid);
	if(is_null($cUser)) return "not-found";
	if($userId != $cUser) return "conflict";
	if($cid != $xmlcid) return "conflict";

	if(CheckChangesetIsOpen($cid)!=1)
		return "conflict";

	return ChangesetUpdateLowLevel($cid,$data,$displayName,$userId);
}

function ChangesetClose($cid)
{
	$lock=GetWriteDatabaseLock();
	ChangesetCloseLowLevel($cid);
}

//**************************************
//Process User Uploaded Map Data
//**************************************

//<osmChange version='0.6' generator='JOSM'><delete><way id='171' action='delete' timestamp='2010-10-14T08:41:57Z' uid='6809' user='TimSC' visible='true' version='1' changeset='209'/>  <node id='681' action='delete' timestamp='2010-10-14T08:41:57Z' uid='6809' user='TimSC' visible='true' version='1' changeset='209' lat='51.26718186473299' lon='-0.5552857742301459'/></delete></osmChange>

function CheckChildrenExist(&$children, $childType, &$createdEls,$map)
{
	foreach($children as $child)
	{
		$id = $child[0];
		if($id>0)
		{
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
			return "deleting-would-break-way1,".$wayId;
		}

		//Check if it is used by a recently changed element
		foreach($recentlyChanged as $el)
		{
			if(strcmp($el->GetType(),"way")!=0) continue;			

			$match = 0;
			//Check if this node is used
			foreach($el->nodes as $n)
			{
				if((int)$n[0]==(int)$id) {$match = $n[0];break;}
			}

			//Conflict found
			if($match!=0) return "deleting-would-break-way,".$match;
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
		return "deleting-would-break-relation,".$relId;
	}

	//Check if it is used by a recently changed element
	foreach($recentlyChanged as $el)
	{
		if(strcmp($el->GetType(),"relation")!=0) continue;			

		$match = 0;
		//Check if this element is used
		if(strcmp($type,"node")==0)
		foreach($el->nodes as $n)
		{
			if((int)$n[0]==(int)$id) {$match = $n[0];break;}
		}
		if(strcmp($type,"way")==0)
		foreach($el->ways as $n)
		{
			if((int)$n[0]==(int)$id) {$match = $n[0];break;}
		}
		if(strcmp($type,"relation")==0)
		foreach($el->relations as $n)
		{
			if((int)$n[0]==(int)$id) {$match = $n[0];break;}
		}

		//Conflict found
		if($match!=0) return "deleting-would-break-relation,".$match;
	}


	return 1;
}

$requiredAttributes = array('id','visible','changeset');

function ValidateOsmChange($osmchange,$cid,$displayName,$userId,$map)
{
	$createdEls = array();
	$deletedEls = array();
	$recentlyChanged = array();

	//Check API version
	$ver = $osmchange->version;
	if(strcmp($ver,"0.6")!=0) throw new Exception("OsmChange has wrong version");

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
			throw new Exception("Action not supported");

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
		if(!CheckChangesetIsOpen($cid)) throw new Exception("Changeset is not open");

		//Check if user has permission to add to this changeset
		$ciduser = GetChangesetUid($cid);
		if($ciduser != $userId) throw new Exception("Changeset belongs to a different user");

		//Check we don't exceed number of max elements
		$cidsize = GetChangetsetSize($cid);
		if(is_null($cidsize)) throw new Exception("Changeset size could not be determined ".$cid);
		if($cidsize + 1 > MAX_CHANGESET_SIZE)
			throw new Exception("Max changeset size exceeded");

		//Check if method is consistent
		//TODO work out what action really does??
		if(isset($el->attr['action']))
		{
		$objaction = $el->attr['action'];
		//if(strcmp($action,$objaction)!=0)
		//	throw new Exception("Action specified in object not consistent ".$action." vs. ".$objaction);
		}

 		//Check visibility is boolean (always true?)
		$visible = $el->attr['visible'];
		if(!(strcmp($visible,"true")==0 or strcmp($visibile,"false")==0))
			throw new Exception("Visibile attribute must be true or false");

		//Object id zero not allowed?
		if($id == 0) throw new Exception("Object ID zero not allowed");

		//Object versions
		if($id >= 0)
		{
			$currentVer = $map->GetCurentVerOfElement($type,$id);
			if($currentVer==null)
				return "object-not-found,".$type." ".$id." A";
			if($ver != $currentVer and VERSION_VALIDATION)
			{
				return "version-mismatch,".(int)$ver.",".$currentVer.",".$type.",".$id;
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


		//Check if references elements actual exist, Nodes
		$ret = CheckChildrenExist($el->nodes, "node", $createdEls, $map);
		if($ret==0) return "object-not-found,".$type." ".$id." B";

		//Check if references elements actual exist, Ways
		$ret = CheckChildrenExist($el->ways, "way", $createdEls, $map);
		if($ret==0) return "object-not-found,".$type." ".$id." C";

		//Check if references elements actual exist, Relations
		$ret = CheckChildrenExist($el->relations, "relation", $createdEls, $map);
		if($ret==0) return "object-not-found,".$type." ".$id." D";
	
		//Check if deleting stuff will break ways
		if(strcmp($action,"delete")==0)
		{
		$ret = CheckCitationsIfDeleted($type, $el, $map, $deletedEls, $recentlyChanged);
		if($ret!=1) return $ret;
		}

		//Check if deleting stuff will break relations

		//Enforce max nodes in way and relations, etc
		if (count($el->nodes)>MAX_WAY_NODES)
			return "too-large";
		}
	}	

	return 1;
}

function AssignIdsToOsmChange(&$osmchange,$displayName,$userId)
{
	$idmapping = array();
	$changes = array();

	foreach($osmchange->data as $i => $eldata)
	{
		$action = $eldata[0];
		$els = $eldata[1];

		//Set timestamp of object
		$el->attr['timestamp'] = date('c'); //Set timestamp at creation

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

				//Store changes to return to editor
				array_push($changes,array($type,$oldid,null,null,$newver));
			}
		}

		//Renumber members, for each element
		foreach($els as $i2 => $el)
		{
			//Renumber child nodes
			//if(strcmp($el->GetType(),"way")==0 or strcmp($el->GetType(),"relation")==0)
			foreach($el->nodes as $i3 => $nd)
			{		
				$oldid = $nd[0];
				if($oldid >= 0) continue;
				$type = "node";
				if(!isset($idmapping[$type.",".$oldid]))
					throw new Exception("ID not found in mapping ".$type.",".$oldid);
				$newid = $idmapping[$type.",".$oldid][0];
				$el->nodes[$i3][0] = $newid;
			}

			//Renumber child ways
			//if(strcmp($el->GetType(),"relation")==0)
			foreach($el->ways as $i3 => $way)
			{	
				$oldid = $way[0];
				if($oldid >= 0) continue;
				$type = "way";
				if(!isset($idmapping[$type.",".$oldid]))
					throw new Exception("ID not found in mapping ".$type.",".$oldid);
				$newid = $idmapping[$type.",".$oldid][0];
				$el->ways[$i3][0] = $newid;
			}

			//Renumber child relations			
			//if(strcmp($el->GetType(),"relation")==0)
			foreach($el->relations as $i3 => $relation)
			{	
				$oldid = $relation[0];
				if($oldid >= 0) continue;
				$type = "relations";
				if(!isset($idmapping[$type.",".$oldid]))
					throw new Exception("ID not found in mapping ".$type.",".$oldid);
				$newid = $idmapping[$type.",".$oldid][0];
				$el->relations[$i3][0] = $newid;
			}

		}
	}

	return $changes;
}

function ApplyChangeToDatabase(&$osmchange,&$map)
{
	//For each element
	foreach($osmchange->data as $data)
	{
		list($method, $els) = $data;
		if(strcmp($method,"create")==0)
		foreach($els as $el)
		{
			$type = $el->GetType();

			$map->CreateElement($type,$el->attr['id'],$el);
			//$value = simplexml_load_string($el->ToXmlString());
			//$newdata = $map->addChild($type);
			//CloneElement($value, $newdata);

			//Also store in changeset
			AppendElementToChangeset($el->attr['changeset'], $method, $el);
		}

		if(strcmp($method,"modify")==0)
		foreach($els as $el)
		{
			$type = $el->GetType();

			$map->ModifyElement($type,$el->attr['id'],$el);
			//$value = simplexml_load_string($el->ToXmlString());
			//RemoveElementById($map, $type, $value['id']);
			//$element = $map->addChild($type);
			//CloneElement($value, $element);

			//Also store in changeset
			AppendElementToChangeset($el->attr['changeset'], $method, $el);
		}

		if(strcmp($method,"delete")==0)
		foreach($els as $el)
		{
			$type = $el->GetType();
			$value = simplexml_load_string($el->ToXmlString());

			$map->DeleteElement($type,$el->attr['id'],$el);
			//echo "delete ".$type." ".$value['id']."\n";
			//RemoveElementById($map, $type, $value['id']);

			//Also store in changeset
			AppendElementToChangeset($el->attr['changeset'], $method, $el);
		}

	}
}

function ProcessOsmChange($cid,$osmchange,$displayName,$userId)
{
	//Lock the database for writing
	$lock=GetWriteDatabaseLock();

	//Load database
	$map = new OsmDatabase();

	//Validate change
	try
	{
	$valret = ValidateOsmChange($osmchange,$cid,$displayName,$userId,$map);
	if($valret != 1)
		return $valret;
	}
	catch (Exception $e)
	{
		return "bad-request,".$e;
	}

	//Assign IDs to created objects
	$changes = AssignIdsToOsmChange($osmchange,$displayName,$userId);

	//Apply changes to database
	ApplyChangeToDatabase($osmchange,$map);

	$map->Save();

	return $changes;
}

function ProcessSingleObject($method,$data,$displayName,$userId)
{
	//$cid = 204;
	//$data = "<osm version='0.6' generator='JOSM'>  <node id='-7801' visible='true' changeset='220' lat='51.26493956255727' lon='-0.5636603245748466' /></osm>";

	//Construct an OsmChange from the single object
	$osmchange = new OsmChange();
	$singleObj = SingleObjectFromXml($data);
	array_push($osmchange->data,array($method,array($singleObj)));
	$cid = null;
	
	//Process OsmChange
	$changes = ProcessOsmChange($cid,$osmchange,$displayName,$userId);

	//Return version or new ID as appropriate
	$newobjid = $changes[0][2];
	$newversion = $changes[0][4];
	if(strcmp($method,"create")==0)
		return $newobjid;
	return $newversion;
}

function ChangesetUpload($cid,$data,$displayName,$userId)
{
//	$cid=204;

//	$data="<osmChange version=\"0.6\" generator=\"PythonTest\">\n<modify>\n<way id='595' action='modify' timestamp='2009-03-01T14:21:58Z' uid='6809' user='TimSC' visible='true' version='1' changeset='204' lat='51.285184946434256' lon='-0.5986675534296598'><tag k='name' v='Belvidere'/><nd ref='555'/></way>\n</modify>\n</osmChange>\n";

	//$data="<osmChange version='0.6' generator='JOSM'><delete><way id='171' action='delete' timestamp='2010-10-14T08:41:57Z' uid='6809' user='TimSC' visible='true' version='1' changeset='209'/>  <node id='681' action='delete' timestamp='2010-10-14T08:41:57Z' uid='6809' user='TimSC' visible='true' version='1' changeset='209' lat='51.26718186473299' lon='-0.5552857742301459'/></delete></osmChange>";

	//Convert XML upload to native data
	$osmchange = new OsmChange();
	$osmchange->FromXmlString($data);

	//Process OsmChange
	$changes = ProcessOsmChange($cid,$osmchange,$displayName,$userId);
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

	return $diff;
	}

	return $changes;
}

/*function ChangesetToXml($cid)
{
	$open = CheckChangesetIsOpen($cid);
	if($open==-1) return "not-found";
	$path = "changesets-open/";
	if($open==0) $path = "changesets-closed/";
	
	$details = explode(";",file_get_contents($path.$cid."/details.txt"));
	$xml = simplexml_load_file($path.$cid."/putdata.xml");	

	//2010-10-02T11:43:37Z
	$da = date("c", (int)$details[2]);
	$out = '<changeset id="'.$cid.'" user="'.$details[0].'" uid="'.$details[1].'" created_at="'.$da;
	if($open==true) $out = $out.'" open="true"';
	if($open==false) $out = $out.'" open="false"';
	//TODO bounding box calculation
	//$out = $out.' min_lon="7.4444867" min_lat="46.997839" max_lon="7.4774895" max_lat="47.014299"';
	$out = $out. '>'."\n";
	foreach ($xml as $type => $changsetxml)
	{
		foreach ($changsetxml as $type => $v)
		{
			//echo "here".$v['k'];
			if(strcmp($type,"tag")!=0) continue;
			$out = $out.'<tag k="'.$v['k'].'" v="'.$v['v'].'"/>'."\n";
		}
	}

	$out = $out.'</changeset>'."\n";
	return $out;
}*/

function GetChangesets($query)
{
	$lock=GetReadDatabaseLock();
	$user = null;
	if(isset($query['user'])) $user = (int)$query['user'];
	$open = null;
	if(isset($query['open'])) $open = (strcmp($query['open'],"true")==0);
	$timerange = null; //TODO implement this
	$csids = ChangesetsQuery($user,$open,$timerange);
	//print_r($csids);
	
	$out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$out = $out.'<osm version="0.6" generator="'.SERVER_NAME.'">'."\n";

	foreach($csids as $cid)
	{ 
		if(isset($query['user']) and $query['user'] != GetChangesetUid($cid)) continue;
		#$out = $out.ChangesetToXml($cid);
		$out = $out.GetChangesetMetadataLowLevel($cid)->ToXmlString();
	}
	
	$out = $out.'</osm>'."\n";
	return $out;
}

function GetChangesetMetadata($cid)
{
	$lock=GetReadDatabaseLock();
	$data = GetChangesetMetadataLowLevel($cid);
	if(is_null($data)) return "not-found";
	return '<osm version="0.6" generator="'.SERVER_NAME.'">'.$data->ToXmlString().'</osm>';

	//$data = ChangesetToXml($cid);
	//if(strcmp("not-found",$data)==0) return $data;
	//return '<osm version="0.6" generator="'.SERVER_NAME.'">'.$data.'</osm>';

}

function GetChangesetContents($cid)
{
	$lock=GetReadDatabaseLock();
	$changeset = GetChangesetContentObject($cid);
	if(is_null($changeset)) return "not-found";

	return($changeset->ToXmlString());

}

function GetChangesetClosedTime($cid)
{
	$lock=GetReadDatabaseLock();
	GetChangesetClosedTimeLowLevel($cid);
}

?>
