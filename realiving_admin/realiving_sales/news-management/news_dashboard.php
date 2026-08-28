<?php
//news_dashboard.php
include $includes ['mainbody'];

// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales']);

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

// Get statistics
$header_data = $conn->query("SELECT * FROM news_header LIMIT 1")->fetch_assoc();
$total_news = $conn->query("SELECT COUNT(*) as count FROM news")->fetch_assoc()['count'];
$published_news = $conn->query("SELECT COUNT(*) as count FROM news WHERE status='published'")->fetch_assoc()['count'];
$draft_news = $conn->query("SELECT COUNT(*) as count FROM news WHERE status='draft'")->fetch_assoc()['count'];
$featured_news = $conn->query("SELECT COUNT(*) as count FROM news WHERE featured=1")->fetch_assoc()['count'];
$total_views = $conn->query("SELECT SUM(views) as total FROM news")->fetch_assoc()['total'] ?? 0;

// Get recent news (last 5)
$recent_news = $conn->query("SELECT * FROM news ORDER BY date_uploaded DESC LIMIT 5");

// Get top viewed news (top 3)
$top_viewed = $conn->query("SELECT * FROM news ORDER BY views DESC LIMIT 3");

// Get categories with counts
$categories = $conn->query("SELECT category, COUNT(*) as count FROM news GROUP BY category ORDER BY count DESC LIMIT 5");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>News Page Dashboard - RealLiving</title>
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
      gap: 14px;
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      padding: 1.2rem 1.3rem;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }

    .adm-metric:hover {
      border-color: var(--adm-ink);
      box-shadow: 0 10px 26px -16px rgba(11, 11, 11, 0.25);
      transform: translateY(-2px);
    }

    .adm-metric-icon {
      width: 42px;
      height: 42px;
      border-radius: 9px;
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      color: var(--adm-ink);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      flex-shrink: 0;
    }

    .adm-metric-label {
      font-size: 12px;
      color: var(--adm-soft);
      margin-bottom: 3px;
    }

    .adm-metric-number {
      font-size: 22px;
      font-weight: 700;
      color: var(--adm-ink);
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
      padding: 8px 15px;
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
      height: 170px;
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
      font-size: 19px;
      font-weight: 700;
      margin-bottom: 8px;
    }

    .header-overlay p {
      font-size: 12.5px;
      opacity: .9;
    }

    .adm-hint {
      font-size: 12.5px;
      color: var(--adm-soft);
      line-height: 1.5;
    }

    /* ── News list ──────────────────────────── */
    .news-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .news-item {
      display: flex;
      gap: 14px;
      padding: 13px 14px;
      background: var(--adm-surface2);
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      align-items: center;
      transition: border-color .15s ease;
    }

    .news-item:hover {
      border-color: var(--adm-ink);
    }

    .news-image {
      width: 84px;
      height: 60px;
      object-fit: cover;
      border-radius: 6px;
      flex-shrink: 0;
      border: 1px solid var(--adm-line);
      background: var(--adm-bg);
    }

    .news-rank {
      font-size: 20px;
      font-weight: 700;
      color: var(--adm-soft);
      width: 28px;
      text-align: center;
      flex-shrink: 0;
    }

    .news-content {
      flex: 1;
      min-width: 0;
    }

    .news-title {
      font-size: 14px;
      font-weight: 600;
      color: var(--adm-ink);
      margin-bottom: 5px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .news-meta {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      font-size: 11.5px;
      color: var(--adm-soft);
    }

    .news-meta span {
      display: flex;
      align-items: center;
      gap: 4px;
    }

    /* ── Badges ─────────────────────────────── */
    .adm-badge {
      padding: 3px 9px;
      border-radius: 999px;
      font-size: 10.5px;
      font-weight: 700;
      letter-spacing: .3px;
      text-transform: uppercase;
      border: 1px solid var(--adm-line);
      background: var(--adm-surface);
      color: var(--adm-soft);
    }

    .adm-badge.badge-published {
      color: #1e5631;
      background: #e6f4ea;
      border-color: #c5e6d0;
    }

    .adm-badge.badge-draft {
      color: var(--adm-soft);
      background: var(--adm-surface2);
      border-color: var(--adm-line);
    }

    .adm-badge.badge-featured {
      color: #7d5a00;
      background: #fff8e6;
      border-color: #f0e0b0;
    }

    /* ── Category list ──────────────────────── */
    .category-list {
      display: flex;
      flex-direction: column;
      gap: 9px;
    }

    .category-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 11px 14px;
      background: var(--adm-surface2);
      border: 1px solid var(--adm-line);
      border-radius: 8px;
    }

    .category-name {
      font-weight: 600;
      font-size: 13px;
      color: var(--adm-ink);
    }

    .category-count {
      background: var(--adm-ink);
      color: #fff;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
    }

    /* ── Empty state ────────────────────────── */
    .adm-empty {
      text-align: center;
      padding: 34px 20px;
      color: var(--adm-muted);
    }

    .adm-empty i {
      font-size: 28px;
      opacity: .35;
      display: block;
      margin-bottom: 10px;
    }

    .adm-empty p {
      font-size: 13px;
      color: var(--adm-soft);
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
      <h1 class="adm-title">News Page Dashboard</h1>
      <p class="adm-subtitle mt-1">Manage your news articles, header, and content distribution.</p>
    </div>

    <!-- Statistics -->
    <div class="mb-10 adm-fade">
      <div class="adm-section-label mb-4">Overview</div>
      <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
        <div class="adm-metric">
          <div class="adm-metric-icon"><i class="fas fa-file-lines"></i></div>
          <div>
            <div class="adm-metric-label">Total Articles</div>
            <div class="adm-metric-number"><?php echo $total_news; ?></div>
          </div>
        </div>
        <div class="adm-metric">
          <div class="adm-metric-icon"><i class="fas fa-circle-check"></i></div>
          <div>
            <div class="adm-metric-label">Published</div>
            <div class="adm-metric-number"><?php echo $published_news; ?></div>
          </div>
        </div>
        <div class="adm-metric">
          <div class="adm-metric-icon"><i class="fas fa-file-pen"></i></div>
          <div>
            <div class="adm-metric-label">Drafts</div>
            <div class="adm-metric-number"><?php echo $draft_news; ?></div>
          </div>
        </div>
        <div class="adm-metric">
          <div class="adm-metric-icon"><i class="fas fa-star"></i></div>
          <div>
            <div class="adm-metric-label">Featured</div>
            <div class="adm-metric-number"><?php echo $featured_news; ?></div>
          </div>
        </div>
        <div class="adm-metric">
          <div class="adm-metric-icon"><i class="fas fa-eye"></i></div>
          <div>
            <div class="adm-metric-label">Total Views</div>
            <div class="adm-metric-number"><?php echo number_format($total_views); ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="mb-10 adm-fade">
      <div class="adm-section-label mb-4">Quick Actions</div>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <a href="news-manage" class="adm-action">
          <div class="adm-action-icon"><i class="fas fa-plus"></i></div>
          <span>Add New Article</span>
        </a>
        <a href="news-manage-header" class="adm-action">
          <div class="adm-action-icon"><i class="fas fa-pen"></i></div>
          <span>Edit Header</span>
        </a>
        <a href="news-manage" class="adm-action">
          <div class="adm-action-icon"><i class="fas fa-list"></i></div>
          <span>Manage All Articles</span>
        </a>
        <a href="news" class="adm-action" target="_blank">
          <div class="adm-action-icon"><i class="fas fa-eye"></i></div>
          <span>View Live Page</span>
        </a>
      </div>
    </div>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 adm-fade">

      <!-- Left Column -->
      <div class="lg:col-span-2 flex flex-col gap-5">

        <!-- Page Header Card -->
        <div class="adm-panel">
          <div class="adm-panel-head">
            <h2>Page Header</h2>
            <a href="news-manage-header" class="adm-btn adm-btn-primary">Edit Header</a>
          </div>
          <div class="adm-panel-body">
            <?php if ($header_data): ?>
              <div class="header-preview" style="background-image: url('<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($header_data['header_image']); ?>')">
                <div class="header-overlay">
                  <h3><?php echo htmlspecialchars($header_data['title']); ?></h3>
                  <p><?php echo htmlspecialchars(substr($header_data['subtitle'], 0, 120)) . '...'; ?></p>
                </div>
              </div>
              <p class="adm-hint">Control the main header section with background image, title, and subtitle.</p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Recent Articles Card -->
        <div class="adm-panel">
          <div class="adm-panel-head">
            <h2>Recent Articles</h2>
            <a href="news-manage" class="adm-btn adm-btn-outline">View All</a>
          </div>
          <div class="adm-panel-body">
            <?php if ($recent_news->num_rows > 0): ?>
              <div class="news-list">
                <?php while ($news = $recent_news->fetch_assoc()): ?>
                  <div class="news-item">
                    <img src="<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($news['image']); ?>" class="news-image" alt="">
                    <div class="news-content">
                      <div class="news-title"><?php echo htmlspecialchars($news['title']); ?></div>
                      <div class="news-meta">
                        <span class="adm-badge badge-<?php echo $news['status']; ?>"><?php echo $news['status']; ?></span>
                        <?php if ($news['featured']): ?>
                          <span class="adm-badge badge-featured">Featured</span>
                        <?php endif; ?>
                        <span><i class="fas fa-calendar" style="opacity:.5;"></i> <?php echo date('M d, Y', strtotime($news['date_uploaded'])); ?></span>
                        <span><i class="fas fa-eye" style="opacity:.5;"></i> <?php echo $news['views']; ?> views</span>
                      </div>
                    </div>
                  </div>
                <?php endwhile; ?>
              </div>
            <?php else: ?>
              <div class="adm-empty">
                <i class="fas fa-newspaper"></i>
                <p>No articles yet. Create your first article!</p>
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>

      <!-- Right Column -->
      <div class="flex flex-col gap-5">

        <!-- Top Viewed Articles Card -->
        <div class="adm-panel">
          <div class="adm-panel-head">
            <h2>Top Viewed</h2>
          </div>
          <div class="adm-panel-body">
            <?php if ($top_viewed->num_rows > 0): ?>
              <div class="news-list">
                <?php $rank = 1; while ($news = $top_viewed->fetch_assoc()): ?>
                  <div class="news-item">
                    <div class="news-rank"><?php echo $rank++; ?></div>
                    <div class="news-content">
                      <div class="news-title"><?php echo htmlspecialchars($news['title']); ?></div>
                      <div class="news-meta">
                        <span><i class="fas fa-eye" style="opacity:.5;"></i> <?php echo number_format($news['views']); ?> views</span>
                      </div>
                    </div>
                  </div>
                <?php endwhile; ?>
              </div>
            <?php else: ?>
              <div class="adm-empty">
                <i class="fas fa-chart-line"></i>
                <p>No view data yet</p>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Categories Card -->
        <div class="adm-panel">
          <div class="adm-panel-head">
            <h2>Top Categories</h2>
          </div>
          <div class="adm-panel-body">
            <?php if ($categories->num_rows > 0): ?>
              <div class="category-list">
                <?php while ($cat = $categories->fetch_assoc()): ?>
                  <div class="category-item">
                    <span class="category-name"><?php echo htmlspecialchars($cat['category']); ?></span>
                    <span class="category-count"><?php echo $cat['count']; ?></span>
                  </div>
                <?php endwhile; ?>
              </div>
            <?php else: ?>
              <div class="adm-empty">
                <i class="fas fa-tags"></i>
                <p>No categories yet</p>
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>

  </div>
</body>

</html>