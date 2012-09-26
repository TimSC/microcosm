<?
require_once('fileutils.php');
require_once('querymap.php');
require_once('system.php');
require_once('auth.php');


//******************************
//Start up functions and logging
//******************************

////-------------------------
dprint("$_SERVER",$_SERVER);

/*     [DOCUMENT_ROOT] => /home/mdupont/experiments/fosm/microcosm/ */
/*     [GATEWAY_INTERFACE] => CGI/1.1 */
/*     [HTTP_ACCEPT] => text/html,application/xhtml+xml,application/xml;q=0.9,*\/\*;q=0.8 */
/*     [HTTP_ACCEPT_CHARSET] => ISO-8859-1,utf-8;q=0.7,*;q=0.3 */
/*     [HTTP_ACCEPT_ENCODING] => gzip,deflate,sdch */
/*     [HTTP_ACCEPT_LANGUAGE] => en-US,en;q=0.8,sq;q=0.6,de;q=0.4 */
/*     [HTTP_CACHE_CONTROL] => max-age=0 */
/*     [HTTP_CONNECTION] => keep-alive */
/*     [HTTP_HOST] => localhost */
/*     [HTTP_USER_AGENT] => Mozilla/5.0 (X11; Linux i686) AppleWebKit/535.19 (KHTML, like Gecko) Ubuntu/12.04 Chromium/18.0.1025.168 Chrome/18.0.1025.168 Safari/535.19 */
/*     [PATH] => /usr/local/bin:/usr/bin:/bin */
/*     [PHP_SELF] => /microcosm.php */
/*     [QUERY_STRING] => bbox=80,80,90,90 */
/*     [REDIRECT_QUERY_STRING] => bbox=80,80,90,90 */
/*     [REDIRECT_STATUS] => 200 */
/*     [REDIRECT_URL] => /0.6/map */
/*     [REMOTE_ADDR] => 127.0.0.1 */
/*     [REMOTE_PORT] => 52878 */
/*     [REQUEST_METHOD] => GET */
/*     [REQUEST_TIME] => 1348380992 */
/*     [REQUEST_URI] => /0.6/map?bbox=80,80,90,90 */
/*     [SCRIPT_FILENAME] => /home/mdupont/experiments/fosm/microcosm/microcosm.php */
/*     [SCRIPT_NAME] => /microcosm.php */
/*     [SERVER_ADDR] => 127.0.0.1 */
/*     [SERVER_ADMIN] => [no address given] */
/*     [SERVER_NAME] => localhost */
/*     [SERVER_PORT] => 80 */
/*     [SERVER_PROTOCOL] => HTTP/1.1 */
/*     [SERVER_SIGNATURE] => <address>Apache/2.2.22 (Ubuntu) Server at ....</address> */
/*     [SERVER_SOFTWARE] => Apache/2.2.22 (Ubuntu) */


  $options = getopt("p:g:q:");
  

  if(!isset($options["g"]))
    {    
      if (isset($_GET) ) {
        if (count($_GET) < 1) {
          //      dprint("_GET:",$_GET);
          //      print "_get set";
          //      die("omg");
          $MYGET=array();
        }
        $MYGET=$_GET;
      }
    }  
  else  
    {
      $MYGET=array();    
      dprint("args:",$options);    
      if(!isset($options["g"]))    {    
        die("Could not determine get args, no -g option on the command line. :GET\n" );
      } else         {
        $get= $options["g"];
        if (count($get) > 0)
          {

            if (is_array($get)) {
              foreach ($get as &$value) {
                $kv = explode("=",$value);
                if (count($kv) > 0)
                  {
                    if (isset($kv[1]))     {
                    $MYGET[$kv[0]]=$kv[1];
                    }  else {
                      $MYGET[$kv[0]]="";
                  }
                  }
                else
                  {
                    $MYGET[$value]="";
                  }
                
              }
            }
            else
              {
                dprint("GET",$get);                
                $kv = explode("=",$get);
                if (count($kv) > 0)
                  {
                    if (isset($kv[1]))     {
                      $MYGET[$kv[0]]=$kv[1];
                    }  else {
                      $MYGET[$kv[0]]="";
                    }
                  }
                else
                  {
                    $MYGET[$value]="";
                  }
                
              }
          }
        else
          {
            dprint("GET",$get);
          }
      }
    }
  
  // ---------
  if(!isset($_SERVER['PATH_INFO']))  {

    if(!isset($options["p"]))
      {
        //die("Could not determine URL path, no -p option on the command line. :pathInfo\n" );
        $request = explode("?",$_SERVER['REQUEST_URI']);
        if (count($request) > 0) {
          $_SERVER['PATH_INFO']= $request[0];
        } else  {
          $_SERVER['PATH_INFO']= $_SERVER['REQUEST_URI'];
            }         
      } else {
      $_SERVER['PATH_INFO']= $options["p"];
    }

  } else {
    dprint("PATH_INFO:",$_SERVER['PATH_INFO']);
  }

  if(!isset($_SERVER['QUERY_STRING']))  {
     if(!isset($options["q"]))
      {
        $_SERVER['QUERY_STRING']= "";

      }  else    {
       $_SERVER['QUERY_STRING']= $options["q"];
    }
  }
