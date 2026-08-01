<?php
//view_addon_details.php
include $includes ['mainbody'];

// Allow only admin1, superadmin, sales
require_role(['admin1','superadmin', 'sales', 'designer']);

// Extra check: if designer, only heads can access
if ($_SESSION['admin_role'] === 'designer') {
    $headCheck = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
    $headCheck->bind_param("i", $_SESSION['admin_id']);
    $headCheck->execute();
    $headRow = $headCheck->get_result()->fetch_assoc();
    $headCheck->close();

    if (empty($headRow['is_head'])) {
        $_SESSION['noti'] = 'Access Denied: Only head designers can access this page.';
        header("Location: " . BASE_URL . "designer-layout-list");
        exit();
    }
}

// Check if ID is provided
if (!isset($_GET['id'])) {
    header("Location: " . BASE_URL . "view-addons");
    exit();
}

$addon_id = $_GET['id'];

// Fetch addon details
$stmt = $conn->prepare("SELECT * FROM product_addons WHERE id = ?");
$stmt->bind_param("i", $addon_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['error_message'] = "Accessory not found!";
    header("Location: " . BASE_URL . "view-addons");
    exit();
}

$addon = $result->fetch_assoc();
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Accessory Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        
        .container { max-width: 1000px; margin: 0 auto; padding: 30px; }
        
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
        
        /* Back Button */
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: white;
            color: #3b1f0f;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 24px;
            transition: all 0.2s;
        }
        .back-btn:hover {
            background: #f8f9fa;
            transform: translateX(-4px);
        }
        
        /* Card */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .card-header {
            background: #3b1f0f;
            color: white;
            padding: 20px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .card-header h2 { font-size: 20px; font-weight: 600; }
        .card-body { padding: 30px; }
        
        /* Badge */
        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        /* Image Section */
        .image-section {
            text-align: center;
            margin-bottom: 30px;
            padding: 30px;
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
            border-radius: 12px;
        }
        .addon-image {
            max-width: 100%;
            max-height: 400px;
            border-radius: 12px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        .no-image {
            width: 200px;
            height: 200px;
            background: linear-gradient(135deg, #e5e7eb 0%, #d1d5db 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }
        .no-image i {
            font-size: 80px;
            color: #9ca3af;
        }
        
        /* Details Grid */
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }
        .detail-item {
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #3b1f0f;
        }
        .detail-label {
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .detail-value {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
        }
        .detail-price {
            font-size: 32px;
            color: #3b1f0f;
        }
        
        /* Description Section */
        .description-section {
            background: #f9fafb;
            padding: 24px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .description-section h3 {
            font-size: 16px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .description-text {
            font-size: 15px;
            color: #4b5563;
            line-height: 1.6;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
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
        .btn-edit {
            background: #f59e0b;
            color: white;
        }
        .btn-edit:hover {
            background: #d97706;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        .btn-delete {
            background: #ef4444;
            color: white;
        }
        .btn-delete:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }
        .btn-back {
            background: #6b7280;
            color: white;
        }
        .btn-back:hover {
            background: #4b5563;
        }
        
        /* Info Box */
        .info-box {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border-left: 4px solid #3b82f6;
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .info-box i {
            font-size: 24px;
            color: #1e40af;
        }
        .info-box-content {
            flex: 1;
        }
        .info-box-title {
            font-weight: 600;
            color: #1e3a8a;
            margin-bottom: 4px;
        }
        .info-box-text {
            font-size: 14px;
            color: #1e40af;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container { padding: 20px; }
            .page-header { padding: 30px 20px; }
            .page-header h1 { font-size: 24px; }
            .card-body { padding: 20px; }
            .card-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .details-grid { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Back Button -->
        <a href="<?= BASE_URL ?>view-addons" class="back-btn">
            <i class="fas fa-arrow-left"></i>
            Back to Accessories
        </a>

        <!-- Header -->
        <div class="page-header">
            <h1>👁️ Accessory Details</h1>
            <p>Complete information about this accessory</p>
        </div>

        <!-- Info Box -->
        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <div class="info-box-content">
                <div class="info-box-title">Viewing Accessory Information</div>
                <div class="info-box-text">Review all details about this product accessory below</div>
            </div>
        </div>

        <!-- Main Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-left">
                    <i class="fas fa-puzzle-piece"></i>
                    <h2><?php echo htmlspecialchars($addon['addon_name']); ?></h2>
                </div>
                <?php if (!empty($addon['addon_category'])): ?>
                    <span class="badge">
                        <i class="fas fa-tag"></i>
                        <?php echo htmlspecialchars(ucfirst($addon['addon_category'])); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <!-- Image Section -->
                <div class="image-section">
                    <?php if (!empty($addon['addon_image_path'])): ?>
                        <img src="../../realiving_user/images/product_addons/<?php echo htmlspecialchars($addon['addon_image_path']); ?>" 
                             alt="<?php echo htmlspecialchars($addon['addon_name']); ?>"
                             class="addon-image">
                    <?php else: ?>
                        <div class="no-image">
                            <i class="fas fa-puzzle-piece"></i>
                        </div>
                        <p style="margin-top: 16px; color: #6b7280; font-size: 14px;">No image available</p>
                    <?php endif; ?>
                </div>

                <!-- Details Grid -->
                <div class="details-grid">
                    <div class="detail-item">
                        <div class="detail-label">
                            <i class="fas fa-peso-sign"></i>
                            Price
                        </div>
                        <div class="detail-value detail-price">
                            ₱<?php echo number_format($addon['addon_price'], 2); ?>
                        </div>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">
                            <i class="fas fa-tag"></i>
                            Category
                        </div>
                        <div class="detail-value">
                            <?php echo !empty($addon['addon_category']) ? htmlspecialchars(ucfirst($addon['addon_category'])) : 'N/A'; ?>
                        </div>
                    </div>

                    <?php if (!empty($addon['labor_cost'])): ?>
<div class="detail-item" style="border-left-color: #6366f1;">
    <div class="detail-label">
        <i class="fas fa-hard-hat"></i>
        Labor Cost
    </div>
    <div class="detail-value detail-price" style="color: #6366f1;">
        ₱<?php echo number_format($addon['labor_cost'], 2); ?>
    </div>
</div>
<?php endif; ?>

                    <div class="detail-item">
                        <div class="detail-label">
                            <i class="fas fa-calendar-plus"></i>
                            Created Date
                        </div>
                        <div class="detail-value" style="font-size: 16px;">
                            <?php echo date('F d, Y', strtotime($addon['created_at'])); ?>
                        </div>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">
                            <i class="fas fa-clock"></i>
                            Created Time
                        </div>
                        <div class="detail-value" style="font-size: 16px;">
                            <?php echo date('h:i A', strtotime($addon['created_at'])); ?>
                        </div>
                    </div>
                </div>

                <!-- Description Section -->
                <div class="description-section">
                    <h3>
                        <i class="fas fa-align-left"></i>
                        Description
                    </h3>
                    <div class="description-text">
                        <?php echo nl2br(htmlspecialchars($addon['addon_description'])); ?>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <a href="<?= BASE_URL ?>view-addons" class="btn btn-back">
                        <i class="fas fa-arrow-left"></i>
                        Back to List
                    </a>
                    <a href="<?= BASE_URL ?>edit-addon?id=<?php echo $addon['id']; ?>" class="btn btn-edit">
                        <i class="fas fa-edit"></i>
                        Edit Accessory
                    </a>
                    <button onclick="deleteAddon(<?php echo $addon['id']; ?>, '<?php echo htmlspecialchars($addon['addon_name']); ?>')" 
                            class="btn btn-delete">
                        <i class="fas fa-trash"></i>
                        Delete Accessory
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function deleteAddon(addonId, addonName) {
            if (confirm(`Are you sure you want to delete "${addonName}"?\n\nThis action cannot be undone.`)) {
                window.location.href = '<?= BASE_URL ?>delete-insert-addon?id=' + addonId;
            }
        }
    </script>
</body>
</html>