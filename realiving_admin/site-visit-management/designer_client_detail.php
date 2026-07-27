<?php
// designer_client_detail.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

if (!$client_id) {
    header("Location: " . BASE_URL . "designer-clients-list");
    exit();
}

// Verify designer
$meStmt = $conn->prepare("SELECT full_name, role FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

if ($me['role'] !== 'designer') {
    die("Access denied: This page is for designers only.");
}

// Fetch full client info
$clientStmt = $conn->prepare("
    SELECT ui.*
    FROM user_info ui
    JOIN site_visit sv ON sv.client_id = ui.id
    WHERE ui.id = ? AND (sv.designer1_id = ? OR sv.designer2_id = ?)
    LIMIT 1
");
$clientStmt->bind_param("iii", $client_id, $admin_id, $admin_id);
$clientStmt->execute();
$client = $clientStmt->get_result()->fetch_assoc();

if (!$client) {
    die("Client not found or you are not assigned to this client.");
}

// Display-friendly business type label
$business_type_label = ($client['business_type'] ?? '') === 'Non-Project' ? 'Individual' : ($client['business_type'] ?? '');

// Handle report form submission
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $visit_id = intval($_POST['visit_id']);
    $which = $_POST['which'];

    // ── Handle photo upload (separate action) ──
    if ($_POST['action'] === 'upload_proof_photo') {
        if (!in_array($which, ['designer1', 'designer2'])) {
            $error = "Invalid designer role.";
        } elseif (!isset($_FILES['proof_photo']) || $_FILES['proof_photo']['error'] !== UPLOAD_ERR_OK) {
            $error = "Please select a valid photo to upload.";
        } else {
            $tmp = $_FILES['proof_photo']['tmp_name'];
            $mime = mime_content_type($tmp);
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($mime, $allowed)) {
                $error = "Invalid image format. Please upload JPG, PNG, GIF, or WebP.";
            } else {
                $upload_dir = ROOT_PATH . 'uploads/site_visit_photos/';
                if (!is_dir($upload_dir))
                    mkdir($upload_dir, 0755, true);

                // Convert to WebP
                $src_image = null;
                if ($mime === 'image/jpeg')
                    $src_image = imagecreatefromjpeg($tmp);
                elseif ($mime === 'image/png')
                    $src_image = imagecreatefrompng($tmp);
                elseif ($mime === 'image/gif')
                    $src_image = imagecreatefromgif($tmp);
                elseif ($mime === 'image/webp')
                    $src_image = imagecreatefromwebp($tmp);

                if (!$src_image) {
                    $error = "Could not process the image. Please try another file.";
                } else {
                    // Delete old photo if exists
                    $oldStmt = $conn->prepare("SELECT {$which}_photo FROM site_visit WHERE id = ?");
                    $oldStmt->bind_param("i", $visit_id);
                    $oldStmt->execute();
                    $oldRow = $oldStmt->get_result()->fetch_assoc();
                    $oldPhoto = $oldRow[$which . '_photo'] ?? null;
                    if ($oldPhoto && file_exists($upload_dir . $oldPhoto)) {
                        unlink($upload_dir . $oldPhoto);
                    }

                    $photo_filename = 'visit_' . $visit_id . '_' . $which . '_' . time() . '.webp';
                    imagewebp($src_image, $upload_dir . $photo_filename, 85);
                    imagedestroy($src_image);

                    $col = $which . '_photo';
                    $upPhotoStmt = $conn->prepare("UPDATE site_visit SET {$col} = ? WHERE id = ?");
                    $upPhotoStmt->bind_param("si", $photo_filename, $visit_id);
                    if ($upPhotoStmt->execute()) {
                        $success = "Photo uploaded successfully! You can now write your report.";
                    } else {
                        $error = "Failed to save photo. Please try again.";
                    }
                }
            }
        }

        $redirect = BASE_URL . "designer-client-detail?client_id={$client_id}";
        if ($success)
            $redirect .= "&success=" . urlencode($success);
        if ($error)
            $redirect .= "&error=" . urlencode($error);
        header("Location: " . $redirect);
        exit();
    }

    if ($_POST['action'] === 'submit_report') {
        $report = trim($_POST['report']);
        $finished = 1;
        $finished_at = date('Y-m-d H:i:s');

        if (!in_array($which, ['designer1', 'designer2'])) {
            $error = "Invalid designer role.";
        } else {
            // Check photo was already uploaded
            $photoCheckStmt = $conn->prepare("SELECT {$which}_photo FROM site_visit WHERE id = ?");
            $photoCheckStmt->bind_param("i", $visit_id);
            $photoCheckStmt->execute();
            $photoRow = $photoCheckStmt->get_result()->fetch_assoc();
            $existingPhoto = $photoRow[$which . '_photo'] ?? null;

            if (empty($existingPhoto)) {
                $error = "You must upload a proof photo before submitting your report.";
            } elseif (empty($report)) {
                $error = "Please write your report before submitting.";
            } else {
                if ($which === 'designer1') {
                    $upStmt = $conn->prepare("
                        UPDATE site_visit
                        SET designer1_report = ?, designer1_finished = ?, designer1_finished_at = ?
                        WHERE id = ?
                    ");
                } else {
                    $upStmt = $conn->prepare("
                        UPDATE site_visit
                        SET designer2_report = ?, designer2_finished = ?, designer2_finished_at = ?
                        WHERE id = ?
                    ");
                }
                $upStmt->bind_param("sisi", $report, $finished, $finished_at, $visit_id);

                if ($upStmt->execute()) {
                    // Check both designers — mark Done if both finished or absent
                    $checkStmt = $conn->prepare("
        SELECT designer1_id, designer2_id,
               designer1_finished, designer2_finished,
               designer1_absent, designer2_absent,
               original_designer1_id, original_designer2_id
        FROM site_visit WHERE id = ?
    ");
                    $checkStmt->bind_param("i", $visit_id);
                    $checkStmt->execute();
                    $vRow = $checkStmt->get_result()->fetch_assoc();

                    $d1HasReplacement = !empty($vRow['original_designer1_id'] ?? null);
                    $d2HasReplacement = !empty($vRow['original_designer2_id'] ?? null);

                    $d1ok = ($vRow['designer1_absent'] && !$d1HasReplacement) || $vRow['designer1_finished'];
                    $d2ok = !$vRow['designer2_id'] || ($vRow['designer2_absent'] && !$d2HasReplacement) || $vRow['designer2_finished'];

                    if ($d1ok && $d2ok) {
                        $doneStmt = $conn->prepare("UPDATE site_visit SET status='Done' WHERE id=?");
                        $doneStmt->bind_param("i", $visit_id);
                        $doneStmt->execute();
                    } else {
                        $ongStmt = $conn->prepare("UPDATE site_visit SET status='Ongoing' WHERE id=? AND status='Pending'");
                        $ongStmt->bind_param("i", $visit_id);
                        $ongStmt->execute();
                    }
                    $success = "Report saved successfully!";
                } else {
                    $error = "Failed to save report.";
                }
            } // closes the else (photo exists check)
        }
    }

    if ($_POST['action'] === 'update_my_status') {
        $new_status = $_POST['new_status'];

        if (!in_array($new_status, ['pending', 'ongoing'])) {
            $error = "Invalid status.";
        } elseif (!in_array($which, ['designer1', 'designer2'])) {
            $error = "Invalid role.";
        } else {
            if ($new_status === 'ongoing') {
                $updStmt = $conn->prepare("UPDATE site_visit SET status='Ongoing' WHERE id=? AND status IN ('Pending', 'Done')");
                $updStmt->bind_param("i", $visit_id);
                $updStmt->execute();
                $success = "Status set to Ongoing.";
            } elseif ($new_status === 'pending') {
                $checkStmt = $conn->prepare("
                    SELECT designer1_finished, designer2_finished,
                           designer1_absent, designer2_absent, designer2_id
                    FROM site_visit WHERE id = ?
                ");
                $checkStmt->bind_param("i", $visit_id);
                $checkStmt->execute();
                $vRow = $checkStmt->get_result()->fetch_assoc();

                $otherFinished = false;
                if ($which === 'designer1') {
                    $otherFinished = $vRow['designer2_id'] &&
                        ($vRow['designer2_finished'] || $vRow['designer2_absent']);
                } else {
                    $otherFinished = $vRow['designer1_finished'] || $vRow['designer1_absent'];
                }

                if ($otherFinished) {
                    $error = "Cannot revert to Pending — the other designer has already finished.";
                } else {
                    $updStmt = $conn->prepare("UPDATE site_visit SET status='Pending' WHERE id=? AND status='Ongoing'");
                    $updStmt->bind_param("i", $visit_id);
                    $updStmt->execute();
                    $success = "Status reverted to Pending.";
                }
            }
        }
    }

    // PRG
    $redirect = BASE_URL . "designer-client-detail?client_id={$client_id}";
    if ($success)
        $redirect .= "&success=" . urlencode($success);
    if ($error)
        $redirect .= "&error=" . urlencode($error);
    header("Location: " . $redirect);
    exit();
}

$success = isset($_GET['success']) ? $_GET['success'] : '';
$error = isset($_GET['error']) ? $_GET['error'] : '';

// Fetch site visits for this client and this designer
$visitsStmt = $conn->prepare("
    SELECT sv.*,
           a1.full_name AS designer1_name,
           a2.full_name AS designer2_name,
           orig1.full_name AS original_designer1_name,
           orig2.full_name AS original_designer2_name,
           CASE WHEN sv.designer1_id = ? THEN 'designer1' ELSE 'designer2' END AS my_role
    FROM site_visit sv
    LEFT JOIN account a1    ON sv.designer1_id          = a1.id
    LEFT JOIN account a2    ON sv.designer2_id          = a2.id
    LEFT JOIN account orig1 ON sv.original_designer1_id = orig1.id
    LEFT JOIN account orig2 ON sv.original_designer2_id = orig2.id
    WHERE sv.client_id = ?
    AND (
        sv.designer1_id = ? OR sv.designer2_id = ?
        OR sv.original_designer1_id = ? OR sv.original_designer2_id = ?
    )
    ORDER BY sv.visit_date DESC
");
$visitsStmt->bind_param("iiiiii", $admin_id, $client_id, $admin_id, $admin_id, $admin_id, $admin_id);
$visitsStmt->execute();
$visits = $visitsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totalVisits = count($visits);
$doneVisits = count(array_filter($visits, fn($v) => $v['status'] === 'Done'));
$pendingVisits = count(array_filter($visits, fn($v) => $v['status'] === 'Pending'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($client['clientname']) ?> — Client Detail</title>
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
        }

        .container {
            max-width: 960px;
            margin: 30px auto;
            padding: 0 20px 40px;
        }

        /* ── Back button ── */
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 8px 18px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 18px;
            transition: background 0.2s;
        }

        .btn-back:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* ── Client Header ── */
        .client-header {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            border-radius: 16px;
            padding: 20px;
            color: white;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .client-header h1 {
            font-size: 20px;
            margin-bottom: 4px;
            line-height: 1.3;
        }

        .client-header .project {
            font-size: 14px;
            opacity: 0.9;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 22px;
            gap: 12px;
        }

        .info-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 14px;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 14px;
        }

        .info-card .label {
            font-size: 10px;
            opacity: 0.75;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .info-card .value {
            font-size: 14px;
            font-weight: 600;
        }

        .info-card .value.mono {
            font-family: monospace;
            font-size: 13px;
        }

        /* ── View Full Details Button ── */
        .btn-details {
            background: white;
            color: #3b1f0f;
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            white-space: nowrap;
            flex-shrink: 0;
            align-self: flex-start;
        }

        .btn-details:hover {
            background: #f5f5f5;
            transform: translateY(-1px);
        }

        /* ── Alerts ── */
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

        /* ── Section Card ── */
        .section-card {
            background: white;
            border-radius: 12px;
            padding: 26px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.07);
            margin-bottom: 22px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #3b1f0f;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f5f1ed;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ── Detail Rows ── */
        .detail-row {
            display: grid;
            grid-template-columns: 160px 1fr;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
            align-items: start;
            font-size: 13px;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: #6b7280;
        }

        .detail-value {
            color: #111;
        }

        /* Status badge */
        .status-pill {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .pill-new {
            background: #fef3c7;
            color: #92400e;
        }

        .pill-old {
            background: #dbeafe;
            color: #1e40af;
        }

        .pill-done {
            background: #d1fae5;
            color: #065f46;
        }

        .pill-pend {
            background: #fef3c7;
            color: #92400e;
        }

        .pill-ongo {
            background: #dbeafe;
            color: #1e40af;
        }

        /* ── Visit Stats ── */
        .visit-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 22px;
        }

        .v-stat {
            background: #f9f9f9;
            border-radius: 10px;
            padding: 14px;
            text-align: center;
            border: 1px solid #f3f4f6;
        }

        .v-stat-val {
            font-size: 26px;
            font-weight: 700;
            color: #3b1f0f;
        }

        .v-stat-lbl {
            font-size: 11px;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-top: 3px;
        }

        /* ── Visit Cards ── */
        .visit-card {
            border: 1px solid #f3f4f6;
            border-radius: 10px;
            margin-bottom: 12px;
            overflow: hidden;
            border-left: 4px solid #e9ecef;
        }

        .visit-card.v-pending {
            border-left-color: #f59e0b;
        }

        .visit-card.v-ongoing {
            border-left-color: #3b82f6;
        }

        .visit-card.v-done {
            border-left-color: #10b981;
        }

        .visit-card-top {
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            user-select: none;
        }

        .visit-card-top:hover {
            background: #fafafa;
        }

        .visit-date {
            font-size: 14px;
            font-weight: 700;
            color: #1f2937;
        }

        .visit-sub {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 3px;
        }

        .visit-card-body {
            display: none;
            padding: 0 18px 18px;
            border-top: 1px solid #f3f4f6;
        }

        .visit-card-body.open {
            display: block;
            padding-top: 16px;
        }

        /* Report form / display */
        .report-section label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 8px;
        }

        .report-textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 13px;
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
        }

        .report-textarea:focus {
            outline: none;
            border-color: #8a5a44;
        }

        .btn-save {
            background: #3b82f6;
            color: white;
            padding: 9px 22px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 12px;
        }

        .btn-save:hover {
            background: #2563eb;
        }

        .finished-banner {
            background: #d1fae5;
            border-radius: 8px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 600;
            color: #065f46;
        }

        .existing-report {
            background: #f0fdf4;
            border-radius: 8px;
            padding: 14px;
            border-left: 3px solid #10b981;
            margin-top: 12px;
        }

        .existing-report strong {
            font-size: 12px;
            color: #065f46;
            display: block;
            margin-bottom: 5px;
        }

        .existing-report p {
            font-size: 13px;
            color: #374151;
        }

        /* ── Modal ── */
        .modal-overlay {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: white;
            padding: 30px;
            border-radius: 14px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #f3f4f6;
            padding-bottom: 14px;
        }

        .modal-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: #3b1f0f;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-close {
            font-size: 22px;
            color: #9ca3af;
            background: none;
            border: none;
            cursor: pointer;
            line-height: 1;
            transition: color 0.2s;
        }

        .modal-close:hover {
            color: #374151;
        }

        .empty-visits {
            text-align: center;
            padding: 40px 20px;
            color: #9ca3af;
        }

        .empty-visits i {
            font-size: 40px;
            display: block;
            margin-bottom: 12px;
        }

        @media (max-width: 600px) {
            .container {
                margin: 10px auto;
                padding: 0 12px 30px;
            }

            .detail-row {
                grid-template-columns: 1fr;
                gap: 4px;
            }

            .info-cards {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .info-card {
                padding: 10px;
            }

            .info-card .value {
                font-size: 13px;
            }

            .info-card .value.mono {
                font-size: 11px;
                word-break: break-all;
            }

            .visit-stats {
                grid-template-columns: 1fr 1fr 1fr;
            }

            .section-card {
                padding: 16px;
            }

            .btn-details span {
                display: none;
                /* icon only on very small screens */
            }
        }
    </style>
</head>

<body>
    <div class="container">

        <!-- ── Back ── -->
        <a href="designer-clients-list" class="btn-back" style="display:inline-flex; align-items:center; gap:8px; color:#3b1f0f; background:white;
              padding:9px 18px; border-radius:8px; font-weight:600; font-size:13px;
              text-decoration:none; box-shadow:0 1px 3px rgba(0,0,0,0.08); margin-bottom:18px;
              border: 1px solid #e9ecef; transition: all 0.2s;">
            <i class="fas fa-arrow-left"></i> Back to Clients
        </a>

        <!-- ══════════════════════════════════════════ -->
        <!-- CLIENT HEADER                              -->
        <!-- ══════════════════════════════════════════ -->
        <div class="client-header">
            <div class="header-top">
                <div>
                    <h1>📋 <?= htmlspecialchars($client['clientname']) ?></h1>
                    <div class="project"><?= htmlspecialchars($client['nameproject']) ?></div>
                </div>
                <button class="btn-details" onclick="openModal()">
                    <i class="fas fa-info-circle"></i> View Full Details
                </button>
            </div>

            <div class="info-cards">
                <?php if ($client['reference_number']): ?>
                    <div class="info-card">
                        <div class="label"><i class="fas fa-hashtag"></i> Reference Number</div>
                        <div class="value mono"><?= htmlspecialchars($client['reference_number']) ?></div>
                    </div>
                <?php endif; ?>
                <div class="info-card">
                    <div class="label"><i class="fas fa-building"></i> Business Type</div>
                    <div class="value"><?= htmlspecialchars($business_type_label) ?></div>
                </div>
                <div class="info-card">
                    <div class="label"><i class="fas fa-peso-sign"></i> Total Project Cost</div>
                    <div class="value">₱<?= number_format($client['total_project_cost'] ?? 0, 2) ?></div>
                </div>
                <div class="info-card">
                    <div class="label"><i class="fas fa-balance-scale"></i> Remaining Balance</div>
                    <div class="value">₱<?= number_format($client['remaining_balance'] ?? 0, 2) ?></div>
                </div>
            </div>
        </div>

        <!-- ── Alerts ── -->
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════ -->
        <!-- SITE VISITS SECTION                        -->
        <!-- ══════════════════════════════════════════ -->
        <div class="section-card">
            <div class="section-title">
                <i class="fas fa-calendar-check" style="color:#8a5a44;"></i> Site Visit Reports
            </div>

            <!-- Visit Summary Stats -->
            <div class="visit-stats">
                <div class="v-stat">
                    <div class="v-stat-val"><?= $totalVisits ?></div>
                    <div class="v-stat-lbl">Total Visits</div>
                </div>
                <div class="v-stat">
                    <div class="v-stat-val" style="color:#f59e0b;"><?= $pendingVisits ?></div>
                    <div class="v-stat-lbl">Pending</div>
                </div>
                <div class="v-stat">
                    <div class="v-stat-val" style="color:#10b981;"><?= $doneVisits ?></div>
                    <div class="v-stat-lbl">Done</div>
                </div>
            </div>

            <?php if (empty($visits)): ?>
                <div class="empty-visits">
                    <i class="fas fa-calendar-times"></i>
                    <p style="font-size:15px; font-weight:600;">No site visits yet</p>
                    <p style="font-size:13px; margin-top:5px;">Visits for this client will appear here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($visits as $idx => $visit):
                    // Determine my role on this visit
                    // Could be current designer1, current designer2,
                    // or the original who was replaced (absent)
                    if ($visit['designer1_id'] == $admin_id) {
                        $myRole = 'designer1';
                    } elseif ($visit['designer2_id'] == $admin_id) {
                        $myRole = 'designer2';
                    } elseif ($visit['original_designer1_id'] == $admin_id) {
                        $myRole = 'designer1'; // I was the original, now replaced
                    } elseif ($visit['original_designer2_id'] == $admin_id) {
                        $myRole = 'designer2'; // I was the original, now replaced
                    } else {
                        $myRole = $visit['my_role']; // fallback
                    }

                    $myReport = $visit[$myRole . '_report'];
                    $myFinished = (bool) $visit[$myRole . '_finished'];
                    $myPhoto = $visit[$myRole . '_photo'] ?? null;

                    // Am I truly absent?
                    // I am absent if: the absent flag is on my slot AND I am the ORIGINAL (not the replacement)
                    $isOriginalDesigner1 = ($visit['original_designer1_id'] == $admin_id);
                    $isOriginalDesigner2 = ($visit['original_designer2_id'] == $admin_id);
                    $isReplacement = false;

                    if ($myRole === 'designer1') {
                        // If there's an original saved and it's NOT me, then I'm the replacement
                        if (!empty($visit['original_designer1_id']) && $visit['original_designer1_id'] != $admin_id) {
                            $isReplacement = true;
                        }
                        // I am absent only if the flag is set AND I am the original (not replacement)
                        $myAbsent = (bool) $visit['designer1_absent'] && !$isReplacement;
                    } else {
                        if (!empty($visit['original_designer2_id']) && $visit['original_designer2_id'] != $admin_id) {
                            $isReplacement = true;
                        }
                        $myAbsent = (bool) $visit['designer2_absent'] && !$isReplacement;
                    }

                    $visitStatus = strtolower($visit['status']);
                    ?>
                    <div class="visit-card v-<?= $visitStatus ?>">
                        <!-- Card Top -->
                        <div class="visit-card-top" onclick="toggleVisit(<?= $visit['id'] ?>)">
                            <div>
                                <div class="visit-date">
                                    <i class="fas fa-calendar" style="color:#8a5a44; margin-right:5px;"></i>
                                    <?= date('F d, Y', strtotime($visit['visit_date'])) ?>
                                    <?php if (!empty($visit['visit_time'])): ?>
                                        <span style="margin-left:8px; color:#6b7280; font-size:13px; font-weight:400;">
                                            <i class="fas fa-clock" style="color:#8a5a44;"></i>
                                            <?= date('g:i A', strtotime($visit['visit_time'])) ?>
                                        </span>
                                    <?php endif; ?>
                                    <span
                                        style="margin-left:8px; background:#f3f4f6; padding:2px 9px; border-radius:8px; font-size:11px; font-weight:600; color:#374151;">
                                        <?= $myRole === 'designer1' ? 'Lead' : 'Support' ?>
                                    </span>
                                </div>
                                <div class="visit-sub">
                                    <?php if ($visit['designer2_name'] && $myRole === 'designer1'): ?>
                                        With: <?= htmlspecialchars($visit['designer2_name']) ?>
                                    <?php elseif ($myRole === 'designer2'): ?>
                                        With: <?= htmlspecialchars($visit['designer1_name']) ?>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top:5px; display:flex; align-items:center; gap:6px;">
                                    <?php if (($visit['visit_type'] ?? 'Free') === 'Paid'): ?>
                                        <span
                                            style="background:#fef3c7; color:#92400e; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:4px;">
                                            <i class="fas fa-money-bill-wave"></i> Paid —
                                            ₱<?= number_format($visit['visit_amount'], 2) ?>
                                        </span>
                                    <?php else: ?>
                                        <span
                                            style="background:#d1fae5; color:#065f46; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:4px;">
                                            <i class="fas fa-gift"></i> Free
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                                <span
                                    class="status-pill <?= $visitStatus === 'done' ? 'pill-done' : ($visitStatus === 'ongoing' ? 'pill-ongo' : 'pill-pend') ?>">
                                    <?= $visit['status'] ?>
                                </span>
                                <?php if ($myFinished): ?>
                                    <span style="font-size:11px; color:#10b981; font-weight:600;">
                                        <i class="fas fa-check"></i> You finished
                                    </span>
                                <?php endif; ?>
                                <i class="fas fa-chevron-down" id="chev-<?= $visit['id'] ?>"
                                    style="color:#9ca3af; font-size:12px; transition:transform 0.2s;"></i>
                            </div>
                        </div>

                        <!-- Card Body -->
                        <div class="visit-card-body" id="vbody-<?= $visit['id'] ?>">

                            <?php if ($visit['notes']): ?>
                                <div
                                    style="background:#fffbeb; border-radius:8px; padding:12px; margin-bottom:14px; font-size:13px; color:#92400e;">
                                    <i class="fas fa-sticky-note"></i>
                                    <strong>Notes from head:</strong> <?= htmlspecialchars($visit['notes']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($myAbsent): ?>
                                <!-- ABSENT — shown only to the original designer who was replaced -->
                                <div
                                    style="background:#fee2e2; border-radius:8px; padding:16px; display:flex; align-items:flex-start; gap:12px; color:#991b1b; font-weight:600; font-size:14px;">
                                    <i class="fas fa-user-slash" style="font-size:20px; flex-shrink:0; margin-top:2px;"></i>
                                    <div>
                                        <div>You have been marked as absent for this visit.</div>
                                        <?php
                                        $absentReason = $visit[$myRole . '_absent_reason'] ?? '';
                                        $replacementName = ($myRole === 'designer1')
                                            ? ($visit['designer1_name'] ?? null)
                                            : ($visit['designer2_name'] ?? null);
                                        // Only show replacement name if there is an original (meaning someone replaced them)
                                        $hasReplacement = !empty($visit['original_' . $myRole . '_id']);
                                        ?>
                                        <?php if ($absentReason): ?>
                                            <div
                                                style="font-size:12px; font-weight:400; margin-top:6px; background:#fca5a5; padding:6px 10px; border-radius:6px;">
                                                <i class="fas fa-comment"></i> Reason: "<?= htmlspecialchars($absentReason) ?>"
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($hasReplacement && $replacementName): ?>
                                            <div
                                                style="font-size:12px; font-weight:600; margin-top:6px; display:flex; align-items:center; gap:5px;">
                                                <i class="fas fa-exchange-alt"></i>
                                                Replaced by: <span
                                                    style="text-decoration:underline;"><?= htmlspecialchars($replacementName) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div style="font-size:12px; font-weight:400; margin-top:6px; opacity:0.8;">
                                            Please contact your head if this is incorrect.
                                        </div>
                                    </div>
                                </div>

                            <?php elseif ($myFinished): ?>
                                <!-- FINISHED -->
                                <div class="finished-banner">
                                    <i class="fas fa-check-circle" style="font-size:18px;"></i>
                                    You have marked this visit as finished.
                                    <?php if ($visit[$myRole . '_finished_at']): ?>
                                        <span style="font-weight:400; margin-left:auto; font-size:12px;">
                                            <?= date('M d, Y g:i A', strtotime($visit[$myRole . '_finished_at'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php $myPhoto = $visit[$myRole . '_photo'] ?? null; ?>
                                <?php if ($myPhoto): ?>
                                    <div style="margin-top:12px; margin-bottom:10px;">
                                        <div
                                            style="font-size:12px; font-weight:700; color:#065f46; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                                            <i class="fas fa-camera"></i> Proof Photo
                                        </div>
                                        <img src="<?= BASE_URL ?>uploads/site_visit_photos/<?= htmlspecialchars($myPhoto) ?>" alt="Proof"
                                            style="max-width:100%; max-height:220px; border-radius:8px; border:2px solid #bbf7d0; object-fit:cover; display:block; cursor:pointer;"
                                            onclick="openPhotoModal(this.src)">
                                    </div>
                                <?php endif; ?>
                                <?php if ($myReport): ?>
                                    <div class="existing-report">
                                        <strong><i class="fas fa-file-alt"></i> Your Report</strong>
                                        <p><?= nl2br(htmlspecialchars($myReport)) ?></p>
                                    </div>
                                <?php endif; ?>

                            <?php else: ?>
                                <!-- PENDING / ONGOING -->
                                <?php if ($isReplacement): ?>
                                    <div
                                        style="background:#ede9fe; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:12px; font-weight:600; color:#5b21b6; display:flex; align-items:center; gap:8px;">
                                        <i class="fas fa-exchange-alt"></i>
                                        You are the <strong>replacement designer</strong> for this visit.
                                        <?php
                                        $origName = ($myRole === 'designer1')
                                            ? ($visit['original_designer1_name'] ?? null)
                                            : ($visit['original_designer2_name'] ?? null);
                                        ?>
                                        <?php if ($origName): ?>
                                            &nbsp;Originally assigned: <span
                                                style="text-decoration:underline;"><?= htmlspecialchars($origName) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($visit['approval_status'] !== 'Approved'): ?>
                                    <!-- Not yet approved -->
                                    <div
                                        style="background:#fef3c7; border-radius:8px; padding:16px; display:flex; align-items:center; gap:12px; color:#92400e; font-size:13px; font-weight:600;">
                                        <i class="fas fa-hourglass-half" style="font-size:20px;"></i>
                                        <div>
                                            <?php if ($visit['approval_status'] === 'Rejected'): ?>
                                                <div>This visit has been <strong>rejected</strong> by the manager.</div>
                                                <div style="font-size:12px; font-weight:400; margin-top:4px; opacity:0.85;">
                                                    Please wait for the head to make adjustments and resubmit.
                                                </div>
                                                <?php if ($visit['approval_comment']): ?>
                                                    <div
                                                        style="margin-top:8px; padding:8px; background:#fee2e2; border-radius:6px; color:#991b1b; font-size:12px; font-style:italic;">
                                                        <i class="fas fa-comment-slash"></i>
                                                        "<?= htmlspecialchars($visit['approval_comment']) ?>"
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <div>This visit is <strong>awaiting approval</strong> from the manager.</div>
                                                <div style="font-size:12px; font-weight:400; margin-top:4px; opacity:0.85;">
                                                    You can set it as ongoing and submit your report once approved.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                <?php else: ?>
                                    <!-- Approved -->
                                    <div
                                        style="background:#d1fae5; border-radius:8px; padding:8px 14px; margin-bottom:14px; font-size:12px; font-weight:600; color:#065f46; display:flex; align-items:center; gap:6px;">
                                        <i class="fas fa-check-circle"></i> Approved — you can now submit your report.
                                    </div>

                                    <!-- Status Toggle -->
                                    <div style="display:flex; gap:10px; margin-bottom:16px; align-items:center; flex-wrap:wrap;">
                                        <span
                                            style="font-size:12px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:0.4px;">
                                            <i class="fas fa-toggle-on"></i> My Status:
                                        </span>
                                        <?php if ($visitStatus === 'pending' || ($visitStatus === 'done' && !$myFinished)): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="update_my_status">
                                                <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                <input type="hidden" name="new_status" value="ongoing">
                                                <input type="hidden" name="which" value="<?= $myRole ?>">
                                                <button type="submit"
                                                    style="padding:7px 16px; background:#3b82f6; color:white; border:none; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                                                    <i class="fas fa-play"></i> Set as Ongoing
                                                </button>
                                            </form>
                                        <?php elseif ($visitStatus === 'ongoing'): ?>
                                            <span
                                                style="background:#dbeafe; color:#1e40af; padding:5px 14px; border-radius:20px; font-size:12px; font-weight:700;">
                                                <i class="fas fa-spinner fa-spin" style="font-size:10px;"></i> Ongoing
                                            </span>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="update_my_status">
                                                <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                <input type="hidden" name="new_status" value="pending">
                                                <input type="hidden" name="which" value="<?= $myRole ?>">
                                                <button type="submit"
                                                    style="padding:7px 16px; background:#f9f9f9; color:#6b7280; border:2px solid #e9ecef; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                                                    <i class="fas fa-undo"></i> Set Back to Pending
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($visitStatus === 'ongoing' || ($visitStatus === 'done' && !$myFinished)): ?>

                                        <?php
                                        $myPhoto = $visit[$myRole . '_photo'] ?? null;
                                        ?>

                                        <?php if (empty($myPhoto)): ?>
                                            <!-- STEP 1: Upload Photo First -->
                                            <div
                                                style="background:#fffbeb; border:2px dashed #f59e0b; border-radius:10px; padding:20px; margin-bottom:16px;">
                                                <div
                                                    style="font-size:13px; font-weight:700; color:#92400e; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                                    <i class="fas fa-camera" style="font-size:16px;"></i>
                                                    Step 1 of 2 — Upload Proof Photo
                                                    <span style="font-size:11px; font-weight:400; color:#b45309;">(Required before writing your
                                                        report)</span>
                                                </div>
                                                <form method="POST" enctype="multipart/form-data">
                                                    <input type="hidden" name="action" value="upload_proof_photo">
                                                    <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                    <input type="hidden" name="which" value="<?= $myRole ?>">
                                                    <input type="file" name="proof_photo" accept="image/*" required
                                                        id="photoInput-<?= $visit['id'] ?>"
                                                        style="width:100%; padding:9px; border:2px solid #f59e0b; border-radius:8px; font-size:13px; background:white; cursor:pointer; margin-bottom:10px;"
                                                        onchange="previewPhoto(this, <?= $visit['id'] ?>)">
                                                    <div id="photoPreview-<?= $visit['id'] ?>" style="display:none; margin-bottom:10px;">
                                                        <img id="previewImg-<?= $visit['id'] ?>" src="" alt="Preview"
                                                            style="max-width:100%; max-height:200px; border-radius:8px; border:2px solid #fcd34d; object-fit:cover; display:block;">
                                                    </div>
                                                    <button type="submit"
                                                        style="background:#f59e0b; color:white; padding:9px 22px; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; display:inline-flex; align-items:center; gap:6px;">
                                                        <i class="fas fa-upload"></i> Upload Photo
                                                    </button>
                                                </form>
                                            </div>
                                            <!-- Step 2 locked -->
                                            <div
                                                style="background:#f9f9f9; border-radius:8px; padding:16px; text-align:center; color:#9ca3af; font-size:13px; border:2px dashed #e9ecef;">
                                                <i class="fas fa-lock" style="font-size:20px; display:block; margin-bottom:8px;"></i>
                                                <strong style="color:#374151;">Step 2</strong> — Write your report will unlock after photo is
                                                uploaded.
                                            </div>

                                        <?php else: ?>
                                            <!-- STEP 1 DONE: Show uploaded photo -->
                                            <div
                                                style="background:#d1fae5; border-radius:10px; padding:14px; margin-bottom:16px; display:flex; align-items:flex-start; gap:12px;">
                                                <div style="flex-shrink:0;">
                                                    <img src="<?= BASE_URL ?>uploads/site_visit_photos/<?= htmlspecialchars($myPhoto) ?>" alt="Proof"
                                                        style="width:80px; height:80px; object-fit:cover; border-radius:8px; border:2px solid #6ee7b7; cursor:pointer;"
                                                        onclick="openPhotoModal(this.src)">
                                                </div>
                                                <div style="flex:1;">
                                                    <div
                                                        style="font-size:12px; font-weight:700; color:#065f46; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                                        <i class="fas fa-check-circle"></i> Step 1 Complete — Proof Photo Uploaded
                                                    </div>
                                                    <div style="font-size:11px; color:#059669;">Photo converted and saved as WebP.</div>
                                                    <!-- Allow re-upload -->
                                                    <form method="POST" enctype="multipart/form-data"
                                                        style="margin-top:8px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                                        <input type="hidden" name="action" value="upload_proof_photo">
                                                        <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                        <input type="hidden" name="which" value="<?= $myRole ?>">
                                                        <input type="file" name="proof_photo" accept="image/*"
                                                            id="reuploadInput-<?= $visit['id'] ?>" style="font-size:11px; color:#374151;">
                                                        <button type="submit"
                                                            style="background:white; color:#065f46; padding:5px 12px; border:1px solid #6ee7b7; border-radius:6px; cursor:pointer; font-size:11px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                                                            <i class="fas fa-redo"></i> Replace Photo
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>

                                            <!-- STEP 2: Write Report -->
                                            <div
                                                style="background:#f0f9ff; border:2px solid #bae6fd; border-radius:10px; padding:16px; margin-bottom:4px;">
                                                <div
                                                    style="font-size:13px; font-weight:700; color:#0369a1; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                                    <i class="fas fa-file-alt"></i> Step 2 of 2 — Write Your Report
                                                </div>
                                                <form method="POST" class="report-section">
                                                    <input type="hidden" name="action" value="submit_report">
                                                    <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                    <input type="hidden" name="which" value="<?= $myRole ?>">
                                                    <textarea name="report" class="report-textarea"
                                                        placeholder="Describe what was observed during the site visit..."><?= htmlspecialchars($myReport ?? '') ?></textarea>
                                                    <button type="submit" class="btn-save">
                                                        <i class="fas fa-save"></i> Save & Mark as Finished
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <div
                                            style="background:#f9f9f9; border-radius:8px; padding:16px; text-align:center; color:#9ca3af; font-size:13px;">
                                            <i class="fas fa-lock" style="font-size:20px; display:block; margin-bottom:8px;"></i>
                                            Set your status to <strong style="color:#374151;">Ongoing</strong> first before submitting a
                                            report.
                                        </div>
                                    <?php endif; ?>

                                <?php endif; ?> <!-- end approval check -->
                            <?php endif; ?> <!-- end absent/finished/pending check -->

                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

    <!-- ══════════════════════════════════════════ -->
    <!-- FULL DETAILS MODAL                         -->
    <!-- ══════════════════════════════════════════ -->
    <div class="modal-overlay" id="detailModal" onclick="handleOverlayClick(event)">
        <div class="modal-box">
            <div class="modal-header">
                <h2><i class="fas fa-user-circle" style="color:#8a5a44;"></i> Client Details</h2>
                <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>

            <!-- Reference Number -->
            <div class="detail-row">
                <div class="detail-label">Reference Number:</div>
                <div class="detail-value" style="color:#3b82f6; font-family:monospace; font-weight:600;">
                    <?= htmlspecialchars($client['reference_number'] ?? '') ?>
                </div>
            </div>

            <!-- Client Name -->
            <div class="detail-row">
                <div class="detail-label">Client Name:</div>
                <div class="detail-value"><?= htmlspecialchars($client['clientname']) ?></div>
            </div>

            <!-- Project Name -->
            <div class="detail-row">
                <div class="detail-label">Project Name:</div>
                <div class="detail-value"><?= htmlspecialchars($client['nameproject']) ?></div>
            </div>

            <!-- Status -->
            <div class="detail-row">
                <div class="detail-label">Status:</div>
                <div class="detail-value">
                    <?php $st = $client['status'] ?? ''; ?>
                    <span class="status-pill <?= strtolower($st) === 'new client' ? 'pill-new' : 'pill-old' ?>">
                        <?= htmlspecialchars($st) ?>
                    </span>
                </div>
            </div>

            <!-- Business Type -->
            <div class="detail-row">
                <div class="detail-label">Business Type:</div>
                <div class="detail-value"><?= htmlspecialchars($business_type_label) ?></div>
            </div>

            <!-- Phone -->
            <div class="detail-row">
                <div class="detail-label">Phone:</div>
                <div class="detail-value"><?= htmlspecialchars($client['contact'] ?? '') ?></div>
            </div>

            <!-- Email -->
            <div class="detail-row">
                <div class="detail-label">Email:</div>
                <div class="detail-value"><?= htmlspecialchars($client['email'] ?? '') ?></div>
            </div>

            <!-- Address -->
            <div class="detail-row">
                <div class="detail-label">Address:</div>
                <div class="detail-value"><?= htmlspecialchars($client['address'] ?? '') ?></div>
            </div>

            <!-- Gender -->
            <div class="detail-row">
                <div class="detail-label">Gender:</div>
                <div class="detail-value"><?= htmlspecialchars($client['gender'] ?? '—') ?></div>
            </div>

            <!-- Classification -->
            <div class="detail-row">
                <div class="detail-label">Classification:</div>
                <div class="detail-value"><?= htmlspecialchars($client['client_class'] ?? '—') ?></div>
            </div>

            <!-- Client Type -->
            <div class="detail-row">
                <div class="detail-label">Client Type:</div>
                <div class="detail-value"><?= htmlspecialchars($client['client_type'] ?? '—') ?></div>
            </div>

            <!-- Project Scope -->
            <?php if (!empty($client['project_scope'])): ?>
                <div class="detail-row">
                    <div class="detail-label">Project Scope:</div>
                    <div class="detail-value"><?= nl2br(htmlspecialchars($client['project_scope'])) ?></div>
                </div>
            <?php endif; ?>

            <!-- Scope of Work -->
            <?php if (!empty($client['scope_of_work'])): ?>
                <div class="detail-row">
                    <div class="detail-label">Scope of Work:</div>
                    <div class="detail-value"><?= nl2br(htmlspecialchars($client['scope_of_work'])) ?></div>
                </div>
            <?php endif; ?>

            <!-- Total Project Cost -->
            <div class="detail-row">
                <div class="detail-label">Total Project Cost:</div>
                <div class="detail-value" style="font-weight:700; color:#065f46;">
                    ₱<?= number_format($client['total_project_cost'] ?? 0, 2) ?>
                </div>
            </div>

            <!-- Remaining Balance -->
            <div class="detail-row">
                <div class="detail-label">Remaining Balance:</div>
                <div class="detail-value" style="font-weight:700; color:#1e40af;">
                    ₱<?= number_format($client['remaining_balance'] ?? 0, 2) ?>
                </div>
            </div>

        </div>
    </div>

    <script>
        function toggleVisit(id) {
            const body = document.getElementById('vbody-' + id);
            const chev = document.getElementById('chev-' + id);
            body.classList.toggle('open');
            chev.style.transform = body.classList.contains('open') ? 'rotate(180deg)' : '';
        }

        function openModal() {
            document.getElementById('detailModal').classList.add('open');
        }
        function closeModal() {
            document.getElementById('detailModal').classList.remove('open');
        }
        function handleOverlayClick(e) {
            if (e.target === document.getElementById('detailModal')) closeModal();
        }
        function previewPhoto(input, visitId) {
            const preview = document.getElementById('photoPreview-' + visitId);
            const img = document.getElementById('previewImg-' + visitId);
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => { img.src = e.target.result; preview.style.display = 'block'; };
                reader.readAsDataURL(input.files[0]);
            }
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