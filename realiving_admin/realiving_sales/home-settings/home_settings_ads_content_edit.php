<?php
// home_settings_ads_edit.php
include $includes ['mainbody'];

$error_message   = "";
$success_message = "";

// ── Fetch the post to edit ─────────────────────────────────
$id   = intval($_GET['id'] ?? 0);
$item = null;

if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM ads_content WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $item   = $result->fetch_assoc();
    $stmt->close();
}

if (!$item) {
    header("Location: " . BASE_URL . "ads-content-view?error=notfound");
    exit();
}

// ── Day labels ─────────────────────────────────────────────
$day_labels = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// ══════════════════════════════════════════════════════════
//  FORM HANDLING
// ══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── EDIT / UPDATE ──────────────────────────────────────
    if ($action === 'edit_ads_content') {
        $caption        = trim($_POST['caption']);
        $hashtags       = trim($_POST['hashtags']);
        $scheduled_date = trim($_POST['scheduled_date'] ?? '');

        $sched_valid = false;
        $sched_dow   = 0;
        if ($scheduled_date) {
            $sched_ts  = strtotime($scheduled_date);
            $sched_dow = (int) date('N', $sched_ts);
            if ($sched_ts >= strtotime('today') && $sched_dow >= 1 && $sched_dow <= 6) {
                $sched_valid = true;
            }
        }

        if (!$sched_valid) {
            $error_message = "Please choose a valid scheduled date (Monday – Saturday, today or future).";
        } else {
            $new_filepath = $item['filepath']; // keep existing image by default
            $new_filename_on_disk = null;

            // If a new image was uploaded
            if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
                $target_dir         = ROOT_PATH . "realiving_user/images/ads_content/";
                $file_extension     = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                if (!in_array($file_extension, $allowed_extensions)) {
                    $error_message = "Only image files (JPG, PNG, GIF, WebP) are allowed.";
                } else {
                    $file_name   = uniqid() . '_' . time() . '.webp';
                    $target_file = $target_dir . $file_name;
                    $temp_file   = $_FILES['image']['tmp_name'];
                    $image       = null;

                    switch ($file_extension) {
                        case 'jpg':
                        case 'jpeg': $image = imagecreatefromjpeg($temp_file); break;
                        case 'png':  $image = imagecreatefrompng($temp_file);  break;
                        case 'gif':  $image = imagecreatefromgif($temp_file);  break;
                        case 'webp': $image = imagecreatefromwebp($temp_file); break;
                    }

                    if ($image !== false && $image !== null) {
                        if (imagewebp($image, $target_file, 90)) {
                            imagedestroy($image);
                            $new_filepath         = './images/ads_content/' . $file_name;
                            $new_filename_on_disk = $target_dir . basename($item['filepath']);
                        } else {
                            imagedestroy($image);
                            $error_message = "Failed to convert image to WebP.";
                        }
                    } else {
                        $error_message = "Failed to process image.";
                    }
                }
            }

            if (empty($error_message)) {
                $is_today_sched  = ($scheduled_date === date('Y-m-d'));
                $is_active_val   = $is_today_sched ? 1 : 0;
                $posted_date_val = $is_today_sched ? $scheduled_date : null;

                $stmt = $conn->prepare("
                    UPDATE ads_content 
                    SET caption = ?, hashtags = ?, filepath = ?, 
                        is_active = ?, posted_date = ?, 
                        day_of_week = ?, scheduled_date = ?
                    WHERE id = ?
                ");
                $stmt->bind_param(
                    "ssssiisi",
                    $caption, $hashtags, $new_filepath,
                    $is_active_val, $posted_date_val,
                    $sched_dow, $scheduled_date, $id
                );

                if ($stmt->execute()) {
                    $stmt->close();
                    // Delete old image only after successful DB update and only if a new image was uploaded
                    if ($new_filename_on_disk && file_exists($new_filename_on_disk)) {
                        unlink($new_filename_on_disk);
                    }
                    if ($is_today_sched) {
                        header("Location: " . BASE_URL . "ads-content-view?success=updated");
                    } else {
                        header("Location: " . BASE_URL . "ads-content-view?success=updated");
                    }
                    exit();
                } else {
                    $error_message = "Database error: " . $conn->error;
                    $stmt->close();
                }
            }
        }
    }

    // ── FORCE DELETE (bypass min-3 rule) ───────────────────
    if ($action === 'force_delete') {
        $filepath = $item['filepath'];

        $stmt = $conn->prepare("DELETE FROM ads_content WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $stmt->close();
            $file_to_delete = ROOT_PATH . "realiving_user/images/ads_content/" . basename($filepath);
            if (file_exists($file_to_delete)) unlink($file_to_delete);
            header("Location: home_settings_ads_content_view.php?success=deleted");
            exit();
        } else {
            $error_message = "Failed to delete post.";
            $stmt->close();
        }
    }
}

