<?php
  // Fetch additional client data for the header
  $reference_number = '';
  $status = '';

  if ($client_id) {
    $stmt = $conn->prepare("SELECT reference_number, status FROM user_info WHERE id = ?");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
      $reference_number = $row['reference_number'];
      $status = $row['status'];
    }
  }
  ?>

<div class="client-header text-white py-6 mb-6 relative" style="background: linear-gradient(135deg, #3b1f0f 0%, #8a5a44 100%); border-radius: 12px; max-width: 1400px; margin: 30px auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
    <div style="max-width: 1400px; margin: 0 auto; padding: 0 40px; position: relative; z-index: 10;">
      <!-- Header with View Details Button -->
      <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
        <div>
          <h1 style="font-size: 32px; margin-bottom: 10px;">📋 <?= htmlspecialchars($client_name) ?></h1>
          <p style="opacity: 0.9; font-size: 16px;"><?= htmlspecialchars($project_name) ?></p>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button onclick="viewClientDetails()" style="background: white; color: #3b1f0f; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;">
            <i class="fas fa-info-circle"></i>
            View Full Details
          </button>
          <button onclick="openEditModal()" style="background: #f59e0b; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;">
            <i class="fas fa-edit"></i>
            Edit Client
          </button>
        </div>
      </div>

      <!-- Info Grid - Only show 4 key cards -->
      <div class="info-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
        <?php if ($reference_number): ?>
          <div class="info-card" style="backdrop-filter: blur(10px); background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 10px; padding: 15px; transition: all 0.3s ease;">
            <div class="info-icon" style="width: 40px; height: 40px; background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(5px); border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 8px;">
              <i class="fas fa-hashtag" style="color: white; font-size: 18px;"></i>
            </div>
            <div class="info-label" style="font-size: 11px; opacity: 0.75; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">Reference Number</div>
            <div class="info-value" style="font-size: 14px; font-weight: 600; margin-top: 4px; font-family: monospace;"><?= htmlspecialchars($reference_number) ?></div>
          </div>
        <?php endif; ?>

        <?php if ($business_type): ?>
          <div class="info-card" style="backdrop-filter: blur(10px); background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 10px; padding: 15px; transition: all 0.3s ease;">
            <div class="info-icon" style="width: 40px; height: 40px; background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(5px); border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 8px;">
              <i class="fas fa-building" style="color: white; font-size: 18px;"></i>
            </div>
            <div class="info-label" style="font-size: 11px; opacity: 0.75; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">Business Type</div>
            <div class="info-value" style="font-size: 14px; font-weight: 600; margin-top: 4px;"><?= htmlspecialchars($business_type_label) ?></div>
          </div>
        <?php endif; ?>

        <?php
        // Fetch total_project_cost and remaining_balance (already exists in your code)
        $costStmt = $conn->prepare("SELECT total_project_cost, remaining_balance, computation_locked FROM user_info WHERE id = ?");
        $costStmt->bind_param("i", $client_id);
        $costStmt->execute();
        $costData = $costStmt->get_result()->fetch_assoc();
        $computation_locked = (int)($costData['computation_locked'] ?? 0);
        ?>
        <div class="info-card" style="backdrop-filter: blur(10px); background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 10px; padding: 15px; transition: all 0.3s ease;">
          <div class="info-icon" style="width: 40px; height: 40px; background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(5px); border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 8px;">
            <i class="fas fa-dollar-sign" style="color: white; font-size: 18px;"></i>
          </div>
          <div class="info-label" style="font-size: 11px; opacity: 0.75; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">Total Project Cost</div>
          <?php if ($computation_locked): ?>
            <input type="number" step="0.01"
              id="manual-total-cost"
              value="<?= htmlspecialchars($costData['total_project_cost'] ?? 0) ?>"
              onchange="saveManualCost()"
              style="width:100%; padding:4px 6px; border:1px solid rgba(255,255,255,0.4); border-radius:6px; background:rgba(255,255,255,0.15); color:white; font-size:13px; font-weight:600;">
          <?php else: ?>
            <div class="info-value" data-kpi="total_cost" style="font-size: 14px; font-weight: 600; margin-top: 4px;">₱<?= number_format($costData['total_project_cost'] ?? 0, 2) ?></div>
          <?php endif; ?>
        </div>

        <div class="info-card" style="backdrop-filter: blur(10px); background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 10px; padding: 15px; transition: all 0.3s ease;">
          <div class="info-icon" style="width: 40px; height: 40px; background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(5px); border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 8px;">
            <i class="fas fa-balance-scale" style="color: white; font-size: 18px;"></i>
          </div>
          <div class="info-label" style="font-size: 11px; opacity: 0.75; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">Remaining Balance</div>
          <?php if ($computation_locked): ?>
            <input type="number" step="0.01"
              id="manual-remaining-balance"
              value="<?= htmlspecialchars($costData['remaining_balance'] ?? 0) ?>"
              onchange="saveManualCost()"
              style="width:100%; padding:4px 6px; border:1px solid rgba(255,255,255,0.4); border-radius:6px; background:rgba(255,255,255,0.15); color:white; font-size:13px; font-weight:600;">
          <?php else: ?>
            <div class="info-value" data-kpi="remaining_balance" style="font-size: 14px; font-weight: 600; margin-top: 4px;">₱<?= number_format($costData['remaining_balance'] ?? 0, 2) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>