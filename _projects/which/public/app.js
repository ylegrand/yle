const BASE = (window.__BASE__ && window.__BASE__ !== '/') ? window.__BASE__ : '';
const CSRF = window.__CSRF__ || '';
const SHARE_TOKEN = window.__SHARE_TOKEN__ || '';
const SHARE_MODE = !!window.__SHARE_MODE__;

const el = (sel, root = document) => root.querySelector(sel);
const els = (sel, root = document) => Array.from(root.querySelectorAll(sel));
const esc = (s) => (s ?? '').toString().replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

function assetUrl(path) {
  if (!path) return '';
  if (/^(https?:|data:|blob:)/i.test(path)) return path;
  const basePath = path.startsWith('/') ? (BASE + path) : (BASE + '/' + path);
  if (!SHARE_MODE || !SHARE_TOKEN) return basePath;
  const sep = basePath.includes('?') ? '&' : '?';
  return basePath + sep + 'st=' + encodeURIComponent(SHARE_TOKEN);
}

async function api(action, params = {}, body = null, isForm = false) {
  const url = new URL(location.origin + BASE + '/?p=api');
  url.searchParams.set('action', action);
  Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
  if (SHARE_TOKEN) {
    url.searchParams.set('st', SHARE_TOKEN);
  }

  const headers = {};
  if (!isForm) headers['Content-Type'] = 'application/json';
  if (CSRF) headers['X-CSRF-Token'] = CSRF;

  const resp = await fetch(url.toString(), {
    method: 'POST',
    headers,
    body: isForm ? body : (body ? JSON.stringify(body) : null)
  });

  const data = await resp.json().catch(() => ({ ok: false, error: 'Invalid JSON' }));
  if (!resp.ok && data.ok !== false) {
    data.ok = false;
    data.error = data.error || ('HTTP ' + resp.status);
  }
  return data;
}

function ensureToastStack() {
  let stack = el('#toastStack');
  if (stack) return stack;
  stack = document.createElement('div');
  stack.id = 'toastStack';
  stack.className = 'toast-stack';
  document.body.appendChild(stack);
  return stack;
}

function toast(message, kind = 'info') {
  const stack = ensureToastStack();
  const node = document.createElement('div');
  node.className = `toast toast-${kind}`;
  node.textContent = message;
  stack.appendChild(node);
  window.setTimeout(() => {
    node.classList.add('hide');
    window.setTimeout(() => node.remove(), 220);
  }, 2200);
}

async function initHome() {
  const wrap = el('#homeSets');
  const res = await api('list_sets');
  if (!res.ok) {
    wrap.textContent = res.error || 'Erreur';
    return;
  }

  const sets = res.sets || [];
  if (!sets.length) {
    wrap.innerHTML = '<p class="muted">Aucun set.</p>';
    return;
  }

  wrap.innerHTML = sets.map((set) => `
    <article class="set-card">
      <h3>${esc(set.title)}</h3>
      <p class="muted">${esc(String(set.itemCount || 0))} item(s)</p>
      <a class="btn" href="${BASE}/?p=play&set=${encodeURIComponent(set.id)}">Jouer</a>
    </article>
  `).join('');
}

function ensurePlayLoader() {
  let loader = el('#playLoader');
  if (loader) return loader;

  loader = document.createElement('div');
  loader.id = 'playLoader';
  loader.className = 'play-loader';
  loader.hidden = true;
  loader.innerHTML = `
    <div class="play-loader-card">
      <div class="play-loader-title" id="playLoaderTitle">Préparation du set...</div>
      <div class="play-loader-detail" id="playLoaderDetail">Chargement des images</div>
      <div class="play-loader-bar"><span id="playLoaderBar"></span></div>
      <button class="btn ghost" type="button" id="playLoaderRetry" hidden>Réessayer</button>
    </div>
  `;
  document.body.appendChild(loader);
  return loader;
}

async function preloadImage(url) {
  return new Promise((resolve) => {
    const im = new Image();
    let done = false;
    const finish = (ok) => {
      if (done) return;
      done = true;
      resolve(ok);
    };
    const timer = window.setTimeout(() => finish(false), 8000);
    im.onload = () => { clearTimeout(timer); finish(true); };
    im.onerror = () => { clearTimeout(timer); finish(false); };
    im.src = url;
  });
}

