<?php
ob_start();
session_start();
include 'connection.php';
include 'header.php';

// H�ndter sletning af bruger
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["slet_bruger"])) {
    $unilogin = $_POST["unilogin"];
    
    // Slet f�rst fra Laerer_info hvis det er en l�rer
    $conn->query("DELETE FROM Laerer_info WHERE Laerer_Unilogin = '$unilogin'");
    
    // Slet fra Bruger tabellen
    if ($conn->query("DELETE FROM Bruger WHERE Unilogin = '$unilogin'")) {
        $success_msg = "Bruger slettet succesfuldt!";
    } else {
        $error_msg = "Fejl ved sletning: " . $conn->error;
    }
}

// �rets afslutning funktion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["aret_omme"])) {
    // Start transaction for at sikre data integritet
    $conn->begin_transaction();
    
    try {
        // 1. Slet alle elevafleveringer og tilh�rende evalueringer
        $conn->query("DELETE FROM Evaluering");
        $conn->query("DELETE FROM Elev_Aflevering");
        
        // 2. Slet alle oprettede afleveringer
        $conn->query("DELETE FROM Oprettet_Aflevering");
        
        // 3. Slet elever der g�r ud af skolen (Klasse_id 3, 6, 9)
        $conn->query("DELETE FROM Bruger WHERE Level = 0 AND Klasse_id IN (3, 6, 9)");
        
        // 4. Opdater de alle andre elever
        $conn->query("UPDATE Bruger SET Klasse_id = Klasse_id + 1 WHERE Level = 0 AND Klasse_id NOT IN (3, 6, 9)");
        
        // Commit �ndringerne
        $conn->commit();
        
        $success_msg = "Klasser opdateret! Alle afleveringer er blevet slettet og elever i sidste �rgang er blevet fjernet.";
    } catch (Exception $e) {
        // Rollback ved fejl
        $conn->rollback();
        $error_msg = "Fejl under �rsskifte: " . $e->getMessage();
    }
}

