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
// Normalize resident phone to 09 format if stored as +63
$phoneNormalized = $phone;
if (preg_match('/^\+63(9\d{9})$/', $phone)) {
  $phoneNormalized = '0' . substr($phone, 3); // +63xxxxxxxxxx -> 0xxxxxxxxxx
}
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
/* Inline warnings copied from signup style */
.field-warning {
  color: #333;
  font-size: 0.85rem;
  margin-top: 6px;
  background: #fff;
  border-left: 4px solid #c0392b; /* error accent */
  box-shadow: 0 2px 8px rgba(0,0,0,0.12);
  border-radius: 8px;
  padding: 8px 10px;
  display: flex;
  align-items: flex-start;
  gap: 8px;
  position: relative;
  z-index: 2;
}
.field-warning .warn-icon {
  width: 18px; height: 18px; border-radius: 50%;
  background: #c0392b; color: #fff; display: inline-flex;
  align-items: center; justify-content: center; font-size: 0.75rem;
  flex-shrink: 0; line-height: 1;
}
.field-warning .msg { color: #333; }
.field-warning .close-warn {
  margin-left: auto; background: transparent; border: 0; font-size: 1rem;
  cursor: pointer; color: #888; line-height: 1;
}
.field-warning .close-warn:hover { color: #555; }
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
      <input type="tel" id="resident_contact" name="resident_contact" placeholder="Resident Contact Number*" value="<?php echo htmlspecialchars($phoneNormalized); ?>" required>
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
      <input type="file" id="visitor_valid_id" name="visitor_valid_id" accept="image/*" hidden required>
      <img src="mainpage/upload.svg" alt="Upload">
      <p>Upload Visitor’s Valid ID*<br><small>(e.g. National ID, Driver’s License)</small></p>
    </label>

    <div id="idPreviewWrap" style="display:none;margin:8px 0 14px;">
      <img id="idPreview" alt="Valid ID Preview" style="max-width:240px;border-radius:10px;border:1px solid #e6ebe6;display:block;">
      <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" id="btnClearId" class="btn-next" style="background:#e6ebe6;color:#23412e;">Remove Selected ID</button>
      </div>
    </div>

    <h4 style="margin:20px 0 5px;color:#23412e;">Visit Details</h4>
    <div class="form-row">
      <input type="date" name="visit_date" placeholder="Date of Visit*" required>
      <input type="time" name="visit_time" placeholder="Expected Time*" required>
    </div>
    <textarea rows="3" name="visit_purpose" placeholder="Purpose of Visit*" required></textarea>

    <div class="reserve-note">
      <label for="reserveCheck">
        <input type="checkbox" id="reserveCheck" name="wants_amenity" value="1">
        Reserve an amenity instead of a regular visit
      </label>
      <div class="note-text">
        Check this box to proceed to the Next page for amenity reservation; you may leave the Visit Details section empty.
      </div>
    </div>

    <div class="form-actions">
      <a href="profileresident.php" class="btn-back">Back</a>
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
const idInput = document.getElementById('visitor_valid_id');
const idPreviewWrap = document.getElementById('idPreviewWrap');
const idPreview = document.getElementById('idPreview');
const btnClearId = document.getElementById('btnClearId');
const visitDate = entryForm.querySelector('input[name="visit_date"]');
const visitTime = entryForm.querySelector('input[name="visit_time"]');
const visitPurpose = entryForm.querySelector('textarea[name="visit_purpose"]');

function openModal(refCode){
  document.getElementById('refCode').textContent = refCode;
  document.getElementById('refModal').style.display = 'flex';
}
function closeModal(){
  document.getElementById('refModal').style.display = 'none';
}

// Inline warnings helper (mirrors signup)
function setWarning(key, message){
  const inputEl = document.getElementById(key);
  let container = null;
  if (inputEl){
    container = inputEl.closest('.form-group') || inputEl.closest('.form-row') || inputEl.closest('.upload-box') || inputEl.parentNode;
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
    let icon = warnEl.querySelector('.warn-icon');
    if (!icon){ icon = document.createElement('span'); icon.className='warn-icon'; icon.textContent='!'; warnEl.appendChild(icon); }
    let msgSpan = warnEl.querySelector('.msg');
    if (!msgSpan){ msgSpan = document.createElement('span'); msgSpan.className='msg'; warnEl.appendChild(msgSpan); }
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
    }
  } else {
    if (warnEl) warnEl.remove();
  }
}

// Client-side validation following signup patterns
function blockDigits(e){ if(/[0-9]/.test(e.key)){ e.preventDefault(); setWarning(e.target.id, 'Numbers are not allowed in this field.'); } }
function sanitizeNoDigits(e){ const val=e.target.value; const cleaned=val.replace(/[0-9]/g,''); if(val!==cleaned){ e.target.value=cleaned; setWarning(e.target.id,'Numbers were removed.'); } else { setWarning(e.target.id,''); } }
function isValidPhone(el){ const val=el.value.trim(); return /^09\d{9}$/.test(val); }
function isValidEmail(el){ const val=el.value.trim(); return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val); }
['resident_full_name','visitor_first_name','visitor_last_name'].forEach(function(id){ const el=document.getElementById(id); if(!el) return; el.addEventListener('keydown',blockDigits); el.addEventListener('input',sanitizeNoDigits); });

