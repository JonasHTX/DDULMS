<?php
session_start();
include 'connection.php';

// Tjek login
if (!isset($_SESSION['signed_in']) || $_SESSION['signed_in'] !== true) {
    header("Location: Uni_bruger.php");
    exit();
}

$opgave_id = intval($_GET['id']);
$unilogin = $_SESSION["unilogin"];

// Hent afleveringsdata
$stmt = $conn->prepare("
    SELECT 
        o.Oprettet_Afl_navn,
        f.Fag_navn,
        ea.Elev_Afl_tid,
        ea.Filpath,
        ev.Evaluering_karakter,
        ev.Feedback,
        ev.Filpath AS Feedback_fil
    FROM Elev_Aflevering ea
    JOIN Oprettet_Aflevering o ON ea.Oprettet_Afl_id = o.Oprettet_Afl_id
    JOIN Fag f ON o.Fag_id = f.Fag_id
    LEFT JOIN Evaluering ev ON ea.Elev_Afl_id = ev.Elev_Afl_id
    WHERE ea.Oprettet_Afl_id = ? 
    AND ea.Unilogin = ?
");
$stmt->bind_param("is", $opgave_id, $unilogin);
$stmt->execute();
$aflevering = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$aflevering) {
    die("Aflevering ikke fundet eller du har ikke adgang");
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="da">

<head>
    <meta charset="UTF-8">
    <title>Din aflevering</title>
    <link rel="stylesheet" href="index.css">
</head>

<body>
    <div class="container">
        <h1><?= htmlspecialchars($aflevering['Oprettet_Afl_navn']) ?></h1>
        <p>Fag: <?= htmlspecialchars($aflevering['Fag_navn']) ?></p>
        <p>Afleveret: <?= htmlspecialchars($aflevering['Elev_Afl_tid']) ?></p>

        <?php if (!empty($aflevering['Filpath'])): ?>
            <h3>Din afleverede fil:</h3>
            <a href="uploads/<?= htmlspecialchars(basename($aflevering['Filpath'])) ?>" download>
                Download din aflevering
            </a>
        <?php endif; ?>

        <?php if (!empty($aflevering['Evaluering_karakter'])): ?>
            <div class="feedback">
                <h3>Feedback</h3>
                <p>Karakter: <?= htmlspecialchars($aflevering['Evaluering_karakter']) ?></p>
                <p>Feedback: <?= nl2br(htmlspecialchars($aflevering['Feedback'])) ?></p>

                <?php if (!empty($aflevering['Feedback_fil'])): ?>
                    <a href="uploads/feedback/<?= htmlspecialchars(basename($aflevering['Feedback_fil'])) ?>" download>
                        Download feedback fil
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p>Denne aflevering er endnu ikke blevet evalueret.</p>
        <?php endif; ?>

        <a href="index.php">Tilbage til oversigten</a>
    </div>
</body>

</html>