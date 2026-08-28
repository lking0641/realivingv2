<?php
//qoutation_list.php
include $includes ['mainbody'];
// Allow only sales and superadmin
require_role(['sales', 'designer', 'technical_designer', 'project_coordinator']);

$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['role'] ?? '';
$search_name = isset($_GET['search_name']) ? trim($_GET['search_name']) : '';
$filter_business = isset($_GET['filter_business']) ? trim($_GET['filter_business']) : '';

// Build dynamic WHERE conditions for stats
$where_extra = '';
$bind_types = 'i';
$bind_params = [$admin_id];

if ($search_name !== '') {
    $where_extra .= " AND (clientname LIKE ? OR reference_number LIKE ? OR nameproject LIKE ?)";
    $bind_types .= 'sss';
    $like_name = '%' . $search_name . '%';
    $bind_params[] = $like_name;
    $bind_params[] = $like_name;
    $bind_params[] = $like_name;
}

if ($filter_business !== '') {
    $where_extra .= " AND business_type = ?";
    $bind_types .= 's';
    $bind_params[] = $filter_business;
}

// Total
$total_query = "SELECT COUNT(*) as count FROM user_info WHERE accountaid_fk = ?" . $where_extra;
$stmt = $conn->prepare($total_query);
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$total_quotations = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

// New clients
$new_params = $bind_params;
$new_types = $bind_types . 's';
$new_params[] = 'New Client';
$pending_query = "SELECT COUNT(*) as count FROM user_info WHERE accountaid_fk = ? AND status = 'New Client'" . $where_extra;
$stmt = $conn->prepare($pending_query);
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$new_clients = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

// Old clients  
$old_query = "SELECT COUNT(*) as count FROM user_info WHERE accountaid_fk = ? AND status = 'Old Client'" . $where_extra;
$stmt = $conn->prepare($old_query);
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$old_clients = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

// Fetch user_info records with additional fields
// Active clients only
$data_query = "SELECT id, clientname, status, nameproject, reference_number, contact, email, address, update_time, business_type FROM user_info WHERE accountaid_fk = ? AND account_status != 'Finished'" . $where_extra . " ORDER BY update_time DESC";
$stmt = $conn->prepare($data_query);
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$result = $stmt->get_result();