// Live phone/email warnings
['resident_contact','visitor_contact'].forEach(function(id){ const el=document.getElementById(id); if(!el) return; el.addEventListener('input', function(e){ setWarning(id, isValidPhone(el)? '' : 'Phone must start with 09 and contain numbers only.'); }); });
['resident_email','visitor_email'].forEach(function(id){ const el=document.getElementById(id); if(!el) return; el.addEventListener('input', function(e){ setWarning(id, isValidEmail(el)? '' : 'Please enter a valid email.'); }); });

// Toggle button behavior when reserving amenity
function updateSubmitBehavior(){
  if (reserveCheck.checked){
    // Keep as submit so we can create the guest_form then redirect with ref_code
    submitBtn.textContent = 'Next';
    submitBtn.type = 'submit';
    submitBtn.onclick = null;
    if (visitDate) visitDate.required = false;
    if (visitTime) visitTime.required = false;
    if (visitPurpose) visitPurpose.required = false;
  } else {
    submitBtn.textContent = 'Submit Request';
    submitBtn.type = 'submit';
    submitBtn.onclick = null;
    if (visitDate) visitDate.required = true;
    if (visitTime) visitTime.required = true;
    if (visitPurpose) visitPurpose.required = true;
  }
}
updateSubmitBehavior();
reserveCheck.addEventListener('change', updateSubmitBehavior);

// Preview selected valid ID and allow clearing
if (idInput) idInput.addEventListener('change', function(){
  const file = idInput.files && idInput.files[0];
  if (!file) { idPreviewWrap.style.display='none'; setWarning('visitor_valid_id','Please upload Visitor’s Valid ID.'); return; }
  const reader = new FileReader();
  reader.onload = function(e){ idPreview.src = e.target.result; idPreviewWrap.style.display = 'block'; };
  reader.readAsDataURL(file);
  setWarning('visitor_valid_id','');
});
if (btnClearId) btnClearId.addEventListener('click', function(){ idInput.value=''; idPreviewWrap.style.display='none'; setWarning('visitor_valid_id','Please upload Visitor’s Valid ID.'); });

entryForm.addEventListener('submit', async (e)=>{
  e.preventDefault();
  let valid = true;
  const reqIds = ['resident_full_name','resident_house','resident_email','resident_contact','visitor_first_name','visitor_last_name','visitor_contact','visitor_email','birthdate'];
  reqIds.forEach(function(id){ const el=document.getElementById(id); if(!el) return; if(!String(el.value||'').trim()){ setWarning(id,'This field is required.'); valid=false; }});
  const rc=document.getElementById('resident_contact'); const vc=document.getElementById('visitor_contact');
  if(rc && !isValidPhone(rc)){ setWarning('resident_contact','Phone must start with 09 and contain numbers only.'); valid=false; }
  if(vc && !isValidPhone(vc)){ setWarning('visitor_contact','Phone must start with 09 and contain numbers only.'); valid=false; }
  const re=document.getElementById('resident_email'); const ve=document.getElementById('visitor_email');
  if(re && !isValidEmail(re)){ setWarning('resident_email','Please enter a valid email.'); valid=false; }
  if(ve && !isValidEmail(ve)){ setWarning('visitor_email','Please enter a valid email.'); valid=false; }
  if(idInput && !(idInput.files && idInput.files[0])){ setWarning('visitor_valid_id','Please upload Visitor’s Valid ID.'); valid=false; }
  if(!valid) return;
  try {
    const fd = new FormData(entryForm);
    const res = await fetch('submit_guest.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data && data.success) {
      const ref = String(data.ref_code || '');
      if (reserveCheck.checked) {
        // Proceed to amenity reservation page carrying the ref_code
        const url = 'reserve_guest.php?wants_amenity=1&ref_code=' + encodeURIComponent(ref);
        window.location.href = url;
      } else {
        openModal(ref);
      }
    } else {
      setWarning('visitor_email', data.message || 'Failed to submit guest request.');
    }
  } catch (err) {
    setWarning('visitor_email', 'Error connecting to server.');
  }
});
</script>
</body>
</html>
