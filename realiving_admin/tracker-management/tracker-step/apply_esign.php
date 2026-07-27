<?php
// apply_esign.php
// Place at: realiving_admin/tracker_step/apply_esign.php
session_start();
set_time_limit(120);

include $includes ['connection'];
require_once ROOT_PATH . 'vendor/autoload.php';

use setasign\Fpdi\Tcpdf\Fpdi;

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$data = json_decode(file_get_contents('php://input'), true);

$approval_id = isset($data['approval_id']) ? intval($data['approval_id']) : 0;
$action = isset($data['action']) ? $data['action'] : '';
$note = isset($data['note']) ? trim($data['note']) : '';
$apply_sign = isset($data['apply_sign']) ? (bool) $data['apply_sign'] : false;
$sign_x_pct = isset($data['sign_x_pct']) ? floatval($data['sign_x_pct']) : 0;
$sign_y_pct = isset($data['sign_y_pct']) ? floatval($data['sign_y_pct']) : 0;
$sign_w_pct = isset($data['sign_w_pct']) ? floatval($data['sign_w_pct']) : 20;
$sign_h_pct = isset($data['sign_h_pct']) ? floatval($data['sign_h_pct']) : 8;
$sign_page = isset($data['sign_page']) ? intval($data['sign_page']) : 1;

