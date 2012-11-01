<?php
require_once('oauthfuncs.php');

//Split URL for processing
$pathInfo = GetRequestPath();
$urlExp = explode("/",$pathInfo);

$fi = fopen("log.txt","wt");
fwrite($fi,print_r($_POST,True));
fwrite($fi,print_r($pathInfo,True));

if($urlExp[1] == "request_token")
{
	$oam = new OAuthMicrocosm();
	$error = $oam->RequestToken();
}

if($urlExp[1] == "access_token")
{
	$oam = new OAuthMicrocosm();
	$error = $oam->AccessToken();
}

if($urlExp[1] == "authorise")
{
	$oam = new OAuthMicrocosm();
	$error = $oam->Authorise();
}

fclose($fi);

?>
