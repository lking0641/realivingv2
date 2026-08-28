<?php
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
    $result = $conn->query("SELECT image_path FROM concept_carousel WHERE id = $id");
    if ($row = $result->fetch_assoc()) {
        $image_file = ROOT_PATH . "realiving_user/" . $row['image_path'];
        if (file_exists($image_file)) {
            unlink($image_file);
        }
    }
    $conn->query("DELETE FROM concept_carousel WHERE id = $id");
    header("Location: " . BASE_URL . "concept-manage-carousel");
    exit();
}

// Handle add
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['image'])) {
    if ($_FILES['image']['error'] == 0) {
        $target_dir = ROOT_PATH . "realiving_user/images/carousel/";
        
        // Create directory if not exists
        if (!file_exists($target_dir)) {
            if (!mkdir($target_dir, 0777, true) && !is_dir($target_dir)) {
                die("Upload failed: could not create folder $target_dir. Check folder permissions.");
            }
        }

        if (!is_writable($target_dir)) {
            die("Upload failed: folder $target_dir is not writable. Check folder permissions (chmod 755/777).");
        }
        
        $new_filename = 'carousel_' . time() . '_' . rand(1000, 9999) . '.webp';
        $target_file = $target_dir . $new_filename;
        
        // Convert to WebP
        if (convertToWebP($_FILES['image']['tmp_name'], $target_file, 90)) {
            $image_path = 'images/carousel/' . $new_filename;
            $display_order = intval($_POST['display_order']);
            
            $stmt = $conn->prepare("INSERT INTO concept_carousel (image_path, display_order) VALUES (?, ?)");
            $stmt->bind_param("si", $image_path, $display_order);
            $stmt->execute();
        } else {
            die("Upload failed: could not convert image to WebP. Check the PHP error log for details.");
        }
    } else {
        die("Upload failed: file upload error code " . $_FILES['image']['error']);
    }
    header("Location: " . BASE_URL . "concept-manage-carousel");
    exit();
}

// Handle reorder
if (isset($_POST['update_order'])) {
    foreach ($_POST['order'] as $id => $order) {
        $id = intval($id);
        $order = intval($order);
        $conn->query("UPDATE concept_carousel SET display_order = $order WHERE id = $id");
    }
    header("Location: " . BASE_URL . "concept-manage-carousel");
    exit();
}

