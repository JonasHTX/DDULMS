<?php
session_start();
include 'connection.php';

if (!isset($_SESSION['unilogin'])) {
    die("Du skal være logget ind for at få adgang.");
}

$unilogin = $_SESSION['unilogin'];

// Brug prepared statements for at forhindre SQL-injektion
$sql_user = $conn->prepare("SELECT * FROM Bruger WHERE Unilogin = ?");
$sql_user->bind_param("s", $unilogin);
$sql_user->execute();
$user_result = $sql_user->get_result();

if ($user_result->num_rows > 0) {
    $user_row = $user_result->fetch_assoc();
    $klasse_id = $user_row['Klasse_id'];
    $user_level = $user_row['Level'];
} else {
    die("Brugeren blev ikke fundet.");
}

// Hent lærerens klasser og fag (hvis bruger er lærer)
if ($user_level == 1) {
    $sql_teacher_info = $conn->prepare("SELECT Klasse_id, Fag_id FROM Laerer_info WHERE Laerer_Unilogin = ?");
    $sql_teacher_info->bind_param("s", $unilogin);
    $sql_teacher_info->execute();
    $teacher_result = $sql_teacher_info->get_result();

    if ($teacher_result->num_rows > 0) {
        $teacher_data = $teacher_result->fetch_all(MYSQLI_ASSOC);
    } else {
        die("Læreroplysninger blev ikke fundet.");
    }
}

// Hent opgave ID fra URL
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $oprettet_afl_id = $_GET['id'];
} else {
    die("Ugyldigt opgave ID.");
}

// Hent opgave oplysninger med prepared statements
if ($user_level == 0) {
    $sql_opgaver = $conn->prepare("SELECT * FROM Oprettet_Aflevering WHERE Oprettet_Afl_id = ? AND Klasse_id = ?");
    $sql_opgaver->bind_param("ii", $oprettet_afl_id, $klasse_id);
} else {
    // Hvis lærer underviser i flere klasser og fag, bygges en dynamisk WHERE-clause
    $where_clauses = [];
    $params = [];
    $types = "i"; // Første parameter er altid opgave-id
    foreach ($teacher_data as $row) {
        $where_clauses[] = "(Klasse_id = ? AND Fag_id = ?)";
        $params[] = $row['Klasse_id'];
        $params[] = $row['Fag_id'];
        $types .= "ii";
    }
    
    $query = "SELECT * FROM Oprettet_Aflevering WHERE Oprettet_Afl_id = ? AND (" . implode(" OR ", $where_clauses) . ")";
    $sql_opgaver = $conn->prepare($query);
    $sql_opgaver->bind_param($types, $oprettet_afl_id, ...$params);
}

$sql_opgaver->execute();
$opgaver_result = $sql_opgaver->get_result();

if ($opgaver_result->num_rows > 0) {
    while ($row = $opgaver_result->fetch_assoc()) {
        echo "<h3>" . htmlspecialchars($row['Oprettet_Afl_navn']) . "</h3>";
        echo "<p>Deadline: " . htmlspecialchars($row['Oprettet_Afl_deadline']) . "</p>";
        echo "<p>Fag: " . htmlspecialchars($row['Fag_id']) . "</p>";
        echo "<p>Klasse: " . htmlspecialchars($row['Klasse_id']) . "</p>";

        // Check om elev allerede har afleveret
        $sql_check = $conn->prepare("SELECT * FROM Elev_Aflevering WHERE Oprettet_Afl_id = ? AND Unilogin = ?");
        $sql_check->bind_param("is", $oprettet_afl_id, $unilogin);
        $sql_check->execute();
        $check_result = $sql_check->get_result();

        if ($check_result->num_rows > 0) {
            $check_row = $check_result->fetch_assoc();
            echo "<p>Du har allerede afleveret: <a href='" . htmlspecialchars($check_row['Filpath']) . "' target='_blank'>Download din aflevering</a></p>";

            // Vis feedback og karakter (kun for læreren)
            if ($user_level == 1) {
                $sql_feedback = $conn->prepare("SELECT * FROM Evaluering WHERE Elev_Aflevering_id = ?");
                $sql_feedback->bind_param("i", $check_row['Elev_Afl_id']);
                $sql_feedback->execute();
                $feedback_result = $sql_feedback->get_result();

                if ($feedback_result->num_rows > 0) {
                    $feedback_row = $feedback_result->fetch_assoc();
                    echo "<p>Karakter: " . htmlspecialchars($feedback_row['Evaluering_karakter']) . "</p>";
                    echo "<p>Feedback: " . htmlspecialchars($feedback_row['Feedback']) . "</p>";
                    echo "<p>Fil: <a href='" . htmlspecialchars($feedback_row['Filpath']) . "' target='_blank'>Download feedback fil</a></p>";
                } else {
                    echo "<form method='POST' enctype='multipart/form-data'>
                            <label for='karakter'>Karakter:</label>
                            <select name='karakter' id='karakter'>
                                <option value='12'>12</option>
                                <option value='10'>10</option>
                                <option value='7'>7</option>
                                <option value='4'>4</option>
                                <option value='02'>02</option>
                                <option value='00'>00</option>
                                <option value='-03'>-03</option>
                            </select><br>
                            <label for='feedback'>Feedback:</label><br>
                            <textarea name='feedback' id='feedback' rows='4' cols='50'></textarea><br>
                            <label for='feedback_fil'>Upload feedback fil (valgfrit):</label>
                            <input type='file' name='feedback_fil' id='feedback_fil'><br>
                            <input type='hidden' name='elev_afl_id' value='" . $check_row['Elev_Afl_id'] . "'>
                            <input type='submit' name='submit_feedback' value='Aflever feedback'>
                          </form>";
                }
            }
        } else {
            echo "<form method='POST' enctype='multipart/form-data'>
                    <input type='hidden' name='oprettet_afl_id' value='" . htmlspecialchars($row['Oprettet_Afl_id']) . "'>
                    <label for='aflevering_fil'>Upload din aflevering:</label>
                    <input type='file' name='aflevering_fil' id='aflevering_fil'>
                    <input type='submit' value='Upload'>
                  </form>";
        }
    }
} else {
    echo "Ingen opgaver fundet.";
}

// Håndter feedback og karakter
if (isset($_POST['submit_feedback'])) {
    $karakter = $_POST['karakter'];
    $feedback = $_POST['feedback'];
    $elev_afl_id = $_POST['elev_afl_id'];

    $feedback_file_path = NULL;
    if (!empty($_FILES['feedback_fil']['name'])) {
        $feedback_file_name = basename($_FILES['feedback_fil']['name']);
        $feedback_file_path = 'uploads/' . $feedback_file_name;
        move_uploaded_file($_FILES['feedback_fil']['tmp_name'], $feedback_file_path);
    }

    $sql_feedback = $conn->prepare("INSERT INTO Evaluering (Elev_Aflevering_id, Evaluering_karakter, Feedback, Filpath) VALUES (?, ?, ?, ?)");
    $sql_feedback->bind_param("isss", $elev_afl_id, $karakter, $feedback, $feedback_file_path);
    $sql_feedback->execute();
    echo "Feedback og karakter er gemt.";
}

$conn->close();
?>
