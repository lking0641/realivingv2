<?php
// coordinator_timeline.php
include $includes['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];

$meStmt = $conn->prepare("SELECT full_name, role FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

if ($me['role'] !== 'project_coordinator') {
    die("Access denied. Only Project Coordinators can access this page.");
}

// ── Handle Save Stage Deadline ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_stage_deadline') {
    $client_id = intval($_POST['client_id']);
    $stage_name = trim($_POST['stage_name'] ?? '');
    $start_date = trim($_POST['start_date'] ?? '') ?: null;
    $end_date = trim($_POST['end_date'] ?? '') ?: null;

    $duration = 0;
    if ($start_date && $end_date) {
        $d1 = new DateTime($start_date);
        $d2 = new DateTime($end_date);
        $duration = (int) $d1->diff($d2)->days + 1;
    }

    $stmt = $conn->prepare("INSERT INTO stage_deadlines (client_id, stage_name, start_date, end_date, duration) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE start_date=?, end_date=?, duration=?, updated_at=NOW()");
    $stmt->bind_param("isssissi", $client_id, $stage_name, $start_date, $end_date, $duration, $start_date, $end_date, $duration);
    $stmt->execute();

    header("Location: " . BASE_URL . "coordinator-timeline?client_id={$client_id}&success=" . urlencode("Deadline saved for '{$stage_name}'!"));
    exit();
}

// ── Handle Save Overall Timeline ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_overall_timeline') {
    $client_id = intval($_POST['client_id']);
    $overall_start = trim($_POST['overall_start_date'] ?? '');
    $overall_end = trim($_POST['overall_end_date'] ?? '');

    $duration = 0;
    if ($overall_start && $overall_end) {
        $d1 = new DateTime($overall_start);
        $d2 = new DateTime($overall_end);
        $duration = (int) $d1->diff($d2)->days + 1;
    }

    $stmt = $conn->prepare("UPDATE user_info SET overall_start_date=?, overall_end_date=?, overall_duration=? WHERE id=?");
    $stmt->bind_param("ssii", $overall_start, $overall_end, $duration, $client_id);
    $stmt->execute();

    header("Location: " . BASE_URL . "coordinator-timeline?client_id={$client_id}&success=" . urlencode("Overall timeline saved!"));
    exit();
}

// ── Handle Save Group Assignments ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_group_assignments') {
    $client_id = intval($_POST['client_id']);
    $assignments = $_POST['group_label'] ?? []; // area => label

    foreach ($assignments as $area => $label) {
        $area = trim($area);
        $label = trim($label) ?: null;

        // Check what the PREVIOUS group label was for this area
        $prevLblStmt = $conn->prepare("SELECT group_label FROM timeline_area_groups WHERE client_id=? AND area=?");
        $prevLblStmt->bind_param("is", $client_id, $area);
        $prevLblStmt->execute();
        $prevRow = $prevLblStmt->get_result()->fetch_assoc();
        $prevLabel = $prevRow['group_label'] ?? null;

        // Save the new group assignment
        $glStmt = $conn->prepare("INSERT INTO timeline_area_groups (client_id, area, group_label) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE group_label=?, updated_at=NOW()");
        $glStmt->bind_param("isss", $client_id, $area, $label, $label);
        $glStmt->execute();

        // If area is being GROUPED (previously had no label, now has one)
        // OR moved to a DIFFERENT group — clear its old timeline data
        if ($label && $label !== $prevLabel) {
            $delTlStmt = $conn->prepare("DELETE FROM timeline_area WHERE client_id=? AND area=?");
            $delTlStmt->bind_param("is", $client_id, $area);
            $delTlStmt->execute();
        }
    }

    header("Location: " . BASE_URL . "coordinator-timeline?client_id={$client_id}&success=" . urlencode("Group assignments saved!"));
    exit();
}

// ── Handle Save Group Timeline (dates apply to ALL areas in the group) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_group_timeline') {
    $client_id = intval($_POST['client_id']);
    $group_label = trim($_POST['group_label'] ?? '');
    $fab_start = trim($_POST['fab_start'] ?? '') ?: null;
    $fab_end = trim($_POST['fab_end'] ?? '') ?: null;
    $del_start = trim($_POST['del_start'] ?? '') ?: null;
    $del_end = trim($_POST['del_end'] ?? '') ?: null;
    $ins_start = trim($_POST['ins_start'] ?? '') ?: null;
    $ins_end = trim($_POST['ins_end'] ?? '') ?: null;

    function daysDiff($s, $e)
    {
        if (!$s || !$e)
            return 0;
        return (int) (new DateTime($s))->diff(new DateTime($e))->days + 1;
    }
    $fab_dur = daysDiff($fab_start, $fab_end);
    $del_dur = daysDiff($del_start, $del_end);
    $ins_dur = daysDiff($ins_start, $ins_end);

    // Find all areas in this group for this client
    $areasInGroupStmt = $conn->prepare("SELECT area FROM timeline_area_groups WHERE client_id=? AND group_label=?");
    $areasInGroupStmt->bind_param("is", $client_id, $group_label);
    $areasInGroupStmt->execute();
    $areasInGroup = array_column($areasInGroupStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'area');

    // Also handle ungrouped single area (group_label passed as the area itself)
    if (empty($areasInGroup)) {
        $areasInGroup = [$group_label];
    }

    foreach ($areasInGroup as $area) {
        $chkStmt = $conn->prepare("SELECT id FROM timeline_area WHERE client_id=? AND area=?");
        $chkStmt->bind_param("is", $client_id, $area);
        $chkStmt->execute();
        $existing = $chkStmt->get_result()->fetch_assoc();

        if ($existing) {
            // Only update if at least one date is being set (don't wipe with nulls)
            if ($fab_start || $del_start || $ins_start) {
                $upStmt = $conn->prepare("UPDATE timeline_area SET fab_start=?,fab_end=?,fab_duration=?,del_start=?,del_end=?,del_duration=?,ins_start=?,ins_end=?,ins_duration=?,updated_at=NOW() WHERE client_id=? AND area=?");
                $upStmt->bind_param("ssississsis", $fab_start, $fab_end, $fab_dur, $del_start, $del_end, $del_dur, $ins_start, $ins_end, $ins_dur, $client_id, $area);
                $upStmt->execute();
            }
        } else {
            if ($fab_start || $del_start || $ins_start) {
                $inStmt = $conn->prepare("INSERT INTO timeline_area (client_id,area,fab_start,fab_end,fab_duration,del_start,del_end,del_duration,ins_start,ins_end,ins_duration) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $inStmt->bind_param("isssississs", $client_id, $area, $fab_start, $fab_end, $fab_dur, $del_start, $del_end, $del_dur, $ins_start, $ins_end, $ins_dur);
                $inStmt->execute();
            }
        }
    }

    header("Location: " . BASE_URL . "coordinator-timeline?client_id={$client_id}&success=" . urlencode("Timeline saved for group '{$group_label}'!"));
    exit();
}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

$clientsStmt = $conn->prepare("SELECT ui.id,ui.clientname,ui.nameproject,ui.reference_number,ui.business_type,ui.status,ui.overall_start_date,ui.overall_end_date,ui.overall_duration FROM user_info ui WHERE ui.project_coordinator_id=? ORDER BY ui.created_at DESC");
$clientsStmt->bind_param("i", $admin_id);
$clientsStmt->execute();
$clients = $clientsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$selectedClient = null;
$areas = [];
$areaTimelines = [];

