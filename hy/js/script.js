'use strict';
/* HEISVERSE: vanilla JS. Online data comes from api.php (TMDB) and archive.org.
   Local videos never leave this computer: they are played through temporary object URLs. */
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const store = {
  get(k, d) { try { const v = JSON.parse(localStorage.getItem('hv_' + k)); return v ?? d; } catch { return d; } },
  set(k, v) { try { localStorage.setItem('hv_' + k, JSON.stringify(v)); } catch { toast('Browser storage is full or blocked, so this was not saved.'); } },
  clear() { Object.keys(localStorage).filter(k => k.startsWith('hv_')).forEach(k => localStorage.removeItem(k)); }
};
const IMG = 'https://image.tmdb.org/t/p/';
const GENRES = [['Action', 28], ['Adventure', 12], ['Comedy', 35], ['Drama', 18], ['Horror', 27], ['Romance', 10749], ['Sci-Fi', 878],
  ['Animation', 16], ['Fantasy', 14], ['Thriller', 53], ['Documentaries', 99]];
const REGIONS = { RW: 'Rwanda', KE: 'Kenya', UG: 'Uganda', TZ: 'Tanzania', NG: 'Nigeria', GH: 'Ghana', ZA: 'South Africa', EG: 'Egypt',
  US: 'United States', GB: 'United Kingdom', CA: 'Canada', FR: 'France', DE: 'Germany', IN: 'India', BR: 'Brazil', AU: 'Australia' };
let prefs = Object.assign({ region: HV.region, resume: true }, store.get('prefs', {}));
const items = new Map();
const NOKEY = `<div class="notice">Online discovery needs a free TMDB key. Open <b>config.php</b>, paste your key, then refresh. Steps are in README.md. Free films and My PC work without it.</div>`;

/* ---------- helpers ---------- */
let toastTimer;
function toast(msg) { const t = $('#toast'); t.textContent = msg; t.classList.add('show'); clearTimeout(toastTimer); toastTimer = setTimeout(() => t.classList.remove('show'), 3800); }
const errHTML = m => `<div class="err">${esc(m)}</div>`;
const loadHTML = m => `<div class="load">${esc(m || 'Loading')}</div>`;
function fmtSize(b) { if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB'; if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB'; return Math.max(1, Math.round(b / 1024)) + ' KB'; }
function fmtTime(s) { s = Math.max(0, Math.floor(s || 0)); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60; return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(x).padStart(2, '0'); }
async function api(params) {
  let r;
  try { r = await fetch('api.php?' + new URLSearchParams(params)); } catch { throw new Error('Cannot reach api.php. Make sure Apache is running and you opened http://localhost/heisverse/'); }
  let j; try { j = await r.json(); } catch { throw new Error('api.php returned an unexpected reply. Check that PHP is running in XAMPP.'); }
  if (!r.ok || j.error) throw new Error(j.error || 'Request failed.');
  return j;
}
function tmdbItem(m) {
  const it = { key: 'tmdb:' + m.id, kind: 'tmdb', id: m.id, title: m.title, year: m.year, poster: m.poster ? IMG + 'w342' + m.poster : null,
    backdrop: m.backdrop ? IMG + 'w1280' + m.backdrop : null, rating: m.rating, genres: m.genres || [], overview: m.overview };
  items.set(it.key, it); return it;
}
function iaItem(d) {
  const it = { key: 'ia:' + d.identifier, kind: 'ia', id: d.identifier, title: d.title || d.identifier, year: String(d.year || '').slice(0, 4),
    poster: 'https://archive.org/services/img/' + encodeURIComponent(d.identifier), genres: [], overview: [].concat(d.description || '').join(' ') };
  items.set(it.key, it); return it;
}
function dbItem(m) {
  const it = { key: 'db:' + m.id, kind: 'db', id: m.id, title: m.title, year: m.year, poster: m.poster || null, backdrop: m.backdrop || null,
    rating: m.rating, genres: m.genres || [], overview: m.overview };
  items.set(it.key, it); return it;
}
function card(it) {
  const sub = [it.year, (it.genres || []).slice(0, 2).join(', ')].filter(Boolean).join(', ');
  return `<button class="card" data-key="${esc(it.key)}"><span class="pw">${it.poster ? `<img src="${esc(it.poster)}" alt="" loading="lazy" data-t="${esc(it.title)}">` : `<div class="noposter"><span>${esc(it.title)}</span></div>`}${it.rating ? `<span class="rate">${it.rating}</span>` : ''}</span><span class="ct"><b>${esc(it.title)}</b><small>${esc(sub || (it.kind === 'ia' ? 'Free film' : it.kind === 'db' ? 'In the catalog' : ''))}</small></span></button>`;
}
document.addEventListener('error', e => {
  const i = e.target; if (i.tagName !== 'IMG' || i.dataset.t === undefined) return;
  const d = document.createElement('div'), sp = document.createElement('span'); d.className = 'noposter'; sp.textContent = i.dataset.t; d.append(sp); i.replaceWith(d);
}, true);
const row = (title, its) => `<section class="row"><h2>${esc(title)}</h2><div class="strip">${its.map(card).join('')}</div></section>`;
const stripHTML = html => { const d = new DOMParser().parseFromString(String(html), 'text/html'); return (d.body.textContent || '').trim(); };

