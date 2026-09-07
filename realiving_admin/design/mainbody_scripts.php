<script>
  document.addEventListener('DOMContentLoaded', function () {
    const mobileMenuButton = document.getElementById('mobileMenuButton');
    const closeMenu = document.getElementById('closeMenu');
    const mobileMenu = document.getElementById('mobileMenu');
    const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

    function openMobileMenu() {
      mobileMenu.classList.add('active');
      mobileMenuOverlay.style.display = 'block';
      setTimeout(() => mobileMenuOverlay.classList.add('active'), 10);
      document.body.style.overflow = 'hidden';
    }

    function closeMobileMenu() {
      mobileMenu.classList.remove('active');
      mobileMenuOverlay.classList.remove('active');
      setTimeout(() => { mobileMenuOverlay.style.display = 'none'; }, 300);
      document.body.style.overflow = '';
    }

    if (mobileMenuButton) mobileMenuButton.addEventListener('click', openMobileMenu);
    if (closeMenu) closeMenu.addEventListener('click', closeMobileMenu);
    if (mobileMenuOverlay) mobileMenuOverlay.addEventListener('click', closeMobileMenu);

    // Profile dropdown
    const profileButton = document.getElementById('profileButton');
    const profileDropdown = document.getElementById('profileDropdown');
    if (profileButton && profileDropdown) {
      profileButton.addEventListener('click', function (e) {
        e.stopPropagation();
        profileDropdown.classList.toggle('hidden');
      });
    }

    // Mobile dropdowns
    document.querySelectorAll('.mobile-dropdown-button').forEach(button => {
      button.addEventListener('click', function (e) {
        e.preventDefault();
        const targetId = this.dataset.target;
        const dropdownContent = document.getElementById(targetId);
        const arrow = this.querySelector('.mobile-dropdown-arrow');
        if (dropdownContent && arrow) {
          dropdownContent.classList.toggle('show');
          arrow.classList.toggle('rotated');
        }
      });
    });

    // Close on outside click
    document.addEventListener('click', function (e) {
      if (profileDropdown && profileButton && !profileButton.contains(e.target) && !profileDropdown.contains(e.target)) {
        profileDropdown.classList.add('hidden');
      }
      document.querySelectorAll('.dropdown').forEach(dropdown => {
        if (!dropdown.parentElement.contains(e.target)) {
          dropdown.classList.remove('show');
        }
      });
    });

    // Escape key
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeMobileMenu();
        if (profileDropdown) profileDropdown.classList.add('hidden');
        document.querySelectorAll('.dropdown').forEach(dd => dd.classList.remove('show'));
      }
    });
  });

  function toggleDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    if (dropdown) {
      dropdown.classList.toggle('show');
      document.querySelectorAll('.dropdown').forEach(dd => {
        if (dd.id !== dropdownId) dd.classList.remove('show');
      });
    }
  }

  // ── Account Settings Modal ──────────────────────────────────────
  function openAccountSettings() {
    // Close profile dropdown first
    const profileDropdown = document.getElementById('profileDropdown');
    if (profileDropdown) profileDropdown.classList.add('hidden');

    document.getElementById('account-settings-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    switchSettingsTab('profile');
    loadAccountData();
  }

  function closeAccountSettings() {
    document.getElementById('account-settings-modal').classList.add('hidden');
    document.body.style.overflow = '';
    document.getElementById('settings-current-password').value = '';
    document.getElementById('settings-password').value = '';
    document.getElementById('settings-confirm-password').value = '';
    switchSettingsTab('profile');
  }

  // ── Account Settings Tabs ────────────────────────────────────────
  function switchSettingsTab(tab) {
    document.querySelectorAll('.settings-tab-btn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    document.querySelectorAll('.settings-tab-panel').forEach(panel => {
      panel.classList.toggle('hidden', panel.dataset.tabPanel !== tab);
    });
    // Scroll the panel container back to top on tab switch
    const scrollArea = document.querySelector('#account-settings-modal .overflow-y-auto');
    if (scrollArea) scrollArea.scrollTop = 0;
  }

  function togglePassVis(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (input.type === 'password') {
      input.type = 'text';
      icon.className = 'ri-eye-line text-lg';
    } else {
      input.type = 'password';
      icon.className = 'ri-eye-off-line text-lg';
    }
  }

  function previewSignature(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.type !== 'image/png') {
      showSettingsAlert('Only PNG files are allowed for e-signature.', 'error');
      input.value = '';
      return;
    }
    document.getElementById('sig-filename').textContent = file.name;
    const reader = new FileReader();
    reader.onload = e => {
      const wrap = document.getElementById('sig-preview-wrap');
      document.getElementById('sig-preview-img').src = e.target.result;
      wrap.classList.remove('hidden');
    };
    reader.readAsDataURL(file);
  }

  function previewPlatformQr(input, platform) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
      document.getElementById(platform + '-qr-preview').src = e.target.result;
      document.getElementById(platform + '-qr-preview').classList.remove('hidden');
      document.getElementById(platform + '-qr-preview-fallback').classList.add('hidden');
      document.getElementById(platform + '-qr-remove').classList.remove('hidden');
    };
    reader.readAsDataURL(file);
  }

  function removePlatformQr(platform) {
    if (!confirm('Remove this QR code image?')) return;

    fetch('<?php echo BASE_URL ?>delete-team-qr', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'platform=' + encodeURIComponent(platform)
    })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          document.getElementById(platform + '-qr-preview').src = '';
          document.getElementById(platform + '-qr-preview').classList.add('hidden');
          document.getElementById(platform + '-qr-preview-fallback').classList.remove('hidden');
          document.getElementById(platform + '-qr-remove').classList.add('hidden');
          document.getElementById(platform + '-qr-upload').value = '';
          showSettingsAlert(platform === 'wechat' ? 'WeChat QR removed.' : 'Viber QR removed.', 'success');
        } else {
          showSettingsAlert(data.message || 'Failed to remove QR code.', 'error');
        }
      })
      .catch(() => showSettingsAlert('Server error. Please try again.', 'error'));
  }

  function previewAvatar(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
      document.getElementById('avatar-preview').src = e.target.result;
      document.getElementById('avatar-preview').classList.remove('hidden');
      document.getElementById('avatar-preview-fallback').classList.add('hidden');
      document.querySelector('input[name="avatar_source"][value="custom"]').checked = true;
      document.getElementById('avatar-source-choice').classList.remove('hidden');
    };
    reader.readAsDataURL(file);
  }

  // Cache the raw Google/uploaded URLs so switching the radio can preview
  // instantly without another server round-trip.
  let cachedGooglePicture = null;
  let cachedProfilePicture = null;

  function loadAccountData() {
    fetch('<?php echo BASE_URL ?>get-account')
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          document.getElementById('settings-fullname').value = data.full_name || '';
          document.getElementById('settings-email').value = data.email || '';
          document.getElementById('modal-display-name').textContent = data.full_name || 'User';
          if (data.e_signature) {
            document.getElementById('sig-preview-img').src = data.e_signature;
            document.getElementById('sig-preview-wrap').classList.remove('hidden');
          }

          document.getElementById('settings-show-card').checked = !!Number(data.show_team_card);
          document.getElementById('settings-position').value = data.position || '';
          document.getElementById('settings-contact-number').value = data.contact_number || '';
          document.getElementById('settings-social-gmail').value = data.social_gmail || '';
          document.getElementById('settings-social-wechat').value = data.social_wechat || '';
          document.getElementById('settings-social-viber').value = data.social_viber || '';
          if (data.wechat_qr_image) {
            document.getElementById('wechat-qr-preview').src = data.wechat_qr_image;
            document.getElementById('wechat-qr-preview').classList.remove('hidden');
            document.getElementById('wechat-qr-preview-fallback').classList.add('hidden');
            document.getElementById('wechat-qr-remove').classList.remove('hidden');
          } else {
            document.getElementById('wechat-qr-remove').classList.add('hidden');
          }
          if (data.viber_qr_image) {
            document.getElementById('viber-qr-preview').src = data.viber_qr_image;
            document.getElementById('viber-qr-preview').classList.remove('hidden');
            document.getElementById('viber-qr-preview-fallback').classList.add('hidden');
            document.getElementById('viber-qr-remove').classList.remove('hidden');
          } else {
            document.getElementById('viber-qr-remove').classList.add('hidden');
          }

          cachedGooglePicture = data.google_picture || null;
          cachedProfilePicture = data.profile_picture || null;

          if (data.avatar_url) {
            document.getElementById('avatar-preview').src = data.avatar_url;
            document.getElementById('avatar-preview').classList.remove('hidden');
            document.getElementById('avatar-preview-fallback').classList.add('hidden');
          }
          if (data.google_linked) {
            document.getElementById('avatar-source-choice').classList.remove('hidden');
            const radio = document.querySelector(`input[name="avatar_source"][value="${data.avatar_source}"]`);
            if (radio) radio.checked = true;
          } else {
            document.getElementById('avatar-source-choice').classList.add('hidden');
          }

          document.getElementById('modal-role-badge').textContent =
            (data.role || 'admin').replace(/_/g, ' ').toUpperCase();

          renderGoogleLinkStatus(data.google_linked, data.google_email);
        } else {
          showSettingsAlert('Could not load account data.', 'error');
        }
      })
      .catch(() => showSettingsAlert('Failed to connect to server.', 'error'));
  }

  // Live-preview instantly when the user switches the radio — before Save is clicked.
  // This only updates the modal preview; the actual header/mobile avatars update on Save,
  // same as how choosing a new photo file only previews locally until you save.
  document.querySelectorAll('input[name="avatar_source"]').forEach(radio => {
    radio.addEventListener('change', function () {
      const url = this.value === 'google' ? cachedGooglePicture : cachedProfilePicture;
      const preview = document.getElementById('avatar-preview');
      const fallback = document.getElementById('avatar-preview-fallback');

      if (url) {
        preview.src = url;
        preview.classList.remove('hidden');
        fallback.classList.add('hidden');
      } else {
        preview.classList.add('hidden');
        fallback.classList.remove('hidden');
      }
    });
  });

  function renderGoogleLinkStatus(isLinked, email) {
    const label = document.getElementById('google-link-label');
    const emailEl = document.getElementById('google-link-email');
    const btn = document.getElementById('google-link-btn');

    if (isLinked) {
      label.textContent = 'Google account linked';
      emailEl.textContent = email || '';
      btn.textContent = 'Unlink';
      btn.className = 'text-xs font-bold px-3 py-1.5 rounded-lg transition-colors bg-red-50 text-red-600 hover:bg-red-100';
      btn.dataset.linked = '1';
    } else {
      label.textContent = 'Not linked';
      emailEl.textContent = 'Link your Google account to sign in without a password';
      btn.textContent = 'Link';
      btn.className = 'text-xs font-bold px-3 py-1.5 rounded-lg transition-colors bg-blue-50 text-blue-600 hover:bg-blue-100';
      btn.dataset.linked = '0';
    }
  }

  function handleGoogleLinkClick() {
    const btn = document.getElementById('google-link-btn');
    if (btn.dataset.linked === '1') {
      if (!confirm('Unlink your Google account? You will no longer be able to sign in with Google until you link it again.')) return;

      btn.disabled = true;
      btn.textContent = 'Unlinking…';
      fetch('<?php echo BASE_URL ?>unlink-google', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            showSettingsAlert('Google account unlinked.', 'success');
            renderGoogleLinkStatus(false, '');
            refreshAvatarEverywhere();
          } else {
            showSettingsAlert(data.message || 'Failed to unlink.', 'error');
          }
        })
        .catch(() => showSettingsAlert('Server error. Please try again.', 'error'))
        .finally(() => { btn.disabled = false; });
    } else {
      window.location.href = '<?php echo BASE_URL ?>google-link';
    }
  }

  function refreshAvatarEverywhere() {
    fetch('<?php echo BASE_URL ?>get-account')
      .then(r => r.json())
      .then(data => {
        if (!data.success) return;

        const preview = document.getElementById('avatar-preview');
        const fallback = document.getElementById('avatar-preview-fallback');

        if (data.avatar_url) {
          preview.src = data.avatar_url + '?t=' + Date.now();
          preview.classList.remove('hidden');
          fallback.classList.add('hidden');
        } else {
          preview.classList.add('hidden');
          fallback.classList.remove('hidden');
        }

        document.querySelectorAll('.js-avatar-slot').forEach(slot => {
          if (!data.avatar_url) return;
          const bustedUrl = data.avatar_url + '?t=' + Date.now();
          const originalContent = slot.innerHTML;
          const img = document.createElement('img');
          img.src = bustedUrl;
          img.className = 'w-full h-full object-cover rounded-full';
          img.onerror = function () {
            slot.innerHTML = originalContent;
          };
          slot.innerHTML = '';
          slot.appendChild(img);
        });
      })
      .catch(() => {});
  }

  function saveAccountSettings(e) {
    e.preventDefault();
    const currentPass = document.getElementById('settings-current-password').value;
    const newPass = document.getElementById('settings-password').value;
    const confirmPass = document.getElementById('settings-confirm-password').value;

    if (newPass && !currentPass) {
      showSettingsAlert('Please enter your current password to set a new one.', 'error');
      switchSettingsTab('security');
      return;
    }
    if (newPass && newPass !== confirmPass) {
      showSettingsAlert('Passwords do not match.', 'error');
      switchSettingsTab('security');
      return;
    }
    if (newPass && newPass.length < 6) {
      showSettingsAlert('Password must be at least 6 characters.', 'error');
      switchSettingsTab('security');
      return;
    }

    const btn = document.getElementById('save-settings-btn');
    btn.textContent = 'Saving...';
    btn.disabled = true;

    fetch('<?php echo BASE_URL ?>update-account', {
      method: 'POST',
      body: new FormData(document.getElementById('account-settings-form'))
    })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          showSettingsAlert(data.message || 'Account updated successfully!', 'success');
          document.getElementById('modal-display-name').textContent =
            document.getElementById('settings-fullname').value;
          document.getElementById('settings-current-password').value = '';
          document.getElementById('settings-password').value = '';
          document.getElementById('settings-confirm-password').value = '';

          // Update signature preview if a new one was uploaded
          if (data.e_signature) {
            const previewWrap = document.getElementById('sig-preview-wrap');
            const previewImg = document.getElementById('sig-preview-img');
            const sigFilename = document.getElementById('sig-filename');
            previewImg.src = data.e_signature + '?t=' + Date.now(); // bust cache
            previewWrap.classList.remove('hidden');
            sigFilename.textContent = 'Click to upload PNG signature';
            document.getElementById('sig-upload').value = '';
          }

          // Update avatar everywhere — modal preview + header/mobile slots — without a page refresh.
          // Re-fetch from the server instead of guessing the URL client-side, so we always
          // show whatever the DB actually resolved to (avoids the "blank until refresh" bug).
          document.getElementById('avatar-upload').value = '';
          refreshAvatarEverywhere();
        } else {
          showSettingsAlert(data.message || 'Update failed.', 'error');
        }
      })
      .catch(() => showSettingsAlert('Server error. Please try again.', 'error'))
      .finally(() => { btn.textContent = 'Save Changes'; btn.disabled = false; });
  }

  // ── Toast Notifications (replaces the old inline alert banner) ─────
  function showSettingsAlert(message, type) {
    showToast(message, type);
  }

  function showToast(message, type) {
    const container = document.getElementById('settings-toast-container');
    if (!container) return;

    const isSuccess = type === 'success';
    const toast = document.createElement('div');
    toast.className = 'settings-toast pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg text-sm font-medium max-w-xs sm:max-w-sm ' +
      (isSuccess ? 'bg-white border border-green-200 text-green-700' : 'bg-white border border-red-200 text-red-700');

    toast.innerHTML =
      '<div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 ' +
        (isSuccess ? 'bg-green-100' : 'bg-red-100') + '">' +
        '<i class="' + (isSuccess ? 'ri-checkbox-circle-fill text-green-600' : 'ri-error-warning-fill text-red-600') + ' text-lg"></i>' +
      '</div>' +
      '<span class="flex-1 leading-snug">' + message + '</span>' +
      '<button type="button" class="text-gray-300 hover:text-gray-500 flex-shrink-0" onclick="this.closest(\'.settings-toast\').remove()">' +
        '<i class="ri-close-line text-lg"></i>' +
      '</button>';

    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));

    setTimeout(() => {
      toast.classList.remove('show');
      toast.classList.add('hide');
      setTimeout(() => toast.remove(), 300);
    }, 4000);
  }

