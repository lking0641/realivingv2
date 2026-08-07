<?php
// concept_process.php
session_name("Realivinguser");
session_start();
ob_start();

// Bootstrap includes (same as your other files)

include $includes['connection'];
include $includes['assignement_logic'];
require_once $includes['recaptcha'];

$inquiry_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['concept_inquiry_submit'])) {

    $concept_style = trim(htmlspecialchars($_POST['concept_style'] ?? ''));
    $concept_id = intval($_POST['concept_id'] ?? 0);
    $project_type = trim(htmlspecialchars($_POST['project_type'] ?? ''));
    $category_name = trim(htmlspecialchars($_POST['category_name'] ?? ''));
    $name = trim(htmlspecialchars($_POST['name'] ?? ''));
    $email = trim(htmlspecialchars($_POST['email'] ?? ''));
    $phone = trim(htmlspecialchars($_POST['phone'] ?? ''));
    $address = trim(htmlspecialchars($_POST['address'] ?? ''));
    $know_more_about = trim(htmlspecialchars($_POST['know_more_about'] ?? ''));
    $additional_info = trim(htmlspecialchars($_POST['additional_info'] ?? ''));
    $terms_accepted = isset($_POST['terms_accepted']);

    // Validation
    if (empty($project_type))
        $inquiry_errors['project_type'] = 'Project type is required';

    if (empty($name)) {
        $inquiry_errors['name'] = 'Name is required';
    } elseif (!preg_match("/^[a-zA-Z\s\-'\.]+$/", $name)) {
        $inquiry_errors['name'] = 'Name should only contain letters, spaces, hyphens, and apostrophes.';
    } elseif (strlen($name) < 2 || strlen($name) > 100) {
        $inquiry_errors['name'] = 'Name must be between 2 and 100 characters.';
    }

    if (empty($email)) {
        $inquiry_errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $inquiry_errors['email'] = 'Invalid email format';
    }

    if (empty($phone)) {
        $inquiry_errors['phone'] = 'Phone number is required';
    } else {
        $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
        if (!preg_match('/^09[0-9]{9}$/', $phoneDigits)) {
            $inquiry_errors['phone'] = 'Please enter a valid 11-digit Philippine mobile number starting with 09.';
        }
    }

    if (empty($address)) {
        $inquiry_errors['address'] = 'Address is required';
    } elseif (!preg_match("/^[a-zA-Z0-9\s,\-\.]+$/", $address)) {
        $inquiry_errors['address'] = 'Address should only contain letters, numbers, spaces, commas, hyphens, and periods.';
    }

    if (empty($know_more_about))
        $inquiry_errors['know_more_about'] = 'Please select what you want to know more about';
    if ($know_more_about === 'Other' && empty($additional_info))
        $inquiry_errors['additional_info'] = 'Please specify what you want to know more about';
    if (!$terms_accepted)
        $inquiry_errors['terms'] = 'You must accept the terms and conditions';

    // Turnstile
    $turnstileResponse = $_POST['cf-turnstile-response'] ?? '';
    if (empty($turnstileResponse)) {
        $inquiry_errors['recaptcha'] = 'Please complete the verification check.';
    } else {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, TURNSTILE_VERIFY_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'secret' => TURNSTILE_SECRET_KEY,
            'response' => $turnstileResponse,
            'remoteip' => $_SERVER['REMOTE_ADDR']
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $responseData = json_decode(curl_exec($ch));
        curl_close($ch);
        if (!$responseData->success) {
            $inquiry_errors['recaptcha'] = 'Verification failed. Please try again.';
        }
    }

    if (empty($inquiry_errors)) {
        $assignedSalesId = assignToSalesAgent($conn);

        if ($assignedSalesId === null) {
            // No agents — store error in session and redirect back
            $_SESSION['concept_errors'] = ['submit' => 'No sales agents available. Please try again later.'];
            header('Location: ' . BASE_URL . 'concepts');
            exit();
        }

        $insert_sql = "INSERT INTO concept_inquiries 
                        (concept_id, concept_style, project_type, name, email, phone, address, know_more_about, additional_info, assigned_to, inquiry_type, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'concept_page', 'pending', NOW())";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("issssssssi", $concept_id, $concept_style, $project_type, $name, $email, $phone, $address, $know_more_about, $additional_info, $assignedSalesId);

        if ($insert_stmt->execute()) {
            updateAssignedAgent($conn, $assignedSalesId);
            $_SESSION['concept_success'] = true;
            ob_end_clean();
            header('Location: ' . BASE_URL . 'concepts');
            exit();
        } else {
            $_SESSION['concept_errors'] = ['submit' => 'Failed to submit inquiry. Please try again.'];
            ob_end_clean();
            header('Location: ' . BASE_URL . 'concepts');
            exit();
        }
        $insert_stmt->close();
    } else {
        // Validation failed — store errors and old input in session, redirect back
        $_SESSION['concept_errors'] = $inquiry_errors;
        $_SESSION['concept_old_input'] = $_POST;
        ob_end_clean();
        header('Location: ' . BASE_URL . 'concepts');
        exit();
    }
} else {
    // Direct access — redirect home
    header('Location: ' . BASE_URL . 'concepts');
    exit();
}