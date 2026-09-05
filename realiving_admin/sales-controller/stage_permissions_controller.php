<?php
// stage_permissions_controller.php
include $includes ['mainbody'];
require_role(['sales', 'general_manager', 'operational_manager', 'super_admin']); // Only superadmin can manage permissions

// Fetch all admins with their clients
$adminsStmt = $conn->prepare("
    SELECT 
        a.id,
        a.full_name,
        a.email,
        a.role,
        COUNT(DISTINCT u.id) as client_count
    FROM account a
    LEFT JOIN user_info u ON a.id = u.accountaid_fk
    GROUP BY a.id, a.full_name, a.email, a.role
    ORDER BY a.full_name
");
$adminsStmt->execute();
$admins = $adminsStmt->get_result();
$adminRows = $admins->fetch_all(MYSQLI_ASSOC);
$totalAdmins = count($adminRows);

// Define all stages
$all_stages = [
    'Rough Estimation',
    'Site Visit',
    '2D / 3D Layout',
    'Reference',
    'Samples Submitted TDS/SDS',
    'Quotation',
    'Internal P.O to Accounting',
    'Downpayment',
    'Cuttinglist',
    'Bill of Materials (BOM)',
    'Purchase Order (Submit to accounting)',
    'Accounting (Order Processing)',
    'Production Data Submittals',
    'Fabrication',
    'Delivery',
    'Installation',
    'BILLING',
    'Handover'
];
$totalStages = count($all_stages);

// Fetch stages already assigned to roles
$roleAssignedStages = [];
$roleStagesStmt = $conn->prepare("
    SELECT DISTINCT stage_name, role 
    FROM role_stage_permissions 
    WHERE can_update = 1
");
$roleStagesStmt->execute();
$roleStagesResult = $roleStagesStmt->get_result();
while ($row = $roleStagesResult->fetch_assoc()) {
    $roleAssignedStages[$row['stage_name']] = $row['role'];
}

// Distinct roles present, for the filter dropdown
$distinctRoles = [];
foreach ($adminRows as $a) {
    if (!in_array($a['role'], $distinctRoles))
        $distinctRoles[] = $a['role'];
}
sort($distinctRoles);

function initials($name)
{
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        if ($p !== '')
            $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out ?: '?';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stage Permissions Controller</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
        crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        @keyframes admFade {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .adm-fade {
            animation: admFade .35s ease both;
        }

        @keyframes popIn {
            from {
                transform: scale(.96);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        #permissionsModal.flex .modal-box {
            animation: popIn .2s ease both;
        }

        @keyframes toastIn {
            from {
                transform: translateX(20px);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        #toast.show {
            animation: toastIn .25s ease both;
        }

        @media (prefers-reduced-motion: reduce) {

            .adm-fade,
            #permissionsModal.flex .modal-box,
            #toast.show {
                animation: none;
            }
        }
    </style>
</head>

<body class="min-h-screen bg-[#F5F5F5] text-[#0B0B0B]">
    <div class="max-w-6xl mx-auto w-full px-4 sm:px-6 lg:px-8 pt-10 pb-16">

        <!-- Header -->
        <div class="bg-[#0B0B0B] rounded-xl p-6 sm:p-7 text-white mb-6 adm-fade">
            <div class="flex items-start justify-between flex-wrap gap-4">
                <div class="flex items-center gap-4">
                    <div
                        class="w-11 h-11 rounded-[9px] bg-white/10 border border-white/15 flex items-center justify-center text-[17px] flex-shrink-0">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div>
                        <div class="text-[11px] font-semibold uppercase tracking-[1.5px] text-white/50 mb-0.5">Access
                            Control</div>
                        <div class="text-[19px] font-bold tracking-tight">Stage Permissions Controller</div>
                        <div class="text-[12.5px] text-white/60 mt-0.5">Manage which project stages each admin can
                            update for their clients.</div>
                    </div>
                </div>
                <div class="flex gap-6">
                    <div class="text-right">
                        <div class="text-xl font-bold font-mono"><?= $totalAdmins ?></div>
                        <div class="text-[10px] uppercase tracking-wide text-white/45">Admins</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xl font-bold font-mono"><?= $totalStages ?></div>
                        <div class="text-[10px] uppercase tracking-wide text-white/45">Stages</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search + filter bar -->
        <div class="bg-white border border-[#E2E2E2] rounded-[10px] p-3.5 mb-6 flex flex-col sm:flex-row gap-3 adm-fade">
            <div class="relative flex-1">
                <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-[#9A9A9A] text-sm"></i>
                <input type="text" id="searchInput" placeholder="Search admins by name or email..."
                    class="w-full pl-10 pr-4 py-2.5 border border-[#E2E2E2] rounded-md text-sm focus:outline-none focus:border-[#0B0B0B] transition-colors">
            </div>
            <select id="roleFilter"
                class="border border-[#E2E2E2] rounded-md text-sm px-3.5 py-2.5 focus:outline-none focus:border-[#0B0B0B] transition-colors bg-white">
                <option value="">All roles</option>
                <?php foreach ($distinctRoles as $r): ?>
                    <option value="<?= htmlspecialchars(strtolower($r)) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $r))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Admins grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" id="adminsGrid">
            <?php foreach ($adminRows as $admin): ?>
                <div class="admin-card bg-white border border-[#E2E2E2] rounded-[10px] p-5 hover:border-[#0B0B0B] hover:shadow-[0_10px_26px_-16px_rgba(11,11,11,0.25)] hover:-translate-y-0.5 transition-all adm-fade"
                    data-search="<?= strtolower($admin['full_name'] . ' ' . $admin['email']) ?>"
                    data-role="<?= strtolower($admin['role']) ?>">

                    <div class="flex items-start gap-3 mb-4">
                        <div
                            class="w-11 h-11 rounded-full bg-[#F5F5F5] border border-[#E2E2E2] flex items-center justify-center text-[13px] font-bold text-[#6B6B6B] flex-shrink-0">
                            <?= htmlspecialchars(initials($admin['full_name'])) ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-[15px] font-semibold truncate"><?= htmlspecialchars($admin['full_name']) ?></h3>
                            <div class="text-xs text-[#9A9A9A] truncate"><?= htmlspecialchars($admin['email']) ?></div>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <div class="text-lg font-bold font-mono leading-none"><?= $admin['client_count'] ?></div>
                            <div class="text-[9px] uppercase tracking-wide text-[#9A9A9A] mt-0.5">Clients</div>
                        </div>
                    </div>

                    <span
                        class="inline-block px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide bg-[#F5F5F5] text-[#6B6B6B] border border-[#E2E2E2] mb-4">
                        <i class="fas fa-user-tag"></i> <?= htmlspecialchars(str_replace('_', ' ', $admin['role'])) ?>
                    </span>

                    <button
                        class="w-full inline-flex items-center justify-center gap-2 bg-[#0B0B0B] text-white py-2.5 rounded-md font-semibold text-[13px] hover:bg-[#2a2a2a] transition-colors"
                        data-id="<?= $admin['id'] ?>" data-name="<?= htmlspecialchars($admin['full_name'], ENT_QUOTES) ?>"
                        data-email="<?= htmlspecialchars($admin['email'], ENT_QUOTES) ?>"
                        data-role="<?= htmlspecialchars($admin['role'], ENT_QUOTES) ?>" data-count="<?= $admin['client_count'] ?>"
                        onclick="openPermissionsModal(this.dataset.id, this.dataset.name, this.dataset.email, this.dataset.role, this.dataset.count)">
                        <i class="fas fa-cog"></i>
                        Manage Stage Permissions
                    </button>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="noResults" class="hidden text-center py-14 px-5 bg-white border-2 border-dashed border-[#E2E2E2] rounded-[10px] mt-4">
            <i class="fas fa-user-slash text-3xl text-[#E2E2E2] mb-2.5 block"></i>
            <div class="text-sm font-semibold mb-1">No admins match your search</div>
            <div class="text-xs text-[#9A9A9A]">Try a different name, email, or role filter.</div>
        </div>
    </div>

    <!-- Permissions Modal -->
    <div id="permissionsModal" class="hidden fixed inset-0 bg-black/50 z-[1000] items-center justify-center p-4">
        <div class="modal-box bg-white rounded-2xl shadow-2xl w-full max-w-[900px] max-h-[92vh] flex flex-col overflow-hidden">

            <!-- Header -->
            <div class="bg-[#0B0B0B] text-white px-6 sm:px-7 py-5 flex justify-between items-center flex-shrink-0">
                <h2 class="text-lg font-bold flex items-center gap-2.5">
                    <i class="fas fa-user-shield"></i>
                    <span id="modalTitle">Stage Permissions</span>
                </h2>
                <button onclick="closeModal()"
                    class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 border-none text-white text-lg flex items-center justify-center transition-all hover:rotate-90 duration-200">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="px-6 sm:px-7 py-6 overflow-y-auto flex-1">
                <!-- Admin info -->
                <div class="bg-[#F5F5F5] border border-[#E2E2E2] rounded-[10px] p-5 mb-6 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
                    <div class="flex items-center justify-between sm:justify-start sm:gap-3 py-1.5 text-sm">
                        <span class="text-[#9A9A9A] font-medium">Name</span>
                        <span class="font-semibold" id="modalAdminName"></span>
                    </div>
                    <div class="flex items-center justify-between sm:justify-start sm:gap-3 py-1.5 text-sm">
                        <span class="text-[#9A9A9A] font-medium">Email</span>
                        <span class="font-semibold" id="modalAdminEmail"></span>
                    </div>
                    <div class="flex items-center justify-between sm:justify-start sm:gap-3 py-1.5 text-sm">
                        <span class="text-[#9A9A9A] font-medium">Role</span>
                        <span class="font-semibold" id="modalAdminRole"></span>
                    </div>
                    <div class="flex items-center justify-between sm:justify-start sm:gap-3 py-1.5 text-sm">
                        <span class="text-[#9A9A9A] font-medium">Total Clients</span>
                        <span class="font-semibold" id="modalClientCount"></span>
                    </div>
                </div>

                <!-- Permissions -->
                <div class="flex items-center justify-between flex-wrap gap-2 mb-3.5">
                    <h3 class="text-sm font-bold flex items-center gap-2">
                        <i class="fas fa-tasks text-[#6B6B6B]"></i> Project Stage Permissions
                    </h3>
                    <span id="selectedCounter"
                        class="text-xs font-bold px-2.5 py-1 rounded-full bg-[#F5F5F5] border border-[#E2E2E2] text-[#6B6B6B]">0
                        of <?= $totalStages ?> selected</span>
                </div>

                <div class="flex flex-wrap gap-2 mb-4">
                    <button
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border border-[#E2E2E2] text-[#6B6B6B] hover:border-[#0B0B0B] hover:text-[#0B0B0B] transition-colors"
                        onclick="selectAll()">
                        <i class="fas fa-check-double"></i> Select All
                    </button>
                    <button
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border border-[#E2E2E2] text-[#6B6B6B] hover:border-[#0B0B0B] hover:text-[#0B0B0B] transition-colors"
                        onclick="deselectAll()">
                        <i class="fas fa-times"></i> Deselect All
                    </button>
                    <button
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border border-[#E2E2E2] text-[#6B6B6B] hover:border-[#0B0B0B] hover:text-[#0B0B0B] transition-colors"
                        onclick="selectFirstHalf()">
                        <i class="fas fa-list-ol"></i> First 9 Stages
                    </button>
                    <button
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border border-[#E2E2E2] text-[#6B6B6B] hover:border-[#0B0B0B] hover:text-[#0B0B0B] transition-colors"
                        onclick="selectLastHalf()">
                        <i class="fas fa-list"></i> Last 9 Stages
                    </button>
                </div>

                <div class="grid gap-2" id="stagesList">
                    <!-- Stages will be populated here -->
                </div>
            </div>

            <div class="px-6 sm:px-7 py-4 bg-[#F5F5F5] border-t border-[#E2E2E2] flex justify-end gap-2.5 flex-shrink-0">
                <button onclick="closeModal()"
                    class="bg-white text-[#6B6B6B] px-4 py-2.5 rounded-md font-semibold text-[13px] border border-[#E2E2E2] hover:bg-[#E2E2E2] transition-colors">
                    Cancel
                </button>
                <button id="saveBtn" onclick="savePermissions()"
                    class="inline-flex items-center gap-2 bg-[#0B0B0B] text-white px-4 py-2.5 rounded-md font-semibold text-[13px] hover:bg-[#2a2a2a] transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-save"></i>
                    Save Permissions
                </button>
            </div>
        </div>
    </div>

    <div id="toast"
        class="hidden fixed top-5 right-5 bg-white px-5 py-4 rounded-lg shadow-[0_4px_12px_rgba(0,0,0,.15)] items-center gap-3 z-[2000] border-l-4">
        <i id="toastIcon" class="fas fa-check-circle text-xl text-emerald-500"></i>
        <span id="toastMessage" class="text-sm font-medium">Permissions saved successfully!</span>
    </div>

    <script>
        const allStages = <?= json_encode($all_stages) ?>;
        const totalStages = allStages.length;
        let currentAdminId = null;

        // Search + role filter
        function applyFilters() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const roleTerm = document.getElementById('roleFilter').value.toLowerCase();
            const cards = document.querySelectorAll('.admin-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const searchData = card.getAttribute('data-search');
                const roleData = card.getAttribute('data-role');
                const matchesSearch = searchData.includes(searchTerm);
                const matchesRole = !roleTerm || roleData === roleTerm;
                const visible = matchesSearch && matchesRole;
                card.style.display = visible ? '' : 'none';
                if (visible) visibleCount++;
            });

            document.getElementById('noResults').classList.toggle('hidden', visibleCount !== 0);
        }
        document.getElementById('searchInput').addEventListener('input', applyFilters);
        document.getElementById('roleFilter').addEventListener('change', applyFilters);

        function updateSelectedCounter() {
            const checked = document.querySelectorAll('.stage-permission-item input[type="checkbox"]:checked').length;
            document.getElementById('selectedCounter').textContent = `${checked} of ${totalStages} selected`;
        }

        async function openPermissionsModal(adminId, name, email, role, clientCount) {
            currentAdminId = adminId;

            document.getElementById('modalTitle').textContent = `Stage Permissions — ${name}`;
            document.getElementById('modalAdminName').textContent = name;
            document.getElementById('modalAdminEmail').textContent = email;
            document.getElementById('modalAdminRole').textContent = role;
            document.getElementById('modalClientCount').textContent = clientCount;

            const stagesList = document.getElementById('stagesList');
            stagesList.innerHTML = '<div class="text-center py-8 text-sm text-[#9A9A9A]"><i class="fas fa-spinner fa-spin"></i> Loading permissions...</div>';

            const modal = document.getElementById('permissionsModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            let data;
            try {
                const response = await fetch('<?= BASE_URL ?>get-stage-permissions?admin_id=' + adminId);
                data = await response.json();
            } catch (e) {
                stagesList.innerHTML = '<div class="text-center py-8 text-sm text-red-600"><i class="fas fa-exclamation-circle"></i> Failed to load permissions.</div>';
                return;
            }

            stagesList.innerHTML = '';
            allStages.forEach((stage, index) => {
                const isEnabled = data.permissions.includes(stage);
                const isLockedByRole = data.lockedByRole && data.lockedByRole[stage];

                const stageItem = document.createElement('div');
                stageItem.className = 'stage-permission-item flex items-center justify-between gap-3 border rounded-lg px-4 py-3 transition-colors ' +
                    (isLockedByRole ? 'bg-[#F5F5F5] border-[#E2E2E2] opacity-60' : 'bg-white border-[#E2E2E2] hover:border-[#0B0B0B]');
                stageItem.innerHTML = `
                    <div class="flex items-center gap-3 flex-1 min-w-0">
                        <div class="w-8 h-8 rounded-full bg-[#0B0B0B] text-white flex items-center justify-center font-bold text-xs flex-shrink-0">${index + 1}</div>
                        <div class="text-sm font-medium truncate">
                            ${stage}
                            ${isLockedByRole ? `<span class="ml-2 text-[10px] font-bold text-red-700 bg-red-50 border border-red-200 px-2 py-0.5 rounded-full"><i class="fas fa-lock"></i> Locked by ${isLockedByRole}</span>` : ''}
                        </div>
                    </div>
                    <label class="relative inline-block w-[46px] h-6 flex-shrink-0">
                        <input type="checkbox" class="opacity-0 w-0 h-0 peer"
                               data-stage="${stage}"
                               ${isEnabled ? 'checked' : ''}
                               ${isLockedByRole ? 'disabled' : ''}
                               onchange="updateSelectedCounter()">
                        <span class="absolute inset-0 bg-[#ccc] peer-checked:bg-[#0B0B0B] rounded-full cursor-pointer transition-colors duration-300 peer-disabled:cursor-not-allowed peer-disabled:opacity-60 before:content-[''] before:absolute before:h-[18px] before:w-[18px] before:left-[3px] before:bottom-[3px] before:bg-white before:rounded-full before:transition-transform before:duration-300 peer-checked:before:translate-x-[20px]"></span>
                    </label>
                `;
                stagesList.appendChild(stageItem);
            });

            updateSelectedCounter();
        }

        function closeModal() {
            const modal = document.getElementById('permissionsModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function selectAll() {
            document.querySelectorAll('.stage-permission-item input[type="checkbox"]:not(:disabled)').forEach(cb => {
                cb.checked = true;
            });
            updateSelectedCounter();
        }

        function deselectAll() {
            document.querySelectorAll('.stage-permission-item input[type="checkbox"]:not(:disabled)').forEach(cb => {
                cb.checked = false;
            });
            updateSelectedCounter();
        }

        function selectFirstHalf() {
            const checkboxes = document.querySelectorAll('.stage-permission-item input[type="checkbox"]');
            checkboxes.forEach((cb, index) => {
                if (!cb.disabled) cb.checked = index < 9;
            });
            updateSelectedCounter();
        }

        function selectLastHalf() {
            const checkboxes = document.querySelectorAll('.stage-permission-item input[type="checkbox"]');
            checkboxes.forEach((cb, index) => {
                if (!cb.disabled) cb.checked = index >= 9;
            });
            updateSelectedCounter();
        }

        async function savePermissions() {
            const checkboxes = document.querySelectorAll('.stage-permission-item input[type="checkbox"]:checked');
            const enabledStages = Array.from(checkboxes).map(cb => cb.dataset.stage);

            const btn = document.getElementById('saveBtn');
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            try {
                const response = await fetch('<?= BASE_URL ?>save-stage-permissions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        admin_id: currentAdminId,
                        stages: enabledStages
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showToast('Permissions saved successfully!', 'success');
                    closeModal();
                } else {
                    showToast('Failed to save permissions: ' + result.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('An error occurred', 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            const icon = document.getElementById('toastIcon');

            toastMessage.textContent = message;
            toast.classList.remove('border-emerald-500', 'border-red-500');

            if (type === 'error') {
                icon.className = 'fas fa-exclamation-circle text-xl text-red-500';
                toast.classList.add('border-red-500');
            } else {
                icon.className = 'fas fa-check-circle text-xl text-emerald-500';
                toast.classList.add('border-emerald-500');
            }

            toast.classList.remove('hidden');
            toast.classList.add('flex', 'show');

            setTimeout(() => {
                toast.classList.add('hidden');
                toast.classList.remove('flex', 'show');
            }, 3000);
        }

        // Close modal when clicking outside
        document.getElementById('permissionsModal').addEventListener('click', function (e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>

</html>