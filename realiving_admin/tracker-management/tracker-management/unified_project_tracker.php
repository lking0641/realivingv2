<?php
// unified_project_tracker.php
include $includes['mainbody'];

require_once __DIR__ . '/unified_project_tracker_data.php';
require_once __DIR__ . '/unified_project_tracker_helpers.php';

// ── Reusable Tailwind button classes (defined once, reused everywhere) ──
$BTN_PRIMARY = "inline-flex items-center gap-2 bg-black text-white px-4 py-2 rounded-lg text-xs font-bold shadow-sm hover:bg-neutral-800 hover:-translate-y-0.5 active:translate-y-0 transition-all";
$BTN_PRIMARY_SM = "inline-flex items-center gap-1.5 bg-black text-white px-3 py-1.5 rounded-md text-[11px] font-bold shadow-sm hover:bg-neutral-800 hover:-translate-y-0.5 active:translate-y-0 transition-all";
$BTN_DANGER = "inline-flex items-center gap-2 bg-red-600 text-white px-4 py-2 rounded-lg text-xs font-bold shadow-sm hover:bg-red-700 hover:-translate-y-0.5 active:translate-y-0 transition-all";
$BTN_GHOST = "inline-flex items-center gap-2 bg-white border-2 border-neutral-200 text-black px-4 py-2 rounded-lg text-xs font-bold hover:border-black transition-all";
$BTN_WHITE_ON_DARK = "inline-flex items-center gap-2 bg-white text-black px-4 py-2 rounded-lg text-xs font-bold shadow-sm hover:bg-neutral-100 hover:-translate-y-0.5 active:translate-y-0 transition-all";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Tracker — <?= htmlspecialchars($client['clientname']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <!-- Tailwind is compiled via your npm build (output.css) — loaded by mainbody include -->
    <style>
        /* Thin styled scrollbar for the master list panel */
        #masterListScroll::-webkit-scrollbar { width: 6px; }
        #masterListScroll::-webkit-scrollbar-track { background: transparent; }
        #masterListScroll::-webkit-scrollbar-thumb { background: #d4d4d4; border-radius: 999px; }
        #masterListScroll::-webkit-scrollbar-thumb:hover { background: #a3a3a3; }

        @keyframes fadeInPanel {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .stage-detail-panel:not(.hidden) { animation: fadeInPanel .18s ease-out; }
    </style>
</head>

<body class="bg-neutral-100 font-['Inter'] text-black min-h-screen">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 pb-16">

        <!-- Back -->
        <a href="<?= $backUrl ?>"
            class="inline-flex items-center gap-2 text-sm font-semibold text-neutral-500 hover:text-black transition mb-6">
            <i class="fas fa-arrow-left"></i> <?= $backText ?>
        </a>

        <?php if (isset($_GET['locked'])): ?>
            <div
                class="bg-amber-50 border-2 border-amber-400 rounded-xl px-5 py-3.5 mb-5 flex items-center gap-3 text-[13px] text-amber-800 font-semibold">
                <i class="fas fa-lock text-amber-600 text-base"></i>
                This stage is locked. Complete the previous stage first before accessing its files.
            </div>
        <?php endif; ?>

        <!-- Client header -->
        <div class="bg-black rounded-2xl p-7 sm:p-8 text-white mb-6 relative overflow-hidden">
            <div
                class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(255,255,255,.08)_0%,transparent_65%)] pointer-events-none">
            </div>
            <div class="flex justify-between items-start flex-wrap gap-4 relative z-10">
                <div>
                    <div class="text-xl sm:text-2xl font-bold tracking-tight">
                        <?= htmlspecialchars($client['clientname']) ?>
                    </div>
                    <div class="text-sm text-white/70 mt-0.5"><?= htmlspecialchars($client['nameproject']) ?></div>
                </div>
                <div class="flex items-center gap-2.5 flex-wrap justify-end">
                    <button onclick="document.getElementById('clientDetailModal').classList.remove('hidden'); document.getElementById('clientDetailModal').classList.add('flex');"
                        class="<?= $BTN_WHITE_ON_DARK ?>">
                        <i class="fas fa-info-circle"></i> View Details
                    </button>
                    <div
                        class="bg-white/10 border border-white/20 rounded-full px-3.5 py-1.5 text-xs font-semibold flex items-center gap-2 flex-shrink-0">
                        <i class="fas fa-user-circle"></i>
                        <?= htmlspecialchars($userInfo['full_name']) ?>
                        <span
                            class="bg-white/15 rounded px-2 py-0.5 text-[11px] capitalize"><?= str_replace('_', ' ', $admin_role) ?></span>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2.5 mt-6 relative z-10">
                <div class="bg-white/10 border border-white/15 rounded-lg px-3.5 py-2 text-xs min-w-[110px]">
                    <div class="opacity-60 text-[10px] uppercase tracking-wide mb-0.5">Reference</div>
                    <div class="font-semibold break-all"><?= htmlspecialchars($client['reference_number']) ?></div>
                </div>
                <div class="bg-white/10 border border-white/15 rounded-lg px-3.5 py-2 text-xs min-w-[110px]">
                    <div class="opacity-60 text-[10px] uppercase tracking-wide mb-0.5">Status</div>
                    <div class="font-semibold"><?= htmlspecialchars($client['status']) ?></div>
                </div>
                <div class="bg-white/10 border border-white/15 rounded-lg px-3.5 py-2 text-xs min-w-[110px]">
                    <div class="opacity-60 text-[10px] uppercase tracking-wide mb-0.5">Type</div>
                    <div class="font-semibold"><?= htmlspecialchars($business_type_label) ?></div>
                </div>
                <?php if ($client['admin_name']): ?>
                    <div class="bg-white/10 border border-white/15 rounded-lg px-3.5 py-2 text-xs min-w-[110px]">
                        <div class="opacity-60 text-[10px] uppercase tracking-wide mb-0.5">Assigned To</div>
                        <div class="font-semibold"><?= htmlspecialchars($client['admin_name']) ?></div>
                    </div>
                <?php endif; ?>
                <div class="bg-white/10 border border-white/15 rounded-lg px-3.5 py-2 text-xs min-w-[110px]">
                    <div class="opacity-60 text-[10px] uppercase tracking-wide mb-0.5">Tracker Mode</div>
                    <div class="font-semibold capitalize"><?= str_replace('-', ' ', $tracker_mode) ?></div>
                </div>
            </div>
        </div>

        <?php if ($tracker_mode === 'sequential'): ?>
            <div
                class="inline-flex items-center gap-2 bg-neutral-200 border border-neutral-300 rounded-lg px-3.5 py-2 text-[11px] font-bold text-neutral-700 mb-4">
                <i class="fas fa-lock"></i> Sequential Mode — stages must be completed in order
            </div>
        <?php endif; ?>

        <?php
        // ── Payment proof notifications ──────────────────────────────

        // For accounting: check if there are pending proofs waiting for review
        if ($isAccountingRole) {
            $pendingProofStmt = $conn->prepare("
        SELECT COUNT(*) as cnt
        FROM payment_proofs pp
        JOIN payment_schedule ps ON ps.id = pp.payment_id
        JOIN payment_accounting_reviews par ON par.payment_id = pp.payment_id
        WHERE ps.client_id = ?
          AND par.review_status = 'pending'
          AND ps.accounting_status = 'pending_review'
    ");
            $pendingProofStmt->bind_param("i", $client_id);
            $pendingProofStmt->execute();
            $pendingProofCount = (int) $pendingProofStmt->get_result()->fetch_assoc()['cnt'];

            if ($pendingProofCount > 0):
                ?>
                <div
                    class="bg-amber-50 border-2 border-amber-400 rounded-xl px-5 py-3.5 mb-4 flex items-center justify-between gap-3.5 flex-wrap">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-file-invoice-dollar text-amber-600 text-xl flex-shrink-0"></i>
                        <div>
                            <div class="font-bold text-sm text-amber-800">
                                <?= $pendingProofCount ?> payment proof<?= $pendingProofCount > 1 ? 's' : '' ?> waiting for your
                                review
                            </div>
                            <div class="text-xs text-amber-600 mt-0.5">
                                Open the Payment Tracker to approve or reject the submitted proofs.
                            </div>
                        </div>
                    </div>
                    <a href="payment-tracker?client_id=<?= $client_id ?>"
                        class="<?= $BTN_PRIMARY ?> !bg-amber-600 hover:!bg-amber-700 flex-shrink-0">
                        <i class="fas fa-arrow-right"></i> Review Proofs
                    </a>
                </div>
            <?php endif;
        } ?>

        <?php
        // For assigned user: check if any of their submitted proofs were rejected
        if ($isAssignedToClient && !$isAccountingRole) {
            $rejectedProofStmt = $conn->prepare("
        SELECT COUNT(*) as cnt
        FROM payment_proofs pp
        JOIN payment_schedule ps ON ps.id = pp.payment_id
        JOIN payment_accounting_reviews par ON par.payment_id = pp.payment_id
        WHERE ps.client_id = ?
          AND pp.uploaded_by = ?
          AND par.review_status = 'rejected'
          AND ps.accounting_status = 'rejected'
    ");
            $rejectedProofStmt->bind_param("ii", $client_id, $admin_id);
            $rejectedProofStmt->execute();
            $rejectedProofCount = (int) $rejectedProofStmt->get_result()->fetch_assoc()['cnt'];

            if ($rejectedProofCount > 0):
                ?>
                <div
                    class="bg-red-50 border-2 border-red-400 rounded-xl px-5 py-3.5 mb-4 flex items-center justify-between gap-3.5 flex-wrap">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-times-circle text-red-600 text-xl flex-shrink-0"></i>
                        <div>
                            <div class="font-bold text-sm text-red-800">
                                <?= $rejectedProofCount ?> payment proof<?= $rejectedProofCount > 1 ? 's' : '' ?> you submitted
                                <?= $rejectedProofCount > 1 ? 'were' : 'was' ?> rejected
                            </div>
                            <div class="text-xs text-red-600 mt-0.5">
                                Open the Payment Tracker to view the rejection reason and resubmit.
                            </div>
                        </div>
                    </div>
                    <a href="payment-tracker?client_id=<?= $client_id ?>" class="<?= $BTN_DANGER ?> flex-shrink-0">
                        <i class="fas fa-redo"></i> Resubmit Proof
                    </a>
                </div>
            <?php endif;
        } ?>

        <!-- ══════════════ Split Master–Detail Stage Viewer ══════════════ -->
        <?php $layoutPendingCount = getLayoutPendingCount($conn, $admin_id, $client_id); ?>

        <div class="grid grid-cols-1 lg:grid-cols-[340px_1fr] gap-5 items-start">

            <!-- ── MASTER: stage list ── -->
            <div class="bg-white border border-neutral-200 rounded-2xl shadow-sm lg:sticky lg:top-6 overflow-hidden">
                <div class="px-4 sm:px-5 py-4 border-b border-neutral-100 flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wide text-neutral-500">Stages</span>
                    <span class="text-[11px] font-mono font-bold text-neutral-400"><?= $total_stages ?></span>
                </div>
                <div id="masterListScroll" class="max-h-[70vh] overflow-y-auto p-2.5 flex flex-col gap-1.5">

                    <?php foreach ($stages as $index => $stage):

                        $stageData = $trackerData[$stage] ?? null;
                        $canUpdate = isset($permissions[$stage]) ? $permissions[$stage] : false;
                        $isApproval = in_array($stage, $approvalStages);
                        $isFileUpload = in_array($stage, $fileUploadStages);
                        $isAuto = in_array($stage, $autoStages);

                        // Sequential lock
                        $isLocked = false;
                        if ($tracker_mode === 'sequential' && $index > 0) {
                            if ($index >= 6) {
                                $prevStatus = isset($trackerData[$stages[$index - 1]]) ? $trackerData[$stages[$index - 1]]['status'] : 'Pending';

                                // For Delivery and Installation: unlock as soon as at least one item is Done
                                // in the previous stage (item-level check), not waiting for full stage completion
                                $itemDepStages = ['Delivery' => 'Fabrication', 'Installation' => 'Delivery', 'BILLING' => 'Installation'];
                                if (isset($itemDepStages[$stage])) {
                                    $prevItemCol = strtolower($itemDepStages[$stage]) . '_status';
                                    $itemDepStmt = $conn->prepare("
                        SELECT COUNT(*) AS has_done
                        FROM (
                            SELECT $prevItemCol FROM quotation_entries WHERE client_id = ? AND $prevItemCol = 'Done'
                            UNION ALL
                            SELECT $prevItemCol FROM quotation_fixed_sizes WHERE client_id = ? AND $prevItemCol = 'Done'
                        ) x
                    ");
                                    $itemDepStmt->bind_param("ii", $client_id, $client_id);
                                    $itemDepStmt->execute();
                                    $hasDoneItem = (int) $itemDepStmt->get_result()->fetch_assoc()['has_done'];

                                    if ($hasDoneItem === 0) {
                                        $isLocked = true;
                                        $canUpdate = false;
                                    }
                                    // else: at least one item is Done in prev stage → unlock
                                } else {
                                    // Normal stages: lock if previous is Pending
                                    if ($prevStatus === 'Pending') {
                                        $isLocked = true;
                                        $canUpdate = false;
                                    }
                                }
                            }
                        }

                        // ── Compute status ──────────────────────────────────────────────
                        $status = $stageData ? $stageData['status'] : 'Pending';

                        if ($stage === 'Downpayment') {
                            $dpStmt = $conn->prepare("SELECT status FROM payment_schedule WHERE client_id = ? AND payment_type LIKE '%Down%' LIMIT 1");
                            $dpStmt->bind_param("i", $client_id);
                            $dpStmt->execute();
                            $dpRow = $dpStmt->get_result()->fetch_assoc();
                            $status = ($dpRow && $dpRow['status'] === 'Paid') ? 'Done' : 'Pending';

                            // Sync computed Downpayment status back to DB so progress counter is accurate
                            if ($stageData && $stageData['status'] !== $status) {
                                $syncDpStmt = $conn->prepare("UPDATE project_tracker SET status = ?, updated_at = NOW() WHERE id = ?");
                                $syncDpStmt->bind_param("si", $status, $stageData['id']);
                                $syncDpStmt->execute();
                                $trackerData[$stage]['status'] = $status;
                            }
                        } elseif ($stage === 'BILLING') {
                            // Fetch all payment rows
                            $bStmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status='Paid' THEN 1 ELSE 0 END) AS paid FROM payment_schedule WHERE client_id = ? AND payment_type NOT LIKE '%Down Payment%'");
                            $bStmt->bind_param("i", $client_id);
                            $bStmt->execute();
                            $bRow = $bStmt->get_result()->fetch_assoc();

                            $dpChk = $conn->prepare("SELECT COUNT(*) AS dp FROM payment_schedule WHERE client_id = ? AND payment_type LIKE '%Down Payment%' AND status='Paid'");
                            $dpChk->bind_param("i", $client_id);
                            $dpChk->execute();
                            $dpPaid = $dpChk->get_result()->fetch_assoc()['dp'] > 0;

                            $hasCollections = $bRow['total'] > 0;
                            $allCollectionsPaid = $hasCollections && $bRow['paid'] == $bRow['total'];

                            if (($client['business_type'] ?? '') === 'Project') {
                                // Project: also require installation to be 100% complete
                                $instStmt = $conn->prepare("
                    SELECT CASE
                        WHEN COUNT(*) = 0 THEN 0
                        WHEN COUNT(*) = SUM(CASE WHEN installation_status = 'Done' THEN 1 ELSE 0 END) THEN 1
                        ELSE 0
                    END AS all_done
                    FROM (
                        SELECT installation_status FROM quotation_entries WHERE client_id = ?
                        UNION ALL
                        SELECT installation_status FROM quotation_fixed_sizes WHERE client_id = ?
                    ) x
                ");
                                $instStmt->bind_param("ii", $client_id, $client_id);
                                $instStmt->execute();
                                $instAllDone = (bool) ($instStmt->get_result()->fetch_assoc()['all_done'] ?? false);

                                if ($allCollectionsPaid && $instAllDone)
                                    $status = 'Done';
                                elseif ($bRow['paid'] > 0 || $dpPaid)
                                    $status = 'Ongoing';
                                else
                                    $status = 'Pending';

                            } else {
                                // Non-Project (Individual): check all 3 payments — DP + 40% + 10%
                                $allPayStmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status='Paid' THEN 1 ELSE 0 END) AS paid FROM payment_schedule WHERE client_id = ?");
                                $allPayStmt->bind_param("i", $client_id);
                                $allPayStmt->execute();
                                $allPayRow = $allPayStmt->get_result()->fetch_assoc();

                                $anyPaid = $allPayRow['paid'] > 0;
                                $allPaid = $allPayRow['total'] > 0 && $allPayRow['paid'] == $allPayRow['total'];

                                if ($allPaid)
                                    $status = 'Done';
                                elseif ($anyPaid)
                                    $status = 'Ongoing';
                                else
                                    $status = 'Pending';
                            }

                            // Sync computed BILLING status back to DB so progress counter is accurate
                            if ($stageData && $stageData['status'] !== $status) {
                                $syncBillStmt = $conn->prepare("UPDATE project_tracker SET status = ?, updated_at = NOW() WHERE id = ?");
                                $syncBillStmt->bind_param("si", $status, $stageData['id']);
                                $syncBillStmt->execute();
                                // Also update the in-memory trackerData so progress counts correctly
                                $trackerData[$stage]['status'] = $status;
                            }
                        } elseif ($stage === 'Cuttinglist') {
                            // Auto-set Cuttinglist to Ongoing when Downpayment is Ongoing or Done
                            $dpStmt2 = $conn->prepare("SELECT status FROM payment_schedule WHERE client_id = ? AND payment_type LIKE '%Down%' LIMIT 1");
                            $dpStmt2->bind_param("i", $client_id);
                            $dpStmt2->execute();
                            $dpRow2 = $dpStmt2->get_result()->fetch_assoc();
                            $downpaymentStatus = ($dpRow2 && $dpRow2['status'] === 'Paid') ? 'Done' : 'Pending';

                            if ($status === 'Done') {
                                $status = 'Done';
                            } elseif (in_array($downpaymentStatus, ['Ongoing', 'Done'])) {
                                $status = 'Ongoing';
                                if ($stageData && $stageData['status'] !== 'Ongoing') {
                                    $syncStmt = $conn->prepare("UPDATE project_tracker SET status = 'Ongoing', updated_at = NOW() WHERE id = ?");
                                    $syncStmt->bind_param("i", $stageData['id']);
                                    $syncStmt->execute();
                                }
                            }
                        } elseif (in_array($stage, ['Fabrication', 'Delivery', 'Installation'])) {
                            $col = strtolower($stage) . '_status';

                            // Use item-level status columns (unit-level distribution tracking removed)
                            $iStmt = $conn->prepare("
                SELECT CASE
                    WHEN COUNT(*) = 0 THEN 'Pending'
                    WHEN COUNT(*) = SUM(CASE WHEN $col = 'Done' THEN 1 ELSE 0 END) THEN 'Done'
                    WHEN SUM(CASE WHEN $col IN ('Ongoing','Incomplete','Punchlist','Done') THEN 1 ELSE 0 END) > 0 THEN 'Ongoing'
                    ELSE 'Pending'
                END AS s
                FROM (
                    SELECT $col FROM quotation_entries WHERE client_id = ?
                    UNION ALL
                    SELECT $col FROM quotation_fixed_sizes WHERE client_id = ?
                ) x
            ");
                            $iStmt->bind_param("ii", $client_id, $client_id);
                            $iStmt->execute();
                            $status = $iStmt->get_result()->fetch_assoc()['s'] ?? 'Pending';
                        }

                        $statusClass = strtolower($status);
                        $icon = getStageIcon($stage);

                        // Tailwind status color sets
                        $statusColors = [
                            'pending' => [
                                'chip' => 'bg-neutral-100 text-neutral-500 border-neutral-300',
                                'node' => 'bg-white text-neutral-300 border-2 border-neutral-200',
                                'text' => 'text-neutral-400',
                            ],
                            'ongoing' => [
                                'chip' => 'bg-blue-600 text-white border-blue-600',
                                'node' => 'bg-blue-600 text-white shadow-sm ring-4 ring-blue-100',
                                'text' => 'text-blue-600',
                            ],
                            'done' => [
                                'chip' => 'bg-emerald-50 text-emerald-600 border-emerald-200',
                                'node' => 'bg-emerald-500 text-white shadow-sm',
                                'text' => 'text-emerald-500',
                            ],
                        ];
                        $sc = $statusColors[$statusClass] ?? $statusColors['pending'];

                        // Flag stages that carry a pending notification badge, so the master list can surface it
                        $masterHasAlert = false;
                        if ($stage === '2D / 3D Layout' && $layoutPendingCount > 0) $masterHasAlert = true;
                        ?>

                        <div id="master-item-<?= $index ?>" data-idx="<?= $index ?>" data-status="<?= htmlspecialchars($status) ?>"
                            onclick="selectStage(<?= $index ?>)"
                            class="master-item flex items-center gap-3 px-3 py-2.5 rounded-xl border border-neutral-200 bg-white cursor-pointer transition-all hover:border-neutral-300 <?= $isLocked ? 'opacity-50' : '' ?>">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center text-[11px] flex-shrink-0 <?= $sc['node'] ?>">
                                <?php if ($status === 'Done'): ?>
                                    <i class="fas fa-check"></i>
                                <?php elseif ($status === 'Ongoing'): ?>
                                    <i class="fas fa-circle-notch fa-spin"></i>
                                <?php else: ?>
                                    <i class="fas <?= $icon ?> text-[10px]"></i>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="master-label text-[13px] font-bold text-black truncate flex items-center gap-1.5">
                                    <?= htmlspecialchars($stage) ?>
                                    <?php if ($masterHasAlert): ?>
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500 flex-shrink-0"></span>
                                    <?php endif; ?>
                                </div>
                                <div class="master-sub text-[10px] font-semibold uppercase tracking-wide <?= $sc['text'] ?>">
                                    <?php if ($isLocked): ?>
                                        <i class="fas fa-lock text-[9px]"></i> Locked
                                    <?php else: ?>
                                        <?= htmlspecialchars($status) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <i class="master-chevron fas fa-chevron-right text-[9px] text-neutral-300 flex-shrink-0"></i>
                        </div>

                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── DETAIL: selected stage ── -->
            <div id="detailPanelWrap">

                <!-- Project overview status bar -->
                <div class="bg-white border border-neutral-200 rounded-2xl shadow-sm overflow-hidden mb-5">
                    <div class="flex items-center flex-wrap gap-y-4 gap-x-6 px-5 sm:px-7 py-5">
                        <div class="flex items-center gap-2.5 pr-6 mr-2 border-r border-neutral-100 flex-shrink-0">
                            <div class="w-8 h-8 rounded-full bg-neutral-900 flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-layer-group text-white text-[11px]"></i>
                            </div>
                            <div>
                                <div class="text-[13px] font-bold text-black leading-tight">Project overview</div>
                                <div class="text-[10px] text-neutral-400 font-semibold uppercase tracking-wide"><?= $total_stages ?> stages total</div>
                            </div>
                        </div>

                        <div class="flex items-center gap-6 sm:gap-8 flex-1 justify-between sm:justify-start">
                            <div class="flex items-center gap-3 group">
                                <div class="relative w-12 h-12 rounded-full bg-neutral-50 border border-neutral-200 flex items-center justify-center text-[16px] font-bold text-neutral-700 transition-transform group-hover:scale-105">
                                    <?= $pending_count ?>
                                </div>
                                <div>
                                    <div class="text-[12px] font-bold text-black leading-tight">Pending</div>
                                    <div class="text-[10px] text-neutral-400">Not started</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 group">
                                <div class="relative w-12 h-12 rounded-full bg-blue-50 border border-blue-200 flex items-center justify-center text-[16px] font-bold text-blue-600 transition-transform group-hover:scale-105">
                                    <?= $ongoing_count ?>
                                </div>
                                <div>
                                    <div class="text-[12px] font-bold text-black leading-tight">Ongoing</div>
                                    <div class="text-[10px] text-neutral-400">In progress</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 group">
                                <div class="relative w-12 h-12 rounded-full bg-emerald-50 border border-emerald-200 flex items-center justify-center text-[16px] font-bold text-emerald-600 transition-transform group-hover:scale-105">
                                    <?= $done_count ?>
                                </div>
                                <div>
                                    <div class="text-[12px] font-bold text-black leading-tight">Done</div>
                                    <div class="text-[10px] text-neutral-400">Completed</div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2.5 flex-shrink-0 ml-auto">
                            <span class="text-[18px] font-bold text-black font-mono"><?= number_format($completion_percentage, 0) ?>%</span>
                            <div class="w-20 h-1.5 bg-neutral-100 rounded-full overflow-hidden">
                                <div class="h-full bg-neutral-900 rounded-full transition-all duration-500" style="width:<?= $completion_percentage ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php foreach ($stages as $index => $stage):

                    $stageData = $trackerData[$stage] ?? null;
                    $canUpdate = isset($permissions[$stage]) ? $permissions[$stage] : false;
                    $isApproval = in_array($stage, $approvalStages);
                    $isFileUpload = in_array($stage, $fileUploadStages);
                    $isAuto = in_array($stage, $autoStages);

                    // Sequential lock
                    $isLocked = false;
                    if ($tracker_mode === 'sequential' && $index > 0) {
                        if ($index >= 6) {
                            $prevStatus = isset($trackerData[$stages[$index - 1]]) ? $trackerData[$stages[$index - 1]]['status'] : 'Pending';

                            $itemDepStages = ['Delivery' => 'Fabrication', 'Installation' => 'Delivery', 'BILLING' => 'Installation'];
                            if (isset($itemDepStages[$stage])) {
                                $prevItemCol = strtolower($itemDepStages[$stage]) . '_status';
                                $itemDepStmt = $conn->prepare("
                    SELECT COUNT(*) AS has_done
                    FROM (
                        SELECT $prevItemCol FROM quotation_entries WHERE client_id = ? AND $prevItemCol = 'Done'
                        UNION ALL
                        SELECT $prevItemCol FROM quotation_fixed_sizes WHERE client_id = ? AND $prevItemCol = 'Done'
                    ) x
                ");
                                $itemDepStmt->bind_param("ii", $client_id, $client_id);
                                $itemDepStmt->execute();
                                $hasDoneItem = (int) $itemDepStmt->get_result()->fetch_assoc()['has_done'];

                                if ($hasDoneItem === 0) {
                                    $isLocked = true;
                                    $canUpdate = false;
                                }
                            } else {
                                if ($prevStatus === 'Pending') {
                                    $isLocked = true;
                                    $canUpdate = false;
                                }
                            }
                        }
                    }

                    // ── Compute status ──────────────────────────────────────────────
                    $status = $stageData ? $stageData['status'] : 'Pending';

                    if ($stage === 'Downpayment') {
                        $dpStmt = $conn->prepare("SELECT status FROM payment_schedule WHERE client_id = ? AND payment_type LIKE '%Down%' LIMIT 1");
                        $dpStmt->bind_param("i", $client_id);
                        $dpStmt->execute();
                        $dpRow = $dpStmt->get_result()->fetch_assoc();
                        $status = ($dpRow && $dpRow['status'] === 'Paid') ? 'Done' : 'Pending';

                        if ($stageData && $stageData['status'] !== $status) {
                            $syncDpStmt = $conn->prepare("UPDATE project_tracker SET status = ?, updated_at = NOW() WHERE id = ?");
                            $syncDpStmt->bind_param("si", $status, $stageData['id']);
                            $syncDpStmt->execute();
                            $trackerData[$stage]['status'] = $status;
                        }
                    } elseif ($stage === 'BILLING') {
                        $bStmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status='Paid' THEN 1 ELSE 0 END) AS paid FROM payment_schedule WHERE client_id = ? AND payment_type NOT LIKE '%Down Payment%'");
                        $bStmt->bind_param("i", $client_id);
                        $bStmt->execute();
                        $bRow = $bStmt->get_result()->fetch_assoc();

                        $dpChk = $conn->prepare("SELECT COUNT(*) AS dp FROM payment_schedule WHERE client_id = ? AND payment_type LIKE '%Down Payment%' AND status='Paid'");
                        $dpChk->bind_param("i", $client_id);
                        $dpChk->execute();
                        $dpPaid = $dpChk->get_result()->fetch_assoc()['dp'] > 0;

                        $hasCollections = $bRow['total'] > 0;
                        $allCollectionsPaid = $hasCollections && $bRow['paid'] == $bRow['total'];

                        if (($client['business_type'] ?? '') === 'Project') {
                            $instStmt = $conn->prepare("
                SELECT CASE
                    WHEN COUNT(*) = 0 THEN 0
                    WHEN COUNT(*) = SUM(CASE WHEN installation_status = 'Done' THEN 1 ELSE 0 END) THEN 1
                    ELSE 0
                END AS all_done
                FROM (
                    SELECT installation_status FROM quotation_entries WHERE client_id = ?
                    UNION ALL
                    SELECT installation_status FROM quotation_fixed_sizes WHERE client_id = ?
                ) x
            ");
                            $instStmt->bind_param("ii", $client_id, $client_id);
                            $instStmt->execute();
                            $instAllDone = (bool) ($instStmt->get_result()->fetch_assoc()['all_done'] ?? false);

                            if ($allCollectionsPaid && $instAllDone)
                                $status = 'Done';
                            elseif ($bRow['paid'] > 0 || $dpPaid)
                                $status = 'Ongoing';
                            else
                                $status = 'Pending';

                        } else {
                            $allPayStmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status='Paid' THEN 1 ELSE 0 END) AS paid FROM payment_schedule WHERE client_id = ?");
                            $allPayStmt->bind_param("i", $client_id);
                            $allPayStmt->execute();
                            $allPayRow = $allPayStmt->get_result()->fetch_assoc();

                            $anyPaid = $allPayRow['paid'] > 0;
                            $allPaid = $allPayRow['total'] > 0 && $allPayRow['paid'] == $allPayRow['total'];

                            if ($allPaid)
                                $status = 'Done';
                            elseif ($anyPaid)
                                $status = 'Ongoing';
                            else
                                $status = 'Pending';
                        }

                        if ($stageData && $stageData['status'] !== $status) {
                            $syncBillStmt = $conn->prepare("UPDATE project_tracker SET status = ?, updated_at = NOW() WHERE id = ?");
                            $syncBillStmt->bind_param("si", $status, $stageData['id']);
                            $syncBillStmt->execute();
                            $trackerData[$stage]['status'] = $status;
                        }
                    } elseif ($stage === 'Cuttinglist') {
                        $dpStmt2 = $conn->prepare("SELECT status FROM payment_schedule WHERE client_id = ? AND payment_type LIKE '%Down%' LIMIT 1");
                        $dpStmt2->bind_param("i", $client_id);
                        $dpStmt2->execute();
                        $dpRow2 = $dpStmt2->get_result()->fetch_assoc();
                        $downpaymentStatus = ($dpRow2 && $dpRow2['status'] === 'Paid') ? 'Done' : 'Pending';

                        if ($status === 'Done') {
                            $status = 'Done';
                        } elseif (in_array($downpaymentStatus, ['Ongoing', 'Done'])) {
                            $status = 'Ongoing';
                            if ($stageData && $stageData['status'] !== 'Ongoing') {
                                $syncStmt = $conn->prepare("UPDATE project_tracker SET status = 'Ongoing', updated_at = NOW() WHERE id = ?");
                                $syncStmt->bind_param("i", $stageData['id']);
                                $syncStmt->execute();
                            }
                        }
                    } elseif (in_array($stage, ['Fabrication', 'Delivery', 'Installation'])) {
                        $col = strtolower($stage) . '_status';

                        $iStmt = $conn->prepare("
            SELECT CASE
                WHEN COUNT(*) = 0 THEN 'Pending'
                WHEN COUNT(*) = SUM(CASE WHEN $col = 'Done' THEN 1 ELSE 0 END) THEN 'Done'
                WHEN SUM(CASE WHEN $col IN ('Ongoing','Incomplete','Punchlist','Done') THEN 1 ELSE 0 END) > 0 THEN 'Ongoing'
                ELSE 'Pending'
            END AS s
            FROM (
                SELECT $col FROM quotation_entries WHERE client_id = ?
                UNION ALL
                SELECT $col FROM quotation_fixed_sizes WHERE client_id = ?
            ) x
        ");
                        $iStmt->bind_param("ii", $client_id, $client_id);
                        $iStmt->execute();
                        $status = $iStmt->get_result()->fetch_assoc()['s'] ?? 'Pending';
                    }

                    // Count files for this stage
                    $fileCount = 0;
                    if ($stageData && ($isApproval || $isFileUpload || $stage === 'Accounting (Order Processing)')) {
                        $fcStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM stage_approvals WHERE stage_id = ?");
                        $fcStmt->bind_param("i", $stageData['id']);
                        $fcStmt->execute();
                        $fileCount = $fcStmt->get_result()->fetch_assoc()['cnt'] ?? 0;
                    }

                    $statusClass = strtolower($status);
                    $typeBadge = getStageTypeBadge($stage, $approvalStages, $fileUploadStages, $autoStages);
                    $icon = getStageIcon($stage);
                    $updated_at = $stageData['updated_at'] ?? null;
                    $updatedBy = $stageData['updated_by_name'] ?? null;
                    $assigned = $stageData['assigned_people'] ?? [];

                    // Who can mark this stage as Done
                    $assignedDesigner1 = $clientInfo['designer1_id'] ?? null;
                    $assignedDesigner2 = $clientInfo['designer2_id'] ?? null;
                    $assignedTechDesign = $clientInfo['technical_designer_id'] ?? null;
                    $assignedProjCoord = $clientInfo['project_coordinator_id'] ?? null;

                    // Special link targets
                    $isHead = (bool) ($userInfo['is_head'] ?? false);
                    $stageLink = null;
                    if ($stage === 'Site Visit' && $isHead && $admin_role !== 'technical_designer') {
                        $stageLink = BASE_URL . "site-visit-manager?client_id={$client_id}";
                    } elseif ($stage === '2D / 3D Layout' && (in_array($admin_role, ['general_manager', 'operational_manager', 'sales']) || ($admin_role === 'designer' && $isHead) || ($admin_role === 'technical_designer' && $isHead))) {
                        $stageLink = BASE_URL . "designer-2d3d-layout?client_id={$client_id}";
                    } elseif (
                        in_array($stage, ['Fabrication', 'Delivery', 'Installation']) && ($canUpdate || (
                            $admin_role === 'technical_designer' && (
                                $userInfo['is_head'] == 1 ||
                                $admin_id == $assignedTechDesignId
                            )
                        ))
                    ) {
                        $isTDViewOnly = !$canUpdate && $admin_role === 'technical_designer';
                        $stageLink = BASE_URL . "item-tracker?client_id={$client_id}&stage=" . urlencode($stage)
                            . ($isTDViewOnly ? '&view_only=1' : '');
                    } elseif ($stage === 'BILLING' && ((isset($permissions['BILLING']) && $permissions['BILLING']) || $isAssignedToClient || $isAccountingRole)) {
                        $stageLink = BASE_URL . "payment-tracker?client_id={$client_id}";
                    } elseif ($stage === 'Downpayment' && ((isset($permissions['BILLING']) && $permissions['BILLING']) || $isAssignedToClient || $isAccountingRole)) {
                        $stageLink = BASE_URL . "payment-tracker?client_id={$client_id}";
                    }

                    // Files page link
                    $filesPageLink = BASE_URL . "stage-files?client_id={$client_id}&stage_id=" . ($stageData['id'] ?? 0) . "&stage=" . urlencode($stage);

                    // Tailwind status color sets
                    $statusColors = [
                        'pending' => [
                            'ring' => 'border-neutral-200',
                            'bg' => 'bg-neutral-100',
                            'text' => 'text-neutral-400',
                            'chip' => 'bg-neutral-100 text-neutral-500 border-neutral-300',
                            'left' => 'border-l-neutral-200',
                            'node' => 'bg-white text-neutral-300 border-2 border-neutral-200',
                        ],
                        'ongoing' => [
                            'ring' => 'border-blue-400',
                            'bg' => 'bg-blue-50',
                            'text' => 'text-blue-600',
                            'chip' => 'bg-blue-600 text-white border-blue-600',
                            'left' => 'border-l-blue-500',
                            'node' => 'bg-blue-600 text-white shadow-md ring-4 ring-blue-100',
                        ],
                        'done' => [
                            'ring' => 'border-emerald-300',
                            'bg' => 'bg-emerald-50',
                            'text' => 'text-emerald-500',
                            'chip' => 'bg-emerald-50 text-emerald-600 border-emerald-200',
                            'left' => 'border-l-emerald-300',
                            'node' => 'bg-emerald-500 text-white shadow-sm',
                        ],
                    ];
                    $sc = $statusColors[$statusClass] ?? $statusColors['pending'];
                    ?>

                    <div id="stage-detail-<?= $index ?>" class="stage-detail-panel <?= $index === 0 ? '' : 'hidden' ?>">
                        <div
                            class="bg-white border border-neutral-200 rounded-2xl p-5 sm:p-7 shadow-sm border-l-4 <?= $sc['left'] ?> <?= $isLocked ? 'opacity-80' : '' ?>">

                            <!-- Top row -->
                            <div class="flex justify-between items-start gap-3 mb-3 flex-wrap">
                                <div class="flex items-center gap-3 flex-1 min-w-0 flex-wrap">
                                    <div
                                        class="w-10 h-10 rounded-full flex items-center justify-center text-sm flex-shrink-0 <?= $sc['node'] ?>">
                                        <?php if ($status === 'Done'): ?>
                                            <i class="fas fa-check"></i>
                                        <?php elseif ($status === 'Ongoing'): ?>
                                            <i class="fas fa-circle-notch fa-spin text-[13px]"></i>
                                        <?php else: ?>
                                            <i class="fas <?= $icon ?> text-[13px]"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span
                                                class="font-mono text-[10px] font-semibold text-neutral-400 bg-neutral-100 border border-neutral-200 rounded px-1.5 py-0.5">
                                                <?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?>
                                            </span>
                                            <span class="text-base sm:text-lg font-bold text-black"><?= htmlspecialchars($stage) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-1.5 flex-shrink-0">
                                    <?php if ($isLocked): ?>
                                        <span
                                            class="inline-flex items-center gap-1 text-[10px] font-bold text-amber-700 bg-amber-100 border border-amber-300 rounded-full px-2 py-0.5">
                                            <i class="fas fa-lock"></i> Locked
                                        </span>
                                    <?php endif; ?>
                                    <span
                                        class="inline-flex items-center gap-1.5 border rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide <?= $sc['chip'] ?>">
                                        <?php if ($status === 'Done'): ?><i class="fas fa-check"></i>
                                        <?php elseif ($status === 'Ongoing'): ?><i class="fas fa-circle-notch fa-spin"></i>
                                        <?php else: ?><i class="fas fa-clock"></i>
                                        <?php endif; ?>
                                        <?= $status ?>
                                    </span>
                                </div>
                            </div>

                            <?php if ($stageLink && !$isLocked): ?>
                                <a href="<?= $stageLink ?>"
                                    class="w-full flex items-center justify-center gap-2 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold text-[13px] rounded-lg px-4 py-3 mb-3 transition-all">
                                    <i class="fas fa-arrow-right"></i> Open <?= htmlspecialchars($stage) ?> Page
                                </a>
                            <?php endif; ?>

                            <?php if ($isLocked): ?>
                                <div
                                    class="bg-amber-50 border border-amber-300 rounded-lg px-3.5 py-2.5 mb-3 text-[12px] text-amber-800 font-semibold flex items-center gap-2">
                                    <i class="fas fa-lock"></i> This stage is locked. Complete the previous stage first.
                                </div>
                            <?php endif; ?>

                            <!-- Type + file badges -->
                            <?php if ($typeBadge || ($stage === '2D / 3D Layout')): ?>
                                <div class="flex flex-wrap gap-1.5 mb-3">
                                    <?php if ($typeBadge): ?>
                                        <?php
                                        $tbClass = [
                                            'badge-approval' => 'bg-amber-100 text-amber-800 border-amber-300',
                                            'badge-upload' => 'bg-violet-100 text-violet-800 border-violet-300',
                                            'badge-auto' => 'bg-sky-100 text-sky-800 border-sky-300',
                                        ][$typeBadge['class']] ?? 'bg-neutral-100 text-neutral-700 border-neutral-300';
                                        ?>
                                        <span
                                            class="text-[10px] font-bold uppercase tracking-wide border rounded-full px-2 py-0.5 <?= $tbClass ?>"><?= $typeBadge['label'] ?></span>
                                    <?php endif; ?>
                                    <?php if ($stage === '2D / 3D Layout'): ?>
                                        <span
                                            class="text-[10px] font-bold uppercase tracking-wide bg-neutral-100 text-neutral-600 border border-neutral-300 rounded-full px-2 py-0.5">
                                            <i class="fas fa-sync-alt"></i> Rev <?= $current_revision ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($isAuto && !in_array($stage, ['Downpayment'])): ?>
                                        <span
                                            class="text-[10px] font-bold uppercase tracking-wide bg-sky-100 text-sky-800 border border-sky-300 rounded-full px-2 py-0.5">
                                            <i class="fas fa-bolt"></i> Auto-Tracked
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Meta info -->
                            <div class="flex flex-wrap gap-3.5 text-[11px] text-neutral-400 mt-1.5 pb-3 border-b border-neutral-100">
                                <?php if ($updated_at): ?>
                                    <span class="flex items-center gap-1"><i class="fas fa-clock"></i>
                                        <?= date('M d, Y · g:i A', strtotime($updated_at)) ?></span>
                                <?php endif; ?>
                                <?php if ($updatedBy): ?>
                                    <span class="flex items-center gap-1"><i class="fas fa-user-edit"></i>
                                        <?= htmlspecialchars($updatedBy) ?></span>
                                <?php endif; ?>
                                <?php
                                // Show deadline badge for stages that have one set
                                $deadlineStagesForBadge = $isNonProject
                                    ? ['2D / 3D Layout', 'Cuttinglist']
                                    : ['Samples Submitted TDS/SDS', '2D / 3D Layout', 'Cuttinglist'];
                                if (in_array($stage, $deadlineStagesForBadge)):
                                    $dlStmt = $conn->prepare("SELECT start_date, end_date, duration FROM stage_deadlines WHERE client_id = ? AND stage_name = ?");
                                    $dlStmt->bind_param("is", $client_id, $stage);
                                    $dlStmt->execute();
                                    $dlRow = $dlStmt->get_result()->fetch_assoc();
                                    if ($dlRow && ($dlRow['start_date'] || $dlRow['end_date'])):
                                        $now = new DateTime();
                                        $endDt = $dlRow['end_date'] ? new DateTime($dlRow['end_date']) : null;
                                        $isOverdue = $endDt && $now > $endDt && $status !== 'Done';
                                        $dlClasses = $isOverdue
                                            ? 'bg-red-50 border-red-300 text-red-600'
                                            : 'bg-blue-50 border-blue-300 text-blue-600';
                                        ?>
                                        <span
                                            class="inline-flex items-center gap-1.5 border rounded-full px-2.5 py-0.5 text-[11px] font-bold <?= $dlClasses ?>">
                                            <i class="fas fa-<?= $isOverdue ? 'exclamation-circle' : 'calendar-alt' ?>"></i>
                                            <?php if ($dlRow['start_date']): ?>
                                                <?= date('M d', strtotime($dlRow['start_date'])) ?> →
                                            <?php endif; ?>
                                            <?php if ($dlRow['end_date']): ?>
                                                <?= date('M d, Y', strtotime($dlRow['end_date'])) ?>
                                            <?php endif; ?>
                                            <?php if ($isOverdue): ?>(Overdue)<?php endif; ?>
                                        </span>
                                    <?php endif; endif; ?>
                            </div>

                            <?php if ($stage === 'Site Visit' && $admin_role === 'designer' && $isHead): ?>
                                <?php
                                $rejectedVisitStmt = $conn->prepare("
        SELECT sv.id, sv.visit_date, a.full_name as rejected_by_name, sv.approval_comment
        FROM site_visit sv
        LEFT JOIN account a ON sv.approved_by = a.id
        WHERE sv.client_id = ? AND sv.approval_status = 'Rejected'
        ORDER BY sv.visit_date DESC
        LIMIT 1
    ");
                                $rejectedVisitStmt->bind_param("i", $client_id);
                                $rejectedVisitStmt->execute();
                                $rejectedVisit = $rejectedVisitStmt->get_result()->fetch_assoc();
                                ?>
                                <?php if ($rejectedVisit): ?>
                                    <div
                                        class="bg-red-50 border-2 border-red-400 rounded-lg px-3.5 py-2.5 mt-3 flex items-center justify-between gap-3 flex-wrap">
                                        <div class="flex items-center gap-2.5">
                                            <i class="fas fa-times-circle text-red-600 text-base flex-shrink-0"></i>
                                            <div>
                                                <div class="font-bold text-[13px] text-red-800">
                                                    Site visit rejected by
                                                    <?= htmlspecialchars($rejectedVisit['rejected_by_name'] ?? 'Manager') ?>
                                                </div>
                                                <?php if ($rejectedVisit['approval_comment']): ?>
                                                    <div class="text-[11px] text-red-600 mt-1 italic">
                                                        "<?= htmlspecialchars($rejectedVisit['approval_comment']) ?>"
                                                    </div>
                                                <?php endif; ?>
                                                <div class="text-[11px] text-red-600 mt-1">
                                                    Open Site Visit Manager to edit and resubmit.
                                                </div>
                                            </div>
                                        </div>
                                        <?php if (isset($stageLink) && $stageLink): ?>
                                            <a href="<?= $stageLink ?>" class="<?= $BTN_DANGER ?> flex-shrink-0">
                                                <i class="fas fa-arrow-right"></i> Fix & Resubmit
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($stage === '2D / 3D Layout' && $layoutPendingCount > 0): ?>
                                <div
                                    class="bg-amber-50 border-2 border-amber-400 rounded-lg px-3.5 py-2.5 mt-3 flex items-center justify-between gap-3 flex-wrap">
                                    <div class="flex items-center gap-2.5">
                                        <i class="fas fa-bell text-amber-600 text-base flex-shrink-0"></i>
                                        <div>
                                            <div class="font-bold text-[13px] text-amber-800">
                                                <?= $layoutPendingCount ?> pending approval<?= $layoutPendingCount > 1 ? 's' : '' ?>
                                                waiting for your review
                                            </div>
                                            <div class="text-[11px] text-amber-600 mt-0.5">
                                                Go to the 2D/3D layout page to approve or reject.
                                            </div>
                                        </div>
                                    </div>
                                    <a href="designer-2d3d-layout?client_id=<?= $client_id ?>"
                                        class="<?= $BTN_PRIMARY ?> !bg-amber-600 hover:!bg-amber-700 flex-shrink-0">
                                        <i class="fas fa-arrow-right"></i> Go to 2D/3D Layout
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php
                            $isHead = (bool) ($userInfo['is_head'] ?? false);
                            $approvalStagesForNotif = ['Rough Estimation', 'Samples Submitted TDS/SDS', 'Quotation', 'Bill of Materials (BOM)', 'Purchase Order (Submit to accounting)'];
                            if (in_array($stage, $approvalStagesForNotif) && $stageData):
                                $stagePendingCount = getStagePendingApprovalCount($conn, $admin_id, $admin_role, $isHead, $stageData['id']);
                                if ($stagePendingCount > 0):
                                    ?>
                                    <div
                                        class="bg-amber-50 border-2 border-amber-400 rounded-lg px-3.5 py-2.5 mt-3 flex items-center justify-between gap-3 flex-wrap">
                                        <div class="flex items-center gap-2.5">
                                            <i class="fas fa-bell text-amber-600 text-base flex-shrink-0"></i>
                                            <div>
                                                <div class="font-bold text-[13px] text-amber-800">
                                                    <?= $stagePendingCount ?> file<?= $stagePendingCount > 1 ? 's' : '' ?> waiting for
                                                    your approval
                                                </div>
                                                <div class="text-[11px] text-amber-600 mt-0.5">
                                                    Open the files page to review and approve or reject.
                                                </div>
                                            </div>
                                        </div>
                                        <a href="<?= $filesPageLink ?>"
                                            class="<?= $BTN_PRIMARY ?> !bg-amber-600 hover:!bg-amber-700 flex-shrink-0">
                                            <i class="fas fa-arrow-right"></i> Review Files
                                        </a>
                                    </div>
                                <?php endif; endif; ?>

                            <!-- Internal P.O to Accounting: stage-level approval notification -->
                            <?php if ($stage === 'Internal P.O to Accounting' && $stageData):
                                $ipoNotifStmt = $conn->prepare("SELECT * FROM internal_po_approvals WHERE stage_id = ? ORDER BY id DESC LIMIT 1");
                                $ipoNotifStmt->bind_param("i", $stageData['id']);
                                $ipoNotifStmt->execute();
                                $ipoNotif = $ipoNotifStmt->get_result()->fetch_assoc();

                                $showIpoNotif = false;
                                $ipoNotifMsg = '';
                                if ($ipoNotif) {
                                    if ($admin_role === 'accounting' && $ipoNotif['accounting_status'] === 'pending' && $ipoNotif['overall_status'] === 'pending') {
                                        $showIpoNotif = true;
                                        $ipoNotifMsg = 'All files are ready for your review — please approve or add a remark.';
                                    } elseif ($admin_role === 'designer' && !empty($userInfo['is_head']) && $ipoNotif['designer_status'] === 'pending' && $ipoNotif['accounting_status'] === 'approved' && $ipoNotif['overall_status'] === 'pending') {
                                        $showIpoNotif = true;
                                        $ipoNotifMsg = 'Accounting has approved. Please review and approve or add a remark.';
                                    }
                                }
                                if ($showIpoNotif): ?>
                                    <div
                                        class="bg-amber-50 border-2 border-amber-400 rounded-lg px-3.5 py-2.5 mt-3 flex items-center justify-between gap-3 flex-wrap">
                                        <div class="flex items-center gap-2.5">
                                            <i class="fas fa-bell text-amber-600 text-base flex-shrink-0"></i>
                                            <div>
                                                <div class="font-bold text-[13px] text-amber-800">Internal P.O needs your review</div>
                                                <div class="text-[11px] text-amber-600 mt-0.5"><?= $ipoNotifMsg ?></div>
                                            </div>
                                        </div>
                                        <a href="<?= $filesPageLink ?>"
                                            class="<?= $BTN_PRIMARY ?> !bg-amber-600 hover:!bg-amber-700 flex-shrink-0">
                                            <i class="fas fa-arrow-right"></i> Review
                                        </a>
                                    </div>
                                <?php endif; endif; ?>

                            <!-- Approved PO not yet ordered notification -->
                            <?php
                            if ($stage === 'Purchase Order (Submit to accounting)' && $stageData):
                                $approvedPoNotOrderedStmt = $conn->prepare("
        SELECT COUNT(*) FROM stage_approvals sa
        JOIN project_tracker pt ON sa.stage_id = pt.id
        LEFT JOIN bom_order_status bos ON bos.bom_approval_id = sa.linked_bom_id
        WHERE pt.client_id = ?
          AND pt.stage_name = 'Purchase Order (Submit to accounting)'
          AND sa.approval_status = 'approved'
          AND (bos.status IS NULL OR bos.status IN ('pending', 'partially_ordered'))
    ");
                                $approvedPoNotOrderedStmt->bind_param("i", $client_id);
                                $approvedPoNotOrderedStmt->execute();
                                $approvedPoNotOrderedCount = (int) $approvedPoNotOrderedStmt->get_result()->fetch_row()[0];
                                if ($approvedPoNotOrderedCount > 0 && ($admin_role === 'project_coordinator' && $admin_id == $assignedProjCoordId)):
                                    ?>
                                    <div
                                        class="bg-blue-50 border-2 border-blue-400 rounded-lg px-3.5 py-2.5 mt-3 flex items-center justify-between gap-3 flex-wrap">
                                        <div class="flex items-center gap-2.5">
                                            <i class="fas fa-shopping-cart text-blue-600 text-base flex-shrink-0"></i>
                                            <div>
                                                <div class="font-bold text-[13px] text-blue-800">
                                                    <?= $approvedPoNotOrderedCount ?> approved
                                                    PO<?= $approvedPoNotOrderedCount > 1 ? 's are' : ' is' ?> not yet fully ordered
                                                </div>
                                                <div class="text-[11px] text-blue-600 mt-0.5">
                                                    Open the Purchase Order files page to update the order status.
                                                </div>
                                            </div>
                                        </div>
                                        <a href="<?= $filesPageLink ?>"
                                            class="<?= $BTN_PRIMARY ?> !bg-blue-600 hover:!bg-blue-700 flex-shrink-0">
                                            <i class="fas fa-arrow-right"></i> Update Order Status
                                        </a>
                                    </div>
                                <?php endif; endif; ?>
                            <!-- End Approved PO not yet ordered notification -->

                            <!-- PO Missing notification — show when BOM is ordered but no PO submitted yet -->
                            <?php
                            if ($stage === 'Purchase Order (Submit to accounting)' && $stageData):
                                $missingPoStmt = $conn->prepare("
        SELECT sa.id, sa.label, sa.file_name,
               COALESCE(bos.status, 'pending') as order_status
        FROM stage_approvals sa
        LEFT JOIN bom_order_status bos ON bos.bom_approval_id = sa.id
        WHERE sa.stage_id = (
            SELECT id FROM project_tracker 
            WHERE client_id = ? AND stage_name = 'Bill of Materials (BOM)' LIMIT 1
        )
        AND sa.approval_status = 'approved'
        AND NOT EXISTS (
            SELECT 1 FROM stage_approvals po
            WHERE po.stage_id = ? AND po.linked_bom_id = sa.id
        )
    ");
                                $missingPoStmt->bind_param("ii", $client_id, $stageData['id']);
                                $missingPoStmt->execute();
                                $missingPoResult = $missingPoStmt->get_result();
                                $missingPoBoms = [];
                                while ($mbRow = $missingPoResult->fetch_assoc()) {
                                    $missingPoBoms[] = $mbRow;
                                }
                                $missingPoCount = count($missingPoBoms);
                                if ($missingPoCount > 0 && ($canUpdate || $isAssignedToClient)):
                                    ?>
                                    <div
                                        class="bg-amber-50 border-2 border-amber-400 rounded-lg px-3.5 py-2.5 mt-3 flex items-center justify-between gap-3 flex-wrap">
                                        <div class="flex items-center gap-2.5">
                                            <i class="fas fa-bell text-amber-600 text-base flex-shrink-0"></i>
                                            <div>
                                                <div class="font-bold text-[13px] text-amber-800">
                                                    <?= $missingPoCount ?> approved BOM<?= $missingPoCount > 1 ? 's have' : ' has' ?> no
                                                    Purchase Order submitted yet
                                                </div>
                                                <div class="text-[11px] text-amber-600 mt-0.5">
                                                    Open the Purchase Order page to submit a PO for
                                                    <?= $missingPoCount > 1 ? 'each BOM' : 'this BOM' ?>.
                                                </div>
                                            </div>
                                        </div>
                                        <a href="<?= $filesPageLink ?>"
                                            class="<?= $BTN_PRIMARY ?> !bg-amber-600 hover:!bg-amber-700 flex-shrink-0">
                                            <i class="fas fa-arrow-right"></i> Submit PO
                                        </a>
                                    </div>
                                <?php endif; endif; ?>
                            <!-- End PO Missing notification -->

                            <!-- Rejection notification for uploader -->
                            <?php
                            $isMyUpload = false;
                            if ($stageData) {
                                if ($stage === 'Internal P.O to Accounting') {
                                    $myUploadCheckStmt = $conn->prepare("
                                    SELECT COUNT(*) FROM internal_po_approvals ipa
                                    JOIN stage_approvals sa ON sa.stage_id = ipa.stage_id
                                    WHERE ipa.stage_id = ? 
                                    AND sa.uploaded_by = ?
                                    AND ipa.overall_status = 'rejected'
                                ");
                                    $myUploadCheckStmt->bind_param("ii", $stageData['id'], $admin_id);
                                } else {
                                    $myUploadCheckStmt = $conn->prepare("SELECT COUNT(*) FROM stage_approvals WHERE stage_id = ? AND uploaded_by = ? AND approval_status = 'rejected'");
                                    $myUploadCheckStmt->bind_param("ii", $stageData['id'], $admin_id);
                                }
                                $myUploadCheckStmt->execute();
                                $isMyUpload = (int) $myUploadCheckStmt->get_result()->fetch_row()[0] > 0;
                            }
                            if (($isApproval || $stage === 'Internal P.O to Accounting') && $stageData && ($canUpdate || $isMyUpload)):
                                if ($stage === 'Internal P.O to Accounting') {
                                    $rejectedFileStmt = $conn->prepare("
                                    SELECT sa.id, sa.label, sa.file_name, 
                                        COALESCE(ipa.accounting_remark, ipa.designer_remark) as review_note,
                                        COALESCE(ac.full_name, dc.full_name) as reviewer_name,
                                        CASE WHEN ipa.accounting_status = 'rejected' THEN 'accounting' ELSE 'designer' END as reviewer_role
                                    FROM stage_approvals sa
                                    JOIN internal_po_approvals ipa ON ipa.stage_id = sa.stage_id
                                    LEFT JOIN account ac ON ipa.accounting_reviewed_by = ac.id
                                    LEFT JOIN account dc ON ipa.designer_reviewed_by = dc.id
                                    WHERE sa.stage_id = ?
                                    AND sa.uploaded_by = ?
                                    AND ipa.overall_status = 'rejected'
                                    ORDER BY ipa.id DESC
                                    LIMIT 1
                                ");
                                } else {
                                    $rejectedFileStmt = $conn->prepare("
                                    SELECT sa.id, sa.label, sa.file_name, sar.review_note, a.full_name as reviewer_name, sar.reviewer_role
                                    FROM stage_approvals sa
                                    JOIN stage_approval_reviews sar ON sar.approval_id = sa.id
                                    LEFT JOIN account a ON sar.reviewed_by = a.id
                                    WHERE sa.stage_id = ?
                                      AND sa.uploaded_by = ?
                                      AND sa.approval_status = 'rejected'
                                      AND sar.review_status = 'rejected'
                                    ORDER BY sar.reviewed_at DESC
                                    LIMIT 1
                                ");
                                }
                                $rejectedFileStmt->bind_param("ii", $stageData['id'], $admin_id);
                                $rejectedFileStmt->execute();
                                $rejectedFile = $rejectedFileStmt->get_result()->fetch_assoc();
                                ?>
                                <?php if (!empty($rejectedFile)): ?>
                                    <div
                                        class="bg-red-50 border-2 border-red-400 rounded-lg px-3.5 py-2.5 mt-3 flex items-center justify-between gap-3 flex-wrap">
                                        <div class="flex items-center gap-2.5">
                                            <i class="fas fa-times-circle text-red-600 text-base flex-shrink-0"></i>
                                            <div>
                                                <div class="font-bold text-[13px] text-red-800">
                                                    Your file
                                                    "<?= htmlspecialchars($rejectedFile['label'] ?: $rejectedFile['file_name']) ?>" was
                                                    rejected
                                                    <?php if ($rejectedFile['reviewer_name']): ?>
                                                        by <?= htmlspecialchars($rejectedFile['reviewer_name']) ?>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($rejectedFile['review_note']): ?>
                                                    <div class="text-[11px] text-red-600 mt-1 italic">
                                                        "<?= htmlspecialchars($rejectedFile['review_note']) ?>"
                                                    </div>
                                                <?php endif; ?>
                                                <div class="text-[11px] text-red-600 mt-1">
                                                    Open the files page to re-submit.
                                                </div>
                                            </div>
                                        </div>
                                        <a href="<?= $filesPageLink ?>" class="<?= $BTN_DANGER ?> flex-shrink-0">
                                            <i class="fas fa-redo"></i> Re-submit File
                                        </a>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <!-- End rejection notification -->

                            <!-- Assigned people -->
                            <?php if (!empty($assigned)): ?>
                                <div class="flex flex-wrap gap-1.5 mt-3">
                                    <?php foreach ($assigned as $person): ?>
                                        <span
                                            class="inline-flex items-center gap-1 bg-neutral-100 border border-neutral-200 rounded-full px-2.5 py-1 text-[11px] font-semibold text-neutral-600">
                                            <i class="fas fa-user text-[9px]"></i> <?= htmlspecialchars($person) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Actions -->
                            <?php
                            $alwaysUnlockedStages = ['Rough Estimation', 'Site Visit', '2D / 3D Layout', 'Reference', 'Samples Submitted TDS/SDS', 'Quotation'];
                            if ($isNonProject) {
                                $alwaysUnlockedStages = array_values(array_filter($alwaysUnlockedStages, function ($s) {
                                    return $s !== 'Samples Submitted TDS/SDS';
                                }));
                            }
                            $isAlwaysUnlocked = in_array($stage, $alwaysUnlockedStages);

                            $sequentialActive = ($tracker_mode !== 'sequential') || ($status === 'Ongoing' || $status === 'Done') || !$isLocked || $isAlwaysUnlocked;
                            $isReferenceAssigned = ($stage === 'Reference') && (
                                $admin_id == $assignedDesigner1Id ||
                                $admin_id == $assignedDesigner2Id ||
                                $admin_id == ($ptAssignedRow['accountaid_fk'] ?? null)
                            );
                            $hasActions = (($isFileUpload && $canUpdate) || $isApproval || $isFileUpload || $stage === 'Accounting (Order Processing)' || $isReferenceAssigned || ($stage === 'Production Data Submittals' && (
                                $canUpdate ||
                                ($admin_role === 'technical_designer' && (
                                    $userInfo['is_head'] == 1 ||
                                    $admin_id == $assignedTechDesignId
                                ))
                            ))) && $sequentialActive;
                            ?>
                            <?php if ($hasActions): ?>
                                <div class="flex gap-2 flex-wrap items-center mt-4 pt-4 border-t border-neutral-200">

                                    <?php
                                    $canMarkDone = false;
                                    $canCancelDone = false;
                                    if ($stage === 'Production Data Submittals' && !$isLocked) {
                                        $canActOnPDS = (
                                            $canUpdate ||
                                            ($admin_role === 'technical_designer' && (
                                                $userInfo['is_head'] == 1 ||
                                                $admin_id == $assignedTechDesignId
                                            ))
                                        );
                                        // No action for GM/OM — they view only
                                    } elseif ($isFileUpload && $canUpdate && !$isLocked) {
                                        if ($stage === 'Reference') {
                                            $isReferenceUser = (
                                                $admin_id == $assignedDesigner1Id ||
                                                $admin_id == $assignedDesigner2Id ||
                                                $admin_id == ($ptAssignedRow['accountaid_fk'] ?? null)
                                            );
                                            if ($isReferenceUser && in_array($status, ['Pending', 'Ongoing'])) {
                                                $canMarkDone = true;
                                            }
                                            if ($isReferenceUser && $status === 'Done') {
                                                $canCancelDone = true;
                                            }
                                        } elseif ($stage === 'Internal P.O to Accounting') {
                                            $ipoCheckStmt = $conn->prepare("SELECT id FROM internal_po_approvals WHERE stage_id = ? AND overall_status = 'approved' LIMIT 1");
                                            $ipoCheckStmt->bind_param("i", $stageData['id']);
                                            $ipoCheckStmt->execute();
                                            $ipoApprovedRow = $ipoCheckStmt->get_result()->fetch_assoc();
                                            $canMarkDone = !empty($ipoApprovedRow) && ($admin_id == $assignedProjCoordId || $admin_role === 'sales') && $status === 'Ongoing';
                                        } elseif ($stage === 'Handover') {
                                            $canMarkDone = ($admin_id == $assignedTechDesignId || $admin_id == $assignedProjCoordId) && $status === 'Ongoing';
                                        }
                                    }
                                    ?>
                                    <?php if ($stage === 'Production Data Submittals' && $canActOnPDS): ?>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span
                                                class="text-[11px] font-bold text-neutral-400 uppercase tracking-wide">Set
                                                Status:</span>
                                            <button
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-[11px] font-bold border transition
                                                <?= $status === 'Pending' ? 'bg-amber-500 text-white border-amber-500 ring-2 ring-amber-300' : 'bg-amber-50 text-amber-700 border-amber-300 hover:bg-amber-100' ?>"
                                                onclick="setPDSStatus(<?= $stageData['id'] ?>, 'Pending')" <?= $status === 'Pending' ? 'disabled' : '' ?>>
                                                <i class="fas fa-clock"></i> Pending
                                            </button>
                                            <button
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-[11px] font-bold border transition
                                                <?= $status === 'Ongoing' ? 'bg-blue-600 text-white border-blue-600 ring-2 ring-blue-300' : 'bg-blue-50 text-blue-700 border-blue-300 hover:bg-blue-100' ?>"
                                                onclick="setPDSStatus(<?= $stageData['id'] ?>, 'Ongoing')" <?= $status === 'Ongoing' ? 'disabled' : '' ?>>
                                                <i class="fas fa-circle-notch"></i> Ongoing
                                            </button>
                                            <button
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-[11px] font-bold border transition
                                                <?= $status === 'Done' ? 'bg-emerald-600 text-white border-emerald-600 ring-2 ring-emerald-300' : 'bg-emerald-50 text-emerald-700 border-emerald-300 hover:bg-emerald-100' ?>"
                                                onclick="setPDSStatus(<?= $stageData['id'] ?>, 'Done')" <?= $status === 'Done' ? 'disabled' : '' ?>>
                                                <i class="fas fa-check-circle"></i> Done
                                            </button>
                                        </div>
                                    <?php elseif ($canMarkDone): ?>
                                        <button class="<?= $BTN_PRIMARY ?> !bg-emerald-600 hover:!bg-emerald-700"
                                            onclick="markDone(<?= $stageData['id'] ?>)">
                                            <i class="fas fa-check-circle"></i> Mark as Done
                                        </button>
                                    <?php endif; ?>
                                    <?php if (!($stage === 'Production Data Submittals') && $canCancelDone): ?>
                                        <button class="<?= $BTN_DANGER ?>" onclick="cancelDone(<?= $stageData['id'] ?>)">
                                            <i class="fas fa-undo"></i> Revert to Ongoing
                                        </button>
                                    <?php endif; ?>

                                    <!-- Files button -->
                                    <?php if (($isApproval || $isFileUpload || $stage === 'Accounting (Order Processing)') && $stage !== 'Production Data Submittals' && $stageData && $sequentialActive): ?>
                                        <a href="<?= $filesPageLink ?>" class="<?= $BTN_GHOST ?> ml-auto">
                                            <span
                                                class="w-1.5 h-1.5 rounded-full <?= $fileCount > 0 ? 'bg-emerald-500' : 'bg-neutral-300' ?>"></span>
                                            <i class="fas fa-paperclip"></i>
                                            <?= $fileCount ?> file<?= $fileCount !== 1 ? 's' : '' ?>
                                            <i class="fas fa-chevron-right text-[9px] opacity-50"></i>
                                        </a>
                                    <?php endif; ?>

                                </div>
                            <?php endif; ?>

                        </div>
                    </div>

                <?php endforeach;

                // Recalculate progress counts using synced statuses from DB
                $pending_count = $ongoing_count = $done_count = 0;
                foreach ($trackerData as $data) {
                    if ($data['status'] === 'Pending')
                        $pending_count++;
                    elseif ($data['status'] === 'Ongoing')
                        $ongoing_count++;
                    elseif ($data['status'] === 'Done')
                        $done_count++;
                }
                $completion_percentage = ($done_count / $total_stages) * 100;
                ?>
            </div><!-- /detailPanelWrap -->

        </div><!-- /split master-detail -->

    </div><!-- /page -->

    <!-- Client Detail Modal -->
    <?php
    $house_state = $client['house_state'] ?? '';
    $permit_required = $client['permit_required'] ?? '';
    $target_movein_date = $client['target_movein_date'] ?? '';
    ?>
    <div id="clientDetailModal"
        class="hidden fixed inset-0 z-[9999] bg-black/50 items-center justify-center p-5"
        onclick="if(event.target===this){this.classList.add('hidden');this.classList.remove('flex');}">
        <div class="bg-white rounded-2xl p-7 max-w-xl w-full max-h-[90vh] overflow-y-auto shadow-2xl">
            <div class="flex justify-between items-center mb-4 pb-3.5 border-b-2 border-neutral-100">
                <div class="text-lg font-bold text-black flex items-center gap-2">
                    <i class="fas fa-user-circle text-neutral-500"></i> Client Details
                </div>
                <button class="text-xl text-neutral-400 hover:text-neutral-700 p-1 leading-none"
                    onclick="document.getElementById('clientDetailModal').classList.add('hidden'); document.getElementById('clientDetailModal').classList.remove('flex');">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <?php
            function trackerModalRow($label, $valueHtml)
            {
                echo '<div class="grid grid-cols-1 sm:grid-cols-[160px_1fr] gap-1.5 sm:gap-2.5 py-2.5 border-b border-neutral-100 items-start last:border-b-0">';
                echo '<div class="font-semibold text-neutral-500 text-[13px]">' . $label . '</div>';
                echo '<div class="text-black text-[13px] break-words">' . $valueHtml . '</div>';
                echo '</div>';
            }
            trackerModalRow('Reference Number', '<span class="text-blue-600 font-mono font-semibold">' . htmlspecialchars($client['reference_number'] ?? '') . '</span>');
            trackerModalRow('Client Name', htmlspecialchars($client['clientname']));
            trackerModalRow('Project Name', htmlspecialchars($client['nameproject']));

            $st = $client['status'] ?? '';
            $stClasses = strtolower($st) === 'new client' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800';
            trackerModalRow('Status', '<span class="inline-block px-3 py-0.5 rounded-full text-[11px] font-bold ' . $stClasses . '">' . htmlspecialchars($st) . '</span>');

            trackerModalRow('Business Type', htmlspecialchars($business_type_label));

            if (!empty($client['contact']))
                trackerModalRow('Phone', htmlspecialchars($client['contact']));
            if (!empty($client['email']))
                trackerModalRow('Email', htmlspecialchars($client['email']));
            if (!empty($client['address']))
                trackerModalRow('Address', htmlspecialchars($client['address']));
            if (!empty($client['gender']))
                trackerModalRow('Gender', htmlspecialchars($client['gender']));
            if (!empty($client['client_class']))
                trackerModalRow('Classification', htmlspecialchars($client['client_class']));
            if (!empty($client['client_type']))
                trackerModalRow('Client Type', htmlspecialchars($client['client_type']));
            if (!empty($client['project_scope']))
                trackerModalRow('Project Scope', nl2br(htmlspecialchars($client['project_scope'])));
            if (!empty($client['scope_of_work']))
                trackerModalRow('Scope of Work', nl2br(htmlspecialchars($client['scope_of_work'])));

            if ($house_state) {
                $hsClasses = 'bg-amber-100 text-amber-800';
                if ($house_state === 'Bare/Empty Lot')
                    $hsClasses = 'bg-blue-100 text-blue-800';
                elseif ($house_state === 'Construction Started')
                    $hsClasses = 'bg-red-100 text-red-800';
                elseif ($house_state === 'Renovation')
                    $hsClasses = 'bg-violet-100 text-violet-800';
                trackerModalRow('House State', '<span class="inline-block px-3 py-0.5 rounded-full text-xs font-bold ' . $hsClasses . '">' . htmlspecialchars($house_state) . '</span>');
            }

            if ($permit_required) {
                $prClasses = 'bg-amber-100 text-amber-800';
                if ($permit_required === 'Yes')
                    $prClasses = 'bg-red-100 text-red-800';
                elseif ($permit_required === 'No')
                    $prClasses = 'bg-emerald-100 text-emerald-800';
                trackerModalRow('Permit Required', '<span class="inline-block px-3 py-0.5 rounded-full text-xs font-bold ' . $prClasses . '">' . htmlspecialchars($permit_required) . '</span>');
            }

            if ($target_movein_date) {
                trackerModalRow('Target Move-in', '<span class="font-semibold"><i class="fas fa-calendar-check text-emerald-500"></i> ' . date('F d, Y', strtotime($target_movein_date)) . '</span>');
            }

            trackerModalRow('Total Project Cost', '<span class="font-bold text-black text-[15px]">₱' . number_format($client['total_project_cost'] ?? 0, 2) . '</span>');
            trackerModalRow('Remaining Balance', '<span class="font-bold text-red-600 text-[15px]">₱' . number_format($client['remaining_balance'] ?? 0, 2) . '</span>');
            ?>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast"
        class="fixed bottom-7 right-7 bg-black text-white px-5 py-3.5 rounded-xl text-[13px] font-semibold flex items-center gap-2.5 shadow-2xl z-[9999] pointer-events-none opacity-0 translate-y-20 transition-all duration-300">
        <i class="fas fa-check-circle"></i>
        <span id="toastMsg">Updated!</span>
    </div>

    <script>const TRACKER_BASE_URL = "<?= BASE_URL ?>";</script>
    <script src="<?= ADMIN_ASSET ?>/tracker-management/tracker-management/js/unified_project_tracker.js"></script>

    <!-- Master–detail panel switching (new UI logic only — does not touch existing tracker JS/API calls) -->
    <script>
        function selectStage(idx) {
            // Remember the open stage in the URL hash so markDone()/cancelDone()/setPDSStatus()
            // (which reload the page) reopen the same stage instead of resetting to default.
            history.replaceState(null, '', '#stage-' + idx);

            document.querySelectorAll('.stage-detail-panel').forEach(function (p) {
                p.classList.add('hidden');
            });
            var panel = document.getElementById('stage-detail-' + idx);
            if (panel) panel.classList.remove('hidden');

            document.querySelectorAll('.master-item').forEach(function (it) {
                it.classList.remove('bg-black', 'text-white', 'border-black', 'shadow-md');
                it.classList.add('border-neutral-200', 'bg-white');
                var label = it.querySelector('.master-label');
                if (label) { label.classList.remove('text-white'); label.classList.add('text-black'); }
                var sub = it.querySelector('.master-sub');
                if (sub) { sub.classList.remove('text-white/70'); }
                var chev = it.querySelector('.master-chevron');
                if (chev) { chev.classList.remove('text-white/50'); chev.classList.add('text-neutral-300'); }
            });

            var active = document.getElementById('master-item-' + idx);
            if (active) {
                active.classList.remove('border-neutral-200', 'bg-white');
                active.classList.add('bg-black', 'text-white', 'border-black', 'shadow-md');
                var label = active.querySelector('.master-label');
                if (label) { label.classList.add('text-white'); label.classList.remove('text-black'); }
                var sub = active.querySelector('.master-sub');
                if (sub) { sub.classList.add('text-white/70'); }
                var chev = active.querySelector('.master-chevron');
                if (chev) { chev.classList.add('text-white/50'); chev.classList.remove('text-neutral-300'); }
            }

            if (window.innerWidth < 1024) {
                var wrap = document.getElementById('detailPanelWrap');
                if (wrap) wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            var items = document.querySelectorAll('.master-item');
            var target = null;

            // 1) Prefer the stage saved in the URL hash — this is what makes markDone()/cancelDone()/
            //    setPDSStatus() (all of which end with location.reload()) reopen the same stage.
            var hashMatch = window.location.hash.match(/^#stage-(\d+)$/);
            if (hashMatch) {
                target = document.getElementById('master-item-' + hashMatch[1]);
            }

            // 2) Otherwise default to the first "Ongoing" stage
            if (!target) {
                items.forEach(function (it) {
                    if (!target && it.dataset.status === 'Ongoing') target = it;
                });
            }

            // 3) Otherwise fall back to the first stage
            if (!target && items.length) target = items[0];

            if (target) selectStage(parseInt(target.dataset.idx, 10));
        });
    </script>
</body>

</html>