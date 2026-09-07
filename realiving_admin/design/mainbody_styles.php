<style>

  body {
    font-family: 'Poppins', sans-serif;
    background-color: #F8FAFC;
  }

  .nav-link {
    position: relative;
    transition: all 0.3s ease;
    font-weight: 500;
  }

  .nav-link::after {
    content: '';
    position: absolute;
    width: 0;
    height: 2px;
    bottom: -6px;
    left: 50%;
    transform: translateX(-50%);
    background-color: #3B82F6;
    transition: width 0.3s ease;
    border-radius: 2px;
  }

  .nav-link:hover::after,
  .nav-link.active::after {
    width: 70%;
  }

  .loading-animation {
    stroke-dasharray: 150;
    stroke-dashoffset: 150;
    animation: dash 1.5s ease-in-out infinite alternate;
  }

  @keyframes dash {
    from {
      stroke-dashoffset: 150;
    }

    to {
      stroke-dashoffset: 0;
    }
  }

  .dropdown {
    opacity: 0;
    transform: translateY(10px);
    transition: opacity 0.3s ease, transform 0.3s ease;
    visibility: hidden;
  }

  .dropdown.show {
    opacity: 1;
    transform: translateY(0);
    visibility: visible;
  }

  .mobile-menu {
    transform: translateX(100%);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: -4px 0 15px rgba(0, 0, 0, 0.1);
  }

  .mobile-menu.active {
    transform: translateX(0);
  }

  .mobile-overlay {
    opacity: 0;
    transition: opacity 0.3s ease;
  }

  .mobile-overlay.active {
    opacity: 1;
  }

  .mobile-menu::-webkit-scrollbar {
    width: 6px;
  }

  .mobile-menu::-webkit-scrollbar-track {
    background: #f1f5f9;
  }

  .mobile-menu::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
  }

  .hover-scale {
    transition: transform 0.2s ease;
  }

  .hover-scale:hover {
    transform: scale(1.05);
  }

  .avatar-glow {
    position: relative;
  }

  .avatar-glow::after {
    content: '';
    position: absolute;
    top: -2px;
    left: -2px;
    right: -2px;
    bottom: -2px;
    border-radius: 9999px;
    background: linear-gradient(45deg, #3B82F6, #10B981);
    z-index: -1;
    opacity: 0;
    transition: opacity 0.3s ease;
  }

  .avatar-glow:hover::after {
    opacity: 1;
  }

  .avatar-glow:hover {
    box-shadow: 0 0 15px rgba(59, 130, 246, 0.5);
  }

  .nav-badge {
    position: absolute;
    top: -4px;
    right: -8px;
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 10px;
    box-shadow: 0 2px 6px rgba(239, 68, 68, 0.4);
    min-width: 18px;
    text-align: center;
    animation: pulse-badge 2s ease-in-out infinite;
  }

  @keyframes pulse-badge {

    0%,
    100% {
      transform: scale(1);
    }

    50% {
      transform: scale(1.15);
    }
  }

  .mobile-dropdown-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease-out, padding 0.3s ease-out;
    padding-top: 0;
    padding-bottom: 0;
  }

  .mobile-dropdown-content.show {
    max-height: 300px;
    padding-top: 0.5rem;
    padding-bottom: 0.5rem;
  }

  .mobile-dropdown-arrow {
    transition: transform 0.3s ease;
  }

  .mobile-dropdown-arrow.rotated {
    transform: rotate(180deg);
  }

  .mobile-overlay {
    display: none;
  }

  .mobile-overlay.active {
    display: block;
    opacity: 1;
  }

  @media screen and (max-width: 374px) {
    nav {
      height: 60px !important;
    }

    #mobileMenu {
      width: 85% !important;
    }
  }

  @media screen and (min-width: 375px) and (max-width: 639px) {
    nav {
      height: 64px !important;
    }

    #mobileMenu {
      width: 80% !important;
    }
  }

  @media screen and (min-width: 640px) and (max-width: 767px) {
    nav {
      height: 68px !important;
    }

    #mobileMenu {
      width: 320px;
    }
  }

  @media screen and (min-width: 768px) and (max-width: 1023px) {
    nav {
      height: 72px !important;
    }

    #mobileMenu {
      width: 350px;
    }
  }

  @media screen and (min-width: 1536px) {
    .max-w-7xl {
      max-width: 1400px !important;
    }

    nav {
      height: 80px !important;
    }
  }

  @media print {
    .header {
      position: static !important;
      box-shadow: none !important;
    }

    .nav-link,
    #profileButton,
    #mobileMenuButton {
      display: none !important;
    }
  }

  @media (max-width: 768px) {
    #notifDropdown {
      position: fixed !important;
      left: 50% !important;
      right: auto !important;
      transform: translateX(-50%) !important;
      top: 70px !important;
      width: calc(100vw - 2rem) !important;
    }
  }

  .dropdown::-webkit-scrollbar {
    width: 6px;
  }

  .dropdown::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
  }

  .dropdown::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 10px;
  }

  .dropdown.with-scroll {
    max-height: 400px;
    overflow-y: auto;
  }

  /* ============================================================ */
  /*          ACCOUNT SETTINGS — TABS                              */
  /* ============================================================ */

  .settings-tab-btn {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 9px 16px;
    font-size: 13px;
    font-weight: 600;
    color: #6b7280;
    background: transparent;
    border: none;
    border-radius: 10px;
    white-space: nowrap;
    cursor: pointer;
    flex-shrink: 0;
    transition: color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
  }

  .settings-tab-btn i {
    font-size: 16px;
    line-height: 1;
  }

  .settings-tab-btn span {
    line-height: 1;
  }

  .settings-tab-btn:hover:not(.active) {
    color: #3B82F6;
    background-color: rgba(59, 130, 246, 0.08);
  }

  .settings-tab-btn.active {
    color: #ffffff;
    background: linear-gradient(135deg, #3B82F6, #4F46E5);
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.35);
  }

  #settings-tabs::-webkit-scrollbar {
    height: 4px;
  }

  #settings-tabs::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 10px;
  }

  /* ============================================================ */
  /*          TOAST NOTIFICATIONS                                  */
  /* ============================================================ */

  .settings-toast {
    opacity: 0;
    transform: translateX(24px);
    transition: opacity 0.3s ease, transform 0.3s ease;
  }

  .settings-toast.show {
    opacity: 1;
    transform: translateX(0);
  }

  .settings-toast.hide {
    opacity: 0;
    transform: translateX(24px);
  }

</style>