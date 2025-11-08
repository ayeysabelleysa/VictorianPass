<?php
session_start();
require_once 'connect.php';

$ref_code = isset($_GET['ref_code']) ? trim($_GET['ref_code']) : '';
$entry_pass_id = isset($_GET['entry_pass_id']) ? trim($_GET['entry_pass_id']) : '';
$continue = isset($_GET['continue']) ? trim($_GET['continue']) : '';
$hasRef = $ref_code !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VictorianPass - Down Payment</title>
  <link rel="icon" type="image/png" href="mainpage/logo.svg">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
  <style>
    body{font-family:'Poppins',sans-serif;margin:0;background:#f7f9f7;color:#23412e;}
    .navbar{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#ffffff;border-bottom:1px solid #e6ebe6;}
    .navbar .brand{display:flex;align-items:center;gap:10px;}
    .container{max-width:820px;margin:24px auto;padding:16px;}
    .card{background:#fff;border:1px solid #e6ebe6;border-radius:12px;padding:18px;box-shadow:0 3px 10px rgba(0,0,0,0.03);}    
    .card h2{margin:0 0 8px 0;font-size:1.4rem;}
    .muted{color:#6b7a6d;}
    .ref{font-weight:600;letter-spacing:0.5px;background:#f1f6f2;padding:8px 12px;border-radius:8px;display:inline-block;margin-top:4px;}
    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;}
    .btn{border:none;border-radius:8px;padding:10px 14px;cursor:pointer;font-weight:600;}
    .btn.primary{background:#23412e;color:#fff;}
    .btn.secondary{background:#e6ebe6;color:#23412e;}
    .upload{display:flex;flex-direction:column;gap:8px;margin-top:12px;}
    .status{margin-top:10px;font-size:0.95rem;}
    .success{color:#1e7e34;}
    .error{color:#c0392b;}
    .divider{height:1px;background:#e6ebe6;margin:16px 0;}
    .note{font-size:0.92rem;color:#6b7a6d;}
  </style>
</head>
<body>
  <header class="navbar">
    <div class="brand">
      <a href="mainpage.php"><img src="mainpage/logo.svg" alt="VictorianPass" style="height:32px;"></a>
      <strong>VictorianPass</strong>
    </div>
    <a href="profileresident.php" class="muted">Back to Profile</a>
  </header>

  <div class="container">
    <div class="card">
      <h2>Online Down Payment</h2>
      <p class="muted">Complete your down payment online to speed up processing. You can upload a payment receipt now or choose to proceed and upload later.</p>
      <?php if ($hasRef) { ?>
        <div>
          <div class="muted">Reference Code</div>
          <div class="ref" id="refCodeShow"><?php echo htmlspecialchars($ref_code); ?></div>
        </div>
      <?php } else { ?>
        <div class="note">Please upload your downpayment receipt to proceed to the reservation page.</div>
      <?php } ?>

      <div class="upload">
        <label for="receipt">Upload payment receipt (JPEG/PNG/GIF, max 5MB)</label>
        <input type="file" id="receipt" accept="image/*">
        <div class="actions">
          <button class="btn primary" id="btnUpload">Upload & Submit</button>
        </div>
        <div id="uploadStatus" class="status"></div>
      </div>

      <div class="divider"></div>
      <p class="note">Once uploaded, your payment status will appear as <b>Pending</b> until verified by admin. You can track verification in your profile under Entries & Requests.</p>
      <?php if ($continue === 'reserve' && $entry_pass_id !== '') { ?>
        <p class="note">After upload, continue to the Amenity Reservation page to select dates and persons for your guest.</p>
      <?php } ?>
    </div>
  </div>

  <script>
    const btnUpload = document.getElementById('btnUpload');
    const receipt   = document.getElementById('receipt');
    const statusEl  = document.getElementById('uploadStatus');
    const refCode   = '<?php echo htmlspecialchars($ref_code); ?>';
    const continueMode = '<?php echo htmlspecialchars($continue); ?>';
    const entryPassId  = '<?php echo htmlspecialchars($entry_pass_id); ?>';

    if (document.getElementById('btnUpload')) btnUpload.addEventListener('click', async function(){
      statusEl.textContent = '';
      statusEl.className = 'status';
      const file = receipt.files && receipt.files[0];
      if(!file){
        statusEl.textContent = 'Please choose a receipt image to upload.';
        statusEl.classList.add('error');
        return;
      }
      const fd = new FormData();
      fd.append('receipt', file);
      let endpoint = '';
      if (refCode) {
        fd.append('ref_code', refCode);
        endpoint = 'upload_receipt.php';
      } else {
        fd.append('entry_pass_id', entryPassId);
        endpoint = 'upload_downpayment_entry.php';
      }
      try{
        const res = await fetch(endpoint, { method: 'POST', body: fd });
        const data = await res.json();
        if(data && data.success){
          statusEl.textContent = 'Receipt uploaded successfully. Payment status is pending verification.';
          statusEl.classList.add('success');
          setTimeout(function(){
            if(continueMode === 'reserve' && entryPassId){
              window.location.href = 'reserve.php?entry_pass_id=' + encodeURIComponent(entryPassId);
            }
          }, 1000);
        } else {
          statusEl.textContent = data && data.message ? data.message : 'Upload failed.';
          statusEl.classList.add('error');
        }
      }catch(err){
        statusEl.textContent = 'Network error. Please try again.';
        statusEl.classList.add('error');
      }
    });
  </script>
</body>
</html>