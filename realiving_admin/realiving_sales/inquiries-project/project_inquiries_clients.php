<?php
//project_inquiries_clients.php
include $includes ['mainbody'];

require_role(['sales', 'superadmin']);

$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['role'] ?? '';

// Handle form submission for adding client from project inquiry
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

    $reference_number = "PREF" . date("YmdHis") . strtoupper(substr(md5(uniqid()), 0, 4));

    $stmt = $conn->prepare("INSERT INTO user_info (clientname, status, nameproject, updatestatus, update_time, reference_number, client_type, client_class, business_type, contact, email, country, address, gender, accountaid_fk, project_scope, scope_of_work, house_state, permit_required, target_movein_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssssssssssissss", $clientname, $status, $nameproject, $status, $updateTime, $reference_number, $client_type, $client_class, $business_type, $contact, $email, $country, $address, $gender, $accountaid_fk, $project_scope, $scope_of_work, $house_state, $permit_required, $target_movein_date);

    if ($stmt->execute()) {
        $client_id = $stmt->insert_id;
        $update_stmt = $conn->prepare("UPDATE project_inquiries SET status = 'completed', client_id = ? WHERE id = ?");
        $update_stmt->bind_param("ii", $client_id, $inquiry_id);
        $update_stmt->execute();
        $update_stmt->close();
        $_SESSION['success_message'] = "Project inquiry successfully converted to client!";
        header("Location: " . BASE_URL . "project-inquiries-clients");
        exit();
    } else {
        $_SESSION['error_message'] = "Error: " . $stmt->error;
    }
    $stmt->close();
}

// Get responded project inquiries not yet completed
$pending_query = ($admin_role === 'superadmin')
    ? "SELECT pi.*, acc.full_name as sales_name, p.title as project_title
       FROM project_inquiries pi
       LEFT JOIN account acc ON pi.assigned_to = acc.id
       LEFT JOIN project p ON pi.project_id = p.id
       WHERE pi.status = 'responded' ORDER BY pi.created_at DESC"
    : "SELECT pi.*, acc.full_name as sales_name, p.title as project_title
       FROM project_inquiries pi
       LEFT JOIN account acc ON pi.assigned_to = acc.id
       LEFT JOIN project p ON pi.project_id = p.id
       WHERE pi.status = 'responded' AND pi.assigned_to = $admin_id ORDER BY pi.created_at DESC";
$pending_inquiries = $conn->query($pending_query);

// Get completed/converted inquiries
$completed_query = ($admin_role === 'superadmin')
    ? "SELECT pi.*, acc.full_name as sales_name, p.title as project_title,
              ui.reference_number, ui.clientname as client_name, ui.id as userinfo_id
       FROM project_inquiries pi
       LEFT JOIN account acc ON pi.assigned_to = acc.id
       LEFT JOIN project p ON pi.project_id = p.id
       LEFT JOIN user_info ui ON pi.client_id = ui.id
       WHERE pi.status = 'completed' ORDER BY pi.updated_at DESC"
    : "SELECT pi.*, acc.full_name as sales_name, p.title as project_title,
              ui.reference_number, ui.clientname as client_name, ui.id as userinfo_id
       FROM project_inquiries pi
       LEFT JOIN account acc ON pi.assigned_to = acc.id
       LEFT JOIN project p ON pi.project_id = p.id
       LEFT JOIN user_info ui ON pi.client_id = ui.id
       WHERE pi.status = 'completed' AND pi.assigned_to = $admin_id ORDER BY pi.updated_at DESC";
$converted_clients = $conn->query($completed_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Project Inquiry Client Conversion — Realiving</title>
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
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}
.app-wrap{max-width:1300px;margin:0 auto;padding:28px 24px;}

.top-bar{display:flex;align-items:center;justify-content:space-between;background:var(--brand);border-radius:var(--radius);padding:18px 28px;margin-bottom:24px;box-shadow:var(--shadow-md);}
.nav-btn{padding:8px 18px;border-radius:30px;font-size:13.5px;font-weight:500;color:rgba(255,255,255,.7);border:none;background:transparent;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:7px;}
.nav-btn:hover{color:#fff;background:rgba(255,255,255,.12);}
.nav-btn.back{background:rgba(255,255,255,.15);color:#fff;}

.alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--radius-sm);margin-bottom:18px;font-size:14px;font-weight:500;}
.alert-success{background:var(--success-bg);color:var(--success);border:1px solid var(--success-border);}
.alert-error{background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-border);}

