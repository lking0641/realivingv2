<?php
//edit_product.php
include $includes['mainbody'];

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

// Function to handle file upload and save to directory
function handleFileUpload($file, $folder_name)
{
    if (isset($file) && $file['error'] === UPLOAD_ERR_OK) {
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');
        
        if (in_array($file_extension, $allowed_extensions)) {
            if ($file['size'] <= 5242880) {
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

function convertToWebP($source, $destination, $source_extension)
{
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

// Get product ID from URL
$item_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($item_id <= 0) {
    header("Location: " . BASE_URL . "view-products");
    exit();
}

// Fetch dimension labels for the dropdown
$dimension_labels = [];
$dimension_labels_json = [];
try {
    $result = $conn->query("SELECT dimension_label_id, dimension_label_name,
        item_width_label_linear, item_height_label_linear, item_length_label_linear,
        item_width_label_sqm, item_height_label_sqm, item_length_label_sqm
        FROM dimension_label ORDER BY dimension_label_name");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $dimension_labels[] = $row;
            $dimension_labels_json[$row['dimension_label_id']] = $row;
        }
    }
} catch (Exception $e) {
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4'>Error fetching dimension labels: " . $e->getMessage() . "</div>";
}
$dimension_labels_json_output = json_encode($dimension_labels_json);

// Fetch existing product data
$product_data = null;
$dimension_data = null;
$standard_colors = [];

$query = "SELECT 
    i.*,
    dm.*
FROM items i
LEFT JOIN dimension_measurement dm ON i.dimension_msmt_fk = dm.dimension_msmt_id
WHERE i.item_id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $item_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $product_data = $result->fetch_assoc();
} else {
    header("Location: " . BASE_URL . "view-products");
    exit();
}
$stmt->close();

// Fetch standard colors
$colors_query = "SELECT * FROM item_standard_color WHERE fk_standard_color = ? ORDER BY standard_color_id";
$stmt_colors = $conn->prepare($colors_query);
$stmt_colors->bind_param("i", $item_id);
$stmt_colors->execute();
$colors_result = $stmt_colors->get_result();
while ($color = $colors_result->fetch_assoc()) {
    $standard_colors[] = $color;
}
$stmt_colors->close();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->autocommit(FALSE);

    try {
        // Update dimension measurements
        $stmt = $conn->prepare("UPDATE dimension_measurement SET 
            item_width_linear = ?, item_height_linear = ?, item_length_linear = ?,
            item_width_sqm = ?, item_height_sqm = ?, item_length_sqm = ?,
            startup_width_linear = ?, startup_height_linear = ?, startup_length_linear = ?,
            startup_width_sqm = ?, startup_height_sqm = ?, startup_length_sqm = ?
            WHERE dimension_msmt_id = ?");

        $stmt->bind_param(
            "ddddddddddddi",
            $_POST['item_width_linear'],
            $_POST['item_height_linear'],
            $_POST['item_length_linear'],
            $_POST['item_width_sqm'],
            $_POST['item_height_sqm'],
            $_POST['item_length_sqm'],
            $_POST['startup_width_linear'],
            $_POST['startup_height_linear'],
            $_POST['startup_length_linear'],
            $_POST['startup_width_sqm'],
            $_POST['startup_height_sqm'],
            $_POST['startup_length_sqm'],
            $product_data['dimension_msmt_fk']
        );

        if (!$stmt->execute()) {
            throw new Exception("Error updating dimension measurements: " . $stmt->error);
        }
        $stmt->close();

        // Handle item image upload
        $item_image_path = $product_data['item_image_path'];
        if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
            $new_image = handleFileUpload($_FILES['item_image'], 'products');
            if ($new_image) {
                // Delete old image if exists
                if (!empty($item_image_path)) {
                    $old_image_path = ROOT_PATH . 'realiving_user/images/products/' . $item_image_path;
                    if (file_exists($old_image_path)) {
                        unlink($old_image_path);
                    }
                }
                $item_image_path = $new_image;
            }
        }

        // Update main item
// Check if is_fixed_modular is set, default to 0 if not
$is_fixed_modular = isset($_POST['is_fixed_modular']) ? 1 : 0;

// Set dimension and pricing values to NULL if fixed modular
$dimension_label_fk = $is_fixed_modular ? NULL : $_POST['dimension_label_fk'];
$non_project_price = $is_fixed_modular ? NULL : $_POST['non_project_price'];
$project_price = $is_fixed_modular ? NULL : $_POST['project_price'];
$jackup = $is_fixed_modular ? NULL : $_POST['jackup'];
$mark_up = $is_fixed_modular ? NULL : $_POST['mark_up'];
$labor_cost = $is_fixed_modular ? NULL : $_POST['labor_cost'];

$stmt = $conn->prepare("UPDATE items SET 
    dimension_label_fk = ?, item_image_path = ?, item_code = ?, item_name = ?, 
    item_family = ?, item_family_2 = ?, item_material = ?, door_material = ?, item_color = ?, is_fixed_modular = ?,
    non_project_price = ?, project_price = ?, jackup = ?, mark_up = ?, labor_cost = ?
    WHERE item_id = ?");

$stmt->bind_param(
    "issssssssssddddi",
    $dimension_label_fk,
    $item_image_path,
    $_POST['item_code'],
    $_POST['item_name'],
    $_POST['item_family'],
    $_POST['item_family_2'],
    $_POST['item_material'],
    $_POST['door_material'],
    $_POST['item_color'],
    $is_fixed_modular,
    $non_project_price,
    $project_price,
    $jackup,
    $mark_up,
    $labor_cost,
    $item_id
);

        if (!$stmt->execute()) {
            throw new Exception("Error updating item: " . $stmt->error);
        }
        $stmt->close();

        // Handle standard colors
        // First, get existing color IDs to track which ones to keep
        $existing_color_ids = [];
        if (isset($_POST['existing_color_ids']) && is_array($_POST['existing_color_ids'])) {
            $existing_color_ids = $_POST['existing_color_ids'];
        }

        // Delete colors that were removed
        foreach ($standard_colors as $color) {
            if (!in_array($color['standard_color_id'], $existing_color_ids)) {
                // Delete the color and its image
                if (!empty($color['standard_color_image_path'])) {
                    $old_color_image = ROOT_PATH . 'realiving_user/images/product_colors/' . $color['standard_color_image_path'];
                    if (file_exists($old_color_image)) {
                        unlink($old_color_image);
                    }
                }
                $stmt = $conn->prepare("DELETE FROM item_standard_color WHERE standard_color_id = ?");
                $stmt->bind_param("i", $color['standard_color_id']);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Update existing colors
        if (isset($_POST['existing_colors']) && is_array($_POST['existing_colors'])) {
            foreach ($_POST['existing_colors'] as $color_id => $color_name) {
                if (!empty($color_name)) {
                    $color_image_path = null;
                    
                    // Check if new image was uploaded for this color
                    if (isset($_FILES['existing_color_images']['tmp_name'][$color_id]) &&
                        $_FILES['existing_color_images']['error'][$color_id] === UPLOAD_ERR_OK) {
                        $file_info = array(
                            'name' => $_FILES['existing_color_images']['name'][$color_id],
                            'type' => $_FILES['existing_color_images']['type'][$color_id],
                            'tmp_name' => $_FILES['existing_color_images']['tmp_name'][$color_id],
                            'error' => $_FILES['existing_color_images']['error'][$color_id],
                            'size' => $_FILES['existing_color_images']['size'][$color_id]
                        );
                        $new_color_image = handleFileUpload($file_info, 'product_colors');
                        if ($new_color_image) {
                            // Delete old color image
                            foreach ($standard_colors as $sc) {
                                if ($sc['standard_color_id'] == $color_id && !empty($sc['standard_color_image_path'])) {
                                    $old_path = ROOT_PATH . 'realiving_user/images/product_colors/' . $sc['standard_color_image_path'];
                                    if (file_exists($old_path)) {
                                        unlink($old_path);
                                    }
                                }
                            }
                            $color_image_path = $new_color_image;
                        }
                    } else {
                        // Keep existing image
                        foreach ($standard_colors as $sc) {
                            if ($sc['standard_color_id'] == $color_id) {
                                $color_image_path = $sc['standard_color_image_path'];
                                break;
                            }
                        }
                    }

                    $stmt = $conn->prepare("UPDATE item_standard_color SET standard_color = ?, standard_color_image_path = ? WHERE standard_color_id = ?");
                    $stmt->bind_param("ssi", $color_name, $color_image_path, $color_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        // Add new colors
        if (isset($_POST['new_colors']) && is_array($_POST['new_colors'])) {
            foreach ($_POST['new_colors'] as $index => $color_name) {
                if (!empty($color_name)) {
                    $color_image_path = null;
                    
                    if (isset($_FILES['new_color_images']['tmp_name'][$index]) &&
                        $_FILES['new_color_images']['error'][$index] === UPLOAD_ERR_OK) {
                        $file_info = array(
                            'name' => $_FILES['new_color_images']['name'][$index],
                            'type' => $_FILES['new_color_images']['type'][$index],
                            'tmp_name' => $_FILES['new_color_images']['tmp_name'][$index],
                            'error' => $_FILES['new_color_images']['error'][$index],
                            'size' => $_FILES['new_color_images']['size'][$index]
                        );
                        $color_image_path = handleFileUpload($file_info, 'product_colors');
                    }

                    $stmt = $conn->prepare("INSERT INTO item_standard_color (fk_standard_color, standard_color, standard_color_image_path) VALUES (?, ?, ?)");
                    $stmt->bind_param("iss", $item_id, $color_name, $color_image_path);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        $conn->commit();
        
        // Redirect to view products with success message
        $_SESSION['success_message'] = "Product updated successfully!";
        header("Location: " . BASE_URL . "view-products");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $edit_error_message = $e->getMessage();
    }

    $conn->autocommit(TRUE);
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - RealLiving</title>
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
        .adm-input:disabled, .adm-select:disabled{ background:#ECECEC; color: var(--adm-muted); cursor:not-allowed; }
        .adm-field{ margin-bottom: 0; }

        .adm-subcard{
            background: var(--adm-bg); border:1px solid var(--adm-line); border-radius:10px; padding:1.25rem;
        }
        .adm-subcard-inner{
            background: var(--adm-surface); border:1px solid var(--adm-line); border-radius:10px; padding:1.25rem;
        }
        .adm-subcard-title{
            font-size:13px; font-weight:700; color: var(--adm-ink);
            display:flex; align-items:center; gap:.5rem; margin-bottom:1rem;
        }
        .adm-subcard-title i{ color: var(--adm-soft); font-size:12px; }
        .adm-group-caption{ font-size:11px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; color: var(--adm-muted); text-align:center; margin-top:.25rem; }

        .adm-checkbox-row{
            display:flex; align-items:center; gap:.75rem;
            background: var(--adm-bg); border:1px solid var(--adm-line);
            border-radius:10px; padding:.9rem 1.1rem; cursor:pointer;
            transition: border-color .2s ease, background .2s ease;
        }
        .adm-checkbox-row:hover{ border-color: var(--adm-ink); }
        .adm-checkbox-row input[type="checkbox"]{
            width:18px; height:18px; accent-color: var(--adm-ink); cursor:pointer; flex-shrink:0;
        }
        .adm-checkbox-text{ font-size:12.5px; font-weight:600; color: var(--adm-ink); }

        /* ── Upload ─────────────────────────────── */
        .adm-upload-box{
            position:relative;
            width:120px; height:120px; flex-shrink:0;
            display:flex; flex-direction:column; align-items:center; justify-content:center; gap:.35rem;
            border:1.5px dashed var(--adm-line); border-radius:11px;
            background: var(--adm-bg); color: var(--adm-muted);
            transition: border-color .2s ease, background .2s ease, color .2s ease;
        }
        .adm-upload-box:hover{ border-color: var(--adm-ink); color: var(--adm-ink); background:#EFEFEF; }
        .adm-upload-box input[type="file"]{
            position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%;
        }
        .adm-upload-box i{ font-size:20px; }
        .adm-upload-box span{ font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }

        .adm-upload-box.is-sm{ width:84px; height:84px; }
        .adm-upload-box.is-sm i{ font-size:16px; }
        .adm-upload-box.is-sm span{ font-size:9px; }

        .adm-image-preview{ position:relative; display:none; }
        .adm-image-preview.active{ display:block; }
        .adm-preview-frame{
            width:120px; height:120px; border-radius:11px; overflow:hidden;
            border:1px solid var(--adm-line); background: var(--adm-bg);
        }
        .adm-preview-frame.is-sm{ width:84px; height:84px; }
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

        /* ── Color card ─────────────────────────── */
        .adm-color-card{
            background: var(--adm-bg); border:1px solid var(--adm-line);
            border-radius:10px; padding:1.1rem;
        }
        .adm-color-card.is-new{ border-style:dashed; }
        .adm-color-head{ display:flex; align-items:center; justify-content:between; justify-content:space-between; margin-bottom:1rem; }
        .adm-color-tag{
            display:inline-flex; align-items:center; gap:8px;
            font-size:12px; font-weight:700; color: var(--adm-ink);
        }
        .adm-color-dot{
            width:26px; height:26px; border-radius:999px;
            background: var(--adm-ink); color:#fff;
            display:flex; align-items:center; justify-content:center; font-size:11px;
        }
        .adm-color-dot.is-new-dot{ background:#16A34A; }
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

        @keyframes adm-fade{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }
        .adm-fade{ animation: adm-fade .4s ease both; }
        @media (prefers-reduced-motion: reduce){ .adm-fade{ animation:none; } }

        .adm-opacity-locked{ opacity:.5; pointer-events:none; }
    </style>
</head>

<body class="min-h-screen">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-10 max-w-6xl">

        <?php if (!empty($edit_error_message)): ?>
        <div class="adm-alert-error mb-6 adm-fade">
            <div class="adm-alert-icon-error"><i class="fas fa-triangle-exclamation"></i></div>
            <div class="flex-1">
                <h3 class="text-sm font-bold mb-1" style="color:var(--adm-ink);">Error updating product</h3>
                <p class="text-xs" style="color:var(--adm-soft);"><?php echo htmlspecialchars($edit_error_message); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="adm-card p-6 sm:p-8 mb-6 adm-fade">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <a href="<?= BASE_URL ?>view-products" class="adm-back">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Products</span>
                    </a>
                    <div class="adm-eyebrow mb-2">Catalog / Edit</div>
                    <h1 class="adm-title">Edit Product</h1>
                    <p class="adm-subtitle mt-1">Update product information and details.</p>
                </div>
                <div class="adm-chip-outline flex items-center gap-2 px-3 py-2 rounded-lg" style="border:1px solid var(--adm-line); background:var(--adm-bg);">
                    <span class="text-xs font-semibold" style="color:var(--adm-muted);">Item Code</span>
                    <span class="text-sm font-bold" style="color:var(--adm-ink);"><?php echo htmlspecialchars($product_data['item_code']); ?></span>
                </div>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">

            <!-- Item Information -->
            <div class="adm-card p-6 sm:p-8 adm-fade">
                <div class="flex items-center gap-3 mb-6">
                    <div class="adm-section-icon"><i class="fas fa-circle-info"></i></div>
                    <div>
                        <div class="adm-section-title">Item Information</div>
                        <div class="adm-section-hint">Core identifying details for this product</div>
                    </div>
                </div>

                <div class="space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="adm-field">
                            <label class="adm-label">Item Code *</label>
                            <input type="text" name="item_code" required
                                value="<?php echo htmlspecialchars($product_data['item_code']); ?>"
                                class="adm-input">
                        </div>

                        <div class="adm-field">
                            <label class="adm-label">Item Name *</label>
                            <input type="text" name="item_name" required
                                value="<?php echo htmlspecialchars($product_data['item_name']); ?>"
                                class="adm-input">
                        </div>

                        <div class="adm-field">
                            <label class="adm-label">Item Family Variant 1</label>
                            <input type="text" name="item_family"
                                value="<?php echo htmlspecialchars($product_data['item_family'] ?? ''); ?>"
                                class="adm-input">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="adm-field">
                            <label class="adm-label">Item Family Variant 2</label>
                            <input type="text" name="item_family_2"
                                value="<?php echo htmlspecialchars($product_data['item_family_2'] ?? ''); ?>"
                                class="adm-input">
                        </div>
                        <div class="adm-field">
                            <label class="adm-label">Carcass Material</label>
                            <input type="text" name="item_material"
                                value="<?php echo htmlspecialchars($product_data['item_material'] ?? ''); ?>"
                                class="adm-input">
                        </div>
                        <div class="adm-field">
                            <label class="adm-label">Door Material</label>
                            <input type="text" name="door_material"
                                value="<?php echo htmlspecialchars($product_data['door_material'] ?? ''); ?>"
                                class="adm-input">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="adm-field">
                            <label class="adm-label">Item Color</label>
                            <input type="text" name="item_color"
                                value="<?php echo htmlspecialchars($product_data['item_color'] ?? ''); ?>"
                                class="adm-input" placeholder="Enter item color">
                        </div>
                    </div>

                    <!-- Fixed Modular Checkbox -->
                    <label class="adm-checkbox-row w-full md:w-auto md:inline-flex">
                        <input type="checkbox"
                               id="fixed_modular"
                               name="is_fixed_modular"
                               value="1"
                               <?php echo (!empty($product_data['is_fixed_modular']) && $product_data['is_fixed_modular'] == 1) ? 'checked' : ''; ?>
                               onchange="toggleFixedModular()">
                        <span class="adm-checkbox-text"><i class="fas fa-lock mr-1" style="color:var(--adm-muted);"></i> Fixed Modular — disable pricing &amp; dimensions</span>
                    </label>

                    <!-- Item Image Upload -->
                    <div class="adm-field">
                        <label class="adm-label">Item Image</label>
                        <div class="flex items-center gap-5">
                            <div class="adm-upload-box">
                                <input type="file" name="item_image" accept="image/*"
                                       id="item_image"
                                       onchange="previewImage(this, 'item_image_preview')">
                                <i class="fas fa-camera"></i>
                                <span>Upload</span>
                            </div>

                            <div id="item_image_preview" class="adm-image-preview <?php echo !empty($product_data['item_image_path']) ? 'active' : ''; ?>">
                                <div class="adm-preview-frame">
                                    <img src="<?php echo !empty($product_data['item_image_path']) ? BASE_URL . '/realiving_user/images/products/' . htmlspecialchars($product_data['item_image_path']) : ''; ?>"
                                         alt="Preview">
                                </div>
                                <button type="button" onclick="removeImage('item_image', 'item_image_preview')" class="adm-preview-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Pricing -->
                    <div class="adm-subcard" id="pricing_section">
                        <div class="adm-subcard-title"><i class="fas fa-tag"></i> Pricing</div>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                            <div class="adm-field">
                                <label class="adm-label">Individual Price</label>
                                <input type="number" name="non_project_price" step="0.01" id="non_project_price"
                                    value="<?php echo htmlspecialchars($product_data['non_project_price'] ?? '0'); ?>"
                                    class="adm-input">
                            </div>
                            <div class="adm-field">
                                <label class="adm-label">Project Price</label>
                                <input type="number" name="project_price" step="0.01" id="project_price"
                                    value="<?php echo htmlspecialchars($product_data['project_price'] ?? '0'); ?>"
                                    class="adm-input">
                            </div>
                            <div class="adm-field">
                                <label class="adm-label">Dimension Adj. (%)</label>
                                <input type="number" name="jackup" step="0.01" id="jackup"
                                    value="<?php echo htmlspecialchars($product_data['jackup'] ?? '0'); ?>"
                                    class="adm-input">
                            </div>
                            <div class="adm-field">
                                <label class="adm-label">Jack Up (%)</label>
                                <input type="number" name="mark_up" step="0.01" id="mark_up"
                                    value="<?php echo htmlspecialchars($product_data['mark_up'] ?? '0'); ?>"
                                    class="adm-input">
                            </div>
                            <div class="adm-field">
                                <label class="adm-label">Labor Cost</label>
                                <input type="number" name="labor_cost" step="0.01" id="labor_cost"
                                    value="<?php echo htmlspecialchars($product_data['labor_cost'] ?? '0'); ?>"
                                    class="adm-input">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dimension Information -->
            <div class="adm-card p-6 sm:p-8 adm-fade" id="dimension_section">
                <div class="flex items-center gap-3 mb-6">
                    <div class="adm-section-icon"><i class="fas fa-ruler-combined"></i></div>
                    <div>
                        <div class="adm-section-title">Dimension Information</div>
                        <div class="adm-section-hint">Measurements used for pricing and layout</div>
                    </div>
                </div>

                <div class="space-y-5">
                    <div class="adm-field">
                        <label class="adm-label">Dimension Label</label>
                        <select name="dimension_label_fk" required
                            id="dimension_label_select"
                            onchange="updateDimensionLabels(this.value)"
                            class="adm-select">
                            <option value="">Select Dimension Label</option>
                            <?php foreach ($dimension_labels as $label): ?>
                                <option value="<?php echo htmlspecialchars($label['dimension_label_id']); ?>"
                                    <?php echo ($product_data['dimension_label_fk'] == $label['dimension_label_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label['dimension_label_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Item Dimensions -->
                    <div class="adm-subcard">
                        <div class="adm-subcard-title"><i class="fas fa-cube"></i> Item Dimensions</div>

                        <div class="mb-5">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-2">
                                <div class="adm-field">
                                    <label class="adm-label" id="label_width_linear">Width (Linear)</label>
                                    <input type="number" name="item_width_linear" step="0.01"
                                        value="<?php echo htmlspecialchars($product_data['item_width_linear'] ?? '0'); ?>"
                                        class="adm-input">
                                </div>
                                <div class="adm-field">
                                    <label class="adm-label" id="label_height_linear">Height (Linear)</label>
                                    <input type="number" name="item_height_linear" step="0.01"
                                        value="<?php echo htmlspecialchars($product_data['item_height_linear'] ?? '0'); ?>"
                                        class="adm-input">
                                </div>
                                <div class="adm-field">
                                    <label class="adm-label" id="label_length_linear">Length (Linear)</label>
                                    <input type="number" name="item_length_linear" step="0.01"
                                        value="<?php echo htmlspecialchars($product_data['item_length_linear'] ?? '0'); ?>"
                                        class="adm-input">
                                </div>
                            </div>
                            <div class="adm-group-caption">Linear Measurements</div>
                        </div>

                        <div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-2">
                                <div class="adm-field">
                                    <label class="adm-label" id="label_width_sqm">Width (SqM)</label>
                                    <input type="number" name="item_width_sqm" step="0.01"
                                        value="<?php echo htmlspecialchars($product_data['item_width_sqm'] ?? '0'); ?>"
                                        class="adm-input">
                                </div>
                                <div class="adm-field">
                                    <label class="adm-label" id="label_height_sqm">Height (SqM)</label>
                                    <input type="number" name="item_height_sqm" step="0.01"
                                        value="<?php echo htmlspecialchars($product_data['item_height_sqm'] ?? '0'); ?>"
                                        class="adm-input">
                                </div>
                                <div class="adm-field">
                                    <label class="adm-label" id="label_length_sqm">Length (SqM)</label>
                                    <input type="number" name="item_length_sqm" step="0.01"
                                        value="<?php echo htmlspecialchars($product_data['item_length_sqm'] ?? '0'); ?>"
                                        class="adm-input">
                                </div>
                            </div>
                            <div class="adm-group-caption">Square Meter Measurements</div>
                        </div>
                    </div>

                    <!-- Startup Dimensions -->
                    <div class="adm-subcard-inner">
                        <div class="adm-subcard-title"><i class="fas fa-rocket"></i> Startup Dimensions</div>

                        <div class="mb-5">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-2">
                                <div class="adm-field">
                                    <label class="adm-label" id="startup_label_width_linear">Width (Linear)</label>
                                    <input type="number" name="startup_width_linear" step="0.01"
                                        value="<?php echo htmlspecialchars($product_data['startup_width_linear'] ?? '0'); ?>"
                                        class="adm-input">
                                </div>
                                <div class="adm-field">
                                    <label class="adm-label" id="startup_label_height_linear">Height (Linear)</label>
                                    <input type="number" name="startup_height_linear" step="0.01"
                                        value="<?php echo htmlspecialchars($product_data['startup_height_linear'] ?? '0'); ?>"
                                        class="adm-input">
                                </div>
                                <div class="adm-field">
                                    <label class="adm-label" id="startup_label_length_linear">Length (Linear)</label>
                                    <input type="number" name="startup_length_linear" step="0.01"
                                        value="<?php echo htmlspecialchars($product_data['startup_length_linear'] ?? '0'); ?>"
                                        class="adm-input">
                                </div>
                            </div>
                            <div class="adm-group-caption">Startup Linear Measurements</div>
                        </div>

                        <div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-2">
                                <div class="adm-field">
                                    <label class="adm-label" id="startup_label_width_sqm">Width (SqM)</label>
                                    <input type="number" name="startup_width_sqm" step="0.01"
                                        value="<?php echo htmlspecialchars($product_data['startup_width_sqm'] ?? '0'); ?>"
                                        class="adm-input">
                                </div>
                                <div class="adm-field">
                                    <label class="adm-label" id="startup_label_height_sqm">Height (SqM)</label>
                                    <input type="number" name="startup_height_sqm" step="0.01"
                                        value="<?php echo htmlspecialchars($product_data['startup_height_sqm'] ?? '0'); ?>"
                                        class="adm-input">
                                </div>
                                <div class="adm-field">
                                    <label class="adm-label" id="startup_label_length_sqm">Length (SqM)</label>
                                    <input type="number" name="startup_length_sqm" step="0.01"
                                        value="<?php echo htmlspecialchars($product_data['startup_length_sqm'] ?? '0'); ?>"
                                        class="adm-input">
                                </div>
                            </div>
                            <div class="adm-group-caption">Startup Square Meter Measurements</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Standard Colors Section -->
            <div class="adm-card p-6 sm:p-8 adm-fade">
                <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
                    <div class="flex items-center gap-3">
                        <div class="adm-section-icon"><i class="fas fa-palette"></i></div>
                        <div>
                            <div class="adm-section-title">Standard Colors</div>
                            <div class="adm-section-hint">Available finishes for this product</div>
                        </div>
                    </div>
                    <button type="button" onclick="addNewColor()" class="adm-btn-outline">
                        <i class="fas fa-plus"></i>
                        <span>Add Color</span>
                    </button>
                </div>

                <!-- Existing Colors -->
                <div id="existingColorsContainer" class="space-y-4 mb-4">
                    <?php if (!empty($standard_colors)): ?>
                        <?php foreach ($standard_colors as $index => $color): ?>
                            <div class="adm-color-card" id="existing_color_<?php echo $color['standard_color_id']; ?>">
                                <div class="adm-color-head">
                                    <div class="adm-color-tag">
                                        <div class="adm-color-dot"><i class="fas fa-paint-brush"></i></div>
                                        Existing Color
                                    </div>
                                    <button type="button" onclick="removeExistingColor(<?php echo $color['standard_color_id']; ?>)" class="adm-remove-btn">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                </div>

                                <input type="hidden" name="existing_color_ids[]" value="<?php echo $color['standard_color_id']; ?>">

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                                    <div class="adm-field">
                                        <label class="adm-label">Color Name *</label>
                                        <input type="text" name="existing_colors[<?php echo $color['standard_color_id']; ?>]" required
                                            value="<?php echo htmlspecialchars($color['standard_color']); ?>"
                                            class="adm-input">
                                    </div>

                                    <div class="adm-field">
                                        <label class="adm-label">Color Image</label>
                                        <div class="flex items-center gap-4">
                                            <div class="adm-upload-box is-sm">
                                                <input type="file" name="existing_color_images[<?php echo $color['standard_color_id']; ?>]" accept="image/*"
                                                       id="existing_color_image_<?php echo $color['standard_color_id']; ?>"
                                                       onchange="previewImage(this, 'existing_color_preview_<?php echo $color['standard_color_id']; ?>')">
                                                <i class="fas fa-camera"></i>
                                                <span>Upload</span>
                                            </div>

                                            <div id="existing_color_preview_<?php echo $color['standard_color_id']; ?>"
                                                 class="adm-image-preview <?php echo !empty($color['standard_color_image_path']) ? 'active' : ''; ?>">
                                                <div class="adm-preview-frame is-sm">
                                                    <img src="<?php echo !empty($color['standard_color_image_path']) ? BASE_URL . '/realiving_user/images/product_colors/' . htmlspecialchars($color['standard_color_image_path']) : ''; ?>"
                                                         alt="Color Preview">
                                                </div>
                                                <button type="button" onclick="removeImage('existing_color_image_<?php echo $color['standard_color_id']; ?>', 'existing_color_preview_<?php echo $color['standard_color_id']; ?>')" class="adm-preview-remove">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-sm text-center py-8" style="color:var(--adm-muted);">No standard colors added yet.</p>
                    <?php endif; ?>
                </div>

                <!-- New Colors Container -->
                <div id="newColorsContainer" class="space-y-4"></div>
            </div>

            <!-- Submit Buttons -->
            <div class="adm-card p-6 sm:p-8 adm-fade flex justify-center gap-3">
                <a href="<?= BASE_URL ?>view-products" class="adm-btn-outline">
                    <i class="fas fa-times"></i>
                    <span>Cancel</span>
                </a>
                <button type="submit" class="adm-btn">
                    <i class="fas fa-save"></i>
                    <span>Update Product</span>
                </button>
            </div>
        </form>
    </div>

    <script>
        let newColorCount = 0;
        const dimensionLabelsData = <?php echo $dimension_labels_json_output; ?>;

        function updateDimensionLabels(dimensionId) {
            if (!dimensionId || !dimensionLabelsData[dimensionId]) {
                document.getElementById('label_width_linear').textContent = 'Width (Linear)';
                document.getElementById('label_height_linear').textContent = 'Height (Linear)';
                document.getElementById('label_length_linear').textContent = 'Length (Linear)';
                document.getElementById('label_width_sqm').textContent = 'Width (SqM)';
                document.getElementById('label_height_sqm').textContent = 'Height (SqM)';
                document.getElementById('label_length_sqm').textContent = 'Length (SqM)';
                document.getElementById('startup_label_width_linear').textContent = 'Width (Linear)';
                document.getElementById('startup_label_height_linear').textContent = 'Height (Linear)';
                document.getElementById('startup_label_length_linear').textContent = 'Length (Linear)';
                document.getElementById('startup_label_width_sqm').textContent = 'Width (SqM)';
                document.getElementById('startup_label_height_sqm').textContent = 'Height (SqM)';
                document.getElementById('startup_label_length_sqm').textContent = 'Length (SqM)';
                return;
            }
            const data = dimensionLabelsData[dimensionId];
            document.getElementById('label_width_linear').textContent  = (data.item_width_label_linear  || 'Width')  + ' (Linear)';
            document.getElementById('label_height_linear').textContent = (data.item_height_label_linear || 'Height') + ' (Linear)';
            document.getElementById('label_length_linear').textContent = (data.item_length_label_linear || 'Length') + ' (Linear)';
            document.getElementById('label_width_sqm').textContent     = (data.item_width_label_sqm    || 'Width')  + ' (SqM)';
            document.getElementById('label_height_sqm').textContent    = (data.item_height_label_sqm   || 'Height') + ' (SqM)';
            document.getElementById('label_length_sqm').textContent    = (data.item_length_label_sqm   || 'Length') + ' (SqM)';
            document.getElementById('startup_label_width_linear').textContent  = (data.item_width_label_linear  || 'Width')  + ' (Linear)';
            document.getElementById('startup_label_height_linear').textContent = (data.item_height_label_linear || 'Height') + ' (Linear)';
            document.getElementById('startup_label_length_linear').textContent = (data.item_length_label_linear || 'Length') + ' (Linear)';
            document.getElementById('startup_label_width_sqm').textContent     = (data.item_width_label_sqm    || 'Width')  + ' (SqM)';
            document.getElementById('startup_label_height_sqm').textContent    = (data.item_height_label_sqm   || 'Height') + ' (SqM)';
            document.getElementById('startup_label_length_sqm').textContent    = (data.item_length_label_sqm   || 'Length') + ' (SqM)';
        }

        function previewImage(input, previewId) {
            const preview = document.getElementById(previewId);
            const img = preview.querySelector('img');

            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
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

        function removeExistingColor(colorId) {
            if (confirm('Are you sure you want to remove this color?')) {
                const colorDiv = document.getElementById('existing_color_' + colorId);
                if (colorDiv) {
                    colorDiv.remove();
                }
            }
        }

        function addNewColor() {
            const container = document.getElementById('newColorsContainer');
            const colorIndex = newColorCount;

            const colorDiv = document.createElement('div');
            colorDiv.className = 'adm-color-card is-new';
            colorDiv.id = `new_color_${colorIndex}`;
            colorDiv.innerHTML = `
                <div class="adm-color-head">
                    <div class="adm-color-tag">
                        <div class="adm-color-dot is-new-dot"><i class="fas fa-paint-brush"></i></div>
                        New Color
                    </div>
                    <button type="button" onclick="removeNewColor(${colorIndex})" class="adm-remove-btn">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                    <div class="adm-field">
                        <label class="adm-label">Color Name *</label>
                        <input type="text" name="new_colors[${colorIndex}]" required
                            class="adm-input" placeholder="Enter color name">
                    </div>

                    <div class="adm-field">
                        <label class="adm-label">Color Image</label>
                        <div class="flex items-center gap-4">
                            <div class="adm-upload-box is-sm">
                                <input type="file" name="new_color_images[${colorIndex}]" accept="image/*"
                                       id="new_color_image_${colorIndex}"
                                       onchange="previewImage(this, 'new_color_preview_${colorIndex}')">
                                <i class="fas fa-camera"></i>
                                <span>Upload</span>
                            </div>

                            <div id="new_color_preview_${colorIndex}" class="adm-image-preview">
                                <div class="adm-preview-frame is-sm">
                                    <img src="" alt="Color Preview">
                                </div>
                                <button type="button" onclick="removeImage('new_color_image_${colorIndex}', 'new_color_preview_${colorIndex}')" class="adm-preview-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            container.appendChild(colorDiv);
            newColorCount++;
        }

        function removeNewColor(colorIndex) {
            const colorDiv = document.getElementById(`new_color_${colorIndex}`);
            if (colorDiv) {
                colorDiv.remove();
            }
        }

        function toggleFixedModular() {
            const checkbox = document.getElementById('fixed_modular');
            const isChecked = checkbox.checked;

            const pricingFields = [
                'non_project_price',
                'project_price',
                'jackup',
                'mark_up',
                'labor_cost'
            ];

            const dimensionSection = document.getElementById('dimension_section');

            if (isChecked) {
                pricingFields.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) {
                        field.value = '';
                        field.disabled = true;
                    }
                });

                if (dimensionSection) {
                    const dimensionInputs = dimensionSection.querySelectorAll('input, select');
                    dimensionInputs.forEach(input => {
                        input.value = '';
                        input.disabled = true;
                    });
                    dimensionSection.classList.add('adm-opacity-locked');
                }
            } else {
                pricingFields.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) {
                        field.disabled = false;
                    }
                });

                if (dimensionSection) {
                    const dimensionInputs = dimensionSection.querySelectorAll('input, select');
                    dimensionInputs.forEach(input => {
                        input.disabled = false;
                    });
                    dimensionSection.classList.remove('adm-opacity-locked');
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            toggleFixedModular();
            const select = document.getElementById('dimension_label_select');
            if (select && select.value) {
                updateDimensionLabels(select.value);
            }
        });
    </script>
</body>
</html>