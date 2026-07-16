<?php
// manage_collection_details.php - Images managed here; connections are read-only (set at sub-theme level)
session_start();
include '../../connection/connection.php';
include '../design/mainbody.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

$collection_id    = intval($_GET['id'] ?? 0);
$success_message  = "";
$error_message    = "";

// ── Get collection details ───────────────────────────────────────────────────
$coll_stmt = $conn->prepare("
    SELECT gc.*, st.name AS subtheme_name, st.id AS subtheme_id, t.name AS theme_name
    FROM gallery_collections gc
    JOIN sub_themes st ON gc.sub_theme_id = st.id
    JOIN themes t ON st.theme_id = t.id
    WHERE gc.id = ?
");
$coll_stmt->bind_param("i", $collection_id);
$coll_stmt->execute();
$collection = $coll_stmt->get_result()->fetch_assoc();
$coll_stmt->close();

if (!$collection) {
    header("Location: manage_collections.php");
    exit();
}

// ── WebP converter ───────────────────────────────────────────────────────────
function convertToWebP($source, $destination, $quality = 90) {
    $info  = getimagesize($source);
    $image = null;
    switch ($info['mime']) {
        case 'image/jpeg': $image = imagecreatefromjpeg($source); break;
        case 'image/png':  $image = imagecreatefrompng($source);  break;
        case 'image/gif':  $image = imagecreatefromgif($source);  break;
        case 'image/webp': $image = imagecreatefromwebp($source); break;
    }
    if ($image !== false && $image !== null) {
        $result = imagewebp($image, $destination, $quality);
        imagedestroy($image);
        return $result;
    }
    return false;
}

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add images
    if ($action === 'add_images') {
        $count_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM gallery_collection_images WHERE collection_id = ?");
        $count_stmt->bind_param("i", $collection_id);
        $count_stmt->execute();
        $current_count = $count_stmt->get_result()->fetch_assoc()['cnt'];
        $count_stmt->close();
        $remaining = 10 - $current_count;

        if ($remaining > 0 && isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
            $target_dir = "../../realiving_user/images/gallery/";
            if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
            $added = 0;

            foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                if ($added >= $remaining) break;
                if ($_FILES['images']['error'][$key] === 0) {
                    $file_name   = uniqid() . '_' . $collection_id . '_' . time() . '.webp';
                    $target_file = $target_dir . $file_name;
                    if (convertToWebP($tmp_name, $target_file)) {
                        $image_path   = './images/gallery/' . $file_name;
                        $display_order = $current_count + $added;
                        $stmt = $conn->prepare("INSERT INTO gallery_collection_images (collection_id, image_path, display_order) VALUES (?, ?, ?)");
                        $stmt->bind_param("isi", $collection_id, $image_path, $display_order);
                        $stmt->execute();
                        $stmt->close();
                        $added++;
                    }
                }
            }
            $success_message = "$added image(s) added successfully!";
        } else {
            $error_message = $remaining <= 0
                ? "Collection is already full (10/10)."
                : "No images were selected.";
        }
    }

    // Delete image
    if ($action === 'delete_image') {
        $image_id  = intval($_POST['image_id']);
        $img_stmt  = $conn->prepare("SELECT image_path FROM gallery_collection_images WHERE id = ?");
        $img_stmt->bind_param("i", $image_id);
        $img_stmt->execute();
        $img = $img_stmt->get_result()->fetch_assoc();
        $img_stmt->close();

        $del = $conn->prepare("DELETE FROM gallery_collection_images WHERE id = ?");
        $del->bind_param("i", $image_id);
        if ($del->execute()) {
            if ($img && file_exists("../../realiving_user/" . $img['image_path'])) {
                unlink("../../realiving_user/" . $img['image_path']);
            }
            $success_message = "Image deleted.";
        }
        $del->close();
    }

    // Update collection info
    if ($action === 'update_info') {
        $name        = trim($_POST['name']);
        $slug        = strtolower(str_replace(' ', '-', $name));
        $room_area   = trim($_POST['room_area']);
        $description = trim($_POST['description']);
        $upd = $conn->prepare("UPDATE gallery_collections SET name=?, slug=?, room_area=?, description=? WHERE id=?");
        $upd->bind_param("ssssi", $name, $slug, $room_area, $description, $collection_id);
        if ($upd->execute()) {
            $success_message = "Collection info updated!";
            // Refresh collection data
            $coll_stmt2 = $conn->prepare("SELECT gc.*, st.name AS subtheme_name, st.id AS subtheme_id, t.name AS theme_name FROM gallery_collections gc JOIN sub_themes st ON gc.sub_theme_id = st.id JOIN themes t ON st.theme_id = t.id WHERE gc.id = ?");
            $coll_stmt2->bind_param("i", $collection_id);
            $coll_stmt2->execute();
            $collection = $coll_stmt2->get_result()->fetch_assoc();
            $coll_stmt2->close();
        }
        $upd->close();
    }
}

