<?php
// designer_clients_list.php
include $includes ['mainbody'];
date_default_timezone_set('Asia/Manila');


$admin_id = $_SESSION['admin_id'];

$meStmt = $conn->prepare("SELECT full_name, role FROM account WHERE id = ?");
$meStmt->bind_param("i", $admin_id);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();

if ($me['role'] !== 'designer') {
    die("Access denied: This page is for designers only.");
}

// Fetch all unique clients assigned to this designer via site_visit
$clientsStmt = $conn->prepare("
    SELECT DISTINCT
        ui.id AS client_id,
        ui.clientname,
        ui.nameproject,
        ui.reference_number,
        ui.status,
        ui.business_type,
        ui.contact,
        ui.email,
        ui.address,
        ui.total_project_cost,
        ui.remaining_balance,
        (SELECT COUNT(*) FROM site_visit sv WHERE sv.client_id = ui.id AND (sv.designer1_id = ? OR sv.designer2_id = ? OR sv.original_designer1_id = ? OR sv.original_designer2_id = ?)) AS total_visits,
        (SELECT COUNT(*) FROM site_visit sv WHERE sv.client_id = ui.id AND (sv.designer1_id = ? OR sv.designer2_id = ? OR sv.original_designer1_id = ? OR sv.original_designer2_id = ?) AND sv.status = 'Done') AS done_visits,
        (SELECT COUNT(*) FROM site_visit sv WHERE sv.client_id = ui.id AND (sv.designer1_id = ? OR sv.designer2_id = ? OR sv.original_designer1_id = ? OR sv.original_designer2_id = ?) AND sv.status = 'Pending') AS pending_visits,
        (SELECT COUNT(*) FROM site_visit sv WHERE sv.client_id = ui.id AND (sv.designer1_id = ? OR sv.designer2_id = ? OR sv.original_designer1_id = ? OR sv.original_designer2_id = ?) AND sv.status = 'Ongoing') AS ongoing_visits,
        (SELECT a.full_name FROM site_visit sv2 JOIN account a ON (
            (sv2.original_designer1_id = ? AND a.id = sv2.designer1_id AND sv2.designer1_id != ?) OR
            (sv2.original_designer2_id = ? AND a.id = sv2.designer2_id AND sv2.designer2_id != ?)
        ) WHERE sv2.client_id = ui.id LIMIT 1) AS replaced_by_name,
        (SELECT a.full_name FROM site_visit sv3 JOIN account a ON (
            (sv3.designer1_id = ? AND a.id = sv3.original_designer1_id AND sv3.original_designer1_id IS NOT NULL AND sv3.original_designer1_id != ?) OR
            (sv3.designer2_id = ? AND a.id = sv3.original_designer2_id AND sv3.original_designer2_id IS NOT NULL AND sv3.original_designer2_id != ?)
        ) WHERE sv3.client_id = ui.id LIMIT 1) AS took_over_from_name
    FROM user_info ui
    JOIN site_visit sv ON sv.client_id = ui.id
    WHERE sv.designer1_id = ? OR sv.designer2_id = ?
    OR sv.original_designer1_id = ? OR sv.original_designer2_id = ?
    ORDER BY ui.clientname ASC
");
$clientsStmt->bind_param(
    "iiiiiiiiiiiiiiiiiiiiiiiiiiii",
    $admin_id, $admin_id, $admin_id, $admin_id,
    $admin_id, $admin_id, $admin_id, $admin_id,
    $admin_id, $admin_id, $admin_id, $admin_id,
    $admin_id, $admin_id, $admin_id, $admin_id,
    $admin_id, $admin_id, $admin_id, $admin_id,
    $admin_id, $admin_id, $admin_id, $admin_id,
    $admin_id, $admin_id, $admin_id, $admin_id
);
$clientsStmt->execute();
$clients = $clientsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totalClients = count($clients);
$totalPending = array_sum(array_column($clients, 'pending_visits'));
$totalOngoing = array_sum(array_column($clients, 'ongoing_visits'));
$doneClientCount   = count(array_filter($clients, fn($c) => $c['done_visits'] == $c['total_visits'] && $c['total_visits'] > 0));
$activeClientCount = count($clients) - $doneClientCount;
$reassignedClientCount = count(array_filter($clients, fn($c) => !empty($c['replaced_by_name']) || !empty($c['took_over_from_name'])));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Clients — Designer</title>
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

        <!-- ── Page Header ── -->
        <div class="bg-white border border-line rounded-[10px] p-6 mb-5">
            <div class="text-[11px] font-semibold tracking-[1.5px] uppercase text-soft mb-2">
                <i class="fas fa-users"></i> My Clients
            </div>
            <h1 class="text-2xl font-bold tracking-[-0.01em]">Welcome, <?= htmlspecialchars($me['full_name']) ?></h1>
            <p class="text-[13.5px] text-soft mt-1">Tap a client to view details and site visits.</p>

            <!-- Stats -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-5">
                <div class="bg-[#F5F5F5] border border-line rounded-lg px-4 py-3.5">
                    <div class="text-2xl font-bold"><?= $totalClients ?></div>
                    <div class="text-[11px] font-semibold uppercase tracking-[0.5px] text-muted mt-0.5">Total Clients</div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg px-4 py-3.5">
                    <div class="text-2xl font-bold text-amber-600"><?= $totalPending ?></div>
                    <div class="text-[11px] font-semibold uppercase tracking-[0.5px] text-muted mt-0.5">Pending Visits</div>
                </div>
                <div class="bg-[#F5F5F5] border border-line rounded-lg px-4 py-3.5">
                    <div class="text-2xl font-bold text-blue-600"><?= $totalOngoing ?></div>
                    <div class="text-[11px] font-semibold uppercase tracking-[0.5px] text-muted mt-0.5">Ongoing Visits</div>
                </div>
            </div>
        </div>

        <!-- ── Filter Tabs ── -->
        <div class="flex items-center gap-2.5 mb-5 flex-wrap">
            <button type="button" id="btn-active" onclick="setFilter('active')"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-[13px] font-semibold transition border">
                <i class="fas fa-spinner"></i> Active
                <span id="badge-active" class="px-2 py-0.5 rounded-full text-[11px] font-bold"><?= $activeClientCount ?></span>
            </button>
            <button type="button" id="btn-done" onclick="setFilter('done')"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-[13px] font-semibold transition border">
                <i class="fas fa-check-double"></i> Completed
                <span id="badge-done" class="px-2 py-0.5 rounded-full text-[11px] font-bold"><?= $doneClientCount ?></span>
            </button>
            <?php if ($reassignedClientCount > 0): ?>
                <button type="button" id="btn-reassigned" onclick="setFilter('reassigned')"
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-[13px] font-semibold transition border">
                    <i class="fas fa-exchange-alt"></i> Reassigned
                    <span id="badge-reassigned" class="px-2 py-0.5 rounded-full text-[11px] font-bold"><?= $reassignedClientCount ?></span>
                </button>
            <?php endif; ?>
        </div>

        <!-- ── Search ── -->
        <div class="bg-white border border-line rounded-lg px-4 py-2.5 mb-5 flex items-center gap-2.5">
            <i class="fas fa-search text-muted"></i>
            <input type="text" id="searchInput" placeholder="Search client name, project, or reference..."
                oninput="filterClients()"
                class="w-full border-none outline-none text-sm bg-transparent">
        </div>

        <!-- ── Client List ── -->
        <div id="clientList" class="flex flex-col gap-4">
            <?php if (empty($clients)): ?>
                <div class="bg-white border border-line rounded-[10px] p-6">
                    <div class="text-center py-10 text-muted">
                        <i class="fas fa-users-slash text-3xl mb-3 block"></i>
                        No clients assigned yet. Clients will appear here once site visits are assigned to you.
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($clients as $client):
                    $isDone = ($client['done_visits'] == $client['total_visits'] && $client['total_visits'] > 0);

                    $stripeClass = 'border-l-line';
                    if ($client['ongoing_visits'] > 0) {
                        $stripeClass = 'border-l-blue-400';
                    } elseif ($client['pending_visits'] > 0) {
                        $stripeClass = 'border-l-amber-400';
                    } elseif ($isDone) {
                        $stripeClass = 'border-l-emerald-400';
                    }

                    $statusBadgeClass = strtolower($client['status']) === 'new client'
                        ? 'bg-amber-100 text-amber-800 border-amber-300'
                        : 'bg-blue-100 text-blue-800 border-blue-300';

                    // Reassignment detection: was this client handed to/from another designer?
                    $tookOverFrom = $client['took_over_from_name'] ?? null;   // I am now assigned, replacing this person
                    $replacedByOther = $client['replaced_by_name'] ?? null;   // I was replaced by this person
                    $isReassigned = !empty($tookOverFrom) || !empty($replacedByOther);
                    if (!empty($tookOverFrom)) {
                        $stripeClass = 'border-l-purple-400';
                    } elseif (!empty($replacedByOther)) {
                        $stripeClass = 'border-l-gray-300';
                    }
                    ?>
                    <a href="designer-client-detail?client_id=<?= $client['client_id'] ?>"
                        class="client-card block bg-white border border-line <?= $stripeClass ?> border-l-4 rounded-lg p-[18px] hover:border-ink transition"
                        data-name="<?= htmlspecialchars(strtolower($client['clientname']), ENT_QUOTES) ?>"
                        data-project="<?= htmlspecialchars(strtolower($client['nameproject']), ENT_QUOTES) ?>"
                        data-ref="<?= htmlspecialchars(strtolower($client['reference_number']), ENT_QUOTES) ?>"
                        data-status="<?= $isDone ? 'done' : 'active' ?>"
                        data-reassigned="<?= $isReassigned ? '1' : '0' ?>">

                        <div class="flex justify-between items-start gap-3 flex-wrap">
                            <div class="flex-1 min-w-[220px]">
                                <div class="flex items-center gap-2 flex-wrap mb-1">
                                    <span class="text-[14px] font-semibold"><?= htmlspecialchars($client['clientname']) ?></span>
                                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase border <?= $statusBadgeClass ?>">
                                        <?= htmlspecialchars($client['status']) ?>
                                    </span>
                                    <?php if (!empty($tookOverFrom)): ?>
                                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase border bg-purple-100 text-purple-800 border-purple-300">
                                            <i class="fas fa-exchange-alt"></i> Switched from <?= htmlspecialchars($tookOverFrom) ?>
                                        </span>
                                    <?php elseif (!empty($replacedByOther)): ?>
                                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase border bg-gray-100 text-gray-600 border-gray-300">
                                            <i class="fas fa-exchange-alt"></i> Switched to <?= htmlspecialchars($replacedByOther) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[13px] text-soft">
                                    <?= htmlspecialchars($client['nameproject']) ?>
                                    &nbsp;•&nbsp;
                                    <span class="font-mono text-muted"><?= htmlspecialchars($client['reference_number']) ?></span>
                                </div>

                                <div class="flex items-center gap-4 mt-2.5 flex-wrap text-[12px] text-soft">
                                    <span class="flex items-center gap-1.5">
                                        <i class="fas fa-building text-muted"></i>
                                        <?= $client['business_type'] === 'Non-Project' ? 'Individual' : htmlspecialchars($client['business_type']) ?>
                                    </span>
                                    <?php if ($client['contact']): ?>
                                        <span class="flex items-center gap-1.5">
                                            <i class="fas fa-phone text-muted"></i> <?= htmlspecialchars($client['contact']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="flex items-center gap-1.5">
                                        <i class="fas fa-calendar-check text-muted"></i>
                                        <?= $client['total_visits'] ?> visit<?= $client['total_visits'] != 1 ? 's' : '' ?>
                                    </span>
                                </div>

                                <div class="flex items-center gap-2 mt-2.5 flex-wrap">
                                    <?php if ($client['pending_visits'] > 0): ?>
                                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase border bg-amber-100 text-amber-800 border-amber-300">
                                            <i class="fas fa-clock"></i> <?= $client['pending_visits'] ?> Pending
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($client['ongoing_visits'] > 0): ?>
                                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase border bg-blue-100 text-blue-800 border-blue-300">
                                            <i class="fas fa-spinner"></i> <?= $client['ongoing_visits'] ?> Ongoing
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($client['done_visits'] > 0): ?>
                                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase border bg-emerald-100 text-emerald-800 border-emerald-300">
                                            <i class="fas fa-check"></i> <?= $client['done_visits'] ?> Done
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <i class="fas fa-chevron-right text-line text-lg flex-shrink-0 mt-1"></i>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Empty filter/search state (JS-controlled) -->
            <div id="emptyFilterMsg" class="hidden bg-white border border-line rounded-[10px] p-6">
                <div class="text-center py-10 text-muted">
                    <i class="fas fa-search text-3xl mb-3 block"></i>
                    No clients found.
                </div>
            </div>
        </div>

    </div>

    <script>
        let activeFilter = 'active';

        function styleTab(btn, active) {
            if (active) {
                btn.classList.add('bg-ink', 'text-white', 'border-ink');
                btn.classList.remove('bg-white', 'border-line', 'text-soft');
            } else {
                btn.classList.remove('bg-ink', 'text-white', 'border-ink');
                btn.classList.add('bg-white', 'border-line', 'text-soft');
            }
        }

        function styleBadge(badge, active) {
            badge.classList.remove('bg-white/20', 'text-white', 'bg-[#F5F5F5]', 'text-ink');
            if (active) {
                badge.classList.add('bg-white/20', 'text-white');
            } else {
                badge.classList.add('bg-[#F5F5F5]', 'text-ink');
            }
        }

        function setFilter(filter) {
            activeFilter = filter;

            const btnActive = document.getElementById('btn-active');
            const btnDone = document.getElementById('btn-done');
            const btnReassigned = document.getElementById('btn-reassigned');
            const badgeActive = document.getElementById('badge-active');
            const badgeDone = document.getElementById('badge-done');
            const badgeReassigned = document.getElementById('badge-reassigned');

            styleTab(btnActive, filter === 'active');
            styleTab(btnDone, filter === 'done');
            styleBadge(badgeActive, filter === 'active');
            styleBadge(badgeDone, filter === 'done');
            if (btnReassigned) {
                styleTab(btnReassigned, filter === 'reassigned');
                styleBadge(badgeReassigned, filter === 'reassigned');
            }

            filterClients();
        }

        function filterClients() {
            const q = document.getElementById('searchInput').value.toLowerCase();
            let visible = 0;
            document.querySelectorAll('.client-card').forEach(card => {
                const name = card.dataset.name || '';
                const project = card.dataset.project || '';
                const ref = card.dataset.ref || '';
                const status = card.dataset.status || 'active';
                const reassigned = card.dataset.reassigned === '1';
                const matchSearch = !q || name.includes(q) || project.includes(q) || ref.includes(q);
                const matchFilter = activeFilter === 'reassigned' ? reassigned : status === activeFilter;
                const show = matchSearch && matchFilter;
                card.style.display = show ? 'block' : 'none';
                if (show) visible++;
            });

            document.getElementById('emptyFilterMsg').classList.toggle('hidden', visible !== 0);
        }

        document.addEventListener('DOMContentLoaded', () => setFilter('active'));
    </script>
</body>

</html>