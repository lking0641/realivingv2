<?php
// add_product_details.php
include $includes ['mainbody'];


// Allow only admin1 to admin5
require_role(['admin1','superadmin', 'sales', 'designer']);

// Extra check: if designer, only heads can access
if ($_SESSION['admin_role'] === 'designer') {
    $headCheck = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
    $headCheck->bind_param("i", $_SESSION['admin_id']);
    $headCheck->execute();
    $headRow = $headCheck->get_result()->fetch_assoc();
    $headCheck->close();

    if (empty($headRow['is_head'])) {
        $_SESSION['noti'] = 'Access Denied: Only head designers can access this page.';
        header("Location: ../../realiving_admin/tracker_site_visit/designer_layout_list.php");
        exit();
    }
}

// Get product ID from URL
$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($product_id <= 0) {
    header("Location: view_products.php");
    exit();
}

// Fetch product information
$product_query = "SELECT i.*, dl.dimension_label_name, 
                  dl.item_width_label_linear, dl.item_height_label_linear, dl.item_length_label_linear
                  FROM items i 
                  LEFT JOIN dimension_label dl ON i.dimension_label_fk = dl.dimension_label_id
                  WHERE i.item_id = ?";
$stmt = $conn->prepare($product_query);
$stmt->bind_param("i", $product_id);
$stmt->execute();
$product_result = $stmt->get_result();

if ($product_result->num_rows === 0) {
    header("Location: view_products.php");
    exit();
}

$product = $product_result->fetch_assoc();
$stmt->close();

// Fetch existing visual assets
$existing_visual_assets = [];
$visual_query = "SELECT * FROM item_visual_assets WHERE item_fk = ? ORDER BY asset_type, display_order";
$stmt = $conn->prepare($visual_query);
$stmt->bind_param("i", $product_id);
$stmt->execute();
$visual_result = $stmt->get_result();
while ($row = $visual_result->fetch_assoc()) {
    $existing_visual_assets[] = $row;
}
$stmt->close();

// Fetch existing technical specifications
$existing_specs = [];
$specs_query = "SELECT * FROM item_technical_specifications WHERE item_fk = ? ORDER BY display_order";
$stmt = $conn->prepare($specs_query);
$stmt->bind_param("i", $product_id);
$stmt->execute();
$specs_result = $stmt->get_result();
while ($row = $specs_result->fetch_assoc()) {
    $existing_specs[] = $row;
}
$stmt->close();

// Fetch existing features
$existing_features = [];
$features_query = "SELECT * FROM item_features WHERE item_fk = ? ORDER BY display_order";
$stmt = $conn->prepare($features_query);
$stmt->bind_param("i", $product_id);
$stmt->execute();
$features_result = $stmt->get_result();
while ($row = $features_result->fetch_assoc()) {
    $existing_features[] = $row['feature_name'];
}
$stmt->close();

// Function to handle file upload
function handleFileUpload($file, $folder_name) {
    if (isset($file) && $file['error'] === UPLOAD_ERR_OK) {
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');
        
        if (in_array($file_extension, $allowed_extensions)) {
            if ($file['size'] <= 5242880) { // 5MB limit
                $upload_dir = ROOT_PATH . 'realiving_user/images/' . $folder_name . '/';
                
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $unique_filename = uniqid() . '_' . time() . '.webp';
                $target_file = $upload_dir . $unique_filename;
                $temp_file = $upload_dir . 'temp_' . $unique_filename;
                
                if (move_uploaded_file($file['tmp_name'], $temp_file)) {
                    if (convertToWebP($temp_file, $target_file, $file_extension)) {
                        unlink($temp_file);
                        return $unique_filename;
                    } else {
                        unlink($temp_file);
                    }
                }
            }
        }
    }
    return null;
}

