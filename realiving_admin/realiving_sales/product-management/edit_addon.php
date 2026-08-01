<?php
//edit_addon.php
include $includes ['mainbody'];

// Allow only admin1, superadmin, sales, designer
require_role(['admin1', 'superadmin', 'sales', 'designer']);

// Extra check: if designer, only heads can access
if ($_SESSION['admin_role'] === 'designer') {
    $headCheck = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
    $headCheck->bind_param("i", $_SESSION['admin_id']);
    $headCheck->execute();
    $headRow = $headCheck->get_result()->fetch_assoc();
    $headCheck->close();

    if (empty($headRow['is_head'])) {
        $_SESSION['noti'] = 'Access Denied: Only head designers can access this page.';
        header("Location: " . BASE_URL . "designer-layout-list");
        exit();
    }
}

// Check if ID is provided
if (!isset($_GET['id'])) {
    header("Location: " . BASE_URL . "view-addons");
    exit();
}

$addon_id = intval($_GET['id']);
$message     = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $addon_name          = trim($_POST['addon_name']);
    $addon_price         = floatval($_POST['addon_price']);
    $addon_description   = trim($_POST['addon_description']);
    $addon_category      = trim($_POST['addon_category']);
    $addon_type          = trim($_POST['addon_type'] ?? '');
    $addon_labor_cost    = !empty($_POST['addon_labor_cost'])         ? floatval($_POST['addon_labor_cost'])         : null;
    $addon_jackup = !empty($_POST['addon_jackup']) ? floatval($_POST['addon_jackup']) : null;

    $has_dimension       = isset($_POST['has_dimension']) ? 1 : 0;
    $dimension_type      = $has_dimension && !empty($_POST['dimension_type']) ? trim($_POST['dimension_type']) : null;

    $dimension_label_1   = $has_dimension && !empty($_POST['dimension_label_1']) ? trim($_POST['dimension_label_1']) : null;
    $dimension_label_2   = $has_dimension && !empty($_POST['dimension_label_2']) ? trim($_POST['dimension_label_2']) : null;
    $dimension_label_3   = $has_dimension && !empty($_POST['dimension_label_3']) ? trim($_POST['dimension_label_3']) : null;

    $dimension_value_1   = $has_dimension && isset($_POST['dimension_value_1']) && $_POST['dimension_value_1'] !== '' ? floatval($_POST['dimension_value_1']) : null;
    $dimension_value_2   = $has_dimension && isset($_POST['dimension_value_2']) && $_POST['dimension_value_2'] !== '' ? floatval($_POST['dimension_value_2']) : null;
    $dimension_value_3   = $has_dimension && isset($_POST['dimension_value_3']) && $_POST['dimension_value_3'] !== '' ? floatval($_POST['dimension_value_3']) : null;

    $required_unit       = $has_dimension && !empty($_POST['required_unit'])       ? floatval($_POST['required_unit'])       : null;
    $min_required_unit   = $has_dimension && !empty($_POST['min_required_unit'])   ? floatval($_POST['min_required_unit'])   : null;
    $max_quantity        = !empty($_POST['max_quantity']) ? floatval($_POST['max_quantity']) : null;
    $is_stable_mat       = $has_dimension && isset($_POST['is_stable_mat']) ? 1 : 0;
    $multiply_value      = !empty($_POST['multiply_value']) ? floatval($_POST['multiply_value']) : null;

    // Image handling
    $upload_dir = ROOT_PATH . 'realiving_user/images/product_addons/';
    $image_path = $_POST['existing_image']; // Keep existing by default

    // Remove image flag
    if (isset($_POST['remove_image']) && $_POST['remove_image'] == '1') {
        if (!empty($_POST['existing_image']) && file_exists($upload_dir . $_POST['existing_image'])) {
            unlink($upload_dir . $_POST['existing_image']);
        }
        $image_path = null;
    }

    // New upload
    if (!empty($_FILES['addon_image']['name']) && $_FILES['addon_image']['error'] == 0) {
        $file_extension = strtolower(pathinfo($_FILES['addon_image']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($file_extension, $allowed_extensions)) {
            $message     = "Only JPG, JPEG, PNG, GIF, and WEBP files are allowed.";
            $messageType = 'error';
        } elseif ($_FILES['addon_image']['size'] > 5242880) {
            $message     = "Image file size must be less than 5MB.";
            $messageType = 'error';
        } else {
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

            $new_filename = uniqid() . '_' . time() . '.webp';
            $target_file  = $upload_dir . $new_filename;
            $temp_file    = $upload_dir . 'temp_' . $new_filename;

            if (move_uploaded_file($_FILES['addon_image']['tmp_name'], $temp_file)) {
                $src_image = null;
                switch ($file_extension) {
                    case 'jpg':
                    case 'jpeg':
                        $src_image = imagecreatefromjpeg($temp_file);
                        break;
                    case 'png':
                        $src_image = imagecreatefrompng($temp_file);
                        imagealphablending($src_image, true);
                        imagesavealpha($src_image, true);
                        break;
                    case 'gif':
                        $src_image = imagecreatefromgif($temp_file);
                        break;
                    case 'webp':
                        copy($temp_file, $target_file);
                        unlink($temp_file);
                        if (!empty($_POST['existing_image']) && file_exists($upload_dir . $_POST['existing_image'])) {
                            unlink($upload_dir . $_POST['existing_image']);
                        }
                        $image_path = $new_filename;
                        break;
                }
                if ($src_image !== null) {
                    if (imagewebp($src_image, $target_file, 80)) {
                        imagedestroy($src_image);
                        unlink($temp_file);
                        if (!empty($_POST['existing_image']) && file_exists($upload_dir . $_POST['existing_image'])) {
                            unlink($upload_dir . $_POST['existing_image']);
                        }
                        $image_path = $new_filename;
                    } else {
                        imagedestroy($src_image);
                        unlink($temp_file);
                        $message     = "Error converting image to WebP.";
                        $messageType = 'error';
                    }
                }
            } else {
                $message     = "Error uploading image file.";
                $messageType = 'error';
            }
        }
    }

    if (empty($message)) {
        $stmt = $conn->prepare("
            UPDATE product_addons SET
                addon_name               = ?,
                addon_price              = ?,
                addon_description        = ?,
                addon_category           = ?,
                addon_type               = ?,
                addon_image_path         = ?,
                labor_cost               = ?,
                labor_cost_jack_up       = ?,
                has_dimension            = ?,
                dimension_type           = ?,
                dimension_label_1        = ?,
                dimension_label_2        = ?,
                dimension_label_3        = ?,
                dimension_value_1        = ?,
                dimension_value_2        = ?,
                dimension_value_3        = ?,
                required_unit            = ?,
                min_required_unit        = ?,
                max_quantity             = ?,
                is_stable_mat            = ?,
                multiply_value           = ?
            WHERE id = ?
        ");
        $stmt->bind_param(
            "sdssssddissssddddddidi",
            $addon_name,                // s
            $addon_price,               // d
            $addon_description,         // s
            $addon_category,            // s
            $addon_type,                // s
            $image_path,                // s
            $addon_labor_cost,          // d
            $addon_jackup,              // d
            $has_dimension,             // i
            $dimension_type,            // s
            $dimension_label_1,         // s
            $dimension_label_2,         // s
            $dimension_label_3,         // s
            $dimension_value_1,         // d
            $dimension_value_2,         // d
            $dimension_value_3,         // d
            $required_unit,             // d
            $min_required_unit,         // d
            $max_quantity,              // d
            $is_stable_mat,             // i
            $multiply_value,            // d
            $addon_id                   // i
        );

        if ($stmt->execute()) {
            $_SESSION['noti'] = 'success';
            header("Location: " . BASE_URL . "edit-addon?id=" . $addon_id);
            exit();
        } else {
            $message     = "Error updating addon: " . $stmt->error;
            $messageType = 'error';
        }
        $stmt->close();
    }
}

// Fetch addon details
$stmt = $conn->prepare("SELECT * FROM product_addons WHERE id = ?");
$stmt->bind_param("i", $addon_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    $_SESSION['error_message'] = "Addon not found!";
    header("Location: " . BASE_URL . "view-addons");
    exit();
}
$addon = $result->fetch_assoc();
$stmt->close();

// Fetch existing categories
$categories_result = $conn->query("SELECT DISTINCT addon_category FROM product_addons WHERE addon_category IS NOT NULL AND addon_category != '' ORDER BY addon_category ASC");
$existing_categories = [];
if ($categories_result) {
    while ($row = $categories_result->fetch_assoc()) {
        $existing_categories[] = $row['addon_category'];
    }
}

// Fetch types grouped by category
$types_by_category = [];
$tbcr = $conn->query("SELECT DISTINCT addon_category, addon_type FROM product_addons WHERE addon_category IS NOT NULL AND addon_category != '' AND addon_type IS NOT NULL AND addon_type != '' ORDER BY addon_category, addon_type ASC");
if ($tbcr) {
    while ($row = $tbcr->fetch_assoc()) {
        $types_by_category[$row['addon_category']][] = $row['addon_type'];
    }
}

if (!empty($_SESSION['noti'])) {
    if ($_SESSION['noti'] === 'success') {
        $message     = "Accessory updated successfully!";
        $messageType = 'success';
    }
    unset($_SESSION['noti']);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Accessory</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'real-blue': '#3B9DD1',
                        'real-orange': '#F5A623',
                        'real-dark': '#2C3E50'
                    }
                }
            }
        }
    </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .camera-upload {
            position: relative;
            transition: all 0.3s ease;
        }

        .camera-upload:hover {
            transform: translateY(-2px);
        }

        .camera-upload input[type="file"] {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
            top: 0;
            left: 0;
        }

        .camera-icon {
            transition: all 0.3s ease;
        }

        .camera-upload:hover .camera-icon {
            transform: scale(1.1);
            color: #3B9DD1;
        }

        .image-preview {
            transition: all 0.3s ease;
            border: 3px solid transparent;
        }

        .image-preview.active {
            border-color: #3B9DD1;
            box-shadow: 0 0 20px rgba(59, 157, 209, 0.3);
        }

        .category-card,
        .type-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .category-card:hover,
        .type-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .category-card.selected {
            border-color: #3B9DD1;
            background: linear-gradient(135deg, rgba(59, 157, 209, 0.1) 0%, rgba(245, 166, 35, 0.1) 100%);
        }

        .type-card.selected {
            border-color: #10b981;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(20, 184, 166, 0.1) 100%);
        }

        .dimension-toggle-wrap {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 56px;
            height: 30px;
            flex-shrink: 0;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-knob {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #d1d5db;
            border-radius: 30px;
            transition: .35s;
        }

        .toggle-knob:before {
            content: "";
            position: absolute;
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background: white;
            border-radius: 50%;
            transition: .35s;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
        }

        .toggle-switch input:checked+.toggle-knob {
            background: #3B9DD1;
        }

        .toggle-switch input:checked+.toggle-knob:before {
            transform: translateX(26px);
        }

        .dim-type-btn {
            flex: 1;
            padding: 14px 10px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            cursor: pointer;
            text-align: center;
            transition: all 0.25s;
            background: #f9fafb;
        }

        .dim-type-btn:hover {
            border-color: #3B9DD1;
            background: rgba(59, 157, 209, 0.05);
        }

        .dim-type-btn.selected {
            border-color: #3B9DD1;
            background: linear-gradient(135deg, rgba(59, 157, 209, 0.12) 0%, rgba(245, 166, 35, 0.08) 100%);
        }

        .dim-type-btn .icon {
            font-size: 2rem;
            margin-bottom: 6px;
        }

        .dim-type-btn .label {
            font-weight: 700;
            font-size: 0.95rem;
            color: #2C3E50;
        }

        .dim-type-btn .sublabel {
            font-size: 0.72rem;
            color: #6b7280;
            margin-top: 2px;
        }

        .price-input {
            position: relative;
        }

        .price-input::before {
            content: "₱";
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-weight: 600;
            z-index: 10;
        }

        .price-input input {
            padding-left: 2rem;
        }

        .section-slide {
            overflow: hidden;
            transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
            max-height: 0;
            opacity: 0;
        }

        .section-slide.open {
            max-height: 2500px;
            opacity: 1;
        }

        .linked-addon-card {
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            padding: 12px 16px;
            cursor: pointer;
            transition: all 0.25s;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .linked-addon-card:hover {
            border-color: #3B9DD1;
            background: rgba(59, 157, 209, 0.04);
        }

        .linked-addon-card.selected {
            border-color: #3B9DD1;
            background: rgba(59, 157, 209, 0.08);
        }

        .badge-sqm {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .badge-lm {
            background: #dcfce7;
            color: #15803d;
        }

        .badge {
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .dim-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            align-items: start;
        }

        .dim-label-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: white;
            font-size: 0.875rem;
            color: #374151;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 8px center;
            background-size: 16px;
            padding-right: 32px;
            cursor: pointer;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .dim-label-select:focus {
            outline: none;
            border-color: #3B9DD1;
            box-shadow: 0 0 0 2px rgba(59, 157, 209, 0.25);
        }

        .dim-row-number {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            background: #e0f2fe;
            color: #0369a1;
            border-radius: 50%;
            font-size: 0.75rem;
            font-weight: 700;
            flex-shrink: 0;
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="container mx-auto px-6 py-8">

        <!-- Header -->
        <div class="bg-white rounded-xl shadow-lg p-8 mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <a href="view-addons" class="inline-flex items-center text-real-blue hover:text-real-orange font-semibold mb-3 transition-colors duration-200">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Accessories
                    </a>
                    <h1 class="text-3xl font-bold text-real-dark">Edit Accessory</h1>
                    <p class="text-gray-600 mt-2">Update accessory details — <span class="font-semibold text-real-blue"><?php echo htmlspecialchars($addon['addon_name']); ?></span></p>
                </div>
                <div class="hidden md:block">
                    <div class="w-16 h-16 bg-gradient-to-br from-real-blue to-real-orange rounded-full flex items-center justify-center">
                        <i class="fas fa-pencil-alt text-white text-2xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div id="messageContainer" class="mb-6">
                <div class="<?php echo $messageType === 'success' ? 'bg-green-100 border-green-400 text-green-700' : 'bg-red-100 border-red-400 text-red-700'; ?> border px-4 py-3 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                        <span class="font-semibold"><?php echo $messageType === 'success' ? 'Success!' : 'Error!'; ?></span>
                    </div>
                    <p class="mt-1"><?php echo htmlspecialchars($message); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <form id="addonForm" method="POST" enctype="multipart/form-data" class="space-y-8" onsubmit="return validateForm()">
            <input type="hidden" name="existing_image" value="<?php echo htmlspecialchars($addon['addon_image_path'] ?? ''); ?>">
            <input type="hidden" name="remove_image" id="remove_image" value="0">

            <!-- ═══════════════════════════════════════════════
             BASIC INFORMATION
        ═══════════════════════════════════════════════ -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-real-blue to-real-orange p-6">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <i class="fas fa-info-circle mr-3"></i>Basic Information
                    </h2>
                </div>
                <div class="p-8 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">Accessory Name *</label>
                            <input type="text" name="addon_name" id="addon_name" required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-real-blue focus:border-transparent transition-all duration-200"
                                placeholder="Enter accessory name"
                                value="<?php echo htmlspecialchars($addon['addon_name']); ?>">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">Accessory Price *</label>
                            <div class="price-input">
                                <input type="number" name="addon_price" id="addon_price" step="0.01" min="0" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-real-blue focus:border-transparent transition-all duration-200"
                                    placeholder="0.00"
                                    value="<?php echo htmlspecialchars($addon['addon_price']); ?>">
                            </div>
                        </div>
                        <!-- Labor Cost -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">
                                Labor Cost
                                <span class="text-gray-400 font-normal text-xs ml-1">(Optional)</span>
                            </label>
                            <div class="price-input">
                                <input type="number" name="addon_labor_cost" id="addon_labor_cost" step="0.01" min="0"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-real-blue focus:border-transparent transition-all duration-200"
                                    placeholder="0.00"
                                    value="<?php echo !empty($addon['labor_cost']) ? htmlspecialchars($addon['labor_cost']) : ''; ?>">
                            </div>
                            <p class="text-xs text-gray-400">Leave blank if no labor cost applies.</p>
                        </div>
                        <!-- Addon Jack Up -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">
                                Addon Jack Up
                                <span class="text-gray-400 font-normal text-xs ml-1">(Optional)</span>
                            </label>
                            <div class="relative">
                                <input type="number" name="addon_jackup" id="addon_jackup" step="0.01" min="0" max="100"
                                    class="w-full px-4 py-3 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-real-blue focus:border-transparent transition-all duration-200"
                                    placeholder="0.00"
                                    value="<?php echo !empty($addon['addon_jackup']) ? htmlspecialchars($addon['addon_jackup']) : ''; ?>">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 font-semibold pointer-events-none">%</span>
                            </div>
                            <p class="text-xs text-gray-400">Additional jack up percentage on Accessory Price.</p>
                        </div>

                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">
                                Maximum Quantity
                                <span class="text-gray-400 font-normal text-xs ml-1">(Optional)</span>
                            </label>
                            <input type="number" name="max_quantity" id="max_quantity"
                                step="0.01" min="0"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-real-blue focus:border-transparent transition-all duration-200"
                                placeholder="e.g., 500.00"
                                value="<?php echo !empty($addon['max_quantity']) ? htmlspecialchars($addon['max_quantity']) : ''; ?>">
                            <p class="text-xs text-gray-400">Largest allowed quantity a customer can order.</p>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-gray-700">Description *</label>
                        <textarea name="addon_description" id="addon_description" rows="4" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-real-blue focus:border-transparent transition-all duration-200"
                            placeholder="Describe the addon and its benefits..."><?php echo htmlspecialchars($addon['addon_description']); ?></textarea>
                    </div>

                    <!-- Image Upload -->
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-gray-700">Addon Image</label>
                        <div class="flex items-center space-x-6">
                            <div class="camera-upload bg-gradient-to-br from-gray-100 to-gray-200 rounded-xl p-6 shadow-lg hover:shadow-xl">
                                <input type="file" name="addon_image" accept="image/*" id="addon_image"
                                    onchange="previewImage(this)">
                                <div class="text-center">
                                    <div class="text-gray-500 text-4xl mb-3 camera-icon"><i class="fas fa-camera"></i></div>
                                    <p class="text-sm font-semibold text-gray-700">Upload New Image</p>
                                    <p class="text-xs text-gray-500 mt-1">Click to select</p>
                                    <p class="text-xs text-gray-400 mt-1">Max size: 5MB</p>
                                </div>
                            </div>

                            <!-- Current / Preview -->
                            <div id="imageDisplay">
                                <?php if (!empty($addon['addon_image_path'])): ?>
                                    <div id="previewBox" class="image-preview active relative">
                                        <div class="w-32 h-32 rounded-xl overflow-hidden shadow-lg">
                                            <img id="previewImg"
                                                src="<?= BASE_URL ?>realiving_user/images/product_addons/<?php echo htmlspecialchars($addon['addon_image_path']); ?>"
                                                alt="Current image" class="w-full h-full object-cover">
                                        </div>
                                        <button type="button" onclick="removeImage()"
                                            class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs hover:bg-red-600 transition-colors">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    <p id="previewLabel" class="text-xs text-gray-500 mt-2 text-center">Current Image</p>
                                <?php else: ?>
                                    <div id="previewBox" class="image-preview hidden relative">
                                        <div class="w-32 h-32 rounded-xl overflow-hidden shadow-lg">
                                            <img id="previewImg" src="" alt="Preview" class="w-full h-full object-cover">
                                        </div>
                                        <button type="button" onclick="removeImage()"
                                            class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs hover:bg-red-600 transition-colors">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    <p id="previewLabel" class="text-xs text-gray-400 italic mt-2">No image uploaded</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════
             DIMENSION SETTINGS
        ═══════════════════════════════════════════════ -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-indigo-500 to-blue-600 p-6">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <i class="fas fa-ruler-combined mr-3"></i>Dimension Settings
                    </h2>
                    <p class="text-white text-sm mt-1 opacity-85">Enable if this accessory is sold by area or length (e.g., flooring, wallpaper, curtains)</p>
                </div>
                <div class="p-8">

                    <!-- Toggle -->
                    <div class="dimension-toggle-wrap mb-6">
                        <label class="toggle-switch">
                            <input type="checkbox" name="has_dimension" id="has_dimension"
                                onchange="toggleDimensionSection(this.checked)"
                                <?php echo !empty($addon['has_dimension']) ? 'checked' : ''; ?>>
                            <span class="toggle-knob"></span>
                        </label>
                        <div>
                            <p class="font-semibold text-gray-800 text-sm">This accessory has a dimension</p>
                            <p class="text-xs text-gray-500">Toggle on to specify measurement type, labels, and quantity constraints</p>
                        </div>
                    </div>

                    <!-- Dimension details -->
                    <div id="dimensionSection" class="section-slide <?php echo !empty($addon['has_dimension']) ? 'open' : ''; ?>">
                        <div class="border border-indigo-100 rounded-xl p-6 bg-indigo-50/40 space-y-7">

                            <!-- Dimension Type -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-3">
                                    Dimension Type <span class="text-red-500">*</span>
                                </label>
                                <div class="flex gap-4">
                                    <div class="dim-type-btn <?php echo ($addon['dimension_type'] === 'sqm') ? 'selected' : ''; ?>"
                                        onclick="selectDimType('sqm', this)">
                                        <div class="icon">⬛</div>
                                        <div class="label">Square Meter</div>
                                        <div class="sublabel">m² — Area-based (flooring, tiles, wallpaper)</div>
                                    </div>
                                    <div class="dim-type-btn <?php echo ($addon['dimension_type'] === 'lm') ? 'selected' : ''; ?>"
                                        onclick="selectDimType('lm', this)">
                                        <div class="icon">➡️</div>
                                        <div class="label">Linear Meter</div>
                                        <div class="sublabel">lm — Length-based (molding, curtain tracks, trim)</div>
                                    </div>
                                </div>
                                <input type="hidden" name="dimension_type" id="dimension_type"
                                    value="<?php echo htmlspecialchars($addon['dimension_type'] ?? ''); ?>">
                            </div>

                            <!-- 3 Dimension Labels + Values -->
                            <div>
                                <div class="flex items-center justify-between mb-3">
                                    <label class="block text-sm font-semibold text-gray-700">
                                        Dimension Labels &amp; Values
                                        <span class="text-gray-400 font-normal text-xs ml-1">(Optional)</span>
                                    </label>
                                    <span class="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded-full">
                                        Labels are shown to customers when inputting measurements
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500 mb-4">
                                    Set a label (e.g., Width, Height, Depth) and an optional default/fixed value for each dimension slot.
                                </p>

                                <div class="space-y-4">
                                    <?php
                                    $dim_slots = [
                                        1 => ['label_key' => 'dimension_label_1', 'value_key' => 'dimension_value_1'],
                                        2 => ['label_key' => 'dimension_label_2', 'value_key' => 'dimension_value_2'],
                                        3 => ['label_key' => 'dimension_label_3', 'value_key' => 'dimension_value_3'],
                                    ];
                                    $label_options = ['Width', 'Height', 'Length', 'Depth', 'Thickness', 'Diameter', 'Perimeter', 'Custom'];
                                    foreach ($dim_slots as $num => $slot):
                                        $saved_label = $addon[$slot['label_key']] ?? '';
                                        $saved_value = $addon[$slot['value_key']] ?? '';
                                        $is_custom_label = !empty($saved_label) && !in_array($saved_label, array_slice($label_options, 0, -1));
                                    ?>
                                        <div class="bg-white rounded-lg border border-indigo-100 p-4">
                                            <div class="flex items-center gap-3 mb-3">
                                                <div class="dim-row-number"><?php echo $num; ?></div>
                                                <span class="text-sm font-semibold text-gray-700">Dimension <?php echo $num; ?></span>
                                            </div>
                                            <div class="dim-row">
                                                <div class="space-y-1">
                                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide">Label</label>
                                                    <select class="dim-label-select"
                                                        id="dim_label_select_<?php echo $num; ?>"
                                                        onchange="onDimLabelChange(<?php echo $num; ?>, this.value)">
                                                        <option value="">— Select label —</option>
                                                        <?php foreach ($label_options as $opt): ?>
                                                            <option value="<?php echo $opt; ?>"
                                                                <?php echo ((!$is_custom_label && $saved_label === $opt) || ($is_custom_label && $opt === 'Custom')) ? 'selected' : ''; ?>>
                                                                <?php echo $opt; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <!-- Custom label text input -->
                                                    <input type="text"
                                                        id="dim_label_custom_<?php echo $num; ?>"
                                                        name="<?php echo $slot['label_key']; ?>"
                                                        class="w-full mt-2 px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition-all duration-200 <?php echo $is_custom_label ? '' : 'hidden'; ?>"
                                                        placeholder="Enter custom label"
                                                        value="<?php echo $is_custom_label ? htmlspecialchars($saved_label) : ''; ?>">
                                                    <!-- Hidden input for non-custom -->
                                                    <input type="hidden"
                                                        id="dim_label_hidden_<?php echo $num; ?>"
                                                        name="<?php echo $slot['label_key']; ?>"
                                                        value="<?php echo !$is_custom_label ? htmlspecialchars($saved_label) : ''; ?>">
                                                </div>
                                                <div class="space-y-1">
                                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide">Default Value</label>
                                                    <input type="number"
                                                        name="<?php echo $slot['value_key']; ?>"
                                                        id="<?php echo $slot['value_key']; ?>"
                                                        step="0.01" min="0"
                                                        class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition-all duration-200"
                                                        placeholder="e.g., 2.50"
                                                        value="<?php echo ($saved_value !== '' && $saved_value !== null) ? htmlspecialchars($saved_value) : ''; ?>">
                                                    <p class="text-xs text-gray-400">Optional fixed/default measurement</p>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Quantity Constraints -->
                            <div>
                                <p class="text-sm font-semibold text-gray-700 mb-1">
                                    Quantity Constraints
                                    <span class="text-gray-400 font-normal text-xs ml-1">(All optional)</span>
                                </p>
                                <p class="text-xs text-gray-500 mb-4">Control the range of measurement a customer can enter.</p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide">Required Unit</label>
                                        <p class="text-xs text-gray-400">Fixed unit per order item</p>
                                        <input type="number" name="required_unit" id="required_unit" step="0.01" min="0"
                                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition-all duration-200"
                                            placeholder="e.g., 1.00"
                                            value="<?php echo !empty($addon['required_unit']) ? htmlspecialchars($addon['required_unit']) : ''; ?>">
                                    </div>
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide">Min Required Unit</label>
                                        <p class="text-xs text-gray-400">Smallest allowed input</p>
                                        <input type="number" name="min_required_unit" id="min_required_unit" step="0.01" min="0"
                                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition-all duration-200"
                                            placeholder="e.g., 1.00"
                                            value="<?php echo !empty($addon['min_required_unit']) ? htmlspecialchars($addon['min_required_unit']) : ''; ?>">
                                    </div>
                                </div>
                            </div>

                            <!-- Stable Mats Toggle -->
                            <div>
                                <p class="text-sm font-semibold text-gray-700 mb-1">Stable Mats Option</p>
                                <p class="text-xs text-gray-500 mb-3">Mark this accessory as a stable mat item.</p>
                                <div class="dimension-toggle-wrap">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="is_stable_mat" id="is_stable_mat"
                                            <?php echo !empty($addon['is_stable_mat']) ? 'checked' : ''; ?>>
                                        <span class="toggle-knob"></span>
                                    </label>
                                    <div>
                                        <p class="font-semibold text-gray-800 text-sm">This accessory is a stable mat</p>
                                        <p class="text-xs text-gray-500">Toggle on if this item functions as or includes a stable mat</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Multiply Value -->
                            <div class="space-y-1 pt-2 border-t border-indigo-100">
                                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide">Multiply Value</label>
                                <p class="text-xs text-gray-400">Value to multiply against the accessory price (e.g., 2 = price × 2)</p>
                                <input type="number" name="multiply_value" id="multiply_value" step="0.01" min="0"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition-all duration-200"
                                    placeholder="e.g., 2.00"
                                    value="<?php echo !empty($addon['multiply_value']) ? htmlspecialchars($addon['multiply_value']) : ''; ?>">
                            </div>

                        </div>
                    </div>

                </div>
            </div>

            <!-- ═══════════════════════════════════════════════
             CATEGORY SELECTION
        ═══════════════════════════════════════════════ -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-purple-500 to-pink-600 p-6">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <i class="fas fa-tags mr-3"></i>Category Selection
                    </h2>
                </div>
                <div class="p-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                        <?php foreach ($existing_categories as $category): ?>
                            <div class="category-card border-2 border-gray-200 rounded-lg p-4 <?php echo ($addon['addon_category'] === $category) ? 'selected' : ''; ?>"
                                onclick="selectCategory('<?php echo htmlspecialchars($category, ENT_QUOTES); ?>', this)">
                                <div class="text-center">
                                    <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars(ucfirst($category)); ?></h3>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="category-card border-2 border-gray-200 rounded-lg p-4 <?php echo (!in_array($addon['addon_category'], $existing_categories) && !empty($addon['addon_category'])) ? 'selected' : ''; ?>"
                            onclick="selectCategory('custom', this)">
                            <div class="text-center">
                                <h3 class="font-semibold text-gray-800">+ Add New Category</h3>
                            </div>
                        </div>
                    </div>

                    <script>
                        const typesByCategory = <?php echo json_encode($types_by_category); ?>;
                    </script>

                    <div id="customCategorySection" class="<?php echo (!in_array($addon['addon_category'], $existing_categories) && !empty($addon['addon_category'])) ? '' : 'hidden'; ?> space-y-2">
                        <label class="block text-sm font-semibold text-gray-700">Custom Category Name</label>
                        <input type="text" id="custom_category_input"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200"
                            placeholder="Enter custom category name"
                            value="<?php echo (!in_array($addon['addon_category'], $existing_categories)) ? htmlspecialchars($addon['addon_category']) : ''; ?>">
                    </div>
                    <input type="hidden" name="addon_category" id="addon_category" required
                        value="<?php echo htmlspecialchars($addon['addon_category']); ?>">
                </div>
            </div>

            <!-- TYPE SELECTION -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden" id="typeSelectionSection"
                style="<?php echo !empty($addon['addon_category']) ? '' : 'display:none;'; ?>">
                <div class="bg-gradient-to-r from-green-500 to-teal-600 p-6">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <i class="fas fa-layer-group mr-3"></i>Type Selection
                    </h2>
                    <p class="text-white text-sm mt-1 opacity-90">Specify the type/finish (e.g., Wooden Finish, Metal, Glass)</p>
                </div>
                <div class="p-8">
                    <div id="typeCardsContainer" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6"></div>
                    <div id="customTypeSection" class="hidden space-y-2">
                        <label class="block text-sm font-semibold text-gray-700">Custom Type Name</label>
                        <input type="text" id="custom_type_input"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                            placeholder="Enter custom type (e.g., Wooden Finish, Metal, Glass)">
                    </div>
                    <input type="hidden" name="addon_type" id="addon_type"
                        value="<?php echo htmlspecialchars($addon['addon_type'] ?? ''); ?>">
                </div>
            </div>

            <!-- SUBMIT -->
            <div class="flex justify-end gap-4 pt-4">
                <a href="view-addons"
                    class="px-8 py-4 rounded-xl font-bold text-gray-600 bg-gray-200 hover:bg-gray-300 transition-all duration-200 flex items-center">
                    <i class="fas fa-times mr-2"></i>Cancel
                </a>
                <button type="submit"
                    class="bg-gradient-to-r from-real-blue to-real-orange text-white px-12 py-4 rounded-xl font-bold text-lg shadow-lg hover:shadow-xl transform hover:-translate-y-1 transition-all duration-200 flex items-center">
                    <i class="fas fa-save mr-3"></i>Update Accessory
                </button>
            </div>
        </form>
    </div>

    <script>
        let selectedCategory = '<?php echo addslashes($addon['addon_category'] ?? ''); ?>';
        let selectedType = '<?php echo addslashes($addon['addon_type'] ?? ''); ?>';
        let selectedDimType = '<?php echo addslashes($addon['dimension_type'] ?? ''); ?>';

        const knownCategories = <?php echo json_encode($existing_categories); ?>;

        const dimLabelDefaults = {
            sqm: ['Width', 'Length', 'Height'],
            lm: ['Length', 'Width', 'Depth'],
        };

        /* ── Image ── */
        function previewImage(input) {
            const previewBox = document.getElementById('previewBox');
            const previewImg = document.getElementById('previewImg');
            const previewLabel = document.getElementById('previewLabel');

            if (input.files && input.files[0]) {
                if (input.files[0].size > 5242880) {
                    alert('File size must be less than 5MB');
                    input.value = '';
                    return;
                }
                const reader = new FileReader();
                reader.onload = e => {
                    previewImg.src = e.target.result;
                    previewBox.classList.remove('hidden');
                    previewBox.classList.add('active');
                    previewLabel.textContent = 'New Image (will replace current)';
                    previewLabel.style.color = '#10b981';
                    document.getElementById('remove_image').value = '0';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function removeImage() {
            const previewBox = document.getElementById('previewBox');
            const previewLabel = document.getElementById('previewLabel');
            const fileInput = document.getElementById('addon_image');

            previewBox.classList.add('hidden');
            previewBox.classList.remove('active');
            previewLabel.textContent = 'No image — current image will be removed on save.';
            previewLabel.style.color = '#ef4444';
            fileInput.value = '';
            document.getElementById('remove_image').value = '1';
            document.querySelector('input[name="existing_image"]').value = '';
        }

        /* ── Dimension toggle ── */
        function toggleDimensionSection(on) {
            const dimSec = document.getElementById('dimensionSection');
            if (on) {
                dimSec.classList.add('open');
            } else {
                dimSec.classList.remove('open');
                document.getElementById('dimension_type').value = '';
                selectedDimType = '';
                document.querySelectorAll('.dim-type-btn').forEach(b => b.classList.remove('selected'));
            }
        }

        /* ── Dimension type buttons ── */
        function selectDimType(type, el) {
            document.querySelectorAll('.dim-type-btn').forEach(b => b.classList.remove('selected'));
            el.classList.add('selected');
            selectedDimType = type;
            document.getElementById('dimension_type').value = type;
            suggestDimLabels(type);
        }

        function suggestDimLabels(type) {
            const defaults = dimLabelDefaults[type] || [];
            defaults.forEach((labelName, i) => {
                const num = i + 1;
                const select = document.getElementById('dim_label_select_' + num);
                const hidden = document.getElementById('dim_label_hidden_' + num);
                const custom = document.getElementById('dim_label_custom_' + num);
                if (!select.value) {
                    select.value = labelName;
                    hidden.value = labelName;
                    custom.classList.add('hidden');
                }
            });
        }

        function onDimLabelChange(num, value) {
            const hidden = document.getElementById('dim_label_hidden_' + num);
            const custom = document.getElementById('dim_label_custom_' + num);
            if (value === 'Custom') {
                custom.classList.remove('hidden');
                hidden.value = '';
                custom.focus();
            } else {
                custom.classList.add('hidden');
                hidden.value = value;
                custom.value = '';
            }
        }

        /* ── Category ── */
        function selectCategory(category, element) {
            document.querySelectorAll('.category-card').forEach(c => c.classList.remove('selected'));
            element.classList.add('selected');
            const customSection = document.getElementById('customCategorySection');
            const categoryInput = document.getElementById('addon_category');
            const typeSection = document.getElementById('typeSelectionSection');
            if (category === 'custom') {
                customSection.classList.remove('hidden');
                selectedCategory = 'custom';
                categoryInput.value = '';
                document.getElementById('custom_category_input').focus();
                typeSection.style.display = 'none';
            } else {
                customSection.classList.add('hidden');
                selectedCategory = category;
                categoryInput.value = category;
                typeSection.style.display = 'block';
                loadTypesForCategory(category);
            }
        }

        function loadTypesForCategory(category) {
            const container = document.getElementById('typeCardsContainer');
            const customSec = document.getElementById('customTypeSection');
            const typeInput = document.getElementById('addon_type');
            container.innerHTML = '';
            customSec.classList.add('hidden');

            const types = typesByCategory[category] || [];
            let restoredType = false;

            types.forEach(type => {
                const card = document.createElement('div');
                const isSelected = (selectedType === type);
                card.className = 'type-card border-2 border-gray-200 rounded-lg p-4' + (isSelected ? ' selected' : '');
                card.onclick = function() {
                    selectType(type, this);
                };
                card.innerHTML = `<div class="text-center"><h3 class="font-semibold text-gray-800">${escapeHtml(type.charAt(0).toUpperCase()+type.slice(1))}</h3></div>`;
                container.appendChild(card);
                if (isSelected) restoredType = true;
            });

            // Add New Type card
            const custom = document.createElement('div');
            custom.className = 'type-card border-2 border-gray-200 rounded-lg p-4';
            custom.onclick = function() {
                selectType('custom', this);
            };
            custom.innerHTML = `<div class="text-center"><h3 class="font-semibold text-gray-800">+ Add New Type</h3></div>`;
            container.appendChild(custom);

            // If current type is not in the list — show as custom
            if (selectedType && !restoredType) {
                custom.classList.add('selected');
                customSec.classList.remove('hidden');
                document.getElementById('custom_type_input').value = selectedType;
                typeInput.value = selectedType;
            }
        }

        function selectType(type, element) {
            document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
            element.classList.add('selected');
            const customSec = document.getElementById('customTypeSection');
            const typeInput = document.getElementById('addon_type');
            if (type === 'custom') {
                customSec.classList.remove('hidden');
                selectedType = 'custom';
                typeInput.value = '';
                document.getElementById('custom_type_input').focus();
            } else {
                customSec.classList.add('hidden');
                selectedType = type;
                typeInput.value = type;
            }
        }

        function escapeHtml(text) {
            const d = document.createElement('div');
            d.textContent = text;
            return d.innerHTML;
        }

        document.getElementById('custom_category_input').addEventListener('input', function() {
            if (selectedCategory === 'custom') {
                document.getElementById('addon_category').value = this.value;
                const typeSection = document.getElementById('typeSelectionSection');
                if (this.value.trim() !== '') {
                    typeSection.style.display = 'block';
                    loadTypesForCategory('_custom_new');
                }
            }
        });

        document.getElementById('custom_type_input').addEventListener('input', function() {
            document.getElementById('addon_type').value = this.value;
            selectedType = this.value;
        });

        /* ── Validation ── */
        function validateForm() {
            const category = document.getElementById('addon_category').value.trim();
            if (!category) {
                alert('Please select or enter a category.');
                return false;
            }

            const hasDim = document.getElementById('has_dimension').checked;
            if (hasDim) {
                const dimType = document.getElementById('dimension_type').value.trim();
                if (!dimType) {
                    alert('Please select a dimension type (Square Meter or Linear Meter).');
                    return false;
                }
            }

            const typeSection = document.getElementById('typeSelectionSection');
            if (typeSection.style.display !== 'none' && !document.getElementById('addon_type').value.trim()) {
                alert('Please select or enter a type.');
                return false;
            }

            return true;
        }

        /* ── On page load ── */
        document.addEventListener('DOMContentLoaded', function() {
            // Sync custom label inputs
            [1, 2, 3].forEach(num => {
                const custom = document.getElementById('dim_label_custom_' + num);
                if (custom) {
                    custom.addEventListener('input', function() {
                        document.getElementById('dim_label_hidden_' + num).value = '';
                    });
                }
            });

            // Auto-hide message
            const msg = document.getElementById('messageContainer');
            if (msg) setTimeout(() => msg.style.display = 'none', 5000);

            // Restore selected dimension type button visually
            if (selectedDimType) {
                document.querySelectorAll('.dim-type-btn').forEach(function(btn) {
                    btn.classList.remove('selected');
                });
                document.querySelectorAll('.dim-type-btn').forEach(function(btn) {
                    btn.onclick.toString(); // no-op, just find by data
                });
                // Find the button whose onclick matches the saved type
                document.querySelectorAll('.dim-type-btn').forEach(function(btn) {
                    const onclickAttr = btn.getAttribute('onclick');
                    if (onclickAttr && onclickAttr.includes("'" + selectedDimType + "'")) {
                        btn.classList.add('selected');
                    }
                });
            }

            // Restore category + type state
            if (selectedCategory) {
                if (knownCategories.includes(selectedCategory)) {
                    document.getElementById('typeSelectionSection').style.display = 'block';
                    loadTypesForCategory(selectedCategory);
                } else if (selectedCategory) {
                    // custom category — already shown via PHP, load types for new
                    document.getElementById('typeSelectionSection').style.display = 'block';
                    loadTypesForCategory('_custom_new');
                }
            }

            // Dimension section state
            const hasDimCheckbox = document.getElementById('has_dimension');
            if (hasDimCheckbox.checked) {
                document.getElementById('dimensionSection').classList.add('open');
            }
        });
    </script>
</body>

</html>