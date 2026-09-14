<?php
// notification_counts.php
// Shared counting functions used by both the notification bell (get_notifications.php)
// and the push notification cron checker.

function getClientRejectedSiteVisits($conn, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM site_visit 
        WHERE client_id = ? AND approval_status = 'Rejected'
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

function getClientPendingPaymentProofs($conn, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM payment_proofs pp
        JOIN payment_schedule ps ON ps.id = pp.payment_id
        JOIN payment_accounting_reviews par ON par.payment_id = pp.payment_id
        WHERE ps.client_id = ?
          AND par.review_status = 'pending'
          AND ps.accounting_status = 'pending_review'
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

function getClientMissingPoCount($conn, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM stage_approvals sa
        WHERE sa.stage_id = (
            SELECT id FROM project_tracker 
            WHERE client_id = ? AND stage_name = 'Bill of Materials (BOM)' LIMIT 1
        )
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
    $stmt->bind_param("iii", $client_id, $client_id, $client_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

function getClientApprovedPoNotOrderedCount($conn, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM stage_approvals sa
        JOIN project_tracker pt ON sa.stage_id = pt.id
        LEFT JOIN bom_order_status bos ON bos.bom_approval_id = sa.linked_bom_id
        WHERE pt.client_id = ?
          AND pt.stage_name = 'Purchase Order (Submit to accounting)'
          AND sa.approval_status = 'approved'
          AND (bos.status IS NULL OR bos.status IN ('pending', 'partially_ordered'))
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

function getClientRejectedFilesForUploader($conn, $admin_id, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM stage_approvals sa
        INNER JOIN project_tracker pt ON sa.stage_id = pt.id
        WHERE pt.client_id = ?
          AND sa.uploaded_by = ?
          AND sa.approval_status = 'rejected'
    ");
    $stmt->bind_param("ii", $client_id, $admin_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

function getClientPendingInternalPO($conn, $admin_id, $admin_role, $is_head, $client_id)
{
    if (!in_array($admin_role, ['accounting', 'designer']))
        return 0;
    if ($admin_role === 'designer' && !$is_head)
        return 0;

    if ($admin_role === 'accounting') {
        $stmt = $conn->prepare("
            SELECT ipa.id
            FROM internal_po_approvals ipa
            JOIN project_tracker pt ON ipa.stage_id = pt.id
            WHERE pt.client_id = ?
              AND ipa.overall_status = 'pending'
              AND ipa.accounting_status = 'pending'
            LIMIT 1
        ");
        $stmt->bind_param("i", $client_id);
    } else {
        $stmt = $conn->prepare("
            SELECT ipa.id
            FROM internal_po_approvals ipa
            JOIN project_tracker pt ON ipa.stage_id = pt.id
            WHERE pt.client_id = ?
              AND ipa.overall_status = 'pending'
              AND ipa.accounting_status = 'approved'
              AND ipa.designer_status = 'pending'
            LIMIT 1
        ");
        $stmt->bind_param("i", $client_id);
    }

    $stmt->execute();
    return (int) $stmt->get_result()->num_rows;
}

function getClientPendingApprovalsForUser($conn, $admin_id, $admin_role, $is_head, $client_id)
{
    $approvalStageRoles = [
        'Rough Estimation' => ['designer', 'general_manager', 'operational_manager'],
        'Samples Submitted TDS/SDS' => ['designer', 'technical_designer', 'general_manager', 'operational_manager'],
        'Quotation' => ['designer', 'general_manager', 'operational_manager'],
        'Bill of Materials (BOM)' => ['technical_designer', 'general_manager', 'operational_manager'],
        'Purchase Order (Submit to accounting)' => ['accounting', 'general_manager', 'operational_manager'],
    ];

    $total = 0;
    foreach ($approvalStageRoles as $stageName => $rolesAllowed) {
        $canApprove = false;
        if ($admin_role === 'technical_designer') {
            if (in_array('technical_designer', $rolesAllowed) && $is_head)
                $canApprove = true;
        } elseif ($admin_role === 'designer') {
            if (in_array($stageName, ['Quotation', 'Rough Estimation', 'Samples Submitted TDS/SDS']) && $is_head)
                $canApprove = true;
        } else {
            if (in_array($admin_role, $rolesAllowed))
                $canApprove = true;
        }
        if (!$canApprove)
            continue;

        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM stage_approvals sa
            INNER JOIN project_tracker pt ON sa.stage_id = pt.id
            WHERE pt.client_id = ?
              AND pt.stage_name = ?
              AND sa.approval_status = 'pending'
              AND NOT EXISTS (
                  SELECT 1 FROM stage_approval_reviews sar
                  WHERE sar.approval_id = sa.id
                    AND sar.reviewer_role = ?
              )
        ");
        $stmt->bind_param("iss", $client_id, $stageName, $admin_role);
        $stmt->execute();
        $total += (int) $stmt->get_result()->fetch_row()[0];
    }

    $layoutStmt = $conn->prepare("
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
    $layoutStmt->bind_param("ii", $client_id, $admin_id);
    $layoutStmt->execute();
    $total += (int) $layoutStmt->get_result()->fetch_row()[0];

    return $total;
}