if ($client_id) {
    $verifyStmt = $conn->prepare("SELECT * FROM user_info WHERE id=? AND project_coordinator_id=?");
    $verifyStmt->bind_param("ii", $client_id, $admin_id);
    $verifyStmt->execute();
    $selectedClient = $verifyStmt->get_result()->fetch_assoc();
    if (!$selectedClient)
        die("Access denied: Not assigned to this client.");

    $areasStmt = $conn->prepare("SELECT DISTINCT area FROM quotation_entries WHERE client_id=? UNION SELECT DISTINCT area FROM quotation_fixed_sizes WHERE client_id=? ORDER BY area");
    $areasStmt->bind_param("ii", $client_id, $client_id);
    $areasStmt->execute();
    $areas = array_column($areasStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'area');

    // Load group labels
    $groupLabels = [];
    $glLoadStmt = $conn->prepare("SELECT area, group_label FROM timeline_area_groups WHERE client_id=?");
    $glLoadStmt->bind_param("i", $client_id);
    $glLoadStmt->execute();
    foreach ($glLoadStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $gl) {
        $groupLabels[$gl['area']] = $gl['group_label'];
    }

    $tlStmt = $conn->prepare("SELECT * FROM timeline_area WHERE client_id=?");
    $tlStmt->bind_param("i", $client_id);
    $tlStmt->execute();
    foreach ($tlStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $tl) {
        $areaTimelines[$tl['area']] = $tl;
    }

    // Load stage deadlines
    $stageDeadlines = [];
    $sdStmt = $conn->prepare("SELECT * FROM stage_deadlines WHERE client_id=?");
    $sdStmt->bind_param("i", $client_id);
    $sdStmt->execute();
    foreach ($sdStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $sd) {
        $stageDeadlines[$sd['stage_name']] = $sd;
    }

    // Load group labels
    $groupLabels = [];
    $glLoadStmt = $conn->prepare("SELECT area, group_label FROM timeline_area_groups WHERE client_id=?");
    $glLoadStmt->bind_param("i", $client_id);
    $glLoadStmt->execute();
    foreach ($glLoadStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $gl) {
        $groupLabels[$gl['area']] = $gl['group_label'];
    }

    // Build groups: group_label => [areas], and ungrouped areas
    $groups = [];       // label => [area1, area2, ...]
    $ungrouped = [];    // areas with no label
    foreach ($areas as $area) {
        $lbl = $groupLabels[$area] ?? null;
        if ($lbl) {
            $groups[$lbl][] = $area;
        } else {
            $ungrouped[] = $area;
        }
    }
}

function bizLabel($bt)
{
    return $bt === 'Non-Project' ? 'Individual' : ($bt ?? '');
}

// ── Tailwind class helpers (kept here so markup below stays readable) ──
$C_CARD          = "bg-white border border-[#E2E2E2] rounded-[10px] p-6 md:p-[26px] mb-5";
$C_CARD_TITLE    = "font-sans text-[15px] font-bold text-[#0B0B0B] mb-5 pb-3.5 border-b border-[#E2E2E2] flex items-center gap-2.5";
$C_BTN_SAVE      = "bg-[#0B0B0B] text-white px-6 py-[11px] border-0 rounded-[9px] cursor-pointer text-[13px] font-bold font-sans inline-flex items-center gap-2 mt-5 transition-opacity hover:opacity-85 tracking-[0.3px]";
$C_BTN_BACK      = "bg-[#0B0B0B] text-white px-[18px] py-[9px] rounded-[9px] font-semibold text-[13px] inline-flex items-center gap-[7px] no-underline mb-[18px] hover:opacity-85 transition-opacity";
$C_BADGE         = "px-3 py-[3px] rounded-full text-[11px] font-bold bg-[#F5F5F5] border border-[#E2E2E2] text-[#0B0B0B]";
$C_VIEW_TAB      = "px-[22px] py-[9px] rounded-[9px] border text-[13px] font-bold cursor-pointer transition-all font-sans";
$C_VIEW_TAB_OFF  = "bg-white text-[#6B6B6B] border-[#E2E2E2]";
$C_VIEW_TAB_ON   = "bg-[#0B0B0B] text-white border-[#0B0B0B]";
$C_RANGE_DISPLAY = "border border-[#E2E2E2] rounded-[10px] px-4 py-[14px] cursor-pointer transition-all relative bg-[#F5F5F5] select-none min-h-[80px] hover:border-[#0B0B0B] hover:bg-white";
$C_PILL          = "px-[11px] py-[3px] rounded-full text-[11px] font-bold";
$C_PILL_DONE     = "bg-emerald-100 text-emerald-800";
$C_PILL_PARTIAL  = "bg-amber-100 text-amber-800";
$C_PILL_NONE     = "bg-gray-100 text-gray-400";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Timeline — Coordinator</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!--
      Pure Tailwind build (npm run dev). No custom <style> block —
      all styling below is Tailwind utility classes. Dynamic states
      (calendar days, "has value" swaps, group modal rows, etc.) are
      applied by coordinator_timeline.js using literal Tailwind class
      strings so the JIT compiler can pick them up from this bundle.
    -->
    <style>
        body { font-family: 'Inter', sans-serif; }
        @keyframes popIn { from { transform: scale(.9); opacity: 0 } to { transform: scale(1); opacity: 1 } }
        .anim-pop-in { animation: popIn .2s ease; }
    </style>
</head>

