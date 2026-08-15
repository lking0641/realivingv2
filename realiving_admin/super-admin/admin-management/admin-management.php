<?php
//admin-management.php
include $includes['mainbody'];

require_role(['super_admin']);

// Prevent superadmin from suspending themselves via this page (safety net; also enforced in AJAX endpoint)
$current_admin_id = $_SESSION['admin_id'] ?? 0;

// Filters
$role_filter   = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search        = trim($_GET['search'] ?? '');

$where = [];
$params = [];
$types = '';

if ($role_filter !== '') {
  $where[] = "role = ?";
  $params[] = $role_filter;
  $types .= 's';
}

if ($status_filter !== '') {
  $where[] = "account_status = ?";
  $params[] = $status_filter;
  $types .= 's';
}

if ($search !== '') {
  $where[] = "(full_name LIKE ? OR admin_name LIKE ? OR email LIKE ?)";
  $like = "%$search%";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $types .= 'sss';
}

$sql = "SELECT id, full_name, admin_name, email, role, account_status, is_online, is_head, last_activity, created_at FROM account";
if (!empty($where)) {
  $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY full_name ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$admins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Distinct roles for filter dropdown
$roles = [];
$role_res = $conn->query("SELECT DISTINCT role FROM account ORDER BY role ASC");
while ($r = $role_res->fetch_assoc()) {
  $roles[] = $r['role'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Management - RealLiving</title>
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

    /* Filter bar */
    .adm-filterbar {
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      padding: 1rem 1.25rem;
    }

    .adm-input, .adm-select {
      font-size: 13px;
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      padding: .55rem .75rem;
      background: #fff;
      color: var(--adm-ink);
      outline: none;
    }

    .adm-input:focus, .adm-select:focus {
      border-color: var(--adm-ink);
    }

    /* Table */
    .adm-table-wrap {
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      overflow: hidden;
    }

    table.adm-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }

    .adm-table thead th {
      text-align: left;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .5px;
      color: var(--adm-soft);
      padding: .9rem 1.1rem;
      border-bottom: 1px solid var(--adm-line);
      background: var(--adm-bg);
    }

    .adm-table tbody td {
      padding: .9rem 1.1rem;
      border-bottom: 1px solid var(--adm-line);
      vertical-align: middle;
      color: var(--adm-ink);
    }

    .adm-table tbody tr:last-child td {
      border-bottom: none;
    }

    .adm-table tbody tr:hover {
      background: #FAFAFA;
    }

    .adm-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      font-weight: 700;
      color: var(--adm-ink);
      flex-shrink: 0;
    }

    .adm-name-cell {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .adm-name-primary {
      font-weight: 600;
      font-size: 13.5px;
    }

    .adm-name-secondary {
      font-size: 12px;
      color: var(--adm-soft);
    }

    .adm-pill {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: 11px;
      font-weight: 600;
      padding: .3rem .6rem;
      border-radius: 999px;
      text-transform: capitalize;
    }

    .adm-pill-role {
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      color: var(--adm-ink);
    }

    .adm-pill-active {
      background: #ECFDF5;
      color: var(--adm-online);
      border: 1px solid #BBF7D0;
    }

    .adm-pill-suspended {
      background: #FEF2F2;
      color: var(--adm-suspend);
      border: 1px solid #FECACA;
    }

    .adm-online-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--adm-muted);
      display: inline-block;
      margin-right: 5px;
    }

    .adm-online-dot.is-online {
      background: var(--adm-online);
    }

    .adm-row-actions {
      display: flex;
      align-items: center;
      gap: .5rem;
      justify-content: flex-end;
    }

    .adm-icon-btn {
      width: 30px;
      height: 30px;
      border-radius: 7px;
      border: 1px solid var(--adm-line);
      background: #fff;
      color: var(--adm-soft);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 12.5px;
      transition: border-color .15s ease, color .15s ease;
    }

    .adm-icon-btn:hover {
      border-color: var(--adm-ink);
      color: var(--adm-ink);
    }

    .adm-icon-btn.danger:hover {
      border-color: var(--adm-suspend);
      color: var(--adm-suspend);
    }

    .adm-icon-btn.success:hover {
      border-color: var(--adm-online);
      color: var(--adm-online);
    }

    .adm-empty {
      text-align: center;
      padding: 3rem 1rem;
      color: var(--adm-soft);
      font-size: 13.5px;
    }

    .adm-toast {
      background: #fff;
      border-left: 3px solid var(--adm-ink);
      box-shadow: 0 12px 32px -14px rgba(11, 11, 11, 0.3);
    }

    #confirmModal {
      display: none;
    }

    .adm-confirm-icon {
      width: 44px;
      height: 44px;
      border-radius: 10px;
      background: #FEF2F2;
      color: var(--adm-suspend);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      margin-bottom: 1.1rem;
    }

    .adm-confirm-title {
      font-size: 16.5px;
      font-weight: 700;
      color: var(--adm-ink);
      margin-bottom: .4rem;
    }

    .adm-confirm-message {
      font-size: 13.5px;
      line-height: 1.5;
      color: var(--adm-soft);
      margin-bottom: 0;
    }

    #confirmModal.show {
      display: flex;
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

  <!-- Notification -->
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

  <!-- Confirmation Modal -->
  <div id="confirmModal" class="fixed inset-0 items-center justify-center" style="background: rgba(11,11,11,0.5); backdrop-filter: blur(2px); z-index: 9999;">
    <div class="rounded-xl p-7 w-[360px]" style="background:#fff; box-shadow: 0 24px 60px -20px rgba(11,11,11,0.35);">
      <div class="adm-confirm-icon" id="confirmIcon">
        <i class="fas fa-triangle-exclamation"></i>
      </div>
      <h3 id="confirmTitle" class="adm-confirm-title">Are you sure?</h3>
      <p id="confirmMessage" class="adm-confirm-message"></p>

      <div id="confirmTypeWrap" class="mt-4" style="display:none;">
        <label class="adm-field-label" for="confirmTypeInput" style="display:block; font-size:12px; font-weight:600; color:var(--adm-ink); margin-bottom:.4rem;">
          Type <span id="confirmTypeTarget" style="font-weight:700;"></span> to confirm
        </label>
        <input type="text" id="confirmTypeInput" class="adm-input" autocomplete="off" placeholder="Type here...">
      </div>

      <div class="flex gap-2 mt-6">
        <button id="confirmCancelBtn" class="adm-btn flex-1" style="justify-content:center;">Cancel</button>
        <button id="confirmOkBtn" class="adm-btn adm-btn-dark flex-1" style="justify-content:center;">Confirm</button>
      </div>
    </div>
  </div>

  <!-- Toast for AJAX actions -->
  <div id="ajaxToast" class="adm-toast fixed top-20 right-4 rounded-lg p-4 w-80 z-50" style="display:none;">
    <div class="flex items-start">
      <i id="ajaxToastIcon" class="fa-solid fa-circle-check mt-0.5 mr-3 text-base" style="color:var(--adm-online);"></i>
      <div>
        <p id="ajaxToastLabel" class="text-[10px] font-semibold uppercase tracking-[1px]" style="color:var(--adm-soft);">Success</p>
        <p id="ajaxToastMsg" class="text-[13px] mt-1" style="color:var(--adm-ink);"></p>
      </div>
    </div>
  </div>

  <!-- Main Content -->
  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">

    <!-- Header -->
    <div class="mb-8 adm-fade flex items-start justify-between flex-wrap gap-4">
      <div>
        <div class="adm-eyebrow mb-2">Super Admin</div>
        <h1 class="adm-title">Admin Management</h1>
        <p class="adm-subtitle mt-1">View, edit, and control access for admin accounts.</p>
      </div>
      <div class="flex gap-2">
        <a href="<?= BASE_URL ?>admin-mainpage" class="adm-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <a href="<?= BASE_URL ?>admin-add" class="adm-btn adm-btn-dark"><i class="fas fa-plus"></i> Add Admin</a>
      </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="adm-filterbar mb-6 adm-fade flex flex-wrap gap-3 items-center">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or email..." class="adm-input flex-1 min-w-[200px]">

      <select name="role" class="adm-select">
        <option value="">All Roles</option>
        <?php foreach ($roles as $r): ?>
          <option value="<?= htmlspecialchars($r) ?>" <?= $role_filter === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="status" class="adm-select">
        <option value="">All Statuses</option>
        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="suspended" <?= $status_filter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
      </select>

      <button type="submit" class="adm-btn"><i class="fas fa-filter"></i> Filter</button>
      <?php if ($search || $role_filter || $status_filter): ?>
        <a href="<?= BASE_URL ?>admin-management" class="adm-btn">Clear</a>
      <?php endif; ?>
    </form>

    <!-- Table -->
    <div class="adm-table-wrap adm-fade">
      <?php if (empty($admins)): ?>
        <div class="adm-empty">
          <i class="fas fa-user-slash mb-2" style="font-size:22px;"></i>
          <p>No admin accounts match your filters.</p>
        </div>
      <?php else: ?>
        <table class="adm-table">
          <thead>
            <tr>
              <th>Admin</th>
              <th>Role</th>
              <th>Status</th>
              <th>Last Active</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody id="adminTableBody">
            <?php foreach ($admins as $a): ?>
              <tr id="row-<?= $a['id'] ?>">
                <td>
                  <div class="adm-name-cell">
                    <div class="adm-avatar"><?= strtoupper(substr($a['full_name'], 0, 1)) ?></div>
                    <div>
                      <div class="adm-name-primary">
                        <span id="online-dot-<?= $a['id'] ?>" class="adm-online-dot <?= isAdminOnline($a['is_online'], $a['last_activity']) ? 'is-online' : '' ?>"></span><?= htmlspecialchars($a['full_name']) ?>
                        <?php if ($a['is_head']): ?>
                          <i class="fas fa-crown ml-1" style="font-size:10px; color:#B45309;" title="Head Admin"></i>
                        <?php endif; ?>
                        <?php if ($a['id'] == $current_admin_id): ?>
                          <span style="font-size:10.5px; color: var(--adm-soft); font-weight:400;">(You)</span>
                        <?php endif; ?>
                      </div>
                      <div class="adm-name-secondary"><?= htmlspecialchars($a['email']) ?></div>
                    </div>
                  </div>
                </td>
                <td><span class="adm-pill adm-pill-role"><?= htmlspecialchars($a['role']) ?></span></td>
                <td>
                  <span id="status-pill-<?= $a['id'] ?>" class="adm-pill <?= $a['account_status'] === 'suspended' ? 'adm-pill-suspended' : 'adm-pill-active' ?>">
                    <?= $a['account_status'] === 'suspended' ? 'Suspended' : 'Active' ?>
                  </span>
                </td>
                <td style="color: var(--adm-soft);">
                  <?= $a['last_activity'] ? date('M j, Y g:i A', strtotime($a['last_activity'])) : '—' ?>
                </td>
                <td>
                  <div class="adm-row-actions">
                    <a href="<?= BASE_URL ?>admin-edit?id=<?= $a['id'] ?>" class="adm-icon-btn" title="Edit">
                      <i class="fas fa-pen"></i>
                    </a>
                    <?php if ($a['id'] != $current_admin_id): ?>
                      <button
                        class="adm-icon-btn <?= $a['account_status'] === 'suspended' ? 'success' : 'danger' ?> toggle-status-btn"
                        data-id="<?= $a['id'] ?>"
                        data-current-status="<?= $a['account_status'] ?>"
                        data-name="<?= htmlspecialchars($a['full_name']) ?>"
                        title="<?= $a['account_status'] === 'suspended' ? 'Activate' : 'Suspend' ?>">
                        <i class="fas <?= $a['account_status'] === 'suspended' ? 'fa-user-check' : 'fa-user-slash' ?>"></i>
                      </button>
                      <button
                        class="adm-icon-btn danger delete-admin-btn"
                        data-id="<?= $a['id'] ?>"
                        data-name="<?= htmlspecialchars($a['full_name']) ?>"
                        title="Delete">
                        <i class="fas fa-trash"></i>
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </div>

  <script>
    function showToast(message, isError = false) {
      const toast = document.getElementById('ajaxToast');
      const icon = document.getElementById('ajaxToastIcon');
      const label = document.getElementById('ajaxToastLabel');

      document.getElementById('ajaxToastMsg').textContent = message;
      icon.className = isError
        ? 'fa-solid fa-circle-exclamation mt-0.5 mr-3 text-base'
        : 'fa-solid fa-circle-check mt-0.5 mr-3 text-base';
      icon.style.color = isError ? 'var(--adm-suspend)' : 'var(--adm-online)';
      label.textContent = isError ? 'Error' : 'Success';

      toast.style.display = 'block';
      toast.classList.add('adm-fade');
      setTimeout(() => {
        toast.classList.add('opacity-0', 'transition-opacity', 'duration-300');
        setTimeout(() => {
          toast.style.display = 'none';
          toast.classList.remove('opacity-0', 'transition-opacity', 'duration-300');
        }, 300);
      }, 3000);
    }

    // Reusable confirm modal
    // options.requireText: if set, the Confirm button stays disabled until
    // the user types this exact string into the input.
    function showConfirm(title, message, onConfirm, options = {}) {
      const modal = document.getElementById('confirmModal');
      document.getElementById('confirmTitle').textContent = title;
      document.getElementById('confirmMessage').textContent = message;

      const typeWrap = document.getElementById('confirmTypeWrap');
      const typeInput = document.getElementById('confirmTypeInput');
      const typeTarget = document.getElementById('confirmTypeTarget');

      const okBtnPreview = document.getElementById('confirmOkBtn');
      okBtnPreview.textContent = options.confirmLabel || 'Confirm';

      modal.classList.add('show');

      const okBtn = document.getElementById('confirmOkBtn');
      const cancelBtn = document.getElementById('confirmCancelBtn');

      // Clone buttons to strip old listeners before attaching new ones
      const newOkBtn = okBtn.cloneNode(true);
      okBtn.parentNode.replaceChild(newOkBtn, okBtn);
      const newCancelBtn = cancelBtn.cloneNode(true);
      cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);

      if (options.requireText) {
        typeWrap.style.display = 'block';
        typeTarget.textContent = options.requireText;
        typeInput.value = '';
        newOkBtn.disabled = true;
        newOkBtn.style.opacity = '0.5';
        newOkBtn.style.cursor = 'not-allowed';

        // Fresh input listener each time (typeInput itself isn't cloned, so remove stale ones first)
        const newTypeInput = typeInput.cloneNode(true);
        typeInput.parentNode.replaceChild(newTypeInput, typeInput);

        newTypeInput.addEventListener('input', function () {
          const matches = newTypeInput.value === options.requireText;
          newOkBtn.disabled = !matches;
          newOkBtn.style.opacity = matches ? '1' : '0.5';
          newOkBtn.style.cursor = matches ? 'pointer' : 'not-allowed';
        });

        setTimeout(() => newTypeInput.focus(), 100);
      } else {
        typeWrap.style.display = 'none';
      }

      newOkBtn.addEventListener('click', function () {
        if (options.requireText && newOkBtn.disabled) return;
        modal.classList.remove('show');
        onConfirm();
      });

      newCancelBtn.addEventListener('click', function () {
        modal.classList.remove('show');
      });
    }

    document.querySelectorAll('.toggle-status-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        const id = this.dataset.id;
        const currentStatus = this.dataset.currentStatus;
        const name = this.dataset.name;
        const newStatus = currentStatus === 'suspended' ? 'active' : 'suspended';
        const actionWord = newStatus === 'suspended' ? 'suspend' : 'activate';

        showConfirm(
          `${actionWord.charAt(0).toUpperCase() + actionWord.slice(1)} admin?`,
          `Are you sure you want to ${actionWord} ${name}?`,
          function () { runToggleStatus(id, newStatus, actionWord, name, btn); }
        );
      });
    });

    function runToggleStatus(id, newStatus, actionWord, name, btn) {
        fetch('<?= BASE_URL ?>toggle-admin-status', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `id=${encodeURIComponent(id)}&status=${encodeURIComponent(newStatus)}`
        })
          .then(res => res.json())
          .then(data => {
            if (data.success) {
              // Update pill
              const pill = document.getElementById(`status-pill-${id}`);
              pill.textContent = newStatus === 'suspended' ? 'Suspended' : 'Active';
              pill.className = `adm-pill ${newStatus === 'suspended' ? 'adm-pill-suspended' : 'adm-pill-active'}`;

              // Update button
              btn.dataset.currentStatus = newStatus;
              btn.title = newStatus === 'suspended' ? 'Activate' : 'Suspend';
              btn.classList.toggle('danger', newStatus !== 'suspended');
              btn.classList.toggle('success', newStatus === 'suspended');
              btn.querySelector('i').className = `fas ${newStatus === 'suspended' ? 'fa-user-check' : 'fa-user-slash'}`;

              showToast(`${name} has been ${actionWord}d.`);
            } else {
              showToast(data.error || 'Something went wrong.', true);
            }
          })
          .catch(() => showToast('Network error. Please try again.', true));
    }

    // ── Real-time online/offline presence ──
    function pollOnlineStatus() {
      fetch('<?= BASE_URL ?>get-admin-status', { credentials: 'same-origin' })
        .then(res => res.json())
        .then(data => {
          if (!data.success) return;
          Object.entries(data.statuses).forEach(([id, isOnline]) => {
            const dot = document.getElementById(`online-dot-${id}`);
            if (dot) dot.classList.toggle('is-online', isOnline);
          });
        })
        .catch(() => {});
    }

    pollOnlineStatus();
    setInterval(pollOnlineStatus, 15000);

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) pollOnlineStatus();
    });

    // ── Delete admin ──
    document.querySelectorAll('.delete-admin-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        const id = this.dataset.id;
        const name = this.dataset.name;

        showConfirm(
          'Delete admin?',
          `This permanently deletes ${name}'s account. Any inquiries assigned to them will be unassigned. This cannot be undone.`,
          function () { runDeleteAdmin(id, name, btn); },
          { confirmLabel: 'Delete', requireText: name }
        );
      });
    });

    function runDeleteAdmin(id, name, btn) {
      fetch('<?= BASE_URL ?>delete-admin', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${encodeURIComponent(id)}`
      })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            const row = document.getElementById(`row-${id}`);
            if (row) row.remove();
            showToast(`${name} has been deleted.`);
          } else {
            showToast(data.error || 'Failed to delete admin.', true);
          }
        })
        .catch(() => showToast('Network error. Please try again.', true));
    }
  </script>
</body>

</html>