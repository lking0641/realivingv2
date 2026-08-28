<?php
//appointment_dashboard.php (merged with appointment_manage.php)
include $includes['mainbody'];

require_role(['sales', 'superadmin']);

if (!isset($_SESSION['admin_id'])) {
  header("Location: ../../loginpage/index.php");
  exit();
}

$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['role'] ?? '';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
  $appointment_id = intval($_POST['appointment_id']);
  $new_status = $_POST['status'];
  $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?");
  $stmt->bind_param("si", $new_status, $appointment_id);
  if ($stmt->execute()) {
    $_SESSION['success_message'] = "Appointment status updated.";
  } else {
    $_SESSION['error_message'] = "Error: " . $stmt->error;
  }
  $stmt->close();
  header("Location: " . BASE_URL . 'appointment-dashboard' . (isset($_GET['view']) ? '?view=list' : ''));
  exit();
}

// Handle delete appointment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_appointment'])) {
  $appointment_id = intval($_POST['appointment_id']);
  $stmt = $conn->prepare("DELETE FROM appointments WHERE appointment_id = ?");
  $stmt->bind_param("i", $appointment_id);
  if ($stmt->execute()) {
    $_SESSION['success_message'] = "Appointment deleted.";
  } else {
    $_SESSION['error_message'] = "Error: " . $stmt->error;
  }
  $stmt->close();
  header("Location: " . BASE_URL . 'appointment-dashboard?view=list');
  exit();
}

// Handle add holiday
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_holiday'])) {
  $holiday_date = $_POST['holiday_date'];
  $holiday_name = trim($_POST['holiday_name']);
  if (!empty($holiday_date) && !empty($holiday_name)) {
    $stmt = $conn->prepare("INSERT IGNORE INTO holidays (holiday_date, holiday_name) VALUES (?, ?)");
    $stmt->bind_param("ss", $holiday_date, $holiday_name);
    if ($stmt->execute()) {
      $_SESSION['success_message'] = "Holiday '{$holiday_name}' added successfully.";
    } else {
      $_SESSION['error_message'] = "Error adding holiday.";
    }
    $stmt->close();
  }
  header("Location: " . BASE_URL . 'appointment-dashboard?view=dashboard');
  exit();
}

// Handle delete holiday
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_holiday'])) {
  $holiday_id = intval($_POST['holiday_id']);
  $stmt = $conn->prepare("DELETE FROM holidays WHERE id = ?");
  $stmt->bind_param("i", $holiday_id);
  if ($stmt->execute()) {
    $_SESSION['success_message'] = "Holiday removed.";
  }
  $stmt->close();
  header("Location: " . BASE_URL . 'appointment-dashboard?view=dashboard');
  exit();
}

// Handle reschedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reschedule'])) {
  $appointment_id = intval($_POST['appointment_id']);
  $new_date = $_POST['new_date'];
  $new_time = $_POST['new_time'];
  $stmt = $conn->prepare("UPDATE appointments SET preferred_date = ?, preferred_time = ?, status = 'confirmed' WHERE appointment_id = ?");
  $stmt->bind_param("ssi", $new_date, $new_time, $appointment_id);
  if ($stmt->execute()) {
    // Fetch updated appointment details para sa email
    $fetch = $conn->prepare("SELECT * FROM appointments WHERE appointment_id = ?");
    $fetch->bind_param("i", $appointment_id);
    $fetch->execute();
    $apt_data = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    // Send reschedule email
    if ($apt_data && !empty($apt_data['email'])) {
      define('MAILER_INCLUDED_ONLY', true);
      require_once ROOT_PATH . $routes['appointment-mailer']; // include function lang, hindi mag-execute yung POST handler
      sendAppointmentEmail($apt_data, $apt_data['email'], 'rescheduled');
    }

    $_SESSION['success_message'] = "Appointment rescheduled successfully. Notification sent to client.";
  } else {
    $_SESSION['error_message'] = "Error: " . $stmt->error;
  }
  $stmt->close();
  header("Location: " . BASE_URL . 'appointment-dashboard?view=list');
  exit();
}

// Stats
$role_filter = ($admin_role === 'superadmin') ? "" : " WHERE assigned_to = $admin_id";
$role_and = ($admin_role === 'superadmin') ? " WHERE " : " AND assigned_to = $admin_id AND ";
$role_and2 = ($admin_role === 'superadmin') ? " AND " : " AND assigned_to = $admin_id AND ";

$total_appointments = $conn->query("SELECT COUNT(*) as c FROM appointments" . $role_filter)->fetch_assoc()['c'];
$pending_appointments = $conn->query("SELECT COUNT(*) as c FROM appointments WHERE status='pending'" . ($admin_role !== 'superadmin' ? " AND assigned_to=$admin_id" : ""))->fetch_assoc()['c'];
$confirmed_appointments = $conn->query("SELECT COUNT(*) as c FROM appointments WHERE status='confirmed'" . ($admin_role !== 'superadmin' ? " AND assigned_to=$admin_id" : ""))->fetch_assoc()['c'];
$completed_appointments = $conn->query("SELECT COUNT(*) as c FROM appointments WHERE status='completed'" . ($admin_role !== 'superadmin' ? " AND assigned_to=$admin_id" : ""))->fetch_assoc()['c'];

$today_appointments = $conn->query("SELECT COUNT(*) as c FROM appointments WHERE DATE(preferred_date) = CURDATE()" . ($admin_role !== 'superadmin' ? " AND assigned_to=$admin_id" : ""))->fetch_assoc()['c'];

$overdue_base = "(DATE(preferred_date) < CURDATE() OR (DATE(preferred_date) = CURDATE() AND TIME(preferred_time) < CURTIME())) AND status IN ('pending','confirmed')";
$overdue_appointments = $conn->query("SELECT COUNT(*) as c FROM appointments WHERE $overdue_base" . ($admin_role !== 'superadmin' ? " AND assigned_to=$admin_id" : ""))->fetch_assoc()['c'];

