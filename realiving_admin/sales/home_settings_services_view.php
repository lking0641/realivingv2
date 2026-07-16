<?php
//home_settings_services_view.php
session_start();
include '../../connection/connection.php';
include '../design/mainbody.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

$success_message = "";
$error_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_service') {
    $service_number = intval($_POST['service_number']);
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $detailed_description = trim($_POST['detailed_description']);
    $display_order = intval($_POST['display_order']);
    $target_dir = "../../realiving_user/images/services/";
    
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
        $file_extension = strtolower(pathinfo($_FILES['media']['name'], PATHINFO_EXTENSION));
        $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $video_extensions = ['mp4', 'webm', 'mov', 'avi'];
        $allowed_extensions = array_merge($image_extensions, $video_extensions);
        
        if (!in_array($file_extension, $allowed_extensions)) {
            $error_message = "Only image files (JPG, PNG, GIF, WebP) or video files (MP4, WebM, MOV, AVI) are allowed.";
        } else {
            $is_video = in_array($file_extension, $video_extensions);
            $media_type = $is_video ? 'video' : 'image';
            
            if ($is_video) {
                // Handle video upload
                $file_name = uniqid() . '_' . time() . '.' . $file_extension;
                $target_file = $target_dir . $file_name;
                $filepath = './images/services/' . $file_name;
                
                if (move_uploaded_file($_FILES['media']['tmp_name'], $target_file)) {
                    $stmt = $conn->prepare("INSERT INTO services_section (service_number, title, description, detailed_description, image_path, media_type, is_active, display_order) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
                    $stmt->bind_param("isssssi", $service_number, $title, $description, $detailed_description, $filepath, $media_type, $display_order);
                    
                    if ($stmt->execute()) {
                        $success_message = "Service added successfully!";
                    } else {
                        $error_message = "Database error: " . $conn->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = "Failed to upload video.";
                }
            } else {
                // Handle image upload (convert to WebP)
                $file_name = uniqid() . '_' . time() . '.webp';
                $target_file = $target_dir . $file_name;
                $filepath = './images/services/' . $file_name;
                
                $temp_file = $_FILES['media']['tmp_name'];
                $image = null;
                
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
                    if (imagewebp($image, $target_file, 90)) {
                        imagedestroy($image);
                        
                        $stmt = $conn->prepare("INSERT INTO services_section (service_number, title, description, detailed_description, image_path, media_type, is_active, display_order) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
                        $stmt->bind_param("isssssi", $service_number, $title, $description, $detailed_description, $filepath, $media_type, $display_order);
                        
                        if ($stmt->execute()) {
                            $success_message = "Service added successfully!";
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
        }
    } else {
        $error_message = "Please select a media file.";
    }
}
    
    if ($action === 'edit_service') {
        $id = intval($_POST['id']);
        $service_number = intval($_POST['service_number']);
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $detailed_description = trim($_POST['detailed_description']);
        $display_order = intval($_POST['display_order']);
        
        // Check if new media uploaded
if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
    $target_dir = "../../realiving_user/images/services/";
    $file_extension = strtolower(pathinfo($_FILES['media']['name'], PATHINFO_EXTENSION));
    $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $video_extensions = ['mp4', 'webm', 'mov', 'avi'];
    $allowed_extensions = array_merge($image_extensions, $video_extensions);
    
    if (!in_array($file_extension, $allowed_extensions)) {
        $error_message = "Only image or video files allowed.";
    } else {
        $is_video = in_array($file_extension, $video_extensions);
        $media_type = $is_video ? 'video' : 'image';
        
        // Get old file path to delete
        $old_result = $conn->query("SELECT image_path FROM services_section WHERE id = $id");
        $old_row = $old_result->fetch_assoc();
        $old_filepath = $old_row['image_path'];
        
        if ($is_video) {
            // Handle video upload
            $file_name = uniqid() . '_' . time() . '.' . $file_extension;
            $target_file = $target_dir . $file_name;
            $filepath = './images/services/' . $file_name;
            
            if (move_uploaded_file($_FILES['media']['tmp_name'], $target_file)) {
    // Delete old file
    $old_filename = basename($old_filepath);
    $file_to_delete = "../../realiving_user/images/services/" . $old_filename;
    if (file_exists($file_to_delete)) {
        unlink($file_to_delete);
    }
                
                $stmt = $conn->prepare("UPDATE services_section SET service_number = ?, title = ?, description = ?, detailed_description = ?, image_path = ?, media_type = ?, display_order = ? WHERE id = ?");
                $stmt->bind_param("isssssii", $service_number, $title, $description, $detailed_description, $filepath, $media_type, $display_order, $id);
                
                if ($stmt->execute()) {
                    $success_message = "Service updated successfully!";
                } else {
                    $error_message = "Database error: " . $conn->error;
                }
                $stmt->close();
            } else {
                $error_message = "Failed to upload video.";
            }
        } else {
            // Handle image upload (convert to WebP)
            $file_name = uniqid() . '_' . time() . '.webp';
            $target_file = $target_dir . $file_name;
            $filepath = './images/services/' . $file_name;
            
            $temp_file = $_FILES['media']['tmp_name'];
            $image = null;
            
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
                if (imagewebp($image, $target_file, 90)) {
    imagedestroy($image);
    
    // Delete old file
    $old_filename = basename($old_filepath);
    $file_to_delete = "../../realiving_user/images/services/" . $old_filename;
    if (file_exists($file_to_delete)) {
        unlink($file_to_delete);
    }
                    
                    $stmt = $conn->prepare("UPDATE services_section SET service_number = ?, title = ?, description = ?, detailed_description = ?, image_path = ?, media_type = ?, display_order = ? WHERE id = ?");
                    $stmt->bind_param("isssssii", $service_number, $title, $description, $detailed_description, $filepath, $media_type, $display_order, $id);
                    
                    if ($stmt->execute()) {
                        $success_message = "Service updated successfully!";
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
    }
} else {
    // Update without changing media
    $stmt = $conn->prepare("UPDATE services_section SET service_number = ?, title = ?, description = ?, detailed_description = ?, display_order = ? WHERE id = ?");
    $stmt->bind_param("isssii", $service_number, $title, $description, $detailed_description, $display_order, $id);
    
    if ($stmt->execute()) {
        $success_message = "Service updated successfully!";
    } else {
        $error_message = "Failed to update service.";
    }
    $stmt->close();
}
    }
    
    if ($action === 'toggle_status') {
        $id = intval($_POST['id']);
        $current_status = intval($_POST['current_status']);
        $new_status = $current_status === 1 ? 0 : 1;
        
        $stmt = $conn->prepare("UPDATE services_section SET is_active = ? WHERE id = ?");
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
        
        $stmt = $conn->prepare("DELETE FROM services_section WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
    $filename = basename($filepath);
    $file_to_delete = "../../realiving_user/images/services/" . $filename;
    if (file_exists($file_to_delete)) {
        unlink($file_to_delete);
    }
    $success_message = "Service deleted successfully!";
} else {
            $error_message = "Failed to delete service.";
        }
        $stmt->close();
    }
}

$services = $conn->query("SELECT * FROM services_section ORDER BY display_order ASC, service_number ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Services Section Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: "#4f46e5",
                        secondary: "#4338ca",
                    },
                }
            },
        };
    </script>
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
            animation: fadeIn 0.3s ease;
        }
        
        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
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
            max-height: 90vh;
            overflow-y: auto;
        }
    </style>
