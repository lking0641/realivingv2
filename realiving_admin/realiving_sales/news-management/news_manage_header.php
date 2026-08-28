<?php
//news_manage_header.php
include $includes ['mainbody'];

// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales']);

// Function to convert image to WebP
function convertToWebP($source, $destination, $quality = 90) {
    $info = getimagesize($source);

    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
    } elseif ($info['mime'] == 'image/gif') {
        $image = imagecreatefromgif($source);
    } else {
        return false;
    }

    imagewebp($image, $destination, $quality);
    imagedestroy($image);

    return true;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = $_POST['title'];
    $subtitle = $_POST['subtitle'];

    // Get current settings
    $current = $conn->query("SELECT * FROM news_header LIMIT 1")->fetch_assoc();
    $header_image = $current['header_image'];

    // Handle image upload
    if (isset($_FILES['header_image']) && $_FILES['header_image']['error'] == 0) {
        $target_dir = ROOT_PATH . "realiving_user/images/";

        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }

        $new_filename = 'news_header_' . time() . '.webp';
        $target_file = $target_dir . $new_filename;

        if (convertToWebP($_FILES['header_image']['tmp_name'], $target_file, 90)) {
            $header_image = 'images/' . $new_filename;

            if ($current['header_image'] != 'images/background-image.jpg') {
                $old_image = ROOT_PATH . "realiving_user/" . $current['header_image'];
                if (file_exists($old_image)) {
                    unlink($old_image);
                }
            }
        }
    }

    $stmt = $conn->prepare("UPDATE news_header SET header_image=?, title=?, subtitle=? WHERE id=1");
    $stmt->bind_param("sss", $header_image, $title, $subtitle);
    $stmt->execute();

    $success_message = "Header updated successfully!";
}

$header = $conn->query("SELECT * FROM news_header LIMIT 1")->fetch_assoc();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage News Header - RealLiving</title>
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

    /* ── Breadcrumb / back link ─────────────── */
    .adm-back {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      font-size: 12.5px;
      font-weight: 600;
      color: var(--adm-soft);
      text-decoration: none;
      margin-bottom: 14px;
      transition: color .18s ease;
    }

    .adm-back:hover {
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

    /* ── Alerts ─────────────────────────────── */
    .adm-alert {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 13px 16px;
      border-radius: 10px;
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 22px;
    }

    .adm-alert-success {
      color: #1e5631;
      background: #e6f4ea;
      border: 1px solid #c5e6d0;
    }

    .adm-alert i {
      font-size: 14px;
    }

    /* ── Panel ──────────────────────────────── */
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
      padding: 24px;
    }

    /* ── Form ───────────────────────────────── */
    .adm-form-group {
      margin-bottom: 22px;
    }

    .adm-form-group:last-of-type {
      margin-bottom: 0;
    }

    .adm-label {
      display: block;
      font-size: 12.5px;
      font-weight: 700;
      color: var(--adm-ink);
      margin-bottom: 8px;
    }

    .adm-label .adm-required {
      color: #b3261e;
      margin-left: 2px;
    }

    .adm-input,
    .adm-textarea {
      width: 100%;
      padding: 11px 13px;
      background: var(--adm-surface2);
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      font-family: 'Inter', sans-serif;
      font-size: 13.5px;
      color: var(--adm-ink);
      transition: border-color .18s ease, background .18s ease;
    }

    .adm-input:focus,
    .adm-textarea:focus {
      outline: none;
      border-color: var(--adm-ink);
      background: var(--adm-surface);
    }

    .adm-textarea {
      min-height: 100px;
      resize: vertical;
      line-height: 1.5;
    }

    .adm-help {
      font-size: 11.5px;
      color: var(--adm-soft);
      margin-top: 6px;
    }

    /* ── File upload ────────────────────────── */
    .adm-upload {
      position: relative;
      border: 1.5px dashed var(--adm-line);
      border-radius: 10px;
      background: var(--adm-surface2);
      padding: 22px;
      text-align: center;
      transition: border-color .18s ease, background .18s ease;
      cursor: pointer;
    }

    .adm-upload:hover {
      border-color: var(--adm-ink);
      background: var(--adm-bg);
    }

    .adm-upload input[type="file"] {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      opacity: 0;
      cursor: pointer;
    }

    .adm-upload-icon {
      width: 40px;
      height: 40px;
      margin: 0 auto 10px;
      border-radius: 9px;
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      color: var(--adm-ink);
    }

    .adm-upload-text {
      font-size: 13px;
      font-weight: 600;
      color: var(--adm-ink);
      margin-bottom: 3px;
    }

    .adm-upload-hint {
      font-size: 11.5px;
      color: var(--adm-soft);
    }

    #file-chosen-name {
      display: none;
      margin-top: 10px;
      font-size: 12px;
      font-weight: 600;
      color: var(--adm-ink);
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 999px;
      padding: 5px 12px;
    }

    /* ── Header preview ─────────────────────── */
    .header-preview {
      position: relative;
      height: 200px;
      background-size: cover;
      background-position: center;
      background-color: var(--adm-bg);
      border-radius: 8px;
      overflow: hidden;
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
      max-width: 460px;
    }

    .adm-hint {
      font-size: 12.5px;
      color: var(--adm-soft);
      line-height: 1.5;
      margin-top: 12px;
    }

    /* ── Buttons ────────────────────────────── */
    .adm-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 10px 22px;
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

    .adm-form-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      margin-top: 28px;
      padding-top: 22px;
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

    @media (max-width: 900px) {
      .adm-two-col {
        grid-template-columns: 1fr !important;
      }
    }
  </style>
