// app.js
// dev@cadrexa.com

const App = (() => {

  const state = {
    lang:         'zh',
    weightedAvg:  null, 
    avgSpeed:     null,
    testCount:    0,
    history:      [],     // [{speed, ts}]
    stability:    null,
    rhythmDelay:  0.3,

    // Adaptive pace
    paceMult:     1.0, 
    pauseCount:   0, 
    lastFlowMs:   0,   

    // Speed test
    testRunning:  false,
    testStart:    null,
    testTimer:    null,

    // Write mode
    tokens:       [],
    currentIdx:   0,
    writeTimer:   null,
    writeStart:   null,
    pauseStart:   null,
    pausedMs:     0,
    isPaused:     false,
    focusMode:    false,
  };

  const $  = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => [...c.querySelectorAll(s)];

  function fmt(seconds) {
    const m = Math.floor(seconds / 60).toString().padStart(2, '0');
    const s = Math.floor(seconds % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
  }

  function showToast(msg) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2400);
  }

  async function api(action, data = {}) {
    const body = new FormData();
    body.append('action', action);
    for (const [k, v] of Object.entries(data)) body.append(k, v);
    const r = await fetch('api.php', { method: 'POST', body });
    return r.json();
  }

  // ══════════════════════════════════════════════════════════
  // LANGUAGE

  function initLangSwitcher() {
    $$('.lang-switcher button').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.lang === state.lang);
      btn.addEventListener('click', () => setLang(btn.dataset.lang));
    });
    $$('.lang-card').forEach(c => c.addEventListener('click', () => setLang(c.dataset.lang)));
  }
  async function setLang(lang) {
    await api('set_lang', { lang });
    window.location.search = `?lang=${lang}`;
  }

  // ══════════════════════════════════════════════════════════
  // NAVIGATION

  function showPage(id) {
    $$('.page').forEach(p => p.classList.remove('active'));
    const page = $(`#page-${id}`);
    if (page) page.classList.add('active');
    if (id === 'home')     renderHomeStats();
    if (id === 'stats')    loadStatsPage();
    if (id === 'settings') loadChangelog();
  }

  // ══════════════════════════════════════════════════════════
  // HOME STATS

  function renderHomeStats() {
    const avg  = state.weightedAvg || state.avgSpeed;
    $('#stat-avg').textContent       = avg        ? avg.toFixed(2)        : '—';
    $('#stat-count').textContent     = state.testCount;
    const stab = state.stability;
    const stabEl = $('#stat-stability');
    if (stabEl) {
      stabEl.textContent = stab !== null ? stab : '—';
      stabEl.style.color = stabilityColor(stab);
    }
    const warn = $('#no-speed-warn');
    if (warn) warn.style.display = avg ? 'none' : 'flex';
  }

  function stabilityColor(s) {
    if (s === null) return 'var(--text-dim)';
    if (s >= 85) return 'var(--green)';
    if (s >= 65) return 'var(--gold)';
    if (s >= 40) return '#e08a4e';
    return 'var(--red)';
  }

  // ══════════════════════════════════════════════════════════
  // SPEED TEST

  function initSpeedPage() {
    const startBtn  = $('#speed-start-btn');
    const doneBtn   = $('#speed-done-btn');
    const againBtn  = $('#speed-again-btn');
    const clearBtn  = $('#speed-clear-btn');
    const timerEl   = $('#speed-timer');
    const resultsEl = $('#speed-results');

    doneBtn.disabled = true;

    startBtn.addEventListener('click', () => {
      state.testRunning = true;
      state.testStart   = Date.now();
      startBtn.disabled = true;
      doneBtn.disabled  = false;
      timerEl.textContent = '00:00';
      resultsEl.classList.remove('visible');
      state.testTimer = setInterval(() => {
        timerEl.textContent = fmt((Date.now() - state.testStart) / 1000);
      }, 100);
    });

    doneBtn.addEventListener('click', async () => {
      if (!state.testRunning) return;
      clearInterval(state.testTimer);
      state.testRunning = false;

      const elapsed   = (Date.now() - state.testStart) / 1000;
      const sampleTxt = $('#sample-text').textContent.trim();
      // Per character for Chinese, per word for others
      const count = state.lang === 'zh'
        ? sampleTxt.replace(/\s/g, '').length
        : sampleTxt.trim().split(/\s+/).length;
      const speed = count / elapsed;

      const res = await api('save_speed', { speed });
      if (res.ok) {
        state.weightedAvg = res.weightedAvg;
        state.avgSpeed    = res.avgSpeed;
        state.testCount   = res.testCount;
        state.stability   = res.stability;
        if (res.history) state.history = res.history;

        $('#result-last').textContent = res.lastSpeed.toFixed(2);
        $('#result-avg').textContent  = (res.weightedAvg || res.avgSpeed).toFixed(2);
        const stabEl = $('#result-stability');
        if (stabEl) {
          stabEl.textContent  = res.stability !== null ? res.stability : '—';
          stabEl.style.color  = stabilityColor(res.stability);
        }
        resultsEl.classList.add('visible');
        renderHistoryBars();
        renderHomeStats();
        startBtn.disabled = false;
        doneBtn.disabled  = true;
      }
    });

    againBtn.addEventListener('click', () => {
      resultsEl.classList.remove('visible');
      timerEl.textContent = '00:00';
      startBtn.disabled   = false;
      doneBtn.disabled    = true;
    });

    clearBtn.addEventListener('click', async () => {
      await api('clear_history');
      state.weightedAvg = null;
      state.avgSpeed    = null;
      state.testCount   = 0;
      state.history     = [];
      state.stability   = null;
      $('#result-last').textContent = '—';
      $('#result-avg').textContent  = '—';
      if ($('#result-stability')) $('#result-stability').textContent = '—';
      timerEl.textContent = '00:00';
      resultsEl.classList.remove('visible');
      renderHistoryBars();
      renderHomeStats();
      showToast(window.CF_LANG.history_cleared);
    });
  }

  function renderHistoryBars() {
    const c = $('#history-bars');
    if (!c || !state.history.length) { if (c) c.innerHTML = ''; return; }
    // history items are {speed, ts} objects or bare numbers — normalise
    const speeds = state.history.map(h => parseFloat(h.speed ?? h)).filter(v => !isNaN(v));
    if (!speeds.length) return;
    const max   = Math.max(...speeds);
    c.innerHTML = speeds.slice(-20).map(v => {
      const barH = Math.max(4, Math.round((v / max) * 40));
      return `<div class="history-bar" style="height:${barH}px" title="${v.toFixed(2)}"></div>`;
    }).join('');
  }

  // ══════════════════════════════════════════════════════════
  // STATS PAGE + CANVAS CHART

  async function loadStatsPage() {
    const res = await api('get_stats');
    if (!res.ok) return;

    const history   = res.history || [];
    const speeds    = history.map(h => h.speed);
    const n         = speeds.length;

    // Top metrics
    const fv = v => v != null ? parseFloat(v).toFixed(2) : '—';
    if ($('#sm-count'))    $('#sm-count').textContent    = n;
    if ($('#sm-wavg'))     $('#sm-wavg').textContent     = fv(res.weightedAvg);
    if ($('#sm-best'))     $('#sm-best').textContent     = fv(res.maxSpeed);
    const stab = res.stability;
    const stabEl = $('#sm-stability');
    if (stabEl) {
      stabEl.textContent = stab !== null ? stab : '—';
      stabEl.style.color = stabilityColor(stab);
    }

    // Stability bar
    const ssBar  = $('#ss-bar');
    const ssVal  = $('#ss-val');
    const ssDesc = $('#ss-desc');
    if (stab !== null && ssBar) {
      ssBar.style.width          = stab + '%';
      ssBar.style.background     = stabilityColor(stab);
      ssVal.textContent          = stab;
      ssVal.style.color          = stabilityColor(stab);
      const labels               = window.CF_LANG.stability_labels;
      const idx                  = stab >= 85 ? 4 : stab >= 65 ? 3 : stab >= 40 ? 2 : stab >= 20 ? 1 : 0;
      ssDesc.textContent         = labels[idx];
    }

    // Chart
    const noDataMsg = $('#no-data-msg');
    const canvas    = $('#speed-chart');
    if (n < 2) {
      if (noDataMsg) noDataMsg.style.display = '';
      if (canvas)    canvas.style.display    = 'none';
      return;
    }
    if (noDataMsg) noDataMsg.style.display = 'none';
    if (canvas)    canvas.style.display    = '';

    drawChart(canvas, speeds);
  }

  function drawChart(canvas, speeds) {
    const dpr    = window.devicePixelRatio || 1;
    const W      = canvas.parentElement.clientWidth;
    const H      = 220;
    canvas.width  = W * dpr;
    canvas.height = H * dpr;
    canvas.style.width  = W + 'px';
    canvas.style.height = H + 'px';

    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);

    const pad   = { top: 20, right: 20, bottom: 36, left: 46 };
    const cw    = W - pad.left - pad.right;
    const ch    = H - pad.top  - pad.bottom;
    const n     = speeds.length;
    const minV  = Math.min(...speeds) * 0.85;
    const maxV  = Math.max(...speeds) * 1.1;

    const xOf = i => pad.left + (i / (n - 1)) * cw;
    const yOf = v => pad.top + ch - ((v - minV) / (maxV - minV)) * ch;

    // Grid
    ctx.strokeStyle = 'rgba(255,255,255,0.05)';
    ctx.lineWidth   = 1;
    for (let i = 0; i <= 4; i++) {
      const y = pad.top + (ch / 4) * i;
      ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(pad.left + cw, y); ctx.stroke();
      const val = maxV - ((maxV - minV) / 4) * i;
      ctx.fillStyle    = 'rgba(232,224,208,0.3)';
      ctx.font         = `10px 'JetBrains Mono', monospace`;
      ctx.textAlign    = 'right';
      ctx.fillText(val.toFixed(1), pad.left - 6, y + 4);
    }

    // X axis labels (every ~5 points)
    ctx.textAlign = 'center';
    const step = Math.max(1, Math.floor(n / 8));
    for (let i = 0; i < n; i += step) {
      ctx.fillStyle = 'rgba(232,224,208,0.3)';
      ctx.fillText(i + 1, xOf(i), H - pad.bottom + 16);
    }

    // Moving average (dashed gold dim)
    const maWindow = Math.min(5, Math.ceil(n / 3));
    const maLine   = [];
    for (let i = 0; i < n; i++) {
      const slice = speeds.slice(Math.max(0, i - maWindow + 1), i + 1);
      maLine.push(slice.reduce((a, b) => a + b, 0) / slice.length);
    }
    ctx.setLineDash([4, 4]);
    ctx.strokeStyle = 'rgba(201,168,76,0.4)';
    ctx.lineWidth   = 1.5;
    ctx.beginPath();
    maLine.forEach((v, i) => i === 0 ? ctx.moveTo(xOf(i), yOf(v)) : ctx.lineTo(xOf(i), yOf(v)));
    ctx.stroke();
    ctx.setLineDash([]);

    // Main line (smooth bezier)
    const grad = ctx.createLinearGradient(pad.left, 0, pad.left + cw, 0);
    grad.addColorStop(0, '#c9a84c');
    grad.addColorStop(1, '#e8c85a');
    ctx.strokeStyle = grad;
    ctx.lineWidth   = 2;
    ctx.beginPath();
    ctx.moveTo(xOf(0), yOf(speeds[0]));
    for (let i = 1; i < n; i++) {
      const cpx = (xOf(i - 1) + xOf(i)) / 2;
      ctx.bezierCurveTo(cpx, yOf(speeds[i - 1]), cpx, yOf(speeds[i]), xOf(i), yOf(speeds[i]));
    }
    ctx.stroke();

    // Fill under line
    const fillGrad = ctx.createLinearGradient(0, pad.top, 0, pad.top + ch);
    fillGrad.addColorStop(0, 'rgba(201,168,76,0.15)');
    fillGrad.addColorStop(1, 'rgba(201,168,76,0)');
    ctx.fillStyle = fillGrad;
    ctx.beginPath();
    ctx.moveTo(xOf(0), yOf(speeds[0]));
    for (let i = 1; i < n; i++) {
      const cpx = (xOf(i - 1) + xOf(i)) / 2;
      ctx.bezierCurveTo(cpx, yOf(speeds[i - 1]), cpx, yOf(speeds[i]), xOf(i), yOf(speeds[i]));
    }
    ctx.lineTo(xOf(n - 1), pad.top + ch);
    ctx.lineTo(xOf(0), pad.top + ch);
    ctx.closePath();
    ctx.fill();

    // Data points
    const maxI = speeds.indexOf(Math.max(...speeds));
    const minI = speeds.indexOf(Math.min(...speeds));
    speeds.forEach((v, i) => {
      const x  = xOf(i);
      const y  = yOf(v);
      const isMax = i === maxI;
      const isMin = i === minI;
      ctx.beginPath();
      ctx.arc(x, y, isMax || isMin ? 5 : 3, 0, Math.PI * 2);
      ctx.fillStyle   = isMax ? '#6dbf8e' : isMin ? '#e05a4e' : '#0d0d0d';
      ctx.strokeStyle = isMax ? '#6dbf8e' : isMin ? '#e05a4e' : '#c9a84c';
      ctx.lineWidth   = 2;
      ctx.fill();
      ctx.stroke();
    });
  }

  // ══════════════════════════════════════════════════════════
  // WRITE MODE — tokenize

  function tokenize(text, lang) {
    if (lang === 'zh') return text.split('').filter(c => c.trim().length > 0);
    return text.trim().split(/\s+/).filter(Boolean);
  }

  // ══════════════════════════════════════════════════════════
  // WRITE MODE — adaptive pace

  function onPauseEvent() {
    state.pauseCount++;
    // 5% pace reduction after 2+ pauses, up to 30% max
    if (state.pauseCount >= 2) {
      state.paceMult = Math.max(0.70, state.paceMult - 0.05);
    }
    updatePaceDisplay();
  }

  function tryRecoverPace() {
    // Gradually recover pace after 60s of continuous flow
    state.paceMult = Math.min(1.0, state.paceMult + 0.002);
    updatePaceDisplay();
  }

  function updatePaceDisplay() {
    const el = $('#pace-value');
    if (!el) return;
    el.textContent = `×${state.paceMult.toFixed(2)}`;
    el.style.color = state.paceMult < 0.85 ? 'var(--red)' : state.paceMult < 0.95 ? 'var(--gold)' : 'var(--green)';
  }

  // ══════════════════════════════════════════════════════════
  // WRITE MODE — main

  function initWritePage() {
    const beginBtn    = $('#write-begin-btn');
    const pauseBtn    = $('#write-pause-btn');
    const finishBtn   = $('#write-finish-btn');
    const focusBtn    = $('#write-focus-btn');
    const textInput   = $('#write-text-input');
    const inputArea   = $('#write-input-area');
    const display     = $('#writing-display');
    const completedEl = $('#completed-overlay');

    beginBtn.addEventListener('click', () => {
      const rawText = textInput.value.trim();
      if (!rawText) { showToast(window.CF_LANG.text_empty); return; }
      const speed = state.weightedAvg || state.avgSpeed;
      if (!speed)  { showToast(window.CF_LANG.no_speed_set); return; }

      state.tokens     = tokenize(rawText, state.lang);
      state.currentIdx = 0;
      state.pausedMs   = 0;
      state.isPaused   = false;
      state.writeStart = Date.now();
      state.paceMult   = 1.0;
      state.pauseCount = 0;

      inputArea.style.display = 'none';
      display.classList.add('active');
      completedEl.classList.remove('active');

      renderWriteFrame();
      startWriteTimer();
      updatePaceDisplay();
    });

    pauseBtn.addEventListener('click', () => {
      if (state.isPaused) {
        state.pausedMs   += Date.now() - state.pauseStart;
        state.isPaused    = false;
        pauseBtn.textContent = window.CF_LANG.pause;
        startWriteTimer();
      } else {
        state.pauseStart  = Date.now();
        state.isPaused    = true;
        pauseBtn.textContent = window.CF_LANG.resume;
        clearInterval(state.writeTimer);
        onPauseEvent();
      }
    });

    finishBtn.addEventListener('click', finishWrite);

    // Focus mode
    if (focusBtn) {
      focusBtn.addEventListener('click', toggleFocusMode);
    }
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && state.focusMode) exitFocusMode();
    });
    document.addEventListener('fullscreenchange', () => {
      if (!document.fullscreenElement && state.focusMode) exitFocusMode();
    });

    function finishWrite() {
      clearInterval(state.writeTimer);
      exitFocusMode();
      display.classList.remove('active');
      inputArea.style.display = '';
      textInput.value = '';
    }

    $$('#restart-btn, #restart-btn-2').forEach(b => b.addEventListener('click', () => {
      completedEl.classList.remove('active');
      finishWrite();
    }));
  }

  function toggleFocusMode() {
    if (state.focusMode) exitFocusMode();
    else enterFocusMode();
  }

  function enterFocusMode() {
    state.focusMode = true;
    document.body.classList.add('focus-mode');
    if (document.documentElement.requestFullscreen) {
      document.documentElement.requestFullscreen().catch(() => {});
    }
    const btn = $('#write-focus-btn');
    if (btn) btn.textContent = '⊡';
    const hint = $('#focus-exit-hint');
    if (hint) hint.style.opacity = '1';
  }

  function exitFocusMode() {
    if (!state.focusMode) return;
    state.focusMode = false;
    document.body.classList.remove('focus-mode');
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
    const btn = $('#write-focus-btn');
    if (btn) btn.textContent = '⊞';
    const hint = $('#focus-exit-hint');
    if (hint) hint.style.opacity = '0';
  }

  function startWriteTimer() {
    clearInterval(state.writeTimer);
    state.writeTimer = setInterval(tickWrite, 150);
  }

  function tickWrite() {
    if (state.isPaused) return;

    const elapsed        = (Date.now() - state.writeStart - state.pausedMs) / 1000;
    const baseSpeed      = state.weightedAvg || state.avgSpeed || 1;
    const effectiveSpeed = baseSpeed * state.paceMult;
    const delayedElapsed = Math.max(0, elapsed - state.rhythmDelay);
    const displayProg    = effectiveSpeed * delayedElapsed;
    const theoretical    = effectiveSpeed * elapsed;   // for completion check
    const newIdx         = Math.min(Math.floor(displayProg), state.tokens.length - 1);

    // Update timer
    const timerEl = $('#write-elapsed');
    if (timerEl) timerEl.textContent = fmt(elapsed);

    // Progress bar
    const fill = $('#progress-fill');
    if (fill) fill.style.width = Math.min(100, ((newIdx + 1) / state.tokens.length) * 100) + '%';

    // Adaptive pace recovery
    tryRecoverPace();

    if (newIdx !== state.currentIdx) {
      state.currentIdx = newIdx;
      renderWriteFrame();
    }

    // Completion
    if (theoretical >= state.tokens.length) {
      clearInterval(state.writeTimer);
      setTimeout(() => $('#completed-overlay').classList.add('active'), 400);
    }
  }

  function renderWriteFrame() {
    const tokens = state.tokens;
    const idx    = state.currentIdx;

    const currentEl = $('#current-char');
    if (currentEl) {
      currentEl.style.opacity = '0';
      setTimeout(() => {
        currentEl.textContent   = tokens[idx] || '';
        currentEl.style.opacity = '1';
      }, 60);
    }

    const upcomingEl = $('#upcoming-text');
    if (upcomingEl) {
      const upcoming = tokens.slice(idx + 1, idx + 31);
      const sep = state.lang === 'zh' ? '' : ' ';
      upcomingEl.innerHTML = upcoming.map((t, i) => {
        const cls = i === 0 ? 'next' : i < 4 ? 'soon' : '';
        return `<span class="token ${cls}">${t}</span>${sep}`;
      }).join('');
    }
  }

  // ══════════════════════════════════════════════════════════
  // DELAY SLIDER

  function initDelaySlider() {
    const slider  = $('#delay-slider');
    const display = $('#delay-value');
    if (!slider) return;
    slider.addEventListener('input', () => {
      state.rhythmDelay = parseFloat(slider.value);
      display.textContent = state.rhythmDelay.toFixed(1) + 's';
    });
  }

  // ── Clipboard fallback (works over plain HTTP) ─────────────
  function fallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (_) {}
    document.body.removeChild(ta);
    showToast(ok ? window.CF_LANG.copied : text); // if copy fails, show the code itself
  }

  // ══════════════════════════════════════════════════════════
  // SETTINGS PAGE

  const UPDATE_URL = 'update-proxy.php';

  function hashStr(str) {
    let h = 5381;
    for (let i = 0; i < str.length; i++) h = ((h << 5) + h) ^ str.charCodeAt(i);
    return (h >>> 0).toString(36);
  }

  function initSettingsPage() {
    const clearBtn = $('#settings-clear-btn');
    if (clearBtn) {
      clearBtn.addEventListener('click', async () => {
        await api('clear_history');
        state.weightedAvg = null;
        state.avgSpeed    = null;
        state.testCount   = 0;
        state.history     = [];
        state.stability   = null;
        renderHomeStats();
        renderHistoryBars();
        const countEl = $('#settings-record-count');
        if (countEl) countEl.textContent = `0`;
        showToast(window.CF_LANG.history_cleared);
      });
    }

    // Share: Generate pace code
    const generateBtn = $('#share-generate-btn');
    const codeWrap    = $('#share-code-wrap');
    const codeOutput  = $('#share-code-output');
    const copyBtn     = $('#share-copy-btn');

    if (generateBtn) {
      generateBtn.addEventListener('click', async () => {
        generateBtn.disabled = true;
        const res = await api('export_profile');
        generateBtn.disabled = false;
        if (!res.ok) {
          showToast(window.CF_LANG.no_data_export);
          return;
        }
        codeOutput.value = res.code;
        codeWrap.style.display = 'flex';
        codeOutput.select();
      });
    }

    if (copyBtn) {
      copyBtn.addEventListener('click', () => {
        if (!codeOutput || !codeOutput.value) return;
        const text = codeOutput.value;

        // Modern Clipboard API (requires HTTPS)
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text)
            .then(() => showToast(window.CF_LANG.copied))
            .catch(() => fallbackCopy(text));
        } else {
          fallbackCopy(text);
        }
      });
    }

    // Share: Import pace code
    const importInput = $('#share-import-input');
    const importBtn   = $('#share-import-btn');

    if (importBtn) {
      importBtn.addEventListener('click', async () => {
        const code = (importInput?.value || '').trim();
        if (!code) return;
        importBtn.disabled = true;
        const res = await api('import_profile', { code });
        importBtn.disabled = false;
        if (res.ok) {
          // Merge into local state
          state.weightedAvg = res.weightedAvg;
          state.avgSpeed    = res.avgSpeed;
          state.testCount   = res.count;
          state.stability   = res.stability;
          if (res.history)  state.history = res.history;
          renderHomeStats();
          renderHistoryBars();
          const countEl = $('#settings-record-count');
          if (countEl) countEl.textContent = `${state.testCount}`;
          importInput.value = '';
          showToast(window.CF_LANG.import_ok);
        } else {
          showToast(window.CF_LANG.import_fail);
        }
      });

      // Allow pressing Enter in the import field
      if (importInput) {
        importInput.addEventListener('keydown', e => {
          if (e.key === 'Enter') importBtn.click();
        });
      }
    }
  }

  let changelogLoaded = false;
  async function loadChangelog() {
    if (changelogLoaded) return;
    changelogLoaded = true;
    const body = $('#changelog-body');
    const dot  = $('#changelog-dot');
    if (!body) return;
    try {
      const res  = await fetch(UPDATE_URL, { cache: 'no-cache' });
      if (!res.ok) throw new Error('fetch failed');
      const html = await res.text();
      const hash = hashStr(html.trim());
      body.innerHTML = html;
      if (dot) dot.style.display = 'none';
      const lastHash = localStorage.getItem('cf_update_hash');
      if (lastHash && lastHash !== hash) {
        const label = body.closest('.settings-group').querySelector('.settings-group-label');
        if (label) label.insertAdjacentHTML('beforeend', '<span class="new-badge">NEW</span>');
      }
      localStorage.setItem('cf_update_hash', hash);
    } catch (_) {
      body.innerHTML = `<p style="color:var(--text-dim);font-family:var(--mono);font-size:0.8rem">
        ${state.lang === 'zh' ? '加载失败，请检查网络' : 'Failed to load. Check your connection.'}
      </p>`;
      if (dot) dot.style.display = 'none';
      changelogLoaded = false;
    }
  }

  // ══════════════════════════════════════════════════════════
  // LANDSCAPE WARNING

  function initLandscapeWarn() {
    const warn = $('#landscape-warn');
    if (!warn) return;
    const check = () => {
      const mobile    = window.innerWidth <= 768;
      const landscape = window.innerWidth > window.innerHeight;
      warn.classList.toggle('active', mobile && landscape);
    };
    window.addEventListener('resize', check);
    window.addEventListener('orientationchange', () => setTimeout(check, 100));
    check();
  }

  // ══════════════════════════════════════════════════════════
  // INIT

  async function init() {
    const res = await api('get_data');
    if (res.ok) {
      state.weightedAvg = res.weightedAvg || res.avgSpeed;
      state.avgSpeed    = res.avgSpeed;
      state.testCount   = res.count || res.testCount || 0;
      state.stability   = res.stability;
      state.history     = res.history || [];
    }

    state.lang = document.documentElement.lang || 'zh';

    initLangSwitcher();
    renderHomeStats();
    renderHistoryBars();
    initSpeedPage();
    initWritePage();
    initDelaySlider();
    initLandscapeWarn();
    initSettingsPage();

    // Global nav delegation
    document.addEventListener('click', e => {
      const btn = e.target.closest('[data-nav]');
      if (btn) showPage(btn.dataset.nav);
    });
  }

  return { init };
})();

document.addEventListener('DOMContentLoaded', App.init);