// Simple SPA gallery – album overview and thumbnail grid.
// Data is loaded progressively from getData.php (album list, then album
// contents on demand), navigation via hash (#album=001).

let DATA = null;
let GALLERY_TITLE = 'Gallery';   // overridden by "title" from config.json (via whoami)

const content = document.getElementById('content');
const breadcrumb = document.getElementById('breadcrumb');
const pageTitle = document.getElementById('page-title');
const statusEl = document.getElementById('status');

async function fetchJson(url, opts) {
  const res = await fetch(url, { cache: 'no-store', ...opts });   // always fresh data, never from cache
  if (!res.ok) throw new Error(`${url}: HTTP ${res.status}`);
  return res.json();
}

async function init() {
  window.addEventListener('hashchange', render);
  try {
    // authentication check – allowed IPs pass automatically
    const who = await fetchJson('getData.php?action=whoami');
    if (who.title) {
      GALLERY_TITLE = who.title;
      pageTitle.textContent = GALLERY_TITLE;   // visible already on the login screen
      document.title = GALLERY_TITLE;
    }
    if (!who.auth) {
      showLogin();
      return;
    }
    updateLogoutButton(who.user);
  } catch (e) {
    content.innerHTML = '<p class="loading">Server is not responding (getData.php).</p>';
    return;
  }
  loadData();
}

// ---- login ----
// The logout button only makes sense for password logins;
// internal accounts (@ip:…, @noconfig) would immediately sign in again.
function updateLogoutButton(user) {
  const btn = document.getElementById('logout');
  btn.hidden = !user || user.startsWith('@');
  btn.title = `Sign out ${user}`;
  document.body.classList.toggle('has-logout', !btn.hidden);   // shifts album controls to the left
}

document.getElementById('logout').addEventListener('click', async () => {
  try { await fetchJson('getData.php?action=logout'); } catch (e) { /* session ends either way */ }
  location.reload();   // shows the login dialog again, or auto-signs in by IP
});

function showLogin() {
  content.innerHTML = '';
  const dlg = document.createElement('div');
  dlg.id = 'login';
  dlg.innerHTML =
    '<form class="login-box">' +
    '<h2>Sign in</h2>' +
    '<input type="text" name="user" placeholder="Username" autocomplete="username" required>' +
    '<input type="password" name="password" placeholder="Password" autocomplete="current-password" required>' +
    '<button type="submit">Sign in</button>' +
    '<p class="login-error"></p>' +
    '</form>';
  document.body.appendChild(dlg);
  const form = dlg.querySelector('form');
  form.user.focus();
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const errEl = dlg.querySelector('.login-error');
    errEl.textContent = '';
    try {
      await fetchJson('getData.php?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user: form.user.value, password: form.password.value }),
      });
      dlg.remove();
      updateLogoutButton(form.user.value);
      loadData();
    } catch (err) {
      errEl.textContent = 'Invalid username or password';
      form.password.value = '';
      form.password.focus();
    }
  });
}

async function loadData() {
  try {
    // the album list carries title, counts and cover – the overview is ready
    // with a single request; album contents load when opened (ensureAlbum)
    statusEl.textContent = 'Loading album list…';
    const list = (await fetchJson('getData.php?action=albums')).albums;
    DATA = { albums: list.map(a => ({ ...a, items: [], loaded: false, loading: null })) };
    statusEl.textContent = '';
    render();
  } catch (e) {
    statusEl.textContent = '';
    content.innerHTML = '<p class="loading">Failed to load gallery data (getData.php).</p>';
  }
}

// fetches album contents if not loaded yet (concurrent callers share one fetch)
function ensureAlbum(album) {
  if (album.loaded) return Promise.resolve(album);
  if (!album.loading) {
    album.loading = fetchJson('getData.php?action=album&id=' + encodeURIComponent(album.id))
      .then(d => {
        album.items = d.items;
        album.loaded = true;
        return album;
      })
      .finally(() => { album.loading = null; });
  }
  return album.loading;
}

