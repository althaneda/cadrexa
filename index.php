<?php
session_start();

$allowed = ['zh', 'en'];
$autoDetected = false;

if (isset($_GET['lang']) && in_array($_GET['lang'], $allowed)) {
    $lang = $_GET['lang'];
    $_SESSION['lang'] = $lang;
    setcookie('cf_lang', $lang, time() + 86400 * 365, '/');
} elseif (isset($_SESSION['lang'])) {
    $lang = $_SESSION['lang'];
} elseif (isset($_COOKIE['cf_lang']) && in_array($_COOKIE['cf_lang'], $allowed)) {
    $lang = $_COOKIE['cf_lang'];
} else {
    // Auto-detect from Accept-Language header
    $acceptLang = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $lang = (strpos($acceptLang, 'zh') !== false) ? 'zh' : 'en';
    $_SESSION['lang'] = $lang;
    setcookie('cf_lang', $lang, time() + 86400 * 365, '/');
    $autoDetected = true;
}

require_once 'includes/lang.php';

// Load stats from session for initial render
$weightedAvg = $_SESSION['avg_speed']  ?? null;
$avgSpeed    = $weightedAvg;
$testCount   = $_SESSION['test_count'] ?? 0;
$stability   = null; // computed client-side after get_data

$initialPage = 'home';

