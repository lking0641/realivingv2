<?php
// item_tracker.php
include $includes ['mainbody'];

$admin_id = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$stage = isset($_GET['stage']) ? $_GET['stage'] : '';

$valid_stages = ['Fabrication', 'Delivery', 'Installation', 'BILLING'];
if (!in_array($stage, $valid_stages))
    die("Invalid stage");

// Get admin role
$roleStmt = $conn->prepare("SELECT role, full_name, is_head FROM account WHERE id = ?");
$roleStmt->bind_param("i", $admin_id);
$roleStmt->execute();
$userInfo = $roleStmt->get_result()->fetch_assoc();
$admin_role = $userInfo['role'];

$allowedRolesForAllClients = ['general_manager', 'operational_manager', 'designer', 'technical_designer', 'accounting', 'superadmin', 'project_coordinator'];
$canViewAllClients = in_array($admin_role, $allowedRolesForAllClients);

// Fetch client
if ($canViewAllClients) {
    $clientStmt = $conn->prepare("SELECT u.*, a.full_name as admin_name, a.role as admin_role FROM user_info u LEFT JOIN account a ON u.accountaid_fk = a.id WHERE u.id = ?");
    $clientStmt->bind_param("i", $client_id);
} else {
    $clientStmt = $conn->prepare("SELECT u.*, a.full_name as admin_name, a.role as admin_role FROM user_info u LEFT JOIN account a ON u.accountaid_fk = a.id WHERE u.id = ? AND u.accountaid_fk = ?");
    $clientStmt->bind_param("ii", $client_id, $admin_id);
}
$clientStmt->execute();
$client = $clientStmt->get_result()->fetch_assoc();
if (!$client)
    die("Access denied.");

// Permissions
if ($admin_role === 'sales') {
    $permStmt = $conn->prepare("SELECT stage_name, can_update FROM stage_permissions WHERE admin_id = ?");
    $permStmt->bind_param("i", $admin_id);
} else {
    $permStmt = $conn->prepare("SELECT stage_name, can_update FROM role_stage_permissions WHERE role = ?");
    $permStmt->bind_param("s", $admin_role);
}
$permStmt->execute();
$permResult = $permStmt->get_result();
$permissions = [];
while ($perm = $permResult->fetch_assoc()) {
    $permissions[$perm['stage_name']] = (bool) $perm['can_update'];
}
$came_from = isset($_GET['came_from']) ? $_GET['came_from'] : '';
$view_only = isset($_GET['view_only']) && $_GET['view_only'] == '1';
$canUpdate = $permissions[$stage] ?? false;

// Fetch assigned staff for this client
$assignedStaffStmt = $conn->prepare("SELECT technical_designer_id FROM user_info WHERE id = ?");
$assignedStaffStmt->bind_param("i", $client_id);
$assignedStaffStmt->execute();
$assignedStaffRow = $assignedStaffStmt->get_result()->fetch_assoc();

// Allow technical designer head OR assigned technical designer to access
$isTechDesignerAccess = $admin_role === 'technical_designer' && (
    $userInfo['is_head'] == 1 ||
    $admin_id == ($assignedStaffRow['technical_designer_id'] ?? null)
);

if (!$canUpdate && !$view_only && !$isTechDesignerAccess) {
    header("Location: " . BASE_URL . "unified-project-tracker?client_id=" . $client_id);
    exit();
}

// If TD has no explicit update permission, force view_only
if (!$canUpdate && $isTechDesignerAccess) {
    $view_only = true;
}

// If view_only flag set, force canUpdate off
if ($view_only)
    $canUpdate = false;

$statusColumn = strtolower($stage) . '_status';
$updatedColumn = strtolower($stage) . '_updated_at';

// Statuses per stage
$isInstallation = ($stage === 'Installation');
$stageStatuses = $isInstallation
    ? ['Pending', 'Ongoing', 'Done', 'Incomplete', 'Punchlist']
    : ['Pending', 'Ongoing', 'Done'];