</head>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <a href="home_settings_dashboard.php" class="text-primary hover:text-secondary flex items-center space-x-2 mb-4">
                    <i class="ri-arrow-left-line"></i>
                    <span>Back to Dashboard</span>
                </a>
                <h1 class="text-3xl font-bold text-gray-800">Services Section</h1>
                <p class="text-gray-500">Manage service cards displayed on homepage</p>
            </div>
            <button onclick="openAddModal()" class="bg-primary hover:bg-secondary text-white px-6 py-3 rounded-lg flex items-center space-x-2 transition-colors shadow-sm">
                <i class="ri-add-line text-xl"></i>
                <span class="font-medium">Add New Service</span>
            </button>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="mb-6 p-4 bg-green-50 border-l-4 border-green-500 rounded-lg">
                <div class="flex">
                    <i class="ri-checkbox-circle-line text-green-500 text-xl"></i>
                    <p class="ml-3 text-sm text-green-700 font-medium"><?php echo htmlspecialchars($success_message); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 rounded-lg">
                <div class="flex">
                    <i class="ri-error-warning-line text-red-500 text-xl"></i>
                    <p class="ml-3 text-sm text-red-700 font-medium"><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php while ($service = $services->fetch_assoc()): 
                $filename = basename($service['image_path']);
                $display_path = "../../realiving_user/images/services/" . $filename;
            ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="relative">
    <?php if (isset($service['media_type']) && $service['media_type'] === 'video'): ?>
        <video src="<?php echo htmlspecialchars($display_path); ?>" class="w-full h-48 object-cover" controls></video>
    <?php else: ?>
        <img src="<?php echo htmlspecialchars($display_path); ?>" alt="Service" class="w-full h-48 object-cover" />
    <?php endif; ?>
                        <div class="absolute top-2 left-2 bg-primary text-white rounded-full w-10 h-10 flex items-center justify-center font-bold text-lg">
                            <?php echo $service['service_number']; ?>
                        </div>
                    </div>
                    <div class="p-4">
                        <h3 class="font-semibold text-gray-800 mb-2 text-lg"><?php echo htmlspecialchars($service['title']); ?></h3>
                        <p class="text-sm text-gray-600 mb-4 line-clamp-3"><?php echo htmlspecialchars($service['description']); ?></p>
                        <p class="text-xs text-gray-400 mb-4">Order: <?php echo $service['display_order']; ?></p>
                        <div class="flex items-center justify-between gap-2">
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="toggle_status" />
                                <input type="hidden" name="id" value="<?php echo $service['id']; ?>" />
                                <input type="hidden" name="current_status" value="<?php echo $service['is_active']; ?>" />
                                <button type="submit" class="<?php echo $service['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'; ?> px-3 py-2 rounded-lg text-sm font-medium hover:opacity-80 transition-opacity">
                                    <?php echo $service['is_active'] ? 'Active' : 'Inactive'; ?>
                                </button>
                            </form>
                            <div class="flex gap-2">
                                <button onclick='openEditModal(<?php echo htmlspecialchars(json_encode($service), ENT_QUOTES, "UTF-8"); ?>)' class="text-blue-600 hover:text-blue-700 p-2">
    <i class="ri-edit-line text-xl"></i>
