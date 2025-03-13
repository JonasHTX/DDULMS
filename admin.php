<?php
ob_start();
session_start();
include 'connection.php';

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
                        $stmt2 = $conn->prepare("INSERT INTO Laerer_info (Laerer_Unilogin, Klasse_id, Fag_id, Navn) VALUES (?, ?, ?, ?)");
                        $stmt2->bind_param("siis", $unilogin, $klasse_id, $fag_id, $navn);
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


  <a href="index.php">
        <button>Gå tilbage</button>

</body>
</html>
