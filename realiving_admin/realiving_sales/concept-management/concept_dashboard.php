<?php
//concept_dashboard.php
include $includes ['mainbody'];

// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales']);

// Get statistics
$header_data = $conn->query("SELECT * FROM concept_header LIMIT 1")->fetch_assoc();
$styles_count = $conn->query("SELECT COUNT(*) as count FROM concept_styles")->fetch_assoc()['count'];
$carousel_count = $conn->query("SELECT COUNT(*) as count FROM concept_carousel")->fetch_assoc()['count'];

// Get recent styles (last 3)
$recent_styles = $conn->query("SELECT * FROM concept_styles ORDER BY display_order DESC LIMIT 3");

// Get recent carousel images (last 3)
$recent_carousel = $conn->query("SELECT * FROM concept_carousel ORDER BY display_order DESC LIMIT 3");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Concept Page Dashboard - RealLiving</title>
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

    /* ── Metric cards (statistics) ──────────── */
    .adm-metric {
      display: flex;
      align-items: center;
      gap: 16px;
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      padding: 1.35rem 1.4rem;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }

    .adm-metric:hover {
      border-color: var(--adm-ink);
      box-shadow: 0 10px 26px -16px rgba(11, 11, 11, 0.25);
      transform: translateY(-2px);
    }

    .adm-metric-icon {
      width: 46px;
      height: 46px;
      border-radius: 9px;
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      color: var(--adm-ink);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      flex-shrink: 0;
    }

    .adm-metric-label {
      font-size: 12.5px;
      color: var(--adm-soft);
      margin-bottom: 3px;
    }

    .adm-metric-number {
      font-size: 24px;
      font-weight: 700;
      color: var(--adm-ink);
    }

    /* ── Panel (management cards) ───────────── */
    .adm-panel {
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      overflow: hidden;
    }

    .adm-panel-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 18px 22px;
      border-bottom: 1px solid var(--adm-line);
      gap: 12px;
    }

    .adm-panel-head h2 {
      font-size: 15px;
      font-weight: 700;
      color: var(--adm-ink);
    }

    .adm-panel-body {
      padding: 20px 22px;
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
      white-space: nowrap;
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

    /* ── Header preview ─────────────────────── */
    .header-preview {
      position: relative;
      height: 180px;
      background-size: cover;
      background-position: center;
      background-color: var(--adm-bg);
      border-radius: 8px;
      overflow: hidden;
      margin-bottom: 14px;
      border: 1px solid var(--adm-line);
    }

    .header-overlay {
      position: absolute;
      inset: 0;
      background: rgba(11, 11, 11, .55);
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      padding: 20px;
      color: #fff;
      text-align: center;
    }

    .header-overlay h3 {
      font-size: 20px;
      font-weight: 700;
      margin-bottom: 8px;
    }

    .header-overlay p {
      font-size: 13px;
      opacity: .9;
    }

    .adm-hint {
      font-size: 12.5px;
      color: var(--adm-soft);
      line-height: 1.5;
    }

    /* ── Item preview list ──────────────────── */
    .items-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .item-preview {
      display: flex;
      gap: 14px;
      padding: 13px 14px;
      background: var(--adm-surface2);
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      align-items: center;
    }

    .item-image {
      width: 60px;
      height: 60px;
      object-fit: cover;
      border-radius: 6px;
      flex-shrink: 0;
      border: 1px solid var(--adm-line);
      background: var(--adm-bg);
    }

    .item-info {
      flex: 1;
      min-width: 0;
    }

    .item-info h4 {
      font-size: 14px;
      font-weight: 600;
      margin-bottom: 3px;
      color: var(--adm-ink);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .item-info p {
      font-size: 12px;
      color: var(--adm-soft);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      margin-top: 2px;
    }

    .adm-badge {
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .3px;
      text-transform: uppercase;
      border: 1px solid var(--adm-line);
      background: var(--adm-surface);
      color: var(--adm-soft);
      flex-shrink: 0;
    }

    .adm-list-note {
      font-size: 12px;
      color: var(--adm-muted);
      margin-top: 14px;
    }

    .adm-empty {
      text-align: center;
      padding: 34px 20px;
      color: var(--adm-muted);
    }

    .adm-empty i {
      font-size: 30px;
      opacity: .35;
      display: block;
      margin-bottom: 10px;
    }

    .adm-empty p {
      font-size: 13px;
      color: var(--adm-soft);
    }

    /* ── Quick action cards ─────────────────── */
    .adm-action {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 15px 16px;
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      text-decoration: none;
      color: var(--adm-ink);
      font-weight: 600;
      font-size: 13.5px;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }

    .adm-action:hover {
      border-color: var(--adm-ink);
      box-shadow: 0 10px 26px -16px rgba(11, 11, 11, 0.25);
      transform: translateY(-2px);
    }

    .adm-action-icon {
      width: 38px;
      height: 38px;
      border-radius: 8px;
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 15px;
      color: var(--adm-ink);
      flex-shrink: 0;
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

  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">

    <!-- Dashboard Header -->
    <div class="mb-10 adm-fade">
      <div class="adm-eyebrow mb-2">Sales &amp; Marketing</div>
      <h1 class="adm-title">Concept Page Dashboard</h1>
      <p class="adm-subtitle mt-1">Manage your concept designs page content, header, styles, and carousel images.</p>
    </div>

    <!-- Statistics -->
    <div class="mb-10 adm-fade">
      <div class="adm-section-label mb-4">Overview</div>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="adm-metric">
          <div class="adm-metric-icon"><i class="fas fa-file-lines"></i></div>
          <div>
            <div class="adm-metric-label">Header Settings</div>
            <div class="adm-metric-number">1</div>
          </div>
        </div>
        <div class="adm-metric">
          <div class="adm-metric-icon"><i class="fas fa-palette"></i></div>
          <div>
            <div class="adm-metric-label">Design Styles</div>
            <div class="adm-metric-number"><?php echo $styles_count; ?></div>
          </div>
        </div>
        <div class="adm-metric">
          <div class="adm-metric-icon"><i class="fas fa-images"></i></div>
          <div>
            <div class="adm-metric-label">Carousel Images</div>
            <div class="adm-metric-number"><?php echo $carousel_count; ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Management Sections -->
    <div class="mb-10 adm-fade">
      <div class="adm-section-label mb-4">Management</div>
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        <!-- Header Management -->
        <div class="adm-panel">
          <div class="adm-panel-head">
            <h2>Page Header</h2>
            <a href="concept-manage-header" class="adm-btn adm-btn-primary">Edit Header</a>
          </div>
          <div class="adm-panel-body">
            <?php if ($header_data): ?>
              <div class="header-preview" style="background-image: url('<?php echo CLIENT_ASSET ?>/<?php echo htmlspecialchars($header_data['header_image']); ?>')">
                <div class="header-overlay">
                  <h3><?php echo htmlspecialchars($header_data['title']); ?></h3>
                  <p><?php echo htmlspecialchars(substr($header_data['subtitle'], 0, 100)) . '...'; ?></p>
                </div>
              </div>
              <p class="adm-hint">Control the main header section with background image, title, and subtitle.</p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Styles Management -->
        <div class="adm-panel">
          <div class="adm-panel-head">
            <h2>Design Styles</h2>
            <a href="concept-manage-styles" class="adm-btn adm-btn-outline">Manage</a>
          </div>
          <div class="adm-panel-body">
            <?php if ($recent_styles->num_rows > 0): ?>
              <div class="items-list">
                <?php while ($style = $recent_styles->fetch_assoc()): ?>
                  <div class="item-preview">
                    <div class="item-info">
                      <h4><?php echo htmlspecialchars($style['title']); ?></h4>
                      <p><?php echo htmlspecialchars(substr($style['description'], 0, 50)) . '...'; ?></p>
                      <p style="margin-top:5px;"><i class="fas fa-link" style="opacity:.5;"></i> <?php echo htmlspecialchars(substr($style['iframe_url'], 0, 40)) . '...'; ?></p>
                    </div>
                    <span class="adm-badge"><?php echo $style['layout_type']; ?></span>
                  </div>
                <?php endwhile; ?>
              </div>
              <p class="adm-list-note">Showing last 3 styles. Total: <?php echo $styles_count; ?></p>
            <?php else: ?>
              <div class="adm-empty">
                <i class="fas fa-palette"></i>
                <p>No styles added yet</p>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Carousel Management -->
        <div class="adm-panel">
          <div class="adm-panel-head">
            <h2>Carousel Images</h2>
            <a href="concept-manage-carousel" class="adm-btn adm-btn-outline">Manage</a>
          </div>
          <div class="adm-panel-body">
            <?php if ($recent_carousel->num_rows > 0): ?>
              <div class="items-list">
                <?php while ($carousel = $recent_carousel->fetch_assoc()): ?>
                  <div class="item-preview">
                    <img src="<?php echo CLIENT_ASSET ?>/<?php echo htmlspecialchars($carousel['image_path']); ?>" class="item-image" alt="">
                    <div class="item-info">
                      <h4>Carousel Image #<?php echo $carousel['id']; ?></h4>
                      <p>Display order: <?php echo $carousel['display_order']; ?></p>
                    </div>
                  </div>
                <?php endwhile; ?>
              </div>
              <p class="adm-list-note">Showing last 3 images. Total: <?php echo $carousel_count; ?></p>
            <?php else: ?>
              <div class="adm-empty">
                <i class="fas fa-images"></i>
                <p>No carousel images added yet</p>
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>

    <!-- Quick Actions -->
    <div class="adm-fade">
      <div class="adm-section-label mb-4">Quick Actions</div>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <a href="<?= BASE_URL ?>concept-manage-header" class="adm-action">
          <div class="adm-action-icon"><i class="fas fa-pen"></i></div>
          <span>Edit Header</span>
        </a>
        <a href="concept-manage-styles" class="adm-action">
          <div class="adm-action-icon"><i class="fas fa-plus"></i></div>
          <span>Add New Style</span>
        </a>
        <a href="concept-manage-carousel" class="adm-action">
          <div class="adm-action-icon"><i class="fas fa-image"></i></div>
          <span>Add Carousel Image</span>
        </a>
        <a href="<?= BASE_URL ?>concepts" class="adm-action" target="_blank">
          <div class="adm-action-icon"><i class="fas fa-eye"></i></div>
          <span>View Live Page</span>
        </a>
      </div>
    </div>

  </div>
</body>

</html>