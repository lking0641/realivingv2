<?php
// home_settings_ads_content_view.php
include $includes ['mainbody'];


// ══════════════════════════════════════════════════════════
//  AUTO-ACTIVATE: scheduled posts whose date has arrived
// ══════════════════════════════════════════════════════════
$conn->query("
    UPDATE ads_content 
    SET is_active = 1 
    WHERE is_active = 0 
      AND scheduled_date IS NOT NULL 
      AND scheduled_date <= CURDATE()
      AND (posted_date IS NULL OR posted_date = scheduled_date)
");
// Set posted_date when activating for the first time
$conn->query("
    UPDATE ads_content 
    SET posted_date = scheduled_date 
    WHERE is_active = 1 
      AND (posted_date IS NULL OR posted_date = '0000-00-00' OR YEAR(posted_date) < 1900)
      AND scheduled_date IS NOT NULL
      AND scheduled_date != '0000-00-00'
");

// ══════════════════════════════════════════════════════════
//  AUTO-DELETE: posts older than 7 days, but keep minimum 3
// ══════════════════════════════════════════════════════════
$total_count_res = $conn->query("SELECT COUNT(*) AS cnt FROM ads_content WHERE is_active = 1");
$total_count     = $total_count_res->fetch_assoc()['cnt'];

if ($total_count > 3) {
    $expire_res = $conn->query("
        SELECT id, filepath 
        FROM ads_content 
        WHERE is_active = 1 
          AND posted_date IS NOT NULL 
          AND DATEDIFF(CURDATE(), posted_date) > 7
        ORDER BY posted_date ASC
    ");

    if ($expire_res && $expire_res->num_rows > 0) {
        $deletable  = [];
        while ($row = $expire_res->fetch_assoc()) $deletable[] = $row;

        $can_delete = $total_count - 3;
        $to_delete  = array_slice($deletable, 0, $can_delete);

        foreach ($to_delete as $d) {
            $did = intval($d['id']);
            $conn->query("DELETE FROM ads_content WHERE id = $did");
            $file_path = ROOT_PATH . "realiving_user/images/ads_content/" . basename($d['filepath']);
            if (file_exists($file_path)) unlink($file_path);
        }
    }
}

// ══════════════════════════════════════════════════════════
//  VALID POSTING DAYS: Monday–Saturday only
// ══════════════════════════════════════════════════════════
$today_dow    = (int) date('N'); // 1=Mon … 7=Sun
$is_valid_day = ($today_dow >= 1 && $today_dow <= 6);

$error_message = "";
$success_message = match($_GET['success'] ?? '') {
    'published' => "Post published and now active!",
    'scheduled' => "Post scheduled for " . htmlspecialchars($_GET['date'] ?? '') . ". It will go live automatically on that day.",
    'status'    => "Status updated!",
    'deleted'   => "Post deleted successfully!",
    'expired'   => "Post marked as expired.",
    'cancelled' => "Scheduled post cancelled and removed.",
    'updated'   => "Post updated successfully!",
    default     => ""
};

// ══════════════════════════════════════════════════════════
//  FORM HANDLING
// ══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── ADD ──────────────────────────────────────────────
    if ($action === 'add_ads_content') {

        $caption        = trim($_POST['caption']);
        $hashtags       = trim($_POST['hashtags']);
        $scheduled_date = trim($_POST['scheduled_date'] ?? '');

        // Validate scheduled_date
        $sched_valid = false;
        $sched_dow   = 0;
        if ($scheduled_date) {
            $sched_ts  = strtotime($scheduled_date);
            $sched_dow = (int) date('N', $sched_ts); // 1=Mon…7=Sun
            if ($sched_ts >= strtotime('today') && $sched_dow >= 1 && $sched_dow <= 6) {
                $sched_valid = true;
            }
        }

        if (!$sched_valid) {
            $error_message = "Please choose a valid scheduled date (Monday – Saturday, today or future).";
        } else {
            $target_dir = ROOT_PATH . "realiving_user/images/ads_content/";
            if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

            if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
                $file_extension     = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                if (!in_array($file_extension, $allowed_extensions)) {
                    $error_message = "Only image files (JPG, PNG, GIF, WebP) are allowed.";
                } else {
                    $file_name   = uniqid() . '_' . time() . '.webp';
                    $target_file = $target_dir . $file_name;
                    $filepath    = './images/ads_content/' . $file_name;
                    $temp_file   = $_FILES['image']['tmp_name'];
                    $image       = null;

                    switch ($file_extension) {
                        case 'jpg':
                        case 'jpeg': $image = imagecreatefromjpeg($temp_file); break;
                        case 'png':  $image = imagecreatefrompng($temp_file);  break;
                        case 'gif':  $image = imagecreatefromgif($temp_file);  break;
                        case 'webp': $image = imagecreatefromwebp($temp_file); break;
                    }

                    if ($image !== false && $image !== null) {
                        if (imagewebp($image, $target_file, 90)) {
                            imagedestroy($image);

                            // If scheduled for today → activate immediately
                            $is_today_sched = ($scheduled_date === date('Y-m-d'));
                            $is_active_val  = $is_today_sched ? 1 : 0;
                            $posted_date_val = $is_today_sched ? $scheduled_date : null;
                            $is_today_sched  = ($scheduled_date === date('Y-m-d'));
                            $is_active_val   = $is_today_sched ? 1 : 0;
                            $posted_date_val = $is_today_sched ? date('Y-m-d') : null;

                            $stmt = $conn->prepare("
                                INSERT INTO ads_content 
                                    (caption, hashtags, filepath, is_active, posted_date, day_of_week, scheduled_date) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->bind_param(
                                "ssssiis",
                                $caption, $hashtags, $filepath,
                                $is_active_val, $posted_date_val,
                                $sched_dow, $scheduled_date
                            );

                            if ($stmt->execute()) {
    $stmt->close();
    if ($is_today_sched) {
        header("Location: " . BASE_URL . "ads-content-view?success=published");
    } else {
        header("Location: " . BASE_URL . "ads-content-view?success=scheduled&date=" . urlencode(date('M d, Y', strtotime($scheduled_date))));
    }
    exit();
                            } else {
                                $error_message = "Database error: " . $conn->error;
                            }
                            $stmt->close();
                        } else {
                            imagedestroy($image);
                            $error_message = "Failed to convert image to WebP.";
                        }
                    } else {
                        $error_message = "Failed to process image.";
                    }
                }
            } else {
                $error_message = "Please select an image.";
            }
        }
    }

    // ── TOGGLE STATUS ────────────────────────────────────
    if ($action === 'toggle_status') {
        $id             = intval($_POST['id']);
        $current_status = intval($_POST['current_status']);
        $new_status     = $current_status === 1 ? 0 : 1;

        $stmt = $conn->prepare("UPDATE ads_content SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_status, $id);
        if ($stmt->execute()) {
    $stmt->close();
    header("Location: " . BASE_URL . "ads-content-view?success=status");
    exit();
} else {
    $error_message = "Failed to update status.";
    $stmt->close();
}
    }

    // ── DELETE ───────────────────────────────────────────
    if ($action === 'delete') {
        $id       = intval($_POST['id']);
        $filepath = $_POST['filepath'];

        $active_count_res = $conn->query("SELECT COUNT(*) AS cnt FROM ads_content WHERE is_active = 1");
        $active_count     = $active_count_res->fetch_assoc()['cnt'];

        if ($active_count <= 3) {
            $error_message = "Cannot delete — minimum of 3 active posts must remain.";
        } else {
            $stmt = $conn->prepare("DELETE FROM ads_content WHERE id = ?");
            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {
    $stmt->close();
    $file_to_delete = ROOT_PATH . "realiving_user/images/ads_content/" . basename($filepath);
    if (file_exists($file_to_delete)) unlink($file_to_delete);
    header("Location: " . BASE_URL . "ads-content-view?success=deleted");
    exit();
            } else {
                $error_message = "Failed to delete post.";
            }
            $stmt->close();
        }
    }

    // ── FORCE EXPIRE ─────────────────────────────────────
    if ($action === 'force_expire') {
        $id               = intval($_POST['id']);
        $active_count_res = $conn->query("SELECT COUNT(*) AS cnt FROM ads_content WHERE is_active = 1");
        $active_count     = $active_count_res->fetch_assoc()['cnt'];

        if ($active_count <= 3) {
            $error_message = "Cannot expire — minimum of 3 active posts must remain.";
        } else {
            $stmt = $conn->prepare("UPDATE ads_content SET is_active = 0 WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
    $stmt->close();
    header("Location: " . BASE_URL . "ads-content-view?success=expired");
    exit();
} else {
    $error_message = "Failed.";
    $stmt->close();
}
        }
    }

    // ── CANCEL SCHEDULED ─────────────────────────────────
    if ($action === 'cancel_scheduled') {
        $id       = intval($_POST['id']);
        $filepath = $_POST['filepath'];

        $stmt = $conn->prepare("DELETE FROM ads_content WHERE id = ? AND is_active = 0");
        $stmt->bind_param("i", $id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
    $stmt->close();
    $file_to_delete = ROOT_PATH . "realiving_user/images/ads_content/" . basename($filepath);
    if (file_exists($file_to_delete)) unlink($file_to_delete);
    header("Location: " . BASE_URL . "ads-content-view?success=cancelled");
    exit();
        } else {
            $error_message = "Could not cancel — post may already be active.";
        }
        $stmt->close();
    }
}

// ── Fetch all posts ───────────────────────────────────────
$ads_items = $conn->query("SELECT * FROM ads_content ORDER BY scheduled_date ASC, id DESC");

// ── Stats ─────────────────────────────────────────────────
$stat_res      = $conn->query("SELECT COUNT(*) AS total, SUM(is_active) AS active FROM ads_content");
$stats         = $stat_res->fetch_assoc();
$stat_total    = $stats['total'] ?? 0;
$stat_active   = $stats['active'] ?? 0;
$stat_scheduled_res = $conn->query("SELECT COUNT(*) AS cnt FROM ads_content WHERE is_active = 0 AND scheduled_date > CURDATE()");
$stat_scheduled = $stat_scheduled_res->fetch_assoc()['cnt'] ?? 0;
$expiring_res  = $conn->query("SELECT COUNT(*) AS cnt FROM ads_content WHERE is_active=1 AND posted_date IS NOT NULL AND DATEDIFF(CURDATE(), posted_date) >= 5");
$stat_expiring = $expiring_res->fetch_assoc()['cnt'] ?? 0;

// ── Day labels ────────────────────────────────────────────
$day_labels = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$dow_map    = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];

// ── Posts by day of week (for schedule strip) ─────────────
$posts_by_dow = [];
$dow_posts_res = $conn->query("
    SELECT day_of_week, COUNT(*) as cnt 
    FROM ads_content 
    WHERE (is_active = 1 OR (is_active = 0 AND scheduled_date >= CURDATE())) 
      AND day_of_week IS NOT NULL 
    GROUP BY day_of_week
");
if ($dow_posts_res) while ($r = $dow_posts_res->fetch_assoc()) $posts_by_dow[$r['day_of_week']] = $r['cnt'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Ads Content - RealLiving</title>
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
      font-size:13px; color: var(--adm-soft);
    }
    .adm-back{
      font-size:12.5px; font-weight:600; color: var(--adm-soft);
      display:inline-flex; align-items:center; gap:8px;
      margin-bottom:1rem;
      transition: color .2s ease, gap .2s ease;
    }
    .adm-back:hover{ color: var(--adm-ink); gap:11px; }

    /* ── Buttons ─────────────────────────────── */
    .adm-btn{
      display:inline-flex; align-items:center; gap:8px;
      background: var(--adm-ink); color:#fff;
      font-size:13px; font-weight:600;
      padding:.75rem 1.25rem; border-radius:9px;
      border:1px solid var(--adm-ink);
      transition: opacity .2s ease, transform .2s ease;
    }
    .adm-btn:hover{ opacity:.85; transform: translateY(-1px); }
    .adm-btn-block{ width:100%; justify-content:center; }
    .adm-btn-ghost{
      display:inline-flex; align-items:center; justify-content:center;
      width:32px; height:32px; border-radius:8px;
      color: var(--adm-soft); background:transparent; border:1px solid transparent;
      transition: background .2s ease, color .2s ease, border-color .2s ease;
    }
    .adm-btn-ghost:hover{ background: var(--adm-bg); color:#DC2626; border-color: var(--adm-line); }
    .adm-btn-ghost.is-edit:hover{ color:#2563EB; }
    .adm-btn-ghost:disabled, .adm-btn-ghost.is-disabled{ color:#D1D5DB; cursor:not-allowed; }
    .adm-btn-ghost:disabled:hover, .adm-btn-ghost.is-disabled:hover{ background:transparent; border-color:transparent; }

    .adm-chip{
      display:inline-flex; align-items:center; gap:6px;
      font-size:11px; font-weight:600;
      padding:.4rem .75rem; border-radius:8px;
      border:1px solid var(--adm-line);
      background: var(--adm-bg); color: var(--adm-soft);
      cursor:pointer; transition: opacity .2s ease, background .2s ease, color .2s ease;
    }
    .adm-chip:hover{ opacity:.85; }
    .adm-chip.is-active{ background:#ECFDF3; color:#16A34A; border-color:#BBF7D0; }
    .adm-chip.is-warn{ background:#FFFBEB; color:#B45309; border-color:#FDE68A; }

    /* ── Alerts ──────────────────────────────── */
    .adm-alert{
      display:flex; align-items:flex-start; gap:.75rem;
      border:1px solid var(--adm-line); border-left:3px solid var(--adm-ink);
      background: var(--adm-surface);
      border-radius:9px; padding:.9rem 1.1rem;
      font-size:13px; color: var(--adm-ink);
    }
    .adm-alert.is-error{ border-left-color:#DC2626; }
    .adm-alert.is-error i{ color:#DC2626; }
    .adm-alert.is-success i{ color:#16A34A; }

    /* ── Stat cards ──────────────────────────── */
    .adm-stat{
      background: var(--adm-surface);
      border:1px solid var(--adm-line);
      border-radius:10px;
      padding:1rem 1.1rem;
      display:flex; align-items:center; gap:.85rem;
    }
    .adm-stat-icon{
      width:38px; height:38px; border-radius:9px;
      display:flex; align-items:center; justify-content:center;
      font-size:16px; flex-shrink:0;
    }
    .adm-stat-value{ font-size:22px; font-weight:700; color: var(--adm-ink); line-height:1.1; }
    .adm-stat-label{ font-size:11.5px; color: var(--adm-soft); margin-top:2px; }

    /* ── Day schedule strip ──────────────────── */
    .day-pill{
      display:inline-flex; align-items:center; justify-content:center;
      width:30px; height:30px; border-radius:8px;
      font-size:10px; font-weight:700; letter-spacing:.4px;
      border:1px solid var(--adm-line);
      background: var(--adm-bg); color: var(--adm-muted);
    }
    .day-pill.today{ background: var(--adm-ink); color:#fff; border-color: var(--adm-ink); }
    .day-pill.has-post{ background:#EFF6FF; color:#1D4ED8; border-color:#BFDBFE; }
    .day-pill.has-sched{ background:#FEFCE8; color:#B45309; border-color:#FDE68A; }
    .day-pill.sunday{ background:#FEF2F2; color:#DC2626; font-style:italic; }

    /* ── Section labels ─────────────────────── */
    .adm-section-label{
      font-size:11px; font-weight:700; letter-spacing:1px; text-transform:uppercase;
      color: var(--adm-soft);
      display:flex; align-items:center; gap:8px;
      margin-bottom:.9rem;
    }

    /* ── Media cards ─────────────────────────── */
    .adm-media-card{
      background: var(--adm-surface);
      border:1px solid var(--adm-line);
      border-radius:10px;
      overflow:hidden;
      display:flex; flex-direction:column;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }
    .adm-media-card:hover{
      border-color: var(--adm-ink);
      box-shadow: 0 10px 26px -16px rgba(11,11,11,0.25);
      transform: translateY(-2px);
    }
    .adm-media-card.is-scheduled{ border-color:#DDD6FE; }
    .adm-media-thumb{
      width:100%; height:190px; object-fit:cover; display:block;
      background: var(--adm-bg);
    }
    .adm-media-body{ padding:1rem 1.15rem 1.15rem; display:flex; flex-direction:column; flex:1; }
    .adm-media-title{ font-size:13.5px; font-weight:600; color: var(--adm-ink); margin-bottom:.25rem; line-height:1.4;
      display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
    .adm-media-tags{ font-size:11.5px; color: var(--adm-soft); margin-bottom:.6rem;
      display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden; }

    .adm-badge{
      position:absolute; padding:.35rem .7rem; border-radius:999px;
      font-size:10.5px; font-weight:700; letter-spacing:.2px;
    }
    .adm-badge.tl{ top:.7rem; left:.7rem; }
    .adm-badge.tr{ top:.7rem; right:.7rem; }
    .badge-active{ background:#16A34A; color:#fff; }
    .badge-inactive{ background: var(--adm-ink); color:#fff; opacity:.8; }
    .badge-day{ background: var(--adm-ink); color:#fff; }
    .badge-sched{ background:#7C3AED; color:#fff; }
    .badge-sched-day{ background: rgba(255,255,255,0.92); color:#5B21B6; }

    .badge-ok{ background:#ECFDF3; color:#16A34A; }
    .badge-warn{ background:#FFFBEB; color:#B45309; }
    .badge-expired{ background:#FEF2F2; color:#DC2626; }

    .life-bar-track{ height:5px; border-radius:99px; background:var(--adm-line); overflow:hidden; margin-top:6px; }
    .life-bar-fill{ height:100%; border-radius:99px; transition:width .4s ease; }

    .scheduled-overlay{
      position:absolute; inset:0;
      background: rgba(11,11,11,0.55);
      display:flex; align-items:center; justify-content:center;
      backdrop-filter: blur(2px);
      color:#fff; text-align:center;
    }

    /* ── Empty state ─────────────────────────── */
    .adm-empty{
      border:1px dashed var(--adm-line); border-radius:10px;
      padding:3rem 1.5rem; text-align:center; color: var(--adm-soft);
      grid-column: 1 / -1;
    }

    /* ── Modal ───────────────────────────────── */
    .modal {
      display: none;
      position: fixed;
      z-index: 50;
      left: 0; top: 0;
      width: 100%; height: 100%;
      background-color: rgba(11, 11, 11, 0.45);
      animation: fadeIn 0.2s ease;
    }
    .modal.active {
      display: flex;
      align-items: center;
      justify-content: center;
    }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes slideUp { from { transform: translateY(16px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .modal-content {
      animation: slideUp 0.25s ease;
      background: var(--adm-surface);
      border:1px solid var(--adm-line);
      border-radius:14px;
      max-height:90vh;
      overflow-y:auto;
    }
    .adm-field-label{ font-size:12.5px; font-weight:600; color: var(--adm-ink); margin-bottom:.5rem; display:flex; align-items:center; gap:6px; }
    .adm-field-hint{ font-size:11.5px; color: var(--adm-muted); margin-top:.4rem; }
    .adm-input, .adm-textarea{
      width:100%; padding:.75rem 1rem; border-radius:9px;
      border:1px solid var(--adm-line); background: var(--adm-bg);
      font-size:13.5px; color: var(--adm-ink);
      transition: border-color .2s ease, background .2s ease;
      font-family:'Inter', sans-serif;
    }
    .adm-textarea{ resize:vertical; }
    .adm-input:focus, .adm-textarea:focus{ outline:none; border-color: var(--adm-ink); background: var(--adm-surface); }
    .adm-info-box{
      padding:.85rem 1rem; border-radius:9px;
      background:#F5F3FF; border:1px solid #DDD6FE; color:#5B21B6;
      font-size:12px; display:flex; align-items:flex-start; gap:8px;
    }
    .adm-error-text{ font-size:11.5px; color:#DC2626; margin-top:.4rem; }

    @keyframes adm-fade{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }
    .adm-fade{ animation: adm-fade .4s ease both; }
    @media (prefers-reduced-motion: reduce){ .adm-fade{ animation:none; } }
  </style>
</head>
<body class="min-h-screen">

  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">

    <!-- Header -->
    <div class="mb-6 adm-fade flex flex-col lg:flex-row lg:items-start lg:justify-between gap-5">
      <div>
        <a href="<?= BASE_URL ?>home-setting" class="adm-back">
          <i class="fas fa-arrow-left"></i>
          <span>Back to Dashboard</span>
        </a>
        <div class="adm-eyebrow mb-2">Home Settings</div>
        <h1 class="adm-title">Ads Content</h1>
        <p class="adm-subtitle mt-1">Posts run <strong>Mon – Sat</strong> · 7-day lifespan · Minimum 3 active posts · Schedule ahead anytime</p>
      </div>

      <div class="flex flex-col items-start lg:items-end gap-3">
        <!-- Weekly schedule strip -->
        <div class="flex items-center gap-1.5">
          <?php for ($d = 1; $d <= 6; $d++):
              $is_today  = ($today_dow === $d);
              $has_posts = isset($posts_by_dow[$d]);

              $active_on_day_res = $conn->query("SELECT COUNT(*) AS cnt FROM ads_content WHERE is_active=1 AND day_of_week=$d");
              $active_on_day = $active_on_day_res->fetch_assoc()['cnt'];
              $sched_on_day_res  = $conn->query("SELECT COUNT(*) AS cnt FROM ads_content WHERE is_active=0 AND day_of_week=$d AND scheduled_date >= CURDATE()");
              $sched_on_day  = $sched_on_day_res->fetch_assoc()['cnt'];

              if ($is_today)          $cls = 'today';
              elseif ($active_on_day) $cls = 'has-post';
              elseif ($sched_on_day)  $cls = 'has-sched';
              else                    $cls = '';

              $tip = $day_labels[$d];
              if ($active_on_day) $tip .= " ($active_on_day active)";
              if ($sched_on_day)  $tip .= " ($sched_on_day scheduled)";
          ?>
          <div class="flex flex-col items-center gap-1">
            <div class="day-pill <?php echo $cls; ?>" title="<?php echo $tip; ?>">
              <?php echo $dow_map[$d]; ?>
            </div>
          </div>
          <?php endfor; ?>
          <!-- Sunday — blocked -->
          <div class="flex flex-col items-center gap-1">
            <div class="day-pill sunday" title="Sunday — posting disabled">Sun</div>
          </div>
        </div>

        <button onclick="openModal('adsModal')" class="adm-btn">
          <i class="fas fa-calendar-check"></i>
          <span>Schedule / Add Post</span>
        </button>
      </div>
    </div>

    <!-- Stats row -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6 adm-fade">
      <div class="adm-stat">
        <div class="adm-stat-icon" style="background:#F5F5F5; color:var(--adm-ink);"><i class="fas fa-images"></i></div>
        <div>
          <p class="adm-stat-value"><?php echo $stat_total; ?></p>
          <p class="adm-stat-label">Total Posts</p>
        </div>
      </div>
      <div class="adm-stat">
        <div class="adm-stat-icon" style="background:#ECFDF3; color:#16A34A;"><i class="fas fa-circle-check"></i></div>
        <div>
          <p class="adm-stat-value"><?php echo $stat_active; ?></p>
          <p class="adm-stat-label">Active Posts</p>
        </div>
      </div>
      <div class="adm-stat">
        <div class="adm-stat-icon" style="background:#F5F3FF; color:#7C3AED;"><i class="fas fa-calendar-check"></i></div>
        <div>
          <p class="adm-stat-value"><?php echo $stat_scheduled; ?></p>
          <p class="adm-stat-label">Scheduled</p>
        </div>
      </div>
      <div class="adm-stat">
        <div class="adm-stat-icon" style="background:#FFFBEB; color:#B45309;"><i class="fas fa-clock"></i></div>
        <div>
          <p class="adm-stat-value"><?php echo $stat_expiring; ?></p>
          <p class="adm-stat-label">Expiring Soon</p>
        </div>
      </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($success_message)): ?>
      <div class="adm-alert is-success mb-5 adm-fade">
        <i class="fas fa-circle-check mt-0.5"></i>
        <p><?php echo htmlspecialchars($success_message); ?></p>
      </div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
      <div class="adm-alert is-error mb-5 adm-fade">
        <i class="fas fa-triangle-exclamation mt-0.5"></i>
        <p><?php echo htmlspecialchars($error_message); ?></p>
      </div>
    <?php endif; ?>

    <!-- Section: Scheduled (upcoming) -->
    <?php
    $sched_items = $conn->query("SELECT * FROM ads_content WHERE is_active = 0 AND scheduled_date > CURDATE() ORDER BY scheduled_date ASC");
    if ($sched_items && $sched_items->num_rows > 0):
    ?>
    <div class="mb-8 adm-fade">
      <div class="adm-section-label"><i class="fas fa-calendar-check" style="color:#7C3AED;"></i> Upcoming Scheduled Posts</div>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php while ($item = $sched_items->fetch_assoc()):
            $filename     = basename($item['filepath']);
            $display_path = CLIENT_ASSET . "/images/ads_content/" . $filename;
            $sched_ts     = strtotime($item['scheduled_date']);
            $days_until   = (int) ceil(($sched_ts - strtotime('today')) / 86400);
            $sched_dow_label = $day_labels[$item['day_of_week']] ?? '—';
        ?>
        <div class="adm-media-card is-scheduled">
          <div class="relative">
            <img src="<?php echo htmlspecialchars($display_path); ?>" alt="Scheduled Ad" class="adm-media-thumb" style="opacity:.6;" />
            <div class="scheduled-overlay">
              <div>
                <i class="fas fa-calendar-check text-2xl mb-1 block"></i>
                <p class="font-bold text-sm">Goes live <?php echo date('M d', $sched_ts); ?></p>
                <p class="text-xs opacity-80"><?php echo $days_until === 1 ? 'Tomorrow' : "In $days_until days"; ?></p>
              </div>
            </div>
            <span class="adm-badge tl badge-sched">Scheduled</span>
            <span class="adm-badge tr badge-sched-day"><?php echo $sched_dow_label; ?></span>
          </div>
          <div class="adm-media-body">
            <p class="adm-media-title"><?php echo htmlspecialchars($item['caption']); ?></p>
            <?php if (!empty($item['hashtags'])): ?>
              <p class="adm-media-tags" style="color:var(--adm-ink);"><?php echo htmlspecialchars($item['hashtags']); ?></p>
            <?php endif; ?>
            <div class="mt-auto">
              <div class="adm-info-box">
                <i class="fas fa-clock mt-0.5"></i>
                <span>
                  Activates on <strong><?php echo date('l, M d, Y', $sched_ts); ?></strong><br>
                  Expires <strong><?php echo date('M d, Y', strtotime('+7 days', $sched_ts)); ?></strong>
                </span>
              </div>
            </div>
            <div class="flex items-center justify-between mt-4 pt-3" style="border-top:1px solid var(--adm-line);">
              <span class="text-xs italic" style="color:var(--adm-muted);">Hidden until scheduled date</span>
              <div class="flex items-center gap-1">
                <a href="home_settings_ads_edit.php?id=<?php echo $item['id']; ?>" class="adm-btn-ghost is-edit" title="Edit post">
                  <i class="fas fa-pen"></i>
                </a>
                <form method="POST" onsubmit="return confirm('Cancel and delete this scheduled post?');" class="inline">
                  <input type="hidden" name="action" value="cancel_scheduled" />
                  <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                  <input type="hidden" name="filepath" value="<?php echo htmlspecialchars($item['filepath']); ?>" />
                  <button type="submit" class="adm-btn-ghost" title="Cancel scheduled post">
                    <i class="fas fa-trash-can"></i>
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>
        <?php endwhile; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Section: Active / Inactive posts -->
    <div class="adm-fade">
      <div class="adm-section-label"><i class="fas fa-circle-check" style="color:#16A34A;"></i> Active &amp; Inactive Posts</div>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php
        $ads_items = $conn->query("
            SELECT * FROM ads_content 
            WHERE is_active = 1
               OR (is_active = 0 AND (scheduled_date IS NULL OR scheduled_date <= CURDATE()))
            ORDER BY id DESC
        ");
        $card_index = 0;
        while ($item = $ads_items->fetch_assoc()):
            $filename     = basename($item['filepath']);
            $display_path = CLIENT_ASSET . "/images/ads_content/" . $filename;

            $posted_date = $item['posted_date'] ?? null;
            $days_alive  = $posted_date ? (int) floor((time() - strtotime($posted_date)) / 86400) : null;
            $days_left   = $posted_date ? max(0, 7 - $days_alive) : null;
            $life_pct    = $posted_date ? min(100, round(($days_alive / 7) * 100)) : 0;

            if ($days_left === null)    $badge_cls = 'badge-ok';
            elseif ($days_left <= 1)   $badge_cls = 'badge-expired';
            elseif ($days_left <= 3)   $badge_cls = 'badge-warn';
            else                       $badge_cls = 'badge-ok';

            if ($life_pct >= 85)       $bar_color = '#DC2626';
            elseif ($life_pct >= 57)   $bar_color = '#D97706';
            else                       $bar_color = '#16A34A';

            $posted_dow_label = ($item['day_of_week'] ?? null) ? ($day_labels[$item['day_of_week']] ?? '—') : '—';
            $card_index++;
        ?>
        <div class="adm-media-card">
          <div class="relative">
            <img src="<?php echo htmlspecialchars($display_path); ?>" alt="Ads" class="adm-media-thumb" />
            <span class="adm-badge tl <?php echo $item['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
              <?php echo $item['is_active'] ? '● Active' : '○ Inactive'; ?>
            </span>
            <?php if ($posted_dow_label !== '—'): ?>
              <span class="adm-badge tr badge-day"><?php echo $posted_dow_label; ?></span>
            <?php endif; ?>
          </div>

          <div class="adm-media-body">
            <p class="adm-media-title"><?php echo htmlspecialchars($item['caption']); ?></p>
            <?php if (!empty($item['hashtags'])): ?>
              <p class="adm-media-tags" style="color:var(--adm-ink);"><?php echo htmlspecialchars($item['hashtags']); ?></p>
            <?php endif; ?>

            <div class="mt-auto">
              <?php if ($posted_date): ?>
                <div class="flex items-center justify-between text-xs mb-1" style="color:var(--adm-soft);">
                  <span class="flex items-center gap-1">
                    <i class="fas fa-calendar"></i>
                    Posted: <?php echo date('M d, Y', strtotime($posted_date)); ?>
                  </span>
                  <span class="adm-chip <?php echo $badge_cls; ?>" style="padding:.2rem .55rem;">
                    <?php
                        if ($days_left === 0)     echo 'Expires today';
                        elseif ($days_left === 1) echo '1 day left';
                        else                      echo $days_left . ' days left';
                    ?>
                  </span>
                </div>
                <div class="life-bar-track">
                  <div class="life-bar-fill" style="width:<?php echo $life_pct; ?>%; background:<?php echo $bar_color; ?>;"></div>
                </div>
                <p class="text-xs mt-1" style="color:var(--adm-muted);">
                  Day <?php echo min($days_alive, 7); ?> of 7
                  <?php if ($days_alive > 7): ?>
                    &nbsp;<span style="color:#DC2626; font-weight:600;">(Overdue — protected by min. rule)</span>
                  <?php endif; ?>
                </p>
              <?php else: ?>
                <p class="text-xs italic" style="color:var(--adm-muted);">No post date recorded</p>
              <?php endif; ?>
            </div>

            <div class="flex items-center justify-between mt-4 pt-3" style="border-top:1px solid var(--adm-line);">
              <div class="flex items-center gap-2">
                <form method="POST" class="inline">
                  <input type="hidden" name="action" value="toggle_status" />
                  <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                  <input type="hidden" name="current_status" value="<?php echo $item['is_active']; ?>" />
                  <button type="submit" class="adm-chip <?php echo $item['is_active'] ? 'is-active' : ''; ?>">
                    <i class="fas <?php echo $item['is_active'] ? 'fa-eye' : 'fa-eye-slash'; ?>"></i>
                    <?php echo $item['is_active'] ? 'Active' : 'Inactive'; ?>
                  </button>
                </form>

                <?php if ($item['is_active'] && $stat_active > 3): ?>
                <form method="POST" onsubmit="return confirm('Mark this post as expired?');" class="inline">
                  <input type="hidden" name="action" value="force_expire" />
                  <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                  <button type="submit" class="adm-chip is-warn" title="Force expire this post">
                    <i class="fas fa-clock"></i>
                    Expire
                  </button>
                </form>
                <?php endif; ?>
              </div>

              <div class="flex items-center gap-1">
                <a href="home_settings_ads_edit.php?id=<?php echo $item['id']; ?>" class="adm-btn-ghost is-edit" title="Edit post">
                  <i class="fas fa-pen"></i>
                </a>
                <form method="POST" onsubmit="return confirmDelete(<?php echo $stat_active; ?>);" class="inline">
                  <input type="hidden" name="action" value="delete" />
                  <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                  <input type="hidden" name="filepath" value="<?php echo htmlspecialchars($item['filepath']); ?>" />
                  <button type="submit"
                    class="adm-btn-ghost <?php echo ($stat_active <= 3 && $item['is_active']) ? 'is-disabled' : ''; ?>"
                    title="<?php echo ($stat_active <= 3 && $item['is_active']) ? 'Cannot delete — minimum 3 active posts required' : 'Delete post'; ?>"
                    <?php echo ($stat_active <= 3 && $item['is_active']) ? 'disabled' : ''; ?>>
                    <i class="fas fa-trash-can"></i>
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>
        <?php endwhile; ?>

        <?php if ($card_index === 0): ?>
        <div class="adm-empty">
          <i class="fas fa-images text-2xl mb-3" style="color:var(--adm-muted);"></i>
          <p class="text-sm font-medium" style="color:var(--adm-ink);">No posts yet</p>
          <p class="text-xs mt-1">Add your first post using the button above.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /max-w -->


  <!-- Upload / Schedule Modal -->
  <div id="adsModal" class="modal">
    <div class="modal-content max-w-md w-full mx-4 p-6">
      <div class="flex items-center justify-between mb-5">
        <div>
          <h3 class="text-[15px] font-semibold" style="color:var(--adm-ink);">Schedule a Post</h3>
          <p class="adm-field-hint">Pick any Mon – Sat · Post lives for <strong>7 days</strong> from scheduled date</p>
        </div>
        <button onclick="closeModal('adsModal')" class="adm-btn-ghost">
          <i class="fas fa-xmark"></i>
        </button>
      </div>

      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_ads_content" />
        <div class="space-y-4">

          <!-- Scheduled Date Picker -->
          <div>
            <label class="adm-field-label"><i class="fas fa-calendar-check"></i>Scheduled Date <span style="color:#DC2626;">*</span></label>
            <input type="date" name="scheduled_date" id="scheduledDateInput" required
              min="<?php echo date('Y-m-d'); ?>"
              class="adm-input"
              onchange="updateSchedulePreview(this.value)" />
            <p id="scheduleNote" class="adm-field-hint">Choose a Monday – Saturday date.</p>
            <p id="scheduleError" class="adm-error-text hidden">Sundays are not allowed. Please pick Mon – Sat.</p>
          </div>

          <!-- Caption -->
          <div>
            <label class="adm-field-label"><i class="fas fa-align-left"></i>Caption</label>
            <textarea name="caption" required rows="3" class="adm-textarea" placeholder="Write your post caption here..."></textarea>
          </div>

          <!-- Hashtags -->
          <div>
            <label class="adm-field-label"><i class="fas fa-hashtag"></i>Hashtags</label>
            <input type="text" name="hashtags" class="adm-input" placeholder="#design #realiving #modular" />
            <p class="adm-field-hint">Separate with spaces</p>
          </div>

          <!-- Image upload -->
          <div>
            <label class="adm-field-label"><i class="fas fa-image"></i>Image <span style="color:var(--adm-muted); font-weight:400;">(auto-converted to WebP)</span></label>
            <input type="file" id="adsImageInput" name="image" accept="image/*" required class="adm-input" onchange="previewImage(event, 'adsPreview')" />
            <p class="adm-field-hint">JPG · PNG · GIF · WebP</p>
          </div>

          <!-- Preview -->
          <div id="adsPreview" class="hidden">
            <label class="adm-field-label">Preview</label>
            <div class="relative rounded-lg overflow-hidden" style="border:1px solid var(--adm-line);">
              <img id="adsPreviewImage" src="" alt="Preview" class="w-full h-48 object-cover" />
              <button type="button" onclick="clearPreview('adsImageInput','adsPreview')" class="absolute top-2 right-2 adm-btn-ghost" style="background:#fff; border:1px solid var(--adm-line);">
                <i class="fas fa-xmark"></i>
              </button>
            </div>
          </div>

          <!-- Dynamic schedule info box -->
          <div id="scheduleInfoBox" class="adm-info-box hidden">
            <i class="fas fa-circle-info mt-0.5"></i>
            <span id="scheduleInfoText"></span>
          </div>

          <button type="submit" id="submitBtn" class="adm-btn adm-btn-block">
            <i class="fas fa-calendar-check"></i>
            <span id="submitBtnText">Schedule Post</span>
          </button>
        </div>
      </form>
    </div>
  </div>


  <script>
    const today = new Date();
    today.setHours(0,0,0,0);
    const todayStr = '<?php echo date('Y-m-d'); ?>';

    const dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

    function updateSchedulePreview(val) {
      const errorEl = document.getElementById('scheduleError');
      const noteEl  = document.getElementById('scheduleNote');
      const infoBox = document.getElementById('scheduleInfoBox');
      const infoTxt = document.getElementById('scheduleInfoText');
      const btnTxt  = document.getElementById('submitBtnText');

      if (!val) {
        infoBox.classList.add('hidden');
        errorEl.classList.add('hidden');
        return;
      }

      // Check if Sunday (0)
      const parts = val.split('-');
      const d = new Date(parts[0], parts[1]-1, parts[2]);
      const dow = d.getDay(); // 0=Sun

      if (dow === 0) {
        errorEl.classList.remove('hidden');
        noteEl.classList.add('hidden');
        infoBox.classList.add('hidden');
        document.getElementById('scheduledDateInput').value = '';
        return;
      }

      errorEl.classList.add('hidden');
      noteEl.classList.remove('hidden');

      const isToday = (val === todayStr);
      const expiry  = new Date(d);
      expiry.setDate(expiry.getDate() + 7);
      const expiryStr = expiry.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      const dayName   = dayNames[dow];

      if (isToday) {
        infoTxt.innerHTML = `Post will <strong>go live immediately today (${dayName})</strong> and auto-expire on <strong>${expiryStr}</strong>.`;
        btnTxt.textContent = 'Publish Now';
      } else {
        const diff = Math.ceil((d - today) / 86400000);
        infoTxt.innerHTML = `Post will be <strong>hidden until ${dayName}, ${val}</strong> (in ${diff} day${diff>1?'s':''}), then automatically go live and expire on <strong>${expiryStr}</strong>.`;
        btnTxt.textContent = 'Schedule Post';
      }

      infoBox.classList.remove('hidden');
    }

    function openModal(id)  { document.getElementById(id).classList.add('active'); }
    function closeModal(id) {
      document.getElementById(id).classList.remove('active');
      const form = document.querySelector('#' + id + ' form');
      if (form) form.reset();
      clearPreview('adsImageInput', 'adsPreview');
      document.getElementById('scheduleInfoBox').classList.add('hidden');
      document.getElementById('scheduleError').classList.add('hidden');
      document.getElementById('submitBtnText').textContent = 'Schedule Post';
    }
    function previewImage(event, previewId) {
      const file = event.target.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = e => {
        document.getElementById(previewId + 'Image').src = e.target.result;
        document.getElementById(previewId).classList.remove('hidden');
      };
      reader.readAsDataURL(file);
    }
    function clearPreview(inputId, previewId) {
      document.getElementById(inputId).value = '';
      document.getElementById(previewId).classList.add('hidden');
      document.getElementById(previewId + 'Image').src = '';
    }
    function confirmDelete(activeCount) {
      if (activeCount <= 3) {
        alert('Cannot delete — minimum of 3 active posts must be kept.');
        return false;
      }
      return confirm('Are you sure you want to permanently delete this post?');
    }
    window.onclick = e => {
      if (e.target.classList.contains('modal')) closeModal(e.target.id);
    };
  </script>

</body>
</html>
<?php $conn->close(); ?>