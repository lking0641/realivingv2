<?php
// role_permissions_controller.php
include $includes['mainbody'];
require_role(['sales', 'general_manager', 'operational_manager', 'super_admin']); // Only superadmin can manage role permissions

$current_admin_id = $_SESSION['admin_id'];

// Fetch current admin info
$adminStmt = $conn->prepare("SELECT full_name FROM account WHERE id = ?");
$adminStmt->bind_param("i", $current_admin_id);
$adminStmt->execute();
$currentAdmin = $adminStmt->get_result()->fetch_assoc();

// Define roles (excluding sales as per requirement)
$roles = [
    'general_manager' => 'General Manager',
    'operational_manager' => 'Operational Manager',
    'designer' => 'Designer',
    'technical_designer' => 'Technical Designer',
    'accounting' => 'Accounting',
    'project_coordinator' => 'Project Coordinator'
];

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

// Fetch stages already assigned to individual sales users
$salesAssignedStages = [];
$salesStagesStmt = $conn->prepare("
    SELECT DISTINCT sp.stage_name, a.full_name 
    FROM stage_permissions sp
    JOIN account a ON sp.admin_id = a.id
    WHERE sp.can_update = 1
");
$salesStagesStmt->execute();
$salesStagesResult = $salesStagesStmt->get_result();
while ($row = $salesStagesResult->fetch_assoc()) {
    if (!isset($salesAssignedStages[$row['stage_name']])) {
        $salesAssignedStages[$row['stage_name']] = [];
    }
    $salesAssignedStages[$row['stage_name']][] = $row['full_name'];
}

// Fetch stages already assigned to other roles
$otherRoleStages = [];
$otherRoleStmt = $conn->prepare("
    SELECT DISTINCT stage_name, role 
    FROM role_stage_permissions 
    WHERE can_update = 1
");
$otherRoleStmt->execute();
$otherRoleResult = $otherRoleStmt->get_result();
while ($row = $otherRoleResult->fetch_assoc()) {
    $otherRoleStages[$row['stage_name']] = $row['role'];
}

// Fetch statistics for each role
$roleStats = [];
foreach ($roles as $role_key => $role_name) {
    $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM account WHERE role = ?");
    $countStmt->bind_param("s", $role_key);
    $countStmt->execute();
    $result = $countStmt->get_result()->fetch_assoc();
    $roleStats[$role_key] = $result['count'];
}

// Role descriptions
$descriptions = [
    'general_manager' => 'Oversees all operations and has strategic oversight',
    'operational_manager' => 'Manages day-to-day operations and project execution',
    'designer' => 'Creates design concepts and visual layouts',
    'technical_designer' => 'Handles technical drawings and specifications',
    'accounting' => 'Manages financial transactions and billing',
    'project_coordinator' => 'Handles the timeline and purchasing',
];

// Role icons
$icons = [
    'general_manager' => 'fa-user-tie',
    'operational_manager' => 'fa-tasks',
    'designer' => 'fa-pencil-ruler',
    'technical_designer' => 'fa-drafting-compass',
    'accounting' => 'fa-calculator',
    'project_coordinator' => 'fa-calculator',
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Role Permissions Controller</title>
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
        <div class="bg-[#0B0B0B] rounded-xl p-6 sm:p-7 text-white mb-4 adm-fade">
            <div class="flex items-center gap-4">
                <div
                    class="w-11 h-11 rounded-[9px] bg-white/10 border border-white/15 flex items-center justify-center text-[17px] flex-shrink-0">
                    <i class="fas fa-users-cog"></i>
                </div>
                <div>
                    <div class="text-[11px] font-semibold uppercase tracking-[1.5px] text-white/50 mb-0.5">Access
                        Control</div>
                    <div class="text-[19px] font-bold tracking-tight">Role-Based Permissions Controller</div>
                    <div class="text-[12.5px] text-white/60 mt-0.5">Manage project stage permissions by role. Sales
                        users manage their own permissions individually.</div>
                </div>
            </div>
        </div>

        <!-- Info banner -->
        <div class="bg-white border border-[#E2E2E2] rounded-[10px] px-4 py-3.5 mb-6 flex items-start gap-3 adm-fade">
            <i class="fas fa-info-circle text-[#6B6B6B] mt-0.5"></i>
            <div class="text-[13px] text-[#6B6B6B]">
                <strong class="text-[#0B0B0B]">Note:</strong> Sales role is excluded from this controller. Sales
                users have individual stage permissions managed through the
                <a href="stage-permissions-controller" class="text-[#0B0B0B] font-semibold underline underline-offset-2">Stage
                    Permissions Controller</a>.
            </div>
        </div>

        <!-- Roles grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($roles as $role_key => $role_name): ?>
                <?php
                $lastUpdateStmt = $conn->prepare("
                        SELECT updated_at, updated_by_name 
                        FROM role_stage_permissions 
                        WHERE role = ? 
                        ORDER BY updated_at DESC 
                        LIMIT 1
                    ");
                $lastUpdateStmt->bind_param("s", $role_key);
                $lastUpdateStmt->execute();
                $lastUpdate = $lastUpdateStmt->get_result()->fetch_assoc();
                ?>
                <div
                    class="bg-white border border-[#E2E2E2] rounded-[10px] p-5 flex flex-col hover:border-[#0B0B0B] hover:shadow-[0_10px_26px_-16px_rgba(11,11,11,0.25)] hover:-translate-y-0.5 transition-all adm-fade">

                    <div class="flex items-start justify-between gap-3 mb-4">
                        <div class="flex items-center gap-3 min-w-0">
                            <div
                                class="w-11 h-11 rounded-[9px] bg-[#F5F5F5] border border-[#E2E2E2] flex items-center justify-center text-lg text-[#0B0B0B] flex-shrink-0">
                                <i class="fas <?= $icons[$role_key] ?>"></i>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-[15px] font-semibold truncate"><?= htmlspecialchars($role_name) ?></h3>
                                <div class="text-[11px] font-mono text-[#9A9A9A] truncate"><?= htmlspecialchars($role_key) ?>
                                </div>
                            </div>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <div class="text-2xl font-bold font-mono leading-none"><?= $roleStats[$role_key] ?></div>
                            <div class="text-[9px] uppercase tracking-wide text-[#9A9A9A] mt-1">Users</div>
                        </div>
                    </div>

                    <p class="text-[13px] text-[#6B6B6B] leading-relaxed mb-4 flex-1">
                        <?= htmlspecialchars($descriptions[$role_key]) ?>
                    </p>

                    <?php if ($lastUpdate): ?>
                        <div class="bg-[#F5F5F5] border border-[#E2E2E2] rounded-md px-3 py-2 text-[11px] text-[#6B6B6B] mb-4">
                            <i class="fas fa-clock"></i>
                            Last updated by <strong class="text-[#0B0B0B]"><?= htmlspecialchars($lastUpdate['updated_by_name']) ?></strong>
                            on <?= date('M d, Y - g:i A', strtotime($lastUpdate['updated_at'])) ?>
                        </div>
                    <?php endif; ?>

                    <button
                        class="w-full inline-flex items-center justify-center gap-2 bg-[#0B0B0B] text-white py-2.5 rounded-md font-semibold text-[13px] hover:bg-[#2a2a2a] transition-colors"
                        onclick="openPermissionsModal('<?= $role_key ?>', '<?= htmlspecialchars($role_name, ENT_QUOTES) ?>', <?= $roleStats[$role_key] ?>)">
                        <i class="fas fa-cog"></i>
                        Configure Permissions
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Permissions Modal -->
    <div id="permissionsModal" class="hidden fixed inset-0 bg-black/50 z-[1000] items-center justify-center p-4">
        <div class="modal-box bg-white rounded-2xl shadow-2xl w-full max-w-[900px] max-h-[92vh] flex flex-col overflow-hidden">

            <!-- Header -->
            <div class="bg-[#0B0B0B] text-white px-6 sm:px-7 py-5 flex justify-between items-center flex-shrink-0">
                <h2 class="text-lg font-bold flex items-center gap-2.5">
                    <i class="fas fa-shield-alt"></i>
                    <span id="modalTitle">Role Permissions</span>
                </h2>
                <button onclick="closeModal()"
                    class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 border-none text-white text-lg flex items-center justify-center transition-all hover:rotate-90 duration-200">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="px-6 sm:px-7 py-6 overflow-y-auto flex-1">
                <!-- Role info -->
                <div class="bg-[#F5F5F5] border border-[#E2E2E2] rounded-[10px] p-5 mb-6 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
                    <div class="flex items-center justify-between sm:justify-start sm:gap-3 py-1.5 text-sm">
                        <span class="text-[#9A9A9A] font-medium">Role Name</span>
                        <span class="font-semibold" id="modalRoleName"></span>
                    </div>
                    <div class="flex items-center justify-between sm:justify-start sm:gap-3 py-1.5 text-sm">
                        <span class="text-[#9A9A9A] font-medium">Role Key</span>
                        <span class="font-semibold font-mono text-xs" id="modalRoleKey"></span>
                    </div>
                    <div class="flex items-center justify-between sm:justify-start sm:gap-3 py-1.5 text-sm">
                        <span class="text-[#9A9A9A] font-medium">Total Users</span>
                        <span class="font-semibold" id="modalUserCount"></span>
                    </div>
                    <div class="flex items-center justify-between sm:justify-start sm:gap-3 py-1.5 text-sm">
                        <span class="text-[#9A9A9A] font-medium">Configuring As</span>
                        <span class="font-semibold"><?= htmlspecialchars($currentAdmin['full_name']) ?></span>
                    </div>
                </div>

                <!-- Permissions -->
                <div class="flex items-center justify-between flex-wrap gap-2 mb-3.5">
                    <h3 class="text-sm font-bold flex items-center gap-2">
                        <i class="fas fa-tasks text-[#6B6B6B]"></i> Project Stage Permissions
                    </h3>
                    <span id="selectedCounter"
                        class="text-xs font-bold px-2.5 py-1 rounded-full bg-white border border-[#E2E2E2] text-[#6B6B6B]">0
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
                    Save Role Permissions
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
        let currentRole = null;

        function updateSelectedCounter() {
            const checked = document.querySelectorAll('.stage-permission-item input[type="checkbox"]:checked').length;
            document.getElementById('selectedCounter').textContent = `${checked} of ${totalStages} selected`;
        }

        async function openPermissionsModal(roleKey, roleName, userCount) {
            currentRole = roleKey;

            document.getElementById('modalTitle').textContent = `${roleName} — Stage Permissions`;
            document.getElementById('modalRoleName').textContent = roleName;
            document.getElementById('modalRoleKey').textContent = roleKey;
            document.getElementById('modalUserCount').textContent = userCount;

            const stagesList = document.getElementById('stagesList');
            stagesList.innerHTML = '<div class="text-center py-8 text-sm text-[#9A9A9A]"><i class="fas fa-spinner fa-spin"></i> Loading permissions...</div>';

            const modal = document.getElementById('permissionsModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            let data;
            try {
                const response = await fetch('get-role-permissions?role=' + encodeURIComponent(roleKey));
                data = await response.json();
            } catch (e) {
                stagesList.innerHTML = '<div class="text-center py-8 text-sm text-red-600"><i class="fas fa-exclamation-circle"></i> Failed to load permissions.</div>';
                return;
            }

            stagesList.innerHTML = '';
            allStages.forEach((stage, index) => {
                const isEnabled = data.permissions.includes(stage);
                const isLockedBySales = data.lockedBySales && data.lockedBySales[stage];
                const isLockedByOtherRole = data.lockedByOtherRole && data.lockedByOtherRole[stage];

                let lockMessage = '';
                let isLocked = false;

                if (isLockedBySales) {
                    isLocked = true;
                    const usersList = isLockedBySales.join(', ');
                    lockMessage = `<span class="ml-2 text-[10px] font-bold text-red-700 bg-red-50 border border-red-200 px-2 py-0.5 rounded-full"><i class="fas fa-lock"></i> Locked by Sales: ${usersList}</span>`;
                } else if (isLockedByOtherRole) {
                    isLocked = true;
                    lockMessage = `<span class="ml-2 text-[10px] font-bold text-red-700 bg-red-50 border border-red-200 px-2 py-0.5 rounded-full"><i class="fas fa-lock"></i> Locked by ${isLockedByOtherRole}</span>`;
                }

                const stageItem = document.createElement('div');
                stageItem.className = 'stage-permission-item flex items-center justify-between gap-3 border rounded-lg px-4 py-3 transition-colors ' +
                    (isLocked ? 'bg-[#F5F5F5] border-[#E2E2E2] opacity-60' : 'bg-white border-[#E2E2E2] hover:border-[#0B0B0B]');
                stageItem.innerHTML = `
                    <div class="flex items-center gap-3 flex-1 min-w-0">
                        <div class="w-8 h-8 rounded-full bg-[#0B0B0B] text-white flex items-center justify-center font-bold text-xs flex-shrink-0">${index + 1}</div>
                        <div class="text-sm font-medium truncate">
                            ${stage}
                            ${lockMessage}
                        </div>
                    </div>
                    <label class="relative inline-block w-[46px] h-6 flex-shrink-0">
                        <input type="checkbox" class="opacity-0 w-0 h-0 peer"
                               data-stage="${stage}"
                               ${isEnabled ? 'checked' : ''}
                               ${isLocked ? 'disabled' : ''}
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
                const response = await fetch('save-role-permissions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        role: currentRole,
                        stages: enabledStages
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showToast('Role permissions saved successfully!', 'success');
                    closeModal();
                    // Reload page to show updated timestamp
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Failed to save permissions: ' + result.error, 'error');
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('An error occurred', 'error');
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