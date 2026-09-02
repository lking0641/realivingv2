<?php

function getStagePendingApprovalCount($conn, $admin_id, $admin_role, $is_head, $stage_id)
{
    if (!$stage_id)
        return 0;
    // Check if this role can approve
    $approvalStageRoles = [
        'Rough Estimation' => ['designer', 'general_manager', 'operational_manager'],
        'Samples Submitted TDS/SDS' => ['designer', 'technical_designer', 'general_manager', 'operational_manager'],
        'Quotation' => ['designer', 'general_manager', 'operational_manager'],
        'Bill of Materials (BOM)' => ['technical_designer', 'general_manager', 'operational_manager'],
        'Purchase Order (Submit to accounting)' => ['accounting', 'general_manager', 'operational_manager'],
    ];
    // Find which stage this stage_id belongs to — check all
    $stStmt = $conn->prepare("SELECT stage_name FROM project_tracker WHERE id = ?");
    $stStmt->bind_param("i", $stage_id);
    $stStmt->execute();
    $stRow = $stStmt->get_result()->fetch_assoc();
    if (!$stRow)
        return 0;
    $stageName = $stRow['stage_name'];
    $rolesForStage = $approvalStageRoles[$stageName] ?? [];
    $canApprove = false;
    $allGmOmStages = [
        'Rough Estimation',
        'Samples Submitted TDS/SDS',
        'Quotation',
        'Bill of Materials (BOM)',
        'Purchase Order (Submit to accounting)',
    ];

    if ($admin_role === 'technical_designer') {
        if (in_array('technical_designer', $rolesForStage) && $is_head)
            $canApprove = true;
    } elseif ($admin_role === 'designer') {
        if (in_array($stageName, ['Rough Estimation', 'Quotation', 'Samples Submitted TDS/SDS']) && $is_head)
            $canApprove = true;
    } elseif ($admin_role === 'accounting') {
        if ($stageName === 'Purchase Order (Submit to accounting)')
            $canApprove = true;
    } elseif (in_array($admin_role, ['general_manager', 'operational_manager'])) {
        if (in_array($admin_role, $rolesForStage))
            $canApprove = true;
    } else {
        if (in_array($admin_role, $rolesForStage))
            $canApprove = true;
    }
    if (!$canApprove)
        return 0;

    // GM/OM: skip if other already approved (all sequential stages)
    if (in_array($stageName, $allGmOmStages) && in_array($admin_role, ['general_manager', 'operational_manager'])) {
        $otherRole = ($admin_role === 'general_manager') ? 'operational_manager' : 'general_manager';
        $stmt = $conn->prepare("
        SELECT COUNT(*) FROM stage_approvals sa
        WHERE sa.stage_id = ?
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
    ");
        $stmt->bind_param("iss", $stage_id, $admin_role, $otherRole);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_row()[0];
    }

    $stmt = $conn->prepare("
    SELECT COUNT(*) FROM stage_approvals sa
    WHERE sa.stage_id = ?
      AND sa.approval_status = 'pending'
      AND NOT EXISTS (
          SELECT 1 FROM stage_approval_reviews sar
          WHERE sar.approval_id = sa.id AND sar.reviewer_role = ?
      )
");
    $stmt->bind_param("is", $stage_id, $admin_role);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

function getLayoutPendingCount($conn, $admin_id, $client_id)
{
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

function getStageIcon($stage)
{
    $icons = [
        'Rough Estimation' => 'fa-ruler-combined',
        'Site Visit' => 'fa-map-marker-alt',
        '2D / 3D Layout' => 'fa-drafting-compass',
        'Reference' => 'fa-folder-open',
        'Samples Submitted TDS/SDS' => 'fa-vials',
        'Quotation' => 'fa-file-invoice-dollar',
        'Internal P.O to Accounting' => 'fa-file-signature',
        'Downpayment' => 'fa-coins',
        'Cuttinglist' => 'fa-cut',
        'Bill of Materials (BOM)' => 'fa-calculator',
        'Purchase Order (Submit to accounting)' => 'fa-shopping-cart',
        'Accounting (Order Processing)' => 'fa-receipt',
        'Production Data Submittals' => 'fa-industry',
        'Fabrication' => 'fa-tools',
        'Delivery' => 'fa-truck',
        'Installation' => 'fa-hard-hat',
        'BILLING' => 'fa-file-invoice',
        'Handover' => 'fa-key',
    ];
    return $icons[$stage] ?? 'fa-circle';
}

function getStageTypeBadge($stage, $approvalStages, $fileUploadStages, $autoStages)
{
    if (in_array($stage, $approvalStages))
        return ['label' => 'Approval Required', 'class' => 'badge-approval'];
    if (in_array($stage, $fileUploadStages))
        return ['label' => 'File Upload', 'class' => 'badge-upload'];
    if (in_array($stage, $autoStages))
        return ['label' => 'Auto-Tracked', 'class' => 'badge-auto'];
    return null;
}