<?php
//dashboard.php
include $includes['mainbody'];

require_role(['human_resource']);

// ── Admin/Employee Overview Stats (superadmin accounts excluded entirely) ──
$total_admins = $conn->query("SELECT COUNT(*) as count FROM account WHERE role != 'super_admin'")->fetch_assoc()['count'] ?? 0;

$online_admins = $conn->query("SELECT COUNT(*) as count FROM account WHERE role != 'super_admin' AND " . getOnlineSqlCondition())->fetch_assoc()['count'] ?? 0;

$head_admins = $conn->query("SELECT COUNT(*) as count FROM account WHERE role != 'super_admin' AND is_head = 1")->fetch_assoc()['count'] ?? 0;

$suspended_admins = $conn->query("SELECT COUNT(*) as count FROM account WHERE role != 'super_admin' AND account_status = 'suspended'")->fetch_assoc()['count'] ?? 0;

// Breakdown by role (superadmin excluded)
$role_breakdown = [];
$role_result = $conn->query("SELECT role, COUNT(*) as count FROM account WHERE role != 'super_admin' GROUP BY role ORDER BY count DESC");
while ($row = $role_result->fetch_assoc()) {
  $role_breakdown[] = $row;
}

// Recently added employees (last 5, superadmin excluded)
$recent_admins = [];
$recent_result = $conn->query("SELECT id, full_name, role, is_online, last_activity, created_at, profile_picture, google_picture, avatar_source FROM account WHERE role != 'super_admin' ORDER BY created_at DESC LIMIT 5");
while ($row = $recent_result->fetch_assoc()) {
  $recent_admins[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>HR Dashboard - RealLiving</title>
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

    .adm-card {
      display: block;
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      padding: 1.5rem;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }

    .adm-card:hover,
    .adm-card:focus-visible {
      border-color: var(--adm-ink);
      box-shadow: 0 10px 26px -16px rgba(11, 11, 11, 0.25);
      transform: translateY(-2px);
      outline: none;
    }

    .adm-icon {
      width: 44px;
      height: 44px;
      border-radius: 9px;
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      color: var(--adm-ink);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 17px;
      margin-bottom: 1rem;
    }

    .adm-card-title {
      font-size: 15px;
      font-weight: 600;
      color: var(--adm-ink);
      margin-bottom: .35rem;
    }

    .adm-card-desc {
      font-size: 13px;
      line-height: 1.5;
      color: var(--adm-soft);
      margin-bottom: 1.1rem;
    }

    .adm-card-link {
      font-size: 12.5px;
      font-weight: 600;
      color: var(--adm-ink);
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .adm-card-link i {
      font-size: 10px;
      transition: transform .2s ease;
    }

    .adm-card:hover .adm-card-link i {
      transform: translateX(3px);
    }

    /* ── Metric cards ── */
    .adm-metric {
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      padding: 1.35rem 1.4rem;
    }

    .adm-metric-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: .9rem;
    }

    .adm-metric-icon {
      font-size: 15px;
      color: var(--adm-soft);
    }

    .adm-metric-value {
      font-size: 26px;
      font-weight: 700;
      color: var(--adm-ink);
      line-height: 1;
      margin-bottom: .4rem;
    }

    .adm-metric-label {
      font-size: 12.5px;
      color: var(--adm-soft);
    }

    /* ── Role breakdown bars ── */
    .adm-role-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: .65rem 0;
      border-bottom: 1px solid var(--adm-line);
      font-size: 13px;
    }

    .adm-role-row:last-child {
      border-bottom: none;
    }

    .adm-role-name {
      font-weight: 600;
      color: var(--adm-ink);
      text-transform: capitalize;
    }

    .adm-role-count {
      font-weight: 700;
      color: var(--adm-ink);
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      border-radius: 999px;
      min-width: 26px;
      height: 26px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
    }

    /* ── Recent admins list ── */
    .adm-list-row {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: .75rem 0;
      border-bottom: 1px solid var(--adm-line);
    }

    .adm-list-row:last-child {
      border-bottom: none;
    }

    .adm-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12.5px;
      font-weight: 700;
      color: var(--adm-ink);
      flex-shrink: 0;
    }

    .adm-list-name {
      font-size: 13.5px;
      font-weight: 600;
      color: var(--adm-ink);
    }

    .adm-list-role {
      font-size: 12px;
      color: var(--adm-soft);
      text-transform: capitalize;
    }

    .adm-online-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--adm-muted);
      flex-shrink: 0;
    }

    .adm-online-dot.is-online {
      background: var(--adm-online);
    }

    .adm-toast {
      background: #fff;
      border-left: 3px solid var(--adm-ink);
      box-shadow: 0 12px 32px -14px rgba(11, 11, 11, 0.3);
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

  <!-- Main Content -->
  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">

    <!-- Dashboard Header -->
    <div class="mb-10 adm-fade">
      <div class="adm-eyebrow mb-2">Human Resource</div>
      <h1 class="adm-title">HR Dashboard</h1>
      <p class="adm-subtitle mt-1">Overview of employee and admin accounts.</p>
    </div>

    <!-- Overview -->
    <div class="mb-10 adm-fade">
      <div class="adm-section-label mb-4">Overview</div>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">

        <div class="adm-metric">
          <div class="adm-metric-top">
            <i class="fas fa-users adm-metric-icon"></i>
          </div>
          <div class="adm-metric-value"><?= $total_admins ?></div>
          <div class="adm-metric-label">Total Employees</div>
        </div>

        <div class="adm-metric">
          <div class="adm-metric-top">
            <i class="fas fa-circle-dot adm-metric-icon" style="color: var(--adm-online);"></i>
          </div>
          <div class="adm-metric-value" id="online-count-value"><?= $online_admins ?></div>
          <div class="adm-metric-label">Currently Online</div>
        </div>

        <div class="adm-metric">
          <div class="adm-metric-top">
            <i class="fas fa-crown adm-metric-icon"></i>
          </div>
          <div class="adm-metric-value"><?= $head_admins ?></div>
          <div class="adm-metric-label">Head Admins</div>
        </div>

        <div class="adm-metric">
          <div class="adm-metric-top">
            <i class="fas fa-user-slash adm-metric-icon" style="color: var(--adm-suspend);"></i>
          </div>
          <div class="adm-metric-value"><?= $suspended_admins ?></div>
          <div class="adm-metric-label">Suspended Accounts</div>
        </div>

      </div>
    </div>

    <!-- Management Cards -->
    <div class="mb-10 adm-fade">
      <div class="adm-section-label mb-4">Management</div>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

        <a href="<?= BASE_URL ?>hr-admin-management" class="adm-card">
          <div class="adm-icon"><i class="fas fa-user-shield"></i></div>
          <h3 class="adm-card-title">Employee Accounts</h3>
          <p class="adm-card-desc">View, edit, suspend, or remove employee accounts.</p>
          <span class="adm-card-link">Manage Employees <i class="fas fa-arrow-right"></i></span>
        </a>

      </div>
    </div>

    <!-- Role Breakdown + Recently Added -->
    <div class="adm-fade">
      <div class="adm-section-label mb-4">Team Breakdown</div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        <!-- Role breakdown -->
        <div class="adm-card" style="padding: 1.5rem;">
          <h3 class="adm-card-title mb-3">Employees by Role</h3>
          <?php if (empty($role_breakdown)): ?>
            <p class="adm-card-desc">No employee accounts found.</p>
          <?php else: ?>
            <?php foreach ($role_breakdown as $r): ?>
              <div class="adm-role-row">
                <span class="adm-role-name"><?= htmlspecialchars($r['role']) ?></span>
                <span class="adm-role-count"><?= $r['count'] ?></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Recently added employees -->
        <div class="adm-card" style="padding: 1.5rem;">
          <h3 class="adm-card-title mb-3">Recently Added</h3>
          <?php if (empty($recent_admins)): ?>
            <p class="adm-card-desc">No employee accounts found.</p>
          <?php else: ?>
            <?php foreach ($recent_admins as $a): ?>
              <div class="adm-list-row">
                <?= renderAvatarHtml($a) ?>
                <div class="flex-1">
                  <div class="adm-list-name"><?= htmlspecialchars($a['full_name']) ?></div>
                  <div class="adm-list-role"><?= htmlspecialchars($a['role']) ?></div>
                </div>
                <span id="online-dot-<?= $a['id'] ?>" class="adm-online-dot <?= isAdminOnline($a['is_online'], $a['last_activity']) ? 'is-online' : '' ?>" title="<?= isAdminOnline($a['is_online'], $a['last_activity']) ? 'Online' : 'Offline' ?>"></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>
    </div>

  </div>

  <script>
    function pollOnlineStatus() {
      fetch('<?= BASE_URL ?>get-admin-status', { credentials: 'same-origin' })
        .then(res => res.json())
        .then(data => {
          if (!data.success) return;

          const countEl = document.getElementById('online-count-value');
          if (countEl) countEl.textContent = data.online_count;

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
  </script>

</body>

</html>