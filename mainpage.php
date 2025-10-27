<?php
include("connect.php");
session_start();

// Ensure entry_passes table exists
function ensureEntryPassesTable($con) {
  $con->query("CREATE TABLE IF NOT EXISTS entry_passes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    sex VARCHAR(10) NULL,
    birthdate DATE NULL,
    contact VARCHAR(50) NULL,
    email VARCHAR(120) NOT NULL,
    address VARCHAR(255) NOT NULL,
    valid_id_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

ensureEntryPassesTable($con);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  // Collect form data
  $first = trim($_POST['first_name'] ?? '');
  $middle = trim($_POST['middle_name'] ?? '');
  $last = trim($_POST['last_name'] ?? '');
  $address = trim($_POST['address'] ?? '');
  // Email removed from form; store as empty string
  $email = '';
  $sex = $_POST['sex'] ?? '';
  $birthdate = $_POST['birthdate'] ?? '';
  $contact = trim($_POST['contact'] ?? '');

  // Handle valid ID upload
  $validIdPath = null;
  if (!empty($_FILES['valid_id']['name'])) {
    $uploadDir = "uploads/";
    if (!is_dir($uploadDir)) mkdir($uploadDir);
    $fileName = time() . "_" . basename($_FILES["valid_id"]["name"]);
    $targetFile = $uploadDir . $fileName;
    if (move_uploaded_file($_FILES["valid_id"]["tmp_name"], $targetFile)) {
      $validIdPath = $targetFile;
    }
  }

  // Insert into entry_passes
  $stmt = $con->prepare("INSERT INTO entry_passes (full_name, middle_name, last_name, sex, birthdate, contact, email, address, valid_id_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
  $stmt->bind_param("sssssssss", $first, $middle, $last, $sex, $birthdate, $contact, $email, $address, $validIdPath);
  if ($stmt->execute()) {
    $entryPassId = $stmt->insert_id;

    // Store minimal info in session (optional)
    $_SESSION['entry_pass_id'] = $entryPassId;
    $_SESSION['entry_pass_name'] = $first . ' ' . $last;

    // Redirect to reservation page carrying entry_pass_id
    header("Location: reserve.php?entry_pass_id=" . $entryPassId);
    exit;
  } else {
    // Fallback: keep previous behavior
    header("Location: reserve.php");
    exit;
  }
}
?>

<!DOCTYPE html> 
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VictorianPass</title>
  <link rel="icon" type="image/png" href="mainpage/logo.svg">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="mainpage.css">

  <style>
    /* Global Poppins Font Application */
    * {
      font-family: 'Poppins', sans-serif !important;
    }
    
    body {
      animation: fadeIn 0.6s ease-in-out;
      font-family: 'Poppins', sans-serif;
      font-weight: 400;
    }
    
    h1, h2, h3, h4, h5, h6 {
      font-family: 'Poppins', sans-serif;
      font-weight: 600;
    }
    
    p, span, div, a, button, input, select, textarea, label {
      font-family: 'Poppins', sans-serif;
    }
    
    .brand-text h1 {
      font-family: 'Poppins', sans-serif;
      font-weight: 700;
    }
    
    .brand-text p {
      font-family: 'Poppins', sans-serif;
      font-weight: 400;
    }
    
    .hero-content h1 {
      font-family: 'Poppins', sans-serif;
      font-weight: 900;
    }
    
    .tagline {
      font-family: 'Poppins', sans-serif;
      font-weight: 300;
    }
    
    .btn-qr, .btn-nav {
      font-family: 'Poppins', sans-serif;
      font-weight: 600;
    }
    
    .entry-form input, .entry-form select, .entry-form textarea {
      font-family: 'Poppins', sans-serif;
      font-weight: 400;
    }
    
    .form-header span {
      font-family: 'Poppins', sans-serif;
      font-weight: 600;
    }
    
    .dropdown-btn {
      font-family: 'Poppins', sans-serif;
      font-weight: 500;
    }
    
    .dropdown-content a {
      font-family: 'Poppins', sans-serif;
      font-weight: 400;
    }
    
    .page-instructions, .form-note {
      font-family: 'Poppins', sans-serif;
      font-weight: 400;
    }
    
    .page-instructions strong {
      font-weight: 600;
    }
    
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }               
    .action-buttons {
      display: flex;
      gap: 15px;
      margin-top: 20px;
      flex-wrap: wrap;
    }
    .btn-qr {
      display: flex;
      gap: 8px;
      padding: 12px 22px;
      border-radius: 10px;
      font-weight: 600;
      text-decoration: none;
      color: #222;
      background: #e5ddc6;
      transition: 0.2s;
    }
    .btn-qr img { width: 18px; height: 18px; }
    .btn-qr:hover { transform: translateY(-2px); opacity: 0.9; }
    .btn-referral { background: #4CAF50; color: #fff; }
    .form-note, .page-instructions {
      margin-top: 15px;
      font-size: 0.9rem;
      color: #ddd;
      text-align: center;
      max-width: 520px;
      line-height: 1.5;
    }
    .page-instructions strong { color: #fff; }

    .entry-form select { 
      width: 95%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; 
      font-size: 0.9rem; font-family: 'Poppins', sans-serif; 
      background: #fff url("mainpage/arrow.svg") no-repeat right 12px center; 
      background-size: 14px; appearance: none; color: #333; cursor: pointer; 
      margin-bottom: 14px;
    } 
    .entry-form select:focus { border-color: #4CAF50; }

    .form-group { position: relative; flex: 1; }
    .form-group input[type="date"] {
      width: 100%; padding: 12px; border: 1px solid #ccc;
      border-radius: 8px; font-size: 0.95rem;
      font-family: 'Poppins', sans-serif; background: #fff; color: #222;
    }
    input[type="date"]:not(:focus):placeholder-shown::-webkit-datetime-edit {
      color: transparent;
    }
    input[type="date"]::-webkit-calendar-picker-indicator {
      position: absolute; right: 12px; cursor: pointer;
    }
    .form-group label {
      position: absolute; left: 12px; top: 12px; color: #888;
      font-size: 0.95rem; pointer-events: none; transition: 0.2s ease all;
    }
    .form-group input:focus + label,
    .form-group input:not(:placeholder-shown) + label {
      top: -8px; left: 8px; font-size: 0.75rem; color: #23412e;
      background: #fff; padding: 0 4px;
    }

    /* User Type Dropdown Styles */
    .user-type-dropdown {
      position: relative;
      display: inline-block;
    }

    .dropdown-btn {
      background: #23412e;
      color: #fff;
      padding: 8px 16px;
      border: none;
      border-radius: 20px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.9rem;
      font-weight: 500;
      transition: all 0.2s ease;
      width: 100%;
      justify-content: space-between;
    }

    .dropdown-btn span {
      pointer-events: none;
    }

    .dropdown-btn:hover {
      background: #1a2f21;
      transform: scale(1.05);
    }

    .dropdown-arrow {
      font-size: 12px;
      transition: transform 0.2s ease;
      user-select: none;
      pointer-events: none;
    }

    .dropdown-content {
      display: none;
      position: absolute;
      right: 0;
      background: #fff;
      min-width: 160px;
      box-shadow: 0px 8px 16px rgba(0,0,0,0.2);
      border-radius: 8px;
      z-index: 1000;
      overflow: hidden;
      margin-top: 5px;
    }

    .dropdown-content a {
      color: #222;
      padding: 12px 16px;
      text-decoration: none;
      display: block;
      transition: background-color 0.2s ease;
    }

    .dropdown-content a:hover {
      background-color: #f1f1f1;
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 12px;
    }
  </style>
</head>

<body>
  <!-- HEADER -->
  <header class="navbar">
    <div class="logo">
      <a href="mainpage.php"><img src="mainpage/logo.svg" alt="VictorianPass Logo"></a>
      <div class="brand-text">
        <h1>VictorianPass</h1>
        <p>Victorian Heights Subdivision</p>
      </div>
    </div>

    <div class="nav-actions">
      <!-- User Type Dropdown -->
      <div class="user-type-dropdown" id="userTypeDropdown">
        <button class="dropdown-btn" id="dropdownBtn">
          <span>Select User Type</span>
          <span class="dropdown-arrow">▼</span>
        </button>
        <div class="dropdown-content" id="dropdownContent">
          <a href="#" onclick="selectUserType('resident')">Resident</a>
          <a href="#" onclick="selectUserType('visitor')">Visitor</a>
        </div>
      </div>

      <!-- Navigation Links (initially hidden) -->
      <div class="nav-links" id="navLinks" style="display: none;">
        <a href="login.php" class="btn-nav btn-login">Login</a>
        <a href="signup.php" class="btn-nav btn-register">Register</a>
        <a href="profileresident.html" id="profileIcon" style="display: none;">
          <img src="mainpage/profile'.jpg" alt="Profile" class="profile-icon">
        </a>
      </div>
    </div>
  </header>

  <!-- HERO SECTION -->
  <section class="hero">
    <div class="hero-content">
      <div class="hero-icons">
        <a href="mainpage.php" class="icon-box">
          <img src="mainpage/entrypass.svg" alt="Entry Pass">
          <span>Entrypass</span>
        </a>
      </div>

      <h1>WELCOME</h1>
      <p class="tagline">
        "Every home holds a story<br>
        start yours in a place<br>
        worth remembering."
      </p>

      <div class="action-buttons" id="checkStatusSection" style="display: none;">
        <a href="checkurstatus.php" class="btn-qr">
          <span>Check Status</span>
          <img src="mainpage/arrow.svg" alt="QR Icon">
        </a>
      </div>

      <p class="page-instructions" id="checkStatusInstructions" style="display: none;">
        <strong>Instructions:</strong>  
        Use <b>Check Status</b> if you already have a <b>Status Code</b>.
      </p>
    </div>

    <!-- ✅ ENTRY FORM with working PHP (initially hidden for visitors) -->
    <form class="entry-form" id="entryForm" method="POST" enctype="multipart/form-data" style="display: none;">
      <div class="form-header">
        <img src="mainpage/ticket.svg" alt="Ticket Icon">
        <span>Entry Pass</span>
      </div>

      <input type="text" name="first_name" placeholder="First Name*" required>
      <input type="text" name="middle_name" placeholder="Middle Name">
      <input type="text" name="last_name" placeholder="Last Name*" required>
      <input type="text" name="address" placeholder="Address*" required>

      <div class="form-row">
        <select name="sex" required>
          <option value="" disabled selected>Sex*</option>
          <option value="Male">Male</option>
          <option value="Female">Female</option>
        </select>

        <div class="form-group">
          <div class="form-row">
            <input type="date" id="birthdate" name="birthdate" placeholder=" " required>
            <label for="birthdate">Birthdate*</label>
          </div>
        </div>
      </div>

      <div class="form-row">
        <input type="tel" name="contact" placeholder="Phone Number*" required>
      </div>

      <label class="upload-box">
        <input type="file" name="valid_id" hidden required>
        <img src="mainpage/upload.svg" alt="Upload">
        <p>Upload a Valid ID* <br><small>(e.g. National ID, Driver's License)</small></p>
      </label>

      <button type="submit" class="btn-next">Next</button>
    </form>
  </section>

  <script>
    function selectUserType(type) {
      const dropdown = document.getElementById('userTypeDropdown');
      const navLinks = document.getElementById('navLinks');
      const entryForm = document.getElementById('entryForm');
      const profileIcon = document.getElementById('profileIcon');
      const checkStatusSection = document.getElementById('checkStatusSection');
      const checkStatusInstructions = document.getElementById('checkStatusInstructions');
      
      if (type === 'resident') {
        // Hide dropdown and show navigation links
        dropdown.style.display = 'none';
        navLinks.style.display = 'flex';
        entryForm.style.display = 'none';
        checkStatusSection.style.display = 'none';
        checkStatusInstructions.style.display = 'none';
        
        // Check if user is logged in (you can modify this logic based on your session handling)
        <?php if (isset($_SESSION['user_id'])): ?>
          profileIcon.style.display = 'block';
        <?php endif; ?>
        
      } else if (type === 'visitor') {
        // Hide dropdown and show entry form and check status
        dropdown.style.display = 'none';
        navLinks.style.display = 'none';
        entryForm.style.display = 'block';
        profileIcon.style.display = 'none';
        checkStatusSection.style.display = 'block';
        checkStatusInstructions.style.display = 'block';
      }
    }

    // Toggle dropdown visibility
    document.getElementById('dropdownBtn').addEventListener('click', function(event) {
      event.stopPropagation();
      const content = document.getElementById('dropdownContent');
      content.style.display = content.style.display === 'block' ? 'none' : 'block';
    });

    // Close dropdown when clicking outside
    window.addEventListener('click', function(event) {
      if (!event.target.closest('.dropdown-btn')) {
        const dropdowns = document.getElementsByClassName('dropdown-content');
        for (let i = 0; i < dropdowns.length; i++) {
          dropdowns[i].style.display = 'none';
        }
      }
    });
  </script>

</body>
</html>