// List view filters
$search = $_GET['search'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
$filter_service = $_GET['filter_service'] ?? '';
$filter_overdue = isset($_GET['filter_overdue']);
$filter_active = isset($_GET['filter_active']);
$view = $_GET['view'] ?? 'dashboard';

$sql = "SELECT a.*, acc.full_name as sales_name,
        CASE WHEN ($overdue_base) THEN 1 ELSE 0 END as is_overdue
        FROM appointments a
        LEFT JOIN account acc ON a.assigned_to = acc.id WHERE 1=1";

if ($admin_role !== 'superadmin')
  $sql .= " AND a.assigned_to = $admin_id";
if (!empty($search)) {
  $s = $conn->real_escape_string($search);
  $sql .= " AND (a.first_name LIKE '%$s%' OR a.last_name LIKE '%$s%' OR a.email LIKE '%$s%' OR a.phone LIKE '%$s%')";
}
if (!empty($filter_status))
  $sql .= " AND a.status = '" . $conn->real_escape_string($filter_status) . "'";
if (!empty($filter_service))
  $sql .= " AND a.service_type = '" . $conn->real_escape_string($filter_service) . "'";
if ($filter_overdue)
  $sql .= " AND ($overdue_base)";
if ($filter_active)
  $sql .= " AND a.status NOT IN ('completed','cancelled')";
// Converted appointments now live under their own tab, keep the main list focused on active leads
$sql .= " AND a.converted_to_client = 0";
$sql .= " ORDER BY CASE WHEN is_overdue=1 THEN 0 ELSE 1 END, a.preferred_date DESC, a.preferred_time DESC";
$all_appointments = $conn->query($sql);

// Converted appointments (their own tab, keeps the main list free of already-processed leads)
$converted_sql = "SELECT a.*, acc.full_name as sales_name
        FROM appointments a
        LEFT JOIN account acc ON a.assigned_to = acc.id
        WHERE a.converted_to_client = 1";
if ($admin_role !== 'superadmin')
  $converted_sql .= " AND a.assigned_to = $admin_id";
if (!empty($search)) {
  $s = $conn->real_escape_string($search);
  $converted_sql .= " AND (a.first_name LIKE '%$s%' OR a.last_name LIKE '%$s%' OR a.email LIKE '%$s%' OR a.phone LIKE '%$s%')";
}
if (!empty($filter_service))
  $converted_sql .= " AND a.service_type = '" . $conn->real_escape_string($filter_service) . "'";
$converted_sql .= " ORDER BY a.preferred_date DESC";
$converted_appointments = $conn->query($converted_sql);
$converted_total = $conn->query("SELECT COUNT(*) as c FROM appointments WHERE converted_to_client = 1" . ($admin_role !== 'superadmin' ? " AND assigned_to=$admin_id" : ""))->fetch_assoc()['c'];

// Today's appointments
$today_sql = "SELECT a.*, acc.full_name as sales_name FROM appointments a LEFT JOIN account acc ON a.assigned_to = acc.id WHERE DATE(a.preferred_date) = CURDATE() AND a.status != 'cancelled'" . ($admin_role !== 'superadmin' ? " AND a.assigned_to=$admin_id" : "") . " ORDER BY a.preferred_time ASC";
$today_details = $conn->query($today_sql);

// Upcoming
$upcoming_sql = "SELECT a.*, acc.full_name as sales_name FROM appointments a LEFT JOIN account acc ON a.assigned_to = acc.id WHERE a.preferred_date >= CURDATE() AND a.status NOT IN ('cancelled','completed')" . ($admin_role !== 'superadmin' ? " AND a.assigned_to=$admin_id" : "") . " ORDER BY a.preferred_date ASC, a.preferred_time ASC LIMIT 6";
$upcoming_appointments = $conn->query($upcoming_sql);

// Overdue details
$overdue_sql = "SELECT a.*, acc.full_name as sales_name FROM appointments a LEFT JOIN account acc ON a.assigned_to = acc.id WHERE ($overdue_base)" . ($admin_role !== 'superadmin' ? " AND a.assigned_to=$admin_id" : "") . " ORDER BY a.preferred_date DESC LIMIT 10";
$overdue_details = $conn->query($overdue_sql);

// Auto-delete past holidays
$conn->query("DELETE FROM holidays WHERE holiday_date < CURDATE()");

// Fetch upcoming holidays
$holidays_result = $conn->query("SELECT * FROM holidays WHERE holiday_date >= CURDATE() ORDER BY holiday_date ASC");
$holidays_list = [];
while ($h = $holidays_result->fetch_assoc()) {
  $holidays_list[] = $h;
}

// Convertible appointments (confirmed consultations not yet converted)
$convertible_sql = "SELECT a.*, acc.full_name as sales_name FROM appointments a LEFT JOIN account acc ON a.assigned_to = acc.id WHERE a.service_type = 'Consultation' AND a.converted_to_client = 0 AND a.status = 'confirmed'" . ($admin_role !== 'superadmin' ? " AND a.assigned_to=$admin_id" : "") . " ORDER BY a.preferred_date ASC";
$convertible_result = $conn->query($convertible_sql);
$convertible_count = $convertible_result->num_rows;
$convertible_result->data_seek(0); // reset pointer
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Appointments — Realiving</title>
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
      flex-wrap: wrap;
      gap: 12px;
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
      grid-template-columns: repeat(6, 1fr);
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

    .tile-confirmed {
      --tile-color: #1F6F43;
    }

    .tile-today {
      --tile-color: #46424F;
    }

    .tile-done {
      --tile-color: #0B0B0B;
    }

    .tile-overdue {
      --tile-color: #9B1C1C;
    }

    .tile-overdue .num {
      color: var(--danger);
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
      border: 1px solid var(--success-border);
    }

    .alert-error {
      background: var(--danger-bg);
      color: var(--danger);
      border: 1px solid var(--danger-border);
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

    .section-body {
      padding: 20px 24px;
    }

    /* DASHBOARD GRID */
    .dash-grid {
      display: grid;
      grid-template-columns: 1fr 340px;
      gap: 20px;
    }

    /* APPOINTMENT CARD */
    .apt-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .apt-card {
      display: flex;
      align-items: flex-start;
      gap: 14px;
      padding: 14px 16px;
      border-radius: var(--radius-sm);
      background: var(--surface2);
      border: 1.5px solid var(--border);
      transition: border-color .2s, background .2s;
    }

    .apt-card:hover {
      border-color: var(--brand-light);
      background: var(--hover-bg);
    }

    .apt-card.overdue {
      border-left: 3px solid var(--danger) !important;
      background: var(--danger-bg);
    }

    .apt-time-col {
      text-align: center;
      min-width: 48px;
    }

    .apt-time {
      font-family: 'Inter', sans-serif;
      font-size: 15px;
      font-weight: 700;
      color: var(--text);
      line-height: 1.1;
    }

    .apt-ampm {
      font-size: 10px;
      color: var(--text-muted);
      text-transform: uppercase;
    }

    .apt-info {
      flex: 1;
    }

    .apt-name {
      font-weight: 600;
      font-size: 14.5px;
      color: var(--text);
    }

    .apt-meta {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 4px;
      font-size: 12.5px;
      color: var(--text-muted);
    }

    .apt-meta span {
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .apt-actions {
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

    .badge-confirmed {
      background: var(--success-bg);
      color: var(--success);
    }

    .badge-completed {
      background: var(--info-bg);
      color: var(--info);
    }

    .badge-cancelled {
      background: var(--danger-bg);
      color: var(--danger);
    }

    .badge-draft {
      background: var(--surface2);
      color: var(--text-muted);
      border: 1px solid var(--border);
    }

    .badge-overdue {
      background: var(--danger-bg);
      color: var(--danger);
      border: 1px solid var(--danger-border);
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

    .btn-warning {
      background: var(--warning-bg);
      color: var(--warning);
      border: 1.5px solid var(--warning-border);
    }

    .btn-warning:hover {
      background: var(--warning);
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

    .btn-icon {
      padding: 6px 10px;
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

    .qtag-overdue {
      border-color: var(--danger-border);
      color: var(--danger);
      background: var(--danger-bg);
    }

    .qtag-overdue:hover {
      background: var(--danger);
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

    /* TABLE */
    .apt-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13.5px;
    }

    .apt-table thead th {
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

    .apt-table tbody tr {
      border-bottom: 1px solid var(--border);
      transition: background .15s;
    }

    .apt-table tbody tr:hover {
      background: var(--hover-bg);
    }

    .apt-table tbody tr.row-overdue {
      background: var(--danger-bg);
      border-left: 3px solid var(--danger);
    }

    .apt-table td {
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

    .overdue-tag {
      display: inline-flex;
      align-items: center;
      gap: 3px;
      font-size: 10.5px;
      font-weight: 700;
      color: var(--danger);
      background: var(--danger-bg);
      padding: 2px 7px;
      border-radius: 10px;
      margin-left: 6px;
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
      max-width: 580px;
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
        transform: translateY(16px) scale(.97);
      }

      to {
        opacity: 1;
        transform: none;
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
      font-size: 17px;
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

    /* FORM ELEMENTS */
    .form-row {
      display: grid;
      gap: 16px;
      margin-bottom: 16px;
    }

    .form-row.cols-2 {
      grid-template-columns: 1fr 1fr;
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

    /* EMPTY */
    .empty-state {
      text-align: center;
      padding: 48px 20px;
      color: var(--text-muted);
    }

    .empty-state i {
      font-size: 40px;
      opacity: .25;
      display: block;
      margin-bottom: 12px;
    }

    .empty-state p {
      font-size: 14px;
    }

    /* UPCOMING ITEM */
    .upcoming-item {
      display: flex;
      gap: 12px;
      align-items: flex-start;
      padding: 12px 0;
      border-bottom: 1px solid var(--border);
    }

    .upcoming-item:last-child {
      border-bottom: none;
    }

    .upc-date {
      background: var(--brand);
      color: #fff;
      border-radius: var(--radius-sm);
      padding: 6px 10px;
      text-align: center;
      min-width: 46px;
    }

    .upc-date .day {
      font-family: 'Inter', sans-serif;
      font-size: 18px;
      font-weight: 700;
      line-height: 1;
    }

    .upc-date .mon {
      font-size: 10px;
      text-transform: uppercase;
      opacity: .8;
    }

    .upc-info .name {
      font-weight: 600;
      font-size: 14px;
    }

    .upc-info .sub {
      font-size: 12px;
      color: var(--text-muted);
      margin-top: 2px;
    }

    /* OVERDUE BANNER */
    .overdue-banner {
      background: var(--danger-bg);
      border: 1.5px solid var(--danger-border);
      border-radius: var(--radius);
      padding: 14px 20px;
      margin-bottom: 18px;
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .overdue-banner i {
      color: var(--danger);
      font-size: 20px;
      flex-shrink: 0;
    }

    .overdue-banner strong {
      font-size: 14px;
      color: var(--danger);
    }

    .overdue-banner span {
      font-size: 13px;
      color: var(--danger);
    }

    /* HOLIDAY ITEM */
    .holiday-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 12px;
      background: var(--purple-bg);
      border: 1.5px solid var(--purple-border);
      border-radius: var(--radius-sm);
    }

    .holiday-item .name {
      font-weight: 600;
      font-size: 13.5px;
      color: var(--purple);
    }

    .holiday-item .meta {
      font-size: 12px;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .holiday-item .countdown {
      margin-left: 6px;
      font-size: 11px;
      color: var(--purple);
    }

    @keyframes adm-fade {
      from {
        opacity: 0;
        transform: translateY(8px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @media (prefers-reduced-motion: reduce) {
      * {
        animation: none !important;
      }
    }

    /* RESPONSIVE */
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

      .detail-grid,
      .form-row.cols-2 {
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
            <i class="fas fa-list"></i> All Appointments
          </a>
          <a href="?view=converted" class="nav-btn <?php echo $view === 'converted' ? 'active' : ''; ?>">
            <i class="fas fa-user-check"></i> Converted
            <span
              style="background:<?php echo $view === 'converted' ? 'var(--brand)' : 'rgba(255,255,255,.15)'; ?>;color:<?php echo $view === 'converted' ? '#fff' : 'rgba(255,255,255,.85)'; ?>;padding:1px 8px;border-radius:20px;font-size:11px;font-weight:700;"><?php echo $converted_total; ?></span>
          </a>
        </div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a href="appointment-clients" class="nav-btn"><i class="fas fa-user-plus"></i> Clients</a>
        <?php if ($pending_appointments > 0): ?>
          <button onclick="document.getElementById('pendingPopup').classList.add('open')" class="nav-btn"
            style="position:relative;">
            <i class="fas fa-hourglass-half"></i> Pending
            <span
              style="position:absolute;top:4px;right:4px;background:#fff;color:var(--brand);border-radius:50%;width:18px;height:18px;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;"><?php echo $pending_appointments; ?></span>
          </button>
        <?php endif; ?>
        <?php if ($convertible_count > 0): ?>
          <button onclick="document.getElementById('convertPopup').classList.add('open')" class="nav-btn"
            style="position:relative;">
            <i class="fas fa-user-plus"></i> Convert
            <span
              style="position:absolute;top:4px;right:4px;background:#fff;color:var(--brand);border-radius:50%;width:18px;height:18px;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;"><?php echo $convertible_count; ?></span>
          </button>
        <?php endif; ?>
        <a href="appointment" target="_blank" class="nav-btn"><i class="fas fa-external-link-alt"></i> Booking
          Page</a>
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
      <div class="stat-tile tile-total"><i class="fas fa-calendar-alt ico"></i>
        <div class="num"><?php echo $total_appointments; ?></div>
        <div class="lbl">Total</div>
      </div>
      <div class="stat-tile tile-pending"><i class="fas fa-hourglass-half ico"></i>
        <div class="num"><?php echo $pending_appointments; ?></div>
        <div class="lbl">Pending</div>
      </div>
      <div class="stat-tile tile-confirmed"><i class="fas fa-check-circle ico"></i>
        <div class="num"><?php echo $confirmed_appointments; ?></div>
        <div class="lbl">Confirmed</div>
      </div>
      <div class="stat-tile tile-today"><i class="fas fa-calendar-day ico"></i>
        <div class="num"><?php echo $today_appointments; ?></div>
        <div class="lbl">Today</div>
      </div>
      <div class="stat-tile tile-done"><i class="fas fa-trophy ico"></i>
        <div class="num"><?php echo $completed_appointments; ?></div>
        <div class="lbl">Completed</div>
      </div>
      <div class="stat-tile tile-overdue"><i class="fas fa-exclamation-triangle ico"></i>
        <div class="num"><?php echo $overdue_appointments; ?></div>
        <div class="lbl">Overdue</div>
      </div>
    </div>

    <!-- OVERDUE BANNER -->
    <?php if ($overdue_appointments > 0): ?>
      <div class="overdue-banner">
        <i class="fas fa-exclamation-triangle"></i>
        <div><strong><?php echo $overdue_appointments; ?> overdue
            appointment<?php echo $overdue_appointments > 1 ? 's' : ''; ?></strong>
          <span> — these are past their scheduled time and still pending/confirmed.</span>
        </div>
        <a href="?view=list&filter_overdue=1" class="btn btn-sm btn-danger" style="margin-left:auto;">View Overdue</a>
      </div>
    <?php endif; ?>

    <?php if ($view === 'dashboard'): ?>
      <!-- ===================== DASHBOARD VIEW ===================== -->
      <div class="dash-grid">
        <div>
          <!-- Today's Schedule -->
          <div class="section-card">
            <div class="section-head">
              <h2><i class="fas fa-calendar-day" style="color:var(--text-muted);"></i> Today's Schedule
                <span
                  style="background:var(--purple-bg);color:var(--purple);padding:3px 10px;border-radius:20px;font-size:13px;font-family:inherit;font-weight:600;"><?php echo $today_details->num_rows; ?></span>
              </h2>
              <a href="?view=list" class="btn btn-sm btn-outline">View All</a>
            </div>
            <div class="section-body">
              <?php if ($today_details->num_rows > 0): ?>
                <div class="apt-list">
                  <?php while ($apt = $today_details->fetch_assoc()):
                    $is_past = strtotime($apt['preferred_date'] . ' ' . $apt['preferred_time']) < time();
                    ?>
                    <div class="apt-card <?php echo $is_past ? 'overdue' : ''; ?>">
                      <div class="apt-time-col">
                        <div class="apt-time"><?php echo date('g:i', $st = strtotime($apt['preferred_time'])); ?></div>
                        <div class="apt-ampm"><?php echo date('A', $st); ?></div>
                      </div>
                      <div class="apt-info" style="flex:1;">
                        <div class="apt-name"><?php echo htmlspecialchars($apt['first_name'] . ' ' . $apt['last_name']); ?>
                          <?php if ($is_past): ?><span style="color:var(--danger);font-size:11px;font-weight:400;margin-left:6px;">⚠
                              Passed</span><?php endif; ?>
                        </div>
                        <div class="apt-meta">
                          <span><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($apt['service_type']); ?></span>
                          <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($apt['email']); ?></span>
                          <?php if ($admin_role === 'superadmin' && $apt['sales_name']): ?><span><i class="fas fa-user"></i>
                              <?php echo htmlspecialchars($apt['sales_name']); ?></span><?php endif; ?>
                        </div>
                        <div class="apt-actions">
                          <span class="badge badge-<?php echo $apt['status']; ?>"><?php echo $apt['status']; ?></span>
                          <button onclick="openStatus(<?php echo $apt['appointment_id']; ?>,'<?php echo $apt['status']; ?>')"
                            class="btn btn-sm btn-outline"><i class="fas fa-pen"></i> Update</button>
                          <button
                            onclick="openReschedule(<?php echo $apt['appointment_id']; ?>,'<?php echo $apt['preferred_date']; ?>','<?php echo $apt['preferred_time']; ?>')"
                            class="btn btn-sm btn-warning"><i class="fas fa-calendar-alt"></i> Reschedule</button>
                        </div>
                      </div>
                    </div>
                  <?php endwhile; ?>
                </div>
              <?php else: ?>
                <div class="empty-state"><i class="fas fa-calendar"></i>
                  <p>No appointments today</p>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Overdue Appointments -->
          <?php if ($overdue_details->num_rows > 0): ?>
            <div class="section-card" style="border-color:var(--danger-border);">
              <div class="section-head" style="background:var(--danger-bg);">
                <h2 style="color:var(--danger);"><i class="fas fa-exclamation-triangle"></i> Overdue</h2>
              </div>
              <div class="section-body">
                <div class="apt-list">
                  <?php while ($apt = $overdue_details->fetch_assoc()): ?>
                    <div class="apt-card overdue">
                      <div class="apt-time-col">
                        <div class="apt-time" style="color:var(--danger);">
                          <?php echo date('M j', strtotime($apt['preferred_date'])); ?>
                        </div>
                        <div class="apt-ampm"><?php echo date('g:i A', strtotime($apt['preferred_time'])); ?></div>
                      </div>
                      <div class="apt-info" style="flex:1;">
                        <div class="apt-name" style="color:var(--danger);">
                          <?php echo htmlspecialchars($apt['first_name'] . ' ' . $apt['last_name']); ?>
                        </div>
                        <div class="apt-meta">
                          <span><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($apt['service_type']); ?></span>
                          <span class="badge badge-<?php echo $apt['status']; ?>"><?php echo $apt['status']; ?></span>
                        </div>
                        <div class="apt-actions">
                          <button onclick="openStatus(<?php echo $apt['appointment_id']; ?>,'<?php echo $apt['status']; ?>')"
                            class="btn btn-sm btn-outline"><i class="fas fa-pen"></i> Update Status</button>
                          <button
                            onclick="openReschedule(<?php echo $apt['appointment_id']; ?>,'<?php echo $apt['preferred_date']; ?>','<?php echo $apt['preferred_time']; ?>')"
                            class="btn btn-sm btn-warning"><i class="fas fa-calendar-alt"></i> Reschedule</button>
                        </div>
                      </div>
                    </div>
                  <?php endwhile; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- RIGHT COLUMN -->
        <div>
          <!-- Upcoming -->
          <div class="section-card" style="margin-bottom:20px;">
            <div class="section-head">
              <h2><i class="fas fa-arrow-right" style="color:var(--success);"></i> Upcoming</h2>
            </div>
            <div class="section-body" style="padding:12px 20px;">
              <?php if ($upcoming_appointments->num_rows > 0): ?>
                <?php while ($apt = $upcoming_appointments->fetch_assoc()): ?>
                  <div class="upcoming-item">
                    <div class="upc-date">
                      <div class="day"><?php echo date('d', strtotime($apt['preferred_date'])); ?></div>
                      <div class="mon"><?php echo date('M', strtotime($apt['preferred_date'])); ?></div>
                    </div>
                    <div class="upc-info">
                      <div class="name"><?php echo htmlspecialchars($apt['first_name'] . ' ' . $apt['last_name']); ?></div>
                      <div class="sub"><?php echo htmlspecialchars($apt['service_type']); ?> ·
                        <?php echo date('g:i A', strtotime($apt['preferred_time'])); ?>
                      </div>
                      <div style="margin-top:4px;"><span
                          class="badge badge-<?php echo $apt['status']; ?>"><?php echo $apt['status']; ?></span></div>
                    </div>
                  </div>
                <?php endwhile; ?>
              <?php else: ?>
                <div class="empty-state" style="padding:28px 0;"><i class="fas fa-calendar-check"></i>
                  <p>No upcoming</p>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Holiday Manager -->
          <div class="section-card" style="margin-bottom:20px;">
            <div class="section-head">
              <h2><i class="fas fa-umbrella-beach" style="color:var(--purple);"></i> Holiday Manager</h2>
              <button onclick="document.getElementById('addHolidayModal').classList.add('open')"
                class="btn btn-sm btn-primary">
                <i class="fas fa-plus"></i> Add
              </button>
            </div>
            <div class="section-body" style="padding:12px 20px;">
              <?php if (empty($holidays_list)): ?>
                <div class="empty-state" style="padding:20px 0;"><i class="fas fa-calendar-check"></i>
                  <p style="font-size:13px;">No upcoming holidays</p>
                </div>
              <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:8px;">
                  <?php foreach ($holidays_list as $h): ?>
                    <div class="holiday-item">
                      <div>
                        <div class="name"><?php echo htmlspecialchars($h['holiday_name']); ?></div>
                        <div class="meta">
                          <i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($h['holiday_date'])); ?>
                          <span class="countdown">(<?php
                          $diff = (new DateTime($h['holiday_date']))->diff(new DateTime())->days;
                          echo $diff === 0 ? 'Today' : "in {$diff} day" . ($diff > 1 ? 's' : '');
                          ?>)</span>
                        </div>
                      </div>
                      <form method="POST" style="margin:0;">
                        <input type="hidden" name="delete_holiday" value="1">
                        <input type="hidden" name="holiday_id" value="<?php echo $h['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Remove Holiday"
                          onclick="return confirm('Remove this holiday?')">
                          <i class="fas fa-trash"></i>
                        </button>
                      </form>
                    </div>
                  <?php endforeach; ?>
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
                All Appointments</a>
              <a href="?view=list&filter_active=1" class="btn btn-outline"
                style="justify-content:flex-start;color:var(--success);border-color:var(--success-border);"><i class="fas fa-check-circle"></i>
                Active Only</a>
              <a href="?view=list&filter_overdue=1" class="btn btn-outline"
                style="justify-content:flex-start;color:var(--danger);border-color:var(--danger-border);"><i
                  class="fas fa-exclamation-triangle"></i> Overdue</a>
              <a href="?view=converted" class="btn btn-outline"
                style="justify-content:flex-start;color:var(--info);border-color:var(--info-border);"><i
                  class="fas fa-user-check"></i> Converted (<?php echo $converted_total; ?>)</a>
              <a href="appointment-clients" class="btn btn-primary" style="justify-content:flex-start;"><i
                  class="fas fa-user-plus"></i> Convert to Client</a>
            </div>
          </div>
        </div>
      </div>

    <?php elseif ($view === 'converted'): ?>
      <!-- ===================== CONVERTED VIEW ===================== -->
      <div class="section-card">
        <div class="section-head">
          <h2><i class="fas fa-user-check" style="color:var(--success);"></i> Converted Appointments
            <span class="badge badge-confirmed"><?php echo $converted_total; ?> total</span>
          </h2>
          <a href="appointment-clients" class="btn btn-sm btn-primary"><i class="fas fa-external-link-alt"></i> Manage
            Clients</a>
        </div>

        <!-- Filters (search + service only — status no longer applies once converted) -->
        <form method="GET">
          <input type="hidden" name="view" value="converted">
          <div class="filters-bar">
            <div class="filter-group">
              <label>Search</label>
              <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                placeholder="Name, email, phone…" class="filter-input" style="width:200px;">
            </div>
            <div class="filter-group">
              <label>Service</label>
              <select name="filter_service" class="filter-input">
                <option value="">All Services</option>
                <?php foreach (['Consultation', 'Site Visit', 'Project Discussion', 'Follow-up', 'Other'] as $sv): ?>
                  <option value="<?php echo $sv; ?>" <?php echo $filter_service === $sv ? 'selected' : ''; ?>>
                    <?php echo $sv; ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="filter-group" style="justify-content:flex-end;gap:8px;flex-direction:row;align-items:flex-end;">
              <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
              <a href="?view=converted" class="btn btn-outline btn-sm"><i class="fas fa-redo"></i></a>
            </div>
          </div>
        </form>

        <?php if ($converted_appointments->num_rows > 0): ?>
          <div style="overflow-x:auto;">
            <table class="apt-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Client</th>
                  <th>Contact</th>
                  <th>Service</th>
                  <th>Original Schedule</th>
                  <?php if ($admin_role === 'superadmin'): ?>
                    <th>Assigned</th><?php endif; ?>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($apt = $converted_appointments->fetch_assoc()): ?>
                  <tr>
                    <td><span style="font-size:12px;color:var(--text-muted);">#<?php echo $apt['appointment_id']; ?></span>
                    </td>
                    <td>
                      <div class="td-name"><?php echo htmlspecialchars($apt['first_name'] . ' ' . $apt['last_name']); ?></div>
                      <div class="td-sub" style="color:var(--success);"><i class="fas fa-check-circle"></i> Converted to
                        client</div>
                    </td>
                    <td>
                      <div class="td-sub"><i class="fas fa-envelope" style="opacity:.5;"></i>
                        <?php echo htmlspecialchars($apt['email']); ?></div>
                      <div class="td-sub"><i class="fas fa-phone" style="opacity:.5;"></i>
                        <?php echo htmlspecialchars($apt['country_code'] . ' ' . $apt['phone']); ?></div>
                    </td>
                    <td>
                      <div style="font-size:13.5px;"><?php echo htmlspecialchars($apt['service_type']); ?></div>
                      <?php if ($apt['service_type'] === 'Other' && !empty($apt['other_service'])): ?>
                        <div class="td-sub"><?php echo htmlspecialchars($apt['other_service']); ?></div><?php endif; ?>
                    </td>
                    <td>
                      <div style="font-weight:600;font-size:13px;">
                        <?php echo date('M d, Y', strtotime($apt['preferred_date'])); ?>
                      </div>
                      <div class="td-sub"><?php echo date('g:i A', strtotime($apt['preferred_time'])); ?></div>
                    </td>
                    <?php if ($admin_role === 'superadmin'): ?>
                      <td class="td-sub"><?php echo htmlspecialchars($apt['sales_name'] ?? '—'); ?></td>
                    <?php endif; ?>
                    <td>
                      <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <button onclick="openDetail(<?php echo htmlspecialchars(json_encode($apt)); ?>)"
                          class="btn btn-sm btn-outline btn-icon" title="View"><i class="fas fa-eye"></i></button>
                        <a href="appointment-clients" class="btn btn-sm btn-purple btn-icon" title="Open in Clients"><i
                            class="fas fa-external-link-alt"></i></a>
                      </div>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty-state"><i class="fas fa-user-check"></i>
            <p>No converted appointments yet</p>
          </div>
        <?php endif; ?>
      </div>

    <?php else: ?>
      <!-- ===================== LIST VIEW ===================== -->
      <div class="section-card">
        <div class="section-head">
          <h2><i class="fas fa-calendar-check" style="color:var(--text-muted);"></i> All Appointments
            <?php if ($filter_active): ?>
              <span class="badge badge-confirmed">Active Only</span>
            <?php elseif ($filter_overdue): ?>
              <span class="badge badge-overdue">Overdue</span>
            <?php elseif ($filter_status === 'completed'): ?>
              <span class="badge badge-completed">Completed</span>
            <?php endif; ?>
          </h2>
          <span style="font-size:13px;color:var(--text-muted);"><?php echo $all_appointments->num_rows; ?> records
            <?php if ($converted_total > 0): ?>
              <a href="?view=converted" style="color:var(--info);text-decoration:none;font-weight:600;margin-left:8px;">
                · <?php echo $converted_total; ?> converted &rarr;</a>
            <?php endif; ?>
          </span>
        </div>

        <!-- Filters -->
        <form method="GET">
          <input type="hidden" name="view" value="list">
          <div class="filters-bar">
            <div class="filter-group">
              <label>Search</label>
              <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                placeholder="Name, email, phone…" class="filter-input" style="width:200px;">
            </div>
            <div class="filter-group">
              <label>Status</label>
              <select name="filter_status" class="filter-input">
                <option value="">All Statuses</option>
                <?php foreach (['draft', 'pending', 'confirmed', 'completed', 'cancelled'] as $s): ?>
                  <option value="<?php echo $s; ?>" <?php echo $filter_status === $s ? 'selected' : ''; ?>>
                    <?php echo ucfirst($s); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="filter-group">
              <label>Service</label>
              <select name="filter_service" class="filter-input">
                <option value="">All Services</option>
                <?php foreach (['Consultation', 'Site Visit', 'Project Discussion', 'Follow-up', 'Other'] as $sv): ?>
                  <option value="<?php echo $sv; ?>" <?php echo $filter_service === $sv ? 'selected' : ''; ?>>
                    <?php echo $sv; ?>
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
              class="qtag qtag-all <?php echo (!$filter_active && !$filter_overdue && $filter_status !== 'completed') ? 'active' : ''; ?>">All</a>
            <a href="?view=list&filter_active=1" class="qtag qtag-active <?php echo $filter_active ? 'active' : ''; ?>">
              <i class="fas fa-check" style="font-size:10px;"></i> Active</a>
            <a href="?view=list&filter_overdue=1"
              class="qtag qtag-overdue <?php echo $filter_overdue ? 'active' : ''; ?>">
              <i class="fas fa-exclamation-triangle" style="font-size:10px;"></i> Overdue</a>
            <a href="?view=list&filter_status=completed"
              class="qtag qtag-done <?php echo $filter_status === 'completed' ? 'active' : ''; ?>">
              <i class="fas fa-trophy" style="font-size:10px;"></i> Completed</a>
          </div>
        </form>

        <!-- Table -->
        <?php if ($all_appointments->num_rows > 0): ?>
          <div style="overflow-x:auto;">
            <table class="apt-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Client</th>
                  <th>Contact</th>
                  <th>Service</th>
                  <th>Schedule</th>
                  <th>Status</th>
                  <?php if ($admin_role === 'superadmin'): ?>
                    <th>Assigned</th><?php endif; ?>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($apt = $all_appointments->fetch_assoc()):
                  $is_overdue = $apt['is_overdue'] == 1;
                  ?>
                  <tr class="<?php echo $is_overdue ? 'row-overdue' : ''; ?>">
                    <td>
                      <span style="font-size:12px;color:var(--text-muted);">#<?php echo $apt['appointment_id']; ?></span>
                      <?php if ($is_overdue): ?>
                        <div><span class="overdue-tag"><i class="fas fa-clock"></i> Late</span></div><?php endif; ?>
                    </td>
                    <td>
                      <div class="td-name"><?php echo htmlspecialchars($apt['first_name'] . ' ' . $apt['last_name']); ?></div>
                      <?php if ($apt['converted_to_client']): ?>
                        <div class="td-sub" style="color:var(--success);"><i class="fas fa-check-circle"></i> Converted</div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="td-sub"><i class="fas fa-envelope" style="opacity:.5;"></i>
                        <?php echo htmlspecialchars($apt['email']); ?></div>
                      <div class="td-sub"><i class="fas fa-phone" style="opacity:.5;"></i>
                        <?php echo htmlspecialchars($apt['country_code'] . ' ' . $apt['phone']); ?></div>
                    </td>
                    <td>
                      <div style="font-size:13.5px;"><?php echo htmlspecialchars($apt['service_type']); ?></div>
                      <?php if ($apt['service_type'] === 'Other' && !empty($apt['other_service'])): ?>
                        <div class="td-sub"><?php echo htmlspecialchars($apt['other_service']); ?></div><?php endif; ?>
                    </td>
                    <td>
                      <div style="font-weight:600;font-size:13px;">
                        <?php echo date('M d, Y', strtotime($apt['preferred_date'])); ?>
                      </div>
                      <div class="td-sub"><?php echo date('g:i A', strtotime($apt['preferred_time'])); ?></div>
                    </td>
                    <td>
                      <span class="badge badge-<?php echo $apt['status']; ?>"><?php echo ucfirst($apt['status']); ?></span>
                      <?php if ($is_overdue): ?>
                        <div style="margin-top:4px;font-size:11px;color:var(--danger);font-weight:600;">Overdue</div><?php endif; ?>
                    </td>
                    <?php if ($admin_role === 'superadmin'): ?>
                      <td class="td-sub"><?php echo htmlspecialchars($apt['sales_name'] ?? '—'); ?></td>
                    <?php endif; ?>
                    <td>
                      <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <button onclick="openDetail(<?php echo htmlspecialchars(json_encode($apt)); ?>)"
                          class="btn btn-sm btn-outline btn-icon" title="View"><i class="fas fa-eye"></i></button>
                        <button onclick="openStatus(<?php echo $apt['appointment_id']; ?>,'<?php echo $apt['status']; ?>')"
                          class="btn btn-sm btn-outline btn-icon" title="Update Status"
                          style="color:var(--success);border-color:var(--success-border);"><i class="fas fa-pen"></i></button>
                        <button
                          onclick="openReschedule(<?php echo $apt['appointment_id']; ?>,'<?php echo $apt['preferred_date']; ?>','<?php echo $apt['preferred_time']; ?>')"
                          class="btn btn-sm btn-warning btn-icon" title="Reschedule"><i
                            class="fas fa-calendar-alt"></i></button>
                        <?php if ($apt['service_type'] !== 'Consultation' && $apt['status'] === 'pending'): ?>
                          <form method="POST" action="appointment-mailer" style="display:inline;">
                            <input type="hidden" name="appointment_id" value="<?php echo $apt['appointment_id']; ?>">
                            <input type="hidden" name="email_type" value="schedule_confirmed">
                            <button type="submit" class="btn btn-sm btn-success btn-icon" title="Send Confirmation"><i
                                class="fas fa-paper-plane"></i></button>
                          </form>
                        <?php endif; ?>
                        <button
                          onclick="confirmDelete(<?php echo $apt['appointment_id']; ?>, '<?php echo htmlspecialchars($apt['first_name'] . ' ' . $apt['last_name']); ?>')"
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
            <p>No appointments found</p>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div><!-- /app-wrap -->

  <!-- VIEW DETAIL MODAL -->
  <div class="modal-bg" id="detailModal">
    <div class="modal-box modal-lg">
      <div class="modal-head">
        <h3><i class="fas fa-calendar-check" style="color:var(--purple);"></i> Appointment Details</h3>
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
        <input type="hidden" name="appointment_id" id="status_id">
        <div class="form-group">
          <label>New Status</label>
          <select name="status" id="status_val" class="form-control" required>
            <option value="draft">Draft</option>
            <option value="pending">Pending</option>
            <option value="confirmed">Confirmed</option>
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

  <!-- RESCHEDULE MODAL -->
  <div class="modal-bg" id="rescheduleModal">
    <div class="modal-box">
      <div class="modal-head">
        <h3><i class="fas fa-calendar-alt" style="color:var(--warning);"></i> Reschedule Appointment</h3>
        <button class="modal-close" onclick="document.getElementById('rescheduleModal').classList.remove('open')"><i
            class="fas fa-times"></i></button>
      </div>
      <form method="POST">
        <input type="hidden" name="reschedule" value="1">
        <input type="hidden" name="appointment_id" id="resched_id">
        <div class="form-row cols-2">
          <div class="form-group">
            <label>New Date</label>
            <input type="date" name="new_date" id="resched_date" class="form-control" required
              min="<?php echo date('Y-m-d'); ?>">
          </div>
          <div class="form-group">
            <label>New Time</label>
            <input type="time" name="new_time" id="resched_time" class="form-control" required>
          </div>
        </div>
        <p
          style="font-size:13px;color:var(--text-muted);background:var(--surface2);padding:10px 13px;border-radius:var(--radius-sm);border:1px solid var(--border);">
          <i class="fas fa-info-circle" style="color:var(--info);"></i> Rescheduling will set the status to
          <strong>Confirmed</strong>.
        </p>
        <div class="form-actions">
          <button type="button" class="btn btn-outline"
            onclick="document.getElementById('rescheduleModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-calendar-check"></i> Reschedule</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ADD HOLIDAY MODAL -->
  <div class="modal-bg" id="addHolidayModal">
    <div class="modal-box" style="max-width:420px;">
      <div class="modal-head">
        <h3><i class="fas fa-umbrella-beach" style="color:var(--purple);"></i> Add Holiday</h3>
        <button class="modal-close" onclick="document.getElementById('addHolidayModal').classList.remove('open')"><i
            class="fas fa-times"></i></button>
      </div>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">
        Marked holidays will be blocked on the booking calendar automatically.
      </p>
      <form method="POST">
        <input type="hidden" name="add_holiday" value="1">
        <div class="form-row cols-2" style="margin-bottom:0;">
          <div class="form-group">
            <label>Holiday Date <span style="color:var(--danger);">*</span></label>
            <input type="date" name="holiday_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
          </div>
          <div class="form-group">
            <label>Holiday Name <span style="color:var(--danger);">*</span></label>
            <input type="text" name="holiday_name" class="form-control" required placeholder="e.g. Christmas Day"
              maxlength="100">
          </div>
        </div>
        <p
          style="font-size:12px;color:var(--text-muted);background:var(--surface2);padding:10px 13px;border-radius:var(--radius-sm);border:1px solid var(--border);margin-top:12px;">
          <i class="fas fa-info-circle" style="color:var(--info);"></i> The holiday will be <strong>automatically
            removed</strong> the day after it passes.
        </p>
        <div class="form-actions">
          <button type="button" class="btn btn-outline"
            onclick="document.getElementById('addHolidayModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Holiday</button>
        </div>
      </form>
    </div>
  </div>

  <!-- DELETE CONFIRM MODAL -->
  <div class="modal-bg" id="deleteModal">
    <div class="modal-box" style="max-width:440px;">
      <div class="modal-head">
        <h3><i class="fas fa-trash" style="color:var(--danger);"></i> Delete Appointment</h3>
        <button class="modal-close" onclick="document.getElementById('deleteModal').classList.remove('open')"><i
            class="fas fa-times"></i></button>
      </div>
      <p style="font-size:15px;color:var(--text);margin-bottom:8px;">Are you sure you want to delete the appointment for
        <strong id="delete_name"></strong>?
      </p>
      <p style="font-size:13px;color:var(--danger);">This action cannot be undone.</p>
      <form method="POST" id="deleteForm">
        <input type="hidden" name="delete_appointment" value="1">
        <input type="hidden" name="appointment_id" id="delete_id">
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
    <div class="modal-box" style="max-width:500px;">
      <div class="modal-head">
        <h3><i class="fas fa-hourglass-half" style="color:var(--warning);"></i> Pending Appointments</h3>
        <button class="modal-close" onclick="document.getElementById('pendingPopup').classList.remove('open')"><i
            class="fas fa-times"></i></button>
      </div>
      <p style="font-size:14px;color:var(--text-muted);margin-bottom:16px;">
        These appointments are still waiting for action:
      </p>
      <div style="display:flex;flex-direction:column;gap:10px;max-height:340px;overflow-y:auto;">
        <?php
        $pending_popup_sql = "SELECT a.*, acc.full_name as sales_name FROM appointments a LEFT JOIN account acc ON a.assigned_to = acc.id WHERE a.status = 'pending'" . ($admin_role !== 'superadmin' ? " AND a.assigned_to=$admin_id" : "") . " ORDER BY a.preferred_date ASC";
        $pending_popup = $conn->query($pending_popup_sql);
        if ($pending_popup->num_rows > 0):
          while ($apt = $pending_popup->fetch_assoc()):
            ?>
            <div
              style="background:var(--warning-bg);border:1.5px solid var(--warning-border);border-radius:var(--radius-sm);padding:12px 14px;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                  <div style="font-weight:600;font-size:14px;">
                    <?php echo htmlspecialchars($apt['first_name'] . ' ' . $apt['last_name']); ?>
                  </div>
                  <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">
                    <i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($apt['service_type']); ?> &nbsp;·&nbsp;
                    <i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($apt['preferred_date'])); ?>
                    <?php echo date('g:i A', strtotime($apt['preferred_time'])); ?>
                  </div>
                  <?php if ($admin_role === 'superadmin' && $apt['sales_name']): ?>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><i class="fas fa-user"></i>
                      <?php echo htmlspecialchars($apt['sales_name']); ?></div>
                  <?php endif; ?>
                </div>
                <button
                  onclick="openStatus(<?php echo $apt['appointment_id']; ?>,'<?php echo $apt['status']; ?>');document.getElementById('pendingPopup').classList.remove('open');"
                  class="btn btn-sm"
                  style="background:var(--warning-bg);color:var(--warning);border:1.5px solid var(--warning-border);flex-shrink:0;margin-left:10px;">
                  <i class="fas fa-pen"></i> Update
                </button>
              </div>
            </div>
          <?php endwhile; else: ?>
          <div class="empty-state" style="padding:24px 0;"><i class="fas fa-check-circle"></i>
            <p>No pending appointments!</p>
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

  <!-- CONVERT POPUP -->
  <div class="modal-bg" id="convertPopup">
    <div class="modal-box" style="max-width:500px;">
      <div class="modal-head">
        <h3><i class="fas fa-user-plus" style="color:var(--success);"></i> Ready to Convert</h3>
        <button class="modal-close" onclick="document.getElementById('convertPopup').classList.remove('open')"><i
            class="fas fa-times"></i></button>
      </div>
      <p style="font-size:14px;color:var(--text-muted);margin-bottom:16px;">
        These confirmed consultations can now be converted to clients:
      </p>
      <div style="display:flex;flex-direction:column;gap:10px;max-height:340px;overflow-y:auto;">
        <?php if ($convertible_count > 0):
          $convertible_result->data_seek(0);
          while ($apt = $convertible_result->fetch_assoc()): ?>
            <div
              style="background:var(--success-bg);border:1.5px solid var(--success-border);border-radius:var(--radius-sm);padding:12px 14px;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                  <div style="font-weight:600;font-size:14px;">
                    <?php echo htmlspecialchars($apt['first_name'] . ' ' . $apt['last_name']); ?>
                  </div>
                  <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">
                    <i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($apt['service_type']); ?> &nbsp;·&nbsp;
                    <i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($apt['preferred_date'])); ?>
                  </div>
                  <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
                    <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($apt['email']); ?>
                  </div>
                </div>
                <a href="appointment-clients" class="btn btn-sm btn-success" style="flex-shrink:0;margin-left:10px;">
                  <i class="fas fa-user-plus"></i> Convert
                </a>
              </div>
            </div>
          <?php endwhile; else: ?>
          <div class="empty-state" style="padding:24px 0;"><i class="fas fa-user-check"></i>
            <p>No appointments ready to convert!</p>
          </div>
        <?php endif; ?>
      </div>
      <div class="form-actions" style="margin-top:16px;">
        <a href="appointment-clients" class="btn btn-primary btn-sm"><i class="fas fa-user-plus"></i> Go to Client
          Conversion</a>
        <button class="btn btn-outline btn-sm"
          onclick="document.getElementById('convertPopup').classList.remove('open')">Close</button>
      </div>
    </div>
  </div>

  <script>
    function openStatus(id, status) {
      document.getElementById('status_id').value = id;
      document.getElementById('status_val').value = status;
      document.getElementById('statusModal').classList.add('open');
    }
    function openReschedule(id, date, time) {
      document.getElementById('resched_id').value = id;
      document.getElementById('resched_date').value = date;
      document.getElementById('resched_time').value = time;
      document.getElementById('rescheduleModal').classList.add('open');
    }
    function confirmDelete(id, name) {
      document.getElementById('delete_id').value = id;
      document.getElementById('delete_name').textContent = name;
      document.getElementById('deleteModal').classList.add('open');
    }
    function openDetail(apt) {
      const svc = apt.service_type === 'Other' && apt.other_service ? apt.service_type + ' — ' + apt.other_service : apt.service_type;
      document.getElementById('detailContent').innerHTML = `
    <div class="detail-grid">
      <div class="detail-item"><label>Full Name</label><p>${apt.first_name} ${apt.last_name}</p></div>
      <div class="detail-item"><label>Email</label><p>${apt.email}</p></div>
      <div class="detail-item"><label>Phone</label><p>${apt.country_code} ${apt.phone}</p></div>
      <div class="detail-item"><label>Service</label><p>${svc}</p></div>
      <div class="detail-item"><label>Date</label><p>${new Date(apt.preferred_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}</p></div>
      <div class="detail-item"><label>Time</label><p>${apt.preferred_time}</p></div>
      <div class="detail-item"><label>Status</label><p><span class="badge badge-${apt.status}">${apt.status}</span></p></div>
      <div class="detail-item"><label>Inquiry Type</label><p>${apt.inquiry_type || '—'}</p></div>
    </div>
    ${apt.notes ? `<div style="margin-top:16px;padding:14px;background:var(--surface2);border-radius:var(--radius-sm);border:1px solid var(--border);"><label style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px;">Notes</label><p style="font-size:14px;">${apt.notes}</p></div>` : ''}
    <div style="margin-top:16px;font-size:12px;color:var(--text-muted);">Created: ${new Date(apt.created_at).toLocaleString()}</div>
  `;
      document.getElementById('detailModal').classList.add('open');
    }

    // Close modals on backdrop click
    document.querySelectorAll('.modal-bg').forEach(m => {
      m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });
  </script>
</body>

</html>
<?php $conn->close(); ?>