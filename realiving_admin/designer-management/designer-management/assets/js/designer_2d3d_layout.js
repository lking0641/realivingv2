// ── Room unit scroll ──
        function scrollRoomBtns(slug, dir) {
            const el = document.getElementById('rb-' + slug);
            if (el) el.scrollBy({ left: dir * 200, behavior: 'smooth' });
        }

        // ── Room unit modal ──
        async function showDesignerRoomModal(clientId, area, roomNumber, roomLabel) {
            document.getElementById('roomModalTitle').innerHTML =
                '<i class="fas fa-door-open"></i> ' + roomLabel;
            document.getElementById('roomModalArea').innerHTML =
                '<i class="fas fa-map-marker-alt"></i> Area: ' + area;
            document.getElementById('roomModalBody').innerHTML = `
        <div style="text-align:center; padding:30px; color:#9ca3af;">
            <i class="fas fa-spinner fa-spin" style="font-size:28px;"></i>
            <p style="margin-top:10px;">Loading items...</p>
        </div>`;
            document.getElementById('designerRoomModal').style.display = 'flex';

            try {
                const res = await fetch('<?= BASE_URL ?>get-area-room-details?client_id=' + clientId +
                    '&area=' + encodeURIComponent(area) +
                    '&room_number=' + roomNumber);
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to load');
                renderDesignerRoomItems(data.items);
            } catch (err) {
                document.getElementById('roomModalBody').innerHTML =
                    '<div style="text-align:center; padding:30px; color:#ef4444;">' +
                    '<i class="fas fa-exclamation-triangle" style="font-size:28px;"></i>' +
                    '<p style="margin-top:10px;">Error: ' + err.message + '</p></div>';
            }
        }

        function renderDesignerRoomItems(items) {
            if (!items || items.length === 0) {
                document.getElementById('roomModalBody').innerHTML =
                    '<div style="text-align:center; padding:40px; color:#9ca3af;">' +
                    '<i class="fas fa-box-open" style="font-size:36px; display:block; margin-bottom:10px;"></i>' +
                    'No items found for this unit.</div>';
                return;
            }

            let totalQty = 0;
            let html = '<div style="display:flex; flex-direction:column; gap:12px;">';

            items.forEach(function (item) {
                totalQty += parseInt(item.quantity) || 0;

                let imgPath = '';
                if (item.image_folder && item.image_file) {
                    imgPath = '<?= CLIENT_ASSET ?>/images/' + item.image_folder + '/' + item.image_file;
                }

                html += '<div style="border:1px solid #e0e7ff; border-radius:10px; overflow:hidden;">';

                // Item row
                html += '<div style="display:flex; gap:12px; padding:14px; background:#fafafa; align-items:center;">';

                // Image
                if (imgPath) {
                    html += '<img src="' + imgPath + '" style="width:52px; height:52px; object-fit:cover; border-radius:8px; border:1px solid #e0e7ff; flex-shrink:0;" onerror="this.style.display=\'none\'">';
                } else {
                    html += '<div style="width:52px; height:52px; background:#e0e7ff; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0;"><i class="fas fa-box" style="color:#818cf8;"></i></div>';
                }

                // Info
                html += '<div style="flex:1; min-width:0;">';
                html += '<div style="font-weight:700; font-size:13px; color:#1f2937;">' + escHtml(item.item_name) + '</div>';
                if (item.display_color) {
                    html += '<div style="font-size:11px; color:#6b7280; margin-top:2px;"><i class="fas fa-palette"></i> ' + escHtml(item.display_color) + '</div>';
                }
                // Dimensions
                let dims = [];
                if (item.width) dims.push((item.width_label || 'W') + ': ' + item.width + 'mm');
                if (item.height) dims.push((item.height_label || 'H') + ': ' + item.height + 'mm');
                if (item.length) dims.push((item.length_label || 'L') + ': ' + item.length + 'mm');
                if (dims.length) {
                    html += '<div style="font-size:11px; color:#9ca3af; margin-top:3px;">' + dims.join(' &nbsp;•&nbsp; ') + '</div>';
                }
                if (item.room_unit_name) {
                    html += '<div style="font-size:11px; color:#6366f1; margin-top:3px;"><i class="fas fa-door-open"></i> ' + escHtml(item.room_unit_name) + '</div>';
                }
                if (item.notes && item.notes.trim()) {
                    html += '<div style="font-size:11px; color:#92400e; background:#fffbeb; padding:3px 8px; border-radius:4px; margin-top:4px;"><i class="fas fa-sticky-note"></i> ' + escHtml(item.notes) + '</div>';
                }
                html += '</div>';

                // Qty badge
                html += '<div style="flex-shrink:0; text-align:center;">';
                html += '<div style="background:#e0e7ff; color:#3730a3; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700;">' + item.quantity + ' pcs</div>';
                html += '<div style="font-size:10px; color:#9ca3af; margin-top:3px;">' + (item.entry_type === 'customized' ? 'Custom' : 'Fixed') + '</div>';
                html += '</div>';

                html += '</div>'; // end item row

                // Addons sub-section
                if (item.addons && item.addons.length > 0) {
                    const bodyId = 'drm-addon-' + Math.random().toString(36).substr(2, 6);
                    const iconId = 'drm-icon-' + Math.random().toString(36).substr(2, 6);

                    html += '<div style="border-top:1px solid #e0e7ff; background:#f0f4ff;">';
                    html += '<button type="button" onclick="toggleDrmAddon(\'' + bodyId + '\',\'' + iconId + '\')" ';
                    html += 'style="width:100%; padding:8px 14px; background:none; border:none; cursor:pointer; display:flex; align-items:center; gap:6px; font-size:11px; font-weight:700; color:#3730a3;">';
                    html += '<i class="fas fa-puzzle-piece"></i> ' + item.addons.length + ' Add-on' + (item.addons.length > 1 ? 's' : '');
                    html += '<i id="' + iconId + '" class="fas fa-chevron-down" style="margin-left:auto; transition:transform 0.2s;"></i>';
                    html += '</button>';

                    html += '<div id="' + bodyId + '" style="display:none;">';
                    item.addons.forEach(function (addon, ai) {
                        const border = ai > 0 ? 'border-top:1px solid #dde3ff;' : '';
                        html += '<div style="display:flex; align-items:center; gap:10px; padding:8px 14px; ' + border + '">';
                        if (addon.addon_image_path) {
                            html += '<img src="<?= CLIENT_ASSET ?>/images/product_addons/' + escHtml(addon.addon_image_path) + '" ';
                            html += 'style="width:32px; height:32px; object-fit:cover; border-radius:6px; border:1px solid #c7d2fe; flex-shrink:0;" onerror="this.style.display=\'none\'">';
                        } else {
                            html += '<div style="width:32px; height:32px; background:#dde3ff; border-radius:6px; display:flex; align-items:center; justify-content:center; flex-shrink:0;"><i class="fas fa-puzzle-piece" style="color:#818cf8; font-size:12px;"></i></div>';
                        }
                        html += '<div style="flex:1;">';
                        html += '<div style="font-size:12px; font-weight:700; color:#1e1b4b;">' + escHtml(addon.addon_name) + '</div>';
                        html += '<div style="font-size:11px; color:#4f46e5;">₱' + parseFloat(addon.price).toFixed(2) + ' / pc</div>';
                        if (addon.note) html += '<div style="font-size:10px; color:#64748b; font-style:italic;">' + escHtml(addon.note) + '</div>';
                        html += '</div>';
                        html += '</div>';
                    });
                    html += '</div>'; // addon body
                    html += '</div>'; // addon section
                }

                html += '</div>'; // end card
            });

            html += '</div>';

            // Summary footer
            html += '<div style="margin-top:14px; padding:14px 16px; background:linear-gradient(135deg,#3730a3,#6366f1); border-radius:10px; display:flex; justify-content:space-between; align-items:center; color:white;">';
            html += '<span style="font-size:13px; font-weight:600;"><i class="fas fa-boxes"></i> Total Items in Unit</span>';
            html += '<span style="font-size:22px; font-weight:700;">' + totalQty + '</span>';
            html += '</div>';

            document.getElementById('roomModalBody').innerHTML = html;
        }

        function toggleDrmAddon(bodyId, iconId) {
            const body = document.getElementById(bodyId);
            const icon = document.getElementById(iconId);
            if (!body) return;
            const open = body.style.display !== 'none';
            body.style.display = open ? 'none' : 'block';
            if (icon) icon.style.transform = open ? '' : 'rotate(180deg)';
        }

        function closeDesignerRoomModal() {
            document.getElementById('designerRoomModal').style.display = 'none';
        }

        function escHtml(text) {
            if (!text) return '';
            const d = document.createElement('div');
            d.textContent = text;
            return d.innerHTML;
        }

        function showAddons(itemName, addons) {
            document.getElementById('addonsModalTitle').innerHTML = '<i class="fas fa-puzzle-piece"></i> Add-ons for: ' + itemName;
            let html = '<div style="display:flex; flex-direction:column; gap:12px;">';
            let grandTotal = 0;
            addons.forEach(function (a) {
                const sub = parseFloat(a.quantity) * parseFloat(a.price);
                grandTotal += sub;
                html += '<div style="display:flex; align-items:center; gap:14px; padding:12px; border:1px solid #e5e7eb; border-radius:8px; background:#fafafa;">';
                if (a.addon_image_path) {
                    html += '<img src="<?= CLIENT_ASSET ?>/images/product_addons/' + a.addon_image_path + '" style="width:50px; height:50px; object-fit:cover; border-radius:6px; border:1px solid #e5e7eb;" onerror="this.style.display=\'none\'">';
                }
                html += '<div style="flex:1;">';
                html += '<div style="font-weight:700; font-size:13px; color:#111;">' + a.addon_name + '</div>';
                if (a.note) html += '<div style="font-size:11px; color:#9ca3af; margin-top:2px;"><i class="fas fa-sticky-note"></i> ' + a.note + '</div>';
                html += '<div style="font-size:12px; color:#6b7280; margin-top:4px;">Qty: <strong>' + a.quantity + '</strong> × ₱' + parseFloat(a.price).toFixed(2) + '</div>';
                html += '</div>';
                html += '<div style="font-weight:700; color:#065f46; font-size:14px;">₱' + sub.toFixed(2) + '</div>';
                html += '</div>';
            });
            html += '</div>';
            html += '<div style="margin-top:14px; padding:12px 16px; background:#f0fdf4; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">';
            html += '<span style="font-weight:600; color:#374151;">Add-ons Total</span>';
            html += '<span style="font-weight:700; font-size:16px; color:#065f46;">₱' + grandTotal.toFixed(2) + '</span>';
            html += '</div>';
            document.getElementById('addonsModalBody').innerHTML = html;
            document.getElementById('addonsModal').style.display = 'flex';
        }

        // Close modals on outside click
        document.addEventListener('click', function (e) {
            ['addonsModal', 'clientDetailModal2', 'designerRoomModal'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el && e.target === el) el.style.display = 'none';
            });
        });

        // ── Multi-select revision ──
        let revSelections = []; // [{area, unitNum, unitName, reason}]

        function getSelKey(area, unitNum) {
            return area + '||' + (unitNum ?? 'null');
        }

        function onAreaCheck(cb) {
            const area = cb.dataset.area;
            const key = getSelKey(area, null);
            if (cb.checked) {
                if (!revSelections.find(s => getSelKey(s.area, s.unitNum) === key)) {
                    revSelections.push({ area, unitNum: null, unitName: null, reason: '' });
                }
            } else {
                revSelections = revSelections.filter(s => getSelKey(s.area, s.unitNum) !== key);
            }
            updateSummary();
        }

        function removeSelection(key) {
            const idx = revSelections.findIndex(s => getSelKey(s.area, s.unitNum) === key);
            if (idx === -1) return;
            const s = revSelections[idx];
            // Uncheck the checkbox
            if (s.unitNum !== null) {
                const cb = document.querySelector(`.rev-unit-check[data-area="${CSS.escape(s.area)}"][data-unit-num="${s.unitNum}"]`);
                if (cb) { cb.checked = false; onUnitCheck(cb); return; }
            } else {
                const cb = document.querySelector(`.rev-area-check[data-area="${CSS.escape(s.area)}"]`);
                if (cb) { cb.checked = false; onAreaCheck(cb); return; }
            }
            revSelections.splice(idx, 1);
            updateSummary();
        }

        function updateSummary() {
            const box = document.getElementById('selectionSummary');
            const items = document.getElementById('selectionItems');
            const inp = document.getElementById('selectionsInput');

            if (revSelections.length === 0) {
                box.style.display = 'none';
                inp.value = '';
                updateSubmitBtn();
                return;
            }

            box.style.display = 'block';
            items.innerHTML = revSelections.map((s, i) => {
                const key = getSelKey(s.area, s.unitNum);
                const label = s.unitNum !== null
                    ? s.area + ' › ' + (s.unitName || 'Unit ' + s.unitNum)
                    : s.area + ' (whole area)';
                return `<div style="border:1px solid #fcd34d; border-radius:8px; padding:12px 14px; background:white;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:13px; font-weight:700; color:#92400e;">
                    <i class="fas fa-map-marker-alt"></i> ${label}
                </span>
                <button type="button" onclick="removeSelection('${key}')"
                    style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:13px; padding:0 4px;">
                    <i class="fas fa-times"></i> Remove
                </button>
            </div>
            <textarea
                placeholder="Reason for revision on this area/unit... *"
                oninput="updateReason('${key}', this.value)"
                style="width:100%; padding:8px 10px; border:1px solid #e9ecef; border-radius:6px; font-size:13px; font-family:inherit; resize:vertical; min-height:60px; box-sizing:border-box;"
            >${s.reason}</textarea>
        </div>`;
            }).join('');

            inp.value = JSON.stringify(revSelections);
            updateSubmitBtn();
        }

        function updateReason(key, val) {
            const s = revSelections.find(s => getSelKey(s.area, s.unitNum) === key);
            if (s) s.reason = val.trim();
            document.getElementById('selectionsInput').value = JSON.stringify(revSelections);
            updateSubmitBtn();
        }

        function updateSubmitBtn() {
            const btn = document.getElementById('revisionSubmitBtn');
            const ready = revSelections.length > 0 && revSelections.every(s => s.reason.trim() !== '');
            btn.disabled = !ready;
            btn.style.opacity = ready ? '1' : '0.5';
            btn.style.cursor = ready ? 'pointer' : 'not-allowed';
        }

        function confirmRevision() {
            if (revSelections.length === 0) return false;
            if (!revSelections.every(s => s.reason.trim() !== '')) {
                alert('Please fill in a reason for each selected area/unit.');
                return false;
            }
            const lines = revSelections.map(s =>
                s.unitNum !== null
                    ? '  • ' + s.area + ' › ' + (s.unitName || 'Unit ' + s.unitNum)
                    : '  • ' + s.area + ' (whole area)'
            ).join('\n');
            return confirm(
                'This will count as Revision #1 (one submission).\n\nAreas/units to reset:\n' + lines +
                '\n\nApprovals for these will be reset. Continue?'
            );
        }

        function toggleRevHistory() {
            const panel = document.getElementById('revHistoryPanel');
            const icon = document.getElementById('revHistoryBtnIcon');
            const text = document.getElementById('revHistoryBtnText');
            const open = panel.style.display !== 'none';
            panel.style.display = open ? 'none' : 'block';
            icon.className = open ? 'fas fa-eye' : 'fas fa-eye-slash';
            text.textContent = open ? 'Show History' : 'Hide History';
        }

        function toggleRevPanel(panelId, chevronId) {
            const panel = document.getElementById(panelId);
            const chev = document.getElementById(chevronId);
            const open = panel.style.display !== 'none';
            panel.style.display = open ? 'none' : 'block';
            chev.style.transform = open ? '' : 'rotate(180deg)';
        }

        function toggleIntakeEdit() {
            const viewMode = document.getElementById('intakeViewMode');
            const editMode = document.getElementById('intakeEditMode');
            const btn = document.getElementById('intakeEditBtn');
            const isEditing = editMode.style.display !== 'none';
            viewMode.style.display = isEditing ? 'block' : 'none';
            editMode.style.display = isEditing ? 'none' : 'block';
            btn.innerHTML = isEditing
                ? '<i class="fas fa-pen"></i> Edit'
                : '<i class="fas fa-times"></i> Cancel';
        }