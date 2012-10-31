<?php
require_once('OAuth.php');
require_once('fileutils.php');
require_once('system.php');
require_once('auth.php');

class OAuthMicrocosmStore extends OAuthDataStore
{
	function __construct()
	{
		$this->consumer = new OAuthConsumer("AdCRxTpvnbmfV8aPqrTLyA", "XmYOiGY9hApytcBC3xCec3e28QBqOWz5g6DSb5UpE", NULL);
		$this->requestToken = new OAuthToken("requestkey", "requestsecret", 1);
        $this->accessToken = new OAuthToken("accesskey", "accesssecret", 1);
        $this->nonce = "nonce";
	}

	function lookup_consumer($consumer_key) 
	{
		$consumerSecret = CallFuncByMessage(Message::OAUTH_LOOKUP_CONSUMER, array($consumer_key));
		if($consumerSecret!==Null)
			return new OAuthConsumer($consumer_key, $consumerSecret, NULL);

		//if ($consumer_key == $this->consumer->key) 
		//	return $this->consumer;
		return NULL;
	}

	function lookup_token($consumer, $token_type, $token)
	{
		$token_attrib = $token_type . "Token";
		//if ($consumer->key == $this->consumer->key
		//	&& $token == $this->$token_attrib->key)
		//{
		//	return $this->$token_attrib;
		//}

		if ($consumer->key == $this->consumer->key && $token == $this->requestToken->key)
		{
			return $this->requestToken;
		}
		if ($consumer->key == $this->consumer->key && $token == $this->accessToken->key)
		{
			return $this->accessToken;
		}

		return NULL;
	}

	function lookup_nonce($consumer, $token, $nonce, $timestamp)
	{
		if ($consumer->key == $this->consumer->key
			&& (($token && $token->key == $this->requestToken->key)
				|| ($token && $token->key == $this->accessToken->key))
			&& $nonce == $this->nonce)
		{
			return $this->nonce;
		}
        return NULL;
	}

	function new_request_token($consumer, $callback = null)
	{
		// return a new token attached to this consumer
		if ($consumer->key == $this->consumer->key)
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
		if ($consumer->key == $this->consumer->key && $token->key == $this->requestToken->key)
		{
			return $this->accessToken;
		}
		return NULL;
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
			$fi = fopen("out.txt","wt");
			fwrite($fi, "Token:".$token);
			fclose($fi);
			return True;
		}
		catch (OAuthException $e)
		{
			print($e->getMessage() . "\n<hr />\n");
			print_r($req);

			$fi = fopen("out.txt","wt");
			fwrite($fi, $e->getMessage() . "\n<hr />\n");
			fwrite($fi, print_r($req, True));
			fclose($fi);
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
			$displayName = Null;
			$userId = Null;
			if($token->key == $this->dataStore->accessToken->key)
			{
				$displayName = "TimSC";
				$userId = 1;
			}

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
