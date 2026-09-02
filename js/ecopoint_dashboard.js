/**
 * VHEcoPoint Resident Dashboard — Real-Time Session Monitoring
 * ==============================================================
 * Connects to the SSE endpoint and updates the dashboard with live
 * session status, weight, points, notifications, and historical data.
 * 
 * Features:
 * - Auto-reconnecting EventSource (handles Wi-Fi drops)
 * - Real-time weight/points updates
 * - Session verification & completion notifications
 * - Live session card population
 * - Historical session tracking
 * - Error handling and graceful fallback
 */

(function() {
  'use strict';

  // =====================================================================
  // Configuration
  // =====================================================================
  const CONFIG = {
    SSE_ENDPOINT: '/VictorianPass/api/ecopoint_sse.php',
    POLL_ENDPOINT: '/VictorianPass/api/ecopoint_session_status.php',
    POLL_INTERVAL_MS: 5000, // fallback poll if SSE fails
    NOTIFICATION_DURATION_MS: 6000,
    UI_UPDATE_THROTTLE_MS: 200, // prevent excessive DOM updates
  };

  // =====================================================================
  // State
  // =====================================================================
  let eventSource = null;
  let lastSnapshot = null;
  let previousSession = null;
  let hasRenderedInitialState = false;
  let notifiedEndedSessionId = null;
  let pollInterval = null;
  let uiUpdateTimeout = null;
  let isConnecting = false;
  let reconnectAttempts = 0;
  const MAX_RECONNECT_ATTEMPTS = 10;
  const RECONNECT_DELAY_MS = 2000;

  // =====================================================================
  // Logging (for debugging)
  // =====================================================================
  function log(msg, data) {
    const timestamp = new Date().toLocaleTimeString();
    if (data) {
      console.log(`[EcoPoint ${timestamp}] ${msg}`, data);
    } else {
      console.log(`[EcoPoint ${timestamp}] ${msg}`);
    }
  }

  // =====================================================================
  // DOM Helpers
  // =====================================================================
  function getElement(id) {
    return document.getElementById(id);
  }

  function setText(id, text) {
    const el = getElement(id);
    if (el) {
      el.textContent = text;
    }
  }

  function setHTML(id, html) {
    const el = getElement(id);
    if (el) {
      el.innerHTML = html;
    }
  }

  function setClass(id, className, add) {
    const el = getElement(id);
    if (el) {
      if (add) {
        el.classList.add(className);
      } else {
        el.classList.remove(className);
      }
    }
  }

  function setStyle(id, styles) {
    const el = getElement(id);
    if (el) {
      Object.assign(el.style, styles);
    }
  }

  // =====================================================================
  // Notification System
  // =====================================================================
  function showNotification(type, title, message, iconClassOverride, iconColor) {
    // Create or reuse notification container
    let container = getElement('ecopoint-notification-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'ecopoint-notification-container';
      container.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10000;
        max-width: 400px;
        max-height: 80vh;
        overflow-y: auto;
      `;
      document.body.appendChild(container);
    }

    // Create notification element
    const notification = document.createElement('div');
    const bgColor = type === 'success' ? '#d1fae5' : type === 'error' ? '#fee2e2' : '#fef3c7';
    const borderColor = type === 'success' ? '#86efac' : type === 'error' ? '#fca5a5' : '#fde047';
    const textColor = type === 'success' ? '#15803d' : type === 'error' ? '#991b1b' : '#b45309';
    const iconClass = iconClassOverride || (type === 'success' ? 'fa-solid fa-circle-check' : type === 'error' ? 'fa-solid fa-circle-xmark' : 'fa-solid fa-circle-info');

    notification.style.cssText = `
      background-color: ${bgColor};
      border: 2px solid ${borderColor};
      border-radius: 12px;
      padding: 16px 18px;
      margin-bottom: 12px;
      color: ${textColor};
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
      animation: slideIn 0.3s ease-out;
    `;

    const titleEl = document.createElement('div');
    titleEl.style.cssText = `
      font-weight: 800;
      font-size: 16px;
      margin-bottom: 4px;
    `;
    titleEl.innerHTML = '<i class="' + iconClass + '"' + (iconColor ? ' style="color:' + iconColor + '"' : '') + ' aria-hidden="true"></i> ';
    titleEl.appendChild(document.createTextNode(title));

    const messageEl = document.createElement('div');
    messageEl.style.cssText = `
      font-size: 14px;
      line-height: 1.5;
    `;
    messageEl.textContent = message;

    notification.appendChild(titleEl);
    notification.appendChild(messageEl);
    container.appendChild(notification);

    // Auto-remove after duration
    setTimeout(() => {
      notification.style.animation = 'slideOut 0.3s ease-in';
      setTimeout(() => {
        container.removeChild(notification);
      }, 300);
    }, CONFIG.NOTIFICATION_DURATION_MS);
  }

  function showSessionPopup(type, title, description, detailText, buttonText) {
    const existing = getElement('ecopoint-session-popup');
    if (existing) {
      existing.remove();
    }

    const overlay = document.createElement('div');
    overlay.id = 'ecopoint-session-popup';
    overlay.style.cssText = `
      position: fixed;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(15, 23, 42, 0.3);
      backdrop-filter: blur(2px);
      z-index: 10001;
      padding: 20px;
      animation: ecopointPopupFadeIn 0.25s ease-out;
    `;

    const card = document.createElement('div');
    card.style.cssText = `
      width: min(440px, 100%);
      background: #ffffff;
      border: 1px solid rgba(34, 197, 94, 0.2);
      border-radius: 18px;
      box-shadow: 0 24px 50px rgba(15, 23, 42, 0.18);
      padding: 26px 24px 20px;
      text-align: center;
      color: #0f172a;
      position: relative;
    `;

    const iconWrap = document.createElement('div');
    iconWrap.style.cssText = `
      width: 62px;
      height: 62px;
      margin: 0 auto 14px;
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, rgba(34, 197, 94, 0.12), rgba(34, 197, 94, 0.2));
      color: #166534;
      font-size: 2rem;
    `;
    iconWrap.innerHTML = '<i class="fa-solid ' + (type === 'success' ? 'fa-recycle' : 'fa-circle-check') + '" aria-hidden="true"></i>';

    const cardTitle = document.createElement('div');
    cardTitle.style.cssText = `
      font-size: 1.25rem;
      font-weight: 800;
      margin-bottom: 8px;
      color: #14532d;
    `;
    cardTitle.textContent = title;

    const desc = document.createElement('div');
    desc.style.cssText = `
      color: #475569;
      line-height: 1.6;
      margin-bottom: 12px;
      font-size: 0.96rem;
    `;
    desc.textContent = description;

    const detail = document.createElement('div');
    detail.style.cssText = `
      font-size: 1.1rem;
      font-weight: 800;
      color: #166534;
      margin-bottom: 18px;
    `;
    detail.textContent = detailText;

    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = buttonText;
    button.style.cssText = `
      border: none;
      border-radius: 10px;
      background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%);
      color: #ffffff;
      font-weight: 700;
      padding: 11px 18px;
      min-width: 120px;
      cursor: pointer;
      box-shadow: 0 10px 20px rgba(34, 197, 94, 0.2);
    `;

    button.addEventListener('click', function() {
      overlay.style.animation = 'ecopointPopupFadeOut 0.2s ease-in';
      setTimeout(() => overlay.remove(), 200);
    });

    card.appendChild(iconWrap);
    card.appendChild(cardTitle);
    card.appendChild(desc);
    card.appendChild(detail);
    card.appendChild(button);
    overlay.appendChild(card);
    document.body.appendChild(overlay);

    setTimeout(() => {
      if (overlay.parentNode) {
        overlay.style.animation = 'ecopointPopupFadeOut 0.25s ease-in';
        setTimeout(() => overlay.remove(), 250);
      }
    }, 4500);
  }

  // Add CSS animations
  function injectStyles() {
    if (document.getElementById('ecopoint-dashboard-styles')) return;
    
    const style = document.createElement('style');
    style.id = 'ecopoint-dashboard-styles';
    style.textContent = `
      @keyframes slideIn {
        from {
          transform: translateX(400px);
          opacity: 0;
        }
        to {
          transform: translateX(0);
          opacity: 1;
        }
      }
      @keyframes slideOut {
        from {
          transform: translateX(0);
          opacity: 1;
        }
        to {
          transform: translateX(400px);
          opacity: 0;
        }
      }
      @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; }
      }
      @keyframes ecopointPopupFadeIn {
        from {
          opacity: 0;
          transform: translateY(8px) scale(0.98);
        }
        to {
          opacity: 1;
          transform: translateY(0) scale(1);
        }
      }
      @keyframes ecopointPopupFadeOut {
        from {
          opacity: 1;
          transform: translateY(0) scale(1);
        }
        to {
          opacity: 0;
          transform: translateY(8px) scale(0.98);
        }
      }
      .ecopoint-pulse {
        animation: pulse 2s infinite;
      }
    `;
    document.head.appendChild(style);
  }

  // =====================================================================
  // UI Update Functions
  // =====================================================================
  function updateLiveSessionUI(snapshot) {
    const panel = getElement('ecopoint-live-panel');
    const statusEl = getElement('ecopoint-live-status');
    const messageEl = getElement('ecopoint-live-message');
    const startTimeEl = getElement('ecopoint-live-start-time');
    const materialEl = getElement('ecopoint-live-material');
    const weightEl = getElement('ecopoint-live-weight');
    const pointsEl = getElement('ecopoint-live-points');

    if (!snapshot || !snapshot.active_session) {
      if (panel) panel.classList.remove('is-active');
      if (statusEl) statusEl.textContent = 'No Active Session';
      if (messageEl) messageEl.textContent = 'Scan your VictorianPass QR at the VHEcoPoint Station to begin.';
      if (startTimeEl) startTimeEl.textContent = '—';
      if (materialEl) materialEl.textContent = '—';
      if (weightEl) weightEl.textContent = '0.00 kg';
      if (pointsEl) pointsEl.textContent = '0 pts';
      if (panel) panel.style.opacity = '0.8';
      return;
    }

    const session = snapshot.active_session;
    const sessionStatus = String(session.status || '').toUpperCase();
    const isActive = sessionStatus === 'ACTIVE';

    if (panel) {
      panel.classList.toggle('is-active', isActive);
      panel.style.opacity = '1';
    }

    if (statusEl) {
      statusEl.innerHTML = isActive
        ? '<span style="color:#22c55e"><i class="fa-solid fa-circle" aria-hidden="true"></i></span> Session Active'
        : getStatusLabel(session.status);
    }

    if (messageEl) {
      messageEl.textContent = isActive
        ? 'Your VHEcoPoint session is currently active.'
        : 'Your VHEcoPoint session is currently active.';
    }

    const startTime = session.started_at || session.startedAt || session.created_at || '—';
    const material = session.material || session.material_type || session.material_label || session.current_material || '—';
    const weight = parseFloat(session.total_weight_kg || session.weight_kg || 0).toFixed(2);
    const points = parseInt(session.total_points || session.points_awarded || 0);

    if (startTimeEl) startTimeEl.textContent = startTime === '—' ? '—' : formatSessionTime(startTime);
    if (materialEl) materialEl.textContent = material;
    if (weightEl) weightEl.textContent = weight + ' kg';
    if (pointsEl) pointsEl.textContent = points + ' pts';

    if (session.waste_items && session.waste_items.length > 0) {
      updateWasteItemsDisplay(session.waste_items);
    }
  }

  function getStatusLabel(status) {
    const statusMap = {
      'WAITING': '<i class="fa-solid fa-hourglass-half" aria-hidden="true"></i> Waiting to Start',
      'ACTIVE': '<span style="color:#22c55e"><i class="fa-solid fa-circle" aria-hidden="true"></i></span> Session Active',
      'PROCESSING': '<i class="fa-solid fa-gear" aria-hidden="true"></i> Processing',
      'COMPLETED': '<i class="fa-solid fa-circle-check" style="color:#16a34a" aria-hidden="true"></i> Completed',
      'CANCELLED': '<i class="fa-solid fa-circle-xmark" style="color:#dc2626" aria-hidden="true"></i> Cancelled',
      'ERROR': '<i class="fa-solid fa-triangle-exclamation" style="color:#d97706" aria-hidden="true"></i> Error',
    };
    return statusMap[String(status).toUpperCase()] || status || 'No Active Session';
  }

  function formatSessionTime(value) {
    if (!value || value === '—') return '—';
    const date = new Date(value);
    if (isNaN(date.getTime())) return value;
    return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  function updateWasteItemsDisplay(items) {
    // Create a detailed breakdown of waste items if desired
    let itemsHTML = '<div style="font-size: 12px; color: #6b7280; margin-top: 8px;">';
    items.forEach(item => {
      const material = item.material_label || item.waste_type || 'Unknown';
      const weight = parseFloat(item.weight_kg || 0).toFixed(2);
      const rate = parseInt(item.rate_pts_per_kg || 0);
      const points = parseInt(item.points_awarded || 0);
      itemsHTML += `<div style="margin-bottom: 4px;">• ${material}: ${weight}kg @ ${rate}pts/kg = ${points}pts</div>`;
    });
    itemsHTML += '</div>';

    const detailsEl = getElement('ecopoint-live-details');
    if (detailsEl) {
      setHTML('ecopoint-live-details', itemsHTML);
    }
  }

  function updateCurrentBalance(balance) {
    const balanceEl = getElement('ecopoint-current-balance');
    if (balanceEl) {
      setText('ecopoint-current-balance', number_format(balance) + ' pts');
    }
  }

  function updateCapState(capState) {
    if (!capState) return;

    const dailyRemaining = parseInt(capState.daily_points_left || 0);
    const weeklyRemaining = parseInt(capState.weekly_points_left || 0);
    const dailySessions = parseInt(capState.daily_sessions_left || 0);

    // Update cap indicators
    const dailyEl = getElement('ecopoint-daily-remaining');
    if (dailyEl) {
      setText('ecopoint-daily-remaining', dailyRemaining + ' pts');
    }

    const weeklyEl = getElement('ecopoint-weekly-remaining');
    if (weeklyEl) {
      setText('ecopoint-weekly-remaining', weeklyRemaining + ' pts');
    }

    const sessionsEl = getElement('ecopoint-sessions-remaining');
    if (sessionsEl) {
      setText('ecopoint-sessions-remaining', dailySessions + ' sessions');
    }
  }

  // Simple number formatter
  function number_format(num) {
    return parseInt(num).toLocaleString();
  }

  // =====================================================================
  // Change Detection & Notifications
  // =====================================================================
  function detectAndNotifyChanges(newSnapshot) {
    if (!newSnapshot) return;

    const newSession = newSnapshot.active_session;
    const prevSession = previousSession;

    // Ignore the first snapshot so a resident doesn't get a false verification popup when the page loads
    // with an existing active session already in the database.
    if (!hasRenderedInitialState) {
      hasRenderedInitialState = true;
      previousSession = newSession ? JSON.parse(JSON.stringify(newSession)) : null;
      return;
    }

    // No previous session → new verification (first scan)
    if (!prevSession && newSession) {
      showSessionPopup(
        'success',
        'VHEcoPoint Successfully Verified',
        'Your VictorianPass QR has been verified successfully.',
        'Your recycling session is now active. You may begin depositing recyclables.',
        'Continue'
      );
      log('Session verified', newSession);
    }
    // Had a real session that ended → notify exactly once per session id
    else if (prevSession && !newSession) {
      if (notifiedEndedSessionId === String(prevSession.id || '')) {
        previousSession = null;
        return;
      }
      notifiedEndedSessionId = String(prevSession.id || '');
      const pointsAwarded = Math.max(0, parseInt((prevSession && prevSession.total_points) || (prevSession && prevSession.points_awarded) || 0));
      showSessionPopup(
        'success',
        'VHEcoPoint Session Completed',
        'Your recycling activity has been recorded successfully.',
        '+' + pointsAwarded + ' EcoPoints',
        'Got it'
      );
      log('Session completed and removed from active');
    }
    // Session status changed
    else if (newSession && prevSession && newSession.status !== prevSession.status) {
      const statusMap = {
        'ACTIVE': { icon: 'fa-solid fa-circle', color: '#22c55e', text: 'Session is now active' },
        'PROCESSING': { icon: 'fa-solid fa-gear', text: 'Processing waste data' },
        'COMPLETED': { icon: 'fa-solid fa-circle-check', color: '#16a34a', text: 'Session completed successfully' },
        'CANCELLED': { icon: 'fa-solid fa-circle-xmark', color: '#dc2626', text: 'Session was cancelled' },
        'ERROR': { icon: 'fa-solid fa-triangle-exclamation', color: '#d97706', text: 'An error occurred during processing' },
      };
      const info = statusMap[String(newSession.status).toUpperCase()] || { icon: 'fa-solid fa-circle-info', text: 'Status changed' };
      showNotification('info', 'Status Update', info.text, info.icon, info.color);
      log('Session status changed', { from: prevSession.status, to: newSession.status });
    }
    // Weight updated significantly
    else if (newSession && prevSession) {
      const newWeight = parseFloat(newSession.total_weight_kg || newSession.weight_kg || 0);
      const prevWeight = parseFloat(prevSession.total_weight_kg || prevSession.weight_kg || 0);
      const newPoints = parseInt(newSession.total_points || newSession.points_awarded || 0);
      const prevPoints = parseInt(prevSession.total_points || prevSession.points_awarded || 0);

      if (newWeight > prevWeight + 0.05 || newPoints > prevPoints) {
        log('Waste detected', { weight: newWeight, points: newPoints });
        // Optional: show subtle toast for weight update
        // showNotification('info', '📦 Waste Detected', `${newWeight}kg detected (${newPoints}pts)`);
      }
    }

    previousSession = newSession ? JSON.parse(JSON.stringify(newSession)) : null;
  }

  // =====================================================================
  // SSE Connection Management
  // =====================================================================
  function connectSSE() {
    if (isConnecting || eventSource) return;
    isConnecting = true;

    log('Connecting to SSE endpoint...');

    try {
      eventSource = new EventSource(CONFIG.SSE_ENDPOINT, { withCredentials: true });

      eventSource.addEventListener('snapshot', function(event) {
        try {
          const snapshot = JSON.parse(event.data);
          lastSnapshot = snapshot;

          log('SSE snapshot received', {
            ts: snapshot.ts,
            hasActiveSession: !!snapshot.active_session,
            balance: snapshot.current_balance,
          });

          // Detect changes and notify
          detectAndNotifyChanges(snapshot);

          // Throttle UI updates
          if (uiUpdateTimeout) {
            clearTimeout(uiUpdateTimeout);
          }
          uiUpdateTimeout = setTimeout(() => {
            updateLiveSessionUI(snapshot);
            updateCurrentBalance(snapshot.current_balance);
            updateCapState(snapshot.cap_state);
          }, CONFIG.UI_UPDATE_THROTTLE_MS);

          reconnectAttempts = 0; // Reset on successful message
        } catch (e) {
          log('Error parsing SSE snapshot', e);
        }
      });

      eventSource.addEventListener('error', function(event) {
        log('SSE error event', event.type);
        closeSSE();
        scheduleReconnect();
      });

      eventSource.onerror = function(event) {
        log('EventSource onerror', event.type);
        closeSSE();
        scheduleReconnect();
      };

      isConnecting = false;
      log('SSE connection established');
    } catch (e) {
      log('Error creating EventSource', e);
      isConnecting = false;
      scheduleReconnect();
    }
  }

  function closeSSE() {
    if (eventSource) {
      eventSource.close();
      eventSource = null;
      log('SSE connection closed');
    }
    if (pollInterval) {
      clearInterval(pollInterval);
      pollInterval = null;
    }
  }

  function scheduleReconnect() {
    if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
      log('Max reconnect attempts reached, switching to polling');
      startPolling();
      return;
    }

    reconnectAttempts++;
    const delayMs = RECONNECT_DELAY_MS * Math.pow(1.5, reconnectAttempts - 1); // exponential backoff
    log(`Scheduling reconnect in ${Math.round(delayMs)}ms (attempt ${reconnectAttempts})`);

    setTimeout(() => {
      connectSSE();
    }, delayMs);
  }

  // =====================================================================
  // Fallback Polling (if SSE fails)
  // =====================================================================
  function startPolling() {
    if (pollInterval) return;

    log('Starting fallback polling...');
    pollInterval = setInterval(() => {
      fetch(CONFIG.POLL_ENDPOINT, { credentials: 'include' })
        .then(resp => {
          if (!resp.ok) throw new Error('Poll response not OK');
          return resp.json();
        })
        .then(data => {
          if (data && data.success) {
            // Simulate snapshot structure
            const snapshot = {
              success: true,
              active_session: data.active_session,
              current_balance: data.current_balance,
              cap_state: data.cap_state,
              ts: Date.now() / 1000,
              polled_at: new Date().toISOString(),
            };
            lastSnapshot = snapshot;

            detectAndNotifyChanges(snapshot);

            if (uiUpdateTimeout) {
              clearTimeout(uiUpdateTimeout);
            }
            uiUpdateTimeout = setTimeout(() => {
              updateLiveSessionUI(snapshot);
              updateCurrentBalance(snapshot.current_balance);
              updateCapState(snapshot.cap_state);
            }, CONFIG.UI_UPDATE_THROTTLE_MS);
          }
        })
        .catch(e => {
          log('Poll error', e);
        });
    }, CONFIG.POLL_INTERVAL_MS);
  }

  // =====================================================================
  // Initialization
  // =====================================================================
  function init() {
    log('Initializing VHEcoPoint Dashboard monitoring...');

    // Inject CSS animations
    injectStyles();

    // Check if we're on the ecopoint section
    const ecoPanel = getElement('ecopoint-live-panel');
    if (!ecoPanel) {
      log('EcoPoint live panel not found on this page');
      return;
    }

    // Start SSE connection
    connectSSE();

    // Handle page visibility (pause SSE when tab is hidden, resume when visible)
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        log('Page hidden, closing SSE');
        closeSSE();
      } else {
        log('Page visible, reopening SSE');
        connectSSE();
      }
    });

    // Cleanup on unload
    window.addEventListener('beforeunload', () => {
      closeSSE();
    });

    log('Initialization complete');
  }

  // =====================================================================
  // Start on DOM ready
  // =====================================================================
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Export for testing
  window.EcoPontDashboard = {
    log,
    showNotification,
    closeSSE,
    connectSSE,
  };
})();
