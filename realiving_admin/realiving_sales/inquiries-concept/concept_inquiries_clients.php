<?php
//concept_inquiries_clients.php
include $includes ['mainbody'];

require_role(['sales', 'superadmin']);

$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['role'] ?? '';

// Handle form submission for adding client from concept inquiry
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['inquiry_id'])) {
    $inquiry_id = intval($_POST['inquiry_id']);
    $clientname = $_POST['clientname'];
    $status = $_POST['status'];
    $nameproject = $_POST['nameproject'];
    $client_type = $_POST['client_type'];
    $client_class = $_POST['client_class'];
    $contact = $_POST['contact'];
    $email = $_POST['email'];
    $country = $_POST['country'];
    $address = $_POST['address'];
    $gender = $_POST['gender'];
    $business_type = $_POST['business_type'];
    $project_scope      = $_POST['project_scope'];
    $scope_of_work      = $_POST['scope_of_work'];
    $house_state        = $_POST['house_state'];
    $permit_required    = $_POST['permit_required'];
    $target_movein_date = !empty($_POST['target_movein_date']) ? $_POST['target_movein_date'] : null;
    $updateTime = date('Y-m-d H:i:s');
    $accountaid_fk = $_SESSION['admin_id'];

    $reference_number = "CREF" . date("YmdHis") . strtoupper(substr(md5(uniqid()), 0, 4));

    $stmt = $conn->prepare("INSERT INTO user_info (clientname, status, nameproject, updatestatus, update_time, reference_number, client_type, client_class, business_type, contact, email, country, address, gender, accountaid_fk, project_scope, scope_of_work, house_state, permit_required, target_movein_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssssssssssissss", $clientname, $status, $nameproject, $status, $updateTime, $reference_number, $client_type, $client_class, $business_type, $contact, $email, $country, $address, $gender, $accountaid_fk, $project_scope, $scope_of_work, $house_state, $permit_required, $target_movein_date);

    if ($stmt->execute()) {
        $client_id = $stmt->insert_id;
        $update_stmt = $conn->prepare("UPDATE concept_inquiries SET status = 'completed', client_id = ? WHERE id = ?");
        $update_stmt->bind_param("ii", $client_id, $inquiry_id);
        $update_stmt->execute();
        $update_stmt->close();
        $_SESSION['success_message'] = "Concept inquiry successfully converted to client!";
        header("Location: " . BASE_URL . "concept-inquiries-clients");
        exit();
    } else {
        $_SESSION['error_message'] = "Error: " . $stmt->error;
    }
    $stmt->close();
}

// Get responded concept inquiries not yet completed
$pending_query = ($admin_role === 'superadmin')
    ? "SELECT ci.*, acc.full_name as sales_name FROM concept_inquiries ci LEFT JOIN account acc ON ci.assigned_to = acc.id WHERE ci.status = 'responded' ORDER BY ci.created_at DESC"
    : "SELECT ci.*, acc.full_name as sales_name FROM concept_inquiries ci LEFT JOIN account acc ON ci.assigned_to = acc.id WHERE ci.status = 'responded' AND ci.assigned_to = $admin_id ORDER BY ci.created_at DESC";
$pending_inquiries = $conn->query($pending_query);

// Get completed/converted inquiries
$completed_query = ($admin_role === 'superadmin')
    ? "SELECT ci.*, acc.full_name as sales_name, ui.reference_number, ui.clientname as client_name, ui.id as userinfo_id FROM concept_inquiries ci LEFT JOIN account acc ON ci.assigned_to = acc.id LEFT JOIN user_info ui ON ci.client_id = ui.id WHERE ci.status = 'completed' ORDER BY ci.updated_at DESC"
    : "SELECT ci.*, acc.full_name as sales_name, ui.reference_number, ui.clientname as client_name, ui.id as userinfo_id FROM concept_inquiries ci LEFT JOIN account acc ON ci.assigned_to = acc.id LEFT JOIN user_info ui ON ci.client_id = ui.id WHERE ci.status = 'completed' AND ci.assigned_to = $admin_id ORDER BY ci.updated_at DESC";
