<?php
// item_tracker.php
session_start();
include '../../connection/connection.php';
include '../design/mainbody.php';
include '../checkrole/checkrole.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$stage = isset($_GET['stage']) ? $_GET['stage'] : '';

$valid_stages = ['Fabrication', 'Delivery', 'Installation', 'BILLING'];
if (!in_array($stage, $valid_stages))
    die("Invalid stage");

// Get admin role
$roleStmt = $conn->prepare("SELECT role, full_name, is_head FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();
$admin_role = $userInfo['role'];

$allowedRolesForAllClients = ['general_manager', 'operational_manager', 'designer', 'technical_designer', 'accounting', 'superadmin', 'project_coordinator'];
$canViewAllClients = in_array($admin_role, $allowedRolesForAllClients);

// Fetch client
if ($canViewAllClients) {
    $clientStmt = $conn->prepare("SELECT u.*, a.full_name as admin_name, a.role as admin_role FROM user_info u LEFT JOIN account a ON u.accountaid_fk = a.id WHERE u.id = ?");
    $clientStmt->bind_param("i", $client_id);
} else {
    $clientStmt = $conn->prepare("SELECT u.*, a.full_name as admin_name, a.role as admin_role FROM user_info u LEFT JOIN account a ON u.accountaid_fk = a.id WHERE u.id = ? AND u.accountaid_fk = ?");
    $clientStmt->bind_param("ii", $client_id, $admin_id);
}
$clientStmt->execute();
$client = $clientStmt->get_result()->fetch_assoc();
if (!$client)
    die("Access denied.");

// Permissions
if ($admin_role === 'sales') {
    $permStmt = $conn->prepare("SELECT stage_name, can_update FROM stage_permissions WHERE admin_id = ?");
    $permStmt->bind_param("i", $admin_id);
} else {
    $permStmt = $conn->prepare("SELECT stage_name, can_update FROM role_stage_permissions WHERE role = ?");
    $permStmt->bind_param("s", $admin_role);
}
$permStmt->execute();
$permResult = $permStmt->get_result();
$permissions = [];
while ($perm = $permResult->fetch_assoc()) {
    $permissions[$perm['stage_name']] = (bool) $perm['can_update'];
}
$came_from = isset($_GET['came_from']) ? $_GET['came_from'] : '';
$view_only = isset($_GET['view_only']) && $_GET['view_only'] == '1';
$canUpdate = $permissions[$stage] ?? false;

// Fetch assigned staff for this client
$assignedStaffStmt = $conn->prepare("SELECT technical_designer_id FROM user_info WHERE id = ?");
$assignedStaffStmt->bind_param("i", $client_id);
$assignedStaffStmt->execute();
$assignedStaffRow = $assignedStaffStmt->get_result()->fetch_assoc();

// Allow technical designer head OR assigned technical designer to access
$isTechDesignerAccess = $admin_role === 'technical_designer' && (
    $userInfo['is_head'] == 1 ||
    $admin_id == ($assignedStaffRow['technical_designer_id'] ?? null)
);

if (!$canUpdate && !$view_only && !$isTechDesignerAccess) {
    header("Location: unified_project_tracker.php?client_id=" . $client_id);
    exit();
}

// If TD has no explicit update permission, force view_only
if (!$canUpdate && $isTechDesignerAccess) {
    $view_only = true;
}

// If view_only flag set, force canUpdate off
if ($view_only)
    $canUpdate = false;

$statusColumn = strtolower($stage) . '_status';
$updatedColumn = strtolower($stage) . '_updated_at';

// Statuses per stage
$isInstallation = ($stage === 'Installation');
$stageStatuses = $isInstallation
    ? ['Pending', 'Ongoing', 'Done', 'Incomplete', 'Punchlist']
    : ['Pending', 'Ongoing', 'Done'];

// ── Fetch quotation_entries ──
$itemsStmt = $conn->prepare("
    SELECT
        qe.id,
        qe.area,
        qe.quantity,
        qe.unit_type        AS unit,
        qe.computed_tot_amount,
        qe.$statusColumn    AS status,
        qe.$updatedColumn   AS updated_at,
        COALESCE(qe.item_name, i.item_name) AS item_name,
        qe.color_label
    FROM quotation_entries qe
    LEFT JOIN items i ON qe.entry_item_id = i.item_id
    WHERE qe.client_id = ?
    ORDER BY qe.area, COALESCE(qe.item_name, i.item_name)
");
$itemsStmt->bind_param("i", $client_id);
$itemsStmt->execute();
$rawItems = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Fetch quotation_fixed_sizes ──
$fixedStmt = $conn->prepare("
    SELECT
        qfs.id,
        qfs.area,
        qfs.quantity,
        qfs.unit_type                       AS unit,
        (qfs.base_price * qfs.quantity)     AS computed_tot_amount,
        qfs.$statusColumn                   AS status,
        qfs.$updatedColumn                  AS updated_at,
        qfs.item_name,
        qfs.color_label
    FROM quotation_fixed_sizes qfs
    WHERE qfs.client_id = ?
    ORDER BY qfs.area, qfs.item_name
");
$fixedStmt->bind_param("i", $client_id);
$fixedStmt->execute();
$rawFixed = $fixedStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Tag source
foreach ($rawItems as &$r) {
    $r['source'] = 'entry';
}
foreach ($rawFixed as &$r) {
    $r['source'] = 'fixed';
}
unset($r);

// ── Fetch ALL room distributions for this client ──
$distStmt = $conn->prepare("
    SELECT
        qrd.distribution_id,
        qrd.quotation_entry_id      AS entry_id,
        NULL                        AS fixed_id,
        qrd.room_unit_number,
        qrd.room_unit_name,
        qrd.quantity,
        qrd.$statusColumn           AS unit_status,
        qrd.$updatedColumn          AS unit_updated_at,
        'entry'                     AS source
    FROM quotation_room_distribution qrd
    INNER JOIN quotation_entries qe ON qrd.quotation_entry_id = qe.id
    WHERE qe.client_id = ? AND qrd.quotation_entry_id IS NOT NULL

    UNION ALL

    SELECT
        qrd.distribution_id,
        NULL                        AS entry_id,
        qrd.quotation_fixed_size_id AS fixed_id,
        qrd.room_unit_number,
        qrd.room_unit_name,
        qrd.quantity,
        qrd.$statusColumn           AS unit_status,
        qrd.$updatedColumn          AS unit_updated_at,
        'fixed'                     AS source
    FROM quotation_room_distribution qrd
    INNER JOIN quotation_fixed_sizes qfs ON qrd.quotation_fixed_size_id = qfs.id
    WHERE qfs.client_id = ? AND qrd.quotation_fixed_size_id IS NOT NULL

    ORDER BY room_unit_number
");
$distStmt->bind_param("ii", $client_id, $client_id);
$distStmt->execute();
$distRows = $distStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Index distributions by entry_id and fixed_id
$distByEntry = [];
$distByFixed = [];
foreach ($distRows as $d) {
    if ($d['source'] === 'entry' && $d['entry_id']) {
        $distByEntry[$d['entry_id']][] = $d;
    } elseif ($d['source'] === 'fixed' && $d['fixed_id']) {
        $distByFixed[$d['fixed_id']][] = $d;
    }
}

// ── Load area group labels FIRST before grouping ──
$groupLabels = [];
$glStmt = $conn->prepare("SELECT area, group_label FROM timeline_area_groups WHERE client_id=?");
$glStmt->bind_param("i", $client_id);
$glStmt->execute();
foreach ($glStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $gl) {
    $groupLabels[$gl['area']] = $gl['group_label'];
}

// Merge & group by area
$allItems = array_merge($rawItems, $rawFixed);
$areaGroups = [];
foreach ($allItems as $item) {
    $areaGroups[$item['area']][] = $item;
}
ksort($areaGroups);

// Build group-based display structure
$groupedDisplay = [];
$ungroupedDisplay = [];

foreach ($areaGroups as $area => $items) {
    $lbl = $groupLabels[$area] ?? null;
    if ($lbl) {
        if (!isset($groupedDisplay[$lbl])) {
            $groupedDisplay[$lbl] = ['label' => $lbl, 'areas' => [], 'type' => 'group'];
        }
        $groupedDisplay[$lbl]['areas'][] = $area;
    } else {
        $ungroupedDisplay[$area] = $items;
    }
}

// Build per-item previous-stage status map — item-level only, no unit logic
$prevStageStatusMap = [];
$stageDependencies = [
    'Delivery' => 'Fabrication',
    'Installation' => 'Delivery',
];
if (isset($stageDependencies[$stage])) {
    $prevStage = $stageDependencies[$stage];
    $prevStageCol = strtolower($prevStage) . '_status';

    $prevEntryStmt = $conn->prepare("SELECT id, COALESCE($prevStageCol, 'Pending') AS prev_status FROM quotation_entries WHERE client_id = ?");
    $prevEntryStmt->bind_param("i", $client_id);
    $prevEntryStmt->execute();
    foreach ($prevEntryStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $prevStageStatusMap['entry:' . $r['id']] = $r['prev_status'];
    }

    $prevFixedStmt = $conn->prepare("SELECT id, COALESCE($prevStageCol, 'Pending') AS prev_status FROM quotation_fixed_sizes WHERE client_id = ?");
    $prevFixedStmt->bind_param("i", $client_id);
    $prevFixedStmt->execute();
    foreach ($prevFixedStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $prevStageStatusMap['fixed:' . $r['id']] = $r['prev_status'];
    }
}

// ── Fetch area timelines ──
$tlStmt = $conn->prepare("SELECT * FROM timeline_area WHERE client_id = ?");
$tlStmt->bind_param("i", $client_id);
$tlStmt->execute();
$timelines = [];
foreach ($tlStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $tl) {
    $timelines[$tl['area']] = $tl;
}

// Overall timeline
$overallStart = $client['overall_start_date'] ?? '';
$overallEnd = $client['overall_end_date'] ?? '';
$overallDuration = $client['overall_duration'] ?? 0;

// Aggregate counts (count unit rows if they exist, else count item rows)
$statusCounts = array_fill_keys($stageStatuses, 0);
$total_items = 0;
foreach ($allItems as $it) {
    $units = ($it['source'] === 'entry')
        ? ($distByEntry[$it['id']] ?? [])
        : ($distByFixed[$it['id']] ?? []);

    if (!empty($units)) {
        foreach ($units as $u) {
            $s = $u['unit_status'] ?: 'Pending';
            if (isset($statusCounts[$s]))
                $statusCounts[$s]++;
            $total_items++;
        }
    } else {
        $s = $it['status'] ?: 'Pending';
        if (isset($statusCounts[$s]))
            $statusCounts[$s]++;
        $total_items++;
    }
}
$done_count = $statusCounts['Done'] ?? 0;
$completion_percentage = $total_items > 0 ? ($done_count / $total_items) * 100 : 0;

// Stage colors
$stageColors = [
    'Fabrication' => ['primary' => '#3b82f6', 'light' => '#eff6ff', 'border' => '#bfdbfe'],
    'Delivery' => ['primary' => '#8b5cf6', 'light' => '#f5f3ff', 'border' => '#ddd6fe'],
    'Installation' => ['primary' => '#10b981', 'light' => '#f0fdf4', 'border' => '#a7f3d0'],
    'BILLING' => ['primary' => '#f59e0b', 'light' => '#fffbeb', 'border' => '#fde68a'],
];
$sc = $stageColors[$stage] ?? $stageColors['Fabrication'];

$statusConfig = [
    'Pending' => ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#f59e0b'],
    'Ongoing' => ['bg' => '#dbeafe', 'color' => '#1e40af', 'border' => '#3b82f6'],
    'Done' => ['bg' => '#d1fae5', 'color' => '#065f46', 'border' => '#10b981'],
    'Incomplete' => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#ef4444'],
    'Punchlist' => ['bg' => '#fce7f3', 'color' => '#9d174d', 'border' => '#ec4899'],
];

// Pre-check stage dependencies for UI locking
$stageDepLocked = false;
$stageDepMessage = '';
$stageDependencies = [
    'Delivery' => 'Fabrication',
    'Installation' => 'Delivery',
];
// No full-stage lock — per-item locking is handled in the render below

function renderTimelineBar($tl)
{
    $phases = [
        'fab' => ['label' => 'Fab', 'color' => '#3b82f6'],
        'del' => ['label' => 'Del', 'color' => '#8b5cf6'],
        'ins' => ['label' => 'Ins', 'color' => '#10b981'],
    ];
    $dates = [];
    foreach ($phases as $k => $_) {
        if (!empty($tl[$k . '_start']))
            $dates[] = strtotime($tl[$k . '_start']);
        if (!empty($tl[$k . '_end']))
            $dates[] = strtotime($tl[$k . '_end']);
    }
    if (empty($dates))
        return '';
    $min = min($dates);
    $max = max($dates);
    $total = max(1, $max - $min);
    $html = '<div class="tl-bar-wrap">';
    foreach ($phases as $k => $ph) {
        if (empty($tl[$k . '_start']) || empty($tl[$k . '_end']))
            continue;
        $s = $tl[$k . '_start'];
        $e = $tl[$k . '_end'];
        $dur = $tl[$k . '_duration'] ?? 0;
        $l = round((strtotime($s) - $min) / $total * 100, 1);
        $w = max(round((strtotime($e) - strtotime($s)) / $total * 100, 1), 2);
        $html .= '<div class="tl-phase" style="left:' . $l . '%;width:' . $w . '%;background:' . $ph['color'] . ';"
                     title="' . $ph['label'] . ': ' . date('M d', strtotime($s)) . '–' . date('M d', strtotime($e)) . ' (' . $dur . 'd)">
                  <span class="tl-label">' . $ph['label'] . '</span></div>';
    }
    $html .= '</div><div class="tl-dates">';
    foreach ($phases as $k => $ph) {
        if (empty($tl[$k . '_start']))
            continue;
        $html .= '<span style="color:' . $ph['color'] . ';font-weight:700;">' . $ph['label'] . ': '
            . date('M d', strtotime($tl[$k . '_start'])) . '–' . date('M d', strtotime($tl[$k . '_end']))
            . ' <em>(' . (($tl[$k . '_duration']) ?? 0) . 'd)</em></span>';
    }
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= htmlspecialchars($stage) ?> Tracker — <?= htmlspecialchars($client['clientname']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --brand-dark: #3b1f0f;
            --brand-mid: #8a5a44;
            --brand-light: #f5f1ed;
            --stage-primary:
                <?= $sc['primary'] ?>
            ;
            --stage-light:
                <?= $sc['light'] ?>
            ;
            --stage-border:
                <?= $sc['border'] ?>
            ;
        }

        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--brand-light);
            font-family: 'Segoe UI', sans-serif;
            color: #1f2937;
        }

        .wrap {
            max-width: 1400px;
            margin: 28px auto;
            padding: 0 20px;
        }

        .page-header {
            background: linear-gradient(135deg, var(--brand-dark), var(--brand-mid));
            border-radius: 16px;
            padding: 26px 32px;
            color: white;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
        }

        .page-header h1 {
            font-size: 21px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-header .sub {
            font-size: 13px;
            opacity: .85;
            margin-top: 4px;
        }

        .stage-badge {
            background: rgba(255, 255, 255, .2);
            border: 1px solid rgba(255, 255, 255, .35);
            padding: 6px 18px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: linear-gradient(135deg, var(--brand-dark), var(--brand-mid));
            color: white;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            margin-bottom: 16px;
            transition: opacity .2s;
        }

        .btn-back:hover {
            opacity: .85;
        }

        /* Overall timeline banner */
        .otl-banner {
            background: white;
            border-radius: 12px;
            padding: 14px 22px;
            margin-bottom: 18px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .06);
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .otl-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--brand-dark);
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .otl-dates {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }

        .otl-chip {
            background: var(--brand-dark);
            color: white;
            padding: 3px 12px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
        }

        .otl-mini-bar {
            flex: 1;
            min-width: 180px;
            position: relative;
            height: 12px;
            background: #f0ece8;
            border-radius: 6px;
            overflow: hidden;
        }

        /* Progress card */
        .progress-card {
            background: white;
            border-radius: 12px;
            padding: 20px 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .07);
            margin-bottom: 20px;
        }

        .progress-card h2 {
            font-size: 15px;
            font-weight: 700;
            color: var(--brand-dark);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-grid {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .stat-box {
            flex: 1 1 90px;
            background: var(--brand-light);
            border-radius: 10px;
            padding: 12px 14px;
            text-align: center;
            border: 2px solid transparent;
            min-width: 80px;
        }

        .stat-box .num {
            font-size: 28px;
            font-weight: 800;
            line-height: 1;
        }

        .stat-box .lbl {
            font-size: 10px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-top: 3px;
        }

        .stat-Pending {
            border-color: #f59e0b;
        }

        .stat-Pending .num {
            color: #d97706;
        }

        .stat-Ongoing {
            border-color: #3b82f6;
        }

        .stat-Ongoing .num {
            color: #2563eb;
        }

        .stat-Done {
            border-color: #10b981;
        }

        .stat-Done .num {
            color: #059669;
        }

        .stat-Incomplete {
            border-color: #ef4444;
        }

        .stat-Incomplete .num {
            color: #dc2626;
        }

        .stat-Punchlist {
            border-color: #ec4899;
        }

        .stat-Punchlist .num {
            color: #db2777;
        }

        .stat-total {
            border-color: var(--brand-mid);
        }

        .stat-total .num {
            color: var(--brand-dark);
        }

        .prog-bar {
            height: 10px;
            background: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
        }

        .prog-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--brand-dark), var(--brand-mid));
            transition: width .5s;
            border-radius: 5px;
        }

        .prog-pct {
            font-size: 12px;
            font-weight: 700;
            color: var(--brand-dark);
            text-align: right;
            margin-top: 4px;
        }

        /* Area card */
        .area-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .07);
            margin-bottom: 22px;
            overflow: hidden;
        }

        .area-hdr {
            background: linear-gradient(135deg, var(--brand-dark), var(--brand-mid));
            color: white;
            padding: 13px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .area-hdr-name {
            font-size: 14px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .area-pills {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .area-pill {
            padding: 3px 9px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
            background: rgba(255, 255, 255, .2);
            border: 1px solid rgba(255, 255, 255, .3);
        }

        /* Timeline strip */
        .area-tl {
            padding: 11px 20px 10px;
            background: #fafafa;
            border-bottom: 1px solid #f0ece8;
        }

        .tl-bar-wrap {
            position: relative;
            height: 20px;
            background: #f0ece8;
            border-radius: 5px;
            overflow: visible;
            margin-bottom: 5px;
        }

        .tl-phase {
            position: absolute;
            top: 0;
            height: 100%;
            border-radius: 5px;
            display: flex;
            align-items: center;
            overflow: hidden;
            transition: opacity .15s;
            cursor: default;
        }

        .tl-phase:hover {
            opacity: .8;
            z-index: 2;
        }

        .tl-label {
            color: white;
            font-size: 9px;
            font-weight: 800;
            padding-left: 5px;
            text-transform: uppercase;
            pointer-events: none;
        }

        .tl-dates {
            display: flex;
            gap: 12px;
            font-size: 10px;
            flex-wrap: wrap;
        }

        .tl-dates em {
            font-style: normal;
            opacity: .7;
        }

        .no-tl {
            font-size: 11px;
            color: #9ca3af;
            font-style: italic;
            padding: 8px 20px;
            background: #fafafa;
            border-bottom: 1px solid #f0ece8;
        }

        /* Monitoring table */
        .mon-wrap {
            overflow-x: auto;
            padding: 14px 18px 18px;
        }

        .mon-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            min-width: 600px;
        }

        .mon-table th {
            background: var(--brand-dark);
            color: white;
            padding: 8px 11px;
            font-size: 11px;
            font-weight: 700;
            border: 1px solid rgba(255, 255, 255, .12);
            white-space: nowrap;
        }

        .mon-table th.l {
            text-align: left;
        }

        .mon-table th.c {
            text-align: center;
        }

        .mon-table td {
            padding: 7px 11px;
            border: 1px solid #e9ecef;
            vertical-align: middle;
        }

        .mon-table tr.item-row:hover td {
            background: var(--stage-light);
        }

        /* Item name */
        .item-name-td {
            font-weight: 700;
            color: #111;
            font-size: 12px;
        }

        .item-color-sub {
            font-size: 10px;
            color: #6b7280;
            margin-top: 2px;
        }

        .src-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 7px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            margin-left: 4px;
        }

        .src-entry {
            background: #dbeafe;
            color: #1e40af;
        }

        .src-fixed {
            background: #fce7f3;
            color: #9d174d;
        }

        /* Unit block */
        .unit-block {
            border-radius: 10px;
            margin-bottom: 14px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .06);
        }

        .unit-block-hdr {
            user-select: none;
            transition: filter .15s;
        }

        .unit-block-hdr:hover {
            filter: brightness(.97);
        }

        .unit-block-body .mon-table th {
            background: #4b5563;
        }

        /* Status buttons */
        .s-wrap {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }

        .s-btn {
            padding: 3px 9px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            border: 2px solid transparent;
            cursor: pointer;
            transition: all .12s;
            white-space: nowrap;
            background: none;
        }

        .s-btn:hover {
            transform: scale(1.06);
        }

        .s-btn.active {
            transform: scale(1.08);
        }

        .s-Pending {
            background: #fef3c7;
            color: #92400e;
        }

        .s-Pending.active {
            border-color: #f59e0b;
        }

        .s-Ongoing {
            background: #dbeafe;
            color: #1e40af;
        }

        .s-Ongoing.active {
            border-color: #3b82f6;
        }

        .s-Done {
            background: #d1fae5;
            color: #065f46;
        }

        .s-Done.active {
            border-color: #10b981;
        }

        .s-Incomplete {
            background: #fee2e2;
            color: #991b1b;
        }

        .s-Incomplete.active {
            border-color: #ef4444;
        }

        .s-Punchlist {
            background: #fce7f3;
            color: #9d174d;
        }

        .s-Punchlist.active {
            border-color: #ec4899;
        }

        .upd-cell {
            font-size: 10px;
            color: #9ca3af;
        }

        .summary-row td {
            background: #f0e6db !important;
            font-weight: 700;
            color: var(--brand-dark);
        }

        .no-units-label {
            font-size: 11px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin: 16px 0 8px;
            padding: 0 2px;
        }

        /* Toast */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 13px 20px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, .14);
            display: none;
            align-items: center;
            gap: 11px;
            z-index: 9999;
            animation: slideIn .3s ease;
            font-size: 13px;
            font-weight: 600;
        }

        .toast.show {
            display: flex;
        }

        .toast.success {
            border-left: 4px solid #10b981;
        }

        .toast.error {
            border-left: 4px solid #ef4444;
        }

        @keyframes slideIn {
            from {
                transform: translateX(360px);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @media(max-width:700px) {
            .stat-grid {
                gap: 8px;
            }

            .s-wrap {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">

        <?php
        if ($came_from === 'manager') {
            $backHref = '../manager_tracker/manager_project_detail.php?client_id=' . $client_id;
            $backLabel = 'Back to Project Detail';
        } else {
            $backHref = 'unified_project_tracker.php?client_id=' . $client_id;
            $backLabel = 'Back to Project Tracker';
        }
        ?>
        <a href="<?= $backHref ?>" class="btn-back">
            <i class="fas fa-arrow-left"></i> <?= $backLabel ?>
        </a>

        <?php if ($view_only): ?>
            <div
                style="background:#fef3c7; border:2px solid #f59e0b; border-radius:10px; padding:13px 18px; margin-bottom:16px; display:flex; align-items:center; gap:10px; font-size:13px; font-weight:600; color:#92400e;">
                <i class="fas fa-eye"></i> View Only — You can view this stage but cannot make changes.
            </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="page-header">
            <div>
                <h1>
                    <i
                        class="fas fa-<?= $stage === 'Fabrication' ? 'tools' : ($stage === 'Delivery' ? 'truck' : ($stage === 'Installation' ? 'hard-hat' : 'file-invoice-dollar')) ?>"></i>
                    <?= htmlspecialchars($stage) ?> Tracker
                </h1>
                <div class="sub">
                    <?= htmlspecialchars($client['clientname']) ?> &nbsp;·&nbsp;
                    <?= htmlspecialchars($client['nameproject']) ?>
                    <?php if ($client['reference_number']): ?>
                        &nbsp;·&nbsp;<span
                            style="font-family:monospace;"><?= htmlspecialchars($client['reference_number']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="stage-badge">
                <i class="fas fa-circle" style="font-size:8px;color:<?= $sc['primary'] ?>;"></i>
                <?= htmlspecialchars($stage) ?> Stage
            </div>
        </div>

        <!-- Overall Timeline Banner -->
        <?php if ($overallStart): ?>
            <div class="otl-banner">
                <div>
                    <div class="otl-label"><i class="fas fa-calendar-alt"></i> Overall Project Timeline</div>
                    <div class="otl-dates">
                        <?= date('F d, Y', strtotime($overallStart)) ?> &rarr;
                        <?= date('F d, Y', strtotime($overallEnd)) ?>
                    </div>
                </div>
                <span class="otl-chip"><?= $overallDuration ?> days</span>
                <?php
                $allTlDates = [];
                foreach ($timelines as $tl) {
                    foreach (['fab', 'del', 'ins'] as $k) {
                        if (!empty($tl[$k . '_start']))
                            $allTlDates[] = strtotime($tl[$k . '_start']);
                        if (!empty($tl[$k . '_end']))
                            $allTlDates[] = strtotime($tl[$k . '_end']);
                    }
                }
                $oMin = $allTlDates ? min($allTlDates) : strtotime($overallStart);
                $oMax = $allTlDates ? max($allTlDates) : strtotime($overallEnd);
                $oTot = max(1, $oMax - $oMin);
                $phColors = ['fab' => '#3b82f6', 'del' => '#8b5cf6', 'ins' => '#10b981'];
                ?>
                <div class="otl-mini-bar">
                    <?php foreach ($timelines as $areaTl):
                        foreach ($phColors as $k => $c):
                            if (empty($areaTl[$k . '_start']))
                                continue;
                            $s = strtotime($areaTl[$k . '_start']);
                            $e = strtotime($areaTl[$k . '_end']);
                            $l = round(($s - $oMin) / $oTot * 100, 1);
                            $w = max(round(($e - $s) / $oTot * 100, 1), 1);
                            ?>
                            <div
                                style="position:absolute;top:0;height:100%;left:<?= $l ?>%;width:<?= $w ?>%;background:<?= $c ?>;opacity:.65;">
                            </div>
                        <?php endforeach; endforeach; ?>
                </div>
                <div style="display:flex;gap:10px;font-size:11px;font-weight:700;">
                    <span style="color:#3b82f6;">● Fab</span>
                    <span style="color:#8b5cf6;">● Del</span>
                    <span style="color:#10b981;">● Ins</span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Progress -->
        <div class="progress-card">
            <h2><i class="fas fa-chart-pie" style="color:<?= $sc['primary'] ?>;"></i> <?= htmlspecialchars($stage) ?>
                Progress
                <span style="font-size:11px;font-weight:400;color:#6b7280;margin-left:6px;">(counting per unit where
                    distributed)</span>
            </h2>
            <div class="stat-grid">
                <?php foreach ($stageStatuses as $st): ?>
                    <div class="stat-box stat-<?= $st ?>">
                        <div class="num"><?= $statusCounts[$st] ?? 0 ?></div>
                        <div class="lbl"><?= $st ?></div>
                    </div>
                <?php endforeach; ?>
                <div class="stat-box stat-total">
                    <div class="num"><?= $total_items ?></div>
                    <div class="lbl">Total</div>
                </div>
            </div>
            <div class="prog-bar">
                <div class="prog-fill" style="width:<?= $completion_percentage ?>%;"></div>
            </div>
            <div class="prog-pct"><?= number_format($completion_percentage, 1) ?>% Complete</div>
        </div>

        <!-- Area Tables -->
        <?php
// Build display order: grouped entries first, then ungrouped
$displayOrder = [];
foreach ($groupedDisplay as $lbl => $gEntry) {
    $displayOrder[] = ['type' => 'group', 'label' => $lbl, 'areas' => $gEntry['areas']];
}
foreach ($ungroupedDisplay as $area => $items) {
    $displayOrder[] = ['type' => 'single', 'label' => $area, 'areas' => [$area]];
}
?>

<?php foreach ($displayOrder as $displayEntry):
    $entryAreas = $displayEntry['areas'];
    $entryLabel = $displayEntry['label'];
    $isGroup = $displayEntry['type'] === 'group';

    // Merge all items across areas in this group
    $areaItemsMerged = [];
    foreach ($entryAreas as $area) {
        foreach (($areaGroups[$area] ?? []) as $item) {
            $areaItemsMerged[] = $item;
        }
    }

    // Use first area's timeline (groups share same timeline)
    $tl = $timelines[$entryAreas[0]] ?? [];

    // Build unit-grouped structure for all areas in group
    $unitGroups = [];
    $noUnitItems = [];

    foreach ($areaItemsMerged as $item) {
        $itemUnits = ($item['source'] === 'entry')
            ? ($distByEntry[$item['id']] ?? [])
            : ($distByFixed[$item['id']] ?? []);

        if (empty($itemUnits)) {
            $noUnitItems[] = $item;
        } else {
            foreach ($itemUnits as $dist) {
                $uNum = $dist['room_unit_number'];
                $uName = $dist['room_unit_name'] ?? '';
                $uKey = $uNum . '|' . $uName;
                if (!isset($unitGroups[$uKey])) {
                    $unitGroups[$uKey] = ['number' => $uNum, 'name' => $uName, 'items' => []];
                }
                $unitGroups[$uKey]['items'][] = ['item' => $item, 'dist' => $dist];
            }
        }
    }
    ksort($unitGroups);

    // Area-level counts (across all areas in group)
    $aCounts = array_fill_keys($stageStatuses, 0);
    $aTotal = 0;
    foreach ($areaItemsMerged as $it) {
        $units = ($it['source'] === 'entry') ? ($distByEntry[$it['id']] ?? []) : ($distByFixed[$it['id']] ?? []);
        if (!empty($units)) {
            foreach ($units as $u) {
                $s = $u['unit_status'] ?: 'Pending';
                if (isset($aCounts[$s])) $aCounts[$s]++;
                $aTotal++;
            }
        } else {
            $s = $it['status'] ?: 'Pending';
            if (isset($aCounts[$s])) $aCounts[$s]++;
            $aTotal++;
        }
    }
    $aDone = $aCounts['Done'] ?? 0;
    $aPct = $aTotal > 0 ? round($aDone / $aTotal * 100) : 0;
    $aAreaAmt = array_sum(array_column($areaItemsMerged, 'computed_tot_amount'));
    $aAreaQty = array_sum(array_column($areaItemsMerged, 'quantity'));
?>
    <div class="area-card">

        <div class="area-hdr">
            <div class="area-hdr-name">
                <?php if ($isGroup): ?>
                    <i class="fas fa-object-group"></i>
                    <?= htmlspecialchars($entryLabel) ?>
                    <span style="background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.3);padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;margin-left:6px;">
                        <i class="fas fa-layer-group"></i>
                        <?= count($entryAreas) ?> area<?= count($entryAreas) > 1 ? 's' : '' ?>:
                        <?= htmlspecialchars(implode(', ', $entryAreas)) ?>
                    </span>
                <?php else: ?>
                    <i class="fas fa-map-marker-alt"></i>
                    <?= htmlspecialchars($entryLabel) ?>
                <?php endif; ?>
            </div>
            <div class="area-pills">
                <?php foreach ($stageStatuses as $st):
                    if (!($aCounts[$st] ?? 0)) continue; ?>
                    <span class="area-pill"><?= $st ?>: <?= $aCounts[$st] ?></span>
                <?php endforeach; ?>
                <span class="area-pill" style="background:rgba(255,255,255,.35);"><?= $aPct ?>% done</span>
                <?php if (!empty($unitGroups)): ?>
                    <span class="area-pill" style="background:rgba(255,255,255,.2);">
                        <i class="fas fa-door-open"></i> <?= count($unitGroups) ?> unit<?= count($unitGroups) > 1 ? 's' : '' ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($tl['ins_end'])): ?>
                    <span class="area-pill" style="background:rgba(16,185,129,.3);border-color:rgba(16,185,129,.5);">
                        <i class="fas fa-calendar-check"></i> Install by: <?= date('M d, Y', strtotime($tl['ins_end'])) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($tl)): ?>
            <div class="area-tl"><?= renderTimelineBar($tl) ?></div>
        <?php else: ?>
            <div class="no-tl"><i class="fas fa-calendar-times"></i> No timeline set for this group yet.</div>
        <?php endif; ?>

        <div class="mon-wrap">

            <?php if (!empty($unitGroups)): ?>
                <?php foreach ($unitGroups as $uKey => $uGroup):
                    $uNum = $uGroup['number'];
                    $uName = $uGroup['name'];
                    $uLabel = $uName ? 'Unit ' . $uNum . ' — ' . htmlspecialchars($uName) : 'Unit ' . $uNum;
                    $uItems = $uGroup['items'];

                    $uCounts = array_fill_keys($stageStatuses, 0);
                    foreach ($uItems as $ui) {
                        $s = $ui['dist']['unit_status'] ?: 'Pending';
                        if (isset($uCounts[$s])) $uCounts[$s]++;
                    }
                    $uTotal = count($uItems);
                    $uDone = $uCounts['Done'] ?? 0;
                    $uPct = $uTotal > 0 ? round($uDone / $uTotal * 100) : 0;
                    $anyOngoing = ($uCounts['Ongoing'] ?? 0) + ($uCounts['Incomplete'] ?? 0) + ($uCounts['Punchlist'] ?? 0);
                    $allDone = ($uDone === $uTotal && $uTotal > 0);

                    if ($allDone) {
                        $uHdrBg = '#d1fae5'; $uHdrColor = '#065f46'; $uHdrBorder = '#10b981';
                        $uBadgeBg = '#059669'; $uIcon = 'fa-check-circle';
                    } elseif ($anyOngoing > 0) {
                        $uHdrBg = '#dbeafe'; $uHdrColor = '#1e40af'; $uHdrBorder = '#3b82f6';
                        $uBadgeBg = '#2563eb'; $uIcon = 'fa-spinner';
                    } else {
                        $uHdrBg = '#fef3c7'; $uHdrColor = '#92400e'; $uHdrBorder = '#f59e0b';
                        $uBadgeBg = '#d97706'; $uIcon = 'fa-clock';
                    }

                    $slugUnit = 'ublk_' . preg_replace('/[^a-z0-9]/i', '_', $entryLabel) . '_' . $uNum;
                    ?>
                    <div class="unit-block" style="border:2px solid <?= $uHdrBorder ?>;">
                        <div class="unit-block-hdr"
                            style="background:<?= $uHdrBg ?>;border-bottom:2px solid <?= $uHdrBorder ?>;padding:11px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;cursor:pointer;"
                            onclick="toggleUnitBlock('<?= $slugUnit ?>', this)">
                            <div style="display:flex;align-items:center;gap:12px;">
                                <span style="background:<?= $uBadgeBg ?>;color:white;width:34px;height:34px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0;box-shadow:0 2px 6px rgba(0,0,0,.15);">
                                    <?= $uNum ?>
                                </span>
                                <div>
                                    <div style="font-size:14px;font-weight:700;color:<?= $uHdrColor ?>;display:flex;align-items:center;gap:7px;">
                                        <i class="fas fa-door-open" style="font-size:12px;opacity:.8;"></i>
                                        <?= $uLabel ?>
                                    </div>
                                    <div style="font-size:11px;color:<?= $uHdrColor ?>;opacity:.7;margin-top:2px;">
                                        <?= $uTotal ?> item<?= $uTotal > 1 ? 's' : '' ?> &nbsp;·&nbsp; <?= $uPct ?>% complete
                                    </div>
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <?php foreach ($stageStatuses as $st):
                                    if (!($uCounts[$st] ?? 0)) continue; ?>
                                    <span style="background:<?= $statusConfig[$st]['bg'] ?>;color:<?= $statusConfig[$st]['color'] ?>;border:1px solid <?= $statusConfig[$st]['border'] ?>;padding:3px 10px;border-radius:10px;font-size:10px;font-weight:700;">
                                        <?= $st ?>: <?= $uCounts[$st] ?>
                                    </span>
                                <?php endforeach; ?>
                                <div style="width:70px;height:6px;background:rgba(0,0,0,.12);border-radius:3px;overflow:hidden;">
                                    <div style="width:<?= $uPct ?>%;height:100%;background:<?= $uBadgeBg ?>;border-radius:3px;transition:width .4s;"></div>
                                </div>
                                <span style="font-size:10px;font-weight:700;color:<?= $uHdrColor ?>;min-width:32px;"><?= $uPct ?>%</span>
                                <i class="fas fa-chevron-down" id="chev-<?= $slugUnit ?>" style="color:<?= $uHdrColor ?>;font-size:13px;transition:transform .25s;margin-left:2px;"></i>
                            </div>
                        </div>

                        <div id="<?= $slugUnit ?>" style="display:none;">
                            <table class="mon-table" style="width:100%;">
                                <thead>
                                    <tr>
                                        <th class="l" style="min-width:180px;">Item Name</th>
                                        <?php if ($isGroup): ?>
                                            <th class="c" style="min-width:80px;">Area</th>
                                        <?php endif; ?>
                                        <th class="c" style="min-width:60px;">Type</th>
                                        <th class="c" style="min-width:45px;">Qty</th>
                                        <th class="l" style="min-width:<?= $isInstallation ? '320px' : '220px' ?>;">Status</th>
                                        <th class="c" style="min-width:100px;">Amount</th>
                                        <th class="l" style="min-width:120px;">Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($uItems as $ui):
                                        $item = $ui['item'];
                                        $dist = $ui['dist'];
                                        $uStatus = $dist['unit_status'] ?: 'Pending';
                                        $rowBg = $statusConfig[$uStatus]['bg'] ?? '#fff';
                                        ?>
                                        <tr class="item-row" style="background:<?= $rowBg ?>;">
                                            <td>
                                                <div class="item-name-td">
                                                    <?= htmlspecialchars($item['item_name'] ?? 'N/A') ?>
                                                    <span class="src-badge <?= $item['source'] === 'entry' ? 'src-entry' : 'src-fixed' ?>">
                                                        <?= $item['source'] === 'entry' ? 'Entry' : 'Fixed' ?>
                                                    </span>
                                                </div>
                                                <button onclick="viewRemarks(<?= $item['id'] ?>, '<?= $item['source'] ?>', <?= $dist['distribution_id'] ?>, '<?= htmlspecialchars($item['item_name'] ?? '', ENT_QUOTES) ?>')"
                                                    style="margin-top:4px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:6px;padding:2px 8px;font-size:10px;font-weight:700;cursor:pointer;">
                                                    <i class="fas fa-history"></i> Remarks
                                                </button>
                                                <?php if (!empty($item['color_label'])): ?>
                                                    <div class="item-color-sub"><i class="fas fa-palette"></i> <?= htmlspecialchars($item['color_label']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <?php if ($isGroup): ?>
                                                <td style="text-align:center;font-size:11px;font-weight:700;color:#6b7280;">
                                                    <?= htmlspecialchars($item['area']) ?>
                                                </td>
                                            <?php endif; ?>
                                            <td style="text-align:center;font-weight:700;color:#374151;"><?= htmlspecialchars($item['unit'] ?? '—') ?></td>
                                            <td style="text-align:center;font-weight:700;color:var(--brand-dark);"><?= $dist['quantity'] ?></td>
                                            <td>
                                                <div class="s-wrap">
                                                    <?php foreach ($stageStatuses as $st):
                                                        $active = ($uStatus === $st) ? ' active' : '';
                                                        $itemPrevKey = $item['source'] . ':' . $item['id'];
                                                        $itemPrevStatus = $prevStageStatusMap[$itemPrevKey] ?? 'Done';
                                                        $itemDepLocked = isset($stageDependencies[$stage]) && ($itemPrevStatus !== 'Done');
                                                        ?>
                                                        <button class="s-btn s-<?= $st ?><?= $active ?>"
                                                            <?= ($view_only || $itemDepLocked) ? 'disabled style="cursor:not-allowed; opacity:0.6;"' : "onclick=\"updateUnitStatus({$dist['distribution_id']}, '{$st}', this, {$item['id']}, '{$item['source']}')\"" ?>>
                                                            <?= $st ?>
                                                        </button>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                            <td style="text-align:right;font-weight:600;font-size:11px;color:#374151;">₱<?= number_format($item['computed_tot_amount'], 2) ?></td>
                                            <td class="upd-cell">
                                                <?= (!empty($dist['unit_updated_at']) && $dist['unit_updated_at'] !== '0000-00-00 00:00:00')
                                                    ? '<i class="fas fa-clock"></i> ' . date('M d, Y g:i A', strtotime($dist['unit_updated_at']))
                                                    : '—' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($noUnitItems)): ?>
                <?php if (!empty($unitGroups)): ?>
                    <div class="no-units-label" style="display:flex;align-items:center;gap:6px;">
                        <i class="fas fa-box"></i> Individual Items
                        <span style="font-size:10px;font-weight:400;color:#9ca3af;">(no unit distribution)</span>
                    </div>
                <?php endif; ?>
                <table class="mon-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th class="l" style="min-width:180px;">Item Name</th>
                            <?php if ($isGroup): ?>
                                <th class="c" style="min-width:80px;">Area</th>
                            <?php endif; ?>
                            <th class="c" style="min-width:60px;">Type</th>
                            <th class="c" style="min-width:45px;">Qty</th>
                            <th class="l" style="min-width:<?= $isInstallation ? '320px' : '220px' ?>;">Status</th>
                            <th class="c" style="min-width:100px;">Amount</th>
                            <th class="l" style="min-width:120px;">Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($noUnitItems as $item):
                            $status = $item['status'] ?: 'Pending';
                            $rowBg = $statusConfig[$status]['bg'] ?? '#fff';
                            ?>
                            <tr class="item-row" style="background:<?= $rowBg ?>;">
                                <td>
                                    <div class="item-name-td">
                                        <?= htmlspecialchars($item['item_name'] ?? 'N/A') ?>
                                        <span class="src-badge <?= $item['source'] === 'entry' ? 'src-entry' : 'src-fixed' ?>">
                                            <?= $item['source'] === 'entry' ? 'Entry' : 'Fixed' ?>
                                        </span>
                                    </div>
                                    <button onclick="viewRemarks(<?= $item['id'] ?>, '<?= $item['source'] ?>', null, '<?= htmlspecialchars($item['item_name'] ?? '', ENT_QUOTES) ?>')"
                                        style="margin-top:4px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:6px;padding:2px 8px;font-size:10px;font-weight:700;cursor:pointer;">
                                        <i class="fas fa-history"></i> Remarks
                                    </button>
                                    <?php if (!empty($item['color_label'])): ?>
                                        <div class="item-color-sub"><i class="fas fa-palette"></i> <?= htmlspecialchars($item['color_label']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <?php if ($isGroup): ?>
                                    <td style="text-align:center;font-size:11px;font-weight:700;color:#6b7280;">
                                        <?= htmlspecialchars($item['area']) ?>
                                    </td>
                                <?php endif; ?>
                                <td style="text-align:center;font-weight:700;color:#374151;"><?= htmlspecialchars($item['unit'] ?? '—') ?></td>
                                <td style="text-align:center;font-weight:700;color:var(--brand-dark);"><?= $item['quantity'] ?></td>
                                <td>
                                    <div class="s-wrap">
                                        <?php foreach ($stageStatuses as $st):
                                            $active = ($status === $st) ? ' active' : '';
                                            $itemPrevKey = $item['source'] . ':' . $item['id'];
                                            $itemPrevStatus = $prevStageStatusMap[$itemPrevKey] ?? 'Done';
                                            $itemDepLocked = isset($stageDependencies[$stage]) && ($itemPrevStatus !== 'Done');
                                            ?>
                                            <button class="s-btn s-<?= $st ?><?= $active ?>"
                                                <?= ($view_only || $itemDepLocked) ? 'disabled style="cursor:not-allowed; opacity:0.6;"' : "onclick=\"updateItemStatus({$item['id']}, '{$st}', this, '{$item['source']}')\"" ?>>
                                                <?= $st ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td style="text-align:right;font-weight:600;">₱<?= number_format($item['computed_tot_amount'], 2) ?></td>
                                <td class="upd-cell">
                                    <?= (!empty($item['updated_at']) && $item['updated_at'] !== '0000-00-00 00:00:00')
                                        ? '<i class="fas fa-clock"></i> ' . date('M d, Y g:i A', strtotime($item['updated_at']))
                                        : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Area Summary Footer -->
            <table class="mon-table" style="width:100%;margin-top:10px;">
                <tfoot>
                    <tr class="summary-row">
                        <td colspan="<?= $isGroup ? '3' : '2' ?>" style="text-align:right;font-size:11px;">
                            <?= $isGroup ? htmlspecialchars($entryLabel) . ' Totals' : 'Area Totals' ?>
                        </td>
                        <td style="text-align:center;"><?= $aAreaQty ?></td>
                        <td>
                            <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                <?php foreach ($stageStatuses as $st):
                                    if (!($aCounts[$st] ?? 0)) continue; ?>
                                    <span style="background:<?= $statusConfig[$st]['bg'] ?>;color:<?= $statusConfig[$st]['color'] ?>;padding:2px 7px;border-radius:8px;font-weight:700;font-size:10px;">
                                        <?= $st ?>: <?= $aCounts[$st] ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td style="text-align:right;">₱<?= number_format($aAreaAmt, 2) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

        </div>
    </div>
<?php endforeach; ?>

        <?php if (empty($areaGroups)): ?>
            <div style="text-align:center;padding:50px;color:#9ca3af;background:white;border-radius:12px;">
                <i class="fas fa-inbox" style="font-size:40px;display:block;margin-bottom:12px;"></i>
                No items found for this client yet.
            </div>
        <?php endif; ?>

    </div>

    <!-- Remarks Modal -->
    <div id="remarkModal"
        style="display:none;position:fixed;z-index:9998;inset:0;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;">
        <div
            style="background:white;border-radius:14px;padding:26px;max-width:480px;width:90%;box-shadow:0 20px 50px rgba(0,0,0,.2);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                <h3 id="remarkModalTitle"
                    style="font-size:15px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-comment-alt" style="color:#3b82f6;"></i> Add Remark
                </h3>
                <button onclick="closeRemarkModal()"
                    style="background:none;border:none;font-size:18px;color:#6b7280;cursor:pointer;line-height:1;">&times;</button>
            </div>
            <div id="remarkStatusBadge" style="margin-bottom:12px;"></div>
            <textarea id="remarkText" rows="4" placeholder="Enter your remark here… (optional)"
                style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;margin-bottom:14px;"></textarea>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button onclick="closeRemarkModal()"
                    style="background:#f1f5f9;color:#475569;padding:9px 18px;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;">
                    Cancel
                </button>
                <button onclick="skipRemark()"
                    style="background:#e2e8f0;color:#374151;padding:9px 18px;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;">
                    <i class="fas fa-forward"></i> Skip
                </button>
                <button onclick="submitRemark()"
                    style="background:linear-gradient(135deg,#3b1f0f,#8a5a44);color:white;padding:9px 18px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;">
                    <i class="fas fa-paper-plane"></i> Save & Update
                </button>
            </div>
        </div>
    </div>

    <!-- Remarks History Modal -->
    <div id="historyModal"
        style="display:none;position:fixed;z-index:9998;inset:0;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;">
        <div
            style="background:white;border-radius:14px;padding:26px;max-width:500px;width:90%;box-shadow:0 20px 50px rgba(0,0,0,.2);max-height:80vh;overflow-y:auto;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <h3 style="font-size:15px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-history" style="color:#8a5a44;"></i> Remark History
                </h3>
                <button onclick="closeHistoryModal()"
                    style="background:none;border:none;font-size:18px;color:#6b7280;cursor:pointer;">&times;</button>
            </div>
            <div id="historyContent">Loading...</div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="toast">
        <i id="toastIcon" class="fas fa-check-circle" style="font-size:18px;color:#10b981;"></i>
        <span id="toastMsg">Status updated!</span>
    </div>

    <script>
        const STAGE = '<?= addslashes($stage) ?>';
        const CLIENT_ID = <?= $client_id ?>;

        // ── Pending action — stored while modal is open ──
        let _pendingAction = null;

        // ── Status color map for badge ──
        const statusColors = {
            Pending: { bg: '#fef3c7', color: '#92400e', border: '#f59e0b' },
            Ongoing: { bg: '#dbeafe', color: '#1e40af', border: '#3b82f6' },
            Done: { bg: '#d1fae5', color: '#065f46', border: '#10b981' },
            Incomplete: { bg: '#fee2e2', color: '#991b1b', border: '#ef4444' },
            Punchlist: { bg: '#fce7f3', color: '#9d174d', border: '#ec4899' },
        };

        function toggleUnitBlock(slugId, hdrEl) {
            const body = document.getElementById(slugId);
            const chev = document.getElementById('chev-' + slugId);
            if (!body) return;
            const isOpen = body.style.display !== 'none';
            body.style.display = isOpen ? 'none' : 'block';
            if (chev) chev.style.transform = isOpen ? '' : 'rotate(180deg)';
        }

        // Called when a status button is clicked — opens modal first
        function updateItemStatus(itemId, newStatus, btn, source) {
            _pendingAction = {
                type: 'item', itemId, newStatus, btn, source,
                distId: null
            };
            openRemarkModal(newStatus);
        }

        function updateUnitStatus(distId, newStatus, btn, itemId, source) {
            _pendingAction = {
                type: 'unit', distId, newStatus, btn, itemId, source
            };
            openRemarkModal(newStatus);
        }

        // ── Modal controls ──
        function openRemarkModal(status) {
            const sc = statusColors[status] || { bg: '#f1f5f9', color: '#374151', border: '#e2e8f0' };
            document.getElementById('remarkModalTitle').innerHTML =
                `<i class="fas fa-comment-alt" style="color:${sc.border};"></i> Add Remark for Status Change`;
            document.getElementById('remarkStatusBadge').innerHTML =
                `<span style="background:${sc.bg};color:${sc.color};border:1.5px solid ${sc.border};
         padding:4px 14px;border-radius:12px;font-size:12px;font-weight:700;">
         → ${status}</span>`;
            document.getElementById('remarkText').value = '';
            const modal = document.getElementById('remarkModal');
            modal.style.display = 'flex';
            setTimeout(() => document.getElementById('remarkText').focus(), 100);
        }

        function closeRemarkModal() {
            document.getElementById('remarkModal').style.display = 'none';
            _pendingAction = null;
        }

        function skipRemark() {
            // Proceed without saving a remark
            executeStatusUpdate('');
        }

        function submitRemark() {
            const remark = document.getElementById('remarkText').value.trim();
            executeStatusUpdate(remark);
        }

        async function executeStatusUpdate(remark) {
            document.getElementById('remarkModal').style.display = 'none';
            if (!_pendingAction) return;

            const { type, itemId, distId, newStatus, btn, source } = _pendingAction;
            _pendingAction = null;

            // Optimistic UI update
            const row = btn.closest('tr.item-row');
            const allBtns = row.querySelectorAll('.s-btn');
            allBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            applyRowBg(row, newStatus);

            const updCell = row.querySelector('.upd-cell');

            if (type === 'unit') {
                if (updCell) updCell.innerHTML = '<i class="fas fa-clock"></i> Just now';
                await doUpdate('update_unit_status.php', {
                    distribution_id: distId,
                    stage: STAGE,
                    status: newStatus,
                    item_id: itemId,
                    source,
                    remark
                });
            } else {
                await doUpdate('update_item_status.php', {
                    entry_id: itemId,
                    source,
                    stage: STAGE,
                    status: newStatus,
                    remark
                });
            }

            // Refresh remark badge on the row
            if (remark) {
                let badge = row.querySelector('.remark-badge');
                if (!badge) {
                    badge = document.createElement('button');
                    badge.className = 'remark-badge';
                    badge.style.cssText = 'background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:6px;padding:2px 7px;font-size:10px;font-weight:700;cursor:pointer;margin-left:6px;';
                    badge.innerHTML = '<i class="fas fa-comment-dots"></i> <span class="remark-count">1</span>';
                    const nameDiv = row.querySelector('.item-name-td');
                    if (nameDiv) nameDiv.appendChild(badge);
                } else {
                    const countEl = badge.querySelector('.remark-count');
                    if (countEl) countEl.textContent = parseInt(countEl.textContent || 0) + 1;
                }
            }
        }

        async function doUpdate(endpoint, payload) {
            try {
                const resp = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await resp.json();
                if (result.success) {
                    showToast('Status updated to ' + payload.status, 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    showToast('Error: ' + (result.error || 'Unknown'), 'error');
                    location.reload();
                }
            } catch (e) {
                showToast('Network error', 'error');
                location.reload();
            }
        }

        // ── Remark History ──
        async function viewRemarks(itemId, source, distId = null, itemName = '') {
            document.getElementById('historyContent').innerHTML =
                '<div style="text-align:center;padding:20px;color:#9ca3af;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            document.getElementById('historyModal').style.display = 'flex';

            try {
                const params = new URLSearchParams({
                    client_id: CLIENT_ID, stage: STAGE, item_id: itemId,
                    source, ...(distId ? { distribution_id: distId } : {})
                });
                const resp = await fetch('get_item_remarks.php?' + params);
                const data = await resp.json();

                if (!data.success || !data.remarks.length) {
                    document.getElementById('historyContent').innerHTML =
                        '<div style="text-align:center;padding:24px;color:#9ca3af;"><i class="fas fa-comment-slash" style="font-size:28px;display:block;margin-bottom:8px;"></i>No remarks yet.</div>';
                    return;
                }

                const bgMap = { Pending: '#fef3c7', Ongoing: '#dbeafe', Done: '#d1fae5', Incomplete: '#fee2e2', Punchlist: '#fce7f3' };
                const clMap = { Pending: '#92400e', Ongoing: '#1e40af', Done: '#065f46', Incomplete: '#991b1b', Punchlist: '#9d174d' };
                const bdMap = { Pending: '#f59e0b', Ongoing: '#3b82f6', Done: '#10b981', Incomplete: '#ef4444', Punchlist: '#ec4899' };

                let html = `<div style="font-size:12px;color:#6b7280;margin-bottom:12px;">${data.remarks.length} remark(s) for <strong>${itemName}</strong></div>`;
                data.remarks.forEach(r => {
                    const sc = { bg: bgMap[r.status] || '#f1f5f9', color: clMap[r.status] || '#374151', border: bdMap[r.status] || '#e2e8f0' };
                    html += `
            <div style="border:1.5px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-bottom:10px;background:#fafcff;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;margin-bottom:7px;">
                    <span style="background:${sc.bg};color:${sc.color};border:1px solid ${sc.border};padding:2px 10px;border-radius:10px;font-size:10px;font-weight:700;">${r.status}</span>
                    <span style="font-size:11px;color:#9ca3af;"><i class="fas fa-user"></i> ${r.created_by_name} &nbsp;·&nbsp; <i class="fas fa-clock"></i> ${r.created_at}</span>
                </div>
                <div style="font-size:13px;color:#1f2937;font-style:${r.remark ? 'normal' : 'italic'};color:${r.remark ? '#1f2937' : '#9ca3af'};">
                    ${r.remark ? r.remark.replace(/\n/g, '<br>') : 'No remark entered.'}
                </div>
            </div>`;
                });
                document.getElementById('historyContent').innerHTML = html;
            } catch (e) {
                document.getElementById('historyContent').innerHTML = '<div style="color:#ef4444;padding:16px;">Failed to load remarks.</div>';
            }
        }

        function closeHistoryModal() {
            document.getElementById('historyModal').style.display = 'none';
        }

        // Close modals on backdrop click
        document.addEventListener('click', e => {
            if (e.target === document.getElementById('remarkModal')) closeRemarkModal();
            if (e.target === document.getElementById('historyModal')) closeHistoryModal();
        });

        function applyRowBg(row, status) {
            const bgMap = { Pending: '#fef3c7', Ongoing: '#dbeafe', Done: '#d1fae5', Incomplete: '#fee2e2', Punchlist: '#fce7f3' };
            if (row) row.style.background = bgMap[status] || '#fff';
        }

        function showToast(msg, type) {
            const t = document.getElementById('toast');
            const icon = document.getElementById('toastIcon');
            document.getElementById('toastMsg').textContent = msg;
            t.className = 'toast show ' + type;
            icon.style.color = type === 'success' ? '#10b981' : '#ef4444';
            icon.className = 'fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle');
            setTimeout(() => t.classList.remove('show'), 3000);
        }
    </script>
</body>

</html>