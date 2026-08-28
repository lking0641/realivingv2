<?php
//projects_dashboard.php
include $includes['mainbody'];

// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales']);

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

// Get counts for each category
$all_count = $conn->query("SELECT COUNT(*) as count FROM project")->fetch_assoc()['count'];
$site_count = $conn->query("SELECT COUNT(*) as count FROM project WHERE category = 'site'")->fetch_assoc()['count'];
$residential_count = $conn->query("SELECT COUNT(*) as count FROM project WHERE category = 'residential'")->fetch_assoc()['count'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Projects Dashboard - RealLiving</title>
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
      --adm-ink: #0B0B0B;
      --adm-soft: #6B6B6B;
      --adm-muted: #9A9A9A;
      --adm-line: #E2E2E2;
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

    /* ── Back link ──────────────────────────── */
    .adm-back {
      width: 38px;
      height: 38px;
      border-radius: 9px;
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      color: var(--adm-ink);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: border-color .2s ease, transform .2s ease;
    }

    .adm-back:hover {
      border-color: var(--adm-ink);
      transform: translateX(-2px);
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

    /* ── Cards (Categories) ─────────────────── */
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

    /* ── Count block inside category cards ──── */
    .adm-card-count-wrap {
      padding-top: 1rem;
      border-top: 1px solid var(--adm-line);
    }

    .adm-card-count {
      font-size: 30px;
      font-weight: 700;
      color: var(--adm-ink);
      line-height: 1.1;
    }

    .adm-card-count-label {
      font-size: 11.5px;
      color: var(--adm-muted);
      margin-top: .15rem;
    }

    /* ── Quick action stat-style cards ──────── */
    .adm-stat {
      position: relative;
      display: flex;
      align-items: center;
      gap: 14px;
      background: var(--adm-surface);
      border: 1px dashed var(--adm-line);
      border-radius: 10px;
      padding: 1.1rem 1.2rem;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease, background .2s ease;
    }

    .adm-stat:hover,
    .adm-stat:focus-visible {
      border-color: var(--adm-ink);
      border-style: solid;
      box-shadow: 0 10px 26px -16px rgba(11, 11, 11, 0.25);
      transform: translateY(-2px);
      outline: none;
    }

    .adm-stat-icon-box {
      width: 40px;
      height: 40px;
      flex-shrink: 0;
      border-radius: 9px;
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      color: var(--adm-ink);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
    }

    .adm-stat-title {
      font-size: 14px;
      font-weight: 600;
      color: var(--adm-ink);
      margin-bottom: .1rem;
    }

    .adm-stat-desc {
      font-size: 12.5px;
      color: var(--adm-soft);
    }

    /* ── Toast ──────────────────────────────── */
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
    <div class="mb-10 adm-fade flex items-center gap-4">
      <a href="<?= BASE_URL ?>sales-dashboard" class="adm-back">
        <i class="fas fa-arrow-left text-sm"></i>
      </a>
      <div>
        <div class="adm-eyebrow mb-2">Projects Management</div>
        <h1 class="adm-title">Projects Dashboard</h1>
        <p class="adm-subtitle mt-1">Manage all your projects and categories.</p>
      </div>
    </div>

    <!-- Category Overview Cards -->
    <div class="mb-10 adm-fade">
      <div class="adm-section-label mb-4">Categories</div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

        <!-- All Projects Card -->
        <a href="<?= BASE_URL ?>projects-view?category=all" class="adm-card">
          <div class="adm-icon"><i class="fas fa-folder"></i></div>
          <h3 class="adm-card-title">All Projects</h3>
          <p class="adm-card-desc">View every project across categories.</p>
          <div class="adm-card-count-wrap">
            <div class="adm-card-count"><?php echo $all_count; ?></div>
            <div class="adm-card-count-label">Total Projects</div>
          </div>
        </a>

        <!-- Site Projects Card -->
        <a href="<?= BASE_URL ?>projects-view?category=site" class="adm-card">
          <div class="adm-icon"><i class="fas fa-building"></i></div>
          <h3 class="adm-card-title">Site Projects</h3>
          <p class="adm-card-desc">Construction site projects.</p>
          <div class="adm-card-count-wrap">
            <div class="adm-card-count"><?php echo $site_count; ?></div>
            <div class="adm-card-count-label">Total Projects</div>
          </div>
        </a>

        <!-- Residential Interiors Card -->
        <a href="<?= BASE_URL ?>projects-view?category=residential" class="adm-card">
          <div class="adm-icon"><i class="fas fa-home"></i></div>
          <h3 class="adm-card-title">Residential</h3>
          <p class="adm-card-desc">Home interior projects.</p>
          <div class="adm-card-count-wrap">
            <div class="adm-card-count"><?php echo $residential_count; ?></div>
            <div class="adm-card-count-label">Total Projects</div>
          </div>
        </a>

      </div>
    </div>

    <!-- Quick Actions -->
    <div class="adm-fade">
      <div class="adm-section-label mb-4">Quick Actions</div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

        <a href="<?= BASE_URL ?>projects-view?action=add" class="adm-stat">
          <div class="adm-stat-icon-box"><i class="fas fa-plus"></i></div>
          <div>
            <div class="adm-stat-title">Add New Project</div>
            <div class="adm-stat-desc">Create a new project</div>
          </div>
        </a>

        <a href="<?= BASE_URL ?>projects-cabinet-cost-settings" class="adm-stat">
          <div class="adm-stat-icon-box"><i class="fas fa-image"></i></div>
          <div>
            <div class="adm-stat-title">Cabinet Cost Image</div>
            <div class="adm-stat-desc">Update section image</div>
          </div>
        </a>

        <a href="<?= BASE_URL ?>projects-view" class="adm-stat">
          <div class="adm-stat-icon-box"><i class="fas fa-list-check"></i></div>
          <div>
            <div class="adm-stat-title">View All Projects</div>
            <div class="adm-stat-desc">Manage existing projects</div>
          </div>
        </a>

      </div>
    </div>

  </div>

</body>

</html>