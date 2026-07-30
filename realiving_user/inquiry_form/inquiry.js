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

  // Open inquiry form
  openButtons.forEach(function (btn) {
    btn.addEventListener("click", function (e) {
      e.preventDefault();
      popupForm.style.display = "flex";
      document.body.style.overflow = 'hidden';
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

    // Reset reCAPTCHA
    window.inquiryRecaptchaToken = '';
    if (typeof grecaptcha !== 'undefined' && grecaptcha.reset) {
      try {
        grecaptcha.reset();
      } catch (e) {
        console.warn("⚠️ Could not reset reCAPTCHA:", e);
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

    // Check reCAPTCHA (use the token captured by the callback, since
    // multiple g-recaptcha widgets can exist on one page — grecaptcha.getResponse()
    // with no widget ID always reads widget #0, which may not be this form's widget)
    if (!window.inquiryRecaptchaToken || window.inquiryRecaptchaToken.length === 0) {
      showError('Please complete the reCAPTCHA verification by checking the box.');
      return;
    }
    const recaptchaResponse = window.inquiryRecaptchaToken;

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

        if (typeof grecaptcha !== 'undefined' && grecaptcha.reset) {
          grecaptcha.reset();
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

    // Reset reCAPTCHA when showing error (except for reCAPTCHA-specific errors)
    if (typeof grecaptcha !== 'undefined' && grecaptcha.reset &&
      !message.includes('reCAPTCHA')) {
      setTimeout(() => {
        grecaptcha.reset();
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

  // reCAPTCHA callback functions for inquiry form (must be global)
  // The token is captured here directly from the widget that was actually
  // checked, avoiding the multi-widget getResponse() ambiguity.
  window.inquiryRecaptchaCallback = function (token) {
    window.inquiryRecaptchaToken = token;
  };

  window.inquiryRecaptchaExpiredCallback = function () {
    window.inquiryRecaptchaToken = '';
    showError('reCAPTCHA verification expired. Please verify again.');
  };

  window.inquiryRecaptchaErrorCallback = function () {
    window.inquiryRecaptchaToken = '';
    showError('reCAPTCHA error. Please refresh and try again.');
  };
});