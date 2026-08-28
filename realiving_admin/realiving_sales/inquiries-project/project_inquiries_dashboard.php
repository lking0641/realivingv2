<?php
//project_inquiries_dashboard.php (merged with project_inquiries_manage.php)
include $includes ['mainbody'];

require_role(['sales', 'superadmin']);

$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['role'] ?? '';

// Handle mark as responded
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_responded'])) {
  $inquiry_id = intval($_POST['inquiry_id']);
  $stmt = $conn->prepare("UPDATE project_inquiries SET status = 'responded', updated_at = NOW() WHERE id = ?");
  $stmt->bind_param("i", $inquiry_id);
  if ($stmt->execute()) {
    $_SESSION['success_message'] = "Inquiry marked as responded!";
  } else {
    $_SESSION['error_message'] = "Error updating status: " . $stmt->error;
  }
  $stmt->close();
  header("Location: " . BASE_URL . "project-inquiries-dashboard" . (isset($_GET['view']) ? '?view=list' : ''));
  exit();
}

// Handle general status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
  $inquiry_id = intval($_POST['inquiry_id']);
  $new_status = $_POST['status'];
  $stmt = $conn->prepare("UPDATE project_inquiries SET status = ?, updated_at = NOW() WHERE id = ?");
  $stmt->bind_param("si", $new_status, $inquiry_id);
  if ($stmt->execute()) {
    $_SESSION['success_message'] = "Inquiry status updated successfully!";
  } else {
    $_SESSION['error_message'] = "Error updating status: " . $stmt->error;
  }
  $stmt->close();
  header("Location: " . BASE_URL . "project-inquiries-dashboard" . '?view=list');
  exit();
}

// Handle delete inquiry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_inquiry'])) {
  $inquiry_id = intval($_POST['inquiry_id']);
  $stmt = $conn->prepare("DELETE FROM project_inquiries WHERE id = ?");
  $stmt->bind_param("i", $inquiry_id);
  if ($stmt->execute()) {
    $_SESSION['success_message'] = "Inquiry deleted successfully.";
  } else {
    $_SESSION['error_message'] = "Error: " . $stmt->error;
  }
  $stmt->close();
  header("Location: " . BASE_URL . "project-inquiries-dashboard" . '?view=list');
  exit();
}

// Stats
$role_where = ($admin_role !== 'superadmin') ? " WHERE assigned_to=$admin_id" : "";
$role_and = ($admin_role !== 'superadmin') ? " AND assigned_to=$admin_id" : "";

$total_inquiries = $conn->query("SELECT COUNT(*) as c FROM project_inquiries" . $role_where)->fetch_assoc()['c'];
$pending_inquiries = $conn->query("SELECT COUNT(*) as c FROM project_inquiries WHERE status='pending'" . $role_and)->fetch_assoc()['c'];
$responded_inquiries = $conn->query("SELECT COUNT(*) as c FROM project_inquiries WHERE status='responded'" . $role_and)->fetch_assoc()['c'];
$today_inquiries = $conn->query("SELECT COUNT(*) as c FROM project_inquiries WHERE DATE(created_at) = CURDATE()" . $role_and)->fetch_assoc()['c'];
$completed_count = $conn->query("SELECT COUNT(*) as c FROM project_inquiries WHERE status='completed'" . $role_and)->fetch_assoc()['c'];

// View toggle
$view = $_GET['view'] ?? 'dashboard';

