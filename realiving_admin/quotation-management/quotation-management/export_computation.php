<?php
// export_quotation_pdf.php
session_start();
include $includes ['connection'];
include $includes ['checkrole'];
require_role(['designer', 'technical_designer', 'sales', 'project_coordinator']);

if (!isset($_SESSION['admin_id'])) {
  header("Location: ../login.php");
  exit();
}

$admin_id = $_SESSION['admin_id'];

$adminStmt = $conn->prepare("SELECT COALESCE(admin_name, full_name) AS display_name, e_signature FROM account WHERE id = ?");
$adminStmt->bind_param("i", $admin_id);
$adminStmt->execute();
$adminResult = $adminStmt->get_result();
$adminData = $adminResult->fetch_assoc();
$admin_name = $adminData['display_name'] ?? 'Unknown Admin';
$admin_esignature = $adminData['e_signature'] ?? '';
// normalize path: e_signature is stored as "../../uploads/signatures/sig_X.png"
// resolve it relative to this file's location
if (!empty($admin_esignature)) {
  $admin_esignature = realpath(dirname(__FILE__) . '/' . $admin_esignature);
}

$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$client_name = isset($_GET['client_name']) ? urldecode($_GET['client_name']) : '';
$safe_client_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $client_name);

$project_name = '';
$business_type = '';
$project_scope = '';
$scope_of_work = '';
$client_email = '';
$client_address = '';
$client_contact = '';

