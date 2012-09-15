<?php
function CheckPermissions()
{
	$filesToCheck=array('nextnodeid.txt','nextchangesetid.txt','nextwayid.txt','db.lock','changesets-closed','changesets-open');

	foreach($filesToCheck as $f)
	if(!is_writable($f))
	{
		header('HTTP/1.1 500 Internal Server Error');
		echo $f.' is not writable';
		exit();
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

function getDirectory( $path = '.', $level = 0 )
{
$out=array();
// Directories to ignore when listing output.
$ignore = array( '.', '..' );

// Open the directory to the handle $dh
$dh = @opendir( $path );

// Loop through the directory
while( false !== ( $file = readdir( $dh ) ) )
{
// Check that this file is not to be ignored
if( !in_array( $file, $ignore ) )
{
// Show directories only
if(is_dir( "$path/$file" ) )
{
	//echo $file."\n";
	array_push($out,$file);
}
}
}
// Close the directory handle
closedir( $dh );
return $out;
} 


function ReadAndIncrementFileNum($filename)
{
	//This needs to be thread safe
	$fp = fopen($filename, "r+t");
	while (1) 
	{ 
		$wouldblock = null;
		$ret = flock($fp, LOCK_EX, $wouldblock);// do an exclusive lock
		if($ret == false) {throw new Exception('Lock failed.');}
		$out = (int)fread($fp,1024);
		if($out==0) $out = 1; //Disallow changeset to be zero
		fseek($fp,0);
		ftruncate($fp, 0); // truncate file
		fwrite($fp, $out+1);
		flock($fp, LOCK_UN); // release the lock
		fclose($fp);
		return $out;
	}
	return null;
}

//http://www.codewalkers.com/c/a/File-Manipulation-Code/Recursive-Delete-Function/
function RecursiveDeleteFolder($dirname)
{ // recursive function to delete
// all subdirectories and contents:
if(is_dir($dirname))$dir_handle=opendir($dirname);
while($file=readdir($dir_handle))
{
if($file!="." && $file!="..")
{
if(!is_dir($dirname."/".$file))unlink ($dirname."/".$file);
else RecursiveDeleteFolder($dirname."/".$file);
}
}
closedir($dir_handle);
rmdir($dirname);
return true;
}

function GetReadDatabaseLock()
{
	//To unlock, let the returned object go out of scope
	$fp = fopen("db.lock", "w");
	$ret = flock($fp, LOCK_SH);
	return $fp;
}

function GetWriteDatabaseLock()
{
	//To unlock, let the returned object go out of scope
	$fp = fopen("db.lock", "w");
	$ret = flock($fp, LOCK_EX);
	return $fp;
}

//http://www.webtoolkit.info/php-validate-email.html
function isValidEmail($email){
	return eregi("^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})$", $email);
}

?>
