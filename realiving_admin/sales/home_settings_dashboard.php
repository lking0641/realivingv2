<?php
//home_settings_dashboard.php
session_start();
include '../../connection/connection.php';
include '../design/mainbody.php';
include '../../loginpage/checkrole.php';

// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales']);

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

// Get counts for each section
$hero_count = $conn->query("SELECT COUNT(*) as count FROM hero_section")->fetch_assoc()['count'];
$hero_active = $conn->query("SELECT COUNT(*) as count FROM hero_section WHERE is_active = 1")->fetch_assoc()['count'];

$inquire_count = $conn->query("SELECT COUNT(*) as count FROM inquire_images")->fetch_assoc()['count'];
$inquire_active = $conn->query("SELECT COUNT(*) as count FROM inquire_images WHERE is_active = 1")->fetch_assoc()['count'];

$ads_count = $conn->query("SELECT COUNT(*) as count FROM ads_banner")->fetch_assoc()['count'];
$ads_active = $conn->query("SELECT COUNT(*) as count FROM ads_banner WHERE is_active = 1")->fetch_assoc()['count'];

$services_count = $conn->query("SELECT COUNT(*) as count FROM services_section")->fetch_assoc()['count'];
$services_active = $conn->query("SELECT COUNT(*) as count FROM services_section WHERE is_active = 1")->fetch_assoc()['count'];

