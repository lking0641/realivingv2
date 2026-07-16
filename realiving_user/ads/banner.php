<!-- banner.php -->
<?php
$banner1_items = [
    ['delivery.png', 'Worldwide Shipping', 'We deliver our designs to your door, anywhere in the world.'],
    ['discount.png', 'Special Discounts', 'Exclusive pricing for design enthusiasts.'],
    ['order.png', 'Large Volume Orders', 'Special terms and dedicated support for bulk or contract projects.'],
    ['3d.png', '3D Visualization Services', 'Realistic renderings to preview your space before build.'],
];
?>
<section class="realiving-banner">
    <div class="max-w-6xl mx-auto px-6">
        <div class="banner-grid">
            <?php foreach ($banner1_items as $item): ?>
                <div class="banner-item">
                    <img src="<?= CLIENT_ASSET ?>/images/feaban/<?= $item[0] ?>" alt="<?= htmlspecialchars($item[1]) ?>">
                    <h4><?= htmlspecialchars($item[1]) ?></h4>
                    <p><?= htmlspecialchars($item[2]) ?></p>
                </div>
            <?php endforeach; ?>
            <?php foreach ($banner1_items as $item): ?>
                <div class="banner-item banner-item-clone" aria-hidden="true">
                    <img src="<?= CLIENT_ASSET ?>/images/feaban/<?= $item[0] ?>" alt="">
                    <h4><?= htmlspecialchars($item[1]) ?></h4>
                    <p><?= htmlspecialchars($item[2]) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<style>
    .realiving-banner {
        background: #ECE4D6;
        border-top: 1px solid #CBBFA9;
        border-bottom: 1px solid #CBBFA9;
        padding: 48px 0;
        overflow: hidden;
    }

    .banner-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 32px;
    }

    @media (max-width: 900px) {
        .banner-grid { grid-template-columns: repeat(2, 1fr); }
    }

    .banner-item-clone {
        display: none;
    }

    @media (max-width: 480px) {
        .banner-grid {
            display: flex;
            width: max-content;
            gap: 0;
            animation: bannerScroll 20s linear infinite;
        }
        .realiving-banner .banner-item {
            width: 100vw;
            flex: 0 0 100vw;
            box-sizing: border-box;
        }
        .banner-item-clone {
            display: block;
        }
    }

    @keyframes bannerScroll {
        0% { transform: translateX(0); }
        100% { transform: translateX(-50%); }
    }

    .realiving-banner .banner-item {
        text-align: center;
        padding: 0 12px;
    }

    .realiving-banner .banner-item img {
        width: 44px;
        height: 44px;
        object-fit: contain;
        margin: 0 auto 16px;
        opacity: 0.85;
        filter: sepia(15%) saturate(120%);
    }

    .realiving-banner .banner-item h4 {
        font-family: 'IBM Plex Mono', monospace;
        font-size: 11px;
        letter-spacing: 2.5px;
        text-transform: uppercase;
        color: #211A14;
        margin-bottom: 10px;
        font-weight: 600;
    }

    .realiving-banner .banner-item p {
        font-family: 'Work Sans', sans-serif;
        font-size: 13.5px;
        line-height: 1.6;
        color: rgba(33, 26, 20, 0.65);
    }
</style>