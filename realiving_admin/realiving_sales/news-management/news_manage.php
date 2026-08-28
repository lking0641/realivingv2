<?php
//news_manage.php
include $includes ['mainbody'];

// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales']);

// Function to convert image to WebP
function convertToWebP($source, $destination, $quality = 90) {
    $info = @getimagesize($source);

    if ($info === false) {
        error_log("convertToWebP: not a valid image - $source");
        return false;
    }

    switch ($info['mime']) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source);
            // preserve transparency instead of turning it black
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($source);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($source);
            break;
        default:
            error_log("convertToWebP: unsupported mime type - " . $info['mime']);
            return false;
    }

    if (!$image) {
        error_log("convertToWebP: imagecreate failed for $source");
        return false;
    }

    $success = imagewebp($image, $destination, $quality);
    imagedestroy($image);

    if (!$success || !file_exists($destination)) {
        error_log("convertToWebP: imagewebp failed to write $destination");
        return false;
    }

    return true;
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $result = $conn->query("SELECT image, sub_images FROM news WHERE id = $id");
    if ($row = $result->fetch_assoc()) {
        $image_file = ROOT_PATH . "realiving_user/" . $row['image'];
        if (file_exists($image_file)) {
            unlink($image_file);
        }

        $sub_images = json_decode($row['sub_images'] ?? '[]', true) ?: [];
        foreach ($sub_images as $sub_path) {
            $sub_file = ROOT_PATH . "realiving_user/" . $sub_path;
            if (file_exists($sub_file)) {
                unlink($sub_file);
            }
        }
    }
    $conn->query("DELETE FROM news WHERE id = $id");
    header("Location: " . BASE_URL . "news-manage");
    exit();
}

// Handle add/edit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'] ?? null;
    $title = $_POST['title'];
    $category = $_POST['category'];
    $description = $_POST['description'];
    $content = $_POST['content'];
    $keywords = $_POST['keywords'];
    $author = $_POST['author'];
    $featured = isset($_POST['featured']) ? 1 : 0;
    $status = $_POST['status'];
    
    // Handle image upload
    $image_path = $_POST['existing_image'] ?? '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $target_dir = ROOT_PATH . "realiving_user/images/news/";
        
        if (!file_exists($target_dir)) {
            if (!mkdir($target_dir, 0777, true) && !is_dir($target_dir)) {
                die("Upload failed: could not create folder $target_dir. Check folder permissions.");
            }
        }

        if (!is_writable($target_dir)) {
            die("Upload failed: folder $target_dir is not writable. Check folder permissions (chmod 755/777).");
        }
        
        $new_filename = 'news_' . time() . '_' . rand(1000, 9999) . '.webp';
        $target_file = $target_dir . $new_filename;
        
        if (convertToWebP($_FILES['image']['tmp_name'], $target_file, 90)) {
            $image_path = 'images/news/' . $new_filename;
            
            if ($id && !empty($_POST['existing_image'])) {
                $old_image = ROOT_PATH . "realiving_user/" . $_POST['existing_image'];
                if (file_exists($old_image)) {
                    unlink($old_image);
                }
            }
        } else {
            die("Upload failed: could not convert image to WebP. Check the PHP error log for details.");
        }
    }
    
    // Handle multiple sub-images upload
$sub_images = json_decode($_POST['existing_sub_images'] ?? '[]', true) ?: [];

// Get the original sub_images from DB (before this edit) so we know what to delete
$old_sub_images = [];
if ($id) {
    $old_result = $conn->query("SELECT sub_images FROM news WHERE id=" . intval($id));
    if ($old_row = $old_result->fetch_assoc()) {
        $old_sub_images = json_decode($old_row['sub_images'] ?? '[]', true) ?: [];
    }
}

// Delete any old sub-image files that are no longer in the kept list
$removed_sub_images = array_diff($old_sub_images, $sub_images);
foreach ($removed_sub_images as $removed_path) {
    $removed_file = ROOT_PATH . "realiving_user/" . $removed_path;
    if (file_exists($removed_file)) {
        unlink($removed_file);
    }
}

