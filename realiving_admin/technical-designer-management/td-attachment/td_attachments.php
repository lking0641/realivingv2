<?php
// td_attachments.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

// ── Pending approval notif for TD ──
function getTDPendingApprovalCount($conn, $admin_id, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM td_attachment_approvals la
        WHERE la.client_id = ? AND la.approver_id = ? AND la.status = 'pending'
        AND la.requested_at IS NOT NULL
        AND NOT EXISTS (
            SELECT 1 FROM td_revision_log rl
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

$allowedRoles = ['technical_designer', 'general_manager', 'operational_manager'];
if (!in_array($me['role'], $allowedRoles))
    die("Access denied.");

$canViewAll = in_array($me['role'], ['general_manager', 'operational_manager'])
    || ($me['role'] === 'technical_designer' && $me['is_head'] == 1);

$ciStmt = $conn->prepare("SELECT technical_designer_id, clientname, nameproject, reference_number FROM user_info WHERE id = ?");
$ciStmt->bind_param("i", $client_id);
$ciStmt->execute();
$clientInfo = $ciStmt->get_result()->fetch_assoc();
if (!$clientInfo)
    die("Client not found.");

$isAssigned = ($clientInfo['technical_designer_id'] == $admin_id);
if (!$isAssigned && !$canViewAll)
    die("Access denied.");

// Distinct areas
$areasStmt = $conn->prepare("
    SELECT DISTINCT area FROM quotation_entries WHERE client_id = ?
    UNION
    SELECT DISTINCT area FROM quotation_fixed_sizes WHERE client_id = ?
    ORDER BY area
");
$areasStmt->bind_param("ii", $client_id, $client_id);
$areasStmt->execute();
$areas = array_column($areasStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'area');

function tdCountAreaFiles($conn, $client_id, $area)
{
    $s = $conn->prepare("SELECT COUNT(*) FROM td_attachments WHERE client_id=? AND area=?");
    $s->bind_param("is", $client_id, $area);
    $s->execute();
    return $s->get_result()->fetch_row()[0] ?? 0;
}

// Fetch pending revisions for this client
$revStmt = $conn->prepare("
    SELECT area, room_unit_number, reason, revision_number, created_at
    FROM td_revision_log WHERE client_id=? AND status='pending' ORDER BY created_at DESC
");
$revStmt->bind_param("i", $client_id);
$revStmt->execute();
$revRows = $revStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$revMap = [];
foreach ($revRows as $rv)
    $revMap[$rv['area'] . '||' . ($rv['room_unit_number'] ?? 'null')] = $rv;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TD Attachments — <?= htmlspecialchars($clientInfo['clientname']) ?></title>
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
            <a href="td-layout?client_id=<?= $client_id ?>"
                class="inline-flex items-center gap-2 bg-white border border-line rounded-lg px-4 py-2 text-[13px] font-semibold hover:border-ink transition">
                <i class="fas fa-arrow-left"></i> Back to TD Layout
            </a>
        </div>

        <!-- ── Page Header ── -->
        <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
            <div class="text-[11px] font-semibold tracking-[1.5px] uppercase text-soft mb-2">
                <i class="fas fa-paperclip"></i> TD Attachments
            </div>
            <h1 class="text-2xl font-bold tracking-[-0.01em]"><?= htmlspecialchars($clientInfo['clientname']) ?></h1>
            <p class="text-[13.5px] text-soft mt-1"><?= htmlspecialchars($clientInfo['nameproject']) ?></p>
            <p class="text-[13px] text-muted mt-1">
                Ref: <?= htmlspecialchars($clientInfo['reference_number']) ?>
                &nbsp;•&nbsp; <?= htmlspecialchars($me['full_name']) ?>
            </p>
        </div>

        <?php
        $tdPendingCount = getTDPendingApprovalCount($conn, $admin_id, $client_id);
        if ($tdPendingCount > 0):
            ?>
            <div class="bg-amber-50 border border-amber-300 rounded-[10px] p-4 mb-[18px] flex items-center gap-3 flex-wrap">
                <i class="fas fa-bell text-amber-600 text-xl flex-shrink-0"></i>
                <div class="flex-1">
                    <div class="font-semibold text-sm text-amber-900">
                        You have <?= $tdPendingCount ?> pending approval<?= $tdPendingCount > 1 ? 's' : '' ?> — click an area below to review
                    </div>
                    <div class="text-xs text-amber-700 mt-0.5">
                        Areas highlighted in amber below have pending approvals waiting for you.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($areas)): ?>
            <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
                <div class="text-center py-10 text-muted">
                    <i class="fas fa-inbox text-3xl mb-3 block"></i>
                    No areas found. The designer needs to add items to the computation list first.
                </div>
            </div>
        <?php else: ?>
            <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
                <div class="flex items-center gap-2.5 text-xs font-semibold mb-4">
                    <i class="fas fa-map-marker-alt text-soft"></i> Select an Area
                    <span class="flex-1 h-px bg-line"></span>
                </div>

                <div class="flex flex-col gap-2.5">
                    <?php foreach ($areas as $area):
                        $fileCount = tdCountAreaFiles($conn, $client_id, $area);
                        $revKey = $area . '||null';
                        $areaRev = $revMap[$revKey] ?? null;
                        $url = BASE_URL . 'td-attachment-upload?client_id=' . $client_id . '&area=' . urlencode($area);

                        // Approval state for this area
                        $apSt = $conn->prepare("SELECT taa.status, a.full_name, a.role FROM td_attachment_approvals taa JOIN account a ON taa.approver_id=a.id WHERE taa.client_id=? AND taa.area=? AND taa.room_unit_number IS NULL");
                        $apSt->bind_param("is", $client_id, $area);
                        $apSt->execute();
                        $apRows = $apSt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $apStatuses = array_column($apRows, 'status');

                        if (empty($apRows)) {
                            $cardBorderClass = 'border-line';
                            $cardBgClass = 'bg-white';
                        } elseif (in_array('rejected', $apStatuses)) {
                            $cardBorderClass = 'border-red-300';
                            $cardBgClass = 'bg-red-50';
                        } elseif (count(array_filter($apStatuses, fn($s) => $s === 'approved')) === count($apStatuses)) {
                            $cardBorderClass = 'border-emerald-300';
                            $cardBgClass = 'bg-emerald-50';
                        } elseif (in_array('pending', $apStatuses)) {
                            $cardBorderClass = 'border-amber-300';
                            $cardBgClass = 'bg-amber-50';
                        } else {
                            $cardBorderClass = 'border-line';
                            $cardBgClass = 'bg-white';
                        }

                        // Check if remark is needed for this area
                        $remarkAreaNeeded = false;
                        if ($isAssigned) {
                            $rmkStmt = $conn->prepare("
            SELECT COUNT(*) FROM layout_approvals
            WHERE client_id = ? AND area = ?
            AND room_unit_number IS NULL
            AND (td_remark IS NULL OR td_remark = '')
            AND requested_at IS NOT NULL
        ");
                            $rmkStmt->bind_param("is", $client_id, $area);
                            $rmkStmt->execute();
                            $remarkAreaNeeded = (int) $rmkStmt->get_result()->fetch_row()[0] > 0;
                        }

                        // Build per-approver badges
                        $apBadge = '';
                        if (!empty($apRows)) {
                            foreach ($apRows as $apr) {
                                if ($apr['status'] === 'approved') {
                                    $bClasses = 'bg-emerald-100 text-emerald-800';
                                    $bIcon = 'fa-check-circle';
                                } elseif ($apr['status'] === 'rejected') {
                                    $bClasses = 'bg-red-100 text-red-800';
                                    $bIcon = 'fa-times-circle';
                                } else {
                                    $bClasses = 'bg-amber-100 text-amber-800';
                                    $bIcon = 'fa-hourglass-half';
                                }
                                $shortName = explode(' ', trim($apr['full_name']))[0]; // first name only
                                $apBadge .= '<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold ' . $bClasses . '"><i class="fas ' . $bIcon . '"></i> ' . htmlspecialchars($shortName) . '</span>';
                            }
                        }

                        $finalBorderClass = $areaRev ? 'border-amber-300' : ($remarkAreaNeeded ? 'border-blue-300' : $cardBorderClass);
                        $finalBgClass = $areaRev ? 'bg-amber-50' : ($remarkAreaNeeded ? 'bg-blue-50' : $cardBgClass);
                        ?>
                        <?php if ($areaRev): ?>
                            <div class="bg-amber-50 border border-amber-300 rounded-lg px-3.5 py-2 flex items-center gap-2 flex-wrap text-[12px] font-semibold text-amber-800">
                                <i class="fas fa-redo"></i>
                                Revision #<?= $areaRev['revision_number'] ?> Pending
                                <span class="font-normal"><?= date('M d, Y', strtotime($areaRev['created_at'])) ?></span>
                                <span class="font-normal italic"><?= htmlspecialchars(mb_strimwidth($areaRev['reason'], 0, 60, '...')) ?></span>
                            </div>
                        <?php endif; ?>

                        <a href="<?= $url ?>"
                            class="flex items-center justify-between gap-3 px-5 py-4 border rounded-lg transition hover:border-ink hover:bg-[#F5F5F5] <?= $finalBorderClass ?> <?= $finalBgClass ?>">
                            <div class="flex items-center gap-3">
                                <div class="w-11 h-11 bg-[#F5F5F5] border border-line rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-layer-group text-soft"></i>
                                </div>
                                <div>
                                    <div class="text-[15px] font-bold"><?= htmlspecialchars($area) ?></div>
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <?php if ($fileCount > 0): ?>
                                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800 inline-flex items-center gap-1">
                                                <i class="fas fa-file"></i> <?= $fileCount ?> file(s)
                                            </span>
                                        <?php endif; ?>
                                        <?= $apBadge ?>
                                        <?php if ($remarkAreaNeeded): ?>
                                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-50 text-blue-800 border border-blue-300 inline-flex items-center gap-1">
                                                <i class="fas fa-comment-medical"></i> Remark Needed
                                            </span>
                                        <?php endif; ?>
                                    </div>
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