</button>
                                <form method="POST" onsubmit="return confirm('Delete this service?');" class="inline">
                                    <input type="hidden" name="action" value="delete" />
                                    <input type="hidden" name="id" value="<?php echo $service['id']; ?>" />
                                    <input type="hidden" name="filepath" value="<?php echo $service['image_path']; ?>" />
                                    <button type="submit" class="text-red-600 hover:text-red-700 p-2">
                                        <i class="ri-delete-bin-line text-xl"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <!-- Add Service Modal -->
    <div id="addServiceModal" class="modal">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-semibold text-gray-800">Add New Service</h3>
                <button onclick="closeModal('addServiceModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_service" />
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Service Number</label>
                            <input type="number" name="service_number" required min="1" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="1" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Display Order</label>
                            <input type="number" name="display_order" required min="0" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="0" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Title</label>
                        <input type="text" name="title" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="Design" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Short Description (for homepage)</label>
                        <textarea name="description" required rows="2" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="Brief description for homepage card..."></textarea>
                        <p class="text-xs text-gray-500 mt-1">This appears on the homepage service cards</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Detailed Description (for services page)</label>
                        <textarea name="detailed_description" required rows="6" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="Full detailed description for services page..."></textarea>
                        <p class="text-xs text-gray-500 mt-1">This appears on the dedicated services page with more details</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Image or Video</label>
<input type="file" id="addServiceMedia" name="media" accept="image/*,video/*" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" onchange="previewImage(event, 'addPreview')" />
                        <p class="text-xs text-gray-500 mt-1">Supports: JPG, PNG, GIF, WebP, MP4, WebM, MOV, AVI</p>
                    </div>
                    <div id="addPreview" class="hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Preview</label>
                        <div class="relative rounded-lg overflow-hidden border-2 border-gray-200">
                            <img id="addPreviewImage" src="" alt="Preview" class="w-full h-48 object-cover hidden" />
<video id="addPreviewVideo" src="" class="w-full h-48 object-cover hidden" controls></video>
                            <button type="button" onclick="clearPreview('addServiceMedia', 'addPreview')" class="absolute top-2 right-2 bg-red-500 hover:bg-red-600 text-white rounded-full p-2 shadow-lg transition-colors">
                                <i class="ri-close-line text-lg"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-primary hover:bg-secondary text-white py-3 rounded-lg font-medium transition-colors">
                        <i class="ri-add-line mr-2"></i>Add Service
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Service Modal -->
    <div id="editServiceModal" class="modal">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-semibold text-gray-800">Edit Service</h3>
                <button onclick="closeModal('editServiceModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="editForm">
                <input type="hidden" name="action" value="edit_service" />
                <input type="hidden" name="id" id="editId" />
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Service Number</label>
                            <input type="number" name="service_number" id="editNumber" required min="1" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Display Order</label>
                            <input type="number" name="display_order" id="editOrder" required min="0" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Title</label>
                        <input type="text" name="title" id="editTitle" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Short Description (for homepage)</label>
                        <textarea name="description" id="editDescription" required rows="2" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary"></textarea>
                        <p class="text-xs text-gray-500 mt-1">This appears on the homepage service cards</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Detailed Description (for services page)</label>
                        <textarea name="detailed_description" id="editDetailedDescription" required rows="6" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary"></textarea>
                        <p class="text-xs text-gray-500 mt-1">This appears on the dedicated services page with more details</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Current Media</label>