// Get all carousel images
$carousel_images = $conn->query("SELECT * FROM concept_carousel ORDER BY display_order ASC");
$carousel_count = $carousel_images->num_rows;
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Carousel - RealLiving</title>
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

    /* ── Toolbar ────────────────────────────── */
    .adm-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      padding: 14px 18px;
      margin-bottom: 20px;
      gap: 12px;
      flex-wrap: wrap;
    }

    .adm-toolbar-info {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 12.5px;
      color: var(--adm-soft);
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

    .adm-btn:disabled {
      opacity: .5;
      cursor: not-allowed;
    }

    /* ── Carousel grid ──────────────────────── */
    .carousel-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: 16px;
    }

    .carousel-item {
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      overflow: hidden;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }

    .carousel-item:hover {
      border-color: var(--adm-ink);
      box-shadow: 0 10px 26px -16px rgba(11, 11, 11, 0.25);
      transform: translateY(-2px);
    }

    .carousel-image-wrap {
      position: relative;
      background: var(--adm-surface2);
    }

    .carousel-image {
      width: 100%;
      height: 170px;
      object-fit: cover;
      display: block;
    }

    .carousel-order-flag {
      position: absolute;
      top: 10px;
      left: 10px;
      background: rgba(11, 11, 11, .75);
      color: #fff;
      font-size: 11px;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 999px;
      letter-spacing: .3px;
    }

    .carousel-body {
      padding: 14px 16px;
    }

    .order-field {
      margin-bottom: 12px;
    }

    .order-field label {
      display: block;
      font-size: 11px;
      font-weight: 600;
      color: var(--adm-soft);
      text-transform: uppercase;
      letter-spacing: .4px;
      margin-bottom: 6px;
    }

    .order-input {
      width: 100%;
      padding: 8px 11px;
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      font-size: 13px;
      font-family: 'Inter', sans-serif;
      color: var(--adm-ink);
      background: var(--adm-surface2);
    }

    .order-input:focus {
      outline: none;
      border-color: var(--adm-ink);
      background: #fff;
    }

    .item-actions {
      display: flex;
      justify-content: flex-end;
    }

    /* ── Empty state ────────────────────────── */
    .adm-empty {
      text-align: center;
      padding: 56px 20px;
      color: var(--adm-muted);
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
    }

    .adm-empty i {
      font-size: 38px;
      opacity: .35;
      display: block;
      margin-bottom: 14px;
    }

    .adm-empty p {
      font-size: 13.5px;
      color: var(--adm-soft);
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
      max-width: 480px;
      width: 100%;
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
    .form-group {
      margin-bottom: 18px;
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

    input[type="file"].form-control {
      padding: 9px 12px;
      cursor: pointer;
    }

    .form-help {
      font-size: 11.5px;
      color: var(--adm-muted);
      margin-top: 6px;
    }

    .form-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      margin-top: 22px;
      padding-top: 18px;
      border-top: 1px solid var(--adm-line);
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

    <!-- Header -->
    <div class="mb-8 adm-fade adm-header-row">
      <div>
        <div class="adm-eyebrow mb-2">Sales &amp; Marketing</div>
        <h1 class="adm-title">Manage Carousel Images</h1>
        <p class="adm-subtitle mt-1">Upload, reorder, and remove images shown in the Concept Designs carousel.</p>
      </div>
      <div class="adm-header-actions">
        <a href="concept-dashboard" class="adm-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <button class="adm-btn adm-btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Add New Image</button>
      </div>
    </div>

    <form method="POST" id="orderForm">

      <!-- Toolbar -->
      <div class="adm-toolbar adm-fade">
        <div class="adm-toolbar-info">
          <i class="fas fa-images"></i>
          <span><?php echo $carousel_count; ?> image<?php echo $carousel_count == 1 ? '' : 's'; ?> in carousel</span>
        </div>
        <button type="submit" name="update_order" class="adm-btn adm-btn-outline" <?php echo $carousel_count == 0 ? 'disabled' : ''; ?>>
          <i class="fas fa-arrow-up-short-wide"></i> Save Order
        </button>
      </div>

      <!-- Carousel Grid -->
      <?php if ($carousel_count > 0): ?>
        <div class="carousel-grid adm-fade">
          <?php while ($image = $carousel_images->fetch_assoc()): ?>
            <div class="carousel-item">
              <div class="carousel-image-wrap">
                <img src="<?php echo CLIENT_ASSET; ?>/<?php echo htmlspecialchars(ltrim($image['image_path'], './')); ?>" class="carousel-image" alt="">
                <span class="carousel-order-flag">#<?php echo $image['display_order']; ?></span>
              </div>
              <div class="carousel-body">
                <div class="order-field">
                  <label>Display Order</label>
                  <input type="number" name="order[<?php echo $image['id']; ?>]" value="<?php echo $image['display_order']; ?>" class="order-input">
                </div>
                <div class="item-actions">
                  <a href="?delete=<?php echo $image['id']; ?>" class="adm-btn adm-btn-danger adm-btn-sm" onclick="return confirm('Are you sure?')"><i class="fas fa-trash"></i> Delete</a>
                </div>
              </div>
            </div>
          <?php endwhile; ?>
        </div>
      <?php else: ?>
        <div class="adm-empty adm-fade">
          <i class="fas fa-images"></i>
          <p>No carousel images added yet</p>
        </div>
      <?php endif; ?>

    </form>

  </div>

  <!-- Add Image Modal -->
  <div id="imageModal" class="adm-modal-bg">
    <div class="adm-modal-box">
      <div class="adm-modal-head">
        <h3><i class="fas fa-image" style="color:var(--adm-soft);"></i> Add New Carousel Image</h3>
        <button class="adm-modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
          <label>Image</label>
          <input type="file" name="image" accept="image/*" class="form-control" required>
          <p class="form-help">JPG/PNG will be converted to WebP automatically.</p>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label>Display Order</label>
          <input type="number" name="display_order" value="1" class="form-control" required>
        </div>

        <div class="form-actions">
          <button type="button" class="adm-btn adm-btn-outline" onclick="closeModal()">Cancel</button>
          <button type="submit" class="adm-btn adm-btn-primary"><i class="fas fa-upload"></i> Upload</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function openModal() {
      document.getElementById('imageModal').classList.add('open');
    }

    function closeModal() {
      document.getElementById('imageModal').classList.remove('open');
    }

    window.onclick = function (event) {
      const modal = document.getElementById('imageModal');
      if (event.target === modal) {
        closeModal();
      }
    }
  </script>
</body>

</html>