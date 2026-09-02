<?php
// stage_files.php
include $includes ['mainbody'];
require_once __DIR__ . '/stage_files_helpers.php';

require_once __DIR__ . '/stage_files_config.php';

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$stage_id = isset($_GET['stage_id']) ? intval($_GET['stage_id']) : 0;
$stage = isset($_GET['stage']) ? trim($_GET['stage']) : '';

require_once __DIR__ . '/stage_files_data.php';

require_once __DIR__ . '/stage_files_permissions.php';
// $permissions, $canUpdate, $canApprove, $isAssigned,
// $canRequestInternalPoApproval, $canReviewInternalPoAccounting,
// $canReviewInternalPoDesigner all come from stage_files_permissions.php

require_once __DIR__ . '/stage_files_upload_permissions.php';
// $canUpload comes from stage_files_upload_permissions.php

require_once __DIR__ . '/stage_files_mark_done.php';
// $sfCanMarkDone, $sfCanCancelDone come from stage_files_mark_done.php

// $isApproval, $isFileUpload, $isAccounting, $stageStatus,
// $internalPoApproval, $isInternalPo come from stage_files_data.php

// Sequential mode lock: block access if previous stage is not done
// ($sf_tracker_mode comes from stage_files_data.php)
if ($sf_tracker_mode === 'sequential' && $stageStatus === 'Pending') {
    $isNonProjectSF = ($client['business_type'] ?? '') === 'Non-Project';

    $all_stages = $all_stages_master;

    if ($isNonProjectSF) {
        $all_stages = array_values(array_filter($all_stages, function ($s) {
            return $s !== 'Samples Submitted TDS/SDS';
        }));
    }

    // Always-unlocked stages (first 6 by original index, regardless of list size)
    $alwaysUnlocked = $alwaysUnlocked_master;
    // For Non-Project, Samples is removed so unlocked list adjusts too
    if ($isNonProjectSF) {
        $alwaysUnlocked = array_values(array_filter($alwaysUnlocked, function ($s) {
            return $s !== 'Samples Submitted TDS/SDS';
        }));
    }

    // If this stage is in the always-unlocked list, never block it
    if (!in_array($stage, $alwaysUnlocked)) {
        $current_idx = array_search($stage, $all_stages);
        if ($current_idx !== false && $current_idx > 0) {
            $prev_stage = $all_stages[$current_idx - 1];
            $prevStmt = $conn->prepare("SELECT status FROM project_tracker WHERE client_id = ? AND stage_name = ?");
            $prevStmt->bind_param("is", $client_id, $prev_stage);
            $prevStmt->execute();
            $prevRow = $prevStmt->get_result()->fetch_assoc();
            $prevStatus = $prevRow['status'] ?? 'Pending';
            if ($prevStatus === 'Pending') {
                header("Location: " . BASE_URL . "unified-project-tracker?client_id={$client_id}&locked=1");
                exit();
            }
        }
    }
}

// $poApprovedFiles, $bomApprovedFiles, $files, $categories all come from stage_files_data.php

$stageTypeLabel = $isApproval ? 'Approval Required' : ($isFileUpload ? 'File Upload' : ($isAccounting ? 'Delivery Receipt' : 'Files'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Files — <?= htmlspecialchars($stage) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <style>
        :root {
            --adm-bg: #F5F5F5;
            --adm-surface: #FFFFFF;
            --adm-ink: #0B0B0B;
            --adm-soft: #6B6B6B;
            --adm-muted: #9A9A9A;
            --adm-line: #E2E2E2;

            --ok-bg: #f0fdf4;
            --ok-line: #a7f3d0;
            --ok-ink: #065f46;
            --ok-accent: #10b981;

            --warn-bg: #fffbeb;
            --warn-line: #fde68a;
            --warn-ink: #92400e;
            --warn-accent: #f59e0b;

            --bad-bg: #fef2f2;
            --bad-line: #fecaca;
            --bad-ink: #991b1b;
            --bad-accent: #ef4444;

            --info-bg: #f0f9ff;
            --info-line: #bae6fd;
            --info-ink: #0369a1;
            --info-accent: #0ea5e9;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--adm-bg);
            color: var(--adm-ink);
        }

        .adm-eyebrow {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--adm-soft);
        }

        .adm-section-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--adm-ink);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .adm-section-label::after {
            content: "";
            flex: 1;
            height: 1px;
            background: var(--adm-line);
        }

        @keyframes adm-fade {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .adm-fade { animation: adm-fade .35s ease both; }

        @media (prefers-reduced-motion: reduce) {
            .adm-fade { animation: none; }
        }

        @keyframes shimmer {
            0% { background-position: 0% 0; }
            100% { background-position: 200% 0; }
        }

        /* ── Back link ─────────────────────────── */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--adm-soft);
        }
        .back-link:hover { color: var(--adm-ink); }

        /* ── Header badges ─────────────────────── */
        .hbadge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 600;
            padding: 5px 11px;
            border-radius: 999px;
            background: var(--adm-bg);
            border: 1px solid var(--adm-line);
            color: var(--adm-soft);
        }
        .hbadge.approval { background: var(--warn-bg); border-color: var(--warn-line); color: var(--warn-ink); }
        .hbadge.upload   { background: var(--info-bg); border-color: var(--info-line); color: var(--info-ink); }
        .hbadge.receipt  { background: var(--ok-bg); border-color: var(--ok-line); color: var(--ok-ink); }

        /* ── Category filter chips ─────────────── */
        .cat-btn {
            font-size: 12.5px;
            font-weight: 600;
            padding: 7px 14px;
            border-radius: 999px;
            border: 1px solid var(--adm-line);
            background: var(--adm-surface);
            color: var(--adm-soft);
            transition: all .15s ease;
        }
        .cat-btn:hover { border-color: var(--adm-ink); color: var(--adm-ink); }
        .cat-btn.active { background: var(--adm-ink); border-color: var(--adm-ink); color: #fff; }

        /* ── Buttons ────────────────────────────── */
        .btn-upload {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
            padding: 10px 18px;
            border-radius: 8px;
            background: var(--adm-ink);
            color: #fff;
            transition: opacity .15s ease;
        }
        .btn-upload:hover { opacity: .85; }
        .btn-upload:disabled { opacity: .5; cursor: not-allowed; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 7px 12px;
            border-radius: 7px;
            border: 1px solid var(--adm-line);
            background: var(--adm-surface);
            color: var(--adm-ink);
            transition: all .15s ease;
            white-space: nowrap;
        }
        .btn:hover { border-color: var(--adm-ink); }

        .btn-view    { background: var(--adm-bg); color: var(--adm-ink); }
        .btn-approve { background: var(--ok-bg); color: var(--ok-ink); border-color: var(--ok-line); }
        .btn-reject  { background: var(--bad-bg); color: var(--bad-ink); border-color: var(--bad-line); }
        .btn-delete  { background: var(--bad-bg); color: var(--bad-ink); border-color: var(--bad-line); }
        .btn-resubmit{ background: var(--info-bg); color: var(--info-ink); border-color: var(--info-line); }

        .btn-submit {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 13px; font-weight: 700; padding: 10px 18px;
            border-radius: 8px; background: var(--adm-ink); color: #fff;
        }
        .btn-submit:disabled { opacity: .6; cursor: not-allowed; }
        .btn-cancel {
            font-size: 13px; font-weight: 600; padding: 10px 16px;
            border-radius: 8px; color: var(--adm-soft);
        }
        .btn-cancel:hover { color: var(--adm-ink); }
        .btn-reject-confirm {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 13px; font-weight: 700; padding: 10px 18px;
            border-radius: 8px; background: var(--bad-accent); color: #fff;
        }

        /* ── File cards ─────────────────────────── */
        .file-card {
            background: var(--adm-surface);
            border: 1px solid var(--adm-line);
            border-left: 3px solid var(--adm-muted);
            border-radius: 10px;
            padding: 16px 18px;
            margin-bottom: 12px;
        }
        .file-card.approved { border-left-color: var(--ok-accent); }
        .file-card.rejected { border-left-color: var(--bad-accent); }
        .file-card.pending  { border-left-color: var(--warn-accent); }

        .file-row { display: flex; gap: 14px; align-items: flex-start; }
        .file-icon { font-size: 22px; margin-top: 2px; flex-shrink: 0; }
        .file-body { flex: 1; min-width: 0; }
        .file-label {
            font-size: 10.5px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .5px; color: var(--adm-soft); margin-bottom: 2px;
        }
        .file-name {
            font-size: 14px; font-weight: 600; color: var(--adm-ink);
            overflow-wrap: anywhere;
        }
        .file-meta {
            display: flex; gap: 14px; flex-wrap: wrap;
            font-size: 11.5px; color: var(--adm-muted); margin-top: 4px;
        }
        .file-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 10px; }

        .file-status {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 999px;
        }
        .file-status.approved { background: var(--ok-bg); color: var(--ok-ink); }
        .file-status.rejected { background: var(--bad-bg); color: var(--bad-ink); }
        .file-status.pending  { background: var(--warn-bg); color: var(--warn-ink); }

        .approval-badges { display: flex; flex-wrap: wrap; gap: 6px; }
        .apbadge {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 10.5px; font-weight: 600; padding: 4px 9px; border-radius: 999px;
            background: var(--adm-bg); border: 1px solid var(--adm-line); color: var(--adm-soft);
        }
        .apbadge.approved { background: var(--ok-bg); border-color: var(--ok-line); color: var(--ok-ink); }
        .apbadge.rejected { background: var(--bad-bg); border-color: var(--bad-line); color: var(--bad-ink); }
        .apbadge.pending  { background: var(--warn-bg); border-color: var(--warn-line); color: var(--warn-ink); }
        .apbadge-date { opacity: .75; font-weight: 500; }

        .reject-note {
            font-size: 12px; color: var(--bad-ink); background: var(--bad-bg);
            border: 1px solid var(--bad-line); border-radius: 7px; padding: 8px 11px; margin-top: 8px;
        }

        /* ── Empty state ────────────────────────── */
        .empty-state {
            text-align: center; padding: 48px 20px;
            background: var(--adm-surface); border: 1px dashed var(--adm-line); border-radius: 12px;
        }
        .empty-icon { font-size: 26px; color: var(--adm-muted); margin-bottom: 12px; }
        .empty-title { font-size: 14.5px; font-weight: 700; color: var(--adm-ink); margin-bottom: 4px; }
        .empty-sub { font-size: 12.5px; color: var(--adm-soft); }

        /* ── Upload mode toggle ─────────────────── */
        .upload-mode-toggle {
            display: flex; align-items: center; gap: 10px;
            background: var(--adm-bg); border: 1px solid var(--adm-line); border-radius: 9px;
            padding: 10px 12px; margin-bottom: 14px;
        }
        .mode-label { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--adm-ink); }
        .toggle-switch { position: relative; width: 40px; height: 22px; flex-shrink: 0; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute; inset: 0; background: var(--adm-line); border-radius: 999px; cursor: pointer; transition: .2s;
        }
        .toggle-slider::before {
            content: ""; position: absolute; width: 16px; height: 16px; left: 3px; top: 3px;
            background: #fff; border-radius: 50%; transition: .2s;
        }
        .toggle-switch input:checked + .toggle-slider { background: var(--adm-ink); }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(18px); }
        .mode-badge {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 999px;
        }
        .mode-badge.direct  { background: var(--info-bg); color: var(--info-ink); }
        .mode-badge.chunked { background: var(--warn-bg); color: var(--warn-ink); }

        /* ── Forms ──────────────────────────────── */
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 12.5px; font-weight: 600; color: var(--adm-ink); margin-bottom: 6px; }
        .form-input, .form-textarea {
            width: 100%; padding: 10px 12px; border: 1px solid var(--adm-line); border-radius: 8px;
            font-size: 13px; font-family: inherit; color: var(--adm-ink); background: var(--adm-surface);
        }
        .form-input:focus, .form-textarea:focus { outline: none; border-color: var(--adm-ink); }
        .form-textarea { min-height: 90px; resize: vertical; }
        .form-hint { font-size: 11.5px; color: var(--adm-muted); margin-top: 5px; }
        .form-error {
            display: none; font-size: 12.5px; color: var(--bad-ink); background: var(--bad-bg);
            border: 1px solid var(--bad-line); border-radius: 7px; padding: 8px 11px; margin-bottom: 12px;
        }

        /* ── Modals ─────────────────────────────── */
        .modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(11,11,11,.45);
            align-items: center; justify-content: center; z-index: 100; padding: 20px;
        }
        .modal-overlay.show { display: flex; }
        .modal-box {
            background: var(--adm-surface); border-radius: 14px; padding: 26px; width: 100%; max-width: 460px;
            max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 60px -20px rgba(11,11,11,.35);
        }
        .modal-title { font-size: 16.5px; font-weight: 700; color: var(--adm-ink); display: flex; align-items: center; gap: 9px; margin-bottom: 4px; }
        .modal-sub { font-size: 12.5px; color: var(--adm-soft); margin-bottom: 18px; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 6px; }

        /* ── Toast ──────────────────────────────── */
        .toast {
            position: fixed; bottom: 24px; right: 24px; z-index: 200;
            display: flex; align-items: center; gap: 10px;
            background: #fff; border-left: 3px solid var(--adm-ink);
            box-shadow: 0 12px 32px -14px rgba(11,11,11,.35);
            padding: 13px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; color: var(--adm-ink);
            transform: translateY(16px); opacity: 0; pointer-events: none; transition: all .25s ease;
        }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.error { border-left-color: var(--bad-accent); color: var(--bad-ink); }
    </style>
