<?php
// get_inquiry_counts.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// ONLY include connection - DO NOT include mainbody.php or checkrole.php
include $includes ['connection'];

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['role'] ?? '';

try {
    // Get pending inquiry counts
    // Appointments
    $apt_query = ($admin_role === 'superadmin') 
        ? "SELECT COUNT(*) as count FROM appointments WHERE status='pending'" 
        : "SELECT COUNT(*) as count FROM appointments WHERE status='pending' AND assigned_to = $admin_id";
    $apt_result = $conn->query($apt_query);
    $pending_appointments = $apt_result ? $apt_result->fetch_assoc()['count'] : 0;

    // Concept Inquiries
    $concept_query = ($admin_role === 'superadmin') 
        ? "SELECT COUNT(*) as count FROM concept_inquiries WHERE status='pending'" 
        : "SELECT COUNT(*) as count FROM concept_inquiries WHERE status='pending' AND assigned_to = $admin_id";
    $concept_result = $conn->query($concept_query);
    $pending_concepts = $concept_result ? $concept_result->fetch_assoc()['count'] : 0;

    // Contact Inquiries
    $contact_query = ($admin_role === 'superadmin') 
        ? "SELECT COUNT(*) as count FROM contact WHERE status='pending'" 
        : "SELECT COUNT(*) as count FROM contact WHERE status='pending' AND assigned_to = $admin_id";
    $contact_result = $conn->query($contact_query);
    $pending_contacts = $contact_result ? $contact_result->fetch_assoc()['count'] : 0;

    // Project Inquiries
    $project_query = ($admin_role === 'superadmin') 
        ? "SELECT COUNT(*) as count FROM project_inquiries WHERE status='pending'" 
        : "SELECT COUNT(*) as count FROM project_inquiries WHERE status='pending' AND assigned_to = $admin_id";
    $project_result = $conn->query($project_query);
    $pending_projects = $project_result ? $project_result->fetch_assoc()['count'] : 0;

    // Set header and return JSON ONLY
    header('Content-Type: application/json');
    echo json_encode([
        'appointments' => (int)$pending_appointments,
        'concepts' => (int)$pending_concepts,
        'contacts' => (int)$pending_contacts,
        'projects' => (int)$pending_projects
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}

$conn->close();
exit(); // Important: stop execution here
?>