<?php
//realiving_main.php — Homepage

include $includes['connection'];

$hero_query = "SELECT * FROM hero_section WHERE is_active = 1 ORDER BY id DESC";
$hero_result = $conn->query($hero_query);

$team_query = "SELECT full_name, position, contact_number, social_gmail, social_wechat, social_viber,
               profile_picture, google_picture, avatar_source, wechat_qr_image, viber_qr_image
               FROM account WHERE show_team_card = 1 ORDER BY id ASC";
$team_result = $conn->query($team_query);
if (!$team_result) {
  echo '<pre style="background:#fee;padding:10px;">SQL ERROR: ' . $conn->error . '</pre>';
}
$team_members = [];
if ($team_result) {
  while ($t = $team_result->fetch_assoc()) {
    if (($t['avatar_source'] ?? 'custom') === 'google' && !empty($t['google_picture'])) {
      $t['avatar_url'] = $t['google_picture'];
    } elseif (!empty($t['profile_picture'])) {
      $t['avatar_url'] = BASE_URL . $t['profile_picture'];
    } else {
      $t['avatar_url'] = null;
    }
    $t['wechat_qr_url'] = !empty($t['wechat_qr_image']) ? BASE_URL . $t['wechat_qr_image'] : null;
    $t['viber_qr_url']  = !empty($t['viber_qr_image'])  ? BASE_URL . $t['viber_qr_image']  : null;
    $team_members[] = $t;
  }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Realiving Design Center</title>
  <meta name="description" content="Realiving Design Center — Custom cabinet design, fabrication, delivery, and installation. Transform your space with timeless design.">
  <link rel="icon" type="image/png" href="<?= CLIENT_ASSET ?>/images/logo/favicon.png">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>

  <link
    href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@400;500;600&display=swap&font-display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />

  <style>
    /* Ken Burns left-to-right pan — MOBILE ONLY, synced sa slide duration */
    @keyframes heroKenBurns {
      0% {
        transform: scale(1.2) translateX(-7%);
      }

      100% {
        transform: scale(1.2) translateX(7%);
      }
    }

    :root{ --sb-current-offset: 280px; }
@media (max-width: 767px){ :root{ --sb-current-offset: 0px; } }

#heroSlider{
  margin-left: calc(var(--sb-current-offset) * -1);
  width: calc(100% + var(--sb-current-offset));
}

#heroSlider > .relative.z-10{
  position: absolute;
  inset: 0;
  left: var(--sb-current-offset);
  width: auto;
  box-sizing: border-box;
}

#heroSlider > .absolute.bottom-8{
  left: calc(50% + (var(--sb-current-offset) / 2));
}

    @media (max-width: 767px) {
      .hero-slide.is-active {
        animation: heroKenBurns 6s linear forwards;
      }
    }

    @media (min-width: 768px) {
      .hero-slide.is-active {
        transform: scale(1);
      }
    }

    /* ── Meet the Team slider ──────────────────────────────── */
    .team-slider-track {
      display: flex;
      gap: 20px;
      overflow-x: auto;
      scroll-snap-type: x mandatory;
      scroll-behavior: smooth;
      -webkit-overflow-scrolling: touch;
      padding: 6px 6px 20px;
      /* hide native scrollbar, still scrollable/swipeable */
      scrollbar-width: none;
      -ms-overflow-style: none;
    }
    .team-slider-track::-webkit-scrollbar { display: none; }

    .team-slide-card {
      scroll-snap-align: start;
      flex: 0 0 auto;
      width: 220px;
      background: #ffffff;
      border: 1px solid rgba(196, 144, 92, 0.18);
      border-radius: 16px;
      padding: 28px 20px 24px;
      text-align: center;
      box-shadow: 0 10px 30px rgba(47, 18, 0, 0.06);
      transition: box-shadow 0.35s ease, transform 0.35s ease;
    }
    .team-slide-card:hover {
      box-shadow: 0 20px 45px rgba(47, 18, 0, 0.14);
      transform: translateY(-4px);
    }

    .team-slide-photo {
      width: 76px;
      height: 76px;
      border-radius: 50%;
      object-fit: cover;
      margin: 0 auto 16px;
      display: block;
      border: 2px solid rgba(196, 144, 92, 0.35);
      background: #eee;
    }

    .team-slide-initial {
      display: flex;
      align-items: center;
      justify-content: center;
      background: #2f1200;
      color: #c4905c;
      font-weight: 700;
      font-family: 'Montserrat', sans-serif;
      font-size: 22px;
    }

    .team-slide-name {
      font-family: 'Montserrat', sans-serif;
      font-weight: 700;
      font-size: 14px;
      letter-spacing: 0.3px;
      color: #2f1200;
      margin: 0 0 4px;
    }

    .team-slide-position {
      font-family: 'Montserrat', sans-serif;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: #c4905c;
      margin: 0;
    }

    .team-slider-nav-btn {
      width: 44px;
      height: 44px;
      border-radius: 9999px;
      background: #ffffff;
      border: 1px solid rgba(196, 144, 92, 0.3);
      color: #2f1200;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.25s ease;
      box-shadow: 0 6px 16px rgba(47, 18, 0, 0.08);
    }
    .team-slider-nav-btn:hover {
      background: #2f1200;
      color: #ffffff;
      border-color: #2f1200;
    }
    .team-slider-nav-btn:disabled {
      opacity: 0.35;
      cursor: default;
      pointer-events: none;
    }

    @media (max-width: 639px) {
      .team-slide-card { width: 168px; padding: 22px 14px 18px; }
      .team-slide-photo { width: 60px; height: 60px; margin-bottom: 12px; }
      .team-slide-name { font-size: 12.5px; }
      .team-slide-position { font-size: 10px; }
      .team-slider-nav-btn { width: 38px; height: 38px; }
    }

    /* ── Team e-calling card modal ─────────────────────────── */
    #teamCardModalBox {
      transform: scale(0.94);
      opacity: 0;
      transition: transform 0.3s cubic-bezier(0.16,1,0.3,1), opacity 0.3s ease;
    }
    #teamCardModal.is-open #teamCardModalBox {
      transform: scale(1);
      opacity: 1;
    }

    /* ── QR fullscreen lightbox — premium framed card ──────── */
    .qr-lightbox-card {
      transform: scale(0.92);
      opacity: 0;
      transition: transform 0.3s cubic-bezier(0.16,1,0.3,1), opacity 0.3s ease;
    }
    #qrLightbox.is-open .qr-lightbox-card {
      transform: scale(1);
      opacity: 1;
    }
    .qr-corner {
      position: absolute;
      width: 20px;
      height: 20px;
      border-color: #c4905c;
    }
    .qr-corner-tl { top: 10px; left: 10px; border-top: 2px solid; border-left: 2px solid; }
    .qr-corner-tr { top: 10px; right: 10px; border-top: 2px solid; border-right: 2px solid; }
    .qr-corner-bl { bottom: 10px; left: 10px; border-bottom: 2px solid; border-left: 2px solid; }
    .qr-corner-br { bottom: 10px; right: 10px; border-bottom: 2px solid; border-right: 2px solid; }
  </style>
</head>