</head>

<body class="min-h-screen flex flex-col">

  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">

    <!-- Page Header -->
    <div class="mb-8 adm-fade">
      <a href="news-dashboard" class="adm-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
      <div class="adm-eyebrow mb-2">Sales &amp; Marketing</div>
      <h1 class="adm-title">Manage News Header</h1>
      <p class="adm-subtitle mt-1">Update the background image, title, and subtitle shown at the top of the news page.</p>
    </div>

    <?php if (isset($success_message)): ?>
      <div class="adm-alert adm-alert-success adm-fade">
        <i class="fas fa-circle-check"></i>
        <?php echo htmlspecialchars($success_message); ?>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 adm-fade">

      <!-- Left Column: Form -->
      <div class="lg:col-span-2">
        <div class="adm-panel">
          <div class="adm-panel-head">
            <h2>Header Details</h2>
          </div>
          <div class="adm-panel-body">
            <form method="POST" enctype="multipart/form-data">

              <div class="adm-form-group">
                <label class="adm-label">Page Title<span class="adm-required">*</span></label>
                <input type="text" name="title" class="adm-input"
                  value="<?php echo htmlspecialchars($header['title']); ?>" required>
              </div>

              <div class="adm-form-group">
                <label class="adm-label">Page Subtitle<span class="adm-required">*</span></label>
                <textarea name="subtitle" class="adm-textarea" required><?php echo htmlspecialchars($header['subtitle']); ?></textarea>
              </div>

              <div class="adm-form-group">
                <label class="adm-label">Header Background Image</label>
                <div class="adm-upload" id="upload-zone">
                  <input type="file" name="header_image" id="header_image" accept="image/*">
                  <div class="adm-upload-icon"><i class="fas fa-cloud-arrow-up"></i></div>
                  <div class="adm-upload-text">Click or drop an image to upload</div>
                  <div class="adm-upload-hint">Recommended size: 1920 × 400px · JPG, PNG or GIF</div>
                  <div id="file-chosen-name"><i class="fas fa-paperclip"></i> <span id="file-chosen-label"></span></div>
                </div>
                <p class="adm-help">Uploaded images are automatically optimized and converted to WebP.</p>
              </div>

              <div class="adm-form-actions">
                <a href="news-dashboard" class="adm-btn adm-btn-outline">Cancel</a>
                <button type="submit" class="adm-btn adm-btn-primary">
                  <i class="fas fa-check"></i> Save Changes
                </button>
              </div>

            </form>
          </div>
        </div>
      </div>

      <!-- Right Column: Live Preview -->
      <div class="flex flex-col gap-5">
        <div class="adm-panel">
          <div class="adm-panel-head">
            <h2>Current Preview</h2>
          </div>
          <div class="adm-panel-body">
            <div class="header-preview" id="preview-box"
              style="background-image: url('<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($header['header_image']); ?>')">
              <div class="header-overlay">
                <h3 id="preview-title"><?php echo htmlspecialchars($header['title']); ?></h3>
                <p id="preview-subtitle"><?php echo htmlspecialchars($header['subtitle']); ?></p>
              </div>
            </div>
            <p class="adm-hint">This is how the header currently appears on the live news page. Changes are reflected here after saving.</p>
          </div>
        </div>
      </div>

    </div>

  </div>

  <script>
    // Show chosen filename in the upload zone
    const fileInput = document.getElementById('header_image');
    const fileChosen = document.getElementById('file-chosen-name');
    const fileChosenLabel = document.getElementById('file-chosen-label');

    fileInput.addEventListener('change', function () {
      if (this.files && this.files.length > 0) {
        fileChosenLabel.textContent = this.files[0].name;
        fileChosen.style.display = 'inline-flex';

        // Live preview of the newly selected image
        const reader = new FileReader();
        reader.onload = function (e) {
          document.getElementById('preview-box').style.backgroundImage = "url('" + e.target.result + "')";
        };
        reader.readAsDataURL(this.files[0]);
      }
    });

    // Live preview of title/subtitle as the admin types
    const titleInput = document.querySelector('input[name="title"]');
    const subtitleInput = document.querySelector('textarea[name="subtitle"]');
    const previewTitle = document.getElementById('preview-title');
    const previewSubtitle = document.getElementById('preview-subtitle');

    titleInput.addEventListener('input', function () {
      previewTitle.textContent = this.value;
    });

    subtitleInput.addEventListener('input', function () {
      previewSubtitle.textContent = this.value;
    });
  </script>

</body>

</html>