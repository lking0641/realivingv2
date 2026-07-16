<?php
//location_images.php
session_start();
include '../../connection/connection.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

$location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;

// Verify location exists and get project info
$location_stmt = $conn->prepare("
    SELECT pl.*, p.title as project_title, p.id as project_id 
    FROM project_locations pl 
    JOIN project p ON pl.project_id = p.id 
    WHERE pl.id = ?
");
$location_stmt->bind_param("i", $location_id);
$location_stmt->execute();
$location_result = $location_stmt->get_result();

if ($location_result->num_rows === 0) {
    header("Location: projects_view.php");
    exit();
}

$location = $location_result->fetch_assoc();
$location_stmt->close();

$success_message = "";
$error_message = "";

// Function to convert image to WebP
function convertToWebP($source, $destination, $quality = 90) {
    $info = getimagesize($source);
    $image = null;
    
    switch ($info['mime']) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($source);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($source);
            break;
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
    
    if ($action === 'add_image') {
    // Check current image count for this location
    $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM project_location_images WHERE location_id = ?");
    $count_stmt->bind_param("i", $location_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $current_count = $count_result->fetch_assoc()['count'];
    $count_stmt->close();
    
    $target_dir = "../../realiving_user/images/project_locations/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    // Multiple file upload handling
    if (isset($_FILES['location_images']) && !empty($_FILES['location_images']['name'][0])) {
        $total_files = count($_FILES['location_images']['name']);
        $available_slots = 10 - $current_count;
        
        if ($total_files > $available_slots) {
            $error_message = "You can only upload " . $available_slots . " more image(s). You tried to upload " . $total_files . " images.";
        } else {
            $uploaded_count = 0;
            $failed_count = 0;
            $errors = [];
            
            for ($i = 0; $i < $total_files; $i++) {
                if ($_FILES['location_images']['error'][$i] === 0) {
                    $file_name = uniqid() . '_loc_' . $location_id . '_' . time() . '_' . $i . '.webp';
                    $target_file = $target_dir . $file_name;
                    
                    if (convertToWebP($_FILES['location_images']['tmp_name'][$i], $target_file)) {
                        $image_path = './images/project_locations/' . $file_name;
                        
                        $stmt = $conn->prepare("INSERT INTO project_location_images (location_id, image_path) VALUES (?, ?)");
                        $stmt->bind_param("is", $location_id, $image_path);
                        
                        if ($stmt->execute()) {
                            $uploaded_count++;
                        } else {
                            $failed_count++;
                            if (file_exists($target_file)) {
                                unlink($target_file);
                            }
                        }
                        $stmt->close();
                    } else {
                        $failed_count++;
                        $errors[] = "Failed to convert image " . ($i + 1);
                    }
                } else {
                    $failed_count++;
                }
            }
            
            if ($uploaded_count > 0) {
                $success_message = $uploaded_count . " image(s) uploaded successfully and converted to WebP!";
                if ($failed_count > 0) {
                    $success_message .= " (" . $failed_count . " failed)";
                }
            } else {
                $error_message = "All uploads failed. " . implode(", ", $errors);
            }
        }
    } else {
        $error_message = "Please select at least one image to upload.";
    }
}
    
    if ($action === 'delete_image') {
        $image_id = intval($_POST['image_id']);
        
        // Get file path first
        $stmt = $conn->prepare("SELECT image_path FROM project_location_images WHERE id = ? AND location_id = ?");
        $stmt->bind_param("ii", $image_id, $location_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $image = $result->fetch_assoc();
        $stmt->close();
        
        if ($image) {
            // Delete from database
            $stmt = $conn->prepare("DELETE FROM project_location_images WHERE id = ? AND location_id = ?");
            $stmt->bind_param("ii", $image_id, $location_id);
            
            if ($stmt->execute()) {
                // Delete file
                $file_path = "../../realiving_user/" . $image['image_path'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                $success_message = "Image deleted successfully!";
            } else {
                $error_message = "Failed to delete image.";
            }
            $stmt->close();
        }
    }
}

// Fetch all images for this location
$images = $conn->query("SELECT * FROM project_location_images WHERE location_id = $location_id ORDER BY id DESC");
$image_count = $images->num_rows;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($location['location_name']); ?> - Images</title>
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
            overflow-y: auto;
            padding: 20px;
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

        .image-preview {
            position: relative;
            border: 2px dashed #d1d5db;
            border-radius: 0.5rem;
            padding: 1rem;
            text-align: center;
        }

        .image-preview img {
            max-height: 250px;
            margin: 0 auto;
            border-radius: 0.5rem;
        }

        .image-grid-item {
            position: relative;
            overflow: hidden;
            border-radius: 0.75rem;
            aspect-ratio: 4/3;
        }

        .image-grid-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .image-grid-item:hover img {
            transform: scale(1.05);
        }
    </style>
</head>
<body class="bg-gray-50 p-8">
    <div class="max-w-7xl mx-auto">
        <div class="mb-6">
            <a href="project_locations.php?project_id=<?php echo $location['project_id']; ?>" class="text-primary hover:text-secondary flex items-center space-x-2 mb-4">
                <i class="ri-arrow-left-line"></i>
                <span>Back to Locations</span>
            </a>
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div>
                    <div class="flex items-center space-x-3 mb-2">
                        <div class="bg-primary text-white p-3 rounded-lg">
                            <i class="ri-map-pin-fill text-2xl"></i>
                        </div>
                        <div>
                            <h1 class="text-3xl font-bold text-gray-800"><?php echo htmlspecialchars($location['location_name']); ?></h1>
                            <p class="text-gray-500">Project: <?php echo htmlspecialchars($location['project_title']); ?></p>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center space-x-4">
                        <div class="bg-white px-4 py-2 rounded-lg border border-gray-200">
                            <span class="text-sm text-gray-600">Images:</span>
                            <span class="font-bold text-gray-800 ml-1"><?php echo $image_count; ?>/10</span>
                        </div>
                        <div class="flex-1 max-w-xs">
                            <div class="bg-gray-200 rounded-full h-3 overflow-hidden">
                                <div class="bg-primary h-full transition-all" style="width: <?php echo ($image_count / 10) * 100; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <?php if ($image_count < 10): ?>
                        <button onclick="openModal('addImageModal')" class="bg-primary hover:bg-secondary text-white px-6 py-3 rounded-lg flex items-center justify-center space-x-2 transition-colors shadow-sm">
                            <i class="ri-image-add-line text-xl"></i>
                            <span class="font-medium">Add Image</span>
                        </button>
                    <?php else: ?>
                        <div class="bg-amber-50 border border-amber-200 text-amber-700 px-6 py-3 rounded-lg flex items-center space-x-2">
                            <i class="ri-error-warning-line"></i>
                            <span class="font-medium">Maximum limit reached (10/10)</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
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

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <?php while ($image = $images->fetch_assoc()): 
                $img_path = "../../realiving_user/" . $image['image_path'];
            ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
                    <div class="image-grid-item">
                        <img src="<?php echo htmlspecialchars($img_path); ?>" alt="Location Image" />
                        <div class="absolute inset-0 bg-black bg-opacity-0 hover:bg-opacity-50 transition-all flex items-center justify-center">
                            <a href="<?php echo htmlspecialchars($img_path); ?>" target="_blank" class="opacity-0 hover:opacity-100 bg-white text-gray-800 px-3 py-2 rounded-lg text-sm font-medium transform scale-90 hover:scale-100 transition-all">
                                <i class="ri-eye-line mr-1"></i> View Full
                            </a>
                        </div>
                    </div>
                    <div class="p-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-gray-500">
                                <?php echo date('M d, Y', strtotime($image['created_at'])); ?>
                            </span>
                            <form method="POST" onsubmit="return confirm('Delete this image?');" class="inline">
                                <input type="hidden" name="action" value="delete_image" />
                                <input type="hidden" name="image_id" value="<?php echo $image['id']; ?>" />
                                <button type="submit" class="text-red-600 hover:text-red-700 p-1.5 hover:bg-red-50 rounded transition-colors">
                                    <i class="ri-delete-bin-line text-lg"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <?php if ($image_count === 0): ?>
            <div class="text-center py-16">
                <div class="bg-gray-100 rounded-full w-24 h-24 flex items-center justify-center mx-auto mb-4">
                    <i class="ri-image-line text-5xl text-gray-400"></i>
                </div>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">No images yet</h3>
                <p class="text-gray-500 mb-4">Add up to 10 images for this location</p>
                <button onclick="openModal('addImageModal')" class="bg-primary hover:bg-secondary text-white px-6 py-3 rounded-lg inline-flex items-center space-x-2">
                    <i class="ri-image-add-line"></i>
                    <span>Add First Image</span>
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Image Modal -->
    <div id="addImageModal" class="modal">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-2xl font-semibold text-gray-800">Add Image to <?php echo htmlspecialchars($location['location_name']); ?></h3>
                <button onclick="closeModal('addImageModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_image" />
                <div class="space-y-4">
                    <div>
    <label class="block text-sm font-medium text-gray-700 mb-2">Select Images (Max: <?php echo 10 - $image_count; ?>) *</label>
    <input type="file" name="location_images[]" accept="image/*" multiple required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" onchange="previewMultipleImages(this)" />
    <div id="imagePreview" class="mt-3 grid grid-cols-2 gap-2 hidden"></div>
    <p class="text-xs text-gray-500 mt-2">
        <i class="ri-information-line"></i>
        You can select multiple images at once. All images will be automatically converted to WebP format
    </p>
</div>
                    
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
    <div class="flex items-start space-x-3">
        <i class="ri-lightbulb-line text-blue-600 text-xl mt-0.5"></i>
        <div class="flex-1">
            <p class="text-sm font-medium text-blue-900 mb-1">Tips for better images:</p>
            <ul class="text-xs text-blue-700 space-y-1">
                <li>• You can upload up to <?php echo 10 - $image_count; ?> images at once</li>
                <li>• Use high-resolution images (at least 1920x1080)</li>
                <li>• Ensure good lighting in the photos</li>
                <li>• Take photos from different angles</li>
            </ul>
        </div>
    </div>
</div>
                    
                    <button type="submit" class="w-full bg-primary hover:bg-secondary text-white py-3 rounded-lg font-medium transition-colors">
                        <i class="ri-upload-2-line mr-2"></i>Upload Image
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
    document.body.style.overflow = 'auto';
    const form = document.querySelector('#' + modalId + ' form');
    if (form) form.reset();
    const preview = document.getElementById('imagePreview');
    if (preview) {
        preview.classList.add('hidden');
        preview.innerHTML = '';
    }
}

        function previewImage(input, previewId) {
    const file = input.files[0];
    const preview = document.getElementById(previewId);
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `<img src="${e.target.result}" alt="Preview" />`;
            preview.classList.remove('hidden');
        }
        reader.readAsDataURL(file);
    }
}

function previewMultipleImages(input) {
    const preview = document.getElementById('imagePreview');
    const files = input.files;
    
    if (files.length > 0) {
        preview.innerHTML = '';
        preview.classList.remove('hidden');
        
        // Show count
        const countDiv = document.createElement('div');
        countDiv.className = 'col-span-2 text-sm font-medium text-gray-700 mb-2';
        countDiv.innerHTML = `<i class="ri-image-line mr-1"></i>${files.length} image(s) selected`;
        preview.appendChild(countDiv);
        
        for (let i = 0; i < Math.min(files.length, 10); i++) {
            const file = files[i];
            const reader = new FileReader();
            
            reader.onload = function(e) {
                const imgWrapper = document.createElement('div');
                imgWrapper.className = 'relative border-2 border-dashed border-gray-300 rounded-lg p-2';
                imgWrapper.innerHTML = `
                    <img src="${e.target.result}" alt="Preview ${i + 1}" class="w-full h-32 object-cover rounded" />
                    <div class="text-xs text-center text-gray-500 mt-1 truncate">${file.name}</div>
                `;
                preview.appendChild(imgWrapper);
            }
            
            reader.readAsDataURL(file);
        }
        
        if (files.length > 10) {
            const warningDiv = document.createElement('div');
            warningDiv.className = 'col-span-2 text-sm text-amber-600 bg-amber-50 border border-amber-200 rounded p-2';
            warningDiv.innerHTML = `<i class="ri-alert-line mr-1"></i>Only first 10 images will be shown in preview`;
            preview.appendChild(warningDiv);
        }
    } else {
        preview.classList.add('hidden');
        preview.innerHTML = '';
    }
}
    </script>
</body>
</html>

<?php $conn->close(); ?>