<?php
// stage_files_mark_done.php
// Determines whether the current admin can mark this stage Done, or revert
// a Done stage back to Ongoing.
// Requires: $conn, $client_id, $admin_id, $admin_role, $stage, $stage_id,
//           $canUpdate, $stageStatus, $files, $isFileUpload, $isAccounting,
//           $isApproval, $internalPoApproval, $isAccountFk, $assignData
// Produces: $sfCanMarkDone, $sfCanCancelDone

$sfAssignStmt = $conn->prepare("SELECT designer1_id, designer2_id, technical_designer_id, project_coordinator_id FROM user_info WHERE id = ?");
$sfAssignStmt->bind_param("i", $client_id);
$sfAssignStmt->execute();
$sfAssignRow = $sfAssignStmt->get_result()->fetch_assoc();
$sfDesigner1Id = $sfAssignRow['designer1_id'] ?? null;
$sfDesigner2Id = $sfAssignRow['designer2_id'] ?? null;
$sfTechDesignId = $sfAssignRow['technical_designer_id'] ?? null;
$sfProjCoordId = $sfAssignRow['project_coordinator_id'] ?? null;

$sfCanMarkDone = false;
$sfCanCancelDone = false;

if ($stage === 'Reference') {
    $isRefUserSF = (
        $admin_id == $sfDesigner1Id ||
        $admin_id == $sfDesigner2Id ||
        $admin_id == ($assignData['accountaid_fk'] ?? null)
    );
    if ($isRefUserSF && $canUpdate && in_array($stageStatus, ['Pending', 'Ongoing'])) {
        $sfCanMarkDone = true;
    }
    if ($isRefUserSF && $canUpdate && $stageStatus === 'Done') {
        $sfCanCancelDone = true;
    }

} elseif (($isFileUpload || $isAccounting) && $canUpdate && $stageStatus === 'Ongoing' && !empty($files)) {
    if ($stage === 'Internal P.O to Accounting') {
        $ipoApproved = ($internalPoApproval && $internalPoApproval['overall_status'] === 'approved');
        $sfCanMarkDone = $ipoApproved && ($admin_id == $sfProjCoordId || $admin_role === 'sales' || $isAccountFk);

    } elseif ($stage === 'Handover') {
        $sfCanMarkDone = ($admin_id == $sfTechDesignId || $admin_id == $sfProjCoordId || $isAccountFk);

    } elseif ($isAccounting) {
        $poStatusStmt = $conn->prepare("SELECT status FROM project_tracker WHERE client_id = ? AND stage_name = 'Purchase Order (Submit to accounting)' LIMIT 1");
        $poStatusStmt->bind_param("i", $client_id);
        $poStatusStmt->execute();
        $poStatusRow = $poStatusStmt->get_result()->fetch_assoc();
        if (($poStatusRow['status'] ?? '') === 'Done') {
            $sfCanMarkDone = true;
        }
    }

} elseif ($isApproval && $canUpdate && $stageStatus === 'Ongoing' && !empty($files)) {
    $allApproved = true;
    foreach ($files as $f) {
        if (($f['approval_status'] ?? 'pending') !== 'approved') {
            $allApproved = false;
            break;
        }
    }
    if ($allApproved) {
        if ($stage === 'Purchase Order (Submit to accounting)') {
            $bomStatusStmt = $conn->prepare("SELECT status FROM project_tracker WHERE client_id = ? AND stage_name = 'Bill of Materials (BOM)' LIMIT 1");
            $bomStatusStmt->bind_param("i", $client_id);
            $bomStatusStmt->execute();
            $bomStatusRow = $bomStatusStmt->get_result()->fetch_assoc();
            if (($bomStatusRow['status'] ?? '') === 'Done') {
                $sfCanMarkDone = true;
            }

        } elseif ($stage === 'Accounting (Order Processing)') {
            $poStatusStmt = $conn->prepare("SELECT status FROM project_tracker WHERE client_id = ? AND stage_name = 'Purchase Order (Submit to accounting)' LIMIT 1");
            $poStatusStmt->bind_param("i", $client_id);
            $poStatusStmt->execute();
            $poStatusRow = $poStatusStmt->get_result()->fetch_assoc();
            if (($poStatusRow['status'] ?? '') === 'Done') {
                $sfCanMarkDone = true;
            }

        } else {
            $sfCanMarkDone = true;
        }
    }
}