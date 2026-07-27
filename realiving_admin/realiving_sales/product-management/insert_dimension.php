<?php
// insert_dimension.php
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
        header("Location: " . BASE_URL . "designer-ayout-ist");
        exit();
    }
}

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

// Handle DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = intval($_POST['delete_id']);
    $del_stmt = $conn->prepare("DELETE FROM dimension_label WHERE dimension_label_id = ?");
    $del_stmt->bind_param("i", $delete_id);
    if ($del_stmt->execute()) {
        echo "<script>alert('Dimension Label deleted successfully'); window.location.href='insert-dimension';</script>";
    } else {
        echo "<script>alert('Error deleting record.');</script>";
    }
    $del_stmt->close();
}

// Handle UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    $edit_id = intval($_POST['edit_id']);
    $name = $_POST['dimension_label_name'];
    $wll  = $_POST['item_width_label_linear'];
    $hll  = $_POST['item_height_label_linear'];
    $lll  = $_POST['item_length_label_linear'];
    $wsl  = $_POST['item_width_label_sqm'];
    $hsl  = $_POST['item_height_label_sqm'];
    $lsl  = $_POST['item_length_label_sqm'];

    $upd = $conn->prepare("UPDATE dimension_label SET
        dimension_label_name = ?,
        item_width_label_linear = ?,
        item_height_label_linear = ?,
        item_length_label_linear = ?,
        item_width_label_sqm = ?,
        item_height_label_sqm = ?,
        item_length_label_sqm = ?
        WHERE dimension_label_id = ?");
    $upd->bind_param("sssssssi", $name, $wll, $hll, $lll, $wsl, $hsl, $lsl, $edit_id);

    if ($upd->execute()) {
        echo "<script>alert('Dimension Label updated successfully'); window.location.href='insert-dimension';</script>";
    } else {
        echo "<script>alert('Error updating record.');</script>";
    }
    $upd->close();
}

// Handle INSERT (only when edit_id is NOT present)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dimension_label_name']) && !isset($_POST['edit_id'])) {
    $name = $_POST['dimension_label_name'];
    $wll  = $_POST['item_width_label_linear'];
    $hll  = $_POST['item_height_label_linear'];
    $lll  = $_POST['item_length_label_linear'];
    $wsl  = $_POST['item_width_label_sqm'];
    $hsl  = $_POST['item_height_label_sqm'];
    $lsl  = $_POST['item_length_label_sqm'];

    $stmt = $conn->prepare("INSERT INTO dimension_label (
        dimension_label_name,
        item_width_label_linear,
        item_height_label_linear,
        item_length_label_linear,
        item_width_label_sqm,
        item_height_label_sqm,
        item_length_label_sqm
    ) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssss", $name, $wll, $hll, $lll, $wsl, $hsl, $lsl);

    if ($stmt->execute()) {
        echo "<script>alert('Dimension Label inserted successfully'); window.location.href='insert-dimension';</script>";
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();
}

// Fetch all dimension labels for the list
$list_result = $conn->query("SELECT * FROM dimension_label ORDER BY dimension_label_name ASC");
$dimension_list = [];
if ($list_result) {
    while ($row = $list_result->fetch_assoc()) {
        $dimension_list[] = $row;
    }
}
?>

