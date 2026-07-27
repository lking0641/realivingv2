<?php
// designer_clients_list.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');


$admin_id = $_SESSION['admin_id'];

$meStmt = $conn->prepare("SELECT full_name, role FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

if ($me['role'] !== 'designer') {
    die("Access denied: This page is for designers only.");
}

// Fetch all unique clients assigned to this designer via site_visit
$clientsStmt = $conn->prepare("
    SELECT DISTINCT
        ui.id AS client_id,
        ui.clientname,
        ui.nameproject,
        ui.reference_number,
        ui.status,
        ui.business_type,
        ui.contact,
        ui.email,
        ui.address,
        ui.total_project_cost,
        ui.remaining_balance,
        (SELECT COUNT(*) FROM site_visit sv WHERE sv.client_id = ui.id AND (sv.designer1_id = ? OR sv.designer2_id = ? OR sv.original_designer1_id = ? OR sv.original_designer2_id = ?)) AS total_visits,
        (SELECT COUNT(*) FROM site_visit sv WHERE sv.client_id = ui.id AND (sv.designer1_id = ? OR sv.designer2_id = ? OR sv.original_designer1_id = ? OR sv.original_designer2_id = ?) AND sv.status = 'Done') AS done_visits,
        (SELECT COUNT(*) FROM site_visit sv WHERE sv.client_id = ui.id AND (sv.designer1_id = ? OR sv.designer2_id = ? OR sv.original_designer1_id = ? OR sv.original_designer2_id = ?) AND sv.status = 'Pending') AS pending_visits,
        (SELECT COUNT(*) FROM site_visit sv WHERE sv.client_id = ui.id AND (sv.designer1_id = ? OR sv.designer2_id = ? OR sv.original_designer1_id = ? OR sv.original_designer2_id = ?) AND sv.status = 'Ongoing') AS ongoing_visits
    FROM user_info ui
    JOIN site_visit sv ON sv.client_id = ui.id
    WHERE sv.designer1_id = ? OR sv.designer2_id = ?
    OR sv.original_designer1_id = ? OR sv.original_designer2_id = ?
    ORDER BY ui.clientname ASC
");
$clientsStmt->bind_param(
    "iiiiiiiiiiiiiiiiiiii",
    $admin_id, $admin_id, $admin_id, $admin_id,
    $admin_id, $admin_id, $admin_id, $admin_id,
    $admin_id, $admin_id, $admin_id, $admin_id,
    $admin_id, $admin_id, $admin_id, $admin_id,
    $admin_id, $admin_id, $admin_id, $admin_id
);
$clientsStmt->execute();
$clients = $clientsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totalClients = count($clients);
$totalPending = array_sum(array_column($clients, 'pending_visits'));
$totalOngoing = array_sum(array_column($clients, 'ongoing_visits'));
$totalDone    = count(array_filter($clients, fn($c) => $c['done_visits'] == $c['total_visits'] && $c['total_visits'] > 0));
$totalActive  = count(array_filter($clients, fn($c) => !($c['done_visits'] == $c['total_visits'] && $c['total_visits'] > 0)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Clients — Designer</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f1ed; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .container { max-width: 960px; margin: 30px auto; padding: 0 20px; }

        .page-header {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            padding: 30px 35px; border-radius: 16px; color: white; margin-bottom: 25px;
        }
        .page-header h1 { font-size: 24px; margin-bottom: 5px; }
        .page-header .sub { font-size: 13px; opacity: 0.85; margin-top: 4px; }

        .header-stats {
            display: flex; gap: 16px; margin-top: 18px; flex-wrap: wrap;
        }
        .h-stat {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 10px; padding: 12px 20px; text-align: center;
        }
        .h-stat-val { font-size: 24px; font-weight: 700; }
        .h-stat-label { font-size: 11px; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.4px; }

        /* Search */
        .search-bar {
            display: flex; align-items: center; gap: 12px;
            background: white; border-radius: 10px; padding: 10px 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.07); margin-bottom: 18px;
        }
        .search-bar i { color: #9ca3af; font-size: 15px; }
        .search-bar input {
            border: none; outline: none; font-size: 14px; color: #111;
            width: 100%; font-family: inherit;
        }

        /* Client Card */
        .client-card {
            background: white; border-radius: 12px; margin-bottom: 14px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.07);
            border-left: 5px solid #8a5a44;
            display: flex; justify-content: space-between; align-items: center;
            padding: 20px 24px; cursor: pointer;
            transition: all 0.2s; text-decoration: none;
        }
        .client-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        .client-card.has-ongoing { border-left-color: #3b82f6; }
        .client-card.has-pending { border-left-color: #f59e0b; }
        .client-card.all-done { border-left-color: #10b981; }

        .client-name { font-size: 16px; font-weight: 700; color: #1f2937; margin-bottom: 4px; }
        .client-sub { font-size: 12px; color: #9ca3af; }
        .client-meta { font-size: 12px; color: #6b7280; margin-top: 8px; display: flex; gap: 14px; flex-wrap: wrap; }
        .client-meta span { display: flex; align-items: center; gap: 5px; }

        .badge {
            padding: 3px 10px; border-radius: 10px;
            font-size: 11px; font-weight: 700;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-ongoing { background: #dbeafe; color: #1e40af; }
        .badge-done    { background: #d1fae5; color: #065f46; }
        .badge-new     { background: #fef3c7; color: #92400e; }
        .badge-old     { background: #dbeafe; color: #1e40af; }

        .visit-badges { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }

        .empty-state {
            text-align: center; padding: 60px 20px;
            color: #9ca3af; background: white; border-radius: 12px;
        }
        .empty-state i { font-size: 50px; margin-bottom: 14px; display: block; }

        .right-arrow { color: #d1d5db; font-size: 18px; flex-shrink: 0; }
    </style>
</head>
<body>
<div class="container">

    <div class="page-header">
        <h1><i class="fas fa-users"></i> My Clients</h1>
        <div class="sub">Welcome, <?= htmlspecialchars($me['full_name']) ?> — tap a client to view details and site visits.</div>
        <div class="header-stats">
            <div class="h-stat">
                <div class="h-stat-val"><?= $totalClients ?></div>
                <div class="h-stat-label">Total Clients</div>
            </div>
            <div class="h-stat">
                <div class="h-stat-val"><?= $totalPending ?></div>
                <div class="h-stat-label">Pending Visits</div>
            </div>
            <div class="h-stat">
                <div class="h-stat-val"><?= $totalOngoing ?></div>
                <div class="h-stat-label">Ongoing Visits</div>
            </div>
        </div>
    </div>

    <?php
    $doneClientCount   = count(array_filter($clients, fn($c) => $c['done_visits'] == $c['total_visits'] && $c['total_visits'] > 0));
    $activeClientCount = count($clients) - $doneClientCount;
    ?>
    <!-- Filter Tabs -->
    <div style="display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap;">
        <button type="button" id="btn-active" onclick="setFilter('active')"
                style="padding:9px 20px; border-radius:10px; border:2px solid #3b1f0f;
                       background:#3b1f0f; color:white; font-family:inherit;
                       font-size:13px; font-weight:700; cursor:pointer;
                       display:flex; align-items:center; gap:8px; transition:all 0.2s;">
            <i class="fas fa-spinner"></i> Active
            <span style="background:rgba(255,255,255,0.25); padding:1px 9px; border-radius:20px; font-size:11px;">
                <?= $activeClientCount ?>
            </span>
        </button>
        <button type="button" id="btn-done" onclick="setFilter('done')"
                style="padding:9px 20px; border-radius:10px; border:2px solid #e9ecef;
                       background:white; color:#9ca3af; font-family:inherit;
                       font-size:13px; font-weight:700; cursor:pointer;
                       display:flex; align-items:center; gap:8px; transition:all 0.2s;">
            <i class="fas fa-check-double"></i> Completed
            <span style="background:#f3f4f6; padding:1px 9px; border-radius:20px; font-size:11px; color:#3b1f0f;">
                <?= $doneClientCount ?>
            </span>
        </button>
    </div>

    <!-- Search -->
    <div class="search-bar">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" placeholder="Search client name, project, or reference..." oninput="filterClients()">
    </div>

    <!-- Client List -->
    <div id="clientList">
        <?php if (empty($clients)): ?>
        <div class="empty-state">
            <i class="fas fa-users-slash"></i>
            <p style="font-size:16px; font-weight:600;">No clients assigned yet</p>
            <p style="font-size:13px; margin-top:6px;">Clients will appear here once site visits are assigned to you.</p>
        </div>
        <?php else: ?>
            <?php foreach ($clients as $client):
                // Determine card color class
                $cardClass = '';
                if ($client['ongoing_visits'] > 0) $cardClass = 'has-ongoing';
                elseif ($client['pending_visits'] > 0) $cardClass = 'has-pending';
                elseif ($client['done_visits'] == $client['total_visits'] && $client['total_visits'] > 0) $cardClass = 'all-done';
            ?>
            <?php $isDone = ($client['done_visits'] == $client['total_visits'] && $client['total_visits'] > 0); ?>
            <a class="client-card <?= $cardClass ?>"
               href="designer-client-detail?client_id=<?= $client['client_id'] ?>"
               data-name="<?= htmlspecialchars(strtolower($client['clientname']), ENT_QUOTES) ?>"
               data-project="<?= htmlspecialchars(strtolower($client['nameproject']), ENT_QUOTES) ?>"
               data-ref="<?= htmlspecialchars(strtolower($client['reference_number']), ENT_QUOTES) ?>"
               data-status="<?= $isDone ? 'done' : 'active' ?>">

                <div style="flex: 1;">
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <div class="client-name"><?= htmlspecialchars($client['clientname']) ?></div>
                        <span class="badge <?= strtolower($client['status']) === 'new client' ? 'badge-new' : 'badge-old' ?>">
                            <?= htmlspecialchars($client['status']) ?>
                        </span>
                    </div>
                    <div class="client-sub">
                        <?= htmlspecialchars($client['nameproject']) ?>
                        &nbsp;•&nbsp;
                        <span style="font-family: monospace;"><?= htmlspecialchars($client['reference_number']) ?></span>
                    </div>
                    <div class="client-meta">
                        <span><i class="fas fa-building"></i> <?= $client['business_type'] === 'Non-Project' ? 'Individual' : htmlspecialchars($client['business_type']) ?></span>
                        <?php if ($client['contact']): ?>
                        <span><i class="fas fa-phone"></i> <?= htmlspecialchars($client['contact']) ?></span>
                        <?php endif; ?>
                        <span><i class="fas fa-calendar-check"></i> <?= $client['total_visits'] ?> visit<?= $client['total_visits'] != 1 ? 's' : '' ?></span>
                    </div>
                    <div class="visit-badges">
                        <?php if ($client['pending_visits'] > 0): ?>
                        <span class="badge badge-pending"><i class="fas fa-clock"></i> <?= $client['pending_visits'] ?> Pending</span>
                        <?php endif; ?>
                        <?php if ($client['ongoing_visits'] > 0): ?>
                        <span class="badge badge-ongoing"><i class="fas fa-spinner"></i> <?= $client['ongoing_visits'] ?> Ongoing</span>
                        <?php endif; ?>
                        <?php if ($client['done_visits'] > 0): ?>
                        <span class="badge badge-done"><i class="fas fa-check"></i> <?= $client['done_visits'] ?> Done</span>
                        <?php endif; ?>
                    </div>
                </div>

                <i class="fas fa-chevron-right right-arrow"></i>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<script>
let activeFilter = 'active';

function setFilter(filter) {
    activeFilter = filter;

    const btnActive = document.getElementById('btn-active');
    const btnDone   = document.getElementById('btn-done');

    if (filter === 'active') {
        btnActive.style.background   = '#3b1f0f';
        btnActive.style.borderColor  = '#3b1f0f';
        btnActive.style.color        = 'white';
        btnDone.style.background     = 'white';
        btnDone.style.borderColor    = '#e9ecef';
        btnDone.style.color          = '#9ca3af';
    } else {
        btnDone.style.background     = '#3b1f0f';
        btnDone.style.borderColor    = '#3b1f0f';
        btnDone.style.color          = 'white';
        btnActive.style.background   = 'white';
        btnActive.style.borderColor  = '#e9ecef';
        btnActive.style.color        = '#9ca3af';
    }

    filterClients();
}

function filterClients() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    let visible = 0;
    document.querySelectorAll('.client-card').forEach(card => {
        const name    = card.dataset.name    || '';
        const project = card.dataset.project || '';
        const ref     = card.dataset.ref     || '';
        const status  = card.dataset.status  || 'active';
        const matchSearch = !q || name.includes(q) || project.includes(q) || ref.includes(q);
        const matchFilter = status === activeFilter;
        const show = matchSearch && matchFilter;
        card.style.display = show ? 'flex' : 'none';
        if (show) visible++;
    });

    // Show empty state if nothing visible
    let emptyMsg = document.getElementById('emptyFilterMsg');
    if (!emptyMsg) {
        emptyMsg = document.createElement('div');
        emptyMsg.id = 'emptyFilterMsg';
        emptyMsg.style.cssText = 'text-align:center; padding:50px 20px; color:#9ca3af; background:white; border-radius:12px;';
        emptyMsg.innerHTML = '<i class="fas fa-search" style="font-size:40px; display:block; margin-bottom:12px; opacity:0.4;"></i><p style="font-size:14px; font-weight:600;">No clients found</p>';
        document.getElementById('clientList').appendChild(emptyMsg);
    }
    emptyMsg.style.display = visible === 0 ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', () => setFilter('active'));
</script>
</body>
</html>