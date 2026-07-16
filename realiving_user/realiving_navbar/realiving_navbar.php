<?php
//realiving_sidebar.php — Collapsible sidebar navigation (desktop) +
//floating bottom nav island + top logo bar (mobile).
//
// ── WHAT'S IN THIS VERSION ───────────────────────────────────────────────
// DESKTOP (>1024px): 100% identical to the original — collapsible sidebar,
// blurred-hero → solid-white on scroll, all 7 links + Book Now footer.
//
// MOBILE (<=1024px): sidebar rail is retired completely. Two new pieces
// take over, both themed with the site's dark-brown/gold "hardware" look:
//
//   1. #mobileTopBar — a slim floating bar, logo pinned top-left.
//      Transparent + blurred over the hero (same trick as the sidebar's
//      ::before/::after blur), then swaps to solid white with the dark
//      logo once the page is scrolled past SCROLL_THRESHOLD.
//
//   2. #mobileBottomNav — floating rounded "island" bottom nav. Only
//      4 slots are visible (Home, Concepts, [raised Book Now knob],
//      Projects) because 7 links won't fit in a thumb-reachable bar.
//      The 5th slot is "More" — tapping it slides up a sheet
//      (#mobileMoreSheet) containing the overflow links: About,
//      Services, What's New, Contact. So every single link from the
//      original nav is still reachable, just grouped so the bar itself
//      stays uncluttered.
// ──────────────────────────────────────────────────────────────────────────
// I-detect kung anong link ang dapat i-highlight bilang "active" base sa
// kasalukuyang URL slug — parehong pattern ng ginagamit sa header.php mo.
$sb_request_uri = $_SERVER['REQUEST_URI'];
if ($is_local ?? false) {
  $sb_request_uri = preg_replace('#^/realivingv2/#', '/', $sb_request_uri);
}
$sb_current_slug = trim(parse_url($sb_request_uri, PHP_URL_PATH), '/');

// I-normalize ang mga "detail/view" page slugs pabalik sa parent nav
// slug nila, para tuloy-tuloy pa ring naka-highlight yung tamang link
// (hal. Projects) kahit nasa loob ka na ng isang specific na item
// (hal. /view-projects?id=5). Idagdag lang dito ang bagong pares
// kung magkakaroon pa ng ganitong "detail page" sa ibang section.
$sb_slug_aliases = [
  'news-view' => 'news',
  'view-projects' => 'projects',
];
if (isset($sb_slug_aliases[$sb_current_slug])) {
  $sb_current_slug = $sb_slug_aliases[$sb_current_slug];
}

function sb_is_active($slug, $current)
{
  if ($slug === '' && ($current === '' || $current === 'index.php'))
    return true;
  return $slug !== '' && $current === $slug;
}

?>

<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />

