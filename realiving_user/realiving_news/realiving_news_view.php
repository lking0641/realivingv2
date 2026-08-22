    <?php
//news-template.php
session_name("Realivinguser");
session_start();
include $includes['connection'];

if (isset($_GET['id'])) {
    $news_id = intval($_GET['id']);

    // Update view count only once per session per article
    if (!isset($_SESSION['viewed_news'])) {
        $_SESSION['viewed_news'] = [];
    }

    if (!in_array($news_id, $_SESSION['viewed_news'])) {
        $conn->query("UPDATE news SET views = views + 1 WHERE id = $news_id");
        $_SESSION['viewed_news'][] = $news_id;
    }

    $sql = "SELECT * FROM news WHERE id = ? AND status = 'published'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $news_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $news = $result->fetch_assoc();
    } else {
        echo "<p>News not found.</p>";
        exit;
    }
    $stmt->close();
} else {
    echo "<p>No news selected.</p>";
    exit;
}

$sub_images = [];
if (!empty($news['sub_images'])) {
    $sub_images = json_decode($news['sub_images'], true) ?: [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" type="image/png" href="../images/favicon.png">
    <title><?php echo htmlspecialchars($news['title']); ?> | Realiving Design Center</title>

    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,500;0,600;0,700;1,400;1,600&display=swap"
        rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />
</head>

<body class="news-template-page no-hero bg-[#faf8f4] text-[#241205]" style="font-family:'Montserrat',sans-serif;">

    <?php include $includes['header']; ?>

    <div class="main-content">

        <!-- ═══════════════════════════════
             ARTICLE — wide-screen two-column layout.
             Article body on the left, sticky info panel on the right
             (fills the wasted whitespace on large monitors). Collapses
             to a single stacked column below the lg breakpoint.
        ═══════════════════════════════ -->
        <section class="max-w-7xl mx-auto px-6 pt-14 sm:pt-20 pb-16">

            <!-- Breadcrumb -->
            <div class="flex items-center gap-2 text-[12px] font-montserrat text-[#a3907a] mb-8">
                <a href="<?= BASE_URL ?>news" class="text-[#2f1200] hover:text-[#c4905c] transition-colors font-medium">News</a>
                <i class="ri-arrow-right-s-line"></i>
                <span><?php echo htmlspecialchars($news['category']); ?></span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16">

                <!-- ───────── MAIN COLUMN ───────── -->
                <div class="lg:col-span-8">

                    <span class="inline-block bg-[#2f1200] text-white text-[10px] font-semibold tracking-[1.5px] uppercase px-3 py-1.5 mb-5">
                        <?php echo htmlspecialchars($news['category']); ?>
                    </span>
                    <h1 class="font-['Crimson_Pro'] font-semibold text-[#2f1200] text-3xl sm:text-4xl md:text-5xl leading-tight mb-8">
                        <?php echo htmlspecialchars($news['title']); ?>
                    </h1>

                    <!-- Featured image -->
                    <div class="mb-6 overflow-hidden shadow-[0_10px_30px_rgba(47,18,0,0.12)] bg-[#f0ebe4]">
                        <img id="mainNewsImage" src="<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($news['image']); ?>"
                            alt="<?php echo htmlspecialchars($news['title']); ?>"
                            class="w-full h-[260px] sm:h-[420px] lg:h-[480px] object-contain bg-[#f0ebe4]">
                    </div>

                    <!-- Sub images slider -->
                    <?php if (!empty($sub_images)): ?>
                        <div class="mb-10 flex items-center gap-2">
                            <button id="sliderPrev" onclick="slideImages(-1)"
                                class="shrink-0 w-7 h-7 rounded-full bg-[#2f1200] text-white text-sm flex items-center justify-center hover:bg-[#c4905c] transition-colors">
                                <i class="ri-arrow-left-s-line"></i>
                            </button>
                            <div id="subImagesTrack" class="flex-1 overflow-hidden">
                                <div id="subTrack" class="flex gap-2.5">
                                    <div class="sub-thumb active shrink-0 w-14 h-12 sm:w-16 sm:h-14 rounded overflow-hidden cursor-pointer border-2 border-[#2f1200] opacity-100 transition-all"
                                        onclick="switchMainImage(this, '<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($news['image']); ?>')">
                                        <img src="<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($news['image']); ?>" alt="Main"
                                            class="w-full h-full object-contain bg-[#f0ebe4]">
                                    </div>
                                    <?php foreach ($sub_images as $sub): ?>
                                        <div class="sub-thumb shrink-0 w-14 h-12 sm:w-16 sm:h-14 rounded overflow-hidden cursor-pointer border-2 border-transparent opacity-65 hover:opacity-100 transition-all"
                                            onclick="switchMainImage(this, '<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($sub); ?>')">
                                            <img src="<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($sub); ?>" alt="Sub image"
                                                class="w-full h-full object-contain bg-[#f0ebe4]">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <button id="sliderNext" onclick="slideImages(1)"
                                class="shrink-0 w-7 h-7 rounded-full bg-[#2f1200] text-white text-sm flex items-center justify-center hover:bg-[#c4905c] transition-colors">
                                <i class="ri-arrow-right-s-line"></i>
                            </button>
                        </div>
                    <?php endif; ?>

                    <!-- Article content -->
                    <div class="font-['Crimson_Pro'] text-[17px] sm:text-[18px] leading-[1.8] text-[#3a2c1f] [&_p]:mb-5">
                        <?php echo nl2br(htmlspecialchars($news['content'])); ?>
                    </div>

                    <!-- Keywords (mobile/tablet only — moves to sidebar on lg+) -->
                    <?php if (!empty($news['keywords'])): ?>
                        <div class="lg:hidden mt-10 p-6 bg-[#faf3e9] border-l-4 border-[#c4905c]">
                            <span class="flex items-center gap-2 text-[13px] font-semibold uppercase tracking-wide text-[#2f1200] mb-3">
                                <i class="ri-price-tag-3-line text-[#c4905c]"></i> Tags
                            </span>
                            <div class="flex flex-wrap gap-2">
                                <?php
                                $keywords = explode(',', $news['keywords']);
                                foreach ($keywords as $kw):
                                    $trimmed = trim($kw);
                                    if (!empty($trimmed)):
                                ?>
                                    <span class="bg-white text-[#2f1200] text-[12px] px-3.5 py-1.5 rounded-full border border-[#e3d6c5] hover:bg-[#2f1200] hover:text-white hover:border-[#2f1200] transition-colors">
                                        <?php echo htmlspecialchars($trimmed); ?>
                                    </span>
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>

                <!-- ───────── SIDEBAR (sticky on lg+) ───────── -->
                <aside class="lg:col-span-4">
                    <div class="lg:sticky lg:top-24 flex flex-col gap-5">

                        <!-- At a glance -->
                        <div class="bg-white border border-[#e8dfd3] p-6">
                            <span class="block text-[11px] font-bold tracking-[2px] uppercase text-[#c4905c] mb-4">At a glance</span>
                            <div class="flex flex-col gap-3.5 text-[13px] text-[#3a2c1f]">
                                <div class="flex items-center gap-3">
                                    <i class="ri-calendar-line text-[#c4905c] text-base"></i>
                                    <span><?php echo date('F d, Y', strtotime($news['date_uploaded'])); ?></span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <i class="ri-user-line text-[#c4905c] text-base"></i>
                                    <span><?php echo htmlspecialchars($news['author']); ?></span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <i class="ri-eye-line text-[#c4905c] text-base"></i>
                                    <span><?php echo number_format($news['views']); ?> views</span>
                                </div>
                            </div>
                        </div>

                        <!-- Tags (lg+ home for keywords) -->
                        <?php if (!empty($news['keywords'])): ?>
                            <div class="hidden lg:block bg-white border border-[#e8dfd3] p-6">
                                <span class="flex items-center gap-2 text-[11px] font-bold tracking-[2px] uppercase text-[#c4905c] mb-4">
                                    <i class="ri-price-tag-3-line"></i> Tags
                                </span>
                                <div class="flex flex-wrap gap-2">
                                    <?php
                                    $keywords = explode(',', $news['keywords']);
                                    foreach ($keywords as $kw):
                                        $trimmed = trim($kw);
                                        if (!empty($trimmed)):
                                    ?>
                                        <span class="bg-[#faf3e9] text-[#2f1200] text-[12px] px-3.5 py-1.5 rounded-full border border-[#e3d6c5] hover:bg-[#2f1200] hover:text-white hover:border-[#2f1200] transition-colors">
                                            <?php echo htmlspecialchars($trimmed); ?>
                                        </span>
                                    <?php
                                        endif;
                                    endforeach;
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Mini CTA -->
                        <div class="bg-[#2f1200] p-6">
                            <span class="block text-[10px] font-bold tracking-[2px] uppercase text-[#c4905c] mb-3">✦ Let's talk</span>
                            <p class="font-['Crimson_Pro'] font-semibold text-white text-lg leading-snug mb-4">
                                Interested in customized cabinets?
                            </p>
                            <a href="<?= BASE_URL ?>appointment"
                                class="group inline-flex items-center justify-center gap-2 w-full bg-white text-[#2f1200] font-semibold text-[10px] tracking-[1.5px] uppercase px-5 py-3 rounded-full transition-all duration-300 hover:bg-[#c4905c] hover:text-white">
                                <i class="ri-calendar-check-line"></i> Book consultation
                            </a>
                        </div>

                    </div>
                </aside>

            </div>
        </section>

        <!-- ═══════════════════════════════
             RELATED ARTICLES
        ═══════════════════════════════ -->
        <section class="max-w-7xl mx-auto px-6 pb-16">
            <div class="flex items-end justify-between border-b border-[#e8dfd3] pb-3 mb-8">
                <h2 class="flex items-center gap-2 text-[13px] font-bold tracking-[2px] uppercase text-[#2f1200]">
                    <span class="w-2 h-2 rounded-full bg-[#c4905c]"></span> Related articles
                </h2>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php
                $sql = "SELECT id, image, title, description, category, date_uploaded FROM news WHERE id != ? AND status = 'published' ORDER BY date_uploaded DESC LIMIT 3";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $news_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0):
                    while ($related = $result->fetch_assoc()):
                ?>
                    <a href="<?= BASE_URL ?>news-view?id=<?php echo $related['id']; ?>" class="group block">
                        <div class="relative h-44 overflow-hidden mb-3">
                            <img src="<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($related['image']); ?>"
                                alt="<?php echo htmlspecialchars($related['title']); ?>"
                                class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                            <span class="absolute top-3 left-3 bg-[#2f1200]/90 text-white text-[10px] font-semibold tracking-[1.5px] uppercase px-2.5 py-1">
                                <?php echo htmlspecialchars($related['category']); ?>
                            </span>
                        </div>
                        <span class="block text-[11px] text-[#a3907a] uppercase tracking-wide mb-1.5">
                            <?php echo date('M d, Y', strtotime($related['date_uploaded'])); ?>
                        </span>
                        <h3 class="font-['Crimson_Pro'] font-semibold text-lg text-[#2f1200] leading-snug mb-2 line-clamp-2 group-hover:text-[#c4905c] transition-colors">
                            <?php echo htmlspecialchars($related['title']); ?>
                        </h3>
                        <p class="text-sm text-[#6b5c4d] line-clamp-2 mb-3">
                            <?php echo htmlspecialchars(substr($related['description'], 0, 100)); ?>...
                        </p>
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-[#2f1200] group-hover:gap-2.5 transition-all">
                            Read more <i class="ri-arrow-right-line"></i>
                        </span>
                    </a>
                <?php
                    endwhile;
                else:
                ?>
                    <p class="col-span-full text-center text-[#a3907a] py-10">No related articles available.</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- ═══════════════════════════════
             CTA SECTION (mobile/tablet only — desktop already has the
             mini CTA in the sticky sidebar above, so this stays hidden
             on lg+ to avoid pitching the same thing twice)
        ═══════════════════════════════ -->
        <section class="lg:hidden bg-[#2f1200] py-16 sm:py-20 px-6 text-center">
            <div class="max-w-xl mx-auto">
                <span class="inline-block text-[10px] font-bold tracking-[3px] uppercase text-[#c4905c] mb-4">✦ Let's talk</span>
                <h2 class="font-['Crimson_Pro'] font-semibold text-white text-2xl sm:text-4xl leading-snug mb-4">
                    Interested in customized cabinets?
                </h2>
                <p class="text-white/70 text-sm sm:text-base mb-8">
                    Let us help you bring your dream space to life with our expert design services.
                </p>
                <a href="<?= BASE_URL ?>appointment"
                    class="group inline-flex items-center gap-2 bg-white text-[#2f1200] font-semibold text-[11px] tracking-[2px] uppercase px-8 py-4 rounded-full transition-all duration-300 hover:bg-[#c4905c] hover:text-white shadow-lg">
                    <i class="ri-calendar-check-line"></i> Book consultation
                </a>
            </div>
        </section>

    </div>

    <script>
        let sliderOffset = 0;
        let touchStartX = 0;
        let touchCurrentX = 0;
        let isDragging = false;
        let startTranslate = 0;

        function getVisibleCount() {
            if (window.innerWidth <= 480) return 3;
            if (window.innerWidth <= 768) return 4;
            return 5;
        }

        function getThumbWidth() {
            const track = document.getElementById('subTrack');
            if (!track) return 0;
            const thumb = track.querySelector('.sub-thumb');
            return thumb ? thumb.offsetWidth + 10 : 0;
        }

        function getMaxOffset() {
            const track = document.getElementById('subTrack');
            if (!track) return 0;
            const thumbs = track.querySelectorAll('.sub-thumb');
            return Math.max(0, thumbs.length - getVisibleCount());
        }

        function applyTranslate(px, animate = true) {
            const track = document.getElementById('subTrack');
            if (!track) return;
            track.style.transition = animate ? 'transform 0.35s ease' : 'none';
            track.style.transform = `translateX(-${px}px)`;
        }

        function slideImages(dir) {
            const maxOffset = getMaxOffset();
            sliderOffset = Math.min(Math.max(sliderOffset + dir, 0), maxOffset);
            applyTranslate(sliderOffset * getThumbWidth(), true);

            const prev = document.getElementById('sliderPrev');
            const next = document.getElementById('sliderNext');
            if (prev) prev.style.opacity = sliderOffset === 0 ? '0.4' : '1';
            if (next) next.style.opacity = sliderOffset >= maxOffset ? '0.4' : '1';
        }

        function switchMainImage(el, src) {
            document.getElementById('mainNewsImage').src = src;
            document.querySelectorAll('.sub-thumb').forEach(t => {
                t.classList.remove('active', 'border-[#2f1200]', 'opacity-100');
                t.classList.add('border-transparent', 'opacity-65');
            });
            el.classList.add('active', 'border-[#2f1200]', 'opacity-100');
            el.classList.remove('border-transparent', 'opacity-65');
        }

        // Smooth touch drag
        const trackContainer = document.getElementById('subImagesTrack');
        if (trackContainer) {

            trackContainer.addEventListener('touchstart', (e) => {
                touchStartX = e.changedTouches[0].clientX;
                startTranslate = sliderOffset * getThumbWidth();
                isDragging = true;
                applyTranslate(startTranslate, false);
            }, { passive: true });

            trackContainer.addEventListener('touchmove', (e) => {
                if (!isDragging) return;
                touchCurrentX = e.changedTouches[0].clientX;
                const diff = touchStartX - touchCurrentX;
                const track = document.getElementById('subTrack');
                if (track) {
                    track.style.transition = 'none';
                    track.style.transform = `translateX(-${startTranslate + diff}px)`;
                }
            }, { passive: true });

            trackContainer.addEventListener('touchend', (e) => {
                if (!isDragging) return;
                isDragging = false;
                const diff = touchStartX - e.changedTouches[0].clientX;
                const thumbWidth = getThumbWidth();

                if (Math.abs(diff) > 30) {
                    const steps = Math.round(Math.abs(diff) / thumbWidth) || 1;
                    slideImages(diff > 0 ? steps : -steps);
                } else {
                    // Snap back if swipe too short
                    applyTranslate(sliderOffset * thumbWidth, true);
                }
            }, { passive: true });
        }

        function updateArrowVisibility() {
            const prev = document.getElementById('sliderPrev');
            const next = document.getElementById('sliderNext');
            if (window.innerWidth <= 768) {
                if (prev) prev.style.display = 'none';
                if (next) next.style.display = 'none';
            } else {
                if (prev) prev.style.display = 'flex';
                if (next) next.style.display = 'flex';
            }
        }

        window.addEventListener('load', () => {
            slideImages(0);
            updateArrowVisibility();
        });

        window.addEventListener('resize', () => {
            updateArrowVisibility();
            applyTranslate(sliderOffset * getThumbWidth(), false);
        });
    </script>

    <?php
    $stmt->close();
    $conn->close();
    include $includes['footer'];
    ?>
</body>

</html>