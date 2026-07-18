<?php
include $includes ['mainbody'];



// Allow only admin1 to admin5
require_role(['admin1','superadmin', 'sales', 'designer']);

// Extra check: if designer, only heads can access
if ($_SESSION['admin_role'] === 'designer') {
    $headCheck = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
    $headCheck->bind_param("i", $_SESSION['admin_id']);
    $headCheck->execute();
    $headRow = $headCheck->get_result()->fetch_assoc();
    $headCheck->close();

    if (empty($headRow['is_head'])) {
        $_SESSION['noti'] = 'Access Denied: Only head designers can access this page.';
        header("Location: ../../realiving_admin/tracker_site_visit/designer_layout_list.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Product Management - RealLiving</title>
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

    /* ── Utility link card (Dimension Label) ─── */
    .adm-util-card{
      background: var(--adm-surface);
      border:1px solid var(--adm-line);
      border-radius:11px;
      padding:.9rem 1.1rem;
      display:inline-flex; align-items:center; gap:.85rem;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }
    .adm-util-card:hover{
      border-color: var(--adm-ink);
      box-shadow: 0 10px 26px -16px rgba(11,11,11,0.25);
      transform: translateY(-2px);
    }
    .adm-util-icon{
      width:38px; height:38px; border-radius:9px;
      display:flex; align-items:center; justify-content:center;
      background: var(--adm-ink); color:#fff; font-size:15px; flex-shrink:0;
    }
    .adm-util-title{ font-size:13px; font-weight:600; color: var(--adm-ink); }
    .adm-util-sub{ font-size:11.5px; color: var(--adm-soft); }
    .adm-util-arrow{ color: var(--adm-muted); font-size:12px; transition: transform .2s ease; }
    .adm-util-card:hover .adm-util-arrow{ transform: translateX(3px); color: var(--adm-ink); }

    /* ── Main action cards ──────────────────── */
    .adm-action-card{
      background: var(--adm-surface);
      border:1px solid var(--adm-line);
      border-radius:16px;
      padding:2.25rem 1.75rem;
      text-align:center;
      height:100%;
      display:flex; flex-direction:column;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }
    .adm-action-card:hover{
      border-color: var(--adm-ink);
      box-shadow: 0 20px 44px -22px rgba(11,11,11,0.3);
      transform: translateY(-4px);
    }
    .adm-action-icon{
      width:76px; height:76px; margin:0 auto 1.5rem;
      border-radius:16px;
      display:flex; align-items:center; justify-content:center;
      background: var(--adm-ink); color:#fff; font-size:30px;
      transition: transform .3s ease;
    }
    .adm-action-card:hover .adm-action-icon{ transform: scale(1.08); }
    .adm-action-title{
      font-size:20px; font-weight:700; color: var(--adm-ink); margin-bottom:.75rem; letter-spacing:-0.01em;
    }
    .adm-action-desc{
      font-size:13.5px; color: var(--adm-soft); line-height:1.6; margin-bottom:1.5rem;
    }
    .adm-feature-list{
      display:flex; flex-direction:column; gap:.6rem; margin-bottom:1.75rem;
      text-align:left;
    }
    .adm-feature-item{
      display:flex; align-items:center; gap:.6rem;
      font-size:12.5px; color: var(--adm-ink);
    }
    .adm-feature-item i{ color:#16A34A; font-size:12px; flex-shrink:0; }
    .adm-action-btn{
      margin-top:auto;
      display:inline-flex; align-items:center; justify-content:center; gap:8px;
      background: var(--adm-ink); color:#fff;
      font-size:13px; font-weight:600;
      padding:.8rem 1.5rem; border-radius:9px;
      transition: opacity .2s ease, transform .2s ease;
    }
    .adm-action-card:hover .adm-action-btn{ opacity:.85; }
    .adm-action-btn i{ transition: transform .2s ease; }
    .adm-action-card:hover .adm-action-btn i{ transform: translateX(3px); }

    @keyframes adm-fade{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }
    .adm-fade{ animation: adm-fade .4s ease both; }
    @media (prefers-reduced-motion: reduce){ .adm-fade{ animation:none; } }
  </style>
</head>
<body class="min-h-screen">

  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">

    <!-- Header -->
    <div class="mb-8 adm-fade flex items-start justify-between flex-wrap gap-4">
      <div>
        <div class="adm-eyebrow mb-2">Catalog Management</div>
        <h1 class="adm-title">Product Management</h1>
        <p class="adm-subtitle mt-1">Manage products, accessories, and their relationships.</p>
      </div>
      <a href="insert_dimension.php" class="adm-util-card adm-fade">
        <div class="adm-util-icon"><i class="fas fa-ruler-combined"></i></div>
        <div>
          <div class="adm-util-title">Dimension Label</div>
          <div class="adm-util-sub">Manage labels</div>
        </div>
        <i class="fas fa-chevron-right adm-util-arrow"></i>
      </a>
    </div>

    <!-- Action cards -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 adm-fade">

      <!-- Add Product -->
      <a href="<?= BASE_URL ?>view-products" class="block">
        <div class="adm-action-card">
          <div class="adm-action-icon"><i class="fas fa-boxes-stacked"></i></div>
          <h2 class="adm-action-title">Add New Product</h2>
          <p class="adm-action-desc">Create and manage your main products with detailed specifications, dimensions, and variants.</p>
          <div class="adm-feature-list">
            <div class="adm-feature-item"><i class="fas fa-check"></i><span>Product Images &amp; Gallery</span></div>
            <div class="adm-feature-item"><i class="fas fa-check"></i><span>Linear &amp; Square Dimensions</span></div>
            <div class="adm-feature-item"><i class="fas fa-check"></i><span>Color Options &amp; Variants</span></div>
          </div>
          <span class="adm-action-btn"><span>Get Started</span><i class="fas fa-arrow-right"></i></span>
        </div>
      </a>

      <!-- Link Product Accessories -->
      <a href="link_product_addons.php" class="block">
        <div class="adm-action-card">
          <div class="adm-action-icon"><i class="fas fa-link"></i></div>
          <h2 class="adm-action-title">Link Product Accessories</h2>
          <p class="adm-action-desc">Connect your products with compatible accessories to enhance customer customization options.</p>
          <div class="adm-feature-list">
            <div class="adm-feature-item"><i class="fas fa-check"></i><span>Product-Accessory Relationships</span></div>
            <div class="adm-feature-item"><i class="fas fa-check"></i><span>Required &amp; Optional Settings</span></div>
            <div class="adm-feature-item"><i class="fas fa-check"></i><span>Quantity Restrictions</span></div>
          </div>
          <span class="adm-action-btn"><span>Get Started</span><i class="fas fa-arrow-right"></i></span>
        </div>
      </a>

      <!-- Add Accessory -->
      <a href="view_addons.php" class="block">
        <div class="adm-action-card">
          <div class="adm-action-icon"><i class="fas fa-puzzle-piece"></i></div>
          <h2 class="adm-action-title">Add New Accessory</h2>
          <p class="adm-action-desc">Create independent accessory products that can enhance and complement your main product offerings.</p>
          <div class="adm-feature-list">
            <div class="adm-feature-item"><i class="fas fa-check"></i><span>Independent Products</span></div>
            <div class="adm-feature-item"><i class="fas fa-check"></i><span>Cross-Category Compatibility</span></div>
            <div class="adm-feature-item"><i class="fas fa-check"></i><span>Flexible Pricing Options</span></div>
          </div>
          <span class="adm-action-btn"><span>Get Started</span><i class="fas fa-arrow-right"></i></span>
        </div>
      </a>

    </div>
  </div>

</body>
</html>