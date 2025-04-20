<?php
ob_start();
session_start();
include 'connection.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $unilogin = $_POST['unilogin'];

    if (empty($unilogin)) {
        echo "Unilogin skal udfyldes!";
    } else {
        // Forbered SQL-foresp�rgsel
        $sql = "SELECT * FROM Bruger WHERE Unilogin = ?";
        $stmt = $conn->prepare($sql);

        // Tjek om prepare lykkedes
        if (!$stmt) {
            die("SQL fejl: " . $conn->error);
        }

        $stmt->bind_param("s", $unilogin);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $_SESSION['unilogin'] = $unilogin;
            header("Location: Uni_kode.php");
            exit();
        } else {
            echo "Unilogin ikke fundet!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="da">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniLogin</title>
    <link rel="stylesheet" href="Uni_bruger.css">
</head>

<body>
    <div class="login-container">
        <h2>Uni Login</h2>
        <form method="post" class="login-form">
            <input type="text" name="unilogin" placeholder="Indtast UniLogin" required>
            <button type="submit">Næste</button>
        </form>
    </div>
</body>

</html>