<body class="bg-[#F5F5F5] text-[#0B0B0B] font-sans">
    <div class="max-w-[1140px] mx-auto px-5 py-7">

        <div class="bg-white border border-[#E2E2E2] px-[30px] py-6 rounded-[10px] text-[#0B0B0B] mb-6 relative">
            <div class="text-[11px] font-semibold tracking-[1.5px] uppercase text-[#6B6B6B] mb-1.5">Tracker Management</div>
            <h1 class="font-sans text-2xl font-bold tracking-[-0.01em] flex items-center gap-2.5">
                <i class="fas fa-calendar-alt"></i> Project Timeline Manager
            </h1>
            <p class="text-[13px] opacity-85 mt-1.5 text-[#6B6B6B]">Welcome, <?= htmlspecialchars($me['full_name']) ?> — Project Coordinator</p>
        </div>

        <?php if ($success): ?>
            <div class="px-4 py-3 rounded-[10px] mb-4 text-[13px] font-semibold flex items-center gap-2 bg-emerald-100 text-emerald-800 border-l-4 border-emerald-500">
                <i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="px-4 py-3 rounded-[10px] mb-4 text-[13px] font-semibold flex items-center gap-2 bg-red-100 text-red-800 border-l-4 border-red-500">
                <i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!$client_id): ?>
            <!-- ══ CLIENT LIST ══ -->
            <div class="<?= $C_CARD ?>">
                <div class="<?= $C_CARD_TITLE ?>"><i class="fas fa-users"></i> Assigned Projects</div>
                <?php
                $notSetCount = count(array_filter($clients, fn($c) => empty($c['overall_start_date'])));
                $alreadySetCount = count(array_filter($clients, fn($c) => !empty($c['overall_start_date'])));
                ?>
                <div class="flex gap-2.5 mb-4 flex-wrap">
                    <button type="button" id="btn-filter-notset" onclick="setFilter('notset')"
                        class="px-5 py-[9px] rounded-[10px] border border-[#0B0B0B] bg-[#0B0B0B] text-white font-sans text-[13px] font-bold cursor-pointer flex items-center gap-2 transition-all">
                        <i class="fas fa-calendar-times"></i> Not Set
                        <span class="bg-white/25 px-[9px] py-px rounded-full text-[11px]"><?= $notSetCount ?></span>
                    </button>
                    <button type="button" id="btn-filter-set" onclick="setFilter('set')"
                        class="px-5 py-[9px] rounded-[10px] border border-[#E2E2E2] bg-white text-[#6B6B6B] font-sans text-[13px] font-bold cursor-pointer flex items-center gap-2 transition-all">
                        <i class="fas fa-calendar-check"></i> Already Set
                        <span class="bg-[#E2E2E2] text-[#0B0B0B] px-[9px] py-px rounded-full text-[11px]"><?= $alreadySetCount ?></span>
                    </button>
                </div>
                <div class="mb-4 relative">
                    <i class="fas fa-search absolute left-[14px] top-1/2 -translate-y-1/2 text-[#6B6B6B] text-[13px] pointer-events-none"></i>
                    <input type="text" id="clientSearch" placeholder="Search by client name, project, or reference…"
                        oninput="filterClients()"
                        class="w-full py-3 pr-4 pl-10 border border-[#E2E2E2] rounded-[11px] font-sans text-[13px] outline-none bg-white text-[#0B0B0B] transition-colors focus:border-[#0B0B0B]">
                </div>
                <div id="noResults" class="hidden text-center py-[30px] text-[#6B6B6B] text-[13px]">
                    <i class="fas fa-search text-[28px] block mb-2.5 opacity-40"></i>
                    No clients match your search.
                </div>
                <?php if (empty($clients)): ?>
                    <div class="text-center py-10 text-[#6B6B6B]">
                        <i class="fas fa-inbox text-4xl block mb-3"></i>
                        No clients assigned to you yet.
                    </div>
                <?php else: ?>
                    <div class="flex flex-col gap-2.5">
                        <?php foreach ($clients as $c):
                            $hasTimeline = !empty($c['overall_start_date']);
                            ?>
                            <a href="<?= BASE_URL ?>coordinator-timeline?client_id=<?= $c['id'] ?>"
                                class="client-row border border-[#E2E2E2] rounded-[10px] px-5 py-4 flex items-center justify-between cursor-pointer transition-all no-underline bg-white hover:border-[#0B0B0B] hover:shadow-[0_10px_26px_-16px_rgba(11,11,11,0.25)] hover:-translate-y-0.5"
                                data-search="<?= htmlspecialchars(strtolower($c['clientname'] . ' ' . $c['nameproject'] . ' ' . ($c['reference_number'] ?? ''))) ?>"
                                data-timeline="<?= empty($c['overall_start_date']) ? 'notset' : 'set' ?>">
                                <div>
                                    <div class="text-[15px] font-bold text-[#0B0B0B]"><?= htmlspecialchars($c['clientname']) ?></div>
                                    <div class="text-xs text-[#6B6B6B] mt-0.5">
                                        <?= htmlspecialchars($c['nameproject']) ?>
                                        <?php if ($c['reference_number']): ?>
                                            &nbsp;•&nbsp;<span class="font-mono"><?= htmlspecialchars($c['reference_number']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="<?= $C_BADGE ?>"><?= htmlspecialchars(bizLabel($c['business_type'])) ?></span>
                                    <?php if ($hasTimeline): ?>
                                        <span class="text-emerald-500 text-xs font-bold flex items-center gap-1.5">
                                            <i class="fas fa-calendar-check"></i> Timeline Set
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs font-semibold flex items-center gap-1.5">
                                            <i class="fas fa-calendar-times"></i> Not Set
                                        </span>
                                    <?php endif; ?>
                                    <i class="fas fa-chevron-right text-gray-300"></i>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- ══ TIMELINE EDITOR ══ -->
            <a href="<?= BASE_URL ?>coordinator-timeline" class="<?= $C_BTN_BACK ?>">
                <i class="fas fa-arrow-left"></i> Back to Project List
            </a>

            <!-- Client Info Header -->
            <div class="<?= $C_CARD ?> !mb-5">
                <div class="flex justify-between items-start flex-wrap gap-3">
                    <div>
                        <h2 class="font-sans text-xl font-bold mb-1 text-[#0B0B0B]">
                            <?= htmlspecialchars($selectedClient['clientname']) ?>
                        </h2>
                        <p class="opacity-85 text-sm text-[#6B6B6B]"><?= htmlspecialchars($selectedClient['nameproject']) ?></p>
                        <?php if ($selectedClient['reference_number']): ?>
                            <p class="opacity-65 text-xs font-mono mt-1 text-[#6B6B6B]">
                                <?= htmlspecialchars($selectedClient['reference_number']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="flex gap-2.5 flex-wrap items-center">
                        <span class="<?= $C_BADGE ?>">
                            <?= htmlspecialchars(bizLabel($selectedClient['business_type'])) ?>
                        </span>
                        <?php if (!empty($selectedClient['overall_start_date'])): ?>
                            <span class="bg-emerald-100 text-emerald-800 px-4 py-1.5 rounded-full text-xs font-bold border border-emerald-200">
                                <i class="fas fa-calendar-check"></i> Timeline Active
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-2 mb-5">
                <button class="<?= $C_VIEW_TAB ?> <?= $C_VIEW_TAB_ON ?>" onclick="switchTab('settings')" id="tab-settings">
    <i class="fas fa-cog"></i> Timeline Settings
</button>
<button class="<?= $C_VIEW_TAB ?> <?= $C_VIEW_TAB_OFF ?>" onclick="switchTab('gantt')" id="tab-gantt">
    <i class="fas fa-chart-gantt"></i> Gantt Chart View
</button>
            </div>

            <!-- ── SETTINGS PANEL ── -->
            <div id="panel-settings">

                <!-- Overall Timeline -->
                <div class="<?= $C_CARD ?>">
                    <div class="<?= $C_CARD_TITLE ?>"><i class="fas fa-calendar-alt text-blue-500"></i> Overall Project Timeline</div>

                    <?php
                    $overallStart = $selectedClient['overall_start_date'] ?? '';
                    $overallEnd = $selectedClient['overall_end_date'] ?? '';
                    $overallDuration = $selectedClient['overall_duration'] ?? 0;
                    ?>

                    <p class="text-[13px] text-[#6B6B6B] mb-3.5">
                        Tap the bar below to open the calendar and pick the project's start and end dates.
                    </p>

                    <form method="POST" id="form-overall">
                        <input type="hidden" name="action" value="save_overall_timeline">
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        <input type="hidden" name="overall_start_date" id="h_overall_start"
                            value="<?= htmlspecialchars($overallStart) ?>">
                        <input type="hidden" name="overall_end_date" id="h_overall_end"
                            value="<?= htmlspecialchars($overallEnd) ?>">

                        <div class="border border-[#E2E2E2] rounded-[10px] px-6 py-5 cursor-pointer transition-all bg-[#F5F5F5] flex items-center gap-5 flex-wrap min-h-[80px] hover:border-[#0B0B0B] hover:bg-white <?= $overallStart ? 'bg-white' : '' ?>"
                            id="ord-overall" onclick="openCalendar('overall','overall','Overall Project','overall')">
                            <?php if ($overallStart): ?>
                                <div class="flex flex-col gap-1">
                                    <div class="text-[10px] font-bold uppercase tracking-[0.5px] text-[#6B6B6B]">
                                        <i class="fas fa-play-circle text-emerald-500"></i> Start Date
                                    </div>
                                    <div class="text-[17px] font-bold text-[#0B0B0B]" id="ord-start-overall">
                                        <?= date('M d, Y', strtotime($overallStart)) ?>
                                    </div>
                                </div>
                                <div class="text-[#6B6B6B] text-[22px]"><i class="fas fa-arrow-right"></i></div>
                                <div class="flex flex-col gap-1">
                                    <div class="text-[10px] font-bold uppercase tracking-[0.5px] text-[#6B6B6B]">
                                        <i class="fas fa-stop-circle text-red-500"></i> End Date
                                    </div>
                                    <div class="text-[17px] font-bold text-[#0B0B0B]" id="ord-end-overall"><?= date('M d, Y', strtotime($overallEnd)) ?></div>
                                </div>
                                <div class="bg-[#0B0B0B] text-white px-4 py-[5px] rounded-full text-xs font-bold ml-auto whitespace-nowrap" id="ord-dur-overall">
                                    <i class="fas fa-clock"></i> <?= $overallDuration ?> day<?= $overallDuration != 1 ? 's' : '' ?>
                                </div>
                            <?php else: ?>
                                <div class="flex items-center gap-2 text-[#6B6B6B] text-[13px] font-medium" id="ord-tap-overall">
                                    <i class="fas fa-calendar-plus text-[22px] text-[#9A9A9A]"></i>
                                    <div>
                                        <div class="font-bold text-[#0B0B0B] text-sm">Tap to set project dates</div>
                                        <div class="text-xs text-[#6B6B6B] mt-0.5">Pick start → end in a single calendar</div>
                                    </div>
                                </div>
                                <!-- Hidden placeholders updated by JS -->
                                <div class="hidden" id="ord-start-overall"></div>
                                <div class="hidden" id="ord-end-overall"></div>
                                <div class="hidden" id="ord-dur-overall"></div>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="<?= $C_BTN_SAVE ?>" onclick="return validateOverall()">
                            <i class="fas fa-save"></i> Save Overall Timeline
                        </button>
                    </form>
                </div>

                <?php if (empty($areas)): ?>
                    <div class="<?= $C_CARD ?> text-center py-10 text-[#6B6B6B]">
                        <i class="fas fa-layer-group text-4xl block mb-3"></i>
                        No areas found for this client yet.
                    </div>
                <?php else: ?>

                    <?php
                    // Only show stage deadlines for Project type (not Individual/Non-Project)
                    $isNonProjectClient = ($selectedClient['business_type'] ?? '') === 'Non-Project';
                    $deadlineStages = $isNonProjectClient
                        ? ['2D / 3D Layout', 'Cuttinglist']
                        : ['Samples Submitted TDS/SDS', '2D / 3D Layout', 'Cuttinglist'];
                    if (true):
                        ?>
                        <!-- STAGE DEADLINES -->
                        <div class="<?= $C_CARD ?>">
                            <div class="<?= $C_CARD_TITLE ?>"><i class="fas fa-calendar-exclamation text-red-500"></i> Step 1 — Stage Deadlines</div>
                            <p class="text-[13px] text-[#6B6B6B] mb-[18px]">
                                Set start and end deadlines for specific stages. These are visible to assigned staff on their
                                respective pages.
                            </p>
                            <div class="flex flex-col gap-4">
                                <?php foreach ($deadlineStages as $ds):
                                    $sdData = $stageDeadlines[$ds] ?? [];
                                    $hasSD = !empty($sdData['start_date']);
                                    $dsSlug = 'sd_' . preg_replace('/[^a-zA-Z0-9]/', '_', $ds);
                                    $dsIcons = [
                                        'Samples Submitted TDS/SDS' => 'fa-vials',
                                        '2D / 3D Layout' => 'fa-drafting-compass',
                                        'Cuttinglist' => 'fa-cut',
                                    ];
                                    $dsColorClasses = [
                                        'Samples Submitted TDS/SDS' => 'text-violet-500',
                                        '2D / 3D Layout' => 'text-sky-700',
                                        'Cuttinglist' => 'text-amber-600',
                                    ];
                                    $dsIcon = $dsIcons[$ds] ?? 'fa-calendar';
                                    $dsColorClass = $dsColorClasses[$ds] ?? 'text-gray-700';
                                    ?>
                                    <div class="border border-[#E2E2E2] rounded-[10px] overflow-hidden">
                                        <div class="bg-[#F5F5F5] px-5 py-3.5 flex items-center justify-between gap-2.5 flex-wrap">
                                            <span class="text-sm font-bold text-[#0B0B0B] flex items-center gap-2">
                                                <i class="fas <?= $dsIcon ?> <?= $dsColorClass ?>"></i>
                                                <?= htmlspecialchars($ds) ?>
                                            </span>
                                            <span class="<?= $C_PILL ?> <?= $hasSD ? $C_PILL_DONE : $C_PILL_NONE ?>">
                                                <?= $hasSD ? 'Set' : 'Not Set' ?>
                                            </span>
                                        </div>
                                        <div class="p-5 bg-white border-t border-[#E2E2E2]">
                                            <form method="POST" id="form-<?= $dsSlug ?>">
                                                <input type="hidden" name="action" value="save_stage_deadline">
                                                <input type="hidden" name="client_id" value="<?= $client_id ?>">
                                                <input type="hidden" name="stage_name" value="<?= htmlspecialchars($ds) ?>">
                                                <input type="hidden" name="start_date" id="h_<?= $dsSlug ?>_start"
                                                    value="<?= htmlspecialchars($sdData['start_date'] ?? '') ?>">
                                                <input type="hidden" name="end_date" id="h_<?= $dsSlug ?>_end"
                                                    value="<?= htmlspecialchars($sdData['end_date'] ?? '') ?>">

                                                <div class="grid grid-cols-2 gap-3.5 mb-3.5">
                                                    <!-- Start date display -->
                                                    <div>
                                                        <div class="text-[11px] font-bold uppercase tracking-[.5px] text-[#6B6B6B] mb-[7px]">
                                                            <i class="fas fa-play-circle text-emerald-500"></i> Start Date
                                                        </div>
                                                        <div class="<?= $C_RANGE_DISPLAY ?> <?= $hasSD ? 'bg-white' : '' ?>"
                                                            id="rd-<?= $dsSlug ?>-start"
                                                            onclick="openSingleCal('<?= $dsSlug ?>','start','<?= htmlspecialchars($ds, ENT_QUOTES) ?> Start')">
                                                            <div class="absolute top-2.5 right-3 text-[11px] text-[#9A9A9A] font-semibold flex items-center gap-1"><i class="fas fa-pen"></i></div>
                                                            <div class="flex items-center gap-2 text-[13px] font-bold text-[#0B0B0B] flex-wrap <?= $hasSD ? '' : 'text-[#6B6B6B] font-medium' ?>"
                                                                id="rd-dates-<?= $dsSlug ?>-start">
                                                                <?php if ($hasSD): ?>
                                                                    <span><?= date('M d, Y', strtotime($sdData['start_date'])) ?></span>
                                                                <?php else: ?>
                                                                    <span><i class="fas fa-calendar-plus mr-1.5 <?= $dsColorClass ?>"></i>Tap to pick</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <!-- End date display -->
                                                    <div>
                                                        <div class="text-[11px] font-bold uppercase tracking-[.5px] text-[#6B6B6B] mb-[7px]">
                                                            <i class="fas fa-stop-circle text-red-500"></i> End Date / Deadline
                                                        </div>
                                                        <div class="<?= $C_RANGE_DISPLAY ?> <?= (!empty($sdData['end_date'])) ? 'bg-white' : '' ?>"
                                                            id="rd-<?= $dsSlug ?>-end"
                                                            onclick="openSingleCal('<?= $dsSlug ?>','end','<?= htmlspecialchars($ds, ENT_QUOTES) ?> Deadline')">
                                                            <div class="absolute top-2.5 right-3 text-[11px] text-[#9A9A9A] font-semibold flex items-center gap-1"><i class="fas fa-pen"></i></div>
                                                            <div class="flex items-center gap-2 text-[13px] font-bold text-[#0B0B0B] flex-wrap <?= (!empty($sdData['end_date'])) ? '' : 'text-[#6B6B6B] font-medium' ?>"
                                                                id="rd-dates-<?= $dsSlug ?>-end">
                                                                <?php if (!empty($sdData['end_date'])): ?>
                                                                    <span><?= date('M d, Y', strtotime($sdData['end_date'])) ?></span>
                                                                <?php else: ?>
                                                                    <span><i class="fas fa-calendar-plus mr-1.5 <?= $dsColorClass ?>"></i>Tap to pick</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <?php if ($hasSD && !empty($sdData['duration'])): ?>
                                                    <div class="text-xs text-[#6B6B6B] mb-3 flex items-center gap-1.5">
                                                        <i class="fas fa-clock <?= $dsColorClass ?>"></i>
                                                        <span id="sd-dur-<?= $dsSlug ?>"><?= $sdData['duration'] ?> day<?= $sdData['duration'] != 1 ? 's' : '' ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-xs text-[#6B6B6B] mb-3 hidden" id="sd-dur-<?= $dsSlug ?>"></div>
                                                <?php endif; ?>

                                                <button type="submit" class="<?= $C_BTN_SAVE ?> !mt-0">
                                                    <i class="fas fa-save"></i> Save Deadline
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- STEP 2: Assign Groups — Modal based -->
                    <div class="<?= $C_CARD ?>">
                        <div class="<?= $C_CARD_TITLE ?>"><i class="fas fa-object-group text-[#6B6B6B]"></i> Step 2 — Group Areas</div>
                        <p class="text-[13px] text-[#6B6B6B] mb-[18px]">
                            Create a group label and assign areas to it. All areas in a group will share the same timeline
                            dates.
                        </p>

                        <?php
                        $allGroupLabels = array_unique(array_filter(array_values($groupLabels)));
                        $ungroupedAreas = array_filter($areas, fn($a) => empty($groupLabels[$a]));
                        ?>
                        <button type="button" onclick="openGroupModal('', [])"
                            class="bg-[#0B0B0B] text-white px-[22px] py-2.5 border-0 rounded-[9px] cursor-pointer text-[13px] font-bold font-sans inline-flex items-center gap-2 hover:opacity-85 transition-opacity">
                            <i class="fas fa-plus"></i> Add / Edit Group
                        </button>
                    </div>

                    <!-- ── GROUP MODAL ── -->
                    <div id="groupModal" class="hidden fixed inset-0 bg-[rgba(11,11,11,0.45)] z-[9998] items-center justify-center p-5">
                        <div class="anim-pop-in bg-white rounded-2xl p-[30px] max-w-[520px] w-full shadow-[0_24px_70px_rgba(11,11,11,0.2)] max-h-[90vh] overflow-y-auto">

                            <div class="flex items-center justify-between mb-5">
                                <h3 class="font-sans text-[17px] font-bold text-[#0B0B0B] flex items-center gap-2.5">
                                    <i class="fas fa-object-group"></i> <span id="modalTitleText">Group Areas</span>
                                </h3>
                                <button onclick="closeGroupModal()" class="bg-transparent border-0 text-xl text-gray-400 cursor-pointer leading-none">&times;</button>
                            </div>

                            <form method="POST" id="groupModalForm">
                                <input type="hidden" name="action" value="save_group_assignments">
                                <input type="hidden" name="client_id" value="<?= $client_id ?>">
                                <!-- All areas will be submitted with their labels -->
                                <?php foreach ($areas as $area): ?>
                                    <input type="hidden" name="group_label[<?= htmlspecialchars($area, ENT_QUOTES) ?>]"
                                        id="modal_gl_<?= md5($area) ?>" value="<?= htmlspecialchars($groupLabels[$area] ?? '') ?>">
                                <?php endforeach; ?>

                                <!-- Group Label Input -->
                                <div class="mb-5">
                                    <label class="text-[11px] font-bold uppercase tracking-[0.5px] text-[#6B6B6B] block mb-2">
                                        <i class="fas fa-tag text-[#6B6B6B]"></i> Group Label
                                    </label>
                                    <input type="text" id="modalGroupLabelInput" placeholder="e.g. Room 1, Floor 2, Main Areas…"
                                        class="w-full px-4 py-3 border border-[#E2E2E2] rounded-[10px] font-sans text-sm font-semibold outline-none text-[#0B0B0B] focus:border-[#0B0B0B]">
                                    <div class="text-[11px] text-[#6B6B6B] mt-1.5">
                                        <i class="fas fa-info-circle"></i> Type a label then check the areas below to assign them to this group.
                                    </div>
                                </div>

                                <!-- Area Checkboxes -->
                                <div class="mb-5">
                                    <label class="text-[11px] font-bold uppercase tracking-[0.5px] text-[#6B6B6B] block mb-2.5">
                                        <i class="fas fa-map-marker-alt text-[#6B6B6B]"></i> Select Areas
                                    </label>
                                    <div class="flex flex-col gap-2" id="modalAreaList">
                                        <?php foreach ($areas as $area): ?>
                                            <label
                                                class="flex items-center gap-3 px-4 py-3 border border-[#E2E2E2] rounded-[10px] cursor-pointer transition-all bg-white hover:border-[#0B0B0B]"
                                                id="modal_row_<?= md5($area) ?>">
                                                <input type="checkbox" id="modal_chk_<?= md5($area) ?>"
                                                    data-area="<?= htmlspecialchars($area, ENT_QUOTES) ?>"
                                                    data-areahash="<?= md5($area) ?>" onchange="syncRowStyle('<?= md5($area) ?>')"
                                                    class="w-[18px] h-[18px] cursor-pointer accent-[#0B0B0B]">
                                                <div class="flex-1">
                                                    <div class="text-[13px] font-bold text-[#0B0B0B]">
                                                        <?= htmlspecialchars($area) ?>
                                                    </div>
                                                    <div id="modal_cur_<?= md5($area) ?>" class="text-[11px] text-[#6B6B6B] mt-0.5">
                                                        <?php $cur = $groupLabels[$area] ?? ''; ?>
                                                        <?= $cur ? '<i class="fas fa-tag"></i> Currently: <strong>' . htmlspecialchars($cur) . '</strong>' : 'No group assigned' ?>
                                                    </div>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Remove from group option -->
                                <div id="modalRemoveSection" class="bg-red-50 border border-red-300 rounded-[10px] px-4 py-3 mb-5 flex items-center gap-2.5">
                                    <input type="checkbox" id="modalRemoveChecked" class="w-4 h-4 accent-red-500">
                                    <label for="modalRemoveChecked" class="text-[13px] font-semibold text-red-800 cursor-pointer">
                                        <i class="fas fa-times-circle"></i> Remove checked areas from their current group (ungroup them)
                                    </label>
                                </div>

                                <div class="flex gap-2.5 justify-end">
                                    <button type="button" onclick="closeGroupModal()"
                                        class="bg-white border border-[#E2E2E2] text-[#6B6B6B] px-5 py-2.5 rounded-[9px] cursor-pointer text-[13px] font-semibold font-sans">
                                        Cancel
                                    </button>
                                    <button type="button" onclick="submitGroupModal()"
                                        class="bg-[#0B0B0B] text-white px-6 py-2.5 border-0 rounded-[9px] cursor-pointer text-[13px] font-bold font-sans inline-flex items-center gap-[7px] hover:opacity-85 transition-opacity">
                                        <i class="fas fa-save"></i> Save Group
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- STEP 3: Set Dates per Group -->
                    <div class="<?= $C_CARD ?>">
                        <div class="<?= $C_CARD_TITLE ?>"><i class="fas fa-calendar-alt text-[#6B6B6B]"></i> Step 3 — Set Timeline per Group</div>
                        <p class="text-[13px] text-[#6B6B6B] mb-[18px]">
                            Set dates for each group below. All areas in a group share the same dates automatically.
                        </p>

                        <div class="flex flex-col gap-3">

                            <?php
                            // Build display entries: grouped ones + ungrouped
                            $displayEntries = [];
                            foreach ($groups as $label => $areasInGroup) {
                                $displayEntries[] = ['type' => 'group', 'label' => $label, 'areas' => $areasInGroup];
                            }
                            foreach ($ungrouped as $area) {
                                $displayEntries[] = ['type' => 'single', 'label' => $area, 'areas' => [$area]];
                            }
                            ?>

                            <?php foreach ($displayEntries as $entry):
                                $entryLabel = $entry['label'];
                                $entryAreas = $entry['areas'];
                                $isGroup = $entry['type'] === 'group';
                                // Use first area's timeline data (all areas in group share same dates)
                                $tl = $areaTimelines[$entryAreas[0]] ?? [];
                                $hasFab = !empty($tl['fab_start']);
                                $hasDel = !empty($tl['del_start']);
                                $hasIns = !empty($tl['ins_start']);
                                $setCount = (int) $hasFab + (int) $hasDel + (int) $hasIns;
                                if ($setCount === 3) {
                                    $pillClass = $C_PILL_DONE;
                                    $pillText = 'Complete';
                                } elseif ($setCount > 0) {
                                    $pillClass = $C_PILL_PARTIAL;
                                    $pillText = "$setCount/3 Set";
                                } else {
                                    $pillClass = $C_PILL_NONE;
                                    $pillText = 'Not Set';
                                }
                                $eSlug = 'e_' . preg_replace('/[^a-zA-Z0-9]/', '_', $entryLabel);
                                ?>
                                <div class="border border-[#E2E2E2] rounded-[10px] overflow-hidden" id="block-<?= $eSlug ?>">
                                    <div class="flex items-center justify-between gap-2.5 flex-wrap w-full bg-[#F5F5F5] px-5 py-[15px] rounded-t-[10px]">
                                        <button type="button" onclick="toggleArea('<?= $eSlug ?>')"
                                            class="flex-1 bg-transparent border-0 p-0 flex items-center gap-2.5 flex-wrap cursor-pointer font-sans text-left">
                                            <?php if ($isGroup): ?>
                                                <i class="fas fa-object-group text-[#6B6B6B]"></i>
                                                <span class="text-sm font-bold text-[#0B0B0B]"><?= htmlspecialchars($entryLabel) ?></span>
                                                <span class="bg-amber-100 text-amber-800 px-2.5 py-0.5 rounded-full text-[11px] font-bold">
                                                    <i class="fas fa-layer-group"></i> <?= count($entryAreas) ?> area<?= count($entryAreas) > 1 ? 's' : '' ?>:
                                                    <?= htmlspecialchars(implode(', ', $entryAreas)) ?>
                                                </span>
                                            <?php else: ?>
                                                <i class="fas fa-map-marker-alt text-[#6B6B6B]"></i>
                                                <span class="text-sm font-bold text-[#0B0B0B]"><?= htmlspecialchars($entryLabel) ?></span>
                                            <?php endif; ?>
                                        </button>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <?php if ($isGroup): ?>
                                                <button type="button" class="edit-group-btn"
                                                    data-label="<?= htmlspecialchars($entryLabel, ENT_QUOTES) ?>"
                                                    data-areas="<?= htmlspecialchars(json_encode(array_values($entryAreas)), ENT_QUOTES) ?>"
                                                    class="bg-white border border-[#E2E2E2] text-[#6B6B6B] px-3 py-[5px] rounded-lg cursor-pointer text-[11px] font-bold font-sans inline-flex items-center gap-1">
                                                    <i class="fas fa-pen"></i> Edit Group
                                                </button>
                                            <?php endif; ?>
                                            <span class="<?= $C_PILL ?> <?= $pillClass ?>"><?= $pillText ?></span>
                                            <i class="fas fa-chevron-down chev-icon transition-transform duration-200 text-gray-300" id="chev-<?= $eSlug ?>"></i>
                                        </div>
                                    </div>

                                    <div class="area-body hidden p-6 bg-white border-t border-[#E2E2E2]" id="body-<?= $eSlug ?>">
                                        <form method="POST" id="form-<?= $eSlug ?>">
                                            <input type="hidden" name="action" value="save_group_timeline">
                                            <input type="hidden" name="client_id" value="<?= $client_id ?>">
                                            <input type="hidden" name="group_label" value="<?= htmlspecialchars($entryLabel) ?>">
                                            <input type="hidden" name="fab_start" id="h_<?= $eSlug ?>_fab_start"
                                                value="<?= htmlspecialchars($tl['fab_start'] ?? '') ?>">
                                            <input type="hidden" name="fab_end" id="h_<?= $eSlug ?>_fab_end"
                                                value="<?= htmlspecialchars($tl['fab_end'] ?? '') ?>">
                                            <input type="hidden" name="del_start" id="h_<?= $eSlug ?>_del_start"
                                                value="<?= htmlspecialchars($tl['del_start'] ?? '') ?>">
                                            <input type="hidden" name="del_end" id="h_<?= $eSlug ?>_del_end"
                                                value="<?= htmlspecialchars($tl['del_end'] ?? '') ?>">
                                            <input type="hidden" name="ins_start" id="h_<?= $eSlug ?>_ins_start"
                                                value="<?= htmlspecialchars($tl['ins_start'] ?? '') ?>">
                                            <input type="hidden" name="ins_end" id="h_<?= $eSlug ?>_ins_end"
                                                value="<?= htmlspecialchars($tl['ins_end'] ?? '') ?>">

                                            <?php if ($isGroup): ?>
                                                <div class="bg-amber-50 border border-amber-200 rounded-[9px] px-3.5 py-2.5 mb-4 text-xs text-amber-800 flex items-center gap-2">
                                                    <i class="fas fa-info-circle"></i>
                                                    These dates will apply to all <?= count($entryAreas) ?> areas:
                                                    <strong><?= htmlspecialchars(implode(', ', $entryAreas)) ?></strong>
                                                </div>
                                            <?php endif; ?>

                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                <!-- FABRICATION -->
                                                <div class="border border-[#E2E2E2] rounded-[10px] p-[18px]">
                                                    <div class="text-xs font-bold uppercase tracking-[0.6px] mb-3.5 flex items-center gap-1.5 text-blue-500">
                                                        <i class="fas fa-tools"></i> Fabrication
                                                    </div>
                                                    <div class="<?= $C_RANGE_DISPLAY ?> <?= $hasFab ? 'bg-white' : '' ?>"
                                                        id="rd-<?= $eSlug ?>-fab"
                                                        onclick="openCalendar('<?= $eSlug ?>','fab','Fabrication','<?= $eSlug ?>-fab')">
                                                        <div class="text-[10px] font-bold uppercase tracking-[0.5px] text-[#6B6B6B] mb-2">Date Range</div>
                                                        <div class="absolute top-2.5 right-3 text-[11px] text-[#9A9A9A] font-semibold flex items-center gap-1"><i class="fas fa-pen"></i></div>
                                                        <div class="flex items-center gap-2 text-[13px] font-bold text-[#0B0B0B] flex-wrap <?= $hasFab ? '' : 'text-[#6B6B6B] font-medium' ?>"
                                                            id="rd-dates-<?= $eSlug ?>-fab">
                                                            <?php if ($hasFab): ?>
                                                                <span><?= date('M d', strtotime($tl['fab_start'])) ?></span>
                                                                <span class="text-[#6B6B6B] text-[11px]">→</span>
                                                                <span><?= date('M d, Y', strtotime($tl['fab_end'])) ?></span>
                                                            <?php else: ?>
                                                                <span><i class="fas fa-calendar-plus mr-1.5 text-blue-500"></i>Tap to pick dates</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="mt-2.5 text-[11px] font-bold inline-flex items-center gap-[5px] bg-[#E2E2E2] text-[#0B0B0B] px-2.5 py-[3px] rounded-full <?= ($hasFab && ($tl['fab_duration'] ?? 0) > 0) ? '' : 'hidden' ?>" id="rd-dur-<?= $eSlug ?>-fab">
                                                            <?php if ($hasFab && ($tl['fab_duration'] ?? 0) > 0): ?>
                                                                <i class="fas fa-clock text-blue-500"></i>
                                                                <?= $tl['fab_duration'] ?> day<?= $tl['fab_duration'] != 1 ? 's' : '' ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <!-- DELIVERY -->
                                                <div class="border border-[#E2E2E2] rounded-[10px] p-[18px]">
                                                    <div class="text-xs font-bold uppercase tracking-[0.6px] mb-3.5 flex items-center gap-1.5 text-violet-500">
                                                        <i class="fas fa-truck"></i> Delivery
                                                    </div>
                                                    <div class="<?= $C_RANGE_DISPLAY ?> <?= $hasDel ? 'bg-white' : '' ?>"
                                                        id="rd-<?= $eSlug ?>-del"
                                                        onclick="openCalendar('<?= $eSlug ?>','del','Delivery','<?= $eSlug ?>-del')">
                                                        <div class="text-[10px] font-bold uppercase tracking-[0.5px] text-[#6B6B6B] mb-2">Date Range</div>
                                                        <div class="absolute top-2.5 right-3 text-[11px] text-[#9A9A9A] font-semibold flex items-center gap-1"><i class="fas fa-pen"></i></div>
                                                        <div class="flex items-center gap-2 text-[13px] font-bold text-[#0B0B0B] flex-wrap <?= $hasDel ? '' : 'text-[#6B6B6B] font-medium' ?>"
                                                            id="rd-dates-<?= $eSlug ?>-del">
                                                            <?php if ($hasDel): ?>
                                                                <span><?= date('M d', strtotime($tl['del_start'])) ?></span>
                                                                <span class="text-[#6B6B6B] text-[11px]">→</span>
                                                                <span><?= date('M d, Y', strtotime($tl['del_end'])) ?></span>
                                                            <?php else: ?>
                                                                <span><i class="fas fa-calendar-plus mr-1.5 text-violet-500"></i>Tap to pick dates</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="mt-2.5 text-[11px] font-bold inline-flex items-center gap-[5px] bg-[#E2E2E2] text-[#0B0B0B] px-2.5 py-[3px] rounded-full <?= ($hasDel && ($tl['del_duration'] ?? 0) > 0) ? '' : 'hidden' ?>" id="rd-dur-<?= $eSlug ?>-del">
                                                            <?php if ($hasDel && ($tl['del_duration'] ?? 0) > 0): ?>
                                                                <i class="fas fa-clock text-violet-500"></i>
                                                                <?= $tl['del_duration'] ?> day<?= $tl['del_duration'] != 1 ? 's' : '' ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <!-- INSTALLATION -->
                                                <div class="border border-[#E2E2E2] rounded-[10px] p-[18px]">
                                                    <div class="text-xs font-bold uppercase tracking-[0.6px] mb-3.5 flex items-center gap-1.5 text-emerald-500">
                                                        <i class="fas fa-hard-hat"></i> Installation
                                                    </div>
                                                    <div class="<?= $C_RANGE_DISPLAY ?> <?= $hasIns ? 'bg-white' : '' ?>"
                                                        id="rd-<?= $eSlug ?>-ins"
                                                        onclick="openCalendar('<?= $eSlug ?>','ins','Installation','<?= $eSlug ?>-ins')">
                                                        <div class="text-[10px] font-bold uppercase tracking-[0.5px] text-[#6B6B6B] mb-2">Date Range</div>
                                                        <div class="absolute top-2.5 right-3 text-[11px] text-[#9A9A9A] font-semibold flex items-center gap-1"><i class="fas fa-pen"></i></div>
                                                        <div class="flex items-center gap-2 text-[13px] font-bold text-[#0B0B0B] flex-wrap <?= $hasIns ? '' : 'text-[#6B6B6B] font-medium' ?>"
                                                            id="rd-dates-<?= $eSlug ?>-ins">
                                                            <?php if ($hasIns): ?>
                                                                <span><?= date('M d', strtotime($tl['ins_start'])) ?></span>
                                                                <span class="text-[#6B6B6B] text-[11px]">→</span>
                                                                <span><?= date('M d, Y', strtotime($tl['ins_end'])) ?></span>
                                                            <?php else: ?>
                                                                <span><i class="fas fa-calendar-plus mr-1.5 text-emerald-500"></i>Tap to pick dates</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="mt-2.5 text-[11px] font-bold inline-flex items-center gap-[5px] bg-[#E2E2E2] text-[#0B0B0B] px-2.5 py-[3px] rounded-full <?= ($hasIns && ($tl['ins_duration'] ?? 0) > 0) ? '' : 'hidden' ?>" id="rd-dur-<?= $eSlug ?>-ins">
                                                            <?php if ($hasIns && ($tl['ins_duration'] ?? 0) > 0): ?>
                                                                <i class="fas fa-clock text-emerald-500"></i>
                                                                <?= $tl['ins_duration'] ?> day<?= $tl['ins_duration'] != 1 ? 's' : '' ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <button type="submit" class="<?= $C_BTN_SAVE ?>">
                                                <i class="fas fa-save"></i> Save Timeline for "<?= htmlspecialchars($entryLabel) ?>"
                                                <?php if ($isGroup): ?>
                                                    <span class="text-[11px] font-normal opacity-80">(applies to <?= count($entryAreas) ?> areas)</span>
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                        </div>
                    </div>
                <?php endif; ?>

            </div><!-- /panel-settings -->

            <!-- ── GANTT PANEL ── -->
            <div id="panel-gantt" class="hidden">
                <?php
                $ganttData = [];
                foreach ($areas as $area) {
                    $tl = $areaTimelines[$area] ?? [];
                    if (!empty($tl))
                        $ganttData[$area] = $tl;
                }
                $allDates = [];
                foreach ($ganttData as $tl) {
                    foreach (['fab_start', 'fab_end', 'del_start', 'del_end', 'ins_start', 'ins_end'] as $f) {
                        if (!empty($tl[$f]))
                            $allDates[] = strtotime($tl[$f]);
                    }
                }
                if ($overallStart)
                    $allDates[] = strtotime($overallStart);
                if ($overallEnd)
                    $allDates[] = strtotime($overallEnd);
                $ganttMin = !empty($allDates) ? strtotime('-2 days', min($allDates)) : strtotime('today');
                $ganttMax = !empty($allDates) ? strtotime('+2 days', max($allDates)) : strtotime('+30 days');
                $totalDays = max(1, round(($ganttMax - $ganttMin) / 86400));
                function pctLeft($d, $min, $tot)
                {
                    return !$d ? 0 : round((strtotime($d) - $min) / ($tot * 86400) * 100, 2);
                }
                function pctWidth($s, $e, $tot)
                {
                    return (!$s || !$e) ? 0 : max(1, round((strtotime($e) - strtotime($s)) / ($tot * 86400) * 100, 2));
                }
                ?>
                <div class="bg-white border border-[#E2E2E2] rounded-[10px] p-6">
                    <div class="flex justify-between items-center mb-4 flex-wrap gap-2.5">
                        <h3 class="font-sans text-base font-bold text-[#0B0B0B] flex items-center gap-2">
                            <i class="fas fa-chart-gantt"></i> Gantt Chart
                        </h3>
                        <?php if ($overallStart): ?>
                            <div class="text-xs text-[#6B6B6B] font-semibold">
                                <?= date('M d, Y', strtotime($overallStart)) ?> → <?= date('M d, Y', strtotime($overallEnd)) ?>
                                <span class="bg-[#0B0B0B] text-white px-2.5 py-0.5 rounded-full ml-2 text-[11px]">
                                    <?= $overallDuration ?> days
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="flex gap-4 mb-4 flex-wrap">
                        <div class="flex items-center gap-1.5 text-xs font-semibold">
                            <div class="w-3 h-3 rounded-[3px] bg-blue-500"></div> Fabrication
                        </div>
                        <div class="flex items-center gap-1.5 text-xs font-semibold">
                            <div class="w-3 h-3 rounded-[3px] bg-violet-500"></div> Delivery
                        </div>
                        <div class="flex items-center gap-1.5 text-xs font-semibold">
                            <div class="w-3 h-3 rounded-[3px] bg-emerald-500"></div> Installation
                        </div>
                    </div>
                    <?php if (empty($ganttData)): ?>
                        <div class="text-center py-[50px] text-[#6B6B6B]">
                            <i class="fas fa-calendar-times text-4xl block mb-3"></i>
                            No timeline data yet. Set dates in Timeline Settings.
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <div class="min-w-[680px]">
                                <div class="flex ml-[190px] mb-1">
                                    <?php $step = max(1, intdiv($totalDays, 8));
                                    for ($d = 0; $d <= $totalDays; $d += $step) {
                                        $ts = strtotime("+{$d} days", $ganttMin);
                                        $pct = round($d / $totalDays * 100, 1);
                                        echo "<div class='text-[10px] text-gray-400 px-0.5' style='flex:0 0 {$pct}%;'>" . date('M d', $ts) . "</div>";
                                    } ?>
                                </div>
                                <?php foreach ($ganttData as $area => $tl):
                                    $displayName = !empty($groupLabels[$area]) ? $groupLabels[$area] . ' (' . $area . ')' : $area;
                                    $lbl = strlen($displayName) > 26 ? substr($displayName, 0, 24) . '…' : $displayName;
                                    $phases = [
                                        ['fab', 'bg-blue-500', 'text-blue-500', 'bg-blue-100 text-blue-700', 'Fabrication', $tl['fab_start'] ?? '', $tl['fab_end'] ?? '', $tl['fab_duration'] ?? 0],
                                        ['del', 'bg-violet-500', 'text-violet-500', 'bg-violet-100 text-violet-700', 'Delivery', $tl['del_start'] ?? '', $tl['del_end'] ?? '', $tl['del_duration'] ?? 0],
                                        ['ins', 'bg-emerald-500', 'text-emerald-500', 'bg-emerald-100 text-emerald-700', 'Installation', $tl['ins_start'] ?? '', $tl['ins_end'] ?? '', $tl['ins_duration'] ?? 0],
                                    ];
                                    ?>
                                    <div class="flex items-center bg-[#F5F5F5] border-t border-[#E2E2E2] mt-1.5">
                                        <div class="w-[190px] shrink-0 px-3 py-2 text-xs font-bold text-[#0B0B0B]">
                                            <i class="fas fa-map-marker-alt text-[#6B6B6B] mr-1"></i><?= htmlspecialchars($lbl) ?>
                                        </div>
                                        <div class="flex-1 h-3 bg-[#E2E2E2] rounded my-1.5"></div>
                                    </div>
                                    <?php foreach ($phases as [$key, $bgClass, $textClass, $chipClass, $label, $start, $end, $dur]):
                                        if (!$start)
                                            continue;
                                        $l = pctLeft($start, $ganttMin, $totalDays);
                                        $w = pctWidth($start, $end, $totalDays);
                                        ?>
                                        <div class="flex items-center border-b border-gray-100">
                                            <div class="w-[190px] shrink-0 py-1 pr-3 pl-6 text-[11px] text-[#6B6B6B]">
                                                <?= $label ?>
                                                <?php if ($dur > 0): ?>
                                                    <span class="<?= $chipClass ?> px-1.5 py-px rounded-lg text-[10px] font-bold ml-1"><?= $dur ?>d</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-1 relative h-[26px] bg-gray-50">
                                                <div class="absolute top-1 bottom-1 <?= $bgClass ?> rounded flex items-center pl-1.5 text-[10px] font-bold text-white overflow-hidden whitespace-nowrap"
                                                    style="left:<?= $l ?>%;width:<?= $w ?>%;">
                                                    <?php if ($w > 8): ?><?= date('M d', strtotime($start)) ?> – <?= date('M d', strtotime($end)) ?><?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php endif; // client_id ?>
    </div><!-- /wrap -->

    <!-- ══════════════ CALENDAR POPUP ══════════════ -->
    <div class="cal-overlay hidden fixed inset-0 bg-[rgba(11,11,11,0.45)] z-[9999] items-center justify-center p-5" id="calOverlay" onclick="overlayClick(event)">
        <div class="anim-pop-in bg-white rounded-2xl p-7 shadow-[0_24px_70px_rgba(11,11,11,0.22)] w-[360px] max-w-full">
            <div class="font-sans text-[15px] font-bold text-[#0B0B0B] mb-3.5 flex items-center gap-2" id="calTitle">
                <i class="fas fa-calendar-alt"></i> <span id="calTitleText">Pick Date Range</span>
            </div>
            <div class="flex items-center justify-between mb-4">
                <button type="button" class="w-9 h-9 border border-[#E2E2E2] rounded-[9px] bg-white cursor-pointer flex items-center justify-center text-[13px] text-[#0B0B0B] transition-all hover:bg-[#F5F5F5] hover:border-[#0B0B0B] shrink-0"
                    onclick="changeMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                <div class="font-sans text-[15px] font-bold text-[#0B0B0B] text-center" id="calMonthYear"></div>
                <button type="button" class="w-9 h-9 border border-[#E2E2E2] rounded-[9px] bg-white cursor-pointer flex items-center justify-center text-[13px] text-[#0B0B0B] transition-all hover:bg-[#F5F5F5] hover:border-[#0B0B0B] shrink-0"
                    onclick="changeMonth(1)"><i class="fas fa-chevron-right"></i></button>
            </div>

            <div class="bg-[#F5F5F5] rounded-[10px] px-4 py-3 mb-3 flex items-center gap-3">
                <div class="flex flex-col gap-[3px] flex-1">
                    <div class="text-[10px] font-bold uppercase tracking-[0.4px] text-[#6B6B6B]"><i class="fas fa-play-circle text-emerald-500"></i> Start</div>
                    <div class="text-[13px] font-bold text-[#6B6B6B] font-normal" id="csStart">Not selected</div>
                </div>
                <div class="text-[#6B6B6B] text-lg"><i class="fas fa-arrow-right"></i></div>
                <div class="flex flex-col gap-[3px] flex-1">
                    <div class="text-[10px] font-bold uppercase tracking-[0.4px] text-[#6B6B6B]"><i class="fas fa-stop-circle text-red-500"></i> End</div>
                    <div class="text-[13px] font-bold text-[#6B6B6B] font-normal" id="csEnd">Not selected</div>
                </div>
            </div>

            <div class="text-xs text-[#6B6B6B] text-center mb-3 leading-relaxed" id="calHint">Tap a date to set the <strong>start</strong></div>

            <div class="grid grid-cols-7 gap-0.5 mb-1.5">
                <div class="text-center text-[11px] font-bold text-[#6B6B6B] py-1">Su</div>
                <div class="text-center text-[11px] font-bold text-[#6B6B6B] py-1">Mo</div>
                <div class="text-center text-[11px] font-bold text-[#6B6B6B] py-1">Tu</div>
                <div class="text-center text-[11px] font-bold text-[#6B6B6B] py-1">We</div>
                <div class="text-center text-[11px] font-bold text-[#6B6B6B] py-1">Th</div>
                <div class="text-center text-[11px] font-bold text-[#6B6B6B] py-1">Fr</div>
                <div class="text-center text-[11px] font-bold text-[#6B6B6B] py-1">Sa</div>
            </div>
            <div class="grid grid-cols-7 gap-[3px]" id="calDays"></div>

            <div class="flex gap-2 justify-end mt-4">
                <button type="button"
                    class="px-[18px] py-[9px] rounded-[9px] border border-[#E2E2E2] bg-white text-[13px] font-semibold cursor-pointer text-[#6B6B6B] font-sans hover:border-red-500 hover:text-red-500 transition-colors"
                    onclick="clearCalendar()">
                    <i class="fas fa-times"></i> Clear
                </button>
                <button type="button"
                    class="px-[22px] py-[9px] rounded-[9px] border-0 bg-[#0B0B0B] text-white text-[13px] font-bold cursor-pointer font-sans transition-opacity hover:opacity-85 disabled:opacity-40 disabled:cursor-default"
                    onclick="confirmCalendar()" id="calConfirm" disabled>
                    <i class="fas fa-check"></i> Confirm
                </button>
            </div>
        </div>
    </div>

    <script>
        window.CoordTimelineData = {
            hasClientId: <?= $client_id ? 'true' : 'false' ?>,
            currentGroupLabels: <?php
                $jsGroupLabels = [];
                if ($client_id) {
                    foreach ($areas as $area) {
                        $jsGroupLabels[$area] = $groupLabels[$area] ?? '';
                    }
                }
                echo json_encode($jsGroupLabels, JSON_UNESCAPED_UNICODE);
            ?>
        };
    </script>
    <script src="<?= ADMIN_ASSET ?>/tracker-management/tracker-coordinator/coordinator_timeline.js"></script>
</body>

</html>