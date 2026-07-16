<?php
//sales_dashboard.php
session_start();
require_once __DIR__ . '/../../config/app_config.php';
include '../../connection/connection.php';
include '../design/mainbody.php';
include '../../loginpage/checkrole.php';


// Allow only admin1 to admin5
require_role(['admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'superadmin', 'sales', 'designer']);

// Get admin info for inquiry counts
$admin_id = $_SESSION['admin_id'] ?? 0;
$admin_role = $_SESSION['role'] ?? '';

// Get pending inquiry counts
// Appointments
$apt_query = ($admin_role === 'superadmin') 
    ? "SELECT COUNT(*) as count FROM appointments WHERE status='pending'" 
    : "SELECT COUNT(*) as count FROM appointments WHERE status='pending' AND assigned_to = $admin_id";
$pending_appointments = $conn->query($apt_query)->fetch_assoc()['count'] ?? 0;

// Concept Inquiries
$concept_query = ($admin_role === 'superadmin') 
    ? "SELECT COUNT(*) as count FROM concept_inquiries WHERE status='pending'" 
    : "SELECT COUNT(*) as count FROM concept_inquiries WHERE status='pending' AND assigned_to = $admin_id";
$pending_concepts = $conn->query($concept_query)->fetch_assoc()['count'] ?? 0;

// Contact Inquiries
$contact_query = ($admin_role === 'superadmin') 
    ? "SELECT COUNT(*) as count FROM contact WHERE status='pending'" 
    : "SELECT COUNT(*) as count FROM contact WHERE status='pending' AND assigned_to = $admin_id";
$pending_contacts = $conn->query($contact_query)->fetch_assoc()['count'] ?? 0;

// Project Inquiries
$project_query = ($admin_role === 'superadmin') 
    ? "SELECT COUNT(*) as count FROM project_inquiries WHERE status='pending'" 
    : "SELECT COUNT(*) as count FROM project_inquiries WHERE status='pending' AND assigned_to = $admin_id";
$pending_projects = $conn->query($project_query)->fetch_assoc()['count'] ?? 0;

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales & Marketing Dashboard - RealLiving</title>
  <link rel="icon" type="image/png" sizes="32x32" href="../../logo/favicon.ico">
  <!-- Font Awesome CDN -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <!-- Google Fonts: Inter -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root{
      --adm-bg:#F5F5F5;
      --adm-surface:#FFFFFF;
      --adm-ink:#0B0B0B;
      --adm-soft:#6B6B6B;
      --adm-muted:#9A9A9A;
      --adm-line:#E2E2E2;
    }

    body{
      font-family:'Inter', sans-serif;
      background: var(--adm-bg);
      color: var(--adm-ink);
    }

    /* ── Header ─────────────────────────────── */
    .adm-eyebrow{
      font-size:11px; font-weight:600; letter-spacing:1.5px; text-transform:uppercase;
      color: var(--adm-soft);
    }
    .adm-title{
      font-size:28px; font-weight:700; letter-spacing:-0.01em; color: var(--adm-ink);
    }
    .adm-subtitle{
      font-size:13.5px; color: var(--adm-soft);
    }

    /* ── Section label ──────────────────────── */
    .adm-section-label{
      font-size:12px; font-weight:600; color: var(--adm-ink);
      display:flex; align-items:center; gap:10px;
    }
    .adm-section-label::after{
      content:""; flex:1; height:1px; background: var(--adm-line);
    }

    /* ── Cards (Departments) ────────────────── */
    .adm-card{
      display:block;
      background: var(--adm-surface);
      border:1px solid var(--adm-line);
      border-radius:10px;
      padding:1.5rem;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }
    .adm-card:hover,
    .adm-card:focus-visible{
      border-color: var(--adm-ink);
      box-shadow: 0 10px 26px -16px rgba(11,11,11,0.25);
      transform: translateY(-2px);
      outline:none;
    }
    .adm-icon{
      width:44px; height:44px; border-radius:9px;
      background: var(--adm-bg);
      border:1px solid var(--adm-line);
      color: var(--adm-ink);
      display:flex; align-items:center; justify-content:center;
      font-size:17px;
      margin-bottom:1rem;
    }
    .adm-card-title{
      font-size:15px; font-weight:600; color: var(--adm-ink); margin-bottom:.35rem;
    }
    .adm-card-desc{
      font-size:13px; line-height:1.5; color: var(--adm-soft); margin-bottom:1.1rem;
    }
    .adm-card-link{
      font-size:12.5px; font-weight:600; color: var(--adm-ink);
      display:inline-flex; align-items:center; gap:6px;
    }
    .adm-card-link i{ font-size:10px; transition: transform .2s ease; }
    .adm-card:hover .adm-card-link i{ transform: translateX(3px); }

    /* ── Inquiry stat cards ──────────────────── */
    .adm-stat{
      position:relative;
      display:block;
      background: var(--adm-surface);
      border:1px solid var(--adm-line);
      border-radius:10px;
      padding:1.35rem 1.4rem;
      transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }
    .adm-stat:hover,
    .adm-stat:focus-visible{
      border-color: var(--adm-ink);
      box-shadow: 0 10px 26px -16px rgba(11,11,11,0.25);
      transform: translateY(-2px);
      outline:none;
    }
    .adm-stat-top{ display:flex; align-items:center; justify-content:space-between; margin-bottom:.9rem; }
    .adm-stat-icon{ font-size:15px; color: var(--adm-soft); }
    .adm-stat-badge{
      min-width:20px; height:20px; padding:0 6px; line-height:20px; text-align:center;
      border-radius:999px; background: var(--adm-ink); color:#fff;
      font-size:11px; font-weight:700;
    }
    .adm-stat-label{ font-size:12.5px; color: var(--adm-soft); margin-bottom:.15rem; }
    .adm-stat-title{ font-size:14.5px; font-weight:600; color: var(--adm-ink); margin-bottom:1rem; }
    .adm-stat-link{
      font-size:12px; font-weight:600; color: var(--adm-ink);
      display:inline-flex; align-items:center; gap:6px;
    }
    .adm-stat-link i{ font-size:9px; transition: transform .2s ease; }
    .adm-stat:hover .adm-stat-link i{ transform: translateX(3px); }

    /* ── Toast ──────────────────────────────── */
    .adm-toast{
      background:#fff;
      border-left:3px solid var(--adm-ink);
      box-shadow: 0 12px 32px -14px rgba(11,11,11,0.3);
    }

    @keyframes adm-fade{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }
    .adm-fade{ animation: adm-fade .4s ease both; }
    @media (prefers-reduced-motion: reduce){ .adm-fade{ animation:none; } }
  </style>
</head>

<body class="min-h-screen flex flex-col">

  <!-- Notification -->
  <?php if (isset($_SESSION['noti'])): ?>
    <div id="notifBox" class="adm-toast fixed top-20 right-4 rounded-lg p-4 w-80 adm-fade z-50">
      <div class="flex items-start">
        <i class="fa-solid fa-circle-info mt-0.5 mr-3 text-base" style="color:var(--adm-ink);"></i>
        <div>
          <p class="text-[10px] font-semibold uppercase tracking-[1px]" style="color:var(--adm-soft);">Notification</p>
          <p class="text-[13px] mt-1" style="color:var(--adm-ink);"><?= $_SESSION['noti']; ?></p>
        </div>
        <button onclick="this.parentElement.parentElement.remove()" class="ml-auto pl-3" style="color:var(--adm-soft);">
          <i class="fas fa-times text-xs"></i>
        </button>
      </div>
    </div>
    <script>
      setTimeout(function() {
        var notif = document.getElementById("notifBox");
        if (notif) {
          notif.classList.add('opacity-0', 'transition-opacity', 'duration-300');
          setTimeout(() => notif.remove(), 300);
        }
      }, 3000);
    </script>
    <?php unset($_SESSION['noti']); ?>
  <?php endif; ?>

  <!-- Main Content -->
  <div class="pt-10 pb-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">

    <!-- Dashboard Header -->
    <div class="mb-10 adm-fade">
      <div class="adm-eyebrow mb-2">Sales &amp; Marketing</div>
      <h1 class="adm-title">Dashboard</h1>
      <p class="adm-subtitle mt-1">Manage your content and digital presence.</p>
    </div>

    <!-- Dashboard Navigation Cards -->
    <div class="mb-10 adm-fade">
      <div class="adm-section-label mb-4">Management</div>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

        <!-- Home Management -->
        <a href="<?= BASE_URL ?>serviceshome_settings_dashboard.php" class="adm-card">
          <div class="adm-icon"><i class="fas fa-home"></i></div>
          <h3 class="adm-card-title">Home Management</h3>
          <p class="adm-card-desc">Configure homepage settings, banners, and featured content.</p>
          <span class="adm-card-link">Manage Home <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Product Management -->
        <a href="../sales_product_management/choose.php" class="adm-card">
          <div class="adm-icon"><i class="fas fa-box"></i></div>
          <h3 class="adm-card-title">Product Management</h3>
          <p class="adm-card-desc">Add, edit, and organize your product catalog.</p>
          <span class="adm-card-link">Manage Products <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Projects Management -->
        <a href="projects_dashboard.php" class="adm-card">
          <div class="adm-icon"><i class="fas fa-briefcase"></i></div>
          <h3 class="adm-card-title">Projects Management</h3>
          <p class="adm-card-desc">Showcase completed projects and portfolio items.</p>
          <span class="adm-card-link">Manage Projects <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Rooms Management -->
        <a href="gallery_dashboard_v2.php" class="adm-card">
          <div class="adm-icon"><i class="fas fa-images"></i></div>
          <h3 class="adm-card-title">Rooms Management</h3>
          <p class="adm-card-desc">Organize and display room design galleries.</p>
          <span class="adm-card-link">Manage Gallery <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Concept Management -->
        <a href="concept_dashboard.php" class="adm-card">
          <div class="adm-icon"><i class="fas fa-lightbulb"></i></div>
          <h3 class="adm-card-title">Concept Management</h3>
          <p class="adm-card-desc">Create and manage design concepts and themes.</p>
          <span class="adm-card-link">Manage Concepts <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- News Management -->
        <a href="news_dashboard.php" class="adm-card">
          <div class="adm-icon"><i class="fas fa-newspaper"></i></div>
          <h3 class="adm-card-title">News Management</h3>
          <p class="adm-card-desc">Publish and manage company news and updates.</p>
          <span class="adm-card-link">Manage News <i class="fas fa-arrow-right"></i></span>
        </a>

      </div>
    </div>

    <!-- Inquiry Management Section -->
    <div class="adm-fade">
      <div class="adm-section-label mb-4">Inquiries</div>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">

        <!-- Appointment Inquiries -->
        <a href="../sales_inquiry_management/appointment_dashboard.php" class="adm-stat">
          <div class="adm-stat-top">
            <i class="fas fa-calendar-check adm-stat-icon"></i>
            <span class="adm-stat-badge" id="badge-appointments" style="display: <?php echo $pending_appointments > 0 ? 'block' : 'none'; ?>"><?php echo $pending_appointments; ?></span>
          </div>
          <div class="adm-stat-label">Appointments</div>
          <div class="adm-stat-title">Bookings &amp; scheduling</div>
          <span class="adm-stat-link">Manage <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Concept Inquiries -->
        <a href="../sales_inquiry_management/concept_inquiries_dashboard.php" class="adm-stat">
          <div class="adm-stat-top">
            <i class="fas fa-palette adm-stat-icon"></i>
            <span class="adm-stat-badge" id="badge-concepts" style="display: <?php echo $pending_concepts > 0 ? 'block' : 'none'; ?>"><?php echo $pending_concepts; ?></span>
          </div>
          <div class="adm-stat-label">Concept Inquiries</div>
          <div class="adm-stat-title">Customization requests</div>
          <span class="adm-stat-link">Manage <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Contact Inquiries -->
        <a href="../sales_inquiry_management/contact_dashboard.php" class="adm-stat">
          <div class="adm-stat-top">
            <i class="fas fa-envelope-open-text adm-stat-icon"></i>
            <span class="adm-stat-badge" id="badge-contacts" style="display: <?php echo $pending_contacts > 0 ? 'block' : 'none'; ?>"><?php echo $pending_contacts; ?></span>
          </div>
          <div class="adm-stat-label">Contact Inquiries</div>
          <div class="adm-stat-title">General submissions</div>
          <span class="adm-stat-link">Manage <i class="fas fa-arrow-right"></i></span>
        </a>

        <!-- Project Inquiries -->
        <a href="../sales_inquiry_management/project_inquiries_dashboard.php" class="adm-stat">
          <div class="adm-stat-top">
            <i class="fas fa-building adm-stat-icon"></i>
            <span class="adm-stat-badge" id="badge-projects" style="display: <?php echo $pending_projects > 0 ? 'block' : 'none'; ?>"><?php echo $pending_projects; ?></span>
          </div>
          <div class="adm-stat-label">Project Inquiries</div>
          <div class="adm-stat-title">Showcase page leads</div>
          <span class="adm-stat-link">Manage <i class="fas fa-arrow-right"></i></span>
        </a>

      </div>
    </div>

  </div>

<script>
// Real-time inquiry count update
function updateInquiryCounts() {
  console.log('Fetching inquiry counts...'); // Debug log
  
  fetch('get_inquiry_counts.php')
    .then(response => {
      console.log('Response status:', response.status); // Debug log
      if (!response.ok) {
        throw new Error('Network response was not ok');
      }
      return response.json();
    })
    .then(data => {
      console.log('Received data:', data); // Debug log
      
      // Check if data has error
      if (data.error) {
        console.error('API Error:', data.error);
        return;
      }
      
      // Update Appointments badge
      const appointmentsBadge = document.getElementById('badge-appointments');
      if (appointmentsBadge) {
        if (data.appointments > 0) {
          appointmentsBadge.textContent = data.appointments;
          appointmentsBadge.style.display = 'block';
          console.log('Updated appointments:', data.appointments);
        } else {
          appointmentsBadge.style.display = 'none';
        }
      }

      // Update Concepts badge
      const conceptsBadge = document.getElementById('badge-concepts');
      if (conceptsBadge) {
        if (data.concepts > 0) {
          conceptsBadge.textContent = data.concepts;
          conceptsBadge.style.display = 'block';
          console.log('Updated concepts:', data.concepts);
        } else {
          conceptsBadge.style.display = 'none';
        }
      }

      // Update Contacts badge
      const contactsBadge = document.getElementById('badge-contacts');
      if (contactsBadge) {
        if (data.contacts > 0) {
          contactsBadge.textContent = data.contacts;
          contactsBadge.style.display = 'block';
          console.log('Updated contacts:', data.contacts);
        } else {
          contactsBadge.style.display = 'none';
        }
      }

      // Update Projects badge
      const projectsBadge = document.getElementById('badge-projects');
      if (projectsBadge) {
        if (data.projects > 0) {
          projectsBadge.textContent = data.projects;
          projectsBadge.style.display = 'block';
          console.log('Updated projects:', data.projects);
        } else {
          projectsBadge.style.display = 'none';
        }
      }
    // Also update navbar badges if they exist
      const navBadge = document.querySelector('.nav-badge');
      if (navBadge) {
        const totalPending = data.appointments + data.concepts + data.contacts + data.projects;
        if (totalPending > 0) {
          navBadge.textContent = totalPending;
          navBadge.style.display = 'inline-block';
        } else {
          navBadge.style.display = 'none';
        }
      }

      // Update mobile badge if exists
      const mobileDropdownButton = document.querySelector('[data-target="inquiryMobileDropdown"]');
      if (mobileDropdownButton) {
        const mobileBadge = mobileDropdownButton.querySelector('.rounded-full');
        if (mobileBadge) {
          const totalPending = data.appointments + data.concepts + data.contacts + data.projects;
          if (totalPending > 0) {
            mobileBadge.textContent = totalPending;
            mobileBadge.style.display = 'inline-flex';
          } else {
            mobileBadge.style.display = 'none';
          }
        }
      }
    })
    .catch(error => {
      console.error('Error fetching inquiry counts:', error);
    });
}

// Test immediately on page load
console.log('Page loaded, testing update...');
updateInquiryCounts();

// Update counts every 10 seconds (10000 milliseconds)
setInterval(updateInquiryCounts, 10000);

// Also update when page becomes visible again (user switches back to tab)
document.addEventListener('visibilitychange', function() {
  if (!document.hidden) {
    console.log('Tab visible again, updating...');
    updateInquiryCounts();
  }
});
</script>
</body>

</html>