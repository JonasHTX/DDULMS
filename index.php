<?php
session_start();
include 'connection.php';

if (!isset($_SESSION['signed_in']) || $_SESSION['signed_in'] !== true) {
    header("Location: Uni_bruger.php");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Velkommen</title>
</head>
<body>
    <h1>Du er logget ind som <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h1>
    
    <a href="admin.php">
        <button>Gå til Admin Panel</button>
    </a>

    <a href="logout.php">
        <button>Log ud</button>
    </a>
