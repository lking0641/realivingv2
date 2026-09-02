<?php
// stage_files_permissions.php
// Permission computations for the Stage Files page.
// Requires: $conn, $admin_id, $admin_role, $userInfo, $stage, $stage_id,
//           $isAccountFk, $isApproval, $isInternalPo, $assignData,
//           $approvalStageRoles (from stage_files_config.php)
// Produces: $permissions, $canUpdate, $canApprove, $isAssigned,
//           $canRequestInternalPoApproval, $canReviewInternalPoAccounting,
//           $canReviewInternalPoDesigner

// ── Can this admin update/upload for the current $stage? ───────────
$permissions = [];

if ($admin_role === 'sales') {
    // Sales: only use per-user stage_permissions table
    $pStmt = $conn->prepare("SELECT stage_name, can_update FROM stage_permissions WHERE admin_id=?");
    $pStmt->bind_param("i", $admin_id);
    $pStmt->execute();
    $pr = $pStmt->get_result();
    while ($p = $pr->fetch_assoc())
        $permissions[$p['stage_name']] = (bool) $p['can_update'];
    $canUpdate = isset($permissions[$stage]) ? $permissions[$stage] : false;

} elseif ($isAccountFk) {
    // accountaid_fk (non-sales): check BOTH tables, allow if either grants permission
    // First check role_stage_permissions
    $rolePermStmt = $conn->prepare("SELECT stage_name, can_update FROM role_stage_permissions WHERE role=?");
    $rolePermStmt->bind_param("s", $admin_role);
    $rolePermStmt->execute();
    $rolePermResult = $rolePermStmt->get_result();
    $rolePermissions = [];
    while ($p = $rolePermResult->fetch_assoc()) {
        $rolePermissions[$p['stage_name']] = (bool) $p['can_update'];
    }

    // Then check individual stage_permissions
    $userPermStmt = $conn->prepare("SELECT stage_name, can_update FROM stage_permissions WHERE admin_id=?");
    $userPermStmt->bind_param("i", $admin_id);
    $userPermStmt->execute();
    $userPermResult = $userPermStmt->get_result();
    $userPermissions = [];
    while ($p = $userPermResult->fetch_assoc()) {
        $userPermissions[$p['stage_name']] = (bool) $p['can_update'];
    }

    // Merge: true if either role permission OR individual permission allows it
    $allStageNames = array_unique(array_merge(array_keys($rolePermissions), array_keys($userPermissions)));
    foreach ($allStageNames as $sName) {
        $permissions[$sName] = ($rolePermissions[$sName] ?? false) || ($userPermissions[$sName] ?? false);
    }
    $canUpdate = isset($permissions[$stage]) ? $permissions[$stage] : false;

} else {
    // All other roles: only use role_stage_permissions
    $pStmt = $conn->prepare("SELECT stage_name, can_update FROM role_stage_permissions WHERE role=?");
    $pStmt->bind_param("s", $admin_role);
    $pStmt->execute();
    $pr = $pStmt->get_result();
    while ($p = $pr->fetch_assoc())
        $permissions[$p['stage_name']] = (bool) $p['can_update'];
    $canUpdate = isset($permissions[$stage]) ? $permissions[$stage] : false;
}

// ── Can this admin approve/reject files on this stage? ──────────────
$canApprove = false;
if ($isApproval) {
    $rolesForStage = $approvalStageRoles[$stage] ?? [];
    if ($admin_role === 'technical_designer') {
        if (in_array('technical_designer', $rolesForStage) && !empty($userInfo['is_head']))
            $canApprove = true;
    } elseif ($admin_role === 'designer') {
        if (in_array($stage, ['Rough Estimation', 'Quotation', 'Samples Submitted TDS/SDS']) && !empty($userInfo['is_head']))
            $canApprove = true;
    } elseif ($admin_role === 'accounting') {
        if ($stage === 'Purchase Order (Submit to accounting)')
            $canApprove = true;
    } else {
        if (in_array($admin_role, $rolesForStage))
            $canApprove = true;
    }
}

// ── Is this admin assigned to the client (designer/TD/coordinator/accountFk)? ──
$isAssigned = in_array($admin_id, array_filter([
    $assignData['designer1_id'] ?? null,
    $assignData['designer2_id'] ?? null,
    $assignData['technical_designer_id'] ?? null,
    $assignData['project_coordinator_id'] ?? null,
    $assignData['accountaid_fk'] ?? null,
]));

// ── Internal P.O to Accounting: who can request/review ──────────────
$canRequestInternalPoApproval = false;
$canReviewInternalPoAccounting = false;
$canReviewInternalPoDesigner = false;

if ($isInternalPo) {
    $canRequestInternalPoApproval = ($canUpdate && $isAssigned && $stage_id > 0);
    $canReviewInternalPoAccounting = ($admin_role === 'accounting');
    $canReviewInternalPoDesigner = ($admin_role === 'designer' && !empty($userInfo['is_head']));
}