    <?php
    //concept.php
    session_name("Realivinguser");
    session_start();
    include $includes['connection'];

    $inquiry_success = false;
    if (isset($_SESSION['concept_success'])) {
        $inquiry_success = true;
        unset($_SESSION['concept_success']);
    }

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
            .font-fraunces { font-family: 'Fraunces', serif; }
            .font-worksans { font-family: 'Work Sans', sans-serif; }
            .font-plex     { font-family: 'IBM Plex Mono', monospace; }

            .no-scrollbar { scrollbar-width: none; -ms-overflow-style: none; }
            .no-scrollbar::-webkit-scrollbar { display: none; }

            @media (min-width: 1024px) {
                .concept-row.reverse { flex-direction: row-reverse; }
            }

            .concept-text, .reverse-text {
                position: relative;
                overflow: hidden;
            }
            .concept-text > *, .reverse-text > * { position: relative; z-index: 1; }

            .concept-text .row-meta { justify-content: flex-start; }
            .reverse-text { text-align: right; }
            .reverse-text .row-meta { justify-content: flex-end; }
            .reverse-text .cta-button,
            .reverse-text .view-category-btn { align-self: flex-end; }

            @media (max-width: 1023px) {
                .concept-text, .reverse-text { text-align: left; }
                .reverse-text .row-meta { justify-content: flex-start; }
                .reverse-text .cta-button,
                .reverse-text .view-category-btn { align-self: flex-start; }
            }

            /* Ambient ink-stain wash */
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

            .category-btn { position: relative; }
            .category-btn .tab-underline {
                position: absolute; left: 0; right: 0; bottom: -1px; height: 2px;
                background: #A84D2B; transform: scaleX(0); transform-origin: left;
                transition: transform .3s ease;
            }
            .category-btn:hover .tab-underline,
            .category-btn.active .tab-underline { transform: scaleX(1); }
            .category-btn.active { color: #211A14; }

            .reg-marks { position: relative; }
            .reg-marks span {
                position: absolute; width: 14px; height: 14px; border-color: #A08150;
                pointer-events: none;
            }
            .reg-marks span:nth-child(1) { top: 10px; left: 10px; border-top: 2px solid; border-left: 2px solid; }
            .reg-marks span:nth-child(2) { top: 10px; right: 10px; border-top: 2px solid; border-right: 2px solid; }
            .reg-marks span:nth-child(3) { bottom: 10px; left: 10px; border-bottom: 2px solid; border-left: 2px solid; }
            .reg-marks span:nth-child(4) { bottom: 10px; right: 10px; border-bottom: 2px solid; border-right: 2px solid; }

            /* ── FIX: click-guard was eating too much width on small screens
            and wasn't clipped to the media panel's own box, contributing
            to horizontal overflow on mobile. Scaled down further + capped. */
            .concept-media {
                position: relative;
                overflow: hidden; /* clip anything that pokes out */
            }
            .concept-media::after {
                content: ''; position: absolute; top: 0; right: 0; width: 75px; height: 100%;
                z-index: 14; background: transparent; pointer-events: auto; cursor: not-allowed;
            }
            @media (max-width: 992px) { .concept-media::after { width: 65px; } }
            @media (max-width: 768px) { .concept-media::after { width: 55px; } }
            @media (max-width: 576px) { .concept-media::after { width: 40px; } }

            .carousel-slide {
                opacity: .5;
                transition: opacity .5s ease;
                filter: saturate(0.9);
                border-radius: 16px;
            }
            .carousel-track.no-anim,
            .carousel-track.no-anim .carousel-slide {
                transition: none !important;
            }
            .carousel-slide.active {
                opacity: 1;
                filter: saturate(1);
            }
            .carousel-track { background: #F7F2E9; }

            .logo-overlay {
                background: linear-gradient(135deg, rgba(247,242,233,0.55), rgba(247,242,233,0.15));
                backdrop-filter: blur(24px) saturate(160%);
                -webkit-backdrop-filter: blur(24px) saturate(160%);
                border-bottom: 1px solid rgba(160,129,80,0.4);
                border-right: 1px solid rgba(160,129,80,0.4);
            }

            /* ── FIX: row/panels were allowed to exceed 100% width on narrow
            viewports (iframe + fixed min-heights + no explicit max-width
            guard), causing the page to scroll sideways in mobile. */
            .concept-row {
                width: 100%;
                max-width: 100%;
                overflow: hidden;
            }
            .concept-media,
            .concept-text,
            .reverse-text {
                max-width: 100%;
                box-sizing: border-box;
            }

            @media (max-width: 1023px) {
                .concept-media {
                    min-height: 260px !important;
                    height: 55vh;
                    max-height: 420px;
                }
            }
            @media (max-width: 480px) {
                .concept-media {
                    height: 48vh;
                    min-height: 220px !important;
                }
            }

            #conceptPageWrap {
                margin-left: 0;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
                transition: margin-left .3s ease, width .3s ease;
                overflow-x: clip;
            }

            
        </style>
    </head>

    <body class="bg-[#F7F2E9] no-hero">
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const urlParams = new URLSearchParams(window.location.search);
                const styleId = urlParams.get('style');

                if (styleId) {
                    setTimeout(() => {
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

                            if (targetRow.classList.contains('hidden')) {
                                const catBtns = document.querySelectorAll('.category-btn');
                                catBtns.forEach(b => {
                                    b.classList.toggle('active', b.getAttribute('data-category') === catId);
                                });

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

                                const backBar = document.getElementById('backBar');
                                const barLabel = document.getElementById('backBarLabel');
                                if (backBar) backBar.style.display = 'flex';
                                if (barLabel) barLabel.textContent = 'Showing: All ' + catName + ' Styles';
                            }

                            setTimeout(() => {
                                targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                targetRow.style.transition = 'all 0.5s ease';
                                targetRow.style.boxShadow = '0 0 30px rgba(168, 77, 43, 0.45)';
                                targetRow.style.transform = 'scale(1.02)';
                                setTimeout(() => {
                                    targetRow.style.boxShadow = '';
                                    targetRow.style.transform = '';
                                }, 3000);
                            }, 300);
                        }
                    }, 500);
                }
            });
        </script>

        <?php // include $includes['sidebar']; // uncomment kung hindi pa naka-include sa header.php mo ?>

        <div id="conceptPageWrap">

            <?php
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

            <!-- Back to All Styles bar -->
            <div class="flex items-center gap-6 bg-[#211A14] text-[#F7F2E9] px-6 py-3" id="backBar" style="display:none;">
                <button class="font-plex text-[11px] tracking-[2px] uppercase border border-white/30 px-4 py-2 hover:bg-white hover:text-[#211A14] transition-colors duration-300" id="backAllBtn">
                    ← Back to All Styles
                </button>
                <span class="font-worksans text-[13px] italic text-white/70" id="backBarLabel"></span>
            </div>

            <main class="bg-[#F7F2E9] pt-6 pb-4">
                <?php
                $styles_result = $conn->query("SELECT cs.*, cc.name as category_name, cc.id as category_id 
                                        FROM concept_styles cs 
                                        LEFT JOIN concept_categories cc ON cs.category_id = cc.id 
                                        ORDER BY cs.category_id ASC, cs.display_order ASC");

                $all_styles = [];
                while ($row = $styles_result->fetch_assoc()) {
                    $all_styles[] = $row;
                }

                $seen_categories = [];
                $default_styles = [];
                $extra_styles = [];

                foreach ($all_styles as $style) {
                    $cat_id = $style['category_id'] ?? 'none';
                    if (!in_array($cat_id, $seen_categories)) {
                        $seen_categories[] = $cat_id;
                        $default_styles[] = $style;
                    } else {
                        $extra_styles[] = $style;
                    }
                }

                $ordered_styles = array_merge($default_styles, $extra_styles);

                $row_index = 0;
                foreach ($ordered_styles as $style):
                    $is_default = in_array($style, $default_styles);
                    $cat_id = $style['category_id'] ?? 'none';
                    $cat_name = htmlspecialchars($style['category_name'] ?? '');
                    $reverse_class = ($row_index % 2 !== 0) ? 'reverse' : '';
                    $reverse_text_class = ($row_index % 2 !== 0) ? 'reverse-text' : '';
                    $hidden_class = $is_default ? '' : 'extra-style hidden';
                    $row_index++;
                    ?>
                    <!-- ═══════════════════════════════
                    STYLE ENTRY
                    ═══════════════════════════════ -->
                    <section class="concept-row <?php echo $reverse_class; ?> <?php echo $hidden_class; ?> flex flex-col lg:flex-row items-stretch mb-10 sm:mb-14"
                        data-category="<?php echo $cat_id; ?>" data-is-default="<?php echo $is_default ? '1' : '0'; ?>">

                        <!-- Media panel -->
                        <div class="concept-media reg-marks relative w-full lg:w-1/2 min-h-[300px] lg:min-h-[560px] bg-[#211A14] p-3">
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
                        <div class="<?php echo trim('concept-text ' . $reverse_text_class); ?> w-full lg:w-1/2 flex flex-col justify-center px-6 py-10 sm:px-10 sm:py-14 lg:px-16">

                            <div class="row-meta flex items-center gap-3 mb-4">
                                <?php if ($style['category_name']): ?>
                                    <span class="category-label font-plex text-[10px] tracking-[2.5px] uppercase text-[#A08150] border-l-2 border-[#A08150] pl-3"><?php echo $cat_name; ?></span>
                                <?php endif; ?>
                            </div>

                            <h2 class="font-fraunces text-[#211A14] text-[2rem] sm:text-[2.4rem] lg:text-5xl leading-[1.1] mb-4">
                                <?php echo htmlspecialchars($style['title']); ?>
                            </h2>
                            <p class="font-worksans text-[15px] sm:text-[15.5px] text-[#211A14]/70 leading-[1.75] mb-8 max-w-md">
                                <?php echo nl2br(htmlspecialchars($style['description'])); ?>
                            </p>

                            <!-- Customize button -->
                            <button class="cta-button inline-flex items-center gap-2 self-start font-plex text-[11px] tracking-[2px] uppercase text-[#211A14] border-b-2 border-[#211A14] pb-1.5 hover:text-[#A84D2B] hover:border-[#A84D2B] transition-colors duration-300"
                                data-concept-id="<?php echo $style['id']; ?>"
                                data-concept-title="<?php echo htmlspecialchars($style['title']); ?>"
                                data-category-name="<?php echo $cat_name; ?>">
                                Customize Your Cabinet in This Style →
                            </button>

                            <!-- View All [Category] button -->
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

                <div class="relative max-w-6xl mx-auto px-1">
                    <div class="carousel-track flex items-stretch gap-3 transition-transform duration-500 ease-out aspect-[4/3] sm:aspect-[16/10] lg:aspect-[16/9] max-h-[560px] bg-[#211A14]">
                        <?php
                        $carousel_result = $conn->query("SELECT * FROM concept_carousel ORDER BY display_order ASC");
                        $carousel_images = [];
                        while ($img = $carousel_result->fetch_assoc()) {
                            $carousel_images[] = $img;
                        }

                        if (count($carousel_images) > 0) {
                            echo '<img src="' . CLIENT_ASSET . '/' . htmlspecialchars($carousel_images[count($carousel_images) - 1]['image_path']) . '" 
            class="carousel-slide flex-none w-[80%] h-full object-cover object-center cursor-pointer" alt="Slide" loading="eager" decoding="sync" onclick="openLightbox(this.src)">';

                            foreach ($carousel_images as $img) {
                                echo '<img src="' . CLIENT_ASSET . '/' . htmlspecialchars($img['image_path']) . '" 
                class="carousel-slide flex-none w-[80%] h-full object-cover object-center cursor-pointer" alt="Slide" loading="eager" decoding="sync" onclick="openLightbox(this.src)">';
                            }

                            echo '<img src="' . CLIENT_ASSET . '/' . htmlspecialchars($carousel_images[0]['image_path']) . '" 
            class="carousel-slide flex-none w-[80%] h-full object-cover object-center cursor-pointer" alt="Slide" loading="eager" decoding="sync" onclick="openLightbox(this.src)">';
                        }
                        ?>
                    </div>

                    <button class="carousel-arrow left absolute left-2 sm:left-4 top-1/2 -translate-y-1/2 w-9 h-9 sm:w-11 sm:h-11 border border-[#A08150]/60 bg-[#211A14]/85 hover:bg-[#A84D2B] hover:border-[#A84D2B] text-white z-10 select-none cursor-pointer transition-colors duration-300"
                        onclick="prevSlide()" aria-label="Previous slide">
                        <span class="flex items-center justify-center w-full h-full">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M12.5 15L7.5 10L12.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                    </button>

                    <button class="carousel-arrow right absolute right-2 sm:right-4 top-1/2 -translate-y-1/2 w-9 h-9 sm:w-11 sm:h-11 border border-[#A08150]/60 bg-[#211A14]/85 hover:bg-[#A84D2B] hover:border-[#A84D2B] text-white z-10 select-none cursor-pointer transition-colors duration-300"
                        onclick="nextSlide()" aria-label="Next slide">
                        <span class="flex items-center justify-center w-full h-full">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M7.5 15L12.5 10L7.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                    </button>
                </div>
            </section>
            

            <!-- ═══════════════════════════════
            IMAGE LIGHTBOX
            ═══════════════════════════════ -->
            <div id="imageLightbox" class="fixed inset-0 bg-black/90 z-[99999] hidden items-center justify-center px-4" onclick="closeLightbox(event)">
                <button class="absolute top-5 right-5 sm:top-8 sm:right-8 w-10 h-10 flex items-center justify-center border border-white/40 text-white hover:bg-white hover:text-[#211A14] transition-colors duration-300 z-10" onclick="closeLightbox(event)" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M4 4L16 16M16 4L4 16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
                <img id="lightboxImg" src="" alt="Preview" class="max-w-full max-h-[90vh] object-contain">
            </div>

            <?php
            include $includes['banner2'];
            ?>

            <?php include $includes['inquiry']; ?>

            <?php include $includes['footer']; ?>

        </div> <!-- /#conceptPageWrap -->

        <script>
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
                if (!track || !slides.length) return;
                let currentIndex = 1;

                function updateCarousel(skipTransition) {
                    if (skipTransition) track.style.transition = 'none';
                    const gap = 12;
                    const slideWidth = slides[0].offsetWidth;
                    const containerWidth = track.offsetWidth;
                    const centerOffset = (containerWidth - slideWidth) / 2;
                    track.style.transform = `translateX(${centerOffset - currentIndex * (slideWidth + gap)}px)`;
                    slides.forEach((slide, i) => {
                        slide.classList.toggle('active', i === currentIndex);
                    });
                    if (skipTransition) {
                        track.offsetHeight;
                        track.style.transition = '';
                    }
                }

                // ── Re-sync whenever the layout shifts (sidebar collapse/expand,
                // window resize, or the sidebar's own async width sync finishing
                // after this script already ran) ──────────────────────────────
                let resizeTimer;
                function handleResize() {
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(() => updateCarousel(true), 120);
                }
                window.addEventListener('resize', handleResize);

                const wrapEl = document.getElementById('conceptPageWrap');
                if (wrapEl && window.ResizeObserver) {
                    new ResizeObserver(() => handleResize()).observe(wrapEl);
                }

                function jumpToIndex(index) {
                    track.classList.add('no-anim');
                    currentIndex = index;
                    updateCarousel(true);
                    track.offsetHeight;
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => {
                            track.classList.remove('no-anim');
                        });
                    });
                }

                function nextSlide() {
                    currentIndex++;
                    track.classList.remove('no-anim');
                    updateCarousel();
                    if (currentIndex === slides.length - 1) {
                        setTimeout(() => jumpToIndex(1), 500);
                    }
                }

                function prevSlide() {
                    currentIndex--;
                    track.classList.remove('no-anim');
                    updateCarousel();
                    if (currentIndex === 0) {
                        setTimeout(() => jumpToIndex(slides.length - 2), 500);
                    }
                }

                window.nextSlide = nextSlide;
                window.prevSlide = prevSlide;

                updateCarousel(true);
                setInterval(nextSlide, 4000);
            });

            // ── IMAGE LIGHTBOX ──────────────────────────────────────────────
            function openLightbox(src) {
                const lightbox = document.getElementById('imageLightbox');
                const lightboxImg = document.getElementById('lightboxImg');
                lightboxImg.src = src;
                lightbox.classList.remove('hidden');
                lightbox.classList.add('flex');
                document.body.style.overflow = 'hidden';
            }

            function closeLightbox(e) {
                if (e.target.id === 'lightboxImg') return;
                const lightbox = document.getElementById('imageLightbox');
                lightbox.classList.add('hidden');
                lightbox.classList.remove('flex');
                document.body.style.overflow = '';
            }

            window.openLightbox = openLightbox;
            window.closeLightbox = closeLightbox;

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

                function applyFilter(catId, catName, scrollToTop) {
                    let visibleIndex = 0;

                    allRows.forEach(row => {
                        const rowCat = row.getAttribute('data-category');
                        const isDefault = row.getAttribute('data-is-default') === '1';

                        let shouldShow = false;
                        if (catId === 'all') {
                            shouldShow = isDefault;
                        } else {
                            shouldShow = (rowCat === catId);
                        }

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
                            row.querySelectorAll('.lazy-iframe').forEach(iframe => {
                                if (iframe.src !== 'about:blank') {
                                    iframe.dataset.src = iframe.src;
                                    iframe.src = 'about:blank';
                                }
                            });
                        }
                    });

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

                catBtns.forEach(btn => {
                    btn.addEventListener('click', () => {
                        catBtns.forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');

                        const catId = btn.getAttribute('data-category');
                        const catName = btn.textContent.trim();
                        applyFilter(catId, catName, true);
                    });
                });

                document.querySelectorAll('.view-category-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const catId = btn.getAttribute('data-category');
                        const catName = btn.getAttribute('data-category-name');

                        catBtns.forEach(b => {
                            b.classList.toggle('active', b.getAttribute('data-category') === catId);
                        });

                        applyFilter(catId, catName, false);

                        const firstVisible = document.querySelector('.concept-row:not(.hidden)');
                        if (firstVisible) firstVisible.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    });
                });

                backBtn.addEventListener('click', () => {
                    catBtns.forEach(b => {
                        b.classList.toggle('active', b.getAttribute('data-category') === 'all');
                    });
                    applyFilter('all', '', true);
                });

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

                const iframeObserver = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        const iframe = entry.target;
                        if (entry.isIntersecting) {
                            if (iframe.dataset.src && iframe.src !== iframe.dataset.src) {
                                iframe.src = iframe.dataset.src;
                            }
                        } else {
                            if (iframe.src !== 'about:blank') {
                                iframe.dataset.src = iframe.src;
                                iframe.src = 'about:blank';
                            }
                        }
                    });
                }, {
                    rootMargin: '200px 0px',
                    threshold: 0.01
                });

                document.querySelectorAll('.lazy-iframe').forEach(iframe => {
                    iframeObserver.observe(iframe);
                });
            });
        </script>

        <script>
            // ── SELF-CONTAINED SIDEBAR OFFSET (concepts page only) ──────────
            (function () {
                function sync() {
                    const wrap = document.getElementById('conceptPageWrap');
                    const sidebar = document.getElementById('sidebar');
                    if (!wrap) return;

                    // Sa mobile (<=1024px), retired na ang #sidebar rail — ang
                    // mobileTopBar/mobileBottomNav na ang gamit doon. Kaya walang
                    // dapat i-offset sa kaliwa; kung hindi ito i-guard, mananatili
                    // ang naka-set na marginLeft mula sa desktop resize/init at
                    // magiging blangkong puwang sa kaliwa ng content sa mobile.
                    const isMobile = window.matchMedia('(max-width: 767px)').matches;
                    if (!sidebar || isMobile) {
                        wrap.style.marginLeft = '0';
                        wrap.style.width = '100%';
                        return;
                    }

                    // Sumusunod na ngayon sa ACTUAL state ng sidebar — kapag
                    // naka-collapse, offset base sa collapsed width; kapag
                    // naka-expand, offset base sa expanded width. Kaya
                    // gumagana na rin dito ang shrink effect, gaya ng nasa
                    // ibang pages (realiving_main.php, atbp.).
                    const isCollapsed = sidebar.classList.contains('collapsed');
                    const rootStyles = getComputedStyle(document.documentElement);
                    const varName = isCollapsed ? '--sb-w-collapsed' : '--sb-w-expanded';
                    const w = parseInt(rootStyles.getPropertyValue(varName).trim());
                    wrap.style.marginLeft = w + 'px';
                    wrap.style.width = `calc(100% - ${w}px)`;
                }

                function init() {
                    const wrap = document.getElementById('conceptPageWrap');

                    // Alisin muna ang transition bago ang unang sync — kundi,
                    // ma-a-animate ang paglipat mula sa default (margin-left:0)
                    // papunta sa tamang offset (84px/280px), kaya parang
                    // "gumagalaw papuntang kanan" ang buong page sa unang
                    // pagbukas. I-disable muna, i-calculate ang tamang offset,
                    // saka lang i-enable ulit para sa mga susunod na resize/
                    // collapse-toggle interactions.
                    if (wrap) wrap.style.transition = 'none';

                    sync();

                    // I-restore ang transition sa susunod na frame — sa
                    // puntong ito, tapos na ang unang paint sa tamang offset.
                    requestAnimationFrame(function () {
                        requestAnimationFrame(function () {
                            if (wrap) wrap.style.transition = '';
                        });
                    });

                    let resizeTimer;
                    window.addEventListener('resize', function () {
                        clearTimeout(resizeTimer);
                        resizeTimer = setTimeout(sync, 100);
                    });

                    const sidebar = document.getElementById('sidebar');
                    if (sidebar && window.MutationObserver) {
                        const mo = new MutationObserver(sync);
                        mo.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
                    }

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

                    setTimeout(() => { toast.style.opacity = '1'; }, 100);

                    setTimeout(() => {
                        toast.style.opacity = '0';
                        setTimeout(() => toast.remove(), 500);
                    }, 4000);
                });
            </script>
        <?php endif; ?>
    </body>

    </html>