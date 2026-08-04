<?php
// inquiry.php - Handles both display and form submission

// Include reCAPTCHA configuration
include $includes['recaptcha'];

// Handle form submission FIRST before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_submit'])) {
    // Start output buffering to catch any errors
    ob_start();

    session_name("Realivinguser");
    session_start();

    // Include database connection and assignment logic
    include $includes['connection'];
    include $includes['assignement_logic'];

    // Clear any output buffer
    ob_clean();

    // Set JSON header
    header('Content-Type: application/json');

    try {
        // Check if connection exists
        if (!isset($conn)) {
            throw new Exception('Database connection failed.');
        }

        // Get form data and sanitize
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');

        // Validate required fields
        if (empty($name) || empty($email) || empty($phone) || empty($subject) || empty($message)) {
            throw new Exception('Please fill in all required fields.');
        }

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }

        // Validate name (letters, spaces, hyphens, apostrophes only)
        if (!preg_match("/^[a-zA-Z\s\-'\.]+$/", $name)) {
            throw new Exception('Name should only contain letters, spaces, hyphens, and apostrophes.');
        }

        if (strlen($name) < 2 || strlen($name) > 100) {
            throw new Exception('Name must be between 2 and 100 characters.');
        }

        // Validate Philippine phone number (11 digits, starting with 09)
        $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
        if (!preg_match('/^09[0-9]{9}$/', $phoneDigits)) {
            throw new Exception('Please enter a valid 11-digit Philippine mobile number starting with 09.');
        }

        // Validate location (letters, numbers, spaces, commas, hyphens only)
        if (!empty($location) && !preg_match("/^[a-zA-Z0-9\s,\-\.]+$/", $location)) {
            throw new Exception('Location should only contain letters, numbers, spaces, commas, and hyphens.');
        }

        // Validate subject (reasonable length and characters)
        if (strlen($subject) < 3 || strlen($subject) > 200) {
            throw new Exception('Subject must be between 3 and 200 characters.');
        }

        // Validate message (minimum length)
        if (strlen($message) < 1 || strlen($message) > 1000) {
            throw new Exception('Message must be between 1 and 1000 characters.');
        }

        // Validate reCAPTCHA
        $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

        if (empty($recaptchaResponse)) {
            throw new Exception('Please complete the reCAPTCHA verification.');
        }

        // Verify reCAPTCHA with Google
        $verifyURL = RECAPTCHA_VERIFY_URL;
        $verifyData = [
            'secret' => RECAPTCHA_SECRET_KEY,
            'response' => $recaptchaResponse,
            'remoteip' => $_SERVER['REMOTE_ADDR']
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $verifyURL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($verifyData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $verifyResponse = curl_exec($ch);
        curl_close($ch);

        $responseData = json_decode($verifyResponse);

        if (!$responseData->success) {
            throw new Exception('reCAPTCHA verification failed. Please try again.');
        }

        // Get assigned sales agent
        $assignedSalesId = assignToSalesAgent($conn);

        // Insert inquiry
        $stmt = $conn->prepare("
            INSERT INTO contact 
            (name, phone, email, location, subject, message, assigned_to, inquiry_type, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'popup_inquiry', 'pending')
        ");

        if (!$stmt) {
            throw new Exception('Database preparation failed: ' . $conn->error);
        }

        // Normalize phone number to store only digits
        $phoneNormalized = preg_replace('/[^0-9]/', '', $phone);

        $stmt->bind_param(
            "ssssssi",
            $name,
            $phoneNormalized,
            $email,
            $location,
            $subject,
            $message,
            $assignedSalesId
        );

        if ($stmt->execute()) {
            $inquiryId = $conn->insert_id;

            // Update last_assigned_inquiry for the assigned sales agent
            updateAssignedAgent($conn, $assignedSalesId);

            $stmt->close();

            echo json_encode([
                'success' => true,
                'message' => 'Inquiry submitted successfully! We will get back to you soon.',
                'inquiry_id' => $inquiryId
            ]);
        } else {
            throw new Exception('Failed to submit inquiry: ' . $stmt->error);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    if (isset($conn)) {
        $conn->close();
    }

    // End output buffering and send
    ob_end_flush();
    exit(); // CRITICAL: Must exit here to prevent HTML output
}

?>

<!-- HTML Form Display with Tailwind CSS -->
<div id="popupForm" class="hidden fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 sm:p-6">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md max-h-[calc(100dvh-2rem)] sm:max-h-[calc(100dvh-3rem)] flex flex-col overflow-hidden animate-fadeIn relative">
        <div class="shrink-0 bg-white flex items-center justify-between px-5 pt-6 pb-3 sm:px-10 sm:pt-8 sm:pb-4 border-b border-gray-100">
            <h2 class="font-serif text-xl text-amber-950 sm:text-3xl">Send an Inquiry</h2>
            <span id="closeFormBtn" class="text-2xl text-gray-800 cursor-pointer hover:text-black leading-none">&times;</span>
        </div>

        <form id="inquiryFormElement" class="space-y-4 overflow-y-auto min-h-0 px-5 pb-7 pt-4 sm:px-10 sm:pb-10">
            <div class="mb-4">
                <label for="inq_name" class="block font-semibold text-sm mb-1.5">NAME</label>
                <input type="text" id="inq_name" name="name" placeholder="ENTER YOUR NAME" required class="w-full px-3 py-2.5 border border-gray-300 text-xs tracking-widest focus:border-amber-950 focus:outline-none transition-colors" >
            </div>

            <div class="mb-4">
                <label for="inq_email" class="block font-semibold text-sm mb-1.5">EMAIL ADDRESS</label>
                <input type="email" id="inq_email" name="email" placeholder="ENTER YOUR EMAIL" required class="w-full px-3 py-2.5 border border-gray-300 text-xs tracking-widest focus:border-amber-950 focus:outline-none transition-colors">
            </div>

            <div class="mb-4">
                <label for="inq_phone" class="block font-semibold text-sm mb-1.5">PHONE NUMBER</label>
                <input type="tel" id="inq_phone" name="phone" placeholder="E.g. 09123456789" required pattern="09[0-9]{9}" title="Enter 11-digit Philippine mobile number starting with 09" maxlength="11" class="w-full px-3 py-2.5 border border-gray-300 text-xs tracking-widest focus:border-amber-950 focus:outline-none transition-colors">
                <small class="text-gray-600 text-xs block mt-1">Format: 09XXXXXXXXX (11 digits)</small>
            </div>

            <div class="mb-4">
                <label for="inq_location" class="block font-semibold text-sm mb-1.5">LOCATION</label>
                <input type="text" id="inq_location" name="location" placeholder="E.g. 123 Main St, Quezon City" required pattern="[a-zA-Z0-9\s,\-\.]+" title="Location should only contain letters, numbers, spaces, commas, and hyphens" class="w-full px-3 py-2.5 border border-gray-300 text-xs tracking-widest focus:border-amber-950 focus:outline-none transition-colors">
            </div>

            <div class="mb-4">
                <label for="inq_subject" class="block font-semibold text-sm mb-1.5">SUBJECT</label>
                <input type="text" id="inq_subject" name="subject" placeholder="SUBJECT TITLE" required class="w-full px-3 py-2.5 border border-gray-300 text-xs tracking-widest focus:border-amber-950 focus:outline-none transition-colors">
            </div>

            <div class="mb-4">
                <label for="inq_message" class="block font-semibold text-sm mb-1.5">MESSAGE</label>
                <textarea id="inq_message" name="message" rows="4" placeholder="TYPE YOUR MESSAGE HERE" required class="w-full px-3 py-2.5 border border-gray-300 text-xs tracking-widest focus:border-amber-950 focus:outline-none transition-colors"></textarea>
            </div>

            <!-- reCAPTCHA -->
            <div class="flex justify-center mb-2">
                <div class="w-[228px] h-[78px] overflow-hidden sm:w-[304px] sm:h-[78px] sm:overflow-visible">
                    <div id="inquiry-recaptcha" class="g-recaptcha origin-top-left scale-75 sm:scale-100"
                        data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"
                        data-callback="inquiryRecaptchaCallback"
                        data-expired-callback="inquiryRecaptchaExpiredCallback"
                        data-error-callback="inquiryRecaptchaErrorCallback">
                    </div>
                </div>
            </div>

            <button type="submit" class="submit-btn w-full py-3 bg-amber-950 text-white border-none tracking-widest text-sm cursor-pointer transition-colors hover:bg-amber-900 disabled:bg-gray-300 disabled:cursor-not-allowed">SUBMIT INQUIRY</button>
        </form>
    </div>
</div>
<!-- Add custom animation to your Tailwind CSS config -->
<style>
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: scale(0.95);
        }
        to {
            opacity: 1;
            transform: scale(1);
        }
    }
    
    .animate-fadeIn {
        animation: fadeIn 0.3s ease-out;
    }
</style>

<!-- Thank You Modal for Inquiry -->
<div id="inquiryThankYouModal" class="inquiry-modal fixed inset-0 z-[10000] items-center justify-center bg-black/50 backdrop-blur-sm p-5" style="display:none;">
    <div class="inquiry-modal-content bg-white rounded-xl shadow-2xl w-full max-w-sm p-8 text-center relative">
        <span class="inquiry-close absolute top-4 right-5 text-2xl text-gray-800 cursor-pointer hover:text-black">&times;</span>
        <h2 class="font-serif text-2xl text-amber-950 mb-3">Thank You!</h2>
        <p class="text-gray-600 text-sm">Thank you for your inquiry.<br>We will get back to you soon.</p>
    </div>
</div>

<!-- Error Modal -->
<div id="inquiryErrorModal" class="inquiry-modal fixed inset-0 z-[10000] items-center justify-center bg-black/50 backdrop-blur-sm p-5" style="display:none;">
    <div class="inquiry-modal-content bg-white rounded-xl shadow-2xl w-full max-w-sm p-8 text-center relative">
        <span class="inquiry-error-close absolute top-4 right-5 text-2xl text-gray-800 cursor-pointer hover:text-black">&times;</span>
        <h2 class="font-serif text-2xl text-red-700 mb-3">Error</h2>
        <p id="errorMessage" class="text-gray-600 text-sm"></p>
    </div>
</div>

<!-- reCAPTCHA Script -->
<script src="<?php echo RECAPTCHA_SCRIPT_URL; ?>" async defer></script>
<script>window.BASE_URL = "<?= BASE_URL ?>";</script>
<script src="<?= CLIENT_ASSET ?>/inquiry_form/inquiry.js" defer></script>

