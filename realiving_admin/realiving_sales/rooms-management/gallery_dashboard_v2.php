<?php
// gallery_dashboard_v2.php - REDESIGNED
include $includes ['mainbody'];

// Allow only admin1 to admin5
require_role(['sales']);

// Get counts
$building_types_count = $conn->query("SELECT COUNT(*) as count FROM building_types")->fetch_assoc()['count'];
$themes_count = $conn->query("SELECT COUNT(*) as count FROM themes")->fetch_assoc()['count'];
$sub_themes_count = $conn->query("SELECT COUNT(*) as count FROM sub_themes")->fetch_assoc()['count'];
$collections_count = $conn->query("SELECT COUNT(*) as count FROM gallery_collections")->fetch_assoc()['count'];
$total_images = $conn->query("SELECT COUNT(*) as count FROM gallery_collection_images")->fetch_assoc()['count'];
$total_connections = $conn->query("SELECT COUNT(*) as count FROM gallery_collection_connections")->fetch_assoc()['count'];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Gallery Management Dashboard V2</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: "#4f46e5",
                        secondary: "#4338ca",
                    },
                }
            },
        };
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />
    <style>
        body { font-family: 'Inter', sans-serif; }
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <div class="gradient-bg text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="sales-dashboard" class="text-white/80 hover:text-white transition-colors">
                        <i class="ri-arrow-left-line text-2xl"></i>
                    </a>
                    <div>
                        <h1 class="text-3xl font-bold">Gallery Management System V2</h1>
                        <p class="text-white/80 text-sm mt-1">Collection-based gallery with cross-referencing (Max 10 images per collection)</p>
                    </div>
                </div>
                <img src="../../realiving_user/images/logo/realiving_logo_hd.png" alt="Logo" class="h-14 object-contain" />
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Statistics Overview -->
        <div class="grid grid-cols-1 md:grid-cols-6 gap-6 mb-8">
            <!-- Building Types -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-3">
                    <div class="p-3 bg-blue-50 rounded-lg">
                        <i class="ri-building-line text-2xl text-blue-600"></i>
                    </div>
                </div>
                <p class="text-3xl font-bold text-gray-800"><?php echo $building_types_count; ?></p>
                <p class="text-sm text-gray-500">Building Types</p>
            </div>

            <!-- Themes -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <div class="flex items-center justify-between mb-3">
        <div class="p-3 bg-green-50 rounded-lg">
            <i class="ri-palette-line text-2xl text-green-600"></i>
        </div>
    </div>
    <p class="text-3xl font-bold text-gray-800"><?php echo $themes_count; ?></p>
    <p class="text-sm text-gray-500">Themes</p>
</div>

<!-- Sub-Themes -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <div class="flex items-center justify-between mb-3">
        <div class="p-3 bg-teal-50 rounded-lg">
            <i class="ri-brush-line text-2xl text-teal-600"></i>
        </div>
    </div>
    <p class="text-3xl font-bold text-gray-800"><?php echo $sub_themes_count; ?></p>
    <p class="text-sm text-gray-500">Sub-Themes</p>
