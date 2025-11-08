<?php
session_start();
require_once 'connect.php';

// Restrict to logged-in residents
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident') {
  header('Location: login.php');
  exit;
}

$userId = intval($_SESSION['user_id']);
$user = null;

// Fetch resident details
if ($con) {
  $stmt = mysqli_prepare($con, "SELECT id, first_name, middle_name, last_name, email, phone, house_number, address FROM users WHERE id = ?");
  if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_num_rows($result) === 1) {
      $user = mysqli_fetch_assoc($result);
    }
    mysqli_stmt_close($stmt);
  }
}

if (!$user) {
  header('Location: mainpage.php');
  exit;
}

$fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$houseNumber = $user['house_number'] ?? '';
$email = $user['email'] ?? '';
$phone = $user['phone'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Guest Entry Pass - VictorianPass</title>
<link rel="icon" type="image/png" href="mainpage/logo.svg">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<style>
<?php echo file_get_contents('guestform.css') ?: '';?>
</style>
</head>
<body>

<header class="navbar">
  <div class="logo">
    <a href="mainpage.php"><img src="mainpage/logo.svg" alt="VictorianPass Logo"></a>
    <div class="brand-text">
      <h1>VictorianPass</h1>
      <p>Victorian Heights Subdivision</p>
    </div>
  </div>
  <div class="nav-actions">
    <a href="profileresident.php"><img src="mainpage/profile'.jpg" alt="Profile" class="profile-icon"></a>
  </div>
</header>

<section class="hero">
  <form class="entry-form" id="entryForm">
    <div class="form-header">
      <img src="mainpage/ticket.svg" alt="Entry Icon">
      <span>Guest Form</span>
    </div>

    <h4 style="margin:10px 0 5px;color:#23412e;">Resident Information</h4>
    <div class="form-row">
      <input type="text" id="resident_full_name" name="resident_full_name" placeholder="Resident Full Name*" value="<?php echo htmlspecialchars($fullName); ?>" required>
      <input type="text" id="resident_house" name="resident_house" placeholder="House/Unit No.*" value="<?php echo htmlspecialchars($houseNumber); ?>" required>
    </div>
    <div class="form-row">
      <input type="email" id="resident_email" name="resident_email" placeholder="Resident Email*" value="<?php echo htmlspecialchars($email); ?>" required>
      <input type="tel" id="resident_contact" name="resident_contact" placeholder="Resident Contact Number*" value="<?php echo htmlspecialchars($phone); ?>" required>
    </div>

    <h4 style="margin:20px 0 5px;color:#23412e;">Visitor Information</h4>
    <div class="form-row">
      <input type="text" id="visitor_first_name" name="visitor_first_name" placeholder="Visitor First Name*" required>
      <input type="text" id="visitor_last_name" name="visitor_last_name" placeholder="Visitor Last Name*" required>
    </div>
    <div class="form-row">
      <select name="visitor_sex" required>
        <option value="" disabled selected>Sex*</option>
        <option>Male</option>
        <option>Female</option>
      </select>
      <div class="form-group">
        <input type="date" id="birthdate" name="visitor_birthdate" placeholder=" " required>
        <label for="birthdate">Birthdate*</label>
      </div>
    </div>
    <input type="tel" id="visitor_contact" name="visitor_contact" placeholder="Visitor Contact Number*" required>
    <input type="email" id="visitor_email" name="visitor_email" placeholder="Visitor Email*" required>

    <label class="upload-box">
      <input type="file" name="visitor_valid_id" accept="image/*" hidden required>
      <img src="mainpage/upload.svg" alt="Upload">
      <p>Upload Visitor’s Valid ID*<br><small>(e.g. National ID, Driver’s License)</small></p>
    </label>

    <h4 style="margin:20px 0 5px;color:#23412e;">Visit Details</h4>
    <div class="form-row">
      <input type="date" name="visit_date" placeholder="Date of Visit*" required>
      <input type="time" name="visit_time" placeholder="Expected Time*" required>
    </div>
    <textarea rows="3" name="visit_purpose" placeholder="Purpose of Visit*" required></textarea>

    <div class="reserve-note">
      <label for="reserveCheck">
        <input type="checkbox" id="reserveCheck">
        Reserve an amenity instead of a regular visit
      </label>
      <div class="note-text">
        Check this box to proceed to the Next page for amenity reservation; you may leave the Visit Details section empty.
      </div>
    </div>

    <div class="form-actions">
      <a href="mainpage.php" class="btn-back">Back</a>
      <button type="submit" class="btn-next" id="submitBtn">Submit Request</button>
    </div>
  </form>
</section>

<!-- Modal -->
<div id="refModal" class="modal">
  <div class="modal-content">
    <h2>Request Submitted!</h2>
    <p>Your guest request has been successfully submitted.</p>
    <p><strong>Share this status code with your visitor:</strong></p>
    <div id="refCode" class="ref-code"></div>
    <p><small>Note: Your visitor will need this code to check the status of their Entry Pass,
      since they don’t have their own VictorianPass account.</small></p>
    <p><small><em>You can still view and manage the request in your resident dashboard.</em></small></p>
    <div style="display:flex;gap:10px;justify-content:center;margin-top:10px;flex-wrap:wrap;">
      <button class="close-btn" onclick="closeModal()">Close</button>
    </div>
  </div>
</div>
<script>
const reserveCheck = document.getElementById('reserveCheck');
const submitBtn   = document.getElementById('submitBtn');
const entryForm   = document.getElementById('entryForm');

function openModal(refCode){
  document.getElementById('refCode').textContent = refCode;
  document.getElementById('refModal').style.display = 'flex';
}
function closeModal(){
  document.getElementById('refModal').style.display = 'none';
}

// Client-side validation similar to signup
function blockDigits(e){ if(/[0-9]/.test(e.key)){ e.preventDefault(); alert('Numbers are not allowed in name fields.'); } }
function sanitizeNoDigits(e){ const cleaned=e.target.value.replace(/[0-9]/g,''); if(e.target.value!==cleaned){ e.target.value=cleaned; } }
function validatePhoneInput(el){ const val=el.value.trim(); return /^\+63\d+$/.test(val); }
['resident_full_name','visitor_first_name','visitor_last_name'].forEach(function(id){ const el=document.getElementById(id); if(!el) return; el.addEventListener('keydown',blockDigits); el.addEventListener('input',sanitizeNoDigits); });

// Toggle button behavior when reserving amenity
function updateSubmitBehavior(){
  if (reserveCheck.checked){
    submitBtn.textContent = 'Next';
    submitBtn.type = 'button';
    submitBtn.onclick = function(){ window.location.href = 'reserve.php'; };
  } else {
    submitBtn.textContent = 'Submit Request';
    submitBtn.type = 'submit';
    submitBtn.onclick = null;
  }
}
updateSubmitBehavior();
reserveCheck.addEventListener('change', updateSubmitBehavior);

entryForm.addEventListener('submit', async (e)=>{
  if (reserveCheck.checked) { return; }
  e.preventDefault();
  const rc=document.getElementById('resident_contact');
  const vc=document.getElementById('visitor_contact');
  let valid=true;
  if(rc && !validatePhoneInput(rc)){ alert('Resident contact must start with +63 and contain numbers only after.'); valid=false; }
  if(vc && !validatePhoneInput(vc)){ alert('Visitor contact must start with +63 and contain numbers only after.'); valid=false; }
  if(!valid) return;
  try {
    const fd = new FormData(entryForm);
    const res = await fetch('submit_guest.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data && data.success) {
      const ref = String(data.ref_code || '');
      const epId = String(data.entry_pass_id || '');
      openModal(ref);
    } else {
      alert(data.message || 'Failed to submit guest request.');
    }
  } catch (err) {
    alert('Error connecting to server.');
  }
});
</script>
</body>
</html>