</head>

<body class="min-h-screen">
    <div class="max-w-4xl mx-auto w-full px-4 sm:px-6 lg:px-8 pt-10 pb-16">

        <!-- Back -->
        <a href="unified-project-tracker?client_id=<?= $client_id ?>" class="back-link mb-6 adm-fade">
            <i class="fas fa-arrow-left"></i> Back to Tracker
        </a>

        <!-- Header -->
        <div class="bg-white border border-[var(--adm-line)] rounded-xl p-6 mb-8 adm-fade">
            <div class="flex items-start justify-between flex-wrap gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-11 h-11 rounded-lg bg-[var(--adm-bg)] border border-[var(--adm-line)] flex items-center justify-center text-[17px] flex-shrink-0">
                        <i class="fas fa-paperclip"></i>
                    </div>
                    <div>
                        <div class="adm-eyebrow mb-1"><?= htmlspecialchars($stageTypeLabel) ?></div>
                        <h1 class="text-xl font-bold tracking-tight text-[var(--adm-ink)]"><?= htmlspecialchars($stage) ?></h1>
                        <p class="text-[13px] text-[var(--adm-soft)] mt-0.5">
                            <?= htmlspecialchars($client['clientname']) ?> · <?= htmlspecialchars($client['nameproject']) ?>
                        </p>
                    </div>
                </div>
                <div class="text-right flex-shrink-0">
                    <div class="text-[11px] text-[var(--adm-muted)] mb-1">Stage Status</div>
                    <div class="text-sm font-bold flex items-center gap-2 justify-end">
                        <?php if ($stageStatus === 'Done'): ?><i class="fas fa-check-circle" style="color:var(--ok-accent);"></i>
                        <?php elseif ($stageStatus === 'Ongoing'): ?><i class="fas fa-circle-notch fa-spin" style="color:var(--info-accent);"></i>
                        <?php else: ?><i class="fas fa-clock" style="color:var(--warn-accent);"></i>
                        <?php endif; ?>
                        <?= $stageStatus ?>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 mt-5">
                <?php if ($isApproval): ?>
                    <span class="hbadge approval"><i class="fas fa-stamp"></i> Approval Required</span>
                    <?php
                    $required = $requiredApproversList[$stage] ?? [];
                    if (in_array($stage, $gmOmStages)):
                        foreach ($required as $role):
                            if (in_array($role, ['general_manager', 'operational_manager'])) continue;
                            ?>
                            <span class="hbadge"><i class="fas fa-user-check"></i> <?= getRoleDisplayName($role) ?></span>
                        <?php endforeach; ?>
                        <span class="hbadge" style="background:#eef2ff;border-color:#c7d2fe;color:#4338ca;">
                            <i class="fas fa-user-check"></i> GM <em class="opacity-60 not-italic">or</em> OM <em class="opacity-60 not-italic">(one required)</em>
                        </span>
                    <?php else:
                        foreach ($required as $role): ?>
                            <span class="hbadge"><i class="fas fa-user-check"></i> <?= getRoleDisplayName($role) ?></span>
                        <?php endforeach; endif; ?>
                <?php elseif ($isFileUpload): ?>
                    <span class="hbadge upload"><i class="fas fa-file-upload"></i> File Upload Stage</span>
                <?php elseif ($isAccounting): ?>
                    <span class="hbadge receipt"><i class="fas fa-receipt"></i> Delivery Receipt</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Upload button -->
        <?php if ($canUpload && !$isAccounting && $stage !== 'Purchase Order (Submit to accounting)'): ?>
            <div class="mb-6 adm-fade">
                <button class="btn-upload" onclick="openUploadModal()">
                    <i class="fas fa-plus"></i>
                    <?= $isApproval ? 'Attach File for Approval' : 'Upload File' ?>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($stage === 'Purchase Order (Submit to accounting)' && empty($bomApprovedFiles)): ?>
            <div class="rounded-xl p-4 mb-6 flex items-center gap-3" style="background:var(--warn-bg);border:1px solid var(--warn-line);">
                <i class="fas fa-hourglass-half text-lg" style="color:var(--warn-accent);"></i>
                <div>
                    <div class="text-sm font-bold" style="color:var(--warn-ink);">Waiting for Approved BOM</div>
                    <div class="text-xs mt-0.5" style="color:var(--warn-ink);">A Bill of Materials must be approved before Purchase Orders can be submitted.</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- BOM Mirror (Purchase Order stage) — each BOM card shows its own linked POs -->
        <?php if ($stage === 'Purchase Order (Submit to accounting)'): ?>
            <div class="adm-section-label mb-4"><i class="fas fa-calculator"></i> Approved Bills of Materials</div>
            <?php if (empty($bomApprovedFiles)): ?>
                <div class="empty-state mb-6">
                    <div class="empty-icon"><i class="fas fa-hourglass-half"></i></div>
                    <div class="empty-title">No Approved BOMs Yet</div>
                    <div class="empty-sub">BOMs will appear here once approved in the Bill of Materials stage.</div>
                </div>
            <?php else: ?>

                <?php
                $posByBom = [];
                $allPosStmt = $conn->prepare("
    SELECT sa.*, a.full_name as uploaded_by_name,
           COALESCE(bos.status, 'pending') as order_status
    FROM stage_approvals sa
    LEFT JOIN account a ON sa.uploaded_by = a.id
    LEFT JOIN bom_order_status bos ON bos.bom_approval_id = sa.linked_bom_id
    WHERE sa.stage_id = ? AND sa.linked_bom_id IS NOT NULL
    ORDER BY sa.uploaded_at ASC
");
                $allPosStmt->bind_param("i", $stage_id);
                $allPosStmt->execute();
                $allPosResult = $allPosStmt->get_result();
                while ($poRow = $allPosResult->fetch_assoc()) {
                    $poRow['role_reviews'] = [];
                    $rStmt = $conn->prepare("SELECT sar.*, a.full_name as reviewer_name FROM stage_approval_reviews sar LEFT JOIN account a ON sar.reviewed_by = a.id WHERE sar.approval_id = ?");
                    $rStmt->bind_param("i", $poRow['id']);
                    $rStmt->execute();
                    $rRes = $rStmt->get_result();
                    while ($rev = $rRes->fetch_assoc())
                        $poRow['role_reviews'][$rev['reviewer_role']] = $rev;
                    $posByBom[$poRow['linked_bom_id']][] = $poRow;
                }
                ?>

                <?php foreach ($bomApprovedFiles as $bom):
                    $bomExt = strtolower(pathinfo($bom['file_name'], PATHINFO_EXTENSION));
                    [$bomIcon, $bomColor] = fileIcon($bomExt);
                    $linkedPos = $posByBom[$bom['id']] ?? [];
                    $hasPos = !empty($linkedPos);

                    $osColors = [
                        'pending' => ['bg' => 'var(--warn-bg)', 'color' => 'var(--warn-ink)', 'border' => 'var(--warn-line)', 'label' => 'Not Yet Ordered', 'icon' => 'fa-clock'],
                        'ordered' => ['bg' => 'var(--ok-bg)', 'color' => 'var(--ok-ink)', 'border' => 'var(--ok-line)', 'label' => 'Ordered', 'icon' => 'fa-check-circle'],
                        'partially_ordered' => ['bg' => 'var(--info-bg)', 'color' => 'var(--info-ink)', 'border' => 'var(--info-line)', 'label' => 'Partially Ordered', 'icon' => 'fa-adjust'],
                    ];
                    $osc = $osColors[$bom['order_status']] ?? $osColors['pending'];
                    ?>
                    <div class="mb-4">
                        <div class="file-card" style="background:var(--ok-bg);border-left-color:var(--ok-accent);border-radius:<?= $hasPos ? '10px 10px 0 0' : '10px' ?>;margin-bottom:0;<?= $hasPos ? 'border-bottom:1px dashed var(--ok-line);' : '' ?>">
                            <div class="file-row">
                                <i class="fas <?= $bomIcon ?> file-icon" style="color:<?= $bomColor ?>;"></i>
                                <div class="file-body">
                                    <?php if ($bom['label']): ?>
                                        <div class="file-label" style="color:var(--ok-ink);"><?= htmlspecialchars($bom['label']) ?></div>
                                    <?php endif; ?>
                                    <div class="file-name"><?= htmlspecialchars($bom['file_name']) ?></div>
                                    <div class="file-meta">
                                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($bom['uploaded_by_name']) ?></span>
                                        <span><i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($bom['uploaded_at'])) ?></span>
                                        <span><i class="fas fa-weight"></i> <?= number_format($bom['file_size'] / 1024, 1) ?> KB</span>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end gap-2 flex-shrink-0">
                                    <span class="file-status approved"><i class="fas fa-check-circle"></i> Approved BOM</span>
                                    <span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;background:<?= $osc['bg'] ?>;color:<?= $osc['color'] ?>;border:1px solid <?= $osc['border'] ?>;">
                                        <i class="fas <?= $osc['icon'] ?>"></i> <?= $osc['label'] ?>
                                    </span>
                                    <div class="flex gap-1.5 items-center">
                                        <?php
                                        $bomImageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
                                        $bomViewable = in_array($bomExt, $bomImageExts) || $bomExt === 'pdf';
                                        ?>
                                        <?php if ($bomViewable): ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($bom['file_path']) ?>?v=<?= filemtime(realpath(ROOT_PATH . $bom['file_path'])) ?: time() ?>" target="_blank" class="btn btn-view">
                                                <i class="fas fa-eye"></i> View BOM
                                            </a>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($bom['file_path']) ?>" download="<?= htmlspecialchars($bom['file_name']) ?>" class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($bom['file_path']) ?>" download="<?= htmlspecialchars($bom['file_name']) ?>" class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($canUpload): ?>
                                            <button class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);"
                                                onclick="openPOUploadModal(<?= $bom['id'] ?>, '<?= htmlspecialchars(addslashes($bom['label'] ?: $bom['file_name'])) ?>')">
                                                <i class="fas fa-file-invoice-dollar"></i> Add PO
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-[11px] font-bold flex items-center gap-1" style="color:<?= $hasPos ? 'var(--ok-ink)' : 'var(--adm-muted)' ?>;">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                        <?= count($linkedPos) ?> PO<?= count($linkedPos) !== 1 ? 's' : '' ?> submitted
                                    </span>
                                </div>
                            </div>
                        </div>

                        <?php if ($hasPos): ?>
                            <div style="background:var(--ok-bg);border:1px solid var(--ok-line);border-top:none;border-radius:0 0 10px 10px;padding:10px 16px 14px 16px;">
                                <div class="text-[10px] font-bold uppercase tracking-wide mb-2.5 flex items-center gap-1.5" style="color:var(--ok-ink);">
                                    <i class="fas fa-file-invoice-dollar"></i> Purchase Orders for this BOM
                                </div>
                                <?php foreach ($linkedPos as $po):
                                    $poExt = strtolower(pathinfo($po['file_name'], PATHINFO_EXTENSION));
                                    [$poIcon, $poColor] = fileIcon($poExt);
                                    $poStatus = $po['approval_status'] ?? 'pending';
                                    $myPoReview = $po['role_reviews'][$admin_role] ?? null;

                                    $poGmOmCanActNow = true;
                                    if (in_array($admin_role, ['general_manager', 'operational_manager'])) {
                                        $step1Roles = ['accounting', 'technical_designer'];
                                        foreach ($step1Roles as $s1r) {
                                            $s1rev = $po['role_reviews'][$s1r] ?? null;
                                            if (!$s1rev || $s1rev['review_status'] !== 'approved') { $poGmOmCanActNow = false; break; }
                                        }
                                    }
                                    $poGmOmAlreadyActed = false;
                                    if (in_array($admin_role, ['general_manager', 'operational_manager'])) {
                                        $gmRev2 = $po['role_reviews']['general_manager'] ?? null;
                                        $omRev2 = $po['role_reviews']['operational_manager'] ?? null;
                                        if (($gmRev2 && in_array($gmRev2['review_status'], ['approved', 'rejected'])) ||
                                            ($omRev2 && in_array($omRev2['review_status'], ['approved', 'rejected']))) {
                                            $poGmOmAlreadyActed = true;
                                        }
                                    }
                                    ?>
                                    <div class="bg-white rounded-lg p-3.5 mb-2" style="border:1px solid var(--ok-line);">
                                        <div class="flex gap-3 items-start">
                                            <i class="fas <?= $poIcon ?>" style="color:<?= $poColor ?>;font-size:20px;flex-shrink:0;margin-top:2px;"></i>
                                            <div class="flex-1 min-w-0">
                                                <?php if ($po['label']): ?>
                                                    <div class="text-[10px] font-bold uppercase tracking-wide mb-0.5" style="color:var(--ok-ink);"><?= htmlspecialchars($po['label']) ?></div>
                                                <?php endif; ?>
                                                <div class="text-[13px] font-semibold text-[var(--adm-ink)] truncate"><?= htmlspecialchars($po['file_name']) ?></div>
                                                <div class="file-meta">
                                                    <span><i class="fas fa-user"></i> <?= htmlspecialchars($po['uploaded_by_name']) ?></span>
                                                    <span><i class="fas fa-calendar"></i> <?= date('M d, Y · g:i A', strtotime($po['uploaded_at'])) ?></span>
                                                    <span><i class="fas fa-weight"></i> <?= number_format($po['file_size'] / 1024, 1) ?> KB</span>
                                                </div>

                                                <?php $reqPoRoles = $requiredApproversList['Purchase Order (Submit to accounting)'] ?? []; ?>
                                                <div class="approval-badges mt-2">
                                                    <?php foreach ($reqPoRoles as $role):
                                                        if (in_array($role, ['general_manager', 'operational_manager'])) continue;
                                                        $rev = $po['role_reviews'][$role] ?? null;
                                                        $bClass = $rev ? $rev['review_status'] : 'pending';
                                                        $bIcon = $bClass === 'approved' ? 'fa-check-circle' : ($bClass === 'rejected' ? 'fa-times-circle' : 'fa-clock');
                                                        $isMine = ($role === $admin_role);
                                                        ?>
                                                        <span class="apbadge <?= $bClass ?>" <?= $isMine ? 'style="box-shadow:0 0 0 2px var(--adm-ink);"' : '' ?>>
                                                            <i class="fas <?= $bIcon ?>"></i> <?= getRoleDisplayName($role) ?>
                                                            <?php if ($isMine): ?><em class="text-[9px] opacity-70 not-italic">(You)</em><?php endif; ?>
                                                            <?php if ($rev && !empty($rev['reviewed_at'])): ?>
                                                                <span class="apbadge-date">&middot; <?= date('M d, Y g:i A', strtotime($rev['reviewed_at'])) ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                    <?php
                                                    $gmRev3 = $po['role_reviews']['general_manager'] ?? null;
                                                    $omRev3 = $po['role_reviews']['operational_manager'] ?? null;
                                                    $gmStatus3 = $gmRev3 ? $gmRev3['review_status'] : null;
                                                    $omStatus3 = $omRev3 ? $omRev3['review_status'] : null;
                                                    if ($gmStatus3 === 'approved' || $omStatus3 === 'approved') {
                                                        $cs3 = 'approved'; $cl3 = 'Approved by ' . ($gmStatus3 === 'approved' ? 'GM' : 'OM'); $ci3 = 'fa-check-circle';
                                                    } elseif ($gmStatus3 === 'rejected' || $omStatus3 === 'rejected') {
                                                        $cs3 = 'rejected'; $cl3 = 'Rejected by ' . ($gmStatus3 === 'rejected' ? 'GM' : 'OM'); $ci3 = 'fa-times-circle';
                                                    } else { $cs3 = 'pending'; $cl3 = 'GM or OM (one required)'; $ci3 = 'fa-clock'; }
                                                    $isMineGmOm3 = in_array($admin_role, ['general_manager', 'operational_manager']);
                                                    $gmOmActedRev3 = null;
                                                    if ($gmStatus3 === 'approved' || $gmStatus3 === 'rejected') $gmOmActedRev3 = $gmRev3;
                                                    elseif ($omStatus3 === 'approved' || $omStatus3 === 'rejected') $gmOmActedRev3 = $omRev3;
                                                    ?>
                                                    <span class="apbadge <?= $cs3 ?>" <?= $isMineGmOm3 ? 'style="box-shadow:0 0 0 2px var(--adm-ink);"' : '' ?>>
                                                        <i class="fas <?= $ci3 ?>"></i> <?= $cl3 ?>
                                                        <?php if ($gmOmActedRev3 && !empty($gmOmActedRev3['reviewed_at'])): ?>
                                                            <span class="apbadge-date">&middot; <?= date('M d, Y g:i A', strtotime($gmOmActedRev3['reviewed_at'])) ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>

                                                <?php foreach ($po['role_reviews'] as $rKey => $rev):
                                                    if ($rev['review_status'] === 'rejected' && $rev['review_note']): ?>
                                                        <div class="reject-note">
                                                            <i class="fas fa-comment-alt"></i>
                                                            <strong><?= getRoleDisplayName($rKey) ?>:</strong> <?= htmlspecialchars($rev['review_note']) ?>
                                                        </div>
                                                    <?php endif; endforeach; ?>
                                            </div>
                                            <div class="flex flex-col items-end gap-1.5 flex-shrink-0">
                                                <span class="file-status <?= $poStatus ?>">
                                                    <?php if ($poStatus === 'approved'): ?><i class="fas fa-check-circle"></i>
                                                    <?php elseif ($poStatus === 'rejected'): ?><i class="fas fa-times-circle"></i>
                                                    <?php else: ?><i class="fas fa-clock"></i>
                                                    <?php endif; ?>
                                                    <?= ucfirst($poStatus) ?>
                                                </span>
                                                <?php
                                                $poImageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
                                                $poViewable = in_array($poExt, $poImageExts) || $poExt === 'pdf';
                                                ?>
                                                <?php if ($poViewable): ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($po['file_path']) ?>?v=<?= filemtime(realpath(ROOT_PATH . $po['file_path'])) ?: time() ?>" target="_blank" class="btn btn-view"><i class="fas fa-eye"></i> View</a>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($po['file_path']) ?>" download="<?= htmlspecialchars($po['file_name']) ?>" class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);"><i class="fas fa-download"></i></a>
                                                <?php else: ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($po['file_path']) ?>" download="<?= htmlspecialchars($po['file_name']) ?>" class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);"><i class="fas fa-download"></i> Download</a>
                                                <?php endif; ?>
                                                <?php if ($canApprove && !$myPoReview && $poGmOmCanActNow && !$poGmOmAlreadyActed && $poStatus === 'pending'): ?>
                                                    <button class="btn btn-approve" onclick="approveFile(<?= $po['id'] ?>)"><i class="fas fa-check"></i> Approve</button>
                                                    <button class="btn btn-reject" onclick="openRejectModal(<?= $po['id'] ?>)"><i class="fas fa-times"></i> Reject</button>
                                                <?php elseif ($canApprove && ($myPoReview || $poGmOmAlreadyActed)): ?>
                                                    <span class="text-[11px] font-semibold flex items-center gap-1" style="color:var(--ok-accent);"><i class="fas fa-check-double"></i> You reviewed this</span>
                                                <?php endif; ?>
                                                <?php if ($po['uploaded_by'] == $admin_id && $poStatus !== 'approved'): ?>
                                                    <?php if ($poStatus === 'rejected'): ?>
                                                        <button class="btn btn-resubmit"
                                                            onclick="openPOUploadModal(<?= $bom['id'] ?>, '<?= htmlspecialchars(addslashes($bom['label'] ?: $bom['file_name'])) ?>', '<?= htmlspecialchars(addslashes($po['label'] ?? '')) ?>')">
                                                            <i class="fas fa-redo"></i> Re-submit
                                                        </button>
                                                    <?php endif; ?>
                                                    <button class="btn btn-delete" onclick="deleteFile(<?= $po['id'] ?>)"><i class="fas fa-trash"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <?php if ($canUpdate && $isAssigned): ?>
                                    <div class="mt-2 flex items-center gap-2.5 flex-wrap">
                                        <span class="text-xs font-bold" style="color:var(--ok-ink);">Mark order status:</span>
                                        <button class="btn" style="background:var(--warn-bg);color:var(--warn-ink);border-color:var(--warn-line);" onclick="updateBomOrderStatus(<?= $bom['id'] ?>, 'pending')"><i class="fas fa-clock"></i> Not Ordered</button>
                                        <button class="btn" style="background:var(--info-bg);color:var(--info-ink);border-color:var(--info-line);" onclick="updateBomOrderStatus(<?= $bom['id'] ?>, 'partially_ordered')"><i class="fas fa-adjust"></i> Partially Ordered</button>
                                        <button class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);" onclick="updateBomOrderStatus(<?= $bom['id'] ?>, 'ordered')"><i class="fas fa-check-circle"></i> Fully Ordered</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

            <?php endif; ?>
        <?php endif; // end Purchase Order stage section ?>

        <!-- PO Mirror (Accounting only) — each PO card shows its own linked receipts -->
        <?php if ($isAccounting): ?>
            <div class="adm-section-label mb-4"><i class="fas fa-file-import"></i> Purchase Orders & Receipts</div>
            <?php if (empty($poApprovedFiles)): ?>
                <div class="empty-state mb-6">
                    <div class="empty-icon"><i class="fas fa-hourglass-half"></i></div>
                    <div class="empty-title">Waiting for PO Approval</div>
                    <div class="empty-sub">Purchase Order files will appear here once approved.</div>
                </div>
            <?php else: ?>

                <?php
                $receiptsByPo = [];
                $allReceiptsStmt = $conn->prepare("
        SELECT sa.*, a.full_name as uploaded_by_name
        FROM stage_approvals sa
        LEFT JOIN account a ON sa.uploaded_by = a.id
        WHERE sa.stage_id = ? AND sa.linked_po_id IS NOT NULL
        ORDER BY sa.uploaded_at ASC
    ");
                $allReceiptsStmt->bind_param("i", $stage_id);
                $allReceiptsStmt->execute();
                $allReceiptsResult = $allReceiptsStmt->get_result();
                while ($rc = $allReceiptsResult->fetch_assoc()) {
                    $receiptsByPo[$rc['linked_po_id']][] = $rc;
                }
                ?>

                <?php foreach ($poApprovedFiles as $pof):
                    $ext = strtolower(pathinfo($pof['file_name'], PATHINFO_EXTENSION));
                    [$fiIcon, $fiColor] = fileIcon($ext);
                    $linkedReceipts = $receiptsByPo[$pof['id']] ?? [];
                    $hasReceipt = !empty($linkedReceipts);
                    ?>
                    <div class="mb-4">
                        <div class="file-card" style="background:var(--info-bg);border-left-color:var(--info-accent);margin-bottom:0;border-radius:<?= $hasReceipt ? '10px 10px 0 0' : '10px' ?>;<?= $hasReceipt ? 'border-bottom:1px dashed var(--info-line);' : '' ?>">
                            <div class="file-row">
                                <i class="fas <?= $fiIcon ?> file-icon" style="color:<?= $fiColor ?>;"></i>
                                <div class="file-body">
                                    <?php if ($pof['label']): ?><div class="file-label"><?= htmlspecialchars($pof['label']) ?></div><?php endif; ?>
                                    <div class="file-name"><?= htmlspecialchars($pof['file_name']) ?></div>
                                    <div class="file-meta">
                                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($pof['uploaded_by_name']) ?></span>
                                        <span><i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($pof['uploaded_at'])) ?></span>
                                        <span><i class="fas fa-weight"></i> <?= number_format($pof['file_size'] / 1024, 1) ?> KB</span>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end gap-2 flex-shrink-0">
                                    <span class="file-status approved"><i class="fas fa-check-circle"></i> Approved PO</span>
                                    <div class="flex gap-1.5 items-center">
                                        <?php
                                        $pofExt2 = strtolower(pathinfo($pof['file_name'], PATHINFO_EXTENSION));
                                        $pofViewable = in_array($pofExt2, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']) || $pofExt2 === 'pdf';
                                        ?>
                                        <?php if ($pofViewable): ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($pof['file_path']) ?>?v=<?= filemtime(realpath(ROOT_PATH . $pof['file_path'])) ?: time() ?>" target="_blank" class="btn btn-view"><i class="fas fa-eye"></i> View</a>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($pof['file_path']) ?>" download="<?= htmlspecialchars($pof['file_name']) ?>" class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);"><i class="fas fa-download"></i></a>
                                        <?php else: ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($pof['file_path']) ?>" download="<?= htmlspecialchars($pof['file_name']) ?>" class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);"><i class="fas fa-download"></i> Download</a>
                                        <?php endif; ?>
                                        <?php if ($canUpload): ?>
                                            <button class="btn" style="background:var(--info-bg);color:var(--info-ink);border-color:var(--info-line);" onclick="openReceiptModal(<?= $pof['id'] ?>, '<?= htmlspecialchars(addslashes($pof['label'] ?: $pof['file_name'])) ?>')"><i class="fas fa-upload"></i> Upload Receipt</button>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-[11px] font-bold flex items-center gap-1" style="color:<?= $hasReceipt ? 'var(--info-ink)' : 'var(--adm-muted)' ?>;">
                                        <i class="fas fa-receipt"></i> <?= count($linkedReceipts) ?> receipt<?= count($linkedReceipts) !== 1 ? 's' : '' ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <?php if ($hasReceipt): ?>
                            <div style="background:var(--info-bg);border:1px solid var(--info-line);border-top:none;border-radius:0 0 10px 10px;padding:10px 16px 14px 16px;">
                                <div class="text-[10px] font-bold uppercase tracking-wide mb-2.5 flex items-center gap-1.5" style="color:var(--info-ink);">
                                    <i class="fas fa-receipt"></i> Receipts for this PO
                                </div>
                                <?php foreach ($linkedReceipts as $rc):
                                    $rcExt = strtolower(pathinfo($rc['file_name'], PATHINFO_EXTENSION));
                                    [$rcIcon, $rcColor] = fileIcon($rcExt);
                                    ?>
                                    <div class="bg-white rounded-lg p-3.5 mb-2 flex gap-3 items-center" style="border:1px solid var(--info-line);">
                                        <i class="fas <?= $rcIcon ?>" style="color:<?= $rcColor ?>;font-size:20px;flex-shrink:0;"></i>
                                        <div class="flex-1 min-w-0">
                                            <?php if ($rc['label']): ?>
                                                <div class="text-[10px] font-bold uppercase tracking-wide mb-0.5" style="color:var(--info-ink);"><?= htmlspecialchars($rc['label']) ?></div>
                                            <?php endif; ?>
                                            <div class="text-[13px] font-semibold text-[var(--adm-ink)] truncate"><?= htmlspecialchars($rc['file_name']) ?></div>
                                            <div class="file-meta">
                                                <span><i class="fas fa-user"></i> <?= htmlspecialchars($rc['uploaded_by_name']) ?></span>
                                                <span><i class="fas fa-calendar"></i> <?= date('M d, Y · g:i A', strtotime($rc['uploaded_at'])) ?></span>
                                                <span><i class="fas fa-weight"></i> <?= number_format($rc['file_size'] / 1024, 1) ?> KB</span>
                                            </div>
                                        </div>
                                        <div class="flex flex-col items-end gap-1.5 flex-shrink-0">
                                            <?php $rcViewable = in_array($rcExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']) || $rcExt === 'pdf'; ?>
                                            <?php if ($rcViewable): ?>
                                                <a href="<?= BASE_URL ?><?= htmlspecialchars($rc['file_path']) ?>?v=<?= filemtime(realpath(ROOT_PATH . $rc['file_path'])) ?: time() ?>" target="_blank" class="btn btn-view"><i class="fas fa-eye"></i> View</a>
                                                <a href="<?= BASE_URL ?><?= htmlspecialchars($rc['file_path']) ?>" download="<?= htmlspecialchars($rc['file_name']) ?>" class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);"><i class="fas fa-download"></i></a>
                                            <?php else: ?>
                                                <a href="<?= BASE_URL ?><?= htmlspecialchars($rc['file_path']) ?>" download="<?= htmlspecialchars($rc['file_name']) ?>" class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);"><i class="fas fa-download"></i> Download</a>
                                            <?php endif; ?>
                                            <?php if ($rc['uploaded_by'] == $admin_id && $stageStatus !== 'Done'): ?>
                                                <button class="btn btn-delete" onclick="deleteFile(<?= $rc['id'] ?>)"><i class="fas fa-trash"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php
            $unlinkedReceiptsStmt2 = $conn->prepare("
        SELECT sa.*, a.full_name as uploaded_by_name
        FROM stage_approvals sa
        LEFT JOIN account a ON sa.uploaded_by = a.id
        WHERE sa.stage_id = ? AND sa.linked_po_id IS NULL
        ORDER BY sa.uploaded_at DESC
    ");
            $unlinkedReceiptsStmt2->bind_param("i", $stage_id);
            $unlinkedReceiptsStmt2->execute();
            $unlinkedReceipts = $unlinkedReceiptsStmt2->get_result()->fetch_all(MYSQLI_ASSOC);
            ?>
            <?php if (!empty($unlinkedReceipts)): ?>
                <div class="adm-section-label mt-8 mb-4"><i class="fas fa-receipt"></i> Other Receipts (Unlinked)</div>
                <?php foreach ($unlinkedReceipts as $f):
                    $ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
                    [$fiIcon, $fiColor] = fileIcon($ext);
                    ?>
                    <div class="file-card approved">
                        <div class="file-row">
                            <i class="fas <?= $fiIcon ?> file-icon" style="color:<?= $fiColor ?>;"></i>
                            <div class="file-body">
                                <?php if ($f['label']): ?><div class="file-label"><?= htmlspecialchars($f['label']) ?></div><?php endif; ?>
                                <div class="file-name"><?= htmlspecialchars($f['file_name']) ?></div>
                                <div class="file-meta">
                                    <span><i class="fas fa-user"></i> <?= htmlspecialchars($f['uploaded_by_name']) ?></span>
                                    <span><i class="fas fa-calendar"></i> <?= date('M d, Y · g:i A', strtotime($f['uploaded_at'])) ?></span>
                                </div>
                            </div>
                            <div class="flex flex-col gap-1.5 flex-shrink-0">
                                <?php
                                $fExt2 = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
                                $fViewable2 = in_array($fExt2, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']) || $fExt2 === 'pdf';
                                ?>
                                <?php if ($fViewable2): ?>
                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($f['file_path']) ?>?v=<?= filemtime(realpath(ROOT_PATH . $f['file_path'])) ?: time() ?>" target="_blank" class="btn btn-view"><i class="fas fa-eye"></i> View</a>
                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($f['file_path']) ?>" download="<?= htmlspecialchars($f['file_name']) ?>" class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);"><i class="fas fa-download"></i></a>
                                <?php else: ?>
                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($f['file_path']) ?>" download="<?= htmlspecialchars($f['file_name']) ?>" class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);"><i class="fas fa-download"></i> Download</a>
                                <?php endif; ?>
                                <?php if ($f['uploaded_by'] == $admin_id && $stageStatus !== 'Done'): ?>
                                    <button class="btn btn-delete" onclick="deleteFile(<?= $f['id'] ?>)"><i class="fas fa-trash"></i> Delete</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php elseif ($isApproval && $stage !== 'Purchase Order (Submit to accounting)'): ?>
            <div class="adm-section-label mb-4"><i class="fas fa-folder-open"></i> Submitted Files</div>
        <?php elseif ($stage !== 'Purchase Order (Submit to accounting)'): ?>
            <div class="adm-section-label mb-4"><i class="fas fa-folder-open"></i> Uploaded Files</div>
        <?php endif; ?>

        <!-- Category filter — hidden for Accounting stage and PO stage -->
        <?php if (!empty($categories) && !$isAccounting && $stage !== 'Purchase Order (Submit to accounting)'): ?>
            <div id="categoryFilter" class="flex flex-wrap gap-2 mb-4">
                <button class="cat-btn active" onclick="filterCategory('all', this)"><i class="fas fa-th-large"></i> All</button>
                <?php foreach ($categories as $cat): ?>
                    <button class="cat-btn" onclick="filterCategory('<?= htmlspecialchars(addslashes($cat)) ?>', this)"><?= htmlspecialchars($cat) ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Files list — hidden for Accounting stage -->
        <?php if ($isAccounting): ?>
            <?php /* Receipts are displayed per-PO in the section above */ ?>
        <?php elseif ($stage === 'Purchase Order (Submit to accounting)'): ?>
            <?php /* POs are displayed per-BOM in the section above, uploaded files hidden */ ?>
        <?php elseif (empty($files) && $stage !== 'Purchase Order (Submit to accounting)'): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-file"></i></div>
                <div class="empty-title">No files yet</div>
                <div class="empty-sub">
                    <?php if ($canUpload): ?>
                        Click the button above to <?= $isApproval ? 'submit a file for approval' : 'upload a file' ?>.
                    <?php elseif (!$canUpdate && !$isApproval): ?>
                        You don't have permission to upload files to this stage.
                    <?php else: ?>
                        No files have been uploaded for this stage yet.
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($stage !== 'Purchase Order (Submit to accounting)'): ?>
            <?php foreach ($files as $f):
                $ext = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
                [$fiIcon, $fiColor] = fileIcon($ext);
                $fStatus = $f['approval_status'] ?? 'pending';
                $myReview = $f['role_reviews'][$admin_role] ?? null;
                ?>
                <div class="file-card <?= $fStatus ?>" data-category="<?= htmlspecialchars($f['label'] ?? '') ?>">
                    <div class="file-row">
                        <i class="fas <?= $fiIcon ?> file-icon" style="color:<?= $fiColor ?>;"></i>
                        <div class="file-body">
                            <?php if ($f['label']): ?><div class="file-label"><?= htmlspecialchars($f['label']) ?></div><?php endif; ?>
                            <div class="file-name"><?= htmlspecialchars($f['file_name']) ?></div>
                            <div class="file-meta">
                                <span><i class="fas fa-user"></i> <?= htmlspecialchars($f['uploaded_by_name']) ?></span>
                                <span><i class="fas fa-calendar"></i> <?= date('M d, Y · g:i A', strtotime($f['uploaded_at'])) ?></span>
                                <span><i class="fas fa-weight"></i> <?= number_format($f['file_size'] / 1024, 1) ?> KB</span>
                            </div>

                            <?php if ($isApproval && !empty($requiredApproversList[$stage])): ?>
                                <?php $reqRoles = $requiredApproversList[$stage]; ?>
                                <div class="approval-badges mt-2">
                                    <?php if (in_array($stage, $gmOmStages)):
                                        foreach ($reqRoles as $role):
                                            if (in_array($role, ['general_manager', 'operational_manager'])) continue;
                                            $rev = $f['role_reviews'][$role] ?? null;
                                            $bClass = $rev ? $rev['review_status'] : 'pending';
                                            $bIcon = $bClass === 'approved' ? 'fa-check-circle' : ($bClass === 'rejected' ? 'fa-times-circle' : 'fa-clock');
                                            $isMine = ($role === $admin_role);
                                            ?>
                                            <span class="apbadge <?= $bClass ?>" <?= $isMine ? 'style="box-shadow:0 0 0 2px var(--adm-ink);"' : '' ?>>
                                                <i class="fas <?= $bIcon ?>"></i> <?= getRoleDisplayName($role) ?>
                                                <?php if ($isMine): ?><em class="text-[9px] opacity-70 not-italic">(You)</em><?php endif; ?>
                                                <?php if ($rev && !empty($rev['reviewed_at'])): ?><span class="apbadge-date">&middot; <?= date('M d, Y g:i A', strtotime($rev['reviewed_at'])) ?></span><?php endif; ?>
                                            </span>
                                        <?php endforeach;

                                        $gmRev = $f['role_reviews']['general_manager'] ?? null;
                                        $omRev = $f['role_reviews']['operational_manager'] ?? null;
                                        $gmStatus = $gmRev ? $gmRev['review_status'] : null;
                                        $omStatus = $omRev ? $omRev['review_status'] : null;

                                        if ($gmStatus === 'approved' || $omStatus === 'approved') {
                                            $combinedStatus = 'approved';
                                            $whoActed = $gmStatus === 'approved' ? getRoleDisplayName('general_manager') : getRoleDisplayName('operational_manager');
                                            $combinedLabel = "Approved by {$whoActed}"; $combinedIcon = 'fa-check-circle';
                                        } elseif ($gmStatus === 'rejected' || $omStatus === 'rejected') {
                                            $combinedStatus = 'rejected';
                                            $whoActed = $gmStatus === 'rejected' ? getRoleDisplayName('general_manager') : getRoleDisplayName('operational_manager');
                                            $combinedLabel = "Rejected by {$whoActed}"; $combinedIcon = 'fa-times-circle';
                                        } else { $combinedStatus = 'pending'; $combinedLabel = 'GM or OM (one required)'; $combinedIcon = 'fa-clock'; }
                                        $isMineGmOm = in_array($admin_role, ['general_manager', 'operational_manager']);
                                        $gmOmActedRev = null;
                                        if ($gmStatus === 'approved' || $gmStatus === 'rejected') $gmOmActedRev = $gmRev;
                                        elseif ($omStatus === 'approved' || $omStatus === 'rejected') $gmOmActedRev = $omRev;
                                        ?>
                                        <span class="apbadge <?= $combinedStatus ?>" <?= $isMineGmOm ? 'style="box-shadow:0 0 0 2px var(--adm-ink);"' : '' ?>>
                                            <i class="fas <?= $combinedIcon ?>"></i> <?= $combinedLabel ?>
                                            <?php if ($isMineGmOm && ($gmRev || $omRev)): ?><em class="text-[9px] opacity-70 not-italic">(You)</em><?php endif; ?>
                                            <?php if ($gmOmActedRev && !empty($gmOmActedRev['reviewed_at'])): ?><span class="apbadge-date">&middot; <?= date('M d, Y g:i A', strtotime($gmOmActedRev['reviewed_at'])) ?></span><?php endif; ?>
                                        </span>

                                    <?php else:
                                        foreach ($reqRoles as $role):
                                            $rev = $f['role_reviews'][$role] ?? null;
                                            $bClass = $rev ? $rev['review_status'] : 'pending';
                                            $bIcon = $bClass === 'approved' ? 'fa-check-circle' : ($bClass === 'rejected' ? 'fa-times-circle' : 'fa-clock');
                                            $isMine = ($role === $admin_role);
                                            ?>
                                            <span class="apbadge <?= $bClass ?>" <?= $isMine ? 'style="box-shadow:0 0 0 2px var(--adm-ink);"' : '' ?>>
                                                <i class="fas <?= $bIcon ?>"></i> <?= getRoleDisplayName($role) ?>
                                                <?php if ($isMine): ?><em class="text-[9px] opacity-70 not-italic">(You)</em><?php endif; ?>
                                                <?php if ($rev && !empty($rev['reviewed_at'])): ?><span class="apbadge-date">&middot; <?= date('M d, Y g:i A', strtotime($rev['reviewed_at'])) ?></span><?php endif; ?>
                                            </span>
                                        <?php endforeach; endif; ?>
                                </div>
                                <?php
                                if ($isApproval && in_array($admin_role, ['general_manager', 'operational_manager']) && in_array($stage, $gmOmStages)):
                                    $step1Roles = $sequentialStagesInfo[$stage] ?? [];
                                    $step1AllDone = true; $missingStep1 = [];
                                    foreach ($step1Roles as $s1r) {
                                        $s1rev = $f['role_reviews'][$s1r] ?? null;
                                        if (!$s1rev || $s1rev['review_status'] !== 'approved') { $step1AllDone = false; $missingStep1[] = getRoleDisplayName($s1r); }
                                    }
                                    if (!$step1AllDone && $fStatus === 'pending'):
                                        ?>
                                        <div class="rounded-lg px-3 py-2 text-xs mt-1.5 flex items-center gap-2" style="background:var(--warn-bg);border:1px solid var(--warn-line);color:var(--warn-ink);">
                                            <i class="fas fa-hourglass-half flex-shrink-0" style="color:var(--warn-accent);"></i>
                                            <span>Waiting for <strong><?= implode(' and ', $missingStep1) ?></strong> to approve first before you can review this file.</span>
                                        </div>
                                    <?php endif; endif; ?>

                                <?php foreach ($f['role_reviews'] as $rKey => $rev):
                                    if ($rev['review_status'] === 'rejected' && $rev['review_note']): ?>
                                        <div class="reject-note">
                                            <i class="fas fa-comment-alt"></i>
                                            <strong><?= getRoleDisplayName($rKey) ?>:</strong> <?= htmlspecialchars($rev['review_note']) ?>
                                            <?php if ($rev['reviewer_name']): ?> — <em><?= htmlspecialchars($rev['reviewer_name']) ?></em><?php endif; ?>
                                        </div>
                                    <?php endif; endforeach; ?>
                            <?php endif; ?>

                            <div class="file-actions">
                                <?php
                                $imageExts2 = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
                                $isViewable2 = strpos($f['file_type'] ?? '', 'image/') === 0 || in_array($ext, $imageExts2) || $ext === 'pdf';
                                ?>
                                <?php if ($isViewable2): ?>
                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($f['file_path']) ?>?v=<?= filemtime(realpath(ROOT_PATH . $f['file_path'])) ?: time() ?>" target="_blank" class="btn btn-view"><i class="fas fa-eye"></i> View</a>
                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($f['file_path']) ?>" download="<?= htmlspecialchars($f['file_name']) ?>" class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);"><i class="fas fa-download"></i></a>
                                <?php else: ?>
                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($f['file_path']) ?>" download="<?= htmlspecialchars($f['file_name']) ?>" class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);"><i class="fas fa-download"></i> Download</a>
                                <?php endif; ?>

                                <?php
                                $gmOmCanActNow = true;
                                if ($isApproval && in_array($admin_role, ['general_manager', 'operational_manager']) && in_array($stage, $gmOmStages)) {
                                    $s1Roles2 = $sequentialStagesInfo[$stage] ?? [];
                                    foreach ($s1Roles2 as $s1r2) {
                                        $s1rev2 = $f['role_reviews'][$s1r2] ?? null;
                                        if (!$s1rev2 || $s1rev2['review_status'] !== 'approved') { $gmOmCanActNow = false; break; }
                                    }
                                }
                                $gmOmAlreadyActed = false;
                                if (in_array($admin_role, ['general_manager', 'operational_manager'])) {
                                    $gmRev2 = $f['role_reviews']['general_manager'] ?? null;
                                    $omRev2 = $f['role_reviews']['operational_manager'] ?? null;
                                    if (($gmRev2 && in_array($gmRev2['review_status'], ['approved', 'rejected'])) ||
                                        ($omRev2 && in_array($omRev2['review_status'], ['approved', 'rejected']))) {
                                        $gmOmAlreadyActed = true;
                                    }
                                }
                                ?>
                                <?php if ($isApproval && $canApprove && !$myReview && $gmOmCanActNow && !$gmOmAlreadyActed): ?>
                                    <button class="btn btn-approve" onclick="approveFile(<?= $f['id'] ?>)"><i class="fas fa-check"></i> Approve</button>
                                    <button class="btn btn-reject" onclick="openRejectModal(<?= $f['id'] ?>)"><i class="fas fa-times"></i> Reject</button>
                                <?php elseif ($isApproval && $canApprove && ($myReview || $gmOmAlreadyActed)): ?>
                                    <span class="text-[11px] font-semibold flex items-center gap-1" style="color:var(--ok-accent);">
                                        <i class="fas fa-check-double"></i>
                                        <?= $myReview ? 'You reviewed this' : 'Already reviewed by ' . (isset($f['role_reviews']['general_manager']) ? 'General Manager' : 'Operational Manager') ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($fStatus === 'rejected' && $f['uploaded_by'] == $admin_id): ?>
                                    <button class="btn btn-resubmit" onclick="openUploadModal('<?= htmlspecialchars(addslashes($f['label'])) ?>')"><i class="fas fa-redo"></i> Re-submit</button>
                                <?php endif; ?>

                                <?php if ($f['uploaded_by'] == $admin_id && $stageStatus !== 'Done' && ($fStatus !== 'approved' || $isFileUpload)): ?>
                                    <button class="btn btn-delete" onclick="deleteFile(<?= $f['id'] ?>)"><i class="fas fa-trash"></i> Delete</button>
                                <?php endif; ?>

                                <span class="file-status <?= $fStatus ?>" style="margin-left:auto;">
                                    <?php if ($fStatus === 'approved'): ?><i class="fas fa-check-circle"></i>
                                    <?php elseif ($fStatus === 'rejected'): ?><i class="fas fa-times-circle"></i>
                                    <?php else: ?><i class="fas fa-clock"></i>
                                    <?php endif; ?>
                                    <?= ucfirst($fStatus) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; /* end isAccounting check */ ?>

        <?php
        if ($isAccounting && $stageStatus === 'Ongoing' && !empty($files) && !$sfCanMarkDone && $canUpdate) {
            $poCheckStmt = $conn->prepare("SELECT status FROM project_tracker WHERE client_id = ? AND stage_name = 'Purchase Order (Submit to accounting)' LIMIT 1");
            $poCheckStmt->bind_param("i", $client_id);
            $poCheckStmt->execute();
            $poCheckRow = $poCheckStmt->get_result()->fetch_assoc();
            if (($poCheckRow['status'] ?? '') !== 'Done'):
                ?>
                <div class="rounded-xl p-4 mt-5 flex items-center gap-3" style="background:var(--warn-bg);border:1px solid var(--warn-line);">
                    <i class="fas fa-lock flex-shrink-0" style="color:var(--warn-accent);font-size:18px;"></i>
                    <div>
                        <div class="text-sm font-bold" style="color:var(--warn-ink);">Cannot mark as Done yet</div>
                        <div class="text-xs mt-0.5" style="color:var(--warn-ink);">The <strong>Purchase Order</strong> stage must be marked as Done before Accounting can be completed.</div>
                    </div>
                </div>
            <?php endif;
        } ?>

        <?php if ($sfCanMarkDone || $sfCanCancelDone): ?>
            <div class="mt-6 flex gap-2.5 justify-end flex-wrap">
                <?php if ($sfCanMarkDone): ?>
                    <button class="btn-upload" onclick="markDone()" style="background:var(--ok-accent);"><i class="fas fa-check-circle"></i> Mark Stage as Done</button>
                <?php endif; ?>
                <?php if ($sfCanCancelDone): ?>
                    <button class="btn-upload" onclick="cancelDone()" style="background:var(--bad-accent);"><i class="fas fa-undo"></i> Cancel (Revert to Ongoing)</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- Internal P.O to Accounting — Stage-level Approval Panel -->
    <?php if ($isInternalPo): ?>
        <div class="bg-white border border-[var(--adm-line)] rounded-xl p-6 mt-6 mb-8 max-w-4xl mx-auto w-full px-4 sm:px-6 lg:px-8">
            <div class="adm-section-label mb-4"><i class="fas fa-stamp"></i> Stage Approval Status</div>

            <?php if (!$internalPoApproval): ?>
                <div class="rounded-xl p-5 flex items-center justify-between flex-wrap gap-3.5" style="background:var(--adm-bg);border:2px dashed var(--adm-line);">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-white border border-[var(--adm-line)] flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-paper-plane" style="color:var(--adm-soft);font-size:16px;"></i>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-[var(--adm-ink)]">No approval requested yet</div>
                            <div class="text-xs text-[var(--adm-soft)] mt-0.5">Upload your files then request approval from Accounting and Head Designer.</div>
                        </div>
                    </div>
                    <?php if ($canRequestInternalPoApproval && !empty($files)): ?>
                        <button class="btn-upload flex-shrink-0" onclick="requestInternalPoApproval()"><i class="fas fa-paper-plane"></i> Request Approval</button>
                    <?php elseif ($canRequestInternalPoApproval && empty($files)): ?>
                        <button class="btn-upload flex-shrink-0" disabled style="background:var(--adm-muted);cursor:not-allowed;"><i class="fas fa-paper-plane"></i> Upload files first</button>
                    <?php endif; ?>
                </div>

            <?php else:
                $ipa = $internalPoApproval;
                $overallStatus = $ipa['overall_status'];
                $overallColors = [
                    'pending'  => ['bg' => 'var(--warn-bg)', 'border' => 'var(--warn-line)', 'color' => 'var(--warn-ink)', 'icon' => 'fa-clock'],
                    'approved' => ['bg' => 'var(--ok-bg)', 'border' => 'var(--ok-line)', 'color' => 'var(--ok-ink)', 'icon' => 'fa-check-circle'],
                    'rejected' => ['bg' => 'var(--bad-bg)', 'border' => 'var(--bad-line)', 'color' => 'var(--bad-ink)', 'icon' => 'fa-times-circle'],
                ];
                $oc = $overallColors[$overallStatus];
                ?>
                <div class="rounded-lg px-3.5 py-2.5 mb-3.5 flex items-center gap-2.5" style="background:<?= $oc['bg'] ?>;border:1px solid <?= $oc['border'] ?>;">
                    <i class="fas <?= $oc['icon'] ?> flex-shrink-0" style="color:<?= $oc['color'] ?>;font-size:16px;"></i>
                    <div>
                        <div class="text-[13px] font-bold" style="color:<?= $oc['color'] ?>;">
                            <?php if ($overallStatus === 'pending'): ?>Approval in progress<?php elseif ($overallStatus === 'approved'): ?>Fully approved — stage can be marked Done<?php else: ?>Rejected — please fix and re-request<?php endif; ?>
                        </div>
                        <div class="text-[11px] opacity-80 mt-0.5" style="color:<?= $oc['color'] ?>;">
                            Requested by <?= htmlspecialchars($ipa['requested_by_name']) ?> · <?= date('M d, Y g:i A', strtotime($ipa['requested_at'])) ?>
                        </div>
                    </div>
                    <?php if ($overallStatus === 'rejected' && $canRequestInternalPoApproval): ?>
                        <button onclick="resetInternalPoApproval(<?= $ipa['id'] ?>)" class="btn ml-auto flex-shrink-0" style="background:var(--bad-bg);color:var(--bad-ink);border-color:var(--bad-line);"><i class="fas fa-redo"></i> Re-request</button>
                    <?php endif; ?>
                </div>

                <?php
                $ntpStmt = $conn->prepare("
                    SELECT n.*, a.full_name as uploader_name, ps.payment_type
                    FROM notice_to_proceed n
                    LEFT JOIN account a ON a.id = n.uploaded_by
                    LEFT JOIN payment_schedule ps ON ps.id = n.payment_id
                    WHERE n.client_id = ?
                    ORDER BY n.uploaded_at DESC
                ");
                $ntpStmt->bind_param("i", $client_id);
                $ntpStmt->execute();
                $ntpFiles = $ntpStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                if (!empty($ntpFiles)): ?>
                    <div class="mt-4">
                        <div class="text-[11px] font-bold uppercase tracking-wide mb-2.5 flex items-center gap-1.5" style="color:var(--adm-soft);">
                            <i class="fas fa-file-signature" style="color:var(--info-accent);"></i> Notice to Proceed (NTP) Files
                        </div>
                        <?php foreach ($ntpFiles as $ntp):
                            $ntpExt = strtolower(pathinfo($ntp['file_name'], PATHINFO_EXTENSION));
                            $ntpViewable = in_array($ntpExt, ['jpg','jpeg','png','gif','webp','bmp','svg']) || $ntpExt === 'pdf';
                            ?>
                            <div class="rounded-lg p-3.5 mb-2" style="background:var(--info-bg);border:1px solid var(--info-line);">
                                <div class="flex items-center justify-between flex-wrap gap-2">
                                    <div>
                                        <div class="text-xs font-bold mb-0.5" style="color:var(--info-ink);">
                                            <i class="fas fa-file-signature"></i> NTP — <?= htmlspecialchars($ntp['payment_type'] ?? 'Payment') ?>
                                        </div>
                                        <div class="text-[11px] text-[var(--adm-soft)]">
                                            <i class="fas fa-user"></i> <?= htmlspecialchars($ntp['uploader_name']) ?> &bull; <?= date('M d, Y g:i A', strtotime($ntp['uploaded_at'])) ?>
                                        </div>
                                        <?php if (!empty($ntp['notes'])): ?>
                                            <div class="text-[11px] rounded-md px-2 py-1.5 mt-1.5" style="background:#fff;color:var(--adm-ink);">
                                                <i class="fas fa-sticky-note"></i> <?= htmlspecialchars($ntp['notes']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex gap-1.5">
                                        <?php if ($ntpViewable): ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($ntp['file_path']) ?>" target="_blank" class="btn btn-view"><i class="fas fa-eye"></i> View</a>
                                        <?php endif; ?>
                                        <a href="<?= BASE_URL ?><?= htmlspecialchars($ntp['file_path']) ?>" download="<?= htmlspecialchars($ntp['file_name']) ?>" class="btn" style="background:var(--ok-bg);color:var(--ok-ink);border-color:var(--ok-line);"><i class="fas fa-download"></i></a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="flex flex-col gap-2.5">
                    <?php
                    $acStatus = $ipa['accounting_status'];
                    $acColors = ['pending' => ['var(--adm-bg)', 'var(--adm-muted)', 'var(--adm-line)', 'fa-clock'], 'approved' => ['var(--ok-bg)', 'var(--ok-ink)', 'var(--ok-accent)', 'fa-check-circle'], 'rejected' => ['var(--bad-bg)', 'var(--bad-ink)', 'var(--bad-accent)', 'fa-times-circle']];
                    $acc = $acColors[$acStatus];
                    ?>
                    <div class="rounded-lg p-3.5" style="background:<?= $acc[0] ?>;border:1px solid <?= $acc[2] ?>;">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <div class="flex items-center gap-2">
                                <span class="w-[22px] h-[22px] rounded-full text-white flex items-center justify-center text-[11px] font-bold flex-shrink-0" style="background:<?= $acc[2] ?>;">1</span>
                                <div>
                                    <div class="text-xs font-bold flex items-center gap-1.5" style="color:<?= $acc[1] ?>;">
                                        <i class="fas <?= $acc[3] ?>"></i> Accounting
                                        <?php if ($acStatus === 'pending'): ?>
                                            <span class="rounded-full px-2 py-0.5 text-[10px]" style="background:var(--warn-bg);color:var(--warn-ink);border:1px solid var(--warn-line);">Waiting</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($ipa['accounting_reviewed_at']): ?>
                                        <div class="text-[11px] text-[var(--adm-soft)] mt-0.5"><?= htmlspecialchars($ipa['accounting_reviewer_name']) ?> · <?= date('M d, Y g:i A', strtotime($ipa['accounting_reviewed_at'])) ?></div>
                                    <?php endif; ?>
                                    <?php if ($ipa['accounting_remark']): ?>
                                        <div class="text-xs mt-1.5 rounded-md px-2.5 py-1.5 italic" style="color:var(--bad-ink);background:var(--bad-bg);">
                                            <i class="fas fa-comment-alt"></i> "<?= htmlspecialchars($ipa['accounting_remark']) ?>"
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($canReviewInternalPoAccounting && $acStatus === 'pending' && $overallStatus === 'pending'): ?>
                                <div class="flex gap-1.5 flex-shrink-0">
                                    <button class="btn btn-approve" onclick="reviewInternalPo(<?= $ipa['id'] ?>, 'approve', 'accounting')"><i class="fas fa-check"></i> Approve</button>
                                    <button class="btn btn-reject" onclick="showInternalPoRejectForm('accounting', <?= $ipa['id'] ?>)"><i class="fas fa-times"></i> Reject</button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div id="ipo-reject-form-accounting" class="hidden mt-2.5 rounded-lg p-3" style="background:var(--bad-bg);border:1px solid var(--bad-line);">
                            <div class="text-xs font-bold mb-2 flex items-center gap-1.5" style="color:var(--bad-ink);"><i class="fas fa-times-circle"></i> Remark / Rejection Note</div>
                            <textarea id="ipo-remark-accounting" class="form-textarea mb-2" placeholder="Explain what needs to be fixed..."></textarea>
                            <div class="flex gap-1.5 justify-end">
                                <button class="btn" onclick="hideInternalPoRejectForm('accounting')">Cancel</button>
                                <button class="btn" style="background:var(--bad-accent);color:#fff;border-color:var(--bad-accent);" onclick="submitInternalPoReject(<?= $ipa['id'] ?>, 'accounting')"><i class="fas fa-times"></i> Confirm Reject</button>
                            </div>
                        </div>
                    </div>

                    <?php
                    $dsStatus = $ipa['designer_status'];
                    $dsLocked = ($acStatus !== 'approved');
                    $dColors = ['pending' => ['var(--adm-bg)', 'var(--adm-muted)', 'var(--adm-line)', 'fa-clock'], 'approved' => ['var(--ok-bg)', 'var(--ok-ink)', 'var(--ok-accent)', 'fa-check-circle'], 'rejected' => ['var(--bad-bg)', 'var(--bad-ink)', 'var(--bad-accent)', 'fa-times-circle']];
                    $dc = $dColors[$dsStatus];
                    ?>
                    <div class="rounded-lg p-3.5" style="background:<?= $dc[0] ?>;border:1px solid <?= $dc[2] ?>;<?= $dsLocked ? 'opacity:.5;' : '' ?>">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <div class="flex items-center gap-2">
                                <span class="w-[22px] h-[22px] rounded-full text-white flex items-center justify-center text-[11px] font-bold flex-shrink-0" style="background:<?= $dc[2] ?>;">2</span>
                                <div>
                                    <div class="text-xs font-bold flex items-center gap-1.5" style="color:<?= $dc[1] ?>;">
                                        <i class="fas <?= $dc[3] ?>"></i> Head Designer
                                        <?php if ($dsLocked): ?>
                                            <span class="rounded-full px-2 py-0.5 text-[10px]" style="background:var(--adm-bg);color:var(--adm-soft);border:1px solid var(--adm-line);"><i class="fas fa-lock"></i> Waiting for Accounting</span>
                                        <?php elseif ($dsStatus === 'pending'): ?>
                                            <span class="rounded-full px-2 py-0.5 text-[10px]" style="background:var(--warn-bg);color:var(--warn-ink);border:1px solid var(--warn-line);">Waiting</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($ipa['designer_reviewed_at']): ?>
                                        <div class="text-[11px] text-[var(--adm-soft)] mt-0.5"><?= htmlspecialchars($ipa['designer_reviewer_name']) ?> · <?= date('M d, Y g:i A', strtotime($ipa['designer_reviewed_at'])) ?></div>
                                    <?php endif; ?>
                                    <?php if ($ipa['designer_remark']): ?>
                                        <div class="text-xs mt-1.5 rounded-md px-2.5 py-1.5 italic" style="color:var(--bad-ink);background:var(--bad-bg);">
                                            <i class="fas fa-comment-alt"></i> "<?= htmlspecialchars($ipa['designer_remark']) ?>"
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($canReviewInternalPoDesigner && $dsStatus === 'pending' && !$dsLocked && $overallStatus === 'pending'): ?>
                                <div class="flex gap-1.5 flex-shrink-0">
                                    <button class="btn btn-approve" onclick="reviewInternalPo(<?= $ipa['id'] ?>, 'approve', 'designer')"><i class="fas fa-check"></i> Approve</button>
                                    <button class="btn btn-reject" onclick="showInternalPoRejectForm('designer', <?= $ipa['id'] ?>)"><i class="fas fa-times"></i> Reject</button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div id="ipo-reject-form-designer" class="hidden mt-2.5 rounded-lg p-3" style="background:var(--bad-bg);border:1px solid var(--bad-line);">
                            <div class="text-xs font-bold mb-2 flex items-center gap-1.5" style="color:var(--bad-ink);"><i class="fas fa-times-circle"></i> Remark / Rejection Note</div>
                            <textarea id="ipo-remark-designer" class="form-textarea mb-2" placeholder="Explain what needs to be fixed..."></textarea>
                            <div class="flex gap-1.5 justify-end">
                                <button class="btn" onclick="hideInternalPoRejectForm('designer')">Cancel</button>
                                <button class="btn" style="background:var(--bad-accent);color:#fff;border-color:var(--bad-accent);" onclick="submitInternalPoReject(<?= $ipa['id'] ?>, 'designer')"><i class="fas fa-times"></i> Confirm Reject</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Upload Modal -->
    <div id="uploadModal" class="modal-overlay">
        <div class="modal-box">
            <iframe name="direct_upload_frame" id="direct_upload_frame" style="display:none;"></iframe>

            <form id="directUploadForm" method="POST" action="<?= BASE_URL ?>direct-upload" enctype="multipart/form-data" target="direct_upload_frame" style="display:contents;">
                <input type="hidden" name="stage_id" value="<?= $stage_id ?>">
                <input type="hidden" name="client_id" value="<?= $client_id ?>">
                <input type="hidden" name="stage_name" value="<?= htmlspecialchars($stage) ?>">

                <div class="modal-title"><i class="fas fa-file-upload"></i> <?= $isApproval ? 'Submit File for Approval' : 'Upload File' ?></div>
                <div class="modal-sub"><?= htmlspecialchars($stage) ?> · <?= htmlspecialchars($client['clientname']) ?></div>

                <div class="form-group">
                    <label class="form-label">File Label <span style="color:var(--bad-accent)">*</span></label>
                    <input type="text" id="uploadLabel" name="label" class="form-input" placeholder="e.g. Material Data Sheet, Quotation v2...">
                    <div class="form-hint">Describe what this file contains so reviewers understand it at a glance.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Select File <span style="color:var(--bad-accent)">*</span></label>
                    <input type="file" id="uploadFile" name="file" class="form-input"
                        accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.png,.jpg,.jpeg,.gif,.webp,.bmp,.mp4,.mov,.avi,.mkv,.webm"
                        onchange="autoSuggestUploadMode(this)">
                    <div class="form-hint" id="uploadFileHint">PDF, Word, Excel, PowerPoint, Images, Video · Max 50MB (Direct) or 1.3GB (Chunked)</div>
                </div>

                <div class="upload-mode-toggle">
                    <div class="mode-label"><i class="fas fa-bolt" style="color:var(--info-accent);"></i> <span>Upload Mode:</span></div>
                    <label class="toggle-switch">
                        <input type="checkbox" id="uploadModeToggle" onchange="onUploadModeChange()">
                        <span class="toggle-slider"></span>
                    </label>
                    <div id="uploadModeLabel">
                        <span class="mode-badge direct"><i class="fas fa-bolt"></i> Direct</span>
                        <span class="text-[11px] text-[var(--adm-soft)] ml-1">Best for files under 50MB · faster, no 405 errors</span>
                    </div>
                </div>

                <div id="uploadProgressWrap" style="display:none;" class="mb-3.5">
                    <div class="flex justify-between text-xs mb-1.5" style="color:var(--adm-soft);">
                        <span id="uploadProgressLabel">Uploading...</span>
                        <span id="uploadProgressPct">0%</span>
                    </div>
                    <div class="h-2 rounded-full overflow-hidden" style="background:var(--adm-line);">
                        <div id="uploadProgressBar" class="h-full rounded-full" style="width:0%;background:linear-gradient(90deg,var(--adm-ink),var(--adm-muted));transition:width .2s;"></div>
                    </div>
                    <div id="uploadProgressSub" class="text-[11px] mt-1" style="color:var(--adm-muted);"></div>
                </div>

                <div id="uploadError" class="form-error"></div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" id="uploadCancelBtn" onclick="closeUploadModal()">Cancel</button>
                    <button type="button" class="btn-submit" id="uploadSubmitBtn" onclick="submitUpload()">
                        <i class="fas fa-upload"></i> <?= $isApproval ? 'Submit for Approval' : 'Upload' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-title" style="color:var(--bad-ink);"><i class="fas fa-times-circle"></i> Reject File</div>
            <div class="modal-sub">Please explain why this file is being rejected. The submitter will be notified.</div>
            <input type="hidden" id="rejectApprovalId">
            <div class="form-group">
                <label class="form-label">Rejection Note <span style="color:var(--bad-accent)">*</span></label>
                <textarea id="rejectNote" class="form-textarea" placeholder="e.g. Please revise the material specifications on page 2..."></textarea>
            </div>
            <div id="rejectError" class="form-error"></div>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="closeRejectModal()">Cancel</button>
                <button class="btn-reject-confirm" onclick="submitRejection()"><i class="fas fa-times"></i> Confirm Rejection</button>
            </div>
        </div>
    </div>

    <!-- PO Upload Modal (linked to a specific BOM) -->
    <div id="poUploadModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-title"><i class="fas fa-file-invoice-dollar"></i> Submit Purchase Order</div>
            <div class="modal-sub">Submitting PO for BOM: <strong id="poUploadBomLabel">All BOMs</strong></div>
            <input type="hidden" id="poUploadLinkedBomId">

            <div class="form-group">
                <label class="form-label">PO Label <span style="color:var(--bad-accent)">*</span></label>
                <input type="text" id="poUploadLabel" class="form-input" placeholder="e.g. Purchase Order #001, Hardware PO...">
            </div>

            <div class="form-group">
                <label class="form-label">Select File <span style="color:var(--bad-accent)">*</span></label>
                <input type="file" id="poUploadFile" class="form-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.png,.jpg,.jpeg,.gif,.webp,.bmp">
                <div class="form-hint">PDF, Word, Excel, Images · Max 1.3GB</div>
            </div>

            <div id="poUploadProgressWrap" style="display:none;" class="mb-3.5">
                <div class="flex justify-between text-xs mb-1.5" style="color:var(--adm-soft);">
                    <span id="poUploadProgressLabel">Uploading...</span>
                    <span id="poUploadProgressPct">0%</span>
                </div>
                <div class="h-2 rounded-full overflow-hidden" style="background:var(--adm-line);">
                    <div id="poUploadProgressBar" class="h-full rounded-full" style="width:0%;background:linear-gradient(90deg,var(--ok-ink),var(--ok-accent));transition:width .2s;"></div>
                </div>
            </div>

            <div id="poUploadError" class="form-error"></div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closePOUploadModal()">Cancel</button>
                <button type="button" class="btn-submit" id="poUploadSubmitBtn" onclick="submitPOUpload()" style="background:var(--ok-ink);">
                    <i class="fas fa-file-invoice-dollar"></i> Submit for Approval
                </button>
            </div>
        </div>
    </div>

    <!-- Receipt Upload Modal (linked to a specific PO) -->
    <div id="receiptModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-title"><i class="fas fa-receipt"></i> Upload Receipt</div>
            <div class="modal-sub">Uploading receipt for PO: <strong id="receiptPoLabel"></strong></div>
            <input type="hidden" id="receiptLinkedPoId">

            <div class="form-group">
                <label class="form-label">Receipt Label <span style="color:var(--bad-accent)">*</span></label>
                <input type="text" id="receiptLabel" class="form-input" placeholder="e.g. Delivery Receipt #001, Invoice #123...">
            </div>

            <div class="form-group">
                <label class="form-label">Select File <span style="color:var(--bad-accent)">*</span></label>
                <input type="file" id="receiptFile" class="form-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.png,.jpg,.jpeg,.gif,.webp,.bmp,.mp4,.mov,.avi,.mkv,.webm">
                <div class="form-hint">PDF, Word, Excel, Images · Max 1.3GB</div>
            </div>

            <div id="receiptProgressWrap" style="display:none;" class="mb-3.5">
                <div class="flex justify-between text-xs mb-1.5" style="color:var(--adm-soft);">
                    <span id="receiptProgressLabel">Uploading...</span>
                    <span id="receiptProgressPct">0%</span>
                </div>
                <div class="h-2 rounded-full overflow-hidden" style="background:var(--adm-line);">
                    <div id="receiptProgressBar" class="h-full rounded-full" style="width:0%;background:linear-gradient(90deg,var(--info-ink),var(--info-accent));transition:width .2s;"></div>
                </div>
            </div>

            <div id="receiptError" class="form-error"></div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeReceiptModal()">Cancel</button>
                <button type="button" class="btn-submit" id="receiptSubmitBtn" onclick="submitReceipt()" style="background:var(--info-ink);">
                    <i class="fas fa-upload"></i> Upload Receipt
                </button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="toast"><i class="fas fa-check-circle"></i><span id="toastMsg"></span></div>

    <script>
        const STAGE_ID = <?= $stage_id ?>;
        const CLIENT_ID = <?= $client_id ?>;
        const STAGE_NAME = <?= json_encode($stage) ?>;
        const BASE_URL_JS = <?= json_encode(BASE_URL) ?>;
        const IS_APPROVAL = <?= $isApproval ? 'true' : 'false' ?>;
        const UPLOAD_BTN_ICON_LABEL = <?= json_encode($isApproval ? '<i class="fas fa-upload"></i> Submit for Approval' : '<i class="fas fa-upload"></i> Upload') ?>;
    </script>
    <script src="<?= ADMIN_ASSET ?>/tracker-management/tracker-management/js/stage_files.js"></script>
    <?php include $includes ['esign-modal']; ?>
</body>

</html>