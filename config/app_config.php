<?php
// config/app_config.php
if (!defined('BASE_URL')) {

    $is_local = ($_SERVER['HTTP_HOST'] === 'localhost' || str_contains($_SERVER['HTTP_HOST'], '127.0.0.1'));

    if ($is_local) {
        define('BASE_URL', 'http://localhost/realivingv2/');
        define('ROOT_PATH', 'C:/xampp/htdocs/realivingv2/');
    } else {
        define('BASE_URL', 'https://realivingdesigncenter.com/');
        define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/');
    }

    define('BASE_ASSET', BASE_URL . '');
    define('CLIENT_ASSET', BASE_URL . 'realiving_user');
    define('PAGES_PATH', ROOT_PATH . 'realiving_user/');
    
    define('ADMIN_ASSET', BASE_URL . 'realiving_admin');
    define('ADMIN_PATH', ROOT_PATH . 'realiving_admin/');

    define('LOGINPAGE_PATH', ROOT_PATH . 'loginpage/');
}

if (!isset($GLOBALS['includes'])) {
    $GLOBALS['includes'] = [
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

        //inquiry_popup
        'inquiry-popup' => PAGES_PATH . 'inquiry_form/inquiry.php',

        //navbar
        'header' => PAGES_PATH . 'realiving_navbar/realiving_navbar.php',

        //admin_navbar
        'mainbody' => ADMIN_PATH . 'design/mainbody.php',

        //checkrole
        'checkrole' => LOGINPAGE_PATH . 'checkrole.php',

        //footer
        'footer' => PAGES_PATH . 'footer/footer.php',

        //product list view
        'quotation-products-display' => ADMIN_PATH . 'quotation-management/product-list-view/quotation_products_display.php',
        'quotation-modal-content' => ADMIN_PATH . 'quotation-management/product-list-view/quotation_modal_content.php',

        //quotation partials
        'client-header' => ADMIN_PATH . 'quotation-management/quotation-partials/client_header.php',
        'customized-entries' => ADMIN_PATH . 'quotation-management/quotation-partials/customized_entries.php',
        'fixed-entries' => ADMIN_PATH . 'quotation-management/quotation-partials/fixed_entries.php',
        'modals' => ADMIN_PATH . 'quotation-management/quotation-partials/modals.php',
        'summary-pannel' => ADMIN_PATH . 'quotation-management/quotation-partials/summary_pannel.php',

        //tracker management
        'esign-modal' => ADMIN_PATH . 'tracker-management/tracker-management/esign_modal.php',
    ];
}

$includes = $GLOBALS['includes'];