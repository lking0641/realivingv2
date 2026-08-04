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
    // Items with no unit distribution: use their own status
    // Items with unit distribution: count each unit
    $epStmt = $conn->prepare("
        SELECT
            COUNT(DISTINCT CASE WHEN qrd.distribution_id IS NULL THEN qe.id END)                                            AS items_no_unit,
            COUNT(DISTINCT CASE WHEN qrd.distribution_id IS NULL AND qe.installation_status='Done' THEN qe.id END)          AS items_no_unit_done,
            COUNT(qrd.distribution_id)                                                                                       AS units_total,
            SUM(CASE WHEN qrd.distribution_id IS NOT NULL AND qrd.installation_status='Done' THEN 1 ELSE 0 END)             AS units_done
        FROM quotation_entries qe
        LEFT JOIN quotation_room_distribution qrd ON qrd.quotation_entry_id = qe.id
        WHERE qe.client_id = ?
    ");
    $epStmt->bind_param("i", $client_id);
    $epStmt->execute();
    $ep = $epStmt->get_result()->fetch_assoc();

    $fpStmt = $conn->prepare("
        SELECT
            COUNT(DISTINCT CASE WHEN qrd.distribution_id IS NULL THEN qfs.id END)                                           AS items_no_unit,
            COUNT(DISTINCT CASE WHEN qrd.distribution_id IS NULL AND qfs.installation_status='Done' THEN qfs.id END)        AS items_no_unit_done,
            COUNT(qrd.distribution_id)                                                                                       AS units_total,
            SUM(CASE WHEN qrd.distribution_id IS NOT NULL AND qrd.installation_status='Done' THEN 1 ELSE 0 END)             AS units_done
        FROM quotation_fixed_sizes qfs
        LEFT JOIN quotation_room_distribution qrd ON qrd.quotation_fixed_size_id = qfs.id
        WHERE qfs.client_id = ?
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

    // Sum of already billed amounts and find the highest snapshot_pct billed so far
    $already_billed_pct = 0;
    $already_billed_amt = 0;
    $total_paid = 0;
    $last_snapshot_pct = 0; // highest progress % already collected against

    foreach ($collections as $c) {
        $already_billed_pct += (float) $c['percentage']; // amount-based %, for display only
        $already_billed_amt += (float) $c['amount'];
        if ($c['status'] === 'Paid')
            $total_paid += (float) $c['amount'];
        // Track the highest snapshot_pct billed (the progress % at time of each billing)
        if (!empty($c['snapshot_pct']) && (float) $c['snapshot_pct'] > $last_snapshot_pct) {
            $last_snapshot_pct = (float) $c['snapshot_pct'];
        }
    }
    if ($dpRow && $dpRow['status'] === 'Paid')
        $total_paid += (float) $dpRow['amount'];

    // Suggested = (current overall% - last billed snapshot%) × remaining_balance
    $unbilled_pct = max(0, $overall_pct - $last_snapshot_pct);

    // If progress is 100%, suggest the full remaining balance directly
// instead of a percentage calculation (avoids rounding gaps)
    if ($overall_pct >= 100) {
        $suggested_amount = round($remaining_balance, 2);
    } else {
        $suggested_amount = round($remaining_balance * ($unbilled_pct / 100), 2);
    }
    $next_no = count($collections) + 1;

    // Ordinal suffix
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

    // ── Area breakdown for sidebar (unit-aware) ──
// For items WITH units: count each unit from quotation_room_distribution
// For items WITHOUT units: count the item directly
    $areaStmt = $conn->prepare("
    SELECT
        qe.area,
        COUNT(qrd.distribution_id)                                                              AS units_total,
        SUM(CASE WHEN qrd.installation_status = 'Done' THEN 1 ELSE 0 END)                      AS units_done,
        COUNT(DISTINCT CASE WHEN qrd.distribution_id IS NULL THEN qe.id END)                   AS items_no_unit,
        COUNT(DISTINCT CASE WHEN qrd.distribution_id IS NULL AND qe.installation_status = 'Done' THEN qe.id END) AS items_no_unit_done
    FROM quotation_entries qe
    LEFT JOIN quotation_room_distribution qrd ON qrd.quotation_entry_id = qe.id
    WHERE qe.client_id = ?
    GROUP BY qe.area
    UNION ALL
    SELECT
        qfs.area,
        COUNT(qrd.distribution_id)                                                               AS units_total,
        SUM(CASE WHEN qrd.installation_status = 'Done' THEN 1 ELSE 0 END)                       AS units_done,
        COUNT(DISTINCT CASE WHEN qrd.distribution_id IS NULL THEN qfs.id END)                   AS items_no_unit,
        COUNT(DISTINCT CASE WHEN qrd.distribution_id IS NULL AND qfs.installation_status = 'Done' THEN qfs.id END) AS items_no_unit_done
    FROM quotation_fixed_sizes qfs
    LEFT JOIN quotation_room_distribution qrd ON qrd.quotation_fixed_size_id = qfs.id
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
        // Recalculate amounts for unpaid entries if total cost changed
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

    // Determine if the split can still be toggled (locked once the relevant stage is Paid)
    $toggleLockedRow = null;
    foreach ($payments as $p) {
        if ($p['payment_type'] === '40% Before Installation' || $p['payment_type'] === '50% Retention') {
            $toggleLockedRow = $p;
            break;
        }
    }
    $isSplitLocked = $toggleLockedRow && $toggleLockedRow['status'] === 'Paid';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Payment Tracker — <?= htmlspecialchars($client['clientname']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --green: #059669;
            --green2: #10b981;
            --brand: #3b1f0f;
            --bg: #f5f1ed;
            --yellow: #f59e0b;
            --blue: #3b82f6;
        }

        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--bg);
            font-family: 'Segoe UI', sans-serif;
            color: #1f2937;
        }

        .wrap {
            max-width: 1240px;
            margin: 30px auto;
            padding: 0 20px;
        }

        /* ── Header ── */
        .client-header {
            background: linear-gradient(135deg, #059669, #10b981);
            padding: 32px 36px;
            border-radius: 16px;
            color: #fff;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .client-header::after {
            content: '';
            position: absolute;
            right: -40px;
            top: -40px;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .07);
        }

        .hdr-inner {
            position: relative;
            z-index: 1;
        }

        .hdr-inner h1 {
            font-size: 24px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .biz-tag {
            background: rgba(255, 255, 255, .2);
            border: 1px solid rgba(255, 255, 255, .3);
            padding: 3px 13px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .5px;
        }

        .hdr-sub {
            font-size: 14px;
            opacity: .88;
            margin-top: 7px;
        }

        .kpi-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .kpi {
            background: rgba(255, 255, 255, .14);
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 10px;
            padding: 12px 14px;
        }

        .kpi-label {
            font-size: 10px;
            opacity: .72;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 3px;
        }

        .kpi-val {
            font-size: 19px;
            font-weight: 800;
            line-height: 1.2;
        }

        .kpi-val.sm {
            font-size: 14px;
        }

        /* ── Layout ── */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #059669;
            color: #fff;
            padding: 8px 17px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            margin-bottom: 16px;
            transition: background .2s;
        }

        .back-link:hover {
            background: #047857;
        }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 20px;
            align-items: start;
        }

        @media(max-width:860px) {
            .two-col {
                grid-template-columns: 1fr;
            }
        }

        /* ── Card ── */
        .card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .07);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-hdr {
            padding: 15px 22px;
            border-bottom: 2px solid #f5f1ed;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .card-hdr h2 {
            font-size: 15px;
            font-weight: 700;
            color: var(--brand);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-hdr .sub {
            font-size: 12px;
            color: #9ca3af;
        }

        .card-body {
            padding: 20px 22px;
        }

        /* ── Down Payment ── */
        .dp-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            padding: 16px 18px;
            border-radius: 10px;
            border: 2px solid #e9ecef;
        }

        .dp-row.paid {
            border-color: #10b981;
            background: #f0fdf4;
        }

        .dp-row.pending {
            border-color: #f59e0b;
            background: #fffbeb;
        }

        .dp-left .dp-type {
            font-size: 15px;
            font-weight: 700;
            color: #111;
            margin-bottom: 6px;
        }

        .dp-right .dp-amount {
            font-size: 22px;
            font-weight: 800;
            color: #059669;
            text-align: right;
        }

        /* ── Progress Banner ── */
        .prog-banner {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border: 2px solid #a7f3d0;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 20px;
        }

        .prog-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 9px;
            flex-wrap: wrap;
            gap: 6px;
        }

        .prog-title {
            font-size: 13px;
            font-weight: 700;
            color: #065f46;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .prog-stat {
            font-size: 15px;
            font-weight: 800;
            color: #059669;
        }

        .prog-bar-bg {
            height: 14px;
            background: #d1fae5;
            border-radius: 7px;
            overflow: hidden;
        }

        .prog-bar-fill {
            height: 100%;
            border-radius: 7px;
            background: linear-gradient(90deg, #059669, #34d399);
            transition: width .6s ease;
        }

        .prog-bottom {
            display: flex;
            justify-content: space-between;
            margin-top: 6px;
            font-size: 11px;
            color: #6b7280;
            flex-wrap: wrap;
            gap: 4px;
        }

        /* ── Collection list ── */
        .coll-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .coll-item {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            transition: box-shadow .18s;
        }

        .coll-item:hover {
            box-shadow: 0 3px 14px rgba(0, 0, 0, .09);
        }

        .coll-item.paid {
            border-color: #10b981;
        }

        .coll-item.pending {
            border-color: #f59e0b;
        }

        .coll-top {
            padding: 11px 17px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .coll-top.paid {
            background: #f0fdf4;
        }

        .coll-top.pending {
            background: #fffbeb;
        }

        .coll-name {
            font-size: 13px;
            font-weight: 700;
            color: #111;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .coll-num {
            background: var(--brand);
            color: #fff;
            min-width: 26px;
            height: 26px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 800;
        }

        .snap-pill {
            background: #dbeafe;
            color: #1e40af;
            padding: 2px 9px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .coll-bot {
            padding: 13px 17px;
            border-top: 1px solid #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .coll-amount {
            font-size: 21px;
            font-weight: 800;
            color: #059669;
        }

        .coll-meta {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        /* ── Badges ── */
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-paid {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-not-available {
            background: #e5e7eb;
            color: #6b7280;
        }

        /* ── Add billing form ── */
        .add-form {
            background: linear-gradient(135deg, #f0fdf4, #f8fffc);
            border: 2px dashed #6ee7b7;
            border-radius: 12px;
            padding: 20px;
            margin-top: 6px;
        }

        .add-form-title {
            color: #065f46;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .add-form-sub {
            color: #9ca3af;
            font-size: 12px;
            margin-bottom: 16px;
        }

        /* Suggestion box */
        .sug-box {
            background: #fff;
            border: 2px solid #a7f3d0;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 16px;
        }

        .sug-line {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 0;
        }

        .sug-key {
            font-size: 12px;
            color: #6b7280;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .sug-v {
            font-size: 13px;
            font-weight: 700;
            color: #374151;
        }

        .sug-divider {
            border: none;
            border-top: 1px dashed #d1fae5;
            margin: 8px 0;
        }

        .sug-big {
            font-size: 21px;
            font-weight: 800;
            color: #059669;
        }

        .sug-formula {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 8px;
            padding-top: 7px;
            border-top: 1px solid #f0fdf4;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #4a5568;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 5px;
        }

        .form-input {
            width: 100%;
            padding: 10px 13px;
            border: 2px solid #d1fae5;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            background: #fff;
            transition: border-color .2s;
        }

        .form-input:focus {
            outline: none;
            border-color: #10b981;
        }

        .form-input.overridden {
            border-color: #f59e0b !important;
            background: #fffbeb;
        }

        .hint {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 4px;
        }

        /* ── Buttons ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: all .2s;
            white-space: nowrap;
        }

        .btn-green {
            background: #10b981;
            color: #fff;
        }

        .btn-green:hover {
            background: #059669;
        }

        .btn-gray {
            background: #e5e7eb;
            color: #374151;
        }

        .btn-gray:hover {
            background: #d1d5db;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid #10b981;
            color: #059669;
        }

        .btn-outline:hover {
            background: #f0fdf4;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn:disabled {
            background: #d1d5db !important;
            color: #9ca3af !important;
            cursor: not-allowed;
            border-color: #d1d5db !important;
        }

        .btn-row {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        /* ── Sidebar area breakdown ── */
        .sidebar-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .07);
            overflow: hidden;
            position: sticky;
            top: 20px;
        }

        .sb-hdr {
            padding: 14px 18px;
            background: var(--brand);
            color: #fff;
        }

        .sb-hdr h3 {
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .sb-body {
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 9px;
            max-height: 500px;
            overflow-y: auto;
        }

        .area-row {
            padding: 9px 11px;
            border-radius: 8px;
            background: #f9f9f9;
            border: 1px solid #f0ece8;
        }

        .area-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3px;
        }

        .area-name {
            font-size: 12px;
            font-weight: 700;
            color: #374151;
        }

        .area-pct {
            font-size: 12px;
            font-weight: 800;
        }

        .area-count {
            font-size: 11px;
            color: #9ca3af;
            margin-bottom: 4px;
        }

        .mini-bar-bg {
            height: 5px;
            background: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
        }

        .mini-bar-fill {
            height: 100%;
            border-radius: 3px;
            transition: width .4s;
        }

        /* ── Non-project payment items ── */
        .pay-item {
            border-left: 4px solid #e9ecef;
            padding: 14px 18px;
            margin-bottom: 12px;
            border-radius: 8px;
            background: #f9f9f9;
        }

        .pay-item.status-pending {
            border-left-color: #f59e0b;
            background: #fffbeb;
        }

        .pay-item.status-paid {
            border-left-color: #10b981;
            background: #f0fdf4;
        }

        .pay-item.status-not-available {
            border-left-color: #9ca3af;
            background: #f9fafb;
            opacity: .8;
        }

        .pay-hdr {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .pay-type {
            font-size: 14px;
            font-weight: 600;
            color: #111;
        }

        .pay-amt {
            font-size: 18px;
            font-weight: 700;
            color: #059669;
        }

        .pay-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 12px;
            color: #666;
            flex-wrap: wrap;
        }

        /* ── Modals ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal {
            background: #fff;
            border-radius: 16px;
            padding: 28px;
            max-width: 440px;
            width: 92%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .22);
        }

        .modal h3 {
            font-size: 17px;
            font-weight: 700;
            color: var(--brand);
            margin-bottom: 8px;
        }

        .modal .modal-sub {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .modal .form-input {
            border: 2px solid #e2e8f0;
        }

        .modal .form-input:focus {
            border-color: #10b981;
        }

        .modal-btns {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 18px;
        }

        .modal-err {
            display: none;
            color: #ef4444;
            font-size: 13px;
            padding: 8px 12px;
            background: #fee2e2;
            border-radius: 6px;
            margin-bottom: 12px;
        }

        /* ── Empty state ── */
        .empty {
            text-align: center;
            padding: 34px 20px;
            color: #9ca3af;
        }

        .empty i {
            font-size: 34px;
            display: block;
            margin-bottom: 10px;
            opacity: .5;
        }

        .empty p {
            font-size: 13px;
        }

        /* ── Proof upload ── */
        .proof-box {
            margin-top: 12px;
            border: 2px dashed #d1fae5;
            border-radius: 10px;
            padding: 14px 16px;
            background: #f0fdf4;
        }

        .proof-box.rejected {
            border-color: #fca5a5;
            background: #fef2f2;
        }

        .proof-box.pending {
            border-color: #fde68a;
            background: #fffbeb;
        }

        .proof-box.approved {
            border-color: #6ee7b7;
            background: #f0fdf4;
        }

        .proof-title {
            font-size: 12px;
            font-weight: 700;
            color: #065f46;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .proof-title.rejected {
            color: #991b1b;
        }

        .proof-title.pending {
            color: #92400e;
        }

        .proof-input {
            width: 100%;
            padding: 8px;
            border: 2px solid #d1fae5;
            border-radius: 7px;
            font-size: 13px;
            background: #fff;
            cursor: pointer;
        }

        .proof-preview {
            max-width: 100%;
            max-height: 180px;
            border-radius: 8px;
            margin-top: 8px;
            display: none;
            object-fit: contain;
        }

        .proof-filename {
            font-size: 11px;
            color: #6b7280;
            margin-top: 4px;
        }

        .acct-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
        }

        .acct-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .acct-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .acct-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        /* ── Toast ── */
        .toast {
            position: fixed;
            top: 18px;
            right: 18px;
            background: #fff;
            padding: 12px 20px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, .14);
            display: none;
            align-items: center;
            gap: 10px;
            z-index: 9999;
            font-size: 13px;
            font-weight: 600;
            animation: slideIn .3s ease;
        }

        .toast.show {
            display: flex;
        }

        .toast.success {
            border-left: 4px solid #10b981;
        }

        .toast.error {
            border-left: 4px solid #ef4444;
        }

        @keyframes slideIn {
            from {
                transform: translateX(360px);
                opacity: 0
            }

            to {
                transform: translateX(0);
                opacity: 1
            }
        }

        /* ── Lightbox ── */
        .lightbox-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .88);
            z-index: 9998;
            align-items: center;
            justify-content: center;
            cursor: zoom-out;
        }
        .lightbox-overlay.open {
            display: flex;
        }
        .lightbox-overlay img {
            max-width: 92vw;
            max-height: 90vh;
            border-radius: 10px;
            object-fit: contain;
            box-shadow: 0 8px 40px rgba(0,0,0,.6);
            cursor: default;
        }
        .lightbox-close {
            position: fixed;
            top: 16px;
            right: 20px;
            color: #fff;
            font-size: 28px;
            cursor: pointer;
            z-index: 9999;
            background: rgba(0,0,0,.4);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .2s;
        }
        .lightbox-close:hover {
            background: rgba(255,255,255,.2);
        }
    </style>
