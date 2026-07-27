<?php
// backup_client.php
session_start();
include $includes ['connection'];
include $includes ['checkrole'];

require_role(['sales', 'designer', 'technical_designer']);

$admin_id  = $_SESSION['admin_id'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

if (!$client_id) {
    http_response_code(400);
    echo 'Invalid client ID';
    exit();
}

// ── Ownership check ──────────────────────────────────────────────────────────
$ownerStmt = $conn->prepare("SELECT accountaid_fk, clientname, reference_number, nameproject, contact, email, address, status, account_status, business_type FROM user_info WHERE id = ?");
$ownerStmt->bind_param("i", $client_id);
$ownerStmt->execute();
$clientInfo = $ownerStmt->get_result()->fetch_assoc();
$ownerStmt->close();

if (!$clientInfo) {
    http_response_code(404);
    echo 'Client not found';
    exit();
}

if ((int)$clientInfo['accountaid_fk'] !== $admin_id) {
    http_response_code(403);
    echo 'Access denied';
    exit();
}

// ── Check ZipArchive ─────────────────────────────────────────────────────────
if (!extension_loaded('zip')) {
    http_response_code(500);
    echo 'ZIP extension is not enabled. Please enable php_zip in php.ini.';
    exit();
}

$base       = '../../';
$clientName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $clientInfo['clientname']);
$refNumber  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $clientInfo['reference_number'] ?? 'NO_REF');
$dateStamp  = date('Y-m-d');
$zipRoot    = "client_backup_{$clientName}_{$refNumber}_{$dateStamp}";

// Temp ZIP path
$tmpZip = sys_get_temp_dir() . '/' . $zipRoot . '_' . uniqid() . '.zip';

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Failed to create ZIP file';
    exit();
}

// ── Helper: safely add a physical file into the ZIP ──────────────────────────
function addFile(ZipArchive $zip, string $abs_path, string $zip_path): void
{
    if (file_exists($abs_path) && is_file($abs_path)) {
        $zip->addFile($abs_path, $zip_path);
    }
}

$fileCount = 0; // track how many actual files were added

// ════════════════════════════════════════════════════════════════════════════
// 1. stage_approvals  →  stage_approvals/
//    file_path: uploads/stage_approvals/{client_id}/{filename}
// ════════════════════════════════════════════════════════════════════════════
$saStmt = $conn->prepare("SELECT file_name, file_path FROM stage_approvals WHERE client_id = ? AND file_path IS NOT NULL");
$saStmt->bind_param("i", $client_id);
$saStmt->execute();
$saResult = $saStmt->get_result();
while ($row = $saResult->fetch_assoc()) {
    $absPath = $base . $row['file_path'];
    $zipPath = "$zipRoot/stage_approvals/" . basename($row['file_path']);
    addFile($zip, $absPath, $zipPath);
    $fileCount++;
}
$saStmt->close();

