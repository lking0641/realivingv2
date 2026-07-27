<?php
// update_room_distribution.php
session_start();
include $includes ['connection'];
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$data   = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

// ── Helper: recalculate and sync quantity back to parent table ──
function recalcAndSync($conn, $entry_id, $fixed_size_id) {
    $newTotal = 0;
    if ($entry_id) {
        $s = $conn->prepare("
            SELECT COALESCE(SUM(quantity), 0) as total
            FROM quotation_room_distribution
            WHERE quotation_entry_id = ?
        ");
        $s->bind_param("i", $entry_id);
        $s->execute();
        $newTotal = (int)$s->get_result()->fetch_assoc()['total'];
        $s->close();

        $u = $conn->prepare("UPDATE quotation_entries SET quantity = ? WHERE id = ?");
        $u->bind_param("ii", $newTotal, $entry_id);
        $u->execute();
        $u->close();

    } elseif ($fixed_size_id) {
        $s = $conn->prepare("
            SELECT COALESCE(SUM(quantity), 0) as total
            FROM quotation_room_distribution
            WHERE quotation_fixed_size_id = ?
        ");
        $s->bind_param("i", $fixed_size_id);
        $s->execute();
        $newTotal = (int)$s->get_result()->fetch_assoc()['total'];
        $s->close();

        $u = $conn->prepare("UPDATE quotation_fixed_sizes SET quantity = ? WHERE id = ?");
        $u->bind_param("ii", $newTotal, $fixed_size_id);
        $u->execute();
        $u->close();
    }
    return $newTotal;
}

// ══════════════════════════════════════════
// ADD
// ══════════════════════════════════════════
if ($action === 'add') {
    $entry_id      = isset($data['entry_id'])      ? intval($data['entry_id'])      : 0;
    $fixed_size_id = isset($data['fixed_size_id']) ? intval($data['fixed_size_id']) : 0;
    $room_number   = isset($data['room_number'])   ? intval($data['room_number'])   : 0;
    $room_name     = isset($data['room_name'])     ? trim($data['room_name'])       : '';
    $quantity      = isset($data['quantity'])      ? intval($data['quantity'])      : 1;
    $notes         = isset($data['notes'])         ? trim($data['notes'])           : '';

    if (!$room_number || $quantity < 1) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        exit();
    }

    if ($entry_id) {
        // Check if this room already exists for this entry
        $check = $conn->prepare("
            SELECT distribution_id FROM quotation_room_distribution
            WHERE quotation_entry_id = ? AND room_unit_number = ?
        ");
        $check->bind_param("ii", $entry_id, $room_number);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            $stmt = $conn->prepare("
                UPDATE quotation_room_distribution
                SET quantity = quantity + ?, notes = ?
                WHERE quotation_entry_id = ? AND room_unit_number = ?
            ");
            $stmt->bind_param("isii", $quantity, $notes, $entry_id, $room_number);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO quotation_room_distribution
                    (quotation_entry_id, room_unit_number, room_unit_name, quantity, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iisis", $entry_id, $room_number, $room_name, $quantity, $notes);
        }
        $stmt->execute();
        $stmt->close();

    } elseif ($fixed_size_id) {
        $check = $conn->prepare("
            SELECT distribution_id FROM quotation_room_distribution
            WHERE quotation_fixed_size_id = ? AND room_unit_number = ?
        ");
        $check->bind_param("ii", $fixed_size_id, $room_number);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            $stmt = $conn->prepare("
                UPDATE quotation_room_distribution
                SET quantity = quantity + ?, notes = ?
                WHERE quotation_fixed_size_id = ? AND room_unit_number = ?
            ");
            $stmt->bind_param("isii", $quantity, $notes, $fixed_size_id, $room_number);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO quotation_room_distribution
                    (quotation_fixed_size_id, room_unit_number, room_unit_name, quantity, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iisis", $fixed_size_id, $room_number, $room_name, $quantity, $notes);
        }
        $stmt->execute();
        $stmt->close();

    } else {
        echo json_encode(['success' => false, 'error' => 'No entry_id or fixed_size_id provided']);
        exit();
    }

    $newTotal = recalcAndSync($conn, $entry_id, $fixed_size_id);
    echo json_encode(['success' => true, 'new_total_quantity' => $newTotal]);

// ══════════════════════════════════════════
// DELETE
// ══════════════════════════════════════════
} elseif ($action === 'delete') {
    $dist_id       = isset($data['dist_id'])       ? intval($data['dist_id'])       : 0;
    $entry_id      = isset($data['entry_id'])      ? intval($data['entry_id'])      : 0;
    $fixed_size_id = isset($data['fixed_size_id']) ? intval($data['fixed_size_id']) : 0;

    if (!$dist_id) {
        echo json_encode(['success' => false, 'error' => 'No dist_id provided']);
        exit();
    }

    $del = $conn->prepare("DELETE FROM quotation_room_distribution WHERE distribution_id = ?");
    $del->bind_param("i", $dist_id);
    $del->execute();
    $del->close();

    $newTotal = recalcAndSync($conn, $entry_id, $fixed_size_id);
    echo json_encode(['success' => true, 'new_total_quantity' => $newTotal]);

// ══════════════════════════════════════════
// UPDATE QTY
// ══════════════════════════════════════════
} elseif ($action === 'update_qty') {
    $dist_id       = isset($data['dist_id'])       ? intval($data['dist_id'])       : 0;
    $quantity      = isset($data['quantity'])      ? intval($data['quantity'])      : 1;
    $entry_id      = isset($data['entry_id'])      ? intval($data['entry_id'])      : 0;
    $fixed_size_id = isset($data['fixed_size_id']) ? intval($data['fixed_size_id']) : 0;

    if (!$dist_id || $quantity < 1) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        exit();
    }

    $upd = $conn->prepare("UPDATE quotation_room_distribution SET quantity = ? WHERE distribution_id = ?");
    $upd->bind_param("ii", $quantity, $dist_id);
    $upd->execute();
    $upd->close();

    $newTotal = recalcAndSync($conn, $entry_id, $fixed_size_id);
    echo json_encode(['success' => true, 'new_total_quantity' => $newTotal]);

} else {
    echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
}
?>