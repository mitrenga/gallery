<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gallery</title>
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
