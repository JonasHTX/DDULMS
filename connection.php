<?php
$servername = "mysql49.unoeuro.com";
$username = "ddujonas_dk";
$password = "xz3c94Enek26HmydtaRg";
$dbname = "ddujonas_dk_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
  die("Forbindelse fejlede: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
