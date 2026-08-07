<?php
// concept_inquiry.php
$inquiry_errors = $inquiry_errors ?? [];
$submitted_style = '';
?>

<!-- Modal -->
<div id="inquiryModal" class="modal">
  <div class="modal-content">
    <div class="modal-image">
      <img src="<?= CLIENT_ASSET ?>/images/magazine.png" alt="Catalogue">
    </div>
    <div class="modal-form">
      <button class="close-btn" id="closeModalBtn">&times;</button>
      <h2 id="modalTitle">REQUEST CUSTOMIZATION</h2>
      <p id="modalSubtitle">Fill up the form</p>

      <!-- Display Error Message -->
      <?php if (!empty($inquiry_errors) && isset($inquiry_errors['submit'])): ?>
        <div class="form-error-message">
          <i style="color: #721c24;">⚠</i>
          <p><?php echo $inquiry_errors['submit']; ?></p>
        </div>
      <?php endif; ?>

      <form method="POST" action="<?= BASE_URL ?>concept-process">
        <input type="hidden" name="concept_style" id="conceptStyleInput" value="">
        <input type="hidden" name="concept_id" id="conceptIdInput" value="">
        <input type="hidden" name="category_name" id="categoryNameInput" value="">

        <input type="text" name="project_type" id="projectTypeInput" placeholder="PROJECT TYPE*" required readonly
          class="readonly-field <?php echo isset($inquiry_errors['project_type']) ? 'error-field' : ''; ?>"
          value="<?php echo isset($_POST['project_type']) ? htmlspecialchars($_POST['project_type']) : ''; ?>">
        <?php if (isset($inquiry_errors['project_type'])): ?>
          <span class="error-text"><?php echo $inquiry_errors['project_type']; ?></span>
        <?php endif; ?>

        <input type="text" name="name" placeholder="Name*" required pattern="[a-zA-Z\s\-'\.]+"
          title="Name should only contain letters, spaces, hyphens, and apostrophes" minlength="2" maxlength="100"
          class="<?php echo isset($inquiry_errors['name']) ? 'error-field' : ''; ?>"
          value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
        <?php if (isset($inquiry_errors['name'])): ?>
          <span class="error-text"><?php echo $inquiry_errors['name']; ?></span>
        <?php endif; ?>

        <div class="row">
          <div style="flex: 1;">
            <input type="email" name="email" placeholder="Email*" required
              class="<?php echo isset($inquiry_errors['email']) ? 'error-field' : ''; ?>"
              value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            <?php if (isset($inquiry_errors['email'])): ?>
              <span class="error-text"><?php echo $inquiry_errors['email']; ?></span>
            <?php endif; ?>
          </div>

          <div style="flex: 1;">
            <input type="number" name="phone" placeholder="Phone* (09XXXXXXXXX)" required pattern="09[0-9]{9}"
              title="Enter 11-digit Philippine mobile number starting with 09" maxlength="11"
              class="<?php echo isset($inquiry_errors['phone']) ? 'error-field' : ''; ?>"
              value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
            <small class="phone-hint-modal">Format: 09XXXXXXXXX (11 digits)</small>
            <?php if (isset($inquiry_errors['phone'])): ?>
              <span class="error-text"><?php echo $inquiry_errors['phone']; ?></span>
            <?php endif; ?>
          </div>
        </div>

        <input type="text" name="address" placeholder="Full Address*" required pattern="[a-zA-Z0-9\s,\-\.]+"
          title="Address should only contain letters, numbers, spaces, commas, hyphens, and periods"
          class="<?php echo isset($inquiry_errors['address']) ? 'error-field' : ''; ?>"
          value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>">
        <?php if (isset($inquiry_errors['address'])): ?>
          <span class="error-text"><?php echo $inquiry_errors['address']; ?></span>
        <?php endif; ?>

        <select name="know_more_about" id="knowMoreSelect" required
          class="<?php echo isset($inquiry_errors['know_more_about']) ? 'error-field' : ''; ?>">
          <option value="" disabled selected>I WANT TO KNOW MORE OPTIONS ABOUT:*</option>
          <option value="Material Options">Material Options</option>
          <option value="Color Options">Color Options</option>
          <option value="Customization Process">Customization Process</option>
          <option value="Pricing & Packages">Pricing & Packages</option>
          <option value="Installation Services">Installation Services</option>
          <option value="Warranty & Maintenance">Warranty & Maintenance</option>
          <option value="Other">Other (Please specify below)</option>
        </select>
        <?php if (isset($inquiry_errors['know_more_about'])): ?>
          <span class="error-text"><?php echo $inquiry_errors['know_more_about']; ?></span>
        <?php endif; ?>

        <textarea name="additional_info" id="additionalInfoBox"
          placeholder="Please specify what you want to know more about..."
          class="additional-textarea <?php echo isset($inquiry_errors['additional_info']) ? 'error-field' : ''; ?>"><?php echo isset($_POST['additional_info']) ? htmlspecialchars($_POST['additional_info']) : ''; ?></textarea>
        <?php if (isset($inquiry_errors['additional_info'])): ?>
          <span class="error-text"><?php echo $inquiry_errors['additional_info']; ?></span>
        <?php endif; ?>

        <label class="checkbox <?php echo isset($inquiry_errors['terms']) ? 'error-field' : ''; ?>">
          <input type="checkbox" name="terms_accepted" required>
          I have read and accepted your Privacy Policy and Terms & Conditions.
        </label>
        <?php if (isset($inquiry_errors['terms'])): ?>
          <span class="error-text"><?php echo $inquiry_errors['terms']; ?></span>
        <?php endif; ?>

        <!-- Cloudflare Turnstile -->
        <div class="recaptcha-container">
          <div id="concept-turnstile" class="cf-turnstile" data-sitekey="<?php require_once $includes['recaptcha'];
          echo TURNSTILE_SITE_KEY; ?>" data-callback="conceptTurnstileCallback"
            data-expired-callback="conceptTurnstileExpiredCallback" data-error-callback="conceptTurnstileErrorCallback">
          </div>
          <?php if (isset($inquiry_errors['recaptcha'])): ?>
            <span class="error-text"><?php echo $inquiry_errors['recaptcha']; ?></span>
          <?php endif; ?>
        </div>

        <button type="submit" name="concept_inquiry_submit" class="submit-btn">REQUEST NOW</button>
      </form>
    </div>
  </div>
