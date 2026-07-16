<?php
//projects_view.php
session_start();
include '../../connection/connection.php';
include '../design/mainbody.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

$success_message = "";
$error_message = "";
$category_filter = $_GET['category'] ?? 'all';

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
    
    if ($action === 'add_project') {
        $title = trim($_POST['title']);
        $category = $_POST['category'];
        $address = trim($_POST['address']);
        $description = trim($_POST['description']);
        
        $target_dir = "../../realiving_user/images/projects/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $errors = [];
        $uploaded_files = [];
        
        // Process main image
        if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === 0) {
            $file_name = uniqid() . '_main_' . time() . '.webp';
            $target_file = $target_dir . $file_name;
            
            if (convertToWebP($_FILES['main_image']['tmp_name'], $target_file)) {
                $uploaded_files['main_image'] = './images/projects/' . $file_name;
            } else {
                $errors[] = "Failed to convert main image to WebP.";
            }
        }
        
        // Process hover image
        if (isset($_FILES['hover_image']) && $_FILES['hover_image']['error'] === 0) {
            $file_name = uniqid() . '_hover_' . time() . '.webp';
            $target_file = $target_dir . $file_name;
            
            if (convertToWebP($_FILES['hover_image']['tmp_name'], $target_file)) {
                $uploaded_files['hover_image'] = './images/projects/' . $file_name;
            } else {
                $errors[] = "Failed to convert hover image to WebP.";
            }
        }
        
        // Process image1
        if (isset($_FILES['image1']) && $_FILES['image1']['error'] === 0) {
            $file_name = uniqid() . '_img1_' . time() . '.webp';
            $target_file = $target_dir . $file_name;
            
            if (convertToWebP($_FILES['image1']['tmp_name'], $target_file)) {
                $uploaded_files['image1'] = './images/projects/' . $file_name;
            } else {
                $errors[] = "Failed to convert image 1 to WebP.";
            }
        }
        
        // Process image2
        if (isset($_FILES['image2']) && $_FILES['image2']['error'] === 0) {
            $file_name = uniqid() . '_img2_' . time() . '.webp';
            $target_file = $target_dir . $file_name;
            
            if (convertToWebP($_FILES['image2']['tmp_name'], $target_file)) {
                $uploaded_files['image2'] = './images/projects/' . $file_name;
            } else {
                $errors[] = "Failed to convert image 2 to WebP.";
            }
        }
        
        if (empty($errors) && count($uploaded_files) >= 4) {
            $stmt = $conn->prepare("INSERT INTO project (title, category, address, description, main_image, hover_image, image1, image2) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssss", 
                $title, 
                $category, 
                $address, 
                $description, 
                $uploaded_files['main_image'],
                $uploaded_files['hover_image'],
                $uploaded_files['image1'],
                $uploaded_files['image2']
            );
            
            if ($stmt->execute()) {
                $success_message = "Project added successfully! All images converted to WebP.";
            } else {
                $error_message = "Database error: " . $conn->error;
            }
            $stmt->close();
        } else {
            $error_message = empty($errors) ? "Please upload all 4 required images." : implode(" ", $errors);
        }
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id']);
        
        // Get file paths first
        $stmt = $conn->prepare("SELECT main_image, hover_image, image1, image2 FROM project WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $project = $result->fetch_assoc();
        $stmt->close();
        
        // Delete from database
        $stmt = $conn->prepare("DELETE FROM project WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            // Delete files
            foreach (['main_image', 'hover_image', 'image1', 'image2'] as $key) {
                if (!empty($project[$key])) {
                    $file_path = "../../realiving_user/" . $project[$key];
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }
            }
            $success_message = "Project deleted successfully!";
        } else {
            $error_message = "Failed to delete project.";
        }
        $stmt->close();
    }
    
    if ($action === 'edit_project') {
        $id = intval($_POST['id']);
        $title = trim($_POST['title']);
        $category = $_POST['category'];
        $address = trim($_POST['address']);
        $description = trim($_POST['description']);
        
        // Get existing project data
        $stmt = $conn->prepare("SELECT main_image, hover_image, image1, image2 FROM project WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing = $result->fetch_assoc();
        $stmt->close();
        
        $target_dir = "../../realiving_user/images/projects/";
        $updated_files = [
            'main_image' => $existing['main_image'],
            'hover_image' => $existing['hover_image'],
            'image1' => $existing['image1'],
            'image2' => $existing['image2']
        ];
        
        // Process each image if uploaded
        $image_fields = ['main_image', 'hover_image', 'image1', 'image2'];
        foreach ($image_fields as $field) {
            if (isset($_FILES[$field]) && $_FILES[$field]['error'] === 0) {
                // Delete old file
                if (!empty($existing[$field])) {
                    $old_file = "../../realiving_user/" . $existing[$field];
                    if (file_exists($old_file)) {
                        unlink($old_file);
                    }
                }
                
                // Upload new file
                $file_name = uniqid() . '_' . $field . '_' . time() . '.webp';
                $target_file = $target_dir . $file_name;
                
                if (convertToWebP($_FILES[$field]['tmp_name'], $target_file)) {
                    $updated_files[$field] = './images/projects/' . $file_name;
                }
            }
        }
        
        // Update database
        $stmt = $conn->prepare("UPDATE project SET title = ?, category = ?, address = ?, description = ?, main_image = ?, hover_image = ?, image1 = ?, image2 = ? WHERE id = ?");
        $stmt->bind_param("ssssssssi", 
            $title, 
            $category, 
            $address, 
            $description, 
            $updated_files['main_image'],
            $updated_files['hover_image'],
            $updated_files['image1'],
            $updated_files['image2'],
            $id
        );
        
        if ($stmt->execute()) {
            $success_message = "Project updated successfully!";
        } else {
            $error_message = "Failed to update project.";
        }
        $stmt->close();
    }
}

