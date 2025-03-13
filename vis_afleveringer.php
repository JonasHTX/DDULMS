<?php
session_start();
include 'connection.php';

if (!isset($_SESSION["unilogin"])) {
    die("Du skal være logget ind!");
}

$unilogin = $_SESSION["unilogin"];
$level = $_SESSION["level"];

$bruger_fag_klasser = [];

if ($level == 1) {
    $stmt = $conn->prepare("SELECT DISTINCT Klasse_id, Fag_id FROM Laerer_info WHERE Laerer_Unilogin = ?");
} else {
    $stmt = $conn->prepare("SELECT DISTINCT Klasse_id FROM Bruger WHERE Unilogin = ?");
}

if (!$stmt) {
    die("Fejl i forberedelse af forespørgsel: " . $conn->error);
}

$stmt->bind_param("s", $unilogin);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    if ($level == 1) {
        $bruger_fag_klasser[] = ["Klasse_id" => $row["Klasse_id"], "Fag_id" => $row["Fag_id"]];
    } else {
        $bruger_fag_klasser[] = ["Klasse_id" => $row["Klasse_id"], "Fag_id" => null];
    }
}

$afleveringer = [];

if (!empty($bruger_fag_klasser)) {
    $where_clauses = [];
    $params = [];
    $types = "";

    foreach ($bruger_fag_klasser as $row) {
        if ($row["Fag_id"] !== null) {
            $where_clauses[] = "(Oprettet_Aflevering.Klasse_id = ? AND Oprettet_Aflevering.Fag_id = ?)";
            $params[] = $row["Klasse_id"];
            $params[] = $row["Fag_id"];
            $types .= "ii";
        } else {
            $where_clauses[] = "(Oprettet_Aflevering.Klasse_id = ?)";
            $params[] = $row["Klasse_id"];
            $types .= "i";
        }
    }

    $query = "
        SELECT 
            Oprettet_Aflevering.Oprettet_Afl_id,
            Oprettet_Aflevering.Oprettet_Afl_navn, 
            Oprettet_Aflevering.Oprettet_Afl_deadline,
            Klasse.Klasse_navn,
            Fag.Fag_navn
        FROM Oprettet_Aflevering
        JOIN Klasse ON Oprettet_Aflevering.Klasse_id = Klasse.Klasse_id
        JOIN Fag ON Oprettet_Aflevering.Fag_id = Fag.Fag_id
        WHERE " . implode(" OR ", $where_clauses);

    $stmt = $conn->prepare($query);

    if (!$stmt) {
        die("Fejl i SQL-forespørgsel: " . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $afleveringer[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mine Afleveringer</title>
</head>
<body>
    <h1>Mine Afleveringer</h1>

    <?php if (!empty($afleveringer)) { ?>
        <ul>
            <?php foreach ($afleveringer as $afl) { ?>
                <li>
                    <strong><?= htmlspecialchars($afl["Oprettet_Afl_navn"]) ?></strong> - 
                    Klasse: <?= htmlspecialchars($afl["Klasse_navn"]) ?>, 
                    Fag: <?= htmlspecialchars($afl["Fag_navn"]) ?> 
                    (Deadline: <?= htmlspecialchars($afl["Oprettet_Afl_deadline"]) ?>)
                    <a href="Afleveringer.php?id=<?= intval($afl["Oprettet_Afl_id"]) ?>">Se detaljer</a>
                </li>
            <?php } ?>
        </ul>
    <?php } else { ?>
        <p>Ingen afleveringer fundet.</p>
    <?php } ?>

    <br>
    <a href="index.php">Tilbage</a>
</body>
</html>
