<?php
// mainbody_data.php
// Everything that talks to the session/DB and produces the plain PHP
// variables the nav markup needs ($user_role, $is_head_user, badge counts,
// $_current_nav_section, etc). No HTML here.
//
// Requires: $conn (DB connection), $includes array, and mainbody_helpers.php
// already loaded (for getNavSectionByFile()).

session_start();
include $includes['connection'];
include $includes['checkrole'];
include $includes['online_status'];

// Redirect if not logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_role'])) {
  header("Location: " . BASE_URL . "login");
  exit();
}

// ── Single active session enforcement ──────────────────────────
// If this browser's session token no longer matches what's in the DB,
// it means this account logged in somewhere else — kick this session out.
$sessionCheckStmt = $conn->prepare("SELECT active_session_token FROM account WHERE id = ?");
$sessionCheckStmt->bind_param("i", $_SESSION['admin_id']);
$sessionCheckStmt->execute();
$sessionCheckRow = $sessionCheckStmt->get_result()->fetch_assoc();
$sessionCheckStmt->close();

$dbToken = $sessionCheckRow['active_session_token'] ?? null;

if (
  empty($_SESSION['session_token']) ||
  empty($dbToken) ||
  !hash_equals($dbToken, $_SESSION['session_token'])
) {
  session_unset();
  session_destroy();
  header("Location: " . BASE_URL . "login?kicked=1");
  exit();
}

$user_role = $_SESSION['admin_role'];

// Check is_head for designer and technical_designer
$is_head_user = false;
if (in_array($user_role, ['designer', 'technical_designer'])) {
  $headStmt = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
  $headStmt->bind_param("i", $_SESSION['admin_id']);
  $headStmt->execute();
  $headRow = $headStmt->get_result()->fetch_assoc();
  $is_head_user = !empty($headRow['is_head']);
}

// Get inquiry counts for badges (only for sales role)
$pending_appointments = 0;
$pending_concepts = 0;
$pending_contacts = 0;
$pending_projects = 0;

if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_role'])) {
  $admin_id = $_SESSION['admin_id'];
  $admin_role = $_SESSION['admin_role'];

  if (in_array($admin_role, ['sales', 'superadmin', 'admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6'])) {
    $apt_query = ($admin_role === 'superadmin')
      ? "SELECT COUNT(*) as count FROM appointments WHERE status='pending'"
      : "SELECT COUNT(*) as count FROM appointments WHERE status='pending' AND assigned_to = $admin_id";
    $apt_result = $conn->query($apt_query);
    if ($apt_result)
      $pending_appointments = $apt_result->fetch_assoc()['count'] ?? 0;

    $concept_query = ($admin_role === 'superadmin')
      ? "SELECT COUNT(*) as count FROM concept_inquiries WHERE status='pending'"
      : "SELECT COUNT(*) as count FROM concept_inquiries WHERE status='pending' AND assigned_to = $admin_id";
    $concept_result = $conn->query($concept_query);
    if ($concept_result)
      $pending_concepts = $concept_result->fetch_assoc()['count'] ?? 0;

    $contact_query = ($admin_role === 'superadmin')
      ? "SELECT COUNT(*) as count FROM contact WHERE status='pending'"
      : "SELECT COUNT(*) as count FROM contact WHERE status='pending' AND assigned_to = $admin_id";
    $contact_result = $conn->query($contact_query);
    if ($contact_result)
      $pending_contacts = $contact_result->fetch_assoc()['count'] ?? 0;

    $project_query = ($admin_role === 'superadmin')
      ? "SELECT COUNT(*) as count FROM project_inquiries WHERE status='pending'"
      : "SELECT COUNT(*) as count FROM project_inquiries WHERE status='pending' AND assigned_to = $admin_id";
    $project_result = $conn->query($project_query);
    if ($project_result)
      $pending_projects = $project_result->fetch_assoc()['count'] ?? 0;
  }
}

// ── TD pending approvals for approver roles ──────────────────────────────
$td_pending_approvals = 0;
if (isset($_SESSION['admin_id']) && in_array($_SESSION['admin_role'], ['general_manager', 'operational_manager', 'technical_designer'])) {
  $tdApprId = $_SESSION['admin_id'];
  $tdApprStmt = $conn->prepare("
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
  $tdApprStmt->bind_param("i", $tdApprId);
  $tdApprStmt->execute();
  $td_pending_approvals = (int) $tdApprStmt->get_result()->fetch_row()[0];
}

// ── TD remark needed count (for assigned TD only) ─────────────────────────
$td_remark_needed = false;
if (isset($_SESSION['admin_id']) && $_SESSION['admin_role'] === 'technical_designer') {
  $tdRemarkId = $_SESSION['admin_id'];
  $tdRemarkStmt = $conn->prepare("
        SELECT COUNT(DISTINCT la.client_id) FROM layout_approvals la
        INNER JOIN user_info u ON u.id = la.client_id
        WHERE u.technical_designer_id = ?
        AND (la.td_remark IS NULL OR la.td_remark = '')
        AND la.requested_at IS NOT NULL
    ");
  $tdRemarkStmt->bind_param("i", $tdRemarkId);
  $tdRemarkStmt->execute();
  $td_remark_needed = (int) $tdRemarkStmt->get_result()->fetch_row()[0];
}

// Detect active section from the route slug the router resolved.
// Falls back to PHP_SELF only if a page bypasses the router.
$_current_route_slug = $GLOBALS['current_route_slug'] ?? basename($_SERVER['PHP_SELF']);
$_current_nav_section = getNavSectionByFile($_current_route_slug, $user_role);

// Build absolute URL for inquiry counts AJAX
$_is_local = (isset($_SERVER['HTTP_HOST']) && (
  $_SERVER['HTTP_HOST'] === 'localhost' ||
  str_starts_with($_SERVER['HTTP_HOST'], '127.0.0.1') ||
  str_starts_with($_SERVER['HTTP_HOST'], '192.168.')
));
$_protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host = $_SERVER['HTTP_HOST'];
$_inquiry_counts_url = BASE_URL . 'get-inquiry-counts';