// Fetch projects
$where_clause = "";
if ($category_filter !== 'all') {
    $where_clause = "WHERE category = '" . $conn->real_escape_string($category_filter) . "'";
}
$projects = $conn->query("SELECT * FROM project $where_clause ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Projects Management</title>
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
            max-height: 200px;
            margin: 0 auto;
            border-radius: 0.5rem;
        }
    </style>
</head>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <a href="projects_dashboard.php" class="text-primary hover:text-secondary flex items-center space-x-2 mb-4">
                    <i class="ri-arrow-left-line"></i>
                    <span>Back to Dashboard</span>
                </a>
                <h1 class="text-3xl font-bold text-gray-800">
                    <?php 
                    $titles = [
                        'all' => 'All Projects',
                        'site' => 'Site Projects',
                        'residential' => 'Residential Interiors'
                    ];
                    echo $titles[$category_filter] ?? 'All Projects';
                    ?>
                </h1>
                <p class="text-gray-500">Manage and organize your projects</p>
            </div>
            <div class="flex flex-col sm:flex-row gap-3">
                <select onchange="window.location.href='?category=' + this.value" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary">
                    <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                    <option value="site" <?php echo $category_filter === 'site' ? 'selected' : ''; ?>>Site Projects</option>
                    <option value="residential" <?php echo $category_filter === 'residential' ? 'selected' : ''; ?>>Residential</option>
                </select>
                <button onclick="openModal('addProjectModal')" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded-lg flex items-center justify-center space-x-2 transition-colors shadow-sm">
                    <i class="ri-add-line text-xl"></i>
                    <span class="font-medium">Add Project</span>
                </button>
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

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php while ($project = $projects->fetch_assoc()): 
                $main_img_path = "../../realiving_user/" . $project['main_image'];
            ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
                    <div class="relative h-48 overflow-hidden group">
                        <img src="<?php echo htmlspecialchars($main_img_path); ?>" alt="Project" class="w-full h-full object-cover" />
                        <div class="absolute top-2 right-2">
                            <span class="px-3 py-1 text-xs font-medium rounded-full <?php 
                                echo $project['category'] === 'site' ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700';
                            ?>">
                                <?php echo ucfirst($project['category']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="p-4">
                        <h3 class="font-semibold text-gray-800 mb-1 text-lg"><?php echo htmlspecialchars($project['title']); ?></h3>
                        <p class="text-sm text-gray-500 mb-3 flex items-center">
                            <i class="ri-map-pin-line mr-1"></i>
                            <?php echo htmlspecialchars($project['address']); ?>
                        </p>
                        <p class="text-sm text-gray-600 mb-4 line-clamp-2"><?php echo htmlspecialchars(substr($project['description'], 0, 100)) . '...'; ?></p>
                        <div class="pt-3 border-t border-gray-100 space-y-2">
    <div class="flex items-center justify-between">
        <a href="../../realiving_user/projects/project-template-example.php?id=<?php echo $project['id']; ?>" target="_blank" class="text-primary hover:text-secondary text-sm font-medium flex items-center">
            <i class="ri-external-link-line mr-1"></i>
            View Project
        </a>
        <div class="flex space-x-2">
            <button onclick="editProject(<?php echo htmlspecialchars(json_encode($project)); ?>)" class="text-blue-600 hover:text-blue-700 p-2">
                <i class="ri-edit-line text-lg"></i>
            </button>
            <form method="POST" onsubmit="return confirm('Delete this project and all its images?');" class="inline">
                <input type="hidden" name="action" value="delete" />
                <input type="hidden" name="id" value="<?php echo $project['id']; ?>" />
                <button type="submit" class="text-red-600 hover:text-red-700 p-2">
                    <i class="ri-delete-bin-line text-lg"></i>
                </button>
            </form>
        </div>
    </div>
    <a href="project_locations.php?project_id=<?php echo $project['id']; ?>" class="block w-full text-center bg-amber-50 hover:bg-amber-100 text-amber-700 py-2 rounded-lg text-sm font-medium transition-colors">
        <i class="ri-map-pin-add-line mr-1"></i>
        Manage Locations (<?php 
            $loc_count = $conn->query("SELECT COUNT(*) as count FROM project_locations WHERE project_id = " . $project['id']);
            echo $loc_count->fetch_assoc()['count'];
        ?>)
    </a>
</div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <?php if ($projects->num_rows === 0): ?>
            <div class="text-center py-12">
                <i class="ri-folder-open-line text-6xl text-gray-300 mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">No projects found</h3>
                <p class="text-gray-500 mb-4">Start by adding your first project</p>
                <button onclick="openModal('addProjectModal')" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded-lg inline-flex items-center space-x-2">
                    <i class="ri-add-line"></i>
                    <span>Add Project</span>
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Project Modal -->
    <div id="addProjectModal" class="modal">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-3xl w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-2xl font-semibold text-gray-800">Add New Project</h3>
                <button onclick="closeModal('addProjectModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_project" />
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Project Title *</label>
                            <input type="text" name="title" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="Enter project title" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Category *</label>
                            <select name="category" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                <option value="">Select category</option>
                                <option value="site">Site Projects</option>
                                <option value="residential">Residential Interiors</option>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Address/Location *</label>
                        <input type="text" name="address" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="Enter project location" />
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description *</label>
                        <textarea name="description" rows="4" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="Enter project description"></textarea>
                    </div>

                    <div class="border-t pt-4">
                        <h4 class="text-lg font-semibold text-gray-800 mb-4">Project Images (All will be converted to WebP)</h4>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Main Image (Card) *</label>
                                <input type="file" name="main_image" accept="image/*" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" onchange="previewImage(this, 'mainPreview')" />
                                <div id="mainPreview" class="image-preview mt-2 hidden"></div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Hover Image (Card) *</label>
                                <input type="file" name="hover_image" accept="image/*" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" onchange="previewImage(this, 'hoverPreview')" />
                                <div id="hoverPreview" class="image-preview mt-2 hidden"></div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Detail Image 1 *</label>
                                <input type="file" name="image1" accept="image/*" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" onchange="previewImage(this, 'img1Preview')" />
                                <div id="img1Preview" class="image-preview mt-2 hidden"></div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Detail Image 2 *</label>
                                <input type="file" name="image2" accept="image/*" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" onchange="previewImage(this, 'img2Preview')" />
                                <div id="img2Preview" class="image-preview mt-2 hidden"></div>
                            </div>
                        </div>
                        
                        <p class="text-sm text-gray-500 mt-3">
                            <i class="ri-information-line"></i>
                            All images will be automatically converted to WebP format for optimal performance.
                        </p>
                    </div>
                    
                    <button type="submit" class="w-full bg-primary hover:bg-secondary text-white py-3 rounded-lg font-medium transition-colors">
                        <i class="ri-add-line mr-2"></i>Add Project
                    </button>
                </div>
            </form>
        </div>
    </div>
<!-- Edit Project Modal -->
    <div id="editProjectModal" class="modal">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-3xl w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-2xl font-semibold text-gray-800">Edit Project</h3>
                <button onclick="closeModal('editProjectModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="editProjectForm">
                <input type="hidden" name="action" value="edit_project" />
                <input type="hidden" name="id" id="edit_id" />
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Project Title *</label>
                            <input type="text" name="title" id="edit_title" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Category *</label>
                            <select name="category" id="edit_category" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary">
                                <option value="">Select category</option>
                                <option value="site">Site Projects</option>
                                <option value="residential">Residential Interiors</option>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Address/Location *</label>
                        <input type="text" name="address" id="edit_address" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" />
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description *</label>
                        <textarea name="description" id="edit_description" rows="4" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary"></textarea>
                    </div>

                    <div class="border-t pt-4">
                        <h4 class="text-lg font-semibold text-gray-800 mb-2">Update Images (Optional)</h4>
                        <p class="text-sm text-gray-500 mb-4">Leave empty to keep existing images. Upload new images to replace them.</p>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Main Image (Card)</label>
                                <input type="file" name="main_image" accept="image/*" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" onchange="previewImage(this, 'editMainPreview')" />
                                <div id="editMainPreview" class="image-preview mt-2"></div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Hover Image (Card)</label>
                                <input type="file" name="hover_image" accept="image/*" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" onchange="previewImage(this, 'editHoverPreview')" />
                                <div id="editHoverPreview" class="image-preview mt-2"></div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Detail Image 1</label>
                                <input type="file" name="image1" accept="image/*" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" onchange="previewImage(this, 'editImg1Preview')" />
                                <div id="editImg1Preview" class="image-preview mt-2"></div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Detail Image 2</label>
                                <input type="file" name="image2" accept="image/*" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" onchange="previewImage(this, 'editImg2Preview')" />
                                <div id="editImg2Preview" class="image-preview mt-2"></div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="w-full bg-primary hover:bg-secondary text-white py-3 rounded-lg font-medium transition-colors">
                        <i class="ri-save-line mr-2"></i>Update Project
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
            // Clear all previews
            ['mainPreview', 'hoverPreview', 'img1Preview', 'img2Preview', 'editMainPreview', 'editHoverPreview', 'editImg1Preview', 'editImg2Preview'].forEach(id => {
                const preview = document.getElementById(id);
                if (preview) {
                    preview.classList.add('hidden');
                    preview.innerHTML = '';
                }
            });
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

        function editProject(project) {
            // Populate form fields
            document.getElementById('edit_id').value = project.id;
            document.getElementById('edit_title').value = project.title;
            document.getElementById('edit_category').value = project.category;
            document.getElementById('edit_address').value = project.address;
            document.getElementById('edit_description').value = project.description;
            
            // Show existing images as previews
            const imageFields = [
                { id: 'editMainPreview', path: project.main_image },
                { id: 'editHoverPreview', path: project.hover_image },
                { id: 'editImg1Preview', path: project.image1 },
                { id: 'editImg2Preview', path: project.image2 }
            ];
            
            imageFields.forEach(field => {
                const preview = document.getElementById(field.id);
                if (field.path) {
                    preview.innerHTML = `<img src="../../realiving_user/${field.path}" alt="Current Image" />
                        <p class="text-xs text-gray-500 mt-1">Current image (upload new to replace)</p>`;
                    preview.classList.remove('hidden');
                }
            });
            
            // Open modal
            openModal('editProjectModal');
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                closeModal(event.target.id);
            }
        }
    </script>
</html>

<?php $conn->close(); ?>