<style>
  :root {
    --sb-accent: #2f1200;
    --sb-gold: #c4905c;
    --sb-gold-deep: #A08150;
    --sb-w-expanded: 280px;
    --sb-w-collapsed: 84px;
  }

  /* ═══════════════════════════════════════════════════════════════
     DESKTOP SIDEBAR — untouched from the original
     ═══════════════════════════════════════════════════════════════ */
  #sidebar {
    font-family: 'Montserrat', sans-serif;
    position: fixed;
    top: 0;
    left: 0;
    height: 100vh;
    width: var(--sb-w-expanded);
    background: rgba(20, 10, 0, 0.28);
    backdrop-filter: blur(16px) saturate(140%);
    -webkit-backdrop-filter: blur(16px) saturate(140%);
    border-right: 1px solid rgba(255, 255, 255, 0.12);
    display: flex;
    flex-direction: column;
    z-index: 50;
    transition: width .3s ease, background-color .3s ease, border-color .3s ease, box-shadow .3s ease, backdrop-filter .3s ease;
    overflow: hidden;
  }

  #sidebar.collapsed {
    width: var(--sb-w-collapsed);
  }

  #sidebar.no-transition {
    transition: none !important;
  }

  #sidebar.scrolled {
    background: #ffffff;
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
    border-right: 1px solid rgba(0, 0, 0, 0.08);
    box-shadow: 2px 0 16px rgba(0, 0, 0, 0.05);
    transition: width .3s ease, background-color .3s ease, border-color .3s ease, box-shadow .15s ease .15s, backdrop-filter .3s ease;
  }

  .sb-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 26px 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    flex-shrink: 0;
    transition: border-color .3s ease;
  }

  #sidebar.scrolled .sb-header {
    border-bottom-color: rgba(0, 0, 0, 0.08);
  }

  .sb-brand {
    display: flex;
    align-items: center;
    gap: 14px;
    overflow: hidden;
    text-decoration: none;
    min-width: 0;
  }

  .sb-logo-mark {
    display: flex;
    flex-shrink: 0;
    height: 64px;
    width: 64px;
    border-radius: 50%;
    background: rgba(20, 10, 0, 0.55);
    overflow: hidden;
    align-items: center;
    justify-content: center;
    padding: 10px;
    box-sizing: border-box;
    border: 2px solid rgba(255, 255, 255, 0.5);
    transition: all .3s ease;
  }

  #sidebar.scrolled .sb-logo-mark {
    background: #fff;
    border-color: rgba(0, 0, 0, 0.1);
  }

  .sb-mark-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }

  #sidebar:not(.collapsed) .sb-logo-mark {
    display: none;
  }

  .sb-logo-text {
    display: flex;
    align-items: center;
    overflow: hidden;
    min-width: 0;
  }

  .sb-logo-full {
    height: 56px;
    width: auto;
    max-width: 210px;
    object-fit: contain;
  }

  #sidebar.collapsed .sb-logo-text {
    display: none;
  }

  #sidebar.collapsed .sb-header {
    flex-direction: column;
    justify-content: center;
    gap: 14px;
    padding: 24px 0;
  }

  #sidebar.collapsed .sb-brand {
    justify-content: center;
  }

  .sb-collapse-btn {
    background: transparent;
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: #fff;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all .2s ease, transform .3s ease;
    flex-shrink: 0;
  }

  .sb-collapse-btn:hover {
    background: rgba(255, 255, 255, 0.15);
  }

  #sidebar.scrolled .sb-collapse-btn {
    border-color: rgba(0, 0, 0, 0.2);
    color: var(--sb-accent);
  }

  #sidebar.scrolled .sb-collapse-btn:hover {
    background: rgba(0, 0, 0, 0.05);
  }

  #sidebar.collapsed .sb-collapse-btn i {
    transform: rotate(180deg);
  }

  .sb-label {
    padding: 18px 24px 8px;
    font-size: 11px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.65);
    flex-shrink: 0;
    white-space: nowrap;
    transition: color .3s ease;
  }

  #sidebar.scrolled .sb-label {
    color: rgba(0, 0, 0, 0.45);
  }

  #sidebar.collapsed .sb-label {
    text-align: center;
    padding: 18px 0 8px;
  }

  .sb-nav {
    flex: 1 1 auto;
    overflow-y: auto;
    overflow-x: hidden;
    padding-bottom: 12px;
  }

  .sb-nav::-webkit-scrollbar {
    width: 4px;
  }

  .sb-nav::-webkit-scrollbar-thumb {
    background: rgba(0, 0, 0, 0.15);
    border-radius: 4px;
  }

  .sb-link {
    position: relative;
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 13px 24px;
    color: #fff;
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 1px;
    white-space: nowrap;
    transition: background .2s ease, color .2s ease;
  }

  .sb-link i {
    font-size: 19px;
    width: 22px;
    text-align: center;
    flex-shrink: 0;
    color: rgba(255, 255, 255, 0.85);
    transition: color .2s ease;
  }

  .sb-link:hover {
    background: rgba(255, 255, 255, 0.1);
  }

  #sidebar.scrolled .sb-link {
    color: #2b2b2b;
  }

  #sidebar.scrolled .sb-link i {
    color: #8a8a8a;
  }

  #sidebar.scrolled .sb-link:hover {
    background: rgba(47, 18, 0, 0.06);
  }

  #sidebar.scrolled .sb-link:hover i {
    color: var(--sb-accent);
  }

  .sb-link.active {
    color: var(--sb-accent);
    background: rgba(255, 255, 255, 0.85);
  }

  .sb-link.active i {
    color: var(--sb-accent);
  }

  #sidebar.scrolled .sb-link.active {
    background: rgba(47, 18, 0, 0.08);
  }

  .sb-link.active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--sb-accent);
  }

  .sb-link .sb-text {
    transition: opacity .2s ease;
  }

  #sidebar.collapsed .sb-link {
    justify-content: center;
    padding: 13px 0;
    gap: 0;
  }

  #sidebar.collapsed .sb-link .sb-text {
    display: none;
  }

  .sb-divider {
    height: 1px;
    background: rgba(255, 255, 255, 0.2);
    margin: 8px 20px;
    flex-shrink: 0;
    transition: background-color .3s ease;
  }

  #sidebar.scrolled .sb-divider {
    background: rgba(0, 0, 0, 0.08);
  }

  #sidebar.collapsed .sb-divider {
    margin: 8px 16px;
  }

  .sb-footer {
    padding: 18px 20px 22px;
    border-top: 1px solid rgba(255, 255, 255, 0.15);
    flex-shrink: 0;
    transition: border-color .3s ease;
  }

  #sidebar.scrolled .sb-footer {
    border-top-color: rgba(0, 0, 0, 0.08);
  }

  .sb-book-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    padding: 12px 14px;
    border: 2px solid #fff;
    background: transparent;
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    text-decoration: none;
    transition: all .25s ease;
    white-space: nowrap;
    overflow: hidden;
  }

  .sb-book-btn:hover {
    background: #fff;
    color: var(--sb-accent);
  }

  #sidebar.scrolled .sb-book-btn {
    border-color: var(--sb-accent);
    color: var(--sb-accent);
  }

  #sidebar.scrolled .sb-book-btn:hover {
    background: var(--sb-accent);
    color: #fff;
  }

  #sidebar.collapsed .sb-book-btn {
    padding: 12px 0;
  }

  #sidebar.collapsed .sb-book-btn .sb-text {
    display: none;
  }

  #sidebar.collapsed .sb-link {
    position: relative;
  }

  #sidebar.collapsed .sb-link:hover::after {
    content: attr(data-label);
    position: absolute;
    left: calc(100% + 12px);
    top: 50%;
    transform: translateY(-50%);
    background: #2b2b2b;
    color: #fff;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    white-space: nowrap;
    box-shadow: 0 4px 12px rgba(0, 0, 0, .25);
    pointer-events: none;
    z-index: 60;
  }

  .main-content {
    margin-left: 0;
    width: 100%;
    box-sizing: border-box;
  }

  /* Desktop only: offset content based on the ACTUAL current width of
     #sidebar (sibling selector), hindi hiwalay na class na kailangan
     pang i-sync manually sa JS. Kung ano ang state ng #sidebar, sabay
     na sumusunod ang .main-content — walang lag, walang mismatch. */
  @media (min-width: 768px) {
    #sidebar~.main-content {
      margin-left: var(--sb-w-expanded);
      width: calc(100% - var(--sb-w-expanded));
      transition: margin-left .3s ease, width .3s ease;
    }

    #sidebar.collapsed~.main-content {
      margin-left: var(--sb-w-collapsed);
      width: calc(100% - var(--sb-w-collapsed));
    }
  }

  /* ═══════════════════════════════════════════════════════════════
     MOBILE (<=1024px): sidebar rail retired. Top logo bar + floating
     bottom nav island take over.
     ═══════════════════════════════════════════════════════════════ */
  @media (max-width: 767px) {
    #sidebar {
      display: none !important;
    }

    .main-content,
    .main-content.sb-collapsed-offset {
      margin-left: 0 !important;
      width: 100% !important;
    }
  }

  @media (min-width: 768px) {
    #mobileTopBar {
      display: none !important;
    }

    #mobileBottomNav {
      display: none !important;
    }

    #mobileMoreSheet {
      display: none !important;
    }
  }

  /* ─────────────────────────────────────────
     MOBILE TOP BAR — logo, top-left, floating.
     Same transparent-blur-over-hero → solid-white-on-scroll trick as
     the desktop sidebar, just applied to a slim top strip instead.
  ───────────────────────────────────────── */
  #mobileTopBar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 72px;
    z-index: 70;
    display: flex;
    align-items: center;
    padding: 0 18px;
    background: rgba(20, 10, 0, 0.28);
    backdrop-filter: blur(16px) saturate(140%);
    -webkit-backdrop-filter: blur(16px) saturate(140%);
    border-bottom: 1px solid rgba(255, 255, 255, 0.12);
    transition: background-color .3s ease, border-color .3s ease, box-shadow .3s ease, backdrop-filter .3s ease;
    font-family: 'Montserrat', sans-serif;
  }

  #mobileTopBar.scrolled {
    background: #ffffff;
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
    border-bottom-color: rgba(0, 0, 0, 0.08);
    box-shadow: 0 2px 16px rgba(0, 0, 0, 0.06);
  }

  .mtb-logo-full {
    height: 52px;
    width: auto;
    max-width: 210px;
    object-fit: contain;
  }

  /* ─────────────────────────────────────────
     MOBILE BOTTOM NAV — floating rounded island (same hardware theme
     as the reference file), now with a "More" slot so every one of
     the original 7 links + Book Now stays reachable.
  ───────────────────────────────────────── */
  #mobileBottomNav {
    position: fixed;
    left: 16px;
    right: 16px;
    bottom: calc(14px + env(safe-area-inset-bottom, 0px));
    z-index: 80;
    display: flex;
    align-items: center;
    justify-content: space-around;
    height: 66px;
    background: rgba(20, 10, 0, 0.35);
    backdrop-filter: blur(16px) saturate(140%);
    -webkit-backdrop-filter: blur(16px) saturate(140%);
    border-radius: 26px;
    box-shadow: 0 10px 30px rgba(20, 8, 0, 0.35), 0 1px 0 rgba(255, 255, 255, 0.06) inset;
    border: 1px solid rgba(255, 255, 255, 0.08);
    transition: background-color .3s ease, box-shadow .3s ease, border-color .3s ease, backdrop-filter .3s ease;
    font-family: 'Montserrat', sans-serif;
  }

  #mobileBottomNav.scrolled {
    background: #ffffff;
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
    box-shadow: 0 10px 30px rgba(20, 8, 0, 0.16), 0 1px 0 rgba(0, 0, 0, 0.03) inset;
    border: 1px solid rgba(0, 0, 0, 0.06);
  }

  .mbn-link {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    width: 52px;
    height: 100%;
    color: rgba(247, 242, 233, 0.5);
    text-decoration: none;
    background: transparent;
    border: none;
    cursor: pointer;
    font-family: inherit;
    -webkit-tap-highlight-color: transparent;
    outline: none;
    transition: color .3s ease;
  }

  .mbn-link:focus,
  .mbn-link:focus-visible {
    outline: none;
    box-shadow: none;
  }

  #mobileBottomNav.scrolled .mbn-link {
    color: rgba(47, 18, 0, 0.85);
  }

  .mbn-icon {
    font-size: 19px;
    transform: translateY(0) scale(1);
    transition: transform .35s cubic-bezier(.34, 1.56, .64, 1), color .2s ease;
  }

  .mbn-label {
    font-size: 8px;
    font-weight: 600;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    opacity: 0.95;
    transition: opacity .2s ease, color .2s ease;
  }

  .mbn-tick {
    position: absolute;
    bottom: 3px;
    width: 10px;
    height: 5px;
    opacity: 0;
    transition: opacity .25s ease;
    pointer-events: none;
  }

  .mbn-tick::before,
  .mbn-tick::after {
    content: '';
    position: absolute;
    top: 0;
    width: 4px;
    height: 4px;
    border-color: var(--sb-gold);
  }

  .mbn-tick::before {
    left: 0;
    border-left: 1.5px solid;
    border-bottom: 1.5px solid;
  }

  .mbn-tick::after {
    right: 0;
    border-right: 1.5px solid;
    border-bottom: 1.5px solid;
  }

  .mbn-link.active {
    color: var(--sb-gold);
  }

  .mbn-link.active .mbn-icon {
    transform: translateY(-3px) scale(1.15);
  }

  .mbn-link.active .mbn-label {
    opacity: 1;
  }

  .mbn-link.active .mbn-tick {
    opacity: 1;
  }

  .mbn-icon .ri-line {
    display: block;
  }

  .mbn-icon .ri-fill {
    display: none;
  }

  .mbn-link.active .mbn-icon .ri-line {
    display: none;
  }

  .mbn-link.active .mbn-icon .ri-fill {
    display: block;
  }

  /* raised center "hardware pull" — Book Now */
  .mbn-center {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    outline: none;
    width: 60px;
    height: 60px;
    margin-top: -34px;
    border-radius: 50%;
    background: linear-gradient(150deg, #dcae7c 0%, var(--sb-gold) 45%, var(--sb-gold-deep) 100%);
    box-shadow: 0 8px 18px rgba(0, 0, 0, 0.35), 0 0 0 5px var(--sb-accent), inset 0 1px 1px rgba(255, 255, 255, 0.5), inset 0 -3px 5px rgba(80, 50, 10, 0.35);
    text-decoration: none;
    flex-shrink: 0;
    transition: transform .25s cubic-bezier(.34, 1.56, .64, 1), box-shadow .3s ease;
  }

  #mobileBottomNav.scrolled .mbn-center {
    box-shadow: 0 8px 18px rgba(0, 0, 0, 0.22), 0 0 0 5px #ffffff, inset 0 1px 1px rgba(255, 255, 255, 0.5), inset 0 -3px 5px rgba(80, 50, 10, 0.35);
  }

  .mbn-center:active {
    transform: scale(0.92);
  }

  .mbn-center::before {
    content: '';
    position: absolute;
    inset: 7px;
    border-radius: 50%;
    border: 1px solid rgba(80, 50, 10, 0.3);
    pointer-events: none;
  }

  .mbn-center i {
    position: relative;
    font-size: 22px;
    color: #3a1c05;
    z-index: 1;
  }

  .mbn-center-label {
    position: absolute;
    bottom: -16px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 8.5px;
    font-weight: 700;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    color: rgba(247, 242, 233, 0.55);
    white-space: nowrap;
  }

  /* "More" trigger — same visual language as the other mbn-links, so it
     doesn't stand out as a different kind of control, it just opens
     a sheet instead of navigating */
  .mbn-more.sheet-open .mbn-icon {
    color: var(--sb-gold);
    transform: translateY(-3px) scale(1.15);
  }

  @media (max-width: 380px) {
    #mobileBottomNav {
      left: 10px;
      right: 10px;
    }

    .mbn-link {
      width: 44px;
    }
  }

  /* ─────────────────────────────────────────
     "MORE" SHEET — groups the overflow links (About, Services,
     What's New, Contact) so they're all still one tap away.
  ───────────────────────────────────────── */
  #mobileMoreSheet {
    position: fixed;
    inset: 0;
    z-index: 90;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    visibility: hidden;
    pointer-events: none;
    font-family: 'Montserrat', sans-serif;
  }

  #mobileMoreSheet.open {
    visibility: visible;
    pointer-events: auto;
  }

  .ms-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(20, 10, 0, 0.45);
    opacity: 0;
    transition: opacity .25s ease;
  }

  #mobileMoreSheet.open .ms-backdrop {
    opacity: 1;
  }

  .ms-panel {
    position: relative;
    width: 100%;
    max-width: 560px;
    background: rgba(20, 10, 0, 0.55);
    backdrop-filter: blur(20px) saturate(150%);
    -webkit-backdrop-filter: blur(20px) saturate(150%);
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 26px 26px 0 0;
    padding: 10px 14px calc(24px + env(safe-area-inset-bottom, 0px));
    box-shadow: 0 -10px 30px rgba(0, 0, 0, 0.3);
    transform: translateY(100%);
    transition: transform .3s cubic-bezier(.32, .72, 0, 1);
  }

  #mobileMoreSheet.open .ms-panel {
    transform: translateY(0);
  }

  .ms-grabber {
    width: 40px;
    height: 4px;
    border-radius: 4px;
    background: rgba(255, 255, 255, 0.25);
    margin: 8px auto 14px;
  }

  .ms-title {
    padding: 0 4px 12px;
    font-size: 11px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.55);
  }

  .ms-links {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
  }

  .ms-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 20px 10px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: #fff;
    text-decoration: none;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    border-radius: 16px;
    outline: none;
    transition: background .2s ease, color .2s ease, border-color .2s ease;
  }

  .ms-link i {
    font-size: 24px;
    color: var(--sb-gold);
    flex-shrink: 0;
  }

  .ms-link:hover,
  .ms-link:active {
    background: rgba(255, 255, 255, 0.12);
    border-color: rgba(255, 255, 255, 0.16);
  }

  .ms-link.active {
    background: rgba(196, 144, 92, 0.18);
    border-color: var(--sb-gold);
  }

  .ms-link.active i {
    color: var(--sb-gold);
  }

  .ms-close {
    position: absolute;
    top: 12px;
    right: 14px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
    border: none;
    outline: none;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    cursor: pointer;
  }

  /* ═══════════════════════════════════════════════════════════════
     NO-HERO PAGES — centralized override.
     Ilagay lang ang class="no-hero" sa <body> ng anumang page na
     walang hero slider (Concepts, Projects, atbp.). Automatic nang
     mananatiling PUTI ang buong sidebar/top bar/bottom nav dito
     (walang transparent-over-hero → white-on-scroll transition), at
     isang logo variant (dark) lang ang lalabas — hindi na kailangan
     mag-duplicate ng ganitong CSS sa bawat individual page.
     ═══════════════════════════════════════════════════════════════ */
  body.no-hero #sidebar,
  body.no-hero #sidebar.scrolled {
    background: #ffffff !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
    border-right: 1px solid rgba(0, 0, 0, 0.08) !important;
    box-shadow: 2px 0 16px rgba(0, 0, 0, 0.05) !important;
  }

  body.no-hero #sidebar::before,
  body.no-hero #sidebar::after {
    display: none !important;
  }

  body.no-hero #sidebar .sb-header,
  body.no-hero #sidebar .sb-footer {
    border-color: rgba(0, 0, 0, 0.08) !important;
  }

  body.no-hero #sidebar .sb-divider {
    background: rgba(0, 0, 0, 0.08) !important;
  }

  body.no-hero #sidebar .sb-logo-mark {
    background: rgba(20, 10, 0, 0.55) !important;
    border-color: rgba(0, 0, 0, 0.1) !important;
  }

  body.no-hero #sidebar.scrolled .sb-logo-mark,
  body.no-hero #sidebar .sb-logo-mark {
    background: #fff !important;
  }

  body.no-hero #sidebar .sb-label {
    color: rgba(0, 0, 0, 0.45) !important;
  }

  body.no-hero #sidebar .sb-collapse-btn {
    border-color: rgba(0, 0, 0, 0.2) !important;
    color: var(--sb-accent) !important;
  }

  body.no-hero #sidebar .sb-collapse-btn:hover {
    background: rgba(0, 0, 0, 0.05) !important;
  }

  body.no-hero #sidebar .sb-link {
    color: #2b2b2b !important;
  }

  body.no-hero #sidebar .sb-link i {
    color: #8a8a8a !important;
  }

  body.no-hero #sidebar .sb-link:hover {
    background: rgba(47, 18, 0, 0.06) !important;
  }

  body.no-hero #sidebar .sb-link:hover i {
    color: var(--sb-accent) !important;
  }

  body.no-hero #sidebar .sb-link.active {
    background: rgba(47, 18, 0, 0.08) !important;
  }

  body.no-hero #sidebar .sb-book-btn {
    border-color: var(--sb-accent) !important;
    color: var(--sb-accent) !important;
  }

  body.no-hero #sidebar .sb-book-btn:hover {
    background: var(--sb-accent) !important;
    color: #fff !important;
  }

  /* Paalala: "sbLogoWhite"/"sbMarkWhite" ang mga IMG na gumagamit ng
  logowhite.png file — ang WHITE-BACKGROUND variant (mas kumpleto,
  may subtitle text). "sbLogoDark"/"sbMarkDark" ay logoblack.png,
  ang HERO variant. Sa no-hero pages, laging puti ang bg kaya laging
  ang *White-named* (logowhite.png) elements ang dapat lumabas. */
  body.no-hero #sbLogoDark,
  body.no-hero #sbMarkDark {
    display: none !important;
  }

  body.no-hero #sbLogoWhite {
    display: block !important;
  }

  body.no-hero #sbMarkWhite {
    display: none !important;
  }

  body.no-hero #sidebar.collapsed #sbMarkWhite {
    display: block !important;
  }

  body.no-hero #mobileTopBar,
  body.no-hero #mobileTopBar.scrolled {
    background: #ffffff !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
    border-bottom-color: rgba(0, 0, 0, 0.08) !important;
    box-shadow: 0 2px 16px rgba(0, 0, 0, 0.06) !important;
  }

  body.no-hero #mobileBottomNav,
  body.no-hero #mobileBottomNav.scrolled {
    background: #ffffff !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
    box-shadow: 0 10px 30px rgba(20, 8, 0, 0.16), 0 1px 0 rgba(0, 0, 0, 0.03) inset !important;
    border: 1px solid rgba(0, 0, 0, 0.06) !important;
  }

  body.no-hero #mobileBottomNav .mbn-link {
    color: rgba(47, 18, 0, 0.85) !important;
  }

  body.no-hero #mobileBottomNav .mbn-center {
    box-shadow: 0 8px 18px rgba(0, 0, 0, 0.22), 0 0 0 5px #ffffff, inset 0 1px 1px rgba(255, 255, 255, 0.5), inset 0 -3px 5px rgba(80, 50, 10, 0.35) !important;
  }