/* ---------- router ---------- */
const views = ['home', 'catalog', 'discover', 'free', 'local', 'watchlist', 'settings'];
const inited = {};
function go(v) {
  if (!views.includes(v)) v = 'home';
  views.forEach(n => $('#view-' + n).hidden = n !== v);
  $$('[data-view]').forEach(a => a.classList.toggle('on', a.dataset.view === v));
  $('#nav').classList.remove('open'); $('#burger').setAttribute('aria-expanded', 'false');
  const init = { home: initHome, catalog: initCatalog, discover: initDiscover, free: initFree, local: renderLocal, watchlist: renderWatch, settings: renderSettings };
  if (!inited[v]) { inited[v] = true; init[v](); } else if (v === 'watchlist') renderWatch();
  window.scrollTo(0, 0);
}
window.addEventListener('hashchange', () => go(location.hash.slice(1)));
$('#burger').onclick = () => { const o = $('#nav').classList.toggle('open'); $('#burger').setAttribute('aria-expanded', o); };
document.addEventListener('click', e => {
  const g = e.target.closest('[data-go]'); if (g) { location.hash = '#' + g.dataset.go; return; }
  const c = e.target.closest('[data-key]'); if (c) openItem(items.get(c.dataset.key));
});
function syncOnline() { $('#offline').hidden = navigator.onLine; }
addEventListener('online', syncOnline); addEventListener('offline', syncOnline); syncOnline();

/* ---------- dialog ---------- */
const dlg = $('#dlg'), dlgBody = $('#dlgBody'); let cleanup = null;
function openDlg(html, cls = '') { dlg.className = cls; dlgBody.innerHTML = html; if (!dlg.open) dlg.showModal(); }
dlg.addEventListener('close', () => { if (cleanup) { cleanup(); cleanup = null; } dlgBody.innerHTML = ''; if (document.fullscreenElement) document.exitFullscreen().catch(() => {}); });
dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
$('.x', dlg).onclick = () => dlg.close();

/* ---------- watchlist / recent ---------- */
const slim = it => ({ key: it.key, kind: it.kind, id: it.id, title: it.title, year: it.year, poster: it.poster, rating: it.rating || 0, genres: it.genres || [] });
const inWatch = key => store.get('watchlist', []).some(w => w.key === key);
function toggleWatch(it) {
  let w = store.get('watchlist', []);
  if (w.some(x => x.key === it.key)) { w = w.filter(x => x.key !== it.key); toast('Removed from watchlist.'); }
  else { w.unshift(slim(it)); toast('Added to watchlist.'); }
  store.set('watchlist', w); if (!$('#view-watchlist').hidden) renderWatch();
  return inWatch(it.key);
}
function addRecent(it) {
  const r = store.get('recent', []).filter(x => x.key !== it.key); r.unshift({ ...slim(it), ts: Date.now() });
  store.set('recent', r.slice(0, 24));
}
function bindWatchBtn(btn, it) {
  const paint = () => { btn.textContent = inWatch(it.key) ? 'Remove from watchlist' : 'Add to watchlist'; btn.classList.toggle('on', inWatch(it.key)); };
  paint(); btn.onclick = () => { toggleWatch(it); paint(); };
}
function renderWatch() {
  const w = store.get('watchlist', []), r = store.get('recent', []);
  [...w, ...r].forEach(x => { if (x.kind !== 'local') items.set(x.key, x); });
  const cardOf = x => x.kind === 'local'
    ? `<button class="card" data-gotolocal="${esc(x.title)}"><span class="pw"><div class="noposter"><span>${esc(x.title)}</span></div></span><span class="ct"><b>${esc(x.title)}</b><small>On your PC</small></span></button>` : card(x);
  $('#watchGrid').innerHTML = w.length ? w.map(cardOf).join('') : `<div class="empty">Your watchlist is empty. Open any movie and choose Add to watchlist.</div>`;
  $('#recentGrid').innerHTML = r.length ? r.map(cardOf).join('') : `<div class="empty">Nothing watched yet. Trailers, free films and your PC videos show up here.</div>`;
}
document.addEventListener('click', e => {
  const c = e.target.closest('[data-gotolocal]'); if (!c) return;
  $('#lq').value = c.dataset.gotolocal; location.hash = '#local'; setTimeout(renderLocal, 0);
});
$('#clearRecent').onclick = () => { store.set('recent', []); renderWatch(); };

