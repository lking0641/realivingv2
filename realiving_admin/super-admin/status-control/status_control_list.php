<?php
// status_control_list.php
include $includes['mainbody'];

require_role(['super_admin']); // adjust exact string kung 'superadmin' pala sa DB mo

$search = isset($_GET['q']) ? trim($_GET['q']) : '';

$sql = "SELECT u.id, u.clientname, u.nameproject, u.reference_number, u.account_status,
               a.full_name as admin_name
        FROM user_info u
        LEFT JOIN account a ON u.accountaid_fk = a.id";
$params = [];
$types = '';
if ($search !== '') {
    $sql .= " WHERE u.clientname LIKE ? OR u.nameproject LIKE ? OR u.reference_number LIKE ?";
    $like = "%{$search}%";
    $params = [$like, $like, $like];
    $types = 'sss';
}
$sql .= " ORDER BY u.created_at DESC";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$clients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Status Control — Super Admin</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --adm-bg:#F5F5F5; --adm-surface:#FFFFFF; --adm-ink:#0B0B0B; --adm-soft:#6B6B6B; --adm-line:#E2E2E2; }
    body { font-family:'Inter',sans-serif; background:var(--adm-bg); color:var(--adm-ink); }
    .wrap { max-width:1100px; margin:0 auto; padding:40px 20px; }
    .title { font-size:26px; font-weight:700; margin-bottom:6px; }
    .sub { font-size:13.5px; color:var(--adm-soft); margin-bottom:24px; }
    .back { font-size:13px; color:var(--adm-soft); text-decoration:none; display:inline-block; margin-bottom:16px; }
    .search-box { margin-bottom:20px; }
    .search-box input { width:100%; padding:10px 14px; border:1px solid var(--adm-line); border-radius:8px; font-size:14px; }
    .client-row {
      display:flex; align-items:center; justify-content:space-between;
      background:var(--adm-surface); border:1px solid var(--adm-line); border-radius:10px;
      padding:14px 18px; margin-bottom:10px;
    }
    .client-name { font-weight:600; font-size:14px; }
    .client-meta { font-size:12px; color:var(--adm-soft); margin-top:2px; }
    .btn-manage {
      background:var(--adm-ink); color:#fff; padding:8px 16px; border-radius:7px;
      font-size:12.5px; font-weight:600; text-decoration:none;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <a href="<?= BASE_URL ?>admin-mainpage" class="back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    <div class="title">Client Status Control</div>
    <div class="sub">Directly override any client's tracker stage status. Super Admin only.</div>

    <form class="search-box" method="GET">
      <input type="text" name="q" placeholder="Search client, project, or reference..." value="<?= htmlspecialchars($search) ?>">
    </form>

    <?php if (empty($clients)): ?>
      <p style="color:var(--adm-soft);">No clients found.</p>
    <?php else: foreach ($clients as $c): ?>
      <div class="client-row">
        <div>
          <div class="client-name"><?= htmlspecialchars($c['clientname']) ?> — <?= htmlspecialchars($c['nameproject']) ?></div>
          <div class="client-meta">
            Ref: <?= htmlspecialchars($c['reference_number']) ?> · Status: <?= htmlspecialchars($c['account_status'] ?? 'Active') ?>
            <?php if ($c['admin_name']): ?> · Assigned: <?= htmlspecialchars($c['admin_name']) ?><?php endif; ?>
          </div>
        </div>
        <a href="<?= BASE_URL ?>status-control-detail?client_id=<?= $c['id'] ?>" class="btn-manage">
          <i class="fas fa-sliders"></i> Manage Statuses
        </a>
      </div>
    <?php endforeach; endif; ?>
  </div>
</body>
</html>