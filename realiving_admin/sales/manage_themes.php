<?php
// manage_themes.php - Manage Themes and Sub-Themes
session_start();
include '../../connection/connection.php';
include '../design/mainbody.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

$success_message = "";
$error_message = "";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // THEME ACTIONS
    if ($action === 'add_theme') {
        $name = trim($_POST['name']);
        $slug = strtolower(str_replace(' ', '-', $name));
        $description = trim($_POST['description']);
        $display_order = intval($_POST['display_order']);
        
        $stmt = $conn->prepare("INSERT INTO themes (name, slug, description, display_order) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $name, $slug, $description, $display_order);
        
        if ($stmt->execute()) {
            $success_message = "Theme added successfully!";
        } else {
            $error_message = "Error: " . $conn->error;
        }
        $stmt->close();
    }
    
    if ($action === 'edit_theme') {
        $id = intval($_POST['id']);
        $name = trim($_POST['name']);
        $slug = strtolower(str_replace(' ', '-', $name));
        $description = trim($_POST['description']);
        $display_order = intval($_POST['display_order']);
        
        $stmt = $conn->prepare("UPDATE themes SET name=?, slug=?, description=?, display_order=? WHERE id=?");
        $stmt->bind_param("sssii", $name, $slug, $description, $display_order, $id);
        
        if ($stmt->execute()) {
            $success_message = "Theme updated successfully!";
        } else {
            $error_message = "Error: " . $conn->error;
        }
        $stmt->close();
    }
    
    if ($action === 'delete_theme') {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM themes WHERE id=?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $success_message = "Theme deleted successfully!";
        } else {
            $error_message = "Error: " . $conn->error;
        }
        $stmt->close();
    }
    
    // SUB-THEME ACTIONS
    if ($action === 'add_subtheme') {
        $name = trim($_POST['name']);
        $slug = strtolower(str_replace(' ', '-', $name));
        $theme_id = intval($_POST['theme_id']);
        $description = trim($_POST['description']);
        $display_order = intval($_POST['display_order']);
        
        $stmt = $conn->prepare("INSERT INTO sub_themes (name, slug, theme_id, description, display_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssisi", $name, $slug, $theme_id, $description, $display_order);
        
        if ($stmt->execute()) {
            $success_message = "Sub-theme added successfully!";
        } else {
            $error_message = "Error: " . $conn->error;
        }
        $stmt->close();
    }
    
    if ($action === 'edit_subtheme') {
        $id = intval($_POST['id']);
        $name = trim($_POST['name']);
        $slug = strtolower(str_replace(' ', '-', $name));
        $theme_id = intval($_POST['theme_id']);
        $description = trim($_POST['description']);
        $display_order = intval($_POST['display_order']);
        
        $stmt = $conn->prepare("UPDATE sub_themes SET name=?, slug=?, theme_id=?, description=?, display_order=? WHERE id=?");
        $stmt->bind_param("ssiiii", $name, $slug, $theme_id, $description, $display_order, $id);
        
        if ($stmt->execute()) {
            $success_message = "Sub-theme updated successfully!";
        } else {
            $error_message = "Error: " . $conn->error;
        }
        $stmt->close();
    }
    
    if ($action === 'delete_subtheme') {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM sub_themes WHERE id=?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $success_message = "Sub-theme deleted successfully!";
        } else {
            $error_message = "Error: " . $conn->error;
        }
        $stmt->close();
    }
}

// Fetch all themes with sub-theme count
$themes_query = "
    SELECT t.*, COUNT(st.id) as subtheme_count
    FROM themes t
    LEFT JOIN sub_themes st ON t.id = st.theme_id
    GROUP BY t.id
    ORDER BY t.display_order ASC, t.name ASC
";
$themes = $conn->query($themes_query);

// Fetch all sub-themes with theme name
$subthemes_query = "
    SELECT st.*, t.name as theme_name
    FROM sub_themes st
    JOIN themes t ON st.theme_id = t.id
    ORDER BY t.display_order ASC, st.display_order ASC
