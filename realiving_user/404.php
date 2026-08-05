<?php
// 404.php — Themed "Page Not Found" error page
// Matches the visual language of realiving_main.php (hero styling,
// dark-brown/gold palette, Montserrat font).
//
// This file is loaded directly by index.php's router:
//   require_once PAGES_PATH . '404.php';
// so it does NOT go through the normal $includes['header'] / sidebar
// include — it's a full standalone page (keeps things simple and fast
// for an error page, and avoids any risk of the sidebar/nav failing
// to resolve routes properly on a broken URL).
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Page Not Found — Realiving Design Center</title>
  <link rel="icon" type="image/png" href="<?= CLIENT_ASSET ?>/images/logo/favicon.png">

  <link
    href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@400;500;600&display=swap&font-display=swap"
    rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />

  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Montserrat', sans-serif;
      background: #0e0704;
      color: #fff;
      min-height: 100vh;
      overflow-x: hidden;
    }

    /* Full-cover background, same treatment as the hero section:
       dark image + double gradient overlay for readable text */
    .err-hero {
      position: relative;
      min-height: 100vh;
      width: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      overflow: hidden;
      padding: 24px;
    }

    .err-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background-image: url('<?= CLIENT_ASSET ?>/images/background-image.jpg');
      background-size: cover;
      background-position: center;
      transform: scale(1.1);
      filter: saturate(1.05);
    }

    .err-hero::after {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(to top, rgba(14, 7, 4, 0.95) 0%, rgba(14, 7, 4, 0.55) 45%, rgba(14, 7, 4, 0.35) 100%),
        linear-gradient(120deg, rgba(14, 7, 4, 0.55) 0%, transparent 60%);
    }

    .err-content {
      position: relative;
      z-index: 2;
      max-width: 640px;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    .err-logo {
      height: 56px;
      width: auto;
      max-width: 220px;
      object-fit: contain;
      margin-bottom: 40px;
      opacity: 0.95;
    }

    .err-eyebrow {
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 4px;
      text-transform: uppercase;
      color: #c4905c;
      margin-bottom: 18px;
    }

    .err-code {
      font-family: 'Cormorant Garamond', serif;
      font-weight: 500;
      font-size: clamp(90px, 18vw, 160px);
      line-height: 1;
      letter-spacing: 6px;
      color: #fff;
      text-shadow: 0 10px 40px rgba(196, 144, 92, 0.25);
      margin-bottom: 12px;
    }

    .err-title {
      font-family: 'Cormorant Garamond', serif;
      font-weight: 500;
      font-size: clamp(24px, 4vw, 34px);
      line-height: 1.25;
      margin-bottom: 16px;
    }

    .err-desc {
      font-size: 13px;
      font-weight: 300;
      line-height: 1.7;
      color: rgba(255, 255, 255, 0.75);
      max-width: 420px;
      margin-bottom: 40px;
    }

    .err-divider {
      width: 64px;
      height: 2px;
      background: #c4905c;
      opacity: 0.6;
      border-radius: 4px;
      margin-bottom: 40px;
    }

    .err-actions {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 18px;
      width: 100%;
    }

    .err-btn-row {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 14px;
      width: 100%;
      max-width: 380px;
    }

    @media (min-width: 480px) {
      .err-btn-row {
        flex-direction: row;
        justify-content: center;
      }
    }

    .err-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 15px 32px;
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 2px;
      text-transform: uppercase;
      text-decoration: none;
      transition: all 0.3s ease;
      white-space: nowrap;
      width: 100%;
    }

    @media (min-width: 480px) {
      .err-btn {
        width: auto;
      }
    }

    .err-btn-primary {
      background: #fff;
      color: #2f1200;
      border: 1px solid #fff;
    }

    .err-btn-primary:hover {
      background: #2f1200;
      color: #fff;
      border-color: #2f1200;
    }

    .err-btn-secondary {
      background: transparent;
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.7);
    }

    .err-btn-secondary:hover {
      border-color: #fff;
      background: rgba(255, 255, 255, 0.1);
    }

    /* Quick links — helps recover lost visitors, echoes sidebar nav */
    .err-links {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: center;
      gap: 10px 22px;
      margin-top: 36px;
      padding-top: 28px;
      border-top: 1px solid rgba(255, 255, 255, 0.12);
      width: 100%;
      max-width: 460px;
    }

    .err-links a {
      font-size: 11px;
      font-weight: 500;
      letter-spacing: 1px;
      text-transform: uppercase;
      color: rgba(255, 255, 255, 0.6);
      text-decoration: none;
      transition: color 0.25s ease;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .err-links a:hover {
      color: #c4905c;
    }

    .err-links i {
      font-size: 13px;
    }

    /* Subtle floating decoration, nothing heavy — keeps page fast */
    .err-search-hint {
      margin-top: 28px;
      font-size: 11px;
      color: rgba(255, 255, 255, 0.4);
      display: flex;
      align-items: center;
      gap: 6px;
    }
  </style>
</head>

<body>

  <section class="err-hero">
    <div class="err-content">

      <img src="<?= CLIENT_ASSET ?>/images/logo/logowhite.png" alt="Realiving Design Center" class="err-logo">

      <span class="err-eyebrow">Design &bull; Fabricate &bull; Install</span>

      <div class="err-code">404</div>

      <h1 class="err-title">This room hasn't been designed yet</h1>

      <p class="err-desc">
        The page you're looking for may have been moved, renamed, or doesn't exist.
        Let's get you back to somewhere beautiful.
      </p>

      <div class="err-divider"></div>

      <div class="err-actions">
        <div class="err-btn-row">
          <a href="<?= BASE_URL ?>" class="err-btn err-btn-primary">
            <i class="ri-home-5-line"></i> Back to Home
          </a>
          <a href="<?= BASE_URL ?>contact" class="err-btn err-btn-secondary">
            <i class="ri-mail-line"></i> Contact Us
          </a>
        </div>

        <div class="err-links">
          <a href="<?= BASE_URL ?>projects"><i class="ri-building-4-line"></i> Projects</a>
          <a href="<?= BASE_URL ?>concepts"><i class="ri-lightbulb-flash-line"></i> Concepts</a>
          <a href="<?= BASE_URL ?>services"><i class="ri-tools-line"></i> Services</a>
          <a href="<?= BASE_URL ?>about"><i class="ri-information-line"></i> About</a>
          <a href="<?= BASE_URL ?>news"><i class="ri-newspaper-line"></i> What's New</a>
        </div>
      </div>

    </div>
  </section>

</body>

</html>