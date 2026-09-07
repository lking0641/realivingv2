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