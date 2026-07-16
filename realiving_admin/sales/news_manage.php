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

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $result = $conn->query("SELECT image FROM news WHERE id = $id");
    if ($row = $result->fetch_assoc()) {
        $image_file = "../../realiving_user/" . $row['image'];
        if (file_exists($image_file)) {
            unlink($image_file);
        }
    }
    $conn->query("DELETE FROM news WHERE id = $id");
    header("Location: news_manage.php");
    exit();
}

// Handle add/edit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'] ?? null;
    $title = $_POST['title'];
    $category = $_POST['category'];
    $description = $_POST['description'];
    $content = $_POST['content'];
    $keywords = $_POST['keywords'];
    $author = $_POST['author'];
    $featured = isset($_POST['featured']) ? 1 : 0;
    $status = $_POST['status'];
    
    // Handle image upload
    $image_path = $_POST['existing_image'] ?? '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $target_dir = "../../realiving_user/images/news/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $new_filename = 'news_' . time() . '_' . rand(1000, 9999) . '.webp';
        $target_file = $target_dir . $new_filename;
        
        if (convertToWebP($_FILES['image']['tmp_name'], $target_file, 90)) {
            $image_path = 'images/news/' . $new_filename;
            
            if ($id && !empty($_POST['existing_image'])) {
                $old_image = "../../realiving_user/" . $_POST['existing_image'];
                if (file_exists($old_image)) {
                    unlink($old_image);
                }
            }
        }
    }
    
    // Handle multiple sub-images upload
    $sub_images = json_decode($_POST['existing_sub_images'] ?? '[]', true) ?: [];

    if (!empty($_FILES['sub_images']['name'][0])) {
        $target_dir = "../../realiving_user/images/news/";
        foreach ($_FILES['sub_images']['tmp_name'] as $index => $tmp_name) {
            if ($_FILES['sub_images']['error'][$index] == 0) {
                $sub_filename = 'news_sub_' . time() . '_' . rand(1000, 9999) . '_' . $index . '.webp';
                $sub_target = $target_dir . $sub_filename;
                if (convertToWebP($tmp_name, $sub_target, 90)) {
                    $sub_images[] = 'images/news/' . $sub_filename;
                }
            }
        }
    }
    $sub_images_json = json_encode($sub_images);

    if ($id) {
        $stmt = $conn->prepare("UPDATE news SET title=?, category=?, description=?, content=?, image=?, keywords=?, author=?, featured=?, status=?, sub_images=? WHERE id=?");
        $stmt->bind_param("ssssssssssi", $title, $category, $description, $content, $image_path, $keywords, $author, $featured, $status, $sub_images_json, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO news (title, category, description, content, image, keywords, author, featured, status, sub_images) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssss", $title, $category, $description, $content, $image_path, $keywords, $author, $featured, $status, $sub_images_json);
    }
    
    $stmt->execute();
    header("Location: news_manage.php");
    exit();
}

