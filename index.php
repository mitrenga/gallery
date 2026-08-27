<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gallery</title>
<meta name="theme-color" content="#0F5FA8">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" href="images/app-icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" sizes="192x192" href="images/app-icon-192x192.png">
<link rel="manifest" href="manifest.webmanifest">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<header>
  <h1 id="page-title">Gallery</h1>
  <nav id="breadcrumb"></nav>
  <span id="status"></span>
  <button id="logout" hidden title="Sign out">&#x23FB;</button>
  <button id="fs-toggle" title="Fullscreen">&#x26F6;</button>
</header>
<main id="content">
  <p class="loading">Loading…</p>
</main>
<script src="app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>"></script>
</body>
</html>