</script>

<!-- Active-link highlighting, driven by the section PHP resolved server-side -->
<div id="navSectionData" data-section="<?= htmlspecialchars($_current_nav_section ?? '') ?>"></div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const activeSection = document.getElementById('navSectionData')?.dataset.section;
    if (!activeSection) return;

    document.querySelectorAll('[data-section]').forEach(function (el) {
      if (el.dataset.section !== activeSection) return;

      const tag = el.tagName.toLowerCase();

      if (tag === 'a') {
        el.classList.add('active');
        el.style.color = '#3B82F6';
        el.style.fontWeight = '700';
        if (el.closest('#mobileMenu')) {
          el.classList.add('bg-blue-50');
          el.style.borderLeft = '3px solid #3B82F6';
          el.style.paddingLeft = '14px';
        }
      }

      if (tag === 'button') {
        el.classList.add('active');
        el.style.color = '#3B82F6';
        el.style.fontWeight = '700';
      }

      const dropdown = el.closest('.dropdown, .mobile-dropdown-content');
      if (dropdown) {
        el.style.color = '#3B82F6';
        el.style.fontWeight = '700';
        el.style.background = '#eff6ff';
        el.style.borderRadius = '6px';
        const trigger = dropdown.previousElementSibling;
        if (trigger) {
          trigger.classList.add('active');
          trigger.style.color = '#3B82F6';
          trigger.style.fontWeight = '700';
        }
      }
    });
  });