if ($client_id) {
  $stmt = $conn->prepare("
        SELECT
            ui.nameproject, ui.business_type, ui.project_scope, ui.scope_of_work,
            ui.email, ui.address, ui.contact,
            COALESCE(a.admin_name, a.full_name) AS assigned_admin_name
        FROM user_info ui
        LEFT JOIN account a ON ui.accountaid_fk = a.id
        WHERE ui.id = ?
    ");
  $stmt->bind_param("i", $client_id);
  $stmt->execute();
  $result = $stmt->get_result();
  if ($row = $result->fetch_assoc()) {
    $project_name = $row['nameproject'];
    $business_type = $row['business_type'];
    $project_scope = $row['project_scope'];
    $scope_of_work = $row['scope_of_work'];
    $client_email = $row['email'];
    $client_address = $row['address'];
    $client_contact = $row['contact'];
    if (($admin_name === 'Unknown Admin') && !empty($row['assigned_admin_name'])) {
      $admin_name = $row['assigned_admin_name'];
    }
  }
}

$q = $conn->prepare("
    SELECT
        e.id AS entry_id,
        e.color_image, e.color_label,
        e.dimension_msmt_id, e.dimension_label_id, e.jackup,
        e.width, e.height, e.length,
        e.width_label, e.height_label, e.length_label,
        d.item_width_linear,  d.startup_width_linear,
        d.item_height_linear, d.startup_height_linear,
        d.item_length_linear, d.startup_length_linear,
        d.item_width_sqm,     d.startup_width_sqm,
        d.item_height_sqm,    d.startup_height_sqm,
        d.item_length_sqm,    d.startup_length_sqm,
        e.unit_price, e.labor_cost, e.mark_up,
        e.quantity, e.area,
        e.unit_type, e.unit_mode,
        e.computed_unit, e.computed_tot_mats,
        e.computed_tot_labor, e.computed_tot_amount,
        e.entry_item_id,
        COALESCE(e.item_name, i.item_name) AS item_name
    FROM quotation_entries AS e
    LEFT JOIN items AS i ON e.entry_item_id = i.item_id
    LEFT JOIN dimension_measurement AS d ON e.dimension_msmt_id = d.dimension_msmt_id
    WHERE e.client_id = ? AND e.admin_id = ?
    ORDER BY e.area, e.created_at DESC
");
$q->bind_param("ii", $client_id, $admin_id);
$q->execute();
$result = $q->get_result();
$entriesArr = [];
while ($r = $result->fetch_assoc())
  $entriesArr[] = $r;

$qFixed = $conn->prepare("
    SELECT
        qfs.id, qfs.item_name, qfs.base_price, qfs.quantity,
        qfs.unit_type, qfs.area, qfs.color_label,
        ifs.size_label, ifs.size_width, ifs.size_height, ifs.size_length, ifs.measurement_unit,
        dl.dimension_label_name,
        dl.item_width_label_linear, dl.item_height_label_linear, dl.item_length_label_linear
    FROM quotation_fixed_sizes AS qfs
    LEFT JOIN item_fixed_sizes AS ifs ON qfs.fixed_size_id = ifs.fixed_size_id
    LEFT JOIN dimension_label AS dl ON ifs.dimension_label_fk = dl.dimension_label_id
    WHERE qfs.client_id = ? AND qfs.admin_id = ?
    ORDER BY qfs.area, qfs.created_at DESC
");
$qFixed->bind_param("ii", $client_id, $admin_id);
$qFixed->execute();
$resultFixed = $qFixed->get_result();
$fixedEntriesArr = [];
while ($r = $resultFixed->fetch_assoc())
  $fixedEntriesArr[] = $r;

$areasStmt = $conn->prepare("
    SELECT DISTINCT area FROM quotation_entries WHERE client_id = ? AND admin_id = ?
    UNION
    SELECT DISTINCT area FROM quotation_fixed_sizes WHERE client_id = ? AND admin_id = ?
    ORDER BY area
");
$areasStmt->bind_param("iiii", $client_id, $admin_id, $client_id, $admin_id);
$areasStmt->execute();
$areasRes = $areasStmt->get_result();
$areas = [];
while ($a = $areasRes->fetch_assoc())
  $areas[] = $a['area'];

$addonsStmt = $conn->prepare("
    SELECT a.id AS addon_entry_id, a.quotation_entry_id, a.quantity, a.price,
           a.labor_cost, a.note, a.computed_area,
           a.addon_jackup, a.linked_dimension_addon_id,
           p.addon_name, p.addon_image_path,
           p.multiply_value, p.is_stable_mat, p.min_required_unit,
           p.required_unit, p.max_quantity
    FROM quotation_entry_addons AS a
    JOIN product_addons AS p ON a.addon_id = p.id
    WHERE a.quotation_entry_id IN (
        SELECT id FROM quotation_entries WHERE client_id = ? AND admin_id = ?
    )
");
$addonsStmt->bind_param("ii", $client_id, $admin_id);
$addonsStmt->execute();
$addonsResult = $addonsStmt->get_result();
$addonsData = [];
while ($addon = $addonsResult->fetch_assoc()) {
  $addonsData[$addon['quotation_entry_id']][] = $addon;
}

$fixedAddonsStmt = $conn->prepare("
    SELECT a.id, a.quotation_fixed_size_id, a.quantity, a.price,
           a.labor_cost, a.note, a.computed_area, a.addon_category,
           a.addon_jackup, a.linked_dimension_addon_id,
           p.addon_name,
           p.multiply_value, p.is_stable_mat, p.min_required_unit,
           p.required_unit, p.max_quantity
    FROM quotation_fixed_size_addons AS a
    JOIN product_addons AS p ON a.addon_id = p.id
    WHERE a.quotation_fixed_size_id IN (
        SELECT id FROM quotation_fixed_sizes WHERE client_id = ? AND admin_id = ?
    )
");
$fixedAddonsStmt->bind_param("ii", $client_id, $admin_id);
$fixedAddonsStmt->execute();
$fixedAddonsResult = $fixedAddonsStmt->get_result();
$fixedAddonsData = [];
while ($addon = $fixedAddonsResult->fetch_assoc()) {
  $fixedAddonsData[$addon['quotation_fixed_size_id']][] = $addon;
}

// ── HELPER: compute addon totals ──
function computeAddonTotals($price, $laborCost, $jackup, $computedArea, $qty, $isStableMat, $multiplyValue, $minRequiredUnit)
{
  $aComputedArea = floatval($computedArea);
  $aEffUnit = $aComputedArea > 0 ? $aComputedArea : 1;
  $aJackAmt = floatval($price) * (floatval($jackup) / 100);
  $aMinReq = floatval($minRequiredUnit);
  $aMultiply = floatval($multiplyValue);
  $aIsStable = intval($isStableMat);
  $aQty = intval($qty);
  $aLaborUnit = ($aMinReq > 0 && $aEffUnit < $aMinReq) ? 1 : $aEffUnit;
  if ($aIsStable) {
    $aRawMats = (floatval($price) * $aQty) + ($aJackAmt * $aQty);
  } else {
    $aRawMats = (floatval($price) * $aEffUnit * $aQty) + ($aJackAmt * $aQty);
  }
  $aTotMats = round($aMultiply > 0 ? $aRawMats * $aMultiply : $aRawMats, 2);
  $aTotLabor = round(floatval($laborCost) * $aLaborUnit * $aQty, 2);
  $aTotal = round($aTotMats + $aTotLabor, 2);
  $aPpi = $aQty > 0 ? round($aTotal / $aQty, 2) : $aTotal;
  return ['tot_mats' => $aTotMats, 'tot_labor' => $aTotLabor, 'total' => $aTotal, 'price_per_item' => $aPpi];
}

$grandMats = 0;
$grandLabor = 0;
$grandAddons = 0;
$grandFixed = 0;

foreach ($entriesArr as $eRow) {
  $eMode = $eRow['unit_mode'];
  $eW = floatval($eRow['width']);
  $eH = floatval($eRow['height']);
  $eL = floatval($eRow['length']);
  if ($eMode === 'linear') {
    $eFlagW = intval($eRow['item_width_linear']);
    $eFlagH = intval($eRow['item_height_linear']);
    if ($eFlagW === 0)
      $eUnit = $eW / 1000;
    elseif ($eFlagH === 0)
      $eUnit = $eH / 1000;
    else
      $eUnit = $eL / 1000;
  } elseif ($eMode === 'sqm') {
    $eVals = [];
    if (intval($eRow['item_width_sqm']) === 0)
      $eVals[] = $eW / 1000;
    if (intval($eRow['item_height_sqm']) === 0)
      $eVals[] = $eH / 1000;
    if (intval($eRow['item_length_sqm']) === 0)
      $eVals[] = $eL / 1000;
    $eUnit = count($eVals) === 2 ? $eVals[0] * $eVals[1] : (count($eVals) === 1 ? $eVals[0] : 1);
  } else {
    $eUnit = 1;
  }
  $eQty = intval($eRow['quantity']);
  $eUp = floatval($eRow['unit_price']);
  $eLc = floatval($eRow['labor_cost']);
  $eJackupPct = floatval($eRow['jackup']) / 100;
  $eBaseMats = $eUnit * $eUp * $eQty;
  $eBaseLabor = $eUnit * $eLc * $eQty;
  $eJackAmt = $eBaseMats * $eJackupPct;
  $eTotMats = round($eBaseMats + $eJackAmt, 2);
  $eTotLabor = round($eBaseLabor, 2);
  $grandMats += $eTotMats > 0 ? $eTotMats : round(floatval($eRow['computed_tot_mats']), 2);
  $grandLabor += $eTotLabor > 0 ? $eTotLabor : round(floatval($eRow['computed_tot_labor']), 2);
  if (isset($addonsData[$eRow['entry_id']])) {
    foreach ($addonsData[$eRow['entry_id']] as $addon) {
      $calc = computeAddonTotals($addon['price'], $addon['labor_cost'] ?? 0, $addon['addon_jackup'] ?? 0, $addon['computed_area'] ?? 0, $addon['quantity'], $addon['is_stable_mat'] ?? 0, $addon['multiply_value'] ?? 0, $addon['min_required_unit'] ?? 0);
      $grandAddons += $calc['total'];
    }
  }
}
foreach ($fixedEntriesArr as $row) {
  $grandFixed += round(floatval($row['base_price']) * intval($row['quantity']), 2);
  if (isset($fixedAddonsData[$row['id']])) {
    foreach ($fixedAddonsData[$row['id']] as $addon) {
      $calc = computeAddonTotals($addon['price'], $addon['labor_cost'] ?? 0, $addon['addon_jackup'] ?? 0, $addon['computed_area'] ?? 0, $addon['quantity'], $addon['is_stable_mat'] ?? 0, $addon['multiply_value'] ?? 0, $addon['min_required_unit'] ?? 0);
      $grandAddons += $calc['total'];
    }
  }
}

$discStmt = $conn->prepare("SELECT discount FROM user_info WHERE id = ?");
$discStmt->bind_param("i", $client_id);
$discStmt->execute();
$discRes = $discStmt->get_result()->fetch_assoc();
$storedDiscount = floatval($discRes['discount'] ?? 0);

$rawTotal = $grandMats + $grandLabor + $grandAddons + $grandFixed;
$discPct = $storedDiscount / 100;
$afterDiscount = $rawTotal * (1 - $discPct);
$generalReq = 0;
$subtotalWithGR = 0;
$vat = 0;
$finalTotal = $afterDiscount;
if ($business_type === 'Project') {
  $generalReq = $afterDiscount * 0.10;
  $subtotalWithGR = $afterDiscount + $generalReq;
  $vat = $subtotalWithGR * 0.12;
  $finalTotal = $subtotalWithGR + $vat;
}

$display_rawTotal = round($rawTotal, 2);
$display_afterDiscount = round($afterDiscount, 2);
$display_discountAmt = round($rawTotal * $discPct, 2);
$display_generalReq = round($generalReq, 2);
$display_subtotalWithGR = round($subtotalWithGR, 2);
$display_vat = round($vat, 2);
$display_finalTotal = round($finalTotal, 2);

require_once ROOT_PATH . 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// ── PHP money formatter ──
function phpFmt($val)
{
  return number_format(floatval($val), 2);
}

// ── COLOR PALETTE (plain letterhead theme, matches Excel export) ──
$CLR = [
  'header_bg' => '#FFFFFF',
  'accent' => '#666666',
  'accent_gold' => '#FFE066',
  'accent_light' => '#F0F0F0',
  'col_head_bg' => '#F0F0F0',
  'col_sub_bg' => '#F7F7F7',
  'area_bg' => '#EFEFEF',
  'area_text' => '#1A1A1A',
  'row_alt' => '#F7F9FC',
  'row_white' => '#FFFFFF',
  'fixed_bg' => '#F4F2FF',
  'addon_bg' => '#FFFAEC',
  'addon_hdr' => '#FFE8A0',
  'total_bg' => '#FFFFFF',
  'grand_bg' => '#FFE066',
  'border' => '#333333',
  'border_dark' => '#000000',
  'scope_bg' => '#FFFFFF',
];

// ── Build HTML ──
ob_start();
?>
<!DOCTYPE html>
<html>

<head>
  <meta charset="UTF-8">
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: DejaVu Sans, sans-serif;
      font-size: 7.5pt;
      color: #333;
      background: #fff;
    }

    .page-wrap {
      width: 100%;
    }

    /* ── HEADER ── */
    .header-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 0;
      border-bottom: 2px solid #333333;
    }

    .header-logo-cell {
      width: 30%;
      background: #fff;
      padding: 8px 8px;
      vertical-align: middle;
    }

    .header-logo-cell img {
      height: 95px;
      max-width: 220px;
    }

    .header-right-cell {
      width: 70%;
      background: #FFFFFF;
      padding: 8px 12px 8px 20px;
      vertical-align: middle;
      text-align: left;
    }

    .header-right-cell .title-quot {
      font-size: 23pt;
      font-weight: bold;
      color: #1A1A1A;
      line-height: 1.1;
      letter-spacing: 1px;
    }

    .header-right-cell .company-name {
      font-size: 9.5pt;
      font-weight: bold;
      color: #1A1A1A;
      margin-top: 3px;
    }

    .header-right-cell .company-addr {
      font-size: 7pt;
      color: #555555;
      font-style: italic;
      margin-top: 1px;
    }

    .accent-bar {
      display: none;
    }

    /* ── INFO CARD ── */
    .info-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 6px;
    }

    .info-table td {
      padding: 3px 5px;
      border: 1px solid #333333;
      font-size: 7.5pt;
      vertical-align: middle;
    }

    .info-label {
      background: #F0F2F5;
      color: #444;
      font-weight: bold;
      font-size: 6.5pt;
      width: 60px;
    }

    .info-value {
      background: #fff;
      color: #1A1A1A;
      font-weight: bold;
    }

    /* ── MAIN TABLE ── */
    .main-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 0;
    }

    /* Title band */
    .band-title {
      background: #FFFFFF;
      color: #1A1A1A;
      font-size: 9.5pt;
      font-weight: bold;
      text-align: center;
      padding: 5px 4px;
      border: 1px solid #333333;
    }

    /* Column headers row 1 */
    .col-head-1 {
      background: #F0F0F0;
      color: #1A1A1A;
      font-size: 7pt;
      font-weight: bold;
      text-align: center;
      padding: 4px 3px;
      border: 1px solid #333333;
    }

    /* Column headers row 2 */
    .col-head-2 {
      background: #F7F7F7;
      color: #444444;
      font-size: 6.5pt;
      font-weight: bold;
      text-align: center;
      padding: 3px 2px;
      border: 1px solid #666666;
    }

    /* Scope of work row */
    .scope-row {
      background: #FFFFFF;
      color: #1A1A1A;
      font-size: 8.5pt;
      font-weight: bold;
      text-align: center;
      padding: 5px 4px;
      border: 1px solid #333333;
    }

    /* Area header */
    .area-no {
      background: #EFEFEF;
      color: #1A1A1A;
      font-size: 8pt;
      font-weight: bold;
      text-align: center;
      padding: 4px 3px;
      border: 1px solid #333333;
      vertical-align: middle;
    }

    .area-name {
      background: #EFEFEF;
      color: #1A1A1A;
      font-size: 8pt;
      font-weight: bold;
      text-align: left;
      padding: 4px 6px;
      border: 1px solid #333333;
      vertical-align: middle;
    }

    /* Data rows */
    .data-row-a {
      background: #FFFFFF;
    }

    .data-row-b {
      background: #F8F9FE;
    }

    .data-cell {
      padding: 3px 4px;
      border: 1px solid #888888;
      vertical-align: top;
      font-size: 7pt;
    }

    .data-cell-c {
      text-align: center;
    }

    .item-name {
      font-weight: bold;
      font-size: 8pt;
      color: #1A1A1A;
    }

    .item-dim {
      font-size: 6.5pt;
      color: #777777;
    }

    .num-cell {
      font-weight: bold;
      color: #1A1A1A;
      text-align: right;
    }

    .accent-left {
      border-left: 1px solid #333333 !important;
    }

    .purple-left {
      border-left: 3px solid #7060A0 !important;
    }

    /* Fixed size rows */
    .data-row-fixed-a {
      background: #F9F8FF;
    }

    .data-row-fixed-b {
      background: #F4F3FF;
    }

    .fixed-name {
      font-weight: bold;
      font-size: 8pt;
      color: #2D1B69;
    }

    .fixed-dim {
      font-size: 6.5pt;
      color: #7060A0;
    }

    .fixed-num {
      font-weight: bold;
      color: #2D1B69;
      text-align: right;
    }

    /* Accessories */
    .acc-banner {
      background: #F0F0F0;
      color: #1A1A1A;
      font-size: 6.5pt;
      font-weight: bold;
      padding: 2px 5px;
      border: 1px solid #888888;
    }

    .acc-col-head {
      background: #F7F7F7;
      color: #444444;
      font-size: 6pt;
      font-weight: bold;
      font-style: italic;
      text-align: center;
      padding: 2px 3px;
      border: 1px solid #888888;
    }

    .acc-data {
      background: #FFFFFF;
      color: #1A1A1A;
      font-size: 6.5pt;
      padding: 2px 4px;
      border: 1px solid #888888;
      vertical-align: middle;
    }

    .acc-data-c {
      text-align: center;
    }

    .acc-note {
      background: #FAFAFA;
      color: #888;
      font-size: 6pt;
      font-style: italic;
      padding: 2px 8px;
      border: 1px solid #EEEEEE;
    }

    /* Fixed accessories */
    .facc-banner {
      background: #F0F0F0;
      color: #1A1A1A;
      font-size: 6.5pt;
      font-weight: bold;
      padding: 2px 5px;
      border: 1px solid #888888;
    }

    .facc-col-head {
      background: #F7F7F7;
      color: #444444;
      font-size: 6pt;
      font-weight: bold;
      font-style: italic;
      text-align: center;
      padding: 2px 3px;
      border: 1px solid #888888;
    }

    .facc-data {
      background: #FFFFFF;
      color: #1A1A1A;
      font-size: 6.5pt;
      padding: 2px 4px;
      border: 1px solid #888888;
      vertical-align: middle;
    }

    .facc-data-c {
      text-align: center;
    }

    /* Nothing follows */
    .nothing-follows {
      background: #F5F5F5;
      color: #AAAAAA;
      font-style: italic;
      font-size: 7pt;
      text-align: center;
      padding: 5px;
      border: 1px solid #EEEEEE;
    }

    /* ── TOTALS ── */
    .totals-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 8px;
    }

    .total-label-cell {
      background: #FFFFFF;
      color: #1A1A1A;
      font-size: 7.5pt;
      font-weight: bold;
      text-align: right;
      padding: 4px 8px;
      border: 1px solid #333333;
      width: 60%;
    }

    .total-sym-cell {
      background: #FFFFFF;
      color: #1A1A1A;
      font-size: 7.5pt;
      font-weight: bold;
      text-align: center;
      padding: 4px 5px;
      border: 1px solid #333333;
      width: 8%;
    }

    .total-val-cell {
      background: #FFFFFF;
      color: #1A1A1A;
      font-size: 7.5pt;
      font-weight: bold;
      text-align: right;
      padding: 4px 8px;
      border: 1px solid #333333;
      width: 32%;
    }

    .disc-label {
      background: #FFFFFF;
      color: #B00020;
    }

    .disc-sym {
      background: #FFFFFF;
      color: #B00020;
    }

    .disc-val {
      background: #FFFFFF;
      color: #B00020;
    }

    .grand-label {
      background: #C4BD97;
      color: #1A1A1A;
      font-size: 9pt;
      border: 1px solid #333333;
    }

    .grand-sym {
      background: #C4BD97;
      color: #1A1A1A;
      font-size: 9pt;
      border: 1px solid #333333;
    }

    .grand-val {
      background: #C4BD97;
      color: #1A1A1A;
      font-size: 9pt;
      border: 1px solid #333333;
    }

    /* ── NOTES ── */
    .notes-header {
      background: #F0F0F0;
      color: #1A1A1A;
      font-size: 7.5pt;
      font-weight: bold;
      padding: 4px 8px;
      border: 1px solid #333333;
      margin-top: 8px;
    }

    .notes-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 2px;
    }

    .note-row td {
      background: #FFFFFF;
      color: #333;
      font-size: 7pt;
      padding: 3px 8px;
      border: 1px solid #EEEEEE;
    }

    .bank-head {
      background: #F0F0F0;
      color: #1A1A1A;
      font-size: 7pt;
      font-weight: bold;
      text-align: center;
      padding: 3px 4px;
      border: 1px solid #333333;
    }

    .bank-row td {
      background: #FFFFFF;
      color: #1A1A1A;
      font-size: 7pt;
      text-align: center;
      padding: 3px 4px;
      border: 1px solid #DDDDDD;
    }

    .bank-acc {
      font-weight: bold;
      color: #CC2200 !important;
    }

    .bank-row-alt td {
      background: #FAFAFA;
    }

    /* ── SIGNATURE ── */
    .sig-table {
      width: 100%;
      border-collapse: separate;
      margin-top: 10px;
      position: relative;
    }

    .sig-head {
      background: #F0F0F0;
      color: #1A1A1A;
      font-size: 7pt;
      font-weight: bold;
      text-align: center;
      padding: 4px 3px;
      border: 1px solid #333333;
    }

    .sig-space {
      background: #FAFAFA;
      height: 42px;
      border: 1px solid #333333;
      border-bottom: none;
    }

    .sig-name {
      background: #fff;
      color: #1A1A1A;
      font-size: 7.5pt;
      font-weight: bold;
      text-decoration: underline;
      text-align: center;
      padding: 3px;
      border: 1px solid #333333;
      border-top: none;
    }

    .sig-title {
      background: #F7F9FC;
      color: #555;
      font-size: 6.5pt;
      font-style: italic;
      text-align: center;
      padding: 2px 3px;
      border: 1px solid #333333;
      border-top: none;
    }

    /* Closing banner */
    .closing-banner {
      color: #1A1A1A;
      font-size: 7.5pt;
      font-weight: normal;
      font-style: italic;
      text-align: center;
      padding: 5px;
      margin-top: 8px;
    }

    /* Column widths in main-table (8 logical cols → compressed into page) */
    .col-a {
      width: 4%;
    }

    .col-b {
      width: 9%;
    }

    .col-c {
      width: 28%;
    }

    .col-d {
      width: 9%;
    }

    .col-e {
      width: 6%;
    }

    .col-f {
      width: 14%;
    }

    .col-g {
      width: 12%;
    }

    .col-h {
      width: 18%;
    }
  </style>
