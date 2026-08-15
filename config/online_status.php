<?php
// includes/online_status.php
// Single source of truth for "is this admin actually online right now."
// A stale is_online=1 flag alone isn't trustworthy — we also require
// a recent heartbeat (last_activity) within the threshold below.

if (!defined('ONLINE_THRESHOLD_SECONDS')) {
  define('ONLINE_THRESHOLD_SECONDS', 90);
}

/**
 * Returns a SQL condition fragment to use inside a WHERE clause.
 * Example: "SELECT COUNT(*) FROM account WHERE " . getOnlineSqlCondition()
 */
function getOnlineSqlCondition($table_alias = '') {
  $prefix = $table_alias ? "$table_alias." : '';
  return "{$prefix}is_online = 1 AND {$prefix}last_activity > NOW() - INTERVAL " . ONLINE_THRESHOLD_SECONDS . " SECOND";
}

/**
 * PHP-side check for rows already pulled from the DB
 * (e.g. when you fetched is_online + last_activity together).
 */
function isAdminOnline($is_online, $last_activity) {
  if (!$is_online || !$last_activity) return false;
  $lastTs = strtotime($last_activity);
  if ($lastTs === false) return false;
  return (time() - $lastTs) <= ONLINE_THRESHOLD_SECONDS;
}