</script>

<script>
  (function () {
    var hasInquiryAccess = <?php echo json_encode(
      in_array($_SESSION['admin_role'] ?? '', ['sales', 'superadmin', 'admin1', 'admin2', 'admin3', 'admin4', 'admin5', 'admin6'])
    ); ?>;
    if (!hasInquiryAccess) return;

    var countUrl = '<?= htmlspecialchars($_inquiry_counts_url) ?>';

    function updateBadges(data) {
      if (!data || data.error) return;

      var map = {
        appointments: data.appointments || 0,
        concepts: data.concepts || 0,
        contacts: data.contacts || 0,
        projects: data.projects || 0
      };

      // Per-item: desktop + mobile
      ['appointments', 'concepts', 'contacts', 'projects'].forEach(function (key) {
        var count = map[key];

        var desktopBadge = document.getElementById('nav-badge-' + key);
        if (desktopBadge) {
          desktopBadge.textContent = count;
          count > 0 ? desktopBadge.classList.remove('hidden') : desktopBadge.classList.add('hidden');
        }

        var mobileBadge = document.getElementById('mob-badge-' + key);
        if (mobileBadge) {
          mobileBadge.textContent = count;
          count > 0 ? mobileBadge.classList.remove('hidden') : mobileBadge.classList.add('hidden');
        }
      });

      // Totals
      var total = map.appointments + map.concepts + map.contacts + map.projects;

      var navTotal = document.getElementById('nav-badge-total');
      if (navTotal) {
        navTotal.textContent = total > 99 ? '99+' : total;
        total > 0 ? navTotal.classList.remove('hidden') : navTotal.classList.add('hidden');
      }

      var mobTotal = document.getElementById('mob-badge-total');
      if (mobTotal) {
        mobTotal.textContent = total > 99 ? '99+' : total;
        total > 0 ? mobTotal.classList.remove('hidden') : mobTotal.classList.add('hidden');
      }
    }

    function fetchCounts() {
      fetch(countUrl, { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(updateBadges)
        .catch(function () { });
    }

    fetchCounts();
    setInterval(fetchCounts, 15000);

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) fetchCounts();
    });
  })();