/* TABS */
.tabs{display:flex;gap:4px;margin-bottom:22px;background:var(--surface);border-radius:var(--radius);padding:6px;box-shadow:var(--shadow);border:1.5px solid var(--border);}
.tab-btn{flex:1;padding:11px 20px;border-radius:10px;border:none;background:transparent;font-family:'Inter',sans-serif;font-size:14px;font-weight:600;color:var(--text-muted);cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;}
.tab-btn.active{background:var(--brand);color:#fff;box-shadow:0 2px 8px rgba(11,11,11,.2);}
.tab-btn:not(.active):hover{background:var(--surface2);color:var(--text);}
.tab-content{display:none;}
.tab-content.active{display:block;}

.section-card{background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow);border:1.5px solid var(--border);overflow:hidden;margin-bottom:22px;}
.section-head{display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1.5px solid var(--border);}
.section-head h2{font-family:'Inter',sans-serif;font-size:15.5px;font-weight:700;display:flex;align-items:center;gap:9px;}

.apt-table{width:100%;border-collapse:collapse;font-size:13.5px;}
.apt-table thead th{padding:11px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);background:var(--surface2);border-bottom:1.5px solid var(--border);}
.apt-table tbody tr{border-bottom:1px solid var(--border);transition:background .15s;}
.apt-table tbody tr:hover{background:var(--hover-bg);}
.apt-table td{padding:13px 16px;vertical-align:top;}
.td-name{font-weight:600;font-size:14px;}
.td-sub{font-size:12px;color:var(--text-muted);margin-top:2px;}

.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;letter-spacing:.3px;text-transform:uppercase;}
.badge-responded{background:var(--success-bg);color:var(--success);}
.badge-completed{background:var(--info-bg);color:var(--info);}
.badge-pending{background:var(--warning-bg);color:var(--warning);}
.badge-project{background:var(--purple-bg);color:var(--purple);}

.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;border:none;cursor:pointer;text-decoration:none;transition:all .18s;font-family:inherit;}
.btn-primary{background:var(--brand);color:#fff;}
.btn-primary:hover{background:var(--brand-mid);}
.btn-sm{padding:6px 13px;font-size:12.5px;}
.btn-outline{background:transparent;border:1.5px solid var(--border);color:var(--text-muted);}
.btn-outline:hover{border-color:var(--brand-light);color:var(--brand);background:var(--surface2);}
.btn-convert{background:var(--brand);color:#fff;border:none;}
.btn-convert:hover{background:var(--brand-mid);transform:translateY(-1px);box-shadow:var(--shadow-md);}

/* MODAL */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(11,11,11,.55);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(3px);}
.modal-bg.open{display:flex;}
.modal-box{background:var(--surface);border-radius:var(--radius);padding:32px;max-width:860px;width:94%;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.22);animation:modalIn .22s ease;}
@keyframes modalIn{from{opacity:0;transform:translateY(16px) scale(.97)}to{opacity:1;transform:none}}
.modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;}
.modal-head h3{font-family:'Inter',sans-serif;font-size:17px;font-weight:700;}
.modal-close{background:none;border:none;font-size:20px;color:var(--text-muted);cursor:pointer;padding:4px;border-radius:6px;}
.modal-close:hover{color:var(--text);background:var(--surface2);}