async function initPlay() {
  const stage = el('#playStage');
  const image = el('#playImage');
  const caption = el('#playCaption');
  const hint = el('#playHint');
  const playMainTitle = el('#playMainTitle');

  const loader = ensurePlayLoader();
  const loaderTitle = el('#playLoaderTitle', loader);
  const loaderDetail = el('#playLoaderDetail', loader);
  const loaderBar = el('#playLoaderBar', loader);
  const loaderRetry = el('#playLoaderRetry', loader);

  function showLoader(main, detail = '', progress = 0, canRetry = false) {
    loader.hidden = false;
    loaderTitle.textContent = main;
    loaderDetail.textContent = detail;
    loaderBar.style.width = `${Math.max(0, Math.min(100, progress))}%`;
    loaderRetry.hidden = !canRetry;
  }

  function hideLoader() {
    loader.hidden = true;
  }

  const setId = (window.__SET_ID__ || window.__SHARE_SET__ || '').trim();
  if (!setId) {
    hint.textContent = 'Set manquant';
    hint.classList.remove('hint-stop');
    stage.disabled = true;
    return;
  }

  const res = await api('get_set', { id: setId });
  if (!res.ok) {
    hint.textContent = res.error || 'Set introuvable';
    hint.classList.remove('hint-stop');
    stage.disabled = true;
    return;
  }

  const set = res.set || {};
  const setLabel = (set.title || setId || '').trim();
  if (playMainTitle && setLabel) {
    playMainTitle.textContent = `Quel ${setLabel} es-tu ?`;
    document.title = `${playMainTitle.textContent} - Jeu`;
  }
  const rawItems = Array.isArray(set.items) ? set.items.filter((it) => it && it.img) : [];
  if (!rawItems.length) {
    hint.textContent = 'Set vide';
    hint.classList.remove('hint-stop');
    caption.hidden = false;
    caption.textContent = 'Ce set ne contient pas d\'image.';
    stage.disabled = true;
    return;
  }

  const items = rawItems.map((it) => ({ ...it, __url: assetUrl(it.img) }));

  async function runPreload() {
    showLoader('Préchargement...', 'Initialisation', 2, false);
    stage.disabled = true;

    const uniqueUrls = Array.from(new Set(items.map((it) => it.__url).filter(Boolean)));
    let done = 0;
    const failed = [];

    for (const url of uniqueUrls) {
      const ok = await preloadImage(url);
      done += 1;
      const pct = Math.round((done / uniqueUrls.length) * 100);
      showLoader('Préchargement...', `Images ${done}/${uniqueUrls.length}`, pct, false);
      if (!ok) failed.push(url);
    }

    if (failed.length > 0) {
      showLoader('Chargement incomplet', `${failed.length} image(s) indisponible(s).`, 100, true);
      throw new Error('Image preload failed');
    }

    hideLoader();
    stage.disabled = false;
  }

  let running = false;
  let current = null;
  let timer = null;

  function pickRandom() {
    if (items.length === 1) return items[0];
    let candidate = items[Math.floor(Math.random() * items.length)];
    if (current && items.length > 1) {
      let guard = 0;
      while (candidate.id === current.id && guard < 10) {
        candidate = items[Math.floor(Math.random() * items.length)];
        guard += 1;
      }
    }
    return candidate;
  }

  function render(item) {
    image.src = item.__url;
    image.alt = item.label || '';
    caption.textContent = item.label || '';
  }

  function loop() {
    current = pickRandom();
    render(current);
  }

  function start() {
    if (running) return;
    running = true;
    hint.textContent = '';
    hint.classList.remove('hint-stop');
    caption.hidden = true;
    loop();
    timer = window.setInterval(loop, 100);
  }

  function stop() {
    running = false;
    if (timer) {
      window.clearInterval(timer);
      timer = null;
    }
    hint.textContent = 'Tu es';
    hint.classList.add('hint-stop');
    
    caption.hidden = false;
  }

  stage.addEventListener('click', () => {
    if (running) {
      stop();
    } else {
      start();
    }
  });

  loaderRetry.addEventListener('click', async () => {
    try {
      await runPreload();
      toast('Préchargement terminé', 'success');
      start();
    } catch {
      toast('Certaines images ne se chargent pas', 'error');
    }
  });

  try {
    await runPreload();
    start();
  } catch {
    toast('Échec du préchargement. Vérifiez les images du set.', 'error');
  }

  if (SHARE_MODE && window.__SHARE_EXP__) {
    const msLeft = (Number(window.__SHARE_EXP__) * 1000) - Date.now();
    if (msLeft > 0) {
      window.setTimeout(() => {
        stop();
        stage.disabled = true;
        hint.textContent = 'Lien expiré';
        hint.classList.remove('hint-stop');
        caption.hidden = false;
        caption.textContent = 'Ce lien temporaire a expiré.';
      }, msLeft);
    }
  }
}

async function initAdmin() {
  if (!window.SetAdminCore || typeof window.SetAdminCore.init !== 'function') {
    toast('Module admin commun introuvable', 'error');
    return;
  }

  await window.SetAdminCore.init({
    api,
    assetUrl,
    esc,
    previewSelector: '#btnPlay',
    uploadExt: '.jpg',
    emptySet: () => ({ id: '', title: '', items: [] }),
    fromSet: (set) => ({
      id: set.id || '',
      title: set.title || '',
      items: Array.isArray(set.items) ? set.items : []
    }),
    writeForm: (form, current) => {
      form.id.value = current.id || '';
      form.title.value = current.title || '';
    },
    readForm: (form, current) => {
      current.id = (form.id.value || '').trim();
      current.title = (form.title.value || '').trim();
    },
    toPayload: (current) => ({
      id: current.id,
      title: current.title,
      items: current.items
    }),
    listMeta: (set, escFn) => `${escFn(String(set.itemCount || 0))} item(s)`,
    previewHref: (id) => `${BASE}/?p=play&set=${encodeURIComponent(id)}`
  });
}

document.addEventListener('DOMContentLoaded', () => {
  const page = window.__PAGE__;
  if (page === 'home') initHome();
  if (page === 'admin') initAdmin();
  if (page === 'play') initPlay();
});














