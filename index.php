<?php

// ══════════════════════════════════════════════════════
//  ENVIRONMENT DETECTION — localhost vs live
// ══════════════════════════════════════════════════════
$is_local = ($_SERVER['HTTP_HOST'] === 'localhost' || str_contains($_SERVER['HTTP_HOST'], '127.0.0.1'));

if ($is_local) {
  // Local: http://localhost/realiving/
  define('BASE_URL', 'http://localhost/realivingv2/');
  define('ROOT_PATH', 'C:/xampp/htdocs/realivingv2/');
} else {
  // Live: https://realivingdesigncenter.com/
  define('BASE_URL', 'https://realivingdesigncenter.com/');
  define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/');
}

// ══════════════════════════════════════════════════════
//  ASSET & PAGE PATHS
// ══════════════════════════════════════════════════════
define('BASE_ASSET', BASE_URL . '');
define('CLIENT_ASSET', BASE_URL . 'realiving_user');
define('PAGES_PATH', ROOT_PATH . 'realiving_user/');

$includes = [
  //connection
  'connection' => ROOT_PATH . 'connection/connection.php',

  //recaptcha
  'recaptcha' => PAGES_PATH . 'config/recaptcha_config.php',

  //ads
  'ads' => PAGES_PATH . 'ads/promo-banner.php',
  'banner' => PAGES_PATH . 'ads/banner.php',
  'banner2' => PAGES_PATH . 'ads/banner2.php',

  //assignment_logic
  'assignement_logic' => ROOT_PATH . 'connection/assignement_logic.php',

  //inquiry
  'inquiry' => PAGES_PATH . 'inquiry_form/concept_inquiry.php',

  //navbar
  'header' => PAGES_PATH . 'realiving_navbar/realiving_navbar.php',

  //footer
  'footer' => PAGES_PATH . 'footer/footer.php',
];

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