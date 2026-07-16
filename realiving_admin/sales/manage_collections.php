<?php
// manage_collections.php - Revamped: Grouped by sub-theme, connections at sub-theme level
session_start();
include '../../connection/connection.php';
include '../design/mainbody.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

$success_message = "";
$error_message = "";

// ── Persist filter state via URL params ──────────────────────────────────────
$filter_theme    = intval($_GET['filter_theme']    ?? 0);
$filter_subtheme = intval($_GET['filter_subtheme'] ?? 0);
$filter_redirect = '';
if ($filter_theme)    $filter_redirect .= '&filter_theme='    . $filter_theme;
if ($filter_subtheme) $filter_redirect .= '&filter_subtheme=' . $filter_subtheme;
$filter_redirect = $filter_redirect ? '?' . ltrim($filter_redirect, '&') : '';

// Function to convert image to WebP
function convertToWebP($source, $destination, $quality = 90) {
    $info = getimagesize($source);
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

// ─── HANDLE POST ACTIONS ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Create collection ──────────────────────────────────────────────────
    if ($action === 'create_collection') {
        $name        = trim($_POST['name']);
        $slug        = strtolower(str_replace(' ', '-', $name));
        $sub_theme_id = intval($_POST['sub_theme_id']);
        $room_area   = trim($_POST['room_area']);
        $description = trim($_POST['description']);

        $stmt = $conn->prepare("INSERT INTO gallery_collections (name, slug, sub_theme_id, room_area, description) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiss", $name, $slug, $sub_theme_id, $room_area, $description);

        if ($stmt->execute()) {
            $collection_id = $conn->insert_id;

            // Inherit building type connections from sub-theme level
            $inherit_stmt = $conn->prepare("SELECT building_type_id FROM sub_theme_building_types WHERE sub_theme_id = ?");
            $inherit_stmt->bind_param("i", $sub_theme_id);
            $inherit_stmt->execute();
            $inherited = $inherit_stmt->get_result();
            while ($row = $inherited->fetch_assoc()) {
                $ins = $conn->prepare("INSERT IGNORE INTO gallery_collection_connections (collection_id, building_type_id) VALUES (?, ?)");
                $ins->bind_param("ii", $collection_id, $row['building_type_id']);
                $ins->execute();
                $ins->close();
            }
            $inherit_stmt->close();

            // Handle image uploads (max 10)
            $target_dir = "../../realiving_user/images/gallery/";
            if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

            $image_count = 0;
            if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
                foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                    if ($image_count >= 10) break;
                    if ($_FILES['images']['error'][$key] === 0) {
                        $file_name   = uniqid() . '_' . $collection_id . '_' . time() . '.webp';
                        $target_file = $target_dir . $file_name;
                        if (convertToWebP($tmp_name, $target_file)) {
                            $image_path = './images/gallery/' . $file_name;
                            $img_stmt   = $conn->prepare("INSERT INTO gallery_collection_images (collection_id, image_path, display_order) VALUES (?, ?, ?)");
                            $img_stmt->bind_param("isi", $collection_id, $image_path, $image_count);
                            $img_stmt->execute();
                            $img_stmt->close();
                            $image_count++;
                        }
                    }
                }
                $success_message = "Collection created with $image_count image(s)!";
            } else {
                $success_message = "Collection created! Add images from the collection detail page.";
            }
        } else {
            $error_message = "Error creating collection: " . $conn->error;
        }
        $stmt->close();
    }

    // ── Delete collection ──────────────────────────────────────────────────
    if ($action === 'delete_collection') {
        $id   = intval($_POST['id']);
        $imgs = $conn->query("SELECT image_path FROM gallery_collection_images WHERE collection_id = $id");
        while ($img = $imgs->fetch_assoc()) {
            $fp = "../../realiving_user/" . $img['image_path'];
            if (file_exists($fp)) unlink($fp);
        }
        $stmt = $conn->prepare("DELETE FROM gallery_collections WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $success_message = "Collection and all images deleted!";
        } else {
            $error_message = "Failed to delete collection.";
        }
        $stmt->close();
    }

    // ── Save sub-theme building type connections ────────────────────────────
    if ($action === 'save_subtheme_connections') {
        $sub_theme_id    = intval($_POST['sub_theme_id']);
        $building_types  = $_POST['building_types'] ?? [];

        // Delete existing connections for this sub-theme
        $del = $conn->prepare("DELETE FROM sub_theme_building_types WHERE sub_theme_id = ?");
        $del->bind_param("i", $sub_theme_id);
        $del->execute();
        $del->close();

        // Insert new connections
        foreach ($building_types as $bt_id) {
            $bt_id_int = intval($bt_id);
            $ins = $conn->prepare("INSERT IGNORE INTO sub_theme_building_types (sub_theme_id, building_type_id) VALUES (?, ?)");
            $ins->bind_param("ii", $sub_theme_id, $bt_id_int);
            $ins->execute();
            $ins->close();
        }

        // Sync all existing collections under this sub-theme to match
        // First delete all collection connections for collections in this sub-theme
        $sync_del = $conn->prepare("
            DELETE gcc FROM gallery_collection_connections gcc
            JOIN gallery_collections gc ON gcc.collection_id = gc.id
            WHERE gc.sub_theme_id = ?
        ");
        $sync_del->bind_param("i", $sub_theme_id);
        $sync_del->execute();
        $sync_del->close();

        // Re-insert from sub_theme_building_types for all those collections
        $sync_ins = $conn->prepare("
            INSERT INTO gallery_collection_connections (collection_id, building_type_id)
            SELECT gc.id, stbt.building_type_id
            FROM gallery_collections gc
            JOIN sub_theme_building_types stbt ON stbt.sub_theme_id = gc.sub_theme_id
            WHERE gc.sub_theme_id = ?
        ");
        $sync_ins->bind_param("i", $sub_theme_id);
        $sync_ins->execute();
        $sync_ins->close();

        $success_message = "Building type connections updated for all collections in this sub-theme!";
    }
}

// ─── FETCH DATA ─────────────────────────────────────────────────────────────

// All sub-themes (with parent theme name + collection count)
$subthemes_query = "
    SELECT 
        st.id, st.name, st.slug, st.description,
        t.name  AS theme_name,
        t.id    AS theme_id,
        COUNT(DISTINCT gc.id) AS collection_count,
        GROUP_CONCAT(DISTINCT bt.name ORDER BY bt.display_order SEPARATOR ', ') AS connected_building_types,
        GROUP_CONCAT(DISTINCT bt.id   ORDER BY bt.display_order SEPARATOR ',')  AS connected_bt_ids
    FROM sub_themes st
    JOIN themes t ON st.theme_id = t.id
    LEFT JOIN gallery_collections gc ON gc.sub_theme_id = st.id
    LEFT JOIN sub_theme_building_types stbt ON stbt.sub_theme_id = st.id
    LEFT JOIN building_types bt ON bt.id = stbt.building_type_id
    GROUP BY st.id
    ORDER BY t.display_order ASC, st.display_order ASC
";
$subthemes_result = $conn->query($subthemes_query);

// All collections grouped
$collections_query = "
    SELECT 
        gc.*,
        st.name  AS subtheme_name,
        st.id    AS subtheme_id,
        t.name   AS theme_name,
        COUNT(DISTINCT gci.id) AS image_count,
        GROUP_CONCAT(DISTINCT bt.name SEPARATOR ', ') AS building_types
    FROM gallery_collections gc
    JOIN sub_themes st ON gc.sub_theme_id = st.id
    JOIN themes t ON st.theme_id = t.id
    LEFT JOIN gallery_collection_images gci ON gc.id = gci.collection_id
    LEFT JOIN gallery_collection_connections gcc ON gc.id = gcc.collection_id
    LEFT JOIN building_types bt ON gcc.building_type_id = bt.id
    GROUP BY gc.id
    ORDER BY gc.sub_theme_id ASC, gc.created_at DESC
";
$collections_result = $conn->query($collections_query);

// Group collections by sub_theme_id in PHP
$collections_by_subtheme = [];
while ($col = $collections_result->fetch_assoc()) {
    $collections_by_subtheme[$col['subtheme_id']][] = $col;
}

// For dropdowns
$themes_dropdown    = $conn->query("SELECT * FROM themes ORDER BY display_order ASC");
$subthemes_dropdown = $conn->query("SELECT st.*, t.name AS theme_name FROM sub_themes st JOIN themes t ON st.theme_id = t.id ORDER BY t.display_order ASC, st.display_order ASC");
$building_types_all = $conn->query("SELECT * FROM building_types ORDER BY display_order ASC");

// Build subtheme JS data for cascading dropdown
$subthemes_dropdown->data_seek(0);
$subthemes_js = [];
while ($st = $subthemes_dropdown->fetch_assoc()) {
    $subthemes_js[] = ['id' => $st['id'], 'name' => $st['name'], 'theme_id' => $st['theme_id']];
}
$subthemes_dropdown->data_seek(0);

// Build sub-theme connections map for JS (stbt modal pre-fill)
$stbt_map = [];
$stbt_rows = $conn->query("SELECT sub_theme_id, building_type_id FROM sub_theme_building_types");
while ($r = $stbt_rows->fetch_assoc()) {
    $stbt_map[$r['sub_theme_id']][] = intval($r['building_type_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Manage Gallery Collections</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />
    <style>
        :root {
            --purple: #7c3aed;
            --purple-light: #ede9fe;
            --purple-mid: #a78bfa;
            --teal: #0d9488;
            --teal-light: #ccfbf1;
            --amber: #d97706;
            --amber-light: #fef3c7;
            --surface: #f8f7ff;
            --border: #e5e7eb;
            --text: #1e1b2e;
            --muted: #6b7280;
        }
        body { font-family: 'DM Sans', sans-serif; background: var(--surface); color: var(--text); }
        h1, h2, h3, .font-display { font-family: 'Syne', sans-serif; }

        /* ── Modal ── */
        .modal {
            display: none; position: fixed; z-index: 60;
            inset: 0; background: rgba(15,10,30,0.55);
            backdrop-filter: blur(3px);
            overflow-y: auto;
        }
        .modal.active { display: flex; align-items: flex-start; justify-content: center; padding: 48px 20px; }

        /* ── Sub-theme header strip ── */
        .subtheme-header {
            background: linear-gradient(90deg, var(--purple) 0%, #a855f7 100%);
        }

        /* ── Collection card hover ── */
        .collection-card { transition: transform .18s ease, box-shadow .18s ease; }
        .collection-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(124,58,237,.13); }

        /* ── Badge ── */
        .badge-bt {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 10px; border-radius: 999px; font-size: .7rem; font-weight: 600;
            background: #ede9fe; color: #5b21b6;
        }

        /* ── Cascading select hidden ── */
        #subthemeSection { transition: opacity .25s, max-height .3s; }
        #subthemeSection.hidden { opacity: 0; max-height: 0; overflow: hidden; pointer-events: none; }
        #subthemeSection:not(.hidden) { opacity: 1; max-height: 300px; }

        /* ── Checkbox grid ── */
        .bt-checkbox-label {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 12px; border-radius: 8px; border: 1.5px solid var(--border);
            cursor: pointer; transition: border-color .15s, background .15s;
            font-size: .85rem;
        }
        .bt-checkbox-label:has(input:checked) {
            border-color: var(--purple); background: var(--purple-light);
        }
        input[type="checkbox"] { accent-color: var(--purple); width: 16px; height: 16px; }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; } 
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 9px; }
    </style>
</head>
<body>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- ── Page Header ── -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <a href="gallery_dashboard_v2.php" class="inline-flex items-center gap-1.5 text-sm text-purple-600 hover:text-purple-800 mb-3 transition-colors">
                <i class="ri-arrow-left-line"></i> Back to Dashboard
            </a>
            <h1 class="text-3xl font-display text-gray-900">Gallery Collections</h1>
            <p class="text-sm text-gray-500 mt-1">Organized by sub-theme · Building type connections managed per sub-theme · Max 10 images per collection</p>
        </div>
        <button onclick="openCreateModal()"
            class="inline-flex items-center gap-2 bg-purple-600 hover:bg-purple-700 active:bg-purple-800 text-white px-5 py-3 rounded-xl font-semibold shadow transition-colors">
            <i class="ri-add-line text-lg"></i> New Collection
        </button>
    </div>

    <!-- ── Flash Messages ── -->
    <?php if (!empty($success_message)): ?>
        <div class="mb-6 flex items-center gap-3 p-4 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-800 text-sm">
            <i class="ri-checkbox-circle-line text-lg text-emerald-500"></i>
            <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="mb-6 flex items-center gap-3 p-4 bg-red-50 border border-red-200 rounded-xl text-red-800 text-sm">
            <i class="ri-error-warning-line text-lg text-red-500"></i>
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <!-- ── Filter Bar ── -->
    <div class="mb-7 bg-white rounded-2xl border border-gray-200 shadow-sm p-4">
        <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center flex-wrap">
            <span class="text-sm font-semibold text-gray-500 shrink-0"><i class="ri-filter-3-line mr-1"></i>Filter:</span>
            <select id="filterTheme" onchange="applyFilters()"
                class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-purple-400 outline-none bg-white min-w-[150px]">
                <option value="">All Themes</option>
                <?php $themes_dropdown->data_seek(0); while ($th_f = $themes_dropdown->fetch_assoc()): ?>
                    <option value="<?php echo $th_f['id']; ?>">
                        <?php echo htmlspecialchars($th_f['name']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <select id="filterSubtheme" onchange="applyFilters()"
                class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-purple-400 outline-none bg-white min-w-[180px]">
                <option value="">All Sub-Themes</option>
                <?php $subthemes_dropdown->data_seek(0); while ($st_f = $subthemes_dropdown->fetch_assoc()): ?>
                    <option value="<?php echo $st_f['id']; ?>" data-theme-id="<?php echo $st_f['theme_id']; ?>">
                        <?php echo htmlspecialchars($st_f['name']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <button onclick="clearFilters()" class="text-xs text-purple-600 hover:text-purple-800 font-semibold px-3 py-2 rounded-lg hover:bg-purple-50 border border-purple-100 transition-colors">
                <i class="ri-close-line mr-1"></i>Clear Filters
            </button>
            <span id="filterCount" class="text-xs text-gray-400 italic ml-auto"></span>
        </div>
    </div>

    <!-- ── Sub-theme Grouped Sections ── -->
    <?php
    $subthemes_result->data_seek(0);
    $has_any = false;
    while ($st = $subthemes_result->fetch_assoc()):
        $has_any = true;
        $cols = $collections_by_subtheme[$st['id']] ?? [];
        $bt_ids_arr = $st['connected_bt_ids'] ? array_map('intval', explode(',', $st['connected_bt_ids'])) : [];
    ?>

    <div class="mb-10 subtheme-section" data-theme-id="<?php echo $st['theme_id']; ?>" data-subtheme-id="<?php echo $st['id']; ?>">
        <!-- Sub-theme Header -->
        <div class="subtheme-header rounded-t-2xl px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <div class="flex items-center gap-2 text-purple-200 text-xs font-semibold uppercase tracking-widest mb-1">
                    <i class="ri-palette-line"></i>
                    <?php echo htmlspecialchars($st['theme_name']); ?>
                </div>
                <h2 class="text-white text-xl font-display flex items-center gap-2">
                    <i class="ri-brush-line"></i>
                    <?php echo htmlspecialchars($st['name']); ?>
                    <span class="ml-2 text-sm bg-white/20 text-white px-3 py-0.5 rounded-full font-medium">
                        <?php echo count($cols); ?> collection<?php echo count($cols) !== 1 ? 's' : ''; ?>
                    </span>
                </h2>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <!-- Connected building types summary -->
                <div class="flex flex-wrap gap-1.5">
                    <?php if ($st['connected_building_types']): 
                        foreach (explode(', ', $st['connected_building_types']) as $bt_name): ?>
                            <span class="badge-bt"><i class="ri-building-line"></i><?php echo htmlspecialchars($bt_name); ?></span>
                        <?php endforeach;
                    else: ?>
                        <span class="text-xs text-white/60 italic">No building types connected</span>
                    <?php endif; ?>
                </div>
                <!-- Edit connections button -->
                <button
                    onclick='openConnectionsModal(<?php echo $st["id"]; ?>, <?php echo htmlspecialchars(json_encode($bt_ids_arr)); ?>, "<?php echo htmlspecialchars(addslashes($st['name'])); ?>")'
                    class="inline-flex items-center gap-1.5 bg-white/15 hover:bg-white/25 text-white text-xs font-semibold px-3 py-2 rounded-lg border border-white/20 transition-colors">
                    <i class="ri-links-line"></i> Manage Connections
                </button>
            </div>
        </div>

        <!-- Collections grid for this sub-theme -->
        <div class="bg-white border border-gray-200 border-t-0 rounded-b-2xl p-5">
            <?php if (empty($cols)): ?>
                <div class="flex flex-col items-center justify-center py-10 text-center">
                    <div class="w-16 h-16 rounded-2xl bg-purple-50 flex items-center justify-center mb-3">
                        <i class="ri-folder-image-line text-3xl text-purple-300"></i>
                    </div>
                    <p class="text-gray-400 text-sm mb-3">No collections yet under this sub-theme</p>
                    <button onclick="openCreateModalWithSubtheme(<?php echo $st['id']; ?>, '<?php echo htmlspecialchars(addslashes($st['name'])); ?>')"
                        class="inline-flex items-center gap-1.5 text-sm bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition-colors">
                        <i class="ri-add-line"></i> Add Collection Here
                    </button>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                    <?php foreach ($cols as $col):
                        $preview_stmt = $conn->prepare("SELECT image_path FROM gallery_collection_images WHERE collection_id = ? ORDER BY display_order ASC LIMIT 1");
                        $preview_stmt->bind_param("i", $col['id']);
                        $preview_stmt->execute();
                        $preview = $preview_stmt->get_result()->fetch_assoc();
                        $preview_stmt->close();
                        $preview_img = $preview ? "../../realiving_user/" . $preview['image_path'] : '';
                        $is_full = $col['image_count'] >= 10;
                    ?>
                        <div class="collection-card bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
                            <!-- Image preview -->
                            <div class="relative h-36 bg-gray-100 overflow-hidden">
                                <?php if ($preview_img && file_exists($preview_img)): ?>
                                    <img src="<?php echo htmlspecialchars($preview_img); ?>" alt="" class="w-full h-full object-cover" />
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center">
                                        <i class="ri-image-line text-4xl text-gray-200"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="absolute top-2 right-2 flex gap-1.5">
                                    <span class="text-xs font-bold px-2 py-0.5 rounded-full <?php echo $is_full ? 'bg-emerald-500 text-white' : 'bg-purple-500 text-white'; ?>">
                                        <?php echo $col['image_count']; ?>/10<?php echo $is_full ? ' ✓' : ''; ?>
                                    </span>
                                </div>
                            </div>
                            <!-- Card body -->
                            <div class="p-3">
                                <h4 class="font-semibold text-gray-900 text-sm leading-tight mb-0.5"><?php echo htmlspecialchars($col['name']); ?></h4>
                                <?php if (!empty($col['room_area'])): ?>
                                    <p class="text-xs text-purple-600 font-medium mb-1.5">
                                        <i class="ri-home-4-line mr-0.5"></i><?php echo htmlspecialchars($col['room_area']); ?>
                                    </p>
                                <?php endif; ?>
                                <?php if ($col['description']): ?>
                                    <p class="text-xs text-gray-400 line-clamp-1 mb-2"><?php echo htmlspecialchars($col['description']); ?></p>
                                <?php endif; ?>
                                <!-- Actions -->
                                <div class="flex items-center justify-between pt-2 border-t border-gray-100 mt-2">
                                    <a href="manage_collection_details.php?id=<?php echo $col['id']; ?>"
                                        class="inline-flex items-center gap-1 text-xs text-purple-600 hover:text-purple-800 font-semibold transition-colors">
                                        <i class="ri-image-edit-line"></i> Manage Images
                                    </a>
                                    <form method="POST" action="manage_collections.php<?php echo $filter_redirect; ?>" onsubmit="return confirm('Delete this collection and all <?php echo $col['image_count']; ?> images?');" class="inline delete-collection-form">
                                        <input type="hidden" name="action" value="delete_collection" />
                                        <input type="hidden" name="id" value="<?php echo $col['id']; ?>" />
                                        <button type="submit" class="p-1.5 text-red-400 hover:text-red-600 transition-colors" title="Delete">
                                            <i class="ri-delete-bin-line text-sm"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Add collection card -->
                    <button onclick="openCreateModalWithSubtheme(<?php echo $st['id']; ?>, '<?php echo htmlspecialchars(addslashes($st['name'])); ?>')"
                        class="collection-card bg-gray-50 hover:bg-purple-50 border-2 border-dashed border-gray-300 hover:border-purple-400 rounded-xl h-full min-h-[160px] flex flex-col items-center justify-center gap-2 transition-colors cursor-pointer">
                        <div class="w-10 h-10 rounded-full bg-white border-2 border-dashed border-purple-300 flex items-center justify-center">
                            <i class="ri-add-line text-xl text-purple-400"></i>
                        </div>
                        <span class="text-xs text-purple-500 font-semibold">Add Collection</span>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php endwhile; ?>

    <?php if (!$has_any): ?>
        <div class="text-center py-20">
            <i class="ri-folder-image-line text-6xl text-gray-200 mb-4"></i>
            <h3 class="text-xl font-display text-gray-400 mb-2">No sub-themes yet</h3>
            <p class="text-gray-400 text-sm mb-6">Create sub-themes first, then add collections here.</p>
            <a href="manage_themes.php" class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-5 py-3 rounded-xl font-semibold transition-colors">
                <i class="ri-palette-line"></i> Go to Themes
            </a>
        </div>
    <?php endif; ?>

</div><!-- /max-w-7xl -->


<!-- ════════════════════════════════════════════════════════════
     MODAL: Create Collection
═══════════════════════════════════════════════════════════════ -->
<div id="createModal" class="modal">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl mx-4 p-7">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-2xl font-display text-gray-900">New Collection</h3>
            <button onclick="closeModal('createModal')" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors text-gray-400 hover:text-gray-700">
                <i class="ri-close-line text-xl"></i>
            </button>
        </div>

        <form method="POST" action="manage_collections.php<?php echo $filter_redirect; ?>" enctype="multipart/form-data" id="createCollectionForm">
            <input type="hidden" name="action" value="create_collection" />

            <div class="space-y-4">

                <!-- Collection Name -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Collection Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" required
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-400 focus:border-purple-400 outline-none"
                        placeholder="e.g. Modern Living Room, Industrial Kitchen" />
                </div>

                <!-- Theme filter (no name, not submitted) -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Theme <span class="text-red-500">*</span></label>
                    <select id="themeFilter"
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-400 outline-none">
                        <option value="">— Select theme first —</option>
                        <?php $themes_dropdown->data_seek(0); while ($th = $themes_dropdown->fetch_assoc()): ?>
                            <option value="<?php echo $th['id']; ?>"><?php echo htmlspecialchars($th['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <!-- Sub-theme (cascades from theme) -->
                <div id="subthemeSection" class="hidden">
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Sub-Theme <span class="text-red-500">*</span></label>
                    <select name="sub_theme_id" id="subthemeSelect"
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-400 outline-none">
                        <option value="">— Select sub-theme —</option>
                    </select>
                    <!-- Auto-fill building types note -->
                    <div id="btInheritNote" class="mt-2 hidden">
                        <p class="text-xs text-teal-700 bg-teal-50 border border-teal-200 rounded-lg px-3 py-2">
                            <i class="ri-links-line mr-1"></i>
                            <strong>Building type connections</strong> for this sub-theme will be automatically applied to this collection.
                            <span id="btInheritList" class="font-semibold"></span>
                        </p>
                    </div>
                    <div id="btNoConnectionNote" class="mt-2 hidden">
                        <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                            <i class="ri-alert-line mr-1"></i>
                            This sub-theme has no building type connections yet.
                            <a href="#" onclick="closeModal('createModal')" class="underline font-semibold">Set them via "Manage Connections"</a> on the main page first.
                        </p>
                    </div>
                </div>

                <!-- Room Area -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Room Area <span class="text-red-500">*</span></label>
                    <input type="text" name="room_area" required
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-400 outline-none"
                        placeholder="Living Room, Bedroom, Kitchen, Bathroom…" />
                    <p class="text-xs text-gray-400 mt-1">The specific space being showcased</p>
                </div>

                <!-- Description -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Description <span class="text-gray-400 font-normal">(optional)</span></label>
                    <textarea name="description" rows="2"
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-400 outline-none resize-none"
                        placeholder="Brief description…"></textarea>
                </div>

                <!-- Images -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Upload Images <span class="text-gray-400 font-normal">(max 10, optional)</span></label>
                    <input type="file" name="images[]" accept="image/*" multiple
                        class="w-full text-sm file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-purple-50 file:text-purple-700 file:font-semibold hover:file:bg-purple-100 border border-gray-300 rounded-lg py-1.5" />
                    <p class="text-xs text-gray-400 mt-1">Auto-converts to WebP. You can add more images from the detail page.</p>
                </div>

                <button type="submit"
                    class="w-full bg-purple-600 hover:bg-purple-700 active:bg-purple-800 text-white py-3 rounded-xl font-semibold text-sm transition-colors">
                    <i class="ri-folder-add-line mr-2"></i>Create Collection
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ════════════════════════════════════════════════════════════
     MODAL: Sub-theme Building Type Connections
═══════════════════════════════════════════════════════════════ -->
<div id="connectionsModal" class="modal">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 p-7">
        <div class="flex items-center justify-between mb-2">
            <h3 class="text-2xl font-display text-gray-900">Building Type Connections</h3>
            <button onclick="closeModal('connectionsModal')" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors text-gray-400 hover:text-gray-700">
                <i class="ri-close-line text-xl"></i>
            </button>
        </div>
        <p class="text-sm text-gray-500 mb-5">
            Sub-theme: <strong id="connModalSubthemeName" class="text-purple-700"></strong><br/>
            <span class="text-xs">Connections set here apply to <strong>all existing &amp; new collections</strong> under this sub-theme.</span>
        </p>

        <form method="POST" action="manage_collections.php<?php echo $filter_redirect; ?>" id="connectionsForm">
            <input type="hidden" name="action" value="save_subtheme_connections" />
            <input type="hidden" name="sub_theme_id" id="connFormSubthemeId" />

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-6" id="btCheckboxGrid">
                <?php $building_types_all->data_seek(0); while ($bt = $building_types_all->fetch_assoc()): ?>
                    <label class="bt-checkbox-label" data-bt-id="<?php echo $bt['id']; ?>">
                        <input type="checkbox" name="building_types[]" value="<?php echo $bt['id']; ?>" />
                        <i class="<?php echo htmlspecialchars($bt['icon']); ?> text-lg text-indigo-500"></i>
                        <span><?php echo htmlspecialchars($bt['name']); ?></span>
                    </label>
                <?php endwhile; ?>
            </div>

            <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 mb-5 text-xs text-amber-800">
                <i class="ri-information-line mr-1"></i>
                <strong>Note:</strong> Saving will update connections for <em>all</em> existing collections under this sub-theme to match these selections.
            </div>

            <button type="submit"
                class="w-full bg-purple-600 hover:bg-purple-700 text-white py-3 rounded-xl font-semibold text-sm transition-colors">
                <i class="ri-save-line mr-2"></i>Save Connections
            </button>
        </form>
    </div>
</div>


<!-- ═══ JS ═══════════════════════════════════════════════════════════════════ -->
<script>
// ── Data from PHP ────────────────────────────────────────────────────────────
const subthemeData = <?php echo json_encode($subthemes_js); ?>;
const stbtMap      = <?php echo json_encode($stbt_map); ?>;  // { sub_theme_id: [bt_id, ...] }

// ── Modal helpers ────────────────────────────────────────────────────────────
function openCreateModal() {
    document.getElementById('createCollectionForm').reset();
    document.getElementById('subthemeSection').classList.add('hidden');
    document.getElementById('btInheritNote').classList.add('hidden');
    document.getElementById('btNoConnectionNote').classList.add('hidden');
    document.getElementById('createModal').classList.add('active');
}

function openCreateModalWithSubtheme(subthemeId, subthemeName) {
    openCreateModal();
    // Find the parent theme_id for this subtheme
    const st = subthemeData.find(s => s.id == subthemeId);
    if (!st) return;
    // Set theme filter
    const tf = document.getElementById('themeFilter');
    tf.value = st.theme_id;
    tf.dispatchEvent(new Event('change'));
    // After populating, set sub-theme
    setTimeout(() => {
        const ss = document.getElementById('subthemeSelect');
        ss.value = subthemeId;
        ss.dispatchEvent(new Event('change'));
    }, 50);
}

function openConnectionsModal(subthemeId, checkedIds, subthemeName) {
    document.getElementById('connModalSubthemeName').textContent = subthemeName;
    document.getElementById('connFormSubthemeId').value = subthemeId;
    // Reset checkboxes
    document.querySelectorAll('#btCheckboxGrid input[type=checkbox]').forEach(cb => {
        cb.checked = checkedIds.includes(parseInt(cb.value));
    });
    document.getElementById('connectionsModal').classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

window.addEventListener('click', e => {
    if (e.target.classList.contains('modal')) e.target.classList.remove('active');
});

// ── Build BT name map from modal checkboxes ───────────────────────────────────
function buildBtNameMap() {
    const nameMap = {};
    document.querySelectorAll('#btCheckboxGrid label').forEach(l => {
        const cb = l.querySelector('input');
        const sp = l.querySelector('span');
        if (cb && sp) nameMap[parseInt(cb.value)] = sp.textContent.trim();
    });
    return nameMap;
}

// ── Cascading theme → sub-theme dropdown (FORM modal) ────────────────────────
document.getElementById('themeFilter').addEventListener('change', function () {
    const themeId = this.value; // keep as string for comparison
    const ss      = document.getElementById('subthemeSelect');
    const section = document.getElementById('subthemeSection');

    ss.innerHTML = '<option value="">— Select sub-theme —</option>';
    document.getElementById('btInheritNote').classList.add('hidden');
    document.getElementById('btNoConnectionNote').classList.add('hidden');

    if (themeId) {
        // Use == (loose) to avoid string/int mismatch
        const filtered = subthemeData.filter(s => s.theme_id == themeId);
        filtered.forEach(s => {
            const opt       = document.createElement('option');
            opt.value       = s.id;
            opt.textContent = s.name;
            ss.appendChild(opt);
        });
        // Auto-select if only one sub-theme
        if (filtered.length === 1) {
            ss.value = filtered[0].id;
            ss.dispatchEvent(new Event('change'));
        }
        section.classList.remove('hidden');
        ss.setAttribute('required', 'required');
    } else {
        section.classList.add('hidden');
        ss.removeAttribute('required');
    }
});

// ── Sub-theme change → show inherited BT connections ─────────────────────────
document.getElementById('subthemeSelect').addEventListener('change', function () {
    const stId    = parseInt(this.value);
    const noteEl  = document.getElementById('btInheritNote');
    const noConn  = document.getElementById('btNoConnectionNote');
    const listEl  = document.getElementById('btInheritList');

    noteEl.classList.add('hidden');
    noConn.classList.add('hidden');
    if (!stId) return;

    const btIds = stbtMap[stId] || [];
    if (btIds.length > 0) {
        const nameMap      = buildBtNameMap();
        const resolvedNames = btIds.map(id => nameMap[id] || id).join(', ');
        listEl.textContent = resolvedNames ? '(' + resolvedNames + ')' : '';
        noteEl.classList.remove('hidden');
    } else {
        noConn.classList.remove('hidden');
    }
});

// ── Form validation ───────────────────────────────────────────────────────────
document.getElementById('createCollectionForm').addEventListener('submit', function (e) {
    const theme = document.getElementById('themeFilter').value;
    const st    = document.getElementById('subthemeSelect').value;
    if (!theme) { e.preventDefault(); alert('Please select a theme first!'); return; }
    if (!st)    { e.preventDefault(); alert('Please select a sub-theme!'); return; }
});

// ── Filter bar: Theme → cascade sub-theme options ────────────────────────────
document.getElementById('filterTheme').addEventListener('change', function () {
    const themeId  = this.value;
    const stSelect = document.getElementById('filterSubtheme');
    const opts     = stSelect.querySelectorAll('option');

    opts.forEach(opt => {
        if (!opt.value) return;
        opt.hidden = themeId && opt.dataset.themeId != themeId;
    });

    // Reset sub-theme if now hidden
    const selected = stSelect.querySelector(`option[value="${stSelect.value}"]`);
    if (selected && selected.hidden) stSelect.value = '';

    applyFilters();
    pushFilterToUrl();
});

document.getElementById('filterSubtheme').addEventListener('change', function () {
    applyFilters();
    pushFilterToUrl();
});

// ── Push current filter state into URL (no page reload) ──────────────────────
function pushFilterToUrl() {
    const themeId = document.getElementById('filterTheme').value;
    const stId    = document.getElementById('filterSubtheme').value;
    const params  = new URLSearchParams(window.location.search);

    if (themeId) params.set('filter_theme', themeId);
    else         params.delete('filter_theme');

    if (stId) params.set('filter_subtheme', stId);
    else      params.delete('filter_subtheme');

    const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    history.replaceState(null, '', newUrl);

    // Also update all form actions to carry these params
    updateFormActions(themeId, stId);
}

// ── Update all form actions so POST submissions return to same filter ─────────
function updateFormActions(themeId, stId) {
    let qs = '';
    if (themeId) qs += (qs ? '&' : '?') + 'filter_theme='    + themeId;
    if (stId)    qs += (qs ? '&' : '?') + 'filter_subtheme=' + stId;

    // Create collection form
    const cf = document.getElementById('createCollectionForm');
    if (cf) cf.action = 'manage_collections.php' + qs;

    // Connections form
    const connF = document.getElementById('connectionsForm');
    if (connF) connF.action = 'manage_collections.php' + qs;

    // All delete forms inside collection cards
    document.querySelectorAll('.delete-collection-form').forEach(f => {
        f.action = 'manage_collections.php' + qs;
    });
}

// ── Apply filters (show/hide subtheme sections) ───────────────────────────────
function applyFilters() {
    const themeId  = document.getElementById('filterTheme').value;
    const stId     = document.getElementById('filterSubtheme').value;
    const sections = document.querySelectorAll('.subtheme-section');
    let   visible  = 0;

    sections.forEach(sec => {
        const matchT  = !themeId || sec.dataset.themeId  == themeId;
        const matchSt = !stId    || sec.dataset.subthemeId == stId;
        if (matchT && matchSt) {
            sec.style.display = '';
            visible++;
        } else {
            sec.style.display = 'none';
        }
    });

    const countEl = document.getElementById('filterCount');
    countEl.textContent = (themeId || stId)
        ? visible + ' sub-theme' + (visible !== 1 ? 's' : '') + ' shown'
        : '';
}

function clearFilters() {
    document.getElementById('filterTheme').value    = '';
    document.getElementById('filterSubtheme').value = '';
    document.querySelectorAll('#filterSubtheme option').forEach(o => o.hidden = false);
    applyFilters();
    pushFilterToUrl();
}

// ── Restore filters from URL on page load ────────────────────────────────────
(function restoreFiltersFromUrl() {
    const params  = new URLSearchParams(window.location.search);
    const themeId = params.get('filter_theme')    || '';
    const stId    = params.get('filter_subtheme') || '';

    if (themeId) {
        // Set theme filter
        const tf = document.getElementById('filterTheme');
        tf.value = themeId;

        // Hide irrelevant sub-theme options
        document.querySelectorAll('#filterSubtheme option').forEach(opt => {
            if (!opt.value) return;
            opt.hidden = opt.dataset.themeId != themeId;
        });
    }

    if (stId) {
        document.getElementById('filterSubtheme').value = stId;
    }

    if (themeId || stId) {
        applyFilters();
        updateFormActions(themeId, stId);

        // Scroll to first visible section smoothly
        setTimeout(() => {
            const first = document.querySelector('.subtheme-section:not([style*="none"])');
            if (first) first.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);
    }
})();
</script>

</body>
</html>
<?php $conn->close(); ?>