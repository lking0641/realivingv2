<?php
//update_spinwheel_segment.php
session_start();
include '../../connection/connection.php';
include '../checkrole/checkrole.php';
require_role(['admin1','admin2','superadmin','sales']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
}

$action = $_POST['action'] ?? 'update';

// ── DELETE ──
if ($action === 'delete') {
    $id = (int)$_POST['segment_id'];
    $conn->query("DELETE FROM spinwheel_segments WHERE id=$id");
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
}

// ── INSERT ──
if ($action === 'insert') {
    $label       = $conn->real_escape_string(trim($_POST['label']));
    $color       = $conn->real_escape_string(trim($_POST['color']));
    $probability = max(1, (int)$_POST['probability']);
    $is_active   = isset($_POST['is_active']) ? 1 : 0;
    $conn->query("INSERT INTO spinwheel_segments (label, color, probability, is_active)
                  VALUES ('$label', '$color', $probability, $is_active)");
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
}

// ── UPDATE (default) ──
$id          = (int)$_POST['segment_id'];
$label       = $conn->real_escape_string(trim($_POST['label']));
$color       = $conn->real_escape_string(trim($_POST['color']));
$probability = max(1, (int)$_POST['probability']);
$is_active   = isset($_POST['is_active']) ? 1 : 0;
$conn->query("UPDATE spinwheel_segments SET label='$label', color='$color', probability=$probability, is_active=$is_active WHERE id=$id");
header("Location: " . $_SERVER['HTTP_REFERER']);
exit();