// Re-fetch item after possible updates (for display)
$stmt = $conn->prepare("SELECT * FROM ads_content WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

$display_path    = CLIENT_ASSET . "/images/ads_content/" . basename($item['filepath']);
$posted_dow_label = ($item['day_of_week'] ?? null) ? ($day_labels[$item['day_of_week']] ?? '—') : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Edit Ads Post #<?php echo $id; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: { primary: "#4f46e5", secondary: "#4338ca" } } }
        };
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />
    <style>
        body { font-family: 'Inter', sans-serif; }

        .modal {
            display: none; position: fixed; z-index: 50;
            left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }
        .modal.active { display: flex; align-items: center; justify-content: center; }
        @keyframes fadeIn  { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; }
                             to   { transform: translateY(0);    opacity: 1; } }
        .modal-content { animation: slideUp 0.3s ease; }

        .input-style {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.15s;
        }
        .input-style:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Header -->
    <div class="mb-6">
        <a href="<?= BASE_URL ?>ads-content-view?success=deleted"
           class="text-primary hover:text-secondary flex items-center gap-2 mb-3 text-sm font-medium">
            <i class="ri-arrow-left-line"></i> Back to Ads Manager
        </a>
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Edit Post <span class="text-gray-400">#<?php echo $id; ?></span></h1>
                <p class="text-sm text-gray-500 mt-1">
                    Update caption, hashtags, image, or reschedule this post.
                    <strong class="text-red-500">Force delete bypasses the 3-post minimum rule.</strong>
                </p>
            </div>
            <!-- Force Delete Button -->
            <button onclick="openDeleteModal()"
                class="bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 px-4 py-2 rounded-lg text-sm font-semibold flex items-center gap-2 transition-colors">
                <i class="ri-delete-bin-2-line"></i> Force Delete
            </button>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($success_message)): ?>
    <div class="mb-5 p-4 bg-green-50 border-l-4 border-green-500 rounded-lg flex items-center gap-3">
        <i class="ri-checkbox-circle-line text-green-500 text-xl"></i>
        <p class="text-sm text-green-700 font-medium"><?php echo htmlspecialchars($success_message); ?></p>
    </div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
    <div class="mb-5 p-4 bg-red-50 border-l-4 border-red-500 rounded-lg flex items-center gap-3">
        <i class="ri-error-warning-line text-red-500 text-xl"></i>
        <p class="text-sm text-red-700 font-medium"><?php echo htmlspecialchars($error_message); ?></p>
    </div>
    <?php endif; ?>

    <!-- Edit Form -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">

        <!-- Current Image Preview -->
        <div class="relative">
            <img src="<?php echo htmlspecialchars($display_path); ?>"
                 alt="Current Ad Image"
                 class="w-full h-56 object-cover" />
            <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent"></div>
            <div class="absolute bottom-3 left-4 flex items-center gap-2">
                <span class="px-2.5 py-1 rounded-full text-xs font-bold
                    <?php echo $item['is_active'] ? 'bg-green-500 text-white' : 'bg-gray-500 text-white'; ?>">
                    <?php echo $item['is_active'] ? '● Active' : '○ Inactive'; ?>
                </span>
                <?php if ($posted_dow_label !== '—'): ?>
                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-indigo-600 text-white">
                    <?php echo $posted_dow_label; ?>
                </span>
                <?php endif; ?>
            </div>
            <p class="absolute bottom-3 right-4 text-xs text-white/80">Current Image</p>
        </div>

        <!-- Form Body -->
        <form method="POST" enctype="multipart/form-data" class="p-6 space-y-5">
            <input type="hidden" name="action" value="edit_ads_content" />

            <!-- Scheduled Date -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">
                    <i class="ri-calendar-check-line text-primary mr-1"></i>Scheduled Date
                    <span class="text-red-500">*</span>
                </label>
                <input type="date" name="scheduled_date" id="scheduledDateInput" required
                    min="<?php echo date('Y-m-d'); ?>"
                    value="<?php echo htmlspecialchars($item['scheduled_date'] ?? date('Y-m-d')); ?>"
                    class="input-style"
                    onchange="updateSchedulePreview(this.value)" />
                <p id="scheduleNote" class="text-xs text-gray-400 mt-1">Choose a Monday – Saturday date.</p>
                <p id="scheduleError" class="text-xs text-red-500 mt-1 hidden">Sundays are not allowed. Please pick Mon – Sat.</p>
            </div>

            <!-- Caption -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">
                    <i class="ri-text text-primary mr-1"></i>Caption <span class="text-red-500">*</span>
                </label>
                <textarea name="caption" required rows="3" class="input-style resize-none"
                    placeholder="Write your post caption here..."><?php echo htmlspecialchars($item['caption']); ?></textarea>
            </div>

            <!-- Hashtags -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">
                    <i class="ri-hashtag text-primary mr-1"></i>Hashtags
                </label>
                <input type="text" name="hashtags" class="input-style"
                    placeholder="#design #realiving #modular"
                    value="<?php echo htmlspecialchars($item['hashtags'] ?? ''); ?>" />
                <p class="text-xs text-gray-400 mt-1">Separate with spaces</p>
            </div>

            <!-- New Image Upload (optional) -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">
                    <i class="ri-image-line text-primary mr-1"></i>Replace Image
                    <span class="text-gray-400 font-normal">(optional — leave blank to keep current)</span>
                </label>
                <input type="file" id="newImageInput" name="image" accept="image/*"
                    class="input-style"
                    onchange="previewNewImage(event)" />
                <p class="text-xs text-gray-400 mt-1">JPG · PNG · GIF · WebP · auto-converted to WebP</p>
            </div>

            <!-- New Image Preview -->
            <div id="newPreviewWrap" class="hidden">
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">New Image Preview</label>
                <div class="relative rounded-xl overflow-hidden border-2 border-primary/30">
                    <img id="newPreviewImg" src="" alt="Preview" class="w-full h-48 object-cover" />
                    <button type="button" onclick="clearNewPreview()"
                        class="absolute top-2 right-2 bg-red-500 hover:bg-red-600 text-white rounded-full p-1.5 shadow-lg transition-colors">
                        <i class="ri-close-line"></i>
                    </button>
                </div>
            </div>

            <!-- Schedule info box -->
            <div id="scheduleInfoBox" class="p-3 bg-indigo-50 border border-indigo-100 rounded-xl text-xs text-indigo-700
                <?php echo empty($item['scheduled_date']) ? 'hidden' : ''; ?>">
                <div class="flex items-start gap-2">
                    <i class="ri-information-line text-base mt-0.5 shrink-0"></i>
                    <span id="scheduleInfoText"></span>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                    class="flex-1 bg-primary hover:bg-secondary text-white py-3 rounded-xl font-semibold text-sm transition-colors flex items-center justify-center gap-2">
                    <i class="ri-save-line"></i> <span id="submitBtnText">Save Changes</span>
                </button>
                <a href="<?= BASE_URL ?>ads-content-view"
                   class="px-5 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-semibold text-sm transition-colors">
                    Cancel
                </a>
            </div>
        </form>
    </div>

    <!-- Post Metadata Card -->
    <div class="mt-4 bg-white rounded-xl border border-gray-200 p-4">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Post Info</p>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs text-gray-600">
            <div>
                <span class="text-gray-400 block mb-0.5">Post ID</span>
                <span class="font-semibold">#<?php echo $item['id']; ?></span>
            </div>
            <div>
                <span class="text-gray-400 block mb-0.5">Scheduled</span>
                <span class="font-semibold"><?php echo $item['scheduled_date'] ? date('M d, Y', strtotime($item['scheduled_date'])) : '—'; ?></span>
            </div>
            <div>
                <span class="text-gray-400 block mb-0.5">Posted Date</span>
                <span class="font-semibold"><?php echo $item['posted_date'] ? date('M d, Y', strtotime($item['posted_date'])) : 'Not yet posted'; ?></span>
            </div>
            <div>
                <span class="text-gray-400 block mb-0.5">Day of Week</span>
                <span class="font-semibold"><?php echo $posted_dow_label; ?></span>
            </div>
            <div>
                <span class="text-gray-400 block mb-0.5">Status</span>
                <span class="font-semibold <?php echo $item['is_active'] ? 'text-green-600' : 'text-gray-500'; ?>">
                    <?php echo $item['is_active'] ? 'Active' : 'Inactive'; ?>
                </span>
            </div>
            <div>
                <span class="text-gray-400 block mb-0.5">Image File</span>
                <span class="font-semibold truncate block"><?php echo htmlspecialchars(basename($item['filepath'])); ?></span>
            </div>
        </div>
    </div>