// Finished clients
$finished_query = "SELECT id, clientname, status, nameproject, reference_number, contact, email, address, update_time, business_type FROM user_info WHERE accountaid_fk = ? AND account_status = 'Finished'" . $where_extra . " ORDER BY update_time DESC";
$fstmt = $conn->prepare($finished_query);
$fstmt->bind_param($bind_types, ...$bind_params);
$fstmt->execute();
$finished_result = $fstmt->get_result();
$finishedRows = [];
while ($frow = $finished_result->fetch_assoc()) {
    $finishedRows[] = $frow;
}
$finished_count = count($finishedRows);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Quotation Requests — Realiving</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg: #F5F5F5;
            --surface: #FFFFFF;
            --surface2: #FAFAFA;
            --border: #E2E2E2;
            --text: #0B0B0B;
            --text-muted: #6B6B6B;
            --text-mute2: #9A9A9A;
            --brand: #0B0B0B;
            --brand-mid: #262626;
            --brand-light: #9A9A9A;
            --accent: #E8E8E8;
            --hover-bg: #F2F2F2;

            --success: #1F6F43;
            --success-bg: #E8F3EC;
            --success-border: #BFE0CC;

            --warning: #8A6100;
            --warning-bg: #FBF1D8;
            --warning-border: #EAD9A6;

            --danger: #9B1C1C;
            --danger-bg: #FBEAEA;
            --danger-border: #E3B7B7;

            --info: #33475B;
            --info-bg: #EDF0F3;
            --info-border: #C7D0DA;

            --purple: #46424F;
            --purple-bg: #F0EFF1;
            --purple-border: #D8D6DA;

            --radius: 12px;
            --radius-sm: 8px;
            --shadow: 0 1px 3px rgba(11, 11, 11, .06);
            --shadow-md: 0 10px 26px -16px rgba(11, 11, 11, .25);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        .app-wrap {
            max-width: 1400px;
            margin: 0 auto;
            padding: 28px 24px;
        }

        /* TOP BAR */
        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--brand);
            border-radius: var(--radius);
            padding: 20px 28px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-md);
            flex-wrap: wrap;
            gap: 14px;
        }

        .top-bar h1 {
            font-size: 20px;
            font-weight: 700;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .top-bar p {
            font-size: 13px;
            color: rgba(255, 255, 255, .6);
            margin-top: 3px;
        }

        /* STATS ROW */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 22px;
        }

        .stat-tile {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 20px 18px;
            box-shadow: var(--shadow);
            border: 1.5px solid var(--border);
            position: relative;
            overflow: hidden;
            transition: transform .2s, box-shadow .2s;
        }

        .stat-tile:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .stat-tile::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--tile-color, var(--brand-light));
        }

        .stat-tile .num {
            font-family: 'Inter', sans-serif;
            font-size: 32px;
            font-weight: 800;
            line-height: 1;
            color: var(--text);
        }

        .stat-tile .lbl {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
            font-weight: 500;
            letter-spacing: .3px;
            text-transform: uppercase;
        }

        .stat-tile .ico {
            position: absolute;
            right: 14px;
            top: 14px;
            font-size: 22px;
            opacity: .12;
            color: var(--tile-color, var(--brand-light));
        }

        .tile-total {
            --tile-color: #33475B;
        }

        .tile-new {
            --tile-color: #8A6100;
        }

        .tile-old {
            --tile-color: #46424F;
        }

        .tile-finished {
            --tile-color: #1F6F43;
        }

        /* SECTION CARD */
        .section-card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1.5px solid var(--border);
            overflow: hidden;
            margin-bottom: 22px;
        }

        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 24px;
            border-bottom: 1.5px solid var(--border);
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-head h2 {
            font-family: 'Inter', sans-serif;
            font-size: 15.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        /* FILTERS BAR */
        .filters-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: flex-end;
            padding: 18px 24px;
            background: var(--surface2);
            border-bottom: 1.5px solid var(--border);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-group label {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .filter-input {
            padding: 9px 13px;
            border-radius: var(--radius-sm);
            border: 1.5px solid var(--border);
            font-size: 13.5px;
            font-family: inherit;
            color: var(--text);
            background: var(--surface);
            transition: border-color .18s;
        }

        .filter-input:focus {
            outline: none;
            border-color: var(--brand);
        }

        /* TABS */
        .tabs-row {
            display: flex;
            gap: 8px;
            padding: 16px 24px;
            flex-wrap: wrap;
        }

        .tab-pill {
            padding: 9px 20px;
            border-radius: 30px;
            border: 1.5px solid var(--border);
            background: var(--surface);
            color: var(--text-muted);
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all .18s;
            font-family: inherit;
        }

        .tab-pill:hover {
            border-color: var(--brand-light);
            color: var(--brand);
        }

        .tab-pill.active {
            background: var(--brand);
            border-color: var(--brand);
            color: #fff;
        }

        .tab-pill .count {
            background: rgba(0, 0, 0, .08);
            border-radius: 12px;
            padding: 1px 8px;
            font-size: 11px;
        }

        .tab-pill.active .count {
            background: rgba(255, 255, 255, .22);
        }

        /* BADGES */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .3px;
            text-transform: uppercase;
        }

        .badge-new {
            background: var(--warning-bg);
            color: var(--warning);
        }

        .badge-old {
            background: var(--info-bg);
            color: var(--info);
        }

        .badge-project {
            background: var(--success-bg);
            color: var(--success);
        }

        .badge-individual {
            background: var(--purple-bg);
            color: var(--purple);
        }

        /* BUTTONS */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: all .18s;
            font-family: inherit;
        }

        .btn-primary {
            background: var(--brand);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--brand-mid);
        }

        .btn-header {
            background: #fff;
            color: var(--brand);
        }

        .btn-header:hover {
            background: var(--hover-bg);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn-outline {
            background: transparent;
            border: 1.5px solid var(--border);
            color: var(--text-muted);
        }

        .btn-outline:hover {
            border-color: var(--brand-light);
            color: var(--brand);
            background: var(--surface2);
        }

        .btn-success {
            background: var(--success-bg);
            color: var(--success);
            border: 1.5px solid var(--success-border);
        }

        .btn-success:hover {
            background: var(--success);
            color: #fff;
        }

        .btn-info {
            background: var(--info-bg);
            color: var(--info);
            border: 1.5px solid var(--info-border);
        }

        .btn-info:hover {
            background: var(--info);
            color: #fff;
        }

        .btn-warning {
            background: var(--warning-bg);
            color: var(--warning);
            border: 1.5px solid var(--warning-border);
        }

        .btn-warning:hover {
            background: var(--warning);
            color: #fff;
        }

        .btn-danger {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1.5px solid var(--danger-border);
        }

        .btn-danger:hover {
            background: var(--danger);
            color: #fff;
        }

        /* TABLE */
        .qt-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }

        .qt-table thead th {
            padding: 11px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-muted);
            background: var(--surface2);
            border-bottom: 1.5px solid var(--border);
            white-space: nowrap;
        }

        .qt-table tbody tr {
            border-bottom: 1px solid var(--border);
            transition: background .15s;
        }

        .qt-table tbody tr:hover {
            background: var(--hover-bg);
        }

        .qt-table td {
            padding: 13px 16px;
            vertical-align: top;
        }

        .td-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--text);
        }

        .td-sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .ref-mono {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: var(--text);
            background: var(--surface2);
            padding: 3px 8px;
            border-radius: 5px;
            border: 1px solid var(--border);
            display: inline-block;
        }

        .actions-cell {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            white-space: nowrap;
        }

        /* EMPTY STATE */
        .empty-state {
            text-align: center;
            padding: 56px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 42px;
            opacity: .25;
            display: block;
            margin-bottom: 14px;
        }

        .empty-state p:first-of-type {
            font-size: 15px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        /* MODAL */
        .modal-bg {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11, 11, 11, .55);
            z-index: 999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(3px);
        }

        .modal-bg.open {
            display: flex;
        }

        .modal-box {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 32px;
            max-width: 560px;
            width: 92%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .22);
            animation: modalIn .22s ease;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: translateY(16px) scale(.97)
            }

            to {
                opacity: 1;
                transform: none
            }
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .modal-head h3 {
            font-family: 'Inter', sans-serif;
            font-size: 17px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            border-radius: 6px;
        }

        .modal-close:hover {
            color: var(--text);
            background: var(--surface2);
        }

        .detail-row {
            display: grid;
            grid-template-columns: 140px 1fr;
            padding: 11px 0;
            border-bottom: 1px solid var(--border);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-muted);
        }

        .detail-value {
            font-size: 13.5px;
            color: var(--text);
        }

        /* DELETE MODAL */
        #deleteModal .modal-box {
            max-width: 440px;
            text-align: center;
        }

        .delete-warning-icon {
            font-size: 46px;
            color: var(--danger);
            margin-bottom: 14px;
        }

        #deleteModal h3 {
            justify-content: center;
            font-size: 18px;
            margin-bottom: 8px;
        }

        #deleteModal p {
            color: var(--text-muted);
            font-size: 13.5px;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        #deleteConfirmInput {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--danger-border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            letter-spacing: 2px;
            outline: none;
            transition: border-color .18s;
            font-family: inherit;
            margin-bottom: 18px;
            color: var(--text);
        }

        #deleteConfirmInput:focus {
            border-color: var(--danger);
        }

        .delete-modal-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .btn-confirm-delete:disabled {
            background: var(--danger-bg);
            color: var(--danger-border);
            cursor: not-allowed;
        }

        @media (max-width: 1100px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .app-wrap {
                padding: 16px;
            }

            .top-bar {
                padding: 16px 20px;
            }

            .filters-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-input {
                width: 100% !important;
            }

            /* Hide less important columns on mobile */
            th:nth-child(3),
            td:nth-child(3),
            th:nth-child(5),
            td:nth-child(5),
            th:nth-child(6),
            td:nth-child(6) {
                display: none;
            }

            .qt-table {
                font-size: 12px;
            }

            .qt-table th,
            .qt-table td {
                padding: 9px 8px;
            }

            .actions-cell {
                display: grid !important;
                grid-template-columns: 1fr 1fr;
                gap: 5px;
            }

            .actions-cell .btn {
                justify-content: center;
                padding: 7px !important;
            }

            .actions-cell .btn span {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="app-wrap">

        <!-- TOP BAR -->
        <div class="top-bar">
            <div>
                <h1><i class="fas fa-file-invoice"></i> My Quotation Requests</h1>
                <p>Manage and track your client quotations</p>
            </div>
            <a href="allclient" class="btn btn-header">
                <i class="fas fa-user-plus"></i> Add New Client
            </a>
        </div>

        <!-- STATS -->
        <div class="stats-row">
            <div class="stat-tile tile-total"> <i class="fas fa-file-invoice ico"></i>
                <div class="num"><?php echo $total_quotations; ?></div>
                <div class="lbl">Total Quotations</div>
            </div>
            <div class="stat-tile tile-new"> <i class="fas fa-user-plus ico"></i>
                <div class="num"><?php echo $new_clients; ?></div>
                <div class="lbl">New Clients</div>
            </div>
            <div class="stat-tile tile-old"> <i class="fas fa-user-check ico"></i>
                <div class="num"><?php echo $old_clients; ?></div>
                <div class="lbl">Returning Clients</div>
            </div>
            <div class="stat-tile tile-finished"> <i class="fas fa-check-double ico"></i>
                <div class="num"><?php echo $finished_count; ?></div>
                <div class="lbl">Finished</div>
            </div>
        </div>

        <!-- MAIN TABLE CARD (filters + tabs + table together) -->
        <div class="section-card">
            <div class="section-head">
                <h2><i class="fas fa-file-invoice" style="color:var(--brand-light);"></i> Quotation Requests</h2>
                <span id="resultCount" style="font-size:13px;color:var(--text-muted);"><?php echo $result->num_rows; ?> records</span>
            </div>

            <!-- Search & Filter -->
            <form method="get" class="filters-bar">
                <div class="filter-group" style="flex:1;min-width:220px;">
                    <label>Search</label>
                    <input type="text" name="search_name" placeholder="Client, project, or reference…"
                        value="<?php echo htmlspecialchars($search_name); ?>" class="filter-input">
                </div>
                <div class="filter-group">
                    <label>Business Type</label>
                    <select name="filter_business" class="filter-input" style="min-width:170px;">
                        <option value="">All Business Types</option>
                        <option value="Project" <?php echo $filter_business === 'Project' ? 'selected' : ''; ?>>Project</option>
                        <option value="Non-Project" <?php echo $filter_business === 'Non-Project' ? 'selected' : ''; ?>>Individual</option>
                    </select>
                </div>
                <div class="filter-group" style="justify-content:flex-end;gap:8px;flex-direction:row;align-items:flex-end;">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Apply</button>
                    <?php if ($search_name !== '' || $filter_business !== ''): ?>
                        <a href="quotation-list" class="btn btn-outline btn-sm"><i class="fas fa-redo"></i></a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- TABS -->
            <div class="tabs-row">
                <button id="tabActive" class="tab-pill active" onclick="setTab('active')">
                    <i class="fas fa-tasks"></i> Active
                    <span class="count" id="activeCount"><?php echo $result->num_rows; ?></span>
                </button>
                <button id="tabFinished" class="tab-pill" onclick="setTab('finished')">
                    <i class="fas fa-check-double"></i> Finished
                    <span class="count" id="finishedCount"><?php echo $finished_count; ?></span>
                </button>
            </div>

            <!-- ACTIVE TABLE -->
            <div id="activeTable">
                <?php if ($result->num_rows > 0): ?>
                    <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                        <table class="qt-table">
                            <thead>
                                <tr>
                                    <th>Ref #</th>
                                    <th>Client Name</th>
                                    <th>Project Name</th>
                                    <th>Status</th>
                                    <th>Business Type</th>
                                    <th>Contact</th>
                                    <th>Last Updated</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td><span class="ref-mono"><?php echo htmlspecialchars($row['reference_number']); ?></span></td>
                                        <td><div class="td-name"><?php echo htmlspecialchars($row['clientname']); ?></div></td>
                                        <td><div class="td-sub" style="color:var(--text);"><?php echo htmlspecialchars($row['nameproject']); ?></div></td>
                                        <td>
                                            <span class="badge badge-<?php echo $row['status'] === 'New Client' ? 'new' : 'old'; ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $btype = $row['business_type'] ?? '';
                                            $display = $btype === 'Non-Project' ? 'Individual' : htmlspecialchars($btype);
                                            $badgeClass = $btype === 'Project' ? 'badge-project' : 'badge-individual';
                                            ?>
                                            <span class="badge <?php echo $badgeClass; ?>"><?php echo $display; ?></span>
                                        </td>
                                        <td>
                                            <div class="td-sub"><i class="fas fa-phone" style="opacity:.5;"></i> <?php echo htmlspecialchars($row['contact'] ?: 'N/A'); ?></div>
                                            <div class="td-sub"><i class="fas fa-envelope" style="opacity:.5;"></i> <?php echo htmlspecialchars($row['email'] ?: 'N/A'); ?></div>
                                        </td>
                                        <td><div class="td-sub"><?php echo $row['update_time'] ? date('M d, Y', strtotime($row['update_time'])) : 'N/A'; ?></div></td>
                                        <td>
                                            <div class="actions-cell">
                                                <a href="quotation-items?prefill=1&id=<?php echo urlencode($row['id']); ?>&name=<?php echo urlencode($row['clientname']); ?>&contact=<?php echo urlencode($row['contact']); ?>&email=<?php echo urlencode($row['email']); ?>&address=<?php echo urlencode($row['address']); ?>"
                                                    class="btn btn-success btn-sm" title="Open Quotation Form">
                                                    <i class="fas fa-file-invoice"></i> <span>Open</span>
                                                </a>
                                                <button class="btn btn-info btn-sm" title="View Details"
                                                    onclick="viewDetails(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                                    <i class="fas fa-eye"></i> <span>View</span>
                                                </button>
                                                <a href="backup-client?client_id=<?php echo (int) $row['id']; ?>"
                                                    class="btn btn-warning btn-sm" title="Download backup before deleting">
                                                    <i class="fas fa-download"></i> <span>Backup</span>
                                                </a>
                                                <button class="btn btn-danger btn-sm" title="Delete Client"
                                                    onclick="confirmDelete(<?php echo (int) $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['clientname'])); ?>')">
                                                    <i class="fas fa-trash"></i> <span>Delete</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-file-invoice"></i>
                        <p>No quotation requests found</p>
                        <p style="font-size:13px;">Quotation requests will appear here when available</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- FINISHED TABLE -->
            <div id="finishedTable" style="display:none;">
                <?php if (!empty($finishedRows)): ?>
                    <div style="overflow-x: auto;">
                        <table class="qt-table">
                            <thead>
                                <tr>
                                    <th>Ref #</th>
                                    <th>Client Name</th>
                                    <th>Project Name</th>
                                    <th>Status</th>
                                    <th>Business Type</th>
                                    <th>Contact</th>
                                    <th>Last Updated</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($finishedRows as $row): ?>
                                    <tr>
                                        <td><span class="ref-mono"><?php echo htmlspecialchars($row['reference_number']); ?></span></td>
                                        <td><div class="td-name"><?php echo htmlspecialchars($row['clientname']); ?></div></td>
                                        <td><div class="td-sub" style="color:var(--text);"><?php echo htmlspecialchars($row['nameproject']); ?></div></td>
                                        <td>
                                            <span class="badge badge-<?php echo $row['status'] === 'New Client' ? 'new' : 'old'; ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $btype = $row['business_type'] ?? '';
                                            $display = $btype === 'Non-Project' ? 'Individual' : htmlspecialchars($btype);
                                            $badgeClass = $btype === 'Project' ? 'badge-project' : 'badge-individual';
                                            ?>
                                            <span class="badge <?php echo $badgeClass; ?>"><?php echo $display; ?></span>
                                        </td>
                                        <td>
                                            <div class="td-sub"><i class="fas fa-phone" style="opacity:.5;"></i> <?php echo htmlspecialchars($row['contact'] ?: 'N/A'); ?></div>
                                            <div class="td-sub"><i class="fas fa-envelope" style="opacity:.5;"></i> <?php echo htmlspecialchars($row['email'] ?: 'N/A'); ?></div>
                                        </td>
                                        <td><div class="td-sub"><?php echo $row['update_time'] ? date('M d, Y', strtotime($row['update_time'])) : 'N/A'; ?></div></td>
                                        <td>
                                            <div class="actions-cell">
                                                <a href="quotation-items?prefill=1&id=<?php echo urlencode($row['id']); ?>&name=<?php echo urlencode($row['clientname']); ?>&contact=<?php echo urlencode($row['contact']); ?>&email=<?php echo urlencode($row['email']); ?>&address=<?php echo urlencode($row['address']); ?>"
                                                    class="btn btn-success btn-sm" title="Open Quotation Form">
                                                    <i class="fas fa-file-invoice"></i> <span>Open</span>
                                                </a>
                                                <button class="btn btn-info btn-sm" title="View Details"
                                                    onclick="viewDetails(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                                    <i class="fas fa-eye"></i> <span>View</span>
                                                </button>
                                                <a href="backup-client?client_id=<?php echo (int) $row['id']; ?>"
                                                    class="btn btn-warning btn-sm" title="Download backup before deleting">
                                                    <i class="fas fa-download"></i> <span>Backup</span>
                                                </a>
                                                <button class="btn btn-danger btn-sm" title="Delete Client"
                                                    onclick="confirmDelete(<?php echo (int) $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['clientname'])); ?>')">
                                                    <i class="fas fa-trash"></i> <span>Delete</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-double"></i>
                        <p>No finished clients yet</p>
                        <p style="font-size:13px;">Clients will appear here once all their stages are completed.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /app-wrap -->

    <!-- DELETE CONFIRMATION MODAL -->
    <div id="deleteModal" class="modal-bg">
        <div class="modal-box">
            <i class="fas fa-exclamation-triangle delete-warning-icon"></i>
            <h3 style="display:block;">Delete Client?</h3>
            <p id="deleteModalText">
                This will permanently delete the client and <strong>all related data</strong>
                including quotations, files, payments, site visits, and more.<br><br>
                <strong>This action cannot be undone.</strong>
            </p>
            <input type="text" id="deleteConfirmInput" placeholder="Type DELETE here"
                oninput="document.getElementById('btnConfirmDelete').disabled = this.value !== 'DELETE';"
                onkeyup="if(event.key==='Enter' && this.value==='DELETE') executeDelete();" />
            <div class="delete-modal-actions">
                <button class="btn btn-outline" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="btn btn-danger" id="btnConfirmDelete" onclick="executeDelete()" disabled>
                    <i class="fas fa-trash"></i> Yes, Delete
                </button>
            </div>
        </div>
    </div>

    <!-- DETAIL MODAL -->
    <div id="detailModal" class="modal-bg">
        <div class="modal-box">
            <div class="modal-head">
                <h3><i class="fas fa-info-circle" style="color:var(--info);"></i> Quotation Details</h3>
                <button onclick="closeModal()" class="modal-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="modalContent"></div>
        </div>
    </div>

    <script>
        function setTab(tab) {
            const activeTable = document.getElementById('activeTable');
            const finishedTable = document.getElementById('finishedTable');
            const tabActive = document.getElementById('tabActive');
            const tabFinished = document.getElementById('tabFinished');

            if (tab === 'active') {
                activeTable.style.display = '';
                finishedTable.style.display = 'none';
                tabActive.classList.add('active');
                tabFinished.classList.remove('active');
            } else {
                activeTable.style.display = 'none';
                finishedTable.style.display = '';
                tabFinished.classList.add('active');
                tabActive.classList.remove('active');
            }
        }

        function viewDetails(quotation) {
            const modal = document.getElementById('detailModal');
            const content = document.getElementById('modalContent');

            content.innerHTML = `
                <div>
                    <div class="detail-row">
                        <div class="detail-label">Business Type:</div>
                        <div class="detail-value">${quotation.business_type === 'Non-Project' ? 'Individual' : (quotation.business_type || 'N/A')}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Reference Number:</div>
                        <div class="detail-value"><span class="ref-mono">${quotation.reference_number}</span></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Client Name:</div>
                        <div class="detail-value">${quotation.clientname}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Project Name:</div>
                        <div class="detail-value">${quotation.nameproject}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Status:</div>
                        <div class="detail-value">
                            <span class="badge badge-${quotation.status === 'New Client' ? 'new' : 'old'}">
                                ${quotation.status}
                            </span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Phone:</div>
                        <div class="detail-value">${quotation.contact || 'N/A'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Email:</div>
                        <div class="detail-value">${quotation.email || 'N/A'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Address:</div>
                        <div class="detail-value">${quotation.address || 'N/A'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Last Updated:</div>
                        <div class="detail-value">${quotation.update_time ? new Date(quotation.update_time).toLocaleString() : 'N/A'}</div>
                    </div>
                </div>
                <div style="margin-top: 20px; padding-top: 18px; border-top: 1.5px solid var(--border);">
                    <a href="quotation-items?prefill=1&id=${quotation.id}&name=${encodeURIComponent(quotation.clientname)}&contact=${encodeURIComponent(quotation.contact)}&email=${encodeURIComponent(quotation.email)}&address=${encodeURIComponent(quotation.address)}"
                       class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px;">
                        <i class="fas fa-file-invoice"></i>
                        <span>Open Quotation Form</span>
                    </a>
                </div>
            `;

            modal.classList.add('open');
        }

        function closeModal() {
            document.getElementById('detailModal').classList.remove('open');
        }

        document.getElementById('detailModal').addEventListener('click', function (e) {
            if (e.target === this) { closeModal(); }
        });
        document.getElementById('deleteModal').addEventListener('click', function (e) {
            if (e.target === this) { closeDeleteModal(); }
        });

        // ── Delete helpers ────────────────────────────────────────────
        let _deleteClientId = null;
        let _deleteClientName = null;

        function confirmDelete(clientId, clientName) {
            _deleteClientId = clientId;
            _deleteClientName = clientName;
            document.getElementById('deleteModalText').innerHTML =
                'You are about to permanently delete <strong>' + clientName + '</strong> and ' +
                '<strong>all related data</strong> including quotations, files, payments, ' +
                'site visits, and more.<br><br>' +
                'Type <strong>DELETE</strong> below to confirm:';
            document.getElementById('deleteConfirmInput').value = '';
            document.getElementById('btnConfirmDelete').disabled = true;
            document.getElementById('btnConfirmDelete').innerHTML = '<i class="fas fa-trash"></i> Yes, Delete';
            document.getElementById('deleteModal').classList.add('open');
            setTimeout(() => document.getElementById('deleteConfirmInput').focus(), 100);
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('open');
            document.getElementById('deleteConfirmInput').value = '';
            document.getElementById('btnConfirmDelete').disabled = true;
            _deleteClientId = null;
            _deleteClientName = null;
        }

        function executeDelete() {
            if (!_deleteClientId) return;

            const btn = document.getElementById('btnConfirmDelete');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting…';

            const fd = new FormData();
            fd.append('client_id', _deleteClientId);

            fetch('delete-client', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        closeDeleteModal();
                        window.location.reload();
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-trash"></i> Yes, Delete';
                        alert('Error: ' + (data.error || 'Delete failed'));
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-trash"></i> Yes, Delete';
                    alert('Network error — please try again.');
                });
        }
    </script>
</body>

</html>

<?php
$stmt->close();
$conn->close();
?>