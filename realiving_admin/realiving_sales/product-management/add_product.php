<?php
//add_product.php
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

// Function to handle file upload and save to directory
function handleFileUpload($file, $folder_name)
{
    if (isset($file) && $file['error'] === UPLOAD_ERR_OK) {
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');
        
        if (in_array($file_extension, $allowed_extensions)) {
            // Check file size (limit to 5MB)
            if ($file['size'] <= 5242880) {
                // Define upload directory
                $upload_dir = ROOT_PATH . 'realiving_user/images/' . $folder_name . '/';
                
                // Create directory if it doesn't exist
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                // Generate unique filename with .webp extension
                $unique_filename = uniqid() . '_' . time() . '.webp';
                $target_file = $upload_dir . $unique_filename;
                
                // Move uploaded file to temporary location first
                $temp_file = $upload_dir . 'temp_' . $unique_filename;
                if (move_uploaded_file($file['tmp_name'], $temp_file)) {
                    // Convert to WebP
                    if (convertToWebP($temp_file, $target_file, $file_extension)) {
                        // Delete temporary file
                        unlink($temp_file);
                        return $unique_filename; // Return only filename
                    } else {
                        // If conversion fails, delete temp file
                        unlink($temp_file);
                    }
                }
            }
        }
    }
    return null;
}

