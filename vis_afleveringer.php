<?php
session_start();
include 'connection.php';

if (!isset($_SESSION["unilogin"])) {
    header("Location: login.php");
    exit();
}

$unilogin = $_SESSION["unilogin"];
$is_teacher = false;
$selected_fag = isset($_GET['fag']) ? intval($_GET['fag']) : 0;

// Hent brugerinfo
$stmt = $conn->prepare("SELECT Level, Klasse_id FROM Bruger WHERE Unilogin = ?");
$stmt->bind_param("s", $unilogin);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) die("Bruger ikke fundet");

$is_teacher = ($user['Level'] == 1);
$klasse_id = $user['Klasse_id'];

// Hent alle relevante fag for brugeren (til knapperne)
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

// Hent afleveringer baseret på brugerens rolle
if ($is_teacher) {
    // Lærer-logik
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
        FROM 
            Oprettet_Aflevering o
        JOIN 
            Fag f ON o.Fag_id = f.Fag_id
        JOIN 
            Laerer_info l ON o.Klasse_id = l.Klasse_id AND o.Fag_id = l.Fag_id
        LEFT JOIN 
            Elev_Aflevering ea ON o.Oprettet_Afl_id = ea.Oprettet_Afl_id
        WHERE 
            l.Laerer_Unilogin = ?
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
} else {
    // Elev-logik
    $sql = "
        SELECT 
            o.Oprettet_Afl_id,
            o.Oprettet_Afl_navn,
            o.Oprettet_Afl_deadline,
            o.Klasse_id,
            f.Fag_navn,
            f.Fag_id
        FROM 
            Oprettet_Aflevering o
        JOIN 
            Fag f ON o.Fag_id = f.Fag_id
        WHERE 
            o.Klasse_id = ?
            AND NOT EXISTS (
                SELECT 1 FROM Elev_Aflevering ea 
                WHERE ea.Oprettet_Afl_id = o.Oprettet_Afl_id 
                AND ea.Unilogin = ?
            )
    ";

    if ($selected_fag > 0) {
        $sql .= " AND o.Fag_id = ?";
    }

    $sql .= " ORDER BY o.Oprettet_Afl_deadline DESC";

    $stmt = $conn->prepare($sql);
    if ($selected_fag > 0) {
        $stmt->bind_param("isi", $klasse_id, $unilogin, $selected_fag);
    } else {
        $stmt->bind_param("is", $klasse_id, $unilogin);
    }
}

$stmt->execute();
$afleveringer = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="da">

<head>
    <meta charset="UTF-8">
    <title><?php echo $is_teacher ? 'L?rers' : 'Elevens'; ?> afleveringsoversigt</title>
    <style>
        body,
        html {
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #F6F6F6;
        }

        h1 {
            color: #2c3e50;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background-color: #3498db;
            color: white;
        }

        tr:hover {
            background-color: #f5f5f5;
        }

        .deadline-passed {
            color: #e74c3c;
            font-weight: bold;
        }

        .deadline-soon {
            color: #e67e22;
        }

        .deadline-ok {
            color: #27ae60;
        }

        .progress-container {
            width: 100%;
            background-color: #f1f1f1;
            border-radius: 5px;
        }

        .progress-bar {
            height: 20px;
            border-radius: 5px;
            background-color: #2ecc71;
            text-align: center;
            color: white;
            line-height: 20px;
        }

        .aflever-knap {
            background-color: #3498db;
            color: white;
            padding: 8px 12px;
            border-radius: 4px;
            text-decoration: none;
        }

        .aflever-knap:hover {
            background-color: #2980b9;
        }

        .fag-filter {
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .fag-knap {
            padding: 8px 16px;
            background-color: #ecf0f1;
            border: 1px solid #bdc3c7;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            color: #2c3e50;
        }

        .fag-knap:hover {
            background-color: #d6eaf8;
        }

        .fag-knap.active {
            background-color: #3498db;
            color: white;
            border-color: #2980b9;
        }
    </style>
</head>

<body>
    <h1>Mine afleveringer</h1>

    <!-- Fagfilter knapper -->
    <div class="fag-filter">
        <a href="?fag=0" class="fag-knap <?php echo $selected_fag == 0 ? 'active' : ''; ?>">Alle fag</a>
        <?php foreach ($alle_fag as $fag): ?>
            <a href="?fag=<?php echo $fag['Fag_id']; ?>" class="fag-knap <?php echo $selected_fag == $fag['Fag_id'] ? 'active' : ''; ?>">
                <?php echo htmlspecialchars($fag['Fag_navn']); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($afleveringer)): ?>
        <table>
            <thead>
                <tr>
                    <th>Opgave</th>
                    <th>Fag</th>
                    <th>Klasse</th>
                    <th>Deadline</th>
                    <th>Status</th>
                    <th>Handling</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($afleveringer as $aflevering):
                    $deadline = new DateTime($aflevering['Oprettet_Afl_deadline']);
                    $now = new DateTime();
                    $interval = $now->diff($deadline);
                    $days_left = $interval->days;
                    $deadline_class = '';

                    if ($deadline < $now) {
                        $deadline_class = 'deadline-passed';
                        $status_text = 'Udl?bet for ' . $days_left . ' dage siden';
                    } elseif ($days_left <= 3) {
                        $deadline_class = 'deadline-soon';
                        $status_text = 'Udl?ber om ' . $days_left . ' dage';
                    } else {
                        $deadline_class = 'deadline-ok';
                        $status_text = 'Aktiv (' . $days_left . ' dage tilbage)';
                    }

                    // Beregn fremskridt for l?rere
                    $progress = 0;
                    if ($is_teacher && $aflevering['antal_elever'] > 0) {
                        $progress = round(($aflevering['antal_afleveret'] / $aflevering['antal_elever']) * 100);
                    }

                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($aflevering['Oprettet_Afl_navn']); ?></td>
                        <td><?php echo htmlspecialchars($aflevering['Fag_navn']); ?></td>
                        <td><?php echo htmlspecialchars($aflevering['Klasse_id']); ?></td>
                        <td class="<?php echo $deadline_class; ?>">
                            <?php echo $deadline->format('d/m/Y H:i'); ?>
                            <br><small><?php echo $status_text; ?></small>
                        </td>
                        <td>
                            <?php if ($is_teacher): ?>
                                <div class="progress-container">
                                    <div class="progress-bar" style="width:<?php echo $progress; ?>%">
                                        <?php echo $aflevering['antal_afleveret']; ?>/<?php echo $aflevering['antal_elever']; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                Ikke afleveret
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($is_teacher): ?>
                                <a href="Evaluering.php?oprettet_afl_id=<?php echo $aflevering['Oprettet_Afl_id']; ?>">
                                    Se detaljer
                                </a>
                            <?php else: ?>
                                <a href="Afleveringer.php?oprettet_afl_id=<?php echo $aflevering['Oprettet_Afl_id']; ?>" class="aflever-knap">
                                    Aflever nu
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p><?php echo $is_teacher ? 'Du har ikke oprettet nogen afleveringer endnu.' : 'Du har ingen afleveringer at aflevere i ?jeblikket.'; ?></p>
    <?php endif; ?>
</body>

</html>