/* ---------- open a movie ---------- */
function openItem(it) { if (!it) return; if (it.kind === 'tmdb') openTmdb(it); else if (it.kind === 'ia') openArchive(it); else if (it.kind === 'db') openCatalog(it); }
async function openTmdb(it) {
  openDlg(loadHTML('Loading movie details'));
  try { renderTmdb(await api({ action: 'detail', id: it.id, region: prefs.region }), it); }
  catch (e) { dlgBody.innerHTML = errHTML(e.message); }
}
function renderTmdb(d, base) {
  const it = Object.assign(base, { title: d.title, year: d.year });
  const pv = d.providers, names = a => (a || []).join(', ');
  const freeNames = pv ? [...pv.free, ...pv.ads] : [];
  let prov;
  if (pv) {
    prov = '';
    if (pv.free.length) prov += `<p><b>Free:</b> ${esc(names(pv.free))}</p>`;
    if (pv.ads.length) prov += `<p><b>Free with ads:</b> ${esc(names(pv.ads))}</p>`;
    if (pv.flatrate.length) prov += `<p><b>Subscription required:</b> ${esc(names(pv.flatrate))}</p>`;
    if (pv.rent.length) prov += `<p><b>Rent (payment required):</b> ${esc(names(pv.rent))}</p>`;
    if (pv.buy.length) prov += `<p><b>Buy (payment required):</b> ${esc(names(pv.buy))}</p>`;
    prov = `<div class="prov"><p><b>Where to watch in ${esc(REGIONS[pv.region] || pv.region)}</b></p>${prov}<p class="fine">Availability comes from TMDB and JustWatch. HEISVERSE never bypasses a subscription, DRM or regional limit.</p></div>`;
  } else {
    prov = `<div class="prov"><p>No verified full-movie source for ${esc(REGIONS[d.region] || d.region)}. Try another country in Settings, or check the TMDB page.</p></div>`;
  }
  const bg = d.backdrop ? IMG + 'w1280' + d.backdrop : (d.poster ? IMG + 'w780' + d.poster : '');
  openDlg(`<div class="media" id="media" style="${bg ? `background-image:url('${esc(bg)}')` : ''}"></div>
  <div class="info"><h2>${esc(d.title)}</h2>
  <p class="meta">${esc([d.year, d.runtime ? d.runtime + ' min' : '', d.rating ? 'Rated ' + d.rating + '/10' : ''].filter(Boolean).join(', '))}</p>
  ${d.tagline ? `<p class="tag">${esc(d.tagline)}</p>` : ''}
  <div class="pills">${(d.genres || []).map(g => `<span>${esc(g)}</span>`).join('')}</div>
  <p>${esc(d.overview || 'No description available.')}</p>
  <div class="actions">
    ${d.trailer ? `<button class="btn red" id="bTrailer">Watch trailer</button>` : ''}
    ${pv && pv.link ? `<a class="btn ${freeNames.length ? 'red' : 'line'}" href="${esc(pv.link)}" target="_blank" rel="noopener noreferrer">${freeNames.length ? 'Watch full movie (free, official page)' : 'See where to watch'}</a>` : ''}
    <button class="btn line" id="bWatch"></button>
    <a class="btn line" href="${esc(d.tmdbUrl)}" target="_blank" rel="noopener noreferrer">TMDB page</a>
  </div>${prov}</div>`);
  bindWatchBtn($('#bWatch'), it);
  const t = $('#bTrailer');
  if (t) t.onclick = () => {
    $('#media').innerHTML = `<iframe src="https://www.youtube-nocookie.com/embed/${encodeURIComponent(d.trailer)}?autoplay=1&rel=0" title="Trailer" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>`;
    $('#media').scrollIntoView({ block: 'nearest' }); addRecent(it);
  };
}
function openArchive(it) {
  const desc = stripHTML(it.overview).slice(0, 700);
  openDlg(`<div class="media" id="media"><iframe src="https://archive.org/embed/${encodeURIComponent(it.id)}" title="${esc(it.title)}" allow="autoplay; fullscreen" allowfullscreen></iframe></div>
  <div class="info"><h2>${esc(it.title)}</h2><p class="meta">${esc(it.year || 'Year unknown')}, Internet Archive</p>
  <p>${esc(desc || 'No description available.')}</p>
  <div class="actions"><a class="btn red" href="https://archive.org/details/${encodeURIComponent(it.id)}" target="_blank" rel="noopener noreferrer">Open on Internet Archive</a><button class="btn line" id="bWatch"></button></div>
  <div class="prov"><p class="fine">If the player stays blank, the owner blocked embedding. Use Open on Internet Archive. Check the item page for its rights details.</p></div></div>`);
  bindWatchBtn($('#bWatch'), it); addRecent(it);
}

