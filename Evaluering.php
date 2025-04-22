<?php
// Start session and output buffering at the VERY TOP
ob_start();
session_start();

// Then include other files
include 'connection.php';

// Aktiver fejlrapportering
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Tjek om filen inkluderes eller åbnes direkte
$is_included = (strpos($_SERVER['PHP_SELF'], 'Evaluering.php') === false);

// Tjek login
if (!isset($_SESSION['unilogin'])) {
    if ($is_included) {
        die(); // Silent fail hvis inkluderet uden session
    } else {
        header("Location: login.php");
        exit();
    }
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
        visFeedbackFormular($aflevering, $is_included);
    } else {
        visElevAflevering($aflevering, $is_included);
    }
} elseif (isset($_GET['oprettet_afl_id']) && $is_teacher) {
    // Vis alle afleveringer til en opgave (kun for lærere)
    $oprettet_afl_id = intval($_GET['oprettet_afl_id']);

    // Først hent opgaveinfo for at tjekke om læreren har adgang
    $stmt = $conn->prepare("
        SELECT o.*, f.Fag_navn 
        FROM Oprettet_Aflevering o
        JOIN Fag f ON o.Fag_id = f.Fag_id
        WHERE o.Oprettet_Afl_id = ? 
        AND EXISTS (
            SELECT 1 FROM Laerer_info 
            WHERE Laerer_info.Laerer_Unilogin = ? 
            AND Laerer_info.Klasse_id = o.Klasse_id
            AND Laerer_info.Fag_id = o.Fag_id
        )
    ");
    $stmt->bind_param("is", $oprettet_afl_id, $current_user);
    $stmt->execute();
    $result = $stmt->get_result();
    $opgave = $result->fetch_assoc();
    $stmt->close();

    if (!$opgave) {
        die("Du har ikke adgang til denne opgave.");
    }

    $afleveringer = hentAfleveringerTilOpgave($conn, $oprettet_afl_id);
    visTreKolonnerLayout($afleveringer, $opgave, $is_included);
} else {
    // Standardvisning
    if ($is_teacher) {
        $afleveringer = hentAfleveringerManglerEvaluering($conn);
        visAfleveringsListe($afleveringer, true, null, $is_included);
    } else {
        $afleveringer = hentMineAfleveringer($conn, $current_user);
        visAfleveringsListe($afleveringer, false, null, $is_included);
    }
}

// Flush output buffer at the end
ob_end_flush();
$conn->close();

/*** FUNKTIONER ***/

function hentAflevering($conn, $elev_afl_id, $current_user, $is_teacher)
{
    $stmt = $conn->prepare(
        "
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
    // Først: Hent alle elever i klassen der skal aflevere
    $stmt = $conn->prepare("
        SELECT b.Unilogin, b.Navn AS Elev_navn, k.Klasse_navn
        FROM Bruger b
        JOIN Klasse k ON b.Klasse_id = k.Klasse_id
        JOIN Oprettet_Aflevering o ON b.Klasse_id = o.Klasse_id
        WHERE o.Oprettet_Afl_id = ? AND b.Level = 0
        ORDER BY b.Navn
    ");
    $stmt->bind_param("i", $oprettet_afl_id);
    $stmt->execute();
    $alle_elever = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Så: Hent alle afleveringer for denne opgave med feedback detaljer
    $stmt = $conn->prepare("
        SELECT ea.Elev_Afl_id, ea.Unilogin, ea.Elev_Afl_tid, 
               b.Navn AS Elev_navn, k.Klasse_navn, ev.Evaluering_id, 
               ev.Evaluering_karakter, ev.Feedback, ev.Filpath AS feedback_fil
        FROM Elev_Aflevering ea
        JOIN Bruger b ON ea.Unilogin = b.Unilogin
        JOIN Klasse k ON b.Klasse_id = k.Klasse_id
        LEFT JOIN Evaluering ev ON ea.Elev_Afl_id = ev.Elev_Afl_id
        WHERE ea.Oprettet_Afl_id = ?
        ORDER BY b.Navn
    ");
    $stmt->bind_param("i", $oprettet_afl_id);
    $stmt->execute();
    $afleveringer = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Kombiner data for at vise alle elever med status
    $result = [];
    foreach ($alle_elever as $elev) {
        $elev_data = [
            'Unilogin' => $elev['Unilogin'],
            'Elev_navn' => $elev['Elev_navn'],
            'Klasse_navn' => $elev['Klasse_navn'],
            'Status' => 'Mangler at aflevere',
            'Elev_Afl_id' => null,
            'Elev_Afl_tid' => null,
            'Evaluering_id' => null,
            'Evaluering_karakter' => null,
            'Feedback' => null,
            'feedback_fil' => null
        ];

        foreach ($afleveringer as $afl) {
            if ($afl['Unilogin'] == $elev['Unilogin']) {
                $elev_data['Status'] = $afl['Evaluering_id'] ? 'Evalueret' : 'Afleveret';
                $elev_data['Elev_Afl_id'] = $afl['Elev_Afl_id'];
                $elev_data['Elev_Afl_tid'] = $afl['Elev_Afl_tid'];
                $elev_data['Evaluering_id'] = $afl['Evaluering_id'];
                $elev_data['Evaluering_karakter'] = $afl['Evaluering_karakter'];
                $elev_data['Feedback'] = $afl['Feedback'];
                $elev_data['feedback_fil'] = $afl['feedback_fil'];
                break;
            }
        }

        $result[] = $elev_data;
    }

    return $result;
}

function visTreKolonnerLayout($afleveringer, $opgave, $is_included)
{
    $ikke_afleveret = [];
    $afleveret = [];
    $evalueret = [];

    foreach ($afleveringer as $afl) {
        if ($afl['Status'] === 'Mangler at aflevere') {
            $ikke_afleveret[] = $afl;
        } elseif ($afl['Status'] === 'Afleveret') {
            $afleveret[] = $afl;
        } elseif ($afl['Status'] === 'Evalueret') {
            $evalueret[] = $afl;
        }
    }
?>
    <!DOCTYPE html>
    <html lang="da">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Afleveringsstatus</title>
        <link rel="stylesheet" href="TreKolonner.css">
        <link rel="stylesheet" href="Header.css">
    </head>

    <body>

        <header class="site-header">
            <a href="index.php">
                <img src="images/logo.png" alt="Logo" class="logo">
            </a>
        </header>

        <div class="page-wrapper">
            <h2>Afleveringsstatus for: <?= htmlspecialchars($opgave['Oprettet_Afl_navn']) ?></h2>
            <p>Fag: <?= htmlspecialchars($opgave['Fag_navn']) ?> | Klasse: <?= htmlspecialchars($opgave['Klasse_id']) ?></p>

            <div class="container">
                <!-- Kolonne 1: Elever der ikke har afleveret -->
                <div class="column ikke-afleveret">
                    <h3>Ikke afleveret</h3>
                    <?php if (!empty($ikke_afleveret)): ?>
                        <div class="scroll-box">
                            <ul class="elev-liste">
                                <?php foreach ($ikke_afleveret as $elev): ?>
                                    <li>
                                        <span class="elev-navn"><?= htmlspecialchars($elev['Elev_navn']) ?></span><br>
                                        <span class="ingen-aflevering">Ingen aflevering</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <p style="color: #28a745;">Alle elever har afleveret!</p>
                    <?php endif; ?>
                </div>

                <!-- Kolonne 2: Elever der har afleveret (venter på evaluering) -->
                <div class="column afleveret">
                    <h3>Afleveret – venter bedømmelse</h3>
                    <?php if (!empty($afleveret)): ?>
                        <div class="scroll-box">
                            <ul class="elev-liste">
                                <?php foreach ($afleveret as $elev): ?>
                                    <li>
                                        <a href="Evaluering.php?elev_afl_id=<?= $elev['Elev_Afl_id'] ?>">
                                            <?= htmlspecialchars($elev['Elev_navn']) ?>
                                        </a> <br>
                                        <small>Afleveret: <?= htmlspecialchars($elev['Elev_Afl_tid']) ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <p>Ingen afleveringer venter på bedømmelse</p>
                    <?php endif; ?>
                </div>

                <!-- Kolonne 3: Elever der er evalueret -->
<div class="column evalueret">
    <h3>Evalueret</h3>
    <?php if (!empty($evalueret)): ?>
        <div class="scroll-box">
            <ul class="elev-liste">
                <?php foreach ($evalueret as $elev): ?>
                    <li class="evaluering-item">
                        <div class="elev-info">
                            <span class="elev-navn"><?= htmlspecialchars($elev['Elev_navn']) ?></span>
                            <span class="karakter"><?= htmlspecialchars($elev['Evaluering_karakter']) ?></span>
                        </div>
                        <?php if (!empty($elev['Feedback'])): ?>
                            <div class="feedback-tekst">
                                <?= nl2br(htmlspecialchars($elev['Feedback'])) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($elev['feedback_fil'])): ?>
                            <div class="feedback-fil">
                                <a href="<?= htmlspecialchars($elev['feedback_fil']) ?>" download>
                                    Download feedback-fil
                                </a>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>
        <p>Ingen afleveringer er endnu evalueret</p>
    <?php endif; ?>
</div>
            </div>

            <?php if (!$is_included): ?>
                <p><a href="index.php">Tilbage til alle afleveringer</a></p>
            <?php endif; ?>
        </div>

    </body>

    </html>

<?php
}

function hentAfleveringerManglerEvaluering($conn)
{
    $stmt = $conn->prepare("
        SELECT ea.Elev_Afl_id, o.Oprettet_Afl_navn, ea.Elev_Afl_tid, 
               b.Navn AS Elev_navn, k.Klasse_navn, f.Fag_navn,
               o.Oprettet_Afl_id
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

function hentMineAfleveringer($conn, $current_user)
{
    $stmt = $conn->prepare("
        SELECT ea.Elev_Afl_id, o.Oprettet_Afl_navn, ea.Elev_Afl_tid, 
               f.Fag_navn, ev.Evaluering_karakter, ev.Feedback,
               ev.Filpath AS Feedback_fil, o.Oprettet_Afl_id
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

function visAfleveringsListe($afleveringer, $is_teacher, $opgave = null, $is_included = false)
{
    if ($is_teacher) {
        if (isset($_GET['oprettet_afl_id']) && $opgave) {
            echo "<h2>Afleveringsstatus for: " . htmlspecialchars($opgave['Oprettet_Afl_navn']) . "</h2>";
            echo "<p>Fag: " . htmlspecialchars($opgave['Fag_navn']) . " | Klasse: " . htmlspecialchars($opgave['Klasse_id']) . "</p>";
        } else {
            echo "<h2>Afleveringer der venter på evaluering</h2>";
        }
    } else {
        echo "<h2>Mine afleveringer</h2>";
    }

    if (empty($afleveringer)) {
        echo "<p>Ingen afleveringer fundet.</p>";
        return;
    }

    echo "<table border='1' style='width:100%; border-collapse:collapse; margin-top:20px;'>";

    if ($is_teacher) {
        if (isset($_GET['oprettet_afl_id'])) {
            echo "<tr>
                    <th style='padding:8px;'>Elev</th>
                    <th style='padding:8px;'>Klasse</th>
                    <th style='padding:8px;'>Status</th>
                    <th style='padding:8px;'>Afleveret</th>
                    <th style='padding:8px;'>Karakter</th>
                    <th style='padding:8px;'>Handling</th>
                  </tr>";

            foreach ($afleveringer as $afl) {
                $status_class = '';
                if ($afl['Status'] === 'Mangler at aflevere') {
                    $status_class = 'mangler';
                } elseif ($afl['Status'] === 'Afleveret') {
                    $status_class = 'afleveret';
                } elseif ($afl['Status'] === 'Evalueret') {
                    $status_class = 'evalueret';
                }

                echo "<tr class='$status_class'>";
                echo "<td style='padding:8px;'>" . htmlspecialchars($afl['Elev_navn']) . "</td>";
                echo "<td style='padding:8px;'>" . htmlspecialchars($afl['Klasse_navn']) . "</td>";
                echo "<td style='padding:8px;'>" . htmlspecialchars($afl['Status']) . "</td>";
                echo "<td style='padding:8px;'>" . ($afl['Elev_Afl_tid'] ? htmlspecialchars($afl['Elev_Afl_tid']) : '-') . "</td>";
                echo "<td style='padding:8px;'>" . ($afl['Evaluering_karakter'] ? htmlspecialchars($afl['Evaluering_karakter']) : '-') . "</td>";

                if ($afl['Status'] === 'Afleveret') {
                    echo "<td style='padding:8px;'><a href='Evaluering.php?elev_afl_id=" . $afl['Elev_Afl_id'] . "'>Giv feedback</a></td>";
                } elseif ($afl['Status'] === 'Evalueret') {
                    echo "<td style='padding:8px;'><a href='Evaluering.php?elev_afl_id=" . $afl['Elev_Afl_id'] . "'>Se feedback</a></td>";
                } else {
                    echo "<td style='padding:8px;'>-</td>";
                }

                echo "</tr>";
            }
        } else {
            echo "<tr>
                    <th style='padding:8px;'>Elev</th>
                    <th style='padding:8px;'>Klasse</th>
                    <th style='padding:8px;'>Opgave</th>
                    <th style='padding:8px;'>Afleveret</th>
                    <th style='padding:8px;'>Handling</th>
                  </tr>";

            foreach ($afleveringer as $afl) {
                echo "<tr>";
                echo "<td style='padding:8px;'>" . htmlspecialchars($afl['Elev_navn']) . "</td>";
                echo "<td style='padding:8px;'>" . htmlspecialchars($afl['Klasse_navn']) . "</td>";
                echo "<td style='padding:8px;'>" . htmlspecialchars($afl['Oprettet_Afl_navn']) . "</td>";
                echo "<td style='padding:8px;'>" . htmlspecialchars($afl['Elev_Afl_tid']) . "</td>";
                echo "<td style='padding:8px;'><a href='Evaluering.php?elev_afl_id=" . $afl['Elev_Afl_id'] . "'>Giv feedback</a></td>";
                echo "</tr>";
            }
        }
    } else {
        echo "<tr>
                <th style='padding:8px;'>Opgave</th>
                <th style='padding:8px;'>Fag</th>
                <th style='padding:8px;'>Afleveret</th>
                <th style='padding:8px;'>Status</th>
                <th style='padding:8px;'>Handling</th>
              </tr>";

        foreach ($afleveringer as $afl) {
            echo "<tr>";
            echo "<td style='padding:8px;'>" . htmlspecialchars($afl['Oprettet_Afl_navn']) . "</td>";
            echo "<td style='padding:8px;'>" . htmlspecialchars($afl['Fag_navn']) . "</td>";
            echo "<td style='padding:8px;'>" . htmlspecialchars($afl['Elev_Afl_tid']) . "</td>";

            if (isset($afl['Evaluering_karakter'])) {
                echo "<td style='padding:8px;'>Evalueret (" . htmlspecialchars($afl['Evaluering_karakter']) . ")</td>";
                echo "<td style='padding:8px;'><a href='Evaluering.php?elev_afl_id=" . $afl['Elev_Afl_id'] . "'>Se detaljer</a></td>";
            } else {
                echo "<td style='padding:8px;'>Afventer evaluering</td>";
                echo "<td style='padding:8px;'>-</td>";
            }

            echo "</tr>";
        }
    }

    echo "</table>";

    if (!$is_included) {
        if ($is_teacher && isset($_GET['oprettet_afl_id'])) {
            echo "<p><a href='Evaluering.php'>Tilbage til alle afleveringer</a></p>";
        } elseif ($is_teacher) {
            echo "<p><a href='index.php'>Tilbage</a></p>";
        } else {
            echo "<p><a href='index.php'>Tilbage</a></p>";
        }
    }
}

function visFeedbackFormular($aflevering, $is_included = false)
{
?>
    <!DOCTYPE html>
    <html lang="da">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Giv Feedback</title>
        <link rel="stylesheet" href="Header.css">
        <link rel="stylesheet" href="Evaluering.css">
    </head>

    <body>
        <header class="site-header">
            <a href="index.php">
                <img src="images/logo.png" alt="Logo" class="logo">
            </a>
        </header>

        <main class="feedback-page">
            <h1>Giv Feedback</h1>

            <section class="submission-info">
                <h2>Aflevering</h2>
                <table class="info-table">
                    <tr>
                        <th>Elev:</th>
                        <td><?= htmlspecialchars($aflevering['Elev_navn']) ?></td>
                    </tr>
                    <tr>
                        <th>Klasse:</th>
                        <td><?= htmlspecialchars($aflevering['Klasse_navn']) ?></td>
                    </tr>
                    <tr>
                        <th>Fag:</th>
                        <td><?= htmlspecialchars($aflevering['Fag_navn']) ?></td>
                    </tr>
                    <tr>
                        <th>Opgave:</th>
                        <td><?= htmlspecialchars($aflevering['Oprettet_Afl_navn']) ?></td>
                    </tr>
                    <tr>
                        <th>Afleveret:</th>
                        <td><?= htmlspecialchars($aflevering['Elev_Afl_tid']) ?></td>
                    </tr>
                </table>

                <?php if (!empty($aflevering['Filpath'])): ?>
                    <p>
                        <a href="uploads/<?= htmlspecialchars(basename($aflevering['Filpath'])) ?>"
                            target="_blank" class="download-link">
                            Download aflevering
                        </a>
                    </p>
                <?php endif; ?>
            </section>

            <section class="feedback-form-section">
                <h2>Feedbackformular</h2>
                <form method="post" enctype="multipart/form-data" class="feedback-form">
                    <input type="hidden" name="elev_afl_id" value="<?= $aflevering['Elev_Afl_id'] ?>">

                    <div class="form-group">
                        <label for="karakter">Karakter:</label>
                        <input type="text" id="karakter" name="karakter" required>
                    </div>

                    <div class="form-group">
                        <label for="feedback">Feedback:</label>
                        <textarea id="feedback" name="feedback" required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="feedback_file">Feedbackfil (valgfri):</label>
                        <input type="file" id="feedback_file" name="feedback_file">
                    </div>

                    <button type="submit" name="submit_feedback" class="btn-submit">
                        Gem feedback
                    </button>
                </form>
            </section>

            <?php if (!$is_included): ?>
                <p class="back-link">
                    <a href="Evaluering.php?oprettet_afl_id=<?= $aflevering['Oprettet_Afl_id'] ?>">
                        ← Tilbage til afleveringsoversigt
                    </a>
                </p>
            <?php endif; ?>
        </main>
    </body>

    </html>

<?php
}

function visElevAflevering($aflevering, $is_included = false)
{
?>
    <!DOCTYPE html>
    <html lang="da">

    <head>
        <meta charset="UTF-8">
        <title>Min Aflevering</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                max-width: 800px;
                margin: 0 auto;
                padding: 20px;
            }

            .container {
                background: #f9f9f9;
                padding: 20px;
                border-radius: 5px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }

            th,
            td {
                padding: 8px;
                text-align: left;
                border-bottom: 1px solid #ddd;
            }

            .feedback {
                background-color: #f0f0f0;
                padding: 15px;
                margin-top: 20px;
            }
        </style>
    </head>

    <body>
        <h1>Min Aflevering</h1>
        <div class="container">
            <h2>Opgavedetaljer</h2>
            <table>
                <tr>
                    <th>Opgave:</th>
                    <td><?= htmlspecialchars($aflevering['Oprettet_Afl_navn']) ?></td>
                </tr>
                <tr>
                    <th>Fag:</th>
                    <td><?= htmlspecialchars($aflevering['Fag_navn']) ?></td>
                </tr>
                <tr>
                    <th>Afleveret:</th>
                    <td><?= htmlspecialchars($aflevering['Elev_Afl_tid']) ?></td>
                </tr>
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

            <?php if (!$is_included): ?>
                <p><a href="index.php">Tilbage til Forside</a></p>
            <?php endif; ?>
        </div>
    </body>

    </html>
<?php
}

function gemFeedback($conn)
{
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
        // Hent oprettet_afl_id for at kunne returnere til oversigten
        $stmt = $conn->prepare("SELECT Oprettet_Afl_id FROM Elev_Aflevering WHERE Elev_Afl_id = ?");
        $stmt->bind_param("i", $elev_afl_id);
        $stmt->execute();   
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $oprettet_afl_id = $row['Oprettet_Afl_id'];
        $stmt->close();

        header("Location: Evaluering.php?oprettet_afl_id=" . $oprettet_afl_id);
        exit();
    } else {
        echo "<p style='color: red;'>Fejl: " . $conn->error . "</p>";
    }
}
?>