</style>

<!-- ═══════════════════════════════
     DESKTOP SIDEBAR
     Blocking inline script (no defer/async) sets the collapsed class
     via document.write BEFORE the aside is parsed — kaya naka-tamang
     width na agad ito sa unang paint, walang expand→collapse flash.
═══════════════════════════════ -->
<script>
  try {
    if (localStorage.getItem('sb_collapsed') === '1') {
      document.write('<aside id="sidebar" class="collapsed no-transition">');
    } else {
      document.write('<aside id="sidebar" class="no-transition">');
    }
  } catch (e) {
    document.write('<aside id="sidebar" class="no-transition">');
  }
</script>
<noscript>
  <aside id="sidebar">
</noscript>

<div class="sb-header">
  <a href="<?= BASE_URL ?>" class="sb-brand">
    <span class="sb-logo-mark">
      <img id="sbMarkWhite" src="<?= CLIENT_ASSET ?>/images/logo/logowhite.png" alt="Realiving" class="sb-mark-img">
      <img id="sbMarkDark" src="<?= CLIENT_ASSET ?>/images/logo/logoblack.png" alt="Realiving" class="sb-mark-img"
        style="display:none;">
    </span>
    <span class="sb-logo-text">
      <img id="sbLogoWhite" src="<?= CLIENT_ASSET ?>/images/logo/logowhite.png" alt="Realiving Logo"
        class="sb-logo-full">
      <img id="sbLogoDark" src="<?= CLIENT_ASSET ?>/images/logo/logoblack.png" alt="Realiving Logo" class="sb-logo-full"
        style="display:none;">
    </span>
  </a>
  <button id="sbCollapseBtn" class="sb-collapse-btn">
    <i class="ri-arrow-left-s-line"></i>
  </button>