// Hent brugere
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["hent_brugere"])) {
    $brugere = $conn->query("
        SELECT b.Unilogin, b.Navn, b.Level, b.Klasse_id, 
               GROUP_CONCAT(DISTINCT CONCAT('Klasse ', li.Klasse_id, ': ', f.Fag_navn) SEPARATOR ', ') AS laerer_info
        FROM Bruger b
        LEFT JOIN Laerer_info li ON b.Unilogin = li.Laerer_Unilogin
        LEFT JOIN Fag f ON li.Fag_id = f.Fag_id
        GROUP BY b.Unilogin
        ORDER BY b.Level DESC, b.Navn
    ")->fetch_all(MYSQLI_ASSOC);
}

// Opret bruger
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["opret_bruger"])) {
    $unilogin = $conn->real_escape_string($_POST["bruger_unilogin"]);
    $password = sha1($_POST["bruger_password"]);
    $navn = $conn->real_escape_string($_POST["bruger_navn"]);
    $level = (int)$_POST["bruger_level"];
    $klasse_id = ($level === 0) ? (int)$_POST["Klasse_id"] : null;

    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Opret bruger
        $insert_user = $conn->query("INSERT INTO Bruger (Unilogin, Password, Navn, Klasse_id, Level) 
                   VALUES ('$unilogin', '$password', '$navn', " . ($klasse_id !== null ? $klasse_id : 'NULL') . ", $level)");
        
        if (!$insert_user) {
            throw new Exception("Fejl ved oprettelse af bruger: " . $conn->error);
        }

        // Tilf�j l�rerinfo hvis det er en l�rer
        if ($level === 1 && !empty($_POST["laerer_klasse"])) {
            foreach ($_POST["laerer_klasse"] as $index => $klasse) {
                if (!empty($_POST["laerer_fag"][$index])) {
                    foreach ($_POST["laerer_fag"][$index] as $fag_id) {
                        $insert_teacher = $conn->query("INSERT INTO Laerer_info (Laerer_Unilogin, Klasse_id, Fag_id) 
                                    VALUES ('$unilogin', $klasse, $fag_id)");
                        
                        if (!$insert_teacher) {
                            throw new Exception("Fejl ved tilf�jelse af l�rerinfo: " . $conn->error);
                        }
                    }
                }
            }
        }
        
        $conn->commit();
        $success_msg = "Bruger oprettet succesfuldt!";
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Panel</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .message { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .success { background-color: #d4edda; color: #155724; }
        .error { background-color: #f8d7da; color: #721c24; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f2f2f2; }
        .btn { padding: 5px 10px; text-decoration: none; border-radius: 3px; }
        .btn-danger { background-color: #dc3545; color: white; }
        .warning-box { 
            background-color: #fff3cd; 
            color: #856404; 
            padding: 15px; 
            margin: 20px 0; 
            border-left: 5px solid #ffeeba;
        }
    </style>
    <script>
        function toggleFields() {
            var level = document.getElementById("bruger_level").value;
            document.getElementById("elev_fields").style.display = (level == "0") ? "block" : "none";
            document.getElementById("laerer_fields").style.display = (level == "1") ? "block" : "none";
        }

        function addClassSubjectPair() {
            let container = document.getElementById("class_subjects_container");
            let newPair = document.createElement("div");
            newPair.classList.add("class_subject_pair");
            newPair.innerHTML = `
                <label>V�lg Klasse:</label>
                <select name="laerer_klasse[]">
                    <option value="1">1.A</option>
                    <option value="2">2.A</option>
                    <option value="3">3.A</option>
                    <option value="4">1.B</option>
                    <option value="5">2.B</option>
                    <option value="6">3.B</option>
                    <option value="7">1.C</option>
                    <option value="8">2.C</option>
                    <option value="9">3.C</option>
                </select>

                <label>V�lg Fag:</label>
                <select name="laerer_fag[][]" multiple>
                    <option value="1">Dansk</option>
                    <option value="2">Engelsk</option>
                    <option value="3">Matematik</option>
                    <option value="4">Samfundsfag</option>
                    <option value="5">Historie</option>
                    <option value="6">Kemi</option>
                    <option value="7">Fysik</option>
                    <option value="8">Idr�t</option>
                    <option value="9">Biologi</option>
                    <option value="10">Musik</option>
                </select>

                <button type="button" onclick="this.parentElement.remove()">Fjern</button>
            `;
            container.appendChild(newPair);
        }

        function confirmDelete(unilogin, navn) {
            return confirm('Er du sikker p� at du vil slette ' + navn + ' (Unilogin: ' + unilogin + ')?');
        }
        
        function confirmYearEnd() {
            return confirm('ADVARSEL: Dette vil slette ALLE afleveringer og evalueringer, samt opdatere elevernes klasser. Er du sikker p� at du vil forts�tte?');
        }
    </script>
</head>
<body onload="toggleFields()">
    <h1>Admin Panel</h1>
    
    <?php if (!empty($success_msg)): ?>
        <div class="message success"><?= $success_msg ?></div>
    <?php endif; ?>
    
    <?php if (!empty($error_msg)): ?>
        <div class="message error"><?= $error_msg ?></div>
    <?php endif; ?>
    
    <h2>Opret Bruger</h2>
    <form method="POST">
        <input type="text" name="bruger_unilogin" placeholder="Unilogin" required>
        <input type="password" name="bruger_password" placeholder="Kodeord" required>
        <input type="text" name="bruger_navn" placeholder="Navn" required>
        <label>Level:</label>
        <select name="bruger_level" id="bruger_level" onchange="toggleFields()" required>
            <option value="0">Elev</option>
            <option value="1">L�rer</option>
            <option value="2">Administrator</option>
        </select>
        
        <div id="elev_fields" style="display: none;">
            <h3>Elev Information</h3>
            <select name="Klasse_id" required>
                <option value="1">1.A</option>
                <option value="2">2.A</option>
                <option value="3">3.A</option>
                <option value="4">1.B</option>
                <option value="5">2.B</option>
                <option value="6">3.B</option>
                <option value="7">1.C</option>
                <option value="8">2.C</option>
                <option value="9">3.C</option>
            </select> 
        </div>

        <div id="laerer_fields" style="display: none;">
            <h3>L�rer Information</h3>
            <div id="class_subjects_container"></div>
            <button type="button" onclick="addClassSubjectPair()">Tilf�j flere</button>
        </div>

        <button type="submit" name="opret_bruger">Opret Bruger</button>
    </form>

    <h2>�ret er omme</h2>
    <div class="warning-box">
        <strong>ADVARSEL:</strong> Denne handling kan ikke fortrydes! Den vil:
        <ul>
            <li>Slette alle elever i 3.A, 3.B og 3.C</li>
            <li>Opdatere alle andre elevers klassetrin (1.A -> 2.A, 2.A -> 3.A osv.)</li>
            <li>Slette ALLE afleveringer, evalueringer og oprettede afleveringer</li>
        </ul>
    </div>
    <form method="POST" onsubmit="return confirmYearEnd()">
        <button type="submit" name="aret_omme">Udf�r �rsskifte</button>
    </form>

    <h2>Brugere</h2>
    <form method="POST">
        <button type="submit" name="hent_brugere">Vis Brugere</button>
    </form>

    <?php if (!empty($brugere)): ?>
        <h3>Brugerliste</h3>
        <table>
            <thead>
                <tr>
                    <th>Navn</th>
                    <th>Unilogin</th>
                    <th>Rolle</th>
                    <th>Tilknytning</th>
                    <th>Handling</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($brugere as $bruger): ?>
                    <tr>
                        <td><?= htmlspecialchars($bruger['Navn']) ?></td>
                        <td><?= htmlspecialchars($bruger['Unilogin']) ?></td>
                        <td>
                            <?php 
                            switch($bruger['Level']) {
                                case 0: echo 'Elev'; break;
                                case 1: echo 'L�rer'; break;
                                case 2: echo 'Admin'; break;
                                default: echo 'Ukendt';
                            }
                            ?>
                        </td>
                        <td>
                            <?= $bruger['Level'] == 0 
                                ? 'Klasse: ' . $bruger['Klasse_id'] 
                                : ($bruger['laerer_info'] ?? 'Ingen tilknytning') ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;" 
                                  onsubmit="return confirmDelete('<?= $bruger['Unilogin'] ?>', '<?= $bruger['Navn'] ?>')">
                                <input type="hidden" name="unilogin" value="<?= $bruger['Unilogin'] ?>">
                                <button type="submit" name="slet_bruger" class="btn btn-danger">Slet</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <a href="index.php">
        <button>G� tilbage</button>
    </a>
</body>
</html>