$ads_content_count = $conn->query("SELECT COUNT(*) as count FROM ads_content")->fetch_assoc()['count'];
$ads_content_active = $conn->query("SELECT COUNT(*) as count FROM ads_content WHERE is_active = 1")->fetch_assoc()['count'];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Home Settings - Admin Dashboard</title>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: "#4f46e5",
                        secondary: "#4338ca",
                        accent: "#3730a3"
                    },
                }
            },
        };
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <div class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="../sales/sales_dashboard.php" class="text-gray-600 hover:text-primary transition-colors">
                        <i class="ri-arrow-left-line text-xl"></i>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Home Settings Dashboard</h1>
                        <p class="text-sm text-gray-500">Manage hero section, inquire, and ads images</p>
                    </div>
                </div>
                <img src="../../realiving_user/images/logo/realiving_logo_hd.png" alt="Logo" class="h-12 object-contain" />
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Row 1: Hero, Inquire, Ads Banner -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Hero Section Card -->
            <a href="home_settings_hero_view.php" class="block group">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300 hover:border-primary">
                    <div class="flex items-start justify-between mb-4">
                        <div class="p-3 bg-indigo-50 rounded-xl group-hover:bg-indigo-100 transition-colors">
                            <i class="ri-image-line text-3xl text-primary"></i>
                        </div>
                        <i class="ri-arrow-right-line text-xl text-gray-400 group-hover:text-primary transition-colors"></i>
                    </div>
                    <h2 class="text-xl font-semibold text-gray-800 mb-2">Hero Section</h2>
                    <p class="text-sm text-gray-500 mb-4">Multiple images can be active</p>
                    <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                        <div class="flex items-center space-x-4">
                            <div class="text-center">
                                <p class="text-2xl font-bold text-gray-800"><?php echo $hero_count; ?></p>
                                <p class="text-xs text-gray-500">Total</p>
                            </div>
                            <div class="text-center">
                                <p class="text-2xl font-bold text-green-600"><?php echo $hero_active; ?></p>
                                <p class="text-xs text-gray-500">Active</p>
                            </div>
                        </div>
                    </div>
                </div>
            </a>

            <!-- Inquire Image Card -->
            <a href="home_settings_inquire_view.php" class="block group">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300 hover:border-primary">
                    <div class="flex items-start justify-between mb-4">
                        <div class="p-3 bg-purple-50 rounded-xl group-hover:bg-purple-100 transition-colors">
                            <i class="ri-question-line text-3xl text-purple-600"></i>
                        </div>
                        <i class="ri-arrow-right-line text-xl text-gray-400 group-hover:text-primary transition-colors"></i>
                    </div>
                    <h2 class="text-xl font-semibold text-gray-800 mb-2">Inquire Image</h2>
                    <p class="text-sm text-gray-500 mb-4">Only 1 image can be active</p>
                    <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                        <div class="flex items-center space-x-4">
                            <div class="text-center">
                                <p class="text-2xl font-bold text-gray-800"><?php echo $inquire_count; ?></p>
                                <p class="text-xs text-gray-500">Total</p>
                            </div>
                            <div class="text-center">
                                <p class="text-2xl font-bold text-green-600"><?php echo $inquire_active; ?></p>
                                <p class="text-xs text-gray-500">Active</p>
                            </div>
                        </div>
                    </div>
                </div>
            </a>

            <!-- Ads Banner Card -->
            <a href="home_settings_ads_view.php" class="block group">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300 hover:border-primary">
                    <div class="flex items-start justify-between mb-4">
                        <div class="p-3 bg-amber-50 rounded-xl group-hover:bg-amber-100 transition-colors">
                            <i class="ri-advertisement-line text-3xl text-amber-600"></i>
                        </div>
                        <i class="ri-arrow-right-line text-xl text-gray-400 group-hover:text-primary transition-colors"></i>
                    </div>
                    <h2 class="text-xl font-semibold text-gray-800 mb-2">Ads Banner</h2>
                    <p class="text-sm text-gray-500 mb-4">Only 1 banner can be active</p>
                    <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                        <div class="flex items-center space-x-4">
                            <div class="text-center">
                                <p class="text-2xl font-bold text-gray-800"><?php echo $ads_count; ?></p>
                                <p class="text-xs text-gray-500">Total</p>
                            </div>
                            <div class="text-center">
                                <p class="text-2xl font-bold text-green-600"><?php echo $ads_active; ?></p>
                                <p class="text-xs text-gray-500">Active</p>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>

        <!-- Row 2: Services, Ads Content -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
            <!-- Services Section Card -->
            <a href="home_settings_services_view.php" class="block group">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300 hover:border-primary">
                    <div class="flex items-start justify-between mb-4">
                        <div class="p-3 bg-emerald-50 rounded-xl group-hover:bg-emerald-100 transition-colors">
                            <i class="ri-service-line text-3xl text-emerald-600"></i>
                        </div>
                        <i class="ri-arrow-right-line text-xl text-gray-400 group-hover:text-primary transition-colors"></i>
                    </div>
                    <h2 class="text-xl font-semibold text-gray-800 mb-2">Services Section</h2>
                    <p class="text-sm text-gray-500 mb-4">Manage service cards on homepage</p>
                    <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                        <div class="flex items-center space-x-4">
                            <div class="text-center">
                                <p class="text-2xl font-bold text-gray-800"><?php echo $services_count; ?></p>
                                <p class="text-xs text-gray-500">Total</p>
                            </div>
                            <div class="text-center">
                                <p class="text-2xl font-bold text-green-600"><?php echo $services_active; ?></p>
                                <p class="text-xs text-gray-500">Active</p>
                            </div>
                        </div>
                    </div>
                </div>
            </a>

            <!-- Ads Content Card -->
            <a href="home_settings_ads_content_view.php" class="block group">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300 hover:border-primary">
                    <div class="flex items-start justify-between mb-4">
                        <div class="p-3 bg-rose-50 rounded-xl group-hover:bg-rose-100 transition-colors">
                            <i class="ri-megaphone-line text-3xl text-rose-600"></i>
                        </div>
                        <i class="ri-arrow-right-line text-xl text-gray-400 group-hover:text-primary transition-colors"></i>
                    </div>
                    <h2 class="text-xl font-semibold text-gray-800 mb-2">Ads Content</h2>
                    <p class="text-sm text-gray-500 mb-4">Manage ads with caption & hashtags</p>
                    <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                        <div class="flex items-center space-x-4">
                            <div class="text-center">
                                <p class="text-2xl font-bold text-gray-800"><?php echo $ads_content_count; ?></p>
                                <p class="text-xs text-gray-500">Total</p>
                            </div>
                            <div class="text-center">
                                <p class="text-2xl font-bold text-green-600"><?php echo $ads_content_active; ?></p>
                                <p class="text-xs text-gray-500">Active</p>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>
</body>
</html>