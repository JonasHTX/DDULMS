<?php
session_start();
include 'connection.php';
include 'header.php';

if (!isset($_SESSION['unilogin'])) {
    die("Du skal v�re logget ind!");
}

$unilogin = $_SESSION['unilogin'];
$level = $_SESSION['level'];

// Change from $_GET['id'] to $_GET['oprettet_afl_id']
if (!isset($_GET['oprettet_afl_id']) || !is_numeric($_GET['oprettet_afl_id'])) {
    die("Ugyldigt opgave ID.");
}

$oprettet_afl_id = $_GET['oprettet_afl_id'];

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

// H�ndter filupload for elever
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['aflevering_fil']) && !$afleveret) {
    $upload_dir = 'uploads/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $file_name = basename($_FILES['aflevering_fil']['name']);
    $upload_file = $upload_dir . uniqid() . '_' . $file_name;

    if (move_uploaded_file($_FILES['aflevering_fil']['tmp_name'], $upload_file)) {
        $stmt = $conn->prepare("INSERT INTO Elev_Aflevering (Oprettet_Afl_id, Unilogin, Elev_Afl_tid, Filpath) VALUES (?, ?, NOW(), ?)");
        $stmt->bind_param("iss", $oprettet_afl_id, $unilogin, $upload_file);
        $stmt->execute();
        $stmt->close();
        $afleveret = true; // Mark as submitted after successful upload
        echo "<p>Fil uploadet succesfuldt!</p>";
    } else {
        echo "<p>Fejl ved upload af fil.</p>";
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <title>Aflever opgave</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
        }
        textarea {
            width: 100%;
            height: 100px;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            padding: 8px 12px;
            background-color: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .back-link:hover {
            background-color: #2980b9;
        }
    </style>
</head>
<body>
    <h2><?php echo htmlspecialchars($opgave['Oprettet_Afl_navn']); ?></h2>
    <p><strong>Deadline:</strong> <?php echo htmlspecialchars($opgave['Oprettet_Afl_deadline']); ?></p>

    <?php if ($level == 0): ?>
        <?php if ($afleveret): ?>
            <p>Du har allerede afleveret denne opgave.</p>
        <?php else: ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="aflevering_fil">V�lg fil til upload:</label>
                    <input type="file" name="aflevering_fil" id="aflevering_fil" required>
                </div>
                <input type="submit" value="Aflever opgave">
            </form>
        <?php endif; ?>
    <?php else: ?>
        <h3>Afleveringer fra elever</h3>
        <?php
        $stmt = $conn->prepare("SELECT ea.*, b.Navn FROM Elev_Aflevering ea 
                               JOIN Bruger b ON ea.Unilogin = b.Unilogin 
                               WHERE Oprettet_Afl_id = ?");
        $stmt->bind_param("i", $oprettet_afl_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0): ?>
            <table border="1">
                <tr>
                    <th>Elev</th>
                    <th>Afleveret</th>
                    <th>Fil</th>
                    <th>Handling</th>
                </tr>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['Navn']); ?></td>
                        <td><?php echo htmlspecialchars($row['Elev_Afl_tid']); ?></td>
                        <td><a href="<?php echo htmlspecialchars($row['Filpath']); ?>" target="_blank">Download</a></td>
                        <td>
                            <?php
                            $eval_stmt = $conn->prepare("SELECT * FROM Evaluering WHERE Elev_Afl_id = ?");
                            $eval_stmt->bind_param("i", $row['Elev_Afl_id']);
                            $eval_stmt->execute();
                            $eval_result = $eval_stmt->get_result();
                            $evaluering = $eval_result->fetch_assoc();
                            $eval_stmt->close();
                            
                            if ($evaluering): ?>
                                <p>Karakter: <?php echo htmlspecialchars($evaluering['Evaluering_karakter']); ?></p>
                                <p>Feedback: <?php echo htmlspecialchars($evaluering['Feedback']); ?></p>
                            <?php else: ?>
                                <form method="POST">
                                    <div class="form-group">
                                        <label for="karakter">Karakter:</label>
                                        <select name="karakter" id="karakter" required>
                                            <option value="">V�lg karakter</option>
                                            <option value="12">12</option>
                                            <option value="10">10</option>
                                            <option value="7">7</option>
                                            <option value="4">4</option>
                                            <option value="02">02</option>
                                            <option value="00">00</option>
                                            <option value="-3">-3</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="feedback">Feedback:</label>
                                        <textarea name="feedback" id="feedback"></textarea>
                                    </div>
                                    <input type="hidden" name="elev_afl_id" value="<?php echo $row['Elev_Afl_id']; ?>">
                                    <input type="submit" name="submit_feedback" value="Gem evaluering">
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </table>
        <?php else: ?>
            <p>Ingen elever har afleveret endnu.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    // H�ndter evaluering
    if (isset($_POST['submit_feedback'])) {
        $stmt = $conn->prepare("INSERT INTO Evaluering (Elev_Afl_id, Evaluering_karakter, Feedback) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $_POST['elev_afl_id'], $_POST['karakter'], $_POST['feedback']);
        $stmt->execute();
        $stmt->close();
        echo "<p>Evaluering gemt!</p>";
        // Refresh to show the new evaluation
        header("Refresh:0");
    }
    ?>

    <a href="index.php" class="back-link">Tilbage til Forside</a>
</body>
</html>
<?php
$conn->close();
?>