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
      <p class="muted">ID: ${esc(set.id)} | ${esc(String(set.itemCount || 0))} item(s)</p>
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
  const listEl = el('#setList');
  const form = el('#setForm');
  const itemsEditor = el('#itemsEditor');
  const btnNew = el('#btnNewSet');
  const btnDelete = el('#btnDeleteSet');
  const btnPlay = el('#btnPlay');
  const btnShare = el('#btnShare');
  const btnSave = el('#btnSaveSet');
  const statusEl = el('#adminStatus');
  const searchInput = el('#setSearch');

  const shareModal = el('#shareModal');
  const shareUrl = el('#shareUrl');
  const shareExpiry = el('#shareExpiry');
  const shareQr = el('#shareQr');
  const btnCopyShare = el('#btnCopyShare');
  const btnCloseShare = el('#btnCloseShare');

  let current = { id: '', title: '', items: [] };
  let allSets = [];
  let selectedId = '';
  let dirty = false;
  let busy = false;

  const pendingFiles = new Map();
  const pendingPreview = new Map();

  function setStatus(text, tone = 'info') {
    if (!statusEl) return;
    statusEl.textContent = text;
    statusEl.className = `status-pill status-${tone}`;
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

  function setBusy(next, label = '') {
    busy = next;
    if (btnSave) {
      btnSave.disabled = next;
      btnSave.setAttribute('aria-busy', next ? 'true' : 'false');
      btnSave.textContent = next ? (label || 'Traitement...') : 'Enregistrer';
    }
    btnDelete.disabled = next;
    btnNew.disabled = next;
    btnShare.disabled = next;
  }

  function setDirty(next) {
    dirty = next;
    if (dirty) setStatus('Modifications non enregistrées', 'warn');
    else setStatus('Prêt', 'ok');
  }

  function ensureLeaveDirty() {
    if (!dirty || busy) return true;
    return window.confirm('Vous avez des modifications non enregistrées. Continuer ?');
  }

  function normalizeItems(items) {
    return items.map((item, idx) => ({
      id: (item.id || ('i' + (idx + 1))).toString(),
      label: (item.label || '').trim(),
      img: item.img || ''
    }));
  }

  function createItemId() {
    return 'i' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
  }

  function filenameToLabel(name) {
    return (name || '').replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim();
  }

  function clearPending(itemId) {
    const url = pendingPreview.get(itemId);
    if (url) URL.revokeObjectURL(url);
    pendingPreview.delete(itemId);
    pendingFiles.delete(itemId);
  }

  function clearAllPending() {
    for (const id of Array.from(pendingPreview.keys())) {
      clearPending(id);
    }
  }

  function setCurrent(set) {
    current = {
      id: set.id || '',
      title: set.title || '',
      items: normalizeItems(Array.isArray(set.items) ? set.items : [])
    };

    selectedId = current.id;
    form.id.value = current.id;
    form.title.value = current.title;
    btnPlay.href = current.id ? `${BASE}/?p=play&set=${encodeURIComponent(current.id)}` : '#';
    renderList();
    renderItems();
    setDirty(false);
  }

  function renderList() {
    const q = (searchInput?.value || '').trim().toLowerCase();
    const rows = allSets.filter((set) => {
      if (!q) return true;
      return (set.title || '').toLowerCase().includes(q) || (set.id || '').toLowerCase().includes(q);
    });

    listEl.innerHTML = rows.map((set) => `
      <div class="list-row ${selectedId === set.id ? 'active' : ''}">
        <div>
          <div class="strong">${esc(set.title)}</div>
          <div class="muted">${esc(set.id)} • ${esc(String(set.itemCount || 0))} item(s)</div>
        </div>
        <div class="row">
          <button class="btn ghost" data-act="edit" data-id="${esc(set.id)}">Éditer</button>
          <a class="btn ghost" href="${BASE}/?p=play&set=${encodeURIComponent(set.id)}">Jouer</a>
          <button class="btn danger" data-act="delete" data-id="${esc(set.id)}">Suppr.</button>
        </div>
      </div>
    `).join('') || '<p class="muted">Aucun set.</p>';
  }

  function renderItems() {
    const rows = current.items.map((item, idx) => {
      const preview = pendingPreview.get(item.id) || assetUrl(item.img);
      const pending = pendingFiles.has(item.id);
      return `
        <tr data-idx="${idx}">
          <td class="col-preview">${preview ? `<img src="${esc(preview)}" alt="">` : '<span class="muted">-</span>'}</td>
          <td class="col-label"><input data-kind="label" data-idx="${idx}" value="${esc(item.label)}" placeholder="Libellé"></td>
          <td class="col-status">${pending ? '<span class="status-chip">En attente</span>' : '<span class="muted">OK</span>'}</td>
          <td class="col-actions"><button class="btn danger" type="button" data-act="remove-item" data-idx="${idx}">Supprimer</button></td>
        </tr>
      `;
    }).join('');

    const canAddImages = !!current.id;
    const addHint = canAddImages
      ? `${current.items.length} image(s)`
      : 'Créez le set puis enregistrez pour ajouter des images';

    itemsEditor.innerHTML = `
      <div class="items-toolbar">
        <button class="btn" id="btnAddImages" type="button" ${canAddImages ? '' : 'disabled'}>Ajouter image(s)</button>
        <input id="inputAddImages" type="file" accept="image/*" multiple hidden>
        <span class="muted">${addHint}</span>
      </div>
      <div class="items-table-wrap">
        <table class="items-table">
          <thead>
            <tr><th>Image</th><th>Libellé</th><th>Etat</th><th>Action</th></tr>
          </thead>
          <tbody>${rows || '<tr><td colspan="4" class="muted">Aucune image. Cliquez sur "Ajouter image(s)".</td></tr>'}</tbody>
        </table>
      </div>
    `;

    const btnAddImages = el('#btnAddImages', itemsEditor);
    const inputAddImages = el('#inputAddImages', itemsEditor);
    btnAddImages?.addEventListener('click', () => {
      if (!current.id) {
        toast('Enregistrez d\'abord le set, puis ajoutez les images.', 'warn');
        setStatus('Créez d\'abord le set (titre + enregistrer)', 'warn');
        return;
      }
      inputAddImages?.click();
    });
    inputAddImages?.addEventListener('change', () => addFiles(inputAddImages.files));
  }

  function readForm() {
    current.id = (form.id.value || '').trim();
    current.title = (form.title.value || '').trim();
    current.items = normalizeItems(current.items);
  }

  async function refreshList() {
    setStatus('Chargement des sets...', 'info');
    const res = await api('list_sets');
    if (!res.ok) {
      listEl.textContent = res.error || 'Erreur';
      setStatus('Erreur de chargement', 'error');
      return;
    }
    allSets = Array.isArray(res.sets) ? res.sets : [];
    renderList();
    setStatus('Prêt', 'ok');
  }

  async function loadSet(id) {
    if (!ensureLeaveDirty()) return;
    setBusy(true, 'Ouverture...');
    const res = await api('get_set', { id });
    setBusy(false);
    if (!res.ok) {
      toast(res.error || 'Set introuvable', 'error');
      return;
    }
    clearAllPending();
    setCurrent(res.set);
    toast(`Set "${id}" chargé`, 'success');
  }

  async function uploadOne(itemId, file) {
    const fd = new FormData();
    fd.append('file', file, file.name || `${itemId}.jpg`);
    return api('upload_image', { set: current.id, item: itemId }, fd, true);
  }

  async function addFiles(fileList) {
    if (!current.id) {
      toast('Enregistrez d\'abord le set, puis ajoutez les images.', 'warn');
      setStatus('Créez d\'abord le set (titre + enregistrer)', 'warn');
      return;
    }

    if (!fileList || fileList.length === 0) return;

    const files = Array.from(fileList).filter((f) => f && /^image\//i.test(f.type || ''));
    if (files.length === 0) {
      toast('Aucune image valide', 'error');
      return;
    }

    setStatus(`Ajout de ${files.length} image(s)...`, 'info');

    for (const file of files) {
      const id = createItemId();
      const item = { id, label: filenameToLabel(file.name), img: '' };
      current.items.push(item);

      if (current.id) {
        const up = await uploadOne(id, file);
        if (up.ok) {
          item.img = up.url;
        } else {
          pendingFiles.set(id, file);
          const objectUrl = URL.createObjectURL(file);
          pendingPreview.set(id, objectUrl);
          toast(`Upload différé pour ${file.name}`, 'warn');
        }
      } else {
        pendingFiles.set(id, file);
        const objectUrl = URL.createObjectURL(file);
        pendingPreview.set(id, objectUrl);
      }
    }

    renderItems();
    setDirty(true);
    setStatus('Images ajoutées', 'ok');
  }

  async function uploadPendingFiles() {
    if (!current.id || pendingFiles.size === 0) return;

    const entries = Array.from(pendingFiles.entries());
    let done = 0;

    for (const [itemId, file] of entries) {
      done += 1;
      setStatus(`Upload images ${done}/${entries.length}...`, 'info');
      const up = await uploadOne(itemId, file);
      if (up.ok) {
        const item = current.items.find((it) => it.id === itemId);
        if (item) item.img = up.url;
        clearPending(itemId);
      } else {
        toast(`Upload échoué (${file.name})`, 'error');
      }
    }
  }

  async function saveCurrent() {
    if (busy) return;

    readForm();
    if (!current.title) {
      toast('Titre requis', 'error');
      setStatus('Le titre est obligatoire', 'error');
      return;
    }


    setBusy(true, 'Enregistrement...');
    setStatus('Enregistrement du set...', 'info');

    const first = await api('save_set', {}, current);
    if (!first.ok) {
      setBusy(false);
      toast(first.error || 'Erreur enregistrement', 'error');
      setStatus('Échec enregistrement', 'error');
      return;
    }

    setCurrent(first.set);
    await uploadPendingFiles();

    const finalSave = await api('save_set', {}, current);
    if (!finalSave.ok) {
      setBusy(false);
      toast(finalSave.error || 'Erreur finalisation', 'error');
      setStatus('Échec finalisation', 'error');
      return;
    }

    setCurrent(finalSave.set);
    await refreshList();
    setBusy(false);
    setDirty(false);
    setStatus('Enregistré', 'ok');
    toast('Set enregistré', 'success');
  }

  async function deleteSetById(id) {
    if (!id || busy) return;
    if (!window.confirm(`Supprimer le set "${id}" ?`)) return;

    setBusy(true, 'Suppression...');
    const res = await api('delete_set', { id });
    setBusy(false);

    if (!res.ok) {
      toast(res.error || 'Erreur suppression', 'error');
      setStatus('Échec suppression', 'error');
      return;
    }

    if (current.id === id) {
      clearAllPending();
      setCurrent({ id: '', title: '', items: [] });
    }

    await refreshList();
    toast(`Set "${id}" supprimé`, 'success');
    setStatus('Set supprimé', 'ok');
  }

  async function openShareModal(setId) {
    const res = await api('create_share_link', { id: setId });
    if (!res.ok) {
      toast(res.error || 'Erreur création lien', 'error');
      return;
    }

    shareUrl.value = res.url;
    const exp = new Date(res.expiresAt);
    shareExpiry.textContent = 'Valable jusqu\'au ' + exp.toLocaleString('fr-FR');

    shareQr.innerHTML = '';
    const renderQr = () => {
      if (typeof window.QRCode === 'undefined') return;
      new window.QRCode(shareQr, {
        text: res.url,
        width: 180,
        height: 180,
        correctLevel: window.QRCode.CorrectLevel.M
      });
    };

    renderQr();
    if (typeof window.QRCode === 'undefined') window.setTimeout(renderQr, 250);
    shareModal.hidden = false;
  }

  listEl.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const id = btn.dataset.id || '';
    if (btn.dataset.act === 'edit') await loadSet(id);
    if (btn.dataset.act === 'delete') await deleteSetById(id);
  });

  itemsEditor.addEventListener('input', (e) => {
    const input = e.target;
    if (!(input instanceof HTMLInputElement)) return;
    const idx = Number(input.dataset.idx);
    if (Number.isNaN(idx)) return;

    if (input.dataset.kind === 'label') {
      current.items[idx].label = input.value;
      setDirty(true);
    }
  });

  itemsEditor.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;

    if (btn.dataset.act === 'remove-item') {
      const idx = Number(btn.dataset.idx);
      if (Number.isNaN(idx)) return;
      const item = current.items[idx];
      if (item?.id) clearPending(item.id);
      current.items.splice(idx, 1);
      renderItems();
      setDirty(true);
    }
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await saveCurrent();
  });

  form.addEventListener('input', () => {
    if (!busy) setDirty(true);
  });

  btnNew.addEventListener('click', () => {
    if (!ensureLeaveDirty()) return;
    clearAllPending();
    setCurrent({ id: '', title: '', items: [] });
    if (searchInput) searchInput.value = '';
    renderList();
    toast('Nouveau set prêt', 'info');
  });

  btnDelete.addEventListener('click', async () => {
    readForm();
    if (!current.id) return;
    await deleteSetById(current.id);
  });

  btnShare.addEventListener('click', async () => {
    readForm();
    if (!current.id) {
      toast('Enregistrez d\'abord le set', 'error');
      return;
    }
    await openShareModal(current.id);
  });

  btnCopyShare.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(shareUrl.value || '');
      toast('Lien copié', 'success');
    } catch {
      shareUrl.select();
      document.execCommand('copy');
      toast('Lien copié', 'success');
    }
  });

  searchInput?.addEventListener('input', renderList);

  const closeShare = () => { shareModal.hidden = true; };
  btnCloseShare.addEventListener('click', closeShare);
  el('.modal-backdrop', shareModal).addEventListener('click', closeShare);

  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
      e.preventDefault();
      saveCurrent();
    }
  });

  window.addEventListener('beforeunload', (e) => {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = '';
  });

  await refreshList();
  setCurrent({ id: '', title: '', items: [] });
}
document.addEventListener('DOMContentLoaded', () => {
  const page = window.__PAGE__;
  if (page === 'home') initHome();
  if (page === 'admin') initAdmin();
  if (page === 'play') initPlay();
});








