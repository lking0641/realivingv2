<?php
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

// Function to convert image to WebP
function convertToWebP($source, $destination, $quality = 90) {
    $info = getimagesize($source);
    
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
    } elseif ($info['mime'] == 'image/gif') {
        $image = imagecreatefromgif($source);
    } else {
        return false;
    }
    
    imagewebp($image, $destination, $quality);
    imagedestroy($image);
    
    return true;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = $_POST['title'];
    $subtitle = $_POST['subtitle'];
    
    // Get current settings
    $current = $conn->query("SELECT * FROM news_header LIMIT 1")->fetch_assoc();
    $header_image = $current['header_image'];
    
    // Handle image upload
    if (isset($_FILES['header_image']) && $_FILES['header_image']['error'] == 0) {
        $target_dir = "../../realiving_user/images/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $new_filename = 'news_header_' . time() . '.webp';
        $target_file = $target_dir . $new_filename;
        
        if (convertToWebP($_FILES['header_image']['tmp_name'], $target_file, 90)) {
            $header_image = 'images/' . $new_filename;
            
            if ($current['header_image'] != 'images/background-image.jpg') {
                $old_image = "../../realiving_user/" . $current['header_image'];
                if (file_exists($old_image)) {
                    unlink($old_image);
                }
            }
        }
    }
    
    $stmt = $conn->prepare("UPDATE news_header SET header_image=?, title=?, subtitle=? WHERE id=1");
    $stmt->bind_param("sss", $header_image, $title, $subtitle);
    $stmt->execute();
    
    $success_message = "Header updated successfully!";
}

$header = $conn->query("SELECT * FROM news_header LIMIT 1")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage News Header</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { color: #3b1f0f; margin-bottom: 20px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .form-card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: bold; }
        .form-group input[type="text"], .form-group textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .current-image { max-width: 100%; height: 200px; object-fit: cover; border-radius: 4px; margin-top: 10px; }
        .btn { padding: 12px 30px; background: #3b1f0f; color: white; border: none; cursor: pointer; border-radius: 4px; }
        .btn:hover { background: #8a5a44; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Manage News Header</h1>
        
        <?php if (isset($success_message)): ?>
        <div class="success">✓ <?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Page Title</label>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($header['title']); ?>" required>
                </div>

                <div class="form-group">
                    <label>Page Subtitle</label>
                    <textarea name="subtitle" required><?php echo htmlspecialchars($header['subtitle']); ?></textarea>
                </div>

                <div class="form-group">
                    <label>Header Background Image</label>
                    <input type="file" name="header_image" accept="image/*">
                    <p style="font-size: 13px; color: #666; margin-top: 5px;">Recommended: 1920x400px</p>
                    <img src="../../realiving_user/<?php echo htmlspecialchars($header['header_image']); ?>" class="current-image" alt="Current Header">
                </div>

                <button type="submit" class="btn">Save Changes</button>
            </form>
        </div>
    </div>
</body>
</html>