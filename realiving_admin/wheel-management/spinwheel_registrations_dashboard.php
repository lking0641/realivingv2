<?php
//spinwheel_registrations_dashboard.php
include $includes ['mainbody'];

// Allow sales-related roles
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales', 'designer']);

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if (!empty($search)) {
  $search_esc = $conn->real_escape_string($search);
  $query = "SELECT * FROM spinwheel_registrations 
            WHERE full_name LIKE '%$search_esc%' 
               OR email LIKE '%$search_esc%' 
               OR phone LIKE '%$search_esc%' 
               OR company_name LIKE '%$search_esc%'
               OR position LIKE '%$search_esc%'
            ORDER BY created_at DESC";
} else {
  $query = "SELECT * FROM spinwheel_registrations ORDER BY created_at DESC";
}

$result = $conn->query($query);
$total_count = $result->num_rows;

// ── Spin to Win promo status ──
$spinwheel_status = $conn->query("SELECT is_active FROM spinwheel_settings WHERE id = 1")->fetch_assoc();
$spinwheel_active = $spinwheel_status && $spinwheel_status['is_active'] == 1;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Spin to Win Registrations - RealLiving</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>logo/favicon.ico">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Poppins', sans-serif;
    }
    .table-row:hover {
      background-color: #f8fafc;
    }
    .badge-gmail {
      background: linear-gradient(135deg, #ea4335 0%, #c5221f 100%);
    }
  </style>
</head>

<body class="bg-gradient-to-br from-slate-50 to-gray-100 min-h-screen flex flex-col">

  <!-- Main Content -->
  <div class="pt-10 pb-10 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">

    <!-- Header -->
    <div class="mb-8">
      <div class="flex items-center justify-between flex-wrap gap-4 mb-3">
        <div class="flex items-center">
          <div class="h-12 w-1 bg-[#c4905c] rounded-full mr-4"></div>
          <div>
            <h1 class="text-3xl font-bold text-gray-900">Spin to Win Registrations</h1>
            <p class="text-sm text-gray-600 mt-1">View and search all promo registrations</p>
          </div>
        </div>

        <!-- Scanner Button -->
        <a href="spinwheel-claim-scanner"
           class="inline-flex items-center gap-2 px-4 py-3 bg-gradient-to-r from-[#c4905c] to-[#2f1200] text-white rounded-xl font-semibold text-sm hover:opacity-90 transition-opacity shadow-sm">
          <i class="fas fa-qrcode"></i> Prize Claim Scanner
        </a>

        <!-- Promo Toggle -->
        <form method="POST" action="<?BASE_URL ?>toggle-spinwheel" class="flex items-center gap-3 bg-white px-4 py-3 rounded-xl shadow-sm border border-gray-100">
          <span class="text-sm font-semibold <?php echo $spinwheel_active ? 'text-green-600' : 'text-gray-400'; ?>">
            <?php echo $spinwheel_active ? 'Promo Active' : 'Promo Inactive'; ?>
          </span>
          <label class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox" name="is_active" class="sr-only peer"
              <?php echo $spinwheel_active ? 'checked' : ''; ?>
              onchange="this.form.submit()">
            <div class="w-11 h-6 bg-gray-300 rounded-full peer peer-checked:bg-green-500 transition-colors"></div>
            <div class="absolute left-1 top-1 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-5"></div>
          </label>
        </form>
      </div>
    </div>

    <!-- Stats Card -->
    <div class="mb-6">
      <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100 inline-flex items-center gap-4">
        <div class="h-14 w-14 bg-gradient-to-br from-[#c4905c] to-[#2f1200] rounded-xl flex items-center justify-center shadow-lg">
          <i class="fas fa-users text-white text-2xl"></i>
        </div>
        <div>
          <p class="text-2xl font-bold text-gray-900"><?php echo $total_count; ?></p>
          <p class="text-sm text-gray-600"><?php echo !empty($search) ? 'Search Results' : 'Total Registrations'; ?></p>
        </div>
      </div>
    </div>

    <!-- Wheel Segments Settings -->
    <details class="mb-6 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden" id="wheelSettings">
      <summary class="px-6 py-4 cursor-pointer font-semibold text-gray-700 flex items-center gap-2 select-none">
        <i class="fas fa-cog text-[#c4905c]"></i> Wheel Segment Settings
        <span class="ml-auto text-xs text-gray-400">click to expand</span>
      </summary>
      <div class="px-6 pb-6 border-t border-gray-100">
        <p class="text-xs text-gray-500 mt-3 mb-4">Manage the prizes on the spin wheel. Higher probability = more likely to land.</p>

        <!-- Existing segments -->
        <?php
        $segs = $conn->query("SELECT * FROM spinwheel_segments ORDER BY id ASC");
        while ($seg = $segs->fetch_assoc()):
        ?>
        <form method="POST" action="<?= BASE_URL ?>update-spinwheel-segment" class="flex items-center gap-3 mb-2 flex-wrap">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="segment_id" value="<?= $seg['id'] ?>">
          <input type="text" name="label" value="<?= htmlspecialchars($seg['label']) ?>"
            class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-36 focus:outline-none focus:border-[#c4905c]"
            placeholder="Prize label">
          <input type="color" name="color" value="<?= $seg['color'] ?>"
            class="w-10 h-9 border border-gray-200 rounded-lg cursor-pointer"
            title="Segment color">
          <input type="number" name="probability" value="<?= $seg['probability'] ?>" min="1" max="100"
            class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-20 focus:outline-none focus:border-[#c4905c]"
            placeholder="Weight">
          <label class="flex items-center gap-1 text-sm text-gray-600 cursor-pointer">
            <input type="checkbox" name="is_active" <?= $seg['is_active'] ? 'checked' : '' ?> class="accent-[#2f1200]"> Active
          </label>
          <button type="submit"
            class="px-4 py-2 bg-[#2f1200] text-white rounded-lg text-xs font-semibold hover:opacity-80">
            <i class="fas fa-save mr-1"></i>Save
          </button>
          <button type="submit" name="action" value="delete"
            onclick="return confirm('Delete this prize segment?')"
            class="px-4 py-2 bg-red-100 text-red-600 rounded-lg text-xs font-semibold hover:bg-red-200 transition-colors">
            <i class="fas fa-trash mr-1"></i>Delete
          </button>
        </form>
        <?php endwhile; ?>

        <!-- Add new segment -->
        <div class="mt-4 pt-4 border-t border-gray-100">
          <p class="text-xs font-semibold text-gray-500 mb-3 uppercase tracking-wide">
            <i class="fas fa-plus text-[#c4905c] mr-1"></i>Add New Prize
          </p>
          <form method="POST" action="<?= BASE_URL ?>update-spinwheel-segment" class="flex items-center gap-3 flex-wrap">
            <input type="hidden" name="action" value="insert">
            <input type="text" name="label" required
              class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-36 focus:outline-none focus:border-[#c4905c]"
              placeholder="Prize label">
            <input type="color" name="color" value="#c4905c"
              class="w-10 h-9 border border-gray-200 rounded-lg cursor-pointer"
              title="Segment color">
            <input type="number" name="probability" value="10" min="1" max="100" required
              class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-20 focus:outline-none focus:border-[#c4905c]"
              placeholder="Weight">
            <label class="flex items-center gap-1 text-sm text-gray-600 cursor-pointer">
              <input type="checkbox" name="is_active" checked class="accent-[#2f1200]"> Active
            </label>
            <button type="submit"
              class="px-4 py-2 bg-gradient-to-r from-[#c4905c] to-[#2f1200] text-white rounded-lg text-xs font-semibold hover:opacity-90 transition-opacity">
              <i class="fas fa-plus mr-1"></i>Add Prize
            </button>
          </form>
        </div>
      </div>
    </details>

    <!-- Pity System Settings -->
<details class="mb-6 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden" id="pitySettings">
  <summary class="px-6 py-4 cursor-pointer font-semibold text-gray-700 flex items-center gap-2 select-none">
    <i class="fas fa-heart text-[#c4905c]"></i> Pity System Settings
    <span class="ml-auto text-xs text-gray-400">click to expand</span>
  </summary>
  <div class="px-6 pb-6 border-t border-gray-100">
    <p class="text-xs text-gray-500 mt-3 mb-1">Set guaranteed win thresholds per prize. <strong>0 = no pity</strong>. Every X spins with no winner = guaranteed win.</p>

    <!-- Global spin counter display -->
    <?php
    $gc = $conn->query("SELECT total_spins FROM spinwheel_global_counter WHERE id=1")->fetch_assoc();
    $total_spins = $gc['total_spins'] ?? 0;
    ?>
    <div class="flex items-center gap-3 bg-gray-50 rounded-lg px-4 py-3 mb-4 mt-3">
      <i class="fas fa-rotate text-[#c4905c]"></i>
      <span class="text-sm font-semibold text-gray-700">Total Spins So Far: <strong class="text-[#2f1200]"><?= $total_spins ?></strong></span>
      <form method="POST" action="<?= BASE_URL ?>update-pity-settings" class="ml-auto">
        <input type="hidden" name="action" value="reset_all">
        <button type="submit" onclick="return confirm('Reset all pity counters and global spin count? This cannot be undone.')"
          class="px-3 py-1.5 bg-red-100 text-red-600 rounded-lg text-xs font-semibold hover:bg-red-200 transition-colors">
          <i class="fas fa-rotate-left mr-1"></i>Reset All Counters
        </button>
      </form>
    </div>

    <!-- Existing pity settings -->
    <?php
    $pity_rows = $conn->query("SELECT * FROM spinwheel_pity_settings ORDER BY pity_threshold ASC");
    while ($pity = $pity_rows->fetch_assoc()):
    ?>
    <form method="POST" action="<?= BASE_URL ?>update-pity-settings" class="flex items-center gap-3 mb-2 flex-wrap bg-gray-50 rounded-lg px-3 py-2">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="pity_id" value="<?= $pity['id'] ?>">
      <input type="text" name="prize_label" value="<?= htmlspecialchars($pity['prize_label']) ?>"
        class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-44 focus:outline-none focus:border-[#c4905c]"
        placeholder="Prize label (must match wheel)">
      <div class="flex items-center gap-2">
        <label class="text-xs text-gray-500 font-semibold">Every</label>
        <input type="number" name="pity_threshold" value="<?= $pity['pity_threshold'] ?>" min="0" max="9999"
          class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-20 focus:outline-none focus:border-[#c4905c]"
          placeholder="0 = off">
        <label class="text-xs text-gray-500 font-semibold">spins</label>
      </div>
      <div class="text-xs text-gray-400">
        Window: <strong><?= $pity['current_window_count'] ?></strong> / <?= $pity['pity_threshold'] ?> &nbsp;|&nbsp;
        Won: <strong class="<?= $pity['window_won'] ? 'text-green-600' : 'text-gray-400' ?>"><?= $pity['window_won'] ? 'YES ✓' : 'No' ?></strong>
      </div>
      <button type="submit"
        class="px-4 py-2 bg-[#2f1200] text-white rounded-lg text-xs font-semibold hover:opacity-80">
        <i class="fas fa-save mr-1"></i>Save
      </button>
      <button type="submit" name="action" value="delete"
        onclick="return confirm('Delete this pity rule?')"
        class="px-4 py-2 bg-red-100 text-red-600 rounded-lg text-xs font-semibold hover:bg-red-200">
        <i class="fas fa-trash mr-1"></i>Delete
      </button>
    </form>
    <?php endwhile; ?>

    <!-- Add new pity rule -->
    <div class="mt-4 pt-4 border-t border-gray-100">
      <p class="text-xs font-semibold text-gray-500 mb-3 uppercase tracking-wide">
        <i class="fas fa-plus text-[#c4905c] mr-1"></i>Add New Pity Rule
      </p>
      <form method="POST" action="<?= BASE_URL ?>update-pity-settings" class="flex items-center gap-3 flex-wrap">
        <input type="hidden" name="action" value="insert">
        <input type="text" name="prize_label" required
          class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-44 focus:outline-none focus:border-[#c4905c]"
          placeholder="Prize label (must match wheel)">
        <div class="flex items-center gap-2">
          <label class="text-xs text-gray-500 font-semibold">Every</label>
          <input type="number" name="pity_threshold" value="25" min="1" max="9999" required
            class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-20 focus:outline-none focus:border-[#c4905c]">
          <label class="text-xs text-gray-500 font-semibold">spins</label>
        </div>
        <button type="submit"
          class="px-4 py-2 bg-gradient-to-r from-[#c4905c] to-[#2f1200] text-white rounded-lg text-xs font-semibold hover:opacity-90">
          <i class="fas fa-plus mr-1"></i>Add Rule
        </button>
      </form>
    </div>
  </div>
</details>

    <!-- Search Bar -->
    <div class="mb-6">
      <form method="GET" action="" class="flex gap-3">
        <div class="relative flex-1">
          <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
          <input type="text" name="search" placeholder="Search by name, email, phone, company, or position..."
            value="<?php echo htmlspecialchars($search); ?>"
            class="w-full pl-11 pr-4 py-3 border-2 border-gray-200 rounded-xl text-sm focus:outline-none focus:border-[#c4905c] transition-colors">
        </div>
        <button type="submit" class="px-6 py-3 bg-gradient-to-r from-[#c4905c] to-[#2f1200] text-white rounded-xl font-semibold text-sm hover:opacity-90 transition-opacity">
          <i class="fas fa-search mr-2"></i>Search
        </button>
        <?php if (!empty($search)): ?>
          <a href="<?= BASE_URL ?>spinwheel-registrations-dashboard" class="px-6 py-3 bg-gray-100 text-gray-700 rounded-xl font-semibold text-sm hover:bg-gray-200 transition-colors flex items-center">
            <i class="fas fa-times mr-2"></i>Clear
          </a>
        <?php endif; ?>
      </form>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="px-6 py-4 text-left font-semibold text-gray-700">#</th>
              <th class="px-6 py-4 text-left font-semibold text-gray-700">Name</th>
              <th class="px-6 py-4 text-left font-semibold text-gray-700">Phone</th>
              <th class="px-6 py-4 text-left font-semibold text-gray-700">Email</th>
              <th class="px-6 py-4 text-left font-semibold text-gray-700">Company</th>
              <th class="px-6 py-4 text-left font-semibold text-gray-700">Position</th>
              <th class="px-6 py-4 text-left font-semibold text-gray-700">Registered On</th>
<th class="px-6 py-4 text-left font-semibold text-gray-700">Spin Status</th>
<th class="px-6 py-4 text-left font-semibold text-gray-700">Prize</th>
<th class="px-6 py-4 text-left font-semibold text-gray-700">Claim Status</th>
<th class="px-6 py-4 text-left font-semibold text-gray-700">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php if ($result && $result->num_rows > 0): ?>
              <?php $i = 1; while ($row = $result->fetch_assoc()): ?>
                <tr class="table-row transition-colors">
                  <td class="px-6 py-4 text-gray-500"><?php echo $i++; ?></td>
                  <td class="px-6 py-4 font-medium text-gray-900"><?php echo htmlspecialchars($row['full_name']); ?></td>
                  <td class="px-6 py-4 text-gray-600">
                    <i class="fas fa-phone text-gray-400 mr-1"></i><?php echo htmlspecialchars($row['phone']); ?>
                  </td>
                  <td class="px-6 py-4">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium badge-gmail text-white">
                      <i class="fab fa-google"></i> <?php echo htmlspecialchars($row['email']); ?>
                    </span>
                  </td>
                  <td class="px-6 py-4 text-gray-600">
                    <i class="fas fa-building text-gray-400 mr-1"></i><?php echo htmlspecialchars($row['company_name']); ?>
                  </td>
                  <td class="px-6 py-4 text-gray-600">
                    <i class="fas fa-id-badge text-gray-400 mr-1"></i><?php echo htmlspecialchars($row['position']); ?>
                  </td>
                  <td class="px-6 py-4 text-gray-500 text-xs">
                    <?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?>
                  </td>
                  <td class="px-6 py-4">
                    <?php if ($row['has_spun']): ?>
                      <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">
                        <i class="fas fa-check-circle"></i> Spun
                      </span>
                    <?php else: ?>
                      <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-semibold">
                        <i class="fas fa-clock"></i> Pending
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="px-6 py-4 text-gray-600 text-xs">
                    <?php echo $row['spin_result'] ? htmlspecialchars($row['spin_result']) : '—'; ?>
                  </td>
                  <td class="px-6 py-4">
                    <?php if ($row['has_spun']): ?>
                      <?php if ($row['is_claimed']): ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">
                          <i class="fas fa-check-double"></i> Claimed
                        </span>
                        <div class="text-xs text-gray-400 mt-1"><?= date('M d, h:i A', strtotime($row['claimed_at'])) ?></div>
                      <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-orange-100 text-orange-700 rounded-full text-xs font-semibold">
                          <i class="fas fa-gift"></i> Unclaimed
                        </span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-gray-300 text-xs">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-6 py-4">
                    <a href="<?= BASE_URL ?>delete-spinwheel/<?php echo $row['id']; ?>"
                       onclick="return confirm('Are you sure you want to delete this registration?')"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-100 text-red-600 rounded-lg text-xs font-semibold hover:bg-red-200 transition-colors">
                      <i class="fas fa-trash"></i> Delete
                    </a>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                  <i class="fas fa-inbox text-4xl mb-3 block"></i>
                  <?php echo !empty($search) ? 'No results found for "' . htmlspecialchars($search) . '"' : 'No registrations yet.'; ?>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <script>
  // Wheel settings persistence
  const details = document.getElementById('wheelSettings');
  if (sessionStorage.getItem('wheelSettingsOpen') === 'true') details.open = true;
  details.addEventListener('toggle', () => sessionStorage.setItem('wheelSettingsOpen', details.open));
  details.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', () => sessionStorage.setItem('wheelSettingsOpen', 'true'));
  });

  // Pity settings persistence
  const pityDetails = document.getElementById('pitySettings');
  if (sessionStorage.getItem('pitySettingsOpen') === 'true') pityDetails.open = true;
  pityDetails.addEventListener('toggle', () => sessionStorage.setItem('pitySettingsOpen', pityDetails.open));
  pityDetails.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', () => sessionStorage.setItem('pitySettingsOpen', 'true'));
  });
</script>

</body>

</html>