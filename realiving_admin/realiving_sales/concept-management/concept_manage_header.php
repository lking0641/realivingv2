<?php
//concept_manage_header.php
include $includes ['mainbody'];

// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales']);


// Function to convert image to WebP
function convertToWebP($source, $destination, $quality = 90) {
    $info = @getimagesize($source);

    if ($info === false) {
        return "Invalid image file or unable to read image info.";
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
            return "Unsupported image type: " . $info['mime'];
    }

    if (!$image) {
        return "Failed to create image resource from uploaded file.";
    }

    if (!imagewebp($image, $destination, $quality)) {
        imagedestroy($image);
        return "Failed to write WebP file. Check folder permissions.";
    }

    imagedestroy($image);
    return true; // success
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = $_POST['title'];
    $subtitle = $_POST['subtitle'];
    
    // Get current settings
    $current = $conn->query("SELECT * FROM concept_header LIMIT 1")->fetch_assoc();
    $header_image = $current['header_image'];
    
    // Handle image upload
    $upload_error = null;
    if (isset($_FILES['header_image']) && $_FILES['header_image']['error'] !== UPLOAD_ERR_NO_FILE) {

        if ($_FILES['header_image']['error'] !== UPLOAD_ERR_OK) {
            $upload_error = "Upload error code: " . $_FILES['header_image']['error'];
        } else {
            $target_dir = ROOT_PATH . "realiving_user/images/";

            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }

            if (!is_writable($target_dir)) {
                $upload_error = "Target folder is not writable: " . $target_dir;
            } else {
                $new_filename = 'concept_header_' . time() . '.webp';
                $target_file = $target_dir . $new_filename;

                // Convert to WebP
                $conversion_result = convertToWebP($_FILES['header_image']['tmp_name'], $target_file, 90);

                if ($conversion_result === true) {
                    $header_image = 'images/' . $new_filename;

                    // Delete old image if exists and not default
                    if ($current['header_image'] != 'images/background-image.jpg') {
                        $old_image = ROOT_PATH . "realiving_user/" . $current['header_image'];
                        if (file_exists($old_image)) {
                            unlink($old_image);
                        }
                    }
                } else {
                    $upload_error = $conversion_result;
                }
            }
        }
    }
    
    // Update database
    $stmt = $conn->prepare("UPDATE concept_header SET header_image=?, title=?, subtitle=? WHERE id=1");
    $stmt->bind_param("sss", $header_image, $title, $subtitle);

    if ($stmt->execute()) {
        $success_message = "Header updated successfully!";
        if ($upload_error) {
            $success_message .= " (Note: image upload issue — " . $upload_error . ")";
        }
    } else {
        $error_message = "Database update failed: " . $stmt->error;
    }
}

