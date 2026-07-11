<?php
//realiving_project_details.php — Individual Project Detail Page (Tailwind version)
session_name("Realivinguser");
session_start();
include $includes['connection'];

if (isset($_GET['id'])) {
    $project_id = $_GET['id'];

    $sql = "SELECT * FROM project WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $project = $result->fetch_assoc();

        $main_image = $project['main_image'];
        $image1 = $project['image1'];
        $image2 = $project['image2'];

        $main_image = '/' . ltrim($main_image, './');
        $image1 = '/' . ltrim($image1, './');
        $image2 = '/' . ltrim($image2, './');
    } else {
        echo "<p>Project not found.</p>";
        exit;
    }

    $other_projects_sql = "SELECT * FROM project WHERE id != ? AND category = ? ORDER BY RAND() LIMIT 4";
    $other_projects_stmt = $conn->prepare($other_projects_sql);
    $other_projects_stmt->bind_param("is", $project_id, $project['category']);
    $other_projects_stmt->execute();
    $other_projects_result = $other_projects_stmt->get_result();

    $other_projects = [];
    while ($row = $other_projects_result->fetch_assoc())
        $other_projects[] = $row;

    if (count($other_projects) < 4) {
        $remaining = 4 - count($other_projects);
        $random_sql = "SELECT * FROM project WHERE id != ? ORDER BY RAND() LIMIT ?";
        $random_stmt = $conn->prepare($random_sql);
        $random_stmt->bind_param("ii", $project_id, $remaining);
        $random_stmt->execute();
        $random_result = $random_stmt->get_result();
        while ($row = $random_result->fetch_assoc())
            $other_projects[] = $row;
        $random_stmt->close();
    }

    $locations_query = "
        SELECT pl.*, 
               GROUP_CONCAT(pli.image_path ORDER BY pli.id) as images
        FROM project_locations pl
        LEFT JOIN project_location_images pli ON pl.id = pli.location_id
        WHERE pl.project_id = ?
        GROUP BY pl.id
        ORDER BY pl.id ASC
    ";
    $locations_stmt = $conn->prepare($locations_query);
    $locations_stmt->bind_param("i", $project_id);
    $locations_stmt->execute();
    $locations_result = $locations_stmt->get_result();

    $project_locations = [];
    while ($loc_row = $locations_result->fetch_assoc()) {
        $images_array = !empty($loc_row['images']) ? explode(',', $loc_row['images']) : [];
        $fixed_images = [];
        foreach ($images_array as $img_path) {
            $fixed_images[] = '/' . ltrim($img_path, './');
        }
        $project_locations[] = [
            'id' => $loc_row['id'],
            'name' => $loc_row['location_name'],
            'images' => $fixed_images
        ];
    }
    $locations_stmt->close();
    $stmt->close();
    $other_projects_stmt->close();
} else {
    echo "<p>No project selected.</p>";
    exit;
}

$top_products_query = "SELECT 
    i.item_id, i.item_code, i.item_name, i.item_image_path, i.item_material, i.item_color,
    dl.dimension_label_name
FROM items i
LEFT JOIN dimension_label dl ON i.dimension_label_fk = dl.dimension_label_id
WHERE i.is_top_product = 1 AND i.is_hidden = 0
ORDER BY i.item_id DESC LIMIT 8";

$top_products_result = $conn->query($top_products_query);
?>

<?php
require_once $includes['recaptcha'];

$contact_errors = [];
$contact_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    include $includes['assignement_logic'];

    $contact_name = trim(htmlspecialchars($_POST['name'] ?? ''));
    $contact_phone = trim(htmlspecialchars($_POST['phone'] ?? ''));
    $contact_email = trim(htmlspecialchars($_POST['email'] ?? ''));
    $contact_location = trim(htmlspecialchars($_POST['location'] ?? ''));
    $contact_project_id = intval($_POST['project_id'] ?? 0);

    if (empty($contact_name)) {
        $contact_errors['name'] = 'Name is required';
    } elseif (!preg_match("/^[a-zA-Z\s\-'\.]+$/", $contact_name)) {
        $contact_errors['name'] = 'Name should only contain letters, spaces, hyphens, and apostrophes.';
    } elseif (strlen($contact_name) < 2 || strlen($contact_name) > 100) {
        $contact_errors['name'] = 'Name must be between 2 and 100 characters.';
    }

    if (empty($contact_phone)) {
        $contact_errors['phone'] = 'Phone number is required';
    } else {
        $phoneDigits = preg_replace('/[^0-9]/', '', $contact_phone);
        if (!preg_match('/^09[0-9]{9}$/', $phoneDigits)) {
            $contact_errors['phone'] = 'Please enter a valid 11-digit Philippine mobile number starting with 09.';
        }
    }

    if (empty($contact_email)) {
        $contact_errors['email'] = 'Email is required';
    } elseif (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $contact_errors['email'] = 'Invalid email format';
    }

    if (empty($contact_location)) {
        $contact_errors['location'] = 'Location is required';
    } elseif (!preg_match("/^[a-zA-Z0-9\s,\-\.]+$/", $contact_location)) {
        $contact_errors['location'] = 'Location should only contain letters, numbers, spaces, commas, hyphens, and periods.';
    }

    if ($contact_project_id <= 0)
        $contact_errors['project'] = 'Invalid project';

    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
    if (empty($recaptchaResponse)) {
        $contact_errors['recaptcha'] = 'Please complete the reCAPTCHA verification.';
    } else {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, RECAPTCHA_VERIFY_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'secret' => RECAPTCHA_SECRET_KEY,
            'response' => $recaptchaResponse,
            'remoteip' => $_SERVER['REMOTE_ADDR']
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $verifyResponse = curl_exec($ch);
        curl_close($ch);
        $responseData = json_decode($verifyResponse);
        if (!$responseData->success) {
            $contact_errors['recaptcha'] = 'reCAPTCHA verification failed. Please try again.';
        }
    }

    if (empty($contact_errors)) {
        $assignedSalesId = assignToSalesAgent($conn);
        $phoneNormalized = preg_replace('/[^0-9]/', '', $contact_phone);
        $insert_sql = "INSERT INTO project_inquiries (project_id, name, phone, email, location, assigned_to, inquiry_type, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'project_page', 'pending', NOW())";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("issssi", $contact_project_id, $contact_name, $phoneNormalized, $contact_email, $contact_location, $assignedSalesId);
        if ($insert_stmt->execute()) {
            updateAssignedAgent($conn, $assignedSalesId);
            $contact_success = true;
        } else {
            $contact_errors['submit'] = 'Failed to submit inquiry. Please try again.';
        }
        $insert_stmt->close();
    }
}

