<?php
// role_permissions_controller.php
include $includes['mainbody'];
require_role(['sales', 'general_manager', 'operational_manager']); // Only superadmin can manage role permissions

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Role Permissions Controller</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            padding: 40px;
            border-radius: 16px;
            color: white;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.05)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .page-header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            opacity: 0.9;
            font-size: 16px;
            position: relative;
            z-index: 1;
        }

        .info-banner {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 15px 20px;
            border-radius: 10px;
            margin-top: 20px;
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-banner i {
            font-size: 20px;
        }

        .roles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .role-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .role-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-color: #8a5a44;
        }

        .role-card-header {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            padding: 25px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .role-info h3 {
            font-size: 20px;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .role-key {
            font-size: 12px;
            opacity: 0.8;
            font-family: monospace;
            background: rgba(255,255,255,0.2);
            padding: 3px 8px;
            border-radius: 4px;
        }

        .role-stats {
            text-align: right;
        }

        .role-stats .count {
            font-size: 32px;
            font-weight: bold;
            line-height: 1;
        }

        .role-stats .label {
            font-size: 11px;
            opacity: 0.9;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .role-card-body {
            padding: 25px;
        }

        .role-description {
            color: #666;
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .permissions-btn {
            width: 100%;
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
            padding: 14px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .permissions-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background-color: white;
            padding: 0;
            border-radius: 16px;
            max-width: 900px;
            width: 90%;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }

        .modal-header {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .close-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 30px;
            overflow-y: auto;
            flex: 1;
        }

        .role-info-section {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
        }

        .role-info-section h3 {
            color: #3b1f0f;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .info-row {
            display: grid;
            grid-template-columns: 140px 1fr;
            padding: 8px 0;
            font-size: 14px;
        }

        .info-row .label {
            color: #666;
            font-weight: 500;
        }

        .info-row .value {
            color: #111;
            font-weight: 600;
        }

        .permissions-section h3 {
            color: #3b1f0f;
            margin-bottom: 20px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .quick-action-btn {
            padding: 8px 16px;
            border: 2px solid #8a5a44;
            background: white;
            color: #8a5a44;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .quick-action-btn:hover {
            background: #8a5a44;
            color: white;
        }

        .stages-list {
            display: grid;
            gap: 12px;
        }

        .stage-permission-item {
            background: #f9f9f9;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
        }

        .stage-permission-item:hover {
            border-color: #8a5a44;
            background: #fff;
        }

        .stage-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .stage-number {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }

        .stage-name {
            font-size: 14px;
            font-weight: 500;
            color: #111;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 30px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .3s;
            border-radius: 30px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
        }

        input:checked + .slider:before {
            transform: translateX(30px);
        }

        .modal-footer {
            padding: 20px 30px;
            background: #f9f9f9;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .save-btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .save-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(16,185,129,0.3);
        }

        .cancel-btn {
            background: #6b7280;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
        }

        .cancel-btn:hover {
            background: #4b5563;
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 16px 24px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: none;
            align-items: center;
            gap: 12px;
            z-index: 2000;
            animation: slideIn 0.3s ease;
        }

        .toast.show {
            display: flex;
        }

        .toast.success {
            border-left: 4px solid #10b981;
        }

        .toast.error {
            border-left: 4px solid #ef4444;
        }

        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .role-icon {
            font-size: 24px;
        }

        .last-updated {
            background: rgba(255,255,255,0.1);
            padding: 10px 15px;
            border-radius: 8px;
            font-size: 12px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-users-cog"></i> Role-Based Permissions Controller</h1>
            <p>Manage project stage permissions by role (Sales users manage their own permissions individually)</p>
            <div class="info-banner">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Note:</strong> Sales role is excluded from this controller. 
                    Sales users have individual stage permissions managed through the 
                    <a href="stage-permissions-controller" style="color: white; text-decoration: underline;">Stage Permissions Controller</a>.
                </div>
            </div>
        </div>

        <div class="roles-grid">
            <?php foreach ($roles as $role_key => $role_name): ?>
                <?php
                    // Fetch last updated info
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
                <div class="role-card">
                    <div class="role-card-header">
                        <div class="role-info">
                            <h3>
                                <i class="fas <?= $icons[$role_key] ?> role-icon"></i>
                                <?= htmlspecialchars($role_name) ?>
                            </h3>
                            <div class="role-key"><?= htmlspecialchars($role_key) ?></div>
                        </div>
                        <div class="role-stats">
                            <div class="count"><?= $roleStats[$role_key] ?></div>
                            <div class="label">Users</div>
                        </div>
                    </div>
                    <div class="role-card-body">
                        <div class="role-description">
                            <?= htmlspecialchars($descriptions[$role_key]) ?>
                        </div>
                        <?php if ($lastUpdate): ?>
                        <div class="last-updated" style="background: #f0f0f0; color: #666; padding: 8px 12px; border-radius: 6px; font-size: 11px; margin-bottom: 15px;">
                            <i class="fas fa-clock"></i> 
                            Last updated by <strong><?= htmlspecialchars($lastUpdate['updated_by_name']) ?></strong>
                            on <?= date('M d, Y - g:i A', strtotime($lastUpdate['updated_at'])) ?>
                        </div>
                        <?php endif; ?>
                        <button class="permissions-btn" onclick="openPermissionsModal('<?= $role_key ?>', '<?= htmlspecialchars($role_name) ?>', <?= $roleStats[$role_key] ?>)">
                            <i class="fas fa-cog"></i>
                            Configure Permissions
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Permissions Modal -->
    <div id="permissionsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i class="fas fa-shield-alt"></i>
                    <span id="modalTitle">Role Permissions</span>
                </h2>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="role-info-section">
                    <h3><i class="fas fa-info-circle"></i> Role Information</h3>
                    <div class="info-row">
                        <div class="label">Role Name:</div>
                        <div class="value" id="modalRoleName"></div>
                    </div>
                    <div class="info-row">
                        <div class="label">Role Key:</div>
                        <div class="value" style="font-family: monospace;" id="modalRoleKey"></div>
                    </div>
                    <div class="info-row">
                        <div class="label">Total Users:</div>
                        <div class="value" id="modalUserCount"></div>
                    </div>
                    <div class="info-row">
                        <div class="label">Configuring As:</div>
                        <div class="value"><?= htmlspecialchars($currentAdmin['full_name']) ?></div>
                    </div>
                </div>

                <div class="permissions-section">
                    <h3>
                        <i class="fas fa-tasks"></i>
                        Project Stage Permissions
                    </h3>
                    
                    <div class="quick-actions">
                        <button class="quick-action-btn" onclick="selectAll()">
                            <i class="fas fa-check-double"></i> Select All
                        </button>
                        <button class="quick-action-btn" onclick="deselectAll()">
                            <i class="fas fa-times"></i> Deselect All
                        </button>
                        <button class="quick-action-btn" onclick="selectFirstHalf()">
                            <i class="fas fa-list-ol"></i> First 9 Stages
                        </button>
                        <button class="quick-action-btn" onclick="selectLastHalf()">
                            <i class="fas fa-list"></i> Last 8 Stages
                        </button>
                    </div>

                    <div class="stages-list" id="stagesList">
                        <!-- Stages will be populated here -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="cancel-btn" onclick="closeModal()">
                    Cancel
                </button>
                <button class="save-btn" onclick="savePermissions()">
                    <i class="fas fa-save"></i>
                    Save Role Permissions
                </button>
            </div>
        </div>
    </div>

    <div id="toast" class="toast">
        <i class="fas fa-check-circle" style="font-size: 20px; color: #10b981;"></i>
        <span id="toastMessage">Permissions saved successfully!</span>
    </div>

    <script>
        const allStages = <?= json_encode($all_stages) ?>;
        let currentRole = null;

        async function openPermissionsModal(roleKey, roleName, userCount) {
            currentRole = roleKey;
            
            // Set role info
            document.getElementById('modalTitle').textContent = `${roleName} - Stage Permissions`;
            document.getElementById('modalRoleName').textContent = roleName;
            document.getElementById('modalRoleKey').textContent = roleKey;
            document.getElementById('modalUserCount').textContent = userCount;

            // Fetch current permissions
            const response = await fetch('get-role-permissions?role=' + encodeURIComponent(roleKey));
            const data = await response.json();
            
            // Populate stages list
            const stagesList = document.getElementById('stagesList');
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
        lockMessage = `<span style="margin-left: 8px; font-size: 11px; color: #ef4444; background: #fee2e2; padding: 2px 8px; border-radius: 4px;"><i class="fas fa-lock"></i> Locked by Sales: ${usersList}</span>`;
    } else if (isLockedByOtherRole) {
        isLocked = true;
        lockMessage = `<span style="margin-left: 8px; font-size: 11px; color: #ef4444; background: #fee2e2; padding: 2px 8px; border-radius: 4px;"><i class="fas fa-lock"></i> Locked by ${isLockedByOtherRole}</span>`;
    }
    
    const stageItem = document.createElement('div');
    stageItem.className = 'stage-permission-item' + (isLocked ? ' disabled' : '');
    stageItem.innerHTML = `
        <div class="stage-info">
            <div class="stage-number">${index + 1}</div>
            <div class="stage-name">
                ${stage}
                ${lockMessage}
            </div>
        </div>
        <label class="toggle-switch">
            <input type="checkbox" 
                   data-stage="${stage}" 
                   ${isEnabled ? 'checked' : ''}
                   ${isLocked ? 'disabled' : ''}>
            <span class="slider"></span>
        </label>
    `;
    stagesList.appendChild(stageItem);
});

            // Show modal
            document.getElementById('permissionsModal').classList.add('show');
        }

        function closeModal() {
            document.getElementById('permissionsModal').classList.remove('show');
        }

        function selectAll() {
            document.querySelectorAll('.stage-permission-item input[type="checkbox"]').forEach(cb => {
                cb.checked = true;
            });
        }

        function deselectAll() {
            document.querySelectorAll('.stage-permission-item input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
        }

        function selectFirstHalf() {
            const checkboxes = document.querySelectorAll('.stage-permission-item input[type="checkbox"]');
            checkboxes.forEach((cb, index) => {
                cb.checked = index < 9;
            });
        }

        function selectLastHalf() {
            const checkboxes = document.querySelectorAll('.stage-permission-item input[type="checkbox"]');
            checkboxes.forEach((cb, index) => {
                cb.checked = index >= 9;
            });
        }

        async function savePermissions() {
            const checkboxes = document.querySelectorAll('.stage-permission-item input[type="checkbox"]:checked');
            const enabledStages = Array.from(checkboxes).map(cb => cb.dataset.stage);

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
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('An error occurred', 'error');
            }
        }

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            const icon = toast.querySelector('i');
            
            toastMessage.textContent = message;
            toast.className = 'toast show ' + type;
            
            if (type === 'error') {
                icon.className = 'fas fa-exclamation-circle';
                icon.style.color = '#ef4444';
            } else {
                icon.className = 'fas fa-check-circle';
                icon.style.color = '#10b981';
            }
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // Close modal when clicking outside
        document.getElementById('permissionsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>