</div>

<div class="sb-label">Menu</div>

<nav class="sb-nav">
  <a href="<?= BASE_URL ?>" class="sb-link home-link <?= sb_is_active('', $sb_current_slug) ? 'active' : '' ?>"
    data-label="Home">
    <i class="ri-home-5-line"></i><span class="sb-text">Home</span>
  </a>
  <a href="<?= BASE_URL ?>projects"
    class="sb-link projects-link <?= sb_is_active('projects', $sb_current_slug) ? 'active' : '' ?>"
    data-label="Projects">
    <i class="ri-building-4-line"></i><span class="sb-text">Projects</span>
  </a>
  <a href="<?= BASE_URL ?>concepts"
    class="sb-link concepts-link <?= sb_is_active('concepts', $sb_current_slug) ? 'active' : '' ?>"
    data-label="Concepts">
    <i class="ri-lightbulb-flash-line"></i><span class="sb-text">Concepts</span>
  </a>
  <a href="<?= BASE_URL ?>about"
    class="sb-link about-link <?= sb_is_active('about', $sb_current_slug) ? 'active' : '' ?>" data-label="About">
    <i class="ri-information-line"></i><span class="sb-text">About</span>
  </a>
  <a href="<?= BASE_URL ?>services"
    class="sb-link services-link <?= sb_is_active('services', $sb_current_slug) ? 'active' : '' ?>"
    data-label="Services">
    <i class="ri-tools-line"></i><span class="sb-text">Services</span>
  </a>
  <a href="<?= BASE_URL ?>news"
    class="sb-link whatsnew-link <?= sb_is_active('news', $sb_current_slug) ? 'active' : '' ?>" data-label="What's New">
    <i class="ri-newspaper-line"></i><span class="sb-text">What's New</span>
  </a>
  <div class="sb-divider"></div>
  <a href="<?= BASE_URL ?>contact"
    class="sb-link contact-link <?= sb_is_active('contact', $sb_current_slug) ? 'active' : '' ?>" data-label="Contact">
    <i class="ri-mail-line"></i><span class="sb-text">Contact</span>
  </a>
