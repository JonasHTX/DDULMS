<?php
session_start();
include 'connection.php';

if (!isset($_SESSION['unilogin'])) {
    die("Du skal være logget ind!");
}

$unilogin = $_SESSION['unilogin'];
$level = $_SESSION['level'];

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Ugyldigt opgave ID.");
}

$oprettet_afl_id = $_GET['id'];

// Hent opgave oplysninger
$stmt = $conn->prepare("SELECT * FROM Oprettet_Aflevering WHERE Oprettet_Afl_id = ?");
$stmt->bind_param("i", $oprettet_afl_id);
$stmt->execute();
$opgave_result = $stmt->get_result();
$opgave = $opgave_result->fetch_assoc();
$stmt->close();

if (!$opgave) {
    die("Opgaven findes ikke.");
}

echo "<h3>" . htmlspecialchars($opgave['Oprettet_Afl_navn']) . "</h3>";
echo "<p>Deadline: " . htmlspecialchars($opgave['Oprettet_Afl_deadline']) . "</p>";

// Tjek om eleven allerede har afleveret
$afleveret = false;
if ($level == 0) {
    $stmt = $conn->prepare("SELECT * FROM Elev_Aflevering WHERE Oprettet_Afl_id = ? AND Unilogin = ?");
    $stmt->bind_param("is", $oprettet_afl_id, $unilogin);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->fetch_assoc()) {
        $afleveret = true;
    }
    $stmt->close();
}

// **Håndter filupload for elever**
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['aflevering_fil']) && !$afleveret) {
    $upload_dir = 'uploads/';
    $upload_file = $upload_dir . basename($_FILES['aflevering_fil']['name']);

    if (move_uploaded_file($_FILES['aflevering_fil']['tmp_name'], $upload_file)) {
        $stmt = $conn->prepare("INSERT INTO Elev_Aflevering (Oprettet_Afl_id, Unilogin, Elev_Afl_tid, Filpath) VALUES (?, ?, NOW(), ?)");
        $stmt->bind_param("iss", $oprettet_afl_id, $unilogin, $upload_file);
        $stmt->execute();
        echo "<p>Fil uploadet succesfuldt!</p>";
    } else {
        echo "<p>Fejl ved upload af fil.</p>";
    }
}

// **Vis upload mulighed for elever, hvis de IKKE har afleveret**
if ($level == 0 && !$afleveret) {
    echo "<form method='POST' enctype='multipart/form-data'>
            <label for='aflevering_fil'>Upload din aflevering:</label>
            <input type='file' name='aflevering_fil' id='aflevering_fil' required>
            <input type='submit' value='Upload'>
          </form>";
} elseif ($level == 0) {
    echo "<p>Du har allerede afleveret denne opgave.</p>";
}

// **Læreren evaluerer afleveringer**
if ($level == 1) {
    echo "<h3>Afleveringer fra elever</h3>";

    $stmt = $conn->prepare("SELECT * FROM Elev_Aflevering WHERE Oprettet_Afl_id = ?");
    $stmt->bind_param("i", $oprettet_afl_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        echo "<p><strong>" . htmlspecialchars($row['Unilogin']) . "</strong> har afleveret 
              <a href='" . htmlspecialchars($row['Filpath']) . "' target='_blank'>Download</a></p>";

        echo "<form method='POST'>
                <label for='karakter'>Karakter:</label>
                <select name='karakter'>
                    <option value='12'>12</option>
                    <option value='10'>10</option>
                    <option value='7'>7</option>
                    <option value='4'>4</option>
                    <option value='02'>02</option>
                    <option value='00'>00</option>
                    <option value='-3'>-3</option>
                </select>
                <br>
                <label for='feedback'>Feedback:</label>
                <textarea name='feedback'></textarea>
                <input type='hidden' name='elev_afl_id' value='" . $row['Elev_Afl_id'] . "'>
                <input type='submit' name='submit_feedback' value='Send feedback'>
              </form>";
    }
}

if (isset($_POST['submit_feedback'])) {
    $stmt = $conn->prepare("INSERT INTO Evaluering (Elev_Afl_id, Evaluering_karakter, Feedback) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $_POST['elev_afl_id'], $_POST['karakter'], $_POST['feedback']);
    $stmt->execute();
    echo "<p>Feedback gemt!</p>";
}

$conn->close();
?>
<!DOCTYPE html>
<html>
<body>

   <a href="index.php">Tilbage</a>

</body>
</html>