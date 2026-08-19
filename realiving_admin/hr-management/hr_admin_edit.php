<?php
//hr-admin-edit.php
include $includes['mainbody'];

require_role(['human_resource']);

$errors = [];

$edit_id = (int)($_GET['id'] ?? 0);
if (!$edit_id) {
  $_SESSION['noti'] = "No employee selected.";
  header("Location: " . BASE_URL . "hr-admin-management");
  exit;
}

// Block HR from ever loading a super_admin's record, even via manual URL edit
$roleCheck = $conn->prepare("SELECT role FROM account WHERE id = ?");
$roleCheck->bind_param('i', $edit_id);
$roleCheck->execute();
$roleRow = $roleCheck->get_result()->fetch_assoc();

if (!$roleRow || $roleRow['role'] === 'super_admin') {
  $_SESSION['noti'] = "You are not permitted to edit this account.";
  header("Location: " . BASE_URL . "hr-admin-management");
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $full_name  = trim($_POST['full_name'] ?? '');
  $admin_name = trim($_POST['admin_name'] ?? '');
  $email      = trim($_POST['email'] ?? '');
  $role       = trim($_POST['role'] ?? '');
  $is_head    = isset($_POST['is_head']) ? 1 : 0;
  $password   = $_POST['password'] ?? '';
  $confirm    = $_POST['confirm_password'] ?? '';

  if ($full_name === '') $errors[] = "Full name is required.";
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "A valid email is required.";
  if ($role === '' || $role === 'super_admin') $errors[] = "Invalid role selected.";

  if ($password !== '' || $confirm !== '') {
    if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";
    if ($password !== $confirm) $errors[] = "Passwords do not match.";
  }

  if (empty($errors)) {
    $check = $conn->prepare("SELECT id FROM account WHERE email = ? AND id != ?");
    $check->bind_param('si', $email, $edit_id);
    $check->execute();
    if ($check->get_result()->fetch_assoc()) {
      $errors[] = "That email is already used by another account.";
    }
  }

  if (empty($errors)) {
    if ($password !== '') {
      $hashed = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $conn->prepare("UPDATE account SET full_name = ?, admin_name = ?, email = ?, role = ?, is_head = ?, password = ? WHERE id = ?");
      $stmt->bind_param('ssssisi', $full_name, $admin_name, $email, $role, $is_head, $hashed, $edit_id);
    } else {
      $stmt = $conn->prepare("UPDATE account SET full_name = ?, admin_name = ?, email = ?, role = ?, is_head = ? WHERE id = ?");
      $stmt->bind_param('ssssii', $full_name, $admin_name, $email, $role, $is_head, $edit_id);
    }

    if ($stmt->execute()) {
      $_SESSION['noti'] = "Employee account updated successfully.";
      header("Location: " . BASE_URL . "hr-admin-management");
      exit;
    } else {
      $errors[] = "Database error. Please try again.";
    }
  }
}

