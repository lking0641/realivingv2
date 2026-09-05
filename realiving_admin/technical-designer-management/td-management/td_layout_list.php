<?php
// td_layout_list.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');

$admin_id = $_SESSION['admin_id'];

$meStmt = $conn->prepare("SELECT full_name, role, is_head FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

$allowedRoles = ['technical_designer', 'general_manager', 'operational_manager'];
if (!in_array($me['role'], $allowedRoles))
    die("Access denied.");

$isHead = ($me['role'] === 'technical_designer' && $me['is_head'] == 1);
$isManager = in_array($me['role'], ['general_manager', 'operational_manager']);
$canViewAll = $isHead;

// ── Fetch clients that have a technical_designer assigned ─────────────────
if ($canViewAll || $isManager) {
    $clientsStmt = $conn->prepare("
        SELECT
            u.id, u.clientname, u.nameproject, u.reference_number,
            u.status, u.business_type, u.created_at,
            u.technical_designer_id,
            a1.full_name AS tech_designer_name,
            pt.status     AS layout_stage_status
        FROM user_info u
        LEFT JOIN account a1 ON u.technical_designer_id = a1.id
        LEFT JOIN project_tracker pt
               ON pt.client_id = u.id AND pt.stage_name = 'Cuttinglist'
        WHERE u.technical_designer_id IS NOT NULL
        ORDER BY u.created_at DESC
    ");
    $clientsStmt->execute();
} else {
    $clientsStmt = $conn->prepare("
        SELECT
            u.id, u.clientname, u.nameproject, u.reference_number,
            u.status, u.business_type, u.created_at,
            u.technical_designer_id,
            a1.full_name AS tech_designer_name,
            pt.status     AS layout_stage_status
        FROM user_info u
        LEFT JOIN account a1 ON u.technical_designer_id = a1.id
        LEFT JOIN project_tracker pt
               ON pt.client_id = u.id AND pt.stage_name = 'Cuttinglist'
        WHERE u.technical_designer_id = ?
        ORDER BY u.created_at DESC
    ");
    $clientsStmt->bind_param("i", $admin_id);
    $clientsStmt->execute();
}
$clients = $clientsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Partition ─────────────────────────────────────────────────────────────
$myClients = [];
$otherClients = [];
$doneClients = [];

foreach ($clients as $c) {
    $isMine = ($c['technical_designer_id'] == $admin_id);
    $isDone = ($c['layout_stage_status'] === 'Done');
    if ($isDone) {
        $doneClients[] = array_merge($c, ['_is_mine' => $isMine]);
    } elseif ($isMine) {
        $myClients[] = $c;
    } else {
        $otherClients[] = $c;
    }
}

$activeClients = array_merge($myClients, $otherClients);

// Fetch rejected layout count per client (only for assigned TD's own clients)
foreach ($activeClients as &$c) {
    $isMineCheck = ($c['technical_designer_id'] == $admin_id);
    $c['rejected_td_layouts'] = $isMineCheck
        ? getTDRejectedForClient($conn, $c['id'])
        : 0;
}
unset($c);
// Re-partition after enrichment
$myClients = [];
$otherClients = [];
foreach ($activeClients as $c) {
    $isMine = ($c['technical_designer_id'] == $admin_id);
    if ($isMine)
        $myClients[] = $c;
    else
        $otherClients[] = $c;
}
$activeClients = array_merge($myClients, $otherClients);

$totalActive = count($activeClients);
$totalDone = count($doneClients);
$intakePending = 0;
$intakeDoneCount = count($activeClients);

// ── My TD team (head only) ────────────────────────────────────────────────
$myDesigners = [];
if ($isHead) {
    $dStmt = $conn->prepare("
        SELECT id, full_name, role,
            (SELECT COUNT(*) FROM user_info WHERE technical_designer_id = account.id) AS client_count
        FROM account
        WHERE role = 'technical_designer' AND is_head = 0
        ORDER BY full_name ASC
    ");
    $dStmt->execute();
    $myDesigners = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getTDRejectedForClient($conn, $client_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM td_attachment_approvals
        WHERE client_id = ? AND status = 'rejected'
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

// ── Pending approval count per client for this approver ─────────────────
function getTDPendingForClient($conn, $admin_id, $client_id)
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

function getTDRemarkNeededForClient($conn, $admin_id, $client_id) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM layout_approvals la
        INNER JOIN user_info u ON u.id = la.client_id
        WHERE la.client_id = ?
        AND u.technical_designer_id = ?
        AND (la.td_remark IS NULL OR la.td_remark = '')
        AND la.requested_at IS NOT NULL
    ");
    $stmt->bind_param("ii", $client_id, $admin_id);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_row()[0] > 0;
}

// ── Total pending approvals across ALL clients for this approver ─────────
$totalPendingStmt = $conn->prepare("
    SELECT COUNT(*) FROM td_attachment_approvals la
    WHERE la.approver_id = ? AND la.status = 'pending'
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
$totalPendingStmt->bind_param("i", $admin_id);
$totalPendingStmt->execute();
$totalPendingApprovals = (int) $totalPendingStmt->get_result()->fetch_row()[0];

$totalRejectedTD = array_sum(array_column($activeClients, 'rejected_td_layouts'));

// ── Helper: render meta badges ───────────────────────────────────────────
function renderCardMeta($c) {
    global $conn, $admin_id;
    $pendingCount = getTDPendingForClient($conn, $admin_id, $c['id']);
    $remarkNeeded = getTDRemarkNeededForClient($conn, $admin_id, $c['id']);
    ?>
    <div class="flex flex-wrap gap-1.5 mt-2.5 items-center">
        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase border <?= $c['status'] === 'New Client' ? 'bg-amber-100 text-amber-800 border-amber-300' : 'bg-blue-100 text-blue-800 border-blue-300' ?>"><?= htmlspecialchars($c['status']) ?></span>
        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase border <?= $c['business_type'] === 'Project' ? 'bg-purple-100 text-purple-800 border-purple-300' : 'bg-pink-100 text-pink-800 border-pink-300' ?>"><?= $c['business_type'] === 'Non-Project' ? 'Individual' : htmlspecialchars($c['business_type']) ?></span>
        <?php if ($c['tech_designer_name']): ?>
        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border border-line bg-[#F5F5F5] text-ink"><i class="fas fa-tools text-[8px]"></i> TD: <?= htmlspecialchars($c['tech_designer_name']) ?></span>
        <?php endif; ?>
        <?php if ($pendingCount > 0): ?>
        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border border-amber-300 bg-amber-100 text-amber-800 inline-flex items-center gap-1">
            <i class="fas fa-bell text-[9px]"></i> <?= $pendingCount ?> pending approval<?= $pendingCount > 1 ? 's' : '' ?>
        </span>
        <?php endif; ?>
        <?php if ($remarkNeeded): ?>
        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border border-blue-200 bg-blue-50 text-blue-700 inline-flex items-center gap-1">
            <i class="fas fa-comment-medical text-[9px]"></i> Remark needed
        </span>
        <?php endif; ?>
        <?php if (!empty($c['rejected_td_layouts']) && $c['rejected_td_layouts'] > 0): ?>
        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border border-red-300 bg-red-100 text-red-800 inline-flex items-center gap-1">
            <i class="fas fa-times-circle text-[9px]"></i> <?= $c['rejected_td_layouts'] ?> Rejected
        </span>
        <?php endif; ?>
    </div>
<?php }
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technical Designer — Clients</title>
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

        <!-- ── Page Header ── -->
        <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
            <div class="text-[11px] font-semibold tracking-[1.5px] uppercase text-soft mb-2">
                <i class="fas fa-tools"></i> Technical Design
            </div>
            <h1 class="text-2xl font-bold tracking-[-0.01em]">Clients</h1>
            <p class="text-[13.5px] text-soft mt-1">
                <?php if ($isHead): ?>Overview of all clients and your technical design team
                <?php elseif ($isManager): ?>All clients assigned for technical design work
                <?php else: ?>Clients assigned to you for technical design work
                <?php endif; ?>
            </p>
            <div class="inline-flex items-center gap-2 bg-[#F5F5F5] border border-line rounded-full px-4 py-1.5 text-[12px] font-semibold mt-3">
                <i class="fas fa-user-circle text-soft"></i>
                <?= htmlspecialchars($me['full_name']) ?>
                <span class="text-muted">•</span>
                <span class="text-soft capitalize"><?= str_replace('_', ' ', $me['role']) ?></span>
                <?php if ($isHead): ?>
                    <span class="text-muted">•</span>
                    <span class="text-soft"><i class="fas fa-crown text-[10px]"></i> Head</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Stats ── -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
            <div class="bg-white border border-line rounded-[10px] p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-ink text-white flex items-center justify-center text-lg flex-shrink-0"><i class="fas fa-users"></i></div>
                <div>
                    <div class="text-xl font-bold"><?= $totalActive ?></div>
                    <div class="text-[10px] font-semibold uppercase tracking-[0.4px] text-muted">Active Clients</div>
                </div>
            </div>
            <?php if ($canViewAll): ?>
                <div class="bg-white border border-line rounded-[10px] p-4 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-amber-500 text-white flex items-center justify-center text-lg flex-shrink-0"><i class="fas fa-user-check"></i></div>
                    <div>
                        <div class="text-xl font-bold"><?= count($myClients) ?></div>
                        <div class="text-[10px] font-semibold uppercase tracking-[0.4px] text-muted">My Clients</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-white border border-line rounded-[10px] p-4 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-emerald-500 text-white flex items-center justify-center text-lg flex-shrink-0"><i class="fas fa-clipboard-check"></i></div>
                    <div>
                        <div class="text-xl font-bold"><?= $intakeDoneCount ?></div>
                        <div class="text-[10px] font-semibold uppercase tracking-[0.4px] text-muted">Started</div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="bg-white border border-line rounded-[10px] p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-amber-500 text-white flex items-center justify-center text-lg flex-shrink-0"><i class="fas fa-clock"></i></div>
                <div>
                    <div class="text-xl font-bold"><?= $intakePending ?></div>
                    <div class="text-[10px] font-semibold uppercase tracking-[0.4px] text-muted">Not Started</div>
                </div>
            </div>
            <div class="bg-white border border-line rounded-[10px] p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-teal-600 text-white flex items-center justify-center text-lg flex-shrink-0"><i class="fas fa-flag-checkered"></i></div>
                <div>
                    <div class="text-xl font-bold"><?= $totalDone ?></div>
                    <div class="text-[10px] font-semibold uppercase tracking-[0.4px] text-muted">Layout Done</div>
                </div>
            </div>
        </div>

        <!-- ── My Technical Design Team (head only) ── -->
        <?php if ($isHead && !empty($myDesigners)): ?>
            <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
                <div class="flex items-center gap-2.5 text-xs font-semibold mb-4">
                    <i class="fas fa-users-cog text-soft"></i> My Technical Design Team
                    <span class="bg-blue-100 text-blue-700 px-2.5 py-0.5 rounded-full text-[11px] font-bold">
                        <?= count($myDesigners) ?> member<?= count($myDesigners) != 1 ? 's' : '' ?>
                    </span>
                    <span class="flex-1 h-px bg-line"></span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5">
                    <?php foreach ($myDesigners as $d):
                        $initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', $d['full_name']), 0, 2)));
                    ?>
                        <div class="bg-[#F5F5F5] border border-line rounded-lg px-3.5 py-2.5 flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-ink text-white flex items-center justify-center text-[13px] font-bold flex-shrink-0">
                                <?= $initials ?>
                            </div>
                            <div>
                                <div class="text-[13px] font-semibold"><?= htmlspecialchars($d['full_name']) ?></div>
                                <div class="text-[10px] text-muted">Technical Designer</div>
                                <div class="text-[10px] font-bold text-soft mt-0.5">
                                    <i class="fas fa-folder text-[9px]"></i>
                                    <?= $d['client_count'] ?> client<?= $d['client_count'] != 1 ? 's' : '' ?> assigned
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── Filter Tabs ── -->
        <div class="flex items-center gap-2 mb-4 flex-wrap">
            <button class="filter-tab inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-[13px] font-semibold transition border bg-ink text-white border-ink"
                data-filter="active" onclick="setFilter('active', this)">
                <i class="fas fa-th-list"></i> Active
                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-white/20"><?= $totalActive ?></span>
            </button>
            <?php if ($canViewAll): ?>
                <button class="filter-tab inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-[13px] font-semibold transition border bg-white text-soft border-line"
                    data-filter="mine" onclick="setFilter('mine', this)">
                    <i class="fas fa-user-check"></i> My Clients
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-[#F5F5F5] text-ink"><?= count($myClients) ?></span>
                </button>
                <button class="filter-tab inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-[13px] font-semibold transition border bg-white text-soft border-line"
                    data-filter="others" onclick="setFilter('others', this)">
                    <i class="fas fa-users"></i> Team's Clients
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-[#F5F5F5] text-ink"><?= count($otherClients) ?></span>
                </button>
            <?php endif; ?>
            <button class="filter-tab inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-[13px] font-semibold transition border bg-white text-soft border-line"
                data-filter="done" onclick="setFilter('done', this)">
                <i class="fas fa-flag-checkered"></i> Done
                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-[#F5F5F5] text-ink"><?= $totalDone ?></span>
            </button>
        </div>

        <!-- ── Search ── -->
        <div class="bg-white border border-line rounded-lg px-4 py-2.5 mb-5 flex items-center gap-2.5">
            <i class="fas fa-search text-muted"></i>
            <input type="text" id="searchInput" placeholder="Search by client name, project, or reference number..."
                class="w-full border-none outline-none text-sm bg-transparent">
        </div>

        <?php if ($totalPendingApprovals > 0): ?>
            <div class="bg-amber-50 border border-amber-300 rounded-lg px-5 py-4 mb-5 flex items-center justify-between gap-3.5 flex-wrap">
                <div class="flex items-center gap-3.5">
                    <i class="fas fa-bell text-amber-600 text-xl flex-shrink-0"></i>
                    <div>
                        <div class="font-bold text-[14px] text-amber-800">
                            You have <?= $totalPendingApprovals ?> pending approval<?= $totalPendingApprovals > 1 ? 's' : '' ?> waiting for your action
                        </div>
                        <div class="text-[12px] text-amber-700 mt-0.5">
                            Cards highlighted below need your review. Click a client to open and approve or reject.
                        </div>
                    </div>
                </div>
                <span class="bg-amber-500 text-white px-4 py-1.5 rounded-lg text-[13px] font-bold whitespace-nowrap">
                    <i class="fas fa-exclamation-circle"></i> <?= $totalPendingApprovals ?> Pending
                </span>
            </div>
        <?php endif; ?>

        <?php if ($totalRejectedTD > 0): ?>
            <div class="bg-red-50 border border-red-300 rounded-lg px-5 py-4 mb-5 flex items-center gap-3.5 flex-wrap">
                <i class="fas fa-times-circle text-red-600 text-xl flex-shrink-0"></i>
                <div>
                    <div class="font-bold text-[14px] text-red-800">
                        <?= $totalRejectedTD ?> TD area<?= $totalRejectedTD > 1 ? 's/units' : '/unit' ?> rejected across your clients
                    </div>
                    <div class="text-[12px] text-red-700 mt-0.5">
                        Look for the <strong>red badge</strong> on each client card below. Open the client to review and resubmit.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($clients)): ?>
            <div class="bg-white border border-line rounded-[10px] p-6">
                <div class="text-center py-10 text-muted">
                    <i class="fas fa-inbox text-3xl mb-3 block"></i>
                    <p class="text-[15px] font-semibold text-ink">No Clients Assigned</p>
                    <p class="text-[12.5px] mt-1">You have no clients assigned for technical design work yet.</p>
                </div>
            </div>
        <?php else: ?>

            <!-- ══ ACTIVE SECTION ══ -->
            <div id="section-active" class="flex flex-col gap-3">
                <?php if ($canViewAll && !empty($myClients)): ?>
                    <div class="section-heading flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.6px] text-muted mt-1" data-section="mine">
                        <i class="fas fa-user-check"></i> My Clients <span class="text-[11px]">(<?= count($myClients) ?>)</span>
                        <span class="flex-1 h-px bg-line"></span>
                    </div>
                    <?php foreach ($myClients as $c):
                        $hasIntake = true;
                        $cardPending = getTDPendingForClient($conn, $admin_id, $c['id']);
                        $rejected = !empty($c['rejected_td_layouts']) && $c['rejected_td_layouts'] > 0;
                        $borderClass = $rejected ? 'border-red-400' : ($cardPending > 0 ? 'border-amber-400' : '');
                    ?>
                        <div class="client-card bg-white border border-line <?= $borderClass ?> border-l-4 border-l-amber-400 rounded-lg cursor-pointer hover:border-ink transition"
                            data-filter-tags="active mine <?= $hasIntake ? 'intake-done' : 'intake-pending' ?>"
                            data-search="<?= strtolower($c['clientname'] . ' ' . $c['nameproject'] . ' ' . $c['reference_number']) ?>"
                            onclick="window.location.href='<?= BASE_URL ?>td-layout?client_id=<?= $c['id'] ?>'">
                            <div class="flex items-stretch">
                                <div class="flex-1 min-w-0 p-4">
                                    <div class="flex justify-between items-start gap-2.5 mb-1 flex-wrap">
                                        <div>
                                            <div class="text-[16px] font-bold flex items-center gap-1.5 flex-wrap">
                                                <?= htmlspecialchars($c['clientname']) ?>
                                                <span class="text-[10px] bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full font-bold">
                                                    <i class="fas fa-star text-[8px]"></i> Mine
                                                </span>
                                            </div>
                                            <div class="text-[12px] text-soft"><?= htmlspecialchars($c['nameproject']) ?></div>
                                            <div class="text-[11px] text-muted font-mono mt-0.5"><i class="fas fa-hashtag text-[9px]"></i> <?= htmlspecialchars($c['reference_number']) ?></div>
                                        </div>
                                        <div>
                                            <?php if ($hasIntake): ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border bg-emerald-100 text-emerald-800 border-emerald-300"><i class="fas fa-check-circle"></i> Started</span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border bg-amber-100 text-amber-800 border-amber-300"><i class="fas fa-hourglass-half"></i> Not Started</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php renderCardMeta($c); ?>
                                </div>
                                <div class="hidden sm:flex items-center px-4 border-l border-line flex-shrink-0">
                                    <a href="<?= BASE_URL ?>td-layout?client_id=<?= $c['id'] ?>"
                                        class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-4 py-2 text-[12px] font-semibold hover:opacity-90 transition whitespace-nowrap"
                                        onclick="event.stopPropagation()">
                                        <i class="fas fa-tools"></i> Open
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($canViewAll && !empty($otherClients)): ?>
                    <div class="section-heading flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.6px] text-muted mt-1" data-section="others">
                        <i class="fas fa-users"></i> Team's Clients <span class="text-[11px]">(<?= count($otherClients) ?>)</span>
                        <span class="flex-1 h-px bg-line"></span>
                    </div>
                <?php endif; ?>

                <?php
                $activeListToRender = ($canViewAll) ? $otherClients : ($isManager ? $activeClients : $myClients);
                foreach ($activeListToRender as $c):
                    $hasIntake = true;
                    $filterTag = $canViewAll ? 'others' : 'mine';
                    $cardPending = getTDPendingForClient($conn, $admin_id, $c['id']);
                    $rejected = !empty($c['rejected_td_layouts']) && $c['rejected_td_layouts'] > 0;
                    $borderClass = $rejected ? 'border-red-400' : ($cardPending > 0 ? 'border-amber-400' : '');
                    $stripe = $hasIntake ? 'border-l-emerald-400' : 'border-l-line';
                ?>
                    <div class="client-card bg-white border border-line <?= $borderClass ?> <?= $stripe ?> border-l-4 rounded-lg cursor-pointer hover:border-ink transition"
                        data-filter-tags="active <?= $filterTag ?> <?= $hasIntake ? 'intake-done' : 'intake-pending' ?>"
                        data-search="<?= strtolower($c['clientname'] . ' ' . $c['nameproject'] . ' ' . $c['reference_number']) ?>"
                        onclick="window.location.href='<?= BASE_URL ?>td-layout?client_id=<?= $c['id'] ?>'">
                        <div class="flex items-stretch">
                            <div class="flex-1 min-w-0 p-4">
                                <div class="flex justify-between items-start gap-2.5 mb-1 flex-wrap">
                                    <div>
                                        <div class="text-[16px] font-bold"><?= htmlspecialchars($c['clientname']) ?></div>
                                        <div class="text-[12px] text-soft"><?= htmlspecialchars($c['nameproject']) ?></div>
                                        <div class="text-[11px] text-muted font-mono mt-0.5"><i class="fas fa-hashtag text-[9px]"></i> <?= htmlspecialchars($c['reference_number']) ?></div>
                                    </div>
                                    <div>
                                        <?php if ($hasIntake): ?>
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border bg-emerald-100 text-emerald-800 border-emerald-300"><i class="fas fa-check-circle"></i> Started</span>
                                        <?php else: ?>
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border bg-amber-100 text-amber-800 border-amber-300"><i class="fas fa-hourglass-half"></i> Not Started</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php renderCardMeta($c); ?>
                            </div>
                            <div class="hidden sm:flex items-center px-4 border-l border-line flex-shrink-0">
                                <a href="<?= BASE_URL ?>td-layout?client_id=<?= $c['id'] ?>"
                                    class="inline-flex items-center gap-2 bg-ink text-white rounded-lg px-4 py-2 text-[12px] font-semibold hover:opacity-90 transition whitespace-nowrap"
                                    onclick="event.stopPropagation()">
                                    <i class="fas fa-tools"></i> Open
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($activeClients)): ?>
                    <div class="bg-white border border-line rounded-[10px] p-6">
                        <div class="text-center py-10 text-muted">
                            <i class="fas fa-check-double text-3xl mb-3 block"></i>
                            <p class="text-[15px] font-semibold text-ink">All caught up!</p>
                            <p class="text-[12.5px] mt-1">No active clients. Check the Done tab.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div><!-- /section-active -->

            <!-- ══ DONE SECTION (hidden by default) ══ -->
            <div id="section-done" class="hidden flex-col gap-3">
                <?php if (!empty($doneClients)): ?>
                    <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.6px] text-muted mt-1">
                        <i class="fas fa-flag-checkered"></i> Completed <span class="text-[11px]">(<?= count($doneClients) ?>)</span>
                        <span class="flex-1 h-px bg-line"></span>
                    </div>
                    <?php foreach ($doneClients as $c):
                        $isMine = $c['_is_mine'];
                    ?>
                        <div class="client-card bg-white border border-line border-l-4 border-l-emerald-400 rounded-lg cursor-pointer opacity-90 hover:opacity-100 hover:border-ink transition"
                            data-filter-tags="done <?= $isMine ? 'mine' : 'others' ?>"
                            data-search="<?= strtolower($c['clientname'] . ' ' . $c['nameproject'] . ' ' . $c['reference_number']) ?>"
                            onclick="window.location.href='<?= BASE_URL ?>td-layout?client_id=<?= $c['id'] ?>'">
                            <div class="flex items-stretch">
                                <div class="flex-1 min-w-0 p-4">
                                    <div class="flex justify-between items-start gap-2.5 mb-1 flex-wrap">
                                        <div>
                                            <div class="text-[16px] font-bold flex items-center gap-1.5 flex-wrap">
                                                <?= htmlspecialchars($c['clientname']) ?>
                                                <?php if ($isMine && $canViewAll): ?>
                                                    <span class="text-[10px] bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full font-bold">
                                                        <i class="fas fa-star text-[8px]"></i> Mine
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-[12px] text-soft"><?= htmlspecialchars($c['nameproject']) ?></div>
                                            <div class="text-[11px] text-muted font-mono mt-0.5"><i class="fas fa-hashtag text-[9px]"></i> <?= htmlspecialchars($c['reference_number']) ?></div>
                                        </div>
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border bg-teal-100 text-teal-800 border-teal-300"><i class="fas fa-flag-checkered"></i> Done</span>
                                    </div>
                                    <?php renderCardMeta($c); ?>
                                </div>
                                <div class="hidden sm:flex items-center px-4 border-l border-line flex-shrink-0">
                                    <a href="<?= BASE_URL ?>td-layout?client_id=<?= $c['id'] ?>"
                                        class="inline-flex items-center gap-2 bg-teal-600 text-white rounded-lg px-4 py-2 text-[12px] font-semibold hover:opacity-90 transition whitespace-nowrap"
                                        onclick="event.stopPropagation()">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="bg-white border border-line rounded-[10px] p-6">
                        <div class="text-center py-10 text-muted">
                            <i class="fas fa-flag-checkered text-3xl mb-3 block"></i>
                            <p class="text-[15px] font-semibold text-ink">No Completed Clients Yet</p>
                            <p class="text-[12.5px] mt-1">Clients whose layout stage is Done will appear here.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div><!-- /section-done -->

        <?php endif; ?>
    </div>

    <script>
        let currentFilter = 'active';
        let currentSearch = '';

        function setFilter(filter, btn) {
            currentFilter = filter;
            document.querySelectorAll('.filter-tab').forEach(b => {
                b.classList.remove('bg-ink', 'text-white', 'border-ink');
                b.classList.add('bg-white', 'text-soft', 'border-line');
                const cnt = b.querySelector('span');
                if (cnt) { cnt.classList.remove('bg-white/20'); cnt.classList.add('bg-[#F5F5F5]', 'text-ink'); }
            });
            btn.classList.remove('bg-white', 'text-soft', 'border-line');
            btn.classList.add('bg-ink', 'text-white', 'border-ink');
            const activeCnt = btn.querySelector('span');
            if (activeCnt) { activeCnt.classList.remove('bg-[#F5F5F5]', 'text-ink'); activeCnt.classList.add('bg-white/20'); }
            applyFilters();
        }

        document.getElementById('searchInput').addEventListener('input', function () {
            currentSearch = this.value.toLowerCase().trim();
            applyFilters();
        });

        function applyFilters() {
            const isDoneView = (currentFilter === 'done');
            const activeSection = document.getElementById('section-active');
            const doneSection = document.getElementById('section-done');

            activeSection.classList.toggle('hidden', isDoneView);
            activeSection.classList.toggle('flex', !isDoneView);
            doneSection.classList.toggle('hidden', !isDoneView);
            doneSection.classList.toggle('flex', isDoneView);

            const sectionId = isDoneView ? 'section-done' : 'section-active';

            document.querySelectorAll('#' + sectionId + ' .client-card').forEach(card => {
                const tags = card.getAttribute('data-filter-tags') || '';
                const search = card.getAttribute('data-search') || '';

                const matchFilter = (currentFilter === 'active' || currentFilter === 'done')
                    ? true
                    : tags.includes(currentFilter);
                const matchSearch = !currentSearch || search.includes(currentSearch);

                card.style.display = (matchFilter && matchSearch) ? 'block' : 'none';
            });

            document.querySelectorAll('#' + sectionId + ' .section-heading[data-section]').forEach(h => {
                const sec = h.getAttribute('data-section');
                const anyVisible = Array.from(
                    document.querySelectorAll('#' + sectionId + ' .client-card[data-filter-tags*="' + sec + '"]')
                ).some(c => c.style.display !== 'none');
                h.style.display = anyVisible ? 'flex' : 'none';
            });
        }
    </script>
</body>

</html>