<?php
session_start();
include 'connection.php';

if (!isset($_SESSION["unilogin"])) {
    die("Du skal være logget ind!");
}

$unilogin = $_SESSION["unilogin"];
$level = $_SESSION["level"];
$klasse_id = null;
$afleveringer = [];

// Hent brugerens klasse_id
$stmt = $conn->prepare("SELECT Klasse_id FROM Bruger WHERE Unilogin = ?");
$stmt->bind_param("s", $unilogin);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $klasse_id = $row["Klasse_id"];
} else {
    die("Brugerens klasse blev ikke fundet.");
}

if ($level == 1) {
    $stmt = $conn->prepare(
        "SELECT * FROM Oprettet_Aflevering WHERE Klasse_id = ?"
    );
} else {
    $stmt = $conn->prepare(
        "SELECT * FROM Oprettet_Aflevering WHERE Klasse_id = ?"
    );
}

$stmt->bind_param("i", $klasse_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $afleveringer[] = $row;
}
?>

<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <title>Mine Afleveringer</title>
</head>
<body>
    <h1>Mine Afleveringer</h1>

    <?php if (!empty($afleveringer)) { ?>
        <ul>
            <?php foreach ($afleveringer as $afl) { ?>
                <li>
                    <strong><?= htmlspecialchars($afl["Oprettet_Afl_navn"]) ?></strong> - 
                    (Deadline: <?= htmlspecialchars($afl["Oprettet_Afl_deadline"]) ?>)
                    <a href="Afleveringer.php?id=<?= intval($afl["Oprettet_Afl_id"]) ?>">Se detaljer</a>
                </li>
            <?php } ?>
        </ul>
    <?php } else { ?>
        <p>Ingen afleveringer fundet.</p>
    <?php } ?>
</body>
</html>
