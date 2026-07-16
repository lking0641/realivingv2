// Header Responsiveness & Dropdown functionality
document.addEventListener('DOMContentLoaded', function() {
  // Mobile menu functionality
  const mobileMenuButton = document.getElementById('mobileMenuButton');
  const closeMenuButton = document.getElementById('closeMenu');
  const mobileMenu = document.getElementById('mobileMenu');
  const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

  // Profile dropdown
  const profileButton = document.getElementById('profileButton');
  const profileDropdown = document.getElementById('profileDropdown');
  
  // Toggle functions for desktop dropdowns
  function toggleDropdown(id) {
    const dropdown = document.getElementById(id);
    const allDropdowns = document.querySelectorAll('.dropdown');
    
    // Close all other dropdowns first
    allDropdowns.forEach(item => {
      if (item.id !== id && item.classList.contains('show')) {
        item.classList.remove('show');
      }
    });
    
    // Toggle the clicked dropdown
    dropdown.classList.toggle('show');
  }
  
  // Mobile menu toggle
  if (mobileMenuButton) {
    mobileMenuButton.addEventListener('click', function() {
      mobileMenu.classList.add('active');
      mobileMenuOverlay.classList.add('active');
      document.body.style.overflow = 'hidden'; // Prevent scrolling
    });
  }
  
  // Close mobile menu
  if (closeMenuButton) {
    closeMenuButton.addEventListener('click', function() {
      closeMobileMenu();
    });
  }
  
  // Close when clicking overlay
  if (mobileMenuOverlay) {
    mobileMenuOverlay.addEventListener('click', function() {
      closeMobileMenu();
    });
  }
  
  function closeMobileMenu() {
    mobileMenu.classList.remove('active');
    mobileMenuOverlay.classList.remove('active');
    document.body.style.overflow = ''; // Re-enable scrolling
  }
  
  // Toggle profile dropdown
  if (profileButton) {
    profileButton.addEventListener('click', function(e) {
      e.stopPropagation();
      profileDropdown.classList.toggle('hidden');
      
      // Close all other dropdowns
      document.querySelectorAll('.dropdown').forEach(item => {
        item.classList.remove('show');
      });
    });
  }
  
  // Mobile dropdown toggles
  const mobileDropdownButtons = document.querySelectorAll('.mobile-dropdown-button');
  
  mobileDropdownButtons.forEach(button => {
    button.addEventListener('click', function() {
      // Get the next sibling which is the dropdown content
      const dropdownContent = this.nextElementSibling;
      const dropdownArrow = this.querySelector('i.ri-arrow-down-s-line');
      
      // Toggle active class for the content
      dropdownContent.classList.toggle('hidden');
      dropdownContent.classList.toggle('active');
      
      // Toggle active class for the button to rotate arrow
      this.classList.toggle('active');
    });
  });
  
  // Close dropdowns when clicking outside
  document.addEventListener('click', function(e) {
    // Close profile dropdown if open
    if (profileDropdown && !profileDropdown.contains(e.target) && e.target !== profileButton) {
      profileDropdown.classList.add('hidden');
    }
    
    // Close all dropdowns
    document.querySelectorAll('.dropdown').forEach(dropdown => {
      if (!dropdown.contains(e.target) && 
          !e.target.classList.contains('nav-link') && 
          !e.target.closest('.nav-link')) {
        dropdown.classList.remove('show');
      }
    });
  });
  
  // Sticky header behavior - add shadow on scroll
  const header = document.querySelector('header');
  let scrollPosition = 0;
  
  window.addEventListener('scroll', function() {
    const currentScroll = window.pageYOffset;
    
    // Add shadow when scrolled down
    if (currentScroll > 10) {
      header.classList.add('shadow-md');
    } else {
      header.classList.remove('shadow-md');
    }
    
    // Auto-hide header on scroll down (optional feature)
    if (currentScroll > 500) { // Only apply after scrolling down a bit
      if (currentScroll > scrollPosition) {
        // Scrolling down
        header.style.transform = 'translateY(-100%)';
      } else {
        // Scrolling up
        header.style.transform = 'translateY(0)';
      }
    }
    
    scrollPosition = currentScroll;
  });
  
  // Make the toggleDropdown function globally available
  window.toggleDropdown = toggleDropdown;
  
  // Responsive adjustments based on screen size
  function handleResponsiveChanges() {
    const windowWidth = window.innerWidth;
    
    // Auto-close mobile menu when resizing to desktop
    if (windowWidth >= 1024 && mobileMenu.classList.contains('active')) {
      closeMobileMenu();
    }
    
    // Adjust dropdown positioning based on viewport
    const dropdowns = document.querySelectorAll('.dropdown');
    dropdowns.forEach(dropdown => {
      if (windowWidth < 640) {
        // For very small screens, adjust dropdown width
        dropdown.style.width = 'calc(100vw - 40px)';
        dropdown.style.right = '-10px';
      } else {
        dropdown.style.width = '';
        dropdown.style.right = '';
      }
    });
  }
  
  // Run on load and resize
  handleResponsiveChanges();
  window.addEventListener('resize', handleResponsiveChanges);
});