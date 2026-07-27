<?php
//projects_dashboard.php
include $includes ['mainbody'];

// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales']);

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

// Get counts for each category
$all_count = $conn->query("SELECT COUNT(*) as count FROM project")->fetch_assoc()['count'];
$site_count = $conn->query("SELECT COUNT(*) as count FROM project WHERE category = 'site'")->fetch_assoc()['count'];
$residential_count = $conn->query("SELECT COUNT(*) as count FROM project WHERE category = 'residential'")->fetch_assoc()['count'];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Projects Dashboard - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
                    <a href="sales-dashboard" class="text-gray-600 hover:text-primary transition-colors">
                        <i class="ri-arrow-left-line text-xl"></i>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Projects Dashboard</h1>
                        <p class="text-sm text-gray-500">Manage all your projects and categories</p>
                    </div>
                </div>
                <img src="../../realiving_user/images/logo/realiving_logo_hd.png" alt="Logo" class="h-12 object-contain" />
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Category Overview Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <!-- All Projects Card -->
            <a href="projects-view?category=all" class="block group">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300 hover:border-primary">
                    <div class="flex items-start justify-between mb-4">
                        <div class="p-3 bg-indigo-50 rounded-xl group-hover:bg-indigo-100 transition-colors">
                            <i class="ri-folder-line text-3xl text-primary"></i>
                        </div>
                        <i class="ri-arrow-right-line text-xl text-gray-400 group-hover:text-primary transition-colors"></i>
                    </div>
                    <h2 class="text-xl font-semibold text-gray-800 mb-2">All Projects</h2>
                    <p class="text-sm text-gray-500 mb-4">View all projects</p>
                    <div class="pt-4 border-t border-gray-100">
                        <p class="text-3xl font-bold text-gray-800"><?php echo $all_count; ?></p>
                        <p class="text-xs text-gray-500">Total Projects</p>
                    </div>
                </div>
            </a>

            <!-- Site Projects Card -->
            <a href="projects-view?category=site" class="block group">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300 hover:border-amber-500">
                    <div class="flex items-start justify-between mb-4">
                        <div class="p-3 bg-amber-50 rounded-xl group-hover:bg-amber-100 transition-colors">
                            <i class="ri-building-2-line text-3xl text-amber-600"></i>
                        </div>
                        <i class="ri-arrow-right-line text-xl text-gray-400 group-hover:text-amber-600 transition-colors"></i>
                    </div>
                    <h2 class="text-xl font-semibold text-gray-800 mb-2">Site Projects</h2>
                    <p class="text-sm text-gray-500 mb-4">Construction sites</p>
                    <div class="pt-4 border-t border-gray-100">
                        <p class="text-3xl font-bold text-amber-600"><?php echo $site_count; ?></p>
                        <p class="text-xs text-gray-500">Total Projects</p>
                    </div>
                </div>
            </a>

            <!-- Residential Interiors Card -->
            <a href="projects-view?category=residential" class="block group">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300 hover:border-green-500">
                    <div class="flex items-start justify-between mb-4">
                        <div class="p-3 bg-green-50 rounded-xl group-hover:bg-green-100 transition-colors">
                            <i class="ri-home-4-line text-3xl text-green-600"></i>
                        </div>
                        <i class="ri-arrow-right-line text-xl text-gray-400 group-hover:text-green-600 transition-colors"></i>
                    </div>
                    <h2 class="text-xl font-semibold text-gray-800 mb-2">Residential</h2>
                    <p class="text-sm text-gray-500 mb-4">Home interiors</p>
                    <div class="pt-4 border-t border-gray-100">
                        <p class="text-3xl font-bold text-green-600"><?php echo $residential_count; ?></p>
                        <p class="text-xs text-gray-500">Total Projects</p>
                    </div>
                </div>
            </a>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Quick Actions</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="projects-view?action=add" class="flex items-center space-x-3 p-4 border-2 border-dashed border-gray-300 rounded-xl hover:border-primary hover:bg-indigo-50 transition-all group">
                    <div class="p-2 bg-indigo-50 rounded-lg group-hover:bg-indigo-100">
                        <i class="ri-add-line text-2xl text-primary"></i>
                    </div>
                    <div>
                        <p class="font-medium text-gray-800">Add New Project</p>
                        <p class="text-sm text-gray-500">Create a new project</p>
                    </div>
                </a>

                <a href="projects-cabinet-cost-settings" class="flex items-center space-x-3 p-4 border-2 border-dashed border-gray-300 rounded-xl hover:border-primary hover:bg-indigo-50 transition-all group">
                    <div class="p-2 bg-amber-50 rounded-lg group-hover:bg-amber-100">
                        <i class="ri-image-line text-2xl text-amber-600"></i>
                    </div>
                    <div>
                        <p class="font-medium text-gray-800">Cabinet Cost Image</p>
                        <p class="text-sm text-gray-500">Update section image</p>
                    </div>
                </a>

                <a href="projects-view" class="flex items-center space-x-3 p-4 border-2 border-dashed border-gray-300 rounded-xl hover:border-primary hover:bg-indigo-50 transition-all group">
                    <div class="p-2 bg-purple-50 rounded-lg group-hover:bg-purple-100">
                        <i class="ri-list-check text-2xl text-purple-600"></i>
                    </div>
                    <div>
                        <p class="font-medium text-gray-800">View All Projects</p>
                        <p class="text-sm text-gray-500">Manage existing projects</p>
                    </div>
                </a>
            </div>
        </div>
    </div>
</body>
</html>