</nav>

<div class="sb-footer">
  <a href="<?= BASE_URL ?>appointment" id="bookBtn" class="sb-book-btn">
    <i class="ri-calendar-check-line"></i><span class="sb-text">Book Now</span>
  </a>
</div>
</aside><noscript></noscript>

<!-- ═══════════════════════════════
     MOBILE TOP BAR (logo only)
═══════════════════════════════ -->
<div id="mobileTopBar">
  <a href="<?= BASE_URL ?>" class="sb-brand">
    <img id="mtbLogo" src="<?= CLIENT_ASSET ?>/images/logo/logoblack.png" alt="Realiving Logo" class="mtb-logo-full">
  </a>
</div>

<!-- ═══════════════════════════════
     MOBILE BOTTOM NAV (floating island)
═══════════════════════════════ -->
<nav id="mobileBottomNav">

  <a href="<?= BASE_URL ?>" class="mbn-link home-link <?= sb_is_active('', $sb_current_slug) ? 'active' : '' ?>"
    data-label="Home">
    <span class="mbn-icon">
      <i class="ri-home-5-line ri-line"></i>
      <i class="ri-home-5-fill ri-fill"></i>
    </span>
    <span class="mbn-label">Home</span>
    <span class="mbn-tick"></span>
  </a>

  <a href="<?= BASE_URL ?>concepts"
    class="mbn-link concepts-link <?= sb_is_active('concepts', $sb_current_slug) ? 'active' : '' ?>"
    data-label="Concepts">
    <span class="mbn-icon">
      <i class="ri-lightbulb-flash-line ri-line"></i>
      <i class="ri-lightbulb-flash-fill ri-fill"></i>
    </span>
    <span class="mbn-label">Concepts</span>
    <span class="mbn-tick"></span>
  </a>

  <!-- raised "cabinet hardware pull" center button -->
  <a href="<?= BASE_URL ?>appointment" id="bookBtnMobile" class="mbn-center" aria-label="Book Now">
    <i class="ri-calendar-check-fill"></i>
    <span class="mbn-center-label">Book</span>
  </a>

  <a href="<?= BASE_URL ?>projects"
    class="mbn-link projects-link <?= sb_is_active('projects', $sb_current_slug) ? 'active' : '' ?>"
    data-label="Projects">
    <span class="mbn-icon">
      <i class="ri-building-4-line ri-line"></i>
      <i class="ri-building-4-fill ri-fill"></i>
    </span>
    <span class="mbn-label">Projects</span>
    <span class="mbn-tick"></span>
  </a>

  <!-- MORE — groups About / Services / What's New / Contact so every
       link from the original nav still has a home -->
  <?php
  $sb_more_active = in_array($sb_current_slug, ['about', 'services', 'news', 'contact']);
  ?>
  <button type="button" id="mbnMoreBtn" class="mbn-link mbn-more <?= $sb_more_active ? 'active' : '' ?>"
    data-label="More">
    <span class="mbn-icon">
      <i class="ri-more-2-line ri-line"></i>
      <i class="ri-more-2-fill ri-fill"></i>
    </span>
    <span class="mbn-label">More</span>
    <span class="mbn-tick"></span>
  </button>

