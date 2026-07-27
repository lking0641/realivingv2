<?php
// update_computation.php
$data     = json_decode(file_get_contents('php://input'), true);
$entry_id = intval($data['entry_id']);
$isTextField = in_array($data['field'], ['item_name']);
$field = preg_match(
    '/^(width|height|length|unit_price|labor_cost|mark_up|jackup|quantity|item_name)$/',
    $data['field']
) ? $data['field'] : null;
$value = $isTextField ? trim($data['value']) : floatval($data['value']);

if (!$field) {
    http_response_code(400);
    exit;
}

include $includes ['connection'];

// 1) Update the single edited field
$stmt = $conn->prepare("UPDATE quotation_entries SET {$field} = ? WHERE id = ?");
if ($isTextField) {
    $stmt->bind_param("si", $value, $entry_id);
} else {
    $stmt->bind_param("di", $value, $entry_id);
}
$stmt->execute();

// Early exit for text-only fields — no need to recompute dimensions
if ($isTextField) {
    http_response_code(204);
    exit;
}

// 2) Re‑fetch the entire row + dimension flags/startups
$stmt2 = $conn->prepare("
  SELECT
    e.width, e.height, e.length,
    e.unit_price, e.labor_cost, e.jackup, e.mark_up,
    e.unit_mode, e.dimension_msmt_id,
      e.quantity,
    d.item_width_linear,  d.startup_width_linear,
    d.item_height_linear, d.startup_height_linear,
    d.item_length_linear, d.startup_length_linear,
    d.item_width_sqm,     d.startup_width_sqm,
    d.item_height_sqm,    d.startup_height_sqm,
    d.item_length_sqm,    d.startup_length_sqm
  FROM quotation_entries AS e
  JOIN dimension_measurement   AS d
    ON e.dimension_msmt_id = d.dimension_msmt_id
  WHERE e.id = ?
");
$stmt2->bind_param("i", $entry_id);
$stmt2->execute();
$row = $stmt2->get_result()->fetch_assoc();

// 3) Compute computed_unit
$isLinear = $row['unit_mode'] === 'linear';
$isSqm    = $row['unit_mode'] === 'sqm';
$rawW = $row['width'];   $rawH = $row['height'];   $rawL = $row['length'];

if ($isLinear) {
    // linear: pick whichever axis has item_*_linear == 0
    if ((int)$row['item_width_linear']   === 0) {
        $computedUnit = $rawW / 1000;
    } elseif ((int)$row['item_height_linear'] === 0) {
        $computedUnit = $rawH / 1000;
    } else {
        $computedUnit = $rawL / 1000;
    }

} elseif ($isSqm) {
    // sqm: multiply the two axes whose item_*_sqm == 0
    $parts = [];
    if ((int)$row['item_width_sqm']  === 0) $parts[] = $rawW / 1000;
    if ((int)$row['item_height_sqm'] === 0) $parts[] = $rawH / 1000;
    if ((int)$row['item_length_sqm'] === 0) $parts[] = $rawL / 1000;
    $computedUnit = (count($parts) === 2) ? $parts[0] * $parts[1] : 1;
} else {
    $computedUnit = 1;
}

// 4) Base mats & labor
$quantity  = max(1, (int)$row['quantity']);
$baseMats  = $computedUnit * $row['unit_price'] * $quantity;
$baseLabor = $computedUnit * $row['labor_cost'] * $quantity;

// 5) Jack‑up logic — percentage of total base mats
$jackupPct = $row['jackup'] / 100;
$jackupAmt = $baseMats * $jackupPct;

// 7) Final computed totals
$computed_tot_mats   = $baseMats + $jackupAmt;
$computed_tot_labor  = $baseLabor;
$computed_tot_amount = $computed_tot_mats + $computed_tot_labor;

// 8) Persist all computed columns
$stmt3 = $conn->prepare("
  UPDATE quotation_entries
     SET computed_unit       = ?,
         computed_tot_mats   = ?,
         computed_tot_labor  = ?,
         computed_tot_amount = ?
   WHERE id = ?
");
$stmt3->bind_param(
  "ddddi",
  $computedUnit,
  $computed_tot_mats,
  $computed_tot_labor,
  $computed_tot_amount,
  $entry_id
);
$stmt3->execute();

// 9) Return no content
http_response_code(204);
