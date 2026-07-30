<?php
// get_user_notifications.php
session_start();
include $includes ['connection'];

if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$admin_id = $_SESSION['admin_id'];

$roleStmt = $conn->prepare("SELECT role, full_name, is_head FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();
$currentRole = $userInfo['role'];
$isHeadUser = (bool) ($userInfo['is_head'] ?? false);

// ── Roles allowed to see notifications at all ─────────────────────────────
$allowedRoles = ['general_manager', 'operational_manager', 'designer', 'technical_designer', 'accounting', 'superadmin', 'project_coordinator', 'sales'];
if (!in_array($currentRole, $allowedRoles)) {
    header('Content-Type: application/json');
    echo json_encode(['notifications' => [], 'total' => 0]);
    exit();
}

$isManagerRole = in_array($currentRole, ['general_manager', 'operational_manager']);
$isSalesRole = ($currentRole === 'sales');

// ── Helper: build link to the relevant stage_files.php / tracker page ─────
function buildStageLink($client_id, $stage_id, $stage_name, $isManagerRole = false)
{
    if ($isManagerRole) {
        return BASE_URL . "manager-stage-files?client_id={$client_id}&stage_id={$stage_id}&stage=" . urlencode($stage_name);
    }
    return BASE_URL . "stage-files?client_id={$client_id}&stage_id={$stage_id}&stage=" . urlencode($stage_name);
}
function buildTrackerLink($client_id, $isManagerRole = false)
{
    if ($isManagerRole) {
        return BASE_URL . "manager-project-detail?client_id={$client_id}";
    }
    return BASE_URL . "unified-project-tracker?client_id={$client_id}";
}

// ── Build client ID + name list (respecting assignment filter) ────────────
$needsAssignmentFilter = (
    $currentRole === 'project_coordinator' ||
    ($currentRole === 'designer' && !$isHeadUser) ||
    ($currentRole === 'technical_designer' && !$isHeadUser)
);

if ($isSalesRole) {
    // Sales: clients where accountaid_fk = this admin
    $stmt = $conn->prepare("
        SELECT id, clientname, nameproject FROM user_info 
        WHERE accountaid_fk = ?
          AND account_status != 'Finished'
    ");
    $stmt->bind_param("i", $admin_id);
} elseif ($needsAssignmentFilter) {
    $stmt = $conn->prepare("
        SELECT id, clientname, nameproject FROM user_info 
        WHERE account_status != 'Finished'
          AND (designer1_id = ? OR designer2_id = ? OR technical_designer_id = ? OR project_coordinator_id = ?)
    ");
    $stmt->bind_param("iiii", $admin_id, $admin_id, $admin_id, $admin_id);
} else {
    $stmt = $conn->prepare("SELECT id, clientname, nameproject FROM user_info WHERE account_status != 'Finished'");
}
$stmt->execute();
$clientsResult = $stmt->get_result();
$clients = [];
while ($row = $clientsResult->fetch_assoc()) {
    $clients[$row['id']] = $row;
}

$notifications = [];

// ── 1. Pending stage approvals (Rough Estimation, BOM, Quotation, etc.) ───
$approvalStageRoles = [
    'Rough Estimation' => ['designer', 'general_manager', 'operational_manager'],
    'Samples Submitted TDS/SDS' => ['designer', 'technical_designer', 'general_manager', 'operational_manager'],
    'Quotation' => ['designer', 'general_manager', 'operational_manager'],
    'Bill of Materials (BOM)' => ['technical_designer', 'general_manager', 'operational_manager'],
    'Purchase Order (Submit to accounting)' => ['accounting', 'general_manager', 'operational_manager'],
];

// Step-1 roles that must approve BEFORE GM/OM's turn becomes active
$gmOmStep1Map = [
    'Rough Estimation' => ['designer'],
    'Samples Submitted TDS/SDS' => ['designer', 'technical_designer'],
    'Quotation' => ['designer'],
    'Bill of Materials (BOM)' => ['technical_designer'],
    'Purchase Order (Submit to accounting)' => ['accounting'],
];

foreach ($clients as $cid => $cinfo) {
    // Sales is not an approver role — skip approval stages entirely for sales
    if (!$isSalesRole) {
        foreach ($approvalStageRoles as $stageName => $rolesAllowed) {
            $canApprove = false;
            if ($currentRole === 'technical_designer') {
                if (in_array('technical_designer', $rolesAllowed) && $isHeadUser)
                    $canApprove = true;
            } elseif ($currentRole === 'designer') {
                if (in_array($stageName, ['Quotation', 'Rough Estimation', 'Samples Submitted TDS/SDS']) && $isHeadUser)
                    $canApprove = true;
            } else {
                if (in_array($currentRole, $rolesAllowed))
                    $canApprove = true;
            }
            if (!$canApprove)
                continue;

            if ($isManagerRole) {
                // ── GM/OM sequential logic: only notify after step-1 roles approved,
                //    and only if the OTHER GM/OM hasn't already approved it ──────────
                $otherRole = ($currentRole === 'general_manager') ? 'operational_manager' : 'general_manager';
                $step1Roles = $gmOmStep1Map[$stageName] ?? [];

                $step1Clauses = '';
                foreach ($step1Roles as $s1r) {
                    $step1Clauses .= "
                      AND EXISTS (
                          SELECT 1 FROM stage_approval_reviews sar_s1
                          WHERE sar_s1.approval_id = sa.id
                            AND sar_s1.reviewer_role = '{$s1r}'
                            AND sar_s1.review_status = 'approved'
                      )";
                }

                $stmt = $conn->prepare("
                    SELECT sa.id, sa.label, sa.file_name, sa.uploaded_at, pt.id as stage_id
                    FROM stage_approvals sa
                    INNER JOIN project_tracker pt ON sa.stage_id = pt.id
                    WHERE pt.client_id = ?
                      AND pt.stage_name = ?
                      AND sa.approval_status = 'pending'
                      AND NOT EXISTS (
                          SELECT 1 FROM stage_approval_reviews sar
                          WHERE sar.approval_id = sa.id AND sar.reviewer_role = ?
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM stage_approval_reviews sar2
                          WHERE sar2.approval_id = sa.id AND sar2.reviewer_role = ?
                          AND sar2.review_status = 'approved'
                      )
                      {$step1Clauses}
                    ORDER BY sa.uploaded_at DESC
                ");
                $stmt->bind_param("isss", $cid, $stageName, $currentRole, $otherRole);
            } else {
                $stmt = $conn->prepare("
                    SELECT sa.id, sa.label, sa.file_name, sa.uploaded_at, pt.id as stage_id
                    FROM stage_approvals sa
                    INNER JOIN project_tracker pt ON sa.stage_id = pt.id
                    WHERE pt.client_id = ?
                      AND pt.stage_name = ?
                      AND sa.approval_status = 'pending'
                      AND NOT EXISTS (
                          SELECT 1 FROM stage_approval_reviews sar
                          WHERE sar.approval_id = sa.id
                            AND sar.reviewer_role = ?
                      )
                    ORDER BY sa.uploaded_at DESC
                ");
                $stmt->bind_param("iss", $cid, $stageName, $currentRole);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $notifications[] = [
                    'type' => 'stage_approval',
                    'icon' => 'fa-stamp',
                    'color' => 'amber',
                    'client_id' => $cid,
                    'client_name' => $cinfo['clientname'],
                    'title' => $stageName . ' needs your approval',
                    'subtitle' => $cinfo['clientname'] . ' · ' . htmlspecialchars($row['label'] ?: $row['file_name']),
                    'link' => buildStageLink($cid, $row['stage_id'], $stageName, $isManagerRole),
                    'created_at' => $row['uploaded_at'],
                ];
            }
        }
    }

    // ── 2. Pending 2D/3D layout approvals (not applicable to sales) ───────────
    if (!$isSalesRole) {
        $layoutStmt = $conn->prepare("
            SELECT la.id, la.area, la.room_unit_number, la.requested_at
            FROM layout_approvals la
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
        $layoutStmt->bind_param("ii", $cid, $admin_id);
        $layoutStmt->execute();
        $layoutRes = $layoutStmt->get_result();
        while ($row = $layoutRes->fetch_assoc()) {
            $areaLabel = $row['area'] . (!empty($row['room_unit_number']) ? ' (Unit ' . $row['room_unit_number'] . ')' : '');
            $notifications[] = [
                'type' => 'layout_approval',
                'icon' => 'fa-pencil-ruler',
                'color' => 'amber',
                'client_id' => $cid,
                'client_name' => $cinfo['clientname'],
                'title' => '2D/3D Layout needs your approval',
                'subtitle' => $cinfo['clientname'] . ' · ' . htmlspecialchars($areaLabel),
                'link' => BASE_URL . "designer-attachment-upload?client_id={$cid}&area=" . urlencode($row['area']),
                'created_at' => $row['requested_at'],
            ];
        }
    }

    // ── 2b. Rejected 2D/3D layout — notify the assigned designer ─────────
    if (!$isSalesRole && in_array($currentRole, ['designer', 'technical_designer'])) {
        $rejLayoutStmt = $conn->prepare("
            SELECT la.id, la.area, la.responded_at
            FROM layout_approvals la
            WHERE la.client_id = ? 
              AND la.status = 'rejected'
              AND EXISTS (
                  SELECT 1 FROM user_info u
                  WHERE u.id = la.client_id
                    AND (u.designer1_id = ? OR u.designer2_id = ? OR u.technical_designer_id = ?)
              )
            ORDER BY la.responded_at DESC
        ");
        $rejLayoutStmt->bind_param("iiii", $cid, $admin_id, $admin_id, $admin_id);
        $rejLayoutStmt->execute();
        $rejLayoutRes = $rejLayoutStmt->get_result();
        while ($row = $rejLayoutRes->fetch_assoc()) {
            $notifications[] = [
                'type'        => 'rejected_layout',
                'icon'        => 'ri-pencil-ruler-2-line',
                'color'       => 'red',
                'client_id'   => $cid,
                'client_name' => $cinfo['clientname'],
                'title'       => '2D/3D layout was rejected',
                'subtitle'    => $cinfo['clientname'] . ' · ' . htmlspecialchars($row['area']),
                'link'        => BASE_URL . "designer-attachment-upload?client_id={$cid}&area=" . urlencode($row['area']),
                'created_at'  => $row['responded_at'],
            ];
        }
    }

    // ── 3. Internal P.O pending review (accounting / head designer only) ──────
    if (!$isManagerRole && !$isSalesRole && in_array($currentRole, ['accounting', 'designer']) && !($currentRole === 'designer' && !$isHeadUser)) {
        if ($currentRole === 'accounting') {
            $ipoStmt = $conn->prepare("
                SELECT ipa.id, ipa.requested_at, pt.id as stage_id
                FROM internal_po_approvals ipa
                JOIN project_tracker pt ON ipa.stage_id = pt.id
                WHERE pt.client_id = ?
                  AND ipa.overall_status = 'pending'
                  AND ipa.accounting_status = 'pending'
            ");
            $ipoStmt->bind_param("i", $cid);
        } else {
            $ipoStmt = $conn->prepare("
                SELECT ipa.id, ipa.requested_at, pt.id as stage_id
                FROM internal_po_approvals ipa
                JOIN project_tracker pt ON ipa.stage_id = pt.id
                WHERE pt.client_id = ?
                  AND ipa.overall_status = 'pending'
                  AND ipa.accounting_status = 'approved'
                  AND ipa.designer_status = 'pending'
            ");
            $ipoStmt->bind_param("i", $cid);
        }
        $ipoStmt->execute();
        $ipoRes = $ipoStmt->get_result();
        while ($row = $ipoRes->fetch_assoc()) {
            $notifications[] = [
                'type' => 'internal_po',
                'icon' => 'fa-file-signature',
                'color' => 'amber',
                'client_id' => $cid,
                'client_name' => $cinfo['clientname'],
                'title' => 'Internal P.O needs your review',
                'subtitle' => $cinfo['clientname'],
                'link' => buildStageLink($cid, $row['stage_id'], 'Internal P.O to Accounting', $isManagerRole),
                'created_at' => $row['requested_at'],
            ];
        }
    }

    // ── 4. Rejected uploads — applies to sales too (uploader-based) ───────────
    if (!$isManagerRole) {
        $rejStmt = $conn->prepare("
            SELECT sa.id, sa.label, sa.file_name, sa.reviewed_at, pt.id as stage_id, pt.stage_name
            FROM stage_approvals sa
            INNER JOIN project_tracker pt ON sa.stage_id = pt.id
            WHERE pt.client_id = ?
              AND sa.uploaded_by = ?
              AND sa.approval_status = 'rejected'
            ORDER BY sa.reviewed_at DESC
        ");
        $rejStmt->bind_param("ii", $cid, $admin_id);
        $rejStmt->execute();
        $rejRes = $rejStmt->get_result();
        while ($row = $rejRes->fetch_assoc()) {
            $notifications[] = [
                'type' => 'rejected_upload',
                'icon' => 'fa-times-circle',
                'color' => 'red',
                'client_id' => $cid,
                'client_name' => $cinfo['clientname'],
                'title' => 'Your file was rejected',
                'subtitle' => $cinfo['clientname'] . ' · ' . htmlspecialchars($row['label'] ?: $row['file_name']) . ' (' . $row['stage_name'] . ')',
                'link' => buildStageLink($cid, $row['stage_id'], $row['stage_name'], $isManagerRole),
                'created_at' => $row['reviewed_at'],
            ];
        }
    }

    // ── 5. Pending payment proofs (accounting only) ───────────
    if (in_array($currentRole, ['accounting'])) {
        $payStmt = $conn->prepare("
            SELECT pp.id, pp.uploaded_at, ps.payment_type
            FROM payment_proofs pp
            JOIN payment_schedule ps ON ps.id = pp.payment_id
            JOIN payment_accounting_reviews par ON par.payment_id = pp.payment_id
            WHERE ps.client_id = ?
              AND par.review_status = 'pending'
              AND ps.accounting_status = 'pending_review'
            ORDER BY pp.uploaded_at DESC
        ");
        $payStmt->bind_param("i", $cid);
        $payStmt->execute();
        $payRes = $payStmt->get_result();
        while ($row = $payRes->fetch_assoc()) {
            $notifications[] = [
                'type' => 'payment_proof',
                'icon' => 'fa-file-invoice-dollar',
                'color' => 'amber',
                'client_id' => $cid,
                'client_name' => $cinfo['clientname'],
                'title' => 'Payment proof needs review',
                'subtitle' => $cinfo['clientname'] . ' · ' . htmlspecialchars($row['payment_type'] ?? 'Payment'),
                'link' => buildTrackerLink($cid, $isManagerRole),
                'created_at' => $row['uploaded_at'],
            ];
        }
    }

    // ── 5b. Rejected payment proofs YOU submitted — sales (and any uploader) ──
    if ($isSalesRole) {
        $rejPayStmt = $conn->prepare("
            SELECT pp.id, pp.uploaded_at, ps.payment_type
            FROM payment_proofs pp
            JOIN payment_schedule ps ON ps.id = pp.payment_id
            JOIN payment_accounting_reviews par ON par.payment_id = pp.payment_id
            WHERE ps.client_id = ?
              AND pp.uploaded_by = ?
              AND par.review_status = 'rejected'
              AND ps.accounting_status = 'rejected'
            ORDER BY pp.uploaded_at DESC
        ");
        $rejPayStmt->bind_param("ii", $cid, $admin_id);
        $rejPayStmt->execute();
        $rejPayRes = $rejPayStmt->get_result();
        while ($row = $rejPayRes->fetch_assoc()) {
            $notifications[] = [
                'type' => 'rejected_payment_proof',
                'icon' => 'fa-file-invoice-dollar',
                'color' => 'red',
                'client_id' => $cid,
                'client_name' => $cinfo['clientname'],
                'title' => 'Your payment proof was rejected',
                'subtitle' => $cinfo['clientname'] . ' · ' . htmlspecialchars($row['payment_type'] ?? 'Payment'),
                'link' => buildTrackerLink($cid, $isManagerRole),
                'created_at' => $row['uploaded_at'],
            ];
        }
    }

    // ── 6. Missing PO — project coordinator / sales only ───────────────────
    if (!$isManagerRole && in_array($currentRole, ['project_coordinator', 'sales'])) {
        $missStmt = $conn->prepare("
            SELECT sa.id, sa.label, sa.file_name, sa.uploaded_at, pt.id as stage_id
            FROM stage_approvals sa
            JOIN project_tracker pt ON sa.stage_id = pt.id
            WHERE pt.client_id = ?
              AND pt.stage_name = 'Bill of Materials (BOM)'
              AND sa.approval_status = 'approved'
              AND NOT EXISTS (
                  SELECT 1 FROM stage_approvals po
                  WHERE po.client_id = ?
                    AND po.stage_id = (
                        SELECT id FROM project_tracker 
                        WHERE client_id = ? AND stage_name = 'Purchase Order (Submit to accounting)' LIMIT 1
                    )
                    AND po.linked_bom_id = sa.id
              )
        ");
        $missStmt->bind_param("iii", $cid, $cid, $cid);
        $missStmt->execute();
        $missRes = $missStmt->get_result();
        while ($row = $missRes->fetch_assoc()) {
            $notifications[] = [
                'type' => 'missing_po',
                'icon' => 'fa-file-invoice-dollar',
                'color' => 'amber',
                'client_id' => $cid,
                'client_name' => $cinfo['clientname'],
                'title' => 'Approved BOM has no Purchase Order yet',
                'subtitle' => $cinfo['clientname'] . ' · ' . htmlspecialchars($row['label'] ?: $row['file_name']),
                'link' => buildStageLink($cid, $row['stage_id'], 'Purchase Order (Submit to accounting)', $isManagerRole),
                'created_at' => $row['uploaded_at'],
            ];
        }
    }

    // ── 7. Approved PO not yet ordered — project_coordinator only ─────────
    if ($currentRole === 'project_coordinator') {
        $poNotOrderedStmt = $conn->prepare("
            SELECT sa.id, sa.label, sa.file_name, sa.uploaded_at, pt.id as stage_id
            FROM stage_approvals sa
            JOIN project_tracker pt ON sa.stage_id = pt.id
            LEFT JOIN bom_order_status bos ON bos.bom_approval_id = sa.linked_bom_id
            WHERE pt.client_id = ?
              AND pt.stage_name = 'Purchase Order (Submit to accounting)'
              AND sa.approval_status = 'approved'
              AND (bos.status IS NULL OR bos.status IN ('pending', 'partially_ordered'))
        ");
        $poNotOrderedStmt->bind_param("i", $cid);
        $poNotOrderedStmt->execute();
        $poRes = $poNotOrderedStmt->get_result();
        while ($row = $poRes->fetch_assoc()) {
            $notifications[] = [
                'type' => 'po_not_ordered',
                'icon' => 'fa-shopping-cart',
                'color' => 'blue',
                'client_id' => $cid,
                'client_name' => $cinfo['clientname'],
                'title' => 'Approved PO not yet fully ordered',
                'subtitle' => $cinfo['clientname'] . ' · ' . htmlspecialchars($row['label'] ?: $row['file_name']),
                'link' => buildStageLink($cid, $row['stage_id'], 'Purchase Order (Submit to accounting)', $isManagerRole),
                'created_at' => $row['uploaded_at'],
            ];
        }
    }

    // ── 8. Rejected site visits — head designer only ──────────────────────────
if ($currentRole === 'designer' && $isHeadUser) {
    $svStmt = $conn->prepare("
        SELECT id, visit_date, created_at, approval_comment FROM site_visit 
        WHERE client_id = ? AND approval_status = 'Rejected'
        ORDER BY created_at DESC
    ");
    $svStmt->bind_param("i", $cid);
    $svStmt->execute();
    $svRes = $svStmt->get_result();
    while ($row = $svRes->fetch_assoc()) {
        $notifications[] = [
            'type'        => 'rejected_site_visit',
            'icon'        => 'ri-map-pin-line',
            'color'       => 'red',
            'client_id'   => $cid,
            'client_name' => $cinfo['clientname'],
            'title'       => 'Site visit was rejected',
            'subtitle'    => $cinfo['clientname'] . ' · '
                             . date('M d, Y', strtotime($row['visit_date']))
                             . (!empty($row['approval_comment']) ? ' — "' . htmlspecialchars($row['approval_comment']) . '"' : ''),
            'link'        => BASE_URL . "site-visit-manager?client_id={$cid}",
            'created_at'  => $row['created_at'],
        ];
    }
}

    // ── 9. Pending Site Visit approvals — GM/OM/superadmin only ────────────
    if (in_array($currentRole, ['general_manager', 'operational_manager', 'superadmin'])) {
        $svPendStmt = $conn->prepare("
            SELECT id, visit_date, created_at FROM site_visit
            WHERE client_id = ? AND approval_status = 'Pending'
            ORDER BY created_at DESC
        ");
        $svPendStmt->bind_param("i", $cid);
        $svPendStmt->execute();
        $svPendRes = $svPendStmt->get_result();
        while ($row = $svPendRes->fetch_assoc()) {
            $notifications[] = [
                'type' => 'site_visit_approval',
                'icon' => 'fa-map-marker-alt',
                'color' => 'amber',
                'client_id' => $cid,
                'client_name' => $cinfo['clientname'],
                'title' => 'Site Visit needs your approval',
                'subtitle' => $cinfo['clientname'],
                'link' => $isManagerRole
                    ? BASE_URL . "manager-site-visit-approval?client_id={$cid}"
                    : buildTrackerLink($cid, $isManagerRole),
                'created_at' => $row['created_at'],
            ];
        }
    }
}

// ── Sort newest first ──────────────────────────────────────────────────────
usort($notifications, function ($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

header('Content-Type: application/json');
echo json_encode([
    'notifications' => $notifications,
    'total' => count($notifications),
]);