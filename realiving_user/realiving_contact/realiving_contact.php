<?php
//contact.php
session_name("Realivinguser");
session_start();
include $includes['connection'];

// Check if form was just submitted
$show_modal = false;
if (isset($_SESSION['contact_form_submitted']) && $_SESSION['contact_form_submitted'] === true) {
    $show_modal = true;
    unset($_SESSION['contact_form_submitted']);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Include reCAPTCHA config and assignment logic
    require_once $includes["recaptcha"];
    include $includes["assignement_logic"];

    // Get data from form and sanitize
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Validation errors array
    $errors = [];

    // Validate required fields
    if (empty($name) || empty($email) || empty($phone) || empty($subject) || empty($message)) {
        $errors[] = 'Please fill in all required fields.';
    }

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    // Validate name (letters, spaces, hyphens, apostrophes only)
    if (!preg_match("/^[a-zA-Z\s\-'\.]+$/", $name)) {
        $errors[] = 'Name should only contain letters, spaces, hyphens, and apostrophes.';
    }

    if (strlen($name) < 2 || strlen($name) > 100) {
        $errors[] = 'Name must be between 2 and 100 characters.';
    }

    // Validate Philippine phone number (11 digits, starting with 09)
    $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
    if (!preg_match('/^09[0-9]{9}$/', $phoneDigits)) {
        $errors[] = 'Please enter a valid 11-digit Philippine mobile number starting with 09.';
    }

    // Validate location (letters, numbers, spaces, commas, hyphens only)
    if (!empty($location) && !preg_match("/^[a-zA-Z0-9\s,\-\.]+$/", $location)) {
        $errors[] = 'Location should only contain letters, numbers, spaces, commas, and hyphens.';
    }

    // Validate subject
    if (strlen($subject) < 3 || strlen($subject) > 200) {
        $errors[] = 'Subject must be between 3 and 200 characters.';
    }

    // Validate message
    if (strlen($message) < 1 || strlen($message) > 1000) {
        $errors[] = 'Message must be between 1 and 1000 characters.';
    }

    // Validate reCAPTCHA
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

    if (empty($recaptchaResponse)) {
        $errors[] = 'Please complete the reCAPTCHA verification.';
    } else {
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
            $errors[] = 'reCAPTCHA verification failed. Please try again.';
        }
    }

    // If no errors, proceed with insertion
    if (empty($errors)) {
        // Normalize phone number
        $phoneNormalized = preg_replace('/[^0-9]/', '', $phone);

        // Get assigned sales agent
        $assignedSalesId = assignToSalesAgent($conn);

        // Insert query with assignment
        $sql = "INSERT INTO contact (name, phone, email, location, subject, message, assigned_to, inquiry_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'contact_page', 'pending')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssi", $name, $phoneNormalized, $email, $location, $subject, $message, $assignedSalesId);

        if ($stmt->execute()) {
            // Update last_assigned_inquiry for the assigned sales agent
            updateAssignedAgent($conn, $assignedSalesId);

            // Set success flag and redirect
            $_SESSION['contact_form_submitted'] = true;
            header("Location: contact");
            exit();
        } else {
            $errors[] = "Database error: " . $stmt->error;
        }

        $stmt->close();
    }

    // Store errors in session
    if (!empty($errors)) {
        $_SESSION['contact_form_errors'] = $errors;
    }
}

