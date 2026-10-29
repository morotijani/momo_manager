<?php

declare(strict_types=1);

/** @var string $baseUrl */
/** @var string $csrfToken */
/** @var string|null $dbError */
$assetBase = rtrim($baseUrl, '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <meta name="theme-color" content="#0F172A">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>MoMo Manager — Enterprise &amp; Branch Ledger</title>
  <link rel="manifest" href="<?= htmlspecialchars($assetBase . '/public/manifest.json', ENT_QUOTES) ?>">
  <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($assetBase . '/public/assets/icons/icon.svg', ENT_QUOTES) ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetBase . '/public/assets/css/app.css', ENT_QUOTES) ?>">
</head>
<body>
  <div class="studio-stage">
    <div class="device-shell">
      <div class="phone-screen">
        <!-- iPhone 16 Top Status Bar & Dynamic Island (Desktop/Laptop Preview) -->
        <div class="ios-status-bar">
          <span id="ios-clock" class="tabular">09:41</span>
          <div class="dynamic-island" aria-hidden="true"></div>
          <div class="ios-status-icons" aria-hidden="true">
            <span>5G</span>
            <svg width="15" height="11" viewBox="0 0 18 12" fill="currentColor">
              <rect x="0.5" y="1" width="14" height="10" rx="2.2" stroke="currentColor" fill="none" stroke-width="1.3"/>
              <rect x="2.2" y="2.7" width="10.6" height="6.6" rx="1"/>
              <rect x="15.5" y="4" width="1.8" height="4" rx="0.8"/>
            </svg>
          </div>
        </div>

        <!-- Contained Toast Notifications -->
        <div id="toast-stack" class="toast-stack"></div>

        <!-- Reactive Mobile App Mount -->
        <main id="app-mount" style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden;"></main>

        <!-- Contained Slide-Up Bottom Sheets -->
        <div id="sheet-host"></div>

        <!-- iPhone 16 Home Indicator -->
        <div class="ios-home-indicator" aria-hidden="true"></div>
      </div>
    </div>
  </div>

  <script>
    window.APP_CONFIG = <?= json_encode([
        'baseUrl' => $assetBase,
        'csrfToken' => $csrfToken,
        'dbError' => $dbError,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="<?= htmlspecialchars($assetBase . '/public/assets/js/sms-parser.js', ENT_QUOTES) ?>"></script>
  <script src="<?= htmlspecialchars($assetBase . '/public/assets/js/app.js', ENT_QUOTES) ?>"></script>
</body>
</html>
