<?php
ob_start();
session_start();
include 'connection.php';

// Opdater klasse ID'er ved årets afslutning
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["aret_omme"])) {
    // Slet elever der går ud af skolen (Klasse_id 3, 6, 9)
    $stmt_delete = $conn->prepare("DELETE FROM Bruger WHERE Level = 0 AND Klasse_id IN (3, 6, 9)");
    $stmt_delete->execute();

    // Opdater de resterende elever til næste klassetrin
    $stmt_update = $conn->prepare("UPDATE Bruger SET Klasse_id = Klasse_id + 1 WHERE Level = 0 AND Klasse_id NOT IN (3, 6, 9)");
    $stmt_update->execute();

    echo "Klasser opdateret! Elever i sidste årgang er blevet slettet.";
}
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["hent_brugere"])) {
    // Forbered SQL-forespørgsel for at hente brugerdata og tilhørende lærerinfo
    $stmt = $conn->prepare("
        SELECT Bruger.Unilogin AS Bruger_Unilogin, Bruger.Navn AS Bruger_Navn, Bruger.Level, Bruger.Klasse_id, 
               Laerer_info.Klasse_id AS Laerer_Klasse_id, Laerer_info.Fag_id, Laerer_info.Laerer_Unilogin
        FROM Bruger
        LEFT JOIN Laerer_info ON Bruger.Unilogin = Laerer_info.Laerer_Unilogin
        ORDER BY Bruger.Navn, Laerer_info.Klasse_id, Laerer_info.Fag_id
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "<h2>Brugerliste</h2><ul>";
    
    // Array til at gemme lærere og deres kombinationer
    $laerere = [];
    
    // Hent alle brugere
    while ($row = $result->fetch_assoc()) {
        $unilogin = htmlspecialchars($row['Bruger_Unilogin']);
        $navn = htmlspecialchars($row['Bruger_Navn']);
        
        // Hvis bruger er elev
        if ($row['Level'] == 0) {
            $klasse = isset($row['Klasse_id']) ? $row['Klasse_id'] : "Ingen klasse";
            echo "<li>" . $navn . " (Elev, Unilogin: " . $unilogin . ", Klasse: " . $klasse . ")</li>";
        } 
        // Hvis bruger er lærer
        else {
            $laerer_unilogin = isset($row['Laerer_Unilogin']) ? $row['Laerer_Unilogin'] : "Ingen Unilogin";
            $klasse = isset($row['Laerer_Klasse_id']) ? $row['Laerer_Klasse_id'] : "Ingen klasse";
            $fag = isset($row['Fag_id']) ? $row['Fag_id'] : "Ingen fag";
            
            // Gem kombinationer for læreren
            if (!isset($laerere[$unilogin])) {
                $laerere[$unilogin] = [
                    'navn' => $navn,
                    'unilogin' => $laerer_unilogin,
                    'kombinationer' => []
                ];
            }
            
            if ($klasse !== "Ingen klasse" && $fag !== "Ingen fag") {
                $laerere[$unilogin]['kombinationer'][] = "(Klasse: $klasse Fag: $fag)";
            }
        }
    }
    
    // Udskriv lærerne med alle deres kombinationer
    foreach ($laerere as $laerer) {
        $kombinationer = !empty($laerer['kombinationer']) ? 
                         implode(", ", $laerer['kombinationer']) : 
                         "Ingen tilknyttede klasser/fag";
        
        echo "<li>" . $laerer['navn'] . " (Lærer, Unilogin: " . $laerer['unilogin'] . ", " . $kombinationer . ")</li>";
    }
    
    echo "</ul>";
}




if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["opret_bruger"])) {
    $unilogin = $_POST["bruger_unilogin"];
    $password = sha1($_POST["bruger_password"]);
    $navn = $_POST["bruger_navn"];
    $level = $_POST["bruger_level"];
    
    $klasse_id = null;

    $stmt = $conn->prepare("INSERT INTO Bruger (Unilogin, Password, Navn, Klasse_id, Level) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssii", $unilogin, $password, $navn, $klasse_id, $level);
    if ($stmt->execute()) {
        echo "Bruger oprettet!";
    } else {
        echo "Fejl: " . $stmt->error;
    }
    
    if ($level == 1) {
        if (!empty($_POST["laerer_klasse"]) && !empty($_POST["laerer_fag"])) {
            foreach ($_POST["laerer_klasse"] as $index => $klasse_id) {
                if (!empty($_POST["laerer_fag"][$index])) {
                    foreach ($_POST["laerer_fag"][$index] as $fag_id) {
                        $stmt2 = $conn->prepare("INSERT INTO Laerer_info (Laerer_Unilogin, Klasse_id, Fag_id) VALUES (?, ?, ?)");
                        $stmt2->bind_param("sii", $unilogin, $klasse_id, $fag_id);
                        $stmt2->execute();
                    }
                }
            }
        }
        echo "L&aelig;rer-info tilf&oslash;jet!";
    } else if ($level == 0) {
        $klasse_id = $_POST["Klasse_id"];
        $stmt3 = $conn->prepare("UPDATE Bruger SET Klasse_id = ? WHERE Unilogin = ?");
        $stmt3->bind_param("is", $klasse_id, $unilogin);
        $stmt3->execute();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Panel</title>
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
                <label>V&aelig;lg Klasse:</label>
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

                <label>V&aelig;lg Fag:</label>
                <select name="laerer_fag[][]" multiple>
                     <option value="1">Dansk</option>
                        <option value="2">Engelsk</option>
                        <option value="3">Matematik</option>
                        <option value="4">Samfundsfag</option>
                        <option value="5">Historie</option>
                        <option value="6">Kemi</option>
                        <option value="7">Fysik</option>
                        <option value="8">Idræt</option>
                        <option value="9">Biologi</option>
                        <option value="10">Musik</option>
                </select>

                <button type="button" onclick="this.parentElement.remove()">Fjern</button>
            `;
            container.appendChild(newPair);
        }
    </script>
</head>
<body onload="toggleFields()">
    <h1>Admin Panel</h1>
    
    <h2>Opret Bruger</h2>
    <form method="POST">
        <input type="text" name="bruger_unilogin" placeholder="Unilogin" required>
        <input type="password" name="bruger_password" placeholder="Kodeord" required>
        <input type="text" name="bruger_navn" placeholder="Navn" required>
        <label>Level:</label>
        <select name="bruger_level" id="bruger_level" onchange="toggleFields()" required>
            <option value="0">Elev</option>
            <option value="1">L&aelig;rer</option>
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
            <h3>L&aelig;rer Information</h3>
            <div id="class_subjects_container"></div>
            <button type="button" onclick="addClassSubjectPair()">Tilf&oslash;j flere</button>
        </div>

        <button type="submit" name="opret_bruger">Opret Bruger</button>
    </form>

        <h2>Året er omme</h2>
    <form method="POST">
        <button type="submit" name="aret_omme">Opdater Klasser</button>
        </form>

        <h2>Brugere</h2>
     <form method="POST">
   <button type="submit" name="hent_brugere">Vis Brugere</button>
</form>


  <a href="index.php">
        <button>Gå tilbage</button>



</body>
</html>
