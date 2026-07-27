<?php
//allclient.php
include $includes ['mainbody'];
// Allow only admin1 and superadmin
require_role(['sales','designer','technical_designer','project_coordinator']);

$admin_id = $_SESSION['admin_id'];

// Automatically update old clients
$conn->query("UPDATE user_info SET status = 'Old Client' 
    WHERE created_at <= DATE_SUB(CURDATE(), INTERVAL 1 YEAR) AND status != 'Old Client'");

// Handle new client form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['clientname'], $_POST['status'], $_POST['nameproject'], $_POST['client_type'], $_POST['client_class']) && !isset($_POST['export']) && !isset($_POST['update_id'])) {
    $clientname = $_POST['clientname'];
    $status = $_POST['status'];
    $nameproject = $_POST['nameproject'];
    $client_type = $_POST['client_type'];
    $client_class = $_POST['client_class'];
    $contact = $_POST['contact'];
    $email = $_POST['email'];
    $address = $_POST['address'];
    $gender = $_POST['gender'];
    $business_type = $_POST['business_type'];
    $project_scope = $_POST['project_scope'];
$scope_of_work = $_POST['scope_of_work'];
$house_state = $_POST['house_state'];
$permit_required = $_POST['permit_required'];
$target_movein_date = !empty($_POST['target_movein_date']) ? $_POST['target_movein_date'] : null;
    $updateTime = date('Y-m-d H:i:s');
    $accountaid_fk = $_SESSION['admin_id'];

    $reference_number = "REF" . date("YmdHis") . strtoupper(substr(md5(uniqid()), 0, 4));

    $stmt = $conn->prepare("INSERT INTO user_info (clientname, status, nameproject, updatestatus, update_time, reference_number, client_type, client_class, business_type, contact, email, address, gender, accountaid_fk, project_scope, scope_of_work, house_state, permit_required, target_movein_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sssssssssssssisssss", $clientname, $status, $nameproject, $status, $updateTime, $reference_number, $client_type, $client_class, $business_type, $contact, $email, $address, $gender, $accountaid_fk, $project_scope, $scope_of_work, $house_state, $permit_required, $target_movein_date);

    if ($stmt->execute()) {
        // If this client was created from an inquiry, update the inquiry status
if ($inquiry_id) {
    $update_inquiry_stmt = $conn->prepare("UPDATE contact_inquiries SET account_status = 'Converted to Client' WHERE id = ?");
    $update_inquiry_stmt->bind_param("i", $inquiry_id);
    $update_inquiry_stmt->execute();
    $update_inquiry_stmt->close();
}

        $_SESSION['success_message'] = "New client added successfully!";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        $_SESSION['error_message'] = "Error: " . $stmt->error;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $stmt->close();
}

// Search functionality
$search = isset($_GET['search']) ? $_GET['search'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$filter_type = isset($_GET['filter_type']) ? $_GET['filter_type'] : '';

$sql = "SELECT * FROM user_info WHERE 1=1";

if (!empty($search)) {
    $search = "%$search%";
    $sql .= " AND (clientname LIKE '$search' OR reference_number LIKE '$search' OR nameproject LIKE '$search' OR email LIKE '$search')";
}

if (!empty($filter_status)) {
    $sql .= " AND status = '$filter_status'";
}

if (!empty($filter_type)) {
    $sql .= " AND client_type = '$filter_type'";
}

$sql .= " ORDER BY id DESC";
$result = $conn->query($sql);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Management System</title>
    <link rel="icon" type="image/png" sizes="32x32" href="../../logo/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        
        .container { max-width: 1400px; margin: 0 auto; padding: 30px; }
        
        /* Header */
        .page-header { 
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
            padding: 40px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .page-header h1 { font-size: 32px; margin-bottom: 10px; }
        .page-header p { opacity: 0.9; font-size: 16px; }
        
        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease-out;
        }
        .alert-success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }
        .alert-error {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }
        .alert i { font-size: 20px; }
        
        /* Card */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 30px;
        }
        .card-header {
            background: #3b1f0f;
            color: white;
            padding: 20px 30px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .card-header h2 { font-size: 20px; font-weight: 600; }
        .card-header i { font-size: 24px; }
        .card-body { padding: 30px; }
        
        /* Info Alert */
        .info-alert {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            color: #1e40af;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .form-group { display: flex; flex-direction: column; }
        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-wrapper i {
            position: absolute;
            left: 12px;
            color: #9ca3af;
            font-size: 14px;
        }
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 10px 12px 10px 38px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .form-textarea {
            resize: vertical;
            min-height: 80px;
            padding-top: 10px;
        }
        
        /* Button */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        .btn-center {
            display: flex;
            justify-content: center;
            margin-top: 24px;
        }
        
        /* Animations */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container { padding: 20px; }
            .page-header { padding: 30px 20px; }
            .page-header h1 { font-size: 24px; }
            .card-body { padding: 20px; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="page-header">
            <h1>👥 Client Management System</h1>
            <p>Manage your clients and projects efficiently</p>
        </div>

        <!-- Success Message -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Success!</strong>
                    <p><?php echo $_SESSION['success_message']; ?></p>
                </div>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <!-- Error Message -->
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Error!</strong>
                    <p><?php echo $_SESSION['error_message']; ?></p>
                </div>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Add Client Card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-user-plus"></i>
                <h2>Add New Client</h2>
            </div>
            <div class="card-body">
                <?php if (isset($_GET['prefill']) && isset($_GET['id'])): ?>
                    <div class="info-alert">
                        <i class="fas fa-info-circle"></i>
                        <p>Form pre-filled with inquiry data (ID: <?php echo htmlspecialchars($_GET['id']); ?>). Review and modify as needed. The inquiry status will be updated when you add this client.</p>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?php if (isset($_GET['id'])): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($_GET['id']); ?>">
                    <?php endif; ?>
                    
                    <!-- Row 1 -->
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="clientname">Client Name</label>
                            <div class="input-wrapper">
                                <i class="fas fa-user"></i>
                                <input type="text" name="clientname" id="clientname" class="form-input" 
                                    placeholder="Enter client name"
                                    value="<?php echo isset($_GET['prefill']) && isset($_GET['name']) ? htmlspecialchars($_GET['name']) : ''; ?>" 
                                    required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="status">Status</label>
                            <div class="input-wrapper">
                                <i class="fas fa-tag"></i>
                                <select name="status" id="status" class="form-select" required>
                                    <option value="New Client">New Client</option>
                                    <option value="Old Client">Old Client</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="nameproject">Project Name</label>
                            <div class="input-wrapper">
                                <i class="fas fa-project-diagram"></i>
                                <input type="text" name="nameproject" id="nameproject" class="form-input" 
                                    placeholder="Enter project name" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="client_type">Client Type</label>
                            <div class="input-wrapper">
                                <i class="fas fa-building"></i>
                                <select name="client_type" id="client_type" class="form-select" required>
                                    <option value="Realiving">Realiving</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Row 2 -->
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="contact">Contact Number</label>
                            <div class="input-wrapper">
                                <i class="fas fa-phone"></i>
                                <input type="tel" name="contact" id="contact" class="form-input" 
    placeholder="e.g. 09171234567"
    maxlength="11"
    pattern="^(09|\+639)\d{9}$"
    value="<?php echo isset($_GET['prefill']) && isset($_GET['phone']) ? htmlspecialchars($_GET['phone']) : ''; ?>" 
    required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <div class="input-wrapper">
                                <i class="fas fa-envelope"></i>
                                <input type="email" name="email" id="email" class="form-input" 
                                    placeholder="Enter email address"
                                    value="<?php echo isset($_GET['prefill']) && isset($_GET['email']) ? htmlspecialchars($_GET['email']) : ''; ?>" 
                                    required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="gender">Gender</label>
                            <div class="input-wrapper">
                                <i class="fas fa-venus-mars"></i>
                                <select name="gender" id="gender" class="form-select" required>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                    <option value="Prefer not to say">Prefer not to say</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Row 3 -->
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                        <div class="form-group">
                            <label for="client_class">Client Classification</label>
                            <div class="input-wrapper">
                                <i class="fas fa-award"></i>
                                <select name="client_class" id="client_class" class="form-select" required>
                                    <option value="VIP">VIP</option>
                                    <option value="Regular">Regular</option>
                                    <option value="Walk-in">Walk-in</option>
                                    <option value="Returning">Returning</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="business_type">Business Type</label>
                            <div class="input-wrapper">
                                <i class="fas fa-briefcase"></i>
                                <select name="business_type" id="business_type" class="form-select" required>
                                    <option value="Non-Project">Individual</option>
                                    <option value="Project">Project</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Row 4 - Address -->
                    <div class="form-group">
                        <label for="address">Address</label>
                        <div class="input-wrapper">
                            <i class="fas fa-map-marker-alt" style="top: 14px;"></i>
                            <textarea name="address" id="address" class="form-textarea" 
                                placeholder="Enter full address" required><?php echo isset($_GET['prefill']) && isset($_GET['address']) ? htmlspecialchars($_GET['address']) : ''; ?></textarea>
                        </div>
                    </div>

                    <!-- Row 5 -->
                    <div class="form-group">
                        <label for="project_scope">Project Scope</label>
                        <div class="input-wrapper">
                            <i class="fas fa-tasks"></i>
                            <input type="text" name="project_scope" id="project_scope" class="form-input" 
                                placeholder="Enter project scope" required>
                        </div>
                    </div>

                    <!-- Row 6 -->
                    <!-- Row 6 -->
                    <div class="form-group">
                        <label for="scope_of_work">Scope of Work</label>
                        <div class="input-wrapper">
                            <i class="fas fa-clipboard-list" style="top: 14px;"></i>
                            <textarea name="scope_of_work" id="scope_of_work" class="form-textarea" 
                                placeholder="Enter scope of work" required></textarea>
                        </div>
                    </div>

                    <!-- Row 7 - Property Info -->
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="house_state">State of the House</label>
                            <div class="input-wrapper">
                                <i class="fas fa-home"></i>
                                <select name="house_state" id="house_state" class="form-select" required>
                                    <option value="" disabled selected>Select house state</option>
                                    <option value="Bare/Empty Lot">Bare / Empty Lot</option>
                                    <option value="Existing Structure">Existing Structure (No renovation yet)</option>
                                    <option value="Renovation">Existing Structure (For Renovation)</option>
                                    <option value="Construction Started">Construction Already Started</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="permit_required">Permit Required?</label>
                            <div class="input-wrapper">
                                <i class="fas fa-file-contract"></i>
                                <select name="permit_required" id="permit_required" class="form-select" required>
                                    <option value="" disabled selected>Select permit status</option>
                                    <option value="Yes">Yes — Permit Required</option>
                                    <option value="No">No — Not Required</option>
                                    <option value="Unsure">Unsure — Needs Assessment</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="target_movein_date">Target Move-in Date</label>
                            <div class="input-wrapper">
                                <i class="fas fa-calendar-check"></i>
                                <input type="date" name="target_movein_date" id="target_movein_date" 
                                    class="form-input">
                            </div>
                            <label style="display: flex; align-items: center; gap: 8px; margin-top: 8px; font-size: 13px; color: #6b7280; cursor: pointer; font-weight: normal;">
                                <input type="checkbox" id="no_movein_date" onchange="toggleMoveInDate(this)" 
                                    style="width: 15px; height: 15px; cursor: pointer; accent-color: #3b82f6;">
                                None / Not yet determined
                            </label>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="btn-center">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i>
                            Add Client
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        document.getElementById('contact').addEventListener('input', function () {
    this.value = this.value.replace(/[^0-9+]/g, ''); // only allow digits and +
});

document.getElementById('contact').addEventListener('blur', function () {
    const val = this.value.trim();
    const valid = /^(09|\+639)\d{9}$/.test(val);
    if (!valid && val !== '') {
        this.style.borderColor = '#ef4444';
        this.style.boxShadow = '0 0 0 3px rgba(239,68,68,0.1)';
        let msg = document.getElementById('contact-error');
        if (!msg) {
            msg = document.createElement('small');
            msg.id = 'contact-error';
            msg.style.color = '#ef4444';
            msg.style.marginTop = '4px';
            msg.style.fontSize = '12px';
            this.parentElement.insertAdjacentElement('afterend', msg);
        }
        msg.textContent = 'Enter a valid PH number: 09XXXXXXXXX';
    } else {
        this.style.borderColor = '';
        this.style.boxShadow = '';
        const msg = document.getElementById('contact-error');
        if (msg) msg.remove();
    }
});

        function toggleMoveInDate(checkbox) {
    const dateInput = document.getElementById('target_movein_date');
    if (checkbox.checked) {
        dateInput.value = '';
        dateInput.disabled = true;
    } else {
        dateInput.disabled = false;
    }
}
    </script>
</body>
</html>