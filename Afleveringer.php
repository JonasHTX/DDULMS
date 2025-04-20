<?php
session_start();
include 'connection.php';

if (!isset($_SESSION['unilogin'])) {
    die("Du skal være logget ind!");
}

$unilogin = $_SESSION['unilogin'];
$level     = $_SESSION['level']; // 0 = elev, 1 = lærer/admin

// --- 1) Hent aflevering eller opgave ID ---
if (isset($_GET['elev_afl_id'])) {
    // Detail-view på en elevs aflevering
    $elev_afl_id = intval($_GET['elev_afl_id']);

    if ($level == 1) {
        // Lærer ser kun afleveringer fra deres egne klasser/fag
        $stmt = $conn->prepare("
            SELECT ea.*, b.Navn AS Elev_navn, o.Oprettet_Afl_id, o.Oprettet_Afl_navn, o.Oprettet_Afl_deadline,
                   f.Fag_navn, f.Fag_id,
                   ev.Evaluering_karakter, ev.Feedback, ev.Filpath AS feedback_fil
            FROM Elev_Aflevering ea
            JOIN Bruger b ON ea.Unilogin = b.Unilogin
            JOIN Oprettet_Aflevering o ON ea.Oprettet_Afl_id = o.Oprettet_Afl_id
            JOIN Fag f ON o.Fag_id = f.Fag_id
            LEFT JOIN Evaluering ev ON ea.Elev_Afl_id = ev.Elev_Afl_id
            JOIN Laerer_info li ON o.Klasse_id = li.Klasse_id AND li.Laerer_Unilogin = ?
            WHERE ea.Elev_Afl_id = ?
        ");
        $stmt->bind_param("si", $unilogin, $elev_afl_id);
    } else {
        // Elev ser kun sin egen aflevering
        $stmt = $conn->prepare("
            SELECT ea.*, o.Oprettet_Afl_id, o.Oprettet_Afl_navn, o.Oprettet_Afl_deadline,
                   f.Fag_navn, f.Fag_id,
                   ev.Evaluering_karakter, ev.Feedback, ev.Filpath AS feedback_fil
            FROM Elev_Aflevering ea
            JOIN Oprettet_Aflevering o ON ea.Oprettet_Afl_id = o.Oprettet_Afl_id
            JOIN Fag f ON o.Fag_id = f.Fag_id
            LEFT JOIN Evaluering ev ON ea.Elev_Afl_id = ev.Elev_Afl_id
            WHERE ea.Elev_Afl_id = ? AND ea.Unilogin = ?
        ");
        $stmt->bind_param("is", $elev_afl_id, $unilogin);
    }
    $stmt->execute();
    $aflevering = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$aflevering) {
        die("Aflevering ikke fundet eller du har ikke adgang.");
    }

    $oprettet_afl_id = $aflevering['Oprettet_Afl_id'];
    $afleveret       = true;
    $har_evaluering  = !empty($aflevering['Evaluering_karakter']);
} elseif (isset($_GET['oprettet_afl_id'])) {
    // Standard flow: vis opgaveoplysninger og evt. upload/manglende aflevering
    $oprettet_afl_id = intval($_GET['oprettet_afl_id']);

    $stmt = $conn->prepare("SELECT * FROM Oprettet_Aflevering WHERE Oprettet_Afl_id = ?");
    $stmt->bind_param("i", $oprettet_afl_id);
    $stmt->execute();
    $opgave = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$opgave) {
        die("Opgaven findes ikke.");
    }

    $afleveret = false;
    if ($level == 0) {
        // Tjek om elev har afleveret
        $stmt = $conn->prepare("
            SELECT 1 FROM Elev_Aflevering
            WHERE Oprettet_Afl_id = ? AND Unilogin = ?
        ");
        $stmt->bind_param("is", $oprettet_afl_id, $unilogin);
        $stmt->execute();
        $afleveret = $stmt->get_result()->fetch_assoc() ? true : false;
        $stmt->close();
    }
} else {
    die("Ugyldigt opgave ID.");
}

// Hvis $opgave ikke er sat (for elev_afl_id‑flow), hent det alligevel
if (!isset($opgave)) {
    $stmt = $conn->prepare("SELECT * FROM Oprettet_Aflevering WHERE Oprettet_Afl_id = ?");
    $stmt->bind_param("i", $oprettet_afl_id);
    $stmt->execute();
    $opgave = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// --- 2) Håndter upload for elever ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['aflevering_fil']) && !$afleveret) {
    $upload_dir = 'uploads/';
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

    $file_name   = basename($_FILES['aflevering_fil']['name']);
    $upload_file = $upload_dir . uniqid() . '_' . $file_name;

    if (move_uploaded_file($_FILES['aflevering_fil']['tmp_name'], $upload_file)) {
        $stmt = $conn->prepare("
            INSERT INTO Elev_Aflevering (Oprettet_Afl_id, Unilogin, Elev_Afl_tid, Filpath) 
            VALUES (?, ?, NOW(), ?)
        ");
        $stmt->bind_param("iss", $oprettet_afl_id, $unilogin, $upload_file);
        $stmt->execute();
        $stmt->close();
        header("Location: ?oprettet_afl_id=$oprettet_afl_id");
        exit();
    } else {
        $upload_error = "Fejl ved upload af fil.";
    }
}

// --- 3) Hent sidebar‑liste (5 seneste) ---
if ($level == 0) {
    // Elev: manglende afleveringer
    $stmt = $conn->prepare("
        SELECT o.Oprettet_Afl_id, o.Oprettet_Afl_navn, o.Oprettet_Afl_deadline, f.Fag_navn
        FROM Oprettet_Aflevering o
        JOIN Fag f ON o.Fag_id = f.Fag_id
        WHERE o.Klasse_id = (SELECT Klasse_id FROM Bruger WHERE Unilogin = ?)
          AND NOT EXISTS (
            SELECT 1 FROM Elev_Aflevering ea 
             WHERE ea.Oprettet_Afl_id = o.Oprettet_Afl_id
               AND ea.Unilogin = ?
          )
        ORDER BY o.Oprettet_Afl_deadline DESC
        LIMIT 5
    ");
    $stmt->bind_param("ss", $unilogin, $unilogin);
} else {
    // Lærer/admin: afleveringer uden evaluering
    $stmt = $conn->prepare("
        SELECT DISTINCT o.Oprettet_Afl_id, o.Oprettet_Afl_navn, o.Oprettet_Afl_deadline, f.Fag_navn
        FROM Oprettet_Aflevering o
        JOIN Fag f ON o.Fag_id = f.Fag_id
        JOIN Elev_Aflevering ea ON o.Oprettet_Afl_id = ea.Oprettet_Afl_id
        LEFT JOIN Evaluering ev ON ea.Elev_Afl_id = ev.Elev_Afl_id
        WHERE o.Klasse_id IN (
          SELECT Klasse_id FROM Laerer_info WHERE Laerer_Unilogin = ?
        )
          AND ev.Elev_Afl_id IS NULL
        ORDER BY o.Oprettet_Afl_deadline DESC
        LIMIT 5
    ");
    $stmt->bind_param("s", $unilogin);
}
$stmt->execute();
$ikke_klarede_opgaver = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="da">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aflever opgave</title>
    <link rel="stylesheet" href="Afleveringer.css">
    <link rel="stylesheet" href="Header.css">
</head>

<body>
    <div class="left-sidebar">
        <h2>Afleveringer</h2>
        <ul class="sidebar-opgaver">
            <?php if ($ikke_klarede_opgaver): ?>
                <?php foreach ($ikke_klarede_opgaver as $afl): ?>
                    <li>
                        <a href="?oprettet_afl_id=<?= $afl['Oprettet_Afl_id'] ?>"
                            class="opgave-link">
                            <div class="opgave-titel"><?= htmlspecialchars($afl['Oprettet_Afl_navn']) ?></div>
                            <div class="opgave-fag"><?= htmlspecialchars($afl['Fag_navn']) ?></div>
                            <div class="opgave-deadline">
                                Deadline: <?= (new DateTime($afl['Oprettet_Afl_deadline']))->format('d M Y \k\l H:i') ?>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <li class="no-opgaver">
                    Ingen <?= $level == 0 ? 'manglende afleveringer' : 'opgaver at rette' ?>
                </li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="main-area">
        <header class="site-header">
            <a href="index.php"><img src="images/logo.png" alt="Logo" class="logo"></a>
            <select id="opgave-select" class="opgave-dropdown">
                <option value="">Vælg opgave…</option>
                <?php foreach ($ikke_klarede_opgaver as $afl): ?>
                    <option value="<?= $afl['Oprettet_Afl_id'] ?>"
                        <?= ($afl['Oprettet_Afl_id'] == $oprettet_afl_id ? 'selected' : '') ?>>
                        <?= htmlspecialchars($afl['Oprettet_Afl_navn']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </header>

        <section class="content-section">
            <h2 class="opgave-overskrift"><?= htmlspecialchars($opgave['Oprettet_Afl_navn']) ?></h2>

            <?php if ($level == 0): // Elev visning 
            ?>
                <div class="two-column-layout">
                    <div class="upload-column">
                        <?php if ($afleveret): ?>
                            <div class="evaluering-card">
                                <h3>Din aflevering</h3>
                                <p><strong>Afleveret:</strong>
                                    <?= (new DateTime($aflevering['Elev_Afl_tid']))->format('d M Y \k\l H:i') ?>
                                </p>
                                <?php if (!empty($aflevering['Filpath'])): ?>
                                    <p><a href="<?= htmlspecialchars($aflevering['Filpath']) ?>" target="_blank">
                                            Download din aflevering
                                        </a></p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="upload-card">
                                <div class="deadline-label">
                                    Tidsfrist: <?= (new DateTime($opgave['Oprettet_Afl_deadline']))->format('d M Y \k\l H:i') ?>
                                </div>
                                <form method="POST" enctype="multipart/form-data" class="upload-form">
                                    <input type="file" name="aflevering_fil" required>
                                    <button type="submit" class="besvar-knap">Besvar</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="evaluation-column">
                        <?php if ($afleveret): ?>
                            <?php if ($har_evaluering): ?>
                                <div class="evaluering-container">
                                    <h3>Feedback fra lærer</h3>
                                    <p><strong>Karakter:</strong>
                                        <?= htmlspecialchars($aflevering['Evaluering_karakter'] ?? '') ?>
                                    </p>
                                    <div class="feedback-text">
                                        <?= nl2br(htmlspecialchars($aflevering['Feedback'] ?? '')) ?>
                                    </div>
                                    <?php if (!empty($aflevering['feedback_fil'])): ?>
                                        <p><a href="<?= htmlspecialchars($aflevering['feedback_fil']) ?>" download>
                                                Download feedback‑fil
                                            </a></p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="evaluering-container">
                                    <h3>Feedback fra lærer</h3>
                                    <p>Afventer evaluering</p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: // Lærer/admin visning 
            ?>
                <?php if (isset($aflevering)): // Visning af specifik elevs aflevering 
                ?>
                    <div class="evaluering-container">
                        <h3>Evaluering for <?= htmlspecialchars($aflevering['Elev_navn'] ?? '') ?></h3>
                        <?php if ($har_evaluering): ?>
                            <p><strong>Karakter:</strong> <?= htmlspecialchars($aflevering['Evaluering_karakter']) ?></p>
                            <div class="feedback-text">
                                <?= nl2br(htmlspecialchars($aflevering['Feedback'])) ?>
                            </div>
                            <?php if (!empty($aflevering['feedback_fil'])): ?>
                                <p><a href="<?= htmlspecialchars($aflevering['feedback_fil']) ?>" download>
                                        Download feedback‑fil
                                    </a></p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p>Ingen evaluering endnu</p>
                        <?php endif; ?>
                        <p><a href="Evaluering.php?elev_afl_id=<?= $elev_afl_id ?>">
                                <?= $har_evaluering ? 'Rediger evaluering' : 'Opret evaluering' ?>
                            </a></p>
                        <p><a href="?oprettet_afl_id=<?= $oprettet_afl_id ?>">← Tilbage til alle afleveringer</a></p>
                    </div>
                <?php else: // Normal visning af alle afleveringer 
                ?>
                    <h3>Afleveringer fra elever</h3>
                    <?php
                    $stmt = $conn->prepare("
                        SELECT ea.*, b.Navn
                        FROM Elev_Aflevering ea
                        JOIN Bruger b ON ea.Unilogin = b.Unilogin
                        WHERE ea.Oprettet_Afl_id = ?
                        ORDER BY b.Navn
                    ");
                    $stmt->bind_param("i", $oprettet_afl_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    ?>
                    <?php if ($res->num_rows): ?>
                        <table class="afleverings-tabel">
                            <thead>
                                <tr>
                                    <th>Elev</th>
                                    <th>Afleveret</th>
                                    <th>Fil</th>
                                    <th>Status</th>
                                    <th>Handling</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $res->fetch_assoc()):
                                    $eval_s = $conn->prepare("SELECT 1 FROM Evaluering WHERE Elev_Afl_id=?");
                                    $eval_s->bind_param("i", $row['Elev_Afl_id']);
                                    $eval_s->execute();
                                    $has_ev = $eval_s->get_result()->num_rows > 0;
                                    $eval_s->close();
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['Navn']) ?></td>
                                        <td><?= htmlspecialchars($row['Elev_Afl_tid']) ?></td>
                                        <td><a href="<?= htmlspecialchars($row['Filpath']) ?>" target="_blank">Download</a></td>
                                        <td><?= $has_ev ? 'Evalueret' : 'Afventer' ?></td>
                                        <td>
                                            <a href="?elev_afl_id=<?= $row['Elev_Afl_id'] ?>">
                                                <?= $has_ev ? 'Se evaluering' : 'Giv evaluering' ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>Ingen afleveringer endnu.</p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <p><a href="index.php" class="tilbage-knap">← Tilbage til Forside</a></p>
        </section>
    </div>

    <script>
        document.getElementById('opgave-select')?.addEventListener('change', function() {
            if (this.value) {
                window.location.search = '?oprettet_afl_id=' + this.value;
            }
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>