// Get all news
$news_list = $conn->query("SELECT * FROM news ORDER BY date_uploaded DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage News</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header-section { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .btn { padding: 10px 20px; background: #3b1f0f; color: white; border: none; cursor: pointer; text-decoration: none; display: inline-block; border-radius: 4px; }
        .btn:hover { background: #8a5a44; }
        .btn-success { background: #28a745; }
        .btn-danger { background: #dc3545; }
        table { width: 100%; border-collapse: collapse; background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #3b1f0f; color: white; }
        .news-image { width: 100px; height: 60px; object-fit: cover; border-radius: 4px; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; color: white; }
        .badge-featured { background: #ffc107; color: #000; }
        .badge-published { background: #28a745; }
        .badge-draft { background: #6c757d; }
        .actions { display: flex; gap: 10px; }
        
        /* Modal */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: #fefefe; margin: 2% auto; padding: 30px; border: 1px solid #888; width: 90%; max-width: 800px; border-radius: 8px; max-height: 90vh; overflow-y: auto; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: #000; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        .form-group textarea { min-height: 100px; resize: vertical; }
        .checkbox-group { display: flex; align-items: center; gap: 10px; }
        .checkbox-group input { width: auto; }
        .current-image { max-width: 200px; margin-top: 10px; border-radius: 4px; }
        .sub-images-grid { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
        .sub-image-item { position: relative; width: 100px; }
        .sub-image-item img { width: 100px; height: 70px; object-fit: cover; border-radius: 4px; }
        .sub-image-item .remove-sub { position: absolute; top: -6px; right: -6px; background: red; color: white; border: none; border-radius: 50%; width: 20px; height: 20px; cursor: pointer; font-size: 12px; line-height: 20px; text-align: center; padding: 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-section">
            <h1>Manage News Articles</h1>
            <button class="btn btn-success" onclick="openModal()">+ Add New Article</button>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Title</th>
                    <th>Category</th>
                    <th>Author</th>
                    <th>Date</th>
                    <th>Views</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($news = $news_list->fetch_assoc()): ?>
                <tr>
                    <td><img src="../../realiving_user/<?php echo htmlspecialchars($news['image']); ?>" class="news-image" alt=""></td>
                    <td>
                        <?php echo htmlspecialchars($news['title']); ?>
                        <?php if ($news['featured']): ?>
                            <span class="badge badge-featured">Featured</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($news['category']); ?></td>
                    <td><?php echo htmlspecialchars($news['author']); ?></td>
                    <td><?php echo date('M d, Y', strtotime($news['date_uploaded'])); ?></td>
                    <td><?php echo $news['views']; ?></td>
                    <td><span class="badge badge-<?php echo $news['status']; ?>"><?php echo ucfirst($news['status']); ?></span></td>
                    <td class="actions">
                        <button class="btn" onclick='editNews(<?php echo json_encode($news); ?>)'>Edit</button>
                        <a href="?delete=<?php echo $news['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <!-- Modal -->
    <div id="newsModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle">Add New Article</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id" id="newsId">
                <input type="hidden" name="existing_image" id="existingImage">
                
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" name="title" id="title" required>
                </div>
                
                <div class="form-group">
                    <label>Category *</label>
                    <input type="text" name="category" id="category" placeholder="e.g., Design Tips, Company News, Industry Trends" required>
                </div>
                
                <div class="form-group">
                    <label>Short Description * (for preview)</label>
                    <textarea name="description" id="description" rows="3" required></textarea>
                </div>
                
                <div class="form-group">
                    <label>Full Content *</label>
                    <textarea name="content" id="content" rows="10" required></textarea>
                </div>
                
                <div class="form-group">
                    <label>Featured Image *</label>
                    <input type="file" name="image" id="image" accept="image/*">
                    <small>JPG/PNG will be converted to WebP. Recommended: 800x500px</small>
                    <img id="currentImage" class="current-image" style="display:none;">
                </div>

                <div class="form-group">
                    <label>Sub Images (unlimited)</label>
                    <input type="file" name="sub_images[]" id="subImages" accept="image/*" multiple>
                    <small>You can select multiple images at once. JPG/PNG will be converted to WebP.</small>
                    <input type="hidden" name="existing_sub_images" id="existingSubImages" value="[]">
                    <div class="sub-images-grid" id="subImagesPreview"></div>
                </div>
                
                <div class="form-group">
                    <label>Keywords (comma-separated)</label>
                    <input type="text" name="keywords" id="keywords" placeholder="e.g., interior design, cabinets, modern">
                </div>
                
                <div class="form-group">
                    <label>Author</label>
                    <input type="text" name="author" id="author" value="Admin">
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="status">
                        <option value="published">Published</option>
                        <option value="draft">Draft</option>
                    </select>
                </div>
                
                <div class="form-group checkbox-group">
                    <input type="checkbox" name="featured" id="featured">
                    <label>Mark as Featured</label>
                </div>
                
                <button type="submit" class="btn btn-success">Save Article</button>
            </form>
        </div>
    </div>

    <script>
        let subImagesToKeep = [];

        function openModal() {
            document.getElementById('newsModal').style.display = 'block';
            document.getElementById('modalTitle').textContent = 'Add New Article';
            document.getElementById('newsId').value = '';
            document.getElementById('title').value = '';
            document.getElementById('category').value = '';
            document.getElementById('description').value = '';
            document.getElementById('content').value = '';
            document.getElementById('keywords').value = '';
            document.getElementById('author').value = 'Admin';
            document.getElementById('status').value = 'published';
            document.getElementById('featured').checked = false;
            document.getElementById('existingImage').value = '';
            document.getElementById('currentImage').style.display = 'none';
            subImagesToKeep = [];
            document.getElementById('existingSubImages').value = '[]';
            document.getElementById('subImagesPreview').innerHTML = '';
        }

        function editNews(news) {
            document.getElementById('newsModal').style.display = 'block';
            document.getElementById('modalTitle').textContent = 'Edit Article';
            document.getElementById('newsId').value = news.id;
            document.getElementById('title').value = news.title;
            document.getElementById('category').value = news.category;
            document.getElementById('description').value = news.description;
            document.getElementById('content').value = news.content;
            document.getElementById('keywords').value = news.keywords;
            document.getElementById('author').value = news.author;
            document.getElementById('status').value = news.status;
            document.getElementById('featured').checked = news.featured == 1;
            document.getElementById('existingImage').value = news.image;
            
            const currentImg = document.getElementById('currentImage');
            currentImg.src = '../../realiving_user/' + news.image;
            currentImg.style.display = 'block';

            // Load existing sub images
            subImagesToKeep = news.sub_images ? JSON.parse(news.sub_images) : [];
            document.getElementById('existingSubImages').value = JSON.stringify(subImagesToKeep);
            renderSubImagePreviews();
        }

        function closeModal() {
            document.getElementById('newsModal').style.display = 'none';
        }

        function renderSubImagePreviews() {
            const preview = document.getElementById('subImagesPreview');
            preview.innerHTML = '';
            subImagesToKeep.forEach((path, index) => {
                const div = document.createElement('div');
                div.className = 'sub-image-item';
                div.innerHTML = `
                    <img src="../../realiving_user/${path}" alt="sub">
                    <button type="button" class="remove-sub" onclick="removeSubImage(${index})">×</button>
                `;
                preview.appendChild(div);
            });
        }

        function removeSubImage(index) {
            subImagesToKeep.splice(index, 1);
            document.getElementById('existingSubImages').value = JSON.stringify(subImagesToKeep);
            renderSubImagePreviews();
        }

        document.getElementById('subImages').addEventListener('change', function() {
            const preview = document.getElementById('subImagesPreview');
            const existingCount = subImagesToKeep.length;
            // Remove previously added new-file previews
            const newPreviews = preview.querySelectorAll('.new-preview');
            newPreviews.forEach(el => el.remove());

            Array.from(this.files).forEach((file, i) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'sub-image-item new-preview';
                    div.innerHTML = `<img src="${e.target.result}" alt="new sub"><small style="font-size:10px;display:block;text-align:center">New</small>`;
                    preview.appendChild(div);
                };
                reader.readAsDataURL(file);
            });
        });

        window.onclick = function(event) {
            const modal = document.getElementById('newsModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>