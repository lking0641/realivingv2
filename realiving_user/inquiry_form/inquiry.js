// inquiry.js - Enhanced with comprehensive error checking
console.log("✅ PopupForm JS loaded");

document.addEventListener("DOMContentLoaded", function () {
  console.log("🔧 DOM Content Loaded - Initializing...");

  // Get all elements with error checking
  const openButtons = document.querySelectorAll(".openFormBtn");
  const popupForm = document.getElementById("popupForm");
  const thankYouModal = document.getElementById("inquiryThankYouModal");
  const errorModal = document.getElementById("inquiryErrorModal");
  const closeFormBtn = document.getElementById("closeFormBtn");
  const inquiryForm = document.querySelector("#popupForm form");
  
  // Verify all critical elements exist
  console.log("📋 Element Check:");
  console.log("  - Open Buttons:", openButtons.length);
  console.log("  - Popup Form:", popupForm ? "✓" : "✗ MISSING");
  console.log("  - Thank You Modal:", thankYouModal ? "✓" : "✗ MISSING");
  console.log("  - Error Modal:", errorModal ? "✓" : "✗ MISSING");
  console.log("  - Close Button:", closeFormBtn ? "✓" : "✗ MISSING");
  console.log("  - Inquiry Form:", inquiryForm ? "✓" : "✗ MISSING");

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
  console.log("  - Submit Button:", "✓");

  // Open inquiry form
  openButtons.forEach(function (btn, index) {
    console.log(`🔘 Attaching click event to button ${index + 1}`);
    btn.addEventListener("click", function (e) {
      e.preventDefault();
      console.log("🖱️ Open button clicked");
      popupForm.style.display = "flex";
      document.body.style.overflow = 'hidden';
      console.log("✅ Popup form displayed");
    });
  });

  // Close inquiry form
  if (closeFormBtn) {
    closeFormBtn.addEventListener("click", function () {
      console.log("🖱️ Close button clicked");
      closePopupForm();
    });
  }

  // Helper function to close popup form
  function closePopupForm() {
    console.log("🔄 Closing popup form and cleaning up");
    popupForm.style.display = "none";
    document.body.style.overflow = 'auto';
    
    // Reset form
    inquiryForm.reset();
    
    // Reset reCAPTCHA
    if (typeof grecaptcha !== 'undefined' && grecaptcha.reset) {
      try {
        grecaptcha.reset();
        console.log("✅ reCAPTCHA reset");
      } catch (e) {
        console.warn("⚠️ Could not reset reCAPTCHA:", e);
      }
    }
    
    // Re-enable submit button
    submitBtn.disabled = false;
    submitBtn.textContent = "SUBMIT INQUIRY";
    
    console.log("✅ Popup form closed and cleaned up");
  }

  // Close when clicking outside
  window.addEventListener("click", function (event) {
    if (event.target === popupForm) {
      console.log("🖱️ Clicked outside popup");
      closePopupForm();
    }
    if (event.target === thankYouModal) {
      console.log("🖱️ Clicked outside thank you modal");
      thankYouModal.style.display = "none";
      document.body.style.overflow = 'auto';
    }
    if (event.target === errorModal) {
      console.log("🖱️ Clicked outside error modal");
      errorModal.style.display = "none";
      document.body.style.overflow = 'auto';
    }
  });

  // Handle form submission with AJAX
  inquiryForm.addEventListener("submit", function (e) {
    e.preventDefault();
    console.log("📤 Form submission started");

    // Client-side validation
    const name = document.getElementById('name').value.trim();
    const email = document.getElementById('email').value.trim();
    const phone = document.getElementById('phone').value.trim();
    const location = document.getElementById('location').value.trim();
    const subject = document.getElementById('subject').value.trim();
    const message = document.getElementById('message').value.trim();

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
    if (message.length < 10 || message.length > 1000) {
      showError('Message must be between 10 and 1000 characters.');
      return;
    }

    // Check reCAPTCHA
let recaptchaResponse = '';
if (typeof grecaptcha !== 'undefined' && grecaptcha.getResponse) {
  try {
    recaptchaResponse = grecaptcha.getResponse();
    console.log("🔍 reCAPTCHA Response:", recaptchaResponse ? "EXISTS" : "EMPTY");
  } catch (e) {
    console.error("❌ Error getting reCAPTCHA response:", e);
  }
  
  if (!recaptchaResponse || recaptchaResponse.length === 0) {
    console.error("❌ reCAPTCHA not completed");
    showError('Please complete the reCAPTCHA verification by checking the box.');
    return;
  }
  console.log("✅ reCAPTCHA completed with response length:", recaptchaResponse.length);
} else {
  console.error("❌ reCAPTCHA script not loaded");
  showError('reCAPTCHA is not loaded. Please refresh the page and try again.');
  return;
}

    // Get form data for validation logging
    const formData = new FormData(inquiryForm);
    console.log("📋 Form Data:");
    for (let [key, value] of formData.entries()) {
      console.log(`  - ${key}:`, value ? `"${value}"` : "EMPTY");
    }

    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.textContent = "SUBMITTING...";
    console.log("🔒 Submit button disabled");

    // Add AJAX flag
    formData.append('ajax_submit', '1');

    // Determine the correct path to inquiry.php
    const inquiryPath = './inquiry_form/inquiry.php';
    
    console.log("🌐 Request details:");
    console.log("  - Target URL:", inquiryPath);
    console.log("  - Method: POST");
    console.log("  - Body: FormData with ajax_submit flag");

    // Send data
    fetch(inquiryPath, {
      method: 'POST',
      body: formData
    })
    .then(response => {
      console.log("📥 Response received");
      console.log("  - Status:", response.status);
      console.log("  - Status Text:", response.statusText);
      console.log("  - Content-Type:", response.headers.get('Content-Type'));
      
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      
      // Get response text first to see what we're getting
      return response.text();
    })
    .then(text => {
      console.log("📄 Raw Response (first 500 chars):", text.substring(0, 500));
      
      // Try to parse as JSON
      try {
        const data = JSON.parse(text);
        console.log("✅ Parsed JSON successfully:", data);
        return data;
      } catch (parseError) {
        console.error("❌ JSON Parse Error:", parseError);
        console.error("❌ Full response text:", text);
        throw new Error("Server returned invalid JSON. The server may have output HTML or errors before the JSON response.");
      }
    })
    .then(data => {
      // Re-enable submit button
      submitBtn.disabled = false;
      submitBtn.textContent = "SUBMIT INQUIRY";
      console.log("🔓 Submit button re-enabled");

      if (data.success) {
        console.log("✅ Inquiry submitted successfully!");
        console.log("  - Inquiry ID:", data.inquiry_id);
        console.log("  - Message:", data.message);

        // Close inquiry form with cleanup
        closePopupForm();

        // Show thank you modal
        if (thankYouModal) {
          thankYouModal.style.display = "flex";
          document.body.style.overflow = 'hidden';
          console.log("✅ Thank you modal displayed");
        } else {
          console.warn("⚠️ Thank you modal not found, using alert");
          alert(data.message);
          document.body.style.overflow = 'auto';
        }
      } else {
        console.error("❌ Submission failed:", data.message);
        showError(data.message || 'An error occurred. Please try again.');
      }
    })
    .catch(error => {
  console.error("❌ Error Details:");
  console.error("  - Error Type:", error.name);
  console.error("  - Error Message:", error.message);
  console.error("  - Full Error:", error);
  
  // Re-enable submit button
  submitBtn.disabled = false;
  submitBtn.textContent = "SUBMIT INQUIRY";
  
  // Reset reCAPTCHA on error
  if (typeof grecaptcha !== 'undefined' && grecaptcha.reset) {
    grecaptcha.reset();
    console.log("🔄 reCAPTCHA reset due to error");
  }
  
  showError('Network error: ' + error.message + '. Please check your connection and try again.');
});
  });

  // Function to show error modal
function showError(message) {
  console.log("⚠️ Showing error:", message);
  const errorMessage = document.getElementById("errorMessage");
  
  if (errorMessage && errorModal) {
    errorMessage.textContent = message;
    errorModal.style.display = "flex";
    document.body.style.overflow = 'hidden';
    console.log("✅ Error modal displayed");
  } else {
    console.warn("⚠️ Error modal elements not found, using alert");
    alert("Error: " + message);
  }
  
  // Reset reCAPTCHA when showing error (except for reCAPTCHA-specific errors)
  if (typeof grecaptcha !== 'undefined' && grecaptcha.reset && 
      !message.includes('reCAPTCHA')) {
    setTimeout(() => {
      grecaptcha.reset();
      console.log("🔄 reCAPTCHA reset after error");
    }, 100);
  }
}

  // Close thank you modal
  const closeThankYou = document.querySelector(".inquiry-close");
  if (closeThankYou) {
    closeThankYou.addEventListener("click", function () {
      console.log("🖱️ Close thank you modal");
      thankYouModal.style.display = "none";
      document.body.style.overflow = 'auto';
    });
    console.log("✅ Thank you close button attached");
  } else {
    console.warn("⚠️ Thank you close button not found");
  }

  // Close error modal
  const closeError = document.querySelector(".inquiry-error-close");
  if (closeError) {
    closeError.addEventListener("click", function () {
      console.log("🖱️ Close error modal");
      errorModal.style.display = "none";
      document.body.style.overflow = 'auto';
    });
    console.log("✅ Error close button attached");
  } else {
    console.warn("⚠️ Error close button not found");
  }

  // reCAPTCHA callback functions for inquiry form (must be global)
  window.inquiryRecaptchaCallback = function() {
    console.log("✅ Inquiry reCAPTCHA verified by user");
  };
  
  window.inquiryRecaptchaExpiredCallback = function() {
    console.warn("⚠️ Inquiry reCAPTCHA expired - user needs to verify again");
    showError('reCAPTCHA verification expired. Please verify again.');
  };
  
  window.inquiryRecaptchaErrorCallback = function() {
    console.error("❌ Inquiry reCAPTCHA error occurred");
    showError('reCAPTCHA error. Please refresh and try again.');
  };

  console.log("🎉 Initialization complete!");
});