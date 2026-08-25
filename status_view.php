<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Status Result - VictorianPass</title>
  <link rel="icon" type="image/png" href="images/logo.svg" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <style>
    body { animation: fadeIn 0.6s ease-in-out; }
    * { font-family: 'Poppins', sans-serif !important; margin: 0; padding: 0; box-sizing: border-box; }
    body {
      background: url("images/background.svg") center/cover no-repeat;
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      position: relative;
    }
    body::before {
      content: "";
      position: absolute;
      top: 0; left: 0;
      width: 100%; height: 100%;
      background: rgba(0,0,0,0.6);
      z-index: -1;
    }

    .status-card {
      background: #fff;
      padding: 30px;
      border-radius: 12px;
      text-align: center;
      width: 90%;
      max-width: 400px;
      box-shadow: 0px 6px 16px rgba(0,0,0,0.25);
    }
    .status-card h2 { margin-bottom: 10px; font-size: 1.6rem; font-weight: 600; }
    .status-message {
      font-size: 1.2rem; font-weight: 500; margin: 20px 0; padding: 12px; border-radius: 8px;
    }
    .status-details { font-size: 0.95rem; color: #333; text-align: left; margin-top: 8px; }
    .status-details p { margin: 6px 0; }
    .approved { background: #e6f7ed; color: #1e7d46; border: 1px solid #1e7d46; }
    .pending  { background: #fff9e6; color: #b68b00; border: 1px solid #b68b00; }
    .expired  { background: #f0f0f0; color: #555; border: 1px solid #999; }
    .declined { background: #ffe6e6; color: #b30000; border: 1px solid #b30000; }
    .cancelled { background: #f7f7f7; color: #8a2a2a; border: 1px solid #8a2a2a; }

    .dashboard {
      display: none;
      background: #23412e;
      padding: 24px;
      width: 95%;
      max-width: 1000px;
      border-radius: 16px;
      color: white;
      margin-top: 20px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.25);
      position: relative;
      overflow: hidden;
    }
    .dashboard-header {
      background: #2c2c2c;
      padding: 15px;
      border-radius: 8px;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .dashboard-header img { height: 40px; }
    .qr-btn { background: #23412e; color: #fff; padding: 10px 16px; border-radius: 8px; border: none; cursor: pointer; display:inline-flex; align-items:center; justify-content:center; white-space:nowrap; text-decoration:none; font-weight:600; min-width:110px; }
    .qr-btn:hover { opacity: 0.92; }
    .qr-btn.disabled { background: #ccc; color: #666; cursor: not-allowed; }
    .qr-btn.disabled:hover { opacity: 1; }

    .table-wrap { width: 100%; background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.10); overflow-x: auto; }
    .status-table { width: 100%; min-width: 980px; border-collapse: separate; border-spacing: 0; color: #000; }
    th, td { padding: 14px 12px; border-bottom: 1px solid #eee; text-align: center; }
    .date-time { color:#666; font-size:0.9rem; white-space: nowrap; }
    .status-badge { padding: 5px 10px; border-radius: 12px; font-size: 0.9rem; font-weight: 500; }
    .status-approved { background: #d6eaff; color: #0044cc; }
    .status-pending { background: #fff4cc; color: #b68b00; }
    .cancel-btn { background:#c0392b; color:#fff; padding:10px 16px; border-radius:8px; border:none; cursor:pointer; white-space: nowrap; display:inline-flex; align-items:center; justify-content:center; min-width:180px; font-weight:600; }
    .cancel-btn:disabled { background:#ccc; color:#666; cursor:not-allowed; }
    .status-expired { background: #f0f0f0; color: #555; }
    .status-denied { background: #ffe6e6; color: #b30000; }
    .status-cancelled { background: #ffecec; color: #8a2a2a; }

    /* Details Modal Styles (match site cards) */
    .details-content { width: 480px; max-width: 92vw; max-height: 85vh; overflow-y: auto; background:#fff; border-radius:14px; box-shadow:0 8px 18px rgba(0,0,0,0.12); }
    #cancelModal .modal-content { width: 520px; max-width: 92vw; }
    .modal-header { display:flex; align-items:center; justify-content:space-between; background:#fff; padding:12px 16px; border-bottom:1px solid #e6ebe6; }
    .modal-header h3{ margin:0; color:#23412e; font-size:1.05rem; font-weight:700; }
    .close-btn {
      width: 36px;
      height: 36px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      background: #fff;
      color: #111827;
      border: 1px solid #333;
      font-size: 20px;
      cursor: pointer;
      line-height: 1;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
      transition: transform 0.2s ease, color 0.2s ease, border-color 0.2s ease;
    }
    .close-btn:hover { color: #000; border-color: #000; transform: scale(1.06); }
    .details-body { padding: 16px; color: #222; font-size: 0.95rem; background:#fff; }
    .details-section { margin-bottom: 14px; }
    .details-section h4 { margin: 0 0 8px 0; font-size: 1rem; color: #23412e; font-weight:700; }
    .details-table { width: 100%; border-collapse: separate; border-spacing: 0 10px; }
    .details-table th{ width:38%; font-weight:600; color:#6b7a6d; padding:6px 8px; vertical-align:middle; }
    .details-table td{ background:#f7faf7; border:1px solid #e6ebe6; color:#222; padding:10px 12px; border-radius:10px; }

    .modal {
      display: none; position: fixed; top: 0; left: 0;
      width: 100%; height: 100%;
      background: rgba(0,0,0,0.8);
      justify-content: center; align-items: center;
      z-index: 1000;
    }
    .modal-content { background: #fff; border-radius: 16px; width: 420px; max-width: 98vw; max-height: 90vh; overflow-y: auto; color: #222; position: relative; box-shadow:0 8px 24px rgba(0,0,0,0.18); }
    @media (max-width: 600px){ .details-content{ width: 92vw; } }
    .modal-header img { height: 28px; }
    .qr-section { text-align: center; background: #fff; padding: 24px 0 10px 0; }
    .qr-section img { width: 200px; height: 200px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.10); }
    .qr-details {
      background: #fff;
      padding: 18px 22px 18px 22px;
      font-size: 1.05rem;
      line-height: 1.6;
      color: #222;
      border-radius: 0 0 16px 16px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
      font-weight: 500;
      text-align: left;
      word-break: break-word;
      max-width: 420px;
      margin: 0 auto;
    }
    .qr-details strong, .qr-details b {
      color: #23412e;
      font-weight: 700;
    }
    .qr-details .valid {
      color: #1e7d46;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 8px;
    }
    .qr-details .valid:before {
      content: '\2714';
      color: #1e7d46;
      font-size: 1.1em;
      margin-right: 4px;
    }
    
    
  </style>
</head>
<body>
  <div class="status-card" id="statusCard">
    <h2>Entry Pass Status</h2>
    <div id="statusResult" class="status-message">Loading...</div>
  </div>
  
  <div class="dashboard" id="dashboard">
    <div class="dashboard-header">
      <a href="mainpage.php" aria-label="Go to Main Page"><img src="images/logo.svg" alt="VictorianPass Logo" /></a>
      <button onclick="goBack()" class="qr-btn">Go Back</button>
    </div>
    <div id="dashboardStatusTitle" style="text-align:center; margin-bottom:15px; display:none;">
      <h2 style="color:#fff; font-size:1.5rem;"></h2>
    </div>
    <div id="paymentNotice" style="display:none; margin:10px 0; padding:10px; border:1px solid #e74c3c; border-radius:8px; background:#fdecea; color:#a94442;">
      <div id="paymentNoticeText" style="margin-bottom:8px; font-weight:600;">Payment receipt rejected. Please pay the sufficient amount and re-upload your receipt.</div>
      <a id="paymentNoticeBtn" class="qr-btn" href="#">Go to Downpayment</a>
    </div>
    <div class="table-wrap">
      <table class="status-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Amenity</th>
            <th>Date & Time</th>
            <th>Persons</th>
            <th>Price</th>
            <th>Status</th>
            <th>Details</th>
            <th>QR Code</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="dashboardRows"></tbody>
      </table>
    </div>
  </div>

  <div class="modal" id="qrModal">
    <div class="modal-content">
      <div class="modal-header">
        <a href="mainpage.php" aria-label="Go to Main Page"><img src="images/logo.svg" alt="Victorian Heights" /></a>
        <span class="close-btn" onclick="closeQR()">&times;</span>
      </div>
      <div class="qr-section">
        <img id="qrImage" src="" alt="QR Code" />
      </div>
      <div class="qr-details" id="qrDetails"></div>
    </div>
  </div>

  <!-- Details Modal -->
  <div class="modal" id="detailsModal">
    <div class="modal-content details-content">
      <div class="modal-header">
        <h3>Reservation Details</h3>
        <span class="close-btn" onclick="closeDetails()">&times;</span>
      </div>
      <div class="details-body" id="detailsBody">
        <!-- Filled dynamically -->
      </div>
    </div>
  </div>

  <div class="modal" id="cancelModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Cancel Reservation</h3>
        <span class="close-btn" onclick="closeCancelModal()">&times;</span>
      </div>
      <div class="details-body">
        <p>Are you sure you want to cancel this reservation?</p>
        <p style="font-size:0.9rem;color:#d9534f;font-weight:bold">Note: Downpayment is non-refundable. Cancelling will forfeit your downpayment.</p>
      </div>
      <div style="display:flex; gap:10px; padding: 0 16px 16px 16px; justify-content:center; flex-wrap:nowrap;">
        <button type="button" class="qr-btn" onclick="closeCancelModal()">Keep Reservation</button>
        <button type="button" class="cancel-btn" onclick="performCancel()">Confirm Cancel</button>
      </div>
    </div>
  </div>

  
  <script>
    function formatMDY(d){
      if(!d) return '';
      var p=String(d).split('-'); if(p.length!==3) return d;
      return [p[1].padStart(2,'0'), p[2].padStart(2,'0'), String(p[0]).slice(-2)].join('/');
    }
    function fmtTime(t){
      if(!t) return '';
      const parts = String(t).split(':');
      let h = parseInt(parts[0],10);
      const m = String(parts[1]||'00').padStart(2,'0');
      const ap = h>=12 ? 'PM' : 'AM';
      h = h%12; if(h===0) h=12;
      return `${h}:${m} ${ap}`;
    }
    function countWeekdaysInclusive(startStr,endStr){
      if(!startStr || !endStr) return 0;
      const s=new Date(startStr);
      const e=new Date(endStr);
      if(isNaN(s) || isNaN(e)) return 0;
      let count=0;
      const d=new Date(s.getFullYear(),s.getMonth(),s.getDate());
      const end=new Date(e.getFullYear(),e.getMonth(),e.getDate());
      while(d<=end){
        const dow=d.getDay();
        if(dow!==0 && dow!==6){ count++; }
        d.setDate(d.getDate()+1);
      }
      return count;
    }
    let statusData = {};

    function escHtml(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }
    function bannerHtml(iconClass, text) {
      return (iconClass ? '<i class="' + iconClass + '" aria-hidden="true"></i> ' : '') + escHtml(text);
    }

    document.addEventListener('DOMContentLoaded', function() {
      const params = new URLSearchParams(window.location.search);
      const code = params.get("code");
      const statusDiv = document.getElementById("statusResult");
      const statusCard = document.getElementById("statusCard");
      const dashboard = document.getElementById("dashboard");
      const dashboardRows = document.getElementById("dashboardRows");

      if (!code) {
        statusDiv.innerHTML = bannerHtml('fa-solid fa-triangle-exclamation', 'No code provided!');
        statusDiv.className = "status-message declined";
      } else {
        fetch(`status.php?code=${code}`)
          .then(response => response.json())
          .then(data => {
            statusData = data; // Store all data for later use
            window.statusData = data; // Make it globally available
            
            if (data.success) {
              // If this page was opened directly (likely via QR scan), redirect to full QR card
              const internalRef = document.referrer && document.referrer.indexOf(location.origin) === 0;
              if (!internalRef) {
                window.location.replace(`qr_view.php?code=${encodeURIComponent(code)}`);
                return;
              }
              try { if (sessionStorage.getItem('cancelled:'+code)==='1') { data.status = 'cancelled'; } } catch(_){}
              const status = (data.status || '').toLowerCase();
              const statusClass = (status === 'pending_update') ? 'pending' : status;
              const statusLabel = String(data.status || '').replace(/[_-]+/g,' ').toLowerCase().replace(/\b\w/g,function(m){ return m.toUpperCase(); });
              let bannerText = '';
              let bannerIcon = '';
              switch (status) {
                case 'approved': bannerText = 'Valid Entry Pass'; bannerIcon = 'fa-solid fa-circle-check'; break;
                case 'expired': bannerText = 'Expired Entry Pass'; bannerIcon = 'fa-solid fa-circle-xmark'; break;
                case 'pending': bannerText = 'Pending Review'; bannerIcon = 'fa-solid fa-hourglass-half'; break;
                case 'pending_update': bannerText = 'Pending Update'; bannerIcon = 'fa-solid fa-hourglass-half'; break;
                case 'denied': bannerText = 'Denied Entry Pass'; bannerIcon = 'fa-solid fa-circle-xmark'; break;
                case 'rejected': bannerText = 'Rejected'; bannerIcon = ''; break;
                case 'cancelled': bannerText = 'Cancelled Reservation'; bannerIcon = 'fa-solid fa-circle-xmark'; break;
                default: bannerText = data.message || 'Unknown status'; bannerIcon = 'fa-solid fa-triangle-exclamation';
              }
              statusDiv.innerHTML = bannerHtml(bannerIcon, bannerText);
              statusDiv.className = `status-message ${statusClass}`;

              // Update Document Title and Dashboard Header
              document.title = `${bannerText} - VictorianPass`;
              const dashTitleDiv = document.getElementById('dashboardStatusTitle');
              if (dashTitleDiv) {
                 dashTitleDiv.style.display = 'block';
                 dashTitleDiv.querySelector('h2').innerHTML = bannerHtml(bannerIcon, bannerText);
                 // Set color based on status if needed, but white text on dark background works
                 if (status === 'cancelled' || status === 'denied' || status === 'expired') {
                     dashTitleDiv.querySelector('h2').style.color = '#ffcccb';
                 } else if (status === 'approved') {
                     dashTitleDiv.querySelector('h2').style.color = '#d4edda';
                 } else {
                     dashTitleDiv.querySelector('h2').style.color = '#fff';
                 }
              }

              statusCard.style.display = 'block';
              setTimeout(() => {
                statusCard.style.display = "none";
                dashboard.style.display = "block";
                try{
                  const payStatus = String(data.payment_status||'').toLowerCase();
                  if(payStatus === 'rejected'){
                    const btn = document.getElementById('paymentNoticeBtn');
                    const box = document.getElementById('paymentNotice');
                    if(btn && box){
                      const isVisitor = !!(data.entry_pass_id && Number(data.entry_pass_id) > 0);
                      const cont = isVisitor ? 'reserve' : 'reserve_resident';
                      const params = new URLSearchParams({continue: cont, ref_code: (data.code||'')});
                      if(isVisitor){ params.set('entry_pass_id', String(data.entry_pass_id)); }
                      btn.href = 'downpayment.php?' + params.toString();
                      box.style.display = 'block';
                    }
                  }
                }catch(_){ }
                const dateDisplay = (data.start_date && data.end_date)
                  ? `${formatMDY(data.start_date)} → ${formatMDY(data.end_date)}`
                  : (data.start_date && data.expires_at)
                    ? `${formatMDY(data.start_date)} → ${formatMDY(data.expires_at)}`
                    : (data.start_date ? formatMDY(data.start_date) : '-')
                const timeDisplay = (data.start_time || data.end_time) ? (`<div class="date-time">${fmtTime(data.start_time)}${data.end_time?(' → '+fmtTime(data.end_time)):''}</div>`) : '';
                const personsDisplay = (function(p){ const n = parseInt(p, 10); return isNaN(n) ? '-' : String(n); })(data.persons);
                const priceDisplay = (function(p){ const n = parseFloat(p); if (isNaN(n)) return '-'; try { return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(n); } catch(e) { return `₱ ${n.toFixed(2)}`; } })(data.price);
                const statusLower = (data.status||'').toLowerCase();
                const canCancel = statusLower==='pending';
                const isGuest = String(data.type||'').toLowerCase() === 'guest entry';
                dashboardRows.innerHTML = `
                  <tr>
                    <td>${data.name}</td>
                    <td>${data.type}</td>
                    <td>${dateDisplay}${timeDisplay}</td>
                    <td>${personsDisplay}</td>
                    <td>${priceDisplay}</td>
                    <td><span class="status-badge status-${statusClass}">${statusLabel}</span></td>
                    <td><button class="qr-btn" onclick="openDetails()">View More Details</button></td>
                    <td>
                      ${((data.status||'').toLowerCase() === 'approved')
                        ? `<a class="qr-btn" href="qr_view.php?code=${encodeURIComponent(code)}">View QR</a>`
                        : `<button class="qr-btn disabled" disabled>QR Disabled</button>`}
                    </td>
                    <td><button class="cancel-btn" onclick="confirmCancel()" ${canCancel ? '' : 'disabled'}>${canCancel ? (isGuest ? 'Cancel Request' : 'Cancel Reservation') : 'Cancel Disabled'}</button></td>
                  </tr>`;
              }, 600);
            } else {
              statusDiv.innerHTML = bannerHtml('fa-solid fa-triangle-exclamation', data.message);
              statusDiv.className = "status-message declined";
            }
          })
          .catch(error => {
            statusDiv.innerHTML = bannerHtml('fa-solid fa-triangle-exclamation', 'Error connecting to server.');
            statusDiv.className = "status-message declined";
            console.error('Error:', error);
          });
      }
    });

    function goBack() {
      let s = String((window.statusData || {}).status || '').toLowerCase();
      try { const params = new URLSearchParams(window.location.search); const code = params.get('code'); if (sessionStorage.getItem('cancelled:'+code)==='1') s = 'cancelled'; } catch(_){}
      if (s === 'cancelled' || s === 'denied') { window.location.href = 'mainpage.php'; return; }
      if (document.referrer && document.referrer.indexOf(location.origin) === 0) { window.location.href = document.referrer; return; }
      if (history.length > 1) { history.back(); return; }
      window.location.href = 'checkurstatus.php';
    }

    // Reservation button removed per request

    function openQR(name, type, status, qrPath) {
      document.getElementById("qrModal").style.display = "flex";

      const params = new URLSearchParams(window.location.search);
      const scannedCode = params.get('code') || ((window.statusData || {}).code) || '';
      const basePath = window.location.pathname.replace(/\/[^\/]*$/, '');
      const verificationLink = `${location.origin}${basePath}/qr_view.php?code=${encodeURIComponent(scannedCode)}`;
      const data = window.statusData || {};
      name = name || data.name || '';
      type = type || data.type || '';
      status = status || data.status || '';
      qrPath = qrPath || data.qr_path || '';
      const isGuestEntry = String(type || data.type || '').toLowerCase() === 'guest entry';
      const useStoredQR = qrPath && !/mainpage\/qr\.png$/i.test(qrPath);
      const dynamicQR = `https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=${encodeURIComponent(verificationLink)}`;
      document.getElementById("qrImage").src = useStoredQR ? qrPath : dynamicQR;
      
      const accessWindow = `${formatMDY(data.start_date || '') || '-'}${data.expires_at ? ' → ' + formatMDY(data.expires_at) : ''}`;
      const statusLower = (status || '').toLowerCase();
      const banner = statusLower === 'approved' ? bannerHtml('fa-solid fa-circle-check', 'Valid Entry Pass')
                    : statusLower === 'expired' ? bannerHtml('fa-solid fa-circle-xmark', 'Expired Entry Pass')
                    : statusLower === 'pending' ? bannerHtml('fa-solid fa-hourglass-half', 'Pending Review')
                    : statusLower === 'cancelled' ? bannerHtml('fa-solid fa-circle-xmark', 'Cancelled Reservation')
                    : bannerHtml('fa-solid fa-triangle-exclamation', status);

      document.getElementById("qrDetails").innerHTML = `
        <p style="font-weight:600;">${banner}</p>
        <p><strong>${isGuestEntry ? "Resident's Guest Name" : "Name"}:</strong> ${name}</p>
        ${isGuestEntry && data.resident_name ? `<p><strong>Referred by Resident:</strong> ${data.resident_name}</p>` : ''}
        ${data.birthdate ? `<p><strong>Birthdate:</strong> ${data.birthdate}</p>` : ''}
        ${data.sex ? `<p><strong>Sex:</strong> ${data.sex}</p>` : ''}
        ${data.contact ? `<p><strong>Contact:</strong> ${data.contact}</p>` : ''}
        ${data.address ? `<p><strong>${isGuestEntry ? 'Resident House Number' : 'Address'}:</strong> ${data.address}</p>` : ''}
        ${data.purpose ? `<p><strong>Purpose:</strong> ${data.purpose}</p>` : ''}
        <p><strong>Type:</strong> ${isGuestEntry ? "Resident's Guest" : type}</p>
        ${(!isGuestEntry && (data.start_date || data.expires_at)) ? `<p><strong>Valid Dates:</strong> ${accessWindow}</p>` : ''}
        <p><strong>Full QR Card:</strong> <a href="${verificationLink}" target="_blank" style="color:#9bd08f;">Open full QR card</a></p>
      `;
    }

    function closeQR() {
      document.getElementById("qrModal").style.display = "none";
    }

    function openDetails() {
      const data = window.statusData || {};
      const isGuestEntry = String(data.type || '').toLowerCase() === 'guest entry';
      const dateDisplay = (data.start_date && data.end_date)
        ? `${data.start_date} → ${data.end_date}`
        : (data.start_date && data.expires_at)
          ? `${data.start_date} → ${data.expires_at}`
          : (data.start_date || '-')
      const personsDisplay = (function(p){ const n = parseInt(p, 10); return isNaN(n) ? '-' : String(n); })(data.persons);
      const priceDisplay = (function(p){ const n = parseFloat(p); if (isNaN(n)) return '-'; try { return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(n); } catch(e) { return `₱ ${n.toFixed(2)}`; } })(data.price);
      let durationDisplay = '';
      if (data.start_date && data.end_date) {
        let days = 0;
        if (String(data.type || '') === 'Pool') {
          days = countWeekdaysInclusive(data.start_date, data.end_date);
        } else {
          const sDate = new Date(data.start_date);
          const eDate = new Date(data.end_date);
          const diff = Math.floor((eDate - sDate) / (1000 * 60 * 60 * 24));
          days = isNaN(diff) ? 0 : (diff + 1);
        }
        if (days > 0) {
          durationDisplay = `${days} day${days > 1 ? 's' : ''}`;
        }
      }

      const yourInfoPairs = isGuestEntry
        ? [
            ['Full Name', data.name || '-'],
            ['Email', data.email || '-'],
            ['Birthdate', data.birthdate || '-'],
            ['Sex', data.sex || '-']
          ]
        : [
            ['Full Name', data.name || '-'],
            ['Email', data.email || '-'],
            ['Address', data.address || '-'],
            ['Birthdate', data.birthdate || '-'],
            ['Sex', data.sex || '-']
          ];
      const yourInfo = yourInfoPairs.map(([k,v]) => `<tr><th>${k}</th><td>${v}</td></tr>`).join('');

      const resRows = [];
      if (!isGuestEntry && (data.type || '')) resRows.push(['Amenity', data.type]);
      resRows.push(['Purpose', data.purpose || '-']);
      resRows.push(['Date', dateDisplay]);
      if (!isGuestEntry && durationDisplay) resRows.push(['Duration', durationDisplay]);
      if (data.start_time || data.end_time) {
        const t1 = fmtTime(data.start_time);
        const t2 = data.end_time ? fmtTime(data.end_time) : '';
        resRows.push(['Time', `${t1}${t2?(' → '+t2):''}`]);
      }
      if (!isGuestEntry && data.persons) resRows.push(['Persons', personsDisplay]);
      if (!isGuestEntry && data.price != null && data.price !== '') resRows.push(['Price', priceDisplay]);
      const reservationInfo = resRows.map(([k,v]) => `<tr><th>${k}</th><td>${v}</td></tr>`).join('');

      let priceInfo = '';
      if (!isGuestEntry) {
        const fmtPhp = function(p){ const n=parseFloat(p); if(isNaN(n)) return '₱ 0.00'; try{ return new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(n); } catch(e){ return `₱ ${Number(n||0).toFixed(2)}`; } };
        const totalPriceVal = (function(p){ const n=parseFloat(p); return isNaN(n)?0:n; })(data.price);
        const downVal = (function(p){ const n=parseFloat(p); return isNaN(n)?0:n; })(data.downpayment);
        const remainingVal = Math.max(0, totalPriceVal - downVal);
        priceInfo = [
          ['Total Price', fmtPhp(totalPriceVal)],
          ['Online Payment (Partial)', fmtPhp(downVal)],
          ['Onsite Payment (Remaining)', fmtPhp(remainingVal)]
        ].map(([k,v]) => `<tr><th>${k}</th><td>${v}</td></tr>`).join('');
      }

      const residentInfoRows = [];
      if (data.resident_name || data.resident_house_number || data.resident_email || data.resident_phone) {
        if (data.resident_name) residentInfoRows.push(['Name', data.resident_name]);
        if (data.resident_house_number) residentInfoRows.push(['House No.', data.resident_house_number]);
        if (data.resident_email) residentInfoRows.push(['Email', data.resident_email]);
        if (data.resident_phone) residentInfoRows.push(['Contact', data.resident_phone]);
      }
      const residentInfoHtml = residentInfoRows.length ? (`<div class=\"details-section\"><h4>Resident Information</h4><table class=\"details-table\">${residentInfoRows.map(([k,v])=>`<tr><th>${k}</th><td>${v}</td></tr>`).join('')}</table><div class=\"form-note\" style=\"margin-top:8px;color:#23412e\">Share the Status Code with your guest so they can check their status.</div></div>`) : '';

      const html = `
        ${residentInfoHtml}
        <div class="details-section">
          <h4>${residentInfoRows.length ? "Resident's Guest Information" : 'Your Information'}</h4>
          <table class="details-table">${yourInfo}</table>
        </div>
        <div class="details-section">
          <h4>Reservation Details</h4>
          <table class="details-table">${reservationInfo}</table>
        </div>
        ${priceInfo ? (`<div class=\"details-section\"><h4>Price Details</h4><table class=\"details-table\">${priceInfo}</table></div>`) : ''}`;

      document.getElementById('detailsBody').innerHTML = html;
      document.getElementById('detailsModal').style.display = 'flex';
    }

    function closeDetails() {
      document.getElementById('detailsModal').style.display = 'none';
      document.getElementById('detailsBody').innerHTML = '';
    }

    
    function confirmCancel(){
      const data = window.statusData || {};
      const statusLower = String(data.status || '').toLowerCase();
      if(statusLower !== 'pending' && statusLower !== 'pending_update'){ alert('Cancel only available for pending reservations.'); return; }
      
      const modal = document.getElementById('cancelModal');
      const isGuest = String(data.type || '').toLowerCase() === 'guest entry';
      const h3 = modal.querySelector('h3');
      const pBody = modal.querySelector('.details-body p:first-child');
      const pNote = modal.querySelector('.details-body p:nth-of-type(2)');
      const btnKeep = modal.querySelector('.qr-btn'); // "Keep Reservation" button

      if(isGuest){
        if(h3) h3.textContent = 'Cancel Request';
        if(pBody) pBody.textContent = 'Are you sure you want to cancel this request?';
        if(pNote) pNote.style.display = 'none';
        if(btnKeep) btnKeep.textContent = 'Keep Request';
      } else {
        if(h3) h3.textContent = 'Cancel Reservation';
        if(pBody) pBody.textContent = 'Are you sure you want to cancel this reservation?';
        if(pNote) {
           pNote.style.display = 'block';
           pNote.textContent = 'Note: Downpayment is non-refundable. Cancelling will forfeit your downpayment.';
        }
        if(btnKeep) btnKeep.textContent = 'Keep Reservation';
      }

      modal.style.display='flex';
    }
    function closeCancelModal(){
      document.getElementById('cancelModal').style.display='none';
    }
    function performCancel(){
      const params=new URLSearchParams(window.location.search);
      const code=params.get('code');
      const d = window.statusData || {};
      const statusLower = String(d.status || '').toLowerCase();
      const isGuest = String(d.type || '').toLowerCase() === 'guest entry';

      if(statusLower !== 'pending' && statusLower !== 'pending_update'){ alert(isGuest ? 'Unable to cancel: only pending requests can be canceled' : 'Unable to cancel: only pending reservations can be canceled'); return; }
      if(!code){ alert('Missing reservation code'); return; }
      fetch('status.php',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({action:'cancel',code})
      }).then(r=>r.json()).then(data=>{
        if(data && data.success){
          closeCancelModal();
          alert(isGuest ? 'Request cancelled.' : 'Reservation cancelled. Downpayment is non-refundable.');
          try {
            try { localStorage.setItem('cancelled:'+code, String(Date.now())); } catch(_){}
            d.status = 'cancelled';
            window.statusData = d;
            const statusDiv = document.getElementById('statusResult');
            const newBannerText = isGuest ? 'Cancelled Request' : 'Cancelled Reservation';
            statusDiv.innerHTML = bannerHtml('fa-solid fa-circle-xmark', newBannerText);
            statusDiv.className = 'status-message cancelled';

            // Update Document Title and Dashboard Header
            document.title = `Cancelled ${isGuest ? 'Request' : 'Reservation'} - VictorianPass`;
            const dashTitleDiv = document.getElementById('dashboardStatusTitle');
            if (dashTitleDiv) {
                dashTitleDiv.style.display = 'block';
                dashTitleDiv.querySelector('h2').innerHTML = bannerHtml('fa-solid fa-circle-xmark', newBannerText);
                dashTitleDiv.querySelector('h2').style.color = '#ffcccb';
            }
            
            const dateDisplay = (d.start_date && d.end_date)
              ? `${d.start_date} → ${d.end_date}`
              : (d.start_date && d.expires_at)
                ? `${d.start_date} → ${d.expires_at}`
                : (d.start_date || '-')
            const timeDisplay = (d.start_time || d.end_time) ? (`<div class="date-time">${fmtTime(d.start_time)}${d.end_time?(' → '+fmtTime(d.end_time)):''}</div>`) : '';
            const personsDisplay = (function(p){ const n = parseInt(p, 10); return isNaN(n) ? '-' : String(n); })(d.persons);
            const priceDisplay = (function(p){ const n = parseFloat(p); if (isNaN(n)) return '-'; try { return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(n); } catch(e) { return `₱ ${Number(n||0).toFixed(2)}`; } })(d.price);
            const canCancel = false;
            document.getElementById('dashboardRows').innerHTML = `
              <tr>
                <td>${d.name||'-'}</td>
                <td>${d.type||'-'}</td>
                <td>${dateDisplay}${timeDisplay}</td>
                <td>${personsDisplay}</td>
                <td>${priceDisplay}</td>
                <td><span class="status-badge status-cancelled">Cancelled</span></td>
                <td><button class="qr-btn" onclick="openDetails()">View More Details</button></td>
                <td><button class="qr-btn disabled" disabled>QR Disabled</button></td>
                <td><button class="cancel-btn" onclick="confirmCancel()" disabled>Cancel Disabled</button></td>
              </tr>`;
          } catch(_){ /* noop */ }
        } else {
          alert('Unable to cancel: '+(data && data.message ? data.message : 'Server error'));
        }
      }).catch(_=>{ alert('Network error. Please try again.'); });
    }

    window.onclick = function(event) {
      const qrModal = document.getElementById("qrModal");
      const detailsModal = document.getElementById('detailsModal');
      const cancelModal = document.getElementById('cancelModal');
      if (event.target === qrModal) closeQR();
      if (event.target === detailsModal) closeDetails();
      if (event.target === cancelModal) closeCancelModal();
    };
  </script>
</body>
</html>
