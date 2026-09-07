<?php
//mainbody.php
ob_start();

// ── Load helpers first (getNavSectionByFile is needed by mainbody_data.php) ──
require_once __DIR__ . '/mainbody_helpers.php';

// ── Auth check, session enforcement, badge counts, nav-section detection ──
require_once __DIR__ . '/mainbody_data.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin - Realiving Design Center</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script>
    window.onload = function () {
      const ls = document.getElementById("loadingScreen");
      ls.classList.add("opacity-0");
      setTimeout(() => ls.classList.add("hidden"), 500);
    }
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet">
  <link rel="stylesheet" href="<?php echo BASE_ASSET; ?>assets/css/output.css">
  <?php require __DIR__ . '/mainbody_styles.php'; ?>
</head>

<body>

  <!-- Loading Screen -->
  <div id="loadingScreen"
    class="fixed inset-0 z-50 flex items-center justify-center bg-white transition-opacity duration-500">
    <div class="flex flex-col items-center">
      <svg width="64" height="64" viewBox="0 0 44 44" xmlns="http://www.w3.org/2000/svg">
        <circle class="loading-animation" cx="22" cy="22" r="20" fill="none" stroke="#3B82F6" stroke-width="4" />
      </svg>
      <p class="mt-4 text-lg text-primary font-medium tracking-wide">Loading...</p>
    </div>
  </div>

  <header class="bg-white shadow-nav sticky top-0 z-40 transition-all duration-300">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <nav class="flex items-center justify-between h-20">

        <!-- Logo -->
        <div class="flex items-center space-x-3 p-2">
          <img src="<?= BASE_URL ?>/logo/picart.png" alt="Logo" class="h-10 object-cover hover-scale">
        </div>

        <?php require __DIR__ . '/mainbody_nav_desktop.php'; ?>

        <!-- Right Side Actions -->
        <div class="flex items-center space-x-5">
          <!-- Notification Bell -->
          <?php if (in_array($user_role, ['general_manager', 'operational_manager', 'designer', 'technical_designer', 'accounting', 'superadmin', 'project_coordinator', 'sales'])): ?>
          <div class="relative">
            <button id="notifBellButton"
              class="relative w-10 h-10 flex items-center justify-center text-gray-500 hover:text-primary hover:bg-gray-50 rounded-full transition-colors">
              <i class="ri-notification-3-line text-xl"></i>
              <span id="notifBellBadge"
                class="nav-badge hidden" style="top:2px; right:2px;">0</span>
            </button>
            <div id="notifDropdown"
              class="hidden absolute right-0 mt-3 w-96 bg-white rounded-lg shadow-dropdown z-50 border border-gray-100 overflow-hidden">
              <div class="px-4 py-3 border-b bg-gray-50 flex items-center justify-between">
                <span class="font-semibold text-gray-800 text-sm">Notifications</span>
                <span id="notifDropdownCount" class="text-xs text-gray-500">0 pending</span>
              </div>
              <div id="notifList" class="max-h-96 overflow-y-auto divide-y divide-gray-100">
                <div class="px-4 py-8 text-center text-gray-400 text-sm">
                  <i class="ri-notification-off-line text-2xl block mb-2"></i>
                  Loading...
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Profile Dropdown -->
          <div class="relative hidden md:block">
            <button id="profileButton"
              class="avatar-glow w-10 h-10 flex items-center justify-center bg-gradient-to-br from-blue-100 to-blue-50 rounded-full hover:shadow-md transition-all duration-300 border-2 border-white overflow-hidden js-avatar-slot">
              <i class="ri-user-smile-line text-xl text-primary"></i>
            </button>
            <div id="profileDropdown"
              class="hidden absolute right-0 mt-3 w-72 bg-white rounded-lg shadow-dropdown z-50 border border-gray-100 overflow-hidden">
              <div class="px-4 py-3 border-b bg-gradient-to-r from-blue-50 to-indigo-50">
                <div class="flex items-center space-x-3">
                  <?php $adminInitial = isset($_SESSION['admin_email']) ? strtoupper(substr($_SESSION['admin_email'], 0, 1)) : 'A'; ?>
                  <div
                    class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-lg font-bold overflow-hidden js-avatar-slot">
                    <?= $adminInitial ?>
                  </div>
                  <div>
                    <?php if (isset($_SESSION['admin_email'], $_SESSION['admin_role'])): ?>
                      <div class="mb-1 text-sm text-gray-700">
                        <span class="block">Logged in as:</span>
                        <span class="font-medium"><?= htmlspecialchars($_SESSION['admin_email']) ?></span>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <button onclick="openAccountSettings()"
                class="w-full text-left px-4 py-3 hover:bg-gray-50 flex items-center text-sm group transition-colors">
                <i class="ri-user-settings-line mr-3 text-lg text-gray-500 group-hover:text-primary"></i>
                <span class="group-hover:text-primary">Manage Profile & Settings</span>
              </button>
              <div class="border-t border-gray-100"></div>
              <button onclick="location.href='<?= BASE_URL ?>logout'"
                class="w-full text-left px-4 py-3 hover:bg-red-50 flex items-center text-sm group transition-colors">
                <i class="ri-logout-box-line mr-3 text-lg text-gray-500 group-hover:text-red-500"></i>
                <span class="group-hover:text-red-500">Sign Out</span>
              </button>
            </div>
          </div>

          <!-- Mobile Toggle -->
          <button id="mobileMenuButton"
            class="lg:hidden w-10 h-10 flex items-center justify-center text-gray-500 hover:text-primary">
            <i class="ri-menu-line text-2xl"></i>
          </button>
        </div>
      </nav>
    </div>

    <?php require __DIR__ . '/mainbody_account_modal.php'; ?>

    <?php require __DIR__ . '/mainbody_nav_mobile.php'; ?>

  </header>

  <?php require __DIR__ . '/mainbody_scripts.php'; ?>

</body>

</html>