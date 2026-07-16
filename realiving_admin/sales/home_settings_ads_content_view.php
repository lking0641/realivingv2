<?php
// home_settings_ads_content_view.php
session_start();
include '../../connection/connection.php';
include '../design/mainbody.php';
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

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
            $file_path = "../../realiving_user/images/ads_content/" . basename($d['filepath']);
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
            $target_dir = "../../realiving_user/images/ads_content/";
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
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=published");
    } else {
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=scheduled&date=" . urlencode(date('M d, Y', strtotime($scheduled_date))));
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
    header("Location: " . $_SERVER['PHP_SELF'] . "?success=status");
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
    $file_to_delete = "../../realiving_user/images/ads_content/" . basename($filepath);
    if (file_exists($file_to_delete)) unlink($file_to_delete);
    header("Location: " . $_SERVER['PHP_SELF'] . "?success=deleted");
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
    header("Location: " . $_SERVER['PHP_SELF'] . "?success=expired");
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
    $file_to_delete = "../../realiving_user/images/ads_content/" . basename($filepath);
    if (file_exists($file_to_delete)) unlink($file_to_delete);
    header("Location: " . $_SERVER['PHP_SELF'] . "?success=cancelled");
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
    <title>Ads Content Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: { primary: "#4f46e5", secondary: "#4338ca" } } }
        };
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />
    <style>
        body { font-family: 'Inter', sans-serif; }

        .modal {
            display: none; position: fixed; z-index: 50;
            left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }
        .modal.active { display: flex; align-items: center; justify-content: center; }
        @keyframes fadeIn  { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; }
                             to   { transform: translateY(0);    opacity: 1; } }
        .modal-content { animation: slideUp 0.3s ease; }

        .life-bar-track {
            height: 5px; border-radius: 99px;
            background: #e5e7eb; overflow: hidden; margin-top: 6px;
        }
        .life-bar-fill {
            height: 100%; border-radius: 99px;
            transition: width 0.4s ease;
        }

        .day-pill {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 8px;
            font-size: 10px; font-weight: 700; letter-spacing: 0.5px;
        }
        .day-pill.today    { background: #4f46e5; color: #fff; }
        .day-pill.has-post { background: #dbeafe; color: #1d4ed8; border: 1.5px solid #93c5fd; }
        .day-pill.has-sched { background: #fef9c3; color: #b45309; border: 1.5px solid #fde68a; }
        .day-pill.empty    { background: #f3f4f6; color: #9ca3af; }
        .day-pill.sunday   { background: #fee2e2; color: #ef4444; font-style: italic; }

        .ads-card { transition: box-shadow 0.2s, transform 0.2s; }
        .ads-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.10); transform: translateY(-2px); }

        /* Scheduled card overlay */
        .scheduled-overlay {
            position: absolute; inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: flex; align-items: center; justify-content: center;
            backdrop-filter: blur(2px);
        }

        .badge-ok      { background: #d1fae5; color: #065f46; }
        .badge-warn    { background: #fef3c7; color: #92400e; }
        .badge-expired { background: #fee2e2; color: #991b1b; }
        .badge-sched   { background: #ede9fe; color: #5b21b6; }

        /* Date picker: disable Sundays visually via JS */
    </style>
</head>
<body class="bg-gray-50">
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- ── Header ── -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <a href="home_settings_dashboard.php" class="text-primary hover:text-secondary flex items-center gap-2 mb-3 text-sm">
                <i class="ri-arrow-left-line"></i> Back to Dashboard
            </a>
            <h1 class="text-3xl font-bold text-gray-800">Ads Content</h1>
            <p class="text-gray-500 text-sm mt-1">
                Posts run <strong>Mon – Sat</strong> · 7-day lifespan · Minimum 3 active posts · Schedule ahead anytime
            </p>
        </div>

        <div class="flex flex-col items-end gap-3">
            <!-- Weekly schedule strip -->
            <div class="flex items-center gap-1.5">
                <?php for ($d = 1; $d <= 6; $d++):
                    $is_today  = ($today_dow === $d);
                    $has_posts = isset($posts_by_dow[$d]);

                    // Check if it's scheduled (future) vs active
                    $active_on_day_res = $conn->query("SELECT COUNT(*) AS cnt FROM ads_content WHERE is_active=1 AND day_of_week=$d");
                    $active_on_day = $active_on_day_res->fetch_assoc()['cnt'];
                    $sched_on_day_res  = $conn->query("SELECT COUNT(*) AS cnt FROM ads_content WHERE is_active=0 AND day_of_week=$d AND scheduled_date >= CURDATE()");
                    $sched_on_day  = $sched_on_day_res->fetch_assoc()['cnt'];

                    if ($is_today)          $cls = 'today';
                    elseif ($active_on_day) $cls = 'has-post';
                    elseif ($sched_on_day)  $cls = 'has-sched';
                    else                    $cls = 'empty';

                    $tip = $day_labels[$d];
                    if ($active_on_day) $tip .= " ($active_on_day active)";
                    if ($sched_on_day)  $tip .= " ($sched_on_day scheduled)";
                ?>
                <div class="flex flex-col items-center gap-1">
                    <div class="day-pill <?php echo $cls; ?>" title="<?php echo $tip; ?>">
                        <?php echo $dow_map[$d]; ?>
                    </div>
                    <?php if ($active_on_day || $sched_on_day): ?>
                        <div class="w-1.5 h-1.5 rounded-full <?php echo $active_on_day ? 'bg-blue-400' : 'bg-yellow-400'; ?>"></div>
                    <?php else: ?>
                        <div class="w-1.5 h-1.5"></div>
                    <?php endif; ?>
                </div>
                <?php endfor; ?>
                <!-- Sunday — blocked -->
                <div class="flex flex-col items-center gap-1">
                    <div class="day-pill sunday" title="Sunday — posting disabled">Sun</div>
                    <div class="w-1.5 h-1.5 rounded-full bg-red-300"></div>
                </div>
            </div>

            <button onclick="openModal('adsModal')"
                class="bg-primary hover:bg-secondary text-white px-5 py-2.5 rounded-lg flex items-center gap-2 text-sm font-semibold transition-colors shadow-sm">
                <i class="ri-calendar-check-line"></i> Schedule / Add Post
            </button>
        </div>
    </div>

    <!-- ── Stats row ── -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-indigo-50 flex items-center justify-center">
                <i class="ri-image-line text-primary text-xl"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-800"><?php echo $stat_total; ?></p>
                <p class="text-xs text-gray-500">Total Posts</p>
            </div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-green-50 flex items-center justify-center">
                <i class="ri-checkbox-circle-line text-green-600 text-xl"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-800"><?php echo $stat_active; ?></p>
                <p class="text-xs text-gray-500">Active Posts</p>
            </div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
                <i class="ri-calendar-schedule-line text-violet-600 text-xl"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-800"><?php echo $stat_scheduled; ?></p>
                <p class="text-xs text-gray-500">Scheduled</p>
            </div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-yellow-50 flex items-center justify-center">
                <i class="ri-time-line text-yellow-600 text-xl"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-800"><?php echo $stat_expiring; ?></p>
                <p class="text-xs text-gray-500">Expiring Soon</p>
            </div>
        </div>
    </div>

    <!-- ── Alerts ── -->
    <?php if (!empty($success_message)): ?>
        <div class="mb-5 p-4 bg-green-50 border-l-4 border-green-500 rounded-lg flex items-center gap-3">
            <i class="ri-checkbox-circle-line text-green-500 text-xl"></i>
            <p class="text-sm text-green-700 font-medium"><?php echo htmlspecialchars($success_message); ?></p>
        </div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="mb-5 p-4 bg-red-50 border-l-4 border-red-500 rounded-lg flex items-center gap-3">
            <i class="ri-error-warning-line text-red-500 text-xl"></i>
            <p class="text-sm text-red-700 font-medium"><?php echo htmlspecialchars($error_message); ?></p>
        </div>
    <?php endif; ?>

    <!-- ── Section: Scheduled (upcoming) ── -->
    <?php
    $sched_items = $conn->query("SELECT * FROM ads_content WHERE is_active = 0 AND scheduled_date > CURDATE() ORDER BY scheduled_date ASC");
    if ($sched_items && $sched_items->num_rows > 0):
    ?>
    <div class="mb-8">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
            <i class="ri-calendar-schedule-line text-violet-500"></i> Upcoming Scheduled Posts
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php while ($item = $sched_items->fetch_assoc()):
            $filename     = basename($item['filepath']);
            $display_path = "../../realiving_user/images/ads_content/" . $filename;
            $sched_ts     = strtotime($item['scheduled_date']);
            $days_until   = (int) ceil(($sched_ts - strtotime('today')) / 86400);
            $sched_dow_label = $day_labels[$item['day_of_week']] ?? '—';
        ?>
        <div class="ads-card bg-white rounded-xl shadow-sm border-2 border-violet-200 overflow-hidden flex flex-col">
            <div class="relative">
                <img src="<?php echo htmlspecialchars($display_path); ?>"
                     alt="Scheduled Ad"
                     class="w-full h-48 object-cover opacity-60" />
                <div class="scheduled-overlay">
                    <div class="text-center text-white">
                        <i class="ri-calendar-schedule-line text-3xl mb-1 block"></i>
                        <p class="font-bold text-sm">Goes live <?php echo date('M d', $sched_ts); ?></p>
                        <p class="text-xs opacity-80"><?php echo $days_until === 1 ? 'Tomorrow' : "In $days_until days"; ?></p>
                    </div>
                </div>
                <span class="absolute top-3 left-3 px-2.5 py-1 rounded-full text-xs font-bold bg-violet-600 text-white">
                    ◷ Scheduled
                </span>
                <span class="absolute top-3 right-3 px-2.5 py-1 rounded-full text-xs font-bold bg-white/90 text-violet-700">
                    <?php echo $sched_dow_label; ?>
                </span>
            </div>
            <div class="p-4 flex flex-col flex-1">
                <p class="font-semibold text-gray-800 text-sm mb-1 line-clamp-2">
                    <?php echo htmlspecialchars($item['caption']); ?>
                </p>
                <?php if (!empty($item['hashtags'])): ?>
                <p class="text-xs text-primary mb-2 line-clamp-1">
                    <?php echo htmlspecialchars($item['hashtags']); ?>
                </p>
                <?php endif; ?>
                <div class="mt-auto">
                    <div class="p-2.5 bg-violet-50 rounded-lg text-xs text-violet-700 flex items-center gap-2">
                        <i class="ri-time-line"></i>
                        <span>
                            Activates on <strong><?php echo date('l, M d, Y', $sched_ts); ?></strong><br>
                            Expires <strong><?php echo date('M d, Y', strtotime('+7 days', $sched_ts)); ?></strong>
                        </span>
                    </div>
                </div>
                <div class="flex items-center justify-between mt-4 pt-3 border-t border-gray-100">
    <span class="text-xs text-gray-400 italic">Hidden from users until scheduled date</span>
    <div class="flex items-center gap-1">
        <a href="home_settings_ads_edit.php?id=<?php echo $item['id']; ?>"
           class="text-indigo-500 hover:text-indigo-700 p-2 rounded-lg transition-colors" title="Edit post">
            <i class="ri-edit-line text-lg"></i>
        </a>
        <form method="POST" onsubmit="return confirm('Cancel and delete this scheduled post?');" class="inline">
            <input type="hidden" name="action" value="cancel_scheduled" />
            <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
            <input type="hidden" name="filepath" value="<?php echo htmlspecialchars($item['filepath']); ?>" />
            <button type="submit" class="text-red-500 hover:text-red-700 p-2 rounded-lg transition-colors" title="Cancel scheduled post">
                <i class="ri-delete-bin-line text-lg"></i>
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

    <!-- ── Section: Active posts ── -->
    <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
        <i class="ri-checkbox-circle-line text-green-500"></i> Active &amp; Inactive Posts
    </h2>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php
        // Show: active posts + manually-inactive posts (scheduled_date is null OR already passed)
        // Exclude only: future-scheduled posts (those appear in the "Upcoming" section above)
        $ads_items = $conn->query("
            SELECT * FROM ads_content 
            WHERE is_active = 1
               OR (is_active = 0 AND (scheduled_date IS NULL OR scheduled_date <= CURDATE()))
            ORDER BY id DESC
        ");
        $card_index = 0;
        while ($item = $ads_items->fetch_assoc()):
            $filename     = basename($item['filepath']);
            $display_path = "../../realiving_user/images/ads_content/" . $filename;

            $posted_date = $item['posted_date'] ?? null;
            $days_alive  = $posted_date ? (int) floor((time() - strtotime($posted_date)) / 86400) : null;
            $days_left   = $posted_date ? max(0, 7 - $days_alive) : null;
            $life_pct    = $posted_date ? min(100, round(($days_alive / 7) * 100)) : 0;

            if ($days_left === null)    $badge_cls = 'badge-ok';
            elseif ($days_left <= 1)   $badge_cls = 'badge-expired';
            elseif ($days_left <= 3)   $badge_cls = 'badge-warn';
            else                       $badge_cls = 'badge-ok';

            if ($life_pct >= 85)       $bar_color = '#ef4444';
            elseif ($life_pct >= 57)   $bar_color = '#f59e0b';
            else                       $bar_color = '#10b981';

            $posted_dow_label = ($item['day_of_week'] ?? null) ? ($day_labels[$item['day_of_week']] ?? '—') : '—';
            $card_index++;
        ?>
        <div class="ads-card bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col">
            <div class="relative">
                <img src="<?php echo htmlspecialchars($display_path); ?>"
                     alt="Ads"
                     class="w-full h-48 object-cover" />
                <span class="absolute top-3 left-3 px-2.5 py-1 rounded-full text-xs font-bold
                    <?php echo $item['is_active'] ? 'bg-green-500 text-white' : 'bg-gray-500 text-white'; ?>">
                    <?php echo $item['is_active'] ? '● Active' : '○ Inactive'; ?>
                </span>
                <?php if ($posted_dow_label !== '—'): ?>
                <span class="absolute top-3 right-3 px-2.5 py-1 rounded-full text-xs font-bold bg-indigo-600 text-white">
                    <?php echo $posted_dow_label; ?>
                </span>
                <?php endif; ?>
            </div>

            <div class="p-4 flex flex-col flex-1">
                <p class="font-semibold text-gray-800 text-sm mb-1 line-clamp-2">
                    <?php echo htmlspecialchars($item['caption']); ?>
                </p>
                <?php if (!empty($item['hashtags'])): ?>
                <p class="text-xs text-primary mb-2 line-clamp-1">
                    <?php echo htmlspecialchars($item['hashtags']); ?>
                </p>
                <?php endif; ?>

                <div class="mt-auto">
                    <?php if ($posted_date): ?>
                    <div class="flex items-center justify-between text-xs text-gray-500 mb-1">
                        <span class="flex items-center gap-1">
                            <i class="ri-calendar-line"></i>
                            Posted: <?php echo date('M d, Y', strtotime($posted_date)); ?>
                        </span>
                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold <?php echo $badge_cls; ?>">
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
                    <p class="text-xs text-gray-400 mt-1">
                        Day <?php echo min($days_alive, 7); ?> of 7
                        <?php if ($days_alive > 7): ?>
                            &nbsp;<span class="text-red-400 font-semibold">(Overdue — protected by min. rule)</span>
                        <?php endif; ?>
                    </p>
                    <?php else: ?>
                    <p class="text-xs text-gray-400 italic">No post date recorded</p>
                    <?php endif; ?>
                </div>

                <div class="flex items-center justify-between mt-4 pt-3 border-t border-gray-100">
                    <div class="flex items-center gap-2">
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="toggle_status" />
                            <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                            <input type="hidden" name="current_status" value="<?php echo $item['is_active']; ?>" />
                            <button type="submit"
                                class="<?php echo $item['is_active'] ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors">
                                <?php echo $item['is_active'] ? '<i class="ri-eye-line mr-1"></i>Active' : '<i class="ri-eye-off-line mr-1"></i>Inactive'; ?>
                            </button>
                        </form>

                        <?php if ($item['is_active'] && $stat_active > 3): ?>
                        <form method="POST" onsubmit="return confirm('Mark this post as expired?');" class="inline">
                            <input type="hidden" name="action" value="force_expire" />
                            <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                            <button type="submit"
                                class="bg-yellow-50 text-yellow-700 hover:bg-yellow-100 px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors"
                                title="Force expire this post">
                                <i class="ri-time-line mr-1"></i>Expire
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center gap-1">
                    <a href="home_settings_ads_edit.php?id=<?php echo $item['id']; ?>"
                       class="text-indigo-500 hover:text-indigo-700 p-2 rounded-lg transition-colors" title="Edit post">
                        <i class="ri-edit-line text-lg"></i>
                    </a>
                    <form method="POST" onsubmit="return confirmDelete(<?php echo $stat_active; ?>);" class="inline">
                        <input type="hidden" name="action" value="delete" />
                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                        <input type="hidden" name="filepath" value="<?php echo htmlspecialchars($item['filepath']); ?>" />
                        <button type="submit"
                            class="<?php echo ($stat_active <= 3 && $item['is_active']) ? 'text-gray-300 cursor-not-allowed' : 'text-red-500 hover:text-red-700'; ?> p-2 rounded-lg transition-colors"
                            title="<?php echo ($stat_active <= 3 && $item['is_active']) ? 'Cannot delete — minimum 3 active posts required' : 'Delete post'; ?>"
                            <?php echo ($stat_active <= 3 && $item['is_active']) ? 'disabled' : ''; ?>>
                            <i class="ri-delete-bin-line text-lg"></i>
                        </button>
                    </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endwhile; ?>

        <?php if ($card_index === 0): ?>
        <div class="col-span-3 py-16 text-center text-gray-400">
            <i class="ri-image-line text-5xl mb-3 block opacity-30"></i>
            <p class="text-lg font-medium">No posts yet</p>
            <p class="text-sm mt-1">Add your first post using the button above.</p>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /max-w -->


<!-- ════ Upload / Schedule Modal ════ -->
<div id="adsModal" class="modal">
    <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h3 class="text-xl font-semibold text-gray-800">Schedule a Post</h3>
                <p class="text-xs text-gray-500 mt-0.5">Pick any Mon – Sat · Post lives for <strong>7 days</strong> from scheduled date</p>
            </div>
            <button onclick="closeModal('adsModal')" class="text-gray-400 hover:text-gray-600 transition-colors">
                <i class="ri-close-line text-2xl"></i>
            </button>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_ads_content" />
            <div class="space-y-4">

                <!-- Scheduled Date Picker -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">
                        <i class="ri-calendar-check-line text-primary mr-1"></i>Scheduled Date
                        <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="scheduled_date" id="scheduledDateInput" required
                        min="<?php echo date('Y-m-d'); ?>"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary transition"
                        onchange="updateSchedulePreview(this.value)" />
                    <p id="scheduleNote" class="text-xs text-gray-400 mt-1">Choose a Monday – Saturday date.</p>
                    <p id="scheduleError" class="text-xs text-red-500 mt-1 hidden">Sundays are not allowed. Please pick Mon – Sat.</p>
                </div>

                <!-- Caption -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">
                        <i class="ri-text text-primary mr-1"></i>Caption
                    </label>
                    <textarea name="caption" required rows="3"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary resize-none transition"
                        placeholder="Write your post caption here..."></textarea>
                </div>

                <!-- Hashtags -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">
                        <i class="ri-hashtag text-primary mr-1"></i>Hashtags
                    </label>
                    <input type="text" name="hashtags"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary transition"
                        placeholder="#design #realiving #modular" />
                    <p class="text-xs text-gray-400 mt-1">Separate with spaces</p>
                </div>

                <!-- Image upload -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">
                        <i class="ri-image-line text-primary mr-1"></i>Image
                        <span class="text-gray-400 font-normal">(auto-converted to WebP)</span>
                    </label>
                    <input type="file" id="adsImageInput" name="image" accept="image/*" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary transition"
                        onchange="previewImage(event, 'adsPreview')" />
                    <p class="text-xs text-gray-400 mt-1">JPG · PNG · GIF · WebP</p>
                </div>

                <!-- Preview -->
                <div id="adsPreview" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Preview</label>
                    <div class="relative rounded-xl overflow-hidden border-2 border-gray-200">
                        <img id="adsPreviewImage" src="" alt="Preview" class="w-full h-48 object-cover" />
                        <button type="button" onclick="clearPreview('adsImageInput','adsPreview')"
                            class="absolute top-2 right-2 bg-red-500 hover:bg-red-600 text-white rounded-full p-1.5 shadow-lg transition-colors">
                            <i class="ri-close-line"></i>
                        </button>
                    </div>
                </div>

                <!-- Dynamic schedule info box -->
                <div id="scheduleInfoBox" class="p-3 bg-indigo-50 border border-indigo-100 rounded-xl text-xs text-indigo-700 hidden">
                    <div class="flex items-start gap-2">
                        <i class="ri-information-line text-base mt-0.5 shrink-0"></i>
                        <span id="scheduleInfoText"></span>
                    </div>
                </div>

                <button type="submit" id="submitBtn"
                    class="w-full bg-primary hover:bg-secondary text-white py-3 rounded-xl font-semibold text-sm transition-colors flex items-center justify-center gap-2">
                    <i class="ri-calendar-check-line"></i> <span id="submitBtnText">Schedule Post</span>
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
    const btn     = document.getElementById('submitBtn');
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