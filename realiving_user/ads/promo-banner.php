<?php
//promo-banner.php
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Document</title>
  <link rel="stylesheet" href="<?= CLIENT_ASSET ?>/ads/promo-banner.css" />
</head>

<body>
  <?php
  // Include connection if not already included
  if (!isset($conn)) {
    include '../connection/connection.php';
  }

  // Fetch active ads banner
  $ads_query = "SELECT * FROM ads_banner WHERE is_active = 1 LIMIT 1";
  $ads_result = $conn->query($ads_query);

  if ($ads_result->num_rows > 0) {
    $ads = $ads_result->fetch_assoc();
    $stored_filepath = $ads['filepath'];

    // DYNAMIC FILEPATH LOGIC
    // Check if we're in index.php (root) or subfolder
    $current_file = basename($_SERVER['PHP_SELF']);
    $current_dir = basename(dirname($_SERVER['PHP_SELF']));

    if ($current_file === 'index.php' && $current_dir === 'realiving_user') {
      // We're in /realiving_user/index.php
      $ads_image = htmlspecialchars($stored_filepath);
    } else {
      // We're in a subfolder like /cabinet/product.php or /about/about.php
      // Extract just the filename from the stored path
      $filename = basename($stored_filepath);
      $ads_image = '/images/ads_banner/' . htmlspecialchars($filename);
    }
  } else {
    // Fallback image with dynamic path
    $current_file = basename($_SERVER['PHP_SELF']);
    $current_dir = basename(dirname($_SERVER['PHP_SELF']));

    if ($current_file === 'index.php' && $current_dir === 'realiving_user') {
      $ads_image = '/images/background-image3.jpg';
    } else {
      $ads_image = '/images/background-image3.jpg';
    }
  }
  ?>

  <section class="promo-banner">
    <div class="promo-banner__content">
      <img src="<?= CLIENT_ASSET ?><?php echo $ads_image; ?>" alt="Promo Banner" class="promo-banner__image">
    </div>
  </section>
</body>

</html>