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
  var cacheBuilt = false;

  function buildUserIdCache(callback) {
    if (cacheBuilt) { if (callback) callback(); return; }

    fetch('/api/approved_grouped.php', { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (Array.isArray(data)) {
          data.forEach(function(group) {
            if (group.user_email && group.user_id) {
              userIdCache[group.user_email.toLowerCase()] = group.user_id;
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
              userIdCache[u.email.toLowerCase()] = u.id;
            }
          });
        }
        cacheBuilt = true;
        if (callback) callback();
      })
      .catch(function() {
        fetch('/api/users.php', { credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(data) {
            var users = data.users || data;
            if (Array.isArray(users)) {
              users.forEach(function(u) {
                if (u.email && u.id) {
                  userIdCache[u.email.toLowerCase()] = u.id;
                }
              });
            }
            cacheBuilt = true;
            if (callback) callback();
          })
          .catch(function() { if (callback) callback(); });
      });
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

  // === MutationObserver ===
  var debounceTimer = null;
  var observer = new MutationObserver(function() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function() {
      injectBalanceBadge();
      injectLoginButtons();
      injectWalletEdit();
    }, 300);
  });

  function init() {
    observer.observe(document.body, { childList: true, subtree: true });
    buildUserIdCache();
    injectBalanceBadge();
    injectLoginButtons();
    injectWalletEdit();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
