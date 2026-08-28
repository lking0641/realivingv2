<?php
//admin_mainpage.php
include $includes['mainbody'];

// Only the super admin can access this page
require_role(['super_admin']);

// ── Admin/Employee Overview Stats ──
$total_admins = $conn->query("SELECT COUNT(*) as count FROM account")->fetch_assoc()['count'] ?? 0;

$online_admins = $conn->query("SELECT COUNT(*) as count FROM account WHERE " . getOnlineSqlCondition())->fetch_assoc()['count'] ?? 0;

$head_admins = $conn->query("SELECT COUNT(*) as count FROM account WHERE is_head = 1")->fetch_assoc()['count'] ?? 0;

// Breakdown by role
$role_breakdown = [];
$role_result = $conn->query("SELECT role, COUNT(*) as count FROM account GROUP BY role ORDER BY count DESC");
while ($row = $role_result->fetch_assoc()) {
  $role_breakdown[] = $row;
}

// Recently added admins (last 5)
$recent_admins = [];
$recent_result = $conn->query("SELECT id, full_name, role, is_online, last_activity, created_at, profile_picture, google_picture, avatar_source FROM account ORDER BY created_at DESC LIMIT 5");
while ($row = $recent_result->fetch_assoc()) {
  $recent_admins[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Super Admin Dashboard - RealLiving</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>logo/favicon.ico">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
    crossorigin="anonymous" referrerpolicy="no-referrer" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    :root {
      --adm-bg: #F5F5F5;
      --adm-surface: #FFFFFF;
      --adm-ink: #0B0B0B;
      --adm-soft: #6B6B6B;
      --adm-muted: #9A9A9A;
      --adm-line: #E2E2E2;
      --adm-online: #16A34A;
    }

    html, body {
      width: 100%;
      height: 100%;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--adm-bg);
      color: var(--adm-ink);
      overflow-x: hidden;
    }

    /* ── Typography ── */
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
      margin: 0;
    }

    .adm-subtitle {
      font-size: 13.5px;
      color: var(--adm-soft);
      margin: 0;
    }

    .adm-section-label {
      font-size: 12px;
      font-weight: 600;
      color: var(--adm-ink);
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 0 0 16px 0;
    }

    .adm-section-label::after {
      content: "";
      flex: 1;
      height: 1px;
      background: var(--adm-line);
    }

    /* ── Card Base ── */
    .adm-card {
      display: block;
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      padding: 1.5rem;
      transition: all 0.2s ease;
      text-decoration: none;
      color: inherit;
    }

    .adm-card:hover,
    .adm-card:focus-visible {
      border-color: var(--adm-ink);
      box-shadow: 0 10px 26px -16px rgba(11, 11, 11, 0.25);
      transform: translateY(-2px);
      outline: none;
    }

    /* ── Icon ── */
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
      flex-shrink: 0;
    }

    /* ── Card Content ── */
    .adm-card-title {
      font-size: 15px;
      font-weight: 600;
      color: var(--adm-ink);
      margin-bottom: 0.5rem;
      margin-top: 0;
    }

    .adm-card-desc {
      font-size: 13px;
      line-height: 1.5;
      color: var(--adm-soft);
      margin-bottom: 1.1rem;
      margin-top: 0;
    }

    .adm-card-link {
      font-size: 12.5px;
      font-weight: 600;
      color: var(--adm-ink);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: color 0.2s ease;
    }

    .adm-card-link i {
      font-size: 10px;
      transition: transform 0.2s ease;
    }

    .adm-card:hover .adm-card-link i {
      transform: translateX(3px);
    }

    /* ── Metric Card ── */
    .adm-metric {
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      padding: 1.5rem;
      transition: all 0.2s ease;
      display: flex;
      flex-direction: column;
      height: 100%;
    }

    .adm-metric:hover {
      border-color: var(--adm-ink);
      box-shadow: 0 4px 12px rgba(11, 11, 11, 0.08);
    }

    .adm-metric-icon {
      font-size: 18px;
      color: var(--adm-soft);
      margin-bottom: 1rem;
    }

    .adm-metric-value {
      font-size: 28px;
      font-weight: 700;
      color: var(--adm-ink);
      line-height: 1;
      margin-bottom: 0.5rem;
    }

    .adm-metric-label {
      font-size: 12.5px;
      color: var(--adm-soft);
      margin: 0;
    }

    /* ── Role Breakdown ── */
    .adm-role-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0.75rem 0;
      border-bottom: 1px solid var(--adm-line);
      gap: 1rem;
    }

    .adm-role-row:last-child {
      border-bottom: none;
    }

    .adm-role-name {
      font-weight: 600;
      color: var(--adm-ink);
      text-transform: capitalize;
      flex: 1;
      font-size: 13px;
    }

    .adm-role-count {
      font-weight: 700;
      color: var(--adm-ink);
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      border-radius: 999px;
      min-width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      flex-shrink: 0;
    }

    /* ── Admin List ── */
    .adm-list-row {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 0.75rem 0;
      border-bottom: 1px solid var(--adm-line);
    }

    .adm-list-row:last-child {
      border-bottom: none;
    }

    .adm-avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      font-weight: 700;
      color: var(--adm-ink);
      flex-shrink: 0;
    }

    .adm-list-content {
      flex: 1;
      min-width: 0;
    }

    .adm-list-name {
      font-size: 13.5px;
      font-weight: 600;
      color: var(--adm-ink);
      margin: 0 0 2px 0;
    }

    .adm-list-role {
      font-size: 12px;
      color: var(--adm-soft);
      text-transform: capitalize;
      margin: 0;
    }

    .adm-online-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--adm-muted);
      flex-shrink: 0;
      transition: background-color 0.2s ease;
    }

    .adm-online-dot.is-online {
      background: var(--adm-online);
      box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.15);
    }

    /* ── Notification Toast ── */
    .adm-toast {
      background: var(--adm-surface);
      border-left: 4px solid var(--adm-ink);
      border-radius: 8px;
      box-shadow: 0 12px 32px rgba(11, 11, 11, 0.15);
      padding: 1rem 1.25rem;
    }

    .adm-toast-content {
      display: flex;
      align-items: flex-start;
      gap: 12px;
    }

    .adm-toast-icon {
      font-size: 16px;
      color: var(--adm-ink);
      margin-top: 2px;
      flex-shrink: 0;
    }

    .adm-toast-text {
      flex: 1;
    }

    .adm-toast-label {
      font-size: 10px;
      font-weight: 600;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      color: var(--adm-soft);
      margin: 0 0 4px 0;
    }

    .adm-toast-message {
      font-size: 13px;
      color: var(--adm-ink);
      margin: 0;
      line-height: 1.4;
    }

    .adm-toast-close {
      background: none;
      border: none;
      color: var(--adm-soft);
      cursor: pointer;
      font-size: 14px;
      padding: 0;
      flex-shrink: 0;
      transition: color 0.2s ease;
    }

    .adm-toast-close:hover {
      color: var(--adm-ink);
    }

    /* ── Animations ── */
    @keyframes adm-fade-in {
      from {
        opacity: 0;
        transform: translateY(8px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes adm-slide-down {
      from {
        opacity: 0;
        transform: translateY(-16px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .adm-fade {
      animation: adm-fade-in 0.4s ease both;
    }

    .adm-toast {
      animation: adm-slide-down 0.3s ease both;
    }

    @media (prefers-reduced-motion: reduce) {
      .adm-fade,
      .adm-toast {
        animation: none;
      }
    }

    /* ── Layout ── */
    .adm-container {
      width: 100%;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      background: var(--adm-bg);
    }

    .adm-main {
      flex: 1;
      padding: 2rem 1rem;
    }

    .adm-content {
      max-width: 1280px;
      margin: 0 auto;
      width: 100%;
    }

    /* ── Header Section ── */
    .adm-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      margin-bottom: 2rem;
      gap: 1.5rem;
      flex-wrap: wrap;
    }

    .adm-header-left {
      flex: 1;
      min-width: 250px;
    }

    .adm-header-eyebrow {
      margin-bottom: 0.5rem;
    }

    .adm-header-right {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .adm-btn-primary {
      background: var(--adm-ink);
      color: #fff;
      padding: 0.65rem 1.2rem;
      border-radius: 8px;
      border: none;
      font-size: 12.5px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s ease;
      text-decoration: none;
      white-space: nowrap;
    }

    .adm-btn-primary:hover {
      background: #2a2a2a;
      box-shadow: 0 4px 12px rgba(11, 11, 11, 0.15);
    }

    /* ── Section ── */
    .adm-section {
      margin-bottom: 2.5rem;
      animation: adm-fade-in 0.4s ease both;
    }

    .adm-section:nth-child(1) {
      animation-delay: 0.05s;
    }

    .adm-section:nth-child(2) {
      animation-delay: 0.1s;
    }

    .adm-section:nth-child(3) {
      animation-delay: 0.15s;
    }

    /* ── Grid Layouts ── */
    .adm-grid-4 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 1rem;
    }

    .adm-grid-3 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 1rem;
    }

    .adm-grid-2 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
      gap: 1rem;
    }

    /* ── Responsive ── */
    @media (max-width: 768px) {
      .adm-main {
        padding: 1.5rem 1rem;
      }

      .adm-header {
        flex-direction: column;
        align-items: stretch;
      }

      .adm-header-right {
        justify-content: flex-start;
      }

      .adm-title {
        font-size: 24px;
      }

      .adm-grid-4,
      .adm-grid-3,
      .adm-grid-2 {
        grid-template-columns: 1fr;
      }

      .adm-metric-value {
        font-size: 24px;
      }

      .adm-card {
        padding: 1.25rem;
      }
    }

    @media (max-width: 480px) {
      .adm-main {
        padding: 1rem;
      }

      .adm-title {
        font-size: 20px;
      }

      .adm-card-title {
        font-size: 14px;
      }

      .adm-card-desc {
        font-size: 12px;
      }
    }

    /* ── Utilities ── */
    .adm-space-top {
      margin-top: 1rem;
    }

    .adm-space-bottom {
      margin-bottom: 1rem;
    }

    .adm-hidden {
      display: none;
    }
  </style>
</head>

<body>
  <div class="adm-container">

    <!-- Notification Toast -->
    <?php if (isset($_SESSION['noti'])): ?>
      <div id="notifBox" class="adm-toast fixed top-6 right-4 w-full max-w-sm z-50" style="max-width: 400px;">
        <div class="adm-toast-content">
          <i class="fa-solid fa-circle-info adm-toast-icon"></i>
          <div class="adm-toast-text">
            <p class="adm-toast-label">Notification</p>
            <p class="adm-toast-message"><?= htmlspecialchars($_SESSION['noti']); ?></p>
          </div>
          <button class="adm-toast-close" onclick="this.closest('#notifBox').remove()">
            <i class="fas fa-times"></i>
          </button>
        </div>
      </div>
      <script>
        setTimeout(function () {
          const notif = document.getElementById("notifBox");
          if (notif) {
            notif.style.animation = 'adm-slide-down 0.3s ease reverse forwards';
            setTimeout(() => notif.remove(), 300);
          }
        }, 4000);
      </script>
      <?php unset($_SESSION['noti']); ?>
    <?php endif; ?>

    <!-- Main Content -->
    <main class="adm-main">
      <div class="adm-content">

        <!-- Header Section -->
        <header class="adm-header adm-fade">
          <div class="adm-header-left">
            <div class="adm-eyebrow adm-header-eyebrow">Super Admin</div>
            <h1 class="adm-title">Admin Dashboard</h1>
            <p class="adm-subtitle adm-space-top">Oversee admin and employee accounts.</p>
          </div>
          <div class="adm-header-right">
            <a href="<?= BASE_URL ?>registration" class="adm-btn-primary">
              <i class="fas fa-plus"></i> Add Admin
            </a>
          </div>
        </header>

        <!-- Overview Metrics Section -->
        <section class="adm-section">
          <div class="adm-section-label">Overview</div>
          <div class="adm-grid-4">
            <div class="adm-metric">
              <i class="fas fa-users adm-metric-icon"></i>
              <div class="adm-metric-value"><?= $total_admins ?></div>
              <p class="adm-metric-label">Total Admin Accounts</p>
            </div>

            <div class="adm-metric">
              <i class="fas fa-circle-dot adm-metric-icon" style="color: var(--adm-online);"></i>
              <div class="adm-metric-value" id="online-count-value"><?= $online_admins ?></div>
              <p class="adm-metric-label">Currently Online</p>
            </div>

            <div class="adm-metric">
              <i class="fas fa-crown adm-metric-icon"></i>
              <div class="adm-metric-value"><?= $head_admins ?></div>
              <p class="adm-metric-label">Head Admins</p>
            </div>

            <div class="adm-metric">
              <i class="fas fa-shield-halved adm-metric-icon"></i>
              <div class="adm-metric-value" style="font-size: 18px;">Super Admin</div>
              <p class="adm-metric-label">Signed in as <?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></p>
            </div>
          </div>
        </section>

        <!-- Management Cards Section -->
        <section class="adm-section">
          <div class="adm-section-label">Management</div>
          <div class="adm-grid-3">
            <a href="<?= BASE_URL ?>admin-management" class="adm-card">
              <div class="adm-icon"><i class="fas fa-user-shield"></i></div>
              <h3 class="adm-card-title">All Admins</h3>
              <p class="adm-card-desc">View, edit, suspend, or remove admin and employee accounts.</p>
              <span class="adm-card-link">Manage Admins <i class="fas fa-arrow-right"></i></span>
            </a>

            <a href="<?= BASE_URL ?>admin-permissions" class="adm-card">
              <div class="adm-icon"><i class="fas fa-user-tag"></i></div>
              <h3 class="adm-card-title">Roles &amp; Permissions</h3>
              <p class="adm-card-desc">Assign roles and control what each admin type can access.</p>
              <span class="adm-card-link">Manage Roles <i class="fas fa-arrow-right"></i></span>
            </a>

            <a href="<?= BASE_URL ?>activity-logs" class="adm-card">
              <div class="adm-icon"><i class="fas fa-clock-rotate-left"></i></div>
              <h3 class="adm-card-title">Activity Logs</h3>
              <p class="adm-card-desc">Review login history and actions taken by each admin.</p>
              <span class="adm-card-link">View Logs <i class="fas fa-arrow-right"></i></span>
            </a>

            <a href="<?= BASE_URL ?>status-control" class="adm-card">
              <div class="adm-icon"><i class="fas fa-diagram-project"></i></div>
              <h3 class="adm-card-title">Client Trackers</h3>
              <p class="adm-card-desc">View and control every client's project tracker stages and statuses.</p>
              <span class="adm-card-link">Manage Trackers <i class="fas fa-arrow-right"></i></span>
            </a>
          </div>
        </section>

        <!-- Team Breakdown Section -->
        <section class="adm-section">
          <div class="adm-section-label">Team Breakdown</div>
          <div class="adm-grid-2">
            <!-- Admins by Role Card -->
            <div class="adm-card">
              <h3 class="adm-card-title">Admins by Role</h3>
              <?php if (empty($role_breakdown)): ?>
                <p class="adm-card-desc adm-space-top">No admin accounts found.</p>
              <?php else: ?>
                <div style="margin-top: 1rem;">
                  <?php foreach ($role_breakdown as $r): ?>
                    <div class="adm-role-row">
                      <span class="adm-role-name"><?= htmlspecialchars($r['role']) ?></span>
                      <span class="adm-role-count"><?= $r['count'] ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Recently Added Admins Card -->
            <div class="adm-card">
              <h3 class="adm-card-title">Recently Added</h3>
              <?php if (empty($recent_admins)): ?>
                <p class="adm-card-desc adm-space-top">No admin accounts found.</p>
              <?php else: ?>
                <div style="margin-top: 1rem;">
                  <?php foreach ($recent_admins as $a): ?>
                    <div class="adm-list-row">
                      <div class="adm-avatar"><?= renderAvatarHtml($a) ?></div>
                      <div class="adm-list-content">
                        <p class="adm-list-name"><?= htmlspecialchars($a['full_name']) ?></p>
                        <p class="adm-list-role"><?= htmlspecialchars($a['role']) ?></p>
                      </div>
                      <span 
                        id="online-dot-<?= $a['id'] ?>" 
                        class="adm-online-dot <?= isAdminOnline($a['is_online'], $a['last_activity']) ? 'is-online' : '' ?>" 
                        title="<?= isAdminOnline($a['is_online'], $a['last_activity']) ? 'Online' : 'Offline' ?>"
                      ></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </section>

      </div>
    </main>

  </div>

  <!-- Online Status Polling Script -->
  <script>
    function pollOnlineStatus() {
      fetch('<?= BASE_URL ?>get-admin-status', { 
        credentials: 'same-origin', 
        cache: 'no-store' 
      })
        .then(res => {
          if (!res.ok) throw new Error('HTTP ' + res.status);
          return res.json();
        })
        .then(data => {
          if (!data.success) {
            console.warn('get-admin-status failed:', data.error);
            return;
          }

          const countEl = document.getElementById('online-count-value');
          if (countEl) {
            countEl.textContent = data.online_count;
          }

          Object.entries(data.statuses || {}).forEach(([id, isOnline]) => {
            const dot = document.getElementById(`online-dot-${id}`);
            if (dot) {
              dot.classList.toggle('is-online', isOnline);
              dot.title = isOnline ? 'Online' : 'Offline';
            }
          });
        })
        .catch(err => console.error('pollOnlineStatus error:', err));
    }

    // Initial poll
    pollOnlineStatus();

    // Poll every 15 seconds
    setInterval(pollOnlineStatus, 15000);

    // Poll when tab becomes visible
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        pollOnlineStatus();
      }
    });
  </script>

</body>

</html>