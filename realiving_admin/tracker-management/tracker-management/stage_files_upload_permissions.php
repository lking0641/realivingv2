<?php
// stage_files_upload_permissions.php
// Determines whether the current admin can upload/submit a file for $stage.
// Requires: $canUpdate, $isAssigned, $isAccounting, $stage, $stage_id,
//           $admin_id, $assignData, $bomApprovedFiles, $poApprovedFiles, $stageStatus
// Produces: $canUpload

if ($stage === 'Purchase Order (Submit to accounting)') {
    // Requires at least one approved BOM
    $canUpload = ($canUpdate && $isAssigned) && $stage_id && !empty($bomApprovedFiles);

} elseif ($stage === 'Reference') {
    $isReferenceAssignedSF = (
        $admin_id == ($assignData['designer1_id'] ?? null) ||
        $admin_id == ($assignData['designer2_id'] ?? null) ||
        $admin_id == ($assignData['accountaid_fk'] ?? null)
    );
    $canUpload = $isReferenceAssignedSF && $canUpdate && $stage_id;

} elseif ($isAccounting) {
    $canUpload = ($canUpdate && $isAssigned) && !empty($poApprovedFiles) && $stageStatus !== 'Done';

} else {
    $canUpload = ($canUpdate && $isAssigned) && $stage_id;
}