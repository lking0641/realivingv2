  <?php
  //mainbody.php
  ob_start();

session_start();
include $includes ['connection'];
include $includes ['checkrole'];
include $includes ['online_status'];

  // Redirect if not logged in
  if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_role'])) {
    header("Location: " . BASE_URL . "login");
    exit();
  }

  // ── Single active session enforcement ──────────────────────────
  // If this browser's session token no longer matches what's in the DB,
  // it means this account logged in somewhere else — kick this session out.
  $sessionCheckStmt = $conn->prepare("SELECT active_session_token FROM account WHERE id = ?");
  $sessionCheckStmt->bind_param("i", $_SESSION['admin_id']);
  $sessionCheckStmt->execute();
  $sessionCheckRow = $sessionCheckStmt->get_result()->fetch_assoc();
  $sessionCheckStmt->close();

  $dbToken = $sessionCheckRow['active_session_token'] ?? null;

  if (
    empty($_SESSION['session_token']) ||
    empty($dbToken) ||
    !hash_equals($dbToken, $_SESSION['session_token'])
  ) {
    session_unset();
    session_destroy();
    header("Location: " . BASE_URL . "login?kicked=1");
    exit();
  }

  $user_role = $_SESSION['admin_role'];

  // Check is_head for designer and technical_designer
  $is_head_user = false;
  if (in_array($user_role, ['designer', 'technical_designer'])) {
    $headStmt = $conn->prepare("SELECT is_head FROM account WHERE id = ?");
    $headStmt->bind_param("i", $_SESSION['admin_id']);
    $headStmt->execute();
    $headRow = $headStmt->get_result()->fetch_assoc();
    $is_head_user = !empty($headRow['is_head']);
  }

  ?>

  <?php
  // Get inquiry counts for badges (only for sales role)
  $pending_appointments = 0;
  $pending_concepts = 0;
  $pending_contacts = 0;
  $pending_projects = 0;

  if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_role'])) {
    $admin_id = $_SESSION['admin_id'];
    $admin_role = $_SESSION['admin_role'];

    if (in_array($admin_role, ['sales', 'superadmin', 'admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6'])) {
      $apt_query = ($admin_role === 'superadmin')
        ? "SELECT COUNT(*) as count FROM appointments WHERE status='pending'"
        : "SELECT COUNT(*) as count FROM appointments WHERE status='pending' AND assigned_to = $admin_id";
      $apt_result = $conn->query($apt_query);
      if ($apt_result)
        $pending_appointments = $apt_result->fetch_assoc()['count'] ?? 0;

      $concept_query = ($admin_role === 'superadmin')
        ? "SELECT COUNT(*) as count FROM concept_inquiries WHERE status='pending'"
        : "SELECT COUNT(*) as count FROM concept_inquiries WHERE status='pending' AND assigned_to = $admin_id";
      $concept_result = $conn->query($concept_query);
      if ($concept_result)
        $pending_concepts = $concept_result->fetch_assoc()['count'] ?? 0;

      $contact_query = ($admin_role === 'superadmin')
        ? "SELECT COUNT(*) as count FROM contact WHERE status='pending'"
        : "SELECT COUNT(*) as count FROM contact WHERE status='pending' AND assigned_to = $admin_id";
      $contact_result = $conn->query($contact_query);
      if ($contact_result)
        $pending_contacts = $contact_result->fetch_assoc()['count'] ?? 0;

      $project_query = ($admin_role === 'superadmin')
        ? "SELECT COUNT(*) as count FROM project_inquiries WHERE status='pending'"
        : "SELECT COUNT(*) as count FROM project_inquiries WHERE status='pending' AND assigned_to = $admin_id";
      $project_result = $conn->query($project_query);
      if ($project_result)
        $pending_projects = $project_result->fetch_assoc()['count'] ?? 0;
    }
  }

  // ── TD pending approvals for approver roles ──────────────────────────────
  $td_pending_approvals = 0;
  if (isset($_SESSION['admin_id']) && in_array($_SESSION['admin_role'], ['general_manager', 'operational_manager', 'technical_designer'])) {
    $tdApprId = $_SESSION['admin_id'];
    $tdApprStmt = $conn->prepare("
          SELECT COUNT(*) FROM td_attachment_approvals la
          WHERE la.approver_id = ? AND la.status = 'pending'
          AND la.requested_at IS NOT NULL
          AND NOT EXISTS (
              SELECT 1 FROM td_revision_log rl
              WHERE rl.client_id = la.client_id
              AND rl.area = la.area
              AND rl.status = 'pending'
              AND (
                  (la.room_unit_number IS NULL AND rl.room_unit_number IS NULL)
                  OR rl.room_unit_number = la.room_unit_number
              )
          )
      ");
    $tdApprStmt->bind_param("i", $tdApprId);
    $tdApprStmt->execute();
    $td_pending_approvals = (int) $tdApprStmt->get_result()->fetch_row()[0];
  }

  // ── TD remark needed count (for assigned TD only) ─────────────────────────
  $td_remark_needed = false;
  if (isset($_SESSION['admin_id']) && $_SESSION['admin_role'] === 'technical_designer') {
    $tdRemarkId = $_SESSION['admin_id'];
    $tdRemarkStmt = $conn->prepare("
          SELECT COUNT(DISTINCT la.client_id) FROM layout_approvals la
          INNER JOIN user_info u ON u.id = la.client_id
          WHERE u.technical_designer_id = ?
          AND (la.td_remark IS NULL OR la.td_remark = '')
          AND la.requested_at IS NOT NULL
      ");
    $tdRemarkStmt->bind_param("i", $tdRemarkId);
    $tdRemarkStmt->execute();
    $td_remark_needed = (int) $tdRemarkStmt->get_result()->fetch_row()[0];
  }
  ?>

  <?php
  // Resolves whichever avatar (Google or uploaded) is active for a user row,
  // falling back to an initial-letter circle if neither exists.
  // $row must include: full_name, profile_picture, google_picture, avatar_source
  function renderAvatarHtml($row, $class = 'adm-avatar')
  {
    $avatarUrl = null;

    if (($row['avatar_source'] ?? 'custom') === 'google' && !empty($row['google_picture'])) {
      $avatarUrl = $row['google_picture'];
    } elseif (!empty($row['profile_picture'])) {
      $avatarUrl = BASE_URL . $row['profile_picture'];
    }

    if ($avatarUrl) {
      return '<img src="' . htmlspecialchars($avatarUrl) . '" class="' . $class . '" style="object-fit:cover;">';
    }

    $initial = strtoupper(substr($row['full_name'] ?: '?', 0, 1));
    return '<div class="' . $class . '">' . htmlspecialchars($initial) . '</div>';
  }

  function hasAccess($role, $section)
  {
    $permissions = [
      'general_manager' => ['manager_dashboard', 'technical_approval_management', 'sales_controller', 'role_controller'],
      'operational_manager' => ['manager_dashboard', 'technical_approval_management', 'sales_controller', 'role_controller'],
      'sales' => ['sales_dashboard', 'content_management', 'inquiry_management', 'quotation_management', 'sales_tracker', 'spinwheel_management'],
      'designer' => ['designer_dashboard', 'designer_site_visit', 'designer_2d3d', 'designer_quotation', 'designer_client_tracker', 'sales_product'],
      'technical_designer' => ['technical_designer_dashboard', 'technical_designer_management', 'technical_designer_quotation'],
      'accounting' => ['accounting_dashboard'],
      'project_coordinator' => ['project_dashboard', 'project_timeline', 'project_coordinator_quotation', 'ps_sales_tracker'],
    ];
    return isset($permissions[$role]) && in_array($section, $permissions[$role]);
  }

  // ── Map route slugs → nav section key ────────────────────────────────────
  // These MUST match the keys used in your $routes array (index.php),
  // not the underlying .php filenames — the router passes us the slug.
  function getNavSectionByFile($slug, $role = '')
  {
    $groups = [

      // ── Sales ────────────────────────────────────────────────────
      'sales_dashboard' => [
        'sales-dashboard',
      ],

      // ── Spin to Win ─────────────────────────────────────────────────
      'spinwheel_management' => [
        'spinwheel-registrations-dashboard',
      ],

      // ── Content Management ────────────────────────────────────────
      'home_management' => [
        'home-setting',
        'hero-view',
        'inquire-image',
        'ads-view',
        'services-view',
      ],

      'project_management' => [
        'projects-dashboard',
        'projects-view',
      ],

      'gallery_management' => [
        'gallery-dashboard',
        'manage-building-types',
        'manage-themes',
        'manage-collection-details',
        'manage-collections',
      ],

      'concept_management' => [
        'concept-dashboard',
        'concept-manage-header',
        'concept-manage-styles',
        'concept-manage-carousel',
      ],

      'news_management' => [
        'news-dashboard',
        'news-manage',
        'news-manage-header',
      ],

      // ── Product ───────────────────────────────────────────────────
      'sales_product' => [
        'choose',
        'view-products',
        'add-product',
        'edit-product',
        'add-details',
        'fixed-sized-setting',
        'link-product-addons',
        'view-addons',
      ],

      // ── Inquiry ───────────────────────────────────────────────────
      'appointment_management' => [
        'appointment-dashboard',
        'appointment-clients',
      ],

      'concept_inquiry' => [
        'concept-inquiries-dashboard',
        'concept-inquiries-clients',
      ],

      'contact_inquiry' => [
        'contact-dashboard',
        'contact-clients',
      ],

      'project_inquiry' => [
        'project-inquiries-dashboard',
        'project-inquiries-manage',
        'project-inquiries-clients',
      ],

      // ── Quotation ─────────────────────────────────────────────────
      'quotation_management' => [
        'quotation-list',
        'quotation-items',
        'quotation-product-details',
        'computation-list',
      ],

      'sales_tracker' => [
        'client-tracker-list',
        'stage-files',
        'td-layout-list', // sales sees this as part of Client Tracker
      ],

      // ── Designer ──────────────────────────────────────────────────
      'designer_dashboard' => [
        'all-clients-tracker-list',
        'site-visit-manager',
      ],
      'designer_client_tracker' => [
        'client-tracker-list',
      ],
      'designer_site_visit' => [
        'designer-clients-list',
      ],
      'designer_2d3d' => [
        'designer-layout-list',
      ],
      'designer_quotation' => [
        'quotation-list',
      ],

      // ── Technical Designer ────────────────────────────────────────
      'technical_designer_management' => [
        'td-layout-list',
      ],
      'technical_designer_quotation' => [
        'quotation-list',
      ],

      // ── Manager ───────────────────────────────────────────────────
      'manager_dashboard' => [
        'manager-status-tracker',
      ],
      'sales_controller' => [
        'stage-permissions-controller',
      ],
      'role_controller' => [
        'role-permissions-controller',
      ],

      // ── Project Coordinator ───────────────────────────────────────
      'project_timeline' => [
        'coordinator-timeline',
      ],

    ];

    // Role-based override for shared pages
    $roleOverrides = [
      'unified-project-tracker' => [
        'designer' => 'designer_dashboard',
        'technical_designer' => 'technical_designer_management',
        'sales' => 'sales_tracker',
      ],
      'stage-files' => [
        'designer' => 'designer_dashboard',
        'technical_designer' => 'technical_designer_management',
        'sales' => 'sales_tracker',
      ],
      'designer-2d3d-layout' => [
        'designer' => 'designer_dashboard',
        'technical_designer' => 'technical_designer_management'
      ],
      'designer-attachments' => [
        'designer' => 'designer_dashboard',
        'technical_designer' => 'technical_designer_management'
      ],
      'designer-attachment-area' => [
        'designer' => 'designer_dashboard',
        'technical_designer' => 'technical_designer_management'
      ],
      'designer-attachment-upload' => [
        'designer' => 'designer_dashboard',
        'technical_designer' => 'technical_designer_management'
      ],
    ];

    if (isset($roleOverrides[$slug][$role])) {
      return $roleOverrides[$slug][$role];
    }

    // Only accept a match if the current role actually has this section
    // (prevents a shared slug like 'quotation-list' resolving to the
    // wrong role's section).
    foreach ($groups as $section => $slugs) {
      if (in_array($slug, $slugs) && hasAccess($role, $section)) {
        return $section;
      }
    }

    // Fallback: unfiltered match, in case hasAccess() permissions
    // haven't been updated yet for a newly-added section.
    foreach ($groups as $section => $slugs) {
      if (in_array($slug, $slugs)) {
        return $section;
      }
    }
    return null;
  }

  // Detect active section from the route slug the router resolved.
  // Falls back to PHP_SELF only if a page bypasses the router.
  $_current_route_slug = $GLOBALS['current_route_slug'] ?? basename($_SERVER['PHP_SELF']);
  $_current_nav_section = getNavSectionByFile($_current_route_slug, $user_role);

  // Build absolute URL for inquiry counts AJAX
  $_is_local = (isset($_SERVER['HTTP_HOST']) && (
    $_SERVER['HTTP_HOST'] === 'localhost' ||
    str_starts_with($_SERVER['HTTP_HOST'], '127.0.0.1') ||
    str_starts_with($_SERVER['HTTP_HOST'], '192.168.')
  ));
  $_protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $_host = $_SERVER['HTTP_HOST'];
  $_inquiry_counts_url = BASE_URL . 'get-inquiry-counts';
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
    <style>

      body {
        font-family: 'Poppins', sans-serif;
        background-color: #F8FAFC;
      }

      .nav-link {
        position: relative;
        transition: all 0.3s ease;
        font-weight: 500;
      }

      .nav-link::after {
        content: '';
        position: absolute;
        width: 0;
        height: 2px;
        bottom: -6px;
        left: 50%;
        transform: translateX(-50%);
        background-color: #3B82F6;
        transition: width 0.3s ease;
        border-radius: 2px;
      }

      .nav-link:hover::after,
      .nav-link.active::after {
        width: 70%;
      }

      .loading-animation {
        stroke-dasharray: 150;
        stroke-dashoffset: 150;
        animation: dash 1.5s ease-in-out infinite alternate;
      }

      @keyframes dash {
        from {
          stroke-dashoffset: 150;
        }

        to {
          stroke-dashoffset: 0;
        }
      }

      .dropdown {
        opacity: 0;
        transform: translateY(10px);
        transition: opacity 0.3s ease, transform 0.3s ease;
        visibility: hidden;
      }

      .dropdown.show {
        opacity: 1;
        transform: translateY(0);
        visibility: visible;
      }

      .mobile-menu {
        transform: translateX(100%);
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: -4px 0 15px rgba(0, 0, 0, 0.1);
      }

      .mobile-menu.active {
        transform: translateX(0);
      }

      .mobile-overlay {
        opacity: 0;
        transition: opacity 0.3s ease;
      }

      .mobile-overlay.active {
        opacity: 1;
      }

      .mobile-menu::-webkit-scrollbar {
        width: 6px;
      }

      .mobile-menu::-webkit-scrollbar-track {
        background: #f1f5f9;
      }

      .mobile-menu::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 3px;
      }

      .hover-scale {
        transition: transform 0.2s ease;
      }

      .hover-scale:hover {
        transform: scale(1.05);
      }

      .avatar-glow {
        position: relative;
      }

      .avatar-glow::after {
        content: '';
        position: absolute;
        top: -2px;
        left: -2px;
        right: -2px;
        bottom: -2px;
        border-radius: 9999px;
        background: linear-gradient(45deg, #3B82F6, #10B981);
        z-index: -1;
        opacity: 0;
        transition: opacity 0.3s ease;
      }

      .avatar-glow:hover::after {
        opacity: 1;
      }

      .avatar-glow:hover {
        box-shadow: 0 0 15px rgba(59, 130, 246, 0.5);
      }

      .nav-badge {
        position: absolute;
        top: -4px;
        right: -8px;
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        color: white;
        font-size: 10px;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 10px;
        box-shadow: 0 2px 6px rgba(239, 68, 68, 0.4);
        min-width: 18px;
        text-align: center;
        animation: pulse-badge 2s ease-in-out infinite;
      }

      @keyframes pulse-badge {

        0%,
        100% {
          transform: scale(1);
        }

        50% {
          transform: scale(1.15);
        }
      }

      .mobile-dropdown-content {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease-out, padding 0.3s ease-out;
        padding-top: 0;
        padding-bottom: 0;
      }

      .mobile-dropdown-content.show {
        max-height: 300px;
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
      }

      .mobile-dropdown-arrow {
        transition: transform 0.3s ease;
      }

      .mobile-dropdown-arrow.rotated {
        transform: rotate(180deg);
      }

      .mobile-overlay {
        display: none;
      }

      .mobile-overlay.active {
        display: block;
        opacity: 1;
      }

      @media screen and (max-width: 374px) {
        nav {
          height: 60px !important;
        }

        #mobileMenu {
          width: 85% !important;
        }
      }

      @media screen and (min-width: 375px) and (max-width: 639px) {
        nav {
          height: 64px !important;
        }

        #mobileMenu {
          width: 80% !important;
        }
      }

      @media screen and (min-width: 640px) and (max-width: 767px) {
        nav {
          height: 68px !important;
        }

        #mobileMenu {
          width: 320px;
        }
      }

      @media screen and (min-width: 768px) and (max-width: 1023px) {
        nav {
          height: 72px !important;
        }

        #mobileMenu {
          width: 350px;
        }
      }

      @media screen and (min-width: 1536px) {
        .max-w-7xl {
          max-width: 1400px !important;
        }

        nav {
          height: 80px !important;
        }
      }

      @media print {
        .header {
          position: static !important;
          box-shadow: none !important;
        }

        .nav-link,
        #profileButton,
        #mobileMenuButton {
          display: none !important;
        }
      }

      @media (max-width: 768px) {
    #notifDropdown {
      position: fixed !important;
      left: 50% !important;
      right: auto !important;
      transform: translateX(-50%) !important;
      top: 70px !important;
      width: calc(100vw - 2rem) !important;
    }
  }

      .dropdown::-webkit-scrollbar {
        width: 6px;
      }

      .dropdown::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
      }

      .dropdown::-webkit-scrollbar-thumb {
        background: #d1d5db;
        border-radius: 10px;
      }

      .dropdown.with-scroll {
        max-height: 400px;
        overflow-y: auto;
      }
    </style>
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

          <!-- ============================================================ -->
          <!--                   DESKTOP NAVIGATION                         -->
          <!-- ============================================================ -->
          <div class="hidden lg:flex lg:items-center lg:space-x-8">

            <!-- ===== SALES ===== -->
            <?php if (hasAccess($user_role, 'sales_dashboard')): ?>
              <a href="<?= BASE_URL ?>sales-dashboard" data-section="sales_dashboard"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-speed-up-line text-lg"></i>
                  <span>Dashboard</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'content_management')): ?>
              <div class="relative group">
                <button onclick="toggleDropdown('clientDropdown')"
                  class="nav-link flex items-center text-dark hover:text-primary text-sm">
                  <div class="flex items-center space-x-1">
                    <i class="ri-layout-masonry-line text-lg"></i>
                    <span>Content</span>
                    <i class="ri-arrow-down-s-line ml-1 transition-transform duration-300 group-hover:rotate-180"></i>
                  </div>
                </button>
                <div id="clientDropdown"
                  class="dropdown absolute bg-white shadow-dropdown rounded-lg p-2 mt-2 space-y-1 z-50 w-52 border border-gray-100">
                  <a href="<?= BASE_URL ?>home-setting" data-section="home_management"
                    class="block px-4 py-2.5 text-sm hover:bg-blue-50 hover:text-primary rounded-md flex items-center space-x-2 transition-colors">
                    <i class="ri-home-gear-line"></i><span>Home Management</span>
                  </a>
                  <a href="projects-dashboard" data-section="project_management"
                    class="block px-4 py-2.5 text-sm hover:bg-blue-50 hover:text-primary rounded-md flex items-center space-x-2 transition-colors">
                    <i class="ri-building-4-line"></i><span>Projects Management</span>
                  </a>
                  <a href="gallery-dashboard" data-section="gallery_management"
                    class="block px-4 py-2.5 text-sm hover:bg-blue-50 hover:text-primary rounded-md flex items-center space-x-2 transition-colors">
                    <i class="ri-image-2-line"></i><span>Rooms Management</span>
                  </a>
                  <a href="concept-dashboard" data-section="concept_management"
                    class="block px-4 py-2.5 text-sm hover:bg-blue-50 hover:text-primary rounded-md flex items-center space-x-2 transition-colors">
                    <i class="ri-lightbulb-line"></i><span>Concept Management</span>
                  </a>
                  <a href="news-dashboard" data-section="news_management"
                    class="block px-4 py-2.5 text-sm hover:bg-blue-50 hover:text-primary rounded-md flex items-center space-x-2 transition-colors">
                    <i class="ri-newspaper-line"></i><span>News Management</span>
                  </a>
                </div>
              </div>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'sales_dashboard')): ?>
              <a href="choose" data-section="sales_product"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-store-2-line text-lg"></i>
                  <span>Product</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'inquiry_management')): ?>
              <div class="relative group">
                <button onclick="toggleDropdown('inquiryDropdown')"
                  class="nav-link flex items-center text-dark hover:text-primary text-sm">
                  <div class="flex items-center space-x-1 relative">
                    <i class="ri-mail-open-line text-lg"></i>
                    <span>Inquiry</span>
                    <i class="ri-arrow-down-s-line ml-1 transition-transform duration-300 group-hover:rotate-180"></i>
                    <?php $total_pending = $pending_appointments + $pending_concepts + $pending_contacts + $pending_projects; ?>
                    <span id="nav-badge-total"
                      class="nav-badge<?php echo $total_pending > 0 ? '' : ' hidden'; ?>"><?php echo $total_pending; ?></span>
                  </div>
                </button>
                <div id="inquiryDropdown"
                  class="dropdown absolute bg-white shadow-dropdown rounded-lg p-2 mt-2 space-y-1 z-50 w-56 border border-gray-100">
                  <a href="appointment-dashboard"
                    data-section="appointment_management"
                    class="block px-4 py-2.5 text-sm hover:bg-blue-50 hover:text-primary rounded-md flex items-center justify-between transition-colors">
                    <div class="flex items-center space-x-2"><i class="ri-calendar-check-line"></i><span>Appointments</span>
                    </div>
                    <span id="nav-badge-appointments"
                      class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-red-500 rounded-full<?php echo $pending_appointments > 0 ? '' : ' hidden'; ?>"><?php echo $pending_appointments; ?></span>
                  </a>
                  <a href="concept-inquiries-dashboard"
                    data-section="concept_inquiry"
                    class="block px-4 py-2.5 text-sm hover:bg-blue-50 hover:text-primary rounded-md flex items-center justify-between transition-colors">
                    <div class="flex items-center space-x-2"><i class="ri-palette-line"></i><span>Concepts</span></div>
                    <span id="nav-badge-concepts"
                      class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-red-500 rounded-full<?php echo $pending_concepts > 0 ? '' : ' hidden'; ?>"><?php echo $pending_concepts; ?></span>
                  </a>
                  <a href="contact-dashboard"
                    data-section="contact_inquiry"
                    class="block px-4 py-2.5 text-sm hover:bg-blue-50 hover:text-primary rounded-md flex items-center justify-between transition-colors">
                    <div class="flex items-center space-x-2"><i class="ri-contacts-line"></i><span>Contacts</span></div>
                    <span id="nav-badge-contacts"
                      class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-red-500 rounded-full<?php echo $pending_contacts > 0 ? '' : ' hidden'; ?>"><?php echo $pending_contacts; ?></span>
                  </a>
                  <a href="project-inquiries-dashboard"
                    data-section="project_inquiry"
                    class="block px-4 py-2.5 text-sm hover:bg-blue-50 hover:text-primary rounded-md flex items-center justify-between transition-colors">
                    <div class="flex items-center space-x-2"><i class="ri-building-line"></i><span>Projects</span></div>
                    <span id="nav-badge-projects"
                      class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-red-500 rounded-full<?php echo $pending_projects > 0 ? '' : ' hidden'; ?>"><?php echo $pending_projects; ?></span>
                  </a>
                </div>
              </div>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'quotation_management')): ?>
              <a href="quotation-list" data-section="quotation_management"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-file-list-3-line text-lg"></i>
                  <span>Quotation</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'sales_tracker')): ?>
              <a href="client-tracker-list" data-section="sales_tracker"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-map-pin-time-line text-lg"></i>
                  <span>Client Tracker</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'spinwheel_management')): ?>
              <a href="spinwheel-registrations-dashboard"
                data-section="spinwheel_management" class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-disc-line text-lg"></i>
                  <span>Spin to Win</span>
                </div>
              </a>
            <?php endif; ?>

            <!-- ===== DESIGNER ===== -->
            <?php if (hasAccess($user_role, 'designer_dashboard') && $is_head_user): ?>
              <a href="all-clients-tracker-list"
                data-section="designer_dashboard" class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-speed-up-line text-lg"></i>
                  <span>Dashboard</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'sales_product') && $is_head_user): ?>
              <a href="choose" data-section="sales_product"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-store-2-line text-lg"></i>
                  <span>Product</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'designer_site_visit')): ?>
              <a href="designer-clients-list"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-map-pin-user-line text-lg"></i>
                  <span>Site Visit</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'designer_dashboard') && !$is_head_user): ?>
              <a href="all-clients-tracker-list"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-speed-up-line text-lg"></i>
                  <span>Designer Client</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'designer_2d3d')): ?>
              <a href="designer-layout-list"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-pencil-ruler-2-line text-lg"></i>
                  <span>2D / 3D</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'designer_quotation')): ?>
              <a href="quotation-list"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-file-list-3-line text-lg"></i>
                  <span>Quotation</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'designer_client_tracker')): ?>
              <a href="client-tracker-list" data-section="designer_client_tracker"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-map-pin-time-line text-lg"></i>
                  <span>Client Tracker</span>
                </div>
              </a>
            <?php endif; ?>

            <!-- ===== TECHNICAL DESIGNER ===== -->
            <?php if (hasAccess($user_role, 'technical_designer_dashboard') && $is_head_user): ?>
              <a href="all-clients-tracker-list"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-speed-up-line text-lg"></i>
                  <span>Dashboard</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'technical_designer_management')): ?>
              <a href="td-layout-list"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1 relative">
                  <i class="ri-settings-3-line text-lg"></i>
                  <span>Technical Management</span>
                  <?php $td_total_badge = $td_pending_approvals + $td_remark_needed; ?>
                  <span id="nav-badge-td"
                    class="nav-badge<?php echo $td_total_badge > 0 ? '' : ' hidden'; ?>"><?= $td_total_badge ?></span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'technical_designer_dashboard') && !$is_head_user): ?>
              <a href="all-clients-tracker-list"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-speed-up-line text-lg"></i>
                  <span>Technical Designer Client</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'technical_designer_quotation') && $is_head_user): ?>
              <a href="quotation-list"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-file-list-3-line text-lg"></i>
                  <span>Quotation</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'technical_designer_dashboard') && $is_head_user): ?>
              <a href="client-tracker-list"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-map-pin-time-line text-lg"></i>
                  <span>Client Tracker</span>
                </div>
              </a>
            <?php endif; ?>

            <!-- ===== ACCOUNTING ===== -->
            <?php if (hasAccess($user_role, 'accounting_dashboard')): ?>
              <a href="all-clients-tracker-list"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-speed-up-line text-lg"></i>
                  <span>Dashboard</span>
                </div>
              </a>
            <?php endif; ?>

            <!-- ===== MANAGER ===== -->
            <?php if (hasAccess($user_role, 'manager_dashboard')): ?>
              <a href="manager-status-tracker"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-speed-up-line text-lg"></i>
                  <span>Dashboard</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'technical_approval_management')): ?>
              <a href="td-layout-list"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1 relative">
                  <i class="ri-settings-3-line text-lg"></i>
                  <span>Technical Management</span>
                  <span id="nav-badge-td-mgr"
                    class="nav-badge<?php echo $td_pending_approvals > 0 ? '' : ' hidden'; ?>"><?= $td_pending_approvals ?></span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'sales_controller')): ?>
              <a href="stage-permissions-controller"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-shield-user-line text-lg"></i>
                  <span>Sales Controller</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'role_controller')): ?>
              <a href="role-permissions-controller"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-user-settings-line text-lg"></i>
                  <span>Role Controller</span>
                </div>
              </a>
            <?php endif; ?>


            <!-- ===== PROJECT COORDINATOR ===== -->
            <?php if (hasAccess($user_role, 'project_dashboard')): ?>
              <a href="all-clients-tracker-list"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-speed-up-line text-lg"></i>
                  <span>Dashboard</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'project_timeline')): ?>
              <a href="coordinator-timeline"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-settings-3-line text-lg"></i>
                  <span>Timeline Management</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'project_coordinator_quotation')): ?>
              <a href="quotation-list" data-section="quotation_management"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-file-list-3-line text-lg"></i>
                  <span>Quotation</span>
                </div>
              </a>
            <?php endif; ?>

            <?php if (hasAccess($user_role, 'ps_sales_tracker')): ?>
              <a href="client-tracker-list" data-section="sales_tracker"
                class="nav-link text-dark hover:text-primary text-sm transition-colors">
                <div class="flex items-center space-x-1">
                  <i class="ri-map-pin-time-line text-lg"></i>
                  <span>Client Tracker</span>
                </div>
              </a>
            <?php endif; ?>

          </div>
          <!-- ============================================================ -->
          <!--                END DESKTOP NAVIGATION                        -->
          <!-- ============================================================ -->

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
    class="hidden absolute right-0 mt-3 w-96 bg-white rounded-lg shadow-dropdown z-50 border border-gray-100 overflow-hidden"
    id="notifDropdown">
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

      <!-- ACCOUNT SETTINGS MODAL -->
      <div id="account-settings-modal" class="fixed inset-0 z-[9999] flex items-center justify-center hidden">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="closeAccountSettings()"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden"
          style="font-family: 'Poppins', sans-serif;">

          <!-- Modal Header -->
          <div class="px-8 pt-8 pb-6 bg-gradient-to-r from-blue-500 to-indigo-600">
            <div class="flex items-center justify-between mb-4">
              <h2 class="text-white text-xl font-bold tracking-wide">Account Settings</h2>
              <button onclick="closeAccountSettings()" class="text-white/70 hover:text-white transition-colors">
                <i class="ri-close-line text-2xl"></i>
              </button>
            </div>
            <div class="flex items-center gap-3">
              <div class="w-12 h-12 rounded-full flex items-center justify-center bg-white/20 overflow-hidden js-avatar-slot">
                <i class="ri-user-line text-white text-xl"></i>
              </div>
              <div>
                <p id="modal-display-name" class="text-white font-semibold text-sm">Loading...</p>
                <span id="modal-role-badge"
                  class="inline-block px-3 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider mt-1 bg-white/20 text-white/90 border border-white/30">—</span>
              </div>
            </div>
          </div>

          <!-- Modal Body -->
          <div class="px-8 py-6 overflow-y-auto max-h-[65vh]">
            <div id="account-settings-alert" class="hidden mb-4 p-3 rounded-lg text-sm font-medium"></div>

            <form id="account-settings-form" onsubmit="saveAccountSettings(event)">
              <!-- Full Name -->
              <div class="mb-4">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Full Name</label>
                <input type="text" id="settings-fullname" name="full_name" required
                  class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all"
                  onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'"
                  placeholder="Enter your full name">
              </div>

              <!-- Email -->
              <div class="mb-4">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Email Address</label>
                <input type="email" id="settings-email" name="email" autocomplete="username" required
                  class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all"
                  onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'"
                  placeholder="Enter your email">
              </div>

              <!-- Divider -->
              <div class="flex items-center gap-3 my-5">
                <div class="flex-1 h-px bg-gray-200"></div>
                <span class="text-xs text-gray-400 uppercase tracking-widest font-semibold">Change Password</span>
                <div class="flex-1 h-px bg-gray-200"></div>
              </div>

              <!-- Current Password -->
              <div class="mb-4">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Current Password <span
                    class="text-red-400 normal-case font-normal">* required to change password</span></label>
                <div class="relative">
                  <input type="password" id="settings-current-password" name="current_password"
                    autocomplete="current-password"
                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all pr-12"
                    onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'"
                    placeholder="Enter your current password">
                  <button type="button" onclick="togglePassVis('settings-current-password','tog-icon-0')"
                    class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700">
                    <i id="tog-icon-0" class="ri-eye-off-line text-lg"></i>
                  </button>
                </div>
              </div>

              <!-- New Password -->
              <div class="mb-4">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">New Password <span
                    class="text-gray-400 normal-case font-normal">(leave blank to keep current)</span></label>
                <div class="relative">
                  <input type="password" id="settings-password" name="new_password" autocomplete="new-password"
                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all pr-12"
                    onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'"
                    placeholder="Enter new password">
                  <button type="button" onclick="togglePassVis('settings-password','tog-icon-1')"
                    class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700">
                    <i id="tog-icon-1" class="ri-eye-off-line text-lg"></i>
                  </button>
                </div>
              </div>

              <!-- Confirm Password -->
              <div class="mb-6">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Confirm New
                  Password</label>
                <div class="relative">
                  <input type="password" id="settings-confirm-password" name="confirm_password"
                    autocomplete="new-password"
                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all pr-12"
                    onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'"
                    placeholder="Confirm new password">
                  <button type="button" onclick="togglePassVis('settings-confirm-password','tog-icon-2')"
                    class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700">
                    <i id="tog-icon-2" class="ri-eye-off-line text-lg"></i>
                  </button>
                </div>
              </div>

              <!-- Profile Picture -->
              <div class="mb-6">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Profile Picture</label>
                <div class="flex items-center gap-4 mb-3">
                  <img id="avatar-preview" src="" class="w-16 h-16 rounded-full object-cover border-2 border-gray-200 hidden">
                  <div id="avatar-preview-fallback" class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center">
                    <i class="ri-user-line text-2xl text-gray-400"></i>
                  </div>
                  <label for="avatar-upload" class="cursor-pointer text-xs font-bold px-3 py-2 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100">
                    Upload Photo
                  </label>
                  <input type="file" id="avatar-upload" name="profile_picture" accept="image/png,image/jpeg,image/webp" class="hidden" onchange="previewAvatar(this)">
                </div>

                <div id="avatar-source-choice" class="hidden space-y-2">
                  <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="radio" name="avatar_source" value="google"> Use my Google photo
                  </label>
                  <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="radio" name="avatar_source" value="custom"> Use my uploaded photo
                  </label>
                </div>
              </div>

              <!-- E-Signature Upload -->
              <div class="mb-6">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">
                  E-Signature <span class="text-gray-400 normal-case font-normal">(PNG only, max 2MB)</span>
                </label>

                <div class="border-2 border-gray-200 rounded-xl overflow-hidden">
                  <!-- Preview existing -->
                  <div id="sig-preview-wrap" class="hidden">
                    <div class="flex items-center justify-between px-4 py-2 bg-gray-50 border-b border-gray-200">
                      <span class="text-xs text-gray-400 uppercase tracking-wider font-semibold">Current Signature</span>
                      <span class="text-xs text-green-600 font-medium flex items-center gap-1">
                        <i class="ri-checkbox-circle-fill"></i> Saved
                      </span>
                    </div>
                    <div class="flex items-center justify-center p-4 bg-white min-h-[80px]">
                      <img id="sig-preview-img" src="" alt="E-Signature" class="max-h-16 max-w-full object-contain">
                    </div>
                    <div class="h-px bg-gray-200"></div>
                  </div>

                  <!-- Upload input -->
                  <label for="sig-upload"
                    class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-blue-50 transition-all group">
                    <div
                      class="w-9 h-9 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0 group-hover:bg-blue-200 transition-colors">
                      <i class="ri-upload-cloud-2-line text-lg text-blue-500"></i>
                    </div>
                    <div>
                      <span id="sig-filename"
                        class="block text-sm text-gray-600 group-hover:text-blue-600 font-medium transition-colors">Click
                        to upload PNG signature</span>
                      <span class="text-xs text-gray-400">Transparent background recommended</span>
                    </div>
                    <i
                      class="ri-arrow-right-s-line ml-auto text-gray-300 group-hover:text-blue-400 transition-colors"></i>
                  </label>
                </div>
                <input type="file" id="sig-upload" name="e_signature" accept=".png,image/png" class="hidden"
                  onchange="previewSignature(this)">
              </div>

              <!-- Team / Digital Business Card -->
              <div class="mb-6 border-2 border-gray-200 rounded-xl p-4">
                <div class="flex items-center justify-between mb-4">
                  <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider">Show me on team card</label>
                    <p class="text-xs text-gray-400 mt-1">Appears on the website's "Meet the Team" section</p>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" id="settings-show-card" name="show_team_card" value="1" class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:bg-blue-500 transition-colors"></div>
                    <div class="absolute left-1 top-1 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-5"></div>
                  </label>
                </div>

                <div class="mb-3">
                  <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Position <span class="text-gray-400 normal-case font-normal">(optional)</span></label>
                  <input type="text" id="settings-position" name="position" placeholder="e.g. Lead Interior Designer"
                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all"
                    onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'">
                </div>

                <div class="mb-3">
                  <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Contact Number <span class="text-gray-400 normal-case font-normal">(optional)</span></label>
                  <input type="text" id="settings-contact-number" name="contact_number" placeholder="0917 000 0000"
                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all"
                    onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'">
                </div>

                <div class="mb-3">
                  <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Gmail <span class="text-gray-400 normal-case font-normal">(optional, can differ from login email)</span></label>
                  <input type="email" id="settings-social-gmail" name="social_gmail" placeholder="name@gmail.com"
                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all"
                    onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'">
                </div>

                <div class="mb-3">
                  <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">WeChat ID <span class="text-gray-400 normal-case font-normal">(optional)</span></label>
                  <input type="text" id="settings-social-wechat" name="social_wechat" placeholder="WeChat ID"
                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all"
                    onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'">
                </div>

                <div class="mb-3">
                  <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Viber <span class="text-gray-400 normal-case font-normal">(optional)</span></label>
                  <input type="text" id="settings-social-viber" name="social_viber" placeholder="0917 000 0000"
                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm text-gray-800 focus:outline-none transition-all"
                    onfocus="this.style.borderColor='#3B82F6'" onblur="this.style.borderColor='#e5e7eb'">
                </div>

                <!-- WeChat: ID + its own QR -->
                <div>
                  <div class="flex items-center gap-4 mb-2">
                    <img id="wechat-qr-preview" src="" class="w-16 h-16 rounded-lg object-cover border-2 border-gray-200 hidden">
                    <div id="wechat-qr-preview-fallback" class="w-16 h-16 rounded-lg bg-gray-100 flex items-center justify-center">
                      <i class="ri-qr-code-line text-2xl text-gray-400"></i>
                    </div>
                    <label for="wechat-qr-upload" class="cursor-pointer text-xs font-bold px-3 py-2 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100">
                      Upload WeChat QR
                    </label>
                    <input type="file" id="wechat-qr-upload" name="wechat_qr_image" accept="image/png,image/jpeg,image/webp" class="hidden" onchange="previewPlatformQr(this, 'wechat')">
                    <button type="button" id="wechat-qr-remove" onclick="removePlatformQr('wechat')" class="hidden text-xs font-bold px-3 py-2 rounded-lg bg-red-50 text-red-600 hover:bg-red-100">
                      Remove
                    </button>
                  </div>
                </div>

                <!-- Viber: number + its own QR -->
                <div>
                  <div class="flex items-center gap-4 mb-2">
                    <img id="viber-qr-preview" src="" class="w-16 h-16 rounded-lg object-cover border-2 border-gray-200 hidden">
                    <div id="viber-qr-preview-fallback" class="w-16 h-16 rounded-lg bg-gray-100 flex items-center justify-center">
                      <i class="ri-qr-code-line text-2xl text-gray-400"></i>
                    </div>
                    <label for="viber-qr-upload" class="cursor-pointer text-xs font-bold px-3 py-2 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100">
                      Upload Viber QR
                    </label>
                    <input type="file" id="viber-qr-upload" name="viber_qr_image" accept="image/png,image/jpeg,image/webp" class="hidden" onchange="previewPlatformQr(this, 'viber')">
                    <button type="button" id="viber-qr-remove" onclick="removePlatformQr('viber')" class="hidden text-xs font-bold px-3 py-2 rounded-lg bg-red-50 text-red-600 hover:bg-red-100">
                      Remove
                    </button>
                  </div>
                </div>
              </div>

              <!-- Google Account Link -->
              <div class="mb-6">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Google Sign-In</label>
                <div id="google-link-status" class="border-2 border-gray-200 rounded-xl px-4 py-3 flex items-center justify-between">
                  <div class="flex items-center gap-3">
                    <svg width="20" height="20" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                      <path fill="#FFC107" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z"/>
                      <path fill="#FF3D00" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z"/>
                      <path fill="#4CAF50" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z"/>
                      <path fill="#1976D2" d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571c0.001-0.001,0.002-0.001,0.003-0.002l6.19,5.238C36.971,39.205,44,34,44,24C44,22.659,43.862,21.35,43.611,20.083z"/>
                    </svg>
                    <div>
                      <p id="google-link-label" class="text-sm font-medium text-gray-700">Loading…</p>
                      <p id="google-link-email" class="text-xs text-gray-400"></p>
                    </div>
                  </div>
                  <button type="button" id="google-link-btn" onclick="handleGoogleLinkClick()"
                    class="text-xs font-bold px-3 py-1.5 rounded-lg transition-colors">
                    ...
                  </button>
                </div>
              </div>

              <!-- Save Button -->
              <button type="submit" id="save-settings-btn"
                class="w-full py-3 rounded-xl text-white font-bold text-sm uppercase tracking-wider transition-all hover:opacity-90 active:scale-95 bg-gradient-to-r from-blue-500 to-indigo-600">
                Save Changes
              </button>
            </form>
          </div>
        </div>
      </div>

      <!-- Mobile Overlay -->
      <div id="mobileMenuOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 mobile-overlay" style="display:none;">
      </div>

      <!-- Mobile Menu -->
      <div id="mobileMenu" class="fixed top-0 right-0 h-full w-80 bg-white shadow-2xl mobile-menu z-50 overflow-y-auto">

        <!-- Mobile Header -->
        <div class="sticky top-0 bg-white z-10 border-b shadow-sm">
          <div class="flex justify-between items-center p-4 bg-gradient-to-r from-blue-50 to-indigo-50">
            <div class="flex items-center space-x-3">
              <img src="<?= BASE_URL ?>logo/picart.png" alt="Logo" class="h-10 object-cover">
              <div>
                <span class="font-semibold text-gray-800 block">Realiving</span>
                <span class="text-xs text-gray-500">Menu</span>
              </div>
            </div>
            <button id="closeMenu"
              class="w-10 h-10 flex items-center justify-center rounded-full hover:bg-red-50 hover:text-red-500 transition-colors">
              <i class="ri-close-line text-2xl"></i>
            </button>
          </div>
        </div>

        <!-- Mobile User Profile -->
        <div class="p-4 bg-gradient-to-r from-blue-500 to-indigo-600 text-white">
          <div class="flex items-center space-x-3">
            <?php $adminInitial = isset($_SESSION['admin_email']) ? strtoupper(substr($_SESSION['admin_email'], 0, 1)) : 'A'; ?>
            <div
              class="w-14 h-14 rounded-full bg-white/20 backdrop-blur-sm flex items-center justify-center text-white text-xl font-bold border-2 border-white/50 overflow-hidden js-avatar-slot">
              <?= $adminInitial ?>
            </div>
            <div class="flex-1">
              <?php if (isset($_SESSION['admin_email'])): ?>
                <p class="text-sm font-medium text-white/90">Logged in as:</p>
                <p class="text-sm font-semibold text-white truncate"><?= htmlspecialchars($_SESSION['admin_email']) ?></p>
              <?php endif; ?>
              <?php if (isset($_SESSION['admin_role'])): ?>
                <p class="text-xs text-white/70 mt-1 capitalize">
                  <?= str_replace('_', ' ', htmlspecialchars($_SESSION['admin_role'])) ?>
                </p>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Mobile Notification Bell -->
        <?php if (in_array($user_role, ['general_manager', 'operational_manager', 'designer', 'technical_designer', 'accounting', 'superadmin', 'project_coordinator'])): ?>
        <div class="px-2 pt-3">
          <button id="mobileNotifBellButton"
            class="w-full flex items-center justify-between px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg transition-all duration-200 border border-gray-200">
            <div class="flex items-center space-x-3">
              <i class="ri-notification-3-line text-lg"></i>
              <span class="font-medium">Notifications</span>
            </div>
            <span id="mobileNotifBellBadge"
              class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-red-500 rounded-full hidden">0</span>
          </button>
          <div id="mobileNotifList" class="mobile-dropdown-content bg-blue-50/50 rounded-lg mt-1"></div>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!--                   MOBILE MENU ITEMS                          -->
        <!-- ============================================================ -->
        <div class="py-3 px-2">

          <!-- ===== SALES MOBILE ===== -->
          <?php if (hasAccess($user_role, 'sales_dashboard')): ?>
            <a href="<?= BASE_URL ?>sales-dashboard" data-section="sales_dashboard"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-speed-up-line text-lg"></i><span>Dashboard</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'content_management')): ?>
            <div>
              <button
                class="mobile-dropdown-button w-full text-left px-4 py-3 flex justify-between items-center text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg transition-all duration-200 mb-1"
                data-target="clientMobileDropdown">
                <div class="flex items-center space-x-3"><i class="ri-layout-masonry-line text-lg"></i><span>Content</span>
                </div>
                <i class="ri-arrow-down-s-line mobile-dropdown-arrow"></i>
              </button>
              <div id="clientMobileDropdown" class="mobile-dropdown-content bg-blue-50/50 rounded-lg mx-2">
                <div class="pl-8 py-2 space-y-1">
                  <a href="../../realiving_admin/sales/home_settings_dashboard.php" data-section="home_management"
                    class="block px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-white/50 rounded-md transition-colors flex items-center space-x-2"><i
                      class="ri-home-gear-line"></i><span>Home Management</span></a>
                  <a href="projects-dashboard" data-section="project_management"
                    class="block px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-white/50 rounded-md transition-colors flex items-center space-x-2"><i
                      class="ri-building-4-line"></i><span>Projects Management</span></a>
                  <a href="gallery_dashboard" data-section="gallery_management"
                    class="block px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-white/50 rounded-md transition-colors flex items-center space-x-2"><i
                      class="ri-image-2-line"></i><span>Rooms Management</span></a>
                  <a href="concept-dashboard" data-section="concept_management"
                    class="block px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-white/50 rounded-md transition-colors flex items-center space-x-2"><i
                      class="ri-lightbulb-line"></i><span>Concept Management</span></a>
                  <a href="news-dashboard" data-section="news_management"
                    class="block px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-white/50 rounded-md transition-colors flex items-center space-x-2"><i
                      class="ri-newspaper-line"></i><span>News Management</span></a>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'sales_dashboard')): ?>
            <a href="choose" data-section="sales_product"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-store-2-line text-lg"></i><span>Product</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'inquiry_management')): ?>
            <div>
              <button
                class="mobile-dropdown-button w-full text-left px-4 py-3 flex justify-between items-center text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg transition-all duration-200 mb-1"
                data-target="inquiryMobileDropdown">
                <div class="flex items-center space-x-3 relative">
                  <i class="ri-mail-open-line text-lg"></i><span>Inquiry</span>
                  <?php $total_pending = $pending_appointments + $pending_concepts + $pending_contacts + $pending_projects; ?>
                  <span id="mob-badge-total"
                    class="inline-flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-red-500 rounded-full ml-2<?php echo $total_pending > 0 ? '' : ' hidden'; ?>"><?php echo $total_pending; ?></span>
                </div>
                <i class="ri-arrow-down-s-line mobile-dropdown-arrow"></i>
              </button>
              <div id="inquiryMobileDropdown" class="mobile-dropdown-content bg-blue-50/50 rounded-lg mx-2">
                <div class="pl-8 py-2 space-y-1">
                  <a href="appointment-dashboard" data-section="appointment_management"
                    class="block px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-white/50 rounded-md transition-colors flex items-center justify-between">
                    <div class="flex items-center space-x-2"><i class="ri-calendar-check-line"></i><span>Appointments</span>
                    </div>
                    <span id="mob-badge-appointments"
                      class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-red-500 rounded-full<?php echo $pending_appointments > 0 ? '' : ' hidden'; ?>"><?php echo $pending_appointments; ?></span>
                  </a>
                  <a href="concept-inquiries-dashboard"
                    data-section="concept_inquiry"
                    class="block px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-white/50 rounded-md transition-colors flex items-center justify-between">
                    <div class="flex items-center space-x-2"><i class="ri-palette-line"></i><span>Concepts</span></div>
                    <span id="mob-badge-concepts"
                      class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-red-500 rounded-full<?php echo $pending_concepts > 0 ? '' : ' hidden'; ?>"><?php echo $pending_concepts; ?></span>
                  </a>
                  <a href="contact-dashboard"
                    data-section="contact_inquiry"
                    class="block px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-white/50 rounded-md transition-colors flex items-center justify-between">
                    <div class="flex items-center space-x-2"><i class="ri-contacts-line"></i><span>Contacts</span></div>
                    <span id="mob-badge-contacts"
                      class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-red-500 rounded-full<?php echo $pending_contacts > 0 ? '' : ' hidden'; ?>"><?php echo $pending_contacts; ?></span>
                  </a>
                  <a href="project-inquiries-dashboard"
                    data-section="project_inquiry"
                    class="block px-3 py-2 text-sm text-gray-600 hover:text-primary hover:bg-white/50 rounded-md transition-colors flex items-center justify-between">
                    <div class="flex items-center space-x-2"><i class="ri-building-line"></i><span>Projects</span></div>
                    <span id="mob-badge-projects"
                      class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-red-500 rounded-full<?php echo $pending_projects > 0 ? '' : ' hidden'; ?>"><?php echo $pending_projects; ?></span>
                  </a>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'quotation_management')): ?>
            <a href="quotation-list" data-section="quotation_management"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-file-list-3-line text-lg"></i><span>Quotation</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'sales_tracker')): ?>
            <a href="client-tracker-list" data-section="sales_tracker"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-map-pin-time-line text-lg"></i><span>Client Tracker</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'spinwheel_management')): ?>
            <a href="spinwheel-registrations-dashboard" data-section="spinwheel_management"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-disc-line text-lg"></i><span>Spin to Win</span>
            </a>
          <?php endif; ?>

          <!-- ===== DESIGNER MOBILE ===== -->
          <?php if (hasAccess($user_role, 'designer_dashboard') && $is_head_user): ?>
            <a href="all-clients-tracker-list"
              data-section="designer_dashboard"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-speed-up-line text-lg"></i><span>Dashboard</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'sales_product') && $is_head_user): ?>
            <a href="choose" data-section="sales_product"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-store-2-line text-lg"></i><span>Product</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'designer_site_visit')): ?>
            <a href="designer-clients-list"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-map-pin-user-line text-lg"></i><span>Site Visit</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'designer_dashboard') && !$is_head_user): ?>
            <a href="all-clients-tracker-list"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-speed-up-line text-lg"></i><span>Designer Client</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'designer_2d3d')): ?>
            <a href="designer-layout-list"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-pencil-ruler-2-line text-lg"></i><span>2D / 3D</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'designer_quotation')): ?>
            <a href="quotation-list"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-file-list-3-line text-lg"></i><span>Quotation</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'designer_client_tracker')): ?>
            <a href="client-tracker-list" data-section="designer_client_tracker"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-map-pin-time-line text-lg"></i><span>Client Tracker</span>
            </a>
          <?php endif; ?>

          <!-- ===== TECHNICAL DESIGNER MOBILE ===== -->
          <?php if (hasAccess($user_role, 'technical_designer_dashboard') && $is_head_user): ?>
            <a href="all-clients-tracker-list"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-speed-up-line text-lg"></i><span>Dashboard</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'technical_designer_management')): ?>
            <a href="td-layout-list"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center justify-between transition-all duration-200 mb-1">
              <div class="flex items-center space-x-3">
                <i class="ri-settings-3-line text-lg"></i><span>Technical Management</span>
              </div>
              <?php $td_total_badge_mob = $td_pending_approvals + $td_remark_needed; ?>
              <span id="mob-badge-td"
                class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-red-500 rounded-full<?php echo $td_total_badge_mob > 0 ? '' : ' hidden'; ?>"><?= $td_total_badge_mob ?></span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'technical_designer_dashboard') && !$is_head_user): ?>
            <a href="all-clients-tracker-list"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-speed-up-line text-lg"></i><span>Technical Designer Client</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'technical_designer_quotation') && $is_head_user): ?>
            <a href="quotation-list"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-file-list-3-line text-lg"></i><span>Quotation</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'technical_designer_dashboard') && $is_head_user): ?>
            <a href="client-tracker-list"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-map-pin-time-line text-lg"></i><span>Client Tracker</span>
            </a>
          <?php endif; ?>

          <!-- ===== ACCOUNTING MOBILE ===== -->
          <?php if (hasAccess($user_role, 'accounting_dashboard')): ?>
            <a href="client-tracker-list"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-speed-up-line text-lg"></i><span>Dashboard</span>
            </a>
          <?php endif; ?>

          <!-- ===== MANAGER MOBILE ===== -->
          <?php if (hasAccess($user_role, 'manager_dashboard')): ?>
            <a href="manager-status-tracker"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-speed-up-line text-lg"></i><span>Dashboard</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'technical_approval_management')): ?>
            <a href="td-layout-list"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center justify-between transition-all duration-200 mb-1">
              <div class="flex items-center space-x-3">
                <i class="ri-settings-3-line text-lg"></i><span>Technical Management</span>
              </div>
              <span id="mob-badge-td-mgr"
                class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-red-500 rounded-full<?php echo $td_pending_approvals > 0 ? '' : ' hidden'; ?>"><?= $td_pending_approvals ?></span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'sales_controller')): ?>
            <a href="stage-permissions-controller"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-shield-user-line text-lg"></i><span>Sales Controller</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'role_controller')): ?>
            <a href="role-permissions-controller"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-user-settings-line text-lg"></i><span>Role Controller</span>
            </a>
          <?php endif; ?>

          <!-- ===== PROJECT COORDINATOR MOBILE ===== -->
          <?php if (hasAccess($user_role, 'project_dashboard')): ?>
            <a href="all-clients-tracker-list"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-speed-up-line text-lg"></i><span>Dashboard</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'project_timeline')): ?>
            <a href="coordinator-timeline"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-settings-3-line text-lg"></i><span>Timeline Management</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'project_coordinator_quotation')): ?>
            <a href="quotation-list" data-section="quotation_management"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-file-list-3-line text-lg"></i><span>Quotation</span>
            </a>
          <?php endif; ?>

          <?php if (hasAccess($user_role, 'sales_tracker')): ?>
            <a href="client-tracker-list" data-section="ps_sales_tracker"
              class="block px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-primary rounded-lg flex items-center space-x-3 transition-all duration-200 mb-1">
              <i class="ri-map-pin-time-line text-lg"></i><span>Client Tracker</span>
            </a>
          <?php endif; ?>

          <!-- Logout -->
          <div class="border-t border-gray-200 mt-4 pt-4 px-2 pb-4">
            <a href="logout.php"
              class="block px-4 py-3 text-gray-700 hover:bg-red-50 hover:text-red-500 rounded-lg flex items-center space-x-3 transition-all duration-200 border border-red-200">
              <i class="ri-logout-box-line text-lg"></i>
              <span class="font-medium">Sign Out</span>
            </a>
          </div>

        </div>
        <!-- ============================================================ -->
        <!--                END MOBILE MENU ITEMS                         -->
        <!-- ============================================================ -->

      </div>
    </header>

    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const mobileMenuButton = document.getElementById('mobileMenuButton');
        const closeMenu = document.getElementById('closeMenu');
        const mobileMenu = document.getElementById('mobileMenu');
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

        function openMobileMenu() {
          mobileMenu.classList.add('active');
          mobileMenuOverlay.style.display = 'block';
          setTimeout(() => mobileMenuOverlay.classList.add('active'), 10);
          document.body.style.overflow = 'hidden';
        }

        function closeMobileMenu() {
          mobileMenu.classList.remove('active');
          mobileMenuOverlay.classList.remove('active');
          setTimeout(() => { mobileMenuOverlay.style.display = 'none'; }, 300);
          document.body.style.overflow = '';
        }

        if (mobileMenuButton) mobileMenuButton.addEventListener('click', openMobileMenu);
        if (closeMenu) closeMenu.addEventListener('click', closeMobileMenu);
        if (mobileMenuOverlay) mobileMenuOverlay.addEventListener('click', closeMobileMenu);

        // Profile dropdown
        const profileButton = document.getElementById('profileButton');
        const profileDropdown = document.getElementById('profileDropdown');
        if (profileButton && profileDropdown) {
          profileButton.addEventListener('click', function (e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('hidden');
          });
        }

        // Mobile dropdowns
        document.querySelectorAll('.mobile-dropdown-button').forEach(button => {
          button.addEventListener('click', function (e) {
            e.preventDefault();
            const targetId = this.dataset.target;
            const dropdownContent = document.getElementById(targetId);
            const arrow = this.querySelector('.mobile-dropdown-arrow');
            if (dropdownContent && arrow) {
              dropdownContent.classList.toggle('show');
              arrow.classList.toggle('rotated');
            }
          });
        });

        // Close on outside click
        document.addEventListener('click', function (e) {
          if (profileDropdown && profileButton && !profileButton.contains(e.target) && !profileDropdown.contains(e.target)) {
            profileDropdown.classList.add('hidden');
          }
          document.querySelectorAll('.dropdown').forEach(dropdown => {
            if (!dropdown.parentElement.contains(e.target)) {
              dropdown.classList.remove('show');
            }
          });
        });

        // Escape key
        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') {
            closeMobileMenu();
            if (profileDropdown) profileDropdown.classList.add('hidden');
            document.querySelectorAll('.dropdown').forEach(dd => dd.classList.remove('show'));
          }
        });
      });

      function toggleDropdown(dropdownId) {
        const dropdown = document.getElementById(dropdownId);
        if (dropdown) {
          dropdown.classList.toggle('show');
          document.querySelectorAll('.dropdown').forEach(dd => {
            if (dd.id !== dropdownId) dd.classList.remove('show');
          });
        }
      }

      // ── Account Settings Modal ──────────────────────────────────────
      function openAccountSettings() {
        // Close profile dropdown first
        const profileDropdown = document.getElementById('profileDropdown');
        if (profileDropdown) profileDropdown.classList.add('hidden');

        document.getElementById('account-settings-modal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        loadAccountData();
      }

      function closeAccountSettings() {
        document.getElementById('account-settings-modal').classList.add('hidden');
        document.body.style.overflow = '';
        document.getElementById('account-settings-alert').classList.add('hidden');
        document.getElementById('settings-current-password').value = '';
        document.getElementById('settings-password').value = '';
        document.getElementById('settings-confirm-password').value = '';
      }

      function togglePassVis(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        if (input.type === 'password') {
          input.type = 'text';
          icon.className = 'ri-eye-line text-lg';
        } else {
          input.type = 'password';
          icon.className = 'ri-eye-off-line text-lg';
        }
      }

      function previewSignature(input) {
        const file = input.files[0];
        if (!file) return;
        if (file.type !== 'image/png') {
          showSettingsAlert('Only PNG files are allowed for e-signature.', 'error');
          input.value = '';
          return;
        }
        document.getElementById('sig-filename').textContent = file.name;
        const reader = new FileReader();
        reader.onload = e => {
          const wrap = document.getElementById('sig-preview-wrap');
          document.getElementById('sig-preview-img').src = e.target.result;
          wrap.classList.remove('hidden');
        };
        reader.readAsDataURL(file);
      }

            function previewPlatformQr(input, platform) {
        const file = input.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = e => {
          document.getElementById(platform + '-qr-preview').src = e.target.result;
          document.getElementById(platform + '-qr-preview').classList.remove('hidden');
          document.getElementById(platform + '-qr-preview-fallback').classList.add('hidden');
          document.getElementById(platform + '-qr-remove').classList.remove('hidden');
        };
        reader.readAsDataURL(file);
      }

      function removePlatformQr(platform) {
        if (!confirm('Remove this QR code image?')) return;

        fetch('<?php echo BASE_URL ?>delete-team-qr', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'platform=' + encodeURIComponent(platform)
        })
          .then(r => r.json())
          .then(data => {
            if (data.success) {
              document.getElementById(platform + '-qr-preview').src = '';
              document.getElementById(platform + '-qr-preview').classList.add('hidden');
              document.getElementById(platform + '-qr-preview-fallback').classList.remove('hidden');
              document.getElementById(platform + '-qr-remove').classList.add('hidden');
              document.getElementById(platform + '-qr-upload').value = '';
              showSettingsAlert(platform === 'wechat' ? 'WeChat QR removed.' : 'Viber QR removed.', 'success');
            } else {
              showSettingsAlert(data.message || 'Failed to remove QR code.', 'error');
            }
          })
          .catch(() => showSettingsAlert('Server error. Please try again.', 'error'));
      }

      function previewAvatar(input) {
        const file = input.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = e => {
          document.getElementById('avatar-preview').src = e.target.result;
          document.getElementById('avatar-preview').classList.remove('hidden');
          document.getElementById('avatar-preview-fallback').classList.add('hidden');
          document.querySelector('input[name="avatar_source"][value="custom"]').checked = true;
          document.getElementById('avatar-source-choice').classList.remove('hidden');
        };
        reader.readAsDataURL(file);
      }

      // Cache the raw Google/uploaded URLs so switching the radio can preview
      // instantly without another server round-trip.
      let cachedGooglePicture = null;
      let cachedProfilePicture = null;

      function loadAccountData() {
        fetch('<?php echo BASE_URL ?>get-account')
          .then(r => r.json())
          .then(data => {
            if (data.success) {
              document.getElementById('settings-fullname').value = data.full_name || '';
              document.getElementById('settings-email').value = data.email || '';
              document.getElementById('modal-display-name').textContent = data.full_name || 'User';
              if (data.e_signature) {
                document.getElementById('sig-preview-img').src = data.e_signature;
                document.getElementById('sig-preview-wrap').classList.remove('hidden');
              }

              document.getElementById('settings-show-card').checked = !!Number(data.show_team_card);
              document.getElementById('settings-position').value = data.position || '';
              document.getElementById('settings-contact-number').value = data.contact_number || '';
              document.getElementById('settings-social-gmail').value = data.social_gmail || '';
              document.getElementById('settings-social-wechat').value = data.social_wechat || '';
              document.getElementById('settings-social-viber').value = data.social_viber || '';
              if (data.wechat_qr_image) {
                document.getElementById('wechat-qr-preview').src = data.wechat_qr_image;
                document.getElementById('wechat-qr-preview').classList.remove('hidden');
                document.getElementById('wechat-qr-preview-fallback').classList.add('hidden');
                document.getElementById('wechat-qr-remove').classList.remove('hidden');
              } else {
                document.getElementById('wechat-qr-remove').classList.add('hidden');
              }
              if (data.viber_qr_image) {
                document.getElementById('viber-qr-preview').src = data.viber_qr_image;
                document.getElementById('viber-qr-preview').classList.remove('hidden');
                document.getElementById('viber-qr-preview-fallback').classList.add('hidden');
                document.getElementById('viber-qr-remove').classList.remove('hidden');
              } else {
                document.getElementById('viber-qr-remove').classList.add('hidden');
              }

              cachedGooglePicture = data.google_picture || null;
              cachedProfilePicture = data.profile_picture || null;

              if (data.avatar_url) {
                document.getElementById('avatar-preview').src = data.avatar_url;
                document.getElementById('avatar-preview').classList.remove('hidden');
                document.getElementById('avatar-preview-fallback').classList.add('hidden');
              }
              if (data.google_linked) {
                document.getElementById('avatar-source-choice').classList.remove('hidden');
                const radio = document.querySelector(`input[name="avatar_source"][value="${data.avatar_source}"]`);
                if (radio) radio.checked = true;
              } else {
                document.getElementById('avatar-source-choice').classList.add('hidden');
              }

              document.getElementById('modal-role-badge').textContent =
                (data.role || 'admin').replace(/_/g, ' ').toUpperCase();

              renderGoogleLinkStatus(data.google_linked, data.google_email);
            } else {
              showSettingsAlert('Could not load account data.', 'error');
            }
          })
          .catch(() => showSettingsAlert('Failed to connect to server.', 'error'));
      }

      // Live-preview instantly when the user switches the radio — before Save is clicked.
      // This only updates the modal preview; the actual header/mobile avatars update on Save,
      // same as how choosing a new photo file only previews locally until you save.
      document.querySelectorAll('input[name="avatar_source"]').forEach(radio => {
        radio.addEventListener('change', function () {
          const url = this.value === 'google' ? cachedGooglePicture : cachedProfilePicture;
          const preview = document.getElementById('avatar-preview');
          const fallback = document.getElementById('avatar-preview-fallback');

          if (url) {
            preview.src = url;
            preview.classList.remove('hidden');
            fallback.classList.add('hidden');
          } else {
            preview.classList.add('hidden');
            fallback.classList.remove('hidden');
          }
        });
      });

      function renderGoogleLinkStatus(isLinked, email) {
        const label = document.getElementById('google-link-label');
        const emailEl = document.getElementById('google-link-email');
        const btn = document.getElementById('google-link-btn');

        if (isLinked) {
          label.textContent = 'Google account linked';
          emailEl.textContent = email || '';
          btn.textContent = 'Unlink';
          btn.className = 'text-xs font-bold px-3 py-1.5 rounded-lg transition-colors bg-red-50 text-red-600 hover:bg-red-100';
          btn.dataset.linked = '1';
        } else {
          label.textContent = 'Not linked';
          emailEl.textContent = 'Link your Google account to sign in without a password';
          btn.textContent = 'Link';
          btn.className = 'text-xs font-bold px-3 py-1.5 rounded-lg transition-colors bg-blue-50 text-blue-600 hover:bg-blue-100';
          btn.dataset.linked = '0';
        }
      }

      function handleGoogleLinkClick() {
        const btn = document.getElementById('google-link-btn');
        if (btn.dataset.linked === '1') {
          if (!confirm('Unlink your Google account? You will no longer be able to sign in with Google until you link it again.')) return;

          btn.disabled = true;
          btn.textContent = 'Unlinking…';
          fetch('<?php echo BASE_URL ?>unlink-google', { method: 'POST' })
            .then(r => r.json())
            .then(data => {
              if (data.success) {
                showSettingsAlert('Google account unlinked.', 'success');
                renderGoogleLinkStatus(false, '');
                refreshAvatarEverywhere();
              } else {
                showSettingsAlert(data.message || 'Failed to unlink.', 'error');
              }
            })
            .catch(() => showSettingsAlert('Server error. Please try again.', 'error'))
            .finally(() => { btn.disabled = false; });
        } else {
          window.location.href = '<?php echo BASE_URL ?>google-link';
        }
      }

      function refreshAvatarEverywhere() {
        fetch('<?php echo BASE_URL ?>get-account')
          .then(r => r.json())
          .then(data => {
            if (!data.success) return;

            const preview = document.getElementById('avatar-preview');
            const fallback = document.getElementById('avatar-preview-fallback');

            if (data.avatar_url) {
              preview.src = data.avatar_url + '?t=' + Date.now();
              preview.classList.remove('hidden');
              fallback.classList.add('hidden');
            } else {
              preview.classList.add('hidden');
              fallback.classList.remove('hidden');
            }

            document.querySelectorAll('.js-avatar-slot').forEach(slot => {
              if (!data.avatar_url) return;
              const bustedUrl = data.avatar_url + '?t=' + Date.now();
              const originalContent = slot.innerHTML;
              const img = document.createElement('img');
              img.src = bustedUrl;
              img.className = 'w-full h-full object-cover rounded-full';
              img.onerror = function () {
                slot.innerHTML = originalContent;
              };
              slot.innerHTML = '';
              slot.appendChild(img);
            });
          })
          .catch(() => {});
      }

      function saveAccountSettings(e) {
        e.preventDefault();
        const currentPass = document.getElementById('settings-current-password').value;
        const newPass = document.getElementById('settings-password').value;
        const confirmPass = document.getElementById('settings-confirm-password').value;

        if (newPass && !currentPass) {
          showSettingsAlert('Please enter your current password to set a new one.', 'error'); return;
        }
        if (newPass && newPass !== confirmPass) {
          showSettingsAlert('Passwords do not match.', 'error'); return;
        }
        if (newPass && newPass.length < 6) {
          showSettingsAlert('Password must be at least 6 characters.', 'error'); return;
        }

        const btn = document.getElementById('save-settings-btn');
        btn.textContent = 'Saving...';
        btn.disabled = true;

        fetch('<?php echo BASE_URL ?>update-account', {
          method: 'POST',
          body: new FormData(document.getElementById('account-settings-form'))
        })
          .then(r => r.json())
          .then(data => {
            if (data.success) {
              showSettingsAlert(data.message || 'Account updated successfully!', 'success');
              document.getElementById('modal-display-name').textContent =
                document.getElementById('settings-fullname').value;
              document.getElementById('settings-current-password').value = '';
              document.getElementById('settings-password').value = '';
              document.getElementById('settings-confirm-password').value = '';

              // Update signature preview if a new one was uploaded
              if (data.e_signature) {
                const previewWrap = document.getElementById('sig-preview-wrap');
                const previewImg = document.getElementById('sig-preview-img');
                const sigFilename = document.getElementById('sig-filename');
                previewImg.src = data.e_signature + '?t=' + Date.now(); // bust cache
                previewWrap.classList.remove('hidden');
                sigFilename.textContent = 'Click to upload PNG signature';
                document.getElementById('sig-upload').value = '';
              }

              // Update avatar everywhere — modal preview + header/mobile slots — without a page refresh.
              // Re-fetch from the server instead of guessing the URL client-side, so we always
              // show whatever the DB actually resolved to (avoids the "blank until refresh" bug).
              document.getElementById('avatar-upload').value = '';
              refreshAvatarEverywhere();
            } else {
              showSettingsAlert(data.message || 'Update failed.', 'error');
            }
          })
          .catch(() => showSettingsAlert('Server error. Please try again.', 'error'))
          .finally(() => { btn.textContent = 'Save Changes'; btn.disabled = false; });
      }

      function showSettingsAlert(message, type) {
        const el = document.getElementById('account-settings-alert');
        el.textContent = message;
        el.className = 'mb-4 p-3 rounded-lg text-sm font-medium ' +
          (type === 'success'
            ? 'bg-green-50 text-green-700 border border-green-200'
            : 'bg-red-50 text-red-700 border border-red-200');
        el.classList.remove('hidden');
        setTimeout(() => el.classList.add('hidden'), 4000);
      }

    </script>

    <!-- PHP passes the detected section into JS cleanly -->
    <div id="navSectionData" data-section="<?= htmlspecialchars($_current_nav_section ?? '') ?>"></div>

    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const activeSection = document.getElementById('navSectionData')?.dataset.section;
        if (!activeSection) return;

        document.querySelectorAll('[data-section]').forEach(function (el) {
          if (el.dataset.section !== activeSection) return;

          const tag = el.tagName.toLowerCase();

          if (tag === 'a') {
            el.classList.add('active');
            el.style.color = '#3B82F6';
            el.style.fontWeight = '700';
            if (el.closest('#mobileMenu')) {
              el.classList.add('bg-blue-50');
              el.style.borderLeft = '3px solid #3B82F6';
              el.style.paddingLeft = '14px';
            }
          }

          if (tag === 'button') {
            el.classList.add('active');
            el.style.color = '#3B82F6';
            el.style.fontWeight = '700';
          }

          const dropdown = el.closest('.dropdown, .mobile-dropdown-content');
          if (dropdown) {
            el.style.color = '#3B82F6';
            el.style.fontWeight = '700';
            el.style.background = '#eff6ff';
            el.style.borderRadius = '6px';
            const trigger = dropdown.previousElementSibling;
            if (trigger) {
              trigger.classList.add('active');
              trigger.style.color = '#3B82F6';
              trigger.style.fontWeight = '700';
            }
          }
        });
      });
    </script>

    <script>
      (function () {
        var hasInquiryAccess = <?php echo json_encode(
          in_array($_SESSION['admin_role'] ?? '', ['sales', 'superadmin', 'admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6'])
        ); ?>;
        if (!hasInquiryAccess) return;

        var countUrl = '<?= htmlspecialchars($_inquiry_counts_url) ?>';

        function updateBadges(data) {
          if (!data || data.error) return;

          var map = {
            appointments: data.appointments || 0,
            concepts: data.concepts || 0,
            contacts: data.contacts || 0,
            projects: data.projects || 0
          };

          // Per-item: desktop + mobile
          ['appointments', 'concepts', 'contacts', 'projects'].forEach(function (key) {
            var count = map[key];

            var desktopBadge = document.getElementById('nav-badge-' + key);
            if (desktopBadge) {
              desktopBadge.textContent = count;
              count > 0 ? desktopBadge.classList.remove('hidden') : desktopBadge.classList.add('hidden');
            }

            var mobileBadge = document.getElementById('mob-badge-' + key);
            if (mobileBadge) {
              mobileBadge.textContent = count;
              count > 0 ? mobileBadge.classList.remove('hidden') : mobileBadge.classList.add('hidden');
            }
          });

          // Totals
          var total = map.appointments + map.concepts + map.contacts + map.projects;

          var navTotal = document.getElementById('nav-badge-total');
          if (navTotal) {
            navTotal.textContent = total > 99 ? '99+' : total;
            total > 0 ? navTotal.classList.remove('hidden') : navTotal.classList.add('hidden');
          }

          var mobTotal = document.getElementById('mob-badge-total');
          if (mobTotal) {
            mobTotal.textContent = total > 99 ? '99+' : total;
            total > 0 ? mobTotal.classList.remove('hidden') : mobTotal.classList.add('hidden');
          }
        }

        function fetchCounts() {
          fetch(countUrl, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(updateBadges)
            .catch(function () { });
        }

        fetchCounts();
        setInterval(fetchCounts, 15000);

        document.addEventListener('visibilitychange', function () {
          if (!document.hidden) fetchCounts();
        });
      })();
    </script>

    <script>
      (function () {
        var hasNotifAccess = <?php echo json_encode(
          in_array($_SESSION['admin_role'] ?? '', ['general_manager', 'operational_manager', 'designer', 'technical_designer', 'accounting', 'superadmin', 'project_coordinator', 'sales'])
        ); ?>;
        if (!hasNotifAccess) return;

        var notifUrl = <?php echo json_encode(BASE_URL . 'get-user-notificaitons'); ?>;

        var notifBellButton = document.getElementById('notifBellButton');
        var notifDropdown = document.getElementById('notifDropdown');
        var notifBellBadge = document.getElementById('notifBellBadge');
        var notifList = document.getElementById('notifList');
        var notifDropdownCount = document.getElementById('notifDropdownCount');

        var mobileNotifBellButton = document.getElementById('mobileNotifBellButton');
        var mobileNotifBellBadge = document.getElementById('mobileNotifBellBadge');
        var mobileNotifList = document.getElementById('mobileNotifList');

        function timeAgo(dateStr) {
          var diff = Math.floor((Date.now() - new Date(dateStr.replace(' ', 'T'))) / 1000);
          if (diff < 60) return 'just now';
          if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
          if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
          return Math.floor(diff / 86400) + 'd ago';
        }

        function colorClasses(color) {
          var map = {
            amber: { bg: 'bg-amber-50', text: 'text-amber-600', icon: 'bg-amber-100' },
            red: { bg: 'bg-red-50', text: 'text-red-600', icon: 'bg-red-100' },
            blue: { bg: 'bg-blue-50', text: 'text-blue-600', icon: 'bg-blue-100' }
          };
          return map[color] || map.amber;
        }

        function renderNotifItem(n) {
          var c = colorClasses(n.color);
          return '<a href="' + n.link + '" class="block px-4 py-3 hover:bg-gray-50 transition-colors">' +
            '<div class="flex items-start gap-3">' +
            '<div class="w-9 h-9 rounded-full ' + c.icon + ' flex items-center justify-center flex-shrink-0 mt-0.5">' +
  '<i class="fas ' + n.icon + ' ' + c.text + '"></i></div>' +
            '<div class="flex-1 min-w-0">' +
            '<p class="text-sm font-semibold text-gray-800 truncate">' + n.title + '</p>' +
            '<p class="text-xs text-gray-500 truncate mt-0.5">' + n.subtitle + '</p>' +
            '<p class="text-[11px] text-gray-400 mt-1">' + timeAgo(n.created_at) + '</p>' +
            '</div></div></a>';
        }

        function renderEmpty() {
          return '<div class="px-4 py-8 text-center text-gray-400 text-sm">' +
            '<i class="ri-checkbox-circle-line text-2xl block mb-2 text-green-400"></i>' +
            'You\'re all caught up!</div>';
        }

        function updateNotifications(data) {
          if (!data || data.error) return;
          var items = data.notifications || [];
          var total = data.total || 0;

          // Desktop badge
          if (notifBellBadge) {
            notifBellBadge.textContent = total > 99 ? '99+' : total;
            total > 0 ? notifBellBadge.classList.remove('hidden') : notifBellBadge.classList.add('hidden');
          }
          if (notifDropdownCount) {
            notifDropdownCount.textContent = total + ' pending';
          }
          if (notifList) {
            notifList.innerHTML = items.length ? items.map(renderNotifItem).join('') : renderEmpty();
          }

          // Mobile badge
          if (mobileNotifBellBadge) {
            mobileNotifBellBadge.textContent = total > 99 ? '99+' : total;
            total > 0 ? mobileNotifBellBadge.classList.remove('hidden') : mobileNotifBellBadge.classList.add('hidden');
          }
          if (mobileNotifList) {
            mobileNotifList.innerHTML = items.length ? items.map(renderNotifItem).join('') : renderEmpty();
          }
        }

        function fetchNotifications() {
          fetch(notifUrl, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(updateNotifications)
            .catch(function () { });
        }

        fetchNotifications();
        setInterval(fetchNotifications, 15000);

        document.addEventListener('visibilitychange', function () {
          if (!document.hidden) fetchNotifications();
        });

        // Toggle desktop dropdown
        if (notifBellButton && notifDropdown) {
          notifBellButton.addEventListener('click', function (e) {
            e.stopPropagation();
            notifDropdown.classList.toggle('hidden');
          });
          document.addEventListener('click', function (e) {
            if (!notifBellButton.contains(e.target) && !notifDropdown.contains(e.target)) {
              notifDropdown.classList.add('hidden');
            }
          });
        }

        // Toggle mobile dropdown
        if (mobileNotifBellButton && mobileNotifList) {
          mobileNotifBellButton.addEventListener('click', function () {
            mobileNotifList.classList.toggle('show');
          });
        }
      })();
    </script>

    <script>
      (function () {
        var hasTdAccess = <?php echo json_encode(
          in_array($_SESSION['admin_role'] ?? '', ['general_manager', 'operational_manager', 'technical_designer'])
        ); ?>;
        if (!hasTdAccess) return;

        // Build URL the same way the inquiry counts URL is built
        var tdCountUrl = <?php echo json_encode(BASE_URL . 'get-td-approval-counts'); ?>;

        function updateTdBadges(data) {
          if (!data || data.error) return;
          var count = (data.td_approvals || 0) + (data.remark_needed || 0);

          ['nav-badge-td', 'nav-badge-td-mgr', 'mob-badge-td', 'mob-badge-td-mgr'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.textContent = count > 99 ? '99+' : count;
            count > 0 ? el.classList.remove('hidden') : el.classList.add('hidden');
          });
        }

        function fetchTdCounts() {
          fetch(tdCountUrl, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(updateTdBadges)
            .catch(function () { });
        }

        fetchTdCounts();
        setInterval(fetchTdCounts, 15000);

        document.addEventListener('visibilitychange', function () {
          if (!document.hidden) fetchTdCounts();
        });
      })();
    </script>

    <script>
  document.addEventListener('DOMContentLoaded', function () {
    const params = new URLSearchParams(window.location.search);
    if (params.get('google_linked') === '1') {
      openAccountSettings();
      setTimeout(() => showSettingsAlert('Google account linked successfully!', 'success'), 400);
      // Clean the URL so refresh doesn't re-trigger this
      window.history.replaceState({}, '', window.location.pathname);
    }
    if (params.get('google_error') === 'already_linked') {
      alert('That Google account is already linked to a different staff account.');
      window.history.replaceState({}, '', window.location.pathname);
    }
  });
</script>

    <!-- Presence heartbeat — keeps last_activity fresh while this tab is open -->
    <script>
      (function () {
        var heartbeatUrl = <?php echo json_encode(BASE_URL . 'heartbeat'); ?>;

        function sendHeartbeat() {
          if (document.hidden) return; // don't ping from a backgrounded tab
          fetch(heartbeatUrl, { method: 'POST', credentials: 'same-origin' }).catch(function () {});
        }

        sendHeartbeat(); // immediately on page load
        setInterval(sendHeartbeat, 30000); // every 30 seconds

        document.addEventListener('visibilitychange', function () {
          if (!document.hidden) sendHeartbeat();
        });
      })();
    </script>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const params = new URLSearchParams(window.location.search);
    if (params.get('google_linked') === '1') {
      openAccountSettings();
      setTimeout(() => showSettingsAlert('Google account linked successfully!', 'success'), 400);
      window.history.replaceState({}, '', window.location.pathname);
    }
    if (params.get('google_error') === 'already_linked') {
      alert('That Google account is already linked to a different staff account.');
      window.history.replaceState({}, '', window.location.pathname);
    }
  });
</script>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    fetch('<?php echo BASE_URL ?>get-account')
      .then(r => r.json())
      .then(data => {
        if (!data.success || !data.avatar_url) return;
        document.querySelectorAll('.js-avatar-slot').forEach(slot => {
          const originalContent = slot.innerHTML; // keep the letter/icon as fallback
          const img = document.createElement('img');
          img.src = data.avatar_url;
          img.className = 'w-full h-full object-cover rounded-full';
          img.onerror = function () {
            slot.innerHTML = originalContent; // broken image → restore letter/icon instead of blank
          };
          slot.innerHTML = '';
          slot.appendChild(img);
        });
      })
      .catch(() => {});
  });
</script>    

  </body>

  </html>