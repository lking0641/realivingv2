<?php
//realiving_about
session_name("Realivinguser");
session_start();
include $includes['connection'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>About Us — Realiving Design Center</title>
  <link rel="icon" type="image/png" href="<?= CLIENT_ASSET ?>/images/logo/favicon.png">

  <link
    href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@400;500;600&display=swap&font-display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />

  <link rel="stylesheet" href="<?= BASE_ASSET ?>assets/css/output.css">

  <style>
    .reveal-up {
      opacity: 0;
      transform: translateY(28px);
      transition: opacity 0.8s ease-out, transform 0.8s ease-out;
      will-change: opacity, transform;
    }
    .reveal-up.in-view { opacity: 1; transform: translateY(0); }

    /* Blueprint corner brackets — the signature motif for this page */
    .bp-bracket { pointer-events: none; position: absolute; width: 18px; height: 18px; border-color: #c4905c; transition: all 0.35s ease; }
    .group:hover .bp-bracket { width: 26px; height: 26px; }
    .bp-tl { top: -1px; left: -1px; border-top: 2px solid; border-left: 2px solid; }
    .bp-tr { top: -1px; right: -1px; border-top: 2px solid; border-right: 2px solid; }
    .bp-bl { bottom: -1px; left: -1px; border-bottom: 2px solid; border-left: 2px solid; }
    .bp-br { bottom: -1px; right: -1px; border-bottom: 2px solid; border-right: 2px solid; }

    /* Core Values marquee — position is driven by JS (see script below) so
       it can be grabbed and dragged; no CSS keyframes here on purpose. */
    .marquee-track {
      will-change: transform;
    }
    .marquee-wrap {
      cursor: grab;
      touch-action: pan-y;
      -webkit-user-select: none;
      user-select: none;
    }
    .marquee-wrap.dragging {
      cursor: grabbing;
    }
  </style>
</head>

<body class="about-page bg-[#faf8f6] no-hero">

  <?php include $includes['header']; ?>

  <div class="main-content">

    <!-- ═══════════════════════════════
         HERO / SUB-HEADER
    ═══════════════════════════════ -->
    <section class="relative h-[52vh] w-full overflow-hidden sm:h-[60vh] md:h-[68vh]" id="aboutHero">
      <img src="<?= CLIENT_ASSET ?>/images/background-image.jpg" alt="Realiving Design Center"
        class="absolute inset-0 h-full w-full scale-105 object-cover object-center">

      <div class="absolute inset-0 bg-black/40"></div>
      <div class="absolute inset-0 bg-gradient-to-t from-[#2f1200]/85 via-[#2f1200]/25 to-[#2f1200]/10"></div>

      <div class="relative z-10 flex h-full w-full flex-col items-center justify-center px-6 text-center text-white">
        <span class="mb-4 font-montserrat text-[10px] font-semibold uppercase tracking-[4px] text-[#e8c9a0] sm:text-xs md:tracking-[6px]">
          Who We Are
        </span>

        <h1 class="max-w-2xl text-4xl italic leading-[1.15] sm:text-5xl md:text-6xl"
          style="font-family: 'Cormorant Garamond', serif;">
          About Realiving
        </h1>

        <p class="mt-5 max-w-md font-montserrat text-[13px] font-light leading-relaxed text-white/80 sm:text-sm">
          Quezon City's atelier for modular cabinetry, fixtures, and interior craft.
        </p>

        <div class="mt-9 flex items-center gap-2 font-montserrat text-[10px] uppercase tracking-[2px] text-white/55">
          <a href="<?= BASE_URL ?>" class="transition-colors hover:text-[#c4905c]">Home</a>
          <i class="ri-arrow-right-s-line"></i>
          <span class="text-[#c4905c]">About Us</span>
        </div>
      </div>
    </section>

    <!-- ═══════════════════════════════
         VISION & MISSION — blueprint bracket cards
    ═══════════════════════════════ -->
    <section class="reveal-up bg-[#faf8f6] py-20 md:py-28" id="vision-mission">
      <div class="mx-auto max-w-6xl px-6">

        <div class="mb-14 text-center">
          <span class="mb-3 inline-block font-montserrat text-[10px] font-bold uppercase tracking-[3px] text-[#c4905c]">
            The Blueprint
          </span>
          <h2 class="font-montserrat text-3xl font-bold uppercase tracking-wide text-[#2f1200] sm:text-4xl">
            Vision &amp; Mission
          </h2>
          <div class="mx-auto mt-4 h-0.5 w-16 rounded-full bg-[#c4905c] opacity-60"></div>
        </div>

        <div class="relative grid grid-cols-1 gap-8 md:grid-cols-2 md:gap-10">

          <div class="pointer-events-none absolute left-1/2 top-0 hidden h-full w-px -translate-x-1/2 border-l border-dashed border-[#c4905c]/40 md:block"></div>

          <div class="group relative bg-white p-10 shadow-[0_10px_35px_rgba(47,18,0,0.07)] md:p-12">
            <span class="bp-bracket bp-tl"></span><span class="bp-bracket bp-tr"></span>
            <span class="bp-bracket bp-bl"></span><span class="bp-bracket bp-br"></span>

            <span class="mb-3 block font-montserrat text-[10px] font-bold uppercase tracking-[3px] text-[#c4905c]">Vision</span>
            <h3 class="mb-4 text-2xl italic text-[#2f1200]" style="font-family: 'Cormorant Garamond', serif;">
              Where We're Headed
            </h3>
            <p class="text-[15px] leading-relaxed text-[#5e5e5e]">
              To be the elite provider of interiors and to be the forefront of the special architectural
              industry in the Philippines.
            </p>
          </div>

          <div class="group relative bg-white p-10 shadow-[0_10px_35px_rgba(47,18,0,0.07)] md:p-12">
            <span class="bp-bracket bp-tl"></span><span class="bp-bracket bp-tr"></span>
            <span class="bp-bracket bp-bl"></span><span class="bp-bracket bp-br"></span>

            <span class="mb-3 block font-montserrat text-[10px] font-bold uppercase tracking-[3px] text-[#c4905c]">Mission</span>
            <h3 class="mb-4 text-2xl italic text-[#2f1200]" style="font-family: 'Cormorant Garamond', serif;">
              How We Get There
            </h3>
            <p class="text-[15px] leading-relaxed text-[#5e5e5e]">
              To provide customized and sustainable modular cabinet solutions, utilizing cutting-edge
              technology, skilled craftsmanship, and a customer-centric approach, while ensuring timely
              delivery and exceeding client expectations.
            </p>
          </div>

        </div>
      </div>
    </section>

    <!-- ═══════════════════════════════
         OUR COMPANY / STORY
    ═══════════════════════════════ -->
    <section class="reveal-up bg-white py-20 md:py-28" id="our-company">
      <div class="mx-auto max-w-7xl px-6">
        <div class="grid grid-cols-1 items-center gap-12 lg:grid-cols-2 lg:gap-16">

          <div>
            <span class="mb-3 inline-block font-montserrat text-[10px] font-bold uppercase tracking-[3px] text-[#c4905c]">
              Our Story
            </span>
            <h2 class="mb-6 max-w-lg text-3xl italic leading-[1.2] text-[#2f1200] sm:text-4xl"
              style="font-family: 'Cormorant Garamond', serif;">
              Building Trust, Room By Room
            </h2>

            <p class="mb-4 text-[15px] leading-loose text-[#5e5e5e]">
              Realiving Design Center Corporation is one of the biggest building material suppliers in the
              Philippines. Realflooring, ECON Global, and GrandEast Aluminum and Door each represent the top
              level in their respective field in the country.
            </p>
            <p class="text-[15px] leading-loose text-[#5e5e5e]">
              Duly incorporated and situated at 1181 2nd Flr MC Premiere Balintawak, Quezon City, we primarily
              engage in wholesale and retail of competitively priced, high-quality construction, plumbing, and
              decorative materials to both projects and retailers nationwide — from modular kitchen cabinets
              and shower enclosures to lavatory fixtures, aluminum windows, bathtubs, and ceramic items.
            </p>

            <div class="mt-8 flex flex-wrap gap-3">
              <span class="rounded-full border border-[#c4905c]/30 bg-[#faf8f6] px-4 py-2 font-montserrat text-[11px] font-semibold uppercase tracking-[1px] text-[#2f1200]">
                Realflooring
              </span>
              <span class="rounded-full border border-[#c4905c]/30 bg-[#faf8f6] px-4 py-2 font-montserrat text-[11px] font-semibold uppercase tracking-[1px] text-[#2f1200]">
                ECON Global
              </span>
              <span class="rounded-full border border-[#c4905c]/30 bg-[#faf8f6] px-4 py-2 font-montserrat text-[11px] font-semibold uppercase tracking-[1px] text-[#2f1200]">
                GrandEast Aluminum &amp; Door
              </span>
            </div>
          </div>

          <div class="group relative">
            <span class="bp-bracket bp-tl"></span><span class="bp-bracket bp-tr"></span>
            <span class="bp-bracket bp-bl"></span><span class="bp-bracket bp-br"></span>

            <div class="relative overflow-hidden rounded-sm shadow-[0_20px_50px_rgba(47,18,0,0.18)]">
              <video autoplay loop muted playsinline class="h-[320px] w-full object-cover sm:h-[400px] md:h-[460px]">
                <source src="<?= BASE_ASSET ?>/videos/realiving.mp4" type="video/mp4">
                Your browser does not support the video tag.
              </video>
              <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-[#2f1200]/30 via-transparent to-transparent"></div>
            </div>
          </div>

        </div>
      </div>
    </section>

    <!-- ═══════════════════════════════
         CORE VALUES — seamless marquee, desktop through mobile
    ═══════════════════════════════ -->
    <section class="reveal-up relative overflow-hidden py-20 md:py-24" id="core-values">
      <img src="<?= CLIENT_ASSET ?>/images/background-image2.jpg" alt=""
        class="absolute inset-0 h-full w-full object-cover object-center">
      <div class="absolute inset-0 bg-[#2f1200]/75"></div>
      <div class="absolute inset-0 bg-gradient-to-b from-[#2f1200]/40 via-transparent to-[#2f1200]/60"></div>

      <div class="relative z-10 mx-auto max-w-7xl px-4">
        <div class="mb-14 text-center">
          <span class="mb-3 inline-block font-montserrat text-[10px] font-bold uppercase tracking-[3px] text-[#e8c9a0]">
            What Guides Us
          </span>
          <h2 class="font-montserrat text-3xl font-bold uppercase tracking-wide text-white sm:text-4xl">
            Our Core Values
          </h2>
          <div class="mx-auto mt-4 h-0.5 w-16 rounded-full bg-[#c4905c] opacity-70"></div>
        </div>

        <?php
        $core_values = [
          ['icon' => 'excellence-icon.png', 'title' => 'Excellence', 'desc' => 'We strive for excellence in every aspect of our work, from design conception to project completion.'],
          ['icon' => 'innovation-icon.png', 'title' => 'Innovation', 'desc' => 'We embrace innovation and continuously explore new techniques and materials to enhance our products and services.'],
          ['icon' => 'collaboration-icon.png', 'title' => 'Collaboration', 'desc' => "We believe in the power of collaboration and aim to build strong partnerships with industry professionals."],
          ['icon' => 'integrity-icon.png', 'title' => 'Integrity', 'desc' => 'We conduct our business with integrity, transparency, and ethical practices.'],
          ['icon' => 'satisfaction-icon.png', 'title' => 'Customer Satisfaction', 'desc' => "We focus on customer satisfaction by delivering solutions tailored to each client's needs."],
        ];

        // Renders one card. $hidden = true for the duplicate set that
        // powers the seamless loop — kept out of the accessibility tree
        // so screen readers / tab order only see the values once.
        function render_cv_card($cv, $hidden = false) {
          $aria = $hidden ? 'aria-hidden="true"' : '';
          $tab  = $hidden ? 'tabindex="-1"' : '';
          ob_start();
          ?>
          <div <?= $aria ?> class="core-value-card group relative flex min-w-[270px] max-w-[270px] shrink-0 flex-col items-center overflow-hidden rounded-2xl bg-white p-8 text-center shadow-[0_16px_40px_rgba(0,0,0,0.2)] sm:min-w-[290px] sm:max-w-[290px]">
            <div class="absolute inset-x-0 top-0 h-1.5 bg-[#c4905c]"></div>
            <div class="mb-5 flex h-16 w-16 items-center justify-center rounded-full border border-[#c4905c]/30 bg-[#faf8f6] transition-colors duration-300 group-hover:bg-[#2f1200]">
              <img <?= $tab ?> src="<?= CLIENT_ASSET ?>/images/icon/<?= $cv['icon'] ?>" alt="<?= $hidden ? '' : htmlspecialchars($cv['title']) . ' Icon' ?>"
                class="h-8 w-8 object-contain">
            </div>
            <h3 class="mb-2.5 font-montserrat text-[15px] font-bold uppercase tracking-[1px] text-[#2f1200]">
              <?= htmlspecialchars($cv['title']) ?>
            </h3>
            <p class="text-[13px] leading-relaxed text-gray-500">
              <?= htmlspecialchars($cv['desc']) ?>
            </p>
          </div>
          <?php
          return ob_get_clean();
        }
        ?>

        <div class="marquee-wrap overflow-hidden" id="cvMarqueeWrap"
          style="mask-image: linear-gradient(to right, transparent 0, black 40px, black calc(100% - 40px), transparent 100%);
                 -webkit-mask-image: linear-gradient(to right, transparent 0, black 40px, black calc(100% - 40px), transparent 100%);">
          <div class="marquee-track flex w-max gap-6 px-2" id="cvMarqueeTrack" style="transform: translateX(0px);">
            <?php foreach ($core_values as $cv) echo render_cv_card($cv, false); ?>
            <?php foreach ($core_values as $cv) echo render_cv_card($cv, true); ?>
          </div>
        </div>

      </div>
    </section>

    <!-- ═══════════════════════════════
         COMPANY QUOTE
    ═══════════════════════════════ -->
    <section class="reveal-up bg-[#faf8f6] py-20 text-center md:py-24">
      <div class="mx-auto max-w-3xl px-6">
        <i class="ri-double-quotes-l block text-3xl text-[#c4905c]/60"></i>
        <blockquote class="mt-4 text-2xl italic leading-snug text-[#2f1200] sm:text-3xl md:text-4xl"
          style="font-family: 'Cormorant Garamond', serif;">
          Making your dream space a reality.
        </blockquote>
      </div>
    </section>

    <!-- ═══════════════════════════════
         COMPANY PROFILE FLIPBOOK
    ═══════════════════════════════ -->
    <section class="reveal-up bg-[#2f1200] py-16 pb-[110px] md:py-20 md:pb-20" id="company-profile" style="scroll-margin-top: 90px;">
      <div class="mx-auto max-w-6xl px-6">

        <div class="mb-10 flex flex-col items-start justify-between gap-6 sm:flex-row sm:items-end">
          <div>
            <span class="mb-3 inline-block font-montserrat text-[10px] font-bold uppercase tracking-[3px] text-[#c4905c]">
              Company Profile
            </span>
            <h2 class="max-w-md text-2xl italic leading-tight text-white sm:text-3xl"
              style="font-family: 'Cormorant Garamond', serif;">
              Get to Know Us Better
            </h2>
            <p class="mt-3 max-w-sm font-montserrat text-[13px] leading-relaxed text-white/60">
              Browse through our company profile and discover what makes Realiving exceptional.
            </p>
          </div>

          <a href="../../videos/REALIVING COMPANY PROFILE.pdf" download="Realiving_Company_Profile.pdf"
            class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap bg-[#c4905c] px-7 py-3.5 font-montserrat text-[11px] font-bold uppercase tracking-[2px] text-[#2f1200] transition-all duration-300 hover:bg-white">
            <i class="ri-download-2-line"></i> Download PDF
          </a>
        </div>

        <div class="group relative overflow-hidden rounded-2xl border border-[#c4905c]/20 shadow-[0_25px_60px_rgba(0,0,0,0.35)]">
          <iframe allowfullscreen="allowfullscreen" allow="clipboard-write" scrolling="no"
            class="block h-[420px] w-full border-0 sm:h-[520px] md:h-[650px]"
            src="https://heyzine.com/flip-book/340e032c45.html" title="Realiving Company Profile Flipbook"></iframe>
        </div>

      </div>
    </section>

    <?php include $includes['footer']; ?>

  </div>
  <!-- ↑ closing .main-content -->

  

  <script>
    document.addEventListener('DOMContentLoaded', function () {
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

      // Core Values marquee — auto-drifts at idle, but the user can grab it
      // (mouse or touch) and drag left/right to take over control directly.
      const wrap = document.getElementById('cvMarqueeWrap');
      const track = document.getElementById('cvMarqueeTrack');

      if (wrap && track) {
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const AUTO_SPEED = reduceMotion ? 0 : 0.45; // px per frame

        let pos = 0;          // current scroll position (px)
        let halfWidth = 0;    // width of ONE set of cards (track is duplicated)
        let dragging = false;
        let hovering = false;
        let startX = 0;
        let startPos = 0;
        let lastX = 0;
        let lastT = 0;
        let velocity = 0;     // px/ms, for a bit of momentum on release

        function recalcWidth() {
          halfWidth = track.scrollWidth / 2;
        }
        recalcWidth();
        window.addEventListener('resize', recalcWidth);
        window.addEventListener('load', recalcWidth);

        function applyTransform() {
          if (halfWidth > 0) {
            // wrap seamlessly so it looks endless in both directions
            pos = ((pos % halfWidth) + halfWidth) % halfWidth;
          }
          track.style.transform = 'translateX(' + (-pos) + 'px)';
        }

        function pointerX(e) {
          return e.touches && e.touches.length ? e.touches[0].clientX : e.clientX;
        }

        function onPointerDown(e) {
          dragging = true;
          velocity = 0;
          startX = lastX = pointerX(e);
          startPos = pos;
          lastT = performance.now();
          wrap.classList.add('dragging');
          if (e.pointerId !== undefined) {
            try { wrap.setPointerCapture(e.pointerId); } catch (_) {}
          }
        }

        function onPointerMove(e) {
          if (!dragging) return;
          const x = pointerX(e);
          const delta = x - startX;
          pos = startPos - delta;

          const now = performance.now();
          const dt = now - lastT;
          if (dt > 0) velocity = (lastX - x) / dt; // px per ms, drag direction
          lastX = x;
          lastT = now;

          applyTransform();
        }

        function onPointerUp(e) {
          if (!dragging) return;
          dragging = false;
          wrap.classList.remove('dragging');
          if (e && e.pointerId !== undefined) {
            try { wrap.releasePointerCapture(e.pointerId); } catch (_) {}
          }
        }

        // Pointer Events cover mouse + touch + pen in one set of handlers
        wrap.addEventListener('pointerdown', onPointerDown);
        wrap.addEventListener('pointermove', onPointerMove);
        wrap.addEventListener('pointerup', onPointerUp);
        wrap.addEventListener('pointercancel', onPointerUp);
        wrap.addEventListener('pointerleave', function (e) { if (dragging) onPointerUp(e); });

        wrap.addEventListener('mouseenter', () => { hovering = true; });
        wrap.addEventListener('mouseleave', () => { hovering = false; });

        // prevent the card icons from triggering a native drag-image ghost
        wrap.addEventListener('dragstart', (e) => e.preventDefault());

        function tick() {
          if (dragging) {
            // let momentum decay naturally into a gentle drift on release
            velocity *= 0.94;
          } else {
            if (Math.abs(velocity) > 0.02) {
              pos += velocity * 16; // approximate one frame at ~60fps
              velocity *= 0.94;
            } else if (!hovering) {
              pos += AUTO_SPEED;
            }
          }
          applyTransform();
          requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
      }
    });
  </script>

</body>

</html>