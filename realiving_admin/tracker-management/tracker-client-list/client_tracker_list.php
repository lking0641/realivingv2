<?php
// client_tracker_list.php
include $includes ['mainbody'];
require_role(['admin1', 'superadmin', 'sales', 'designer', 'technical_designer', 'project_coordinator']);

$admin_id = $_SESSION['admin_id'];

// Check user's role
$roleStmt = $conn->prepare("SELECT role, full_name FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();

// Fetch clients assigned to this admin
$stmt = $conn->prepare("
    SELECT 
        u.id,
        u.clientname,
        u.nameproject,
        u.reference_number,
        u.status,
        u.business_type,
        u.contact,
        u.email,
        u.address,
        u.created_at,
        u.account_status,
        a.full_name as admin_name,
        a.role as admin_role
    FROM user_info u
    LEFT JOIN account a ON u.accountaid_fk = a.id
    WHERE u.accountaid_fk = ?
      AND u.account_status != 'Finished'
    ORDER BY u.created_at DESC
");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();

// Fetch finished clients separately
$finishedStmt = $conn->prepare("
    SELECT 
        u.id,
        u.clientname,
        u.nameproject,
        u.reference_number,
        u.status,
        u.business_type,
        u.contact,
        u.created_at,
        u.account_status,
        a.full_name as admin_name,
        a.role as admin_role
    FROM user_info u
    LEFT JOIN account a ON u.accountaid_fk = a.id
    WHERE u.accountaid_fk = ?
      AND u.account_status = 'Finished'
    ORDER BY u.created_at DESC
");
$finishedStmt->bind_param("i", $admin_id);
$finishedStmt->execute();
$finishedResult = $finishedStmt->get_result();
$finishedClients = [];
while ($row = $finishedResult->fetch_assoc()) {
    $finishedClients[] = $row;
}

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

function getClientRejectedPaymentProofs($conn, $admin_id, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM payment_proofs pp
        JOIN payment_schedule ps ON ps.id = pp.payment_id
        JOIN payment_accounting_reviews par ON par.payment_id = pp.payment_id
        WHERE ps.client_id = ?
          AND pp.uploaded_by = ?
          AND par.review_status = 'rejected'
          AND ps.accounting_status = 'rejected'
    ");
    $stmt->bind_param("ii", $client_id, $admin_id);
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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Clients - Project Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f5f1ed;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-header {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            padding: 40px;
            border-radius: 16px;
            color: white;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.05)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .page-header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            opacity: 0.9;
            font-size: 16px;
            position: relative;
            z-index: 1;
        }

        .user-info-badge {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 10px 20px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-top: 15px;
            position: relative;
            z-index: 1;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (max-width: 600px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-card .stat-icon {
                width: 38px;
                height: 38px;
                font-size: 18px;
                margin-bottom: 10px;
            }

            .stat-card .stat-value {
                font-size: 22px;
            }

            .stat-card .stat-label {
                font-size: 11px;
            }
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #8a5a44;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .stat-card .stat-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            margin-bottom: 15px;
        }

        .stat-card .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .stat-card .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: #3b1f0f;
        }

        /* ── Filters section (matches all_clients_tracker_list) ── */
        .filters-section {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 15px;
        }

        @media (max-width: 600px) {
            .filters-grid {
                grid-template-columns: 1fr 1fr;
            }

            .filters-grid .filter-group:first-child {
                grid-column: 1 / -1;
            }
        }

        .filter-group label {
            display: block;
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #8a5a44;
        }

        /* ── Toggle buttons ── */
        .toggle-btn {
            background: white;
            border: 2px solid #e9ecef;
            padding: 8px 14px;
            border-radius: 8px;
            cursor: pointer;
            color: #666;
            font-size: 16px;
            transition: all 0.2s;
        }

        .toggle-btn.active {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            border-color: #3b1f0f;
            color: white;
        }

        /* ── Cards grid ── */
        .clients-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .client-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid transparent;
        }

        .client-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
            border-color: #8a5a44;
        }

        .client-card-header {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            padding: 20px;
            color: white;
        }

        .client-card-header h3 {
            font-size: 18px;
            margin-bottom: 5px;
        }

        .client-card-header .reference {
            font-size: 12px;
            opacity: 0.9;
            font-family: monospace;
        }

        .client-card-body {
            padding: 20px;
        }

        .client-info-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .client-info-row i {
            color: #8a5a44;
            width: 20px;
        }

        .client-info-row .label {
            color: #666;
            min-width: 100px;
        }

        .client-info-row .value {
            color: #111;
            font-weight: 500;
            flex: 1;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-new {
            background: #fef3c7;
            color: #92400e;
        }

        .status-old {
            background: #dbeafe;
            color: #1e40af;
        }

        .client-card-footer {
            padding: 15px 20px;
            background: #f9f9f9;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .view-tracker-btn {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .view-tracker-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .empty-state i {
            font-size: 64px;
            color: #d1d5db;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 20px;
            color: #666;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #999;
        }

        /* ── List view overrides (matches all_clients_tracker_list) ── */
        .clients-grid.list-view {
            grid-template-columns: 1fr !important;
            display: flex !important;
            flex-direction: column !important;
        }

        .clients-grid.list-view .client-card {
            display: flex !important;
            flex-direction: row !important;
            align-items: stretch;
            width: 100%;
            min-height: unset;
        }

        @media (max-width: 600px) {
            .clients-grid.list-view .client-card {
                flex-direction: column !important;
            }

            .clients-grid.list-view .client-card-header {
                min-width: unset !important;
                max-width: unset !important;
            }

            .clients-grid.list-view .client-card-footer {
                flex-direction: row !important;
                justify-content: space-between !important;
                min-width: unset !important;
                max-width: unset !important;
            }

            .page-header {
                padding: 24px 20px;
            }

            .page-header h1 {
                font-size: 22px;
            }

            .page-header p {
                font-size: 13px;
            }
        }

        .clients-grid.list-view .client-card-header {
            min-width: 260px;
            max-width: 260px;
            border-radius: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            flex-shrink: 0;
            overflow: visible;
        }

        .clients-grid.list-view .client-card-body {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: wrap;
            align-items: center;
            padding: 10px 20px;
            flex: 1;
            gap: 5px 20px;
        }

        .clients-grid.list-view .client-card-body .client-info-row {
            flex: 1 1 200px;
            margin-bottom: 0;
        }

        .clients-grid.list-view .client-card-footer {
            flex-direction: column !important;
            justify-content: center;
            align-items: center;
            gap: 10px;
            min-width: 140px;
            max-width: 140px;
            flex-shrink: 0;
        }

        /* Fix finished badge overflow in list view */
        .clients-grid.list-view .client-card-header h3 {
            font-size: 14px;
            word-break: break-word;
        }

        .clients-grid.list-view .client-card-header .reference {
            font-size: 10px;
            word-break: break-all;
        }

        .clients-grid.list-view .client-card-header>div {
            flex-wrap: nowrap;
            overflow: hidden;
        }

        .clients-grid.list-view .client-card-header>div>div:first-child {
            min-width: 0;
            overflow: hidden;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-users"></i> My Clients</h1>
            <p>Manage and track your assigned client projects</p>
            <div class="user-info-badge">
                <i class="fas fa-user-circle"></i>
                <span><?= htmlspecialchars($userInfo['full_name']) ?></span>
                <span style="opacity: 0.7;">•</span>
                <span style="text-transform: capitalize;"><?= str_replace('_', ' ', $userInfo['role']) ?></span>
            </div>
        </div>

        <?php
        $total_clients = $result->num_rows;
        $new_clients = 0;
        $active_projects = 0;

        // Get is_head for this user (for site visit rejection check)
        $isHeadUser = false;
        $headCheckStmt = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
        $headCheckStmt->bind_param("i", $admin_id);
        $headCheckStmt->execute();
        $headCheckRow = $headCheckStmt->get_result()->fetch_assoc();
        $isHeadUser = (bool) ($headCheckRow['is_head'] ?? false);
        $currentRole = $userInfo['role'];

        $clients = [];
        while ($row = $result->fetch_assoc()) {
            $row['rejected_site_visits'] = ($currentRole === 'designer' && $isHeadUser)
                ? getClientRejectedSiteVisits($conn, $row['id'])
                : 0;
            $row['rejected_uploads'] = getClientRejectedFilesForUploader($conn, $admin_id, $row['id']);
            $row['rejected_payment_proofs'] = getClientRejectedPaymentProofs($conn, $admin_id, $row['id']);
            $clients[] = $row;
            if ($row['status'] === 'New Client')
                $new_clients++;
            $active_projects++;
        }

        $finished_count = count($finishedClients);
        ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-label">Total Clients</div>
                <div class="stat-value"><?= $total_clients ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="stat-label">New Clients</div>
                <div class="stat-value"><?= $new_clients ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-label">Active Projects</div>
                <div class="stat-value"><?= $active_projects ?></div>
            </div>

            <div class="stat-card" style="border-left-color: #10b981;">
                <div class="stat-icon" style="background: linear-gradient(135deg,#065f46,#10b981);">
                    <i class="fas fa-check-double"></i>
                </div>
                <div class="stat-label">Finished</div>
                <div class="stat-value" style="color:#065f46;"><?= $finished_count ?></div>
            </div>
        </div>

        <!-- ── Filters (matches all_clients_tracker_list layout) ── -->
        <div class="filters-section">
            <!-- Active / Finished tabs -->
            <div style="display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap;">
                <button id="tabActive" onclick="setTab('active')"
                    style="padding:10px 24px; border-radius:25px; border:2px solid #3b1f0f; background:linear-gradient(135deg,#3b1f0f,#8a5a44); color:white; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:all .2s;">
                    <i class="fas fa-tasks"></i> Active
                    <span id="activeCount"
                        style="background:rgba(255,255,255,.25); border-radius:12px; padding:1px 8px; font-size:11px;"><?= count($clients) ?></span>
                </button>
                <button id="tabFinished" onclick="setTab('finished')"
                    style="padding:10px 24px; border-radius:25px; border:2px solid #e2d9ce; background:white; color:#5c4033; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:all .2s;">
                    <i class="fas fa-check-double"></i> Finished
                    <span id="finishedCount"
                        style="background:#e2d9ce; border-radius:12px; padding:1px 8px; font-size:11px;"><?= count($finishedClients) ?></span>
                </button>
            </div>
            <div class="filters-grid">
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" id="searchInput" placeholder="Search by client name, project, or reference...">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-filter"></i> Status</label>
                    <select id="statusFilter">
                        <option value="">All Statuses</option>
                        <option value="New Client">New Client</option>
                        <option value="Old Client">Old Client</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-building"></i> Business Type</label>
                    <select id="businessFilter">
                        <option value="">All Types</option>
                        <option value="Project">Project</option>
                        <option value="Non-Project">Individual</option>
                    </select>
                </div>
            </div>
            <div style="margin-top: 15px; display: flex; justify-content: flex-end; gap: 10px;">
                <button class="toggle-btn active" id="gridBtn" onclick="setView('grid')" title="Grid View">
                    <i class="fas fa-th"></i>
                </button>
                <button class="toggle-btn" id="listBtn" onclick="setView('list')" title="List View">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>

        <?php
        $totalRejectedSiteVisits = array_sum(array_column($clients, 'rejected_site_visits'));
        if ($totalRejectedSiteVisits > 0):
            ?>
            <div
                style="background:#fee2e2; border:2px solid #ef4444; border-radius:12px; padding:16px 20px; margin-bottom:20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-times-circle" style="color:#dc2626; font-size:22px; flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:700; font-size:15px; color:#991b1b;">
                            <?= $totalRejectedSiteVisits ?> site visit<?= $totalRejectedSiteVisits > 1 ? 's' : '' ?>
                            rejected across your clients
                        </div>
                        <div style="font-size:12px; color:#b91c1c; margin-top:3px;">
                            Look for the <strong>red badge</strong> on each client card below. Open the tracker to edit and
                            resubmit.
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php
        $totalRejectedPaymentProofs = array_sum(array_column($clients, 'rejected_payment_proofs'));
        if ($totalRejectedPaymentProofs > 0):
            ?>
            <div
                style="background:#fee2e2; border:2px solid #ef4444; border-radius:12px; padding:16px 20px; margin-bottom:20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-file-invoice-dollar" style="color:#dc2626; font-size:22px; flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:700; font-size:15px; color:#991b1b;">
                            <?= $totalRejectedPaymentProofs ?> payment
                            proof<?= $totalRejectedPaymentProofs > 1 ? 's' : '' ?> you submitted
                            <?= $totalRejectedPaymentProofs > 1 ? 'were' : 'was' ?> rejected
                        </div>
                        <div style="font-size:12px; color:#b91c1c; margin-top:3px;">
                            Look for the <strong>red badge</strong> on each client card below. Open the tracker to resubmit.
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php
        $totalRejectedUploads = array_sum(array_column($clients, 'rejected_uploads'));
        if ($totalRejectedUploads > 0):
            ?>
            <div
                style="background:#fee2e2; border:2px solid #ef4444; border-radius:12px; padding:16px 20px; margin-bottom:20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-times-circle" style="color:#dc2626; font-size:22px; flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:700; font-size:15px; color:#991b1b;">
                            <?= $totalRejectedUploads ?> file<?= $totalRejectedUploads > 1 ? 's' : '' ?> you submitted
                            <?= $totalRejectedUploads > 1 ? 'have' : 'has' ?> been rejected
                        </div>
                        <div style="font-size:12px; color:#b91c1c; margin-top:3px;">
                            Look for the <strong>red badge</strong> on each client card below. Open the tracker to
                            re-submit.
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="active-content">
            <?php if (empty($clients)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Clients Assigned</h3>
                    <p>You don't have any clients assigned to you yet.</p>
                </div>
            <?php else: ?>
                <div class="clients-grid" id="clientsGrid">
                    <?php foreach ($clients as $client): ?>
                        <div class="client-card"
                            data-search="<?= strtolower($client['clientname'] . ' ' . $client['nameproject'] . ' ' . $client['reference_number']) ?>"
                            data-status="<?= htmlspecialchars($client['status']) ?>"
                            data-business="<?= htmlspecialchars($client['business_type']) ?>"
                            onclick="viewTracker(<?= $client['id'] ?>)">

                            <div class="client-card-header"
                                style="<?= ($client['rejected_site_visits'] > 0 || $client['rejected_uploads'] > 0 || $client['rejected_payment_proofs'] > 0) ? 'background:linear-gradient(135deg,#991b1b,#ef4444);' : '' ?>">
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                                        <div style="min-width:0; flex:1;">
                                            <h3 style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                <?= htmlspecialchars($client['clientname']) ?>
                                            </h3>
                                            <div class="reference">
                                                <i class="fas fa-hashtag"></i>
                                                <?= htmlspecialchars($client['reference_number']) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($client['rejected_site_visits'] > 0 || $client['rejected_uploads'] > 0 || $client['rejected_payment_proofs'] > 0): ?>
                                        <div style="display:flex; flex-wrap:wrap; gap:5px;">
                                            <?php if ($client['rejected_site_visits'] > 0): ?>
                                                <div
                                                    style="background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.4); border-radius:20px; padding:3px 8px; display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; color:white;">
                                                    <i class="fas fa-times-circle"></i>
                                                    <span><?= $client['rejected_site_visits'] ?> rejected
                                                        visit<?= $client['rejected_site_visits'] > 1 ? 's' : '' ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($client['rejected_uploads'] > 0): ?>
                                                <div
                                                    style="background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.4); border-radius:20px; padding:3px 8px; display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; color:white;">
                                                    <i class="fas fa-file-times"></i>
                                                    <span><?= $client['rejected_uploads'] ?> rejected
                                                        file<?= $client['rejected_uploads'] > 1 ? 's' : '' ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($client['rejected_payment_proofs'] > 0): ?>
                                                <div
                                                    style="background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.4); border-radius:20px; padding:3px 8px; display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; color:white;">
                                                    <i class="fas fa-file-invoice-dollar"></i>
                                                    <span><?= $client['rejected_payment_proofs'] ?> rejected
                                                        proof<?= $client['rejected_payment_proofs'] > 1 ? 's' : '' ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="client-card-body">
                                <div class="client-info-row">
                                    <i class="fas fa-project-diagram"></i>
                                    <span class="label">Project:</span>
                                    <span class="value"><?= htmlspecialchars($client['nameproject']) ?></span>
                                </div>

                                <div class="client-info-row">
                                    <i class="fas fa-tag"></i>
                                    <span class="label">Status:</span>
                                    <span class="status-badge status-<?= $client['status'] === 'New Client' ? 'new' : 'old' ?>">
                                        <?= htmlspecialchars($client['status']) ?>
                                    </span>
                                </div>

                                <div class="client-info-row">
                                    <i class="fas fa-building"></i>
                                    <span class="label">Type:</span>
                                    <span class="value">
                                        <?= $client['business_type'] === 'Non-Project' ? 'Individual' : htmlspecialchars($client['business_type']) ?>
                                    </span>
                                </div>

                                <?php if ($client['contact']): ?>
                                    <div class="client-info-row">
                                        <i class="fas fa-phone"></i>
                                        <span class="label">Contact:</span>
                                        <span class="value"><?= htmlspecialchars($client['contact']) ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="client-info-row">
                                    <i class="fas fa-calendar"></i>
                                    <span class="label">Created:</span>
                                    <span class="value"><?= date('M d, Y', strtotime($client['created_at'])) ?></span>
                                </div>
                            </div>

                            <div class="client-card-footer">
                                <small style="color: #666;">
                                    <i class="fas fa-clock"></i>
                                    <?= date('g:i A', strtotime($client['created_at'])) ?>
                                </small>
                                <button class="view-tracker-btn"
                                    onclick="viewTracker(<?= $client['id'] ?>); event.stopPropagation();">
                                    <i class="fas fa-chart-line"></i>
                                    View Tracker
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div><!-- end active-content -->

        <!-- Finished clients grid (hidden by default) -->
        <div id="finishedGridWrapper" style="display:none;">
            <?php if (empty($finishedClients)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-double"></i>
                    <h3>No Finished Projects</h3>
                    <p>No projects have been marked as finished yet.</p>
                </div>
            <?php else: ?>
                <div class="clients-grid" id="finishedClientsGrid">
                    <?php foreach ($finishedClients as $client): ?>
                        <div class="client-card"
                            data-search="<?= strtolower($client['clientname'] . ' ' . $client['nameproject'] . ' ' . $client['reference_number']) ?>"
                            data-status="<?= htmlspecialchars($client['status']) ?>"
                            data-business="<?= htmlspecialchars($client['business_type']) ?>"
                            onclick="viewTracker(<?= $client['id'] ?>)" style="border:2px solid #6ee7b7;">
                            <div class="client-card-header" style="background:linear-gradient(135deg,#065f46,#10b981);">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
                                    <div>
                                        <h3><?= htmlspecialchars($client['clientname']) ?></h3>
                                        <div class="reference">
                                            <i class="fas fa-hashtag"></i> <?= htmlspecialchars($client['reference_number']) ?>
                                        </div>
                                    </div>
                                    <div
                                        style="background:rgba(255,255,255,.2); border:1px solid rgba(255,255,255,.4); border-radius:20px; padding:4px 10px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:5px; flex-shrink:0;">
                                        <i class="fas fa-check-double"></i> Finished
                                    </div>
                                </div>
                            </div>
                            <div class="client-card-body">
                                <div class="client-info-row">
                                    <i class="fas fa-project-diagram"></i>
                                    <span class="label">Project:</span>
                                    <span class="value"><?= htmlspecialchars($client['nameproject']) ?></span>
                                </div>
                                <div class="client-info-row">
                                    <i class="fas fa-tag"></i>
                                    <span class="label">Status:</span>
                                    <span class="status-badge status-<?= $client['status'] === 'New Client' ? 'new' : 'old' ?>">
                                        <?= htmlspecialchars($client['status']) ?>
                                    </span>
                                </div>
                                <div class="client-info-row">
                                    <i class="fas fa-building"></i>
                                    <span class="label">Type:</span>
                                    <span
                                        class="value"><?= $client['business_type'] === 'Non-Project' ? 'Individual' : htmlspecialchars($client['business_type']) ?></span>
                                </div>
                                <?php if ($client['contact']): ?>
                                    <div class="client-info-row">
                                        <i class="fas fa-phone"></i>
                                        <span class="label">Contact:</span>
                                        <span class="value"><?= htmlspecialchars($client['contact']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="client-info-row">
                                    <i class="fas fa-calendar"></i>
                                    <span class="label">Created:</span>
                                    <span class="value"><?= date('M d, Y', strtotime($client['created_at'])) ?></span>
                                </div>
                            </div>
                            <div class="client-card-footer">
                                <small style="color:#666;">
                                    <i class="fas fa-clock"></i>
                                    <?= date('g:i A', strtotime($client['created_at'])) ?>
                                </small>
                                <button class="view-tracker-btn" style="background:linear-gradient(135deg,#065f46,#10b981);"
                                    onclick="viewTracker(<?= $client['id'] ?>); event.stopPropagation();">
                                    <i class="fas fa-chart-line"></i> View Tracker
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
        let currentTab = 'active';

        function setTab(tab) {
            currentTab = tab;
            const finishedWrapper = document.getElementById('finishedGridWrapper');
            const tabActive = document.getElementById('tabActive');
            const tabFinished = document.getElementById('tabFinished');

            // Show/hide sections
            document.querySelectorAll('.active-content').forEach(el => {
                el.style.display = tab === 'active' ? '' : 'none';
            });
            if (finishedWrapper) finishedWrapper.style.display = tab === 'finished' ? '' : 'none';

            // Style tabs
            if (tab === 'active') {
                tabActive.style.background = 'linear-gradient(135deg,#3b1f0f,#8a5a44)';
                tabActive.style.color = 'white';
                tabActive.style.borderColor = '#3b1f0f';
                tabFinished.style.background = 'white';
                tabFinished.style.color = '#5c4033';
                tabFinished.style.borderColor = '#e2d9ce';
            } else {
                tabFinished.style.background = 'linear-gradient(135deg,#065f46,#10b981)';
                tabFinished.style.color = 'white';
                tabFinished.style.borderColor = '#065f46';
                tabActive.style.background = 'white';
                tabActive.style.color = '#5c4033';
                tabActive.style.borderColor = '#e2d9ce';
            }

            // Force show ALL cards in the target grid first, then apply filters
            const targetGridId = tab === 'active' ? 'clientsGrid' : 'finishedClientsGrid';
            const targetGrid = document.getElementById(targetGridId);
            if (targetGrid) {
                const isListView = targetGrid.classList.contains('list-view');
                targetGrid.querySelectorAll('.client-card').forEach(card => {
                    card.style.setProperty('display', isListView ? 'flex' : 'block', 'important');
                });
            }

            applyFilters();
        }

        function applyFilters() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            const businessFilter = document.getElementById('businessFilter').value;

            const gridId = currentTab === 'active' ? 'clientsGrid' : 'finishedClientsGrid';
            const grid = document.getElementById(gridId);
            if (!grid) return;

            const isListView = grid.classList.contains('list-view');
            const cards = grid.querySelectorAll('.client-card');

            cards.forEach(card => {
                const searchData = card.getAttribute('data-search');
                const cardStatus = card.getAttribute('data-status');
                const cardBusiness = card.getAttribute('data-business');

                const matchesSearch = searchData.includes(searchTerm);
                const matchesStatus = !statusFilter || cardStatus === statusFilter;
                const matchesBusiness = !businessFilter || cardBusiness === businessFilter;

                card.style.setProperty('display', (matchesSearch && matchesStatus && matchesBusiness) ? (isListView ? 'flex' : 'block') : 'none', 'important');
            });
        }

        document.getElementById('searchInput').addEventListener('input', applyFilters);
        document.getElementById('statusFilter').addEventListener('change', applyFilters);
        document.getElementById('businessFilter').addEventListener('change', applyFilters);

        function setView(type) {
            const grids = [document.getElementById('clientsGrid'), document.getElementById('finishedClientsGrid')];
            const gridBtn = document.getElementById('gridBtn');
            const listBtn = document.getElementById('listBtn');

            grids.forEach(grid => {
                if (!grid) return;
                if (type === 'list') {
                    grid.classList.add('list-view');
                } else {
                    grid.classList.remove('list-view');
                }
            });

            if (type === 'list') {
                listBtn.classList.add('active');
                gridBtn.classList.remove('active');
            } else {
                gridBtn.classList.add('active');
                listBtn.classList.remove('active');
            }
            applyFilters();
        }

        function viewTracker(clientId) {
            window.location.href = `<?= BASE_URL ?>unified-project-tracker?client_id=${clientId}`;
        }

        document.addEventListener('DOMContentLoaded', function () {
            setView('list');
            setTab('active');
        });
    </script>
</body>

</html>