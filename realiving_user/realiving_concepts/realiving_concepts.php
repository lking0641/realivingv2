<?php
//concept.php
//
// ── CHANGES SA VERSION NA ITO ────────────────────────────────────────────
// 1. Tinanggal yung buong HERO/HEADER section (yung dark image + "Panorama"
//    title). Diretso na agad sa INTRO text yung page ngayon.
// 2. Binalot yung buong visible content (intro → footer) sa isang
//    <div class="main-content"> para tama yung push/offset kapag naka-expand
//    o naka-collapse yung sidebar — dati kasi walang wrapper kaya natatakpan
//    yung left side ng page ng fixed sidebar.
// 3. Idinagdag yung "CONCEPTS PAGE — SIDEBAR OVERRIDE" block sa <style>.
//    Ito yung nag-a-force sa sidebar na laging naka-solid-white dito sa
//    concepts page — pinapatay yung scroll-triggered blurred-hero-image
//    blend/color-swap na galing sa realiving_sidebar.php, dahil yun ay
//    designed lang talaga para sa realiving_main.php (na may rotating hero
//    banner na pinagbabatayan ng --hero-bg-image variable). Wala namang
//    hero image dito sa concepts page kaya kung hindi natin i-o-override,
//    plain dark transparent lang ang lalabas na sidebar hangga't hindi
//    naka-scroll.
// ──────────────────────────────────────────────────────────────────────────
//
// NOTE: Kung hindi mo pa na-i-include yung realiving_sidebar.php sa
// header.php mo (o kahit saan bago ang <body> content dito), i-uncomment
// yung linya sa ibaba pagkatapos mabuksan ang <body> tag:
//     <?php include $includes['sidebar'];
// Kung naka-include na siya sa header.php mo, huwag nang idagdag ulit dito
// para hindi mag-duplicate.

session_name("Realivinguser");
session_start();
include $includes['connection'];

// Check session success from redirect
$inquiry_success = false;
if (isset($_SESSION['concept_success'])) {
    $inquiry_success = true;
    unset($_SESSION['concept_success']);
}

// Restore validation errors if redirected back after failed submission
$inquiry_errors = $_SESSION['concept_errors'] ?? [];
$old_input = $_SESSION['concept_old_input'] ?? [];
unset($_SESSION['concept_errors'], $_SESSION['concept_old_input']);