</script>

<script>
  (function () {
    var hasNotifAccess = <?php echo json_encode(
      in_array($_SESSION['admin_role'] ?? '', ['general_manager', 'operational_manager', 'designer', 'technical_designer', 'accounting', 'superadmin', 'project_coordinator', 'sales'])
    ); ?>;
    if (!hasNotifAccess) return;

    var notifUrl = <?php echo json_encode(BASE_URL . 'get-user-notificaitons'); ?>;

    var notifBellButton = document.getElementById('notifBellButton');
    var notifDropdown = document.getElementById('notifDropdown');
    var notifBellBadge = document.getElementById('notifBellBadge');
    var notifList = document.getElementById('notifList');
    var notifDropdownCount = document.getElementById('notifDropdownCount');

    var mobileNotifBellButton = document.getElementById('mobileNotifBellButton');
    var mobileNotifBellBadge = document.getElementById('mobileNotifBellBadge');
    var mobileNotifList = document.getElementById('mobileNotifList');

    function timeAgo(dateStr) {
      var diff = Math.floor((Date.now() - new Date(dateStr.replace(' ', 'T'))) / 1000);
      if (diff < 60) return 'just now';
      if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
      if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
      return Math.floor(diff / 86400) + 'd ago';
    }

    function colorClasses(color) {
      var map = {
        amber: { bg: 'bg-amber-50', text: 'text-amber-600', icon: 'bg-amber-100' },
        red: { bg: 'bg-red-50', text: 'text-red-600', icon: 'bg-red-100' },
        blue: { bg: 'bg-blue-50', text: 'text-blue-600', icon: 'bg-blue-100' }
      };
      return map[color] || map.amber;
    }

    function renderNotifItem(n) {
      var c = colorClasses(n.color);
      return '<a href="' + n.link + '" class="block px-4 py-3 hover:bg-gray-50 transition-colors">' +
        '<div class="flex items-start gap-3">' +
        '<div class="w-9 h-9 rounded-full ' + c.icon + ' flex items-center justify-center flex-shrink-0 mt-0.5">' +
        '<i class="fas ' + n.icon + ' ' + c.text + '"></i></div>' +
        '<div class="flex-1 min-w-0">' +
        '<p class="text-sm font-semibold text-gray-800 truncate">' + n.title + '</p>' +
        '<p class="text-xs text-gray-500 truncate mt-0.5">' + n.subtitle + '</p>' +
        '<p class="text-[11px] text-gray-400 mt-1">' + timeAgo(n.created_at) + '</p>' +
        '</div></div></a>';
    }

    function renderEmpty() {
      return '<div class="px-4 py-8 text-center text-gray-400 text-sm">' +
        '<i class="ri-checkbox-circle-line text-2xl block mb-2 text-green-400"></i>' +
        'You\'re all caught up!</div>';
    }

    function updateNotifications(data) {
      if (!data || data.error) return;
      var items = data.notifications || [];
      var total = data.total || 0;

      // Desktop badge
      if (notifBellBadge) {
        notifBellBadge.textContent = total > 99 ? '99+' : total;
        total > 0 ? notifBellBadge.classList.remove('hidden') : notifBellBadge.classList.add('hidden');
      }
      if (notifDropdownCount) {
        notifDropdownCount.textContent = total + ' pending';
      }
      if (notifList) {
        notifList.innerHTML = items.length ? items.map(renderNotifItem).join('') : renderEmpty();
      }

      // Mobile badge
      if (mobileNotifBellBadge) {
        mobileNotifBellBadge.textContent = total > 99 ? '99+' : total;
        total > 0 ? mobileNotifBellBadge.classList.remove('hidden') : mobileNotifBellBadge.classList.add('hidden');
      }
      if (mobileNotifList) {
        mobileNotifList.innerHTML = items.length ? items.map(renderNotifItem).join('') : renderEmpty();
      }
    }

    function fetchNotifications() {
      fetch(notifUrl, { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(updateNotifications)
        .catch(function () { });
    }

    fetchNotifications();
    setInterval(fetchNotifications, 15000);

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) fetchNotifications();
    });

    // Toggle desktop dropdown
    if (notifBellButton && notifDropdown) {
      notifBellButton.addEventListener('click', function (e) {
        e.stopPropagation();
        notifDropdown.classList.toggle('hidden');
      });
      document.addEventListener('click', function (e) {
        if (!notifBellButton.contains(e.target) && !notifDropdown.contains(e.target)) {
          notifDropdown.classList.add('hidden');
        }
      });
    }

    // Toggle mobile dropdown
    if (mobileNotifBellButton && mobileNotifList) {
      mobileNotifBellButton.addEventListener('click', function () {
        mobileNotifList.classList.toggle('show');
      });
    }
  })();
