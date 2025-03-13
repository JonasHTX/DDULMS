<?php
session_start();
include 'connection.php';

if (!isset($_SESSION["unilogin"])) {
    die("Du skal være logget ind!");
}

$unilogin = $_SESSION["unilogin"];

// Hent klasser og tilhørende fag for læreren
$stmt = $conn->prepare("
    SELECT Klasse.Klasse_id, Klasse.Klasse_navn, Fag.Fag_id, Fag.Fag_navn
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
    $klasser[$row['Klasse_id']] = $row['Klasse_navn'];

    // Organiser fagene efter klasse
    $fag[$row['Klasse_id']][] = [
        'id' => $row['Fag_id'],
        'navn' => $row['Fag_navn']
    ];
}

// Håndter oprettelse af aflevering
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["opret_aflevering"])) {
    $afl_navn = $_POST["afl_navn"];
    $klasse_id = $_POST["klasse_id"];
    $fag_id = $_POST["fag_id"];
    $deadline = $_POST["afl_deadline"];

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
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            let fagData = <?= json_encode($fag) ?>;
            let klasseSelect = document.getElementById("klasse_select");
            let fagSelect = document.getElementById("fag_select");

            klasseSelect.addEventListener("change", function () {
                let selectedKlasse = this.value;
                fagSelect.innerHTML = '<option value="">Vælg fag</option>';

                if (fagData[selectedKlasse]) {
                    fagData[selectedKlasse].forEach(fag => {
                        let option = document.createElement("option");
                        option.value = fag.id;
                        option.textContent = fag.navn;
                        fagSelect.appendChild(option);
                    });
                }
            });

            // Sæt den første mulighed ved load, hvis en klasse allerede er valgt
            if (klasseSelect.value) {
                klasseSelect.dispatchEvent(new Event("change"));
            }
        });
    </script>
</head>
<body>
    <h1>Opret Aflevering</h1>

    <form method="POST">
        <input type="text" name="afl_navn" placeholder="Afleveringsnavn" required>

        <label>Vælg Klasse:</label>
        <select name="klasse_id" id="klasse_select" required>
            <option value="">Vælg klasse</option>
            <?php foreach ($klasser as $id => $navn) { ?>
                <option value="<?= $id ?>"><?= $navn ?></option>
            <?php } ?>
        </select>

        <label>Vælg Fag:</label>
        <select name="fag_id" id="fag_select" required>
            <option value="">Vælg fag</option>
        </select>

        <label>Deadline:</label>
        <input type="datetime-local" name="afl_deadline" required>

        <button type="submit" name="opret_aflevering">Opret Aflevering</button>
    </form>

    <br>
    <a href="index.php">Tilbage</a>
</body>
</html>
