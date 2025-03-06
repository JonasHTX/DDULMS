<?php
session_start();
include 'connection.php';

if (!isset($_SESSION['unilogin'])) {
    header("Location: Uni_bruger.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $password = $_POST['password'] ?? '';

    if (!empty($password)) {
        $stmt = $conn->prepare("SELECT Unilogin, Navn, Level FROM Bruger WHERE Unilogin = ? AND Password = ?");
        $hashedPassword = sha1($password);
        $stmt->bind_param("ss", $_SESSION['unilogin'], $hashedPassword);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $_SESSION['signed_in'] = true;
            $_SESSION['user_name'] = $user['Navn'];
            $_SESSION['user_level'] = $user['Level'];
            header("Location: index.php");
            exit();
        } else {
            $error = "Forkert kodeord!";
        }
    } else {
        $error = "Indtast din kode!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Indtast kode</title>
</head>
<body>
    <h2>Indtast din kode</h2>
    <form method="POST">
        <input type="password" name="password" placeholder="Kodeord" required>
        <button type="submit">Log ind</button>
    </form>
    <?php if (isset($error)) echo "<p style='color: red;'>$error</p>"; ?>
</body>
</html>
