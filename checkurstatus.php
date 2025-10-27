<?php
// Start session if needed for any future functionality
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Check Status - VictorianPass</title>
  <link rel="icon" type="image/png" href="mainpage/logo.svg">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">

  <style>
    body {
      animation: fadeIn 0.6s ease-in-out;
    }

    /* Global styling */
    * {
      font-family: 'Poppins', sans-serif !important;
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      background: url("mainpage/background.svg") center/cover no-repeat;
      color: #fff;
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      position: relative;
    }

    /* Overlay */
    body::before {
      content: "";
      position: absolute;
      top: 0; left: 0;
      width: 100%; height: 100%;
      background: rgba(0,0,0,0.65);
      z-index: 0;
    }

    /* Go Back Button */
    .btn-back {
      position: absolute;
      top: 30px;
      left: 30px;
      background: #e5ddc6;
      color: #2d593d;
      font-weight: 500;
      padding: 8px 16px;
      border-radius: 8px;
      text-decoration: none;
      font-size: 0.9rem;
      border: none;
      box-shadow: 0px 4px 10px rgba(0,0,0,0.3);
      transition: background 0.2s ease, transform 0.2s ease;
      z-index: 2;
    }

    .btn-back:hover {
      transform: scale(1.03);
      opacity: 0.92;
    }

    /* Status Box */
    .status-box {
      position: relative;
      z-index: 1;
      background: #fff;
      color: #222;
      padding: 35px 28px;
      border-radius: 14px;
      width: 90%;
      max-width: 420px;
      text-align: center;
      box-shadow: 0px 8px 20px rgba(0,0,0,0.35);
      animation: fadeInUp 0.5s ease;
    }

    .status-box h2 {
      margin-bottom: 22px;
      font-size: 1.6rem;
      font-weight: 600;
      color: #23412e;
    }

    .status-box label {
      display: block;
      text-align: left;
      margin-bottom: 6px;
      font-weight: 500;
      font-size: 0.9rem;
    }

    .status-box input {
      width: 100%;
      padding: 12px 14px;
      margin-bottom: 20px;
      border: 1px solid #ccc;
      border-radius: 8px;
      font-size: 0.95rem;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    .status-box input:focus {
      border-color: #23412e;
      box-shadow: 0 0 0 2px rgba(35,65,46,0.2);
      outline: none;
    }

    .btn-confirm {
      display: block;
      width: 100%;
      background: #23412e;
      color: #fff;
      font-weight: 600;
      padding: 12px;
      border-radius: 8px;
      border: none;
      cursor: pointer;
      transition: transform 0.2s ease, opacity 0.2s ease;
      font-size: 1rem;
    }

    .btn-confirm:hover {
      transform: scale(1.03);
      opacity: 0.92;
    }

    /* Instructions */
    .form-note {
      font-size: 0.85rem;
      margin-top: 18px;
      color: #333;
      background: #f9f9f9;
      padding: 12px;
      border-radius: 8px;
      line-height: 1.5;
      text-align: left;
      border: 1px solid #eee;
    }

    .form-note strong {
      color: #23412e;
    }

    /* Extra animation for the box (subtle pop-in) */
    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(15px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* Mobile tweaks */
    @media (max-width: 480px) {
      .btn-back {
        top: 20px;
        left: 20px;
        padding: 6px 12px;
        font-size: 0.8rem;
      }
      .status-box {
        padding: 25px 20px;
      }
      .status-box h2 {
        font-size: 1.3rem;
      }
    }
  </style>
</head>
<body>

  <!-- Go Back Button -->
  <a href="mainpage.php" class="btn-back">← Go Back</a>

  <!-- STATUS BOX -->
  <div class="status-box">
    <h2>Check Your Status</h2>
    <form id="statusForm" action="status_view.php" method="GET">
      <label for="code">Status Code:</label>
      <input type="text" id="code" name="code" placeholder="Enter your code*" required>
      <button type="submit" class="btn-confirm">Confirm</button>
    </form>

    <div id="inlineResult" class="form-note" style="display:none;"></div>

    <div class="form-note">
      <strong>Instructions:</strong><br>
      • All visitors should use their <strong>Status Code</strong> to check status.<br>
      • If you are a resident, go to your Profile Page to check your status.
    </div>
  </div>

  <script>
    const form = document.getElementById('statusForm');
    const codeInput = document.getElementById('code');
    const resultBox = document.getElementById('inlineResult');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const code = codeInput.value.trim();
      if (!code) {
        resultBox.style.display = 'block';
        resultBox.innerHTML = '⚠️ Please enter your status code.';
        return;
      }

      try {
        const res = await fetch(`status.php?code=${encodeURIComponent(code)}`);
        const data = await res.json();
        resultBox.style.display = 'block';
        if (data.success) {
          const badgeColor = {
            approved: '#1e7d46',
            pending: '#b68b00',
            expired: '#555',
            declined: '#b30000'
          }[String(data.status).toLowerCase()] || '#23412e';

          resultBox.innerHTML = `
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
              <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${badgeColor}"></span>
              <strong>${String(data.status).toUpperCase()}</strong>
            </div>
            <div style="margin-bottom:8px;">${data.message}</div>
            <div style="font-size:0.9rem;color:#333;margin-top:6px;">
              <div><strong>Name:</strong> ${data.name || 'N/A'}</div>
              <div><strong>Type:</strong> ${data.type || 'N/A'}</div>
            </div>
            <div style="margin-top:12px;">
              <a href="status_view.php?code=${encodeURIComponent(code)}" style="background:#23412e;color:#fff;padding:8px 12px;border-radius:6px;text-decoration:none;display:inline-block;">Open full view</a>
            </div>
          `;
        } else {
          resultBox.innerHTML = `⚠️ ${data.message || 'Unable to find status for this code.'}`;
        }
      } catch (err) {
        resultBox.style.display = 'block';
        resultBox.innerHTML = '⚠️ Error connecting to server.';
      }
    });
  </script>
</body>
</html>