// Handle newly uploaded sub-images
if (!empty($_FILES['sub_images']['name'][0])) {
    $target_dir = ROOT_PATH . "realiving_user/images/news/";
    foreach ($_FILES['sub_images']['tmp_name'] as $index => $tmp_name) {
        if ($_FILES['sub_images']['error'][$index] == 0) {
            $sub_filename = 'news_sub_' . time() . '_' . rand(1000, 9999) . '_' . $index . '.webp';
            $sub_target = $target_dir . $sub_filename;
            if (convertToWebP($tmp_name, $sub_target, 90)) {
                $sub_images[] = 'images/news/' . $sub_filename;
            }
        }
    }
}
$sub_images_json = json_encode($sub_images);

    if ($id) {
        $stmt = $conn->prepare("UPDATE news SET title=?, category=?, description=?, content=?, image=?, keywords=?, author=?, featured=?, status=?, sub_images=? WHERE id=?");
        $stmt->bind_param("ssssssssssi", $title, $category, $description, $content, $image_path, $keywords, $author, $featured, $status, $sub_images_json, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO news (title, category, description, content, image, keywords, author, featured, status, sub_images) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssss", $title, $category, $description, $content, $image_path, $keywords, $author, $featured, $status, $sub_images_json);
    }
    
    $stmt->execute();
    header("Location: " . BASE_URL . "news-manage");
    exit();
}

