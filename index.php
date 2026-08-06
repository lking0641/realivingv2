<?php

// ══════════════════════════════════════════════════════
//  ENVIRONMENT DETECTION — localhost vs live
// ══════════════════════════════════════════════════════
require_once __DIR__ . '/config/app_config.php';

// ══════════════════════════════════════════════════════
//  ROUTES — add more as needed
//  'url-slug' => 'realiving_user/folder/file.php'
// ══════════════════════════════════════════════════════
$routes = [
  '' => 'realiving_user/realiving_mainpage/realiving_main.php',        // realivingdesigncenter.com/
  'index.php' => 'realiving_user/realiving_mainpage/realiving_main.php',        // /home

  //PROJECTS
  'projects' => 'realiving_user/realiving_projects/realiving_projects.php',
  'view-projects' => 'realiving_user/realiving_projects/realiving_projects_view.php',

  //CONCEPTS
  'concepts' => 'realiving_user/realiving_concepts/realiving_concepts.php',
  'concept-process' => 'realiving_user/inquiry_form/concept_process.php',

  //ABOUT
  'about' => 'realiving_user/realiving_about/realiving_about.php',

  //SERVICES
  'services' => 'realiving_user/realiving_services/realiving_services.php',

  //MODULAR
  'modular' => 'realiving_user/realiving_modular/realiving_modular.php',

  //NEWS
  'news' => 'realiving_user/realiving_news/realiving_news.php',
  'news-view' => 'realiving_user/realiving_news/realiving_news_view.php',

  //CONTACT
  'contact' => 'realiving_user/realiving_contact/realiving_contact.php',

  //APPOINTMENT
  'appointment' => 'realiving_user/realiving_appointment/realiving_appointment.php',
  'get-booked-dates' => 'realiving_user/realiving_appointment/get_booked_dates.php',
  'submit-appointment' => 'realiving_user/realiving_appointment/submit_appointment.php',

  //SPIN-WHEEL
  'spinwheel-spin' => 'realiving_user/spin-wheel/spinwheel_spin.php',
  'badge-scanner' => 'realiving_user/spin-wheel/badge_scanner.php',
  'spinwheel-verify-claim' => 'realiving-user/spin-wheel/spinwheel_verify_claim.php',
  'spinwheel' => 'realiving_user/spin-wheel/spinwheel.php',

  //ADMIN

  //AUTH
  'login' => 'loginpage/index.php',
  'logout' => 'loginpage/logout.php',

  //SALES
  //SALES DASHBOARD
  'sales-dashboard' => 'realiving_admin/realiving_sales/home_management/sales_dashboard.php',

  //HOME SETTINGS
  'home-setting' => 'realiving_admin/realiving_sales/home-settings/home_settings_dashboard.php',
  'hero-view' => 'realiving_admin/realiving_sales/home-settings/home_settings_hero_view.php',
  'inquire-image' => 'realiving_admin/realiving_sales/home-settings/home_settings_inquire_view.php',
  'ads-view' => 'realiving_admin/realiving_sales/home-settings/home_settings_ads_view.php',
  'services-view' => 'realiving_admin/realiving_sales/home-settings/home_settings_services_view.php',
  'ads-content-view' => 'realiving_admin/realiving_sales/home-settings/home_settings_ads_content_view.php',
  'ads-content-edit' => 'realiving_admin/realiving_sales/home-settings/home_settings_ads_content_edit.php',

  //PRODUCT MANAGEMENT
  'choose' => 'realiving_admin/realiving_sales/product-management/choose.php',
  'view-products' => 'realiving_admin/realiving_sales/product-management/view_products.php',
  'add-product' => 'realiving_admin/realiving_sales/product-management/add_product.php',
  'edit-product' => 'realiving_admin/realiving_sales/product-management/view_products_edit.php',
  'add-details' => 'realiving_admin/realiving_sales/product-management/view_products_add_details.php',
  'fixed-sized-setting' => 'realiving_admin/realiving_sales/product-management/view_products_fixed_size_settings.php',
  'insert-dimension' => 'realiving_admin/realiving_sales/product-management/insert_dimension.php',
  'insert-dimension-edit' => 'realiving_admin/realiving_sales/product-management/insert_dimension_edit.php',
  'link-product-addons' => 'realiving_admin/realiving_sales/product-management/link_product_addons.php',
  'view-addons' => 'realiving_admin/realiving_sales/product-management/view_addons.php',
  'add-addon' => 'realiving_admin/realiving_sales/product-management/add_addon.php',
  'delete-insert-addon' => 'realiving_admin/realiving_sales/product-management/delete_addon.php',
  'edit-addon' => 'realiving_admin/realiving_sales/product-management/edit_addon.php',
  'view-addon-details' => 'realiving_admin/realiving_sales/product-management/view_addon_details.php',
  


  //PROJECT MANAGEMENT
  'projects-dashboard' => 'realiving_admin/realiving_sales/project-management/projects_dashboard.php',
  'projects-view' => 'realiving_admin/realiving_sales/project-management/projects_view.php',
  'project-locations' => 'realiving_admin/realiving_sales/project-management/project_locations.php',
  'project-location-images' => 'realiving_admin/realiving_sales/project-management/project_location_images.php',
  'projects-cabinet-cost-settings' => 'realiving_admin/realiving_sales/project-management/projects_cabinet_cost_settings.php',

  //ROOMS MANAGEMENT
  'gallery-dashboard' => 'realiving_admin/realiving_sales/rooms-management/gallery_dashboard_v2.php',
  'manage-building-types' => 'realiving_admin/realiving_sales/rooms-management/manage_building_types.php',
  'manage-collection-details' => 'realiving_admin/realiving_sales/rooms-management/manage_collection_details.php',
  'manage-collections' => 'realiving_admin/realiving_sales/rooms-management/manage_collections.php',
  'manage-themes' => 'realiving_admin/realiving_sales/rooms-management/manage_themes.php',

  //CONCEPT MANAGEMENT
  'concept-dashboard' => 'realiving_admin/realiving_sales/concept-management/concept_dashboard.php',
  'concept-managed' => 'realiving_admin/realiving_sales/concept-management/concept_managed.php',
  'concept-manage-carousel' => 'realiving_admin/realiving_sales/concept-management/concept_manage_carousel.php',
  'concept-manage-header' => 'realiving_admin/realiving_sales/concept-management/concept_manage_header.php',
  'concept-manage-styles' => 'realiving_admin/realiving_sales/concept-management/concept_manage_styles.php',

  //NEWS MANAGEMENT
  'news-dashboard' => 'realiving_admin/realiving_sales/news-management/news_dashboard.php',
  'news-manage-header' => 'realiving_admin/realiving_sales/news-management/news_manage_header.php',
  'news-manage' => 'realiving_admin/realiving_sales/news-management/news_manage.php',

  //INQUIRIES APPOINTMENT
  'appointment-clients' => 'realiving_admin/realiving_sales/inquiries-appointment/appointment_clients.php',
  'appointment-dashboard' => 'realiving_admin/realiving_sales/inquiries-appointment/appointment_dashboard.php',
  'appointment-mailer' => 'realiving_admin/realiving_sales/inquiries-appointment/appointment_mailer.php',

  //INQUIRIES CONCEPT
  'concept-inquiries-clients' => 'realiving_admin/realiving_sales/inquiries-concept/concept_inquiries_clients.php',
  'concept-inquiries-dashboard' => 'realiving_admin/realiving_sales/inquiries-concept/concept_inquiries_dashboard.php',

  //INQUIRIES CONTACT
  'contact-clients' => 'realiving_admin/realiving_sales/inquiries-contact/contact_clients.php',
  'contact-dashboard' => 'realiving_admin/realiving_sales/inquiries-contact/contact_dashboard.php',
  'contact-get-details' => 'realiving_admin/realiving_sales/inquiries-contact/contact_get_details.php',

  //INQUIRIES PROJECT
  'project-inquiries-clients' => 'realiving_admin/realiving_sales/inquiries-project/project_inquiries_clients.php',
  'project-inquiries-dashboard' => 'realiving_admin/realiving_sales/inquiries-project/project_inquiries_dashboard.php',
  'project-inquiries-manage' => 'realiving_admin/realiving_sales/inquiries-project/project_inquiries_manage.php',

  //INQURIES VIEWER
  'view-client' => 'realiving_admin/realiving_sales/inquiries-viewer/view_client.php',

  //GET ACCOUNT
  'get-account' => 'realiving_admin/realiving-account/get-account.php',
  'update-account' => 'realiving_admin/realiving-account/update-account.php',

  //PRODUCT LIST VIEW
  'allclient' => 'realiving_admin/quotation-management/insert-client/allclient.php',
  'backup-client' => 'realiving_admin/quotation-management/insert-client/backup_client.php',
  'delete-client' => 'realiving_admin/quotation-management/insert-client/delete_client.php',
  'quotation-list' => 'realiving_admin/quotation-management/insert-client/quotation_list.php',
  'update-client-info' => 'realiving_admin/quotation-management/insert-client/update_client_info.php',

  //PRODUCT LIST VIEW
  'quotation-items' => 'realiving_admin/quotation-management/product-list-view/quotation_items.php',
  'quotation-product-details' => 'realiving_admin/quotation-management/product-list-view/quotation_product_details.php',
  'quotation-products-display' => 'realiving_admin/quotation-management/product-list-view/quotation_products_display.php',
  'search-suggestions' => 'realiving_admin/quotation-management/product-list-view/search_suggestions.php',

  //QUOTATION MANAGEMENT
  'computation-list' => 'realiving_admin/quotation-management/quotation-management/computation_list.php',
  'edit-quotation-entry' => 'realiving_admin/quotation-management/quotation-management/edit_quotation_entry.php',
  'export-computation' => 'realiving_admin/quotation-management/quotation-management/export_computation.php',
  'export-quotation' => 'realiving_admin/quotation-management/quotation-management/export_quotation.php',
  'link-addon' => 'realiving_admin/quotation-management/quotation-management/link_addon.php',
  'link-fixed-addon' => 'realiving_admin/quotation-management/quotation-management/link_fixed_addon.php',

  //QUOTATION MANAGEMENT DELETE
  'delete-addon' => 'realiving_admin/quotation-management/quotation-management-delete/delete_addon.php',
  'delete-entry' => 'realiving_admin/quotation-management/quotation-management-delete/delete_entry.php',
  'delete-fixed-addon' => 'realiving_admin/quotation-management/quotation-management-delete/delete_fixed_addon.php',
  'delete-fixed-entry' => 'realiving_admin/quotation-management/quotation-management-delete/delete_fixed_entry.php',

  //QUOTATION MANAGEMENT UPDATE
  'update-addon-entry' => 'realiving_admin/quotation-management/quotation-management-update/update_addon_entry.php',
  'update-computation-lock' => 'realiving_admin/quotation-management/quotation-management-update/update_computation_lock.php',
  'update-computation' => 'realiving_admin/quotation-management/quotation-management-update/update_computation.php',
  'update-discount' => 'realiving_admin/quotation-management/quotation-management-update/update_discount.php',
  'update-fixed-addon' => 'realiving_admin/quotation-management/quotation-management-update/update_fixed_addon.php',
  'update-fixed-computation' => 'realiving_admin/quotation-management/quotation-management-update/update_fixed_computation.php',
  'update-manual-cost' => 'realiving_admin/quotation-management/quotation-management-update/update_manual_cost.php',
  'update-project-cost' => 'realiving_admin/quotation-management/quotation-management-update/update_project_cost.php',
  'update-room-distribution' => 'realiving_admin/quotation-management/quotation-management-update/update_room_distribution.php',
  'update-tracker-mode' => 'realiving_admin/quotation-management/quotation-management-update/update_tracker_mode.php',

  //JS
  'computation-addons' => 'realiving_admin/quotation-management/js/computation_addons.js',
  'computation-core' => 'realiving_admin/quotation-management/js/computation_core.js',
  'computation-entries' => 'realiving_admin/quotation-management/js/computation_entries.js',
  'computation-fixed' => 'realiving_admin/quotation-management/js/computation_fixed.js',
  'computation-ui' => 'realiving_admin/quotation-management/js/computation_ui.js',

  //TRACKER MANAGEMENT

  //TRACKER CLIENT LIST
  'all-clients-tracker-list' => 'realiving_admin/tracker-management/tracker-client-list/all_clients_tracker_list.php',
  'client-tracker-list' => 'realiving_admin/tracker-management/tracker-client-list/client_tracker_list.php',

  //TRACKER MANAGEMENT
  'stage-files' => 'realiving_admin/tracker-management/tracker-management/stage_files.php',
  'unified-project-tracker' => 'realiving_admin/tracker-management/tracker-management/unified_project_tracker.php',
  'esign-pdf-viewer' => 'realiving_admin/tracker-management/tracker-management/esign_pdf_viewer.php',

  //TRACKER PAYMENT
  'add-collection-billing' => 'realiving_admin/tracker-management/tracker-payment/add_collection_billing.php',
  'check-ipo-approved' => 'realiving_admin/tracker-management/tracker-payment/check_ipo_approved.php',
  'payment-tracker' => 'realiving_admin/tracker-management/tracker-payment/payment_tracker.php',
  'review-payment-proof' => 'realiving_admin/tracker-management/tracker-payment/review_payment_proof.php',
  'toggle-payment-split' => 'realiving_admin/tracker-management/tracker-payment/toggle_payment_split.php',
  'upload-ntp' => 'realiving_admin/tracker-management/tracker-payment/upload_ntp.php',
  'upload-payment-proof' => 'realiving_admin/tracker-management/tracker-payment/upload_payment_proof.php',

  //TRACKER STEP
  'apply-esign' => 'realiving_admin/tracker-management/tracker-step/apply_esign.php',
  'approve-reject-stage' => 'realiving_admin/tracker-management/tracker-step/approve_reject_stage.php',
  'chunk-probe' => 'realiving_admin/tracker-management/tracker-step/chunk_probe.php',
  'chunk-upload' => 'realiving_admin/tracker-management/tracker-step/chunk_upload.php',
  'delete-stage-file' => 'realiving_admin/tracker-management/tracker-step/delete_stage_file.php',
  'direct-upload' => 'realiving_admin/tracker-management/tracker-step/direct_upload.php',
  'get-share-approvals' => 'realiving_admin/tracker-management/tracker-step/get_share_approvals.php',
  'internal-po-review' => 'realiving_admin/tracker-management/tracker-step/internal_po_review.php',
  'upload-stage-file' => 'realiving_admin/tracker-management/tracker-step/upload_stage_file.php',

  //TRACKER UPDATE
  'update-accomplishment-amount' => 'realiving_admin/tracker-management/tracker-update/update_accomplishment_amount.php',
  'update-bom-order-status' => 'realiving_admin/tracker-management/tracker-update/update_bom_order_status.php',
  'update-downpayment' => 'realiving_admin/tracker-management/tracker-update/update_downpayment.php',
  'update-item-status' => 'realiving_admin/tracker-management/tracker-update/update_item_status.php',
  'update-payment-schedule' => 'realiving_admin/tracker-management/tracker-update/update_payment_schedule.php',
  'update-payment-status' => 'realiving_admin/tracker-management/tracker-update/update_payment_status.php',
  'update-revision-count' => 'realiving_admin/tracker-management/tracker-update/update_revision_count.php',
  'update-tracker-status' => 'realiving_admin/tracker-management/tracker-update/update_tracker_status.php',
  'update-unit-status' => 'realiving_admin/tracker-management/tracker-update/update_unit_status.php',

  //ITEM-TRACKER
  'get-item-remarks' => 'realiving_admin/tracker-management/item-tracker/get_item_remarks.php',
  'item-tracker' => 'realiving_admin/tracker-management/item-tracker/item_tracker.php',

  //TRACKER-COORDINATOR
  'coordinator-timeline' => 'realiving_admin/tracker-management/tracker-coordinator/coordinator_timeline.php',

  //TRACKER-MANAGER
  'manager-project-detail' => 'realiving_admin/tracker-management/tracker-manager/manager_project_detail.php',
  'manager-site-visit-approval' => 'realiving_admin/tracker-management/tracker-manager/manager_site_visit_approval.php',
  'manager-stage-files' => 'realiving_admin/tracker-management/tracker-manager/manager_stage_files.php',
  'manager-status-tracker' => 'realiving_admin/tracker-management/tracker-manager/manager_status_tracker.php',

  //WHEEL-MANAGEMENT
  'delete-spinwheel' => 'realiving_admin/wheel-management/delete_spinwheel.php',
  'spinwheel-claim-scanner' => 'realiving_admin/wheel-management/spinwheel_claim_scanner.php',
  'spinwheel-mark-claimed' => 'realiving_admin/wheel-management/spinwheel_mark_claimed.php',
  'spinwheel-registrations-dashboard' => 'realiving_admin/wheel-management/spinwheel_registrations_dashboard.php',
  'toggle-spinwheel' => 'realiving_admin/wheel-management/toggle_spinwheel.php',
  'update-pity-settings' => 'realiving_admin/wheel-management/update_pity_settings.php',
  'update-spinwheel-segment' => 'realiving_admin/wheel-management/update_spinwheel_segment.php',

  //DESIGNER-MANAGEMENT

  //SITE-VISIT-MANAGEMENT
  'site-visit-manager' => 'realiving_admin/site-visit-management/site_visit_manager.php',
  'designer-client-detail' => 'realiving_admin/site-visit-management/designer_client_detail.php',
  'designer-clients-list' => 'realiving_admin/site-visit-management/designer_clients_list.php',

  //DESIGNER-MANAGEMENT
  
  //DESIGNER-ATTACHMENT
  'attachment-chunk-upload' => 'realiving_admin/designer-management/designer-attachment/attachment_chunk_upload.php',
  'attachment-delete' => 'realiving_admin/designer-management/designer-attachment/attachment_delete.php',
  'attachment-direct-upload' => 'realiving_admin/designer-management/designer-attachment/attachment_direct_upload.php',
  'attachment-upload-ui-remove' => 'realiving_admin/designer-management/designer-attachment/attachment_upload_ui_remove.php',
  'attachment-upload' => 'realiving_admin/designer-management/designer-attachment/attachment_upload.php',

  //DESIGNER-MANAGEMENT
  'designer-2d3d-layout' => 'realiving_admin/designer-management/designer-management/designer_2d3d_layout.php',
  'designer-attachment-area' => 'realiving_admin/designer-management/designer-management/designer_attachment_area.php',
  'designer-attachment-upload' => 'realiving_admin/designer-management/designer-management/designer_attachment_upload.php',
  'designer-attachments' => 'realiving_admin/designer-management/designer-management/designer_attachments.php',
  'designer-layout-list' => 'realiving_admin/designer-management/designer-management/designer_layout_list.php',

  //DESIGNER-PROCESS
  'get-area-items' => 'realiving_admin/designer-management/designer-process/get_area_items.php',
  'mark-layout-done' => 'realiving_admin/designer-management/designer-process/mark_layout_done.php',
  'request-layout-approval' => 'realiving_admin/designer-management/designer-process/request_layout_approval.php',
  'request-revision' => 'realiving_admin/designer-management/designer-process/request_revision.php',
  'respond-layout-approval' => 'realiving_admin/designer-management/designer-process/respond_layout_approval.php',

  //TECHNICAL-DESIGNER-MANAGEMENT

  //TD-ATTACHMENT
  'td-attachment-area' => 'realiving_admin/technical-designer-management/td-attachment/td_attachment_area.php',
  'td-attachment-chunk-upload' => 'realiving_admin/technical-designer-management/td-attachment/td_attachment_chunk_upload.php',
  'td-attachment-delete' => 'realiving_admin/technical-designer-management/td-attachment/td_attachment_delete.php',
  'td-attachment-direct-upload' => 'realiving_admin/technical-designer-management/td-attachment/td_attachment_direct_upload.php',
  'td-attachment-upload-process' => 'realiving_admin/technical-designer-management/td-attachment/td_attachment_upload_process.php',
  'td-attachment-upload' => 'realiving_admin/technical-designer-management/td-attachment/td_attachment_upload.php',
  'td-attachments' => 'realiving_admin/technical-designer-management/td-attachment/td_attachments.php',

  //TD-MANAGEMENT
  'designer-submit-td-remark' => 'realiving_admin/technical-designer-management/td-management/designer_submit_td_remark.php',
  'td-layout-list' => 'realiving_admin/technical-designer-management/td-management/td_layout_list.php',
  'td-layout' => 'realiving_admin/technical-designer-management/td-management/td_layout.php',

  //TD-PROCESS
  'td-request-approval' => 'realiving_admin/technical-designer-management/td-process/td_request_approval.php',
  'td-request-revision' => 'realiving_admin/technical-designer-management/td-process/td_request_revision.php',
  'td-respond-approval' => 'realiving_admin/technical-designer-management/td-process/td_respond_approval.php',

  //ROLE-CONTROLLER
  'role-permissions-controller' => 'realiving_admin/role-controller/role_permissions_controller.php',
  'get-role-permissions' => 'realiving_admin/role-controller/get_role_permissions.php',
  'save-role-permissions' => 'realiving_admin/role-controller/save_role_permissions.php',

  //SALES-CONTROLLER
  'stage-permissions-controller' => 'realiving_admin/sales-controller/stage_permissions_controller.php',
  'get-stage-permissions' => 'realiving_admin/sales-controller/get_stage_permissions.php',
  'save-stage-permissions' => 'realiving_admin/sales-controller/save_stage_permissions.php',

  //GET-NOTIFICATIONS
  'get-user-notificaitons' => 'realiving_admin/get_notifications/get_user_notifications.php',
  'get-td-approval-counts' => 'realiving_admin/get_notifications/get_td_approval_counts.php',
  'get-inquiry-counts' => 'realiving_admin/get_notifications/get_inquiry_counts.php',
  'get-notifications' => 'realiving_admin/get_notifications/get_notifications.php',

  //INQUIRY-FORM
  'inquiry' => 'realiving_user/inquiry_form/inquiry.php',

  //GOOGLE AUTH
  'google-login' => 'loginpage/google_login.php',
  'google-callback' => 'loginpage/google_callback.php',
  'google-link' => 'loginpage/google_link.php',
  'unlink-google' => 'loginpage/unlink_google.php',

];

