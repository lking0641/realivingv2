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
    <title>My Quotation Requests</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }

        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }

        /* Header */
        .dashboard-header {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
            padding: 40px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .dashboard-header h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }

        .dashboard-header p {
            opacity: 0.9;
            font-size: 16px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .icon-total {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .icon-new {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .icon-old {
            background: linear-gradient(135deg, #48c6ef 0%, #6f86d6 100%);
        }

        .stat-content h3 {
            font-size: 13px;
            color: #666;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #3b1f0f;
        }

        /* Table Card */
        .table-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .table-header {
            background: #3b1f0f;
            color: white;
            padding: 20px;
        }

        .table-header h2 {
            font-size: 20px;
        }

        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f8f9fa;
        }

        th {
            padding: 15px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 15px;
            border-top: 1px solid #e9ecef;
        }

        tbody tr {
            transition: background 0.2s;
        }

        tbody tr:hover {
            background: #f8f9fa;
        }

        /* Buttons */
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.2s;
        }

        .btn-quotation {
            background: #10b981;
            color: white;
        }

        .btn-quotation:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        .btn-view {
            background: #3b82f6;
            color: white;
        }

        .btn-view:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }

        .btn-header {
            background: white;
            color: #3b1f0f;
            padding: 12px 20px;
        }

        .btn-header:hover {
            background: #f8f9fa;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(255, 255, 255, 0.3);
        }

        /* Badge */
        .badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-new {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-old {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background-color: #fefefe;
            padding: 30px;
            border-radius: 12px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h2 {
            font-size: 24px;
            font-weight: bold;
            color: #3b1f0f;
        }

        .modal-close {
            font-size: 24px;
            color: #666;
            cursor: pointer;
            background: none;
            border: none;
        }

        .modal-close:hover {
            color: #000;
        }

        .detail-row {
            display: grid;
            grid-template-columns: 140px 1fr;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: #666;
        }

        .detail-value {
            color: #111;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            color: #ddd;
        }

        /* Search & Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 12px;
            padding: 20px 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-bar input[type="text"] {
            flex: 1;
            min-width: 220px;
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        .filter-bar input[type="text"]:focus {
            outline: none;
            border-color: #3b1f0f;
        }

        .filter-bar select {
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: border-color 0.2s;
            min-width: 170px;
        }

        .filter-bar select:focus {
            outline: none;
            border-color: #3b1f0f;
        }

        .btn-filter {
            background: #3b1f0f;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-filter:hover {
            background: #2a1609;
            transform: translateY(-1px);
        }

        .btn-clear {
            background: #e9ecef;
            color: #555;
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-clear:hover {
            background: #dee2e6;
        }

        .btn-backup {
            background: #f59e0b;
            color: white;
        }

        .btn-backup:hover {
            background: #d97706;
            transform: translateY(-1px);
        }

        .btn-delete {
            background: #ef4444;
            color: white;
        }

        .btn-delete:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }

        /* Delete Confirmation Modal */
        #deleteModal .modal-content {
            max-width: 440px;
            text-align: center;
        }

        .delete-warning-icon {
            font-size: 56px;
            margin-bottom: 16px;
        }

        #deleteModal h2 {
            font-size: 22px;
            color: #111;
            margin-bottom: 8px;
        }

        #deleteModal p {
            color: #666;
            font-size: 14px;
            margin-bottom: 24px;
            line-height: 1.5;
        }

        .delete-modal-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .btn-cancel-delete {
            background: #e9ecef;
            color: #555;
            padding: 11px 28px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
        }

        .btn-cancel-delete:hover {
            background: #dee2e6;
        }

        .btn-confirm-delete {
            background: #ef4444;
            color: white;
            padding: 11px 28px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-confirm-delete:hover {
            background: #dc2626;
        }

        .btn-confirm-delete:disabled {
            background: #fca5a5;
            cursor: not-allowed;
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 15px;
            }

            .dashboard-header {
                padding: 20px;
            }

            .dashboard-header h1 {
                font-size: 22px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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

            table {
                font-size: 12px;
            }

            th,
            td {
                padding: 8px 6px;
            }

            .btn {
                padding: 5px 8px;
                font-size: 11px;
            }

            .btn span {
                display: none;
            }

            .btn i {
                margin: 0;
            }

            /* Make action buttons a 2x2 grid */
            td:last-child div[style*="display: flex"] {
                display: grid !important;
                grid-template-columns: 1fr 1fr;
                gap: 4px;
            }

            .btn {
                justify-content: center;
                padding: 6px !important;
            }

            .filter-bar {
                flex-direction: column;
            }

            .filter-bar input[type="text"],
            .filter-bar select {
                width: 100%;
                min-width: unset;
            }

            td:first-child div {
                font-size: 9px !important;
                word-break: break-all;
            }
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div
                style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                <div>
                    <h1>📋 My Quotation Requests</h1>
                    <p>Manage and track your client quotations</p>
                </div>
                <a href="allclient" class="btn btn-header">
                    <i class="fas fa-user-plus"></i>
                    <span>Add New Client</span>
                </a>
            </div>
        </div>

        <!-- Search & Filter Bar -->
        <form method="get" class="filter-bar">
            <input type="text" name="search_name" placeholder="🔍 Search by client, project, or reference…"
                value="<?php echo htmlspecialchars($search_name); ?>" />
            <select name="filter_business">
                <option value="">All Business Types</option>
                <option value="Project" <?php echo $filter_business === 'Project' ? 'selected' : ''; ?>>
                    Project
                </option>
                <option value="Non-Project" <?php echo $filter_business === 'Non-Project' ? 'selected' : ''; ?>>
                    Individual
                </option>
            </select>
            <button type="submit" class="btn-filter">
                <i class="fas fa-filter"></i> Apply
            </button>
            <?php if ($search_name !== '' || $filter_business !== ''): ?>
                <a href="quotation-list" class="btn-clear">
                    <i class="fas fa-times"></i> Clear
                </a>
            <?php endif; ?>
        </form>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-content">
                        <h3>Total Quotations</h3>
                        <div class="stat-number"><?php echo $total_quotations; ?></div>
                    </div>
                    <div class="stat-icon icon-total">📋</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-content">
                        <h3>New Clients</h3>
                        <div class="stat-number"><?php echo $new_clients; ?></div>
                    </div>
                    <div class="stat-icon icon-new">🆕</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-content">
                        <h3>Returning Clients</h3>
                        <div class="stat-number"><?php echo $old_clients; ?></div>
                    </div>
                    <div class="stat-icon icon-old">🔄</div>
                </div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #10b981;">
                <div class="stat-header">
                    <div class="stat-content">
                        <h3>Finished</h3>
                        <div class="stat-number" style="color:#065f46;"><?php echo $finished_count; ?></div>
                    </div>
                    <div class="stat-icon" style="background: linear-gradient(135deg, #065f46 0%, #10b981 100%);">✅
                    </div>
                </div>
            </div>
        </div>

        <!-- Active / Finished Tabs -->
        <div style="display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap;">
            <button id="tabActive" onclick="setTab('active')"
                style="padding:10px 24px; border-radius:25px; border:2px solid #3b1f0f; background:linear-gradient(135deg,#3b1f0f,#8a5a44); color:white; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:8px;">
                <i class="fas fa-tasks"></i> Active
                <span id="activeCount"
                    style="background:rgba(255,255,255,.25); border-radius:12px; padding:1px 8px; font-size:11px;"><?php echo $result->num_rows; ?></span>
            </button>
            <button id="tabFinished" onclick="setTab('finished')"
                style="padding:10px 24px; border-radius:25px; border:2px solid #e9ecef; background:white; color:#555; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:8px;">
                <i class="fas fa-check-double"></i> Finished
                <span id="finishedCount"
                    style="background:#e9ecef; border-radius:12px; padding:1px 8px; font-size:11px;"><?php echo $finished_count; ?></span>
            </button>
        </div>

        <!-- Quotations Table -->
        <div class="table-card" id="activeTable">
            <div class="table-header">
                <h2>All Quotation Requests</h2>
            </div>

            <?php if ($result->num_rows > 0): ?>
                <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                    <table>
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
                                    <td>
                                        <div style="font-family: monospace; font-size: 12px; color: #3b82f6;">
                                            <?php echo htmlspecialchars($row['reference_number']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #111;">
                                            <?php echo htmlspecialchars($row['clientname']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="color: #666;">
                                            <?php echo htmlspecialchars($row['nameproject']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span
                                            class="badge badge-<?php echo $row['status'] === 'New Client' ? 'new' : 'old'; ?>">
                                            <?php echo htmlspecialchars($row['status']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php
                                        $btype = $row['business_type'] ?? '';
                                        $display = $btype === 'Non-Project' ? 'Individual' : htmlspecialchars($btype);
                                        $color = $btype === 'Project' ? '#d1fae5; color: #065f46' : '#ede9fe; color: #4c1d95';
                                        ?>
                                        <span
                                            style="padding: 4px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; background: <?php echo $color; ?>;">
                                            <?php echo $display; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-size: 13px;">
                                            <div><i class="fas fa-phone"
                                                    style="color: #999; margin-right: 5px;"></i><?php echo htmlspecialchars($row['contact'] ?: 'N/A'); ?>
                                            </div>
                                            <div style="color: #666; margin-top: 2px;"><i class="fas fa-envelope"
                                                    style="color: #999; margin-right: 5px;"></i><?php echo htmlspecialchars($row['email'] ?: 'N/A'); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 13px; color: #666;">
                                            <?php echo $row['update_time'] ? date('M d, Y', strtotime($row['update_time'])) : 'N/A'; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                            <a href="quotation-items?prefill=1&id=<?php echo urlencode($row['id']); ?>&name=<?php echo urlencode($row['clientname']); ?>&contact=<?php echo urlencode($row['contact']); ?>&email=<?php echo urlencode($row['email']); ?>&address=<?php echo urlencode($row['address']); ?>"
                                                class="btn btn-quotation">
                                                <i class="fas fa-file-invoice"></i>
                                                <span>Open</span>
                                            </a>

                                            <button class="btn btn-view"
                                                onclick="viewDetails(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                                <i class="fas fa-eye"></i>
                                                <span>View</span>
                                            </button>

                                            <a href="backup-client?client_id=<?php echo (int) $row['id']; ?>"
                                                class="btn btn-backup" title="Download backup before deleting">
                                                <i class="fas fa-download"></i>
                                                <span>Backup</span>
                                            </a>

                                            <button class="btn btn-delete"
                                                onclick="confirmDelete(<?php echo (int) $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['clientname'])); ?>')">
                                                <i class="fas fa-trash"></i>
                                                <span>Delete</span>
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
                    <p style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">No quotation requests found</p>
                    <p style="font-size: 14px;">Quotation requests will appear here when available</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Finished Table -->
        <div class="table-card" id="finishedTable" style="display:none;">
            <div class="table-header" style="background:#065f46;">
                <h2><i class="fas fa-check-double"></i> Finished Quotations</h2>
            </div>
            <?php if (!empty($finishedRows)): ?>
                <div style="overflow-x: auto;">
                    <table>
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
                                    <td>
                                        <div style="font-family:monospace; font-size:12px; color:#3b82f6;">
                                            <?php echo htmlspecialchars($row['reference_number']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:600; color:#111;">
                                            <?php echo htmlspecialchars($row['clientname']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="color:#666;"><?php echo htmlspecialchars($row['nameproject']); ?></div>
                                    </td>
                                    <td>
                                        <span
                                            class="badge badge-<?php echo $row['status'] === 'New Client' ? 'new' : 'old'; ?>">
                                            <?php echo htmlspecialchars($row['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $btype = $row['business_type'] ?? '';
                                        $display = $btype === 'Non-Project' ? 'Individual' : htmlspecialchars($btype);
                                        $color = $btype === 'Project' ? '#d1fae5; color: #065f46' : '#ede9fe; color: #4c1d95';
                                        ?>
                                        <span
                                            style="padding:4px 10px; border-radius:10px; font-size:11px; font-weight:600; background:<?php echo $color; ?>;">
                                            <?php echo $display; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-size:13px;">
                                            <div><i class="fas fa-phone"
                                                    style="color:#999; margin-right:5px;"></i><?php echo htmlspecialchars($row['contact'] ?: 'N/A'); ?>
                                            </div>
                                            <div style="color:#666; margin-top:2px;"><i class="fas fa-envelope"
                                                    style="color:#999; margin-right:5px;"></i><?php echo htmlspecialchars($row['email'] ?: 'N/A'); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size:13px; color:#666;">
                                            <?php echo $row['update_time'] ? date('M d, Y', strtotime($row['update_time'])) : 'N/A'; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                            <a href="quotation-items?prefill=1&id=<?php echo urlencode($row['id']); ?>&name=<?php echo urlencode($row['clientname']); ?>&contact=<?php echo urlencode($row['contact']); ?>&email=<?php echo urlencode($row['email']); ?>&address=<?php echo urlencode($row['address']); ?>"
                                                class="btn btn-quotation">
                                                <i class="fas fa-file-invoice"></i> Open
                                            </a>
                                            <button class="btn btn-view"
                                                onclick="viewDetails(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>

                                            <a href="backup-client?client_id=<?php echo (int) $row['id']; ?>"
                                                class="btn btn-backup" title="Download backup before deleting">
                                                <i class="fas fa-download"></i> Backup
                                            </a>

                                            <button class="btn btn-delete"
                                                onclick="confirmDelete(<?php echo (int) $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['clientname'])); ?>')">
                                                <i class="fas fa-trash"></i> Delete
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
                    <p style="font-size:18px; font-weight:600; margin-bottom:8px;">No finished clients yet</p>
                    <p style="font-size:14px;">Clients will appear here once all their stages are completed.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Delete Confirmation Modal -->
        <div id="deleteModal" class="modal">
            <div class="modal-content">
                <div class="delete-warning-icon">⚠️</div>
                <h2>Delete Client?</h2>
                <p id="deleteModalText">
                    This will permanently delete the client and <strong>all related data</strong>
                    including quotations, files, payments, site visits, and more.<br><br>
                    <strong>This action cannot be undone.</strong>
                </p>
                <div style="margin-bottom: 18px;">
                    <input type="text" id="deleteConfirmInput" placeholder="Type DELETE here"
                        oninput="document.getElementById('btnConfirmDelete').disabled = this.value !== 'DELETE';" style="
                        width: 100%;
                        padding: 10px 14px;
                        border: 2px solid #fca5a5;
                        border-radius: 8px;
                        font-size: 15px;
                        font-weight: 600;
                        text-align: center;
                        letter-spacing: 2px;
                        outline: none;
                        transition: border-color 0.2s;
                    " onkeyup="if(event.key==='Enter' && this.value==='DELETE') executeDelete();" />
                </div>
                <div class="delete-modal-actions">
                    <button class="btn-cancel-delete" onclick="closeDeleteModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button class="btn-confirm-delete" id="btnConfirmDelete" onclick="executeDelete()" disabled>
                        <i class="fas fa-trash"></i> Yes, Delete
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal for viewing details -->
        <div id="detailModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>
                        <i class="fas fa-info-circle" style="color: #3b82f6;"></i> Quotation Details
                    </h2>
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
                    tabActive.style.background = 'linear-gradient(135deg,#3b1f0f,#8a5a44)';
                    tabActive.style.color = 'white';
                    tabActive.style.borderColor = '#3b1f0f';
                    tabFinished.style.background = 'white';
                    tabFinished.style.color = '#555';
                    tabFinished.style.borderColor = '#e9ecef';
                } else {
                    activeTable.style.display = 'none';
                    finishedTable.style.display = '';
                    tabFinished.style.background = 'linear-gradient(135deg,#065f46,#10b981)';
                    tabFinished.style.color = 'white';
                    tabFinished.style.borderColor = '#065f46';
                    tabActive.style.background = 'white';
                    tabActive.style.color = '#555';
                    tabActive.style.borderColor = '#e9ecef';
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
                        <div class="detail-value" style="font-family: monospace; color: #3b82f6;">${quotation.reference_number}</div>
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
                <div style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #e9ecef;">
                    <a href="quotation-items?prefill=1&id=${quotation.id}&name=${encodeURIComponent(quotation.clientname)}&contact=${encodeURIComponent(quotation.contact)}&email=${encodeURIComponent(quotation.email)}&address=${encodeURIComponent(quotation.address)}"
                       class="btn btn-quotation" style="width: 100%; justify-content: center; padding: 12px;">
                        <i class="fas fa-file-invoice"></i>
                        <span>Open Quotation Form</span>
                    </a>
                </div>
            `;

                modal.classList.add('active');
            }

            function closeModal() {
                const modal = document.getElementById('detailModal');
                modal.classList.remove('active');
            }

            // Close modal when clicking outside
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
                document.getElementById('deleteModal').classList.add('active');
                setTimeout(() => document.getElementById('deleteConfirmInput').focus(), 100);
            }

            function closeDeleteModal() {
                document.getElementById('deleteModal').classList.remove('active');
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
                            // Remove the row from both tables without a full reload
                            document.querySelectorAll('tr').forEach(tr => {
                                if (tr.innerHTML.includes('data-client-id-' + _deleteClientId) ||
                                    tr.dataset.clientId == _deleteClientId) {
                                    tr.remove();
                                }
                            });
                            // Safest: just reload the page so counts update
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