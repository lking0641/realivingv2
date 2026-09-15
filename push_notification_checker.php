<?php
// push_notification_checker.php
// Runs on a schedule (cron job). Checks every admin user's pending count,
// sends a push if it went up, or a reminder push if 3+ hours have passed
// with unfinished tasks still pending.

require_once __DIR__ . '/config/app_config.php';
require_once __DIR__ . '/config/notification_counts.php';
require_once __DIR__ . '/config/push_functions.php';

include $includes['connection'];

$REMINDER_INTERVAL_HOURS = 3;

// ── Get every admin user eligible for push notifications ──────────────────
$usersStmt = $conn->prepare("
    SELECT id, role, is_head FROM account
    WHERE role IN ('general_manager', 'operational_manager', 'designer', 'technical_designer', 'accounting', 'superadmin', 'project_coordinator', 'sales')
");
$usersStmt->execute();
$usersResult = $usersStmt->get_result();

while ($user = $usersResult->fetch_assoc()) {
    $admin_id = $user['id'];
    $role = $user['role'];
    $isHead = (bool) $user['is_head'];

    // ── Calculate this user's total pending count (reusing shared functions) ──
    $needsAssignmentFilter = (
        $role === 'project_coordinator' ||
        ($role === 'designer' && !$isHead) ||
        ($role === 'technical_designer' && !$isHead)
    );

    if ($needsAssignmentFilter) {
        $stmt = $conn->prepare("
            SELECT id FROM user_info 
            WHERE account_status != 'Finished'
              AND (designer1_id = ? OR designer2_id = ? OR technical_designer_id = ? OR project_coordinator_id = ?)
        ");
        $stmt->bind_param("iiii", $admin_id, $admin_id, $admin_id, $admin_id);
    } else {
        $stmt = $conn->prepare("SELECT id FROM user_info WHERE account_status != 'Finished'");
    }
    $stmt->execute();
    $clientsResult = $stmt->get_result();

    $totalPending = 0;
    while ($row = $clientsResult->fetch_assoc()) {
        $cid = $row['id'];
        $totalPending += getClientPendingApprovalsForUser($conn, $admin_id, $role, $isHead, $cid);
        $totalPending += getClientRejectedFilesForUploader($conn, $admin_id, $cid);
        if ($role === 'designer' && $isHead) {
            $totalPending += getClientRejectedSiteVisits($conn, $cid);
        }
        if (in_array($role, ['accounting', 'general_manager', 'operational_manager', 'superadmin'])) {
            $totalPending += getClientPendingPaymentProofs($conn, $cid);
        }
        if (in_array($role, ['project_coordinator', 'sales', 'general_manager', 'operational_manager', 'superadmin'])) {
            $totalPending += getClientMissingPoCount($conn, $cid);
        }
        if ($role === 'project_coordinator') {
            $totalPending += getClientApprovedPoNotOrderedCount($conn, $cid);
        }
        $totalPending += getClientPendingInternalPO($conn, $admin_id, $role, $isHead, $cid);
    }

    // ── Inquiry counts (sales / superadmin) ────────────────────────────────
    if (in_array($role, ['sales', 'superadmin'])) {
        $apt_query = ($role === 'superadmin')
            ? "SELECT COUNT(*) as count FROM appointments WHERE status='pending'"
            : "SELECT COUNT(*) as count FROM appointments WHERE status='pending' AND assigned_to = $admin_id";
        $apt_result = $conn->query($apt_query);
        if ($apt_result) $totalPending += (int) $apt_result->fetch_assoc()['count'];

        $concept_query = ($role === 'superadmin')
            ? "SELECT COUNT(*) as count FROM concept_inquiries WHERE status='pending'"
            : "SELECT COUNT(*) as count FROM concept_inquiries WHERE status='pending' AND assigned_to = $admin_id";
        $concept_result = $conn->query($concept_query);
        if ($concept_result) $totalPending += (int) $concept_result->fetch_assoc()['count'];

        $contact_query = ($role === 'superadmin')
            ? "SELECT COUNT(*) as count FROM contact WHERE status='pending'"
            : "SELECT COUNT(*) as count FROM contact WHERE status='pending' AND assigned_to = $admin_id";
        $contact_result = $conn->query($contact_query);
        if ($contact_result) $totalPending += (int) $contact_result->fetch_assoc()['count'];

        $project_query = ($role === 'superadmin')
            ? "SELECT COUNT(*) as count FROM project_inquiries WHERE status='pending'"
            : "SELECT COUNT(*) as count FROM project_inquiries WHERE status='pending' AND assigned_to = $admin_id";
        $project_result = $conn->query($project_query);
        if ($project_result) $totalPending += (int) $project_result->fetch_assoc()['count'];
    }

    // ── TD approval counts (GM / OM / technical_designer) ──────────────────
    if (in_array($role, ['general_manager', 'operational_manager', 'technical_designer'])) {
        $tdStmt = $conn->prepare("
            SELECT COUNT(*) FROM td_attachment_approvals la
            WHERE la.approver_id = ? AND la.status = 'pending'
            AND la.requested_at IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM td_revision_log rl
                WHERE rl.client_id = la.client_id
                AND rl.area = la.area
                AND rl.status = 'pending'
                AND (
                    (la.room_unit_number IS NULL AND rl.room_unit_number IS NULL)
                    OR rl.room_unit_number = la.room_unit_number
                )
            )
        ");
        $tdStmt->bind_param("i", $admin_id);
        $tdStmt->execute();
        $totalPending += (int) $tdStmt->get_result()->fetch_row()[0];
    }

    if ($role === 'technical_designer') {
        $rmkStmt = $conn->prepare("
            SELECT COUNT(DISTINCT la.client_id) FROM layout_approvals la
            INNER JOIN user_info u ON u.id = la.client_id
            WHERE u.technical_designer_id = ?
            AND (la.td_remark IS NULL OR la.td_remark = '')
            AND la.requested_at IS NOT NULL
        ");
        $rmkStmt->bind_param("i", $admin_id);
        $rmkStmt->execute();
        $totalPending += (int) $rmkStmt->get_result()->fetch_row()[0];
    }

    // ── Get this user's last known state ───────────────────────────────────
    $lastStmt = $conn->prepare("SELECT last_count, last_notified_at FROM notification_last_counts WHERE admin_id = ?");
    $lastStmt->bind_param("i", $admin_id);
    $lastStmt->execute();
    $lastRow = $lastStmt->get_result()->fetch_assoc();

    $lastCount = $lastRow ? (int) $lastRow['last_count'] : 0;
    $lastNotifiedAt = $lastRow ? $lastRow['last_notified_at'] : null;

    $shouldNotify = false;
    $notifyReason = '';

    // ── Check A: did the count go up? ──────────────────────────────────────
    if ($totalPending > $lastCount) {
        $shouldNotify = true;
        $notifyReason = 'new';
    }
    // ── Check B: reminder — still pending, and enough time has passed ──────
    elseif ($totalPending > 0) {
        if (!$lastNotifiedAt || (strtotime('now') - strtotime($lastNotifiedAt)) >= ($REMINDER_INTERVAL_HOURS * 3600)) {
            $shouldNotify = true;
            $notifyReason = 'reminder';
        }
    }

    if ($shouldNotify) {
        $title = $notifyReason === 'new' ? 'New activity' : 'Pending tasks reminder';
        $body = "You have {$totalPending} pending item" . ($totalPending == 1 ? '' : 's') . " that need your attention.";

        $redirectUrl = BASE_URL . 'login';
        send_push_notification($conn, $admin_id, $title, $body, $redirectUrl);

        // Update last_notified_at since we just sent a push
        $updateStmt = $conn->prepare("
            INSERT INTO notification_last_counts (admin_id, last_count, last_notified_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE last_count = VALUES(last_count), last_notified_at = VALUES(last_notified_at)
        ");
        $updateStmt->bind_param("ii", $admin_id, $totalPending);
        $updateStmt->execute();
    } else {
        // No push sent, but still update the count in case it changed
        $updateStmt = $conn->prepare("
            INSERT INTO notification_last_counts (admin_id, last_count)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE last_count = VALUES(last_count)
        ");
        $updateStmt->bind_param("ii", $admin_id, $totalPending);
        $updateStmt->execute();
    }
}

echo "Push notification check completed.\n";