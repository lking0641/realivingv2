<?php
// coordinator_timeline.php
include $includes['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];

$meStmt = $conn->prepare("SELECT full_name, role FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

if ($me['role'] !== 'project_coordinator') {
    die("Access denied. Only Project Coordinators can access this page.");
}

// ── Handle Save Stage Deadline ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_stage_deadline') {
    $client_id = intval($_POST['client_id']);
    $stage_name = trim($_POST['stage_name'] ?? '');
    $start_date = trim($_POST['start_date'] ?? '') ?: null;
    $end_date = trim($_POST['end_date'] ?? '') ?: null;

    $duration = 0;
    if ($start_date && $end_date) {
        $d1 = new DateTime($start_date);
        $d2 = new DateTime($end_date);
        $duration = (int) $d1->diff($d2)->days + 1;
    }

    $stmt = $conn->prepare("INSERT INTO stage_deadlines (client_id, stage_name, start_date, end_date, duration) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE start_date=?, end_date=?, duration=?, updated_at=NOW()");
    $stmt->bind_param("isssissi", $client_id, $stage_name, $start_date, $end_date, $duration, $start_date, $end_date, $duration);
    $stmt->execute();

    header("Location: " . BASE_URL . "coordinator-timeline?client_id={$client_id}&success=" . urlencode("Deadline saved for '{$stage_name}'!"));
    exit();
}

// ── Handle Save Overall Timeline ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_overall_timeline') {
    $client_id = intval($_POST['client_id']);
    $overall_start = trim($_POST['overall_start_date'] ?? '');
    $overall_end = trim($_POST['overall_end_date'] ?? '');

    $duration = 0;
    if ($overall_start && $overall_end) {
        $d1 = new DateTime($overall_start);
        $d2 = new DateTime($overall_end);
        $duration = (int) $d1->diff($d2)->days + 1;
    }

    $stmt = $conn->prepare("UPDATE user_info SET overall_start_date=?, overall_end_date=?, overall_duration=? WHERE id=?");
    $stmt->bind_param("ssii", $overall_start, $overall_end, $duration, $client_id);
    $stmt->execute();

    header("Location: " . BASE_URL . "coordinator-timeline?client_id={$client_id}&success=" . urlencode("Overall timeline saved!"));
    exit();
}

// ── Handle Save Group Assignments ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_group_assignments') {
    $client_id = intval($_POST['client_id']);
    $assignments = $_POST['group_label'] ?? []; // area => label

    foreach ($assignments as $area => $label) {
        $area = trim($area);
        $label = trim($label) ?: null;

        // Check what the PREVIOUS group label was for this area
        $prevLblStmt = $conn->prepare("SELECT group_label FROM timeline_area_groups WHERE client_id=? AND area=?");
        $prevLblStmt->bind_param("is", $client_id, $area);
        $prevLblStmt->execute();
        $prevRow = $prevLblStmt->get_result()->fetch_assoc();
        $prevLabel = $prevRow['group_label'] ?? null;

        // Save the new group assignment
        $glStmt = $conn->prepare("INSERT INTO timeline_area_groups (client_id, area, group_label) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE group_label=?, updated_at=NOW()");
        $glStmt->bind_param("isss", $client_id, $area, $label, $label);
        $glStmt->execute();

        // If area is being GROUPED (previously had no label, now has one)
        // OR moved to a DIFFERENT group — clear its old timeline data
        if ($label && $label !== $prevLabel) {
            $delTlStmt = $conn->prepare("DELETE FROM timeline_area WHERE client_id=? AND area=?");
            $delTlStmt->bind_param("is", $client_id, $area);
            $delTlStmt->execute();
        }
    }

    header("Location: " . BASE_URL . "coordinator-timeline?client_id={$client_id}&success=" . urlencode("Group assignments saved!"));
    exit();
}

// ── Handle Save Group Timeline (dates apply to ALL areas in the group) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_group_timeline') {
    $client_id = intval($_POST['client_id']);
    $group_label = trim($_POST['group_label'] ?? '');
    $fab_start = trim($_POST['fab_start'] ?? '') ?: null;
    $fab_end = trim($_POST['fab_end'] ?? '') ?: null;
    $del_start = trim($_POST['del_start'] ?? '') ?: null;
    $del_end = trim($_POST['del_end'] ?? '') ?: null;
    $ins_start = trim($_POST['ins_start'] ?? '') ?: null;
    $ins_end = trim($_POST['ins_end'] ?? '') ?: null;

    function daysDiff($s, $e)
    {
        if (!$s || !$e)
            return 0;
        return (int) (new DateTime($s))->diff(new DateTime($e))->days + 1;
    }
    $fab_dur = daysDiff($fab_start, $fab_end);
    $del_dur = daysDiff($del_start, $del_end);
    $ins_dur = daysDiff($ins_start, $ins_end);

    // Find all areas in this group for this client
    $areasInGroupStmt = $conn->prepare("SELECT area FROM timeline_area_groups WHERE client_id=? AND group_label=?");
    $areasInGroupStmt->bind_param("is", $client_id, $group_label);
    $areasInGroupStmt->execute();
    $areasInGroup = array_column($areasInGroupStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'area');

    // Also handle ungrouped single area (group_label passed as the area itself)
    if (empty($areasInGroup)) {
        $areasInGroup = [$group_label];
    }

    foreach ($areasInGroup as $area) {
        $chkStmt = $conn->prepare("SELECT id FROM timeline_area WHERE client_id=? AND area=?");
        $chkStmt->bind_param("is", $client_id, $area);
        $chkStmt->execute();
        $existing = $chkStmt->get_result()->fetch_assoc();

        if ($existing) {
            // Only update if at least one date is being set (don't wipe with nulls)
            if ($fab_start || $del_start || $ins_start) {
                $upStmt = $conn->prepare("UPDATE timeline_area SET fab_start=?,fab_end=?,fab_duration=?,del_start=?,del_end=?,del_duration=?,ins_start=?,ins_end=?,ins_duration=?,updated_at=NOW() WHERE client_id=? AND area=?");
                $upStmt->bind_param("ssississsis", $fab_start, $fab_end, $fab_dur, $del_start, $del_end, $del_dur, $ins_start, $ins_end, $ins_dur, $client_id, $area);
                $upStmt->execute();
            }
        } else {
            if ($fab_start || $del_start || $ins_start) {
                $inStmt = $conn->prepare("INSERT INTO timeline_area (client_id,area,fab_start,fab_end,fab_duration,del_start,del_end,del_duration,ins_start,ins_end,ins_duration) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $inStmt->bind_param("isssississs", $client_id, $area, $fab_start, $fab_end, $fab_dur, $del_start, $del_end, $del_dur, $ins_start, $ins_end, $ins_dur);
                $inStmt->execute();
            }
        }
    }

    header("Location: " . BASE_URL . "coordinator-timeline?client_id={$client_id}&success=" . urlencode("Timeline saved for group '{$group_label}'!"));
    exit();
}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

$clientsStmt = $conn->prepare("SELECT ui.id,ui.clientname,ui.nameproject,ui.reference_number,ui.business_type,ui.status,ui.overall_start_date,ui.overall_end_date,ui.overall_duration FROM user_info ui WHERE ui.project_coordinator_id=? ORDER BY ui.created_at DESC");
$clientsStmt->bind_param("i", $admin_id);
$clientsStmt->execute();
$clients = $clientsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$selectedClient = null;
$areas = [];
$areaTimelines = [];

if ($client_id) {
    $verifyStmt = $conn->prepare("SELECT * FROM user_info WHERE id=? AND project_coordinator_id=?");
    $verifyStmt->bind_param("ii", $client_id, $admin_id);
    $verifyStmt->execute();
    $selectedClient = $verifyStmt->get_result()->fetch_assoc();
    if (!$selectedClient)
        die("Access denied: Not assigned to this client.");

    $areasStmt = $conn->prepare("SELECT DISTINCT area FROM quotation_entries WHERE client_id=? UNION SELECT DISTINCT area FROM quotation_fixed_sizes WHERE client_id=? ORDER BY area");
    $areasStmt->bind_param("ii", $client_id, $client_id);
    $areasStmt->execute();
    $areas = array_column($areasStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'area');

    // Load group labels
    $groupLabels = [];
    $glLoadStmt = $conn->prepare("SELECT area, group_label FROM timeline_area_groups WHERE client_id=?");
    $glLoadStmt->bind_param("i", $client_id);
    $glLoadStmt->execute();
    foreach ($glLoadStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $gl) {
        $groupLabels[$gl['area']] = $gl['group_label'];
    }

    $tlStmt = $conn->prepare("SELECT * FROM timeline_area WHERE client_id=?");
    $tlStmt->bind_param("i", $client_id);
    $tlStmt->execute();
    foreach ($tlStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $tl) {
        $areaTimelines[$tl['area']] = $tl;
    }

    // Load stage deadlines
    $stageDeadlines = [];
    $sdStmt = $conn->prepare("SELECT * FROM stage_deadlines WHERE client_id=?");
    $sdStmt->bind_param("i", $client_id);
    $sdStmt->execute();
    foreach ($sdStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $sd) {
        $stageDeadlines[$sd['stage_name']] = $sd;
    }

    // Load group labels
    $groupLabels = [];
    $glLoadStmt = $conn->prepare("SELECT area, group_label FROM timeline_area_groups WHERE client_id=?");
    $glLoadStmt->bind_param("i", $client_id);
    $glLoadStmt->execute();
    foreach ($glLoadStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $gl) {
        $groupLabels[$gl['area']] = $gl['group_label'];
    }

    // Build groups: group_label => [areas], and ungrouped areas
    $groups = [];       // label => [area1, area2, ...]
    $ungrouped = [];    // areas with no label
    foreach ($areas as $area) {
        $lbl = $groupLabels[$area] ?? null;
        if ($lbl) {
            $groups[$lbl][] = $area;
        } else {
            $ungrouped[] = $area;
        }
    }
}

