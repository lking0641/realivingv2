<?php
// payment_tracker.php
include $includes ['mainbody'];

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

// Get admin role
$roleStmt = $conn->prepare("SELECT role, full_name FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();
$admin_role = $userInfo['role'];

$allowedRolesForAllClients = ['general_manager', 'operational_manager', 'accounting', 'superadmin'];
$canViewAllClients = in_array($admin_role, $allowedRolesForAllClients);

// Fetch client
if ($canViewAllClients) {
    $clientStmt = $conn->prepare("SELECT u.*, a.full_name as admin_name FROM user_info u LEFT JOIN account a ON u.accountaid_fk = a.id WHERE u.id = ?");
    $clientStmt->bind_param("i", $client_id);
} else {
    $clientStmt = $conn->prepare("SELECT u.*, a.full_name as admin_name FROM user_info u LEFT JOIN account a ON u.accountaid_fk = a.id WHERE u.id = ? AND u.accountaid_fk = ?");
    $clientStmt->bind_param("ii", $client_id, $admin_id);
}
$clientStmt->execute();
$client = $clientStmt->get_result()->fetch_assoc();
if (!$client)
    die("Access denied: Client not found or you don't have permission.");

// Permissions
$permissions = [];
if ($admin_role === 'sales') {
    $permStmt = $conn->prepare("SELECT stage_name, can_update FROM stage_permissions WHERE admin_id = ?");
    $permStmt->bind_param("i", $admin_id);
} else {
    $permStmt = $conn->prepare("SELECT stage_name, can_update FROM role_stage_permissions WHERE role = ?");
    $permStmt->bind_param("s", $admin_role);
}
$permStmt->execute();
$permRes = $permStmt->get_result();
while ($p = $permRes->fetch_assoc()) {
    $permissions[$p['stage_name']] = (bool) $p['can_update'];
}
$view_only = isset($_GET['view_only']) && $_GET['view_only'] == '1';
$canUpdate = $permissions['BILLING'] ?? false;

// Helper: get proof and accounting review for a payment

function getNTPInfo($conn, $payment_id)
{
    $stmt = $conn->prepare("
        SELECT n.*, a.full_name as uploader_name
        FROM notice_to_proceed n
        LEFT JOIN account a ON a.id = n.uploaded_by
        WHERE n.payment_id = ?
        ORDER BY n.id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}
function getPaymentProofInfo($conn, $payment_id)
{
    $stmt = $conn->prepare("
        SELECT pp.*, par.review_status, par.rejection_note, a.full_name as reviewer_name
        FROM payment_proofs pp
        LEFT JOIN payment_accounting_reviews par ON par.payment_id = pp.payment_id
        LEFT JOIN account a ON a.id = par.reviewed_by
        WHERE pp.payment_id = ?
        ORDER BY par.id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Check if current user is assigned to this client (can submit proof)
$assignedCheckStmt = $conn->prepare("
    SELECT designer1_id, designer2_id, technical_designer_id, project_coordinator_id, accountaid_fk
    FROM user_info WHERE id = ?
");
$assignedCheckStmt->bind_param("i", $client_id);
$assignedCheckStmt->execute();
$assignedRow = $assignedCheckStmt->get_result()->fetch_assoc();

$isAssignedToClient = in_array($admin_id, array_filter([
    $assignedRow['designer1_id'] ?? null,
    $assignedRow['designer2_id'] ?? null,
    $assignedRow['technical_designer_id'] ?? null,
    $assignedRow['project_coordinator_id'] ?? null,
    $assignedRow['accountaid_fk'] ?? null,
]));

$isAccountingRole = in_array($admin_role, ['accounting', 'general_manager', 'operational_manager', 'superadmin']);
$canApprovePayment = in_array($admin_role, ['accounting', 'superadmin']); // only accounting can approve/reject proofs

// Only the assigned staff can upload proof — NOT accounting/managers
// accounting/GM/OM are reviewers only
$isReviewerOnly = in_array($admin_role, ['accounting', 'general_manager', 'operational_manager', 'superadmin']);
$canSubmitProof = $isAssignedToClient && !$isReviewerOnly;

// Allow access if: has BILLING permission, OR is assigned to client, OR view_only
if (!$canUpdate && !$view_only && !$isAssignedToClient && !$isAccountingRole) {
    header("Location: " . BASE_URL . "unified-project-tracker?client_id=" . $client_id);
    exit();
}
if ($view_only)
    $canUpdate = false;

$business_type = $client['business_type'];
$total_cost = (float) ($client['total_project_cost'] ?? 0);
$remaining_balance = (float) ($client['remaining_balance'] ?? 0);
$payment_split_mode = $client['payment_split_mode'] ?? 'standard';

// ══════════════════════════════════════════════════════════════════
//  PROJECT TYPE — Monthly collection billing logic
// ══════════════════════════════════════════════════════════════════
if ($business_type === 'Project') {

    // Ensure downpayment row exists
    $dpCheckStmt = $conn->prepare("SELECT id, status, amount, payment_date, accounting_status FROM payment_schedule WHERE client_id = ? AND payment_type LIKE '%Down Payment%' LIMIT 1");
    $dpCheckStmt->bind_param("i", $client_id);
    $dpCheckStmt->execute();
    $dpRow = $dpCheckStmt->get_result()->fetch_assoc();

    if (!$dpRow && $total_cost > 0) {
        $dp_amount = $total_cost * 0.30;
        $insDp = $conn->prepare("INSERT INTO payment_schedule (client_id, payment_type, percentage, amount, status, payment_date) VALUES (?, 'Down Payment (30%)', 30, ?, 'Pending', NULL)");
        $insDp->bind_param("id", $client_id, $dp_amount);
        $insDp->execute();
        $dpRow = ['id' => $conn->insert_id, 'status' => 'Pending', 'amount' => $dp_amount, 'payment_date' => null];
    }

    // ── Compute overall installation progress across entries + fixed_sizes ──
    $epStmt = $conn->prepare("
        SELECT
            COUNT(*)                                                     AS items_no_unit,
            SUM(CASE WHEN installation_status='Done' THEN 1 ELSE 0 END)  AS items_no_unit_done,
            0                                                            AS units_total,
            0                                                            AS units_done
        FROM quotation_entries
        WHERE client_id = ?
    ");
    $epStmt->bind_param("i", $client_id);
    $epStmt->execute();
    $ep = $epStmt->get_result()->fetch_assoc();

    $fpStmt = $conn->prepare("
        SELECT
            COUNT(*)                                                     AS items_no_unit,
            SUM(CASE WHEN installation_status='Done' THEN 1 ELSE 0 END)  AS items_no_unit_done,
            0                                                            AS units_total,
            0                                                            AS units_done
        FROM quotation_fixed_sizes
        WHERE client_id = ?
    ");
    $fpStmt->bind_param("i", $client_id);
    $fpStmt->execute();
    $fp = $fpStmt->get_result()->fetch_assoc();

    $grand_total = (int) $ep['items_no_unit'] + (int) $ep['units_total']
        + (int) $fp['items_no_unit'] + (int) $fp['units_total'];
    $grand_done = (int) $ep['items_no_unit_done'] + (int) $ep['units_done']
        + (int) $fp['items_no_unit_done'] + (int) $fp['units_done'];
    $overall_pct = $grand_total > 0 ? round($grand_done / $grand_total * 100, 2) : 0;

    // ── Fetch all collection billing entries ──
    $collStmt = $conn->prepare("
        SELECT * FROM payment_schedule
        WHERE client_id = ? AND payment_type LIKE 'Collection Billing%'
        ORDER BY id ASC
    ");
    $collStmt->bind_param("i", $client_id);
    $collStmt->execute();
    $collections = $collStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $already_billed_pct = 0;
    $already_billed_amt = 0;
    $total_paid = 0;
    $last_snapshot_pct = 0;

    foreach ($collections as $c) {
        $already_billed_pct += (float) $c['percentage'];
        $already_billed_amt += (float) $c['amount'];
        if ($c['status'] === 'Paid')
            $total_paid += (float) $c['amount'];
        if (!empty($c['snapshot_pct']) && (float) $c['snapshot_pct'] > $last_snapshot_pct) {
            $last_snapshot_pct = (float) $c['snapshot_pct'];
        }
    }
    if ($dpRow && $dpRow['status'] === 'Paid')
        $total_paid += (float) $dpRow['amount'];

    $unbilled_pct = max(0, $overall_pct - $last_snapshot_pct);

    if ($overall_pct >= 100) {
        $suggested_amount = round($remaining_balance, 2);
    } else {
        $suggested_amount = round($remaining_balance * ($unbilled_pct / 100), 2);
    }
    $next_no = count($collections) + 1;

    function getOrdinal($n)
    {
        $n = (int) $n;
        $v = $n % 100;
        if ($v >= 11 && $v <= 13)
            return $n . 'th';
        switch ($n % 10) {
            case 1:
                return $n . 'st';
            case 2:
                return $n . 'nd';
            case 3:
                return $n . 'rd';
            default:
                return $n . 'th';
        }
    }
    $default_label = getOrdinal($next_no) . ' Billing Collection';

    $areaStmt = $conn->prepare("
    SELECT
        qe.area,
        0                                                                 AS units_total,
        0                                                                 AS units_done,
        COUNT(*)                                                          AS items_no_unit,
        SUM(CASE WHEN qe.installation_status = 'Done' THEN 1 ELSE 0 END) AS items_no_unit_done
    FROM quotation_entries qe
    WHERE qe.client_id = ?
    GROUP BY qe.area
    UNION ALL
    SELECT
        qfs.area,
        0                                                                  AS units_total,
        0                                                                  AS units_done,
        COUNT(*)                                                           AS items_no_unit,
        SUM(CASE WHEN qfs.installation_status = 'Done' THEN 1 ELSE 0 END) AS items_no_unit_done
    FROM quotation_fixed_sizes qfs
    WHERE qfs.client_id = ?
    GROUP BY qfs.area
");
    $areaStmt->bind_param("ii", $client_id, $client_id);
    $areaStmt->execute();
    $areaRaw = $areaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $areaMap = [];
    foreach ($areaRaw as $a) {
        if (!isset($areaMap[$a['area']]))
            $areaMap[$a['area']] = ['total' => 0, 'done' => 0];
        $areaMap[$a['area']]['total'] += (int) $a['units_total'] + (int) $a['items_no_unit'];
        $areaMap[$a['area']]['done'] += (int) $a['units_done'] + (int) $a['items_no_unit_done'];
    }
    ksort($areaMap);

} else {
    // ══════════════════════════════════════════════
    // NON-PROJECT — 50/40/10 (standard) or 50/50 (merged) logic
    // ══════════════════════════════════════════════
    $schedQ = $conn->prepare("SELECT COUNT(*) as cnt FROM payment_schedule WHERE client_id=?");
    $schedQ->bind_param("i", $client_id);
    $schedQ->execute();
    $schedCount = $schedQ->get_result()->fetch_assoc()['cnt'];

    if ($schedCount == 0 && $total_cost > 0) {
        $dp_a = $total_cost * 0.50;
        $bf_a = $total_cost * 0.40;
        $af_a = $total_cost * 0.10;
        $i1 = $conn->prepare("INSERT INTO payment_schedule (client_id,payment_type,percentage,amount,status,payment_date) VALUES (?,'Down Payment (50%)',50,?,'Pending',NULL)");
        $i1->bind_param("id", $client_id, $dp_a);
        $i1->execute();
        $i2 = $conn->prepare("INSERT INTO payment_schedule (client_id,payment_type,percentage,amount,status) VALUES (?,'40% Before Installation',40,?,'Not Available'),(?,'10% After Installation',10,?,'Not Available')");
        $i2->bind_param("idid", $client_id, $bf_a, $client_id, $af_a);
        $i2->execute();
    } elseif ($schedCount > 0 && $total_cost > 0) {
        $dp_a = $total_cost * 0.50;

        $upDp = $conn->prepare("UPDATE payment_schedule SET amount = ? WHERE client_id = ? AND payment_type LIKE '%Down Payment%' AND status != 'Paid'");
        $upDp->bind_param("di", $dp_a, $client_id);
        $upDp->execute();

        if ($payment_split_mode === 'merged') {
            $af_merged = $total_cost * 0.50;
            $upMerged = $conn->prepare("UPDATE payment_schedule SET amount = ? WHERE client_id = ? AND payment_type = '50% Retention' AND status != 'Paid'");
            $upMerged->bind_param("di", $af_merged, $client_id);
            $upMerged->execute();
        } else {
            $bf_a = $total_cost * 0.40;
            $af_a = $total_cost * 0.10;

            $upBf = $conn->prepare("UPDATE payment_schedule SET amount = ? WHERE client_id = ? AND payment_type = '40% Before Installation' AND status != 'Paid'");
            $upBf->bind_param("di", $bf_a, $client_id);
            $upBf->execute();

            $upAf = $conn->prepare("UPDATE payment_schedule SET amount = ? WHERE client_id = ? AND payment_type = '10% After Installation' AND status != 'Paid'");
            $upAf->bind_param("di", $af_a, $client_id);
            $upAf->execute();
        }
    }

    $ic = $conn->prepare("SELECT SUM(CASE WHEN installation_status='Ongoing' THEN 1 ELSE 0 END) ong, SUM(CASE WHEN installation_status='Done' THEN 1 ELSE 0 END) dn, COUNT(*) tot FROM quotation_entries WHERE client_id=?");
    $ic->bind_param("i", $client_id);
    $ic->execute();
    $isr = $ic->get_result()->fetch_assoc();

    if ($payment_split_mode === 'merged') {
        if ($isr['dn'] == $isr['tot'] && $isr['tot'] > 0) {
            $uM = $conn->prepare("UPDATE payment_schedule SET status=CASE WHEN status='Paid' THEN 'Paid' WHEN status='Not Available' THEN 'Pending' ELSE status END WHERE client_id=? AND payment_type='50% Retention'");
            $uM->bind_param("i", $client_id);
            $uM->execute();
        }
    } else {
        if ($isr['ong'] > 0) {
            $u1 = $conn->prepare("UPDATE payment_schedule SET status=CASE WHEN status='Paid' THEN 'Paid' WHEN status='Not Available' THEN 'Pending' ELSE status END WHERE client_id=? AND payment_type='40% Before Installation'");
            $u1->bind_param("i", $client_id);
            $u1->execute();
        }
        if ($isr['dn'] == $isr['tot'] && $isr['tot'] > 0) {
            $u2 = $conn->prepare("UPDATE payment_schedule SET status=CASE WHEN status='Paid' THEN 'Paid' WHEN status='Not Available' THEN 'Pending' ELSE status END WHERE client_id=? AND payment_type='10% After Installation'");
            $u2->bind_param("i", $client_id);
            $u2->execute();
        }
    }

    $pq = $conn->prepare("SELECT * FROM payment_schedule WHERE client_id=? ORDER BY id");
    $pq->bind_param("i", $client_id);
    $pq->execute();
    $payments = $pq->get_result()->fetch_all(MYSQLI_ASSOC);
    $total_paid = 0;
    foreach ($payments as $p) {
        if ($p['status'] === 'Paid')
            $total_paid += (float) $p['amount'];
    }

    $toggleLockedRow = null;
    foreach ($payments as $p) {
        if ($p['payment_type'] === '40% Before Installation' || $p['payment_type'] === '50% Retention') {
            $toggleLockedRow = $p;
            break;
        }
    }
    $isSplitLocked = $toggleLockedRow && $toggleLockedRow['status'] === 'Paid';
}

// ── Small style helpers (theme-consistent badge/stripe colors) ──
function badgeClasses($status)
{
    switch (strtolower($status)) {
        case 'paid':
            return 'bg-emerald-100 text-emerald-800 border-emerald-300';
        case 'pending':
            return 'bg-amber-100 text-amber-800 border-amber-300';
        case 'not available':
            return 'bg-gray-100 text-gray-600 border-gray-300';
        default:
            return 'bg-[#F5F5F5] text-ink border-line';
    }
}
function stripeClasses($status)
{
    switch (strtolower($status)) {
        case 'paid':
            return 'border-l-emerald-400';
        case 'pending':
            return 'border-l-amber-400';
        case 'not available':
            return 'border-l-gray-300';
        default:
            return 'border-l-line';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Payment Tracker — <?= htmlspecialchars($client['clientname']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        ink: '#0B0B0B',
                        soft: '#6B6B6B',
                        muted: '#9A9A9A',
                        line: '#E2E2E2',
                    },
                },
            },
        };
    </script>
    <style>
        /* Only used for JS-driven show/hide (classList 'open') — everything else is Tailwind */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 2000; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .lightbox-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.88); z-index: 9998; align-items: center; justify-content: center; cursor: zoom-out; }
        .lightbox-overlay.open { display: flex; }
        .toast { position: fixed; top: 18px; right: 18px; display: none; align-items: center; gap: 10px; z-index: 9999; animation: slideIn .3s ease; }
        .toast.show { display: flex; }
        @keyframes slideIn { from { transform: translateX(360px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .proof-preview, #ntpPreview { display: none; }
        .form-input.overridden { border-color: #f59e0b !important; background: #fffbeb; }
    </style>
</head>

<body class="font-sans bg-[#F5F5F5] text-ink">
    <div class="max-w-[1300px] mx-auto px-5 py-8">

        <a href="<?= $view_only ? 'manager-project-detail?client_id=' . $client_id : 'unified-project-tracker?client_id=' . $client_id ?>"
            class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold hover:opacity-90 transition mb-4">
            <i class="fas fa-arrow-left"></i> <?= $view_only ? 'Back to Project Detail' : 'Back to Project Tracker' ?>
        </a>

        <?php if ($view_only): ?>
            <div class="bg-amber-50 border border-amber-300 rounded-lg px-5 py-3 mb-5 flex items-center gap-3 text-[13px] font-semibold text-amber-800">
                <i class="fas fa-eye"></i> View Only — You can view payment details but cannot make changes.
            </div>
        <?php endif; ?>

        <!-- ══ PAGE HEADER ══ -->
        <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
            <div class="text-[11px] font-semibold tracking-[1.5px] uppercase text-soft mb-2">
                <i class="fas fa-money-bill-wave"></i> Payment Tracker
            </div>
            <h1 class="text-2xl font-bold tracking-[-0.01em] flex items-center gap-2 flex-wrap">
                <?= htmlspecialchars($client['clientname']) ?>
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase border bg-purple-100 text-purple-800 border-purple-300">
                    <?= $business_type === 'Non-Project' ? 'Individual' : htmlspecialchars($business_type) ?>
                </span>
            </h1>
            <p class="text-[13.5px] text-soft mt-1"><?= htmlspecialchars($client['nameproject']) ?></p>

            <div class="grid grid-cols-2 sm:grid-cols-3 <?= $business_type === 'Project' ? 'lg:grid-cols-5' : 'lg:grid-cols-3' ?> gap-3 mt-5">
                <div class="bg-[#F5F5F5] border border-line rounded-[10px] p-4 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-ink text-white flex items-center justify-center text-lg flex-shrink-0"><i class="fas fa-sack-dollar"></i></div>
                    <div>
                        <div class="text-lg font-bold">&#8369;<?= number_format($total_cost, 2) ?></div>
                        <div class="text-[10px] font-semibold uppercase tracking-[0.4px] text-muted">Total Project Cost</div>
                    </div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-[10px] p-4 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-emerald-500 text-white flex items-center justify-center text-lg flex-shrink-0"><i class="fas fa-circle-check"></i></div>
                    <div>
                        <div class="text-lg font-bold">&#8369;<?= number_format($total_paid, 2) ?></div>
                        <div class="text-[10px] font-semibold uppercase tracking-[0.4px] text-muted">Total Paid</div>
                    </div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-[10px] p-4 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-amber-500 text-white flex items-center justify-center text-lg flex-shrink-0"><i class="fas fa-hourglass-half"></i></div>
                    <div>
                        <div class="text-lg font-bold">&#8369;<?= number_format($remaining_balance, 2) ?></div>
                        <div class="text-[10px] font-semibold uppercase tracking-[0.4px] text-muted">Remaining Balance</div>
                    </div>
                </div>
                <?php if ($business_type === 'Project'): ?>
                    <div class="bg-[#F5F5F5] border border-line rounded-[10px] p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-blue-500 text-white flex items-center justify-center text-lg flex-shrink-0"><i class="fas fa-chart-line"></i></div>
                        <div>
                            <div class="text-lg font-bold"><?= $overall_pct ?>%</div>
                            <div class="text-[10px] font-semibold uppercase tracking-[0.4px] text-muted">Overall Progress</div>
                        </div>
                    </div>
                    <div class="bg-[#F5F5F5] border border-line rounded-[10px] p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-purple-500 text-white flex items-center justify-center text-lg flex-shrink-0"><i class="fas fa-file-invoice-dollar"></i></div>
                        <div>
                            <div class="text-base font-bold">&#8369;<?= number_format($already_billed_amt, 2) ?></div>
                            <div class="text-[10px] font-semibold uppercase tracking-[0.4px] text-muted">Already Billed</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($business_type === 'Project'): ?>
            <!-- ════════════════════════════════════ PROJECT ════════════════════════════════════ -->
            <div class="grid grid-cols-1 lg:grid-cols-[1fr_340px] gap-5 items-start">

                <!-- LEFT -->
                <div class="flex flex-col gap-5">

                    <!-- Down Payment -->
                    <div class="bg-white border border-line rounded-[10px] p-6">
                        <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
                            <div class="flex items-center gap-2.5 text-[15px] font-bold">
                                <i class="fas fa-hand-holding-dollar text-amber-500"></i> Down Payment
                            </div>
                            <span class="text-[12px] text-muted">30% of total project cost</span>
                        </div>

                        <?php if ($dpRow):
                            $dpCls = strtolower($dpRow['status']);
                            ?>
                            <div class="border <?= $dpCls === 'paid' ? 'border-emerald-300 bg-emerald-50' : 'border-amber-300 bg-amber-50' ?> rounded-lg p-4 flex justify-between items-center gap-3 flex-wrap">
                                <div>
                                    <div class="text-[15px] font-bold text-ink">Down Payment (30%)</div>
                                    <div class="flex items-center gap-2.5 flex-wrap mt-1.5">
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase border <?= badgeClasses($dpRow['status']) ?>"><?= $dpRow['status'] ?></span>
                                        <?php if ($dpRow['payment_date']): ?>
                                            <span class="text-[12px] text-soft">
                                                <i class="fas fa-check-circle text-emerald-500"></i>
                                                Paid on: <?= date('M d, Y g:i A', strtotime($dpRow['payment_date'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-xl font-bold text-emerald-600">&#8369;<?= number_format($dpRow['amount'], 2) ?></div>
                                    <?php if ($dpRow['status'] === 'Paid'):
                                        $dpPaidProof = getPaymentProofInfo($conn, $dpRow['id']);
                                        $dpNTP = getNTPInfo($conn, $dpRow['id']);
                                        if ($dpPaidProof): ?>
                                            <div class="bg-emerald-50 border-2 border-emerald-300 rounded-lg p-3.5 mt-2.5 text-left">
                                                <div class="text-[12px] font-bold text-emerald-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-clock-rotate-left"></i> Payment Proof (Approved)</div>
                                                <div class="text-[11px] text-emerald-800 mb-2">
                                                    <i class="fas fa-check-circle"></i> Reviewed by: <?= htmlspecialchars($dpPaidProof['reviewer_name'] ?? 'Accounting') ?>
                                                </div>
                                                <?php if (strpos($dpPaidProof['file_type'] ?? '', 'image') !== false): ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($dpPaidProof['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                        <img src="<?= BASE_URL ?><?= htmlspecialchars($dpPaidProof['file_path']) ?>" class="max-w-full max-h-[200px] rounded-lg object-contain cursor-pointer">
                                                    </a>
                                                <?php else: ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($dpPaidProof['file_path']) ?>" target="_blank"
                                                        class="inline-flex items-center gap-2 bg-[#F5F5F5] border border-line text-ink rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-line/40 transition mt-1.5">
                                                        <i class="fas fa-file"></i> View Proof File
                                                    </a>
                                                <?php endif; ?>
                                                <div class="text-[11px] text-muted mt-1.5"><i class="fas fa-paperclip"></i> <?= htmlspecialchars($dpPaidProof['file_name']) ?></div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($dpNTP): ?>
                                            <div class="bg-blue-50 border-2 border-blue-300 rounded-lg p-3.5 mt-2.5 text-left">
                                                <div class="text-[12px] font-bold text-blue-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-file-signature"></i> Notice to Proceed (NTP) Issued</div>
                                                <div class="text-[11px] text-blue-800 mb-1.5">
                                                    <i class="fas fa-user"></i> Uploaded by: <?= htmlspecialchars($dpNTP['uploader_name'] ?? 'Accounting') ?>
                                                    &bull; <?= date('M d, Y g:i A', strtotime($dpNTP['uploaded_at'])) ?>
                                                </div>
                                                <?php if (!empty($dpNTP['notes'])): ?>
                                                    <div class="text-[11px] text-ink bg-blue-100/70 rounded-md p-2 mb-1.5"><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($dpNTP['notes']) ?></div>
                                                <?php endif; ?>
                                                <?php if (strpos($dpNTP['file_type'] ?? '', 'image') !== false): ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($dpNTP['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                        <img src="<?= BASE_URL ?><?= htmlspecialchars($dpNTP['file_path']) ?>" class="max-w-full max-h-[200px] rounded-lg object-contain cursor-pointer">
                                                    </a>
                                                <?php else: ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($dpNTP['file_path']) ?>" target="_blank"
                                                        class="inline-flex items-center gap-2 bg-[#F5F5F5] border border-line text-ink rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-line/40 transition mt-1.5">
                                                        <i class="fas fa-file"></i> View NTP File
                                                    </a>
                                                <?php endif; ?>
                                                <div class="text-[11px] text-muted mt-1.5"><i class="fas fa-paperclip"></i> <?= htmlspecialchars($dpNTP['file_name']) ?></div>
                                                <?php if ($canApprovePayment): ?>
                                                    <div class="flex justify-end mt-2.5">
                                                        <button class="inline-flex items-center gap-1.5 border-2 border-emerald-600 text-emerald-700 rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-emerald-50 transition"
                                                            onclick="openUpdateNTP(<?= $dpRow['id'] ?>)">
                                                            <i class="fas fa-sync-alt"></i> Update NTP
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; elseif ($dpRow['status'] === 'Pending'): ?>
                                        <?php
                                        $dpProof = getPaymentProofInfo($conn, $dpRow['id']);
                                        $dpAcctStatus = $dpRow['accounting_status'] ?? 'not_submitted';
                                        if (!$view_only && $canSubmitProof):
                                            $dpBoxCls = $dpAcctStatus === 'rejected' ? 'border-red-300 bg-red-50' : ($dpAcctStatus === 'pending_review' ? 'border-amber-300 bg-amber-50' : 'border-emerald-200 bg-emerald-50/40');
                                            ?>
                                            <div class="border-2 <?= $dpBoxCls ?> rounded-lg p-3.5 mt-2.5 text-left">
                                                <?php if ($dpAcctStatus === 'rejected'): ?>
                                                    <div class="text-[12px] font-bold text-red-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-times-circle"></i> Proof rejected — please resubmit</div>
                                                    <div class="text-[11px] text-red-700 italic mb-2">"<?= htmlspecialchars($dpProof['rejection_note'] ?? 'No reason provided') ?>" — <?= htmlspecialchars($dpProof['reviewer_name'] ?? 'Accounting') ?></div>
                                                <?php elseif ($dpAcctStatus === 'pending_review'): ?>
                                                    <div class="text-[12px] font-bold text-amber-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-clock"></i> Proof submitted — awaiting accounting review</div>
                                                    <?php if ($dpProof): ?>
                                                        <div class="text-[11px] text-muted"><i class="fas fa-paperclip"></i> <?= htmlspecialchars($dpProof['file_name']) ?></div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <div class="text-[12px] font-bold text-emerald-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-upload"></i> Attach Proof of Payment</div>
                                                <?php endif; ?>
                                                <?php if ($dpAcctStatus !== 'pending_review'): ?>
                                                    <input type="file" class="proof-input block w-full text-[13px] border-2 border-dashed border-line rounded-lg p-2 cursor-pointer bg-white"
                                                        id="proofFile_<?= $dpRow['id'] ?>" accept="image/*,.pdf,.doc,.docx" onchange="previewProof(this, <?= $dpRow['id'] ?>)">
                                                    <img id="proofImg_<?= $dpRow['id'] ?>" class="proof-preview max-w-full max-h-[180px] rounded-lg mt-2 object-contain">
                                                    <div class="flex justify-end mt-2.5">
                                                        <button class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-3 py-1.5 text-[12px] font-semibold transition"
                                                            onclick="submitProof(<?= $dpRow['id'] ?>, <?= $client_id ?>)">
                                                            <i class="fas fa-paper-plane"></i> Submit for Review
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php
                            if ($canApprovePayment && isset($dpRow) && $dpRow['status'] === 'Pending') {
                                $dpProofAcct = getPaymentProofInfo($conn, $dpRow['id']);
                                $dpAcctStatus = $dpRow['accounting_status'] ?? 'not_submitted';
                                if (in_array($dpAcctStatus, ['pending_review', 'rejected']) && $dpProofAcct):
                                    ?>
                                    <div class="mt-3 border-2 <?= $dpAcctStatus === 'rejected' ? 'border-red-300 bg-red-50' : 'border-amber-300 bg-amber-50' ?> rounded-lg p-3.5">
                                        <div class="text-[13px] font-bold <?= $dpAcctStatus === 'rejected' ? 'text-red-800' : 'text-amber-800' ?> mb-2">
                                            <i class="fas fa-<?= $dpAcctStatus === 'rejected' ? 'times-circle' : 'file-alt' ?>"></i>
                                            <?= $dpAcctStatus === 'rejected' ? 'You rejected this proof' : 'Proof submitted — Accounting Review Required' ?>
                                        </div>
                                        <?php if ($dpAcctStatus === 'rejected' && !empty($dpProofAcct['rejection_note'])): ?>
                                            <div class="bg-red-100 border border-red-300 rounded-md p-2.5 mb-2.5">
                                                <div class="text-[11px] font-bold text-red-800 mb-1">Your rejection note:</div>
                                                <div class="text-[12px] text-red-700 italic">"<?= htmlspecialchars($dpProofAcct['rejection_note']) ?>"</div>
                                            </div>
                                            <div class="text-[11px] text-muted">Waiting for the uploader to resubmit a new proof.</div>
                                        <?php else: ?>
                                            <?php if (strpos($dpProofAcct['file_type'] ?? '', 'image') !== false): ?>
                                                <a href="<?= BASE_URL ?><?= htmlspecialchars($dpProofAcct['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                    <img src="<?= BASE_URL ?><?= htmlspecialchars($dpProofAcct['file_path']) ?>" class="max-w-full max-h-[200px] rounded-lg mb-2.5 object-contain cursor-pointer">
                                                </a>
                                            <?php else: ?>
                                                <a href="<?= BASE_URL ?><?= htmlspecialchars($dpProofAcct['file_path']) ?>" target="_blank"
                                                    class="inline-flex items-center gap-2 bg-[#F5F5F5] border border-line text-ink rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-line/40 transition mb-2.5">
                                                    <i class="fas fa-file"></i> View Proof File
                                                </a>
                                            <?php endif; ?>
                                            <div class="flex gap-2 justify-end flex-wrap">
                                                <button class="inline-flex items-center gap-1.5 bg-[#F5F5F5] border border-line text-ink rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-line/40 transition" onclick="openRejectModal(<?= $dpRow['id'] ?>)">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                                <button class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-3 py-1.5 text-[12px] font-semibold transition" onclick="approvePayment(<?= $dpRow['id'] ?>)">
                                                    <i class="fas fa-check"></i> Approve & Mark Paid
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif;
                            } ?>
                        <?php endif; ?>
                    </div>

                    <!-- Overall Progress -->
                    <div class="bg-emerald-50 border border-emerald-300 rounded-[10px] p-5">
                        <div class="flex items-center justify-between flex-wrap gap-2 mb-2.5">
                            <div class="text-[13px] font-bold text-emerald-800 flex items-center gap-1.5"><i class="fas fa-chart-line"></i> Overall Installation Progress</div>
                            <div class="text-[15px] font-bold text-emerald-600"><?= $grand_done ?> / <?= $grand_total ?> items done</div>
                        </div>
                        <div class="h-3.5 bg-emerald-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full bg-gradient-to-r from-emerald-600 to-emerald-400 transition-all duration-500" style="width:<?= min(100, $overall_pct) ?>%;"></div>
                        </div>
                        <div class="flex justify-between flex-wrap gap-1 mt-1.5 text-[11px] text-soft">
                            <span><?= $overall_pct ?>% complete</span>
                            <span>
                                Last billed at: <?= round($last_snapshot_pct, 1) ?>%
                                &nbsp;&bull;&nbsp;
                                Unbilled progress: <strong class="text-emerald-700"><?= max(0, round($overall_pct - $last_snapshot_pct, 1)) ?>%</strong>
                            </span>
                        </div>
                    </div>

                    <!-- Collection Billings -->
                    <div class="bg-white border border-line rounded-[10px] p-6">
                        <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
                            <div class="flex items-center gap-2.5 text-[15px] font-bold"><i class="fas fa-file-invoice-dollar text-emerald-500"></i> Progress Billing Collections</div>
                            <span class="text-[12px] text-muted"><?= count($collections) ?> collection<?= count($collections) != 1 ? 's' : '' ?> recorded</span>
                        </div>

                        <?php if (empty($collections)): ?>
                            <div class="text-center py-10 text-muted">
                                <i class="fas fa-file-invoice text-3xl mb-3 block opacity-50"></i>
                                <p class="text-[13px]">No billing collections yet. Add the first one below.</p>
                            </div>
                        <?php else: ?>
                            <div class="flex flex-col gap-3 mb-5">
                                <?php foreach ($collections as $idx => $coll):
                                    $cCls = strtolower($coll['status']);
                                    $cNo = $idx + 1;
                                    ?>
                                    <div class="border border-line <?= stripeClasses($coll['status']) ?> border-l-4 rounded-lg overflow-hidden hover:border-ink transition">
                                        <div class="p-3.5 flex items-center justify-between flex-wrap gap-2 <?= $cCls === 'paid' ? 'bg-emerald-50' : 'bg-amber-50' ?>">
                                            <div class="text-[13px] font-bold flex items-center gap-2 flex-wrap">
                                                <span class="bg-ink text-white min-w-[26px] h-[26px] rounded-full inline-flex items-center justify-center text-[11px] font-bold"><?= $cNo ?></span>
                                                <?= htmlspecialchars($coll['payment_type']) ?>
                                                <?php if (!empty($coll['snapshot_pct'])): ?>
                                                    <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded-full text-[10px] font-bold inline-flex items-center gap-1">
                                                        <i class="fas fa-camera"></i> <?= number_format((float) $coll['snapshot_pct'], 1) ?>% at billing
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase border <?= badgeClasses($coll['status']) ?>"><?= $coll['status'] ?></span>
                                        </div>
                                        <div class="p-3.5 border-t border-line flex items-center justify-between flex-wrap gap-2.5">
                                            <div>
                                                <div class="text-xl font-bold text-emerald-600">&#8369;<?= number_format($coll['amount'], 2) ?></div>
                                                <div class="text-[11px] text-muted">
                                                    <?= number_format((float) $coll['percentage'], 2) ?>% of project
                                                    <?php if ($coll['payment_date']): ?>
                                                        &bull; <i class="fas fa-check-circle text-emerald-500"></i> Paid <?= date('M d, Y', strtotime($coll['payment_date'])) ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="flex gap-2 flex-wrap">
                                                <?php if ($coll['status'] !== 'Paid' && !$view_only && $canApprovePayment): ?>
                                                    <button class="inline-flex items-center gap-1.5 border-2 border-emerald-600 text-emerald-700 rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-emerald-50 transition"
                                                        onclick="openEditModal(<?= $coll['id'] ?>, <?= $coll['amount'] ?>, '<?= addslashes($coll['payment_type']) ?>')">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                <?php elseif ($coll['status'] !== 'Paid' && $view_only): ?>
                                                <?php else: ?>
                                                    <span class="text-[12px] text-emerald-600 font-bold"><i class="fas fa-check-double"></i> Paid</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($coll['status'] === 'Paid'):
                                            $paidProof = getPaymentProofInfo($conn, $coll['id']);
                                            $collNTP = getNTPInfo($conn, $coll['id']);
                                            if ($paidProof): ?>
                                                <div class="mx-3.5 mb-3.5 bg-emerald-50 border-2 border-emerald-300 rounded-lg p-3.5">
                                                    <div class="text-[12px] font-bold text-emerald-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-clock-rotate-left"></i> Payment Proof (Approved)</div>
                                                    <div class="text-[11px] text-emerald-800 mb-2"><i class="fas fa-check-circle"></i> Reviewed by: <?= htmlspecialchars($paidProof['reviewer_name'] ?? 'Accounting') ?></div>
                                                    <?php if (strpos($paidProof['file_type'] ?? '', 'image') !== false): ?>
                                                        <a href="<?= BASE_URL ?><?= htmlspecialchars($paidProof['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                            <img src="<?= BASE_URL ?><?= htmlspecialchars($paidProof['file_path']) ?>" class="max-w-full max-h-[180px] rounded-lg object-contain cursor-pointer">
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="<?= BASE_URL ?><?= htmlspecialchars($paidProof['file_path']) ?>" target="_blank"
                                                            class="inline-flex items-center gap-2 bg-[#F5F5F5] border border-line text-ink rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-line/40 transition mt-1.5">
                                                            <i class="fas fa-file"></i> View Proof File
                                                        </a>
                                                    <?php endif; ?>
                                                    <div class="text-[11px] text-muted mt-1.5"><i class="fas fa-paperclip"></i> <?= htmlspecialchars($paidProof['file_name']) ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($collNTP): ?>
                                                <div class="mx-3.5 mb-3.5 bg-blue-50 border-2 border-blue-300 rounded-lg p-3.5">
                                                    <div class="text-[12px] font-bold text-blue-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-file-signature"></i> Notice to Proceed (NTP) Issued</div>
                                                    <div class="text-[11px] text-blue-800 mb-1.5">
                                                        <i class="fas fa-user"></i> Uploaded by: <?= htmlspecialchars($collNTP['uploader_name'] ?? 'Accounting') ?>
                                                        &bull; <?= date('M d, Y g:i A', strtotime($collNTP['uploaded_at'])) ?>
                                                    </div>
                                                    <?php if (!empty($collNTP['notes'])): ?>
                                                        <div class="text-[11px] text-ink bg-blue-100/70 rounded-md p-2 mb-1.5"><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($collNTP['notes']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if (strpos($collNTP['file_type'] ?? '', 'image') !== false): ?>
                                                        <a href="<?= BASE_URL ?><?= htmlspecialchars($collNTP['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                            <img src="<?= BASE_URL ?><?= htmlspecialchars($collNTP['file_path']) ?>" class="max-w-full max-h-[180px] rounded-lg object-contain cursor-pointer">
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="<?= BASE_URL ?><?= htmlspecialchars($collNTP['file_path']) ?>" target="_blank"
                                                            class="inline-flex items-center gap-2 bg-[#F5F5F5] border border-line text-ink rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-line/40 transition mt-1.5">
                                                            <i class="fas fa-file"></i> View NTP File
                                                        </a>
                                                    <?php endif; ?>
                                                    <div class="text-[11px] text-muted mt-1.5"><i class="fas fa-paperclip"></i> <?= htmlspecialchars($collNTP['file_name']) ?></div>
                                                    <?php if ($canApprovePayment): ?>
                                                        <div class="flex justify-end mt-2.5">
                                                            <button class="inline-flex items-center gap-1.5 border-2 border-emerald-600 text-emerald-700 rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-emerald-50 transition" onclick="openUpdateNTP(<?= $coll['id'] ?>)">
                                                                <i class="fas fa-sync-alt"></i> Update NTP
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; endif; ?>
                                        <?php
                                        $collProof2 = getPaymentProofInfo($conn, $coll['id']);
                                        $collAcctStatus2 = $coll['accounting_status'] ?? 'not_submitted';
                                        if ($coll['status'] !== 'Paid' && !$view_only && ($canSubmitProof || $isAccountingRole)):
                                            $collBoxCls = $collAcctStatus2 === 'rejected' ? 'border-red-300 bg-red-50' : ($collAcctStatus2 === 'pending_review' ? 'border-amber-300 bg-amber-50' : 'border-emerald-200 bg-emerald-50/40');
                                            ?>
                                            <div class="mx-3.5 mb-3.5 border-2 <?= $collBoxCls ?> rounded-lg p-3.5">
                                                <?php if ($collAcctStatus2 === 'rejected'): ?>
                                                    <div class="text-[12px] font-bold text-red-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-times-circle"></i> Proof rejected — resubmit</div>
                                                    <div class="text-[11px] text-red-700 italic mb-2">"<?= htmlspecialchars($collProof2['rejection_note'] ?? '') ?>"</div>
                                                <?php elseif ($collAcctStatus2 === 'pending_review'): ?>
                                                    <div class="text-[12px] font-bold text-amber-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-clock"></i> Awaiting accounting review</div>
                                                <?php else: ?>
                                                    <div class="text-[12px] font-bold text-emerald-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-upload"></i> Attach Proof of Payment</div>
                                                <?php endif; ?>
                                                <?php if ($collAcctStatus2 !== 'pending_review' && $canSubmitProof): ?>
                                                    <input type="file" class="proof-input block w-full text-[13px] border-2 border-dashed border-line rounded-lg p-2 cursor-pointer bg-white"
                                                        id="proofFile_<?= $coll['id'] ?>" accept="image/*,.pdf,.doc,.docx" onchange="previewProof(this, <?= $coll['id'] ?>)">
                                                    <img id="proofImg_<?= $coll['id'] ?>" class="proof-preview max-w-full max-h-[180px] rounded-lg mt-2 object-contain">
                                                    <div class="flex justify-end mt-2">
                                                        <button class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-3 py-1.5 text-[12px] font-semibold transition"
                                                            onclick="submitProof(<?= $coll['id'] ?>, <?= $client_id ?>)">
                                                            <i class="fas fa-paper-plane"></i> Submit for Review
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($canApprovePayment && $collAcctStatus2 === 'pending_review' && $collProof2): ?>
                                                    <div class="mt-2.5 pt-2.5 border-t border-dashed border-amber-300">
                                                        <div class="text-[12px] font-bold text-amber-800 mb-2">Accounting Review</div>
                                                        <?php if (strpos($collProof2['file_type'] ?? '', 'image') !== false): ?>
                                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($collProof2['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                                <img src="<?= BASE_URL ?><?= htmlspecialchars($collProof2['file_path']) ?>" class="max-w-full max-h-[160px] rounded-md mb-2 object-contain cursor-pointer">
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($collProof2['file_path']) ?>" target="_blank"
                                                                class="inline-flex items-center gap-2 bg-[#F5F5F5] border border-line text-ink rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-line/40 transition mb-2">
                                                                <i class="fas fa-file"></i> View File
                                                            </a>
                                                        <?php endif; ?>
                                                        <div class="flex gap-2 justify-end flex-wrap">
                                                            <button class="inline-flex items-center gap-1.5 bg-[#F5F5F5] border border-line text-ink rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-line/40 transition" onclick="openRejectModal(<?= $coll['id'] ?>)">
                                                                <i class="fas fa-times"></i> Reject
                                                            </button>
                                                            <button class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-3 py-1.5 text-[12px] font-semibold transition" onclick="quickApprove(<?= $coll['id'] ?>)">
                                                                <i class="fas fa-check"></i> Approve & Mark Paid
                                                            </button>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- ── Add New Collection ── -->
                        <?php
                        $hasPendingCollection = false;
                        foreach ($collections as $c) {
                            if ($c['status'] !== 'Paid') {
                                $hasPendingCollection = true;
                                break;
                            }
                        }
                        ?>
                        <?php if (!$view_only && $isAccountingRole && $remaining_balance > 0 && !$hasPendingCollection): ?>
                            <div class="border-2 border-dashed border-emerald-300 bg-emerald-50/40 rounded-[10px] p-5 mt-1.5">
                                <div class="text-emerald-800 text-[14px] font-bold flex items-center gap-1.5 mb-1"><i class="fas fa-plus-circle"></i> New Collection — #<?= $next_no ?></div>
                                <div class="text-muted text-[12px] mb-4">The system suggests an amount based on unbilled progress. You can enter any amount.</div>

                                <div class="bg-white border-2 border-emerald-300 rounded-lg p-3.5 mb-4">
                                    <div class="flex justify-between items-center py-1">
                                        <span class="text-[12px] text-soft font-semibold flex items-center gap-1.5"><i class="fas fa-chart-bar text-emerald-500"></i> Current overall progress</span>
                                        <span class="text-[13px] font-bold text-gray-700"><?= $overall_pct ?>%</span>
                                    </div>
                                    <div class="flex justify-between items-center py-1">
                                        <span class="text-[12px] text-soft font-semibold flex items-center gap-1.5"><i class="fas fa-minus-circle text-muted"></i> Last billed progress snapshot</span>
                                        <span class="text-[13px] font-bold text-gray-700"><?= round($last_snapshot_pct, 2) ?>%</span>
                                    </div>
                                    <hr class="border-none border-t border-dashed border-emerald-200 my-2">
                                    <div class="flex justify-between items-center py-1">
                                        <span class="text-[12px] text-emerald-700 font-bold flex items-center gap-1.5"><i class="fas fa-lightbulb text-amber-500"></i> Suggested billing amount</span>
                                        <span class="text-xl font-bold text-emerald-600">&#8369;<?= number_format($suggested_amount, 2) ?></span>
                                    </div>
                                    <div class="text-[11px] text-muted mt-2 pt-1.5 border-t border-line leading-relaxed">
                                        <?php if ($overall_pct >= 100): ?>
                                            All work is 100% complete — suggesting full remaining balance.
                                            <br>&#8369;<?= number_format($remaining_balance, 2) ?> (remaining balance) = <strong>&#8369;<?= number_format($suggested_amount, 2) ?></strong>
                                        <?php else: ?>
                                            Formula: (<?= $overall_pct ?>% &minus; <?= round($last_snapshot_pct, 2) ?>% last snapshot) &times; &#8369;<?= number_format($remaining_balance, 2) ?> (remaining balance) = <strong>&#8369;<?= number_format($suggested_amount, 2) ?></strong>
                                        <?php endif; ?>
                                        <br>You can override this with any amount below.
                                    </div>
                                </div>

                                <div class="mb-3.5">
                                    <label class="block text-[11px] font-bold text-gray-600 uppercase tracking-[0.4px] mb-1.5"><i class="fas fa-tag"></i> Billing Label</label>
                                    <input type="text" id="newCollLabel" class="form-input w-full px-3.5 py-2.5 border-2 border-emerald-200 rounded-lg text-sm font-semibold bg-white focus:outline-none focus:border-emerald-500"
                                        value="<?= htmlspecialchars($default_label) ?>" placeholder="e.g. 1st Billing Collection">
                                </div>
                                <div class="mb-3.5">
                                    <label class="block text-[11px] font-bold text-gray-600 uppercase tracking-[0.4px] mb-1.5"><i class="fas fa-peso-sign"></i> Amount to Bill (&#8369;) <span class="text-red-500">*</span></label>
                                    <input type="number" id="newCollAmount" class="form-input w-full px-3.5 py-2.5 border-2 border-emerald-200 rounded-lg text-sm font-semibold bg-white focus:outline-none focus:border-emerald-500"
                                        value="<?= $suggested_amount ?>" min="0" step="0.01" oninput="onAmountInput(this)">
                                    <div id="overrideHint" class="text-[11px] text-muted mt-1"></div>
                                </div>

                                <div id="addCollErr" class="hidden text-red-500 text-[13px] px-3 py-2 bg-red-100 rounded-md mb-3"></div>

                                <div class="flex gap-2 justify-end flex-wrap">
                                    <button class="inline-flex items-center gap-1.5 bg-[#F5F5F5] border border-line text-ink rounded-lg px-4 py-2 text-[13px] font-semibold hover:bg-line/40 transition" onclick="resetSuggested()">
                                        <i class="fas fa-undo"></i> Reset to Suggested
                                    </button>
                                    <button class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-4 py-2 text-[13px] font-semibold transition" id="addCollBtn" onclick="submitNewCollection()">
                                        <i class="fas fa-save"></i> Save Billing Entry
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                </div><!-- /left -->

                <!-- RIGHT — Area Breakdown Sidebar -->
                <div>
                    <div class="bg-white border border-line rounded-[10px] overflow-hidden sticky top-5">
                        <div class="bg-ink text-white px-4.5 py-3.5 px-[18px] py-[14px]">
                            <h3 class="text-[13px] font-bold flex items-center gap-1.5"><i class="fas fa-map-marker-alt"></i> Area Breakdown</h3>
                        </div>
                        <div class="p-3.5 flex flex-col gap-2.5 max-h-[500px] overflow-y-auto">
                            <?php if (empty($areaMap)): ?>
                                <div class="text-center py-5 text-muted text-[13px]">No areas found.</div>
                            <?php else: ?>
                                <?php foreach ($areaMap as $aName => $aData):
                                    $aPct = $aData['total'] > 0 ? round($aData['done'] / $aData['total'] * 100) : 0;
                                    $aColorClasses = ($aPct === 100) ? ['text' => 'text-emerald-500', 'bar' => 'bg-emerald-500'] : (($aPct > 0) ? ['text' => 'text-blue-500', 'bar' => 'bg-blue-500'] : ['text' => 'text-muted', 'bar' => 'bg-muted']);
                                    ?>
                                    <div class="bg-[#F9F9F9] border border-line rounded-lg px-2.5 py-2">
                                        <div class="flex justify-between items-center mb-0.5">
                                            <span class="text-[12px] font-bold text-gray-700"><?= htmlspecialchars($aName) ?></span>
                                            <span class="text-[12px] font-bold <?= $aColorClasses['text'] ?>"><?= $aPct ?>%</span>
                                        </div>
                                        <div class="text-[11px] text-muted mb-1"><?= $aData['done'] ?>/<?= $aData['total'] ?> items done</div>
                                        <div class="h-[5px] bg-line rounded-full overflow-hidden">
                                            <div class="h-full rounded-full <?= $aColorClasses['bar'] ?> transition-all duration-300" style="width:<?= $aPct ?>%;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div><!-- /right -->

            </div><!-- /grid -->

        <?php else: ?>
            <!-- ════════════════════════════════════ NON-PROJECT ════════════════════════════════════ -->

            <?php if ($isAssignedToClient && !$view_only): ?>
                <div class="bg-white border border-line rounded-[10px] p-5 mb-4">
                    <?php if ($isSplitLocked): ?>
                        <div class="flex items-center gap-2.5 text-[13px] text-soft">
                            <i class="fas fa-lock text-muted"></i>
                            Payment split is locked (<?= $payment_split_mode === 'merged' ? '50% Retention' : '40%/10% split' ?> already paid).
                        </div>
                    <?php elseif ($payment_split_mode === 'merged'): ?>
                        <div class="flex items-center justify-between flex-wrap gap-2.5">
                            <div class="text-[13px] text-gray-700"><i class="fas fa-info-circle text-blue-500"></i> Currently using <strong>50% Retention</strong> split.</div>
                            <button class="inline-flex items-center gap-1.5 border-2 border-emerald-600 text-emerald-700 rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-emerald-50 transition" onclick="openToggleConfirm('revert')">
                                <i class="fas fa-undo"></i> Revert to 40% / 10% split
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="flex items-center justify-between flex-wrap gap-2.5">
                            <div class="text-[13px] text-gray-700"><i class="fas fa-info-circle text-blue-500"></i> Currently using <strong>40% Before / 10% After</strong> split.</div>
                            <button class="inline-flex items-center gap-1.5 border-2 border-emerald-600 text-emerald-700 rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-emerald-50 transition" onclick="openToggleConfirm('merge')">
                                <i class="fas fa-random"></i> Switch to 50% Retention
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="bg-white border border-line rounded-[10px] p-6">
                <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
                    <div class="flex items-center gap-2.5 text-[15px] font-bold"><i class="fas fa-calendar-check text-emerald-500"></i> Payment Schedule</div>
                    <span class="text-[12px] text-muted"><?= $payment_split_mode === 'merged' ? '50% Down &bull; 50% Retention' : '50% Down &bull; 40% Before Installation &bull; 10% After Installation' ?></span>
                </div>

                <div class="flex flex-col gap-3">
                    <?php foreach ($payments as $payment):
                        $st = strtolower(str_replace(' ', '-', $payment['status']));
                        $canMark = true;
                        $disableMsg = '';
                        if ($payment['status'] === 'Not Available') {
                            $canMark = false;
                            $disableMsg = 'Not yet available';
                        }
                        if ($payment['payment_type'] === '40% Before Installation') {
                            $dq = $conn->prepare("SELECT status FROM payment_schedule WHERE client_id=? AND payment_type LIKE '%Down Payment%' LIMIT 1");
                            $dq->bind_param("i", $client_id);
                            $dq->execute();
                            $ds = $dq->get_result()->fetch_assoc();
                            if (!$ds || $ds['status'] !== 'Paid') {
                                $canMark = false;
                                $disableMsg = 'Downpayment must be paid first';
                            } else {
                                $iq = $conn->prepare("SELECT COUNT(*) t,SUM(CASE WHEN fabrication_status='Done' THEN 1 ELSE 0 END) f,SUM(CASE WHEN delivery_status='Done' THEN 1 ELSE 0 END) d FROM quotation_entries WHERE client_id=?");
                                $iq->bind_param("i", $client_id);
                                $iq->execute();
                                $ir = $iq->get_result()->fetch_assoc();
                                if ($ir['f'] != $ir['t'] || $ir['d'] != $ir['t'] || $ir['t'] == 0) {
                                    if ($payment['status'] != 'Paid') {
                                        $canMark = false;
                                        $disableMsg = 'All items must complete fabrication and delivery first';
                                    }
                                }
                            }
                        }
                        if ($payment['payment_type'] === '10% After Installation') {
                            $bq = $conn->prepare("SELECT status FROM payment_schedule WHERE client_id=? AND payment_type='40% Before Installation' LIMIT 1");
                            $bq->bind_param("i", $client_id);
                            $bq->execute();
                            $bs = $bq->get_result()->fetch_assoc();
                            if (!$bs || $bs['status'] !== 'Paid') {
                                $canMark = false;
                                $disableMsg = '40% Before Installation must be paid first';
                            } else {
                                $iq2 = $conn->prepare("SELECT COUNT(*) t,SUM(CASE WHEN installation_status='Done' THEN 1 ELSE 0 END) d FROM quotation_entries WHERE client_id=?");
                                $iq2->bind_param("i", $client_id);
                                $iq2->execute();
                                $ir2 = $iq2->get_result()->fetch_assoc();
                                if ($ir2['d'] != $ir2['t'] || $ir2['t'] == 0) {
                                    if ($payment['status'] != 'Paid') {
                                        $canMark = false;
                                        $disableMsg = 'All items must be fully installed first';
                                    }
                                }
                            }
                        }
                        if ($payment['payment_type'] === '50% Retention') {
                            $dq3 = $conn->prepare("SELECT status FROM payment_schedule WHERE client_id=? AND payment_type LIKE '%Down Payment%' LIMIT 1");
                            $dq3->bind_param("i", $client_id);
                            $dq3->execute();
                            $ds3 = $dq3->get_result()->fetch_assoc();
                            if (!$ds3 || $ds3['status'] !== 'Paid') {
                                $canMark = false;
                                $disableMsg = 'Downpayment must be paid first';
                            } else {
                                $iq3 = $conn->prepare("SELECT COUNT(*) t,SUM(CASE WHEN installation_status='Done' THEN 1 ELSE 0 END) d FROM quotation_entries WHERE client_id=?");
                                $iq3->bind_param("i", $client_id);
                                $iq3->execute();
                                $ir3 = $iq3->get_result()->fetch_assoc();
                                if ($ir3['d'] != $ir3['t'] || $ir3['t'] == 0) {
                                    if ($payment['status'] != 'Paid') {
                                        $canMark = false;
                                        $disableMsg = 'All items must be fully installed first';
                                    }
                                }
                            }
                        }
                        ?>
                        <div class="border border-line <?= stripeClasses($payment['status']) ?> border-l-4 rounded-lg p-4 <?= $st === 'paid' ? 'bg-emerald-50/60' : ($st === 'pending' ? 'bg-amber-50/60' : 'bg-[#F9F9F9]') ?>">
                            <div class="flex justify-between items-center mb-1.5 flex-wrap gap-2">
                                <span class="text-[14px] font-bold text-ink">
                                    <?= htmlspecialchars($payment['payment_type']) ?>
                                    <span class="text-muted text-[12px] font-normal">(<?= number_format($payment['percentage'], 1) ?>%)</span>
                                </span>
                                <span class="text-lg font-bold text-emerald-600">&#8369;<?= number_format($payment['amount'], 2) ?></span>
                            </div>
                            <div class="flex items-center gap-3 text-[12px] text-soft flex-wrap">
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase border <?= badgeClasses($payment['status']) ?>"><?= $payment['status'] ?></span>
                                <?php if (!$canMark && $payment['status'] !== 'Paid'): ?>
                                    <span class="text-[11px] text-amber-600"><i class="fas fa-info-circle"></i> <?= $disableMsg ?></span>
                                <?php endif; ?>
                                <?php if ($payment['payment_date']): ?>
                                    <span><i class="fas fa-check-circle text-emerald-500"></i> Paid: <?= date('M d, Y g:i A', strtotime($payment['payment_date'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($payment['status'] === 'Paid'):
                                $paidProofNP = getPaymentProofInfo($conn, $payment['id']);
                                $npNTP = getNTPInfo($conn, $payment['id']);
                                if ($paidProofNP): ?>
                                    <div class="bg-emerald-50 border-2 border-emerald-300 rounded-lg p-3.5 mt-2.5">
                                        <div class="text-[12px] font-bold text-emerald-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-clock-rotate-left"></i> Payment Proof (Approved)</div>
                                        <div class="text-[11px] text-emerald-800 mb-2"><i class="fas fa-check-circle"></i> Reviewed by: <?= htmlspecialchars($paidProofNP['reviewer_name'] ?? 'Accounting') ?></div>
                                        <?php if (strpos($paidProofNP['file_type'] ?? '', 'image') !== false): ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($paidProofNP['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                <img src="<?= BASE_URL ?><?= htmlspecialchars($paidProofNP['file_path']) ?>" class="max-w-full max-h-[180px] rounded-lg object-contain cursor-pointer">
                                            </a>
                                        <?php else: ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($paidProofNP['file_path']) ?>" target="_blank"
                                                class="inline-flex items-center gap-2 bg-[#F5F5F5] border border-line text-ink rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-line/40 transition mt-1.5">
                                                <i class="fas fa-file"></i> View Proof File
                                            </a>
                                        <?php endif; ?>
                                        <div class="text-[11px] text-muted mt-1.5"><i class="fas fa-paperclip"></i> <?= htmlspecialchars($paidProofNP['file_name']) ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($npNTP): ?>
                                    <div class="bg-blue-50 border-2 border-blue-300 rounded-lg p-3.5 mt-2.5">
                                        <div class="text-[12px] font-bold text-blue-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-file-signature"></i> Notice to Proceed (NTP) Issued</div>
                                        <div class="text-[11px] text-blue-800 mb-1.5">
                                            <i class="fas fa-user"></i> Uploaded by: <?= htmlspecialchars($npNTP['uploader_name'] ?? 'Accounting') ?>
                                            &bull; <?= date('M d, Y g:i A', strtotime($npNTP['uploaded_at'])) ?>
                                        </div>
                                        <?php if (!empty($npNTP['notes'])): ?>
                                            <div class="text-[11px] text-ink bg-blue-100/70 rounded-md p-2 mb-1.5"><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($npNTP['notes']) ?></div>
                                        <?php endif; ?>
                                        <?php if (strpos($npNTP['file_type'] ?? '', 'image') !== false): ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($npNTP['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                <img src="<?= BASE_URL ?><?= htmlspecialchars($npNTP['file_path']) ?>" class="max-w-full max-h-[180px] rounded-lg object-contain cursor-pointer">
                                            </a>
                                        <?php else: ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($npNTP['file_path']) ?>" target="_blank"
                                                class="inline-flex items-center gap-2 bg-[#F5F5F5] border border-line text-ink rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-line/40 transition mt-1.5">
                                                <i class="fas fa-file"></i> View NTP File
                                            </a>
                                        <?php endif; ?>
                                        <div class="text-[11px] text-muted mt-1.5"><i class="fas fa-paperclip"></i> <?= htmlspecialchars($npNTP['file_name']) ?></div>
                                        <?php if ($canApprovePayment): ?>
                                            <div class="flex justify-end mt-2.5">
                                                <button class="inline-flex items-center gap-1.5 border-2 border-emerald-600 text-emerald-700 rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-emerald-50 transition" onclick="openUpdateNTP(<?= $payment['id'] ?>)">
                                                    <i class="fas fa-sync-alt"></i> Update NTP
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; endif; ?>
                            <?php
                            $npProof = getPaymentProofInfo($conn, $payment['id']);
                            $npAcctStatus = $payment['accounting_status'] ?? 'not_submitted';
                            if ($payment['status'] !== 'Paid' && $payment['status'] !== 'Not Available' && !$view_only && ($canSubmitProof && $canMark || $isAccountingRole)):
                                $npBoxCls = $npAcctStatus === 'rejected' ? 'border-red-300 bg-red-50' : ($npAcctStatus === 'pending_review' ? 'border-amber-300 bg-amber-50' : 'border-emerald-200 bg-emerald-50/40');
                                ?>
                                <div class="border-2 <?= $npBoxCls ?> rounded-lg p-3.5 mt-2.5">
                                    <?php if ($npAcctStatus === 'rejected' && $npProof): ?>
                                        <div class="text-[12px] font-bold text-red-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-times-circle"></i> Proof rejected — resubmit</div>
                                        <div class="text-[11px] text-red-700 italic mb-2">"<?= htmlspecialchars($npProof['rejection_note'] ?? '') ?>"</div>
                                    <?php elseif ($npAcctStatus === 'pending_review'): ?>
                                        <div class="text-[12px] font-bold text-amber-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-clock"></i> Awaiting accounting review</div>
                                    <?php else: ?>
                                        <div class="text-[12px] font-bold text-emerald-800 flex items-center gap-1.5 mb-1.5"><i class="fas fa-upload"></i> Attach Proof of Payment</div>
                                    <?php endif; ?>
                                    <?php if ($npAcctStatus !== 'pending_review' && $canSubmitProof): ?>
                                        <input type="file" class="proof-input block w-full text-[13px] border-2 border-dashed border-line rounded-lg p-2 cursor-pointer bg-white"
                                            id="proofFile_<?= $payment['id'] ?>" accept="image/*,.pdf,.doc,.docx" onchange="previewProof(this, <?= $payment['id'] ?>)">
                                        <img id="proofImg_<?= $payment['id'] ?>" class="proof-preview max-w-full max-h-[180px] rounded-lg mt-2 object-contain">
                                        <div class="flex justify-end mt-2">
                                            <button class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-3 py-1.5 text-[12px] font-semibold transition"
                                                onclick="submitProof(<?= $payment['id'] ?>, <?= $client_id ?>)">
                                                <i class="fas fa-paper-plane"></i> Submit for Review
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($canApprovePayment && in_array($npAcctStatus, ['pending_review', 'rejected']) && $npProof): ?>
                                        <div class="mt-2.5 pt-2.5 border-t border-dashed <?= $npAcctStatus === 'rejected' ? 'border-red-300' : 'border-amber-300' ?>">
                                            <div class="text-[12px] font-bold <?= $npAcctStatus === 'rejected' ? 'text-red-800' : 'text-amber-800' ?> mb-2">
                                                <i class="fas fa-<?= $npAcctStatus === 'rejected' ? 'times-circle' : 'file-alt' ?>"></i>
                                                <?= $npAcctStatus === 'rejected' ? 'You rejected this proof' : 'Accounting Review' ?>
                                            </div>
                                            <?php if ($npAcctStatus === 'rejected' && !empty($npProof['rejection_note'])): ?>
                                                <div class="bg-red-100 border border-red-300 rounded-md p-2.5 mb-2.5">
                                                    <div class="text-[11px] font-bold text-red-800 mb-1">Your rejection note:</div>
                                                    <div class="text-[12px] text-red-700 italic">"<?= htmlspecialchars($npProof['rejection_note']) ?>"</div>
                                                </div>
                                                <div class="text-[11px] text-muted">Waiting for the uploader to resubmit a new proof.</div>
                                            <?php else: ?>
                                                <?php if (strpos($npProof['file_type'] ?? '', 'image') !== false): ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($npProof['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                        <img src="<?= BASE_URL ?><?= htmlspecialchars($npProof['file_path']) ?>" class="max-w-full max-h-[160px] rounded-md mb-2 object-contain cursor-pointer">
                                                    </a>
                                                <?php else: ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($npProof['file_path']) ?>" target="_blank"
                                                        class="inline-flex items-center gap-2 bg-[#F5F5F5] border border-line text-ink rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-line/40 transition mb-2">
                                                        <i class="fas fa-file"></i> View File
                                                    </a>
                                                <?php endif; ?>
                                                <div class="flex gap-2 justify-end flex-wrap">
                                                    <button class="inline-flex items-center gap-1.5 bg-[#F5F5F5] border border-line text-ink rounded-lg px-3 py-1.5 text-[12px] font-semibold hover:bg-line/40 transition" onclick="openRejectModal(<?= $payment['id'] ?>)">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                    <?php if (stripos($payment['payment_type'], 'Down Payment') !== false): ?>
                                                        <button class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-3 py-1.5 text-[12px] font-semibold transition" onclick="approvePayment(<?= $payment['id'] ?>)">
                                                            <i class="fas fa-check"></i> Approve & Upload NTP
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-3 py-1.5 text-[12px] font-semibold transition" onclick="quickApprove(<?= $payment['id'] ?>)">
                                                            <i class="fas fa-check"></i> Approve & Mark Paid
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    </div><!-- /wrap -->

    <!-- ══ Lightbox ══ -->
    <div id="lightboxOverlay" class="lightbox-overlay" onclick="closeLightbox()">
        <span class="fixed top-4 right-5 text-white text-2xl cursor-pointer z-[9999] bg-black/40 hover:bg-white/20 rounded-full w-10 h-10 flex items-center justify-center transition" onclick="closeLightbox()"><i class="fas fa-times"></i></span>
        <img id="lightboxImg" src="" class="max-w-[92vw] max-h-[90vh] rounded-lg object-contain shadow-2xl" onclick="event.stopPropagation()">
    </div>

    <!-- ══ Confirm Modal ══ -->
    <div id="confirmModal" class="modal-overlay">
        <div class="bg-white rounded-2xl p-7 max-w-[440px] w-[92%] shadow-2xl">
            <h3 class="text-[17px] font-bold text-ink mb-2"><i class="fas fa-check-circle text-emerald-500"></i> Confirm Payment</h3>
            <p id="confirmMsg" class="text-[13px] text-soft mb-5 leading-relaxed"></p>
            <input type="hidden" id="confirmId">
            <div class="flex gap-2.5 justify-end mt-4">
                <button class="bg-[#F5F5F5] border border-line text-ink rounded-lg px-4 py-2 text-[13px] font-semibold hover:bg-line/40 transition" onclick="closeConfirm()">Cancel</button>
                <button class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-4 py-2 text-[13px] font-semibold transition" onclick="doMarkPaid()">
                    <i class="fas fa-check"></i> Yes, Mark as Paid
                </button>
            </div>
        </div>
    </div>

    <!-- ══ NTP Upload Modal ══ -->
    <div id="ntpModal" class="modal-overlay">
        <div class="bg-white rounded-2xl p-7 max-w-[500px] w-[92%] shadow-2xl">
            <h3 class="text-[17px] font-bold text-ink mb-2"><i class="fas fa-file-signature text-emerald-500"></i> Upload Notice to Proceed (NTP)</h3>
            <p class="modal-sub text-[13px] text-soft mb-5 leading-relaxed">
                An NTP file is <strong class="text-red-500">required</strong> before this payment can be approved.
                Please attach the NTP document below.
            </p>
            <input type="hidden" id="ntpPaymentId">
            <input type="hidden" id="ntpClientId">

            <div class="mb-3.5">
                <label class="block text-[11px] font-bold text-gray-600 uppercase tracking-[0.4px] mb-1.5"><i class="fas fa-paperclip"></i> NTP File <span class="text-red-500">*</span></label>
                <input type="file" id="ntpFile" class="block w-full text-[13px] border-2 border-line rounded-lg p-2 cursor-pointer bg-white" accept="image/*,.pdf,.doc,.docx" onchange="previewNTP(this)">
                <img id="ntpPreview" class="max-w-full max-h-[160px] rounded-lg mt-2 object-contain">
            </div>

            <div class="mb-3.5">
                <label class="block text-[11px] font-bold text-gray-600 uppercase tracking-[0.4px] mb-1.5"><i class="fas fa-sticky-note"></i> Notes <span class="text-muted font-normal">(optional)</span></label>
                <textarea id="ntpNotes" rows="2" class="w-full px-3.5 py-2.5 border-2 border-line rounded-lg text-sm resize-y focus:outline-none focus:border-emerald-500" placeholder="e.g. NTP issued on June 3, 2026..."></textarea>
            </div>

            <div id="ntpErr" class="hidden text-red-500 text-[13px] px-3 py-2 bg-red-100 rounded-md mb-3"></div>

            <div class="flex gap-2.5 justify-end mt-4">
                <button class="bg-[#F5F5F5] border border-line text-ink rounded-lg px-4 py-2 text-[13px] font-semibold hover:bg-line/40 transition" onclick="closeNTPModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="btn-green inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-4 py-2 text-[13px] font-semibold transition" onclick="doApproveWithNTP()">
                    <i class="fas fa-check"></i> Approve &amp; Upload NTP
                </button>
            </div>
        </div>
    </div>

    <!-- ══ Reject Proof Modal ══ -->
    <div id="rejectModal" class="modal-overlay">
        <div class="bg-white rounded-2xl p-7 max-w-[440px] w-[92%] shadow-2xl">
            <h3 class="text-[17px] font-bold text-ink mb-2"><i class="fas fa-times-circle text-red-500"></i> Reject Proof</h3>
            <p class="text-[13px] text-soft mb-5 leading-relaxed">Please provide a reason so the submitter can resubmit correctly.</p>
            <input type="hidden" id="rejectPaymentId">
            <div class="mb-3.5">
                <label class="block text-[11px] font-bold text-gray-600 uppercase tracking-[0.4px] mb-1.5">Rejection Reason <span class="text-red-500">*</span></label>
                <textarea id="rejectNote" rows="3" class="w-full px-3.5 py-2.5 border-2 border-line rounded-lg text-sm resize-y focus:outline-none focus:border-emerald-500" placeholder="e.g. Image is blurry, wrong receipt..."></textarea>
            </div>
            <div id="rejectErr" class="hidden text-red-500 text-[13px] px-3 py-2 bg-red-100 rounded-md mb-3"></div>
            <div class="flex gap-2.5 justify-end mt-4">
                <button class="bg-[#F5F5F5] border border-line text-ink rounded-lg px-4 py-2 text-[13px] font-semibold hover:bg-line/40 transition" onclick="closeRejectModal()">Cancel</button>
                <button class="inline-flex items-center gap-1.5 bg-red-500 hover:bg-red-600 text-white rounded-lg px-4 py-2 text-[13px] font-semibold transition" onclick="submitReject()">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
        </div>
    </div>

    <!-- ══ Edit Amount Modal ══ -->
    <div id="editModal" class="modal-overlay">
        <div class="bg-white rounded-2xl p-7 max-w-[440px] w-[92%] shadow-2xl">
            <h3 class="text-[17px] font-bold text-ink mb-2"><i class="fas fa-edit text-blue-500"></i> Edit Billing Amount</h3>
            <p id="editModalLabel" class="text-[13px] text-soft mb-5 leading-relaxed"></p>
            <input type="hidden" id="editId">
            <div class="mb-3.5">
                <label class="block text-[11px] font-bold text-gray-600 uppercase tracking-[0.4px] mb-1.5">New Amount (&#8369;)</label>
                <input type="number" id="editAmt" class="w-full px-3.5 py-2.5 border-2 border-line rounded-lg text-sm font-semibold focus:outline-none focus:border-emerald-500" min="0" step="0.01">
            </div>
            <div id="editErr" class="hidden text-red-500 text-[13px] px-3 py-2 bg-red-100 rounded-md mb-3"></div>
            <div class="flex gap-2.5 justify-end mt-4">
                <button class="bg-[#F5F5F5] border border-line text-ink rounded-lg px-4 py-2 text-[13px] font-semibold hover:bg-line/40 transition" onclick="closeEditModal()">Cancel</button>
                <button class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-4 py-2 text-[13px] font-semibold transition" onclick="submitEdit()">
                    <i class="fas fa-save"></i> Update Amount
                </button>
            </div>
        </div>
    </div>

    <!-- ══ Quick Approve Confirmation Modal ══ -->
    <div id="quickApproveModal" class="modal-overlay">
        <div class="bg-white rounded-2xl p-7 max-w-[440px] w-[92%] shadow-2xl">
            <h3 class="text-[17px] font-bold text-ink mb-2"><i class="fas fa-check-circle text-emerald-500"></i> Confirm Approval</h3>
            <p class="text-[13px] text-soft mb-5 leading-relaxed">
                Are you sure you want to approve this payment proof and mark it as <strong class="text-emerald-600">Paid</strong>? This action cannot be undone.
            </p>
            <div class="flex gap-2.5 justify-end mt-4">
                <button class="bg-[#F5F5F5] border border-line text-ink rounded-lg px-4 py-2 text-[13px] font-semibold hover:bg-line/40 transition" onclick="closeQuickApproveModal()">Cancel</button>
                <button class="btn-green inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-4 py-2 text-[13px] font-semibold transition" onclick="doQuickApprove()">
                    <i class="fas fa-check"></i> Yes, Approve & Mark Paid
                </button>
            </div>
        </div>
    </div>

    <!-- ══ Toggle Payment Split Confirmation Modal ══ -->
    <div id="toggleConfirmModal" class="modal-overlay">
        <div class="bg-white rounded-2xl p-7 max-w-[440px] w-[92%] shadow-2xl">
            <h3 class="text-[17px] font-bold text-ink mb-2"><i class="fas fa-random text-blue-500"></i> Confirm Payment Split Change</h3>
            <p id="toggleConfirmMsg" class="text-[13px] text-soft mb-5 leading-relaxed"></p>
            <div class="flex gap-2.5 justify-end mt-4">
                <button class="bg-[#F5F5F5] border border-line text-ink rounded-lg px-4 py-2 text-[13px] font-semibold hover:bg-line/40 transition" onclick="closeToggleConfirm()">Cancel</button>
                <button class="btn-green inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-4 py-2 text-[13px] font-semibold transition" onclick="doToggleSplit()">
                    <i class="fas fa-check"></i> Yes, Continue
                </button>
            </div>
        </div>
    </div>

    <!-- ══ Toast ══ -->
    <div id="toast" class="toast bg-white px-5 py-3 rounded-lg shadow-lg border-l-4 border-emerald-500 text-[13px] font-semibold">
        <i id="toastIcon" class="fas fa-check-circle text-[17px]"></i>
        <span id="toastMsg"></span>
    </div>

    <script>
        window.PAYMENT_TRACKER_CONFIG = {
            baseUrl: '<?= BASE_URL ?>',
            clientId: <?= $client_id ?>,
            totalCost: <?= $total_cost ?>,
            remainingBal: <?= $remaining_balance ?>,
            suggested: <?= isset($suggested_amount) ? $suggested_amount : 0 ?>,
            snapshotPct: <?= isset($overall_pct) ? $overall_pct : 0 ?>
        };
    </script>
    <script src="<?= BASE_ASSET ?>/realiving_admin/tracker-management/tracker-payment/js/payment_tracker.js"></script>
</body>

</html>