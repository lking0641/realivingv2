<?php
// manage_building_types.php
session_start();
include '../../connection/connection.php';
include '../design/mainbody.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

$success_message = "";
$error_message = "";

// Function to convert image to WebP
function convertToWebP($source, $destination, $quality = 90) {
    $info = getimagesize($source);
    $image = null;
    
    switch ($info['mime']) {
        case 'image/jpeg': $image = imagecreatefromjpeg($source); break;
        case 'image/png': $image = imagecreatefrompng($source); break;
        case 'image/gif': $image = imagecreatefromgif($source); break;
        case 'image/webp': $image = imagecreatefromwebp($source); break;
    }
    
    if ($image !== false && $image !== null) {
        $result = imagewebp($image, $destination, $quality);
        imagedestroy($image);
        return $result;
    }
    return false;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
    $name = trim($_POST['name']);
    $slug = strtolower(str_replace(' ', '-', $name));
    $description = trim($_POST['description']);
    $icon = $_POST['icon'];
    $display_order = intval($_POST['display_order']);
    
    $background_image = null;
    
    // Handle background image upload
    if (isset($_FILES['background_image']) && $_FILES['background_image']['error'] === 0) {
        $target_dir = "../../realiving_user/images/building_types/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_name = $slug . '_' . time() . '.webp';
        $target_file = $target_dir . $file_name;
        
        if (convertToWebP($_FILES['background_image']['tmp_name'], $target_file)) {
            $background_image = './images/building_types/' . $file_name;
        }
    }
    
    $stmt = $conn->prepare("INSERT INTO building_types (name, slug, description, icon, background_image, display_order) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssi", $name, $slug, $description, $icon, $background_image, $display_order);
        
        if ($stmt->execute()) {
            $success_message = "Building type added successfully!";
        } else {
            $error_message = "Error: " . $conn->error;
        }
        $stmt->close();
    }
    
    if ($action === 'edit') {
    $id = intval($_POST['id']);
    $name = trim($_POST['name']);
    $slug = strtolower(str_replace(' ', '-', $name));
    $description = trim($_POST['description']);
    $icon = $_POST['icon'];
    $display_order = intval($_POST['display_order']);
    
    // Check if new background image uploaded
    $background_image = $_POST['existing_background_image'] ?? null;
    
    if (isset($_FILES['background_image']) && $_FILES['background_image']['error'] === 0) {
        $target_dir = "../../realiving_user/images/building_types/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        // Delete old image if exists
        if (!empty($background_image)) {
            $old_file = "../../realiving_user/" . $background_image;
            if (file_exists($old_file)) {
                unlink($old_file);
            }
        }
        
        $file_name = $slug . '_' . time() . '.webp';
        $target_file = $target_dir . $file_name;
        
        if (convertToWebP($_FILES['background_image']['tmp_name'], $target_file)) {
            $background_image = './images/building_types/' . $file_name;
        }
    }
    
    $stmt = $conn->prepare("UPDATE building_types SET name=?, slug=?, description=?, icon=?, background_image=?, display_order=? WHERE id=?");
    $stmt->bind_param("sssssii", $name, $slug, $description, $icon, $background_image, $display_order, $id);
        
        if ($stmt->execute()) {
            $success_message = "Building type updated successfully!";
        } else {
            $error_message = "Error: " . $conn->error;
        }
        $stmt->close();
    }
    
    if ($action === 'delete') {
    $id = intval($_POST['id']);
    
    // Get background image path first
    $img_stmt = $conn->prepare("SELECT background_image FROM building_types WHERE id = ?");
    $img_stmt->bind_param("i", $id);
    $img_stmt->execute();
    $img_result = $img_stmt->get_result()->fetch_assoc();
    $img_stmt->close();
    
    $stmt = $conn->prepare("DELETE FROM building_types WHERE id=?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        // Delete image file if exists
        if (!empty($img_result['background_image'])) {
            $file_path = "../../realiving_user/" . $img_result['background_image'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        $success_message = "Building type deleted successfully!";
    }
        
        if ($stmt->execute()) {
            $success_message = "Building type deleted successfully!";
        } else {
            $error_message = "Error: " . $conn->error;
        }
        $stmt->close();
    }
}

// Fetch all building types
$building_types = $conn->query("SELECT * FROM building_types ORDER BY display_order ASC, name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Manage Building Types</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />
    <style>
        body { font-family: 'Inter', sans-serif; }
        .modal {
            display: none;
            position: fixed;
            z-index: 50;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <a href="gallery_dashboard_v2.php" class="text-indigo-600 hover:text-indigo-800 flex items-center space-x-2 mb-4">
                    <i class="ri-arrow-left-line"></i>
                    <span>Back to Dashboard</span>
                </a>
                <h1 class="text-3xl font-bold text-gray-800">Building Types Management</h1>
                <p class="text-gray-500">Hospitality, Commercial, Residential, Institutional</p>
            </div>
            <button onclick="openAddModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg flex items-center space-x-2 transition-colors shadow-sm">
                <i class="ri-add-line text-xl"></i>
                <span class="font-medium">Add Building Type</span>
            </button>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="mb-6 p-4 bg-green-50 border-l-4 border-green-500 rounded-lg">
                <p class="text-green-700"><?php echo htmlspecialchars($success_message); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 rounded-lg">
                <p class="text-red-700"><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        <?php endif; ?>

        <!-- Building Types Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php while ($type = $building_types->fetch_assoc()): ?>
                <div class="bg-white rounded-xl shadow-sm border-2 border-gray-200 p-6 hover:shadow-lg transition-all">
                    <div class="flex items-start justify-between mb-4">
                        <div class="p-3 bg-indigo-50 rounded-xl">
                            <i class="<?php echo htmlspecialchars($type['icon']); ?> text-3xl text-indigo-600"></i>
                        </div>
                        <div class="flex space-x-2">
                            <button onclick='editBuildingType(<?php echo json_encode($type); ?>)' class="text-blue-600 hover:text-blue-700 p-2">
                                <i class="ri-edit-line text-lg"></i>
                            </button>
                            <form method="POST" onsubmit="return confirm('Delete this building type? All connections will be lost!');" class="inline">
                                <input type="hidden" name="action" value="delete" />
                                <input type="hidden" name="id" value="<?php echo $type['id']; ?>" />
                                <button type="submit" class="text-red-600 hover:text-red-700 p-2">
                                    <i class="ri-delete-bin-line text-lg"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-800 mb-2"><?php echo htmlspecialchars($type['name']); ?></h3>
                    <p class="text-sm text-gray-500 mb-3"><?php echo htmlspecialchars($type['description']); ?></p>
                    <div class="pt-3 border-t border-gray-100">
                        <span class="text-xs text-gray-500">Display Order: <?php echo $type['display_order']; ?></span>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <?php if ($building_types->num_rows === 0): ?>
            <div class="text-center py-12">
                <i class="ri-building-line text-6xl text-gray-300 mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">No building types yet</h3>
                <p class="text-gray-500 mb-4">Add your first building type to get started</p>
                <button onclick="openAddModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg">
                    <i class="ri-add-line mr-2"></i>Add Building Type
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add/Edit Modal -->
    <div id="buildingModal" class="modal">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 id="modalTitle" class="text-2xl font-semibold text-gray-800">Add Building Type</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <form id="buildingForm" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" id="formAction" value="add" />
    <input type="hidden" name="id" id="formId" value="" />
    <input type="hidden" name="existing_background_image" id="formExistingImage" value="" />
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Building Type Name *</label>
                        <input type="text" name="name" id="formName" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500" placeholder="e.g., Hospitality" />
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="description" id="formDescription" rows="3" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500" placeholder="Brief description..."></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Icon (RemixIcon class) *</label>
                        <select name="icon" id="formIcon" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                            <option value="ri-hotel-line">🏨 Hotel (ri-hotel-line)</option>
                            <option value="ri-store-2-line">🏢 Store (ri-store-2-line)</option>
                            <option value="ri-home-4-line">🏠 Home (ri-home-4-line)</option>
                            <option value="ri-government-line">🏛️ Government (ri-government-line)</option>
                            <option value="ri-building-line">🏗️ Building (ri-building-line)</option>
                            <option value="ri-community-line">🏘️ Community (ri-community-line)</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Display Order *</label>
                        <input type="number" name="display_order" id="formOrder" required value="0" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500" />
                        <p class="text-xs text-gray-500 mt-1">Lower numbers appear first</p>
                    </div>

                    <div>
    <label class="block text-sm font-medium text-gray-700 mb-2">Background Image</label>
    <input type="file" name="background_image" id="formBackgroundImage" accept="image/*" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500" onchange="previewBackgroundImage(this)" />
    <p class="text-xs text-gray-500 mt-1">This image will be used as background when viewing this building type (Auto-converts to WebP)</p>
    <div id="backgroundPreview" class="mt-3 hidden">
        <img id="backgroundPreviewImg" src="" alt="Preview" class="max-h-40 rounded-lg border" />
    </div>
</div>
                    
                    <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-3 rounded-lg font-medium transition-colors">
                        <i class="ri-save-line mr-2"></i><span id="submitBtnText">Add Building Type</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Building Type';
            document.getElementById('formAction').value = 'add';
            document.getElementById('submitBtnText').textContent = 'Add Building Type';
            document.getElementById('buildingForm').reset();
            document.getElementById('buildingModal').classList.add('active');
        }

        function editBuildingType(type) {
    document.getElementById('modalTitle').textContent = 'Edit Building Type';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = type.id;
    document.getElementById('formName').value = type.name;
    document.getElementById('formDescription').value = type.description;
    document.getElementById('formIcon').value = type.icon;
    document.getElementById('formOrder').value = type.display_order;
    document.getElementById('formExistingImage').value = type.background_image || '';
    document.getElementById('submitBtnText').textContent = 'Update Building Type';
    
    // Show existing background image if available
    if (type.background_image) {
        const preview = document.getElementById('backgroundPreview');
        const img = document.getElementById('backgroundPreviewImg');
        img.src = '../../realiving_user/' + type.background_image;
        preview.classList.remove('hidden');
    }
    
    document.getElementById('buildingModal').classList.add('active');
}

function previewBackgroundImage(input) {
    const file = input.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('backgroundPreviewImg').src = e.target.result;
            document.getElementById('backgroundPreview').classList.remove('hidden');
        }
        reader.readAsDataURL(file);
    }
}

        function closeModal() {
            document.getElementById('buildingModal').classList.remove('active');
        }

        window.onclick = function(event) {
            if (event.target.id === 'buildingModal') {
                closeModal();
            }
        }
    </script>
</html>

<?php $conn->close(); ?>