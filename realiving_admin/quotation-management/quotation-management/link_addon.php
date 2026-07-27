<?php
// link_addon.php
session_start();
include $includes ['connection'];

header('Content-Type: application/json');
if (!isset($_SESSION['admin_id'])) { echo json_encode(['success'=>false,'error'=>'Not logged in']); exit; }

$data = json_decode(file_get_contents('php://input'), true);
$addon_id  = intval($data['addon_id']  ?? 0);
$linked_id = isset($data['linked_id']) && $data['linked_id'] !== '' ? intval($data['linked_id']) : null;

if (!$addon_id) { echo json_encode(['success'=>false,'error'=>'Missing addon_id']); exit; }

$stmt = $conn->prepare("UPDATE quotation_entry_addons SET linked_dimension_addon_id = ? WHERE id = ?");
$stmt->bind_param("ii", $linked_id, $addon_id);
$stmt->execute();

echo json_encode(['success'=>true]);