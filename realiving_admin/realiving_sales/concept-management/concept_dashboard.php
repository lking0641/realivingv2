<?php
//concept_dashboard.php
include $includes ['mainbody'];

// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales']);

// Get statistics
$header_data = $conn->query("SELECT * FROM concept_header LIMIT 1")->fetch_assoc();
$styles_count = $conn->query("SELECT COUNT(*) as count FROM concept_styles")->fetch_assoc()['count'];
$carousel_count = $conn->query("SELECT COUNT(*) as count FROM concept_carousel")->fetch_assoc()['count'];

// Get recent styles (last 3)
$recent_styles = $conn->query("SELECT * FROM concept_styles ORDER BY display_order DESC LIMIT 3");

// Get recent carousel images (last 3)
$recent_carousel = $conn->query("SELECT * FROM concept_carousel ORDER BY display_order DESC LIMIT 3");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Concept Page Dashboard</title>
    <link rel="stylesheet" href="../css/admin-style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        
        .dashboard-container { max-width: 1400px; margin: 0 auto; padding: 30px; }
        
        /* Header */
        .dashboard-header { 
            background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%);
            color: white;
            padding: 40px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .dashboard-header h1 { font-size: 32px; margin-bottom: 10px; }
        .dashboard-header p { opacity: 0.9; font-size: 16px; }
        
        /* Stats Cards */
        .stats-grid { 
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        .stat-icon.header-icon { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-icon.styles-icon { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-icon.carousel-icon { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-content h3 { font-size: 14px; color: #666; margin-bottom: 5px; font-weight: 500; }
        .stat-content .stat-number { font-size: 28px; font-weight: bold; color: #3b1f0f; }
        
        /* Management Sections */
        .management-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        .management-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .card-header {
            background: #3b1f0f;
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h2 { font-size: 20px; }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-primary { background: #8a5a44; color: white; }
        .btn-primary:hover { background: #a67c52; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; }
        
        .card-body { padding: 20px; }
        
        /* Header Preview */
        .header-preview {
            position: relative;
            height: 200px;
            background-size: cover;
            background-position: center;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 15px;
        }
        .header-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 20px;
            color: white;
            text-align: center;
        }
        .header-overlay h3 { font-size: 24px; margin-bottom: 10px; }
        .header-overlay p { font-size: 14px; opacity: 0.9; }
        
        /* Items List */
        .items-list { display: flex; flex-direction: column; gap: 15px; }
        .item-preview {
            display: flex;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            align-items: center;
        }
        .item-image {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 6px;
            flex-shrink: 0;
        }
        .item-info { flex: 1; min-width: 0; }
        .item-info h4 {
            font-size: 16px;
            margin-bottom: 5px;
            color: #3b1f0f;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .item-info p {
            font-size: 13px;
            color: #666;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-primary { background: #e3f2fd; color: #1976d2; }
        .badge-secondary { background: #f3e5f5; color: #7b1fa2; }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        .empty-state svg {
            width: 60px;
            height: 60px;
            margin-bottom: 15px;
            opacity: 0.3;
        }
        
        /* Quick Actions */
        .quick-actions {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .quick-actions h2 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #3b1f0f;
        }
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .action-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            border-radius: 8px;
            text-decoration: none;
            color: #3b1f0f;
            font-weight: 500;
            transition: transform 0.2s;
        }
        .action-btn:hover {
            transform: translateY(-2px);
        }
        .action-icon {
            font-size: 24px;
        }
        
        @media (max-width: 768px) {
            .management-grid {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <h1>📐 Concept Page Dashboard</h1>
            <p>Manage your concept designs page content, header, styles, and carousel images</p>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon header-icon">📄</div>
                <div class="stat-content">
                    <h3>Header Settings</h3>
                    <div class="stat-number">1</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon styles-icon">🎨</div>
                <div class="stat-content">
                    <h3>Design Styles</h3>
                    <div class="stat-number"><?php echo $styles_count; ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon carousel-icon">🖼️</div>
                <div class="stat-content">
                    <h3>Carousel Images</h3>
                    <div class="stat-number"><?php echo $carousel_count; ?></div>
                </div>
            </div>
        </div>

        <!-- Management Sections -->
        <div class="management-grid">
            <!-- Header Management -->
            <div class="management-card">
                <div class="card-header">
                    <h2>Page Header</h2>
                    <a href="concept-manage-header" class="btn btn-primary">Edit Header</a>
                </div>
                <div class="card-body">
                    <?php if ($header_data): ?>
                    <div class="header-preview" style="background-image: url('<?php echo CLIENT_ASSET ?>/<?php echo htmlspecialchars($header_data['header_image']); ?>')">
                        <div class="header-overlay">
                            <h3><?php echo htmlspecialchars($header_data['title']); ?></h3>
                            <p><?php echo htmlspecialchars(substr($header_data['subtitle'], 0, 100)) . '...'; ?></p>
                        </div>
                    </div>
                    <p style="font-size: 13px; color: #666;">Control the main header section with background image, title, and subtitle.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Styles Management -->
            <div class="management-card">
                <div class="card-header">
                    <h2>Design Styles</h2>
                    <a href="concept-manage-styles" class="btn btn-success">Manage Styles</a>
                </div>
                <div class="card-body">
                    <?php if ($recent_styles->num_rows > 0): ?>
                    <div class="items-list">
                        <?php while ($style = $recent_styles->fetch_assoc()): ?>
                        <div class="item-preview">
    <div class="item-info">
        <h4><?php echo htmlspecialchars($style['title']); ?></h4>
        <p><?php echo htmlspecialchars(substr($style['description'], 0, 50)) . '...'; ?></p>
        <p style="font-size: 11px; color: #999; margin-top: 5px;">
            🔗 <?php echo htmlspecialchars(substr($style['iframe_url'], 0, 40)) . '...'; ?>
        </p>
    </div>
    <span class="badge badge-primary"><?php echo $style['layout_type']; ?></span>
</div>
                        <?php endwhile; ?>
                    </div>
                    <p style="font-size: 13px; color: #666; margin-top: 15px;">
                        Showing last 3 styles. Total: <?php echo $styles_count; ?>
                    </p>
                    <?php else: ?>
                    <div class="empty-state">
                        <p>No styles added yet</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Carousel Management -->
            <div class="management-card">
                <div class="card-header">
                    <h2>Carousel Images</h2>
                    <a href="concept-manage-carousel" class="btn btn-success">Manage Carousel</a>
                </div>
                <div class="card-body">
                    <?php if ($recent_carousel->num_rows > 0): ?>
                    <div class="items-list">
                        <?php while ($carousel = $recent_carousel->fetch_assoc()): ?>
                        <div class="item-preview">
                            <img src="<?php echo CLIENT_ASSET ?>/<?php echo htmlspecialchars($carousel['image_path']); ?>" class="item-image" alt="">
                            <div class="item-info">
                                <h4>Carousel Image #<?php echo $carousel['id']; ?></h4>
                                <p>Display order: <?php echo $carousel['display_order']; ?></p>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <p style="font-size: 13px; color: #666; margin-top: 15px;">
                        Showing last 3 images. Total: <?php echo $carousel_count; ?>
                    </p>
                    <?php else: ?>
                    <div class="empty-state">
                        <p>No carousel images added yet</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <h2>Quick Actions</h2>
            <div class="actions-grid">
                <a href="concept_manage_header.php" class="action-btn">
                    <span class="action-icon">✏️</span>
                    <span>Edit Header</span>
                </a>
                <a href="concept-manage-styles" class="action-btn">
                    <span class="action-icon">➕</span>
                    <span>Add New Style</span>
                </a>
                <a href="concept-manage-carousel" class="action-btn">
                    <span class="action-icon">🖼️</span>
                    <span>Add Carousel Image</span>
                </a>
                <a href="../concept-designs.php" class="action-btn" target="_blank">
                    <span class="action-icon">👁️</span>
                    <span>View Live Page</span>
                </a>
            </div>
        </div>
    </div>
</body>
</html>