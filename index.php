<?php
session_start();
include 'connection.php';

// Tjekker om brugeren er logget ind
if (!isset($_SESSION['signed_in']) || $_SESSION['signed_in'] !== true) {
    header("Location: Uni_bruger.php");
    exit();
}

// Hent brugerens level baseret på Unilogin
$unilogin = $_SESSION['user_name']; // Brug Unilogin, som er gemt i sessionen
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
</head>
<body>
    <h1>Du er logget ind som <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h1>

    <!-- Kun vis knappen hvis level = 2 -->
    <?php if ($user_level == 2): ?>
        <a href="admin.php">
            <button>Gå til Admin Panel</button>
        </a>
    <?php endif; ?>

    <a href="Opretaflevering.php">
        <button>Opret aflevering</button>
    </a>
    <a href="vis_afleveringer.php">
        <button>Vis afleveringer</button>
    </a>

    <a href="Evaluering.php">
        <button>Evaluering</button>
    </a>

    <a href="logout.php">
        <button>Log ud</button>
    </a>
</body>
</html>