// ══════════════════════════════════════════════════════
//  GET THE REQUESTED SLUG
//  Strips leading slash and query string
// ══════════════════════════════════════════════════════
$request_uri = $_SERVER['REQUEST_URI'];

// Remove the base path prefix for local environment
if ($is_local) {
  $request_uri = preg_replace('#^/realivingv2/#', '/', $request_uri);
}

$slug = trim(parse_url($request_uri, PHP_URL_PATH), '/');

// ══════════════════════════════════════════════════════
//  RESOLVE & INCLUDE THE PAGE
// ══════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════
//  REDIRECT: old direct file paths → clean URLs
// ══════════════════════════════════════════════════════
$old_path_map = [
  'realiving_user/index.php' => '',
  'realiving_user/about/about.php' => 'about',
  'realiving_user/contact/contact.php' => 'contact',
  'realiving_user/news/news.php' => 'news',
  'realiving_user/concept/concept.php' => 'concepts',
  'realiving_user/projects/all-projects.php' => 'projects',
  'realiving_user/modular/product-catalog.php' => 'modular',
  'loginpage/index.php' => 'login',
  'loginpage/logout.php' => 'logout',
  // Add more old paths here as needed
];

if (array_key_exists($slug, $old_path_map)) {
  $clean_slug = $old_path_map[$slug];
  $query = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: ' . BASE_URL . $clean_slug . $query, true, 301);
  exit();
}

if (array_key_exists($slug, $routes)) {
  $page_file = ROOT_PATH . $routes[$slug];

  if (file_exists($page_file)) {
    $GLOBALS['current_route_slug'] = $slug;
    require_once $page_file;
  } else {
    http_response_code(404);
    echo "Page file not found: " . htmlspecialchars($page_file);
  }
} else {
  http_response_code(404);
  require_once PAGES_PATH . '404.php';
}