include $includes['header'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Realiving Design Center</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,300;0,400;0,500;0,600;1,400;1,500&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="<?= BASE_ASSET ?>assets/css/output.css">

    <style>
        /* ===== THE STYLE LEDGER — page-specific tokens & mechanics =====
           Everything visual lives in Tailwind utility classes in the markup
           below. What's kept here as raw CSS are only the pieces Tailwind
           utilities genuinely can't express: custom font-family helpers (no
           access to this project's tailwind.config to register new font
           families), keyframe animation, the literal class names the page's
           JS toggles at runtime (.active / .reverse / .concept-text /
           .reverse-text / .carousel-slide.active), and the small
           registration-mark signature motif reused on every media panel. */

        .font-fraunces { font-family: 'Fraunces', serif; }
        .font-worksans { font-family: 'Work Sans', sans-serif; }
        .font-plex     { font-family: 'IBM Plex Mono', monospace; }

        .no-scrollbar { scrollbar-width: none; -ms-overflow-style: none; }
        .no-scrollbar::-webkit-scrollbar { display: none; }

        /* JS does row.classList.toggle('reverse', isReversed) — a plain
           utility can't be toggled by a literal classList call, so this
           small rule (higher specificity than the lg:flex-row utility)
           does the actual left/right flip at desktop widths. */
        @media (min-width: 1024px) {
            .concept-row.reverse { flex-direction: row-reverse; }
        }

        /* JS swaps these two literal class names per row (and the initial
           PHP render also outputs them together on odd rows, same as the
           original template) — kept as real CSS so that behavior carries
           over unchanged. */
        .concept-text, .reverse-text {
            position: relative;
            overflow: hidden;
        }
        .concept-text > *, .reverse-text > * { position: relative; z-index: 1; }

        .concept-text .index-ghost { left: -0.25rem; }
        .concept-text .row-meta { justify-content: flex-start; }

        .reverse-text { text-align: right; }
        .reverse-text .index-ghost { left: auto; right: -0.25rem; }
        .reverse-text .row-meta { justify-content: flex-end; }
        .reverse-text .cta-button,
        .reverse-text .view-category-btn { align-self: flex-end; }

        @media (max-width: 1023px) {
            .concept-text, .reverse-text { text-align: left; }
            .reverse-text .index-ghost { left: -0.25rem; right: auto; }
            .reverse-text .row-meta { justify-content: flex-start; }
            .reverse-text .cta-button,
            .reverse-text .view-category-btn { align-self: flex-start; }
        }

        /* Ambient ink-stain wash, scroll-triggered via IntersectionObserver
           adding/removing .in-view — a moving radial gradient can't be a
           Tailwind utility, so it stays as a keyframe animation. */
        .concept-text::before, .reverse-text::before {
            content: '';
            position: absolute;
            inset: -50%;
            background: radial-gradient(circle, rgba(168,77,43,0.16), transparent 70%);
            opacity: 0;
            transition: opacity 1.2s ease;
            z-index: 0;
            pointer-events: none;
        }
        .reverse-text::before {
            background: radial-gradient(circle, rgba(160,129,80,0.2), transparent 70%);
        }
        .concept-text.in-view::before, .reverse-text.in-view::before {
            opacity: 1;
            animation: inkDrift 14s linear infinite;
        }
        @keyframes inkDrift {
            0% { transform: translate(0,0); }
            50% { transform: translate(6%,6%); }
            100% { transform: translate(0,0); }
        }

        /* Category tab underline — JS toggles the literal .active class */
        .category-btn { position: relative; }
        .category-btn .tab-underline {
            position: absolute; left: 0; right: 0; bottom: -1px; height: 2px;
            background: #A84D2B; transform: scaleX(0); transform-origin: left;
            transition: transform .3s ease;
        }
        .category-btn:hover .tab-underline,
        .category-btn.active .tab-underline { transform: scaleX(1); }
        .category-btn.active { color: #211A14; }

        /* Registration-mark corners — the page's one signature motif,
           reused on every media panel and the carousel viewing window. */
        .reg-marks { position: relative; }
        .reg-marks span {
            position: absolute; width: 14px; height: 14px; border-color: #A08150;
            pointer-events: none;
        }
        .reg-marks span:nth-child(1) { top: 10px; left: 10px; border-top: 2px solid; border-left: 2px solid; }
        .reg-marks span:nth-child(2) { top: 10px; right: 10px; border-top: 2px solid; border-right: 2px solid; }
        .reg-marks span:nth-child(3) { bottom: 10px; left: 10px; border-bottom: 2px solid; border-left: 2px solid; }
        .reg-marks span:nth-child(4) { bottom: 10px; right: 10px; border-bottom: 2px solid; border-right: 2px solid; }

        /* Blocks clicks on the embedded viewer's own built-in side controls
           while keeping the logo badge legible — same click-guard as the
           original template, resized per breakpoint. */
        .concept-media::after {
            content: ''; position: absolute; top: 0; right: 0; width: 75px; height: 100%;
            z-index: 14; background: transparent; pointer-events: auto; cursor: not-allowed;
        }
        @media (max-width: 992px) { .concept-media::after { width: 65px; } }
        @media (max-width: 768px) { .concept-media::after { width: 55px; } }
        @media (max-width: 576px) { .concept-media::after { width: 50px; } }

        .logo-overlay {
            background: linear-gradient(135deg, rgba(247,242,233,0.55), rgba(247,242,233,0.15));
            backdrop-filter: blur(24px) saturate(160%);
            -webkit-backdrop-filter: blur(24px) saturate(160%);
            border-bottom: 1px solid rgba(160,129,80,0.4);
            border-right: 1px solid rgba(160,129,80,0.4);
        }

        /* JS toggles .active on carousel-slide directly by class name */
        .carousel-slide { opacity: .35; transition: opacity .5s; }
        .carousel-slide.active { opacity: 1; }

        /* ═══════════════════════════════════════════════════════════════
           CONCEPTS PAGE — SIDEBAR OVERRIDE
           realiving_sidebar.php has a scroll-triggered "transparent/blurred
           hero-image → solid white" transition, keyed off a --hero-bg-image
           CSS variable that realiving_main.php sets from its own rotating
           hero slides. This page (concepts) has no hero banner to sync
           against, so we lock the sidebar into its solid "scrolled" look
           permanently, and hide the blurred-image/dark-overlay pseudo
           elements entirely. This only affects the sidebar's presentation
           on THIS page — realiving_main.php is untouched.
           ═══════════════════════════════════════════════════════════════ */
        #sidebar::before,
        #sidebar::after {
            display: none !important;
        }
        #sidebar {
            background: #ffffff !important;
            border-right: 1px solid rgba(0,0,0,0.08) !important;
            box-shadow: 2px 0 16px rgba(0,0,0,0.05) !important;
        }
        #sidebar .sb-header,
        #sidebar .sb-divider,
        #sidebar .sb-footer {
            border-color: rgba(0,0,0,0.08) !important;
        }
        #sidebar .sb-logo-mark { border-color: rgba(0,0,0,0.1) !important; }
        #sidebar .sb-label { color: rgba(0,0,0,0.45) !important; }
        #sidebar .sb-collapse-btn { border-color: rgba(0,0,0,0.2) !important; color: var(--sb-accent) !important; }
        #sidebar .sb-collapse-btn:hover { background: rgba(0,0,0,0.05) !important; }
        #sidebar .sb-link { color: #2b2b2b !important; }
        #sidebar .sb-link i { color: #8a8a8a !important; }
        #sidebar .sb-link:hover { background: rgba(47,18,0,0.06) !important; }
        #sidebar .sb-link:hover i { color: var(--sb-accent) !important; }
        #sidebar .sb-link.active { background: rgba(47,18,0,0.08) !important; }
        #sidebar .sb-book-btn { border-color: var(--sb-accent) !important; color: var(--sb-accent) !important; }
        #sidebar .sb-book-btn:hover { background: var(--sb-accent) !important; color: #fff !important; }
        /* force the "dark logo" variants on (the ones meant for the solid
           white state) and hide the white-on-transparent variants */
        #sidebar #sbLogoWhite, #sidebar #sbMarkWhite { display: none !important; }
        #sidebar #sbLogoDark  { display: block !important; }
        #sidebar #sbMarkDark  { display: none !important; } /* collapsed-state mark toggles via JS, dark version shown when collapsed below */
        #sidebar.collapsed #sbMarkDark { display: block !important; }

        /* Self-contained content wrapper — margin-left/width dito ay
           ise-set ng JS sa ibaba base sa ACTUAL measured width ng
           #sidebar, hindi CSS variable. Yung margin-left:0 dito ay safe
           fallback lang habang hindi pa tumatakbo yung JS (o kung walang
           #sidebar sa page for whatever reason). */
        #conceptPageWrap {
            margin-left: 0;
            width: 100%;
            box-sizing: border-box;
            transition: margin-left .3s ease, width .3s ease;
            overflow-x: clip;
        }
    </style>