</div>

            <!-- Collections -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-3">
                    <div class="p-3 bg-purple-50 rounded-lg">
                        <i class="ri-folder-image-line text-2xl text-purple-600"></i>
                    </div>
                </div>
                <p class="text-3xl font-bold text-gray-800"><?php echo $collections_count; ?></p>
                <p class="text-sm text-gray-500">Collections</p>
            </div>

            <!-- Total Images -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-3">
                    <div class="p-3 bg-amber-50 rounded-lg">
                        <i class="ri-image-line text-2xl text-amber-600"></i>
                    </div>
                </div>
                <p class="text-3xl font-bold text-gray-800"><?php echo $total_images; ?></p>
                <p class="text-sm text-gray-500">Total Images</p>
            </div>

            <!-- Total Connections -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-3">
                    <div class="p-3 bg-pink-50 rounded-lg">
                        <i class="ri-links-line text-2xl text-pink-600"></i>
                    </div>
                </div>
                <p class="text-3xl font-bold text-gray-800"><?php echo $total_connections; ?></p>
                <p class="text-sm text-gray-500">Connections</p>
            </div>
        </div>

        <!-- System Flow Diagram -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">🎨 New System Structure</h2>
            <div class="flex items-center justify-center space-x-4 text-sm">
                <div class="text-center">
                    <div class="w-28 h-28 bg-blue-100 rounded-lg flex items-center justify-center mb-2">
                        <i class="ri-building-line text-4xl text-blue-600"></i>
                    </div>
                    <p class="font-medium">Building Type</p>
                    <p class="text-xs text-gray-500">Hospitality</p>
                </div>
                <i class="ri-arrow-right-line text-2xl text-gray-400"></i>
                <div class="text-center">
    <div class="w-28 h-28 bg-green-100 rounded-lg flex items-center justify-center mb-2">
        <i class="ri-palette-line text-4xl text-green-600"></i>
    </div>
    <p class="font-medium">Theme</p>
    <p class="text-xs text-gray-500">Contemporary</p>
</div>
<i class="ri-arrow-right-line text-2xl text-gray-400"></i>
<div class="text-center">
    <div class="w-28 h-28 bg-teal-100 rounded-lg flex items-center justify-center mb-2">
        <i class="ri-brush-line text-4xl text-teal-600"></i>
    </div>
    <p class="font-medium">Sub-Theme</p>
    <p class="text-xs text-gray-500">Minimalist</p>
</div>
                <i class="ri-arrow-right-line text-2xl text-gray-400"></i>
                <div class="text-center">
    <div class="w-28 h-28 bg-purple-100 rounded-lg flex items-center justify-center mb-2">
        <i class="ri-folder-image-line text-4xl text-purple-600"></i>
    </div>
    <p class="font-medium">Collection</p>
    <p class="text-xs text-gray-500">Living Room</p>
</div>
                <i class="ri-arrow-right-line text-2xl text-gray-400"></i>
                <div class="text-center">
                    <div class="w-28 h-28 bg-amber-100 rounded-lg flex items-center justify-center mb-2">
                        <i class="ri-gallery-line text-4xl text-amber-600"></i>
                    </div>
                    <p class="font-medium">Images</p>
                    <p class="text-xs text-gray-500">Max 10 photos</p>
                </div>
            </div>
            
            <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 bg-blue-50 rounded-lg">
    <p class="text-sm text-blue-800">
        <i class="ri-information-line mr-1"></i>
        <strong>New Structure:</strong><br/>
        Theme (Contemporary) → Sub-theme (Minimalist) → Collections (Living Room, Bedroom, etc.)
    </p>
</div>
<div class="p-4 bg-green-50 rounded-lg">
    <p class="text-sm text-green-800">
        <i class="ri-lightbulb-line mr-1"></i>
        <strong>Cross-Reference Magic:</strong><br/>
        "Minimalist Contemporary" can appear in BOTH Hospitality AND Commercial! Same collections, multiple building types.
    </p>
</div>
            </div>
        </div>

        <!-- Management Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Manage Building Types -->
            <a href="manage-building-types" class="block group">
                <div class="bg-white rounded-xl shadow-sm border-2 border-gray-200 p-6 hover:shadow-lg hover:border-blue-500 transition-all duration-300">
                    <div class="flex items-start justify-between mb-4">
                        <div class="p-3 bg-blue-50 rounded-xl group-hover:bg-blue-100 transition-colors">
                            <i class="ri-building-line text-3xl text-blue-600"></i>
                        </div>
                        <i class="ri-arrow-right-line text-xl text-gray-400 group-hover:text-blue-600 transition-colors"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Building Types</h3>
                    <p class="text-sm text-gray-500 mb-4">Hospitality, Commercial, Residential, Institutional</p>
                    <div class="flex items-center space-x-2 text-sm">
                        <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full font-medium"><?php echo $building_types_count; ?> types</span>
                    </div>
                </div>
            </a>

            <!-- Manage Themes -->
