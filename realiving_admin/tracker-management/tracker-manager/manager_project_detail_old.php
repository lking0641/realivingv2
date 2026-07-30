<?php
// manager_project_detail.php
session_start();
include '../../connection/connection.php';
include '../design/mainbody.php';
include '../checkrole/checkrole.php';

// Restrict access to general_manager and operational_manager only
$allowedRoles = ['general_manager', 'operational_manager', 'superadmin', 'sales'];

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

// Check user's role
$roleStmt = $conn->prepare("SELECT role, full_name FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();

if (!in_array($userInfo['role'], $allowedRoles)) {
    die("Access Denied: This page is only accessible by General Manager and Operational Manager.");
}

// Fetch client information
$clientStmt = $conn->prepare("
    SELECT 
        u.*,
        a.full_name as admin_name,
        a.role as admin_role
    FROM user_info u
    LEFT JOIN account a ON u.accountaid_fk = a.id
    WHERE u.id = ?
");
$clientStmt->bind_param("i", $client_id);
$clientStmt->execute();
$client = $clientStmt->get_result()->fetch_assoc();

if (!$client) {
    die("Client not found.");
}

// Display-friendly business type label
$business_type_label = ($client['business_type'] ?? '') === 'Non-Project' ? 'Individual' : ($client['business_type'] ?? '');

// Get current revision count
$current_revision = $client['revision_count'] ?? 0;

// Fetch tracker statuses
$trackerStmt = $conn->prepare("
    SELECT 
        pt.*,
        a.full_name as updated_by_name
    FROM project_tracker pt
    LEFT JOIN account a ON pt.updated_by = a.id
    WHERE pt.client_id = ?
");
$trackerStmt->bind_param("i", $client_id);
$trackerStmt->execute();
$trackerResult = $trackerStmt->get_result();
$trackerData = [];

while ($row = $trackerResult->fetch_assoc()) {
    // Fetch assigned people for this stage
    $row['assigned_people'] = [];
    $assignStmt = $conn->prepare("
        SELECT assigned_to 
        FROM stage_assignments 
        WHERE stage_id = ?
        ORDER BY assigned_at
    ");
    $assignStmt->bind_param("i", $row['id']);
    $assignStmt->execute();
    $assignResult = $assignStmt->get_result();
    
    while ($assignRow = $assignResult->fetch_assoc()) {
        $row['assigned_people'][] = $assignRow['assigned_to'];
    }
    
    $trackerData[$row['stage_name']] = $row;
}

$stages = [
    'Site Visit',
    '2D / 3D Layout',
    'Proposal (Confirmation of Client)',
    'Samples Submitted TDS/MDS',
    'Quotation',
    'Contract Signing (Quotation and Final Layout)',
    'Downpayment',
    'Materials Estimate',
    'Purchase Order (Submit to accounting)',
    'Accounting (Order Processing)',
    'Cuttinglist',
    'Production Data Submittals',
    'Fabrication',
    'Delivery',
    'Installation',
    'BILLING',
    'Handover'
];

// Calculate progress
$total_stages = count($stages);
$pending_count = 0;
$ongoing_count = 0;
$done_count = 0;

foreach ($trackerData as $data) {
    if ($data['status'] === 'Pending') $pending_count++;
    elseif ($data['status'] === 'Ongoing') $ongoing_count++;
    elseif ($data['status'] === 'Done') $done_count++;
}

$completion_percentage = ($done_count / $total_stages) * 100;

// Fetch payment schedule
$scheduleQuery = $conn->prepare("
    SELECT * FROM payment_schedule 
    WHERE client_id = ? 
    ORDER BY id
");
$scheduleQuery->bind_param("i", $client_id);
$scheduleQuery->execute();
$payments = $scheduleQuery->get_result();

// Calculate payment totals
$total_paid = 0;
$total_pending = 0;

$payments->data_seek(0);
while ($payment = $payments->fetch_assoc()) {
    if ($payment['status'] === 'Paid') {
        $total_paid += $payment['amount'];
    } elseif ($payment['status'] === 'Pending') {
        $total_pending += $payment['amount'];
    }
}

$payment_progress_percentage = ($client['total_project_cost'] > 0) 
    ? ($total_paid / $client['total_project_cost']) * 100 
    : 0;

// Fetch approval files for all approval stages
$approvalStages = [
    'Samples Submitted TDS/MDS',
    'Quotation',
    'Materials Estimate',
    'Purchase Order (Submit to accounting)',
    'Production Data Submittals'
];

$approvalStageRoles = [
    'Samples Submitted TDS/MDS'              => ['general_manager', 'project_coordinator', 'operational_manager'],
    'Quotation'                               => ['operational_manager', 'general_manager'],
    'Materials Estimate'                      => ['operational_manager', 'general_manager', 'project_coordinator'],
    'Purchase Order (Submit to accounting)'   => ['operational_manager', 'general_manager', 'accounting'],
    'Production Data Submittals'              => ['operational_manager', 'general_manager'],
];

// Required approvers per stage (canonical list)
$requiredApproversList = [
    'Samples Submitted TDS/MDS'             => ['general_manager', 'project_coordinator', 'operational_manager'],
    'Quotation'                              => ['operational_manager', 'general_manager', 'designer'],
    'Materials Estimate'                     => ['operational_manager', 'general_manager', 'project_coordinator'],
    'Purchase Order (Submit to accounting)'  => ['operational_manager', 'general_manager', 'accounting', 'technical_designer'],
    'Production Data Submittals'             => ['operational_manager', 'general_manager'],
];

function getRoleDisplayName($role) {
    $names = [
        'general_manager'    => 'General Manager',
        'operational_manager'=> 'Operational Manager',
        'project_coordinator'=> 'Project Coordinator',
        'designer'           => 'Designer (Head)',
        'technical_designer' => 'Technical Designer (Head)',
        'accounting'         => 'Accounting',
    ];
    return $names[$role] ?? ucwords(str_replace('_', ' ', $role));
}

function buildRoleApprovalBadges($af, $requiredRoles, $currentUserRole, $currentUserId, $isHead, $stageName) {
    $roleReviews = $af['role_reviews'] ?? [];
    $html = '<div style="margin-top:10px; display:flex; flex-wrap:wrap; gap:6px; align-items:center;">';
    $html .= '<span style="font-size:11px; color:#718096; font-weight:600; margin-right:4px;">Approvals:</span>';
    
    foreach ($requiredRoles as $role) {
        $displayName = getRoleDisplayName($role);
        if (isset($roleReviews[$role])) {
            $rev = $roleReviews[$role];
            if ($rev['review_status'] === 'approved') {
                $html .= '<span style="background:#d1fae5; color:#065f46; border:1px solid #10b981; padding:3px 10px; border-radius:10px; font-size:11px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                    <i class="fas fa-check-circle"></i> ' . $displayName . '</span>';
            } else {
                $html .= '<span style="background:#fee2e2; color:#991b1b; border:1px solid #ef4444; padding:3px 10px; border-radius:10px; font-size:11px; font-weight:600; display:inline-flex; align-items:center; gap:4px;" title="' . htmlspecialchars($rev['review_note'] ?? '') . '">
                    <i class="fas fa-times-circle"></i> ' . $displayName . '</span>';
            }
        } else {
            $html .= '<span style="background:#f3f4f6; color:#9ca3af; border:1px solid #e5e7eb; padding:3px 10px; border-radius:10px; font-size:11px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                <i class="fas fa-clock"></i> ' . $displayName . '</span>';
        }
    }
    $html .= '</div>';
    return $html;
}

$allApprovalFiles = [];
foreach ($approvalStages as $aStageName) {
    $stageRow = $trackerData[$aStageName] ?? null;
    if (!$stageRow) continue;

    $afStmt = $conn->prepare("
        SELECT sa.*, 
               a1.full_name as uploaded_by_name,
               a2.full_name as reviewed_by_name
        FROM stage_approvals sa
        LEFT JOIN account a1 ON sa.uploaded_by = a1.id
        LEFT JOIN account a2 ON sa.reviewed_by = a2.id
        WHERE sa.stage_id = ?
        ORDER BY sa.uploaded_at DESC
    ");
    $afStmt->bind_param("i", $stageRow['id']);
    $afStmt->execute();
    $afResult = $afStmt->get_result();
    $files = [];
    while ($afRow = $afResult->fetch_assoc()) {
        $revStmt = $conn->prepare("
            SELECT sar.*, a.full_name as reviewer_name
            FROM stage_approval_reviews sar
            LEFT JOIN account a ON sar.reviewed_by = a.id
            WHERE sar.approval_id = ?
        ");
        $revStmt->bind_param("i", $afRow['id']);
        $revStmt->execute();
        $revResult = $revStmt->get_result();
        $roleReviews = [];
        while ($rev = $revResult->fetch_assoc()) {
            $roleReviews[$rev['reviewer_role']] = $rev;
        }
        $afRow['role_reviews'] = $roleReviews;
        $files[] = $afRow;
    }
    $allApprovalFiles[$aStageName] = $files;
}

// Check if current user can approve per stage
function canManagerApprove($stageName, $adminRole, $isHead, $approvalStageRoles) {
    $allowed = $approvalStageRoles[$stageName] ?? [];
    if (in_array($adminRole, $allowed)) return true;
    if ($stageName === 'Quotation' && $adminRole === 'designer' && $isHead) return true;
    if ($stageName === 'Purchase Order (Submit to accounting)' && $adminRole === 'technical_designer' && $isHead) return true;
    return false;
}

$managerIsHead = false; // managers don't need head check, but keep for safety

// Fetch assigned designers for site visit
$designerStmt = $conn->prepare("
    SELECT a1.full_name as designer1_name, a2.full_name as designer2_name
    FROM user_info ui
    LEFT JOIN account a1 ON ui.designer1_id = a1.id
    LEFT JOIN account a2 ON ui.designer2_id = a2.id
    WHERE ui.id = ?
");
$designerStmt->bind_param("i", $client_id);
$designerStmt->execute();
$designerData = $designerStmt->get_result()->fetch_assoc();
$designer1_name = $designerData['designer1_name'] ?? null;
$designer2_name = $designerData['designer2_name'] ?? null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Status - <?= htmlspecialchars($client['clientname']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f5f1ed;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .btn-back {
            background: white;
            color: #8a5a44;
            padding: 12px 24px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            transition: all 0.3s ease;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .btn-back:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }

        /* Executive Header */
        .executive-header {
            background: white;
            padding: 40px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }

        .executive-header h1 {
            font-size: 32px;
            color: #1a202c;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .executive-header h1 i {
            color: #8a5a44;
        }

        .executive-header .subtitle {
            font-size: 18px;
            color: #718096;
            margin-bottom: 25px;
        }

        .header-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }

        .header-info-card {
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
            padding: 20px;
            border-radius: 12px;
            border-left: 4px solid #8a5a44;
        }

        .header-info-label {
            font-size: 11px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .header-info-value {
            font-size: 18px;
            color: #1a202c;
            font-weight: 700;
        }

        .business-type-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .business-type-badge.project {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
        }

        .business-type-badge.non-project {
            background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
            color: white;
        }

        /* Progress Dashboard */
        .progress-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .progress-card {
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .progress-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .progress-card-title {
            font-size: 14px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .progress-card-percentage {
            font-size: 28px;
            font-weight: 700;
            color: #8a5a44;
        }

        .progress-bar-large {
            height: 12px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .progress-bar-fill-large {
            height: 100%;
            background: linear-gradient(90deg, #3b1f0f 0%, #8a5a44 100%);
            transition: width 0.5s ease;
            border-radius: 10px;
        }

        .progress-bar-fill-large.green {
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
        }

        .progress-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .progress-stat-item {
            text-align: center;
            padding: 15px;
            background: #f7fafc;
            border-radius: 10px;
        }

        .progress-stat-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .progress-stat-value.pending { color: #f59e0b; }
        .progress-stat-value.ongoing { color: #3b82f6; }
        .progress-stat-value.done { color: #10b981; }

        .progress-stat-label {
            font-size: 11px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Stages Timeline */
        .stages-container {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }

        .stages-header {
            margin-bottom: 30px;
        }

        .stages-header h2 {
            font-size: 24px;
            color: #1a202c;
            margin-bottom: 10px;
        }

        .stages-timeline {
            position: relative;
            padding-left: 40px;
        }

        .stages-timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(180deg, #3b1f0f 0%, #8a5a44 100%);
        }

        .stage-item {
            position: relative;
            margin-bottom: 30px;
            padding: 25px;
            background: #f7fafc;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .stage-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .stage-item::before {
            content: '';
            position: absolute;
            left: -33px;
            top: 30px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: white;
            border: 4px solid #cbd5e0;
        }

        .stage-item.status-pending::before {
            border-color: #f59e0b;
        }

        .stage-item.status-ongoing::before {
            border-color: #3b82f6;
        }

        .stage-item.status-done::before {
            border-color: #10b981;
            background: #10b981;
        }

        .stage-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .stage-number-name {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
        }

        .stage-number {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
        }

        .stage-name {
            font-size: 18px;
            font-weight: 700;
            color: #1a202c;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.ongoing {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-badge.done {
            background: #d1fae5;
            color: #065f46;
        }

        .stage-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .stage-detail-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: #4a5568;
        }

        .stage-detail-item i {
            color: #8a5a44;
            width: 20px;
        }

        .assigned-people {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .assigned-badge {
            background: #f0e6db;
            color: #3b1f0f;
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* Payment Tracker Section */
        .payment-section {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .payment-header {
            margin-bottom: 30px;
        }

        .payment-header h2 {
            font-size: 24px;
            color: #1a202c;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .payment-header h2 i {
            color: #10b981;
        }

        .payment-item {
            background: #f7fafc;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
            border-left: 4px solid #cbd5e0;
            transition: all 0.3s ease;
        }

        .payment-item:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .payment-item.status-paid {
            border-left-color: #10b981;
            background: #f0fdf4;
        }

        .payment-item.status-pending {
            border-left-color: #f59e0b;
            background: #fffbeb;
        }

        .payment-item.status-not-available {
            border-left-color: #9ca3af;
            background: #f9fafb;
            opacity: 0.7;
        }

        .payment-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .payment-type {
            font-size: 16px;
            font-weight: 700;
            color: #1a202c;
        }

        .payment-amount {
            font-size: 20px;
            font-weight: 700;
            color: #10b981;
        }

        .payment-meta {
            display: flex;
            gap: 20px;
            align-items: center;
            font-size: 13px;
            color: #718096;
        }

        .revision-badge {
            background: #f0e6db;
            color: #3b1f0f;
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* ===== APPROVAL STYLES ===== */
.approval-required-badge {
    background: #fef3c7;
    color: #92400e;
    padding: 3px 10px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
    margin-left: 8px;
}

.approval-section {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 2px solid #e2e8f0;
}

.approval-files-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.approval-file-card {
    background: white;
    border-radius: 8px;
    padding: 14px 16px;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #cbd5e0;
    transition: all 0.2s;
}

.approval-file-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.approval-file-card.status-pending  { border-left-color: #f59e0b; background: #fffbeb; }
.approval-file-card.status-approved { border-left-color: #10b981; background: #f0fdf4; }
.approval-file-card.status-rejected { border-left-color: #ef4444; background: #fff5f5; }

.file-label-tag {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #3b1f0f;
    background: #fef3c7;
    padding: 2px 8px;
    border-radius: 6px;
    display: inline-block;
    margin-bottom: 4px;
}

.approval-status-pill {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    border: 1px solid;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.approval-status-pill.pending  { background:#fef3c7; color:#92400e; border-color:#f59e0b; }
.approval-status-pill.approved { background:#d1fae5; color:#065f46; border-color:#10b981; }
.approval-status-pill.rejected { background:#fee2e2; color:#991b1b; border-color:#ef4444; }

.file-note-box {
    margin-top: 8px;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 12px;
    display: flex;
    align-items: flex-start;
    gap: 6px;
}
.file-note-box.rejected { background: #fee2e2; color: #991b1b; }
.file-note-box.approved { background: #d1fae5; color: #065f46; }

.btn-approve-action {
    background: #10b981; color: white;
    padding: 5px 14px; border-radius: 6px;
    font-size: 12px; font-weight: 700;
    border: none; cursor: pointer;
    display: inline-flex; align-items: center; gap: 4px;
    transition: all 0.2s;
}
.btn-approve-action:hover { background: #059669; }

.btn-reject-action {
    background: #ef4444; color: white;
    padding: 5px 14px; border-radius: 6px;
    font-size: 12px; font-weight: 700;
    border: none; cursor: pointer;
    display: inline-flex; align-items: center; gap: 4px;
    transition: all 0.2s;
}
.btn-reject-action:hover { background: #dc2626; }

.btn-view-file {
    background: #3b82f6; color: white;
    padding: 5px 14px; border-radius: 6px;
    font-size: 12px; font-weight: 700;
    text-decoration: none;
    display: inline-flex; align-items: center; gap: 4px;
    transition: all 0.2s;
}
.btn-view-file:hover { background: #2563eb; }

.reject-inline-form {
    display: none;
    margin-top: 10px;
    padding: 12px;
    background: #fff5f5;
    border-radius: 8px;
    border: 1px solid #fecaca;
}

.reject-inline-form textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #fca5a5;
    border-radius: 6px;
    font-size: 13px;
    font-family: inherit;
    resize: vertical;
    min-height: 70px;
    margin-bottom: 8px;
}

.reject-inline-form textarea:focus {
    outline: none;
    border-color: #ef4444;
}

.no-files-yet {
    text-align: center;
    padding: 20px;
    color: #a0aec0;
    background: #f9fafb;
    border-radius: 8px;
    border: 2px dashed #e2e8f0;
    font-size: 13px;
}

.toast-detail {
    position: fixed;
    top: 20px; right: 20px;
    background: white;
    padding: 16px 24px;
    border-radius: 10px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    display: none;
    align-items: center;
    gap: 12px;
    z-index: 9999;
    font-size: 14px;
    font-weight: 600;
}
.toast-detail.show { display: flex; }
.toast-detail.success { border-left: 4px solid #10b981; }
.toast-detail.error   { border-left: 4px solid #ef4444; }
    </style>
</head>
<body>
    <div class="container">
        <a href="manager_status_tracker.php" class="btn-back">
            <i class="fas fa-arrow-left"></i>
            Back to Status Tracker
        </a>

        <!-- Executive Header -->
        <div class="executive-header">
            <h1>
                <i class="fas fa-chart-line"></i>
                Project Status Dashboard
            </h1>
            <div class="subtitle">
                <?= htmlspecialchars($client['clientname']) ?> - <?= htmlspecialchars($client['nameproject']) ?>
            </div>

            <div class="header-grid">
                <div class="header-info-card">
                    <div class="header-info-label">Reference Number</div>
                    <div class="header-info-value"><?= htmlspecialchars($client['reference_number']) ?></div>
                </div>

                <div class="header-info-card">
                    <div class="header-info-label">Business Type</div>
                    <div class="header-info-value">
                        <span class="business-type-badge <?= strtolower(str_replace('-', '-', $client['business_type'])) ?>">
                            <?= htmlspecialchars($business_type_label) ?>
                        </span>
                    </div>
                </div>

                <div class="header-info-card">
                    <div class="header-info-label">Project Manager</div>
                    <div class="header-info-value">
                        <?= htmlspecialchars($client['admin_name'] ?? 'Unassigned') ?>
                    </div>
                </div>

                <div class="header-info-card">
                    <div class="header-info-label">Total Project Cost</div>
                    <div class="header-info-value">₱<?= number_format($client['total_project_cost'] ?? 0, 2) ?></div>
                </div>
            </div>
        </div>

        <!-- Progress Dashboard -->
        <div class="progress-dashboard">
            <div class="progress-card">
                <div class="progress-card-header">
                    <span class="progress-card-title">
                        <i class="fas fa-tasks"></i> Project Completion
                    </span>
                    <span class="progress-card-percentage">
                        <?= number_format($completion_percentage, 1) ?>%
                    </span>
                </div>
                <div class="progress-bar-large">
                    <div class="progress-bar-fill-large" style="width: <?= $completion_percentage ?>%"></div>
                </div>
                <div class="progress-stats-grid">
                    <div class="progress-stat-item">
                        <div class="progress-stat-value pending"><?= $pending_count ?></div>
                        <div class="progress-stat-label">Pending</div>
                    </div>
                    <div class="progress-stat-item">
                        <div class="progress-stat-value ongoing"><?= $ongoing_count ?></div>
                        <div class="progress-stat-label">Ongoing</div>
                    </div>
                    <div class="progress-stat-item">
                        <div class="progress-stat-value done"><?= $done_count ?></div>
                        <div class="progress-stat-label">Done</div>
                    </div>
                </div>
            </div>

            <div class="progress-card">
                <div class="progress-card-header">
                    <span class="progress-card-title">
                        <i class="fas fa-money-bill-wave"></i> Payment Progress
                    </span>
                    <span class="progress-card-percentage">
                        <?= number_format($payment_progress_percentage, 1) ?>%
                    </span>
                </div>
                <div class="progress-bar-large">
                    <div class="progress-bar-fill-large green" style="width: <?= $payment_progress_percentage ?>%"></div>
                </div>
                <div class="progress-stats-grid">
                    <div class="progress-stat-item">
                        <div class="progress-stat-value" style="color: #3b82f6;">
                            ₱<?= number_format($client['total_project_cost'] ?? 0, 0) ?>
                        </div>
                        <div class="progress-stat-label">Total</div>
                    </div>
                    <div class="progress-stat-item">
                        <div class="progress-stat-value" style="color: #10b981;">
                            ₱<?= number_format($total_paid, 0) ?>
                        </div>
                        <div class="progress-stat-label">Collected</div>
                    </div>
                    <div class="progress-stat-item">
                        <div class="progress-stat-value" style="color: #f59e0b;">
                            ₱<?= number_format($client['remaining_balance'] ?? 0, 0) ?>
                        </div>
                        <div class="progress-stat-label">Balance</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stages Timeline -->
        <div class="stages-container">
            <div class="stages-header">
                <h2><i class="fas fa-list-check"></i> Project Stages Timeline</h2>
                <p style="color: #718096; font-size: 14px;">Detailed view of all project stages and their current status</p>
            </div>

            <div class="stages-timeline">
                <?php foreach ($stages as $index => $stage): ?>
                    <?php
                    if ($stage === 'Downpayment') {
    // Get downpayment status from payment_schedule AND updated_by info from project_tracker
    $downpaymentStmt = $conn->prepare("
        SELECT ps.status, ps.payment_date,
               pt.updated_by, pt.updated_at,
               a.full_name as updated_by_name
        FROM payment_schedule ps
        LEFT JOIN project_tracker pt ON pt.client_id = ps.client_id AND pt.stage_name = 'Downpayment'
        LEFT JOIN account a ON pt.updated_by = a.id
        WHERE ps.client_id = ? AND ps.payment_type LIKE '%Down%'
        LIMIT 1
    ");
    $downpaymentStmt->bind_param("i", $client_id);
    $downpaymentStmt->execute();
    $downpaymentResult = $downpaymentStmt->get_result()->fetch_assoc();
    $downpayment_status = $downpaymentResult ? $downpaymentResult['status'] : 'Pending';
    
    $status = ($downpayment_status === 'Paid') ? 'Done' : 'Pending';
    $stageData = $trackerData[$stage] ?? null;
    $updated_at = $downpaymentResult ? $downpaymentResult['updated_at'] : null;
    $updated_by_name = $downpaymentResult ? $downpaymentResult['updated_by_name'] : null;
    
    $downpayment_percentage = ($client['business_type'] === 'Non-Project') ? 50 : 30;
    $downpayment_amount = ($client['total_project_cost'] ?? 0) * ($downpayment_percentage / 100);
                    } elseif (in_array($stage, ['Fabrication', 'Delivery', 'Installation', 'BILLING'])) {
    $isApprovalStage = false;
    $stageApprovalFiles = [];
    $assigned_people = [];
    // Get aggregated status
    $statusColumn = strtolower($stage) . '_status';
    $itemStatusStmt = $conn->prepare("
        SELECT 
            CASE 
                WHEN COUNT(*) = 0 THEN 'Pending'
                WHEN COUNT(*) = SUM(CASE WHEN $statusColumn = 'Done' THEN 1 ELSE 0 END) THEN 'Done'
                WHEN SUM(CASE WHEN $statusColumn = 'Ongoing' THEN 1 ELSE 0 END) > 0 THEN 'Ongoing'
                ELSE 'Pending'
            END as aggregated_status
        FROM quotation_entries
        WHERE client_id = ?
    ");
    $canManagerViewItems = in_array($userInfo['role'], ['general_manager', 'operational_manager']);
    $itemStatusStmt->bind_param("i", $client_id);
    $itemStatusStmt->execute();
    $itemStatusResult = $itemStatusStmt->get_result()->fetch_assoc();
    $status = $itemStatusResult['aggregated_status'] ?? 'Pending';
    
    $stageData = $trackerData[$stage] ?? null;
    $updated_at = $stageData ? $stageData['updated_at'] : null;
    $updated_by_name = $stageData ? $stageData['updated_by_name'] : null;
                    } else {
    $stageData = $trackerData[$stage] ?? null;
    $status = $stageData ? $stageData['status'] : 'Pending';
    $updated_at = $stageData ? $stageData['updated_at'] : null;
    $updated_by_name = $stageData ? $stageData['updated_by_name'] : null;
    $assigned_people = $stageData ? $stageData['assigned_people'] : [];
    $isApprovalStage = in_array($stage, $approvalStages);
    $stageApprovalFiles = $isApprovalStage ? ($allApprovalFiles[$stage] ?? []) : [];
    $canApproveThis = $isApprovalStage
        ? canManagerApprove($stage, $userInfo['role'], false, $approvalStageRoles)
        : false;
}
                    
                    $statusClass = strtolower($status);
                    ?>
                    <div class="stage-item status-<?= $statusClass ?>">
                        <div class="stage-header">
                            <div class="stage-number-name">
                                <div class="stage-number"><?= $index + 1 ?></div>
                                <div>
                                    <div class="stage-name">
                                <?php if ($stage === '2D / 3D Layout'): ?>
                                    <a href="../tracker_designer/designer_2d3d_layout.php?client_id=<?= $client_id ?>&back=manager_detail"
                                       style="text-decoration:none; color:inherit; display:inline-flex; align-items:center; gap:6px;">
                                        <?= htmlspecialchars($stage) ?>
                                        <i class="fas fa-external-link-alt" style="font-size:11px; opacity:0.7;"></i>
                                    </a>
                                    <span class="revision-badge" style="margin-left:8px;">
                                        <i class="fas fa-sync-alt"></i>
                                        Rev: <?= $current_revision ?>
                                    </span>
                                <?php else: ?>
                                    <?php if (in_array($stage, ['Fabrication', 'Delivery', 'Installation']) && ($canManagerViewItems ?? false)): ?>
                                        <a href="../tracker_management/item_tracker.php?client_id=<?= $client_id ?>&stage=<?= urlencode($stage) ?>&view_only=1"
                                           style="text-decoration:none; color:inherit; display:inline-flex; align-items:center; gap:6px;">
                                            <?= htmlspecialchars($stage) ?>
                                            <i class="fas fa-external-link-alt" style="font-size:11px; opacity:0.7;"></i>
                                        </a>
                                    <?php elseif ($stage === 'BILLING' && ($canManagerViewItems ?? false)): ?>
                                        <a href="../tracker_management/payment_tracker.php?client_id=<?= $client_id ?>&view_only=1"
                                           style="text-decoration:none; color:inherit; display:inline-flex; align-items:center; gap:6px;">
                                            <?= htmlspecialchars($stage) ?>
                                            <i class="fas fa-external-link-alt" style="font-size:11px; opacity:0.7;"></i>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($stage) ?>
                                        <?php if (in_array($stage, $approvalStages)): ?>
                                            <span class="approval-required-badge">
                                                <i class="fas fa-stamp"></i> APPROVAL REQUIRED
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php endif; ?>
                            </div>
                                </div>
                            </div>
                            <span class="status-badge <?= $statusClass ?>">
    <?= htmlspecialchars($status) ?>
</span>
<?php if ($stage === 'Site Visit'): ?>
<a href="../manager_tracker/manager_site_visit_approval.php?client_id=<?= $client_id ?>"
   style="background:#2563eb; color:white; padding:7px 16px; border-radius:8px; font-size:12px;
          font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
    <i class="fas fa-clipboard-check"></i> Review Site Visits
</a>
<?php endif; ?>
                        </div>

                        <div class="stage-details">
                            <?php if ($updated_at): ?>
                                <div class="stage-detail-item">
                                    <i class="fas fa-clock"></i>
                                    <span>Updated: <?= date('M d, Y g:i A', strtotime($updated_at)) ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (isset($updated_by_name) && $updated_by_name): ?>
                                <div class="stage-detail-item">
                                    <i class="fas fa-user-edit"></i>
                                    <span>By: <?= htmlspecialchars($updated_by_name) ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($stage === 'Downpayment'): ?>
                                <div class="stage-detail-item">
                                    <i class="fas fa-money-bill"></i>
                                    <span><?= $downpayment_percentage ?>% - ₱<?= number_format($downpayment_amount, 2) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($stage === 'Site Visit'): ?>
                        <div style="margin-top:14px;">
                            <div style="font-size:12px; color:#718096; margin-bottom:8px; font-weight:600;">
                                <i class="fas fa-users"></i> Assigned Designers:
                            </div>
                            <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                <?php if ($designer1_name): ?>
                                <span class="assigned-badge">
                                    <i class="fas fa-user-tie"></i>
                                    <?= htmlspecialchars($designer1_name) ?>
                                    <em style="font-size:10px; opacity:0.7; font-style:normal;">(Lead)</em>
                                </span>
                                <?php if ($designer2_name): ?>
                                <span class="assigned-badge">
                                    <i class="fas fa-user-tie"></i>
                                    <?= htmlspecialchars($designer2_name) ?>
                                    <em style="font-size:10px; opacity:0.7; font-style:normal;">(Support)</em>
                                </span>
                                <?php endif; ?>
                                <?php else: ?>
                                <span style="color:#9ca3af; font-size:12px; font-style:italic;">
                                    No designer assigned yet
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($assigned_people) && !empty($assigned_people)): ?>
                            <div style="margin-top: 15px;">
                                <div style="font-size: 12px; color: #718096; margin-bottom: 8px; font-weight: 600;">
                                    <i class="fas fa-users"></i> Assigned Personnel:
                                </div>
                                <div class="assigned-people">
                                    <?php foreach ($assigned_people as $person): ?>
                                        <span class="assigned-badge">
                                            <i class="fas fa-user"></i>
                                            <?= htmlspecialchars($person) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php if (isset($isApprovalStage) && $isApprovalStage && $stageData): ?>
                    <div class="approval-section" id="approval-section-<?= $stageData['id'] ?>">
                        <div style="font-size: 13px; font-weight: 700; color: #3b1f0f; margin-bottom: 12px;">
                            <i class="fas fa-file-upload"></i> File Submissions & Approvals
                            <?php
                            $pendingCount = count(array_filter($stageApprovalFiles, fn($f) => $f['approval_status'] === 'pending'));
                            ?>
                            <?php if ($pendingCount > 0): ?>
                            <span style="margin-left: 8px; background: #f59e0b; color: white; padding: 2px 10px; border-radius: 10px; font-size: 11px;">
                                <?= $pendingCount ?> pending
                            </span>
                            <?php else: ?>
                            <span style="margin-left: 8px; background: #10b981; color: white; padding: 2px 10px; border-radius: 10px; font-size: 11px;">
                                All reviewed
                            </span>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($stageApprovalFiles)): ?>
                        <div class="no-files-yet">
                            <i class="fas fa-file" style="display:block; font-size:22px; margin-bottom:6px; color:#cbd5e0;"></i>
                            No files submitted yet for this stage.
                        </div>
                        <?php else: ?>
                        <div class="approval-files-list">
                            <?php foreach ($stageApprovalFiles as $af):
                                $ext = strtolower(pathinfo($af['file_name'], PATHINFO_EXTENSION));
                                $fileIconMap = [
                                    'pdf'  => ['icon'=>'fa-file-pdf',        'color'=>'#ef4444'],
                                    'doc'  => ['icon'=>'fa-file-word',       'color'=>'#3b82f6'],
                                    'docx' => ['icon'=>'fa-file-word',       'color'=>'#3b82f6'],
                                    'xls'  => ['icon'=>'fa-file-excel',      'color'=>'#10b981'],
                                    'xlsx' => ['icon'=>'fa-file-excel',      'color'=>'#10b981'],
                                    'ppt'  => ['icon'=>'fa-file-powerpoint', 'color'=>'#f59e0b'],
                                    'pptx' => ['icon'=>'fa-file-powerpoint', 'color'=>'#f59e0b'],
                                    'png'  => ['icon'=>'fa-file-image',      'color'=>'#8b5cf6'],
                                    'jpg'  => ['icon'=>'fa-file-image',      'color'=>'#8b5cf6'],
                                    'jpeg' => ['icon'=>'fa-file-image',      'color'=>'#8b5cf6'],
                                    'txt'  => ['icon'=>'fa-file-alt',        'color'=>'#6b7280'],
                                    'csv'  => ['icon'=>'fa-file-csv',        'color'=>'#6b7280'],
                                ];
                                $fi = $fileIconMap[$ext] ?? ['icon'=>'fa-file','color'=>'#6b7280'];
                                $afStatus = $af['approval_status'];
                            ?>
                            <div class="approval-file-card status-<?= $afStatus ?>" id="af-card-<?= $af['id'] ?>">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
                                    <div style="display:flex; align-items:flex-start; gap:12px; flex:1;">
                                        <i class="fas <?= $fi['icon'] ?>" style="font-size:26px; color:<?= $fi['color'] ?>; flex-shrink:0; margin-top:2px;"></i>
                                        <div style="flex:1;">
                                            <?php if ($af['label']): ?>
                                            <div class="file-label-tag"><?= htmlspecialchars($af['label']) ?></div>
                                            <?php endif; ?>
                                            <div style="font-size:14px; font-weight:700; color:#1a202c; margin-top:2px;">
                                                <?= htmlspecialchars($af['file_name']) ?>
                                            </div>
                                            <div style="font-size:12px; color:#718096; margin-top:3px;">
                                                Uploaded by <strong><?= htmlspecialchars($af['uploaded_by_name']) ?></strong>
                                                &nbsp;•&nbsp; <?= date('M d, Y g:i A', strtotime($af['uploaded_at'])) ?>
                                                &nbsp;•&nbsp; <?= number_format($af['file_size'] / 1024, 1) ?> KB
                                            </div>
                                            <?php 
$requiredForStage = $requiredApproversList[$stage] ?? [];
echo buildRoleApprovalBadges($af, $requiredForStage, $userInfo['role'], $admin_id, false, $stage);

// Show all rejection notes
if (!empty($af['role_reviews'])) {
    foreach ($af['role_reviews'] as $roleKey => $rev) {
        if ($rev['review_status'] === 'rejected' && $rev['review_note']) {
            echo '<div class="file-note-box rejected">
                <i class="fas fa-comment-alt"></i>
                <div><strong>' . getRoleDisplayName($roleKey) . ':</strong> ' 
                . htmlspecialchars($rev['review_note']) 
                . ($rev['reviewer_name'] ? ' — <em>' . htmlspecialchars($rev['reviewer_name']) . '</em>' : '')
                . '</div>
            </div>';
        }
    }
}
?>

                                            <!-- Reject inline form -->
                                            <div class="reject-inline-form" id="reject-form-<?= $af['id'] ?>">
                                                <textarea id="reject-note-<?= $af['id'] ?>" 
                                                          placeholder="Enter rejection reason (required)..."></textarea>
                                                <div style="display:flex; gap:8px; justify-content:flex-end;">
                                                    <button onclick="cancelReject(<?= $af['id'] ?>)"
                                                            style="background:#6b7280; color:white; padding:5px 14px; border:none; border-radius:6px; cursor:pointer; font-size:12px; font-weight:700;">
                                                        Cancel
                                                    </button>
                                                    <button onclick="confirmReject(<?= $af['id'] ?>, <?= $stageData['id'] ?>)"
                                                            style="background:#ef4444; color:white; padding:5px 14px; border:none; border-radius:6px; cursor:pointer; font-size:12px; font-weight:700;">
                                                        <i class="fas fa-times"></i> Confirm Reject
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div style="display:flex; flex-direction:column; align-items:flex-end; gap:8px; flex-shrink:0;">
                                        <span class="approval-status-pill <?= $afStatus ?>">
                                            <i class="fas <?= $afStatus==='pending' ? 'fa-clock' : ($afStatus==='approved' ? 'fa-check' : 'fa-times') ?>"></i>
                                            <?= ucfirst($afStatus) ?>
                                        </span>
                                        <a href="../../<?= htmlspecialchars($af['file_path']) ?>" 
                                           target="_blank" class="btn-view-file">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <?php 
$myRoleReview = $af['role_reviews'][$userInfo['role']] ?? null;
$myReviewDone = ($myRoleReview !== null);
if ($canApproveThis && !$myReviewDone): ?>
<button class="btn-approve-action"
        onclick="approveFileDetail(<?= $af['id'] ?>, <?= $stageData['id'] ?>)">
    <i class="fas fa-check"></i> Approve
</button>
<button class="btn-reject-action"
        onclick="showRejectForm(<?= $af['id'] ?>)">
    <i class="fas fa-times"></i> Reject
</button>
<?php elseif ($canApproveThis && $myReviewDone): ?>
<span style="font-size:11px; color:#059669; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
    <i class="fas fa-check-double"></i> You reviewed this
</span>
<?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Payment Schedule -->
        <div class="payment-section">
            <div class="payment-header">
                <h2>
                    <i class="fas fa-money-check-alt"></i>
                    Payment Schedule
                </h2>
                <p style="color: #718096; font-size: 14px;">Overview of all payment milestones</p>
            </div>

            <?php
            $payments->data_seek(0);
            while ($payment = $payments->fetch_assoc()):
                $status = strtolower(str_replace(' ', '-', $payment['status']));
            ?>
                <div class="payment-item status-<?= $status ?>">
                    <div class="payment-item-header">
                        <div class="payment-type">
                            <?= htmlspecialchars($payment['payment_type']) ?>
                            <span style="font-size: 13px; color: #718096; font-weight: normal;">
                                (<?= number_format($payment['percentage'], 1) ?>%)
                            </span>
                        </div>
                        <div class="payment-amount">
                            ₱<?= number_format($payment['amount'], 2) ?>
                        </div>
                    </div>

                    <div class="payment-meta">
                        <span class="status-badge <?= $status ?>">
                            <?= htmlspecialchars($payment['status']) ?>
                        </span>
                        <?php if ($payment['payment_date']): ?>
                            <span>
                                <i class="fas fa-check-circle" style="color: #10b981;"></i>
                                Paid on: <?= date('M d, Y g:i A', strtotime($payment['payment_date'])) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <div id="toastDetail" class="toast-detail">
    <span id="toastDetailMsg"></span>
</div>

<script>
function showToastDetail(message, type) {
    const toast = document.getElementById('toastDetail');
    document.getElementById('toastDetailMsg').textContent = message;
    toast.className = 'toast-detail show ' + type;
    setTimeout(() => toast.classList.remove('show'), 3500);
}

function showRejectForm(fileId) {
    document.getElementById('reject-form-' + fileId).style.display = 'block';
}

function cancelReject(fileId) {
    document.getElementById('reject-form-' + fileId).style.display = 'none';
    document.getElementById('reject-note-' + fileId).value = '';
}

async function approveFileDetail(approvalId, stageId) {
    if (!confirm('Approve this file?')) return;
    await submitAction(approvalId, 'approved', '');
}

async function confirmReject(approvalId, stageId) {
    const note = document.getElementById('reject-note-' + approvalId).value.trim();
    if (!note) {
        showToastDetail('Please enter a rejection reason.', 'error');
        return;
    }
    await submitAction(approvalId, 'rejected', note);
}

async function submitAction(approvalId, action, note) {
    try {
        const response = await fetch('../tracker_step/approve_reject_stage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ approval_id: approvalId, action: action, note: note })
        });
        const result = await response.json();

        if (result.success) {
            const msg = action === 'approved'
                ? 'File approved!' + (result.new_stage_status === 'Done' ? ' Stage marked as Done!' : '')
                : 'File rejected successfully.';
            showToastDetail(msg, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToastDetail('Error: ' + (result.error || 'Action failed'), 'error');
        }
    } catch (err) {
        showToastDetail('An error occurred.', 'error');
    }
}
</script>
</body>
</html>