// re-render only the view affected by the freshly loaded album
function renderIfAffected(album) {
  const m = location.hash.match(/^#album=(.+)$/);
  if (!m || decodeURIComponent(m[1]) === album.id) render();
}

function render() {
  const m = location.hash.match(/^#album=(.+)$/);
  if (m) {
    const album = DATA.albums.find(a => a.id === decodeURIComponent(m[1]));
    if (album) return renderAlbum(album);
  }
  renderOverview();
}

// ---- album overview ----
function renderOverview() {
  pageTitle.textContent = GALLERY_TITLE;
  breadcrumb.innerHTML = '';
  document.title = GALLERY_TITLE;

  const grid = document.createElement('div');
  grid.className = 'album-grid';

  for (const album of DATA.albums) {
    const card = document.createElement('a');
    card.className = 'album-card';
    card.href = '#album=' + encodeURIComponent(album.id);

    const counts = [];
    if (album.photos) counts.push(album.photos + ' photos');
    if (album.videos) counts.push(album.videos + ' videos');

    card.innerHTML =
      (album.cover
        ? `<img class="album-cover" src="${album.cover}" alt="" loading="lazy">`
        : `<div class="album-cover"></div>`) +
      `<div class="album-info"><h2>${album.title}</h2><p>${counts.join(', ') || 'empty'}</p></div>`;

    grid.appendChild(card);
  }

  // play all albums – sequentially, or in random album order
  const nav = document.createElement('div');
  nav.className = 'album-nav';
  nav.innerHTML =
    '<button class="an-play" title="Play all albums sequentially">&#9654;</button>' +
    '<button class="an-shuffle" title="Play albums in random order">&#9860;</button>';
  nav.querySelector('.an-play').addEventListener('click', () => startGlobalSlideshow(false));
  nav.querySelector('.an-shuffle').addEventListener('click', () => startGlobalSlideshow(true));

  content.replaceChildren(grid, nav);
}

// ---- album view ----
function renderAlbum(album) {
  pageTitle.textContent = album.title;
  breadcrumb.innerHTML = '';
  document.title = album.title + ' – ' + GALLERY_TITLE;

  if (!album.loaded) {
    content.innerHTML = '<p class="loading">Loading album contents…</p>';
    ensureAlbum(album)
      .then(() => renderIfAffected(album))
      .catch(() => { content.innerHTML = '<p class="loading">Failed to load the album.</p>'; });
    return;
  }

  const grid = document.createElement('div');
  grid.className = 'thumb-grid';

  album.items.forEach((item, index) => {
    const cell = document.createElement('a');
    cell.className = 'thumb' + (item.type === 'video' ? ' video' : '');
    cell.href = item.src;
    cell.addEventListener('click', e => {
      e.preventDefault();
      openLightbox(album, index);
    });

    // drag & drop ordering
    cell.draggable = true;
    cell.addEventListener('dragstart', e => {
      dragIndex = index;
      cell.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    cell.addEventListener('dragend', () => cell.classList.remove('dragging'));
    cell.addEventListener('dragover', e => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      cell.classList.add('drag-over');
    });
    cell.addEventListener('dragleave', () => cell.classList.remove('drag-over'));
    cell.addEventListener('drop', e => {
      e.preventDefault();
      moveItem(album, dragIndex, index);
    });

    if (item.thumb) {
      cell.innerHTML = `<img src="${item.thumb}" alt="${item.name}" loading="lazy">`;
    } else if (item.type === 'video') {
      // no ffmpeg thumbnail – the browser renders the first frame
      cell.innerHTML = `<video src="${item.src}#t=0.5" preload="metadata" muted playsinline></video>`;
    } else {
      cell.innerHTML = `<img src="${item.src}" alt="${item.name}" loading="lazy">`;
    }

    cell.innerHTML += `<span class="label">${item.name}</span>`;
    grid.appendChild(cell);
  });

  // album navigation – same controls as the photo detail
  const nav = document.createElement('div');
  nav.className = 'album-nav';
  nav.innerHTML =
    '<button class="an-close" title="Back to overview (Esc)">&times;</button>' +
    '<button class="an-play" title="Slideshow">&#9654;</button>' +
    '<button class="an-shuffle" title="Random slideshow">&#9860;</button>' +
    '<button class="an-prev" title="Previous album (←)">&#10094;</button>' +
    '<button class="an-next" title="Next album (→)">&#10095;</button>';
  nav.querySelector('.an-close').addEventListener('click', () => { location.hash = ''; });
  nav.querySelector('.an-play').addEventListener('click', () => startSlideshow(album, false));
  nav.querySelector('.an-shuffle').addEventListener('click', () => startSlideshow(album, true));
  nav.querySelector('.an-prev').addEventListener('click', () => stepAlbum(-1));
  nav.querySelector('.an-next').addEventListener('click', () => stepAlbum(1));

  content.replaceChildren(grid, nav);
}

// ---- drag & drop ordering ----
let dragIndex = null;

function moveItem(album, from, to) {
  if (from === null || from === to) return;
  const [moved] = album.items.splice(from, 1);
  album.items.splice(to, 0, moved);
  dragIndex = null;
  render();          // redraw the grid in the new order
  saveOrder(album);  // and persist it on the server
}

async function saveOrder(album) {
  try {
    await fetchJson('getData.php?action=saveOrder&id=' + encodeURIComponent(album.id), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(album.items.map(i => i.name)),
    });
    statusEl.textContent = 'Order saved';
  } catch (e) {
    statusEl.textContent = 'Failed to save the order!';
  }
  clearTimeout(saveOrder.timer);
  saveOrder.timer = setTimeout(() => { statusEl.textContent = ''; }, 2000);
}

// ---- lightbox (photo/video detail) ----
const lightbox = {
  album: null,
  index: 0,
  el: null,
};

function openLightbox(album, index) {
  lightbox.album = album;
  lightbox.index = index;

  if (!lightbox.el) {
    const el = document.createElement('div');
    el.id = 'lightbox';
    el.innerHTML =
      '<button class="lb-close" title="Close (Esc)">&times;</button>' +
      '<button class="lb-prev" title="Previous (←)">&#10094;</button>' +
      '<div class="lb-stage"></div>' +
      '<button class="lb-next" title="Next (→)">&#10095;</button>';
    el.querySelector('.lb-close').addEventListener('click', closeLightbox);
    el.querySelector('.lb-prev').addEventListener('click', () => stepLightbox(-1));
    el.querySelector('.lb-next').addEventListener('click', () => stepLightbox(1));
    // clicking outside the content closes the lightbox
    el.addEventListener('click', e => { if (e.target === el || e.target.classList.contains('lb-stage')) closeLightbox(); });
    document.body.appendChild(el);
    lightbox.el = el;
  }

  lightbox.el.classList.add('open');
  document.body.classList.add('no-scroll');
  showLightboxItem();
}

// transition types – a random one is picked on every switch (direction is drawn separately)
const TRANSITIONS = [
  'fade', 'slide', 'zoom', 'flip', 'blur', 'wipe',
  'iris', 'diag', 'slidev', 'spin', 'door', 'fall', 'stretch', 'bounce',
  'checker', 'cube', 'blinds', 'blindsv',
];
const TRANSITION_MS = 900;   // fallback for removing the old frame

function showLightboxItem() {
  const item = lightbox.album.items[lightbox.index];
  const stage = lightbox.el.querySelector('.lb-stage');

  clearTimeout(slideshow.timer);

  let el;
  if (item.type === 'video') {
    el = document.createElement('video');
    el.src = item.src;
    el.controls = el.autoplay = true;
    el.playsInline = true;
    if (slideshow.active) {
      el.addEventListener('ended', slideshowNext);
      // if the video fails to play, continue after 3 s
      el.addEventListener('error', () => { slideshow.timer = setTimeout(slideshowNext, 3000); });
    }
  } else {
    el = document.createElement('img');
    el.src = item.src;
    el.alt = item.name;
    if (slideshow.active) slideshow.timer = setTimeout(slideshowNext, SLIDESHOW_DELAY);
  }

  const old = stage.lastElementChild;
  stage.appendChild(el);   // the new frame renders above the old one

  const type = TRANSITIONS[Math.floor(Math.random() * TRANSITIONS.length)];
  if (old) {
    old.removeAttribute('class');   // drop classes from the previous transition, otherwise animations clash
    el.classList.add(`tr-${type}-in`);
    old.classList.add(`tr-${type}-out`);
    if (Math.random() < 0.5) { el.classList.add('tr-rev'); old.classList.add('tr-rev'); }   // random direction
    old.addEventListener('animationend', () => old.remove(), { once: true });
    setTimeout(() => old.remove(), TRANSITION_MS);   // fallback
  }

  // Ken Burns – slow photo drift during the slideshow (after the transition ends)
  if (slideshow.active && item.type !== 'video') {
    const kb = 'kb-' + (1 + Math.floor(Math.random() * 4));
    if (old) el.addEventListener('animationend', () => { el.className = kb; }, { once: true });
    else el.className = kb;
  }

  // preload the next frame – the transition never waits for the download
  if (slideshow.active) {
    slideshow.nextIndex = pickNextIndex();
    if (slideshow.nextIndex !== null) preloadItem(lightbox.album.items[slideshow.nextIndex]);
  } else {
    preloadItem(lightbox.album.items[(lightbox.index + 1) % lightbox.album.items.length]);
  }
}

function stepLightbox(dir) {
  const n = lightbox.album.items.length;
  lightbox.index = (lightbox.index + dir + n) % n;
  showLightboxItem();
}

function closeLightbox() {
  if (!lightbox.el) return;
  stopSlideshow();
  lightbox.el.classList.remove('open');
  lightbox.el.querySelector('.lb-stage').innerHTML = '';  // stops any playing video
  document.body.classList.remove('no-scroll');
  lightbox.album = null;
}

// ---- slideshow ----
const SLIDESHOW_DELAY = 5000;
const slideshow = {
  active: false,
  random: false,        // random photo order within the album
  global: false,        // move to the next album after the current one finishes
  globalRandom: false,  // pick the next album randomly
  nextIndex: undefined, // next frame drawn in advance (for preloading)
  timer: null,
};

function startSlideshow(album, random) {
  slideshow.active = true;
  slideshow.random = random;
  const start = random ? Math.floor(Math.random() * album.items.length) : 0;
  openLightbox(album, start);
}

// all-albums slideshow from the overview – album contents play in order
async function startGlobalSlideshow(randomAlbums) {
  const album = randomAlbums
    ? DATA.albums[Math.floor(Math.random() * DATA.albums.length)]
    : DATA.albums[0];
  statusEl.textContent = 'Loading album…';
  try { await ensureAlbum(album); } catch (e) { statusEl.textContent = 'Failed to load the album'; return; }
  statusEl.textContent = '';
  slideshow.active = true;
  slideshow.random = false;
  slideshow.global = true;
  slideshow.globalRandom = randomAlbums;
  location.hash = 'album=' + encodeURIComponent(album.id);   // background behind the lightbox
  openLightbox(album, 0);
}

// next frame chosen in advance – for preloading (null = end of album)
function pickNextIndex() {
  const n = lightbox.album.items.length;
  if (slideshow.random) {
    let i;
    do { i = Math.floor(Math.random() * n); } while (n > 1 && i === lightbox.index);
    return i;
  }
  if (slideshow.global && lightbox.index === n - 1) return null;   // the next album continues
  return (lightbox.index + 1) % n;
}

// background image download so the transition never waits for it
let preloader = null;
function preloadItem(item) {
  if (item && item.type === 'image') {
    preloader = new Image();
    preloader.src = item.src;
  }
}

function slideshowNext() {
  if (!slideshow.active || !lightbox.album) return;
  if (slideshow.nextIndex === null) {
    nextSlideshowAlbum();   // the album finished – move to the next one
    return;
  }
  lightbox.index = slideshow.nextIndex ?? (lightbox.index + 1) % lightbox.album.items.length;
  showLightboxItem();
}

async function nextSlideshowAlbum() {
  const albums = DATA.albums;
  let album;
  if (slideshow.globalRandom) {
    do { album = albums[Math.floor(Math.random() * albums.length)]; }
    while (albums.length > 1 && album === lightbox.album);
  } else {
    album = albums[(albums.indexOf(lightbox.album) + 1) % albums.length];
  }
  try { await ensureAlbum(album); } catch (e) { stopSlideshow(); return; }
  if (!slideshow.active) return;   // the user may have closed the slideshow meanwhile
  location.hash = 'album=' + encodeURIComponent(album.id);
  lightbox.album = album;
  lightbox.index = 0;
  showLightboxItem();
}

function stopSlideshow() {
  slideshow.active = false;
  slideshow.global = slideshow.globalRandom = false;
  slideshow.nextIndex = undefined;
  clearTimeout(slideshow.timer);
  slideshow.timer = null;
}

// ---- switching between albums (arrows in album view, outside the lightbox) ----
function currentAlbumIndex() {
  const m = location.hash.match(/^#album=(.+)$/);
  if (!m) return -1;
  return DATA.albums.findIndex(a => a.id === decodeURIComponent(m[1]));
}

function stepAlbum(dir) {
  const i = currentAlbumIndex();
  if (i === -1) return;
  const n = DATA.albums.length;
  location.hash = 'album=' + encodeURIComponent(DATA.albums[(i + dir + n) % n].id);
}

// ---- fullscreen ----
function toggleFullscreen() {
  if (document.fullscreenElement) document.exitFullscreen();
  else document.documentElement.requestFullscreen();
}

document.getElementById('fs-toggle').addEventListener('click', toggleFullscreen);

document.addEventListener('fullscreenchange', () => {
  const on = !!document.fullscreenElement;
  const btn = document.getElementById('fs-toggle');
  btn.title = on ? 'Exit fullscreen' : 'Fullscreen';
  btn.classList.toggle('active', on);
});

document.addEventListener('keydown', e => {
  if (!DATA) return;   // keys do nothing before login / data load

  // Esc in fullscreen lets the browser only exit fullscreen, closes nothing
  if (e.key === 'Escape' && document.fullscreenElement) return;

  if (lightbox.el && lightbox.el.classList.contains('open')) {
    // photo detail open – arrows switch photos
    if (e.key === 'Escape') closeLightbox();
    else if (e.key === 'ArrowLeft') stepLightbox(-1);
    else if (e.key === 'ArrowRight') stepLightbox(1);
  } else {
    // album view – arrows switch albums, Esc returns to the overview
    if (e.key === 'ArrowLeft') stepAlbum(-1);
    else if (e.key === 'ArrowRight') stepAlbum(1);
    else if (e.key === 'Escape' && currentAlbumIndex() !== -1) location.hash = '';
  }
});

init();