// List view filters
$search = $_GET['search'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
$filter_active = isset($_GET['filter_active']);

$sql = "SELECT pi.*, acc.full_name as sales_name, p.title as project_title, p.address as project_address, p.id as project_page_id
        FROM project_inquiries pi
        LEFT JOIN account acc ON pi.assigned_to = acc.id
        LEFT JOIN project p ON pi.project_id = p.id
        WHERE 1=1";

if ($admin_role !== 'superadmin')
  $sql .= " AND pi.assigned_to = $admin_id";
if (!empty($search)) {
  $s = $conn->real_escape_string($search);
  $sql .= " AND (pi.name LIKE '%$s%' OR pi.email LIKE '%$s%' OR pi.phone LIKE '%$s%' OR pi.location LIKE '%$s%')";
}
if (!empty($filter_status))
  $sql .= " AND pi.status = '" . $conn->real_escape_string($filter_status) . "'";
if ($filter_active)
  $sql .= " AND pi.status NOT IN ('completed', 'cancelled')";
$sql .= " ORDER BY pi.created_at DESC";
$all_inquiries = $conn->query($sql);

// Dashboard: recent (not completed/cancelled)
$recent_sql = "SELECT pi.*, acc.full_name as sales_name, p.title as project_title, p.address as project_address, p.id as project_page_id
               FROM project_inquiries pi
               LEFT JOIN account acc ON pi.assigned_to = acc.id
               LEFT JOIN project p ON pi.project_id = p.id
               WHERE pi.status NOT IN ('completed','cancelled')"
  . ($admin_role !== 'superadmin' ? " AND pi.assigned_to=$admin_id" : "")
  . " ORDER BY pi.created_at DESC LIMIT 10";
$recent_inquiries = $conn->query($recent_sql);

// Dashboard: priority/pending
$priority_sql = "SELECT pi.*, p.title as project_title
                 FROM project_inquiries pi
                 LEFT JOIN project p ON pi.project_id = p.id
                 WHERE pi.status = 'pending'"
  . ($admin_role !== 'superadmin' ? " AND pi.assigned_to=$admin_id" : "")
  . " ORDER BY pi.created_at DESC LIMIT 5";
$priority_inquiries = $conn->query($priority_sql);

// Inquiry type stats
$type_stats_sql = "SELECT inquiry_type, COUNT(*) as count FROM project_inquiries"
  . ($admin_role !== 'superadmin' ? " WHERE assigned_to=$admin_id" : "")
  . " GROUP BY inquiry_type ORDER BY count DESC";
$type_stats = $conn->query($type_stats_sql);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Project Inquiries — Realiving</title>
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

  --teal: #2C5F5D;
  --teal-bg: #E7F0EF;
  --teal-border: #C3D9D7;

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
      font-family: 'DM Sans', sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
    }

    .app-wrap {
      max-width: 1380px;
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
      padding: 18px 28px;
      margin-bottom: 24px;
      box-shadow: var(--shadow-md);
    }

    .top-bar-left {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .top-bar-nav {
      display: flex;
      gap: 4px;
    }

    .nav-btn {
      padding: 8px 18px;
      border-radius: 30px;
      font-size: 13.5px;
      font-weight: 500;
      color: rgba(255, 255, 255, 0.7);
      border: none;
      background: transparent;
      cursor: pointer;
      transition: all .2s;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 7px;
    }

    .nav-btn:hover {
      color: #fff;
      background: rgba(255, 255, 255, 0.12);
    }

    .nav-btn.active {
      color: var(--brand);
      background: #fff;
      font-weight: 600;
    }

    /* STATS ROW */
    .stats-row {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 14px;
      margin-bottom: 24px;
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

    .tile-pending {
      --tile-color: #8A6100;
    }

    .tile-responded {
      --tile-color: #1F6F43;
    }

    .tile-today {
      --tile-color: #46424F;
    }

    .tile-done {
      --tile-color: #0B0B0B;
    }

    /* ALERTS */
    .alert {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 18px;
      border-radius: var(--radius-sm);
      margin-bottom: 18px;
      font-size: 14px;
      font-weight: 500;
    }

    .alert-success {
      background: var(--success-bg);
      color: var(--success);
      border: 1px solid #b7e4c7;
    }

    .alert-error {
      background: var(--danger-bg);
      color: var(--danger);
      border: 1px solid #fca5a5;
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
    }

    .section-head h2 {
      font-family: 'Inter', sans-serif;
      font-size: 16px;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 9px;
    }

    .section-body {
      padding: 20px 24px;
    }

    /* DASHBOARD GRID */
    .dash-grid {
      display: grid;
      grid-template-columns: 1fr 340px;
      gap: 20px;
    }

    /* INQUIRY CARD */
    .inq-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .inq-card {
      display: flex;
      align-items: flex-start;
      gap: 14px;
      padding: 14px 16px;
      border-radius: var(--radius-sm);
      background: var(--surface2);
      border: 1.5px solid var(--border);
      transition: border-color .2s, background .2s;
    }

    .inq-card:hover {
      border-color: var(--brand-light);
      background: var(--hover-bg);
    }

    .inq-info {
      flex: 1;
    }

    .inq-name {
      font-weight: 600;
      font-size: 14.5px;
      color: var(--text);
    }

    .inq-meta {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 4px;
      font-size: 12.5px;
      color: var(--text-muted);
    }

    .inq-meta span {
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .inq-actions {
      display: flex;
      gap: 6px;
      align-items: center;
      flex-wrap: wrap;
      margin-top: 8px;
    }

    /* BADGE */
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

    .badge-pending {
      background: var(--warning-bg);
      color: var(--warning);
    }

    .badge-responded {
      background: var(--success-bg);
      color: var(--success);
    }

    .badge-completed {
      background: var(--info-bg);
      color: var(--info);
    }

    .badge-resolved {
      background: var(--purple-bg);
      color: var(--purple);
    }

    .badge-cancelled {
      background: var(--danger-bg);
      color: var(--danger);
    }

    .badge-project {
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

    .btn-sm {
      padding: 5px 12px;
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

    .btn-danger {
      background: var(--danger-bg);
      color: var(--danger);
      border: 1.5px solid var(--danger-border);
    }

    .btn-danger:hover {
      background: var(--danger);
      color: #fff;
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

    .btn-purple {
      background: var(--purple-bg);
      color: var(--purple);
      border: 1.5px solid var(--purple-border);
    }

    .btn-purple:hover {
      background: var(--purple);
      color: #fff;
    }

    .btn-teal {
      background: var(--teal-bg);
      color: var(--teal);
      border: 1.5px solid var(--teal-border);
    }

    .btn-teal:hover {
      background: var(--teal);
      color: #fff;
    }

    .btn-icon {
      padding: 5px 9px;
      font-size: 12px;
    }

    /* keep actions column from wrapping */
    .inq-table td:last-child {
      white-space: nowrap;
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
      padding: 8px 12px;
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

    .qtag {
      padding: 5px 13px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      text-decoration: none;
      border: 1.5px solid transparent;
      transition: all .18s;
      cursor: pointer;
    }

    .qtag-all {
      border-color: var(--border);
      color: var(--text-muted);
      background: var(--surface);
    }

    .qtag-all:hover,
    .qtag-all.active {
      border-color: var(--brand);
      color: var(--brand);
      background: var(--hover-bg);
    }

    .qtag-active {
      border-color: var(--success-border);
      color: var(--success);
      background: var(--success-bg);
    }

    .qtag-active:hover {
      background: var(--success);
      color: #fff;
    }

    .qtag-done {
      border-color: var(--info-border);
      color: var(--info);
      background: var(--info-bg);
    }

    .qtag-done:hover {
      background: var(--info);
      color: #fff;
    }

    .qtag-project {
      border-color: var(--purple);
      color: var(--purple);
      background: var(--purple-bg);
    }

    .qtag-project:hover {
      background: var(--purple);
      color: #fff;
    }

    /* TABLE */
    .inq-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13.5px;
    }

    .inq-table thead th {
      padding: 11px 16px;
      text-align: left;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
      color: var(--text-muted);
      background: var(--surface2);
      border-bottom: 1.5px solid var(--border);
    }

    .inq-table tbody tr {
      border-bottom: 1px solid var(--border);
      transition: background .15s;
    }

    .inq-table tbody tr:hover {
      background: var(--hover-bg);
    }

    .inq-table td {
      padding: 13px 16px;
      vertical-align: top;
    }

    .td-name {
      font-weight: 600;
      font-size: 14px;
    }

    .td-sub {
      font-size: 12px;
      color: var(--text-muted);
      margin-top: 2px;
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
      max-width: 620px;
      width: 92%;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 20px 60px rgba(0, 0, 0, .22);
      animation: modalIn .22s ease;
    }

    .modal-box.modal-lg {
      max-width: 720px;
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
      margin-bottom: 22px;
    }

    .modal-head h3 {
      font-family: 'Inter', sans-serif;
      font-size: 18px;
      font-weight: 700;
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

    .form-group label {
      display: block;
      font-size: 12px;
      font-weight: 600;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: .4px;
      margin-bottom: 6px;
    }

    .form-control {
      width: 100%;
      padding: 10px 13px;
      border: 1.5px solid var(--border);
      border-radius: var(--radius-sm);
      font-size: 13.5px;
      font-family: inherit;
      color: var(--text);
      background: var(--surface2);
      transition: border-color .18s;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--brand);
      background: #fff;
    }

    .form-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      margin-top: 22px;
      padding-top: 18px;
      border-top: 1.5px solid var(--border);
    }

    /* DETAIL GRID */
    .detail-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }

    .detail-item label {
      font-size: 11px;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: .4px;
      display: block;
      margin-bottom: 4px;
    }

    .detail-item p {
      font-size: 14px;
      font-weight: 500;
      color: var(--text);
    }

    /* RIGHT COLUMN ITEMS */
    .priority-item {
      padding: 12px;
      background: var(--surface2);
      border: 1.5px solid var(--warning-border);
      border-radius: var(--radius-sm);
      margin-bottom: 10px;
    }

    .priority-item:last-child {
      margin-bottom: 0;
    }

    .type-stat-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 12px;
      background: var(--surface2);
      border-radius: var(--radius-sm);
      margin-bottom: 8px;
    }

    .type-stat-item:last-child {
      margin-bottom: 0;
    }

    .type-count {
      background: var(--brand);
      color: #fff;
      padding: 3px 11px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 700;
    }

    .empty-state {
      text-align: center;
      padding: 48px 20px;
      color: var(--text-muted);
    }

    .empty-state i {
      font-size: 40px;
      opacity: .3;
      display: block;
      margin-bottom: 12px;
    }

    @media(max-width:1100px) {
      .stats-row {
        grid-template-columns: repeat(3, 1fr);
      }

      .dash-grid {
        grid-template-columns: 1fr;
      }
    }

    @media(max-width:700px) {
      .stats-row {
        grid-template-columns: repeat(2, 1fr);
      }

      .filters-bar {
        flex-direction: column;
      }

      .detail-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>

<body>
  <div class="app-wrap">

    <!-- TOP BAR -->
    <div class="top-bar">
      <div class="top-bar-left">
        <div class="top-bar-nav">
          <a href="?view=dashboard" class="nav-btn <?php echo $view === 'dashboard' ? 'active' : ''; ?>">
            <i class="fas fa-chart-pie"></i> Dashboard
          </a>
          <a href="?view=list" class="nav-btn <?php echo $view === 'list' ? 'active' : ''; ?>">
            <i class="fas fa-list"></i> All Inquiries
          </a>
        </div>
      </div>
      <div style="display:flex;gap:10px;">
        <a href="project-inquiries-clients" class="nav-btn" style="position:relative;">
          <i class="fas fa-user-plus"></i> Convert to Clients
          <?php if ($responded_inquiries > 0): ?>
            <span
              style="position:absolute;top:4px;right:4px;background:#fff;color:var(--brand);border-radius:50%;width:18px;height:18px;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;"><?php echo $responded_inquiries; ?></span>
          <?php endif; ?>
        </a>
        <a href="projects" target="_blank" class="nav-btn"><i class="fas fa-external-link-alt"></i> View
          Projects</a>
        <?php if ($pending_inquiries > 0): ?>
          <button onclick="document.getElementById('pendingPopup').classList.add('open')" class="nav-btn"
            style="position:relative;">
            <i class="fas fa-hourglass-half"></i> Pending
            <span
              style="position:absolute;top:4px;right:4px;background:#fff;color:var(--brand);border-radius:50%;width:18px;height:18px;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;"><?php echo $pending_inquiries; ?></span>
          </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- ALERTS -->
    <?php if (isset($_SESSION['success_message'])): ?>
      <div class="alert alert-success"><i class="fas fa-check-circle"></i>
        <?php echo $_SESSION['success_message'];
        unset($_SESSION['success_message']); ?>
      </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
      <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i>
        <?php echo $_SESSION['error_message'];
        unset($_SESSION['error_message']); ?>
      </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats-row">
      <div class="stat-tile tile-total"> <i class="fas fa-building ico"></i>
        <div class="num"><?php echo $total_inquiries; ?></div>
        <div class="lbl">Total</div>
      </div>
      <div class="stat-tile tile-pending"> <i class="fas fa-hourglass-half ico"></i>
        <div class="num"><?php echo $pending_inquiries; ?></div>
        <div class="lbl">Pending</div>
      </div>
      <div class="stat-tile tile-responded"><i class="fas fa-check-circle ico"></i>
        <div class="num"><?php echo $responded_inquiries; ?></div>
        <div class="lbl">Responded</div>
      </div>
      <div class="stat-tile tile-today"> <i class="fas fa-calendar-day ico"></i>
        <div class="num"><?php echo $today_inquiries; ?></div>
        <div class="lbl">Today</div>
      </div>
      <div class="stat-tile tile-done"> <i class="fas fa-trophy ico"></i>
        <div class="num"><?php echo $completed_count; ?></div>
        <div class="lbl">Completed</div>
      </div>
    </div>

    <?php if ($view === 'dashboard'): ?>
      <!-- ===================== DASHBOARD VIEW ===================== -->
      <div class="dash-grid">
        <div>
          <!-- Recent Inquiries -->
          <div class="section-card">
            <div class="section-head">
              <h2><i class="fas fa-building" style="color:var(--info);"></i> Recent Project Inquiries
                <span
                  style="background:var(--purple-bg);color:var(--purple);padding:3px 10px;border-radius:20px;font-size:13px;font-family:inherit;font-weight:600;"><?php echo $recent_inquiries->num_rows; ?></span>
              </h2>
              <a href="?view=list" class="btn btn-sm btn-outline">View All</a>
            </div>
            <div class="section-body">
              <?php if ($recent_inquiries->num_rows > 0): ?>
                <div class="inq-list">
                  <?php while ($inq = $recent_inquiries->fetch_assoc()): ?>
                    <div class="inq-card">
                      <div class="inq-info" style="flex:1;">
                        <div class="inq-name"><?php echo htmlspecialchars($inq['name']); ?></div>
                        <div class="inq-meta">
                          <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($inq['email']); ?></span>
                          <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($inq['phone']); ?></span>
                          <?php if ($inq['location']): ?><span><i class="fas fa-map-marker-alt"></i>
                              <?php echo htmlspecialchars(substr($inq['location'], 0, 35)) . (strlen($inq['location']) > 35 ? '…' : ''); ?></span><?php endif; ?>
                        </div>
                        <?php if ($inq['project_title']): ?>
                          <div style="font-size:13px;color:var(--text);margin-top:4px;font-weight:500;"><i
                              class="fas fa-building" style="opacity:.5;"></i>
                            <?php echo htmlspecialchars($inq['project_title']); ?></div>
                        <?php endif; ?>
                        <?php if ($inq['project_address']): ?>
                          <div style="font-size:12px;color:var(--text-muted);margin-top:2px;"><i class="fas fa-map-marker-alt"
                              style="opacity:.5;"></i>
                            <?php echo htmlspecialchars(substr($inq['project_address'], 0, 80)) . (strlen($inq['project_address']) > 80 ? '…' : ''); ?>
                          </div><?php endif; ?>
                        <?php if ($admin_role === 'superadmin' && $inq['sales_name']): ?>
                          <div class="inq-meta" style="margin-top:3px;"><span><i class="fas fa-user"></i>
                              <?php echo htmlspecialchars($inq['sales_name']); ?></span></div><?php endif; ?>
                        <div class="inq-actions">
                          <span class="badge badge-<?php echo $inq['status']; ?>"><?php echo $inq['status']; ?></span>
                          <button onclick="openDetail(<?php echo htmlspecialchars(json_encode($inq)); ?>)"
                            class="btn btn-sm btn-teal"><i class="fas fa-eye"></i> View</button>
                          <button onclick="openStatus(<?php echo $inq['id']; ?>,'<?php echo $inq['status']; ?>')"
                            class="btn btn-sm btn-outline"><i class="fas fa-pen"></i> Update</button>
                          <?php if ($inq['status'] === 'pending'): ?>
                            <form method="POST" style="display:inline;">
                              <input type="hidden" name="inquiry_id" value="<?php echo $inq['id']; ?>">
                              <input type="hidden" name="mark_responded" value="1">
                              <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-check-circle"></i> Mark
                                Responded</button>
                            </form>
                          <?php endif; ?>
                          <?php if ($inq['project_page_id']): ?>
                            <a href="view-projects?id=<?php echo $inq['project_page_id']; ?>" target="_blank"
                              class="btn btn-sm btn-purple"><i class="fas fa-external-link-alt"></i> Project</a>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  <?php endwhile; ?>
                </div>
              <?php else: ?>
                <div class="empty-state"><i class="fas fa-building"></i>
                  <p>No active project inquiries</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- RIGHT COLUMN -->
        <div>
          <!-- Needs Attention -->
          <div class="section-card" style="margin-bottom:20px;">
            <div class="section-head">
              <h2><i class="fas fa-exclamation-circle" style="color:var(--warning);"></i> Needs Attention</h2>
            </div>
            <div class="section-body" style="padding:14px 20px;">
              <?php if ($priority_inquiries->num_rows > 0): ?>
                <?php while ($inq = $priority_inquiries->fetch_assoc()): ?>
                  <div class="priority-item">
                    <div style="font-weight:600;font-size:13.5px;"><?php echo htmlspecialchars($inq['name']); ?></div>
                    <?php if ($inq['project_title']): ?>
                      <div style="font-size:12px;color:var(--brand-mid);margin-top:2px;font-weight:500;"><i
                          class="fas fa-building"></i> <?php echo htmlspecialchars($inq['project_title']); ?></div>
                    <?php endif; ?>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;"><i class="fas fa-envelope"></i>
                      <?php echo htmlspecialchars($inq['email']); ?></div>
                    <div style="margin-top:5px;display:flex;align-items:center;justify-content:space-between;">
                      <span class="badge badge-pending">Pending</span>
                      <span
                        style="font-size:11px;color:var(--text-muted);"><?php echo date('M d, Y', strtotime($inq['created_at'])); ?></span>
                    </div>
                  </div>
                <?php endwhile; ?>
              <?php else: ?>
                <div class="empty-state" style="padding:24px 0;"><i class="fas fa-check-circle"></i>
                  <p>All caught up!</p>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Inquiry Type Stats -->
          <div class="section-card" style="margin-bottom:20px;">
            <div class="section-head">
              <h2><i class="fas fa-chart-bar" style="color:var(--success);"></i> Inquiry Types</h2>
            </div>
            <div class="section-body" style="padding:14px 20px;">
              <?php if ($type_stats->num_rows > 0): ?>
                <?php while ($stat = $type_stats->fetch_assoc()): ?>
                  <div class="type-stat-item">
                    <span style="font-size:13.5px;font-weight:500;">🏗️
                      <?php echo htmlspecialchars($stat['inquiry_type']); ?></span>
                    <span class="type-count"><?php echo $stat['count']; ?></span>
                  </div>
                <?php endwhile; ?>
              <?php else: ?>
                <div class="empty-state" style="padding:20px 0;"><i class="fas fa-chart-bar"></i>
                  <p style="font-size:13px;">No data yet</p>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Quick Actions -->
          <div class="section-card">
            <div class="section-head">
              <h2><i class="fas fa-bolt" style="color:var(--warning);"></i> Quick Actions</h2>
            </div>
            <div class="section-body" style="display:flex;flex-direction:column;gap:10px;">
              <a href="?view=list" class="btn btn-outline" style="justify-content:flex-start;"><i class="fas fa-list"></i>
                All Inquiries</a>
              <a href="?view=list&filter_active=1" class="btn btn-outline"
                style="justify-content:flex-start;color:var(--success);border-color:var(--success-border);"><i class="fas fa-check-circle"></i>
                Active Only</a>
              <a href="?view=list&filter_status=completed" class="btn btn-outline"
                style="justify-content:flex-start;color:var(--info);border-color:var(--info-border);"><i class="fas fa-trophy"></i>
                Completed</a>
              <a href="../../projects" target="_blank" class="btn btn-outline"
                style="justify-content:flex-start;color:var(--purple);border-color:var(--purple-border);"><i
                  class="fas fa-building"></i> View All Projects</a>
              <a href="project-inquiries-clients" class="btn btn-primary" style="justify-content:flex-start;"><i
                  class="fas fa-user-plus"></i> Convert to Client</a>
            </div>
          </div>
        </div>
      </div>

    <?php else: ?>
      <!-- ===================== LIST VIEW ===================== -->
      <div class="section-card">
        <div class="section-head">
          <h2><i class="fas fa-building" style="color:var(--brand-light);"></i> All Project Inquiries
            <?php if ($filter_active): ?>
              <span class="badge badge-responded">Active Only</span>
            <?php elseif ($filter_status === 'completed'): ?>
              <span class="badge badge-completed">Completed</span>
            <?php endif; ?>
          </h2>
          <span style="font-size:13px;color:var(--text-muted);"><?php echo $all_inquiries->num_rows; ?> records</span>
        </div>

        <!-- Filters -->
        <form method="GET">
          <input type="hidden" name="view" value="list">
          <div class="filters-bar">
            <div class="filter-group">
              <label>Search</label>
              <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                placeholder="Name, email, phone, location…" class="filter-input" style="width:210px;">
            </div>
            <div class="filter-group">
              <label>Status</label>
              <select name="filter_status" class="filter-input">
                <option value="">All Statuses</option>
                <?php foreach (['pending', 'responded', 'completed', 'cancelled'] as $s): ?>
                  <option value="<?php echo $s; ?>" <?php echo $filter_status === $s ? 'selected' : ''; ?>>
                    <?php echo ucfirst($s); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="filter-group" style="justify-content:flex-end;gap:8px;flex-direction:row;align-items:flex-end;">
              <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
              <a href="?view=list" class="btn btn-outline btn-sm"><i class="fas fa-redo"></i></a>
            </div>
          </div>
          <div
            style="padding:12px 24px;background:var(--surface2);border-bottom:1.5px solid var(--border);display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <span
              style="font-size:12px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Quick:</span>
            <a href="?view=list"
              class="qtag qtag-all <?php echo (!$filter_active && !$filter_status) ? 'active' : ''; ?>">All</a>
            <a href="?view=list&filter_active=1" class="qtag qtag-active <?php echo $filter_active ? 'active' : ''; ?>">✅
              Active</a>
            <a href="?view=list&filter_status=completed"
              class="qtag qtag-done <?php echo $filter_status === 'completed' ? 'active' : ''; ?>">🎉 Completed</a>
          </div>
        </form>

        <!-- Table -->
        <?php if ($all_inquiries->num_rows > 0): ?>
          <div style="overflow-x:auto;">
            <table class="inq-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Client</th>
                  <th>Contact</th>
                  <th>Project</th>
                  <th>Type</th>
                  <th>Status</th>
                  <?php if ($admin_role === 'superadmin'): ?>
                    <th>Assigned</th><?php endif; ?>
                  <th>Created</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($inq = $all_inquiries->fetch_assoc()): ?>
                  <tr>
                    <td><span style="font-size:12px;color:var(--text-muted);">#<?php echo $inq['id']; ?></span></td>
                    <td>
                      <div class="td-name"><?php echo htmlspecialchars($inq['name']); ?></div>
                      <?php if ($inq['location']): ?>
                        <div class="td-sub"><i class="fas fa-map-marker-alt" style="opacity:.5;"></i>
                          <?php echo htmlspecialchars(substr($inq['location'], 0, 40)) . (strlen($inq['location']) > 40 ? '…' : ''); ?>
                        </div><?php endif; ?>
                    </td>
                    <td>
                      <div class="td-sub"><i class="fas fa-envelope" style="opacity:.5;"></i>
                        <?php echo htmlspecialchars($inq['email']); ?></div>
                      <div class="td-sub"><i class="fas fa-phone" style="opacity:.5;"></i>
                        <?php echo htmlspecialchars($inq['phone']); ?></div>
                    </td>
                    <td>
                      <?php if ($inq['project_title']): ?>
                        <div style="font-weight:600;font-size:13.5px;color:var(--purple);">
                          <?php echo htmlspecialchars($inq['project_title']); ?>
                        </div>
                        <?php if ($inq['project_address']): ?>
                          <div class="td-sub">
                            <?php echo htmlspecialchars(substr($inq['project_address'], 0, 40)) . (strlen($inq['project_address']) > 40 ? '…' : ''); ?>
                          </div><?php endif; ?>
                      <?php else: ?><span class="td-sub">—</span><?php endif; ?>
                    </td>
                    <td class="td-sub"><?php echo htmlspecialchars($inq['inquiry_type'] ?? '—'); ?></td>
                    <td><span class="badge badge-<?php echo $inq['status']; ?>"><?php echo ucfirst($inq['status']); ?></span>
                    </td>
                    <?php if ($admin_role === 'superadmin'): ?>
                      <td class="td-sub"><?php echo htmlspecialchars($inq['sales_name'] ?? '—'); ?></td>
                    <?php endif; ?>
                    <td class="td-sub"><?php echo date('M d, Y', strtotime($inq['created_at'])); ?></td>
                    <td>
                      <div style="display:flex;gap:4px;flex-wrap:nowrap;align-items:center;">
                        <button onclick="openDetail(<?php echo htmlspecialchars(json_encode($inq)); ?>)"
                          class="btn btn-sm btn-teal btn-icon" title="View Details"><i class="fas fa-eye"></i></button>
                        <button onclick="openStatus(<?php echo $inq['id']; ?>,'<?php echo $inq['status']; ?>')"
                          class="btn btn-sm btn-outline btn-icon" title="Update Status"
                          style="color:var(--success);border-color:var(--success-border);"><i class="fas fa-pen"></i></button>
                        <?php if ($inq['status'] === 'pending'): ?>
                          <form method="POST" style="display:inline;margin:0;">
                            <input type="hidden" name="inquiry_id" value="<?php echo $inq['id']; ?>">
                            <input type="hidden" name="mark_responded" value="1">
                            <button type="submit" class="btn btn-sm btn-success btn-icon" title="Mark Responded"><i
                                class="fas fa-check-circle"></i></button>
                          </form>
                        <?php endif; ?>
                        <?php if ($inq['project_page_id']): ?>
                          <a href="view-projects?id=<?php echo $inq['project_page_id']; ?>" target="_blank"
                            class="btn btn-sm btn-purple btn-icon" title="View Project"><i
                              class="fas fa-external-link-alt"></i></a>
                        <?php endif; ?>
                        <button
                          onclick="confirmDelete(<?php echo $inq['id']; ?>,'<?php echo htmlspecialchars($inq['name']); ?>')"
                          class="btn btn-sm btn-danger btn-icon" title="Delete"><i class="fas fa-trash"></i></button>
                      </div>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty-state"><i class="fas fa-inbox"></i>
            <p>No project inquiries found</p>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div><!-- /app-wrap -->

  <!-- VIEW DETAIL MODAL -->
  <div class="modal-bg" id="detailModal">
    <div class="modal-box modal-lg">
      <div class="modal-head">
        <h3><i class="fas fa-building" style="color:var(--purple);"></i> Inquiry Details</h3>
        <button class="modal-close" onclick="document.getElementById('detailModal').classList.remove('open')"><i
            class="fas fa-times"></i></button>
      </div>
      <div id="detailContent"></div>
    </div>
  </div>

  <!-- STATUS MODAL -->
  <div class="modal-bg" id="statusModal">
    <div class="modal-box">
      <div class="modal-head">
        <h3><i class="fas fa-pen" style="color:var(--success);"></i> Update Status</h3>
        <button class="modal-close" onclick="document.getElementById('statusModal').classList.remove('open')"><i
            class="fas fa-times"></i></button>
      </div>
      <form method="POST">
        <input type="hidden" name="update_status" value="1">
        <input type="hidden" name="inquiry_id" id="status_id">
        <div class="form-group">
          <label>New Status</label>
          <select name="status" id="status_val" class="form-control" required>
            <option value="pending">Pending</option>
            <option value="responded">Responded</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>
        <div class="form-actions">
          <button type="button" class="btn btn-outline"
            onclick="document.getElementById('statusModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Update</button>
        </div>
      </form>
    </div>
  </div>

  <!-- DELETE CONFIRM MODAL -->
  <div class="modal-bg" id="deleteModal">
    <div class="modal-box" style="max-width:440px;">
      <div class="modal-head">
        <h3><i class="fas fa-trash" style="color:var(--danger);"></i> Delete Inquiry</h3>
        <button class="modal-close" onclick="document.getElementById('deleteModal').classList.remove('open')"><i
            class="fas fa-times"></i></button>
      </div>
      <p style="font-size:15px;color:var(--text);margin-bottom:8px;">Are you sure you want to delete the inquiry from
        <strong id="delete_name"></strong>?
      </p>
      <p style="font-size:13px;color:var(--danger);">This action cannot be undone.</p>
      <form method="POST" id="deleteForm">
        <input type="hidden" name="delete_inquiry" value="1">
        <input type="hidden" name="inquiry_id" id="delete_id">
        <div class="form-actions">
          <button type="button" class="btn btn-outline"
            onclick="document.getElementById('deleteModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
        </div>
      </form>
    </div>
  </div>

  <!-- PENDING POPUP -->
  <div class="modal-bg" id="pendingPopup">
    <div class="modal-box" style="max-width:520px;">
      <div class="modal-head">
        <h3><i class="fas fa-hourglass-half" style="color:var(--warning);"></i> Pending Inquiries</h3>
        <button class="modal-close" onclick="document.getElementById('pendingPopup').classList.remove('open')"><i
            class="fas fa-times"></i></button>
      </div>
      <p style="font-size:14px;color:var(--text-muted);margin-bottom:16px;">These project inquiries are still waiting
        for action:</p>
      <div style="display:flex;flex-direction:column;gap:10px;max-height:340px;overflow-y:auto;">
        <?php
        $pp_sql = "SELECT pi.*, p.title as project_title FROM project_inquiries pi LEFT JOIN project p ON pi.project_id = p.id WHERE pi.status='pending'" . ($admin_role !== 'superadmin' ? " AND pi.assigned_to=$admin_id" : "") . " ORDER BY pi.created_at ASC";
        $pp = $conn->query($pp_sql);
        if ($pp->num_rows > 0):
          while ($c = $pp->fetch_assoc()):
            ?>
            <div
              style="background:var(--warning-bg);border:1.5px solid var(--warning-border);border-radius:var(--radius-sm);padding:12px 14px;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                  <div style="font-weight:600;font-size:14px;"><?php echo htmlspecialchars($c['name']); ?></div>
                  <?php if ($c['project_title']): ?>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:3px;"><i class="fas fa-building"></i>
                      <?php echo htmlspecialchars($c['project_title']); ?></div><?php endif; ?>
                  <div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><i class="fas fa-clock"></i>
                    <?php echo date('M d, Y g:i A', strtotime($c['created_at'])); ?></div>
                </div>
                <button
                  onclick="openStatus(<?php echo $c['id']; ?>,'<?php echo $c['status']; ?>');document.getElementById('pendingPopup').classList.remove('open');"
                  class="btn btn-sm"
                  style="background:var(--warning-bg);color:var(--warning);border:1.5px solid var(--warning-border);flex-shrink:0;margin-left:10px;">
                  <i class="fas fa-pen"></i> Update
                </button>
              </div>
            </div>
          <?php endwhile; else: ?>
          <div class="empty-state" style="padding:24px 0;"><i class="fas fa-check-circle"></i>
            <p>No pending inquiries!</p>
          </div>
        <?php endif; ?>
      </div>
      <div class="form-actions" style="margin-top:16px;">
        <a href="?view=list&filter_status=pending" class="btn btn-primary btn-sm"><i class="fas fa-list"></i> View All
          Pending</a>
        <button class="btn btn-outline btn-sm"
          onclick="document.getElementById('pendingPopup').classList.remove('open')">Close</button>
      </div>
    </div>
  </div>

  <script>
    function confirmDelete(id, name) {
      document.getElementById('delete_id').value = id;
      document.getElementById('delete_name').textContent = name;
      document.getElementById('deleteModal').classList.add('open');
    }

    function openStatus(id, status) {
      document.getElementById('status_id').value = id;
      document.getElementById('status_val').value = status;
      document.getElementById('statusModal').classList.add('open');
    }

    function openDetail(inqObj) {
      document.getElementById('detailContent').innerHTML = `
    <div style="text-align:center;padding:40px 20px;color:var(--text-muted);">
      <i class="fas fa-spinner fa-spin" style="font-size:28px;margin-bottom:12px;display:block;"></i>
      Loading details…
    </div>`;
      document.getElementById('detailModal').classList.add('open');

      const projectLink = inqObj.project_page_id
        ? `<a href="view-projects?id=${inqObj.project_page_id}" target="_blank"
         style="font-size:12px;color:var(--purple);text-decoration:none;display:inline-flex;align-items:center;gap:4px;margin-top:3px;">
         <i class="fas fa-external-link-alt"></i> View Project Page
       </a>` : '';

      const projectSection = inqObj.project_title ? `
    <div style="margin-top:14px;padding:12px;background:var(--purple-bg);border-radius:var(--radius-sm);border:1px solid var(--purple-border);">
      <label style="font-size:11px;font-weight:700;color:var(--purple);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:4px;">Related Project</label>
      <p style="font-size:14px;font-weight:600;color:var(--purple);">${inqObj.project_title}</p>
      ${inqObj.project_address ? `<p style="font-size:12px;color:var(--text-muted);margin-top:2px;"><i class="fas fa-map-marker-alt"></i> ${inqObj.project_address}</p>` : ''}
      ${projectLink}
    </div>` : '';

      document.getElementById('detailContent').innerHTML = `
    <div class="detail-grid">
      <div class="detail-item"><label>Name</label><p>${inqObj.name}</p></div>
      <div class="detail-item"><label>Email</label><p>${inqObj.email}</p></div>
      <div class="detail-item"><label>Phone</label><p>${inqObj.phone}</p></div>
      <div class="detail-item"><label>Location</label><p>${inqObj.location || '—'}</p></div>
      <div class="detail-item"><label>Inquiry Type</label><p>${inqObj.inquiry_type || '—'}</p></div>
      <div class="detail-item"><label>Status</label><p><span class="badge badge-${inqObj.status}">${inqObj.status}</span></p></div>
      <div class="detail-item"><label>Created</label><p>${new Date(inqObj.created_at).toLocaleString()}</p></div>
    </div>
    ${projectSection}
  `;
    }

    document.querySelectorAll('.modal-bg').forEach(m => {
      m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });
  </script>
</body>

</html>
<?php $conn->close(); ?>