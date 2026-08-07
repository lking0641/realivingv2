// inquiry.js - Handles the "Inquire Now" popup form

document.addEventListener("DOMContentLoaded", function () {
  const openButtons = document.querySelectorAll(".openFormBtn");
  const popupForm = document.getElementById("popupForm");
  const thankYouModal = document.getElementById("inquiryThankYouModal");
  const errorModal = document.getElementById("inquiryErrorModal");
  const closeFormBtn = document.getElementById("closeFormBtn");
  const inquiryForm = document.querySelector("#popupForm form");

  if (!popupForm) {
    console.error("❌ CRITICAL: popupForm element not found!");
    return;
  }

  if (!inquiryForm) {
    console.error("❌ CRITICAL: inquiryForm element not found!");
    return;
  }

  const submitBtn = inquiryForm.querySelector(".submit-btn");
  if (!submitBtn) {
    console.error("❌ CRITICAL: submit button not found!");
    return;
  }

  // Track the rendered Turnstile widget ID so we can reset/remove it properly
  let inquiryTurnstileWidgetId = null;

  function renderInquiryTurnstile() {
    const el = document.getElementById('inquiry-turnstile');
    if (!el || inquiryTurnstileWidgetId !== null) return; // already rendered

    if (typeof turnstile === 'undefined') {
      // Cloudflare's script hasn't finished loading yet — try again shortly
      // instead of silently giving up (this was the bug)
      setTimeout(renderInquiryTurnstile, 100);
      return;
    }

    inquiryTurnstileWidgetId = turnstile.render(el, {
      sitekey: el.getAttribute('data-sitekey'),
      callback: window.inquiryTurnstileCallback,
      'expired-callback': window.inquiryTurnstileExpiredCallback,
      'error-callback': window.inquiryTurnstileErrorCallback
    });
  }

  // Open inquiry form
  openButtons.forEach(function (btn) {
    btn.addEventListener("click", function (e) {
      e.preventDefault();
      popupForm.style.display = "flex";
      document.body.style.overflow = 'hidden';
      renderInquiryTurnstile(); // only fetch a token now, not on page load
    });
  });

  // Close inquiry form
  if (closeFormBtn) {
    closeFormBtn.addEventListener("click", function () {
      closePopupForm();
    });
  }

  // Helper function to close popup form
  function closePopupForm() {
    popupForm.style.display = "none";
    document.body.style.overflow = 'auto';

    // Reset form
    inquiryForm.reset();

    // Remove Turnstile widget entirely so it stops holding/refreshing
    // a token in the background while the popup is closed
    window.inquiryTurnstileToken = '';
    if (typeof turnstile !== 'undefined' && inquiryTurnstileWidgetId !== null) {
      try {
        turnstile.remove(inquiryTurnstileWidgetId);
        inquiryTurnstileWidgetId = null;
      } catch (e) {
        console.warn("⚠️ Could not remove Turnstile widget:", e);
      }
    }

    // Re-enable submit button
    submitBtn.disabled = false;
    submitBtn.textContent = "SUBMIT INQUIRY";
  }

  // Close when clicking outside
  window.addEventListener("click", function (event) {
    if (event.target === popupForm) {
      closePopupForm();
    }
    if (event.target === thankYouModal) {
      thankYouModal.style.display = "none";
      document.body.style.overflow = 'auto';
    }
    if (event.target === errorModal) {
      errorModal.style.display = "none";
      document.body.style.overflow = 'auto';
    }
  });

  // Handle form submission with AJAX
  inquiryForm.addEventListener("submit", function (e) {
    e.preventDefault();

    // Client-side validation
    const name = document.getElementById('inq_name').value.trim();
    const email = document.getElementById('inq_email').value.trim();
    const phone = document.getElementById('inq_phone').value.trim();
    const location = document.getElementById('inq_location').value.trim();
    const subject = document.getElementById('inq_subject').value.trim();
    const message = document.getElementById('inq_message').value.trim();

    // Validate name
    if (!/^[a-zA-Z\s\-'\.]+$/.test(name)) {
      showError('Name should only contain letters, spaces, hyphens, and apostrophes.');
      return;
    }

    if (name.length < 2 || name.length > 100) {
      showError('Name must be between 2 and 100 characters.');
      return;
    }

    // Validate email
    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(email)) {
      showError('Please enter a valid email address.');
      return;
    }

    // Validate Philippine phone number (11 digits starting with 09)
    const phoneDigits = phone.replace(/[^0-9]/g, '');
    if (!/^09[0-9]{9}$/.test(phoneDigits)) {
      showError('Please enter a valid 11-digit Philippine mobile number starting with 09 (e.g., 09123456789).');
      return;
    }

    // Validate location (allow numbers)
    if (location && !/^[a-zA-Z0-9\s,\-\.]+$/.test(location)) {
      showError('Location should only contain letters, numbers, spaces, commas, and hyphens.');
      return;
    }

    // Validate subject
    if (subject.length < 3 || subject.length > 200) {
      showError('Subject must be between 3 and 200 characters.');
      return;
    }

    // Validate message
    if (message.length < 1 || message.length > 1000) {
      showError('Please enter a message.');
      return;
    }

    // Check Turnstile token (captured via the callback)
    if (!window.inquiryTurnstileToken || window.inquiryTurnstileToken.length === 0) {
      showError('Please complete the verification check.');
      return;
    }

    const formData = new FormData(inquiryForm);

    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.textContent = "SUBMITTING...";

    // Add AJAX flag
    formData.append('ajax_submit', '1');

    // Determine the correct path to inquiry.php
    const inquiryPath = (window.BASE_URL || '/') + 'inquiry';

    // Send data
    fetch(inquiryPath, {
      method: 'POST',
      body: formData
    })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
      })
      .then(text => {
        try {
          return JSON.parse(text);
        } catch (parseError) {
          console.error("❌ JSON Parse Error:", parseError, text);
          throw new Error("Server returned invalid JSON. The server may have output HTML or errors before the JSON response.");
        }
      })
      .then(data => {
        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.textContent = "SUBMIT INQUIRY";

        if (data.success) {
          closePopupForm();

          if (thankYouModal) {
            thankYouModal.style.display = "flex";
            document.body.style.overflow = 'hidden';
          } else {
            alert(data.message);
          }
        } else {
          showError(data.message || 'An error occurred. Please try again.');
        }
      })
      .catch(error => {
        console.error("❌ Inquiry submission error:", error);

        submitBtn.disabled = false;
        submitBtn.textContent = "SUBMIT INQUIRY";

        if (typeof turnstile !== 'undefined' && inquiryTurnstileWidgetId !== null) {
          turnstile.reset(inquiryTurnstileWidgetId);
        }

        showError('Network error: ' + error.message + '. Please check your connection and try again.');
      });
  });

  // Function to show error modal
  function showError(message) {
    const errorMessage = document.getElementById("errorMessage");

    if (errorMessage && errorModal) {
      errorMessage.textContent = message;
      errorModal.style.display = "flex";
      document.body.style.overflow = 'hidden';
    } else {
      alert("Error: " + message);
    }

    // Reset Turnstile when showing error (except for verification-specific errors)
    if (typeof turnstile !== 'undefined' && inquiryTurnstileWidgetId !== null &&
      !message.includes('verification')) {
      setTimeout(() => {
        turnstile.reset(inquiryTurnstileWidgetId);
      }, 100);
    }
  }

  // Close thank you modal
  const closeThankYou = document.querySelector(".inquiry-close");
  if (closeThankYou) {
    closeThankYou.addEventListener("click", function () {
      thankYouModal.style.display = "none";
      document.body.style.overflow = 'auto';
    });
  }

  // Close error modal
  const closeError = document.querySelector(".inquiry-error-close");
  if (closeError) {
    closeError.addEventListener("click", function () {
      errorModal.style.display = "none";
      document.body.style.overflow = 'auto';
    });
  }

  // Turnstile callback functions for inquiry form (must be global)
  window.inquiryTurnstileCallback = function (token) {
    window.inquiryTurnstileToken = token;
  };

  window.inquiryTurnstileExpiredCallback = function () {
    window.inquiryTurnstileToken = '';
    showError('Verification expired. Please verify again.');
  };

  window.inquiryTurnstileErrorCallback = function () {
    window.inquiryTurnstileToken = '';
    showError('Verification error. Please refresh and try again.');
  };
});