</nav>

<!-- ═══════════════════════════════
     MORE SHEET (overflow links)
═══════════════════════════════ -->
<div id="mobileMoreSheet" aria-hidden="true">
  <div class="ms-backdrop" id="msBackdrop"></div>
  <div class="ms-panel">
    <button type="button" class="ms-close" id="msCloseBtn" aria-label="Close">
      <i class="ri-close-line"></i>
    </button>
    <div class="ms-grabber"></div>
    <div class="ms-title">More</div>
    <div class="ms-links">
      <a href="<?= BASE_URL ?>about"
        class="ms-link about-link <?= sb_is_active('about', $sb_current_slug) ? 'active' : '' ?>" data-label="About">
        <i class="ri-information-line"></i><span>About</span>
      </a>
      <a href="<?= BASE_URL ?>services"
        class="ms-link services-link <?= sb_is_active('services', $sb_current_slug) ? 'active' : '' ?>"
        data-label="Services">
        <i class="ri-tools-line"></i><span>Services</span>
      </a>
      <a href="<?= BASE_URL ?>news"
        class="ms-link whatsnew-link <?= sb_is_active('news', $sb_current_slug) ? 'active' : '' ?>"
        data-label="What's New">
        <i class="ri-newspaper-line"></i><span>What's New</span>
      </a>
      <a href="<?= BASE_URL ?>contact"
        class="ms-link contact-link <?= sb_is_active('contact', $sb_current_slug) ? 'active' : '' ?>"
        data-label="Contact">
        <i class="ri-mail-line"></i><span>Contact</span>
      </a>
    </div>
  </div>
