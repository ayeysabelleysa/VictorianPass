<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Status Result - VictorianPass</title>
  <link rel="icon" type="image/png" href="mainpage/logo.svg" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet" />
  <style>
    body { animation: fadeIn 0.6s ease-in-out; }
    * { font-family: 'Poppins', sans-serif !important; margin: 0; padding: 0; box-sizing: border-box; }
    body {
      background: url("mainpage/background.svg") center/cover no-repeat;
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
    .approved { background: #e6f7ed; color: #1e7d46; border: 1px solid #1e7d46; }
    .pending  { background: #fff9e6; color: #b68b00; border: 1px solid #b68b00; }
    .expired  { background: #f0f0f0; color: #555; border: 1px solid #999; }
    .declined { background: #ffe6e6; color: #b30000; border: 1px solid #b30000; }

    .dashboard {
      display: none;
      background: #23412e;
      padding: 20px;
      width: 95%;
      max-width: 1000px;
      border-radius: 12px;
      color: white;
      margin-top: 20px;
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
    .qr-btn { background: #23412e; color: #fff; padding: 8px 14px; border-radius: 6px; border: none; cursor: pointer; }
    .qr-btn:hover { opacity: 0.85; }
    .qr-btn.disabled { background: #ccc; color: #666; cursor: not-allowed; }
    .qr-btn.disabled:hover { opacity: 1; }
    
    .upload-btn { background: #007bff; color: #fff; padding: 8px 14px; border-radius: 6px; border: none; cursor: pointer; }
    .upload-btn:hover { background: #0056b3; }

    table { width: 100%; border-collapse: collapse; color: #000; background: #fff; border-radius: 10px; overflow: hidden; }
    th, td { padding: 12px; border-bottom: 1px solid #ddd; text-align: center; }
    .status-badge { padding: 5px 10px; border-radius: 12px; font-size: 0.9rem; font-weight: 500; }
    .status-approved { background: #d6eaff; color: #0044cc; }
    .status-pending { background: #fff9e6; color: #b68b00; }
    .status-expired { background: #f0f0f0; color: #555; }

    .modal {
      display: none; position: fixed; top: 0; left: 0;
      width: 100%; height: 100%;
      background: rgba(0,0,0,0.8);
      justify-content: center; align-items: center;
      z-index: 1000;
    }
    .modal-content { background: #222; border-radius: 12px; width: 350px; color: #fff; overflow: hidden; position: relative; }
    .modal-header { display: flex; align-items: center; justify-content: space-between; background: #111; padding: 10px 16px; }
    .modal-header img { height: 28px; }
    .close-btn { font-size: 20px; cursor: pointer; color: #fff; }
    .qr-section { text-align: center; background: #fff; padding: 20px; }
    .qr-section img { width: 150px; height: 150px; }
    .qr-details { padding: 15px; font-size: 0.9rem; line-height: 1.4; color: #eee; }
    
    /* Upload Modal Styles */
    .upload-section { padding: 20px; }
    .upload-section label { display: block; margin-bottom: 8px; font-weight: 500; }
    .upload-section input[type="file"] { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; }
    .upload-preview { text-align: center; margin-top: 10px; }
    .upload-actions { padding: 0 20px 20px; display: flex; gap: 10px; justify-content: flex-end; }
    .upload-actions button { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; }
    .upload-actions button[type="button"] { background: #6c757d; color: white; }
    .upload-actions button[type="submit"] { background: #007bff; color: white; }
    .upload-actions button:hover { opacity: 0.9; }
  </style>
</head>
<body>
  <div class="status-card" id="statusCard">
    <h2>Entry Pass Status</h2>
    <div id="statusResult" class="status-message">Loading...</div>
  </div>

  <div class="dashboard" id="dashboard">
    <div class="dashboard-header">
      <img src="mainpage/logo.svg" alt="VictorianPass Logo" />
      <button onclick="goBack()" class="qr-btn">Go Back</button>
    </div>
    <table>
      <thead>
        <tr><th>Name</th><th>Type</th><th>Status</th><th>QR Code</th><th>Proof of Payment</th></tr>
      </thead>
      <tbody id="dashboardRows"></tbody>
    </table>
  </div>

  <div class="modal" id="qrModal">
    <div class="modal-content">
      <div class="modal-header">
        <img src="mainpage/logo.svg" alt="Victorian Heights" />
        <span class="close-btn" onclick="closeQR()">&times;</span>
      </div>
      <div class="qr-section">
        <img id="qrImage" src="" alt="QR Code" />
      </div>
      <div class="qr-details" id="qrDetails"></div>
    </div>
  </div>

  <!-- Upload Receipt Modal -->
  <div class="modal" id="uploadModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Upload Proof of Payment</h3>
        <span class="close-btn" onclick="closeUploadModal()">&times;</span>
      </div>
      <form id="uploadForm" enctype="multipart/form-data">
        <div class="upload-section">
          <label for="receiptFile">Select Receipt Image:</label>
          <input type="file" id="receiptFile" name="receipt" accept="image/*" required>
          <input type="hidden" id="refCode" name="ref_code" value="">
          <div class="upload-preview" id="uploadPreview"></div>
        </div>
        <div class="upload-actions">
          <button type="button" onclick="closeUploadModal()">Cancel</button>
          <button type="submit">Upload Receipt</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    let statusData = {};
    
    document.addEventListener('DOMContentLoaded', function() {
      const params = new URLSearchParams(window.location.search);
      const code = params.get("code");
      const statusDiv = document.getElementById("statusResult");
      const dashboard = document.getElementById("dashboard");
      const statusCard = document.getElementById("statusCard");
      const dashboardRows = document.getElementById("dashboardRows");

      if (!code) {
        statusDiv.textContent = "⚠️ No code provided!";
        statusDiv.className = "status-message declined";
      } else {
        fetch(`status.php?code=${code}`)
          .then(response => response.json())
          .then(data => {
            statusData = data; // Store all data for later use
            window.statusData = data; // Make it globally available
            
            if (data.success) {
              statusDiv.textContent = `✅ ${data.status.toUpperCase()} - ${data.message}`;
              statusDiv.className = `status-message ${data.status.toLowerCase()}`;
              setTimeout(() => {
                statusCard.style.display = "none";
                dashboard.style.display = "block";
                dashboardRows.innerHTML = `
                  <tr>
                    <td>${data.name}</td>
                    <td>${data.type}</td>
                    <td><span class="status-badge status-${data.status.toLowerCase()}">${data.status}</span></td>
                    <td><button class="qr-btn ${data.status.toLowerCase() === 'approved' ? '' : 'disabled'}" 
                        onclick="${data.status.toLowerCase() === 'approved' ? `openQR('${data.name}','${data.type}','${data.status}','${data.qr_path}')` : 'return false;'}"
                        ${data.status.toLowerCase() !== 'approved' ? 'disabled' : ''}>
                        ${data.status.toLowerCase() === 'approved' ? 'View QR' : 'QR Disabled'}
                    </button></td>
                    <td><button class="upload-btn" onclick="openUploadModal()">Upload Receipt</button></td>
                  </tr>`;
              }, 2000);
            } else {
              statusDiv.textContent = `⚠️ ${data.message}`;
              statusDiv.className = "status-message declined";
            }
          })
          .catch(error => {
            statusDiv.textContent = "⚠️ Error connecting to server.";
            statusDiv.className = "status-message declined";
            console.error('Error:', error);
          });
      }
    });

    function goBack() {
      window.location.href = "qr.php";
    }

    function openQR(name, type, status, qrPath) {
      document.getElementById("qrModal").style.display = "flex";
      document.getElementById("qrImage").src = qrPath || "mainpage/qr.png";
      
      // Get all personal details from the data object
      const data = window.statusData || {};
      
      document.getElementById("qrDetails").innerHTML = `
        <p><strong>Name:</strong> ${name}</p>
        <p><strong>Type:</strong> ${type}</p>
        <p><strong>Status:</strong> ${status}</p>
        <p><strong>Address:</strong> ${data.address || 'Not provided'}</p>
        <p><strong>Email:</strong> ${data.email || 'Not provided'}</p>
        <p><strong>Phone:</strong> ${data.phone || 'Not provided'}</p>
        <p><strong>Valid From:</strong> ${data.start_date || 'Not specified'}</p>
        <p><strong>Expiration:</strong> ${data.end_date || 'Not specified'}</p>`;
    }

    function closeQR() {
      document.getElementById("qrModal").style.display = "none";
    }

    function openUploadModal() {
      const params = new URLSearchParams(window.location.search);
      const code = params.get("code");
      document.getElementById("refCode").value = code;
      document.getElementById("uploadModal").style.display = "flex";
    }

    function closeUploadModal() {
      document.getElementById("uploadModal").style.display = "none";
      document.getElementById("uploadForm").reset();
      document.getElementById("uploadPreview").innerHTML = "";
    }

    // Handle file preview
    document.getElementById("receiptFile").addEventListener("change", function(e) {
      const file = e.target.files[0];
      const preview = document.getElementById("uploadPreview");
      
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          preview.innerHTML = `<img src="${e.target.result}" alt="Receipt Preview" style="max-width: 200px; max-height: 200px;">`;
        };
        reader.readAsDataURL(file);
      } else {
        preview.innerHTML = "";
      }
    });

    // Handle form submission
    document.getElementById("uploadForm").addEventListener("submit", function(e) {
      e.preventDefault();
      
      const formData = new FormData(this);
      
      fetch("upload_receipt.php", {
        method: "POST",
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          alert("Receipt uploaded successfully!");
          closeUploadModal();
        } else {
          alert("Error uploading receipt: " + data.message);
        }
      })
      .catch(error => {
        console.error("Error:", error);
        alert("Error uploading receipt. Please try again.");
      });
    });

    window.onclick = function(event) {
      const qrModal = document.getElementById("qrModal");
      const uploadModal = document.getElementById("uploadModal");
      if (event.target === qrModal) closeQR();
      if (event.target === uploadModal) closeUploadModal();
    };
  </script>
</body>
</html>