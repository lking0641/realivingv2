<?php
//update_pity_settings.php
session_start();
include $includes ['connection'];
include $includes ['checkrole'];

require_role(['sales']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . BASE_URL . "spinwheel-registrations-dashboard");
    exit();
}

$action = $_POST['action'] ?? '';

// ── DELETE ──
if ($action === 'delete') {
    $id = (int)$_POST['pity_id'];
    $stmt = $conn->prepare("DELETE FROM spinwheel_pity_settings WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: " . BASE_URL . "spinwheel-registrations-dashboard");
    exit();
}

// ── INSERT ──
if ($action === 'insert') {
    $label     = trim($_POST['prize_label']);
    $threshold = max(0, (int)$_POST['pity_threshold']);
    $stmt = $conn->prepare("INSERT INTO spinwheel_pity_settings (prize_label, pity_threshold, current_window_count, window_won) VALUES (?, ?, 0, 0)");
    $stmt->bind_param("si", $label, $threshold);
    $stmt->execute();
    header("Location: " . BASE_URL . "spinwheel-registrations-dashboard");
    exit();
}

// ── RESET COUNTERS ──
if ($action === 'reset_all') {
    $conn->query("UPDATE spinwheel_pity_settings SET current_window_count=0, window_won=0");
    $conn->query("UPDATE spinwheel_global_counter SET total_spins=0 WHERE id=1");
    header("Location: " . BASE_URL . "spinwheel-registrations-dashboard");
    exit();
}

// ── UPDATE ──
if ($action === 'update') {
    $id        = (int)$_POST['pity_id'];
    $label     = trim($_POST['prize_label']);
    $threshold = max(0, (int)$_POST['pity_threshold']);
    $stmt = $conn->prepare("UPDATE spinwheel_pity_settings SET prize_label=?, pity_threshold=? WHERE id=?");
    $stmt->bind_param("sii", $label, $threshold, $id);
    $stmt->execute();
    header("Location: " . BASE_URL . "spinwheel-registrations-dashboard");
    exit();
}

header("Location: " . BASE_URL . "spinwheel-registrations-dashboard");
exit();