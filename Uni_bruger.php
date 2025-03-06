<?php
session_start();
include 'connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $unilogin = $_POST['unilogin'] ?? '';

    if (!empty($unilogin)) {
        $stmt = $conn->prepare("SELECT Unilogin FROM Bruger WHERE Unilogin = ?");
        $stmt->bind_param("s", $unilogin);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $_SESSION['unilogin'] = $unilogin;
            header("Location: Uni_kode.php");
            exit();
        } else {
            $error = "Ugyldigt Unilogin!";
        }
    } else {
        $error = "Indtast dit Unilogin!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Indtast Unilogin navn</title>
</head>
<body>
    <h2>Indtast dit Unilogin</h2>
    <form method="POST">
        <input type="text" name="unilogin" placeholder="Unilogin" required>
        <button type="submit">Næste</button>
    </form>
    <?php if (isset($error)) echo "<p style='color: red;'>$error</p>"; ?>
</body>
</html>