<a href="manage-themes" class="block group">
    <div class="bg-white rounded-xl shadow-sm border-2 border-gray-200 p-6 hover:shadow-lg hover:border-green-500 transition-all duration-300">
        <div class="flex items-start justify-between mb-4">
            <div class="p-3 bg-green-50 rounded-xl group-hover:bg-green-100 transition-colors">
                <i class="ri-palette-line text-3xl text-green-600"></i>
            </div>
            <i class="ri-arrow-right-line text-xl text-gray-400 group-hover:text-green-600 transition-colors"></i>
        </div>
        <h3 class="text-lg font-semibold text-gray-800 mb-2">Themes & Sub-Themes</h3>
        <p class="text-sm text-gray-500 mb-4">Contemporary, Modern, Traditional, etc.</p>
        <div class="flex items-center space-x-2 text-sm">
            <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full font-medium"><?php echo $themes_count; ?> themes</span>
            <span class="px-3 py-1 bg-teal-100 text-teal-700 rounded-full font-medium"><?php echo $sub_themes_count; ?> sub-themes</span>
        </div>
    </div>
</a>

            <!-- Manage Collections -->
            <a href="manage-collections" class="block group">
                <div class="bg-white rounded-xl shadow-sm border-2 border-purple-200 p-6 hover:shadow-lg hover:border-purple-500 transition-all duration-300 relative overflow-hidden">
                    <div class="absolute top-0 right-0 bg-purple-500 text-white text-xs px-3 py-1 rounded-bl-lg font-bold">
                        MAIN
                    </div>
                    <div class="flex items-start justify-between mb-4">
                        <div class="p-3 bg-purple-50 rounded-xl group-hover:bg-purple-100 transition-colors">
                            <i class="ri-folder-image-line text-3xl text-purple-600"></i>
                        </div>
                        <i class="ri-arrow-right-line text-xl text-gray-400 group-hover:text-purple-600 transition-colors"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Gallery Collections</h3>
                    <p class="text-sm text-gray-500 mb-4">Room areas (Living Room, Bedroom, etc.) with up to 10 images</p>
                    <div class="flex items-center space-x-2 text-sm">
                        <span class="px-3 py-1 bg-purple-100 text-purple-700 rounded-full font-medium"><?php echo $collections_count; ?> collections</span>
                        <span class="px-3 py-1 bg-amber-100 text-amber-700 rounded-full font-medium"><?php echo $total_images; ?> images</span>
                    </div>
                </div>
            </a>
        </div>

        <!-- Quick Start Guide -->
        <div class="mt-8 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl shadow-lg p-6 text-white">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <h3 class="text-xl font-bold mb-3">🚀 Quick Start Guide</h3>
                    <ol class="space-y-2 text-sm text-white/90">
    <li><strong>Step 1:</strong> Set up Building Types (Hospitality, Commercial, etc.)</li>
    <li><strong>Step 2:</strong> Set up Themes (Contemporary, Modern, Traditional, etc.)</li>
    <li><strong>Step 3:</strong> Create Sub-Themes under each Theme (e.g., Minimalist Contemporary)</li>
    <li><strong>Step 4:</strong> Create Collections (room areas like Living Room) with max 10 images</li>
    <li><strong>Step 5:</strong> Cross-reference sub-themes to multiple building types!</li>
</ol>
                </div>
                <a href="manage_building_types.php" class="bg-white text-indigo-600 px-6 py-3 rounded-lg font-semibold hover:bg-gray-100 transition-colors whitespace-nowrap ml-4">
                    Get Started <i class="ri-arrow-right-line ml-1"></i>
                </a>
            </div>
        </div>
    </div>
</body>
</html>