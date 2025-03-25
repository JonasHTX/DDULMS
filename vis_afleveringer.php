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
$is_teacher = false;

// Hent brugerens info (klasse_id og level)
$stmt = $conn->prepare("SELECT Klasse_id, Level FROM Bruger WHERE Unilogin = ?");
$stmt->bind_param("s", $unilogin);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $klasse_id = $row["Klasse_id"];
    $is_teacher = ($row["Level"] == 1); // Hvis Level er 1, er brugeren lærer
} else {
    die("Bruger ikke fundet.");
}
$stmt->close();

if ($is_teacher) {
    // HENT AFLEVERINGER SOM LÆRER HAR OPRETTET
    $stmt = $conn->prepare(
        "SELECT Oprettet_Aflevering.*, Fag.Fag_navn 
         FROM Oprettet_Aflevering 
         JOIN Fag ON Oprettet_Aflevering.Fag_id = Fag.Fag_id
         WHERE EXISTS (
             SELECT 1 FROM Laerer_info 
             WHERE Laerer_info.Laerer_Unilogin = ? 
             AND Laerer_info.Klasse_id = Oprettet_Aflevering.Klasse_id
             AND Laerer_info.Fag_id = Oprettet_Aflevering.Fag_id
         )
         ORDER BY Oprettet_Aflevering.Oprettet_Afl_deadline DESC"
    );
    $stmt->bind_param("s", $unilogin);
} else {
    // HENT AFLEVERINGER FOR ELEV (som før)
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
         WHERE Oprettet_Aflevering.Klasse_id = ?
         ORDER BY Oprettet_Aflevering.Oprettet_Afl_deadline DESC"
    );
    $stmt->bind_param("i", $klasse_id);
}

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // For elever: Filtrer afleverede opgaver væk
    if (!$is_teacher && in_array($row["Oprettet_Afl_id"], $afleverede_opgaver)) {
        continue;
    }
    
    $afleveringer[] = $row;
    $afleveringer_per_fag[$row["Fag_navn"]][] = $row;
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
    <?php if ($is_teacher) { ?>
        <h1>Afleveringer du har oprettet</h1>
    <?php } else { ?>
        <h1>Afleveringer, du endnu ikke har afleveret</h1>
    <?php } ?>

    <?php if (!empty($afleveringer)) { ?>
        <ul>
            <?php foreach ($afleveringer as $afl) { ?>
                <li>
                    <strong><?= htmlspecialchars($afl["Oprettet_Afl_navn"]) ?></strong> - 
                    (Fag: <?= htmlspecialchars($afl["Fag_navn"]) ?>, 
                    Klasse: <?= htmlspecialchars($afl["Klasse_id"]) ?>, 
                    Deadline: <?= htmlspecialchars($afl["Oprettet_Afl_deadline"]) ?>)
                    <?php if ($is_teacher) { ?>
                        <a href="Evaluering.php?id=<?= intval($afl["Oprettet_Afl_id"]) ?>">Se afleveret</a>
                    <?php } else { ?>
                        <a href="Afleveringer.php?id=<?= intval($afl["Oprettet_Afl_id"]) ?>">Se detaljer</a>
                    <?php } ?>
                </li>
            <?php } ?>
        </ul>
    <?php } else { ?>
        <p><?= $is_teacher ? 'Du har ikke oprettet nogen afleveringer endnu.' : 'Du har afleveret alle opgaver.' ?></p>
    <?php } ?>

    <?php if (!$is_teacher) { ?>
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
    <?php } ?>

    <a href="index.php">Tilbage</a>
</body>
</html>