// Get all news
$news_list = $conn->query("SELECT * FROM news ORDER BY date_uploaded DESC");
$news_count = $news_list->num_rows;
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage News - RealLiving</title>
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

    .adm-header-row {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }

    .adm-header-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
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

    /* ── Buttons ────────────────────────────── */
    .adm-btn {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 10px 18px;
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
      color: var(--adm-ink);
    }

    .adm-btn-outline:hover {
      border-color: var(--adm-ink);
    }

    .adm-btn-danger {
      background: var(--adm-surface);
      border-color: #f3caca;
      color: #9b1c1c;
    }

    .adm-btn-danger:hover {
      background: #fdf1f1;
      border-color: #9b1c1c;
    }

    .adm-btn-sm {
      padding: 6px 12px;
      font-size: 11.5px;
    }

    /* ── Panel / table ──────────────────────── */
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
    }

    .adm-panel-head h2 {
      font-size: 15px;
      font-weight: 700;
      color: var(--adm-ink);
      display: flex;
      align-items: center;
      gap: 9px;
    }

    .adm-table-wrap {
      overflow-x: auto;
    }

    .adm-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }

    .adm-table thead th {
      padding: 11px 16px;
      text-align: left;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
      color: var(--adm-soft);
      background: var(--adm-surface2);
      border-bottom: 1px solid var(--adm-line);
      white-space: nowrap;
    }

    .adm-table tbody tr {
      border-bottom: 1px solid var(--adm-line);
      transition: background .15s ease;
    }

    .adm-table tbody tr:last-child {
      border-bottom: none;
    }

    .adm-table tbody tr:hover {
      background: var(--adm-surface2);
    }

    .adm-table td {
      padding: 12px 16px;
      vertical-align: middle;
      color: var(--adm-ink);
    }

    .td-name {
      font-weight: 600;
      font-size: 13.5px;
    }

    .td-muted {
      color: var(--adm-soft);
    }

    .news-image {
      width: 90px;
      height: 56px;
      object-fit: cover;
      border-radius: 6px;
      border: 1px solid var(--adm-line);
      display: block;
    }

    .adm-badge {
      display: inline-flex;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 10.5px;
      font-weight: 700;
      letter-spacing: .3px;
      text-transform: uppercase;
      border: 1px solid var(--adm-line);
      background: var(--adm-surface2);
      color: var(--adm-soft);
    }

    .adm-badge.badge-featured {
      color: #7d5a00;
      background: #fff8e6;
      border-color: #f0e0b0;
      margin-left: 6px;
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

    .adm-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .adm-empty {
      text-align: center;
      padding: 20px;
      color: var(--adm-muted);
      font-size: 13px;
    }

    /* ── Modal ──────────────────────────────── */
    .adm-modal-bg {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(11, 11, 11, .5);
      z-index: 999;
      align-items: center;
      justify-content: center;
      backdrop-filter: blur(2px);
      padding: 20px;
    }

    .adm-modal-bg.open {
      display: flex;
    }

    .adm-modal-box {
      background: var(--adm-surface);
      border-radius: 12px;
      padding: 30px;
      max-width: 780px;
      width: 100%;
      max-height: 92vh;
      overflow-y: auto;
      box-shadow: 0 20px 60px rgba(11, 11, 11, .25);
      border: 1px solid var(--adm-line);
    }

    .adm-modal-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 22px;
      padding-bottom: 16px;
      border-bottom: 1px solid var(--adm-line);
    }

    .adm-modal-head h3 {
      font-size: 17px;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 9px;
      color: var(--adm-ink);
    }

    .adm-modal-close {
      background: none;
      border: none;
      font-size: 18px;
      color: var(--adm-soft);
      cursor: pointer;
      padding: 6px;
      border-radius: 6px;
      line-height: 1;
    }

    .adm-modal-close:hover {
      color: var(--adm-ink);
      background: var(--adm-surface2);
    }

    /* ── Form ───────────────────────────────── */
    .form-section {
      margin-bottom: 24px;
    }

    .form-section-title {
      font-size: 11.5px;
      font-weight: 700;
      color: var(--adm-soft);
      text-transform: uppercase;
      letter-spacing: .5px;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 7px;
      padding-bottom: 10px;
      border-bottom: 1px solid var(--adm-line);
    }

    .form-group {
      margin-bottom: 16px;
    }

    .form-group label {
      display: block;
      font-size: 11.5px;
      font-weight: 600;
      color: var(--adm-soft);
      text-transform: uppercase;
      letter-spacing: .4px;
      margin-bottom: 7px;
    }

    .form-control {
      width: 100%;
      padding: 10px 13px;
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      font-size: 13.5px;
      font-family: 'Inter', sans-serif;
      color: var(--adm-ink);
      background: var(--adm-surface2);
      transition: border-color .18s ease, background .18s ease;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--adm-ink);
      background: #fff;
    }

    textarea.form-control {
      resize: vertical;
    }

    input[type="file"].form-control {
      padding: 9px 12px;
      cursor: pointer;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    .form-help {
      font-size: 11.5px;
      color: var(--adm-muted);
      margin-top: 6px;
    }

    .form-check {
      display: flex;
      align-items: center;
      gap: 9px;
      font-size: 13px;
      color: var(--adm-ink);
      cursor: pointer;
      text-transform: none;
      letter-spacing: 0;
      font-weight: 500;
    }

    .form-check input {
      width: auto;
      accent-color: var(--adm-ink);
    }

    .current-image {
      max-width: 200px;
      margin-top: 12px;
      border-radius: 8px;
      border: 1px solid var(--adm-line);
      display: none;
    }

    .form-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      margin-top: 24px;
      padding-top: 18px;
      border-top: 1px solid var(--adm-line);
    }

    /* ── Sub images ─────────────────────────── */
    .sub-images-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 12px;
    }

    .sub-image-item {
      position: relative;
      width: 96px;
    }

    .sub-image-item img {
      width: 96px;
      height: 68px;
      object-fit: cover;
      border-radius: 6px;
      border: 1px solid var(--adm-line);
      display: block;
    }

    .sub-image-item .remove-sub {
      position: absolute;
      top: -7px;
      right: -7px;
      background: #9b1c1c;
      color: white;
      border: 2px solid var(--adm-surface);
      border-radius: 50%;
      width: 20px;
      height: 20px;
      cursor: pointer;
      font-size: 12px;
      line-height: 16px;
      text-align: center;
      padding: 0;
    }

    .sub-image-item .new-tag {
      font-size: 10px;
      display: block;
      text-align: center;
      margin-top: 3px;
      color: var(--adm-muted);
      font-weight: 600;
      letter-spacing: .3px;
      text-transform: uppercase;
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

    @media (max-width: 640px) {
      .form-row {
        grid-template-columns: 1fr;
      }

      .adm-header-row {
        flex-direction: column;
      }
    }
  </style>
</head>

<body class="min-h-screen flex flex-col">

  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">

    <!-- Header -->
    <div class="mb-8 adm-fade adm-header-row">
      <div>
        <div class="adm-eyebrow mb-2">Sales &amp; Marketing</div>
        <h1 class="adm-title">Manage News Articles</h1>
        <p class="adm-subtitle mt-1">Create, edit, and publish articles for the News page.</p>
      </div>
      <div class="adm-header-actions">
        <a href="news-dashboard" class="adm-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <button class="adm-btn adm-btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Add New Article</button>
      </div>
    </div>

    <!-- Articles Table -->
    <div class="adm-panel adm-fade">
      <div class="adm-panel-head">
        <h2><i class="fas fa-newspaper" style="color:var(--adm-soft);"></i> Articles</h2>
        <span class="td-muted" style="font-size:12.5px;"><?php echo $news_count; ?> total</span>
      </div>
      <div class="adm-table-wrap">
        <table class="adm-table">
          <thead>
            <tr>
              <th>Image</th>
              <th>Title</th>
              <th>Category</th>
              <th>Author</th>
              <th>Date</th>
              <th>Views</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($news_count > 0): ?>
              <?php while ($news = $news_list->fetch_assoc()): ?>
                <tr>
                  <td><img src="<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($news['image']); ?>" class="news-image" alt=""></td>
                  <td class="td-name">
                    <?php echo htmlspecialchars($news['title']); ?>
                    <?php if ($news['featured']): ?>
                      <span class="adm-badge badge-featured">Featured</span>
                    <?php endif; ?>
                  </td>
                  <td class="td-muted"><?php echo htmlspecialchars($news['category']); ?></td>
                  <td class="td-muted"><?php echo htmlspecialchars($news['author']); ?></td>
                  <td class="td-muted"><?php echo date('M d, Y', strtotime($news['date_uploaded'])); ?></td>
                  <td class="td-muted"><?php echo $news['views']; ?></td>
                  <td><span class="adm-badge badge-<?php echo $news['status']; ?>"><?php echo ucfirst($news['status']); ?></span></td>
                  <td>
                    <div class="adm-actions">
                      <button class="adm-btn adm-btn-outline adm-btn-sm" onclick='editNews(<?php echo json_encode($news); ?>)'><i class="fas fa-pen"></i> Edit</button>
                      <a href="?delete=<?php echo $news['id']; ?>" class="adm-btn adm-btn-danger adm-btn-sm" onclick="return confirm('Are you sure?')"><i class="fas fa-trash"></i> Delete</a>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="8" class="adm-empty">No articles yet. Create your first one!</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <!-- Add/Edit Modal -->
  <div id="newsModal" class="adm-modal-bg">
    <div class="adm-modal-box">
      <div class="adm-modal-head">
        <h3 id="modalTitle"><i class="fas fa-newspaper" style="color:var(--adm-soft);"></i> Add New Article</h3>
        <button class="adm-modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="id" id="newsId">
        <input type="hidden" name="existing_image" id="existingImage">

        <!-- Basic Info -->
        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-circle-info" style="color:var(--adm-soft);"></i> Basic Info</div>

          <div class="form-row">
            <div class="form-group">
              <label>Title <span style="color:#9b1c1c;">*</span></label>
              <input type="text" name="title" id="title" class="form-control" required>
            </div>
            <div class="form-group">
              <label>Category <span style="color:#9b1c1c;">*</span></label>
              <input type="text" name="category" id="category" class="form-control" placeholder="e.g., Design Tips, Company News" required>
            </div>
          </div>

          <div class="form-group">
            <label>Short Description <span style="color:#9b1c1c;">*</span> (for preview)</label>
            <textarea name="description" id="description" rows="3" class="form-control" required></textarea>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label>Full Content <span style="color:#9b1c1c;">*</span></label>
            <textarea name="content" id="content" rows="10" class="form-control" required></textarea>
          </div>
        </div>

        <!-- Media -->
        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-image" style="color:var(--adm-soft);"></i> Media</div>

          <div class="form-group">
            <label>Featured Image <span style="color:#9b1c1c;">*</span></label>
            <input type="file" name="image" id="image" accept="image/*" class="form-control">
            <p class="form-help">JPG/PNG will be converted to WebP. Recommended: 800x500px</p>
            <img id="currentImage" class="current-image">
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label>Sub Images (unlimited)</label>
            <input type="file" name="sub_images[]" id="subImages" accept="image/*" multiple class="form-control">
            <p class="form-help">You can select multiple images at once. JPG/PNG will be converted to WebP.</p>
            <input type="hidden" name="existing_sub_images" id="existingSubImages" value="[]">
            <div class="sub-images-grid" id="subImagesPreview"></div>
          </div>
        </div>

        <!-- Metadata -->
        <div class="form-section" style="margin-bottom:0;">
          <div class="form-section-title"><i class="fas fa-tags" style="color:var(--adm-soft);"></i> Metadata</div>

          <div class="form-row">
            <div class="form-group">
              <label>Author</label>
              <input type="text" name="author" id="author" value="Admin" class="form-control">
            </div>
            <div class="form-group">
              <label>Status</label>
              <select name="status" id="status" class="form-control">
                <option value="published">Published</option>
                <option value="draft">Draft</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label>Keywords (comma-separated)</label>
            <input type="text" name="keywords" id="keywords" class="form-control" placeholder="e.g., interior design, cabinets, modern">
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-check">
              <input type="checkbox" name="featured" id="featured"> Mark as Featured
            </label>
          </div>
        </div>

        <div class="form-actions">
          <button type="button" class="adm-btn adm-btn-outline" onclick="closeModal()">Cancel</button>
          <button type="submit" class="adm-btn adm-btn-primary"><i class="fas fa-check"></i> Save Article</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    const clientAsset = '<?php echo CLIENT_ASSET; ?>';
    let subImagesToKeep = [];

    function openModal() {
      document.getElementById('newsModal').classList.add('open');
      document.getElementById('modalTitle').innerHTML = '<i class="fas fa-newspaper" style="color:var(--adm-soft);"></i> Add New Article';
      document.getElementById('newsId').value = '';
      document.getElementById('title').value = '';
      document.getElementById('category').value = '';
      document.getElementById('description').value = '';
      document.getElementById('content').value = '';
      document.getElementById('keywords').value = '';
      document.getElementById('author').value = 'Admin';
      document.getElementById('status').value = 'published';
      document.getElementById('featured').checked = false;
      document.getElementById('existingImage').value = '';
      document.getElementById('currentImage').style.display = 'none';
      subImagesToKeep = [];
      document.getElementById('existingSubImages').value = '[]';
      document.getElementById('subImagesPreview').innerHTML = '';
    }

    function editNews(news) {
      document.getElementById('newsModal').classList.add('open');
      document.getElementById('modalTitle').innerHTML = '<i class="fas fa-pen" style="color:var(--adm-soft);"></i> Edit Article';
      document.getElementById('newsId').value = news.id;
      document.getElementById('title').value = news.title;
      document.getElementById('category').value = news.category;
      document.getElementById('description').value = news.description;
      document.getElementById('content').value = news.content;
      document.getElementById('keywords').value = news.keywords;
      document.getElementById('author').value = news.author;
      document.getElementById('status').value = news.status;
      document.getElementById('featured').checked = news.featured == 1;
      document.getElementById('existingImage').value = news.image;

      const currentImg = document.getElementById('currentImage');
      currentImg.src = clientAsset + '/' + news.image;
      currentImg.style.display = 'block';

      // Load existing sub images
      subImagesToKeep = news.sub_images ? JSON.parse(news.sub_images) : [];
      document.getElementById('existingSubImages').value = JSON.stringify(subImagesToKeep);
      renderSubImagePreviews();
    }

    function closeModal() {
      document.getElementById('newsModal').classList.remove('open');
    }

    function renderSubImagePreviews() {
      const preview = document.getElementById('subImagesPreview');
      preview.innerHTML = '';
      subImagesToKeep.forEach((path, index) => {
        const div = document.createElement('div');
        div.className = 'sub-image-item';
        div.innerHTML = `
    <img src="${clientAsset}/${path}" alt="sub">
    <button type="button" class="remove-sub" onclick="removeSubImage(${index})">×</button>
`;
        preview.appendChild(div);
      });
    }

    function removeSubImage(index) {
      subImagesToKeep.splice(index, 1);
      document.getElementById('existingSubImages').value = JSON.stringify(subImagesToKeep);
      renderSubImagePreviews();
    }

    document.getElementById('subImages').addEventListener('change', function () {
      const preview = document.getElementById('subImagesPreview');
      // Remove previously added new-file previews
      const newPreviews = preview.querySelectorAll('.new-preview');
      newPreviews.forEach(el => el.remove());

      Array.from(this.files).forEach((file) => {
        const reader = new FileReader();
        reader.onload = function (e) {
          const div = document.createElement('div');
          div.className = 'sub-image-item new-preview';
          div.innerHTML = `<img src="${e.target.result}" alt="new sub"><span class="new-tag">New</span>`;
          preview.appendChild(div);
        };
        reader.readAsDataURL(file);
      });
    });

    window.onclick = function (event) {
      const modal = document.getElementById('newsModal');
      if (event.target === modal) {
        closeModal();
      }
    }
  </script>
</body>

</html>