";
$subthemes = $conn->query($subthemes_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Manage Themes & Sub-Themes</title>
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
        .tab-button.active {
            background-color: #10b981;
            color: white;
        }
    </style>
</head>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <a href="gallery_dashboard_v2.php" class="text-green-600 hover:text-green-800 flex items-center space-x-2 mb-4">
                    <i class="ri-arrow-left-line"></i>
                    <span>Back to Dashboard</span>
                </a>
                <h1 class="text-3xl font-bold text-gray-800">Themes & Sub-Themes Management</h1>
                <p class="text-gray-500">Contemporary, Modern, Traditional → Minimalist, Industrial, Rustic, etc.</p>
            </div>
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

        <!-- Tab Navigation -->
        <div class="mb-6 flex items-center justify-between">
            <div class="flex space-x-2 bg-white p-1 rounded-lg border border-gray-200">
                <button onclick="switchTab('themes')" id="themesTab" class="tab-button active px-6 py-2 rounded-md transition-colors">
                    <i class="ri-palette-line mr-2"></i>Themes
                </button>
                <button onclick="switchTab('subthemes')" id="subthemesTab" class="tab-button px-6 py-2 rounded-md transition-colors">
                    <i class="ri-brush-line mr-2"></i>Sub-Themes
                </button>
            </div>
            <div>
                <button onclick="openAddThemeModal()" id="addThemeBtn" class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg flex items-center space-x-2 transition-colors shadow-sm">
                    <i class="ri-add-line text-xl"></i>
                    <span class="font-medium">Add Theme</span>
                </button>
                <button onclick="openAddSubthemeModal()" id="addSubthemeBtn" class="hidden bg-teal-600 hover:bg-teal-700 text-white px-6 py-3 rounded-lg flex items-center space-x-2 transition-colors shadow-sm">
                    <i class="ri-add-line text-xl"></i>
                    <span class="font-medium">Add Sub-Theme</span>
                </button>
            </div>
        </div>

        <!-- Themes Tab Content -->
        <div id="themesContent" class="tab-content">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php $themes->data_seek(0); while ($theme = $themes->fetch_assoc()): ?>
                    <div class="bg-white rounded-xl shadow-sm border-2 border-gray-200 p-6 hover:shadow-lg transition-all">
                        <div class="flex items-start justify-between mb-4">
                            <div class="p-3 bg-green-50 rounded-xl">
                                <i class="ri-palette-line text-3xl text-green-600"></i>
                            </div>
                            <div class="flex space-x-2">
                                <button onclick='editTheme(<?php echo htmlspecialchars(json_encode($theme), ENT_QUOTES, 'UTF-8'); ?>)' class="text-blue-600 hover:text-blue-700 p-2">
                                    <i class="ri-edit-line text-lg"></i>
                                </button>
                                <form method="POST" onsubmit="return confirm('Delete this theme and all its sub-themes?');" class="inline">
                                    <input type="hidden" name="action" value="delete_theme" />
                                    <input type="hidden" name="id" value="<?php echo $theme['id']; ?>" />
                                    <button type="submit" class="text-red-600 hover:text-red-700 p-2">
                                        <i class="ri-delete-bin-line text-lg"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-800 mb-2"><?php echo htmlspecialchars($theme['name']); ?></h3>
                        <p class="text-sm text-gray-500 mb-3"><?php echo htmlspecialchars($theme['description']); ?></p>
                        <div class="pt-3 border-t border-gray-100 flex items-center justify-between">
                            <span class="text-xs text-gray-500">Order: <?php echo $theme['display_order']; ?></span>
                            <span class="px-3 py-1 bg-teal-100 text-teal-700 rounded-full text-xs font-medium">
                                <?php echo $theme['subtheme_count']; ?> sub-themes
                            </span>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>

            <?php if ($themes->num_rows === 0): ?>
                <div class="text-center py-12">
                    <i class="ri-palette-line text-6xl text-gray-300 mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">No themes yet</h3>
                    <p class="text-gray-500 mb-4">Add your first theme to get started</p>
                    <button onclick="openAddThemeModal()" class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg">
                        <i class="ri-add-line mr-2"></i>Add Theme
                    </button>
                </div>
            <?php endif; ?>

            <!-- Common Theme Suggestions -->
            <div class="mt-8 bg-green-50 rounded-xl p-6 border-2 border-green-200">
                <h3 class="text-lg font-semibold text-gray-800 mb-3">💡 Common Themes</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div class="bg-white p-3 rounded-lg">Contemporary</div>
                    <div class="bg-white p-3 rounded-lg">Modern</div>
                    <div class="bg-white p-3 rounded-lg">Traditional</div>
                    <div class="bg-white p-3 rounded-lg">Industrial</div>
                    <div class="bg-white p-3 rounded-lg">Minimalist</div>
                    <div class="bg-white p-3 rounded-lg">Scandinavian</div>
                    <div class="bg-white p-3 rounded-lg">Rustic</div>
                    <div class="bg-white p-3 rounded-lg">Art Deco</div>
                </div>
            </div>
        </div>

        <!-- Sub-Themes Tab Content -->
        <div id="subthemesContent" class="tab-content hidden">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php while ($subtheme = $subthemes->fetch_assoc()): ?>
                    <div class="bg-white rounded-xl shadow-sm border-2 border-gray-200 p-5 hover:shadow-lg transition-all">
                        <div class="flex items-center justify-between mb-3">
                            <div class="p-2 bg-teal-50 rounded-lg">
                                <i class="ri-brush-line text-2xl text-teal-600"></i>
                            </div>
                            <div class="flex space-x-1">
                                <button onclick='editSubtheme(<?php echo htmlspecialchars(json_encode($subtheme), ENT_QUOTES, 'UTF-8'); ?>)' class="text-blue-600 hover:text-blue-700 p-1">
                                    <i class="ri-edit-line"></i>
                                </button>
                                <form method="POST" onsubmit="return confirm('Delete this sub-theme?');" class="inline">
                                    <input type="hidden" name="action" value="delete_subtheme" />
                                    <input type="hidden" name="id" value="<?php echo $subtheme['id']; ?>" />
                                    <button type="submit" class="text-red-600 hover:text-red-700 p-1">
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 mb-1"><?php echo htmlspecialchars($subtheme['name']); ?></h3>
                        <p class="text-xs text-green-600 font-medium mb-2">
                            <i class="ri-palette-line mr-1"></i><?php echo htmlspecialchars($subtheme['theme_name']); ?>
                        </p>
                        <p class="text-xs text-gray-500 mb-2"><?php echo htmlspecialchars($subtheme['description']); ?></p>
                        <span class="text-xs text-gray-400">Order: <?php echo $subtheme['display_order']; ?></span>
                    </div>
                <?php endwhile; ?>
            </div>

            <?php if ($subthemes->num_rows === 0): ?>
                <div class="text-center py-12">
                    <i class="ri-brush-line text-6xl text-gray-300 mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">No sub-themes yet</h3>
                    <p class="text-gray-500 mb-4">Add your first sub-theme</p>
                    <button onclick="openAddSubthemeModal()" class="bg-teal-600 hover:bg-teal-700 text-white px-6 py-3 rounded-lg">
                        <i class="ri-add-line mr-2"></i>Add Sub-Theme
                    </button>
                </div>
            <?php endif; ?>

            <!-- Common Sub-Theme Suggestions -->
            <div class="mt-8 bg-teal-50 rounded-xl p-6 border-2 border-teal-200">
                <h3 class="text-lg font-semibold text-gray-800 mb-3">💡 Example Sub-Themes</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div class="bg-white p-3 rounded-lg">Minimalist Contemporary</div>
                    <div class="bg-white p-3 rounded-lg">Industrial Modern</div>
                    <div class="bg-white p-3 rounded-lg">Warm Traditional</div>
                    <div class="bg-white p-3 rounded-lg">Coastal Rustic</div>
                    <div class="bg-white p-3 rounded-lg">Urban Industrial</div>
                    <div class="bg-white p-3 rounded-lg">Nordic Minimalist</div>
                    <div class="bg-white p-3 rounded-lg">Mid-Century Modern</div>
                    <div class="bg-white p-3 rounded-lg">Eclectic Contemporary</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Theme Modal -->
    <div id="themeModal" class="modal">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 id="themeModalTitle" class="text-2xl font-semibold text-gray-800">Add Theme</h3>
                <button onclick="closeModal('themeModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <form id="themeForm" method="POST">
                <input type="hidden" name="action" id="themeFormAction" value="add_theme" />
                <input type="hidden" name="id" id="themeFormId" value="" />
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Theme Name *</label>
                        <input type="text" name="name" id="themeFormName" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500" placeholder="e.g., Contemporary" />
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="description" id="themeFormDescription" rows="3" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500" placeholder="Brief description..."></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Display Order *</label>
                        <input type="number" name="display_order" id="themeFormOrder" required value="0" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500" />
                        <p class="text-xs text-gray-500 mt-1">Lower numbers appear first</p>
                    </div>
                    
                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white py-3 rounded-lg font-medium transition-colors">
                        <i class="ri-save-line mr-2"></i><span id="themeSubmitBtnText">Add Theme</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add/Edit Sub-Theme Modal -->
    <div id="subthemeModal" class="modal">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 id="subthemeModalTitle" class="text-2xl font-semibold text-gray-800">Add Sub-Theme</h3>
                <button onclick="closeModal('subthemeModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="ri-close-line text-2xl"></i>
                </button>
            </div>
            <form id="subthemeForm" method="POST">
                <input type="hidden" name="action" id="subthemeFormAction" value="add_subtheme" />
                <input type="hidden" name="id" id="subthemeFormId" value="" />
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Parent Theme *</label>
                        <select name="theme_id" id="subthemeFormThemeId" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500">
                            <option value="">Select theme...</option>
                            <?php $themes->data_seek(0); while ($theme = $themes->fetch_assoc()): ?>
                                <option value="<?php echo $theme['id']; ?>"><?php echo htmlspecialchars($theme['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Sub-Theme Name *</label>
                        <input type="text" name="name" id="subthemeFormName" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500" placeholder="e.g., Minimalist Contemporary" />
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="description" id="subthemeFormDescription" rows="3" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500" placeholder="Brief description..."></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Display Order *</label>
                        <input type="number" name="display_order" id="subthemeFormOrder" required value="0" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500" />
                        <p class="text-xs text-gray-500 mt-1">Lower numbers appear first</p>
                    </div>
                    
                    <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white py-3 rounded-lg font-medium transition-colors">
                        <i class="ri-save-line mr-2"></i><span id="subthemeSubmitBtnText">Add Sub-Theme</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tab) {
            if (tab === 'themes') {
                document.getElementById('themesContent').classList.remove('hidden');
                document.getElementById('subthemesContent').classList.add('hidden');
                document.getElementById('themesTab').classList.add('active');
                document.getElementById('subthemesTab').classList.remove('active');
                document.getElementById('addThemeBtn').classList.remove('hidden');
                document.getElementById('addSubthemeBtn').classList.add('hidden');
            } else {
                document.getElementById('themesContent').classList.add('hidden');
                document.getElementById('subthemesContent').classList.remove('hidden');
                document.getElementById('themesTab').classList.remove('active');
                document.getElementById('subthemesTab').classList.add('active');
                document.getElementById('addThemeBtn').classList.add('hidden');
                document.getElementById('addSubthemeBtn').classList.remove('hidden');
            }
        }

        // Theme modals
        function openAddThemeModal() {
            document.getElementById('themeModalTitle').textContent = 'Add Theme';
            document.getElementById('themeFormAction').value = 'add_theme';
            document.getElementById('themeSubmitBtnText').textContent = 'Add Theme';
            document.getElementById('themeForm').reset();
            document.getElementById('themeModal').classList.add('active');
        }

        function editTheme(theme) {
            document.getElementById('themeModalTitle').textContent = 'Edit Theme';
            document.getElementById('themeFormAction').value = 'edit_theme';
            document.getElementById('themeFormId').value = theme.id;
            document.getElementById('themeFormName').value = theme.name;
            document.getElementById('themeFormDescription').value = theme.description;
            document.getElementById('themeFormOrder').value = theme.display_order;
            document.getElementById('themeSubmitBtnText').textContent = 'Update Theme';
            document.getElementById('themeModal').classList.add('active');
        }

        // Sub-theme modals
        function openAddSubthemeModal() {
            document.getElementById('subthemeModalTitle').textContent = 'Add Sub-Theme';
            document.getElementById('subthemeFormAction').value = 'add_subtheme';
            document.getElementById('subthemeSubmitBtnText').textContent = 'Add Sub-Theme';
            document.getElementById('subthemeForm').reset();
            document.getElementById('subthemeModal').classList.add('active');
        }

        function editSubtheme(subtheme) {
            document.getElementById('subthemeModalTitle').textContent = 'Edit Sub-Theme';
            document.getElementById('subthemeFormAction').value = 'edit_subtheme';
            document.getElementById('subthemeFormId').value = subtheme.id;
            document.getElementById('subthemeFormThemeId').value = subtheme.theme_id;
            document.getElementById('subthemeFormName').value = subtheme.name;
            document.getElementById('subthemeFormDescription').value = subtheme.description;
            document.getElementById('subthemeFormOrder').value = subtheme.display_order;
            document.getElementById('subthemeSubmitBtnText').textContent = 'Update Sub-Theme';
            document.getElementById('subthemeModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</html>

<?php $conn->close(); ?>