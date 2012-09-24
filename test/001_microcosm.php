<?php

require_once("config.php");
require_once("querymap.php");
require_once('changeset.php');
require_once('fileutils.php');
require_once('traces.php');
require_once('userdetails.php');
require_once('requestprocessor.php');
require_once('system.php');

class test  extends PHPUnit_Framework_TestCase
{

  public function testGetCapabilities() {
    GetCapabilities();
    return 0;
  }

  public function testMapQuery() {
    $userInfo="";
    $a=array(0,0,0,0);
    MapQuery($userInfo,$a);
    return 0;
  }


  //for x in `cat test/functions.txt `; do echo "// $x"; echo "public function test${x} () { \$ret = ${x}(); return \$ret; }"; done;

// ChangesetClose
public function testChangesetClose () { 
  $userInfo="";
  $argExp=0;
  $ret = ChangesetClose($userInfo,$argExp); 
  return $ret; }
// ChangesetExpandBbox
public function testChangesetExpandBbox () { $ret = ChangesetExpandBbox(); return $ret; }
// ChangesetOpen
public function testChangesetOpen () { $ret = ChangesetOpen(); return $ret; }
// ChangesetUpdate
public function testChangesetUpdate () { $ret = ChangesetUpdate(); return $ret; }
// ChangesetUpload
public function testChangesetUpload () { $ret = ChangesetUpload(); return $ret; }
// GetChangesetContents
public function testGetChangesetContents () { $ret = GetChangesetContents(); return $ret; }
// GetChangesetMetadata
public function testGetChangesetMetadata () { $ret = GetChangesetMetadata(); return $ret; }
// GetChangesets
public function testGetChangesets () { $ret = GetChangesets(); return $ret; }
// GetFullDetailsOfElement
public function testGetFullDetailsOfElement () { $ret = GetFullDetailsOfElement(); return $ret; }
// GetRelationsForElement
public function testGetRelationsForElement () { $ret = GetRelationsForElement(); return $ret; }
// GetTraceData
public function testGetTraceData () { $ret = GetTraceData(); return $ret; }
// GetTraceDetails
public function testGetTraceDetails () { $ret = GetTraceDetails(); return $ret; }
// GetTraceForUser
public function testGetTraceForUser () { $ret = GetTraceForUser(); return $ret; }
// GetTracesInBbox
public function testGetTracesInBbox () { $ret = GetTracesInBbox(); return $ret; }
// GetUserDetails
public function testGetUserDetails () { $ret = GetUserDetails(); return $ret; }
// GetUserPermissions
public function testGetUserPermissions () { $ret = GetUserPermissions(); return $ret; }
// GetUserPreferences
public function testGetUserPreferences () { $ret = GetUserPreferences(); return $ret; }
// GetWaysForNode
public function testGetWaysForNode () { $ret = GetWaysForNode(); return $ret; }
// InsertTraceIntoDb
public function testInsertTraceIntoDb () { $ret = InsertTraceIntoDb(); return $ret; }
// MapObjectFullHistory
public function testMapObjectFullHistory () { $ret = MapObjectFullHistory(); return $ret; }
// MapObjectQuery
public function testMapObjectQuery () { $ret = MapObjectQuery(); return $ret; }
// MultiFetch
public function testMultiFetch () { $ret = MultiFetch(); return $ret; }
// ProcessSingleObject
public function testProcessSingleObject () { $ret = ProcessSingleObject(); return $ret; }
// SetUserPreferences
public function testSetUserPreferences () { $ret = SetUserPreferences(); return $ret; }
// SetUserPreferencesSingle
public function testSetUserPreferencesSingle () { $ret = SetUserPreferencesSingle(); return $ret; }

};
