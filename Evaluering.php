<?php
session_start();
include 'connection.php';

// Tjek om brugeren er logget ind
if (!isset($_SESSION['unilogin'])) {
    die("Du har ikke adgang til denne side.");
}

$unilogin = $_SESSION['unilogin'];

// Hent brugerens level (0 = elev, 1 = lærer)
$stmt = $conn->prepare("SELECT Level FROM Bruger WHERE Unilogin = ?");
$stmt->bind_param("s", $unilogin);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    die("Bruger ikke fundet.");
}

$level = $user['Level'];

if ($level == 0) { 
    // **ELEVVISNING**
    echo "<h2>Mine afleveringer og feedback</h2>";

    $stmt = $conn->prepare("
        SELECT ea.Elev_Afl_id, o.Oprettet_Afl_navn, ea.Elev_Afl_tid, ea.Filpath, 
               ev.Evaluering_karakter, ev.Feedback, ev.Filpath AS FeedbackFil
        FROM Elev_Aflevering ea
        JOIN Oprettet_Aflevering o ON ea.Oprettet_Afl_id = o.Oprettet_Afl_id
        LEFT JOIN Evaluering ev ON ea.Elev_Afl_id = ev.Elev_Afl_id
        WHERE ea.Unilogin = ?
    ");
    $stmt->bind_param("s", $unilogin);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "<p><strong>Opgave:</strong> " . htmlspecialchars($row['Oprettet_Afl_navn']) . "<br>";
            echo "<strong>Afleveret den:</strong> " . htmlspecialchars($row['Elev_Afl_tid']) . "<br>";
            echo "<a href='uploads/" . htmlspecialchars(basename($row['Filpath'])) . "' target='_blank'>Download aflevering</a><br>";

            if ($row['Evaluering_karakter']) {
                echo "<strong>Karakter:</strong> " . htmlspecialchars($row['Evaluering_karakter']) . "<br>";
                echo "<strong>Feedback:</strong> " . nl2br(htmlspecialchars($row['Feedback'])) . "<br>";

                if (!empty($row['FeedbackFil'])) {
                    echo "<a href='uploads/" . htmlspecialchars(basename($row['FeedbackFil'])) . "' download>Download feedback-fil</a><br>";
                }
            } else {
                echo "<em>Ingen feedback endnu.</em>";
            }
            echo "<hr>";
        }
    } else {
        echo "<p>Du har ikke afleveret nogen opgaver endnu.</p>";
    }
} elseif ($level == 1) {  
    // **LÆRERVISNING**
    echo "<h2>Ret afleveringer</h2>";

    $stmt = $conn->prepare("
        SELECT ea.Elev_Afl_id, ea.Unilogin, o.Oprettet_Afl_navn, ea.Elev_Afl_tid, ea.Filpath
        FROM Elev_Aflevering ea
        JOIN Oprettet_Aflevering o ON ea.Oprettet_Afl_id = o.Oprettet_Afl_id
        LEFT JOIN Evaluering ev ON ea.Elev_Afl_id = ev.Elev_Afl_id
        WHERE ev.Elev_Afl_id IS NULL
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "<form method='POST' action='' enctype='multipart/form-data'>";
            echo "<p><strong>Opgave:</strong> " . htmlspecialchars($row['Oprettet_Afl_navn']) . "<br>";
            echo "<strong>Afleveret af:</strong> " . htmlspecialchars($row['Unilogin']) . "<br>";
            echo "<a href='uploads/" . htmlspecialchars(basename($row['Filpath'])) . "' target='_blank'>Download aflevering</a><br>";
            
            echo "<input type='hidden' name='Elev_Afl_id' value='" . $row['Elev_Afl_id'] . "'>";
            echo "<label for='karakter'>Karakter:</label> ";
            echo "<input type='text' name='karakter' required><br>";
            
            echo "<label for='feedback'>Feedback:</label><br>";
            echo "<textarea name='feedback' rows='4' cols='50' required></textarea><br>";

            echo "<label for='feedback_file'>Upload feedback-fil:</label> ";
            echo "<input type='file' name='feedback_file' accept='.pdf,.docx,.txt'><br>";

            echo "<input type='submit' name='submit_feedback' value='Gem feedback'>";
            echo "</form><hr>";
        }
    } else {
        echo "<p>Ingen afleveringer mangler rettelse.</p>";
    }
}

// **Håndtering af feedback-indsendelse**
if ($level == 1 && isset($_POST['submit_feedback'])) {
    $Elev_Afl_id = $_POST['Elev_Afl_id'];
    $karakter = $_POST['karakter'];
    $feedback = $_POST['feedback'];
    $feedbackFilpath = null;

    // **Håndtering af feedback-fil**
    if (!empty($_FILES['feedback_file']['name'])) {
        $uploadDir = "uploads/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileName = basename($_FILES['feedback_file']['name']);
        $filePath = $uploadDir . uniqid() . "_" . $fileName;
        $fileType = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // **Tilladte filtyper**
        $allowedTypes = ['pdf', 'docx', 'txt'];
        if (in_array($fileType, $allowedTypes)) {
            if (move_uploaded_file($_FILES['feedback_file']['tmp_name'], $filePath)) {
                $feedbackFilpath = $filePath;
            } else {
                echo "<p style='color: red;'>Fejl ved upload af feedback-filen.</p>";
            }
        } else {
            echo "<p style='color: red;'>Ugyldig filtype! Kun PDF, DOCX og TXT tilladt.</p>";
        }
    }

    // **Gem feedback og karakter i databasen**
    $stmt = $conn->prepare("
        INSERT INTO Evaluering (Elev_Afl_id, Evaluering_karakter, Feedback, Filpath) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("isss", $Elev_Afl_id, $karakter, $feedback, $feedbackFilpath);
    $stmt->execute();
    $stmt->close();

    echo "<p style='color: green;'>Feedback gemt!</p>";
}

$conn->close();
?>
<!DOCTYPE html>
<html>
<body>
   <a href="index.php">Tilbage</a>
</body>
</html>
