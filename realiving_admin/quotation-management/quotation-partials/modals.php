<!-- Modal for viewing full client details -->
<div id="clientDetailModal" class="modal"
  style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
  <div class="modal-content"
    style="background-color: #fefefe; padding: 30px; border-radius: 12px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
    <div class="modal-header"
      style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
      <h2 style="font-size: 24px; font-weight: bold; color: #3b1f0f;">
        <i class="fas fa-user-circle" style="color: #3b1f0f;"></i> Client Details
      </h2>
      <button onclick="closeClientModal()" class="modal-close"
        style="font-size: 24px; color: #666; cursor: pointer; background: none; border: none;">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div id="clientModalContent">
      <div class="detail-row"
        style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
        <div class="detail-label" style="font-weight: 600; color: #666;">Reference Number:</div>
        <div class="detail-value" style="color: #111; font-family: monospace; color: #3b82f6;">
          <?= htmlspecialchars($reference_number) ?></div>
      </div>
      <div class="detail-row"
        style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
        <div class="detail-label" style="font-weight: 600; color: #666;">Client Name:</div>
        <div class="detail-value" style="color: #111;"><?= htmlspecialchars($client_name) ?></div>
      </div>
      <div class="detail-row"
        style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
        <div class="detail-label" style="font-weight: 600; color: #666;">Project Name:</div>
        <div class="detail-value" style="color: #111;"><?= htmlspecialchars($project_name) ?></div>
      </div>
      <div class="detail-row"
        style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
        <div class="detail-label" style="font-weight: 600; color: #666;">Status:</div>
        <div class="detail-value" style="color: #111;">
          <span class="badge badge-<?= $status === 'New Client' ? 'new' : 'old' ?>"
            style="padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; background: <?= $status === 'New Client' ? '#fef3c7' : '#dbeafe' ?>; color: <?= $status === 'New Client' ? '#92400e' : '#1e40af' ?>;">
            <?= htmlspecialchars($status) ?>
          </span>
        </div>
      </div>
      <div class="detail-row"
        style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
        <div class="detail-label" style="font-weight: 600; color: #666;">Business Type:</div>
        <div class="detail-value" style="color: #111;"><?= htmlspecialchars($business_type_label) ?></div>
      </div>
      <div class="detail-row"
        style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
        <div class="detail-label" style="font-weight: 600; color: #666;">Phone:</div>
        <div class="detail-value" style="color: #111;"><?= htmlspecialchars($client_contact) ?></div>
      </div>
      <div class="detail-row"
        style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
        <div class="detail-label" style="font-weight: 600; color: #666;">Email:</div>
        <div class="detail-value" style="color: #111;"><?= htmlspecialchars($client_email) ?></div>
      </div>
      <div class="detail-row"
        style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
        <div class="detail-label" style="font-weight: 600; color: #666;">Address:</div>
        <div class="detail-value" style="color: #111;"><?= htmlspecialchars($client_address) ?></div>
      </div>
      <div class="detail-row"
        style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
        <div class="detail-label" style="font-weight: 600; color: #666;">Gender:</div>
        <div class="detail-value" style="color: #111;"><?= htmlspecialchars($gender) ?></div>
      </div>
      <div class="detail-row"
        style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
        <div class="detail-label" style="font-weight: 600; color: #666;">Classification:</div>
        <div class="detail-value" style="color: #111;"><?= htmlspecialchars($client_class) ?></div>
      </div>
      <div class="detail-row"
        style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
        <div class="detail-label" style="font-weight: 600; color: #666;">Client Type:</div>
        <div class="detail-value" style="color: #111;"><?= htmlspecialchars($client_type) ?></div>
      </div>
      <?php if ($project_scope): ?>
        <div class="detail-row"
          style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
          <div class="detail-label" style="font-weight: 600; color: #666;">Project Scope:</div>
          <div class="detail-value" style="color: #111;"><?= nl2br(htmlspecialchars($project_scope)) ?></div>
        </div>
      <?php endif; ?>
      <?php if ($scope_of_work): ?>
        <div class="detail-row"
          style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
          <div class="detail-label" style="font-weight: 600; color: #666;">Scope of Work:</div>
          <div class="detail-value" style="color: #111;"><?= nl2br(htmlspecialchars($scope_of_work)) ?></div>
        </div>
      <?php endif; ?>

      <!-- House State -->
      <div class="detail-row"
        style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
        <div class="detail-label" style="font-weight: 600; color: #666;">House State:</div>
        <div class="detail-value" id="view-house-state">
          <?php if ($house_state):
            $hsBg = '#fef3c7';
            $hsColor = '#92400e';
            if ($house_state === 'Bare/Empty Lot') {
              $hsBg = '#dbeafe';
              $hsColor = '#1e40af';
            } elseif ($house_state === 'Construction Started') {
              $hsBg = '#fee2e2';
              $hsColor = '#991b1b';
            } elseif ($house_state === 'Renovation') {
              $hsBg = '#ede9fe';
              $hsColor = '#5b21b6';
            }
            ?>
            <span
              style="padding:3px 10px; border-radius:10px; font-size:12px; font-weight:700; background:<?= $hsBg ?>; color:<?= $hsColor ?>;">
              <?= htmlspecialchars($house_state) ?>
            </span>
          <?php else: ?>
            <span style="color:#9ca3af;">—</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Permit Required -->
      <div class="detail-row"
        style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0; border-bottom: 1px solid #e9ecef;">
        <div class="detail-label" style="font-weight: 600; color: #666;">Permit Required:</div>
        <div class="detail-value" id="view-permit-required">
          <?php if ($permit_required):
            $prBg = '#fef3c7';
            $prColor = '#92400e';
            if ($permit_required === 'Yes') {
              $prBg = '#fee2e2';
              $prColor = '#991b1b';
            } elseif ($permit_required === 'No') {
              $prBg = '#d1fae5';
              $prColor = '#065f46';
            }
            ?>
            <span
              style="padding:3px 10px; border-radius:10px; font-size:12px; font-weight:700; background:<?= $prBg ?>; color:<?= $prColor ?>;">
              <?= htmlspecialchars($permit_required) ?>
            </span>
          <?php else: ?>
            <span style="color:#9ca3af;">—</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Target Move-in Date -->
      <div class="detail-row" style="display: grid; grid-template-columns: 140px 1fr; padding: 12px 0;">
        <div class="detail-label" style="font-weight: 600; color: #666;">Target Move-in:</div>
        <div class="detail-value" id="view-target-movein" style="color: #111;">
          <?= $target_movein_date
            ? '<i class="fas fa-calendar-check" style="color:#10b981;"></i> ' . date('F d, Y', strtotime($target_movein_date))
            : '<span style="color:#9ca3af;">—</span>' ?>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ===== EDIT CLIENT MODAL ===== -->
