<?php
require_once('OAuth.php');
require_once('fileutils.php');
require_once('system.php');
require_once('auth.php');

class OAuthMicrocosmStore extends OAuthDataStore
{
	function __construct()
	{

	}

	function lookup_consumer($consumer_key) 
	{
		$consumerSecret = CallFuncByMessage(Message::OAUTH_LOOKUP_CONSUMER, array($consumer_key));
		if($consumerSecret!==Null)
			return new OAuthConsumer($consumer_key, $consumerSecret, NULL);

		return NULL;
	}

	function lookup_token($consumer, $tokenType, $token)
	{
		$consumerSecret = CallFuncByMessage(Message::OAUTH_LOOKUP_CONSUMER, array($consumer->key));
		if($consumerSecret===Null)
			return Null;

		$tokenSecret = CallFuncByMessage(Message::OAUTH_LOOKUP_TOKEN, array($tokenType, $token));
		if($tokenSecret===Null)
			return Null;

		return new OAuthToken($token, $tokenSecret, 1);
	}

	function lookup_nonce($consumer, $token, $nonce, $timestamp)
	{

		$consumerSecret = CallFuncByMessage(Message::OAUTH_LOOKUP_CONSUMER, array($consumer->key));
		if($consumerSecret===Null)
			return Null;

		if(!$token) return Null;

		$tokenSecret = CallFuncByMessage(Message::OAUTH_LOOKUP_TOKEN, array(Null, $token->key));
		if($tokenSecret!==Null)
		{
			$found = CallFuncByMessage(Message::OAUTH_LOOKUP_NONCE, array($token->key, $nonce, $timestamp));
			if($found) return $nonce;
		}

        return NULL;
	}

	function new_request_token($consumer, $callback = null)
	{
		$consumerSecret = CallFuncByMessage(Message::OAUTH_LOOKUP_CONSUMER, array($consumer->key));
		if($consumerSecret===Null)
			return Null;

		list($token, $tokenSecret) = CallFuncByMessage(Message::OAUTH_NEW_ACCESS_TOKEN, Null);
		if($token === Null)
			return Null;

		return new OAuthToken($token, $tokenSecret, 1);
	}

	function new_access_token($token, $consumer, $verifier = null)
	{
		$consumerSecret = CallFuncByMessage(Message::OAUTH_LOOKUP_CONSUMER, array($consumer->key));
		if($consumerSecret===Null)
			return Null;

		list($token, $tokenSecret) = CallFuncByMessage(Message::OAUTH_NEW_REQUEST_TOKEN, Null);
		if($token === Null)
			return Null;

		return new OAuthToken($token, $tokenSecret, 1);
	}

	function verify($token)
	{
		list($displayName, $userId) = CallFuncByMessage(Message::OAUTH_GET_USER_FROM_ACCESS_TOKEN, array($token->key));
		return array($displayName, $userId);
	}
}

function PromptForUsernameAndPassword()
{
?>

<form method="POST">
Username: <input type="text" name="username"><br/>
Password: <input type="password" name="password"><br/>
<input type="submit" value="Submit">
</form> 

<?php
}

class OAuthMicrocosm
{
	function __construct()
	{
		$this->dataStore = new OAuthMicrocosmStore();
		$this->server = new OAuthServer($this->dataStore);
		$this->server->add_signature_method(new OAuthSignatureMethod_HMAC_SHA1());
	}

	function FetchToken($request, $access)
	{
		try
		{
			$req = OAuthRequest::from_request();
			$token = Null;
			if($request) $token = $this->server->fetch_request_token($req);
			if($access) $token = $this->server->fetch_access_token($req);

			echo $token;
			/*$fi = fopen("out.txt","wt");
			fwrite($fi, "Token:".$token);
			fclose($fi);*/
			return True;
		}
		catch (OAuthException $e)
		{
			print($e->getMessage() . "\n<hr />\n");
			print_r($req);

			/*$fi = fopen("out.txt","wt");
			fwrite($fi, $e->getMessage() . "\n<hr />\n");
			fwrite($fi, print_r($req, True));
			fclose($fi);*/
			return False;
		}
	}

	function RequestToken()
	{
		return $this->FetchToken(True,False);
	}

	function AccessToken()
	{
		return $this->FetchToken(False,True);
	}

	function Authorise()
	{
		session_start();

		//Process log on
		if(isset($_POST['username']) and isset($_POST['password']))
		{
			$authRet = RequireAuth($_POST['username'], $_POST['password']);
			if($authRet == -1) //If authentication failed
				$authFailed = True;
			else
			{
				list ($displayName, $userId) = $authRet;
				$_SESSION['username'] = $_POST['username'];
				$_SESSION['displayName'] = $displayName;
				$_SESSION['userId'] = $userId;
			}
		}

		if(isset($_SESSION['userId']))
		{
			echo "Logged in as ".$_SESSION['username']." ".$_SESSION['displayName']."(".$_SESSION['userId'].")";
		}
		else
		{
			PromptForUsernameAndPassword();
		}
	}

	function Verify()
	{
		try
		{
			$req = OAuthRequest::from_request();
			list($consumer, $token) = $this->server->verify_request($req);

			// lsit back the non-OAuth params
			$total = array();
			foreach($req->get_parameters() as $k => $v) {
				if (substr($k, 0, 5) == "oauth") continue;
				$total[] = urlencode($k) . "=" . urlencode($v);
			}
			//return implode("&", $total);

			//Get display name and ID num
			list($displayName, $userId) = $this->dataStore->verify($token);

			return array(True, $displayName, $userId, $consumer, $token);
		} 
		catch (OAuthException $e)
		{
			print($e->getMessage() . "\n<hr />\n");
			print_r($req);
			return array(False, Null, Null, Null, Null);
		}
	}
}

?>
