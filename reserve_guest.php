<?php
session_start();
include 'connect.php';
$generatedCode = '';
$errorMsg = '';

// Require a valid guest form reference code
$ref_code = isset($_GET['ref_code']) ? $_GET['ref_code'] : '';
if ($ref_code === '') {
  header('Location: guestform.php');
  exit;
}

$gfRow = null;
$residentOwnerId = null;
$stmtGF = $con->prepare("SELECT id, resident_user_id FROM guest_forms WHERE ref_code = ? LIMIT 1");
if ($stmtGF) {
  $stmtGF->bind_param('s', $ref_code);
  if ($stmtGF->execute()) {
    $resGF = $stmtGF->get_result();
    $gfRow = $resGF ? $resGF->fetch_assoc() : null;
    if ($gfRow) { $residentOwnerId = $gfRow['resident_user_id'] ?? null; }
  }
  $stmtGF->close();
}
if (!$gfRow) {
  header('Location: guestform.php');
  exit;
}

// Ensure reservations columns are nullable, supporting placeholder record before amenity selection
function ensureReservationsNullable($con) {
  @$con->query("ALTER TABLE reservations MODIFY amenity VARCHAR(100) NULL");
  @$con->query("ALTER TABLE reservations MODIFY start_date DATE NULL");
  @$con->query("ALTER TABLE reservations MODIFY end_date DATE NULL");
  @$con->query("ALTER TABLE reservations MODIFY persons INT NULL");
  @$con->query("ALTER TABLE reservations MODIFY price DECIMAL(10,2) NULL");
}
ensureReservationsNullable($con);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $amenity = $_POST['amenity'] ?? 'Pool';
  $start   = $_POST['startDate'] ?? '';
  $end     = $_POST['endDate'] ?? '';
  $persons = intval($_POST['persons'] ?? 1);
  $price = $persons * 1; // Example: $1 per person
  $user_id = $residentOwnerId ? intval($residentOwnerId) : NULL;

  // Validate inputs
  if (!$start || !$end) {
    $errorMsg = 'Please select a start and end date.';
  } else {
    // Overlap across reservations (visitors), resident_reservations (residents), and guest_forms (guest amenities)
    $cnt = 0;
    $check1 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND start_date <= ? AND end_date >= ?");
    $check1->bind_param("sss", $amenity, $end, $start);
    $check1->execute(); $r1 = $check1->get_result(); $cnt += ($r1 && ($rw=$r1->fetch_assoc())) ? intval($rw['c']) : 0; $check1->close();
    $check2 = $con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND start_date <= ? AND end_date >= ?");
    $check2->bind_param("sss", $amenity, $end, $start);
    $check2->execute(); $r2 = $check2->get_result(); $cnt += ($r2 && ($rw=$r2->fetch_assoc())) ? intval($rw['c']) : 0; $check2->close();
    $check3 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND start_date <= ? AND end_date >= ? AND (approval_status IN ('pending','approved'))");
    $check3->bind_param("sss", $amenity, $end, $start);
    $check3->execute(); $r3 = $check3->get_result(); $cnt += ($r3 && ($rw=$r3->fetch_assoc())) ? intval($rw['c']) : 0; $check3->close();
    if ($cnt > 0) {
      $errorMsg = 'Selected dates are not available. Please choose different dates.';
    } else {
      // Store amenity reservation details on guest_forms
      $stmtUp = $con->prepare("UPDATE guest_forms SET wants_amenity = 1, amenity = ?, start_date = ?, end_date = ?, persons = ?, price = ?, updated_at = NOW() WHERE ref_code = ?");
      $stmtUp->bind_param("sssids", $amenity, $start, $end, $persons, $price, $ref_code);
      $stmtUp->execute();
      $generatedCode = $ref_code;
    }
  }
}