function stabilityColor($s): string {
    if ($s === null) return 'var(--text-dim)';
    if ($s >= 85)   return 'var(--green)';
    if ($s >= 65)   return 'var(--gold)';
    if ($s >= 40)   return '#e08a4e';
    return 'var(--red)';
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Cadrexa is a smart transcription assistant that keeps you on pace with adaptive rhythm highlighting, speed measurement, training analytics, and pace sharing.">
  <meta name="keywords" content="transcription tool, copy assistant, writing speed, rhythm highlight, typing trainer, pace tracker, Cadrexa, focus writing">
  <title>Cadrexa — <?= t('tagline') ?></title>
  <link rel="stylesheet" href="assets/css/style.css?latest">
  <link rel="icon" type="image/svg+xml" href="favicon.ico">
</head>
<body>
<div class="app-wrap">

  <!--  Header  -->
  <header class="site-header" id="site-header">
    <a href="?lang=<?= $lang ?>" class="logo" data-nav="home">
      <span class="logo-text">Cadrexa</span>
      <span class="logo-sub"><?= t('tagline') ?></span>
    </a>
    <div class="header-actions">
      <div class="lang-switcher">
        <button data-lang="zh"<?= $lang==='zh' ? ' class="active"' : '' ?>>中文</button>
        <button data-lang="en"<?= $lang==='en' ? ' class="active"' : '' ?>>EN</button>
      </div>
    </div>
  </header>

  <!-- Page: Language Select  (kept for manual override)      -->
  <section class="page lang-select-page<?= $initialPage==='lang-select' ? ' active' : '' ?>" id="page-lang-select">
    <div class="brand-block">
      <div class="brand-name">Cadrexa</div>
      <div class="brand-tagline">Smart Transcription Assistant · 智能抄写辅助</div>
    </div>
    <div class="lang-options">
      <div class="lang-card<?= $lang==='zh' ? ' selected' : '' ?>" data-lang="zh"><span class="lang-flag">🇨🇳</span><span class="lang-name">中文</span></div>
      <div class="lang-card<?= $lang==='en' ? ' selected' : '' ?>" data-lang="en"><span class="lang-flag">🇬🇧</span><span class="lang-name">English</span></div>
    </div>
    <?php if ($autoDetected): ?>
    <p class="auto-lang-note">✦ <?= t('auto_lang_note') ?></p>
    <?php endif; ?>
  </section>

  <!--  Page: Home  -->
  <section class="page home-page<?= $initialPage==='home' ? ' active' : '' ?>" id="page-home">

    <div class="speed-summary">
      <div class="stat-item">
        <span class="stat-label"><?= t('weighted_avg') ?></span>
        <span class="stat-value mono" id="stat-avg"><?= $weightedAvg ? number_format($weightedAvg, 2) : '—' ?></span>
        <span class="stat-unit mid"><?= t('chars_per_sec') ?></span>
      </div>
      <div class="stat-item">
        <span class="stat-label"><?= t('stability') ?></span>
        <span class="stat-value mono" id="stat-stability" style="color:<?= stabilityColor($stability) ?>"><?= $stability !== null ? $stability : '—' ?></span>
        <span class="stat-unit mid">/ 100</span>
      </div>
      <div class="stat-item">
        <span class="stat-label"><?= t('test_count') ?></span>
        <span class="stat-value mono" id="stat-count"><?= $testCount ?></span>
        <span class="stat-unit mid"><?= t('test_times') ?></span>
      </div>
    </div>

    <?php if (!$weightedAvg): ?>
    <div id="no-speed-warn" class="warn-bar">
      <span class="warn-icon">⚠</span>
      <span><?= t('no_speed_set') ?></span>
      <button class="btn btn-ghost btn-sm" style="margin-left:auto" data-nav="speed"><?= t('go_measure') ?></button>
    </div>
    <?php else: ?>
    <div id="no-speed-warn" style="display:none"></div>
    <?php endif; ?>

    <div class="menu-cards">
      <div class="menu-card" data-nav="speed">
        <div class="menu-card-icon">⏱</div>
        <div class="menu-card-title"><?= t('measure_speed') ?></div>
        <div class="menu-card-desc"><?= $lang === 'zh' ? '通过抄写示例文本建立你的速度基准' : 'Establish your writing speed baseline.' ?></div>
        <div class="menu-card-arrow">→ <?= t('measure_speed') ?></div>
      </div>
      <div class="menu-card" data-nav="write">
        <div class="menu-card-icon">✒</div>
        <div class="menu-card-title"><?= t('start_writing') ?></div>
        <div class="menu-card-desc"><?= $lang === 'zh' ? '进入专注抄写模式，自适应节奏高亮' : 'Enter focus mode with adaptive rhythm.' ?></div>
        <div class="menu-card-arrow">→ <?= t('start_writing') ?></div>
      </div>
      <div class="menu-card" data-nav="stats">
        <div class="menu-card-icon">📈</div>
        <div class="menu-card-title"><?= t('stats_title') ?></div>
        <div class="menu-card-desc"><?= $lang === 'zh' ? '速度曲线、稳定度趋势与成长记录' : 'Speed curve, stability trend & growth.' ?></div>
        <div class="menu-card-arrow">→ <?= t('stats_title') ?></div>
      </div>
      <div class="menu-card" data-nav="settings">
        <div class="menu-card-icon">⚙</div>
        <div class="menu-card-title"><?= $lang === 'zh' ? '设置' : 'Settings' ?></div>
        <div class="menu-card-desc"><?= $lang === 'zh' ? '语言、数据管理与更新日志' : 'Language, data management & changelog.' ?></div>
        <div class="menu-card-arrow">→ <?= $lang === 'zh' ? '设置' : 'Settings' ?></div>
      </div>
    </div>

  </section>

  <!--  Page: Settings  -->
  <section class="page settings-page" id="page-settings">

    <div class="page-title">
      <h2><?= $lang === 'zh' ? '设置' : 'Settings' ?></h2>
      <button class="btn btn-ghost btn-sm" data-nav="home">← <?= t('back') ?></button>
    </div>

    <!-- Language -->
    <div class="settings-group">
      <div class="settings-group-label"><?= $lang === 'zh' ? '语言 · Language' : 'Language · 语言' ?></div>
      <div class="settings-row">
        <div class="lang-switcher" style="border:1px solid var(--border2);border-radius:6px">
          <button data-lang="zh"<?= $lang==='zh' ? ' class="active"' : '' ?>>中文</button>
          <button data-lang="en"<?= $lang==='en' ? ' class="active"' : '' ?>>English</button>
        </div>
      </div>
    </div>

    <!-- Data -->
    <div class="settings-group">
      <div class="settings-group-label"><?= $lang === 'zh' ? '数据管理' : 'Data Management' ?></div>
      <div class="settings-row">
        <div class="settings-row-info">
          <span><?= $lang === 'zh' ? '历史测速记录' : 'Speed history' ?></span>
          <span class="settings-row-sub" id="settings-record-count"><?= $testCount ?> <?= t('test_times') ?></span>
        </div>
        <button class="btn btn-danger btn-sm" id="settings-clear-btn"><?= t('clear_history') ?></button>
      </div>
    </div>

    <!-- Speed Sharing -->
    <div class="settings-group">
      <div class="settings-group-label"><?= t('share_title') ?></div>

      <!-- Desc + Export -->
      <div class="settings-row share-export-section">
        <div class="share-col">
          <p class="settings-group-desc"><?= t('share_desc') ?></p>
          <button class="btn btn-ghost btn-sm" id="share-generate-btn">⬆ <?= t('generate_code') ?></button>
          <div class="share-code-wrap" id="share-code-wrap" style="display:none">
            <input type="text" class="share-code-input" id="share-code-output" readonly>
            <button class="btn btn-ghost btn-sm share-copy-btn" id="share-copy-btn"><?= t('copy_code') ?></button>
          </div>
        </div>
      </div>

      <!-- Import -->
      <div class="settings-row share-import-section">
        <div class="share-col">
          <div class="settings-group-sublabel"><?= t('import_title') ?></div>
          <div class="share-import-inputs">
            <input type="text" class="share-code-input" id="share-import-input"
                   placeholder="<?= t('import_placeholder') ?>">
            <button class="btn btn-gold btn-sm" id="share-import-btn">⬇ <?= t('import_btn') ?></button>
          </div>
        </div>
      </div>
    </div>

    <!-- Changelog -->
    <div class="settings-group settings-group-changelog">
      <div class="settings-group-label">
        <?= $lang === 'zh' ? '更新日志' : 'Changelog' ?>
        <span class="changelog-loading-dot" id="changelog-dot"></span>
      </div>
      <div class="changelog-body" id="changelog-body">
        <div class="update-loading"><?= $lang === 'zh' ? '加载中…' : 'Loading…' ?></div>
      </div>
    </div>

  </section>

  <!-- Page: Speed Measure -->
  <section class="page speed-page" id="page-speed">

    <div class="page-title">
      <h2><?= t('speed_title') ?></h2>
      <button class="btn btn-ghost btn-sm" data-nav="home">← <?= t('back') ?></button>
    </div>

    <p style="color:var(--text-mid);font-size:0.9rem"><?= t('speed_desc') ?></p>

    <div class="sample-block">
      <div class="sample-label"><?= $lang === 'zh' ? '示例文本' : 'Sample Text' ?></div>
      <div class="sample-text" id="sample-text"><?= t('sample_text') ?></div>
    </div>

    <div class="speed-controls">
      <div class="timer-display mono" id="speed-timer">00:00</div>
      <button class="btn btn-gold" id="speed-start-btn"><?= t('start') ?></button>
      <button class="btn btn-ghost" id="speed-done-btn" disabled><?= t('done') ?></button>
    </div>

    <div class="speed-results" id="speed-results">
      <div class="sample-label"><?= $lang === 'zh' ? '测量结果' : 'Results' ?></div>
      <div class="result-grid">
        <div class="stat-item">
          <span class="stat-label"><?= t('last_speed') ?></span>
          <span class="stat-value mono" id="result-last">—</span>
          <span class="stat-unit mid"><?= t('chars_per_sec') ?></span>
        </div>
        <div class="stat-item">
          <span class="stat-label"><?= t('weighted_avg') ?></span>
          <span class="stat-value mono" id="result-avg"><?= $weightedAvg ? number_format($weightedAvg, 2) : '—' ?></span>
          <span class="stat-unit mid"><?= t('chars_per_sec') ?></span>
        </div>
        <div class="stat-item">
          <span class="stat-label"><?= t('stability_score') ?></span>
          <span class="stat-value mono" id="result-stability" style="color:<?= stabilityColor($stability) ?>"><?= $stability !== null ? $stability : '—' ?></span>
          <span class="stat-unit mid">/ 100</span>
        </div>
      </div>

      <div class="speed-history" style="margin-top:20px">
        <div class="history-label"><?= $lang === 'zh' ? '速度历史' : 'Speed History' ?></div>
        <div class="history-bars" id="history-bars"></div>
      </div>

      <div style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" id="speed-again-btn"><?= t('again') ?></button>
        <button class="btn btn-danger btn-sm" id="speed-clear-btn"><?= t('clear_history') ?></button>
        <button class="btn btn-gold btn-sm" data-nav="write" style="margin-left:auto"><?= t('start_writing') ?> →</button>
      </div>
    </div>

  </section>

  <!--  Page: Stats  -->
  <section class="page stats-page" id="page-stats">

    <div class="page-title">
      <h2><?= t('stats_title') ?></h2>
      <button class="btn btn-ghost btn-sm" data-nav="home">← <?= t('back') ?></button>
    </div>

    <!-- Top metrics row -->
    <div class="stats-metrics" id="stats-metrics">
      <div class="metric-card">
        <div class="metric-label"><?= t('test_count') ?></div>
        <div class="metric-value mono" id="sm-count">—</div>
        <div class="metric-unit"><?= t('test_times') ?></div>
      </div>
      <div class="metric-card">
        <div class="metric-label"><?= t('weighted_avg') ?></div>
        <div class="metric-value mono" id="sm-wavg">—</div>
        <div class="metric-unit"><?= t('chars_per_sec') ?></div>
      </div>
      <div class="metric-card">
        <div class="metric-label"><?= t('best_speed') ?></div>
        <div class="metric-value mono" id="sm-best">—</div>
        <div class="metric-unit"><?= t('chars_per_sec') ?></div>
      </div>
      <div class="metric-card stability-card">
        <div class="metric-label"><?= t('stability') ?></div>
        <div class="metric-value mono" id="sm-stability">—</div>
        <div class="metric-unit">/ 100</div>
      </div>
    </div>

    <!-- Chart -->
    <div class="chart-wrap">
      <div class="chart-header">
        <span class="chart-title"><?= $lang === 'zh' ? '速度成长曲线' : 'Speed Growth Curve' ?></span>
        <div class="chart-legend">
          <span class="legend-item"><span class="legend-dot" style="background:var(--gold)"></span><?= $lang === 'zh' ? '实测速度' : 'Speed' ?></span>
          <span class="legend-item"><span class="legend-line"></span><?= $lang === 'zh' ? '加权趋势' : 'Trend' ?></span>
        </div>
      </div>
      <div id="no-data-msg" class="no-data-msg" style="display:none"><?= t('no_data_yet') ?></div>
      <canvas id="speed-chart" class="speed-chart" height="220"></canvas>
    </div>

    <!-- Stability gauge -->
    <div class="stability-section">
      <div class="stability-header">
        <span><?= t('stability_score') ?></span>
        <span class="stability-score-val" id="ss-val">—</span>
      </div>
      <div class="stability-bar-track">
        <div class="stability-bar-fill" id="ss-bar"></div>
      </div>
      <div class="stability-desc" id="ss-desc"></div>
    </div>

  </section>

  <!--  Page: Write  -->
  <section class="page write-page" id="page-write">

    <div class="page-title">
      <h2><?= t('write_title') ?></h2>
      <button class="btn btn-ghost btn-sm" data-nav="home">← <?= t('back') ?></button>
    </div>

    <!-- Input area -->
    <div id="write-input-area" class="write-input-area">
      <textarea class="text-input" id="write-text-input"
        placeholder="<?= t('write_placeholder') ?>" rows="5"></textarea>

      <!-- Rhythm delay control -->
      <div class="delay-control">
        <div class="delay-label">
          <span><?= $lang === 'zh' ? '节奏缓冲' : 'Rhythm Buffer' ?></span>
          <span class="delay-hint"><?= $lang === 'zh' ? '高亮比理论进度延迟（留出书写时间）' : 'Highlight lags behind pace' ?></span>
        </div>
        <div class="delay-slider-row">
          <input type="range" id="delay-slider" min="0" max="1.5" step="0.1" value="0.3" class="delay-slider">
          <span class="delay-value mono" id="delay-value">0.3s</span>
        </div>
        <div class="delay-marks">
          <span><?= $lang === 'zh' ? '紧凑' : 'Tight' ?></span>
          <span><?= $lang === 'zh' ? '宽松' : 'Relaxed' ?></span>
        </div>
      </div>

      <div style="display:flex;gap:10px;align-items:center">
        <button class="btn btn-gold" id="write-begin-btn"><?= t('begin') ?> ✒</button>
        <span style="font-family:var(--mono);font-size:0.75rem;color:var(--text-dim)">
          <?= $lang === 'zh' ? '自适应节奏已启用' : 'Adaptive rhythm enabled' ?>
        </span>
      </div>
    </div>

    <!-- Writing display -->
    <div id="writing-display" class="writing-display">

      <div class="write-header" id="write-header">
        <div class="write-timer">
          <?= t('time_elapsed') ?> <span class="mono" id="write-elapsed">00:00</span>
        </div>
        <!-- Adaptive pace indicator -->
        <div class="pace-indicator" id="pace-indicator">
          <span class="pace-label"><?= t('pace_label') ?></span>
          <span class="pace-value mono" id="pace-value">×1.00</span>
        </div>
        <div class="write-controls">
          <button class="btn btn-ghost btn-sm" id="write-focus-btn" title="<?= t('focus_mode') ?>">⊞</button>
          <button class="btn btn-ghost btn-sm" id="write-pause-btn"><?= t('pause') ?></button>
          <button class="btn btn-danger btn-sm" id="write-finish-btn"><?= t('finish') ?></button>
        </div>
      </div>

      <div class="progress-track">
        <div class="progress-fill" id="progress-fill"></div>
      </div>

      <div class="current-block" id="current-block">
        <div class="current-label" id="current-label"><?= t('current_highlight') ?></div>
        <div class="current-char" id="current-char">·</div>
        <!-- focus mode exit hint -->
        <div class="focus-exit-hint" id="focus-exit-hint"><?= $lang === 'zh' ? '按 ESC 退出专注' : 'Press ESC to exit' ?></div>
      </div>

      <div class="upcoming-block" id="upcoming-block">
        <div class="upcoming-label"><?= t('upcoming') ?></div>
        <div class="upcoming-text" id="upcoming-text"></div>
      </div>

    </div>

  </section>

  <!--  Completed overlay  -->
  <div class="completed-overlay" id="completed-overlay">
    <div class="completed-icon">✓</div>
    <div class="completed-title"><?= t('completed') ?></div>
    <div class="completed-msg"><?= t('completed_msg') ?></div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center">
      <button class="btn btn-ghost" id="restart-btn">← <?= t('back') ?></button>
      <button class="btn btn-gold" id="restart-btn-2">↺ <?= t('restart') ?></button>
    </div>
  </div>

  <?php // endif (lang guard removed - lang is always set via auto-detect or user choice) ?>

  <footer class="site-footer" id="site-footer">
    Cadrexa &nbsp;·&nbsp; <?= $lang === 'zh' ? '专注抄写，节奏为王' : 'Focus. Flow. Write.' ?>
  </footer>

</div><!-- .app-wrap -->

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- Mobile landscape warning -->
<div class="landscape-warn" id="landscape-warn">
  <div class="landscape-icon">📱</div>
  <div class="landscape-text"><?= $lang === 'zh' ? '请旋转至竖屏以获得最佳体验' : 'Please rotate to portrait for the best experience' ?></div>
</div>

<script>
window.CF_LANG = <?= json_encode([
  'history_cleared'  => t('history_cleared'),
  'text_empty'       => t('text_empty'),
  'no_speed_set'     => t('no_speed_set'),
  'pause'            => t('pause'),
  'resume'           => t('resume'),
  'focus_mode'       => t('focus_mode'),
  'exit_focus'       => t('exit_focus'),
  'import_ok'        => t('import_ok'),
  'import_fail'      => t('import_fail'),
  'no_data_export'   => t('no_data_export'),
  'copied'           => t('copied'),
  'stability_labels' => $lang === 'zh'
    ? ['非常不稳定','需要提升','逐渐稳定','相当稳定','极度稳定']
    : ['Very unstable','Needs work','Getting steady','Quite stable','Rock solid'],
]) ?>;
</script>
<script src="assets/js/app.js?latest"></script>
</body>
</html>
