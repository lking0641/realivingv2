<?php
//home_settings_dashboard.php
session_start();
include $includes ['connection'];
include $includes ['mainbody'];
include $includes ['checkrole'];

// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales']);

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

// Get counts for each section
$hero_count = $conn->query("SELECT COUNT(*) as count FROM hero_section")->fetch_assoc()['count'];
$hero_active = $conn->query("SELECT COUNT(*) as count FROM hero_section WHERE is_active = 1")->fetch_assoc()['count'];

$inquire_count = $conn->query("SELECT COUNT(*) as count FROM inquire_images")->fetch_assoc()['count'];
$inquire_active = $conn->query("SELECT COUNT(*) as count FROM inquire_images WHERE is_active = 1")->fetch_assoc()['count'];

$ads_count = $conn->query("SELECT COUNT(*) as count FROM ads_banner")->fetch_assoc()['count'];
$ads_active = $conn->query("SELECT COUNT(*) as count FROM ads_banner WHERE is_active = 1")->fetch_assoc()['count'];

$services_count = $conn->query("SELECT COUNT(*) as count FROM services_section")->fetch_assoc()['count'];
$services_active = $conn->query("SELECT COUNT(*) as count FROM services_section WHERE is_active = 1")->fetch_assoc()['count'];

$ads_content_count = $conn->query("SELECT COUNT(*) as count FROM ads_content")->fetch_assoc()['count'];
$ads_content_active = $conn->query("SELECT COUNT(*) as count FROM ads_content WHERE is_active = 1")->fetch_assoc()['count'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Home Settings - RealLiving</title>
  <link rel="icon" type="image/png" sizes="32x32" href="../../logo/favicon.ico">
  <!-- Font Awesome CDN -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <!-- Google Fonts: Inter -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root{
      --adm-bg:#F5F5F5;
      --adm-surface:#FFFFFF;
      --adm-ink:#0B0B0B;
      --adm-soft:#6B6B6B;
      --adm-muted:#9A9A9A;
      --adm-line:#E2E2E2;
    }

    body{
      font-family:'Inter', sans-serif;
      background: var(--adm-bg);
      color: var(--adm-ink);
    }

    /* ── Header ─────────────────────────────── */
    .adm-eyebrow{
      font-size:11px; font-weight:600; letter-spacing:1.5px; text-transform:uppercase;
      color: var(--adm-soft);
    }
    .adm-title{
      font-size:28px; font-weight:700; letter-spacing:-0.01em; color: var(--adm-ink);
    }
    .adm-subtitle{
      font-size:13.5px; color: var(--adm-soft);
    }
    .adm-back{
      width:38px; height:38px; border-radius:9px;
      background: var(--adm-surface);
      border:1px solid var(--adm-line);
      color: var(--adm-ink);
      display:flex; align-items:center; justify-content:center;
      font-size:14px;
      transition: border-color .2s ease, transform .2s ease;
    }
    .adm-back:hover{
      border-color: var(--adm-ink);
      transform: translateX(-2px);
    }

    /* ── Section label ──────────────────────── */
    .adm-section-label{
      font-size:12px; font-weight:600; color: var(--adm-ink);
      display:flex; align-items:center; gap:10px;
    }
    .adm-section-label::after{
      content:""; flex:1; height:1px; background: var(--adm-line);
    }

    /* ── Cards ──────────────────────────────── */
    .adm-card{
      display:block;
      background: var(--adm-surface);
      border:1px solid var(--adm-line);
      border-radius:10px;
      padding:1.5rem;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }
    .adm-card:hover,
    .adm-card:focus-visible{
      border-color: var(--adm-ink);
      box-shadow: 0 10px 26px -16px rgba(11,11,11,0.25);
      transform: translateY(-2px);
      outline:none;
    }
    .adm-icon{
      width:44px; height:44px; border-radius:9px;
      background: var(--adm-bg);
      border:1px solid var(--adm-line);
      color: var(--adm-ink);
      display:flex; align-items:center; justify-content:center;
      font-size:17px;
      margin-bottom:1rem;
    }
    .adm-card-title{
      font-size:15px; font-weight:600; color: var(--adm-ink); margin-bottom:.35rem;
    }
    .adm-card-desc{
      font-size:13px; line-height:1.5; color: var(--adm-soft); margin-bottom:1.1rem;
    }
    .adm-card-link{
      font-size:12.5px; font-weight:600; color: var(--adm-ink);
      display:inline-flex; align-items:center; gap:6px;
    }
    .adm-card-link i{ font-size:10px; transition: transform .2s ease; }
    .adm-card:hover .adm-card-link i{ transform: translateX(3px); }

    /* ── Stat row inside cards ───────────────── */
    .adm-card-stats{
      display:flex; align-items:center; justify-content:space-between;
      padding-top:1rem; margin-top:.25rem;
      border-top:1px solid var(--adm-line);
    }
    .adm-card-stats-group{ display:flex; align-items:center; gap:1.5rem; }
    .adm-stat-num{ font-size:20px; font-weight:700; color: var(--adm-ink); line-height:1.2; }
    .adm-stat-num.is-active{ color:#16A34A; }
    .adm-stat-caption{ font-size:11px; color: var(--adm-muted); margin-top:2px; }

    @keyframes adm-fade{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }
    .adm-fade{ animation: adm-fade .4s ease both; }
    @media (prefers-reduced-motion: reduce){ .adm-fade{ animation:none; } }
  </style>
</head>

<body class="min-h-screen flex flex-col">

  <!-- Main Content -->
  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">

    <!-- Dashboard Header -->
    <div class="mb-10 adm-fade flex items-center justify-between">
      <div class="flex items-center gap-4">
        <a href="<?= BASE_URL ?>sales-dashboard" class="adm-back">
          <i class="fas fa-arrow-left"></i>
        </a>
        <div>
          <div class="adm-eyebrow mb-2">Sales &amp; Marketing</div>
          <h1 class="adm-title">Home Settings</h1>
          <p class="adm-subtitle mt-1">Manage hero section, inquire, and ads images.</p>
        </div>
      </div>
      <img src="../../realiving_user/images/logo/realiving_logo_hd.png" alt="Logo" class="h-12 object-contain hidden sm:block" />
    </div>

    <!-- Row 1: Hero, Inquire, Ads Banner -->
    <div class="mb-10 adm-fade">
      <div class="adm-section-label mb-4">Homepage Media</div>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

        <!-- Hero Section -->
        <a href="<?= BASE_URL ?>hero-view" class="adm-card">
          <div class="adm-icon"><i class="fas fa-panorama"></i></div>
          <h3 class="adm-card-title">Hero Section</h3>
          <p class="adm-card-desc">Multiple images can be active at once.</p>
          <div class="adm-card-stats">
            <div class="adm-card-stats-group">
              <div>
                <div class="adm-stat-num"><?php echo $hero_count; ?></div>
                <div class="adm-stat-caption">Total</div>
              </div>
              <div>
                <div class="adm-stat-num is-active"><?php echo $hero_active; ?></div>
                <div class="adm-stat-caption">Active</div>
              </div>
            </div>
            <span class="adm-card-link"><i class="fas fa-arrow-right"></i></span>
          </div>
        </a>

        <!-- Inquire Image -->
        <a href="home_settings_inquire_view.php" class="adm-card">
          <div class="adm-icon"><i class="fas fa-circle-question"></i></div>
          <h3 class="adm-card-title">Inquire Image</h3>
          <p class="adm-card-desc">Only 1 image can be active at a time.</p>
          <div class="adm-card-stats">
            <div class="adm-card-stats-group">
              <div>
                <div class="adm-stat-num"><?php echo $inquire_count; ?></div>
                <div class="adm-stat-caption">Total</div>
              </div>
              <div>
                <div class="adm-stat-num is-active"><?php echo $inquire_active; ?></div>
                <div class="adm-stat-caption">Active</div>
              </div>
            </div>
            <span class="adm-card-link"><i class="fas fa-arrow-right"></i></span>
          </div>
        </a>

        <!-- Ads Banner -->
        <a href="home_settings_ads_view.php" class="adm-card">
          <div class="adm-icon"><i class="fas fa-rectangle-ad"></i></div>
          <h3 class="adm-card-title">Ads Banner</h3>
          <p class="adm-card-desc">Only 1 banner can be active at a time.</p>
          <div class="adm-card-stats">
            <div class="adm-card-stats-group">
              <div>
                <div class="adm-stat-num"><?php echo $ads_count; ?></div>
                <div class="adm-stat-caption">Total</div>
              </div>
              <div>
                <div class="adm-stat-num is-active"><?php echo $ads_active; ?></div>
                <div class="adm-stat-caption">Active</div>
              </div>
            </div>
            <span class="adm-card-link"><i class="fas fa-arrow-right"></i></span>
          </div>
        </a>

      </div>
    </div>

    <!-- Row 2: Services, Ads Content -->
    <div class="adm-fade">
      <div class="adm-section-label mb-4">Sections &amp; Content</div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

        <!-- Services Section -->
        <a href="home_settings_services_view.php" class="adm-card">
          <div class="adm-icon"><i class="fas fa-concierge-bell"></i></div>
          <h3 class="adm-card-title">Services Section</h3>
          <p class="adm-card-desc">Manage service cards displayed on the homepage.</p>
          <div class="adm-card-stats">
            <div class="adm-card-stats-group">
              <div>
                <div class="adm-stat-num"><?php echo $services_count; ?></div>
                <div class="adm-stat-caption">Total</div>
              </div>
              <div>
                <div class="adm-stat-num is-active"><?php echo $services_active; ?></div>
                <div class="adm-stat-caption">Active</div>
              </div>
            </div>
            <span class="adm-card-link"><i class="fas fa-arrow-right"></i></span>
          </div>
        </a>

        <!-- Ads Content -->
        <a href="home_settings_ads_content_view.php" class="adm-card">
          <div class="adm-icon"><i class="fas fa-bullhorn"></i></div>
          <h3 class="adm-card-title">Ads Content</h3>
          <p class="adm-card-desc">Manage ads with captions &amp; hashtags.</p>
          <div class="adm-card-stats">
            <div class="adm-card-stats-group">
              <div>
                <div class="adm-stat-num"><?php echo $ads_content_count; ?></div>
                <div class="adm-stat-caption">Total</div>
              </div>
              <div>
                <div class="adm-stat-num is-active"><?php echo $ads_content_active; ?></div>
                <div class="adm-stat-caption">Active</div>
              </div>
            </div>
            <span class="adm-card-link"><i class="fas fa-arrow-right"></i></span>
          </div>
        </a>

      </div>
    </div>

  </div>

</body>

</html>