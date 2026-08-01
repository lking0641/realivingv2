<?php
//add_addon.php
include $includes ['mainbody'];

// Allow only admin1 to admin5
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

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $addon_name          = trim($_POST['addon_name']);
    $addon_price         = floatval($_POST['addon_price']);
    $addon_description   = trim($_POST['addon_description']);
    $addon_category      = trim($_POST['addon_category']);
    $addon_labor_cost          = !empty($_POST['addon_labor_cost']) ? floatval($_POST['addon_labor_cost']) : null;
    $addon_jackup  = !empty($_POST['addon_jackup']) ? floatval($_POST['addon_jackup']) : null;
    $addon_type          = trim($_POST['addon_type'] ?? '');
    $has_dimension       = isset($_POST['has_dimension']) ? 1 : 0;
    $dimension_type      = $has_dimension && !empty($_POST['dimension_type']) ? trim($_POST['dimension_type']) : null;

    // 3 dimension labels
    $dimension_label_1   = $has_dimension && !empty($_POST['dimension_label_1']) ? trim($_POST['dimension_label_1']) : null;
    $dimension_label_2   = $has_dimension && !empty($_POST['dimension_label_2']) ? trim($_POST['dimension_label_2']) : null;
    $dimension_label_3   = $has_dimension && !empty($_POST['dimension_label_3']) ? trim($_POST['dimension_label_3']) : null;

    // 3 dimension values
    $dimension_value_1   = $has_dimension && isset($_POST['dimension_value_1']) && $_POST['dimension_value_1'] !== '' ? floatval($_POST['dimension_value_1']) : null;
    $dimension_value_2   = $has_dimension && isset($_POST['dimension_value_2']) && $_POST['dimension_value_2'] !== '' ? floatval($_POST['dimension_value_2']) : null;
    $dimension_value_3   = $has_dimension && isset($_POST['dimension_value_3']) && $_POST['dimension_value_3'] !== '' ? floatval($_POST['dimension_value_3']) : null;

    $required_unit       = $has_dimension && !empty($_POST['required_unit'])       ? floatval($_POST['required_unit'])       : null;
    $min_required_unit   = $has_dimension && !empty($_POST['min_required_unit'])   ? floatval($_POST['min_required_unit'])   : null;
    $max_quantity        = !empty($_POST['max_quantity']) ? floatval($_POST['max_quantity']) : null;
    $is_stable_mat       = $has_dimension && isset($_POST['is_stable_mat']) ? 1 : 0;
    $multiply_value      = !empty($_POST['multiply_value']) ? floatval($_POST['multiply_value']) : null;

    // Handle file upload
    $addon_image_path = null;

    if (!empty($_FILES['addon_image']['name']) && $_FILES['addon_image']['error'] == 0) {
        $file_extension = strtolower(pathinfo($_FILES['addon_image']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (in_array($file_extension, $allowed_extensions)) {
            if ($_FILES['addon_image']['size'] <= 5242880) {
                $upload_dir = ROOT_PATH . 'realiving_user/images/product_addons/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $unique_filename = uniqid() . '_' . time() . '.webp';
                $target_file     = $upload_dir . $unique_filename;
                $temp_file       = $upload_dir . 'temp_' . $unique_filename;

                if (move_uploaded_file($_FILES['addon_image']['tmp_name'], $temp_file)) {
                    $image = null;
                    switch ($file_extension) {
                        case 'jpg':
                        case 'jpeg':
                            $image = imagecreatefromjpeg($temp_file);
                            break;
                        case 'png':
                            $image = imagecreatefrompng($temp_file);
                            imagealphablending($image, true);
                            imagesavealpha($image, true);
                            break;
                        case 'gif':
                            $image = imagecreatefromgif($temp_file);
                            break;
                        case 'webp':
                            copy($temp_file, $target_file);
                            unlink($temp_file);
                            $addon_image_path = $unique_filename;
                            break;
                    }
                    if ($image !== null) {
                        if (imagewebp($image, $target_file, 80)) {
                            imagedestroy($image);
                            unlink($temp_file);
                            $addon_image_path = $unique_filename;
                        } else {
                            imagedestroy($image);
                            unlink($temp_file);
                            $message = "Error converting image to WebP.";
                            $messageType = 'error';
                        }
                    }
                } else {
                    $message = "Error uploading image file.";
                    $messageType = 'error';
                }
            } else {
                $message = "Image file size must be less than 5MB.";
                $messageType = 'error';
            }
        } else {
            $message = "Only JPG, JPEG, PNG, GIF, and WEBP files are allowed.";
            $messageType = 'error';
        }
    }

    if (empty($message)) {
        $stmt = $conn->prepare("
    INSERT INTO product_addons
    (addon_name, addon_price, addon_description, addon_category, addon_type, addon_image_path,
     labor_cost, labor_cost_jack_up, has_dimension, dimension_type,
         dimension_label_1, dimension_label_2, dimension_label_3,
         dimension_value_1, dimension_value_2, dimension_value_3,
         required_unit, min_required_unit, max_quantity, is_stable_mat,
         multiply_value, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
        $stmt->bind_param(
            "sdssssddissssddddddid",
            $addon_name,                // s - string
            $addon_price,               // d - double
            $addon_description,         // s - string
            $addon_category,            // s - string
            $addon_type,                // s - string
            $addon_image_path,          // s - string (nullable)
            $addon_labor_cost,          // d - double (nullable)
            $addon_jackup,              // d - double (nullable)
            $has_dimension,             // i - int (0 or 1)
            $dimension_type,            // s - string (nullable)
            $dimension_label_1,         // s - string (nullable)
            $dimension_label_2,         // s - string (nullable)
            $dimension_label_3,         // s - string (nullable)
            $dimension_value_1,         // d - double (nullable)
            $dimension_value_2,         // d - double (nullable)
            $dimension_value_3,         // d - double (nullable)
            $required_unit,             // d - double (nullable)
            $min_required_unit,         // d - double (nullable)
            $max_quantity,              // d - double (nullable)
            $is_stable_mat,             // i - int (0 or 1)
            $multiply_value             // d - double (nullable)
        );

        if ($stmt->execute()) {
            $_SESSION['noti'] = 'success';
            header("Location: " . BASE_URL . "view-addons");
            exit();
        } else {
            $message = "Error saving addon: " . $stmt->error;
            $messageType = 'error';
        }
        $stmt->close();
    }
}

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
        $message = "Product accessory has been saved successfully!";
        $messageType = 'success';
    }
    unset($_SESSION['noti']);
}

$conn->close();

// Default label suggestions per dimension type
$label_defaults = [
    'sqm' => ['Width', 'Length', 'Height'],
    'lm'  => ['Length', 'Width', 'Depth'],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Accessories Management</title>
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

        /* Dimension label/value row */
        .dim-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            align-items: start;
        }

        .dim-row-header {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 4px;
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
                    <h1 class="text-3xl font-bold text-real-dark">Product Accessories Management</h1>
                    <p class="text-gray-600 mt-2">Create and manage product accessories for enhanced customization</p>
                </div>
                <div class="hidden md:block">
                    <div class="w-16 h-16 bg-gradient-to-br from-real-blue to-real-orange rounded-full flex items-center justify-center">
                        <i class="fas fa-puzzle-piece text-white text-2xl"></i>
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
                                value="<?php echo isset($_POST['addon_name']) ? htmlspecialchars($_POST['addon_name']) : ''; ?>">
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">Accessory Price *</label>
                            <div class="price-input">
                                <input type="number" name="addon_price" id="addon_price" step="0.01" min="0" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-real-blue focus:border-transparent transition-all duration-200"
                                    placeholder="0.00"
                                    value="<?php echo isset($_POST['addon_price']) ? htmlspecialchars($_POST['addon_price']) : ''; ?>">
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">
                                Labor Cost
                                <span class="text-gray-400 font-normal text-xs ml-1">(Optional)</span>
                            </label>
                            <div class="price-input">
                                <input type="number" name="addon_labor_cost" id="addon_labor_cost" step="0.01" min="0"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-real-blue focus:border-transparent transition-all duration-200"
                                    placeholder="0.00"
                                    value="<?php echo isset($_POST['addon_labor_cost']) ? htmlspecialchars($_POST['addon_labor_cost']) : ''; ?>">
                            </div>
                            <p class="text-xs text-gray-400">Leave blank if no labor cost applies.</p>
                        </div>

                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-gray-700">
                                Addon Jack Up
                                <span class="text-gray-400 font-normal text-xs ml-1">(Optional)</span>
                            </label>
                            <div class="relative">
                                <input type="number" name="addon_jackup" id="addon_jackup" step="0.01" min="0" max="100"
                                    class="w-full px-4 py-3 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-real-blue focus:border-transparent transition-all duration-200"
                                    placeholder="0.00"
                                    value="<?php echo isset($_POST['addon_jackup']) ? htmlspecialchars($_POST['addon_jackup']) : ''; ?>">
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
                                value="<?php echo isset($_POST['max_quantity']) ? htmlspecialchars($_POST['max_quantity']) : ''; ?>">
                            <p class="text-xs text-gray-400">Largest allowed quantity a customer can order.</p>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-gray-700">Description *</label>
                        <textarea name="addon_description" id="addon_description" rows="4" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-real-blue focus:border-transparent transition-all duration-200"
                            placeholder="Describe the addon and its benefits..."><?php echo isset($_POST['addon_description']) ? htmlspecialchars($_POST['addon_description']) : ''; ?></textarea>
                    </div>

                    <!-- Image Upload -->
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-gray-700">Addon Image</label>
                        <div class="flex items-center space-x-6">
                            <div class="camera-upload bg-gradient-to-br from-gray-100 to-gray-200 rounded-xl p-6 shadow-lg hover:shadow-xl">
                                <input type="file" name="addon_image" accept="image/*" id="addon_image"
                                    onchange="previewImage(this, 'addon_image_preview')">
                                <div class="text-center">
                                    <div class="text-gray-500 text-4xl mb-3 camera-icon"><i class="fas fa-camera"></i></div>
                                    <p class="text-sm font-semibold text-gray-700">Upload Image</p>
                                    <p class="text-xs text-gray-500 mt-1">Click to select</p>
                                    <p class="text-xs text-gray-400 mt-1">Max size: 5MB</p>
                                </div>
                            </div>
                            <div id="addon_image_preview" class="image-preview hidden">
                                <div class="relative">
                                    <div class="w-32 h-32 rounded-xl overflow-hidden shadow-lg">
                                        <img src="" alt="Preview" class="w-full h-full object-cover">
                                    </div>
                                    <button type="button" onclick="removeImage('addon_image','addon_image_preview')"
                                        class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs hover:bg-red-600 transition-colors">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════
             DIMENSION TOGGLE
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
                                <?php echo (isset($_POST['has_dimension'])) ? 'checked' : ''; ?>>
                            <span class="toggle-knob"></span>
                        </label>
                        <div>
                            <p class="font-semibold text-gray-800 text-sm">This accessory has a dimension</p>
                            <p class="text-xs text-gray-500">Toggle on to specify measurement type, labels, and quantity constraints</p>
                        </div>
                    </div>

                    <!-- Dimension details — shown when toggled on -->
                    <div id="dimensionSection" class="section-slide <?php echo isset($_POST['has_dimension']) ? 'open' : ''; ?>">
                        <div class="border border-indigo-100 rounded-xl p-6 bg-indigo-50/40 space-y-7">

                            <!-- Dimension Type -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-3">
                                    Dimension Type <span class="text-red-500">*</span>
                                </label>
                                <div class="flex gap-4">
                                    <div class="dim-type-btn <?php echo (isset($_POST['dimension_type']) && $_POST['dimension_type'] === 'sqm') ? 'selected' : ''; ?>"
                                        onclick="selectDimType('sqm', this)">
                                        <div class="icon">⬛</div>
                                        <div class="label">Square Meter</div>
                                        <div class="sublabel">m² — Area-based (flooring, tiles, wallpaper)</div>
                                    </div>
                                    <div class="dim-type-btn <?php echo (isset($_POST['dimension_type']) && $_POST['dimension_type'] === 'lm') ? 'selected' : ''; ?>"
                                        onclick="selectDimType('lm', this)">
                                        <div class="icon">➡️</div>
                                        <div class="label">Linear Meter</div>
                                        <div class="sublabel">lm — Length-based (molding, curtain tracks, trim)</div>
                                    </div>
                                </div>
                                <input type="hidden" name="dimension_type" id="dimension_type"
                                    value="<?php echo isset($_POST['dimension_type']) ? htmlspecialchars($_POST['dimension_type']) : ''; ?>">
                            </div>

                            <!-- ─── 3 Dimension Labels + Values ─── -->
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
                                    The 3rd label defaults to <strong>Depth</strong> for sqm and can be changed to suit your product.
                                </p>

                                <div class="space-y-4">
                                    <?php
                                    $dim_slots = [
                                        1 => ['label_key' => 'dimension_label_1', 'value_key' => 'dimension_value_1', 'fallback_sqm' => 'Width',  'fallback_lm' => 'Length'],
                                        2 => ['label_key' => 'dimension_label_2', 'value_key' => 'dimension_value_2', 'fallback_sqm' => 'Length', 'fallback_lm' => 'Width'],
                                        3 => ['label_key' => 'dimension_label_3', 'value_key' => 'dimension_value_3', 'fallback_sqm' => 'Height', 'fallback_lm' => 'Depth'],
                                    ];
                                    foreach ($dim_slots as $num => $slot):
                                        $saved_label = isset($_POST[$slot['label_key']]) ? htmlspecialchars($_POST[$slot['label_key']]) : '';
                                        $saved_value = isset($_POST[$slot['value_key']]) ? htmlspecialchars($_POST[$slot['value_key']]) : '';
                                        $label_options = ['Width', 'Height', 'Length', 'Depth', 'Thickness', 'Diameter', 'Perimeter', 'Custom'];
                                    ?>
                                        <div class="bg-white rounded-lg border border-indigo-100 p-4">
                                            <div class="flex items-center gap-3 mb-3">
                                                <div class="dim-row-number"><?php echo $num; ?></div>
                                                <span class="text-sm font-semibold text-gray-700">Dimension <?php echo $num; ?></span>
                                            </div>
                                            <div class="dim-row">
                                                <!-- Label selector -->
                                                <div class="space-y-1">
                                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide">Label</label>
                                                    <select class="dim-label-select"
                                                        id="dim_label_select_<?php echo $num; ?>"
                                                        onchange="onDimLabelChange(<?php echo $num; ?>, this.value)">
                                                        <option value="">— Select label —</option>
                                                        <?php foreach ($label_options as $opt): ?>
                                                            <option value="<?php echo $opt; ?>" <?php echo ($saved_label === $opt) ? 'selected' : ''; ?>>
                                                                <?php echo $opt; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <!-- Custom label text input (shown when Custom is selected) -->
                                                    <input type="text"
                                                        id="dim_label_custom_<?php echo $num; ?>"
                                                        name="<?php echo $slot['label_key']; ?>"
                                                        class="w-full mt-2 px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition-all duration-200 <?php echo (!in_array($saved_label, $label_options) && $saved_label !== '') || $saved_label === 'Custom' ? '' : 'hidden'; ?>"
                                                        placeholder="Enter custom label"
                                                        value="<?php echo (!in_array($saved_label, array_slice($label_options, 0, -1))) ? $saved_label : ''; ?>">
                                                    <!-- Hidden input synced for non-custom selections -->
                                                    <input type="hidden"
                                                        id="dim_label_hidden_<?php echo $num; ?>"
                                                        name="<?php echo $slot['label_key']; ?>"
                                                        value="<?php echo in_array($saved_label, array_slice($label_options, 0, -1)) ? $saved_label : ''; ?>">
                                                </div>
                                                <!-- Value input -->
                                                <div class="space-y-1">
                                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide">Default Value</label>
                                                    <input type="number"
                                                        name="<?php echo $slot['value_key']; ?>"
                                                        id="<?php echo $slot['value_key']; ?>"
                                                        step="0.01" min="0"
                                                        class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition-all duration-200"
                                                        placeholder="e.g., 2.50"
                                                        value="<?php echo $saved_value; ?>">
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
                                            value="<?php echo isset($_POST['required_unit']) ? htmlspecialchars($_POST['required_unit']) : ''; ?>">
                                    </div>
                                    <div class="space-y-1">
                                        <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide">Min Required Unit</label>
                                        <p class="text-xs text-gray-400">Smallest allowed input</p>
                                        <input type="number" name="min_required_unit" id="min_required_unit" step="0.01" min="0"
                                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition-all duration-200"
                                            placeholder="e.g., 1.00"
                                            value="<?php echo isset($_POST['min_required_unit']) ? htmlspecialchars($_POST['min_required_unit']) : ''; ?>">
                                    </div>
                                </div>
                            </div>

                            <!-- Stable Mats Toggle (inside dimension section) -->
                            <div>
                                <p class="text-sm font-semibold text-gray-700 mb-1">Stable Mats Option</p>
                                <p class="text-xs text-gray-500 mb-3">Mark this accessory as a stable mat item.</p>
                                <div class="dimension-toggle-wrap">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="is_stable_mat" id="is_stable_mat"
                                            <?php echo (isset($_POST['is_stable_mat'])) ? 'checked' : ''; ?>>
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
                                    value="<?php echo isset($_POST['multiply_value']) ? htmlspecialchars($_POST['multiply_value']) : ''; ?>">
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
                            <div class="category-card border-2 border-gray-200 rounded-lg p-4 <?php echo (isset($_POST['addon_category']) && $_POST['addon_category'] === $category) ? 'selected' : ''; ?>"
                                onclick="selectCategory('<?php echo htmlspecialchars($category); ?>', this)">
                                <div class="text-center">
                                    <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars(ucfirst($category)); ?></h3>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="category-card border-2 border-gray-200 rounded-lg p-4 <?php echo (isset($_POST['addon_category']) && !in_array($_POST['addon_category'], $existing_categories) && !empty($_POST['addon_category'])) ? 'selected' : ''; ?>"
                            onclick="selectCategory('custom', this)">
                            <div class="text-center">
                                <h3 class="font-semibold text-gray-800">+ Add New Category</h3>
                            </div>
                        </div>
                    </div>

                    <script>
                        const typesByCategory = <?php echo json_encode($types_by_category); ?>;
                    </script>

                    <div id="customCategorySection" class="<?php echo (isset($_POST['addon_category']) && !in_array($_POST['addon_category'], $existing_categories) && !empty($_POST['addon_category'])) ? '' : 'hidden'; ?> space-y-2">
                        <label class="block text-sm font-semibold text-gray-700">Custom Category Name</label>
                        <input type="text" id="custom_category_input"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200"
                            placeholder="Enter custom category name"
                            value="<?php echo (isset($_POST['addon_category']) && !in_array($_POST['addon_category'], $existing_categories)) ? htmlspecialchars($_POST['addon_category']) : ''; ?>">
                    </div>
                    <input type="hidden" name="addon_category" id="addon_category" required
                        value="<?php echo isset($_POST['addon_category']) ? htmlspecialchars($_POST['addon_category']) : ''; ?>">
                </div>
            </div>

            <!-- TYPE SELECTION -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden" id="typeSelectionSection" style="display:none;">
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
                    <input type="hidden" name="addon_type" id="addon_type" value="">
                </div>
            </div>

            <!-- SUBMIT -->
            <div class="flex justify-center pt-8">
                <button type="submit"
                    class="bg-gradient-to-r from-real-blue to-real-orange text-white px-12 py-4 rounded-xl font-bold text-lg shadow-lg hover:shadow-xl transform hover:-translate-y-1 transition-all duration-200 flex items-center">
                    <i class="fas fa-save mr-3"></i>Save Accessory
                </button>
            </div>
        </form>
    </div>

    <script>
        let selectedCategory = '<?php echo isset($_POST['addon_category']) ? addslashes($_POST['addon_category']) : ''; ?>';
        let selectedType = '<?php echo isset($_POST['addon_type'])     ? addslashes($_POST['addon_type'])     : ''; ?>';
        let selectedDimType = '<?php echo isset($_POST['dimension_type']) ? addslashes($_POST['dimension_type']) : ''; ?>';

        // Default label suggestions per dimension type
        const dimLabelDefaults = {
            sqm: ['Width', 'Length', 'Height'],
            lm: ['Length', 'Width', 'Depth'],
        };

        /* ── Image ── */
        function previewImage(input, previewId) {
            const preview = document.getElementById(previewId);
            const img = preview.querySelector('img');
            if (input.files && input.files[0]) {
                if (input.files[0].size > 5242880) {
                    alert('File size must be less than 5MB');
                    input.value = '';
                    return;
                }
                const reader = new FileReader();
                reader.onload = e => {
                    img.src = e.target.result;
                    preview.classList.remove('hidden');
                    preview.classList.add('active');
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function removeImage(inputId, previewId) {
            document.getElementById(inputId).value = '';
            const p = document.getElementById(previewId);
            p.classList.add('hidden');
            p.classList.remove('active');
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
            // Apply default label suggestions
            suggestDimLabels(type);
        }

        /* ── Suggest default labels based on dimension type ── */
        function suggestDimLabels(type) {
            const defaults = dimLabelDefaults[type] || [];
            defaults.forEach((labelName, i) => {
                const num = i + 1;
                const select = document.getElementById('dim_label_select_' + num);
                const hidden = document.getElementById('dim_label_hidden_' + num);
                const custom = document.getElementById('dim_label_custom_' + num);
                // Only auto-fill if currently empty
                if (!select.value) {
                    select.value = labelName;
                    hidden.value = labelName;
                    custom.classList.add('hidden');
                }
            });
        }

        /* ── Dimension label change handler ── */
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

        // Sync custom label inputs — only one name attr per slot
        // (the hidden input carries the value for non-custom; the custom text input for custom)
        document.addEventListener('DOMContentLoaded', function() {
            [1, 2, 3].forEach(num => {
                const custom = document.getElementById('dim_label_custom_' + num);
                if (custom) {
                    custom.addEventListener('input', function() {
                        // When custom is visible, this field carries the name already
                        // Hidden input is cleared so there's no duplicate submission
                        document.getElementById('dim_label_hidden_' + num).value = '';
                    });
                }
            });

            // Auto-hide message
            const msg = document.getElementById('messageContainer');
            if (msg) setTimeout(() => msg.style.display = 'none', 5000);

            // Restore category state
            if (selectedCategory && selectedCategory !== 'custom') {
                document.getElementById('typeSelectionSection').style.display = 'block';
                loadTypesForCategory(selectedCategory);
            }

            // Ensure dimension section state matches checkbox on load
            const hasDimCheckbox = document.getElementById('has_dimension');
            if (hasDimCheckbox.checked) {
                document.getElementById('dimensionSection').classList.add('open');
                document.getElementById('linkDimensionSection').classList.remove('open');
            } else {
                document.getElementById('linkDimensionSection').classList.add('open');
            }
        });

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
            typeInput.value = '';
            selectedType = '';
            const types = typesByCategory[category] || [];
            types.forEach(type => {
                const card = document.createElement('div');
                card.className = 'type-card border-2 border-gray-200 rounded-lg p-4' + (selectedType === type ? ' selected' : '');
                card.onclick = function() {
                    selectType(type, this);
                };
                card.innerHTML = `<div class="text-center"><h3 class="font-semibold text-gray-800">${escapeHtml(type.charAt(0).toUpperCase()+type.slice(1))}</h3></div>`;
                container.appendChild(card);
            });
            const custom = document.createElement('div');
            custom.className = 'type-card border-2 border-gray-200 rounded-lg p-4';
            custom.onclick = function() {
                selectType('custom', this);
            };
            custom.innerHTML = `<div class="text-center"><h3 class="font-semibold text-gray-800">+ Add New Type</h3></div>`;
            container.appendChild(custom);
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
    </script>
</body>

</html>