.form-section{border-top:1.5px solid var(--border);padding-top:20px;margin-top:4px;}
.form-section-title{font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;display:flex;align-items:center;gap:7px;}
.form-row{display:grid;gap:14px;margin-bottom:14px;}
.form-row.cols-2{grid-template-columns:1fr 1fr;}
.form-group label{display:block;font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.form-group label .req{color:var(--danger);}
.form-control{width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:13.5px;font-family:inherit;color:var(--text);background:var(--surface2);transition:border-color .18s;}
.form-control:focus{outline:none;border-color:var(--brand);background:#fff;}
textarea.form-control{resize:vertical;}
.form-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:22px;padding-top:18px;border-top:1.5px solid var(--border);}

.empty-state{text-align:center;padding:52px 20px;color:var(--text-muted);}
.empty-state i{font-size:40px;opacity:.25;display:block;margin-bottom:12px;}

.ref-mono{font-family:'Courier New',monospace;font-size:13px;color:var(--text);background:var(--surface2);padding:3px 8px;border-radius:5px;border:1px solid var(--border);}

@media(max-width:700px){
  .form-row.cols-2{grid-template-columns:1fr;}
}
</style>
</head>
<body>
<div class="app-wrap">

  <div class="top-bar">
    <div style="display:flex;align-items:center;gap:14px;">
      <span style="color:rgba(255,255,255,.4);">|</span>
      <span style="color:rgba(255,255,255,.85);font-size:15px;font-weight:500;">Project Inquiry Client Conversion</span>
    </div>
    <a href="<?= BASE_URL ?>project-inquiries-dashboard" class="nav-btn back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
  </div>

  <?php if(isset($_SESSION['success_message'])): ?>
  <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
  <?php endif; ?>
  <?php if(isset($_SESSION['error_message'])): ?>
  <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
  <?php endif; ?>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-btn active" id="tab-pending" onclick="switchTab('pending')">
      <i class="fas fa-user-clock"></i> Ready to Convert
      <span style="background:rgba(255,255,255,.2);padding:2px 8px;border-radius:10px;font-size:12px;"><?php echo $pending_inquiries->num_rows; ?></span>
    </button>
    <button class="tab-btn" id="tab-converted" onclick="switchTab('converted')">
      <i class="fas fa-user-check"></i> Converted Clients
      <span style="background:rgba(255,255,255,.15);padding:2px 8px;border-radius:10px;font-size:12px;"><?php echo $converted_clients->num_rows; ?></span>
    </button>
  </div>

  <!-- PENDING TAB -->
  <div class="tab-content active" id="content-pending">
    <div class="section-card">
      <div class="section-head">
        <h2><i class="fas fa-clock" style="color:var(--warning);"></i> Responded Project Inquiries — Ready to Convert</h2>
      </div>
      <?php if($pending_inquiries->num_rows > 0): ?>
      <div style="overflow-x:auto;">
        <table class="apt-table">
          <thead>
            <tr>
              <th>Client</th>
              <th>Contact</th>
              <th>Project</th>
              <th>Type</th>
              <th>Status</th>
              <?php if($admin_role==='superadmin'): ?><th>Assigned</th><?php endif; ?>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php while($inq = $pending_inquiries->fetch_assoc()): ?>
          <tr>
            <td>
              <div class="td-name"><?php echo htmlspecialchars($inq['name']); ?></div>
              <?php if($inq['location']): ?><div class="td-sub"><i class="fas fa-map-marker-alt" style="opacity:.5;"></i> <?php echo htmlspecialchars(substr($inq['location'],0,50)).(strlen($inq['location'])>50?'…':''); ?></div><?php endif; ?>
            </td>
            <td>
              <div class="td-sub"><i class="fas fa-envelope" style="opacity:.5;"></i> <?php echo htmlspecialchars($inq['email']); ?></div>
              <div class="td-sub"><i class="fas fa-phone" style="opacity:.5;"></i> <?php echo htmlspecialchars($inq['phone']); ?></div>
            </td>
            <td>
              <?php if($inq['project_title']): ?>
              <div style="font-size:13.5px;font-weight:600;color:var(--purple);"><i class="fas fa-building"></i> <?php echo htmlspecialchars($inq['project_title']); ?></div>
              <?php else: ?><span class="td-sub">—</span><?php endif; ?>
            </td>
            <td class="td-sub"><?php echo htmlspecialchars($inq['inquiry_type'] ?? '—'); ?></td>
            <td><span class="badge badge-responded"><?php echo ucfirst($inq['status']); ?></span></td>
            <?php if($admin_role==='superadmin'): ?><td class="td-sub"><i class="fas fa-user" style="opacity:.5;"></i> <?php echo htmlspecialchars($inq['sales_name']??'—'); ?></td><?php endif; ?>
            <td>
              <button onclick="openConvert(<?php echo htmlspecialchars(json_encode($inq)); ?>)" class="btn btn-convert btn-sm">
                <i class="fas fa-user-plus"></i> Convert to Client
              </button>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="empty-state"><i class="fas fa-user-clock"></i><p>No responded inquiries ready for conversion</p><p style="font-size:13px;margin-top:6px;">Inquiries must be in <strong>responded</strong> status before conversion</p></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- CONVERTED TAB -->
  <div class="tab-content" id="content-converted">
    <div class="section-card">
      <div class="section-head">
        <h2><i class="fas fa-user-check" style="color:var(--success);"></i> Converted Clients</h2>
      </div>
      <?php if($converted_clients->num_rows > 0): ?>
      <div style="overflow-x:auto;">
        <table class="apt-table">
          <thead>
            <tr>
              <th>Reference</th>
              <th>Client</th>
              <th>Project</th>
              <th>Contact</th>
              <th>Converted</th>
            </tr>
          </thead>
          <tbody>
          <?php while($client = $converted_clients->fetch_assoc()): ?>
          <tr>
            <td>
              <?php if($client['reference_number']): ?><span class="ref-mono"><?php echo htmlspecialchars($client['reference_number']); ?></span><?php else: ?><span class="td-sub">—</span><?php endif; ?>
            </td>
            <td>
              <div class="td-name"><?php echo htmlspecialchars($client['name']); ?></div>
              <?php if($client['client_name']): ?><div class="td-sub" style="color:var(--success);"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($client['client_name']); ?></div><?php endif; ?>
              <?php if($client['client_id']): ?><div class="td-sub">Client ID: #<?php echo $client['client_id']; ?></div><?php endif; ?>
            </td>
            <td>
              <?php if($client['project_title']): ?>
              <div style="font-size:13.5px;font-weight:600;color:var(--purple);"><i class="fas fa-building"></i> <?php echo htmlspecialchars($client['project_title']); ?></div>
              <?php else: ?><span class="td-sub">—</span><?php endif; ?>
            </td>
            <td>
              <div class="td-sub"><?php echo htmlspecialchars($client['email']); ?></div>
              <div class="td-sub"><?php echo htmlspecialchars($client['phone']); ?></div>
            </td>
            <td>
              <div class="td-sub"><?php echo date('M d, Y', strtotime($client['updated_at'])); ?></div>
              <?php if($client['userinfo_id']): ?>
              <div style="margin-top:4px;"><a href="view_client.php?id=<?php echo $client['userinfo_id']; ?>" target="_blank" class="btn btn-sm btn-outline" style="font-size:11px;padding:4px 9px;"><i class="fas fa-user"></i> View Profile</a></div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="empty-state"><i class="fas fa-user-check"></i><p>No converted clients yet</p></div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- CONVERT MODAL -->
<div class="modal-bg" id="convertModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3><i class="fas fa-user-plus" style="color:var(--brand-light);"></i> Convert Project Inquiry to Client</h3>
      <button class="modal-close" onclick="document.getElementById('convertModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="inquiry_id" id="modal_inquiry_id">

      <!-- Inquiry context panel -->
      <div style="background:var(--purple-bg);border:1.5px solid var(--purple-border);border-radius:var(--radius-sm);padding:13px 16px;margin-bottom:18px;">
        <div style="font-size:11px;font-weight:700;color:var(--purple);text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;"><i class="fas fa-building"></i> Project Inquiry Reference</div>
        <div id="modal_inquiry_details" style="font-size:13px;color:var(--text);"></div>
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
        <div class="form-group"><label>Project Name <span class="req">*</span></label><input type="text" name="nameproject" id="modal_project_name" class="form-control" required></div>
      </div>

      <div class="form-group" style="margin-bottom:14px;"><label>Address <span class="req">*</span></label><textarea name="address" id="modal_address" rows="2" class="form-control" required></textarea></div>
      <div class="form-group" style="margin-bottom:14px;"><label>Project Scope <span class="req">*</span></label><input type="text" name="project_scope" id="modal_project_scope" class="form-control" required placeholder="e.g., Commercial Interior Design"></div>
      <div class="form-group" style="margin-bottom:14px;"><label>Scope of Work <span class="req">*</span></label><textarea name="scope_of_work" id="modal_scope_work" rows="3" class="form-control" required placeholder="Describe the scope of work…"></textarea></div>

      <div class="form-section">
        <div class="form-section-title"><i class="fas fa-home" style="color:var(--brand-light);"></i> Property Information</div>
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
            <label style="display:flex;align-items:center;gap:7px;margin-top:8px;font-size:12.5px;color:var(--text-muted);cursor:pointer;font-weight:normal;">
              <input type="checkbox" id="no_movein" onchange="toggleMovein(this)" style="accent-color:var(--brand);"> None / Not determined
            </label>
          </div>
        </div>
      </div>

      <div class="form-actions">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('convertModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-convert"><i class="fas fa-check"></i> Convert to Client</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchTab(tab) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  document.getElementById('content-' + tab).classList.add('active');
}

function openConvert(inq) {
  document.getElementById('modal_inquiry_id').value = inq.id;
  document.getElementById('modal_clientname').value = inq.name;
  document.getElementById('modal_email').value = inq.email;
  document.getElementById('modal_contact').value = inq.phone;
  document.getElementById('modal_address').value = inq.location || '';

  // Leave sales fields blank for manual entry
  document.getElementById('modal_project_name').value = '';
  document.getElementById('modal_project_scope').value = '';
  document.getElementById('modal_scope_work').value = '';

  document.getElementById('modal_inquiry_details').innerHTML =
    `<strong>Project:</strong> ${inq.project_title || 'N/A'} &nbsp;·&nbsp; <strong>Type:</strong> ${inq.inquiry_type || 'N/A'}<br><strong>Customer Location:</strong> ${inq.location || 'N/A'}`;

  // Reset move-in date
  document.getElementById('modal_movein').disabled = false;
  document.getElementById('no_movein').checked = false;

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