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
            $_SESSION['unilogin'] = $user['Unilogin'];
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
<html lang="da">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Indtast kode</title>
    <link rel="stylesheet" href="Uni_bruger.css">
</head>

<body>
    <div class="login-container">
        <h2>Indtast din kode</h2>
        <form method="POST" class="login-form">
            <input type="password" name="password" placeholder="Kodeord" required>
            <button type="submit">Log ind</button>
        </form>
        <?php if (isset($error)) echo "<p style='color: red; margin-top: 1rem;'>$error</p>"; ?>
    </div>
</body>

</html>