function bizLabel($bt)
{
    return $bt === 'Non-Project' ? 'Individual' : ($bt ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Timeline — Coordinator</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --brown-dark: #2c1810;
            --brown-mid: #7a4f3a;
            --brown-light: #c4956a;
            --cream: #f9f4ef;
            --cream-dark: #ede7df;
            --white: #ffffff;
            --text: #1a1a1a;
            --text-muted: #7a7a7a;
            --fab: #3b82f6;
            --del: #8b5cf6;
            --ins: #10b981;
            --rad: 12px;
            --shadow: 0 2px 12px rgba(44, 24, 16, 0.08);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--cream);
            font-family: 'DM Sans', sans-serif;
            color: var(--text);
        }

        .wrap {
            max-width: 1140px;
            margin: 0 auto;
            padding: 28px 20px;
        }

        /* ── Page Header ── */
        .page-header {
            background: linear-gradient(135deg, var(--brown-dark) 0%, var(--brown-mid) 100%);
            padding: 28px 36px;
            border-radius: 18px;
            color: white;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .page-header::after {
            content: '';
            position: absolute;
            right: -40px;
            top: -40px;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.04);
            pointer-events: none;
        }

        .page-header h1 {
            font-family: 'Playfair Display', serif;
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-header p {
            font-size: 13px;
            opacity: 0.75;
            margin-top: 6px;
        }

        /* ── Alert ── */
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        /* ── Card ── */
        .card {
            background: var(--white);
            border-radius: var(--rad);
            padding: 26px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }

        .card-title {
            font-family: 'Playfair Display', serif;
            font-size: 16px;
            font-weight: 700;
            color: var(--brown-dark);
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 2px solid var(--cream-dark);
            display: flex;
            align-items: center;
            gap: 9px;
        }

        /* ── Client List ── */
        .client-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .client-row {
            border: 2px solid var(--cream-dark);
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            background: white;
        }

        .client-row:hover {
            border-color: var(--brown-mid);
            background: var(--cream);
        }

        .client-row .name {
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
        }

        .client-row .sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .badge {
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            background: var(--cream-dark);
            color: var(--brown-dark);
        }

        /* ── Buttons ── */
        .btn-save {
            background: linear-gradient(135deg, var(--brown-dark), var(--brown-mid));
            color: white;
            padding: 11px 24px;
            border: none;
            border-radius: 9px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            font-family: 'DM Sans', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
            transition: opacity 0.2s;
            letter-spacing: 0.3px;
        }

        .btn-save:hover {
            opacity: 0.88;
        }

        .btn-back {
            background: linear-gradient(135deg, var(--brown-dark), var(--brown-mid));
            color: white;
            padding: 9px 18px;
            border-radius: 9px;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            margin-bottom: 18px;
        }

        /* ── Tabs ── */
        .view-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
        }

        .view-tab {
            padding: 9px 22px;
            border-radius: 9px;
            border: 2px solid var(--cream-dark);
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            background: white;
            color: var(--text-muted);
            transition: all 0.2s;
            font-family: 'DM Sans', sans-serif;
        }

        .view-tab.active {
            background: var(--brown-dark);
            color: white;
            border-color: var(--brown-dark);
        }

        /* ── Area Accordion ── */
        .area-accordion {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .area-block {
            border: 2px solid var(--cream-dark);
            border-radius: 13px;
            overflow: hidden;
        }

        .area-header-btn {
            width: 100%;
            background: transparent;
            border: none;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
            transition: background 0.2s;
            font-family: 'DM Sans', sans-serif;
        }

        .area-header-btn:hover {
            background: var(--cream);
        }

        .pill {
            padding: 3px 11px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .pill-done {
            background: #d1fae5;
            color: #065f46;
        }

        .pill-partial {
            background: #fef3c7;
            color: #92400e;
        }

        .pill-none {
            background: #f3f4f6;
            color: #9ca3af;
        }

        .area-body {
            display: none;
            padding: 24px;
            background: white;
            border-top: 1px solid var(--cream-dark);
        }

        .area-body.open {
            display: block;
        }

        /* ── Phase Grid ── */
        .phase-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .phase-card {
            border: 2px solid var(--cream-dark);
            border-radius: 12px;
            padding: 18px;
        }

        .phase-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .phase-fab {
            color: var(--fab);
        }

        .phase-del {
            color: var(--del);
        }

        .phase-ins {
            color: var(--ins);
        }

        /* ── Range Display (tap to open calendar) ── */
        .range-display {
            border: 2px solid var(--cream-dark);
            border-radius: 10px;
            padding: 14px 16px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            background: var(--cream);
            user-select: none;
            min-height: 80px;
        }

        .range-display:hover {
            border-color: var(--brown-light);
            background: #f5ede4;
        }

        .range-display.has-value {
            background: white;
        }

        .range-display .rd-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .range-display .rd-dates {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
            flex-wrap: wrap;
        }

        .range-display .rd-dates.empty {
            color: var(--text-muted);
            font-weight: 500;
            font-size: 13px;
        }

        .range-display .rd-arrow {
            color: var(--text-muted);
            font-size: 11px;
        }

        .range-display .rd-duration {
            margin-top: 10px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--cream-dark);
            color: var(--brown-dark);
            padding: 3px 10px;
            border-radius: 20px;
        }

        .range-display .rd-edit-icon {
            position: absolute;
            top: 10px;
            right: 12px;
            font-size: 11px;
            color: var(--brown-light);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Overall range display */
        .overall-range-display {
            border: 2px solid var(--cream-dark);
            border-radius: 14px;
            padding: 20px 24px;
            cursor: pointer;
            transition: all 0.2s;
            background: var(--cream);
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            min-height: 80px;
        }

        .overall-range-display:hover {
            border-color: var(--brown-light);
            background: #f5ede4;
        }

        .overall-range-display.has-value {
            background: white;
        }

        .ord-section {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .ord-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
        }

        .ord-value {
            font-size: 17px;
            font-weight: 700;
            color: var(--text);
        }

        .ord-value.empty {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-muted);
        }

        .ord-arrow {
            color: var(--text-muted);
            font-size: 22px;
        }

        .ord-dur {
            background: var(--brown-dark);
            color: white;
            padding: 5px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            margin-left: auto;
            white-space: nowrap;
        }

        .ord-tap-hint {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 500;
        }

        /* ── Calendar Overlay ── */
        .cal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .cal-overlay.open {
            display: flex;
        }

        .cal-popup {
            background: white;
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.22);
            width: 360px;
            max-width: 100%;
            animation: popIn 0.2s ease;
        }

        @keyframes popIn {
            from {
                transform: scale(0.9);
                opacity: 0
            }

            to {
                transform: scale(1);
                opacity: 1
            }
        }

        .cal-title {
            font-family: 'Playfair Display', serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--brown-dark);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .cal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .cal-nav {
            width: 36px;
            height: 36px;
            border: 2px solid var(--cream-dark);
            border-radius: 9px;
            background: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            color: var(--brown-dark);
            transition: all 0.15s;
            flex-shrink: 0;
        }

        .cal-nav:hover {
            background: var(--cream);
            border-color: var(--brown-light);
        }

        .cal-month-year {
            font-family: 'Playfair Display', serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--brown-dark);
            text-align: center;
        }

        /* Status bar */
        .cal-status {
            background: var(--cream);
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .cs-slot {
            display: flex;
            flex-direction: column;
            gap: 3px;
            flex: 1;
        }

        .cs-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--text-muted);
        }

        .cs-val {
            font-size: 13px;
            font-weight: 700;
            color: var(--brown-dark);
        }

        .cs-val.empty {
            color: var(--text-muted);
            font-weight: 400;
            font-size: 12px;
        }

        .cal-hint {
            font-size: 12px;
            color: var(--text-muted);
            text-align: center;
            margin-bottom: 12px;
            line-height: 1.5;
        }

        .cal-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            margin-bottom: 6px;
        }

        .cal-weekdays div {
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            padding: 4px 0;
        }

        .cal-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 3px;
        }

        .cal-day {
            aspect-ratio: 1;
            border-radius: 8px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.12s;
            position: relative;
            font-family: 'DM Sans', sans-serif;
        }

        .cal-day:hover:not(.empty):not(.disabled) {
            background: var(--cream-dark);
        }

        .cal-day.empty {
            cursor: default;
            pointer-events: none;
        }

        .cal-day.today {
            font-weight: 700;
            color: var(--brown-mid);
        }

        .cal-day.today::after {
            content: '';
            position: absolute;
            bottom: 3px;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: var(--brown-mid);
        }

        .cal-day.in-range {
            background: var(--cream-dark);
            border-radius: 0;
            color: var(--brown-dark);
        }

        .cal-day.range-start,
        .cal-day.range-end {
            background: var(--brown-dark);
            color: white;
            font-weight: 700;
            z-index: 1;
        }

        .cal-day.range-start {
            border-radius: 8px 0 0 8px;
        }

        .cal-day.range-end {
            border-radius: 0 8px 8px 0;
        }

        .cal-day.range-start.range-end {
            border-radius: 8px;
        }

        /* single-day selection */

        .cal-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 16px;
        }

        .cal-btn-clear {
            padding: 9px 18px;
            border-radius: 9px;
            border: 2px solid var(--cream-dark);
            background: white;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            color: var(--text-muted);
            transition: all 0.15s;
            font-family: 'DM Sans', sans-serif;
        }

        .cal-btn-clear:hover {
            border-color: #ef4444;
            color: #ef4444;
        }

        .cal-btn-confirm {
            padding: 9px 22px;
            border-radius: 9px;
            border: none;
            background: linear-gradient(135deg, var(--brown-dark), var(--brown-mid));
            color: white;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            transition: opacity 0.15s;
        }

        .cal-btn-confirm:hover:not(:disabled) {
            opacity: 0.88;
        }

        .cal-btn-confirm:disabled {
            opacity: 0.4;
            cursor: default;
        }

        /* ── Gantt ── */
        .gantt-container {
            background: white;
            border-radius: var(--rad);
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .gantt-legend {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 3px;
        }

        @media(max-width:768px) {
            .phase-grid {
                grid-template-columns: 1fr;
            }

            .ord-arrow {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">

        <div class="page-header">
            <h1><i class="fas fa-calendar-alt"></i> Project Timeline Manager</h1>
            <p>Welcome, <?= htmlspecialchars($me['full_name']) ?> — Project Coordinator</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$client_id): ?>
            <!-- ══ CLIENT LIST ══ -->
            <div class="card">
                <div class="card-title"><i class="fas fa-users"></i> Assigned Projects</div>
                <?php
                $notSetCount = count(array_filter($clients, fn($c) => empty($c['overall_start_date'])));
                $alreadySetCount = count(array_filter($clients, fn($c) => !empty($c['overall_start_date'])));
                ?>
                <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
                    <button type="button" id="btn-filter-notset" onclick="setFilter('notset')"
                        style="padding:9px 20px;border-radius:10px;border:2px solid var(--brown-dark);
                       background:var(--brown-dark);color:white;font-family:'DM Sans',sans-serif;
                       font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all 0.2s;">
                        <i class="fas fa-calendar-times"></i> Not Set
                        <span style="background:rgba(255,255,255,0.25);padding:1px 9px;border-radius:20px;font-size:11px;">
                            <?= $notSetCount ?>
                        </span>
                    </button>
                    <button type="button" id="btn-filter-set" onclick="setFilter('set')"
                        style="padding:9px 20px;border-radius:10px;border:2px solid var(--cream-dark);
                       background:white;color:var(--text-muted);font-family:'DM Sans',sans-serif;
                       font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all 0.2s;">
                        <i class="fas fa-calendar-check"></i> Already Set
                        <span
                            style="background:var(--cream-dark);padding:1px 9px;border-radius:20px;font-size:11px;color:var(--brown-dark);">
                            <?= $alreadySetCount ?>
                        </span>
                    </button>
                </div>
                <div style="margin-bottom:16px;position:relative;">
                    <i class="fas fa-search"
                        style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px;pointer-events:none;"></i>
                    <input type="text" id="clientSearch" placeholder="Search by client name, project, or reference…"
                        oninput="filterClients()" style="width:100%;padding:12px 16px 12px 40px;border:2px solid var(--cream-dark);border-radius:11px;
                      font-family:'DM Sans',sans-serif;font-size:13px;outline:none;background:white;
                      color:var(--text);transition:border-color 0.2s;"
                        onfocus="this.style.borderColor='var(--brown-light)'"
                        onblur="this.style.borderColor='var(--cream-dark)'">
                </div>
                <div id="noResults"
                    style="display:none;text-align:center;padding:30px;color:var(--text-muted);font-size:13px;">
                    <i class="fas fa-search" style="font-size:28px;display:block;margin-bottom:10px;opacity:0.4;"></i>
                    No clients match your search.
                </div>
                <?php if (empty($clients)): ?>
                    <div style="text-align:center;padding:40px;color:var(--text-muted);">
                        <i class="fas fa-inbox" style="font-size:40px;display:block;margin-bottom:12px;"></i>
                        No clients assigned to you yet.
                    </div>
                <?php else: ?>
                    <div class="client-list">
                        <?php foreach ($clients as $c):
                            $hasTimeline = !empty($c['overall_start_date']);
                            ?>
                            <a href="<?= BASE_URL ?>coordinator-timeline?client_id=<?= $c['id'] ?>" class="client-row"
                                data-search="<?= htmlspecialchars(strtolower($c['clientname'] . ' ' . $c['nameproject'] . ' ' . ($c['reference_number'] ?? ''))) ?>"
                                data-timeline="<?= empty($c['overall_start_date']) ? 'notset' : 'set' ?>">
                                <div>
                                    <div class="name"><?= htmlspecialchars($c['clientname']) ?></div>
                                    <div class="sub">
                                        <?= htmlspecialchars($c['nameproject']) ?>
                                        <?php if ($c['reference_number']): ?>
                                            &nbsp;•&nbsp;<span
                                                style="font-family:monospace;"><?= htmlspecialchars($c['reference_number']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="display:flex;align-items:center;gap:12px;">
                                    <span class="badge"><?= htmlspecialchars(bizLabel($c['business_type'])) ?></span>
                                    <?php if ($hasTimeline): ?>
                                        <span
                                            style="color:#10b981;font-size:12px;font-weight:700;display:flex;align-items:center;gap:5px;">
                                            <i class="fas fa-calendar-check"></i> Timeline Set
                                        </span>
                                    <?php else: ?>
                                        <span
                                            style="color:#9ca3af;font-size:12px;font-weight:600;display:flex;align-items:center;gap:5px;">
                                            <i class="fas fa-calendar-times"></i> Not Set
                                        </span>
                                    <?php endif; ?>
                                    <i class="fas fa-chevron-right" style="color:#ccc;"></i>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- ══ TIMELINE EDITOR ══ -->
            <a href="<?= BASE_URL ?>coordinator-timeline" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Project List
            </a>

            <!-- Client Info Header -->
            <div
                style="background:linear-gradient(135deg,var(--brown-dark),var(--brown-mid));border-radius:14px;padding:24px 30px;margin-bottom:20px;color:white;">
                <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:12px;">
                    <div>
                        <h2 style="font-family:'Playfair Display',serif;font-size:22px;font-weight:700;margin-bottom:4px;">
                            <?= htmlspecialchars($selectedClient['clientname']) ?>
                        </h2>
                        <p style="opacity:0.85;font-size:14px;"><?= htmlspecialchars($selectedClient['nameproject']) ?></p>
                        <?php if ($selectedClient['reference_number']): ?>
                            <p style="opacity:0.65;font-size:12px;font-family:monospace;margin-top:4px;">
                                <?= htmlspecialchars($selectedClient['reference_number']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                        <span
                            style="background:rgba(255,255,255,0.18);padding:6px 16px;border-radius:20px;font-size:12px;font-weight:700;">
                            <?= htmlspecialchars(bizLabel($selectedClient['business_type'])) ?>
                        </span>
                        <?php if (!empty($selectedClient['overall_start_date'])): ?>
                            <span
                                style="background:rgba(16,185,129,0.3);padding:6px 16px;border-radius:20px;font-size:12px;font-weight:700;border:1px solid rgba(16,185,129,0.5);">
                                <i class="fas fa-calendar-check"></i> Timeline Active
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="view-tabs">
                <button class="view-tab active" onclick="switchTab('settings')" id="tab-settings">
                    <i class="fas fa-cog"></i> Timeline Settings
                </button>
                <button class="view-tab" onclick="switchTab('gantt')" id="tab-gantt">
                    <i class="fas fa-chart-gantt"></i> Gantt Chart View
                </button>
            </div>

            <!-- ── SETTINGS PANEL ── -->
            <div id="panel-settings">

                <!-- Overall Timeline -->
                <div class="card">
                    <div class="card-title"><i class="fas fa-calendar-alt" style="color:var(--fab);"></i> Overall Project
                        Timeline</div>

                    <?php
                    $overallStart = $selectedClient['overall_start_date'] ?? '';
                    $overallEnd = $selectedClient['overall_end_date'] ?? '';
                    $overallDuration = $selectedClient['overall_duration'] ?? 0;
                    ?>

                    <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">
                        Tap the bar below to open the calendar and pick the project's start and end dates.
                    </p>

                    <form method="POST" id="form-overall">
                        <input type="hidden" name="action" value="save_overall_timeline">
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        <input type="hidden" name="overall_start_date" id="h_overall_start"
                            value="<?= htmlspecialchars($overallStart) ?>">
                        <input type="hidden" name="overall_end_date" id="h_overall_end"
                            value="<?= htmlspecialchars($overallEnd) ?>">

                        <div class="overall-range-display <?= $overallStart ? 'has-value' : '' ?>" id="ord-overall"
                            onclick="openCalendar('overall','overall','Overall Project','overall')">
                            <?php if ($overallStart): ?>
                                <div class="ord-section">
                                    <div class="ord-label"><i class="fas fa-play-circle" style="color:#10b981;"></i> Start Date
                                    </div>
                                    <div class="ord-value" id="ord-start-overall">
                                        <?= date('M d, Y', strtotime($overallStart)) ?>
                                    </div>
                                </div>
                                <div class="ord-arrow"><i class="fas fa-arrow-right"></i></div>
                                <div class="ord-section">
                                    <div class="ord-label"><i class="fas fa-stop-circle" style="color:#ef4444;"></i> End Date
                                    </div>
                                    <div class="ord-value" id="ord-end-overall"><?= date('M d, Y', strtotime($overallEnd)) ?>
                                    </div>
                                </div>
                                <div class="ord-dur" id="ord-dur-overall">
                                    <i class="fas fa-clock"></i> <?= $overallDuration ?>
                                    day<?= $overallDuration != 1 ? 's' : '' ?>
                                </div>
                            <?php else: ?>
                                <div class="ord-tap-hint" id="ord-tap-overall">
                                    <i class="fas fa-calendar-plus" style="font-size:22px;color:var(--brown-light);"></i>
                                    <div>
                                        <div style="font-weight:700;color:var(--brown-dark);font-size:14px;">Tap to set project
                                            dates</div>
                                        <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Pick start → end in
                                            a single calendar</div>
                                    </div>
                                </div>
                                <!-- Hidden placeholders updated by JS -->
                                <div style="display:none;" id="ord-start-overall"></div>
                                <div style="display:none;" id="ord-end-overall"></div>
                                <div style="display:none;" id="ord-dur-overall"></div>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="btn-save" onclick="return validateOverall()">
                            <i class="fas fa-save"></i> Save Overall Timeline
                        </button>
                    </form>
                </div>

                <?php if (empty($areas)): ?>
                    <div class="card" style="text-align:center;padding:40px;color:var(--text-muted);">
                        <i class="fas fa-layer-group" style="font-size:40px;display:block;margin-bottom:12px;"></i>
                        No areas found for this client yet.
                    </div>
                <?php else: ?>

                    <?php
                    // Only show stage deadlines for Project type (not Individual/Non-Project)
                    $isNonProjectClient = ($selectedClient['business_type'] ?? '') === 'Non-Project';
                    $deadlineStages = $isNonProjectClient
                        ? ['2D / 3D Layout', 'Cuttinglist']
                        : ['Samples Submitted TDS/SDS', '2D / 3D Layout', 'Cuttinglist'];
                    if (true):
                        ?>
                        <!-- STAGE DEADLINES -->
                        <div class="card">
                            <div class="card-title"><i class="fas fa-calendar-exclamation" style="color:#ef4444;"></i> Step 1 —
                                Stage Deadlines</div>
                            <p style="font-size:13px;color:var(--text-muted);margin-bottom:18px;">
                                Set start and end deadlines for specific stages. These are visible to assigned staff on their
                                respective pages.
                            </p>
                            <div style="display:flex;flex-direction:column;gap:16px;">
                                <?php foreach ($deadlineStages as $ds):
                                    $sdData = $stageDeadlines[$ds] ?? [];
                                    $hasSD = !empty($sdData['start_date']);
                                    $dsSlug = 'sd_' . preg_replace('/[^a-zA-Z0-9]/', '_', $ds);
                                    $dsIcons = [
                                        'Samples Submitted TDS/SDS' => 'fa-vials',
                                        '2D / 3D Layout' => 'fa-drafting-compass',
                                        'Cuttinglist' => 'fa-cut',
                                    ];
                                    $dsColors = [
                                        'Samples Submitted TDS/SDS' => '#8b5cf6',
                                        '2D / 3D Layout' => '#0369a1',
                                        'Cuttinglist' => '#d97706',
                                    ];
                                    $dsIcon = $dsIcons[$ds] ?? 'fa-calendar';
                                    $dsColor = $dsColors[$ds] ?? '#374151';
                                    ?>
                                    <div style="border:2px solid var(--cream-dark);border-radius:13px;overflow:hidden;">
                                        <div
                                            style="background:#fafafa;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                                            <span
                                                style="font-size:14px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;">
                                                <i class="fas <?= $dsIcon ?>" style="color:<?= $dsColor ?>;"></i>
                                                <?= htmlspecialchars($ds) ?>
                                            </span>
                                            <span class="pill <?= $hasSD ? 'pill-done' : 'pill-none' ?>">
                                                <?= $hasSD ? 'Set' : 'Not Set' ?>
                                            </span>
                                        </div>
                                        <div style="padding:20px;background:white;border-top:1px solid var(--cream-dark);">
                                            <form method="POST" id="form-<?= $dsSlug ?>">
                                                <input type="hidden" name="action" value="save_stage_deadline">
                                                <input type="hidden" name="client_id" value="<?= $client_id ?>">
                                                <input type="hidden" name="stage_name" value="<?= htmlspecialchars($ds) ?>">
                                                <input type="hidden" name="start_date" id="h_<?= $dsSlug ?>_start"
                                                    value="<?= htmlspecialchars($sdData['start_date'] ?? '') ?>">
                                                <input type="hidden" name="end_date" id="h_<?= $dsSlug ?>_end"
                                                    value="<?= htmlspecialchars($sdData['end_date'] ?? '') ?>">

                                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                                                    <!-- Start date display -->
                                                    <div>
                                                        <div
                                                            style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:7px;">
                                                            <i class="fas fa-play-circle" style="color:#10b981;"></i> Start Date
                                                        </div>
                                                        <div class="range-display <?= $hasSD ? 'has-value' : '' ?>"
                                                            id="rd-<?= $dsSlug ?>-start"
                                                            onclick="openSingleCal('<?= $dsSlug ?>','start','<?= htmlspecialchars($ds, ENT_QUOTES) ?> Start')">
                                                            <div class="rd-edit-icon"><i class="fas fa-pen"></i></div>
                                                            <div class="rd-dates <?= $hasSD ? '' : 'empty' ?>"
                                                                id="rd-dates-<?= $dsSlug ?>-start">
                                                                <?php if ($hasSD): ?>
                                                                    <span><?= date('M d, Y', strtotime($sdData['start_date'])) ?></span>
                                                                <?php else: ?>
                                                                    <span><i class="fas fa-calendar-plus"
                                                                            style="margin-right:6px;color:<?= $dsColor ?>;"></i>Tap to
                                                                        pick</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <!-- End date display -->
                                                    <div>
                                                        <div
                                                            style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:7px;">
                                                            <i class="fas fa-stop-circle" style="color:#ef4444;"></i> End Date /
                                                            Deadline
                                                        </div>
                                                        <div class="range-display <?= (!empty($sdData['end_date'])) ? 'has-value' : '' ?>"
                                                            id="rd-<?= $dsSlug ?>-end"
                                                            onclick="openSingleCal('<?= $dsSlug ?>','end','<?= htmlspecialchars($ds, ENT_QUOTES) ?> Deadline')">
                                                            <div class="rd-edit-icon"><i class="fas fa-pen"></i></div>
                                                            <div class="rd-dates <?= (!empty($sdData['end_date'])) ? '' : 'empty' ?>"
                                                                id="rd-dates-<?= $dsSlug ?>-end">
                                                                <?php if (!empty($sdData['end_date'])): ?>
                                                                    <span><?= date('M d, Y', strtotime($sdData['end_date'])) ?></span>
                                                                <?php else: ?>
                                                                    <span><i class="fas fa-calendar-plus"
                                                                            style="margin-right:6px;color:<?= $dsColor ?>;"></i>Tap to
                                                                        pick</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <?php if ($hasSD && !empty($sdData['duration'])): ?>
                                                    <div
                                                        style="font-size:12px;color:var(--text-muted);margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                                                        <i class="fas fa-clock" style="color:<?= $dsColor ?>;"></i>
                                                        <span id="sd-dur-<?= $dsSlug ?>"><?= $sdData['duration'] ?>
                                                            day<?= $sdData['duration'] != 1 ? 's' : '' ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;display:none;"
                                                        id="sd-dur-<?= $dsSlug ?>"></div>
                                                <?php endif; ?>

                                                <button type="submit" class="btn-save" style="margin-top:0;">
                                                    <i class="fas fa-save"></i> Save Deadline
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- STEP 2: Assign Groups — Modal based -->
                    <div class="card">
                        <div class="card-title"><i class="fas fa-object-group" style="color:var(--brown-mid);"></i> Step 2 —
                            Group Areas</div>
                        <p style="font-size:13px;color:var(--text-muted);margin-bottom:18px;">
                            Create a group label and assign areas to it. All areas in a group will share the same timeline
                            dates.
                        </p>

                        <?php
                        $allGroupLabels = array_unique(array_filter(array_values($groupLabels)));
                        $ungroupedAreas = array_filter($areas, fn($a) => empty($groupLabels[$a]));
                        ?>

                        <?php
                        $allGroupLabels = array_unique(array_filter(array_values($groupLabels)));
                        $ungroupedAreas = array_filter($areas, fn($a) => empty($groupLabels[$a]));
                        ?>
                        <button type="button" onclick="openGroupModal('', [])"
                            style="background:linear-gradient(135deg,var(--brown-dark),var(--brown-mid));color:white;padding:10px 22px;border:none;border-radius:9px;cursor:pointer;font-size:13px;font-weight:700;font-family:'DM Sans',sans-serif;display:inline-flex;align-items:center;gap:8px;">
                            <i class="fas fa-plus"></i> Add / Edit Group
                        </button>
                    </div>

                    <!-- ── GROUP MODAL ── -->
                    <div id="groupModal"
                        style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9998;align-items:center;justify-content:center;padding:20px;">
                        <div
                            style="background:white;border-radius:18px;padding:30px;max-width:520px;width:100%;box-shadow:0 24px 70px rgba(0,0,0,0.2);max-height:90vh;overflow-y:auto;animation:popIn 0.2s ease;">

                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                                <h3
                                    style="font-family:'Playfair Display',serif;font-size:18px;font-weight:700;color:var(--brown-dark);display:flex;align-items:center;gap:9px;">
                                    <i class="fas fa-object-group"></i> <span id="modalTitleText">Group Areas</span>
                                </h3>
                                <button onclick="closeGroupModal()"
                                    style="background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
                            </div>

                            <form method="POST" id="groupModalForm">
                                <input type="hidden" name="action" value="save_group_assignments">
                                <input type="hidden" name="client_id" value="<?= $client_id ?>">
                                <!-- All areas will be submitted with their labels -->
                                <?php foreach ($areas as $area): ?>
                                    <input type="hidden" name="group_label[<?= htmlspecialchars($area, ENT_QUOTES) ?>]"
                                        id="modal_gl_<?= md5($area) ?>" value="<?= htmlspecialchars($groupLabels[$area] ?? '') ?>">
                                <?php endforeach; ?>

                                <!-- Group Label Input -->
                                <div style="margin-bottom:20px;">
                                    <label
                                        style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);display:block;margin-bottom:8px;">
                                        <i class="fas fa-tag" style="color:var(--brown-mid);"></i> Group Label
                                    </label>
                                    <input type="text" id="modalGroupLabelInput" placeholder="e.g. Room 1, Floor 2, Main Areas…"
                                        style="width:100%;padding:12px 16px;border:2px solid var(--cream-dark);border-radius:10px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;outline:none;color:var(--text);"
                                        onfocus="this.style.borderColor='var(--brown-light)'"
                                        onblur="this.style.borderColor='var(--cream-dark)'">
                                    <div style="font-size:11px;color:var(--text-muted);margin-top:6px;">
                                        <i class="fas fa-info-circle"></i> Type a label then check the areas below to assign
                                        them to this group.
                                    </div>
                                </div>

                                <!-- Area Checkboxes -->
                                <div style="margin-bottom:20px;">
                                    <label
                                        style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);display:block;margin-bottom:10px;">
                                        <i class="fas fa-map-marker-alt" style="color:var(--brown-mid);"></i> Select Areas
                                    </label>
                                    <div style="display:flex;flex-direction:column;gap:8px;" id="modalAreaList">
                                        <?php foreach ($areas as $area): ?>
                                            <label
                                                style="display:flex;align-items:center;gap:12px;padding:12px 16px;border:2px solid var(--cream-dark);border-radius:10px;cursor:pointer;transition:all 0.15s;background:white;"
                                                id="modal_row_<?= md5($area) ?>"
                                                onmouseover="this.style.borderColor='var(--brown-light)'"
                                                onmouseout="syncRowStyle('<?= md5($area) ?>')">
                                                <input type="checkbox" id="modal_chk_<?= md5($area) ?>"
                                                    data-area="<?= htmlspecialchars($area, ENT_QUOTES) ?>"
                                                    data-areahash="<?= md5($area) ?>" onchange="syncRowStyle('<?= md5($area) ?>')"
                                                    style="width:18px;height:18px;cursor:pointer;accent-color:var(--brown-dark);">
                                                <div style="flex:1;">
                                                    <div style="font-size:13px;font-weight:700;color:var(--text);">
                                                        <?= htmlspecialchars($area) ?>
                                                    </div>
                                                    <div id="modal_cur_<?= md5($area) ?>"
                                                        style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                                                        <?php $cur = $groupLabels[$area] ?? ''; ?>
                                                        <?= $cur ? '<i class="fas fa-tag"></i> Currently: <strong>' . htmlspecialchars($cur) . '</strong>' : 'No group assigned' ?>
                                                    </div>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Remove from group option -->
                                <div id="modalRemoveSection"
                                    style="background:#fff5f5;border:1.5px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px;">
                                    <input type="checkbox" id="modalRemoveChecked"
                                        style="width:16px;height:16px;accent-color:#ef4444;">
                                    <label for="modalRemoveChecked"
                                        style="font-size:13px;font-weight:600;color:#991b1b;cursor:pointer;">
                                        <i class="fas fa-times-circle"></i> Remove checked areas from their current group
                                        (ungroup them)
                                    </label>
                                </div>

                                <div style="display:flex;gap:10px;justify-content:flex-end;">
                                    <button type="button" onclick="closeGroupModal()"
                                        style="background:white;border:2px solid var(--cream-dark);color:var(--text-muted);padding:10px 20px;border-radius:9px;cursor:pointer;font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif;">
                                        Cancel
                                    </button>
                                    <button type="button" onclick="submitGroupModal()"
                                        style="background:linear-gradient(135deg,var(--brown-dark),var(--brown-mid));color:white;padding:10px 24px;border:none;border-radius:9px;cursor:pointer;font-size:13px;font-weight:700;font-family:'DM Sans',sans-serif;display:inline-flex;align-items:center;gap:7px;">
                                        <i class="fas fa-save"></i> Save Group
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- STEP 3: Set Dates per Group -->
                    <div class="card">
                        <div class="card-title"><i class="fas fa-calendar-alt" style="color:var(--brown-mid);"></i> Step 3 — Set
                            Timeline per Group</div>
                        <p style="font-size:13px;color:var(--text-muted);margin-bottom:18px;">
                            Set dates for each group below. All areas in a group share the same dates automatically.
                        </p>

                        <div class="area-accordion">

                            <?php
                            // Build display entries: grouped ones + ungrouped
                            $displayEntries = [];
                            foreach ($groups as $label => $areasInGroup) {
                                $displayEntries[] = ['type' => 'group', 'label' => $label, 'areas' => $areasInGroup];
                            }
                            foreach ($ungrouped as $area) {
                                $displayEntries[] = ['type' => 'single', 'label' => $area, 'areas' => [$area]];
                            }
                            ?>

                            <?php foreach ($displayEntries as $entry):
                                $entryLabel = $entry['label'];
                                $entryAreas = $entry['areas'];
                                $isGroup = $entry['type'] === 'group';
                                // Use first area's timeline data (all areas in group share same dates)
                                $tl = $areaTimelines[$entryAreas[0]] ?? [];
                                $hasFab = !empty($tl['fab_start']);
                                $hasDel = !empty($tl['del_start']);
                                $hasIns = !empty($tl['ins_start']);
                                $setCount = (int) $hasFab + (int) $hasDel + (int) $hasIns;
                                if ($setCount === 3) {
                                    $pillClass = 'pill-done';
                                    $pillText = 'Complete';
                                } elseif ($setCount > 0) {
                                    $pillClass = 'pill-partial';
                                    $pillText = "$setCount/3 Set";
                                } else {
                                    $pillClass = 'pill-none';
                                    $pillText = 'Not Set';
                                }
                                $eSlug = 'e_' . preg_replace('/[^a-zA-Z0-9]/', '_', $entryLabel);
                                ?>
                                <div class="area-block" id="block-<?= $eSlug ?>">
                                    <div
                                        style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;width:100%;background:#fafafa;padding:15px 20px;border-radius:inherit;">
                                        <button type="button" class="area-header-btn" onclick="toggleArea('<?= $eSlug ?>')"
                                            style="flex:1;background:none;border:none;padding:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;cursor:pointer;font-family:'DM Sans',sans-serif;text-align:left;">
                                            <?php if ($isGroup): ?>
                                                <i class="fas fa-object-group" style="color:var(--brown-mid);"></i>
                                                <span
                                                    style="font-size:14px;font-weight:700;color:var(--text);"><?= htmlspecialchars($entryLabel) ?></span>
                                                <span
                                                    style="background:#fef3c7;color:#92400e;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">
                                                    <i class="fas fa-layer-group"></i> <?= count($entryAreas) ?>
                                                    area<?= count($entryAreas) > 1 ? 's' : '' ?>:
                                                    <?= htmlspecialchars(implode(', ', $entryAreas)) ?>
                                                </span>
                                            <?php else: ?>
                                                <i class="fas fa-map-marker-alt" style="color:var(--brown-mid);"></i>
                                                <span
                                                    style="font-size:14px;font-weight:700;color:var(--text);"><?= htmlspecialchars($entryLabel) ?></span>
                                            <?php endif; ?>
                                        </button>
                                        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
                                            <?php if ($isGroup): ?>
                                                <button type="button" class="edit-group-btn"
                                                    data-label="<?= htmlspecialchars($entryLabel, ENT_QUOTES) ?>"
                                                    data-areas="<?= htmlspecialchars(json_encode(array_values($entryAreas)), ENT_QUOTES) ?>"
                                                    style="background:white;border:2px solid var(--cream-dark);color:var(--brown-mid);padding:5px 12px;border-radius:8px;cursor:pointer;font-size:11px;font-weight:700;font-family:'DM Sans',sans-serif;display:inline-flex;align-items:center;gap:4px;">
                                                    <i class="fas fa-pen"></i> Edit Group
                                                </button>
                                            <?php endif; ?>
                                            <span class="pill <?= $pillClass ?>"><?= $pillText ?></span>
                                            <i class="fas fa-chevron-down" id="chev-<?= $eSlug ?>"
                                                style="color:#ccc;transition:transform 0.2s;"></i>
                                        </div>
                                    </div>

                                    <div class="area-body" id="body-<?= $eSlug ?>">
                                        <form method="POST" id="form-<?= $eSlug ?>">
                                            <input type="hidden" name="action" value="save_group_timeline">
                                            <input type="hidden" name="client_id" value="<?= $client_id ?>">
                                            <input type="hidden" name="group_label" value="<?= htmlspecialchars($entryLabel) ?>">
                                            <input type="hidden" name="fab_start" id="h_<?= $eSlug ?>_fab_start"
                                                value="<?= htmlspecialchars($tl['fab_start'] ?? '') ?>">
                                            <input type="hidden" name="fab_end" id="h_<?= $eSlug ?>_fab_end"
                                                value="<?= htmlspecialchars($tl['fab_end'] ?? '') ?>">
                                            <input type="hidden" name="del_start" id="h_<?= $eSlug ?>_del_start"
                                                value="<?= htmlspecialchars($tl['del_start'] ?? '') ?>">
                                            <input type="hidden" name="del_end" id="h_<?= $eSlug ?>_del_end"
                                                value="<?= htmlspecialchars($tl['del_end'] ?? '') ?>">
                                            <input type="hidden" name="ins_start" id="h_<?= $eSlug ?>_ins_start"
                                                value="<?= htmlspecialchars($tl['ins_start'] ?? '') ?>">
                                            <input type="hidden" name="ins_end" id="h_<?= $eSlug ?>_ins_end"
                                                value="<?= htmlspecialchars($tl['ins_end'] ?? '') ?>">

                                            <?php if ($isGroup): ?>
                                                <div
                                                    style="background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:#92400e;display:flex;align-items:center;gap:8px;">
                                                    <i class="fas fa-info-circle"></i>
                                                    These dates will apply to all <?= count($entryAreas) ?> areas:
                                                    <strong><?= htmlspecialchars(implode(', ', $entryAreas)) ?></strong>
                                                </div>
                                            <?php endif; ?>

                                            <div class="phase-grid">
                                                <!-- FABRICATION -->
                                                <div class="phase-card" style="border-color:#bfdbfe;">
                                                    <div class="phase-title phase-fab"><i class="fas fa-tools"></i> Fabrication
                                                    </div>
                                                    <div class="range-display <?= $hasFab ? 'has-value' : '' ?>"
                                                        id="rd-<?= $eSlug ?>-fab"
                                                        onclick="openCalendar('<?= $eSlug ?>','fab','Fabrication','<?= $eSlug ?>-fab')">
                                                        <div class="rd-label">Date Range</div>
                                                        <div class="rd-edit-icon"><i class="fas fa-pen"></i></div>
                                                        <div class="rd-dates <?= $hasFab ? '' : 'empty' ?>"
                                                            id="rd-dates-<?= $eSlug ?>-fab">
                                                            <?php if ($hasFab): ?>
                                                                <span><?= date('M d', strtotime($tl['fab_start'])) ?></span>
                                                                <span class="rd-arrow">→</span>
                                                                <span><?= date('M d, Y', strtotime($tl['fab_end'])) ?></span>
                                                            <?php else: ?>
                                                                <span><i class="fas fa-calendar-plus"
                                                                        style="margin-right:6px;color:var(--fab);"></i>Tap to pick
                                                                    dates</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="rd-duration" id="rd-dur-<?= $eSlug ?>-fab" <?= ($hasFab && ($tl['fab_duration'] ?? 0) > 0) ? '' : 'style="display:none;"' ?>>
                                                            <?php if ($hasFab && ($tl['fab_duration'] ?? 0) > 0): ?>
                                                                <i class="fas fa-clock" style="color:var(--fab);"></i>
                                                                <?= $tl['fab_duration'] ?> day<?= $tl['fab_duration'] != 1 ? 's' : '' ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <!-- DELIVERY -->
                                                <div class="phase-card" style="border-color:#ddd6fe;">
                                                    <div class="phase-title phase-del"><i class="fas fa-truck"></i> Delivery</div>
                                                    <div class="range-display <?= $hasDel ? 'has-value' : '' ?>"
                                                        id="rd-<?= $eSlug ?>-del"
                                                        onclick="openCalendar('<?= $eSlug ?>','del','Delivery','<?= $eSlug ?>-del')">
                                                        <div class="rd-label">Date Range</div>
                                                        <div class="rd-edit-icon"><i class="fas fa-pen"></i></div>
                                                        <div class="rd-dates <?= $hasDel ? '' : 'empty' ?>"
                                                            id="rd-dates-<?= $eSlug ?>-del">
                                                            <?php if ($hasDel): ?>
                                                                <span><?= date('M d', strtotime($tl['del_start'])) ?></span>
                                                                <span class="rd-arrow">→</span>
                                                                <span><?= date('M d, Y', strtotime($tl['del_end'])) ?></span>
                                                            <?php else: ?>
                                                                <span><i class="fas fa-calendar-plus"
                                                                        style="margin-right:6px;color:var(--del);"></i>Tap to pick
                                                                    dates</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="rd-duration" id="rd-dur-<?= $eSlug ?>-del" <?= ($hasDel && ($tl['del_duration'] ?? 0) > 0) ? '' : 'style="display:none;"' ?>>
                                                            <?php if ($hasDel && ($tl['del_duration'] ?? 0) > 0): ?>
                                                                <i class="fas fa-clock" style="color:var(--del);"></i>
                                                                <?= $tl['del_duration'] ?> day<?= $tl['del_duration'] != 1 ? 's' : '' ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <!-- INSTALLATION -->
                                                <div class="phase-card" style="border-color:#a7f3d0;">
                                                    <div class="phase-title phase-ins"><i class="fas fa-hard-hat"></i> Installation
                                                    </div>
                                                    <div class="range-display <?= $hasIns ? 'has-value' : '' ?>"
                                                        id="rd-<?= $eSlug ?>-ins"
                                                        onclick="openCalendar('<?= $eSlug ?>','ins','Installation','<?= $eSlug ?>-ins')">
                                                        <div class="rd-label">Date Range</div>
                                                        <div class="rd-edit-icon"><i class="fas fa-pen"></i></div>
                                                        <div class="rd-dates <?= $hasIns ? '' : 'empty' ?>"
                                                            id="rd-dates-<?= $eSlug ?>-ins">
                                                            <?php if ($hasIns): ?>
                                                                <span><?= date('M d', strtotime($tl['ins_start'])) ?></span>
                                                                <span class="rd-arrow">→</span>
                                                                <span><?= date('M d, Y', strtotime($tl['ins_end'])) ?></span>
                                                            <?php else: ?>
                                                                <span><i class="fas fa-calendar-plus"
                                                                        style="margin-right:6px;color:var(--ins);"></i>Tap to pick
                                                                    dates</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="rd-duration" id="rd-dur-<?= $eSlug ?>-ins" <?= ($hasIns && ($tl['ins_duration'] ?? 0) > 0) ? '' : 'style="display:none;"' ?>>
                                                            <?php if ($hasIns && ($tl['ins_duration'] ?? 0) > 0): ?>
                                                                <i class="fas fa-clock" style="color:var(--ins);"></i>
                                                                <?= $tl['ins_duration'] ?> day<?= $tl['ins_duration'] != 1 ? 's' : '' ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <button type="submit" class="btn-save">
                                                <i class="fas fa-save"></i> Save Timeline for "<?= htmlspecialchars($entryLabel) ?>"
                                                <?php if ($isGroup): ?>
                                                    <span style="font-size:11px;font-weight:400;opacity:0.8;">(applies to
                                                        <?= count($entryAreas) ?> areas)</span>
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                        </div>
                    </div>
                <?php endif; ?>



            </div><!-- /panel-settings -->

            <!-- ── GANTT PANEL ── -->
            <div id="panel-gantt" style="display:none;">
                <?php
                $ganttData = [];
                foreach ($areas as $area) {
                    $tl = $areaTimelines[$area] ?? [];
                    if (!empty($tl))
                        $ganttData[$area] = $tl;
                }
                $allDates = [];
                foreach ($ganttData as $tl) {
                    foreach (['fab_start', 'fab_end', 'del_start', 'del_end', 'ins_start', 'ins_end'] as $f) {
                        if (!empty($tl[$f]))
                            $allDates[] = strtotime($tl[$f]);
                    }
                }
                if ($overallStart)
                    $allDates[] = strtotime($overallStart);
                if ($overallEnd)
                    $allDates[] = strtotime($overallEnd);
                $ganttMin = !empty($allDates) ? strtotime('-2 days', min($allDates)) : strtotime('today');
                $ganttMax = !empty($allDates) ? strtotime('+2 days', max($allDates)) : strtotime('+30 days');
                $totalDays = max(1, round(($ganttMax - $ganttMin) / 86400));
                function pctLeft($d, $min, $tot)
                {
                    return !$d ? 0 : round((strtotime($d) - $min) / ($tot * 86400) * 100, 2);
                }
                function pctWidth($s, $e, $tot)
                {
                    return (!$s || !$e) ? 0 : max(1, round((strtotime($e) - strtotime($s)) / ($tot * 86400) * 100, 2));
                }
                ?>
                <div class="gantt-container">
                    <div
                        style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                        <h3
                            style="font-family:'Playfair Display',serif;font-size:16px;font-weight:700;color:var(--brown-dark);display:flex;align-items:center;gap:8px;">
                            <i class="fas fa-chart-gantt"></i> Gantt Chart
                        </h3>
                        <?php if ($overallStart): ?>
                            <div style="font-size:12px;color:var(--text-muted);font-weight:600;">
                                <?= date('M d, Y', strtotime($overallStart)) ?> → <?= date('M d, Y', strtotime($overallEnd)) ?>
                                <span
                                    style="background:var(--brown-dark);color:white;padding:2px 10px;border-radius:20px;margin-left:8px;font-size:11px;">
                                    <?= $overallDuration ?> days
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="gantt-legend">
                        <div class="legend-item">
                            <div class="legend-dot" style="background:var(--fab);"></div> Fabrication
                        </div>
                        <div class="legend-item">
                            <div class="legend-dot" style="background:var(--del);"></div> Delivery
                        </div>
                        <div class="legend-item">
                            <div class="legend-dot" style="background:var(--ins);"></div> Installation
                        </div>
                    </div>
                    <?php if (empty($ganttData)): ?>
                        <div style="text-align:center;padding:50px;color:var(--text-muted);">
                            <i class="fas fa-calendar-times" style="font-size:40px;display:block;margin-bottom:12px;"></i>
                            No timeline data yet. Set dates in Timeline Settings.
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <div style="min-width:680px;">
                                <div style="display:flex;margin-left:190px;margin-bottom:4px;">
                                    <?php $step = max(1, intdiv($totalDays, 8));
                                    for ($d = 0; $d <= $totalDays; $d += $step) {
                                        $ts = strtotime("+{$d} days", $ganttMin);
                                        $pct = round($d / $totalDays * 100, 1);
                                        echo "<div style='flex:0 0 {$pct}%;font-size:10px;color:#aaa;padding:0 2px;'>" . date('M d', $ts) . "</div>";
                                    } ?>
                                </div>
                                <?php foreach ($ganttData as $area => $tl):
                                    $displayName = !empty($groupLabels[$area]) ? $groupLabels[$area] . ' (' . $area . ')' : $area;
                                    $lbl = strlen($displayName) > 26 ? substr($displayName, 0, 24) . '…' : $displayName;
                                    $phases = [
                                        ['fab', 'var(--fab)', 'Fabrication', $tl['fab_start'] ?? '', $tl['fab_end'] ?? '', $tl['fab_duration'] ?? 0],
                                        ['del', 'var(--del)', 'Delivery', $tl['del_start'] ?? '', $tl['del_end'] ?? '', $tl['del_duration'] ?? 0],
                                        ['ins', 'var(--ins)', 'Installation', $tl['ins_start'] ?? '', $tl['ins_end'] ?? '', $tl['ins_duration'] ?? 0],
                                    ];
                                    ?>
                                    <div
                                        style="display:flex;align-items:center;background:var(--cream);border-top:2px solid var(--cream-dark);margin-top:6px;">
                                        <div
                                            style="width:190px;flex-shrink:0;padding:8px 12px;font-size:12px;font-weight:700;color:var(--brown-dark);">
                                            <i class="fas fa-map-marker-alt"
                                                style="color:var(--brown-mid);margin-right:5px;"></i><?= htmlspecialchars($lbl) ?>
                                        </div>
                                        <div
                                            style="flex:1;height:12px;background:var(--cream-dark);border-radius:4px;margin:6px 0;">
                                        </div>
                                    </div>
                                    <?php foreach ($phases as [$key, $color, $label, $start, $end, $dur]):
                                        if (!$start)
                                            continue;
                                        $l = pctLeft($start, $ganttMin, $totalDays);
                                        $w = pctWidth($start, $end, $totalDays);
                                        ?>
                                        <div style="display:flex;align-items:center;border-bottom:1px solid #f3f4f6;">
                                            <div
                                                style="width:190px;flex-shrink:0;padding:4px 12px 4px 24px;font-size:11px;color:var(--text-muted);">
                                                <?= $label ?>
                                                <?php if ($dur > 0): ?>
                                                    <span
                                                        style="background:<?= $color ?>22;color:<?= $color ?>;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700;margin-left:4px;"><?= $dur ?>d</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="flex:1;position:relative;height:26px;background:#f9f9f9;">
                                                <div style="position:absolute;top:4px;bottom:4px;left:<?= $l ?>%;width:<?= $w ?>%;
                            background:<?= $color ?>;border-radius:4px;
                            display:flex;align-items:center;padding-left:6px;
                            font-size:10px;font-weight:700;color:white;overflow:hidden;white-space:nowrap;">
                                                    <?php if ($w > 8): ?>                     <?= date('M d', strtotime($start)) ?> –
                                                        <?= date('M d', strtotime($end)) ?>                 <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php endif; // client_id ?>
    </div><!-- /wrap -->

    <!-- ══════════════ CALENDAR POPUP ══════════════ -->
    <div class="cal-overlay" id="calOverlay" onclick="overlayClick(event)">
        <div class="cal-popup">
            <div class="cal-title" id="calTitle">
                <i class="fas fa-calendar-alt"></i> <span id="calTitleText">Pick Date Range</span>
            </div>
            <div class="cal-header">
                <button type="button" class="cal-nav" onclick="changeMonth(-1)"><i
                        class="fas fa-chevron-left"></i></button>
                <div class="cal-month-year" id="calMonthYear"></div>
                <button type="button" class="cal-nav" onclick="changeMonth(1)"><i
                        class="fas fa-chevron-right"></i></button>
            </div>

            <div class="cal-status">
                <div class="cs-slot">
                    <div class="cs-label"><i class="fas fa-play-circle" style="color:#10b981;"></i> Start</div>
                    <div class="cs-val empty" id="csStart">Not selected</div>
                </div>
                <div style="color:var(--text-muted);font-size:18px;"><i class="fas fa-arrow-right"></i></div>
                <div class="cs-slot">
                    <div class="cs-label"><i class="fas fa-stop-circle" style="color:#ef4444;"></i> End</div>
                    <div class="cs-val empty" id="csEnd">Not selected</div>
                </div>
            </div>

            <div class="cal-hint" id="calHint">Tap a date to set the <strong>start</strong></div>

            <div class="cal-weekdays">
                <div>Su</div>
                <div>Mo</div>
                <div>Tu</div>
                <div>We</div>
                <div>Th</div>
                <div>Fr</div>
                <div>Sa</div>
            </div>
            <div class="cal-days" id="calDays"></div>

            <div class="cal-actions">
                <button type="button" class="cal-btn-clear" onclick="clearCalendar()">
                    <i class="fas fa-times"></i> Clear
                </button>
                <button type="button" class="cal-btn-confirm" onclick="confirmCalendar()" id="calConfirm" disabled>
                    <i class="fas fa-check"></i> Confirm
                </button>
            </div>
        </div>
    </div>

    <script>
        const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        const SMONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        let cal = {
            slugGroup: '', phase: '', label: '', calKey: '',
            viewYear: 0, viewMonth: 0,
            startDate: null, endDate: null,
            picking: 'start'
        };

        function openCalendar(slugGroup, phase, label, calKey) {
            cal.slugGroup = slugGroup;
            cal.phase = phase;
            cal.label = label;
            cal.calKey = calKey;

            let es = '', ee = '';
            if (slugGroup === 'overall') {
                es = document.getElementById('h_overall_start').value;
                ee = document.getElementById('h_overall_end').value;
            } else {
                es = document.getElementById('h_' + slugGroup + '_' + phase + '_start').value;
                ee = document.getElementById('h_' + slugGroup + '_' + phase + '_end').value;
            }

            cal.startDate = es ? parseYMD(es) : null;
            cal.endDate = ee ? parseYMD(ee) : null;
            cal.picking = 'start';

            const nav = cal.startDate || new Date();
            cal.viewYear = nav.getFullYear();
            cal.viewMonth = nav.getMonth();

            document.getElementById('calTitleText').textContent = label + ' — Date Range';
            renderCal();
            updateStatus();
            document.getElementById('calOverlay').classList.add('open');
        }

        function parseYMD(s) {
            const [y, m, d] = s.split('-').map(Number);
            return new Date(y, m - 1, d);
        }
        function toYMD(d) {
            return d.getFullYear() + '-' +
                String(d.getMonth() + 1).padStart(2, '0') + '-' +
                String(d.getDate()).padStart(2, '0');
        }
        function fmt(d) {
            return SMONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
        }
        function sameDay(a, b) {
            return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
        }
        function daysBetween(a, b) { return Math.round(Math.abs(b - a) / 86400000) + 1; }

        function renderCal() {
            const y = cal.viewYear, m = cal.viewMonth;
            document.getElementById('calMonthYear').textContent = MONTHS[m] + ' ' + y;

            const firstDow = new Date(y, m, 1).getDay();
            const lastDay = new Date(y, m + 1, 0).getDate();
            const today = new Date(); today.setHours(0, 0, 0, 0);

            const container = document.getElementById('calDays');
            container.innerHTML = '';

            for (let i = 0; i < firstDow; i++) {
                const e = document.createElement('div');
                e.className = 'cal-day empty';
                container.appendChild(e);
            }

            for (let day = 1; day <= lastDay; day++) {
                const date = new Date(y, m, day);
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'cal-day';
                btn.textContent = day;

                const isStart = sameDay(date, cal.startDate);
                const isEnd = sameDay(date, cal.endDate);
                const isToday = sameDay(date, today);

                if (isToday) btn.classList.add('today');
                if (isStart) btn.classList.add('range-start');
                if (isEnd) btn.classList.add('range-end');

                if (cal.startDate && cal.endDate && !isStart && !isEnd) {
                    if (date > cal.startDate && date < cal.endDate)
                        btn.classList.add('in-range');
                }

                btn.onclick = () => onDayClick(date);
                container.appendChild(btn);
            }
        }

        function onDayClick(date) {
            // Single-date mode: pick and auto-confirm
            if (cal._singleMode) {
                cal.startDate = date;
                cal.endDate = date;
                renderCal();
                updateStatus();
                confirmCalendar();
                return;
            }
            if (cal.picking === 'start') {
                cal.startDate = date;
                cal.endDate = null;
                cal.picking = 'end';
            } else {
                if (cal.startDate && date < cal.startDate) {
                    // Clicked before start: restart from this date
                    cal.startDate = date;
                    cal.endDate = null;
                    cal.picking = 'end';
                } else {
                    // end >= start (same day allowed)
                    cal.endDate = date;
                    cal.picking = 'start'; // both set
                }
            }
            renderCal();
            updateStatus();
        }

        function updateStatus() {
            const csStart = document.getElementById('csStart');
            const csEnd = document.getElementById('csEnd');
            const hint = document.getElementById('calHint');
            const confirm = document.getElementById('calConfirm');

            if (cal.startDate) { csStart.textContent = fmt(cal.startDate); csStart.classList.remove('empty'); }
            else { csStart.textContent = 'Not selected'; csStart.classList.add('empty'); }

            if (cal.endDate) { csEnd.textContent = fmt(cal.endDate); csEnd.classList.remove('empty'); }
            else { csEnd.textContent = 'Not selected'; csEnd.classList.add('empty'); }

            if (!cal.startDate) {
                hint.innerHTML = 'Tap a date to set the <strong>start</strong>';
                confirm.disabled = true;
            } else if (!cal.endDate) {
                hint.innerHTML = 'Now tap the <strong>end</strong> date — same day is OK';
                confirm.disabled = true;
            } else {
                const d = daysBetween(cal.startDate, cal.endDate);
                hint.innerHTML = '<strong>' + d + ' day' + (d !== 1 ? 's' : '') + '</strong> selected ✓';
                confirm.disabled = false;
            }
        }

        function changeMonth(delta) {
            cal.viewMonth += delta;
            if (cal.viewMonth > 11) { cal.viewMonth = 0; cal.viewYear++; }
            if (cal.viewMonth < 0) { cal.viewMonth = 11; cal.viewYear--; }
            renderCal();
        }

        function clearCalendar() {
            cal.startDate = null;
            cal.endDate = null;
            cal.picking = 'start';
            renderCal();
            updateStatus();
        }

        function confirmCalendar() {
            if (!cal.startDate || !cal.endDate) return;

            const startYMD = toYMD(cal.startDate);
            const endYMD = toYMD(cal.endDate);
            const days = daysBetween(cal.startDate, cal.endDate);
            const sg = cal.slugGroup;

            // ── Single-date mode (stage deadlines) ──
            if (cal._singleMode) {
                const slug = cal._singleSlug;
                const field = cal._singleField;
                const hiddenEl = document.getElementById('h_' + slug + '_' + field);
                if (hiddenEl) hiddenEl.value = startYMD;

                const datesEl = document.getElementById('rd-dates-' + slug + '-' + field);
                if (datesEl) {
                    datesEl.classList.remove('empty');
                    datesEl.innerHTML = '<span>' + fmt(cal.startDate) + '</span>';
                }
                document.getElementById('rd-' + slug + '-' + field)?.classList.add('has-value');

                // Update duration display if both start and end are now set
                const startVal = document.getElementById('h_' + slug + '_start')?.value;
                const endVal = document.getElementById('h_' + slug + '_end')?.value;
                if (startVal && endVal) {
                    const d1 = parseYMD(startVal), d2 = parseYMD(endVal);
                    if (d2 >= d1) {
                        const dur = daysBetween(d1, d2);
                        const durEl = document.getElementById('sd-dur-' + slug);
                        if (durEl) {
                            durEl.textContent = dur + ' day' + (dur !== 1 ? 's' : '');
                            durEl.style.display = 'flex';
                        }
                    }
                }

                cal._singleMode = false;
                closeCalendar();
                return;
            }

            const phase = cal.phase;
            const ck = cal.calKey;
            const phaseColors = { fab: 'var(--fab)', del: 'var(--del)', ins: 'var(--ins)', overall: 'var(--brown-mid)' };
            const pColor = phaseColors[phase] || 'var(--brown-mid)';

            if (sg === 'overall') {
                document.getElementById('h_overall_start').value = startYMD;
                document.getElementById('h_overall_end').value = endYMD;

                const ord = document.getElementById('ord-overall');
                ord.classList.add('has-value');
                ord.innerHTML =
                    '<div class="ord-section">' +
                    '<div class="ord-label"><i class="fas fa-play-circle" style="color:#10b981;"></i> Start Date</div>' +
                    '<div class="ord-value" id="ord-start-overall">' + fmt(cal.startDate) + '</div>' +
                    '</div>' +
                    '<div class="ord-arrow"><i class="fas fa-arrow-right"></i></div>' +
                    '<div class="ord-section">' +
                    '<div class="ord-label"><i class="fas fa-stop-circle" style="color:#ef4444;"></i> End Date</div>' +
                    '<div class="ord-value" id="ord-end-overall">' + fmt(cal.endDate) + '</div>' +
                    '</div>' +
                    '<div class="ord-dur" id="ord-dur-overall">' +
                    '<i class="fas fa-clock"></i> ' + days + ' day' + (days !== 1 ? 's' : '') +
                    '</div>';
            } else {
                document.getElementById('h_' + sg + '_' + phase + '_start').value = startYMD;
                document.getElementById('h_' + sg + '_' + phase + '_end').value = endYMD;

                const rdDates = document.getElementById('rd-dates-' + ck);
                rdDates.classList.remove('empty');
                rdDates.innerHTML =
                    '<span>' + SMONTHS[cal.startDate.getMonth()] + ' ' + cal.startDate.getDate() + '</span>' +
                    '<span class="rd-arrow">→</span>' +
                    '<span>' + fmt(cal.endDate) + '</span>';

                const rdDur = document.getElementById('rd-dur-' + ck);
                rdDur.innerHTML = '<i class="fas fa-clock" style="color:' + pColor + ';"></i> ' + days + ' day' + (days !== 1 ? 's' : '');
                rdDur.style.display = '';
                document.getElementById('rd-' + ck).classList.add('has-value');
            }

            closeCalendar();
        }

        function closeCalendar() { document.getElementById('calOverlay').classList.remove('open'); }
        function overlayClick(e) { if (e.target === document.getElementById('calOverlay')) closeCalendar(); }

        function toggleArea(slug) {
            const body = document.getElementById('body-' + slug);
            const chev = document.getElementById('chev-' + slug);
            if (!body) return;
            const open = body.classList.contains('open');
            body.classList.toggle('open', !open);
            chev.style.transform = open ? '' : 'rotate(180deg)';
        }

        function switchTab(tab) {
            ['settings', 'gantt'].forEach(t => {
                document.getElementById('panel-' + t).style.display = t === tab ? 'block' : 'none';
                document.getElementById('tab-' + t).classList.toggle('active', t === tab);
            });
        }

        // ── Single-date calendar (for stage deadlines) ──────────────────────
        let singleCal = { slug: '', field: '', label: '', selectedDate: null, viewYear: 0, viewMonth: 0 };

        function openSingleCal(slug, field, label) {
            singleCal.slug = slug;
            singleCal.field = field;
            singleCal.label = label;

            const hiddenId = 'h_' + slug + '_' + field;
            const existing = document.getElementById(hiddenId)?.value;
            singleCal.selectedDate = existing ? parseYMD(existing) : null;

            const nav = singleCal.selectedDate || new Date();
            singleCal.viewYear = nav.getFullYear();
            singleCal.viewMonth = nav.getMonth();

            // Hijack existing calendar overlay for single-date mode
            cal.slugGroup = '__single__';
            cal.phase = field;
            cal.label = label;
            cal.calKey = slug + '-' + field;
            cal.startDate = singleCal.selectedDate;
            cal.endDate = singleCal.selectedDate; // same = single day selection
            cal.picking = 'start';
            cal.viewYear = singleCal.viewYear;
            cal.viewMonth = singleCal.viewMonth;
            cal._singleMode = true;
            cal._singleSlug = slug;
            cal._singleField = field;

            document.getElementById('calTitleText').textContent = label;
            renderCal();
            updateStatus();
            document.getElementById('calOverlay').classList.add('open');
        }

        function validateOverall() {
            const s = document.getElementById('h_overall_start').value;
            const e = document.getElementById('h_overall_end').value;
            if (!s || !e) {
                alert('Please tap the timeline bar above to pick your start and end dates first.');
                return false;
            }
            return true;
        }

        document.addEventListener('DOMContentLoaded', function () {
            <?php if (!$client_id): ?>
                setFilter('notset');
            <?php endif; ?>
        });

        let activeFilter = 'notset';

        function setFilter(filter) {
            activeFilter = filter;

            const btnNotSet = document.getElementById('btn-filter-notset');
            const btnSet = document.getElementById('btn-filter-set');

            if (filter === 'notset') {
                btnNotSet.style.background = 'var(--brown-dark)';
                btnNotSet.style.borderColor = 'var(--brown-dark)';
                btnNotSet.style.color = 'white';
                btnSet.style.background = 'white';
                btnSet.style.borderColor = 'var(--cream-dark)';
                btnSet.style.color = 'var(--text-muted)';
            } else {
                btnSet.style.background = 'var(--brown-dark)';
                btnSet.style.borderColor = 'var(--brown-dark)';
                btnSet.style.color = 'white';
                btnNotSet.style.background = 'white';
                btnNotSet.style.borderColor = 'var(--cream-dark)';
                btnNotSet.style.color = 'var(--text-muted)';
            }

            filterClients();
        }

        function filterClients() {
            const q = (document.getElementById('clientSearch')?.value || '').toLowerCase().trim();
            const rows = document.querySelectorAll('.client-row');
            let visible = 0;
            rows.forEach(row => {
                const hay = row.getAttribute('data-search') || '';
                const tlStatus = row.getAttribute('data-timeline') || '';
                const matchSearch = !q || hay.includes(q);
                const matchFilter = tlStatus === activeFilter;
                const show = matchSearch && matchFilter;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            const noResults = document.getElementById('noResults');
            if (noResults) noResults.style.display = visible === 0 ? 'block' : 'none';
        }

        // ── Group Modal ──
        // Map area names to md5 hashes
        const strToMd5Map = {
            <?php if ($client_id):
                foreach ($areas as $area): ?>
                                                                                                                                                                                            <?= json_encode($area) ?>: '<?= md5($area) ?>',
                <?php endforeach; endif; ?>
        };

        // Track current group assignments in JS
        let currentGroupLabels = {
            <?php if ($client_id):
                foreach ($areas as $area): ?>
                                                                                                                                                                                            <?= json_encode($area) ?>: <?= json_encode($groupLabels[$area] ?? '') ?>,
                <?php endforeach; endif; ?>
        };

        function openGroupModal(existingLabel, existingAreas) {
            const removeMode = document.getElementById('modalRemoveChecked');
            if (removeMode) removeMode.checked = false;

            // Set label input
            document.getElementById('modalGroupLabelInput').value = existingLabel || '';

            // Show/hide area rows based on context:
            // - If editing existing group (existingLabel set): show only areas IN that group
            // - If adding new group: show only UNGROUPED areas
            document.querySelectorAll('#modalAreaList label').forEach(row => {
                const cb = row.querySelector('input[type=checkbox]');
                const area = cb ? cb.dataset.area : null;
                if (!area) return;

                const areaCurrentGroup = currentGroupLabels[area] || '';

                if (existingLabel) {
                    // Edit mode: show only areas that belong to this group
                    const inThisGroup = areaCurrentGroup === existingLabel;
                    row.style.display = inThisGroup ? '' : 'none';
                    cb.checked = inThisGroup;
                } else {
                    // Add mode: show only ungrouped areas
                    const isUngrouped = !areaCurrentGroup;
                    row.style.display = isUngrouped ? '' : 'none';
                    cb.checked = false;
                }
                syncRowStyle(cb.dataset.areahash);
            });

            // Show/hide the remove option — only relevant when editing
            const removeSection = document.getElementById('modalRemoveSection');
            if (removeSection) {
                removeSection.style.display = existingLabel ? '' : 'none';
            }

            // Update modal title
            const modalTitle = document.getElementById('modalTitleText');
            if (modalTitle) {
                modalTitle.textContent = existingLabel ? 'Edit Group: ' + existingLabel : 'Add New Group';
            }

            // Lock label input if editing (don't allow renaming group here)
            const labelInput = document.getElementById('modalGroupLabelInput');
            if (existingLabel) {
                labelInput.readOnly = true;
                labelInput.style.background = '#f3f4f6';
                labelInput.style.color = '#6b7280';
            } else {
                labelInput.readOnly = false;
                labelInput.style.background = 'white';
                labelInput.style.color = 'var(--text)';
            }

            document.getElementById('groupModal').style.display = 'flex';
        }

        function closeGroupModal() {
            document.getElementById('groupModal').style.display = 'none';
        }

        function syncRowStyle(hash) {
            const cb = document.getElementById('modal_chk_' + hash);
            const row = document.getElementById('modal_row_' + hash);
            if (!cb || !row) return;
            if (cb.checked) {
                row.style.borderColor = 'var(--brown-dark)';
                row.style.background = '#fdf6f0';
            } else {
                row.style.borderColor = 'var(--cream-dark)';
                row.style.background = 'white';
            }
        }

        function submitGroupModal() {
            const label = document.getElementById('modalGroupLabelInput').value.trim();
            const removeMode = document.getElementById('modalRemoveChecked').checked;
            const checked = [...document.querySelectorAll('#modalAreaList input[type=checkbox]:checked')];

            if (checked.length === 0) {
                alert('Please check at least one area.');
                return;
            }
            if (!removeMode && !label) {
                alert('Please enter a group label.');
                return;
            }

            // Get ALL visible area rows in the modal (only those shown for this edit)
            const allVisible = [...document.querySelectorAll('#modalAreaList input[type=checkbox]')]
                .filter(cb => document.getElementById('modal_row_' + cb.dataset.areahash)?.style.display !== 'none');

            allVisible.forEach(cb => {
                const area = cb.dataset.area;
                const hash = cb.dataset.areahash;
                const hiddenInput = document.getElementById('modal_gl_' + hash);
                const curDiv = document.getElementById('modal_cur_' + hash);

                if (removeMode) {
                    // Remove mode: clear all visible (they're all in this group)
                    if (hiddenInput) hiddenInput.value = '';
                    currentGroupLabels[area] = '';
                    if (curDiv) curDiv.innerHTML = 'No group assigned';
                } else if (cb.checked) {
                    // Checked: assign to this group
                    if (hiddenInput) hiddenInput.value = label;
                    currentGroupLabels[area] = label;
                    if (curDiv) curDiv.innerHTML = '<i class="fas fa-tag"></i> Currently: <strong>' + label + '</strong>';
                } else {
                    // Unchecked: remove from group
                    if (hiddenInput) hiddenInput.value = '';
                    currentGroupLabels[area] = '';
                    if (curDiv) curDiv.innerHTML = 'No group assigned';
                }
            });

            document.getElementById('groupModalForm').submit();
        }

        // Delegate edit button clicks via data attributes
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.edit-group-btn');
            if (!btn) return;
            const label = btn.getAttribute('data-label');
            const areas = JSON.parse(btn.getAttribute('data-areas') || '[]');
            openGroupModal(label, areas);
        });

        // Close on backdrop click — only attach if modal exists on this page
        const _groupModal = document.getElementById('groupModal');
        if (_groupModal) {
            _groupModal.addEventListener('click', function (e) {
                if (e.target === this) closeGroupModal();
            });
        }
    </script>
</body>

</html>