</div>

<!-- ════ Force Delete Confirmation Modal ════ -->
<div id="deleteModal" class="modal">
    <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-sm w-full mx-4 p-6">
        <div class="text-center mb-5">
            <div class="w-14 h-14 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-3">
                <i class="ri-delete-bin-2-line text-red-500 text-2xl"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-800">Force Delete Post</h3>
            <p class="text-sm text-gray-500 mt-2">
                This will <strong class="text-red-500">permanently delete</strong> this post and its image.
                This bypasses the 3-post minimum rule. This action cannot be undone.
            </p>
        </div>

        <!-- Safety: require typing "DELETE" -->
        <div class="mb-4">
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                Type <code class="bg-red-50 text-red-600 px-1 py-0.5 rounded font-bold">DELETE</code> to confirm
            </label>
            <input type="text" id="deleteConfirmInput"
                placeholder="Type DELETE here..."
                class="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-red-200 focus:border-red-400 transition"
                oninput="checkDeleteInput(this.value)" />
        </div>

        <form method="POST" id="forceDeleteForm">
            <input type="hidden" name="action" value="force_delete" />
            <div class="flex gap-3">
                <button type="submit" id="confirmDeleteBtn" disabled
                    class="flex-1 bg-red-500 text-white py-2.5 rounded-xl font-semibold text-sm transition-colors disabled:opacity-40 disabled:cursor-not-allowed hover:bg-red-600">
                    <i class="ri-delete-bin-line mr-1"></i>Delete Forever
                </button>
                <button type="button" onclick="closeDeleteModal()"
                    class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2.5 rounded-xl font-semibold text-sm transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const today    = new Date();
