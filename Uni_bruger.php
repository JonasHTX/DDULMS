<?php
session_start();
include 'connection.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $unilogin = $_POST['unilogin'];

    if (empty($unilogin)) {
        echo "Unilogin skal udfyldes!";
    } else {
        // Forbered SQL-forespørgsel
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
<html>
<head>
    <title>UniLogin</title>
</head>
<body>
    <form method="post">
        <input type="text" name="unilogin" placeholder="Indtast UniLogin" required>
        <button type="submit">Næste</button>
    </form>
</body>
</html>
