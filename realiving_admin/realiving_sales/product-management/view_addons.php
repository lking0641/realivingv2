<?php
//view_addons.php
include $includes ['mainbody'];

// Allow only admin1 to admin5
require_role(['sales', 'designer']);

// Extra check: if designer, only heads can access
if ($_SESSION['admin_role'] === 'designer') {
    $headCheck = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
    $headCheck->bind_param("i", $_SESSION['admin_id']);
    $headCheck->execute();
    $headRow = $headCheck->get_result()->fetch_assoc();
    $headCheck->close();

    if (empty($headRow['is_head'])) {
        $_SESSION['noti'] = 'Access Denied: Only head designers can access this page.';
        header("Location:" . BASE_URL . "designer-layout-list");
        exit();
    }
}

// Get filter parameters
$filter_category = isset($_GET['category']) ? $_GET['category'] : 'all';
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';

// Fetch all unique addon categories for primary filter
$categories_query = "SELECT DISTINCT addon_category FROM product_addons WHERE addon_category IS NOT NULL AND addon_category != '' ORDER BY addon_category";
$categories_result = $conn->query($categories_query);
$categories = [];
if ($categories_result) {
    while ($row = $categories_result->fetch_assoc()) {
        $categories[] = $row['addon_category'];
    }
}

// Fetch types for the selected category
$types = [];
if ($filter_category !== 'all') {
    $types_query = "SELECT DISTINCT addon_type FROM product_addons WHERE addon_category = ? AND addon_type IS NOT NULL AND addon_type != '' ORDER BY addon_type";
    $types_stmt = $conn->prepare($types_query);
    $types_stmt->bind_param("s", $filter_category);
    $types_stmt->execute();
    $types_result = $types_stmt->get_result();
    while ($row = $types_result->fetch_assoc()) {
        $types[] = $row['addon_type'];
    }
    $types_stmt->close();
}

// Build query based on filters
$query = "SELECT 
    id,
    addon_name,
    addon_price,
    addon_description,
    addon_category,
    addon_type,
    addon_image_path,
    created_at
FROM product_addons";

$where_conditions = [];
$params = [];
$param_types = '';

if ($filter_category !== 'all') {
    $where_conditions[] = "addon_category = ?";
    $params[] = $filter_category;
    $param_types .= 's';
}

if ($filter_type !== 'all') {
    $where_conditions[] = "addon_type = ?";
    $params[] = $filter_type;
    $param_types .= 's';
}

if (!empty($where_conditions)) {
    $query .= " WHERE " . implode(" AND ", $where_conditions);
}

