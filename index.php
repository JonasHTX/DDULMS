<?php
session_start();
include 'connection.php';
?>
<?php
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
    <a href="logout.php">Log ud</a>
</body>
</html>
