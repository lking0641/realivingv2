<?php
//concept_manage_styles.php
session_start();
include "../../connection/connection.php";
include '../design/mainbody.php';
include '../checkrole/checkrole.php';

// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales']);

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM concept_styles WHERE id = $id");
    header("Location: concept_manage_styles.php");
    exit();
}

// Handle category add
if (isset($_POST['add_category'])) {
    $category_name = trim($_POST['category_name']);
    $category_order = intval($_POST['category_order']);
    
    if (!empty($category_name)) {
        $stmt = $conn->prepare("INSERT INTO concept_categories (name, display_order) VALUES (?, ?)");
        $stmt->bind_param("si", $category_name, $category_order);
        $stmt->execute();
        header("Location: concept_manage_styles.php?category_added=1");
        exit();
    }
}

// Handle category delete
if (isset($_GET['delete_category'])) {
    $cat_id = intval($_GET['delete_category']);
    $conn->query("DELETE FROM concept_categories WHERE id = $cat_id");
    header("Location: concept_manage_styles.php");
    exit();
}

// Get all categories
$categories = $conn->query("SELECT * FROM concept_categories ORDER BY display_order ASC, name ASC");

// Handle add/edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['add_category'])) {
    $id = $_POST['id'] ?? null;
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'];
    $iframe_url = $_POST['iframe_url'];
    $layout_type = $_POST['layout_type'];
    $display_order = intval($_POST['display_order']);
    $is_reversed = isset($_POST['is_reversed']) ? 1 : 0;
    
    $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    
    if ($id) {
        // Update
        $stmt = $conn->prepare("UPDATE concept_styles SET title=?, description=?, iframe_url=?, layout_type=?, display_order=?, is_reversed=?, category_id=? WHERE id=?");
        $stmt->bind_param("ssssiiii", $title, $description, $iframe_url, $layout_type, $display_order, $is_reversed, $category_id, $id);
    } else {
        // Insert
        $stmt = $conn->prepare("INSERT INTO concept_styles (title, description, iframe_url, layout_type, display_order, is_reversed, category_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssiii", $title, $description, $iframe_url, $layout_type, $display_order, $is_reversed, $category_id);
    }
    
    $stmt->execute();
    header("Location: concept_manage_styles.php");
    exit();
}