<body class="index-page">

  <?php include $includes['header']; ?>

  <div class="main-content">

    <!-- ═══════════════════════════════
       HERO SECTION (full-cover, design-studio style)
  ═══════════════════════════════ -->
    <section class="relative h-screen w-full overflow-hidden" id="heroSlider">

      <!-- Slideshow images -->
      <?php
      $first = true;

      if ($hero_result && $hero_result->num_rows > 0):
        while ($hero = $hero_result->fetch_assoc()):
          $is_first = $first;
          $opacity_class = $first ? 'opacity-100' : 'opacity-0';
          $first = false;
          ?>
          <img src="<?= CLIENT_ASSET . '/' . htmlspecialchars($hero['filepath']) ?>"
            alt="<?= htmlspecialchars($hero['title'] ?? 'Realiving Design Center') ?>"
            class="hero-slide absolute inset-0 h-full w-full object-cover object-center opacity-0 transition-opacity duration-[1500ms]"
            <?= $is_first ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
          <?php
        endwhile;
      else:
        ?>
        <img src="<?= CLIENT_ASSET ?>/images/background-image.jpg" alt="Default Slide"
          class="hero-slide absolute inset-0 h-full w-full object-cover object-center opacity-100 transition-opacity duration-[1500ms]"
          fetchpriority="high">
      <?php endif; ?>

      <!-- Dark overlay para readable ang text -->
      <div class="pointer-events-none absolute inset-0 bg-black/35"></div>
      <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/65 via-black/25 to-black/15"></div>

      <!-- Centered Content -->
      <div class="relative z-10 flex h-full w-full flex-col items-center justify-center px-6 text-center text-white">

        <span
          class="mb-4 text-[10px] font-semibold uppercase tracking-[4px] text-white/80 sm:text-xs md:mb-6 md:tracking-[6px]">
          Design &bull; Fabricate &bull; Install
        </span>

        <h1 class="mb-5 max-w-3xl font-normal leading-[1.15] text-3xl sm:text-4xl md:mb-7 md:text-5xl lg:text-6xl"
          style="font-family: 'Cormorant Garamond', serif;">
          Transform your space with timeless design
        </h1>

        <p
          class="mb-8 max-w-md text-[13px] font-light leading-relaxed text-white/85 sm:text-sm md:mb-10 md:max-w-lg md:text-base">
          Your dream interiors, crafted with purpose and personality.
        </p>

        <div class="flex flex-col items-center gap-4 sm:flex-row">
          <a href="<?= BASE_URL ?>inquiry"
            class="openFormBtn inline-flex items-center gap-2 bg-white px-8 py-3.5 text-[11px] font-semibold uppercase tracking-[2px] text-[#2F1200] transition-all duration-300 hover:bg-[#2F1200] hover:text-white sm:px-9 sm:py-4">
            <i class="fa-solid fa-paper-plane text-[10px]"></i> Inquire Now
          </a>

          <a href="projects"
            class="inline-flex items-center gap-2 border border-white/70 px-8 py-3.5 text-[11px] font-semibold uppercase tracking-[2px] text-white transition-all duration-300 hover:border-white hover:bg-white/10 sm:px-9 sm:py-4">
            Explore Our Work
          </a>
        </div>

      </div>

      <!-- Scroll indicator -->
      <div class="pointer-events-none absolute bottom-8 left-1/2 z-10 -translate-x-1/2 animate-bounce text-white/70">
        <i class="ri-arrow-down-line text-xl"></i>
      </div>

    </section>
    <!-- ═══════════════════════════════
       END HERO SECTION
  ═══════════════════════════════ -->


    <!-- ═══════════════════════════════
       SERVICES SECTION
  ═══════════════════════════════ -->
    <section class="services py-20 bg-white" id="services">
      <div class="max-w-7xl mx-auto px-4">

        <!-- Section Header -->
        <div class="text-center mb-16">
          <span class="inline-block font-montserrat text-[10px] font-bold tracking-[3px] uppercase text-[#8a6236] mb-3">
            What We Offer
          </span>
          <h2 class="text-3xl sm:text-4xl font-bold text-[#2f1200] font-montserrat uppercase tracking-wide mb-4">
            Our Services
          </h2>
          <div class="mx-auto h-0.5 w-16 bg-[#c4905c] opacity-60 rounded-full"></div>
        </div>

        <!-- Cards Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 sm:gap-7 px-4 sm:px-8">

          <?php
          $services_query = "SELECT * FROM services_section WHERE is_active = 1 ORDER BY display_order ASC LIMIT 4";
          $services_result = $conn->query($services_query);
          $serviceCount = $services_result ? $services_result->num_rows : 0;

          if ($serviceCount > 0) {
            $i = 0;
            while ($service = $services_result->fetch_assoc()) {
              $i++;
              $mediaPath = $service['image_path'];
              $fileExtension = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION));
              $isVideo = in_array($fileExtension, ['mp4', 'webm', 'ogg', 'mov', 'avi']);
              ?>
              <!-- Service Card from Database -->
              <div
                class="service-card group relative bg-white rounded-2xl border border-gray-100 shadow-[0_4px_20px_rgba(47,18,0,0.06)] hover:shadow-[0_20px_40px_rgba(47,18,0,0.14)] hover:-translate-y-2 transition-all duration-500 overflow-hidden">

                <!-- Top: full media banner with diagonal cut -->
                <div class="relative h-44 sm:h-48 w-full overflow-hidden bg-[#2f1200]"
                  style="clip-path: polygon(0 0, 100% 0, 100% 82%, 0% 100%);">
                  <?php if ($isVideo): ?>
                    <video
                      class="w-full h-full object-cover scale-105 group-hover:scale-110 transition-transform duration-700 pointer-events-none"
                      autoplay muted loop playsinline
                      disablepictureinpicture
                      controlsList="nodownload noplaybackrate nofullscreen">
                      <source src="<?= CLIENT_ASSET ?>/<?= ltrim(htmlspecialchars($mediaPath), './') ?>"
                        type="video/<?= $fileExtension ?>">
                    </video>
                  <?php else: ?>
                    <img src="<?= CLIENT_ASSET ?>/<?= ltrim(htmlspecialchars($mediaPath), './') ?>"
                      alt="<?= htmlspecialchars($service['title']) ?> Service"
                      class="w-full h-full object-cover scale-105 group-hover:scale-110 transition-transform duration-700">
                  <?php endif; ?>
                  <div class="absolute inset-0 bg-gradient-to-t from-[#2f1200]/50 via-transparent to-transparent"></div>
                </div>

                <!-- Floating badge overlapping image + content -->
                <div class="relative -mt-7 flex justify-center">
                  <div
                    class="w-14 h-14 rounded-xl bg-white shadow-lg border border-[#c4905c]/20 flex items-center justify-center group-hover:bg-[#2f1200] transition-colors duration-300">
                    <i
                      class="ri-shapes-line text-xl text-[#c4905c] group-hover:text-white transition-colors duration-300"></i>
                  </div>
                </div>

                <!-- Content -->
                <div class="flex flex-col items-center text-center px-6 pt-4 pb-8">
                  <h3 class="text-base sm:text-lg font-bold mb-2.5 text-[#2f1200] font-montserrat uppercase tracking-[1px]">
                    <?= htmlspecialchars($service['title']) ?>
                  </h3>
                  <p class="text-gray-500 text-[13px] sm:text-sm leading-relaxed">
                    <?= htmlspecialchars($service['description']) ?>
                  </p>
                </div>

              </div>
              <?php
            }
          } else {
            $defaultServices = [
              ['title' => 'Design', 'image' => CLIENT_ASSET . '/images/services/Design.png', 'description' => 'We create smart, space-saving, and stylish designs tailored to your space and lifestyle needs.'],
              ['title' => 'Fabrication', 'image' => CLIENT_ASSET . '/images/services/Fabricate.png', 'description' => 'Using quality materials, we build each piece with precision to ensure durability and a modern finish.'],
              ['title' => 'Delivery', 'image' => CLIENT_ASSET . '/images/services/Delivery.png', 'description' => 'We transport your furniture safely and on time—straight to your doorstep.'],
              ['title' => 'Installation', 'image' => CLIENT_ASSET . '/images/services/Installation.png', 'description' => 'Our team handles the setup efficiently, making sure everything is perfectly fitted and ready to use.']
            ];

            foreach ($defaultServices as $i => $service) {
              $i++;
              ?>
              <div
                class="service-card group relative bg-white rounded-2xl border border-gray-100 shadow-[0_4px_20px_rgba(47,18,0,0.06)] hover:shadow-[0_20px_40px_rgba(47,18,0,0.14)] hover:-translate-y-2 transition-all duration-500 overflow-hidden">

                <div class="relative h-44 sm:h-48 w-full overflow-hidden bg-[#2f1200]"
                  style="clip-path: polygon(0 0, 100% 0, 100% 82%, 0% 100%);">
                  <img src="<?= $service['image'] ?>" alt="<?= $service['title'] ?> Service"
                    class="w-full h-full object-cover scale-105 group-hover:scale-110 transition-transform duration-700">
                  <div class="absolute inset-0 bg-gradient-to-t from-[#2f1200]/50 via-transparent to-transparent"></div>
                </div>

                <div class="relative -mt-7 flex justify-center">
                  <div
                    class="w-14 h-14 rounded-xl bg-white shadow-lg border border-[#c4905c]/20 flex items-center justify-center group-hover:bg-[#2f1200] transition-colors duration-300">
                    <i
                      class="ri-shapes-line text-xl text-[#c4905c] group-hover:text-white transition-colors duration-300"></i>
                  </div>
                </div>

                <div class="flex flex-col items-center text-center px-6 pt-4 pb-8">
                  <h3 class="text-base sm:text-lg font-bold mb-2.5 text-[#2f1200] font-montserrat uppercase tracking-[1px]">
                    <?= $service['title'] ?>
                  </h3>
                  <p class="text-gray-500 text-[13px] sm:text-sm leading-relaxed">
                    <?= $service['description'] ?>
                  </p>
                </div>

              </div>
              <?php
            }
          }
          ?>

        </div>
      </div>
    </section>
    <!-- ═══════════════════════════════
       END SERVICES SECTION
  ═══════════════════════════════ -->

  <!-- ═══════════════════════════════
       MEET THE TEAM SECTION (slider)
  ═══════════════════════════════ -->
  <?php if (!empty($team_members)): ?>
  <section class="w-full py-16 sm:py-20 bg-[#faf8f6]" id="team">
    <div class="max-w-7xl mx-auto px-4">

      <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-6 mb-10">
        <div>
          <span class="inline-block font-montserrat text-[10px] font-bold tracking-[3px] uppercase text-[#8a6236] mb-3">
            Meet The Team
          </span>
          <h2 class="text-3xl sm:text-4xl font-bold text-[#2f1200] font-montserrat uppercase tracking-wide mb-4">
            Who You'll Work With
          </h2>
          <div class="h-0.5 w-16 bg-[#c4905c] opacity-60 rounded-full"></div>
        </div>

        <a href="<?= BASE_URL ?>about#meet-the-team"
          class="group inline-flex items-center gap-2 font-montserrat text-[11px] font-bold tracking-[2px] uppercase text-[#2f1200] pb-1 border-b-2 border-[#2f1200] w-fit hover:text-[#c4905c] hover:border-[#c4905c] transition-colors duration-300">
          Meet the Full Team
          <i class="ri-arrow-right-line transition-transform duration-300 group-hover:translate-x-1"></i>
        </a>
      </div>

      <div class="relative">

        <!-- Left arrow -->
        <button type="button" id="teamSliderPrev" aria-label="Previous"
          class="team-slider-nav-btn absolute -left-3 sm:-left-5 top-1/2 -translate-y-1/2 z-10 hidden sm:flex">
          <i class="ri-arrow-left-s-line text-xl"></i>
        </button>

        <div class="team-slider-track" id="teamSliderTrack">
          <?php foreach ($team_members as $i => $member): ?>
            <div class="team-slide-card cursor-pointer" onclick="openTeamModal(<?= $i ?>)">
              <?php if (!empty($member['avatar_url'])): ?>
                <img src="<?= htmlspecialchars($member['avatar_url']) ?>" alt="<?= htmlspecialchars($member['full_name']) ?>"
                  class="team-slide-photo" loading="lazy">
              <?php else: ?>
                <div class="team-slide-photo team-slide-initial">
                  <?= htmlspecialchars(strtoupper(substr($member['full_name'], 0, 1))) ?>
                </div>
              <?php endif; ?>
              <p class="team-slide-name"><?= htmlspecialchars($member['full_name']) ?></p>
              <?php if (!empty($member['position'])): ?>
                <p class="team-slide-position"><?= htmlspecialchars($member['position']) ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Right arrow -->
        <button type="button" id="teamSliderNext" aria-label="Next"
          class="team-slider-nav-btn absolute -right-3 sm:-right-5 top-1/2 -translate-y-1/2 z-10 hidden sm:flex">
          <i class="ri-arrow-right-s-line text-xl"></i>
        </button>

      </div>

      <p class="mt-2 text-center text-[11px] text-gray-400 font-montserrat sm:hidden">
        <i class="ri-swipe-line mr-1"></i> Swipe to see more
      </p>

    </div>
  </section>
  <?php endif; ?>
  <!-- ═══════════════════════════════
       END MEET THE TEAM SECTION
  ═══════════════════════════════ -->

  <!-- ═══════════════════════════════
       TEAM E-CALLING CARD MODAL
  ═══════════════════════════════ -->
  <div id="teamCardModal" class="hidden fixed inset-0 z-[99999] bg-black/60 backdrop-blur-sm items-center justify-center p-4">
          <div id="teamCardModalBox" class="relative w-full max-w-3xl max-h-[92vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">

        <button type="button" onclick="closeTeamModal()" title="Close"
          class="absolute top-3 right-3 z-20 w-9 h-9 rounded-full bg-white/90 text-[#2f1200] flex items-center justify-center hover:bg-[#2f1200] hover:text-white transition-colors shadow-sm">
          <i class="ri-close-line text-lg"></i>
        </button>

        <div class="flex flex-col sm:flex-row">

          <div class="relative flex shrink-0 flex-col items-center justify-center gap-4 overflow-hidden px-8 py-10 text-center sm:w-[240px] sm:py-8"
            style="background-image: radial-gradient(rgba(255,255,255,0.07) 1px, transparent 1px), linear-gradient(135deg, #2f1200, #5a2a08); background-size: 16px 16px, 100% 100%;">

            <!-- Corner brackets — matches the blueprint motif elsewhere on the site -->
            <span class="pointer-events-none absolute top-3 left-3 h-6 w-6 border-t-2 border-l-2 border-[#c4905c]/50"></span>
            <span class="pointer-events-none absolute top-3 right-3 h-6 w-6 border-t-2 border-r-2 border-[#c4905c]/50"></span>
            <span class="pointer-events-none absolute bottom-3 left-3 h-6 w-6 border-b-2 border-l-2 border-[#c4905c]/50"></span>
            <span class="pointer-events-none absolute bottom-3 right-3 h-6 w-6 border-b-2 border-r-2 border-[#c4905c]/50"></span>

            <!-- Giant faint monogram behind the content -->
            <span id="tcmMonogram" class="pointer-events-none absolute inset-0 flex items-center justify-center select-none text-[130px] font-black text-white/[0.045]" style="font-family:'Cormorant Garamond', serif;"></span>

            <div class="relative">
              <img id="tcmAvatar" src="" alt="" class="hidden h-20 w-20 rounded-full object-cover border-4 border-[#c4905c]/70 shadow-lg sm:h-24 sm:w-24">
              <div id="tcmInitial" class="hidden h-20 w-20 items-center justify-center rounded-full border-4 border-[#c4905c]/70 bg-white/10 font-montserrat text-2xl font-bold text-white shadow-lg sm:h-24 sm:w-24"></div>
            </div>

            <div class="relative">
              <h3 id="tcmName" class="font-montserrat text-base font-bold uppercase tracking-[1px] text-white sm:text-lg"></h3>
              <p id="tcmPosition" class="mt-1 font-montserrat text-[10px] uppercase tracking-[2px] text-[#e8c9a0] sm:text-[11px]"></p>
            </div>

            <div class="relative flex items-center gap-2">
              <span class="h-px w-6 bg-[#c4905c]/50"></span>
              <i class="ri-shapes-line text-[10px] text-[#c4905c]"></i>
              <span class="h-px w-6 bg-[#c4905c]/50"></span>
            </div>

            <span class="relative font-montserrat text-[9px] font-semibold uppercase tracking-[2px] text-white/50">Realiving Design Center</span>
          </div>

          <div class="flex flex-1 flex-col gap-6 p-6 sm:flex-row sm:items-start sm:justify-between sm:gap-8 sm:p-8">
            <div id="tcmContacts" class="flex flex-1 flex-col gap-2 font-montserrat"></div>

            <div id="tcmQrSection" class="hidden items-center gap-3 sm:w-[140px] sm:shrink-0 sm:flex-col">
              <div id="tcmQrToggle" class="flex flex-wrap justify-center gap-2"></div>
              <button type="button" onclick="openQrLightbox()" class="group relative h-28 w-28 sm:h-32 sm:w-32">
                <img id="tcmQrImage" src="" alt="QR Code" class="h-full w-full rounded-lg border border-[#c4905c]/20 bg-white p-2 shadow-sm">
                <span class="pointer-events-none absolute inset-0 flex items-center justify-center rounded-lg bg-black/0 opacity-0 transition-all duration-200 group-hover:bg-black/40 group-hover:opacity-100">
                  <i class="ri-zoom-in-line text-lg text-white"></i>
                </span>
              </button>
            </div>
          </div>

        </div>

      </div>
  </div>
  <!-- QR Fullscreen Lightbox -->
  <div id="qrLightbox" class="hidden fixed inset-0 z-[999999] items-center justify-center bg-black/85 backdrop-blur-sm p-6" onclick="closeQrLightbox()">
    <button type="button" onclick="closeQrLightbox()" title="Close"
      class="absolute top-5 right-5 z-10 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white hover:text-black">
      <i class="ri-close-line text-xl"></i>
    </button>

    <div class="qr-lightbox-card relative flex flex-col items-center gap-5 rounded-2xl bg-white px-10 py-10 shadow-2xl sm:px-14 sm:py-12" onclick="event.stopPropagation()">
      <span class="qr-corner qr-corner-tl"></span>
      <span class="qr-corner qr-corner-tr"></span>
      <span class="qr-corner qr-corner-bl"></span>
      <span class="qr-corner qr-corner-br"></span>

      <div class="flex items-center gap-2">
        <i id="qrLightboxIcon" class="text-base text-[#c4905c]"></i>
        <span id="qrLightboxLabel" class="font-montserrat text-[11px] font-bold uppercase tracking-[2.5px] text-[#2f1200]"></span>
      </div>

      <img id="qrLightboxImage" src="" alt="QR Code" class="h-56 w-56 rounded-lg border border-[#c4905c]/15 bg-white p-3 sm:h-64 sm:w-64">

      <p class="font-montserrat text-[11px] text-gray-400">Scan with your camera app to connect</p>
    </div>
  </div>
  <!-- ═══════════════════════════════
       END TEAM E-CALLING CARD MODAL
  ═══════════════════════════════ -->

  <!-- ═══════════════════════════════
       VIRTUAL WALKTHROUGH SECTION (modernized)
  ═══════════════════════════════ -->
  <section class="w-full pt-4 pb-20 bg-[#faf8f6]" id="walkthrough">
    <div class="max-w-6xl mx-auto px-4">

      <!-- Section Header — matched to Services header style -->
      <div class="text-center mb-14">
        <span class="inline-block font-montserrat text-[10px] font-bold tracking-[3px] uppercase text-[#8a6236] mb-3">
          Step Inside
        </span>
        <h2 class="text-3xl sm:text-4xl font-bold text-[#2f1200] font-montserrat uppercase tracking-wide mb-4">
          Virtual Walkthrough
        </h2>
        <p class="text-gray-500 text-sm font-montserrat max-w-md mx-auto mb-4">
          Experience our design center in an immersive 360° tour
        </p>
        <div class="mx-auto h-0.5 w-16 bg-[#c4905c] opacity-60 rounded-full"></div>
      </div>

      <!-- Walkthrough Frame Card -->
      <div class="relative rounded-2xl overflow-hidden shadow-[0_20px_60px_rgba(47,18,0,0.15)] border border-[#c4905c]/15">

        <!-- iFrame wrapper -->
        <div class="relative w-full" style="height:500px;" id="mainWalkthroughWrapper">

          <!-- Loading Placeholder -->
          <div id="mainWalkthroughPlaceholder"
            class="absolute inset-0 flex flex-col items-center justify-center z-[5]"
            style="background:linear-gradient(135deg, #f5f0eb 0%, #e8ddd4 100%);">
            <i class="ri-360-line text-5xl text-[#2f1200] opacity-40"></i>
            <p class="font-montserrat text-[#2f1200] opacity-50 mt-3 text-[13px]">
              Loading Virtual Tour...
            </p>
          </div>

          <iframe data-src="https://kd20-realiving.yfcad.com/pano?id=56834458&uid=24597"
  title="Realiving Design Center 360° Virtual Tour"
  allow="fullscreen"
  class="absolute inset-0 w-full h-full border-0"></iframe>

          <!-- Logo overlay — frosted glass, matches sidebar's frosted-glass language -->
          <div class="absolute top-0 left-0 flex items-center justify-center p-2.5 pointer-events-none"
            style="width:110px; height:80px; border-radius:16px 0 0 0;
                   background:linear-gradient(135deg, rgba(255,255,255,0.5) 0%, rgba(255,255,255,0.2) 100%);
                   backdrop-filter:blur(20px) saturate(160%);
                   -webkit-backdrop-filter:blur(20px) saturate(160%);
                   border-bottom:1px solid rgba(255,255,255,0.25);
                   border-right:1px solid rgba(255,255,255,0.25);
                   box-shadow:0 8px 24px rgba(0,0,0,0.1); z-index:15;">
            <img src="<?= CLIENT_ASSET ?>/images/logo/logowhite.png" alt="Realiving Logo"
              class="max-w-full max-h-full object-contain opacity-90"
              style="filter:drop-shadow(0 2px 4px rgba(0,0,0,0.2));">
          </div>

          <!-- Right-side click-blocker (hides native pano UI buttons, same trick as index.php) -->
          <div class="absolute top-0 right-0 h-full pointer-events-auto" style="width:75px; z-index:14;"></div>
        </div>

      </div>

      <!-- Caption -->
      <p class="text-center text-xs text-gray-600 mt-5 font-montserrat">
        <i class="ri-cursor-line mr-1"></i>
        Click and drag to explore &middot; Scroll to zoom
      </p>

    </div>
  </section>
  <!-- ═══════════════════════════════
       LATEST UPDATES SECTION (masonry/staggered grid)
  ═══════════════════════════════ -->
  <?php
  $conn->query("UPDATE ads_content SET is_active = 1, posted_date = scheduled_date
                WHERE is_active = 0 AND scheduled_date IS NOT NULL
                AND scheduled_date <= CURDATE() AND posted_date IS NULL");

  $_active_count = $conn->query("SELECT COUNT(*) AS cnt FROM ads_content WHERE is_active = 1")->fetch_assoc()['cnt'];
  if ($_active_count > 3) {
    $_expire_res = $conn->query("SELECT id, filepath FROM ads_content WHERE is_active = 1
                                  AND posted_date IS NOT NULL AND DATEDIFF(CURDATE(), posted_date) > 7
                                  ORDER BY posted_date ASC");
    if ($_expire_res && $_expire_res->num_rows > 0) {
      $_deletable = [];
      while ($_row = $_expire_res->fetch_assoc()) $_deletable[] = $_row;
      $_to_delete = array_slice($_deletable, 0, $_active_count - 3);
      foreach ($_to_delete as $_d) {
        $conn->query("DELETE FROM ads_content WHERE id = " . intval($_d['id']));
        $_img_path = __DIR__ . "/images/ads_content/" . basename($_d['filepath']);
        if (file_exists($_img_path)) unlink($_img_path);
      }
    }
  }

  $ads_result = $conn->query("SELECT * FROM ads_content WHERE is_active = 1 ORDER BY posted_date DESC, id DESC");
  $ads_items = [];
  if ($ads_result) while ($row = $ads_result->fetch_assoc()) $ads_items[] = $row;

  // Iba-ibang height class para sa masonry feel — cinycle base sa index
  $heightClasses = ['md:row-span-2', '', '', 'md:row-span-2', '', ''];
  ?>

  <?php if (!empty($ads_items)): ?>
  <section class="bg-[#faf8f6] py-12 sm:py-16" id="updates">
    <div class="max-w-7xl mx-auto px-5">

      <div class="text-center mb-10">
        <span class="inline-block font-montserrat text-[10px] font-bold tracking-[3px] uppercase text-[#8a6236] mb-3">
          Latest Updates
        </span>
        <h2 class="text-3xl sm:text-4xl font-bold text-[#2f1200] font-montserrat uppercase tracking-wide mb-4">
          From Our Page
        </h2>
        <div class="mx-auto h-0.5 w-16 bg-[#c4905c] opacity-60 rounded-full"></div>
      </div>

      <!-- Masonry grid via CSS columns — mas simple at reliable kaysa grid-row-span
           trickery, at natural na gumagawa ng staggered heights -->
      <div class="columns-1 sm:columns-2 lg:columns-3 gap-5 [&>*]:mb-5" id="updGrid" style="column-fill: balance-all;">
        <?php foreach ($ads_items as $i => $ad):
          $img = CLIENT_ASSET . "/images/ads_content/" . basename($ad['filepath']);
          // I-alternate ang image aspect ratio para talagang staggered ang tingin
          $ratios = ['aspect-[3/4]', 'aspect-square', 'aspect-[4/5]', 'aspect-[3/4]', 'aspect-[4/3]', 'aspect-square'];
          $ratio = $ratios[$i % count($ratios)];
          ?>
          <div class="break-inside-avoid group cursor-pointer bg-white shadow-[0_4px_20px_rgba(47,18,0,0.07)] hover:shadow-[0_16px_40px_rgba(47,18,0,0.16)] transition-shadow duration-500 overflow-hidden"
            onclick="updOpenModal(<?= $i ?>)">

            <div class="relative <?= $ratio ?> overflow-hidden bg-[#2f1200]">
              <img src="<?= htmlspecialchars($img) ?>" alt="Realiving Update"
                class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
                loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
              <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>

              <button onclick="event.stopPropagation(); updOpenFs(<?= $i ?>)" title="View fullscreen"
                class="absolute top-3 right-3 w-8 h-8 rounded-full bg-white/90 text-[#2f1200] flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-300 hover:bg-[#c4905c] hover:text-white">
                <i class="ri-fullscreen-line text-sm"></i>
              </button>

              <div class="absolute bottom-0 left-0 right-0 p-4 translate-y-2 opacity-0 group-hover:translate-y-0 group-hover:opacity-100 transition-all duration-400">
                <span class="inline-flex items-center gap-1 font-montserrat text-[9px] font-bold tracking-wider uppercase text-white">
                  <i class="ri-arrow-right-up-line"></i> Read Story
                </span>
              </div>
            </div>

            <div class="p-4">
              <span class="block font-montserrat text-[9px] font-bold tracking-[2px] uppercase text-[#8a6236] mb-1.5">
                ✦ Update
              </span>
              <p class="font-montserrat text-[13px] text-[#2f1200] leading-relaxed line-clamp-2">
                <?= htmlspecialchars($ad['caption']) ?>
              </p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    </div>
  </section>

  <!-- Fullscreen Lightbox -->
  <div id="updFsViewer" class="hidden fixed inset-0 z-[999999] bg-black flex-col items-center justify-center">
    <div class="absolute top-0 left-0 right-0 flex items-center justify-between px-5 py-4 bg-gradient-to-b from-black/70 to-transparent z-10">
      <span class="font-montserrat text-[10px] font-bold tracking-[3px] uppercase text-white/60">✦ Realiving Update</span>
      <div class="flex items-center gap-2">
        <a id="updFsDownload" href="#" download title="Download image"
          class="w-9 h-9 bg-white/[0.08] border border-white/10 text-white flex items-center justify-center hover:bg-white hover:text-black transition-all backdrop-blur-sm">
          <i class="ri-download-2-line"></i>
        </a>
        <button onclick="updCloseFs()" title="Close (Esc)"
          class="w-9 h-9 bg-white/[0.08] border border-white/10 text-white flex items-center justify-center hover:bg-red-600/60 transition-all backdrop-blur-sm">
          <i class="ri-close-line"></i>
        </button>
      </div>
    </div>

    <div class="relative w-full h-full flex items-center justify-center overflow-hidden" id="updFsImgWrap">
      <img id="updFsImg" src="" alt="Realiving Update" onclick="updToggleZoom(this)"
        class="max-w-full max-h-full object-contain cursor-zoom-in transition-transform duration-300 select-none">
    </div>

    <button onclick="updFsPrev()" class="absolute top-1/2 -translate-y-1/2 left-4 z-10 w-12 h-12 rounded-full bg-white/[0.07] border border-white/15 text-white text-2xl flex items-center justify-center backdrop-blur-sm hover:bg-white hover:text-black transition-all">
      <i class="ri-arrow-left-s-line"></i>
    </button>
    <button onclick="updFsNext()" class="absolute top-1/2 -translate-y-1/2 right-4 z-10 w-12 h-12 rounded-full bg-white/[0.07] border border-white/15 text-white text-2xl flex items-center justify-center backdrop-blur-sm hover:bg-white hover:text-black transition-all">
      <i class="ri-arrow-right-s-line"></i>
    </button>

    <div class="absolute bottom-0 left-0 right-0 flex items-center justify-center gap-2.5 px-5 py-4 bg-gradient-to-t from-black/65 to-transparent">
      <span class="font-montserrat text-[11px] font-bold tracking-wider text-white/45" id="updFsCounter"></span>
    </div>
  </div>

  <!-- Read More Modal -->
  <div id="updModal" class="hidden fixed inset-0 z-[99999] bg-black/75 backdrop-blur-sm items-center justify-center p-4">
    <div class="relative w-full max-w-lg max-h-[90vh] bg-white rounded-2xl overflow-hidden flex flex-col shadow-2xl">
      <div class="relative shrink-0 w-full h-52 sm:h-64 overflow-hidden cursor-zoom-in group" onclick="updOpenFs(updModalIndex)">
        <img id="updModalImg" src="" alt="Realiving Update"
          class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-[1.03]">
        <button onclick="event.stopPropagation(); updCloseModal()" title="Close"
          class="absolute top-3.5 left-3.5 z-10 w-9 h-9 rounded-full bg-white text-black flex items-center justify-center hover:bg-[#2f1200] hover:text-white transition-all">
          <i class="ri-close-line"></i>
        </button>
      </div>
      <div class="flex-1 overflow-y-auto px-7 py-6">
        <span class="inline-block font-montserrat text-[9px] font-black tracking-[3px] uppercase text-[#c4905c] mb-4">
          ✦ Realiving Update
        </span>
        <p id="updModalCaption" class="font-montserrat text-[14px] text-[#2f1200] leading-[1.9] whitespace-pre-line"></p>
        <p id="updModalHashtags" class="hidden mt-5 pt-4 border-t border-gray-200 font-montserrat text-[11px] text-[#c4905c] font-semibold leading-loose"></p>
      </div>
    </div>
  </div>

  <script>
    (function(){
      const items = <?= json_encode(array_map(function($ad){
        return [
          'image' => CLIENT_ASSET . '/images/ads_content/' . basename($ad['filepath']),
          'caption' => $ad['caption'],
          'hashtags' => $ad['hashtags'] ?? '',
        ];
      }, $ads_items)) ?>;
      const total = items.length;

      // Fullscreen lightbox
      const fsViewer = document.getElementById('updFsViewer');
      const fsImg = document.getElementById('updFsImg');
      const fsCounter = document.getElementById('updFsCounter');
      const fsDl = document.getElementById('updFsDownload');
      let fsIndex = 0;

      function fsGoTo(i){
        fsIndex = (i + total) % total;
        const it = items[fsIndex];
        fsImg.classList.remove('scale-[1.8]', 'cursor-zoom-out');
        fsImg.src = it.image;
        fsDl.href = it.image;
        fsCounter.textContent = (fsIndex + 1) + ' / ' + total;
      }

      window.updOpenFs = function(i){
        fsGoTo(i);
        fsViewer.classList.remove('hidden');
        fsViewer.classList.add('flex');
        document.body.style.overflow = 'hidden';
      };
      window.updCloseFs = function(){
        fsViewer.classList.add('hidden');
        fsViewer.classList.remove('flex');
        document.body.style.overflow = '';
        fsImg.classList.remove('scale-[1.8]', 'cursor-zoom-out');
      };
      window.updFsPrev = function(){ fsGoTo(fsIndex - 1); };
      window.updFsNext = function(){ fsGoTo(fsIndex + 1); };
      window.updToggleZoom = function(img){
        const zoomed = img.classList.toggle('scale-[1.8]');
        img.classList.toggle('cursor-zoom-out', zoomed);
      };

      fsViewer.addEventListener('click', function(e){
        if (e.target === fsViewer || e.target.id === 'updFsImgWrap') updCloseFs();
      });
      document.addEventListener('keydown', e => {
        if (fsViewer.classList.contains('hidden')) return;
        if (e.key === 'Escape') updCloseFs();
        if (e.key === 'ArrowLeft') updFsPrev();
        if (e.key === 'ArrowRight') updFsNext();
      });

      // Read More modal
      window.updModalIndex = 0;
      const modal = document.getElementById('updModal');
      window.updOpenModal = function(i){
        window.updModalIndex = i;
        const it = items[i];
        document.getElementById('updModalImg').src = it.image;
        document.getElementById('updModalCaption').innerHTML = it.caption.replace(/\n/g, '<br>');
        const ht = document.getElementById('updModalHashtags');
        ht.textContent = it.hashtags;
        ht.classList.toggle('hidden', !it.hashtags);
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
      };
      window.updCloseModal = function(){
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = '';
      };
      modal.addEventListener('click', function(e){ if (e.target === this) updCloseModal(); });
      document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) updCloseModal();
      });
    })();
  </script>
  <?php endif; ?>
  <!-- ═══════════════════════════════
       END LATEST UPDATES SECTION
  ═══════════════════════════════ -->

  <!-- ═══════════════════════════════
       EXCLUSIVE CABINET PACKAGES (CTA Banner)
  ═══════════════════════════════ -->
  <?php
  $inquire_query = "SELECT * FROM inquire_images WHERE is_active = 1 LIMIT 1";
  $inquire_result = $conn->query($inquire_query);

  if ($inquire_result && $inquire_result->num_rows > 0) {
    $inquire = $inquire_result->fetch_assoc();
    $inquire_image = CLIENT_ASSET . '/' . ltrim(htmlspecialchars($inquire['filepath']), './');
  } else {
    $inquire_image = CLIENT_ASSET . '/images/inquirebanner.png';
  }
  ?>

  <section class="bg-[#faf8f6] py-12 sm:py-16" id="packages">
    <div class="max-w-7xl mx-auto px-4 sm:px-6">

      <div class="relative rounded-[2rem] overflow-hidden shadow-[0_30px_70px_rgba(47,18,0,0.25)] h-[420px] sm:h-[480px] md:h-[560px]">

        <!-- Background image -->
        <img src="<?= $inquire_image ?>" alt="Exclusive Cabinet Packages"
          class="absolute inset-0 w-full h-full object-cover scale-105">

        <!-- Overlay gradients -->
        <div class="absolute inset-0 bg-gradient-to-t from-[#0e0704]/90 via-[#0e0704]/40 to-[#0e0704]/10"></div>
        <div class="absolute inset-0 bg-gradient-to-r from-[#0e0704]/70 via-transparent to-transparent"></div>

        <!-- Floating frosted badge -->
        <div class="absolute top-6 right-6 sm:top-8 sm:right-8 flex items-center gap-2 px-4 py-2.5 rounded-full"
          style="background:linear-gradient(135deg, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0.05) 100%);
                 backdrop-filter:blur(16px) saturate(160%);
                 -webkit-backdrop-filter:blur(16px) saturate(160%);
                 border:1px solid rgba(255,255,255,0.2);">
          <i class="ri-price-tag-3-line text-[#c4905c] text-sm"></i>
          <span class="font-montserrat text-[10px] font-bold tracking-[2px] uppercase text-white">
            Best Value Bundles
          </span>
        </div>

        <!-- Content -->
        <div class="relative z-10 h-full flex flex-col justify-end px-6 sm:px-10 md:px-14 pb-10 sm:pb-12 md:pb-16">

          <span class="inline-block font-montserrat text-[10px] font-bold tracking-[3px] uppercase text-[#c4905c] mb-4">
            ✦ Exclusive Offer
          </span>

          <h2 class="font-montserrat font-bold uppercase tracking-wide text-white leading-[1.15]
                     text-2xl sm:text-3xl md:text-4xl lg:text-5xl max-w-2xl mb-4">
            Exclusive Cabinet Packages
          </h2>

          <p class="font-montserrat text-white/75 text-sm sm:text-base max-w-md mb-8">
            Stylish. Affordable. Ready for your space.
          </p>

          <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4">
            <a href="<?= BASE_URL ?>inquiry"
              class="openFormBtn group inline-flex items-center gap-2.5 bg-white text-[#2f1200] font-montserrat font-bold text-[11px] tracking-[2px] uppercase px-8 py-4 rounded-full transition-all duration-300 hover:bg-[#2f1200] hover:text-white shadow-lg">
              Inquire Now
              <i class="ri-arrow-right-line text-sm transition-transform duration-300 group-hover:translate-x-1"></i>
            </a>

            <span class="font-montserrat text-[11px] text-white/50 tracking-wide">
              <i class="ri-shield-check-line text-[#c4905c] mr-1"></i>
              No hidden charges
            </span>
          </div>

        </div>

      </div>

    </div>
  </section>
  <!-- ═══════════════════════════════
       END EXCLUSIVE CABINET PACKAGES
  ═══════════════════════════════ -->

  <!-- ═══════════════════════════════
       LATEST NEWS & UPDATES (Editorial layout)
  ═══════════════════════════════ -->
  <?php
  $news_sql = "SELECT id, image, title, category, date_uploaded, author FROM news WHERE status = 'published' ORDER BY date_uploaded DESC LIMIT 5";
  $news_result = $conn->query($news_sql);
  $news_rows = [];
  if ($news_result) while ($r = $news_result->fetch_assoc()) $news_rows[] = $r;
  ?>

  <?php if (!empty($news_rows)):
    $featured = $news_rows[0];
    $rest = array_slice($news_rows, 1, 4);
    ?>
  <section class="bg-white py-12 sm:py-16" id="news">
    <div class="max-w-7xl mx-auto px-4 sm:px-6">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between mb-12 gap-4">
        <div>
          <span class="inline-block font-montserrat text-[10px] font-bold tracking-[3px] uppercase text-[#8a6236] mb-3">
            Stay In The Loop
          </span>
          <h2 class="text-3xl sm:text-4xl font-bold text-[#2f1200] font-montserrat uppercase tracking-wide">
            Latest News &amp; Updates
          </h2>
        </div>
        <a href="<?= BASE_URL ?>news"
          class="group inline-flex items-center gap-2 font-montserrat text-[11px] font-bold tracking-[2px] uppercase text-[#2f1200] pb-1 border-b-2 border-[#2f1200] w-fit hover:text-[#c4905c] hover:border-[#c4905c] transition-colors duration-300">
          View All News
          <i class="ri-arrow-right-line transition-transform duration-300 group-hover:translate-x-1"></i>
        </a>
      </div>

      <!-- Editorial Grid: Featured + List -->
      <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 lg:gap-8">

        <!-- Featured Article (big, left) -->
        <a href="<?= BASE_URL ?>news-view?id=<?= $featured['id'] ?>"
          class="group lg:col-span-3 relative rounded-2xl overflow-hidden block h-[320px] sm:h-[420px] lg:h-[560px] shadow-[0_20px_50px_rgba(47,18,0,0.12)]">

          <img src="<?= CLIENT_ASSET ?>/<?= htmlspecialchars($featured['image']) ?>" alt="<?= htmlspecialchars($featured['title']) ?>"
            class="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-105">

          <div class="absolute inset-0 bg-gradient-to-t from-[#0e0704]/90 via-[#0e0704]/20 to-transparent"></div>

          <div class="absolute top-5 left-5 flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 bg-[#c1121f] text-white font-montserrat text-[9px] font-bold tracking-[1.5px] uppercase px-3 py-1.5 rounded-full">
              <i class="ri-flashlight-fill"></i> Latest
            </span>
            <span class="font-montserrat text-[9px] font-bold tracking-[1.5px] uppercase text-white px-3 py-1.5 rounded-full"
              style="background:rgba(255,255,255,0.15); backdrop-filter:blur(10px); border:1px solid rgba(255,255,255,0.2);">
              <?= htmlspecialchars($featured['category']) ?>
            </span>
          </div>

          <div class="absolute bottom-0 left-0 right-0 p-6 sm:p-8">
            <h3 class="font-montserrat font-bold text-white text-xl sm:text-2xl lg:text-3xl leading-snug mb-4 max-w-xl">
              <?= htmlspecialchars($featured['title']) ?>
            </h3>
            <div class="flex items-center gap-4 font-montserrat text-[11px] text-white/70">
              <span class="flex items-center gap-1.5">
                <i class="ri-calendar-line"></i> <?= date('M d, Y', strtotime($featured['date_uploaded'])) ?>
              </span>
              <span class="w-1 h-1 rounded-full bg-white/40"></span>
              <span class="flex items-center gap-1.5">
                <i class="ri-user-line"></i> <?= htmlspecialchars($featured['author']) ?>
              </span>
            </div>
          </div>
        </a>

        <!-- Side List (smaller articles) -->
        <div class="lg:col-span-2 flex flex-col gap-4">
          <?php foreach ($rest as $row): ?>
            <a href="<?= BASE_URL ?>news-view?id=<?= $row['id'] ?>"
              class="group flex items-center gap-4 p-3 rounded-xl border border-gray-100 hover:border-[#c4905c]/30 hover:bg-[#faf8f6] transition-all duration-300">

              <div class="relative w-24 h-24 sm:w-28 sm:h-28 shrink-0 rounded-lg overflow-hidden">
                <img src="<?= CLIENT_ASSET ?>/<?= htmlspecialchars($row['image']) ?>" alt="<?= htmlspecialchars($row['title']) ?>"
                  class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110">
              </div>

              <div class="flex-1 min-w-0">
                <span class="inline-block font-montserrat text-[9px] font-bold tracking-[1.5px] uppercase text-[#8a6236] mb-1.5">
                  <?= htmlspecialchars($row['category']) ?>
                </span>
                <h4 class="font-montserrat font-semibold text-[#2f1200] text-[13px] sm:text-sm leading-snug mb-1.5 line-clamp-2 group-hover:text-[#c4905c] transition-colors duration-300">
                  <?= htmlspecialchars($row['title']) ?>
                </h4>
                <span class="font-montserrat text-[10px] text-gray-600">
                  <?= date('M d, Y', strtotime($row['date_uploaded'])) ?>
                </span>
              </div>

              <i class="ri-arrow-right-s-line text-gray-300 text-xl shrink-0 transition-transform duration-300 group-hover:translate-x-1 group-hover:text-[#c4905c]"></i>
            </a>
          <?php endforeach; ?>
        </div>

      </div>

    </div>
  </section>
  <?php endif; ?>
  <!-- ═══════════════════════════════
       END LATEST NEWS & UPDATES
  ═══════════════════════════════ -->


  <?php include $includes['footer']; ?>

  </div>
  <!-- ↑ closing .main-content — LAHAT ng content mula hero hanggang dito
       ay dapat nasa LOOB nito para gumana yung push/compress ng sidebar -->

  <?php
  $spinwheel_status = $conn->query("SELECT is_active FROM spinwheel_settings WHERE id = 1")->fetch_assoc();
  $spinwheel_active = $spinwheel_status && $spinwheel_status['is_active'] == 1;
  ?>
  <?php if ($spinwheel_active): ?>
  <a href="<?= BASE_URL ?>spinwheel" title="Spin to Win"
    class="fixed bottom-6 right-6 z-[9998] flex items-center gap-2.5 rounded-full
           bg-gradient-to-br from-[#c4905c] to-[#2f1200] pl-3 pr-5 py-2.5 text-white
           shadow-[0_8px_24px_rgba(47,18,0,0.35)] ring-4 ring-[#c4905c]/15
           transition-transform duration-300 ease-out
           hover:scale-105 hover:-translate-y-0.5 hover:shadow-[0_12px_30px_rgba(47,18,0,0.45)]
           sm:bottom-6 sm:right-6">

    <span class="flex h-9 w-9 shrink-0 animate-spin [animation-duration:4s] drop-shadow-[0_2px_4px_rgba(0,0,0,0.3)]">
      <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" class="h-full w-full">
        <circle cx="50" cy="50" r="48" fill="#fff" stroke="#2f1200" stroke-width="3" />
        <path d="M50 50 L50 2 A48 48 0 0 1 84 16 Z" fill="#e63946" />
        <path d="M50 50 L84 16 A48 48 0 0 1 98 50 Z" fill="#f4a261" />
        <path d="M50 50 L98 50 A48 48 0 0 1 84 84 Z" fill="#2a9d8f" />
        <path d="M50 50 L84 84 A48 48 0 0 1 50 98 Z" fill="#e9c46a" />
        <path d="M50 50 L50 98 A48 48 0 0 1 16 84 Z" fill="#264653" />
        <path d="M50 50 L16 84 A48 48 0 0 1 2 50 Z" fill="#f4a261" />
        <path d="M50 50 L2 50 A48 48 0 0 1 16 16 Z" fill="#e76f51" />
        <path d="M50 50 L16 16 A48 48 0 0 1 50 2 Z" fill="#2a9d8f" />
        <circle cx="50" cy="50" r="6" fill="#2f1200" />
      </svg>
    </span>

    <span class="flex flex-col leading-tight">
      <span class="font-montserrat text-[11px] sm:text-[12px] font-extrabold uppercase tracking-[1.5px] text-[#ffd9a0]">
        Spin to Win
      </span>
      <span class="font-montserrat text-[10px] sm:text-[11px] font-semibold tracking-wide text-white">
        Get a Discount!
      </span>
    </span>
  </a>
  <?php endif; ?>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const slides = document.querySelectorAll('#heroSlider .hero-slide');
      let current = 0;
      const SLIDE_DURATION = 5000; // dapat kapareho ng "5s" sa CSS animation

      function activateSlide(index) {
        slides.forEach((slide, i) => {
          if (i === index) {
            slide.classList.remove('opacity-0', 'is-active');

            // Force reflow para mag-restart ang CSS animation mula sa umpisa
            void slide.offsetWidth;

            slide.classList.add('opacity-100', 'is-active');

            // NEW: sync sidebar's background cutout to match the active hero slide
            document.documentElement.style.setProperty('--hero-bg-image', `url("${slide.src}")`);
          } else {
            slide.classList.remove('opacity-100', 'is-active');
            slide.classList.add('opacity-0');
          }
        });
      }

      // I-activate agad yung unang slide
      activateSlide(current);
      // NEW: make sure the sidebar cutout has a value on first paint too
      if (slides.length > 0) {
        document.documentElement.style.setProperty('--hero-bg-image', `url("${slides[current].src}")`);
      }

      if (slides.length > 1) {
        setInterval(function () {
          current = (current + 1) % slides.length;
          activateSlide(current);
        }, SLIDE_DURATION);
      }
    });
  </script>

    <script>
    // Meet the Team slider — arrow buttons scroll by one card's width,
    // and disable themselves at each end so it's clear where the list stops.
    document.addEventListener('DOMContentLoaded', function () {
      const track = document.getElementById('teamSliderTrack');
      const prevBtn = document.getElementById('teamSliderPrev');
      const nextBtn = document.getElementById('teamSliderNext');
      if (!track || !prevBtn || !nextBtn) return;

      function cardStep() {
        const card = track.querySelector('.team-slide-card');
        if (!card) return 240;
        const style = window.getComputedStyle(track);
        const gap = parseFloat(style.columnGap || style.gap || 20);
        return card.offsetWidth + gap;
      }

      function updateArrowState() {
        const maxScroll = track.scrollWidth - track.clientWidth - 2;
        prevBtn.disabled = track.scrollLeft <= 2;
        nextBtn.disabled = track.scrollLeft >= maxScroll;
      }

      prevBtn.addEventListener('click', () => {
        track.scrollBy({ left: -cardStep(), behavior: 'smooth' });
      });
      nextBtn.addEventListener('click', () => {
        track.scrollBy({ left: cardStep(), behavior: 'smooth' });
      });

      track.addEventListener('scroll', updateArrowState, { passive: true });
      window.addEventListener('resize', updateArrowState);
      updateArrowState();
    });
  </script>

  <script>
    // Team e-calling card modal
    const teamMembers = <?= json_encode(array_map(function($m){
      return [
        'full_name' => $m['full_name'],
        'position' => $m['position'] ?? '',
        'avatar_url' => $m['avatar_url'],
        'contact_number' => $m['contact_number'] ?? '',
        'social_gmail' => $m['social_gmail'] ?? '',
        'social_wechat' => $m['social_wechat'] ?? '',
        'social_viber' => $m['social_viber'] ?? '',
        'wechat_qr_url' => $m['wechat_qr_url'] ?? null,
        'viber_qr_url' => $m['viber_qr_url'] ?? null,
      ];
    }, $team_members)) ?>;

    let currentTeamMember = null;
    let qrMetaMap = {};
    let currentQrMeta = null;

    function renderTeamModal(m) {
      document.getElementById('tcmName').textContent = m.full_name;
      const monogramEl = document.getElementById('tcmMonogram');
      if (monogramEl) monogramEl.textContent = m.full_name.charAt(0).toUpperCase();

      const posEl = document.getElementById('tcmPosition');
      if (m.position) { posEl.textContent = m.position; posEl.classList.remove('hidden'); }
      else { posEl.textContent = ''; posEl.classList.add('hidden'); }

      const avatarImg = document.getElementById('tcmAvatar');
      const avatarInitial = document.getElementById('tcmInitial');
      if (m.avatar_url) {
        avatarImg.src = m.avatar_url;
        avatarImg.classList.remove('hidden');
        avatarInitial.classList.add('hidden');
        avatarInitial.classList.remove('flex');
      } else {
        avatarImg.classList.add('hidden');
        avatarInitial.textContent = m.full_name.charAt(0).toUpperCase();
        avatarInitial.classList.remove('hidden');
        avatarInitial.classList.add('flex');
      }

      let rows = '';
      if (m.contact_number) {
        rows += '<a href="tel:' + m.contact_number.replace(/\s+/g, '') + '" onclick="event.stopPropagation()" ' +
          'class="flex items-center gap-3 rounded-lg bg-[#faf8f6] px-4 py-2.5 text-[13px] text-[#2f1200] hover:bg-[#c4905c]/10 transition-colors">' +
          '<i class="ri-phone-line text-[#c4905c]"></i>' + m.contact_number + '</a>';
      }
      if (m.social_gmail) {
        rows += '<a href="mailto:' + m.social_gmail + '" onclick="event.stopPropagation()" ' +
          'class="flex items-center gap-3 rounded-lg bg-[#faf8f6] px-4 py-2.5 text-[13px] text-[#2f1200] hover:bg-[#c4905c]/10 transition-colors">' +
          '<i class="ri-mail-line text-[#c4905c]"></i>' + m.social_gmail + '</a>';
      }
      if (m.social_wechat) {
        rows += '<div class="flex items-center gap-3 rounded-lg bg-[#faf8f6] px-4 py-2.5 text-[13px] text-[#2f1200]">' +
          '<i class="ri-wechat-line text-[#c4905c]"></i>' + m.social_wechat + '</div>';
      }
      if (m.social_viber) {
        rows += '<div class="flex items-center gap-3 rounded-lg bg-[#faf8f6] px-4 py-2.5 text-[13px] text-[#2f1200]">' +
          '<i class="ri-phone-line text-[#c4905c]"></i>Viber: ' + m.social_viber + '</div>';
      }
      document.getElementById('tcmContacts').innerHTML = rows;

      const qrSection = document.getElementById('tcmQrSection');
      const qrToggle = document.getElementById('tcmQrToggle');
      const qrImg = document.getElementById('tcmQrImage');
      const qrOptions = [];
      if (m.wechat_qr_url) qrOptions.push({ key: 'wechat_qr_url', label: 'WeChat QR', icon: 'ri-wechat-line' });
      if (m.viber_qr_url) qrOptions.push({ key: 'viber_qr_url', label: 'Viber QR', icon: 'ri-phone-line' });

      if (qrOptions.length === 0) {
        qrSection.classList.add('hidden');
        qrSection.classList.remove('flex');
        qrToggle.innerHTML = '';
      } else {
        qrSection.classList.remove('hidden');
        qrSection.classList.add('flex');
        qrMetaMap = {};
        qrOptions.forEach(function (o) { qrMetaMap[o.key] = o; });
        qrToggle.innerHTML = qrOptions.map((o, idx) =>
          '<button type="button" data-key="' + o.key + '" onclick="tcmShowQr(\'' + o.key + '\')" ' +
          'class="tcm-qr-btn inline-flex items-center gap-1.5 rounded-full border px-4 py-1.5 font-montserrat text-[10px] font-bold uppercase tracking-[1px] transition-colors ' +
          (idx === 0 ? 'bg-[#2f1200] text-white border-[#2f1200]' : 'bg-white text-[#2f1200] border-[#c4905c]/30') + '">' +
          '<i class="' + o.icon + '"></i>' + o.label + '</button>'
        ).join('');
        qrImg.src = m[qrOptions[0].key];
        currentQrMeta = qrOptions[0];
      }
    }

    function openTeamModal(i) {
      currentTeamMember = teamMembers[i];
      if (!currentTeamMember) return;
      renderTeamModal(currentTeamMember);

      const modal = document.getElementById('teamCardModal');
      modal.classList.remove('hidden');
      modal.classList.add('flex');
      document.body.style.overflow = 'hidden';
      requestAnimationFrame(() => modal.classList.add('is-open'));
    }

    function closeTeamModal() {
      const modal = document.getElementById('teamCardModal');
      modal.classList.remove('is-open');
      document.body.style.overflow = '';
      setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
      }, 200);
    }

    function openQrLightbox() {
      const qrImg = document.getElementById('tcmQrImage');
      if (!qrImg || !qrImg.src) return;
      document.getElementById('qrLightboxImage').src = qrImg.src;
      document.getElementById('qrLightboxLabel').textContent = currentQrMeta ? currentQrMeta.label : 'QR Code';
      document.getElementById('qrLightboxIcon').className = currentQrMeta ? currentQrMeta.icon : 'ri-qr-code-line';

      const lightbox = document.getElementById('qrLightbox');
      lightbox.classList.remove('hidden');
      lightbox.classList.add('flex');
      requestAnimationFrame(() => lightbox.classList.add('is-open'));
    }

    function closeQrLightbox() {
      const lightbox = document.getElementById('qrLightbox');
      lightbox.classList.remove('is-open');
      setTimeout(() => {
        lightbox.classList.add('hidden');
        lightbox.classList.remove('flex');
      }, 200);
    }

    function tcmShowQr(key) {
      if (!currentTeamMember) return;
      document.getElementById('tcmQrImage').src = currentTeamMember[key];
      currentQrMeta = qrMetaMap[key] || null;
      document.querySelectorAll('.tcm-qr-btn').forEach(function (btn) {
        const active = btn.getAttribute('data-key') === key;
        btn.classList.toggle('bg-[#2f1200]', active);
        btn.classList.toggle('text-white', active);
        btn.classList.toggle('border-[#2f1200]', active);
        btn.classList.toggle('bg-white', !active);
        btn.classList.toggle('text-[#2f1200]', !active);
        btn.classList.toggle('border-[#c4905c]/30', !active);
      });
    }

    document.addEventListener('DOMContentLoaded', function () {
      const modal = document.getElementById('teamCardModal');
      modal.addEventListener('click', function (e) {
        if (e.target === this) closeTeamModal();
      });
      document.addEventListener('keydown', function (e) {
        const lightbox = document.getElementById('qrLightbox');
        if (e.key === 'Escape' && lightbox && !lightbox.classList.contains('hidden')) {
          closeQrLightbox();
          return;
        }
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeTeamModal();
      });
    });
  </script>

  <script>
    // Lazy-load ng Virtual Walkthrough iframe — kapareho ng ginagawa sa
    // index.php: mag-a-attach lang ng tunay na `src` kapag malapit na
    // itong makita ng user sa scroll, para hindi ito agad mag-load sa
    // unang page open (mas mabilis ang initial page speed).
    document.addEventListener('DOMContentLoaded', function () {
      const wIframe = document.querySelector('#mainWalkthroughWrapper iframe');
      if (wIframe) {
        const wObserver = new IntersectionObserver((entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              const src = wIframe.getAttribute('data-src');
              if (src && !wIframe.getAttribute('src')) {
                wIframe.setAttribute('src', src);
              }
              wObserver.unobserve(wIframe);
              const placeholder = document.getElementById('mainWalkthroughPlaceholder');
              if (placeholder) placeholder.style.display = 'none';
            }
          });
        }, { rootMargin: '200px' });

        wObserver.observe(wIframe);
      }
    });
  </script>

</body>

</html>