// ════════════════════════════════════════════════════════════════════════════
// 2. payment_proofs  →  payments/proofs/
//    file_path: uploads/payment_proofs/{filename}
// ════════════════════════════════════════════════════════════════════════════
$ppStmt = $conn->prepare("
    SELECT pp.file_name, pp.file_path
    FROM payment_proofs pp
    INNER JOIN payment_schedule ps ON pp.payment_id = ps.id
    WHERE ps.client_id = ? AND pp.file_path IS NOT NULL
");
$ppStmt->bind_param("i", $client_id);
$ppStmt->execute();
$ppResult = $ppStmt->get_result();
while ($row = $ppResult->fetch_assoc()) {
    $absPath = $base . $row['file_path'];
    $zipPath = "$zipRoot/payments/proofs/" . basename($row['file_path']);
    addFile($zip, $absPath, $zipPath);
    $fileCount++;
}
$ppStmt->close();

// ════════════════════════════════════════════════════════════════════════════
// 3. site_visit photos  →  site_visits/photos/
//    stored as just filename: uploads/site_visit_photos/{filename}
// ════════════════════════════════════════════════════════════════════════════
$svStmt = $conn->prepare("SELECT designer1_photo, designer2_photo FROM site_visit WHERE client_id = ?");
$svStmt->bind_param("i", $client_id);
$svStmt->execute();
$svResult = $svStmt->get_result();
while ($row = $svResult->fetch_assoc()) {
    foreach (['designer1_photo', 'designer2_photo'] as $col) {
        if (!empty($row[$col])) {
            $absPath = $base . 'uploads/site_visit_photos/' . $row[$col];
            $zipPath = "$zipRoot/site_visits/photos/" . $row[$col];
            addFile($zip, $absPath, $zipPath);
            $fileCount++;
        }
    }
}
$svStmt->close();

// ════════════════════════════════════════════════════════════════════════════
// 4. layout_attachments  →  layouts/files/
//    file_path: uploads/layout_attachments/{file_path}
// ════════════════════════════════════════════════════════════════════════════
$laStmt = $conn->prepare("SELECT file_name, file_path FROM layout_attachments WHERE client_id = ? AND file_path IS NOT NULL");
$laStmt->bind_param("i", $client_id);
$laStmt->execute();
$laResult = $laStmt->get_result();
while ($row = $laResult->fetch_assoc()) {
    $absPath = $base . 'uploads/layout_attachments/' . $row['file_path'];
    $zipPath = "$zipRoot/layouts/files/" . basename($row['file_path']);
    addFile($zip, $absPath, $zipPath);
    $fileCount++;
}
$laStmt->close();

// ════════════════════════════════════════════════════════════════════════════
// 5. td_attachments  →  td_attachments/files/
//    file_path: uploads/td_attachments/{file_path}
// ════════════════════════════════════════════════════════════════════════════
$tdaStmt = $conn->prepare("SELECT file_name, file_path FROM td_attachments WHERE client_id = ? AND file_path IS NOT NULL");
$tdaStmt->bind_param("i", $client_id);
$tdaStmt->execute();
$tdaResult = $tdaStmt->get_result();
while ($row = $tdaResult->fetch_assoc()) {
    $absPath = $base . 'uploads/td_attachments/' . $row['file_path'];
    $zipPath = "$zipRoot/td_attachments/files/" . basename($row['file_path']);
    addFile($zip, $absPath, $zipPath);
    $fileCount++;
}
$tdaStmt->close();

// ════════════════════════════════════════════════════════════════════════════
// 6. quotation_entries images  →  quotations/images/
//    item_image_path / color_image_path stored as relative path from root
// ════════════════════════════════════════════════════════════════════════════
$qeStmt = $conn->prepare("SELECT item_image_path, color_image_path FROM quotation_entries WHERE client_id = ?");
$qeStmt->bind_param("i", $client_id);
$qeStmt->execute();
$qeResult = $qeStmt->get_result();
while ($row = $qeResult->fetch_assoc()) {
    foreach (['item_image_path', 'color_image_path'] as $col) {
        if (!empty($row[$col])) {
            $absPath = $base . $row[$col];
            $zipPath = "$zipRoot/quotations/images/" . basename($row[$col]);
            addFile($zip, $absPath, $zipPath);
            $fileCount++;
        }
    }
}
$qeStmt->close();

// ════════════════════════════════════════════════════════════════════════════
// 7. quotation_fixed_sizes images  →  quotations/images/
// ════════════════════════════════════════════════════════════════════════════
$qfsStmt = $conn->prepare("SELECT item_image_path, color_image_path FROM quotation_fixed_sizes WHERE client_id = ?");
$qfsStmt->bind_param("i", $client_id);
$qfsStmt->execute();
$qfsResult = $qfsStmt->get_result();
while ($row = $qfsResult->fetch_assoc()) {
    foreach (['item_image_path', 'color_image_path'] as $col) {
        if (!empty($row[$col])) {
            $absPath = $base . $row[$col];
            $zipPath = "$zipRoot/quotations/images/" . basename($row[$col]);
            addFile($zip, $absPath, $zipPath);
            $fileCount++;
        }
    }
}
$qfsStmt->close();

// ════════════════════════════════════════════════════════════════════════════
// 8. README.txt — client summary
// ════════════════════════════════════════════════════════════════════════════
$btype   = $clientInfo['business_type'] === 'Non-Project' ? 'Individual' : ($clientInfo['business_type'] ?? 'N/A');
$summary  = "================================================\n";
$summary .= "  CLIENT BACKUP — " . strtoupper($clientInfo['clientname'] ?? 'N/A') . "\n";
$summary .= "================================================\n\n";
$summary .= "Reference #    : " . ($clientInfo['reference_number'] ?? 'N/A') . "\n";
$summary .= "Client Name    : " . ($clientInfo['clientname']       ?? 'N/A') . "\n";
$summary .= "Project Name   : " . ($clientInfo['nameproject']      ?? 'N/A') . "\n";
$summary .= "Contact        : " . ($clientInfo['contact']          ?? 'N/A') . "\n";
$summary .= "Email          : " . ($clientInfo['email']            ?? 'N/A') . "\n";
$summary .= "Address        : " . ($clientInfo['address']          ?? 'N/A') . "\n";
$summary .= "Status         : " . ($clientInfo['status']           ?? 'N/A') . "\n";
$summary .= "Account Status : " . ($clientInfo['account_status']   ?? 'N/A') . "\n";
$summary .= "Business Type  : " . $btype . "\n\n";
$summary .= "Backup Date    : " . date('F d, Y  h:i A') . "\n";
$summary .= "Total Files    : " . $fileCount . " file(s)\n\n";
$summary .= "------------------------------------------------\n";
$summary .= "FOLDER CONTENTS\n";
$summary .= "------------------------------------------------\n";
$summary .= "stage_approvals/     - Uploaded stage approval files\n";
$summary .= "payments/proofs/     - Payment proof PDFs and images\n";
$summary .= "site_visits/photos/  - Designer site visit photos\n";
$summary .= "layouts/files/       - Layout attachment files\n";
$summary .= "td_attachments/files/- Technical designer attachment files\n";
$summary .= "quotations/images/   - Quotation item and color images\n";
$summary .= "------------------------------------------------\n";

$zip->addFromString("$zipRoot/README.txt", $summary);

// ── Close ZIP and stream to browser ─────────────────────────────────────────
$zip->close();

$zipFilename = $zipRoot . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Pragma: no-cache');
header('Expires: 0');

readfile($tmpZip);
unlink($tmpZip);
$conn->close();
exit();