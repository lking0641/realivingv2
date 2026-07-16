<?php
// submit_appointment.php
session_name("Realivinguser");
session_start();

// Include database connection
include $includes['connection'];

header('Content-Type: application/json');

// Validation helper functions
function validateName($name)
{
    $name = trim($name);
    if (strlen($name) < 2 || strlen($name) > 50) {
        return false;
    }
    // Allow letters, spaces, hyphens, apostrophes
    return preg_match("/^[a-zA-Z\s\-']+$/", $name);
}

function validateEmail($email)
{
    $email = trim($email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePhone($phone)
{
    // Remove spaces, dashes, parentheses
    $cleaned = preg_replace('/[\s\-\(\)]/', '', $phone);
    // Check if it contains only digits and is between 7-15 characters
    return preg_match('/^\d{7,15}$/', $cleaned);
}

function sanitizeInput($data)
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Check if connection exists
        if (!isset($conn)) {
            throw new Exception('Database connection failed.');
        }

        // Get and sanitize form data
        $firstName = sanitizeInput($_POST['firstName'] ?? '');
        $lastName = sanitizeInput($_POST['lastName'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $countryCode = sanitizeInput($_POST['countryCode'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $preferredDate = sanitizeInput($_POST['preferredDate'] ?? '');
        $preferredTime = sanitizeInput($_POST['preferredTime'] ?? '');
        $serviceType = sanitizeInput($_POST['serviceType'] ?? '');
        $otherService = ($serviceType === 'others') ? sanitizeInput($_POST['otherService'] ?? '') : null;
        $notes = sanitizeInput($_POST['notes'] ?? '');

        // === VALIDATE REQUIRED FIELDS ===
        if (empty($firstName)) {
            throw new Exception('First name is required.');
        }
        if (empty($lastName)) {
            throw new Exception('Last name is required.');
        }
        if (empty($email)) {
            throw new Exception('Email address is required.');
        }
        if (empty($phone)) {
            throw new Exception('Phone number is required.');
        }
        if (empty($preferredDate)) {
            throw new Exception('Preferred date is required.');
        }
        if (empty($preferredTime)) {
            throw new Exception('Preferred time is required.');
        }
        if (empty($serviceType)) {
            throw new Exception('Service type is required.');
        }

        // === VALIDATE NAME FORMAT ===
        if (!validateName($firstName)) {
            throw new Exception('First name must be 2-50 characters and contain only letters.');
        }
        if (!validateName($lastName)) {
            throw new Exception('Last name must be 2-50 characters and contain only letters.');
        }

        // === VALIDATE EMAIL FORMAT ===
        if (!validateEmail($email)) {
            throw new Exception('Please enter a valid email address.');
        }

        // === VALIDATE PHONE FORMAT ===
        if (!validatePhone($phone)) {
            throw new Exception('Please enter a valid phone number (7-15 digits).');
        }

        // === VALIDATE SERVICE TYPE ===
        $validServiceTypes = ['consultation', 'follow-up', 'review', 'planning', 'others'];
        if (!in_array($serviceType, $validServiceTypes)) {
            throw new Exception('Invalid service type selected.');
        }

        // === VALIDATE "OTHERS" SERVICE TYPE ===
        if ($serviceType === 'others') {
            if (empty($otherService)) {
                throw new Exception('Please specify the service type.');
            }
            if (strlen($otherService) < 3) {
                throw new Exception('Service type description must be at least 3 characters.');
            }
            if (strlen($otherService) > 200) {
                throw new Exception('Service type description is too long (max 200 characters).');
            }
        }

        // === VALIDATE DATE FORMAT ===
        $dateObj = DateTime::createFromFormat('Y-m-d', $preferredDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $preferredDate) {
            throw new Exception('Invalid date format.');
        }

        // === VALIDATE DATE IS NOT IN THE PAST ===
        $selectedDate = new DateTime($preferredDate);
        $today = new DateTime();
        $today->setTime(0, 0, 0);

        if ($selectedDate < $today) {
            throw new Exception('Please select a future date for your appointment.');
        }

        // === VALIDATE NOT SUNDAY ===
        $dayOfWeek = $selectedDate->format('w'); // 0 = Sunday
        if ($dayOfWeek == 0) {
            throw new Exception('We are closed on Sundays. Please select another date.');
        }

        // === VALIDATE TIME FORMAT ===
        if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $preferredTime)) {
            throw new Exception('Invalid time format.');
        }

        // === VALIDATE TIME BASED ON DAY OF WEEK ===
        $timeInt = (int) str_replace(':', '', $preferredTime);

        if ($dayOfWeek >= 1 && $dayOfWeek <= 5) {
            // Monday to Friday: 7:00 AM - 5:00 PM
            if ($timeInt < 700 || $timeInt > 1700) {
                throw new Exception('For weekdays, please select a time between 7:00 AM and 5:00 PM.');
            }
        } elseif ($dayOfWeek == 6) {
            // Saturday: 8:00 AM - 12:00 PM
            if ($timeInt < 800 || $timeInt > 1200) {
                throw new Exception('For Saturdays, please select a time between 8:00 AM and 12:00 PM.');
            }
        }

        // === VALIDATE COUNTRY CODE ===
        $validCountryCodes = ['+1', '+44', '+33', '+49', '+81', '+86', '+91', '+61', '+55', '+63', '+65', '+60', '+66', '+84', '+62'];
        if (!in_array($countryCode, $validCountryCodes)) {
            throw new Exception('Invalid country code selected.');
        }

        // === VALIDATE NOTES LENGTH ===
        if (!empty($notes) && strlen($notes) > 1000) {
            throw new Exception('Additional notes are too long (max 1000 characters).');
        }

        // === CHECK IF DATE ALREADY HAS 5 BOOKINGS ===
        $checkStmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM appointments 
            WHERE preferred_date = ? 
            AND status != 'cancelled'
        ");

        if (!$checkStmt) {
            throw new Exception('Database preparation failed: ' . $conn->error);
        }

        $checkStmt->bind_param("s", $preferredDate);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $bookingCount = $checkResult->fetch_assoc()['count'];
        $checkStmt->close();

        if ($bookingCount >= 5) {
            throw new Exception('This date is fully booked (5/5 slots taken). Please select another date.');
        }

        // === CHECK FOR DUPLICATE BOOKING (same email, date, time) ===
        $duplicateStmt = $conn->prepare("
    SELECT appointment_id 
    FROM appointments 
    WHERE email = ? 
    AND preferred_date = ? 
    AND preferred_time = ? 
    AND status != 'cancelled'
");

        if (!$duplicateStmt) {
            throw new Exception('Database preparation failed: ' . $conn->error);
        }

        $duplicateStmt->bind_param("sss", $email, $preferredDate, $preferredTime);
        $duplicateStmt->execute();
        $duplicateResult = $duplicateStmt->get_result();

        if ($duplicateResult->num_rows > 0) {
            $duplicateStmt->close();
            throw new Exception('You already have an appointment scheduled for this date and time.');
        }
        $duplicateStmt->close();

        // === GET ASSIGNED SALES AGENT ===
        $assignedSalesId = assignToSalesAgent($conn);

        // === INSERT APPOINTMENT ===
        $stmt = $conn->prepare("
    INSERT INTO appointments 
    (first_name, last_name, email, country_code, phone, preferred_date, 
     preferred_time, service_type, other_service, notes, assigned_to, inquiry_type, status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'booking', 'pending')
");

        if (!$stmt) {
            throw new Exception('Database preparation failed: ' . $conn->error);
        }

        $stmt->bind_param(
            "ssssssssssi",
            $firstName,
            $lastName,
            $email,
            $countryCode,
            $phone,
            $preferredDate,
            $preferredTime,
            $serviceType,
            $otherService,
            $notes,
            $assignedSalesId
        );

        if ($stmt->execute()) {
            $appointmentId = $conn->insert_id;

            // Update last_assigned_inquiry for the assigned sales agent
            if ($assignedSalesId) {
                $updateStmt = $conn->prepare("UPDATE account SET last_assigned_inquiry = NOW() WHERE id = ?");
                if ($updateStmt) {
                    $updateStmt->bind_param("i", $assignedSalesId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
            }

            // Format the date for the success message
            $formattedDate = $selectedDate->format('F d, Y');
            $formattedTime = date('g:i A', strtotime($preferredTime));

            echo json_encode([
                'success' => true,
                'message' => "Your appointment has been successfully scheduled for {$formattedDate} at {$formattedTime}. You will receive a confirmation email at {$email} shortly.",
                'appointment_id' => $appointmentId
            ]);
        } else {
            throw new Exception('Failed to submit appointment: ' . $stmt->error);
        }

        $stmt->close();

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
}

if (isset($conn)) {
    $conn->close();
}

/**
 * HYBRID ASSIGNMENT LOGIC
 */
function assignToSalesAgent($conn)
{
    try {
        // Check for online sales agents (active within last 5 minutes)
        $onlineThreshold = date('Y-m-d H:i:s', strtotime('-5 minutes'));

        $onlineQuery = "SELECT id, last_assigned_inquiry 
                        FROM account 
                        WHERE role = 'sales' 
                        AND is_online = 1 
                        AND last_activity >= ? 
                        ORDER BY 
                            CASE WHEN last_assigned_inquiry IS NULL THEN 0 ELSE 1 END,
                            last_assigned_inquiry ASC, 
                            id ASC";

        $stmt = $conn->prepare($onlineQuery);
        if (!$stmt) {
            return getFallbackSalesAgent($conn);
        }

        $stmt->bind_param("s", $onlineThreshold);
        $stmt->execute();
        $onlineResult = $stmt->get_result();
        $onlineAgents = $onlineResult->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($onlineAgents) === 1) {
            return $onlineAgents[0]['id'];
        }

        if (count($onlineAgents) > 1) {
            return $onlineAgents[0]['id'];
        }

        return getFallbackSalesAgent($conn);

    } catch (Exception $e) {
        return getFallbackSalesAgent($conn);
    }
}

function getFallbackSalesAgent($conn)
{
    try {
        $allSalesQuery = "SELECT id, last_assigned_inquiry 
                          FROM account 
                          WHERE role = 'sales' 
                          ORDER BY 
                            CASE WHEN last_assigned_inquiry IS NULL THEN 0 ELSE 1 END,
                            last_assigned_inquiry ASC, 
                            id ASC 
                          LIMIT 1";

        $result = $conn->query($allSalesQuery);

        if ($result && $result->num_rows > 0) {
            $agent = $result->fetch_assoc();
            return (int) $agent['id'];
        }

        return null;
    } catch (Exception $e) {
        return null;
    }
}
?>