if (!$approval_id || !in_array($action, ['approved', 'rejected'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit();
}

// ── Get reviewer info ────────────────────────────────────────────
$roleStmt = $conn->prepare("SELECT role, is_head, e_signature, full_name FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$reviewerInfo = $roleStmt->get_result()->fetch_assoc();
$reviewerRole = $reviewerInfo['role'];
$isHead = (bool) ($reviewerInfo['is_head'] ?? false);
$sigPath = $reviewerInfo['e_signature'] ?? null;
$reviewerName = $reviewerInfo['full_name'] ?? 'Approver';

// ── Get approval record ──────────────────────────────────────────
$approvalStmt = $conn->prepare("SELECT * FROM stage_approvals WHERE id = ?");
$approvalStmt->bind_param("i", $approval_id);
$approvalStmt->execute();
$approval = $approvalStmt->get_result()->fetch_assoc();

if (!$approval) {
    echo json_encode(['success' => false, 'error' => 'Approval record not found']);
    exit();
}

// ── Permission check ─────────────────────────────────────────────
$requiredApprovers = [
    'Rough Estimation' => ['designer', 'general_manager', 'operational_manager'],
    'Samples Submitted TDS/SDS' => ['technical_designer', 'general_manager', 'operational_manager'],
    'Quotation' => ['designer', 'general_manager', 'operational_manager'],
    'Bill of Materials (BOM)' => ['technical_designer', 'general_manager', 'operational_manager'],
    'Purchase Order (Submit to accounting)' => ['accounting', 'general_manager', 'operational_manager'],
];

$stageName = $approval['stage_name'];
$allowedRoles = $requiredApprovers[$stageName] ?? [];
$canReview = in_array($reviewerRole, $allowedRoles);

if ($reviewerRole === 'technical_designer' && !$isHead)
    $canReview = false;
if ($reviewerRole === 'designer') {
    $canReview = (in_array($stageName, ['Rough Estimation', 'Quotation', 'Samples Submitted TDS/SDS']) && $isHead);
}
if ($reviewerRole === 'accounting') {
    $canReview = ($stageName === 'Purchase Order (Submit to accounting)');
}
if (!$canReview) {
    echo json_encode(['success' => false, 'error' => 'You do not have permission to review this stage']);
    exit();
}

// ── Sequential check for GM/OM ───────────────────────────────────
$sequentialStages = [
    'Rough Estimation' => ['designer'],
    'Samples Submitted TDS/SDS' => ['designer', 'technical_designer'],
    'Quotation' => ['designer'],
    'Bill of Materials (BOM)' => ['technical_designer'],
    'Purchase Order (Submit to accounting)' => ['accounting'],
    'Production Data Submittals' => ['technical_designer'],
];

if (isset($sequentialStages[$stageName]) && in_array($reviewerRole, ['general_manager', 'operational_manager'])) {
    $step1Roles = $sequentialStages[$stageName];
    foreach ($step1Roles as $s1Role) {
        $s1Stmt = $conn->prepare("
            SELECT COUNT(*) FROM stage_approval_reviews
            WHERE approval_id = ? AND reviewer_role = ? AND review_status = 'approved'
        ");
        $s1Stmt->bind_param("is", $approval_id, $s1Role);
        $s1Stmt->execute();
        $s1Approved = (int) $s1Stmt->get_result()->fetch_row()[0];
        if ($s1Approved === 0) {
            $label = ucwords(str_replace('_', ' ', $s1Role));
            echo json_encode(['success' => false, 'error' => "The {$label} must approve first before GM/OM can review."]);
            exit();
        }
    }

    $otherRole = ($reviewerRole === 'general_manager') ? 'operational_manager' : 'general_manager';
    $otherStmt = $conn->prepare("
        SELECT COUNT(*) FROM stage_approval_reviews
        WHERE approval_id = ? AND reviewer_role = ? AND review_status = 'approved'
    ");
    $otherStmt->bind_param("is", $approval_id, $otherRole);
    $otherStmt->execute();
    if ((int) $otherStmt->get_result()->fetch_row()[0] > 0) {
        echo json_encode(['success' => false, 'error' => 'Already approved by ' . str_replace('_', ' ', $otherRole) . '. Only one of GM/OM is required.']);
        exit();
    }
}

// ── Apply e-signature to PDF ─────────────────────────────────────
$doc_root = dirname(dirname(dirname(__FILE__)));

if ($action === 'approved' && $apply_sign && $sigPath) {

    // Clean path — strip any leading ../../
    $cleanFilePath = preg_replace('#^(\.\./)+#', '', $approval['file_path']);
    $absFilePath = $doc_root . '/' . $cleanFilePath;

    $cleanFilePath = preg_replace('#^(\.\./)+#', '', $approval['file_path']);
    $absFilePath = $doc_root . '/' . $cleanFilePath;

    if (!file_exists($absFilePath)) {
        echo json_encode(['success' => false, 'error' => 'PDF file not found on server.']);
        exit();
    }

    if (!file_exists($absSignPath)) {
        echo json_encode(['success' => false, 'error' => 'Signature image not found. Please re-upload in Account Settings.']);
        exit();
    }

    try {
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pageCount = $pdf->setSourceFile($absFilePath);
        $targetPage = max(1, min($sign_page, $pageCount));

        for ($p = 1; $p <= $pageCount; $p++) {
            $tplId = $pdf->importPage($p);
            $size = $pdf->getTemplateSize($tplId);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId, 0, 0, $size['width'], $size['height']);

            if ($p === $targetPage) {
                $pageW = $size['width'];
                $pageH = $size['height'];
                // Convert percentage size to mm
                $sigW = ($sign_w_pct / 100) * $pageW;
                $sigH = ($sign_h_pct / 100) * $pageH;
                $sigX = ($sign_x_pct / 100) * $pageW - ($sigW / 2);
                $sigY = ($sign_y_pct / 100) * $pageH - ($sigH / 2);
                $sigX = max(0, min($sigX, $pageW - $sigW));
                $sigY = max(0, min($sigY, $pageH - $sigH));

                // Validate signature image is readable
                if (!@getimagesize($absSignPath)) {
                    echo json_encode(['success' => false, 'error' => 'Signature image is unreadable or corrupted.']);
                    exit();
                }

                // Clamp minimum size to avoid rendering issues at small scales
                $sigW = max($sigW, 15);
                $sigH = max($sigH, 6);

                // Re-clamp position after size adjustment
                $sigX = max(0, min($sigX, $pageW - $sigW));
                $sigY = max(0, min($sigY, $pageH - $sigH - 5));

                $pdf->Image($absSignPath, $sigX, $sigY, $sigW, $sigH, 'PNG', '', '', false, 96, '', false, false, 0, false, false, false);

                $pdf->SetFont('helvetica', 'B', 6);
                $pdf->SetTextColor(40, 40, 40);
                $pdf->SetXY($sigX, $sigY + $sigH + 0.5);
                $pdf->Cell($sigW, 3, $reviewerName, 0, 0, 'C');
            }
        }

        // Overwrite original PDF
        $pdf->Output($absFilePath, 'F');

    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => 'PDF signing failed: ' . $e->getMessage()]);
        exit();
    }
}

// ── Save review to DB ────────────────────────────────────────────
$upsertStmt = $conn->prepare("
    INSERT INTO stage_approval_reviews
        (approval_id, reviewer_role, reviewed_by, reviewed_at, review_status, review_note)
    VALUES (?, ?, ?, NOW(), ?, ?)
    ON DUPLICATE KEY UPDATE
        reviewed_by   = VALUES(reviewed_by),
        reviewed_at   = NOW(),
        review_status = VALUES(review_status),
        review_note   = VALUES(review_note)
");
$upsertStmt->bind_param("isiss", $approval_id, $reviewerRole, $admin_id, $action, $note);
$upsertStmt->execute();

// ── Determine overall file status ────────────────────────────────
$required = $requiredApprovers[$stageName] ?? [];

$reviewsStmt = $conn->prepare("SELECT reviewer_role, review_status FROM stage_approval_reviews WHERE approval_id = ?");
$reviewsStmt->bind_param("i", $approval_id);
$reviewsStmt->execute();
$reviewsResult = $reviewsStmt->get_result();
$reviews = [];
while ($r = $reviewsResult->fetch_assoc()) {
    $reviews[$r['reviewer_role']] = $r['review_status'];
}

$hasRejection = false;
$allApproved = true;
$gmOmSlotHandled = false;

foreach ($required as $role) {
    if (in_array($role, ['general_manager', 'operational_manager'])) {
        if ($gmOmSlotHandled)
            continue;
        $gmOmSlotHandled = true;
        $gmApproved = isset($reviews['general_manager']) && $reviews['general_manager'] === 'approved';
        $omApproved = isset($reviews['operational_manager']) && $reviews['operational_manager'] === 'approved';
        $gmRejected = isset($reviews['general_manager']) && $reviews['general_manager'] === 'rejected';
        $omRejected = isset($reviews['operational_manager']) && $reviews['operational_manager'] === 'rejected';
        if ($gmRejected || $omRejected) {
            $hasRejection = true;
            $allApproved = false;
        } elseif (!$gmApproved && !$omApproved) {
            $allApproved = false;
        }
        continue;
    }
    if (!isset($reviews[$role])) {
        $allApproved = false;
    } elseif ($reviews[$role] === 'rejected') {
        $hasRejection = true;
        $allApproved = false;
    }
}

$fileStatus = $hasRejection ? 'rejected' : ($allApproved ? 'approved' : 'pending');

// ── Update stage_approvals ────────────────────────────────────────
$updateApprovalStmt = $conn->prepare("
    UPDATE stage_approvals
    SET approval_status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?
    WHERE id = ?
");
$updateApprovalStmt->bind_param("sisi", $fileStatus, $admin_id, $note, $approval_id);
$updateApprovalStmt->execute();

// ── Update project_tracker stage status ──────────────────────────
$stageId = $approval['stage_id'];

$allFilesStmt = $conn->prepare("SELECT approval_status FROM stage_approvals WHERE stage_id = ?");
$allFilesStmt->bind_param("i", $stageId);
$allFilesStmt->execute();
$allFilesResult = $allFilesStmt->get_result();

$totalFiles = 0;
$approvedFiles = 0;
$hasRejected = false;
while ($file = $allFilesResult->fetch_assoc()) {
    $totalFiles++;
    if ($file['approval_status'] === 'approved')
        $approvedFiles++;
    if ($file['approval_status'] === 'rejected')
        $hasRejected = true;
}

$newStageStatus = 'Ongoing';

$statusUpdateStmt = $conn->prepare("
    UPDATE project_tracker
    SET status = ?, updated_by = ?, updated_at = NOW()
    WHERE id = ?
");
$statusUpdateStmt->bind_param("sii", $newStageStatus, $admin_id, $stageId);
$statusUpdateStmt->execute();

echo json_encode([
    'success' => true,
    'message' => 'Review submitted successfully',
    'file_status' => $fileStatus,
    'new_stage_status' => $newStageStatus,
    'esign_applied' => ($action === 'approved' && $apply_sign),
]);