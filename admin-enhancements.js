(function() {
  'use strict';

  // === CSS Fix: Allow dropdowns in tables to overflow visibly ===
  var style = document.createElement('style');
  style.textContent = '.overflow-x-auto { overflow: visible !important; }';
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
  var netByEmail = {};
  var paymentsByEmail = {};
  var adminPaidByEmail = {};
  var cacheBuilt = false;
  var cacheBuilding = false;
  var cacheCallbacks = [];

  function buildUserIdCache(callback) {
    if (cacheBuilt) { if (callback) callback(); return; }
    if (callback) cacheCallbacks.push(callback);
    if (cacheBuilding) return;
    cacheBuilding = true;

    function done() {
      cacheBuilt = true;
      cacheBuilding = false;
      var cbs = cacheCallbacks; cacheCallbacks = [];
      cbs.forEach(function(cb) { try { cb(); } catch (e) {} });
    }

    fetch('/api/approved_grouped.php', { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var groups = (data && data.users) ? data.users : data;
        if (Array.isArray(groups)) {
          groups.forEach(function(group) {
            if (group.user_email) {
              var key = group.user_email.toLowerCase();
              if (group.user_id) userIdCache[key] = group.user_id;
              if (group.wallet_qr_url) walletQrByEmail[key] = group.wallet_qr_url;
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
                }
              });
            }
            done();
          })
          .catch(function() { done(); });
      });
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
  function showQrModal(url, netText) {
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
      if (card.querySelector('.wallet-qr-view')) return;

      // Only inject when the card is expanded (details panel present)
      var panel = card.querySelector('.border-t.border-gray-700');
      if (!panel) return;

      var email = getCardEmail(card);
      if (!email) return;

      var qr = walletQrByEmail[email.toLowerCase()];
      var box = document.createElement('div');
      box.className = 'wallet-qr-view';
      box.style.cssText = 'margin:0 16px 14px; padding:12px 0; border-bottom:1px solid #374151;';
      if (qr) {
        var full = buildWalletQrUrl(qr);
        var label = document.createElement('div');
        label.style.cssText = 'font-size:12px; color:#9ca3af; margin-bottom:6px; font-weight:600;';
        label.textContent = 'Wallet QR (uploaded by user)';
        var img = document.createElement('img');
        img.src = full;
        img.alt = 'Wallet QR';
        img.style.cssText = 'max-width:160px; max-height:160px; border-radius:8px; border:1px solid #374151; background:#fff; padding:4px; display:block; cursor:zoom-in;';
        img.onclick = (function(cardEl, emailKey) {
          return function(e) {
            e.stopPropagation();
            e.preventDefault();
            showQrModal(full, extractNetPayout(cardEl));
          };
        })(card, email.toLowerCase());
        box.appendChild(label);
        box.appendChild(img);
      } else {
        box.innerHTML = '<div style="font-size:12px; color:#6b7280;">No wallet QR photo uploaded by this user.</div>';
      }
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

  // Mark ALL of a user's approved claims as paid manually (settlement, no CoinEx).
  function markPaidTrust(email, btn) {
    var key = email.toLowerCase();
    var pays = paymentsByEmail[key] || [];
    var paymentIds = pays.map(function(p) { return p.id; });
    if (!paymentIds.length) { alert('No approved claims to settle for this user.'); return; }
    var userId = userIdCache[key];
    if (!userId) { alert('User not found: ' + email); return; }

    // Prior manual "TRUST" payouts already recorded for this user — consume them too.
    var priors = adminPaidByEmail[key] || [];
    var dedIds = priors.filter(function(l) {
      return /trust/i.test(String(l.coinex_withdraw_id || '')) && !l.payout_group_id;
    }).map(function(l) { return l.id; });

    var msg = 'Mark ' + paymentIds.length + ' approved claim(s) as PAID (manual, description "TRUST") for ' + email + '?';
    if (dedIds.length) msg += '\n\nThis will also clear ' + dedIds.length + ' prior TRUST payout record(s).';
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
      if (panel.querySelector('.claim-revert-box')) return;

      var email = getCardEmail(card);
      if (!email) return;
      var pays = paymentsByEmail[email.toLowerCase()];
      if (!pays || !pays.length) return;

      var box = document.createElement('div');
      box.className = 'claim-revert-box';
      box.style.cssText = 'margin:0 16px 14px; padding:12px 0; border-bottom:1px solid #374151;';

      var header = document.createElement('div');
      header.style.cssText = 'display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px;';
      var label = document.createElement('div');
      label.style.cssText = 'font-size:12px; color:#9ca3af; font-weight:600;';
      label.textContent = 'Approved claims';

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
        var info = document.createElement('div');
        info.style.cssText = 'font-size:12px; color:#d1d5db; min-width:0;';
        var txPart = p.matched_tx_id ? (' · tx ' + p.matched_tx_id) : '';
        info.textContent = '#' + p.id + ' · ' + (p.payment_method || '') + ' · ' + Number(amt).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' ' + cur + txPart;

        var rbtn = document.createElement('button');
        rbtn.className = 'claim-revert-btn';
        rbtn.textContent = 'Revert';
        rbtn.style.cssText = 'flex-shrink:0; background:rgba(239,68,68,0.15); color:#f87171; border:1px solid rgba(239,68,68,0.35); padding:4px 12px; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer;';
        rbtn.onmouseover = function() { this.style.background = 'rgba(239,68,68,0.3)'; };
        rbtn.onmouseout = function() { this.style.background = 'rgba(239,68,68,0.15)'; };
        rbtn.onclick = (function(pid, b) {
          return function(e) { e.stopPropagation(); e.preventDefault(); revertClaim(pid, b); };
        })(p.id, rbtn);

        row.appendChild(info);
        row.appendChild(rbtn);
        box.appendChild(row);
      });

      panel.insertBefore(box, panel.firstChild);
    });
  }

  // === MutationObserver ===
  var debounceTimer = null;
  var observer = new MutationObserver(function() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function() {
      injectBalanceBadge();
      injectLoginButtons();
      injectWalletEdit();
      injectWalletQr();
      injectRevertButtons();
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
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
