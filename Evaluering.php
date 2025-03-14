<?php
session_start();
include 'connection.php';

// Tjek om brugeren er logget ind
if (!isset($_SESSION['unilogin'])) {
    die("Du har ikke adgang til denne side.");
}

$unilogin = $_SESSION['unilogin'];

// Hent lærerens fag og klasse
$stmt = $conn->prepare("SELECT Fag_id, Klasse_id FROM Laerer_info WHERE Laerer_Unilogin = ?");
if (!$stmt) {
    die('Fejl ved forberedelse af SQL: ' . $conn->error);
}
$stmt->bind_param("s", $unilogin);
$stmt->execute();
$result = $stmt->get_result();
$laerer_info = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$laerer_info) {
    die("Ingen tilknyttet klasse eller fag fundet.");
}

// Hent afleverede opgaver for alle de kombinationer af fag og klasse læreren er tilknyttet
$stmt = $conn->prepare("
    SELECT ea.Elev_Afl_id, ea.Unilogin, ea.Filpath, ea.Elev_Afl_tid, o.Oprettet_Afl_navn, b.Navn, ev.Evaluering_karakter, ev.Feedback
    FROM Elev_Aflevering ea
    JOIN Oprettet_Aflevering o ON ea.Oprettet_Afl_id = o.Oprettet_Afl_id
    JOIN Bruger b ON ea.Unilogin = b.Unilogin
    LEFT JOIN Evaluering ev ON ea.Elev_Afl_id = ev.Elev_Afl_id
    WHERE (o.Fag_id, o.Klasse_id) IN (
        " . implode(',', array_map(function($info) {
            return "({$info['Fag_id']}, {$info['Klasse_id']})";
        }, $laerer_info)) . ")
");
if (!$stmt) {
    die('Fejl ved forberedelse af SQL: ' . $conn->error);
}
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

echo "<h2>Afleverede opgaver</h2>";

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "<p><strong>" . htmlspecialchars($row['Navn']) . " (" . htmlspecialchars($row['Unilogin']) . ")</strong> har afleveret '" . htmlspecialchars($row['Oprettet_Afl_navn']) . "' 
              den " . htmlspecialchars($row['Elev_Afl_tid']) . "<br>
              <a href='" . htmlspecialchars($row['Filpath']) . "' target='_blank'>Download aflevering</a></p>";

        // Vis tidligere feedback hvis den findes
        $existing_karakter = $row['Evaluering_karakter'] ? $row['Evaluering_karakter'] : "";
        $existing_feedback = $row['Feedback'] ? htmlspecialchars($row['Feedback']) : "";

        echo "<form method='POST'>
                <input type='hidden' name='elev_afl_id' value='" . $row['Elev_Afl_id'] . "'>
                <label for='karakter'>Karakter:</label>
                <select name='karakter' required>
                    <option value='' " . ($existing_karakter == "" ? "selected" : "") . ">Vælg karakter</option>
                    <option value='12' " . ($existing_karakter == "12" ? "selected" : "") . ">12</option>
                    <option value='10' " . ($existing_karakter == "10" ? "selected" : "") . ">10</option>
                    <option value='7' " . ($existing_karakter == "7" ? "selected" : "") . ">7</option>
                    <option value='4' " . ($existing_karakter == "4" ? "selected" : "") . ">4</option>
                    <option value='02' " . ($existing_karakter == "02" ? "selected" : "") . ">02</option>
                    <option value='00' " . ($existing_karakter == "00" ? "selected" : "") . ">00</option>
                    <option value='-3' " . ($existing_karakter == "-3" ? "selected" : "") . ">-3</option>
                </select>
                <br>
                <label for='feedback'>Feedback:</label>
                <textarea name='feedback' required>$existing_feedback</textarea>
                <br>
                <input type='submit' name='submit_feedback' value='Send feedback'>
              </form><hr>";
    }
} else {
    echo "<p>Ingen afleveringer endnu.</p>";
}

// Håndter feedback indsendelse
if (isset($_POST['submit_feedback'])) {
    $elev_afl_id = $_POST['elev_afl_id'];
    $karakter = $_POST['karakter'];
    $feedback = $_POST['feedback'];

    // Tjek om feedback allerede eksisterer
    $stmt = $conn->prepare("SELECT * FROM Evaluering WHERE Elev_Afl_id = ?");
    if (!$stmt) {
        die('Fejl ved forberedelse af SQL: ' . $conn->error);
    }
    $stmt->bind_param("i", $elev_afl_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows > 0) {
        // Opdater eksisterende feedback
        $stmt = $conn->prepare("UPDATE Evaluering SET Evaluering_karakter = ?, Feedback = ? WHERE Elev_Afl_id = ?");
        if (!$stmt) {
            die('Fejl ved forberedelse af SQL: ' . $conn->error);
        }
        $stmt->bind_param("ssi", $karakter, $feedback, $elev_afl_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO Evaluering (Elev_Afl_id, Evaluering_karakter, Feedback) VALUES (?, ?, ?)");
        if (!$stmt) {
            die('Fejl ved forberedelse af SQL: ' . $conn->error);
        }
        $stmt->bind_param("iss", $elev_afl_id, $karakter, $feedback);
    }

    if ($stmt->execute()) {
        echo "<p>Feedback gemt!</p>";
    } else {
        echo "<p>Fejl ved gemning af feedback.</p>";
    }
    $stmt->close();
}

$conn->close();
?>