// Add this new function right after handleFileUpload
function convertToWebP($source, $destination, $source_extension)
{
    $image = null;
    
    // Create image resource based on file type
    switch ($source_extension) {
        case 'jpg':
        case 'jpeg':
            $image = imagecreatefromjpeg($source);
            break;
        case 'png':
            $image = imagecreatefrompng($source);
            // Preserve transparency for PNG
            imagealphablending($image, true);
            imagesavealpha($image, true);
            break;
        case 'gif':
            $image = imagecreatefromgif($source);
            break;
        case 'webp':
            // If already WebP, just copy it
            return copy($source, $destination);
    }
    
    if ($image !== null) {
        // Convert to WebP with quality 80 (you can adjust this)
        $result = imagewebp($image, $destination, 80);
        imagedestroy($image);
        return $result;
    }
    
    return false;
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
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $dimension_labels[] = $row;
        }
    }
} catch (Exception $e) {
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4'>Error fetching dimension labels: " . $e->getMessage() . "</div>";
}
$dimension_labels_json_output = json_encode($dimension_labels_json);

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->autocommit(FALSE); // Start transaction

    try {
        $success_count = 0;
        
        // Process each item
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item_index => $item_data) {
                
                // First, insert dimension measurements
                $stmt = $conn->prepare("INSERT INTO dimension_measurement (
                        item_width_linear, item_height_linear, item_length_linear,
                        item_width_sqm, item_height_sqm, item_length_sqm,
                        startup_width_linear, startup_height_linear, startup_length_linear,
                        startup_width_sqm, startup_height_sqm, startup_length_sqm
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $stmt->bind_param(
                    "dddddddddddd",
                    $item_data['item_width_linear'],
                    $item_data['item_height_linear'],
                    $item_data['item_length_linear'],
                    $item_data['item_width_sqm'],
                    $item_data['item_height_sqm'],
                    $item_data['item_length_sqm'],
                    $item_data['startup_width_linear'],
                    $item_data['startup_height_linear'],
                    $item_data['startup_length_linear'],
                    $item_data['startup_width_sqm'],
                    $item_data['startup_height_sqm'],
                    $item_data['startup_length_sqm']
                );

                if (!$stmt->execute()) {
                    throw new Exception("Error inserting dimension measurements for item " . ($item_index + 1) . ": " . $stmt->error);
                }

                $dimension_msmt_id = $conn->insert_id;
                $stmt->close();

                // Handle item image
$item_image_path = null;
if (isset($_FILES['items']['tmp_name'][$item_index]['item_image']) &&
    $_FILES['items']['error'][$item_index]['item_image'] === UPLOAD_ERR_OK) {
    $file_info = array(
        'name' => $_FILES['items']['name'][$item_index]['item_image'],
        'type' => $_FILES['items']['type'][$item_index]['item_image'],
        'tmp_name' => $_FILES['items']['tmp_name'][$item_index]['item_image'],
        'error' => $_FILES['items']['error'][$item_index]['item_image'],
        'size' => $_FILES['items']['size'][$item_index]['item_image']
    );
    $item_image_path = handleFileUpload($file_info, 'products');
}

                // Insert main item
// Check if is_fixed_modular is set, default to 0 if not
$is_fixed_modular = isset($item_data['is_fixed_modular']) ? 1 : 0;

// Set dimension and pricing values to NULL if fixed modular
$dimension_label_fk = $is_fixed_modular ? NULL : $item_data['dimension_label_fk'];
$dimension_msmt_fk_value = $is_fixed_modular ? NULL : $dimension_msmt_id;
$non_project_price = $is_fixed_modular ? NULL : $item_data['non_project_price'];
$project_price = $is_fixed_modular ? NULL : $item_data['project_price'];
$jackup = $is_fixed_modular ? NULL : $item_data['jackup'];
$mark_up = $is_fixed_modular ? NULL : $item_data['mark_up'];
$labor_cost = $is_fixed_modular ? NULL : $item_data['labor_cost'];

$stmt = $conn->prepare("INSERT INTO items (
    dimension_label_fk, dimension_msmt_fk, item_image_path, item_code, item_name, 
    item_family, item_family_2, item_material, door_material, item_color, is_fixed_modular, non_project_price, project_price, jackup, mark_up, labor_cost
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$stmt->bind_param(
    "iisssssssssddddd",
    $item_data['dimension_label_fk'],
    $dimension_msmt_id,
    $item_image_path,
    $item_data['item_code'],
    $item_data['item_name'],
    $item_data['item_family'],
    $item_data['item_family_2'],
    $item_data['item_material'],
    $item_data['door_material'],
    $item_data['item_color'],
    $is_fixed_modular,
    $item_data['non_project_price'],
    $item_data['project_price'],
    $item_data['jackup'],
    $item_data['mark_up'],
    $item_data['labor_cost']
);

                if (!$stmt->execute()) {
                    throw new Exception("Error inserting item " . ($item_index + 1) . ": " . $stmt->error);
                }

                $item_id = $conn->insert_id;
                $stmt->close();

                // Process standard colors for this item if any
                if (isset($_POST['standard_colors'][$item_index]) && is_array($_POST['standard_colors'][$item_index])) {
                    foreach ($_POST['standard_colors'][$item_index] as $color_index => $color_name) {
                        if (!empty($color_name)) {
                            $standard_color_image_path = null;

// Check if standard color image was uploaded
if (isset($_FILES['standard_color_images']['tmp_name'][$item_index][$color_index]) &&
    $_FILES['standard_color_images']['error'][$item_index][$color_index] === UPLOAD_ERR_OK) {
    $file_info = array(
        'name' => $_FILES['standard_color_images']['name'][$item_index][$color_index],
        'type' => $_FILES['standard_color_images']['type'][$item_index][$color_index],
        'tmp_name' => $_FILES['standard_color_images']['tmp_name'][$item_index][$color_index],
        'error' => $_FILES['standard_color_images']['error'][$item_index][$color_index],
        'size' => $_FILES['standard_color_images']['size'][$item_index][$color_index]
    );
    $standard_color_image_path = handleFileUpload($file_info, 'product_colors');
}

$stmt = $conn->prepare("INSERT INTO item_standard_color (fk_standard_color, standard_color, standard_color_image_path) VALUES (?, ?, ?)");
$stmt->bind_param("iss", $item_id, $color_name, $standard_color_image_path);

                            if (!$stmt->execute()) {
                                throw new Exception("Error inserting standard color for item " . ($item_index + 1) . ": " . $stmt->error);
                            }
                            $stmt->close();
                        }
                    }
                }
                
                $success_count++;
            }
        }

        // Commit transaction
        $conn->commit();
        echo "<div class='adm-alert-box is-success mb-6 mx-6'>
                    <div class='flex items-center gap-2'>
                        <i class='fas fa-circle-check'></i>
                        <span class='font-semibold'>Success!</span>
                    </div>
                    <p class='mt-1'>{$success_count} item(s) and all associated data inserted successfully!</p>
                  </div>";

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        echo "<div class='adm-alert-box is-error mb-6 mx-6'>
                    <div class='flex items-center gap-2'>
                        <i class='fas fa-triangle-exclamation'></i>
                        <span class='font-semibold'>Error!</span>
                    </div>
                    <p class='mt-1'>" . $e->getMessage() . "</p>
                  </div>";
    }

    $conn->autocommit(TRUE); // Reset autocommit
}

