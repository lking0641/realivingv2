<?php
//projects_cabinet_cost_settings.php
include $includes ['mainbody'];


$success_message = "";
$error_message = "";

// Function to convert image to WebP
function convertToWebP($source, $destination, $quality = 90) {
    $info = getimagesize($source);
    $image = null;
    
    switch ($info['mime']) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($source);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($source);
            break;
    }
    
    if ($image !== false && $image !== null) {
        $result = imagewebp($image, $destination, $quality);
        imagedestroy($image);
        return $result;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_image') {
        $target_dir = ROOT_PATH . "realiving_user/images/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        if (isset($_FILES['cabinet_image']) && $_FILES['cabinet_image']['error'] === 0) {
            // Delete old image first
            $old_image_path = $target_dir . "background-image.webp";
            if (file_exists($old_image_path)) {
                unlink($old_image_path);
            }
            
            $file_name = "background-image.webp";
            $target_file = $target_dir . $file_name;
            
            if (convertToWebP($_FILES['cabinet_image']['tmp_name'], $target_file)) {
                $success_message = "Cabinet cost image updated successfully and converted to WebP!";
            } else {
                $error_message = "Failed to convert image to WebP.";
            }
        } else {
            $error_message = "Please select an image.";
        }
    }
}

// Get current image
$current_image_disk = ROOT_PATH . "realiving_user/images/background-image.webp";
$current_image = CLIENT_ASSET . "/images/background-image.webp";
$image_exists = file_exists($current_image_disk);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cabinet Cost Section Settings - RealLiving</title>
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
      --adm-ink: #0B0B0B;
      --adm-soft: #6B6B6B;
      --adm-muted: #9A9A9A;
      --adm-line: #E2E2E2;
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
      font-size: 13px;
      font-weight: 600;
      color: var(--adm-soft);
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 1rem;
      transition: color .2s ease;
    }

    .adm-back:hover {
      color: var(--adm-ink);
    }

    /* ── Panels ─────────────────────────────── */
    .adm-panel {
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 12px;
      padding: 2rem;
    }

    .adm-panel-title {
      font-size: 17px;
      font-weight: 700;
      color: var(--adm-ink);
      margin-bottom: .3rem;
    }

    .adm-panel-desc {
      font-size: 13px;
      color: var(--adm-soft);
    }

    /* ── Alerts ─────────────────────────────── */
    .adm-alert {
      border-radius: 10px;
      padding: 1rem 1.1rem;
      display: flex;
      align-items: flex-start;
      gap: 10px;
      font-size: 13px;
      font-weight: 500;
      border: 1px solid var(--adm-line);
      background: var(--adm-surface);
      border-left: 3px solid var(--adm-ink);
    }

    .adm-note {
      border-radius: 10px;
      padding: 1rem 1.1rem;
      display: flex;
      align-items: flex-start;
      gap: 10px;
      font-size: 12.5px;
      line-height: 1.5;
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      color: var(--adm-soft);
    }

    /* ── Image preview / current image ─────── */
    .adm-image-frame {
      position: relative;
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid var(--adm-line);
    }

    .adm-image-badge {
      position: absolute;
      top: 12px;
      right: 12px;
      background: var(--adm-ink);
      color: #fff;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .3px;
      padding: .3rem .7rem;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }

    .adm-empty-frame {
      border: 1.5px dashed var(--adm-line);
      border-radius: 10px;
      text-align: center;
      padding: 3rem 1.5rem;
      background: var(--adm-bg);
    }

    /* ── Buttons ────────────────────────────── */
    .adm-btn {
      background: var(--adm-ink);
      color: #fff;
      border: 1px solid var(--adm-ink);
      border-radius: 8px;
      font-size: 13.5px;
      font-weight: 600;
      padding: .85rem 1.2rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: opacity .2s ease;
    }

    .adm-btn:hover {
      opacity: .85;
    }

    .adm-icon-close {
      color: var(--adm-soft);
      width: 32px;
      height: 32px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px;
      transition: background .2s ease, color .2s ease;
    }

    .adm-icon-close:hover {
      background: var(--adm-bg);
      color: var(--adm-ink);
    }

    .adm-remove-btn {
      background: rgba(11, 11, 11, 0.75);
      color: #fff;
      width: 32px;
      height: 32px;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: background .2s ease;
    }

    .adm-remove-btn:hover {
      background: var(--adm-ink);
    }

    /* ── Form ───────────────────────────────── */
    .adm-label {
      font-size: 12.5px;
      font-weight: 600;
      color: var(--adm-soft);
      margin-bottom: .4rem;
      display: block;
    }

    .adm-file {
      width: 100%;
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      font-size: 13.5px;
      color: var(--adm-ink);
      padding: .75rem .9rem;
    }

    .adm-file:focus {
      outline: none;
      border-color: var(--adm-ink);
      background: var(--adm-surface);
    }

    /* ── Preview section (section mock) ─────── */
    .adm-mock {
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      overflow: hidden;
    }

    .adm-mock-cta {
      background: var(--adm-ink);
      color: #fff;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .5px;
      padding: .65rem 1.3rem;
      border-radius: 6px;
      display: inline-block;
    }

    /* ── Modal ──────────────────────────────── */
    .modal {
      display: none;
      position: fixed;
      z-index: 50;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(11, 11, 11, 0.55);
      animation: fadeIn 0.3s ease;
    }

    .modal.active {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes slideUp {
      from { transform: translateY(20px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }

    .modal-content {
      animation: slideUp 0.3s ease;
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 14px;
    }

    .adm-modal-title {
      font-size: 18px;
      font-weight: 700;
      color: var(--adm-ink);
    }

    /* ── Fade ───────────────────────────────── */
    @keyframes adm-fade {
      from { opacity: 0; transform: translateY(8px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .adm-fade {
      animation: adm-fade .4s ease both;
    }

    @media (prefers-reduced-motion: reduce) {
      .adm-fade { animation: none; }
    }
  </style>
</head>

<body class="min-h-screen flex flex-col">

  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto w-full">

    <!-- Header -->
    <div class="mb-8 adm-fade">
      <a href="<?= BASE_URL ?>projects-dashboard" class="adm-back">
        <i class="fas fa-arrow-left"></i>
        <span>Back to Dashboard</span>
      </a>
      <div class="adm-eyebrow mb-2">Projects Management</div>
      <h1 class="adm-title">Cabinet Cost Section</h1>
      <p class="adm-subtitle mt-1">Manage the background image for the "Know Your Cabinet Cost" section.</p>
    </div>

    <?php if (!empty($success_message)): ?>
      <div class="adm-alert mb-6 adm-fade">
        <i class="fas fa-circle-check mt-0.5" style="color:var(--adm-ink);"></i>
        <p><?php echo htmlspecialchars($success_message); ?></p>
      </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
      <div class="adm-alert mb-6 adm-fade">
        <i class="fas fa-circle-exclamation mt-0.5" style="color:var(--adm-ink);"></i>
        <p><?php echo htmlspecialchars($error_message); ?></p>
      </div>
    <?php endif; ?>

    <!-- Current Image Panel -->
    <div class="adm-panel adm-fade">
      <div class="mb-6">
        <h2 class="adm-panel-title">Current Background Image</h2>
        <p class="adm-panel-desc">This image appears in the "Know Your Cabinet Cost" section.</p>
      </div>

      <?php if ($image_exists): ?>
        <div class="mb-6">
          <div class="adm-image-frame">
            <img src="<?php echo $current_image; ?>?v=<?php echo time(); ?>" alt="Cabinet" class="w-full h-64 object-cover" />
            <div class="adm-image-badge">
              <i class="fas fa-check"></i>
              WebP Format
            </div>
          </div>
          <div class="adm-note mt-4">
            <i class="fas fa-circle-info mt-0.5"></i>
            <span>This image is displayed in the "Know Your Cabinet Cost with Confidence" section on the projects page. It should be high-quality and relevant to kitchen cabinets or interior design.</span>
          </div>
        </div>
      <?php else: ?>
        <div class="adm-empty-frame mb-6">
          <i class="fas fa-image text-5xl mb-4" style="color:var(--adm-muted);"></i>
          <p class="adm-subtitle">No image uploaded yet</p>
        </div>
      <?php endif; ?>

      <button onclick="openModal('uploadModal')" class="adm-btn w-full">
        <i class="fas fa-upload"></i>
        <span><?php echo $image_exists ? 'Replace Image' : 'Upload Image'; ?></span>
      </button>
    </div>

    <!-- Preview Section -->
    <div class="adm-panel mt-6 adm-fade">
      <h2 class="adm-panel-title">Section Preview</h2>
      <p class="adm-panel-desc mb-6">This is how the section appears on the website.</p>

      <div class="adm-mock">
        <div class="p-6 flex flex-col md:flex-row items-center gap-6" style="background: var(--adm-bg);">
          <div class="flex-1">
            <h3 class="text-2xl font-bold mb-3" style="color:var(--adm-ink);">Know Your Cabinet Cost with Confidence</h3>
            <p class="adm-subtitle mb-4">
              Have a vision in mind but not sure where to begin? Let's talk. Our design experts are ready to guide you through every step—from concept to completion.
            </p>
            <span class="adm-mock-cta">BOOK AN APPOINTMENT NOW</span>
          </div>
          <div class="flex-1 w-full">
            <?php if ($image_exists): ?>
              <img src="<?php echo $current_image; ?>?v=<?php echo time(); ?>" alt="Cabinet" class="w-full h-64 object-cover rounded-lg" />
            <?php else: ?>
              <div class="w-full h-64 rounded-lg flex items-center justify-center" style="background: var(--adm-line);">
                <span class="adm-subtitle">No image</span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- Upload Modal -->
  <div id="uploadModal" class="modal">
    <div class="modal-content max-w-md w-full mx-4 p-6">
      <div class="flex items-center justify-between mb-6">
        <h3 class="adm-modal-title">Upload Cabinet Image</h3>
        <button onclick="closeModal('uploadModal')" class="adm-icon-close">
          <i class="fas fa-xmark text-lg"></i>
        </button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_image" />
        <div class="space-y-4">
          <div>
            <label class="adm-label">Select Image</label>
            <input type="file" name="cabinet_image" id="cabinetImageInput" accept="image/*" required class="adm-file" onchange="previewImage(event, 'cabinetPreview')" />
            <p class="text-xs mt-1" style="color:var(--adm-muted);">Will be converted to WebP automatically</p>
          </div>
          <div id="cabinetPreview" class="hidden">
            <label class="adm-label">Preview</label>
            <div class="adm-image-frame">
              <img id="cabinetPreviewImage" src="" alt="Preview" class="w-full h-64 object-cover" />
              <button type="button" onclick="clearPreview('cabinetImageInput', 'cabinetPreview')" class="adm-remove-btn absolute top-2 right-2">
                <i class="fas fa-xmark"></i>
              </button>
            </div>
          </div>
          <div class="adm-note">
            <i class="fas fa-circle-info mt-0.5"></i>
            <span>Recommended: High-quality image of kitchen cabinets or interior design (min. 1920x1080px)</span>
          </div>
          <button type="submit" class="adm-btn w-full">
            <i class="fas fa-upload"></i> Upload &amp; Convert to WebP
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
      clearPreview('cabinetImageInput', 'cabinetPreview');
    }

    function previewImage(event, previewId) {
      const file = event.target.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function (e) {
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

    window.onclick = function (event) {
      if (event.target.classList.contains('modal')) {
        closeModal(event.target.id);
      }
    }
  </script>
</body>

</html>

<?php $conn->close(); ?>