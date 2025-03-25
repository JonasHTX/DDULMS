<?php
session_start();
include 'connection.php';

// Aktiver fejlrapportering
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Tjek login
if (!isset($_SESSION['unilogin'])) {
    header("Location: login.php");
    exit();
}

// Hent brugerinfo
$stmt = $conn->prepare("SELECT Level, Navn FROM Bruger WHERE Unilogin = ?");
$stmt->bind_param("s", $_SESSION['unilogin']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    die("Bruger ikke fundet.");
}

$is_teacher = ($user['Level'] == 1);
$current_user = $_SESSION['unilogin'];

// Håndter formularindsendelse (kun for lærere)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_feedback']) && $is_teacher) {
    gemFeedback($conn);
}

// Håndter forskellige adgangsveje
if (isset($_GET['elev_afl_id'])) {
    // Vis specifik aflevering
    $elev_afl_id = intval($_GET['elev_afl_id']);
    $aflevering = hentAflevering($conn, $elev_afl_id, $current_user, $is_teacher);
    
    if (!$aflevering) {
        die("Aflevering ikke fundet eller du har ikke adgang.");
    }
    
    if ($is_teacher) {
        visFeedbackFormular($aflevering);
    } else {
        visElevAflevering($aflevering);
    }
    
} elseif (isset($_GET['oprettet_afl_id']) && $is_teacher) {
    // Vis alle afleveringer til en opgave (kun for lærere)
    $oprettet_afl_id = intval($_GET['oprettet_afl_id']);
    $afleveringer = hentAfleveringerTilOpgave($conn, $oprettet_afl_id);
    visAfleveringsListe($afleveringer, true);
    
} else {
    // Standardvisning
    if ($is_teacher) {
        $afleveringer = hentAfleveringerManglerEvaluering($conn);
        visAfleveringsListe($afleveringer, true);
    } else {
        $afleveringer = hentMineAfleveringer($conn, $current_user);
        visAfleveringsListe($afleveringer, false);
    }
}

$conn->close();

/*** FUNKTIONER ***/