$category_labels = [
    'site' => 'Site Project',
    'commercial' => 'Commercial Space',
    'residential' => 'Residential Interior'
];
$cat = $project['category'];

$badge_colors = [
    'site'        => 'bg-amber-500/10 text-amber-600 border border-amber-500',
    'commercial'  => 'bg-violet-500/10 text-violet-600 border border-violet-500',
    'residential' => 'bg-emerald-500/10 text-emerald-600 border border-emerald-500',
];
$badge_class = $badge_colors[$cat] ?? 'bg-gray-100 text-gray-600 border border-gray-300';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <link rel="icon" type="image/png" href="<?= CLIENT_ASSET ?>/images/logo/favicon.png">

    <link
        href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@400;500;600&display=swap&font-display=swap"
        rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />

    <title><?php echo htmlspecialchars($project['title']); ?> - Project Details | Realiving Design Center</title>
    <link rel="stylesheet" href="<?= BASE_ASSET ?>assets/css/output.css">

    <script src="<?php echo RECAPTCHA_SCRIPT_URL; ?>" async defer></script>

    <style>
        /* Small bits that genuinely need raw CSS: keyframe animations, the
           JS-toggled .active/.portrait-mode state classes, and hiding
           scrollbars on a couple of horizontally-scrolling strips. */
        @keyframes rpd-shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        .rpd-shimmer {
            background: linear-gradient(90deg, #e8e0d8 25%, #f0ebe4 50%, #e8e0d8 75%);
            background-size: 200% 100%;
            animation: rpd-shimmer 1.8s infinite;
        }

        @keyframes rpd-slide-in { from { transform: translateX(400px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes rpd-slide-down { from { transform: translate(-50%, -30px); opacity: 0; } to { transform: translate(-50%, 0); opacity: 1; } }
        @keyframes rpd-fade-in { from { opacity: 0; } to { opacity: 1; } }
        @keyframes rpd-zoom-in { from { transform: scale(0.85); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .rpd-anim-slide-in { animation: rpd-slide-in 0.5s ease; }
        .rpd-anim-slide-down { animation: rpd-slide-down 0.3s ease; }
        .rpd-anim-fade-in { animation: rpd-fade-in 0.25s ease; }
        .rpd-anim-zoom-in { animation: rpd-zoom-in 0.25s ease; }

        /* portrait-mode is toggled by the checkPortrait() JS below on any
           image that turns out to be taller than it is wide */
        .portrait-mode { object-fit: contain !important; }

        /* lightbox visibility is toggled via .active from the JS, same as
           the original template — keeping this as plain CSS avoids having
           to rewrite every classList.add/remove call below */
        #imageLightbox { display: none; }
        #imageLightbox.active { display: flex; }

        .no-scrollbar { scrollbar-width: none; -ms-overflow-style: none; }
        .no-scrollbar::-webkit-scrollbar { display: none; }

        .thin-scrollbar::-webkit-scrollbar { height: 5px; }
        .thin-scrollbar::-webkit-scrollbar-thumb { background: #c4905c; border-radius: 3px; }

        /* Same sidebar white-lock as realiving_projects.php — this page has
           no hero slider either, so skip the blurred/transparent scroll
           behavior and just keep the shared sidebar solid white here too */
        #sidebar, #sidebar.scrolled {
            background: #ffffff !important;
            border-right: 1px solid rgba(0,0,0,0.08) !important;
            box-shadow: 2px 0 16px rgba(0,0,0,0.05) !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }
        #sidebar::before, #sidebar::after { display: none !important; }
        #sidebar .sb-header { border-bottom-color: rgba(0,0,0,0.08) !important; }
        #sidebar .sb-logo-mark { border-color: rgba(0,0,0,0.1) !important; }
        #sidebar .sb-collapse-btn { border-color: rgba(0,0,0,0.2) !important; color: #2f1200 !important; }
        #sidebar .sb-collapse-btn:hover { background: rgba(0,0,0,0.05) !important; }
        #sidebar .sb-label { color: rgba(0,0,0,0.45) !important; }
        #sidebar .sb-link { color: #2b2b2b !important; }
        #sidebar .sb-link i { color: #8a8a8a !important; }
        #sidebar .sb-link:hover { background: rgba(47,18,0,0.06) !important; }
        #sidebar .sb-link:hover i { color: #2f1200 !important; }
        #sidebar .sb-link.active { background: rgba(47,18,0,0.08) !important; }
        #sidebar .sb-divider { background: rgba(0,0,0,0.08) !important; }
        #sidebar .sb-footer { border-top-color: rgba(0,0,0,0.08) !important; }
        #sidebar .sb-book-btn { border-color: #2f1200 !important; color: #2f1200 !important; }
        #sidebar .sb-book-btn:hover { background: #2f1200 !important; color: #fff !important; }
        #sbLogoWhite, #sbMarkWhite { display: none !important; }
        #sbLogoDark, #sbMarkDark { display: block !important; }
    </style>
</head>

<body class="font-montserrat bg-[#faf8f5]">

    <?php include $includes['header']; ?>

    <div class="main-content">

        <!-- ═══════════════════════════════
             HERO (pure image — title lives in the floating card below)
        ═══════════════════════════════ -->
        <section class="relative w-full h-[34vh] sm:h-[42vh] md:h-[48vh] min-h-[220px] sm:min-h-[300px] overflow-hidden bg-[#1a0a00]">
            <div class="rpd-shimmer absolute inset-0" id="heroShimmer"></div>
            <img src="<?php echo CLIENT_ASSET; ?><?php echo htmlspecialchars($main_image); ?>"
                alt="<?php echo htmlspecialchars($project['title']); ?>"
                class="absolute inset-0 w-full h-full object-cover object-center" id="heroImg"
                loading="eager" onload="handleHeroLoad(this)">
            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/50 via-black/5 to-black/25"></div>
        </section>
        <!-- ═══════════════════════════════
             END HERO
        ═══════════════════════════════ -->

        <!-- ═══════════════════════════════
             FLOATING TITLE CARD — overlaps the hero's bottom edge
        ═══════════════════════════════ -->
        <div class="relative z-20 max-w-5xl mx-auto px-4 sm:px-8 -mt-14 sm:-mt-20 md:-mt-24">
            <div class="bg-white rounded-2xl sm:rounded-[1.75rem] shadow-[0_20px_60px_rgba(47,18,0,0.18)] p-6 sm:p-10 md:p-12">
                <div class="flex flex-col gap-2.5 sm:gap-3 mb-5 sm:mb-6">
                    <span class="self-start inline-block px-3.5 py-1.5 rounded-full font-montserrat text-[10px] sm:text-[11px] font-semibold tracking-[1.5px] uppercase <?php echo $badge_class; ?>">
                        <?php echo $category_labels[$cat] ?? 'Project'; ?>
                    </span>
                    <span class="flex items-start gap-1.5 text-gray-400 font-montserrat text-[12px] sm:text-[13px] leading-relaxed">
                        <i class="ri-map-pin-line text-[#c4905c] mt-0.5 flex-shrink-0"></i>
                        <?php echo htmlspecialchars($project['address']); ?>
                    </span>
                </div>
                <h1 class="font-normal leading-[1.1] text-[#1a0a00]"
                    style="font-family:'Cormorant Garamond', serif; font-size: clamp(30px, 5vw, 60px);">
                    <?php
                    $words = explode(' ', htmlspecialchars($project['title']));
                    $half = max(1, floor(count($words) / 2));
                    echo implode(' ', array_slice($words, 0, $half));
                    if (count($words) > 1):
                        ?>
                        <span class="text-[#c4905c]"> <?php echo implode(' ', array_slice($words, $half)); ?></span>
                    <?php endif; ?>
                </h1>
                <div class="w-[70px] h-[3px] bg-[#c4905c] my-4 sm:my-5"></div>
                <?php if (!empty($project['short_description'])): ?>
                    <p class="font-montserrat text-[14px] sm:text-[14.5px] text-gray-500 leading-relaxed max-w-2xl">
                        <?php echo htmlspecialchars($project['short_description']); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <!-- ═══════════════════════════════
             END FLOATING TITLE CARD
        ═══════════════════════════════ -->

        <?php if ($contact_success): ?>
            <div class="rpd-anim-slide-in fixed top-20 right-4 z-[9999] max-w-[380px]" id="successMsg">
                <div class="relative flex flex-col gap-1.5 bg-emerald-500 text-white p-6 rounded-xl shadow-[0_4px_20px_rgba(34,197,94,0.35)]">
                    <i class="ri-checkbox-circle-line text-4xl self-center"></i>
                    <h3 class="font-montserrat text-[17px] font-semibold text-center">Thank you for your interest!</h3>
                    <p class="font-montserrat text-[13px] text-center">
                        We've received your inquiry about <strong><?php echo htmlspecialchars($project['title']); ?></strong>.
                    </p>
                    <p class="font-montserrat text-[13px] text-center">
                        Our team will contact you soon at <strong><?php echo htmlspecialchars($contact_email); ?></strong>.
                    </p>
                    <button onclick="document.getElementById('successMsg').style.display='none'"
                        class="absolute top-2 right-2.5 bg-transparent border-0 text-white cursor-pointer text-xl">
                        <i class="ri-close-line"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($contact_errors) && isset($contact_errors['submit'])): ?>
            <div class="rpd-anim-slide-down fixed top-24 left-1/2 -translate-x-1/2 z-[10000]" id="errorBanner">
                <div class="relative flex flex-col items-center gap-2.5 bg-white px-9 py-6 rounded-xl shadow-[0_10px_40px_rgba(0,0,0,0.2)] border-l-[5px] border-red-500">
                    <i class="ri-error-warning-line text-5xl text-red-500"></i>
                    <h3 class="font-montserrat text-xl text-red-500 m-0">Oops!</h3>
                    <p class="font-montserrat text-gray-500 text-center m-0"><?php echo $contact_errors['submit']; ?></p>
                    <button onclick="document.getElementById('errorBanner').style.display='none'"
                        class="absolute -top-[15px] -right-[15px] bg-red-500 text-white border-0 w-[30px] h-[30px] rounded-full cursor-pointer flex items-center justify-center transition-transform duration-300 hover:bg-red-600 hover:rotate-90">
                        <i class="ri-close-line"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════
             MAIN PROJECT DETAIL SECTION
        ═══════════════════════════════ -->
        <div class="max-w-7xl mx-auto px-4 sm:px-8 pt-10 sm:pt-14 pb-10 sm:pb-16">

            <!-- Photo Grid + Sticky Contact Panel side by side -->
            <div class="grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-8 lg:gap-10 items-start mb-16">

                <!-- Left: Photo Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 sm:grid-rows-2 gap-3 sm:gap-4">

                    <!-- Main large image (left) -->
                    <div class="photo-cell group relative overflow-hidden rounded-lg bg-[#1a0a00] cursor-pointer aspect-[4/3] sm:aspect-[3/4] sm:col-start-1 sm:row-start-1 sm:row-span-2"
                        onclick="openGridImage(this)">
                        <?php if (!empty($main_image)): ?>
                            <img src="<?= CLIENT_ASSET; ?><?php echo htmlspecialchars($main_image); ?>"
                                alt="<?php echo htmlspecialchars($project['title']); ?>" loading="lazy"
                                class="w-full h-full object-cover object-center transition-transform duration-500 group-hover:scale-[1.03]"
                                onload="checkPortrait(this)">
                            <div class="absolute inset-0 bg-[#2f1200]/65 flex items-center justify-center opacity-0 transition-opacity duration-300 group-hover:opacity-100">
                                <i class="ri-zoom-in-line text-white text-4xl"></i>
                            </div>
                        <?php else: ?>
                            <div class="w-full h-full min-h-[200px] flex flex-col items-center justify-center bg-[#f0ebe4] text-gray-300 gap-2">
                                <i class="ri-image-line text-5xl"></i>
                                <p class="font-montserrat text-[13px]">Image not available</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Top right image -->
                    <div class="photo-cell group relative overflow-hidden rounded-lg bg-[#1a0a00] cursor-pointer aspect-[4/3] sm:col-start-2 sm:row-start-1"
                        onclick="openGridImage(this)">
                        <?php if (!empty($image1)): ?>
                            <img src="<?= CLIENT_ASSET; ?><?php echo htmlspecialchars($image1); ?>"
                                alt="<?php echo htmlspecialchars($project['title']); ?> - View 1" loading="lazy"
                                class="w-full h-full object-cover object-center transition-transform duration-500 group-hover:scale-[1.03]"
                                onload="checkPortrait(this)">
                            <div class="absolute inset-0 bg-[#2f1200]/65 flex items-center justify-center opacity-0 transition-opacity duration-300 group-hover:opacity-100">
                                <i class="ri-zoom-in-line text-white text-4xl"></i>
                            </div>
                        <?php else: ?>
                            <div class="w-full h-full min-h-[200px] flex flex-col items-center justify-center bg-[#f0ebe4] text-gray-300 gap-2">
                                <i class="ri-image-line text-5xl"></i>
                                <p class="font-montserrat text-[13px]">Image not available</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Bottom right image -->
                    <div class="photo-cell group relative overflow-hidden rounded-lg bg-[#1a0a00] cursor-pointer aspect-[4/3] sm:col-start-2 sm:row-start-2"
                        onclick="openGridImage(this)">
                        <?php if (!empty($image2)): ?>
                            <img src="<?= CLIENT_ASSET; ?><?php echo htmlspecialchars($image2); ?>"
                                alt="<?php echo htmlspecialchars($project['title']); ?> - View 2" loading="lazy"
                                class="w-full h-full object-cover object-center transition-transform duration-500 group-hover:scale-[1.03]"
                                onload="checkPortrait(this)">
                            <div class="absolute inset-0 bg-[#2f1200]/65 flex items-center justify-center opacity-0 transition-opacity duration-300 group-hover:opacity-100">
                                <i class="ri-zoom-in-line text-white text-4xl"></i>
                            </div>
                        <?php else: ?>
                            <div class="w-full h-full min-h-[200px] flex flex-col items-center justify-center bg-[#f0ebe4] text-gray-300 gap-2">
                                <i class="ri-image-line text-5xl"></i>
                                <p class="font-montserrat text-[13px]">Image not available</p>
                            </div>
                        <?php endif; ?>
                    </div>

                </div><!-- end photo grid -->

                <!-- Right: Sticky Contact Panel -->
                <div class="no-scrollbar bg-white border border-[#e8e0d8] rounded-2xl p-7 sm:p-9 shadow-[0_4px_24px_rgba(47,18,0,0.07)] lg:sticky lg:top-24 lg:max-h-[calc(100vh-100px)] overflow-y-auto">
                    <h2 class="flex items-center justify-center gap-2 font-montserrat font-bold text-lg tracking-[2px] text-[#1a0a00] mb-1.5">
                        <i class="ri-mail-send-line text-[#c4905c] text-xl"></i>
                        INTERESTED?
                    </h2>
                    <p class="text-center font-montserrat text-[12.5px] text-gray-400 mb-6">Get in touch with us about this project</p>

                    <form class="contact-form flex flex-col gap-4" method="POST">
                        <input type="hidden" name="project_id" value="<?php echo htmlspecialchars($project['id']); ?>">

                        <div class="form-group w-full">
                            <input type="text" name="name" placeholder="NAME *" required pattern="[a-zA-Z\s\-'\.]+"
                                minlength="2" maxlength="100"
                                class="w-full border-0 border-b-[1.5px] <?php echo isset($contact_errors['name']) ? 'border-red-500' : 'border-[#d4c4b0]'; ?> bg-transparent py-2.5 font-montserrat text-[13px] text-gray-700 outline-none focus:border-[#c4905c] transition-colors placeholder:text-gray-400 placeholder:text-xs placeholder:tracking-wide">
                            <?php if (isset($contact_errors['name'])): ?>
                                <span class="block text-red-500 font-montserrat text-[11px] mt-1"><?php echo $contact_errors['name']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group w-full">
                            <input type="tel" name="phone" placeholder="PHONE NUMBER * (09XXXXXXXXX)" required
                                pattern="09[0-9]{9}" maxlength="11"
                                class="w-full border-0 border-b-[1.5px] <?php echo isset($contact_errors['phone']) ? 'border-red-500' : 'border-[#d4c4b0]'; ?> bg-transparent py-2.5 font-montserrat text-[13px] text-gray-700 outline-none focus:border-[#c4905c] transition-colors placeholder:text-gray-400 placeholder:text-xs placeholder:tracking-wide">
                            <small class="block font-montserrat text-[10.5px] text-gray-400 italic mt-1">Format: 09XXXXXXXXX (11 digits)</small>
                            <?php if (isset($contact_errors['phone'])): ?>
                                <span class="block text-red-500 font-montserrat text-[11px] mt-1"><?php echo $contact_errors['phone']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group w-full">
                            <input type="email" name="email" placeholder="EMAIL *" required
                                class="w-full border-0 border-b-[1.5px] <?php echo isset($contact_errors['email']) ? 'border-red-500' : 'border-[#d4c4b0]'; ?> bg-transparent py-2.5 font-montserrat text-[13px] text-gray-700 outline-none focus:border-[#c4905c] transition-colors placeholder:text-gray-400 placeholder:text-xs placeholder:tracking-wide">
                            <?php if (isset($contact_errors['email'])): ?>
                                <span class="block text-red-500 font-montserrat text-[11px] mt-1"><?php echo $contact_errors['email']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group w-full">
                            <input type="text" name="location" placeholder="LOCATION *" required
                                pattern="[a-zA-Z0-9\s,\-\.]+"
                                class="w-full border-0 border-b-[1.5px] <?php echo isset($contact_errors['location']) ? 'border-red-500' : 'border-[#d4c4b0]'; ?> bg-transparent py-2.5 font-montserrat text-[13px] text-gray-700 outline-none focus:border-[#c4905c] transition-colors placeholder:text-gray-400 placeholder:text-xs placeholder:tracking-wide">
                            <?php if (isset($contact_errors['location'])): ?>
                                <span class="block text-red-500 font-montserrat text-[11px] mt-1"><?php echo $contact_errors['location']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group flex flex-col items-center my-2">
                            <div class="g-recaptcha scale-[0.88] origin-center" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"
                                data-callback="projectRecaptchaCallback"
                                data-expired-callback="projectRecaptchaExpiredCallback"
                                data-error-callback="projectRecaptchaErrorCallback"></div>
                            <?php if (isset($contact_errors['recaptcha'])): ?>
                                <span class="block text-red-500 font-montserrat text-[11px] mt-1"><?php echo $contact_errors['recaptcha']; ?></span>
                            <?php endif; ?>
                        </div>

                        <button type="submit" name="contact_submit"
                            class="w-full py-3.5 bg-[#2f1200] text-white border-0 font-montserrat font-semibold text-[13px] tracking-[3px] uppercase rounded-md cursor-pointer flex items-center justify-center gap-2 mt-2 transition-all duration-300 hover:bg-[#c4905c] hover:-translate-y-0.5 hover:shadow-lg">
                            <i class="ri-send-plane-fill text-base"></i>
                            SUBMIT INQUIRY
                        </button>
                    </form>
                </div><!-- end contact panel -->

            </div><!-- end photo-and-contact-row -->

            <!-- Project Overview (full width below) -->
            <div class="mb-4 pt-2">
                <h2 class="font-montserrat font-bold text-xl text-[#1a0a00] tracking-wide mb-2">Project Overview</h2>
                <div class="w-[50px] h-[3px] bg-[#c4905c] mb-5"></div>
                <p class="font-montserrat text-[14.5px] text-gray-600 leading-[1.8] max-w-3xl">
                    <?php echo nl2br(htmlspecialchars($project['description'])); ?>
                </p>
            </div>

        </div><!-- end project-detail-wrapper -->

        <!-- ═══════════════════════════════
             LOCATION GALLERY
        ═══════════════════════════════ -->
        <?php if (count($project_locations) > 0): ?>
            <section class="bg-white py-14 sm:py-20 border-t border-[#ede7df]">
                <div class="max-w-7xl mx-auto px-4 sm:px-8">

                    <div class="text-center mb-10 sm:mb-14">
                        <span class="inline-block font-montserrat text-[9px] sm:text-[10px] font-bold tracking-[3px] uppercase text-[#c4905c] mb-2 sm:mb-3">
                            Explore
                        </span>
                        <h2 class="text-2xl sm:text-4xl font-bold text-[#1a0a00] font-montserrat uppercase tracking-wide mb-3 sm:mb-4">
                            Project Locations
                        </h2>
                        <div class="mx-auto h-0.5 w-16 bg-[#c4905c] opacity-70 rounded-full mb-4"></div>
                        <p class="font-montserrat text-[13px] sm:text-sm text-gray-400">Explore different areas of this project</p>
                    </div>

                    <div class="flex flex-wrap justify-center gap-2.5 sm:gap-3 mb-8 sm:mb-10">
                        <?php foreach ($project_locations as $index => $location): ?>
                            <button
                                class="location-tab <?php echo $index === 0 ? 'active bg-[#2f1200] text-white shadow-[0_3px_10px_rgba(47,18,0,0.25)]' : 'bg-[#faf8f5] text-[#2f1200] hover:border-[#c4905c] hover:text-[#c4905c]'; ?> flex items-center gap-1.5 border-[1.5px] border-[#2f1200] px-5 sm:px-7 py-2 sm:py-2.5 rounded-full font-montserrat text-[12px] sm:text-[13px] font-semibold cursor-pointer transition-all duration-300"
                                data-location="<?php echo $index; ?>" onclick="showLocation(<?php echo $index; ?>)">
                                <i class="ri-map-pin-fill text-[14px] sm:text-[15px]"></i>
                                <?php echo htmlspecialchars($location['name']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="max-w-5xl mx-auto">
                        <?php foreach ($project_locations as $index => $location): ?>
                            <div class="location-content <?php echo $index === 0 ? 'active block' : 'hidden'; ?>"
                                id="location-<?php echo $index; ?>">

                                <?php if (count($location['images']) > 0): ?>
                                    <div class="relative w-full h-[280px] sm:h-[420px] md:h-[560px] overflow-hidden rounded-xl shadow-[0_8px_30px_rgba(0,0,0,0.12)] bg-[#1a0a00]" id="slideshow-<?php echo $index; ?>">
                                        <div class="flex w-full h-full transition-transform duration-700 ease-in-out" data-slides="<?php echo $index; ?>">
                                            <?php foreach ($location['images'] as $img): ?>
                                                <div class="gallery-slide min-w-full h-full relative">
                                                    <img src="<?= CLIENT_ASSET; ?><?php echo htmlspecialchars($img); ?>"
                                                        alt="<?php echo htmlspecialchars($location['name']); ?>"
                                                        class="w-full h-full object-cover object-center cursor-pointer transition-transform duration-300 hover:scale-[1.015]"
                                                        data-location="<?php echo $index; ?>" onload="checkPortrait(this)"
                                                        onclick="openFullscreen(this)">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="absolute bottom-4 sm:bottom-6 left-1/2 -translate-x-1/2 flex items-center gap-3 sm:gap-4 bg-white/95 px-4 sm:px-5 py-2.5 sm:py-3 rounded-full shadow-lg z-10">
                                            <button class="bg-[#2f1200] w-8 h-8 sm:w-9 sm:h-9 rounded-full flex items-center justify-center text-white transition-all duration-300 hover:bg-[#c4905c] hover:scale-110" onclick="previousSlide(<?php echo $index; ?>)">
                                                <i class="ri-arrow-left-s-line text-lg"></i>
                                            </button>
                                            <span class="font-montserrat text-[12px] sm:text-[13px] font-semibold text-[#2f1200] min-w-[48px] sm:min-w-[52px] text-center">
                                                <span id="current-<?php echo $index; ?>">1</span> / <?php echo count($location['images']); ?>
                                            </span>
                                            <button class="bg-[#2f1200] w-8 h-8 sm:w-9 sm:h-9 rounded-full flex items-center justify-center text-white transition-all duration-300 hover:bg-[#c4905c] hover:scale-110" onclick="nextSlide(<?php echo $index; ?>)">
                                                <i class="ri-arrow-right-s-line text-lg"></i>
                                            </button>
                                            <button class="bg-[#c4905c] w-8 h-8 sm:w-9 sm:h-9 rounded-full flex items-center justify-center text-white transition-all duration-300 hover:bg-[#2f1200] hover:scale-110" onclick="toggleAutoPlay(<?php echo $index; ?>)" id="playpause-<?php echo $index; ?>">
                                                <i class="ri-pause-line text-base"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="thin-scrollbar flex gap-2 mt-4 overflow-x-auto pb-1.5" id="thumbnails-<?php echo $index; ?>">
                                        <?php foreach ($location['images'] as $thumbIndex => $img): ?>
                                            <div class="thumbnail <?php echo $thumbIndex === 0 ? 'active border-[#2f1200]' : 'border-transparent'; ?> min-w-[76px] sm:min-w-[90px] h-[52px] sm:h-[62px] rounded-md overflow-hidden cursor-pointer border-[2.5px] transition-all duration-300 hover:border-[#c4905c] flex-shrink-0"
                                                onclick="goToSlide(<?php echo $index; ?>, <?php echo $thumbIndex; ?>)">
                                                <img src="<?= CLIENT_ASSET ?><?php echo htmlspecialchars($img); ?>" alt="Thumbnail" class="w-full h-full object-cover">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                <?php else: ?>
                                    <div class="text-center py-16 sm:py-20 text-gray-300">
                                        <i class="ri-image-line text-5xl block mb-4"></i>
                                        <p class="font-montserrat text-sm text-gray-400">No images available for this location</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                </div>
            </section>
        <?php endif; ?>
        <!-- ═══════════════════════════════
             END LOCATION GALLERY
        ═══════════════════════════════ -->

        <!-- Lightbox Modal -->
        <div class="fixed inset-0 bg-black/95 z-[10000] items-center justify-center rpd-anim-fade-in" id="imageLightbox" onclick="closeLightbox(event)">
            <div class="relative w-full h-full flex items-center justify-center">
                <button class="absolute top-5 right-5 bg-white border-0 w-12 h-12 rounded-full flex items-center justify-center cursor-pointer text-xl transition-all duration-300 hover:bg-red-500 hover:text-white hover:rotate-90 z-[10001]" onclick="closeLightbox(event)">
                    <i class="ri-close-line"></i>
                </button>
                <button class="lightbox-nav prev absolute top-1/2 -translate-y-1/2 left-3 sm:left-6 bg-white/90 border-0 w-11 h-11 sm:w-14 sm:h-14 rounded-full flex items-center justify-center cursor-pointer text-xl sm:text-2xl text-[#2f1200] transition-all duration-300 hover:bg-[#c4905c] hover:text-white hover:scale-110 disabled:opacity-25 disabled:cursor-not-allowed z-[10001]" id="lightboxPrev" onclick="navigateLightbox(-1,event)">
                    <i class="ri-arrow-left-s-line"></i>
                </button>
                <img src="" alt="Fullscreen" id="lightboxImage" onclick="event.stopPropagation()" class="rpd-anim-zoom-in max-w-[90%] max-h-[90%] object-contain select-none">
                <button class="lightbox-nav next absolute top-1/2 -translate-y-1/2 right-3 sm:right-6 bg-white/90 border-0 w-11 h-11 sm:w-14 sm:h-14 rounded-full flex items-center justify-center cursor-pointer text-xl sm:text-2xl text-[#2f1200] transition-all duration-300 hover:bg-[#c4905c] hover:text-white hover:scale-110 disabled:opacity-25 disabled:cursor-not-allowed z-[10001]" id="lightboxNext" onclick="navigateLightbox(1,event)">
                    <i class="ri-arrow-right-s-line"></i>
                </button>
                <div class="absolute bottom-6 left-1/2 -translate-x-1/2 bg-white/95 px-5 py-2 rounded-full font-montserrat text-sm font-semibold text-[#2f1200] z-[10001]" id="lightboxCounter">1 / 1</div>
            </div>
        </div>

        <!-- ═══════════════════════════════
             RELATED PROJECTS
        ═══════════════════════════════ -->
        <?php if (count($other_projects) > 0): ?>
            <section class="bg-[#faf8f5] py-14 sm:py-20 border-t border-[#ede7df]">
                <div class="max-w-7xl mx-auto px-4 sm:px-8">

                    <div class="text-center mb-10 sm:mb-14">
                        <span class="inline-block font-montserrat text-[9px] sm:text-[10px] font-bold tracking-[3px] uppercase text-[#c4905c] mb-2 sm:mb-3">
                            Discover More
                        </span>
                        <h2 class="text-2xl sm:text-4xl font-bold text-[#1a0a00] font-montserrat uppercase tracking-wide mb-3 sm:mb-4">
                            Related Projects
                        </h2>
                        <div class="mx-auto h-0.5 w-16 bg-[#c4905c] opacity-70 rounded-full mb-4"></div>
                        <p class="font-montserrat text-[13px] sm:text-sm text-gray-400">
                            Explore more projects in the <?php echo $category_labels[$project['category']] ?? 'same'; ?> category
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 sm:gap-6 mb-10 sm:mb-14">
                        <?php foreach ($other_projects as $related):
                            $related_main = '/' . ltrim($related['main_image'], './');
                            $related_hover = '/' . ltrim($related['hover_image'], './');
                            ?>
                            <a href="<?= BASE_URL; ?>view-projects?id=<?php echo $related['id']; ?>"
                                class="related-card group relative block h-[260px] sm:h-[300px] rounded-2xl overflow-hidden shadow-[0_4px_20px_rgba(47,18,0,0.08)] hover:shadow-[0_16px_40px_rgba(47,18,0,0.18)] transition-shadow duration-500 bg-[#2f1200]">

                                <img class="default-img absolute inset-0 w-full h-full object-cover transition-opacity duration-500"
                                    src="<?= CLIENT_ASSET; ?><?php echo htmlspecialchars($related_main); ?>"
                                    alt="<?php echo htmlspecialchars($related['title']); ?>">
                                <img class="hover-img absolute inset-0 w-full h-full object-cover opacity-0 transition-opacity duration-500 group-hover:opacity-100"
                                    src="<?= CLIENT_ASSET; ?><?php echo htmlspecialchars($related_hover); ?>"
                                    alt="<?php echo htmlspecialchars($related['title']); ?>">
                                <style>.related-card:hover .default-img{opacity:0;}</style>

                                <div class="absolute inset-0 bg-gradient-to-t from-[#0e0704]/90 via-[#0e0704]/20 to-transparent"></div>

                                <div class="absolute bottom-0 left-0 right-0 p-5">
                                    <h3 class="font-montserrat font-bold text-white text-base mb-1.5"><?php echo htmlspecialchars($related['title']); ?></h3>
                                    <p class="flex items-center gap-1.5 text-white/75 text-[11px] font-montserrat">
                                        <i class="ri-map-pin-line"></i><?php echo htmlspecialchars($related['address']); ?>
                                    </p>
                                </div>

                                <div class="absolute top-4 right-4 w-8 h-8 rounded-full bg-white/15 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-300" style="backdrop-filter:blur(6px);">
                                    <i class="ri-arrow-right-line text-white text-sm"></i>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="text-center">
                        <a href="projects" class="inline-flex items-center gap-2 bg-[#2f1200] text-white font-montserrat font-semibold text-[11px] tracking-[2px] uppercase px-8 py-3.5 rounded-full transition-all duration-300 hover:bg-[#c4905c]">
                            VIEW ALL PROJECTS <i class="ri-arrow-right-line"></i>
                        </a>
                    </div>
                </div>
            </section>
        <?php endif; ?>
        <!-- ═══════════════════════════════
             END RELATED PROJECTS
        ═══════════════════════════════ -->

        <!-- ===== TOP MODULARS ===== -->
        <?php /* Top Modulars - temporarily hidden
<?php if ($top_products_result && $top_products_result->num_rows > 0): ?>
<section class="top-modular-cabinets">
<div class="box">
<div class="section-header">
<h2 class="section-title">TOP MODULARS</h2>
<p class="section-subtitle">Discover our most popular modular solutions</p>
</div>
<div class="carousel-wrapper">
<button class="carousel-arrow left" id="scrollLeft" aria-label="Scroll Left"><i class="ri-arrow-left-s-line"></i></button>
<div class="products-carousel" id="cabinetCarousel">
<?php while ($product = $top_products_result->fetch_assoc()):
$product_image = !empty($product['item_image_path'])
? '../images/products/' . htmlspecialchars($product['item_image_path'])
: '../images/cabinet-example.png';
?>
<div class="product-card">
<div class="product-image-wrapper">
<img src="<?php echo $product_image; ?>" alt="<?php echo htmlspecialchars($product['item_name']); ?>" class="product-image">
<?php if (!empty($product['dimension_label_name'])): ?>
<span class="product-category"><?php echo htmlspecialchars($product['dimension_label_name']); ?></span>
<?php endif; ?>
</div>
<div class="product-info">
<div class="product-code"><?php echo htmlspecialchars($product['item_code']); ?></div>
<h3 class="product-name"><?php echo htmlspecialchars($product['item_name']); ?></h3>
<div class="product-details">
<?php if (!empty($product['item_material'])): ?>
<span class="detail-item"><i class="ri-hammer-line"></i><?php echo htmlspecialchars($product['item_material']); ?></span>
<?php endif; ?>
<?php if (!empty($product['item_color'])): ?>
<span class="detail-item"><i class="ri-palette-line"></i><?php echo htmlspecialchars($product['item_color']); ?></span>
<?php endif; ?>
</div>
<a href="../modular/product-details.php?id=<?php echo $product['item_id']; ?>" class="view-details-btn">
<span>View Details</span><i class="ri-arrow-right-line"></i>
</a>
</div>
</div>
<?php endwhile; ?>
</div>
<button class="carousel-arrow right" id="scrollRight" aria-label="Scroll Right"><i class="ri-arrow-right-s-line"></i></button>
</div>
<div class="view-all-wrapper">
<a href="../modular/product-catalog.php" class="view-all-btn">View All Modulars <i class="ri-arrow-right-line"></i></a>
</div>
</div>
</section>
<?php else: ?>
<section class="top-modular-cabinets">
<div class="box">
<div class="no-products-message">
<i class="ri-inbox-line"></i>
<p>No top products available at the moment.</p>
<a href="../modular/product-catalog.php" class="browse-btn">Browse All Products</a>
</div>
</div>
</section>
<?php endif; ?>
*/ ?>

        <?php include $includes['footer']; ?>

    </div><!-- end main-content -->

    <script>
        // ===== PORTRAIT/LANDSCAPE IMAGE DETECTION =====
        function checkPortrait(img) {
            if (img.naturalWidth && img.naturalHeight) {
                if (img.naturalWidth < img.naturalHeight) {
                    img.classList.add('portrait-mode');
                    const cell = img.closest('.photo-cell');
                    if (cell) cell.style.backgroundColor = '#1a0a00';
                }
            }
        }

        function handleHeroLoad(img) {
            document.getElementById('heroShimmer').style.display = 'none';
            checkPortrait(img);
        }

        // ===== GRID IMAGE LIGHTBOX =====
        let gridLightboxImages = [];
        let gridLightboxIndex = 0;

        function openGridImage(cell) {
            const img = cell.querySelector('img');
            if (!img) return;
            gridLightboxImages = Array.from(document.querySelectorAll('.photo-cell img')).map(i => i.src);
            gridLightboxIndex = gridLightboxImages.indexOf(img.src);
            if (gridLightboxIndex < 0) gridLightboxIndex = 0;
            currentLightboxImages = gridLightboxImages;
            currentLightboxIndex = gridLightboxIndex;
            currentLightboxLocation = -1;
            updateLightboxImage();
            document.getElementById('imageLightbox').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        // ===== GALLERY LOCATION TABS =====
        const galleries = {};
        const autoPlayIntervals = {};

        document.querySelectorAll('[data-slides]').forEach((slidesContainer, index) => {
            galleries[index] = {
                currentIndex: 0,
                slidesContainer: slidesContainer,
                slides: slidesContainer.querySelectorAll('.gallery-slide'),
                counter: document.getElementById(`current-${index}`),
                thumbnails: document.querySelectorAll(`#thumbnails-${index} .thumbnail`),
                isPlaying: true
            };
            startAutoPlay(index);
        });

        function showLocation(locationIndex) {
            Object.keys(autoPlayIntervals).forEach(key => clearInterval(autoPlayIntervals[key]));

            document.querySelectorAll('.location-content').forEach(c => {
                c.classList.remove('active', 'block');
                c.classList.add('hidden');
            });
            document.querySelectorAll('.location-tab').forEach(t => {
                t.classList.remove('active', 'bg-[#2f1200]', 'text-white', 'shadow-[0_3px_10px_rgba(47,18,0,0.25)]');
                t.classList.add('bg-[#faf8f5]', 'text-[#2f1200]');
            });

            const targetContent = document.getElementById(`location-${locationIndex}`);
            targetContent.classList.remove('hidden');
            targetContent.classList.add('active', 'block');

            const targetTab = document.querySelector(`.location-tab[data-location="${locationIndex}"]`);
            targetTab.classList.add('active', 'bg-[#2f1200]', 'text-white', 'shadow-[0_3px_10px_rgba(47,18,0,0.25)]');
            targetTab.classList.remove('bg-[#faf8f5]', 'text-[#2f1200]');

            if (galleries[locationIndex]) {
                galleries[locationIndex].isPlaying = true;
                startAutoPlay(locationIndex);
                updatePlayPauseButton(locationIndex);
            }
        }

        function updateSlide(locationIndex) {
            const gallery = galleries[locationIndex];
            if (!gallery) return;
            gallery.slidesContainer.style.transform = `translateX(${-gallery.currentIndex * 100}%)`;
            if (gallery.counter) gallery.counter.textContent = gallery.currentIndex + 1;
            gallery.thumbnails.forEach((thumb, i) => {
                thumb.classList.toggle('active', i === gallery.currentIndex);
                thumb.classList.toggle('border-[#2f1200]', i === gallery.currentIndex);
                thumb.classList.toggle('border-transparent', i !== gallery.currentIndex);
            });
        }

        function nextSlide(locationIndex) {
            const g = galleries[locationIndex];
            if (!g) return;
            g.currentIndex = (g.currentIndex + 1) % g.slides.length;
            updateSlide(locationIndex);
        }

        function previousSlide(locationIndex) {
            const g = galleries[locationIndex];
            if (!g) return;
            g.currentIndex = (g.currentIndex - 1 + g.slides.length) % g.slides.length;
            updateSlide(locationIndex);
        }

        function goToSlide(locationIndex, slideIndex) {
            const g = galleries[locationIndex];
            if (!g) return;
            g.currentIndex = slideIndex;
            updateSlide(locationIndex);
        }

        function startAutoPlay(locationIndex) {
            const g = galleries[locationIndex];
            if (!g) return;
            if (autoPlayIntervals[locationIndex]) clearInterval(autoPlayIntervals[locationIndex]);
            autoPlayIntervals[locationIndex] = setInterval(() => {
                if (g.isPlaying) nextSlide(locationIndex);
            }, 3000);
        }

        function toggleAutoPlay(locationIndex) {
            const g = galleries[locationIndex];
            if (!g) return;
            g.isPlaying = !g.isPlaying;
            updatePlayPauseButton(locationIndex);
            if (g.isPlaying) startAutoPlay(locationIndex);
        }

        function updatePlayPauseButton(locationIndex) {
            const btn = document.getElementById(`playpause-${locationIndex}`);
            if (!btn) return;
            btn.querySelector('i').className = galleries[locationIndex].isPlaying ? 'ri-pause-line text-base' : 'ri-play-line text-base';
        }

        // ===== FULLSCREEN LIGHTBOX =====
        let currentLightboxLocation = 0;
        let currentLightboxIndex = 0;
        let currentLightboxImages = [];

        function openFullscreen(imgElement) {
            const locationIndex = parseInt(imgElement.getAttribute('data-location'));
            const gallery = galleries[locationIndex];
            if (!gallery) return;
            currentLightboxLocation = locationIndex;
            currentLightboxImages = Array.from(gallery.slides).map(s => s.querySelector('img').src);
            currentLightboxIndex = gallery.currentIndex;
            updateLightboxImage();
            document.getElementById('imageLightbox').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function updateLightboxImage() {
            document.getElementById('lightboxImage').src = currentLightboxImages[currentLightboxIndex];
            document.getElementById('lightboxCounter').textContent = `${currentLightboxIndex + 1} / ${currentLightboxImages.length}`;
            document.getElementById('lightboxPrev').disabled = currentLightboxIndex === 0;
            document.getElementById('lightboxNext').disabled = currentLightboxIndex === currentLightboxImages.length - 1;
        }

        function navigateLightbox(direction, event) {
            event.stopPropagation();
            const newIndex = currentLightboxIndex + direction;
            if (newIndex >= 0 && newIndex < currentLightboxImages.length) {
                currentLightboxIndex = newIndex;
                updateLightboxImage();
                if (currentLightboxLocation >= 0 && galleries[currentLightboxLocation]) {
                    galleries[currentLightboxLocation].currentIndex = currentLightboxIndex;
                    updateSlide(currentLightboxLocation);
                }
            }
        }

        function closeLightbox(event) {
            if (event) event.stopPropagation();
            document.getElementById('imageLightbox').classList.remove('active');
            document.body.style.overflow = '';
        }

        document.addEventListener('keydown', function (e) {
            const lb = document.getElementById('imageLightbox');
            if (!lb.classList.contains('active')) return;
            if (e.key === 'Escape') closeLightbox();
            else if (e.key === 'ArrowLeft') navigateLightbox(-1, e);
            else if (e.key === 'ArrowRight') navigateLightbox(1, e);
        });

        const lbImg = document.getElementById('lightboxImage');
        if (lbImg) lbImg.addEventListener('click', e => e.stopPropagation());

        // ===== PRODUCT CAROUSEL (kept dormant — Top Modulars section is currently commented out) =====
        const carousel = document.getElementById('cabinetCarousel');
        const leftArrow = document.getElementById('scrollLeft');
        const rightArrow = document.getElementById('scrollRight');

        if (carousel && leftArrow && rightArrow) {
            const scrollAmount = 280;
            leftArrow.addEventListener('click', () => carousel.scrollBy({ left: -scrollAmount, behavior: 'smooth' }));
            rightArrow.addEventListener('click', () => carousel.scrollBy({ left: scrollAmount, behavior: 'smooth' }));

            function updateArrows() {
                const maxScroll = carousel.scrollWidth - carousel.clientWidth;
                leftArrow.style.opacity = carousel.scrollLeft <= 0 ? '0.3' : '1';
                rightArrow.style.opacity = carousel.scrollLeft >= maxScroll - 1 ? '0.3' : '1';
            }
            carousel.addEventListener('scroll', updateArrows);
            window.addEventListener('resize', updateArrows);
            updateArrows();

            let isDown = false, startX, scrollLeft;
            carousel.addEventListener('mousedown', e => { isDown = true; carousel.style.cursor = 'grabbing'; startX = e.pageX - carousel.offsetLeft; scrollLeft = carousel.scrollLeft; });
            carousel.addEventListener('mouseleave', () => { isDown = false; carousel.style.cursor = 'grab'; });
            carousel.addEventListener('mouseup', () => { isDown = false; carousel.style.cursor = 'grab'; });
            carousel.addEventListener('mousemove', e => { if (!isDown) return; e.preventDefault(); carousel.scrollLeft = scrollLeft - (e.pageX - carousel.offsetLeft - startX) * 2; });
        }

        // ===== FORM VALIDATION =====
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.querySelector('.contact-form');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                const nameInput = this.querySelector('input[name="name"]');
                if (nameInput && !/^[a-zA-Z\s\-'\.]+$/.test(nameInput.value.trim())) {
                    alert('Name should only contain letters, spaces, hyphens, and apostrophes.');
                    nameInput.classList.add('border-red-500'); nameInput.focus(); e.preventDefault(); return;
                }
                const phoneInput = this.querySelector('input[name="phone"]');
                if (phoneInput) {
                    const digits = phoneInput.value.trim().replace(/[^0-9]/g, '');
                    if (!/^09[0-9]{9}$/.test(digits)) {
                        alert('Please enter a valid 11-digit Philippine mobile number starting with 09.');
                        phoneInput.classList.add('border-red-500'); phoneInput.focus(); e.preventDefault(); return;
                    }
                }
                const locInput = this.querySelector('input[name="location"]');
                if (locInput && locInput.value && !/^[a-zA-Z0-9\s,\-\.]+$/.test(locInput.value.trim())) {
                    alert('Location should only contain letters, numbers, spaces, commas, hyphens, and periods.');
                    locInput.classList.add('border-red-500'); locInput.focus(); e.preventDefault(); return;
                }
                if (typeof grecaptcha !== 'undefined') {
                    if (!grecaptcha.getResponse()) {
                        alert('Please complete the reCAPTCHA verification.'); e.preventDefault();
                    }
                } else {
                    alert('reCAPTCHA is not loaded. Please refresh the page.'); e.preventDefault();
                }
            });
        });

        window.projectRecaptchaCallback = () => { };
        window.projectRecaptchaExpiredCallback = () => alert('reCAPTCHA expired. Please verify again.');
        window.projectRecaptchaErrorCallback = () => alert('reCAPTCHA error. Please refresh and try again.');
    </script>

    <?php $conn->close(); ?>
</body>

</html>