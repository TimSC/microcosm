<?php
require_once('OAuth.php');
require_once('fileutils.php');
require_once('system.php');

class OAuthMicrocosmStore extends OAuthDataStore
{
	function __construct()
	{
		$this->consumer = new OAuthConsumer("AdCRxTpvnbmfV8aPqrTLyA", "XmYOiGY9hApytcBC3xCec3e28QBqOWz5g6DSb5UpE", NULL);
		$this->requestToken = new OAuthToken("requestkey", "requestsecret", 1);
	}

	function lookup_consumer($consumer_key) 
	{
		if ($consumer_key == $this->consumer->key) 
			return $this->consumer;
		return NULL;
	}

	function lookup_token($consumer, $token_type, $token)
	{
		// implement me
	}

	function lookup_nonce($consumer, $token, $nonce, $timestamp)
	{
		// implement me
	}

	function new_request_token($consumer, $callback = null)
	{
		// return a new token attached to this consumer
		if ($consumer->key == $this->consumer->key or True)
		{
			return $this->requestToken;
		}
		return NULL;
	}

	function new_access_token($token, $consumer, $verifier = null)
	{
		// return a new access token attached to this consumer
		// for the user associated with this token if the request token
		// is authorized
		// should also invalidate the request token
	}
}


class OAuthMicrocosm
{
	function __construct()
	{
		$this->dataStore = new OAuthMicrocosmStore();
	}

	function RequestToken()
	{
		try
		{
			$server = new OAuthServer($this->dataStore);
			$server->add_signature_method(new OAuthSignatureMethod_HMAC_SHA1());
			$req = OAuthRequest::from_request();
			$token = $server->fetch_request_token($req);

			echo $token;
			$fi = fopen("out.txt","wt");
			fwrite($fi, $token);
			fclose($fi);
		}
		catch (OAuthException $e)
		{
			print($e->getMessage() . "\n<hr />\n");
			print_r($req);

			$fi = fopen("out.txt","wt");
			fwrite($fi, $e->getMessage() . "\n<hr />\n");
			fwrite($fi, print_r($req, True));
			fclose($fi);

			die();
		}
	}

	function AccessToken()
	{

	}

	function Authorise()
	{

	}
}

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
	//if(!$error)
	//	echo "login_url=https://localhost/m/oauth.php/authorize&oauth_token=".uniqid(mt_rand(), true).
	//		'&oauth_token_secret='.uniqid(mt_rand(), true).
	//		'&oauth_callback_confirmed=true';
}

if($urlExp[1] == "access_token")
{
	$oam = new OAuthMicrocosm();
	$error = $oam->AccessToken();
}

if($urlExp[1] == "authorize")
{
	$oam = new OAuthMicrocosm();
	$error = $oam->Authorise();
}

fclose($fi);

?>
