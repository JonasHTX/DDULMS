<?php
session_start();
include 'connection.php';

if (!isset($_SESSION["unilogin"])) {
    die("Du skal være logget ind!");
}

$unilogin = $_SESSION["unilogin"];
$klasse_id = null;
$afleveringer = [];
$afleveringer_per_fag = [];
$afleverede_opgaver = [];

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
$stmt->close();

// Hent opgaver som eleven allerede har afleveret
$stmt = $conn->prepare("SELECT Oprettet_Afl_id FROM Elev_Aflevering WHERE Unilogin = ?");
$stmt->bind_param("s", $unilogin);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $afleverede_opgaver[] = $row["Oprettet_Afl_id"];
}
$stmt->close();

// Hent alle afleveringer for klassen, som eleven ikke allerede har afleveret
$stmt = $conn->prepare(
    "SELECT Oprettet_Aflevering.*, Fag.Fag_navn 
     FROM Oprettet_Aflevering 
     JOIN Fag ON Oprettet_Aflevering.Fag_id = Fag.Fag_id
     WHERE Oprettet_Aflevering.Klasse_id = ?"
);
$stmt->bind_param("i", $klasse_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    if (!in_array($row["Oprettet_Afl_id"], $afleverede_opgaver)) { // Filtrer afleverede opgaver væk
        $afleveringer[] = $row;
        $afleveringer_per_fag[$row["Fag_navn"]][] = $row;
    }
}
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <title>Mine Afleveringer</title>
</head>
<body>
    <h1>Afleveringer, du endnu ikke har afleveret</h1>

    <?php if (!empty($afleveringer)) { ?>
        <ul>
            <?php foreach ($afleveringer as $afl) { ?>
                <li>
                    <strong><?= htmlspecialchars($afl["Oprettet_Afl_navn"]) ?></strong> - 
                    (Fag: <?= htmlspecialchars($afl["Fag_navn"]) ?>, Deadline: <?= htmlspecialchars($afl["Oprettet_Afl_deadline"]) ?>)
                    <a href="Afleveringer.php?id=<?= intval($afl["Oprettet_Afl_id"]) ?>">Se detaljer</a>
                </li>
            <?php } ?>
        </ul>
    <?php } else { ?>
        <p>Du har afleveret alle opgaver.</p>
    <?php } ?>

    <h1>Afleveringer sorteret efter fag</h1>
    
    <?php if (!empty($afleveringer_per_fag)) { ?>
        <?php foreach ($afleveringer_per_fag as $fag_navn => $afleveringer) { ?>
            <h2><?= htmlspecialchars($fag_navn) ?></h2>
            <ul>
                <?php foreach ($afleveringer as $afl) { ?>
                    <li>
                        <strong><?= htmlspecialchars($afl["Oprettet_Afl_navn"]) ?></strong> - 
                        (Deadline: <?= htmlspecialchars($afl["Oprettet_Afl_deadline"]) ?>)
                        <a href="Afleveringer.php?id=<?= intval($afl["Oprettet_Afl_id"]) ?>">Se detaljer</a>
                    </li>
                <?php } ?>
            </ul>
        <?php } ?>
    <?php } else { ?>
        <p>Ingen afleveringer fundet.</p>
    <?php } ?>

    <a href="index.php">Tilbage</a>
</body>
</html>
