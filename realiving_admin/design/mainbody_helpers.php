<?php
// mainbody_helpers.php
// Pure helper functions used by the admin nav. No side effects, no DB/session
// access here — just logic you can unit-test or reuse elsewhere.

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