<div id="editClientModal"
  style="display:none; position:fixed; z-index:1001; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
  <div
    style="background:#fefefe; padding:30px; border-radius:12px; max-width:700px; width:90%; max-height:90vh; overflow-y:auto; margin:20px;">
    <div
      style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:2px solid #e5e7eb; padding-bottom:15px;">
      <h2 style="font-size:22px; font-weight:bold; color:#3b1f0f;">
        <i class="fas fa-edit" style="color:#f59e0b;"></i> Edit Client Info
      </h2>
      <button onclick="closeEditModal()"
        style="font-size:24px; color:#666; cursor:pointer; background:none; border:none;">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <div id="editAlertSuccess"
      style="display:none; background:#d1fae5; border-left:4px solid #10b981; color:#065f46; padding:12px 16px; border-radius:8px; margin-bottom:16px;">
      <i class="fas fa-check-circle"></i> Client updated successfully!
    </div>
    <div id="editAlertError"
      style="display:none; background:#fee2e2; border-left:4px solid #ef4444; color:#991b1b; padding:12px 16px; border-radius:8px; margin-bottom:16px;">
      <i class="fas fa-exclamation-circle"></i> <span id="editErrorMsg">Error updating client.</span>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; padding-top:8px;">

      <div style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-size:13px; font-weight:600; color:#374151;">Client Name</label>
        <input type="text" id="edit-clientname" value="<?= htmlspecialchars($client_name) ?>"
          style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
      </div>

      <div style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-size:13px; font-weight:600; color:#374151;">Project Name</label>
        <input type="text" id="edit-nameproject" value="<?= htmlspecialchars($project_name) ?>"
          style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
      </div>

      <div style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-size:13px; font-weight:600; color:#374151;">Status</label>
        <select id="edit-status"
          style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
          <option value="New Client" <?= $status === 'New Client' ? 'selected' : '' ?>>New Client</option>
          <option value="Old Client" <?= $status === 'Old Client' ? 'selected' : '' ?>>Old Client</option>
        </select>
      </div>

      <div style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-size:13px; font-weight:600; color:#374151;">Business Type</label>
        <select id="edit-business-type"
          style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
          <option value="Non-Project" <?= $business_type === 'Non-Project' ? 'selected' : '' ?>>Individual</option>
          <option value="Project" <?= $business_type === 'Project' ? 'selected' : '' ?>>Project</option>
        </select>
      </div>

      <div style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-size:13px; font-weight:600; color:#374151;">Contact Number</label>
        <input type="text" id="edit-contact" value="<?= htmlspecialchars($client_contact) ?>"
          style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
      </div>

      <div style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-size:13px; font-weight:600; color:#374151;">Email</label>
        <input type="email" id="edit-email" value="<?= htmlspecialchars($client_email) ?>"
          style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
      </div>

      <div style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-size:13px; font-weight:600; color:#374151;">Client Classification</label>
        <select id="edit-client-class"
          style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
          <option value="VIP">VIP</option>
          <option value="Regular">Regular</option>
          <option value="Walk-in">Walk-in</option>
          <option value="Returning">Returning</option>
        </select>
      </div>

      <div style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-size:13px; font-weight:600; color:#374151;">Gender</label>
        <select id="edit-gender"
          style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
          <option value="Male">Male</option>
          <option value="Female">Female</option>
          <option value="Other">Other</option>
          <option value="Prefer not to say">Prefer not to say</option>
        </select>
      </div>

      <div style="display:flex; flex-direction:column; gap:6px; grid-column:span 2;">
        <label style="font-size:13px; font-weight:600; color:#374151;">Address</label>
        <textarea id="edit-address" rows="2"
          style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%; resize:vertical;"><?= htmlspecialchars($client_address) ?></textarea>
      </div>

      <div style="display:flex; flex-direction:column; gap:6px; grid-column:span 2;">
        <label style="font-size:13px; font-weight:600; color:#374151;">Project Scope</label>
        <input type="text" id="edit-project-scope" value="<?= htmlspecialchars($project_scope) ?>"
          style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
      </div>

      <div style="display:flex; flex-direction:column; gap:6px; grid-column:span 2;">
        <label style="font-size:13px; font-weight:600; color:#374151;">Scope of Work</label>
        <textarea id="edit-scope-of-work" rows="3"
          style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%; resize:vertical;"><?= htmlspecialchars($scope_of_work) ?></textarea>
      </div>

      <div style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-size:13px; font-weight:600; color:#374151;">State of the House</label>
        <select id="edit-house-state"
          style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
          <option value="">— Select —</option>
          <option value="Bare/Empty Lot" <?= $house_state === 'Bare/Empty Lot' ? 'selected' : '' ?>>Bare / Empty Lot
          </option>
          <option value="Existing Structure" <?= $house_state === 'Existing Structure' ? 'selected' : '' ?>>Existing
            Structure (No renovation yet)</option>
          <option value="Renovation" <?= $house_state === 'Renovation' ? 'selected' : '' ?>>Existing Structure (For
            Renovation)</option>
          <option value="Construction Started" <?= $house_state === 'Construction Started' ? 'selected' : '' ?>>
            Construction Already Started</option>
        </select>
      </div>

      <div style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-size:13px; font-weight:600; color:#374151;">Permit Required?</label>
        <select id="edit-permit-required"
          style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
          <option value="">— Select —</option>
          <option value="Yes" <?= $permit_required === 'Yes' ? 'selected' : '' ?>>Yes — Permit Required</option>
          <option value="No" <?= $permit_required === 'No' ? 'selected' : '' ?>>No — Not Required</option>
          <option value="Unsure" <?= $permit_required === 'Unsure' ? 'selected' : '' ?>>Unsure — Needs Assessment</option>
        </select>
      </div>

      <div style="display:flex; flex-direction:column; gap:6px;">
        <label style="font-size:13px; font-weight:600; color:#374151;">Target Move-in Date</label>
        <input type="date" id="edit-target-movein" value="<?= htmlspecialchars($target_movein_date) ?>"
          style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; width:100%;">
        <label
          style="display:flex; align-items:center; gap:8px; margin-top:4px; font-size:13px; color:#6b7280; cursor:pointer; font-weight:normal;">
          <input type="checkbox" id="edit-no-movein-date" onchange="toggleEditMoveInDate(this)"
            <?= empty($target_movein_date) ? 'checked' : '' ?>
            style="width:15px; height:15px; cursor:pointer; accent-color:#3b82f6;">
          None / Not yet determined
        </label>
      </div>

    </div>

    <div
      style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; padding-top:16px; border-top:1px solid #e5e7eb;">
      <button onclick="closeEditModal()"
        style="padding:10px 20px; background:#6b7280; color:white; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:14px;">
        Cancel
      </button>
      <button onclick="saveClientEdit()"
        style="padding:10px 24px; background:linear-gradient(135deg,#3b82f6,#2563eb); color:white; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:14px;">
        <i class="fas fa-save"></i> Save Changes
      </button>
    </div>
  </div>
