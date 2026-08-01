<?php
//export_quotation.php
session_start();
include $includes ['connection'];
include $includes ['checkrole'];
require_role(['designer', 'tecnical_designer', 'sales', 'project_coordinator']);

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

$admin_id = $_SESSION['admin_id'];

// ── Use COALESCE so full_name is the fallback when admin_name is NULL ──
$adminStmt = $conn->prepare("SELECT COALESCE(admin_name, full_name) AS display_name, e_signature FROM account WHERE id = ?");
$adminStmt->bind_param("i", $admin_id);
$adminStmt->execute();
$adminResult = $adminStmt->get_result();
$adminData = $adminResult->fetch_assoc();
$admin_name = $adminData['display_name'] ?? 'Unknown Admin';
$admin_esignature = $adminData['e_signature'] ?? '';
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
    // ── Also fetch the assigned account's full_name via accountaid_fk ──
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
        // If the currently logged-in admin has no name, fall back to the
        // client's assigned account name (accountaid_fk → account)
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

// ── HELPER: compute addon totals (matches JS recalcAddonRow logic exactly) ──
function computeAddonTotals(
    $price,
    $laborCost,
    $jackup,
    $computedArea,
    $qty,
    $isStableMat,
    $multiplyValue,
    $minRequiredUnit
) {
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

    return [
        'tot_mats' => $aTotMats,
        'tot_labor' => $aTotLabor,
        'total' => $aTotal,
        'price_per_item' => $aPpi,
    ];
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
        $eFlagL = intval($eRow['item_length_linear']);
        if ($eFlagW === 0)
            $eUnit = $eW / 1000;
        elseif ($eFlagH === 0)
            $eUnit = $eH / 1000;
        else
            $eUnit = $eL / 1000;
    } elseif ($eMode === 'sqm') {
        $eFlagW = intval($eRow['item_width_sqm']);
        $eFlagH = intval($eRow['item_height_sqm']);
        $eFlagL = intval($eRow['item_length_sqm']);
        $eVals = [];
        if ($eFlagW === 0)
            $eVals[] = $eW / 1000;
        if ($eFlagH === 0)
            $eVals[] = $eH / 1000;
        if ($eFlagL === 0)
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
            $calc = computeAddonTotals(
                $addon['price'],
                $addon['labor_cost'] ?? 0,
                $addon['addon_jackup'] ?? 0,
                $addon['computed_area'] ?? 0,
                $addon['quantity'],
                $addon['is_stable_mat'] ?? 0,
                $addon['multiply_value'] ?? 0,
                $addon['min_required_unit'] ?? 0
            );
            $grandAddons += $calc['total'];
        }
    }
}
foreach ($fixedEntriesArr as $row) {
    $grandFixed += round(floatval($row['base_price']) * intval($row['quantity']), 2);
    if (isset($fixedAddonsData[$row['id']])) {
        foreach ($fixedAddonsData[$row['id']] as $addon) {
            $calc = computeAddonTotals(
                $addon['price'],
                $addon['labor_cost'] ?? 0,
                $addon['addon_jackup'] ?? 0,
                $addon['computed_area'] ?? 0,
                $addon['quantity'],
                $addon['is_stable_mat'] ?? 0,
                $addon['multiply_value'] ?? 0,
                $addon['min_required_unit'] ?? 0
            );
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

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

if (ob_get_level())
    ob_end_clean();

// ── COLOR PALETTE (plain letterhead theme, matches PDF export) ──
define('CLR_HEADER_BG', 'FFFFFF');
define('CLR_HEADER_TEXT', '1A1A1A');
define('CLR_ACCENT', '333333');
define('CLR_ACCENT_LIGHT', 'F0F0F0');
define('CLR_COL_HEAD_BG', 'F0F0F0');
define('CLR_COL_SUB_BG', 'F7F7F7');
define('CLR_AREA_BG', 'EFEFEF');
define('CLR_AREA_TEXT', '1A1A1A');
define('CLR_ROW_ALT', 'F8F9FE');
define('CLR_ROW_WHITE', 'FFFFFF');
define('CLR_FIXED_BG', 'F9F8FF');
define('CLR_ADDON_BG', 'FFFFFF');
define('CLR_ADDON_HDR', 'F0F0F0');
define('CLR_TOTAL_BG', 'FFFFFF');
define('CLR_GRAND_BG', 'C4BD97');
define('CLR_BORDER', 'D0D0D0');
define('CLR_BORDER_DARK', '333333');
define('CLR_SCOPE_BG', 'FFFFFF');
define('CLR_NOTE_BG', 'F7F7F7');

try {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Quotation');

    $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT);
    $sheet->getPageMargins()->setTop(0.4)->setRight(0.4)->setLeft(0.4)->setBottom(0.4);

    // ── COLUMN WIDTHS — A through L (12 columns), matches the template ──
    $sheet->getColumnDimension('A')->setWidth(8);   // Item # / info-card labels
    $sheet->getColumnDimension('B')->setWidth(7);   // Area (B:D merged)
    $sheet->getColumnDimension('C')->setWidth(7);
    $sheet->getColumnDimension('D')->setWidth(7);
    $sheet->getColumnDimension('E')->setWidth(15);  // Description (E:F merged)
    $sheet->getColumnDimension('F')->setWidth(15);
    $sheet->getColumnDimension('G')->setWidth(13);  // Unit
    $sheet->getColumnDimension('H')->setWidth(9);   // Qty
    $sheet->getColumnDimension('I')->setWidth(12);  // Materials
    $sheet->getColumnDimension('J')->setWidth(12);  // Labor
    $sheet->getColumnDimension('K')->setWidth(12);  // Total
    $sheet->getColumnDimension('L')->setWidth(17);  // Total Amount

    // ── HELPER FUNCTIONS ──────────────────────────────────────────────────

    function applyStyle($sheet, $range, array $style)
    {
        $sheet->getStyle($range)->applyFromArray($style);
    }

    function numFmt($sheet, $cell, $format = '#,##0.00')
    {
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($format);
    }

    function numFmtRange($sheet, $range, $format = '#,##0.00')
    {
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode($format);
    }

    function baseFont($size = 9, $bold = false, $color = '333333', $italic = false)
    {
        return [
            'bold' => $bold,
            'size' => $size,
            'name' => 'Calibri',
            'color' => ['rgb' => $color],
            'italic' => $italic
        ];
    }

    function solidFill($rgb)
    {
        return ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => $rgb]];
    }

    function thinBorders($color = 'CCCCCC')
    {
        return [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => $color]
            ]
        ];
    }

    function outerBorder($color = '999999')
    {
        return [
            'outline' => [
                'borderStyle' => Border::BORDER_MEDIUM,
                'color' => ['rgb' => $color]
            ]
        ];
    }

    $row = 1;

    // ═══════════════════════════════════════════════════════════════════
    // HEADER
    // ═══════════════════════════════════════════════════════════════════
    $sheet->getRowDimension(1)->setRowHeight(42);
    $sheet->getRowDimension(2)->setRowHeight(22);
    $sheet->getRowDimension(3)->setRowHeight(16);
    $sheet->getRowDimension(4)->setRowHeight(4);

    foreach (range(1, 3) as $hr) {
        applyStyle($sheet, 'A' . $hr . ':L' . $hr, ['fill' => solidFill('FFFFFF')]);
    }
    applyStyle($sheet, 'A4:L4', [
        'borders' => [
            'bottom' => ['borderStyle' => Border::BORDER_THICK, 'color' => ['rgb' => '333333']]
        ],
    ]);

    $logoPath = ROOT_PATH . 'realiving_admin/quotation-management/quotation-management/img/realiving_logo.png';
    if (file_exists($logoPath)) {
        /** @var \PhpOffice\PhpSpreadsheet\Worksheet\Drawing $drawing */
        $drawing = new Drawing();
        // Bounded explicitly (width+height, proportional) so it can never bleed
        // past its A:E box and collide with the "QUOTATION" title.
        $drawing->setName('Logo')->setDescription('RealLiving Logo')
            ->setPath($logoPath)
            ->setResizeProportional(true)
            ->setWidth(150)
            ->setHeight(60)
            ->setOffsetX(6)->setOffsetY(6)
            ->setCoordinates('A1')->setWorksheet($sheet);
    }

    // Logo occupies A:E (extra buffer column) — title/company block starts at F
    $sheet->setCellValue('F1', 'QUOTATION');
    $sheet->mergeCells('F1:L1');
    applyStyle($sheet, 'F1:L1', [
        'font' => [
            'bold' => true,
            'size' => 26,
            'name' => 'Calibri',
            'color' => ['rgb' => '1A1A1A']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT,
            'vertical' => Alignment::VERTICAL_CENTER,
            'indent' => 1
        ],
        'fill' => solidFill('FFFFFF'),
    ]);

    $sheet->setCellValue('F2', 'REALIVING DESIGN CENTER CORP.');
    $sheet->mergeCells('F2:L2');
    applyStyle($sheet, 'F2:L2', [
        'font' => [
            'bold' => true,
            'size' => 10,
            'name' => 'Calibri',
            'color' => ['rgb' => '1A1A1A']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT,
            'vertical' => Alignment::VERTICAL_CENTER,
            'indent' => 1
        ],
        'fill' => solidFill('FFFFFF'),
    ]);

    $sheet->setCellValue('F3', $business_type === 'Project'
        ? 'Office: 9th Floor, 485 Asuncion St. San Nicolas, Binondo, Manila, 1010 Metro Manila'
        : 'Office: 2nd Floor, MC Premier, Quezon City, Metro Manila');
    $sheet->mergeCells('F3:L3');
    applyStyle($sheet, 'F3:L3', [
        'font' => [
            'size' => 8,
            'name' => 'Calibri',
            'italic' => true,
            'color' => ['rgb' => '555555']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT,
            'vertical' => Alignment::VERTICAL_CENTER,
            'indent' => 1
        ],
        'fill' => solidFill('FFFFFF'),
    ]);

    $row = 5;

    $sheet->getRowDimension($row)->setRowHeight(5);
    $row++;

    // ═══════════════════════════════════════════════════════════════════
    // PROJECT INFO CARD
    // ═══════════════════════════════════════════════════════════════════
    $infoStyle = [
        'font' => baseFont(9, false, '444444'),
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'fill' => solidFill('FFFFFF'),
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'E0E0E0']
            ]
        ],
    ];
    $labelStyle = [
        'font' => baseFont(8, true, '777777'),
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'fill' => solidFill('F5F5F5'),
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'E0E0E0']
            ]
        ],
    ];

    $sheet->getRowDimension($row)->setRowHeight(18);
    $sheet->setCellValue('A' . $row, 'PROJECT');
    $sheet->setCellValue('B' . $row, $project_name);
    $sheet->mergeCells('B' . $row . ':H' . $row);
    $sheet->setCellValue('I' . $row, 'CLIENT');
    $sheet->setCellValue('J' . $row, $client_name);
    $sheet->mergeCells('J' . $row . ':L' . $row);
    applyStyle($sheet, 'A' . $row, $labelStyle);
    applyStyle($sheet, 'B' . $row . ':H' . $row, array_merge($infoStyle, ['font' => baseFont(9, true, '1A1A2E')]));
    applyStyle($sheet, 'I' . $row, $labelStyle);
    applyStyle($sheet, 'J' . $row . ':L' . $row, array_merge($infoStyle, ['font' => baseFont(9, true, '1A1A2E')]));
    $row++;

    $sheet->getRowDimension($row)->setRowHeight(18);
    $sheet->setCellValue('A' . $row, 'LOCATION');
    $sheet->setCellValue('B' . $row, $client_address);
    $sheet->mergeCells('B' . $row . ':H' . $row);
    $sheet->setCellValue('I' . $row, 'DATE');
    $sheet->setCellValue('J' . $row, date('d-M-Y'));
    $sheet->mergeCells('J' . $row . ':L' . $row);
    applyStyle($sheet, 'A' . $row, $labelStyle);
    applyStyle($sheet, 'B' . $row . ':H' . $row, $infoStyle);
    applyStyle($sheet, 'I' . $row, $labelStyle);
    applyStyle($sheet, 'J' . $row . ':L' . $row, $infoStyle);
    $row++;

    $sheet->getRowDimension($row)->setRowHeight(18);
    $sheet->setCellValue('A' . $row, 'SCOPE');
    $sheet->setCellValue('B' . $row, $project_scope);
    $sheet->mergeCells('B' . $row . ':L' . $row);
    applyStyle($sheet, 'A' . $row, $labelStyle);
    applyStyle($sheet, 'B' . $row . ':L' . $row, $infoStyle);
    $row++;

    $sheet->getRowDimension($row)->setRowHeight(8);
    $row++;

    // ═══════════════════════════════════════════════════════════════════
    // TABLE HEADER (2-row header: A/B:D/E:F/G/H/L span both rows,
    // I:K "UNIT COST" spans row1 only, split into Materials/Labor/Total on row2)
    // ═══════════════════════════════════════════════════════════════════
    $sheet->getRowDimension($row)->setRowHeight(22);
    $sheet->getRowDimension($row + 1)->setRowHeight(10);
    $sheet->getRowDimension($row + 2)->setRowHeight(20);
    $sheet->getRowDimension($row + 3)->setRowHeight(20);
    $sheet->getRowDimension($row + 4)->setRowHeight(28);

    $sheet->setCellValue('A' . $row, 'ITEMIZED COST BREAKDOWN');
    $sheet->mergeCells('A' . $row . ':L' . $row);
    applyStyle($sheet, 'A' . $row . ':L' . $row, [
        'font' => [
            'bold' => true,
            'size' => 11,
            'name' => 'Calibri',
            'color' => ['rgb' => '1A1A1A']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'fill' => solidFill('FFFFFF'),
        'borders' => thinBorders(CLR_BORDER_DARK),
    ]);
    $row++;

    // spacer row
    $row++;

    $hdrRow1 = $row;
    $hdrRow2 = $row + 1;

    $sheet->mergeCells('A' . $hdrRow1 . ':A' . $hdrRow2);
    $sheet->setCellValue('A' . $hdrRow1, 'ITEM');

    $sheet->mergeCells('B' . $hdrRow1 . ':D' . $hdrRow2);
    $sheet->setCellValue('B' . $hdrRow1, 'AREA');

    $sheet->mergeCells('E' . $hdrRow1 . ':F' . $hdrRow2);
    $sheet->setCellValue('E' . $hdrRow1, 'DESCRIPTION');

    $sheet->mergeCells('G' . $hdrRow1 . ':G' . $hdrRow2);
    $sheet->setCellValue('G' . $hdrRow1, 'UNIT (Sqm/Lm)');

    $sheet->mergeCells('H' . $hdrRow1 . ':H' . $hdrRow2);
    $sheet->setCellValue('H' . $hdrRow1, 'QTY.');

    $sheet->mergeCells('I' . $hdrRow1 . ':K' . $hdrRow1);
    $sheet->setCellValue('I' . $hdrRow1, 'UNIT COST');

    $sheet->mergeCells('L' . $hdrRow1 . ':L' . $hdrRow2);
    $sheet->setCellValue('L' . $hdrRow1, 'TOTAL AMOUNT');

    $sheet->setCellValue('I' . $hdrRow2, 'Materials');
    $sheet->setCellValue('J' . $hdrRow2, 'Labor');
    $sheet->setCellValue('K' . $hdrRow2, 'Total');

    applyStyle($sheet, 'A' . $hdrRow1 . ':L' . $hdrRow1, [
        'font' => [
            'bold' => true,
            'size' => 9,
            'name' => 'Calibri',
            'color' => ['rgb' => '1A1A1A']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'fill' => solidFill('F0F0F0'),
        'borders' => thinBorders('333333'),
    ]);
    applyStyle($sheet, 'A' . $hdrRow2 . ':L' . $hdrRow2, [
        'font' => [
            'bold' => true,
            'size' => 8,
            'name' => 'Calibri',
            'color' => ['rgb' => '444444']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'fill' => solidFill('F7F7F7'),
        'borders' => thinBorders('666666'),
    ]);
    $row = $hdrRow2 + 1;

    $sheet->setCellValue('A' . $row, $scope_of_work);
    $sheet->mergeCells('A' . $row . ':L' . $row);
    applyStyle($sheet, 'A' . $row . ':L' . $row, [
        'font' => [
            'bold' => true,
            'size' => 11,
            'name' => 'Calibri',
            'color' => ['rgb' => '1A1A1A']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true
        ],
        'fill' => solidFill('FFFFFF'),
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '333333']
            ]
        ],
    ]);
    $row++;

    // ═══════════════════════════════════════════════════════════════════
    // DATA ROWS
    // ═══════════════════════════════════════════════════════════════════
    $itemNo = 1;
    $rowIsEven = false;

    foreach ($areas as $area) {
        $areaCustomized = array_filter($entriesArr, fn($r) => $r['area'] === $area);
        $areaFixed = array_filter($fixedEntriesArr, fn($r) => $r['area'] === $area);
        if (empty($areaCustomized) && empty($areaFixed))
            continue;

        // ── Area header row ──
        $sheet->getRowDimension($row)->setRowHeight(20);
        $sheet->setCellValue('A' . $row, $itemNo);
        $sheet->setCellValue('B' . $row, strtoupper($area));
        $sheet->mergeCells('B' . $row . ':L' . $row);
        applyStyle($sheet, 'A' . $row, [
            'font' => [
                'bold' => true,
                'size' => 10,
                'name' => 'Calibri',
                'color' => ['rgb' => '1A1A1A']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'fill' => solidFill('EFEFEF'),
            'borders' => thinBorders('333333'),
        ]);
        applyStyle($sheet, 'B' . $row . ':L' . $row, [
            'font' => [
                'bold' => true,
                'size' => 10,
                'name' => 'Calibri',
                'color' => ['rgb' => '1A1A1A']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
                'indent' => 1
            ],
            'fill' => solidFill('EFEFEF'),
            'borders' => thinBorders('333333'),
        ]);
        $row++;

        // ── CUSTOMIZED entries ──
        foreach ($areaCustomized as $entry) {
            $rowBg = $rowIsEven ? 'F8F9FE' : 'FFFFFF';
            $rowIsEven = !$rowIsEven;

            $unitMode = $entry['unit_mode'];
            $rawW = floatval($entry['width']);
            $rawH = floatval($entry['height']);
            $rawL = floatval($entry['length']);

            if ($unitMode === 'linear') {
                $flagW = intval($entry['item_width_linear']);
                $flagH = intval($entry['item_height_linear']);
                $flagL = intval($entry['item_length_linear']);
                if ($flagW === 0)
                    $computedUnit = $rawW / 1000;
                elseif ($flagH === 0)
                    $computedUnit = $rawH / 1000;
                else
                    $computedUnit = $rawL / 1000;
            } elseif ($unitMode === 'sqm') {
                $flagW = intval($entry['item_width_sqm']);
                $flagH = intval($entry['item_height_sqm']);
                $flagL = intval($entry['item_length_sqm']);
                $vals = [];
                if ($flagW === 0)
                    $vals[] = $rawW / 1000;
                if ($flagH === 0)
                    $vals[] = $rawH / 1000;
                if ($flagL === 0)
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

            // Unit-cost columns show PER-UNIT figures; Total Amount column shows the extended (qty-included) figure
            $unitMatsDisplay = $qty > 0 ? $display_tot_mats / $qty : $display_tot_mats;
            $unitLaborDisplay = $qty > 0 ? $display_tot_labor / $qty : $display_tot_labor;
            $unitTotalDisplay = $unitMatsDisplay + $unitLaborDisplay;

            $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
            $nameRun = $richText->createTextRun(($entry['item_name'] ?? '') . "\n");
            $nameRun->getFont()->setBold(true)->setSize(10)->setName('Calibri')
                ->setColor(new Color('1A1A2E'));

            $dimText = $entry['width_label'] . ': ' . number_format(floatval($entry['width']), 0) . "mm\n"
                . $entry['height_label'] . ': ' . number_format(floatval($entry['height']), 0) . "mm\n"
                . $entry['length_label'] . ': ' . number_format(floatval($entry['length']), 0) . "mm";
            $dimRun = $richText->createTextRun($dimText);
            $dimRun->getFont()->setSize(8)->setName('Calibri')
                ->setColor(new Color('666688'));

            $sheet->getRowDimension($row)->setRowHeight(95);
            $sheet->setCellValue('A' . $row, '');
            $sheet->mergeCells('B' . $row . ':D' . $row);
            $sheet->setCellValue('B' . $row, '');
            $sheet->mergeCells('E' . $row . ':F' . $row);
            $sheet->setCellValue('E' . $row, $richText);
            $sheet->setCellValue('G' . $row, round($display_unit, 3) . "\n(" . $entry['unit_mode'] . ')');
            $sheet->setCellValue('H' . $row, intval($entry['quantity']) . "\n(" . $entry['unit_type'] . ')');
            $sheet->setCellValue('I' . $row, $unitMatsDisplay);
            $sheet->setCellValue('J' . $row, $unitLaborDisplay);
            $sheet->setCellValue('K' . $row, $unitTotalDisplay);
            $sheet->setCellValue('L' . $row, $display_tot_amount);

            applyStyle($sheet, 'A' . $row . ':L' . $row, [
                'font' => baseFont(9, false, '333333'),
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
                'fill' => solidFill($rowBg),
                'borders' => thinBorders('DDDDF0'),
            ]);
            applyStyle($sheet, 'G' . $row . ':L' . $row, [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_TOP,
                    'wrapText' => true
                ],
            ]);
            applyStyle($sheet, 'I' . $row . ':L' . $row, [
                'font' => baseFont(9, true, '1A1A2E'),
            ]);
            $sheet->getStyle('E' . $row)->getAlignment()->setWrapText(true);
            numFmt($sheet, 'I' . $row);
            numFmt($sheet, 'J' . $row);
            numFmt($sheet, 'K' . $row);
            numFmt($sheet, 'L' . $row);
            applyStyle($sheet, 'A' . $row, [
                'borders' => [
                    'left' => [
                        'borderStyle' => Border::BORDER_MEDIUM,
                        'color' => ['rgb' => '333333']
                    ]
                ],
            ]);
            $row++;

            // Sort addons: linked children appear right after their parent
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
                            foreach ($linkedA[(int) $a['addon_entry_id']] as $child) {
                                $sortedA[] = $child;
                            }
                        }
                    }
                }
                $addonsData[$entry['entry_id']] = $sortedA;
            }

            // Customized addons
            if (!empty($addonsData[$entry['entry_id']])) {
                // ── ✦ ACCESSORIES banner ──
                $sheet->getRowDimension($row)->setRowHeight(16);
                $sheet->setCellValue('E' . $row, '  ✦  ACCESSORIES');
                $sheet->mergeCells('E' . $row . ':L' . $row);
                applyStyle($sheet, 'A' . $row . ':L' . $row, [
                    'font' => [
                        'bold' => true,
                        'size' => 8,
                        'name' => 'Calibri',
                        'color' => ['rgb' => '1A1A1A']
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'indent' => 1
                    ],
                    'fill' => solidFill('F0F0F0'),
                    'borders' => thinBorders('888888'),
                ]);
                $row++;

                // ── Column label sub-header for accessories ──
                $sheet->getRowDimension($row)->setRowHeight(14);
                $sheet->mergeCells('E' . $row . ':F' . $row);
                $sheet->setCellValue('E' . $row, 'Item Name');
                $sheet->setCellValue('G' . $row, 'Price / Item');
                $sheet->setCellValue('H' . $row, 'QTY');
                $sheet->setCellValue('I' . $row, 'Materials');
                $sheet->setCellValue('J' . $row, 'Labor');
                $sheet->mergeCells('K' . $row . ':L' . $row);
                $sheet->setCellValue('K' . $row, 'Total');
                applyStyle($sheet, 'A' . $row . ':L' . $row, [
                    'font' => [
                        'bold' => true,
                        'size' => 7,
                        'name' => 'Calibri',
                        'color' => ['rgb' => '444444'],
                        'italic' => true
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER
                    ],
                    'fill' => solidFill('F7F7F7'),
                    'borders' => thinBorders('888888'),
                ]);
                applyStyle($sheet, 'E' . $row, [
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'indent' => 2
                    ],
                ]);
                $row++;

                foreach ($addonsData[$entry['entry_id']] as $addon) {
                    $calc = computeAddonTotals(
                        $addon['price'],
                        $addon['labor_cost'] ?? 0,
                        $addon['addon_jackup'] ?? 0,
                        $addon['computed_area'] ?? 0,
                        $addon['quantity'],
                        $addon['is_stable_mat'] ?? 0,
                        $addon['multiply_value'] ?? 0,
                        $addon['min_required_unit'] ?? 0
                    );
                    $aTotMats = $calc['tot_mats'];
                    $aTotLabor = $calc['tot_labor'];
                    $aTotal = $calc['total'];
                    $aPricePerItem = $calc['price_per_item'];

                    $sheet->getRowDimension($row)->setRowHeight(16);
                    $sheet->mergeCells('E' . $row . ':F' . $row);
                    $sheet->setCellValue('E' . $row, '  › ' . $addon['addon_name']);
                    $sheet->setCellValue('G' . $row, $aPricePerItem);
                    $sheet->setCellValue('H' . $row, $addon['quantity'] . ' pc(s)');
                    $sheet->setCellValue('I' . $row, $aTotMats);
                    $sheet->setCellValue('J' . $row, $aTotLabor);
                    $sheet->mergeCells('K' . $row . ':L' . $row);
                    $sheet->setCellValue('K' . $row, $aTotal);
                    applyStyle($sheet, 'A' . $row . ':L' . $row, [
                        'font' => baseFont(8, false, '1A1A1A'),
                        'alignment' => [
                            'vertical' => Alignment::VERTICAL_CENTER,
                            'horizontal' => Alignment::HORIZONTAL_LEFT
                        ],
                        'fill' => solidFill('FFFFFF'),
                        'borders' => thinBorders('888888'),
                    ]);
                    applyStyle($sheet, 'G' . $row . ':L' . $row, [
                        'alignment' => [
                            'horizontal' => Alignment::HORIZONTAL_CENTER,
                            'vertical' => Alignment::VERTICAL_CENTER
                        ],
                    ]);
                    numFmt($sheet, 'G' . $row);
                    numFmt($sheet, 'I' . $row);
                    numFmt($sheet, 'J' . $row);
                    numFmt($sheet, 'K' . $row);
                    $row++;

                    if (!empty($addon['note'])) {
                        $sheet->getRowDimension($row)->setRowHeight(22);
                        $sheet->setCellValue('E' . $row, '    📝 ' . $addon['note']);
                        $sheet->mergeCells('E' . $row . ':L' . $row);
                        applyStyle($sheet, 'A' . $row . ':L' . $row, [
                            'font' => baseFont(8, false, '888888', true),
                            'alignment' => [
                                'vertical' => Alignment::VERTICAL_CENTER,
                                'wrapText' => true
                            ],
                            'fill' => solidFill('FAFAFA'),
                            'borders' => thinBorders('EEEEEE'),
                        ]);
                        $sheet->getStyle('E' . $row)->getAlignment()->setWrapText(true);
                        $row++;
                    }
                }
            }
        }

        // ── FIXED SIZE entries ──
        foreach ($areaFixed as $entry) {
            $rowBg = $rowIsEven ? 'F4F3FF' : 'F9F8FF';
            $rowIsEven = !$rowIsEven;

            $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
            $nameRun = $richText->createTextRun(($entry['item_name'] ?? '') . "\n");
            $nameRun->getFont()->setBold(true)->setSize(10)->setName('Calibri')
                ->setColor(new Color('1A1A1A'));

            $detailStr = ($entry['size_label'] ?? '')
                . (($entry['color_label'] ?? '') ? '  |  Color: ' . $entry['color_label'] : '') . "\n"
                . (($entry['size_width'] && $entry['measurement_unit']) ? ($entry['item_width_label_linear'] ?? 'W') . ': ' . $entry['size_width'] . $entry['measurement_unit'] . '  ' : '')
                . (($entry['size_height'] && $entry['measurement_unit']) ? ($entry['item_height_label_linear'] ?? 'H') . ': ' . $entry['size_height'] . $entry['measurement_unit'] . '  ' : '')
                . (($entry['size_length'] && $entry['measurement_unit']) ? ($entry['item_length_label_linear'] ?? 'L') . ': ' . $entry['size_length'] . $entry['measurement_unit'] : '');

            $detailRun = $richText->createTextRun($detailStr);
            $detailRun->getFont()->setSize(8)->setName('Calibri')
                ->setColor(new Color('777777'));

            $baseTotal = floatval($entry['base_price']) * intval($entry['quantity']);

            $sheet->getRowDimension($row)->setRowHeight(95);
            $sheet->setCellValue('A' . $row, '');
            $sheet->mergeCells('B' . $row . ':D' . $row);
            $sheet->setCellValue('B' . $row, '');
            $sheet->mergeCells('E' . $row . ':F' . $row);
            $sheet->setCellValue('E' . $row, $richText);
            $sheet->setCellValue('G' . $row, 'Fixed Size');
            $sheet->setCellValue('H' . $row, intval($entry['quantity']) . "\n(" . $entry['unit_type'] . ')');
            $sheet->setCellValue('I' . $row, floatval($entry['base_price']));
            $sheet->setCellValue('J' . $row, 0);
            $sheet->setCellValue('K' . $row, floatval($entry['base_price']));
            $sheet->setCellValue('L' . $row, $baseTotal);

            applyStyle($sheet, 'A' . $row . ':L' . $row, [
                'font' => baseFont(9, false, '1A1A1A'),
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
                'fill' => solidFill($rowBg),
                'borders' => thinBorders('888888'),
            ]);
            applyStyle($sheet, 'G' . $row . ':L' . $row, [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_TOP,
                    'wrapText' => true
                ],
            ]);
            applyStyle($sheet, 'I' . $row . ':L' . $row, [
                'font' => baseFont(9, true, '1A1A1A'),
            ]);
            $sheet->getStyle('E' . $row)->getAlignment()->setWrapText(true);
            numFmt($sheet, 'I' . $row);
            numFmt($sheet, 'K' . $row);
            numFmt($sheet, 'L' . $row);
            applyStyle($sheet, 'A' . $row, [
                'borders' => [
                    'left' => [
                        'borderStyle' => Border::BORDER_MEDIUM,
                        'color' => ['rgb' => '333333']
                    ]
                ],
            ]);
            $row++;

            // Sort fixed addons: linked children appear right after their parent
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
                            foreach ($linkedF[(int) $a['id']] as $child) {
                                $sortedF[] = $child;
                            }
                        }
                    }
                }
                $fixedAddonsData[$entry['id']] = $sortedF;
            }

            // Fixed size addons
            if (!empty($fixedAddonsData[$entry['id']])) {
                // ── ✦ ACCESSORIES banner ──
                $sheet->getRowDimension($row)->setRowHeight(16);
                $sheet->setCellValue('E' . $row, '  ✦  ACCESSORIES');
                $sheet->mergeCells('E' . $row . ':L' . $row);
                applyStyle($sheet, 'A' . $row . ':L' . $row, [
                    'font' => [
                        'bold' => true,
                        'size' => 8,
                        'name' => 'Calibri',
                        'color' => ['rgb' => '1A1A1A']
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'indent' => 1
                    ],
                    'fill' => solidFill('F0F0F0'),
                    'borders' => thinBorders('888888'),
                ]);
                $row++;

                // ── Column label sub-header for fixed accessories ──
                $sheet->getRowDimension($row)->setRowHeight(14);
                $sheet->mergeCells('E' . $row . ':F' . $row);
                $sheet->setCellValue('E' . $row, 'Item Name');
                $sheet->setCellValue('G' . $row, 'Price / Item');
                $sheet->setCellValue('H' . $row, 'QTY');
                $sheet->setCellValue('I' . $row, 'Materials');
                $sheet->setCellValue('J' . $row, 'Labor');
                $sheet->mergeCells('K' . $row . ':L' . $row);
                $sheet->setCellValue('K' . $row, 'Total');
                applyStyle($sheet, 'A' . $row . ':L' . $row, [
                    'font' => [
                        'bold' => true,
                        'size' => 7,
                        'name' => 'Calibri',
                        'color' => ['rgb' => '444444'],
                        'italic' => true
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER
                    ],
                    'fill' => solidFill('F7F7F7'),
                    'borders' => thinBorders('888888'),
                ]);
                applyStyle($sheet, 'E' . $row, [
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'indent' => 2
                    ],
                ]);
                $row++;

                foreach ($fixedAddonsData[$entry['id']] as $addon) {
                    $calc = computeAddonTotals(
                        $addon['price'],
                        $addon['labor_cost'] ?? 0,
                        $addon['addon_jackup'] ?? 0,
                        $addon['computed_area'] ?? 0,
                        $addon['quantity'],
                        $addon['is_stable_mat'] ?? 0,
                        $addon['multiply_value'] ?? 0,
                        $addon['min_required_unit'] ?? 0
                    );
                    $fTotMats = $calc['tot_mats'];
                    $fTotLabor = $calc['tot_labor'];
                    $fTotal = $calc['total'];
                    $fPricePerItem = $calc['price_per_item'];

                    $catLabel = !empty($addon['addon_category']) ? ' (' . $addon['addon_category'] . ')' : '';

                    $sheet->getRowDimension($row)->setRowHeight(16);
                    $sheet->mergeCells('E' . $row . ':F' . $row);
                    $sheet->setCellValue('E' . $row, '  › ' . $addon['addon_name'] . $catLabel);
                    $sheet->setCellValue('G' . $row, $fPricePerItem);
                    $sheet->setCellValue('H' . $row, $addon['quantity'] . ' pc(s)');
                    $sheet->setCellValue('I' . $row, $fTotMats);
                    $sheet->setCellValue('J' . $row, $fTotLabor);
                    $sheet->mergeCells('K' . $row . ':L' . $row);
                    $sheet->setCellValue('K' . $row, $fTotal);
                    applyStyle($sheet, 'A' . $row . ':L' . $row, [
                        'font' => baseFont(8, false, '1A1A1A'),
                        'alignment' => [
                            'vertical' => Alignment::VERTICAL_CENTER,
                            'horizontal' => Alignment::HORIZONTAL_LEFT
                        ],
                        'fill' => solidFill('FFFFFF'),
                        'borders' => thinBorders('888888'),
                    ]);
                    applyStyle($sheet, 'G' . $row . ':L' . $row, [
                        'alignment' => [
                            'horizontal' => Alignment::HORIZONTAL_CENTER,
                            'vertical' => Alignment::VERTICAL_CENTER
                        ],
                    ]);
                    numFmt($sheet, 'G' . $row);
                    numFmt($sheet, 'I' . $row);
                    numFmt($sheet, 'J' . $row);
                    numFmt($sheet, 'K' . $row);
                    $row++;

                    if (!empty($addon['note'])) {
                        $sheet->getRowDimension($row)->setRowHeight(22);
                        $sheet->setCellValue('E' . $row, '    📝 ' . $addon['note']);
                        $sheet->mergeCells('E' . $row . ':L' . $row);
                        applyStyle($sheet, 'A' . $row . ':L' . $row, [
                            'font' => baseFont(8, false, '888888', true),
                            'alignment' => [
                                'vertical' => Alignment::VERTICAL_CENTER,
                                'wrapText' => true
                            ],
                            'fill' => solidFill('FAFAFA'),
                            'borders' => thinBorders('EEEEEE'),
                        ]);
                        $sheet->getStyle('E' . $row)->getAlignment()->setWrapText(true);
                        $row++;
                    }
                }
            }
        }

        $itemNo++;
    }

    $row += 1;

    // ── Nothing follows ──
    $sheet->getRowDimension($row)->setRowHeight(18);
    $sheet->setCellValue('A' . $row, '— NOTHING FOLLOWS —');
    $sheet->mergeCells('A' . $row . ':L' . $row);
    applyStyle($sheet, 'A' . $row . ':L' . $row, [
        'font' => [
            'bold' => true,
            'size' => 9,
            'name' => 'Calibri',
            'color' => ['rgb' => 'AAAAAA'],
            'italic' => true
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'fill' => solidFill('F5F5F5'),
        'borders' => thinBorders('EEEEEE'),
    ]);
    $row += 2;

    // ═══════════════════════════════════════════════════════════════════
    // TOTALS SECTION — label G:J, currency symbol K, value L
    // ═══════════════════════════════════════════════════════════════════
    $addTotalRow = function ($label, $value, $symbolPrefix = 'PHP', $labelBg = 'FFFFFF', $valueBg = 'FFFFFF', $labelColor = '1A1A1A', $valueColor = '1A1A1A', $fontSize = 10) use ($sheet, &$row) {
        $sheet->getRowDimension($row)->setRowHeight(22);
        $sheet->setCellValue('G' . $row, $label);
        $sheet->mergeCells('G' . $row . ':J' . $row);
        $sheet->setCellValue('K' . $row, $symbolPrefix);
        $sheet->setCellValue('L' . $row, $value);
        applyStyle($sheet, 'G' . $row . ':J' . $row, [
            'font' => [
                'bold' => true,
                'size' => 9,
                'name' => 'Calibri',
                'color' => ['rgb' => $labelColor]
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_RIGHT,
                'vertical' => Alignment::VERTICAL_CENTER,
                'indent' => 1
            ],
            'fill' => solidFill($labelBg),
            'borders' => thinBorders('333333'),
        ]);
        applyStyle($sheet, 'K' . $row, [
            'font' => [
                'bold' => true,
                'size' => $fontSize,
                'name' => 'Calibri',
                'color' => ['rgb' => $valueColor]
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'fill' => solidFill($valueBg),
            'borders' => thinBorders('333333'),
        ]);
        applyStyle($sheet, 'L' . $row, [
            'font' => [
                'bold' => true,
                'size' => $fontSize,
                'name' => 'Calibri',
                'color' => ['rgb' => $valueColor]
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_RIGHT,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'fill' => solidFill($valueBg),
            'borders' => thinBorders('333333'),
        ]);
        numFmt($sheet, 'L' . $row);
        $row++;
    };

    $addTotalRow('SUBTOTAL', $display_rawTotal);

    if ($storedDiscount > 0) {
        $addTotalRow('DISCOUNT (' . $storedDiscount . '%)', $display_discountAmt, '-PHP', 'FFFFFF', 'FFFFFF', 'B00020', 'B00020');
        $addTotalRow('AFTER DISCOUNT', $display_afterDiscount);
    }

    // ── Blank gap row before Grand Total ──
    $sheet->getRowDimension($row)->setRowHeight(8);
    $row++;

    if ($business_type === 'Project') {
        $addTotalRow('GENERAL REQUIREMENTS (10%)', $display_generalReq);
        $addTotalRow('SUBTOTAL w/ GR', $display_subtotalWithGR);
        $addTotalRow('VAT (12%)', $display_vat);
    }

    // Grand Total
    $sheet->getRowDimension($row)->setRowHeight(28);
    $sheet->setCellValue('G' . $row, 'GRAND TOTAL');
    $sheet->mergeCells('G' . $row . ':J' . $row);
    $sheet->setCellValue('K' . $row, 'PHP');
    $sheet->setCellValue('L' . $row, $display_finalTotal);
    applyStyle($sheet, 'G' . $row . ':J' . $row, [
        'font' => [
            'bold' => true,
            'size' => 11,
            'name' => 'Calibri',
            'color' => ['rgb' => '1A1A1A']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_RIGHT,
            'vertical' => Alignment::VERTICAL_CENTER,
            'indent' => 1
        ],
        'fill' => solidFill('C4BD97'),
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_MEDIUM,
                'color' => ['rgb' => '333333']
            ]
        ],
    ]);
    applyStyle($sheet, 'K' . $row . ':L' . $row, [
        'font' => [
            'bold' => true,
            'size' => 12,
            'name' => 'Calibri',
            'color' => ['rgb' => '1A1A1A']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_RIGHT,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'fill' => solidFill('C4BD97'),
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_MEDIUM,
                'color' => ['rgb' => '333333']
            ]
        ],
    ]);
    numFmt($sheet, 'L' . $row);
    $row += 3;

    // ═══════════════════════════════════════════════════════════════════
    // NOTES & BANK INFO
    // ═══════════════════════════════════════════════════════════════════
    $sheet->getRowDimension($row)->setRowHeight(8);
    $row++;

    $sheet->setCellValue('A' . $row, 'NOTES & PAYMENT TERMS');
    $sheet->mergeCells('A' . $row . ':L' . $row);
    applyStyle($sheet, 'A' . $row . ':L' . $row, [
        'font' => [
            'bold' => true,
            'size' => 9,
            'name' => 'Calibri',
            'color' => ['rgb' => '1A1A1A']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT,
            'vertical' => Alignment::VERTICAL_CENTER,
            'indent' => 1
        ],
        'fill' => solidFill('F0F0F0'),
        'borders' => thinBorders('333333'),
    ]);
    $row++;

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
    foreach ($notes1 as $note) {
        $sheet->getRowDimension($row)->setRowHeight(16);
        $sheet->setCellValue('A' . $row, $note);
        $sheet->mergeCells('A' . $row . ':L' . $row);
        applyStyle($sheet, 'A' . $row . ':L' . $row, [
            'font' => baseFont(8, false, '444444'),
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
                'indent' => 1
            ],
            'fill' => solidFill('FAFAFA'),
            'borders' => thinBorders('EEEEEE'),
        ]);
        $row++;
    }
    $row++;

    // ── BANK table: A=Bank, B=blank spacer, C:G=Account Name, H:L=Account Number ──
    $sheet->getRowDimension($row)->setRowHeight(16);
    $sheet->setCellValue('A' . $row, 'BANK');
    $sheet->setCellValue('C' . $row, 'ACCOUNT NAME');
    $sheet->mergeCells('C' . $row . ':G' . $row);
    $sheet->setCellValue('H' . $row, 'ACCOUNT NUMBER');
    $sheet->mergeCells('H' . $row . ':L' . $row);
    applyStyle($sheet, 'A' . $row . ':L' . $row, [
        'font' => [
            'bold' => true,
            'size' => 8,
            'name' => 'Calibri',
            'color' => ['rgb' => '1A1A1A']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'fill' => solidFill('F0F0F0'),
        'borders' => thinBorders('333333'),
    ]);
    $row++;

    $bankData = [
        ['BDO', 'REALIVING DESIGN CENTER CORP.', '008-530-005-770'],
        ['AUB', 'REALIVING DESIGN CENTER CORP.', '004-010-009-294'],
        ['PNB', 'REALIVING DESIGN CENTER CORP.', '165-870-001-147'],
    ];
    $bankBgs = ['FFFFFF', 'FAFAFA', 'FFFFFF'];
    foreach ($bankData as $i => $bank) {
        $sheet->getRowDimension($row)->setRowHeight(16);
        $sheet->setCellValue('A' . $row, $bank[0]);
        $sheet->setCellValue('C' . $row, $bank[1]);
        $sheet->mergeCells('C' . $row . ':G' . $row);
        $sheet->setCellValue('H' . $row, $bank[2]);
        $sheet->mergeCells('H' . $row . ':L' . $row);
        applyStyle($sheet, 'A' . $row . ':L' . $row, [
            'font' => baseFont(8, false, '1A1A1A'),
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'fill' => solidFill($bankBgs[$i]),
            'borders' => thinBorders('DDDDDD'),
        ]);
        applyStyle($sheet, 'H' . $row . ':L' . $row, [
            'font' => [
                'bold' => true,
                'size' => 8,
                'name' => 'Calibri',
                'color' => ['rgb' => 'CC2200']
            ],
        ]);
        $row++;
    }
    $row++;

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
    foreach ($notes2 as $note) {
        $sheet->getRowDimension($row)->setRowHeight(16);
        $sheet->setCellValue('A' . $row, $note);
        $sheet->mergeCells('A' . $row . ':L' . $row);
        applyStyle($sheet, 'A' . $row . ':L' . $row, [
            'font' => baseFont(8, false, '444444'),
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
                'indent' => 1
            ],
            'fill' => solidFill('FAFAFA'),
            'borders' => thinBorders('EEEEEE'),
        ]);
        $row++;
    }
    $row += 2;

    $sheet->getRowDimension($row)->setRowHeight(22);
    $sheet->setCellValue('A' . $row, 'Thank you for giving us a chance to be of service. We look forward to working with you!');
    $sheet->mergeCells('A' . $row . ':L' . $row);
    applyStyle($sheet, 'A' . $row . ':L' . $row, [
        'font' => [
            'bold' => true,
            'size' => 9,
            'name' => 'Calibri',
            'color' => ['rgb' => '1A1A1A'],
            'italic' => true
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'fill' => solidFill('FFFFFF'),
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_NONE]],
    ]);
    $row += 3;

    // ═══════════════════════════════════════════════════════════════════
    // SIGNATURE SECTION — 4 equal blocks across A:L (3 columns each)
    // A:C = Prepared by | D:F = Approved by | G:I = Approved by | J:L = Conforme
    // ═══════════════════════════════════════════════════════════════════

    $sigSlots = [
        ['A', 'C', 'Prepared by:', $admin_name, 'Designer / Sales Associates'],
        ['D', 'F', 'Approved by:', '', 'Project Operation Manager'],
        ['G', 'I', 'Approved by:', '', 'General Manager'],
        ['J', 'L', 'Conforme:', '', ''],
    ];

    // ── Row 1: Label header row (like BANK header) ──
    $sheet->getRowDimension($row)->setRowHeight(16);
    foreach ($sigSlots as [$sc, $ec, $label]) {
        $sheet->setCellValue($sc . $row, $label);
        $sheet->mergeCells($sc . $row . ':' . $ec . $row);
        applyStyle($sheet, $sc . $row . ':' . $ec . $row, [
            'font' => baseFont(8, true, '1A1A1A'),
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => solidFill('F0F0F0'),
            'borders' => thinBorders('333333'),
        ]);
    }
    $row++;

    // ── Row 2: Signature space with e-signature image for "Prepared by" ──
    $sheet->getRowDimension($row)->setRowHeight(50);
    foreach ($sigSlots as [$sc, $ec]) {
        $sheet->mergeCells($sc . $row . ':' . $ec . $row);
        applyStyle($sheet, $sc . $row . ':' . $ec . $row, [
            'fill' => solidFill('FAFAFA'),
            'borders' => [
                'left' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '333333']],
                'right' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '333333']],
                'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '333333']],
                'bottom' => ['borderStyle' => Border::BORDER_NONE],
            ],
        ]);
    }
    // Embed e-signature image into "Prepared by" cell (first slot = A col)
    if (!empty($admin_esignature) && file_exists($admin_esignature)) {
        $sigDrawing = new Drawing();
        $sigDrawing->setName('Signature')
            ->setDescription('E-Signature')
            ->setPath($admin_esignature)
            ->setHeight(44)
            ->setOffsetX(18)
            ->setOffsetY(3)
            ->setCoordinates('A' . $row)
            ->setWorksheet($sheet);
    }
    $row++;

    // ── Row 3: Names ──
    $sheet->getRowDimension($row)->setRowHeight(18);
    foreach ($sigSlots as [$sc, $ec, , $name]) {
        $sheet->setCellValue($sc . $row, $name);
        $sheet->mergeCells($sc . $row . ':' . $ec . $row);
        applyStyle($sheet, $sc . $row . ':' . $ec . $row, [
            'font' => baseFont(9, true, '1A1A1A'),
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => solidFill('FFFFFF'),
            'borders' => [
                'left' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '333333']],
                'right' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '333333']],
                'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '333333']],
            ],
        ]);
    }
    $row++;

    // ── Row 4: Titles ──
    $sheet->getRowDimension($row)->setRowHeight(14);
    foreach ($sigSlots as [$sc, $ec, , , $title]) {
        $sheet->setCellValue($sc . $row, $title);
        $sheet->mergeCells($sc . $row . ':' . $ec . $row);
        applyStyle($sheet, $sc . $row . ':' . $ec . $row, [
            'font' => baseFont(8, false, '555555', true),
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => solidFill('F7F9FC'),
            'borders' => [
                'left' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '333333']],
                'right' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '333333']],
                'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '333333']],
            ],
        ]);
    }

    // ── Output ──
    $tempFile = tempnam(sys_get_temp_dir(), 'quotation_');
    $writer = new Xlsx($spreadsheet);
    $writer->save($tempFile);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="quotation_' . $safe_client_name . '_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    header('Content-Length: ' . filesize($tempFile));

    readfile($tempFile);
    unlink($tempFile);
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Error generating Excel file: ' . $e->getMessage();
}

exit;