// Get all styles with category
$styles = $conn->query("SELECT cs.*, cc.name as category_name 
                        FROM concept_styles cs 
                        LEFT JOIN concept_categories cc ON cs.category_id = cc.id 
                        ORDER BY cs.display_order ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Concept Styles</title>
    <link rel="stylesheet" href="../css/admin-style.css">
    <style>
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .header-section { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .btn { padding: 10px 20px; background: #3b1f0f; color: white; border: none; cursor: pointer; text-decoration: none; display: inline-block; border-radius: 4px; }
        .btn:hover { background: #8a5a44; }
        .btn-success { background: #28a745; }
        .btn-danger { background: #dc3545; }
        table { width: 100%; border-collapse: collapse; background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #3b1f0f; color: white; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; }
        .badge-full { background: #007bff; color: white; }
        .badge-two { background: #6c757d; color: white; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        .actions { display: flex; gap: 10px; }
        
        /* Modal Styles */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: #fefefe; margin: 5% auto; padding: 30px; border: 1px solid #888; width: 90%; max-width: 600px; border-radius: 8px; max-height: 90vh; overflow-y: auto; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: #000; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        .form-group textarea { min-height: 100px; resize: vertical; }
        .checkbox-group { display: flex; align-items: center; gap: 10px; }
        .checkbox-group input { width: auto; }
        .current-image { max-width: 200px; margin-top: 10px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-section">
    <h1>Manage Concept Styles</h1>
    <div style="display: flex; gap: 10px;">
        <button class="btn" onclick="openCategoryModal()">Manage Categories</button>
        <button class="btn btn-success" onclick="openModal()">+ Add New Style</button>
    </div>
</div>

<?php if (isset($_GET['category_added'])): ?>
<div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
    ✓ Category added successfully!
</div>
<?php endif; ?>

        <table>
            <thead>
    <tr>
        <th>Order</th>
        <th>Iframe URL</th>
        <th>Title</th>
        <th>Category</th>
        <th>Description</th>
        <th>Layout</th>
        <th>Reversed</th>
        <th>Actions</th>
    </tr>
</thead>
            <tbody>
                <?php while ($style = $styles->fetch_assoc()): ?>
                <tr>
    <td><?php echo $style['display_order']; ?></td>
    <td style="font-size: 11px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
    <?php echo htmlspecialchars($style['iframe_url']); ?>
</td>
    <td><?php echo htmlspecialchars($style['title']); ?></td>
    <td><?php echo $style['category_name'] ? htmlspecialchars($style['category_name']) : '<em>No category</em>'; ?></td>
    <td><?php echo substr(htmlspecialchars($style['description']), 0, 80) . '...'; ?></td>
    <td><span class="badge <?php echo $style['layout_type'] == 'full' ? 'badge-full' : 'badge-two'; ?>"><?php echo $style['layout_type']; ?></span></td>
    <td><?php echo $style['is_reversed'] ? 'Yes' : 'No'; ?></td>
    <td class="actions">
        <button class="btn" onclick='editStyle(<?php echo json_encode($style); ?>)'>Edit</button>
        <a href="?delete=<?php echo $style['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
    </td>
</tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <!-- Modal -->
    <div id="styleModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle">Add New Style</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id" id="styleId">
                
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" id="title">
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="description" required></textarea>
                </div>

                <div class="form-group">
    <label>Category</label>
    <select name="category_id" id="category_id">
        <option value="">-- No Category --</option>
        <?php 
        $categories_list = $conn->query("SELECT * FROM concept_categories ORDER BY display_order ASC, name ASC");
        while ($cat = $categories_list->fetch_assoc()): 
        ?>
        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
        <?php endwhile; ?>
    </select>
    <small>Select a category or <a href="#" onclick="event.preventDefault(); openCategoryModal();">add a new one</a></small>
</div>
                
                <div class="form-group">
    <label>Iframe URL</label>
    <input type="url" name="iframe_url" id="iframe_url" placeholder="https://example.com/embed/..." required>
    <small>Enter the full URL for the iframe embed</small>
</div>
                
                <div class="form-group">
                    <label>Layout Type</label>
                    <select name="layout_type" id="layout_type" required>
                        <option value="full">Full Width</option>
                        <option value="two-column">Two Column</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Display Order</label>
                    <input type="number" name="display_order" id="display_order" required>
                </div>
                
                <div class="form-group checkbox-group">
                    <input type="checkbox" name="is_reversed" id="is_reversed">
                    <label>Reversed Layout (for full width only)</label>
                </div>
                
                <button type="submit" class="btn btn-success">Save</button>
            </form>
        </div>
    </div>

    <!-- Category Management Modal -->
<div id="categoryModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeCategoryModal()">&times;</span>
        <h2>Manage Categories</h2>
        
        <!-- Add Category Form -->
        <form method="POST" style="margin-bottom: 30px; padding: 20px; background: #f8f9fa; border-radius: 4px;">
            <h3 style="margin-bottom: 15px;">Add New Category</h3>
            <div class="form-group">
                <label>Category Name</label>
                <input type="text" name="category_name" required>
            </div>
            <div class="form-group">
                <label>Display Order</label>
                <input type="number" name="category_order" value="0" required>
            </div>
            <button type="submit" name="add_category" class="btn btn-success">Add Category</button>
        </form>
        
        <!-- Existing Categories List -->
        <h3>Existing Categories</h3>
        <table style="width: 100%; margin-top: 15px;">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Name</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $cats = $conn->query("SELECT * FROM concept_categories ORDER BY display_order ASC, name ASC");
                if ($cats->num_rows > 0):
                    while ($cat = $cats->fetch_assoc()): 
                ?>
                <tr>
                    <td><?php echo $cat['display_order']; ?></td>
                    <td><?php echo htmlspecialchars($cat['name']); ?></td>
                    <td>
                        <a href="?delete_category=<?php echo $cat['id']; ?>" 
                           class="btn btn-danger btn-sm" 
                           onclick="return confirm('Delete this category? Styles using it will have no category.')">
                           Delete
                        </a>
                    </td>
                </tr>
                <?php 
                    endwhile;
                else:
                ?>
                <tr>
                    <td colspan="3" style="text-align: center; color: #999; padding: 20px;">
                        No categories yet. Add one above!
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

    <script>
        function openModal() {
    document.getElementById('styleModal').style.display = 'block';
    document.getElementById('modalTitle').textContent = 'Add New Style';
    document.getElementById('styleId').value = '';
    document.getElementById('title').value = '';
    document.getElementById('description').value = '';
    document.getElementById('category_id').value = '';
    document.getElementById('iframe_url').value = '';
    document.getElementById('layout_type').value = 'full';
    document.getElementById('display_order').value = '';
    document.getElementById('is_reversed').checked = false;
}

        function editStyle(style) {
    document.getElementById('styleModal').style.display = 'block';
    document.getElementById('modalTitle').textContent = 'Edit Style';
    document.getElementById('styleId').value = style.id;
    document.getElementById('title').value = style.title;
    document.getElementById('description').value = style.description;
    document.getElementById('category_id').value = style.category_id || '';
    document.getElementById('iframe_url').value = style.iframe_url;
    document.getElementById('layout_type').value = style.layout_type;
    document.getElementById('display_order').value = style.display_order;
    document.getElementById('is_reversed').checked = style.is_reversed == 1;
}

        function closeModal() {
            document.getElementById('styleModal').style.display = 'none';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('styleModal');
            if (event.target == modal) {
                closeModal();
            }
        }

        function openCategoryModal() {
    document.getElementById('categoryModal').style.display = 'block';
}

function closeCategoryModal() {
    document.getElementById('categoryModal').style.display = 'none';
}

// Update window click handler to handle both modals
window.onclick = function(event) {
    const styleModal = document.getElementById('styleModal');
    const categoryModal = document.getElementById('categoryModal');
    
    if (event.target == styleModal) {
        closeModal();
    }
    if (event.target == categoryModal) {
        closeCategoryModal();
    }
}
    </script>
</body>
</html>