</head>

<body>
    <div class="wrap">

        <a href="<?= $view_only ? 'manager-project-detail?client_id=' . $client_id : 'unified-project-tracker?client_id=' . $client_id ?>"
            class="back-link">
            <i class="fas fa-arrow-left"></i> <?= $view_only ? 'Back to Project Detail' : 'Back to Project Tracker' ?>
        </a>

        <?php if ($view_only): ?>
            <div
                style="background:#fef3c7; border:2px solid #f59e0b; border-radius:10px; padding:13px 18px; margin-bottom:16px; display:flex; align-items:center; gap:10px; font-size:13px; font-weight:600; color:#92400e;">
                <i class="fas fa-eye"></i> View Only — You can view payment details but cannot make changes.
            </div>
        <?php endif; ?>

        <!-- ══ PAGE HEADER ══ -->
        <div class="client-header">
            <div class="hdr-inner">
                <h1>
                    <i class="fas fa-money-bill-wave"></i> Payment Tracker
                    <span
                        class="biz-tag"><?= $business_type === 'Non-Project' ? 'Individual' : htmlspecialchars($business_type) ?></span>
                </h1>
                <div class="hdr-sub"><?= htmlspecialchars($client['clientname']) ?> &mdash;
                    <?= htmlspecialchars($client['nameproject']) ?>
                </div>
                <div class="kpi-row">
                    <div class="kpi">
                        <div class="kpi-label">Total Project Cost</div>
                        <div class="kpi-val">&#8369;<?= number_format($total_cost, 2) ?></div>
                    </div>
                    <div class="kpi">
                        <div class="kpi-label">Total Paid</div>
                        <div class="kpi-val">&#8369;<?= number_format($total_paid, 2) ?></div>
                    </div>
                    <div class="kpi">
                        <div class="kpi-label">Remaining Balance</div>
                        <div class="kpi-val">&#8369;<?= number_format($remaining_balance, 2) ?></div>
                    </div>
                    <?php if ($business_type === 'Project'): ?>
                        <div class="kpi">
                            <div class="kpi-label">Overall Progress</div>
                            <div class="kpi-val"><?= $overall_pct ?>%</div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-label">Already Billed</div>
                            <div class="kpi-val sm">&#8369;<?= number_format($already_billed_amt, 2) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($business_type === 'Project'): ?>
            <!-- ════════════════════════════════════ PROJECT ════════════════════════════════════ -->
            <div class="two-col">

                <!-- LEFT -->
                <div>

                    <!-- Down Payment -->
                    <div class="card">
                        <div class="card-hdr">
                            <h2><i class="fas fa-hand-holding-usd" style="color:#f59e0b;"></i> Down Payment</h2>
                            <span class="sub">30% of total project cost</span>
                        </div>
                        <div class="card-body">
                            <?php if ($dpRow):
                                $dpCls = strtolower($dpRow['status']);
                                ?>
                                <div class="dp-row <?= $dpCls ?>">
                                    <div class="dp-left">
                                        <div class="dp-type">Down Payment (30%)</div>
                                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:5px;">
                                            <span class="badge badge-<?= $dpCls ?>"><?= $dpRow['status'] ?></span>
                                            <?php if ($dpRow['payment_date']): ?>
                                                <span style="font-size:12px;color:#6b7280;">
                                                    <i class="fas fa-check-circle" style="color:#10b981;"></i>
                                                    Paid on: <?= date('M d, Y g:i A', strtotime($dpRow['payment_date'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="dp-right">
                                        <div class="dp-amount">&#8369;<?= number_format($dpRow['amount'], 2) ?></div>
                                        <?php if ($dpRow['status'] === 'Paid'):
                                            $dpPaidProof = getPaymentProofInfo($conn, $dpRow['id']);
                                            $dpNTP = getNTPInfo($conn, $dpRow['id']);
                                            if ($dpPaidProof): ?>
                                                <div class="proof-box approved" style="margin-top:10px;">
                                                    <div class="proof-title"><i class="fas fa-history"></i> Payment Proof (Approved)
                                                    </div>
                                                    <div style="font-size:11px;color:#065f46;margin-bottom:8px;">
                                                        <i class="fas fa-check-circle"></i> Reviewed by:
                                                        <?= htmlspecialchars($dpPaidProof['reviewer_name'] ?? 'Accounting') ?>
                                                    </div>
                                                    <?php if (strpos($dpPaidProof['file_type'] ?? '', 'image') !== false): ?>
                                                        <a href="<?= BASE_URL ?><?= htmlspecialchars($dpPaidProof['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                        <img src="<?= BASE_URL ?><?= htmlspecialchars($dpPaidProof['file_path']) ?>"
                                                            style="max-width:100%; max-height:200px; border-radius:8px; object-fit:contain; cursor:pointer;">
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="<?= BASE_URL ?><?= htmlspecialchars($dpPaidProof['file_path']) ?>" target="_blank"
                                                            class="btn btn-sm btn-gray" style="margin-top:6px;">
                                                            <i class="fas fa-file"></i> View Proof File
                                                        </a>
                                                    <?php endif; ?>
                                                    <div class="proof-filename" style="margin-top:6px;">
                                                        <i class="fas fa-paperclip"></i>
                                                        <?= htmlspecialchars($dpPaidProof['file_name']) ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($dpNTP): ?>
                                                <div
                                                    style="margin-top:10px; background:#f0f9ff; border:2px solid #7dd3fc; border-radius:10px; padding:14px 16px;">
                                                    <div
                                                        style="font-size:12px; font-weight:700; color:#0369a1; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                                                        <i class="fas fa-file-signature"></i> Notice to Proceed (NTP) Issued
                                                    </div>
                                                    <div style="font-size:11px; color:#0369a1; margin-bottom:8px;">
                                                        <i class="fas fa-user"></i> Uploaded by:
                                                        <?= htmlspecialchars($dpNTP['uploader_name'] ?? 'Accounting') ?>
                                                        &bull; <?= date('M d, Y g:i A', strtotime($dpNTP['uploaded_at'])) ?>
                                                    </div>
                                                    <?php if (!empty($dpNTP['notes'])): ?>
                                                        <div
                                                            style="font-size:11px; color:#374151; background:#e0f2fe; border-radius:6px; padding:7px 10px; margin-bottom:8px;">
                                                            <i class="fas fa-sticky-note"></i> <?= htmlspecialchars($dpNTP['notes']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (strpos($dpNTP['file_type'] ?? '', 'image') !== false): ?>
                                                        <a href="<?= BASE_URL ?><?= htmlspecialchars($dpNTP['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                        <img src="<?= BASE_URL ?><?= htmlspecialchars($dpNTP['file_path']) ?>"
                                                            style="max-width:100%; max-height:200px; border-radius:8px; object-fit:contain; cursor:pointer;">
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="<?= BASE_URL ?><?= htmlspecialchars($dpNTP['file_path']) ?>" target="_blank"
                                                            class="btn btn-sm btn-gray" style="margin-top:6px;">
                                                            <i class="fas fa-file"></i> View NTP File
                                                        </a>
                                                    <?php endif; ?>
                                                    <div class="proof-filename" style="margin-top:6px;">
                                                        <i class="fas fa-paperclip"></i> <?= htmlspecialchars($dpNTP['file_name']) ?>
                                                    </div>
                                                    <?php if ($canApprovePayment): ?>
                                                    <div class="btn-row" style="margin-top:10px;">
                                                        <button class="btn btn-sm btn-outline" onclick="openUpdateNTP(<?= $dpRow['id'] ?>)">
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
                                                ?>
                                                <div
                                                    class="proof-box <?= $dpAcctStatus === 'rejected' ? 'rejected' : ($dpAcctStatus === 'pending_review' ? 'pending' : '') ?>">
                                                    <?php if ($dpAcctStatus === 'rejected'): ?>
                                                        <div class="proof-title rejected"><i class="fas fa-times-circle"></i> Proof rejected
                                                            — please resubmit</div>
                                                        <div style="font-size:11px;color:#b91c1c;margin-bottom:8px;font-style:italic;">
                                                            "<?= htmlspecialchars($dpProof['rejection_note'] ?? 'No reason provided') ?>"
                                                            — <?= htmlspecialchars($dpProof['reviewer_name'] ?? 'Accounting') ?>
                                                        </div>
                                                    <?php elseif ($dpAcctStatus === 'pending_review'): ?>
                                                        <div class="proof-title pending"><i class="fas fa-clock"></i> Proof submitted —
                                                            awaiting accounting review</div>
                                                        <?php if ($dpProof): ?>
                                                            <div class="proof-filename"><i class="fas fa-paperclip"></i>
                                                                <?= htmlspecialchars($dpProof['file_name']) ?></div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <div class="proof-title"><i class="fas fa-upload"></i> Attach Proof of Payment</div>
                                                    <?php endif; ?>
                                                    <?php if ($dpAcctStatus !== 'pending_review'): ?>
                                                        <input type="file" class="proof-input" id="proofFile_<?= $dpRow['id'] ?>"
                                                            accept="image/*,.pdf,.doc,.docx"
                                                            onchange="previewProof(this, <?= $dpRow['id'] ?>)">
                                                        <img id="proofImg_<?= $dpRow['id'] ?>" class="proof-preview">
                                                        <div class="btn-row" style="margin-top:10px;">
                                                            <button class="btn btn-sm btn-green"
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
                                // Accounting review panel for downpayment
                                if ($canApprovePayment && isset($dpRow) && $dpRow['status'] === 'Pending') {
                                    $dpProofAcct = getPaymentProofInfo($conn, $dpRow['id']);
                                    $dpAcctStatus = $dpRow['accounting_status'] ?? 'not_submitted';
                                    if (in_array($dpAcctStatus, ['pending_review', 'rejected']) && $dpProofAcct):
                                        ?>
                                        <div
                                            style="margin-top:12px; background:<?= $dpAcctStatus === 'rejected' ? '#fef2f2' : '#fffbeb' ?>; border:2px solid <?= $dpAcctStatus === 'rejected' ? '#fca5a5' : '#fde68a' ?>; border-radius:10px; padding:14px 16px;">
                                            <div
                                                style="font-weight:700; font-size:13px; color:<?= $dpAcctStatus === 'rejected' ? '#991b1b' : '#92400e' ?>; margin-bottom:8px;">
                                                <i class="fas fa-<?= $dpAcctStatus === 'rejected' ? 'times-circle' : 'file-alt' ?>"></i>
                                                <?= $dpAcctStatus === 'rejected' ? 'You rejected this proof' : 'Proof submitted — Accounting Review Required' ?>
                                            </div>
                                            <?php if ($dpAcctStatus === 'rejected' && !empty($dpProofAcct['rejection_note'])): ?>
                                                <div
                                                    style="background:#fee2e2; border:1px solid #fca5a5; border-radius:7px; padding:10px 12px; margin-bottom:10px;">
                                                    <div style="font-size:11px; font-weight:700; color:#991b1b; margin-bottom:3px;">Your
                                                        rejection note:</div>
                                                    <div style="font-size:12px; color:#b91c1c; font-style:italic;">
                                                        "<?= htmlspecialchars($dpProofAcct['rejection_note']) ?>"</div>
                                                </div>
                                                <div style="font-size:11px; color:#9ca3af;">Waiting for the uploader to resubmit a new
                                                    proof.</div>
                                            <?php else: ?>
                                                <?php if (strpos($dpProofAcct['file_type'] ?? '', 'image') !== false): ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($dpProofAcct['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                    <img src="<?= BASE_URL ?><?= htmlspecialchars($dpProofAcct['file_path']) ?>"
                                                        style="max-width:100%; max-height:200px; border-radius:8px; margin-bottom:10px; object-fit:contain; cursor:pointer;">
                                                    </a>
                                                <?php else: ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($dpProofAcct['file_path']) ?>" target="_blank"
                                                        class="btn btn-sm btn-gray" style="margin-bottom:10px;">
                                                        <i class="fas fa-file"></i> View Proof File
                                                    </a>
                                                <?php endif; ?>
                                                <div class="btn-row">
                                                    <button class="btn btn-sm btn-gray" onclick="openRejectModal(<?= $dpRow['id'] ?>)">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                    <button class="btn btn-sm btn-green" onclick="approvePayment(<?= $dpRow['id'] ?>)">
                                                        <i class="fas fa-check"></i> Approve & Mark Paid
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif;
                                } ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Overall Progress -->
                    <div class="prog-banner">
                        <div class="prog-top">
                            <div class="prog-title"><i class="fas fa-chart-line"></i> Overall Installation Progress</div>
                            <div class="prog-stat"><?= $grand_done ?> / <?= $grand_total ?> items done</div>
                        </div>
                        <div class="prog-bar-bg">
                            <div class="prog-bar-fill" style="width:<?= min(100, $overall_pct) ?>%;"></div>
                        </div>
                        <div class="prog-bottom">
                            <span><?= $overall_pct ?>% complete</span>
                            <span>
                                Last billed at: <?= round($last_snapshot_pct, 1) ?>%
                                &nbsp;&bull;&nbsp;
                                Unbilled progress: <strong
                                    style="color:#059669;"><?= max(0, round($overall_pct - $last_snapshot_pct, 1)) ?>%</strong>
                            </span>
                        </div>
                    </div>

                    <!-- Collection Billings -->
                    <div class="card">
                        <div class="card-hdr">
                            <h2><i class="fas fa-file-invoice-dollar" style="color:#10b981;"></i> Progress Billing
                                Collections</h2>
                            <span class="sub"><?= count($collections) ?>
                                collection<?= count($collections) != 1 ? 's' : '' ?> recorded</span>
                        </div>
                        <div class="card-body">

                            <!-- Existing collections -->
                            <?php if (empty($collections)): ?>
                                <div class="empty">
                                    <i class="fas fa-file-invoice"></i>
                                    <p>No billing collections yet. Add the first one below.</p>
                                </div>
                            <?php else: ?>
                                <div class="coll-list" style="margin-bottom:20px;">
                                    <?php foreach ($collections as $idx => $coll):
                                        $cCls = strtolower($coll['status']);
                                        $cNo = $idx + 1;
                                        ?>
                                        <div class="coll-item <?= $cCls ?>">
                                            <div class="coll-top <?= $cCls ?>">
                                                <div class="coll-name">
                                                    <span class="coll-num"><?= $cNo ?></span>
                                                    <?= htmlspecialchars($coll['payment_type']) ?>
                                                    <?php if (!empty($coll['snapshot_pct'])): ?>
                                                        <span class="snap-pill">
                                                            <i class="fas fa-camera"></i>
                                                            <?= number_format((float) $coll['snapshot_pct'], 1) ?>% at billing
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="badge badge-<?= $cCls ?>"><?= $coll['status'] ?></span>
                                            </div>
                                            <div class="coll-bot">
                                                <div>
                                                    <div class="coll-amount">&#8369;<?= number_format($coll['amount'], 2) ?></div>
                                                    <div class="coll-meta">
                                                        <?= number_format((float) $coll['percentage'], 2) ?>% of project
                                                        <?php if ($coll['payment_date']): ?>
                                                            &bull; <i class="fas fa-check-circle" style="color:#10b981;"></i>
                                                            Paid <?= date('M d, Y', strtotime($coll['payment_date'])) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                                    <?php if ($coll['status'] !== 'Paid' && !$view_only && $canApprovePayment):
                                                        $collProof = getPaymentProofInfo($conn, $coll['id']);
                                                        $collAcctStatus = $coll['accounting_status'] ?? 'not_submitted';
                                                        ?>
                                                        <button class="btn btn-sm btn-outline"
                                                            onclick="openEditModal(<?= $coll['id'] ?>, <?= $coll['amount'] ?>, '<?= addslashes($coll['payment_type']) ?>')">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                    <?php elseif ($coll['status'] !== 'Paid' && $view_only): ?>
                                                    <?php else: ?>
                                                        <span style="font-size:12px;color:#059669;font-weight:700;">
                                                            <i class="fas fa-check-double"></i> Paid
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if ($coll['status'] === 'Paid'):
                                                $paidProof = getPaymentProofInfo($conn, $coll['id']);
                                                $collNTP = getNTPInfo($conn, $coll['id']);
                                                if ($paidProof): ?>
                                                    <div class="proof-box approved" style="margin:0 17px 13px;">
                                                        <div class="proof-title"><i class="fas fa-history"></i> Payment Proof (Approved)
                                                        </div>
                                                        <div style="font-size:11px;color:#065f46;margin-bottom:8px;">
                                                            <i class="fas fa-check-circle"></i> Reviewed by:
                                                            <?= htmlspecialchars($paidProof['reviewer_name'] ?? 'Accounting') ?>
                                                        </div>
                                                        <?php if (strpos($paidProof['file_type'] ?? '', 'image') !== false): ?>
                                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($paidProof['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                            <img src="<?= BASE_URL ?><?= htmlspecialchars($paidProof['file_path']) ?>"
                                                                style="max-width:100%; max-height:180px; border-radius:8px; object-fit:contain; cursor:pointer;">
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($paidProof['file_path']) ?>" target="_blank"
                                                                class="btn btn-sm btn-gray" style="margin-top:6px;">
                                                                <i class="fas fa-file"></i> View Proof File
                                                            </a>
                                                        <?php endif; ?>
                                                        <div class="proof-filename" style="margin-top:6px;">
                                                            <i class="fas fa-paperclip"></i>
                                                            <?= htmlspecialchars($paidProof['file_name']) ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($collNTP): ?>
                                                    <div
                                                        style="margin:0 17px 13px; background:#f0f9ff; border:2px solid #7dd3fc; border-radius:10px; padding:14px 16px;">
                                                        <div
                                                            style="font-size:12px; font-weight:700; color:#0369a1; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                                                            <i class="fas fa-file-signature"></i> Notice to Proceed (NTP) Issued
                                                        </div>
                                                        <div style="font-size:11px; color:#0369a1; margin-bottom:8px;">
                                                            <i class="fas fa-user"></i> Uploaded by:
                                                            <?= htmlspecialchars($collNTP['uploader_name'] ?? 'Accounting') ?>
                                                            &bull; <?= date('M d, Y g:i A', strtotime($collNTP['uploaded_at'])) ?>
                                                        </div>
                                                        <?php if (!empty($collNTP['notes'])): ?>
                                                            <div
                                                                style="font-size:11px; color:#374151; background:#e0f2fe; border-radius:6px; padding:7px 10px; margin-bottom:8px;">
                                                                <i class="fas fa-sticky-note"></i> <?= htmlspecialchars($collNTP['notes']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (strpos($collNTP['file_type'] ?? '', 'image') !== false): ?>
                                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($collNTP['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                            <img src="<?= BASE_URL ?><?= htmlspecialchars($collNTP['file_path']) ?>"
                                                                style="max-width:100%; max-height:180px; border-radius:8px; object-fit:contain; cursor:pointer;">
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($collNTP['file_path']) ?>" target="_blank"
                                                                class="btn btn-sm btn-gray" style="margin-top:6px;">
                                                                <i class="fas fa-file"></i> View NTP File
                                                            </a>
                                                        <?php endif; ?>
                                                        <div class="proof-filename" style="margin-top:6px;">
                                                            <i class="fas fa-paperclip"></i> <?= htmlspecialchars($collNTP['file_name']) ?>
                                                        </div>
                                                        <?php if ($canApprovePayment): ?>
                                                        <div class="btn-row" style="margin-top:10px;">
                                                            <button class="btn btn-sm btn-outline" onclick="openUpdateNTP(<?= $coll['id'] ?>)">
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
                                                ?>
                                                <div class="proof-box <?= $collAcctStatus2 === 'rejected' ? 'rejected' : ($collAcctStatus2 === 'pending_review' ? 'pending' : '') ?>"
                                                    style="margin:0 17px 13px;">
                                                    <?php if ($collAcctStatus2 === 'rejected'): ?>
                                                        <div class="proof-title rejected"><i class="fas fa-times-circle"></i> Proof rejected
                                                            — resubmit</div>
                                                        <div style="font-size:11px;color:#b91c1c;margin-bottom:8px;font-style:italic;">
                                                            "<?= htmlspecialchars($collProof2['rejection_note'] ?? '') ?>"
                                                        </div>
                                                    <?php elseif ($collAcctStatus2 === 'pending_review'): ?>
                                                        <div class="proof-title pending"><i class="fas fa-clock"></i> Awaiting accounting
                                                            review</div>
                                                    <?php else: ?>
                                                        <div class="proof-title"><i class="fas fa-upload"></i> Attach Proof of Payment</div>
                                                    <?php endif; ?>
                                                    <?php if ($collAcctStatus2 !== 'pending_review' && $canSubmitProof): ?>
                                                        <input type="file" class="proof-input" id="proofFile_<?= $coll['id'] ?>"
                                                            accept="image/*,.pdf,.doc,.docx"
                                                            onchange="previewProof(this, <?= $coll['id'] ?>)">
                                                        <img id="proofImg_<?= $coll['id'] ?>" class="proof-preview">
                                                        <div class="btn-row" style="margin-top:8px;">
                                                            <button class="btn btn-sm btn-green"
                                                                onclick="submitProof(<?= $coll['id'] ?>, <?= $client_id ?>)">
                                                                <i class="fas fa-paper-plane"></i> Submit for Review
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($canApprovePayment && $collAcctStatus2 === 'pending_review' && $collProof2): ?>
                                                        <div style="margin-top:10px; padding-top:10px; border-top:1px dashed #fde68a;">
                                                            <div style="font-weight:700; font-size:12px; color:#92400e; margin-bottom:8px;">
                                                                Accounting Review</div>
                                                            <?php if (strpos($collProof2['file_type'] ?? '', 'image') !== false): ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($collProof2['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                    <img src="<?= BASE_URL ?><?= htmlspecialchars($collProof2['file_path']) ?>"
                                                        style="max-width:100%; max-height:160px; border-radius:7px; margin-bottom:8px; object-fit:contain; cursor:pointer;">
                                                    </a>
                                                            <?php else: ?>
                                                                <a href="<?= BASE_URL ?><?= htmlspecialchars($collProof2['file_path']) ?>"
                                                                    target="_blank" class="btn btn-sm btn-gray" style="margin-bottom:8px;">
                                                                    <i class="fas fa-file"></i> View File
                                                                </a>
                                                            <?php endif; ?>
                                                            <div class="btn-row">
                                                <button class="btn btn-sm btn-gray"
                                                    onclick="openRejectModal(<?= $coll['id'] ?>)">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                                <button class="btn btn-sm btn-green"
                                                    onclick="quickApprove(<?= $coll['id'] ?>)">
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
                            // Hide new collection form if there's already a pending (unpaid) collection
                            $hasPendingCollection = false;
                            foreach ($collections as $c) {
                                if ($c['status'] !== 'Paid') {
                                    $hasPendingCollection = true;
                                    break;
                                }
                            }
                            ?>
                            <?php if (!$view_only && $isAccountingRole && $remaining_balance > 0 && !$hasPendingCollection): ?>
                                <div class="add-form">
                                    <div class="add-form-title">
                                        <i class="fas fa-plus-circle"></i> New Collection — #<?= $next_no ?>
                                    </div>
                                    <div class="add-form-sub">
                                        The system suggests an amount based on unbilled progress. You can enter any amount.
                                    </div>

                                    <!-- Suggestion breakdown -->
                                    <div class="sug-box">
                                        <div class="sug-line">
                                            <span class="sug-key"><i class="fas fa-chart-bar" style="color:#10b981;"></i>
                                                Current overall progress</span>
                                            <span class="sug-v"><?= $overall_pct ?>%</span>
                                        </div>
                                        <div class="sug-line">
                                            <span class="sug-key"><i class="fas fa-minus-circle" style="color:#9ca3af;"></i>
                                                Last billed progress snapshot</span>
                                            <span class="sug-v"><?= round($last_snapshot_pct, 2) ?>%</span>
                                        </div>
                                        <hr class="sug-divider">
                                        <div class="sug-line">
                                            <span class="sug-key" style="color:#059669;font-weight:700;">
                                                <i class="fas fa-lightbulb" style="color:#f59e0b;"></i> Suggested billing amount
                                            </span>
                                            <span class="sug-big">&#8369;<?= number_format($suggested_amount, 2) ?></span>
                                        </div>
                                        <div class="sug-formula">
                                            <?php if ($overall_pct >= 100): ?>
                                                All work is 100% complete — suggesting full remaining balance.
                                                <br>&#8369;<?= number_format($remaining_balance, 2) ?> (remaining balance)
                                                = <strong>&#8369;<?= number_format($suggested_amount, 2) ?></strong>
                                            <?php else: ?>
                                                Formula: (<?= $overall_pct ?>% &minus; <?= round($last_snapshot_pct, 2) ?>% last
                                                snapshot)
                                                &times; &#8369;<?= number_format($remaining_balance, 2) ?> (remaining balance)
                                                = <strong>&#8369;<?= number_format($suggested_amount, 2) ?></strong>
                                            <?php endif; ?>
                                            <br>You can override this with any amount below.
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label><i class="fas fa-tag"></i> Billing Label</label>
                                        <input type="text" id="newCollLabel" class="form-input"
                                            value="<?= htmlspecialchars($default_label) ?>"
                                            placeholder="e.g. 1st Billing Collection">
                                    </div>
                                    <div class="form-group">
                                        <label><i class="fas fa-peso-sign"></i> Amount to Bill (&#8369;) <span
                                                style="color:#ef4444;">*</span></label>
                                        <input type="number" id="newCollAmount" class="form-input"
                                            value="<?= $suggested_amount ?>" min="0" step="0.01" oninput="onAmountInput(this)">
                                        <div id="overrideHint" class="hint"></div>
                                    </div>

                                    <div id="addCollErr" class="modal-err" style="margin-bottom:12px;"></div>

                                    <div class="btn-row">
                                        <button class="btn btn-sm btn-gray" onclick="resetSuggested()">
                                            <i class="fas fa-undo"></i> Reset to Suggested
                                        </button>
                                        <button class="btn btn-green" id="addCollBtn" onclick="submitNewCollection()">
                                            <i class="fas fa-save"></i> Save Billing Entry
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>

                </div><!-- /left -->

                <!-- RIGHT — Area Breakdown Sidebar -->
                <div>
                    <div class="sidebar-card">
                        <div class="sb-hdr">
                            <h3><i class="fas fa-map-marker-alt"></i> Area Breakdown</h3>
                        </div>
                        <div class="sb-body">
                            <?php if (empty($areaMap)): ?>
                                <div style="text-align:center;padding:20px;color:#9ca3af;font-size:13px;">No areas found.</div>
                            <?php else: ?>
                                <?php foreach ($areaMap as $aName => $aData):
                                    $aPct = $aData['total'] > 0 ? round($aData['done'] / $aData['total'] * 100) : 0;
                                    $aColor = ($aPct === 100) ? '#10b981' : ($aPct > 0 ? '#3b82f6' : '#9ca3af');
                                    ?>
                                    <div class="area-row">
                                        <div class="area-top">
                                            <span class="area-name"><?= htmlspecialchars($aName) ?></span>
                                            <span class="area-pct" style="color:<?= $aColor ?>;"><?= $aPct ?>%</span>
                                        </div>
                                        <div class="area-count"><?= $aData['done'] ?>/<?= $aData['total'] ?> items done</div>
                                        <div class="mini-bar-bg">
                                            <div class="mini-bar-fill" style="width:<?= $aPct ?>%;background:<?= $aColor ?>;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div><!-- /right -->

            </div><!-- /two-col -->

        <?php else: ?>
            <!-- ════════════════════════════════════ NON-PROJECT ════════════════════════════════════ -->

            <?php if ($isAssignedToClient && !$view_only): ?>
                <div class="card" style="margin-bottom:16px;">
                    <div class="card-body" style="padding:16px 22px;">
                        <?php if ($isSplitLocked): ?>
                            <div style="display:flex;align-items:center;gap:10px;font-size:13px;color:#6b7280;">
                                <i class="fas fa-lock" style="color:#9ca3af;"></i>
                                Payment split is locked (<?= $payment_split_mode === 'merged' ? '50% Retention' : '40%/10% split' ?> already paid).
                            </div>
                        <?php elseif ($payment_split_mode === 'merged'): ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                                <div style="font-size:13px;color:#374151;">
                                    <i class="fas fa-info-circle" style="color:#3b82f6;"></i>
                                    Currently using <strong>50% Retention</strong> split.
                                </div>
                                <button class="btn btn-sm btn-outline" onclick="openToggleConfirm('revert')">
                                    <i class="fas fa-undo"></i> Revert to 40% / 10% split
                                </button>
                            </div>
                        <?php else: ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                                <div style="font-size:13px;color:#374151;">
                                    <i class="fas fa-info-circle" style="color:#3b82f6;"></i>
                                    Currently using <strong>40% Before / 10% After</strong> split.
                                </div>
                                <button class="btn btn-sm btn-outline" onclick="openToggleConfirm('merge')">
                                    <i class="fas fa-random"></i> Switch to 50% Retention
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-hdr">
                    <h2><i class="fas fa-calendar-check" style="color:#059669;"></i> Payment Schedule</h2>
                    <span class="sub"><?= $payment_split_mode === 'merged' ? '50% Down &bull; 50% Retention' : '50% Down &bull; 40% Before Installation &bull; 10% After Installation' ?></span>
                </div>
                <div class="card-body">
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
                        <div class="pay-item status-<?= $st ?>">
                            <div class="pay-hdr">
                                <span class="pay-type">
                                    <?= htmlspecialchars($payment['payment_type']) ?>
                                    <span
                                        style="color:#999;font-size:12px;font-weight:400;">(<?= number_format($payment['percentage'], 1) ?>%)</span>
                                </span>
                                <span class="pay-amt">&#8369;<?= number_format($payment['amount'], 2) ?></span>
                            </div>
                            <div class="pay-meta">
                                <span
                                    class="badge badge-<?= str_replace(' ', '-', strtolower($payment['status'])) ?>"><?= $payment['status'] ?></span>
                                <?php if (!$canMark && $payment['status'] !== 'Paid'): ?>
                                    <span style="font-size:11px;color:#f59e0b;"><i class="fas fa-info-circle"></i>
                                        <?= $disableMsg ?></span>
                                <?php endif; ?>
                                <?php if ($payment['payment_date']): ?>
                                    <span><i class="fas fa-check-circle" style="color:#10b981;"></i> Paid:
                                        <?= date('M d, Y g:i A', strtotime($payment['payment_date'])) ?></span>
                                <?php endif; ?>
                                <div style="margin-left:auto;">
                                    <?php if ($payment['status'] !== 'Paid' && $payment['status'] !== 'Not Available' && !$view_only): ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($payment['status'] === 'Paid'):
                                $paidProofNP = getPaymentProofInfo($conn, $payment['id']);
                                $npNTP = getNTPInfo($conn, $payment['id']);
                                if ($paidProofNP): ?>
                                    <div class="proof-box approved" style="margin-top:10px;">
                                        <div class="proof-title"><i class="fas fa-history"></i> Payment Proof (Approved)</div>
                                        <div style="font-size:11px;color:#065f46;margin-bottom:8px;">
                                            <i class="fas fa-check-circle"></i> Reviewed by:
                                            <?= htmlspecialchars($paidProofNP['reviewer_name'] ?? 'Accounting') ?>
                                        </div>
                                        <?php if (strpos($paidProofNP['file_type'] ?? '', 'image') !== false): ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($paidProofNP['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                            <img src="<?= BASE_URL ?><?= htmlspecialchars($paidProofNP['file_path']) ?>"
                                                style="max-width:100%; max-height:180px; border-radius:8px; object-fit:contain; cursor:pointer;">
                                            </a>
                                        <?php else: ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($paidProofNP['file_path']) ?>" target="_blank"
                                                class="btn btn-sm btn-gray" style="margin-top:6px;">
                                                <i class="fas fa-file"></i> View Proof File
                                            </a>
                                        <?php endif; ?>
                                        <div class="proof-filename" style="margin-top:6px;">
                                            <i class="fas fa-paperclip"></i> <?= htmlspecialchars($paidProofNP['file_name']) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($npNTP): ?>
                                    <div
                                        style="margin-top:10px; background:#f0f9ff; border:2px solid #7dd3fc; border-radius:10px; padding:14px 16px;">
                                        <div
                                            style="font-size:12px; font-weight:700; color:#0369a1; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                                            <i class="fas fa-file-signature"></i> Notice to Proceed (NTP) Issued
                                        </div>
                                        <div style="font-size:11px; color:#0369a1; margin-bottom:8px;">
                                            <i class="fas fa-user"></i> Uploaded by:
                                            <?= htmlspecialchars($npNTP['uploader_name'] ?? 'Accounting') ?>
                                            &bull; <?= date('M d, Y g:i A', strtotime($npNTP['uploaded_at'])) ?>
                                        </div>
                                        <?php if (!empty($npNTP['notes'])): ?>
                                            <div
                                                style="font-size:11px; color:#374151; background:#e0f2fe; border-radius:6px; padding:7px 10px; margin-bottom:8px;">
                                                <i class="fas fa-sticky-note"></i> <?= htmlspecialchars($npNTP['notes']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (strpos($npNTP['file_type'] ?? '', 'image') !== false): ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($npNTP['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                            <img src="<?= BASE_URL ?><?= htmlspecialchars($npNTP['file_path']) ?>"
                                                style="max-width:100%; max-height:180px; border-radius:8px; object-fit:contain; cursor:pointer;">
                                            </a>
                                        <?php else: ?>
                                            <a href="<?= BASE_URL ?><?= htmlspecialchars($npNTP['file_path']) ?>" target="_blank"
                                                class="btn btn-sm btn-gray" style="margin-top:6px;">
                                                <i class="fas fa-file"></i> View NTP File
                                            </a>
                                        <?php endif; ?>
                                        <div class="proof-filename" style="margin-top:6px;">
                                            <i class="fas fa-paperclip"></i> <?= htmlspecialchars($npNTP['file_name']) ?>
                                        </div>
                                        <?php if ($canApprovePayment): ?>
                                        <div class="btn-row" style="margin-top:10px;">
                                            <button class="btn btn-sm btn-outline" onclick="openUpdateNTP(<?= $payment['id'] ?>)">
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
                                ?>
                                <div class="proof-box <?= $npAcctStatus === 'rejected' ? 'rejected' : ($npAcctStatus === 'pending_review' ? 'pending' : '') ?>"
                                    style="margin-top:10px;">
                                    <?php if ($npAcctStatus === 'rejected' && $npProof): ?>
                                        <div class="proof-title rejected"><i class="fas fa-times-circle"></i> Proof rejected — resubmit
                                        </div>
                                        <div style="font-size:11px;color:#b91c1c;margin-bottom:8px;font-style:italic;">
                                            "<?= htmlspecialchars($npProof['rejection_note'] ?? '') ?>"
                                        </div>
                                    <?php elseif ($npAcctStatus === 'pending_review'): ?>
                                        <div class="proof-title pending"><i class="fas fa-clock"></i> Awaiting accounting review</div>
                                    <?php else: ?>
                                        <div class="proof-title"><i class="fas fa-upload"></i> Attach Proof of Payment</div>
                                    <?php endif; ?>
                                    <?php if ($npAcctStatus !== 'pending_review' && $canSubmitProof): ?>
                                        <input type="file" class="proof-input" id="proofFile_<?= $payment['id'] ?>"
                                            accept="image/*,.pdf,.doc,.docx" onchange="previewProof(this, <?= $payment['id'] ?>)">
                                        <img id="proofImg_<?= $payment['id'] ?>" class="proof-preview">
                                        <div class="btn-row" style="margin-top:8px;">
                                            <button class="btn btn-sm btn-green"
                                                onclick="submitProof(<?= $payment['id'] ?>, <?= $client_id ?>)">
                                                <i class="fas fa-paper-plane"></i> Submit for Review
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($canApprovePayment && in_array($npAcctStatus, ['pending_review', 'rejected']) && $npProof): ?>
                                        <div style="margin-top:10px; padding-top:10px; border-top:1px dashed #fde68a;">
                                            <div
                                                style="font-weight:700; font-size:12px; color:<?= $npAcctStatus === 'rejected' ? '#991b1b' : '#92400e' ?>; margin-bottom:8px;">
                                                <i class="fas fa-<?= $npAcctStatus === 'rejected' ? 'times-circle' : 'file-alt' ?>"></i>
                                                <?= $npAcctStatus === 'rejected' ? 'You rejected this proof' : 'Accounting Review' ?>
                                            </div>
                                            <?php if ($npAcctStatus === 'rejected' && !empty($npProof['rejection_note'])): ?>
                                                <div
                                                    style="background:#fee2e2; border:1px solid #fca5a5; border-radius:7px; padding:10px 12px; margin-bottom:10px;">
                                                    <div style="font-size:11px; font-weight:700; color:#991b1b; margin-bottom:3px;">Your
                                                        rejection note:</div>
                                                    <div style="font-size:12px; color:#b91c1c; font-style:italic;">
                                                        "<?= htmlspecialchars($npProof['rejection_note']) ?>"</div>
                                                </div>
                                                <div style="font-size:11px; color:#9ca3af;">Waiting for the uploader to resubmit a new
                                                    proof.</div>
                                            <?php else: ?>
                                                <?php if (strpos($npProof['file_type'] ?? '', 'image') !== false): ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($npProof['file_path']) ?>" onclick="openLightbox(this.href);return false;">
                                                    <img src="<?= BASE_URL ?><?= htmlspecialchars($npProof['file_path']) ?>"
                                                        style="max-width:100%; max-height:160px; border-radius:7px; margin-bottom:8px; object-fit:contain; cursor:pointer;">
                                                    </a>
                                                <?php else: ?>
                                                    <a href="<?= BASE_URL ?><?= htmlspecialchars($npProof['file_path']) ?>" target="_blank"
                                                        class="btn btn-sm btn-gray" style="margin-bottom:8px;">
                                                        <i class="fas fa-file"></i> View File
                                                    </a>
                                                <?php endif; ?>
                                                <div class="btn-row">
                                                    <button class="btn btn-sm btn-gray" onclick="openRejectModal(<?= $payment['id'] ?>)">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                    <?php if (stripos($payment['payment_type'], 'Down Payment') !== false): ?>
                                                        <button class="btn btn-sm btn-green" onclick="approvePayment(<?= $payment['id'] ?>)">
                                                            <i class="fas fa-check"></i> Approve & Upload NTP
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-green" onclick="quickApprove(<?= $payment['id'] ?>)">
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
        <span class="lightbox-close" onclick="closeLightbox()"><i class="fas fa-times"></i></span>
        <img id="lightboxImg" src="" onclick="event.stopPropagation()">
    </div>

    <!-- ══ Confirm Modal ══ -->
    <div id="confirmModal" class="modal-overlay">
        <div class="modal">
            <h3><i class="fas fa-check-circle" style="color:#10b981;"></i> Confirm Payment</h3>
            <p id="confirmMsg" class="modal-sub"></p>
            <input type="hidden" id="confirmId">
            <div class="modal-btns">
                <button class="btn btn-gray" onclick="closeConfirm()">Cancel</button>
                <button class="btn btn-green" onclick="doMarkPaid()">
                    <i class="fas fa-check"></i> Yes, Mark as Paid
                </button>
            </div>
        </div>
    </div>

    <!-- ══ NTP Upload Modal ══ -->
    <div id="ntpModal" class="modal-overlay">
        <div class="modal" style="max-width:500px;">
            <h3><i class="fas fa-file-signature" style="color:#059669;"></i> Upload Notice to Proceed (NTP)</h3>
            <p class="modal-sub">
                An NTP file is <strong style="color:#ef4444;">required</strong> before this payment can be approved.
                Please attach the NTP document below.
            </p>
            <input type="hidden" id="ntpPaymentId">
            <input type="hidden" id="ntpClientId">

            <div class="form-group">
                <label><i class="fas fa-paperclip"></i> NTP File <span style="color:#ef4444;">*</span></label>
                <input type="file" id="ntpFile" class="form-input" accept="image/*,.pdf,.doc,.docx"
                    style="padding:8px;cursor:pointer;" onchange="previewNTP(this)">
                <img id="ntpPreview"
                    style="max-width:100%;max-height:160px;border-radius:8px;margin-top:8px;display:none;object-fit:contain;">
            </div>

            <div class="form-group">
                <label><i class="fas fa-sticky-note"></i> Notes <span
                        style="color:#9ca3af;font-weight:400;">(optional)</span></label>
                <textarea id="ntpNotes" class="form-input" rows="2" placeholder="e.g. NTP issued on June 3, 2026..."
                    style="resize:vertical;"></textarea>
            </div>

            <div id="ntpErr" class="modal-err"></div>

            <div class="modal-btns">
                <button class="btn btn-gray" onclick="closeNTPModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="btn btn-green" onclick="doApproveWithNTP()">
                    <i class="fas fa-check"></i> Approve &amp; Upload NTP
                </button>
            </div>
        </div>
    </div>

    <!-- ══ Reject Proof Modal ══ -->
    <div id="rejectModal" class="modal-overlay">
        <div class="modal">
            <h3><i class="fas fa-times-circle" style="color:#ef4444;"></i> Reject Proof</h3>
            <p class="modal-sub">Please provide a reason so the submitter can resubmit correctly.</p>
            <input type="hidden" id="rejectPaymentId">
            <div class="form-group">
                <label>Rejection Reason <span style="color:#ef4444;">*</span></label>
                <textarea id="rejectNote" class="form-input" rows="3"
                    placeholder="e.g. Image is blurry, wrong receipt..." style="resize:vertical;"></textarea>
            </div>
            <div id="rejectErr" class="modal-err"></div>
            <div class="modal-btns">
                <button class="btn btn-gray" onclick="closeRejectModal()">Cancel</button>
                <button class="btn" style="background:#ef4444;color:white;" onclick="submitReject()">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
        </div>
    </div>

    <!-- ══ Edit Amount Modal ══ -->
    <div id="editModal" class="modal-overlay">
        <div class="modal">
            <h3><i class="fas fa-edit" style="color:#3b82f6;"></i> Edit Billing Amount</h3>
            <p id="editModalLabel" class="modal-sub"></p>
            <input type="hidden" id="editId">
            <div class="form-group">
                <label>New Amount (&#8369;)</label>
                <input type="number" id="editAmt" class="form-input" min="0" step="0.01">
            </div>
            <div id="editErr" class="modal-err"></div>
            <div class="modal-btns">
                <button class="btn btn-gray" onclick="closeEditModal()">Cancel</button>
                <button class="btn btn-green" onclick="submitEdit()">
                    <i class="fas fa-save"></i> Update Amount
                </button>
            </div>
        </div>
    </div>

    <!-- ══ Quick Approve Confirmation Modal ══ -->
    <div id="quickApproveModal" class="modal-overlay">
        <div class="modal">
            <h3><i class="fas fa-check-circle" style="color:#10b981;"></i> Confirm Approval</h3>
            <p class="modal-sub">
                Are you sure you want to approve this payment proof and mark it as
                <strong style="color:#059669;">Paid</strong>? This action cannot be undone.
            </p>
            <div class="modal-btns">
                <button class="btn btn-gray" onclick="closeQuickApproveModal()">Cancel</button>
                <button class="btn btn-green" onclick="doQuickApprove()">
                    <i class="fas fa-check"></i> Yes, Approve & Mark Paid
                </button>
            </div>
        </div>
    </div>

    <!-- ══ Toggle Payment Split Confirmation Modal ══ -->
    <div id="toggleConfirmModal" class="modal-overlay">
        <div class="modal">
            <h3><i class="fas fa-random" style="color:#3b82f6;"></i> Confirm Payment Split Change</h3>
            <p id="toggleConfirmMsg" class="modal-sub"></p>
            <div class="modal-btns">
                <button class="btn btn-gray" onclick="closeToggleConfirm()">Cancel</button>
                <button class="btn btn-green" onclick="doToggleSplit()">
                    <i class="fas fa-check"></i> Yes, Continue
                </button>
            </div>
        </div>
    </div>

    <!-- ══ Toast ══ -->
    <div id="toast" class="toast">
        <i id="toastIcon" class="fas fa-check-circle" style="font-size:17px;"></i>
        <span id="toastMsg"></span>
    </div>

    <script>
        const CLIENT_ID = <?= $client_id ?>;
        const TOTAL_COST = <?= $total_cost ?>;
        const REMAINING_BAL = <?= $remaining_balance ?>;
        const SUGGESTED = <?= isset($suggested_amount) ? $suggested_amount : 0 ?>;
        const SNAPSHOT_PCT = <?= isset($overall_pct) ? $overall_pct : 0 ?>;

        // ── Amount input override hint ──
        function onAmountInput(el) {
            const hint = document.getElementById('overrideHint');
            const v = parseFloat(el.value);
            if (!v || Math.abs(v - SUGGESTED) < 0.01) {
                hint.innerHTML = '';
                el.classList.remove('overridden');
            } else {
                el.classList.add('overridden');
                const diff = v - SUGGESTED;
                const sign = diff > 0 ? '+' : '';
                hint.innerHTML = '<span style="color:#f59e0b;font-weight:700;"><i class="fas fa-pen"></i> Overriding suggested amount (' + sign + '&#8369;' + Math.abs(diff).toLocaleString('en-PH', { minimumFractionDigits: 2 }) + ')</span>';
            }
        }
        function resetSuggested() {
            const el = document.getElementById('newCollAmount');
            el.value = SUGGESTED;
            el.classList.remove('overridden');
            document.getElementById('overrideHint').innerHTML = '';
        }

        // ── Add new collection ──
        async function submitNewCollection() {
            const label = document.getElementById('newCollLabel').value.trim();
            const amount = parseFloat(document.getElementById('newCollAmount').value);
            const errDiv = document.getElementById('addCollErr');
            errDiv.style.display = 'none';

            if (!label) { errDiv.textContent = 'Please enter a billing label.'; errDiv.style.display = 'block'; return; }
            if (!amount || amount <= 0) { errDiv.textContent = 'Please enter a valid amount greater than 0.'; errDiv.style.display = 'block'; return; }

            const btn = document.getElementById('addCollBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            try {
                const res = await fetch('<?= BASE_URL ?>add-collection-billing', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        client_id: CLIENT_ID,
                        label: label,
                        amount: amount,
                        total_cost: TOTAL_COST,
                        remaining_bal: REMAINING_BAL,
                        snapshot_pct: SNAPSHOT_PCT
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Billing collection saved!', 'success');
                    setTimeout(() => location.reload(), 1100);
                } else {
                    errDiv.textContent = data.error || 'Failed to save.';
                    errDiv.style.display = 'block';
                }
            } catch (e) {
                errDiv.textContent = 'Network error. Please try again.';
                errDiv.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Save Billing Entry';
            }
        }

        // ── Confirm / Mark as Paid ──
        function openConfirm(id, label, amount) {
            document.getElementById('confirmId').value = id;
            document.getElementById('confirmMsg').innerHTML =
                'Mark <strong>' + label + '</strong> (&#8369;' +
                parseFloat(amount).toLocaleString('en-PH', { minimumFractionDigits: 2 }) +
                ') as <strong style="color:#059669;">Paid</strong>?';
            document.getElementById('confirmModal').classList.add('open');
        }
        function closeConfirm() {
            document.getElementById('confirmModal').classList.remove('open');
        }
        async function doMarkPaid() {
            const id = document.getElementById('confirmId').value;
            closeConfirm();
            try {
                const res = await fetch('<?= BASE_URL ?>update-payment-status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ payment_id: parseInt(id), status: 'Paid', client_id: CLIENT_ID })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Payment marked as paid!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Failed: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (e) {
                showToast('Network error.', 'error');
            }
        }

        // ── Edit amount modal ──
        function openEditModal(id, amount, label) {
            document.getElementById('editId').value = id;
            document.getElementById('editAmt').value = amount;
            document.getElementById('editModalLabel').textContent = label;
            document.getElementById('editErr').style.display = 'none';
            document.getElementById('editModal').classList.add('open');
        }
        function closeEditModal() {
            document.getElementById('editModal').classList.remove('open');
        }
        async function submitEdit() {
            const id = document.getElementById('editId').value;
            const amount = parseFloat(document.getElementById('editAmt').value);
            const errDiv = document.getElementById('editErr');
            errDiv.style.display = 'none';
            if (!amount || amount <= 0) {
                errDiv.textContent = 'Please enter a valid amount.';
                errDiv.style.display = 'block';
                return;
            }
            try {
                const res = await fetch('<?= BASE_URL ?>update-accomplishment-amount', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ payment_id: parseInt(id), amount: amount, total_cost: TOTAL_COST })
                });
                const data = await res.json();
                if (data.success) {
                    closeEditModal();
                    showToast('Amount updated!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    errDiv.textContent = data.error || 'Update failed.';
                    errDiv.style.display = 'block';
                }
            } catch (e) {
                errDiv.textContent = 'Network error.';
                errDiv.style.display = 'block';
            }
        }

        // Close modals on backdrop click
        document.addEventListener('click', e => {
            if (e.target.id === 'confirmModal') closeConfirm();
            if (e.target.id === 'editModal') closeEditModal();
        });

        // ── Proof upload ──
        function previewProof(input, paymentId) {
            const img = document.getElementById('proofImg_' + paymentId);
            if (input.files && input.files[0]) {
                const file = input.files[0];
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = e => {
                        img.src = e.target.result;
                        img.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                } else {
                    img.style.display = 'none';
                }
            }
        }

        async function submitProof(paymentId, clientId) {
            const input = document.getElementById('proofFile_' + paymentId);
            if (!input || !input.files || !input.files[0]) {
                showToast('Please select a file first.', 'error');
                return;
            }
            const formData = new FormData();
            formData.append('payment_id', paymentId);
            formData.append('client_id', clientId);
            formData.append('proof_file', input.files[0]);

            try {
                const res = await fetch('<?= BASE_URL ?>upload-payment-proof', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showToast('Proof submitted! Awaiting accounting review.', 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    showToast(data.error || 'Upload failed.', 'error');
                }
            } catch (e) {
                showToast('Network error.', 'error');
            }
        }

        // ── Accounting approve/reject ──
        let pendingApprovePaymentId = null;
        let pendingApproveClientId = null;

        let ntpSubmitting = false; // guard flag
let ntpMode = 'approve'; // 'approve' or 'update'

// Quick approve WITHOUT NTP requirement (for collections & non-project payments)
let pendingQuickApproveId = null;

function quickApprove(paymentId) {
    pendingQuickApproveId = paymentId;
    document.getElementById('quickApproveModal').classList.add('open');
}

function closeQuickApproveModal() {
    document.getElementById('quickApproveModal').classList.remove('open');
    pendingQuickApproveId = null;
    const btn = document.querySelector('#quickApproveModal .btn-green');
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Yes, Approve & Mark Paid';
    }
}

async function doQuickApprove() {
    const paymentId = pendingQuickApproveId;
    if (!paymentId) return;

    const btn = document.querySelector('#quickApproveModal .btn-green');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Approving...'; }

    try {
        const res = await fetch('<?= BASE_URL ?>check-ipo-approved?client_id=' + CLIENT_ID);

        if (!res.ok) {
            throw new Error('Server returned HTTP ' + res.status);
        }

        const data = await res.json();

        if (!data.approved) {
            showToast('Cannot approve: "Internal P.O to Accounting" stage must be fully approved first.', 'error');
            closeQuickApproveModal();
            return;
        }
    } catch (e) {
        console.error('IPO verification failed:', e);
        showToast('Could not verify Internal P.O status — please refresh and try again.', 'error');
        closeQuickApproveModal();
        return;
    }

    try {
        const res = await fetch('<?= BASE_URL ?>review-payment-proof', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ payment_id: paymentId, action: 'approve' })
        });
        const data = await res.json();
        if (data.success) {
            closeQuickApproveModal();
            showToast('Payment approved and marked paid!', 'success');
            setTimeout(() => location.reload(), 1100);
        } else {
            showToast('Failed: ' + (data.error || 'Unknown error'), 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Yes, Approve & Mark Paid'; }
        }
    } catch (e) {
        showToast('Network error.', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Yes, Approve & Mark Paid'; }
    }
}

let pendingToggleAction = null;

function openToggleConfirm(action) {
    pendingToggleAction = action;
    const msg = document.getElementById('toggleConfirmMsg');
    if (action === 'merge') {
        msg.innerHTML = 'Switch this client\'s remaining balance to a single <strong>50% Retention</strong> payment? The current 40% Before Installation and 10% After Installation stages will be merged.';
    } else {
        msg.innerHTML = 'Revert this client back to the <strong>40% Before Installation / 10% After Installation</strong> split?';
    }
    document.getElementById('toggleConfirmModal').classList.add('open');
}

function closeToggleConfirm() {
    document.getElementById('toggleConfirmModal').classList.remove('open');
    pendingToggleAction = null;
}

async function doToggleSplit() {
    if (!pendingToggleAction) return;
    const btn = document.querySelector('#toggleConfirmModal .btn-green');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...'; }

    try {
        const res = await fetch('<?= BASE_URL ?>toggle-payment-split', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ client_id: CLIENT_ID, action: pendingToggleAction })
        });
        const data = await res.json();
        if (data.success) {
            closeToggleConfirm();
            showToast('Payment split updated!', 'success');
            setTimeout(() => location.reload(), 1100);
        } else {
            showToast(data.error || 'Failed to update split.', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Yes, Continue'; }
        }
    } catch (e) {
        showToast('Network error.', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Yes, Continue'; }
    }
}

async function approvePayment(paymentId) {
    try {
        const res = await fetch('<?= BASE_URL ?>check-ipo-approved?client_id=' + CLIENT_ID);

        if (!res.ok) {
            throw new Error('Server returned HTTP ' + res.status);
        }

        const data = await res.json();

        if (!data.approved) {
            showToast('Cannot approve: "Internal P.O to Accounting" stage must be fully approved first.', 'error');
            return;
        }
    } catch (e) {
        console.error('IPO verification failed:', e);
        showToast('Could not verify Internal P.O status — please refresh and try again.', 'error');
        return;
    }

    ntpSubmitting = false;
    ntpMode = 'approve'; // set mode
    pendingApprovePaymentId = paymentId;
    pendingApproveClientId = CLIENT_ID;
    document.getElementById('ntpPaymentId').value = paymentId;
    document.getElementById('ntpClientId').value = CLIENT_ID;
    document.getElementById('ntpErr').style.display = 'none';
    document.getElementById('ntpFile').value = '';
    document.getElementById('ntpNotes').value = '';
    document.getElementById('ntpPreview').style.display = 'none';
    document.getElementById('ntpModal').classList.add('open');
}

        async function doApproveWithNTP() {
    if (ntpSubmitting) return; // prevent double-submit
    ntpSubmitting = true;

    const paymentId = pendingApprovePaymentId;
    const clientId = pendingApproveClientId;
    const errDiv = document.getElementById('ntpErr');
    errDiv.style.display = 'none';

    const btn = document.querySelector('#ntpModal .btn-green');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...'; }

    const fileInput = document.getElementById('ntpFile');
    if (!fileInput.files || !fileInput.files[0]) {
        errDiv.textContent = 'NTP file is required. Please attach the NTP document.';
        errDiv.style.display = 'block';
        ntpSubmitting = false;
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = ntpMode === 'update'
                ? '<i class="fas fa-upload"></i> Upload New NTP'
                : '<i class="fas fa-check"></i> Approve &amp; Upload NTP';
        }
        return;
    }

            // Step 1: Upload NTP first
            const notes = document.getElementById('ntpNotes').value.trim();
            const formData = new FormData();
            formData.append('payment_id', paymentId);
            formData.append('client_id', clientId);
            formData.append('notes', notes);
            formData.append('ntp_file', fileInput.files[0]);

            try {
                const res = await fetch('<?= BASE_URL ?>upload-ntp', { method: 'POST', body: formData });
                const data = await res.json();
                if (!data.success) {
                    errDiv.textContent = data.error || 'NTP upload failed. Please try again.';
            errDiv.style.display = 'block';
            ntpSubmitting = false;
            if (btn) { btn.disabled = false; btn.innerHTML = ntpMode === 'update' ? '<i class="fas fa-upload"></i> Upload New NTP' : '<i class="fas fa-check"></i> Approve &amp; Upload NTP'; }
            return;
                }
            } catch (e) {
                errDiv.textContent = 'Network error during NTP upload.';
            errDiv.style.display = 'block';
            ntpSubmitting = false;
            if (btn) { btn.disabled = false; btn.innerHTML = ntpMode === 'update' ? '<i class="fas fa-upload"></i> Upload New NTP' : '<i class="fas fa-check"></i> Approve &amp; Upload NTP'; }
            return;
            }

            // Step 2: Skip approval if this is just an NTP update
            if (ntpMode === 'update') {
                closeNTPModal();
                showToast('NTP updated successfully!', 'success');
                setTimeout(() => location.reload(), 1200);
                return;
            }

            try {
                const res = await fetch('<?= BASE_URL ?>review-payment-proof', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ payment_id: paymentId, action: 'approve' })
                });
                const data = await res.json();
                if (!data.success) {
                    errDiv.textContent = data.error || 'Approval failed after NTP upload.';
                errDiv.style.display = 'block';
                ntpSubmitting = false;
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Approve &amp; Upload NTP'; }
                return;
                }
            } catch (e) {
                errDiv.textContent = 'Network error during approval.';
                errDiv.style.display = 'block';
                ntpSubmitting = false;
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Approve &amp; Upload NTP'; }
                return;
            }

            closeNTPModal();
            showToast('Payment approved and NTP uploaded!', 'success');
            setTimeout(() => location.reload(), 1200);
        }

        function openUpdateNTP(paymentId) {
    ntpSubmitting = false;
    ntpMode = 'update'; // set mode
    pendingApprovePaymentId = paymentId;
    pendingApproveClientId = CLIENT_ID;
    document.getElementById('ntpPaymentId').value = paymentId;
    document.getElementById('ntpClientId').value = CLIENT_ID;
    document.getElementById('ntpErr').style.display = 'none';
    document.getElementById('ntpFile').value = '';
    document.getElementById('ntpNotes').value = '';
    document.getElementById('ntpPreview').style.display = 'none';

    // Change modal title/button for update mode
    document.querySelector('#ntpModal h3').innerHTML = '<i class="fas fa-sync-alt" style="color:#3b82f6;"></i> Update Notice to Proceed (NTP)';
    document.querySelector('#ntpModal .modal-sub').innerHTML = 'Upload a new NTP file to replace the current one.';
    document.querySelector('#ntpModal .btn-green').innerHTML = '<i class="fas fa-upload"></i> Upload New NTP';
    document.getElementById('ntpModal').classList.add('open');
}

        function closeNTPModal() {
            document.getElementById('ntpModal').classList.remove('open');
            pendingApprovePaymentId = null;
            pendingApproveClientId = null;
            ntpSubmitting = false;
            ntpMode = 'approve'; // reset mode
            // Reset modal title back to default
            document.querySelector('#ntpModal h3').innerHTML = '<i class="fas fa-file-signature" style="color:#059669;"></i> Upload Notice to Proceed (NTP)';
            document.querySelector('#ntpModal .modal-sub').innerHTML = 'An NTP file is <strong style="color:#ef4444;">required</strong> before this payment can be approved. Please attach the NTP document below.';
            document.querySelector('#ntpModal .btn-green').innerHTML = '<i class="fas fa-check"></i> Approve &amp; Upload NTP';
        }

        function openRejectModal(paymentId) {
            document.getElementById('rejectPaymentId').value = paymentId;
            document.getElementById('rejectNote').value = '';
            document.getElementById('rejectErr').style.display = 'none';
            document.getElementById('rejectModal').classList.add('open');
        }
        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('open');
        }
        async function submitReject() {
            const paymentId = document.getElementById('rejectPaymentId').value;
            const note = document.getElementById('rejectNote').value.trim();
            const errDiv = document.getElementById('rejectErr');
            errDiv.style.display = 'none';
            if (!note) {
                errDiv.textContent = 'Please enter a rejection reason.';
                errDiv.style.display = 'block';
                return;
            }
            try {
                const res = await fetch('<?= BASE_URL ?>review-payment-proof', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ payment_id: parseInt(paymentId), action: 'reject', rejection_note: note })
                });
                const data = await res.json();
                if (data.success) {
                    closeRejectModal();
                    showToast('Proof rejected. Submitter will be notified.', 'success');
                    setTimeout(() => location.reload(), 1100);
                } else {
                    errDiv.textContent = data.error || 'Failed.';
                    errDiv.style.display = 'block';
                }
            } catch (e) {
                errDiv.textContent = 'Network error.';
                errDiv.style.display = 'block';
            }
        }

        // Close modals on backdrop
        document.addEventListener('click', e => {
            if (e.target.id === 'rejectModal') closeRejectModal();
            if (e.target.id === 'ntpModal') closeNTPModal();
            if (e.target.id === 'quickApproveModal') closeQuickApproveModal();
            if (e.target.id === 'toggleConfirmModal') closeToggleConfirm();
        });

        function previewNTP(input) {
            const img = document.getElementById('ntpPreview');
            if (input.files && input.files[0] && input.files[0].type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = e => { img.src = e.target.result; img.style.display = 'block'; };
                reader.readAsDataURL(input.files[0]);
            } else {
                img.style.display = 'none';
            }
        }

        // ── Lightbox ──
        function openLightbox(src) {
            document.getElementById('lightboxImg').src = src;
            document.getElementById('lightboxOverlay').classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        function closeLightbox() {
            document.getElementById('lightboxOverlay').classList.remove('open');
            document.getElementById('lightboxImg').src = '';
            document.body.style.overflow = '';
        }
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            if (document.getElementById('lightboxOverlay').classList.contains('open')) { closeLightbox(); return; }
            if (document.getElementById('ntpModal').classList.contains('open')) { closeNTPModal(); return; }
            if (document.getElementById('rejectModal').classList.contains('open')) { closeRejectModal(); return; }
            if (document.getElementById('editModal').classList.contains('open')) { closeEditModal(); return; }
            if (document.getElementById('confirmModal').classList.contains('open')) { closeConfirm(); return; }
            if (document.getElementById('quickApproveModal').classList.contains('open')) { closeQuickApproveModal(); return; }
            if (document.getElementById('toggleConfirmModal').classList.contains('open')) { closeToggleConfirm(); return; }
        });

        // ── Toast ──
        function showToast(msg, type) {
            const t = document.getElementById('toast');
            const icon = document.getElementById('toastIcon');
            document.getElementById('toastMsg').textContent = msg;
            t.className = 'toast show ' + type;
            icon.style.color = type === 'success' ? '#10b981' : '#ef4444';
            icon.className = 'fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle');
            setTimeout(() => t.classList.remove('show'), 3000);
        }
    </script>
</body>

</html>