/* ---------- catalog (movies published by the admin) ---------- */
const safeUrl = u => /^https:\/\//i.test(String(u)) ? u : '#';
const safeEmbed = u => /^https:\/\/(archive\.org\/embed\/|www\.youtube-nocookie\.com\/embed\/)/.test(String(u)) ? u : '';
const safeFile = u => /^(https:\/\/|assets\/uploads\/videos\/)/.test(String(u)) ? u : '';
async function openCatalog(it) {
  openDlg(loadHTML('Loading movie details'));
  try { renderCatalog(await api({ action: 'catalog_detail', id: it.id }), it); }
  catch (e) { dlgBody.innerHTML = errHTML(e.message); }
}
function renderCatalog(d, base) {
  const it = Object.assign(base, { title: d.title, year: d.year });
  const srcs = d.sources || [], bg = d.backdrop || d.poster || '';
  const watch = srcs.map((s, i) => s.mode === 'link'
    ? `<a class="btn red" href="${esc(safeUrl(s.url))}" target="_blank" rel="noopener noreferrer">${esc(s.label)}</a>`
    : `<button class="btn red" data-src="${i}">Watch: ${esc(s.label)}</button>`).join('');
  const lic = srcs.filter(s => s.license).map(s => `<p class="fine">License for ${esc(s.label)}: ${s.licenseUrl ? `<a href="${esc(safeUrl(s.licenseUrl.replace(/^http:/i, 'https:')))}" target="_blank" rel="noopener noreferrer">${esc(s.license)}</a>` : esc(s.license)}</p>`).join('');
  openDlg(`<div class="media" id="media" style="${bg ? `background-image:url('${esc(bg)}')` : ''}"></div>
  <div class="info"><h2>${esc(d.title)}</h2>
  <p class="meta">${esc([d.year, d.runtime ? d.runtime + ' min' : '', d.rating ? 'Rated ' + d.rating + '/10' : ''].filter(Boolean).join(', '))}</p>
  ${d.tagline ? `<p class="tag">${esc(d.tagline)}</p>` : ''}
  <div class="pills">${(d.genres || []).map(g => `<span>${esc(g)}</span>`).join('')}</div>
  <p>${esc(d.overview || 'No description available.')}</p>
  <div class="actions">${watch}${d.trailer ? `<button class="btn line" id="bTrailer">Watch trailer</button>` : ''}<button class="btn line" id="bWatch"></button></div>
  <div class="prov"><p><b>Rights:</b> ${esc(d.rights || 'Reviewed by the administrator')}</p>${lic}
  <p class="fine">Added by the HEISVERSE administrator after a rights review. If a player stays blank, the owner may block embedding: use the provider's own page.</p></div></div>`);
  bindWatchBtn($('#bWatch'), it);
  $$('[data-src]').forEach(b => b.onclick = () => {
    const s = srcs[b.dataset.src], m = $('#media');
    if (s.mode === 'iframe' && safeEmbed(s.embed)) m.innerHTML = `<iframe src="${esc(s.embed)}" title="${esc(d.title)}" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>`;
    else if (s.mode === 'video' && safeFile(s.file)) m.innerHTML = `<video src="${esc(s.file)}" controls autoplay playsinline></video>`;
    else return toast('This source cannot be played here.');
    m.scrollIntoView({ block: 'nearest' }); addRecent(it);
  });
  const t = $('#bTrailer');
  if (t) t.onclick = () => {
    $('#media').innerHTML = `<iframe src="https://www.youtube-nocookie.com/embed/${encodeURIComponent(d.trailer)}?autoplay=1&rel=0" title="Trailer" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>`;
    $('#media').scrollIntoView({ block: 'nearest' }); addRecent(it);
  };
}
const cState = { q: '', genre: '', page: 1, busy: false, chips: false };
function initCatalog() {
  $('#catForm').onsubmit = e => { e.preventDefault(); loadCatalog({ q: $('#catQ').value.trim(), genre: cState.genre }, true); };
  $('#catMore').onclick = () => loadCatalog(cState, false);
  $('#catChips').onclick = e => {
    const b = e.target.closest('.chip'); if (!b) return;
    $$('#catChips .chip').forEach(x => x.classList.toggle('on', x === b)); loadCatalog({ q: cState.q, genre: b.dataset.g }, true);
  };
  loadCatalog({ q: '', genre: '' }, true);
}
async function loadCatalog(p, reset) {
  if (cState.busy) return; cState.busy = true;
  if (reset) { cState.q = p.q; cState.genre = p.genre; cState.page = 1; $('#catGrid').innerHTML = ''; } else cState.page++;
  const msg = $('#catMsg'), g = $('#catGrid'); msg.innerHTML = loadHTML(); $('#catMore').hidden = true;
  try {
    const j = await api({ action: 'catalog', page: cState.page, q: cState.q, genre: cState.genre });
    msg.innerHTML = '';
    if (j.genres && !cState.chips) {
      cState.chips = true;
      if (j.genres.length) $('#catChips').innerHTML = [''].concat(j.genres).map(x => `<button class="chip${x === cState.genre ? ' on' : ''}" data-g="${esc(x)}">${esc(x || 'All')}</button>`).join('');
    }
    g.insertAdjacentHTML('beforeend', j.results.map(dbItem).map(card).join(''));
    if (!g.children.length) g.innerHTML = `<div class="empty">${cState.q || cState.genre ? 'No catalog movies match.' : 'The catalog is empty. Movies appear here after the administrator publishes them.'}</div>`;
    $('#catMore').hidden = cState.page >= j.total_pages;
  } catch (e) { msg.innerHTML = errHTML(e.message); if (!reset) cState.page--; }
  cState.busy = false;
}

/* ---------- home ---------- */
async function initHome() {
  const rows = $('#homeRows');
  if (HV.hasKey) {
    const defs = [['Trending this week', { action: 'trending' }], ['Action', { action: 'discover', genre: 28 }], ['African cinema', { action: 'discover', africa: 1 }],
      ['Sci-Fi', { action: 'discover', genre: 878 }], ['Comedy', { action: 'discover', genre: 35 }], ['Animation', { action: 'discover', genre: 16 }]];
    rows.innerHTML = '<div id="hrCat"></div>' + defs.map((d, i) => `<div id="hr${i}">${loadHTML('Loading ' + d[0])}</div>`).join('') + '<div id="hrFree"></div>';
    defs.forEach(async (d, i) => {
      try {
        const j = await api(d[1]), its = j.results.map(tmdbItem);
        $('#hr' + i).innerHTML = row(d[0], its);
        if (i === 0) setHero(its.find(x => x.backdrop));
      } catch (e) { $('#hr' + i).innerHTML = i === 0 ? errHTML(e.message) : ''; }
    });
  } else { $('#homeNotice').innerHTML = NOKEY; rows.innerHTML = '<div id="hrCat"></div>'; }
  api({ action: 'catalog' }).then(j => {
    if (!j.results.length) return;
    const its = j.results.slice(0, 14).map(dbItem); $('#hrCat').innerHTML = row('Added to HEISVERSE', its);
    if (!HV.hasKey) setHero(its.find(x => x.backdrop));
  }).catch(() => { /* catalog not set up yet: leave the row out */ });
  try { const a = await archive('', 1); $('#hrFree') ? $('#hrFree').innerHTML = row('Free classics', a.docs.slice(0, 14).map(iaItem)) : rows.insertAdjacentHTML('beforeend', row('Free classics', a.docs.slice(0, 14).map(iaItem))); }
  catch { /* offline: leave the row out */ }
}
function setHero(it) {
  if (!it) return;
  const h = $('#hero'); h.classList.add('img'); h.style.setProperty('--hero', `url('${it.backdrop}')`);
  $('#heroTitle').textContent = it.title; $('#heroText').textContent = (it.overview || '').slice(0, 220) + ((it.overview || '').length > 220 ? '...' : '');
  $('#heroActions').innerHTML = `<button class="btn red" data-key="${esc(it.key)}">Details and trailer</button><button class="btn line" data-go="local">My PC library</button>`;
}