$converted_clients = $conn->query($completed_query);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Concept Client Conversion - RealLiving</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<? BASE_URL ?>logo/favicon.ico">
  <!-- Font Awesome CDN -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
    crossorigin="anonymous" referrerpolicy="no-referrer" />
  <!-- Google Fonts: Inter -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --adm-bg: #F5F5F5;
      --adm-surface: #FFFFFF;
      --adm-surface2: #FAFAFA;
      --adm-ink: #0B0B0B;
      --adm-soft: #6B6B6B;
      --adm-muted: #9A9A9A;
      --adm-line: #E2E2E2;
    }

    * {
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--adm-bg);
      color: var(--adm-ink);
    }

    /* ── Header ─────────────────────────────── */
    .adm-eyebrow {
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      color: var(--adm-soft);
    }

    .adm-title {
      font-size: 28px;
      font-weight: 700;
      letter-spacing: -0.01em;
      color: var(--adm-ink);
    }

    .adm-subtitle {
      font-size: 13.5px;
      color: var(--adm-soft);
    }

    .adm-back {
      font-size: 12.5px;
      font-weight: 600;
      color: var(--adm-soft);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      padding: 8px 14px;
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      background: var(--adm-surface);
      transition: border-color .2s ease, color .2s ease;
    }

    .adm-back:hover {
      border-color: var(--adm-ink);
      color: var(--adm-ink);
    }

    .adm-header-row {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }

    /* ── Section label ──────────────────────── */
    .adm-section-label {
      font-size: 12px;
      font-weight: 600;
      color: var(--adm-ink);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .adm-section-label::after {
      content: "";
      flex: 1;
      height: 1px;
      background: var(--adm-line);
    }

    /* ── Alerts ─────────────────────────────── */
    .adm-alert {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 18px;
      border-radius: 10px;
      margin-bottom: 18px;
      font-size: 13.5px;
      font-weight: 500;
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-left: 3px solid var(--adm-ink);
    }

    .adm-alert.is-error {
      border-left-color: #9b1c1c;
    }

    .adm-alert.is-error i {
      color: #9b1c1c;
    }

    /* ── Tabs ───────────────────────────────── */
    .adm-tabs {
      display: flex;
      gap: 4px;
      margin-bottom: 22px;
      background: var(--adm-surface);
      border-radius: 10px;
      padding: 6px;
      border: 1px solid var(--adm-line);
    }

    .adm-tab-btn {
      flex: 1;
      padding: 11px 18px;
      border-radius: 8px;
      border: none;
      background: transparent;
      font-family: 'Inter', sans-serif;
      font-size: 13.5px;
      font-weight: 600;
      color: var(--adm-soft);
      cursor: pointer;
      transition: all .2s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }

    .adm-tab-btn.active {
      background: var(--adm-ink);
      color: #fff;
    }

    .adm-tab-btn:not(.active):hover {
      background: var(--adm-bg);
      color: var(--adm-ink);
    }

    .adm-tab-count {
      background: rgba(255, 255, 255, 0.18);
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
    }

    .adm-tab-btn:not(.active) .adm-tab-count {
      background: var(--adm-bg);
      color: var(--adm-soft);
      border: 1px solid var(--adm-line);
    }

    .adm-tab-content {
      display: none;
    }

    .adm-tab-content.active {
      display: block;
    }

    /* ── Panel (card container for tables) ──── */
    .adm-panel {
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      overflow: hidden;
      margin-bottom: 22px;
    }

    .adm-panel-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 18px 22px;
      border-bottom: 1px solid var(--adm-line);
    }

    .adm-panel-head h2 {
      font-size: 15px;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 9px;
      color: var(--adm-ink);
    }

    /* ── Table ──────────────────────────────── */
    .adm-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13.5px;
    }

    .adm-table thead th {
      padding: 11px 18px;
      text-align: left;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
      color: var(--adm-soft);
      background: var(--adm-surface2);
      border-bottom: 1px solid var(--adm-line);
      white-space: nowrap;
    }

    .adm-table tbody tr {
      border-bottom: 1px solid var(--adm-line);
      transition: background .15s ease;
    }

    .adm-table tbody tr:last-child {
      border-bottom: none;
    }

    .adm-table tbody tr:hover {
      background: var(--adm-surface2);
    }

    .adm-table td {
      padding: 14px 18px;
      vertical-align: top;
      color: var(--adm-ink);
    }

    .td-name {
      font-weight: 600;
      font-size: 14px;
      color: var(--adm-ink);
    }

    .td-sub {
      font-size: 12px;
      color: var(--adm-soft);
      margin-top: 2px;
    }

    .td-sub i {
      opacity: .6;
      width: 12px;
    }

    /* ── Status badges ──────────────────────── */
    .adm-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .3px;
      text-transform: uppercase;
      border: 1px solid var(--adm-line);
      background: var(--adm-surface2);
      color: var(--adm-soft);
    }

    .adm-badge.badge-responded {
      color: #7d5a00;
      background: #fff8e6;
      border-color: #f0e0b0;
    }

    .adm-badge.badge-completed {
      color: #1e3a5f;
      background: #eaf1fb;
      border-color: #cdddf3;
    }

    .adm-count-pill {
      min-width: 20px;
      height: 20px;
      padding: 0 6px;
      line-height: 20px;
      text-align: center;
      border-radius: 999px;
      background: var(--adm-ink);
      color: #fff;
      font-size: 11px;
      font-weight: 700;
      display: inline-block;
    }

    .ref-mono {
      font-family: 'SFMono-Regular', Consolas, 'Courier New', monospace;
      font-size: 12px;
      color: var(--adm-ink);
      background: var(--adm-surface2);
      padding: 3px 9px;
      border-radius: 6px;
      border: 1px solid var(--adm-line);
    }

    /* ── Buttons ────────────────────────────── */
    .adm-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 9px 16px;
      border-radius: 8px;
      font-size: 12.5px;
      font-weight: 600;
      border: 1px solid transparent;
      cursor: pointer;
      text-decoration: none;
      transition: all .18s ease;
      font-family: 'Inter', sans-serif;
    }

    .adm-btn-primary {
      background: var(--adm-ink);
      color: #fff;
    }

    .adm-btn-primary:hover {
      background: #262626;
    }

    .adm-btn-outline {
      background: var(--adm-surface);
      border-color: var(--adm-line);
      color: var(--adm-soft);
    }

    .adm-btn-outline:hover {
      border-color: var(--adm-ink);
      color: var(--adm-ink);
    }

    .adm-btn-sm {
      padding: 6px 12px;
      font-size: 11.5px;
    }

    /* ── Empty state ────────────────────────── */
    .adm-empty {
      text-align: center;
      padding: 56px 20px;
      color: var(--adm-muted);
    }

    .adm-empty i {
      font-size: 38px;
      opacity: .35;
      display: block;
      margin-bottom: 14px;
    }

    .adm-empty p {
      font-size: 13.5px;
      color: var(--adm-soft);
    }

    .adm-empty p.adm-empty-sub {
      font-size: 12px;
      color: var(--adm-muted);
      margin-top: 4px;
    }

    /* ── Modal ──────────────────────────────── */
    .adm-modal-bg {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(11, 11, 11, .5);
      z-index: 999;
      align-items: center;
      justify-content: center;
      backdrop-filter: blur(2px);
      padding: 20px;
    }

    .adm-modal-bg.open {
      display: flex;
    }

    .adm-modal-box {
      background: var(--adm-surface);
      border-radius: 12px;
      padding: 30px;
      max-width: 860px;
      width: 100%;
      max-height: 92vh;
      overflow-y: auto;
      box-shadow: 0 20px 60px rgba(11, 11, 11, .25);
      animation: admModalIn .2s ease;
      border: 1px solid var(--adm-line);
    }

    @keyframes admModalIn {
      from {
        opacity: 0;
        transform: translateY(14px) scale(.98);
      }

      to {
        opacity: 1;
        transform: none;
      }
    }

    .adm-modal-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 22px;
      padding-bottom: 16px;
      border-bottom: 1px solid var(--adm-line);
    }

    .adm-modal-head h3 {
      font-size: 17px;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 9px;
      color: var(--adm-ink);
    }

    .adm-modal-close {
      background: none;
      border: none;
      font-size: 18px;
      color: var(--adm-soft);
      cursor: pointer;
      padding: 6px;
      border-radius: 6px;
      line-height: 1;
    }

    .adm-modal-close:hover {
      color: var(--adm-ink);
      background: var(--adm-surface2);
    }

    .adm-context-box {
      background: var(--adm-surface2);
      border: 1px solid var(--adm-line);
      border-left: 3px solid var(--adm-ink);
      border-radius: 8px;
      padding: 13px 16px;
      margin-bottom: 20px;
    }

    .adm-context-box .adm-context-label {
      font-size: 11px;
      font-weight: 700;
      color: var(--adm-soft);
      text-transform: uppercase;
      letter-spacing: .4px;
      margin-bottom: 6px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .adm-context-box .adm-context-body {
      font-size: 13px;
      color: var(--adm-ink);
      line-height: 1.5;
    }

    .form-section {
      border-top: 1px solid var(--adm-line);
      padding-top: 20px;
      margin-top: 6px;
    }

    .form-section-title {
      font-size: 11.5px;
      font-weight: 700;
      color: var(--adm-soft);
      text-transform: uppercase;
      letter-spacing: .5px;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 7px;
    }

    .form-row {
      display: grid;
      gap: 14px;
      margin-bottom: 14px;
    }

    .form-row.cols-2 {
      grid-template-columns: 1fr 1fr;
    }

    .form-group label {
      display: block;
      font-size: 11.5px;
      font-weight: 600;
      color: var(--adm-soft);
      text-transform: uppercase;
      letter-spacing: .4px;
      margin-bottom: 6px;
    }

    .form-group label .req {
      color: #9b1c1c;
    }

    .form-control {
      width: 100%;
      padding: 10px 13px;
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      font-size: 13.5px;
      font-family: 'Inter', sans-serif;
      color: var(--adm-ink);
      background: var(--adm-surface2);
      transition: border-color .18s ease, background .18s ease;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--adm-ink);
      background: #fff;
    }

    textarea.form-control {
      resize: vertical;
    }

    .form-check {
      display: flex;
      align-items: center;
      gap: 7px;
      margin-top: 8px;
      font-size: 12.5px;
      color: var(--adm-soft);
      cursor: pointer;
    }

    .form-check input {
      accent-color: var(--adm-ink);
    }

    .form-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      margin-top: 22px;
      padding-top: 18px;
      border-top: 1px solid var(--adm-line);
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

    .adm-fade {
      animation: adm-fade .4s ease both;
    }

    @media (prefers-reduced-motion: reduce) {
      .adm-fade {
        animation: none;
      }
    }

    @media (max-width: 700px) {
      .form-row.cols-2 {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      .adm-tabs {
        flex-direction: column;
      }
    }
  </style>
</head>

<body class="min-h-screen flex flex-col">

  <!-- Main Content -->
  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">

    <!-- Header -->
    <div class="mb-8 adm-fade adm-header-row">
      <div>
        <div class="adm-eyebrow mb-2">Sales &amp; Marketing</div>
        <h1 class="adm-title">Concept Client Conversion</h1>
        <p class="adm-subtitle mt-1">Convert responded concept inquiries into active clients.</p>
      </div>
      <a href="concept-inquiries-dashboard" class="adm-back"><i class="fas fa-arrow-left"></i> Back to Inquiries</a>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
      <div class="adm-alert adm-fade">
        <i class="fa-solid fa-circle-check" style="color:var(--adm-ink);"></i>
        <span><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></span>
      </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
      <div class="adm-alert is-error adm-fade">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></span>
      </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="adm-tabs adm-fade">
      <button class="adm-tab-btn active" id="tab-pending" onclick="switchTab('pending')">
        <i class="fas fa-user-clock"></i> Ready to Convert
        <span class="adm-tab-count"><?php echo $pending_inquiries->num_rows; ?></span>
      </button>
      <button class="adm-tab-btn" id="tab-converted" onclick="switchTab('converted')">
        <i class="fas fa-user-check"></i> Converted Clients
        <span class="adm-tab-count"><?php echo $converted_clients->num_rows; ?></span>
      </button>
    </div>

    <!-- PENDING TAB -->
    <div class="adm-tab-content active adm-fade" id="content-pending">
      <div class="adm-panel">
        <div class="adm-panel-head">
          <h2><i class="fas fa-clock" style="color:var(--adm-soft);"></i> Responded Concept Inquiries — Ready to Convert</h2>
        </div>
        <?php if ($pending_inquiries->num_rows > 0): ?>
          <div style="overflow-x:auto;">
            <table class="adm-table">
              <thead>
                <tr>
                  <th>Client</th>
                  <th>Contact</th>
                  <th>Concept Details</th>
                  <th>Status</th>
                  <?php if ($admin_role === 'superadmin'): ?><th>Assigned</th><?php endif; ?>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($inq = $pending_inquiries->fetch_assoc()): ?>
                  <tr>
                    <td>
                      <div class="td-name"><?php echo htmlspecialchars($inq['name']); ?></div>
                      <?php if ($inq['address']): ?><div class="td-sub"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars(substr($inq['address'], 0, 50)) . (strlen($inq['address']) > 50 ? '…' : ''); ?></div><?php endif; ?>
                    </td>
                    <td>
                      <div class="td-sub"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($inq['email']); ?></div>
                      <div class="td-sub"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($inq['phone']); ?></div>
                    </td>
                    <td>
                      <div style="font-size:13.5px;font-weight:500;color:var(--adm-ink);"><?php echo htmlspecialchars($inq['concept_style'] ?? 'N/A'); ?></div>
                      <div class="td-sub"><?php echo htmlspecialchars($inq['project_type']); ?></div>
                      <div class="td-sub"><?php echo htmlspecialchars(substr($inq['know_more_about'], 0, 45)) . (strlen($inq['know_more_about']) > 45 ? '…' : ''); ?></div>
                    </td>
                    <td><span class="adm-badge badge-<?php echo $inq['status']; ?>"><?php echo ucfirst($inq['status']); ?></span></td>
                    <?php if ($admin_role === 'superadmin'): ?><td class="td-sub"><i class="fas fa-user"></i> <?php echo htmlspecialchars($inq['sales_name'] ?? '—'); ?></td><?php endif; ?>
                    <td>
                      <button onclick="openConvert(<?php echo htmlspecialchars(json_encode($inq)); ?>)" class="adm-btn adm-btn-primary adm-btn-sm">
                        <i class="fas fa-user-plus"></i> Convert
                      </button>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="adm-empty">
            <i class="fas fa-user-clock"></i>
            <p>No responded inquiries ready for conversion</p>
            <p class="adm-empty-sub">Inquiries must be in <strong>responded</strong> status before conversion</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- CONVERTED TAB -->
    <div class="adm-tab-content adm-fade" id="content-converted">
      <div class="adm-panel">
        <div class="adm-panel-head">
          <h2><i class="fas fa-user-check" style="color:var(--adm-soft);"></i> Converted Clients</h2>
        </div>
        <?php if ($converted_clients->num_rows > 0): ?>
          <div style="overflow-x:auto;">
            <table class="adm-table">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Client</th>
                  <th>Concept Details</th>
                  <th>Contact</th>
                  <th>Converted</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($client = $converted_clients->fetch_assoc()): ?>
                  <tr>
                    <td>
                      <?php if ($client['reference_number']): ?><span class="ref-mono"><?php echo htmlspecialchars($client['reference_number']); ?></span><?php else: ?><span class="td-sub">—</span><?php endif; ?>
                    </td>
                    <td>
                      <div class="td-name"><?php echo htmlspecialchars($client['name']); ?></div>
                      <?php if ($client['client_name']): ?><div class="td-sub" style="color:var(--adm-ink);"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($client['client_name']); ?></div><?php endif; ?>
                      <?php if ($client['client_id']): ?><div class="td-sub">Client ID: #<?php echo $client['client_id']; ?></div><?php endif; ?>
                    </td>
                    <td>
                      <div style="font-size:13.5px;color:var(--adm-ink);"><?php echo htmlspecialchars($client['concept_style'] ?? 'N/A'); ?></div>
                      <div class="td-sub"><?php echo htmlspecialchars($client['project_type']); ?></div>
                    </td>
                    <td>
                      <div class="td-sub"><?php echo htmlspecialchars($client['email']); ?></div>
                      <div class="td-sub"><?php echo htmlspecialchars($client['phone']); ?></div>
                    </td>
                    <td>
                      <div class="td-sub"><?php echo date('M d, Y', strtotime($client['updated_at'])); ?></div>
                      <?php if ($client['userinfo_id']): ?>
                        <div style="margin-top:6px;"><a href="view-client?id=<?php echo $client['userinfo_id']; ?>" target="_blank" class="adm-btn adm-btn-outline adm-btn-sm"><i class="fas fa-user"></i> View Profile</a></div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="adm-empty">
            <i class="fas fa-user-check"></i>
            <p>No converted clients yet</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- CONVERT MODAL -->
  <div class="adm-modal-bg" id="convertModal">
    <div class="adm-modal-box">
      <div class="adm-modal-head">
        <h3><i class="fas fa-user-plus" style="color:var(--adm-soft);"></i> Convert Inquiry to Client</h3>
        <button class="adm-modal-close" onclick="document.getElementById('convertModal').classList.remove('open')"><i class="fas fa-times"></i></button>
      </div>
      <form method="POST">
        <input type="hidden" name="inquiry_id" id="modal_inquiry_id">

        <!-- Inquiry context panel -->
        <div class="adm-context-box">
          <div class="adm-context-label"><i class="fas fa-palette"></i> Concept Inquiry Reference</div>
          <div id="modal_inquiry_details" class="adm-context-body"></div>
        </div>

        <div class="form-row cols-2">
          <div class="form-group"><label>Client Name <span class="req">*</span></label><input type="text" name="clientname" id="modal_clientname" class="form-control" required></div>
          <div class="form-group"><label>Email <span class="req">*</span></label><input type="email" name="email" id="modal_email" class="form-control" required></div>
          <div class="form-group"><label>Contact <span class="req">*</span></label><input type="text" name="contact" id="modal_contact" class="form-control" required></div>
          <div class="form-group"><label>Gender <span class="req">*</span></label>
            <select name="gender" class="form-control" required>
              <option value="Male">Male</option><option value="Female">Female</option><option value="Other">Other</option><option value="Prefer not to say">Prefer not to say</option>
            </select>
          </div>
          <div class="form-group"><label>Country <span class="req">*</span></label><input type="text" name="country" value="Philippines" class="form-control" required></div>
          <div class="form-group"><label>Status <span class="req">*</span></label>
            <select name="status" class="form-control" required>
              <option value="New Client" selected>New Client</option><option value="Old Client">Old Client</option>
            </select>
          </div>
          <div class="form-group"><label>Client Type <span class="req">*</span></label>
            <select name="client_type" class="form-control" required>
              <option value="Realiving" selected>Realiving</option>
            </select>
          </div>
          <div class="form-group"><label>Classification <span class="req">*</span></label>
            <select name="client_class" class="form-control" required>
              <option value="VIP">VIP</option><option value="Regular" selected>Regular</option><option value="Walk-in">Walk-in</option><option value="Returning">Returning</option>
            </select>
          </div>
          <div class="form-group"><label>Business Type <span class="req">*</span></label>
            <select name="business_type" class="form-control" required>
              <option value="Project" selected>Project</option><option value="Non-Project">Individual</option>
            </select>
          </div>
          <div class="form-group"><label>Project Name <span class="req">*</span></label><input type="text" name="nameproject" class="form-control" required></div>
        </div>

        <div class="form-group" style="margin-bottom:14px;"><label>Address <span class="req">*</span></label><textarea name="address" id="modal_address" rows="2" class="form-control" required></textarea></div>
        <div class="form-group" style="margin-bottom:14px;"><label>Project Scope <span class="req">*</span></label><input type="text" name="project_scope" class="form-control" required placeholder="e.g., Residential Interior Design"></div>
        <div class="form-group" style="margin-bottom:14px;"><label>Scope of Work <span class="req">*</span></label><textarea name="scope_of_work" rows="3" class="form-control" required placeholder="Describe the scope of work…"></textarea></div>

        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-home" style="color:var(--adm-soft);"></i> Property Information</div>
          <div class="form-row cols-2">
            <div class="form-group"><label>State of House <span class="req">*</span></label>
              <select name="house_state" class="form-control" required>
                <option value="" disabled selected>— Select —</option>
                <option value="Bare/Empty Lot">Bare / Empty Lot</option>
                <option value="Existing Structure">Existing Structure (No renovation)</option>
                <option value="Renovation">Existing Structure (For Renovation)</option>
                <option value="Construction Started">Construction Already Started</option>
              </select>
            </div>
            <div class="form-group"><label>Permit Required? <span class="req">*</span></label>
              <select name="permit_required" class="form-control" required>
                <option value="" disabled selected>— Select —</option>
                <option value="Yes">Yes — Permit Required</option>
                <option value="No">No — Not Required</option>
                <option value="Unsure">Unsure — Needs Assessment</option>
              </select>
            </div>
            <div class="form-group">
              <label>Target Move-in Date</label>
              <input type="date" name="target_movein_date" id="modal_movein" class="form-control">
              <label class="form-check">
                <input type="checkbox" id="no_movein" onchange="toggleMovein(this)"> None / Not determined
              </label>
            </div>
          </div>
        </div>

        <div class="form-actions">
          <button type="button" class="adm-btn adm-btn-outline" onclick="document.getElementById('convertModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="adm-btn adm-btn-primary"><i class="fas fa-check"></i> Convert to Client</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function switchTab(tab) {
      document.querySelectorAll('.adm-tab-btn').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.adm-tab-content').forEach(c => c.classList.remove('active'));
      document.getElementById('tab-' + tab).classList.add('active');
      document.getElementById('content-' + tab).classList.add('active');
    }
    function openConvert(inq) {
      document.getElementById('modal_inquiry_id').value = inq.id;
      document.getElementById('modal_clientname').value = inq.name;
      document.getElementById('modal_email').value = inq.email;
      document.getElementById('modal_contact').value = inq.phone;
      document.getElementById('modal_address').value = inq.address || '';
      document.getElementById('modal_inquiry_details').innerHTML =
        `<strong>Style:</strong> ${inq.concept_style || 'N/A'} &nbsp;·&nbsp; <strong>Project:</strong> ${inq.project_type}<br><strong>Interest:</strong> ${inq.know_more_about}`;
      document.getElementById('convertModal').classList.add('open');
    }
    function toggleMovein(cb) {
      const d = document.getElementById('modal_movein');
      d.disabled = cb.checked;
      if (cb.checked) d.value = '';
    }
    document.getElementById('convertModal').addEventListener('click', e => {
      if (e.target === document.getElementById('convertModal')) document.getElementById('convertModal').classList.remove('open');
    });
  </script>
</body>

</html>
<?php $conn->close(); ?>