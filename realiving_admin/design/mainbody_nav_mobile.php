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