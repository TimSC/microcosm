<?php

//require_once 'PHPUnit.php';
require_once("config.php");
require_once("querymap.php");
require_once('changeset.php');
require_once('fileutils.php');
require_once('traces.php');
require_once('userdetails.php');
require_once('requestprocessor.php');
require_once('system.php');
//require_once 'microcosm.php';




class test  extends PHPUnit_Framework_TestCase
{

  public function testGetCapabilities() {
    GetCapabilities();
    return 0;
  }
};
