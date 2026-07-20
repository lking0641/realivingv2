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



  'sales-appointment' => 'realiving_sales/appointment/appointment_dashboard.php',
  'sales-concept-inquiry' => 'realiving_sales/concept_inquiry/concept_inquiry_dashboard.php',
  'sales-contact' => 'realiving_sales/contact/contact_dashboard.php',
  'sales-project' => 'realiving_sales/project/project_dashboard.php',
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
    require_once $page_file;
  } else {
    http_response_code(404);
    echo "Page file not found: " . htmlspecialchars($page_file);
  }
} else {
  http_response_code(404);
  require_once PAGES_PATH . '404.php';
}