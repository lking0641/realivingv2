document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('inquiryModal');
  const closeModalBtn = document.getElementById('closeModalBtn');
  let modalClickHandler = null;
  let modalKeyHandler = null;

  // Function to properly close modal and cleanup
  function closeModal() {
    modal.classList.remove('show');
    
    // Clean up event listeners
    if (modalClickHandler) {
      window.removeEventListener('click', modalClickHandler);
      modalClickHandler = null;
    }
    if (modalKeyHandler) {
      document.removeEventListener('keydown', modalKeyHandler);
      modalKeyHandler = null;
    }
    
    // Reset Turnstile if available
    if (typeof turnstile !== 'undefined' && turnstile.reset) {
      try {
        turnstile.reset();
        console.log("✅ Turnstile reset after modal close");
      } catch (e) {
        console.warn("⚠️ Could not reset Turnstile:", e);
      }
    }
  }

  // Handle "Customize Your Cabinet" button clicks
  document.querySelectorAll('.cta-button').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      
      // Get concept ID, title, and category from data attributes
      const conceptId = btn.getAttribute('data-concept-id') || '';
      const styleTitle = btn.getAttribute('data-concept-title') || 'Your Style';
      const categoryName = btn.getAttribute('data-category-name') || '';
      
      // Update modal title and hidden inputs
      document.getElementById('modalTitle').textContent = `${styleTitle} CUSTOMIZATION`;
      document.getElementById('conceptStyleInput').value = styleTitle;
      document.getElementById('conceptIdInput').value = conceptId;
      document.getElementById('categoryNameInput').value = categoryName;
      
      // Auto-fill project type with category name
      if (categoryName) {
        document.getElementById('projectTypeInput').value = categoryName;
      }
      
      // Show modal
      modal.classList.add('show');
      
      // Setup modal click outside handler
      modalClickHandler = function(event) {
        if (event.target === modal) {
          closeModal();
        }
      };
      window.addEventListener('click', modalClickHandler);
      
      // Setup escape key handler
      modalKeyHandler = function(e) {
        if (e.key === 'Escape' && modal.classList.contains('show')) {
          closeModal();
        }
      };
      document.addEventListener('keydown', modalKeyHandler);
    });
  });

  // Close button handler
  closeModalBtn.addEventListener('click', closeModal);
  
  // Form submission validation
  const conceptForm = document.querySelector('#inquiryModal form');
  if (conceptForm) {
    conceptForm.addEventListener('submit', function(e) {
      // Reset any previous error states
      const inputs = this.querySelectorAll('input, textarea, select');
      inputs.forEach(input => input.classList.remove('error'));
      
      // Validate Philippine phone number
      const phoneInput = this.querySelector('input[name="phone"]');
      if (phoneInput) {
        const phone = phoneInput.value.trim();
        const phoneDigits = phone.replace(/[^0-9]/g, '');
        
        if (!/^09[0-9]{9}$/.test(phoneDigits)) {
          alert('Please enter a valid 11-digit Philippine mobile number starting with 09 (e.g., 09123456789).');
          phoneInput.classList.add('error');
          phoneInput.focus();
          e.preventDefault();
          return false;
        }
      }
      
      // Validate name (letters, spaces, hyphens, apostrophes only)
      const nameInput = this.querySelector('input[name="name"]');
      if (nameInput) {
        const name = nameInput.value.trim();
        if (!/^[a-zA-Z\s\-'\.]+$/.test(name)) {
          alert('Name should only contain letters, spaces, hyphens, and apostrophes.');
          nameInput.classList.add('error');
          nameInput.focus();
          e.preventDefault();
          return false;
        }
      }
      
      // Validate address (allow letters, numbers, spaces, commas, hyphens, periods)
      const addressInput = this.querySelector('input[name="address"]');
      if (addressInput) {
        const address = addressInput.value.trim();
        if (address && !/^[a-zA-Z0-9\s,\-\.]+$/.test(address)) {
          alert('Address should only contain letters, numbers, spaces, commas, hyphens, and periods.');
          addressInput.classList.add('error');
          addressInput.focus();
          e.preventDefault();
          return false;
        }
      }
      
      // Check Turnstile
      if (typeof turnstile !== 'undefined') {
        const turnstileResponse = turnstile.getResponse();
        if (!turnstileResponse || turnstileResponse.length === 0) {
          alert('Please complete the verification check.');
          e.preventDefault();
          return false;
        }
      } else {
        alert('Verification widget is not loaded. Please refresh the page and try again.');
        e.preventDefault();
        return false;
      }
      
      return true;
    });
  }
});

// Turnstile callback functions for concept inquiry form
window.conceptTurnstileCallback = function() {
  console.log("✅ Concept inquiry Turnstile verified");
};

window.conceptTurnstileExpiredCallback = function() {
  console.warn("⚠️ Concept inquiry Turnstile expired");
  alert('Verification expired. Please verify again.');
};

window.conceptTurnstileErrorCallback = function() {
  console.error("❌ Concept inquiry Turnstile error");
  alert('Verification error. Please refresh and try again.');
};