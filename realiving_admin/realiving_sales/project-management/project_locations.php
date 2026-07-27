<?php
//project_locations.php
include $includes ['mainbody'];

$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

// Verify project exists
$project_stmt = $conn->prepare("SELECT id, title FROM project WHERE id = ?");
$project_stmt->bind_param("i", $project_id);
$project_stmt->execute();
$project_result = $project_stmt->get_result();

if ($project_result->num_rows === 0) {
    header("Location: " . BASE_URL . "projects-view");
    exit();
}

$project = $project_result->fetch_assoc();
$project_stmt->close();

$success_message = "";
$error_message = "";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_location') {
        $location_name = trim($_POST['location_name']);
        
        $stmt = $conn->prepare("INSERT INTO project_locations (project_id, location_name) VALUES (?, ?)");
        $stmt->bind_param("is", $project_id, $location_name);
        
        if ($stmt->execute()) {
            $success_message = "Location '" . htmlspecialchars($location_name) . "' added successfully!";
        } else {
            $error_message = "Database error: " . $conn->error;
        }
        $stmt->close();
    }
    
    if ($action === 'delete_location') {
        $location_id = intval($_POST['location_id']);
        
        // Get all images for this location
        $img_stmt = $conn->prepare("SELECT image_path FROM project_location_images WHERE location_id = ?");
        $img_stmt->bind_param("i", $location_id);
        $img_stmt->execute();
        $img_result = $img_stmt->get_result();
        
        // Delete all image files
        while ($img = $img_result->fetch_assoc()) {
            $file_path = "../../realiving_user/" . $img['image_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        $img_stmt->close();
        
        // Delete location (cascade will delete images from DB)
        $stmt = $conn->prepare("DELETE FROM project_locations WHERE id = ? AND project_id = ?");
        $stmt->bind_param("ii", $location_id, $project_id);
        
        if ($stmt->execute()) {
            $success_message = "Location and all its images deleted successfully!";
        } else {
            $error_message = "Failed to delete location.";
        }
        $stmt->close();
    }
    
    if ($action === 'update_location') {
        $location_id = intval($_POST['location_id']);
        $location_name = trim($_POST['location_name']);
        
        $stmt = $conn->prepare("UPDATE project_locations SET location_name = ? WHERE id = ? AND project_id = ?");
        $stmt->bind_param("sii", $location_name, $location_id, $project_id);
        
        if ($stmt->execute()) {
            $success_message = "Location name updated successfully!";
        } else {
            $error_message = "Failed to update location name.";
        }
        $stmt->close();
    }
}

// Fetch all locations for this project with image count
$locations_query = "
    SELECT pl.*, 
           COUNT(pli.id) as image_count 
    FROM project_locations pl 
    LEFT JOIN project_location_images pli ON pl.id = pli.location_id 
    WHERE pl.project_id = $project_id 
    GROUP BY pl.id 
    ORDER BY pl.id DESC
";
$locations = $conn->query($locations_query);
$location_count = $locations->num_rows;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Manage Locations - <?php echo htmlspecialchars($project['title']); ?></title>
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
    </style>
</head>
<body class="bg-gray-50 p-8">
    <div class="max-w-7xl mx-auto">
        <div class="mb-6">
            <a href="projects-view" class="text-primary hover:text-secondary flex items-center space-x-2 mb-4">
                <i class="ri-arrow-left-line"></i>
                <span>Back to Projects</span>
            </a>
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">Manage Locations</h1>
                    <p class="text-gray-500">Project: <span class="font-semibold"><?php echo htmlspecialchars($project['title']); ?></span></p>
                    <p class="text-sm text-gray-500 mt-1">
                        <i class="ri-map-pin-line"></i>
                        <?php echo $location_count; ?> location(s) • Each location can have up to 10 images
                    </p>
                </div>
                <div>
                    <button onclick="openModal('addLocationModal')" class="bg-primary hover:bg-secondary text-white px-6 py-3 rounded-lg flex items-center justify-center space-x-2 transition-colors shadow-sm">
                        <i class="ri-add-line text-xl"></i>
                        <span class="font-medium">Add New Location</span>
                    </button>
                </div>
            </div>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="mb-6 p-4 bg-green-50 border-l-4 border-green-500 rounded-lg">
                <div class="flex">
                    <i class="ri-checkbox-circle-line text-green-500 text-xl"></i>
                    <p class="ml-3 text-sm text-green-700 font-medium"><?php echo $success_message; ?></p>
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
            <?php while ($location = $locations->fetch_assoc()): ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
                    <div class="bg-gradient-to-br from-primary to-secondary p-6 text-white">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <h3 class="text-xl font-semibold mb-2 flex items-center">
                                    <i class="ri-map-pin-fill mr-2"></i>
                                    <?php echo htmlspecialchars($location['location_name']); ?>
                                </h3>
                                <p class="text-sm opacity-90">
                                    <?php echo $location['image_count']; ?> of 10 images
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 bg-white/20 rounded-full h-2 overflow-hidden">
                            <div class="bg-white h-full transition-all" style="width: <?php echo ($location['image_count'] / 10) * 100; ?>%"></div>
                        </div>
                    </div>
                    
                    <div class="p-4 space-y-3">
                        <div class="text-sm text-gray-500">
                            Created: <?php echo date('M d, Y', strtotime($location['created_at'])); ?>
                        </div>
                        
                        <a href="project-location-images?location_id=<?php echo $location['id']; ?>" class="block w-full text-center bg-primary hover:bg-secondary text-white py-2.5 rounded-lg font-medium transition-colors">
                            <i class="ri-image-line mr-1"></i>
                            Manage Images
                        </a>
                        
                        <div class="flex space-x-2">
                            <button onclick="editLocation(<?php echo htmlspecialchars(json_encode($location)); ?>)" class="flex-1 bg-blue-50 hover:bg-blue-100 text-blue-600 py-2 rounded-lg text-sm font-medium transition-colors">
                                <i class="ri-edit-line mr-1"></i>Rename
                            </button>
                            <form method="POST" onsubmit="return confirm('Delete this location and all its <?php echo $location['image_count']; ?> images?');" class="flex-1">
                                <input type="hidden" name="action" value="delete_location" />
                                <input type="hidden" name="location_id" value="<?php echo $location['id']; ?>" />
                                <button type="submit" class="w-full bg-red-50 hover:bg-red-100 text-red-600 py-2 rounded-lg text-sm font-medium transition-colors">
                                    <i class="ri-delete-bin-line mr-1"></i>Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <?php if ($location_count === 0): ?>
            <div class="text-center py-12">
                <i class="ri-map-pin-line text-6xl text-gray-300 mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">No locations yet</h3>
                <p class="text-gray-500 mb-4">Add locations for your project (e.g., Living Room, Kitchen, Bedroom)</p>
                <button onclick="openModal('addLocationModal')" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded-lg inline-flex items-center space-x-2">
                    <i class="ri-add-line"></i>
                    <span>Add First Location</span>
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Location Modal -->
    <div id="addLocationModal" class="modal">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-2xl font-semibold text-gray-800">Add New Location</h3>
                <button onclick="closeModal('addLocationModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_location" />
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Location Name *</label>
                        <input type="text" name="location_name" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="e.g., Living Room, Kitchen, Master Bedroom" />
                        <p class="text-xs text-gray-500 mt-1">Give this area a descriptive name</p>
                    </div>
                    
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <p class="text-sm text-blue-700">
                            <i class="ri-information-line mr-1"></i>
                            After creating the location, you'll be able to add up to 10 images for it.
                        </p>
                    </div>
                    
                    <button type="submit" class="w-full bg-primary hover:bg-secondary text-white py-3 rounded-lg font-medium transition-colors">
                        <i class="ri-add-line mr-2"></i>Create Location
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Location Modal -->
    <div id="editLocationModal" class="modal">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-2xl font-semibold text-gray-800">Rename Location</h3>
                <button onclick="closeModal('editLocationModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <form method="POST" id="editLocationForm">
                <input type="hidden" name="action" value="update_location" />
                <input type="hidden" name="location_id" id="edit_location_id" />
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Location Name *</label>
                        <input type="text" name="location_name" id="edit_location_name" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary/20 focus:border-primary" />
                    </div>
                    
                    <button type="submit" class="w-full bg-primary hover:bg-secondary text-white py-3 rounded-lg font-medium transition-colors">
                        <i class="ri-save-line mr-2"></i>Update Location Name
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
        }

        function editLocation(location) {
            document.getElementById('edit_location_id').value = location.id;
            document.getElementById('edit_location_name').value = location.location_name;
            openModal('editLocationModal');
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                closeModal(event.target.id);
            }
        }
    </script>
</body>
</html>

<?php $conn->close(); ?>