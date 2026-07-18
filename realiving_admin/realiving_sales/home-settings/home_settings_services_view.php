<?php
//home_settings_services_view.php
include $includes ['mainbody'];

$success_message = "";
$error_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_service') {
    $service_number = intval($_POST['service_number']);
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $detailed_description = trim($_POST['detailed_description']);
    $display_order = intval($_POST['display_order']);
    $target_dir = ROOT_PATH . "realiving_user/images/services/";
    
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
        $file_extension = strtolower(pathinfo($_FILES['media']['name'], PATHINFO_EXTENSION));
        $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $video_extensions = ['mp4', 'webm', 'mov', 'avi'];
        $allowed_extensions = array_merge($image_extensions, $video_extensions);
        
        if (!in_array($file_extension, $allowed_extensions)) {
            $error_message = "Only image files (JPG, PNG, GIF, WebP) or video files (MP4, WebM, MOV, AVI) are allowed.";
        } else {
            $is_video = in_array($file_extension, $video_extensions);
            $media_type = $is_video ? 'video' : 'image';
            
            if ($is_video) {
                // Handle video upload
                $file_name = uniqid() . '_' . time() . '.' . $file_extension;
                $target_file = $target_dir . $file_name;
                $filepath = './images/services/' . $file_name;
                
                if (move_uploaded_file($_FILES['media']['tmp_name'], $target_file)) {
                    $stmt = $conn->prepare("INSERT INTO services_section (service_number, title, description, detailed_description, image_path, media_type, is_active, display_order) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
                    $stmt->bind_param("isssssi", $service_number, $title, $description, $detailed_description, $filepath, $media_type, $display_order);
                    
                    if ($stmt->execute()) {
                        $success_message = "Service added successfully!";
                    } else {
                        $error_message = "Database error: " . $conn->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = "Failed to upload video.";
                }
            } else {
                // Handle image upload (convert to WebP)
                $file_name = uniqid() . '_' . time() . '.webp';
                $target_file = $target_dir . $file_name;
                $filepath = './images/services/' . $file_name;
                
                $temp_file = $_FILES['media']['tmp_name'];
                $image = null;
                
                switch ($file_extension) {
                    case 'jpg':
                    case 'jpeg':
                        $image = imagecreatefromjpeg($temp_file);
                        break;
                    case 'png':
                        $image = imagecreatefrompng($temp_file);
                        break;
                    case 'gif':
                        $image = imagecreatefromgif($temp_file);
                        break;
                    case 'webp':
                        $image = imagecreatefromwebp($temp_file);
                        break;
                }
                
                if ($image !== false && $image !== null) {
                    if (imagewebp($image, $target_file, 90)) {
                        imagedestroy($image);
                        
                        $stmt = $conn->prepare("INSERT INTO services_section (service_number, title, description, detailed_description, image_path, media_type, is_active, display_order) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
                        $stmt->bind_param("isssssi", $service_number, $title, $description, $detailed_description, $filepath, $media_type, $display_order);
                        
                        if ($stmt->execute()) {
                            $success_message = "Service added successfully!";
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
        }
    } else {
        $error_message = "Please select a media file.";
    }
}
    
    if ($action === 'edit_service') {
        $id = intval($_POST['id']);
        $service_number = intval($_POST['service_number']);
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $detailed_description = trim($_POST['detailed_description']);
        $display_order = intval($_POST['display_order']);
        
        // Check if new media uploaded
if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
    $target_dir = ROOT_PATH . "realiving_user/images/services/";
    $file_extension = strtolower(pathinfo($_FILES['media']['name'], PATHINFO_EXTENSION));
    $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $video_extensions = ['mp4', 'webm', 'mov', 'avi'];
    $allowed_extensions = array_merge($image_extensions, $video_extensions);
    
    if (!in_array($file_extension, $allowed_extensions)) {
        $error_message = "Only image or video files allowed.";
    } else {
        $is_video = in_array($file_extension, $video_extensions);
        $media_type = $is_video ? 'video' : 'image';
        
        // Get old file path to delete
        $old_result = $conn->query("SELECT image_path FROM services_section WHERE id = $id");
        $old_row = $old_result->fetch_assoc();
        $old_filepath = $old_row['image_path'];
        
        if ($is_video) {
            // Handle video upload
            $file_name = uniqid() . '_' . time() . '.' . $file_extension;
            $target_file = $target_dir . $file_name;
            $filepath = './images/services/' . $file_name;
            
            if (move_uploaded_file($_FILES['media']['tmp_name'], $target_file)) {
    // Delete old file
    $old_filename = basename($old_filepath);
    $file_to_delete = ROOT_PATH . "realiving_user/images/services/" . $old_filename;
    if (file_exists($file_to_delete)) {
        unlink($file_to_delete);
    }
                
                $stmt = $conn->prepare("UPDATE services_section SET service_number = ?, title = ?, description = ?, detailed_description = ?, image_path = ?, media_type = ?, display_order = ? WHERE id = ?");
                $stmt->bind_param("isssssii", $service_number, $title, $description, $detailed_description, $filepath, $media_type, $display_order, $id);
                
                if ($stmt->execute()) {
                    $success_message = "Service updated successfully!";
                } else {
                    $error_message = "Database error: " . $conn->error;
                }
                $stmt->close();
            } else {
                $error_message = "Failed to upload video.";
            }
        } else {
            // Handle image upload (convert to WebP)
            $file_name = uniqid() . '_' . time() . '.webp';
            $target_file = $target_dir . $file_name;
            $filepath = './images/services/' . $file_name;
            
            $temp_file = $_FILES['media']['tmp_name'];
            $image = null;
            
            switch ($file_extension) {
                case 'jpg':
                case 'jpeg':
                    $image = imagecreatefromjpeg($temp_file);
                    break;
                case 'png':
                    $image = imagecreatefrompng($temp_file);
                    break;
                case 'gif':
                    $image = imagecreatefromgif($temp_file);
                    break;
                case 'webp':
                    $image = imagecreatefromwebp($temp_file);
                    break;
            }
            
            if ($image !== false && $image !== null) {
                if (imagewebp($image, $target_file, 90)) {
    imagedestroy($image);
    
    // Delete old file
    $old_filename = basename($old_filepath);
    $file_to_delete = ROOT_PATH . "realiving_user/images/services/" . $old_filename;
    if (file_exists($file_to_delete)) {
        unlink($file_to_delete);
    }
                    
                    $stmt = $conn->prepare("UPDATE services_section SET service_number = ?, title = ?, description = ?, detailed_description = ?, image_path = ?, media_type = ?, display_order = ? WHERE id = ?");
                    $stmt->bind_param("isssssii", $service_number, $title, $description, $detailed_description, $filepath, $media_type, $display_order, $id);
                    
                    if ($stmt->execute()) {
                        $success_message = "Service updated successfully!";
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
    }
} else {
    // Update without changing media
    $stmt = $conn->prepare("UPDATE services_section SET service_number = ?, title = ?, description = ?, detailed_description = ?, display_order = ? WHERE id = ?");
    $stmt->bind_param("isssii", $service_number, $title, $description, $detailed_description, $display_order, $id);
    
    if ($stmt->execute()) {
        $success_message = "Service updated successfully!";
    } else {
        $error_message = "Failed to update service.";
    }
    $stmt->close();
}
    }
    
    if ($action === 'toggle_status') {
        $id = intval($_POST['id']);
        $current_status = intval($_POST['current_status']);
        $new_status = $current_status === 1 ? 0 : 1;
        
        $stmt = $conn->prepare("UPDATE services_section SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_status, $id);
        
        if ($stmt->execute()) {
            $success_message = "Status updated successfully!";
        } else {
            $error_message = "Failed to update status.";
        }
        $stmt->close();
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id']);
        $filepath = $_POST['filepath'];
        
        $stmt = $conn->prepare("DELETE FROM services_section WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
    $filename = basename($filepath);
    $file_to_delete = ROOT_PATH . "realiving_user/images/services/" . $filename;
    if (file_exists($file_to_delete)) {
        unlink($file_to_delete);
    }
    $success_message = "Service deleted successfully!";
} else {
            $error_message = "Failed to delete service.";
        }
        $stmt->close();
    }
}

$services = $conn->query("SELECT * FROM services_section ORDER BY display_order ASC, service_number ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Services Section - RealLiving</title>
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
      width:36px; height:36px; border-radius:8px;
      color: var(--adm-soft); background:transparent; border:1px solid transparent;
      transition: background .2s ease, color .2s ease, border-color .2s ease;
    }
    .adm-btn-ghost:hover{ background: var(--adm-bg); color:#DC2626; border-color: var(--adm-line); }
    .adm-btn-ghost.is-edit:hover{ color:#2563EB; }

    /* ── Badges / Pills ──────────────────────── */
    .adm-pill{
      display:inline-flex; align-items:center; gap:6px;
      font-size:11.5px; font-weight:600; letter-spacing:.2px;
      padding:.4rem .8rem; border-radius:999px;
      border:1px solid var(--adm-line);
      background: var(--adm-bg); color: var(--adm-soft);
      cursor:pointer; transition: opacity .2s ease;
    }
    .adm-pill:hover{ opacity:.8; }
    .adm-pill.is-active{
      background:#ECFDF3; color:#16A34A; border-color:#BBF7D0;
    }
    .adm-pill .dot{ width:6px; height:6px; border-radius:999px; background: currentColor; }

    .adm-number-badge{
      position:absolute; top:10px; left:10px;
      width:32px; height:32px; border-radius:8px;
      background: var(--adm-ink); color:#fff;
      display:flex; align-items:center; justify-content:center;
      font-size:13px; font-weight:700;
      box-shadow: 0 4px 10px -4px rgba(11,11,11,0.4);
    }
    .adm-order-tag{
      font-size:11px; color: var(--adm-muted); margin-bottom:.75rem;
    }

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

    /* ── Media cards ─────────────────────────── */
    .adm-media-card{
      background: var(--adm-surface);
      border:1px solid var(--adm-line);
      border-radius:10px;
      overflow:hidden;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }
    .adm-media-card:hover{
      border-color: var(--adm-ink);
      box-shadow: 0 10px 26px -16px rgba(11,11,11,0.25);
      transform: translateY(-2px);
    }
    .adm-media-thumb{
      width:100%; height:190px; object-fit:cover; display:block;
      background: var(--adm-bg);
    }
    .adm-media-body{ padding:1.1rem 1.25rem 1.25rem; }
    .adm-media-title{ font-size:14.5px; font-weight:600; color: var(--adm-ink); margin-bottom:.3rem; }
    .adm-media-desc{
      font-size:12.5px; color: var(--adm-soft); margin-bottom:.5rem;
      display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;
      line-height:1.5;
    }

    /* ── Empty state ─────────────────────────── */
    .adm-empty{
      border:1px dashed var(--adm-line); border-radius:10px;
      padding:3rem 1.5rem; text-align:center; color: var(--adm-soft);
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
    .adm-field-label{ font-size:12.5px; font-weight:600; color: var(--adm-ink); margin-bottom:.5rem; display:block; }
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
        <a href="<?= BASE_URL ?>home-setting" class="adm-back">
          <i class="fas fa-arrow-left"></i>
          <span>Back to Dashboard</span>
        </a>
        <div class="adm-eyebrow mb-2">Home Settings</div>
        <h1 class="adm-title">Services Section</h1>
        <p class="adm-subtitle mt-1">Manage service cards displayed on homepage.</p>
      </div>
      <button onclick="openAddModal()" class="adm-btn">
        <i class="fas fa-plus"></i>
        <span>Add New Service</span>
      </button>
    </div>

    <?php if (!empty($success_message)): ?>
      <div class="adm-alert is-success mb-6 adm-fade">
        <i class="fas fa-circle-check mt-0.5"></i>
        <p><?php echo htmlspecialchars($success_message); ?></p>
      </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
      <div class="adm-alert is-error mb-6 adm-fade">
        <i class="fas fa-triangle-exclamation mt-0.5"></i>
        <p><?php echo htmlspecialchars($error_message); ?></p>
      </div>
    <?php endif; ?>

    <?php if ($services->num_rows === 0): ?>
      <div class="adm-empty adm-fade">
        <i class="fas fa-layer-group text-2xl mb-3" style="color:var(--adm-muted);"></i>
        <p class="text-sm font-medium" style="color:var(--adm-ink);">No services yet</p>
        <p class="text-xs mt-1">Add your first service to get started.</p>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 adm-fade">
        <?php while ($service = $services->fetch_assoc()): 
            $filename = basename($service['image_path']);
            $display_path = CLIENT_ASSET . '/images/services/' . $filename;
        ?>
          <div class="adm-media-card">
            <div class="relative">
              <?php if (isset($service['media_type']) && $service['media_type'] === 'video'): ?>
                <video src="<?php echo htmlspecialchars($display_path); ?>" class="adm-media-thumb" controls></video>
              <?php else: ?>
                <img src="<?php echo htmlspecialchars($display_path); ?>" alt="Service" class="adm-media-thumb" />
              <?php endif; ?>
              <div class="adm-number-badge"><?php echo $service['service_number']; ?></div>
            </div>
            <div class="adm-media-body">
              <h3 class="adm-media-title"><?php echo htmlspecialchars($service['title']); ?></h3>
              <p class="adm-media-desc"><?php echo htmlspecialchars($service['description']); ?></p>
              <p class="adm-order-tag">Order: <?php echo $service['display_order']; ?></p>
              <div class="flex items-center justify-between gap-2">
                <form method="POST" class="inline">
                  <input type="hidden" name="action" value="toggle_status" />
                  <input type="hidden" name="id" value="<?php echo $service['id']; ?>" />
                  <input type="hidden" name="current_status" value="<?php echo $service['is_active']; ?>" />
                  <button type="submit" class="adm-pill <?php echo $service['is_active'] ? 'is-active' : ''; ?>">
                    <span class="dot"></span>
                    <?php echo $service['is_active'] ? 'Active' : 'Inactive'; ?>
                  </button>
                </form>
                <div class="flex gap-1">
                  <button onclick='openEditModal(<?php echo htmlspecialchars(json_encode($service), ENT_QUOTES, "UTF-8"); ?>)' class="adm-btn-ghost is-edit">
                    <i class="fas fa-pen"></i>
                  </button>
                  <form method="POST" onsubmit="return confirm('Delete this service?');" class="inline">
                    <input type="hidden" name="action" value="delete" />
                    <input type="hidden" name="id" value="<?php echo $service['id']; ?>" />
                    <input type="hidden" name="filepath" value="<?php echo $service['image_path']; ?>" />
                    <button type="submit" class="adm-btn-ghost">
                      <i class="fas fa-trash-can"></i>
                    </button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Add Service Modal -->
  <div id="addServiceModal" class="modal">
    <div class="modal-content max-w-2xl w-full mx-4 p-6">
      <div class="flex items-center justify-between mb-6">
        <h3 class="text-[15px] font-semibold" style="color:var(--adm-ink);">Add New Service</h3>
        <button onclick="closeModal('addServiceModal')" class="adm-btn-ghost">
          <i class="fas fa-xmark"></i>
        </button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_service" />
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="adm-field-label">Service Number</label>
              <input type="number" name="service_number" required min="1" class="adm-input" placeholder="1" />
            </div>
            <div>
              <label class="adm-field-label">Display Order</label>
              <input type="number" name="display_order" required min="0" class="adm-input" placeholder="0" />
            </div>
          </div>
          <div>
            <label class="adm-field-label">Title</label>
            <input type="text" name="title" required class="adm-input" placeholder="Design" />
          </div>
          <div>
            <label class="adm-field-label">Short Description (for homepage)</label>
            <textarea name="description" required rows="2" class="adm-textarea" placeholder="Brief description for homepage card..."></textarea>
            <p class="adm-field-hint">This appears on the homepage service cards</p>
          </div>
          <div>
            <label class="adm-field-label">Detailed Description (for services page)</label>
            <textarea name="detailed_description" required rows="6" class="adm-textarea" placeholder="Full detailed description for services page..."></textarea>
            <p class="adm-field-hint">This appears on the dedicated services page with more details</p>
          </div>
          <div>
            <label class="adm-field-label">Image or Video</label>
            <input type="file" id="addServiceMedia" name="media" accept="image/*,video/*" required class="adm-input" onchange="previewImage(event, 'addPreview')" />
            <p class="adm-field-hint">Supports: JPG, PNG, GIF, WebP, MP4, WebM, MOV, AVI</p>
          </div>
          <div id="addPreview" class="hidden">
            <label class="adm-field-label">Preview</label>
            <div class="relative rounded-lg overflow-hidden" style="border:1px solid var(--adm-line);">
              <img id="addPreviewImage" src="" alt="Preview" class="w-full h-48 object-cover hidden" />
              <video id="addPreviewVideo" src="" class="w-full h-48 object-cover hidden" controls></video>
              <button type="button" onclick="clearPreview('addServiceMedia', 'addPreview')" class="absolute top-2 right-2 adm-btn-ghost" style="background:#fff; border:1px solid var(--adm-line);">
                <i class="fas fa-xmark"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="adm-btn adm-btn-block">
            <i class="fas fa-plus"></i>
            <span>Add Service</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit Service Modal -->
  <div id="editServiceModal" class="modal">
    <div class="modal-content max-w-2xl w-full mx-4 p-6">
      <div class="flex items-center justify-between mb-6">
        <h3 class="text-[15px] font-semibold" style="color:var(--adm-ink);">Edit Service</h3>
        <button onclick="closeModal('editServiceModal')" class="adm-btn-ghost">
          <i class="fas fa-xmark"></i>
        </button>
      </div>
      <form method="POST" enctype="multipart/form-data" id="editForm">
        <input type="hidden" name="action" value="edit_service" />
        <input type="hidden" name="id" id="editId" />
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="adm-field-label">Service Number</label>
              <input type="number" name="service_number" id="editNumber" required min="1" class="adm-input" />
            </div>
            <div>
              <label class="adm-field-label">Display Order</label>
              <input type="number" name="display_order" id="editOrder" required min="0" class="adm-input" />
            </div>
          </div>
          <div>
            <label class="adm-field-label">Title</label>
            <input type="text" name="title" id="editTitle" required class="adm-input" />
          </div>
          <div>
            <label class="adm-field-label">Short Description (for homepage)</label>
            <textarea name="description" id="editDescription" required rows="2" class="adm-textarea"></textarea>
            <p class="adm-field-hint">This appears on the homepage service cards</p>
          </div>
          <div>
            <label class="adm-field-label">Detailed Description (for services page)</label>
            <textarea name="detailed_description" id="editDetailedDescription" required rows="6" class="adm-textarea"></textarea>
            <p class="adm-field-hint">This appears on the dedicated services page with more details</p>
          </div>
          <div>
            <label class="adm-field-label">Current Media</label>
            <img id="currentImage" src="" alt="Current" class="w-full h-32 object-cover rounded-lg mb-2 hidden" style="border:1px solid var(--adm-line);" />
            <video id="currentVideo" src="" class="w-full h-32 object-cover rounded-lg mb-2 hidden" style="border:1px solid var(--adm-line);" controls></video>
          </div>
          <div>
            <label class="adm-field-label">New Image/Video (optional)</label>
            <input type="file" id="editServiceMedia" name="media" accept="image/*,video/*" class="adm-input" onchange="previewImage(event, 'editPreview')" />
            <p class="adm-field-hint">Leave empty to keep current media</p>
          </div>
          <div id="editPreview" class="hidden">
            <label class="adm-field-label">New Preview</label>
            <div class="relative rounded-lg overflow-hidden" style="border:1px solid var(--adm-line);">
              <img id="editPreviewImage" src="" alt="Preview" class="w-full h-48 object-cover hidden" />
              <video id="editPreviewVideo" src="" class="w-full h-48 object-cover hidden" controls></video>
              <button type="button" onclick="clearPreview('editServiceMedia', 'editPreview')" class="absolute top-2 right-2 adm-btn-ghost" style="background:#fff; border:1px solid var(--adm-line);">
                <i class="fas fa-xmark"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="adm-btn adm-btn-block">
            <i class="fas fa-floppy-disk"></i>
            <span>Update Service</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Base path for service media, injected from PHP (CLIENT_ASSET is a PHP constant, not available in JS)
    const SERVICES_MEDIA_BASE = <?php echo json_encode(CLIENT_ASSET . '/images/services/'); ?>;

    function openAddModal() {
      document.getElementById('addServiceModal').classList.add('active');
    }

    function openEditModal(service) {
      document.getElementById('editId').value = service.id;
      document.getElementById('editNumber').value = service.service_number;
      document.getElementById('editTitle').value = service.title;
      document.getElementById('editDescription').value = service.description;
      document.getElementById('editDetailedDescription').value = service.detailed_description;
      document.getElementById('editOrder').value = service.display_order;

      const filename = service.image_path.split('/').pop();
      const mediaPath = SERVICES_MEDIA_BASE + filename;

      // Show appropriate media type
      const isVideo = service.media_type === 'video';
      if (isVideo) {
        document.getElementById('currentVideo').src = mediaPath;
        document.getElementById('currentVideo').classList.remove('hidden');
        document.getElementById('currentImage').classList.add('hidden');
      } else {
        document.getElementById('currentImage').src = mediaPath;
        document.getElementById('currentImage').classList.remove('hidden');
        document.getElementById('currentVideo').classList.add('hidden');
      }

      document.getElementById('editServiceModal').classList.add('active');
    }

    function closeModal(modalId) {
      document.getElementById(modalId).classList.remove('active');
      const form = document.querySelector('#' + modalId + ' form');
      if (form) form.reset();
      if (modalId === 'addServiceModal') {
        clearPreview('addServiceMedia', 'addPreview');
      } else {
        clearPreview('editServiceMedia', 'editPreview');
      }
    }

    function previewImage(event, previewId) {
      const file = event.target.files[0];
      if (file) {
        const reader = new FileReader();
        const isVideo = file.type.startsWith('video/');

        reader.onload = function(e) {
          const previewContainer = document.getElementById(previewId);
          const previewImage = document.getElementById(previewId + 'Image');
          const previewVideo = document.getElementById(previewId + 'Video');

          if (isVideo) {
            previewVideo.src = e.target.result;
            previewVideo.classList.remove('hidden');
            previewImage.classList.add('hidden');
          } else {
            previewImage.src = e.target.result;
            previewImage.classList.remove('hidden');
            previewVideo.classList.add('hidden');
          }
          previewContainer.classList.remove('hidden');
        }
        reader.readAsDataURL(file);
      }
    }

    function clearPreview(inputId, previewId) {
      document.getElementById(inputId).value = '';
      document.getElementById(previewId).classList.add('hidden');
      document.getElementById(previewId + 'Image').src = '';
      document.getElementById(previewId + 'Image').classList.add('hidden');
      document.getElementById(previewId + 'Video').src = '';
      document.getElementById(previewId + 'Video').classList.add('hidden');
    }

    window.onclick = function(event) {
      if (event.target.classList.contains('modal')) {
        closeModal(event.target.id);
      }
    }
  </script>
</body>
</html>

<?php $conn->close(); ?>