function hentAflevering($conn, $elev_afl_id, $current_user, $is_teacher) {
    $stmt = $conn->prepare("
        SELECT ea.*, o.Oprettet_Afl_navn, b.Navn AS Elev_navn, 
               f.Fag_navn, k.Klasse_navn, o.Oprettet_Afl_id,
               ev.Evaluering_karakter, ev.Feedback, ev.Filpath AS Feedback_fil
        FROM Elev_Aflevering ea
        JOIN Oprettet_Aflevering o ON ea.Oprettet_Afl_id = o.Oprettet_Afl_id
        JOIN Bruger b ON ea.Unilogin = b.Unilogin
        JOIN Fag f ON o.Fag_id = f.Fag_id
        JOIN Klasse k ON b.Klasse_id = k.Klasse_id
        LEFT JOIN Evaluering ev ON ea.Elev_Afl_id = ev.Elev_Afl_id
        WHERE ea.Elev_Afl_id = ? " . 
        (!$is_teacher ? " AND ea.Unilogin = ?" : "")
    );
    
    if ($is_teacher) {
        $stmt->bind_param("i", $elev_afl_id);
    } else {
        $stmt->bind_param("is", $elev_afl_id, $current_user);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function hentAfleveringerTilOpgave($conn, $oprettet_afl_id) {
    $stmt = $conn->prepare("
        SELECT ea.Elev_Afl_id, ea.Elev_Afl_tid, b.Navn AS Elev_navn, 
               k.Klasse_navn, ev.Evaluering_id, o.Oprettet_Afl_navn
        FROM Elev_Aflevering ea
        JOIN Bruger b ON ea.Unilogin = b.Unilogin
        JOIN Klasse k ON b.Klasse_id = k.Klasse_id
        JOIN Oprettet_Aflevering o ON ea.Oprettet_Afl_id = o.Oprettet_Afl_id
        LEFT JOIN Evaluering ev ON ea.Elev_Afl_id = ev.Elev_Afl_id
        WHERE ea.Oprettet_Afl_id = ?
        ORDER BY ea.Elev_Afl_tid DESC
    ");
    $stmt->bind_param("i", $oprettet_afl_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function hentAfleveringerManglerEvaluering($conn) {
    $stmt = $conn->prepare("
        SELECT ea.Elev_Afl_id, o.Oprettet_Afl_navn, ea.Elev_Afl_tid, 
               b.Navn AS Elev_navn, k.Klasse_navn, f.Fag_navn
        FROM Elev_Aflevering ea
        JOIN Oprettet_Aflevering o ON ea.Oprettet_Afl_id = o.Oprettet_Afl_id
        JOIN Bruger b ON ea.Unilogin = b.Unilogin
        JOIN Klasse k ON b.Klasse_id = k.Klasse_id
        JOIN Fag f ON o.Fag_id = f.Fag_id
        LEFT JOIN Evaluering ev ON ea.Elev_Afl_id = ev.Elev_Afl_id
        WHERE ev.Evaluering_id IS NULL
        ORDER BY ea.Elev_Afl_tid DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function hentMineAfleveringer($conn, $current_user) {
    $stmt = $conn->prepare("
        SELECT ea.Elev_Afl_id, o.Oprettet_Afl_navn, ea.Elev_Afl_tid, 
               f.Fag_navn, ev.Evaluering_karakter, ev.Feedback,
               ev.Filpath AS Feedback_fil
        FROM Elev_Aflevering ea
        JOIN Oprettet_Aflevering o ON ea.Oprettet_Afl_id = o.Oprettet_Afl_id
        JOIN Fag f ON o.Fag_id = f.Fag_id
        LEFT JOIN Evaluering ev ON ea.Elev_Afl_id = ev.Elev_Afl_id
        WHERE ea.Unilogin = ?
        ORDER BY ea.Elev_Afl_tid DESC
    ");
    $stmt->bind_param("s", $current_user);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function visAfleveringsListe($afleveringer, $is_teacher) {
    if ($is_teacher) {
        echo "<h2>Afleveringer der venter på evaluering</h2>";
    } else {
        echo "<h2>Mine afleveringer</h2>";
    }
    
    if (empty($afleveringer)) {
        echo "<p>Ingen afleveringer fundet.</p>";
        return;
    }
    
    echo "<table border='1'>";
    
    if ($is_teacher) {
        echo "<tr><th>Elev</th><th>Klasse</th><th>Opgave</th><th>Afleveret</th><th>Handling</th></tr>";
    } else {
        echo "<tr><th>Opgave</th><th>Fag</th><th>Afleveret</th><th>Status</th><th>Handling</th></tr>";
    }
    
    foreach ($afleveringer as $afl) {
        echo "<tr>";
        
        if ($is_teacher) {
            echo "<td>" . htmlspecialchars($afl['Elev_navn']) . "</td>";
            echo "<td>" . htmlspecialchars($afl['Klasse_navn']) . "</td>";
        }
        
        echo "<td>" . htmlspecialchars($afl['Oprettet_Afl_navn'] ?? 'Opgave #' . $afl['Oprettet_Afl_id']) . "</td>";
        
        if (!$is_teacher) {
            echo "<td>" . htmlspecialchars($afl['Fag_navn']) . "</td>";
        }
        
        echo "<td>" . htmlspecialchars($afl['Elev_Afl_tid']) . "</td>";
        
        if ($is_teacher) {
            if (isset($afl['Evaluering_id'])) {
                echo "<td>Allerede evalueret</td>";
            } else {
                echo "<td><a href='Evaluering.php?elev_afl_id=" . $afl['Elev_Afl_id'] . "'>Giv feedback</a></td>";
            }
        } else {
            if (isset($afl['Evaluering_karakter'])) {
                echo "<td>Evalueret (" . htmlspecialchars($afl['Evaluering_karakter']) . ")</td>";
                echo "<td><a href='Evaluering.php?elev_afl_id=" . $afl['Elev_Afl_id'] . "'>Se detaljer</a></td>";
            } else {
                echo "<td>Afventer evaluering</td>";
                echo "<td>ikke rettet</td>";
            }
        }
        
        echo "</tr>";
    }
    
    echo "</table>";
}

function visFeedbackFormular($aflevering) {
    ?>
    <!DOCTYPE html>
    <html lang="da">
    <head>
        <meta charset="UTF-8">
        <title>Giv Feedback</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
            .container { background: #f9f9f9; padding: 20px; border-radius: 5px; }
            textarea { width: 100%; height: 150px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        </style>
    </head>
    <body>
        <h1>Giv Feedback</h1>
        <div class="container">
            <h2>Aflevering</h2>
            <table>
                <tr><th>Elev:</th><td><?= htmlspecialchars($aflevering['Elev_navn']) ?></td></tr>
                <tr><th>Klasse:</th><td><?= htmlspecialchars($aflevering['Klasse_navn']) ?></td></tr>
                <tr><th>Fag:</th><td><?= htmlspecialchars($aflevering['Fag_navn']) ?></td></tr>
                <tr><th>Opgave:</th><td><?= htmlspecialchars($aflevering['Oprettet_Afl_navn']) ?></td></tr>
                <tr><th>Afleveret:</th><td><?= htmlspecialchars($aflevering['Elev_Afl_tid']) ?></td></tr>
            </table>
            
            <?php if (!empty($aflevering['Filpath'])): ?>
                <p><a href="uploads/<?= htmlspecialchars(basename($aflevering['Filpath'])) ?>" target="_blank">Download aflevering</a></p>
            <?php endif; ?>
            
            <hr>
            
            <h2>Feedbackformular</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="elev_afl_id" value="<?= $aflevering['Elev_Afl_id'] ?>">
                <div>
                    <label for="karakter">Karakter:</label>
                    <input type="text" id="karakter" name="karakter" required>
                </div>
                
                <div>
                    <label for="feedback">Feedback:</label>
                    <textarea id="feedback" name="feedback" required></textarea>
                </div>
                
                <div>
                    <label for="feedback_file">Feedbackfil (valgfri):</label>
                    <input type="file" id="feedback_file" name="feedback_file">
                </div>
                
                <button type="submit" name="submit_feedback">Gem feedback</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}

function visElevAflevering($aflevering) {
    ?>
    <!DOCTYPE html>
    <html lang="da">
    <head>
        <meta charset="UTF-8">
        <title>Min Aflevering</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
            .container { background: #f9f9f9; padding: 20px; border-radius: 5px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
            .feedback { background-color: #f0f0f0; padding: 15px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <h1>Min Aflevering</h1>
        <div class="container">
            <h2>Opgavedetaljer</h2>
            <table>
                <tr><th>Opgave:</th><td><?= htmlspecialchars($aflevering['Oprettet_Afl_navn']) ?></td></tr>
                <tr><th>Fag:</th><td><?= htmlspecialchars($aflevering['Fag_navn']) ?></td></tr>
                <tr><th>Afleveret:</th><td><?= htmlspecialchars($aflevering['Elev_Afl_tid']) ?></td></tr>
            </table>
            
            <?php if (!empty($aflevering['Filpath'])): ?>
                <p><a href="uploads/<?= htmlspecialchars(basename($aflevering['Filpath'])) ?>" target="_blank">Download min aflevering</a></p>
            <?php endif; ?>
            
            <?php if (!empty($aflevering['Evaluering_karakter'])): ?>
                <div class="feedback">
                    <h2>Feedback</h2>
                    <p><strong>Karakter:</strong> <?= htmlspecialchars($aflevering['Evaluering_karakter']) ?></p>
                    <p><strong>Feedback:</strong><br><?= nl2br(htmlspecialchars($aflevering['Feedback'])) ?></p>
                    
                    <?php if (!empty($aflevering['Feedback_fil'])): ?>
                        <p><a href="uploads/feedback/<?= htmlspecialchars(basename($aflevering['Feedback_fil'])) ?>" download>Download feedback-fil</a></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p>Denne aflevering er endnu ikke blevet evalueret.</p>
            <?php endif; ?>
            
            <p><a href="tidligere_afleveringer.php">Tilbage til mine afleveringer</a></p>
        </div>
    </body>
    </html>
    <?php
}

function gemFeedback($conn) {
    $elev_afl_id = intval($_POST['elev_afl_id']);
    $karakter = $_POST['karakter'];
    $feedback = $_POST['feedback'];
    $filpath = null;
    
    // Håndter filupload
    if (!empty($_FILES['feedback_file']['name'])) {
        $uploadDir = "uploads/feedback/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileName = basename($_FILES['feedback_file']['name']);
        $filePath = $uploadDir . uniqid() . "_" . $fileName;
        
        if (move_uploaded_file($_FILES['feedback_file']['tmp_name'], $filePath)) {
            $filpath = $filePath;
        }
    }
    
    // Indsæt evaluering
    $stmt = $conn->prepare("
        INSERT INTO Evaluering (Elev_Afl_id, Evaluering_karakter, Feedback, Filpath)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("isss", $elev_afl_id, $karakter, $feedback, $filpath);
    
    if ($stmt->execute()) {
        echo "<p style='color: green;'>Feedback gemt succesfuldt!</p>";
    } else {
        echo "<p style='color: red;'>Fejl: " . $conn->error . "</p>";
    }
}
?>
<!DOCTYPE html>
<html>
<body>

   <a href="index.php">Tilbage</a>

</body>
</html>