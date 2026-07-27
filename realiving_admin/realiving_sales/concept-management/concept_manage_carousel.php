<?php
include $includes ['mainbody'];

// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales']);

// Function to convert image to WebP
function convertToWebP($source, $destination, $quality = 90) {
    $info = @getimagesize($source);

    if ($info === false) {
        error_log("convertToWebP: not a valid image - $source");
        return false;
    }

    switch ($info['mime']) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source);
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($source);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($source);
            break;
        default:
            error_log("convertToWebP: unsupported mime type - " . $info['mime']);
            return false;
    }

    if (!$image) {
        error_log("convertToWebP: imagecreate failed for $source");
        return false;
    }

    $success = imagewebp($image, $destination, $quality);
    imagedestroy($image);

    if (!$success || !file_exists($destination)) {
        error_log("convertToWebP: imagewebp failed to write $destination");
        return false;
    }

    return true;
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $result = $conn->query("SELECT image_path FROM concept_carousel WHERE id = $id");
    if ($row = $result->fetch_assoc()) {
        $image_file = ROOT_PATH . "realiving_user/" . $row['image_path'];
        if (file_exists($image_file)) {
            unlink($image_file);
        }
    }
    $conn->query("DELETE FROM concept_carousel WHERE id = $id");
    header("Location: " . BASE_URL . "concept-manage-carousel");
    exit();
}

// Handle add
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['image'])) {
    if ($_FILES['image']['error'] == 0) {
        $target_dir = ROOT_PATH . "realiving_user/images/carousel/";
        
        // Create directory if not exists
        if (!file_exists($target_dir)) {
            if (!mkdir($target_dir, 0777, true) && !is_dir($target_dir)) {
                die("Upload failed: could not create folder $target_dir. Check folder permissions.");
            }
        }

        if (!is_writable($target_dir)) {
            die("Upload failed: folder $target_dir is not writable. Check folder permissions (chmod 755/777).");
        }
        
        $new_filename = 'carousel_' . time() . '_' . rand(1000, 9999) . '.webp';
        $target_file = $target_dir . $new_filename;
        
        // Convert to WebP
        if (convertToWebP($_FILES['image']['tmp_name'], $target_file, 90)) {
            $image_path = 'images/carousel/' . $new_filename;
            $display_order = intval($_POST['display_order']);
            
            $stmt = $conn->prepare("INSERT INTO concept_carousel (image_path, display_order) VALUES (?, ?)");
            $stmt->bind_param("si", $image_path, $display_order);
            $stmt->execute();
        } else {
            die("Upload failed: could not convert image to WebP. Check the PHP error log for details.");
        }
    } else {
        die("Upload failed: file upload error code " . $_FILES['image']['error']);
    }
    header("Location: " . BASE_URL . "concept-manage-carousel");
    exit();
}

// Handle reorder
if (isset($_POST['update_order'])) {
    foreach ($_POST['order'] as $id => $order) {
        $id = intval($id);
        $order = intval($order);
        $conn->query("UPDATE concept_carousel SET display_order = $order WHERE id = $id");
    }
    header("Location: " . BASE_URL . "concept-manage-carousel");
    exit();
}

// Get all carousel images
$carousel_images = $conn->query("SELECT * FROM concept_carousel ORDER BY display_order ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Carousel</title>
    <link rel="stylesheet" href="../css/admin-style.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header-section { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .btn { padding: 10px 20px; background: #3b1f0f; color: white; border: none; cursor: pointer; text-decoration: none; display: inline-block; border-radius: 4px; }
        .btn:hover { background: #8a5a44; }
        .btn-success { background: #28a745; }
        .btn-danger { background: #dc3545; }
        .carousel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; margin-top: 20px; }
        .carousel-item { background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .carousel-image { width: 100%; height: 200px; object-fit: cover; border-radius: 4px; margin-bottom: 10px; }
        .item-actions { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .order-input { width: 60px; padding: 5px; border: 1px solid #ddd; border-radius: 4px; }
        
        /* Modal */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: #fefefe; margin: 10% auto; padding: 30px; border: 1px solid #888; width: 90%; max-width: 500px; border-radius: 8px; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: #000; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-section">
            <h1>Manage Carousel Images</h1>
            <button class="btn btn-success" onclick="openModal()">+ Add New Image</button>
        </div>

        <form method="POST">
            <button type="submit" name="update_order" class="btn">Save Order</button>
            
            <div class="carousel-grid">
                <?php while ($image = $carousel_images->fetch_assoc()): ?>
                <div class="carousel-item">
                    <img src="<?php echo CLIENT_ASSET; ?>/<?php echo htmlspecialchars(ltrim($image['image_path'], './')); ?>" class="carousel-image" alt="">
                    <div class="item-actions">
                        <label>
                            Order: <input type="number" name="order[<?php echo $image['id']; ?>]" value="<?php echo $image['display_order']; ?>" class="order-input">
                        </label>
                        <a href="?delete=<?php echo $image['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </form>
    </div>

    <!-- Modal -->
    <div id="imageModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Add New Carousel Image</h2>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Image (JPG/PNG will be converted to WebP)</label>
                    <input type="file" name="image" accept="image/*" required>
                </div>
                
                <div class="form-group">
                    <label>Display Order</label>
                    <input type="number" name="display_order" value="1" required>
                </div>
                
                <button type="submit" class="btn btn-success">Upload</button>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('imageModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('imageModal').style.display = 'none';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('imageModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>