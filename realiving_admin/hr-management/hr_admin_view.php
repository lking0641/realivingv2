<?php
//hr-admin-view.php
include $includes['mainbody'];

require_role(['human_resource']);

$view_id = (int)($_GET['id'] ?? 0);
if (!$view_id) {
  $_SESSION['noti'] = "No employee selected.";
  header("Location: " . BASE_URL . "hr-admin-management");
  exit;
}

$stmt = $conn->prepare("SELECT id, full_name, admin_name, email, role, is_head, account_status, is_online, last_activity, created_at, profile_picture, google_picture, avatar_source FROM account WHERE id = ?");
$stmt->bind_param('i', $view_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

if (!$admin || $admin['role'] === 'super_admin') {
  $_SESSION['noti'] = "You are not permitted to view this account.";
  header("Location: " . BASE_URL . "hr-admin-management");
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>View Employee - RealLiving</title>
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

    body { font-family: 'Inter', sans-serif; background: var(--adm-bg); color: var(--adm-ink); }

    .adm-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; color: var(--adm-soft); }
    .adm-title { font-size: 28px; font-weight: 700; letter-spacing: -0.01em; color: var(--adm-ink); }
    .adm-subtitle { font-size: 13.5px; color: var(--adm-soft); }

    .adm-btn {
      font-size: 12.5px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;
      padding: .65rem 1.1rem; border-radius: 8px; border: 1px solid var(--adm-line);
      background: #fff; color: var(--adm-ink); cursor: pointer; transition: border-color .15s ease, background .15s ease;
      text-decoration: none;
    }
    .adm-btn:hover { border-color: var(--adm-ink); }
    .adm-btn-dark { background: var(--adm-ink); color: #fff; border-color: var(--adm-ink); }
    .adm-btn-dark:hover { opacity: .9; }

    .adm-card { background: var(--adm-surface); border: 1px solid var(--adm-line); border-radius: 10px; padding: 1.75rem; }

    .adm-section-label {
      font-size: 12px; font-weight: 600; color: var(--adm-ink);
      display: flex; align-items: center; gap: 10px; margin-bottom: 1.1rem;
    }
    .adm-section-label::after { content: ""; flex: 1; height: 1px; background: var(--adm-line); }

    .adm-avatar-xl {
      width: 76px; height: 76px; border-radius: 50%; background: var(--adm-bg);
      border: 1px solid var(--adm-line); display: flex; align-items: center; justify-content: center;
      font-size: 26px; font-weight: 700; color: var(--adm-ink); flex-shrink: 0; overflow: hidden;
    }
    .adm-avatar-xl img { width: 100%; height: 100%; object-fit: cover; }

    .adm-pill {
      display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600;
      padding: .3rem .6rem; border-radius: 999px; text-transform: capitalize;
    }
    .adm-pill-role { background: var(--adm-bg); border: 1px solid var(--adm-line); color: var(--adm-ink); }
    .adm-pill-active { background: #ECFDF5; color: var(--adm-online); border: 1px solid #BBF7D0; }
    .adm-pill-suspended { background: #FEF2F2; color: var(--adm-suspend); border: 1px solid #FECACA; }

    .adm-online-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--adm-muted); display: inline-block; margin-right: 5px; }
    .adm-online-dot.is-online { background: var(--adm-online); }

    /* Info grid */
    .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.25rem 2rem; }
    @media (max-width: 640px) { .info-grid { grid-template-columns: 1fr; } }
    .info-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: var(--adm-muted); margin-bottom: .3rem; }
    .info-value { font-size: 14px; font-weight: 500; color: var(--adm-ink); }

    .doc-group-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--adm-soft); margin: 1.25rem 0 .6rem 0; }
    .doc-group-title:first-child { margin-top: 0; }
    .doc-row {
      display: flex; align-items: center; justify-content: space-between; padding: .7rem .85rem;
      border: 1px solid var(--adm-line); border-radius: 8px; margin-bottom: .5rem; transition: border-color .15s ease;
    }
    .doc-row:hover { border-color: var(--adm-ink); }
    .doc-icon-wrap { width: 36px; height: 36px; border-radius: 8px; background: var(--adm-bg); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .doc-icon-wrap.pdf i { color: #DC2626; }
    .doc-icon-wrap.image i { color: #2563EB; }
    .doc-meta { font-size: 11px; color: var(--adm-muted); }

    .adm-icon-btn {
      width: 30px; height: 30px; border-radius: 7px; border: 1px solid var(--adm-line); background: #fff;
      color: var(--adm-soft); display: inline-flex; align-items: center; justify-content: center; cursor: pointer;
      font-size: 12.5px; transition: border-color .15s ease, color .15s ease; text-decoration: none;
    }
    .adm-icon-btn:hover { border-color: var(--adm-ink); color: var(--adm-ink); }

    .adm-toast { background: #fff; border-left: 3px solid var(--adm-ink); box-shadow: 0 12px 32px -14px rgba(11, 11, 11, 0.3); }

    @keyframes adm-fade { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    .adm-fade { animation: adm-fade .4s ease both; }
    @media (prefers-reduced-motion: reduce) { .adm-fade { animation: none; } }
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
        <h1 class="adm-title">Employee Profile</h1>
        <p class="adm-subtitle mt-1">Full account details and documents.</p>
      </div>
      <div class="flex gap-2">
        <a href="<?= BASE_URL ?>hr-admin-management" class="adm-btn"><i class="fas fa-arrow-left"></i> Back to List</a>
        <a href="<?= BASE_URL ?>hr-admin-edit?id=<?= $admin['id'] ?>" class="adm-btn adm-btn-dark"><i class="fas fa-pen"></i> Edit</a>
      </div>
    </div>

    <!-- Identity Card -->
    <div class="adm-card adm-fade mb-6">
      <div class="flex items-center gap-4 flex-wrap">
        <?= renderAvatarHtml($admin, 'adm-avatar-xl') ?>
        <div class="flex-1" style="min-width:200px;">
          <div style="font-size:19px; font-weight:700;">
            <?= htmlspecialchars($admin['full_name'] ?: '(No name set)') ?>
            <?php if (!empty($admin['is_head'])): ?>
              <i class="fas fa-crown ml-1" style="font-size:14px; color:#B45309;" title="Head Admin"></i>
            <?php endif; ?>
          </div>
          <div style="font-size:13px; color: var(--adm-soft); margin-top:.2rem;">
            <?= htmlspecialchars($admin['email']) ?>
          </div>
          <div class="flex items-center gap-2 mt-2 flex-wrap">
            <span class="adm-pill adm-pill-role"><?= htmlspecialchars($admin['role']) ?></span>
            <span class="adm-pill <?= $admin['account_status'] === 'suspended' ? 'adm-pill-suspended' : 'adm-pill-active' ?>">
              <?= $admin['account_status'] === 'suspended' ? 'Suspended' : 'Active' ?>
            </span>
            <span style="font-size:11.5px; color: var(--adm-soft);">
              <span class="adm-online-dot <?= isAdminOnline($admin['is_online'], $admin['last_activity']) ? 'is-online' : '' ?>"></span>
              <?= isAdminOnline($admin['is_online'], $admin['last_activity']) ? 'Online now' : 'Offline' ?>
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Account Details -->
    <div class="adm-card adm-fade mb-6">
      <div class="adm-section-label">Account Details</div>
      <div class="info-grid">
        <div>
          <div class="info-label">Display / Username</div>
          <div class="info-value"><?= htmlspecialchars($admin['admin_name'] ?: '—') ?></div>
        </div>
        <div>
          <div class="info-label">Role</div>
          <div class="info-value" style="text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $admin['role'])) ?></div>
        </div>
        <div>
          <div class="info-label">Head Admin</div>
          <div class="info-value"><?= !empty($admin['is_head']) ? 'Yes' : 'No' ?></div>
        </div>
        <div>
          <div class="info-label">Account Status</div>
          <div class="info-value"><?= $admin['account_status'] === 'suspended' ? 'Suspended' : 'Active' ?></div>
        </div>
        <div>
          <div class="info-label">Last Active</div>
          <div class="info-value"><?= $admin['last_activity'] ? date('M j, Y g:i A', strtotime($admin['last_activity'])) : '—' ?></div>
        </div>
        <div>
          <div class="info-label">Account Created</div>
          <div class="info-value"><?= $admin['created_at'] ? date('M j, Y', strtotime($admin['created_at'])) : '—' ?></div>
        </div>
      </div>
    </div>

    <!-- Documents (read-only) -->
    <div class="adm-card adm-fade">
      <div class="adm-section-label">Employee Documents</div>
      <div id="doc-list">
        <p class="adm-card-desc" style="margin:0; font-size:13px; color: var(--adm-soft);">Loading documents...</p>
      </div>
    </div>

  </div>

  <script>
    const EMPLOYEE_ID = <?= (int) $admin['id'] ?>;

    function loadDocuments() {
      fetch('<?= BASE_URL ?>get-employee-documents?account_id=' + EMPLOYEE_ID)
        .then(r => r.json())
        .then(data => {
          const list = document.getElementById('doc-list');
          if (!data.success || !data.documents.length) {
            list.innerHTML = '<p style="margin:0; font-size:13px; color: var(--adm-soft);">No documents uploaded yet. <a href="<?= BASE_URL ?>hr-admin-edit?id=' + EMPLOYEE_ID + '" style="color:var(--adm-ink); font-weight:600;">Add one from the edit page →</a></p>';
            return;
          }
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
          document.getElementById('doc-list').innerHTML = '<p style="margin:0; font-size:13px; color: var(--adm-soft);">Failed to load documents.</p>';
        });
    }

    function renderDocRow(doc) {
      const icon = doc.file_type === 'pdf' ? 'fa-file-pdf' : 'fa-file-image';
      const sizeText = doc.file_size ? ` · ${doc.file_size}` : '';
      return `
        <div class="doc-row">
          <div class="flex items-center gap-3" style="min-width:0;">
            <div class="doc-icon-wrap ${doc.file_type}"><i class="fas ${icon}"></i></div>
            <div style="min-width:0;">
              <div style="font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${doc.label}</div>
              <div class="doc-meta">By ${doc.uploader_name} · ${doc.uploaded_at}${sizeText}</div>
            </div>
          </div>
          <a href="${doc.file_url}" target="_blank" class="adm-icon-btn" title="View"><i class="fas fa-eye"></i></a>
        </div>`;
    }

    loadDocuments();
  </script>

</body>

</html>