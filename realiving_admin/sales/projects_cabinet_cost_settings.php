<?php
//projects_cabinet_cost_settings.php
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_image') {
        $target_dir = "../../realiving_user/images/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        if (isset($_FILES['cabinet_image']) && $_FILES['cabinet_image']['error'] === 0) {
            // Delete old image first
            $old_image_path = $target_dir . "background-image.webp";
            if (file_exists($old_image_path)) {
                unlink($old_image_path);
            }
            
            $file_name = "background-image.webp";
            $target_file = $target_dir . $file_name;
            
            if (convertToWebP($_FILES['cabinet_image']['tmp_name'], $target_file)) {
                $success_message = "Cabinet cost image updated successfully and converted to WebP!";
            } else {
                $error_message = "Failed to convert image to WebP.";
            }
        } else {
            $error_message = "Please select an image.";
        }
    }
}

// Get current image
$current_image = "../../realiving_user/images/background-image.webp";
$image_exists = file_exists($current_image);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Cabinet Cost Section Settings</title>
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
    </style>
</head>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6">
            <a href="projects_dashboard.php" class="text-primary hover:text-secondary flex items-center space-x-2 mb-4">
                <i class="ri-arrow-left-line"></i>
                <span>Back to Dashboard</span>
            </a>
            <h1 class="text-3xl font-bold text-gray-800">Cabinet Cost Section</h1>
            <p class="text-gray-500">Manage the background image for "Know Your Cabinet Cost" section</p>
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

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-8">
            <div class="mb-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Current Background Image</h2>
                <p class="text-sm text-gray-500">This image appears in the "Know Your Cabinet Cost" section</p>
            </div>

            <?php if ($image_exists): ?>
                <div class="mb-6">
                    <div class="relative rounded-lg overflow-hidden border-2 border-gray-200">
                        <img src="<?php echo $current_image; ?>?v=<?php echo time(); ?>" alt="Current Cabinet Image" class="w-full h-96 object-cover" />
                        <div class="absolute top-4 right-4 bg-green-500 text-white px-3 py-1 rounded-full text-sm font-medium flex items-center">
                            <i class="ri-check-line mr-1"></i>
                            WebP Format
                        </div>
                    </div>
                    <div class="mt-4 p-4 bg-blue-50 rounded-lg">
                        <p class="text-sm text-blue-700 flex items-start">
                            <i class="ri-information-line mt-0.5 mr-2"></i>
                            <span>This image is displayed in the "Know Your Cabinet Cost with Confidence" section on the projects page. It should be high-quality and relevant to kitchen cabinets or interior design.</span>
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <div class="mb-6 p-8 border-2 border-dashed border-gray-300 rounded-lg text-center">
                    <i class="ri-image-line text-6xl text-gray-300 mb-4"></i>
                    <p class="text-gray-500">No image uploaded yet</p>
                </div>
            <?php endif; ?>

            <button onclick="openModal('uploadModal')" class="w-full bg-primary hover:bg-secondary text-white py-3 rounded-lg font-medium transition-colors flex items-center justify-center space-x-2">
                <i class="ri-upload-line text-xl"></i>
                <span><?php echo $image_exists ? 'Replace Image' : 'Upload Image'; ?></span>
            </button>
        </div>

        <!-- Preview Section -->
        <div class="mt-8 bg-white rounded-2xl shadow-sm border border-gray-200 p-8">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Section Preview</h2>
            <p class="text-sm text-gray-500 mb-6">This is how the section appears on the website</p>
            
            <div class="border-2 border-gray-200 rounded-lg overflow-hidden">
                <div class="bg-gray-50 p-6 flex items-center gap-6">
                    <div class="flex-1">
                        <h3 class="text-2xl font-bold text-gray-800 mb-3">Know Your Cabinet Cost with Confidence</h3>
                        <p class="text-gray-600 mb-4">
                            Have a vision in mind but not sure where to begin? Let's talk. Our design experts are ready to guide you through every step—from concept to completion.
                        </p>
                        <button class="bg-amber-600 hover:bg-amber-700 text-white px-6 py-2 rounded font-medium">
                            BOOK AN APPOINTMENT NOW
                        </button>
                    </div>
                    <div class="flex-1">
                        <?php if ($image_exists): ?>
                            <img src="<?php echo $current_image; ?>?v=<?php echo time(); ?>" alt="Cabinet" class="w-full h-64 object-cover rounded-lg" />
                        <?php else: ?>
                            <div class="w-full h-64 bg-gray-200 rounded-lg flex items-center justify-center">
                                <span class="text-gray-400">No image</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div id="uploadModal" class="modal">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-semibold text-gray-800">Upload Cabinet Image</h3>
                <button onclick="closeModal('uploadModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_image" />
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select Image</label>
                        <input type="file" name="cabinet_image" id="cabinetImageInput" accept="image/*" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" onchange="previewImage(event, 'cabinetPreview')" />
                        <p class="text-xs text-gray-500 mt-1">Will be converted to WebP automatically</p>
                    </div>
                    <div id="cabinetPreview" class="hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Preview</label>
                        <div class="relative rounded-lg overflow-hidden border-2 border-gray-200">
                            <img id="cabinetPreviewImage" src="" alt="Preview" class="w-full h-64 object-cover" />
                            <button type="button" onclick="clearPreview('cabinetImageInput', 'cabinetPreview')" class="absolute top-2 right-2 bg-red-500 hover:bg-red-600 text-white rounded-full p-2 shadow-lg transition-colors">
                                <i class="ri-close-line text-lg"></i>
                            </button>
                        </div>
                    </div>
                    <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg">
                        <p class="text-sm text-amber-800">
                            <i class="ri-information-line mr-1"></i>
                            Recommended: High-quality image of kitchen cabinets or interior design (min. 1920x1080px)
                        </p>
                    </div>
                    <button type="submit" class="w-full bg-primary hover:bg-secondary text-white py-3 rounded-lg font-medium transition-colors">
                        <i class="ri-upload-line mr-2"></i>Upload & Convert to WebP
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
            const form = document.querySelector('#' + modalId + ' form');
            if (form) form.reset();
            clearPreview('cabinetImageInput', 'cabinetPreview');
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