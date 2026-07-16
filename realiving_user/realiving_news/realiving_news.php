<?php
//news.php
session_name("Realivinguser");
session_start();
include $includes['connection'];

// Get header settings
$header_result = $conn->query("SELECT * FROM news_header LIMIT 1");
$header = $header_result->fetch_assoc();
$header_image = $header ? $header['header_image'] : 'images/background-image.jpg';
$header_title = $header ? $header['title'] : 'News & Updates';
$header_subtitle = $header ? $header['subtitle'] : 'Stay updated with the latest news, trends, and announcements';

// Get news data (moved above the markup so the masthead ticker can use counts)
$sql = "SELECT id, image, title, description, category, date_uploaded, author FROM news WHERE status = 'published' ORDER BY featured DESC, date_uploaded DESC";
$result = $conn->query($sql);
$all_news = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $all_news[] = $row;
    }
}

// Threshold: "latest" = posted within 30 days
$latest_news = [];
$older_news = [];
$now = new DateTime();
foreach ($all_news as $item) {
    $posted = new DateTime($item['date_uploaded']);
    $diff = $now->diff($posted)->days;
    if ($diff <= 30) {
        $latest_news[] = $item;
    } else {
        $older_news[] = $item;
    }
}

// If nothing is "latest", show the 3 most recent as latest
if (empty($latest_news) && !empty($all_news)) {
    $latest_news = array_slice($all_news, 0, 3);
    $older_news = array_slice($all_news, 3);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($header_title); ?> | Realiving Design Center</title>
    <link rel="stylesheet" href="<?= BASE_ASSET ?>assets/css/output.css">
    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,500;0,600;0,700;1,400;1,600&display=swap"
        rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />
</head>

<body class="bg-[#faf8f4] text-[#241205] no-hero" style="font-family:'Montserrat',sans-serif;">

    <?php include $includes['header']; ?>

    <div class="main-content">

    <!-- ═══════════════════════════════
         MASTHEAD
    ═══════════════════════════════ -->
    <section class="relative bg-[#2f1200] overflow-hidden">
        <div class="absolute inset-0 bg-cover bg-center opacity-20"
            style="background-image:url('<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($header_image); ?>');"></div>
        <div class="absolute inset-0 bg-gradient-to-b from-[#2f1200]/60 via-[#2f1200]/85 to-[#2f1200]"></div>

        <div class="relative max-w-5xl mx-auto px-6 pt-24 pb-12 text-center">
            <div class="inline-flex items-center gap-3 text-[#c4905c] text-[11px] font-semibold tracking-[3px] uppercase mb-6">
                <span class="w-6 h-px bg-[#c4905c]"></span>
                Realiving Design Center
                <span class="w-6 h-px bg-[#c4905c]"></span>
            </div>
            <h1 class="font-['Crimson_Pro'] italic font-semibold text-white text-4xl md:text-6xl leading-tight">
                <?php echo htmlspecialchars($header_title); ?>
            </h1>
            <p class="mt-4 text-white/70 text-sm md:text-base max-w-xl mx-auto">
                <?php echo htmlspecialchars($header_subtitle); ?>
            </p>
        </div>

        <div class="relative border-t border-white/10">
            <div class="max-w-5xl mx-auto px-6 py-3 flex items-center justify-between text-[10px] tracking-[2px] uppercase text-white/40">
                <span><?php echo date('l, F d, Y'); ?></span>
                <span><?php echo count($all_news); ?> published <?php echo count($all_news) === 1 ? 'story' : 'stories'; ?></span>
            </div>
        </div>
    </section>

    <?php if (!empty($all_news)):
        $featured = $latest_news[0];
        $remaining_latest = array_slice($latest_news, 1);
    ?>

        <!-- ═══════════════════════════════
             FEATURED HERO — magazine split card,
             overlaps the masthead slightly for depth.
        ═══════════════════════════════ -->
        <section class="max-w-6xl mx-auto px-6 -mt-10 relative z-10">
            <a href="<?= BASE_URL ?>news-view?id=<?php echo $featured['id']; ?>"
                class="group grid grid-cols-1 lg:grid-cols-5 bg-white shadow-xl shadow-[#2f1200]/10 overflow-hidden">
                <div class="lg:col-span-3 relative h-64 lg:h-auto overflow-hidden">
                    <img src="<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($featured['image']); ?>"
                        alt="<?php echo htmlspecialchars($featured['title']); ?>"
                        class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105">
                    <span class="absolute top-4 left-4 inline-flex items-center gap-1.5 bg-[#c4905c] text-[#2f1200] text-[10px] font-bold tracking-[1.5px] uppercase px-3 py-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-[#2f1200] animate-pulse"></span> Latest
                    </span>
                </div>
                <div class="lg:col-span-2 p-8 md:p-10 flex flex-col justify-center bg-white">
                    <span class="text-[11px] font-semibold tracking-[2px] uppercase text-[#c4905c] mb-3">
                        <?php echo htmlspecialchars($featured['category']); ?>
                    </span>
                    <h2 class="font-['Crimson_Pro'] font-semibold text-2xl md:text-3xl text-[#2f1200] leading-snug group-hover:text-[#c4905c] transition-colors">
                        <?php echo htmlspecialchars($featured['title']); ?>
                    </h2>
                    <p class="mt-4 text-sm text-[#6b5c4d] leading-relaxed line-clamp-3">
                        <?php echo htmlspecialchars(substr($featured['description'], 0, 180)) . '...'; ?>
                    </p>
                    <div class="mt-6 flex items-center gap-3 text-[11px] text-[#8a7a68] uppercase tracking-wide">
                        <span><i class="ri-calendar-line mr-1"></i><?php echo date('F d, Y', strtotime($featured['date_uploaded'])); ?></span>
                        <span class="w-1 h-1 rounded-full bg-[#c4905c]"></span>
                        <span><i class="ri-user-line mr-1"></i><?php echo htmlspecialchars($featured['author']); ?></span>
                    </div>
                    <span class="mt-6 inline-flex items-center gap-2 text-[12px] font-semibold tracking-[1.5px] uppercase text-[#2f1200] group-hover:gap-3 transition-all">
                        Read story <i class="ri-arrow-right-line"></i>
                    </span>
                </div>
            </a>
        </section>

        <?php if (!empty($remaining_latest)): ?>
            <!-- ═══════════════════════════════
                 LATEST STRIP — asymmetric editorial grid,
                 first item runs larger than the rest.
            ═══════════════════════════════ -->
            <section class="max-w-6xl mx-auto px-6 mt-16">
                <div class="flex items-end justify-between border-b border-[#e8dfd3] pb-3 mb-8">
                    <h2 class="flex items-center gap-2 text-[13px] font-bold tracking-[2px] uppercase text-[#2f1200]">
                        <span class="w-2 h-2 rounded-full bg-[#c4905c]"></span> Latest news
                    </h2>
                    <span class="text-[11px] text-[#a3907a] uppercase tracking-wide"><?php echo count($remaining_latest); ?> stories</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <?php foreach ($remaining_latest as $i => $row): ?>
                        <article class="group <?php echo $i === 0 ? 'md:col-span-2 md:row-span-2' : ''; ?>">
                            <a href="<?= BASE_URL ?>news-view?id=<?php echo $row['id']; ?>" class="block">
                                <div class="relative overflow-hidden <?php echo $i === 0 ? 'h-72' : 'h-44'; ?>">
                                    <img src="<?= CLIENT_ASSET ?>/<?php echo htmlspecialchars($row['image']); ?>"
                                        alt="<?php echo htmlspecialchars($row['title']); ?>"
                                        class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                                    <span class="absolute top-3 left-3 bg-[#2f1200]/90 text-white text-[10px] font-semibold tracking-[1.5px] uppercase px-2.5 py-1">
                                        <?php echo htmlspecialchars($row['category']); ?>
                                    </span>
                                </div>
                                <div class="pt-4">
                                    <div class="flex items-center gap-2 text-[11px] text-[#a3907a] uppercase tracking-wide mb-2">
                                        <span><?php echo date('M d, Y', strtotime($row['date_uploaded'])); ?></span>
                                        <span class="w-1 h-1 rounded-full bg-[#c4905c]"></span>
                                        <span>By <?php echo htmlspecialchars($row['author']); ?></span>
                                    </div>
                                    <h3 class="font-['Crimson_Pro'] font-semibold <?php echo $i === 0 ? 'text-xl' : 'text-base'; ?> text-[#2f1200] leading-snug group-hover:text-[#c4905c] transition-colors <?php echo $i === 0 ? '' : 'line-clamp-2'; ?>">
                                        <?php echo htmlspecialchars($row['title']); ?>
                                    </h3>
                                    <?php if ($i === 0): ?>
                                        <p class="mt-2 text-sm text-[#6b5c4d] leading-relaxed line-clamp-2">
                                            <?php echo htmlspecialchars(substr($row['description'], 0, 140)) . '...'; ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($older_news)): ?>
            <!-- ═══════════════════════════════
                 PAST ARTICLES — archive index list,
                 not cards. Reads like a newspaper index.
            ═══════════════════════════════ -->
            <section class="max-w-6xl mx-auto px-6 mt-20 mb-24">
                <div class="flex items-end justify-between border-b border-[#e8dfd3] pb-3 mb-4">
                    <h2 class="flex items-center gap-2 text-[13px] font-bold tracking-[2px] uppercase text-[#2f1200]">
                        <span class="w-2 h-2 rounded-full bg-[#a3907a]"></span> Past articles
                    </h2>
                    <span class="text-[11px] text-[#a3907a] uppercase tracking-wide"><?php echo count($older_news); ?> stories</span>
                </div>

                <div class="divide-y divide-[#e8dfd3]">
                    <?php foreach ($older_news as $row): ?>
                        <a href="<?= BASE_URL ?>news-view?id=<?php echo $row['id']; ?>"
                            class="group flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4 py-5 hover:bg-[#faf3e9] transition-colors -mx-4 px-4">
                            <span class="shrink-0 sm:w-24 text-[11px] text-[#a3907a] uppercase tracking-wide font-medium">
                                <?php echo date('M d, Y', strtotime($row['date_uploaded'])); ?>
                            </span>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-['Crimson_Pro'] font-semibold text-lg text-[#2f1200] group-hover:text-[#c4905c] transition-colors truncate">
                                    <?php echo htmlspecialchars($row['title']); ?>
                                </h3>
                                <p class="text-sm text-[#8a7a68] truncate">
                                    <?php echo htmlspecialchars(substr($row['description'], 0, 110)) . '...'; ?>
                                </p>
                            </div>
                            <span class="shrink-0 text-[10px] font-semibold tracking-[1.5px] uppercase text-[#c4905c] border border-[#c4905c]/40 px-2.5 py-1">
                                <?php echo htmlspecialchars($row['category']); ?>
                            </span>
                            <i class="ri-arrow-right-line text-[#a3907a] group-hover:text-[#c4905c] group-hover:translate-x-1 transition-all shrink-0"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

    <?php else: ?>
        <!-- ═══════════════════════════════
             EMPTY STATE
        ═══════════════════════════════ -->
        <div class="max-w-md mx-auto text-center py-32 px-6">
            <i class="ri-newspaper-line text-5xl text-[#e8dfd3]"></i>
            <h3 class="font-['Crimson_Pro'] font-semibold text-2xl text-[#2f1200] mt-5">No news yet</h3>
            <p class="text-sm text-[#8a7a68] mt-2">Check back soon for updates and announcements.</p>
        </div>
    <?php endif; ?>

    <?php
    include $includes['footer'];
    $conn->close();
    ?>

    </div>

    

</body>

</html>