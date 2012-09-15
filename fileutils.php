<?php
function CheckPermissions()
{
	$filesToCheck=array('nextnodeid.txt','nextchangesetid.txt','nextwayid.txt','db.lock','changesets-closed','changesets-open');

	foreach($filesToCheck as $f)
	if(!is_writable($f))
	{
		header('HTTP/1.1 500 Internal Server Error');
		echo $f.' is not writable';
		exit;
	}

}

function GetServerRequestMethod()
{
	$out = $_SERVER['REQUEST_METHOD'];
	if(isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']))
		$out = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'];
	return $out;
}


function RequireMethod($reqMethod)
{
	if(strcmp(GetServerRequestMethod(),$reqMethod)!=0 and !DEBUG_MODE)
	{
		header('HTTP/1.1 405 Method Not Allowed');
		echo "Only method ".$reqMethod." is supported on this URI";
		exit;						
	}
}


?>
