<?php
// get_booked_dates.php
session_name("Realivinguser");
session_start();
include "../../connection/connection.php";

header('Content-Type: application/json');

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');

try {
    // Get booking counts for each date in the specified month
    $stmt = $conn->prepare("
        SELECT 
            preferred_date as date, 
            COUNT(*) as count 
        FROM appointments 
        WHERE YEAR(preferred_date) = ? 
        AND MONTH(preferred_date) = ? 
        AND status != 'cancelled'
        GROUP BY preferred_date
    ");
    
    $stmt->bind_param("ii", $year, $month);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bookedDates = [];
    while ($row = $result->fetch_assoc()) {
        $bookedDates[] = [
            'date' => $row['date'],
            'count' => (int)$row['count']
        ];
    }
    
    $stmt->close();

    // Fetch holidays para sa month na ito
    $hstmt = $conn->prepare("SELECT holiday_date, holiday_name FROM holidays WHERE YEAR(holiday_date) = ? AND MONTH(holiday_date) = ?");
    $hstmt->bind_param("ii", $year, $month);
    $hstmt->execute();
    $hres = $hstmt->get_result();
    $holidayDates = [];
    while ($row = $hres->fetch_assoc()) {
        $holidayDates[] = [
            'date' => $row['holiday_date'],
            'name' => $row['holiday_name']
        ];
    }
    $hstmt->close();

    echo json_encode([
        'success' => true,
        'bookedDates' => $bookedDates,
        'holidayDates' => $holidayDates
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'bookedDates' => []
    ]);
}

$conn->close();
?>