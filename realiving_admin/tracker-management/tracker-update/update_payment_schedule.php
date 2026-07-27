<?php
// update_payment_schedule.php
// This file contains helper functions to update payment schedules for both Project and Non-Project type clients

/**
 * Update payment schedule after installation is complete
 * This is the main function called when installation status changes
 */
function updatePaymentScheduleAfterInstallation($client_id, $conn) {
    // Get client info
    $clientStmt = $conn->prepare("
        SELECT business_type, total_project_cost 
        FROM user_info 
        WHERE id = ?
    ");
    $clientStmt->bind_param("i", $client_id);
    $clientStmt->execute();
    $client = $clientStmt->get_result()->fetch_assoc();
    
    if (!$client) {
        return false;
    }
    
    $business_type = $client['business_type'];
    $total_cost = $client['total_project_cost'];
    
    if ($business_type === 'Project') {
        return updateProjectPaymentSchedule($client_id, $conn, $total_cost);
    } else {
        return updateNonProjectPaymentSchedule($client_id, $conn);
    }
}

/**
 * Update payment schedule for Project type clients
 * Creates progress billing entries for each installed quotation entry
 */
function updateProjectPaymentSchedule($client_id, $conn, $total_cost = null) {
    // Get client info if total_cost not provided
    if ($total_cost === null) {
        $clientStmt = $conn->prepare("
            SELECT business_type, total_project_cost 
            FROM user_info 
            WHERE id = ?
        ");
        $clientStmt->bind_param("i", $client_id);
        $clientStmt->execute();
        $client = $clientStmt->get_result()->fetch_assoc();
        
        if (!$client) {
            return false;
        }
        
        if ($client['business_type'] !== 'Project') {
            return false; // Only for Project type
        }
        
        $total_cost = $client['total_project_cost'];
    }
    
    $remaining_after_downpayment = $total_cost * 0.70;
    
    // Get all quotation entries with Done installation status
    $entriesStmt = $conn->prepare("
        SELECT 
            qe.id,
            qe.installation_status,
            qe.computed_tot_amount,
            i.item_name,
            qe.color_label
        FROM quotation_entries qe
        LEFT JOIN items i ON qe.entry_item_id = i.item_id
        WHERE qe.client_id = ? AND qe.installation_status = 'Done'
        ORDER BY qe.id
    ");
    $entriesStmt->bind_param("i", $client_id);
    $entriesStmt->execute();
    $doneEntries = $entriesStmt->get_result();
    
    // Get total number of entries for calculation
    $totalEntriesStmt = $conn->prepare("
        SELECT COUNT(*) as total FROM quotation_entries WHERE client_id = ?
    ");
    $totalEntriesStmt->bind_param("i", $client_id);
    $totalEntriesStmt->execute();
    $total_entries_count = $totalEntriesStmt->get_result()->fetch_assoc()['total'];
    
    if ($total_entries_count == 0) {
        return false;
    }
    
    $amount_per_entry = $remaining_after_downpayment / $total_entries_count;
    
    while ($entry = $doneEntries->fetch_assoc()) {
        // Build display name
        $item_display_name = $entry['item_name'];
        if ($entry['color_label']) {
            $item_display_name .= ' - ' . $entry['color_label'];
        }
        $payment_type = "Progress Billing - " . $item_display_name;
        
        // Check if payment entry exists
        $checkStmt = $conn->prepare("
            SELECT id, status FROM payment_schedule 
            WHERE client_id = ? AND quotation_entry_id = ?
        ");
        $checkStmt->bind_param("ii", $client_id, $entry['id']);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        
        if (!$existing) {
            // Create new payment entry
            $percentage = ($amount_per_entry / $total_cost) * 100;
            $insertStmt = $conn->prepare("
                INSERT INTO payment_schedule 
                (client_id, payment_type, percentage, amount, status, quotation_entry_id)
                VALUES (?, ?, ?, ?, 'Pending', ?)
            ");
            $insertStmt->bind_param("isddi", $client_id, $payment_type, $percentage, $amount_per_entry, $entry['id']);
            $insertStmt->execute();
        } elseif ($existing['status'] !== 'Paid') {
            // Update existing entry amount (in case total cost changed)
            $percentage = ($amount_per_entry / $total_cost) * 100;
            $updateStmt = $conn->prepare("
                UPDATE payment_schedule 
                SET amount = ?,
                    percentage = ?,
                    payment_type = ?
                WHERE id = ?
            ");
            $updateStmt->bind_param("ddsi", $amount_per_entry, $percentage, $payment_type, $existing['id']);
            $updateStmt->execute();
        }
    }
    
    return true;
}

/**
 * Update payment schedule for Non-Project type clients
 * Updates availability of 40% before installation and 10% after installation payments
 */
function updateNonProjectPaymentSchedule($client_id, $conn) {
    // Get client info
    $clientStmt = $conn->prepare("
        SELECT business_type, total_project_cost 
        FROM user_info 
        WHERE id = ?
    ");
    $clientStmt->bind_param("i", $client_id);
    $clientStmt->execute();
    $client = $clientStmt->get_result()->fetch_assoc();
    
    if (!$client) {
        return false;
    }
    
    if ($client['business_type'] !== 'Non-Project') {
        return false; // Only for Non-Project type
    }
    
    // Check installation status from quotation_entries
    $installCheckStmt = $conn->prepare("
        SELECT 
            SUM(CASE WHEN installation_status = 'Ongoing' THEN 1 ELSE 0 END) as ongoing_count,
            SUM(CASE WHEN installation_status = 'Done' THEN 1 ELSE 0 END) as done_count,
            COUNT(*) as total_count
        FROM quotation_entries
        WHERE client_id = ?
    ");
    $installCheckStmt->bind_param("i", $client_id);
    $installCheckStmt->execute();
    $installStatus = $installCheckStmt->get_result()->fetch_assoc();
    
    // Update 40% before installation
    if ($installStatus['ongoing_count'] > 0) {
        // At least one item is ongoing - payment becomes available
        $updateBeforeStmt = $conn->prepare("
            UPDATE payment_schedule 
            SET status = CASE 
                WHEN status = 'Paid' THEN 'Paid'
                WHEN status = 'Not Available' THEN 'Pending'
                ELSE status
            END
            WHERE client_id = ? AND payment_type = '40% Before Installation'
        ");
        $updateBeforeStmt->bind_param("i", $client_id);
        $updateBeforeStmt->execute();
    } else {
        // No items are ongoing - set back to Not Available if not paid
        $updateBeforeStmt = $conn->prepare("
            UPDATE payment_schedule 
            SET status = CASE 
                WHEN status = 'Paid' THEN 'Paid'
                ELSE 'Not Available'
            END
            WHERE client_id = ? AND payment_type = '40% Before Installation'
        ");
        $updateBeforeStmt->bind_param("i", $client_id);
        $updateBeforeStmt->execute();
    }
    
    // Update 10% after installation
    if ($installStatus['done_count'] == $installStatus['total_count'] && $installStatus['total_count'] > 0) {
        // All items are done - final payment becomes available
        $updateAfterStmt = $conn->prepare("
            UPDATE payment_schedule 
            SET status = CASE 
                WHEN status = 'Paid' THEN 'Paid'
                WHEN status = 'Not Available' THEN 'Pending'
                ELSE status
            END
            WHERE client_id = ? AND payment_type = '10% After Installation'
        ");
        $updateAfterStmt->bind_param("i", $client_id);
        $updateAfterStmt->execute();
    } else {
        // Not all items are done - set back to Not Available if not paid
        $updateAfterStmt = $conn->prepare("
            UPDATE payment_schedule 
            SET status = CASE 
                WHEN status = 'Paid' THEN 'Paid'
                ELSE 'Not Available'
            END
            WHERE client_id = ? AND payment_type = '10% After Installation'
        ");
        $updateAfterStmt->bind_param("i", $client_id);
        $updateAfterStmt->execute();
    }
    
    return true;
}

/**
 * Recalculate all payment amounts when total project cost changes
 * Useful when quotation is updated
 */
function recalculatePaymentAmounts($client_id, $conn) {
    // Get client info
    $clientStmt = $conn->prepare("
        SELECT business_type, total_project_cost 
        FROM user_info 
        WHERE id = ?
    ");
    $clientStmt->bind_param("i", $client_id);
    $clientStmt->execute();
    $client = $clientStmt->get_result()->fetch_assoc();
    
    if (!$client) {
        return false;
    }
    
    $business_type = $client['business_type'];
    $total_cost = $client['total_project_cost'];
    
    // Update downpayment
    $downpayment_percentage = ($business_type === 'Non-Project') ? 50 : 30;
    $downpayment_amount = $total_cost * ($downpayment_percentage / 100);
    
    $updateDownpaymentStmt = $conn->prepare("
        UPDATE payment_schedule 
        SET amount = ?,
            percentage = ?
        WHERE client_id = ? AND payment_type LIKE 'Down Payment%' AND status != 'Paid'
    ");
    $updateDownpaymentStmt->bind_param("ddi", $downpayment_amount, $downpayment_percentage, $client_id);
    $updateDownpaymentStmt->execute();
    
    if ($business_type === 'Project') {
        // Recalculate progress billing amounts
        $remaining_after_downpayment = $total_cost * 0.70;
        
        // Get total number of entries
        $totalEntriesStmt = $conn->prepare("
            SELECT COUNT(*) as total FROM quotation_entries WHERE client_id = ?
        ");
        $totalEntriesStmt->bind_param("i", $client_id);
        $totalEntriesStmt->execute();
        $total_entries_count = $totalEntriesStmt->get_result()->fetch_assoc()['total'];
        
        if ($total_entries_count > 0) {
            $amount_per_entry = $remaining_after_downpayment / $total_entries_count;
            $percentage_per_entry = ($amount_per_entry / $total_cost) * 100;
            
            // Update all progress billing entries
            $updateProgressStmt = $conn->prepare("
                UPDATE payment_schedule 
                SET amount = ?,
                    percentage = ?
                WHERE client_id = ? AND payment_type LIKE 'Progress Billing%' AND status != 'Paid'
            ");
            $updateProgressStmt->bind_param("ddi", $amount_per_entry, $percentage_per_entry, $client_id);
            $updateProgressStmt->execute();
        }
        
    } else {
        // Non-Project: Update 40% and 10% amounts
        $before_installation = $total_cost * 0.40;
        $after_installation = $total_cost * 0.10;
        
        $updateBeforeStmt = $conn->prepare("
            UPDATE payment_schedule 
            SET amount = ?
            WHERE client_id = ? AND payment_type = '40% Before Installation' AND status != 'Paid'
        ");
        $updateBeforeStmt->bind_param("di", $before_installation, $client_id);
        $updateBeforeStmt->execute();
        
        $updateAfterStmt = $conn->prepare("
            UPDATE payment_schedule 
            SET amount = ?
            WHERE client_id = ? AND payment_type = '10% After Installation' AND status != 'Paid'
        ");
        $updateAfterStmt->bind_param("di", $after_installation, $client_id);
        $updateAfterStmt->execute();
    }
    
    return true;
}

/**
 * Initialize payment schedule for a new client
 */
function initializePaymentSchedule($client_id, $conn) {
    // Get client info
    $clientStmt = $conn->prepare("
        SELECT business_type, total_project_cost, downpayment_paid 
        FROM user_info 
        WHERE id = ?
    ");
    $clientStmt->bind_param("i", $client_id);
    $clientStmt->execute();
    $client = $clientStmt->get_result()->fetch_assoc();
    
    if (!$client) {
        return false;
    }
    
    $business_type = $client['business_type'];
    $total_cost = $client['total_project_cost'];
    $downpayment_paid = $client['downpayment_paid'];
    
    // Check if schedule already exists
    $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM payment_schedule WHERE client_id = ?");
    $checkStmt->bind_param("i", $client_id);
    $checkStmt->execute();
    $count = $checkStmt->get_result()->fetch_assoc()['count'];
    
    if ($count > 0) {
        return false; // Already initialized
    }
    
    // Create downpayment entry
    $downpayment_percentage = ($business_type === 'Non-Project') ? 50 : 30;
    $downpayment_amount = $total_cost * ($downpayment_percentage / 100);
    $payment_type = "Down Payment ({$downpayment_percentage}%)";
    $downpayment_status = $downpayment_paid ? 'Paid' : 'Pending';
    
    $insertDownpaymentStmt = $conn->prepare("
        INSERT INTO payment_schedule (client_id, payment_type, percentage, amount, status, payment_date)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $payment_date = $downpayment_paid ? date('Y-m-d H:i:s') : null;
    $insertDownpaymentStmt->bind_param("isddss", $client_id, $payment_type, $downpayment_percentage, $downpayment_amount, $downpayment_status, $payment_date);
    $insertDownpaymentStmt->execute();
    
    if ($business_type === 'Non-Project') {
        // Create 40% before installation and 10% after installation
        $before_installation = $total_cost * 0.40;
        $after_installation = $total_cost * 0.10;
        
        $insertStmt = $conn->prepare("
            INSERT INTO payment_schedule (client_id, payment_type, percentage, amount, status)
            VALUES 
            (?, '40% Before Installation', 40, ?, 'Not Available'),
            (?, '10% After Installation', 10, ?, 'Not Available')
        ");
        $insertStmt->bind_param("idid", $client_id, $before_installation, $client_id, $after_installation);
        $insertStmt->execute();
    }
    // For Project type, progress billing will be created when items are installed
    
    return true;
}

/**
 * Remove payment entry when quotation entry is deleted
 */
function removePaymentEntryForQuotationEntry($quotation_entry_id, $conn) {
    // Delete payment schedule entry linked to this quotation entry
    $deleteStmt = $conn->prepare("
        DELETE FROM payment_schedule 
        WHERE quotation_entry_id = ? AND status != 'Paid'
    ");
    $deleteStmt->bind_param("i", $quotation_entry_id);
    return $deleteStmt->execute();
}

/**
 * Update payment entry name when quotation entry details change
 */
function updatePaymentEntryName($quotation_entry_id, $new_item_name, $new_color_label, $conn) {
    $display_name = $new_item_name;
    if ($new_color_label) {
        $display_name .= ' - ' . $new_color_label;
    }
    $payment_type = "Progress Billing - " . $display_name;
    
    $updateStmt = $conn->prepare("
        UPDATE payment_schedule 
        SET payment_type = ?
        WHERE quotation_entry_id = ?
    ");
    $updateStmt->bind_param("si", $payment_type, $quotation_entry_id);
    return $updateStmt->execute();
}

// ============================================================================
// API ENDPOINT - If this file is called directly via POST request
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    session_start();
    include $includes ['connection'];
    
    if (!isset($_SESSION['admin_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit();
    }
    
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
    
    if ($client_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
        exit();
    }
    
    header('Content-Type: application/json');
    
    switch ($action) {
        case 'update_after_installation':
            $result = updatePaymentScheduleAfterInstallation($client_id, $conn);
            echo json_encode(['success' => $result]);
            break;
            
        case 'recalculate':
            $result = recalculatePaymentAmounts($client_id, $conn);
            echo json_encode(['success' => $result]);
            break;
            
        case 'initialize':
            $result = initializePaymentSchedule($client_id, $conn);
            echo json_encode(['success' => $result]);
            break;
            
        default:
            // Default behavior: determine based on business type
            $typeStmt = $conn->prepare("SELECT business_type FROM user_info WHERE id = ?");
            $typeStmt->bind_param("i", $client_id);
            $typeStmt->execute();
            $businessType = $typeStmt->get_result()->fetch_assoc()['business_type'];
            
            if ($businessType === 'Project') {
                $result = updateProjectPaymentSchedule($client_id, $conn);
            } else {
                $result = updateNonProjectPaymentSchedule($client_id, $conn);
            }
            
            echo json_encode(['success' => $result]);
            break;
    }
    exit();
}
?>