$query .= " ORDER BY addon_name ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Accessories - RealLiving</title>
    <link rel="icon" type="image/png" sizes="32x32" href="../../logo/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --adm-bg:#F5F5F5;
            --adm-surface:#FFFFFF;
            --adm-ink:#0B0B0B;
            --adm-soft:#6B6B6B;
            --adm-muted:#9A9A9A;
            --adm-line:#E2E2E2;
        }

        body{
            font-family:'Inter', sans-serif;
            background: var(--adm-bg);
            color: var(--adm-ink);
        }

        /* ── Header ─────────────────────────────── */
        .adm-eyebrow{ font-size:11px; font-weight:600; letter-spacing:1.5px; text-transform:uppercase; color: var(--adm-soft); }
        .adm-title{ font-size:26px; font-weight:700; letter-spacing:-0.01em; color: var(--adm-ink); }
        .adm-subtitle{ font-size:13px; color: var(--adm-soft); }
        .adm-back{
            font-size:12.5px; font-weight:600; color: var(--adm-soft);
            display:inline-flex; align-items:center; gap:8px;
            margin-bottom:1rem;
            transition: color .2s ease, gap .2s ease;
        }
        .adm-back:hover{ color: var(--adm-ink); gap:11px; }

        /* ── Buttons ────────────────────────────── */
        .adm-btn{
            display:inline-flex; align-items:center; gap:8px;
            background: var(--adm-ink); color:#fff;
            font-size:13px; font-weight:600;
            padding:.75rem 1.25rem; border-radius:9px;
            border:1px solid var(--adm-ink);
            transition: opacity .2s ease, transform .2s ease;
        }
        .adm-btn:hover{ opacity:.85; transform: translateY(-1px); color:#fff; }
        .adm-btn-outline{
            display:inline-flex; align-items:center; gap:8px;
            background: var(--adm-surface); color: var(--adm-ink);
            font-size:13px; font-weight:600;
            padding:.7rem 1.15rem; border-radius:9px;
            border:1px solid var(--adm-line);
            transition: border-color .2s ease, transform .2s ease;
        }
        .adm-btn-outline:hover{ border-color: var(--adm-ink); transform: translateY(-1px); }

        /* ── Cards / sections ───────────────────── */
        .adm-card{
            background: var(--adm-surface);
            border:1px solid var(--adm-line);
            border-radius:12px;
        }

        /* ── Alerts ─────────────────────────────── */
        .adm-alert{
            display:flex; align-items:flex-start; gap:.9rem;
            background: var(--adm-surface);
            border:1px solid var(--adm-line);
            border-radius:10px; padding:1.1rem 1.25rem;
        }
        .adm-alert.is-success{ border-left:3px solid #16A34A; }
        .adm-alert.is-error{ border-left:3px solid #DC2626; }
        .adm-alert-icon{
            width:36px; height:36px; border-radius:999px; flex-shrink:0;
            display:flex; align-items:center; justify-content:center; font-size:15px;
        }
        .adm-alert.is-success .adm-alert-icon{ background:#ECFDF3; color:#16A34A; }
        .adm-alert.is-error .adm-alert-icon{ background:#FEF2F2; color:#DC2626; }

        /* ── Filter pills ───────────────────────── */
        .adm-section-icon{
            width:34px; height:34px; border-radius:9px;
            background: var(--adm-ink); color:#fff;
            display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0;
        }
        .adm-section-title{ font-size:15px; font-weight:700; color: var(--adm-ink); }
        .adm-section-hint{ font-size:12px; color: var(--adm-muted); }

        .adm-pill{
            display:inline-flex; align-items:center; gap:6px;
            font-size:12.5px; font-weight:600;
            padding:.55rem 1rem; border-radius:9px;
            border:1px solid var(--adm-line);
            background: var(--adm-bg); color: var(--adm-soft);
            transition: background .2s ease, color .2s ease, border-color .2s ease, transform .2s ease;
            cursor:pointer;
        }
        .adm-pill:hover{ transform: translateY(-1px); }
        .adm-pill.is-ink{ background: var(--adm-ink); color:#fff; border-color: var(--adm-ink); }

        /* ── Active filters ─────────────────────── */
        .adm-active-filters{
            background: var(--adm-surface); border:1px solid var(--adm-line); border-left:3px solid var(--adm-ink);
            border-radius:10px; padding:1rem 1.1rem;
        }
        .adm-chip-outline{
            display:inline-flex; align-items:center; gap:5px;
            font-size:11px; font-weight:600;
            padding:.35rem .7rem; border-radius:7px;
            background: var(--adm-bg); color: var(--adm-ink);
            border:1px solid var(--adm-line);
        }
        .adm-clear-link{
            display:inline-flex; align-items:center; gap:6px;
            font-size:12.5px; font-weight:600; color:#DC2626;
            background: var(--adm-surface); border:1px solid var(--adm-line);
            padding:.5rem .9rem; border-radius:8px;
            transition: background .2s ease, border-color .2s ease;
        }
        .adm-clear-link:hover{ background:#FEF2F2; border-color:#FECACA; }

        .adm-results-bar{
            background: var(--adm-surface); border:1px solid var(--adm-line);
            border-radius:10px; padding:.9rem 1.1rem;
        }

        /* ── Addon cards ─────────────────────────── */
        .addon-card {
            transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
            background: var(--adm-surface);
            border-radius: 12px;
            border: 1px solid var(--adm-line);
            overflow: hidden;
            display:flex; flex-direction:column;
        }
        .addon-card:hover {
            border-color: var(--adm-ink);
            transform: translateY(-4px);
            box-shadow: 0 16px 34px -20px rgba(11, 11, 11, 0.3);
        }
        .addon-image {
            width: 100%;
            height: 190px;
            object-fit: cover;
            background: var(--adm-bg);
            display:block;
        }
        .addon-placeholder{
            width:100%; height:190px; background: var(--adm-bg);
            display:flex; align-items:center; justify-content:center;
        }
        .addon-placeholder i{ font-size:2.5rem; color: var(--adm-muted); }
        .category-badge {
            position: absolute; top: 10px; right: 10px;
            background: var(--adm-ink); color: white;
            padding: 5px 12px; border-radius: 7px;
            font-size: 10.5px; font-weight: 700; letter-spacing:.2px;
            z-index: 10;
        }
        .adm-card-body{ padding:1.1rem 1.25rem 1.25rem; display:flex; flex-direction:column; flex:1; }
        .adm-addon-name{ font-size:14.5px; font-weight:700; color: var(--adm-ink); margin-bottom:.5rem; line-height:1.4;
            display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; min-height:38px; }
        .adm-addon-desc{ font-size:12px; color: var(--adm-soft); margin-bottom:.75rem; line-height:1.5;
            display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; min-height:36px; }
        .adm-type-chip{
            display:inline-flex; align-items:center; gap:6px; justify-content:center;
            background: var(--adm-bg); color: var(--adm-ink); border:1px solid var(--adm-line);
            padding:.4rem .7rem; border-radius:7px; font-size:11px; font-weight:700;
            margin-bottom:.75rem;
        }
        .adm-price-row{ padding:.75rem; border-radius:9px; background: var(--adm-ink); color:#fff;
            text-align:center; font-size:18px; font-weight:800; margin-bottom:.6rem; }
        .adm-addon-date{ text-align:center; font-size:10.5px; color: var(--adm-muted); margin-bottom:.85rem; }

        .adm-action-row{ display:grid; grid-template-columns:1fr 1fr auto; gap:.5rem; }
        .adm-mini-btn{
            display:flex; align-items:center; justify-content:center; gap:6px;
            padding:.55rem .6rem; border-radius:8px;
            font-size:11.5px; font-weight:600;
            border:1px solid var(--adm-line); background: var(--adm-surface); color: var(--adm-ink);
            transition: background .2s ease, border-color .2s ease, transform .2s ease;
            cursor:pointer;
        }
        .adm-mini-btn:hover{ transform: translateY(-1px); border-color: var(--adm-ink); }
        .adm-mini-btn.is-primary{ background: var(--adm-ink); color:#fff; border-color: var(--adm-ink); }
        .adm-mini-btn.is-primary:hover{ opacity:.85; }
        .adm-mini-btn.is-delete{ background:#FEF2F2; border-color:#FECACA; color:#DC2626; flex:0 0 auto; padding:.55rem .75rem; }
        .adm-mini-btn.is-delete:hover{ border-color:#DC2626; }

        /* ── Empty state ─────────────────────────── */
        .adm-empty-state{
            background: var(--adm-surface); border:1px dashed var(--adm-line);
            border-radius:14px; padding:4rem 1.5rem; text-align:center;
        }
        .adm-empty-icon{
            width:80px; height:80px; border-radius:999px; background: var(--adm-bg);
            display:flex; align-items:center; justify-content:center; margin:0 auto 1.5rem;
            color: var(--adm-muted); font-size:2rem;
        }

        @keyframes adm-fade{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }
        .adm-fade{ animation: adm-fade .4s ease both; }
        @media (prefers-reduced-motion: reduce){ .adm-fade{ animation:none; } }
    </style>
</head>
<body class="min-h-screen">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-10 max-w-7xl">

        <!-- Flash Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="adm-alert is-success mb-6">
                <div class="adm-alert-icon"><i class="fas fa-check"></i></div>
                <div class="flex-1 text-sm font-semibold" style="color:var(--adm-ink);">
                    <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="adm-alert is-error mb-6">
                <div class="adm-alert-icon"><i class="fas fa-exclamation"></i></div>
                <div class="flex-1 text-sm font-semibold" style="color:var(--adm-ink);">
                    <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="adm-card p-6 sm:p-8 mb-6 adm-fade">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <a href="<?= BASE_URL ?>choose" class="adm-back">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back</span>
                    </a>
                    <div class="adm-eyebrow mb-2">Catalog</div>
                    <h1 class="adm-title">Product Accessories</h1>
                    <p class="adm-subtitle mt-1">Browse and manage add-ons and accessories by category.</p>
                </div>
                <a href="<?= BASE_URL ?>add-addon" class="adm-btn">
                    <i class="fas fa-plus"></i>
                    <span>Add New Accessory</span>
                </a>
            </div>
        </div>

        <!-- Filter Sections -->
        <div class="space-y-4">
            <!-- Category Filter -->
            <div class="adm-card p-6 adm-fade">
                <div class="flex items-center gap-3 mb-4">
                    <div class="adm-section-icon"><i class="fas fa-tags"></i></div>
                    <div>
                        <div class="adm-section-title">Select Category</div>
                        <div class="adm-section-hint">Filter accessories by category</div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="?category=all" class="adm-pill <?php echo $filter_category === 'all' ? 'is-ink' : ''; ?>">
                        <i class="fas fa-th-large"></i> All Categories
                    </a>
                    <?php foreach ($categories as $category): ?>
                        <a href="?category=<?php echo urlencode($category); ?>"
                           class="adm-pill <?php echo $filter_category === $category ? 'is-ink' : ''; ?>">
                            <i class="fas fa-tag"></i>
                            <?php echo htmlspecialchars(ucfirst($category)); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Type Filter -->
            <?php if ($filter_category !== 'all' && !empty($types)): ?>
            <div class="adm-card p-6 adm-fade">
                <div class="flex items-center gap-3 mb-4">
                    <div class="adm-section-icon"><i class="fas fa-layer-group"></i></div>
                    <div>
                        <div class="adm-section-title">Select Type</div>
                        <div class="adm-section-hint">Within selected category</div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="?category=<?php echo urlencode($filter_category); ?>&type=all"
                       class="adm-pill <?php echo $filter_type === 'all' ? 'is-ink' : ''; ?>">
                        <i class="fas fa-th-large"></i> All Types
                    </a>
                    <?php foreach ($types as $type): ?>
                        <a href="?category=<?php echo urlencode($filter_category); ?>&type=<?php echo urlencode($type); ?>"
                           class="adm-pill <?php echo $filter_type === $type ? 'is-ink' : ''; ?>">
                            <i class="fas fa-layer-group"></i>
                            <?php echo htmlspecialchars(ucfirst($type)); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Active Filters Display -->
            <?php if ($filter_category !== 'all' || $filter_type !== 'all'): ?>
                <div class="adm-active-filters adm-fade">
                    <div class="flex items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center gap-3 flex-wrap">
                            <i class="fas fa-circle-check" style="color:var(--adm-ink);"></i>
                            <span class="font-bold text-sm" style="color:var(--adm-ink);">Active Filters:</span>
                            <div class="flex gap-2 flex-wrap">
                                <?php if ($filter_category !== 'all'): ?>
                                    <span class="adm-chip-outline"><i class="fas fa-tag"></i> <?php echo htmlspecialchars(ucfirst($filter_category)); ?></span>
                                <?php endif; ?>
                                <?php if ($filter_type !== 'all'): ?>
                                    <span class="adm-chip-outline"><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars(ucfirst($filter_type)); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="?category=all" class="adm-clear-link">
                            <i class="fas fa-xmark"></i> Clear All Filters
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Results Count -->
        <div class="adm-results-bar mb-6 mt-6 adm-fade">
            <div class="flex items-center gap-3">
                <i class="fas fa-puzzle-piece" style="color:var(--adm-ink);"></i>
                <span class="text-sm font-semibold" style="color:var(--adm-ink);">
                    Showing <?php echo $result->num_rows; ?> accessory(ies)
                </span>
            </div>
        </div>

        <!-- Addons Grid -->
        <?php if ($result->num_rows > 0): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 adm-fade">
                <?php while ($row = $result->fetch_assoc()): ?>
                    <div class="addon-card">
                        <!-- Addon Image -->
                        <div class="relative">
                            <?php if (!empty($row['addon_image_path'])): ?>
                                <img src="<?= BASE_URL ?>/realiving_user/images/product_addons/<?php echo htmlspecialchars($row['addon_image_path']); ?>"
                                    alt="<?php echo htmlspecialchars($row['addon_name']); ?>"
                                    class="addon-image">
                            <?php else: ?>
                                <div class="addon-placeholder">
                                    <i class="fas fa-puzzle-piece"></i>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($row['addon_category'])): ?>
                                <div class="category-badge">
                                    <i class="fas fa-tag"></i>
                                    <?php echo htmlspecialchars(ucfirst($row['addon_category'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Addon Content -->
                        <div class="adm-card-body">
                            <h3 class="adm-addon-name"><?php echo htmlspecialchars($row['addon_name']); ?></h3>

                            <p class="adm-addon-desc"><?php echo htmlspecialchars($row['addon_description']); ?></p>

                            <?php if (!empty($row['addon_type'])): ?>
                                <div class="adm-type-chip">
                                    <i class="fas fa-layer-group"></i>
                                    <?php echo htmlspecialchars(ucfirst($row['addon_type'])); ?>
                                </div>
                            <?php endif; ?>

                            <div class="adm-price-row">
                                ₱<?php echo number_format($row['addon_price'], 2); ?>
                            </div>

                            <div class="adm-addon-date">
                                <i class="fas fa-clock"></i>
                                Added: <?php echo date('M d, Y', strtotime($row['created_at'])); ?>
                            </div>

                            <div class="adm-action-row">
                                <button onclick="viewAddon(<?php echo $row['id']; ?>)" class="adm-mini-btn is-primary">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button onclick="editAddon(<?php echo $row['id']; ?>)" class="adm-mini-btn">
                                    <i class="fas fa-pen"></i> Edit
                                </button>
                                <button onclick="deleteAddon(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['addon_name']); ?>')" class="adm-mini-btn is-delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="adm-empty-state">
                <div class="adm-empty-icon"><i class="fas fa-puzzle-piece"></i></div>
                <h3 class="text-lg font-bold mb-2" style="color:var(--adm-ink);">No Accessories Found</h3>
                <p class="text-sm mb-6" style="color:var(--adm-soft);">
                    <?php 
                    if ($filter_type !== 'all') {
                        echo "No accessories found with type '" . htmlspecialchars(ucfirst($filter_type)) . "' in the '" . htmlspecialchars(ucfirst($filter_category)) . "' category.";
                    } elseif ($filter_category !== 'all') {
                        echo "No accessories found in the '" . htmlspecialchars(ucfirst($filter_category)) . "' category.";
                    } else {
                        echo "No accessories have been added yet. Start by creating your first accessory!";
                    }
                    ?>
                </p>
                <div class="flex items-center justify-center gap-3 flex-wrap">
                    <?php if ($filter_category !== 'all'): ?>
                        <a href="?category=all" class="adm-btn-outline">
                            <i class="fas fa-rotate-left"></i> Clear Filter
                        </a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>add-addon" class="adm-btn">
                        <i class="fas fa-plus"></i> Add New Accessory
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function viewAddon(addonId) {
            window.location.href = 'view-addon-details?id=' + addonId;
        }

        function editAddon(addonId) {
            window.location.href = 'edit-addon?id=' + addonId;
        }

        function deleteAddon(addonId, addonName) {
            if (confirm(`Are you sure you want to delete "${addonName}"?\n\nThis action cannot be undone.`)) {
                window.location.href = 'delete-insert-addon?id=' + addonId;
            }
        }
    </script>
</body>
</html>