/* ---------- discover (TMDB) ---------- */
const dState = { params: { action: 'trending' }, page: 1, total: 1, busy: false };
function initDiscover(skipLoad) {
  const chips = [['Popular', { action: 'trending' }], ...GENRES.map(g => [g[0], { action: 'discover', genre: g[1] }]), ['African movies', { action: 'discover', africa: 1 }]];
  $('#chips').innerHTML = chips.map((c, i) => `<button class="chip${i ? '' : ' on'}" data-i="${i}">${esc(c[0])}</button>`).join('');
  $('#chips').onclick = e => {
    const b = e.target.closest('.chip'); if (!b) return;
    $$('.chip').forEach(x => x.classList.toggle('on', x === b)); loadDiscover(chips[b.dataset.i][1], true);
  };
  $('#discoverMore').onclick = () => loadDiscover(dState.params, false);
  if (!HV.hasKey) { $('#discoverMsg').innerHTML = NOKEY; $('#chips').hidden = true; return; }
  if (!dState.started && !skipLoad) loadDiscover(dState.params, true);
}
async function loadDiscover(params, reset) {
  if (dState.busy) return; dState.busy = true; dState.started = true;
  if (reset) { dState.page = 1; dState.params = params; $('#discoverGrid').innerHTML = ''; } else dState.page++;
  const g = $('#discoverGrid'), msg = $('#discoverMsg'); msg.innerHTML = loadHTML(); $('#discoverMore').hidden = true;
  try {
    const j = await api({ ...dState.params, page: dState.page });
    dState.total = j.total_pages; msg.innerHTML = '';
    g.insertAdjacentHTML('beforeend', j.results.map(tmdbItem).map(card).join(''));
    if (!g.children.length) g.innerHTML = `<div class="empty">No movies found here. Try another genre or search term.</div>`;
    $('#discoverMore').hidden = dState.page >= dState.total;
  } catch (e) { msg.innerHTML = errHTML(e.message); if (!reset) dState.page--; }
  dState.busy = false;
}
$('#navSearch').addEventListener('submit', e => {
  e.preventDefault(); const q = $('#navQ').value.trim(); if (!q) return;
  if (HV.hasKey) {
    inited.discover = true; location.hash = '#discover'; if (!$('#chips').children.length) initDiscover(true);
    $$('.chip').forEach(x => x.classList.remove('on')); loadDiscover({ action: 'search', q }, true);
  } else { $('#freeQ').value = q; location.hash = '#free'; setTimeout(() => $('#freeForm').requestSubmit(), 50); toast('No TMDB key yet, so searching free films instead.'); }
});