</head>

<body class="bg-[#F7F2E9]">
    <script>
        // Handle URL parameter to scroll to specific style
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const styleId = urlParams.get('style');

            if (styleId) {
                setTimeout(() => {
                    // Find the target row
                    const allRows = document.querySelectorAll('.concept-row');
                    let targetRow = null;

                    allRows.forEach(row => {
                        const ctaButton = row.querySelector('.cta-button');
                        if (ctaButton && ctaButton.getAttribute('data-concept-id') == styleId) {
                            targetRow = row;
                        }
                    });

                    if (targetRow) {
                        const catId = targetRow.getAttribute('data-category');
                        const catName = targetRow.querySelector('.category-label')
                            ? targetRow.querySelector('.category-label').textContent.trim()
                            : '';

                        // If this row is hidden (it's an "extra" style), show its full category first
                        if (targetRow.classList.contains('hidden')) {
                            // Trigger the category filter so all rows in that category are visible
                            const catBtns = document.querySelectorAll('.category-btn');
                            catBtns.forEach(b => {
                                b.classList.toggle('active', b.getAttribute('data-category') === catId);
                            });

                            // Re-use the applyFilter function defined below in DOMContentLoaded
                            // We call it manually here since applyFilter is scoped inside DOMContentLoaded
                            let visibleIndex = 0;
                            allRows.forEach(row => {
                                const rowCat = row.getAttribute('data-category');
                                const shouldShow = (rowCat === catId);
                                if (shouldShow) {
                                    row.classList.remove('hidden');
                                    const isReversed = visibleIndex % 2 !== 0;
                                    row.classList.toggle('reverse', isReversed);
                                    const textEl = row.querySelector('.concept-text, .reverse-text');
                                    if (textEl) {
                                        if (isReversed) {
                                            textEl.classList.remove('concept-text');
                                            textEl.classList.add('reverse-text');
                                        } else {
                                            textEl.classList.remove('reverse-text');
                                            textEl.classList.add('concept-text');
                                        }
                                    }
                                    visibleIndex++;
                                } else {
                                    row.classList.add('hidden');
                                }
                            });

                            // Show the back bar
                            const backBar = document.getElementById('backBar');
                            const barLabel = document.getElementById('backBarLabel');
                            if (backBar) backBar.style.display = 'flex';
                            if (barLabel) barLabel.textContent = 'Showing: All ' + catName + ' Styles';
                        }

                        // Now scroll and highlight
                        setTimeout(() => {
                            targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            targetRow.style.transition = 'all 0.5s ease';
                            targetRow.style.boxShadow = '0 0 30px rgba(168, 77, 43, 0.45)';
                            targetRow.style.transform = 'scale(1.02)';
                            setTimeout(() => {
                                targetRow.style.boxShadow = '';
                                targetRow.style.transform = '';
                            }, 3000);
                        }, 300); // small extra delay after unhiding
                    }
                }, 500);
            }
        });
    </script>

    <?php // include $includes['sidebar']; // uncomment kung hindi pa naka-include sa header.php mo ?>

    <!-- ═══════════════════════════════════════════════════════════════
       SARILI/SELF-CONTAINED na content wrapper — sinadyang HINDI na
       gumagamit ng ".main-content" class. Dati, umaasa yun sa shared na
       CSS var/JS na nasa realiving_sidebar.php (na posibleng may sarili
       nang ".main-content" wrapper si header.php/footer.php mo para sa
       ibang parte ng site) — kaya kung mayroon na palang existing wrapper
       doon, NAGDOBLE ang shrink/offset (nested calc), at yun malamang ang
       sanhi ng pagka-squish/broken ng layout sa mobile.
       Ngayon, ang #conceptPageWrap ay may sariling JS (nasa ibaba, bago
       mag-close ang </body>) na direktang sumusukat sa ACTUAL rendered
       width ng #sidebar sa real time — walang pag-asa sa CSS variable
       timing o sa ibang shared wrapper, kaya hindi na ito magco-conflict
       kahit ano pa ang meron ka sa header.php/footer.php.
       ═══════════════════════════════════════════════════════════════ -->
    <div id="conceptPageWrap">

        <?php
        // Get header settings (subtitle text lang ang ginagamit ngayon,
        // dahil tinanggal na yung hero image/title block sa itaas ng page)
        $header_result = $conn->query("SELECT * FROM concept_header LIMIT 1");
        $header = $header_result->fetch_assoc();
        $header_subtitle = $header ? $header['subtitle'] : 'A collection of curated cabinet styles blending form and function, crafted to elevate your interiors with personality and precision.';
        ?>

        <!-- ═══════════════════════════════
           INTRO
        ═══════════════════════════════ -->
        <section class="bg-[#ECE4D6] pt-16 pb-16 sm:pt-20 sm:pb-20 px-6">
            <div class="max-w-2xl mx-auto text-center">
                <div class="w-16 h-px bg-[#CBBFA9] mx-auto mb-8"></div>
                <span class="font-plex text-[10px] sm:text-[11px] tracking-[5px] uppercase text-[#A08150] block mb-4">
                    The Style Collection
                </span>
                <p class="font-worksans text-[15px] sm:text-base text-[#211A14]/75 leading-relaxed">
                    <?php echo htmlspecialchars($header_subtitle); ?>
                </p>
                <div class="w-16 h-px bg-[#CBBFA9] mx-auto mt-8"></div>
            </div>
        </section>
        <!-- ═══════════════════════════════
           END INTRO
        ═══════════════════════════════ -->

        <!-- ═══════════════════════════════
           CATEGORY INDEX TABS
        ═══════════════════════════════ -->
        <section class="bg-[#ECE4D6] border-y border-[#CBBFA9]">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 flex items-center gap-3">
                <button class="filter-arrow w-9 h-9 flex-shrink-0 border border-[#211A14]/25 text-[#211A14] hover:border-[#A84D2B] hover:text-[#A84D2B] transition-colors duration-300" id="scrollLeft">
                    <span class="flex items-center justify-center w-full h-full">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none">
                            <path d="M12.5 15L7.5 10L12.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                </button>

                <div class="flex-1 overflow-hidden">
                    <div class="no-scrollbar flex gap-8 overflow-x-auto py-1" id="categoryFilters">
                        <button class="category-btn active pb-2 font-plex text-[11px] tracking-[2.5px] uppercase text-[#211A14]/60 hover:text-[#A84D2B] whitespace-nowrap transition-colors duration-300" data-category="all">
                            All Styles
                            <span class="tab-underline"></span>
                        </button>
                        <?php
                        // Get unique categories from styles
                        $cat_btn_result = $conn->query("SELECT DISTINCT cc.id, cc.name FROM concept_categories cc 
                                                    INNER JOIN concept_styles cs ON cs.category_id = cc.id 
                                                    ORDER BY cc.display_order ASC, cc.name ASC");
                        while ($cat_btn = $cat_btn_result->fetch_assoc()):
                            ?>
                            <button class="category-btn pb-2 font-plex text-[11px] tracking-[2.5px] uppercase text-[#211A14]/60 hover:text-[#A84D2B] whitespace-nowrap transition-colors duration-300" data-category="<?php echo $cat_btn['id']; ?>">
                                <?php echo htmlspecialchars($cat_btn['name']); ?>
                                <span class="tab-underline"></span>
                            </button>
                        <?php endwhile; ?>
                    </div>
                </div>

                <button class="filter-arrow w-9 h-9 flex-shrink-0 border border-[#211A14]/25 text-[#211A14] hover:border-[#A84D2B] hover:text-[#A84D2B] transition-colors duration-300" id="scrollRight">
                    <span class="flex items-center justify-center w-full h-full">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none">
                            <path d="M7.5 15L12.5 10L7.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                </button>
            </div>
        </section>
        <!-- ═══════════════════════════════
           END CATEGORY INDEX TABS
        ═══════════════════════════════ -->

        <!-- Back to All Styles bar (hidden until a category filter is active) -->
        <div class="flex items-center gap-6 bg-[#211A14] text-[#F7F2E9] px-6 py-3" id="backBar" style="display:none;">
            <button class="font-plex text-[11px] tracking-[2px] uppercase border border-white/30 px-4 py-2 hover:bg-white hover:text-[#211A14] transition-colors duration-300" id="backAllBtn">
                ← Back to All Styles
            </button>
            <span class="font-worksans text-[13px] italic text-white/70" id="backBarLabel"></span>
        </div>

        <main class="bg-[#F7F2E9] pt-6 pb-4">
            <?php
            // Get all styles grouped by category - always full-width alternating layout
            $styles_result = $conn->query("SELECT cs.*, cc.name as category_name, cc.id as category_id 
                                       FROM concept_styles cs 
                                       LEFT JOIN concept_categories cc ON cs.category_id = cc.id 
                                       ORDER BY cs.category_id ASC, cs.display_order ASC");

            $all_styles = [];
            while ($row = $styles_result->fetch_assoc()) {
                $all_styles[] = $row;
            }

            // Group by category, pick only the FIRST item per category for default "All Styles" view
            $seen_categories = [];
            $default_styles = []; // one per category
            $extra_styles = []; // the rest

            foreach ($all_styles as $style) {
                $cat_id = $style['category_id'] ?? 'none';
                if (!in_array($cat_id, $seen_categories)) {
                    $seen_categories[] = $cat_id;
                    $default_styles[] = $style;
                } else {
                    $extra_styles[] = $style;
                }
            }

            // Merge: default first, then extras (extras hidden by default)
            $ordered_styles = array_merge($default_styles, $extra_styles);

            // Render all as full-width left/right alternating rows
            $row_index = 0;
            foreach ($ordered_styles as $style):
                $is_default = in_array($style, $default_styles);
                $cat_id = $style['category_id'] ?? 'none';
                $cat_name = htmlspecialchars($style['category_name'] ?? '');
                $reverse_class = ($row_index % 2 !== 0) ? 'reverse' : '';
                $reverse_text_class = ($row_index % 2 !== 0) ? 'reverse-text' : '';
                $hidden_class = $is_default ? '' : 'extra-style hidden';
                $index_label = str_pad($row_index + 1, 2, '0', STR_PAD_LEFT);
                $row_index++;
                ?>
                <!-- ═══════════════════════════════
                   STYLE ENTRY N°<?php echo $index_label; ?>
                ═══════════════════════════════ -->
                <section class="concept-row <?php echo $reverse_class; ?> <?php echo $hidden_class; ?> flex flex-col lg:flex-row items-stretch mb-10 sm:mb-14"
                    data-category="<?php echo $cat_id; ?>" data-is-default="<?php echo $is_default ? '1' : '0'; ?>">

                    <!-- Media panel -->
                    <div class="concept-media reg-marks relative w-full lg:w-1/2 min-h-[300px] lg:min-h-[560px] overflow-hidden bg-[#211A14] p-3">
                        <div class="relative w-full h-full border border-[#CBBFA9]/40 overflow-hidden">
                            <iframe data-src="<?php echo htmlspecialchars($style['iframe_url']); ?>" src="about:blank"
                                frameborder="0" allowfullscreen style="width:100%;height:100%;border:none;display:block;"
                                class="lazy-iframe" allow="autoplay 'none'" muted></iframe>
                            <div class="logo-overlay absolute top-0 left-0 w-[110px] h-[70px] sm:h-[75px] flex items-center justify-center p-2.5 z-[15] pointer-events-none">
                                <img src="<?= CLIENT_ASSET ?>/images/logo/logowhite.png" alt="Realiving Logo" class="max-w-full max-h-full object-contain opacity-90">
                            </div>
                        </div>
                        <span></span><span></span><span></span><span></span>
                    </div>

                    <!-- Text panel -->
                    <div class="<?php echo trim('concept-text ' . $reverse_text_class); ?> w-full lg:w-1/2 flex flex-col justify-center px-6 py-12 sm:px-10 sm:py-16 lg:px-16">

                        <span class="index-ghost absolute -top-4 lg:-top-8 font-fraunces italic font-light text-[5rem] sm:text-[7rem] leading-none text-[#A84D2B]/10 select-none pointer-events-none">
                            N°<?php echo $index_label; ?>
                        </span>

                        <div class="row-meta flex items-center gap-3 mb-5">
                            <?php if ($style['category_name']): ?>
                                <span class="category-label font-plex text-[10px] tracking-[2.5px] uppercase text-[#A08150] border-l-2 border-[#A08150] pl-3"><?php echo $cat_name; ?></span>
                            <?php endif; ?>
                            <span class="font-plex text-[10px] tracking-[2px] text-[#211A14]/35">N°<?php echo $index_label; ?></span>
                        </div>

                        <h2 class="font-fraunces text-[#211A14] text-[2.2rem] sm:text-5xl leading-[1.05] mb-5">
                            <?php echo htmlspecialchars($style['title']); ?>
                        </h2>
                        <p class="font-worksans text-[15px] text-[#211A14]/65 leading-relaxed mb-9 max-w-md">
                            <?php echo htmlspecialchars($style['description']); ?>
                        </p>

                        <!-- Customize button -->
                        <button class="cta-button inline-flex items-center gap-2 self-start font-plex text-[11px] tracking-[2px] uppercase text-[#211A14] border-b-2 border-[#211A14] pb-1.5 hover:text-[#A84D2B] hover:border-[#A84D2B] transition-colors duration-300"
                            data-concept-id="<?php echo $style['id']; ?>"
                            data-concept-title="<?php echo htmlspecialchars($style['title']); ?>"
                            data-category-name="<?php echo $cat_name; ?>">
                            Customize Your Cabinet in This Style →
                        </button>

                        <!-- View All [Category] button — only on the default (first) row of each category -->
                        <?php if ($is_default && $style['category_name']): ?>
                            <button class="view-category-btn inline-flex items-center gap-2 self-start mt-5 font-plex text-[11px] tracking-[2px] uppercase text-[#A08150] hover:text-[#A84D2B] transition-colors duration-300"
                                data-category="<?php echo $cat_id; ?>" data-category-name="<?php echo $cat_name; ?>">
                                View All <?php echo $cat_name; ?> Styles
                                <span class="btn-arrow transition-transform duration-300">→</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </main>

        <?php
        include $includes['banner'];
        ?>

        <!-- ═══════════════════════════════
           SHOWROOM CAROUSEL
        ═══════════════════════════════ -->
        <section class="relative w-full overflow-hidden py-14 sm:py-20 bg-[#F7F2E9]">
            <div class="max-w-5xl mx-auto px-4 sm:px-8 mb-8 sm:mb-10 text-center">
                <span class="font-plex text-[10px] tracking-[3px] uppercase text-[#A08150]">The Showroom Floor</span>
                <h2 class="font-fraunces italic text-[#211A14] text-3xl sm:text-4xl mt-2">A Closer Look</h2>
            </div>

            <div class="reg-marks relative max-w-6xl mx-auto px-1">
                <span></span><span></span><span></span><span></span>

                <div class="carousel-track flex transition-transform duration-500 ease-out border border-[#CBBFA9]">
                    <?php
                    // Get carousel images
                    $carousel_result = $conn->query("SELECT * FROM concept_carousel ORDER BY display_order ASC");
                    $carousel_images = [];
                    while ($img = $carousel_result->fetch_assoc()) {
                        $carousel_images[] = $img;
                    }

                    // Duplicate first and last for seamless loop
                    if (count($carousel_images) > 0) {
                        echo '<img src="' . CLIENT_ASSET . '/' . htmlspecialchars($carousel_images[count($carousel_images) - 1]['image_path']) . '" 
          class="carousel-slide flex-none w-full md:w-3/5 mx-0 md:mx-2.5 object-cover max-h-[35vh] md:max-h-[45vh] lg:max-h-[70vh]" alt="Slide">';

                        foreach ($carousel_images as $img) {
                            echo '<img src="' . CLIENT_ASSET . '/' . htmlspecialchars($img['image_path']) . '" 
              class="carousel-slide flex-none w-full md:w-3/5 mx-0 md:mx-2.5 object-cover max-h-[35vh] md:max-h-[45vh] lg:max-h-[70vh]" alt="Slide">';
                        }

                        echo '<img src="' . CLIENT_ASSET . '/' . htmlspecialchars($carousel_images[0]['image_path']) . '" 
          class="carousel-slide flex-none w-full md:w-3/5 mx-0 md:mx-2.5 object-cover max-h-[35vh] md:max-h-[45vh] lg:max-h-[70vh]" alt="Slide">';
                    }
                    ?>
                </div>

                <!-- Left Arrow -->
                <button class="carousel-arrow left absolute left-2 sm:left-4 top-1/2 -translate-y-1/2 w-9 h-9 sm:w-11 sm:h-11 border border-white/40 bg-[#211A14]/70 text-white z-10 select-none cursor-pointer"
                    onclick="prevSlide()">
                    <span class="flex items-center justify-center w-full h-full text-lg">&#10094;</span>
                </button>

                <!-- Right Arrow -->
                <button class="carousel-arrow right absolute right-2 sm:right-4 top-1/2 -translate-y-1/2 w-9 h-9 sm:w-11 sm:h-11 border border-white/40 bg-[#211A14]/70 text-white z-10 select-none cursor-pointer"
                    onclick="nextSlide()">
                    <span class="flex items-center justify-center w-full h-full text-lg">&#10095;</span>
                </button>
            </div>
        </section>
        <!-- ═══════════════════════════════
           END SHOWROOM CAROUSEL
        ═══════════════════════════════ -->

        <?php
        include $includes['banner2'];
        ?>

        <?php include $includes['inquiry']; ?>

        <?php include $includes['footer']; ?>

    </div> <!-- /#conceptPageWrap -->

    <script>
        // Scroll-triggered animation for the moving gradient backgrounds
        const textObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in-view');
                } else {
                    entry.target.classList.remove('in-view');
                }
            });
        }, {
            threshold: 0.3
        });

        document.querySelectorAll('.concept-text, .reverse-text').forEach(textSection => {
            textObserver.observe(textSection);
        });

        // Scroll-triggered animation setup
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Initially hide items and observe them
        document.querySelectorAll('.concept-item').forEach((item, index) => {
            item.style.opacity = '0';
            item.style.transform = 'translateY(20px)';
            item.style.transition = `opacity 0.6s ease ${index * 0.1}s, transform 0.6s ease ${index * 0.1}s`;
            observer.observe(item);
        });

        //carousel
        document.addEventListener('DOMContentLoaded', () => {
            const track = document.querySelector('.carousel-track');
            const slides = document.querySelectorAll('.carousel-slide');
            let currentIndex = 1; // start from real first slide

            function updateCarousel() {
                const slideWidth = slides[0].offsetWidth + 20; // slide + margin
                track.style.transform = `translateX(calc(50% - ${(currentIndex + 0.5) * slideWidth}px))`;
                slides.forEach((slide, i) => {
                    slide.classList.toggle('active', i === currentIndex);
                });
            }

            function nextSlide() {
                currentIndex++;
                track.style.transition = 'transform 0.5s ease';
                updateCarousel();
                if (currentIndex === slides.length - 1) {
                    setTimeout(() => {
                        track.style.transition = 'none';
                        currentIndex = 1;
                        updateCarousel();
                    }, 500);
                }
            }

            function prevSlide() {
                currentIndex--;
                track.style.transition = 'transform 0.5s ease';
                updateCarousel();
                if (currentIndex === 0) {
                    setTimeout(() => {
                        track.style.transition = 'none';
                        currentIndex = slides.length - 2;
                        updateCarousel();
                    }, 500);
                }
            }

            window.nextSlide = nextSlide;
            window.prevSlide = prevSlide;

            updateCarousel();
            setInterval(nextSlide, 4000);
        });

        //PANORAMA
        window.addEventListener('scroll', () => {
            const section = document.querySelector('.panorama-section');
            if (section) {
                const container = section.querySelector('.panorama-container');
                const rect = section.getBoundingClientRect();
                if (rect.top < window.innerHeight && rect.bottom > 0) {
                    const scrollProgress = 1 - rect.bottom / (window.innerHeight + rect.height);
                    const maxScroll = container.offsetWidth - window.innerWidth;
                    const translateX = Math.min(Math.max(scrollProgress * maxScroll, 0), maxScroll);
                    container.style.transform = `translateX(-${translateX}px)`;
                }
            }
        });

        // ── CATEGORY FILTER LOGIC ──────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            const allRows = document.querySelectorAll('.concept-row');
            const backBar = document.getElementById('backBar');
            const backBtn = document.getElementById('backAllBtn');
            const barLabel = document.getElementById('backBarLabel');
            const catBtns = document.querySelectorAll('.category-btn');
            const filterScroll = document.getElementById('categoryFilters');
            const scrollLeftBtn = document.getElementById('scrollLeft');
            const scrollRightBtn = document.getElementById('scrollRight');

            // ── Core: apply a filter by category id (or 'all' for default view) ──
            function applyFilter(catId, catName, scrollToTop) {
                let visibleIndex = 0;

                allRows.forEach(row => {
                    const rowCat = row.getAttribute('data-category');
                    const isDefault = row.getAttribute('data-is-default') === '1';

                    let shouldShow = false;
                    if (catId === 'all') {
                        shouldShow = isDefault; // only first-of-each-category
                    } else {
                        shouldShow = (rowCat === catId); // all rows in that category
                    }

                    if (shouldShow) {
                        row.classList.remove('hidden');
                        // Re-apply clean left/right alternation
                        const isReversed = visibleIndex % 2 !== 0;
                        row.classList.toggle('reverse', isReversed);

                        const textEl = row.querySelector('.concept-text, .reverse-text');
                        if (textEl) {
                            if (isReversed) {
                                textEl.classList.remove('concept-text');
                                textEl.classList.add('reverse-text');
                            } else {
                                textEl.classList.remove('reverse-text');
                                textEl.classList.add('concept-text');
                            }
                        }
                        visibleIndex++;
                    } else {
                        row.classList.add('hidden');
                        // Unload iframes in hidden rows immediately
                        row.querySelectorAll('.lazy-iframe').forEach(iframe => {
                            if (iframe.src !== 'about:blank') {
                                iframe.dataset.src = iframe.src;
                                iframe.src = 'about:blank';
                            }
                        });
                    }
                });

                // Back bar visibility
                if (catId === 'all') {
                    backBar.style.display = 'none';
                } else {
                    backBar.style.display = 'flex';
                    barLabel.textContent = 'Showing: All ' + catName + ' Styles';
                }

                if (scrollToTop) {
                    const mainEl = document.querySelector('main');
                    if (mainEl) mainEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }

            // ── Top filter buttons ──
            catBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    catBtns.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');

                    const catId = btn.getAttribute('data-category');
                    const catName = btn.textContent.trim();
                    applyFilter(catId, catName, true);
                });
            });

            // ── "View All [Category]" inline button ──
            document.querySelectorAll('.view-category-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const catId = btn.getAttribute('data-category');
                    const catName = btn.getAttribute('data-category-name');

                    // Sync the top filter buttons
                    catBtns.forEach(b => {
                        b.classList.toggle('active', b.getAttribute('data-category') === catId);
                    });

                    applyFilter(catId, catName, false);

                    // Scroll to first visible row smoothly
                    const firstVisible = document.querySelector('.concept-row:not(.hidden)');
                    if (firstVisible) firstVisible.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });

            // ── Back to All Styles ──
            backBtn.addEventListener('click', () => {
                catBtns.forEach(b => {
                    b.classList.toggle('active', b.getAttribute('data-category') === 'all');
                });
                applyFilter('all', '', true);
            });

            // ── Scroll arrows for filter bar ──
            if (scrollLeftBtn && scrollRightBtn && filterScroll) {
                scrollLeftBtn.addEventListener('click', () => {
                    filterScroll.scrollBy({ left: -200, behavior: 'smooth' });
                });
                scrollRightBtn.addEventListener('click', () => {
                    filterScroll.scrollBy({ left: 200, behavior: 'smooth' });
                });

                function updateArrows() {
                    const atStart = filterScroll.scrollLeft <= 0;
                    const atEnd = filterScroll.scrollLeft >= filterScroll.scrollWidth - filterScroll.clientWidth - 1;
                    scrollLeftBtn.classList.toggle('hidden', atStart);
                    scrollRightBtn.classList.toggle('hidden', atEnd);
                }

                filterScroll.addEventListener('scroll', updateArrows);
                window.addEventListener('resize', updateArrows);
                updateArrows();
            }

            // ── LAZY LOAD IFRAMES via IntersectionObserver ──
            const iframeObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    const iframe = entry.target;
                    if (entry.isIntersecting) {
                        // Load iframe when visible
                        if (iframe.dataset.src && iframe.src !== iframe.dataset.src) {
                            iframe.src = iframe.dataset.src;
                        }
                    } else {
                        // Unload iframe when out of view to free memory
                        if (iframe.src !== 'about:blank') {
                            iframe.dataset.src = iframe.src; // save it
                            iframe.src = 'about:blank';
                        }
                    }
                });
            }, {
                rootMargin: '200px 0px', // start loading 200px before visible
                threshold: 0.01
            });

            document.querySelectorAll('.lazy-iframe').forEach(iframe => {
                iframeObserver.observe(iframe);
            });
        });
    </script>

    <script>
        // ── SELF-CONTAINED SIDEBAR OFFSET (concepts page only) ──────────
        // Hindi na ito umaasa sa .main-content class/JS na nasa
        // realiving_sidebar.php. Direktang sinusukat nito yung totoong
        // (rendered) width ng #sidebar sa mismong sandaling iyon —
        // gumagana ito kahit expanded/collapsed, desktop/mobile, at kahit
        // kailan pa mag-toggle yung collapse button, dahil naka-observe
        // ito sa class changes ng sidebar (.collapsed toggling).
        (function () {
            function sync() {
                const wrap = document.getElementById('conceptPageWrap');
                const sidebar = document.getElementById('sidebar');
                if (!wrap) return;

                if (!sidebar) {
                    // walang sidebar sa page na ito — walang dapat i-offset
                    wrap.style.marginLeft = '0';
                    wrap.style.width = '100%';
                    return;
                }

                const w = Math.round(sidebar.getBoundingClientRect().width);
                wrap.style.marginLeft = w + 'px';
                wrap.style.width = `calc(100% - ${w}px)`;
            }

            function init() {
                sync();

                // Update kapag nag-resize ang window (debounced)
                let resizeTimer;
                window.addEventListener('resize', function () {
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(sync, 100);
                });

                // Update kapag nag-collapse/expand yung sidebar
                // (yung button doon ay nagto-toggle ng "collapsed" class)
                const sidebar = document.getElementById('sidebar');
                if (sidebar && window.MutationObserver) {
                    const mo = new MutationObserver(sync);
                    mo.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
                }

                // Extra safety: minsan may transition/animation ang sidebar
                // width kaya sinusync ulit pagkatapos ng ~350ms
                sidebar && sidebar.addEventListener('transitionend', sync);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
    </script>

    <?php if ($inquiry_success): ?>
        <script>
            window.addEventListener('load', function () {
                const toast = document.createElement('div');
                toast.id = 'successToast';
                toast.innerHTML = '✓ &nbsp; Thank you! We\'ll contact you soon.';
                toast.style.cssText = `
                position: fixed;
                bottom: 30px;
                right: 30px;
                z-index: 99999;
                background: #155724;
                color: white;
                padding: 18px 28px;
                border-radius: 8px;
                font-family: 'Work Sans', sans-serif;
                font-size: 14px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.35);
                display: flex;
                align-items: center;
                gap: 10px;
                opacity: 0;
                transition: opacity 0.4s ease;
            `;
                document.body.appendChild(toast);

                // Fade in
                setTimeout(() => { toast.style.opacity = '1'; }, 100);

                // Fade out after 4 seconds
                setTimeout(() => {
                    toast.style.opacity = '0';
                    setTimeout(() => toast.remove(), 500);
                }, 4000);
            });
        </script>
    <?php endif; ?>
</body>

</html>