/////////////----------------



CallFuncByMessage(Message::SCRIPT_START,Null); 

//Allow GET args to be set by command line
$options = getopt(PROG_ARG_STRING, $PROG_ARG_LONG);
if(isset($options["g"]))
{
	$get= $options["g"];
	if(!is_array($get)) $get = array($get);
	foreach ($get as &$value)
	{
		$kv = explode("=",$value);
		if (isset($kv[1]))
			$_GET[$kv[0]]=$kv[1];
		else
			$_GET[$kv[0]]="";
	}
}

//print_r($_SERVER);

CheckPermissions();

//Split URL for processing
$pathInfo = GetRequestPath();
$urlExp = explode("/",$pathInfo);

dprint("pathinfo",$pathInfo);

//Log the request
$fi = fopen("log.txt","at");
flock($fi, LOCK_EX);
fwrite($fi,GetServerRequestMethod());
fwrite($fi,"\t");
fwrite($fi,$pathInfo);
fwrite($fi,"\t");
if(isset($_SERVER['QUERY_STRING'])) fwrite($fi,$_SERVER['QUERY_STRING']);

ob_start();
var_export($_SERVER);
$serverVarDump = ob_get_contents();
ob_end_clean();
//fwrite($fi,"\t");
//fwrite($fi,$serverVarDump);

ob_start();
var_export($_POST);
$postVarDump = ob_get_contents();
ob_end_clean();
fwrite($fi,"\t");
fwrite($fi,$postVarDump);

$putdata = fopen("php://input", "r");
$putDataStr = "";
while ($data = fread($putdata, 1024))
{
	$putDataStr = $putDataStr . $data;
}
fwrite($fi,"\t");
fwrite($fi,$putDataStr);

fwrite($fi,"\n");
fflush($fi);
fclose($fi);

//If we are in read only mode, require GET method
if(API_READ_ONLY and strcmp(GetServerRequestMethod(),"GET")!=0)
{
	header ('HTTP/1.1 503 Service Unavailable');
	echo "API in read only mode.";
	return;
}

//***********************
//User Authentication
//***********************

//Authentication, if there is a server username variable
if (isset($_SERVER['PHP_AUTH_USER'])) 
	list ($displayName, $userId) = RequireAuth();

//Only allow GET method or else request authentication
if(strcmp(GetServerRequestMethod(),"GET")!=0)
{
	list ($displayName, $userId) = RequireAuth();
}
else
{
	$displayName = null;
	$userId = null;
}


//This function determines with function to call based on the URL and, if it can, responds to the client.
$processed = CallFuncByMessage(Message::API_EVENT,array($pathInfo,$urlExp,$putDataStr,$_GET,$_POST,$_FILES));
if(!$processed)
{
	header ('HTTP/1.1 404 Not Found');
	echo "URL not found.";

}

//Trigger destructors acts better, rather than letting database handle going out of scope
CallFuncByMessage(Message::SCRIPT_END,Null); 

//Housekeeping? Process traces?

//TODO Close changesets that time out

?>
