<?php
session_start();
include '../../connection/connection.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../loginpage/index.php");
    exit();
}

$success_message = "";
$error_message = "";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_hero') {
        $title = trim($_POST['title']);
        $target_dir = "../../realiving_user/images/hero_section/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            
            if ($file_extension !== 'webp') {
                $error_message = "Only WebP images are allowed.";
            } else {
                $file_name = uniqid() . '_' . time() . '.webp';
                $target_file = $target_dir . $file_name;
                $filepath = './images/hero_section/' . $file_name;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                    $stmt = $conn->prepare("INSERT INTO hero_section (title, filepath, is_active) VALUES (?, ?, 1)");
                    $stmt->bind_param("ss", $title, $filepath);
                    
                    if ($stmt->execute()) {
                        $success_message = "Hero image added successfully!";
                    } else {
                        $error_message = "Database error: " . $conn->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = "Failed to upload image.";
                }
            }
        } else {
            $error_message = "Please select an image.";
        }
    }
    
    if ($action === 'add_inquire') {
        $title = trim($_POST['title']);
        $target_dir = "../realiving_user/images/inquire_section/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            
            if ($file_extension !== 'webp') {
                $error_message = "Only WebP images are allowed.";
            } else {
                $file_name = uniqid() . '_' . time() . '.webp';
                $target_file = $target_dir . $file_name;
                $filepath = './images/inquire_section/' . $file_name;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                    $stmt = $conn->prepare("INSERT INTO inquire_images (title, filepath, is_active) VALUES (?, ?, 1)");
                    $stmt->bind_param("ss", $title, $filepath);
                    
                    if ($stmt->execute()) {
                        $success_message = "Inquire image added successfully!";
                    } else {
                        $error_message = "Database error: " . $conn->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = "Failed to upload image.";
                }
            }
        } else {
            $error_message = "Please select an image.";
        }
    }
    
    if ($action === 'add_ads') {
        $title = trim($_POST['title']);
        $target_dir = "../realiving_user/images/ads_banner/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            
            if ($file_extension !== 'webp') {
                $error_message = "Only WebP images are allowed.";
            } else {
                $file_name = uniqid() . '_' . time() . '.webp';
                $target_file = $target_dir . $file_name;
                $filepath = './images/ads_banner/' . $file_name;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                    $stmt = $conn->prepare("INSERT INTO ads_banner (title, filepath, is_active) VALUES (?, ?, 1)");
                    $stmt->bind_param("ss", $title, $filepath);
                    
                    if ($stmt->execute()) {
                        $success_message = "Ads banner added successfully!";
                    } else {
                        $error_message = "Database error: " . $conn->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = "Failed to upload image.";
                }
            }
        } else {
            $error_message = "Please select an image.";
        }
    }
    
    if ($action === 'toggle_status') {
        $id = intval($_POST['id']);
        $table = $_POST['table'];
        $current_status = intval($_POST['current_status']);
        $new_status = $current_status === 1 ? 0 : 1;
        
        $allowed_tables = ['hero_section', 'inquire_images', 'ads_banner'];
        if (in_array($table, $allowed_tables)) {
            $stmt = $conn->prepare("UPDATE $table SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $new_status, $id);
            
            if ($stmt->execute()) {
                $success_message = "Status updated successfully!";
            } else {
                $error_message = "Failed to update status.";
            }
            $stmt->close();
        }
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id']);
        $table = $_POST['table'];
        $filepath = $_POST['filepath'];
        
        $allowed_tables = ['hero_section', 'inquire_images', 'ads_banner'];
        if (in_array($table, $allowed_tables)) {
            $stmt = $conn->prepare("DELETE FROM $table WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                // Delete the physical file
                $file_to_delete = "../" . ltrim($filepath, '../');
                if (file_exists($file_to_delete)) {
                    unlink($file_to_delete);
                }
                $success_message = "Item deleted successfully!";
            } else {
                $error_message = "Failed to delete item.";
            }
            $stmt->close();
        }
    }
}

