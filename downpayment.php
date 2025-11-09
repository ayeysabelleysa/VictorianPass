<?php
session_start();
require_once 'connect.php';

// Read parameters
$ref_code = isset($_GET['ref_code']) ? trim($_GET['ref_code']) : '';
$entry_pass_id = isset($_GET['entry_pass_id']) ? trim($_GET['entry_pass_id']) : '';
$continue = isset($_GET['continue']) ? trim($_GET['continue']) : '';

// Ensure reservations supports placeholder columns (in case schema file hasn’t been applied)
function ensureReservationsNullable($con) {
  // Safely attempt to relax NOT NULL constraints (no-op if already NULL)
  @$con->query("ALTER TABLE reservations MODIFY amenity VARCHAR(100) NULL");
  @$con->query("ALTER TABLE reservations MODIFY start_date DATE NULL");
  @$con->query("ALTER TABLE reservations MODIFY end_date DATE NULL");
  @$con->query("ALTER TABLE reservations MODIFY persons INT NULL");
  @$con->query("ALTER TABLE reservations MODIFY price DECIMAL(10,2) NULL");
}
ensureReservationsNullable($con);

// Auto-create a placeholder reservation for visitor entry passes (link downpayment to reservations)
if ($entry_pass_id !== '' && $ref_code === '') {
  $epid = intval($entry_pass_id);
  // Reuse existing reservation if one exists for this entry pass
  $stmtFind = $con->prepare("SELECT ref_code FROM reservations WHERE entry_pass_id = ? ORDER BY id DESC LIMIT 1");
  if ($stmtFind) {
    $stmtFind->bind_param('i', $epid);
    if ($stmtFind->execute()) {
      $res = $stmtFind->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      if ($row && !empty($row['ref_code'])) {
        $ref_code = $row['ref_code'];
      }
    }
    $stmtFind->close();
  }
  // If no existing ref, create placeholder
  if ($ref_code === '') {
    $ref_code = 'VP-' . str_pad(strval(rand(0, 99999)), 5, '0', STR_PAD_LEFT);
    $stmt = $con->prepare("INSERT INTO reservations (ref_code, entry_pass_id, user_id, payment_status) VALUES (?, ?, NULL, 'pending')");
    if ($stmt) {
      $stmt->bind_param('si', $ref_code, $epid);
      if (!$stmt->execute()) {
        // If duplicate ref_code or other error, try regenerating once
        $ref_code = 'VP-' . str_pad(strval(rand(0, 99999)), 5, '0', STR_PAD_LEFT);
        $stmt->bind_param('si', $ref_code, $epid);
        @$stmt->execute();
      }
      $stmt->close();
    }
  }
}

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
    /* Consistent dark theme across sections */
    body{margin:0;font-family:'Poppins',sans-serif;background:#111;color:#fff;animation:fadeIn .6s ease-in-out;}
    @keyframes fadeIn{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);} }
    .navbar{display:flex;justify-content:space-between;align-items:center;padding:14px 6%;background:#2b2623;position:sticky;top:0;z-index:1000;}
    .navbar .brand{display:flex;align-items:center;gap:12px;}
    .navbar strong{color:#f4f4f4;font-weight:600;}
    .container{max-width:900px;margin:30px auto;padding:0 6%;}
    .card{background:#fff;color:#222;border-radius:16px;padding:22px;box-shadow:0 4px 15px rgba(0,0,0,.12);border:1px solid #e6ebe6;}
    .card h2{margin:0 0 10px 0;font-size:1.5rem;color:#23412e;}
    .muted{color:#6b7a6d;}
    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;}
    .btn{border:none;border-radius:10px;padding:10px 16px;cursor:pointer;font-weight:600;transition:.2s;}
    .btn.primary{background:#23412e;color:#fff;}
    .btn.primary:hover{transform:translateY(-1px);opacity:.95;}
    .btn.secondary{background:#e6ebe6;color:#23412e;}
    .btn[disabled]{opacity:.6;cursor:not-allowed;}
    .upload{display:flex;flex-direction:column;gap:8px;margin-top:12px;}
    input[type=file]{border:1px dashed #cbd5cb;border-radius:10px;padding:10px;background:#f7faf7;color:#23412e;}
    .preview{margin-top:8px;}
    .preview img{max-width:220px;border-radius:10px;border:1px solid #e6ebe6;display:block;}
    .status{margin-top:10px;font-size:0.95rem;}
    .success{color:#1e7e34;}
    .error{color:#c0392b;}
    .divider{height:1px;background:#e6ebe6;margin:18px 0;}
    .note{font-size:0.95rem;color:#6b7a6d;}
    .policy{background:#f1f6f2;color:#1b2e22;border:1px solid #e0ebe1;border-radius:12px;padding:12px;margin-top:12px;}
    .policy b{color:#1b2e22;}
  </style>
</head>
<body>
  <header class="navbar">
    <div class="brand">
      <a href="mainpage.php"><img src="mainpage/logo.svg" alt="VictorianPass" style="height:32px;"></a>
      <strong>VictorianPass</strong>
    </div>
  </header>

  <div class="container">
    <div class="card">
      <h2>Online Down Payment</h2>
      <p class="muted">Upload a payment receipt to proceed to amenity reservation. This helps us prevent fake or joke bookings.</p>
      <div class="policy">
        <b>Security Policy:</b> A downpayment is required for visitor reservations. It is <b>non-refundable</b>. If you cancel or fail to show up, the downpayment is forfeited to cover potential costs and lost slots.
      </div>

      <div class="pay">
        <h3 style="margin:16px 0 8px;color:#23412e;">Pay via GCash</h3>
        <div class="qrwrap">
          <img src="mainpage/qr.png" alt="GCash QR" class="qrimg" onerror="this.style.display='none'">
        </div>
        <div class="actions">
          <a href="mainpage/qr.png" download class="btn secondary">Download QR</a>
          <a href="https://www.gcash.com/" target="_blank" rel="noopener" class="btn primary">Open GCash</a>
          <button class="btn primary" id="btnMarkPaid">I Paid via GCash</button>
        </div>
        <p class="note">After paying, upload your receipt below to continue.</p>
      </div>

      <div class="upload">
        <label for="receipt">Upload payment receipt (JPEG/PNG/GIF, max 5MB)</label>
        <input type="file" id="receipt" accept="image/*">
        <div class="preview" id="receiptPreviewWrap" style="display:none;">
          <img id="receiptPreview" alt="Receipt Preview">
          <div class="actions" style="margin-top:6px;">
            <button class="btn secondary" id="btnClearReceipt" type="button">Remove Selected File</button>
          </div>
        </div>
        <div class="actions">
          <button class="btn primary" id="btnUpload">Upload & Submit</button>
        </div>
        <div id="uploadStatus" class="status"></div>
      </div>

      <div class="divider"></div>
      <p class="note">Once uploaded, your payment status appears as <b>Pending</b> until verified by admin. You can track verification in your profile under Entries & Requests.</p>

      <div class="actions" style="margin-top:14px;">
        <a href="mainpage.php" class="btn secondary">Back</a>
        <button class="btn primary" id="btnNext" disabled>Next</button>
      </div>
      <?php if ($continue === 'reserve' && $entry_pass_id !== '') { ?>
        <p class="note">After upload, continue to the Amenity Reservation page to select dates and persons for your guest.</p>
      <?php } ?>
    </div>
  </div>

  <script>
    const btnUpload = document.getElementById('btnUpload');
    const btnMarkPaid = document.getElementById('btnMarkPaid');
    const btnNext = document.getElementById('btnNext');
    const btnClearReceipt = document.getElementById('btnClearReceipt');
    const receipt   = document.getElementById('receipt');
    const statusEl  = document.getElementById('uploadStatus');
    const receiptPreviewWrap = document.getElementById('receiptPreviewWrap');
    const receiptPreview = document.getElementById('receiptPreview');
    const refCode   = '<?php echo htmlspecialchars($ref_code); ?>';
    const continueMode = '<?php echo htmlspecialchars($continue); ?>';
    const entryPassId  = '<?php echo htmlspecialchars($entry_pass_id); ?>';
    let paidDone = false;

    // Preview selected receipt
    if (receipt) receipt.addEventListener('change', function(){
      const file = receipt.files && receipt.files[0];
      if (!file) { receiptPreviewWrap.style.display='none'; return; }
      const reader = new FileReader();
      reader.onload = function(e){
        receiptPreview.src = e.target.result;
        receiptPreviewWrap.style.display = 'block';
      };
      reader.readAsDataURL(file);
    });

    if (btnClearReceipt) btnClearReceipt.addEventListener('click', function(){
      receipt.value = '';
      receiptPreviewWrap.style.display = 'none';
    });

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
      // Always upload to reservation via ref_code to link downpayment properly
      fd.append('ref_code', refCode);
      const endpoint = 'upload_receipt.php';
      try{
        const res = await fetch(endpoint, { method: 'POST', body: fd });
        const data = await res.json();
        if(data && data.success){
          statusEl.textContent = 'Receipt uploaded successfully. You can click Next to continue.';
          statusEl.classList.add('success');
          paidDone = true;
          if (btnNext) btnNext.disabled = false;
        } else {
          statusEl.textContent = data && data.message ? data.message : 'Upload failed.';
          statusEl.classList.add('error');
        }
      }catch(err){
          statusEl.textContent = 'Network error. Please try again.';
          statusEl.classList.add('error');
      }
    });

    // Auto-verify path: mark payment verified and proceed without receipt upload
    if (btnMarkPaid) btnMarkPaid.addEventListener('click', async function(){
      statusEl.textContent = '';
      statusEl.className = 'status';
      const fd = new FormData();
      if (refCode) fd.append('ref_code', refCode);
      if (entryPassId) fd.append('entry_pass_id', entryPassId);
      try{
        const res = await fetch('auto_verify_downpayment.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data && data.success){
          statusEl.textContent = 'Payment verified. You can click Next to continue.';
          statusEl.classList.add('success');
          paidDone = true;
          if (btnNext) btnNext.disabled = false;
        } else {
          statusEl.textContent = data && data.message ? data.message : 'Verification failed.';
          statusEl.classList.add('error');
        }
      } catch(err){
        statusEl.textContent = 'Network error. Please try again.';
        statusEl.classList.add('error');
      }
    });

    if (btnNext) btnNext.addEventListener('click', function(){
      if (btnNext.disabled) return;
      if (continueMode === 'reserve' && entryPassId){
        window.location.href = 'reserve.php?entry_pass_id=' + encodeURIComponent(entryPassId) + '&ref_code=' + encodeURIComponent(refCode);
      } else {
        window.location.href = 'reserve.php';
      }
    });
  </script>
</body>
</html>