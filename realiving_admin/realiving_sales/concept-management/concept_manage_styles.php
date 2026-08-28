<?php
//concept_manage_styles.php
include $includes ['mainbody'];

// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales']);

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM concept_styles WHERE id = $id");
    header("Location: " . BASE_URL . "concept-manage-styles");
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
        header("Location: " . BASE_URL . "concept-manage-styles?category_added=1");
        exit();
    }
}

// Handle category delete
if (isset($_GET['delete_category'])) {
    $cat_id = intval($_GET['delete_category']);
    $conn->query("DELETE FROM concept_categories WHERE id = $cat_id");
    header("Location: " . BASE_URL . "concept-manage-styles");
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
    header("Location: " . BASE_URL . "concept-manage-styles");
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
  <title>Manage Concept Styles - RealLiving</title>
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
      --adm-surface2: #FAFAFA;
      --adm-ink: #0B0B0B;
      --adm-soft: #6B6B6B;
      --adm-muted: #9A9A9A;
      --adm-line: #E2E2E2;
    }

    * {
      box-sizing: border-box;
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

    .adm-header-row {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }

    .adm-header-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .adm-back {
      font-size: 12.5px;
      font-weight: 600;
      color: var(--adm-soft);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      padding: 8px 14px;
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      background: var(--adm-surface);
      transition: border-color .2s ease, color .2s ease;
    }

    .adm-back:hover {
      border-color: var(--adm-ink);
      color: var(--adm-ink);
    }

    /* ── Section label ──────────────────────── */
    .adm-section-label {
      font-size: 12px;
      font-weight: 600;
      color: var(--adm-ink);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .adm-section-label::after {
      content: "";
      flex: 1;
      height: 1px;
      background: var(--adm-line);
    }

    /* ── Alerts ─────────────────────────────── */
    .adm-alert {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 18px;
      border-radius: 10px;
      margin-bottom: 20px;
      font-size: 13.5px;
      font-weight: 500;
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-left: 3px solid var(--adm-ink);
      color: var(--adm-ink);
    }

    /* ── Buttons ────────────────────────────── */
    .adm-btn {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 10px 18px;
      border-radius: 8px;
      font-size: 12.5px;
      font-weight: 600;
      border: 1px solid transparent;
      cursor: pointer;
      text-decoration: none;
      transition: all .18s ease;
      font-family: 'Inter', sans-serif;
      white-space: nowrap;
    }

    .adm-btn-primary {
      background: var(--adm-ink);
      color: #fff;
    }

    .adm-btn-primary:hover {
      background: #262626;
    }

    .adm-btn-outline {
      background: var(--adm-surface);
      border-color: var(--adm-line);
      color: var(--adm-ink);
    }

    .adm-btn-outline:hover {
      border-color: var(--adm-ink);
    }

    .adm-btn-danger {
      background: var(--adm-surface);
      border-color: #f3caca;
      color: #9b1c1c;
    }

    .adm-btn-danger:hover {
      background: #fdf1f1;
      border-color: #9b1c1c;
    }

    .adm-btn-sm {
      padding: 6px 12px;
      font-size: 11.5px;
    }

    /* ── Panel / table ──────────────────────── */
    .adm-panel {
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      overflow: hidden;
    }

    .adm-panel-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 18px 22px;
      border-bottom: 1px solid var(--adm-line);
    }

    .adm-panel-head h2 {
      font-size: 15px;
      font-weight: 700;
      color: var(--adm-ink);
    }

    .adm-table-wrap {
      overflow-x: auto;
    }

    .adm-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }

    .adm-table thead th {
      padding: 11px 16px;
      text-align: left;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
      color: var(--adm-soft);
      background: var(--adm-surface2);
      border-bottom: 1px solid var(--adm-line);
      white-space: nowrap;
    }

    .adm-table tbody tr {
      border-bottom: 1px solid var(--adm-line);
      transition: background .15s ease;
    }

    .adm-table tbody tr:last-child {
      border-bottom: none;
    }

    .adm-table tbody tr:hover {
      background: var(--adm-surface2);
    }

    .adm-table td {
      padding: 13px 16px;
      vertical-align: top;
      color: var(--adm-ink);
    }

    .td-name {
      font-weight: 600;
      font-size: 14px;
    }

    .td-muted {
      color: var(--adm-soft);
    }

    .td-mono {
      font-family: 'SFMono-Regular', Consolas, 'Courier New', monospace;
      font-size: 11.5px;
      color: var(--adm-soft);
      max-width: 200px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      display: block;
    }

    .td-desc {
      max-width: 260px;
      color: var(--adm-soft);
      font-size: 12.5px;
      line-height: 1.5;
    }

    .adm-badge {
      display: inline-flex;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .3px;
      text-transform: uppercase;
      border: 1px solid var(--adm-line);
      background: var(--adm-surface2);
      color: var(--adm-soft);
    }

    .adm-badge.badge-full {
      color: #1e3a5f;
      background: #eaf1fb;
      border-color: #cdddf3;
    }

    .adm-badge.badge-two-column {
      color: var(--adm-ink);
      background: var(--adm-surface2);
      border-color: var(--adm-line);
    }

    .adm-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .adm-empty {
      text-align: center;
      padding: 20px;
      color: var(--adm-muted);
      font-size: 13px;
    }

    /* ── Modal ──────────────────────────────── */
    .adm-modal-bg {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(11, 11, 11, .5);
      z-index: 999;
      align-items: center;
      justify-content: center;
      backdrop-filter: blur(2px);
      padding: 20px;
    }

    .adm-modal-bg.open {
      display: flex;
    }

    .adm-modal-box {
      background: var(--adm-surface);
      border-radius: 12px;
      padding: 30px;
      max-width: 640px;
      width: 100%;
      max-height: 92vh;
      overflow-y: auto;
      box-shadow: 0 20px 60px rgba(11, 11, 11, .25);
      border: 1px solid var(--adm-line);
    }

    .adm-modal-box.wide {
      max-width: 720px;
    }

    .adm-modal-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 22px;
      padding-bottom: 16px;
      border-bottom: 1px solid var(--adm-line);
    }

    .adm-modal-head h3 {
      font-size: 17px;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 9px;
      color: var(--adm-ink);
    }

    .adm-modal-close {
      background: none;
      border: none;
      font-size: 18px;
      color: var(--adm-soft);
      cursor: pointer;
      padding: 6px;
      border-radius: 6px;
      line-height: 1;
    }

    .adm-modal-close:hover {
      color: var(--adm-ink);
      background: var(--adm-surface2);
    }

    /* ── Form ───────────────────────────────── */
    .form-group {
      margin-bottom: 18px;
    }

    .form-group label {
      display: block;
      font-size: 11.5px;
      font-weight: 600;
      color: var(--adm-soft);
      text-transform: uppercase;
      letter-spacing: .4px;
      margin-bottom: 7px;
    }

    .form-control {
      width: 100%;
      padding: 10px 13px;
      border: 1px solid var(--adm-line);
      border-radius: 8px;
      font-size: 13.5px;
      font-family: 'Inter', sans-serif;
      color: var(--adm-ink);
      background: var(--adm-surface2);
      transition: border-color .18s ease, background .18s ease;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--adm-ink);
      background: #fff;
    }

    textarea.form-control {
      min-height: 90px;
      resize: vertical;
    }

    .form-help {
      font-size: 11.5px;
      color: var(--adm-muted);
      margin-top: 6px;
    }

    .form-help a {
      color: var(--adm-ink);
      font-weight: 600;
    }

    .form-check {
      display: flex;
      align-items: center;
      gap: 9px;
      font-size: 13px;
      color: var(--adm-ink);
      cursor: pointer;
    }

    .form-check input {
      width: auto;
      accent-color: var(--adm-ink);
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    .adm-inline-panel {
      background: var(--adm-surface2);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      padding: 18px 20px;
      margin-bottom: 24px;
    }

    .adm-inline-panel h3 {
      font-size: 12px;
      font-weight: 700;
      color: var(--adm-soft);
      text-transform: uppercase;
      letter-spacing: .4px;
      margin-bottom: 14px;
    }

    .adm-subsection-title {
      font-size: 12px;
      font-weight: 700;
      color: var(--adm-soft);
      text-transform: uppercase;
      letter-spacing: .4px;
      margin-bottom: 12px;
    }

    @media (max-width: 640px) {
      .form-row {
        grid-template-columns: 1fr;
      }

      .adm-header-row {
        flex-direction: column;
      }
    }

    @keyframes adm-fade {
      from {
        opacity: 0;
        transform: translateY(8px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .adm-fade {
      animation: adm-fade .4s ease both;
    }

    @media (prefers-reduced-motion: reduce) {
      .adm-fade {
        animation: none;
      }
    }
  </style>
</head>

<body class="min-h-screen flex flex-col">

  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">

    <!-- Header -->
    <div class="mb-8 adm-fade adm-header-row">
      <div>
        <div class="adm-eyebrow mb-2">Sales &amp; Marketing</div>
        <h1 class="adm-title">Manage Concept Styles</h1>
        <p class="adm-subtitle mt-1">Add, edit, and organize the design styles shown on the Concept Designs page.</p>
      </div>
      <div class="adm-header-actions">
        <a href="concept-dashboard" class="adm-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <button class="adm-btn adm-btn-outline" onclick="openCategoryModal()"><i class="fas fa-tags"></i> Manage Categories</button>
        <button class="adm-btn adm-btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Add New Style</button>
      </div>
    </div>

    <?php if (isset($_GET['category_added'])): ?>
      <div class="adm-alert adm-fade">
        <i class="fa-solid fa-circle-check" style="color:var(--adm-ink);"></i>
        <span>Category added successfully!</span>
      </div>
    <?php endif; ?>

    <!-- Styles Table -->
    <div class="adm-panel adm-fade">
      <div class="adm-panel-head">
        <h2><i class="fas fa-palette" style="color:var(--adm-soft);"></i> Design Styles</h2>
        <span class="td-muted" style="font-size:12.5px;"><?php echo $styles->num_rows; ?> total</span>
      </div>
      <div class="adm-table-wrap">
        <table class="adm-table">
          <thead>
            <tr>
              <th>Order</th>
              <th>Title</th>
              <th>Category</th>
              <th>Description</th>
              <th>Iframe URL</th>
              <th>Layout</th>
              <th>Reversed</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($styles->num_rows > 0): ?>
              <?php while ($style = $styles->fetch_assoc()): ?>
                <tr>
                  <td class="td-muted"><?php echo $style['display_order']; ?></td>
                  <td class="td-name"><?php echo htmlspecialchars($style['title']); ?></td>
                  <td class="td-muted">
                    <?php echo $style['category_name'] ? htmlspecialchars($style['category_name']) : '<em>No category</em>'; ?>
                  </td>
                  <td class="td-desc"><?php echo substr(htmlspecialchars($style['description']), 0, 80) . '...'; ?></td>
                  <td><span class="td-mono" title="<?php echo htmlspecialchars($style['iframe_url']); ?>"><?php echo htmlspecialchars($style['iframe_url']); ?></span></td>
                  <td><span class="adm-badge badge-<?php echo $style['layout_type']; ?>"><?php echo $style['layout_type']; ?></span></td>
                  <td class="td-muted"><?php echo $style['is_reversed'] ? 'Yes' : 'No'; ?></td>
                  <td>
                    <div class="adm-actions">
                      <button class="adm-btn adm-btn-outline adm-btn-sm" onclick='editStyle(<?php echo json_encode($style); ?>)'><i class="fas fa-pen"></i> Edit</button>
                      <a href="?delete=<?php echo $style['id']; ?>" class="adm-btn adm-btn-danger adm-btn-sm" onclick="return confirm('Are you sure?')"><i class="fas fa-trash"></i> Delete</a>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="8" class="adm-empty">No styles added yet.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <!-- Add/Edit Style Modal -->
  <div id="styleModal" class="adm-modal-bg">
    <div class="adm-modal-box">
      <div class="adm-modal-head">
        <h3 id="modalTitle"><i class="fas fa-palette" style="color:var(--adm-soft);"></i> Add New Style</h3>
        <button class="adm-modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
      </div>
      <form method="POST">
        <input type="hidden" name="id" id="styleId">

        <div class="form-group">
          <label>Title</label>
          <input type="text" name="title" id="title" class="form-control">
        </div>

        <div class="form-group">
          <label>Description</label>
          <textarea name="description" id="description" class="form-control" required></textarea>
        </div>

        <div class="form-group">
          <label>Category</label>
          <select name="category_id" id="category_id" class="form-control">
            <option value="">-- No Category --</option>
            <?php
            $categories_list = $conn->query("SELECT * FROM concept_categories ORDER BY display_order ASC, name ASC");
            while ($cat = $categories_list->fetch_assoc()):
            ?>
              <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
            <?php endwhile; ?>
          </select>
          <p class="form-help">Select a category or <a href="#" onclick="event.preventDefault(); closeModal(); openCategoryModal();">add a new one</a></p>
        </div>

        <div class="form-group">
          <label>Iframe URL</label>
          <input type="url" name="iframe_url" id="iframe_url" class="form-control" placeholder="https://example.com/embed/..." required>
          <p class="form-help">Enter the full URL for the iframe embed</p>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Layout Type</label>
            <select name="layout_type" id="layout_type" class="form-control" required>
              <option value="full">Full Width</option>
              <option value="two-column">Two Column</option>
            </select>
          </div>
          <div class="form-group">
            <label>Display Order</label>
            <input type="number" name="display_order" id="display_order" class="form-control" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-check" style="text-transform:none;letter-spacing:0;font-weight:500;">
            <input type="checkbox" name="is_reversed" id="is_reversed"> Reversed Layout (for full width only)
          </label>
        </div>

        <div class="form-group" style="margin-bottom:0;display:flex;justify-content:flex-end;gap:10px;padding-top:6px;border-top:1px solid var(--adm-line);">
          <button type="button" class="adm-btn adm-btn-outline" style="margin-top:16px;" onclick="closeModal()">Cancel</button>
          <button type="submit" class="adm-btn adm-btn-primary" style="margin-top:16px;"><i class="fas fa-check"></i> Save</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Category Management Modal -->
  <div id="categoryModal" class="adm-modal-bg">
    <div class="adm-modal-box wide">
      <div class="adm-modal-head">
        <h3><i class="fas fa-tags" style="color:var(--adm-soft);"></i> Manage Categories</h3>
        <button class="adm-modal-close" onclick="closeCategoryModal()"><i class="fas fa-times"></i></button>
      </div>

      <!-- Add Category Form -->
      <div class="adm-inline-panel">
        <h3>Add New Category</h3>
        <form method="POST">
          <div class="form-row">
            <div class="form-group">
              <label>Category Name</label>
              <input type="text" name="category_name" class="form-control" required>
            </div>
            <div class="form-group">
              <label>Display Order</label>
              <input type="number" name="category_order" value="0" class="form-control" required>
            </div>
          </div>
          <button type="submit" name="add_category" class="adm-btn adm-btn-primary"><i class="fas fa-plus"></i> Add Category</button>
        </form>
      </div>

      <!-- Existing Categories List -->
      <div class="adm-subsection-title">Existing Categories</div>
      <div class="adm-panel">
        <div class="adm-table-wrap">
          <table class="adm-table">
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
                  <td class="td-muted"><?php echo $cat['display_order']; ?></td>
                  <td class="td-name"><?php echo htmlspecialchars($cat['name']); ?></td>
                  <td>
                    <a href="?delete_category=<?php echo $cat['id']; ?>"
                      class="adm-btn adm-btn-danger adm-btn-sm"
                      onclick="return confirm('Delete this category? Styles using it will have no category.')">
                      <i class="fas fa-trash"></i> Delete
                    </a>
                  </td>
                </tr>
              <?php
                  endwhile;
              else:
              ?>
                <tr>
                  <td colspan="3" class="adm-empty">No categories yet. Add one above!</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <script>
    function openModal() {
      document.getElementById('styleModal').classList.add('open');
      document.getElementById('modalTitle').innerHTML = '<i class="fas fa-palette" style="color:var(--adm-soft);"></i> Add New Style';
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
      document.getElementById('styleModal').classList.add('open');
      document.getElementById('modalTitle').innerHTML = '<i class="fas fa-pen" style="color:var(--adm-soft);"></i> Edit Style';
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
      document.getElementById('styleModal').classList.remove('open');
    }

    function openCategoryModal() {
      document.getElementById('categoryModal').classList.add('open');
    }

    function closeCategoryModal() {
      document.getElementById('categoryModal').classList.remove('open');
    }

    window.onclick = function (event) {
      const styleModal = document.getElementById('styleModal');
      const categoryModal = document.getElementById('categoryModal');

      if (event.target === styleModal) closeModal();
      if (event.target === categoryModal) closeCategoryModal();
    }
  </script>
</body>

</html>