<?php
// designer_client_detail.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

if (!$client_id) {
    header("Location: " . BASE_URL . "designer-clients-list");
    exit();
}

// Verify designer
$meStmt = $conn->prepare("SELECT full_name, role FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

if ($me['role'] !== 'designer') {
    die("Access denied: This page is for designers only.");
}

// Fetch full client info
$clientStmt = $conn->prepare("
    SELECT ui.*
    FROM user_info ui
    JOIN site_visit sv ON sv.client_id = ui.id
    WHERE ui.id = ?
    AND (
        sv.designer1_id = ? OR sv.designer2_id = ?
        OR sv.original_designer1_id = ? OR sv.original_designer2_id = ?
    )
    LIMIT 1
");
$clientStmt->bind_param("iiiii", $client_id, $admin_id, $admin_id, $admin_id, $admin_id);
$clientStmt->execute();
$client = $clientStmt->get_result()->fetch_assoc();

if (!$client) {
    die("Client not found or you are not assigned to this client.");
}

// Display-friendly business type label
$business_type_label = ($client['business_type'] ?? '') === 'Non-Project' ? 'Individual' : ($client['business_type'] ?? '');

// Handle report form submission
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $visit_id = intval($_POST['visit_id']);
    $which = $_POST['which'];

    // ── Handle photo upload (separate action) ──
    if ($_POST['action'] === 'upload_proof_photo') {
        if (!in_array($which, ['designer1', 'designer2'])) {
            $error = "Invalid designer role.";
        } elseif (!isset($_FILES['proof_photo']) || $_FILES['proof_photo']['error'] !== UPLOAD_ERR_OK) {
            $error = "Please select a valid photo to upload.";
        } else {
            $tmp = $_FILES['proof_photo']['tmp_name'];
            $mime = mime_content_type($tmp);
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($mime, $allowed)) {
                $error = "Invalid image format. Please upload JPG, PNG, GIF, or WebP.";
            } else {
                $upload_dir = ROOT_PATH . 'uploads/site_visit_photos/';
                if (!is_dir($upload_dir))
                    mkdir($upload_dir, 0755, true);

                // Convert to WebP
                $src_image = null;
                if ($mime === 'image/jpeg')
                    $src_image = imagecreatefromjpeg($tmp);
                elseif ($mime === 'image/png')
                    $src_image = imagecreatefrompng($tmp);
                elseif ($mime === 'image/gif')
                    $src_image = imagecreatefromgif($tmp);
                elseif ($mime === 'image/webp')
                    $src_image = imagecreatefromwebp($tmp);

                if (!$src_image) {
                    $error = "Could not process the image. Please try another file.";
                } else {
                    // Delete old photo if exists
                    $oldStmt = $conn->prepare("SELECT {$which}_photo FROM site_visit WHERE id = ?");
                    $oldStmt->bind_param("i", $visit_id);
                    $oldStmt->execute();
                    $oldRow = $oldStmt->get_result()->fetch_assoc();
                    $oldPhoto = $oldRow[$which . '_photo'] ?? null;
                    if ($oldPhoto && file_exists($upload_dir . $oldPhoto)) {
                        unlink($upload_dir . $oldPhoto);
                    }

                    $photo_filename = 'visit_' . $visit_id . '_' . $which . '_' . time() . '.webp';
                    imagewebp($src_image, $upload_dir . $photo_filename, 85);
                    imagedestroy($src_image);

                    $col = $which . '_photo';
                    $upPhotoStmt = $conn->prepare("UPDATE site_visit SET {$col} = ? WHERE id = ?");
                    $upPhotoStmt->bind_param("si", $photo_filename, $visit_id);
                    if ($upPhotoStmt->execute()) {
                        $success = "Photo uploaded successfully! You can now write your report.";
                    } else {
                        $error = "Failed to save photo. Please try again.";
                    }
                }
            }
        }

        $redirect = BASE_URL . "designer-client-detail?client_id={$client_id}";
        if ($success)
            $redirect .= "&success=" . urlencode($success);
        if ($error)
            $redirect .= "&error=" . urlencode($error);
        header("Location: " . $redirect);
        exit();
    }

    if ($_POST['action'] === 'submit_report') {
        $report = trim($_POST['report']);
        $finished = 1;
        $finished_at = date('Y-m-d H:i:s');

        if (!in_array($which, ['designer1', 'designer2'])) {
            $error = "Invalid designer role.";
        } else {
            // Check photo was already uploaded
            $photoCheckStmt = $conn->prepare("SELECT {$which}_photo FROM site_visit WHERE id = ?");
            $photoCheckStmt->bind_param("i", $visit_id);
            $photoCheckStmt->execute();
            $photoRow = $photoCheckStmt->get_result()->fetch_assoc();
            $existingPhoto = $photoRow[$which . '_photo'] ?? null;

            if (empty($existingPhoto)) {
                $error = "You must upload a proof photo before submitting your report.";
            } elseif (empty($report)) {
                $error = "Please write your report before submitting.";
            } else {
                if ($which === 'designer1') {
                    $upStmt = $conn->prepare("
                        UPDATE site_visit
                        SET designer1_report = ?, designer1_finished = ?, designer1_finished_at = ?
                        WHERE id = ?
                    ");
                } else {
                    $upStmt = $conn->prepare("
                        UPDATE site_visit
                        SET designer2_report = ?, designer2_finished = ?, designer2_finished_at = ?
                        WHERE id = ?
                    ");
                }
                $upStmt->bind_param("sisi", $report, $finished, $finished_at, $visit_id);

                if ($upStmt->execute()) {
                    // Check both designers — mark Done if both finished or absent
                    $checkStmt = $conn->prepare("
        SELECT designer1_id, designer2_id,
               designer1_finished, designer2_finished,
               designer1_absent, designer2_absent,
               original_designer1_id, original_designer2_id
        FROM site_visit WHERE id = ?
    ");
                    $checkStmt->bind_param("i", $visit_id);
                    $checkStmt->execute();
                    $vRow = $checkStmt->get_result()->fetch_assoc();

                    $d1HasReplacement = !empty($vRow['original_designer1_id'] ?? null);
                    $d2HasReplacement = !empty($vRow['original_designer2_id'] ?? null);

                    $d1ok = ($vRow['designer1_absent'] && !$d1HasReplacement) || $vRow['designer1_finished'];
                    $d2ok = !$vRow['designer2_id'] || ($vRow['designer2_absent'] && !$d2HasReplacement) || $vRow['designer2_finished'];

                    if ($d1ok && $d2ok) {
                        $doneStmt = $conn->prepare("UPDATE site_visit SET status='Done' WHERE id=?");
                        $doneStmt->bind_param("i", $visit_id);
                        $doneStmt->execute();
                    } else {
                        $ongStmt = $conn->prepare("UPDATE site_visit SET status='Ongoing' WHERE id=? AND status='Pending'");
                        $ongStmt->bind_param("i", $visit_id);
                        $ongStmt->execute();
                    }
                    $success = "Report saved successfully!";
                } else {
                    $error = "Failed to save report.";
                }
            } // closes the else (photo exists check)
        }
    }

    if ($_POST['action'] === 'update_my_status') {
        $new_status = $_POST['new_status'];

        if (!in_array($new_status, ['pending', 'ongoing'])) {
            $error = "Invalid status.";
        } elseif (!in_array($which, ['designer1', 'designer2'])) {
            $error = "Invalid role.";
        } else {
            if ($new_status === 'ongoing') {
                $updStmt = $conn->prepare("UPDATE site_visit SET status='Ongoing' WHERE id=? AND status IN ('Pending', 'Done')");
                $updStmt->bind_param("i", $visit_id);
                $updStmt->execute();
                $success = "Status set to Ongoing.";
            } elseif ($new_status === 'pending') {
                $checkStmt = $conn->prepare("
                    SELECT designer1_finished, designer2_finished,
                           designer1_absent, designer2_absent, designer2_id
                    FROM site_visit WHERE id = ?
                ");
                $checkStmt->bind_param("i", $visit_id);
                $checkStmt->execute();
                $vRow = $checkStmt->get_result()->fetch_assoc();

                $otherFinished = false;
                if ($which === 'designer1') {
                    $otherFinished = $vRow['designer2_id'] &&
                        ($vRow['designer2_finished'] || $vRow['designer2_absent']);
                } else {
                    $otherFinished = $vRow['designer1_finished'] || $vRow['designer1_absent'];
                }

                if ($otherFinished) {
                    $error = "Cannot revert to Pending — the other designer has already finished.";
                } else {
                    $updStmt = $conn->prepare("UPDATE site_visit SET status='Pending' WHERE id=? AND status='Ongoing'");
                    $updStmt->bind_param("i", $visit_id);
                    $updStmt->execute();
                    $success = "Status reverted to Pending.";
                }
            }
        }
    }

    // PRG
    $redirect = BASE_URL . "designer-client-detail?client_id={$client_id}";
    if ($success)
        $redirect .= "&success=" . urlencode($success);
    if ($error)
        $redirect .= "&error=" . urlencode($error);
    header("Location: " . $redirect);
    exit();
}

$success = isset($_GET['success']) ? $_GET['success'] : '';
$error = isset($_GET['error']) ? $_GET['error'] : '';

// Fetch site visits for this client and this designer
$visitsStmt = $conn->prepare("
    SELECT sv.*,
           a1.full_name AS designer1_name,
           a2.full_name AS designer2_name,
           orig1.full_name AS original_designer1_name,
           orig2.full_name AS original_designer2_name,
           CASE WHEN sv.designer1_id = ? THEN 'designer1' ELSE 'designer2' END AS my_role
    FROM site_visit sv
    LEFT JOIN account a1    ON sv.designer1_id          = a1.id
    LEFT JOIN account a2    ON sv.designer2_id          = a2.id
    LEFT JOIN account orig1 ON sv.original_designer1_id = orig1.id
    LEFT JOIN account orig2 ON sv.original_designer2_id = orig2.id
    WHERE sv.client_id = ?
    AND (
        sv.designer1_id = ? OR sv.designer2_id = ?
        OR sv.original_designer1_id = ? OR sv.original_designer2_id = ?
    )
    ORDER BY sv.visit_date DESC
");
$visitsStmt->bind_param("iiiiii", $admin_id, $client_id, $admin_id, $admin_id, $admin_id, $admin_id);
$visitsStmt->execute();
$visits = $visitsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totalVisits = count($visits);
$doneVisits = count(array_filter($visits, fn($v) => $v['status'] === 'Done'));
$pendingVisits = count(array_filter($visits, fn($v) => $v['status'] === 'Pending'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($client['clientname']) ?> — Client Detail</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        ink: '#0B0B0B',
                        soft: '#6B6B6B',
                        muted: '#9A9A9A',
                        line: '#E2E2E2',
                    },
                },
            },
        };
    </script>
</head>

<body class="font-sans bg-[#F5F5F5] text-ink">
    <div class="max-w-[1000px] mx-auto px-5 py-8">

        <!-- Back button -->
        <div class="mb-5">
            <a href="designer-clients-list"
                class="inline-flex items-center gap-2 bg-white border border-line rounded-lg px-4 py-2 text-[13px] font-semibold hover:border-ink transition">
                <i class="fas fa-arrow-left"></i> Back to Clients
            </a>
        </div>

        <!-- ── Client Header ── -->
        <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
            <div class="flex justify-between items-start gap-4 flex-wrap mb-5">
                <div>
                    <div class="text-[11px] font-semibold tracking-[1.5px] uppercase text-soft mb-2">
                        <i class="fas fa-clipboard-list"></i> Client Detail
                    </div>
                    <h1 class="text-2xl font-bold tracking-[-0.01em]"><?= htmlspecialchars($client['clientname']) ?></h1>
                    <p class="text-[13.5px] text-soft mt-1"><?= htmlspecialchars($client['nameproject']) ?></p>
                </div>
                <button onclick="openModal()"
                    class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-4 py-2.5 text-sm font-semibold hover:opacity-90 transition whitespace-nowrap">
                    <i class="fas fa-info-circle"></i> View Full Details
                </button>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <?php if ($client['reference_number']): ?>
                    <div class="bg-[#F5F5F5] border border-line rounded-lg px-4 py-3">
                        <div class="text-[10px] font-semibold uppercase tracking-[0.5px] text-muted mb-1"><i class="fas fa-hashtag"></i> Reference No.</div>
                        <div class="text-[13px] font-semibold font-mono"><?= htmlspecialchars($client['reference_number']) ?></div>
                    </div>
                <?php endif; ?>
                <div class="bg-[#F5F5F5] border border-line rounded-lg px-4 py-3">
                    <div class="text-[10px] font-semibold uppercase tracking-[0.5px] text-muted mb-1"><i class="fas fa-building"></i> Business Type</div>
                    <div class="text-[13px] font-semibold"><?= htmlspecialchars($business_type_label) ?></div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg px-4 py-3">
                    <div class="text-[10px] font-semibold uppercase tracking-[0.5px] text-muted mb-1"><i class="fas fa-peso-sign"></i> Total Project Cost</div>
                    <div class="text-[13px] font-semibold">₱<?= number_format($client['total_project_cost'] ?? 0, 2) ?></div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg px-4 py-3">
                    <div class="text-[10px] font-semibold uppercase tracking-[0.5px] text-muted mb-1"><i class="fas fa-balance-scale"></i> Remaining Balance</div>
                    <div class="text-[13px] font-semibold">₱<?= number_format($client['remaining_balance'] ?? 0, 2) ?></div>
                </div>
            </div>
        </div>

        <!-- ── Alerts ── -->
        <?php if ($success): ?>
            <div class="bg-emerald-50 border border-emerald-300 text-emerald-800 rounded-lg px-4 py-3 mb-4 text-[13px] font-medium flex items-center gap-2">
                <i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-300 text-red-800 rounded-lg px-4 py-3 mb-4 text-[13px] font-medium flex items-center gap-2">
                <i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- ── Site Visits Section ── -->
        <div class="bg-white border border-line rounded-[10px] p-6">
            <div class="flex items-center gap-2.5 text-xs font-semibold mb-4">
                <i class="fas fa-calendar-check text-soft"></i> Site Visit Reports
                <span class="flex-1 h-px bg-line"></span>
            </div>

            <!-- Visit Summary Stats -->
            <div class="grid grid-cols-3 gap-3 mb-5">
                <div class="bg-[#F5F5F5] border border-line rounded-lg px-4 py-3.5 text-center">
                    <div class="text-2xl font-bold"><?= $totalVisits ?></div>
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-muted mt-0.5">Total Visits</div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg px-4 py-3.5 text-center">
                    <div class="text-2xl font-bold text-amber-600"><?= $pendingVisits ?></div>
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-muted mt-0.5">Pending</div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg px-4 py-3.5 text-center">
                    <div class="text-2xl font-bold text-emerald-600"><?= $doneVisits ?></div>
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-muted mt-0.5">Done</div>
                </div>
            </div>

            <?php if (empty($visits)): ?>
                <div class="text-center py-10 text-muted">
                    <i class="fas fa-calendar-times text-3xl mb-3 block"></i>
                    No site visits yet. Visits for this client will appear here.
                </div>
            <?php else: ?>
                <div class="flex flex-col gap-3">
                    <?php foreach ($visits as $idx => $visit):
                        // Determine my role on this visit
                        // Could be current designer1, current designer2,
                        // or the original who was replaced (absent)
                        if ($visit['designer1_id'] == $admin_id) {
                            $myRole = 'designer1';
                        } elseif ($visit['designer2_id'] == $admin_id) {
                            $myRole = 'designer2';
                        } elseif ($visit['original_designer1_id'] == $admin_id) {
                            $myRole = 'designer1'; // I was the original, now replaced
                        } elseif ($visit['original_designer2_id'] == $admin_id) {
                            $myRole = 'designer2'; // I was the original, now replaced
                        } else {
                            $myRole = $visit['my_role']; // fallback
                        }

                        $myReport = $visit[$myRole . '_report'];
                        $myFinished = (bool) $visit[$myRole . '_finished'];
                        $myPhoto = $visit[$myRole . '_photo'] ?? null;

                        // Am I truly absent?
                        // I am absent if: the absent flag is on my slot AND I am the ORIGINAL (not the replacement)
                        $isOriginalDesigner1 = ($visit['original_designer1_id'] == $admin_id);
                        $isOriginalDesigner2 = ($visit['original_designer2_id'] == $admin_id);
                        $isReplacement = false;

                        if ($myRole === 'designer1') {
                            // If there's an original saved and it's NOT me, then I'm the replacement
                            if (!empty($visit['original_designer1_id']) && $visit['original_designer1_id'] != $admin_id) {
                                $isReplacement = true;
                            }
                            // I am absent only if the flag is set AND I am the original (not replacement)
                            $myAbsent = (bool) $visit['designer1_absent'] && !$isReplacement;
                        } else {
                            if (!empty($visit['original_designer2_id']) && $visit['original_designer2_id'] != $admin_id) {
                                $isReplacement = true;
                            }
                            $myAbsent = (bool) $visit['designer2_absent'] && !$isReplacement;
                        }

                        $visitStatus = strtolower($visit['status']);
                        $stripeClass = 'border-l-line';
                        $stBadge = 'bg-[#F5F5F5] text-soft border-line';
                        if ($visitStatus === 'pending') { $stripeClass = 'border-l-amber-400'; $stBadge = 'bg-amber-100 text-amber-800 border-amber-300'; }
                        elseif ($visitStatus === 'ongoing') { $stripeClass = 'border-l-blue-400'; $stBadge = 'bg-blue-100 text-blue-800 border-blue-300'; }
                        elseif ($visitStatus === 'done') { $stripeClass = 'border-l-emerald-400'; $stBadge = 'bg-emerald-100 text-emerald-800 border-emerald-300'; }
                        ?>
                        <div class="border border-line <?= $stripeClass ?> border-l-4 rounded-lg overflow-hidden">
                            <!-- Card Top (toggle) -->
                            <div class="flex justify-between items-start gap-3 flex-wrap p-4 cursor-pointer hover:bg-[#FAFAFA] transition"
                                onclick="toggleVisit(<?= $visit['id'] ?>)">
                                <div>
                                    <div class="text-[14px] font-semibold flex items-center gap-2 flex-wrap">
                                        <i class="fas fa-calendar-day text-soft"></i>
                                        <?= date('F d, Y', strtotime($visit['visit_date'])) ?>
                                        <?php if (!empty($visit['visit_time'])): ?>
                                            <span class="text-soft font-normal">
                                                <i class="fas fa-clock ml-1"></i> <?= date('g:i A', strtotime($visit['visit_time'])) ?>
                                            </span>
                                        <?php endif; ?>
                                        <span class="bg-[#F5F5F5] border border-line text-ink px-2.5 py-0.5 rounded-full text-[11px] font-bold">
                                            <?= $myRole === 'designer1' ? 'Lead' : 'Support' ?>
                                        </span>
                                    </div>
                                    <div class="text-[12px] text-muted mt-1">
                                        <?php if ($visit['designer2_name'] && $myRole === 'designer1'): ?>
                                            With: <?= htmlspecialchars($visit['designer2_name']) ?>
                                        <?php elseif ($myRole === 'designer2'): ?>
                                            With: <?= htmlspecialchars($visit['designer1_name']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                        <?php if (($visit['visit_type'] ?? 'Free') === 'Paid'): ?>
                                            <span class="text-[11px] font-bold text-amber-600"><i class="fas fa-money-bill-wave"></i> Paid — ₱<?= number_format($visit['visit_amount'], 2) ?></span>
                                        <?php else: ?>
                                            <span class="text-[11px] text-muted"><i class="fas fa-gift"></i> Free</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end gap-1.5 flex-shrink-0">
                                    <span class="px-3 py-1 rounded-full text-[11px] font-bold uppercase border <?= $stBadge ?>"><?= $visit['status'] ?></span>
                                    <?php if ($myFinished): ?>
                                        <span class="text-[11px] font-semibold text-emerald-600"><i class="fas fa-check"></i> You finished</span>
                                    <?php endif; ?>
                                    <i class="fas fa-chevron-down text-muted text-[12px] transition-transform" id="chev-<?= $visit['id'] ?>"></i>
                                </div>
                            </div>

                            <!-- Card Body -->
                            <div class="hidden border-t border-line px-4 pt-4 pb-4" id="vbody-<?= $visit['id'] ?>">

                                <?php if ($visit['notes']): ?>
                                    <div class="bg-amber-50 border border-amber-300 rounded-lg px-3.5 py-2.5 mb-3.5 text-[12.5px] text-amber-800">
                                        <i class="fas fa-sticky-note"></i>
                                        <strong>Notes from head:</strong> <?= htmlspecialchars($visit['notes']) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($myAbsent): ?>
                                    <!-- ABSENT — shown only to the original designer who was replaced -->
                                    <div class="bg-red-50 border border-red-300 rounded-lg px-4 py-3.5 flex items-start gap-3">
                                        <i class="fas fa-user-slash text-red-800 text-lg flex-shrink-0 mt-0.5"></i>
                                        <div class="text-red-900">
                                            <div class="text-[13.5px] font-bold">You have been marked as absent for this visit.</div>
                                            <?php
                                            $absentReason = $visit[$myRole . '_absent_reason'] ?? '';
                                            $replacementName = ($myRole === 'designer1')
                                                ? ($visit['designer1_name'] ?? null)
                                                : ($visit['designer2_name'] ?? null);
                                            // Only show replacement name if there is an original (meaning someone replaced them)
                                            $hasReplacement = !empty($visit['original_' . $myRole . '_id']);
                                            ?>
                                            <?php if ($absentReason): ?>
                                                <div class="text-[12px] mt-1.5 bg-white/70 rounded-md px-2.5 py-1.5 italic">
                                                    <i class="fas fa-comment"></i> Reason: "<?= htmlspecialchars($absentReason) ?>"
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($hasReplacement && $replacementName): ?>
                                                <div class="text-[12px] font-semibold mt-1.5 flex items-center gap-1.5">
                                                    <i class="fas fa-exchange-alt"></i>
                                                    Replaced by: <span class="underline"><?= htmlspecialchars($replacementName) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="text-[11px] font-normal mt-1.5 text-red-700">
                                                Please contact your head if this is incorrect.
                                            </div>
                                        </div>
                                    </div>

                                <?php elseif ($myFinished): ?>
                                    <!-- FINISHED -->
                                    <div class="bg-emerald-50 border border-emerald-300 rounded-lg px-3.5 py-2.5 text-[12.5px] font-semibold text-emerald-800 flex items-center gap-2 flex-wrap">
                                        <i class="fas fa-check-circle"></i> You have marked this visit as finished.
                                        <?php if ($visit[$myRole . '_finished_at']): ?>
                                            <span class="font-normal ml-auto text-[11px]">
                                                <?= date('M d, Y g:i A', strtotime($visit[$myRole . '_finished_at'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php $myPhoto = $visit[$myRole . '_photo'] ?? null; ?>
                                    <?php if ($myPhoto): ?>
                                        <div class="mt-3">
                                            <div class="text-[11px] font-bold text-emerald-800 mb-1"><i class="fas fa-camera"></i> Proof Photo</div>
                                            <img src="<?= BASE_URL ?>uploads/site_visit_photos/<?= htmlspecialchars($myPhoto) ?>" alt="Proof"
                                                class="max-w-full max-h-[220px] rounded-md border border-emerald-200 object-cover cursor-pointer"
                                                onclick="openPhotoModal(this.src)">
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($myReport): ?>
                                        <div class="bg-emerald-50 border-l-2 border-emerald-400 rounded-md p-3 mt-3">
                                            <strong class="text-[12px] text-emerald-800 block mb-1"><i class="fas fa-file-alt"></i> Your Report</strong>
                                            <p class="text-[13px]"><?= nl2br(htmlspecialchars($myReport)) ?></p>
                                        </div>
                                    <?php endif; ?>

                                <?php else: ?>
                                    <!-- PENDING / ONGOING -->
                                    <?php if ($isReplacement): ?>
                                        <div class="bg-purple-50 border border-purple-300 rounded-lg px-3.5 py-2.5 mb-3.5 text-[12px] font-semibold text-purple-800 flex items-center gap-2 flex-wrap">
                                            <i class="fas fa-exchange-alt"></i>
                                            You are the <strong>replacement designer</strong> for this visit.
                                            <?php
                                            $origName = ($myRole === 'designer1')
                                                ? ($visit['original_designer1_name'] ?? null)
                                                : ($visit['original_designer2_name'] ?? null);
                                            ?>
                                            <?php if ($origName): ?>
                                                &nbsp;Originally assigned: <span class="underline"><?= htmlspecialchars($origName) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($visit['approval_status'] !== 'Approved'): ?>
                                        <!-- Not yet approved -->
                                        <div class="bg-amber-50 border border-amber-300 rounded-lg px-4 py-3.5 flex items-center gap-3">
                                            <i class="fas fa-hourglass-half text-amber-800 text-lg"></i>
                                            <div class="text-[13px] font-semibold text-amber-800">
                                                <?php if ($visit['approval_status'] === 'Rejected'): ?>
                                                    <div>This visit has been <strong>rejected</strong> by the manager.</div>
                                                    <div class="text-[12px] font-normal mt-1 opacity-85">
                                                        Please wait for the head to make adjustments and resubmit.
                                                    </div>
                                                    <?php if ($visit['approval_comment']): ?>
                                                        <div class="mt-2 px-2.5 py-1.5 bg-red-100 rounded-md text-red-800 text-[12px] italic">
                                                            <i class="fas fa-comment-slash"></i>
                                                            "<?= htmlspecialchars($visit['approval_comment']) ?>"
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <div>This visit is <strong>awaiting approval</strong> from the manager.</div>
                                                    <div class="text-[12px] font-normal mt-1 opacity-85">
                                                        You can set it as ongoing and submit your report once approved.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                    <?php else: ?>
                                        <!-- Approved -->
                                        <div class="bg-emerald-50 border border-emerald-300 rounded-lg px-3.5 py-2 mb-3.5 text-[12px] font-semibold text-emerald-800 flex items-center gap-2">
                                            <i class="fas fa-check-circle"></i> Approved — you can now submit your report.
                                        </div>

                                        <!-- Status Toggle -->
                                        <div class="flex gap-2.5 mb-4 items-center flex-wrap">
                                            <span class="text-[12px] font-bold text-soft uppercase tracking-[0.4px]">
                                                <i class="fas fa-toggle-on"></i> My Status:
                                            </span>
                                            <?php if ($visitStatus === 'pending' || ($visitStatus === 'done' && !$myFinished)): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="action" value="update_my_status">
                                                    <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                    <input type="hidden" name="new_status" value="ongoing">
                                                    <input type="hidden" name="which" value="<?= $myRole ?>">
                                                    <button type="submit"
                                                        class="inline-flex items-center gap-2 bg-blue-600 text-white rounded-lg px-3.5 py-2 text-[13px] font-semibold hover:opacity-90 transition">
                                                        <i class="fas fa-play"></i> Set as Ongoing
                                                    </button>
                                                </form>
                                            <?php elseif ($visitStatus === 'ongoing'): ?>
                                                <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-[12px] font-bold">
                                                    <i class="fas fa-spinner fa-spin text-[10px]"></i> Ongoing
                                                </span>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="action" value="update_my_status">
                                                    <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                    <input type="hidden" name="new_status" value="pending">
                                                    <input type="hidden" name="which" value="<?= $myRole ?>">
                                                    <button type="submit"
                                                        class="inline-flex items-center gap-2 bg-white border border-line text-soft rounded-lg px-3.5 py-2 text-[13px] font-semibold hover:border-ink transition">
                                                        <i class="fas fa-undo"></i> Set Back to Pending
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($visitStatus === 'ongoing' || ($visitStatus === 'done' && !$myFinished)): ?>

                                            <?php
                                            $myPhoto = $visit[$myRole . '_photo'] ?? null;
                                            ?>

                                            <?php if (empty($myPhoto)): ?>
                                                <!-- STEP 1: Upload Photo First -->
                                                <div class="bg-amber-50 border-2 border-dashed border-amber-400 rounded-lg p-5 mb-4">
                                                    <div class="text-[13px] font-bold text-amber-800 mb-3 flex items-center gap-2 flex-wrap">
                                                        <i class="fas fa-camera"></i>
                                                        Step 1 of 2 — Upload Proof Photo
                                                        <span class="text-[11px] font-normal text-amber-700">(Required before writing your report)</span>
                                                    </div>
                                                    <form method="POST" enctype="multipart/form-data">
                                                        <input type="hidden" name="action" value="upload_proof_photo">
                                                        <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                        <input type="hidden" name="which" value="<?= $myRole ?>">
                                                        <input type="file" name="proof_photo" accept="image/*" required
                                                            id="photoInput-<?= $visit['id'] ?>"
                                                            class="w-full border-2 border-amber-400 rounded-lg px-3 py-2 text-[13px] bg-white cursor-pointer mb-2.5"
                                                            onchange="previewPhoto(this, <?= $visit['id'] ?>)">
                                                        <div id="photoPreview-<?= $visit['id'] ?>" class="hidden mb-2.5">
                                                            <img id="previewImg-<?= $visit['id'] ?>" src="" alt="Preview"
                                                                class="max-w-full max-h-[200px] rounded-md border-2 border-amber-300 object-cover block">
                                                        </div>
                                                        <button type="submit"
                                                            class="inline-flex items-center gap-2 bg-amber-500 text-white rounded-lg px-4 py-2 text-[13px] font-semibold hover:opacity-90 transition">
                                                            <i class="fas fa-upload"></i> Upload Photo
                                                        </button>
                                                    </form>
                                                </div>
                                                <!-- Step 2 locked -->
                                                <div class="bg-[#F5F5F5] border-2 border-dashed border-line rounded-lg p-4 text-center text-muted text-[13px]">
                                                    <i class="fas fa-lock text-lg block mb-2"></i>
                                                    <strong class="text-ink">Step 2</strong> — Write your report will unlock after photo is uploaded.
                                                </div>

                                            <?php else: ?>
                                                <!-- STEP 1 DONE: Show uploaded photo -->
                                                <div class="bg-emerald-50 border border-emerald-300 rounded-lg p-3.5 mb-4 flex items-start gap-3">
                                                    <div class="flex-shrink-0">
                                                        <img src="<?= BASE_URL ?>uploads/site_visit_photos/<?= htmlspecialchars($myPhoto) ?>" alt="Proof"
                                                            class="w-20 h-20 object-cover rounded-md border-2 border-emerald-300 cursor-pointer"
                                                            onclick="openPhotoModal(this.src)">
                                                    </div>
                                                    <div class="flex-1">
                                                        <div class="text-[12px] font-bold text-emerald-800 flex items-center gap-1.5 mb-1">
                                                            <i class="fas fa-check-circle"></i> Step 1 Complete — Proof Photo Uploaded
                                                        </div>
                                                        <div class="text-[11px] text-emerald-700">Photo converted and saved as WebP.</div>
                                                        <!-- Allow re-upload -->
                                                        <form method="POST" enctype="multipart/form-data" class="mt-2 flex items-center gap-2 flex-wrap">
                                                            <input type="hidden" name="action" value="upload_proof_photo">
                                                            <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                            <input type="hidden" name="which" value="<?= $myRole ?>">
                                                            <input type="file" name="proof_photo" accept="image/*"
                                                                id="reuploadInput-<?= $visit['id'] ?>" class="text-[11px] text-soft">
                                                            <button type="submit"
                                                                class="inline-flex items-center gap-1.5 bg-white text-emerald-800 border border-emerald-300 rounded-md px-3 py-1.5 text-[11px] font-semibold hover:bg-emerald-100 transition">
                                                                <i class="fas fa-redo"></i> Replace Photo
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>

                                                <!-- STEP 2: Write Report -->
                                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                                    <div class="text-[13px] font-bold text-blue-800 mb-3 flex items-center gap-2">
                                                        <i class="fas fa-file-alt"></i> Step 2 of 2 — Write Your Report
                                                    </div>
                                                    <form method="POST">
                                                        <input type="hidden" name="action" value="submit_report">
                                                        <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                                        <input type="hidden" name="which" value="<?= $myRole ?>">
                                                        <textarea name="report" rows="4"
                                                            class="w-full border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink resize-y"
                                                            placeholder="Describe what was observed during the site visit..."><?= htmlspecialchars($myReport ?? '') ?></textarea>
                                                        <button type="submit"
                                                            class="mt-3 inline-flex items-center gap-2 bg-ink text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:opacity-90 transition">
                                                            <i class="fas fa-save"></i> Save & Mark as Finished
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>

                                        <?php else: ?>
                                            <div class="bg-[#F5F5F5] border border-line rounded-lg p-4 text-center text-muted text-[13px]">
                                                <i class="fas fa-lock text-lg block mb-2"></i>
                                                Set your status to <strong class="text-ink">Ongoing</strong> first before submitting a report.
                                            </div>
                                        <?php endif; ?>

                                    <?php endif; ?> <!-- end approval check -->
                                <?php endif; ?> <!-- end absent/finished/pending check -->

                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ══════════════════════════════════════════ -->
    <!-- FULL DETAILS MODAL                         -->
    <!-- ══════════════════════════════════════════ -->
    <div id="detailModal" class="hidden fixed inset-0 z-[9999] bg-black/50 items-center justify-center" onclick="handleOverlayClick(event)">
        <div class="bg-white p-7 rounded-[14px] max-w-xl w-[90%] max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-5 border-b border-line pb-3.5">
                <h2 class="text-lg font-bold flex items-center gap-2"><i class="fas fa-user-circle text-soft"></i> Client Details</h2>
                <button onclick="closeModal()" class="text-soft hover:text-ink text-lg">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Reference Number -->
            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Reference Number:</div>
                <div class="text-blue-700 font-mono text-[13px] font-semibold"><?= htmlspecialchars($client['reference_number'] ?? '') ?></div>
            </div>

            <!-- Client Name -->
            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Client Name:</div>
                <div class="text-ink text-[13px]"><?= htmlspecialchars($client['clientname']) ?></div>
            </div>

            <!-- Project Name -->
            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Project Name:</div>
                <div class="text-ink text-[13px]"><?= htmlspecialchars($client['nameproject']) ?></div>
            </div>

            <!-- Status -->
            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Status:</div>
                <div>
                    <?php $st = $client['status'] ?? ''; ?>
                    <span class="px-3 py-1 rounded-full text-[11px] font-bold uppercase <?= strtolower($st) === 'new client' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' ?>">
                        <?= htmlspecialchars($st) ?>
                    </span>
                </div>
            </div>

            <!-- Business Type -->
            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Business Type:</div>
                <div class="text-ink text-[13px]"><?= htmlspecialchars($business_type_label) ?></div>
            </div>

            <!-- Phone -->
            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Phone:</div>
                <div class="text-ink text-[13px]"><?= htmlspecialchars($client['contact'] ?? '') ?></div>
            </div>

            <!-- Email -->
            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Email:</div>
                <div class="text-ink text-[13px]"><?= htmlspecialchars($client['email'] ?? '') ?></div>
            </div>

            <!-- Address -->
            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Address:</div>
                <div class="text-ink text-[13px]"><?= htmlspecialchars($client['address'] ?? '') ?></div>
            </div>

            <!-- Gender -->
            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Gender:</div>
                <div class="text-ink text-[13px]"><?= htmlspecialchars($client['gender'] ?? '—') ?></div>
            </div>

            <!-- Classification -->
            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Classification:</div>
                <div class="text-ink text-[13px]"><?= htmlspecialchars($client['client_class'] ?? '—') ?></div>
            </div>

            <!-- Client Type -->
            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Client Type:</div>
                <div class="text-ink text-[13px]"><?= htmlspecialchars($client['client_type'] ?? '—') ?></div>
            </div>

            <!-- Project Scope -->
            <?php if (!empty($client['project_scope'])): ?>
                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Project Scope:</div>
                    <div class="text-ink text-[13px]"><?= nl2br(htmlspecialchars($client['project_scope'])) ?></div>
                </div>
            <?php endif; ?>

            <!-- Scope of Work -->
            <?php if (!empty($client['scope_of_work'])): ?>
                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Scope of Work:</div>
                    <div class="text-ink text-[13px]"><?= nl2br(htmlspecialchars($client['scope_of_work'])) ?></div>
                </div>
            <?php endif; ?>

            <!-- Total Project Cost -->
            <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                <div class="font-semibold text-soft text-[13px]">Total Project Cost:</div>
                <div class="text-[13px] font-bold text-emerald-700">₱<?= number_format($client['total_project_cost'] ?? 0, 2) ?></div>
            </div>

            <!-- Remaining Balance -->
            <div class="grid grid-cols-[160px_1fr] py-3 items-start">
                <div class="font-semibold text-soft text-[13px]">Remaining Balance:</div>
                <div class="text-[13px] font-bold text-blue-700">₱<?= number_format($client['remaining_balance'] ?? 0, 2) ?></div>
            </div>

        </div>
    </div>

    <!-- Photo Lightbox -->
    <div id="photoModal" onclick="closePhotoModal()"
        class="hidden fixed inset-0 z-[99999] bg-black/90 items-center justify-center cursor-zoom-out">
        <img id="photoModalImg" src="" alt="Proof Photo" class="max-w-[92vw] max-h-[92vh] rounded-lg object-contain">
    </div>

    <script>
        function toggleVisit(id) {
            const body = document.getElementById('vbody-' + id);
            const chev = document.getElementById('chev-' + id);
            body.classList.toggle('hidden');
            chev.style.transform = !body.classList.contains('hidden') ? 'rotate(180deg)' : '';
        }

        function openModal() {
            document.getElementById('detailModal').style.display = 'flex';
        }
        function closeModal() {
            document.getElementById('detailModal').style.display = 'none';
        }
        function handleOverlayClick(e) {
            if (e.target === document.getElementById('detailModal')) closeModal();
        }
        function previewPhoto(input, visitId) {
            const preview = document.getElementById('photoPreview-' + visitId);
            const img = document.getElementById('previewImg-' + visitId);
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => { img.src = e.target.result; preview.classList.remove('hidden'); };
                reader.readAsDataURL(input.files[0]);
            }
        }
        function openPhotoModal(src) {
            document.getElementById('photoModalImg').src = src;
            document.getElementById('photoModal').style.display = 'flex';
        }
        function closePhotoModal() {
            document.getElementById('photoModal').style.display = 'none';
        }
    </script>
</body>

</html>