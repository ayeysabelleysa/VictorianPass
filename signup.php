<?php 
include("connect.php");
// Track registration success to show banner and auto-redirect
$registration_success = false;

// 🧠 Pre-fill verified house number (if user came from verify_house.php)
$verified_house = isset($_GET['house_number']) ? trim($_GET['house_number']) : '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $first_name = trim($_POST['first_name']);
  $middle_name = trim($_POST['middle_name']);
  $last_name = trim($_POST['last_name']);
  $phone = trim($_POST['phone']);
  // Normalize and validate email
  $email = strtolower(trim($_POST['email']));
  $password = trim($_POST['password']);
  $confirm_password = trim($_POST['confirm_password']);
  $sex = $_POST['sex'];
  $birthdate = $_POST['birthdate'];
  $house_number = isset($_POST['house_number']) ? trim($_POST['house_number']) : '';
  $address = isset($_POST['address']) ? trim($_POST['address']) : '';
  $terms_agreed = isset($_POST['terms_agreed']) ? $_POST['terms_agreed'] : '0';
  $serverErrors = [];

  // 🛑 Require verified house number
  if (empty($house_number)) {
    $serverErrors['house'] = 'Please verify your House Number before signing up.';
  }

  if ($password !== $confirm_password) {
    $serverErrors['confirm_password'] = 'Passwords do not match.';
  }

  // ✅ Require Terms & Conditions agreement (server-side safeguard)
  if ($terms_agreed !== '1') {
    $serverErrors['terms'] = 'Please read and agree to the Terms & Conditions.';
  }

  // 🔎 Validate email format
  if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $serverErrors['email'] = 'Please enter a valid email address.';
  } else {
    // 🔍 Check if email exists (case-insensitive, portable API)
    $checkEmail = $con->prepare("SELECT id FROM users WHERE LOWER(email) = ?");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $checkEmail->store_result();
    if ($checkEmail->num_rows > 0) {
      $serverErrors['email'] = 'Email already exists.';
    }
    $checkEmail->close();
  }

  // 🔍 Check house validity
  $checkHouse = $con->prepare("SELECT id FROM houses WHERE house_number = ?");
  $checkHouse->bind_param("s", $house_number);
  $checkHouse->execute();
  $checkHouse->store_result();
  if ($checkHouse->num_rows === 0) {
    $serverErrors['house'] = 'Invalid or unregistered House Number.';
  }
  $checkHouse->close();

  // 🚫 Prevent duplicate account for same house
  $checkDuplicate = $con->prepare("SELECT id FROM users WHERE house_number = ?");
  $checkDuplicate->bind_param("s", $house_number);
  $checkDuplicate->execute();
  $checkDuplicate->store_result();
  if ($checkDuplicate->num_rows > 0) {
    $serverErrors['house'] = 'This house already has a registered account.';
  }
  $checkDuplicate->close();

  // ✅ Register user if no errors
  if (empty($serverErrors)) {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $con->prepare("INSERT INTO users 
      (first_name, middle_name, last_name, phone, email, password, sex, birthdate, house_number, address, user_type)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'resident')");
    $stmt->bind_param("ssssssssss", 
      $first_name, $middle_name, $last_name, $phone, $email, $hashed, $sex, $birthdate, $house_number, $address);

    if ($stmt->execute()) {
      // Mark success; show banner and auto-redirect via JS
      $registration_success = true;
    } else {
      // Handle duplicate email race condition safely
      if ($con->errno === 1062) {
        $serverErrors['email'] = 'Email already exists.';
      } else {
        $serverErrors['form'] = 'An error occurred. Please try again.';
      }
    }
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign Up</title>
  <link rel="icon" type="image/png" href="mainpage/logo.svg">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="signup.css">
  <style>
    .success-banner {
      background: #e6ffed;
      color: #135f2a;
      border: 1px solid #b7e3c3;
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 12px;
      text-align: center;
      box-shadow: 0 2px 8px rgba(0,0,0,0.12);
      font-weight: 600;
    }
    .form-group { position: relative; flex: 1; }
    .form-group input[type="date"], .form-group select {
      width: 100%; padding: 12px; border: 1px solid #ccc;
      border-radius: 8px; font-size: 0.95rem; font-family: 'Poppins', sans-serif;
    }
    .form-group label {
      position: absolute; left: 12px; top: 12px;
      color: #888; font-size: 0.95rem;
      transition: 0.2s ease all; background: #fff; padding: 0 4px;
    }
    .form-group input[type="date"]:focus + label,
    .form-group input[type="date"]:not(:placeholder-shown) + label,
    .form-group select:focus + label,
    .form-group select:valid + label {
      top: -8px; left: 8px; font-size: 0.75rem; color: #23412e;
    }

    .instructions {
      font-size: 0.75rem;
      color: #555;
      margin-top: 10px;
      text-align: center;
    }

    .verify-link {
      display: flex;
      align-items: center;
      gap: 8px;
      color: green;
      font-weight: 500;
      text-decoration: none;
      cursor: pointer;
    }

    .verify-link img {
      width: 20px;
      height: 20px;
    }

    .verify-link:hover {
      text-decoration: underline;
    }

    .field-warning {
      color: #333;
      font-size: 0.85rem;
      margin-top: 6px;
      background: #fff;
      border-left: 4px solid #c0392b;
      box-shadow: 0 2px 8px rgba(0,0,0,0.12);
      border-radius: 8px;
      padding: 8px 10px;
      display: flex; /* notification-style inline card */
      align-items: flex-start;
      gap: 8px;
      position: relative;
      z-index: 2; /* ensure close button clickable over toggle icon */
    }
    .password-field { position: relative; display: block; }
    .toggle-password { top: 50%; transform: translateY(-50%); z-index: 0; }
    .field-warning .warn-icon {
      width: 18px; height: 18px; border-radius: 50%;
      background: #c0392b; color: #fff; display: inline-flex;
      align-items: center; justify-content: center; font-size: 0.75rem;
      flex-shrink: 0; line-height: 1;
    }
    .field-warning .msg { color: #333; }
    .field-warning .close-warn { margin-left: auto; background: transparent; border: 0; font-size: 1rem; }
    .field-warning:empty { display: none; }
    .close-warn { cursor: pointer; color: #888; line-height: 1; }
    .close-warn:hover { color: #555; }
  </style>
</head>

<body>
  <div class="page-wrapper">
    <div class="image-side">
      <img src="signuppage/bgsignup.png" alt="Victorian Heights Subdivision">
      <p class="branding">VictorianPass.</p>
    </div>

    <div class="form-side">
      <a href="mainpage.php" class="back-arrow">
        <img src="signuppage/back.svg" alt="Back">
      </a>

      <?php if ($registration_success): ?>
        <div class="success-banner">✅ You have successfully registered! Redirecting to login…</div>
      <?php endif; ?>
      <h1>Sign Up</h1>
      <p class="subtitle">Create your Account</p>

      <form class="signup-form" id="signupForm" method="POST" action="signup.php" novalidate <?php if ($registration_success) echo 'style="display:none"'; ?>>
        <input type="hidden" id="terms_agreed" name="terms_agreed" value="0">
        <div class="form-row">
          <div class="input-wrap">
            <input type="text" name="first_name" id="first_name" placeholder="First Name*" required>
          </div>
          <div class="input-wrap">
            <input type="text" name="last_name" id="last_name" placeholder="Last Name*" required>
          </div>
          <div class="input-wrap">
            <input type="text" name="middle_name" id="middle_name" placeholder="Middle Name*" required>
          </div>
        </div>

        <div class="form-row">
          <div class="input-wrap">
            <input type="tel" name="phone" id="phone" placeholder="Phone Number*" required>
          </div>
        </div>

        <div class="input-wrap">
          <input type="email" name="email" id="email" placeholder="Email*" required>
        </div>

        <!-- ✅ House verification section -->
        <div class="form-row homeowner">
          <a href="#" class="verify-link" onclick="openHouseModal(); return false;">
            <img src="signuppage/location.svg" alt="Location"> Verify House Number
          </a>
          <span id="houseVerifiedBadge" style="display:none;margin-left:8px;color:#23412e;font-weight:600;">Verified</span>
          <input type="text" id="addressField" name="address" placeholder="Enter your full address (required)" required>
          <input type="hidden" id="houseHidden" name="house_number" value="<?php echo htmlspecialchars($verified_house); ?>">
        </div>

        <div class="form-row">
          <div class="form-group">
            <select name="sex" required>
              <option value="" disabled selected></option>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
            </select>
            <label>Sex*</label>
          </div>
          <div class="form-group">
            <input type="date" name="birthdate" placeholder=" " required>
            <label>Birthdate*</label>
          </div>
        </div>

        <div class="password-field">
          <input type="password" id="password" name="password" placeholder="Password*" required>
          <span class="toggle-password" onclick="togglePassword('password', this)">👁️</span>
        </div>

        <div class="password-field">
          <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm Password*" required>
          <span class="toggle-password" onclick="togglePassword('confirm_password', this)">👁️</span>
        </div>

        <div class="form-actions">
          <button type="button" class="btn cancel" onclick="window.location.href='mainpage.php'">Cancel</button>
          <button type="submit" class="btn confirm">Confirm</button>
        </div>

        <p class="login-link">Already have an account? <a href="login.php">Log in</a></p>
      </form>

      <div class="terms">
        <input type="checkbox" id="terms" required disabled>
        <label for="terms">
          By using the <strong>VictorianPass</strong>, you agree to the rules set for security, privacy, and orderly access.
          <a onclick="openTerms()" style="text-decoration: underline; color: rgb(245, 63, 169);">Read Terms & Conditions</a>
        </label>

      </div>

      <p class="instructions"><i>Residents: Please verify your unique House Number on the next page to confirm residency.</i></p>
    </div>

    <!-- Terms Modal -->
    <div id="termsModal" class="modal">
      <div class="modal-content">
        <span class="close" onclick="closeTerms()">&times;</span>
        <h2>Terms & Services</h2>
        <p><strong>In using this website you are deemed to have read and agreed to the following terms and conditions:</strong></p>
        <p>
          The following terminology applies to these Terms and Conditions, Privacy Statement and Disclaimer Notice and any or all Agreements:
          "Customer", "You" and "Your" refers to you, the person accessing this website and accepting the Company's terms and conditions.
        </p>
        <h3>Effective Date: [September 00, 2025]</h3>
        <h4>1. User Roles</h4>
        <ul>
          <li>Residents: Must provide accurate info, manage guest entries responsibly, and use the system for valid purposes only.</li>
          <li>Visitors: Must present valid QR codes and follow subdivision rules.</li>
          <li>Admins/Guards/HOA: Manage logs, approve entries, and maintain system security.</li>
        </ul>
        <h4>2. Privacy and Data</h4>
        <ul>
          <li>Your data is used only for entry validation and amenity booking.</li>
          <li>No data will be shared without consent unless required by law.</li>
        </ul>
        <h4>3. Amenity Booking</h4>
        <ul>
          <li>Bookings are first-come, first-served.</li>
          <li>Cancel if unable to attend.</li>
          <li>Misuse may result in account restriction.</li>
          <li>All billings will be done by walk-in.</li>
        </ul>
        <h4>4. QR Code Rules</h4>
        <ul>
          <li>QR codes are unique and time-limited.</li>
          <li>Sharing or tampering with codes is prohibited.</li>
        </ul>
        <h4>5. Violations</h4>
        <ul>
          <li>Misuse may result in blacklisting or suspension.</li>
          <li>HOA reserves the right to restrict access if rules are broken.</li>
        </ul>
        <h4>6. System Use</h4>
        <ul>
          <li>System may go offline for updates.</li>
          <li>Users accept possible downtime.</li>
        </ul>
        <button class="btn confirm" onclick="agreeTerms()">I Agree</button>
      </div>
    </div>

    <!-- House Verification Modal -->
    <div id="houseModal" class="modal" style="display:none;">
      <div class="modal-content">
        <span class="close" onclick="closeHouseModal()">&times;</span>
        <h2>Verify House Number</h2>
        <p>Enter your registered House Number as listed in HOA records.</p>
        <input type="text" id="houseInput" placeholder="e.g., VH-1023" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;font-family:'Poppins',sans-serif;">
        <div style="display:flex;gap:10px;margin-top:12px;">
          <button class="btn cancel" onclick="closeHouseModal()">Cancel</button>
          <button class="btn confirm" onclick="performHouseVerify()">Verify</button>
        </div>
        <p id="verifyStatus" style="margin-top:10px;font-size:0.9rem;color:#23412e;display:none;"></p>
      </div>
    </div>
  </div>
  

  <script>
    function togglePassword(id, el) {
      const input = document.getElementById(id);
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
    }

    function openTerms() {
      const modal = document.getElementById('termsModal');
      if (modal) modal.style.display = 'block';
    }

    function closeTerms() {
      const modal = document.getElementById('termsModal');
      if (modal) modal.style.display = 'none';
    }

    function agreeTerms() {
      const terms = document.getElementById('terms');
      if (terms) {
        terms.checked = true;
        terms.disabled = false;
      }
      const ta = document.getElementById('terms_agreed');
      if (ta) { ta.value = '1'; }
      closeTerms();
      // Clear any popover on terms
      if (typeof setWarning === 'function') setWarning('terms', '');
    }
    // Floating popover warnings anchored under inputs
    const _popovers = {};
    function _ensureLayer(){ return document.getElementById('warnLayer'); }
    function _positionPopover(inputEl, pop){
      const rect = inputEl.getBoundingClientRect();
      const top = rect.bottom + 8 + window.scrollY;
      let left = rect.left + window.scrollX;
      // Clamp within viewport
      const vw = document.documentElement.clientWidth;
      const popW = pop.offsetWidth || 260;
      const minLeft = 12 + window.scrollX;
      const maxLeft = vw - popW - 12 + window.scrollX;
      left = Math.max(minLeft, Math.min(left, maxLeft));
      pop.style.top = top + 'px';
      pop.style.left = left + 'px';
    }
    function _removePopover(key){
      const layer = _ensureLayer();
      const pop = _popovers[key];
      if (pop && layer && pop.parentNode === layer) layer.removeChild(pop);
      delete _popovers[key];
    }
    function _showPopover(inputId, message){
      const inputEl = document.getElementById(inputId);
      if (!inputEl || !message) return;
      const layer = _ensureLayer();
      if (!layer) return;
      let pop = _popovers[inputId];
      if (!pop){
        pop = document.createElement('div');
        pop.className = 'field-popover';
        pop.setAttribute('role','alert');
        pop.dataset.key = inputId;
        pop.innerHTML = '<span class="popover-icon">!</span><span class="msg"></span><span class="popover-close" aria-label="Dismiss" title="Dismiss">&times;</span>';
        layer.appendChild(pop);
        _popovers[inputId] = pop;
        const closer = pop.querySelector('.popover-close');
        closer.setAttribute('tabindex','0');
        closer.addEventListener('click', function(){ _removePopover(inputId); });
        closer.addEventListener('keydown', function(evt){ if (evt.key==='Enter'||evt.key===' ') { evt.preventDefault(); _removePopover(inputId);} });
      }
      const msg = pop.querySelector('.msg');
      if (msg) msg.textContent = message;
      _positionPopover(inputEl, pop);
    }
    function _repositionAll(){
      Object.keys(_popovers).forEach(function(key){
        const inp = document.getElementById(key);
        const pop = _popovers[key];
        if (inp && pop) _positionPopover(inp, pop);
      });
    }
    window.addEventListener('scroll', _repositionAll, { passive: true });
    window.addEventListener('resize', _repositionAll);
    function setWarning(key, message){
      const inputEl = document.getElementById(key);
      let container = null;
      if (key === 'houseHidden') {
        container = document.querySelector('.homeowner');
      } else if (key === 'terms') {
        container = document.querySelector('.terms');
      } else if (inputEl) {
        container = inputEl.closest('.input-wrap') || inputEl.closest('.password-field') || inputEl.closest('.form-group');
      }
      if (!container) return;
      let warnEl = container.querySelector('.field-warning[data-for="'+key+'"]');
      if (message){
        if (!warnEl){
          warnEl = document.createElement('div');
          warnEl.className = 'field-warning';
          warnEl.setAttribute('data-for', key);
          warnEl.setAttribute('role','alert');
          container.appendChild(warnEl);
        }
        // Build notification-style content
        let icon = warnEl.querySelector('.warn-icon');
        if (!icon){
          icon = document.createElement('span');
          icon.className = 'warn-icon';
          icon.textContent = '!';
          warnEl.appendChild(icon);
        }
        let msgSpan = warnEl.querySelector('.msg');
        if (!msgSpan){
          msgSpan = document.createElement('span');
          msgSpan.className = 'msg';
          warnEl.appendChild(msgSpan);
        }
        msgSpan.textContent = message;
        let closer = warnEl.querySelector('.close-warn');
        if (!closer){
          closer = document.createElement('button');
          closer.type = 'button';
          closer.className = 'close-warn';
          closer.setAttribute('aria-label','Dismiss');
          closer.textContent = '\u00d7';
          warnEl.appendChild(closer);
          closer.addEventListener('click', function(){ warnEl.remove(); });
          closer.addEventListener('keydown', function(evt){ if (evt.key==='Enter' || evt.key===' '){ evt.preventDefault(); warnEl.remove(); } });
        }
      } else {
        if (warnEl) warnEl.remove();
      }
    }

    document.addEventListener('DOMContentLoaded', function() {
      const first = document.getElementById('first_name');
      const last = document.getElementById('last_name');
      const middle = document.getElementById('middle_name');
      const phone = document.getElementById('phone');
      const terms = document.getElementById('terms');
      const password = document.getElementById('password');
      const confirmPwd = document.getElementById('confirm_password');
      const houseHidden = document.getElementById('houseHidden');
      const form = document.getElementById('signupForm');

      function blockDigits(e) {
        if (/[0-9]/.test(e.key)) {
          e.preventDefault();
          setWarning(e.target.id, 'Numbers are not allowed in this field.');
        }
      }

      function sanitizeNoDigits(e) {
        const val = e.target.value;
        const cleaned = val.replace(/[0-9]/g, '');
        if (val !== cleaned) {
          e.target.value = cleaned;
          setWarning(e.target.id, 'Numbers were removed.');
        } else {
          // no toast for clearing
        }
      }

      [first, last, middle].forEach(function(inp) {
        if (!inp) return;
        inp.addEventListener('keydown', blockDigits);
        inp.addEventListener('input', sanitizeNoDigits);
      });

      if (phone) {
        phone.addEventListener('input', function(e) {
          const val = e.target.value;
          const isValidPrefix = val.startsWith('+09');
          const rest = val.slice(3);
          const restDigitsOnly = /^\d*$/.test(rest);
          if (!isValidPrefix || !restDigitsOnly) {
            setWarning('phone', 'Contact must start with +09 and contain numbers only.');
          } else {
            setWarning('phone', '');
          }
        });
      }

      // Live password mismatch feedback
      if (confirmPwd) {
        confirmPwd.addEventListener('input', function(e) {
          if (password && password.value !== e.target.value) {
            setWarning('confirm_password', 'Passwords do not match.');
          } else {
            setWarning('confirm_password', '');
          }
        });
      }

      if (form) {
        form.addEventListener('submit', function(e) {
          let valid = true;

          // Names: ensure no digits
          [first, last, middle].forEach(function(inp) {
            if (!inp) return;
            if (/\d/.test(inp.value)) {
              setWarning(inp.id, 'Numbers are not allowed in this field.');
              valid = false;
            }
            if (!inp.value.trim()) {
              setWarning(inp.id, 'This field is required.');
              valid = false;
            }
          });

          // Phone format: +09 followed by digits
          if (phone) {
            const val = phone.value;
            if (!/^\+09\d+$/.test(val)) {
              setWarning('phone', 'Contact must start with +09 and contain numbers only.');
              valid = false;
            }
          }

          // Password match check
          if (password && confirmPwd && password.value !== confirmPwd.value) {
            setWarning('confirm_password', 'Passwords do not match.');
            valid = false;
          }

          // Require verified house number: open verify modal when missing
          if (houseHidden && !houseHidden.value.trim()) {
            setWarning('houseHidden', 'Please verify your House Number.');
            if (typeof openHouseModal === 'function') openHouseModal();
            valid = false;
          }

          // Terms: show inline warning and block submit until agreed
          if (terms && !terms.checked) {
            setWarning('terms', 'Please read and agree to the Terms & Conditions.');
            valid = false;
          }

          if (!valid) e.preventDefault();
        });
      }
    });
  </script>
  <!-- Inline warnings are inserted per field; floating layer removed -->
  <?php if (!empty($serverErrors ?? [])) { ?>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const warnings = <?php echo json_encode($serverErrors, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      Object.entries(warnings).forEach(function([key, msg]) {
        setWarning(key, msg);
      });
    });
  </script>
  <?php } ?>

  <?php if ($registration_success) { ?>
  <script>
    // Auto-redirect to login after brief success message
    setTimeout(function(){ window.location.href = 'login.php'; }, 2500);
  </script>
  <?php } ?>

  <script>
    function openHouseModal(){
      const modal = document.getElementById('houseModal');
      const hidden = document.getElementById('houseHidden').value;
      if(hidden){
        document.getElementById('houseInput').value = hidden;
      }
      document.getElementById('verifyStatus').style.display = 'none';
      document.getElementById('verifyStatus').textContent = '';
      modal.style.display = 'block';
    }
    function closeHouseModal(){
      document.getElementById('houseModal').style.display = 'none';
    }
    async function performHouseVerify(){
      const house = document.getElementById('houseInput').value.trim();
      const status = document.getElementById('verifyStatus');
      status.style.display = 'none';
      status.textContent = '';
      if(!house){
        status.style.display = 'block';
        status.style.color = '#c0392b';
        status.textContent = 'Please enter a house number.';
        return;
      }
      try{
        const res = await fetch('verify_house.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ ajax:'1', house_number: house }).toString()
        });
        const data = await res.json();
        if(data && data.success){
          document.getElementById('houseHidden').value = house;
          const badge = document.getElementById('houseVerifiedBadge');
          badge.style.display = 'inline';
          badge.textContent = 'Verified: ' + house;
          status.style.display = 'block';
          status.style.color = '#23412e';
          status.textContent = 'House number is valid and saved.';
          // Keep modal open briefly for feedback, then close
          setTimeout(closeHouseModal, 800);
        } else {
          status.style.display = 'block';
          status.style.color = '#c0392b';
          status.textContent = data && data.message ? data.message : 'Invalid or unregistered house number.';
        }
      } catch(err){
        status.style.display = 'block';
        status.style.color = '#c0392b';
        status.textContent = 'Verification failed. Please try again.';
      }
    }
  </script>

  <script>
    function togglePassword(id, el) {
      const input = document.getElementById(id);
      input.type = input.type === "password" ? "text" : "password";
      el.textContent = input.type === "password" ? "👁️" : "🙈";
    }

    function openTerms() {
      document.getElementById("termsModal").style.display = "block";
    }
    function closeTerms() {
      document.getElementById("termsModal").style.display = "none";
    }
    function agreeTerms() {
      const checkbox = document.getElementById("terms");
      checkbox.disabled = false;
      checkbox.checked = true;
      const ta = document.getElementById('terms_agreed');
      if (ta) { ta.value = '1'; }
      closeTerms();
    }
  </script>
</body>
</html>
