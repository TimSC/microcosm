<?php
//Handle log in
require_once('../requestprocessor.php');
require_once('../importfuncs.php');
require_once('../auth.php');

list($login, $pass) = GetUsernameAndPassword();
list ($displayName, $userId) = RequireAuth($login,$pass);
$userDb = UserDbFactory();
$userData = $userDb->GetUser($userId);
$userAdmin = $userData['admin'];
if(!$userAdmin) die("Need administrator access");

//print_r($_FILES);

if (!isset($_FILES["file"]))
  {
?>

<html>
<body>
<form action="import.php" method="post" enctype="multipart/form-data">
<label for="file">Filename:</label>
<input type="file" name="file" id="file" /><br />
<input type="submit" name="submit" value="Submit" />
</form>
</body>
</html>

<?php
  }
else
  {
  if ($_FILES["file"]["error"] > 0)
  {
  echo "Error: " . $_FILES["file"]["error"] . "<br />";
  }
  else
  {
  echo "Upload: " . $_FILES["file"]["name"] . "<br />";
  echo "Type: " . $_FILES["file"]["type"] . "<br />";
  echo "Size: " . ($_FILES["file"]["size"] / 1024) . " Kb<br />";
  echo "Stored in: " . $_FILES["file"]["tmp_name"];

  Import($_FILES["file"]["tmp_name"],1,1,$_FILES["file"]["name"]);
  }
  }
?>


