<?php
//news_dashboard.php
include $includes ['mainbody'];

// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales']);

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

// Get statistics
$header_data = $conn->query("SELECT * FROM news_header LIMIT 1")->fetch_assoc();
$total_news = $conn->query("SELECT COUNT(*) as count FROM news")->fetch_assoc()['count'];
$published_news = $conn->query("SELECT COUNT(*) as count FROM news WHERE status='published'")->fetch_assoc()['count'];
$draft_news = $conn->query("SELECT COUNT(*) as count FROM news WHERE status='draft'")->fetch_assoc()['count'];
$featured_news = $conn->query("SELECT COUNT(*) as count FROM news WHERE featured=1")->fetch_assoc()['count'];
$total_views = $conn->query("SELECT SUM(views) as total FROM news")->fetch_assoc()['total'] ?? 0;

// Get recent news (last 5)
$recent_news = $conn->query("SELECT * FROM news ORDER BY date_uploaded DESC LIMIT 5");

// Get top viewed news (top 3)
$top_viewed = $conn->query("SELECT * FROM news ORDER BY views DESC LIMIT 3");

// Get categories with counts
$categories = $conn->query("SELECT category, COUNT(*) as count FROM news GROUP BY category ORDER BY count DESC LIMIT 5");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>News Page Dashboard</title>
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .icon-total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .icon-published { background: linear-gradient(135deg, #48c6ef 0%, #6f86d6 100%); }
        .icon-draft { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .icon-featured { background: linear-gradient(135deg, #ffd89b 0%, #19547b 100%); }
        .icon-views { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); }
        .stat-content h3 { font-size: 13px; color: #666; margin-bottom: 5px; font-weight: 500; }
        .stat-number { font-size: 32px; font-weight: bold; color: #3b1f0f; }
        
        /* Main Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        /* Cards */
        .card {
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
        .card-body { padding: 20px; }
        
        /* Buttons */
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
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        
        /* Header Preview */
        .header-preview {
            position: relative;
            height: 180px;
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
        .header-overlay h3 { font-size: 22px; margin-bottom: 8px; }
        .header-overlay p { font-size: 13px; opacity: 0.9; }
        
        /* News List */
        .news-list { display: flex; flex-direction: column; gap: 15px; }
        .news-item {
            display: flex;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            align-items: center;
            transition: background 0.2s;
        }
        .news-item:hover { background: #e9ecef; }
        .news-image {
            width: 100px;
            height: 70px;
            object-fit: cover;
            border-radius: 6px;
            flex-shrink: 0;
        }
        .news-content { flex: 1; min-width: 0; }
        .news-title {
            font-size: 15px;
            font-weight: 600;
            color: #3b1f0f;
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .news-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 12px;
            color: #666;
        }
        .news-meta span { display: flex; align-items: center; gap: 4px; }
        
        /* Badges */
        .badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-published { background: #d4edda; color: #155724; }
        .badge-draft { background: #d6d8db; color: #383d41; }
        .badge-featured { background: #fff3cd; color: #856404; }
        
        /* Category List */
        .category-list { display: flex; flex-direction: column; gap: 12px; }
        .category-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: #f8f9fa;
            border-radius: 6px;
        }
        .category-name { font-weight: 500; color: #3b1f0f; }
        .category-count {
            background: #3b1f0f;
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        
        /* Quick Actions */
        .quick-actions {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 30px;
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
        .action-btn:hover { transform: translateY(-2px); }
        .action-icon { font-size: 24px; }
        
        @media (max-width: 1024px) {
            .content-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .actions-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <h1>📰 News Page Dashboard</h1>
            <p>Manage your news articles, header, and content distribution</p>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-content">
                        <h3>Total Articles</h3>
                        <div class="stat-number"><?php echo $total_news; ?></div>
                    </div>
                    <div class="stat-icon icon-total">📝</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-content">
                        <h3>Published</h3>
                        <div class="stat-number"><?php echo $published_news; ?></div>
                    </div>
                    <div class="stat-icon icon-published">✅</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-content">
                        <h3>Drafts</h3>
                        <div class="stat-number"><?php echo $draft_news; ?></div>
                    </div>
                    <div class="stat-icon icon-draft">📄</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-content">
                        <h3>Featured</h3>
                        <div class="stat-number"><?php echo $featured_news; ?></div>
                    </div>
                    <div class="stat-icon icon-featured">⭐</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-content">
                        <h3>Total Views</h3>
                        <div class="stat-number"><?php echo number_format($total_views); ?></div>
                    </div>
                    <div class="stat-icon icon-views">👁️</div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <h2>Quick Actions</h2>
            <div class="actions-grid">
                <a href="news-manage" class="action-btn">
                    <span class="action-icon">➕</span>
                    <span>Add New Article</span>
                </a>
                <a href="news-manage-header" class="action-btn">
                    <span class="action-icon">✏️</span>
                    <span>Edit Header</span>
                </a>
                <a href="news-manage" class="action-btn">
                    <span class="action-icon">📋</span>
                    <span>Manage All Articles</span>
                </a>
                <a href="news" class="action-btn" target="_blank">
                    <span class="action-icon">👁️</span>
                    <span>View Live Page</span>
                </a>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Left Column -->
            <div style="display: flex; flex-direction: column; gap: 25px;">
                <!-- Page Header Card -->
                <div class="card">
                    <div class="card-header">
                        <h2>Page Header</h2>
                        <a href="news-manage-header" class="btn btn-primary btn-sm">Edit Header</a>
                    </div>
                    <div class="card-body">
                        <?php if ($header_data): ?>
                        <div class="header-preview" style="background-image: url('<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($header_data['header_image']); ?>')">
                            <div class="header-overlay">
                                <h3><?php echo htmlspecialchars($header_data['title']); ?></h3>
                                <p><?php echo htmlspecialchars(substr($header_data['subtitle'], 0, 120)) . '...'; ?></p>
                            </div>
                        </div>
                        <p style="font-size: 13px; color: #666;">Control the main header section with background image, title, and subtitle.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Articles Card -->
                <div class="card">
                    <div class="card-header">
                        <h2>Recent Articles</h2>
                        <a href="news-manage" class="btn btn-success btn-sm">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if ($recent_news->num_rows > 0): ?>
                        <div class="news-list">
                            <?php while ($news = $recent_news->fetch_assoc()): ?>
                            <div class="news-item">
                                <img src="<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($news['image']); ?>" class="news-image" alt="">
                                <div class="news-content">
                                    <div class="news-title"><?php echo htmlspecialchars($news['title']); ?></div>
                                    <div class="news-meta">
                                        <span class="badge badge-<?php echo $news['status']; ?>"><?php echo $news['status']; ?></span>
                                        <?php if ($news['featured']): ?>
                                            <span class="badge badge-featured">Featured</span>
                                        <?php endif; ?>
                                        <span>📅 <?php echo date('M d, Y', strtotime($news['date_uploaded'])); ?></span>
                                        <span>👁️ <?php echo $news['views']; ?> views</span>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <p>No articles yet. Create your first article!</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div style="display: flex; flex-direction: column; gap: 25px;">
                <!-- Top Viewed Articles Card -->
                <div class="card">
                    <div class="card-header">
                        <h2>Top Viewed</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($top_viewed->num_rows > 0): ?>
                        <div class="news-list">
                            <?php $rank = 1; while ($news = $top_viewed->fetch_assoc()): ?>
                            <div class="news-item">
                                <div style="font-size: 24px; font-weight: bold; color: #8a5a44; width: 30px; text-align: center;">
                                    <?php echo $rank++; ?>
                                </div>
                                <div class="news-content">
                                    <div class="news-title"><?php echo htmlspecialchars($news['title']); ?></div>
                                    <div class="news-meta">
                                        <span>👁️ <?php echo number_format($news['views']); ?> views</span>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <p>No view data yet</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Categories Card -->
                <div class="card">
                    <div class="card-header">
                        <h2>Top Categories</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($categories->num_rows > 0): ?>
                        <div class="category-list">
                            <?php while ($cat = $categories->fetch_assoc()): ?>
                            <div class="category-item">
                                <span class="category-name"><?php echo htmlspecialchars($cat['category']); ?></span>
                                <span class="category-count"><?php echo $cat['count']; ?></span>
                            </div>
                            <?php endwhile; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <p>No categories yet</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>