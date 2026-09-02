<?php
// stage_files_config.php
// Static stage/role definitions for the Stage Files page.
// No DB calls, no session access — pure config.
// Edit this file when: adding a new stage, changing which roles must
// approve a given stage, or changing which stages are "file upload only".

/**
 * Roles allowed to review/approve each approval-type stage.
 * Also doubles as $requiredApproversList (same data, two names historically
 * used for two different purposes — kept as separate arrays here so nothing
 * downstream breaks, but they are literally identical).
 */
$approvalStageRoles = [
    'Rough Estimation' => ['designer', 'general_manager', 'operational_manager'],
    'Samples Submitted TDS/SDS' => ['designer', 'technical_designer', 'general_manager', 'operational_manager'],
    'Quotation' => ['designer', 'general_manager', 'operational_manager'],
    'Bill of Materials (BOM)' => ['technical_designer', 'general_manager', 'operational_manager'],
    'Purchase Order (Submit to accounting)' => ['accounting', 'general_manager', 'operational_manager'],
];

$requiredApproversList = $approvalStageRoles;

/**
 * Stages where the action is a plain file upload (no multi-role approval).
 */
$fileUploadStages = ['Reference', 'Internal P.O to Accounting', 'Handover'];

/**
 * Stages where EITHER General Manager OR Operational Manager approval
 * satisfies the "final approver" requirement (step 2 of a 2-step approval).
 * Previously duplicated in this codebase as $gmOmStages / $gmOmStages2.
 */
$gmOmStages = [
    'Rough Estimation',
    'Samples Submitted TDS/SDS',
    'Quotation',
    'Bill of Materials (BOM)',
    'Purchase Order (Submit to accounting)',
    'Production Data Submittals',
];

/**
 * For GM/OM dual-approval stages: which role(s) must approve FIRST
 * (step 1) before GM/OM (step 2) are allowed to act.
 * Previously duplicated as $sequentialStagesInfo / $seqInfo2.
 */
$sequentialStagesInfo = [
    'Rough Estimation' => ['designer'],
    'Samples Submitted TDS/SDS' => ['technical_designer'],
    'Quotation' => ['designer'],
    'Bill of Materials (BOM)' => ['technical_designer'],
    'Purchase Order (Submit to accounting)' => ['accounting', 'technical_designer'],
    'Production Data Submittals' => ['technical_designer'],
];

/**
 * Full ordered stage sequence — used to determine "previous stage" when
 * tracker_mode = 'sequential' (locks a stage until the one before it is Done).
 */
$all_stages_master = [
    'Rough Estimation',
    'Site Visit',
    '2D / 3D Layout',
    'Reference',
    'Samples Submitted TDS/SDS',
    'Quotation',
    'Internal P.O to Accounting',
    'Downpayment',
    'Cuttinglist',
    'Bill of Materials (BOM)',
    'Purchase Order (Submit to accounting)',
    'Accounting (Order Processing)',
    'Production Data Submittals',
    'Fabrication',
    'Delivery',
    'Installation',
    'BILLING',
    'Handover',
];

/**
 * Stages that are never locked by sequential mode (first 6 in the sequence).
 */
$alwaysUnlocked_master = [
    'Rough Estimation',
    'Site Visit',
    '2D / 3D Layout',
    'Reference',
    'Samples Submitted TDS/SDS',
    'Quotation',
];