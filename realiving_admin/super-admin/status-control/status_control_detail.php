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

$tStmt = $conn->prepare("SELECT * FROM project_tracker WHERE client_id = ? ORDER BY id ASC");
$tStmt->bind_param("i", $client_id);
$tStmt->execute();
$stages = $tStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch files (stage_approvals) for each stage
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

// ── Fetch payment schedule for this client ──
function getPaymentProofRecord($conn, $payment_id)
{
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
function getNTPRecord($conn, $payment_id)
{
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Status Control — <?= htmlspecialchars($client['clientname']) ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --adm-bg:#F5F5F5; --adm-surface:#FFFFFF; --adm-ink:#0B0B0B; --adm-soft:#6B6B6B; --adm-line:#E2E2E2; }
    body { font-family:'Inter',sans-serif; background:var(--adm-bg); color:var(--adm-ink); }
    .wrap { max-width:900px; margin:0 auto; padding:40px 20px; }
    .back { font-size:13px; color:var(--adm-soft); text-decoration:none; display:inline-block; margin-bottom:16px; }
    .title { font-size:24px; font-weight:700; margin-bottom:4px; }
    .sub { font-size:13.5px; color:var(--adm-soft); margin-bottom:24px; }
    .stage-row {
      display:flex; align-items:center; justify-content:space-between;
      background:var(--adm-surface); border:1px solid var(--adm-line); border-radius:10px 10px 0 0;
      padding:14px 18px;
    }
    .stage-name { font-weight:600; font-size:14px; }
    .stage-id { font-size:11px; color:var(--adm-soft); }
    select.status-select {
      padding:7px 12px; border-radius:7px; border:1px solid var(--adm-line); font-size:13px; font-weight:600;
    }
    .save-flag { font-size:11px; color:#16A34A; margin-left:8px; display:none; }
    .toast {
      position:fixed; bottom:24px; right:24px; background:var(--adm-ink); color:#fff;
      padding:12px 20px; border-radius:8px; font-size:13px; opacity:0; transform:translateY(20px);
      transition:all .3s; pointer-events:none;
    }
    .toast.show { opacity:1; transform:translateY(0); }

    .stage-block { margin-bottom:14px; }
    .file-list {
      background:#fafafa; border:1px solid var(--adm-line); border-top:none;
      border-radius:0 0 10px 10px; padding:8px 18px;
    }
    .file-row {
      display:flex; align-items:center; justify-content:space-between;
      padding:10px 0; border-bottom:1px solid var(--adm-line); gap:12px; flex-wrap:wrap;
    }
    .file-row:last-child { border-bottom:none; }
    .file-label { font-size:13px; font-weight:600; }
    .file-meta { font-size:11px; color:var(--adm-soft); margin-top:2px; }
    .file-controls { display:flex; align-items:center; gap:8px; flex-shrink:0; }
    .file-status-badge {
      font-size:10px; font-weight:700; text-transform:uppercase; padding:3px 9px; border-radius:20px;
    }
    .file-status-badge.status-approved { background:#d1fae5; color:#065f46; }
    .file-status-badge.status-rejected { background:#fee2e2; color:#991b1b; }
    .file-status-badge.status-pending { background:#fef3c7; color:#92400e; }
    .file-status-select {
      padding:5px 10px; border-radius:6px; border:1px solid var(--adm-line); font-size:12px;
    }
    .btn-reset {
      background:#f3f4f6; color:#374151; border:1px solid var(--adm-line); border-radius:6px;
      padding:5px 10px; font-size:11px; font-weight:600; cursor:pointer;
    }
    .btn-reset:hover { background:#e5e7eb; }
  </style>
</head>
<body>
  <div class="wrap">
    <a href="<?= BASE_URL ?>status-control" class="back"><i class="fas fa-arrow-left"></i> Back to Client List</a>
    <div class="title"><?= htmlspecialchars($client['clientname']) ?></div>
    <div class="sub"><?= htmlspecialchars($client['nameproject']) ?> · Ref: <?= htmlspecialchars($client['reference_number']) ?></div>

    <?php if (empty($stages)): ?>
      <p style="color:var(--adm-soft);">No tracker stages found for this client yet.</p>
    <?php else: foreach ($stages as $s): ?>
      <div class="stage-block">
        <div class="stage-row">
          <div>
            <div class="stage-name"><?= htmlspecialchars($s['stage_name']) ?></div>
            <div class="stage-id">Stage ID: <?= $s['id'] ?> · <?= count($s['files']) ?> file(s)</div>
          </div>
          <div>
            <select class="status-select" data-stage-id="<?= $s['id'] ?>" onchange="updateStatus(this)">
              <option value="Pending" <?= $s['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
              <option value="Ongoing" <?= $s['status'] === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
              <option value="Done" <?= $s['status'] === 'Done' ? 'selected' : '' ?>>Done</option>
            </select>
            <span class="save-flag" id="flag-<?= $s['id'] ?>"><i class="fas fa-check"></i> Saved</span>
          </div>
        </div>

        <?php if (!empty($s['files'])): ?>
          <div class="file-list">
            <?php foreach ($s['files'] as $f): ?>
              <div class="file-row" id="file-row-<?= $f['id'] ?>">
                <div class="file-info">
                  <div class="file-label"><?= htmlspecialchars($f['label'] ?: $f['file_name']) ?></div>
                  <div class="file-meta">
                    Uploaded by <?= htmlspecialchars($f['uploaded_by_name'] ?? 'Unknown') ?>
                    · <?= date('M d, Y g:i A', strtotime($f['uploaded_at'])) ?>
                  </div>
                </div>
                <div class="file-controls">
                  <span class="file-status-badge status-<?= strtolower($f['approval_status']) ?>" id="badge-<?= $f['id'] ?>">
                    <?= ucfirst($f['approval_status']) ?>
                  </span>
                  <select class="file-status-select" data-approval-id="<?= $f['id'] ?>" onchange="updateFileStatus(this)">
                    <option value="pending" <?= $f['approval_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $f['approval_status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $f['approval_status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                  </select>
                  <button class="btn-reset" onclick="resetFileApproval(<?= $f['id'] ?>)" title="Delete this file entirely so the assignee can re-upload">
                    <i class="fas fa-trash"></i> Delete & Reset
                  </button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>

    <div style="margin-top:36px;">
      <div class="title" style="font-size:20px;">Payment Schedule</div>
      <div class="sub" style="margin-bottom:16px;">Control payment statuses, proofs, and NTP files.</div>

      <?php if (empty($payments)): ?>
        <p style="color:var(--adm-soft);">No payment schedule entries found for this client yet.</p>
      <?php else: foreach ($payments as $p): ?>
        <div class="stage-block">
          <div class="stage-row">
            <div>
              <div class="stage-name"><?= htmlspecialchars($p['payment_type']) ?></div>
              <div class="stage-id">
                Payment ID: <?= $p['id'] ?> · ₱<?= number_format($p['amount'], 2) ?>
                <?php if ($p['payment_date']): ?> · Paid: <?= date('M d, Y', strtotime($p['payment_date'])) ?><?php endif; ?>
              </div>
            </div>
            <div>
              <select class="status-select" data-payment-id="<?= $p['id'] ?>" onchange="updatePaymentStatus(this)">
                <option value="Pending" <?= $p['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                <option value="Paid" <?= $p['status'] === 'Paid' ? 'selected' : '' ?>>Paid</option>
                <option value="Not Available" <?= $p['status'] === 'Not Available' ? 'selected' : '' ?>>Not Available</option>
              </select>
              <span class="save-flag" id="pay-flag-<?= $p['id'] ?>"><i class="fas fa-check"></i> Saved</span>
            </div>
          </div>

          <?php if ($p['proof'] || $p['ntp']): ?>
            <div class="file-list">
              <?php if ($p['proof']): ?>
                <div class="file-row" id="proof-row-<?= $p['id'] ?>">
                  <div class="file-info">
                    <div class="file-label">Payment Proof</div>
                    <div class="file-meta">
                      <?= htmlspecialchars($p['proof']['file_name'] ?? '') ?>
                      <?php if (!empty($p['proof']['review_status'])): ?>
                        · Review: <?= htmlspecialchars($p['proof']['review_status']) ?>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="file-controls">
                    <button class="btn-reset" onclick="resetPaymentItem(<?= $p['id'] ?>, 'proof')" title="Delete the proof file and revert this payment to Pending">
                      <i class="fas fa-trash"></i> Delete & Reset
                    </button>
                  </div>
                </div>
              <?php endif; ?>

              <?php if ($p['ntp']): ?>
                <div class="file-row" id="ntp-row-<?= $p['id'] ?>">
                  <div class="file-info">
                    <div class="file-label">Notice to Proceed (NTP)</div>
                    <div class="file-meta"><?= htmlspecialchars($p['ntp']['file_name'] ?? '') ?></div>
                  </div>
                  <div class="file-controls">
                    <button class="btn-reset" onclick="resetPaymentItem(<?= $p['id'] ?>, 'ntp')" title="Delete the NTP file">
                      <i class="fas fa-trash"></i> Delete NTP
                    </button>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>

  </div>

  <div id="toast" class="toast">Updated!</div>

  <script>
    async function updateStatus(sel) {
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
          flag.style.display = 'inline';
          setTimeout(() => flag.style.display = 'none', 1500);
          toast('Status updated!');
        } else {
          toast('Error: ' + (data.error || 'Failed'), true);
        }
      } catch (e) {
        toast('Connection error', true);
      }
    }
    function toast(msg, err = false) {
      const el = document.getElementById('toast');
      el.textContent = msg;
      el.style.background = err ? '#dc2626' : '#0B0B0B';
      el.classList.add('show');
      setTimeout(() => el.classList.remove('show'), 2000);
    }

    async function updateFileStatus(sel) {
      const approvalId = sel.dataset.approvalId;
      const status = sel.value;
      if (!confirm('Change this file status to "' + status + '"? This overrides all reviewer approvals.')) {
        // revert dropdown visually — reload row state
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
          const badge = document.getElementById('badge-' + approvalId);
          badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
          badge.className = 'file-status-badge status-' + status;
          toast('File status updated!');
        } else {
          toast('Error: ' + (data.error || 'Failed'), true);
        }
      } catch (e) {
        toast('Connection error', true);
      }
    }

        async function updatePaymentStatus(sel) {
      const paymentId = sel.dataset.paymentId;
      const status = sel.value;
      if (!confirm('Change this payment status to "' + status + '"? This directly affects the client\'s remaining balance and tracker sync.')) {
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
          flag.style.display = 'inline';
          setTimeout(() => flag.style.display = 'none', 1500);
          toast('Payment status updated!');
        } else {
          toast('Error: ' + (data.error || 'Failed'), true);
        }
      } catch (e) {
        toast('Connection error', true);
      }
    }

    async function resetPaymentItem(paymentId, type) {
      const msg = type === 'proof'
        ? 'Delete this payment proof? The payment will revert to Pending and the assignee must resubmit.'
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
          toast('Deleted successfully!');
          setTimeout(() => location.reload(), 900);
        } else {
          toast('Error: ' + (data.error || 'Failed'), true);
        }
      } catch (e) {
        toast('Connection error', true);
      }
    }

    async function resetFileApproval(approvalId) {
      if (!confirm('Delete this file completely? The file itself, all reviewer approvals, and any linked POs/receipts (if applicable) will be permanently removed. The assignee will need to re-upload from scratch.')) return;
      try {
        const res = await fetch('<?= BASE_URL ?>status-control-file-update', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ approval_id: approvalId, action: 'reset' })
        });
        const data = await res.json();
        if (data.success) {
          toast('File deleted. Stage reset for re-upload!');
          setTimeout(() => location.reload(), 900);
        } else {
          toast('Error: ' + (data.error || 'Failed'), true);
        }
      } catch (e) {
        toast('Connection error', true);
      }
    }
  </script>
</body>
</html>