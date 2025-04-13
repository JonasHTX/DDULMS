<?php
session_start();
include 'connection.php';
include 'header.php';

// Tjekker om brugeren er logget ind
if (!isset($_SESSION['signed_in']) || $_SESSION['signed_in'] !== true) {
    header("Location: Uni_bruger.php");
    exit();
}


// Hent brugerens level baseret p� Unilogin
$unilogin = $_SESSION['unilogin']; // Brug korrekt session-variabel
$query = "SELECT Level FROM Bruger WHERE Unilogin = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $unilogin);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$user_level = $user['Level'];

?>
<!DOCTYPE html>
<html>

<head>
    <title>Velkommen</title>
    <link rel="stylesheet" href="index.css">
</head>

<body>
    <h1>Du er logget ind som <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h1>

    <!-- Kun vis Admin Panel knappen hvis level = 2 -->
    <?php if ($user_level == 2): ?>
        <a href="admin.php">
            <button>Gå til Admin Panel</button>
        </a>
    <?php endif; ?>

    <!-- Kun vis Opret aflevering knappen hvis level = 1 (l�rer) eller 2 (admin) -->
    <?php if ($user_level == 1 || $user_level == 2): ?>
        <a href="Opretaflevering.php">
            <button>Opret aflevering</button>
        </a>
    <?php endif; ?>

    <?php include 'vis_afleveringer.php'; ?>

    <?php if ($user_level == 0):
        include 'Evaluering.php';
    endif; ?>



    <a href="logout.php">
        <button>Log ud</button>
    </a>
</body>

</html>