<?php
//realiving_projects.php — Projects Page (Tailwind version, styled to match realiving_main.php)
session_name("Realivinguser");
session_start();
include $includes['connection'];
include $includes['assignement_logic'];

// Get category filter from URL (optional)
$selected_category = isset($_GET['category']) ? $_GET['category'] : 'all';

$category_labels = [
  'all'         => 'All Projects',
  'site'        => 'Site Projects',
  'residential' => 'Individual Projects'
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Realiving Design Center - Projects</title>
  <link rel="icon" type="image/png" href="<?= CLIENT_ASSET ?>/images/logo/favicon.png">

  <link
    href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@400;500;600&display=swap&font-display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />

  <link rel="stylesheet" href="<?= BASE_ASSET ?>assets/css/output.css">

  <style>
    /*
      Row-based hover expand/shrink mechanics for the project cards.
      This stays as raw CSS (same math as all-projects.css) because the
      JS toggles these classes dynamically per-row and Tailwind utility
      classes can't be swapped in/out with arbitrary calc() values as
      cleanly as a couple of hand-written rules can.
    */
    .proj-card-wrapper {
      flex: 0 1 calc(33.333% - 18px);
      max-width: calc(33.333% - 18px);
      transition: flex .4s cubic-bezier(.25,.46,.45,.94), max-width .4s cubic-bezier(.25,.46,.45,.94);
    }

    .proj-card-wrapper.is-hovered {
      flex: 0 1 calc(50% - 18px);
      max-width: calc(50% - 18px);
    }

    .proj-card-wrapper.is-shrunk {
      flex: 0 1 calc(25% - 18px);
      max-width: calc(25% - 18px);
    }

    .proj-card-wrapper.solo-row.is-hovered,
    .proj-card-wrapper.solo-row.is-shrunk {
      flex: 0 1 calc(33.333% - 18px);
      max-width: calc(33.333% - 18px);
    }

    @media (max-width: 1024px) {
      .proj-card-wrapper {
        flex: 0 1 calc(50% - 18px);
        max-width: calc(50% - 18px);
      }
      .proj-card-wrapper.is-hovered {
        flex: 0 1 calc(60% - 18px);
        max-width: calc(60% - 18px);
      }
      .proj-card-wrapper.is-shrunk {
        flex: 0 1 calc(40% - 18px);
        max-width: calc(40% - 18px);
      }
    }

    @media (max-width: 767px) {
      .proj-card-wrapper,
      .proj-card-wrapper.is-hovered,
      .proj-card-wrapper.is-shrunk {
        flex: none;
        max-width: 100%;
        width: 100%;
      }
    }

    /* Content reveal on hover — mirrors the slideUp animation from all-projects.css */
    .proj-reveal {
      opacity: 0;
      transform: translateY(18px);
      transition: opacity .5s cubic-bezier(.25,.46,.45,.94), transform .5s cubic-bezier(.25,.46,.45,.94);
    }
    .proj-card-wrapper:hover .proj-reveal {
      opacity: 1;
      transform: translateY(0);
    }
    .proj-reveal.delay-1 { transition-delay: .06s; }
    .proj-reveal.delay-2 { transition-delay: .12s; }

    @media (max-width: 767px) {
      .proj-reveal {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .proj-hover-img { opacity: 0; }
    .proj-card-wrapper:hover .proj-default-img { opacity: 0; }
    .proj-card-wrapper:hover .proj-hover-img { opacity: 1; }

    /*
      Lock the shared sidebar (#sidebar, from realiving_sidebar.php) to its
      solid-white style ONLY on this page. The blur/transparent-then-white
      transition it does on scroll is tied to --hero-bg-image, which only
      the homepage's slider sets — this page has no hero slider, so we skip
      that behavior entirely and just keep the sidebar white all the time.
      !important is used here on purpose: it needs to beat the shared
      sidebar's own rules (including its .scrolled state) regardless of
      cascade order, without editing realiving_sidebar.php itself so the
      homepage keeps its original blurred-hero behavior untouched.
    */
    #sidebar,
    #sidebar.scrolled {
      background: #ffffff !important;
      border-right: 1px solid rgba(0,0,0,0.08) !important;
      box-shadow: 2px 0 16px rgba(0,0,0,0.05) !important;
      backdrop-filter: none !important;
      -webkit-backdrop-filter: none !important;
    }
    #sidebar::before,
    #sidebar::after { display: none !important; }

    #sidebar .sb-header { border-bottom-color: rgba(0,0,0,0.08) !important; }
    #sidebar .sb-logo-mark { border-color: rgba(0,0,0,0.1) !important; }
    #sidebar .sb-collapse-btn { border-color: rgba(0,0,0,0.2) !important; color: #2f1200 !important; }
    #sidebar .sb-collapse-btn:hover { background: rgba(0,0,0,0.05) !important; }
    #sidebar .sb-label { color: rgba(0,0,0,0.45) !important; }
    #sidebar .sb-link { color: #2b2b2b !important; }
    #sidebar .sb-link i { color: #8a8a8a !important; }
    #sidebar .sb-link:hover { background: rgba(47,18,0,0.06) !important; }
    #sidebar .sb-link:hover i { color: #2f1200 !important; }
    #sidebar .sb-link.active { background: rgba(47,18,0,0.08) !important; }
    #sidebar .sb-divider { background: rgba(0,0,0,0.08) !important; }
    #sidebar .sb-footer { border-top-color: rgba(0,0,0,0.08) !important; }
    #sidebar .sb-book-btn { border-color: #2f1200 !important; color: #2f1200 !important; }
    #sidebar .sb-book-btn:hover { background: #2f1200 !important; color: #fff !important; }

    /* Force the dark/black logo variants since the sidebar never goes
       transparent here (the JS otherwise swaps these based on scroll
       position, which we're intentionally bypassing above) */
    #sbLogoWhite, #sbMarkWhite { display: none !important; }
    #sbLogoDark,  #sbMarkDark  { display: block !important; }

    /* Horizontal-scrolling category pill strip on mobile instead of
       wrapping into a cramped multi-line block */
    .cat-scroll {
      scrollbar-width: none;
      -ms-overflow-style: none;
    }
    .cat-scroll::-webkit-scrollbar { display: none; }
  </style>
</head>

<body class="projects-page">

  <?php include $includes['header']; ?>

  <div class="main-content">

    <!-- ═══════════════════════════════
       PAGE BANNER
    ═══════════════════════════════ -->
    <section class="relative h-[30vh] min-h-[200px] sm:h-[38vh] sm:min-h-[260px] md:h-[42vh] md:min-h-[280px] w-full overflow-hidden">
      <img src="<?= CLIENT_ASSET ?>/images/background-image.jpg" alt="Realiving Projects"
        class="absolute inset-0 h-full w-full object-cover object-center scale-105">
      <div class="pointer-events-none absolute inset-0 bg-black/40"></div>
      <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-[#0e0704]/90 via-[#0e0704]/30 to-[#0e0704]/10"></div>

      <div class="relative z-10 flex h-full w-full flex-col items-center justify-center px-6 text-center text-white">
        <span class="mb-3 md:mb-4 text-[9px] sm:text-[10px] font-semibold uppercase tracking-[3px] text-white/80 md:tracking-[6px]">
          Our Portfolio
        </span>
        <h1 class="max-w-3xl font-normal leading-[1.15] text-2xl sm:text-3xl md:text-5xl"
          style="font-family: 'Cormorant Garamond', serif;">
          Spaces We've Brought to Life
        </h1>
      </div>
    </section>
    <!-- ═══════════════════════════════
       END PAGE BANNER
    ═══════════════════════════════ -->


    <!-- ═══════════════════════════════
       FILTER + SEARCH BAR (sticky)
    ═══════════════════════════════ -->
    <section class="sticky top-0 z-30 bg-[#faf8f6]/95 backdrop-blur-sm border-b border-[#c4905c]/20 shadow-[0_2px_10px_rgba(47,18,0,0.05)]">
      <div class="max-w-7xl mx-auto px-3 sm:px-6 py-3 sm:py-4 flex flex-col md:flex-row items-stretch md:items-center gap-3 md:gap-4 justify-between">

        <!-- Category Pills — horizontal scroll strip on mobile, no wrapping -->
        <div class="cat-scroll flex md:flex-wrap flex-nowrap overflow-x-auto md:overflow-visible justify-start md:justify-center gap-2 -mx-3 px-3 md:mx-0 md:px-0" id="categoryLinks">
          <a href="#" data-category="all"
            class="category-link flex-shrink-0 inline-flex items-center px-4 sm:px-5 py-2 sm:py-2.5 rounded-full font-montserrat text-[10px] sm:text-[11px] font-semibold uppercase tracking-[1.5px] border whitespace-nowrap transition-all duration-300
                   <?= $selected_category === 'all' ? 'bg-[#2f1200] text-white border-[#2f1200]' : 'bg-white text-[#2f1200] border-[#e3d6c5] hover:border-[#c4905c]' ?>">
            All
          </a>
          <a href="#" data-category="site"
            class="category-link flex-shrink-0 inline-flex items-center px-4 sm:px-5 py-2 sm:py-2.5 rounded-full font-montserrat text-[10px] sm:text-[11px] font-semibold uppercase tracking-[1.5px] border whitespace-nowrap transition-all duration-300
                   <?= $selected_category === 'site' ? 'bg-[#2f1200] text-white border-[#2f1200]' : 'bg-white text-[#2f1200] border-[#e3d6c5] hover:border-[#c4905c]' ?>">
            Site Projects
          </a>
          <a href="#" data-category="residential"
            class="category-link flex-shrink-0 inline-flex items-center px-4 sm:px-5 py-2 sm:py-2.5 rounded-full font-montserrat text-[10px] sm:text-[11px] font-semibold uppercase tracking-[1.5px] border whitespace-nowrap transition-all duration-300
                   <?= $selected_category === 'residential' ? 'bg-[#2f1200] text-white border-[#2f1200]' : 'bg-white text-[#2f1200] border-[#e3d6c5] hover:border-[#c4905c]' ?>">
            Individual Projects
          </a>
        </div>

        <!-- Search -->
        <div class="relative w-full md:w-72">
          <input type="text" id="projectSearch" autocomplete="off" placeholder="Search by name or location..."
            class="w-full pl-4 pr-10 py-2 sm:py-2.5 rounded-full border border-[#e3d6c5] font-montserrat text-[13px] text-[#2f1200] placeholder:text-gray-400 outline-none focus:border-[#c4905c] transition-colors duration-300">
          <i class="ri-search-line absolute right-4 top-1/2 -translate-y-1/2 text-[#c4905c] text-base pointer-events-none"></i>

          <div id="searchSuggestions"
            class="hidden absolute top-[calc(100%+8px)] left-0 right-0 bg-white rounded-xl border border-[#e3d6c5] shadow-[0_16px_40px_rgba(47,18,0,0.14)] max-h-[400px] overflow-y-auto z-40">
          </div>
        </div>

      </div>
    </section>
    <!-- ═══════════════════════════════
       END FILTER + SEARCH BAR
    ═══════════════════════════════ -->


    <!-- ═══════════════════════════════
       PROJECTS GRID SECTION
    ═══════════════════════════════ -->
    <section class="py-10 sm:py-16 md:py-20 bg-white" id="projectsSection">
      <div class="max-w-7xl mx-auto px-3 sm:px-6">

        <div class="text-center mb-8 sm:mb-14">
          <span class="inline-block font-montserrat text-[9px] sm:text-[10px] font-bold tracking-[3px] uppercase text-[#c4905c] mb-2 sm:mb-3">
            Our Work
          </span>
          <h2 class="text-xl sm:text-3xl md:text-4xl font-bold text-[#2f1200] font-montserrat uppercase tracking-wide mb-3 sm:mb-4 px-2">
            <span id="project-title-category"><?= $category_labels[$selected_category] ?? 'All Projects' ?></span>
          </h2>
          <div class="mx-auto h-0.5 w-16 bg-[#c4905c] opacity-60 rounded-full"></div>
        </div>

        <div class="flex flex-wrap gap-3 sm:gap-[18px] justify-start" id="projectsContainer">
          <?php
          $sql = "SELECT * FROM project ORDER BY id DESC";
          $result = $conn->query($sql);

          if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
              $main_image = '/' . ltrim($row['main_image'], '/');
              $hover_image = '/' . ltrim($row['hover_image'], '/');
              $catLabel = $row['category'] === 'site' ? 'Site Project' : 'Residential Interior';
              ?>
              <div class="proj-card-wrapper h-[280px] sm:h-[360px] md:h-[440px]" data-category="<?= htmlspecialchars($row['category']) ?>">
                <a href="<?= BASE_URL ?>view-projects?id=<?= htmlspecialchars($row['id']) ?>"
                  class="group relative block h-full w-full rounded-2xl overflow-hidden shadow-[0_10px_30px_rgba(47,18,0,0.12)] hover:shadow-[0_24px_50px_rgba(47,18,0,0.22)] transition-shadow duration-500 bg-[#2f1200]">

                  <img class="proj-default-img absolute inset-0 h-full w-full object-cover transition-opacity duration-500"
                    src="<?= CLIENT_ASSET . htmlspecialchars(ltrim($main_image, '.')) ?>"
                    alt="<?= htmlspecialchars($row['title']) ?>">
                  <img class="proj-hover-img absolute inset-0 h-full w-full object-cover transition-opacity duration-500"
                    src="<?= CLIENT_ASSET . htmlspecialchars(ltrim($hover_image, '.')) ?>"
                    alt="<?= htmlspecialchars($row['title']) ?> - Alternate view">

                  <div class="absolute inset-0 bg-gradient-to-t from-[#0e0704]/90 via-[#0e0704]/25 to-transparent"></div>

                  <span class="proj-reveal absolute top-4 left-4 font-montserrat text-[9px] font-bold tracking-[1.5px] uppercase text-white px-3 py-1.5 rounded-full"
                    style="background:rgba(255,255,255,0.15); backdrop-filter:blur(10px); border:1px solid rgba(255,255,255,0.25);">
                    <?= $catLabel ?>
                  </span>

                  <div class="absolute bottom-0 left-0 right-0 p-6">
                    <h3 class="proj-reveal title font-montserrat font-bold text-white text-lg sm:text-xl leading-snug mb-2">
                      <?= htmlspecialchars($row['title']) ?>
                    </h3>
                    <p class="proj-reveal delay-1 location flex items-center gap-1.5 font-montserrat text-[12px] text-white/75 mb-3">
                      <i class="ri-map-pin-line"></i> <?= htmlspecialchars($row['address']) ?>
                    </p>
                    <span class="proj-reveal delay-2 category-label inline-flex items-center gap-1.5 font-montserrat text-[10px] font-bold tracking-[1.5px] uppercase text-[#c4905c]">
                      View Project <i class="ri-arrow-right-up-line"></i>
                    </span>
                  </div>

                </a>
              </div>
              <?php
            }
          } else {
            echo '<div class="no-projects w-full text-center py-20 text-gray-400">
                    <i class="ri-folder-open-line text-6xl mb-5 block text-gray-300"></i>
                    <h3 class="font-montserrat text-2xl font-bold text-[#2f1200] mb-2">No Projects Found</h3>
                    <p class="font-montserrat text-sm text-gray-500">Check back later for new projects.</p>
                  </div>';
          }
          ?>
        </div>

        <?php if ($result && $result->num_rows > 0): ?>
          <div id="paginationContainer" class="flex flex-wrap justify-center items-center gap-2 mt-16"></div>
        <?php endif; ?>

      </div>
    </section>
    <!-- ═══════════════════════════════
       END PROJECTS GRID SECTION
    ═══════════════════════════════ -->


    <!-- ═══════════════════════════════
       CABINET COST CTA BANNER
    ═══════════════════════════════ -->
    <section class="bg-[#faf8f6] py-8 sm:py-16" id="cabinet-cost">
      <div class="max-w-7xl mx-auto px-3 sm:px-6">

        <div class="relative rounded-2xl sm:rounded-[2rem] overflow-hidden shadow-[0_30px_70px_rgba(47,18,0,0.25)] h-[380px] sm:h-[480px] md:h-[520px]">

          <img src="<?= CLIENT_ASSET ?>/images/background-image.jpg" alt="Know your cabinet cost"
            class="absolute inset-0 w-full h-full object-cover scale-105">

          <div class="absolute inset-0 bg-gradient-to-t from-[#0e0704]/90 via-[#0e0704]/40 to-[#0e0704]/10"></div>
          <div class="absolute inset-0 bg-gradient-to-r from-[#0e0704]/70 via-transparent to-transparent"></div>

          <!-- Floating badge — hidden on small screens since the card is too short there for it not to collide with the text below -->
          <div class="hidden sm:flex absolute top-6 right-6 sm:top-8 sm:right-8 items-center gap-2 px-4 py-2.5 rounded-full"
            style="background:linear-gradient(135deg, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0.05) 100%);
                   backdrop-filter:blur(16px) saturate(160%);
                   -webkit-backdrop-filter:blur(16px) saturate(160%);
                   border:1px solid rgba(255,255,255,0.2);">
            <i class="ri-price-tag-3-line text-[#c4905c] text-sm"></i>
            <span class="font-montserrat text-[10px] font-bold tracking-[2px] uppercase text-white">
              Free Consultation
            </span>
          </div>

          <div class="relative z-10 h-full flex flex-col justify-end px-5 sm:px-10 md:px-14 pb-6 sm:pb-12 md:pb-16">

            <span class="inline-block font-montserrat text-[9px] sm:text-[10px] font-bold tracking-[2px] sm:tracking-[3px] uppercase text-[#c4905c] mb-2 sm:mb-4">
              ✦ Let's Talk
            </span>

            <h2 class="font-montserrat font-bold uppercase tracking-wide text-white leading-[1.2]
                       text-lg sm:text-3xl md:text-4xl lg:text-5xl max-w-2xl mb-2 sm:mb-4">
              Know Your Cabinet Cost with Confidence
            </h2>

            <p class="font-montserrat text-white/75 text-[12px] sm:text-base max-w-lg mb-4 sm:mb-8 line-clamp-2 sm:line-clamp-none">
              Have a vision in mind but not sure where to begin? Our design experts are ready to guide you through
              every step — from concept to completion.
            </p>

            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 sm:gap-4">
              <a href="<?= BASE_URL ?>appointment"
                class="group inline-flex items-center gap-2 whitespace-nowrap bg-white text-[#2f1200] font-montserrat font-bold text-[9px] sm:text-[11px] tracking-[1.5px] sm:tracking-[2px] uppercase px-5 sm:px-8 py-2.5 sm:py-4 rounded-full transition-all duration-300 hover:bg-[#2f1200] hover:text-white shadow-lg">
                Book an Appointment
                <i class="ri-arrow-right-line text-sm transition-transform duration-300 group-hover:translate-x-1"></i>
              </a>
            </div>

          </div>

        </div>

      </div>
    </section>
    <!-- ═══════════════════════════════
       END CABINET COST CTA BANNER
    ═══════════════════════════════ -->

    <?php include $includes['footer']; ?>

  </div>

  <?php $conn->close(); ?>

  <script>
    // ===== STATE =====
    const CARDS_PER_PAGE = 9;
    let currentPage = 1;
    let currentCategory = <?= json_encode($selected_category) ?>;

    // ===== SELECTORS =====
    const categoryLinks = document.querySelectorAll('.category-link');
    const titleCategory = document.getElementById('project-title-category');
    const projectsContainer = document.getElementById('projectsContainer');
    const searchInput = document.getElementById('projectSearch');
    const searchSuggestions = document.getElementById('searchSuggestions');

    const categoryLabels = { 'all': 'All Projects', 'site': 'Site Projects', 'residential': 'Individual Projects' };

    let allProjectsData = [];
    let activeSearch = false;

    // ===== HELPERS =====
    function getAllWrappers() {
      return Array.from(document.querySelectorAll('.proj-card-wrapper[data-category]'));
    }

    function getEligibleCards() {
      return getAllWrappers().filter(w => {
        const matchesCategory = currentCategory === 'all' || w.dataset.category === currentCategory;
        const matchesSearch = !activeSearch || w.dataset.searchMatch === 'true';
        return matchesCategory && matchesSearch;
      });
    }

    // ===== RENDER =====
    function render() {
      // Reset all hover states before re-rendering to prevent stuck expanded cards
      getAllWrappers().forEach(w => w.classList.remove('is-hovered', 'is-shrunk', 'solo-row'));
      const all = getAllWrappers();
      const eligible = getEligibleCards();
      const totalPages = Math.ceil(eligible.length / CARDS_PER_PAGE);

      // Clamp currentPage
      if (currentPage > totalPages) currentPage = Math.max(1, totalPages);

      const start = (currentPage - 1) * CARDS_PER_PAGE;
      const end = start + CARDS_PER_PAGE;
      const pageCards = eligible.slice(start, end);
      const pageSet = new Set(pageCards);

      // Show/hide cards
      all.forEach(w => {
        w.style.display = pageSet.has(w) ? '' : 'none';
      });

      // Empty state
      const existing = projectsContainer.querySelector('.no-projects');
      if (existing) existing.remove();

      if (eligible.length === 0) {
        const emptyState = document.createElement('div');
        emptyState.className = 'no-projects w-full text-center py-20 text-gray-400';
        emptyState.innerHTML = `
          <i class="ri-folder-open-line text-6xl mb-5 block text-gray-300"></i>
          <h3 class="font-montserrat text-2xl font-bold text-[#2f1200] mb-2">No Projects Found</h3>
          <p class="font-montserrat text-sm text-gray-500">Check back later for new projects.</p>`;
        projectsContainer.appendChild(emptyState);
      }

      renderPaginationUI(totalPages);
      initCardHover();
    }

    function renderPaginationUI(totalPages) {
      const container = document.getElementById('paginationContainer');
      if (!container) return;
      container.innerHTML = '';
      if (totalPages <= 1) return;

      const baseBtn = 'min-w-[42px] h-[42px] px-2.5 flex items-center justify-center rounded-lg font-montserrat text-[13px] font-semibold border-[1.5px] transition-all duration-300';
      const idleBtn = 'bg-white text-[#2f1200] border-[#e3d6c5] hover:bg-[#2f1200] hover:text-white hover:border-[#2f1200] hover:-translate-y-0.5';
      const activeBtn = 'bg-[#2f1200] text-white border-[#2f1200] shadow-[0_4px_10px_rgba(47,18,0,0.3)] -translate-y-0.5';

      function makePageBtn(i) {
        const btn = document.createElement('button');
        btn.className = baseBtn + ' ' + (i === currentPage ? activeBtn : idleBtn);
        btn.textContent = i;
        btn.addEventListener('click', () => {
          currentPage = i;
          render();
          document.getElementById('projectsSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        return btn;
      }

      function makeEllipsis() {
        const span = document.createElement('span');
        span.className = 'font-montserrat text-sm text-gray-400 px-1 select-none leading-[42px]';
        span.textContent = '…';
        return span;
      }

      const prev = document.createElement('button');
      prev.className = baseBtn + ' ' + (currentPage === 1 ? 'opacity-35 cursor-not-allowed border-[#e3d6c5] text-[#2f1200] bg-white' : idleBtn);
      prev.innerHTML = '<i class="ri-arrow-left-s-line text-lg"></i>';
      prev.disabled = currentPage === 1;
      prev.addEventListener('click', () => {
        if (currentPage > 1) {
          currentPage--; render();
          document.getElementById('projectsSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
      container.appendChild(prev);

      const pages = [];
      pages.push(1);
      const rangeStart = Math.max(2, currentPage - 1);
      const rangeEnd = Math.min(totalPages - 1, currentPage + 1);
      if (rangeStart > 2) pages.push('...');
      for (let i = rangeStart; i <= rangeEnd; i++) pages.push(i);
      if (rangeEnd < totalPages - 1) pages.push('...');
      if (totalPages > 1) pages.push(totalPages);

      pages.forEach(p => container.appendChild(p === '...' ? makeEllipsis() : makePageBtn(p)));

      const next = document.createElement('button');
      next.className = baseBtn + ' ' + (currentPage === totalPages ? 'opacity-35 cursor-not-allowed border-[#e3d6c5] text-[#2f1200] bg-white' : idleBtn);
      next.innerHTML = '<i class="ri-arrow-right-s-line text-lg"></i>';
      next.disabled = currentPage === totalPages;
      next.addEventListener('click', () => {
        if (currentPage < totalPages) {
          currentPage++; render();
          document.getElementById('projectsSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
      container.appendChild(next);
    }

    // ===== HOVER (row-grouped expand/shrink — same logic as all-projects.php) =====
    function buildRowGroups() {
      const visible = getAllWrappers().filter(w => w.style.display !== 'none');
      const rows = [];
      visible.forEach(wrapper => {
        const top = wrapper.offsetTop;
        const existingRow = rows.find(row => Math.abs(row.top - top) < 5);
        if (existingRow) existingRow.cards.push(wrapper);
        else rows.push({ top, cards: [wrapper] });
      });
      rows.forEach(row => {
        row.cards.forEach(card => {
          card._rowCards = row.cards;
          card.classList.toggle('solo-row', row.cards.length === 1);
        });
      });
    }

    let hoverTimeout = null;

    function initCardHover() {
      buildRowGroups();
      const visible = getAllWrappers().filter(w => w.style.display !== 'none');

      visible.forEach(wrapper => {
        const fresh = wrapper.cloneNode(true);
        wrapper.parentNode.replaceChild(fresh, wrapper);
      });

      getAllWrappers().filter(w => w.style.display !== 'none').forEach(wrapper => {
        wrapper.addEventListener('mouseenter', function () {
          if (hoverTimeout) clearTimeout(hoverTimeout);
          const rowCards = this._rowCards;
          if (!rowCards) return;
          if (this.classList.contains('is-hovered')) return;

          // Clear EVERY card across the whole grid first (not just this row) so
          // only one card total — anywhere on the page — is ever expanded at once.
          getAllWrappers().forEach(w => w.classList.remove('is-hovered', 'is-shrunk'));

          this.classList.add('is-hovered');
          rowCards.forEach(w => { if (w !== this) w.classList.add('is-shrunk'); });
        });
        wrapper.addEventListener('mouseleave', function (e) {
          const rowCards = this._rowCards;
          if (!rowCards) return;
          const toElement = e.relatedTarget;
          const movingToRowmate = rowCards.some(w => w === toElement || w.contains(toElement));
          if (!movingToRowmate) {
            hoverTimeout = setTimeout(() => {
              rowCards.forEach(w => w.classList.remove('is-hovered', 'is-shrunk'));
            }, 40);
          }
        });
      });

      buildRowGroups();
    }

    window.addEventListener('resize', buildRowGroups);

    // ===== CATEGORY =====
    categoryLinks.forEach(link => {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        categoryLinks.forEach(l => {
          l.classList.remove('bg-[#2f1200]', 'text-white', 'border-[#2f1200]');
          l.classList.add('bg-white', 'text-[#2f1200]', 'border-[#e3d6c5]');
        });
        this.classList.remove('bg-white', 'text-[#2f1200]', 'border-[#e3d6c5]');
        this.classList.add('bg-[#2f1200]', 'text-white', 'border-[#2f1200]');

        currentCategory = this.dataset.category;
        currentPage = 1;
        activeSearch = false;
        searchInput.value = '';
        searchSuggestions.classList.add('hidden');
        titleCategory.textContent = categoryLabels[currentCategory];
        render();

        const newUrl = new URL(window.location);
        newUrl.searchParams.set('category', currentCategory);
        window.history.pushState({}, '', newUrl);
      });
    });

    // ===== SEARCH =====
    searchInput.addEventListener('input', function (e) {
      const searchTerm = e.target.value.toLowerCase().trim();

      if (searchTerm.length === 0) {
        searchSuggestions.classList.add('hidden');
        searchSuggestions.innerHTML = '';
        activeSearch = false;
        getAllWrappers().forEach(w => delete w.dataset.searchMatch);
        currentPage = 1;
        render();
        return;
      }

      const matches = allProjectsData.filter(p =>
        p.title.toLowerCase().includes(searchTerm) ||
        p.location.toLowerCase().includes(searchTerm)
      );

      activeSearch = true;
      getAllWrappers().forEach(w => {
        const isMatch = matches.some(m => m.element === w || (w.querySelector('.title') && w.querySelector('.title').textContent === m.title));
        w.dataset.searchMatch = isMatch ? 'true' : 'false';
      });

      currentPage = 1;
      displaySuggestions(matches, searchTerm);
      render();
    });

    function displaySuggestions(matches, searchTerm) {
      if (matches.length === 0) {
        searchSuggestions.innerHTML = '<div class="p-5 text-center text-gray-400 font-montserrat text-sm">No projects found</div>';
        searchSuggestions.classList.remove('hidden');
        return;
      }
      searchSuggestions.innerHTML = matches.map(project => {
        const categoryLabel = project.category === 'site' ? 'Site Project' : 'Residential Interior';
        return `
      <div class="suggestion-item flex items-center gap-4 px-4 py-3 border-b border-gray-100 last:border-b-0 cursor-pointer hover:bg-[#faf8f6] transition-colors duration-200" data-link="${project.link}">
        <img src="${project.image}" alt="${project.title}" class="w-14 h-14 object-cover rounded-lg flex-shrink-0">
        <div class="flex-1 min-w-0">
          <div class="font-montserrat text-sm text-[#2f1200] mb-1 truncate">${highlightMatch(project.title, searchTerm)}</div>
          <div class="flex items-center gap-1 font-montserrat text-[11px] text-gray-500"><i class="ri-map-pin-line"></i> ${highlightMatch(project.location, searchTerm)}</div>
          <span class="inline-block mt-1.5 font-montserrat text-[9px] font-bold tracking-[1.5px] uppercase text-[#c4905c]">${categoryLabel}</span>
        </div>
      </div>`;
      }).join('');
      searchSuggestions.classList.remove('hidden');
      document.querySelectorAll('.suggestion-item').forEach(item => {
        item.addEventListener('click', function () { window.location.href = this.dataset.link; });
      });
    }

    function highlightMatch(text, searchTerm) {
      return text.replace(new RegExp(`(${searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi'), '<strong>$1</strong>');
    }

    document.addEventListener('click', function (e) {
      if (!searchInput.contains(e.target) && !searchSuggestions.contains(e.target)) {
        searchSuggestions.classList.add('hidden');
      }
    });

    // ===== POPSTATE =====
    window.addEventListener('popstate', function () {
      const urlParams = new URLSearchParams(window.location.search);
      currentCategory = urlParams.get('category') || 'all';
      categoryLinks.forEach(link => {
        const isActive = link.dataset.category === currentCategory;
        link.classList.toggle('bg-[#2f1200]', isActive);
        link.classList.toggle('text-white', isActive);
        link.classList.toggle('border-[#2f1200]', isActive);
        link.classList.toggle('bg-white', !isActive);
        link.classList.toggle('text-[#2f1200]', !isActive);
        link.classList.toggle('border-[#e3d6c5]', !isActive);
      });
      titleCategory.textContent = categoryLabels[currentCategory];
      currentPage = 1;
      render();
    });

    // ===== INIT =====
    document.addEventListener('DOMContentLoaded', function () {
      getAllWrappers().forEach(wrapper => {
        const titleEl = wrapper.querySelector('.title');
        const locationEl = wrapper.querySelector('.location');
        const imgEl = wrapper.querySelector('.proj-default-img');
        const linkEl = wrapper.querySelector('a');
        if (!titleEl || !locationEl || !imgEl || !linkEl) return;
        allProjectsData.push({
          title: titleEl.textContent.trim(),
          location: locationEl.textContent.trim(),
          category: wrapper.dataset.category,
          image: imgEl.src,
          link: linkEl.href,
          element: wrapper
        });
      });

      const urlParams = new URLSearchParams(window.location.search);
      currentCategory = urlParams.get('category') || 'all';
      categoryLinks.forEach(link => {
        const isActive = link.dataset.category === currentCategory;
        link.classList.toggle('bg-[#2f1200]', isActive);
        link.classList.toggle('text-white', isActive);
        link.classList.toggle('border-[#2f1200]', isActive);
        link.classList.toggle('bg-white', !isActive);
        link.classList.toggle('text-[#2f1200]', !isActive);
        link.classList.toggle('border-[#e3d6c5]', !isActive);
      });

      render();
    });
  </script>

</body>

</html>