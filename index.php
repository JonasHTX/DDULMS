<?php
session_start();
include 'connection.php';

// Tjek login
if (!isset($_SESSION['signed_in']) || $_SESSION['signed_in'] !== true) {
    header("Location: Uni_bruger.php");
    exit();
}

$unilogin = $_SESSION["unilogin"];
$user_name = $_SESSION["user_name"];

// Hent brugerinfo
$stmt = $conn->prepare("SELECT Level, Klasse_id FROM Bruger WHERE Unilogin = ?");
$stmt->bind_param("s", $unilogin);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) die("Bruger ikke fundet");

$user_level = $user['Level'];
$is_teacher = ($user_level == 1);
$klasse_id = $user['Klasse_id'];

$selected_fag = isset($_GET['fag']) ? intval($_GET['fag']) : 0;

// Hent fag til sidebar
if ($is_teacher) {
    $fag_query = $conn->prepare("
        SELECT DISTINCT f.Fag_id, f.Fag_navn 
        FROM Fag f
        JOIN Laerer_info li ON f.Fag_id = li.Fag_id
        WHERE li.Laerer_Unilogin = ?
        ORDER BY f.Fag_navn
    ");
    $fag_query->bind_param("s", $unilogin);
} else {
    $fag_query = $conn->prepare("
        SELECT DISTINCT f.Fag_id, f.Fag_navn 
        FROM Fag f
        JOIN Oprettet_Aflevering oa ON f.Fag_id = oa.Fag_id
        WHERE oa.Klasse_id = ?
        ORDER BY f.Fag_navn
    ");
    $fag_query->bind_param("i", $klasse_id);
}
$fag_query->execute();
$alle_fag = $fag_query->get_result()->fetch_all(MYSQLI_ASSOC);
$fag_query->close();

// Hent data afhængig af brugerrolle
if ($is_teacher) {
    // LÆRER VISNING - Original query
    $sql = "
        SELECT 
            o.Oprettet_Afl_id,
            o.Oprettet_Afl_navn,
            o.Oprettet_Afl_deadline,
            o.Klasse_id,
            f.Fag_navn,
            f.Fag_id,
            COUNT(ea.Elev_Afl_id) AS antal_afleveret,
            (SELECT COUNT(*) FROM Bruger WHERE Klasse_id = o.Klasse_id AND Level = 0) AS antal_elever
        FROM Oprettet_Aflevering o
        JOIN Fag f ON o.Fag_id = f.Fag_id
        JOIN Laerer_info l ON o.Klasse_id = l.Klasse_id AND o.Fag_id = l.Fag_id
        LEFT JOIN Elev_Aflevering ea ON o.Oprettet_Afl_id = ea.Oprettet_Afl_id
        WHERE l.Laerer_Unilogin = ?
    ";
    if ($selected_fag > 0) {
        $sql .= " AND o.Fag_id = ?";
    }
    $sql .= " GROUP BY o.Oprettet_Afl_id ORDER BY o.Oprettet_Afl_deadline DESC";

    $stmt = $conn->prepare($sql);
    if ($selected_fag > 0) {
        $stmt->bind_param("si", $unilogin, $selected_fag);
    } else {
        $stmt->bind_param("s", $unilogin);
    }
    $stmt->execute();
    $afleveringer = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    // ELEV VISNING - To separate queries
    // Hent ikke-afleverede opgaver
    $sql_not_submitted = "
        SELECT 
            o.Oprettet_Afl_id,
            o.Oprettet_Afl_navn,
            o.Oprettet_Afl_deadline,
            o.Klasse_id,
            f.Fag_navn,
            f.Fag_id
        FROM Oprettet_Aflevering o
        JOIN Fag f ON o.Fag_id = f.Fag_id
        WHERE o.Klasse_id = ?
        AND NOT EXISTS (
            SELECT 1 FROM Elev_Aflevering ea 
            WHERE ea.Oprettet_Afl_id = o.Oprettet_Afl_id 
            AND ea.Unilogin = ?
        )
    ";
    if ($selected_fag > 0) {
        $sql_not_submitted .= " AND o.Fag_id = ?";
    }
    $sql_not_submitted .= " ORDER BY o.Oprettet_Afl_deadline DESC";

    // Hent afleverede opgaver - ÆNDRET HER
    $sql_submitted = "
        SELECT 
            o.Oprettet_Afl_id,
            o.Oprettet_Afl_navn,
            o.Oprettet_Afl_deadline,
            o.Klasse_id,
            f.Fag_navn,
            f.Fag_id,
            ea.Elev_Afl_id,  -- TILFØJET
            ea.Elev_Afl_tid,
            ev.Evaluering_karakter,
            ev.Feedback,
            ev.Filpath AS Feedback_fil
        FROM Oprettet_Aflevering o
        JOIN Fag f ON o.Fag_id = f.Fag_id
        JOIN Elev_Aflevering ea ON o.Oprettet_Afl_id = ea.Oprettet_Afl_id
        LEFT JOIN Evaluering ev ON ea.Elev_Afl_id = ev.Elev_Afl_id
        WHERE o.Klasse_id = ?
        AND ea.Unilogin = ?
    ";
    if ($selected_fag > 0) {
        $sql_submitted .= " AND o.Fag_id = ?";
    }
    $sql_submitted .= " ORDER BY o.Oprettet_Afl_deadline DESC";

    // Udfør queries for elev
    $stmt = $conn->prepare($sql_not_submitted);
    if ($selected_fag > 0) {
        $stmt->bind_param("isi", $klasse_id, $unilogin, $selected_fag);
    } else {
        $stmt->bind_param("is", $klasse_id, $unilogin);
    }
    $stmt->execute();
    $afleveringer_ikke_afleveret = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare($sql_submitted);
    if ($selected_fag > 0) {
        $stmt->bind_param("isi", $klasse_id, $unilogin, $selected_fag);
    } else {
        $stmt->bind_param("is", $klasse_id, $unilogin);
    }
    $stmt->execute();
    $afleveringer_afleveret = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="da">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Afleveringsoversigt</title>
    <link rel="stylesheet" href="Header.css">
    <link rel="stylesheet" href="index.css">
</head>

<body>
    <div class="layout-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <h1>Sortér efter fag</h1>
            <ul class="fag-liste">
                <li>
                    <a href="index.php" class="<?= $selected_fag == 0 ? 'active' : '' ?>">
                        Alle fag
                        <span class="antal"><?= $is_teacher ? count($afleveringer) : count($afleveringer_ikke_afleveret) ?> afleveringer</span>
                    </a>
                </li>
                <?php foreach ($alle_fag as $fag):
                    $count = 0;
                    if ($is_teacher) {
                        foreach ($afleveringer as $a) if ($a['Fag_id'] == $fag['Fag_id']) $count++;
                    } else {
                        foreach ($afleveringer_ikke_afleveret as $a) if ($a['Fag_id'] == $fag['Fag_id']) $count++;
                    }
                ?>
                    <li>
                        <a href="?fag=<?= $fag['Fag_id'] ?>" class="<?= $selected_fag == $fag['Fag_id'] ? 'active' : '' ?>">
                            <?= htmlspecialchars($fag['Fag_navn']) ?>
                            <span class="antal"><?= $count ?> afleveringer</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <!-- Content -->
        <div class="content-wrapper">
            <header class="site-header">
                <a href="index.php">
                    <img src="images/logo.png" alt="Logo" class="logo">
                </a>
                <!-- Mobil‑dropdown -->
                <select id="fag-select" class="fag-dropdown">
                    <option value="0" <?= $selected_fag == 0 ? 'selected' : '' ?>>
                        Alle fag (<?= $is_teacher ? count($afleveringer) : count($afleveringer_ikke_afleveret) ?>)
                    </option>
                    <?php foreach ($alle_fag as $fag):
                        $count = 0;
                        if ($is_teacher) {
                            foreach ($afleveringer as $a) if ($a['Fag_id'] == $fag['Fag_id']) $count++;
                        } else {
                            foreach ($afleveringer_ikke_afleveret as $a) if ($a['Fag_id'] == $fag['Fag_id']) $count++;
                        }
                    ?>
                        <option value="<?= $fag['Fag_id'] ?>" <?= $selected_fag == $fag['Fag_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($fag['Fag_navn']) ?> (<?= $count ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </header>

            <main class="main-content">
                <h1>Hej <?= htmlspecialchars($user_name) ?></h1>
                <?php if ($user_level == 2): ?>
                    <a href="admin.php"><button>Gå til Admin Panel</button></a>
                <?php endif; ?>

                <div class="afleverings-wrapper">
                    <?php if ($is_teacher): ?>
                        <div class="column ikke-afleveret">
                            <h3>Afleveringer:</h3>
                            <div class="scroll-box">
                                <?php if (!empty($afleveringer)): ?>
                                    <ul class="afleveringsliste">
                                        <?php foreach ($afleveringer as $afl): ?>
                                            <li>
                                                <a href="Evaluering.php?oprettet_afl_id=<?= $afl['Oprettet_Afl_id'] ?>" class="opgave-link">
                                                    <?= htmlspecialchars($afl['Oprettet_Afl_navn']) ?>
                                                </a>
                                                <span class="fag-navn"><?= htmlspecialchars($afl['Fag_navn']) ?></span>
                                                <span class="deadline">
                                                    Deadline: <?= (new DateTime($afl['Oprettet_Afl_deadline']))->format('d M Y \k\l H:i') ?>
                                                </span>
                                                <span class="status">
                                                    Afleveret: <?= $afl['antal_afleveret'] ?>/<?= $afl['antal_elever'] ?>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p>Ingen afleveringer endnu.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="column ikke-afleveret">
                            <h3>Mangler at aflevere:</h3>
                            <div class="scroll-box">
                                <?php if (!empty($afleveringer_ikke_afleveret)): ?>
                                    <ul class="afleveringsliste">
                                        <?php foreach ($afleveringer_ikke_afleveret as $afl): ?>
                                            <li>
                                                <a href="Afleveringer.php?oprettet_afl_id=<?= $afl['Oprettet_Afl_id'] ?>" class="opgave-titel">
                                                    <?= htmlspecialchars($afl['Oprettet_Afl_navn']) ?>
                                                </a>
                                                <span class="fag-navn"><?= htmlspecialchars($afl['Fag_navn']) ?></span>
                                                <span class="deadline">
                                                    Deadline: <?= (new DateTime($afl['Oprettet_Afl_deadline']))->format('d M Y \k\l H:i') ?>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p>Ingen afleveringer mangler.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="column afleveret">
                            <h3>Afleveret:</h3>
                            <div class="scroll-box">
                                <?php if (!empty($afleveringer_afleveret)): ?>
                                    <ul class="afleveringsliste">
                                        <?php foreach ($afleveringer_afleveret as $afl): ?>
                                            <li>
                                                <a href="Afleveringer.php?elev_afl_id=<?= $afl['Elev_Afl_id'] ?>" class="opgave-titel">
                                                    <?= htmlspecialchars($afl['Oprettet_Afl_navn']) ?>
                                                </a>
                                                <span class="fag-navn"><?= htmlspecialchars($afl['Fag_navn']) ?></span>
                                                <span class="afleveret-tid">
                                                    Afleveret: <?= (new DateTime($afl['Elev_Afl_tid']))->format('d M Y \k\l H:i') ?>
                                                </span>
                                                <?php if (!empty($afl['Evaluering_karakter'])): ?>
                                                    <span class="karakter">
                                                        Karakter: <?= htmlspecialchars($afl['Evaluering_karakter']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p>Ingen afleveringer endnu.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($is_teacher): ?>
                    <div class="aflevere-knap">
                        <button class="btn-opret" onclick="location.href='Opretaflevering.php'">Ny aflevering</button>
                    </div>
                <?php endif; ?>

                <a href="logout.php"><button>Log ud</button></a>
            </main>
        </div>
    </div>

    <script>
        // Naviger til valgt fag på mobil
        document.getElementById('fag-select')?.addEventListener('change', function() {
            window.location.search = '?fag=' + this.value;
        });
    </script>
</body>

</html>