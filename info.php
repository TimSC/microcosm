<?php
include_once('config.php');
include_once('modelfactory.php');

if(empty($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
}

// Strip off query string so dirname() doesn't get confused
$url = preg_replace('/\?.*$/', '', $_SERVER['REQUEST_URI']);
$path = ltrim(dirname($url), '/');
$url = 'http://'.$_SERVER['HTTP_HOST'].'/'.$path;
if(strcmp(substr($url, -1),"/")!=0)
	$url = $url."/";

?>

<html>
<title>Welcome to <?php echo SERVER_NAME;?></title>
<body>
<h1>Welcome to <?php echo SERVER_NAME;?></h1>
<p>
<!--The API URL is: <a href="<?php echo $url."microcosm.php" ?>"><?php echo $url."microcosm.php" ?></a>-->
</p>
<p>
The license for data is <?php echo LICENSE_HUMAN;?>.
</p>
<p>Available PDO drivers:
<?php
print_r(PDO::getAvailableDrivers());
?>
</p>
</body>
</html>

