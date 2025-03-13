<?php
session_start();
include 'connection.php';

if (!isset($_SESSION["unilogin"])) {
    die("Du skal være logget ind!");
}

$unilogin = $_SESSION["unilogin"];

// Hent unikke klasser og fag for læreren
$stmt = $conn->prepare("
    SELECT DISTINCT Klasse.Klasse_id, Klasse.Klasse_navn, Fag.Fag_id, Fag.Fag_navn
    FROM Laerer_info
    JOIN Klasse ON Laerer_info.Klasse_id = Klasse.Klasse_id
    JOIN Fag ON Laerer_info.Fag_id = Fag.Fag_id
    WHERE Laerer_info.Laerer_Unilogin = ?
");
$stmt->bind_param("s", $unilogin);
$stmt->execute();
$result = $stmt->get_result();

$klasser = [];
$fag = [];

while ($row = $result->fetch_assoc()) {
    $klasser[$row['Klasse_id']] = $row['Klasse_navn']; // Fjern gentagelser af klasser
    $fag[$row['Fag_id']] = $row['Fag_navn']; // Fjern gentagelser af fag
}

// Håndter oprettelse af aflevering
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["opret_aflevering"])) {
    $afl_navn = $_POST["afl_navn"];
    $klasse_id = $_POST["klasse_id"];
    $fag_id = $_POST["fag_id"];
    $deadline = $_POST["afl_deadline"];

    // Indsæt i databasen
    $stmt = $conn->prepare("INSERT INTO Oprettet_Aflevering (Oprettet_Afl_navn, Klasse_id, Fag_id, Oprettet_Afl_deadline) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("siis", $afl_navn, $klasse_id, $fag_id, $deadline);

    if ($stmt->execute()) {
        echo "Aflevering oprettet!";
    } else {
        echo "Fejl: " . $stmt->error;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Opret Aflevering</title>
</head>
<body>
    <h1>Opret Aflevering</h1>
    
    <form method="POST">
        <input type="text" name="afl_navn" placeholder="Afleveringsnavn" required>

        <label>Vælg Klasse:</label>
        <select name="klasse_id" required>
            <option value="">Vælg klasse</option>
            <?php foreach ($klasser as $id => $navn) { ?>
                <option value="<?= $id ?>"><?= $navn ?></option>
            <?php } ?>
        </select>

        <label>Vælg Fag:</label>
        <select name="fag_id" required>
            <option value="">Vælg fag</option>
            <?php foreach ($fag as $id => $navn) { ?>
                <option value="<?= $id ?>"><?= $navn ?></option>
            <?php } ?>
        </select>

        <label>Deadline:</label>
        <input type="datetime-local" name="afl_deadline" required>

        <button type="submit" name="opret_aflevering">Opret Aflevering</button>
    </form>

    <br>
    <a href="admin.php">Tilbage</a>
</body>
</html>