</div>

<script>
  (function () {
    const sidebar = document.getElementById('sidebar');
    const collapseBtn = document.getElementById('sbCollapseBtn');
    const logoWhite = document.getElementById('sbLogoWhite');
    const logoDark = document.getElementById('sbLogoDark');
    const markWhite = document.getElementById('sbMarkWhite');
    const markDark = document.getElementById('sbMarkDark');

    const topBar = document.getElementById('mobileTopBar');
    const mtbLogo = document.getElementById('mtbLogo');
    const bottomNav = document.getElementById('mobileBottomNav');
    const MTB_LOGO_WHITE = "<?= CLIENT_ASSET ?>/images/logo/logowhite.png";
    const MTB_LOGO_DARK = "<?= CLIENT_ASSET ?>/images/logo/logoblack.png";

    const SCROLL_THRESHOLD = 60;
    const MOBILE_BREAKPOINT = 767;

    function updateScrollState() {
      const scrolled = window.scrollY > SCROLL_THRESHOLD;

      // desktop sidebar — logowhite.png = white-bg variant (scrolled),
      // logoblack.png = hero variant (unscrolled)
      sidebar.classList.toggle('scrolled', scrolled);
      logoWhite.style.display = scrolled ? 'block' : 'none';
      logoDark.style.display = scrolled ? 'none' : 'block';
      markWhite.style.display = scrolled ? 'block' : 'none';
      markDark.style.display = scrolled ? 'none' : 'block';

      // mobile top bar — iisang <img> na lang, palitan lang ang src depende
      // sa scroll state. Paalala: "logowhite.png" ang WHITE-BACKGROUND
      // variant (ginagamit pag puti/scrolled ang paligid), at "logoblack.png"
      // ang HERO variant (ginagamit habang nasa ibabaw pa ng photo hero) —
      // nakakalito ang pangalan pero ito ang tamang pares base sa disenyo.
      // Sa "no-hero" pages (Concepts, Projects, atbp.), laging puti ang
      // background nila kaya laging naka-lock sa WHITE-BG variant.
      const isNoHero = document.body.classList.contains('no-hero');
      topBar.classList.toggle('scrolled', scrolled);
      mtbLogo.src = isNoHero ? MTB_LOGO_WHITE : (scrolled ? MTB_LOGO_WHITE : MTB_LOGO_DARK);

      // mobile bottom nav — same transparent→white swap as the top bar
      bottomNav.classList.toggle('scrolled', scrolled);
    }
    updateScrollState();
    window.addEventListener('scroll', updateScrollState, { passive: true });

    const savedState = localStorage.getItem('sb_collapsed');
    const isTabletWidth = window.innerWidth >= MOBILE_BREAKPOINT && window.innerWidth < 1024;
    if (savedState === '1' || (savedState === null && isTabletWidth)) {
      sidebar.classList.add('collapsed');
    }

    // Alisin na ang no-transition guard sa susunod na frame — sa puntong ito
    // tapos na ang unang paint sa tamang width, kaya ligtas nang i-enable
    // ulit ang smooth transition para sa mga susunod na click ng user.
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        sidebar.classList.remove('no-transition');
      });
    });

    function updateHeroOffsetVar() {
      if (window.innerWidth <= MOBILE_BREAKPOINT) {
        document.documentElement.style.setProperty('--sb-current-offset', '0px');
        return;
      }
      const isCollapsed = sidebar.classList.contains('collapsed');
      document.documentElement.style.setProperty('--sb-current-offset', isCollapsed ? '84px' : '280px');
    }
    updateHeroOffsetVar();
    window.addEventListener('resize', updateHeroOffsetVar);

    collapseBtn.addEventListener('click', function () {
      sidebar.classList.toggle('collapsed');
      const isCollapsed = sidebar.classList.contains('collapsed');
      localStorage.setItem('sb_collapsed', isCollapsed ? '1' : '0');
      updateHeroOffsetVar();
    });

    document.querySelectorAll('.sb-link').forEach(function (link) {
      link.addEventListener('click', function () {
        document.querySelectorAll('.sb-link').forEach(l => l.classList.remove('active'));
        this.classList.add('active');
      });
    });

    /* ─────────────────────────────────────────────
       MOBILE BOTTOM NAV — active state (nav links only, not the More
       trigger — that opens a sheet instead of "going" anywhere)
    ───────────────────────────────────────────── */
    document.querySelectorAll('.mbn-link:not(.mbn-more)').forEach(function (link) {
      link.addEventListener('click', function () {
        document.querySelectorAll('.mbn-link:not(.mbn-more)').forEach(l => l.classList.remove('active'));
        this.classList.add('active');
      });
    });

    /* ─────────────────────────────────────────────
       "MORE" SHEET — open/close + syncing the active state back onto
       the More button whenever one of the grouped links is picked
    ───────────────────────────────────────────── */
    const moreBtn = document.getElementById('mbnMoreBtn');
    const moreSheet = document.getElementById('mobileMoreSheet');
    const msBackdrop = document.getElementById('msBackdrop');
    const msCloseBtn = document.getElementById('msCloseBtn');

    function openMoreSheet() {
      moreSheet.classList.add('open');
      moreSheet.setAttribute('aria-hidden', 'false');
      moreBtn.classList.add('sheet-open');
      document.body.style.overflow = 'hidden';
    }
    function closeMoreSheet() {
      // Kung may naka-focus na element sa LOOB ng sheet (hal. yung X button
      // o isa sa ms-link na na-click), kailangan muna nating alisin ang
      // focus doon BAGO natin i-set ang aria-hidden="true" — kundi babala
      // ang browser dahil hindi puwedeng may "naka-focus" na element sa
      // loob ng isang seksyong sinasabi nating nakatago sa screen readers.
      if (moreSheet.contains(document.activeElement)) {
        moreBtn.focus();
      }
      moreSheet.classList.remove('open');
      moreSheet.setAttribute('aria-hidden', 'true');
      moreBtn.classList.remove('sheet-open');
      document.body.style.overflow = '';
    }

    moreBtn.addEventListener('click', function () {
      if (moreSheet.classList.contains('open')) {
        closeMoreSheet();
      } else {
        openMoreSheet();
      }
    });
    msBackdrop.addEventListener('click', closeMoreSheet);
    msCloseBtn.addEventListener('click', closeMoreSheet);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeMoreSheet();
    });

    document.querySelectorAll('.ms-link').forEach(function (link) {
      link.addEventListener('click', function () {
        // clear active off the visible bottom-nav links, mark More as the
        // "active" section since the chosen page lives inside it
        document.querySelectorAll('.mbn-link:not(.mbn-more)').forEach(l => l.classList.remove('active'));
        closeMoreSheet();
      });
    });
  })();