$stmt = $conn->prepare("SELECT id, full_name, admin_name, email, role, is_head, account_status, profile_picture, google_picture, avatar_source FROM account WHERE id = ?");
$stmt->bind_param('i', $edit_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

if (!$admin) {
  $_SESSION['noti'] = "Employee not found.";
  header("Location: " . BASE_URL . "hr-admin-management");
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
  $admin['full_name']  = $_POST['full_name'] ?? $admin['full_name'];
  $admin['admin_name'] = $_POST['admin_name'] ?? $admin['admin_name'];
  $admin['email']      = $_POST['email'] ?? $admin['email'];
  $admin['role']       = $_POST['role'] ?? $admin['role'];
  $admin['is_head']    = isset($_POST['is_head']) ? 1 : 0;
}

// Roles for dropdown — super_admin intentionally excluded
$roles = [];
$role_res = $conn->query("SELECT DISTINCT role FROM account WHERE role != 'super_admin' ORDER BY role ASC");
while ($r = $role_res->fetch_assoc()) {
  $roles[] = $r['role'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Employee - RealLiving</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>logo/favicon.ico">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
    crossorigin="anonymous" referrerpolicy="no-referrer" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --adm-bg: #F5F5F5;
      --adm-surface: #FFFFFF;
      --adm-ink: #0B0B0B;
      --adm-soft: #6B6B6B;
      --adm-muted: #9A9A9A;
      --adm-line: #E2E2E2;
      --adm-online: #16A34A;
      --adm-suspend: #DC2626;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--adm-bg);
      color: var(--adm-ink);
    }

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

    .adm-btn {
      font-size: 12.5px;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: .65rem 1.1rem;
      border-radius: 8px;
      border: 1px solid var(--adm-line);
      background: #fff;
      color: var(--adm-ink);
      cursor: pointer;
      transition: border-color .15s ease, background .15s ease;
    }

    .adm-btn:hover {
      border-color: var(--adm-ink);
    }

    .adm-btn-dark {
      background: var(--adm-ink);
      color: #fff;
      border-color: var(--adm-ink);
    }

    .adm-btn-dark:hover {
      opacity: .9;
    }

    .adm-card {
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      padding: 1.75rem;
    }

    .adm-section-label {
      font-size: 12px;
      font-weight: 600;
      color: var(--adm-ink);
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 1.1rem;
    }

    .adm-section-label::after {
      content: "";
      flex: 1;
      height: 1px;
      background: var(--adm-line);
    }

    .adm-field-label {
      font-size: 12.5px;
      font-weight: 600;
      color: var(--adm-ink);
      display: block;
      margin-bottom: .4rem;
    }

    .adm-field-hint {
      font-size: 11.5px;
      color: var(--adm-muted);
      margin-top: .35rem;
    }

    .adm-input, .adm-select {
      width: 100%;
      font-size: 13.5px;
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      padding: .65rem .85rem;
      background: #fff;
      color: var(--adm-ink);
      outline: none;
    }

    .adm-input:focus, .adm-select:focus {
      border-color: var(--adm-ink);
    }

    .adm-field {
      margin-bottom: 1.25rem;
    }

    .adm-toggle-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      padding: .85rem 1rem;
    }

    .adm-toggle-label {
      font-size: 13px;
      font-weight: 600;
      color: var(--adm-ink);
    }

    .adm-toggle-desc {
      font-size: 11.5px;
      color: var(--adm-soft);
      margin-top: .15rem;
    }

    .adm-switch {
      position: relative;
      display: inline-block;
      width: 40px;
      height: 22px;
      flex-shrink: 0;
    }

    .adm-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .adm-switch-slider {
      position: absolute;
      cursor: pointer;
      inset: 0;
      background-color: var(--adm-line);
      border-radius: 999px;
      transition: .2s;
    }

    .adm-switch-slider::before {
      content: "";
      position: absolute;
      height: 16px;
      width: 16px;
      left: 3px;
      bottom: 3px;
      background-color: #fff;
      border-radius: 50%;
      transition: .2s;
    }

    .adm-switch input:checked + .adm-switch-slider {
      background-color: var(--adm-ink);
    }

    .adm-switch input:checked + .adm-switch-slider::before {
      transform: translateX(18px);
    }

    .adm-error-box {
      background: #FEF2F2;
      border: 1px solid #FECACA;
      color: var(--adm-suspend);
      border-radius: 8px;
      padding: .85rem 1rem;
      font-size: 13px;
      margin-bottom: 1.5rem;
    }

    .adm-error-box ul {
      margin: .3rem 0 0 1.1rem;
      padding: 0;
    }

    .adm-avatar-lg {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      font-weight: 700;
      color: var(--adm-ink);
    }

    .adm-toast {
      background: #fff;
      border-left: 3px solid var(--adm-ink);
      box-shadow: 0 12px 32px -14px rgba(11, 11, 11, 0.3);
    }

    @keyframes adm-fade {
      from { opacity: 0; transform: translateY(8px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .adm-fade { animation: adm-fade .4s ease both; }

    @media (prefers-reduced-motion: reduce) {
      .adm-fade { animation: none; }
    }
  </style>
</head>

<body class="min-h-screen flex flex-col">

  <?php if (isset($_SESSION['noti'])): ?>
    <div id="notifBox" class="adm-toast fixed top-20 right-4 rounded-lg p-4 w-80 adm-fade z-50">
      <div class="flex items-start">
        <i class="fa-solid fa-circle-info mt-0.5 mr-3 text-base" style="color:var(--adm-ink);"></i>
        <div>
          <p class="text-[10px] font-semibold uppercase tracking-[1px]" style="color:var(--adm-soft);">Notification</p>
          <p class="text-[13px] mt-1" style="color:var(--adm-ink);"><?= $_SESSION['noti']; ?></p>
        </div>
        <button onclick="this.parentElement.parentElement.remove()" class="ml-auto pl-3" style="color:var(--adm-soft);">
          <i class="fas fa-times text-xs"></i>
        </button>
      </div>
    </div>
    <script>
      setTimeout(function () {
        var notif = document.getElementById("notifBox");
        if (notif) {
          notif.classList.add('opacity-0', 'transition-opacity', 'duration-300');
          setTimeout(() => notif.remove(), 300);
        }
      }, 3000);
    </script>
    <?php unset($_SESSION['noti']); ?>
  <?php endif; ?>

  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-3xl mx-auto w-full">

    <div class="mb-8 adm-fade flex items-start justify-between flex-wrap gap-4">
      <div>
        <div class="adm-eyebrow mb-2">Human Resource</div>
        <h1 class="adm-title">Edit Employee</h1>
        <p class="adm-subtitle mt-1">Update account details and role.</p>
      </div>
      <a href="<?= BASE_URL ?>hr-admin-management" class="adm-btn"><i class="fas fa-arrow-left"></i> Back to List</a>
    </div>

    <div class="mb-6 adm-fade flex items-center gap-3">
      <?= renderAvatarHtml($admin, 'adm-avatar-lg') ?>
      <div>
        <div style="font-size:15px; font-weight:700;"><?= htmlspecialchars($admin['full_name'] ?: '(No name set)') ?></div>
        <div style="font-size:12.5px; color: var(--adm-soft);">
          <?= htmlspecialchars($admin['email']) ?>
          &middot;
          <?= $admin['account_status'] === 'suspended' ? '<span style="color:var(--adm-suspend); font-weight:600;">Suspended</span>' : '<span style="color:var(--adm-online); font-weight:600;">Active</span>' ?>
        </div>
      </div>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="adm-error-box adm-fade">
        <strong>Please fix the following:</strong>
        <ul>
          <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST" class="adm-card adm-fade">

      <div class="adm-section-label">Basic Information</div>

      <div class="adm-field">
        <label class="adm-field-label" for="full_name">Full Name</label>
        <input type="text" id="full_name" name="full_name" class="adm-input" value="<?= htmlspecialchars($admin['full_name']) ?>" required>
      </div>

      <div class="adm-field">
        <label class="adm-field-label" for="admin_name">Display / Username</label>
        <input type="text" id="admin_name" name="admin_name" class="adm-input" value="<?= htmlspecialchars($admin['admin_name'] ?? '') ?>">
      </div>

      <div class="adm-field">
        <label class="adm-field-label" for="email">Email</label>
        <input type="email" id="email" name="email" class="adm-input" value="<?= htmlspecialchars($admin['email']) ?>" required>
      </div>

      <div class="adm-field">
        <label class="adm-field-label" for="role">Role</label>
        <select id="role" name="role" class="adm-select" required>
          <?php foreach ($roles as $r): ?>
            <option value="<?= htmlspecialchars($r) ?>" <?= $admin['role'] === $r ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($r)) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="adm-field-hint">Super admin accounts are managed separately and are not available here.</p>
      </div>

      <div class="adm-field">
        <div class="adm-toggle-row">
          <div>
            <div class="adm-toggle-label">Head Admin</div>
            <div class="adm-toggle-desc">Marks this account as a department head.</div>
          </div>
          <label class="adm-switch">
            <input type="checkbox" name="is_head" <?= !empty($admin['is_head']) ? 'checked' : '' ?>>
            <span class="adm-switch-slider"></span>
          </label>
        </div>
      </div>

      <div class="adm-section-label" style="margin-top: 2rem;">Reset Password</div>

      <div class="adm-field">
        <label class="adm-field-label" for="password">New Password</label>
        <input type="password" id="password" name="password" class="adm-input" placeholder="Leave blank to keep current password" autocomplete="new-password">
      </div>

      <div class="adm-field">
        <label class="adm-field-label" for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" class="adm-input" placeholder="Re-enter new password" autocomplete="new-password">
        <p class="adm-field-hint">Minimum 8 characters. Only fill this in if you want to change the password.</p>
      </div>

      <div class="flex gap-2 mt-6">
        <a href="<?= BASE_URL ?>hr-admin-management" class="adm-btn flex-1" style="justify-content:center;">Cancel</a>
        <button type="submit" class="adm-btn adm-btn-dark flex-1" style="justify-content:center;">Save Changes</button>
      </div>

    </form>

    <!-- ═══════════════════════════════════════════════ -->
    <!--   EMPLOYEE DOCUMENTS                              -->
    <!-- ═══════════════════════════════════════════════ -->
    <div class="adm-card adm-fade mt-6">
      <div class="adm-section-label">Employee Documents</div>

      <!-- Upload form -->
      <div class="mb-6 p-4" style="border: 1px dashed var(--adm-line); border-radius: 8px;">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
          <div>
            <label class="adm-field-label" for="doc-label">Label</label>
            <input type="text" id="doc-label" class="adm-input" placeholder="e.g. Resume, Valid ID, NBI Clearance">
          </div>
          <div>
            <label class="adm-field-label" for="doc-file">File (PDF, PNG, JPG, WEBP — max 50MB)</label>
            <input type="file" id="doc-file" class="adm-input" accept=".pdf,image/png,image/jpeg,image/webp">
          </div>
        </div>
        <button type="button" id="upload-doc-btn" class="adm-btn adm-btn-dark">
          <i class="fas fa-upload"></i> Upload Document
        </button>
        <p id="doc-upload-status" class="adm-field-hint" style="display:none;"></p>
      </div>

      <!-- Summary counts -->
      <div id="doc-summary" class="flex gap-3 mb-4" style="display:none;">
        <div style="font-size:12px; color: var(--adm-soft);">
          <span id="doc-count-total" style="font-weight:700; color: var(--adm-ink);">0</span> total documents
        </div>
      </div>

      <!-- Grouped document list -->
      <div id="doc-list">
        <p class="adm-card-desc" style="margin:0;">Loading documents...</p>
      </div>
    </div>

    <style>
      .doc-group-title {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: var(--adm-soft);
        margin: 1.25rem 0 .6rem 0;
      }
      .doc-group-title:first-child { margin-top: 0; }
      .doc-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: .7rem .85rem;
        border: 1px solid var(--adm-line);
        border-radius: 8px;
        margin-bottom: .5rem;
        transition: border-color .15s ease;
      }
      .doc-row:hover { border-color: var(--adm-ink); }
      .doc-icon-wrap {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background: var(--adm-bg);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
      }
      .doc-icon-wrap.pdf i { color: #DC2626; }
      .doc-icon-wrap.image i { color: #2563EB; }
      .doc-meta {
        font-size: 11px;
        color: var(--adm-muted);
      }
    </style>

  </div>

  <script>
    const EMPLOYEE_ID = <?= (int) $edit_id ?>;

    function loadDocuments() {
      fetch('<?= BASE_URL ?>get-employee-documents?account_id=' + EMPLOYEE_ID)
        .then(r => r.json())
        .then(data => {
          const list = document.getElementById('doc-list');
          const summary = document.getElementById('doc-summary');

          if (!data.success || !data.documents.length) {
            list.innerHTML = '<p class="adm-card-desc" style="margin:0;">No documents uploaded yet.</p>';
            summary.style.display = 'none';
            return;
          }

          summary.style.display = 'flex';
          document.getElementById('doc-count-total').textContent = data.documents.length;

          const pdfs = data.documents.filter(d => d.file_type === 'pdf');
          const images = data.documents.filter(d => d.file_type === 'image');

          let html = '';
          if (pdfs.length) {
            html += `<div class="doc-group-title">PDF Documents (${pdfs.length})</div>`;
            html += pdfs.map(renderDocRow).join('');
          }
          if (images.length) {
            html += `<div class="doc-group-title">Images (${images.length})</div>`;
            html += images.map(renderDocRow).join('');
          }
          list.innerHTML = html;
        })
        .catch(() => {
          document.getElementById('doc-list').innerHTML = '<p class="adm-card-desc" style="margin:0;">Failed to load documents.</p>';
        });
    }

    function renderDocRow(doc) {
      const icon = doc.file_type === 'pdf' ? 'fa-file-pdf' : 'fa-file-image';
      const sizeText = doc.file_size ? ` · ${doc.file_size}` : '';
      return `
        <div class="doc-row" id="doc-row-${doc.id}">
          <div class="flex items-center gap-3" style="min-width:0;">
            <div class="doc-icon-wrap ${doc.file_type}"><i class="fas ${icon}"></i></div>
            <div style="min-width:0;">
              <div style="font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${doc.label}</div>
              <div class="doc-meta">By ${doc.uploader_name} · ${doc.uploaded_at}${sizeText}</div>
            </div>
          </div>
          <div class="flex items-center gap-2" style="flex-shrink:0;">
            <a href="${doc.file_url}" target="_blank" class="adm-icon-btn" title="View"><i class="fas fa-eye"></i></a>
            <button type="button" class="adm-icon-btn danger" title="Delete" onclick="deleteDocument(${doc.id})"><i class="fas fa-trash"></i></button>
          </div>
        </div>`;
    }

    document.getElementById('upload-doc-btn').addEventListener('click', function () {
      const label = document.getElementById('doc-label').value.trim();
      const fileInput = document.getElementById('doc-file');
      const status = document.getElementById('doc-upload-status');

      if (!label) { alert('Please enter a label for this document.'); return; }
      if (!fileInput.files[0]) { alert('Please choose a file to upload.'); return; }

      const formData = new FormData();
      formData.append('account_id', EMPLOYEE_ID);
      formData.append('label', label);
      formData.append('document', fileInput.files[0]);

      this.disabled = true;
      this.textContent = 'Uploading...';

      fetch('<?= BASE_URL ?>hr-upload-document', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            document.getElementById('doc-label').value = '';
            fileInput.value = '';
            loadDocuments();
          } else {
            status.textContent = data.message || 'Upload failed.';
            status.style.display = 'block';
            status.style.color = 'var(--adm-suspend)';
          }
        })
        .catch(() => {
          status.textContent = 'Network error. Please try again.';
          status.style.display = 'block';
          status.style.color = 'var(--adm-suspend)';
        })
        .finally(() => {
          this.disabled = false;
          this.innerHTML = '<i class="fas fa-upload"></i> Upload Document';
        });
    });

    function deleteDocument(id) {
      if (!confirm('Delete this document? This cannot be undone.')) return;
      fetch('<?= BASE_URL ?>hr-delete-document', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(id)
      })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            document.getElementById(`doc-row-${id}`).remove();
          } else {
            alert(data.message || 'Failed to delete.');
          }
        })
        .catch(() => alert('Network error. Please try again.'));
    }

    loadDocuments();
  </script>

</body>

</html>