</div>

<script src="<?= CLIENT_ASSET ?>/inquiry_form/concept.js"></script>
<!-- Cloudflare Turnstile Script -->
<script src="<?php echo TURNSTILE_SCRIPT_URL; ?>" async defer></script>

<script>
  // Handle "Other" option to show/hide additional info textarea
  document.addEventListener('DOMContentLoaded', function () {
    const knowMoreSelect = document.getElementById('knowMoreSelect');
    const additionalInfoBox = document.getElementById('additionalInfoBox');

    if (knowMoreSelect && additionalInfoBox) {
      knowMoreSelect.addEventListener('change', function () {
        if (this.value === 'Other') {
          additionalInfoBox.style.display = 'block';
          additionalInfoBox.required = true;
        } else {
          additionalInfoBox.style.display = 'none';
          additionalInfoBox.required = false;
          additionalInfoBox.value = ''; // Clear the value
        }
      });
    }
  });
</script>

<style>
  .modal {
    display: flex;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.3s ease;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    justify-content: center;
    align-items: center;
    padding: 20px;
  }

  .modal-content {
    background: #f9f7f4;
    width: 90%;
    max-width: 900px;
    display: flex;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    max-height: 85vh;
    height: auto;
  }

  .modal-image {
    flex: 1;
    min-width: 300px;
    max-height: 85vh;
    overflow: hidden;
  }

  .modal-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .modal-form {
    flex: 1;
    padding: 30px 40px;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow-y: auto;
    max-height: 85vh;
  }

  .modal-form h2 {
    text-align: center;
    margin: 0 0 3px;
    font-size: 20px;
    color: #200101;
  }

  .modal-form p {
    text-transform: uppercase;
    font-family: 'Montserrat', sans-serif;
    text-align: center;
    margin: 0 0 15px;
    color: #2f12008c;
    font-size: 13px;
  }

  .modal-form form {
    display: flex;
    flex-direction: column;
  }

  .modal-form select,
  .modal-form input[type="text"],
  .modal-form input[type="email"],
  .modal-form input[type="number"] {
    padding: 10px;
    font-family: 'Montserrat', sans-serif;
    margin-bottom: 12px;
    border: none;
    border-bottom: 1px solid #ccc;
    background: transparent;
    font-size: 14px;
  }

  .additional-textarea {
    display: none;
    padding: 10px;
    margin-bottom: 12px;
    border: none;
    border-bottom: 1px solid #ccc;
    background: transparent;
    font-size: 14px;
    font-family: 'Montserrat', sans-serif;
    min-height: 80px;
    resize: vertical;
    width: 100%;
  }

  .additional-textarea:focus {
    outline: none;
    border-bottom: 2px solid #2F1200;
  }

  .readonly-field {
    background-color: #f5f5f5 !important;
    cursor: not-allowed;
    color: #666;
  }

  .readonly-field:focus {
    outline: none;
    border-bottom: 1px solid #ccc;
  }

  .modal-form .row {
    display: flex;
    gap: 10px;
    margin-bottom: 0;
  }

  .modal-form .checkbox {
    font-family: 'Montserrat', sans-serif;
    display: flex;
    align-items: center;
    font-size: 10px;
    color: #333;
    margin: 10px 0;
  }

  .modal-form .checkbox input {
    margin-right: 8px;
  }

  .submit-btn {
    letter-spacing: 2px;
    padding: 12px;
    font-family: 'Montserrat', sans-serif;
    background-color: #200101;
    color: white;
    border: none;
    cursor: pointer;
    font-size: 14px;
    margin-top: 10px;
    border-radius: 3px;
    transition: background-color 0.3s ease;
  }

  .submit-btn:hover {
    background-color: #160000ff;
  }

  .close-btn {
    position: absolute;
    top: 15px;
    right: 15px;
    background: #200101;
    border: none;
    font-size: 22px;
    cursor: pointer;
    color: white;
    z-index: 10;
    width: 35px;
    height: 35px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.3s ease, transform 0.2s ease;
    line-height: 1;
    padding: 0;
  }

  .close-btn:hover {
    background-color: #160000ff;
    transform: scale(1.1);
  }

  /* Error and Success Styles */
  .error-field {
    border-bottom: 2px solid #dc3545 !important;
  }

  .error-text {
    color: #dc3545;
    font-size: 11px;
    font-family: 'Montserrat', sans-serif;
    margin-top: -10px;
    margin-bottom: 10px;
    display: block;
  }

  /* Phone Hint for Modal */
  .phone-hint-modal {
    display: block;
    color: #666;
    font-size: 10px;
    margin-top: 3px;
    font-family: 'Montserrat', sans-serif;
    font-style: italic;
  }

  .form-success-message,
  .form-error-message {
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 15px;
    text-align: center;
    font-family: 'Montserrat', sans-serif;
    font-size: 13px;
  }

  .form-success-message {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
  }

  .form-error-message {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
  }

  .form-success-message i,
  .form-error-message i {
    font-size: 18px;
    margin-right: 8px;
  }

  @media (max-width: 768px) {
    .modal {
      align-items: center;
      padding: 20px 10px;
    }

    .modal-content {
      flex-direction: column;
      width: 95%;
      max-width: 100%;
      max-height: 95vh;
      margin: 10px auto;
    }

    .modal-image {
      display: none;
    }

    .modal-form {
      padding: 25px 20px;
      flex: 1;
      overflow-y: auto;
    }

    .modal-form h2 {
      font-size: 18px;
    }

    .modal-form p {
      font-size: 12px;
      margin-bottom: 15px;
    }

    .modal-form select,
    .modal-form input[type="text"],
    .modal-form input[type="email"],
    .modal-form input[type="tel"] {
      font-size: 13px;
      padding: 8px;
    }

    .modal-form .row {
      flex-direction: column;
      gap: 0;
    }

    .modal-form .checkbox {
      font-size: 9px;
    }

    .submit-btn {
      font-size: 12px;
      padding: 10px;
    }

    .close-btn {
      top: 10px;
      right: 10px;
      font-size: 20px;
      width: 32px;
      height: 32px;
    }
  }

  @media (max-width: 480px) {
    .modal-content {
      width: 100%;
      border-radius: 0;
      max-height: 100vh;
      margin: 0;
    }

    .modal {
      padding: 0;
    }

    .modal-image {
      display: none;
    }

    .modal-form {
      padding: 20px 15px;
    }

    .modal-form h2 {
      font-size: 16px;
    }

    .modal-form p {
      font-size: 11px;
    }

    .modal-form select,
    .modal-form input[type="text"],
    .modal-form input[type="email"],
    .modal-form input[type="tel"],
    .modal-form textarea {
      font-size: 12px;
      padding: 7px;
    }

    .submit-btn {
      font-size: 11px;
      letter-spacing: 1px;
    }
  }

  .modal.show {
    opacity: 1;
    visibility: visible;
  }

  /* reCAPTCHA Container Styling */
  .recaptcha-container {
    margin: 15px 0;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
  }

  .recaptcha-container .g-recaptcha {
    transform: scale(0.95);
    transform-origin: 0 0;
  }

  @media (max-width: 768px) {
    .recaptcha-container .g-recaptcha {
      transform: scale(0.85);
    }
  }

  @media (max-width: 480px) {
    .recaptcha-container .g-recaptcha {
      transform: scale(0.75);
    }
  }
</style>