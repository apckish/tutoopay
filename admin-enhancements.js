(function() {
  'use strict';

  // === CSS Fix: Allow dropdowns in tables to overflow visibly ===
  var style = document.createElement('style');
  style.textContent = '.max-w-7xl { max-width: 1920px !important; }' +
    ' .overflow-x-auto { overflow-x: auto !important; max-width: 100%; }' +
    ' .overflow-x-auto:has(.absolute) { overflow: visible !important; }';
  document.head.appendChild(style);

  // === Intercept fetch to refresh balance after payments ===
  var originalFetch = window.fetch;
  var paymentEndpoints = ['/api/payout.php', '/api/multi_payout.php', '/api/manual_paid.php', '/api/direct_payout.php'];
  window.fetch = function() {
    var url = arguments[0];
    var urlStr = typeof url === 'string' ? url : (url && url.url ? url.url : '');
    var isPayment = paymentEndpoints.some(function(ep) { return urlStr.indexOf(ep) !== -1; });

    var result = originalFetch.apply(this, arguments);
    if (isPayment) {
      result.then(function(response) {
        // Clone to avoid consuming the body
        var cloned = response.clone();
        cloned.json().then(function(data) {
          if (!data.detail && !data.error) {
            // Successful payment — refresh balance after a short delay
            setTimeout(fetchCoinexBalance, 3000);
            setTimeout(fetchCoinexBalance, 10000);
          }
        }).catch(function() {});
        return response;
      });
    }
    return result;
  };

  // === CoinEx Balance in Header ===
  var balanceEl = null;
  var balanceInterval = null;

  function fetchCoinexBalance() {
    fetch('/api/coinex_balance.php', { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data && data.data) {
          var usdt = null;
          for (var i = 0; i < data.data.length; i++) {
            if (data.data[i].ccy === 'USDT') {
              usdt = parseFloat(data.data[i].available).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
              break;
            }
          }
          if (usdt !== null && balanceEl) {
            balanceEl.querySelector('span').textContent = usdt + ' USDT';
          }
        }
      })
      .catch(function() {});
  }

  function injectBalanceBadge() {
    if (document.getElementById('coinex-balance-badge')) return;
    var allEls = document.querySelectorAll('a, button, span');
    var logoutEl = null;
    for (var i = 0; i < allEls.length; i++) {
      if (allEls[i].textContent.trim() === 'Logout') {
        logoutEl = allEls[i];
        break;
      }
    }
    if (!logoutEl) return;

    balanceEl = document.createElement('span');
    balanceEl.id = 'coinex-balance-badge';
    balanceEl.style.cssText = 'background: rgba(16,185,129,0.15); color: #34d399; padding: 4px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; border: 1px solid rgba(16,185,129,0.3); margin-right: 8px;';
    balanceEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg><span>Loading...</span>';

    logoutEl.parentElement.insertBefore(balanceEl, logoutEl);

    fetchCoinexBalance();
    if (!balanceInterval) {
      balanceInterval = setInterval(fetchCoinexBalance, 60000);
    }
  }

  // === User ID Cache ===
  var userIdCache = {};
  var walletQrByEmail = {};
  var walletsByEmail = {};
  var netByEmail = {};
  var paymentsByEmail = {};
  var adminPaidByEmail = {};
  var cacheBuilt = false;
  var cacheBuilding = false;
  var cacheCallbacks = [];
  var cacheTime = 0;
  var tickState = {};
  var approvedWasVisible = false;

  function pickWallets(o) {
    return {
      bep20: { addr: o.bep20_address || '', qr: o.bep20_qr_url || '', at: o.bep20_updated_at || '' },
      trc20: { addr: o.trc20_address || '', qr: o.trc20_qr_url || '', at: o.trc20_updated_at || '' }
    };
  }

  function walletAgeText(at) {
    if (!at) return 'upload time unknown';
    var d = new Date(String(at).replace(' ', 'T'));
    if (isNaN(d.getTime())) return 'uploaded ' + at;
    var days = Math.floor((Date.now() - d.getTime()) / 86400000);
    var rel = days <= 0 ? 'today' : (days === 1 ? '1 day ago' : days + ' days ago');
    return 'uploaded ' + String(at).slice(0, 16) + ' (' + rel + ')';
  }

  function buildUserIdCache(callback) {
    if (cacheBuilt) { if (callback) callback(); return; }
    if (callback) cacheCallbacks.push(callback);
    if (cacheBuilding) return;
    cacheBuilding = true;
    var loadedOk = false;

    function done() {
      cacheBuilt = true;
      cacheTime = Date.now();
      cacheBuilding = false;
      var cbs = cacheCallbacks; cacheCallbacks = [];
      cbs.forEach(function(cb) { try { cb(); } catch (e) {} });
    }

    fetch('/api/approved_grouped.php', { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var groups = (data && data.users) ? data.users : data;
        if (Array.isArray(groups)) {
          loadedOk = true;
          walletQrByEmail = {}; walletsByEmail = {}; netByEmail = {}; paymentsByEmail = {}; adminPaidByEmail = {};
          groups.forEach(function(group) {
            if (group.user_email) {
              var key = group.user_email.toLowerCase();
              if (group.user_id) userIdCache[key] = group.user_id;
              if (group.wallet_qr_url) walletQrByEmail[key] = group.wallet_qr_url;
              walletsByEmail[key] = pickWallets(group);
              if (group.net_usd !== undefined && group.net_usd !== null) netByEmail[key] = group.net_usd;
              paymentsByEmail[key] = Array.isArray(group.payments) ? group.payments : [];
              adminPaidByEmail[key] = Array.isArray(group.admin_paid) ? group.admin_paid : [];
            }
          });
        }
        return fetch('/api/users.php', { credentials: 'same-origin' });
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var users = data.users || data;
        if (Array.isArray(users)) {
          users.forEach(function(u) {
            if (u.email && u.id) {
              var key = u.email.toLowerCase();
              userIdCache[key] = u.id;
              if (u.wallet_qr_url && !walletQrByEmail[key]) walletQrByEmail[key] = u.wallet_qr_url;
              if (!walletsByEmail[key]) walletsByEmail[key] = pickWallets(u);
            }
          });
        }
        done();
      })
      .catch(function() {
        fetch('/api/users.php', { credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(data) {
            var users = data.users || data;
            if (Array.isArray(users)) {
              users.forEach(function(u) {
                if (u.email && u.id) {
                  var key = u.email.toLowerCase();
                  userIdCache[key] = u.id;
                  if (u.wallet_qr_url && !walletQrByEmail[key]) walletQrByEmail[key] = u.wallet_qr_url;
              if (!walletsByEmail[key]) walletsByEmail[key] = pickWallets(u);
                }
              });
            }
            done();
          })
          .catch(function() { done(); });
      });
  }

  // Rebuild the cache when an expanded card belongs to a user the cache does not know yet.
  function rebuildIfMissing(key) {
    if (paymentsByEmail[key]) return false;
    if (cacheBuilding || Date.now() - cacheTime < 5000) return false;
    cacheBuilt = false;
    buildUserIdCache(function() { injectWalletQr(); injectRevertButtons(); });
    return true;
  }

  function findApprovedSection() {
    var h2s = document.querySelectorAll('h2');
    for (var i = 0; i < h2s.length; i++) {
      if (h2s[i].textContent.includes('Approved Payments')) {
        return h2s[i].closest('.space-y-6') || h2s[i].parentElement;
      }
    }
    return null;
  }

  // === Build a public URL for an uploaded wallet QR photo ===
  function buildWalletQrUrl(p) {
    if (!p) return '';
    if (/^https?:\/\//i.test(p)) return p;
    return 'https://upload.tutoopay.com/' + String(p).replace(/^\/+/, '');
  }

  // === Find the "Net Payout" amount shown in a user's expanded card ===
  function extractNetPayout(card) {
    var els = card.querySelectorAll('div, span, p, td, strong, b');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (el.children.length === 0 && /^\s*net payout\s*$/i.test(el.textContent || '')) {
        var row = el.parentElement;
        if (!row) continue;
        // Read the amount from a sibling cell in the same row
        var kids = row.children;
        for (var j = 0; j < kids.length; j++) {
          if (kids[j] === el) continue;
          var t = kids[j].textContent || '';
          var m = t.match(/-?\$\s?[\d,]+(?:\.\d+)?/);
          if (m) return m[0].replace(/\s/g, '') + (/USDT/i.test(t) ? ' USDT' : '');
        }
        // Fallback: last money token in the row text
        var all = (row.textContent || '').match(/-?\$\s?[\d,]+(?:\.\d+)?/g);
        if (all && all.length) {
          return all[all.length - 1].replace(/\s/g, '') + (/USDT/i.test(row.textContent) ? ' USDT' : '');
        }
      }
    }
    return '';
  }

  // === Fullscreen modal for the wallet QR image ===
  function showQrModal(url, netText, email) {
    var existing = document.getElementById('wallet-qr-modal');
    if (existing && existing.parentNode) existing.parentNode.removeChild(existing);

    var overlay = document.createElement('div');
    overlay.id = 'wallet-qr-modal';
    overlay.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.8); display:flex; align-items:center; justify-content:center; z-index:99999; padding:20px;';

    var inner = document.createElement('div');
    inner.style.cssText = 'position:relative; background:#fff; padding:14px; border-radius:12px; max-width:92vw; max-height:92vh;';

    var img = document.createElement('img');
    img.src = url;
    img.alt = 'Wallet QR';
    img.style.cssText = 'display:block; max-width:86vw; max-height:80vh; border-radius:6px;';

    var closeBtn = document.createElement('button');
    closeBtn.textContent = '\u00d7';
    closeBtn.style.cssText = 'position:absolute; top:-14px; right:-14px; width:34px; height:34px; border-radius:50%; border:none; background:#111827; color:#fff; font-size:22px; line-height:1; cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,0.4);';

    function closeModal() {
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
      document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape') closeModal(); }

    closeBtn.onclick = function(e) { e.stopPropagation(); closeModal(); };
    overlay.onclick = function() { closeModal(); };
    inner.onclick = function(e) { e.stopPropagation(); };
    document.addEventListener('keydown', onKey);

    inner.appendChild(img);
    if (netText) {
      var netEl = document.createElement('div');
      netEl.style.cssText = 'margin-top:12px; text-align:center; color:#065f46; font-size:16px; font-weight:700;';
      netEl.textContent = 'Net Payout: ' + netText;
      inner.appendChild(netEl);
    }

    var amount = 0;
    if (netText) {
      var num = String(netText).replace(/[^0-9.\-]/g, '');
      amount = parseFloat(num);
      if (isNaN(amount)) amount = 0;
    }

    if (email && amount > 0) {
      var sendBtn = document.createElement('button');
      sendBtn.textContent = '\u2709 Send Email';
      sendBtn.style.cssText = 'display:block; margin:12px auto 0; padding:9px 18px; border:none; border-radius:8px; background:#059669; color:#fff; font-size:14px; font-weight:600; cursor:pointer;';
      sendBtn.onclick = function(e) {
        e.stopPropagation();
        if (!confirm('Send payment email to ' + email + ' for $' + amount.toFixed(2) + ' USDT?')) return;
        sendBtn.disabled = true;
        sendBtn.textContent = 'Sending...';
        fetch('/api/send_transfer_email.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: email, amount: amount })
        })
          .then(function(r) { return r.json(); })
          .then(function(d) {
            if (d && d.success) {
              sendBtn.textContent = '\u2714 Email sent';
              sendBtn.style.background = '#065f46';
            } else {
              sendBtn.disabled = false;
              sendBtn.textContent = '\u2709 Send Email';
              alert('Failed to send email: ' + ((d && (d.error || d.message)) || 'unknown error'));
            }
          })
          .catch(function(err) {
            sendBtn.disabled = false;
            sendBtn.textContent = '\u2709 Send Email';
            alert('Failed to send email: ' + err);
          });
      };
      inner.appendChild(sendBtn);
    }

    inner.appendChild(closeBtn);
    overlay.appendChild(inner);
    document.body.appendChild(overlay);
  }

  // === Helper: get user email from a card ===
  function getCardEmail(card) {
    var headerBtn = card.querySelector('button');
    if (!headerBtn) return '';
    var infoDiv = headerBtn.querySelector('.flex-1.min-w-0');
    if (!infoDiv) return '';
    var paragraphs = infoDiv.querySelectorAll('p');
    if (paragraphs.length < 2) return '';
    var emailText = paragraphs[1].textContent;
    var emailMatch = emailText.match(/[\w.+-]+@[\w.-]+\.\w+/);
    return emailMatch ? emailMatch[0] : '';
  }

  // === Login to User Account Button ===
  function injectLoginButtons() {
    var h2s = document.querySelectorAll('h2');
    var approvedSection = null;
    for (var i = 0; i < h2s.length; i++) {
      if (h2s[i].textContent.includes('Approved Payments')) {
        approvedSection = h2s[i].closest('.space-y-6') || h2s[i].parentElement;
        break;
      }
    }
    if (!approvedSection) return;

    var userCards = approvedSection.querySelectorAll('.border.border-gray-700.rounded-lg');

    userCards.forEach(function(card) {
      if (card.querySelector('.admin-login-btn')) return;

      var headerBtn = card.querySelector('button');
      if (!headerBtn) return;

      var infoDiv = headerBtn.querySelector('.flex-1.min-w-0');
      if (!infoDiv) return;

      var paragraphs = infoDiv.querySelectorAll('p');
      if (paragraphs.length < 2) return;

      var nameP = paragraphs[0];
      var emailP = paragraphs[1];
      var userEmail = getCardEmail(card);

      if (!userEmail) return;

      var loginBtn = document.createElement('button');
      loginBtn.className = 'admin-login-btn';
      loginBtn.style.cssText = 'background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); padding: 2px 10px; border-radius: 6px; font-size: 11px; font-weight: 500; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 4px; margin-left: 8px; transition: all 0.2s;';
      loginBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>Login';

      loginBtn.onmouseover = function() { this.style.background = 'rgba(59,130,246,0.3)'; };
      loginBtn.onmouseout = function() { this.style.background = 'rgba(59,130,246,0.15)'; };

      loginBtn.onclick = function(e) {
        e.stopPropagation();
        e.preventDefault();
        var doLogin = function() {
          var userId = userIdCache[userEmail.toLowerCase()];
          if (userId) {
            loginAsUser(userId);
          } else {
            alert('User not found: ' + userEmail);
          }
        };
        if (cacheBuilt) { doLogin(); } else { loginBtn.textContent = '...'; buildUserIdCache(doLogin); }
      };

      nameP.style.display = 'inline';
      nameP.parentElement.insertBefore(loginBtn, emailP);
    });
  }

  function loginAsUser(userId) {
    fetch('/api/admin_login_token.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ user_id: userId })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success && data.url) {
        window.open(data.url, '_blank');
      } else {
        alert('Login failed: ' + (data.detail || data.error || 'Unknown error'));
      }
    })
    .catch(function(err) { alert('Error: ' + err.message); });
  }

  // === Wallet Address Edit ===
  function injectWalletEdit() {
    var h2s = document.querySelectorAll('h2');
    var approvedSection = null;
    for (var i = 0; i < h2s.length; i++) {
      if (h2s[i].textContent.includes('Approved Payments')) {
        approvedSection = h2s[i].closest('.space-y-6') || h2s[i].parentElement;
        break;
      }
    }
    if (!approvedSection) return;

    // Find expanded card sections (border-t divs inside user cards)
    var expandedPanels = approvedSection.querySelectorAll('.border-t.border-gray-700');

    expandedPanels.forEach(function(panel) {
      if (panel.querySelector('.wallet-edit-btn')) return;

      // Find the wallet display div - it has font-mono and truncate classes
      var walletDiv = panel.querySelector('.font-mono.truncate');
      // Also check for "No wallet set" div (red background)
      if (!walletDiv) {
        var allDivs = panel.querySelectorAll('div');
        for (var i = 0; i < allDivs.length; i++) {
          if (allDivs[i].textContent.trim() === 'No wallet set') {
            walletDiv = allDivs[i];
            break;
          }
        }
      }
      if (!walletDiv) return;

      // Get the card container to find user email
      var card = panel.closest('.border.border-gray-700.rounded-lg');
      if (!card) return;

      var userEmail = getCardEmail(card);
      if (!userEmail) return;

      // Get current wallet address from the display
      var currentWallet = walletDiv.getAttribute('title') || '';
      if (!currentWallet || currentWallet === 'undefined') {
        var walletText = walletDiv.textContent.replace(/^[✓⚠]\s*/, '').trim();
        if (walletText === 'No wallet set') {
          currentWallet = '';
        } else {
          currentWallet = walletText;
        }
      }

      // Create wrapper for wallet display + edit button
      var wrapper = document.createElement('div');
      wrapper.style.cssText = 'display: flex; align-items: center; gap: 8px;';
      wrapper.className = 'wallet-edit-wrapper';

      // Move wallet div into wrapper
      walletDiv.parentElement.insertBefore(wrapper, walletDiv);
      walletDiv.style.flex = '1';
      walletDiv.style.minWidth = '0';
      wrapper.appendChild(walletDiv);

      // Create edit button
      var editBtn = document.createElement('button');
      editBtn.className = 'wallet-edit-btn';
      editBtn.style.cssText = 'background: rgba(234,179,8,0.15); color: #facc15; border: 1px solid rgba(234,179,8,0.3); padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 500; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 4px; transition: all 0.2s; flex-shrink: 0;';
      editBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit Wallet';
      editBtn.onmouseover = function() { this.style.background = 'rgba(234,179,8,0.3)'; };
      editBtn.onmouseout = function() { this.style.background = 'rgba(234,179,8,0.15)'; };

      editBtn.onclick = function(e) {
        e.stopPropagation();
        e.preventDefault();

        // Replace wrapper content with edit form
        var savedHTML = wrapper.innerHTML;

        wrapper.innerHTML = '';
        wrapper.style.flexDirection = 'column';
        wrapper.style.gap = '6px';

        var inputRow = document.createElement('div');
        inputRow.style.cssText = 'display: flex; gap: 6px; width: 100%;';

        var input = document.createElement('input');
        input.type = 'text';
        input.value = currentWallet;
        input.placeholder = 'Enter wallet address (TRC20)...';
        input.style.cssText = 'flex: 1; background: #1f2937; border: 1px solid #4b5563; border-radius: 6px; padding: 6px 10px; color: white; font-size: 13px; font-family: monospace; outline: none;';
        input.onfocus = function() { this.style.borderColor = '#3b82f6'; };
        input.onblur = function() { this.style.borderColor = '#4b5563'; };

        var saveBtn = document.createElement('button');
        saveBtn.style.cssText = 'background: #059669; color: white; border: none; padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; transition: all 0.2s;';
        saveBtn.textContent = 'Save';
        saveBtn.onmouseover = function() { this.style.background = '#047857'; };
        saveBtn.onmouseout = function() { this.style.background = '#059669'; };

        var cancelBtn = document.createElement('button');
        cancelBtn.style.cssText = 'background: #4b5563; color: white; border: none; padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer; white-space: nowrap; transition: all 0.2s;';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.onmouseover = function() { this.style.background = '#6b7280'; };
        cancelBtn.onmouseout = function() { this.style.background = '#4b5563'; };

        cancelBtn.onclick = function(ev) {
          ev.stopPropagation();
          ev.preventDefault();
          wrapper.style.flexDirection = '';
          wrapper.innerHTML = savedHTML;
        };

        saveBtn.onclick = function(ev) {
          ev.stopPropagation();
          ev.preventDefault();
          var newWallet = input.value.trim();

          var doSave = function() {
            var userId = userIdCache[userEmail.toLowerCase()];
            if (!userId) {
              alert('User not found: ' + userEmail);
              return;
            }

            saveBtn.textContent = 'Saving...';
            saveBtn.disabled = true;

            fetch('/api/users.php?user_id=' + userId, {
              method: 'PUT',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ wallet_address: newWallet })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
              if (data.message) {
                // Click the Refresh button to force React to re-fetch data with updated wallet
                var refreshBtn = null;
                var allBtns = document.querySelectorAll('button');
                for (var b = 0; b < allBtns.length; b++) {
                  if (allBtns[b].textContent.trim() === 'Refresh') {
                    refreshBtn = allBtns[b];
                    break;
                  }
                }
                if (refreshBtn) {
                  refreshBtn.click();
                }
              } else {
                alert('Save failed: ' + (data.detail || 'Unknown error'));
                saveBtn.textContent = 'Save';
                saveBtn.disabled = false;
              }
            })
            .catch(function(err) {
              alert('Error: ' + err.message);
              saveBtn.textContent = 'Save';
              saveBtn.disabled = false;
            });
          };

          if (cacheBuilt) { doSave(); } else { buildUserIdCache(doSave); }
        };

        inputRow.appendChild(input);
        inputRow.appendChild(saveBtn);
        inputRow.appendChild(cancelBtn);
        wrapper.appendChild(inputRow);

        input.focus();
      };

      wrapper.appendChild(editBtn);
    });
  }

  // === Wallet QR Photo (uploaded by user) ===
  function injectWalletQr() {
    var h2s = document.querySelectorAll('h2');
    var approvedSection = null;
    for (var i = 0; i < h2s.length; i++) {
      if (h2s[i].textContent.includes('Approved Payments')) {
        approvedSection = h2s[i].closest('.space-y-6') || h2s[i].parentElement;
        break;
      }
    }
    if (!approvedSection) return;

    if (!cacheBuilt) { buildUserIdCache(injectWalletQr); return; }

    var userCards = approvedSection.querySelectorAll('.border.border-gray-700.rounded-lg');
    userCards.forEach(function(card) {
      // Only inject when the card is expanded (details panel present)
      var panel = card.querySelector('.border-t.border-gray-700');
      if (!panel) return;

      var email = getCardEmail(card);
      if (!email) return;
      if (rebuildIfMissing(email.toLowerCase())) return;

      var qr = walletQrByEmail[email.toLowerCase()];
      var wl = walletsByEmail[email.toLowerCase()] || pickWallets({});
      var sig = JSON.stringify(wl) + '|' + (qr || '');
      var existing = card.querySelector('.wallet-qr-view');
      if (existing) {
        if (existing.getAttribute('data-qr') === sig) return;
        existing.parentNode.removeChild(existing);
      }
      var box = document.createElement('div');
      box.className = 'wallet-qr-view';
      box.setAttribute('data-qr', sig);
      box.style.cssText = 'margin:0 16px 14px; padding:12px 0; border-bottom:1px solid #374151; display:flex; flex-wrap:wrap; gap:18px;';
      var nets = [['bep20', 'BEP20 ($1)'], ['trc20', 'TRC20 ($5)']];
      if (!wl.bep20.qr && !wl.trc20.qr && qr) wl.bep20 = { addr: '', qr: qr, at: '' };
      nets.forEach(function(n) {
        var w = wl[n[0]];
        var col = document.createElement('div');
        col.style.cssText = 'min-width:170px; max-width:260px;';
        var label = document.createElement('div');
        label.style.cssText = 'font-size:12px; color:#9ca3af; margin-bottom:4px; font-weight:600;';
        label.textContent = 'Wallet QR ' + n[1];
        col.appendChild(label);
        if (w.qr) {
          var full = buildWalletQrUrl(w.qr);
          var meta = document.createElement('div');
          meta.style.cssText = 'font-size:11px; color:#d1d5db; margin-bottom:6px; word-break:break-all;';
          meta.textContent = (w.addr ? w.addr + ' \u00b7 ' : '') + walletAgeText(w.at);
          var img = document.createElement('img');
          img.src = full;
          img.alt = 'Wallet QR ' + n[0].toUpperCase();
          img.style.cssText = 'max-width:160px; max-height:160px; border-radius:8px; border:1px solid #374151; background:#fff; padding:4px; display:block; cursor:zoom-in;';
          img.onclick = (function(cardEl, emailKey, url) {
            return function(e) {
              e.stopPropagation();
              e.preventDefault();
              showQrModal(url, extractNetPayout(cardEl), emailKey);
            };
          })(card, email.toLowerCase(), full);
          col.appendChild(meta);
          col.appendChild(img);
        } else {
          var none = document.createElement('div');
          none.style.cssText = 'font-size:12px; color:#f87171;';
          none.textContent = 'Not uploaded yet';
          col.appendChild(none);
        }
        box.appendChild(col);
      });
      panel.insertBefore(box, panel.firstChild);
    });
  }

  // === Revert Claim buttons (revert an approved+unpaid claim by payment id) ===
  function revertClaim(paymentId, btn) {
    if (!confirm('Revert this approved claim back to Pending?\n\nThis frees any statement matched to it and removes it from Approved & Pay. It will NOT work if the payment was already paid.')) return;
    btn.disabled = true;
    var oldText = btn.textContent;
    btn.textContent = 'Reverting...';
    fetch('/api/revert_claim.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ payment_id: paymentId })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data && data.success) {
        cacheBuilt = false; // force cache rebuild on next pass
        var refreshBtn = null;
        var allBtns = document.querySelectorAll('button');
        for (var b = 0; b < allBtns.length; b++) {
          if (allBtns[b].textContent.trim() === 'Refresh') { refreshBtn = allBtns[b]; break; }
        }
        if (refreshBtn) refreshBtn.click();
      } else {
        alert('Revert failed: ' + (data.detail || data.error || 'Unknown error'));
        btn.disabled = false;
        btn.textContent = oldText;
      }
    })
    .catch(function(err) {
      alert('Error: ' + err.message);
      btn.disabled = false;
      btn.textContent = oldText;
    });
  }

  // Mark the TICKED approved claims as paid manually (settlement, no CoinEx).
  function markPaidTrust(email, btn) {
    var key = email.toLowerCase();
    var box = btn.closest ? btn.closest('.claim-revert-box') : null;
    var paymentIds = [];
    var labels = [];
    if (box) {
      var cbs = box.querySelectorAll('.trust-claim-cb');
      for (var i = 0; i < cbs.length; i++) {
        if (cbs[i].checked) {
          paymentIds.push(parseInt(cbs[i].getAttribute('data-pid'), 10));
          labels.push(cbs[i].getAttribute('data-label'));
        }
      }
    }
    if (!paymentIds.length) { alert('Tick at least one claim to mark paid.'); return; }
    var userId = userIdCache[key];
    if (!userId) { alert('User not found: ' + email); return; }

    // All ungrouped prior payouts (TRUST, CoinEx, Direct Pay) are absorbed into this settlement
    // so no leftover negative balance remains for the user.
    var priors = adminPaidByEmail[key] || [];
    var dedIds = priors.filter(function(l) {
      return !l.payout_group_id;
    }).map(function(l) { return l.id; });

    var msg = 'Mark ' + paymentIds.length + ' TICKED approved claim(s) as PAID (manual, description "TRUST") for ' + email + '?\n\n' + labels.join('\n');
    if (dedIds.length) msg += '\n\nThis will also clear ' + dedIds.length + ' prior payout record(s) so no negative balance remains.';
    msg += '\n\nThese claims will be considered paid and will no longer appear in Approved & Pay.';
    if (!confirm(msg)) return;

    btn.disabled = true;
    var oldText = btn.textContent;
    btn.textContent = 'Settling...';
    fetch('/api/multi_payout.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        user_id: userId,
        payment_ids: paymentIds,
        deduction_log_ids: dedIds,
        manual: true,
        description: 'TRUST',
        exchange_fee: 0
      })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data && data.success) {
        cacheBuilt = false;
        var allBtns = document.querySelectorAll('button');
        for (var b = 0; b < allBtns.length; b++) {
          if (allBtns[b].textContent.trim() === 'Refresh') { allBtns[b].click(); break; }
        }
      } else {
        alert('Settle failed: ' + (data.detail || data.error || 'Unknown error'));
        btn.disabled = false;
        btn.textContent = oldText;
      }
    })
    .catch(function(err) {
      alert('Error: ' + err.message);
      btn.disabled = false;
      btn.textContent = oldText;
    });
  }

  function injectRevertButtons() {
    var h2s = document.querySelectorAll('h2');
    var approvedSection = null;
    for (var i = 0; i < h2s.length; i++) {
      if (h2s[i].textContent.includes('Approved Payments')) {
        approvedSection = h2s[i].closest('.space-y-6') || h2s[i].parentElement;
        break;
      }
    }
    if (!approvedSection) return;
    if (!cacheBuilt) { buildUserIdCache(injectRevertButtons); return; }

    var userCards = approvedSection.querySelectorAll('.border.border-gray-700.rounded-lg');
    userCards.forEach(function(card) {
      var panel = card.querySelector('.border-t.border-gray-700');
      if (!panel) return;

      var email = getCardEmail(card);
      if (!email) return;
      if (rebuildIfMissing(email.toLowerCase())) return;
      var pays = paymentsByEmail[email.toLowerCase()];
      var sig = (pays || []).map(function(p) { return p.id; }).join(',');
      var existing = panel.querySelector('.claim-revert-box');
      if (existing) {
        if (existing.getAttribute('data-sig') === sig) return;
        existing.parentNode.removeChild(existing);
      }
      if (!pays || !pays.length) return;

      var box = document.createElement('div');
      box.className = 'claim-revert-box';
      box.setAttribute('data-sig', sig);
      box.style.cssText = 'margin:0 16px 14px; padding:12px 0; border-bottom:1px solid #374151;';

      var header = document.createElement('div');
      header.style.cssText = 'display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px;';
      var label = document.createElement('div');
      label.style.cssText = 'font-size:12px; color:#9ca3af; font-weight:600;';
      label.textContent = 'Approved claims (tick the ones to pay)';

      var payBtn = document.createElement('button');
      payBtn.className = 'mark-paid-trust-btn';
      payBtn.textContent = 'Mark Paid (TRUST)';
      payBtn.style.cssText = 'flex-shrink:0; background:rgba(16,185,129,0.15); color:#34d399; border:1px solid rgba(16,185,129,0.35); padding:5px 14px; border-radius:6px; font-size:12px; font-weight:700; cursor:pointer;';
      payBtn.onmouseover = function() { this.style.background = 'rgba(16,185,129,0.3)'; };
      payBtn.onmouseout = function() { this.style.background = 'rgba(16,185,129,0.15)'; };
      payBtn.onclick = (function(em, b) {
        return function(e) { e.stopPropagation(); e.preventDefault(); markPaidTrust(em, b); };
      })(email, payBtn);

      header.appendChild(label);
      header.appendChild(payBtn);
      box.appendChild(header);

      pays.forEach(function(p) {
        var row = document.createElement('div');
        row.style.cssText = 'display:flex; align-items:center; justify-content:space-between; gap:10px; padding:6px 0; border-top:1px solid #1f2937;';

        var amt = (p.approved_amount !== undefined ? p.approved_amount : p.amount);
        var cur = (p.approved_currency || p.currency || 'USD');

        var left = document.createElement('label');
        left.style.cssText = 'display:flex; align-items:center; gap:8px; min-width:0; cursor:pointer;';
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'trust-claim-cb';
        cb.checked = tickState[p.id] !== false;
        cb.setAttribute('data-pid', p.id);
        cb.setAttribute('data-label', '#' + p.id + ' ' + Number(amt).toFixed(2) + ' ' + cur);
        cb.onchange = (function(pid) {
          return function() { tickState[pid] = this.checked; };
        })(p.id);
        cb.style.cssText = 'width:15px; height:15px; flex-shrink:0; cursor:pointer;';
        var info = document.createElement('div');
        info.style.cssText = 'font-size:12px; color:#d1d5db; min-width:0;';
        var txPart = p.matched_tx_id ? (' · tx ' + p.matched_tx_id) : '';
        info.textContent = '#' + p.id + ' · ' + (p.payment_method || '') + ' · ' + Number(amt).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' ' + cur + txPart;
        left.appendChild(cb);
        left.appendChild(info);

        var rbtn = document.createElement('button');
        rbtn.className = 'claim-revert-btn';
        rbtn.textContent = 'Revert';
        rbtn.style.cssText = 'flex-shrink:0; background:rgba(239,68,68,0.15); color:#f87171; border:1px solid rgba(239,68,68,0.35); padding:4px 12px; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer;';
        rbtn.onmouseover = function() { this.style.background = 'rgba(239,68,68,0.3)'; };
        rbtn.onmouseout = function() { this.style.background = 'rgba(239,68,68,0.15)'; };
        rbtn.onclick = (function(pid, b) {
          return function(e) { e.stopPropagation(); e.preventDefault(); revertClaim(pid, b); };
        })(p.id, rbtn);

        row.appendChild(left);
        row.appendChild(rbtn);
        box.appendChild(row);
      });

      panel.insertBefore(box, panel.firstChild);
    });
  }

  // === Users tab: enable/disable teacher login ===
  var statusOverride = {};
  function injectStatusToggle() {
    var h2 = Array.prototype.find.call(document.querySelectorAll('h2'), function(h) { return /^Users \(\d+\)$/.test(h.textContent.trim()); });
    if (!h2) return;
    var table = h2.closest('div').parentElement.querySelector('table');
    if (!table) return;
    table.querySelectorAll('thead th').forEach(function(th) { if (th.style.padding !== '8px 6px') th.style.padding = '8px 6px'; });
    table.querySelectorAll('tbody > tr').forEach(function(tr) {
      var tds = tr.children;
      if (tds.length < 10) return;
      if (!tr.dataset.compact) {
        tr.dataset.compact = '1';
        Array.prototype.forEach.call(tds, function(td) { td.style.padding = '8px 6px'; });
        tds[2].style.cssText += 'max-width:190px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;';
      }
      if (tds[2].title !== tds[2].textContent) tds[2].title = tds[2].textContent;
      var wspan = tds[5].querySelector('span');
      if (wspan && !wspan.dataset.compact) {
        wspan.dataset.compact = '1';
        wspan.style.cssText += 'display:inline-block; max-width:130px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:middle;';
        wspan.title = wspan.textContent + ' (click to edit)';
      }
      var id = parseInt(tds[0].textContent.trim(), 10);
      if (!id) return;
      var badge = tds[8].querySelector('span');
      if (!badge) return;
      var status = statusOverride[id] || badge.textContent.trim();
      if (badge.textContent.trim() !== status) badge.textContent = status;
      var badgeCls = 'px-2 py-0.5 rounded text-xs font-medium ' + (status === 'active' ? 'bg-emerald-900/50 text-emerald-300' : 'bg-red-900/50 text-red-300');
      if (badge.className !== badgeCls) badge.className = badgeCls;
      var btn = tds[9].querySelector('.status-toggle-btn');
      if (!btn) {
        var holder = tds[9].querySelector('.flex') || tds[9];
        btn = document.createElement('button');
        btn.className = 'status-toggle-btn px-2 py-0.5 rounded text-xs font-medium transition';
        holder.appendChild(btn);
        btn.onclick = function(e) {
          e.stopPropagation();
          var cur = statusOverride[id] || tds[8].querySelector('span').textContent.trim();
          var next = cur === 'active' ? 'inactive' : 'active';
          var name = tds[1].textContent.trim();
          if (!confirm((next === 'inactive' ? 'Disable login for ' : 'Re-enable login for ') + name + ' (ID ' + id + ')?')) return;
          btn.disabled = true;
          fetch('/api/users.php?user_id=' + id, {
            method: 'PUT', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: next })
          }).then(function(r) { return r.json().then(function(d) { if (!r.ok) throw new Error(d.detail || d.error || 'Failed'); return d; }); })
            .then(function() { statusOverride[id] = next; injectStatusToggle(); injectUsersTools(); })
            .catch(function(err) { alert('Error: ' + err.message); })
            .finally(function() { btn.disabled = false; });
        };
      }
      var label = status === 'active' ? 'Disable' : 'Enable';
      if (btn.textContent !== label) {
        btn.textContent = label;
        btn.title = status === 'active' ? 'Block this teacher from logging in' : 'Allow this teacher to log in again';
        btn.style.cssText = status === 'active'
          ? 'background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3);'
          : 'background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3);';
      }
    });
  }

  // === Users tab: status counts, status filter, column sort ===
  var usersFilter = 'all';
  var usersSort = { col: -1, dir: 1 };
  function usersRowStatus(tds) {
    var id = parseInt(tds[0].textContent.trim(), 10);
    var b = tds[8].querySelector('span');
    return statusOverride[id] || (b ? b.textContent.trim() : '');
  }
  function usersSortKey(tds, col) {
    var t = (tds[col].querySelector('input') ? tds[col].querySelector('input').value : tds[col].textContent).trim();
    if (col === 0 || col === 7) return parseFloat(t) || 0;
    if (col === 8) return usersRowStatus(tds);
    return t.toLowerCase();
  }
  function injectUsersTools() {
    var h2 = Array.prototype.find.call(document.querySelectorAll('h2'), function(h) { return /^Users \(\d+\)$/.test(h.textContent.trim()); });
    if (!h2) return;
    var root = h2.closest('div').parentElement;
    var frame = root.querySelector('.overflow-x-auto');
    var table = frame && frame.querySelector('table');
    var tbody = table && table.querySelector('tbody');
    if (!tbody) return;

    var groups = [];
    Array.prototype.forEach.call(tbody.children, function(tr) {
      if (tr.children.length >= 10) groups.push({ main: tr, rows: [tr] });
      else if (groups.length) groups[groups.length - 1].rows.push(tr);
    });

    var nActive = 0, nInactive = 0;
    groups.forEach(function(g) {
      var st = usersRowStatus(g.main.children);
      if (st === 'active') nActive++; else nInactive++;
      var show = usersFilter === 'all' || (usersFilter === 'active' ? st === 'active' : st !== 'active');
      g.rows.forEach(function(r) { var d = show ? '' : 'none'; if (r.style.display !== d) r.style.display = d; });
    });

    if (usersSort.col >= 0) {
      var sorted = groups.slice().sort(function(a, b) {
        var ka = usersSortKey(a.main.children, usersSort.col), kb = usersSortKey(b.main.children, usersSort.col);
        return (ka < kb ? -1 : ka > kb ? 1 : 0) * usersSort.dir;
      });
      var flat = [];
      sorted.forEach(function(g) { flat = flat.concat(g.rows); });
      var inOrder = flat.every(function(r, i) { return tbody.children[i] === r; });
      if (!inOrder) flat.forEach(function(r) { tbody.appendChild(r); });
    }

    var bar = root.querySelector('.users-status-bar');
    if (!bar) {
      bar = document.createElement('div');
      bar.className = 'users-status-bar';
      bar.style.cssText = 'display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0 0 12px 0; font-size:13px;';
      frame.parentElement.insertBefore(bar, frame);
      bar.onclick = function(e) {
        var b = e.target.closest('button[data-f]');
        if (!b) return;
        usersFilter = b.getAttribute('data-f');
        bar.dataset.sig = '';
        injectUsersTools();
      };
    }
    var shown = usersFilter === 'all' ? groups.length : (usersFilter === 'active' ? nActive : nInactive);
    var sig = [usersFilter, groups.length, nActive, nInactive].join('|');
    if (bar.dataset.sig !== sig) {
      bar.dataset.sig = sig;
      var pill = function(f, label, n, color) {
        var on = usersFilter === f;
        return '<button data-f="' + f + '" style="padding:4px 12px; border-radius:999px; cursor:pointer; font-weight:600; border:1px solid ' + color + '; ' +
          (on ? 'background:' + color + '; color:#0b0f19;' : 'background:transparent; color:' + color + ';') + '">' + label + ' <b>' + n + '</b></button>';
      };
      bar.innerHTML = pill('all', 'All', groups.length, '#9ca3af') + pill('active', 'Active', nActive, '#34d399') + pill('inactive', 'Disabled', nInactive, '#f87171') +
        '<span style="color:#6b7280; margin-left:6px;">Showing ' + shown + ' · click a column header to sort</span>';
    }

    var sortable = { 0: 1, 1: 1, 2: 1, 3: 1, 4: 1, 5: 1, 6: 1, 7: 1, 8: 1 };
    table.querySelectorAll('thead th').forEach(function(th, i) {
      if (!sortable[i]) return;
      if (!th.dataset.sortable) {
        th.dataset.sortable = '1';
        th.style.cursor = 'pointer';
        th.style.userSelect = 'none';
        th.title = 'Click to sort';
        th.addEventListener('click', function() {
          if (usersSort.col === i) usersSort.dir = -usersSort.dir; else { usersSort.col = i; usersSort.dir = 1; }
          injectUsersTools();
        });
        var ind = document.createElement('span');
        ind.className = 'sort-ind';
        ind.style.cssText = 'margin-left:4px; color:#34d399;';
        th.appendChild(ind);
      }
      var ind2 = th.querySelector('.sort-ind');
      var txt = usersSort.col === i ? (usersSort.dir === 1 ? '▲' : '▼') : '';
      if (ind2 && ind2.textContent !== txt) ind2.textContent = txt;
    });
  }

  // === Calculations tab: approved+unpaid grouped by teacher, multi-select gross/net ===
  var calc = { on: false, data: null, sel: {}, ded: {}, net: {}, q: '', loading: false, err: '' };
  function calcEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function r2(n) { return Math.round(n * 100) / 100; }
  function usd(n) { return '$' + r2(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function calcDefaultNet(u) {
    if (u.bep20_address) return 'BEP20';
    if (u.trc20_address) return 'TRC20';
    return (u.user_wallet_address || '').indexOf('T') === 0 ? 'TRC20' : 'BEP20';
  }
  function calcUserTotals(u) {
    var gross = 0, n = 0, ded = 0;
    u.payments.forEach(function(p) { if (calc.sel[p.id]) { gross += Number(p.amount_usd) || 0; n++; } });
    (u.admin_paid || []).forEach(function(d) { if (calc.ded[d.id]) ded += Number(d.usdt_amount) || 0; });
    var comm = r2(gross * (Number(u.commission_pct) / 100));
    var fee = n ? ((calc.net[u.user_id] || calcDefaultNet(u)) === 'TRC20' ? 5 : 1) : 0;
    return { n: n, gross: r2(gross), comm: comm, fee: fee, ded: r2(ded), net: n ? Math.max(0, r2(gross - comm - fee - ded)) : 0 };
  }
  function calcLoad() {
    calc.loading = true; calc.err = ''; calcRender();
    fetch('/api/approved_grouped.php', { credentials: 'same-origin' })
      .then(function(r) { return r.json().then(function(d) { if (!r.ok) throw new Error(d.detail || d.error || 'Failed to load'); return d; }); })
      .then(function(d) { calc.data = (d.users || []).filter(function(u) { return u.payments && u.payments.length; }); })
      .catch(function(e) { calc.err = e.message; })
      .finally(function() { calc.loading = false; calcRender(); });
  }
  function calcSummary() {
    var t = { people: 0, n: 0, gross: 0, comm: 0, fee: 0, ded: 0, net: 0 };
    (calc.data || []).forEach(function(u) {
      var x = calcUserTotals(u);
      if (!x.n) return;
      t.people++; t.n += x.n; t.gross += x.gross; t.comm += x.comm; t.fee += x.fee; t.ded += x.ded; t.net += x.net;
    });
    return t;
  }
  function calcSummaryHtml() {
    var t = calcSummary();
    var cell = function(label, val, color, big) {
      return '<div style="min-width:120px"><div style="font-size:11px; color:#9ca3af; text-transform:uppercase; letter-spacing:.04em">' + label + '</div>' +
        '<div style="font-size:' + (big ? 26 : 20) + 'px; font-weight:700; color:' + color + '">' + val + '</div></div>';
    };
    return cell('Selected', t.n + ' payment' + (t.n === 1 ? '' : 's') + ' · ' + t.people + ' ' + (t.people === 1 ? 'person' : 'people'), '#e5e7eb') +
      cell('Gross', usd(t.gross), '#60a5fa', true) +
      cell('Commission', '−' + usd(t.comm), '#fbbf24') +
      cell('Transfer fees', '−' + usd(t.fee), '#fbbf24') +
      (t.ded ? cell('Already paid (deducted)', '−' + usd(t.ded), '#f87171') : '') +
      cell('Net payout', usd(t.net), '#34d399', true);
  }
  function calcRefreshNumbers(panel) {
    var s = panel.querySelector('.calc-summary');
    if (s) s.innerHTML = calcSummaryHtml();
    (calc.data || []).forEach(function(u) {
      var x = calcUserTotals(u);
      var el = panel.querySelector('.calc-sub[data-u="' + u.user_id + '"]');
      if (el) el.innerHTML = x.n ? (x.n + ' selected · gross <b style="color:#60a5fa">' + usd(x.gross) + '</b> · comm ' + Number(u.commission_pct) + '% −' + usd(x.comm) +
        ' · fee −' + usd(x.fee) + (x.ded ? ' · paid −' + usd(x.ded) : '') + ' · net <b style="color:#34d399">' + usd(x.net) + '</b>') : '<span style="color:#6b7280">nothing selected</span>';
      var all = panel.querySelector('input.calc-user[data-u="' + u.user_id + '"]');
      if (all) { all.checked = x.n === u.payments.length; all.indeterminate = x.n > 0 && x.n < u.payments.length; }
    });
  }
  function calcRender() {
    var panel = document.getElementById('calc-panel');
    if (!panel) return;
    var head = '<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:12px">' +
      '<h2 class="text-lg font-semibold">Calculations — approved, not paid' + (calc.data ? ' (' + calc.data.length + ' people)' : '') + '</h2>' +
      '<div style="display:flex; gap:8px; align-items:center">' +
      '<input class="calc-q" placeholder="Search name or email..." value="' + calcEsc(calc.q) + '" style="background:#1f2937; border:1px solid #374151; border-radius:8px; padding:6px 10px; color:#fff; font-size:13px; width:220px">' +
      '<button data-act="all" style="background:#065f46; color:#d1fae5; border-radius:8px; padding:6px 12px; font-size:13px">Select all</button>' +
      '<button data-act="none" style="background:#374151; color:#e5e7eb; border-radius:8px; padding:6px 12px; font-size:13px">Clear</button>' +
      '<button data-act="reload" style="background:#1d4ed8; color:#fff; border-radius:8px; padding:6px 12px; font-size:13px">Refresh</button></div></div>' +
      '<div class="calc-summary" style="position:sticky; top:0; z-index:5; display:flex; gap:24px; flex-wrap:wrap; background:#111827; border:1px solid #065f46; border-radius:12px; padding:14px 18px; margin-bottom:14px"></div>';
    if (calc.loading) { panel.innerHTML = head + '<p style="color:#9ca3af">Loading...</p>'; return; }
    if (calc.err) { panel.innerHTML = head + '<p style="color:#f87171">' + calcEsc(calc.err) + '</p>'; return; }
    var q = calc.q.trim().toLowerCase();
    var list = (calc.data || []).filter(function(u) { return !q || (u.user_name || '').toLowerCase().indexOf(q) >= 0 || (u.user_email || '').toLowerCase().indexOf(q) >= 0; });
    var body = list.map(function(u) {
      var netw = calc.net[u.user_id] || calcDefaultNet(u);
      var rows = u.payments.map(function(p) {
        return '<tr style="border-top:1px solid #1f2937">' +
          '<td style="padding:6px"><input type="checkbox" class="calc-p" data-u="' + u.user_id + '" data-id="' + p.id + '"' + (calc.sel[p.id] ? ' checked' : '') + '></td>' +
          '<td style="padding:6px; color:#9ca3af">#' + p.id + '</td>' +
          '<td style="padding:6px">' + calcEsc((p.date || '').slice(0, 10)) + '</td>' +
          '<td style="padding:6px">' + calcEsc(p.payment_method) + '</td>' +
          '<td style="padding:6px; color:#9ca3af">' + calcEsc(p.sender_name) + '</td>' +
          '<td style="padding:6px; text-align:right">' + calcEsc(Number(p.approved_amount).toFixed(2) + ' ' + (p.approved_currency || '')) + '</td>' +
          '<td style="padding:6px; text-align:right; color:#60a5fa; font-weight:600">' + usd(Number(p.amount_usd) || 0) + '</td></tr>';
      }).join('');
      var deds = (u.admin_paid || []).map(function(d) {
        return '<tr style="border-top:1px solid #1f2937; color:#fca5a5">' +
          '<td style="padding:6px"><input type="checkbox" class="calc-d" data-id="' + d.id + '"' + (calc.ded[d.id] ? ' checked' : '') + '></td>' +
          '<td style="padding:6px" colspan="5">Already paid (' + calcEsc(d.action) + ', ' + calcEsc((d.created_at || '').slice(0, 10)) + ') — tick to deduct</td>' +
          '<td style="padding:6px; text-align:right">−' + usd(Number(d.usdt_amount) || 0) + '</td></tr>';
      }).join('');
      return '<div style="background:#111827; border:1px solid #1f2937; border-radius:12px; padding:12px 14px; margin-bottom:10px">' +
        '<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap">' +
        '<input type="checkbox" class="calc-user" data-u="' + u.user_id + '" title="Select all payments of this person">' +
        '<b style="color:#fff">' + calcEsc(u.user_name) + '</b><span style="color:#6b7280; font-size:12px">' + calcEsc(u.user_email) + ' · ' + u.payments.length + ' payment' + (u.payments.length === 1 ? '' : 's') + ' · total ' + usd(Number(u.total_approved_usd) || 0) + '</span>' +
        '<select class="calc-net" data-u="' + u.user_id + '" style="margin-left:auto; background:#1f2937; color:#e5e7eb; border:1px solid #374151; border-radius:6px; font-size:12px; padding:2px 6px">' +
        '<option value="BEP20"' + (netw === 'BEP20' ? ' selected' : '') + '>BEP20 fee $1' + (u.bep20_address ? '' : ' (no wallet)') + '</option>' +
        '<option value="TRC20"' + (netw === 'TRC20' ? ' selected' : '') + '>TRC20 fee $5' + (u.trc20_address ? '' : ' (no wallet)') + '</option></select></div>' +
        '<table style="width:100%; font-size:12px; margin-top:8px"><tbody>' + rows + deds + '</tbody></table>' +
        '<div class="calc-sub" data-u="' + u.user_id + '" style="font-size:12px; color:#d1d5db; margin-top:6px; text-align:right"></div></div>';
    }).join('');
    panel.innerHTML = head + (list.length ? body : '<p style="color:#9ca3af">No approved unpaid payments.</p>');
    calcRefreshNumbers(panel);
  }
  function calcBind(panel) {
    panel.addEventListener('change', function(e) {
      var t = e.target;
      if (t.classList.contains('calc-p')) { if (t.checked) calc.sel[t.dataset.id] = 1; else delete calc.sel[t.dataset.id]; }
      else if (t.classList.contains('calc-d')) { if (t.checked) calc.ded[t.dataset.id] = 1; else delete calc.ded[t.dataset.id]; }
      else if (t.classList.contains('calc-net')) { calc.net[t.dataset.u] = t.value; }
      else if (t.classList.contains('calc-user')) {
        var u = (calc.data || []).find(function(x) { return String(x.user_id) === t.dataset.u; });
        if (u) u.payments.forEach(function(p) { if (t.checked) calc.sel[p.id] = 1; else delete calc.sel[p.id]; });
        panel.querySelectorAll('input.calc-p[data-u="' + t.dataset.u + '"]').forEach(function(cb) { cb.checked = t.checked; });
      } else return;
      calcRefreshNumbers(panel);
    });
    panel.addEventListener('input', function(e) {
      if (!e.target.classList.contains('calc-q')) return;
      calc.q = e.target.value;
      var pos = e.target.selectionStart;
      calcRender();
      var qi = panel.querySelector('.calc-q'); qi.focus(); qi.setSelectionRange(pos, pos);
    });
    panel.addEventListener('click', function(e) {
      var b = e.target.closest('button[data-act]');
      if (!b) return;
      var act = b.dataset.act;
      if (act === 'reload') { calc.sel = {}; calc.ded = {}; calcLoad(); return; }
      var q = calc.q.trim().toLowerCase();
      (calc.data || []).forEach(function(u) {
        if (q && (u.user_name || '').toLowerCase().indexOf(q) < 0 && (u.user_email || '').toLowerCase().indexOf(q) < 0) return;
        u.payments.forEach(function(p) { if (act === 'all') calc.sel[p.id] = 1; else delete calc.sel[p.id]; });
        if (act === 'none') (u.admin_paid || []).forEach(function(d) { delete calc.ded[d.id]; });
      });
      calcRender();
    });
  }
  var CALC_BTN_OFF = 'px-3 py-2 rounded-lg text-sm font-medium transition text-gray-400 hover:text-white hover:bg-gray-800';
  var CALC_BTN_ON = 'px-3 py-2 rounded-lg text-sm font-medium transition bg-emerald-600 text-white';
  function calcExit() {
    if (!calc.on) return;
    calc.on = false;
    var panel = document.getElementById('calc-panel');
    if (panel) panel.remove();
    var main = document.querySelector('main');
    if (main) Array.prototype.forEach.call(main.children, function(c) { if (c.dataset.calcHidden) { c.style.display = ''; delete c.dataset.calcHidden; } });
    document.querySelectorAll('header button[data-calc-cls]').forEach(function(b) { b.className = b.dataset.calcCls; delete b.dataset.calcCls; });
    var mine = document.querySelector('.calc-tab-btn');
    if (mine) mine.className = 'calc-tab-btn ' + CALC_BTN_OFF;
  }
  function calcEnter() {
    var main = document.querySelector('main');
    if (!main) return;
    calc.on = true;
    var mine = document.querySelector('.calc-tab-btn');
    mine.parentElement.querySelectorAll('button').forEach(function(b) {
      if (b === mine || b.textContent.trim() === 'Logout') return;
      if (!b.dataset.calcCls) b.dataset.calcCls = b.className;
      b.className = CALC_BTN_OFF;
    });
    mine.className = 'calc-tab-btn ' + CALC_BTN_ON;
    if (!document.getElementById('calc-panel')) {
      var panel = document.createElement('div');
      panel.id = 'calc-panel';
      main.appendChild(panel);
      calcBind(panel);
    }
    injectCalcTab();
    calcLoad();
  }
  function injectCalcTab() {
    var anchor = Array.prototype.find.call(document.querySelectorAll('header button'), function(b) { return b.textContent.trim() === 'Approved & Pay'; });
    if (!anchor) return;
    if (!document.querySelector('.calc-tab-btn')) {
      var btn = document.createElement('button');
      btn.className = 'calc-tab-btn ' + CALC_BTN_OFF;
      btn.textContent = 'Calculations';
      btn.onclick = function(e) { e.stopPropagation(); calcEnter(); };
      anchor.parentElement.insertBefore(btn, anchor.nextSibling);
      anchor.parentElement.addEventListener('click', function(e) {
        var b = e.target.closest('button');
        if (b && !b.classList.contains('calc-tab-btn')) calcExit();
      }, true);
    }
    if (calc.on) {
      var main = document.querySelector('main');
      if (main) Array.prototype.forEach.call(main.children, function(c) {
        if (c.id === 'calc-panel' || c.dataset.calcHidden) return;
        c.dataset.calcHidden = '1'; c.style.display = 'none';
      });
    }
  }

  // === MutationObserver ===
  var debounceTimer = null;
  var observer = new MutationObserver(function() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function() {
      var approvedVisible = !!findApprovedSection();
      if (approvedVisible && !approvedWasVisible && !cacheBuilding && Date.now() - cacheTime > 10000) {
        cacheBuilt = false;
      }
      approvedWasVisible = approvedVisible;
      injectBalanceBadge();
      injectLoginButtons();
      injectWalletEdit();
      injectWalletQr();
      injectRevertButtons();
      injectStatusToggle();
      injectUsersTools();
      injectCalcTab();
    }, 300);
  });

  function init() {
    observer.observe(document.body, { childList: true, subtree: true });
    buildUserIdCache();
    injectBalanceBadge();
    injectLoginButtons();
    injectWalletEdit();
    injectWalletQr();
    injectRevertButtons();
    injectStatusToggle();
    injectUsersTools();
    injectCalcTab();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
