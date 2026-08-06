<?php
//services.php
session_name("Realivinguser");
session_start();
include $includes['connection'];
include $includes['header'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Services — Realiving Design Center</title>
  <link rel="icon" type="image/png" href="<?= CLIENT_ASSET ?>/images/logo/favicon.png">

  <link
    href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@400;500;600&display=swap&font-display=swap"
    rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />

  <style>
    html { scroll-behavior: smooth; }

    .reveal-up {
      opacity: 0;
      transform: translateY(28px);
      transition: opacity 0.8s ease-out, transform 0.8s ease-out;
      will-change: opacity, transform;
    }
    .reveal-up.in-view { opacity: 1; transform: translateY(0); }

    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

    /* Blueprint corner brackets — same signature motif as the About page */
    .bp-bracket { pointer-events: none; position: absolute; width: 18px; height: 18px; border-color: #c4905c; transition: all 0.35s ease; }
    .group:hover .bp-bracket { width: 26px; height: 26px; }
    .bp-tl { top: -1px; left: -1px; border-top: 2px solid; border-left: 2px solid; }
    .bp-tr { top: -1px; right: -1px; border-top: 2px solid; border-right: 2px solid; }
    .bp-bl { bottom: -1px; left: -1px; border-bottom: 2px solid; border-left: 2px solid; }
    .bp-br { bottom: -1px; right: -1px; border-bottom: 2px solid; border-right: 2px solid; }

    /* ═══════════════════════════════════════════════════════
       DESKTOP — sticky process rail, scroll-spy active state
       ═══════════════════════════════════════════════════════ */
    /* The <aside> must stretch to the full height of its sibling
       content column, otherwise the sticky child has almost no
       room to travel and appears to "not stick" at all. */
    .services-row { align-items: stretch; }
    #processRailWrap { position: sticky; top: 8rem; }

    .process-rail-link { color: #9a9a9a; }
    .process-rail-link .process-rail-dot {
      background: #faf8f6; border: 2px solid rgba(196,144,92,0.35);
      transition: all 0.3s ease;
    }
    .process-rail-link .process-rail-num { color: rgba(196,144,92,0.55); transition: color 0.3s ease; }
    .process-rail-link.is-active { color: #2f1200; }
    .process-rail-link.is-active .process-rail-dot { background: #c4905c; border-color: #c4905c; box-shadow: 0 0 0 4px rgba(196,144,92,0.15); }
    .process-rail-link.is-active .process-rail-num { color: #c4905c; }

    /* progress fill that climbs the rail's vertical line as you scroll */
    #processRail .rail-line-fill {
      position: absolute; left: 7px; top: 4px; width: 1px; height: 0;
      background: #c4905c; transition: height 0.35s ease;
    }

    /* ═══════════════════════════════════════════════════════
       MOBILE / TABLET — sticky "marquee" step chooser
       ═══════════════════════════════════════════════════════ */
    .mobile-stepper-wrap {
      position: sticky;
      top: 0;
      z-index: 40;
      background: #ffffff;
      border-bottom: 1px solid rgba(47,18,0,0.08);
      box-shadow: 0 6px 14px rgba(47,18,0,0.04);
    }
    /* phones: mobile top bar (72px) is visible — stick right under it */
    @media (max-width: 767px) {
      .mobile-stepper-wrap { top: 72px; }
    }
    /* tablets: mobile top bar is hidden here (desktop sidebar takes
       over instead), so there's nothing to clear — stick to the very
       top of the viewport */
    @media (min-width: 768px) and (max-width: 1023px) {
      .mobile-stepper-wrap { top: 0; }
    }
    .mobile-stepper {
      display: flex;
      flex-direction: column;
      padding: 10px 18px 12px;
    }
    .stepper-track { display: flex; align-items: center; margin-bottom: 6px; }
    .stepper-node {
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      width: 19px;
      height: 19px;
      border-radius: 50%;
      border: 1.5px solid rgba(47,18,0,0.2);
      color: #9a9a9a;
      font-family: 'Montserrat', sans-serif;
      font-size: 10px;
      font-weight: 600;
      text-decoration: none;
      transition: background 0.3s ease, border-color 0.3s ease, color 0.3s ease;
    }
    .stepper-node.is-active,
    .stepper-node.is-complete { background: #2f1200; border-color: #2f1200; color: #e8c9a0; }
    .stepper-line {
      flex: 1;
      height: 1.5px;
      margin: 0 2px;
      background: rgba(47,18,0,0.15);
      transition: background 0.3s ease;
    }
    .stepper-line.is-complete { background: #2f1200; }
    .stepper-title-row {
      text-align: center;
      font-family: 'Cormorant Garamond', serif;
      font-style: italic;
      font-size: 13px;
      color: #2f1200;
      transition: opacity 0.2s ease;
    }

    /* keep first/last section clear of the fixed mobile chrome */
    @media (max-width: 767px) {
      .services-hero-section { padding-top: 72px; }
      .services-content-end { padding-bottom: 110px; }
    }

    /* Video loading spinner — toggled via .loading on the wrapper */
    .video-wrapper-service .video-loading-spinner { opacity: 0; transition: opacity 0.3s ease; }
    .video-wrapper-service.loading .video-loading-spinner { opacity: 1; }

    /* ═══════════════════════════════════════════════════════
       TABLET FIX (768–1023px) — force the collapsed sidebar
       look so it never eats into the services content/rail.
       This is a page-level patch; long-term the same auto-
       collapse rule belongs in realiving_sidebar.php so every
       page benefits, not just this one.
       ═══════════════════════════════════════════════════════ */
    @media (min-width: 768px) and (max-width: 1023px) {
      main.services-main { padding-left: 4px; padding-right: 4px; }
    }
  </style>
</head>

<body class="bg-white no-hero">

  <div class="main-content">

    <!-- ═══════════════════════════════
         HERO / SUB-HEADER
    ═══════════════════════════════ -->
    <section class="services-hero-section relative h-[42vh] w-full overflow-hidden sm:h-[48vh] md:h-[54vh]">
      <img src="<?= CLIENT_ASSET ?>/images/background-image.jpg" alt="Realiving Design Center"
        class="absolute inset-0 h-full w-full scale-105 object-cover object-center">

      <div class="absolute inset-0 bg-black/40"></div>
      <div class="absolute inset-0 bg-gradient-to-t from-[#2f1200]/85 via-[#2f1200]/25 to-[#2f1200]/10"></div>

      <div class="relative z-10 flex h-full w-full flex-col items-center justify-center px-6 text-center text-white">
        <span class="mb-4 font-montserrat text-[10px] font-semibold uppercase tracking-[4px] text-[#e8c9a0] sm:text-xs md:tracking-[6px]">
          What We Do
        </span>

        <h1 class="max-w-2xl text-4xl italic leading-[1.15] sm:text-5xl md:text-6xl"
          style="font-family: 'Cormorant Garamond', serif;">
          Our Services
        </h1>

        <p class="mt-5 max-w-md font-montserrat text-[13px] font-light leading-relaxed text-white/80 sm:text-sm">
          Design, fabrication, delivery, and installation — crafted with precision and style.
        </p>

        <div class="mt-9 flex items-center gap-2 font-montserrat text-[10px] uppercase tracking-[2px] text-white/55">
          <a href="<?= BASE_URL ?>" class="transition-colors hover:text-[#c4905c]">Home</a>
          <i class="ri-arrow-right-s-line"></i>
          <span class="text-[#c4905c]">Services</span>
        </div>
      </div>
    </section>

    <!-- ═══════════════════════════════
         INTRO
    ═══════════════════════════════ -->
    <div class="reveal-up mx-auto max-w-2xl px-6 pb-10 pt-16 text-center md:pt-28">
      <span class="mb-3 inline-block font-montserrat text-[10px] font-bold uppercase tracking-[3px] text-[#c4905c]">
        Start To Finish
      </span>
      <p class="font-montserrat text-[15px] leading-relaxed text-[#5e5e5e] md:text-base">
        At Realiving Design Center, we offer top-notch design, fabrication, delivery, and installation
        services — all crafted with precision and style to bring your spaces to life.
      </p>
    </div>

    <?php
    // Build one unified list of steps, whether they come from the DB or
    // the static fallback — everything downstream (rail + stepper + content)
    // reads from this single array.
    $items = [];

    $services_query = "SELECT * FROM services_section WHERE is_active = 1 ORDER BY display_order ASC, service_number ASC";
    $services_result = $conn->query($services_query);

    if ($services_result && $services_result->num_rows > 0) {
      while ($service = $services_result->fetch_assoc()) {
        $items[] = [
          'title' => $service['title'],
          'desc'  => !empty($service['detailed_description']) ? $service['detailed_description'] : $service['description'],
          'media' => !empty($service['image_path']) ? ltrim($service['image_path'], './') : 'images/services/default.png',
          'is_video' => isset($service['media_type']) && $service['media_type'] === 'video',
        ];
      }
    } else {
      $items = [
        [
          'title' => 'Design',
          'media' => 'images/services/Design.png',
          'is_video' => false,
          'desc' => "We visit the client's site to take precise measurements, ensuring the product will fit seamlessly into the space and accommodate any existing features or structures.",
        ],
        [
          'title' => 'Fabricate',
          'media' => 'images/services/Fabricate.png',
          'is_video' => false,
          'desc' => "All approved designs are built at our own factory in Bulacan, where we ensure quality craftsmanship and attention to detail.\n\nWe fabricate custom furniture and fixtures such as:\n- Residential or Office\n- Cabinets & Wardrobes\n- Desks & Study Tables\n- Drawers & Side Tables\n\nEach piece is made to match your exact requirements, combining durability with smart design.",
        ],
        [
          'title' => 'Delivery',
          'media' => 'images/services/Delivery.png',
          'is_video' => false,
          'desc' => "We ensure a smooth and secure delivery process for all fabricated items. Your furniture and fixtures are carefully handled, packed, and transported to arrive at your location in excellent condition—on time and ready for installation.",
        ],
        [
          'title' => 'Installation',
          'media' => 'images/services/Installation.png',
          'is_video' => false,
          'desc' => "Our skilled installation team takes care of the final step: assembling and fitting everything perfectly in your space. We make sure that each detail aligns with the original design, giving you a seamless and ready-to-use setup.",
        ],
      ];
    }

    $total = count($items);
    ?>

    <!-- ═══════════════════════════════
         MOBILE / TABLET — sticky segmented progress track.
         Numbered nodes fill in as each step passes, connected by a
         line that fills alongside them; the title below tracks
         whichever step is currently in view (synced from the same
         scroll-spy that drives the desktop rail). Tap a node to jump.
    ═══════════════════════════════ -->
    <div class="mobile-stepper-wrap lg:hidden">
      <div class="mobile-stepper" id="mobileStepper">
        <div class="stepper-track" id="stepperTrack">
          <?php foreach ($items as $idx => $it): $n = $idx + 1; ?>
            <a href="#step-<?= $n ?>" data-step="<?= $n ?>" data-title="<?= htmlspecialchars($it['title']) ?>"
              class="stepper-node <?= $n === 1 ? 'is-active' : '' ?>" aria-label="Go to step <?= $n ?>: <?= htmlspecialchars($it['title']) ?>"><?= $n ?></a>
            <?php if ($n < $total): ?>
              <div class="stepper-line" data-line="<?= $n ?>"></div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <div class="stepper-title-row" id="stepperTitle"><?= htmlspecialchars($items[0]['title']) ?></div>
      </div>
    </div>

    <!-- ═══════════════════════════════
         SERVICES — sticky rail (desktop) + content column
    ═══════════════════════════════ -->
    <main class="services-main mx-auto max-w-7xl px-6 pb-10 pt-8 md:pb-16 md:pt-4">
      <div class="services-row lg:flex lg:gap-16">

        <!-- Sticky process rail — desktop only -->
        <aside class="hidden shrink-0 lg:block lg:w-52">
          <div id="processRailWrap">
            <span class="mb-7 block font-montserrat text-[10px] font-bold uppercase tracking-[3px] text-[#c4905c]">
              The Process
            </span>
            <nav class="relative flex flex-col gap-9 pl-7" id="processRail">
              <div class="absolute bottom-1 left-[7px] top-1 w-px bg-[#2f1200]/10"></div>
              <div class="rail-line-fill" id="railLineFill"></div>
              <?php foreach ($items as $idx => $it): $n = $idx + 1; ?>
                <a href="#step-<?= $n ?>" data-step="<?= $n ?>" class="process-rail-link group relative flex items-baseline gap-2.5 no-underline transition-colors duration-300">
                  <span class="process-rail-dot absolute -left-7 top-[5px] h-[9px] w-[9px] rounded-full"></span>
                  <span class="process-rail-num font-normal italic" style="font-family:'Cormorant Garamond',serif; font-size:17px;">
                    <?= sprintf('%02d', $n) ?>
                  </span>
                  <span class="font-montserrat text-[11px] font-semibold uppercase tracking-[1.5px]">
                    <?= htmlspecialchars($it['title']) ?>
                  </span>
                </a>
              <?php endforeach; ?>
            </nav>
          </div>
        </aside>

        <!-- Content column -->
        <div class="min-w-0 flex-1">
          <?php foreach ($items as $idx => $it):
            $n = $idx + 1;
            $reversed = ($n % 2 === 0);
            $is_last  = ($n === $total);
            ?>
            <section id="step-<?= $n ?>"
              class="reveal-up scroll-mt-32 <?= $idx > 0 ? 'mt-16 border-t border-[#2f1200]/10 pt-16' : '' ?> <?= $is_last ? 'services-content-end' : '' ?>">

              <span class="mb-5 block font-montserrat text-[10px] font-bold uppercase tracking-[3px] text-[#c4905c] lg:hidden">
                Step <?= $n ?> / <?= $total ?>
              </span>

              <div class="grid gap-10 md:grid-cols-2 md:items-center md:gap-14">

                <!-- media, framed with blueprint brackets -->
                <div class="group relative <?= $reversed ? 'md:order-2' : '' ?>">
                  <span class="bp-bracket bp-tl"></span><span class="bp-bracket bp-tr"></span>
                  <span class="bp-bracket bp-bl"></span><span class="bp-bracket bp-br"></span>

                  <?php if ($it['is_video']): ?>
                    <div class="video-wrapper-service relative overflow-hidden rounded-sm shadow-[0_20px_45px_rgba(47,18,0,0.15)]">
                      <div class="video-loading-spinner pointer-events-none absolute left-1/2 top-1/2 z-[5] -translate-x-1/2 -translate-y-1/2 text-3xl text-white">
                        <i class="ri-loader-4-line animate-spin"></i>
                      </div>
                      <video class="service-detail-video block h-[260px] w-full object-cover sm:h-[320px] md:h-[380px] lg:h-[400px]"
                        autoplay muted loop playsinline preload="metadata">
                        <source src="<?= CLIENT_ASSET ?>/<?= htmlspecialchars($it['media']) ?>"
                          type="video/<?= pathinfo($it['media'], PATHINFO_EXTENSION) ?>">
                        Your browser does not support the video tag.
                      </video>
                    </div>
                  <?php else: ?>
                    <img src="<?= CLIENT_ASSET ?>/<?= htmlspecialchars($it['media']) ?>" alt="<?= htmlspecialchars($it['title']) ?>"
                      class="block h-[260px] w-full rounded-sm object-cover shadow-[0_20px_45px_rgba(47,18,0,0.15)] sm:h-[320px] md:h-[380px] lg:h-[400px]">
                  <?php endif; ?>
                </div>

                <!-- text -->
                <div class="<?= $reversed ? 'md:order-1' : '' ?>">
                  <span class="mb-3 hidden font-normal italic text-[#c4905c]/60 lg:inline-block" style="font-family:'Cormorant Garamond',serif; font-size:15px;">
                    <?= sprintf('%02d', $n) ?> / <?= sprintf('%02d', $total) ?>
                  </span>
                  <h2 class="mb-4 text-3xl italic text-[#2f1200] sm:text-4xl" style="font-family: 'Cormorant Garamond', serif;">
                    <?= htmlspecialchars($it['title']) ?>
                  </h2>
                  <div class="mb-5 h-px w-12 bg-[#c4905c]"></div>
                  <p class="font-montserrat text-[15px] leading-loose text-[#5e5e5e]">
                    <?= nl2br(htmlspecialchars($it['desc'])) ?>
                  </p>
                </div>

              </div>
            </section>
          <?php endforeach; ?>
        </div>

      </div>
    </main>

    <?php include $includes['footer']; ?>

  </div>
  <!-- ↑ closing .main-content -->

  

  <script>

    document.addEventListener('DOMContentLoaded', function () {

      /* ─────────────────────────────────────────────────────────
         TABLET FIX: force the sidebar into its collapsed state on
         768–1023px viewports so it can never overlap this page's
         content/pills, regardless of whatever was last saved in
         localStorage from a wider screen.
      ───────────────────────────────────────────────────────── */
      (function fixTabletSidebar(){
        var sb = document.getElementById('sidebar');
        if (!sb) return;
        function applyTabletCollapse(){
          if (window.innerWidth >= 768 && window.innerWidth < 1024) {
            sb.classList.add('collapsed');
          }
        }
        applyTabletCollapse();
        window.addEventListener('resize', applyTabletCollapse);
      })();

      // Scroll-reveal
      const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add('in-view');
            revealObserver.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -30px 0px' });

      document.querySelectorAll('.reveal-up').forEach(el => revealObserver.observe(el));

      /* ─────────────────────────────────────────────────────────
         SCROLL-SPY — single source of truth for "which step is
         active right now". Drives THREE things at once:
           1. the desktop sticky rail highlight
           2. the rail's climbing progress line
           3. the mobile/tablet segmented progress track
      ───────────────────────────────────────────────────────── */
      const railLinks     = document.querySelectorAll('.process-rail-link');
      const stepperNodes  = document.querySelectorAll('.stepper-node');
      const stepperLines  = document.querySelectorAll('.stepper-line');
      const stepSections  = document.querySelectorAll('[id^="step-"]');
      const railLineFill  = document.getElementById('railLineFill');
      const stepperTitle  = document.getElementById('stepperTitle');

      function setActiveStep(stepNum) {
        const current = Number(stepNum);
        railLinks.forEach(l => l.classList.toggle('is-active', l.dataset.step === String(stepNum)));

        // climb the rail's vertical progress line
        if (railLineFill && railLinks.length) {
          const activeLink = document.querySelector('.process-rail-link[data-step="' + stepNum + '"]');
          if (activeLink) {
            railLineFill.style.height = (activeLink.offsetTop + 4) + 'px';
          }
        }

        // fill the mobile/tablet segmented track up to the current step
        let activeTitle = null;
        stepperNodes.forEach(node => {
          const s = Number(node.dataset.step);
          node.classList.toggle('is-active', s === current);
          node.classList.toggle('is-complete', s < current);
          if (s === current) activeTitle = node.dataset.title;
        });
        stepperLines.forEach(line => {
          line.classList.toggle('is-complete', Number(line.dataset.line) < current);
        });
        if (stepperTitle && activeTitle) {
          stepperTitle.textContent = activeTitle;
        }
      }

      if (stepSections.length) {
        const spy = new IntersectionObserver((entries) => {
          entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            const stepNum = entry.target.id.replace('step-', '');
            setActiveStep(stepNum);
          });
        }, { rootMargin: '-35% 0px -50% 0px', threshold: 0 });

        stepSections.forEach(s => spy.observe(s));

        // set an initial active state on load (first step)
        setActiveStep(1);
      }

      // Auto-play service videos when they come into view
      const serviceVideos = document.querySelectorAll('.service-detail-video');

      serviceVideos.forEach(video => {
        const wrapper = video.closest('.video-wrapper-service');

        video.addEventListener('waiting', function () {
          wrapper.classList.add('loading');
        });

        video.addEventListener('canplay', function () {
          wrapper.classList.remove('loading');
        });

        video.play().catch(err => {
          console.log('Autoplay prevented:', err);
          wrapper.classList.remove('loading');
        });
      });

      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          const video = entry.target;
          if (entry.isIntersecting) {
            video.play().catch(err => console.log('Video play prevented:', err));
          }
        });
      }, { threshold: 0.5 });

      serviceVideos.forEach(video => observer.observe(video));
    });
  </script>

</body>

</html>