today.setHours(0,0,0,0);
const todayStr = '<?php echo date('Y-m-d'); ?>';
const dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

// Init schedule preview on page load
window.addEventListener('DOMContentLoaded', () => {
    const val = document.getElementById('scheduledDateInput').value;
    if (val) updateSchedulePreview(val);
});

function updateSchedulePreview(val) {
    const errorEl = document.getElementById('scheduleError');
    const noteEl  = document.getElementById('scheduleNote');
    const infoBox = document.getElementById('scheduleInfoBox');
    const infoTxt = document.getElementById('scheduleInfoText');
    const btnTxt  = document.getElementById('submitBtnText');

    if (!val) { infoBox.classList.add('hidden'); errorEl.classList.add('hidden'); return; }

    const parts = val.split('-');
    const d     = new Date(parts[0], parts[1]-1, parts[2]);
    const dow   = d.getDay();

    if (dow === 0) {
        errorEl.classList.remove('hidden');
        noteEl.classList.add('hidden');
        infoBox.classList.add('hidden');
        document.getElementById('scheduledDateInput').value = '';
        return;
    }

    errorEl.classList.add('hidden');
    noteEl.classList.remove('hidden');

    const isToday  = (val === todayStr);
    const expiry   = new Date(d);
    expiry.setDate(expiry.getDate() + 7);
    const expiryStr = expiry.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    const dayName   = dayNames[dow];

    if (isToday) {
        infoTxt.innerHTML = `Post will <strong>go live immediately today (${dayName})</strong> and auto-expire on <strong>${expiryStr}</strong>.`;
        btnTxt.textContent = 'Save & Publish Now';
    } else {
        const diff = Math.ceil((d - today) / 86400000);
        infoTxt.innerHTML = `Post will be <strong>hidden until ${dayName}, ${val}</strong> (in ${diff} day${diff>1?'s':''}), then automatically go live and expire on <strong>${expiryStr}</strong>.`;
        btnTxt.textContent = 'Save Changes';
    }

    infoBox.classList.remove('hidden');
}

function previewNewImage(event) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('newPreviewImg').src = e.target.result;
        document.getElementById('newPreviewWrap').classList.remove('hidden');
    };
    reader.readAsDataURL(file);
}

function clearNewPreview() {
    document.getElementById('newImageInput').value = '';
    document.getElementById('newPreviewWrap').classList.add('hidden');
    document.getElementById('newPreviewImg').src = '';
}

// Delete modal
function openDeleteModal() {
    document.getElementById('deleteModal').classList.add('active');
    document.getElementById('deleteConfirmInput').value = '';
    document.getElementById('confirmDeleteBtn').disabled = true;
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}
function checkDeleteInput(val) {
    document.getElementById('confirmDeleteBtn').disabled = (val.trim().toUpperCase() !== 'DELETE');
}

window.onclick = e => {
    if (e.target.id === 'deleteModal') closeDeleteModal();
};
</script>

</body>
</html>
<?php $conn->close(); ?>