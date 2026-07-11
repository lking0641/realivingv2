<?php
// footer.php — Modernized, matched to index.php's Tailwind design language.
// Uses the same tokens already established sitewide:
//   #2f1200  deep espresso (brand dark, used sparingly here — CTA button + headline text)
//   #c4905c  warm gold (accent — the signature element of this footer: every
//            divider, border, and section rule is gold, giving the footer a
//            "certificate / brochure" feel that's distinct from the rest of the page)
//   #f5f0e8  warm cream (footer bg — slightly deeper than the site's #faf8f6
//            so it still reads as its own section when scrolled into)
//   Montserrat  -> labels / body / uppercase eyebrows
//   Cormorant Garamond -> display serif (same face used in the hero H1)
//   Remixicon (ri-*) -> already loaded once in the page <head>, no extra icon font needed
?>

<footer class="relative bg-[#f5f0e8] text-[#2f1200] overflow-hidden">

  <!-- Diagonal cut at the top — same signature move used on the service-card
       media banners (clip-path polygon), carried into the footer so the
       transition feels like part of the same system, not a bolted-on section. -->
  <div class="absolute top-0 left-0 right-0 h-10 sm:h-14 bg-white"
       style="clip-path: polygon(0 0, 100% 0, 100% 35%, 0 100%);"></div>

  <!-- Gold hairline tracing the top edge — first hint of the gold-forward
       signature before the thicker rules further down -->
  <div class="absolute top-10 sm:top-14 left-0 right-0 h-[2px] bg-[#c4905c]"></div>

  <div class="relative max-w-7xl mx-auto px-6 sm:px-8 pt-20 sm:pt-24">

    <!-- ═══ CTA STRIP ═══ -->
    <div class="flex flex-col items-center text-center pb-14 sm:pb-16 border-b-2 border-[#c4905c]/50">
      <span class="font-montserrat text-[10px] font-bold tracking-[3px] uppercase text-[#c4905c] mb-4">
        Design &bull; Fabricate &bull; Install
      </span>
      <h2 class="max-w-2xl font-normal leading-[1.2] text-3xl sm:text-4xl md:text-5xl mb-8 text-[#2f1200]"
          style="font-family: 'Cormorant Garamond', serif;">
        Let's build a space that feels like you.
      </h2>
      <div class="flex flex-col sm:flex-row items-center gap-4">
        <a href="javascript:void(0);" class="openFormBtn inline-flex items-center gap-2 bg-[#2f1200] px-8 py-3.5
                  text-[11px] font-montserrat font-semibold uppercase tracking-[2px] text-white
                  transition-all duration-300 hover:bg-[#c4905c] rounded-full">
          <i class="ri-send-plane-line text-[13px]"></i> Inquire Now
        </a>
        <a href="tel:09851245929" class="inline-flex items-center gap-2 border-2 border-[#c4905c] px-8 py-3.5
                  text-[11px] font-montserrat font-semibold uppercase tracking-[2px] text-[#2f1200]
                  transition-all duration-300 hover:bg-[#c4905c] hover:text-white rounded-full">
          <i class="ri-phone-line text-[13px]"></i> Call Us
        </a>
      </div>
    </div>

    <!-- ═══ MAIN GRID ═══ -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-10 lg:gap-8 py-14 sm:py-16">

      <!-- Brand -->
      <div class="lg:col-span-1">
        <img src="<?= CLIENT_ASSET ?>/images/logo/logo.png" alt="Realiving Logo" class="h-11 w-auto mb-5">
        <p class="font-montserrat text-[13px] text-[#5a3520] leading-relaxed mb-6 max-w-xs">
          Crafting timeless interiors — from concept to fabrication to install — for spaces that are built to be lived in.
        </p>
        <div class="flex items-center gap-3">
          <a href="https://www.facebook.com/profile.php?id=61565332146101" target="_blank" aria-label="Facebook"
             class="w-10 h-10 rounded-full bg-white border border-[#c4905c]/40 flex items-center justify-center
                    text-[#2f1200] transition-all duration-300 hover:bg-[#c4905c] hover:border-[#c4905c] hover:text-white">
            <i class="ri-facebook-fill text-base"></i>
          </a>
          <a href="https://www.instagram.com/realivingdesigncorp/" target="_blank" aria-label="Instagram"
             class="w-10 h-10 rounded-full bg-white border border-[#c4905c]/40 flex items-center justify-center
                    text-[#2f1200] transition-all duration-300 hover:bg-[#c4905c] hover:border-[#c4905c] hover:text-white">
            <i class="ri-instagram-line text-base"></i>
          </a>
        </div>
      </div>

      <!-- Quick Links -->
      <div>
        <p class="font-montserrat text-[10px] font-bold tracking-[2px] uppercase text-[#c4905c] mb-6 pb-2.5 border-b-2 border-[#c4905c] inline-block">
          Explore
        </p>
        <ul class="flex flex-col gap-3.5 font-montserrat text-[13px] text-[#5a3520]">
          <li><a href="<?= BASE_URL ?>" class="group inline-flex items-center gap-1.5 hover:text-[#2f1200] transition-colors duration-300">
            <i class="ri-arrow-right-s-line text-[#c4905c] opacity-0 -ml-4 group-hover:opacity-100 group-hover:ml-0 transition-all duration-300"></i> Home
          </a></li>
          <li><a href="<?= BASE_URL ?>projects" class="group inline-flex items-center gap-1.5 hover:text-[#2f1200] transition-colors duration-300">
            <i class="ri-arrow-right-s-line text-[#c4905c] opacity-0 -ml-4 group-hover:opacity-100 group-hover:ml-0 transition-all duration-300"></i> Projects
          </a></li>
          <li><a href="<?= BASE_URL ?>concepts" class="group inline-flex items-center gap-1.5 hover:text-[#2f1200] transition-colors duration-300">
            <i class="ri-arrow-right-s-line text-[#c4905c] opacity-0 -ml-4 group-hover:opacity-100 group-hover:ml-0 transition-all duration-300"></i> Concepts
          </a></li>
          <li><a href="<?= BASE_URL ?>about" class="group inline-flex items-center gap-1.5 hover:text-[#2f1200] transition-colors duration-300">
            <i class="ri-arrow-right-s-line text-[#c4905c] opacity-0 -ml-4 group-hover:opacity-100 group-hover:ml-0 transition-all duration-300"></i> About
          </a></li>
          <li><a href="<?= BASE_URL ?>services" class="group inline-flex items-center gap-1.5 hover:text-[#2f1200] transition-colors duration-300">
            <i class="ri-arrow-right-s-line text-[#c4905c] opacity-0 -ml-4 group-hover:opacity-100 group-hover:ml-0 transition-all duration-300"></i> Services
          </a></li>
        </ul>
      </div>

      <!-- Store Hours -->
      <div>
        <p class="font-montserrat text-[10px] font-bold tracking-[2px] uppercase text-[#c4905c] mb-6 pb-2.5 border-b-2 border-[#c4905c] inline-block">
          Store Hours
        </p>
        <div class="flex flex-col font-montserrat text-[13px]">
          <div class="flex justify-between py-2.5 border-b border-[#c4905c]/25">
            <span class="text-[#2f1200] font-medium">Mon – Fri</span>
            <span class="text-[#5a3520]">7:00 AM – 5:00 PM</span>
          </div>
          <div class="flex justify-between py-2.5 border-b border-[#c4905c]/25">
            <span class="text-[#2f1200] font-medium">Saturday</span>
            <span class="text-[#5a3520]">8:00 AM – 12:00 PM</span>
          </div>
          <div class="flex justify-between py-2.5">
            <span class="text-[#2f1200] font-medium">Sunday</span>
            <span class="text-[#5a3520]">Closed</span>
          </div>
        </div>
      </div>

      <!-- Contact -->
      <div>
        <p class="font-montserrat text-[10px] font-bold tracking-[2px] uppercase text-[#c4905c] mb-6 pb-2.5 border-b-2 border-[#c4905c] inline-block">
          Get In Touch
        </p>
        <div class="flex flex-col gap-4 font-montserrat text-[13px] text-[#5a3520]">
          <div class="flex items-start gap-3">
            <i class="ri-map-pin-2-line text-[#c4905c] text-base mt-0.5 shrink-0"></i>
            <span>MC Premier – EDSA Balintawak, Quezon City</span>
          </div>
          <a href="tel:09851245929" class="flex items-start gap-3 hover:text-[#2f1200] transition-colors duration-300">
            <i class="ri-phone-line text-[#c4905c] text-base mt-0.5 shrink-0"></i>
            <span>0985 124 5929</span>
          </a>
          <a href="mailto:realivingdesign.corp@gmail.com" class="flex items-start gap-3 hover:text-[#2f1200] transition-colors duration-300 break-all">
            <i class="ri-mail-line text-[#c4905c] text-base mt-0.5 shrink-0"></i>
            <span>realivingdesign.corp@gmail.com</span>
          </a>
        </div>
      </div>

    </div>

    <!-- ═══ BOTTOM BAR ═══ -->
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 py-6 border-t-2 border-[#c4905c]/50">
      <span class="font-montserrat text-[11px] text-[#5a3520]/70 text-center">
        &copy;2026 Realiving Design Center Corporation. All rights reserved.
      </span>
      <span class="font-montserrat text-[11px] text-[#5a3520]/60 flex items-center gap-1.5">
        <i class="ri-map-pin-line"></i> Quezon City, Philippines
      </span>
    </div>

  </div>
</footer>