// Get current settings
$header = $conn->query("SELECT * FROM concept_header LIMIT 1")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Concept Header - RealLiving</title>
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

    /* ── Alerts ─────────────────────────────── */
    .adm-alert {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 18px;
      border-radius: 10px;
      margin-bottom: 20px;
      font-size: 13.5px;
      font-weight: 500;
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-left: 3px solid var(--adm-ink);
      color: var(--adm-ink);
    }

    .adm-alert.is-error {
      border-left-color: #9b1c1c;
    }

    .adm-alert.is-error i {
      color: #9b1c1c;
    }

    /* ── Panel / form card ──────────────────── */
    .adm-panel {
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      overflow: hidden;
    }

    .adm-panel-head {
      padding: 18px 22px;
      border-bottom: 1px solid var(--adm-line);
    }

    .adm-panel-head h2 {
      font-size: 15px;
      font-weight: 700;
      color: var(--adm-ink);
    }

    .adm-panel-body {
      padding: 24px 22px;
    }

    /* ── Form ───────────────────────────────── */
    .form-group {
      margin-bottom: 24px;
    }

    .form-group label {
      display: block;
      font-size: 11.5px;
      font-weight: 600;
      color: var(--adm-soft);
      text-transform: uppercase;
      letter-spacing: .4px;
      margin-bottom: 8px;
    }

    .form-control {
      width: 100%;
      padding: 11px 14px;
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
      min-height: 100px;
      resize: vertical;
      font-family: inherit;
    }

    input[type="file"].form-control {
      padding: 9px 12px;
      background: var(--adm-surface2);
      cursor: pointer;
    }

    .help-text {
      font-size: 12px;
      color: var(--adm-muted);
      margin-top: 6px;
    }

    .current-image-section {
      margin-top: 16px;
      padding: 16px;
      background: var(--adm-surface2);
      border: 1px solid var(--adm-line);
      border-radius: 8px;
    }

    .current-image-section h4 {
      font-size: 11.5px;
      font-weight: 700;
      color: var(--adm-soft);
      text-transform: uppercase;
      letter-spacing: .4px;
      margin-bottom: 12px;
    }

    .current-image {
      max-width: 100%;
      width: 100%;
      height: 200px;
      object-fit: cover;
      border-radius: 8px;
      border: 1px solid var(--adm-line);
      display: block;
    }

    .adm-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 11px 24px;
      border-radius: 8px;
      font-size: 13.5px;
      font-weight: 600;
      border: 1px solid transparent;
      cursor: pointer;
      text-decoration: none;
      transition: all .18s ease;
      font-family: 'Inter', sans-serif;
    }

    .adm-btn-primary {
      background: var(--adm-ink);
      color: #fff;
    }

    .adm-btn-primary:hover {
      background: #262626;
    }

    /* ── Preview ────────────────────────────── */
    .preview-section {
      margin-top: 24px;
    }

    .preview-box {
      position: relative;
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid var(--adm-line);
      min-height: 180px;
      background-size: cover;
      background-position: center;
      background-color: var(--adm-bg);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .preview-overlay {
      position: absolute;
      inset: 0;
      background: rgba(11, 11, 11, .55);
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      padding: 30px 24px;
      text-align: center;
    }

    .preview-title {
      font-size: 24px;
      font-weight: 700;
      color: #fff;
      margin-bottom: 10px;
    }

    .preview-subtitle {
      font-size: 13.5px;
      color: rgba(255, 255, 255, .85);
      line-height: 1.6;
      max-width: 560px;
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

  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-3xl mx-auto w-full">

    <!-- Header -->
    <div class="mb-8 adm-fade adm-header-row">
      <div>
        <div class="adm-eyebrow mb-2">Sales &amp; Marketing</div>
        <h1 class="adm-title">Manage Concept Header</h1>
        <p class="adm-subtitle mt-1">Update the header image, title, and subtitle for the Concept Designs page.</p>
      </div>
      <a href="concept-dashboard" class="adm-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <?php if (isset($success_message)): ?>
      <div class="adm-alert adm-fade">
        <i class="fa-solid fa-circle-check" style="color:var(--adm-ink);"></i>
        <span><?php echo $success_message; ?></span>
      </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
      <div class="adm-alert is-error adm-fade">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?php echo htmlspecialchars($error_message); ?></span>
      </div>
    <?php endif; ?>

    <!-- Form -->
    <div class="adm-panel adm-fade">
      <div class="adm-panel-head">
        <h2>Header Details</h2>
      </div>
      <div class="adm-panel-body">
        <form method="POST" enctype="multipart/form-data">
          <div class="form-group">
            <label>Page Title</label>
            <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($header['title']); ?>" required>
            <p class="help-text">The main heading displayed on the page</p>
          </div>

          <div class="form-group">
            <label>Page Subtitle</label>
            <textarea name="subtitle" class="form-control" required><?php echo htmlspecialchars($header['subtitle']); ?></textarea>
            <p class="help-text">The descriptive text below the title</p>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label>Header Background Image</label>
            <input type="file" name="header_image" accept="image/*" class="form-control">
            <p class="help-text">Upload a new header background image (JPG/PNG will be converted to WebP). Recommended size: 1920x400px</p>

            <div class="current-image-section">
              <h4>Current Header Image</h4>
              <img src="<?php echo CLIENT_ASSET; ?>/<?php echo htmlspecialchars($header['header_image']); ?>" class="current-image" alt="Current Header">
            </div>
          </div>

          <button type="submit" class="adm-btn adm-btn-primary" style="margin-top:24px;"><i class="fas fa-check"></i> Save Changes</button>
        </form>
      </div>
    </div>

    <!-- Preview -->
    <div class="preview-section adm-fade">
      <div class="adm-section-label mb-4">Live Preview</div>
      <div class="preview-box" style="background-image:url('<?php echo CLIENT_ASSET; ?>/<?php echo htmlspecialchars($header['header_image']); ?>')">
        <div class="preview-overlay">
          <div class="preview-title"><?php echo htmlspecialchars($header['title']); ?></div>
          <div class="preview-subtitle"><?php echo htmlspecialchars($header['subtitle']); ?></div>
        </div>
      </div>
    </div>

  </div>
</body>

</html>