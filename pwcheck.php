<<<<<<< HEAD
<?php
require __DIR__.'/db.php';
$u = 'member1'; // change
$p = 'Test@123'; // change

$stmt = $conn->prepare("SELECT password FROM users WHERE username=? OR email=? LIMIT 1");
$stmt->bind_param('ss',$u,$u);
$stmt->execute(); $stmt->bind_result($hash);
if ($stmt->fetch()) {
  var_dump(password_verify($p, $hash)); // true = matches
} else {
  echo "User not found";
=======
<?php
require __DIR__.'/db.php';
$u = 'member1'; // change
$p = 'Test@123'; // change

$stmt = $conn->prepare("SELECT password FROM users WHERE username=? OR email=? LIMIT 1");
$stmt->bind_param('ss',$u,$u);
$stmt->execute(); $stmt->bind_result($hash);
if ($stmt->fetch()) {
  var_dump(password_verify($p, $hash)); // true = matches
} else {
  echo "User not found";
>>>>>>> b78dc527f4ca1b402224214aa4f78775c370647f
}