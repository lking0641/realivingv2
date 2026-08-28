<?php
//projects_view.php
include $includes ['mainbody'];

if (!isset($_SESSION['admin_id'])) {
    header("Location: " . BASE_URL . "login");
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
        
        $target_dir = ROOT_PATH . "realiving_user/images/projects/";
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
                    $file_path = ROOT_PATH . "realiving_user/" . $project[$key];
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
        
        $target_dir = ROOT_PATH . "realiving_user/images/projects/";
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
                    $old_file = ROOT_PATH . "realiving_user/" . $existing[$field];
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

// Fetch projects (paginated)
$where_clause = "";
if ($category_filter !== 'all') {
    $where_clause = "WHERE category = '" . $conn->real_escape_string($category_filter) . "'";
}

$per_page = 9; // projects per page
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

$total_count = $conn->query("SELECT COUNT(*) as count FROM project $where_clause")->fetch_assoc()['count'];
$total_pages = max(1, ceil($total_count / $per_page));
$current_page = min($current_page, $total_pages); // clamp to last page if out of range

$offset = ($current_page - 1) * $per_page;

$projects = $conn->query("SELECT * FROM project $where_clause ORDER BY id DESC LIMIT $per_page OFFSET $offset");
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Projects Management - RealLiving</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<? BASE_URL ?>logo/favicon.ico">
  <!-- Font Awesome CDN -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
    crossorigin="anonymous" referrerpolicy="no-referrer" />
  <!-- Google Fonts: Inter -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --adm-bg: #F5F5F5;
      --adm-surface: #FFFFFF;
      --adm-ink: #0B0B0B;
      --adm-soft: #6B6B6B;
      --adm-muted: #9A9A9A;
      --adm-line: #E2E2E2;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--adm-bg);
      color: var(--adm-ink);
    }

    /* ── Header ─────────────────────────────── */
    .adm-eyebrow {
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      color: var(--adm-soft);
    }

    .adm-title {
      font-size: 28px;
      font-weight: 700;
      letter-spacing: -0.01em;
      color: var(--adm-ink);
    }

    .adm-subtitle {
      font-size: 13.5px;
      color: var(--adm-soft);
    }

    .adm-back {
      font-size: 13px;
      font-weight: 600;
      color: var(--adm-soft);
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 1rem;
      transition: color .2s ease;
    }

    .adm-back:hover {
      color: var(--adm-ink);
    }

    /* ── Buttons ────────────────────────────── */
    .adm-btn {
      background: var(--adm-ink);
      color: #fff;
      border: 1px solid var(--adm-ink);
      border-radius: 8px;
      font-size: 13px;
      font-weight: 600;
      padding: .7rem 1.2rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: opacity .2s ease, transform .2s ease;
    }

    .adm-btn:hover {
      opacity: .85;
    }

    .adm-select {
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      font-size: 13px;
      color: var(--adm-ink);
      padding: .7rem 1rem;
    }

    .adm-select:focus {
      outline: none;
      border-color: var(--adm-ink);
    }

    /* ── Alerts ─────────────────────────────── */
    .adm-alert {
      border-radius: 10px;
      padding: 1rem 1.1rem;
      display: flex;
      align-items: flex-start;
      gap: 10px;
      font-size: 13px;
      font-weight: 500;
      border: 1px solid var(--adm-line);
      background: var(--adm-surface);
      border-left: 3px solid var(--adm-ink);
    }

    /* ── Project Cards ──────────────────────── */
    .adm-pcard {
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      overflow: hidden;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }

    .adm-pcard:hover {
      border-color: var(--adm-ink);
      box-shadow: 0 10px 26px -16px rgba(11, 11, 11, 0.25);
      transform: translateY(-2px);
    }

    .adm-pcard-badge {
      font-size: 10.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
      padding: .25rem .6rem;
      border-radius: 999px;
      background: rgba(255,255,255,.92);
      color: var(--adm-ink);
      border: 1px solid var(--adm-line);
    }

    .adm-pcard-title {
      font-size: 15.5px;
      font-weight: 600;
      color: var(--adm-ink);
    }

    .adm-pcard-meta {
      font-size: 12.5px;
      color: var(--adm-soft);
    }

    .adm-pcard-desc {
      font-size: 13px;
      color: var(--adm-soft);
      line-height: 1.5;
    }

    .adm-pcard-link {
      font-size: 12.5px;
      font-weight: 600;
      color: var(--adm-ink);
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .adm-pcard-icon-btn {
      color: var(--adm-soft);
      width: 32px;
      height: 32px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px;
      transition: background .2s ease, color .2s ease;
    }

    .adm-pcard-icon-btn:hover {
      background: var(--adm-bg);
      color: var(--adm-ink);
    }

    .adm-pcard-location-btn {
      display: block;
      width: 100%;
      text-align: center;
      font-size: 12.5px;
      font-weight: 600;
      color: var(--adm-ink);
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      padding: .6rem;
      transition: border-color .2s ease, background .2s ease;
    }

    .adm-pcard-location-btn:hover {
      border-color: var(--adm-ink);
      background: #EFEFEF;
    }

    /* ── Pagination ─────────────────────────── */
    .adm-pagination {
      margin-top: 2.25rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: .9rem;
    }

    .adm-pagination-info {
      font-size: 12.5px;
      color: var(--adm-soft);
    }

    .adm-pagination-controls {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
      justify-content: center;
    }

    .adm-page-btn {
      min-width: 34px;
      height: 34px;
      padding: 0 8px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px;
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      color: var(--adm-ink);
      font-size: 12.5px;
      font-weight: 600;
      transition: border-color .2s ease, background .2s ease;
    }

    .adm-page-btn:hover {
      border-color: var(--adm-ink);
    }

    .adm-page-active {
      background: var(--adm-ink);
      border-color: var(--adm-ink);
      color: #fff;
    }

    .adm-page-active:hover {
      border-color: var(--adm-ink);
    }

    .adm-page-disabled {
      opacity: .35;
      pointer-events: none;
    }

    .adm-page-ellipsis {
      color: var(--adm-muted);
      font-size: 12.5px;
      padding: 0 4px;
    }

    /* ── Empty state ────────────────────────── */
    .adm-empty {
      text-align: center;
      padding: 4rem 1rem;
      background: var(--adm-surface);
      border: 1px dashed var(--adm-line);
      border-radius: 10px;
    }

    /* ── Modal ──────────────────────────────── */
    .modal {
      display: none;
      position: fixed;
      z-index: 50;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(11, 11, 11, 0.55);
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
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 14px;
    }

    .adm-modal-title {
      font-size: 19px;
      font-weight: 700;
      color: var(--adm-ink);
    }

    .adm-label {
      font-size: 12.5px;
      font-weight: 600;
      color: var(--adm-soft);
      margin-bottom: .4rem;
      display: block;
    }

    .adm-input,
    .adm-textarea,
    .adm-file {
      width: 100%;
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      font-size: 13.5px;
      color: var(--adm-ink);
      padding: .75rem .9rem;
    }

    .adm-input:focus,
    .adm-textarea:focus,
    .adm-file:focus {
      outline: none;
      border-color: var(--adm-ink);
      background: var(--adm-surface);
    }

    .image-preview {
      position: relative;
      border: 1.5px dashed var(--adm-line);
      border-radius: 8px;
      padding: .8rem;
      text-align: center;
    }

    .image-preview img {
      max-height: 180px;
      margin: 0 auto;
      border-radius: 8px;
    }

    /* ── Fade ───────────────────────────────── */
    @keyframes adm-fade {
      from { opacity: 0; transform: translateY(8px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .adm-fade {
      animation: adm-fade .4s ease both;
    }

    @media (prefers-reduced-motion: reduce) {
      .adm-fade { animation: none; }
    }
  </style>
</head>

<body class="min-h-screen flex flex-col">

  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">

    <!-- Header -->
    <div class="mb-8 adm-fade flex flex-col md:flex-row md:items-end md:justify-between gap-4">
      <div>
        <a href="<?php echo BASE_URL; ?>projects-dashboard" class="adm-back">
          <i class="fas fa-arrow-left"></i>
          <span>Back to Dashboard</span>
        </a>
        <div class="adm-eyebrow mb-2">Projects Management</div>
        <h1 class="adm-title">
          <?php
          $titles = [
              'all' => 'All Projects',
              'site' => 'Site Projects',
              'residential' => 'Residential Interiors'
          ];
          echo $titles[$category_filter] ?? 'All Projects';
          ?>
        </h1>
        <p class="adm-subtitle mt-1">Manage and organize your projects.</p>
      </div>
      <div class="flex flex-col sm:flex-row gap-3">
        <select onchange="window.location.href='?category=' + this.value" class="adm-select">
          <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
          <option value="site" <?php echo $category_filter === 'site' ? 'selected' : ''; ?>>Site Projects</option>
          <option value="residential" <?php echo $category_filter === 'residential' ? 'selected' : ''; ?>>Residential</option>
        </select>
        <button onclick="openModal('addProjectModal')" class="adm-btn">
          <i class="fas fa-plus"></i>
          <span>Add Project</span>
        </button>
      </div>
    </div>

    <?php if (!empty($success_message)): ?>
      <div class="adm-alert mb-6 adm-fade">
        <i class="fas fa-circle-check mt-0.5" style="color:var(--adm-ink);"></i>
        <p><?php echo htmlspecialchars($success_message); ?></p>
      </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
      <div class="adm-alert mb-6 adm-fade">
        <i class="fas fa-circle-exclamation mt-0.5" style="color:var(--adm-ink);"></i>
        <p><?php echo htmlspecialchars($error_message); ?></p>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 adm-fade">
      <?php while ($project = $projects->fetch_assoc()):
          $main_img_path = CLIENT_ASSET . '/' . $project['main_image'];
      ?>
        <div class="adm-pcard">
          <div class="relative h-48 overflow-hidden">
            <img src="<?php echo htmlspecialchars($main_img_path); ?>" alt="Project" class="w-full h-full object-cover" />
            <div class="absolute top-2 right-2">
              <span class="adm-pcard-badge"><?php echo ucfirst($project['category']); ?></span>
            </div>
          </div>
          <div class="p-4">
            <h3 class="adm-pcard-title mb-1"><?php echo htmlspecialchars($project['title']); ?></h3>
            <p class="adm-pcard-meta mb-3 flex items-center">
              <i class="fas fa-location-dot mr-1.5"></i>
              <?php echo htmlspecialchars($project['address']); ?>
            </p>
            <p class="adm-pcard-desc mb-4 line-clamp-2"><?php echo htmlspecialchars(substr($project['description'], 0, 100)) . '...'; ?></p>
            <div class="pt-3 space-y-2" style="border-top:1px solid var(--adm-line);">
              <div class="flex items-center justify-between">
                <a href="view-projects?id=<?php echo $project['id']; ?>" target="_blank" class="adm-pcard-link">
                  <i class="fas fa-arrow-up-right-from-square"></i>
                  View Project
                </a>
                <div class="flex space-x-1">
                  <button onclick="editProject(<?php echo htmlspecialchars(json_encode($project)); ?>)" class="adm-pcard-icon-btn">
                    <i class="fas fa-pen text-sm"></i>
                  </button>
                  <form method="POST" onsubmit="return confirm('Delete this project and all its images?');" class="inline">
                    <input type="hidden" name="action" value="delete" />
                    <input type="hidden" name="id" value="<?php echo $project['id']; ?>" />
                    <button type="submit" class="adm-pcard-icon-btn">
                      <i class="fas fa-trash text-sm"></i>
                    </button>
                  </form>
                </div>
              </div>
              <a href="project-locations?project_id=<?php echo $project['id']; ?>" class="adm-pcard-location-btn">
                <i class="fas fa-map-location-dot mr-1"></i>
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
      <div class="adm-empty adm-fade">
        <i class="fas fa-folder-open text-5xl mb-4" style="color:var(--adm-muted);"></i>
        <h3 class="text-lg font-semibold mb-2" style="color:var(--adm-ink);">No projects found</h3>
        <p class="adm-subtitle mb-4">Start by adding your first project.</p>
        <button onclick="openModal('addProjectModal')" class="adm-btn mx-auto">
          <i class="fas fa-plus"></i>
          <span>Add Project</span>
        </button>
      </div>
    <?php endif; ?>

    <?php if ($total_pages > 1): ?>
      <div class="adm-pagination adm-fade">
        <p class="adm-pagination-info">
          Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
          <span class="hidden sm:inline">&middot; <?php echo $total_count; ?> total projects</span>
        </p>
        <div class="adm-pagination-controls">
          <?php
            $base_query = ['category' => $category_filter];

            function page_url($page, $base_query) {
                $q = $base_query;
                $q['page'] = $page;
                return '?' . http_build_query($q);
            }

            $window = 2; // pages shown around current
          ?>

          <a href="<?php echo page_url(max(1, $current_page - 1), $base_query); ?>"
             class="adm-page-btn <?php echo $current_page === 1 ? 'adm-page-disabled' : ''; ?>">
            <i class="fas fa-chevron-left text-xs"></i>
          </a>

          <?php if ($current_page - $window > 1): ?>
            <a href="<?php echo page_url(1, $base_query); ?>" class="adm-page-btn">1</a>
            <?php if ($current_page - $window > 2): ?>
              <span class="adm-page-ellipsis">&hellip;</span>
            <?php endif; ?>
          <?php endif; ?>

          <?php for ($p = max(1, $current_page - $window); $p <= min($total_pages, $current_page + $window); $p++): ?>
            <a href="<?php echo page_url($p, $base_query); ?>"
               class="adm-page-btn <?php echo $p === $current_page ? 'adm-page-active' : ''; ?>">
              <?php echo $p; ?>
            </a>
          <?php endfor; ?>

          <?php if ($current_page + $window < $total_pages): ?>
            <?php if ($current_page + $window < $total_pages - 1): ?>
              <span class="adm-page-ellipsis">&hellip;</span>
            <?php endif; ?>
            <a href="<?php echo page_url($total_pages, $base_query); ?>" class="adm-page-btn"><?php echo $total_pages; ?></a>
          <?php endif; ?>

          <a href="<?php echo page_url(min($total_pages, $current_page + 1), $base_query); ?>"
             class="adm-page-btn <?php echo $current_page === $total_pages ? 'adm-page-disabled' : ''; ?>">
            <i class="fas fa-chevron-right text-xs"></i>
          </a>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Add Project Modal -->
  <div id="addProjectModal" class="modal">
    <div class="modal-content max-w-3xl w-full mx-4 p-6">
      <div class="flex items-center justify-between mb-6">
        <h3 class="adm-modal-title">Add New Project</h3>
        <button onclick="closeModal('addProjectModal')" class="adm-pcard-icon-btn">
          <i class="fas fa-xmark text-lg"></i>
        </button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_project" />
        <div class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="adm-label">Project Title *</label>
              <input type="text" name="title" required class="adm-input" placeholder="Enter project title" />
            </div>
            <div>
              <label class="adm-label">Category *</label>
              <select name="category" required class="adm-input">
                <option value="">Select category</option>
                <option value="site">Site Projects</option>
                <option value="residential">Residential Interiors</option>
              </select>
            </div>
          </div>

          <div>
            <label class="adm-label">Address/Location *</label>
            <input type="text" name="address" required class="adm-input" placeholder="Enter project location" />
          </div>

          <div>
            <label class="adm-label">Description *</label>
            <textarea name="description" rows="4" required class="adm-textarea" placeholder="Enter project description"></textarea>
          </div>

          <div class="pt-4" style="border-top:1px solid var(--adm-line);">
            <h4 class="text-sm font-semibold mb-4" style="color:var(--adm-ink);">Project Images (All will be converted to WebP)</h4>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="adm-label">Main Image (Card) *</label>
                <input type="file" name="main_image" accept="image/*" required class="adm-file" onchange="previewImage(this, 'mainPreview')" />
                <div id="mainPreview" class="image-preview mt-2 hidden"></div>
              </div>

              <div>
                <label class="adm-label">Hover Image (Card) *</label>
                <input type="file" name="hover_image" accept="image/*" required class="adm-file" onchange="previewImage(this, 'hoverPreview')" />
                <div id="hoverPreview" class="image-preview mt-2 hidden"></div>
              </div>

              <div>
                <label class="adm-label">Detail Image 1 *</label>
                <input type="file" name="image1" accept="image/*" required class="adm-file" onchange="previewImage(this, 'img1Preview')" />
                <div id="img1Preview" class="image-preview mt-2 hidden"></div>
              </div>

              <div>
                <label class="adm-label">Detail Image 2 *</label>
                <input type="file" name="image2" accept="image/*" required class="adm-file" onchange="previewImage(this, 'img2Preview')" />
                <div id="img2Preview" class="image-preview mt-2 hidden"></div>
              </div>
            </div>

            <p class="text-xs mt-3" style="color:var(--adm-soft);">
              <i class="fas fa-circle-info"></i>
              All images will be automatically converted to WebP format for optimal performance.
            </p>
          </div>

          <button type="submit" class="adm-btn w-full">
            <i class="fas fa-plus"></i> Add Project
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit Project Modal -->
  <div id="editProjectModal" class="modal">
    <div class="modal-content max-w-3xl w-full mx-4 p-6">
      <div class="flex items-center justify-between mb-6">
        <h3 class="adm-modal-title">Edit Project</h3>
        <button onclick="closeModal('editProjectModal')" class="adm-pcard-icon-btn">
          <i class="fas fa-xmark text-lg"></i>
        </button>
      </div>
      <form method="POST" enctype="multipart/form-data" id="editProjectForm">
        <input type="hidden" name="action" value="edit_project" />
        <input type="hidden" name="id" id="edit_id" />
        <div class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="adm-label">Project Title *</label>
              <input type="text" name="title" id="edit_title" required class="adm-input" />
            </div>
            <div>
              <label class="adm-label">Category *</label>
              <select name="category" id="edit_category" required class="adm-input">
                <option value="">Select category</option>
                <option value="site">Site Projects</option>
                <option value="residential">Residential Interiors</option>
              </select>
            </div>
          </div>

          <div>
            <label class="adm-label">Address/Location *</label>
            <input type="text" name="address" id="edit_address" required class="adm-input" />
          </div>

          <div>
            <label class="adm-label">Description *</label>
            <textarea name="description" id="edit_description" rows="4" required class="adm-textarea"></textarea>
          </div>

          <div class="pt-4" style="border-top:1px solid var(--adm-line);">
            <h4 class="text-sm font-semibold mb-2" style="color:var(--adm-ink);">Update Images (Optional)</h4>
            <p class="text-xs mb-4" style="color:var(--adm-soft);">Leave empty to keep existing images. Upload new images to replace them.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="adm-label">Main Image (Card)</label>
                <input type="file" name="main_image" accept="image/*" class="adm-file" onchange="previewImage(this, 'editMainPreview')" />
                <div id="editMainPreview" class="image-preview mt-2"></div>
              </div>

              <div>
                <label class="adm-label">Hover Image (Card)</label>
                <input type="file" name="hover_image" accept="image/*" class="adm-file" onchange="previewImage(this, 'editHoverPreview')" />
                <div id="editHoverPreview" class="image-preview mt-2"></div>
              </div>

              <div>
                <label class="adm-label">Detail Image 1</label>
                <input type="file" name="image1" accept="image/*" class="adm-file" onchange="previewImage(this, 'editImg1Preview')" />
                <div id="editImg1Preview" class="image-preview mt-2"></div>
              </div>

              <div>
                <label class="adm-label">Detail Image 2</label>
                <input type="file" name="image2" accept="image/*" class="adm-file" onchange="previewImage(this, 'editImg2Preview')" />
                <div id="editImg2Preview" class="image-preview mt-2"></div>
              </div>
            </div>
          </div>

          <button type="submit" class="adm-btn w-full">
            <i class="fas fa-floppy-disk"></i> Update Project
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    const CLIENT_ASSET_URL = "<?php echo CLIENT_ASSET; ?>/";
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
        reader.onload = function (e) {
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
          preview.innerHTML = `<img src="${CLIENT_ASSET_URL}${field.path}" alt="Current Image" />
                        <p class="text-xs mt-1" style="color:var(--adm-soft);">Current image (upload new to replace)</p>`;
          preview.classList.remove('hidden');
        }
      });

      // Open modal
      openModal('editProjectModal');
    }

    window.onclick = function (event) {
      if (event.target.classList.contains('modal')) {
        closeModal(event.target.id);
      }
    }
  </script>
</body>

</html>

<?php $conn->close(); ?>