function convertToWebP($source, $destination, $source_extension) {
    $image = null;
    
    switch ($source_extension) {
        case 'jpg':
        case 'jpeg':
            $image = imagecreatefromjpeg($source);
            break;
        case 'png':
            $image = imagecreatefrompng($source);
            imagealphablending($image, true);
            imagesavealpha($image, true);
            break;
        case 'gif':
            $image = imagecreatefromgif($source);
            break;
        case 'webp':
            return copy($source, $destination);
    }
    
    if ($image !== null) {
        $result = imagewebp($image, $destination, 80);
        imagedestroy($image);
        return $result;
    }
    
    return false;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->autocommit(FALSE);
    
    try {
        // DELETE EXISTING DATA FIRST (for update functionality)
        
        // 1. ONLY Delete visual assets if new images are uploaded
        // Check if ANY new images were uploaded
        $new_images_uploaded = false;
        for ($i = 1; $i <= 2; $i++) {
            if (isset($_FILES["finish_closeup_$i"]) && $_FILES["finish_closeup_$i"]['error'] === UPLOAD_ERR_OK) {
                $new_images_uploaded = true;
                break;
            }
            if (isset($_FILES["installed_photo_$i"]) && $_FILES["installed_photo_$i"]['error'] === UPLOAD_ERR_OK) {
                $new_images_uploaded = true;
                break;
            }
        }
        
        // Only delete visual assets if new images are being uploaded
        if ($new_images_uploaded) {
            $delete_visual = $conn->prepare("DELETE FROM item_visual_assets WHERE item_fk = ?");
            $delete_visual->bind_param("i", $product_id);
            $delete_visual->execute();
            $delete_visual->close();
        }
        
        // 2. Delete existing technical specifications
        $delete_specs = $conn->prepare("DELETE FROM item_technical_specifications WHERE item_fk = ?");
        $delete_specs->bind_param("i", $product_id);
        $delete_specs->execute();
        $delete_specs->close();
        
        // 3. Delete existing features
        $delete_features = $conn->prepare("DELETE FROM item_features WHERE item_fk = ?");
        $delete_features->bind_param("i", $product_id);
        $delete_features->execute();
        $delete_features->close();
        
        // NOW INSERT NEW DATA
        
        // 1. Handle Visual Assets - Update individual images only if new ones are uploaded
        $visual_assets_uploaded = 0;
        
        // Process finish close-up images
        for ($i = 1; $i <= 2; $i++) {
            if (isset($_FILES["finish_closeup_$i"]) && $_FILES["finish_closeup_$i"]['error'] === UPLOAD_ERR_OK) {
                // New image uploaded for this slot
                $image_path = handleFileUpload($_FILES["finish_closeup_$i"], 'product_closeups');
                if ($image_path) {
                    // Delete existing image for this specific slot
                    $delete_slot = $conn->prepare("DELETE FROM item_visual_assets WHERE item_fk = ? AND asset_type = 'finish_closeup' AND display_order = ?");
                    $delete_slot->bind_param("ii", $product_id, $i);
                    $delete_slot->execute();
                    $delete_slot->close();
                    
                    // Insert new image
                    $caption = $_POST["finish_closeup_{$i}_caption"] ?? '';
                    $stmt = $conn->prepare("INSERT INTO item_visual_assets (item_fk, asset_type, asset_image_path, asset_caption, display_order) VALUES (?, 'finish_closeup', ?, ?, ?)");
                    $stmt->bind_param("issi", $product_id, $image_path, $caption, $i);
                    $stmt->execute();
                    $stmt->close();
                    $visual_assets_uploaded++;
                }
            } else {
                // No new image uploaded, just update caption if it exists
                if (isset($_POST["finish_closeup_{$i}_caption"])) {
                    $caption = $_POST["finish_closeup_{$i}_caption"];
                    $update_caption = $conn->prepare("UPDATE item_visual_assets SET asset_caption = ? WHERE item_fk = ? AND asset_type = 'finish_closeup' AND display_order = ?");
                    $update_caption->bind_param("sii", $caption, $product_id, $i);
                    $update_caption->execute();
                    $update_caption->close();
                }
            }
        }
        
        // Process installed photo images
        for ($i = 1; $i <= 2; $i++) {
            if (isset($_FILES["installed_photo_$i"]) && $_FILES["installed_photo_$i"]['error'] === UPLOAD_ERR_OK) {
                // New image uploaded for this slot
                $image_path = handleFileUpload($_FILES["installed_photo_$i"], 'product_installed');
                if ($image_path) {
                    // Delete existing image for this specific slot
                    $delete_slot = $conn->prepare("DELETE FROM item_visual_assets WHERE item_fk = ? AND asset_type = 'installed_photo' AND display_order = ?");
                    $delete_slot->bind_param("ii", $product_id, $i);
                    $delete_slot->execute();
                    $delete_slot->close();
                    
                    // Insert new image
                    $caption = $_POST["installed_photo_{$i}_caption"] ?? '';
                    $stmt = $conn->prepare("INSERT INTO item_visual_assets (item_fk, asset_type, asset_image_path, asset_caption, display_order) VALUES (?, 'installed_photo', ?, ?, ?)");
                    $stmt->bind_param("issi", $product_id, $image_path, $caption, $i);
                    $stmt->execute();
                    $stmt->close();
                    $visual_assets_uploaded++;
                }
            } else {
                // No new image uploaded, just update caption if it exists
                if (isset($_POST["installed_photo_{$i}_caption"])) {
                    $caption = $_POST["installed_photo_{$i}_caption"];
                    $update_caption = $conn->prepare("UPDATE item_visual_assets SET asset_caption = ? WHERE item_fk = ? AND asset_type = 'installed_photo' AND display_order = ?");
                    $update_caption->bind_param("sii", $caption, $product_id, $i);
                    $update_caption->execute();
                    $update_caption->close();
                }
            }
        }
        
        // Process installed photo images
        for ($i = 1; $i <= 2; $i++) {
            if (isset($_FILES["installed_photo_$i"]) && $_FILES["installed_photo_$i"]['error'] === UPLOAD_ERR_OK) {
                $image_path = handleFileUpload($_FILES["installed_photo_$i"], 'product_installed');
                if ($image_path) {
                    $caption = $_POST["installed_photo_{$i}_caption"] ?? '';
                    $stmt = $conn->prepare("INSERT INTO item_visual_assets (item_fk, asset_type, asset_image_path, asset_caption, display_order) VALUES (?, 'installed_photo', ?, ?, ?)");
                    $stmt->bind_param("issi", $product_id, $image_path, $caption, $i);
                    $stmt->execute();
                    $stmt->close();
                    $visual_assets_uploaded++;
                }
            }
        }
        
        // 2. Handle Technical Specifications
        $specs_added = 0;
        if (isset($_POST['spec_titles']) && is_array($_POST['spec_titles'])) {
            foreach ($_POST['spec_titles'] as $index => $title) {
                $description = $_POST['spec_descriptions'][$index] ?? '';
                if (!empty($title) && !empty($description)) {
                    $order = $index + 1;
                    $stmt = $conn->prepare("INSERT INTO item_technical_specifications (item_fk, spec_title, spec_description, display_order) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("issi", $product_id, $title, $description, $order);
                    $stmt->execute();
                    $stmt->close();
                    $specs_added++;
                }
            }
        }
        
        // 3. Handle Features (comma-separated input)
        $features_added = 0;
        if (isset($_POST['features']) && !empty($_POST['features'])) {
            $features = explode(',', $_POST['features']);
            $order = 1;
            foreach ($features as $feature) {
                $feature = trim($feature);
                if (!empty($feature)) {
                    $stmt = $conn->prepare("INSERT INTO item_features (item_fk, feature_name, display_order) VALUES (?, ?, ?)");
                    $stmt->bind_param("isi", $product_id, $feature, $order);
                    $stmt->execute();
                    $stmt->close();
                    $features_added++;
                    $order++;
                }
            }
        }
        
        $conn->commit();
        
        // Redirect to view_products.php with success message
        $_SESSION['success_message'] = "Product details updated successfully!";
        $_SESSION['success_details'] = array(
    'visual_assets' => $visual_assets_uploaded,
    'specifications' => $specs_added,
    'features' => $features_added
);
        
        header("Location: view_products.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = $e->getMessage();
    }
    
    $conn->autocommit(TRUE);
}

$conn->close();

$has_existing = !empty($existing_specs) || !empty($existing_features) || !empty($existing_visual_assets);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $has_existing ? 'Edit' : 'Add'; ?> Product Details - <?php echo htmlspecialchars($product['item_name']); ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="../../logo/favicon.ico">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
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
        .adm-eyebrow{ font-size:11px; font-weight:600; letter-spacing:1.5px; text-transform:uppercase; color: var(--adm-soft); }
        .adm-title{ font-size:26px; font-weight:700; letter-spacing:-0.01em; color: var(--adm-ink); }
        .adm-subtitle{ font-size:13px; color: var(--adm-soft); }
        .adm-back{
            font-size:12.5px; font-weight:600; color: var(--adm-soft);
            display:inline-flex; align-items:center; gap:8px;
            margin-bottom:1rem;
            transition: color .2s ease, gap .2s ease;
        }
        .adm-back:hover{ color: var(--adm-ink); gap:11px; }

        /* ── Buttons ────────────────────────────── */
        .adm-btn{
            display:inline-flex; align-items:center; gap:8px;
            background: var(--adm-ink); color:#fff;
            font-size:13px; font-weight:600;
            padding:.9rem 1.5rem; border-radius:9px;
            border:1px solid var(--adm-ink);
            transition: opacity .2s ease, transform .2s ease;
        }
        .adm-btn:hover{ opacity:.85; transform: translateY(-1px); color:#fff; }
        .adm-btn-outline{
            display:inline-flex; align-items:center; gap:8px;
            background: var(--adm-surface); color: var(--adm-ink);
            font-size:13px; font-weight:600;
            padding:.85rem 1.4rem; border-radius:9px;
            border:1px solid var(--adm-line);
            transition: border-color .2s ease, transform .2s ease;
        }
        .adm-btn-outline:hover{ border-color: var(--adm-ink); transform: translateY(-1px); }

        /* ── Cards / sections ───────────────────── */
        .adm-card{
            background: var(--adm-surface);
            border:1px solid var(--adm-line);
            border-radius:12px;
        }

        .adm-section-icon{
            width:34px; height:34px; border-radius:9px;
            background: var(--adm-ink); color:#fff;
            display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0;
        }
        .adm-section-title{ font-size:15px; font-weight:700; color: var(--adm-ink); }
        .adm-section-hint{ font-size:12px; color: var(--adm-muted); }

        .adm-stat-chip{
            display:inline-flex; align-items:center; gap:6px;
            font-size:11.5px; font-weight:600; color: var(--adm-ink);
            background: var(--adm-bg); border:1px solid var(--adm-line);
            padding:.4rem .75rem; border-radius:8px;
        }
        .adm-stat-chip i{ color:#16A34A; }

        /* ── Form elements ──────────────────────── */
        .adm-label{ font-size:11.5px; font-weight:700; letter-spacing:.3px; text-transform:uppercase; color: var(--adm-soft); display:block; margin-bottom:.45rem; }
        .adm-input, .adm-select, textarea.adm-input{
            width:100%;
            padding:.75rem .9rem; border-radius:9px;
            border:1px solid var(--adm-line); background: var(--adm-bg);
            font-size:13.5px; font-weight:500; color: var(--adm-ink);
            transition: border-color .2s ease, background .2s ease;
        }
        .adm-input:focus, .adm-select:focus{ outline:none; border-color: var(--adm-ink); background: var(--adm-surface); }
        .adm-hint{ font-size:11.5px; color: var(--adm-muted); margin-top:.4rem; }
        .adm-field{ margin-bottom: 0; }

        .adm-subcard{
            background: var(--adm-bg); border:1px solid var(--adm-line); border-radius:10px; padding:1.25rem;
        }
        .adm-subcard-title{
            font-size:13px; font-weight:700; color: var(--adm-ink);
            display:flex; align-items:center; gap:.5rem; margin-bottom:1.1rem;
        }
        .adm-subcard-title i{ color: var(--adm-soft); font-size:12px; }

        /* ── Existing-asset notice ──────────────── */
        .adm-existing-note{
            background: var(--adm-surface); border:1px solid var(--adm-line); border-left:3px solid var(--adm-ink);
            border-radius:10px; padding:.9rem 1rem; margin-bottom:1.1rem;
        }
        .adm-existing-note p{ font-size:11.5px; font-weight:600; color: var(--adm-soft); margin-bottom:.6rem; display:flex; align-items:center; gap:6px; }
        .adm-existing-thumb{ width:56px; height:56px; border-radius:8px; overflow:hidden; border:1px solid var(--adm-line); flex-shrink:0; }
        .adm-existing-thumb img{ width:100%; height:100%; object-fit:cover; display:block; }
        .adm-existing-caption{ font-size:12px; color: var(--adm-muted); }

        /* ── Upload ─────────────────────────────── */
        .adm-upload-box{
            position:relative;
            width:100px; height:100px; flex-shrink:0;
            display:flex; flex-direction:column; align-items:center; justify-content:center; gap:.3rem;
            border:1.5px dashed var(--adm-line); border-radius:11px;
            background: var(--adm-surface); color: var(--adm-muted);
            transition: border-color .2s ease, background .2s ease, color .2s ease;
        }
        .adm-upload-box:hover{ border-color: var(--adm-ink); color: var(--adm-ink); background:#EFEFEF; }
        .adm-upload-box input[type="file"]{
            position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%;
        }
        .adm-upload-box i{ font-size:18px; }
        .adm-upload-box span{ font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }

        .adm-image-preview{ display:none; }
        .adm-image-preview.active{ display:block; }
        .adm-preview-frame{
            position:relative;
            width:100px; height:100px; border-radius:11px; overflow:hidden;
            border:1px solid var(--adm-line); background: var(--adm-bg);
        }
        .adm-preview-frame img{ width:100%; height:100%; object-fit:cover; display:block; }
        .adm-preview-remove{
            position:absolute; top:-8px; right:-8px;
            width:22px; height:22px; border-radius:999px;
            background: var(--adm-ink); color:#fff;
            display:flex; align-items:center; justify-content:center;
            font-size:10px; border:2px solid var(--adm-surface);
            transition: background .2s ease;
        }
        .adm-preview-remove:hover{ background:#DC2626; }

        /* ── Spec card ──────────────────────────── */
        .adm-spec-card{
            background: var(--adm-bg); border:1px solid var(--adm-line);
            border-radius:10px; padding:1.1rem;
        }
        .adm-spec-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; }
        .adm-spec-tag{ display:inline-flex; align-items:center; gap:8px; font-size:12.5px; font-weight:700; color: var(--adm-ink); }
        .adm-spec-dot{
            width:26px; height:26px; border-radius:999px;
            background: var(--adm-ink); color:#fff;
            display:flex; align-items:center; justify-content:center; font-size:11px;
        }
        .adm-remove-btn{
            display:inline-flex; align-items:center; gap:6px;
            font-size:11px; font-weight:700; color:#DC2626;
            background: var(--adm-surface); border:1px solid #FECACA;
            padding:.4rem .75rem; border-radius:7px;
            transition: background .2s ease;
        }
        .adm-remove-btn:hover{ background:#FEF2F2; }

        /* ── Alerts ─────────────────────────────── */
        .adm-alert-error{
            display:flex; align-items:flex-start; gap:.9rem;
            background: var(--adm-surface);
            border:1px solid var(--adm-line); border-left:3px solid #DC2626;
            border-radius:10px; padding:1.1rem 1.25rem;
        }
        .adm-alert-icon-error{
            width:36px; height:36px; border-radius:999px; flex-shrink:0;
            background:#FEF2F2; color:#DC2626;
            display:flex; align-items:center; justify-content:center; font-size:15px;
        }

        .adm-empty-note{ font-size:13px; text-align:center; padding:2.5rem 1rem; color: var(--adm-muted); }

        @keyframes adm-fade{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }
        .adm-fade{ animation: adm-fade .4s ease both; }
        @media (prefers-reduced-motion: reduce){ .adm-fade{ animation:none; } }
    </style>
</head>
<body class="min-h-screen">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-10 max-w-6xl">

        <!-- Header -->
        <div class="adm-card p-6 sm:p-8 mb-6 adm-fade">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <a href="<?= BASE_URL ?>view-products" class="adm-back">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Products</span>
                    </a>
                    <div class="adm-eyebrow mb-2">Catalog / Details</div>
                    <h1 class="adm-title"><?php echo $has_existing ? 'Edit' : 'Add'; ?> Product Details</h1>
                    <p class="adm-subtitle mt-1">
                        <span class="font-semibold" style="color:var(--adm-ink);"><?php echo htmlspecialchars($product['item_name']); ?></span>
                        <span style="color:var(--adm-muted);"> &middot; </span>
                        <?php echo htmlspecialchars($product['item_code']); ?>
                    </p>
                </div>
            </div>
        </div>

        <?php if (isset($error_message)): ?>
        <div class="adm-alert-error mb-6 adm-fade">
            <div class="adm-alert-icon-error"><i class="fas fa-triangle-exclamation"></i></div>
            <div class="flex-1">
                <h3 class="text-sm font-bold mb-1" style="color:var(--adm-ink);">Error saving product details</h3>
                <p class="text-xs" style="color:var(--adm-soft);"><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">

            <!-- 1. Visual Assets Section -->
            <div class="adm-card p-6 sm:p-8 adm-fade">
                <div class="flex items-center gap-3 mb-6 flex-wrap">
                    <div class="adm-section-icon"><i class="fas fa-images"></i></div>
                    <div class="flex-1">
                        <div class="adm-section-title">Visual Assets (4 Images)</div>
                        <div class="adm-section-hint">Close-ups and installed project photography</div>
                    </div>
                    <?php if (!empty($existing_visual_assets)): ?>
                        <span class="adm-stat-chip"><i class="fas fa-check-circle"></i> <?php echo count($existing_visual_assets); ?> existing</span>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Finish Close-ups (2 images) -->
                    <div class="space-y-4">
                        <div class="adm-subcard-title" style="margin-bottom:.5rem;"><i class="fas fa-camera-retro"></i> Finish Close-ups (2)</div>

                        <?php 
                        $finish_closeups = array_filter($existing_visual_assets, function($asset) {
                            return $asset['asset_type'] === 'finish_closeup';
                        });
                        for ($i = 1; $i <= 2; $i++): 
                            $existing_asset = null;
                            foreach ($finish_closeups as $asset) {
                                if ($asset['display_order'] == $i) {
                                    $existing_asset = $asset;
                                    break;
                                }
                            }
                        ?>
                        <div class="adm-subcard">
                            <div class="adm-subcard-title"><i class="fas fa-image"></i> Close-up <?php echo $i; ?></div>

                            <?php if ($existing_asset): ?>
                            <div class="adm-existing-note">
                                <p><i class="fas fa-circle-info"></i> Existing image — replaced only if you upload a new one</p>
                                <div class="flex items-center gap-3">
                                    <div class="adm-existing-thumb">
                                        <img src="<?= BASE_URL ?>realiving_user/images/product_closeups/<?php echo htmlspecialchars($existing_asset['asset_image_path']); ?>" alt="Existing">
                                    </div>
                                    <div class="adm-existing-caption">
                                        <?php echo !empty($existing_asset['asset_caption']) ? htmlspecialchars($existing_asset['asset_caption']) : 'No caption'; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="space-y-4">
                                <div class="flex items-center gap-4">
                                    <div class="adm-upload-box">
                                        <input type="file" name="finish_closeup_<?php echo $i; ?>" accept="image/*" 
                                               id="finish_closeup_<?php echo $i; ?>"
                                               onchange="previewImage(this, 'preview_finish_<?php echo $i; ?>')">
                                        <i class="fas fa-camera"></i>
                                        <span>Upload <?php echo $existing_asset ? 'New' : ''; ?></span>
                                    </div>

                                    <div id="preview_finish_<?php echo $i; ?>" class="adm-image-preview">
                                        <div class="adm-preview-frame">
                                            <img src="" alt="Preview">
                                            <button type="button" onclick="removeImage('finish_closeup_<?php echo $i; ?>', 'preview_finish_<?php echo $i; ?>')" class="adm-preview-remove">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="adm-field">
                                    <label class="adm-label">Caption (Optional)</label>
                                    <input type="text" name="finish_closeup_<?php echo $i; ?>_caption" 
                                           value="<?php echo $existing_asset ? htmlspecialchars($existing_asset['asset_caption']) : ''; ?>"
                                           class="adm-input"
                                           placeholder="Add a caption for this image">
                                </div>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>

                    <!-- Installed Project Photos (2 images) -->
                    <div class="space-y-4">
                        <div class="adm-subcard-title" style="margin-bottom:.5rem;"><i class="fas fa-house"></i> Installed Project Photos (2)</div>

                        <?php 
                        $installed_photos = array_filter($existing_visual_assets, function($asset) {
                            return $asset['asset_type'] === 'installed_photo';
                        });
                        for ($i = 1; $i <= 2; $i++): 
                            $existing_asset = null;
                            foreach ($installed_photos as $asset) {
                                if ($asset['display_order'] == $i) {
                                    $existing_asset = $asset;
                                    break;
                                }
                            }
                        ?>
                        <div class="adm-subcard">
                            <div class="adm-subcard-title"><i class="fas fa-image"></i> Installed Photo <?php echo $i; ?></div>

                            <?php if ($existing_asset): ?>
                            <div class="adm-existing-note">
                                <p><i class="fas fa-circle-info"></i> Existing image — replaced only if you upload a new one</p>
                                <div class="flex items-center gap-3">
                                    <div class="adm-existing-thumb">
                                        <img src="<?= BASE_URL ?>realiving_user/images/product_installed/<?php echo htmlspecialchars($existing_asset['asset_image_path']); ?>" alt="Existing">
                                    </div>
                                    <div class="adm-existing-caption">
                                        <?php echo !empty($existing_asset['asset_caption']) ? htmlspecialchars($existing_asset['asset_caption']) : 'No caption'; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="space-y-4">
                                <div class="flex items-center gap-4">
                                    <div class="adm-upload-box">
                                        <input type="file" name="installed_photo_<?php echo $i; ?>" accept="image/*" 
                                               id="installed_photo_<?php echo $i; ?>"
                                               onchange="previewImage(this, 'preview_installed_<?php echo $i; ?>')">
                                        <i class="fas fa-camera"></i>
                                        <span>Upload <?php echo $existing_asset ? 'New' : ''; ?></span>
                                    </div>

                                    <div id="preview_installed_<?php echo $i; ?>" class="adm-image-preview">
                                        <div class="adm-preview-frame">
                                            <img src="" alt="Preview">
                                            <button type="button" onclick="removeImage('installed_photo_<?php echo $i; ?>', 'preview_installed_<?php echo $i; ?>')" class="adm-preview-remove">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="adm-field">
                                    <label class="adm-label">Caption (Optional)</label>
                                    <input type="text" name="installed_photo_<?php echo $i; ?>_caption" 
                                           value="<?php echo $existing_asset ? htmlspecialchars($existing_asset['asset_caption']) : ''; ?>"
                                           class="adm-input"
                                           placeholder="Add a caption for this image">
                                </div>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- 2. Technical Specifications Section -->
            <div class="adm-card p-6 sm:p-8 adm-fade">
                <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
                    <div class="flex items-center gap-3">
                        <div class="adm-section-icon"><i class="fas fa-clipboard-list"></i></div>
                        <div>
                            <div class="adm-section-title">Technical Specifications</div>
                            <div class="adm-section-hint">Titled spec entries shown on the product page</div>
                        </div>
                        <?php if (!empty($existing_specs)): ?>
                            <span class="adm-stat-chip"><i class="fas fa-check-circle"></i> <?php echo count($existing_specs); ?> existing</span>
                        <?php endif; ?>
                    </div>
                    <button type="button" onclick="addSpecification()" class="adm-btn-outline">
                        <i class="fas fa-plus"></i>
                        <span>Add Specification</span>
                    </button>
                </div>

                <div id="specificationsContainer" class="space-y-4">
                    <?php if (empty($existing_specs)): ?>
                    <p class="adm-empty-note">No specifications added yet. Click "Add Specification" to get started.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3. Features Section -->
            <div class="adm-card p-6 sm:p-8 adm-fade">
                <div class="flex items-center gap-3 mb-6 flex-wrap">
                    <div class="adm-section-icon"><i class="fas fa-star"></i></div>
                    <div class="flex-1">
                        <div class="adm-section-title">Product Features</div>
                        <div class="adm-section-hint">Short tags describing the product</div>
                    </div>
                    <?php if (!empty($existing_features)): ?>
                        <span class="adm-stat-chip"><i class="fas fa-check-circle"></i> <?php echo count($existing_features); ?> existing</span>
                    <?php endif; ?>
                </div>

                <div class="adm-field">
                    <label class="adm-label">Features (Comma-separated)</label>
                    <textarea name="features" rows="4" class="adm-input"
                        placeholder="Enter features separated by commas"><?php echo !empty($existing_features) ? implode(', ', $existing_features) : ''; ?></textarea>
                    <p class="adm-hint"><i class="fas fa-circle-info mr-1"></i> e.g. "Soft close, Anti-termite, Scratch resistant" — each will be stored individually.</p>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="adm-card p-6 sm:p-8 adm-fade flex justify-center">
                <button type="submit" class="adm-btn">
                    <i class="fas fa-save"></i>
                    <span><?php echo $has_existing ? 'Update' : 'Save'; ?> All Product Details</span>
                </button>
            </div>
        </form>
    </div>

    <script>
        let specCount = <?php echo count($existing_specs); ?>;

        // Load existing specifications on page load
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($existing_specs as $index => $spec): ?>
            addSpecification(
                <?php echo json_encode($spec['spec_title']); ?>,
                <?php echo json_encode($spec['spec_description']); ?>
            );
            <?php endforeach; ?>
        });

        function previewImage(input, previewId) {
            const preview = document.getElementById(previewId);
            const img = preview.querySelector('img');

            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    img.src = e.target.result;
                    preview.classList.add('active');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function removeImage(inputId, previewId) {
            const input = document.getElementById(inputId);
            const preview = document.getElementById(previewId);

            input.value = '';
            preview.classList.remove('active');
        }

        function addSpecification(title = '', description = '') {
            const container = document.getElementById('specificationsContainer');
            
            const noSpecsMsg = container.querySelector('p.adm-empty-note');
            if (noSpecsMsg) {
                noSpecsMsg.remove();
            }

            const specDiv = document.createElement('div');
            specDiv.className = 'adm-spec-card';
            specDiv.id = 'spec_' + specCount;
            
            // Escape HTML for security
            const escapedTitle = title.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            const escapedDesc = description.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            
            specDiv.innerHTML = `
                <div class="adm-spec-head">
                    <div class="adm-spec-tag">
                        <div class="adm-spec-dot">${specCount + 1}</div>
                        Specification ${specCount + 1}
                    </div>
                    <button type="button" onclick="removeSpecification(${specCount})" class="adm-remove-btn">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>

                <div class="space-y-4">
                    <div class="adm-field">
                        <label class="adm-label">Title *</label>
                        <input type="text" name="spec_titles[]" required value="${escapedTitle}"
                            class="adm-input"
                            placeholder="e.g., Material Composition, Load Capacity, etc.">
                    </div>

                    <div class="adm-field">
                        <label class="adm-label">Description *</label>
                        <textarea name="spec_descriptions[]" rows="3" required
                            class="adm-input"
                            placeholder="Enter detailed description of this specification">${escapedDesc}</textarea>
                    </div>
                </div>
            `;

            container.appendChild(specDiv);
            specCount++;
        }

        function removeSpecification(index) {
            const specDiv = document.getElementById('spec_' + index);
            if (specDiv) {
                specDiv.remove();
                
                const container = document.getElementById('specificationsContainer');
                const remainingSpecs = container.querySelectorAll('.adm-spec-card');
                
                if (remainingSpecs.length === 0) {
                    container.innerHTML = '<p class="adm-empty-note">No specifications added yet. Click "Add Specification" to get started.</p>';
                }
            }
        }
    </script>
</body>
</html>