</div>

<script>
  // On page load, check sessionStorage for fresher client data
  (function syncFromSession() {
    const urlParams = new URLSearchParams(window.location.search);
    const clientId = urlParams.get('client_id');
    if (!clientId) return;
    const stored = sessionStorage.getItem('client_' + clientId);
    if (!stored) return;
    const data = JSON.parse(stored);

    // Update URL
    const url = new URL(window.location.href);
    url.searchParams.set('client_name', data.clientname);
    url.searchParams.set('email', data.email);
    url.searchParams.set('address', data.address);
    url.searchParams.set('contact', data.contact);
    history.replaceState(null, '', url.toString());

    // Update visible header
    const h1 = document.querySelector('.client-header h1');
    if (h1) h1.textContent = '📋 ' + data.clientname;
    const sub = document.querySelector('.client-header h1 + p');
    if (sub) sub.textContent = data.clientname;
  })();

  // Client details modal functions
  function viewClientDetails() {
    document.getElementById('clientDetailModal').style.display = 'flex';
  }

  function closeClientModal() {
    document.getElementById('clientDetailModal').style.display = 'none';
  }
  document.getElementById('clientDetailModal').addEventListener('click', function (e) {
    if (e.target === this) closeClientModal();
  });

  // ── Edit modal ──
  function openEditModal() {
    closeClientModal();
    document.getElementById('editClientModal').style.display = 'flex';
    // Sync checkbox state with date input on open
    const dateInput = document.getElementById('edit-target-movein');
    const checkbox = document.getElementById('edit-no-movein-date');
    if (checkbox && dateInput) {
      checkbox.checked = !dateInput.value;
      dateInput.disabled = !dateInput.value;
    }
  }

  function toggleEditMoveInDate(checkbox) {
    const dateInput = document.getElementById('edit-target-movein');
    if (checkbox.checked) {
      dateInput.value = '';
      dateInput.disabled = true;
    } else {
      dateInput.disabled = false;
    }
  }

  function closeEditModal() {
    document.getElementById('editClientModal').style.display = 'none';
    document.getElementById('editAlertSuccess').style.display = 'none';
    document.getElementById('editAlertError').style.display = 'none';
  }
  document.getElementById('editClientModal').addEventListener('click', function (e) {
    if (e.target === this) closeEditModal();
  });

  async function saveClientEdit() {
    const payload = {
      client_id: <?= intval($client_id) ?>,
      clientname: document.getElementById('edit-clientname').value.trim(),
      nameproject: document.getElementById('edit-nameproject').value.trim(),
      status: document.getElementById('edit-status').value,
      business_type: document.getElementById('edit-business-type').value,
      contact: document.getElementById('edit-contact').value.trim(),
      email: document.getElementById('edit-email').value.trim(),
      address: document.getElementById('edit-address').value.trim(),
      gender: document.getElementById('edit-gender').value,
      client_class: document.getElementById('edit-client-class').value,
      project_scope: document.getElementById('edit-project-scope').value.trim(),
      scope_of_work: document.getElementById('edit-scope-of-work').value.trim(),
      house_state: document.getElementById('edit-house-state').value,
      permit_required: document.getElementById('edit-permit-required').value,
      target_movein_date: document.getElementById('edit-target-movein').value,
    };

    try {
      const res = await fetch('<?= BASE_URL ?>update-client-info', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      });
      const data = await res.json();

      if (data.success) {
        // Update header live
        document.querySelector('.client-header h1').textContent = '📋 ' + data.clientname;
        document.querySelector('.client-header h1 + p').textContent = data.nameproject;

        // Update view modal: basic fields
        const viewFields = {
          'view-clientname': data.clientname,
          'view-nameproject': data.nameproject,
          'view-contact': data.contact,
          'view-email': data.email,
          'view-address': data.address,
        };
        Object.entries(viewFields).forEach(([id, val]) => {
          const el = document.getElementById(id);
          if (el) el.textContent = val || '—';
        });

        // Update business type in view modal
        const bizTypeEl = document.querySelector('#clientModalContent .detail-value:nth-child(even)');
        document.querySelectorAll('#clientModalContent .detail-row').forEach(row => {
          const label = row.querySelector('.detail-label');
          const value = row.querySelector('.detail-value');
          if (!label || !value) return;
          if (label.textContent.trim() === 'Business Type:') {
            value.textContent = data.business_type === 'Non-Project' ? 'Individual' : data.business_type;
          }
          if (label.textContent.trim() === 'Client Name:') {
            value.textContent = data.clientname;
          }
          if (label.textContent.trim() === 'Project Name:') {
            value.textContent = data.nameproject;
          }
          if (label.textContent.trim() === 'Phone:') {
            value.textContent = data.contact || 'N/A';
          }
          if (label.textContent.trim() === 'Email:') {
            value.textContent = data.email || 'N/A';
          }
          if (label.textContent.trim() === 'Address:') {
            value.textContent = data.address || 'N/A';
          }
          if (label.textContent.trim() === 'Project Scope:') {
            value.innerHTML = data.project_scope ? data.project_scope.replace(/\n/g, '<br>') : '—';
          }
          if (label.textContent.trim() === 'Scope of Work:') {
            value.innerHTML = data.scope_of_work ? data.scope_of_work.replace(/\n/g, '<br>') : '—';
          }
          if (label.textContent.trim() === 'Status:') {
            const isNew = data.status === 'New Client';
            const bg = isNew ? '#fef3c7' : '#dbeafe';
            const color = isNew ? '#92400e' : '#1e40af';
            value.innerHTML = `<span style="padding:4px 12px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase; background:${bg}; color:${color};">${data.status}</span>`;
          }
        });

        // Update info grid cards in header
        document.querySelectorAll('.info-card').forEach(card => {
          const label = card.querySelector('.info-label');
          const value = card.querySelector('.info-value');
          if (!label || !value) return;
          if (label.textContent.trim() === 'Business Type') {
            value.textContent = data.business_type === 'Non-Project' ? 'Individual' : data.business_type;
          }
        });

        // Update house state badge
        const houseStateEl = document.getElementById('view-house-state');
        if (houseStateEl) {
          const hsBgMap = {
            'Bare/Empty Lot': ['#dbeafe', '#1e40af'],
            'Construction Started': ['#fee2e2', '#991b1b'],
            'Renovation': ['#ede9fe', '#5b21b6'],
            'Existing Structure': ['#fef3c7', '#92400e'],
          };
          const [bg, color] = hsBgMap[data.house_state] || ['#f3f4f6', '#6b7280'];
          houseStateEl.innerHTML = data.house_state ?
            `<span style="padding:3px 10px; border-radius:10px; font-size:12px; font-weight:700; background:${bg}; color:${color};">${data.house_state}</span>` :
            '<span style="color:#9ca3af;">—</span>';
        }

        // Update permit required badge
        const permitEl = document.getElementById('view-permit-required');
        if (permitEl) {
          const prBgMap = {
            'Yes': ['#fee2e2', '#991b1b'],
            'No': ['#d1fae5', '#065f46'],
            'Unsure': ['#fef3c7', '#92400e'],
          };
          const [bg, color] = prBgMap[data.permit_required] || ['#f3f4f6', '#6b7280'];
          permitEl.innerHTML = data.permit_required ?
            `<span style="padding:3px 10px; border-radius:10px; font-size:12px; font-weight:700; background:${bg}; color:${color};">${data.permit_required}</span>` :
            '<span style="color:#9ca3af;">—</span>';
        }

        // Update target move-in date
        const moveinEl = document.getElementById('view-target-movein');
        if (moveinEl) {
          if (data.target_movein_date) {
            const d = new Date(data.target_movein_date + 'T00:00:00');
            const formatted = d.toLocaleDateString('en-US', {
              year: 'numeric',
              month: 'long',
              day: 'numeric'
            });
            moveinEl.innerHTML = `<i class="fas fa-calendar-check" style="color:#10b981;"></i> ${formatted}`;
          } else {
            moveinEl.innerHTML = '<span style="color:#9ca3af;">—</span>';
          }
        }

        // Show success alert
        // Update URL so reload keeps correct data
        const url = new URL(window.location.href);
        url.searchParams.set('client_name', data.clientname);
        url.searchParams.set('email', data.email);
        url.searchParams.set('address', data.address);
        url.searchParams.set('contact', data.contact);
        history.replaceState(null, '', url.toString());

        // Sync to sessionStorage so quotation_items picks up the latest
        const clientId = url.searchParams.get('client_id');
        sessionStorage.setItem('client_' + clientId, JSON.stringify({
          clientname: data.clientname,
          email: data.email,
          address: data.address,
          contact: data.contact,
        }));

        // Show success alert
        document.getElementById('editAlertSuccess').style.display = 'block';
        document.getElementById('editAlertError').style.display = 'none';
        setTimeout(() => {
          document.getElementById('editAlertSuccess').style.display = 'none';
        }, 3000);
      } else {
        document.getElementById('editErrorMsg').textContent = data.error || 'Unknown error';
        document.getElementById('editAlertError').style.display = 'block';
        document.getElementById('editAlertSuccess').style.display = 'none';
      }
    } catch (err) {
      document.getElementById('editErrorMsg').textContent = err.message;
      document.getElementById('editAlertError').style.display = 'block';
    }
  }

  // Add hover effects to buttons
  document.querySelectorAll('.btn').forEach(btn => {
    btn.addEventListener('mouseenter', function () {
      this.style.transform = 'translateY(-1px)';
      this.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
    });
    btn.addEventListener('mouseleave', function () {
      this.style.transform = 'translateY(0)';
      this.style.boxShadow = 'none';
    });
  });

  // Add hover effects to info cards
  document.querySelectorAll('.info-card').forEach(card => {
    card.addEventListener('mouseenter', function () {
      this.style.background = 'rgba(255, 255, 255, 0.25)';
      this.style.transform = 'translateY(-2px)';
    });
    card.addEventListener('mouseleave', function () {
      this.style.background = 'rgba(255, 255, 255, 0.15)';
      this.style.transform = 'translateY(0)';
    });
  });
</script>