// Fetch all data
$hero_items = $conn->query("SELECT * FROM hero_section ORDER BY id DESC");
$inquire_items = $conn->query("SELECT * FROM inquire_images ORDER BY id DESC");
$ads_items = $conn->query("SELECT * FROM ads_banner ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Home Settings - Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: "#4f46e5",
                        secondary: "#4338ca",
                        accent: "#3730a3"
                    },
                }
            },
        };
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        
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
<body class="bg-gray-50">
    <!-- Header -->
    <div class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="../admin_mainpage/mainpage.php" class="text-gray-600 hover:text-primary transition-colors">
                        <i class="ri-arrow-left-line text-xl"></i>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Home Settings</h1>
                        <p class="text-sm text-gray-500">Manage hero section, inquire images, and ads banners</p>
                    </div>
                </div>
                <img src="../../realiving_user/images/logo/realiving_logo_hd.png" alt="Logo" class="h-12 object-contain" />
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Success/Error Messages -->
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

        <!-- Hero Section -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 mb-8">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                            <i class="ri-image-line text-primary mr-2"></i>
                            Hero Section
                        </h2>
                        <p class="text-sm text-gray-500 mt-1">Main banner images for homepage</p>
                    </div>
                    <button onclick="openModal('heroModal')" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg flex items-center space-x-2 transition-colors">
                        <i class="ri-add-line"></i>
                        <span>Add Hero Image</span>
                    </button>
                </div>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php while ($item = $hero_items->fetch_assoc()): ?>
                        <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                            <img src="<?php echo htmlspecialchars($item['filepath']); ?>" alt="Hero" class="w-full h-40 object-cover rounded-lg mb-3" />
                            <h3 class="font-medium text-gray-800 mb-2"><?php echo htmlspecialchars($item['title']); ?></h3>
                            <p class="text-xs text-gray-500 mb-3 break-all"><?php echo htmlspecialchars($item['filepath']); ?></p>
                            <div class="flex items-center justify-between">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle_status" />
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                                    <input type="hidden" name="table" value="hero_section" />
                                    <input type="hidden" name="current_status" value="<?php echo $item['is_active']; ?>" />
                                    <button type="submit" class="<?php echo $item['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'; ?> px-3 py-1 rounded-lg text-sm font-medium">
                                        <?php echo $item['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this item?');" class="inline">
                                    <input type="hidden" name="action" value="delete" />
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                                    <input type="hidden" name="table" value="hero_section" />
                                    <input type="hidden" name="filepath" value="<?php echo $item['filepath']; ?>" />
                                    <button type="submit" class="text-red-600 hover:text-red-700 transition-colors">
                                        <i class="ri-delete-bin-line text-lg"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>

        <!-- Inquire Image Section -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 mb-8">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                            <i class="ri-question-line text-primary mr-2"></i>
                            Inquire Image Setting
                        </h2>
                        <p class="text-sm text-gray-500 mt-1">Images for inquiry section</p>
                    </div>
                    <button onclick="openModal('inquireModal')" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg flex items-center space-x-2 transition-colors">
                        <i class="ri-add-line"></i>
                        <span>Add Inquire Image</span>
                    </button>
                </div>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php while ($item = $inquire_items->fetch_assoc()): ?>
                        <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                            <img src="<?php echo htmlspecialchars($item['filepath']); ?>" alt="Inquire" class="w-full h-40 object-cover rounded-lg mb-3" />
                            <h3 class="font-medium text-gray-800 mb-2"><?php echo htmlspecialchars($item['title']); ?></h3>
                            <p class="text-xs text-gray-500 mb-3 break-all"><?php echo htmlspecialchars($item['filepath']); ?></p>
                            <div class="flex items-center justify-between">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle_status" />
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                                    <input type="hidden" name="table" value="inquire_images" />
                                    <input type="hidden" name="current_status" value="<?php echo $item['is_active']; ?>" />
                                    <button type="submit" class="<?php echo $item['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'; ?> px-3 py-1 rounded-lg text-sm font-medium">
                                        <?php echo $item['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this item?');" class="inline">
                                    <input type="hidden" name="action" value="delete" />
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                                    <input type="hidden" name="table" value="inquire_images" />
                                    <input type="hidden" name="filepath" value="<?php echo $item['filepath']; ?>" />
                                    <button type="submit" class="text-red-600 hover:text-red-700 transition-colors">
                                        <i class="ri-delete-bin-line text-lg"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>

        <!-- Ads Banner Section -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                            <i class="ri-advertisement-line text-primary mr-2"></i>
                            Ads Banner Setting
                        </h2>
                        <p class="text-sm text-gray-500 mt-1">Advertisement banners for homepage</p>
                    </div>
                    <button onclick="openModal('adsModal')" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg flex items-center space-x-2 transition-colors">
                        <i class="ri-add-line"></i>
                        <span>Add Ads Banner</span>
                    </button>
                </div>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php while ($item = $ads_items->fetch_assoc()): ?>
                        <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                            <img src="<?php echo htmlspecialchars($item['filepath']); ?>" alt="Ads" class="w-full h-40 object-cover rounded-lg mb-3" />
                            <h3 class="font-medium text-gray-800 mb-2"><?php echo htmlspecialchars($item['title']); ?></h3>
                            <p class="text-xs text-gray-500 mb-3 break-all"><?php echo htmlspecialchars($item['filepath']); ?></p>
                            <div class="flex items-center justify-between">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle_status" />
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                                    <input type="hidden" name="table" value="ads_banner" />
                                    <input type="hidden" name="current_status" value="<?php echo $item['is_active']; ?>" />
                                    <button type="submit" class="<?php echo $item['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'; ?> px-3 py-1 rounded-lg text-sm font-medium">
                                        <?php echo $item['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this item?');" class="inline">
                                    <input type="hidden" name="action" value="delete" />
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>" />
                                    <input type="hidden" name="table" value="ads_banner" />
                                    <input type="hidden" name="filepath" value="<?php echo $item['filepath']; ?>" />
                                    <button type="submit" class="text-red-600 hover:text-red-700 transition-colors">
                                        <i class="ri-delete-bin-line text-lg"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <!-- Hero Modal -->
    <div id="heroModal" class="modal">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-semibold text-gray-800">Add Hero Image</h3>
                <button onclick="closeModal('heroModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_hero" />
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Title</label>
                        <input type="text" name="title" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="Enter image title" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Image (WebP only)</label>
                        <input type="file" name="image" accept=".webp" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" />
                    </div>
                    <button type="submit" class="w-full bg-primary hover:bg-secondary text-white py-3 rounded-lg font-medium transition-colors">
                        <i class="ri-upload-line mr-2"></i>Upload Image
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Inquire Modal -->
    <div id="inquireModal" class="modal">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-semibold text-gray-800">Add Inquire Image</h3>
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
                        <label class="block text-sm font-medium text-gray-700 mb-2">Image (WebP only)</label>
                        <input type="file" name="image" accept=".webp" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" />
                    </div>
                    <button type="submit" class="w-full bg-primary hover:bg-secondary text-white py-3 rounded-lg font-medium transition-colors">
                        <i class="ri-upload-line mr-2"></i>Upload Image
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Ads Modal -->
    <div id="adsModal" class="modal">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-semibold text-gray-800">Add Ads Banner</h3>
                <button onclick="closeModal('adsModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_ads" />
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Title</label>
                        <input type="text" name="title" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="Enter banner title" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Image (WebP only)</label>
                        <input type="file" name="image" accept=".webp" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" />
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
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>
</html>

<?php $conn->close(); ?>