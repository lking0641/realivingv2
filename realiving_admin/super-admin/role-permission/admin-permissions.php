<?php
//admin-permissions.php
include $includes['mainbody'];

require_role(['super_admin']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Roles & Permissions - RealLiving</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>logo/favicon.ico">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
    crossorigin="anonymous" referrerpolicy="no-referrer" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --adm-bg: #F5F5F5;
      --adm-surface: #FFFFFF;
      --adm-ink: #0B0B0B;
      --adm-soft: #6B6B6B;
      --adm-muted: #9A9A9A;
      --adm-line: #E2E2E2;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--adm-bg);
      color: var(--adm-ink);
    }

    .adm-eyebrow {
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      color: var(--adm-soft);
    }

    .adm-title {
      font-size: 28px;
      font-weight: 700;
      letter-spacing: -0.01em;
      color: var(--adm-ink);
    }

    .adm-subtitle {
      font-size: 13.5px;
      color: var(--adm-soft);
    }

    .adm-btn {
      font-size: 12.5px;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: .65rem 1.1rem;
      border-radius: 8px;
      border: 1px solid var(--adm-line);
      background: #fff;
      color: var(--adm-ink);
      cursor: pointer;
      transition: border-color .15s ease, background .15s ease;
    }

    .adm-btn:hover {
      border-color: var(--adm-ink);
    }

    .adm-card {
      display: block;
      background: var(--adm-surface);
      border: 1px solid var(--adm-line);
      border-radius: 10px;
      padding: 1.75rem;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }

    .adm-card:hover,
    .adm-card:focus-visible {
      border-color: var(--adm-ink);
      box-shadow: 0 10px 26px -16px rgba(11, 11, 11, 0.25);
      transform: translateY(-2px);
      outline: none;
    }

    .adm-icon {
      width: 48px;
      height: 48px;
      border-radius: 10px;
      background: var(--adm-bg);
      border: 1px solid var(--adm-line);
      color: var(--adm-ink);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 19px;
      margin-bottom: 1.2rem;
    }

    .adm-card-title {
      font-size: 16px;
      font-weight: 700;
      color: var(--adm-ink);
      margin-bottom: .45rem;
    }

    .adm-card-desc {
      font-size: 13px;
      line-height: 1.55;
      color: var(--adm-soft);
      margin-bottom: 1.3rem;
    }

    .adm-card-link {
      font-size: 12.5px;
      font-weight: 600;
      color: var(--adm-ink);
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .adm-card-link i {
      font-size: 10px;
      transition: transform .2s ease;
    }

    .adm-card:hover .adm-card-link i {
      transform: translateX(3px);
    }

    @keyframes adm-fade {
      from { opacity: 0; transform: translateY(8px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .adm-fade { animation: adm-fade .4s ease both; }

    @media (prefers-reduced-motion: reduce) {
      .adm-fade { animation: none; }
    }
  </style>
</head>

<body class="min-h-screen flex flex-col">

  <!-- Main Content -->
  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto w-full">

    <!-- Header -->
    <div class="mb-10 adm-fade flex items-start justify-between flex-wrap gap-4">
      <div>
        <div class="adm-eyebrow mb-2">Super Admin</div>
        <h1 class="adm-title">Roles & Permissions</h1>
        <p class="adm-subtitle mt-1">Choose what you'd like to manage.</p>
      </div>
      <a href="<?= BASE_URL ?>admin-mainpage" class="adm-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <!-- Selection Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 adm-fade">

      <a href="<?= BASE_URL ?>role-permissions-controller" class="adm-card">
        <div class="adm-icon"><i class="fas fa-user-tag"></i></div>
        <h3 class="adm-card-title">Role Permissions</h3>
        <p class="adm-card-desc">Control what each admin role (sales, designer, HR, etc.) can access and do across the system.</p>
        <span class="adm-card-link">Manage Role Permissions <i class="fas fa-arrow-right"></i></span>
      </a>

      <a href="<?= BASE_URL ?>stage-permissions-controller" class="adm-card">
        <div class="adm-icon"><i class="fas fa-diagram-project"></i></div>
        <h3 class="adm-card-title">Stage Permissions</h3>
        <p class="adm-card-desc">Control which roles can view or act on items at each stage of a process or workflow.</p>
        <span class="adm-card-link">Manage Stage Permissions <i class="fas fa-arrow-right"></i></span>
      </a>

    </div>

  </div>

</body>

</html>