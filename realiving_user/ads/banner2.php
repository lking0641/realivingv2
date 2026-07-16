<!-- banner2.php -->
<?php
$banner2_items = [
    ['design.png', 'Design Consultation', 'Expert advice to guide your vision from concept to completion.'],
    ['install.png', 'Installation Services', 'Professional installation for seamless setup.'],
    ['swatch.png', 'Material Samples', 'Experience finishes and textures before you decide.'],
    ['quality.png', 'Certified Quality', 'Commitment to craftsmanship and international standards.'],
];
?>
<section class="realiving-banner realiving-banner-alt">
    <div class="max-w-6xl mx-auto px-6">
        <div class="banner-grid">
            <?php foreach ($banner2_items as $item): ?>
                <div class="banner-item">
                    <img src="<?= CLIENT_ASSET ?>/images/feaban/<?= $item[0] ?>" alt="<?= htmlspecialchars($item[1]) ?>">
                    <h4><?= htmlspecialchars($item[1]) ?></h4>
                    <p><?= htmlspecialchars($item[2]) ?></p>
                </div>
            <?php endforeach; ?>
            <?php foreach ($banner2_items as $item): ?>
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
    .realiving-banner-alt {
        background: #F7F2E9;
        border-top: none;
    }
</style>