// ── Fetch quotation_entries ──
$itemsStmt = $conn->prepare("
    SELECT
        qe.id,
        qe.area,
        qe.quantity,
        qe.unit_type        AS unit,
        qe.computed_tot_amount,
        qe.$statusColumn    AS status,
        qe.$updatedColumn   AS updated_at,
        COALESCE(qe.item_name, i.item_name) AS item_name,
        qe.color_label
    FROM quotation_entries qe
    LEFT JOIN items i ON qe.entry_item_id = i.item_id
    WHERE qe.client_id = ?
    ORDER BY qe.area, COALESCE(qe.item_name, i.item_name)
");
$itemsStmt->bind_param("i", $client_id);
$itemsStmt->execute();
$rawItems = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Fetch quotation_fixed_sizes ──
$fixedStmt = $conn->prepare("
    SELECT
        qfs.id,
        qfs.area,
        qfs.quantity,
        qfs.unit_type                       AS unit,
        (qfs.base_price * qfs.quantity)     AS computed_tot_amount,
        qfs.$statusColumn                   AS status,
        qfs.$updatedColumn                  AS updated_at,
        qfs.item_name,
        qfs.color_label
    FROM quotation_fixed_sizes qfs
    WHERE qfs.client_id = ?
    ORDER BY qfs.area, qfs.item_name
");
$fixedStmt->bind_param("i", $client_id);
$fixedStmt->execute();
$rawFixed = $fixedStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Tag source
foreach ($rawItems as &$r) {
    $r['source'] = 'entry';
}
foreach ($rawFixed as &$r) {
    $r['source'] = 'fixed';
}
unset($r);

// ── Room distributions removed (quotation_room_distribution table no longer exists) ──
$distRows = [];

// Index distributions by entry_id and fixed_id
$distByEntry = [];
$distByFixed = [];
foreach ($distRows as $d) {
    if ($d['source'] === 'entry' && $d['entry_id']) {
        $distByEntry[$d['entry_id']][] = $d;
    } elseif ($d['source'] === 'fixed' && $d['fixed_id']) {
        $distByFixed[$d['fixed_id']][] = $d;
    }
}

// ── Load area group labels FIRST before grouping ──
$groupLabels = [];
$glStmt = $conn->prepare("SELECT area, group_label FROM timeline_area_groups WHERE client_id=?");
$glStmt->bind_param("i", $client_id);
$glStmt->execute();
foreach ($glStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $gl) {
    $groupLabels[$gl['area']] = $gl['group_label'];
}

// Merge & group by area
$allItems = array_merge($rawItems, $rawFixed);
$areaGroups = [];
foreach ($allItems as $item) {
    $areaGroups[$item['area']][] = $item;
}
ksort($areaGroups);

// Build group-based display structure
$groupedDisplay = [];
$ungroupedDisplay = [];

foreach ($areaGroups as $area => $items) {
    $lbl = $groupLabels[$area] ?? null;
    if ($lbl) {
        if (!isset($groupedDisplay[$lbl])) {
            $groupedDisplay[$lbl] = ['label' => $lbl, 'areas' => [], 'type' => 'group'];
        }
        $groupedDisplay[$lbl]['areas'][] = $area;
    } else {
        $ungroupedDisplay[$area] = $items;
    }
}

// Build per-item previous-stage status map — item-level only, no unit logic
$prevStageStatusMap = [];
$stageDependencies = [
    'Delivery' => 'Fabrication',
    'Installation' => 'Delivery',
];
if (isset($stageDependencies[$stage])) {
    $prevStage = $stageDependencies[$stage];
    $prevStageCol = strtolower($prevStage) . '_status';

    $prevEntryStmt = $conn->prepare("SELECT id, COALESCE($prevStageCol, 'Pending') AS prev_status FROM quotation_entries WHERE client_id = ?");
    $prevEntryStmt->bind_param("i", $client_id);
    $prevEntryStmt->execute();
    foreach ($prevEntryStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $prevStageStatusMap['entry:' . $r['id']] = $r['prev_status'];
    }

    $prevFixedStmt = $conn->prepare("SELECT id, COALESCE($prevStageCol, 'Pending') AS prev_status FROM quotation_fixed_sizes WHERE client_id = ?");
    $prevFixedStmt->bind_param("i", $client_id);
    $prevFixedStmt->execute();
    foreach ($prevFixedStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $prevStageStatusMap['fixed:' . $r['id']] = $r['prev_status'];
    }
}

// ── Fetch area timelines ──
$tlStmt = $conn->prepare("SELECT * FROM timeline_area WHERE client_id = ?");
$tlStmt->bind_param("i", $client_id);
$tlStmt->execute();
$timelines = [];
foreach ($tlStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $tl) {
    $timelines[$tl['area']] = $tl;
}

// Overall timeline
$overallStart = $client['overall_start_date'] ?? '';
$overallEnd = $client['overall_end_date'] ?? '';
$overallDuration = $client['overall_duration'] ?? 0;

// Aggregate counts (count unit rows if they exist, else count item rows)
$statusCounts = array_fill_keys($stageStatuses, 0);
$total_items = 0;
foreach ($allItems as $it) {
    $units = ($it['source'] === 'entry')
        ? ($distByEntry[$it['id']] ?? [])
        : ($distByFixed[$it['id']] ?? []);

    if (!empty($units)) {
        foreach ($units as $u) {
            $s = $u['unit_status'] ?: 'Pending';
            if (isset($statusCounts[$s]))
                $statusCounts[$s]++;
            $total_items++;
        }
    } else {
        $s = $it['status'] ?: 'Pending';
        if (isset($statusCounts[$s]))
            $statusCounts[$s]++;
        $total_items++;
    }
}
$done_count = $statusCounts['Done'] ?? 0;
$completion_percentage = $total_items > 0 ? ($done_count / $total_items) * 100 : 0;

// ── Icon per stage (unchanged) ──
$stageIcon = $stage === 'Fabrication' ? 'tools' : ($stage === 'Delivery' ? 'truck' : ($stage === 'Installation' ? 'hard-hat' : 'file-invoice-dollar'));

// ── Tailwind chrome constants — same black/white/gray palette as
//    coordinator_timeline.php (ink #0B0B0B, soft #6B6B6B, line #E2E2E2,
//    surface #F5F5F5). Every value below is a literal, fully-spelled-out
//    Tailwind utility string (never built via concatenation) so the JIT
//    scanner can find it in this file at build time. ──
$C_CARD       = "bg-white border border-[#E2E2E2] rounded-[10px] overflow-hidden mb-5";
$C_CARD_PAD   = "bg-white border border-[#E2E2E2] rounded-[10px] p-6 md:p-[26px] mb-5";
$C_CARD_TITLE = "font-sans text-[15px] font-bold text-[#0B0B0B] mb-5 pb-3.5 border-b border-[#E2E2E2] flex items-center gap-2.5";
$C_BTN_SAVE   = "bg-[#0B0B0B] text-white px-6 py-2.5 border-0 rounded-[9px] cursor-pointer text-[13px] font-bold font-sans inline-flex items-center gap-2 transition-opacity hover:opacity-85 tracking-[0.3px]";
$C_BTN_BACK   = "bg-[#0B0B0B] text-white px-[18px] py-[9px] rounded-[9px] font-semibold text-[13px] inline-flex items-center gap-[7px] no-underline mb-[18px] hover:opacity-85 transition-opacity";
$C_BADGE      = "px-3 py-[3px] rounded-full text-[11px] font-bold bg-[#F5F5F5] border border-[#E2E2E2] text-[#0B0B0B]";

// ── Tailwind stage accent map. Only 4 fixed stages exist, so every class
//    below is a literal string the JIT scanner can find in this file. ──
$STAGE_TW = [
    'Fabrication'  => ['icon' => 'text-blue-500',    'dot' => 'text-blue-500'],
    'Delivery'     => ['icon' => 'text-violet-500',  'dot' => 'text-violet-500'],
    'Installation' => ['icon' => 'text-emerald-500', 'dot' => 'text-emerald-500'],
    'BILLING'      => ['icon' => 'text-amber-500',   'dot' => 'text-amber-500'],
];
$stw = $STAGE_TW[$stage] ?? $STAGE_TW['Fabrication'];

// ── Status → Tailwind classes (mirrors old $statusConfig hex map) ──
$STATUS_TW = [
    'Pending'    => ['border' => 'border-amber-500',   'text' => 'text-amber-600',   'badgeBg' => 'bg-amber-100',   'badgeText' => 'text-amber-800',   'badgeBorder' => 'border-amber-500',   'rowBg' => 'bg-amber-50',   'btnBg' => 'bg-amber-100',   'btnText' => 'text-amber-800'],
    'Ongoing'    => ['border' => 'border-blue-500',    'text' => 'text-blue-600',    'badgeBg' => 'bg-blue-100',    'badgeText' => 'text-blue-800',    'badgeBorder' => 'border-blue-500',    'rowBg' => 'bg-blue-50',    'btnBg' => 'bg-blue-100',    'btnText' => 'text-blue-800'],
    'Done'       => ['border' => 'border-emerald-500', 'text' => 'text-emerald-600', 'badgeBg' => 'bg-emerald-100', 'badgeText' => 'text-emerald-800', 'badgeBorder' => 'border-emerald-500', 'rowBg' => 'bg-emerald-50', 'btnBg' => 'bg-emerald-100', 'btnText' => 'text-emerald-800'],
    'Incomplete' => ['border' => 'border-red-500',     'text' => 'text-red-600',     'badgeBg' => 'bg-red-100',     'badgeText' => 'text-red-800',     'badgeBorder' => 'border-red-500',     'rowBg' => 'bg-red-50',     'btnBg' => 'bg-red-100',     'btnText' => 'text-red-800'],
    'Punchlist'  => ['border' => 'border-pink-500',    'text' => 'text-pink-600',    'badgeBg' => 'bg-pink-100',    'badgeText' => 'text-pink-800',    'badgeBorder' => 'border-pink-500',    'rowBg' => 'bg-pink-50',    'btnBg' => 'bg-pink-100',    'btnText' => 'text-pink-800'],
];

// ── Unit-block header state → Tailwind classes. Mirrors the same
//    done / partial / none language coordinator_timeline.php uses for
//    its C_PILL_DONE / C_PILL_PARTIAL / C_PILL_NONE badges. ──
$UNIT_STATE_TW = [
    'done'    => ['hdrBg' => 'bg-emerald-50', 'hdrText' => 'text-emerald-800', 'hdrBorder' => 'border-emerald-300', 'badge' => 'bg-emerald-600', 'icon' => 'fa-check-circle'],
    'ongoing' => ['hdrBg' => 'bg-amber-50',   'hdrText' => 'text-amber-800',   'hdrBorder' => 'border-amber-300',   'badge' => 'bg-amber-600',   'icon' => 'fa-spinner'],
    'pending' => ['hdrBg' => 'bg-[#F5F5F5]',  'hdrText' => 'text-[#6B6B6B]',   'hdrBorder' => 'border-[#E2E2E2]',   'badge' => 'bg-[#0B0B0B]',   'icon' => 'fa-clock'],
];

// Pre-check stage dependencies for UI locking
$stageDepLocked = false;
$stageDepMessage = '';
$stageDependencies = [
    'Delivery' => 'Fabrication',
    'Installation' => 'Delivery',
];
// No full-stage lock — per-item locking is handled in the render below

// ── Renders a group's Fab/Del/Ins date bar. Position/width are per-record
//    percentages, so they still need an inline style — everything else
//    (color, layout, typography) is a literal Tailwind class. ──
function renderTimelineBar($tl)
{
    $phases = [
        'fab' => ['label' => 'Fab', 'bar' => 'bg-blue-500', 'text' => 'text-blue-600'],
        'del' => ['label' => 'Del', 'bar' => 'bg-violet-500', 'text' => 'text-violet-600'],
        'ins' => ['label' => 'Ins', 'bar' => 'bg-emerald-500', 'text' => 'text-emerald-600'],
    ];
    $dates = [];
    foreach ($phases as $k => $_) {
        if (!empty($tl[$k . '_start']))
            $dates[] = strtotime($tl[$k . '_start']);
        if (!empty($tl[$k . '_end']))
            $dates[] = strtotime($tl[$k . '_end']);
    }
    if (empty($dates))
        return '';
    $min = min($dates);
    $max = max($dates);
    $total = max(1, $max - $min);

    $html = '<div class="relative h-5 bg-[#E2E2E2] rounded-[5px] overflow-visible mb-1.5">';
    foreach ($phases as $k => $ph) {
        if (empty($tl[$k . '_start']) || empty($tl[$k . '_end']))
            continue;
        $s = $tl[$k . '_start'];
        $e = $tl[$k . '_end'];
        $dur = $tl[$k . '_duration'] ?? 0;
        $l = round((strtotime($s) - $min) / $total * 100, 1);
        $w = max(round((strtotime($e) - strtotime($s)) / $total * 100, 1), 2);
        $html .= '<div class="absolute top-0 h-full rounded-[5px] flex items-center overflow-hidden transition-opacity duration-150 hover:opacity-80 hover:z-10 cursor-default ' . $ph['bar'] . '"'
               . ' style="left:' . $l . '%;width:' . $w . '%;"'
               . ' title="' . $ph['label'] . ': ' . date('M d', strtotime($s)) . ' \xE2\x80\x93 ' . date('M d', strtotime($e)) . ' (' . $dur . 'd)">'
               . '<span class="text-white text-[9px] font-extrabold pl-[5px] uppercase pointer-events-none">' . $ph['label'] . '</span></div>';
    }
    $html .= '</div><div class="flex gap-3 text-[10px] flex-wrap">';
    foreach ($phases as $k => $ph) {
        if (empty($tl[$k . '_start']))
            continue;
        $html .= '<span class="font-bold ' . $ph['text'] . '">' . $ph['label'] . ': '
            . date('M d', strtotime($tl[$k . '_start'])) . ' \xE2\x80\x93 ' . date('M d', strtotime($tl[$k . '_end']))
            . ' <em class="not-italic opacity-70">(' . (($tl[$k . '_duration']) ?? 0) . 'd)</em></span>';
    }
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= htmlspecialchars($stage) ?> Tracker — <?= htmlspecialchars($client['clientname']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!--
      Pure Tailwind build. No custom classes below — only the keyframe
      animation for the toast (Tailwind has no built-in slide-in-from-right
      animation), plus the accent color for native checkboxes. Every other
      dynamic state (row status colors, unit-block state, tab-like toggles)
      is applied via literal Tailwind class strings, either straight from
      PHP or from the JS class maps at the bottom of this file, so the JIT
      compiler can find them all in this bundle.
    -->
    <style>
        body { font-family: 'Inter', sans-serif; }
        @keyframes toastSlideIn { from { transform: translateX(360px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .anim-toast-in { animation: toastSlideIn .3s ease; }
        @keyframes popIn { from { transform: scale(.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .anim-pop-in { animation: popIn .2s ease; }
    </style>
</head>

<body class="bg-[#F5F5F5] text-[#0B0B0B] font-sans">
    <div class="max-w-[1400px] mx-auto px-5 py-7">

        <?php
        if ($came_from === 'manager') {
            $backHref = BASE_URL . 'manager-project-detail?client_id=' . $client_id;
            $backLabel = 'Back to Project Detail';
        } else {
            $backHref = BASE_URL . 'unified-project-tracker?client_id=' . $client_id;
            $backLabel = 'Back to Project Tracker';
        }
        ?>
        <a href="<?= $backHref ?>" class="<?= $C_BTN_BACK ?>">
            <i class="fas fa-arrow-left"></i> <?= $backLabel ?>
        </a>

        <?php if ($view_only): ?>
            <div class="px-4 py-3 rounded-[10px] mb-4 text-[13px] font-semibold flex items-center gap-2 bg-amber-100 text-amber-800 border-l-4 border-amber-500">
                <i class="fas fa-eye"></i> View Only — You can view this stage but cannot make changes.
            </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="bg-white border border-[#E2E2E2] px-[30px] py-6 rounded-[10px] text-[#0B0B0B] mb-6 relative">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                    <div class="text-[11px] font-semibold tracking-[1.5px] uppercase text-[#6B6B6B] mb-1.5">Item Tracking</div>
                    <h1 class="font-sans text-2xl font-bold tracking-[-0.01em] flex items-center gap-2.5">
                        <i class="fas fa-<?= $stageIcon ?>"></i>
                        <?= htmlspecialchars($stage) ?> Tracker
                    </h1>
                    <p class="text-[13px] opacity-85 mt-1.5 text-[#6B6B6B]">
                        <?= htmlspecialchars($client['clientname']) ?> &nbsp;·&nbsp;
                        <?= htmlspecialchars($client['nameproject']) ?>
                        <?php if ($client['reference_number']): ?>
                            &nbsp;·&nbsp;<span class="font-mono"><?= htmlspecialchars($client['reference_number']) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <span class="<?= $C_BADGE ?> flex items-center gap-[7px]">
                    <i class="fas fa-circle text-[8px] <?= $stw['dot'] ?>"></i>
                    <?= htmlspecialchars($stage) ?> Stage
                </span>
            </div>
        </div>

        <!-- Overall Timeline Banner -->
        <?php if ($overallStart): ?>
            <div class="<?= $C_CARD_PAD ?> !py-3.5 !px-[22px] !mb-[18px] flex items-center gap-4 flex-wrap">
                <div>
                    <div class="text-[11px] font-bold text-[#0B0B0B] uppercase tracking-[0.4px]"><i class="fas fa-calendar-alt"></i> Overall Project Timeline</div>
                    <div class="text-[13px] font-semibold text-[#6B6B6B]">
                        <?= date('F d, Y', strtotime($overallStart)) ?> &rarr;
                        <?= date('F d, Y', strtotime($overallEnd)) ?>
                    </div>
                </div>
                <span class="bg-[#0B0B0B] text-white px-3 py-[3px] rounded-[10px] text-[11px] font-bold"><?= $overallDuration ?> days</span>
                <?php
                $allTlDates = [];
                foreach ($timelines as $tl) {
                    foreach (['fab', 'del', 'ins'] as $k) {
                        if (!empty($tl[$k . '_start']))
                            $allTlDates[] = strtotime($tl[$k . '_start']);
                        if (!empty($tl[$k . '_end']))
                            $allTlDates[] = strtotime($tl[$k . '_end']);
                    }
                }
                $oMin = $allTlDates ? min($allTlDates) : strtotime($overallStart);
                $oMax = $allTlDates ? max($allTlDates) : strtotime($overallEnd);
                $oTot = max(1, $oMax - $oMin);
                $phClasses = ['fab' => 'bg-blue-500', 'del' => 'bg-violet-500', 'ins' => 'bg-emerald-500'];
                ?>
                <div class="flex-1 min-w-[180px] relative h-3 bg-[#E2E2E2] rounded-md overflow-hidden">
                    <?php foreach ($timelines as $areaTl):
                        foreach ($phClasses as $k => $cls):
                            if (empty($areaTl[$k . '_start']))
                                continue;
                            $s = strtotime($areaTl[$k . '_start']);
                            $e = strtotime($areaTl[$k . '_end']);
                            $l = round(($s - $oMin) / $oTot * 100, 1);
                            $w = max(round(($e - $s) / $oTot * 100, 1), 1);
                            ?>
                            <div class="absolute top-0 h-full <?= $cls ?> opacity-65" style="left:<?= $l ?>%;width:<?= $w ?>%;"></div>
                        <?php endforeach; endforeach; ?>
                </div>
                <div class="flex gap-2.5 text-[11px] font-bold">
                    <span class="text-blue-500">● Fab</span>
                    <span class="text-violet-500">● Del</span>
                    <span class="text-emerald-500">● Ins</span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Progress -->
        <div class="<?= $C_CARD_PAD ?>">
            <h2 class="<?= $C_CARD_TITLE ?> !mb-3.5">
                <i class="fas fa-chart-pie <?= $stw['icon'] ?>"></i> <?= htmlspecialchars($stage) ?> Progress
                <span class="text-[11px] font-normal text-[#6B6B6B] ml-1.5">(counting per unit where distributed)</span>
            </h2>
            <div class="flex gap-2.5 flex-wrap mb-3.5">
                <?php foreach ($stageStatuses as $st): $stw2 = $STATUS_TW[$st]; ?>
                    <div class="flex-1 basis-[90px] min-w-[80px] bg-[#F5F5F5] rounded-[10px] px-3.5 py-3 text-center border-2 <?= $stw2['border'] ?>">
                        <div class="text-[28px] font-extrabold leading-none <?= $stw2['text'] ?>"><?= $statusCounts[$st] ?? 0 ?></div>
                        <div class="text-[10px] font-bold text-[#6B6B6B] uppercase tracking-[0.4px] mt-[3px]"><?= $st ?></div>
                    </div>
                <?php endforeach; ?>
                <div class="flex-1 basis-[90px] min-w-[80px] bg-[#F5F5F5] rounded-[10px] px-3.5 py-3 text-center border-2 border-[#0B0B0B]">
                    <div class="text-[28px] font-extrabold leading-none text-[#0B0B0B]"><?= $total_items ?></div>
                    <div class="text-[10px] font-bold text-[#6B6B6B] uppercase tracking-[0.4px] mt-[3px]">Total</div>
                </div>
            </div>
            <div class="h-2.5 bg-[#E2E2E2] rounded-[5px] overflow-hidden">
                <div class="h-full bg-[#0B0B0B] transition-all duration-500 rounded-[5px]" style="width:<?= $completion_percentage ?>%;"></div>
            </div>
            <div class="text-xs font-bold text-[#0B0B0B] text-right mt-1"><?= number_format($completion_percentage, 1) ?>% Complete</div>
        </div>

        <!-- Area Tables -->
        <?php
        // Build display order: grouped entries first, then ungrouped
        $displayOrder = [];
        foreach ($groupedDisplay as $lbl => $gEntry) {
            $displayOrder[] = ['type' => 'group', 'label' => $lbl, 'areas' => $gEntry['areas']];
        }
        foreach ($ungroupedDisplay as $area => $items) {
            $displayOrder[] = ['type' => 'single', 'label' => $area, 'areas' => [$area]];
        }
        ?>

        <?php foreach ($displayOrder as $displayEntry):
            $entryAreas = $displayEntry['areas'];
            $entryLabel = $displayEntry['label'];
            $isGroup = $displayEntry['type'] === 'group';

            // Merge all items across areas in this group
            $areaItemsMerged = [];
            foreach ($entryAreas as $area) {
                foreach (($areaGroups[$area] ?? []) as $item) {
                    $areaItemsMerged[] = $item;
                }
            }

            // Use first area's timeline (groups share same timeline)
            $tl = $timelines[$entryAreas[0]] ?? [];

            // Build unit-grouped structure for all areas in group
            $unitGroups = [];
            $noUnitItems = [];

            foreach ($areaItemsMerged as $item) {
                $itemUnits = ($item['source'] === 'entry')
                    ? ($distByEntry[$item['id']] ?? [])
                    : ($distByFixed[$item['id']] ?? []);

                if (empty($itemUnits)) {
                    $noUnitItems[] = $item;
                } else {
                    foreach ($itemUnits as $dist) {
                        $uNum = $dist['room_unit_number'];
                        $uName = $dist['room_unit_name'] ?? '';
                        $uKey = $uNum . '|' . $uName;
                        if (!isset($unitGroups[$uKey])) {
                            $unitGroups[$uKey] = ['number' => $uNum, 'name' => $uName, 'items' => []];
                        }
                        $unitGroups[$uKey]['items'][] = ['item' => $item, 'dist' => $dist];
                    }
                }
            }
            ksort($unitGroups);

            // Area-level counts (across all areas in group)
            $aCounts = array_fill_keys($stageStatuses, 0);
            $aTotal = 0;
            foreach ($areaItemsMerged as $it) {
                $units = ($it['source'] === 'entry') ? ($distByEntry[$it['id']] ?? []) : ($distByFixed[$it['id']] ?? []);
                if (!empty($units)) {
                    foreach ($units as $u) {
                        $s = $u['unit_status'] ?: 'Pending';
                        if (isset($aCounts[$s])) $aCounts[$s]++;
                        $aTotal++;
                    }
                } else {
                    $s = $it['status'] ?: 'Pending';
                    if (isset($aCounts[$s])) $aCounts[$s]++;
                    $aTotal++;
                }
            }
            $aDone = $aCounts['Done'] ?? 0;
            $aPct = $aTotal > 0 ? round($aDone / $aTotal * 100) : 0;
            $aAreaAmt = array_sum(array_column($areaItemsMerged, 'computed_tot_amount'));
            $aAreaQty = array_sum(array_column($areaItemsMerged, 'quantity'));
        ?>
            <div class="<?= $C_CARD ?> !mb-[22px]">

                <div class="flex items-center justify-between gap-2.5 flex-wrap w-full bg-[#F5F5F5] px-5 py-[15px] border-b border-[#E2E2E2]">
                    <div class="text-sm font-bold text-[#0B0B0B] flex items-center gap-2">
                        <?php if ($isGroup): ?>
                            <i class="fas fa-object-group text-[#6B6B6B]"></i>
                            <?= htmlspecialchars($entryLabel) ?>
                            <span class="bg-amber-100 text-amber-800 px-2.5 py-0.5 rounded-full text-[11px] font-bold ml-1.5">
                                <i class="fas fa-layer-group"></i>
                                <?= count($entryAreas) ?> area<?= count($entryAreas) > 1 ? 's' : '' ?>:
                                <?= htmlspecialchars(implode(', ', $entryAreas)) ?>
                            </span>
                        <?php else: ?>
                            <i class="fas fa-map-marker-alt text-[#6B6B6B]"></i>
                            <?= htmlspecialchars($entryLabel) ?>
                        <?php endif; ?>
                    </div>
                    <div class="flex gap-1.5 flex-wrap">
                        <?php foreach ($stageStatuses as $st):
                            if (!($aCounts[$st] ?? 0)) continue;
                            $stw2 = $STATUS_TW[$st];
                            ?>
                            <span class="px-2.5 py-[3px] rounded-full text-[10px] font-bold <?= $stw2['badgeBg'] ?> <?= $stw2['badgeText'] ?>"><?= $st ?>: <?= $aCounts[$st] ?></span>
                        <?php endforeach; ?>
                        <span class="px-2.5 py-[3px] rounded-full text-[10px] font-bold bg-[#0B0B0B] text-white"><?= $aPct ?>% done</span>
                        <?php if (!empty($unitGroups)): ?>
                            <span class="<?= $C_BADGE ?>">
                                <i class="fas fa-door-open"></i> <?= count($unitGroups) ?> unit<?= count($unitGroups) > 1 ? 's' : '' ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($tl['ins_end'])): ?>
                            <span class="px-2.5 py-[3px] rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                <i class="fas fa-calendar-check"></i> Install by: <?= date('M d, Y', strtotime($tl['ins_end'])) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($tl)): ?>
                    <div class="px-5 pt-[11px] pb-2.5 bg-white border-b border-[#E2E2E2]"><?= renderTimelineBar($tl) ?></div>
                <?php else: ?>
                    <div class="text-[11px] text-[#6B6B6B] italic px-5 py-2 bg-white border-b border-[#E2E2E2]"><i class="fas fa-calendar-times"></i> No timeline set for this group yet.</div>
                <?php endif; ?>

                <div class="overflow-x-auto px-[18px] pb-[18px] pt-3.5">

                    <?php if (!empty($unitGroups)): ?>
                        <?php foreach ($unitGroups as $uKey => $uGroup):
                            $uNum = $uGroup['number'];
                            $uName = $uGroup['name'];
                            $uLabel = $uName ? 'Unit ' . $uNum . ' — ' . htmlspecialchars($uName) : 'Unit ' . $uNum;
                            $uItems = $uGroup['items'];

                            $uCounts = array_fill_keys($stageStatuses, 0);
                            foreach ($uItems as $ui) {
                                $s = $ui['dist']['unit_status'] ?: 'Pending';
                                if (isset($uCounts[$s])) $uCounts[$s]++;
                            }
                            $uTotal = count($uItems);
                            $uDone = $uCounts['Done'] ?? 0;
                            $uPct = $uTotal > 0 ? round($uDone / $uTotal * 100) : 0;
                            $anyOngoing = ($uCounts['Ongoing'] ?? 0) + ($uCounts['Incomplete'] ?? 0) + ($uCounts['Punchlist'] ?? 0);
                            $allDone = ($uDone === $uTotal && $uTotal > 0);

                            if ($allDone) {
                                $ust = $UNIT_STATE_TW['done'];
                            } elseif ($anyOngoing > 0) {
                                $ust = $UNIT_STATE_TW['ongoing'];
                            } else {
                                $ust = $UNIT_STATE_TW['pending'];
                            }

                            $slugUnit = 'ublk_' . preg_replace('/[^a-z0-9]/i', '_', $entryLabel) . '_' . $uNum;
                        ?>
                        <div class="rounded-[10px] mb-3.5 overflow-hidden shadow-sm border-2 <?= $ust['hdrBorder'] ?>">
                            <div class="<?= $ust['hdrBg'] ?> border-b-2 <?= $ust['hdrBorder'] ?> px-4 py-[11px] flex items-center justify-between gap-2.5 flex-wrap cursor-pointer select-none transition duration-150 hover:brightness-95"
                                onclick="toggleUnitBlock('<?= $slugUnit ?>')">
                                <div class="flex items-center gap-3">
                                    <span class="<?= $ust['badge'] ?> text-white w-[34px] h-[34px] rounded-full inline-flex items-center justify-center font-extrabold text-sm shrink-0 shadow-[0_2px_6px_rgba(0,0,0,.15)]">
                                        <?= $uNum ?>
                                    </span>
                                    <div>
                                        <div class="text-sm font-bold <?= $ust['hdrText'] ?> flex items-center gap-[7px]">
                                            <i class="fas fa-door-open text-xs opacity-80"></i>
                                            <?= $uLabel ?>
                                        </div>
                                        <div class="text-[11px] <?= $ust['hdrText'] ?> opacity-70 mt-0.5">
                                            <?= $uTotal ?> item<?= $uTotal > 1 ? 's' : '' ?> &nbsp;·&nbsp; <?= $uPct ?>% complete
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <?php foreach ($stageStatuses as $st):
                                        if (!($uCounts[$st] ?? 0)) continue;
                                        $stw2 = $STATUS_TW[$st];
                                        ?>
                                        <span class="<?= $stw2['badgeBg'] ?> <?= $stw2['badgeText'] ?> border <?= $stw2['badgeBorder'] ?> px-2.5 py-[3px] rounded-[10px] text-[10px] font-bold">
                                            <?= $st ?>: <?= $uCounts[$st] ?>
                                        </span>
                                    <?php endforeach; ?>
                                    <div class="w-[70px] h-1.5 bg-black/10 rounded-[3px] overflow-hidden">
                                        <div class="h-full <?= $ust['badge'] ?> rounded-[3px] transition-all duration-500" style="width:<?= $uPct ?>%;"></div>
                                    </div>
                                    <span class="text-[10px] font-bold <?= $ust['hdrText'] ?> min-w-[32px]"><?= $uPct ?>%</span>
                                    <i class="fas fa-chevron-down <?= $ust['hdrText'] ?> text-[13px] transition-transform duration-200 ml-0.5" id="chev-<?= $slugUnit ?>"></i>
                                </div>
                            </div>

                            <div id="<?= $slugUnit ?>" class="hidden">
                                <table class="w-full border-collapse text-xs min-w-[600px]">
                                    <thead>
                                        <tr>
                                            <th class="bg-[#6B6B6B] text-white px-[11px] py-2 text-[11px] font-bold border border-white/10 whitespace-nowrap text-left min-w-[180px]">Item Name</th>
                                            <?php if ($isGroup): ?>
                                                <th class="bg-[#6B6B6B] text-white px-[11px] py-2 text-[11px] font-bold border border-white/10 whitespace-nowrap text-center min-w-[80px]">Area</th>
                                            <?php endif; ?>
                                            <th class="bg-[#6B6B6B] text-white px-[11px] py-2 text-[11px] font-bold border border-white/10 whitespace-nowrap text-center min-w-[60px]">Type</th>
                                            <th class="bg-[#6B6B6B] text-white px-[11px] py-2 text-[11px] font-bold border border-white/10 whitespace-nowrap text-center min-w-[45px]">Qty</th>
                                            <th class="bg-[#6B6B6B] text-white px-[11px] py-2 text-[11px] font-bold border border-white/10 whitespace-nowrap text-left <?= $isInstallation ? 'min-w-[320px]' : 'min-w-[220px]' ?>">Status</th>
                                            <th class="bg-[#6B6B6B] text-white px-[11px] py-2 text-[11px] font-bold border border-white/10 whitespace-nowrap text-center min-w-[100px]">Amount</th>
                                            <th class="bg-[#6B6B6B] text-white px-[11px] py-2 text-[11px] font-bold border border-white/10 whitespace-nowrap text-left min-w-[120px]">Last Updated</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($uItems as $ui):
                                            $item = $ui['item'];
                                            $dist = $ui['dist'];
                                            $uStatus = $dist['unit_status'] ?: 'Pending';
                                            $rowStw = $STATUS_TW[$uStatus];
                                            ?>
                                            <tr class="item-row <?= $rowStw['rowBg'] ?>" data-rowbg="<?= $rowStw['rowBg'] ?>">
                                                <td class="px-[11px] py-[7px] border border-gray-200 align-middle">
                                                    <div class="font-bold text-gray-900 text-xs">
                                                        <?= htmlspecialchars($item['item_name'] ?? 'N/A') ?>
                                                        <span class="inline-block px-1.5 py-px rounded-[7px] text-[9px] font-bold uppercase ml-1 <?= $item['source'] === 'entry' ? 'bg-blue-100 text-blue-800' : 'bg-pink-100 text-pink-800' ?>">
                                                            <?= $item['source'] === 'entry' ? 'Entry' : 'Fixed' ?>
                                                        </span>
                                                    </div>
                                                    <button onclick="viewRemarks(<?= $item['id'] ?>, '<?= $item['source'] ?>', <?= $dist['distribution_id'] ?>, '<?= htmlspecialchars($item['item_name'] ?? '', ENT_QUOTES) ?>')"
                                                        class="mt-1 bg-blue-50 border border-blue-200 text-blue-700 rounded-md px-2 py-0.5 text-[10px] font-bold cursor-pointer hover:bg-blue-100 transition-colors">
                                                        <i class="fas fa-history"></i> Remarks
                                                    </button>
                                                    <?php if (!empty($item['color_label'])): ?>
                                                        <div class="text-[10px] text-gray-500 mt-0.5"><i class="fas fa-palette"></i> <?= htmlspecialchars($item['color_label']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if ($isGroup): ?>
                                                    <td class="px-[11px] py-[7px] border border-gray-200 align-middle text-center text-[11px] font-bold text-gray-500">
                                                        <?= htmlspecialchars($item['area']) ?>
                                                    </td>
                                                <?php endif; ?>
                                                <td class="px-[11px] py-[7px] border border-gray-200 align-middle text-center font-bold text-gray-700"><?= htmlspecialchars($item['unit'] ?? '—') ?></td>
                                                <td class="px-[11px] py-[7px] border border-gray-200 align-middle text-center font-bold text-[#0B0B0B]"><?= $dist['quantity'] ?></td>
                                                <td class="px-[11px] py-[7px] border border-gray-200 align-middle">
                                                    <div class="flex gap-1 flex-wrap max-[700px]:flex-col">
                                                        <?php foreach ($stageStatuses as $st):
                                                            $btnStw = $STATUS_TW[$st];
                                                            $active = ($uStatus === $st);
                                                            $itemPrevKey = $item['source'] . ':' . $item['id'];
                                                            $itemPrevStatus = $prevStageStatusMap[$itemPrevKey] ?? 'Done';
                                                            $itemDepLocked = isset($stageDependencies[$stage]) && ($itemPrevStatus !== 'Done');
                                                            $locked = $view_only || $itemDepLocked;
                                                            $btnClasses = "s-btn px-[9px] py-[3px] rounded-full text-[10px] font-bold border-2 transition-transform duration-150 whitespace-nowrap "
                                                                . $btnStw['btnBg'] . " " . $btnStw['btnText'] . " "
                                                                . ($active ? $btnStw['badgeBorder'] . ' scale-105' : 'border-transparent')
                                                                . ($locked ? ' cursor-not-allowed opacity-60' : ' cursor-pointer hover:scale-105');
                                                            ?>
                                                            <button class="<?= $btnClasses ?>"
                                                                data-status="<?= $st ?>"
                                                                <?= $locked ? 'disabled' : "onclick=\"updateUnitStatus({$dist['distribution_id']}, '{$st}', this, {$item['id']}, '{$item['source']}')\"" ?>>
                                                                <?= $st ?>
                                                            </button>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </td>
                                                <td class="px-[11px] py-[7px] border border-gray-200 align-middle text-right font-semibold text-[11px] text-gray-700">₱<?= number_format($item['computed_tot_amount'], 2) ?></td>
                                                <td class="upd-cell px-[11px] py-[7px] border border-gray-200 align-middle text-[10px] text-gray-400">
                                                    <?= (!empty($dist['unit_updated_at']) && $dist['unit_updated_at'] !== '0000-00-00 00:00:00')
                                                        ? '<i class="fas fa-clock"></i> ' . date('M d, Y g:i A', strtotime($dist['unit_updated_at']))
                                                        : '—' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($noUnitItems)): ?>
                        <?php if (!empty($unitGroups)): ?>
                            <div class="text-[11px] font-bold text-gray-500 uppercase tracking-[0.4px] my-4 mx-0.5 flex items-center gap-1.5">
                                <i class="fas fa-box"></i> Individual Items
                                <span class="text-[10px] font-normal text-gray-400">(no unit distribution)</span>
                            </div>
                        <?php endif; ?>
                        <table class="w-full border-collapse text-xs min-w-[600px]">
                            <thead>
                                <tr>
                                    <th class="bg-[#0B0B0B] text-white px-[11px] py-2 text-[11px] font-bold border border-white/10 whitespace-nowrap text-left min-w-[180px]">Item Name</th>
                                    <?php if ($isGroup): ?>
                                        <th class="bg-[#0B0B0B] text-white px-[11px] py-2 text-[11px] font-bold border border-white/10 whitespace-nowrap text-center min-w-[80px]">Area</th>
                                    <?php endif; ?>
                                    <th class="bg-[#0B0B0B] text-white px-[11px] py-2 text-[11px] font-bold border border-white/10 whitespace-nowrap text-center min-w-[60px]">Type</th>
                                    <th class="bg-[#0B0B0B] text-white px-[11px] py-2 text-[11px] font-bold border border-white/10 whitespace-nowrap text-center min-w-[45px]">Qty</th>
                                    <th class="bg-[#0B0B0B] text-white px-[11px] py-2 text-[11px] font-bold border border-white/10 whitespace-nowrap text-left <?= $isInstallation ? 'min-w-[320px]' : 'min-w-[220px]' ?>">Status</th>
                                    <th class="bg-[#0B0B0B] text-white px-[11px] py-2 text-[11px] font-bold border border-white/10 whitespace-nowrap text-center min-w-[100px]">Amount</th>
                                    <th class="bg-[#0B0B0B] text-white px-[11px] py-2 text-[11px] font-bold border border-white/10 whitespace-nowrap text-left min-w-[120px]">Last Updated</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($noUnitItems as $item):
                                    $status = $item['status'] ?: 'Pending';
                                    $rowStw = $STATUS_TW[$status];
                                    ?>
                                    <tr class="item-row <?= $rowStw['rowBg'] ?>" data-rowbg="<?= $rowStw['rowBg'] ?>">
                                        <td class="px-[11px] py-[7px] border border-gray-200 align-middle">
                                            <div class="font-bold text-gray-900 text-xs">
                                                <?= htmlspecialchars($item['item_name'] ?? 'N/A') ?>
                                                <span class="inline-block px-1.5 py-px rounded-[7px] text-[9px] font-bold uppercase ml-1 <?= $item['source'] === 'entry' ? 'bg-blue-100 text-blue-800' : 'bg-pink-100 text-pink-800' ?>">
                                                    <?= $item['source'] === 'entry' ? 'Entry' : 'Fixed' ?>
                                                </span>
                                            </div>
                                            <button onclick="viewRemarks(<?= $item['id'] ?>, '<?= $item['source'] ?>', null, '<?= htmlspecialchars($item['item_name'] ?? '', ENT_QUOTES) ?>')"
                                                class="mt-1 bg-blue-50 border border-blue-200 text-blue-700 rounded-md px-2 py-0.5 text-[10px] font-bold cursor-pointer hover:bg-blue-100 transition-colors">
                                                <i class="fas fa-history"></i> Remarks
                                            </button>
                                            <?php if (!empty($item['color_label'])): ?>
                                                <div class="text-[10px] text-gray-500 mt-0.5"><i class="fas fa-palette"></i> <?= htmlspecialchars($item['color_label']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($isGroup): ?>
                                            <td class="px-[11px] py-[7px] border border-gray-200 align-middle text-center text-[11px] font-bold text-gray-500">
                                                <?= htmlspecialchars($item['area']) ?>
                                            </td>
                                        <?php endif; ?>
                                        <td class="px-[11px] py-[7px] border border-gray-200 align-middle text-center font-bold text-gray-700"><?= htmlspecialchars($item['unit'] ?? '—') ?></td>
                                        <td class="px-[11px] py-[7px] border border-gray-200 align-middle text-center font-bold text-[#0B0B0B]"><?= $item['quantity'] ?></td>
                                        <td class="px-[11px] py-[7px] border border-gray-200 align-middle">
                                            <div class="flex gap-1 flex-wrap max-[700px]:flex-col">
                                                <?php foreach ($stageStatuses as $st):
                                                    $btnStw = $STATUS_TW[$st];
                                                    $active = ($status === $st);
                                                    $itemPrevKey = $item['source'] . ':' . $item['id'];
                                                    $itemPrevStatus = $prevStageStatusMap[$itemPrevKey] ?? 'Done';
                                                    $itemDepLocked = isset($stageDependencies[$stage]) && ($itemPrevStatus !== 'Done');
                                                    $locked = $view_only || $itemDepLocked;
                                                    $btnClasses = "s-btn px-[9px] py-[3px] rounded-full text-[10px] font-bold border-2 transition-transform duration-150 whitespace-nowrap "
                                                        . $btnStw['btnBg'] . " " . $btnStw['btnText'] . " "
                                                        . ($active ? $btnStw['badgeBorder'] . ' scale-105' : 'border-transparent')
                                                        . ($locked ? ' cursor-not-allowed opacity-60' : ' cursor-pointer hover:scale-105');
                                                    ?>
                                                    <button class="<?= $btnClasses ?>"
                                                        data-status="<?= $st ?>"
                                                        <?= $locked ? 'disabled' : "onclick=\"updateItemStatus({$item['id']}, '{$st}', this, '{$item['source']}')\"" ?>>
                                                        <?= $st ?>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td class="px-[11px] py-[7px] border border-gray-200 align-middle text-right font-semibold text-gray-700">₱<?= number_format($item['computed_tot_amount'], 2) ?></td>
                                        <td class="upd-cell px-[11px] py-[7px] border border-gray-200 align-middle text-[10px] text-gray-400">
                                            <?= (!empty($item['updated_at']) && $item['updated_at'] !== '0000-00-00 00:00:00')
                                                ? '<i class="fas fa-clock"></i> ' . date('M d, Y g:i A', strtotime($item['updated_at']))
                                                : '—' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <!-- Area Summary Footer -->
                    <table class="w-full border-collapse text-xs mt-2.5">
                        <tfoot>
                            <tr>
                                <td colspan="<?= $isGroup ? '3' : '2' ?>" class="px-[11px] py-[7px] border border-gray-200 align-middle bg-[#F5F5F5] font-bold text-[#0B0B0B] text-right text-[11px]">
                                    <?= $isGroup ? htmlspecialchars($entryLabel) . ' Totals' : 'Area Totals' ?>
                                </td>
                                <td class="px-[11px] py-[7px] border border-gray-200 align-middle bg-[#F5F5F5] font-bold text-[#0B0B0B] text-center"><?= $aAreaQty ?></td>
                                <td class="px-[11px] py-[7px] border border-gray-200 align-middle bg-[#F5F5F5] font-bold text-[#0B0B0B]">
                                    <div class="flex gap-[5px] flex-wrap">
                                        <?php foreach ($stageStatuses as $st):
                                            if (!($aCounts[$st] ?? 0)) continue;
                                            $stw2 = $STATUS_TW[$st];
                                            ?>
                                            <span class="<?= $stw2['badgeBg'] ?> <?= $stw2['badgeText'] ?> px-[7px] py-0.5 rounded-lg font-bold text-[10px]">
                                                <?= $st ?>: <?= $aCounts[$st] ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td class="px-[11px] py-[7px] border border-gray-200 align-middle bg-[#F5F5F5] font-bold text-[#0B0B0B] text-right">₱<?= number_format($aAreaAmt, 2) ?></td>
                                <td class="px-[11px] py-[7px] border border-gray-200 align-middle bg-[#F5F5F5]"></td>
                            </tr>
                        </tfoot>
                    </table>

                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($areaGroups)): ?>
            <div class="text-center py-[50px] text-gray-400 bg-white rounded-xl">
                <i class="fas fa-inbox text-4xl block mb-3"></i>
                No items found for this client yet.
            </div>
        <?php endif; ?>

    </div>

    <!-- Remarks Modal -->
    <div id="remarkModal" class="hidden fixed inset-0 z-[9998] bg-black/45 items-center justify-center p-5">
        <div class="anim-pop-in bg-white rounded-2xl p-6 max-w-[480px] w-[90%] shadow-2xl">
            <div class="flex items-center justify-between mb-3.5">
                <h3 id="remarkModalTitle" class="text-[15px] font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-comment-alt text-blue-500"></i> Add Remark
                </h3>
                <button onclick="closeRemarkModal()" class="bg-transparent border-0 text-lg text-gray-500 cursor-pointer leading-none">&times;</button>
            </div>
            <div id="remarkStatusBadge" class="mb-3"></div>
            <textarea id="remarkText" rows="4" placeholder="Enter your remark here… (optional)"
                class="w-full px-[13px] py-2.5 border-[1.5px] border-gray-200 rounded-lg text-[13px] font-sans resize-y mb-3.5 outline-none focus:border-[#0B0B0B]"></textarea>
            <div class="flex gap-2.5 justify-end">
                <button onclick="closeRemarkModal()" class="bg-slate-100 text-slate-600 px-[18px] py-2.5 border-0 rounded-lg cursor-pointer font-semibold text-[13px] hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
                <button onclick="skipRemark()" class="bg-slate-200 text-slate-700 px-[18px] py-2.5 border-0 rounded-lg cursor-pointer font-semibold text-[13px] hover:bg-slate-300 transition-colors">
                    <i class="fas fa-forward"></i> Skip
                </button>
                <button onclick="submitRemark()" class="bg-gradient-to-br from-[#3b1f0f] to-[#8a5a44] text-white px-[18px] py-2.5 border-0 rounded-lg cursor-pointer font-bold text-[13px] hover:opacity-90 transition-opacity">
                    <i class="fas fa-paper-plane"></i> Save & Update
                </button>
            </div>
        </div>
    </div>

    <!-- Remarks History Modal -->
    <div id="historyModal" class="hidden fixed inset-0 z-[9998] bg-black/45 items-center justify-center p-5">
        <div class="anim-pop-in bg-white rounded-2xl p-6 max-w-[500px] w-[90%] shadow-2xl max-h-[80vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-[15px] font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-history text-[#6B6B6B]"></i> Remark History
                </h3>
                <button onclick="closeHistoryModal()" class="bg-transparent border-0 text-lg text-gray-500 cursor-pointer">&times;</button>
            </div>
            <div id="historyContent">Loading...</div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="hidden fixed top-5 right-5 bg-white px-5 py-3 rounded-[10px] shadow-[0_4px_20px_rgba(0,0,0,.14)] items-center gap-[11px] z-[9999] text-[13px] font-semibold anim-toast-in">
        <i id="toastIcon" class="fas fa-check-circle text-lg text-emerald-500"></i>
        <span id="toastMsg">Status updated!</span>
    </div>

    <script>
        const STAGE = '<?= addslashes($stage) ?>';
        const CLIENT_ID = <?= $client_id ?>;

        // ── Pending action — stored while modal is open ──
        let _pendingAction = null;

        // ── Status → Tailwind class maps (mirrors PHP $STATUS_TW) ──
        const STATUS_BADGE = {
            Pending: { badge: 'bg-amber-100 text-amber-800 border-amber-500', border: 'border-amber-500' },
            Ongoing: { badge: 'bg-blue-100 text-blue-800 border-blue-500', border: 'border-blue-500' },
            Done: { badge: 'bg-emerald-100 text-emerald-800 border-emerald-500', border: 'border-emerald-500' },
            Incomplete: { badge: 'bg-red-100 text-red-800 border-red-500', border: 'border-red-500' },
            Punchlist: { badge: 'bg-pink-100 text-pink-800 border-pink-500', border: 'border-pink-500' },
        };
        const STATUS_ROW_BG = {
            Pending: 'bg-amber-50', Ongoing: 'bg-blue-50', Done: 'bg-emerald-50',
            Incomplete: 'bg-red-50', Punchlist: 'bg-pink-50',
        };
        const ALL_ROW_BG_CLASSES = Object.values(STATUS_ROW_BG);

        function toggleUnitBlock(slugId) {
            const body = document.getElementById(slugId);
            const chev = document.getElementById('chev-' + slugId);
            if (!body) return;
            const isOpen = !body.classList.contains('hidden');
            body.classList.toggle('hidden', isOpen);
            if (chev) chev.classList.toggle('rotate-180', !isOpen);
        }

        // Called when a status button is clicked — opens modal first
        function updateItemStatus(itemId, newStatus, btn, source) {
            _pendingAction = { type: 'item', itemId, newStatus, btn, source, distId: null };
            openRemarkModal(newStatus);
        }

        function updateUnitStatus(distId, newStatus, btn, itemId, source) {
            _pendingAction = { type: 'unit', distId, newStatus, btn, itemId, source };
            openRemarkModal(newStatus);
        }

        // ── Modal controls ──
        function openOverlay(el) { el.classList.remove('hidden'); el.classList.add('flex'); }
        function closeOverlay(el) { el.classList.add('hidden'); el.classList.remove('flex'); }

        function openRemarkModal(status) {
            const sb = STATUS_BADGE[status] || { badge: 'bg-slate-100 text-slate-600 border-slate-300', border: 'border-slate-300' };
            document.getElementById('remarkModalTitle').innerHTML =
                `<i class="fas fa-comment-alt ${sb.border.replace('border-', 'text-')}"></i> Add Remark for Status Change`;
            document.getElementById('remarkStatusBadge').innerHTML =
                `<span class="${sb.badge} border-[1.5px] px-3.5 py-1 rounded-xl text-xs font-bold">→ ${status}</span>`;
            document.getElementById('remarkText').value = '';
            openOverlay(document.getElementById('remarkModal'));
            setTimeout(() => document.getElementById('remarkText').focus(), 100);
        }

        function closeRemarkModal() {
            closeOverlay(document.getElementById('remarkModal'));
            _pendingAction = null;
        }

        function skipRemark() {
            executeStatusUpdate('');
        }

        function submitRemark() {
            const remark = document.getElementById('remarkText').value.trim();
            executeStatusUpdate(remark);
        }

        async function executeStatusUpdate(remark) {
            closeOverlay(document.getElementById('remarkModal'));
            if (!_pendingAction) return;

            const { type, itemId, distId, newStatus, btn, source } = _pendingAction;
            _pendingAction = null;

            // Optimistic UI update
            const row = btn.closest('tr.item-row');
            const allBtns = row.querySelectorAll('.s-btn');
            allBtns.forEach(b => {
                const bStw = STATUS_BADGE[b.dataset.status];
                b.classList.remove('scale-105', 'border-transparent', ...(bStw ? [bStw.border] : []));
                b.classList.add('border-transparent');
            });
            const activeStw = STATUS_BADGE[newStatus];
            btn.classList.remove('border-transparent');
            if (activeStw) btn.classList.add(activeStw.border);
            btn.classList.add('scale-105');
            applyRowBg(row, newStatus);

            const updCell = row.querySelector('.upd-cell');

            if (type === 'unit') {
                if (updCell) updCell.innerHTML = '<i class="fas fa-clock"></i> Just now';
                await doUpdate('<?= BASE_URL ?>update-unit-status', {
                    distribution_id: distId,
                    stage: STAGE,
                    status: newStatus,
                    item_id: itemId,
                    source,
                    remark
                });
            } else {
                await doUpdate('<?= BASE_URL ?>update-item-status', {
                    entry_id: itemId,
                    source,
                    stage: STAGE,
                    status: newStatus,
                    remark
                });
            }

            // Refresh remark badge on the row
            if (remark) {
                let badge = row.querySelector('.remark-badge');
                if (!badge) {
                    badge = document.createElement('button');
                    badge.className = 'remark-badge bg-blue-50 border border-blue-200 text-blue-700 rounded-md px-[7px] py-0.5 text-[10px] font-bold cursor-pointer ml-1.5';
                    badge.innerHTML = '<i class="fas fa-comment-dots"></i> <span class="remark-count">1</span>';
                    const nameDiv = row.querySelector('.font-bold.text-gray-900');
                    if (nameDiv) nameDiv.appendChild(badge);
                } else {
                    const countEl = badge.querySelector('.remark-count');
                    if (countEl) countEl.textContent = parseInt(countEl.textContent || 0) + 1;
                }
            }
        }

        async function doUpdate(endpoint, payload) {
            try {
                const resp = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await resp.json();
                if (result.success) {
                    showToast('Status updated to ' + payload.status, 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    showToast('Error: ' + (result.error || 'Unknown'), 'error');
                    location.reload();
                }
            } catch (e) {
                showToast('Network error', 'error');
                location.reload();
            }
        }

        // ── Remark History ──
        async function viewRemarks(itemId, source, distId = null, itemName = '') {
            document.getElementById('historyContent').innerHTML =
                '<div class="text-center py-5 text-gray-400"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            openOverlay(document.getElementById('historyModal'));

            try {
                const params = new URLSearchParams({
                    client_id: CLIENT_ID, stage: STAGE, item_id: itemId,
                    source, ...(distId ? { distribution_id: distId } : {})
                });
                const resp = await fetch('<?= BASE_URL ?>get-item-remarks?' + params);
                const data = await resp.json();

                if (!data.success || !data.remarks.length) {
                    document.getElementById('historyContent').innerHTML =
                        '<div class="text-center py-6 text-gray-400"><i class="fas fa-comment-slash text-[28px] block mb-2"></i>No remarks yet.</div>';
                    return;
                }

                const badgeMap = {
                    Pending: 'bg-amber-100 text-amber-800 border-amber-500',
                    Ongoing: 'bg-blue-100 text-blue-800 border-blue-500',
                    Done: 'bg-emerald-100 text-emerald-800 border-emerald-500',
                    Incomplete: 'bg-red-100 text-red-800 border-red-500',
                    Punchlist: 'bg-pink-100 text-pink-800 border-pink-500',
                };

                let html = `<div class="text-xs text-gray-500 mb-3">${data.remarks.length} remark(s) for <strong>${itemName}</strong></div>`;
                data.remarks.forEach(r => {
                    const badgeCls = badgeMap[r.status] || 'bg-slate-100 text-slate-600 border-slate-300';
                    html += `
            <div class="border-[1.5px] border-slate-200 rounded-[10px] px-3.5 py-3 mb-2.5 bg-slate-50">
                <div class="flex items-center justify-between flex-wrap gap-1.5 mb-[7px]">
                    <span class="${badgeCls} border px-2.5 py-0.5 rounded-[10px] text-[10px] font-bold">${r.status}</span>
                    <span class="text-[11px] text-gray-400"><i class="fas fa-user"></i> ${r.created_by_name} &nbsp;·&nbsp; <i class="fas fa-clock"></i> ${r.created_at}</span>
                </div>
                <div class="text-[13px] ${r.remark ? 'text-gray-800' : 'text-gray-400 italic'}">
                    ${r.remark ? r.remark.replace(/\n/g, '<br>') : 'No remark entered.'}
                </div>
            </div>`;
                });
                document.getElementById('historyContent').innerHTML = html;
            } catch (e) {
                document.getElementById('historyContent').innerHTML = '<div class="text-red-500 p-4">Failed to load remarks.</div>';
            }
        }

        function closeHistoryModal() {
            closeOverlay(document.getElementById('historyModal'));
        }

        // Close modals on backdrop click
        document.addEventListener('click', e => {
            if (e.target === document.getElementById('remarkModal')) closeRemarkModal();
            if (e.target === document.getElementById('historyModal')) closeHistoryModal();
        });

        function applyRowBg(row, status) {
            if (!row) return;
            row.classList.remove(...ALL_ROW_BG_CLASSES);
            row.classList.add(STATUS_ROW_BG[status] || 'bg-white');
        }

        function showToast(msg, type) {
            const t = document.getElementById('toast');
            const icon = document.getElementById('toastIcon');
            document.getElementById('toastMsg').textContent = msg;
            t.classList.remove('hidden', 'border-l-4', 'border-emerald-500', 'border-red-500');
            t.classList.add('flex', type === 'success' ? 'border-l-4' : 'border-l-4', type === 'success' ? 'border-emerald-500' : 'border-red-500');
            icon.className = 'fas text-lg ' + (type === 'success' ? 'fa-check-circle text-emerald-500' : 'fa-exclamation-circle text-red-500');
            setTimeout(() => { t.classList.remove('flex'); t.classList.add('hidden'); }, 3000);
        }
    </script>
</body>

</html>