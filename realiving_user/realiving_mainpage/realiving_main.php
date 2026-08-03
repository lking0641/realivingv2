<?php
//realiving_main.php — Homepage

include $includes['connection'];

$hero_query = "SELECT * FROM hero_section WHERE is_active = 1 ORDER BY id DESC";
$hero_result = $conn->query($hero_query);
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Realiving Design Center</title>
  <link rel="icon" type="image/png" href="<?= CLIENT_ASSET ?>/images/logo/favicon.png">

  <link
    href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@400;500;600&display=swap&font-display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />

  <link rel="stylesheet" href="<?= BASE_ASSET ?>assets/css/output.css">

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
  padding-left: var(--sb-current-offset);
  padding-right: var(--sb-current-offset);
  box-sizing: border-box;
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
          <a href="javascript:void(0);"
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
          <span class="inline-block font-montserrat text-[10px] font-bold tracking-[3px] uppercase text-[#c4905c] mb-3">
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
                      class="w-full h-full object-cover scale-105 group-hover:scale-110 transition-transform duration-700"
                      autoplay muted loop playsinline>
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
       VIRTUAL WALKTHROUGH SECTION (modernized)
  ═══════════════════════════════ -->
  <section class="w-full pt-4 pb-20 bg-[#faf8f6]" id="walkthrough">
    <div class="max-w-6xl mx-auto px-4">

      <!-- Section Header — matched to Services header style -->
      <div class="text-center mb-14">
        <span class="inline-block font-montserrat text-[10px] font-bold tracking-[3px] uppercase text-[#c4905c] mb-3">
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
      <p class="text-center text-xs text-gray-400 mt-5 font-montserrat">
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
        <span class="inline-block font-montserrat text-[10px] font-bold tracking-[3px] uppercase text-[#c4905c] mb-3">
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
              <span class="block font-montserrat text-[9px] font-bold tracking-[2px] uppercase text-[#c4905c] mb-1.5">
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
            <a href="javascript:void(0);"
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
          <span class="inline-block font-montserrat text-[10px] font-bold tracking-[3px] uppercase text-[#c4905c] mb-3">
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
            <span class="inline-flex items-center gap-1.5 bg-[#e63946] text-white font-montserrat text-[9px] font-bold tracking-[1.5px] uppercase px-3 py-1.5 rounded-full">
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
                <span class="inline-block font-montserrat text-[9px] font-bold tracking-[1.5px] uppercase text-[#c4905c] mb-1.5">
                  <?= htmlspecialchars($row['category']) ?>
                </span>
                <h4 class="font-montserrat font-semibold text-[#2f1200] text-[13px] sm:text-sm leading-snug mb-1.5 line-clamp-2 group-hover:text-[#c4905c] transition-colors duration-300">
                  <?= htmlspecialchars($row['title']) ?>
                </h4>
                <span class="font-montserrat text-[10px] text-gray-400">
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