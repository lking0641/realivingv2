<?php
//approve_reject_stage.php
session_start();
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$data = json_decode(file_get_contents('php://input'), true);

$approval_id = isset($data['approval_id']) ? intval($data['approval_id']) : 0;
$action = isset($data['action']) ? $data['action'] : '';
$note = isset($data['note']) ? trim($data['note']) : '';

if (!$approval_id || !in_array($action, ['approved', 'rejected'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit();
}

// Get reviewer info
$roleStmt = $conn->prepare("SELECT role, is_head FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$reviewerInfo = $roleStmt->get_result()->fetch_assoc();
$reviewerRole = $reviewerInfo['role'];
$isHead = (bool) ($reviewerInfo['is_head'] ?? false);

// Get approval record
$approvalStmt = $conn->prepare("SELECT * FROM stage_approvals WHERE id = ?");
$approvalStmt->bind_param("i", $approval_id);
$approvalStmt->execute();
$approval = $approvalStmt->get_result()->fetch_assoc();

if (!$approval) {
    echo json_encode(['success' => false, 'error' => 'Approval record not found']);
    exit();
}

// Define required approver roles per stage
$requiredApprovers = [
    'Rough Estimation' => ['designer', 'general_manager', 'operational_manager'],
    'Samples Submitted TDS/SDS' => ['designer', 'technical_designer', 'general_manager', 'operational_manager'],
    'Quotation' => ['designer', 'general_manager', 'operational_manager'],
    'Bill of Materials (BOM)' => ['technical_designer', 'general_manager', 'operational_manager'],
    'Purchase Order (Submit to accounting)' => ['accounting', 'general_manager', 'operational_manager'],
];

$stageName = $approval['stage_name'];
$allowedRoles = $requiredApprovers[$stageName] ?? [];

$canReview = in_array($reviewerRole, $allowedRoles);

// technical_designer: only heads can approve
if ($reviewerRole === 'technical_designer' && !$isHead) {
    $canReview = false;
}

// designer: only heads, only for Rough Estimation, Quotation, and Samples Submitted TDS/SDS
if ($reviewerRole === 'designer') {
    $canReview = (in_array($stageName, ['Rough Estimation', 'Quotation', 'Samples Submitted TDS/SDS']) && $isHead);
}

// accounting: only for Purchase Order
if ($reviewerRole === 'accounting') {
    $canReview = ($stageName === 'Purchase Order (Submit to accounting)');
}

// Define which stages use sequential step1 → GM/OM logic
// step1Role = who must approve first before GM/OM can act
$sequentialStages = [
    'Rough Estimation' => ['step1' => ['designer'], 'step1_label' => 'Head Designer'],
    'Samples Submitted TDS/SDS' => ['step1' => ['designer', 'technical_designer'], 'step1_label' => 'Head Designer and Technical Designer'],
    'Quotation' => ['step1' => ['designer'], 'step1_label' => 'Head Designer'],
    'Bill of Materials (BOM)' => ['step1' => ['technical_designer'], 'step1_label' => 'Technical Designer'],
    'Purchase Order (Submit to accounting)' => ['step1' => ['accounting'], 'step1_label' => 'Accounting'],
    'Production Data Submittals' => ['step1' => ['technical_designer'], 'step1_label' => 'Technical Designer'],
];

// For all sequential stages: block GM/OM if step1 roles have not all approved yet
if ($canReview && isset($sequentialStages[$stageName]) && in_array($reviewerRole, ['general_manager', 'operational_manager'])) {
    $step1Roles = $sequentialStages[$stageName]['step1'];
    $step1Label = $sequentialStages[$stageName]['step1_label'];
    foreach ($step1Roles as $s1Role) {
        $s1Stmt = $conn->prepare("
            SELECT COUNT(*) FROM stage_approval_reviews 
            WHERE approval_id = ? AND reviewer_role = ? AND review_status = 'approved'
        ");
        $s1Stmt->bind_param("is", $approval_id, $s1Role);
        $s1Stmt->execute();
        $s1Approved = (int) $s1Stmt->get_result()->fetch_row()[0];
        if ($s1Approved === 0) {
            echo json_encode(['success' => false, 'error' => "The {$step1Label} must approve this file first before GM/OM can review it."]);
            exit();
        }
    }
}

// For all sequential stages: GM/OM are interchangeable — block if the other already approved
if ($canReview && isset($sequentialStages[$stageName]) && in_array($reviewerRole, ['general_manager', 'operational_manager'])) {
    $otherRole = ($reviewerRole === 'general_manager') ? 'operational_manager' : 'general_manager';
    $otherStmt = $conn->prepare("
        SELECT COUNT(*) FROM stage_approval_reviews 
        WHERE approval_id = ? AND reviewer_role = ? AND review_status = 'approved'
    ");
    $otherStmt->bind_param("is", $approval_id, $otherRole);
    $otherStmt->execute();
    $otherAlreadyApproved = (int) $otherStmt->get_result()->fetch_row()[0];
    if ($otherAlreadyApproved > 0) {
        echo json_encode(['success' => false, 'error' => 'This file has already been approved by ' . str_replace('_', ' ', $otherRole) . '. Only one of GM/OM is required.']);
        exit();
    }
}

if (!$canReview) {
    echo json_encode(['success' => false, 'error' => 'You do not have permission to review this stage']);
    exit();
}

// Determine the effective role key to store
// (designer and technical_designer are only valid as heads, store as-is)
$effectiveRole = $reviewerRole;

// Upsert: insert or update this role's review for this approval
// Delete existing review for this role first, then insert fresh
$deleteStmt = $conn->prepare("
    DELETE FROM stage_approval_reviews 
    WHERE approval_id = ? AND reviewer_role = ?
");
$deleteStmt->bind_param("is", $approval_id, $effectiveRole);
$deleteStmt->execute();

$insertStmt = $conn->prepare("
    INSERT INTO stage_approval_reviews 
        (approval_id, reviewer_role, reviewed_by, reviewed_at, review_status, review_note)
    VALUES (?, ?, ?, NOW(), ?, ?)
");
$insertStmt->bind_param("isiss", $approval_id, $effectiveRole, $admin_id, $action, $note);
$insertStmt->execute();

// Now check overall approval status for this file
// Get all required roles for this stage
$required = $requiredApprovers[$stageName] ?? [];

// Get all reviews for this approval
$reviewsStmt = $conn->prepare("
    SELECT reviewer_role, review_status 
    FROM stage_approval_reviews 
    WHERE approval_id = ?
");
$reviewsStmt->bind_param("i", $approval_id);
$reviewsStmt->execute();
$reviewsResult = $reviewsStmt->get_result();

$reviews = [];
while ($r = $reviewsResult->fetch_assoc()) {
    $reviews[$r['reviewer_role']] = $r['review_status'];
}

// Determine file approval status:
// - If any role rejected → 'rejected'
// - If all required roles approved → 'approved'
// - Otherwise → 'pending'
$hasRejection = false;
$allApproved = true;

// All these stages use the GM/OM one-of logic
$gmOmStagesAll = [
    'Rough Estimation',
    'Samples Submitted TDS/SDS',
    'Quotation',
    'Bill of Materials (BOM)',
    'Purchase Order (Submit to accounting)',
    'Production Data Submittals'
];

$gmOmSlotHandled = false; // ensure we only process the GM/OM slot once per loop

foreach ($required as $role) {
    // GM/OM slot — only one needed
    if (in_array($stageName, $gmOmStagesAll) && in_array($role, ['general_manager', 'operational_manager'])) {
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
        // if either approved, this slot is satisfied — do nothing, allApproved stays true
        continue;
    }
    // All other individual roles
    if (!isset($reviews[$role])) {
        $allApproved = false;
    } elseif ($reviews[$role] === 'rejected') {
        $hasRejection = true;
        $allApproved = false;
    }
    // if approved, do nothing — allApproved stays true
}

if ($hasRejection) {
    $fileStatus = 'rejected';
} elseif ($allApproved) {
    $fileStatus = 'approved';
} else {
    $fileStatus = 'pending';
}

error_log("DEBUG approval_id=$approval_id stage=$stageName reviews=" . json_encode($reviews) . " allApproved=" . ($allApproved ? 'true' : 'false') . " hasRejection=" . ($hasRejection ? 'true' : 'false') . " fileStatus=$fileStatus");

// Update stage_approvals overall status
// Also store the latest reviewer info for display
$updateApprovalStmt = $conn->prepare("
    UPDATE stage_approvals 
    SET approval_status = ?,
        reviewed_by = ?,
        reviewed_at = NOW(),
        review_note = ?
    WHERE id = ?
");
$updateApprovalStmt->bind_param("sisi", $fileStatus, $admin_id, $note, $approval_id);
$updateApprovalStmt->execute();

// Now determine stage status based on ALL files for this stage
$stageId = $approval['stage_id'];

$allFilesStmt = $conn->prepare("
    SELECT approval_status FROM stage_approvals WHERE stage_id = ?
");
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

// When ALL files are fully approved, set to Ongoing so assigned user can manually mark Done
// Stage never auto-completes to Done from approval — user must confirm completion
if ($totalFiles > 0 && $approvedFiles === $totalFiles) {
    $newStageStatus = 'Ongoing';
} elseif ($approvedFiles > 0 || $hasRejected) {
    $newStageStatus = 'Ongoing';
} else {
    $newStageStatus = 'Ongoing'; // has files = at least ongoing
}

// Update project_tracker stage status
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
    'DEBUG_reviews' => $reviews,
    'DEBUG_allApproved' => $allApproved,
    'DEBUG_hasRejection' => $hasRejection,
    'DEBUG_required' => $required,
    'DEBUG_stageName' => $stageName
]);