<img id="currentImage" src="" alt="Current" class="w-full h-32 object-cover rounded-lg border-2 border-gray-200 mb-2 hidden" />
<video id="currentVideo" src="" class="w-full h-32 object-cover rounded-lg border-2 border-gray-200 mb-2 hidden" controls></video>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">New Image/Video (optional)</label>
<input type="file" id="editServiceMedia" name="media" accept="image/*,video/*" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" onchange="previewImage(event, 'editPreview')" />
                        <p class="text-xs text-gray-500 mt-1">Leave empty to keep current media</p>
                    </div>
                    <div id="editPreview" class="hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-2">New Preview</label>
                        <div class="relative rounded-lg overflow-hidden border-2 border-gray-200">
                            <img id="editPreviewImage" src="" alt="Preview" class="w-full h-48 object-cover hidden" />
<video id="editPreviewVideo" src="" class="w-full h-48 object-cover hidden" controls></video>
                            <button type="button" onclick="clearPreview('editServiceMedia', 'editPreview')" class="absolute top-2 right-2 bg-red-500 hover:bg-red-600 text-white rounded-full p-2 shadow-lg transition-colors">
                                <i class="ri-close-line text-lg"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-primary hover:bg-secondary text-white py-3 rounded-lg font-medium transition-colors">
                        <i class="ri-save-line mr-2"></i>Update Service
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddModal() {
            document.getElementById('addServiceModal').classList.add('active');
        }

        function openEditModal(service) {
    document.getElementById('editId').value = service.id;
    document.getElementById('editNumber').value = service.service_number;
    document.getElementById('editTitle').value = service.title;
    document.getElementById('editDescription').value = service.description;
    document.getElementById('editDetailedDescription').value = service.detailed_description;
    document.getElementById('editOrder').value = service.display_order;
    
    const filename = service.image_path.split('/').pop();
    const mediaPath = '../../realiving_user/images/services/' + filename;
    
    // Show appropriate media type
    const isVideo = service.media_type === 'video';
    if (isVideo) {
        document.getElementById('currentVideo').src = mediaPath;
        document.getElementById('currentVideo').classList.remove('hidden');
        document.getElementById('currentImage').classList.add('hidden');
    } else {
        document.getElementById('currentImage').src = mediaPath;
        document.getElementById('currentImage').classList.remove('hidden');
        document.getElementById('currentVideo').classList.add('hidden');
    }
    
    document.getElementById('editServiceModal').classList.add('active');
}

        function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
    const form = document.querySelector('#' + modalId + ' form');
    if (form) form.reset();
    if (modalId === 'addServiceModal') {
        clearPreview('addServiceMedia', 'addPreview');
    } else {
        clearPreview('editServiceMedia', 'editPreview');
    }
}

        function previewImage(event, previewId) {
    const file = event.target.files[0];
    if (file) {
        const reader = new FileReader();
        const isVideo = file.type.startsWith('video/');
        
        reader.onload = function(e) {
            const previewContainer = document.getElementById(previewId);
            const previewImage = document.getElementById(previewId + 'Image');
            const previewVideo = document.getElementById(previewId + 'Video');
            
            if (isVideo) {
                previewVideo.src = e.target.result;
                previewVideo.classList.remove('hidden');
                previewImage.classList.add('hidden');
            } else {
                previewImage.src = e.target.result;
                previewImage.classList.remove('hidden');
                previewVideo.classList.add('hidden');
            }
            previewContainer.classList.remove('hidden');
        }
        reader.readAsDataURL(file);
    }
}

        function clearPreview(inputId, previewId) {
    document.getElementById(inputId).value = '';
    document.getElementById(previewId).classList.add('hidden');
    document.getElementById(previewId + 'Image').src = '';
    document.getElementById(previewId + 'Image').classList.add('hidden');
    document.getElementById(previewId + 'Video').src = '';
    document.getElementById(previewId + 'Video').classList.add('hidden');
}

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                closeModal(event.target.id);
            }
        }
    </script>
</html>

<?php $conn->close(); ?>