/* ---------- free films (Internet Archive) ---------- */
async function archive(q, page) {
  const clean = q.replace(/[^\p{L}\p{N}\s'-]/gu, ' ').trim();
  const p = new URLSearchParams();
  p.set('q', 'collection:feature_films AND mediatype:movies' + (clean ? ` AND title:(${clean})` : ''));
  ['identifier', 'title', 'year', 'description'].forEach(f => p.append('fl[]', f));
  p.append('sort[]', 'downloads desc'); p.set('rows', '24'); p.set('page', page); p.set('output', 'json');
  let r; try { r = await fetch('https://archive.org/advancedsearch.php?' + p); } catch { throw new Error('Cannot reach the Internet Archive. Check your internet connection.'); }
  if (!r.ok) throw new Error('The Internet Archive is not responding. Try again later.');
  const j = await r.json(); return { docs: j.response.docs, total: j.response.numFound };
}
const fState = { q: '', page: 1, busy: false };
function initFree() { $('#freeForm').onsubmit = e => { e.preventDefault(); loadFree($('#freeQ').value.trim(), true); }; $('#freeMore').onclick = () => loadFree(fState.q, false); loadFree('', true); }
async function loadFree(q, reset) {
  if (fState.busy) return; fState.busy = true;
  if (reset) { fState.q = q; fState.page = 1; $('#freeGrid').innerHTML = ''; } else fState.page++;
  const msg = $('#freeMsg'); msg.innerHTML = loadHTML(); $('#freeMore').hidden = true;
  try {
    const a = await archive(fState.q, fState.page); msg.innerHTML = '';
    $('#freeGrid').insertAdjacentHTML('beforeend', a.docs.map(iaItem).map(card).join(''));
    if (!$('#freeGrid').children.length) $('#freeGrid').innerHTML = `<div class="empty">No free films match "${esc(fState.q)}". Try a shorter title.</div>`;
    $('#freeMore').hidden = fState.page * 24 >= a.total;
  } catch (e) { msg.innerHTML = errHTML(e.message); if (!reset) fState.page--; }
  fState.busy = false;
}

/* ---------- local library ---------- */
const MIME = { mp4: 'video/mp4', m4v: 'video/mp4', webm: 'video/webm', ogv: 'video/ogg', ogg: 'video/ogg', mov: 'video/quicktime', mkv: 'video/x-matroska', avi: 'video/x-msvideo',
  wmv: 'video/x-ms-wmv', flv: 'video/x-flv', mpg: 'video/mpeg', mpeg: 'video/mpeg', '3gp': 'video/3gpp', ts: 'video/mp2t', m2ts: 'video/mp2t' };
let local = [], uid = 0;
const extOf = n => (n.split('.').pop() || '').toLowerCase();
const isVideo = f => MIME[extOf(f.name)] || (f.type || '').startsWith('video/');
const sigOf = f => f.name.toLowerCase() + '|' + f.size;
const meta = () => store.get('localmeta', {});
function parseName(fn) {
  let base = fn.replace(/\.[^.]+$/, '').replace(/[._]+/g, ' ');
  const ym = base.match(/(?:^|[\s(\[-])((?:19|20)\d{2})(?=[\s)\]-]|$)/);
  const year = ym ? ym[1] : '';
  if (ym && ym.index > 1) base = base.slice(0, ym.index);
  base = base.replace(/[(\[].*$/, '').replace(/\b(2160p|1080p|720p|480p|4k|uhd|bluray|brrip|bdrip|webrip|web-dl|webdl|hdrip|dvdrip|hdtv|x264|x265|h264|h265|hevc|aac|ac3|yify|yts|rarbg)\b.*$/i, '').replace(/\s+/g, ' ').trim();
  base = (base || fn.replace(/\.[^.]+$/, '')).replace(/\b\p{L}/gu, c => c.toUpperCase());
  return { title: base, year };
}
function addFiles(entries) {
  let added = 0, dup = 0, ignored = 0; const known = new Set(local.map(l => l.sig)), m = meta();
  for (const { file, path } of entries) {
    if (!isVideo(file)) { ignored++; continue; }
    const sig = sigOf(file); if (known.has(sig)) { dup++; continue; } known.add(sig);
    const p = parseName(file.name), saved = m[sig] || {};
    if (!saved.added) { saved.added = Date.now(); }
    m[sig] = { ...saved, name: file.name, size: file.size, modified: file.lastModified };
    local.push({ id: ++uid, file, path: path || file.name, sig, title: p.title, year: p.year, ext: extOf(file.name), size: file.size, modified: file.lastModified, added: saved.added });
    added++;
  }
  store.set('localmeta', m); inited.local = true; renderLocal(); location.hash = '#local';
  toast(`Added ${added} video${added === 1 ? '' : 's'}.` + (dup ? ` ${dup} already in library.` : '') + (ignored ? ` ${ignored} non-video file${ignored === 1 ? '' : 's'} ignored.` : ''));
}
async function walk(dir, path, out) {
  for await (const [name, h] of dir.entries()) {
    if (h.kind === 'file') { if (MIME[extOf(name)]) out.push({ file: await h.getFile(), path: path + name }); }
    else await walk(h, path + name + '/', out);
  }
}
async function walkEntry(en, out, path = '') {
  if (en.isFile) { const f = await new Promise((res, rej) => en.file(res, rej)); out.push({ file: f, path: path + f.name }); }
  else if (en.isDirectory) {
    const rd = en.createReader(); let b;
    do { b = await new Promise((res, rej) => rd.readEntries(res, rej)); for (const c of b) await walkEntry(c, out, path + en.name + '/'); } while (b.length);
  }
}
$('#pickFolder').onclick = async () => {
  if (window.showDirectoryPicker) {
    try { const dir = await showDirectoryPicker({ mode: 'read' }); toast('Scanning folder'); const out = []; await walk(dir, dir.name + '/', out); addFiles(out); }
    catch (e) { if (e.name !== 'AbortError') toast('Could not read that folder: ' + e.message); }
  } else $('#folderInput').click();
};
$('#pickFiles').onclick = () => $('#fileInput').click();
for (const id of ['folderInput', 'fileInput']) $('#' + id).onchange = e => { addFiles([...e.target.files].map(f => ({ file: f, path: f.webkitRelativePath || f.name }))); e.target.value = ''; };
const drop = $('#drop');
['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
drop.addEventListener('drop', async e => {
  const entries = [...(e.dataTransfer.items || [])].map(i => i.webkitGetAsEntry && i.webkitGetAsEntry()).filter(Boolean), out = [];
  try {
    if (entries.length) for (const en of entries) await walkEntry(en, out);
    else [...e.dataTransfer.files].forEach(f => out.push({ file: f, path: f.name }));
    addFiles(out);
  } catch { toast('Could not read the dropped items. Use the Choose buttons instead.'); }
});
['lq', 'lsort', 'ltype', 'ldup'].forEach(id => $('#' + id).addEventListener('input', () => renderLocal()));
$('#lclear').onclick = () => { if (!local.length) return; if (confirm('Remove all videos from this library view? Your files are not deleted.')) { local = []; renderLocal(); } };
function renderLocal() {
  const keyOf = l => l.title.toLowerCase().replace(/[^a-z0-9]/g, '') + '|' + l.year, counts = {};
  local.forEach(l => counts[keyOf(l)] = (counts[keyOf(l)] || 0) + 1); local.forEach(l => l.dup = counts[keyOf(l)] > 1);
  const sel = $('#ltype'), cur = sel.value, exts = [...new Set(local.map(l => l.ext))].sort();
  sel.innerHTML = '<option value="">All types</option>' + exts.map(x => `<option value="${x}">${x.toUpperCase()}</option>`).join(''); sel.value = exts.includes(cur) ? cur : '';
  const q = $('#lq').value.trim().toLowerCase(), sort = $('#lsort').value, m = meta();
  let list = local.filter(l => (!q || (l.title + ' ' + l.file.name).toLowerCase().includes(q)) && (!sel.value || l.ext === sel.value) && (!$('#ldup').checked || l.dup));
  const by = { title: (a, b) => a.title.localeCompare(b.title), year: (a, b) => (b.year || 0) - (a.year || 0), size: (a, b) => b.size - a.size, added: (a, b) => b.added - a.added, modified: (a, b) => b.modified - a.modified };
  list.sort(by[sort]);
  const total = list.reduce((s, l) => s + l.size, 0), dups = local.filter(l => l.dup).length;
  $('#lstats').textContent = local.length ? `${list.length} of ${local.length} videos, ${fmtSize(total)}${dups ? `, ${dups} possible duplicates` : ''}` : '';
  $('#localGrid').innerHTML = list.length ? list.map(l => {
    const s = m[l.sig] || {}, pct = s.dur ? Math.min(100, Math.round(s.pos / s.dur * 100)) : 0;
    return `<article class="card lcard" data-lid="${l.id}"><button class="pw" data-play aria-label="Play ${esc(l.title)}"><div class="noposter"><span>${esc(l.title)}</span></div><span class="ext">${esc(l.ext.toUpperCase())}</span>${l.dup ? '<span class="dupb">Duplicate?</span>' : ''}<span class="play">Play</span>${pct ? `<i class="prog" style="width:${pct}%"></i>` : ''}</button>
    <div class="ct"><b>${esc(l.title)}</b><small>${esc([l.year, fmtSize(l.size)].filter(Boolean).join(', '))}${s.watched ? ' <span class="watched">Watched</span>' : ''}</small></div>
    <div class="lact"><button data-info>Info</button><button data-rm>Remove</button></div></article>`;
  }).join('') : `<div class="empty">${local.length ? 'No videos match your filters.' : 'No videos yet. Choose a folder or drop video files above to build your library.'}</div>`;
}
$('#localGrid').addEventListener('click', e => {
  const a = e.target.closest('.lcard'); if (!a) return; const l = local.find(x => x.id == a.dataset.lid); if (!l) return;
  if (e.target.closest('[data-play]')) playLocal(l);
  else if (e.target.closest('[data-rm]')) { local = local.filter(x => x !== l); renderLocal(); }
  else if (e.target.closest('[data-info]')) {
    const s = meta()[l.sig] || {};
    openDlg(`<div class="info"><h2>${esc(l.title)}</h2><p class="meta">${esc(l.year || 'Year unknown')}</p>
    <div class="prov"><p><b>File:</b> ${esc(l.file.name)}</p><p><b>Folder path:</b> ${esc(l.path)}</p><p><b>Size:</b> ${fmtSize(l.size)}</p><p><b>Type:</b> ${esc(l.ext.toUpperCase())} (${esc(l.file.type || MIME[l.ext] || 'unknown')})</p>
    <p><b>Modified:</b> ${esc(new Date(l.modified).toLocaleString())}</p>${s.dur ? `<p><b>Length:</b> ${fmtTime(s.dur)}</p>` : ''}
    <p><b>Browser support:</b> ${document.createElement('video').canPlayType(MIME[l.ext] || '') ? 'Likely playable' : 'This browser reports no support for this format'}</p>
    ${l.dup ? '<p><b>Duplicate check:</b> another file has the same title and year.</p>' : ''}</div></div>`);
  }
});
function playLocal(l) {
  const url = URL.createObjectURL(l.file), m = meta(), s = m[l.sig] || {}; let lastSave = 0, v;
  openDlg(`<div id="pl"><video id="vid" playsinline preload="metadata"></video><div class="pmsg" id="pmsg" hidden></div>
  <div class="pbar"><button id="pp">Play</button><input id="seek" type="range" min="0" max="1000" value="0" aria-label="Seek"><span id="tm">0:00 / 0:00</span>
  <button id="mute">Mute</button><input id="vol" type="range" min="0" max="1" step="0.05" value="1" aria-label="Volume">
  <select id="spd" aria-label="Speed"><option>0.5</option><option>0.75</option><option selected>1</option><option>1.25</option><option>1.5</option><option>2</option></select><button id="fs">Fullscreen</button></div></div>
  <p class="ptitle">${esc(l.file.name)}. Space plays or pauses, arrow keys seek and change volume, F toggles fullscreen.</p>`, 'player');
  v = $('#vid'); v.src = url;
  const msg = t => { const p = $('#pmsg'); if (p) { p.textContent = t; p.hidden = false; } };
  const fix = `Try Chrome or Edge, convert it to MP4 (H.264 video, AAC audio) with HandBrake, or open it in VLC.`;
  const save = (final) => {
    if (!v.duration) return; const mm = meta(), e = mm[l.sig] || {}; e.pos = final && v.ended ? 0 : v.currentTime; e.dur = v.duration;
    if (v.currentTime / v.duration > 0.9) e.watched = true; mm[l.sig] = { ...e, name: l.file.name, size: l.size, modified: l.modified, added: e.added || l.added }; store.set('localmeta', mm);
  };
  v.onerror = () => msg(`This browser cannot play ${l.ext.toUpperCase()} files with this codec. ${fix}`);
  v.onloadedmetadata = () => {
    if (v.videoWidth === 0 && v.videoHeight === 0 && l.ext !== 'ogg') msg(`The picture cannot be decoded here (audio only). ${fix}`);
    if (prefs.resume && s.pos > 5 && s.pos < v.duration - 10) { v.currentTime = s.pos; toast('Resumed at ' + fmtTime(s.pos)); }
  };
  v.onplay = () => { $('#pp').textContent = 'Pause'; addRecent({ key: 'local:' + l.sig, kind: 'local', id: l.sig, title: l.title, year: l.year, poster: null, rating: 0, genres: [] });
    setTimeout(() => { if (!v.paused && 'webkitAudioDecodedByteCount' in v && v.webkitAudioDecodedByteCount === 0 && !v.muted && v.currentTime > 1) msg(`No sound: the audio codec is not supported here. ${fix}`); }, 3000); };
  v.onpause = () => { $('#pp').textContent = 'Play'; save(); };
  v.onended = () => save(true);
  v.ontimeupdate = () => {
    if (v.duration) { $('#seek').value = v.currentTime / v.duration * 1000; $('#tm').textContent = fmtTime(v.currentTime) + ' / ' + fmtTime(v.duration); }
    if (Date.now() - lastSave > 3000) { lastSave = Date.now(); save(); }
  };
  const toggle = () => v.paused ? v.play().catch(() => msg(`Playback failed. ${fix}`)) : v.pause();
  $('#pp').onclick = toggle; v.onclick = toggle;
  $('#seek').oninput = e => { if (v.duration) v.currentTime = e.target.value / 1000 * v.duration; };
  $('#vol').oninput = e => { v.volume = e.target.value; v.muted = false; $('#mute').textContent = 'Mute'; };
  $('#mute').onclick = () => { v.muted = !v.muted; $('#mute').textContent = v.muted ? 'Unmute' : 'Mute'; };
  $('#spd').onchange = e => v.playbackRate = parseFloat(e.target.value);
  const fs = () => document.fullscreenElement ? document.exitFullscreen() : $('#pl').requestFullscreen().catch(() => toast('Fullscreen is blocked by this browser.'));
  $('#fs').onclick = fs;
  const key = e => {
    if (e.target.matches('select,input[type=text]')) return;
    if (e.key === ' ' && !e.target.matches('button')) { e.preventDefault(); toggle(); }
    else if (e.key === 'ArrowRight' && !e.target.matches('input')) v.currentTime += 10;
    else if (e.key === 'ArrowLeft' && !e.target.matches('input')) v.currentTime -= 10;
    else if (e.key === 'ArrowUp') { e.preventDefault(); v.volume = Math.min(1, v.volume + .1); $('#vol').value = v.volume; }
    else if (e.key === 'ArrowDown') { e.preventDefault(); v.volume = Math.max(0, v.volume - .1); $('#vol').value = v.volume; }
    else if (e.key.toLowerCase() === 'f') fs();
  };
  dlg.addEventListener('keydown', key);
  cleanup = () => { save(); dlg.removeEventListener('keydown', key); v.pause(); v.removeAttribute('src'); v.load(); URL.revokeObjectURL(url); renderLocal(); };
  v.play().catch(() => {});
}

/* ---------- settings ---------- */
function renderSettings() {
  $('#view-settings').innerHTML = `<div class="set"><h2 class="vt">Settings</h2>
  <h3>Preferences</h3>
  <label>Country for "where to watch" <select id="sRegion">${Object.entries(REGIONS).map(([k, v]) => `<option value="${k}"${k === prefs.region ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
  <label><input type="checkbox" id="sResume"${prefs.resume ? ' checked' : ''}> Resume local videos where I stopped</label>
  <h3>Movie data</h3><p>${HV.hasKey ? 'TMDB key found. Online discovery is on.' : 'No TMDB key yet. Open config.php to add one (see README.md).'}</p>
  <h3>Saved data</h3><p class="sub">HEISVERSE saves only small notes in this browser: watchlist, history, preferences, and resume positions for local videos. Video files and API keys are never saved here.</p>
  <div class="actions"><button class="btn line" id="sExport">Export metadata</button><button class="btn line" id="sImport">Import metadata</button><button class="btn red" id="sClear">Clear saved data</button></div>
  <input type="file" id="sFile" accept="application/json,.json" hidden>
  <h3>What needs internet</h3>
  <table><tr><th>Feature</th><th>Offline</th></tr><tr><td>My PC library and player</td><td>Works</td></tr><tr><td>Watchlist, history, settings</td><td>Works (posters may not load)</td></tr>
  <tr><td>Discover, trailers, where to watch</td><td>Needs internet</td></tr><tr><td>Free films</td><td>Needs internet</td></tr></table></div>`;
  $('#sRegion').onchange = e => { prefs.region = e.target.value; store.set('prefs', prefs); toast('Country saved.'); };
  $('#sResume').onchange = e => { prefs.resume = e.target.checked; store.set('prefs', prefs); };
  $('#sExport').onclick = () => {
    const data = { app: 'heisverse', version: 1, watchlist: store.get('watchlist', []), recent: store.get('recent', []), prefs, localmeta: meta() };
    const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })), download: 'heisverse-metadata.json' });
    a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  };
  $('#sImport').onclick = () => $('#sFile').click();
  $('#sFile').onchange = async e => {
    const f = e.target.files[0]; if (!f) return;
    try {
      const d = JSON.parse(await f.text()); if (d.app !== 'heisverse') throw 0;
      const w = store.get('watchlist', []); (Array.isArray(d.watchlist) ? d.watchlist : []).forEach(x => { if (x && x.key && x.title && !w.some(y => y.key === x.key)) w.push(slim(x)); });
      store.set('watchlist', w);
      if (d.localmeta && typeof d.localmeta === 'object') store.set('localmeta', { ...d.localmeta, ...meta() });
      if (d.prefs && typeof d.prefs === 'object') { prefs = { ...prefs, region: String(d.prefs.region || prefs.region).slice(0, 2).toUpperCase(), resume: d.prefs.resume !== false }; store.set('prefs', prefs); }
      toast('Metadata imported.'); renderSettings();
    } catch { toast('That file is not a HEISVERSE metadata export.'); }
    e.target.value = '';
  };
  $('#sClear').onclick = () => { if (confirm('Clear your watchlist, history, preferences and resume positions? Your video files are not touched.')) { store.clear(); prefs = { region: HV.region, resume: true }; toast('Saved data cleared.'); renderSettings(); renderLocal(); } };
}

go(location.hash.slice(1) || 'home');