</script>

<script>
  (function () {
    var hasTdAccess = <?php echo json_encode(
      in_array($_SESSION['admin_role'] ?? '', ['general_manager', 'operational_manager', 'technical_designer'])
    ); ?>;
    if (!hasTdAccess) return;

    // Build URL the same way the inquiry counts URL is built
    var tdCountUrl = <?php echo json_encode(BASE_URL . 'get-td-approval-counts'); ?>;

    function updateTdBadges(data) {
      if (!data || data.error) return;
      var count = (data.td_approvals || 0) + (data.remark_needed || 0);

      ['nav-badge-td', 'nav-badge-td-mgr', 'mob-badge-td', 'mob-badge-td-mgr'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = count > 99 ? '99+' : count;
        count > 0 ? el.classList.remove('hidden') : el.classList.add('hidden');
      });
    }

    function fetchTdCounts() {
      fetch(tdCountUrl, { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(updateTdBadges)
        .catch(function () { });
    }

    fetchTdCounts();
    setInterval(fetchTdCounts, 15000);

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) fetchTdCounts();
    });
  })();
</script>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const params = new URLSearchParams(window.location.search);
    if (params.get('google_linked') === '1') {
      openAccountSettings();
      switchSettingsTab('google');
      setTimeout(() => showSettingsAlert('Google account linked successfully!', 'success'), 400);
      // Clean the URL so refresh doesn't re-trigger this
      window.history.replaceState({}, '', window.location.pathname);
    }
    if (params.get('google_error') === 'already_linked') {
      alert('That Google account is already linked to a different staff account.');
      window.history.replaceState({}, '', window.location.pathname);
    }
  });
</script>

<!-- Presence heartbeat — keeps last_activity fresh while this tab is open -->
<script>
  (function () {
    var heartbeatUrl = <?php echo json_encode(BASE_URL . 'heartbeat'); ?>;

    function sendHeartbeat() {
      if (document.hidden) return; // don't ping from a backgrounded tab
      fetch(heartbeatUrl, { method: 'POST', credentials: 'same-origin' }).catch(function () {});
    }

    sendHeartbeat(); // immediately on page load
    setInterval(sendHeartbeat, 30000); // every 30 seconds

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) sendHeartbeat();
    });
  })();
</script>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    fetch('<?php echo BASE_URL ?>get-account')
      .then(r => r.json())
      .then(data => {
        if (!data.success || !data.avatar_url) return;
        document.querySelectorAll('.js-avatar-slot').forEach(slot => {
          const originalContent = slot.innerHTML; // keep the letter/icon as fallback
          const img = document.createElement('img');
          img.src = data.avatar_url;
          img.className = 'w-full h-full object-cover rounded-full';
          img.onerror = function () {
            slot.innerHTML = originalContent; // broken image → restore letter/icon instead of blank
          };
          slot.innerHTML = '';
          slot.appendChild(img);
        });
      })
      .catch(() => {});
  });
</script>