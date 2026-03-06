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
  if (path.startsWith('/')) return BASE + path;
  return BASE + '/' + path;
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

async function initPlay() {
  const stage = el('#playStage');
  const image = el('#playImage');
  const caption = el('#playCaption');
  const hint = el('#playHint');
  const title = el('#setTitle');
  const state = el('#playState');

  const setId = (window.__SET_ID__ || window.__SHARE_SET__ || '').trim();
  if (!setId) {
    title.textContent = 'Set manquant';
    return;
  }

  const res = await api('get_set', { id: setId });
  if (!res.ok) {
    title.textContent = res.error || 'Set introuvable';
    return;
  }

  const set = res.set || {};
  const items = Array.isArray(set.items) ? set.items.filter((it) => it && it.img) : [];
  if (!items.length) {
    title.textContent = set.title || setId;
    caption.hidden = false;
    caption.textContent = 'Ce set ne contient pas d\'image.';
    return;
  }

  title.textContent = set.title || setId;

  let running = true;
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
    image.src = assetUrl(item.img);
    image.alt = item.label || '';
    caption.textContent = item.label || '';
  }

  function loop() {
    current = pickRandom();
    render(current);
  }

  function start() {
    running = true;
    state.textContent = 'Défilement';
    hint.textContent = 'Clique pour figer et voir la légende';
    caption.hidden = true;
    loop();
    timer = window.setInterval(loop, 200);
  }

  function stop() {
    running = false;
    window.clearInterval(timer);
    timer = null;
    state.textContent = 'Figé';
    hint.textContent = 'Clique pour reprendre';
    caption.hidden = false;
  }

  stage.addEventListener('click', () => {
    if (running) {
      stop();
    } else {
      start();
    }
  });

  start();

  if (SHARE_MODE && window.__SHARE_EXP__) {
    const msLeft = (Number(window.__SHARE_EXP__) * 1000) - Date.now();
    if (msLeft > 0) {
      window.setTimeout(() => {
        running = false;
        if (timer) window.clearInterval(timer);
        stage.disabled = true;
        state.textContent = 'Expiré';
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

  const shareModal = el('#shareModal');
  const shareUrl = el('#shareUrl');
  const shareExpiry = el('#shareExpiry');
  const shareQr = el('#shareQr');
  const btnCopyShare = el('#btnCopyShare');
  const btnCloseShare = el('#btnCloseShare');

  let current = { id: '', title: '', items: [] };
  const pendingFiles = new Map();

  function defaultItems() {
    return [{ id: 'i1', label: '', img: '' }];
  }

  function setCurrent(set) {
    current = {
      id: set.id || '',
      title: set.title || '',
      items: Array.isArray(set.items) && set.items.length ? set.items : defaultItems()
    };
    form.id.value = current.id;
    form.title.value = current.title;
    btnPlay.href = current.id ? `${BASE}/?p=play&set=${encodeURIComponent(current.id)}` : '#';
    renderItems();
  }

  function renderItems() {
    itemsEditor.innerHTML = `
      <div class="row between">
        <h4>Items (${current.items.length})</h4>
        <button type="button" class="btn ghost" data-act="add-item">Ajouter un item</button>
      </div>
      ${current.items.map((item, idx) => `
        <article class="item-row" data-idx="${idx}">
          <div class="preview">${item.img ? `<img src="${esc(assetUrl(item.img))}" alt="">` : '<span>Image</span>'}</div>
          <div class="fields">
            <label>
              <span>Légende</span>
              <input data-kind="label" data-idx="${idx}" value="${esc(item.label)}">
            </label>
            <label>
              <span>Image locale</span>
              <input data-kind="file" data-idx="${idx}" type="file" accept="image/*">
            </label>
            <button type="button" class="btn danger" data-act="remove-item" data-idx="${idx}" ${current.items.length <= 1 ? 'disabled' : ''}>Supprimer</button>
          </div>
        </article>
      `).join('')}
    `;
  }

  function readForm() {
    current.id = (form.id.value || '').trim();
    current.title = (form.title.value || '').trim();
    current.items = current.items.map((item, idx) => ({
      id: item.id || ('i' + (idx + 1)),
      label: item.label || '',
      img: item.img || ''
    }));
  }

  async function refreshList() {
    const res = await api('list_sets');
    if (!res.ok) {
      listEl.textContent = res.error || 'Erreur';
      return;
    }
    const sets = res.sets || [];
    listEl.innerHTML = sets.map((set) => `
      <div class="list-row">
        <div>
          <div class="strong">${esc(set.title)}</div>
          <div class="muted">${esc(set.id)}</div>
        </div>
        <div class="row">
          <button class="btn ghost" data-act="edit" data-id="${esc(set.id)}">Éditer</button>
          <button class="btn danger" data-act="delete" data-id="${esc(set.id)}">Suppr.</button>
        </div>
      </div>
    `).join('') || '<p class="muted">Aucun set.</p>';
  }

  async function loadSet(id) {
    const res = await api('get_set', { id });
    if (!res.ok) {
      alert(res.error || 'Set introuvable');
      return;
    }
    pendingFiles.clear();
    setCurrent(res.set);
  }

  async function uploadPendingFiles() {
    if (!current.id) return;
    for (const [itemId, file] of pendingFiles.entries()) {
      const fd = new FormData();
      fd.append('file', file, file.name || `${itemId}.jpg`);
      const up = await api('upload_image', { set: current.id, item: itemId }, fd, true);
      if (up.ok) {
        const item = current.items.find((it) => it.id === itemId);
        if (item) item.img = up.url;
      }
    }
    pendingFiles.clear();
  }

  async function saveCurrent() {
    readForm();
    if (!current.title) {
      alert('Titre requis');
      return;
    }

    const res = await api('save_set', {}, current);
    if (!res.ok) {
      alert(res.error || 'Erreur enregistrement');
      return;
    }

    setCurrent(res.set);
    await uploadPendingFiles();

    const post = await api('save_set', {}, current);
    if (!post.ok) {
      alert(post.error || 'Erreur post-upload');
      return;
    }

    setCurrent(post.set);
    await refreshList();
    alert('Set enregistré');
  }

  async function deleteCurrent() {
    readForm();
    if (!current.id) return;
    if (!confirm(`Supprimer le set "${current.id}" ?`)) return;

    const res = await api('delete_set', { id: current.id });
    if (!res.ok) {
      alert(res.error || 'Erreur suppression');
      return;
    }

    pendingFiles.clear();
    setCurrent({ id: '', title: '', items: defaultItems() });
    await refreshList();
  }

  async function openShareModal(setId) {
    const res = await api('create_share_link', { id: setId });
    if (!res.ok) {
      alert(res.error || 'Erreur création lien');
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
    if (typeof window.QRCode === 'undefined') {
      window.setTimeout(renderQr, 250);
    }

    shareModal.hidden = false;
  }

  listEl.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const id = btn.dataset.id || '';

    if (btn.dataset.act === 'edit') await loadSet(id);
    if (btn.dataset.act === 'delete') {
      if (!confirm(`Supprimer le set "${id}" ?`)) return;
      const res = await api('delete_set', { id });
      if (!res.ok) {
        alert(res.error || 'Erreur suppression');
        return;
      }
      if (current.id === id) setCurrent({ id: '', title: '', items: defaultItems() });
      await refreshList();
    }
  });

  itemsEditor.addEventListener('input', (e) => {
    const input = e.target;
    if (!(input instanceof HTMLInputElement)) return;
    const idx = Number(input.dataset.idx);
    if (Number.isNaN(idx)) return;

    if (input.dataset.kind === 'label') {
      current.items[idx].label = input.value;
    }
  });

  itemsEditor.addEventListener('change', (e) => {
    const input = e.target;
    if (!(input instanceof HTMLInputElement)) return;
    const idx = Number(input.dataset.idx);
    if (Number.isNaN(idx)) return;

    if (input.dataset.kind === 'file') {
      const file = input.files && input.files[0];
      if (!file) return;

      const item = current.items[idx];
      if (!item.id) item.id = 'i' + (idx + 1);

      if (current.id) {
        const fd = new FormData();
        fd.append('file', file, file.name);
        api('upload_image', { set: current.id, item: item.id }, fd, true).then((up) => {
          if (!up.ok) {
            alert(up.error || 'Upload échoué');
            return;
          }
          current.items[idx].img = up.url;
          renderItems();
        });
      } else {
        pendingFiles.set(item.id, file);
      }
    }
  });

  itemsEditor.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-act]');
    if (!btn) return;

    if (btn.dataset.act === 'add-item') {
      current.items.push({ id: 'i' + Date.now().toString(36), label: '', img: '' });
      renderItems();
      return;
    }

    if (btn.dataset.act === 'remove-item') {
      const idx = Number(btn.dataset.idx);
      if (Number.isNaN(idx)) return;
      if (current.items.length <= 1) return;
      const item = current.items[idx];
      if (item && item.id) pendingFiles.delete(item.id);
      current.items.splice(idx, 1);
      renderItems();
    }
  });

  btnNew.addEventListener('click', () => {
    pendingFiles.clear();
    setCurrent({ id: '', title: '', items: defaultItems() });
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await saveCurrent();
  });

  btnDelete.addEventListener('click', deleteCurrent);

  btnShare.addEventListener('click', async () => {
    readForm();
    if (!current.id) {
      alert('Enregistre d\'abord le set pour générer le lien.');
      return;
    }
    await openShareModal(current.id);
  });

  btnCopyShare.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(shareUrl.value || '');
      btnCopyShare.textContent = 'Copié';
      window.setTimeout(() => { btnCopyShare.textContent = 'Copier le lien'; }, 1200);
    } catch {
      shareUrl.select();
      document.execCommand('copy');
    }
  });

  const closeShare = () => { shareModal.hidden = true; };
  btnCloseShare.addEventListener('click', closeShare);
  el('.modal-backdrop', shareModal).addEventListener('click', closeShare);

  await refreshList();
  setCurrent({ id: '', title: '', items: defaultItems() });
}

document.addEventListener('DOMContentLoaded', () => {
  const page = window.__PAGE__;
  if (page === 'home') initHome();
  if (page === 'admin') initAdmin();
  if (page === 'play') initPlay();
});