// Get errors if any
$form_errors = $_SESSION['contact_form_errors'] ?? [];
unset($_SESSION['contact_form_errors']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - Realiving Design Center</title>

    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,500;0,600;0,700;1,400;1,600&display=swap"
        rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />

    <link rel="stylesheet" href="<?= BASE_ASSET ?>assets/css/output.css">

    <style>
        /* Mechanical modal-lock rule — the JS toggles this literal class
           name directly on <html>/<body>, so it needs a real CSS rule
           behind it (can't be a dynamically-swapped Tailwind utility). */
        html.modal-open,
        body.modal-open {
            overflow: hidden !important;
            position: fixed;
            width: 100%;
            height: 100vh;
        }
    </style>
</head>

<body class="contact-page no-hero bg-[#faf8f4] text-[#241205]" style="font-family:'Montserrat',sans-serif;">

    <?php include $includes["header"]; ?>

    <div class="main-content">

        <!-- ═══════════════════════════════
             MASTHEAD
        ═══════════════════════════════ -->
        <section class="relative bg-[#2f1200] overflow-hidden">
            <div class="absolute inset-0 bg-cover bg-center opacity-20"
                style="background-image:url('<?= CLIENT_ASSET ?>/images/background-image.jpg');"></div>
            <div class="absolute inset-0 bg-gradient-to-b from-[#2f1200]/60 via-[#2f1200]/85 to-[#2f1200]"></div>

            <div class="relative max-w-5xl mx-auto px-6 pt-24 pb-20 text-center">
                <div class="inline-flex items-center gap-3 text-[#c4905c] text-[11px] font-semibold tracking-[3px] uppercase mb-6">
                    <span class="w-6 h-px bg-[#c4905c]"></span>
                    Get in touch
                    <span class="w-6 h-px bg-[#c4905c]"></span>
                </div>
                <h1 class="font-['Crimson_Pro'] italic font-semibold text-white text-4xl md:text-6xl leading-tight">
                    Contact Us
                </h1>
                <p class="mt-4 text-white/70 text-sm md:text-base max-w-xl mx-auto">
                    Looking to upgrade your space with modular cabinetry? We'd love to hear your ideas.
                </p>
            </div>
        </section>

        <!-- ═══════════════════════════════
             CONTACT INFO CARDS — overlaps the masthead slightly
        ═══════════════════════════════ -->
        <section class="max-w-6xl mx-auto px-6 -mt-10 relative z-10 mb-10">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">

                <div class="bg-white shadow-[0_10px_30px_rgba(47,18,0,0.1)] p-5 flex flex-col gap-3">
                    <span class="w-10 h-10 rounded-full bg-[#faf3e9] flex items-center justify-center">
                        <i class="ri-phone-line text-[#c4905c] text-lg"></i>
                    </span>
                    <div>
                        <span class="block text-[10px] font-bold tracking-[1.5px] uppercase text-[#a3907a] mb-1">Call us</span>
                        <p class="text-[13px] font-semibold text-[#2f1200]">0985 124 5929</p>
                    </div>
                </div>

                <div class="bg-white shadow-[0_10px_30px_rgba(47,18,0,0.1)] p-5 flex flex-col gap-3">
                    <span class="w-10 h-10 rounded-full bg-[#faf3e9] flex items-center justify-center">
                        <i class="ri-mail-line text-[#c4905c] text-lg"></i>
                    </span>
                    <div>
                        <span class="block text-[10px] font-bold tracking-[1.5px] uppercase text-[#a3907a] mb-1">Email us</span>
                        <p class="text-[13px] font-semibold text-[#2f1200] break-words">realivingdesign.corp@gmail.com</p>
                    </div>
                </div>

                <div class="bg-white shadow-[0_10px_30px_rgba(47,18,0,0.1)] p-5 flex flex-col gap-3">
                    <span class="w-10 h-10 rounded-full bg-[#faf3e9] flex items-center justify-center">
                        <i class="ri-map-pin-line text-[#c4905c] text-lg"></i>
                    </span>
                    <div>
                        <span class="block text-[10px] font-bold tracking-[1.5px] uppercase text-[#a3907a] mb-1">Visit us</span>
                        <p class="text-[13px] font-semibold text-[#2f1200]">MC Premier — EDSA Balintawak, Quezon City</p>
                    </div>
                </div>

                <div class="bg-white shadow-[0_10px_30px_rgba(47,18,0,0.1)] p-5 flex flex-col gap-3">
                    <span class="w-10 h-10 rounded-full bg-[#faf3e9] flex items-center justify-center">
                        <i class="ri-time-line text-[#c4905c] text-lg"></i>
                    </span>
                    <div>
                        <span class="block text-[10px] font-bold tracking-[1.5px] uppercase text-[#a3907a] mb-1">Office hours</span>
                        <p class="text-[13px] font-semibold text-[#2f1200]">Mon–Fri 7AM–5PM<br>Sat 7AM–12PM</p>
                    </div>
                </div>

            </div>
        </section>

        <!-- ═══════════════════════════════
             CONTACT FORM SECTION
        ═══════════════════════════════ -->
        <section class="max-w-6xl mx-auto px-6 pb-20">
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-12 lg:gap-16">

                <!-- Left — project description (sticky on desktop) -->
                <div class="lg:col-span-2">
                    <div class="lg:sticky lg:top-24">
                        <span class="inline-block text-[10px] font-bold tracking-[3px] uppercase text-[#c4905c] mb-4">✦ Let's build together</span>
                        <h2 class="font-['Crimson_Pro'] font-semibold text-[#2f1200] text-2xl sm:text-3xl leading-snug mb-5">
                            Tell us about your modular furniture project
                        </h2>
                        <p class="text-[14px] text-[#6b5c4d] leading-relaxed mb-4">
                            Looking to upgrade your space with modular cabinetry? We'd love to hear your ideas!
                        </p>
                        <p class="text-[14px] text-[#6b5c4d] leading-relaxed mb-8">
                            Or if you prefer, just fill out the form, and we'll get back to you as soon as possible.
                        </p>

                        <span class="block text-[11px] font-bold tracking-[1.5px] uppercase text-[#2f1200] mb-3">Talk with us</span>
                        <div class="flex gap-3">
                            <img src="<?= CLIENT_ASSET ?>/images/icon/wc.png" alt="WeChat"
                                class="w-9 h-9 opacity-80 hover:opacity-100 hover:scale-110 transition-all cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- Right — form -->
                <div class="lg:col-span-3">
                    <div class="bg-[#faf3e9] p-6 sm:p-10">

                        <?php if (!empty($form_errors)): ?>
                            <div class="bg-[#fdecec] border border-[#f3b8b8] text-[#c33] px-5 py-4 mb-6">
                                <ul class="list-disc pl-5 space-y-1">
                                    <?php foreach ($form_errors as $error): ?>
                                        <li class="text-[13px]"><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form action="<?= BASE_URL ?>contact" method="POST" id="contactForm" class="flex flex-col gap-5">

                            <div class="flex flex-col gap-1.5">
                                <label for="name" class="text-[11px] font-bold uppercase tracking-[1px] text-[#2f1200]">Name</label>
                                <input type="text" id="name" name="name" placeholder="E.g. Juan Dela Cruz" required
                                    pattern="[a-zA-Z\s\-'\.]+"
                                    title="Name should only contain letters, spaces, hyphens, and apostrophes" minlength="2"
                                    maxlength="100"
                                    class="w-full px-4 py-2.5 border border-[#d4b896] bg-white text-[14px] text-[#2f1200] placeholder:text-gray-400 focus:outline-none focus:border-[#2f1200] focus:ring-2 focus:ring-[#2f1200]/10 transition-colors">
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label for="location" class="text-[11px] font-bold uppercase tracking-[1px] text-[#2f1200]">Location</label>
                                <input type="text" id="location" name="location" placeholder="E.g. 123 Main St, Quezon City"
                                    pattern="[a-zA-Z0-9\s,\-\.]+"
                                    title="Location should only contain letters, numbers, spaces, commas, and hyphens"
                                    class="w-full px-4 py-2.5 border border-[#d4b896] bg-white text-[14px] text-[#2f1200] placeholder:text-gray-400 focus:outline-none focus:border-[#2f1200] focus:ring-2 focus:ring-[#2f1200]/10 transition-colors">
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label for="email" class="text-[11px] font-bold uppercase tracking-[1px] text-[#2f1200]">Email</label>
                                <input type="email" id="email" name="email" placeholder="E.g. juan.delacruz@gmail.com" required
                                    class="w-full px-4 py-2.5 border border-[#d4b896] bg-white text-[14px] text-[#2f1200] placeholder:text-gray-400 focus:outline-none focus:border-[#2f1200] focus:ring-2 focus:ring-[#2f1200]/10 transition-colors">
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label for="phone" class="text-[11px] font-bold uppercase tracking-[1px] text-[#2f1200]">Phone</label>
                                <input type="tel" id="phone" name="phone" placeholder="E.g. 09123456789" required
                                    pattern="09[0-9]{9}" title="Enter 11-digit Philippine mobile number starting with 09"
                                    maxlength="11"
                                    class="w-full px-4 py-2.5 border border-[#d4b896] bg-white text-[14px] text-[#2f1200] placeholder:text-gray-400 focus:outline-none focus:border-[#2f1200] focus:ring-2 focus:ring-[#2f1200]/10 transition-colors">
                                <small class="text-[11px] text-gray-500 italic">Format: 09XXXXXXXXX (11 digits)</small>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label for="subject" class="text-[11px] font-bold uppercase tracking-[1px] text-[#2f1200]">Subject</label>
                                <input type="text" id="subject" name="subject" placeholder="Subject" required minlength="3"
                                    maxlength="200"
                                    class="w-full px-4 py-2.5 border border-[#d4b896] bg-white text-[14px] text-[#2f1200] placeholder:text-gray-400 focus:outline-none focus:border-[#2f1200] focus:ring-2 focus:ring-[#2f1200]/10 transition-colors">
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label for="message" class="text-[11px] font-bold uppercase tracking-[1px] text-[#2f1200]">Message</label>
                                <textarea id="message" name="message" rows="6" placeholder="Type your message here..." required
                                    minlength="10" maxlength="1000"
                                    class="w-full px-4 py-2.5 border border-[#d4b896] bg-white text-[14px] text-[#2f1200] placeholder:text-gray-400 focus:outline-none focus:border-[#2f1200] focus:ring-2 focus:ring-[#2f1200]/10 transition-colors resize-y min-h-[120px]"></textarea>
                            </div>

                            <!-- reCAPTCHA -->
                            <div>
                                <div id="contact-recaptcha" class="g-recaptcha origin-top-left scale-[0.93] sm:scale-100"
                                    data-sitekey="<?php require_once __DIR__ . '/../config/recaptcha_config.php';
                                    echo RECAPTCHA_SITE_KEY; ?>" data-callback="contactRecaptchaCallback"
                                    data-expired-callback="contactRecaptchaExpiredCallback"
                                    data-error-callback="contactRecaptchaErrorCallback"></div>
                            </div>

                            <div class="mt-1">
                                <button type="submit"
                                    class="w-full bg-[#2f1200] text-white font-bold text-[12px] tracking-[2px] uppercase px-8 py-4 rounded-full transition-all duration-300 hover:bg-[#c4905c] hover:-translate-y-0.5 hover:shadow-lg">
                                    Submit
                                </button>
                            </div>

                        </form>
                    </div>
                </div>

            </div>
        </section>

        <!-- ═══════════════════════════════
             MAP SECTION
        ═══════════════════════════════ -->
        <section class="bg-[#faf8f4] pb-20 px-6">
            <div class="max-w-6xl mx-auto">
                <div class="text-center mb-8">
                    <span class="inline-block text-[10px] font-bold tracking-[3px] uppercase text-[#c4905c] mb-3">✦ Find us</span>
                    <h2 class="font-['Crimson_Pro'] font-semibold text-[#2f1200] text-2xl sm:text-3xl">Site Location</h2>
                </div>
                <div class="overflow-hidden shadow-[0_10px_30px_rgba(47,18,0,0.12)] h-[280px] sm:h-[380px] lg:h-[480px]">
                    <iframe id="mapIframe"
                        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d1929.989715650841!2d121.00304560449919!3d14.6571087549821!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3397b78911561a43%3A0xb374d2c3a7ccdf7d!2sRealiving%20Design%20Center%20Corp.!5e0!3m2!1sen!2sph!4v1765941856567!5m2!1sen!2sph"
                        width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
            </div>
        </section>

        <?php
    include $includes["footer"];
    ?>

    </div>

    <!-- Thank You Modal -->
    <div id="thankYouModal" class="hidden fixed inset-0 z-[9999] items-center justify-center p-5" style="background-color:rgba(0,0,0,0.5);">
        <div class="relative bg-white rounded-2xl w-full max-w-[400px] p-9 text-center shadow-[0_8px_30px_rgba(0,0,0,0.2)]" style="font-family:'Montserrat',sans-serif;">
            <span class="close absolute top-3 right-4 text-2xl font-bold text-gray-400 hover:text-black cursor-pointer">&times;</span>
            <h2 class="font-['Crimson_Pro'] italic font-semibold text-[#2f1200] text-3xl mb-2">Thank You!</h2>
            <p class="text-[14px] text-gray-600 leading-relaxed">Thank you for reaching out to us.<br>Check your email for our response.</p>
        </div>
    </div>

    

    <!-- reCAPTCHA Script -->
    <script src="<?php echo RECAPTCHA_SCRIPT_URL; ?>" async defer></script>

    <script>
        document.querySelectorAll('.faq-item').forEach(item => {
            const question = item.querySelector('p');
            const icon = item.querySelector('.icon');

            item.addEventListener('click', () => {
                const isOpening = !item.classList.contains('active');
                item.classList.toggle('active');

                if (isOpening) {
                    icon.style.transform = 'rotate(45deg)';
                } else {
                    icon.style.transform = 'rotate(0deg)';
                }
            });
        });

        // Client-side Validation
        document.getElementById('contactForm')?.addEventListener('submit', function (e) {
            // Reset any previous error states
            const inputs = this.querySelectorAll('input, textarea');
            inputs.forEach(input => input.classList.remove('error'));

            const name = document.getElementById('name').value.trim();
            const phone = document.getElementById('phone').value.trim();
            const email = document.getElementById('email').value.trim();
            const subject = document.getElementById('subject').value.trim();
            const message = document.getElementById('message').value.trim();

            // Validate name
            if (!/^[a-zA-Z\s\-'\.]+$/.test(name)) {
                alert('Name should only contain letters, spaces, hyphens, and apostrophes.');
                e.preventDefault();
                return false;
            }

            // Validate Philippine phone number
            const phoneDigits = phone.replace(/[^0-9]/g, '');
            if (!/^09[0-9]{9}$/.test(phoneDigits)) {
                alert('Please enter a valid 11-digit Philippine mobile number starting with 09 (e.g., 09123456789).');
                e.preventDefault();
                return false;
            }

            // Validate location (allow numbers)
            const location = document.getElementById('location').value.trim();
            if (location && !/^[a-zA-Z0-9\s,\-\.]+$/.test(location)) {
                alert('Location should only contain letters, numbers, spaces, commas, and hyphens.');
                e.preventDefault();
                return false;
            }

            // Check reCAPTCHA
            if (typeof grecaptcha !== 'undefined') {
                const recaptchaResponse = grecaptcha.getResponse();
                if (!recaptchaResponse || recaptchaResponse.length === 0) {
                    alert('Please complete the reCAPTCHA verification by checking the box.');
                    e.preventDefault();
                    return false;
                }
            } else {
                alert('reCAPTCHA is not loaded. Please refresh the page and try again.');
                e.preventDefault();
                return false;
            }

            return true;
        });

        // reCAPTCHA callback functions for contact form
        window.contactRecaptchaCallback = function () {
            console.log("✅ Contact form reCAPTCHA verified");
        };

        window.contactRecaptchaExpiredCallback = function () {
            console.warn("⚠️ Contact form reCAPTCHA expired");
            alert('reCAPTCHA verification expired. Please verify again.');
        };

        window.contactRecaptchaErrorCallback = function () {
            console.error("❌ Contact form reCAPTCHA error");
            alert('reCAPTCHA error. Please refresh and try again.');
        };

        // Thank You Modal Script
        <?php if ($show_modal): ?>
                (function () {
                    // Modal management with proper cleanup
                    var modalClickHandler = null;
                    var modalKeyHandler = null;

                    window.addEventListener('load', function () {
                        var modal = document.getElementById('thankYouModal');
                        if (!modal) return;

                        // Scroll to top and show modal
                        window.scrollTo(0, 0);
                        document.documentElement.classList.add('modal-open');
                        document.body.classList.add('modal-open');
                        modal.style.display = 'flex';

                        // Function to close modal properly
                        function closeModal() {
                            modal.style.display = 'none';
                            document.documentElement.classList.remove('modal-open');
                            document.body.classList.remove('modal-open');

                            // Clean up event listeners
                            if (modalClickHandler) {
                                window.removeEventListener('click', modalClickHandler);
                                modalClickHandler = null;
                            }
                            if (modalKeyHandler) {
                                document.removeEventListener('keydown', modalKeyHandler);
                                modalKeyHandler = null;
                            }

                            // Reset reCAPTCHA if available
                            if (typeof grecaptcha !== 'undefined' && grecaptcha.reset) {
                                try {
                                    grecaptcha.reset();
                                    console.log("✅ reCAPTCHA reset after modal close");
                                } catch (e) {
                                    console.warn("⚠️ Could not reset reCAPTCHA:", e);
                                }
                            }
                        }

                        // Close button handler
                        var closeBtn = modal.querySelector('.close');
                        if (closeBtn) {
                            closeBtn.onclick = closeModal;
                        }

                        // Click outside handler
                        modalClickHandler = function (event) {
                            if (event.target === modal) {
                                closeModal();
                            }
                        };
                        window.addEventListener('click', modalClickHandler);

                        // Escape key handler
                        modalKeyHandler = function (e) {
                            if (e.key === 'Escape' && modal.style.display === 'flex') {
                                closeModal();
                            }
                        };
                        document.addEventListener('keydown', modalKeyHandler);
                    });
                })();
        <?php endif; ?>

        // Lazy load map iframe to prevent unnecessary loading
        document.addEventListener('DOMContentLoaded', function () {
            const mapIframe = document.getElementById('mapIframe');
            if (!mapIframe) return;

            const observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        // Map is visible, ensure it's loaded
                        if (!mapIframe.src) {
                            mapIframe.src = mapIframe.dataset.src;
                        }
                        observer.unobserve(mapIframe);
                    }
                });
            }, {
                rootMargin: '100px' // Load when within 100px of viewport
            });

            observer.observe(mapIframe);
        });
    </script>
</body>

</html>