<?php
$edit_data = null;
if (isset($_GET['edit_id'])) {
    $eid = intval($_GET['edit_id']);
    $es = $conn->prepare("SELECT * FROM dimension_label WHERE dimension_label_id = ?");
    $es->bind_param("i", $eid);
    $es->execute();
    $edit_data = $es->get_result()->fetch_assoc();
    $es->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dimension Labels - RealLiving</title>
    <link rel="icon" type="image/png" sizes="32x32" href="../../logo/favicon.ico">
    <script src="https://cdn.tailwindcss.com"></script>
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
            cursor:pointer;
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

        .adm-section-icon{
            width:34px; height:34px; border-radius:9px;
            background: var(--adm-ink); color:#fff;
            display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0;
        }
        .adm-section-title{ font-size:15px; font-weight:700; color: var(--adm-ink); }
        .adm-section-hint{ font-size:12px; color: var(--adm-muted); }

        /* ── Form elements ──────────────────────── */
        .adm-label{
            display:block; font-size:11.5px; font-weight:700; letter-spacing:.3px; text-transform:uppercase;
            color: var(--adm-soft); margin-bottom:.5rem;
        }
        .adm-input, .adm-select-full{
            width:100%; padding:.75rem .9rem; border-radius:9px;
            border:1px solid var(--adm-line); background: var(--adm-bg);
            font-size:13.5px; font-weight:500; color: var(--adm-ink);
            transition: border-color .2s ease, background .2s ease;
        }
        .adm-input:focus, .adm-select-full:focus{
            outline:none; border-color: var(--adm-ink); background: var(--adm-surface);
        }
        .adm-input::placeholder{ color: var(--adm-muted); }
        .adm-subsection-title{
            display:flex; align-items:center; gap:8px;
            font-size:13px; font-weight:700; color: var(--adm-ink);
            margin-bottom:1rem;
        }
        .adm-subsection-title i{ color: var(--adm-soft); font-size:13px; }

        .adm-divider{ border-top:1px solid var(--adm-line); margin:1.75rem 0; }

        /* ── Table ──────────────────────────────── */
        .adm-table-wrap{
            border:1px solid var(--adm-line); border-radius:12px;
            overflow-x:auto; overflow-y:hidden;
            -webkit-overflow-scrolling:touch;
        }
        table.adm-table{ width:100%; min-width:820px; font-size:12.5px; text-align:left; border-collapse:collapse; }
        table.adm-table thead tr{ background: var(--adm-ink); }
        table.adm-table thead th{
            padding:.75rem .85rem; font-weight:700; font-size:10.5px; letter-spacing:.3px; text-transform:uppercase;
            color:#fff; white-space:nowrap;
        }
        table.adm-table thead th span{ font-weight:500; text-transform:none; letter-spacing:0; opacity:.65; font-size:10px; }
        table.adm-table tbody tr{ border-bottom:1px solid var(--adm-line); transition: background .15s ease; }
        table.adm-table tbody tr:last-child{ border-bottom:none; }
        table.adm-table tbody tr:nth-child(even){ background: var(--adm-bg); }
        table.adm-table tbody tr:hover{ background:#F0F0F0; }
        table.adm-table tbody td{ padding:.75rem .85rem; color: var(--adm-ink); vertical-align:middle; white-space:nowrap; }
        table.adm-table tbody td:last-child{ text-align:center; }
        .adm-row-index{ color: var(--adm-muted); font-weight:600; }
        .adm-row-name{ font-weight:700; color: var(--adm-ink); }
        .adm-row-value{ color: var(--adm-soft); }

        .adm-mini-btn{
            display:inline-flex; align-items:center; justify-content:center; gap:6px;
            padding:.45rem .8rem; border-radius:8px;
            font-size:11.5px; font-weight:600;
            border:1px solid var(--adm-line); background: var(--adm-surface); color: var(--adm-ink);
            transition: background .2s ease, border-color .2s ease, transform .2s ease;
            cursor:pointer;
        }
        .adm-mini-btn:hover{ transform: translateY(-1px); border-color: var(--adm-ink); }
        .adm-mini-btn.is-edit{ background:#EFF6FF; border-color:#BFDBFE; color:#2563EB; }
        .adm-mini-btn.is-edit:hover{ border-color:#2563EB; }
        .adm-mini-btn.is-delete{ background:#FEF2F2; border-color:#FECACA; color:#DC2626; }
        .adm-mini-btn.is-delete:hover{ border-color:#DC2626; }

        /* ── Empty state ─────────────────────────── */
        .adm-empty-state{
            background: var(--adm-surface); border:1px dashed var(--adm-line);
            border-radius:14px; padding:3.5rem 1.5rem; text-align:center;
        }
        .adm-empty-icon{
            width:70px; height:70px; border-radius:999px; background: var(--adm-bg);
            display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem;
            color: var(--adm-muted); font-size:1.75rem;
        }

        @keyframes adm-fade{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }
        .adm-fade{ animation: adm-fade .4s ease both; }
        @media (prefers-reduced-motion: reduce){ .adm-fade{ animation:none; } }
    </style>
</head>

<body class="min-h-screen">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-10 max-w-6xl">

        <!-- Header -->
        <div class="adm-card p-6 sm:p-8 mb-6 adm-fade">
            <a href="<?= BASE_URL ?>choose" class="adm-back">
                <i class="fas fa-arrow-left"></i>
                <span>Back</span>
            </a>
            <div class="adm-eyebrow mb-2">Catalog Settings</div>
            <h1 class="adm-title"><?= $edit_data ? 'Edit Dimension Label' : 'Add Dimension Label' ?></h1>
            <p class="adm-subtitle mt-1">Define reusable width, height, and length labels for product dimensions.</p>
        </div>

        <!-- INSERT / EDIT FORM -->
        <div class="adm-card p-6 sm:p-8 mb-6 adm-fade">
            <form action="insert-dimension" method="POST" class="space-y-6">
                <?php if ($edit_data): ?>
                    <input type="hidden" name="edit_id" value="<?= $edit_data['dimension_label_id'] ?>">
                <?php endif; ?>

                <!-- Dimension Label Name -->
                <div>
                    <label class="adm-label">Dimension Label Name</label>
                    <input type="text" name="dimension_label_name" required
                           placeholder="e.g. Cabinet, Closet..."
                           value="<?= $edit_data ? htmlspecialchars($edit_data['dimension_label_name']) : '' ?>"
                           class="adm-input">
                </div>

                <div class="adm-divider"></div>

                <!-- Linear Dimensions -->
                <div>
                    <div class="adm-subsection-title">
                        <div class="adm-section-icon" style="width:28px;height:28px;font-size:12px;">
                            <i class="fas fa-ruler"></i>
                        </div>
                        Linear Dimensions
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <?php
                        $linear_fields = [
                            'item_width_label_linear'  => 'Width Label (Linear)',
                            'item_height_label_linear' => 'Height Label (Linear)',
                            'item_length_label_linear' => 'Length Label (Linear)',
                        ];
                        foreach ($linear_fields as $field => $label): ?>
                        <div>
                            <label class="adm-label"><?= $label ?></label>
                            <select name="<?= $field ?>" required class="adm-select-full">
                                <option value="">Select</option>
                                <?php foreach (['Width','Height','Length','Depth'] as $opt): ?>
                                    <option value="<?= $opt ?>" <?= ($edit_data && $edit_data[$field] === $opt) ? 'selected' : '' ?>>
                                        <?= $opt ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="adm-divider"></div>

                <!-- SQM Dimensions -->
                <div>
                    <div class="adm-subsection-title">
                        <div class="adm-section-icon" style="width:28px;height:28px;font-size:12px;">
                            <i class="fas fa-vector-square"></i>
                        </div>
                        SQM Dimensions
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <?php
                        $sqm_fields = [
                            'item_width_label_sqm'  => 'Width Label (SQM)',
                            'item_height_label_sqm' => 'Height Label (SQM)',
                            'item_length_label_sqm' => 'Length Label (SQM)',
                        ];
                        foreach ($sqm_fields as $field => $label): ?>
                        <div>
                            <label class="adm-label"><?= $label ?></label>
                            <select name="<?= $field ?>" required class="adm-select-full">
                                <option value="">Select</option>
                                <?php foreach (['Width','Height','Length','Depth'] as $opt): ?>
                                    <option value="<?= $opt ?>" <?= ($edit_data && $edit_data[$field] === $opt) ? 'selected' : '' ?>>
                                        <?= $opt ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="adm-divider"></div>

                <!-- Submit -->
                <div class="flex items-center justify-center gap-3">
                    <button type="submit" class="adm-btn">
                        <i class="fas fa-save"></i>
                        <span><?= $edit_data ? 'Update Dimension Label' : 'Save Dimension Label' ?></span>
                    </button>
                    <?php if ($edit_data): ?>
                        <a href="insert-dimension" class="adm-btn-outline">
                            <i class="fas fa-xmark"></i>
                            <span>Cancel</span>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- DIMENSION LABELS LIST -->
        <div class="adm-card p-6 sm:p-8 adm-fade">
            <div class="flex items-center gap-3 mb-5">
                <div class="adm-section-icon"><i class="fas fa-list-check"></i></div>
                <div>
                    <div class="adm-section-title">Dimension Labels List</div>
                    <div class="adm-section-hint">All labels currently available for product dimensions</div>
                </div>
            </div>

            <?php if (empty($dimension_list)): ?>
                <div class="adm-empty-state">
                    <div class="adm-empty-icon"><i class="fas fa-inbox"></i></div>
                    <h3 class="text-sm font-bold mb-1" style="color:var(--adm-ink);">No Dimension Labels Yet</h3>
                    <p class="text-xs" style="color:var(--adm-muted);">Add your first dimension label using the form above.</p>
                </div>
            <?php else: ?>
                <div class="adm-table-wrap">
                    <table class="adm-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Label Name</th>
                                <th>Width <span>(Linear)</span></th>
                                <th>Height <span>(Linear)</span></th>
                                <th>Length <span>(Linear)</span></th>
                                <th>Width <span>(SQM)</span></th>
                                <th>Height <span>(SQM)</span></th>
                                <th>Length <span>(SQM)</span></th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dimension_list as $index => $dim): ?>
                                <tr>
                                    <td class="adm-row-index"><?= $index + 1 ?></td>
                                    <td class="adm-row-name"><?= htmlspecialchars($dim['dimension_label_name']) ?></td>
                                    <td class="adm-row-value"><?= htmlspecialchars($dim['item_width_label_linear']) ?></td>
                                    <td class="adm-row-value"><?= htmlspecialchars($dim['item_height_label_linear']) ?></td>
                                    <td class="adm-row-value"><?= htmlspecialchars($dim['item_length_label_linear']) ?></td>
                                    <td class="adm-row-value"><?= htmlspecialchars($dim['item_width_label_sqm']) ?></td>
                                    <td class="adm-row-value"><?= htmlspecialchars($dim['item_height_label_sqm']) ?></td>
                                    <td class="adm-row-value"><?= htmlspecialchars($dim['item_length_label_sqm']) ?></td>
                                    <td>
                                        <div class="flex items-center justify-center gap-2">
                                            <a href="insert-dimension?edit_id=<?= $dim['dimension_label_id'] ?>" class="adm-mini-btn is-edit">
                                                <i class="fas fa-pen"></i> Edit
                                            </a>
                                            <form method="POST" action="insert-dimension"
                                                  onsubmit="return confirm('Are you sure you want to delete this label?')">
                                                <input type="hidden" name="delete_id" value="<?= $dim['dimension_label_id'] ?>">
                                                <button type="submit" class="adm-mini-btn is-delete">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>