$conn->close();
?>

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

    body{ font-family:'Inter', sans-serif; }

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

    .adm-header-card{
        background: var(--adm-surface); border:1px solid var(--adm-line);
        border-radius:12px; padding:2rem;
    }
    .adm-header-icon{
        width:56px; height:56px; border-radius:14px;
        background: var(--adm-ink); color:#fff;
        display:flex; align-items:center; justify-content:center; font-size:22px;
    }

    /* ── Alert boxes (server-rendered) ─────── */
    .adm-alert-box{
        border:1px solid var(--adm-line); border-left:3px solid var(--adm-ink);
        background: var(--adm-surface); border-radius:9px;
        padding:.9rem 1.1rem; font-size:13.5px; color: var(--adm-ink);
    }
    .adm-alert-box.is-success{ border-left-color:#16A34A; }
    .adm-alert-box.is-success i{ color:#16A34A; }
    .adm-alert-box.is-error{ border-left-color:#DC2626; }
    .adm-alert-box.is-error i{ color:#DC2626; }

    /* ── Buttons ────────────────────────────── */
    .adm-btn{
        display:inline-flex; align-items:center; gap:8px;
        background: var(--adm-ink); color:#fff;
        font-size:13.5px; font-weight:600;
        padding:.85rem 1.5rem; border-radius:10px;
        border:1px solid var(--adm-ink);
        transition: opacity .2s ease, transform .2s ease;
        cursor:pointer;
    }
    .adm-btn:hover{ opacity:.85; transform: translateY(-1px); color:#fff; }
    .adm-btn-danger{
        display:inline-flex; align-items:center; gap:6px;
        background:#FEF2F2; color:#DC2626;
        font-size:12px; font-weight:600;
        padding:.55rem 1rem; border-radius:8px;
        border:1px solid #FECACA;
        transition: background .2s ease, transform .2s ease;
        cursor:pointer;
    }
    .adm-btn-danger:hover{ background:#FEE2E2; transform: translateY(-1px); }
    .adm-btn-accent{
        display:inline-flex; align-items:center; gap:6px;
        background: var(--adm-surface); color: var(--adm-ink);
        font-size:12.5px; font-weight:600;
        padding:.6rem 1.1rem; border-radius:8px;
        border:1px solid var(--adm-line);
        transition: border-color .2s ease, transform .2s ease;
        cursor:pointer;
    }
    .adm-btn-accent:hover{ border-color: var(--adm-ink); transform: translateY(-1px); }
    .adm-btn-submit{
        display:inline-flex; align-items:center; gap:10px;
        background: var(--adm-ink); color:#fff;
        font-size:15px; font-weight:700;
        padding:1rem 2.5rem; border-radius:12px;
        border:1px solid var(--adm-ink);
        transition: opacity .2s ease, transform .2s ease;
    }
    .adm-btn-submit:hover{ opacity:.85; transform: translateY(-1px); }

    /* ── Item card shell ────────────────────── */
    .item-card {
        background: var(--adm-surface);
        border-radius: 14px;
        border: 1px solid var(--adm-line);
    }
    .adm-item-header-badge{
        width:38px; height:38px; border-radius:999px;
        background: var(--adm-ink); color:#fff;
        display:flex; align-items:center; justify-content:center; font-size:16px;
    }

    /* ── Sub-sections ───────────────────────── */
    .adm-subsection{
        background: var(--adm-bg);
        border-radius:12px; padding:1.5rem;
        border:1px solid var(--adm-line);
    }
    .dimension-section, .standard-color-section{
        background: var(--adm-surface);
        border:1px solid var(--adm-line);
        border-radius:12px;
    }
    .adm-inner-panel{
        background: var(--adm-surface);
        border-radius:10px; padding:1.25rem;
        border:1px solid var(--adm-line);
    }
    .adm-inner-panel-alt{
        background: var(--adm-bg);
        border-radius:10px; padding:1.25rem;
        border:1px solid var(--adm-line);
    }

    /* ── Form fields ─────────────────────────── */
    .adm-label{ font-size:12.5px; font-weight:600; color: var(--adm-ink); margin-bottom:.4rem; display:block; }
    .adm-field{
        width:100%; padding:.75rem 1rem; border-radius:9px;
        border:1px solid var(--adm-line); background: var(--adm-surface);
        font-size:13.5px; color: var(--adm-ink);
        font-family:'Inter', sans-serif;
        transition: border-color .2s ease;
    }
    .adm-field:focus{ outline:none; border-color: var(--adm-ink); }
    .adm-field:disabled{ background: var(--adm-line); cursor: not-allowed; color: var(--adm-muted); }

    /* ── Upload ─────────────────────────────── */
    .camera-upload {
        position: relative;
        transition: all 0.2s ease;
        background: var(--adm-surface);
        border:2px dashed var(--adm-line);
        border-radius:12px;
    }
    .camera-upload:hover { border-color: var(--adm-ink); }
    .camera-upload input[type="file"] {
        position: absolute; opacity: 0; width: 100%; height: 100%; cursor: pointer;
    }
    .camera-icon{ transition: all .2s ease; color: var(--adm-muted); }
    .camera-upload:hover .camera-icon{ transform: scale(1.08); color: var(--adm-ink); }

    .image-preview { transition: all .2s ease; border: 2px solid transparent; }
    .image-preview.active { border-color: var(--adm-ink); }

    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
    .upload-animation { animation: pulse 2s infinite; }

    @keyframes adm-fade{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }
    .adm-fade{ animation: adm-fade .4s ease both; }
    @media (prefers-reduced-motion: reduce){ .adm-fade{ animation:none; } }
</style>

<div class="min-h-screen" style="background: var(--adm-bg); font-family:'Inter', sans-serif;">
<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-10 max-w-6xl">
        <!-- Header -->
        <div class="adm-header-card mb-8 adm-fade">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div>
                    <a href="<?= BASE_URL ?>view-products" class="adm-back">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Products</span>
                    </a>
                    <div class="adm-eyebrow mb-2">Catalog</div>
                    <h1 class="adm-title">Add Multiple Items</h1>
                    <p class="adm-subtitle mt-1">Create comprehensive item entries with dimensions and colors.</p>
                </div>
                <div class="hidden md:block">
                    <div class="adm-header-icon">
                        <i class="fas fa-boxes-stacked"></i>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <div id="itemsContainer" class="space-y-6">
                <p style="color:var(--adm-muted);" class="text-center py-16 text-base">No items added yet. Click "Add Item" to get started.</p>
            </div>

            <!-- Add Item Button (moved to bottom) -->
            <div id="addItemSection" class="hidden flex justify-center pt-2">
                <button type="button" onclick="addNewItem()" class="adm-btn">
                    <i class="fas fa-plus"></i>
                    <span>Add Another Item</span>
                </button>
            </div>

            <!-- Submit Button -->
            <div id="submitSection" class="hidden flex justify-center pt-6">
                <button type="submit" class="adm-btn-submit">
                    <i class="fas fa-floppy-disk"></i>
                    <span>Save All Items</span>
                </button>
            </div>
        </form>

        <!-- Initial Add Item Button -->
        <div id="initialAddButton" class="flex justify-center pt-6">
            <button type="button" onclick="addNewItem()" class="adm-btn">
                <i class="fas fa-plus"></i>
                <span>Add Item</span>
            </button>
        </div>
    </div>
</div>

    <script>
        let itemCount = 0;
        let standardColorCounts = {};
        const dimensionLabelsData = <?php echo $dimension_labels_json_output; ?>;

        function previewImage(input, previewId) {
            const preview = document.getElementById(previewId);
            const img = preview.querySelector('img');

            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    img.src = e.target.result;
                    preview.classList.remove('hidden');
                    preview.classList.add('active');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function removeImage(inputId, previewId) {
            const input = document.getElementById(inputId);
            const preview = document.getElementById(previewId);

            input.value = '';
            preview.classList.add('hidden');
            preview.classList.remove('active');
        }

        function addNewItem() {
            const container = document.getElementById('itemsContainer');
            standardColorCounts[itemCount] = 0;

            // Remove the "no items" message if it exists
            const noItemsMsg = container.querySelector('p');
            if (noItemsMsg) {
                noItemsMsg.remove();
            }

            // Show/hide buttons appropriately
            document.getElementById('submitSection').classList.remove('hidden');
            document.getElementById('addItemSection').classList.remove('hidden');
            document.getElementById('initialAddButton').classList.add('hidden');

            const itemDiv = document.createElement('div');
            itemDiv.className = 'item-card p-6 sm:p-8';
            itemDiv.id = 'item_' + itemCount;
            itemDiv.innerHTML = `
                <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
                    <h2 class="text-lg font-bold flex items-center gap-3" style="color:var(--adm-ink);">
                        <div class="adm-item-header-badge">
                            <i class="fas fa-box"></i>
                        </div>
                        Item ${itemCount + 1}
                    </h2>
                    <button type="button" onclick="removeItem(${itemCount})" class="adm-btn-danger">
                        <i class="fas fa-trash-can"></i>
                        Remove Item
                    </button>
                </div>

                <!-- Item Information -->
                <div class="adm-subsection mb-6">
                    <h3 class="text-sm font-bold mb-4 flex items-center gap-2" style="color:var(--adm-ink);">
                        <i class="fas fa-circle-info" style="color:var(--adm-soft);"></i>
                        Item Information
                    </h3>

                    <div class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="adm-label">Item Code *</label>
                                <input type="text" name="items[${itemCount}][item_code]" required class="adm-field">
                            </div>

                            <div>
                                <label class="adm-label">Item Name *</label>
                                <input type="text" name="items[${itemCount}][item_name]" required class="adm-field">
                            </div>

                            <div>
    <label class="adm-label">Item Family Variant 1</label>
    <input type="text" name="items[${itemCount}][item_family]" class="adm-field">
</div>
<div>
    <label class="adm-label">Item Family Variant 2</label>
    <input type="text" name="items[${itemCount}][item_family_2]" class="adm-field">
</div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="adm-label">Carcass Material</label>
        <input type="text" name="items[${itemCount}][item_material]" class="adm-field">
    </div>
    <div>
        <label class="adm-label">Door Material</label>
        <input type="text" name="items[${itemCount}][door_material]" class="adm-field">
    </div>
</div>

<!-- Fixed Modular Checkbox -->
<div>
    <label class="flex items-center gap-3 cursor-pointer">
        <input type="checkbox" 
               id="fixed_modular_${itemCount}" 
               name="items[${itemCount}][is_fixed_modular]"
               value="1"
               onchange="toggleFixedModular(${itemCount})"
               class="w-5 h-5 rounded" style="accent-color: var(--adm-ink);">
        <span class="text-sm font-semibold" style="color:var(--adm-ink);">Fixed Modular (Disable pricing and dimensions)</span>
    </label>
</div>

                        <!-- Enhanced Image Upload -->
                        <div>
                            <label class="adm-label">Item Image</label>
                            <div class="flex items-center gap-6">
                                <!-- Camera Upload Button -->
                                <div class="camera-upload p-6">
                                    <input type="file" name="items[${itemCount}][item_image]" accept="image/*" 
                                           id="item_image_${itemCount}"
                                           onchange="previewImage(this, 'item_image_preview_${itemCount}')">
                                    <div class="text-center">
                                        <div class="camera-icon text-4xl mb-3">
                                            <i class="fas fa-camera"></i>
                                        </div>
                                        <p class="text-sm font-semibold" style="color:var(--adm-ink);">Upload Image</p>
                                        <p class="text-xs mt-1" style="color:var(--adm-muted);">Click to select</p>
                                    </div>
                                </div>

                                <!-- Image Preview -->
                                <div id="item_image_preview_${itemCount}" class="image-preview hidden">
                                    <div class="relative">
                                        <div class="w-32 h-32 rounded-xl overflow-hidden" style="border:1px solid var(--adm-line);">
                                            <img src="" alt="Preview" class="w-full h-full object-cover">
                                        </div>
                                        <button type="button" onclick="removeImage('item_image_${itemCount}', 'item_image_preview_${itemCount}')"
                                            class="absolute -top-2 -right-2 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs transition-colors" style="background:#DC2626;">
                                            <i class="fas fa-xmark"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Item Color Input Field -->
                        <div>
                            <label class="adm-label">Item Color</label>
                            <input type="text" name="items[${itemCount}][item_color]" class="adm-field"
                                placeholder="Enter item color (e.g., White, Black, Natural Wood)">
                        </div>

                        <!-- Pricing -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4" id="pricing_section_${itemCount}">
    <div>
        <label class="adm-label">Non-Project Price</label>
        <input type="number" name="items[${itemCount}][non_project_price]" step="0.01" id="non_project_price_${itemCount}" class="adm-field">
    </div>

    <div>
        <label class="adm-label">Project Price</label>
        <input type="number" name="items[${itemCount}][project_price]" step="0.01" id="project_price_${itemCount}" class="adm-field">
    </div>

    <div>
        <label class="adm-label">Dimension Adjustment (%)</label>
        <input type="number" name="items[${itemCount}][jackup]" step="0.01" id="jackup_${itemCount}" class="adm-field">
    </div>

    <div>
        <label class="adm-label">Jack Up (%)</label>
        <input type="number" name="items[${itemCount}][mark_up]" step="0.01" id="mark_up_${itemCount}" class="adm-field">
    </div>

    <div>
        <label class="adm-label">Labor Cost</label>
        <input type="number" name="items[${itemCount}][labor_cost]" step="0.01" id="labor_cost_${itemCount}" class="adm-field">
    </div>
</div>
                    </div>
                </div>

                <!-- Dimension Information -->
<div class="dimension-section p-6 mb-6" id="dimension_section_${itemCount}">
                    <h3 class="text-sm font-bold mb-4 flex items-center gap-2" style="color:var(--adm-ink);">
                        <i class="fas fa-ruler-combined" style="color:var(--adm-soft);"></i>
                        Dimension Information
                    </h3>

                    <div class="space-y-6">
                        <!-- Dimension Label -->
                        <select name="items[${itemCount}][dimension_label_fk]" required
                            id="dimension_label_select_${itemCount}"
                            onchange="updateDimensionLabels(${itemCount}, this.value)"
                            class="adm-field">
                            <option value="">Select Dimension Label</option>
                            <?php foreach ($dimension_labels as $label): ?>
                                <option value="<?php echo htmlspecialchars($label['dimension_label_id']); ?>">
                                    <?php echo htmlspecialchars($label['dimension_label_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Item Dimensions -->
                        <div class="adm-inner-panel">
                            <h4 class="text-sm font-semibold mb-4 flex items-center gap-2" style="color:var(--adm-ink);">
                                <i class="fas fa-cube" style="color:var(--adm-soft);"></i>
                                Item Dimensions
                            </h4>

                            <!-- Linear Measurements -->
                            <div class="mb-6">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-3">
                                    <div>
                                        <label class="adm-label" id="label_width_linear_${itemCount}">Width (Linear)</label>
                                        <input type="number" name="items[${itemCount}][item_width_linear]" step="0.01" class="adm-field">
                                    </div>

                                    <div>
                                        <label class="adm-label" id="label_height_linear_${itemCount}">Height (Linear)</label>
                                        <input type="number" name="items[${itemCount}][item_height_linear]" step="0.01" class="adm-field">
                                    </div>

                                    <div>
                                        <label class="adm-label" id="label_length_linear_${itemCount}">Length (Linear)</label>
                                        <input type="number" name="items[${itemCount}][item_length_linear]" step="0.01" class="adm-field">
                                    </div>
                                </div>
                                <h5 class="text-xs font-semibold text-center" style="color:var(--adm-muted);">Linear Measurements</h5>
                            </div>

                            <!-- Square Meter Measurements -->
                            <div class="mb-4">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-3">
                                    <div>
                                        <label class="adm-label" id="label_width_sqm_${itemCount}">Width (SqM)</label>
                                        <input type="number" name="items[${itemCount}][item_width_sqm]" step="0.01" class="adm-field">
                                    </div>

                                    <div>
                                        <label class="adm-label" id="label_height_sqm_${itemCount}">Height (SqM)</label>
                                        <input type="number" name="items[${itemCount}][item_height_sqm]" step="0.01" class="adm-field">
                                    </div>

                                    <div>
                                        <label class="adm-label" id="label_length_sqm_${itemCount}">Length (SqM)</label>
                                        <input type="number" name="items[${itemCount}][item_length_sqm]" step="0.01" class="adm-field">
                                    </div>
                                </div>
                                <h5 class="text-xs font-semibold text-center" style="color:var(--adm-muted);">Square Meter Measurements</h5>
                            </div>

                            <!-- Startup Dimensions -->
                            <div class="adm-inner-panel-alt">
                                <h5 class="text-sm font-semibold mb-4 flex items-center gap-2" style="color:var(--adm-ink);">
                                    <i class="fas fa-rocket" style="color:var(--adm-soft);"></i>
                                    Startup Dimensions
                                </h5>

                                <!-- Linear Measurements -->
                                <div class="mb-6">
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-3">
                                        <div>
                                            <label class="adm-label" id="startup_label_width_linear_${itemCount}">Width (Linear)</label>
                                            <input type="number" name="items[${itemCount}][startup_width_linear]" step="0.01" class="adm-field">
                                        </div>
                                        <div>
                                            <label class="adm-label" id="startup_label_height_linear_${itemCount}">Height (Linear)</label>
                                            <input type="number" name="items[${itemCount}][startup_height_linear]" step="0.01" class="adm-field">
                                        </div>
                                        <div>
                                            <label class="adm-label" id="startup_label_length_linear_${itemCount}">Length (Linear)</label>
                                            <input type="number" name="items[${itemCount}][startup_length_linear]" step="0.01" class="adm-field">
                                        </div>
                                    </div>
                                    <h6 class="text-xs font-semibold text-center" style="color:var(--adm-muted);">Startup Linear Measurements</h6>
                                </div>
                                <div class="mb-2">
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-3">
                                        <div>
                                            <label class="adm-label" id="startup_label_width_sqm_${itemCount}">Width (SqM)</label>
                                            <input type="number" name="items[${itemCount}][startup_width_sqm]" step="0.01" class="adm-field">
                                        </div>
                                        <div>
                                            <label class="adm-label" id="startup_label_height_sqm_${itemCount}">Height (SqM)</label>
                                            <input type="number" name="items[${itemCount}][startup_height_sqm]" step="0.01" class="adm-field">
                                        </div>
                                        <div>
                                            <label class="adm-label" id="startup_label_length_sqm_${itemCount}">Length (SqM)</label>
                                            <input type="number" name="items[${itemCount}][startup_length_sqm]" step="0.01" class="adm-field">
                                        </div>
                                    </div>
                                    <h6 class="text-xs font-semibold text-center" style="color:var(--adm-muted);">Startup Square Meter Measurements</h6>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Standard Colors Section -->
                <div class="standard-color-section p-6 mb-2">
                    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                        <h3 class="text-sm font-bold flex items-center gap-2" style="color:var(--adm-ink);">
                            <i class="fas fa-palette" style="color:var(--adm-soft);"></i>
                            Standard Colors
                        </h3>
                        <button type="button" onclick="addStandardColor(${itemCount})" class="adm-btn-accent">
                            <i class="fas fa-plus"></i>
                            Add Color
                        </button>
                    </div>

                    <div id="standardColorsContainer_${itemCount}" class="space-y-4">
                        <p class="text-center py-8 text-sm" style="color:var(--adm-muted);">No standard colors added yet. Click "Add Color" to get started.</p>
                    </div>
                </div>
            `;

            container.appendChild(itemDiv);
            itemCount++;
        }

        function removeItem(index) {
            const itemDiv = document.getElementById('item_' + index);
            if (itemDiv) {
                itemDiv.remove();
                delete standardColorCounts[index];
                
                // Check if there are any remaining items
                const container = document.getElementById('itemsContainer');
                const remainingItems = container.querySelectorAll('.item-card');
                
                if (remainingItems.length === 0) {
                    // Show "no items" message and hide buttons
                    container.innerHTML = '<p style="color:var(--adm-muted);" class="text-center py-16 text-base">No items added yet. Click "Add Item" to get started.</p>';
                    document.getElementById('submitSection').classList.add('hidden');
                    document.getElementById('addItemSection').classList.add('hidden');
                    document.getElementById('initialAddButton').classList.remove('hidden');
                }
            }
        }

        function addStandardColor(itemIndex) {
            const container = document.getElementById('standardColorsContainer_' + itemIndex);
            const colorIndex = standardColorCounts[itemIndex];
            
            // Remove "no colors" message if it exists
            const noColorsMsg = container.querySelector('p');
            if (noColorsMsg) {
                noColorsMsg.remove();
            }

            const colorDiv = document.createElement('div');
            colorDiv.className = 'adm-inner-panel-alt';
            colorDiv.id = `standardColor_${itemIndex}_${colorIndex}`;
            colorDiv.innerHTML = `
                <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                    <h4 class="text-sm font-semibold flex items-center gap-3" style="color:var(--adm-ink);">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center" style="background:var(--adm-ink); color:#fff;">
                            <i class="fas fa-paintbrush text-xs"></i>
                        </div>
                        Color ${colorIndex + 1}
                    </h4>
                    <button type="button" onclick="removeStandardColor(${itemIndex}, ${colorIndex})" class="adm-btn-danger" style="padding:.4rem .8rem;">
                        <i class="fas fa-trash-can"></i>
                        Remove
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                    <!-- Color Name -->
                    <div>
                        <label class="adm-label">Color Name *</label>
                        <input type="text" name="standard_colors[${itemIndex}][${colorIndex}]" required
                            class="adm-field" placeholder="Enter color name">
                    </div>

                    <!-- Color Image Upload -->
                    <div>
                        <label class="adm-label">Color Image</label>
                        <div class="flex items-center gap-4">
                            <!-- Camera Upload Button -->
                            <div class="camera-upload p-4">
                                <input type="file" name="standard_color_images[${itemIndex}][${colorIndex}]" accept="image/*" 
                                       id="standard_color_image_${itemIndex}_${colorIndex}"
                                       onchange="previewImage(this, 'standard_color_preview_${itemIndex}_${colorIndex}')">
                                <div class="text-center">
                                    <div class="camera-icon text-2xl mb-2">
                                        <i class="fas fa-camera"></i>
                                    </div>
                                    <p class="text-xs font-semibold" style="color:var(--adm-ink);">Upload</p>
                                </div>
                            </div>

                            <!-- Image Preview -->
                            <div id="standard_color_preview_${itemIndex}_${colorIndex}" class="image-preview hidden">
                                <div class="relative">
                                    <div class="w-20 h-20 rounded-lg overflow-hidden" style="border:1px solid var(--adm-line);">
                                        <img src="" alt="Color Preview" class="w-full h-full object-cover">
                                    </div>
                                    <button type="button" onclick="removeImage('standard_color_image_${itemIndex}_${colorIndex}', 'standard_color_preview_${itemIndex}_${colorIndex}')"
                                        class="absolute -top-1 -right-1 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs transition-colors" style="background:#DC2626;">
                                        <i class="fas fa-xmark"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            container.appendChild(colorDiv);
            standardColorCounts[itemIndex]++;
        }

        function removeStandardColor(itemIndex, colorIndex) {
            const colorDiv = document.getElementById(`standardColor_${itemIndex}_${colorIndex}`);
            if (colorDiv) {
                colorDiv.remove();
                
                // Check if there are any remaining colors for this item
                const container = document.getElementById('standardColorsContainer_' + itemIndex);
                const remainingColors = container.querySelectorAll('.adm-inner-panel-alt');
                
                if (remainingColors.length === 0) {
                    // Show "no colors" message
                    container.innerHTML = '<p class="text-center py-8 text-sm" style="color:var(--adm-muted);">No standard colors added yet. Click "Add Color" to get started.</p>';
                }
            }
        }

        function updateDimensionLabels(itemIndex, dimensionId) {
            if (!dimensionId || !dimensionLabelsData[dimensionId]) {
                // Reset to defaults
                document.getElementById(`label_width_linear_${itemIndex}`).textContent = 'Width (Linear)';
                document.getElementById(`label_height_linear_${itemIndex}`).textContent = 'Height (Linear)';
                document.getElementById(`label_length_linear_${itemIndex}`).textContent = 'Length (Linear)';
                document.getElementById(`label_width_sqm_${itemIndex}`).textContent = 'Width (SqM)';
                document.getElementById(`label_height_sqm_${itemIndex}`).textContent = 'Height (SqM)';
                document.getElementById(`label_length_sqm_${itemIndex}`).textContent = 'Length (SqM)';
                document.getElementById(`startup_label_width_linear_${itemIndex}`).textContent = 'Width (Linear)';
                document.getElementById(`startup_label_height_linear_${itemIndex}`).textContent = 'Height (Linear)';
                document.getElementById(`startup_label_length_linear_${itemIndex}`).textContent = 'Length (Linear)';
                document.getElementById(`startup_label_width_sqm_${itemIndex}`).textContent = 'Width (SqM)';
                document.getElementById(`startup_label_height_sqm_${itemIndex}`).textContent = 'Height (SqM)';
                document.getElementById(`startup_label_length_sqm_${itemIndex}`).textContent = 'Length (SqM)';
                return;
            }

            const data = dimensionLabelsData[dimensionId];

            // Update Item Dimension labels
            document.getElementById(`label_width_linear_${itemIndex}`).textContent  = (data.item_width_label_linear  || 'Width')  + ' (Linear)';
            document.getElementById(`label_height_linear_${itemIndex}`).textContent = (data.item_height_label_linear || 'Height') + ' (Linear)';
            document.getElementById(`label_length_linear_${itemIndex}`).textContent = (data.item_length_label_linear || 'Length') + ' (Linear)';
            document.getElementById(`label_width_sqm_${itemIndex}`).textContent     = (data.item_width_label_sqm    || 'Width')  + ' (SqM)';
            document.getElementById(`label_height_sqm_${itemIndex}`).textContent    = (data.item_height_label_sqm   || 'Height') + ' (SqM)';
            document.getElementById(`label_length_sqm_${itemIndex}`).textContent    = (data.item_length_label_sqm   || 'Length') + ' (SqM)';

            // Update Startup Dimension labels
            document.getElementById(`startup_label_width_linear_${itemIndex}`).textContent  = (data.item_width_label_linear  || 'Width')  + ' (Linear)';
            document.getElementById(`startup_label_height_linear_${itemIndex}`).textContent = (data.item_height_label_linear || 'Height') + ' (Linear)';
            document.getElementById(`startup_label_length_linear_${itemIndex}`).textContent = (data.item_length_label_linear || 'Length') + ' (Linear)';
            document.getElementById(`startup_label_width_sqm_${itemIndex}`).textContent     = (data.item_width_label_sqm    || 'Width')  + ' (SqM)';
            document.getElementById(`startup_label_height_sqm_${itemIndex}`).textContent    = (data.item_height_label_sqm   || 'Height') + ' (SqM)';
            document.getElementById(`startup_label_length_sqm_${itemIndex}`).textContent    = (data.item_length_label_sqm   || 'Length') + ' (SqM)';
        }

        function toggleFixedModular(itemIndex) {
    const checkbox = document.getElementById(`fixed_modular_${itemIndex}`);
    const isChecked = checkbox.checked;
    
    // Get all pricing fields
    const pricingFields = [
        `non_project_price_${itemIndex}`,
        `project_price_${itemIndex}`,
        `jackup_${itemIndex}`,
        `mark_up_${itemIndex}`,
        `labor_cost_${itemIndex}`
    ];
    
    // Get dimension section
    const dimensionSection = document.getElementById(`dimension_section_${itemIndex}`);
    
    if (isChecked) {
        // Disable and clear pricing fields
        pricingFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.value = '';
                field.disabled = true;
            }
        });
        
        // Disable all inputs in dimension section
        if (dimensionSection) {
            const dimensionInputs = dimensionSection.querySelectorAll('input, select');
            dimensionInputs.forEach(input => {
                input.value = '';
                input.disabled = true;
            });
            dimensionSection.style.opacity = '0.5';
        }
    } else {
        // Enable pricing fields
        pricingFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.disabled = false;
            }
        });
        
        // Enable all inputs in dimension section
        if (dimensionSection) {
            const dimensionInputs = dimensionSection.querySelectorAll('input, select');
            dimensionInputs.forEach(input => {
                input.disabled = false;
            });
            dimensionSection.style.opacity = '1';
        }
    }
}

        // Form validation before submit
        document.querySelector('form').addEventListener('submit', function(e) {
            const items = document.querySelectorAll('.item-card');
            if (items.length === 0) {
                e.preventDefault();
                alert('Please add at least one item before submitting.');
                return false;
            }

            // Additional validation can be added here
            return true;
        });

        // Auto-hide success/error messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.adm-alert-box');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.5s ease-out';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 500);
                }, 5000);
            });
        });
    </script>