</head>

<body>
  <div class="page-wrap">

    <!-- ── HEADER ── -->
    <table class="header-table">
      <tr>
        <td class="header-logo-cell">
          <?php
          $logoPath = ROOT_PATH . 'realiving_admin/quotation-management/quotation-management/img/realiving_logo.png';
          if (file_exists($logoPath)):
            $logoData = base64_encode(file_get_contents($logoPath));
            $logoMime = 'image/png';
            ?>
            <img src="data:<?= $logoMime ?>;base64,<?= $logoData ?>" alt="RealLiving Logo">
          <?php else: ?>
            <span style="font-size:11pt;font-weight:bold;color:#1A1A2E;">REALIVING</span>
          <?php endif; ?>
        </td>
        <td class="header-right-cell">
          <div class="title-quot">QUOTATION</div>
          <div class="company-name">REALIVING DESIGN CENTER CORP.</div>
          <div class="company-addr">
            <?php echo $business_type === 'Project'
              ? 'Office: 9th Floor, 485 Asuncion St. San Nicolas, Binondo, Manila, 1010 Metro Manila'
              : 'Office: 2nd Floor, MC Premier, Quezon City, Metro Manila'; ?>
          </div>
        </td>
      </tr>
    </table>

    <!-- ── PROJECT INFO CARD ── -->
    <table class="info-table">
      <tr>
        <td class="info-label">PROJECT</td>
        <td class="info-value" colspan="3"><?= htmlspecialchars($project_name) ?></td>
        <td class="info-label" style="width:45px;">CLIENT</td>
        <td class="info-value"><?= htmlspecialchars($client_name) ?></td>
      </tr>
      <tr>
        <td class="info-label">LOCATION</td>
        <td colspan="3"><?= htmlspecialchars($client_address) ?></td>
        <td class="info-label">DATE</td>
        <td><?= date('d-M-Y') ?></td>
      </tr>
      <tr>
        <td class="info-label">SCOPE</td>
        <td colspan="5"><?= htmlspecialchars($project_scope) ?></td>
      </tr>
    </table>

    <!-- ── MAIN ITEMS TABLE ── -->
    <table class="main-table">
      <colgroup>
        <col class="col-a">
        <col class="col-b">
        <col class="col-c">
        <col class="col-d">
        <col class="col-e">
        <col class="col-f">
        <col class="col-g">
        <col class="col-h">
      </colgroup>
      <thead>
        <tr>
          <td colspan="8" class="band-title">ITEMIZED COST BREAKDOWN</td>
        </tr>
        <tr>
          <td class="col-head-1">ITEM</td>
          <td class="col-head-1">AREA</td>
          <td class="col-head-1">DESCRIPTION</td>
          <td class="col-head-1">UNIT</td>
          <td class="col-head-1">QTY</td>
          <td class="col-head-1" colspan="2">UNIT COST</td>
          <td class="col-head-1">TOTAL AMOUNT</td>
        </tr>
        <tr>
          <td class="col-head-2"></td>
          <td class="col-head-2"></td>
          <td class="col-head-2"></td>
          <td class="col-head-2">Sqm/Lm</td>
          <td class="col-head-2">Price/Item</td>
          <td class="col-head-2">Materials</td>
          <td class="col-head-2">Labor</td>
          <td class="col-head-2">Total</td>
        </tr>
        <tr>
          <td colspan="8" class="scope-row"><?= htmlspecialchars($scope_of_work) ?></td>
        </tr>
      </thead>
      <tbody>
        <?php
        $itemNo = 1;
        $rowIsEven = false;

        foreach ($areas as $area):
          $areaCustomized = array_filter($entriesArr, fn($r) => $r['area'] === $area);
          $areaFixed = array_filter($fixedEntriesArr, fn($r) => $r['area'] === $area);
          if (empty($areaCustomized) && empty($areaFixed))
            continue;
          ?>
          <!-- Area header -->
          <tr>
            <td class="area-no"><?= $itemNo ?></td>
            <td class="area-name" colspan="7"><?= strtoupper(htmlspecialchars($area)) ?></td>
          </tr>

          <?php
          // ── CUSTOMIZED entries ──
          foreach ($areaCustomized as $entry):
            $rowClass = $rowIsEven ? 'data-row-b' : 'data-row-a';
            $rowIsEven = !$rowIsEven;

            $unitMode = $entry['unit_mode'];
            $rawW = floatval($entry['width']);
            $rawH = floatval($entry['height']);
            $rawL = floatval($entry['length']);

            if ($unitMode === 'linear') {
              $flagW = intval($entry['item_width_linear']);
              $flagH = intval($entry['item_height_linear']);
              if ($flagW === 0)
                $computedUnit = $rawW / 1000;
              elseif ($flagH === 0)
                $computedUnit = $rawH / 1000;
              else
                $computedUnit = $rawL / 1000;
            } elseif ($unitMode === 'sqm') {
              $vals = [];
              if (intval($entry['item_width_sqm']) === 0)
                $vals[] = $rawW / 1000;
              if (intval($entry['item_height_sqm']) === 0)
                $vals[] = $rawH / 1000;
              if (intval($entry['item_length_sqm']) === 0)
                $vals[] = $rawL / 1000;
              $computedUnit = count($vals) === 2 ? $vals[0] * $vals[1] : (count($vals) === 1 ? $vals[0] : 1);
            } else {
              $computedUnit = 1;
            }

            $qty = intval($entry['quantity']);
            $unitPrice = floatval($entry['unit_price']);
            $laborCost = floatval($entry['labor_cost']);
            $jackupPct = floatval($entry['jackup']) / 100;
            $baseMats = $computedUnit * $unitPrice * $qty;
            $baseLabor = $computedUnit * $laborCost * $qty;
            $jackAmt = $baseMats * $jackupPct;
            $calc_tot_mats = $baseMats + $jackAmt;
            $calc_tot_labor = $baseLabor;
            $calc_tot_amount = $calc_tot_mats + $calc_tot_labor;

            $display_tot_mats = $calc_tot_mats > 0 ? $calc_tot_mats : floatval($entry['computed_tot_mats']);
            $display_tot_labor = $calc_tot_labor > 0 ? $calc_tot_labor : floatval($entry['computed_tot_labor']);
            $display_tot_amount = $calc_tot_amount > 0 ? $calc_tot_amount : floatval($entry['computed_tot_amount']);
            $display_unit = $computedUnit > 0 ? $computedUnit : floatval($entry['computed_unit']);
            ?>
            <tr class="<?= $rowClass ?>">
              <td class="data-cell accent-left"></td>
              <td class="data-cell"></td>
              <td class="data-cell">
                <div class="item-name"><?= htmlspecialchars($entry['item_name'] ?? '') ?></div>
                <div class="item-dim">
                  <?= htmlspecialchars($entry['width_label']) ?>: <?= number_format($rawW, 0) ?>mm<br>
                  <?= htmlspecialchars($entry['height_label']) ?>: <?= number_format($rawH, 0) ?>mm<br>
                  <?= htmlspecialchars($entry['length_label']) ?>: <?= number_format($rawL, 0) ?>mm
                </div>
              </td>
              <td class="data-cell data-cell-c">
                <?= number_format($display_unit, 3) ?><br>
                <span style="font-size:6pt;color:#888;">(<?= htmlspecialchars($entry['unit_mode']) ?>)</span>
              </td>
              <td class="data-cell data-cell-c">
                <?= $qty ?><br>
                <span style="font-size:6pt;color:#888;">(<?= htmlspecialchars($entry['unit_type']) ?>)</span>
              </td>
              <td class="data-cell num-cell"><?= phpFmt($display_tot_mats) ?></td>
              <td class="data-cell num-cell"><?= phpFmt($display_tot_labor) ?></td>
              <td class="data-cell num-cell"><?= phpFmt($display_tot_amount) ?></td>
            </tr>

            <?php
            // Sort addons (linked children after parent)
            if (!empty($addonsData[$entry['entry_id']])) {
              $allA = $addonsData[$entry['entry_id']];
              $linkedA = [];
              foreach ($allA as $a) {
                if (!empty($a['linked_dimension_addon_id'])) {
                  $linkedA[(int) $a['linked_dimension_addon_id']][] = $a;
                }
              }
              $sortedA = [];
              foreach ($allA as $a) {
                if (empty($a['linked_dimension_addon_id'])) {
                  $sortedA[] = $a;
                  if (!empty($linkedA[(int) $a['addon_entry_id']])) {
                    foreach ($linkedA[(int) $a['addon_entry_id']] as $child)
                      $sortedA[] = $child;
                  }
                }
              }
              $addonsData[$entry['entry_id']] = $sortedA;
            }

            if (!empty($addonsData[$entry['entry_id']])):
              ?>
              <!-- Accessories banner -->
              <tr>
                <td class="acc-banner" colspan="2"></td>
                <td class="acc-banner" colspan="6">&starf; ACCESSORIES</td>
              </tr>
              <tr>
                <td class="acc-col-head" colspan="2"></td>
                <td class="acc-col-head" style="text-align:left;padding-left:8px;">Item Name</td>
                <td class="acc-col-head">Price/Item</td>
                <td class="acc-col-head">QTY</td>
                <td class="acc-col-head">Materials</td>
                <td class="acc-col-head">Labor</td>
                <td class="acc-col-head">Total</td>
              </tr>
              <?php foreach ($addonsData[$entry['entry_id']] as $addon):
                $calc = computeAddonTotals($addon['price'], $addon['labor_cost'] ?? 0, $addon['addon_jackup'] ?? 0, $addon['computed_area'] ?? 0, $addon['quantity'], $addon['is_stable_mat'] ?? 0, $addon['multiply_value'] ?? 0, $addon['min_required_unit'] ?? 0);
                ?>
                <tr>
                  <td class="acc-data" colspan="2"></td>
                  <td class="acc-data">&rsaquo; <?= htmlspecialchars($addon['addon_name']) ?></td>
                  <td class="acc-data acc-data-c"><?= phpFmt($calc['price_per_item']) ?></td>
                  <td class="acc-data acc-data-c"><?= $addon['quantity'] ?> pc(s)</td>
                  <td class="acc-data acc-data-c"><?= phpFmt($calc['tot_mats']) ?></td>
                  <td class="acc-data acc-data-c"><?= phpFmt($calc['tot_labor']) ?></td>
                  <td class="acc-data acc-data-c"><?= phpFmt($calc['total']) ?></td>
                </tr>
                <?php if (!empty($addon['note'])): ?>
                  <tr>
                    <td class="acc-note" colspan="2"></td>
                    <td class="acc-note" colspan="6">&#128221; <?= htmlspecialchars($addon['note']) ?></td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php endif; // end customized addons ?>

          <?php endforeach; // end customized entries ?>

          <?php
          // ── FIXED SIZE entries ──
          foreach ($areaFixed as $entry):
            $rowClass = $rowIsEven ? 'data-row-fixed-b' : 'data-row-fixed-a';
            $rowIsEven = !$rowIsEven;
            $baseTotal = floatval($entry['base_price']) * intval($entry['quantity']);

            $detailStr = ($entry['size_label'] ?? '');
            if (!empty($entry['color_label']))
              $detailStr .= '  |  Color: ' . $entry['color_label'];
            $dimStr = '';
            if ($entry['size_width'] && $entry['measurement_unit'])
              $dimStr .= ($entry['item_width_label_linear'] ?? 'W') . ': ' . $entry['size_width'] . $entry['measurement_unit'] . '  ';
            if ($entry['size_height'] && $entry['measurement_unit'])
              $dimStr .= ($entry['item_height_label_linear'] ?? 'H') . ': ' . $entry['size_height'] . $entry['measurement_unit'] . '  ';
            if ($entry['size_length'] && $entry['measurement_unit'])
              $dimStr .= ($entry['item_length_label_linear'] ?? 'L') . ': ' . $entry['size_length'] . $entry['measurement_unit'];
            ?>
            <tr class="<?= $rowClass ?>">
              <td class="data-cell purple-left"></td>
              <td class="data-cell"></td>
              <td class="data-cell">
                <div class="fixed-name"><?= htmlspecialchars($entry['item_name'] ?? '') ?></div>
                <div class="fixed-dim">
                  <?= htmlspecialchars($detailStr) ?><br>
                  <?= htmlspecialchars($dimStr) ?>
                </div>
              </td>
              <td class="data-cell data-cell-c" style="font-size:6.5pt;color:#7060A0;">Fixed Size</td>
              <td class="data-cell data-cell-c">
                <?= intval($entry['quantity']) ?><br>
                <span style="font-size:6pt;color:#888;">(<?= htmlspecialchars($entry['unit_type']) ?>)</span>
              </td>
              <td class="data-cell fixed-num"><?= phpFmt($entry['base_price']) ?></td>
              <td class="data-cell fixed-num">0.00</td>
              <td class="data-cell fixed-num"><?= phpFmt($baseTotal) ?></td>
            </tr>

            <?php
            // Sort fixed addons
            if (!empty($fixedAddonsData[$entry['id']])) {
              $allF = $fixedAddonsData[$entry['id']];
              $linkedF = [];
              foreach ($allF as $a) {
                if (!empty($a['linked_dimension_addon_id'])) {
                  $linkedF[(int) $a['linked_dimension_addon_id']][] = $a;
                }
              }
              $sortedF = [];
              foreach ($allF as $a) {
                if (empty($a['linked_dimension_addon_id'])) {
                  $sortedF[] = $a;
                  if (!empty($linkedF[(int) $a['id']])) {
                    foreach ($linkedF[(int) $a['id']] as $child)
                      $sortedF[] = $child;
                  }
                }
              }
              $fixedAddonsData[$entry['id']] = $sortedF;
            }

            if (!empty($fixedAddonsData[$entry['id']])):
              ?>
              <!-- Fixed accessories banner -->
              <tr>
                <td class="facc-banner" colspan="2"></td>
                <td class="facc-banner" colspan="6">&starf; ACCESSORIES</td>
              </tr>
              <tr>
                <td class="facc-col-head" colspan="2"></td>
                <td class="facc-col-head" style="text-align:left;padding-left:8px;">Item Name</td>
                <td class="facc-col-head">Price/Item</td>
                <td class="facc-col-head">QTY</td>
                <td class="facc-col-head">Materials</td>
                <td class="facc-col-head">Labor</td>
                <td class="facc-col-head">Total</td>
              </tr>
              <?php foreach ($fixedAddonsData[$entry['id']] as $addon):
                $calc = computeAddonTotals($addon['price'], $addon['labor_cost'] ?? 0, $addon['addon_jackup'] ?? 0, $addon['computed_area'] ?? 0, $addon['quantity'], $addon['is_stable_mat'] ?? 0, $addon['multiply_value'] ?? 0, $addon['min_required_unit'] ?? 0);
                $catLabel = !empty($addon['addon_category']) ? ' (' . htmlspecialchars($addon['addon_category']) . ')' : '';
                ?>
                <tr>
                  <td class="facc-data" colspan="2"></td>
                  <td class="facc-data">&rsaquo; <?= htmlspecialchars($addon['addon_name']) . $catLabel ?></td>
                  <td class="facc-data facc-data-c"><?= phpFmt($calc['price_per_item']) ?></td>
                  <td class="facc-data facc-data-c"><?= $addon['quantity'] ?> pc(s)</td>
                  <td class="facc-data facc-data-c"><?= phpFmt($calc['tot_mats']) ?></td>
                  <td class="facc-data facc-data-c"><?= phpFmt($calc['tot_labor']) ?></td>
                  <td class="facc-data facc-data-c"><?= phpFmt($calc['total']) ?></td>
                </tr>
                <?php if (!empty($addon['note'])): ?>
                  <tr>
                    <td class="acc-note" colspan="2"></td>
                    <td class="acc-note" colspan="6">&#128221; <?= htmlspecialchars($addon['note']) ?></td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php endif; // end fixed addons ?>

          <?php endforeach; // end fixed entries ?>

          <?php $itemNo++; endforeach; // end areas ?>

        <tr>
          <td colspan="8" class="nothing-follows">— NOTHING FOLLOWS —</td>
        </tr>
      </tbody>
    </table>

    <!-- ── TOTALS ── -->
    <table class="totals-table">
      <tr>
        <td class="total-label-cell">SUBTOTAL</td>
        <td class="total-sym-cell">PHP</td>
        <td class="total-val-cell"><?= phpFmt($display_rawTotal) ?></td>
      </tr>
      <?php if ($storedDiscount > 0): ?>
        <tr>
          <td class="total-label-cell disc-label">DISCOUNT (<?= $storedDiscount ?>%)</td>
          <td class="total-sym-cell disc-sym">-PHP</td>
          <td class="total-val-cell disc-val"><?= phpFmt($display_discountAmt) ?></td>
        </tr>
        <tr>
          <td class="total-label-cell">AFTER DISCOUNT</td>
          <td class="total-sym-cell">PHP</td>
          <td class="total-val-cell"><?= phpFmt($display_afterDiscount) ?></td>
        </tr>
      <?php endif; ?>
      <?php if ($business_type === 'Project'): ?>
        <tr>
          <td class="total-label-cell">GENERAL REQUIREMENTS (10%)</td>
          <td class="total-sym-cell">PHP</td>
          <td class="total-val-cell"><?= phpFmt($display_generalReq) ?></td>
        </tr>
        <tr>
          <td class="total-label-cell">SUBTOTAL w/ GR</td>
          <td class="total-sym-cell">PHP</td>
          <td class="total-val-cell"><?= phpFmt($display_subtotalWithGR) ?></td>
        </tr>
        <tr>
          <td class="total-label-cell">VAT (12%)</td>
          <td class="total-sym-cell">PHP</td>
          <td class="total-val-cell"><?= phpFmt($display_vat) ?></td>
        </tr>
      <?php endif; ?>
      <tr>
        <td colspan="3" style="height:8px;border:none;background:#FFFFFF;padding:0;"></td>
      </tr>
      <tr>
        <td class="total-label-cell grand-label">GRAND TOTAL</td>
        <td class="total-sym-cell grand-sym">PHP</td>
        <td class="total-val-cell grand-val"><?= phpFmt($display_finalTotal) ?></td>
      </tr>
    </table>

    <!-- ── NOTES & PAYMENT TERMS ── -->
    <div class="accent-bar" style="margin-top:8px;"></div>
    <div class="notes-header">NOTES &amp; PAYMENT TERMS</div>
    <table class="notes-table">
      <?php
      if ($business_type === 'Project') {
        $notes1 = [
          '1.  Additional labor cost for material segregation per floor, depends on the availability of alimak or manlift elevator.',
          '2.  Payment Terms 30% down payment and balance payment by Progress Billing.',
          '3.  Payable to REALIVING DESIGN CENTER CORP.',
        ];
      } else {
        $notes1 = [
          '1.  Payment Terms: 50% downpayment, 40% before installation and 10% after installation done.',
          '2.  Payable to REALIVING DESIGN CENTER CORP.',
        ];
      }
      foreach ($notes1 as $note): ?>
        <tr class="note-row">
          <td><?= htmlspecialchars($note) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <!-- Bank info -->
    <table class="notes-table" style="margin-top:4px;">
      <tr>
        <td class="bank-head" style="width:15%;">BANK</td>
        <td class="bank-head" style="width:50%;">ACCOUNT NAME</td>
        <td class="bank-head" style="width:35%;">ACCOUNT NUMBER</td>
      </tr>
      <?php
      $bankData = [
        ['BDO', 'REALIVING DESIGN CENTER CORP.', '008-530-005-770'],
        ['AUB', 'REALIVING DESIGN CENTER CORP.', '004-010-009-294'],
        ['PNB', 'REALIVING DESIGN CENTER CORP.', '165-870-001-147'],
      ];
      foreach ($bankData as $i => $bank):
        $altClass = $i % 2 === 1 ? 'bank-row-alt' : '';
        ?>
        <tr class="bank-row <?= $altClass ?>">
          <td><?= $bank[0] ?></td>
          <td><?= $bank[1] ?></td>
          <td class="bank-acc"><?= $bank[2] ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <!-- Remaining notes -->
    <table class="notes-table" style="margin-top:4px;">
      <?php
      if ($business_type === 'Project') {
        $notes2 = [
          '4.  Quote according to the drawing, any changes made by customers will be charged accordingly.',
          '5.  VAT Inclusive.',
          '6.  Free delivery for NCR Area only, beyond NCR have additional fee.',
          '7.  This is only for quotation, for more details refer to the contact.',
        ];
      } else {
        $notes2 = [
          '3.  Quote according to the drawing, any changes requested by the customer will be charged accordingly.',
          '4.  VAT Exclusive.',
          '5.  Free delivery for NCR Area only. Beyond NCR incurs an additional delivery fee.',
          '6.  This is only for quotation purposes. For full details, please refer to the contract.',
        ];
      }
      foreach ($notes2 as $note): ?>
        <tr class="note-row">
          <td><?= htmlspecialchars($note) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <!-- ── CLOSING BANNER ── -->
    <div class="closing-banner">Thank you for giving us a chance to be of service. We look forward to working with you!
    </div>

    <!-- ── SIGNATURE SECTION ── -->
    <table class="sig-table">
      <tr>
        <td class="sig-head" style="width:25%;">Prepared by:</td>
        <td class="sig-head" style="width:25%;">Approved by:</td>
        <td class="sig-head" style="width:25%;">Approved by:</td>
        <td class="sig-head" style="width:25%;">Conforme:</td>
      </tr>
      <tr>
        <td class="sig-space" style="text-align:center;vertical-align:middle;padding:4px;">
          <?php if (!empty($admin_esignature) && file_exists($admin_esignature)):
            $sigData = base64_encode(file_get_contents($admin_esignature));
            $sigExt = strtolower(pathinfo($admin_esignature, PATHINFO_EXTENSION));
            $sigMime = $sigExt === 'png' ? 'image/png' : 'image/jpeg';
            ?>
            <img src="data:<?= $sigMime ?>;base64,<?= $sigData ?>" style="max-height:38px;max-width:100px;">
          <?php endif; ?>
        </td>
        <td class="sig-space"></td>
        <td class="sig-space"></td>
        <td class="sig-space"></td>
      </tr>
      <tr>
        <td class="sig-name"><?= htmlspecialchars($admin_name) ?></td>
        <td class="sig-name"></td>
        <td class="sig-name"></td>
        <td class="sig-name"></td>
      </tr>
      <tr>
        <td class="sig-title">Designer / Sales Associates</td>
        <td class="sig-title">Project Operation Manager</td>
        <td class="sig-title">General Manager</td>
        <td class="sig-title"></td>
      </tr>
      <tr>
        <td class="sig-title"></td>
        <td class="sig-title"></td>
        <td class="sig-title"></td>
        <td class="sig-title"></td>
      </tr>
    </table>

  </div>
</body>

</html>
<?php
$html = ob_get_clean();

// ── Render with Dompdf ──
$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isFontSubsettingEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

if (ob_get_level())
  ob_end_clean();

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="quotation_' . $safe_client_name . '_' . date('Y-m-d') . '.pdf"');
header('Cache-Control: max-age=0');

echo $dompdf->output();
exit;