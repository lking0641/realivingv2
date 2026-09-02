<?php
//designer_attachments.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

// ── Pending approval notif ──
function getPendingApprovalCount($conn, $admin_id, $client_id)
{
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

$allowedRoles = ['designer', 'technical_designer', 'general_manager', 'operational_manager', 'sales'];
if (!in_array($me['role'], $allowedRoles))
    die("Access denied.");

$canViewAll = in_array($me['role'], ['general_manager', 'operational_manager', 'sales'])
    || (in_array($me['role'], ['designer', 'technical_designer']) && $me['is_head'] == 1);

$assignStmt = $conn->prepare("SELECT designer1_id, designer2_id, clientname, nameproject, reference_number FROM user_info WHERE id = ?");
$assignStmt->bind_param("i", $client_id);
$assignStmt->execute();
$clientInfo = $assignStmt->get_result()->fetch_assoc();
if (!$clientInfo)
    die("Client not found.");

$isAssigned = ($clientInfo['designer1_id'] == $admin_id || $clientInfo['designer2_id'] == $admin_id);
if (!$isAssigned && !$canViewAll)
    die("Access denied.");

$intakeStmt = $conn->prepare("SELECT layout_type_2d, layout_type_3d FROM layout_intake WHERE client_id = ?");
$intakeStmt->bind_param("i", $client_id);
$intakeStmt->execute();
$intake = $intakeStmt->get_result()->fetch_assoc();

$areasStmt = $conn->prepare("
    SELECT DISTINCT area FROM quotation_entries WHERE client_id = ?
    UNION
    SELECT DISTINCT area FROM quotation_fixed_sizes WHERE client_id = ?
    ORDER BY area
");
$areasStmt->bind_param("ii", $client_id, $client_id);
$areasStmt->execute();
$areas = array_column($areasStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'area');

// Fetch active pending revision log entries for this client
$activeRevStmt = $conn->prepare("
    SELECT area, room_unit_number, reason, revision_number, created_at
    FROM layout_revision_log
    WHERE client_id = ? AND status = 'pending'
    ORDER BY created_at DESC
");
$activeRevStmt->bind_param("i", $client_id);
$activeRevStmt->execute();
$activeRevRows = $activeRevStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Index by area+unit for quick lookup
$activeRevMap = [];
foreach ($activeRevRows as $rv) {
    $mapKey = $rv['area'] . '||' . ($rv['room_unit_number'] ?? 'null');
    $activeRevMap[$mapKey] = $rv;
}

// Count total attachments per area
function countAreaAttachments($conn, $client_id, $area)
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM layout_attachments WHERE client_id = ? AND area = ?");
    $stmt->bind_param("is", $client_id, $area);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_row();
    $stmt->close();
    return $row[0] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attachments — <?= htmlspecialchars($clientInfo['clientname']) ?></title>
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

        <!-- Back button -->
        <div class="flex gap-2.5 mb-5 flex-wrap">
            <a href="designer-2d3d-layout?client_id=<?= $client_id ?>"
                class="inline-flex items-center gap-2 bg-white border border-line rounded-lg px-4 py-2 text-[13px] font-semibold hover:border-ink transition">
                <i class="fas fa-arrow-left"></i> Back to Layout
            </a>
        </div>

        <!-- ── Page Header ── -->
        <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
            <div class="text-[11px] font-semibold tracking-[1.5px] uppercase text-soft mb-2">
                <i class="fas fa-paperclip"></i> Attachments
            </div>
            <h1 class="text-2xl font-bold tracking-[-0.01em]"><?= htmlspecialchars($clientInfo['clientname']) ?></h1>
            <p class="text-[13.5px] text-soft mt-1"><?= htmlspecialchars($clientInfo['nameproject']) ?></p>
            <p class="text-[13px] text-muted mt-1">
                Ref: <?= htmlspecialchars($clientInfo['reference_number']) ?>
                &nbsp;•&nbsp; <?= htmlspecialchars($me['full_name']) ?>
            </p>
        </div>

        <?php
        $pendingApprovalCount = getPendingApprovalCount($conn, $admin_id, $client_id);
        if ($pendingApprovalCount > 0):
            ?>
            <div class="bg-amber-50 border border-amber-300 rounded-[10px] p-4 mb-[18px] flex items-center gap-3 flex-wrap">
                <i class="fas fa-bell text-amber-600 text-xl flex-shrink-0"></i>
                <div class="flex-1">
                    <div class="font-semibold text-sm text-amber-900">
                        You have <?= $pendingApprovalCount ?> pending
                        approval<?= $pendingApprovalCount > 1 ? 's' : '' ?> — click an area below to review
                    </div>
                    <div class="text-xs text-amber-700 mt-0.5">
                        Areas highlighted in amber below have pending approvals waiting for you.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($intake)): ?>
            <div class="bg-amber-50 border border-amber-300 text-amber-800 rounded-lg px-4 py-3 mb-4 text-[13px] font-medium flex items-center gap-2">
                <i class="fas fa-exclamation-triangle"></i>
                Please submit the intake form first before uploading attachments.
            </div>
        <?php elseif (empty($areas)): ?>
            <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
                <div class="text-center py-10 text-muted">
                    <i class="fas fa-inbox text-3xl mb-3 block"></i>
                    No areas found. Add items to the computation list first.
                </div>
            </div>
        <?php else: ?>
            <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
                <div class="flex items-center gap-2.5 text-xs font-semibold mb-4">
                    <i class="fas fa-map-marker-alt text-soft"></i> Select an Area
                    <span class="flex-1 h-px bg-line"></span>
                </div>

                <div class="flex flex-col gap-2.5">
                    <?php foreach ($areas as $area): ?>
                        <?php
                        $fileCount = countAreaAttachments($conn, $client_id, $area);
                        $url = BASE_URL . 'designer-attachment-upload?client_id=' . $client_id
                            . '&area=' . urlencode($area);

                        // Approval summary for color coding
                        $approvalSummaryStmt = $conn->prepare("
            SELECT la.status, la.comment, la.responded_at,
                   a.id as approver_id, a.full_name as approver_name, a.role as approver_role
            FROM layout_approvals la
            JOIN account a ON la.approver_id = a.id
            WHERE la.client_id = ? AND la.area = ?
            AND la.room_unit_number IS NULL
        ");
                        $approvalSummaryStmt->bind_param("is", $client_id, $area);
                        $approvalSummaryStmt->execute();
                        $approvalRows = $approvalSummaryStmt->get_result()->fetch_all(MYSQLI_ASSOC);

                        // Also get list of all approvers for this system
                        $allApproversStmt = $conn->prepare("
            SELECT id, full_name, role FROM account
            WHERE (role IN ('general_manager','operational_manager'))
               OR (role IN ('designer','technical_designer') AND is_head = 1)
            ORDER BY role
        ");
                        $allApproversStmt->execute();
                        $allApprovers = $allApproversStmt->get_result()->fetch_all(MYSQLI_ASSOC);

                        // Build map: approver_id => record
                        $areaApprovalMap = [];
                        foreach ($approvalRows as $rec) {
                            $areaApprovalMap[$rec['approver_id']] = $rec;
                        }

                        if (empty($approvalRows)) {
                            $areaApprovalState = 'none';
                        } else {
                            $aStatuses = array_column($approvalRows, 'status');
                            if (in_array('rejected', $aStatuses))
                                $areaApprovalState = 'rejected';
                            elseif (count(array_filter($aStatuses, fn($s) => $s === 'approved')) === count($aStatuses) && count($aStatuses) > 0)
                                $areaApprovalState = 'approved';
                            elseif (in_array('pending', $aStatuses))
                                $areaApprovalState = 'pending';
                            else
                                $areaApprovalState = 'none';
                        }

                        // Color scheme per approval state (adm- palette)
                        $cardBorderClass = 'border-line';
                        $cardBgClass = 'bg-white';
                        $approvalBadgeHtml = '';
                        if ($areaApprovalState === 'approved') {
                            $cardBorderClass = 'border-emerald-300';
                            $cardBgClass = 'bg-emerald-50';
                            $approvalBadgeHtml = '<span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800 inline-flex items-center gap-1"><i class="fas fa-check-circle"></i> All Approved</span>';
                        } elseif ($areaApprovalState === 'rejected') {
                            $cardBorderClass = 'border-red-300';
                            $cardBgClass = 'bg-red-50';
                            $approvalBadgeHtml = '<span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-red-100 text-red-800 inline-flex items-center gap-1"><i class="fas fa-times-circle"></i> Rejected</span>';
                        } elseif ($areaApprovalState === 'pending') {
                            $cardBorderClass = 'border-amber-300';
                            $cardBgClass = 'bg-amber-50';
                            $approvalBadgeHtml = '<span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800 inline-flex items-center gap-1"><i class="fas fa-hourglass-half"></i> Pending Review</span>';
                        }

                        // Build approver badges HTML
                        $approverBadgesHtml = '';
                        if (!empty($allApprovers)) {
                            $approverBadgesHtml .= '<div class="flex flex-wrap gap-1.5 mt-2">';
                            foreach ($allApprovers as $apr) {
                                $rec = $areaApprovalMap[$apr['id']] ?? null;
                                $aStatus = $rec ? $rec['status'] : 'not_requested';

                                if ($aStatus === 'approved') {
                                    $bClasses = 'bg-emerald-100 text-emerald-800';
                                    $bIcon = 'fa-check-circle';
                                    $bTitle = htmlspecialchars($apr['full_name']) . ': Approved';
                                } elseif ($aStatus === 'rejected') {
                                    $bClasses = 'bg-red-100 text-red-800';
                                    $bIcon = 'fa-times-circle';
                                    $bTitle = htmlspecialchars($apr['full_name']) . ': Rejected';
                                    if ($rec['comment'])
                                        $bTitle .= ' — ' . htmlspecialchars($rec['comment']);
                                } elseif ($aStatus === 'pending') {
                                    $bClasses = 'bg-amber-100 text-amber-800';
                                    $bIcon = 'fa-hourglass-half';
                                    $bTitle = htmlspecialchars($apr['full_name']) . ': Pending';
                                } else {
                                    $bClasses = 'bg-[#F5F5F5] text-muted';
                                    $bIcon = 'fa-minus-circle';
                                    $bTitle = htmlspecialchars($apr['full_name']) . ': Not requested';
                                }

                                $shortName = explode(' ', $apr['full_name'])[0]; // First name only
                                $approverBadgesHtml .= '<span title="' . $bTitle . '" class="inline-flex items-center gap-1 ' . $bClasses . ' px-2 py-0.5 rounded-full text-[11px] font-bold">';
                                $approverBadgesHtml .= '<i class="fas ' . $bIcon . ' text-[10px]"></i> ' . htmlspecialchars($shortName);
                                $approverBadgesHtml .= '</span>';
                            }
                            $approverBadgesHtml .= '</div>';
                        }

                        // Check if this area (no unit) has an active revision
                        $areaRevKey = $area . '||null';
                        $areaActiveRev = $activeRevMap[$areaRevKey] ?? null;
                        ?>

                        <?php if ($areaActiveRev): ?>
                            <div class="bg-amber-50 border border-amber-300 rounded-lg px-3.5 py-2 flex items-center gap-2 flex-wrap text-[12px] font-semibold text-amber-800">
                                <i class="fas fa-redo"></i>
                                Revision #<?= $areaActiveRev['revision_number'] ?> Pending
                                <span class="font-normal"><?= date('M d, Y', strtotime($areaActiveRev['created_at'])) ?></span>
                                <span class="font-normal italic"><?= htmlspecialchars(mb_strimwidth($areaActiveRev['reason'], 0, 60, '...')) ?></span>
                            </div>
                        <?php endif; ?>

                        <a href="<?= $url ?>"
                            class="flex items-center justify-between gap-3 px-5 py-4 border rounded-lg transition hover:border-ink hover:bg-[#F5F5F5] <?= $areaActiveRev ? 'border-amber-300 bg-amber-50' : $cardBorderClass . ' ' . $cardBgClass ?>">
                            <div class="flex items-center gap-3">
                                <div class="w-11 h-11 bg-[#F5F5F5] border border-line rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-layer-group text-soft"></i>
                                </div>
                                <div>
                                    <div class="text-[15px] font-bold"><?= htmlspecialchars($area) ?></div>
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <?php if ($fileCount > 0): ?>
                                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-[#F5F5F5] border border-line text-soft inline-flex items-center gap-1">
                                                <i class="fas fa-file"></i> <?= $fileCount ?> file(s)
                                            </span>
                                        <?php endif; ?>
                                        <?= $approvalBadgeHtml ?>
                                    </div>
                                    <?= $approverBadgesHtml ?>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right text-muted flex-shrink-0"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>
</body>

</html>