</script>

<?php
/*
  PAANO I-INTEGRATE SA MAIN LAYOUT:

  1. I-include ang file na ito agad pagkatapos ng <body> tag:
       <?php include 'realiving_sidebar.php'; ?>

  2. I-wrap ang existing na page content mo sa isang div na may class="main-content":
       <div class="main-content">
         ... existing hero, sections, footer, etc ...
       </div>

  3. DESKTOP (>1024px) — WALANG binago. Parehong collapsible sidebar,
     lahat ng 7 links + Book Now, blur-to-white sa scroll, gaya ng dati.

  4. MOBILE (<=1024px) — bagong dalawang piraso:

     a) #mobileTopBar — slim floating bar sa itaas, logo lang, naka-left.
        Transparent + blurred habang nasa ibabaw ng hero (parehong
        ::before/::after blur trick ng sidebar), tapos nagiging SOLID
        WHITE + dark logo pagka-scroll lagpas 60px (SCROLL_THRESHOLD,
        same variable, isang lugar mo lang babaguhin kung gusto mo
        i-adjust yung threshold — apektado both sidebar at top bar).

     b) #mobileBottomNav — floating island, pareho ng theme/hardware-knob
        center button, pero ngayon 4 slots + 1 "More" na lang ang
        directly visible: Home, Concepts, [Book Now knob], Projects,
        More. Yung 4 na natitirang link (About, Services, What's New,
        Contact) ay naka-GROUP sa loob ng #mobileMoreSheet — isang
        bottom sheet na lumalabas pag pinindot ang "More" (backdrop,
        slide-up panel, may sariling icons+labels, may X button at
        pwede ring i-tap yung backdrop o Esc key para isara). Kaya
        LAHAT ng 7 original links + Book Now ay tunay na naa-access sa
        mobile, hindi lang nakasksik.

  5. Kung gusto mong palitan kung alin ang laging nasa bar (hal. gusto
     mong "Contact" ang laging visible instead of "Projects"), hanapin
     lang yung <nav id="mobileBottomNav"> block at ilipat mo yung
     <a> mula doon papunta sa .ms-links list sa #mobileMoreSheet
     (o kabaliktaran). Walang babaguhin sa JS — automatic siyang
     susunod dahil event listeners ay naka-attach sa class, hindi sa
     specific na link.

  6. Dahil FLOATING/OVERLAY lang ang bottom nav (hindi pumupush ng
     content) at ang top bar ay nakapatong din sa taas, siguraduhing
     may sapat na padding ang unang at huling section ng bawat page
     para hindi natatakpan:

       @media (max-width: 1024px) {
         .main-content > *:first-child { padding-top: 72px; } // kung
           hindi hero/full-bleed ang unang section — kung hero naman
           (parang dating design), pwede itong i-skip para tuluyang
           tumakip ang top bar sa hero, gaya ng dati.
         footer, #inquiry-section { padding-bottom: 100px; }
       }

  7. Ang mga href="#" sa lahat ng links (sidebar, bottom nav, at More
     sheet) ay placeholder pa rin — palitan mo na lang ng totoong URLs
     kapag ready ka nang i-wire ang navigation.
*/