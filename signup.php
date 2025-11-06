<?php 
include("connect.php");

// 🧠 Pre-fill verified house number (if user came from verify_house.php)
$verified_house = isset($_GET['house_number']) ? trim($_GET['house_number']) : '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $first_name = trim($_POST['first_name']);
  $middle_name = trim($_POST['middle_name']);
  $last_name = trim($_POST['last_name']);
  $phone = trim($_POST['phone']);
  $email = trim($_POST['email']);
  $password = trim($_POST['password']);
  $confirm_password = trim($_POST['confirm_password']);
  $sex = $_POST['sex'];
  $birthdate = $_POST['birthdate'];
  $house_number = isset($_POST['house_number']) ? trim($_POST['house_number']) : '';
  $address = isset($_POST['address']) ? trim($_POST['address']) : '';

  // 🛑 Require verified house number
  if (empty($house_number)) {
    echo "<script>alert('⚠️ Please verify your House Number before signing up.');</script>";
    exit;
  }

  if ($password !== $confirm_password) {
    echo "<script>alert('⚠️ Passwords do not match!');</script>";
    exit;
  }

  // 🔍 Check if email exists
  $checkEmail = $con->prepare("SELECT id FROM users WHERE email = ?");
  $checkEmail->bind_param("s", $email);
  $checkEmail->execute();
  $emailResult = $checkEmail->get_result();
  if ($emailResult->num_rows > 0) {
    echo "<script>alert('⚠️ Email already exists!');</script>";
    exit;
  }

  // 🔍 Check house validity
  $checkHouse = $con->prepare("SELECT id FROM houses WHERE house_number = ?");
  $checkHouse->bind_param("s", $house_number);
  $checkHouse->execute();
  $houseResult = $checkHouse->get_result();
  if ($houseResult->num_rows === 0) {
    echo "<script>alert('❌ Invalid or unregistered House Number!');</script>";
    exit;
  }

  // 🚫 Prevent duplicate account for same house
  $checkDuplicate = $con->prepare("SELECT id FROM users WHERE house_number = ?");
  $checkDuplicate->bind_param("s", $house_number);
  $checkDuplicate->execute();
  $dupResult = $checkDuplicate->get_result();
  if ($dupResult->num_rows > 0) {
    echo "<script>alert('⚠️ This house already has a registered account!');</script>";
    exit;
  }

  // ✅ Register user
  $hashed = password_hash($password, PASSWORD_DEFAULT);
  $stmt = $con->prepare("INSERT INTO users 
    (first_name, middle_name, last_name, phone, email, password, sex, birthdate, house_number, address, user_type)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'resident')");
  $stmt->bind_param("ssssssssss", 
    $first_name, $middle_name, $last_name, $phone, $email, $hashed, $sex, $birthdate, $house_number, $address);

  if ($stmt->execute()) {
    echo "<script>alert('✅ House number verified. Registration successful! Redirecting to login...'); window.location.href='login.php';</script>";
  } else {
    echo "<script>alert('❌ Error: " . $con->error . "');</script>";
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

      <h1>Sign Up</h1>
      <p class="subtitle">Create your Account</p>

      <form class="signup-form" method="POST" action="">
        <div class="form-row">
          <input type="text" name="first_name" placeholder="First Name*" required>
          <input type="text" name="last_name" placeholder="Last Name*" required>
          <input type="text" name="middle_name" placeholder="Middle Name*" required>
        </div>

        <div class="form-row">
          <input type="tel" name="phone" placeholder="Phone Number*" required>
        </div>

        <input type="email" name="email" placeholder="Email*" required>

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
      closeTerms();
    }
  </script>
</body>
</html>
