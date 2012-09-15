<?php
include_once('importfuncs.php');

//print_r($_SERVER);
if(isset($_SERVER['TERM']))
{
	set_time_limit(0);

	$nukeDatabase = 1;
	$lockDatabase = 1;

	$longopts  = array(
	    "dontnuke",     // Required value
	    "dontlock");

	$options = getopt("i:",$longopts);
	//var_dump($options);

	if(!isset($options["i"]))
	{
		print "Usage: php ".$argv[0]." -i data.osm [--dontnuke] [--dontlock]\n";
		exit(0);
	}
	$filename = $options["i"];

	if(isset($options["dontnuke"]))
	{
		$nukeDatabase = 0;
	}

	if(isset($options["dontlock"]))
	{
		$lockDatabase = 0;
	}
	//echo $filename,$nukeDatabase,$lockDatabase;

	Import($filename,$nukeDatabase,$lockDatabase);
}

?>
