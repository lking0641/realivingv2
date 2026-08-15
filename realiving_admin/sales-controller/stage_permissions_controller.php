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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stage Permissions Controller</title>
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

        .admins-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
        }

        .admin-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }

        .admin-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .admin-card-header {
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            padding: 20px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .admin-info h3 {
            font-size: 18px;
            margin-bottom: 5px;
        }

        .admin-info .email {
            font-size: 12px;
            opacity: 0.9;
        }

        .admin-stats {
            text-align: right;
        }

        .admin-stats .count {
            font-size: 24px;
            font-weight: bold;
        }

        .admin-stats .label {
            font-size: 11px;
            opacity: 0.9;
            text-transform: uppercase;
        }

        .admin-card-body {
            padding: 20px;
        }

        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            background: #e9ecef;
            color: #495057;
            margin-bottom: 15px;
        }

        .permissions-btn {
            width: 100%;
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
            padding: 12px 20px;
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
            transform: translateY(-1px);
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

        .admin-info-section {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
        }

        .admin-info-section h3 {
            color: #3b1f0f;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .info-row {
            display: grid;
            grid-template-columns: 120px 1fr;
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

        .permissions-section {
            margin-top: 20px;
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

        .stage-permission-item.disabled {
            opacity: 0.5;
            background: #f5f5f5;
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

        .search-box {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .search-box input {
            width: 100%;
            padding: 12px 20px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
        }

        .search-box input:focus {
            outline: none;
            border-color: #8a5a44;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-shield-alt"></i> Stage Permissions Controller</h1>
            <p>Manage which project stages each admin can update for their clients</p>
        </div>

        <div class="search-box">
            <input type="text" id="searchInput" placeholder="🔍 Search admins by name or email...">
        </div>

        <div class="admins-grid" id="adminsGrid">
            <?php while ($admin = $admins->fetch_assoc()): ?>
                <div class="admin-card" data-search="<?= strtolower($admin['full_name'] . ' ' . $admin['email']) ?>">
                    <div class="admin-card-header">
                        <div class="admin-info">
                            <h3><?= htmlspecialchars($admin['full_name']) ?></h3>
                            <div class="email"><?= htmlspecialchars($admin['email']) ?></div>
                        </div>
                        <div class="admin-stats">
                            <div class="count"><?= $admin['client_count'] ?></div>
                            <div class="label">Clients</div>
                        </div>
                    </div>
                    <div class="admin-card-body">
                        <div class="role-badge">
                            <i class="fas fa-user-tag"></i> <?= htmlspecialchars($admin['role']) ?>
                        </div>
                        <button class="permissions-btn" 
    data-id="<?= $admin['id'] ?>"
    data-name="<?= htmlspecialchars($admin['full_name'], ENT_QUOTES) ?>"
    data-email="<?= htmlspecialchars($admin['email'], ENT_QUOTES) ?>"
    data-role="<?= htmlspecialchars($admin['role'], ENT_QUOTES) ?>"
    data-count="<?= $admin['client_count'] ?>"
    onclick="openPermissionsModal(this.dataset.id, this.dataset.name, this.dataset.email, this.dataset.role, this.dataset.count)">
    <i class="fas fa-cog"></i>
    Manage Stage Permissions
</button>
                            <i class="fas fa-cog"></i>
                            Manage Stage Permissions
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <!-- Permissions Modal -->
    <div id="permissionsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i class="fas fa-user-shield"></i>
                    <span id="modalTitle">Stage Permissions</span>
                </h2>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="admin-info-section">
                    <h3><i class="fas fa-info-circle"></i> Admin Information</h3>
                    <div class="info-row">
                        <div class="label">Name:</div>
                        <div class="value" id="modalAdminName"></div>
                    </div>
                    <div class="info-row">
                        <div class="label">Email:</div>
                        <div class="value" id="modalAdminEmail"></div>
                    </div>
                    <div class="info-row">
                        <div class="label">Role:</div>
                        <div class="value" id="modalAdminRole"></div>
                    </div>
                    <div class="info-row">
                        <div class="label">Total Clients:</div>
                        <div class="value" id="modalClientCount"></div>
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
                    Save Permissions
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
        let currentAdminId = null;

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const cards = document.querySelectorAll('.admin-card');
            
            cards.forEach(card => {
                const searchData = card.getAttribute('data-search');
                if (searchData.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        async function openPermissionsModal(adminId, name, email, role, clientCount) {
            currentAdminId = adminId;
            
            // Set admin info
            document.getElementById('modalTitle').textContent = `Stage Permissions - ${name}`;
            document.getElementById('modalAdminName').textContent = name;
            document.getElementById('modalAdminEmail').textContent = email;
            document.getElementById('modalAdminRole').textContent = role;
            document.getElementById('modalClientCount').textContent = clientCount;

            // Fetch current permissions
            const response = await fetch('<?= BASE_URL ?>get-stage-permissions?admin_id=' + adminId);
            const data = await response.json();
            
            // Populate stages list
            const stagesList = document.getElementById('stagesList');
            stagesList.innerHTML = '';
            
            allStages.forEach((stage, index) => {
    const isEnabled = data.permissions.includes(stage);
    const isLockedByRole = data.lockedByRole && data.lockedByRole[stage];
    
    const stageItem = document.createElement('div');
    stageItem.className = 'stage-permission-item' + (isLockedByRole ? ' disabled' : '');
    stageItem.innerHTML = `
        <div class="stage-info">
            <div class="stage-number">${index + 1}</div>
            <div class="stage-name">
                ${stage}
                ${isLockedByRole ? `<span style="margin-left: 8px; font-size: 11px; color: #ef4444; background: #fee2e2; padding: 2px 8px; border-radius: 4px;"><i class="fas fa-lock"></i> Locked by ${isLockedByRole}</span>` : ''}
            </div>
        </div>
        <label class="toggle-switch">
            <input type="checkbox" 
                   data-stage="${stage}" 
                   ${isEnabled ? 'checked' : ''}
                   ${isLockedByRole ? 'disabled' : ''}>
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