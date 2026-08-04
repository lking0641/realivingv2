<?php
// manager_site_visit_approval.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

$roleStmt = $conn->prepare("SELECT role, full_name FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();

if (!in_array($userInfo['role'], ['general_manager', 'operational_manager', 'superadmin'])) {
    die("Access Denied.");
}

$success = isset($_GET['success']) ? $_GET['success'] : '';
$error = isset($_GET['error']) ? $_GET['error'] : '';

// Handle approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $visit_id = intval($_POST['visit_id']);

    if ($_POST['action'] === 'approve') {
        $apStmt = $conn->prepare("
            UPDATE site_visit 
            SET approval_status = 'Approved', approved_by = ?, approved_at = NOW(), approval_comment = NULL
            WHERE id = ? AND client_id = ?
        ");
        $apStmt->bind_param("iii", $admin_id, $visit_id, $client_id);
        $apStmt->execute();
        $success = "Visit approved successfully!";

    } elseif ($_POST['action'] === 'reject') {
        $comment = trim($_POST['comment'] ?? '');
        if (empty($comment)) {
            $error = "Please provide a reason for rejection.";
        } else {
            $rejStmt = $conn->prepare("
                UPDATE site_visit 
                SET approval_status = 'Rejected', approved_by = ?, approved_at = NOW(), approval_comment = ?
                WHERE id = ? AND client_id = ?
            ");
            $rejStmt->bind_param("isii", $admin_id, $comment, $visit_id, $client_id);
            $rejStmt->execute();
            $success = "Visit rejected with comment.";
        }
    }

    // PRG — prevent resubmission on back/reload
    $redirect_url = "manager-site-visit-approval?client_id={$client_id}";
    if ($success)
        $redirect_url .= "&success=" . urlencode($success);
    if ($error)
        $redirect_url .= "&error=" . urlencode($error);
    header("Location: " . $redirect_url);
    exit();
}

// Fetch client
$clientStmt = $conn->prepare("SELECT clientname, nameproject, reference_number, status, business_type, contact, email, address, gender, client_class, client_type, project_scope, scope_of_work, house_state, permit_required, target_movein_date, total_project_cost, remaining_balance FROM user_info WHERE id = ?");
$clientStmt->bind_param("i", $client_id);
$clientStmt->execute();
$client = $clientStmt->get_result()->fetch_assoc();
if (!$client)
    die("Client not found.");

// Fetch all visits for this client
$visitsStmt = $conn->prepare("
    SELECT sv.*,
           a1.full_name as designer1_name,
           a2.full_name as designer2_name,
           ab.full_name as approved_by_name,
           orig1.full_name as original_designer1_name,
           orig2.full_name as original_designer2_name
    FROM site_visit sv
    LEFT JOIN account a1    ON sv.designer1_id          = a1.id
    LEFT JOIN account a2    ON sv.designer2_id          = a2.id
    LEFT JOIN account ab    ON sv.approved_by           = ab.id
    LEFT JOIN account orig1 ON sv.original_designer1_id = orig1.id
    LEFT JOIN account orig2 ON sv.original_designer2_id = orig2.id
    WHERE sv.client_id = ?
    ORDER BY sv.visit_date ASC
");
$visitsStmt->bind_param("i", $client_id);
$visitsStmt->execute();
$allVisits = $visitsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Visit Approval</title>
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
            max-width: 900px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
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

        .visit-card {
            background: white;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 16px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.07);
            border-left: 5px solid #e9ecef;
        }

        .visit-card.pending {
            border-left-color: #f59e0b;
        }

        .visit-card.approved {
            border-left-color: #10b981;
        }

        .visit-card.rejected {
            border-left-color: #ef4444;
        }

        .visit-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 14px;
        }

        .visit-title {
            font-size: 15px;
            font-weight: 700;
            color: #1f2937;
        }

        .visit-sub {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 3px;
        }

        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .visit-info {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            font-size: 13px;
            color: #374151;
            margin-bottom: 14px;
        }

        .visit-info span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .designer-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: #f9f9f9;
            border-radius: 8px;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .report-box {
            background: #f0fdf4;
            border-left: 3px solid #10b981;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .report-box strong {
            color: #065f46;
            display: block;
            margin-bottom: 4px;
        }

        .action-area {
            border-top: 2px solid #f3f4f6;
            padding-top: 14px;
            margin-top: 14px;
        }

        .btn-approve {
            background: #10b981;
            color: white;
            padding: 9px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-reject {
            background: #fee2e2;
            color: #991b1b;
            padding: 9px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .reject-form {
            margin-top: 12px;
            display: none;
        }

        .reject-form textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #fca5a5;
            border-radius: 8px;
            font-size: 13px;
            resize: vertical;
            min-height: 80px;
            font-family: inherit;
            margin-bottom: 8px;
        }

        .empty-state {
            text-align: center;
            padding: 50px;
            color: #9ca3af;
            background: white;
            border-radius: 12px;
        }

        .empty-state i {
            font-size: 40px;
            display: block;
            margin-bottom: 12px;
        }
    </style>
</head>

<body>
    <div class="container">
        <a href="manager-project-detail?client_id=<?= $client_id ?>" class="btn-back"
            style="display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:600; color:#3b1f0f; text-decoration:none; margin-bottom:16px; background:#ecddd0; padding:8px 16px; border-radius:8px; border:1px solid #c49a78;">
            <i class="fas fa-arrow-left"></i> Back to Project
        </a>

        <div class="page-header">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap;">
                <div>
                    <h1><i class="fas fa-clipboard-check"></i> Site Visit Approval</h1>
                    <div class="sub">
                        <?= htmlspecialchars($client['clientname']) ?> — <?= htmlspecialchars($client['nameproject']) ?>
                        &nbsp;•&nbsp; Ref: <?= htmlspecialchars($client['reference_number']) ?>
                    </div>
                    <div class="sub" style="margin-top:6px;">
                        Reviewing as: <strong><?= htmlspecialchars($userInfo['full_name']) ?></strong>
                        (<?= ucwords(str_replace('_', ' ', $userInfo['role'])) ?>)
                    </div>
                </div>
                <button onclick="document.getElementById('clientDetailModal').classList.add('open')" style="background:white; color:#1e3a5f; padding:9px 18px; border:none; border-radius:8px;
                       cursor:pointer; font-weight:700; font-size:13px; display:inline-flex;
                       align-items:center; gap:7px; transition:all 0.2s; flex-shrink:0; align-self:flex-start;">
                    <i class="fas fa-info-circle"></i> View Details
                </button>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><?= $success ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= $error ?></div>
        <?php endif; ?>

        <?php if (empty($allVisits)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <p style="font-size:15px; font-weight:600;">No site visits scheduled yet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($allVisits as $vi => $visit):
                $approvalClass = strtolower($visit['approval_status']);
                ?>
                <div class="visit-card <?= $approvalClass ?>">
                    <div class="visit-header">
                        <div>
                            <div class="visit-title">Visit #<?= $vi + 1 ?></div>
                            <div class="visit-sub">
                                <?= date('F d, Y', strtotime($visit['visit_date'])) ?>
                                <?php if (!empty($visit['visit_time'])): ?>
                                    &nbsp;<i class="fas fa-clock" style="color:#2563eb;"></i>
                                    <?= date('g:i A', strtotime($visit['visit_time'])) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                            <span class="badge badge-<?= $approvalClass ?>"><?= $visit['approval_status'] ?></span>
                            <span class="badge"
                                style="background:<?= $visit['status'] === 'Done' ? '#d1fae5' : ($visit['status'] === 'Ongoing' ? '#dbeafe' : '#fef3c7') ?>; color:<?= $visit['status'] === 'Done' ? '#065f46' : ($visit['status'] === 'Ongoing' ? '#1e40af' : '#92400e') ?>">
                                <?= $visit['status'] ?>
                            </span>
                        </div>
                    </div>

                    <div class="visit-info">
                        <?php if ($visit['visit_type'] === 'Paid'): ?>
                            <span style="color:#f59e0b; font-weight:700;">
                                <i class="fas fa-money-bill-wave"></i> Paid — ₱<?= number_format($visit['visit_amount'], 2) ?>
                            </span>
                        <?php else: ?>
                            <span style="color:#6b7280;"><i class="fas fa-gift"></i> Free</span>
                        <?php endif; ?>
                        <?php if ($visit['is_due']): ?>
                            <span style="color:#ef4444; font-weight:700;"><i class="fas fa-exclamation-circle"></i> DUE</span>
                        <?php endif; ?>
                    </div>

                    <!-- Designer 1 -->
                    <div class="designer-row">
                        <i class="fas fa-user-tie" style="color:#8a5a44;"></i>
                        <span style="font-weight:600;"><?= htmlspecialchars($visit['designer1_name']) ?></span>
                        <em style="font-size:11px; color:#9ca3af;">(Designer 1)</em>
                        <?php if ($visit['designer1_absent']): ?>
                            <div style="margin-left:auto; text-align:right;">
                                <span
                                    style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:8px; font-size:11px; font-weight:700;">
                                    <i class="fas fa-user-slash"></i> Absent
                                </span>
                                <?php if ($visit['original_designer1_name']): ?>
                                    <div
                                        style="font-size:11px; color:#991b1b; margin-top:4px; display:flex; align-items:center; gap:4px; justify-content:flex-end;">
                                        <i class="fas fa-user"></i>
                                        Originally: <strong><?= htmlspecialchars($visit['original_designer1_name']) ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if ($visit['designer1_absent_reason']): ?>
                                    <div style="font-size:11px; color:#991b1b; margin-top:3px; font-style:italic;">
                                        Reason: "<?= htmlspecialchars($visit['designer1_absent_reason']) ?>"
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($visit['designer1_finished']): ?>
                            <span
                                style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:8px; font-size:11px; font-weight:700; margin-left:auto;">
                                <i class="fas fa-check"></i> Finished
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($visit['designer1_report'] || $visit['designer1_finished'] || !empty($visit['designer1_photo'])): ?>
                        <div class="report-box">
                            <strong>
                                <i class="fas fa-file-alt"></i> <?= htmlspecialchars($visit['designer1_name']) ?>'s Report
                                <?php if ($visit['designer1_finished']): ?>
                                    <span
                                        style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700; float:right;">
                                        Finished
                                    </span>
                                <?php elseif (!empty($visit['designer1_photo'])): ?>
                                    <span
                                        style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700; float:right;">
                                        <i class="fas fa-camera"></i> Photo Uploaded
                                    </span>
                                <?php endif; ?>
                            </strong>
                            <?php if ($visit['designer1_finished'] && $visit['designer1_finished_at']): ?>
                                <div
                                    style="font-size:11px; color:#6b7280; margin:5px 0 8px 0; display:flex; align-items:center; gap:5px;">
                                    <i class="fas fa-clock" style="color:#8a5a44;"></i>
                                    Submitted: <?= date('F d, Y g:i A', strtotime($visit['designer1_finished_at'])) ?>
                                    <?php
                                    $d1Date = date('Y-m-d', strtotime($visit['designer1_finished_at']));
$visitDate = $visit['visit_date'];
$visitDeadline = date('Y-m-d', strtotime($visitDate . ' +2 days'));
if ($d1Date > $visitDeadline): ?>
    <span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700;">
        <i class="fas fa-exclamation-circle"></i> Late
    </span>
<?php elseif ($d1Date <= $visitDate): ?>
    <span style="background:#dbeafe; color:#1e40af; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700;">
        <i class="fas fa-star"></i> Early
    </span>
<?php else: ?>
    <span style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700;">
        <i class="fas fa-check"></i> On Time
    </span>
<?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($visit['designer1_photo'])): ?>
                                <div style="margin-bottom:10px; margin-top:8px;">
                                    <div
                                        style="font-size:11px; font-weight:700; color:#065f46; margin-bottom:5px; display:flex; align-items:center; gap:5px;">
                                        <i class="fas fa-camera"></i> Proof Photo
                                    </div>
                                    <img src="<?= BASE_URL ?>uploads/site_visit_photos/<?= htmlspecialchars($visit['designer1_photo']) ?>"
                                        alt="Proof"
                                        style="max-width:100%; max-height:240px; border-radius:8px; border:2px solid #bbf7d0; object-fit:cover; display:block; cursor:pointer;"
                                        onclick="openPhotoModal(this.src)">
                                </div>
                            <?php endif; ?>
                            <?php if ($visit['designer1_report']): ?>
                                <?= nl2br(htmlspecialchars($visit['designer1_report'])) ?>
                            <?php elseif (!$visit['designer1_finished']): ?>
                                <em style="color:#9ca3af; font-size:12px;">
                                    <i class="fas fa-hourglass-half"></i> Report not submitted yet.
                                </em>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Designer 2 -->
                    <?php if ($visit['designer2_name']): ?>
                        <div class="designer-row">
                            <i class="fas fa-user-tie" style="color:#8a5a44;"></i>
                            <span style="font-weight:600;"><?= htmlspecialchars($visit['designer2_name']) ?></span>
                            <em style="font-size:11px; color:#9ca3af;">(Designer 2)</em>
                            <?php if ($visit['designer2_absent']): ?>
                                <div style="margin-left:auto; text-align:right;">
                                    <span
                                        style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:8px; font-size:11px; font-weight:700;">
                                        <i class="fas fa-user-slash"></i> Absent
                                    </span>
                                    <?php if ($visit['original_designer2_name']): ?>
                                        <div
                                            style="font-size:11px; color:#991b1b; margin-top:4px; display:flex; align-items:center; gap:4px; justify-content:flex-end;">
                                            <i class="fas fa-user"></i>
                                            Originally: <strong><?= htmlspecialchars($visit['original_designer2_name']) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($visit['designer2_absent_reason']): ?>
                                        <div style="font-size:11px; color:#991b1b; margin-top:3px; font-style:italic;">
                                            Reason: "<?= htmlspecialchars($visit['designer2_absent_reason']) ?>"
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($visit['designer2_finished']): ?>
                                <span
                                    style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:8px; font-size:11px; font-weight:700; margin-left:auto;">
                                    <i class="fas fa-check"></i> Finished
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($visit['designer2_name'] && ($visit['designer2_report'] || $visit['designer2_finished'] || !empty($visit['designer2_photo']))): ?>
                            <div class="report-box">
                                <strong>
                                    <i class="fas fa-file-alt"></i> <?= htmlspecialchars($visit['designer2_name']) ?>'s Report
                                    <?php if ($visit['designer2_finished']): ?>
                                        <span
                                            style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700; float:right;">
                                            Finished
                                        </span>
                                    <?php elseif (!empty($visit['designer2_photo'])): ?>
                                        <span
                                            style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700; float:right;">
                                            <i class="fas fa-camera"></i> Photo Uploaded
                                        </span>
                                    <?php endif; ?>
                                </strong>
                                <?php if ($visit['designer2_finished'] && $visit['designer2_finished_at']): ?>
                                    <div
                                        style="font-size:11px; color:#6b7280; margin:5px 0 8px 0; display:flex; align-items:center; gap:5px;">
                                        <i class="fas fa-clock" style="color:#8a5a44;"></i>
                                        Submitted: <?= date('F d, Y g:i A', strtotime($visit['designer2_finished_at'])) ?>
                                        <?php
                                        $d2Date = date('Y-m-d', strtotime($visit['designer2_finished_at']));
if ($d2Date > $visitDeadline): ?>
    <span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700;">
        <i class="fas fa-exclamation-circle"></i> Late
    </span>
<?php elseif ($d2Date <= $visitDate): ?>
    <span style="background:#dbeafe; color:#1e40af; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700;">
        <i class="fas fa-star"></i> Early
    </span>
<?php else: ?>
    <span style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700;">
        <i class="fas fa-check"></i> On Time
    </span>
<?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($visit['designer2_photo'])): ?>
                                    <div style="margin-bottom:10px; margin-top:8px;">
                                        <div
                                            style="font-size:11px; font-weight:700; color:#065f46; margin-bottom:5px; display:flex; align-items:center; gap:5px;">
                                            <i class="fas fa-camera"></i> Proof Photo
                                        </div>
                                        <img src="<?= BASE_URL ?>uploads/site_visit_photos/<?= htmlspecialchars($visit['designer2_photo']) ?>"
                                            alt="Proof"
                                            style="max-width:100%; max-height:240px; border-radius:8px; border:2px solid #bbf7d0; object-fit:cover; display:block; cursor:pointer;"
                                            onclick="openPhotoModal(this.src)">
                                    </div>
                                <?php endif; ?>
                                <?php if ($visit['designer2_report']): ?>
                                    <?= nl2br(htmlspecialchars($visit['designer2_report'])) ?>
                                <?php elseif (!$visit['designer2_finished']): ?>
                                    <em style="color:#9ca3af; font-size:12px;">
                                        <i class="fas fa-hourglass-half"></i> Report not submitted yet.
                                    </em>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($visit['notes']): ?>
                        <div
                            style="background:#fffbeb; border-radius:8px; padding:10px; font-size:12px; color:#92400e; margin-top:8px;">
                            <i class="fas fa-sticky-note"></i> <strong>Notes:</strong> <?= htmlspecialchars($visit['notes']) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Rejection comment display -->
                    <?php if ($visit['approval_status'] === 'Rejected' && $visit['approval_comment']): ?>
                        <div
                            style="background:#fee2e2; border-radius:8px; padding:10px; font-size:12px; color:#991b1b; margin-top:10px;">
                            <i class="fas fa-comment-slash"></i> <strong>Rejection Reason:</strong>
                            <?= htmlspecialchars($visit['approval_comment']) ?>
                            <div style="font-size:11px; margin-top:4px; opacity:0.8;">
                                by <?= htmlspecialchars($visit['approved_by_name'] ?? '') ?>
                                <?= $visit['approved_at'] ? '• ' . date('M d, Y g:i A', strtotime($visit['approved_at'])) : '' ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Action Buttons — only for Pending visits -->
                    <?php if ($visit['approval_status'] === 'Pending'): ?>
                        <div class="action-area">
                            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                    <button type="submit" class="btn-approve" onclick="return confirm('Approve this site visit?')">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                </form>
                                <button type="button" class="btn-reject" onclick="toggleRejectForm(<?= $visit['id'] ?>)">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                            <div class="reject-form" id="rejectForm-<?= $visit['id'] ?>">
                                <form method="POST">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                    <textarea name="comment" placeholder="Please provide a reason for rejection..."
                                        required></textarea>
                                    <div style="display:flex; gap:8px;">
                                        <button type="submit"
                                            style="background:#ef4444; color:white; padding:8px 16px; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:13px;">
                                            <i class="fas fa-paper-plane"></i> Submit Rejection
                                        </button>
                                        <button type="button" onclick="toggleRejectForm(<?= $visit['id'] ?>)"
                                            style="background:#f9f9f9; color:#6b7280; padding:8px 16px; border:2px solid #e9ecef; border-radius:8px; font-weight:600; cursor:pointer; font-size:13px;">
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php elseif ($visit['approval_status'] === 'Approved'): ?>
                        <div style="margin-top:12px; font-size:12px; color:#065f46; display:flex; align-items:center; gap:6px;">
                            <i class="fas fa-check-circle"></i>
                            Approved by <?= htmlspecialchars($visit['approved_by_name'] ?? '') ?>
                            <?= $visit['approved_at'] ? '• ' . date('M d, Y g:i A', strtotime($visit['approved_at'])) : '' ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Client Detail Modal -->
    <?php
    $business_type_label = ($client['business_type'] ?? '') === 'Non-Project' ? 'Individual' : ($client['business_type'] ?? '');
    $house_state = $client['house_state'] ?? '';
    $permit_required = $client['permit_required'] ?? '';
    $target_movein_date = $client['target_movein_date'] ?? '';
    ?>
    <style>
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: white;
            border-radius: 14px;
            padding: 28px;
            max-width: 580px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
            animation: modalIn .2s ease;
        }

        @keyframes modalIn {
            from {
                transform: scale(0.95);
                opacity: 0
            }

            to {
                transform: scale(1);
                opacity: 1
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 2px solid #f3f4f6;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 700;
            color: #1e3a5f;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-close {
            font-size: 20px;
            color: #9ca3af;
            background: none;
            border: none;
            cursor: pointer;
            line-height: 1;
            padding: 4px;
        }

        .modal-close:hover {
            color: #374151;
        }

        .modal-row {
            display: grid;
            grid-template-columns: 160px 1fr;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
            align-items: start;
            gap: 10px;
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        .modal-row-label {
            font-weight: 600;
            color: #6b7280;
            font-size: 13px;
        }

        .modal-row-value {
            color: #111;
            font-size: 13px;
        }
    </style>
    <div id="clientDetailModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('open')">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-user-circle" style="color:#2563eb;"></i> Client Details</div>
                <button class="modal-close"
                    onclick="document.getElementById('clientDetailModal').classList.remove('open')"><i
                        class="fas fa-times"></i></button>
            </div>
            <div class="modal-row">
                <div class="modal-row-label">Reference Number</div>
                <div class="modal-row-value" style="color:#3b82f6; font-family:monospace; font-weight:600;">
                    <?= htmlspecialchars($client['reference_number'] ?? '') ?></div>
            </div>
            <div class="modal-row">
                <div class="modal-row-label">Client Name</div>
                <div class="modal-row-value"><?= htmlspecialchars($client['clientname']) ?></div>
            </div>
            <div class="modal-row">
                <div class="modal-row-label">Project Name</div>
                <div class="modal-row-value"><?= htmlspecialchars($client['nameproject']) ?></div>
            </div>
            <div class="modal-row">
                <div class="modal-row-label">Status</div>
                <div class="modal-row-value">
                    <?php $st = $client['status'] ?? ''; ?>
                    <span style="padding:3px 12px; border-radius:12px; font-size:11px; font-weight:700;
                    background:<?= strtolower($st) === 'new client' ? '#fef3c7' : '#dbeafe' ?>;
                    color:<?= strtolower($st) === 'new client' ? '#92400e' : '#1e40af' ?>;">
                        <?= htmlspecialchars($st) ?>
                    </span>
                </div>
            </div>
            <div class="modal-row">
                <div class="modal-row-label">Business Type</div>
                <div class="modal-row-value"><?= htmlspecialchars($business_type_label) ?></div>
            </div>
            <?php if (!empty($client['contact'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Phone</div>
                    <div class="modal-row-value"><?= htmlspecialchars($client['contact']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['email'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Email</div>
                    <div class="modal-row-value"><?= htmlspecialchars($client['email']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['address'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Address</div>
                    <div class="modal-row-value"><?= htmlspecialchars($client['address']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['gender'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Gender</div>
                    <div class="modal-row-value"><?= htmlspecialchars($client['gender']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['client_class'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Classification</div>
                    <div class="modal-row-value"><?= htmlspecialchars($client['client_class']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['client_type'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Client Type</div>
                    <div class="modal-row-value"><?= htmlspecialchars($client['client_type']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['project_scope'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Project Scope</div>
                    <div class="modal-row-value"><?= nl2br(htmlspecialchars($client['project_scope'])) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['scope_of_work'])): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Scope of Work</div>
                    <div class="modal-row-value"><?= nl2br(htmlspecialchars($client['scope_of_work'])) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($house_state): ?>
                <div class="modal-row">
                    <div class="modal-row-label">House State</div>
                    <div class="modal-row-value">
                        <?php
                        $hsBg = '#fef3c7';
                        $hsColor = '#92400e';
                        if ($house_state === 'Bare/Empty Lot') {
                            $hsBg = '#dbeafe';
                            $hsColor = '#1e40af';
                        } elseif ($house_state === 'Construction Started') {
                            $hsBg = '#fee2e2';
                            $hsColor = '#991b1b';
                        } elseif ($house_state === 'Renovation') {
                            $hsBg = '#ede9fe';
                            $hsColor = '#5b21b6';
                        }
                        ?>
                        <span
                            style="padding:3px 12px; border-radius:12px; font-size:12px; font-weight:700; background:<?= $hsBg ?>; color:<?= $hsColor ?>;"><?= htmlspecialchars($house_state) ?></span>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($permit_required): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Permit Required</div>
                    <div class="modal-row-value">
                        <?php
                        $prBg = '#fef3c7';
                        $prColor = '#92400e';
                        if ($permit_required === 'Yes') {
                            $prBg = '#fee2e2';
                            $prColor = '#991b1b';
                        } elseif ($permit_required === 'No') {
                            $prBg = '#d1fae5';
                            $prColor = '#065f46';
                        }
                        ?>
                        <span
                            style="padding:3px 12px; border-radius:12px; font-size:12px; font-weight:700; background:<?= $prBg ?>; color:<?= $prColor ?>;"><?= htmlspecialchars($permit_required) ?></span>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($target_movein_date): ?>
                <div class="modal-row">
                    <div class="modal-row-label">Target Move-in</div>
                    <div class="modal-row-value" style="font-weight:600;">
                        <i class="fas fa-calendar-check" style="color:#10b981;"></i>
                        <?= date('F d, Y', strtotime($target_movein_date)) ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="modal-row">
                <div class="modal-row-label">Total Project Cost</div>
                <div class="modal-row-value" style="font-weight:700; color:#1e3a5f; font-size:15px;">
                    ₱<?= number_format($client['total_project_cost'] ?? 0, 2) ?></div>
            </div>
            <div class="modal-row">
                <div class="modal-row-label">Remaining Balance</div>
                <div class="modal-row-value" style="font-weight:700; color:#dc2626; font-size:15px;">
                    ₱<?= number_format($client['remaining_balance'] ?? 0, 2) ?></div>
            </div>
        </div>
    </div>

    <script>
        function toggleRejectForm(visitId) {
            const form = document.getElementById('rejectForm-' + visitId);
            form.style.display = form.style.display === 'none' || form.style.display === '' ? 'block' : 'none';
        }
        function openPhotoModal(src) {
            document.getElementById('photoModalImg').src = src;
            document.getElementById('photoModal').style.display = 'flex';
        }
        function closePhotoModal() {
            document.getElementById('photoModal').style.display = 'none';
        }
    </script>

    <!-- Photo Lightbox -->
    <div id="photoModal" onclick="closePhotoModal()" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%;
            background:rgba(0,0,0,0.88); align-items:center; justify-content:center; cursor:zoom-out;">
        <img id="photoModalImg" src="" alt="Proof Photo" style="max-width:92vw; max-height:92vh; border-radius:10px; object-fit:contain;
                box-shadow:0 10px 40px rgba(0,0,0,0.5);">
    </div>
</body>

</html>