// Lightweight endpoint to return booked dates for the selected amenity
if (isset($_GET['action']) && $_GET['action'] === 'booked_dates') {
  header('Content-Type: application/json');
  $amenity = $_GET['amenity'] ?? 'Pool';
  $dates = [];
  $collect = function($res) use (&$dates) {
    while ($row = $res->fetch_assoc()) {
      if (empty($row['start_date']) || empty($row['end_date'])) continue;
      $start = new DateTime($row['start_date']);
      $end = new DateTime($row['end_date']);
      $period = new DatePeriod($start, new DateInterval('P1D'), (clone $end)->modify('+1 day'));
      foreach ($period as $d) { $dates[] = $d->format('Y-m-d'); }
    }
  };
  $stmt1 = $con->prepare("SELECT start_date, end_date FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved'))");
  $stmt1->bind_param("s", $amenity); $stmt1->execute(); $collect($stmt1->get_result()); $stmt1->close();
  $stmt2 = $con->prepare("SELECT start_date, end_date FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved')");
  $stmt2->bind_param("s", $amenity); $stmt2->execute(); $collect($stmt2->get_result()); $stmt2->close();
  $stmt3 = $con->prepare("SELECT start_date, end_date FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved')");
  $stmt3->bind_param("s", $amenity); $stmt3->execute(); $collect($stmt3->get_result()); $stmt3->close();
  echo json_encode(['dates' => array_values(array_unique($dates))]);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VictorianPass - Reserve (Guest)</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="mainpage/logo.svg">

  <style>
    /* ✅ Keep original design */
    body{margin:0;font-family:'Poppins',sans-serif;background:#111;color:#fff;animation:fadeIn .6s ease-in-out;}
    @keyframes fadeIn{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);} }
    .navbar{display:flex;justify-content:space-between;align-items:center;padding:14px 6%;background:#2b2623;position:sticky;top:0;z-index:1000;}
    .logo{display:flex;align-items:center;gap:12px;}
    .logo img{width:42px;}
    .brand-text h1{margin:0;font-size:1.3rem;font-weight:600;color:#f4f4f4;}
    .brand-text p{margin:0;font-size:.85rem;color:#aaa;}
    .nav-actions{display:flex;align-items:center;gap:14px;}
    .btn-nav{padding:7px 18px;border-radius:20px;font-size:.9rem;font-weight:500;text-decoration:none;display:inline-block;transition:.2s;}
    .btn-login{background:#23412e;color:#fff;}
    .btn-register{background:#e5ddc6;color:#222;}
    .btn-nav:hover{transform:scale(1.05);opacity:.9;}
    .profile-icon{width:38px;height:38px;border-radius:50%;object-fit:cover;cursor:pointer;}
    .hero{display:flex;justify-content:center;align-items:flex-start;padding:50px 6%;gap:50px;flex-wrap:wrap;}
    .calendar{background:#fff;color:#222;padding:20px;border-radius:16px;width:320px;text-align:center;box-shadow:0 4px 15px rgba(0,0,0,.15);flex-shrink:0;}
    .calendar-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
    .calendar-header h3{font-size:1.2rem;margin:0;}
    .calendar-header button{background:#23412e;color:#fff;border:none;padding:6px 12px;border-radius:8px;cursor:pointer;font-size:1.2rem;transition:.2s;}
    .calendar-header button:hover{background:#345c40;}
    .calendar table{width:100%;border-collapse:collapse;}
    .calendar th,.calendar td{width:14%;padding:10px;font-size:.95rem;text-align:center;}
    .calendar td{cursor:pointer;border-radius:8px;transition:background .2s;}
    .calendar td:hover:not(.disabled){background:#eee;}
    .calendar td.active{background:#23412e;color:#fff;font-weight:600;}
    .calendar td.today{border:2px solid #23412e;border-radius:8px;font-weight:600;}
    .calendar td.disabled{color:#999;cursor:not-allowed;background:#f7f7f7;}
    .hero-text{max-width:520px;}
    .hero-text h1{font-size:2.6rem;font-weight:700;margin-bottom:16px;line-height:1.2;}
    .hero-text p{font-size:1rem;line-height:1.6;margin-bottom:24px;color:#ddd;}
    .btn-main{background:#e5ddc6;color:#222;padding:14px 22px;border-radius:12px;text-decoration:none;font-weight:600;transition:.2s;display:inline-block;}
    .btn-main:hover{transform:scale(1.05);opacity:.9;}
    .amenities-tabs{display:flex;justify-content:center;gap:18px;margin:40px 0 25px;flex-wrap:wrap;}
    .amenity-btn{background:#e5ddc6;color:#222;border:none;padding:12px 26px;border-radius:12px;font-weight:600;cursor:pointer;transition:.2s;}
    .amenity-btn.active{background:#23412e;color:#fff;}
    .amenity-btn:hover{transform:translateY(-2px);}
    .amenity-display{position:relative;max-width:960px;margin:auto;border-radius:20px;overflow:hidden;box-shadow:0 4px 15px rgba(0,0,0,.3);}
    .amenity-display img{width:100%;height:440px;object-fit:cover;display:block;}
    .amenity-info{position:absolute;bottom:110px;left:24px;color:white;}
    .amenity-info h2{font-size:2.2rem;font-weight:700;text-shadow:0 2px 6px rgba(0,0,0,.5);}
    .reservation-card{display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,.95);color:#222;padding:18px;border-radius:12px;position:absolute;bottom:20px;left:20px;right:20px;box-shadow:0 3px 10px rgba(0,0,0,.2);flex-wrap:wrap;}
    .res-item{flex:1;text-align:center;}
    .res-label{display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:6px;}
    .icon img{width:18px;height:18px;}
    .reservation-card p{margin:0;font-weight:600;}
    .btn-submit{background:#23412e;color:#fff;border:none;padding:10px 22px;border-radius:10px;cursor:pointer;font-weight:600;}
    .counter{display:flex;align-items:center;gap:6px;justify-content:center;}
    .counter button{background:#23412e;color:#fff;border:none;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:1rem;font-weight:600;}
    .counter span{font-weight:600;min-width:30px;display:inline-block;text-align:center;}
    .modal{display:none;position:fixed;z-index:2000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.6);align-items:center;justify-content:center;}
    .modal-content{background:#fff;color:#222;padding:30px;border-radius:14px;width:90%;max-width:450px;text-align:center;animation:fadeIn .5s ease-in-out;}
    .modal-content h2{margin:0 0 12px;font-weight:700;color:#23412e;}
    .ref-code{font-size:1.4rem;font-weight:700;background:#f3f3f3;padding:10px 16px;border-radius:10px;display:inline-block;margin-bottom:18px;}
    .close-btn{background:#23412e;color:#fff;border:none;padding:10px 20px;border-radius:10px;cursor:pointer;font-weight:600;}
    .btn-secondary{background:#e5ddc6;color:#222;border:none;padding:10px 22px;border-radius:10px;cursor:pointer;font-weight:600;text-decoration:none;display:inline-block;}
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
</header>


<section class="hero">
  <div class="calendar">
    <div class="calendar-header">
      <button id="prevMonth">&lt;</button>
      <h3 id="monthAndYear"></h3>
      <button id="nextMonth">&gt;</button>
    </div>
    <table>
      <thead><tr><th>Mo</th><th>Tu</th><th>We</th><th>Th</th><th>Fr</th><th>Sa</th><th>Su</th></tr></thead>
      <tbody id="calendar-body"></tbody>
    </table>
  </div>

  <div class="hero-text">
    <h1>Reserve Amenity for Events</h1>
    <p>Every moment deserves a space — reserve yours in a place made for memories.</p>
    <a href="#amenities" class="btn-main">Explore Amenities →</a>
  </div>
</section>

<div id="amenities" class="amenities-tabs">
  <button class="amenity-btn active" onclick="showAmenity('pool')">Pool</button>
  <button class="amenity-btn" onclick="showAmenity('clubhouse')">Clubhouse</button>
  <button class="amenity-btn" onclick="showAmenity('basketball')">Basketball Court</button>
  <button class="amenity-btn" onclick="showAmenity('tennis')">Tennis Court</button>
</div>

<form method="POST">
  <div id="amenityDisplay" class="amenity-display">
    <img id="amenityImage" src="mainpage/pool.svg" alt="Amenity Image">
    <div class="amenity-info"><h2 id="amenityTitle">Community Pool</h2></div>

    <div class="reservation-card">
      <input type="hidden" name="amenity" id="amenityField" value="Pool">
      <div class="res-item">
        <div class="res-label"><small>Start Date</small></div>
        <p id="startDate">--</p>
        <input type="hidden" name="startDate" id="startDateInput">
      </div>
      <div class="res-item">
        <div class="res-label"><small>End Date</small></div>
        <p id="endDate">--</p>
        <input type="hidden" name="endDate" id="endDateInput">
      </div>
      <div class="res-item">
        <div class="res-label"><small>Person</small></div>
        <div class="counter">
          <button type="button" onclick="changePersons(-1)">-</button>
          <span id="personCount">1</span>
          <button type="button" onclick="changePersons(1)">+</button>
        </div>
        <small id="price">$1</small>
        <input type="hidden" name="persons" id="personsInput" value="1">
      </div>
      <button class="btn-submit" type="submit">Submit</button>
    </div>
  </div>
</form>

<div id="refModal" class="modal" style="<?php echo $generatedCode ? 'display:flex;' : ''; ?>">
  <div class="modal-content">
    <h2>Request Submitted!</h2>
    <p>Your guest request has been successfully submitted.</p>
    <p><strong>Share this status code with your visitor:</strong></p>
    <div id="refCode" class="ref-code"><?php echo htmlspecialchars($generatedCode); ?></div>
    <p><small>Note: Your visitor will need this code to check the status of their Entry Pass,
      since they don’t have their own VictorianPass account.</small></p>
    <p><small><em>You can still view and manage the request in your resident dashboard.</em></small></p>
    <div style="display:flex;gap:10px;justify-content:center;margin-top:10px;flex-wrap:wrap;">
      <button class="close-btn" onclick="closeModal()">Close</button>
    </div>
  </div>
</div>

<script>
  // Calendar logic
  const monthNames=["January","February","March","April","May","June","July","August","September","October","November","December"];
  let today=new Date(),currentMonth=today.getMonth(),currentYear=today.getFullYear();
  const monthAndYear=document.getElementById("monthAndYear"),calendarBody=document.getElementById("calendar-body");
  let selectedStart=null,selectedEnd=null;
  let bookedDates=new Set();
  let selectedAmenity='Pool';

  async function loadBookedDates(){
    try{
      const res=await fetch(`reserve_guest.php?action=booked_dates&amenity=${encodeURIComponent(selectedAmenity)}`);
      const data=await res.json();
      bookedDates=new Set(data.dates||[]);
    }catch(e){
      bookedDates=new Set();
    }
    renderCalendar(currentMonth,currentYear);
  }

  function renderCalendar(month,year){
    calendarBody.innerHTML="";
    let firstDay=(new Date(year,month)).getDay();
    let daysInMonth=32-new Date(year,month,32).getDate();
    monthAndYear.innerHTML=monthNames[month]+" "+year;
    let date=1;
    for(let i=0;i<6;i++){
      let row=document.createElement("tr");
      for(let j=1;j<=7;j++){
        if(i===0&&j<(firstDay===0?7:firstDay)){row.appendChild(document.createElement("td"));}
        else if(date>daysInMonth){break;}
        else{
          let cell=document.createElement("td");
          cell.textContent=date;
          let dateString=`${year}-${String(month+1).padStart(2,'0')}-${String(date).padStart(2,'0')}`;
          if(bookedDates.has(dateString)){
            cell.classList.add('disabled');
          }
          cell.addEventListener('click',()=>handleDateClick(cell,dateString));
          if(date===today.getDate()&&year===today.getFullYear()&&month===today.getMonth()){cell.classList.add('today');}
          row.appendChild(cell);date++;
        }
      }calendarBody.appendChild(row);
    }
  }

  function handleDateClick(cell,dateString){
    if(cell.classList.contains('disabled')){return;}
    document.querySelectorAll('.calendar td').forEach(td=>td.classList.remove('active'));
    cell.classList.add('active');
    if(!selectedStart){
      selectedStart=dateString;
      document.getElementById('startDate').textContent=selectedStart;
      document.getElementById('startDateInput').value=selectedStart;
    } else {
      selectedEnd=dateString;
      document.getElementById('endDate').textContent=selectedEnd;
      document.getElementById('endDateInput').value=selectedEnd;
    }
  }

  document.getElementById("prevMonth").onclick=()=>{currentMonth=currentMonth===0?11:currentMonth-1;currentYear=currentMonth===11?currentYear-1:currentYear;renderCalendar(currentMonth,currentYear);} ;
  document.getElementById("nextMonth").onclick=()=>{currentMonth=currentMonth===11?0:currentMonth+1;currentYear=currentMonth===0?currentYear+1:currentYear;renderCalendar(currentMonth,currentYear);} ;
  loadBookedDates();

  // Amenity switcher
  function showAmenity(type){
    const title=document.getElementById('amenityTitle');
    const img=document.getElementById('amenityImage');
    const field=document.getElementById('amenityField');
    document.querySelectorAll('.amenity-btn').forEach(btn=>btn.classList.remove('active'));
    if(type==='clubhouse'){title.textContent='Clubhouse';img.src='mainpage/clubhouse.svg';field.value='Clubhouse';document.querySelector('.amenity-btn:nth-child(2)').classList.add('active');}
    else if(type==='basketball'){title.textContent='Basketball Court';img.src='mainpage/basketball.svg';field.value='Basketball Court';document.querySelector('.amenity-btn:nth-child(3)').classList.add('active');}
    else if(type==='tennis'){title.textContent='Tennis Court';img.src='mainpage/tennis.svg';field.value='Tennis Court';document.querySelector('.amenity-btn:nth-child(4)').classList.add('active');}
    else{title.textContent='Community Pool';img.src='mainpage/pool.svg';field.value='Pool';document.querySelector('.amenity-btn:nth-child(1)').classList.add('active');}
    selectedAmenity=field.value;
    selectedStart=null;selectedEnd=null;
    document.getElementById('startDate').textContent='--';
    document.getElementById('endDate').textContent='--';
    document.getElementById('startDateInput').value='';
    document.getElementById('endDateInput').value='';
    loadBookedDates();
  }

  // Person counter
  function changePersons(val){
    let count=parseInt(document.getElementById('personCount').textContent);
    count=Math.max(1,count+val);
    document.getElementById('personCount').textContent=count;
    document.getElementById('personsInput').value=count;
    document.getElementById('price').textContent="$"+count;
  }

  // Close popup
  function closeModal(){document.getElementById("refModal").style.display="none";}
</script>
</body>
</html>
