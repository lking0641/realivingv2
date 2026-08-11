<?php
// designer_2d3d_layout.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

// ── Pending approval notif for this user ──
function getPendingApprovalCount($conn, $admin_id, $client_id)
{
    // Only notify if approval is pending AND no revision is waiting for designer resubmission
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM layout_approvals la
        WHERE la.client_id = ? AND la.approver_id = ? AND la.status = 'pending'
        AND la.requested_at IS NOT NULL
        AND NOT EXISTS (
            SELECT 1 FROM layout_revision_log rl
            WHERE rl.client_id = la.client_id
            AND rl.area = la.area
            AND rl.status = 'pending'
            AND (
                (la.room_unit_number IS NULL AND rl.room_unit_number IS NULL)
                OR rl.room_unit_number = la.room_unit_number
            )
        )
    ");
    $stmt->bind_param("ii", $client_id, $admin_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

$meStmt = $conn->prepare("SELECT full_name, role, is_head FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

$allowedRoles = ['designer', 'technical_designer', 'general_manager', 'operational_manager', 'project_coordinator', 'sales'];
if (!in_array($me['role'], $allowedRoles)) {
    die("Access denied.");
}

$canViewAll = in_array($me['role'], ['general_manager', 'operational_manager', 'sales'])
    || (in_array($me['role'], ['designer', 'technical_designer']) && $me['is_head'] == 1);

// Check if this designer is assigned to this client
$assignStmt = $conn->prepare("
    SELECT designer1_id, designer2_id, technical_designer_id, project_coordinator_id,
           clientname, nameproject, reference_number,
           contact, email, address, project_scope, scope_of_work, business_type, status
    FROM user_info WHERE id = ?
");
$assignStmt->bind_param("i", $client_id);
$assignStmt->execute();
$clientInfo = $assignStmt->get_result()->fetch_assoc();

if (!$clientInfo)
    die("Client not found.");

// Display-friendly business type label
$business_type_label = ($clientInfo['business_type'] ?? '') === 'Non-Project' ? 'Individual' : ($clientInfo['business_type'] ?? '');

$isAssigned = (
    $clientInfo['designer1_id'] == $admin_id ||
    $clientInfo['designer2_id'] == $admin_id ||
    $clientInfo['technical_designer_id'] == $admin_id ||
    $clientInfo['project_coordinator_id'] == $admin_id
);

$isOperationalManager = ($me['role'] === 'operational_manager');
$isDesignerHeadCheck = ($me['role'] === 'designer' && $me['is_head'] == 1);
$isTechDesignerHeadCheck = ($me['role'] === 'technical_designer' && $me['is_head'] == 1);

if (!$isAssigned && !$canViewAll) {
    die("Access denied: You are not assigned to this client.");
}

// Back button logic
$isDesignerHead = ($me['role'] === 'designer' && $me['is_head'] == 1);
$cameFromManager = isset($_GET['back']) && $_GET['back'] === 'manager_detail';

$backToTracker = BASE_URL . 'unified-project-tracker?client_id=' . $client_id;
$backToList = BASE_URL . 'designer-layout-list';
$backToManager = BASE_URL . 'manager-project-detail?client_id=' . $client_id;

// ── Handle Assign Designers 1 & 2 (Designer Head only) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_designers') {
    if (!$isDesignerHeadCheck)
        die("Access denied.");
    $new_d1_id = !empty($_POST['designer1_id']) ? intval($_POST['designer1_id']) : null;
    $new_d2_id = !empty($_POST['designer2_id']) ? intval($_POST['designer2_id']) : null;
    $stmt = $conn->prepare("UPDATE user_info SET designer1_id = ?, designer2_id = ? WHERE id = ?");
    $stmt->bind_param("iii", $new_d1_id, $new_d2_id, $client_id);
    $stmt->execute();
    header("Location: " . BASE_URL . "designer-2d3d-layout?client_id={$client_id}&success=" . urlencode("Designers assigned successfully!"));
    exit();
}

// ── Handle Assign Technical Designer (Technical Designer Head only) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_technical_designer') {
    if (!$isTechDesignerHeadCheck)
        die("Access denied.");
    $new_td_id = !empty($_POST['technical_designer_id']) ? intval($_POST['technical_designer_id']) : null;
    $stmt = $conn->prepare("UPDATE user_info SET technical_designer_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_td_id, $client_id);
    $stmt->execute();
    header("Location: " . BASE_URL . "designer-2d3d-layout?client_id={$client_id}&success=" . urlencode("Technical Designer assigned successfully!"));
    exit();
}

// ── Handle Assign Project Coordinator ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_project_coordinator') {
    if (!$isOperationalManager)
        die("Access denied.");
    $new_pc_id = !empty($_POST['project_coordinator_id']) ? intval($_POST['project_coordinator_id']) : null;
    $stmt = $conn->prepare("UPDATE user_info SET project_coordinator_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_pc_id, $client_id);
    $stmt->execute();
    header("Location: " . BASE_URL . "designer-2d3d-layout?client_id={$client_id}&success=" . urlencode("Project Coordinator assigned successfully!"));
    exit();
}

$success = '';
$error = '';

// Check if intake already submitted
$intakeStmt = $conn->prepare("SELECT * FROM layout_intake WHERE client_id = ?");
$intakeStmt->bind_param("i", $client_id);
$intakeStmt->execute();
$intake = $intakeStmt->get_result()->fetch_assoc();

// Fetch current revision count
$revCountStmt = $conn->prepare("SELECT revision_count FROM user_info WHERE id = ?");
$revCountStmt->bind_param("i", $client_id);
$revCountStmt->execute();
$revCountRow = $revCountStmt->get_result()->fetch_assoc();
$current_revision = $revCountRow['revision_count'] ?? 0;

// Handle intake EDIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_intake') {
    $decoration_stage = trim($_POST['decoration_stage'] ?? '');
    $decoration_style = trim($_POST['decoration_style'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    $favour_color = trim($_POST['favour_color'] ?? '');
    $area_sqm = floatval($_POST['area_sqm'] ?? 0);
    $family_members = !empty($_POST['family_members']) ? intval($_POST['family_members']) : null;
    $layout_2d = isset($_POST['layout_2d']) ? 1 : 0;
    $layout_3d = isset($_POST['layout_3d']) ? 1 : 0;
    $budget = floatval($_POST['budget'] ?? 0);
    $measurement_remark = trim($_POST['measurement_remark'] ?? '');

    if (!$decoration_stage || !$decoration_style || (!$layout_2d && !$layout_3d)) {
        $error = "Please fill in all required fields.";
    } else {
        $updStmt = $conn->prepare("
            UPDATE layout_intake SET
                decoration_stage = ?, decoration_style = ?, occupation = ?,
                favour_color = ?, area_sqm = ?,
                family_members = ?, layout_type_2d = ?, layout_type_3d = ?,
                budget = ?, measurement_remark = ?
            WHERE client_id = ?
        ");
        $updStmt->bind_param(
            "ssssdiiiidi",
            $decoration_stage,
            $decoration_style,
            $occupation,
            $favour_color,
            $area_sqm,
            $family_members,
            $layout_2d,
            $layout_3d,
            $budget,
            $measurement_remark,
            $client_id
        );
        if ($updStmt->execute()) {
            $success = "Intake information updated successfully!";
        } else {
            $error = "Failed to update. Please try again.";
        }
    }

    $redirect = BASE_URL . "designer-2d3d-layout?client_id={$client_id}";
    if ($success)
        $redirect .= "&success=" . urlencode($success);
    if ($error)
        $redirect .= "&error=" . urlencode($error);
    header("Location: " . $redirect);
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_intake') {
    if ($intake) {
        $error = "Intake form has already been submitted for this client.";
    } else {
        $decoration_stage = trim($_POST['decoration_stage'] ?? '');
        $decoration_style = trim($_POST['decoration_style'] ?? '');
        $occupation = trim($_POST['occupation'] ?? '');
        $favour_color = trim($_POST['favour_color'] ?? '');
        $area_sqm = floatval($_POST['area_sqm'] ?? 0);
        $family_members = !empty($_POST['family_members']) ? intval($_POST['family_members']) : null;
        $layout_2d = isset($_POST['layout_2d']) ? 1 : 0;
        $layout_3d = isset($_POST['layout_3d']) ? 1 : 0;
        $budget = floatval($_POST['budget'] ?? 0);
        $measurement_remark = trim($_POST['measurement_remark'] ?? '');

        if (!$decoration_stage || !$decoration_style || (!$layout_2d && !$layout_3d)) {
            $error = "Please fill in all required fields and select at least one layout type.";
        } else {
            $insStmt = $conn->prepare("
                INSERT INTO layout_intake 
                (client_id, submitted_by, decoration_stage, decoration_style, occupation,
                 favour_color, area_sqm, family_members,
                 layout_type_2d, layout_type_3d, budget,
                 measurement_remark)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insStmt->bind_param(
                "iissssdiiids",
                $client_id,
                $admin_id,
                $decoration_stage,
                $decoration_style,
                $occupation,
                $favour_color,
                $area_sqm,
                $family_members,
                $layout_2d,
                $layout_3d,
                $budget,
                $measurement_remark
            );

            if ($insStmt->execute()) {
                // Re-fetch intake
                $intakeStmt->bind_param("i", $client_id);
                $intakeStmt->execute();
                $intake = $intakeStmt->get_result()->fetch_assoc();
                $success = "Intake form submitted successfully!";
            } else {
                $error = "Failed to submit. Please try again.";
            }
        }
    }

    // PRG
    $redirect = BASE_URL . "designer-2d3d-layout?client_id={$client_id}";
    if ($success)
        $redirect .= "&success=" . urlencode($success);
    if ($error)
        $redirect .= "&error=" . urlencode($error);
    header("Location: " . $redirect);
    exit();
}

$success = isset($_GET['success']) ? $_GET['success'] : '';
$error = isset($_GET['error']) ? $_GET['error'] : '';

// Fetch submitter name if intake exists
$submitterName = '';
if ($intake) {
    $subStmt = $conn->prepare("SELECT full_name FROM account WHERE id = ?");
    $subStmt->bind_param("i", $intake['submitted_by']);
    $subStmt->execute();
    $submitterName = $subStmt->get_result()->fetch_assoc()['full_name'] ?? '';
}

// ── Fetch current assigned staff names ──
$fetchAssignedStmt = $conn->prepare("
    SELECT 
        d1.full_name AS designer1_name,
        d2.full_name AS designer2_name,
        td.full_name AS tech_designer_name,
        pc.full_name AS project_coordinator_name
    FROM user_info ui
    LEFT JOIN account d1 ON ui.designer1_id = d1.id
    LEFT JOIN account d2 ON ui.designer2_id = d2.id
    LEFT JOIN account td ON ui.technical_designer_id = td.id
    LEFT JOIN account pc ON ui.project_coordinator_id = pc.id
    WHERE ui.id = ?
");
$fetchAssignedStmt->bind_param("i", $client_id);
$fetchAssignedStmt->execute();
$assignedStaff = $fetchAssignedStmt->get_result()->fetch_assoc();

// ── Fetch all designers (for Designer Head to assign designer1 & designer2) ──
$designersList = [];
if ($isDesignerHeadCheck) {
    $dListStmt = $conn->prepare("SELECT id, full_name FROM account WHERE role = 'designer' ORDER BY full_name");
    $dListStmt->execute();
    $designersList = $dListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Fetch all technical designers (for Technical Designer Head to assign) ──
$techDesignersList = [];
if ($isTechDesignerHeadCheck) {
    $tdListStmt = $conn->prepare("SELECT id, full_name FROM account WHERE role = 'technical_designer' ORDER BY full_name");
    $tdListStmt->execute();
    $techDesignersList = $tdListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Fetch all project coordinators (for operational manager to assign) ──
$projectCoordinatorsList = [];
if ($isOperationalManager) {
    $pcListStmt = $conn->prepare("SELECT id, full_name FROM account WHERE role = 'project_coordinator' ORDER BY full_name");
    $pcListStmt->execute();
    $projectCoordinatorsList = $pcListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2D/3D Layout — <?= htmlspecialchars($clientInfo['clientname']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f5f1ed;
            font-family: 'Segoe UI', sans-serif;
        }

        .container {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-header {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            padding: 30px 35px;
            border-radius: 16px;
            color: white;
            margin-bottom: 25px;
        }

        .page-header h1 {
            font-size: 22px;
            margin-bottom: 5px;
        }

        .page-header .sub {
            font-size: 13px;
            opacity: 0.85;
            margin-top: 6px;
        }

        .btn-back {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 8px 16px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            margin-bottom: 16px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
            font-weight: 500;
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

        .card {
            background: white;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
            margin-bottom: 22px;
        }

        .card h2 {
            font-size: 17px;
            color: #3b1f0f;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f5f1ed;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group.full {
            grid-column: 1/-1;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .form-label span {
            color: #ef4444;
        }

        .form-control {
            padding: 9px 13px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 13px;
            color: #111;
            transition: border-color 0.2s;
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: #8a5a44;
        }

        .checkbox-group {
            display: flex;
            gap: 20px;
            margin-top: 4px;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            padding: 10px 16px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .checkbox-label:has(input:checked) {
            border-color: #3b1f0f;
            background: #fdf6f0;
            color: #3b1f0f;
        }

        .checkbox-label input {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .btn-submit {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
            padding: 12px 28px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }

        .btn-submit:hover {
            opacity: 0.9;
        }

        /* Intake summary display */
        .intake-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 14px;
        }

        .intake-item {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 14px;
            border-left: 3px solid #8a5a44;
        }

        .intake-item .label {
            font-size: 11px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .intake-item .value {
            font-size: 14px;
            font-weight: 700;
            color: #1f2937;
        }

        /* Computation table */
        .area-header {
            background: #f0e6db;
            padding: 10px 16px;
            border-radius: 8px;
            margin-bottom: 10px;
            font-weight: 700;
            color: #3b1f0f;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .comp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-bottom: 20px;
        }

        .comp-table th {
            background: #f9f9f9;
            padding: 8px 10px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            color: #6b7280;
            border-bottom: 2px solid #e9ecef;
        }

        .comp-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            vertical-align: middle;
        }

        .comp-table tr:hover td {
            background: #fafafa;
        }

        .item-img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 6px;
        }

        .grand-total-box {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
            padding: 20px 28px;
            border-radius: 12px;
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .grand-total-box .label {
            font-size: 14px;
            opacity: 0.85;
        }

        .grand-total-box .amount {
            font-size: 28px;
            font-weight: 700;
        }

        .layout-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            background: #dbeafe;
            color: #1e40af;
            margin-right: 6px;
        }

        .submitted-info {
            background: #d1fae5;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 18px;
            font-size: 13px;
            color: #065f46;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        /* Room unit buttons */
        .room-unit-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            background: #e0e7ff;
            color: #3730a3;
            border: none;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        .room-unit-btn:hover {
            background: #c7d2fe;
            transform: translateY(-1px);
        }

        .room-scroll-btn {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 4px 8px;
            cursor: pointer;
            font-size: 11px;
            color: #6b7280;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        .room-scroll-btn:hover {
            background: #f3f4f6;
        }

        .hide-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .hide-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
    </style>
</head>

<body>
    <div class="container">
        <div style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;">
            <?php if ($cameFromManager && in_array($me['role'], ['general_manager', 'operational_manager'])): ?>
                <a href="<?= $backToManager ?>"
                    style="background:linear-gradient(135deg,#1e3a5f,#2563eb); color:white; padding:9px 18px; border-radius:8px; font-weight:600; font-size:13px; display:inline-flex; align-items:center; gap:7px; text-decoration:none;">
                    <i class="fas fa-arrow-left"></i> Back to Project Detail
                </a>
            <?php elseif ($isDesignerHead): ?>
                <a href="<?= $backToList ?>"
                    style="background:linear-gradient(135deg,#3b1f0f,#8a5a44); color:white; padding:9px 18px; border-radius:8px; font-weight:600; font-size:13px; display:inline-flex; align-items:center; gap:7px; text-decoration:none;">
                    <i class="fas fa-arrow-left"></i> Back to Layout List
                </a>
                <a href="<?= $backToTracker ?>"
                    style="background:linear-gradient(135deg,#1e3a5f,#2563eb); color:white; padding:9px 18px; border-radius:8px; font-weight:600; font-size:13px; display:inline-flex; align-items:center; gap:7px; text-decoration:none;">
                    <i class="fas fa-chart-line"></i> Back to Tracker
                </a>
            <?php elseif ($canViewAll): ?>
                <a href="<?= $backToTracker ?>"
                    style="background:linear-gradient(135deg,#1e3a5f,#2563eb); color:white; padding:9px 18px; border-radius:8px; font-weight:600; font-size:13px; display:inline-flex; align-items:center; gap:7px; text-decoration:none;">
                    <i class="fas fa-arrow-left"></i> Back to Tracker
                </a>
            <?php else: ?>
                <a href="<?= $backToList ?>"
                    style="background:linear-gradient(135deg,#3b1f0f,#8a5a44); color:white; padding:9px 18px; border-radius:8px; font-weight:600; font-size:13px; display:inline-flex; align-items:center; gap:7px; text-decoration:none;">
                    <i class="fas fa-arrow-left"></i> Back to Layout List
                </a>
            <?php endif; ?>
        </div>

        <!-- ── Client Information Header ── -->
        <?php
        $costStmt2 = $conn->prepare("SELECT total_project_cost, remaining_balance, reference_number, status, contact, email, address, project_scope, scope_of_work, business_type, house_state, permit_required, target_movein_date, gender, client_class, client_type FROM user_info WHERE id = ?");
        $costStmt2->bind_param("i", $client_id);
        $costStmt2->execute();
        $costData2 = $costStmt2->get_result()->fetch_assoc();
        ?>
        <div
            style="background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%); border-radius: 12px; padding: 28px 35px; margin-bottom: 20px; color: white; position: relative; overflow: hidden;">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
                <div>
                    <h1 style="font-size: 28px; margin-bottom: 6px;">📋
                        <?= htmlspecialchars($clientInfo['clientname']) ?>
                    </h1>
                    <p style="opacity: 0.9; font-size: 15px;"><?= htmlspecialchars($clientInfo['nameproject']) ?></p>
                </div>
                <button onclick="document.getElementById('clientDetailModal2').style.display='flex'"
                    style="background: white; color: #3b1f0f; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fas fa-info-circle"></i> View Full Details
                </button>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <?php if (!empty($costData2['reference_number'])): ?>
                    <div
                        style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2); border-radius: 10px; padding: 15px;">
                        <div
                            style="font-size: 11px; opacity: 0.75; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">
                            Reference Number</div>
                        <div style="font-size: 13px; font-weight: 600; margin-top: 4px; font-family: monospace;">
                            <?= htmlspecialchars($costData2['reference_number']) ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($costData2['business_type'])): ?>
                    <div
                        style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2); border-radius: 10px; padding: 15px;">
                        <div
                            style="font-size: 11px; opacity: 0.75; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">
                            Business Type</div>
                        <div style="font-size: 14px; font-weight: 600; margin-top: 4px;">
                            <?= htmlspecialchars($business_type_label) ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div
                    style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2); border-radius: 10px; padding: 15px;">
                    <div
                        style="font-size: 11px; opacity: 0.75; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">
                        Total Project Cost</div>
                    <div style="font-size: 14px; font-weight: 600; margin-top: 4px;">
                        ₱<?= number_format($costData2['total_project_cost'] ?? 0, 2) ?></div>
                </div>
                <div
                    style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2); border-radius: 10px; padding: 15px;">
                    <div
                        style="font-size: 11px; opacity: 0.75; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">
                        Remaining Balance</div>
                    <div style="font-size: 14px; font-weight: 600; margin-top: 4px;">
                        ₱<?= number_format($costData2['remaining_balance'] ?? 0, 2) ?></div>
                </div>
            </div>
        </div>

        <!-- Client Detail Modal — matches computation_list.php full details -->
        <div id="clientDetailModal2"
            style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
            <div
                style="background:white; padding:30px; border-radius:12px; max-width:600px; width:90%; max-height:90vh; overflow-y:auto; position:relative;">
                <div
                    style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom: 2px solid #f3f4f6; padding-bottom: 14px;">
                    <h2
                        style="font-size:20px; font-weight:bold; color:#3b1f0f; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-user-circle" style="color:#8a5a44;"></i> Client Details
                    </h2>
                    <button onclick="document.getElementById('clientDetailModal2').style.display='none'"
                        style="font-size:22px; color:#666; background:none; border:none; cursor:pointer; line-height:1;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- Reference Number -->
                <div
                    style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                    <div style="font-weight:600; color:#666; font-size:13px;">Reference Number:</div>
                    <div style="color:#3b82f6; font-family:monospace; font-size:13px; font-weight:600;">
                        <?= htmlspecialchars($costData2['reference_number'] ?? '') ?>
                    </div>
                </div>

                <!-- Client Name -->
                <div
                    style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                    <div style="font-weight:600; color:#666; font-size:13px;">Client Name:</div>
                    <div style="color:#111; font-size:13px;"><?= htmlspecialchars($clientInfo['clientname']) ?></div>
                </div>

                <!-- Project Name -->
                <div
                    style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                    <div style="font-weight:600; color:#666; font-size:13px;">Project Name:</div>
                    <div style="color:#111; font-size:13px;"><?= htmlspecialchars($clientInfo['nameproject']) ?></div>
                </div>

                <!-- Status -->
                <div
                    style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                    <div style="font-weight:600; color:#666; font-size:13px;">Status:</div>
                    <div>
                        <?php $st = $costData2['status'] ?? ''; ?>
                        <span style="padding:4px 12px; border-radius:12px; font-size:11px; font-weight:700; text-transform:uppercase;
                        background:<?= strtolower($st) === 'new client' ? '#fef3c7' : '#dbeafe' ?>;
                        color:<?= strtolower($st) === 'new client' ? '#92400e' : '#1e40af' ?>;">
                            <?= htmlspecialchars($st) ?>
                        </span>
                    </div>
                </div>

                <!-- Business Type -->
                <div
                    style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                    <div style="font-weight:600; color:#666; font-size:13px;">Business Type:</div>
                    <div style="color:#111; font-size:13px;"><?= htmlspecialchars($business_type_label) ?></div>
                </div>

                <!-- Phone -->
                <div
                    style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                    <div style="font-weight:600; color:#666; font-size:13px;">Phone:</div>
                    <div style="color:#111; font-size:13px;"><?= htmlspecialchars($costData2['contact'] ?? '') ?></div>
                </div>

                <!-- Email -->
                <div
                    style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                    <div style="font-weight:600; color:#666; font-size:13px;">Email:</div>
                    <div style="color:#111; font-size:13px;"><?= htmlspecialchars($costData2['email'] ?? '') ?></div>
                </div>

                <!-- Address -->
                <div
                    style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                    <div style="font-weight:600; color:#666; font-size:13px;">Address:</div>
                    <div style="color:#111; font-size:13px;"><?= htmlspecialchars($costData2['address'] ?? '') ?></div>
                </div>

                <!-- Gender -->
                <?php if (!empty($costData2['gender'])): ?>
                    <div
                        style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                        <div style="font-weight:600; color:#666; font-size:13px;">Gender:</div>
                        <div style="color:#111; font-size:13px;"><?= htmlspecialchars($costData2['gender']) ?></div>
                    </div>
                <?php endif; ?>

                <!-- Classification -->
                <?php if (!empty($costData2['client_class'])): ?>
                    <div
                        style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                        <div style="font-weight:600; color:#666; font-size:13px;">Classification:</div>
                        <div style="color:#111; font-size:13px;"><?= htmlspecialchars($costData2['client_class']) ?></div>
                    </div>
                <?php endif; ?>

                <!-- Client Type -->
                <?php if (!empty($costData2['client_type'])): ?>
                    <div
                        style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                        <div style="font-weight:600; color:#666; font-size:13px;">Client Type:</div>
                        <div style="color:#111; font-size:13px;"><?= htmlspecialchars($costData2['client_type']) ?></div>
                    </div>
                <?php endif; ?>

                <!-- Project Scope -->
                <?php if (!empty($costData2['project_scope'])): ?>
                    <div
                        style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                        <div style="font-weight:600; color:#666; font-size:13px;">Project Scope:</div>
                        <div style="color:#111; font-size:13px;"><?= nl2br(htmlspecialchars($costData2['project_scope'])) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Scope of Work -->
                <?php if (!empty($costData2['scope_of_work'])): ?>
                    <div
                        style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                        <div style="font-weight:600; color:#666; font-size:13px;">Scope of Work:</div>
                        <div style="color:#111; font-size:13px;"><?= nl2br(htmlspecialchars($costData2['scope_of_work'])) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- House State -->
                <?php if (!empty($costData2['house_state'])): ?>
                    <div
                        style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                        <div style="font-weight:600; color:#666; font-size:13px;">House State:</div>
                        <div>
                            <?php
                            $hsBg = '#fef3c7';
                            $hsColor = '#92400e';
                            if ($costData2['house_state'] === 'Bare/Empty Lot') {
                                $hsBg = '#dbeafe';
                                $hsColor = '#1e40af';
                            } elseif ($costData2['house_state'] === 'Construction Started') {
                                $hsBg = '#fee2e2';
                                $hsColor = '#991b1b';
                            } elseif ($costData2['house_state'] === 'Renovation') {
                                $hsBg = '#ede9fe';
                                $hsColor = '#5b21b6';
                            }
                            ?>
                            <span
                                style="padding:4px 12px; border-radius:12px; font-size:12px; font-weight:700; background:<?= $hsBg ?>; color:<?= $hsColor ?>;">
                                <?= htmlspecialchars($costData2['house_state']) ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Permit Required -->
                <?php if (!empty($costData2['permit_required'])): ?>
                    <div
                        style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; border-bottom:1px solid #e9ecef; align-items:start;">
                        <div style="font-weight:600; color:#666; font-size:13px;">Permit Required:</div>
                        <div>
                            <?php
                            $prBg = '#fef3c7';
                            $prColor = '#92400e';
                            if ($costData2['permit_required'] === 'Yes') {
                                $prBg = '#fee2e2';
                                $prColor = '#991b1b';
                            } elseif ($costData2['permit_required'] === 'No') {
                                $prBg = '#d1fae5';
                                $prColor = '#065f46';
                            }
                            ?>
                            <span
                                style="padding:4px 12px; border-radius:12px; font-size:12px; font-weight:700; background:<?= $prBg ?>; color:<?= $prColor ?>;">
                                <?= htmlspecialchars($costData2['permit_required']) ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Target Move-in Date -->
                <?php if (!empty($costData2['target_movein_date'])): ?>
                    <div style="display:grid; grid-template-columns:160px 1fr; padding:12px 0; align-items:start;">
                        <div style="font-weight:600; color:#666; font-size:13px;">Target Move-in:</div>
                        <div style="color:#111; font-size:13px; font-weight:600;">
                            <i class="fas fa-calendar-check" style="color:#10b981;"></i>
                            <?= date('F d, Y', strtotime($costData2['target_movein_date'])) ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- ── Assigned Staff Section ── -->
        <div class="card" style="margin-bottom:22px;">
            <h2><i class="fas fa-users"></i> Assigned Staff</h2>

            <!-- Current assignments display -->
            <div
                style="display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:14px; margin-bottom:20px;">
                <div class="intake-item" style="border-left-color:#3b82f6;">
                    <div class="label"><i class="fas fa-pencil-ruler" style="color:#3b82f6;"></i> Designer 1</div>
                    <div class="value" style="color:#1e40af;">
                        <?= $assignedStaff['designer1_name'] ? htmlspecialchars($assignedStaff['designer1_name']) : '<span style="color:#9ca3af; font-weight:400; font-size:13px;">Not assigned</span>' ?>
                    </div>
                </div>
                <div class="intake-item" style="border-left-color:#6366f1;">
                    <div class="label"><i class="fas fa-pencil-ruler" style="color:#6366f1;"></i> Designer 2</div>
                    <div class="value" style="color:#4338ca;">
                        <?= $assignedStaff['designer2_name'] ? htmlspecialchars($assignedStaff['designer2_name']) : '<span style="color:#9ca3af; font-weight:400; font-size:13px;">Not assigned</span>' ?>
                    </div>
                </div>
                <div class="intake-item" style="border-left-color:#0891b2;">
                    <div class="label"><i class="fas fa-tools" style="color:#0891b2;"></i> Technical Designer</div>
                    <div class="value" style="color:#0e7490;">
                        <?= $assignedStaff['tech_designer_name'] ? htmlspecialchars($assignedStaff['tech_designer_name']) : '<span style="color:#9ca3af; font-weight:400; font-size:13px;">Not assigned</span>' ?>
                    </div>
                </div>
                <div class="intake-item" style="border-left-color:#059669;">
                    <div class="label"><i class="fas fa-clipboard-check" style="color:#059669;"></i> Project Coordinator
                    </div>
                    <div class="value" style="color:#047857;">
                        <?= $assignedStaff['project_coordinator_name'] ? htmlspecialchars($assignedStaff['project_coordinator_name']) : '<span style="color:#9ca3af; font-weight:400; font-size:13px;">Not assigned</span>' ?>
                    </div>
                </div>
            </div>

            <!-- Designer Head: assign designer1 & designer2 -->
            <?php if ($isDesignerHeadCheck): ?>
                <div style="border-top:2px solid #f5f1ed; padding-top:18px;">
                    <div
                        style="font-size:13px; font-weight:700; color:#1e40af; margin-bottom:12px; display:flex; align-items:center; gap:7px;">
                        <i class="fas fa-pencil-ruler"></i> Assign Designers
                        <span
                            style="background:#dbeafe; color:#1e40af; padding:2px 10px; border-radius:10px; font-size:11px;">Designer
                            Head Only</span>
                    </div>
                    <form method="POST" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                        <input type="hidden" name="action" value="assign_designers">
                        <div style="display:flex; flex-direction:column; gap:5px;">
                            <label
                                style="font-size:11px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:0.4px;">Designer
                                1</label>
                            <select name="designer1_id" class="form-control" style="min-width:220px;">
                                <option value="">— None —</option>
                                <?php foreach ($designersList as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= ($clientInfo['designer1_id'] == $d['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($d['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:5px;">
                            <label
                                style="font-size:11px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:0.4px;">Designer
                                2</label>
                            <select name="designer2_id" class="form-control" style="min-width:220px;">
                                <option value="">— None —</option>
                                <?php foreach ($designersList as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= ($clientInfo['designer2_id'] == $d['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($d['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit"
                            style="background:linear-gradient(135deg,#1e40af,#3b82f6); color:white; padding:9px 20px; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:7px; height:38px;">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </form>
                    <?php if (empty($designersList)): ?>
                        <p style="font-size:12px; color:#9ca3af; margin-top:8px;"><i class="fas fa-info-circle"></i> No
                            designers found in the system.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Technical Designer Head: assign technical_designer -->
            <?php if ($isTechDesignerHeadCheck): ?>
                <div style="border-top:2px solid #f5f1ed; padding-top:18px; margin-top:18px;">
                    <div
                        style="font-size:13px; font-weight:700; color:#0e7490; margin-bottom:12px; display:flex; align-items:center; gap:7px;">
                        <i class="fas fa-tools"></i> Assign Technical Designer
                        <span
                            style="background:#cffafe; color:#0e7490; padding:2px 10px; border-radius:10px; font-size:11px;">Technical
                            Designer Head Only</span>
                    </div>
                    <form method="POST" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                        <input type="hidden" name="action" value="assign_technical_designer">
                        <div style="display:flex; flex-direction:column; gap:5px;">
                            <label
                                style="font-size:11px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:0.4px;">Technical
                                Designer</label>
                            <select name="technical_designer_id" class="form-control" style="min-width:220px;">
                                <option value="">— None —</option>
                                <?php foreach ($techDesignersList as $td): ?>
                                    <option value="<?= $td['id'] ?>" <?= ($clientInfo['technical_designer_id'] == $td['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($td['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit"
                            style="background:linear-gradient(135deg,#0891b2,#0e7490); color:white; padding:9px 20px; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:7px; height:38px;">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </form>
                    <?php if (empty($techDesignersList)): ?>
                        <p style="font-size:12px; color:#9ca3af; margin-top:8px;"><i class="fas fa-info-circle"></i> No
                            technical designers found in the system.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Operational Manager: assign project_coordinator -->
            <?php if ($isOperationalManager): ?>
                <div style="border-top:2px solid #f5f1ed; padding-top:18px; margin-top:18px;">
                    <div
                        style="font-size:13px; font-weight:700; color:#059669; margin-bottom:12px; display:flex; align-items:center; gap:7px;">
                        <i class="fas fa-clipboard-check"></i> Assign Project Coordinator
                        <span
                            style="background:#d1fae5; color:#059669; padding:2px 10px; border-radius:10px; font-size:11px;">Operational
                            Manager Only</span>
                    </div>
                    <form method="POST" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                        <input type="hidden" name="action" value="assign_project_coordinator">
                        <div style="display:flex; flex-direction:column; gap:5px;">
                            <label
                                style="font-size:11px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:0.4px;">Project
                                Coordinator</label>
                            <select name="project_coordinator_id" class="form-control" style="min-width:220px;">
                                <option value="">— None —</option>
                                <?php foreach ($projectCoordinatorsList as $pc): ?>
                                    <option value="<?= $pc['id'] ?>" <?= ($clientInfo['project_coordinator_id'] == $pc['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($pc['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit"
                            style="background:linear-gradient(135deg,#059669,#047857); color:white; padding:9px 20px; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:7px; height:38px;">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </form>
                    <?php if (empty($projectCoordinatorsList)): ?>
                        <p style="font-size:12px; color:#9ca3af; margin-top:8px;"><i class="fas fa-info-circle"></i> No project
                            coordinators found in the system.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>

        <div class="page-header">
            <h1><i class="fas fa-drafting-compass"></i> 2D / 3D Layout Manager</h1>
            <div class="sub">
                <?= htmlspecialchars($clientInfo['clientname']) ?> —
                <?= htmlspecialchars($clientInfo['nameproject']) ?>
                &nbsp;•&nbsp; Ref: <?= htmlspecialchars($clientInfo['reference_number']) ?>
            </div>
            <div class="sub">Designer: <?= htmlspecialchars($me['full_name']) ?></div>
        </div>

        <?php
        // Fetch rejected layout approvals for this client (only shown to assigned designers)
        $rejectedLayoutItems = [];
        if ($isAssigned) {
            $rejStmt = $conn->prepare("
        SELECT la.area, la.room_unit_number, la.comment, la.responded_at,
               a.full_name as rejected_by_name
        FROM layout_approvals la
        LEFT JOIN account a ON la.approver_id = a.id
        WHERE la.client_id = ? AND la.status = 'rejected'
        ORDER BY la.responded_at DESC
    ");
            $rejStmt->bind_param("i", $client_id);
            $rejStmt->execute();
            $rejectedLayoutItems = $rejStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        ?>

        <?php
        $pendingApprovalCount = getPendingApprovalCount($conn, $admin_id, $client_id);
        if ($pendingApprovalCount > 0):
            ?>
            <div
                style="background:#fef3c7; border:2px solid #f59e0b; border-radius:12px; padding:14px 20px; margin-bottom:18px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-bell" style="color:#d97706; font-size:20px;"></i>
                    <div>
                        <div style="font-weight:700; font-size:14px; color:#92400e;">
                            You have <?= $pendingApprovalCount ?> pending
                            approval<?= $pendingApprovalCount > 1 ? 's' : '' ?> for this client
                        </div>
                        <div style="font-size:12px; color:#b45309; margin-top:2px;">
                            Go to Attachments to review and approve or reject.
                        </div>
                    </div>
                </div>
                <a href="designer-attachments?client_id=<?= $client_id ?>"
                    style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:9px 18px; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:7px; white-space:nowrap;">
                    <i class="fas fa-arrow-right"></i> Go to Attachments
                </a>
            </div>
        <?php endif; ?>

        <?php if (!empty($rejectedLayoutItems) && $isAssigned): ?>
            <div
                style="background:#fee2e2; border:2px solid #ef4444; border-radius:12px; padding:14px 20px; margin-bottom:18px;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px; flex-wrap:wrap;">
                    <i class="fas fa-times-circle" style="color:#dc2626; font-size:20px; flex-shrink:0;"></i>
                    <div style="flex:1;">
                        <div style="font-weight:700; font-size:14px; color:#991b1b;">
                            <?= count($rejectedLayoutItems) ?> layout
                            area<?= count($rejectedLayoutItems) > 1 ? 's/units' : '/unit' ?> rejected — action required
                        </div>
                        <div style="font-size:12px; color:#b91c1c; margin-top:2px;">
                            Go to <strong>Attachments</strong> to review the rejection comments and resubmit updated files.
                        </div>
                    </div>
                    <a href="designer-attachments?client_id=<?= $client_id ?>"
                        style="background:linear-gradient(135deg,#dc2626,#ef4444); color:white; padding:9px 18px; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:7px; white-space:nowrap; flex-shrink:0;">
                        <i class="fas fa-arrow-right"></i> Go to Attachments
                    </a>
                </div>
                <!-- List each rejected item -->
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <?php foreach ($rejectedLayoutItems as $rej): ?>
                        <div
                            style="background:white; border:1px solid #fca5a5; border-radius:8px; padding:10px 14px; display:flex; align-items:flex-start; gap:10px; flex-wrap:wrap;">
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:13px; font-weight:700; color:#991b1b;">
                                    <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($rej['area']) ?>
                                    <?php if ($rej['room_unit_number']): ?>
                                        <span style="color:#6b7280; font-weight:400;"> › </span>
                                        <i class="fas fa-door-open"></i> Unit <?= $rej['room_unit_number'] ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($rej['comment']): ?>
                                    <div
                                        style="font-size:12px; color:#7f1d1d; background:#fff5f5; padding:6px 10px; border-radius:6px; margin-top:6px; border-left:3px solid #ef4444; font-style:italic;">
                                        <i class="fas fa-comment-slash"></i> "<?= htmlspecialchars($rej['comment']) ?>"
                                    </div>
                                <?php endif; ?>
                                <div
                                    style="font-size:11px; color:#9ca3af; margin-top:5px; display:flex; align-items:center; gap:5px;">
                                    <i class="fas fa-user-times"></i>
                                    Rejected by: <?= htmlspecialchars($rej['rejected_by_name'] ?? 'Manager') ?>
                                    <?php if ($rej['responded_at']): ?>
                                        &nbsp;•&nbsp; <?= date('M d, Y g:i A', strtotime($rej['responded_at'])) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php
        // Show 2D/3D Layout deadline if set (Project type only)
        $dlStmt2d = $conn->prepare("SELECT start_date, end_date, duration FROM stage_deadlines WHERE client_id = ? AND stage_name = '2D / 3D Layout'");
            $dlStmt2d->bind_param("i", $client_id);
            $dlStmt2d->execute();
            $dlRow2d = $dlStmt2d->get_result()->fetch_assoc();
            if ($dlRow2d && ($dlRow2d['start_date'] || $dlRow2d['end_date'])):
                $now2d = new DateTime();
                $endDt2d = $dlRow2d['end_date'] ? new DateTime($dlRow2d['end_date']) : null;
                $isOverdue2d = $endDt2d && $now2d > $endDt2d;
                $dlBg2d = $isOverdue2d ? '#fee2e2' : '#eff6ff';
                $dlBorder2d = $isOverdue2d ? '#ef4444' : '#3b82f6';
                $dlColor2d = $isOverdue2d ? '#991b1b' : '#1e40af';
                $dlIcon2d = $isOverdue2d ? 'fa-exclamation-circle' : 'fa-calendar-alt';
        ?>
        <div style="background:<?= $dlBg2d ?>; border:2px solid <?= $dlBorder2d ?>; border-radius:12px; padding:14px 20px; margin-bottom:18px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <i class="fas <?= $dlIcon2d ?>" style="color:<?= $dlBorder2d ?>; font-size:20px; flex-shrink:0;"></i>
            <div style="flex:1;">
                <div style="font-weight:700; font-size:14px; color:<?= $dlColor2d ?>;">
                    2D / 3D Layout <?= $isOverdue2d ? '— OVERDUE' : 'Deadline' ?>
                </div>
                <div style="font-size:12px; color:<?= $dlColor2d ?>; opacity:0.85; margin-top:2px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <?php if ($dlRow2d['start_date']): ?>
                        <span><i class="fas fa-play-circle" style="color:#10b981;"></i> Start: <strong><?= date('F d, Y', strtotime($dlRow2d['start_date'])) ?></strong></span>
                    <?php endif; ?>
                    <?php if ($dlRow2d['end_date']): ?>
                        <span><i class="fas fa-stop-circle" style="color:#ef4444;"></i> Deadline: <strong><?= date('F d, Y', strtotime($dlRow2d['end_date'])) ?></strong></span>
                    <?php endif; ?>
                    <?php if ($dlRow2d['duration']): ?>
                        <span><i class="fas fa-clock"></i> <?= $dlRow2d['duration'] ?> day<?= $dlRow2d['duration'] != 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- INTAKE FORM or SUBMITTED VIEW -->
        <?php if (!$intake): ?>
            <?php if ($clientInfo['designer1_id'] == $admin_id || $clientInfo['designer2_id'] == $admin_id): ?>
                <div class="card">
                    <h2><i class="fas fa-clipboard-list"></i> Client Intake Form</h2>
                    <p style="font-size:13px; color:#9ca3af; margin-bottom:20px;">
                        Fill out this form once before proceeding with the layout. Only one submission is allowed per client.
                    </p>
                    <form method="POST">
                        <input type="hidden" name="action" value="submit_intake">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Decoration Stage <span>*</span></label>
                                <input type="text" name="decoration_stage" class="form-control"
                                    placeholder="e.g. New Build, Renovation..." required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Decoration Style <span>*</span></label>
                                <input type="text" name="decoration_style" class="form-control"
                                    placeholder="e.g. Modern, Classic, Minimalist..." required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Occupation <span
                                        style="color:#9ca3af; font-weight:400; text-transform:none;">(Optional)</span></label>
                                <input type="text" name="occupation" class="form-control" placeholder="Client's occupation...">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Favourite Color <span
                                        style="color:#9ca3af; font-weight:400; text-transform:none;">(Optional)</span></label>
                                <input type="text" name="favour_color" class="form-control"
                                    placeholder="e.g. Beige, White, Navy...">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Area (Total SQM of House) <span
                                        style="color:#9ca3af; font-weight:400; text-transform:none;">(Optional)</span></label>
                                <input type="number" name="area_sqm" class="form-control" step="0.01" min="0"
                                    placeholder="e.g. 120.50">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Family Members <span
                                        style="color:#9ca3af; font-weight:400; text-transform:none;">(Optional)</span></label>
                                <input type="number" name="family_members" class="form-control" min="0"
                                    placeholder="Total number of people">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Budget <span
                                        style="color:#9ca3af; font-weight:400; text-transform:none;">(Optional)</span></label>
                                <input type="number" name="budget" class="form-control" step="0.01" min="0"
                                    placeholder="₱ 0.00">
                            </div>
                            <div class="form-group full">
                                <label class="form-label">Layout Type <span>*</span></label>
                                <div class="checkbox-group">
                                    <label class="checkbox-label" style="opacity:0.75; cursor:not-allowed;">
                                        <input type="checkbox" name="layout_2d" value="1" checked disabled>
                                        <i class="fas fa-vector-square" style="color:#3b82f6;"></i> 2D Layout
                                        <span
                                            style="font-size:10px; background:#dbeafe; color:#1e40af; padding:1px 7px; border-radius:8px; margin-left:4px; font-weight:700;">Always</span>
                                    </label>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="layout_3d" value="1">
                                        <i class="fas fa-cube" style="color:#8b5cf6;"></i> 3D Layout
                                        <span
                                            style="font-size:10px; background:#ede9fe; color:#5b21b6; padding:1px 7px; border-radius:8px; margin-left:4px; font-weight:700;">Optional</span>
                                    </label>
                                </div>
                                <input type="hidden" name="layout_2d" value="1">
                            </div>
                            <div class="form-group full">
                                <label class="form-label">Measurement Remark <span
                                        style="color:#9ca3af; font-weight:400; text-transform:none;">(Optional)</span></label>
                                <textarea name="measurement_remark" class="form-control" rows="3"
                                    placeholder="Any additional remarks about measurements..."></textarea>
                            </div>
                        </div>
                        <button type="submit" class="btn-submit">
                            <i class="fas fa-paper-plane"></i> Submit Intake Form
                        </button>
                    </form>
                </div>
            <?php endif; ?>

        <?php else: ?>

            <?php
            // Check approval state for mark-as-done
            $allAreasApproved = false;

            // Fetch distinct areas (needed for approval check and revision section)
            $areasStmt = $conn->prepare("
    SELECT DISTINCT area FROM quotation_entries WHERE client_id = ?
    UNION
    SELECT DISTINCT area FROM quotation_fixed_sizes WHERE client_id = ?
    ORDER BY area
");
            $areasStmt->bind_param("ii", $client_id, $client_id);
            $areasStmt->execute();
            $areasResult = $areasStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $areas = array_column($areasResult, 'area');

            $areasForApproval = $areas;

            if (!empty($areasForApproval)) {
                // Get all approvers
                $aprCountStmt = $conn->prepare("
        SELECT COUNT(*) FROM account
        WHERE (role IN ('general_manager','operational_manager'))
           OR (role IN ('designer','technical_designer') AND is_head = 1)
    ");
                $aprCountStmt->execute();
                $totalApprovers = (int) $aprCountStmt->get_result()->fetch_row()[0];

                if ($totalApprovers > 0) {
                    $allAreasDone = true;
                    foreach ($areasForApproval as $checkArea) {
                        // Area-level approval check
                        $aprChk = $conn->prepare("
                    SELECT COUNT(*) FROM layout_approvals
                    WHERE client_id = ? AND area = ? AND room_unit_number IS NULL AND status = 'approved'
                ");
                        $aprChk->bind_param("is", $client_id, $checkArea);
                        $aprChk->execute();
                        $approvedCount = (int) $aprChk->get_result()->fetch_row()[0];
                        if ($approvedCount < $totalApprovers) {
                            $allAreasDone = false;
                            break;
                        }
                    }
                    $allAreasApproved = $allAreasDone;
                }
            }

            // Check current tracker status for 2D/3D Layout
            $layoutStatusStmt = $conn->prepare("
    SELECT status FROM project_tracker WHERE client_id = ? AND stage_name = '2D / 3D Layout'
");
            $layoutStatusStmt->bind_param("i", $client_id);
            $layoutStatusStmt->execute();
            $layoutTrackerRow = $layoutStatusStmt->get_result()->fetch_assoc();
            $layoutTrackerStatus = $layoutTrackerRow['status'] ?? 'Pending';
            ?>

            <?php
            $isAssignedDesigner = (
                $clientInfo['designer1_id'] == $admin_id ||
                $clientInfo['designer2_id'] == $admin_id
            );
            ?>
            <?php if ($allAreasApproved && $layoutTrackerStatus !== 'Done' && $isAssignedDesigner): ?>
                <div
                    style="background:#d1fae5; border:2px solid #10b981; border-radius:12px; padding:20px 24px; margin-bottom:22px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
                    <div>
                        <div style="font-weight:700; font-size:15px; color:#065f46; margin-bottom:4px;">
                            <i class="fas fa-check-circle"></i> All Areas Approved!
                        </div>
                        <div style="font-size:13px; color:#065f46; opacity:0.85;">
                            All layout areas have been approved by all reviewers. You can now mark this stage as Done.
                        </div>
                    </div>
                    <form method="POST" action="<?= BASE_URL ?>mark-layout-done">
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        <input type="hidden" name="redirect_url"
                            value="<?= BASE_URL ?>designer-2d3d-layout?client_id=<?= $client_id ?>">
                        <button type="submit"
                            style="background:linear-gradient(135deg,#065f46,#10b981); color:white; padding:11px 22px; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:7px; white-space:nowrap;">
                            <i class="fas fa-flag-checkered"></i> Mark as Done
                        </button>
                    </form>
                </div>
            <?php elseif ($layoutTrackerStatus === 'Done'): ?>
                <div
                    style="background:#d1fae5; border:2px solid #10b981; border-radius:12px; padding:16px 24px; margin-bottom:22px; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-check-circle" style="color:#10b981; font-size:22px;"></i>
                    <span style="font-weight:700; font-size:14px; color:#065f46;">2D / 3D Layout stage is marked as Done.</span>
                </div>
            <?php endif; ?>

            <?php
            // Fetch revision log for this client
            $revLogStmt = $conn->prepare("
    SELECT rl.*, a.full_name as requester_name
    FROM layout_revision_log rl
    LEFT JOIN account a ON rl.requested_by = a.id
    WHERE rl.client_id = ?
    ORDER BY rl.created_at DESC
");
            $revLogStmt->bind_param("i", $client_id);
            $revLogStmt->execute();
            $revisionLogs = $revLogStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            ?>

            <!-- Revision Request Section -->
            <?php if (($clientInfo['designer1_id'] == $admin_id || $clientInfo['designer2_id'] == $admin_id) && !empty($areas)): ?>
                <div class="card" style="border:2px solid #f59e0b;">
                    <h2 style="color:#92400e;">
                        <i class="fas fa-redo"></i> Request Revision
                        <?php if ($current_revision > 0): ?>
                            <span
                                style="font-size:13px; background:#fef3c7; color:#92400e; padding:3px 12px; border-radius:12px; margin-left:10px; font-weight:700;">
                                <?= $current_revision ?> Revision(s) so far
                            </span>
                        <?php endif; ?>
                    </h2>
                    <p style="font-size:13px; color:#9ca3af; margin-bottom:20px;">
                        Select an area (and unit if applicable) to request a revision. This will reset the approvals for that
                        area and increment the revision count.
                    </p>

                    <!-- Selected Summary Box -->
                    <div id="selectionSummary"
                        style="display:none; background:#fffbeb; border:2px solid #f59e0b; border-radius:10px; padding:16px; margin-bottom:16px;">
                        <div
                            style="font-size:12px; font-weight:700; color:#92400e; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:12px;">
                            <i class="fas fa-list-check"></i> Selected for Revision — add a reason for each:
                        </div>
                        <div id="selectionItems" style="display:flex; flex-direction:column; gap:10px;"></div>
                    </div>

                    <form method="POST" action="<?= BASE_URL ?>request-revision" id="revisionForm">
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        <input type="hidden" name="redirect_url" value="<?= BASE_URL ?>designer-2d3d-layout?client_id=<?= $client_id ?>">
                        <input type="hidden" name="selections" id="selectionsInput" value="">

                        <!-- Area + Unit selector -->
                        <div style="margin-bottom:16px;">
                            <label
                                style="font-size:12px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:0.4px; display:block; margin-bottom:8px;">
                                <i class="fas fa-map-marker-alt"></i> Select Areas / Units for Revision
                                <span style="color:#ef4444;">*</span>
                                <span
                                    style="font-size:11px; color:#9ca3af; font-weight:400; text-transform:none; margin-left:6px;">(You
                                    can select multiple)</span>
                            </label>

                            <?php foreach ($areas as $areaOption): ?>
                                <?php
                                // Get approval state
                                $areaApprStmt = $conn->prepare("
            SELECT status FROM layout_approvals
            WHERE client_id = ? AND area = ? AND room_unit_number IS NULL
        ");
                                $areaApprStmt->bind_param("is", $client_id, $areaOption);
                                $areaApprStmt->execute();
                                $areaApprRows = $areaApprStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                $areaApprStatuses = array_column($areaApprRows, 'status');

                                if (empty($areaApprRows)) {
                                    $aTag = 'none';
                                    $aTagBg = '#f3f4f6';
                                    $aTagColor = '#9ca3af';
                                } elseif (in_array('rejected', $areaApprStatuses)) {
                                    $aTag = 'rejected';
                                    $aTagBg = '#fee2e2';
                                    $aTagColor = '#991b1b';
                                } elseif (count(array_filter($areaApprStatuses, fn($s) => $s === 'approved')) === count($areaApprStatuses)) {
                                    $aTag = 'approved';
                                    $aTagBg = '#d1fae5';
                                    $aTagColor = '#065f46';
                                } else {
                                    $aTag = 'pending';
                                    $aTagBg = '#fef3c7';
                                    $aTagColor = '#92400e';
                                }

                                $areaSlugRev = 'revarea_' . preg_replace('/[^a-zA-Z0-9]/', '_', $areaOption);
                                ?>

                                <div style="border:2px solid #e9ecef; border-radius:10px; margin-bottom:10px; overflow:hidden;"
                                    id="areablock-<?= $areaSlugRev ?>">
                                    <!-- Area row -->
                                    <div
                                        style="display:flex; align-items:center; gap:10px; padding:12px 16px; background:#fafafa; flex-wrap:wrap;">
                                        <!-- Area-level checkbox -->
                                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; flex:1;">
                                            <input type="checkbox" class="rev-area-check"
                                                data-area="<?= htmlspecialchars($areaOption, ENT_QUOTES) ?>"
                                                onchange="onAreaCheck(this)"
                                                style="width:16px; height:16px; cursor:pointer; accent-color:#f59e0b;">
                                            <span style="font-size:14px; font-weight:700; color:#1f2937;">
                                                <i class="fas fa-layer-group" style="color:#8a5a44;"></i>
                                                <?= htmlspecialchars($areaOption) ?>
                                            </span>
                                            <span
                                                style="padding:2px 10px; border-radius:10px; font-size:11px; font-weight:700; background:<?= $aTagBg ?>; color:<?= $aTagColor ?>;">
                                                <?= ucfirst($aTag) ?>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="submit" id="revisionSubmitBtn" disabled
                            style="background:linear-gradient(135deg,#d97706,#f59e0b); color:white; padding:11px 24px; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:8px; opacity:0.5;"
                            onclick="return confirmRevision()">
                            <i class="fas fa-redo"></i> Request Revision
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Revision History -->
            <?php if (!empty($revisionLogs)):
                // Group by revision_number
                $revGroups = [];
                foreach ($revisionLogs as $log) {
                    $rn = $log['revision_number'];
                    if (!isset($revGroups[$rn]))
                        $revGroups[$rn] = [];
                    $revGroups[$rn][] = $log;
                }
                krsort($revGroups); // newest revision number first
                ?>
                <div class="card" style="margin-top:0;">
                    <div
                        style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; padding-bottom:12px; border-bottom:2px solid #f5f1ed;">
                        <h2 style="color:#3b1f0f; margin-bottom:0; border:none; padding:0;">
                            <i class="fas fa-history"></i> Revision History
                        </h2>
                        <button type="button" onclick="toggleRevHistory()" id="revHistoryToggleBtn"
                            style="background:linear-gradient(135deg,#3b1f0f,#8a5a44); color:white; border:none; padding:8px 18px; border-radius:8px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:7px;">
                            <i class="fas fa-eye" id="revHistoryBtnIcon"></i>
                            <span id="revHistoryBtnText">Show History</span>
                        </button>
                    </div>

                    <div id="revHistoryPanel" style="display:none;">
                        <!-- Revision number list (accordion) -->
                        <?php foreach ($revGroups as $revNum => $logs): ?>
                            <?php
                            // Determine overall status for this revision group
                            $groupStatuses = array_column($logs, 'status');
                            if (in_array('approved', $groupStatuses)) {
                                $grpBg = '#d1fae5';
                                $grpBorder = '#10b981';
                                $grpColor = '#065f46';
                                $grpLabel = 'Approved';
                                $grpIcon = 'fa-check-circle';
                            } elseif (in_array('designer_resubmitted', $groupStatuses)) {
                                $grpBg = '#dbeafe';
                                $grpBorder = '#3b82f6';
                                $grpColor = '#1e40af';
                                $grpLabel = 'Resubmitted';
                                $grpIcon = 'fa-paper-plane';
                            } else {
                                $grpBg = '#fef3c7';
                                $grpBorder = '#f59e0b';
                                $grpColor = '#92400e';
                                $grpLabel = 'Pending';
                                $grpIcon = 'fa-hourglass-half';
                            }
                            $firstLog = $logs[0];
                            $revPanelId = 'revpanel_' . $revNum;
                            $revChevronId = 'revchevron_' . $revNum;
                            ?>
                            <div
                                style="border:2px solid <?= $grpBorder ?>44; border-radius:10px; margin-bottom:10px; overflow:hidden;">
                                <!-- Revision header row — clickable -->
                                <button type="button" onclick="toggleRevPanel('<?= $revPanelId ?>', '<?= $revChevronId ?>')"
                                    style="width:100%; background:<?= $grpBg ?>; border:none; padding:13px 16px; cursor:pointer; display:flex; align-items:center; gap:12px; text-align:left;">
                                    <span
                                        style="background:<?= $grpBorder ?>; color:white; padding:3px 12px; border-radius:10px; font-size:12px; font-weight:700; white-space:nowrap; flex-shrink:0;">
                                        Revision #<?= $revNum ?>
                                    </span>
                                    <span style="font-size:12px; font-weight:600; color:<?= $grpColor ?>; flex:1;">
                                        <?= count($logs) ?>
                                        area<?= count($logs) > 1 ? 's' : '' ?>/unit<?= count($logs) > 1 ? 's' : '' ?> affected
                                        &nbsp;•&nbsp;
                                        <?= date('M d, Y g:i A', strtotime($firstLog['created_at'])) ?>
                                    </span>
                                    <span
                                        style="background:<?= $grpBorder ?>22; color:<?= $grpColor ?>; padding:3px 10px; border-radius:10px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:4px; flex-shrink:0;">
                                        <i class="fas <?= $grpIcon ?>"></i> <?= $grpLabel ?>
                                    </span>
                                    <i id="<?= $revChevronId ?>" class="fas fa-chevron-down"
                                        style="color:<?= $grpColor ?>; font-size:13px; transition:transform 0.2s; flex-shrink:0;"></i>
                                </button>

                                <!-- Revision detail panel (hidden by default) -->
                                <div id="<?= $revPanelId ?>"
                                    style="display:none; padding:14px 16px; background:white; border-top:1px solid <?= $grpBorder ?>33;">
                                    <div style="display:flex; flex-direction:column; gap:10px;">
                                        <?php foreach ($logs as $log):
                                            $logStatusBg = '#f3f4f6';
                                            $logStatusColor = '#6b7280';
                                            $logStatusLabel = 'Pending';
                                            if ($log['status'] === 'designer_resubmitted') {
                                                $logStatusBg = '#dbeafe';
                                                $logStatusColor = '#1e40af';
                                                $logStatusLabel = 'Resubmitted';
                                            } elseif ($log['status'] === 'approved') {
                                                $logStatusBg = '#d1fae5';
                                                $logStatusColor = '#065f46';
                                                $logStatusLabel = 'Approved';
                                            }
                                            ?>
                                            <div
                                                style="border:1px solid #fcd34d; border-radius:8px; padding:12px 14px; background:#fffbeb;">
                                                <div
                                                    style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px; margin-bottom:6px;">
                                                    <span style="font-size:13px; font-weight:700; color:#92400e;">
                                                        <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($log['area']) ?>
                                                        <?php if ($log['room_unit_name'] || $log['room_unit_number']): ?>
                                                            &nbsp;›&nbsp; <i class="fas fa-door-open"></i>
                                                            <?= htmlspecialchars($log['room_unit_name'] ?: 'Unit ' . $log['room_unit_number']) ?>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span
                                                        style="background:<?= $logStatusBg ?>; color:<?= $logStatusColor ?>; padding:2px 10px; border-radius:10px; font-size:11px; font-weight:700;">
                                                        <?= $logStatusLabel ?>
                                                    </span>
                                                </div>
                                                <?php if ($log['reason']): ?>
                                                    <div
                                                        style="font-size:13px; color:#374151; background:white; padding:8px 12px; border-radius:6px; border-left:3px solid #f59e0b; margin-bottom:6px;">
                                                        <?= nl2br(htmlspecialchars($log['reason'])) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div style="font-size:11px; color:#9ca3af; display:flex; align-items:center; gap:6px;">
                                                    <i class="fas fa-user-edit"></i>
                                                    Requested by: <?= htmlspecialchars($log['requester_name'] ?? '') ?>
                                                    &nbsp;•&nbsp;
                                                    <?= date('M d, Y g:i A', strtotime($log['created_at'])) ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- INTAKE SUBMITTED — show summary + edit -->
            <div class="card">
                <div
                    style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; padding-bottom:12px; border-bottom:2px solid #f5f1ed;">
                    <h2 style="margin:0; padding:0; border:none;"><i class="fas fa-clipboard-check"></i> Client Intake
                        Information</h2>
                    <?php if ($clientInfo['designer1_id'] == $admin_id || $clientInfo['designer2_id'] == $admin_id): ?>
                        <button type="button" onclick="toggleIntakeEdit()" id="intakeEditBtn"
                            style="background:linear-gradient(135deg,#3b1f0f,#8a5a44); color:white; border:none; padding:8px 18px; border-radius:8px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:7px;">
                            <i class="fas fa-pen"></i> Edit
                        </button>
                    <?php endif; ?>
                </div>

                <div class="submitted-info">
                    <i class="fas fa-check-circle"></i>
                    Submitted by <?= htmlspecialchars($submitterName) ?> on
                    <?= date('F d, Y g:i A', strtotime($intake['created_at'])) ?>
                </div>

                <!-- VIEW MODE -->
                <div id="intakeViewMode">
                    <div style="margin-bottom:14px;">
                        <?php if ($intake['layout_type_2d']): ?>
                            <span class="layout-badge"><i class="fas fa-vector-square"></i> 2D Layout</span>
                        <?php endif; ?>
                        <?php if ($intake['layout_type_3d']): ?>
                            <span class="layout-badge" style="background:#ede9fe; color:#5b21b6;"><i class="fas fa-cube"></i> 3D
                                Layout</span>
                        <?php endif; ?>
                    </div>
                    <div class="intake-grid">
                        <div class="intake-item">
                            <div class="label">Decoration Stage</div>
                            <div class="value"><?= htmlspecialchars($intake['decoration_stage']) ?></div>
                        </div>
                        <div class="intake-item">
                            <div class="label">Decoration Style</div>
                            <div class="value"><?= htmlspecialchars($intake['decoration_style']) ?></div>
                        </div>
                        <div class="intake-item">
                            <div class="label">Occupation</div>
                            <div class="value"><?= htmlspecialchars($intake['occupation']) ?></div>
                        </div>
                        <div class="intake-item">
                            <div class="label">Favourite Color</div>
                            <div class="value"><?= htmlspecialchars($intake['favour_color']) ?></div>
                        </div>
                        <div class="intake-item">
                            <div class="label">Area (SQM)</div>
                            <div class="value"><?= number_format($intake['area_sqm'], 2) ?> m²</div>
                        </div>
                        <?php if ($intake['family_members'] !== null): ?>
                            <div class="intake-item">
                                <div class="label">Family Members</div>
                                <div class="value"><?= $intake['family_members'] ?> people</div>
                            </div>
                        <?php endif; ?>
                        <div class="intake-item">
                            <div class="label">Budget</div>
                            <div class="value">₱<?= number_format($intake['budget'], 2) ?></div>
                        </div>
                        <?php if ($intake['measurement_remark']): ?>
                            <div class="intake-item" style="grid-column:1/-1;">
                                <div class="label">Measurement Remark</div>
                                <div class="value" style="font-weight:400; font-size:13px;">
                                    <?= nl2br(htmlspecialchars($intake['measurement_remark'])) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- EDIT MODE (hidden by default) -->
                <div id="intakeEditMode" style="display:none;">
                    <form method="POST">
                        <input type="hidden" name="action" value="edit_intake">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Decoration Stage <span>*</span></label>
                                <input type="text" name="decoration_stage" class="form-control"
                                    value="<?= htmlspecialchars($intake['decoration_stage']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Decoration Style <span>*</span></label>
                                <input type="text" name="decoration_style" class="form-control"
                                    value="<?= htmlspecialchars($intake['decoration_style']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Occupation <span
                                        style="color:#9ca3af; font-weight:400; text-transform:none;">(Optional)</span></label>
                                <input type="text" name="occupation" class="form-control"
                                    value="<?= htmlspecialchars($intake['occupation']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Favourite Color <span
                                        style="color:#9ca3af; font-weight:400; text-transform:none;">(Optional)</span></label>
                                <input type="text" name="favour_color" class="form-control"
                                    value="<?= htmlspecialchars($intake['favour_color']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Area (Total SQM) <span
                                        style="color:#9ca3af; font-weight:400; text-transform:none;">(Optional)</span></label>
                                <input type="number" name="area_sqm" class="form-control" step="0.01" min="0"
                                    value="<?= htmlspecialchars($intake['area_sqm']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Family Members <span
                                        style="color:#9ca3af; font-weight:400; text-transform:none;">(Optional)</span></label>
                                <input type="number" name="family_members" class="form-control" min="0"
                                    value="<?= htmlspecialchars($intake['family_members'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Budget <span
                                        style="color:#9ca3af; font-weight:400; text-transform:none;">(Optional)</span></label>
                                <input type="number" name="budget" class="form-control" step="0.01" min="0"
                                    value="<?= htmlspecialchars($intake['budget']) ?>">
                            </div>
                            <div class="form-group full">
                                <label class="form-label">Layout Type <span>*</span></label>
                                <div class="checkbox-group">
                                    <label class="checkbox-label" style="opacity:0.75; cursor:not-allowed;">
                                        <input type="checkbox" name="layout_2d" value="1" checked disabled>
                                        <i class="fas fa-vector-square" style="color:#3b82f6;"></i> 2D Layout
                                        <span
                                            style="font-size:10px; background:#dbeafe; color:#1e40af; padding:1px 7px; border-radius:8px; margin-left:4px; font-weight:700;">Always</span>
                                    </label>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="layout_3d" value="1" <?= $intake['layout_type_3d'] ? 'checked' : '' ?>>
                                        <i class="fas fa-cube" style="color:#8b5cf6;"></i> 3D Layout
                                        <span
                                            style="font-size:10px; background:#ede9fe; color:#5b21b6; padding:1px 7px; border-radius:8px; margin-left:4px; font-weight:700;">Optional</span>
                                    </label>
                                </div>
                                <input type="hidden" name="layout_2d" value="1">
                            </div>
                            <div class="form-group full">
                                <label class="form-label">Measurement Remark <span
                                        style="color:#9ca3af; font-weight:400; text-transform:none;">(Optional)</span></label>
                                <textarea name="measurement_remark" class="form-control"
                                    rows="3"><?= htmlspecialchars($intake['measurement_remark'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div style="display:flex; gap:10px; margin-top:16px;">
                            <button type="submit" class="btn-submit">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <button type="button" onclick="toggleIntakeEdit()"
                                style="background:#6b7280; color:white; padding:12px 24px; border:none; border-radius:8px; cursor:pointer; font-size:14px; font-weight:600; display:inline-flex; align-items:center; gap:8px;">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div style="margin-bottom:22px;">
                <a href="<?= BASE_URL ?>designer-attachments?client_id=<?= $client_id ?>"
                    style="background:linear-gradient(135deg,#3b1f0f,#8a5a44); color:white; padding:11px 22px; border-radius:8px; font-size:14px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                    <i class="fas fa-paperclip"></i> Go to Attachments
                </a>
            </div>

        <?php endif; ?>

    </div>

    <!-- Room Unit Detail Modal -->
    <div id="designerRoomModal"
        style="display:none; position:fixed; z-index:2000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
        <div
            style="background:white; padding:28px; border-radius:14px; max-width:620px; width:90%; max-height:88vh; overflow-y:auto;">
            <div
                style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; border-bottom:2px solid #e0e7ff; padding-bottom:12px;">
                <div>
                    <h3 id="roomModalTitle" style="font-size:17px; font-weight:700; color:#3730a3;">
                        <i class="fas fa-door-open"></i> Unit Details
                    </h3>
                    <p id="roomModalArea" style="font-size:12px; color:#6b7280; margin-top:3px;"></p>
                </div>
                <button onclick="closeDesignerRoomModal()"
                    style="font-size:20px; color:#9ca3af; background:none; border:none; cursor:pointer;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="roomModalBody">
                <div style="text-align:center; padding:30px; color:#9ca3af;">
                    <i class="fas fa-spinner fa-spin" style="font-size:28px;"></i>
                    <p style="margin-top:10px;">Loading items...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Add-ons Detail Modal -->
    <div id="addonsModal"
        style="display:none; position:fixed; z-index:2000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
        <div
            style="background:white; padding:28px; border-radius:12px; max-width:560px; width:90%; max-height:85vh; overflow-y:auto;">
            <div
                style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; border-bottom:2px solid #f3f4f6; padding-bottom:12px;">
                <h3 id="addonsModalTitle" style="font-size:16px; font-weight:700; color:#3730a3;"><i
                        class="fas fa-puzzle-piece"></i> Add-ons</h3>
                <button onclick="document.getElementById('addonsModal').style.display='none'"
                    style="font-size:20px; color:#666; background:none; border:none; cursor:pointer;"><i
                        class="fas fa-times"></i></button>
            </div>
            <div id="addonsModalBody"></div>
        </div>
    </div>

    <script>
        // ── Room unit scroll ──
        function scrollRoomBtns(slug, dir) {
            const el = document.getElementById('rb-' + slug);
            if (el) el.scrollBy({ left: dir * 200, behavior: 'smooth' });
        }

        // ── Room unit modal ──
        async function showDesignerRoomModal(clientId, area, roomNumber, roomLabel) {
            document.getElementById('roomModalTitle').innerHTML =
                '<i class="fas fa-door-open"></i> ' + roomLabel;
            document.getElementById('roomModalArea').innerHTML =
                '<i class="fas fa-map-marker-alt"></i> Area: ' + area;
            document.getElementById('roomModalBody').innerHTML = `
        <div style="text-align:center; padding:30px; color:#9ca3af;">
            <i class="fas fa-spinner fa-spin" style="font-size:28px;"></i>
            <p style="margin-top:10px;">Loading items...</p>
        </div>`;
            document.getElementById('designerRoomModal').style.display = 'flex';

            try {
                const res = await fetch('<?= BASE_URL ?>get-area-room-details?client_id=' + clientId +
                    '&area=' + encodeURIComponent(area) +
                    '&room_number=' + roomNumber);
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to load');
                renderDesignerRoomItems(data.items);
            } catch (err) {
                document.getElementById('roomModalBody').innerHTML =
                    '<div style="text-align:center; padding:30px; color:#ef4444;">' +
                    '<i class="fas fa-exclamation-triangle" style="font-size:28px;"></i>' +
                    '<p style="margin-top:10px;">Error: ' + err.message + '</p></div>';
            }
        }

        function renderDesignerRoomItems(items) {
            if (!items || items.length === 0) {
                document.getElementById('roomModalBody').innerHTML =
                    '<div style="text-align:center; padding:40px; color:#9ca3af;">' +
                    '<i class="fas fa-box-open" style="font-size:36px; display:block; margin-bottom:10px;"></i>' +
                    'No items found for this unit.</div>';
                return;
            }

            let totalQty = 0;
            let html = '<div style="display:flex; flex-direction:column; gap:12px;">';

            items.forEach(function (item) {
                totalQty += parseInt(item.quantity) || 0;

                let imgPath = '';
                if (item.image_folder && item.image_file) {
                    imgPath = '<?= CLIENT_ASSET ?>/images/' + item.image_folder + '/' + item.image_file;
                }

                html += '<div style="border:1px solid #e0e7ff; border-radius:10px; overflow:hidden;">';

                // Item row
                html += '<div style="display:flex; gap:12px; padding:14px; background:#fafafa; align-items:center;">';

                // Image
                if (imgPath) {
                    html += '<img src="' + imgPath + '" style="width:52px; height:52px; object-fit:cover; border-radius:8px; border:1px solid #e0e7ff; flex-shrink:0;" onerror="this.style.display=\'none\'">';
                } else {
                    html += '<div style="width:52px; height:52px; background:#e0e7ff; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0;"><i class="fas fa-box" style="color:#818cf8;"></i></div>';
                }

                // Info
                html += '<div style="flex:1; min-width:0;">';
                html += '<div style="font-weight:700; font-size:13px; color:#1f2937;">' + escHtml(item.item_name) + '</div>';
                if (item.display_color) {
                    html += '<div style="font-size:11px; color:#6b7280; margin-top:2px;"><i class="fas fa-palette"></i> ' + escHtml(item.display_color) + '</div>';
                }
                // Dimensions
                let dims = [];
                if (item.width) dims.push((item.width_label || 'W') + ': ' + item.width + 'mm');
                if (item.height) dims.push((item.height_label || 'H') + ': ' + item.height + 'mm');
                if (item.length) dims.push((item.length_label || 'L') + ': ' + item.length + 'mm');
                if (dims.length) {
                    html += '<div style="font-size:11px; color:#9ca3af; margin-top:3px;">' + dims.join(' &nbsp;•&nbsp; ') + '</div>';
                }
                if (item.room_unit_name) {
                    html += '<div style="font-size:11px; color:#6366f1; margin-top:3px;"><i class="fas fa-door-open"></i> ' + escHtml(item.room_unit_name) + '</div>';
                }
                if (item.notes && item.notes.trim()) {
                    html += '<div style="font-size:11px; color:#92400e; background:#fffbeb; padding:3px 8px; border-radius:4px; margin-top:4px;"><i class="fas fa-sticky-note"></i> ' + escHtml(item.notes) + '</div>';
                }
                html += '</div>';

                // Qty badge
                html += '<div style="flex-shrink:0; text-align:center;">';
                html += '<div style="background:#e0e7ff; color:#3730a3; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700;">' + item.quantity + ' pcs</div>';
                html += '<div style="font-size:10px; color:#9ca3af; margin-top:3px;">' + (item.entry_type === 'customized' ? 'Custom' : 'Fixed') + '</div>';
                html += '</div>';

                html += '</div>'; // end item row

                // Addons sub-section
                if (item.addons && item.addons.length > 0) {
                    const bodyId = 'drm-addon-' + Math.random().toString(36).substr(2, 6);
                    const iconId = 'drm-icon-' + Math.random().toString(36).substr(2, 6);

                    html += '<div style="border-top:1px solid #e0e7ff; background:#f0f4ff;">';
                    html += '<button type="button" onclick="toggleDrmAddon(\'' + bodyId + '\',\'' + iconId + '\')" ';
                    html += 'style="width:100%; padding:8px 14px; background:none; border:none; cursor:pointer; display:flex; align-items:center; gap:6px; font-size:11px; font-weight:700; color:#3730a3;">';
                    html += '<i class="fas fa-puzzle-piece"></i> ' + item.addons.length + ' Add-on' + (item.addons.length > 1 ? 's' : '');
                    html += '<i id="' + iconId + '" class="fas fa-chevron-down" style="margin-left:auto; transition:transform 0.2s;"></i>';
                    html += '</button>';

                    html += '<div id="' + bodyId + '" style="display:none;">';
                    item.addons.forEach(function (addon, ai) {
                        const border = ai > 0 ? 'border-top:1px solid #dde3ff;' : '';
                        html += '<div style="display:flex; align-items:center; gap:10px; padding:8px 14px; ' + border + '">';
                        if (addon.addon_image_path) {
                            html += '<img src="<?= CLIENT_ASSET ?>/images/product_addons/' + escHtml(addon.addon_image_path) + '" ';
                            html += 'style="width:32px; height:32px; object-fit:cover; border-radius:6px; border:1px solid #c7d2fe; flex-shrink:0;" onerror="this.style.display=\'none\'">';
                        } else {
                            html += '<div style="width:32px; height:32px; background:#dde3ff; border-radius:6px; display:flex; align-items:center; justify-content:center; flex-shrink:0;"><i class="fas fa-puzzle-piece" style="color:#818cf8; font-size:12px;"></i></div>';
                        }
                        html += '<div style="flex:1;">';
                        html += '<div style="font-size:12px; font-weight:700; color:#1e1b4b;">' + escHtml(addon.addon_name) + '</div>';
                        html += '<div style="font-size:11px; color:#4f46e5;">₱' + parseFloat(addon.price).toFixed(2) + ' / pc</div>';
                        if (addon.note) html += '<div style="font-size:10px; color:#64748b; font-style:italic;">' + escHtml(addon.note) + '</div>';
                        html += '</div>';
                        html += '</div>';
                    });
                    html += '</div>'; // addon body
                    html += '</div>'; // addon section
                }

                html += '</div>'; // end card
            });

            html += '</div>';

            // Summary footer
            html += '<div style="margin-top:14px; padding:14px 16px; background:linear-gradient(135deg,#3730a3,#6366f1); border-radius:10px; display:flex; justify-content:space-between; align-items:center; color:white;">';
            html += '<span style="font-size:13px; font-weight:600;"><i class="fas fa-boxes"></i> Total Items in Unit</span>';
            html += '<span style="font-size:22px; font-weight:700;">' + totalQty + '</span>';
            html += '</div>';

            document.getElementById('roomModalBody').innerHTML = html;
        }

        function toggleDrmAddon(bodyId, iconId) {
            const body = document.getElementById(bodyId);
            const icon = document.getElementById(iconId);
            if (!body) return;
            const open = body.style.display !== 'none';
            body.style.display = open ? 'none' : 'block';
            if (icon) icon.style.transform = open ? '' : 'rotate(180deg)';
        }

        function closeDesignerRoomModal() {
            document.getElementById('designerRoomModal').style.display = 'none';
        }

        function escHtml(text) {
            if (!text) return '';
            const d = document.createElement('div');
            d.textContent = text;
            return d.innerHTML;
        }

        function showAddons(itemName, addons) {
            document.getElementById('addonsModalTitle').innerHTML = '<i class="fas fa-puzzle-piece"></i> Add-ons for: ' + itemName;
            let html = '<div style="display:flex; flex-direction:column; gap:12px;">';
            let grandTotal = 0;
            addons.forEach(function (a) {
                const sub = parseFloat(a.quantity) * parseFloat(a.price);
                grandTotal += sub;
                html += '<div style="display:flex; align-items:center; gap:14px; padding:12px; border:1px solid #e5e7eb; border-radius:8px; background:#fafafa;">';
                if (a.addon_image_path) {
                    html += '<img src="<?= CLIENT_ASSET ?>/images/product_addons/' + a.addon_image_path + '" style="width:50px; height:50px; object-fit:cover; border-radius:6px; border:1px solid #e5e7eb;" onerror="this.style.display=\'none\'">';
                }
                html += '<div style="flex:1;">';
                html += '<div style="font-weight:700; font-size:13px; color:#111;">' + a.addon_name + '</div>';
                if (a.note) html += '<div style="font-size:11px; color:#9ca3af; margin-top:2px;"><i class="fas fa-sticky-note"></i> ' + a.note + '</div>';
                html += '<div style="font-size:12px; color:#6b7280; margin-top:4px;">Qty: <strong>' + a.quantity + '</strong> × ₱' + parseFloat(a.price).toFixed(2) + '</div>';
                html += '</div>';
                html += '<div style="font-weight:700; color:#065f46; font-size:14px;">₱' + sub.toFixed(2) + '</div>';
                html += '</div>';
            });
            html += '</div>';
            html += '<div style="margin-top:14px; padding:12px 16px; background:#f0fdf4; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">';
            html += '<span style="font-weight:600; color:#374151;">Add-ons Total</span>';
            html += '<span style="font-weight:700; font-size:16px; color:#065f46;">₱' + grandTotal.toFixed(2) + '</span>';
            html += '</div>';
            document.getElementById('addonsModalBody').innerHTML = html;
            document.getElementById('addonsModal').style.display = 'flex';
        }

        // Close modals on outside click
        document.addEventListener('click', function (e) {
            ['addonsModal', 'clientDetailModal2', 'designerRoomModal'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el && e.target === el) el.style.display = 'none';
            });
        });

        // ── Multi-select revision ──
        let revSelections = []; // [{area, unitNum, unitName, reason}]

        function getSelKey(area, unitNum) {
            return area + '||' + (unitNum ?? 'null');
        }

        function onAreaCheck(cb) {
            const area = cb.dataset.area;
            const key = getSelKey(area, null);
            if (cb.checked) {
                if (!revSelections.find(s => getSelKey(s.area, s.unitNum) === key)) {
                    revSelections.push({ area, unitNum: null, unitName: null, reason: '' });
                }
            } else {
                revSelections = revSelections.filter(s => getSelKey(s.area, s.unitNum) !== key);
            }
            updateSummary();
        }

        function removeSelection(key) {
            const idx = revSelections.findIndex(s => getSelKey(s.area, s.unitNum) === key);
            if (idx === -1) return;
            const s = revSelections[idx];
            // Uncheck the checkbox
            if (s.unitNum !== null) {
                const cb = document.querySelector(`.rev-unit-check[data-area="${CSS.escape(s.area)}"][data-unit-num="${s.unitNum}"]`);
                if (cb) { cb.checked = false; onUnitCheck(cb); return; }
            } else {
                const cb = document.querySelector(`.rev-area-check[data-area="${CSS.escape(s.area)}"]`);
                if (cb) { cb.checked = false; onAreaCheck(cb); return; }
            }
            revSelections.splice(idx, 1);
            updateSummary();
        }

        function updateSummary() {
            const box = document.getElementById('selectionSummary');
            const items = document.getElementById('selectionItems');
            const inp = document.getElementById('selectionsInput');

            if (revSelections.length === 0) {
                box.style.display = 'none';
                inp.value = '';
                updateSubmitBtn();
                return;
            }

            box.style.display = 'block';
            items.innerHTML = revSelections.map((s, i) => {
                const key = getSelKey(s.area, s.unitNum);
                const label = s.unitNum !== null
                    ? s.area + ' › ' + (s.unitName || 'Unit ' + s.unitNum)
                    : s.area + ' (whole area)';
                return `<div style="border:1px solid #fcd34d; border-radius:8px; padding:12px 14px; background:white;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:13px; font-weight:700; color:#92400e;">
                    <i class="fas fa-map-marker-alt"></i> ${label}
                </span>
                <button type="button" onclick="removeSelection('${key}')"
                    style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:13px; padding:0 4px;">
                    <i class="fas fa-times"></i> Remove
                </button>
            </div>
            <textarea
                placeholder="Reason for revision on this area/unit... *"
                oninput="updateReason('${key}', this.value)"
                style="width:100%; padding:8px 10px; border:1px solid #e9ecef; border-radius:6px; font-size:13px; font-family:inherit; resize:vertical; min-height:60px; box-sizing:border-box;"
            >${s.reason}</textarea>
        </div>`;
            }).join('');

            inp.value = JSON.stringify(revSelections);
            updateSubmitBtn();
        }

        function updateReason(key, val) {
            const s = revSelections.find(s => getSelKey(s.area, s.unitNum) === key);
            if (s) s.reason = val.trim();
            document.getElementById('selectionsInput').value = JSON.stringify(revSelections);
            updateSubmitBtn();
        }

        function updateSubmitBtn() {
            const btn = document.getElementById('revisionSubmitBtn');
            const ready = revSelections.length > 0 && revSelections.every(s => s.reason.trim() !== '');
            btn.disabled = !ready;
            btn.style.opacity = ready ? '1' : '0.5';
            btn.style.cursor = ready ? 'pointer' : 'not-allowed';
        }

        function confirmRevision() {
            if (revSelections.length === 0) return false;
            if (!revSelections.every(s => s.reason.trim() !== '')) {
                alert('Please fill in a reason for each selected area/unit.');
                return false;
            }
            const lines = revSelections.map(s =>
                s.unitNum !== null
                    ? '  • ' + s.area + ' › ' + (s.unitName || 'Unit ' + s.unitNum)
                    : '  • ' + s.area + ' (whole area)'
            ).join('\n');
            return confirm(
                'This will count as Revision #1 (one submission).\n\nAreas/units to reset:\n' + lines +
                '\n\nApprovals for these will be reset. Continue?'
            );
        }

        function toggleRevHistory() {
            const panel = document.getElementById('revHistoryPanel');
            const icon = document.getElementById('revHistoryBtnIcon');
            const text = document.getElementById('revHistoryBtnText');
            const open = panel.style.display !== 'none';
            panel.style.display = open ? 'none' : 'block';
            icon.className = open ? 'fas fa-eye' : 'fas fa-eye-slash';
            text.textContent = open ? 'Show History' : 'Hide History';
        }

        function toggleRevPanel(panelId, chevronId) {
            const panel = document.getElementById(panelId);
            const chev = document.getElementById(chevronId);
            const open = panel.style.display !== 'none';
            panel.style.display = open ? 'none' : 'block';
            chev.style.transform = open ? '' : 'rotate(180deg)';
        }

        function toggleIntakeEdit() {
            const viewMode = document.getElementById('intakeViewMode');
            const editMode = document.getElementById('intakeEditMode');
            const btn = document.getElementById('intakeEditBtn');
            const isEditing = editMode.style.display !== 'none';
            viewMode.style.display = isEditing ? 'block' : 'none';
            editMode.style.display = isEditing ? 'none' : 'block';
            btn.innerHTML = isEditing
                ? '<i class="fas fa-pen"></i> Edit'
                : '<i class="fas fa-times"></i> Cancel';
        }
    </script>
</body>

</html>