<?php
//home_settings_hero_view.php
session_start();
include $includes ['connection'];
include $includes ['mainbody'];

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

$success_message = "";
$error_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_hero') {
        $title = trim($_POST['title']);
        $target_dir = "../../realiving_user/images/hero_section/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                $error_message = "Only image files (JPG, PNG, GIF, WebP) are allowed.";
            } else {
                $file_name = uniqid() . '_' . time() . '.webp';
                $target_file = $target_dir . $file_name;
                $filepath = './images/hero_section/' . $file_name;
                
                // Convert image to WebP
                $temp_file = $_FILES['image']['tmp_name'];
                $image = null;
                
                // Create image resource based on file type
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
                    // Convert to WebP with quality 90
                    if (imagewebp($image, $target_file, 90)) {
                        imagedestroy($image);
                        
                        $stmt = $conn->prepare("INSERT INTO hero_section (title, filepath, is_active) VALUES (?, ?, 1)");
                        $stmt->bind_param("ss", $title, $filepath);
                        
                        if ($stmt->execute()) {
                            $success_message = "Hero image added and converted to WebP successfully!";
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
    
    if ($action === 'toggle_status') {
        $id = intval($_POST['id']);
        $current_status = intval($_POST['current_status']);
        $new_status = $current_status === 1 ? 0 : 1;
        
        $stmt = $conn->prepare("UPDATE hero_section SET is_active = ? WHERE id = ?");
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
        
        $stmt = $conn->prepare("DELETE FROM hero_section WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $file_to_delete = "../../" . ltrim($filepath, '../');
            if (file_exists($file_to_delete)) {
                unlink($file_to_delete);
            }
            $success_message = "Image deleted successfully!";
        } else {
            $error_message = "Failed to delete image.";
        }
        $stmt->close();
    }
}

$hero_items = $conn->query("SELECT * FROM hero_section ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Hero Section - RealLiving</title>
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
    .adm-media-path{
      font-size:11px; color: var(--adm-muted); margin-bottom:1rem;
      word-break: break-all;
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
    }
    .adm-field-label{ font-size:12.5px; font-weight:600; color: var(--adm-ink); margin-bottom:.5rem; display:block; }
    .adm-field-hint{ font-size:11.5px; color: var(--adm-muted); margin-top:.4rem; }
    .adm-input{
      width:100%; padding:.75rem 1rem; border-radius:9px;
      border:1px solid var(--adm-line); background: var(--adm-bg);
      font-size:13.5px; color: var(--adm-ink);
      transition: border-color .2s ease, background .2s ease;
    }
    .adm-input:focus{ outline:none; border-color: var(--adm-ink); background: var(--adm-surface); }

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
        <h1 class="adm-title">Hero Section Images</h1>
        <p class="adm-subtitle mt-1">Multiple images can be active at once.</p>
      </div>
      <button onclick="openModal('heroModal')" class="adm-btn">
        <i class="fas fa-upload"></i>
        <span>Upload New Image</span>
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

    <?php if ($hero_items->num_rows === 0): ?>
      <div class="adm-empty adm-fade">
        <i class="fas fa-panorama text-2xl mb-3" style="color:var(--adm-muted);"></i>
        <p class="text-sm font-medium" style="color:var(--adm-ink);">No hero images yet</p>
        <p class="text-xs mt-1">Upload your first image to get started.</p>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 adm-fade">
        <?php while ($item = $hero_items->fetch_assoc()): 
            $filename = basename($item['filepath']);
            $display_path = CLIENT_ASSET . "/images/hero_section/" . $filename;
        ?>
          <div class="adm-media-card">
            <img src="<?php echo htmlspecialchars($display_path); ?>" alt="Hero" class="adm-media-thumb" />
            <div class="adm-media-body">
              <h3 class="adm-media-title"><?php echo htmlspecialchars($item['title']); ?></h3>
              <p class="adm-media-path"><?php echo htmlspecialchars($item['filepath']); ?></p>
              <div class="flex items-center justify-between">
                <form method="POST" class="inline">
                  <input type="hidden" name="action" value="toggle_status" />
                  <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                  <input type="hidden" name="current_status" value="<?php echo $item['is_active']; ?>" />
                  <button type="submit" class="adm-pill <?php echo $item['is_active'] ? 'is-active' : ''; ?>">
                    <span class="dot"></span>
                    <?php echo $item['is_active'] ? 'Active' : 'Inactive'; ?>
                  </button>
                </form>
                <form method="POST" onsubmit="return confirm('Delete this image?');" class="inline">
                  <input type="hidden" name="action" value="delete" />
                  <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                  <input type="hidden" name="filepath" value="<?php echo $item['filepath']; ?>" />
                  <button type="submit" class="adm-btn-ghost">
                    <i class="fas fa-trash-can"></i>
                  </button>
                </form>
              </div>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Upload Modal -->
  <div id="heroModal" class="modal">
    <div class="modal-content max-w-md w-full mx-4 p-6">
      <div class="flex items-center justify-between mb-6">
        <h3 class="text-[15px] font-semibold" style="color:var(--adm-ink);">Upload Hero Image</h3>
        <button onclick="closeModal('heroModal')" class="adm-btn-ghost">
          <i class="fas fa-xmark"></i>
        </button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_hero" />
        <div class="space-y-4">
          <div>
            <label class="adm-field-label">Title</label>
            <input type="text" name="title" required class="adm-input" placeholder="Enter image title" />
          </div>
          <div>
            <label class="adm-field-label">Image (any format — converted to WebP)</label>
            <input type="file" id="heroImageInput" name="image" accept="image/*" required class="adm-input" onchange="previewImage(event, 'heroPreview')" />
            <p class="adm-field-hint">Supports: JPG, PNG, GIF, WebP</p>
          </div>
          <div id="heroPreview" class="hidden">
            <label class="adm-field-label">Preview</label>
            <div class="relative rounded-lg overflow-hidden" style="border:1px solid var(--adm-line);">
              <img id="heroPreviewImage" src="" alt="Preview" class="w-full h-48 object-cover" />
              <button type="button" onclick="clearPreview('heroImageInput', 'heroPreview')" class="absolute top-2 right-2 adm-btn-ghost" style="background:#fff; border:1px solid var(--adm-line);">
                <i class="fas fa-xmark"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="adm-btn adm-btn-block">
            <i class="fas fa-upload"></i>
            <span>Upload Image</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function openModal(modalId) {
      document.getElementById(modalId).classList.add('active');
    }

    function closeModal(modalId) {
      document.getElementById(modalId).classList.remove('active');
      const form = document.querySelector('#' + modalId + ' form');
      if (form) form.reset();
      clearPreview('heroImageInput', 'heroPreview');
    }

    function previewImage(event, previewId) {
      const file = event.target.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          const previewContainer = document.getElementById(previewId);
          const previewImage = document.getElementById(previewId + 'Image');
          previewImage.src = e.target.result;
          previewContainer.classList.remove('hidden');
        }
        reader.readAsDataURL(file);
      }
    }

    function clearPreview(inputId, previewId) {
      document.getElementById(inputId).value = '';
      document.getElementById(previewId).classList.add('hidden');
      document.getElementById(previewId + 'Image').src = '';
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