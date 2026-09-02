//unified_project_tracker.js
async function setPDSStatus(stageId, status) {
            const labels = { Pending: 'Pending', Ongoing: 'Ongoing', Done: 'Done' };
            if (!confirm('Set Production Data Submittals to ' + labels[status] + '?')) return;
            try {
                const res = await fetch(`${TRACKER_BASE_URL}update-tracker-status`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ stage_id: stageId, status: status })
                });
                const data = await res.json();
                if (data.success) { toast('Status set to ' + status + '!'); setTimeout(() => location.reload(), 900); }
                else toast('Error: ' + (data.error || 'Failed'), true);
            } catch (e) { toast('An error occurred', true); }
        }

        async function markDone(stageId) {
            if (!confirm('Mark this stage as Done?')) return;
            try {
                const res = await fetch(`${TRACKER_BASE_URL}update-tracker-status`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ stage_id: stageId, status: 'Done' })
                });
                const data = await res.json();
                if (data.success) { toast('Stage marked as Done!'); setTimeout(() => location.reload(), 900); }
                else toast('Error: ' + (data.error || 'Failed'), true);
            } catch (e) { toast('An error occurred', true); }
        }

        async function cancelDone(stageId) {
            if (!confirm('Revert this stage back to Ongoing?')) return;
            try {
                const res = await fetch(`${TRACKER_BASE_URL}update-tracker-status`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ stage_id: stageId, status: 'Ongoing' })
                });
                const data = await res.json();
                if (data.success) { toast('Stage reverted to Ongoing.'); setTimeout(() => location.reload(), 900); }
                else toast('Error: ' + (data.error || 'Failed'), true);
            } catch (e) { toast('An error occurred', true); }
        }

        function toast(msg, err = false) {
    const el = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    el.classList.remove('bg-black', 'bg-red-600', 'opacity-0', 'translate-y-20');
    el.classList.add(err ? 'bg-red-600' : 'bg-black', 'opacity-100', 'translate-y-0');
    setTimeout(() => {
        el.classList.remove('opacity-100', 'translate-y-0');
        el.classList.add('opacity-0', 'translate-y-20');
    }, 3000);
}