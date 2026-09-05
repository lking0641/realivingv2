<?php
//realiving_about
session_name("Realivinguser");
session_start();
include $includes['connection'];

$team_query = "SELECT full_name, position, contact_number, social_gmail, social_wechat, social_viber,
               profile_picture, google_picture, avatar_source, wechat_qr_image, viber_qr_image
               FROM account WHERE show_team_card = 1 ORDER BY id ASC";
$team_result = $conn->query($team_query);
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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>About Us — Realiving Design Center</title>
  <link rel="icon" type="image/png" href="<?= CLIENT_ASSET ?>/images/logo/favicon.png">

  <link
    href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@400;500;600&display=swap&font-display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />

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

    /* ── Meet the Team mobile slider (mirrors homepage) ───── */
    .team-slider-track {
      display: flex;
      gap: 20px;
      overflow-x: auto;
      scroll-snap-type: x mandatory;
      scroll-behavior: smooth;
      -webkit-overflow-scrolling: touch;
      padding: 6px 6px 20px;
      scrollbar-width: none;
      -ms-overflow-style: none;
    }
    .team-slider-track::-webkit-scrollbar { display: none; }

    .team-slide-card {
      scroll-snap-align: start;
      flex: 0 0 auto;
      width: 168px;
      background: #ffffff;
      border: 1px solid rgba(196, 144, 92, 0.18);
      border-radius: 16px;
      padding: 22px 14px 18px;
      text-align: center;
      box-shadow: 0 10px 30px rgba(47, 18, 0, 0.06);
    }

    .team-slide-photo {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      object-fit: cover;
      margin: 0 auto 12px;
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
      font-size: 20px;
    }

    .team-slide-name {
      font-family: 'Montserrat', sans-serif;
      font-weight: 700;
      font-size: 12.5px;
      letter-spacing: 0.3px;
      color: #2f1200;
      margin: 0 0 4px;
    }

    .team-slide-position {
      font-family: 'Montserrat', sans-serif;
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: #c4905c;
      margin: 0;
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
              <video autoplay loop muted playsinline
                disablepictureinpicture
                controlsList="nodownload noplaybackrate nofullscreen"
                class="h-[320px] w-full object-cover pointer-events-none sm:h-[400px] md:h-[460px]">
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
         MEET THE TEAM — blueprint bracket cards, matches Vision/Mission style
    ═══════════════════════════════ -->
    <?php if (!empty($team_members)): ?>
    <section class="reveal-up bg-white py-20 md:py-28" id="meet-the-team">
      <div class="mx-auto max-w-6xl px-6">

        <div class="mb-14 text-center">
          <span class="mb-3 inline-block font-montserrat text-[10px] font-bold uppercase tracking-[3px] text-[#c4905c]">
            The People Behind It
          </span>
          <h2 class="font-montserrat text-3xl font-bold uppercase tracking-wide text-[#2f1200] sm:text-4xl">
            Meet the Team
          </h2>
          <div class="mx-auto mt-4 h-0.5 w-16 rounded-full bg-[#c4905c] opacity-60"></div>
        </div>

        <!-- Mobile: horizontal swipe slider (matches homepage) -->
        <div class="sm:hidden">
          <div class="team-slider-track">
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
          <p class="mt-1 text-center text-[11px] text-gray-400 font-montserrat">
            <i class="ri-swipe-line mr-1"></i> Swipe to see more
          </p>
        </div>

        <!-- Desktop / tablet: blueprint bracket-card grid -->
        <div class="hidden gap-8 sm:grid sm:grid-cols-2 lg:grid-cols-3">
          <?php foreach ($team_members as $i => $member): ?>
            <div class="group relative cursor-pointer bg-white p-8 shadow-[0_10px_35px_rgba(47,18,0,0.07)] transition-shadow duration-300 hover:shadow-[0_18px_45px_rgba(47,18,0,0.14)]" onclick="openTeamModal(<?= $i ?>)">
              <span class="bp-bracket bp-tl"></span><span class="bp-bracket bp-tr"></span>
              <span class="bp-bracket bp-bl"></span><span class="bp-bracket bp-br"></span>

              <div class="mb-5 flex justify-center">
                <?php if (!empty($member['avatar_url'])): ?>
                  <img src="<?= htmlspecialchars($member['avatar_url']) ?>" alt="<?= htmlspecialchars($member['full_name']) ?>"
                    class="h-20 w-20 rounded-full object-cover border-2 border-[#c4905c]/30" loading="lazy">
                <?php else: ?>
                  <div class="flex h-20 w-20 items-center justify-center rounded-full border-2 border-[#c4905c]/30 bg-[#faf8f6] font-montserrat text-xl font-bold text-[#c4905c]">
                    <?= htmlspecialchars(strtoupper(substr($member['full_name'], 0, 1))) ?>
                  </div>
                <?php endif; ?>
              </div>

              <div class="text-center">
                <h3 class="font-montserrat text-[15px] font-bold uppercase tracking-[1px] text-[#2f1200]">
                  <?= htmlspecialchars($member['full_name']) ?>
                </h3>
                <?php if (!empty($member['position'])): ?>
                  <p class="mt-1 font-montserrat text-[11px] uppercase tracking-[1px] text-[#c4905c]">
                    <?= htmlspecialchars($member['position']) ?>
                  </p>
                <?php endif; ?>
              </div>

              <?php if (!empty($member['contact_number']) || !empty($member['social_gmail']) || !empty($member['social_wechat']) || !empty($member['social_viber'])): ?>
                <div class="mt-5 flex flex-col items-center gap-1.5 border-t border-[#c4905c]/15 pt-4">
                  <?php if (!empty($member['contact_number'])): ?>
                    <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $member['contact_number'])) ?>" onclick="event.stopPropagation()"
                      class="flex items-center gap-2 text-[13px] text-[#5e5e5e] hover:text-[#c4905c] transition-colors">
                      <i class="ri-phone-line text-[#c4905c]"></i> <?= htmlspecialchars($member['contact_number']) ?>
                    </a>
                  <?php endif; ?>
                  <?php if (!empty($member['social_gmail'])): ?>
                    <a href="mailto:<?= htmlspecialchars($member['social_gmail']) ?>" onclick="event.stopPropagation()"
                      class="flex items-center gap-2 text-[13px] text-[#5e5e5e] hover:text-[#c4905c] transition-colors">
                      <i class="ri-mail-line text-[#c4905c]"></i> <?= htmlspecialchars($member['social_gmail']) ?>
                    </a>
                  <?php endif; ?>
                  <?php if (!empty($member['social_wechat'])): ?>
                    <span class="flex items-center gap-2 text-[13px] text-[#5e5e5e]">
                      <i class="ri-wechat-line text-[#c4905c]"></i> <?= htmlspecialchars($member['social_wechat']) ?>
                    </span>
                  <?php endif; ?>
                  <?php if (!empty($member['social_viber'])): ?>
                    <span class="flex items-center gap-2 text-[13px] text-[#5e5e5e]">
                      <i class="ri-phone-line text-[#c4905c]"></i> Viber: <?= htmlspecialchars($member['social_viber']) ?>
                    </span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

      </div>
    </section>
    <?php endif; ?>
    <!-- ═══════════════════════════════
         END MEET THE TEAM
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
    <!-- ═══════════════════════════════
         END TEAM E-CALLING CARD MODAL
    ═══════════════════════════════ -->

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

</body>

</html>