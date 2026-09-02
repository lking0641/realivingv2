<?php
// stage_files_data.php
// Primary data fetching for the Stage Files page.
// Requires: $conn, $admin_id, $client_id, $stage_id, $stage,
//           and stage_files_config.php arrays already loaded.
// Produces: $userInfo, $admin_role, $client, $isAccountFk,
//           $isApproval, $isFileUpload, $isAccounting, $isInternalPo,
//           $assignData, $stageStatus, $sf_tracker_mode,
//           $internalPoApproval, $poApprovedFiles, $bomApprovedFiles,
//           $files, $categories

// ── Admin info ──────────────────────────────────────────
$roleStmt = $conn->prepare("SELECT role, full_name, is_head FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();
$admin_role = $userInfo['role'];

// ── Client record ───────────────────────────────────────
$allowedRolesForAllClients = ['general_manager', 'operational_manager', 'designer', 'technical_designer', 'accounting', 'superadmin', 'project_coordinator'];
$canViewAllClients = in_array($admin_role, $allowedRolesForAllClients);

if ($canViewAllClients) {
    $cStmt = $conn->prepare("SELECT u.*, a.full_name as admin_name FROM user_info u LEFT JOIN account a ON u.accountaid_fk=a.id WHERE u.id=?");
    $cStmt->bind_param("i", $client_id);
} else {
    $cStmt = $conn->prepare("SELECT u.*, a.full_name as admin_name FROM user_info u LEFT JOIN account a ON u.accountaid_fk=a.id WHERE u.id=? AND u.accountaid_fk=?");
    $cStmt->bind_param("ii", $client_id, $admin_id);
}
$cStmt->execute();
$client = $cStmt->get_result()->fetch_assoc();
if (!$client)
    die("Access denied.");

// ── accountaid_fk check ─────────────────────────────────
$accountFkCheckStmt = $conn->prepare("SELECT accountaid_fk FROM user_info WHERE id = ?");
$accountFkCheckStmt->bind_param("i", $client_id);
$accountFkCheckStmt->execute();
$accountFkRow = $accountFkCheckStmt->get_result()->fetch_assoc();
$isAccountFk = ($admin_id == ($accountFkRow['accountaid_fk'] ?? null));

// ── Stage type flags (config + request derived, no permission logic) ──
$approvalStages = array_keys($approvalStageRoles);
$isApproval = in_array($stage, $approvalStages);
$isFileUpload = in_array($stage, $fileUploadStages);
$isAccounting = ($stage === 'Accounting (Order Processing)');
$isInternalPo = ($stage === 'Internal P.O to Accounting');

// ── Assigned staff for this client ──────────────────────
$assignCheckStmt = $conn->prepare("SELECT designer1_id, designer2_id, technical_designer_id, project_coordinator_id, accountaid_fk FROM user_info WHERE id=?");
$assignCheckStmt->bind_param("i", $client_id);
$assignCheckStmt->execute();
$assignData = $assignCheckStmt->get_result()->fetch_assoc();

// ── Stage status ─────────────────────────────────────────
$stStmt = $conn->prepare("SELECT status FROM project_tracker WHERE id=?");
$stStmt->bind_param("i", $stage_id);
$stStmt->execute();
$stageRow = $stStmt->get_result()->fetch_assoc();
$stageStatus = $stageRow ? $stageRow['status'] : 'Pending';

// ── Tracker mode (sequential / non-sequential) ──────────
$tracker_mode_stmt = $conn->prepare("SELECT tracker_mode FROM user_info WHERE id = ?");
$tracker_mode_stmt->bind_param("i", $client_id);
$tracker_mode_stmt->execute();
$tracker_mode_row = $tracker_mode_stmt->get_result()->fetch_assoc();
$sf_tracker_mode = $tracker_mode_row['tracker_mode'] ?? 'non-sequential';

// ── Internal P.O to Accounting: approval record ─────────
$internalPoApproval = null;
if ($isInternalPo && $stage_id) {
    $ipaStmt = $conn->prepare("SELECT ipa.*, 
        ac.full_name as accounting_reviewer_name,
        dc.full_name as designer_reviewer_name,
        req.full_name as requested_by_name
        FROM internal_po_approvals ipa
        LEFT JOIN account ac ON ipa.accounting_reviewed_by = ac.id
        LEFT JOIN account dc ON ipa.designer_reviewed_by = dc.id
        LEFT JOIN account req ON ipa.requested_by = req.id
        WHERE ipa.stage_id = ?
        ORDER BY ipa.id DESC LIMIT 1");
    $ipaStmt->bind_param("i", $stage_id);
    $ipaStmt->execute();
    $internalPoApproval = $ipaStmt->get_result()->fetch_assoc();
}

// ── PO approved files (for Accounting stage) ────────────
$poApprovedFiles = [];
if ($isAccounting) {
    $poStmt = $conn->prepare("
        SELECT sa.*, a.full_name as uploaded_by_name
        FROM stage_approvals sa
        LEFT JOIN account a ON sa.uploaded_by=a.id
        WHERE sa.stage_id=(SELECT id FROM project_tracker WHERE client_id=? AND stage_name='Purchase Order (Submit to accounting)' LIMIT 1)
          AND sa.approval_status='approved'
        ORDER BY sa.uploaded_at DESC
    ");
    $poStmt->bind_param("i", $client_id);
    $poStmt->execute();
    $poResult = $poStmt->get_result();
    while ($row = $poResult->fetch_assoc())
        $poApprovedFiles[] = $row;
}

// ── BOM approved files (for Purchase Order stage) ───────
$bomApprovedFiles = [];
if ($stage === 'Purchase Order (Submit to accounting)') {
    $bomStmt = $conn->prepare("
        SELECT sa.*, a.full_name as uploaded_by_name,
               COALESCE(bos.status, 'pending') as order_status
        FROM stage_approvals sa
        LEFT JOIN account a ON sa.uploaded_by = a.id
        LEFT JOIN bom_order_status bos ON bos.bom_approval_id = sa.id
        WHERE sa.stage_id = (
            SELECT id FROM project_tracker 
            WHERE client_id = ? AND stage_name = 'Bill of Materials (BOM)' LIMIT 1
        )
        AND sa.approval_status = 'approved'
        ORDER BY sa.uploaded_at DESC
    ");
    $bomStmt->bind_param("i", $client_id);
    $bomStmt->execute();
    $bomResult = $bomStmt->get_result();
    while ($row = $bomResult->fetch_assoc())
        $bomApprovedFiles[] = $row;
}

// ── Files for this stage + per-role reviews ─────────────
$files = [];
if ($stage_id) {
    $fStmt = $conn->prepare("
        SELECT sa.*, a.full_name as uploaded_by_name
        FROM stage_approvals sa
        LEFT JOIN account a ON sa.uploaded_by=a.id
        WHERE sa.stage_id=?
        ORDER BY sa.uploaded_at DESC
    ");
    $fStmt->bind_param("i", $stage_id);
    $fStmt->execute();
    $fResult = $fStmt->get_result();
    while ($row = $fResult->fetch_assoc()) {
        $row['role_reviews'] = [];
        if ($isApproval) {
            $rStmt = $conn->prepare("SELECT sar.*, a.full_name as reviewer_name, sar.reviewed_at FROM stage_approval_reviews sar LEFT JOIN account a ON sar.reviewed_by=a.id WHERE sar.approval_id=?");
            $rStmt->bind_param("i", $row['id']);
            $rStmt->execute();
            $rRes = $rStmt->get_result();
            while ($rev = $rRes->fetch_assoc())
                $row['role_reviews'][$rev['reviewer_role']] = $rev;
        }
        $files[] = $row;
    }
}

// ── Unique category list from labels ────────────────────
$categories = [];
foreach ($files as $f) {
    $cat = trim($f['label'] ?? '');
    if ($cat && !in_array($cat, $categories)) {
        $categories[] = $cat;
    }
}