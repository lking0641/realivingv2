<?php
session_start();
include '../../connection/connection.php';
include '../checkrole/checkrole.php';
require_role(['admin1','admin2','superadmin','sales']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
}

$action = $_POST['action'] ?? '';

// ── DELETE ──
if ($action === 'delete') {
    $id = (int)$_POST['pity_id'];
    $conn->query("DELETE FROM spinwheel_pity_settings WHERE id=$id");
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
}

// ── INSERT ──
if ($action === 'insert') {
    $label     = $conn->real_escape_string(trim($_POST['prize_label']));
    $threshold = max(0, (int)$_POST['pity_threshold']);
    $conn->query("INSERT INTO spinwheel_pity_settings (prize_label, pity_threshold, current_window_count, window_won)
                  VALUES ('$label', $threshold, 0, 0)");
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
}

// ── RESET COUNTERS ──
if ($action === 'reset_all') {
    $conn->query("UPDATE spinwheel_pity_settings SET current_window_count=0, window_won=0");
    $conn->query("UPDATE spinwheel_global_counter SET total_spins=0 WHERE id=1");
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
}

// ── UPDATE ──
if ($action === 'update') {
    $id        = (int)$_POST['pity_id'];
    $label     = $conn->real_escape_string(trim($_POST['prize_label']));
    $threshold = max(0, (int)$_POST['pity_threshold']);
    $conn->query("UPDATE spinwheel_pity_settings 
                  SET prize_label='$label', pity_threshold=$threshold 
                  WHERE id=$id");
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
}

header("Location: " . $_SERVER['HTTP_REFERER']);
exit();