// ── Fetch images ─────────────────────────────────────────────────────────────
$img_stmt = $conn->prepare("SELECT * FROM gallery_collection_images WHERE collection_id = ? ORDER BY display_order ASC");
$img_stmt->bind_param("i", $collection_id);
$img_stmt->execute();
$images      = $img_stmt->get_result();
$image_count = $images->num_rows;
$img_stmt->close();
$images_arr  = [];
$img_stmt2   = $conn->prepare("SELECT * FROM gallery_collection_images WHERE collection_id = ? ORDER BY display_order ASC");
$img_stmt2->bind_param("i", $collection_id);
$img_stmt2->execute();
$img_res     = $img_stmt2->get_result();
while ($r = $img_res->fetch_assoc()) $images_arr[] = $r;
$img_stmt2->close();

// ── Fetch sub-theme building type connections (read-only here) ───────────────
$conn_stmt = $conn->prepare("
    SELECT bt.name, bt.icon
    FROM sub_theme_building_types stbt
    JOIN building_types bt ON bt.id = stbt.building_type_id
    WHERE stbt.sub_theme_id = ?
    ORDER BY bt.display_order ASC
");
$conn_stmt->bind_param("i", $collection['subtheme_id']);
$conn_stmt->execute();
$connections     = $conn_stmt->get_result();
$connections_arr = [];
while ($r = $connections->fetch_assoc()) $connections_arr[] = $r;
$conn_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Manage: <?php echo htmlspecialchars($collection['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />
    <style>
        :root {
            --purple: #7c3aed;
            --purple-light: #ede9fe;
            --surface: #f8f7ff;
            --border: #e5e7eb;
            --text: #1e1b2e;
        }
        body { font-family: 'DM Sans', sans-serif; background: var(--surface); color: var(--text); }
        h1, h2, h3, .font-display { font-family: 'Syne', sans-serif; }

        .modal { display: none; position: fixed; z-index: 60; inset: 0; background: rgba(15,10,30,.55); backdrop-filter: blur(3px); }
        .modal.active { display: flex; align-items: center; justify-content: center; padding: 40px 20px; }

        .img-card { position: relative; border-radius: 12px; overflow: hidden; aspect-ratio: 4/3; background: #f3f4f6; }
        .img-card img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .3s ease; }
        .img-card:hover img { transform: scale(1.04); }
        .img-card .overlay { position: absolute; inset: 0; background: linear-gradient(to top, rgba(0,0,0,.45) 0%, transparent 60%); opacity: 0; transition: opacity .2s; }
        .img-card:hover .overlay { opacity: 1; }
        .img-card .delete-btn { position: absolute; top: 8px; right: 8px; opacity: 0; transition: opacity .2s; }
        .img-card:hover .delete-btn { opacity: 1; }
        .img-card .order-badge { position: absolute; top: 8px; left: 8px; }

        /* Add image drop zone */
        .drop-zone {
            border: 2px dashed #c4b5fd;
            border-radius: 12px;
            background: #faf5ff;
            transition: background .2s, border-color .2s;
            cursor: pointer;
        }
        .drop-zone:hover, .drop-zone.dragover {
            background: #ede9fe;
            border-color: var(--purple);
        }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 9px; }
    </style>
</head>
<body>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- ── Breadcrumb / Back ── -->
    <a href="manage_collections.php" class="inline-flex items-center gap-1.5 text-sm text-purple-600 hover:text-purple-800 mb-5 transition-colors">
        <i class="ri-arrow-left-line"></i> Back to Collections
    </a>

    <!-- ── Page Header ── -->
    <div class="mb-7 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-purple-400 mb-1">
                <i class="ri-palette-line"></i><?php echo htmlspecialchars($collection['theme_name']); ?>
                <span class="text-gray-300">›</span>
                <i class="ri-brush-line"></i><?php echo htmlspecialchars($collection['subtheme_name']); ?>
            </div>
            <h1 class="text-3xl font-display text-gray-900"><?php echo htmlspecialchars($collection['name']); ?></h1>
            <?php if (!empty($collection['room_area'])): ?>
                <p class="text-sm text-purple-600 font-medium mt-0.5">
                    <i class="ri-home-4-line mr-1"></i><?php echo htmlspecialchars($collection['room_area']); ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-3">
            <!-- Image count pill -->
            <div class="<?php echo $image_count >= 10 ? 'bg-emerald-100 text-emerald-800' : 'bg-purple-100 text-purple-800'; ?> px-4 py-2 rounded-xl text-sm font-bold">
                <i class="ri-image-line mr-1"></i><?php echo $image_count; ?>/10 images
                <?php if ($image_count >= 10): ?><i class="ri-checkbox-circle-line ml-1"></i><?php endif; ?>
            </div>
            <?php if ($image_count < 10): ?>
                <button onclick="openAddImagesModal()"
                    class="inline-flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-5 py-2.5 rounded-xl font-semibold text-sm transition-colors">
                    <i class="ri-image-add-line"></i> Add Images
                    <span class="bg-white/20 text-xs px-1.5 py-0.5 rounded-md"><?php echo 10 - $image_count; ?> left</span>
                </button>
            <?php endif; ?>
            <button onclick="openEditInfoModal()"
                class="inline-flex items-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl font-semibold text-sm transition-colors">
                <i class="ri-edit-line"></i> Edit Info
            </button>
        </div>
    </div>

    <!-- ── Flash Messages ── -->
    <?php if (!empty($success_message)): ?>
        <div class="mb-5 flex items-center gap-3 p-4 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-800 text-sm">
            <i class="ri-checkbox-circle-line text-lg text-emerald-500"></i>
            <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="mb-5 flex items-center gap-3 p-4 bg-red-50 border border-red-200 rounded-xl text-red-800 text-sm">
            <i class="ri-error-warning-line text-lg text-red-500"></i>
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <!-- ── Main Layout ── -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- ── Images panel (2/3) ── -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-xl font-display text-gray-900">Gallery Images</h2>
                    <?php if ($image_count > 0 && $image_count < 10): ?>
                        <button onclick="openAddImagesModal()"
                            class="inline-flex items-center gap-1.5 text-xs text-purple-600 hover:text-purple-800 font-semibold border border-purple-200 px-3 py-1.5 rounded-lg hover:bg-purple-50 transition-colors">
                            <i class="ri-add-line"></i> Add More
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (empty($images_arr)): ?>
                    <!-- Empty state -->
                    <div class="drop-zone flex flex-col items-center justify-center py-16 px-6 text-center" onclick="openAddImagesModal()">
                        <div class="w-16 h-16 rounded-2xl bg-purple-50 flex items-center justify-center mb-4">
                            <i class="ri-image-add-line text-3xl text-purple-300"></i>
                        </div>
                        <p class="text-gray-500 font-medium mb-1">No images yet</p>
                        <p class="text-xs text-gray-400">Click to upload up to 10 images</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <?php foreach ($images_arr as $img):
                            $img_path = "../../realiving_user/" . $img['image_path'];
                        ?>
                            <div class="img-card group">
                                <?php if (file_exists($img_path)): ?>
                                    <img src="<?php echo htmlspecialchars($img_path); ?>" alt="Gallery image" loading="lazy" />
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center">
                                        <i class="ri-image-line text-4xl text-gray-200"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="overlay"></div>
                                <span class="order-badge bg-black/50 text-white text-xs font-bold px-2 py-0.5 rounded-md">
                                    #<?php echo $img['display_order'] + 1; ?>
                                </span>
                                <div class="delete-btn">
                                    <form method="POST" onsubmit="return confirm('Delete this image?');">
                                        <input type="hidden" name="action" value="delete_image" />
                                        <input type="hidden" name="image_id" value="<?php echo $img['id']; ?>" />
                                        <button type="submit" class="bg-red-500 hover:bg-red-600 text-white w-8 h-8 rounded-full flex items-center justify-center shadow-lg transition-colors">
                                            <i class="ri-delete-bin-line text-sm"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($image_count < 10): ?>
                            <div class="drop-zone flex flex-col items-center justify-center aspect-[4/3] cursor-pointer" onclick="openAddImagesModal()">
                                <i class="ri-add-line text-2xl text-purple-400 mb-1"></i>
                                <span class="text-xs text-purple-500 font-medium">Add</span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Sidebar (1/3) ── -->
        <div class="space-y-5">

            <!-- Collection Info card -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-display text-gray-900 text-lg">Collection Info</h3>
                    <button onclick="openEditInfoModal()" class="text-xs text-purple-600 hover:text-purple-800 font-semibold flex items-center gap-1">
                        <i class="ri-edit-line"></i> Edit
                    </button>
                </div>
                <dl class="space-y-2 text-sm">
                    <div class="flex items-start gap-2">
                        <dt class="text-gray-400 w-24 shrink-0">Theme</dt>
                        <dd class="font-medium text-gray-800"><?php echo htmlspecialchars($collection['theme_name']); ?></dd>
                    </div>
                    <div class="flex items-start gap-2">
                        <dt class="text-gray-400 w-24 shrink-0">Sub-theme</dt>
                        <dd class="font-medium text-gray-800"><?php echo htmlspecialchars($collection['subtheme_name']); ?></dd>
                    </div>
                    <?php if (!empty($collection['room_area'])): ?>
                    <div class="flex items-start gap-2">
                        <dt class="text-gray-400 w-24 shrink-0">Room Area</dt>
                        <dd class="font-medium text-gray-800"><?php echo htmlspecialchars($collection['room_area']); ?></dd>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($collection['description'])): ?>
                    <div class="flex items-start gap-2">
                        <dt class="text-gray-400 w-24 shrink-0">Description</dt>
                        <dd class="text-gray-600"><?php echo htmlspecialchars($collection['description']); ?></dd>
                    </div>
                    <?php endif; ?>
                    <div class="flex items-start gap-2">
                        <dt class="text-gray-400 w-24 shrink-0">Images</dt>
                        <dd class="font-bold <?php echo $image_count >= 10 ? 'text-emerald-600' : 'text-purple-600'; ?>">
                            <?php echo $image_count; ?>/10
                        </dd>
                    </div>
                    <div class="flex items-start gap-2">
                        <dt class="text-gray-400 w-24 shrink-0">Created</dt>
                        <dd class="text-gray-600"><?php echo date('M d, Y', strtotime($collection['created_at'])); ?></dd>
                    </div>
                </dl>
            </div>

            <!-- Building type connections (read-only) -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5">
                <div class="flex items-center justify-between mb-1">
                    <h3 class="font-display text-gray-900 text-lg">Building Types</h3>
                    <span class="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded-lg">Inherited</span>
                </div>
                <p class="text-xs text-gray-400 mb-4">Set at the sub-theme level. To change, go back and use "Manage Connections" on the sub-theme.</p>

                <?php if (empty($connections_arr)): ?>
                    <div class="text-center py-4">
                        <i class="ri-links-line text-3xl text-gray-200 mb-2"></i>
                        <p class="text-xs text-gray-400">No building types connected to this sub-theme yet.</p>
                        <a href="manage_collections.php" class="text-xs text-purple-600 hover:underline font-semibold mt-1 inline-block">
                            Go set them ›
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach ($connections_arr as $c): ?>
                            <div class="flex items-center gap-2.5 p-2.5 bg-indigo-50 rounded-lg">
                                <i class="<?php echo htmlspecialchars($c['icon']); ?> text-indigo-500 text-lg"></i>
                                <span class="text-sm font-medium text-gray-800"><?php echo htmlspecialchars($c['name']); ?></span>
                                <i class="ri-lock-line text-gray-300 text-xs ml-auto"></i>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /sidebar -->
    </div><!-- /grid -->

</div><!-- /max-w -->


<!-- ══ MODAL: Add Images ════════════════════════════════════════════════════ -->
<div id="addImagesModal" class="modal">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-7">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h3 class="text-xl font-display text-gray-900">Add Images</h3>
                <p class="text-xs text-gray-400 mt-0.5"><?php echo 10 - $image_count; ?> slot(s) available</p>
            </div>
            <button onclick="closeModal('addImagesModal')" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-700 transition-colors">
                <i class="ri-close-line text-xl"></i>
            </button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_images" />
            <div class="space-y-4">
                <label class="drop-zone flex flex-col items-center justify-center py-10 px-4 text-center cursor-pointer">
                    <i class="ri-upload-cloud-2-line text-4xl text-purple-300 mb-2"></i>
                    <span class="text-sm text-gray-600 font-medium">Click to select images</span>
                    <span class="text-xs text-gray-400 mt-1">JPG, PNG, GIF, WebP · Auto-converts to WebP</span>
                    <input type="file" name="images[]" accept="image/*" multiple required class="hidden" />
                </label>
                <p class="text-xs text-center text-gray-400">Max <?php echo 10 - $image_count; ?> image(s)</p>
                <button type="submit"
                    class="w-full bg-purple-600 hover:bg-purple-700 text-white py-3 rounded-xl font-semibold text-sm transition-colors">
                    <i class="ri-upload-line mr-2"></i>Upload Images
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ══ MODAL: Edit Info ═════════════════════════════════════════════════════ -->
<div id="editInfoModal" class="modal">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-7">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-xl font-display text-gray-900">Edit Collection Info</h3>
            <button onclick="closeModal('editInfoModal')" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-700 transition-colors">
                <i class="ri-close-line text-xl"></i>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_info" />
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Collection Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" required value="<?php echo htmlspecialchars($collection['name']); ?>"
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-400 outline-none" />
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Room Area</label>
                    <input type="text" name="room_area" value="<?php echo htmlspecialchars($collection['room_area'] ?? ''); ?>"
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-400 outline-none"
                        placeholder="Living Room, Kitchen, etc." />
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Description</label>
                    <textarea name="description" rows="3"
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-400 outline-none resize-none"
                        placeholder="Optional…"><?php echo htmlspecialchars($collection['description'] ?? ''); ?></textarea>
                </div>
                <button type="submit"
                    class="w-full bg-purple-600 hover:bg-purple-700 text-white py-3 rounded-xl font-semibold text-sm transition-colors">
                    <i class="ri-save-line mr-2"></i>Save Changes
                </button>
            </div>
        </form>
    </div>
</div>


<script>
function openAddImagesModal() {
    document.getElementById('addImagesModal').classList.add('active');
}
function openEditInfoModal() {
    document.getElementById('editInfoModal').classList.add('active');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
window.addEventListener('click', e => {
    if (e.target.classList.contains('modal')) e.target.classList.remove('active');
});

// Drag-and-drop visual on drop zone
document.querySelectorAll('.drop-zone').forEach(zone => {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
    zone.addEventListener('drop', e => { e.preventDefault(); zone.classList.remove('dragover'); });
});

// Show file name when selected
document.querySelectorAll('.drop-zone input[type=file]').forEach(input => {
    input.addEventListener('change', function () {
        const label = this.closest('.drop-zone').querySelector('span');
        if (label && this.files.length) {
            label.textContent = `${this.files.length} file(s) selected`;
        }
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>