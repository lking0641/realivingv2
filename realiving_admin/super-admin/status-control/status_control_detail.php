<?php
// status_control_detail.php
include $includes['mainbody'];

require_role(['super_admin']);

$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

$cStmt = $conn->prepare("SELECT * FROM user_info WHERE id = ?");
$cStmt->bind_param("i", $client_id);
$cStmt->execute();
$client = $cStmt->get_result()->fetch_assoc();
if (!$client) die("Client not found.");

// ── Fetch Tracker Stages ──
$tStmt = $conn->prepare("SELECT * FROM project_tracker WHERE client_id = ? ORDER BY id ASC");
$tStmt->bind_param("i", $client_id);
$tStmt->execute();
$stages = $tStmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($stages as &$stageRef) {
    $fStmt = $conn->prepare("
        SELECT sa.*, a.full_name as uploaded_by_name
        FROM stage_approvals sa
        LEFT JOIN account a ON sa.uploaded_by = a.id
        WHERE sa.stage_id = ?
        ORDER BY sa.uploaded_at DESC
    ");
    $fStmt->bind_param("i", $stageRef['id']);
    $fStmt->execute();
    $stageRef['files'] = $fStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
unset($stageRef);

// ── Fetch Payment Schedule ──
function getPaymentProofRecord($conn, $payment_id) {
    $stmt = $conn->prepare("
        SELECT pp.*, par.id as review_id, par.review_status, par.rejection_note
        FROM payment_proofs pp
        LEFT JOIN payment_accounting_reviews par ON par.payment_id = pp.payment_id
        WHERE pp.payment_id = ?
        ORDER BY pp.id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}
function getNTPRecord($conn, $payment_id) {
    $stmt = $conn->prepare("SELECT * FROM notice_to_proceed WHERE payment_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

$pStmt = $conn->prepare("SELECT * FROM payment_schedule WHERE client_id = ? ORDER BY id ASC");
$pStmt->bind_param("i", $client_id);
$pStmt->execute();
$payments = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($payments as &$payRef) {
    $payRef['proof'] = getPaymentProofRecord($conn, $payRef['id']);
    $payRef['ntp'] = getNTPRecord($conn, $payRef['id']);
}
unset($payRef);

// ── Fetch Site Visits ──
$siteVisitsStmt = $conn->prepare("
    SELECT sv.*,
           a1.full_name as designer1_name,
           a2.full_name as designer2_name
    FROM site_visit sv
    LEFT JOIN account a1 ON sv.designer1_id = a1.id
    LEFT JOIN account a2 ON sv.designer2_id = a2.id
    WHERE sv.client_id = ?
    ORDER BY sv.visit_date DESC
");
$siteVisitsStmt->bind_param("i", $client_id);
$siteVisitsStmt->execute();
$siteVisits = $siteVisitsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Status Control — <?= htmlspecialchars($client['clientname']) ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --adm-bg: #F5F5F5;
      --adm-surface: #FFFFFF;
      --adm-ink: #0B0B0B;
      --adm-soft: #6B6B6B;
      --adm-muted: #9A9A9A;
      --adm-line: #E2E2E2;
      --adm-online: #16A34A;
      --adm-suspend: #DC2626;
      --primary: #3b82f6;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--adm-bg);
      color: var(--adm-ink);
    }

    .container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }

    /* ── Header ── */
    .page-header {
      background: linear-gradient(135deg, #2d3748 0%, #1a202c 100%);
      padding: 30px 40px;
      border-radius: 12px;
      color: white;
      margin-bottom: 30px;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 20px;
      flex-wrap: wrap;
    }

    .header-content h1 {
      font-size: 24px;
      margin-bottom: 8px;
      font-weight: 700;
    }

    .header-meta {
      font-size: 13px;
      opacity: 0.85;
      display: flex;
      gap: 20px;
      flex-wrap: wrap;
      margin-top: 12px;
    }

    .meta-item {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .btn-back {
      background: rgba(255, 255, 255, 0.2);
      color: white;
      padding: 9px 18px;
      border: 1px solid rgba(255, 255, 255, 0.3);
      border-radius: 8px;
      cursor: pointer;
      font-weight: 600;
      font-size: 13px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      transition: all 0.2s;
      margin-bottom: 20px;
    }

    .btn-back:hover {
      background: rgba(255, 255, 255, 0.3);
    }

    /* ── Tabs ── */
    .tabs-wrapper {
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      margin-bottom: 24px;
      overflow: hidden;
    }

    .tabs-header {
      display: flex;
      border-bottom: 2px solid var(--adm-line);
      background: var(--adm-bg);
      padding: 0;
    }

    .tab-btn {
      flex: 1;
      padding: 16px 20px;
      border: none;
      background: transparent;
      cursor: pointer;
      font-size: 13px;
      font-weight: 600;
      color: var(--adm-soft);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: all 0.2s;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      border-bottom: 3px solid transparent;
      margin-bottom: -2px;
    }

    .tab-btn:hover {
      color: var(--adm-ink);
      background: rgba(0, 0, 0, 0.02);
    }

    .tab-btn.active {
      color: var(--primary);
      border-bottom-color: var(--primary);
      background: var(--adm-surface);
    }

    .tabs-content {
      padding: 24px;
      min-height: 400px;
    }

    .tab-pane {
      display: none;
    }

    .tab-pane.active {
      display: block;
      animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(5px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* ── Section Title ── */
    .section-title {
      font-size: 16px;
      font-weight: 700;
      color: var(--adm-ink);
      margin-bottom: 18px;
      padding-bottom: 12px;
      border-bottom: 2px solid var(--adm-line);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .section-title i {
      color: var(--primary);
      font-size: 18px;
    }

    /* ── Stage/Payment Blocks ── */
    .item-block {
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      margin-bottom: 14px;
      overflow: hidden;
      transition: all 0.2s;
    }

    .item-block:hover {
      border-color: var(--adm-ink);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }

    .item-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 16px 18px;
      background: var(--adm-surface);
      border-bottom: 1px solid var(--adm-line);
      flex-wrap: wrap;
      gap: 12px;
    }

    .item-title {
      font-weight: 600;
      font-size: 14px;
      color: var(--adm-ink);
    }

    .item-meta {
      font-size: 12px;
      color: var(--adm-soft);
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .item-content {
      padding: 16px 18px;
    }

    .status-select, .select-control {
      padding: 7px 12px;
      border-radius: 7px;
      border: 1px solid var(--adm-line);
      font-size: 13px;
      font-weight: 600;
      background: white;
      cursor: pointer;
      transition: all 0.2s;
    }

    .status-select:hover, .select-control:hover {
      border-color: var(--adm-ink);
    }

    .status-select:focus, .select-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .save-flag {
      font-size: 11px;
      color: var(--success);
      font-weight: 700;
      display: none;
      align-items: center;
      gap: 5px;
    }

    .save-flag.show {
      display: inline-flex;
    }

    /* ── File/Report Rows ── */
    .file-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 14px;
      border-bottom: 1px solid var(--adm-line);
      gap: 12px;
      flex-wrap: wrap;
    }

    .file-row:last-child {
      border-bottom: none;
    }

    .file-row:hover {
      background: var(--adm-bg);
    }

    .file-info {
      flex: 1;
      min-width: 200px;
    }

    .file-label {
      font-size: 13px;
      font-weight: 600;
      color: var(--adm-ink);
    }

    .file-meta {
      font-size: 11px;
      color: var(--adm-soft);
      margin-top: 3px;
    }

    .file-controls {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-shrink: 0;
      flex-wrap: wrap;
    }

    .status-badge {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      padding: 4px 10px;
      border-radius: 20px;
      white-space: nowrap;
    }

    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    .status-badge.pending { background: #fef3c7; color: #92400e; }
    .status-badge.submitted { background: #dbeafe; color: #1e40af; }

    .btn-sm {
      padding: 6px 12px;
      border: 1px solid var(--adm-line);
      border-radius: 6px;
      background: white;
      color: var(--adm-ink);
      cursor: pointer;
      font-size: 11.5px;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      transition: all 0.2s;
    }

    .btn-sm:hover {
      background: var(--adm-bg);
      border-color: var(--adm-ink);
    }

    .btn-danger {
      background: #fee2e2;
      color: #991b1b;
      border-color: #fecaca;
    }

    .btn-danger:hover {
      background: #fecaca;
    }

    .btn-success {
      background: #d1fae5;
      color: #065f46;
      border-color: #a7f3d0;
    }

    .btn-success:hover {
      background: #a7f3d0;
    }

    /* ── Empty State ── */
    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: var(--adm-soft);
    }

    .empty-state i {
      font-size: 40px;
      margin-bottom: 12px;
      display: block;
      opacity: 0.5;
    }

    /* ── Status Pills ── */
    .status-pill {
      display: inline-block;
      padding: 5px 14px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .status-pill.pending { background: #fef3c7; color: #92400e; }
    .status-pill.ongoing { background: #dbeafe; color: #1e40af; }
    .status-pill.done { background: #d1fae5; color: #065f46; }

    /* ── Toast ── */
    .toast {
      position: fixed;
      bottom: 24px;
      right: 24px;
      background: var(--adm-ink);
      color: white;
      padding: 14px 20px;
      border-radius: 8px;
      font-size: 13px;
      opacity: 0;
      transform: translateY(20px);
      transition: all 0.3s;
      pointer-events: none;
      z-index: 9999;
    }

    .toast.show {
      opacity: 1;
      transform: translateY(0);
      pointer-events: auto;
    }

    .toast.error {
      background: var(--danger);
    }

    .toast.success {
      background: var(--success);
    }

    /* ── Responsive ── */
    @media (max-width: 768px) {
      .page-header {
        flex-direction: column;
      }

      .tab-btn {
        padding: 12px 10px;
        font-size: 12px;
      }

      .item-header {
        flex-direction: column;
        align-items: flex-start;
      }

      .file-row {
        flex-direction: column;
        align-items: flex-start;
      }
    }
  </style>
</head>
<body>
  <div class="container">

    <!-- Back Button -->
    <a href="<?= BASE_URL ?>status-control" class="btn-back" style="display: inline-flex; align-items: center; gap: 8px; background: var(--adm-surface); color: var(--adm-ink); padding: 10px 20px; border: 1px solid var(--adm-line); border-radius: 8px; font-weight: 600; font-size: 13px; text-decoration: none; margin-bottom: 24px; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.08);">
      <i class="fas fa-arrow-left"></i> Back to Clients
    </a>

    <!-- Header -->
    <div class="page-header">
      <div class="header-content">
        <h1><?= htmlspecialchars($client['clientname']) ?></h1>
        <div class="header-meta">
          <div class="meta-item">
            <i class="fas fa-briefcase"></i>
            <span><?= htmlspecialchars($client['nameproject']) ?></span>
          </div>
          <div class="meta-item">
            <i class="fas fa-hashtag"></i>
            <span><?= htmlspecialchars($client['reference_number']) ?></span>
          </div>
          <div class="meta-item">
            <i class="fas fa-peso-sign"></i>
            <span>₱<?= number_format($client['total_project_cost'], 2) ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabs Container -->
    <div class="tabs-wrapper">
      <div class="tabs-header">
        <button class="tab-btn active" onclick="switchTab('stages')">
          <i class="fas fa-tasks"></i> Stages
        </button>
        <button class="tab-btn" onclick="switchTab('payments')">
          <i class="fas fa-money-bill-wave"></i> Payments
        </button>
        <button class="tab-btn" onclick="switchTab('visits')">
          <i class="fas fa-map-marker-alt"></i> Site Visits
        </button>
      </div>

      <div class="tabs-content">

        <!-- ══════════════════════════════════════════ -->
        <!-- TAB: STAGES ──────────────────────────────── -->
        <!-- ══════════════════════════════════════════ -->
        <div class="tab-pane active" id="stages">
          <div class="section-title">
            <i class="fas fa-tasks"></i> Project Tracker Stages
          </div>

          <?php if (empty($stages)): ?>
            <div class="empty-state">
              <i class="fas fa-inbox"></i>
              <p>No tracker stages found for this client.</p>
            </div>
          <?php else: ?>
            <?php foreach ($stages as $stage): ?>
              <div class="item-block">
                <div class="item-header">
                  <div>
                    <div class="item-title"><?= htmlspecialchars($stage['stage_name']) ?></div>
                    <div class="item-meta">
                      <span>Stage ID: <?= $stage['id'] ?></span>
                      <span><?= count($stage['files']) ?> file(s)</span>
                    </div>
                  </div>
                  <div style="display: flex; align-items: center; gap: 12px;">
                    <select class="status-select" data-stage-id="<?= $stage['id'] ?>" onchange="updateStageStatus(this)">
                      <option value="Pending" <?= $stage['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                      <option value="Ongoing" <?= $stage['status'] === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
                      <option value="Done" <?= $stage['status'] === 'Done' ? 'selected' : '' ?>>Done</option>
                    </select>
                    <span class="save-flag" id="flag-<?= $stage['id'] ?>">
                      <i class="fas fa-check"></i> Saved
                    </span>
                  </div>
                </div>

                <?php if (!empty($stage['files'])): ?>
                  <div class="item-content">
                    <?php foreach ($stage['files'] as $file): ?>
                      <div class="file-row" id="file-row-<?= $file['id'] ?>">
                        <div class="file-info">
                          <div class="file-label"><?= htmlspecialchars($file['label'] ?: $file['file_name']) ?></div>
                          <div class="file-meta">
                            Uploaded by <?= htmlspecialchars($file['uploaded_by_name'] ?? 'Unknown') ?> •
                            <?= date('M d, Y g:i A', strtotime($file['uploaded_at'])) ?>
                          </div>
                        </div>
                        <div class="file-controls">
                          <span class="status-badge <?= strtolower($file['approval_status']) ?>">
                            <?= ucfirst($file['approval_status']) ?>
                          </span>
                          <select class="select-control" data-approval-id="<?= $file['id'] ?>" onchange="updateFileStatus(this)" style="font-size:12px;">
                            <option value="pending" <?= $file['approval_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= $file['approval_status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="rejected" <?= $file['approval_status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                          </select>
                          <button class="btn-sm btn-danger" onclick="resetFileApproval(<?= $file['id'] ?>)" title="Delete file and reset">
                            <i class="fas fa-trash"></i> Delete
                          </button>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- ══════════════════════════════════════════ -->
        <!-- TAB: PAYMENTS ────────────────────────────── -->
        <!-- ══════════════════════════════════════════ -->
        <div class="tab-pane" id="payments">
          <div class="section-title">
            <i class="fas fa-money-bill-wave"></i> Payment Schedule
          </div>

          <?php if (empty($payments)): ?>
            <div class="empty-state">
              <i class="fas fa-inbox"></i>
              <p>No payment schedule entries found for this client.</p>
            </div>
          <?php else: ?>
            <?php foreach ($payments as $payment): ?>
              <div class="item-block">
                <div class="item-header">
                  <div>
                    <div class="item-title"><?= htmlspecialchars($payment['payment_type']) ?></div>
                    <div class="item-meta">
                      <span>ID: <?= $payment['id'] ?></span>
                      <span>₱<?= number_format($payment['amount'], 2) ?></span>
                      <?php if ($payment['payment_date']): ?>
                        <span>Paid: <?= date('M d, Y', strtotime($payment['payment_date'])) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div style="display: flex; align-items: center; gap: 12px;">
                    <select class="status-select" data-payment-id="<?= $payment['id'] ?>" onchange="updatePaymentStatus(this)">
                      <option value="Pending" <?= $payment['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                      <option value="Paid" <?= $payment['status'] === 'Paid' ? 'selected' : '' ?>>Paid</option>
                      <option value="Not Available" <?= $payment['status'] === 'Not Available' ? 'selected' : '' ?>>Not Available</option>
                    </select>
                    <span class="save-flag" id="pay-flag-<?= $payment['id'] ?>">
                      <i class="fas fa-check"></i> Saved
                    </span>
                  </div>
                </div>

                <?php if ($payment['proof'] || $payment['ntp']): ?>
                  <div class="item-content">
                    <?php if ($payment['proof']): ?>
                      <div class="file-row">
                        <div class="file-info">
                          <div class="file-label">Payment Proof</div>
                          <div class="file-meta">
                            <?= htmlspecialchars($payment['proof']['file_name'] ?? '') ?>
                            <?php if (!empty($payment['proof']['review_status'])): ?>
                              • Review: <?= htmlspecialchars($payment['proof']['review_status']) ?>
                            <?php endif; ?>
                          </div>
                        </div>
                        <div class="file-controls">
                          <button class="btn-sm btn-danger" onclick="resetPaymentItem(<?= $payment['id'] ?>, 'proof')">
                            <i class="fas fa-trash"></i> Delete Proof
                          </button>
                        </div>
                      </div>
                    <?php endif; ?>

                    <?php if ($payment['ntp']): ?>
                      <div class="file-row">
                        <div class="file-info">
                          <div class="file-label">Notice to Proceed (NTP)</div>
                          <div class="file-meta"><?= htmlspecialchars($payment['ntp']['file_name'] ?? '') ?></div>
                        </div>
                        <div class="file-controls">
                          <button class="btn-sm btn-danger" onclick="resetPaymentItem(<?= $payment['id'] ?>, 'ntp')">
                            <i class="fas fa-trash"></i> Delete NTP
                          </button>
                        </div>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- ══════════════════════════════════════════ -->
        <!-- TAB: SITE VISITS ──────────────────────────── -->
        <!-- ══════════════════════════════════════════ -->
        <div class="tab-pane" id="visits">
          <div class="section-title">
            <i class="fas fa-map-marker-alt"></i> Site Visits
          </div>

          <?php if (empty($siteVisits)): ?>
            <div class="empty-state">
              <i class="fas fa-inbox"></i>
              <p>No site visits scheduled for this client.</p>
            </div>
          <?php else: ?>
            <?php foreach ($siteVisits as $visit): ?>
              <div class="item-block">
                <div class="item-header">
                  <div>
                    <div class="item-title">
                      <?= date('F d, Y', strtotime($visit['visit_date'])) ?>
                      <?php if (!empty($visit['visit_time'])): ?>
                        at <?= date('g:i A', strtotime($visit['visit_time'])) ?>
                      <?php endif; ?>
                    </div>
                    <div class="item-meta">
                      <span>
                        <i class="fas fa-user-tie"></i>
                        <?= htmlspecialchars($visit['designer1_name']) ?>
                      </span>
                      <?php if ($visit['designer2_name']): ?>
                        <span>
                          <i class="fas fa-user-tie"></i>
                          <?= htmlspecialchars($visit['designer2_name']) ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div style="display: flex; align-items: center; gap: 12px;">
                    <span class="status-pill <?= strtolower($visit['status']) ?>">
                      <?= $visit['status'] ?>
                    </span>
                    <select class="status-select" data-visit-id="<?= $visit['id'] ?>" onchange="updateVisitStatus(this)">
                      <option value="Pending" <?= $visit['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                      <option value="Ongoing" <?= $visit['status'] === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
                      <option value="Done" <?= $visit['status'] === 'Done' ? 'selected' : '' ?>>Done</option>
                    </select>
                    <span class="save-flag" id="visit-flag-<?= $visit['id'] ?>">
                      <i class="fas fa-check"></i> Saved
                    </span>
                  </div>
                </div>

                <?php if ($visit['designer1_finished'] || $visit['designer2_finished']): ?>
                  <div class="item-content">
                    <?php if ($visit['designer1_finished']): ?>
                      <div class="file-row">
                        <div class="file-info">
                          <div class="file-label">
                            <i class="fas fa-check-circle" style="color: var(--success);"></i>
                            <?= htmlspecialchars($visit['designer1_name']) ?> - Report Submitted
                          </div>
                          <div class="file-meta">
                            Submitted: <?= date('M d, Y g:i A', strtotime($visit['designer1_finished_at'])) ?>
                          </div>
                        </div>
                        <div class="file-controls">
                          <button class="btn-sm btn-danger" onclick="resetDesignerReport(<?= $visit['id'] ?>, 'designer1')">
                            <i class="fas fa-undo"></i> Reset D1
                          </button>
                        </div>
                      </div>
                    <?php endif; ?>

                    <?php if ($visit['designer2_finished']): ?>
                      <div class="file-row">
                        <div class="file-info">
                          <div class="file-label">
                            <i class="fas fa-check-circle" style="color: var(--success);"></i>
                            <?= htmlspecialchars($visit['designer2_name']) ?> - Report Submitted
                          </div>
                          <div class="file-meta">
                            Submitted: <?= date('M d, Y g:i A', strtotime($visit['designer2_finished_at'])) ?>
                          </div>
                        </div>
                        <div class="file-controls">
                          <button class="btn-sm btn-danger" onclick="resetDesignerReport(<?= $visit['id'] ?>, 'designer2')">
                            <i class="fas fa-undo"></i> Reset D2
                          </button>
                        </div>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>
    </div>

  </div>

  <!-- Toast -->
  <div id="toast" class="toast"></div>

  <script>
    // ── Tab Switching ──
    function switchTab(tabName) {
      // Hide all panes
      document.querySelectorAll('.tab-pane').forEach(pane => {
        pane.classList.remove('active');
      });

      // Remove active from all buttons
      document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
      });

      // Show selected pane
      document.getElementById(tabName).classList.add('active');

      // Activate button
      event.target.closest('.tab-btn').classList.add('active');
    }

    // ── Toast Notification ──
    function showToast(msg, type = 'success') {
      const toast = document.getElementById('toast');
      toast.textContent = msg;
      toast.className = 'toast ' + type + ' show';
      setTimeout(() => {
        toast.classList.remove('show');
      }, 3000);
    }

    // ── Stage Status Update ──
    async function updateStageStatus(sel) {
      const stageId = sel.dataset.stageId;
      const status = sel.value;
      try {
        const res = await fetch('<?= BASE_URL ?>status-control-update', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ stage_id: stageId, status: status })
        });
        const data = await res.json();
        if (data.success) {
          const flag = document.getElementById('flag-' + stageId);
          flag.classList.add('show');
          setTimeout(() => flag.classList.remove('show'), 1500);
          showToast('Stage status updated!', 'success');
        } else {
          showToast('Error: ' + (data.error || 'Failed'), 'error');
        }
      } catch (e) {
        showToast('Connection error', 'error');
      }
    }

    // ── File Status Update ──
    async function updateFileStatus(sel) {
      const approvalId = sel.dataset.approvalId;
      const status = sel.value;
      if (!confirm('Change this file status to "' + status + '"?')) {
        location.reload();
        return;
      }
      try {
        const res = await fetch('<?= BASE_URL ?>status-control-file-update', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ approval_id: approvalId, action: 'set_status', status: status })
        });
        const data = await res.json();
        if (data.success) {
          showToast('File status updated!', 'success');
          setTimeout(() => location.reload(), 900);
        } else {
          showToast('Error: ' + (data.error || 'Failed'), 'error');
        }
      } catch (e) {
        showToast('Connection error', 'error');
      }
    }

    // ── Reset File Approval ──
    async function resetFileApproval(approvalId) {
      if (!confirm('Delete this file completely? This cannot be undone.')) return;
      try {
        const res = await fetch('<?= BASE_URL ?>status-control-file-update', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ approval_id: approvalId, action: 'reset' })
        });
        const data = await res.json();
        if (data.success) {
          showToast('File deleted successfully!', 'success');
          setTimeout(() => location.reload(), 900);
        } else {
          showToast('Error: ' + (data.error || 'Failed'), 'error');
        }
      } catch (e) {
        showToast('Connection error', 'error');
      }
    }

    // ── Payment Status Update ──
    async function updatePaymentStatus(sel) {
      const paymentId = sel.dataset.paymentId;
      const status = sel.value;
      if (!confirm('Change this payment status to "' + status + '"?')) {
        location.reload();
        return;
      }
      try {
        const res = await fetch('<?= BASE_URL ?>status-control-payment-update', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ payment_id: paymentId, action: 'set_status', status: status })
        });
        const data = await res.json();
        if (data.success) {
          const flag = document.getElementById('pay-flag-' + paymentId);
          flag.classList.add('show');
          setTimeout(() => flag.classList.remove('show'), 1500);
          showToast('Payment status updated!', 'success');
        } else {
          showToast('Error: ' + (data.error || 'Failed'), 'error');
        }
      } catch (e) {
        showToast('Connection error', 'error');
      }
    }

    // ── Reset Payment Item ──
    async function resetPaymentItem(paymentId, type) {
      const msg = type === 'proof'
        ? 'Delete this payment proof? The payment will revert to Pending.'
        : 'Delete this NTP file?';
      if (!confirm(msg)) return;
      try {
        const res = await fetch('<?= BASE_URL ?>status-control-payment-update', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ payment_id: paymentId, action: 'reset_' + type })
        });
        const data = await res.json();
        if (data.success) {
          showToast('Deleted successfully!', 'success');
          setTimeout(() => location.reload(), 900);
        } else {
          showToast('Error: ' + (data.error || 'Failed'), 'error');
        }
      } catch (e) {
        showToast('Connection error', 'error');
      }
    }

    // ── Site Visit Status Update ──
    async function updateVisitStatus(sel) {
      const visitId = sel.dataset.visitId;
      const status = sel.value;
      try {
        const res = await fetch('<?= BASE_URL ?>site-visit-status-update', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ visit_id: visitId, status: status })
        });
        const data = await res.json();
        if (data.success) {
          const flag = document.getElementById('visit-flag-' + visitId);
          flag.classList.add('show');
          setTimeout(() => flag.classList.remove('show'), 1500);
          showToast('Site visit status updated!', 'success');
        } else {
          showToast('Error: ' + (data.error || 'Failed'), 'error');
        }
      } catch (e) {
        showToast('Connection error', 'error');
      }
    }

    // ── Reset Designer Report ──
    async function resetDesignerReport(visitId, designer) {
      if (!confirm('Delete this designer\'s report and photo? This cannot be undone.')) return;
      try {
        const res = await fetch('<?= BASE_URL ?>site-visit-reset', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ visit_id: visitId, action: 'reset_' + designer + '_report' })
        });
        const data = await res.json();
        if (data.success) {
          showToast('Report reset successfully!', 'success');
          setTimeout(() => location.reload(), 900);
        } else {
          showToast('Error: ' + (data.error || 'Failed'), 'error');
        }
      } catch (e) {
        showToast('Connection error', 'error');
      }
    }
  </script>
</body>
</html>