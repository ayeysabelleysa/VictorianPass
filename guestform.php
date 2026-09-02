<?php
require_once __DIR__ . '/session_bootstrap.php';
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
$phoneNormalized = $phone;
$pClean = preg_replace('/\D/', '', $phoneNormalized);
if (strlen($pClean) === 11 && strpos($pClean, '09') === 0) {
    $phoneNormalized = $pClean;
} elseif (strlen($pClean) === 12 && strpos($pClean, '639') === 0) {
    $phoneNormalized = '0' . substr($pClean, 2);
} elseif (strlen($pClean) === 10 && strpos($pClean, '9') === 0) {
    $phoneNormalized = '0' . $pClean;
}

$guestRows = [];
if ($con instanceof mysqli) {
  $stmtG = $con->prepare("SELECT id, visitor_first_name, visitor_middle_name, visitor_last_name, visitor_email, visitor_contact, created_at, ref_code FROM guest_forms WHERE resident_user_id = ? AND approval_status <> 'deleted' ORDER BY created_at DESC");
  if ($stmtG) {
    $stmtG->bind_param('i', $userId);
    $stmtG->execute();
    $resG = $stmtG->get_result();
    while ($rowG = $resG->fetch_assoc()) {
      $guestRows[] = $rowG;
    }
    $stmtG->close();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Guests - VictorianPass</title>
<link rel="icon" type="image/png" href="images/logo.svg">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
<?php
$gfCss = @file_get_contents(__DIR__ . '/CSS/guestform.css');
if ($gfCss === false) { $gfCss = @file_get_contents('CSS/guestform.css'); }
echo $gfCss !== false ? $gfCss : '';
?>
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

  .center-modal {
      display: none;
      position: fixed;
      z-index: 9999;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
      align-items: center;
      justify-content: center;
  }
  .center-modal-content {
      background-color: #fff;
      padding: 30px;
      border-radius: 12px;
      width: 90%;
      max-width: 500px;
      text-align: center;
      position: relative;
      box-shadow: 0 4px 15px rgba(0,0,0,0.2);
      animation: fadeIn 0.3s;
  }
  .center-modal-content h3 { margin-top: 0; color: #23412e; }
  .close-center {
      position: absolute;
      top: 10px;
      right: 15px;
      font-size: 24px;
      cursor: pointer;
      color: #888;
  }
  @keyframes fadeIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
</head>
<body>

<header class="navbar">
  <div class="logo">
    <a href="mainpage.php"><img src="images/logo.svg" alt="VictorianPass Logo"></a>
    <div class="brand-text">
      <h1>VictorianPass</h1>
      <p>Victorian Heights Subdivision</p>
    </div>
  </div>
  <div class="nav-actions">
    <a href="profileresident.php"><img src="images/mainpage/profile'.jpg" alt="Profile" class="profile-icon"></a>
  </div>
</header>

<section class="hero" id="addGuestSection">
  <form class="entry-form" id="entryForm" method="post" enctype="multipart/form-data">
    <div class="booking-steps" aria-label="Guest form steps">
      <div class="booking-steps-header">
        <div class="booking-steps-label">Guest form steps</div>
        <button type="button" class="booking-steps-toggle" id="bookingStepsToggle" aria-label="Minimize instructions" aria-expanded="true">−</button>
      </div>
      <div class="booking-steps-body">
        <div class="booking-step is-active" id="step-resident">
          <div class="step-index">1</div>
          <div class="step-content">
            <div class="step-title">Resident information</div>
            <div class="step-subtitle">Confirm your name, house/unit, and contact details</div>
          </div>
        </div>
        <div class="booking-step" id="step-guest">
          <div class="step-index">2</div>
          <div class="step-content">
            <div class="step-title">Guest information</div>
            <div class="step-subtitle">Enter your guest’s personal and contact details</div>
          </div>
        </div>
        <div class="booking-step" id="step-upload">
          <div class="step-index">3</div>
          <div class="step-content">
            <div class="step-title">Upload ID &amp; save</div>
            <div class="step-subtitle">Add a valid ID and save the guest to your list</div>
          </div>
        </div>
      </div>
    </div>
    <div class="form-header">
      <img src="images/mainpage/ticket.svg" alt="Entry Icon">
      <span>Add Guest</span>
    </div>

    <h4 style="margin:10px 0 5px;color:#111827;">Resident Information</h4>
    <div class="form-row">
      <input type="text" id="resident_full_name" name="resident_full_name" placeholder="Resident Full Name*" value="<?php echo htmlspecialchars($fullName); ?>" required>
      <input type="text" id="resident_house" name="resident_house" placeholder="House/Unit No.*" value="<?php echo htmlspecialchars($houseNumber); ?>" required>
    </div>
    <div class="form-row">
      <div style="flex:1;">
        <input type="email" id="resident_email" name="resident_email" placeholder="Resident Email*" value="<?php echo htmlspecialchars($email); ?>" required>
      </div>
      <div style="flex:1;">
        <input type="tel" id="resident_contact" name="resident_contact" placeholder="Resident Phone Number*" value="<?php echo htmlspecialchars($phoneNormalized); ?>" required>
        <span style="display:block; font-size:0.75rem; color:#666; margin-top:4px;">Format: 09XX XXX XXXX (11 digits)</span>
      </div>
    </div>

    <h4 style="margin:20px 0 5px;color:#111827;">Guest Information</h4>
    <div class="form-row">
      <input type="text" id="visitor_first_name" name="visitor_first_name" placeholder="Visitor First Name*" required>
      <input type="text" id="visitor_last_name" name="visitor_last_name" placeholder="Visitor Last Name*" required>
    </div>
    <div class="form-row">
      <select id="visitor_sex" name="visitor_sex" required>
        <option value="" disabled selected>Sex*</option>
        <option>Male</option>
        <option>Female</option>
      </select>
      <div class="form-group">
        <input type="date" id="birthdate" name="visitor_birthdate" placeholder=" " required>
        <label for="birthdate">Birthdate*</label>
      </div>
    </div>
    <div class="input-wrap">
      <input type="tel" id="visitor_contact" name="visitor_contact" placeholder="Visitor Phone Number*" required>
      <span style="display:block; font-size:0.75rem; color:#666; margin-top:4px;">Format: 09XX XXX XXXX (11 digits)</span>
    </div>
    <div class="form-group">
      <input type="email" id="visitor_email" name="visitor_email" placeholder="Visitor Email*" required>
    </div>
    <div class="input-wrap">
      <input type="text" id="visitor_address" name="visitor_address" placeholder="Guest Address (e.g., Blk 00 Lot 00)*" required>
    </div>
    <h4 style="margin:20px 0 5px;color:#111827;">Visit Schedule</h4>
    <div class="form-row">
      <input type="date" id="visit_date" name="visit_date" required>
      <input type="time" id="visit_time" name="visit_time" required>
    </div>

    <label class="upload-box">
      <input type="file" id="visitor_valid_id" name="visitor_valid_id" accept="image/*" hidden required>
      <img src="images/mainpage/upload.svg" alt="Upload">
      <p>Upload Guest’s Valid ID*<br><small>(e.g. National ID, Driver’s License)</small><br><small>Max 5MB</small></p>
    </label>
    <div class="privacy-note" style="background:#f9fafb;border:1px solid #e5e7eb;color:#374151;padding:10px 12px;border-radius:8px;margin:10px 0;font-size:0.92rem;line-height:1.35;">
      Data Privacy Notice: The visitor’s ID is used only for verification and stored securely. Access is limited to authorized staff, following the Data Privacy Act of 2012.
    </div>

    <div id="idPreviewWrap" style="display:none;margin:8px 0 14px;">
      <img id="idPreview" alt="Valid ID Preview" style="max-width:240px;border-radius:10px;border:1px solid #e6ebe6;display:block;">
      <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" id="btnClearId" class="btn-next" style="background:#e6ebe6;color:#23412e;">Remove Selected ID</button>
      </div>
    </div>

    <div class="form-actions">
      <a href="profileresident.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back</a>
      <button type="submit" class="btn-next" id="submitBtn">Save Guest</button>
    </div>
  </form>

  <div id="guestListSection" style="margin-top:28px;background:#ffffff;border-radius:16px;padding:20px 22px;box-shadow:0 4px 16px rgba(15,23,42,0.08);border:1px solid #e5e7eb;max-width:860px;width:100%;">
    <h4 style="margin:0 0 10px;color:#111827;">My Saved Guests</h4>
    <?php if (empty($guestRows)): ?>
      <p style="margin:4px 0 0;font-size:0.95rem;color:#555;">You have not added any guests yet. Use the form above to add a guest.</p>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
          <thead>
            <tr style="background:#f5f7f5;color:#333;">
              <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e2e6e2;">Name</th>
              <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e2e6e2;">Contact</th>
              <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e2e6e2;">Email</th>
              <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e2e6e2;">Added</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($guestRows as $g): ?>
              <?php
                $nameParts = [];
                if (!empty($g['visitor_first_name'])) { $nameParts[] = $g['visitor_first_name']; }
                if (!empty($g['visitor_middle_name'])) { $nameParts[] = $g['visitor_middle_name']; }
                if (!empty($g['visitor_last_name'])) { $nameParts[] = $g['visitor_last_name']; }
                $guestName = trim(implode(' ', $nameParts));
                if ($guestName === '') { $guestName = 'Guest'; }
                $contact = $g['visitor_contact'] ?? '';
                $emailG = $g['visitor_email'] ?? '';
                $created = $g['created_at'] ?? '';
                $createdLabel = $created ? date('m/d/y', strtotime($created)) : '';
              ?>
              <tr>
                <td style="padding:7px 10px;border-bottom:1px solid #e9ece9;font-weight:600;"><?php echo htmlspecialchars($guestName); ?></td>
                <td style="padding:7px 10px;border-bottom:1px solid #e9ece9;"><?php echo htmlspecialchars($contact); ?></td>
                <td style="padding:7px 10px;border-bottom:1px solid #e9ece9;"><?php echo htmlspecialchars($emailG); ?></td>
                <td style="padding:7px 10px;border-bottom:1px solid #e9ece9;color:#777;"><?php echo htmlspecialchars($createdLabel); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- Modal -->
<div id="refModal" class="modal">
  <div class="modal-content">
    <h2>Request Submitted!</h2>
    <p>Your guest has been successfully saved to your account.</p>
    <p><small><em>You can view and manage all guests from your resident dashboard.</em></small></p>
    <div style="display:flex;gap:10px;justify-content:center;margin-top:10px;flex-wrap:wrap;">
      <button type="button" class="close-btn" onclick="window.location.href='profileresident.php'">Go to Resident Profile</button>
    </div>
  </div>
</div>
<div id="verifyModal" class="modal">
  <div class="modal-content">
    <h2>Confirm Guest Request</h2>
    <div id="verifySummary" style="text-align:left;margin-top:10px"></div>
    <div style="text-align:center;margin-top:12px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
      <button type="button" class="btn-cancel" id="verifyCancelBtn">Cancel</button>
      <button type="button" class="btn-confirm" id="verifyConfirmBtn">Confirm</button>
    </div>
  </div>
</div>
<script>
const submitBtn   = document.getElementById('submitBtn');
const entryForm   = document.getElementById('entryForm');
const birthdateEl = document.getElementById('birthdate');
const idInput = document.getElementById('visitor_valid_id');
const idPreviewWrap = document.getElementById('idPreviewWrap');
const idPreview = document.getElementById('idPreview');
const btnClearId = document.getElementById('btnClearId');
const verifyModal = document.getElementById('verifyModal');
const verifySummary = document.getElementById('verifySummary');
const verifyCancelBtn = document.getElementById('verifyCancelBtn');
const verifyConfirmBtn = document.getElementById('verifyConfirmBtn');
let submitting = false;

document.addEventListener('DOMContentLoaded',function(){
  var panel=document.querySelector('.booking-steps');
  var toggle=document.getElementById('bookingStepsToggle');
  if(panel&&toggle){
    toggle.addEventListener('click',function(){
      var collapsed=panel.classList.toggle('is-collapsed');
      toggle.textContent=collapsed?'+':'−';
      toggle.setAttribute('aria-expanded',collapsed?'false':'true');
    });
  }
});

function openModal(){
  var m = document.getElementById('refModal');
  m.style.display = 'flex';
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
function blockInvalidNameChars(e){ 
  if (['Backspace', 'Tab', 'ArrowLeft', 'ArrowRight', 'Delete', 'Enter'].includes(e.key)) return;
  if (!/^[a-zA-Z\s\-]$/.test(e.key)){ 
    e.preventDefault(); 
    setWarning(e.target.id, 'Only letters, spaces, and hyphens are allowed.'); 
  } else {
    setWarning(e.target.id, '');
  }
}
function sanitizeNameInput(e){ 
  const val=e.target.value; 
  const cleaned=val.replace(/[^a-zA-Z\s\-]/g,''); 
  if(val!==cleaned){ 
    e.target.value=cleaned; 
    setWarning(e.target.id,'Only letters, spaces, and hyphens are allowed.'); 
  } else { 
    setWarning(e.target.id,''); 
  } 
}
function isValidPhone(el){ 
  let val=el.value.replace(/[\s\-]/g, '');
  if(/^(\+63|63)(9\d{9})$/.test(val)) val = '0' + val.replace(/^(\+63|63)/, '');
  else if(/^9\d{9}$/.test(val)) val = '0' + val;
  return /^09\d{9}$/.test(val);
}
function getEmailError(el) {
  const val=el.value.trim(); 
  if(!/^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/.test(val)) return 'Please enter a valid email.';
  return '';
}
['resident_full_name','visitor_first_name','visitor_last_name'].forEach(function(id){ const el=document.getElementById(id); if(!el) return; el.addEventListener('keydown',blockInvalidNameChars); el.addEventListener('input',sanitizeNameInput); });

['resident_email','visitor_email'].forEach(function(id){ const el=document.getElementById(id); if(!el) return; el.addEventListener('input', function(e){ setWarning(id, el.value.trim() ? getEmailError(el) : ''); }); });
['resident_contact','visitor_contact'].forEach(function(id){ 
  const el=document.getElementById(id); 
  if(!el) return; 
  el.setAttribute('maxlength', '15');
  
  el.addEventListener('input', function(e){ 
    // Allow digits, plus, spaces
    let val = el.value.replace(/[^0-9+\s]/g, '');
    if (el.value !== val) el.value = val;
    
    // Basic format guidance
    if(!el.value.trim()){ setWarning(id,''); return; } 
    setWarning(id,'');
  }); 

  el.addEventListener('blur', function(e){
    let val = e.target.value.trim();
    if (!val) return;
    
    // Normalize logic to 09XXXXXXXXX
    let clean = val.replace(/\D/g, '');
    let normalized = '';
    
    if (clean.length === 11 && clean.startsWith('09')) {
       normalized = clean;
    } else if (clean.length === 12 && clean.startsWith('639')) {
       normalized = '0' + clean.substring(2);
    } else if (clean.length === 10 && clean.startsWith('9')) {
       normalized = '0' + clean;
    } else {
       if (!isValidPhone(el)) {
          setWarning(id, 'Format must be 11 digits starting with 09 (e.g. 09XX XXX XXXX)');
       }
       return;
    }
    
    if (normalized) {
       // Display as 09XX XXX XXXX
       const part1 = normalized.substring(0, 4);
       const part2 = normalized.substring(4, 7);
       const part3 = normalized.substring(7);
       e.target.value = `${part1} ${part2} ${part3}`;
       setWarning(id, '');
    }
  });
});

// Birthdate must be not in the future
if (birthdateEl) {
  var d=new Date();
  birthdateEl.setAttribute('max', d.toISOString().split('T')[0]);
}

function openTerms(){ document.getElementById('termsModal').style.display='flex'; }
function closeTerms(){ document.getElementById('termsModal').style.display='none'; }
function openPrivacy(){ document.getElementById('privacyModal').style.display='flex'; }
function closePrivacy(){ document.getElementById('privacyModal').style.display='none'; }

function validateForm(){
  let valid = true;
  const reqIds = ['resident_full_name','resident_house','resident_email','resident_contact','visitor_first_name','visitor_last_name','visitor_email','visitor_address','birthdate','visitor_contact','visit_date','visit_time'];
  reqIds.forEach(function(id){
    const el = document.getElementById(id);
    if(!el) return;
    if(!String(el.value||'').trim()){
      setWarning(id,'This field is required.');
      valid = false;
    } else {
      setWarning(id,'');
    }
  });
  const sexEl = document.getElementById('visitor_sex');
  if (sexEl){
    if(!sexEl.value){
      setWarning('visitor_sex','Please select Sex.');
      valid = false;
    } else {
      setWarning('visitor_sex','');
    }
  }
  if (birthdateEl && birthdateEl.value){
    var todayStr = new Date().toISOString().split('T')[0];
    if (birthdateEl.value > todayStr){
      setWarning('birthdate','Birthdate cannot be in the future.');
      valid = false;
    } else {
      setWarning('birthdate','');
    }
  }
  const rc=document.getElementById('resident_contact');
  const vc=document.getElementById('visitor_contact');
  const re=document.getElementById('resident_email');
  const ve=document.getElementById('visitor_email');
  if(re && getEmailError(re)){ setWarning('resident_email', getEmailError(re)); valid=false; }
  if(ve && getEmailError(ve)){ setWarning('visitor_email', getEmailError(ve)); valid=false; }
  if(rc && !isValidPhone(rc)){ setWarning('resident_contact','Please enter a valid phone number (e.g. 09XX XXX XXXX).'); valid=false; }
  if(vc && !isValidPhone(vc)){ setWarning('visitor_contact','Please enter a valid phone number (e.g. 09XX XXX XXXX).'); valid=false; }
  if(idInput && !(idInput.files && idInput.files[0])){ setWarning('visitor_valid_id','Please upload Visitor’s Valid ID.'); valid=false; }
  return valid;
}

function buildVerifySummary(){
  if (!verifyModal || !verifySummary) return;
  const resNameEl = document.getElementById('resident_full_name');
  const resHouseEl = document.getElementById('resident_house');
  const resContactEl = document.getElementById('resident_contact');
  const visFirstEl = document.getElementById('visitor_first_name');
  const visLastEl = document.getElementById('visitor_last_name');
  const visContactEl = document.getElementById('visitor_contact');
  const visEmailEl = document.getElementById('visitor_email');
  const visAddressEl = document.getElementById('visitor_address');
  const visitDateEl = document.getElementById('visit_date');
  const visitTimeEl = document.getElementById('visit_time');
  const vSexEl = document.getElementById('visitor_sex');
  const personsEl = document.getElementById('visit_persons');
  const resName = resNameEl ? resNameEl.value.trim() : '';
  const resHouse = resHouseEl ? resHouseEl.value.trim() : '';
  const resContact = resContactEl ? resContactEl.value.trim() : '';
  const visFirst = visFirstEl ? visFirstEl.value.trim() : '';
  const visLast = visLastEl ? visLastEl.value.trim() : '';
  const visContact = visContactEl ? visContactEl.value.trim() : '';
  const visEmail = visEmailEl ? visEmailEl.value.trim() : '';
  const visAddress = visAddressEl ? visAddressEl.value.trim() : '';
  const visitDate = visitDateEl ? visitDateEl.value.trim() : '';
  const visitTime = visitTimeEl ? visitTimeEl.value.trim() : '';
  const vSex = vSexEl ? vSexEl.value : '';
  const vBirth = birthdateEl ? birthdateEl.value : '';
  const personsVal = personsEl && personsEl.value ? personsEl.value : '1';
  const items = [
    ['Resident', resName || '-'],
    ['House/Unit', resHouse || '-'],
    ['Resident Contact', resContact || '-'],
    ['Visitor', (visFirst + ' ' + visLast).trim() || '-'],
    ['Visitor Sex', vSex || '-'],
    ['Visitor Birthdate', vBirth || '-'],
    ['Visitor Contact', visContact || '-'],
    ['Visitor Email', visEmail || '-'],
    ['Visitor Address', visAddress || '-'],
    ['Visit Date', visitDate || '-'],
    ['Visit Time', visitTime || '-']
  ];
  verifySummary.innerHTML = items.map(function(x){
    return '<div style="display:flex;justify-content:space-between;margin:4px 0"><span style="font-weight:600">'+x[0]+'</span><span>'+x[1]+'</span></div>';
  }).join('');
  verifyModal.style.display = 'flex';
}

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

if (verifyCancelBtn && verifyModal){
  verifyCancelBtn.addEventListener('click', function(){
    if (submitting) return;
    verifyModal.style.display = 'none';
  });
}

async function performSubmit(){
  if (submitting) return;
  submitting = true;
  if (verifyConfirmBtn){
    verifyConfirmBtn.disabled = true;
  }
  try {
    const fd = new FormData(entryForm);
    const res = await fetch('submit_guest.php', { method: 'POST', body: fd });
    const text = await res.text();
    let data = null;
    try { data = JSON.parse(text); } catch (e) { data = null; }
    if (!res.ok || !data) {
      setWarning('visitor_email', 'Server error. Please try again.');
      return;
    }
    if (data && data.success) {
      openModal();
    } else {
      let msg = data && data.message ? data.message : 'Failed to save guest.';
      if(msg.includes('Resident phone')) setWarning('resident_contact', msg);
      else if(msg.includes('Visitor phone')) setWarning('visitor_contact', msg);
      else if(msg.includes('Resident name')) setWarning('resident_full_name', msg);
      else if(msg.includes('Visitor name')) setWarning('visitor_first_name', msg);
      else if(msg.includes('valid ID')) setWarning('visitor_valid_id', msg);
      else if(msg.includes('Resident email')) setWarning('resident_email', msg);
      else if(msg.includes('Visitor email')) setWarning('visitor_email', msg);
      else setWarning('visitor_email', msg);
    }
  } catch (err) {
    setWarning('visitor_email', 'Error connecting to server.');
  } finally {
    submitting = false;
    if (verifyConfirmBtn){
      verifyConfirmBtn.disabled = false;
    }
  }
}

if (verifyConfirmBtn){
  verifyConfirmBtn.addEventListener('click', function(){
    if (submitting) return;
    if (!validateForm()) return;
    if (verifyModal){
      verifyModal.style.display = 'none';
    }
    performSubmit();
  });
}

entryForm.addEventListener('submit', function(e){
  e.preventDefault();
  if (submitting) return;
  if (!validateForm()) return;
  buildVerifySummary();
});
</script>
</body>
</html>
