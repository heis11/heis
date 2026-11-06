<?php
require_once __DIR__ . '/config.php';
$hv = ['hasKey' => TMDB_API_KEY !== '', 'region' => DEFAULT_REGION];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HEISVERSE</title>
<meta name="theme-color" content="#050505">
<link rel="icon" href="assets/icons/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<header class="top">
  <a class="brand" href="#home">HEISVERSE</a>
  <button class="burger" id="burger" aria-label="Menu" aria-expanded="false">Menu</button>
  <nav id="nav">
    <a href="#home" data-view="home">Home</a>
    <a href="#catalog" data-view="catalog">Catalog</a>
    <a href="#discover" data-view="discover">Discover</a>
    <a href="#free" data-view="free">Free films</a>
    <a href="#local" data-view="local">My PC</a>
    <a href="#watchlist" data-view="watchlist">Watchlist</a>
    <a href="#settings" data-view="settings">Settings</a>
  </nav>
  <form id="navSearch" class="search" role="search">
    <input id="navQ" type="search" placeholder="Search movies" aria-label="Search movies" maxlength="100">
  </form>
</header>
<div id="offline" class="offline" hidden>You are offline. Your PC library and saved watchlist still work. Online discovery needs internet.</div>

<main>
<section id="view-home" class="view">
  <div class="hero" id="hero">
    <div class="hero-in">
      <h1 id="heroTitle">Your films, wherever they live.</h1>
      <p id="heroText">Browse movies from around the world, watch free classics, and play your own video files offline.</p>
      <div class="actions" id="heroActions">
        <button class="btn red" data-go="local">Add videos from my PC</button>
        <button class="btn line" data-go="free">Browse free films</button>
      </div>
    </div>
  </div>
  <div id="homeNotice"></div>
  <div id="homeRows"></div>
</section>

<section id="view-catalog" class="view" hidden>
  <h2 class="vt">Catalog</h2>
  <p class="sub">Movies added by the HEISVERSE administrator. Each one was rights-checked and has a legal place to watch.</p>
  <form id="catForm" class="bar"><input id="catQ" type="search" placeholder="Search the catalog by title" maxlength="100"><button class="btn red">Search</button></form>
  <div class="chips" id="catChips"></div>
  <div id="catMsg"></div>
  <div class="grid" id="catGrid"></div>
  <div class="more"><button class="btn line" id="catMore" hidden>Load more</button></div>
</section>

<section id="view-discover" class="view" hidden>
  <h2 class="vt">Discover</h2>
  <div class="chips" id="chips" role="tablist"></div>
  <div id="discoverMsg"></div>
  <div class="grid" id="discoverGrid"></div>
  <div class="more"><button class="btn line" id="discoverMore" hidden>Load more</button></div>
</section>

<section id="view-free" class="view" hidden>
  <h2 class="vt">Free films</h2>
  <p class="sub">Feature films from the Internet Archive, mostly public domain. Each page lists its own rights details. Nothing here needs an account.</p>
  <form id="freeForm" class="bar"><input id="freeQ" type="search" placeholder="Search free films by title" maxlength="100"><button class="btn red">Search</button></form>
  <div id="freeMsg"></div>
  <div class="grid" id="freeGrid"></div>
  <div class="more"><button class="btn line" id="freeMore" hidden>Load more</button></div>
</section>

<section id="view-local" class="view" hidden>
  <h2 class="vt">My PC</h2>
  <div class="drop" id="drop">
    <p><b>Play videos stored on this computer.</b> Files stay on your PC. They are never uploaded anywhere, and playback works without Wi-Fi.</p>
    <div class="actions">
      <button class="btn red" id="pickFolder">Choose a folder</button>
      <button class="btn line" id="pickFiles">Choose video files</button>
    </div>
    <p class="hint">You can also drag a folder or files here. Browsers cannot reopen files after you close the tab, so choose your folder again next time. Your resume positions come back automatically.</p>
    <input type="file" id="folderInput" webkitdirectory multiple hidden>
    <input type="file" id="fileInput" multiple hidden accept="video/*,.mkv,.avi,.mov,.wmv,.flv,.m4v,.mpg,.mpeg,.ts,.m2ts,.3gp,.ogv">
  </div>
  <div class="tools">
    <input id="lq" type="search" placeholder="Search your library" aria-label="Search your library">
    <select id="lsort" aria-label="Sort">
      <option value="title">Title A to Z</option><option value="year">Year, newest</option><option value="size">Size, largest</option>
      <option value="added">Recently added</option><option value="modified">File date, newest</option>
    </select>
    <select id="ltype" aria-label="File type"><option value="">All types</option></select>
    <label class="chk"><input type="checkbox" id="ldup"> Duplicates only</label>
    <button class="btn line sm" id="lclear">Clear library</button>
  </div>
  <p class="stats" id="lstats"></p>
  <div class="grid" id="localGrid"></div>
</section>

<section id="view-watchlist" class="view" hidden>
  <h2 class="vt">Watchlist</h2>
  <div class="grid" id="watchGrid"></div>
  <div class="split"><h2 class="vt">Recently watched</h2><button class="btn line sm" id="clearRecent">Clear history</button></div>
  <div class="grid" id="recentGrid"></div>
</section>

<section id="view-settings" class="view" hidden></section>
</main>

<dialog id="dlg"><button class="x" aria-label="Close">Close</button><div id="dlgBody"></div></dialog>
<div id="toast" role="status" aria-live="polite"></div>

<script>window.HV = <?= json_encode($hv) ?>;</script>
<script src="js/script.js"></script>
</body>
</html>
