<?php
// designer_2d3d_layout.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

// ── Pending approval notif for this user ──
function getPendingApprovalCount($conn, $admin_id, $client_id)
{
    // Only notify if approval is pending AND no revision is waiting for designer resubmission
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM layout_approvals la
        WHERE la.client_id = ? AND la.approver_id = ? AND la.status = 'pending'
        AND la.requested_at IS NOT NULL
        AND NOT EXISTS (
            SELECT 1 FROM layout_revision_log rl
            WHERE rl.client_id = la.client_id
            AND rl.area = la.area
            AND rl.status = 'pending'
            AND (
                (la.room_unit_number IS NULL AND rl.room_unit_number IS NULL)
                OR rl.room_unit_number = la.room_unit_number
            )
        )
    ");
    $stmt->bind_param("ii", $client_id, $admin_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

$meStmt = $conn->prepare("SELECT full_name, role, is_head FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

$allowedRoles = ['designer', 'technical_designer', 'general_manager', 'operational_manager', 'project_coordinator', 'sales'];
if (!in_array($me['role'], $allowedRoles)) {
    die("Access denied.");
}

$canViewAll = in_array($me['role'], ['general_manager', 'operational_manager', 'sales'])
    || (in_array($me['role'], ['designer', 'technical_designer']) && $me['is_head'] == 1);

// Check if this designer is assigned to this client
$assignStmt = $conn->prepare("
    SELECT designer1_id, designer2_id, technical_designer_id, project_coordinator_id,
           clientname, nameproject, reference_number,
           contact, email, address, project_scope, scope_of_work, business_type, status
    FROM user_info WHERE id = ?
");
$assignStmt->bind_param("i", $client_id);
$assignStmt->execute();
$clientInfo = $assignStmt->get_result()->fetch_assoc();

if (!$clientInfo)
    die("Client not found.");

// Display-friendly business type label
$business_type_label = ($clientInfo['business_type'] ?? '') === 'Non-Project' ? 'Individual' : ($clientInfo['business_type'] ?? '');

$isAssigned = (
    $clientInfo['designer1_id'] == $admin_id ||
    $clientInfo['designer2_id'] == $admin_id ||
    $clientInfo['technical_designer_id'] == $admin_id ||
    $clientInfo['project_coordinator_id'] == $admin_id
);

$isOperationalManager = ($me['role'] === 'operational_manager');
$isDesignerHeadCheck = ($me['role'] === 'designer' && $me['is_head'] == 1);
$isTechDesignerHeadCheck = ($me['role'] === 'technical_designer' && $me['is_head'] == 1);

if (!$isAssigned && !$canViewAll) {
    die("Access denied: You are not assigned to this client.");
}

// Back button logic
$isDesignerHead = ($me['role'] === 'designer' && $me['is_head'] == 1);
$cameFromManager = isset($_GET['back']) && $_GET['back'] === 'manager_detail';

$backToTracker = BASE_URL . 'unified-project-tracker?client_id=' . $client_id;
$backToList = BASE_URL . 'designer-layout-list';
$backToManager = BASE_URL . 'manager-project-detail?client_id=' . $client_id;

// ── Handle Assign Designers 1 & 2 (Designer Head only) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_designers') {
    if (!$isDesignerHeadCheck)
        die("Access denied.");
    $new_d1_id = !empty($_POST['designer1_id']) ? intval($_POST['designer1_id']) : null;
    $new_d2_id = !empty($_POST['designer2_id']) ? intval($_POST['designer2_id']) : null;
    $stmt = $conn->prepare("UPDATE user_info SET designer1_id = ?, designer2_id = ? WHERE id = ?");
    $stmt->bind_param("iii", $new_d1_id, $new_d2_id, $client_id);
    $stmt->execute();
    header("Location: " . BASE_URL . "designer-2d3d-layout?client_id={$client_id}&success=" . urlencode("Designers assigned successfully!"));
    exit();
}

// ── Handle Assign Technical Designer (Technical Designer Head only) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_technical_designer') {
    if (!$isTechDesignerHeadCheck)
        die("Access denied.");
    $new_td_id = !empty($_POST['technical_designer_id']) ? intval($_POST['technical_designer_id']) : null;
    $stmt = $conn->prepare("UPDATE user_info SET technical_designer_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_td_id, $client_id);
    $stmt->execute();
    header("Location: " . BASE_URL . "designer-2d3d-layout?client_id={$client_id}&success=" . urlencode("Technical Designer assigned successfully!"));
    exit();
}

// ── Handle Assign Project Coordinator ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_project_coordinator') {
    if (!$isOperationalManager)
        die("Access denied.");
    $new_pc_id = !empty($_POST['project_coordinator_id']) ? intval($_POST['project_coordinator_id']) : null;
    $stmt = $conn->prepare("UPDATE user_info SET project_coordinator_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_pc_id, $client_id);
    $stmt->execute();
    header("Location: " . BASE_URL . "designer-2d3d-layout?client_id={$client_id}&success=" . urlencode("Project Coordinator assigned successfully!"));
    exit();
}

$success = '';
$error = '';

// Check if intake already submitted
$intakeStmt = $conn->prepare("SELECT * FROM layout_intake WHERE client_id = ?");
$intakeStmt->bind_param("i", $client_id);
$intakeStmt->execute();
$intake = $intakeStmt->get_result()->fetch_assoc();

// Fetch current revision count
$revCountStmt = $conn->prepare("SELECT revision_count FROM user_info WHERE id = ?");
$revCountStmt->bind_param("i", $client_id);
$revCountStmt->execute();
$revCountRow = $revCountStmt->get_result()->fetch_assoc();
$current_revision = $revCountRow['revision_count'] ?? 0;

// Handle intake EDIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_intake') {
    $decoration_stage = trim($_POST['decoration_stage'] ?? '');
    $decoration_style = trim($_POST['decoration_style'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    $favour_color = trim($_POST['favour_color'] ?? '');
    $area_sqm = floatval($_POST['area_sqm'] ?? 0);
    $family_members = !empty($_POST['family_members']) ? intval($_POST['family_members']) : null;
    $layout_2d = isset($_POST['layout_2d']) ? 1 : 0;
    $layout_3d = isset($_POST['layout_3d']) ? 1 : 0;
    $budget = floatval($_POST['budget'] ?? 0);
    $measurement_remark = trim($_POST['measurement_remark'] ?? '');

    if (!$decoration_stage || !$decoration_style || (!$layout_2d && !$layout_3d)) {
        $error = "Please fill in all required fields.";
    } else {
        $updStmt = $conn->prepare("
            UPDATE layout_intake SET
                decoration_stage = ?, decoration_style = ?, occupation = ?,
                favour_color = ?, area_sqm = ?,
                family_members = ?, layout_type_2d = ?, layout_type_3d = ?,
                budget = ?, measurement_remark = ?
            WHERE client_id = ?
        ");
        $updStmt->bind_param(
            "ssssdiiiidi",
            $decoration_stage,
            $decoration_style,
            $occupation,
            $favour_color,
            $area_sqm,
            $family_members,
            $layout_2d,
            $layout_3d,
            $budget,
            $measurement_remark,
            $client_id
        );
        if ($updStmt->execute()) {
            $success = "Intake information updated successfully!";
        } else {
            $error = "Failed to update. Please try again.";
        }
    }

    $redirect = BASE_URL . "designer-2d3d-layout?client_id={$client_id}";
    if ($success)
        $redirect .= "&success=" . urlencode($success);
    if ($error)
        $redirect .= "&error=" . urlencode($error);
    header("Location: " . $redirect);
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_intake') {
    if ($intake) {
        $error = "Intake form has already been submitted for this client.";
    } else {
        $decoration_stage = trim($_POST['decoration_stage'] ?? '');
        $decoration_style = trim($_POST['decoration_style'] ?? '');
        $occupation = trim($_POST['occupation'] ?? '');
        $favour_color = trim($_POST['favour_color'] ?? '');
        $area_sqm = floatval($_POST['area_sqm'] ?? 0);
        $family_members = !empty($_POST['family_members']) ? intval($_POST['family_members']) : null;
        $layout_2d = isset($_POST['layout_2d']) ? 1 : 0;
        $layout_3d = isset($_POST['layout_3d']) ? 1 : 0;
        $budget = floatval($_POST['budget'] ?? 0);
        $measurement_remark = trim($_POST['measurement_remark'] ?? '');

        if (!$decoration_stage || !$decoration_style || (!$layout_2d && !$layout_3d)) {
            $error = "Please fill in all required fields and select at least one layout type.";
        } else {
            $insStmt = $conn->prepare("
                INSERT INTO layout_intake 
                (client_id, submitted_by, decoration_stage, decoration_style, occupation,
                 favour_color, area_sqm, family_members,
                 layout_type_2d, layout_type_3d, budget,
                 measurement_remark)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insStmt->bind_param(
                "iissssdiiids",
                $client_id,
                $admin_id,
                $decoration_stage,
                $decoration_style,
                $occupation,
                $favour_color,
                $area_sqm,
                $family_members,
                $layout_2d,
                $layout_3d,
                $budget,
                $measurement_remark
            );

            if ($insStmt->execute()) {
                // Re-fetch intake
                $intakeStmt->bind_param("i", $client_id);
                $intakeStmt->execute();
                $intake = $intakeStmt->get_result()->fetch_assoc();
                $success = "Intake form submitted successfully!";
            } else {
                $error = "Failed to submit. Please try again.";
            }
        }
    }

    // PRG
    $redirect = BASE_URL . "designer-2d3d-layout?client_id={$client_id}";
    if ($success)
        $redirect .= "&success=" . urlencode($success);
    if ($error)
        $redirect .= "&error=" . urlencode($error);
    header("Location: " . $redirect);
    exit();
}

$success = isset($_GET['success']) ? $_GET['success'] : '';
$error = isset($_GET['error']) ? $_GET['error'] : '';

// Fetch submitter name if intake exists
$submitterName = '';
if ($intake) {
    $subStmt = $conn->prepare("SELECT full_name FROM account WHERE id = ?");
    $subStmt->bind_param("i", $intake['submitted_by']);
    $subStmt->execute();
    $submitterName = $subStmt->get_result()->fetch_assoc()['full_name'] ?? '';
}

// ── Fetch current assigned staff names ──
$fetchAssignedStmt = $conn->prepare("
    SELECT 
        d1.full_name AS designer1_name,
        d2.full_name AS designer2_name,
        td.full_name AS tech_designer_name,
        pc.full_name AS project_coordinator_name
    FROM user_info ui
    LEFT JOIN account d1 ON ui.designer1_id = d1.id
    LEFT JOIN account d2 ON ui.designer2_id = d2.id
    LEFT JOIN account td ON ui.technical_designer_id = td.id
    LEFT JOIN account pc ON ui.project_coordinator_id = pc.id
    WHERE ui.id = ?
");
$fetchAssignedStmt->bind_param("i", $client_id);
$fetchAssignedStmt->execute();
$assignedStaff = $fetchAssignedStmt->get_result()->fetch_assoc();

// ── Fetch all designers (for Designer Head to assign designer1 & designer2) ──
$designersList = [];
if ($isDesignerHeadCheck) {
    $dListStmt = $conn->prepare("SELECT id, full_name FROM account WHERE role = 'designer' ORDER BY full_name");
    $dListStmt->execute();
    $designersList = $dListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Fetch all technical designers (for Technical Designer Head to assign) ──
$techDesignersList = [];
if ($isTechDesignerHeadCheck) {
    $tdListStmt = $conn->prepare("SELECT id, full_name FROM account WHERE role = 'technical_designer' ORDER BY full_name");
    $tdListStmt->execute();
    $techDesignersList = $tdListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Fetch all project coordinators (for operational manager to assign) ──
$projectCoordinatorsList = [];
if ($isOperationalManager) {
    $pcListStmt = $conn->prepare("SELECT id, full_name FROM account WHERE role = 'project_coordinator' ORDER BY full_name");
    $pcListStmt->execute();
    $projectCoordinatorsList = $pcListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2D/3D Layout — <?= htmlspecialchars($clientInfo['clientname']) ?></title>
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
    <div class="max-w-[1100px] mx-auto px-5 py-8">

        <!-- Back buttons -->
        <div class="flex gap-2.5 mb-5 flex-wrap">
            <?php if ($cameFromManager && in_array($me['role'], ['general_manager', 'operational_manager'])): ?>
                <a href="<?= $backToManager ?>"
                    class="inline-flex items-center gap-2 bg-white border border-line rounded-lg px-4 py-2 text-[13px] font-semibold hover:border-ink transition">
                    <i class="fas fa-arrow-left"></i> Back to Project Detail
                </a>
            <?php elseif ($isDesignerHead): ?>
                <a href="<?= $backToList ?>"
                    class="inline-flex items-center gap-2 bg-white border border-line rounded-lg px-4 py-2 text-[13px] font-semibold hover:border-ink transition">
                    <i class="fas fa-arrow-left"></i> Back to Layout List
                </a>
                <a href="<?= $backToTracker ?>"
                    class="inline-flex items-center gap-2 bg-white border border-line rounded-lg px-4 py-2 text-[13px] font-semibold hover:border-ink transition">
                    <i class="fas fa-chart-line"></i> Back to Tracker
                </a>
            <?php elseif ($canViewAll): ?>
                <a href="<?= $backToTracker ?>"
                    class="inline-flex items-center gap-2 bg-white border border-line rounded-lg px-4 py-2 text-[13px] font-semibold hover:border-ink transition">
                    <i class="fas fa-arrow-left"></i> Back to Tracker
                </a>
            <?php else: ?>
                <a href="<?= $backToList ?>"
                    class="inline-flex items-center gap-2 bg-white border border-line rounded-lg px-4 py-2 text-[13px] font-semibold hover:border-ink transition">
                    <i class="fas fa-arrow-left"></i> Back to Layout List
                </a>
            <?php endif; ?>
        </div>

        <!-- ── Client Information Header ── -->
        <?php
        $costStmt2 = $conn->prepare("SELECT total_project_cost, remaining_balance, reference_number, status, contact, email, address, project_scope, scope_of_work, business_type, house_state, permit_required, target_movein_date, gender, client_class, client_type FROM user_info WHERE id = ?");
        $costStmt2->bind_param("i", $client_id);
        $costStmt2->execute();
        $costData2 = $costStmt2->get_result()->fetch_assoc();
        ?>
        <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
            <div class="flex justify-between items-start gap-4 mb-5 flex-wrap">
                <div>
                    <div class="text-[11px] font-semibold tracking-[1.5px] uppercase text-soft mb-2">Client</div>
                    <h1 class="text-2xl font-bold tracking-[-0.01em]"><?= htmlspecialchars($clientInfo['clientname']) ?></h1>
                    <p class="text-[13.5px] text-soft mt-1"><?= htmlspecialchars($clientInfo['nameproject']) ?></p>
                </div>
                <button onclick="document.getElementById('clientDetailModal2').style.display='flex'"
                    class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-4 py-2.5 text-sm font-semibold hover:opacity-90 transition">
                    <i class="fas fa-info-circle"></i> View Full Details
                </button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                <?php if (!empty($costData2['reference_number'])): ?>
                    <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Reference Number</div>
                        <div class="text-[13px] font-semibold font-mono"><?= htmlspecialchars($costData2['reference_number']) ?></div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($costData2['business_type'])): ?>
                    <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Business Type</div>
                        <div class="text-[14px] font-semibold"><?= htmlspecialchars($business_type_label) ?></div>
                    </div>
                <?php endif; ?>
                <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Total Project Cost</div>
                    <div class="text-[14px] font-semibold">₱<?= number_format($costData2['total_project_cost'] ?? 0, 2) ?></div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Remaining Balance</div>
                    <div class="text-[14px] font-semibold">₱<?= number_format($costData2['remaining_balance'] ?? 0, 2) ?></div>
                </div>
            </div>
        </div>

        <!-- Client Detail Modal -->
        <div id="clientDetailModal2"
            class="hidden fixed inset-0 z-[1000] bg-black/50 items-center justify-center">
            <div class="bg-white p-7 rounded-[14px] max-w-xl w-[90%] max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-5 border-b border-line pb-3.5">
                    <h2 class="text-lg font-bold flex items-center gap-2">
                        <i class="fas fa-user-circle text-soft"></i> Client Details
                    </h2>
                    <button onclick="document.getElementById('clientDetailModal2').style.display='none'"
                        class="text-soft hover:text-ink text-lg">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Reference Number:</div>
                    <div class="text-ink font-mono text-[13px] font-semibold"><?= htmlspecialchars($costData2['reference_number'] ?? '') ?></div>
                </div>

                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Client Name:</div>
                    <div class="text-ink text-[13px]"><?= htmlspecialchars($clientInfo['clientname']) ?></div>
                </div>

                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Project Name:</div>
                    <div class="text-ink text-[13px]"><?= htmlspecialchars($clientInfo['nameproject']) ?></div>
                </div>

                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Status:</div>
                    <div>
                        <?php $st = $costData2['status'] ?? ''; ?>
                        <span class="px-3 py-1 rounded-full text-[11px] font-bold uppercase <?= strtolower($st) === 'new client' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' ?>">
                            <?= htmlspecialchars($st) ?>
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Business Type:</div>
                    <div class="text-ink text-[13px]"><?= htmlspecialchars($business_type_label) ?></div>
                </div>

                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Phone:</div>
                    <div class="text-ink text-[13px]"><?= htmlspecialchars($costData2['contact'] ?? '') ?></div>
                </div>

                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Email:</div>
                    <div class="text-ink text-[13px]"><?= htmlspecialchars($costData2['email'] ?? '') ?></div>
                </div>

                <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                    <div class="font-semibold text-soft text-[13px]">Address:</div>
                    <div class="text-ink text-[13px]"><?= htmlspecialchars($costData2['address'] ?? '') ?></div>
                </div>

                <?php if (!empty($costData2['gender'])): ?>
                    <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                        <div class="font-semibold text-soft text-[13px]">Gender:</div>
                        <div class="text-ink text-[13px]"><?= htmlspecialchars($costData2['gender']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($costData2['client_class'])): ?>
                    <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                        <div class="font-semibold text-soft text-[13px]">Classification:</div>
                        <div class="text-ink text-[13px]"><?= htmlspecialchars($costData2['client_class']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($costData2['client_type'])): ?>
                    <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                        <div class="font-semibold text-soft text-[13px]">Client Type:</div>
                        <div class="text-ink text-[13px]"><?= htmlspecialchars($costData2['client_type']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($costData2['project_scope'])): ?>
                    <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                        <div class="font-semibold text-soft text-[13px]">Project Scope:</div>
                        <div class="text-ink text-[13px]"><?= nl2br(htmlspecialchars($costData2['project_scope'])) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($costData2['scope_of_work'])): ?>
                    <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                        <div class="font-semibold text-soft text-[13px]">Scope of Work:</div>
                        <div class="text-ink text-[13px]"><?= nl2br(htmlspecialchars($costData2['scope_of_work'])) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($costData2['house_state'])): ?>
                    <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                        <div class="font-semibold text-soft text-[13px]">House State:</div>
                        <div>
                            <?php
                            $hsClass = 'bg-amber-100 text-amber-800';
                            if ($costData2['house_state'] === 'Bare/Empty Lot') {
                                $hsClass = 'bg-blue-100 text-blue-800';
                            } elseif ($costData2['house_state'] === 'Construction Started') {
                                $hsClass = 'bg-red-100 text-red-800';
                            } elseif ($costData2['house_state'] === 'Renovation') {
                                $hsClass = 'bg-purple-100 text-purple-800';
                            }
                            ?>
                            <span class="px-3 py-1 rounded-full text-xs font-bold <?= $hsClass ?>">
                                <?= htmlspecialchars($costData2['house_state']) ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($costData2['permit_required'])): ?>
                    <div class="grid grid-cols-[160px_1fr] py-3 border-b border-line items-start">
                        <div class="font-semibold text-soft text-[13px]">Permit Required:</div>
                        <div>
                            <?php
                            $prClass = 'bg-amber-100 text-amber-800';
                            if ($costData2['permit_required'] === 'Yes') {
                                $prClass = 'bg-red-100 text-red-800';
                            } elseif ($costData2['permit_required'] === 'No') {
                                $prClass = 'bg-emerald-100 text-emerald-800';
                            }
                            ?>
                            <span class="px-3 py-1 rounded-full text-xs font-bold <?= $prClass ?>">
                                <?= htmlspecialchars($costData2['permit_required']) ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($costData2['target_movein_date'])): ?>
                    <div class="grid grid-cols-[160px_1fr] py-3 items-start">
                        <div class="font-semibold text-soft text-[13px]">Target Move-in:</div>
                        <div class="text-ink text-[13px] font-semibold">
                            <i class="fas fa-calendar-check text-emerald-600"></i>
                            <?= date('F d, Y', strtotime($costData2['target_movein_date'])) ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- ── Assigned Staff Section ── -->
        <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
            <div class="flex items-center gap-2.5 text-xs font-semibold mb-4">
                <i class="fas fa-users text-soft"></i> Assigned Staff
                <span class="flex-1 h-px bg-line"></span>
            </div>

            <!-- Current assignments display -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5 mb-5">
                <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">
                        <i class="fas fa-pencil-ruler"></i> Designer 1
                    </div>
                    <div class="text-[14px] font-semibold">
                        <?= $assignedStaff['designer1_name'] ? htmlspecialchars($assignedStaff['designer1_name']) : '<span class="text-muted font-normal text-[13px]">Not assigned</span>' ?>
                    </div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">
                        <i class="fas fa-pencil-ruler"></i> Designer 2
                    </div>
                    <div class="text-[14px] font-semibold">
                        <?= $assignedStaff['designer2_name'] ? htmlspecialchars($assignedStaff['designer2_name']) : '<span class="text-muted font-normal text-[13px]">Not assigned</span>' ?>
                    </div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">
                        <i class="fas fa-tools"></i> Technical Designer
                    </div>
                    <div class="text-[14px] font-semibold">
                        <?= $assignedStaff['tech_designer_name'] ? htmlspecialchars($assignedStaff['tech_designer_name']) : '<span class="text-muted font-normal text-[13px]">Not assigned</span>' ?>
                    </div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">
                        <i class="fas fa-clipboard-check"></i> Project Coordinator
                    </div>
                    <div class="text-[14px] font-semibold">
                        <?= $assignedStaff['project_coordinator_name'] ? htmlspecialchars($assignedStaff['project_coordinator_name']) : '<span class="text-muted font-normal text-[13px]">Not assigned</span>' ?>
                    </div>
                </div>
            </div>

            <!-- Designer Head: assign designer1 & designer2 -->
            <?php if ($isDesignerHeadCheck): ?>
                <div class="border-t border-line pt-4.5 pt-[18px]">
                    <div class="text-[13px] font-semibold mb-3 flex items-center gap-2">
                        <i class="fas fa-pencil-ruler"></i> Assign Designers
                        <span class="bg-[#F5F5F5] border border-line text-soft px-2.5 py-0.5 rounded-full text-[11px]">Designer Head Only</span>
                    </div>
                    <form method="POST" class="flex gap-2.5 items-end flex-wrap">
                        <input type="hidden" name="action" value="assign_designers">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Designer 1</label>
                            <select name="designer1_id" class="min-w-[220px] border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                                <option value="">— None —</option>
                                <?php foreach ($designersList as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= ($clientInfo['designer1_id'] == $d['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($d['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Designer 2</label>
                            <select name="designer2_id" class="min-w-[220px] border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                                <option value="">— None —</option>
                                <?php foreach ($designersList as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= ($clientInfo['designer2_id'] == $d['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($d['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit"
                            class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:opacity-90 transition h-[42px]">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </form>
                    <?php if (empty($designersList)): ?>
                        <p class="text-xs text-muted mt-2"><i class="fas fa-info-circle"></i> No designers found in the system.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Technical Designer Head: assign technical_designer -->
            <?php if ($isTechDesignerHeadCheck): ?>
                <div class="border-t border-line pt-[18px] mt-[18px]">
                    <div class="text-[13px] font-semibold mb-3 flex items-center gap-2">
                        <i class="fas fa-tools"></i> Assign Technical Designer
                        <span class="bg-[#F5F5F5] border border-line text-soft px-2.5 py-0.5 rounded-full text-[11px]">Technical Designer Head Only</span>
                    </div>
                    <form method="POST" class="flex gap-2.5 items-end flex-wrap">
                        <input type="hidden" name="action" value="assign_technical_designer">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Technical Designer</label>
                            <select name="technical_designer_id" class="min-w-[220px] border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                                <option value="">— None —</option>
                                <?php foreach ($techDesignersList as $td): ?>
                                    <option value="<?= $td['id'] ?>" <?= ($clientInfo['technical_designer_id'] == $td['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($td['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit"
                            class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:opacity-90 transition h-[42px]">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </form>
                    <?php if (empty($techDesignersList)): ?>
                        <p class="text-xs text-muted mt-2"><i class="fas fa-info-circle"></i> No technical designers found in the system.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Operational Manager: assign project_coordinator -->
            <?php if ($isOperationalManager): ?>
                <div class="border-t border-line pt-[18px] mt-[18px]">
                    <div class="text-[13px] font-semibold mb-3 flex items-center gap-2">
                        <i class="fas fa-clipboard-check"></i> Assign Project Coordinator
                        <span class="bg-[#F5F5F5] border border-line text-soft px-2.5 py-0.5 rounded-full text-[11px]">Operational Manager Only</span>
                    </div>
                    <form method="POST" class="flex gap-2.5 items-end flex-wrap">
                        <input type="hidden" name="action" value="assign_project_coordinator">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Project Coordinator</label>
                            <select name="project_coordinator_id" class="min-w-[220px] border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink">
                                <option value="">— None —</option>
                                <?php foreach ($projectCoordinatorsList as $pc): ?>
                                    <option value="<?= $pc['id'] ?>" <?= ($clientInfo['project_coordinator_id'] == $pc['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($pc['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit"
                            class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:opacity-90 transition h-[42px]">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </form>
                    <?php if (empty($projectCoordinatorsList)): ?>
                        <p class="text-xs text-muted mt-2"><i class="fas fa-info-circle"></i> No project coordinators found in the system.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>

        <!-- Page header -->
        <div class="mb-5">
            <div class="text-[11px] font-semibold tracking-[1.5px] uppercase text-soft mb-2">
                <i class="fas fa-drafting-compass"></i> 2D / 3D Layout Manager
            </div>
            <h1 class="text-2xl font-bold tracking-[-0.01em]"><?= htmlspecialchars($clientInfo['clientname']) ?></h1>
            <p class="text-[13.5px] text-soft mt-1">
                <?= htmlspecialchars($clientInfo['nameproject']) ?>
                &nbsp;•&nbsp; Ref: <?= htmlspecialchars($clientInfo['reference_number']) ?>
            </p>
            <p class="text-[13px] text-muted mt-1">Designer: <?= htmlspecialchars($me['full_name']) ?></p>
        </div>

        <?php
        // Fetch rejected layout approvals for this client (only shown to assigned designers)
        $rejectedLayoutItems = [];
        if ($isAssigned) {
            $rejStmt = $conn->prepare("
        SELECT la.area, la.room_unit_number, la.comment, la.responded_at,
               a.full_name as rejected_by_name
        FROM layout_approvals la
        LEFT JOIN account a ON la.approver_id = a.id
        WHERE la.client_id = ? AND la.status = 'rejected'
        ORDER BY la.responded_at DESC
    ");
            $rejStmt->bind_param("i", $client_id);
            $rejStmt->execute();
            $rejectedLayoutItems = $rejStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        ?>

        <?php
        $pendingApprovalCount = getPendingApprovalCount($conn, $admin_id, $client_id);
        if ($pendingApprovalCount > 0):
            ?>
            <div class="bg-amber-50 border border-amber-300 rounded-[10px] p-4 mb-4.5 mb-[18px] flex items-center justify-between gap-3.5 flex-wrap">
                <div class="flex items-center gap-2.5">
                    <i class="fas fa-bell text-amber-600 text-xl"></i>
                    <div>
                        <div class="font-semibold text-sm text-amber-900">
                            You have <?= $pendingApprovalCount ?> pending
                            approval<?= $pendingApprovalCount > 1 ? 's' : '' ?> for this client
                        </div>
                        <div class="text-xs text-amber-700 mt-0.5">
                            Go to Attachments to review and approve or reject.
                        </div>
                    </div>
                </div>
                <a href="designer-attachments?client_id=<?= $client_id ?>"
                    class="inline-flex items-center gap-2 bg-amber-600 text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold hover:opacity-90 transition whitespace-nowrap">
                    <i class="fas fa-arrow-right"></i> Go to Attachments
                </a>
            </div>
        <?php endif; ?>

        <?php if (!empty($rejectedLayoutItems) && $isAssigned): ?>
            <div class="bg-red-50 border border-red-300 rounded-[10px] p-4 mb-[18px]">
                <div class="flex items-center gap-2.5 mb-3 flex-wrap">
                    <i class="fas fa-times-circle text-red-600 text-xl flex-shrink-0"></i>
                    <div class="flex-1">
                        <div class="font-semibold text-sm text-red-900">
                            <?= count($rejectedLayoutItems) ?> layout
                            area<?= count($rejectedLayoutItems) > 1 ? 's/units' : '/unit' ?> rejected — action required
                        </div>
                        <div class="text-xs text-red-700 mt-0.5">
                            Go to <strong>Attachments</strong> to review the rejection comments and resubmit updated files.
                        </div>
                    </div>
                    <a href="designer-attachments?client_id=<?= $client_id ?>"
                        class="inline-flex items-center gap-2 bg-red-600 text-white rounded-lg px-4 py-2.5 text-[13px] font-semibold hover:opacity-90 transition whitespace-nowrap flex-shrink-0">
                        <i class="fas fa-arrow-right"></i> Go to Attachments
                    </a>
                </div>
                <div class="flex flex-col gap-2">
                    <?php foreach ($rejectedLayoutItems as $rej): ?>
                        <div class="bg-white border border-red-200 rounded-lg px-3.5 py-2.5 flex items-start gap-2.5 flex-wrap">
                            <div class="flex-1 min-w-0">
                                <div class="text-[13px] font-semibold text-red-900">
                                    <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($rej['area']) ?>
                                    <?php if ($rej['room_unit_number']): ?>
                                        <span class="text-soft font-normal"> › </span>
                                        <i class="fas fa-door-open"></i> Unit <?= $rej['room_unit_number'] ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($rej['comment']): ?>
                                    <div class="text-xs text-red-800 bg-red-50 px-2.5 py-1.5 rounded-md mt-1.5 border-l-2 border-red-500 italic">
                                        <i class="fas fa-comment-slash"></i> "<?= htmlspecialchars($rej['comment']) ?>"
                                    </div>
                                <?php endif; ?>
                                <div class="text-[11px] text-muted mt-1.5 flex items-center gap-1.5">
                                    <i class="fas fa-user-times"></i>
                                    Rejected by: <?= htmlspecialchars($rej['rejected_by_name'] ?? 'Manager') ?>
                                    <?php if ($rej['responded_at']): ?>
                                        &nbsp;•&nbsp; <?= date('M d, Y g:i A', strtotime($rej['responded_at'])) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

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

        <?php
        // Show 2D/3D Layout deadline if set (Project type only)
        $dlStmt2d = $conn->prepare("SELECT start_date, end_date, duration FROM stage_deadlines WHERE client_id = ? AND stage_name = '2D / 3D Layout'");
            $dlStmt2d->bind_param("i", $client_id);
            $dlStmt2d->execute();
            $dlRow2d = $dlStmt2d->get_result()->fetch_assoc();
            if ($dlRow2d && ($dlRow2d['start_date'] || $dlRow2d['end_date'])):
                $now2d = new DateTime();
                $endDt2d = $dlRow2d['end_date'] ? new DateTime($dlRow2d['end_date']) : null;
                $isOverdue2d = $endDt2d && $now2d > $endDt2d;
                $dlClasses2d = $isOverdue2d ? 'bg-red-50 border-red-300 text-red-900' : 'bg-blue-50 border-blue-300 text-blue-900';
                $dlIconColor2d = $isOverdue2d ? 'text-red-600' : 'text-blue-600';
                $dlIcon2d = $isOverdue2d ? 'fa-exclamation-circle' : 'fa-calendar-alt';
        ?>
        <div class="border rounded-[10px] p-4 mb-[18px] flex items-center gap-3 flex-wrap <?= $dlClasses2d ?>">
            <i class="fas <?= $dlIcon2d ?> <?= $dlIconColor2d ?> text-xl flex-shrink-0"></i>
            <div class="flex-1">
                <div class="font-semibold text-sm">
                    2D / 3D Layout <?= $isOverdue2d ? '— OVERDUE' : 'Deadline' ?>
                </div>
                <div class="text-xs opacity-85 mt-0.5 flex items-center gap-2.5 flex-wrap">
                    <?php if ($dlRow2d['start_date']): ?>
                        <span><i class="fas fa-play-circle text-emerald-600"></i> Start: <strong><?= date('F d, Y', strtotime($dlRow2d['start_date'])) ?></strong></span>
                    <?php endif; ?>
                    <?php if ($dlRow2d['end_date']): ?>
                        <span><i class="fas fa-stop-circle text-red-600"></i> Deadline: <strong><?= date('F d, Y', strtotime($dlRow2d['end_date'])) ?></strong></span>
                    <?php endif; ?>
                    <?php if ($dlRow2d['duration']): ?>
                        <span><i class="fas fa-clock"></i> <?= $dlRow2d['duration'] ?> day<?= $dlRow2d['duration'] != 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- INTAKE FORM or SUBMITTED VIEW -->
        <?php if (!$intake): ?>
            <?php if ($clientInfo['designer1_id'] == $admin_id || $clientInfo['designer2_id'] == $admin_id): ?>
                <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
                    <div class="flex items-center gap-2.5 text-xs font-semibold mb-2">
                        <i class="fas fa-clipboard-list text-soft"></i> Client Intake Form
                        <span class="flex-1 h-px bg-line"></span>
                    </div>
                    <p class="text-[13px] text-muted mb-5">
                        Fill out this form once before proceeding with the layout. Only one submission is allowed per client.
                    </p>
                    <form method="POST">
                        <input type="hidden" name="action" value="submit_intake">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4.5 gap-[18px]">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Decoration Stage <span class="text-red-500">*</span></label>
                                <input type="text" name="decoration_stage" required
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    placeholder="e.g. New Build, Renovation...">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Decoration Style <span class="text-red-500">*</span></label>
                                <input type="text" name="decoration_style" required
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    placeholder="e.g. Modern, Classic, Minimalist...">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Occupation <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                <input type="text" name="occupation"
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    placeholder="Client's occupation...">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Favourite Color <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                <input type="text" name="favour_color"
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    placeholder="e.g. Beige, White, Navy...">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Area (Total SQM of House) <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                <input type="number" name="area_sqm" step="0.01" min="0"
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    placeholder="e.g. 120.50">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Family Members <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                <input type="number" name="family_members" min="0"
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    placeholder="Total number of people">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Budget <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                <input type="number" name="budget" step="0.01" min="0"
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    placeholder="₱ 0.00">
                            </div>
                            <div class="md:col-span-2 flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Layout Type <span class="text-red-500">*</span></label>
                                <div class="flex gap-3 mt-1">
                                    <label class="opacity-75 cursor-not-allowed border border-line rounded-lg px-4 py-2.5 text-[13px] font-semibold flex items-center gap-2">
                                        <input type="checkbox" name="layout_2d" value="1" checked disabled>
                                        <i class="fas fa-vector-square text-blue-600"></i> 2D Layout
                                        <span class="text-[10px] bg-blue-100 text-blue-800 px-1.5 py-0.5 rounded-md font-bold">Always</span>
                                    </label>
                                    <label class="border border-line rounded-lg px-4 py-2.5 text-[13px] font-semibold flex items-center gap-2 cursor-pointer transition has-[:checked]:border-ink has-[:checked]:bg-[#F5F5F5]">
                                        <input type="checkbox" name="layout_3d" value="1">
                                        <i class="fas fa-cube text-purple-600"></i> 3D Layout
                                        <span class="text-[10px] bg-purple-100 text-purple-800 px-1.5 py-0.5 rounded-md font-bold">Optional</span>
                                    </label>
                                </div>
                                <input type="hidden" name="layout_2d" value="1">
                            </div>
                            <div class="md:col-span-2 flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Measurement Remark <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                <textarea name="measurement_remark" rows="3"
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    placeholder="Any additional remarks about measurements..."></textarea>
                            </div>
                        </div>
                        <button type="submit"
                            class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-6 py-3 text-sm font-semibold hover:opacity-90 transition mt-4">
                            <i class="fas fa-paper-plane"></i> Submit Intake Form
                        </button>
                    </form>
                </div>
            <?php endif; ?>

        <?php else: ?>

            <?php
            // Check approval state for mark-as-done
            $allAreasApproved = false;

            // Fetch distinct areas (needed for approval check and revision section)
            $areasStmt = $conn->prepare("
    SELECT DISTINCT area FROM quotation_entries WHERE client_id = ?
    UNION
    SELECT DISTINCT area FROM quotation_fixed_sizes WHERE client_id = ?
    ORDER BY area
");
            $areasStmt->bind_param("ii", $client_id, $client_id);
            $areasStmt->execute();
            $areasResult = $areasStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $areas = array_column($areasResult, 'area');

            $areasForApproval = $areas;

            if (!empty($areasForApproval)) {
                // Get all approvers
                $aprCountStmt = $conn->prepare("
        SELECT COUNT(*) FROM account
        WHERE (role IN ('general_manager','operational_manager'))
           OR (role IN ('designer','technical_designer') AND is_head = 1)
    ");
                $aprCountStmt->execute();
                $totalApprovers = (int) $aprCountStmt->get_result()->fetch_row()[0];

                if ($totalApprovers > 0) {
                    $allAreasDone = true;
                    foreach ($areasForApproval as $checkArea) {
                        // Area-level approval check
                        $aprChk = $conn->prepare("
                    SELECT COUNT(*) FROM layout_approvals
                    WHERE client_id = ? AND area = ? AND room_unit_number IS NULL AND status = 'approved'
                ");
                        $aprChk->bind_param("is", $client_id, $checkArea);
                        $aprChk->execute();
                        $approvedCount = (int) $aprChk->get_result()->fetch_row()[0];
                        if ($approvedCount < $totalApprovers) {
                            $allAreasDone = false;
                            break;
                        }
                    }
                    $allAreasApproved = $allAreasDone;
                }
            }

            // Check current tracker status for 2D/3D Layout
            $layoutStatusStmt = $conn->prepare("
    SELECT status FROM project_tracker WHERE client_id = ? AND stage_name = '2D / 3D Layout'
");
            $layoutStatusStmt->bind_param("i", $client_id);
            $layoutStatusStmt->execute();
            $layoutTrackerRow = $layoutStatusStmt->get_result()->fetch_assoc();
            $layoutTrackerStatus = $layoutTrackerRow['status'] ?? 'Pending';
            ?>

            <?php
            $isAssignedDesigner = (
                $clientInfo['designer1_id'] == $admin_id ||
                $clientInfo['designer2_id'] == $admin_id
            );
            ?>
            <?php if ($allAreasApproved && $layoutTrackerStatus !== 'Done' && $isAssignedDesigner): ?>
                <div class="bg-emerald-50 border border-emerald-300 rounded-[10px] p-5 mb-[22px] flex justify-between items-center gap-4 flex-wrap">
                    <div>
                        <div class="font-semibold text-[15px] text-emerald-900 mb-1">
                            <i class="fas fa-check-circle"></i> All Areas Approved!
                        </div>
                        <div class="text-[13px] text-emerald-800 opacity-85">
                            All layout areas have been approved by all reviewers. You can now mark this stage as Done.
                        </div>
                    </div>
                    <form method="POST" action="<?= BASE_URL ?>mark-layout-done">
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        <input type="hidden" name="redirect_url"
                            value="<?= BASE_URL ?>designer-2d3d-layout?client_id=<?= $client_id ?>">
                        <button type="submit"
                            class="inline-flex items-center gap-2 bg-emerald-700 text-white rounded-lg px-5 py-2.5 text-[13px] font-semibold hover:opacity-90 transition whitespace-nowrap">
                            <i class="fas fa-flag-checkered"></i> Mark as Done
                        </button>
                    </form>
                </div>
            <?php elseif ($layoutTrackerStatus === 'Done'): ?>
                <div class="bg-emerald-50 border border-emerald-300 rounded-[10px] px-6 py-4 mb-[22px] flex items-center gap-2.5">
                    <i class="fas fa-check-circle text-emerald-600 text-xl"></i>
                    <span class="font-semibold text-sm text-emerald-900">2D / 3D Layout stage is marked as Done.</span>
                </div>
            <?php endif; ?>

            <?php
            // Fetch revision log for this client
            $revLogStmt = $conn->prepare("
    SELECT rl.*, a.full_name as requester_name
    FROM layout_revision_log rl
    LEFT JOIN account a ON rl.requested_by = a.id
    WHERE rl.client_id = ?
    ORDER BY rl.created_at DESC
");
            $revLogStmt->bind_param("i", $client_id);
            $revLogStmt->execute();
            $revisionLogs = $revLogStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            ?>

            <!-- Revision Request Section -->
            <?php if (($clientInfo['designer1_id'] == $admin_id || $clientInfo['designer2_id'] == $admin_id) && !empty($areas)): ?>
                <div class="bg-white border-2 border-amber-400 rounded-[10px] p-6 mb-5">
                    <div class="flex items-center gap-2.5 text-xs font-semibold text-amber-800 mb-2">
                        <i class="fas fa-redo"></i> Request Revision
                        <?php if ($current_revision > 0): ?>
                            <span class="bg-amber-100 text-amber-800 px-3 py-0.5 rounded-full text-[13px] font-bold normal-case">
                                <?= $current_revision ?> Revision(s) so far
                            </span>
                        <?php endif; ?>
                        <span class="flex-1 h-px bg-line"></span>
                    </div>
                    <p class="text-[13px] text-muted mb-5">
                        Select an area (and unit if applicable) to request a revision. This will reset the approvals for that
                        area and increment the revision count.
                    </p>

                    <!-- Selected Summary Box -->
                    <div id="selectionSummary" class="hidden bg-amber-50 border border-amber-300 rounded-lg p-4 mb-4">
                        <div class="text-xs font-semibold text-amber-800 uppercase tracking-[0.4px] mb-3">
                            <i class="fas fa-list-check"></i> Selected for Revision — add a reason for each:
                        </div>
                        <div id="selectionItems" class="flex flex-col gap-2.5"></div>
                    </div>

                    <form method="POST" action="<?= BASE_URL ?>request-revision" id="revisionForm">
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        <input type="hidden" name="redirect_url" value="<?= BASE_URL ?>designer-2d3d-layout?client_id=<?= $client_id ?>">
                        <input type="hidden" name="selections" id="selectionsInput" value="">

                        <!-- Area + Unit selector -->
                        <div class="mb-4">
                            <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft block mb-2">
                                <i class="fas fa-map-marker-alt"></i> Select Areas / Units for Revision
                                <span class="text-red-500">*</span>
                                <span class="text-[11px] text-muted font-normal normal-case ml-1.5">(You can select multiple)</span>
                            </label>

                            <?php foreach ($areas as $areaOption): ?>
                                <?php
                                // Get approval state
                                $areaApprStmt = $conn->prepare("
            SELECT status FROM layout_approvals
            WHERE client_id = ? AND area = ? AND room_unit_number IS NULL
        ");
                                $areaApprStmt->bind_param("is", $client_id, $areaOption);
                                $areaApprStmt->execute();
                                $areaApprRows = $areaApprStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                $areaApprStatuses = array_column($areaApprRows, 'status');

                                if (empty($areaApprRows)) {
                                    $aTag = 'none';
                                    $aTagClass = 'bg-gray-100 text-muted';
                                } elseif (in_array('rejected', $areaApprStatuses)) {
                                    $aTag = 'rejected';
                                    $aTagClass = 'bg-red-100 text-red-800';
                                } elseif (count(array_filter($areaApprStatuses, fn($s) => $s === 'approved')) === count($areaApprStatuses)) {
                                    $aTag = 'approved';
                                    $aTagClass = 'bg-emerald-100 text-emerald-800';
                                } else {
                                    $aTag = 'pending';
                                    $aTagClass = 'bg-amber-100 text-amber-800';
                                }

                                $areaSlugRev = 'revarea_' . preg_replace('/[^a-zA-Z0-9]/', '_', $areaOption);
                                ?>

                                <div class="border border-line rounded-lg mb-2.5 overflow-hidden" id="areablock-<?= $areaSlugRev ?>">
                                    <!-- Area row -->
                                    <div class="flex items-center gap-2.5 px-4 py-3 bg-[#F5F5F5] flex-wrap">
                                        <label class="flex items-center gap-2 cursor-pointer flex-1">
                                            <input type="checkbox" class="rev-area-check w-4 h-4 cursor-pointer accent-amber-500"
                                                data-area="<?= htmlspecialchars($areaOption, ENT_QUOTES) ?>"
                                                onchange="onAreaCheck(this)">
                                            <span class="text-sm font-semibold">
                                                <i class="fas fa-layer-group text-soft"></i>
                                                <?= htmlspecialchars($areaOption) ?>
                                            </span>
                                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold <?= $aTagClass ?>">
                                                <?= ucfirst($aTag) ?>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="submit" id="revisionSubmitBtn" disabled
                            class="inline-flex items-center gap-2 bg-amber-600 text-white rounded-lg px-6 py-2.5 text-[13px] font-semibold opacity-50 cursor-not-allowed transition"
                            onclick="return confirmRevision()">
                            <i class="fas fa-redo"></i> Request Revision
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Revision History -->
            <?php if (!empty($revisionLogs)):
                // Group by revision_number
                $revGroups = [];
                foreach ($revisionLogs as $log) {
                    $rn = $log['revision_number'];
                    if (!isset($revGroups[$rn]))
                        $revGroups[$rn] = [];
                    $revGroups[$rn][] = $log;
                }
                krsort($revGroups); // newest revision number first
                ?>
                <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
                    <div class="flex items-center justify-between mb-4 pb-3 border-b border-line">
                        <div class="flex items-center gap-2.5 text-xs font-semibold">
                            <i class="fas fa-history"></i> Revision History
                        </div>
                        <button type="button" onclick="toggleRevHistory()" id="revHistoryToggleBtn"
                            class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-4 py-2 text-[13px] font-semibold hover:opacity-90 transition">
                            <i class="fas fa-eye" id="revHistoryBtnIcon"></i>
                            <span id="revHistoryBtnText">Show History</span>
                        </button>
                    </div>

                    <div id="revHistoryPanel" class="hidden">
                        <?php foreach ($revGroups as $revNum => $logs): ?>
                            <?php
                            // Determine overall status for this revision group
                            $groupStatuses = array_column($logs, 'status');
                            if (in_array('approved', $groupStatuses)) {
                                $grpClasses = 'bg-emerald-50 border-emerald-300';
                                $grpBadge = 'bg-emerald-600';
                                $grpText = 'text-emerald-800';
                                $grpLabel = 'Approved';
                                $grpIcon = 'fa-check-circle';
                            } elseif (in_array('designer_resubmitted', $groupStatuses)) {
                                $grpClasses = 'bg-blue-50 border-blue-300';
                                $grpBadge = 'bg-blue-600';
                                $grpText = 'text-blue-800';
                                $grpLabel = 'Resubmitted';
                                $grpIcon = 'fa-paper-plane';
                            } else {
                                $grpClasses = 'bg-amber-50 border-amber-300';
                                $grpBadge = 'bg-amber-600';
                                $grpText = 'text-amber-800';
                                $grpLabel = 'Pending';
                                $grpIcon = 'fa-hourglass-half';
                            }
                            $firstLog = $logs[0];
                            $revPanelId = 'revpanel_' . $revNum;
                            $revChevronId = 'revchevron_' . $revNum;
                            ?>
                            <div class="border rounded-lg mb-2.5 overflow-hidden <?= $grpClasses ?>">
                                <button type="button" onclick="toggleRevPanel('<?= $revPanelId ?>', '<?= $revChevronId ?>')"
                                    class="w-full border-none px-4 py-3.5 cursor-pointer flex items-center gap-3 text-left <?= $grpClasses ?>">
                                    <span class="<?= $grpBadge ?> text-white px-2.5 py-0.5 rounded-full text-xs font-bold whitespace-nowrap flex-shrink-0">
                                        Revision #<?= $revNum ?>
                                    </span>
                                    <span class="text-xs font-medium <?= $grpText ?> flex-1">
                                        <?= count($logs) ?>
                                        area<?= count($logs) > 1 ? 's' : '' ?>/unit<?= count($logs) > 1 ? 's' : '' ?> affected
                                        &nbsp;•&nbsp;
                                        <?= date('M d, Y g:i A', strtotime($firstLog['created_at'])) ?>
                                    </span>
                                    <span class="<?= $grpText ?> px-2.5 py-0.5 rounded-full text-[11px] font-bold inline-flex items-center gap-1 flex-shrink-0 bg-white/60">
                                        <i class="fas <?= $grpIcon ?>"></i> <?= $grpLabel ?>
                                    </span>
                                    <i id="<?= $revChevronId ?>" class="fas fa-chevron-down <?= $grpText ?> text-[13px] transition-transform flex-shrink-0"></i>
                                </button>

                                <div id="<?= $revPanelId ?>" class="hidden px-4 py-3.5 bg-white border-t border-line">
                                    <div class="flex flex-col gap-2.5">
                                        <?php foreach ($logs as $log):
                                            $logStatusClass = 'bg-gray-100 text-muted';
                                            $logStatusLabel = 'Pending';
                                            if ($log['status'] === 'designer_resubmitted') {
                                                $logStatusClass = 'bg-blue-100 text-blue-800';
                                                $logStatusLabel = 'Resubmitted';
                                            } elseif ($log['status'] === 'approved') {
                                                $logStatusClass = 'bg-emerald-100 text-emerald-800';
                                                $logStatusLabel = 'Approved';
                                            }
                                            ?>
                                            <div class="border border-amber-300 rounded-lg px-3.5 py-3 bg-amber-50">
                                                <div class="flex justify-between items-center flex-wrap gap-1.5 mb-1.5">
                                                    <span class="text-[13px] font-semibold text-amber-900">
                                                        <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($log['area']) ?>
                                                        <?php if ($log['room_unit_name'] || $log['room_unit_number']): ?>
                                                            &nbsp;›&nbsp; <i class="fas fa-door-open"></i>
                                                            <?= htmlspecialchars($log['room_unit_name'] ?: 'Unit ' . $log['room_unit_number']) ?>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold <?= $logStatusClass ?>">
                                                        <?= $logStatusLabel ?>
                                                    </span>
                                                </div>
                                                <?php if ($log['reason']): ?>
                                                    <div class="text-[13px] text-ink bg-white px-3 py-2 rounded-md border-l-2 border-amber-500 mb-1.5">
                                                        <?= nl2br(htmlspecialchars($log['reason'])) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="text-[11px] text-muted flex items-center gap-1.5">
                                                    <i class="fas fa-user-edit"></i>
                                                    Requested by: <?= htmlspecialchars($log['requester_name'] ?? '') ?>
                                                    &nbsp;•&nbsp;
                                                    <?= date('M d, Y g:i A', strtotime($log['created_at'])) ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- INTAKE SUBMITTED — show summary + edit -->
            <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
                <div class="flex justify-between items-center mb-4 pb-3 border-b border-line">
                    <div class="flex items-center gap-2.5 text-xs font-semibold">
                        <i class="fas fa-clipboard-check"></i> Client Intake Information
                    </div>
                    <?php if ($clientInfo['designer1_id'] == $admin_id || $clientInfo['designer2_id'] == $admin_id): ?>
                        <button type="button" onclick="toggleIntakeEdit()" id="intakeEditBtn"
                            class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-4 py-2 text-[13px] font-semibold hover:opacity-90 transition">
                            <i class="fas fa-pen"></i> Edit
                        </button>
                    <?php endif; ?>
                </div>

                <div class="bg-emerald-50 text-emerald-800 rounded-lg px-4 py-3 mb-4.5 mb-[18px] text-[13px] font-semibold flex items-center gap-2">
                    <i class="fas fa-check-circle"></i>
                    Submitted by <?= htmlspecialchars($submitterName) ?> on
                    <?= date('F d, Y g:i A', strtotime($intake['created_at'])) ?>
                </div>

                <!-- VIEW MODE -->
                <div id="intakeViewMode">
                    <div class="mb-3.5">
                        <?php if ($intake['layout_type_2d']): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-blue-100 text-blue-800 mr-1.5">
                                <i class="fas fa-vector-square"></i> 2D Layout
                            </span>
                        <?php endif; ?>
                        <?php if ($intake['layout_type_3d']): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-purple-100 text-purple-800">
                                <i class="fas fa-cube"></i> 3D Layout
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5">
                        <div class="bg-[#F5F5F5] border border-line rounded-lg p-3.5">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Decoration Stage</div>
                            <div class="text-sm font-semibold"><?= htmlspecialchars($intake['decoration_stage']) ?></div>
                        </div>
                        <div class="bg-[#F5F5F5] border border-line rounded-lg p-3.5">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Decoration Style</div>
                            <div class="text-sm font-semibold"><?= htmlspecialchars($intake['decoration_style']) ?></div>
                        </div>
                        <div class="bg-[#F5F5F5] border border-line rounded-lg p-3.5">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Occupation</div>
                            <div class="text-sm font-semibold"><?= htmlspecialchars($intake['occupation']) ?></div>
                        </div>
                        <div class="bg-[#F5F5F5] border border-line rounded-lg p-3.5">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Favourite Color</div>
                            <div class="text-sm font-semibold"><?= htmlspecialchars($intake['favour_color']) ?></div>
                        </div>
                        <div class="bg-[#F5F5F5] border border-line rounded-lg p-3.5">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Area (SQM)</div>
                            <div class="text-sm font-semibold"><?= number_format($intake['area_sqm'], 2) ?> m²</div>
                        </div>
                        <?php if ($intake['family_members'] !== null): ?>
                            <div class="bg-[#F5F5F5] border border-line rounded-lg p-3.5">
                                <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Family Members</div>
                                <div class="text-sm font-semibold"><?= $intake['family_members'] ?> people</div>
                            </div>
                        <?php endif; ?>
                        <div class="bg-[#F5F5F5] border border-line rounded-lg p-3.5">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Budget</div>
                            <div class="text-sm font-semibold">₱<?= number_format($intake['budget'], 2) ?></div>
                        </div>
                        <?php if ($intake['measurement_remark']): ?>
                            <div class="sm:col-span-2 lg:col-span-3 bg-[#F5F5F5] border border-line rounded-lg p-3.5">
                                <div class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft mb-1">Measurement Remark</div>
                                <div class="text-[13px] font-normal"><?= nl2br(htmlspecialchars($intake['measurement_remark'])) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- EDIT MODE (hidden by default) -->
                <div id="intakeEditMode" class="hidden">
                    <form method="POST">
                        <input type="hidden" name="action" value="edit_intake">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-[18px]">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Decoration Stage <span class="text-red-500">*</span></label>
                                <input type="text" name="decoration_stage" required
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    value="<?= htmlspecialchars($intake['decoration_stage']) ?>">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Decoration Style <span class="text-red-500">*</span></label>
                                <input type="text" name="decoration_style" required
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    value="<?= htmlspecialchars($intake['decoration_style']) ?>">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Occupation <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                <input type="text" name="occupation"
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    value="<?= htmlspecialchars($intake['occupation']) ?>">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Favourite Color <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                <input type="text" name="favour_color"
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    value="<?= htmlspecialchars($intake['favour_color']) ?>">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Area (Total SQM) <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                <input type="number" name="area_sqm" step="0.01" min="0"
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    value="<?= htmlspecialchars($intake['area_sqm']) ?>">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Family Members <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                <input type="number" name="family_members" min="0"
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    value="<?= htmlspecialchars($intake['family_members'] ?? '') ?>">
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Budget <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                <input type="number" name="budget" step="0.01" min="0"
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"
                                    value="<?= htmlspecialchars($intake['budget']) ?>">
                            </div>
                            <div class="md:col-span-2 flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Layout Type <span class="text-red-500">*</span></label>
                                <div class="flex gap-3 mt-1">
                                    <label class="opacity-75 cursor-not-allowed border border-line rounded-lg px-4 py-2.5 text-[13px] font-semibold flex items-center gap-2">
                                        <input type="checkbox" name="layout_2d" value="1" checked disabled>
                                        <i class="fas fa-vector-square text-blue-600"></i> 2D Layout
                                        <span class="text-[10px] bg-blue-100 text-blue-800 px-1.5 py-0.5 rounded-md font-bold">Always</span>
                                    </label>
                                    <label class="border border-line rounded-lg px-4 py-2.5 text-[13px] font-semibold flex items-center gap-2 cursor-pointer transition has-[:checked]:border-ink has-[:checked]:bg-[#F5F5F5]">
                                        <input type="checkbox" name="layout_3d" value="1" <?= $intake['layout_type_3d'] ? 'checked' : '' ?>>
                                        <i class="fas fa-cube text-purple-600"></i> 3D Layout
                                        <span class="text-[10px] bg-purple-100 text-purple-800 px-1.5 py-0.5 rounded-md font-bold">Optional</span>
                                    </label>
                                </div>
                                <input type="hidden" name="layout_2d" value="1">
                            </div>
                            <div class="md:col-span-2 flex flex-col gap-1.5">
                                <label class="text-[11px] font-semibold uppercase tracking-[0.4px] text-soft">Measurement Remark <span class="text-muted font-normal normal-case">(Optional)</span></label>
                                <textarea name="measurement_remark" rows="3"
                                    class="border border-line rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-ink"><?= htmlspecialchars($intake['measurement_remark'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="flex gap-2.5 mt-4">
                            <button type="submit"
                                class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-6 py-3 text-sm font-semibold hover:opacity-90 transition">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <button type="button" onclick="toggleIntakeEdit()"
                                class="inline-flex items-center gap-2 bg-white border border-line rounded-lg px-6 py-3 text-sm font-semibold hover:border-ink transition">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="mb-[22px]">
                <a href="<?= BASE_URL ?>designer-attachments?client_id=<?= $client_id ?>"
                    class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-5 py-3 text-sm font-semibold hover:opacity-90 transition">
                    <i class="fas fa-paperclip"></i> Go to Attachments
                </a>
            </div>

        <?php endif; ?>

    </div>

    <!-- Room Unit Detail Modal -->
    <div id="designerRoomModal" class="hidden fixed inset-0 z-[2000] bg-black/50 items-center justify-center">
        <div class="bg-white p-7 rounded-[14px] max-w-xl w-[90%] max-h-[88vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4.5 mb-[18px] border-b border-line pb-3">
                <div>
                    <h3 id="roomModalTitle" class="text-[17px] font-bold">
                        <i class="fas fa-door-open"></i> Unit Details
                    </h3>
                    <p id="roomModalArea" class="text-xs text-soft mt-1"></p>
                </div>
                <button onclick="closeDesignerRoomModal()" class="text-soft hover:text-ink text-xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="roomModalBody">
                <div class="text-center py-8 text-muted">
                    <i class="fas fa-spinner fa-spin text-2xl"></i>
                    <p class="mt-2.5">Loading items...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Add-ons Detail Modal -->
    <div id="addonsModal" class="hidden fixed inset-0 z-[2000] bg-black/50 items-center justify-center">
        <div class="bg-white p-7 rounded-[12px] max-w-lg w-[90%] max-h-[85vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4.5 mb-[18px] border-b border-line pb-3">
                <h3 id="addonsModalTitle" class="text-base font-bold"><i class="fas fa-puzzle-piece"></i> Add-ons</h3>
                <button onclick="document.getElementById('addonsModal').style.display='none'" class="text-soft hover:text-ink text-xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="addonsModalBody"></div>
        </div>
    </div>

    <script>
        window.RL_CONFIG = {
            baseUrl: "<?= BASE_URL ?>",
            clientAsset: "<?= CLIENT_ASSET ?>"
        };
    </script>
    <script src="<?= ADMIN_ASSET ?>/designer-management/designer-management/assets/js/designer_2d3d_layout.js"></script>
</body>

</html>