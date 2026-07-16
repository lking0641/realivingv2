<?php
//home_settings_inquire_view.php
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
    
    if ($action === 'add_inquire') {
        $title = trim($_POST['title']);
        $target_dir = "../../realiving_user/images/inquire_section/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                $error_message = "Only image files (JPG, PNG, GIF, WebP) are allowed.";
            } else {
                $file_name = uniqid() . '_' . time() . '.webp';
                $target_file = $target_dir . $file_name;
                $filepath = './images/inquire_section/' . $file_name;
                
                // Convert image to WebP
                $temp_file = $_FILES['image']['tmp_name'];
                $image = null;
                
                // Create image resource based on file type
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
                    // Convert to WebP with quality 90
                    if (imagewebp($image, $target_file, 90)) {
                        imagedestroy($image);
                        
                        $stmt = $conn->prepare("INSERT INTO inquire_images (title, filepath, is_active) VALUES (?, ?, 0)");
                        $stmt->bind_param("ss", $title, $filepath);
                        
                        if ($stmt->execute()) {
                            $success_message = "Inquire image added and converted to WebP successfully!";
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
        } else {
            $error_message = "Please select an image.";
        }
    }
    
    if ($action === 'set_active') {
        $id = intval($_POST['id']);
        
        // Deactivate all first
        $conn->query("UPDATE inquire_images SET is_active = 0");
        
        // Activate the selected one
        $stmt = $conn->prepare("UPDATE inquire_images SET is_active = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $success_message = "Image set as active successfully!";
        } else {
            $error_message = "Failed to update status.";
        }
        $stmt->close();
    }
    
    if ($action === 'deactivate') {
        $id = intval($_POST['id']);
        
        $stmt = $conn->prepare("UPDATE inquire_images SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $success_message = "Image deactivated successfully!";
        } else {
            $error_message = "Failed to deactivate.";
        }
        $stmt->close();
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id']);
        $filepath = $_POST['filepath'];
        
        $stmt = $conn->prepare("DELETE FROM inquire_images WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $file_to_delete = "../../" . ltrim($filepath, '../');
            if (file_exists($file_to_delete)) {
                unlink($file_to_delete);
            }
            $success_message = "Image deleted successfully!";
        } else {
            $error_message = "Failed to delete image.";
        }
        $stmt->close();
    }
}

$inquire_items = $conn->query("SELECT * FROM inquire_images ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Inquire Image Viewer</title>
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
        }
    </style>
</head>
<div class="max-w-7xl mx-auto">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <a href="home_settings_dashboard.php" class="text-primary hover:text-secondary flex items-center space-x-2 mb-4">
                    <i class="ri-arrow-left-line"></i>
                    <span>Back to Dashboard</span>
                </a>
                <h1 class="text-3xl font-bold text-gray-800">Inquire Images</h1>
                <p class="text-gray-500">Only 1 image can be active at a time</p>
            </div>
            <button onclick="openModal('inquireModal')" class="bg-primary hover:bg-secondary text-white px-6 py-3 rounded-lg flex items-center space-x-2 transition-colors shadow-sm">
                <i class="ri-upload-line text-xl"></i>
                <span class="font-medium">Upload New Image</span>
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
            <?php while ($item = $inquire_items->fetch_assoc()): 
                $filename = basename($item['filepath']);
                $display_path = "../../realiving_user/images/inquire_section/" . $filename;
            ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <img src="<?php echo htmlspecialchars($display_path); ?>" alt="Inquire" class="w-full h-48 object-cover" />
                    <div class="p-4">
                        <h3 class="font-semibold text-gray-800 mb-2"><?php echo htmlspecialchars($item['title']); ?></h3>
                        <p class="text-xs text-gray-500 mb-4 break-all"><?php echo htmlspecialchars($item['filepath']); ?></p>
                        <div class="flex items-center justify-between">
                            <?php if ($item['is_active']): ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="deactivate" />
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                                    <button type="submit" class="bg-green-100 text-green-700 px-4 py-2 rounded-lg text-sm font-medium hover:opacity-80 transition-opacity">
                                        Active
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="set_active" />
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                                    <button type="submit" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-primary hover:text-white transition-all">
                                        Set Active
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" onsubmit="return confirm('Delete this image?');" class="inline">
                                <input type="hidden" name="action" value="delete" />
                                <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                                <input type="hidden" name="filepath" value="<?php echo $item['filepath']; ?>" />
                                <button type="submit" class="text-red-600 hover:text-red-700 p-2">
                                    <i class="ri-delete-bin-line text-xl"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <!-- Upload Modal -->
    <div id="inquireModal" class="modal">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-semibold text-gray-800">Upload Inquire Image</h3>
                <button onclick="closeModal('inquireModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_inquire" />
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Title</label>
                        <input type="text" name="title" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="Enter image title" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Image (Any format - will be converted to WebP)</label>
                        <input type="file" id="inquireImageInput" name="image" accept="image/*" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" onchange="previewImage(event, 'inquirePreview')" />
                        <p class="text-xs text-gray-500 mt-1">Supports: JPG, PNG, GIF, WebP</p>
                    </div>
                    <div id="inquirePreview" class="hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Preview</label>
                        <div class="relative rounded-lg overflow-hidden border-2 border-gray-200">
                            <img id="inquirePreviewImage" src="" alt="Preview" class="w-full h-48 object-cover" />
                            <button type="button" onclick="clearPreview('inquireImageInput', 'inquirePreview')" class="absolute top-2 right-2 bg-red-500 hover:bg-red-600 text-white rounded-full p-2 shadow-lg transition-colors">
                                <i class="ri-close-line text-lg"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-primary hover:bg-secondary text-white py-3 rounded-lg font-medium transition-colors">
                        <i class="ri-upload-line mr-2"></i>Upload Image
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
            // Reset form and preview when closing
            const form = document.querySelector('#' + modalId + ' form');
            if (form) form.reset();
            clearPreview('inquireImageInput', 'inquirePreview');
        }

        function previewImage(event, previewId) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
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

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                closeModal(event.target.id);
            }
        }
    </script>
</html>

<?php $conn->close(); ?>