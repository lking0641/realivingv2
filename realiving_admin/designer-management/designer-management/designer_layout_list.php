<?php
// designer_layout_list.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];

$meStmt = $conn->prepare("SELECT full_name, role, is_head FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

$allowedRoles = ['designer', 'technical_designer', 'general_manager', 'operational_manager'];
if (!in_array($me['role'], $allowedRoles)) {
    die("Access denied.");
}

$isHead     = in_array($me['role'], ['designer', 'technical_designer']) && $me['is_head'] == 1;
$isManager  = in_array($me['role'], ['general_manager', 'operational_manager']);
$canViewAll = $isManager || $isHead;

// ── Fetch clients ─────────────────────────────────────────────────────────
// Only show clients that have at least one designer assigned (designer1_id IS NOT NULL)
if ($canViewAll) {
    $clientsStmt = $conn->prepare("
        SELECT
            u.id, u.clientname, u.nameproject, u.reference_number,
            u.status, u.business_type, u.created_at,
            u.designer1_id, u.designer2_id,
            a1.full_name AS designer1_name,
            a2.full_name AS designer2_name,
            li.id         AS intake_id,
            li.layout_type_2d, li.layout_type_3d,
            li.created_at AS intake_submitted_at,
            pt.status     AS layout_stage_status
        FROM user_info u
        LEFT JOIN account a1       ON u.designer1_id = a1.id
        LEFT JOIN account a2       ON u.designer2_id = a2.id
        LEFT JOIN layout_intake li ON li.client_id   = u.id
        LEFT JOIN project_tracker pt
               ON pt.client_id = u.id AND pt.stage_name = '2D / 3D Layout'
        WHERE u.designer1_id IS NOT NULL
        ORDER BY u.created_at DESC
    ");
    $clientsStmt->execute();
} else {
    $clientsStmt = $conn->prepare("
        SELECT
            u.id, u.clientname, u.nameproject, u.reference_number,
            u.status, u.business_type, u.created_at,
            u.designer1_id, u.designer2_id,
            a1.full_name AS designer1_name,
            a2.full_name AS designer2_name,
            li.id         AS intake_id,
            li.layout_type_2d, li.layout_type_3d,
            li.created_at AS intake_submitted_at,
            pt.status     AS layout_stage_status
        FROM user_info u
        LEFT JOIN account a1       ON u.designer1_id = a1.id
        LEFT JOIN account a2       ON u.designer2_id = a2.id
        LEFT JOIN layout_intake li ON li.client_id   = u.id
        LEFT JOIN project_tracker pt
               ON pt.client_id = u.id AND pt.stage_name = '2D / 3D Layout'
        WHERE (u.designer1_id = ? OR u.designer2_id = ?)
        ORDER BY u.created_at DESC
    ");
    $clientsStmt->bind_param("ii", $admin_id, $admin_id);
    $clientsStmt->execute();
}
$clients = $clientsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Partition: active vs done, mine vs others ────────────────────────────
$myClients    = [];
$otherClients = [];
$doneClients  = [];

foreach ($clients as $c) {
    $isMine = ($c['designer1_id'] == $admin_id || $c['designer2_id'] == $admin_id);
    $isDone = ($c['layout_stage_status'] === 'Done');
    if ($isDone) {
        $doneClients[] = array_merge($c, ['_is_mine' => $isMine]);
    } elseif ($isMine) {
        $myClients[] = $c;
    } else {
        $otherClients[] = $c;
    }
}

$activeClients   = array_merge($myClients, $otherClients);
$totalActive     = count($activeClients);
// Fetch rejected layout counts per client (only for assigned designers)
foreach ($clients as &$c) {
    $isMineCheck = ($c['designer1_id'] == $admin_id || $c['designer2_id'] == $admin_id);
    $c['rejected_layouts'] = ($isMineCheck || $canViewAll)
        ? getClientRejectedLayoutCount($conn, $c['id'])
        : 0;
}
unset($c);

// Re-partition after adding rejected_layouts
$myClients    = [];
$otherClients = [];
$doneClients  = [];
foreach ($clients as $c) {
    $isMine = ($c['designer1_id'] == $admin_id || $c['designer2_id'] == $admin_id);
    $isDone = ($c['layout_stage_status'] === 'Done');
    if ($isDone) {
        $doneClients[] = array_merge($c, ['_is_mine' => $isMine]);
    } elseif ($isMine) {
        $myClients[] = $c;
    } else {
        $otherClients[] = $c;
    }
}
$activeClients = array_merge($myClients, $otherClients);
$totalDone       = count($doneClients);
$intakeDoneCount = count(array_filter($activeClients, fn($c) => $c['intake_id']));
$intakePending   = count(array_filter($activeClients, fn($c) => !$c['intake_id']));

// ── My designers under this head ─────────────────────────────────────────
$myDesigners = [];
if ($isHead) {
    $dStmt = $conn->prepare("
    SELECT id, full_name, role,
        (SELECT COUNT(*) FROM user_info u
         LEFT JOIN project_tracker pt
               ON pt.client_id = u.id AND pt.stage_name = '2D / 3D Layout'
         WHERE (u.designer1_id = account.id OR u.designer2_id = account.id)
           AND u.designer1_id IS NOT NULL
           AND (pt.status IS NULL OR pt.status != 'Done')) AS client_count
    FROM account
    WHERE role = ? AND is_head = 0
    ORDER BY full_name ASC
");
    $dStmt->bind_param("s", $me['role']);
    $dStmt->execute();
    $myDesigners = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getClientRejectedLayoutCount($conn, $client_id) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM layout_approvals
        WHERE client_id = ? AND status = 'rejected'
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_row()[0];
}

// ── Helper: render meta badges ───────────────────────────────────────────
function renderCardMeta($c) { ?>
    <div style="display:flex; flex-wrap:wrap; gap:7px; margin-top:10px; align-items:center;">
        <span class="badge <?= $c['status'] === 'New Client' ? 'badge-new' : 'badge-old' ?>"><?= htmlspecialchars($c['status']) ?></span>
        <span class="badge <?= $c['business_type'] === 'Project' ? 'badge-project' : 'badge-nonproj' ?>"><?= $c['business_type'] === 'Non-Project' ? 'Individual' : htmlspecialchars($c['business_type']) ?></span>
        <?php if ($c['designer1_name']): ?>
        <span class="badge badge-lead"><i class="fas fa-user-tie" style="font-size:8px;"></i> Lead: <?= htmlspecialchars($c['designer1_name']) ?></span>
        <?php endif; ?>
        <?php if ($c['designer2_name']): ?>
        <span class="badge badge-support"><i class="fas fa-user" style="font-size:8px;"></i> Support: <?= htmlspecialchars($c['designer2_name']) ?></span>
        <?php endif; ?>
        <?php if (!empty($c['intake_id'])): ?>
            <?php if ($c['layout_type_2d']): ?><span class="badge badge-layout"><i class="fas fa-vector-square" style="font-size:8px;"></i> 2D</span><?php endif; ?>
            <?php if ($c['layout_type_3d']): ?><span class="badge badge-layout"><i class="fas fa-cube" style="font-size:8px;"></i> 3D</span><?php endif; ?>
            <span style="font-size:10px; color:#9ca3af;"><i class="fas fa-clock" style="font-size:9px;"></i> Submitted <?= date('M d, Y', strtotime($c['intake_submitted_at'])) ?></span>
        <?php endif; ?>
        <?php if (!empty($c['rejected_layouts']) && $c['rejected_layouts'] > 0): ?>
        <span style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; padding:3px 9px; border-radius:20px; font-size:10px; font-weight:700; display:inline-flex; align-items:center; gap:4px;">
            <i class="fas fa-times-circle" style="font-size:9px;"></i> <?= $c['rejected_layouts'] ?> Rejected
        </span>
        <?php endif; ?>
    </div>
<?php }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2D/3D Layout — Clients</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#f5f1ed; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }
        .container { max-width:1100px; margin:30px auto; padding:0 20px 60px; }

        .page-header {
            background:linear-gradient(135deg,#3b1f0f 0%,#8a5a44 100%);
            padding:32px 36px; border-radius:16px; color:white; margin-bottom:24px;
            box-shadow:0 4px 12px rgba(59,31,15,0.25);
        }
        .page-header h1 { font-size:24px; margin-bottom:4px; display:flex; align-items:center; gap:10px; }
        .page-header .sub { font-size:13px; opacity:0.8; }
        .designer-badge {
            background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.25);
            padding:7px 16px; border-radius:20px; display:inline-flex; align-items:center; gap:8px;
            font-size:12px; font-weight:600; margin-top:12px;
        }

        .stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:22px; }
        .stat-card {
            background:white; border-radius:12px; padding:18px 20px;
            box-shadow:0 2px 4px rgba(0,0,0,0.07); border-left:4px solid #8a5a44;
            display:flex; align-items:center; gap:14px;
        }
        .stat-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
        .si-brown { background:linear-gradient(135deg,#3b1f0f,#8a5a44); color:white; }
        .si-gold  { background:linear-gradient(135deg,#d97706,#f59e0b); color:white; }
        .si-green { background:linear-gradient(135deg,#10b981,#059669); color:white; }
        .si-teal  { background:linear-gradient(135deg,#0d9488,#14b8a6); color:white; }
        .stat-value { font-size:26px; font-weight:700; color:#1f2937; }
        .stat-label { font-size:11px; color:#9ca3af; text-transform:uppercase; letter-spacing:0.4px; font-weight:600; }

        .designers-panel {
            background:white; border-radius:12px; padding:20px 24px;
            box-shadow:0 2px 4px rgba(0,0,0,0.07); margin-bottom:22px; border:2px solid #e0f2fe;
        }
        .designers-panel-title { font-size:14px; font-weight:700; color:#075985; margin-bottom:14px; display:flex; align-items:center; gap:8px; }
        .designers-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:10px; }
        .designer-chip { background:#f0f9ff; border:1.5px solid #bae6fd; border-radius:10px; padding:10px 14px; display:flex; align-items:center; gap:10px; }
        .designer-avatar { width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#0369a1,#38bdf8); display:flex; align-items:center; justify-content:center; color:white; font-size:14px; font-weight:700; flex-shrink:0; }

        .filter-tabs { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
        .filter-tab {
            padding:8px 16px; border-radius:20px; border:2px solid #e5e7eb;
            background:white; cursor:pointer; font-size:12px; font-weight:700; color:#6b7280;
            transition:all 0.18s; display:inline-flex; align-items:center; gap:6px;
        }
        .filter-tab:hover { border-color:#8a5a44; color:#8a5a44; }
        .filter-tab.active { background:linear-gradient(135deg,#3b1f0f,#8a5a44); color:white; border-color:transparent; }
        .filter-tab.done-tab.active { background:linear-gradient(135deg,#0d9488,#14b8a6); }
        .filter-tab .cnt { background:rgba(255,255,255,0.25); padding:1px 7px; border-radius:10px; font-size:11px; }
        .filter-tab:not(.active) .cnt { background:#f3f4f6; color:#374151; }

        .search-bar {
            background:white; padding:13px 18px; border-radius:10px; margin-bottom:18px;
            box-shadow:0 2px 4px rgba(0,0,0,0.06); display:flex; align-items:center; gap:10px;
            border:2px solid transparent; transition:border-color 0.2s;
        }
        .search-bar:focus-within { border-color:#8a5a44; }
        .search-bar i { color:#9ca3af; }
        .search-bar input { border:none; outline:none; font-size:13px; color:#374151; width:100%; background:transparent; }

        .section-heading {
            font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.6px;
            color:#9ca3af; margin-bottom:10px; margin-top:6px;
            display:flex; align-items:center; gap:8px;
        }
        .section-heading::after { content:''; flex:1; height:1px; background:#e5e7eb; }

        .client-card {
            background:white; border-radius:12px; margin-bottom:12px;
            box-shadow:0 2px 4px rgba(0,0,0,0.07); overflow:hidden;
            transition:all 0.2s; cursor:pointer; border:2px solid transparent;
        }
        .client-card:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,0.11); border-color:#8a5a44; }
        .client-card.mine  { border-color:#fcd34d; box-shadow:0 2px 8px rgba(217,119,6,0.12); }
        .client-card.mine:hover { border-color:#b45309; }
        .client-card.done-card { opacity:0.85; }
        .client-card.done-card:hover { border-color:#0d9488; opacity:1; }

        .client-card-inner { display:flex; align-items:stretch; }
        .card-accent { width:6px; flex-shrink:0; background:linear-gradient(180deg,#3b1f0f,#8a5a44); }
        .card-accent.done-acc  { background:linear-gradient(180deg,#0d9488,#14b8a6); }
        .card-accent.mine-acc  { background:linear-gradient(180deg,#d97706,#f59e0b); }
        .card-accent.green-acc { background:linear-gradient(180deg,#10b981,#059669); }

        .card-body { padding:15px 20px; flex:1; min-width:0; }
        .card-top  { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px; gap:10px; }
        .client-name  { font-size:16px; font-weight:700; color:#1f2937; margin-bottom:2px; }
        .project-name { font-size:12px; color:#6b7280; }
        .ref-number   { font-size:11px; color:#9ca3af; font-family:monospace; margin-top:2px; }

        .badge { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:20px; font-size:10px; font-weight:700; }
        .badge-new     { background:#fef3c7; color:#92400e; }
        .badge-old     { background:#dbeafe; color:#1e40af; }
        .badge-project { background:#ede9fe; color:#5b21b6; }
        .badge-nonproj { background:#fce7f3; color:#9d174d; }
        .badge-int-done{ background:#d1fae5; color:#065f46; }
        .badge-int-pend{ background:#fef3c7; color:#92400e; }
        .badge-lead    { background:#f0e6db; color:#3b1f0f; }
        .badge-support { background:#e0f2fe; color:#075985; }
        .badge-layout  { background:#ede9fe; color:#5b21b6; }
        .badge-done    { background:#ccfbf1; color:#0f766e; }

        .card-action { display:flex; align-items:center; padding:0 18px; border-left:1px solid #f3f4f6; flex-shrink:0; }
        .btn-open {
            background:linear-gradient(135deg,#3b1f0f,#8a5a44); color:white;
            padding:9px 18px; border-radius:9px; font-size:12px; font-weight:700;
            display:flex; align-items:center; gap:7px; text-decoration:none; white-space:nowrap; transition:opacity 0.2s;
        }
        .btn-open:hover { opacity:0.85; }
        .btn-open.done-btn { background:linear-gradient(135deg,#0d9488,#14b8a6); }

        .empty-state { text-align:center; padding:50px 20px; background:white; border-radius:12px; box-shadow:0 2px 4px rgba(0,0,0,0.07); }
        .empty-state i { font-size:48px; color:#d1d5db; display:block; margin-bottom:14px; }
        .empty-state h3 { font-size:16px; color:#6b7280; margin-bottom:6px; }
        .empty-state p  { font-size:12px; color:#9ca3af; }

        @media(max-width:640px) {
            .stats-row { grid-template-columns:repeat(2,1fr); }
            .card-action { display:none; }
        }
    </style>
</head>
<body>
<div class="container">

    <div class="page-header">
        <h1><i class="fas fa-drafting-compass"></i> 2D / 3D Layout — Clients</h1>
        <div class="sub">
            <?php if ($isHead): ?>Overview of all clients and your design team
            <?php elseif ($isManager): ?>All clients assigned for layout design work
            <?php else: ?>Clients assigned to you for layout design work
            <?php endif; ?>
        </div>
        <div class="designer-badge">
            <i class="fas fa-user-circle"></i>
            <?= htmlspecialchars($me['full_name']) ?>
            <span style="opacity:0.5;">•</span>
            <span style="opacity:0.75; text-transform:capitalize;"><?= str_replace('_',' ',$me['role']) ?></span>
            <?php if ($isHead): ?>
            <span style="opacity:0.5;">•</span>
            <span style="opacity:0.75;"><i class="fas fa-crown" style="font-size:10px;"></i> Head</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon si-brown"><i class="fas fa-users"></i></div>
            <div><div class="stat-value"><?= $totalActive ?></div><div class="stat-label">Active Clients</div></div>
        </div>
        <?php if ($canViewAll): ?>
        <div class="stat-card" style="border-left-color:#d97706;">
            <div class="stat-icon si-gold"><i class="fas fa-user-check"></i></div>
            <div><div class="stat-value"><?= count($myClients) ?></div><div class="stat-label">My Clients</div></div>
        </div>
        <?php else: ?>
        <div class="stat-card" style="border-left-color:#10b981;">
            <div class="stat-icon si-green"><i class="fas fa-clipboard-check"></i></div>
            <div><div class="stat-value"><?= $intakeDoneCount ?></div><div class="stat-label">Intake Done</div></div>
        </div>
        <?php endif; ?>
        <div class="stat-card" style="border-left-color:#f59e0b;">
            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:white;"><i class="fas fa-clock"></i></div>
            <div><div class="stat-value"><?= $intakePending ?></div><div class="stat-label">Intake Pending</div></div>
        </div>
        <div class="stat-card" style="border-left-color:#0d9488;">
            <div class="stat-icon si-teal"><i class="fas fa-flag-checkered"></i></div>
            <div><div class="stat-value"><?= $totalDone ?></div><div class="stat-label">Layout Done</div></div>
        </div>
    </div>

    <!-- My Design Team (head only) -->
    <?php if ($isHead && !empty($myDesigners)): ?>
    <div class="designers-panel">
        <div class="designers-panel-title">
            <i class="fas fa-users-cog"></i> My Design Team
            <span style="font-size:11px; background:#e0f2fe; color:#0369a1; padding:2px 10px; border-radius:10px; font-weight:700;">
                <?= count($myDesigners) ?> designer<?= count($myDesigners) != 1 ? 's' : '' ?>
            </span>
        </div>
        <div class="designers-grid">
            <?php foreach ($myDesigners as $d):
                $initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', $d['full_name']), 0, 2)));
            ?>
            <div class="designer-chip">
                <div class="designer-avatar"><?= $initials ?></div>
                <div>
                    <div style="font-size:13px; font-weight:700; color:#0c4a6e;"><?= htmlspecialchars($d['full_name']) ?></div>
                    <div style="font-size:10px; color:#64748b; text-transform:capitalize;"><?= str_replace('_',' ',$d['role']) ?></div>
                    <div style="font-size:10px; color:#0369a1; font-weight:700; margin-top:2px;">
                        <i class="fas fa-folder" style="font-size:9px;"></i>
                        <?= $d['client_count'] ?> client<?= $d['client_count'] != 1 ? 's' : '' ?> assigned
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter tabs -->
    <div class="filter-tabs">
        <button class="filter-tab active" data-filter="active" onclick="setFilter('active',this)">
            <i class="fas fa-th-list"></i> Active <span class="cnt"><?= $totalActive ?></span>
        </button>
        <?php if ($canViewAll): ?>
        <button class="filter-tab" data-filter="mine" onclick="setFilter('mine',this)">
            <i class="fas fa-user-check"></i> My Clients <span class="cnt"><?= count($myClients) ?></span>
        </button>
        <button class="filter-tab" data-filter="others" onclick="setFilter('others',this)">
            <i class="fas fa-users"></i> Team's Clients <span class="cnt"><?= count($otherClients) ?></span>
        </button>
        <?php endif; ?>
        <button class="filter-tab" data-filter="intake-pending" onclick="setFilter('intake-pending',this)">
            <i class="fas fa-hourglass-half"></i> Intake Pending <span class="cnt"><?= $intakePending ?></span>
        </button>
        <button class="filter-tab done-tab" data-filter="done" onclick="setFilter('done',this)">
            <i class="fas fa-flag-checkered"></i> Done <span class="cnt"><?= $totalDone ?></span>
        </button>
    </div>

    <!-- Search -->
    <div class="search-bar">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" placeholder="Search by client name, project, or reference number...">
    </div>

    <?php
$totalRejectedLayouts = array_sum(array_column($activeClients, 'rejected_layouts'));
if ($totalRejectedLayouts > 0):
?>
<div style="background:#fee2e2; border:2px solid #ef4444; border-radius:12px; padding:16px 20px; margin-bottom:20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
    <div style="display:flex; align-items:center; gap:12px; flex:1;">
        <i class="fas fa-times-circle" style="color:#dc2626; font-size:22px; flex-shrink:0;"></i>
        <div>
            <div style="font-weight:700; font-size:15px; color:#991b1b;">
                <?= $totalRejectedLayouts ?> layout area<?= $totalRejectedLayouts > 1 ? 's/units' : '/unit' ?> rejected across your clients
            </div>
            <div style="font-size:12px; color:#b91c1c; margin-top:3px;">
                Look for the <strong>red badge</strong> on each client card below. Open the layout to review and resubmit.
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

    <?php if (empty($clients)): ?>
    <div class="empty-state">
        <i class="fas fa-inbox"></i>
        <h3>No Clients Assigned</h3>
        <p>You have no clients assigned for 2D/3D layout work yet.</p>
    </div>
    <?php else: ?>

    <!-- ══ ACTIVE SECTION ══ -->
    <div id="section-active">
        <?php if ($canViewAll && !empty($myClients)): ?>
        <div class="section-heading" data-section="mine">
            <i class="fas fa-user-check"></i> My Clients <span style="font-size:11px;">(<?= count($myClients) ?>)</span>
        </div>
        <?php foreach ($myClients as $c):
            $hasIntake = !empty($c['intake_id']);
        ?>
        <div class="client-card mine"
     data-filter-tags="active mine <?= $hasIntake ? 'intake-done' : 'intake-pending' ?>"
     style="<?= (!empty($c['rejected_layouts']) && $c['rejected_layouts'] > 0) ? 'border-color:#ef4444; box-shadow:0 2px 8px rgba(239,68,68,0.15);' : '' ?>"
             data-search="<?= strtolower($c['clientname'].' '.$c['nameproject'].' '.$c['reference_number']) ?>"
             onclick="window.location.href='<?= BASE_URL ?>designer-2d3d-layout?client_id=<?= $c['id'] ?>'">
            <div class="client-card-inner">
                <div class="card-accent mine-acc"></div>
                <div class="card-body">
                    <div class="card-top">
                        <div>
                            <div class="client-name">
                                <?= htmlspecialchars($c['clientname']) ?>
                                <span style="font-size:10px; background:#fef3c7; color:#92400e; padding:2px 7px; border-radius:10px; margin-left:6px; font-weight:700; vertical-align:middle;">
                                    <i class="fas fa-star" style="font-size:8px;"></i> Mine
                                </span>
                            </div>
                            <div class="project-name"><?= htmlspecialchars($c['nameproject']) ?></div>
                            <div class="ref-number"><i class="fas fa-hashtag" style="font-size:9px;"></i> <?= htmlspecialchars($c['reference_number']) ?></div>
                        </div>
                        <div>
                            <?php if ($hasIntake): ?>
                            <span class="badge badge-int-done"><i class="fas fa-check-circle"></i> Intake Done</span>
                            <?php else: ?>
                            <span class="badge badge-int-pend"><i class="fas fa-hourglass-half"></i> Intake Pending</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php renderCardMeta($c); ?>
                </div>
                <div class="card-action">
                    <a href="<?= BASE_URL ?>designer-2d3d-layout?client_id=<?= $c['id'] ?>" class="btn-open" onclick="event.stopPropagation()">
                        <i class="fas fa-drafting-compass"></i> <?= $hasIntake ? 'View Layout' : 'Fill Intake' ?>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($canViewAll && !empty($otherClients)): ?>
        <div class="section-heading" data-section="others">
            <i class="fas fa-users"></i> Team's Clients <span style="font-size:11px;">(<?= count($otherClients) ?>)</span>
        </div>
        <?php endif; ?>

        <?php
        $activeListToRender = $canViewAll ? $otherClients : $myClients;
        foreach ($activeListToRender as $c):
            $hasIntake = !empty($c['intake_id']);
            $filterTag = $canViewAll ? 'others' : 'mine';
        ?>
        <div class="client-card"
     data-filter-tags="active <?= $filterTag ?> <?= $hasIntake ? 'intake-done' : 'intake-pending' ?>"
     style="<?= (!empty($c['rejected_layouts']) && $c['rejected_layouts'] > 0) ? 'border-color:#ef4444; box-shadow:0 2px 8px rgba(239,68,68,0.15);' : '' ?>"
             data-search="<?= strtolower($c['clientname'].' '.$c['nameproject'].' '.$c['reference_number']) ?>"
             onclick="window.location.href='<?= BASE_URL ?>designer-2d3d-layout?client_id=<?= $c['id'] ?>'">
            <div class="client-card-inner">
                <div class="card-accent <?= $hasIntake ? 'green-acc' : '' ?>"></div>
                <div class="card-body">
                    <div class="card-top">
                        <div>
                            <div class="client-name"><?= htmlspecialchars($c['clientname']) ?></div>
                            <div class="project-name"><?= htmlspecialchars($c['nameproject']) ?></div>
                            <div class="ref-number"><i class="fas fa-hashtag" style="font-size:9px;"></i> <?= htmlspecialchars($c['reference_number']) ?></div>
                        </div>
                        <div>
                            <?php if ($hasIntake): ?>
                            <span class="badge badge-int-done"><i class="fas fa-check-circle"></i> Intake Done</span>
                            <?php else: ?>
                            <span class="badge badge-int-pend"><i class="fas fa-hourglass-half"></i> Intake Pending</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php renderCardMeta($c); ?>
                </div>
                <div class="card-action">
                    <a href="<?= BASE_URL ?>designer-2d3d-layout?client_id=<?= $c['id'] ?>" class="btn-open" onclick="event.stopPropagation()">
                        <i class="fas fa-drafting-compass"></i> <?= $hasIntake ? 'View Layout' : 'Fill Intake' ?>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($activeClients)): ?>
        <div class="empty-state">
            <i class="fas fa-check-double"></i>
            <h3>All caught up!</h3>
            <p>No active clients — all layouts are done. Check the Done tab.</p>
        </div>
        <?php endif; ?>
    </div><!-- /section-active -->

    <!-- ══ DONE SECTION (hidden by default) ══ -->
    <div id="section-done" style="display:none;">
        <?php if (!empty($doneClients)): ?>
        <div class="section-heading">
            <i class="fas fa-flag-checkered"></i> Completed Layouts <span style="font-size:11px;">(<?= count($doneClients) ?>)</span>
        </div>
        <?php foreach ($doneClients as $c):
            $hasIntake = !empty($c['intake_id']);
            $isMine    = $c['_is_mine'];
        ?>
        <div class="client-card done-card"
             data-filter-tags="done <?= $isMine ? 'mine' : 'others' ?>"
             data-search="<?= strtolower($c['clientname'].' '.$c['nameproject'].' '.$c['reference_number']) ?>"
             onclick="window.location.href='<?= BASE_URL ?>designer-2d3d-layout?client_id=<?= $c['id'] ?>'">
            <div class="client-card-inner">
                <div class="card-accent done-acc"></div>
                <div class="card-body">
                    <div class="card-top">
                        <div>
                            <div class="client-name">
                                <?= htmlspecialchars($c['clientname']) ?>
                                <?php if ($isMine && $canViewAll): ?>
                                <span style="font-size:10px; background:#fef3c7; color:#92400e; padding:2px 7px; border-radius:10px; margin-left:6px; font-weight:700; vertical-align:middle;">
                                    <i class="fas fa-star" style="font-size:8px;"></i> Mine
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="project-name"><?= htmlspecialchars($c['nameproject']) ?></div>
                            <div class="ref-number"><i class="fas fa-hashtag" style="font-size:9px;"></i> <?= htmlspecialchars($c['reference_number']) ?></div>
                        </div>
                        <span class="badge badge-done"><i class="fas fa-flag-checkered"></i> Layout Done</span>
                    </div>
                    <?php renderCardMeta($c); ?>
                </div>
                <div class="card-action">
                    <a href="<?= BASE_URL ?>designer-2d3d-layout?client_id=<?= $c['id'] ?>" class="btn-open done-btn" onclick="event.stopPropagation()">
                        <i class="fas fa-eye"></i> View
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-flag-checkered"></i>
            <h3>No Completed Layouts Yet</h3>
            <p>Clients whose 2D/3D Layout stage is marked Done will appear here.</p>
        </div>
        <?php endif; ?>
    </div><!-- /section-done -->

    <?php endif; ?>
</div>

<script>
let currentFilter = 'active';
let currentSearch  = '';

function setFilter(filter, btn) {
    currentFilter = filter;
    document.querySelectorAll('.filter-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyFilters();
}

document.getElementById('searchInput').addEventListener('input', function() {
    currentSearch = this.value.toLowerCase().trim();
    applyFilters();
});

function applyFilters() {
    const isDoneView = (currentFilter === 'done');
    const activeSection = document.getElementById('section-active');
    const doneSection   = document.getElementById('section-done');

    activeSection.style.display = isDoneView ? 'none' : 'block';
    doneSection.style.display   = isDoneView ? 'block' : 'none';

    const sectionId = isDoneView ? 'section-done' : 'section-active';

    document.querySelectorAll('#' + sectionId + ' .client-card').forEach(card => {
        const tags   = card.getAttribute('data-filter-tags') || '';
        const search = card.getAttribute('data-search') || '';

        const matchFilter = (currentFilter === 'active' || currentFilter === 'done')
                          ? true
                          : tags.includes(currentFilter);
        const matchSearch = !currentSearch || search.includes(currentSearch);

        card.style.display = (matchFilter && matchSearch) ? 'block' : 'none';
    });

    // Smartly hide section headings if no cards visible under them
    document.querySelectorAll('#' + sectionId + ' .section-heading[data-section]').forEach(h => {
        const sec = h.getAttribute('data-section');
        const anyVisible = Array.from(
            document.querySelectorAll('#' + sectionId + ' .client-card[data-filter-tags*="' + sec + '"]')
        ).some(c => c.style.display !== 'none');
        h.style.display = anyVisible ? 'flex' : 'none';
    });
}
</script>
</body>
</html>