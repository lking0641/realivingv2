<?php
//concept_manage_header.php
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
    $current = $conn->query("SELECT * FROM concept_header LIMIT 1")->fetch_assoc();
    $header_image = $current['header_image'];
    
    // Handle image upload
    if (isset($_FILES['header_image']) && $_FILES['header_image']['error'] == 0) {
        $target_dir = "../../realiving_user/images/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $new_filename = 'concept_header_' . time() . '.webp';
        $target_file = $target_dir . $new_filename;
        
        // Convert to WebP
        if (convertToWebP($_FILES['header_image']['tmp_name'], $target_file, 90)) {
            $header_image = 'images/' . $new_filename;
            
            // Delete old image if exists and not default
            if ($current['header_image'] != 'images/background-image.jpg') {
                $old_image = "../../realiving_user/" . $current['header_image'];
                if (file_exists($old_image)) {
                    unlink($old_image);
                }
            }
        }
    }
    
    // Update database
    $stmt = $conn->prepare("UPDATE concept_header SET header_image=?, title=?, subtitle=? WHERE id=1");
    $stmt->bind_param("sss", $header_image, $title, $subtitle);
    $stmt->execute();
    
    $success_message = "Header updated successfully!";
}

// Get current settings
$header = $conn->query("SELECT * FROM concept_header LIMIT 1")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Concept Header</title>
    <link rel="stylesheet" href="../css/admin-style.css">
    <style>
        .container { max-width: 900px; margin: 0 auto; padding: 20px; }
        .header-section { margin-bottom: 30px; }
        h1 { color: #3b1f0f; margin-bottom: 10px; }
        .success-message { background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #c3e6cb; }
        .form-card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 25px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        .form-group input[type="text"], .form-group textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        .form-group textarea { min-height: 100px; resize: vertical; font-family: inherit; }
        .form-group input[type="file"] { padding: 10px; }
        .current-image-section { margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 4px; }
        .current-image-section h4 { margin-bottom: 10px; color: #666; }
        .current-image { max-width: 100%; height: 200px; object-fit: cover; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .btn { padding: 12px 30px; background: #3b1f0f; color: white; border: none; cursor: pointer; border-radius: 4px; font-size: 16px; font-weight: 500; }
        .btn:hover { background: #8a5a44; }
        .help-text { font-size: 13px; color: #666; margin-top: 5px; }
        .preview-section { margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; }
        .preview-section h3 { margin-bottom: 15px; color: #3b1f0f; }
        .preview-box { background: white; padding: 20px; border-radius: 4px; border: 1px solid #ddd; }
        .preview-title { font-size: 24px; font-weight: bold; color: #3b1f0f; margin-bottom: 10px; }
        .preview-subtitle { font-size: 14px; color: #666; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-section">
            <h1>Manage Concept Designs Header</h1>
            <p>Update the header image, title, and subtitle for the Concept Designs page</p>
        </div>

        <?php if (isset($success_message)): ?>
        <div class="success-message">
            ✓ <?php echo $success_message; ?>
        </div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Page Title</label>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($header['title']); ?>" required>
                    <p class="help-text">The main heading displayed on the page</p>
                </div>

                <div class="form-group">
                    <label>Page Subtitle</label>
                    <textarea name="subtitle" required><?php echo htmlspecialchars($header['subtitle']); ?></textarea>
                    <p class="help-text">The descriptive text below the title</p>
                </div>

                <div class="form-group">
                    <label>Header Background Image</label>
                    <input type="file" name="header_image" accept="image/*">
                    <p class="help-text">Upload a new header background image (JPG/PNG will be converted to WebP). Recommended size: 1920x400px</p>
                    
                    <div class="current-image-section">
                        <h4>Current Header Image:</h4>
                        <img src="../../realiving_user/<?php echo htmlspecialchars($header['header_image']); ?>" class="current-image" alt="Current Header">
                    </div>
                </div>

                <button type="submit" class="btn">Save Changes</button>
            </form>
        </div>

        <div class="preview-section">
            <h3>Current Preview:</h3>
            <div class="preview-box">
                <div class="preview-title"><?php echo htmlspecialchars($header['title']); ?></div>
                <div class="preview-subtitle"><?php echo htmlspecialchars($header['subtitle']); ?></div>
            </div>
        </div>
    </div>
</body>
</html>