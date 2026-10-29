/**
 * MoMo Manager — Mobile-First SPA Engine (iPhone 16 393px Viewport)
 * Clean uniform typography & subtle flat pastel card surfaces
 */
(function () {
  const cfg = window.APP_CONFIG || { baseUrl: '', csrfToken: '' };

  const state = {
    loading: true,
    authenticated: false,
    user: null,
    csrfToken: cfg.csrfToken || '',
    bearerToken: localStorage.getItem('momo_bearer_token') || '',
    demoAccounts: [],
    authMode: 'login', // 'login' | 'register'
    activeTab: 'home', // Vendor: 'home'|'history'|'shift' ; Boss: 'home'|'activity'|'branches'|'team'

    dashboard: null,
    transactions: [],
    txTotals: { count: 0, volume: 0, fees: 0 },
    txFilters: { branch_id: '', network: '', tx_type: '', status: '', q: '' },

    branchesList: [],
    floatAdjustments: [],
    shiftsList: [],
    vendorsList: [],

    sheet: null, // { type: string, data: any }
  };

  function esc(val) {
    return String(val ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function money(val) {
    const num = Number(val || 0);
    return 'GH₵ ' + num.toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function shortMoney(val) {
    const num = Number(val || 0);
    return '₵' + num.toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function netLabel(net) {
    if (net === 'MTN') return 'MTN MoMo';
    if (net === 'TELECEL') return 'Telecel Cash';
    if (net === 'AT') return 'AirtelTigo';
    return net || '';
  }

  function formatTime(dt) {
    if (!dt) return '';
    const parts = String(dt).split(' ');
    if (parts.length === 2) {
      return parts[1].slice(0, 5) + ' · ' + parts[0].slice(5);
    }
    return dt;
  }

  function brandLogoSvg(size = 22) {
    return `
      <svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none">
        <circle cx="6.5" cy="7.5" r="2.3" fill="#F7C818"/>
        <circle cx="12" cy="7.5" r="2.3" fill="#EA4335"/>
        <circle cx="17.5" cy="7.5" r="2.3" fill="#1A73E8"/>
        <path d="M4.5 18v-5l3.8 3.2L12 11.8l3.7 4.4L19.5 13v5" stroke="#202124" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>`;
  }

  function toast(message, isError = false) {
    const stack = document.getElementById('toast-stack');
    if (!stack) return;
    const el = document.createElement('div');
    el.className = 'toast' + (isError ? ' error' : '');
    el.textContent = message;
    stack.appendChild(el);
    setTimeout(() => {
      el.remove();
    }, 3600);
  }

  function apiUrl(path, queryObj) {
    const clean = path.startsWith('/') ? path : '/' + path;
    let url = (cfg.baseUrl || '') + clean;
    if (queryObj) {
      const sp = new URLSearchParams();
      Object.entries(queryObj).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') {
          sp.append(k, String(v));
        }
      });
      const qs = sp.toString();
      if (qs) url += '?' + qs;
    }
    return url;
  }

  async function api(method, path, body = null, query = null) {
    const headers = {
      Accept: 'application/json',
      'X-CSRF-Token': state.csrfToken,
    };
    if (state.bearerToken) {
      headers['Authorization'] = 'Bearer ' + state.bearerToken;
    }
    const opts = {
      method,
      headers,
      credentials: 'same-origin',
    };
    if (body !== null && method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    const res = await fetch(apiUrl(path, query), opts);
    let data = {};
    try {
      data = await res.json();
    } catch (_) {
      throw new Error('Unexpected server response (' + res.status + ')');
    }

    if (data.csrf_token) {
      state.csrfToken = data.csrf_token;
    }

    if (!res.ok || data.ok === false) {
      if (res.status === 401 && state.authenticated) {
        state.authenticated = false;
        state.user = null;
        state.bearerToken = '';
        localStorage.removeItem('momo_bearer_token');
        render();
      }
      throw new Error(data.message || 'Request failed');
    }

    return data;
  }

  function isBoss() {
    return state.user && (state.user.role === 'OWNER' || state.user.role === 'MANAGER');
  }

  async function boot() {
    updateClock();
    setInterval(updateClock, 15000);

    if (cfg.dbError) {
      state.loading = false;
      render();
      return;
    }

    try {
      const me = await api('GET', '/api/auth/me');
      state.authenticated = !!me.authenticated;
      state.user = me.user || null;
      state.demoAccounts = me.demo_accounts || [];
      if (state.authenticated) {
        await refreshCurrentView();
      }
    } catch (err) {
      toast(err.message, true);
    } finally {
      state.loading = false;
      render();
    }
  }

  function updateClock() {
    const clockEl = document.getElementById('ios-clock');
    if (!clockEl) return;
    const now = new Date();
    clockEl.textContent = now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
  }

  async function refreshCurrentView() {
    const dash = await api('GET', '/api/dashboard');
    state.dashboard = dash;

    if (state.activeTab === 'activity' || state.activeTab === 'history') {
      const txRes = await api('GET', '/api/transactions', null, state.txFilters);
      state.transactions = txRes.transactions || [];
      state.txTotals = txRes.totals || { count: 0, volume: 0, fees: 0 };
    } else if (state.activeTab === 'branches') {
      const [brRes, shRes] = await Promise.all([
        api('GET', '/api/branches'),
        api('GET', '/api/shifts'),
      ]);
      state.branchesList = brRes.branches || [];
      state.floatAdjustments = brRes.float_adjustments || [];
      state.shiftsList = shRes.shifts || [];
    } else if (state.activeTab === 'team') {
      const [vRes, brRes] = await Promise.all([
        api('GET', '/api/vendors'),
        api('GET', '/api/branches'),
      ]);
      state.vendorsList = vRes.vendors || [];
      state.branchesList = brRes.branches || [];
    } else if (state.activeTab === 'shift') {
      const shRes = await api('GET', '/api/shifts');
      state.shiftsList = shRes.shifts || [];
    }
  }

  async function switchTab(tab) {
    state.activeTab = tab;
    render();
    try {
      await refreshCurrentView();
      render();
    } catch (err) {
      toast(err.message, true);
    }
  }

  function openSheet(type, data = {}) {
    state.sheet = { type, data };
    renderSheet();
  }

  function closeSheet() {
    state.sheet = null;
    renderSheet();
  }

  /* =========================================================
     RENDER SHELL
     ========================================================= */
  function render() {
    const mount = document.getElementById('app-mount');
    if (!mount) return;

    if (cfg.dbError) {
      mount.innerHTML = renderDbError();
      return;
    }

    if (state.loading) {
      mount.innerHTML = `
        <div class="app-scroll" style="display:grid;place-items:center;">
          <div style="text-align:center;">
            <div style="font-weight:700;font-size:16px;color:var(--ink-primary);margin-bottom:4px;">MoMo Manager</div>
            <div style="font-size:13px;color:var(--ink-muted);">Loading workspace...</div>
          </div>
        </div>`;
      return;
    }

    if (!state.authenticated || !state.user) {
      mount.innerHTML = renderTopbarGuest() + `<div class="app-scroll">${renderAuthScreen()}</div>`;
      bindAuthEvents();
      renderSheet();
      return;
    }

    const bodyHtml = isBoss() ? renderBossView() : renderVendorView();
    const navHtml = isBoss() ? renderBossNav() : renderVendorNav();

    mount.innerHTML = `
      ${renderTopbarUser()}
      <div class="app-scroll" id="main-scroll">${bodyHtml}</div>
      ${navHtml}
    `;

    bindAppEvents();
    renderSheet();
  }

  function renderDbError() {
    return `
      ${renderTopbarGuest()}
      <div class="app-scroll">
        <div class="alert-card danger" style="flex-direction:column;align-items:stretch;gap:10px;margin-top:12px;">
          <div style="font-size:15px;font-weight:700;">MySQL Not Running in XAMPP</div>
          <div style="font-size:13px;color:var(--ink-secondary);">
            Open <strong>XAMPP Control Panel</strong> and click <strong>Start</strong> next to <strong>MySQL</strong>.
            MoMo Manager will automatically create the database and tables on your next refresh.
          </div>
          <div class="sub-surface" style="background:#ffffff;font-size:12px;color:var(--pastel-red-ink);">
            ${esc(cfg.dbError)}
          </div>
          <button class="btn btn-primary btn-block" onclick="window.location.reload()">Retry Connection</button>
        </div>
      </div>`;
  }

  function renderTopbarGuest() {
    return `
      <header class="app-topbar">
        <div class="brand-cluster">
          <div class="brand-dots">${brandLogoSvg(20)}</div>
          <div>
            <div class="brand-title">MoMo Manager</div>
            <div class="brand-subtitle">Multi-Branch Vendor Ledger</div>
          </div>
        </div>
      </header>`;
  }

  function renderTopbarUser() {
    const u = state.user;
    const roleTag = u.role === 'OWNER' ? 'Main Boss' : u.role === 'MANAGER' ? 'Manager' : (u.branch_name || 'Vendor');
    return `
      <header class="app-topbar">
        <div class="brand-cluster">
          <div class="brand-dots">${brandLogoSvg(20)}</div>
          <div style="min-width:0;">
            <div class="brand-title">${esc(u.company_name)}</div>
            <div class="brand-subtitle">${esc(u.full_name)} · ${esc(roleTag)}</div>
          </div>
        </div>
        <div class="topbar-actions">
          <button class="icon-btn" id="btn-refresh" title="Refresh live balances">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 12a9 9 0 1 1-2.64-6.36"/><polyline points="21 3 21 9 15 9"/>
            </svg>
          </button>
          <button class="icon-btn" id="btn-logout" title="Sign out">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
          </button>
        </div>
      </header>`;
  }

  /* =========================================================
     AUTH & MULTI-OWNER REGISTRATION SCREEN
     ========================================================= */
  function renderAuthScreen() {
    const isLogin = state.authMode === 'login';
    return `
      <div class="auth-wrap">
        <div class="auth-hero">
          <div class="auth-logo">${brandLogoSvg(28)}</div>
          <h1 class="auth-headline">Manage every branch<br>with clarity</h1>
          <p class="auth-subline">
            Record Deposits, Transfers &amp; Withdrawals across MTN MoMo, Telecel Cash &amp; AirtelTigo.
          </p>
        </div>

        <div class="seg-control">
          <button type="button" class="seg-btn ${isLogin ? 'active' : ''}" data-auth-mode="login">Sign In</button>
          <button type="button" class="seg-btn ${!isLogin ? 'active' : ''}" data-auth-mode="register">Register Enterprise</button>
        </div>

        ${isLogin ? renderLoginForm() : renderRegisterForm()}
      </div>
    `;
  }

  function renderLoginForm() {
    const demos = state.demoAccounts || [];
    return `
      <form id="form-login" class="card">
        <div class="field">
          <label>Phone Number or Email</label>
          <input class="input" name="identifier" id="login-identifier" placeholder="e.g. 0244000001" required autocomplete="username">
        </div>
        <div class="field">
          <label>Password</label>
          <input class="input" type="password" name="password" id="login-password" placeholder="Enter password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary btn-block" id="btn-login-submit">Sign In to Workspace</button>
      </form>

      ${demos.length ? `
        <div class="demo-box">
          <div style="font-size:13px;font-weight:700;color:var(--ink-primary);">
            Quick Demo Switcher · Move Money Enterprise
          </div>
          <div style="font-size:12px;color:var(--ink-secondary);margin:3px 0 8px;">
            Tap a role to preview Boss vs. Branch Vendor (Password: <strong>Password@123</strong>)
          </div>
          ${demos.map((d) => `
            <button type="button" class="demo-pill-btn" data-demo-phone="${esc(d.phone)}">
              <div>
                <div style="font-size:13px;font-weight:600;color:var(--ink-primary);">${esc(d.full_name)}</div>
                <div style="font-size:11.5px;color:var(--ink-muted);">
                  ${d.role === 'OWNER' ? 'Main Boss · All 3 Branches' : esc(d.branch_name || 'Vendor')}
                </div>
              </div>
              <span class="type-badge ${d.role === 'OWNER' ? 'TRANSFER' : 'DEPOSIT'}">${esc(d.role)}</span>
            </button>
          `).join('')}
        </div>
      ` : ''}
    `;
  }

  function renderRegisterForm() {
    return `
      <form id="form-register" class="card">
        <div style="font-size:13.5px;font-weight:700;color:var(--ink-primary);margin-bottom:10px;">1. Enterprise &amp; Owner Details</div>
        <div class="field">
          <label>Registered Company Name *</label>
          <input class="input" name="company_name" placeholder="e.g. Move Money Enterprise" required>
        </div>
        <div class="row-2">
          <div class="field">
            <label>Owner Full Name *</label>
            <input class="input" name="full_name" placeholder="Your full name" required>
          </div>
          <div class="field">
            <label>Reg. No. (Optional)</label>
            <input class="input" name="business_reg_no" placeholder="BN-XXXXX">
          </div>
        </div>
        <div class="row-2">
          <div class="field">
            <label>Owner Phone *</label>
            <input class="input" type="tel" name="phone" placeholder="024XXXXXXX" required>
          </div>
          <div class="field">
            <label>Password *</label>
            <input class="input" type="password" name="password" placeholder="Min 6 chars" required>
          </div>
        </div>

        <hr style="border:none;border-top:1px solid var(--border-subtle);margin:12px 0;">
        <div style="font-size:13.5px;font-weight:700;color:var(--ink-primary);margin-bottom:10px;">2. First Branch &amp; Starting Float (GH₵)</div>
        <div class="row-2">
          <div class="field">
            <label>First Branch Name *</label>
            <input class="input" name="first_branch_name" value="Main Branch" required>
          </div>
          <div class="field">
            <label>Drawer Cash (GH₵)</label>
            <input class="input tabular" type="number" step="0.01" min="0" name="opening_cash" value="5000">
          </div>
        </div>
        <div class="row-3">
          <div class="field">
            <label><span class="net-badge MTN">MTN</span></label>
            <input class="input tabular" type="number" step="0.01" min="0" name="mtn_ecash" value="5000">
          </div>
          <div class="field">
            <label><span class="net-badge TELECEL">TCEL</span></label>
            <input class="input tabular" type="number" step="0.01" min="0" name="telecel_ecash" value="2000">
          </div>
          <div class="field">
            <label><span class="net-badge AT">AT</span></label>
            <input class="input tabular" type="number" step="0.01" min="0" name="at_ecash" value="1000">
          </div>
        </div>
        <button type="submit" class="btn btn-blue btn-block">Create Enterprise &amp; Sign In</button>
      </form>
    `;
  }

  /* =========================================================
     VENDOR WORKSPACE VIEWS
     ========================================================= */
  function renderVendorView() {
    if (state.activeTab === 'history') return renderHistoryView(false);
    if (state.activeTab === 'shift') return renderVendorShiftView();

    const d = state.dashboard;
    if (!d) return '';
    const branch = (d.branches && d.branches[0]) || null;
    if (!branch) {
      return `<div class="card empty-state">Your account is not assigned to an active branch yet. Contact your Enterprise Owner.</div>`;
    }

    const netMap = { MTN: 0, TELECEL: 0, AT: 0 };
    (branch.wallets || []).forEach((w) => {
      netMap[w.network] = w.ecash_balance;
    });

    const shiftStatus = d.open_shift ? 'OPEN' : 'CLOSED';

    return `
      <div class="hero-card">
        <div class="hero-eyebrow">
          <span>${esc(branch.name)} (${esc(branch.code)})</span>
          <span class="status-pill ${shiftStatus}">Shift ${shiftStatus}</span>
        </div>
        <div class="hero-caption">Physical Cash in Drawer</div>
        <div class="hero-amount tabular">${money(branch.cash_balance)}</div>

        <div class="net-strip">
          <div class="net-mini-card">
            <div class="net-mini-title">MTN MoMo</div>
            <div class="net-mini-bal tabular">${shortMoney(netMap.MTN)}</div>
          </div>
          <div class="net-mini-card">
            <div class="net-mini-title">Telecel</div>
            <div class="net-mini-bal tabular">${shortMoney(netMap.TELECEL)}</div>
          </div>
          <div class="net-mini-card">
            <div class="net-mini-title">AT Cash</div>
            <div class="net-mini-bal tabular">${shortMoney(netMap.AT)}</div>
          </div>
        </div>
      </div>

      ${(d.alerts || []).map((a) => `
        <div class="alert-card warn">
          <span>${esc(a.label)}</span>
        </div>
      `).join('')}

      <div class="tx-launcher">
        <button type="button" class="tx-launch-btn deposit" data-open-tx="DEPOSIT">
          <div class="tx-launch-icon">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <path d="M12 3v12"/><polyline points="7 10 12 15 17 10"/><path d="M5 21h14"/>
            </svg>
          </div>
          <span class="tx-launch-title">Deposit</span>
          <span class="tx-launch-sub">Customer Acct<br>+Cash / -Float</span>
        </button>

        <button type="button" class="tx-launch-btn transfer" data-open-tx="TRANSFER">
          <div class="tx-launch-icon">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
          </div>
          <span class="tx-launch-title">Transfer</span>
          <span class="tx-launch-sub">To Receiver<br>+Cash / -Float</span>
        </button>

        <button type="button" class="tx-launch-btn withdrawal" data-open-tx="WITHDRAWAL">
          <div class="tx-launch-icon">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <path d="M12 19V7"/><polyline points="7 12 12 7 17 12"/><path d="M5 21h14"/>
            </svg>
          </div>
          <span class="tx-launch-title">Withdrawal</span>
          <span class="tx-launch-sub">Allow Cashout<br>-Cash / +Float</span>
        </button>
      </div>

      <button type="button" class="sms-banner-btn" data-open-sms="1">
        <div>
          <strong>Paste Telco Confirmation SMS</strong>
          <span>Auto-fill ID, network, phone &amp; amount in one tap</span>
        </div>
        <span style="font-size:16px;font-weight:700;">→</span>
      </button>

      <div class="kpi-grid">
        <div class="kpi-tile">
          <div class="kpi-label">Today's Volume</div>
          <div class="kpi-val tabular">${money(d.today.total_volume)}</div>
          <div class="kpi-sub">${d.today.tx_count} completed txns</div>
        </div>
        <div class="kpi-tile">
          <div class="kpi-label">In vs Out Split</div>
          <div class="kpi-val tabular" style="font-size:14px;">
            +${shortMoney(d.today.deposits_volume + d.today.transfers_volume)} / -${shortMoney(d.today.withdrawals_volume)}
          </div>
          <div class="kpi-sub">OTC Fees: ${money(d.today.fees_collected)}</div>
        </div>
      </div>

      <div class="section-head">
        <h2 class="section-title">Recent Branch Transactions</h2>
        <button class="link-btn" data-go-tab="history">See all →</button>
      </div>
      ${renderTxList(d.recent_transactions || [], false)}
    `;
  }

  function renderVendorShiftView() {
    const d = state.dashboard || {};
    const branch = (d.branches && d.branches[0]) || null;
    const openShift = d.open_shift;
    const shifts = state.shiftsList || [];

    return `
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
          <h3 style="margin:0;font-size:15px;font-weight:700;">Daily Shift &amp; Drawer Balancing</h3>
          <span class="status-pill ${openShift ? 'OPEN' : 'CLOSED'}">${openShift ? 'Shift Open' : 'Closed'}</span>
        </div>
        ${openShift && branch ? `
          <div style="font-size:12.5px;color:var(--ink-secondary);margin-bottom:10px;">
            Opened at <strong>${esc(formatTime(openShift.opened_at))}</strong> · Opening Cash: <strong class="tabular">${money(openShift.opening_cash)}</strong>
          </div>
          <div class="kv-row">
            <span>Expected Physical Cash Now</span>
            <strong class="tabular">${money(branch.cash_balance)}</strong>
          </div>
          ${(branch.wallets || []).map((w) => `
            <div class="kv-row">
              <span>Expected <span class="net-badge ${esc(w.network)}">${esc(w.network)}</span> Float</span>
              <strong class="tabular">${money(w.ecash_balance)}</strong>
            </div>
          `).join('')}
          <button class="btn btn-primary btn-block" style="margin-top:12px;" data-close-shift="${openShift.id}">
            Count Cash &amp; Close Shift
          </button>
        ` : `
          <p style="font-size:12.5px;color:var(--ink-muted);margin:0 0 12px;">
            Start your shift to snapshot current opening Physical Cash and MTN, Telecel &amp; AT SIM float balances.
          </p>
          <button class="btn btn-blue btn-block" id="btn-open-shift">Start New Shift Now</button>
        `}
      </div>

      <div class="section-head">
        <h2 class="section-title">Shift Closing History</h2>
      </div>
      ${shifts.length === 0 ? `<div class="card empty-state">No shift records yet.</div>` : shifts.map(renderShiftCard).join('')}
    `;
  }

  function renderVendorNav() {
    const t = state.activeTab;
    return `
      <nav class="bottom-nav">
        <button class="nav-tab ${t === 'home' ? 'active' : ''}" data-nav="home">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="4" width="18" height="16" rx="3"/><line x1="3" y1="10" x2="21" y2="10"/>
          </svg>
          <span>Transact</span>
        </button>
        <button class="nav-tab ${t === 'history' ? 'active' : ''}" data-nav="history">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
          </svg>
          <span>History</span>
        </button>
        <button class="nav-tab ${t === 'shift' ? 'active' : ''}" data-nav="shift">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 15"/>
          </svg>
          <span>Shift</span>
        </button>
      </nav>`;
  }

  /* =========================================================
     BOSS / OWNER COMMAND CENTER VIEWS
     ========================================================= */
  function renderBossView() {
    if (state.activeTab === 'activity') return renderHistoryView(true);
    if (state.activeTab === 'branches') return renderBossBranchesView();
    if (state.activeTab === 'team') return renderBossTeamView();

    const d = state.dashboard;
    if (!d) return '';

    const s = d.summary;
    const t = d.today;
    const voids = d.pending_voids || [];

    return `
      <div class="hero-card">
        <div class="hero-eyebrow">
          <span>Enterprise Working Capital</span>
          <span>${(d.branches || []).length} Branch${(d.branches || []).length === 1 ? '' : 'es'}</span>
        </div>
        <div class="hero-amount tabular">${money(s.total_capital)}</div>

        <div class="hero-split">
          <div class="hero-bucket">
            <div class="hero-bucket-label">Total Physical Cash</div>
            <div class="hero-bucket-val tabular">${money(s.total_cash)}</div>
          </div>
          <div class="hero-bucket">
            <div class="hero-bucket-label">Total SIM E-Cash</div>
            <div class="hero-bucket-val tabular">${money(s.total_ecash)}</div>
          </div>
        </div>

        <div class="net-strip">
          <div class="net-mini-card">
            <div class="net-mini-title">MTN Float</div>
            <div class="net-mini-bal tabular">${shortMoney(s.ecash_by_network.MTN)}</div>
          </div>
          <div class="net-mini-card">
            <div class="net-mini-title">Telecel Float</div>
            <div class="net-mini-bal tabular">${shortMoney(s.ecash_by_network.TELECEL)}</div>
          </div>
          <div class="net-mini-card">
            <div class="net-mini-title">AT Float</div>
            <div class="net-mini-bal tabular">${shortMoney(s.ecash_by_network.AT)}</div>
          </div>
        </div>
      </div>

      ${voids.length > 0 ? `
        <div class="alert-card danger" style="flex-direction:column;align-items:stretch;gap:8px;">
          <div style="font-weight:700;font-size:13px;color:var(--pastel-red-ink);">
            ${voids.length} Pending Void / Correction Request${voids.length === 1 ? '' : 's'}
          </div>
          ${voids.map((v) => `
            <div style="width:100%;background:#ffffff;border-radius:12px;padding:10px 12px;border:1px solid var(--pastel-red-border);">
              <div style="display:flex;justify-content:space-between;font-weight:700;font-size:13px;">
                <span>${esc(v.branch_name)} · <span class="net-badge ${esc(v.network)}">${esc(v.network)}</span> ${esc(v.tx_type)}</span>
                <span class="tabular">${money(v.amount)}</span>
              </div>
              <div style="font-size:12px;color:var(--ink-secondary);margin:4px 0 8px;">
                By <strong>${esc(v.vendor_name)}</strong>: "${esc(v.void_reason)}"
              </div>
              <div style="display:flex;gap:6px;">
                <button class="btn btn-danger btn-sm" data-resolve-void="${v.id}" data-decision="APPROVE">Approve &amp; Reverse</button>
                <button class="btn btn-outline btn-sm" data-resolve-void="${v.id}" data-decision="REJECT">Reject</button>
                <button class="btn btn-outline btn-sm" data-inspect-tx="${v.id}">Inspect</button>
              </div>
            </div>
          `).join('')}
        </div>
      ` : ''}

      ${(d.alerts || []).map((a) => `
        <div class="alert-card warn">
          <span>${esc(a.label)}</span>
          <button class="btn btn-outline btn-sm" data-open-float="${a.branch_id}">Top Up</button>
        </div>
      `).join('')}

      <div class="section-head" style="margin-top:8px;">
        <h2 class="section-title">Today's Enterprise Pulse</h2>
        <button class="btn btn-blue btn-sm" data-open-tx="DEPOSIT">+ Log Txn</button>
      </div>

      <div class="kpi-grid">
        <div class="kpi-tile">
          <div class="kpi-label">Today's Total Volume</div>
          <div class="kpi-val tabular">${money(t.total_volume)}</div>
          <div class="kpi-sub">${t.tx_count} transactions · OTC Fees: ${shortMoney(t.fees_collected)}</div>
        </div>
        <div class="kpi-tile">
          <div class="kpi-label">Deposits (Cash-In)</div>
          <div class="kpi-val tabular cash-plus">${money(t.deposits_volume)}</div>
          <div class="kpi-sub">${t.deposits_count} customer deposits</div>
        </div>
        <div class="kpi-tile">
          <div class="kpi-label">Transfers (To Receiver)</div>
          <div class="kpi-val tabular" style="color:var(--brand-ink);">${money(t.transfers_volume)}</div>
          <div class="kpi-sub">${t.transfers_count} merchant transfers</div>
        </div>
        <div class="kpi-tile">
          <div class="kpi-label">Withdrawals (Cash-Out)</div>
          <div class="kpi-val tabular cash-minus">${money(t.withdrawals_volume)}</div>
          <div class="kpi-sub">${t.withdrawals_count} cash-out payouts</div>
        </div>
      </div>

      <div class="section-head">
        <h2 class="section-title">Live Branches Overview</h2>
        <button class="link-btn" data-go-tab="branches">Manage →</button>
      </div>
      ${(d.branches || []).map((b) => `
        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
            <div>
              <div style="font-size:14.5px;font-weight:700;">
                ${esc(b.name)} <span style="color:var(--ink-muted);font-weight:500;font-size:12px;">(${esc(b.code)})</span>
              </div>
              <div style="font-size:12px;color:var(--ink-muted);margin-top:2px;">
                ${b.open_shift ? `Shift Open: ${esc(b.open_shift.vendor_name)}` : 'Shift Closed'}
                · Today: <strong class="tabular" style="color:var(--ink-primary);">${shortMoney(b.today.volume)}</strong> (${b.today.tx_count})
              </div>
            </div>
            <button class="btn btn-outline btn-sm" data-open-float="${b.id}">+ Float</button>
          </div>
          <div class="kv-row">
            <span>Physical Drawer Cash</span>
            <strong class="tabular">${money(b.cash_balance)}</strong>
          </div>
          <div class="row-3" style="margin-top:10px;">
            ${(b.wallets || []).map((w) => `
              <div class="sub-surface">
                <span class="net-badge ${esc(w.network)}">${esc(w.network)}</span>
                <div class="tabular" style="font-size:12.5px;font-weight:700;margin-top:5px;">${shortMoney(w.ecash_balance)}</div>
              </div>
            `).join('')}
          </div>
        </div>
      `).join('')}

      <div class="section-head">
        <h2 class="section-title">Vendor Performance Today</h2>
        <button class="link-btn" data-go-tab="team">Full Team →</button>
      </div>
      ${(d.vendor_leaderboard || []).length === 0 ? `<div class="card empty-state">No vendors added yet.</div>` : `
        <div class="card" style="padding:10px 14px;">
          ${(d.vendor_leaderboard || []).map((v, idx) => `
            <div class="kv-row">
              <div>
                <div style="font-weight:600;font-size:13.5px;color:var(--ink-primary);">${idx + 1}. ${esc(v.full_name)}</div>
                <div style="font-size:11.5px;color:var(--ink-muted);">${esc(v.branch_name || 'Unassigned')} · ${v.today_tx_count} txns today</div>
              </div>
              <div style="text-align:right;">
                <div class="tabular" style="font-weight:700;font-size:13.5px;color:var(--ink-primary);">${money(v.today_volume)}</div>
                <div class="tabular" style="font-size:11px;color:var(--ink-muted);">All-time: ${shortMoney(v.lifetime_volume)}</div>
              </div>
            </div>
          `).join('')}
        </div>
      `}

      <div class="section-head">
        <h2 class="section-title">Latest Live Activity</h2>
        <button class="link-btn" data-go-tab="activity">Explorer →</button>
      </div>
      ${renderTxList((d.recent_transactions || []).slice(0, 8), true)}
    `;
  }

  function renderHistoryView(showBranchFilter) {
    const f = state.txFilters;
    const branches = (state.dashboard && state.dashboard.branches) || [];
    const list = state.transactions || [];

    const csvHref = apiUrl('/api/transactions', { ...f, export: 'csv' });

    return `
      <div class="card" style="padding:12px;">
        <div style="display:flex;gap:6px;margin-bottom:8px;">
          <input class="input" id="filter-q" placeholder="Search phone, name, Tx ID..." value="${esc(f.q)}" style="padding:8px 11px;font-size:13px;">
          <button class="btn btn-primary btn-sm" id="btn-run-search">Search</button>
        </div>

        ${showBranchFilter ? `
          <div style="display:flex;gap:6px;margin-bottom:8px;">
            <select class="select" id="filter-branch" style="padding:8px 10px;font-size:13px;">
              <option value="">All Branches</option>
              ${branches.map((b) => `<option value="${b.id}" ${String(f.branch_id) === String(b.id) ? 'selected' : ''}>${esc(b.name)}</option>`).join('')}
            </select>
            <a class="btn btn-outline btn-sm" href="${esc(csvHref)}" target="_blank" style="text-decoration:none;white-space:nowrap;">Export CSV</a>
          </div>
        ` : ''}

        <div class="chip-scroll">
          ${[
            ['', 'All Types'],
            ['DEPOSIT', 'Deposits'],
            ['TRANSFER', 'Transfers'],
            ['WITHDRAWAL', 'Withdrawals'],
          ].map(([val, lbl]) => `
            <button class="chip ${f.tx_type === val ? 'active' : ''}" data-filter-type="${val}">${lbl}</button>
          `).join('')}
        </div>

        <div class="chip-scroll" style="margin-bottom:0;">
          ${[
            ['', 'All Networks'],
            ['MTN', 'MTN MoMo'],
            ['TELECEL', 'Telecel'],
            ['AT', 'AirtelTigo'],
          ].map(([val, lbl]) => `
            <button class="chip ${f.network === val ? 'active' : ''}" data-filter-net="${val}">${lbl}</button>
          `).join('')}
          <button class="chip ${f.status === 'VOID_REQUESTED' ? 'active' : ''}" data-filter-status="${f.status === 'VOID_REQUESTED' ? '' : 'VOID_REQUESTED'}">Void Queue</button>
        </div>
      </div>

      <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--ink-secondary);margin:4px 2px 8px;">
        <span><strong>${state.txTotals.count}</strong> matching records</span>
        <span class="tabular">Volume: <strong>${money(state.txTotals.volume)}</strong></span>
      </div>

      ${renderTxList(list, showBranchFilter)}
    `;
  }

  function renderBossBranchesView() {
    const branches = state.branchesList || [];
    const shifts = state.shiftsList || [];
    const adjs = state.floatAdjustments || [];

    return `
      <div style="display:flex;gap:8px;margin-bottom:12px;">
        <button class="btn btn-primary" style="flex:1;" id="btn-new-branch">+ Add Branch</button>
        <button class="btn btn-blue" style="flex:1;" data-open-float="${branches[0] ? branches[0].id : ''}">Adjust Float</button>
      </div>

      ${branches.map((b) => `
        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;">
            <div>
              <div style="font-size:15px;font-weight:700;">${esc(b.name)} <span style="font-size:12px;font-weight:500;color:var(--ink-muted);">(${esc(b.code)})</span></div>
              <div style="font-size:12px;color:var(--ink-muted);margin-top:2px;">
                ${esc(b.location || 'Ghana')} · Capital: <strong class="tabular" style="color:var(--ink-primary);">${money(b.total_capital)}</strong>
              </div>
            </div>
            <div style="display:flex;gap:5px;">
              <button class="btn btn-outline btn-sm" data-edit-branch="${b.id}">Edit SIMs</button>
              <button class="btn btn-blue btn-sm" data-open-float="${b.id}">Top Up</button>
            </div>
          </div>

          <div class="kv-row" style="margin-top:8px;">
            <span>Physical Cash Drawer</span>
            <strong class="tabular">${money(b.cash_balance)}</strong>
          </div>
          ${(b.wallets || []).map((w) => `
            <div class="kv-row">
              <span><span class="net-badge ${esc(w.network)}">${esc(w.network)}</span> ${esc(w.merchant_number)}</span>
              <strong class="tabular">${money(w.ecash_balance)}</strong>
            </div>
          `).join('')}

          <div style="margin-top:8px;font-size:12px;color:var(--ink-secondary);">
            <strong>Assigned Staff:</strong>
            ${(b.vendors || []).length ? b.vendors.map((v) => esc(v.full_name)).join(', ') : '<em>None assigned yet</em>'}
          </div>
        </div>
      `).join('')}

      <div class="section-head">
        <h2 class="section-title">Shift Reconciliations &amp; Shortages</h2>
      </div>
      ${shifts.length === 0 ? `<div class="card empty-state">No shifts logged yet.</div>` : shifts.slice(0, 10).map(renderShiftCard).join('')}

      <div class="section-head">
        <h2 class="section-title">Recent Float Top-Ups &amp; Sweeps</h2>
      </div>
      ${adjs.length === 0 ? `<div class="card empty-state">No manual float adjustments yet.</div>` : `
        <div class="card" style="padding:10px 14px;">
          ${adjs.slice(0, 10).map((a) => `
            <div class="kv-row">
              <div>
                <div style="font-weight:600;font-size:13px;color:var(--ink-primary);">
                  ${esc(a.branch_name)} · ${a.bucket === 'CASH' ? 'Physical Cash' : esc(a.wallet_network) + ' E-Cash'}
                </div>
                <div style="font-size:11.5px;color:var(--ink-muted);">${esc(a.reason)} (${esc(a.actor_name)})</div>
              </div>
              <div style="text-align:right;">
                <div class="tabular ${a.direction === 'CREDIT' ? 'cash-plus' : 'cash-minus'}" style="font-weight:700;">
                  ${a.direction === 'CREDIT' ? '+' : '-'}${money(a.amount)}
                </div>
                <div style="font-size:11px;color:var(--ink-muted);">${esc(formatTime(a.created_at))}</div>
              </div>
            </div>
          `).join('')}
        </div>
      `}
    `;
  }

  function renderBossTeamView() {
    const vendors = state.vendorsList || [];
    return `
      <div style="margin-bottom:12px;">
        <button class="btn btn-primary btn-block" id="btn-new-vendor">+ Employ / Add Branch Vendor</button>
      </div>

      ${vendors.map((v) => `
        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;">
            <div>
              <div style="font-size:14.5px;font-weight:700;">
                ${esc(v.full_name)}
                ${!v.is_active ? `<span class="status-pill VOIDED">Suspended</span>` : ''}
              </div>
              <div style="font-size:12px;color:var(--ink-secondary);margin-top:2px;">
                ${esc(v.phone)} · <strong>${esc(v.role)}</strong>
                ${v.branch_name ? ` · ${esc(v.branch_name)}` : ''}
              </div>
            </div>
            ${v.role !== 'OWNER' ? `
              <button class="btn btn-outline btn-sm" data-edit-vendor="${v.id}">Manage</button>
            ` : `<span class="type-badge TRANSFER">BOSS</span>`}
          </div>

          <div class="row-3 sub-surface" style="margin-top:10px;">
            <div>
              <div style="font-size:11px;color:var(--ink-muted);">Volume Handled</div>
              <div class="tabular" style="font-size:13px;font-weight:700;margin-top:2px;">${shortMoney(v.total_volume)}</div>
            </div>
            <div>
              <div style="font-size:11px;color:var(--ink-muted);">Txns (D/T/W)</div>
              <div class="tabular" style="font-size:13px;font-weight:700;margin-top:2px;">
                ${v.total_tx_count} <span style="font-weight:400;font-size:11px;color:var(--ink-muted);">(${v.deposit_count}/${v.transfer_count}/${v.withdrawal_count})</span>
              </div>
            </div>
            <div>
              <div style="font-size:11px;color:var(--ink-muted);">Void Flags</div>
              <div class="tabular ${v.void_flags > 0 ? 'text-danger' : 'cash-plus'}" style="font-size:13px;font-weight:700;margin-top:2px;">${v.void_flags}</div>
            </div>
          </div>
        </div>
      `).join('')}
    `;
  }

  function renderBossNav() {
    const t = state.activeTab;
    const pendingVoids = (state.dashboard && state.dashboard.today && state.dashboard.today.pending_voids_count) || 0;
    return `
      <nav class="bottom-nav">
        <button class="nav-tab ${t === 'home' ? 'active' : ''}" data-nav="home">
          ${pendingVoids > 0 ? `<span class="nav-badge">${pendingVoids}</span>` : ''}
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
          </svg>
          <span>Overview</span>
        </button>
        <button class="nav-tab ${t === 'activity' ? 'active' : ''}" data-nav="activity">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
          </svg>
          <span>Activity</span>
        </button>
        <button class="nav-tab ${t === 'branches' ? 'active' : ''}" data-nav="branches">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
          </svg>
          <span>Branches</span>
        </button>
        <button class="nav-tab ${t === 'team' ? 'active' : ''}" data-nav="team">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
          </svg>
          <span>Team</span>
        </button>
      </nav>`;
  }

  /* =========================================================
     SHARED COMPONENTS
     ========================================================= */
  function renderTxList(items, showBranch) {
    if (!items || items.length === 0) {
      return `<div class="card empty-state">No transactions recorded for this view yet.</div>`;
    }

    return `<div class="tx-list">${items.map((tx) => {
      const cashPlus = tx.cash_impact >= 0;
      const crossNet = tx.tx_type === 'TRANSFER' && tx.receiver_network && tx.receiver_network !== tx.network
        ? ` → ${esc(tx.receiver_network)}`
        : '';
      return `
        <div class="tx-item ${tx.status === 'VOIDED' ? 'voided' : ''}" data-inspect-tx="${tx.id}">
          <div class="tx-left">
            <div class="tx-top-line">
              <span class="net-badge ${esc(tx.network)}">${esc(tx.network)}${crossNet}</span>
              <span class="type-badge ${esc(tx.tx_type)}">${esc(tx.tx_type)}</span>
              ${tx.status !== 'COMPLETED' ? `<span class="status-pill ${esc(tx.status)}">${esc(tx.status.replace('_', ' '))}</span>` : ''}
            </div>
            <div class="tx-phone">${esc(tx.customer_phone)}${tx.customer_name ? ' · ' + esc(tx.customer_name) : ''}</div>
            <div class="tx-meta">
              ${showBranch ? `${esc(tx.branch_name)} (${esc(tx.vendor_name)}) · ` : ''}
              ID: ${esc(tx.telco_tx_id)} · ${esc(formatTime(tx.created_at))}
            </div>
          </div>
          <div class="tx-right">
            <div class="tx-amt tabular">${money(tx.amount)}</div>
            <div class="tx-impact-hint tabular ${cashPlus ? 'cash-plus' : 'cash-minus'}">
              Cash ${cashPlus ? '+' : ''}${shortMoney(tx.cash_impact)}
            </div>
          </div>
        </div>
      `;
    }).join('')}</div>`;
  }

  function renderShiftCard(s) {
    return `
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div style="font-size:13.5px;font-weight:700;">${esc(s.branch_name)} · ${esc(s.vendor_name)}</div>
          <span class="status-pill ${esc(s.status)}">${esc(s.status)}</span>
        </div>
        <div style="font-size:12px;color:var(--ink-muted);margin-top:3px;">
          Opened: ${esc(formatTime(s.opened_at))} ${s.closed_at ? ' · Closed: ' + esc(formatTime(s.closed_at)) : ''}
        </div>
        ${s.status !== 'OPEN' ? `
          <div style="margin-top:8px;font-size:12px;display:flex;justify-content:space-between;">
            <span>Cash Variance: <strong class="tabular ${s.cash_variance < 0 ? 'text-danger' : 'cash-plus'}">${money(s.cash_variance)}</strong></span>
            <span>Float Variance: <strong class="tabular ${s.ecash_variance < 0 ? 'text-danger' : 'cash-plus'}">${money(s.ecash_variance)}</strong></span>
          </div>
        ` : ''}
      </div>`;
  }

  /* =========================================================
     SLIDE-UP BOTTOM SHEETS (CONTAINED INSIDE 393px SCREEN)
     ========================================================= */
  function renderSheet() {
    const host = document.getElementById('sheet-host');
    if (!host) return;
    if (!state.sheet) {
      host.innerHTML = '';
      return;
    }

    const { type, data } = state.sheet;
    let title = '';
    let content = '';

    if (type === 'tx_create') {
      title = 'Record MoMo Transaction';
      content = renderTxCreateSheet(data);
    } else if (type === 'tx_inspect') {
      title = 'Transaction Audit Receipt';
      content = renderTxInspectSheet(data);
    } else if (type === 'shift_close') {
      title = 'Close Shift & Count Cash';
      content = renderShiftCloseSheet(data);
    } else if (type === 'branch_new') {
      title = 'Add New Branch & SIM Wallets';
      content = renderBranchNewSheet();
    } else if (type === 'branch_edit') {
      title = 'Edit Branch & Merchant SIMs';
      content = renderBranchEditSheet(data);
    } else if (type === 'float_adjust') {
      title = 'Float Top-Up / Cash Sweep';
      content = renderFloatAdjustSheet(data);
    } else if (type === 'vendor_new') {
      title = 'Employ Branch Vendor';
      content = renderVendorNewSheet();
    } else if (type === 'vendor_edit') {
      title = 'Manage Employee Account';
      content = renderVendorEditSheet(data);
    }

    host.innerHTML = `
      <div class="sheet-backdrop" id="sheet-backdrop">
        <div class="bottom-sheet" onclick="event.stopPropagation()">
          <div class="sheet-handle"></div>
          <div class="sheet-header">
            <h3 class="sheet-title">${esc(title)}</h3>
            <button type="button" class="sheet-close" id="btn-sheet-close">✕</button>
          </div>
          <div class="sheet-body">${content}</div>
        </div>
      </div>
    `;

    bindSheetEvents();
  }

  function renderTxCreateSheet(data) {
    const branches = (state.dashboard && state.dashboard.branches) || [];
    const branchId = data.branch_id || (branches[0] ? branches[0].id : 0);
    const activeBranch = branches.find((b) => Number(b.id) === Number(branchId)) || branches[0] || { wallets: [], cash_balance: 0 };

    const txType = data.tx_type || 'DEPOSIT';
    const network = data.network || 'MTN';
    const receiverNetwork = data.receiver_network || network;
    const mode = data.tab || 'form'; // 'form' | 'sms'

    const walletMap = { MTN: 0, TELECEL: 0, AT: 0 };
    (activeBranch.wallets || []).forEach((w) => {
      walletMap[w.network] = w.ecash_balance;
    });

    const amt = parseFloat(data.amount || 0) || 0;
    const fee = txType === 'TRANSFER' ? (parseFloat(data.fee_charged || 0) || 0) : 0;
    const cashDelta = txType === 'WITHDRAWAL' ? -amt : (amt + fee);
    const ecashDelta = txType === 'WITHDRAWAL' ? +amt : -amt;

    return `
      <div class="seg-control">
        <button type="button" class="seg-btn ${mode === 'form' ? 'active' : ''}" data-tx-tab="form">Quick Form</button>
        <button type="button" class="seg-btn ${mode === 'sms' ? 'active' : ''}" data-tx-tab="sms">Paste Telco SMS</button>
      </div>

      ${mode === 'sms' ? `
        <div class="field">
          <label>Paste Confirmation SMS from MTN, Telecel, or AT</label>
          <textarea class="textarea" id="sms-raw-box" rows="4" placeholder="Paste the SMS received after completing the transaction on your phone...">${esc(data.raw_sms || '')}</textarea>
        </div>
        <div style="font-size:11.5px;color:var(--ink-muted);margin-bottom:6px;">Quick test samples (one tap):</div>
        <div class="chip-scroll" style="margin-bottom:12px;">
          <button type="button" class="chip" data-sample-sms="MTN_DEPOSIT">Sample MTN Deposit</button>
          <button type="button" class="chip" data-sample-sms="TELECEL_TRANSFER">Sample Telecel Send</button>
          <button type="button" class="chip" data-sample-sms="AT_WITHDRAWAL">Sample AT Cash-Out</button>
        </div>
        <button type="button" class="btn btn-blue btn-block" id="btn-parse-sms">Extract Transaction Details →</button>
      ` : `
        <form id="form-record-tx">
          ${data.entry_mode === 'SMS_PARSED' ? `
            <div class="alert-card success">
              <span>Auto-filled from Telco SMS. Verify &amp; tap Confirm.</span>
            </div>
          ` : ''}

          ${isBoss() && branches.length > 1 ? `
            <div class="field">
              <label>Branch Performing Transaction</label>
              <select class="select" name="branch_id" id="tx-branch-select">
                ${branches.map((b) => `<option value="${b.id}" ${Number(b.id) === Number(branchId) ? 'selected' : ''}>${esc(b.name)} (Cash: ${shortMoney(b.cash_balance)})</option>`).join('')}
              </select>
            </div>
          ` : `<input type="hidden" name="branch_id" value="${esc(branchId)}">`}

          <div class="seg-control">
            <button type="button" class="seg-btn ${txType === 'DEPOSIT' ? 'active' : ''}" data-pick-type="DEPOSIT">Deposit</button>
            <button type="button" class="seg-btn ${txType === 'TRANSFER' ? 'active' : ''}" data-pick-type="TRANSFER">Transfer</button>
            <button type="button" class="seg-btn ${txType === 'WITHDRAWAL' ? 'active' : ''}" data-pick-type="WITHDRAWAL">Withdrawal</button>
          </div>

          <div style="font-size:12px;font-weight:600;color:var(--ink-secondary);margin-bottom:5px;">
            ${txType === 'TRANSFER' ? 'Merchant SIM Wallet Used' : 'Mobile Network'}
          </div>
          <div class="net-selector">
            ${['MTN', 'TELECEL', 'AT'].map((net) => `
              <button type="button" class="net-pick ${net} ${network === net ? 'active' : ''}" data-pick-net="${net}">
                <span class="np-name">${net === 'AT' ? 'AirtelTigo' : net}</span>
                <span class="np-bal tabular">${shortMoney(walletMap[net])}</span>
              </button>
            `).join('')}
          </div>

          ${txType === 'WITHDRAWAL' ? `
            <div class="protocol-box">
              <strong>Withdrawal Cash-Out Protocol</strong>
              1. Ask customer to enable <em>"Allow Cash Out"</em> on their phone.<br>
              2. Initiate withdrawal on Merchant App &amp; customer enters MoMo PIN.<br>
              3. Confirm SMS arrived before giving physical cash (${shortMoney(activeBranch.cash_balance)} in drawer).
            </div>
          ` : ''}

          <div class="row-2">
            <div class="field">
              <label>${txType === 'TRANSFER' ? 'Receiver Phone *' : 'Customer Personal Phone *'}</label>
              <input class="input tabular" type="tel" id="tx-phone" name="customer_phone" placeholder="024XXXXXXX" value="${esc(data.customer_phone || '')}" required>
            </div>
            <div class="field">
              <label>${txType === 'TRANSFER' ? 'Receiver Name' : 'Customer Name'}</label>
              <input class="input" name="customer_name" id="tx-cname" placeholder="Optional" value="${esc(data.customer_name || '')}">
            </div>
          </div>

          ${txType === 'TRANSFER' ? `
            <div class="row-2">
              <div class="field">
                <label>Receiver Network</label>
                <select class="select" name="receiver_network" id="tx-rnet">
                  <option value="MTN" ${receiverNetwork === 'MTN' ? 'selected' : ''}>MTN Mobile Money</option>
                  <option value="TELECEL" ${receiverNetwork === 'TELECEL' ? 'selected' : ''}>Telecel Cash</option>
                  <option value="AT" ${receiverNetwork === 'AT' ? 'selected' : ''}>AirtelTigo Cash</option>
                </select>
              </div>
              <div class="field">
                <label>OTC Service Fee (GH₵)</label>
                <input class="input tabular" type="number" step="0.01" min="0" id="tx-fee" name="fee_charged" value="${esc(data.fee_charged || '0')}">
              </div>
            </div>
            <div class="row-2">
              <div class="field">
                <label>Sender Name (Optional)</label>
                <input class="input" name="sender_name" id="tx-sname" placeholder="Customer in shop" value="${esc(data.sender_name || '')}">
              </div>
              <div class="field">
                <label>Sender Phone (Optional)</label>
                <input class="input tabular" type="tel" name="sender_phone" id="tx-sphone" placeholder="024XXXXXXX" value="${esc(data.sender_phone || '')}">
              </div>
            </div>
          ` : ''}

          <div class="field">
            <label>Amount (GH₵) *</label>
            <input class="input input-lg-amount tabular" type="number" step="0.01" min="0.5" id="tx-amount" name="amount" placeholder="0.00" value="${esc(data.amount || '')}" required>
          </div>

          <div class="row-2">
            <div class="field">
              <label>Telco SMS Txn ID *</label>
              <input class="input tabular" name="telco_tx_id" id="tx-id" placeholder="e.g. 28491038472" value="${esc(data.telco_tx_id || '')}" required>
            </div>
            <div class="field">
              <label>Reference</label>
              <input class="input" name="reference" id="tx-ref" placeholder="e.g. Deposit / Upkeep" value="${esc(data.reference || '')}">
            </div>
          </div>

          ${txType === 'WITHDRAWAL' ? `
            <label class="check-row">
              <input type="checkbox" id="tx-cashout-ok" name="cashout_allowed_confirmed" ${data.cashout_allowed_confirmed ? 'checked' : ''} required>
              <span>Customer enabled "Allow Cash Out", entered MoMo PIN &amp; SMS confirmed</span>
            </label>
          ` : ''}

          <div class="impact-preview" id="tx-impact-bar">
            <span class="${cashDelta >= 0 ? 'cash-plus' : 'cash-minus'} tabular">
              Drawer Cash: ${cashDelta >= 0 ? '+' : ''}${money(cashDelta)}
            </span>
            <span class="${ecashDelta >= 0 ? 'cash-plus' : 'cash-minus'} tabular">
              ${esc(network)} Float: ${ecashDelta >= 0 ? '+' : ''}${money(ecashDelta)}
            </span>
          </div>

          <button type="submit" class="btn btn-primary btn-block">Confirm &amp; Record ${esc(txType)}</button>
        </form>
      `}
    `;
  }

  function findTxById(id) {
    const pools = [
      state.transactions || [],
      (state.dashboard && state.dashboard.recent_transactions) || [],
      (state.dashboard && state.dashboard.pending_voids) || [],
    ];
    for (const list of pools) {
      const hit = list.find((x) => Number(x.id) === Number(id));
      if (hit) return hit;
    }
    return null;
  }

  function renderTxInspectSheet(data) {
    const tx = data.tx;
    if (!tx) return '<div class="empty-state">Transaction details unavailable.</div>';

    return `
      <div style="text-align:center;padding-bottom:12px;border-bottom:1px solid var(--border-subtle);margin-bottom:10px;">
        <div style="display:flex;justify-content:center;gap:6px;margin-bottom:8px;">
          <span class="net-badge ${esc(tx.network)}">${esc(netLabel(tx.network))}</span>
          <span class="type-badge ${esc(tx.tx_type)}">${esc(tx.tx_type)}</span>
          <span class="status-pill ${esc(tx.status)}">${esc(tx.status.replace('_', ' '))}</span>
        </div>
        <div class="tabular" style="font-size:26px;font-weight:700;letter-spacing:-0.02em;">${money(tx.amount)}</div>
        ${tx.fee_charged > 0 ? `<div style="font-size:12px;color:var(--ink-muted);">+ OTC Service Fee: ${money(tx.fee_charged)}</div>` : ''}
      </div>

      <div class="kv-row"><span>Branch</span><strong>${esc(tx.branch_name)}</strong></div>
      <div class="kv-row"><span>Recorded By</span><strong>${esc(tx.vendor_name)}</strong></div>
      <div class="kv-row"><span>${tx.tx_type === 'TRANSFER' ? 'Receiver' : 'Customer'}</span><strong>${esc(tx.customer_phone)} ${tx.customer_name ? '(' + esc(tx.customer_name) + ')' : ''}</strong></div>
      ${tx.tx_type === 'TRANSFER' && (tx.sender_name || tx.sender_phone) ? `
        <div class="kv-row"><span>Sender (Walk-in)</span><strong>${esc(tx.sender_name || '')} ${esc(tx.sender_phone || '')}</strong></div>
      ` : ''}
      <div class="kv-row"><span>Telco SMS Txn ID</span><strong class="tabular">${esc(tx.telco_tx_id)}</strong></div>
      <div class="kv-row"><span>Reference</span><strong>${esc(tx.reference || '—')}</strong></div>
      <div class="kv-row"><span>Entry Mode</span><strong>${esc(tx.entry_mode)}</strong></div>
      <div class="kv-row"><span>Cash Drawer Impact</span><strong class="tabular ${tx.cash_impact >= 0 ? 'cash-plus' : 'cash-minus'}">${tx.cash_impact >= 0 ? '+' : ''}${money(tx.cash_impact)} (After: ${shortMoney(tx.cash_balance_after)})</strong></div>
      <div class="kv-row"><span>${esc(tx.network)} Float Impact</span><strong class="tabular ${tx.ecash_impact >= 0 ? 'cash-plus' : 'cash-minus'}">${tx.ecash_impact >= 0 ? '+' : ''}${money(tx.ecash_impact)} (After: ${shortMoney(tx.ecash_balance_after)})</strong></div>
      <div class="kv-row"><span>Date &amp; Time</span><strong>${esc(tx.created_at)}</strong></div>

      ${tx.raw_sms ? `
        <div class="sub-surface" style="margin-top:10px;font-size:12px;color:var(--ink-secondary);">
          <strong style="color:var(--ink-primary);">Attached SMS Proof:</strong><br>${esc(tx.raw_sms)}
        </div>
      ` : ''}

      ${tx.void_reason ? `
        <div class="alert-card warn" style="margin-top:10px;">
          <div><strong>Void Reason:</strong> ${esc(tx.void_reason)}
          ${tx.void_resolution_note ? `<br><strong>Boss Resolution:</strong> ${esc(tx.void_resolution_note)}` : ''}</div>
        </div>
      ` : ''}

      ${tx.status === 'COMPLETED' ? `
        <form id="form-void-request" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border-subtle);">
          <input type="hidden" name="tx_id" value="${tx.id}">
          <div class="field">
            <label>Made a mistake? Request Correction / Void (Immutable Audit)</label>
            <input class="input" name="reason" placeholder="Explain error clearly for Boss approval..." required>
          </div>
          <button type="submit" class="btn btn-danger btn-block">
            Flag for Boss Void / Reversal
          </button>
        </form>
      ` : ''}

      ${isBoss() && tx.status === 'VOID_REQUESTED' ? `
        <div style="display:flex;gap:8px;margin-top:12px;">
          <button class="btn btn-danger" style="flex:1;" data-resolve-void="${tx.id}" data-decision="APPROVE">Approve &amp; Reverse</button>
          <button class="btn btn-outline" style="flex:1;" data-resolve-void="${tx.id}" data-decision="REJECT">Reject Request</button>
        </div>
      ` : ''}
    `;
  }

  function renderShiftCloseSheet(data) {
    const branches = (state.dashboard && state.dashboard.branches) || [];
    const branch = branches.find((b) => Number(b.id) === Number(data.branch_id)) || branches[0] || { cash_balance: 0, wallets: [] };
    const wMap = { MTN: 0, TELECEL: 0, AT: 0 };
    (branch.wallets || []).forEach((w) => {
      wMap[w.network] = w.ecash_balance;
    });

    return `
      <form id="form-close-shift">
        <input type="hidden" name="shift_id" value="${esc(data.shift_id)}">
        <p style="margin:0 0 12px;font-size:12.5px;color:var(--ink-secondary);">
          Count your physical cash drawer and check each Merchant SIM balance. Any shortage or overage is automatically calculated.
        </p>
        <div class="field">
          <label><span>Counted Physical Cash (GH₵)</span><span>Expected: ${shortMoney(branch.cash_balance)}</span></label>
          <input class="input tabular" type="number" step="0.01" name="actual_cash" value="${esc(branch.cash_balance)}" required>
        </div>
        <div class="field">
          <label><span>Actual MTN MoMo Float</span><span>Expected: ${shortMoney(wMap.MTN)}</span></label>
          <input class="input tabular" type="number" step="0.01" name="actual_mtn" value="${esc(wMap.MTN)}" required>
        </div>
        <div class="field">
          <label><span>Actual Telecel Cash Float</span><span>Expected: ${shortMoney(wMap.TELECEL)}</span></label>
          <input class="input tabular" type="number" step="0.01" name="actual_telecel" value="${esc(wMap.TELECEL)}" required>
        </div>
        <div class="field">
          <label><span>Actual AirtelTigo Float</span><span>Expected: ${shortMoney(wMap.AT)}</span></label>
          <input class="input tabular" type="number" step="0.01" name="actual_at" value="${esc(wMap.AT)}" required>
        </div>
        <div class="field">
          <label>Closing Notes (Optional)</label>
          <input class="input" name="notes" placeholder="Any remarks for the Boss...">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Submit Closing Reconciliation</button>
      </form>
    `;
  }

  function renderBranchNewSheet() {
    return `
      <form id="form-new-branch">
        <div class="field">
          <label>Branch Name *</label>
          <input class="input" name="name" placeholder="e.g. East Legon Boundary Rd" required>
        </div>
        <div class="field">
          <label>Location / Landmark</label>
          <input class="input" name="location" placeholder="e.g. Opposite Shell Filling Station">
        </div>
        <div class="field">
          <label>Starting Physical Cash in Drawer (GH₵)</label>
          <input class="input tabular" type="number" step="0.01" min="0" name="cash_balance" value="3000">
        </div>
        <div class="row-2">
          <div class="field">
            <label>MTN Agent Number</label>
            <input class="input tabular" type="tel" name="mtn_number" placeholder="024XXXXXXX">
          </div>
          <div class="field">
            <label>MTN Float (GH₵)</label>
            <input class="input tabular" type="number" step="0.01" min="0" name="mtn_ecash" value="5000">
          </div>
        </div>
        <div class="row-2">
          <div class="field">
            <label>Telecel Agent Number</label>
            <input class="input tabular" type="tel" name="telecel_number" placeholder="020XXXXXXX">
          </div>
          <div class="field">
            <label>Telecel Float (GH₵)</label>
            <input class="input tabular" type="number" step="0.01" min="0" name="telecel_ecash" value="2000">
          </div>
        </div>
        <div class="row-2">
          <div class="field">
            <label>AirtelTigo Agent No.</label>
            <input class="input tabular" type="tel" name="at_number" placeholder="027XXXXXXX">
          </div>
          <div class="field">
            <label>AT Float (GH₵)</label>
            <input class="input tabular" type="number" step="0.01" min="0" name="at_ecash" value="1000">
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Create Branch &amp; Wallets</button>
      </form>
    `;
  }

  function renderBranchEditSheet(data) {
    const b = data.branch;
    const wMap = {};
    (b.wallets || []).forEach((w) => {
      wMap[w.network] = w;
    });
    return `
      <form id="form-edit-branch">
        <input type="hidden" name="branch_id" value="${b.id}">
        <div class="field">
          <label>Branch Name</label>
          <input class="input" name="name" value="${esc(b.name)}" required>
        </div>
        <div class="field">
          <label>Location</label>
          <input class="input" name="location" value="${esc(b.location || '')}">
        </div>
        ${['MTN', 'TELECEL', 'AT'].map((net) => `
          <div class="row-2">
            <div class="field">
              <label>${net} Merchant SIM</label>
              <input class="input tabular" name="num_${net}" value="${esc(wMap[net] ? wMap[net].merchant_number : '')}">
            </div>
            <div class="field">
              <label>${net} Account Name</label>
              <input class="input" name="acc_${net}" value="${esc(wMap[net] ? wMap[net].account_name : '')}">
            </div>
          </div>
        `).join('')}
        <button type="submit" class="btn btn-primary btn-block">Save Branch &amp; SIM Details</button>
      </form>
    `;
  }

  function renderFloatAdjustSheet(data) {
    const branches = (state.dashboard && state.dashboard.branches) || state.branchesList || [];
    const selectedId = data.branch_id || (branches[0] ? branches[0].id : '');
    return `
      <form id="form-float-adjust">
        <div class="field">
          <label>Target Branch</label>
          <select class="select" name="branch_id" required>
            ${branches.map((b) => `<option value="${b.id}" ${String(b.id) === String(selectedId) ? 'selected' : ''}>${esc(b.name)} (Cash: ${shortMoney(b.cash_balance)})</option>`).join('')}
          </select>
        </div>
        <div class="row-2">
          <div class="field">
            <label>Bucket</label>
            <select class="select" name="bucket" id="float-bucket">
              <option value="CASH">Physical Cash Drawer</option>
              <option value="ECASH">Merchant SIM E-Cash</option>
            </select>
          </div>
          <div class="field" id="float-net-wrap" style="display:none;">
            <label>Network SIM</label>
            <select class="select" name="network">
              <option value="MTN">MTN MoMo</option>
              <option value="TELECEL">Telecel Cash</option>
              <option value="AT">AirtelTigo Cash</option>
            </select>
          </div>
        </div>
        <div class="row-2">
          <div class="field">
            <label>Action</label>
            <select class="select" name="direction">
              <option value="CREDIT">Top Up / Inject (+)</option>
              <option value="DEBIT">Sweep / Deduct (-)</option>
            </select>
          </div>
          <div class="field">
            <label>Amount (GH₵) *</label>
            <input class="input tabular" type="number" step="0.01" min="1" name="amount" placeholder="0.00" required>
          </div>
        </div>
        <div class="field">
          <label>Reason / Reference *</label>
          <input class="input" name="reason" placeholder="e.g. Morning float top-up from bank" required>
        </div>
        <button type="submit" class="btn btn-blue btn-block">Apply Float Adjustment</button>
      </form>
    `;
  }

  function renderVendorNewSheet() {
    const branches = state.branchesList.length ? state.branchesList : ((state.dashboard && state.dashboard.branches) || []);
    return `
      <form id="form-new-vendor">
        <div class="field">
          <label>Employee Full Name *</label>
          <input class="input" name="full_name" placeholder="e.g. Yaw Mensah" required>
        </div>
        <div class="row-2">
          <div class="field">
            <label>Login Phone Number *</label>
            <input class="input tabular" type="tel" name="phone" placeholder="024XXXXXXX" required>
          </div>
          <div class="field">
            <label>Role</label>
            <select class="select" name="role">
              <option value="VENDOR">Branch Vendor</option>
              ${state.user.role === 'OWNER' ? `<option value="MANAGER">Supervisor / Manager</option>` : ''}
            </select>
          </div>
        </div>
        <div class="field">
          <label>Assign to Branch *</label>
          <select class="select" name="branch_id" required>
            ${branches.map((b) => `<option value="${b.id}">${esc(b.name)} (${esc(b.code)})</option>`).join('')}
          </select>
        </div>
        <div class="field">
          <label>Temporary Login Password *</label>
          <input class="input" type="password" name="password" placeholder="Min 6 characters" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Create Vendor Account</button>
      </form>
    `;
  }

  function renderVendorEditSheet(data) {
    const v = data.vendor;
    const branches = state.branchesList.length ? state.branchesList : ((state.dashboard && state.dashboard.branches) || []);
    return `
      <form id="form-edit-vendor">
        <input type="hidden" name="vendor_id" value="${v.id}">
        <div class="field">
          <label>Full Name</label>
          <input class="input" name="full_name" value="${esc(v.full_name)}" required>
        </div>
        <div class="field">
          <label>Assigned Branch</label>
          <select class="select" name="branch_id">
            ${branches.map((b) => `<option value="${b.id}" ${Number(v.branch_id) === Number(b.id) ? 'selected' : ''}>${esc(b.name)}</option>`).join('')}
          </select>
        </div>
        <div class="field">
          <label>Account Status</label>
          <select class="select" name="is_active">
            <option value="1" ${v.is_active ? 'selected' : ''}>Active (Can Sign In &amp; Transact)</option>
            <option value="0" ${!v.is_active ? 'selected' : ''}>Suspended (Block Login Immediately)</option>
          </select>
        </div>
        <div class="field">
          <label>Reset Password (leave blank to keep current)</label>
          <input class="input" type="password" name="new_password" placeholder="Optional new password">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Update Employee</button>
      </form>
    `;
  }

  /* =========================================================
     EVENT BINDINGS
     ========================================================= */
  function bindAuthEvents() {
    document.querySelectorAll('[data-auth-mode]').forEach((btn) => {
      btn.addEventListener('click', () => {
        state.authMode = btn.getAttribute('data-auth-mode');
        render();
      });
    });

    document.querySelectorAll('[data-demo-phone]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const phone = btn.getAttribute('data-demo-phone');
        const idInput = document.getElementById('login-identifier');
        const pwInput = document.getElementById('login-password');
        if (idInput && pwInput) {
          idInput.value = phone;
          pwInput.value = 'Password@123';
          await submitLogin(phone, 'Password@123');
        }
      });
    });

    const loginForm = document.getElementById('form-login');
    if (loginForm) {
      loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(loginForm);
        await submitLogin(fd.get('identifier'), fd.get('password'));
      });
    }

    const regForm = document.getElementById('form-register');
    if (regForm) {
      regForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = Object.fromEntries(new FormData(regForm).entries());
        try {
          const res = await api('POST', '/api/auth/register', payload);
          state.authenticated = true;
          state.user = res.user;
          state.bearerToken = res.token || '';
          if (state.bearerToken) localStorage.setItem('momo_bearer_token', state.bearerToken);
          state.activeTab = 'home';
          await refreshCurrentView();
          toast(res.message || 'Enterprise created!');
          render();
        } catch (err) {
          toast(err.message, true);
        }
      });
    }
  }

  async function submitLogin(identifier, password) {
    try {
      const res = await api('POST', '/api/auth/login', { identifier, password });
      state.authenticated = true;
      state.user = res.user;
      state.bearerToken = res.token || '';
      if (state.bearerToken) localStorage.setItem('momo_bearer_token', state.bearerToken);
      state.activeTab = 'home';
      await refreshCurrentView();
      toast('Signed in as ' + res.user.full_name);
      render();
    } catch (err) {
      toast(err.message, true);
    }
  }

  function bindAppEvents() {
    const refreshBtn = document.getElementById('btn-refresh');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', async () => {
        try {
          await refreshCurrentView();
          render();
          toast('Balances refreshed');
        } catch (err) {
          toast(err.message, true);
        }
      });
    }

    const logoutBtn = document.getElementById('btn-logout');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', async () => {
        try {
          await api('POST', '/api/auth/logout');
        } catch (_) {}
        state.authenticated = false;
        state.user = null;
        state.dashboard = null;
        state.bearerToken = '';
        localStorage.removeItem('momo_bearer_token');
        const me = await api('GET', '/api/auth/me');
        state.demoAccounts = me.demo_accounts || [];
        render();
      });
    }

    document.querySelectorAll('[data-nav]').forEach((btn) => {
      btn.addEventListener('click', () => switchTab(btn.getAttribute('data-nav')));
    });

    document.querySelectorAll('[data-go-tab]').forEach((btn) => {
      btn.addEventListener('click', () => switchTab(btn.getAttribute('data-go-tab')));
    });

    document.querySelectorAll('[data-open-tx]').forEach((btn) => {
      btn.addEventListener('click', () => {
        openSheet('tx_create', { tx_type: btn.getAttribute('data-open-tx'), network: 'MTN', tab: 'form' });
      });
    });

    document.querySelectorAll('[data-open-sms]').forEach((btn) => {
      btn.addEventListener('click', () => {
        openSheet('tx_create', { tx_type: 'DEPOSIT', network: 'MTN', tab: 'sms' });
      });
    });

    document.querySelectorAll('[data-inspect-tx]').forEach((el) => {
      el.addEventListener('click', () => {
        const tx = findTxById(el.getAttribute('data-inspect-tx'));
        if (tx) openSheet('tx_inspect', { tx });
      });
    });

    document.querySelectorAll('[data-resolve-void]').forEach((btn) => {
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const txId = btn.getAttribute('data-resolve-void');
        const decision = btn.getAttribute('data-decision');
        try {
          const res = await api('POST', `/api/transactions/${txId}/void-resolve`, { decision });
          toast(res.message);
          closeSheet();
          await refreshCurrentView();
          render();
        } catch (err) {
          toast(err.message, true);
        }
      });
    });

    // Shift open/close
    const openShiftBtn = document.getElementById('btn-open-shift');
    if (openShiftBtn) {
      openShiftBtn.addEventListener('click', async () => {
        try {
          const res = await api('POST', '/api/shifts/open', {});
          toast(res.message);
          await refreshCurrentView();
          render();
        } catch (err) {
          toast(err.message, true);
        }
      });
    }

    document.querySelectorAll('[data-close-shift]').forEach((btn) => {
      btn.addEventListener('click', () => {
        openSheet('shift_close', { shift_id: btn.getAttribute('data-close-shift') });
      });
    });

    // Explorer filters
    const searchBtn = document.getElementById('btn-run-search');
    const searchInput = document.getElementById('filter-q');
    if (searchBtn && searchInput) {
      const runSearch = async () => {
        state.txFilters.q = searchInput.value.trim();
        await refreshCurrentView();
        render();
      };
      searchBtn.addEventListener('click', runSearch);
      searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          runSearch();
        }
      });
    }

    const branchSelect = document.getElementById('filter-branch');
    if (branchSelect) {
      branchSelect.addEventListener('change', async () => {
        state.txFilters.branch_id = branchSelect.value;
        await refreshCurrentView();
        render();
      });
    }

    document.querySelectorAll('[data-filter-type]').forEach((c) => {
      c.addEventListener('click', async () => {
        state.txFilters.tx_type = c.getAttribute('data-filter-type');
        await refreshCurrentView();
        render();
      });
    });

    document.querySelectorAll('[data-filter-net]').forEach((c) => {
      c.addEventListener('click', async () => {
        state.txFilters.network = c.getAttribute('data-filter-net');
        await refreshCurrentView();
        render();
      });
    });

    document.querySelectorAll('[data-filter-status]').forEach((c) => {
      c.addEventListener('click', async () => {
        state.txFilters.status = c.getAttribute('data-filter-status');
        await refreshCurrentView();
        render();
      });
    });

    // Branches & Float & Team buttons
    const newBranchBtn = document.getElementById('btn-new-branch');
    if (newBranchBtn) {
      newBranchBtn.addEventListener('click', () => openSheet('branch_new'));
    }

    document.querySelectorAll('[data-edit-branch]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = Number(btn.getAttribute('data-edit-branch'));
        const branch = state.branchesList.find((b) => Number(b.id) === id);
        if (branch) openSheet('branch_edit', { branch });
      });
    });

    document.querySelectorAll('[data-open-float]').forEach((btn) => {
      btn.addEventListener('click', () => {
        openSheet('float_adjust', { branch_id: btn.getAttribute('data-open-float') });
      });
    });

    const newVendorBtn = document.getElementById('btn-new-vendor');
    if (newVendorBtn) {
      newVendorBtn.addEventListener('click', () => openSheet('vendor_new'));
    }

    document.querySelectorAll('[data-edit-vendor]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = Number(btn.getAttribute('data-edit-vendor'));
        const vendor = state.vendorsList.find((v) => Number(v.id) === id);
        if (vendor) openSheet('vendor_edit', { vendor });
      });
    });
  }

  function snapshotTxFormDraft() {
    if (!state.sheet || state.sheet.type !== 'tx_create') return {};
    const d = { ...state.sheet.data };
    const gv = (id) => {
      const el = document.getElementById(id);
      return el ? el.value : undefined;
    };
    if (gv('tx-branch-select') !== undefined) d.branch_id = gv('tx-branch-select');
    if (gv('tx-phone') !== undefined) d.customer_phone = gv('tx-phone');
    if (gv('tx-cname') !== undefined) d.customer_name = gv('tx-cname');
    if (gv('tx-rnet') !== undefined) d.receiver_network = gv('tx-rnet');
    if (gv('tx-fee') !== undefined) d.fee_charged = gv('tx-fee');
    if (gv('tx-sname') !== undefined) d.sender_name = gv('tx-sname');
    if (gv('tx-sphone') !== undefined) d.sender_phone = gv('tx-sphone');
    if (gv('tx-amount') !== undefined) d.amount = gv('tx-amount');
    if (gv('tx-id') !== undefined) d.telco_tx_id = gv('tx-id');
    if (gv('tx-ref') !== undefined) d.reference = gv('tx-ref');
    const chk = document.getElementById('tx-cashout-ok');
    if (chk) d.cashout_allowed_confirmed = chk.checked;
    return d;
  }

  function bindSheetEvents() {
    const backdrop = document.getElementById('sheet-backdrop');
    const closeBtn = document.getElementById('btn-sheet-close');
    if (backdrop) backdrop.addEventListener('click', closeSheet);
    if (closeBtn) closeBtn.addEventListener('click', closeSheet);

    if (!state.sheet) return;

    document.querySelectorAll('[data-tx-tab]').forEach((b) => {
      b.addEventListener('click', () => {
        const draft = snapshotTxFormDraft();
        draft.tab = b.getAttribute('data-tx-tab');
        openSheet('tx_create', draft);
      });
    });

    document.querySelectorAll('[data-sample-sms]').forEach((b) => {
      b.addEventListener('click', () => {
        const box = document.getElementById('sms-raw-box');
        if (box) {
          box.value = window.MomoSmsParser.generateSampleSms(b.getAttribute('data-sample-sms'));
        }
      });
    });

    const parseBtn = document.getElementById('btn-parse-sms');
    if (parseBtn) {
      parseBtn.addEventListener('click', async () => {
        const box = document.getElementById('sms-raw-box');
        const raw = box ? box.value.trim() : '';
        if (!raw) {
          toast('Paste a telco SMS message first', true);
          return;
        }
        const parsed = window.MomoSmsParser.parse(raw);
        if (!parsed.matched) {
          toast('Could not recognize standard telco SMS fields; fill manually.', true);
          return;
        }
        openSheet('tx_create', {
          ...state.sheet.data,
          tab: 'form',
          tx_type: parsed.tx_type || state.sheet.data.tx_type || 'DEPOSIT',
          network: parsed.network || state.sheet.data.network || 'MTN',
          receiver_network: parsed.network || 'MTN',
          customer_phone: parsed.customer_phone || '',
          customer_name: parsed.customer_name || '',
          amount: parsed.amount || '',
          telco_tx_id: parsed.telco_tx_id || '',
          reference: parsed.reference || '',
          raw_sms: raw,
          entry_mode: 'SMS_PARSED',
          cashout_allowed_confirmed: parsed.tx_type === 'WITHDRAWAL',
        });
      });
    }

    document.querySelectorAll('[data-pick-type]').forEach((b) => {
      b.addEventListener('click', () => {
        const draft = snapshotTxFormDraft();
        draft.tx_type = b.getAttribute('data-pick-type');
        openSheet('tx_create', draft);
      });
    });

    document.querySelectorAll('[data-pick-net]').forEach((b) => {
      b.addEventListener('click', () => {
        const draft = snapshotTxFormDraft();
        const picked = b.getAttribute('data-pick-net');
        draft.network = picked;
        draft.receiver_network = picked;
        openSheet('tx_create', draft);
      });
    });

    const txBranchSel = document.getElementById('tx-branch-select');
    if (txBranchSel) {
      txBranchSel.addEventListener('change', () => {
        const draft = snapshotTxFormDraft();
        draft.branch_id = txBranchSel.value;
        openSheet('tx_create', draft);
      });
    }

    const phoneInput = document.getElementById('tx-phone');
    if (phoneInput) {
      phoneInput.addEventListener('input', () => {
        const detected = window.MomoSmsParser.detectNetworkFromPhone(phoneInput.value);
        if (detected && detected !== state.sheet.data.network && phoneInput.value.replace(/\D+/g, '').length === 3) {
          const draft = snapshotTxFormDraft();
          draft.network = detected;
          draft.receiver_network = detected;
          openSheet('tx_create', draft);
          const refocused = document.getElementById('tx-phone');
          if (refocused) {
            refocused.focus();
            refocused.setSelectionRange(refocused.value.length, refocused.value.length);
          }
        }
      });
    }

    const updateImpactBar = () => {
      const bar = document.getElementById('tx-impact-bar');
      if (!bar || !state.sheet) return;
      const amtEl = document.getElementById('tx-amount');
      const feeEl = document.getElementById('tx-fee');
      const amt = parseFloat(amtEl ? amtEl.value : 0) || 0;
      const fee = state.sheet.data.tx_type === 'TRANSFER' ? (parseFloat(feeEl ? feeEl.value : 0) || 0) : 0;
      const cDelta = state.sheet.data.tx_type === 'WITHDRAWAL' ? -amt : (amt + fee);
      const eDelta = state.sheet.data.tx_type === 'WITHDRAWAL' ? +amt : -amt;
      const net = state.sheet.data.network || 'MTN';
      bar.innerHTML = `
        <span class="${cDelta >= 0 ? 'cash-plus' : 'cash-minus'} tabular">Drawer Cash: ${cDelta >= 0 ? '+' : ''}${money(cDelta)}</span>
        <span class="${eDelta >= 0 ? 'cash-plus' : 'cash-minus'} tabular">${esc(net)} Float: ${eDelta >= 0 ? '+' : ''}${money(eDelta)}</span>
      `;
    };
    const amtInput = document.getElementById('tx-amount');
    const feeInput = document.getElementById('tx-fee');
    if (amtInput) amtInput.addEventListener('input', updateImpactBar);
    if (feeInput) feeInput.addEventListener('input', updateImpactBar);

    const recordForm = document.getElementById('form-record-tx');
    if (recordForm) {
      recordForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = Object.fromEntries(new FormData(recordForm).entries());
        const payload = {
          ...fd,
          tx_type: state.sheet.data.tx_type || 'DEPOSIT',
          network: state.sheet.data.network || 'MTN',
          raw_sms: state.sheet.data.raw_sms || '',
          entry_mode: state.sheet.data.entry_mode || 'MANUAL',
          cashout_allowed_confirmed: document.getElementById('tx-cashout-ok')
            ? document.getElementById('tx-cashout-ok').checked
            : false,
        };
        try {
          const res = await api('POST', '/api/transactions', payload);
          toast(res.message);
          closeSheet();
          await refreshCurrentView();
          render();
        } catch (err) {
          toast(err.message, true);
        }
      });
    }

    const voidReqForm = document.getElementById('form-void-request');
    if (voidReqForm) {
      voidReqForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(voidReqForm);
        const txId = fd.get('tx_id');
        try {
          const res = await api('POST', `/api/transactions/${txId}/void-request`, { reason: fd.get('reason') });
          toast(res.message);
          closeSheet();
          await refreshCurrentView();
          render();
        } catch (err) {
          toast(err.message, true);
        }
      });
    }

    document.querySelectorAll('#sheet-host [data-resolve-void]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const txId = btn.getAttribute('data-resolve-void');
        const decision = btn.getAttribute('data-decision');
        try {
          const res = await api('POST', `/api/transactions/${txId}/void-resolve`, { decision });
          toast(res.message);
          closeSheet();
          await refreshCurrentView();
          render();
        } catch (err) {
          toast(err.message, true);
        }
      });
    });

    const closeShiftForm = document.getElementById('form-close-shift');
    if (closeShiftForm) {
      closeShiftForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = Object.fromEntries(new FormData(closeShiftForm).entries());
        try {
          const res = await api('POST', `/api/shifts/${fd.shift_id}/close`, fd);
          toast(res.message, res.status === 'FLAGGED');
          closeSheet();
          await refreshCurrentView();
          render();
        } catch (err) {
          toast(err.message, true);
        }
      });
    }

    const newBranchForm = document.getElementById('form-new-branch');
    if (newBranchForm) {
      newBranchForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = Object.fromEntries(new FormData(newBranchForm).entries());
        try {
          const res = await api('POST', '/api/branches', fd);
          toast(res.message);
          closeSheet();
          await refreshCurrentView();
          render();
        } catch (err) {
          toast(err.message, true);
        }
      });
    }

    const editBranchForm = document.getElementById('form-edit-branch');
    if (editBranchForm) {
      editBranchForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = Object.fromEntries(new FormData(editBranchForm).entries());
        const payload = {
          name: fd.name,
          location: fd.location,
          wallets: {
            MTN: { merchant_number: fd.num_MTN, account_name: fd.acc_MTN },
            TELECEL: { merchant_number: fd.num_TELECEL, account_name: fd.acc_TELECEL },
            AT: { merchant_number: fd.num_AT, account_name: fd.acc_AT },
          },
        };
        try {
          const res = await api('PUT', `/api/branches/${fd.branch_id}`, payload);
          toast(res.message);
          closeSheet();
          await refreshCurrentView();
          render();
        } catch (err) {
          toast(err.message, true);
        }
      });
    }

    const bucketSel = document.getElementById('float-bucket');
    const netWrap = document.getElementById('float-net-wrap');
    if (bucketSel && netWrap) {
      bucketSel.addEventListener('change', () => {
        netWrap.style.display = bucketSel.value === 'ECASH' ? 'block' : 'none';
      });
    }

    const floatForm = document.getElementById('form-float-adjust');
    if (floatForm) {
      floatForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = Object.fromEntries(new FormData(floatForm).entries());
        try {
          const res = await api('POST', `/api/branches/${fd.branch_id}/float`, fd);
          toast(res.message);
          closeSheet();
          await refreshCurrentView();
          render();
        } catch (err) {
          toast(err.message, true);
        }
      });
    }

    const newVendorForm = document.getElementById('form-new-vendor');
    if (newVendorForm) {
      newVendorForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = Object.fromEntries(new FormData(newVendorForm).entries());
        try {
          const res = await api('POST', '/api/vendors', fd);
          toast(res.message);
          closeSheet();
          await refreshCurrentView();
          render();
        } catch (err) {
          toast(err.message, true);
        }
      });
    }

    const editVendorForm = document.getElementById('form-edit-vendor');
    if (editVendorForm) {
      editVendorForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = Object.fromEntries(new FormData(editVendorForm).entries());
        try {
          const res = await api('PUT', `/api/vendors/${fd.vendor_id}`, fd);
          toast(res.message);
          closeSheet();
          await refreshCurrentView();
          render();
        } catch (err) {
          toast(err.message, true);
        }
      });
    }
  }

  document.addEventListener('DOMContentLoaded', boot);
})();
