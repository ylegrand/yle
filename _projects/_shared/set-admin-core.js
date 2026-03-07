(function (global) {
  function defaultEsc(s) {
    return (s ?? '').toString().replace(/[&<>"']/g, function (m) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m];
    });
  }

  function ensureToastStack() {
    var stack = document.querySelector('#toastStack');
    if (stack) return stack;
    stack = document.createElement('div');
    stack.id = 'toastStack';
    stack.className = 'toast-stack';
    document.body.appendChild(stack);
    return stack;
  }

  function toast(message, kind) {
    var stack = ensureToastStack();
    var node = document.createElement('div');
    node.className = 'toast toast-' + (kind || 'info');
    node.textContent = message;
    stack.appendChild(node);
    window.setTimeout(function () {
      node.classList.add('hide');
      window.setTimeout(function () { node.remove(); }, 220);
    }, 2200);
  }

  function init(cfg) {
    var api = cfg.api;
    var assetUrl = cfg.assetUrl;
    var esc = cfg.esc || defaultEsc;

    var listEl = document.querySelector('#setList');
    var form = document.querySelector('#setForm');
    var itemsEditor = document.querySelector('#itemsEditor');
    var checklistEl = document.querySelector('#setChecklist');
    var btnNew = document.querySelector('#btnNewSet');
    var btnDelete = document.querySelector('#btnDeleteSet');
    var btnPreview = document.querySelector(cfg.previewSelector);
    var btnShare = document.querySelector('#btnShare');
    var btnSave = document.querySelector('#btnSaveSet');
    var statusEl = document.querySelector('#adminStatus');
    var searchInput = document.querySelector('#setSearch');

    var shareModal = document.querySelector('#shareModal');
    var shareUrl = document.querySelector('#shareUrl');
    var shareExpiry = document.querySelector('#shareExpiry');
    var shareQr = document.querySelector('#shareQr');
    var btnCopyShare = document.querySelector('#btnCopyShare');
    var btnCloseShare = document.querySelector('#btnCloseShare');

    var current = cfg.emptySet();
    var allSets = [];
    var selectedId = '';
    var dirty = false;
    var busy = false;

    var pendingUploads = new Map();
    var pendingPreview = new Map();

    function setStatus(text, tone) {
      if (!statusEl) return;
      statusEl.textContent = text;
      statusEl.className = 'status-pill status-' + (tone || 'info');
    }

    function setBusy(next, label) {
      busy = next;
      if (btnSave) {
        btnSave.disabled = next;
        btnSave.setAttribute('aria-busy', next ? 'true' : 'false');
        btnSave.textContent = next ? (label || 'Traitement...') : 'Enregistrer';
      }
      if (btnDelete) btnDelete.disabled = next;
      if (btnNew) btnNew.disabled = next;
      if (btnShare) btnShare.disabled = next;
      if (btnPreview) {
        var disabled = next || !current.id;
        btnPreview.classList.toggle('is-disabled', disabled);
        btnPreview.setAttribute('aria-disabled', disabled ? 'true' : 'false');
      }
    }

    function checklistState() {
      var items = current.items || [];
      var withImg = items.filter(function (it) { return it.img || pendingPreview.get(it.id); }).length;
      var withLabel = items.filter(function (it) { return (it.label || '').trim() !== ''; }).length;
      return {
        hasTitle: (current.title || '').trim() !== '',
        hasEnoughItems: items.length >= 4,
        hasAllImages: items.length > 0 && withImg === items.length,
        hasAllLabels: items.length > 0 && withLabel === items.length
      };
    }

    function renderChecklist() {
      if (!checklistEl) return;
      var state = checklistState();
      var rows = [
        { ok: state.hasTitle, label: 'Titre renseigne' },
        { ok: state.hasEnoughItems, label: 'Au moins 4 items' },
        { ok: state.hasAllImages, label: 'Images presentes' },
        { ok: state.hasAllLabels, label: 'Libelles renseignes' }
      ];
      checklistEl.innerHTML = rows.map(function (row) {
        return '<span class="check-chip ' + (row.ok ? 'ok' : 'ko') + '">' +
          (row.ok ? 'OK' : 'A faire') + ' - ' + row.label + '</span>';
      }).join('');
    }

    function setDirty(next) {
      dirty = next;
      if (dirty) setStatus('Modifications non enregistrees', 'warn');
      else setStatus('Pret', 'ok');
      renderChecklist();
    }

    function ensureLeaveDirty() {
      if (!dirty || busy) return true;
      return window.confirm('Vous avez des modifications non enregistrees. Continuer ?');
    }

    function normalizeItems(items) {
      return (items || []).map(function (it, i) {
        return {
          id: (it.id || ('i' + (i + 1))).toString(),
          label: (it.label || '').toString(),
          img: (it.img || '').toString()
        };
      });
    }

    function createItemId() {
      return 'i' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
    }

    function filenameToLabel(name) {
      return (name || '').replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim();
    }

    function clearPendingImage(itemId) {
      var old = pendingPreview.get(itemId);
      if (old) URL.revokeObjectURL(old);
      pendingPreview.delete(itemId);
      pendingUploads.delete(itemId);
    }

    function clearAllPending() {
      Array.from(pendingPreview.keys()).forEach(clearPendingImage);
    }

    function setCurrent(next) {
      current = cfg.fromSet(next);
      current.items = normalizeItems(current.items || []);

      selectedId = current.id || '';
      cfg.writeForm(form, current);

      if (btnPreview) {
        if (current.id) {
          btnPreview.href = cfg.previewHref(current.id);
          btnPreview.classList.remove('is-disabled');
          btnPreview.setAttribute('aria-disabled', 'false');
        } else {
          btnPreview.href = '#';
          btnPreview.classList.add('is-disabled');
          btnPreview.setAttribute('aria-disabled', 'true');
        }
      }

      renderList();
      renderItems();
      renderChecklist();
      setDirty(false);
    }

    function readFormToCurrent() {
      cfg.readForm(form, current);
      current.items = normalizeItems(current.items || []);
    }

    function renderList() {
      var q = ((searchInput && searchInput.value) || '').trim().toLowerCase();
      var rows = allSets.filter(function (set) {
        if (!q) return true;
        return (set.title || '').toLowerCase().indexOf(q) !== -1;
      });

      listEl.innerHTML = rows.map(function (s) {
        return '<div class="list-row ' + (selectedId === s.id ? 'active' : '') + '">' +
          '<div><div class="strong">' + esc(s.title) + '</div><div class="muted small">' + cfg.listMeta(s, esc) + '</div></div>' +
          '<div class="row gap wrap">' +
          '<button class="btn btn--ghost" data-action="edit" data-id="' + esc(s.id) + '">Editer</button>' +
          '<a class="btn btn--ghost" href="' + cfg.previewHref(s.id) + '">Jouer</a>' +
          '<button class="btn btn--ghost" data-action="share" data-id="' + esc(s.id) + '">Lien + QR</button>' +
          '<button class="btn btn--danger" data-action="delete" data-id="' + esc(s.id) + '">Supprimer</button>' +
          '</div></div>';
      }).join('') || '<div class="muted">Aucun set.</div>';
    }

    function renderItems() {
      var cards = current.items.map(function (it, idx) {
        var preview = pendingPreview.get(it.id) || (it.img ? assetUrl(it.img) : '');
        var pending = pendingUploads.has(it.id);
        return '<article class="item-card" data-idx="' + idx + '">' +
          '<div class="item-card-preview">' + (preview ? '<img src="' + esc(preview) + '" alt="">' : '<span class="muted">Aucune image</span>') + '</div>' +
          '<div class="item-card-main">' +
          '<input data-kind="label" data-idx="' + idx + '" value="' + esc(it.label) + '" placeholder="Libelle" aria-label="Libelle item ' + (idx + 1) + '">' +
          '<div class="item-card-meta">' + (pending ? '<span class="status-chip">Upload en attente</span>' : '<span class="muted">Image prete</span>') + '</div>' +
          '</div>' +
          '<div class="item-card-actions"><button type="button" class="btn btn--danger" data-action="remove-item" data-idx="' + idx + '">Supprimer</button></div>' +
          '</article>';
      }).join('');

      var addHint = current.id
        ? (current.items.length + ' image(s)')
        : 'Saisissez un titre puis ajoutez des images (creation auto du set)';

      itemsEditor.innerHTML = '<div class="items-toolbar">' +
        '<button type="button" class="btn" id="btnAddImages">Ajouter image(s)</button>' +
        '<input id="inputAddImages" type="file" accept="image/*" multiple hidden>' +
        '<span class="muted">' + addHint + '</span></div>' +
        '<div class="items-cards">' +
        (cards || '<p class="muted">Aucune image. Cliquez sur "Ajouter image(s)".</p>') +
        '</div>';

      var btnAddImages = itemsEditor.querySelector('#btnAddImages');
      var inputAddImages = itemsEditor.querySelector('#inputAddImages');
      if (btnAddImages) btnAddImages.addEventListener('click', function () { inputAddImages && inputAddImages.click(); });
      if (inputAddImages) inputAddImages.addEventListener('change', function () { addFiles(inputAddImages.files); });
    }

    async function uploadOne(itemId, file) {
      var fd = new FormData();
      fd.append('file', file, file.name || (itemId + cfg.uploadExt));
      return api('upload_image', { set: current.id, item: itemId }, fd, true);
    }

    async function ensurePersistedSet() {
      if (current.id) return true;
      readFormToCurrent();
      if (!current.title) {
        toast('Renseignez le titre avant d\'ajouter des images.', 'warn');
        setStatus('Titre requis avant upload', 'warn');
        form.title && form.title.focus();
        return false;
      }

      setBusy(true, 'Creation...');
      var created = await api('save_set', {}, cfg.toPayload(current));
      setBusy(false);
      if (!created.ok) {
        toast(created.error || 'Creation du set impossible', 'error');
        setStatus('Creation du set echouee', 'error');
        return false;
      }

      setCurrent(created.set);
      await refreshList();
      setStatus('Set cree, vous pouvez ajouter des images', 'ok');
      return true;
    }

    async function addFiles(fileList) {
      if (!(await ensurePersistedSet())) return;
      var files = Array.from(fileList || []).filter(function (f) { return f && /^image\//i.test(f.type || ''); });
      if (!files.length) return;

      setStatus('Ajout de ' + files.length + ' image(s)...', 'info');
      for (var i = 0; i < files.length; i += 1) {
        var file = files[i];
        var itemId = createItemId();
        var row = { id: itemId, label: filenameToLabel(file.name), img: '' };
        current.items.push(row);

        var up = await uploadOne(itemId, file);
        if (up.ok) {
          row.img = up.url;
        } else {
          pendingUploads.set(itemId, file);
          pendingPreview.set(itemId, URL.createObjectURL(file));
        }
      }
      renderItems();
      renderChecklist();
      setDirty(true);
      setStatus('Images ajoutees', 'ok');
    }

    async function uploadPendingFiles() {
      if (!current.id || pendingUploads.size === 0) return;
      var entries = Array.from(pendingUploads.entries());
      for (var i = 0; i < entries.length; i += 1) {
        var entry = entries[i];
        var itemId = entry[0];
        var file = entry[1];
        setStatus('Upload images ' + (i + 1) + '/' + entries.length + '...', 'info');
        var up = await uploadOne(itemId, file);
        if (up.ok) {
          var it = current.items.find(function (x) { return x.id === itemId; });
          if (it) it.img = up.url;
          clearPendingImage(itemId);
        } else {
          toast('Upload echoue (' + file.name + ')', 'error');
        }
      }
    }

    async function refreshList() {
      setStatus('Chargement des sets...', 'info');
      var res = await api('list_sets');
      if (!res.ok) {
        listEl.textContent = res.error || 'Erreur';
        setStatus('Erreur de chargement', 'error');
        return;
      }
      allSets = res.sets || [];
      renderList();
      setStatus('Pret', 'ok');
    }

    async function loadSet(id) {
      if (!ensureLeaveDirty()) return;
      setBusy(true, 'Ouverture...');
      var res = await api('get_set', { id: id });
      setBusy(false);
      if (!res.ok) {
        toast(res.error || 'Set introuvable', 'error');
        return;
      }
      clearAllPending();
      setCurrent(res.set);
      toast('Set charge', 'success');
    }

    async function deleteSetById(id) {
      if (!id || busy) return;
      var target = allSets.find(function (set) { return set.id === id; });
      var name = (target && target.title) || 'ce set';
      if (!window.confirm('Supprimer "' + name + '" ?')) return;

      setBusy(true, 'Suppression...');
      var res = await api('delete_set', { id: id });
      setBusy(false);
      if (!res.ok) {
        toast(res.error || 'Erreur suppression', 'error');
        setStatus('Echec suppression', 'error');
        return;
      }

      if (current.id === id) {
        clearAllPending();
        setCurrent(cfg.emptySet());
      }

      await refreshList();
      toast('Set supprime', 'success');
      setStatus('Set supprime', 'ok');
    }

    async function saveCurrent() {
      if (busy) return;

      readFormToCurrent();
      if (!current.title) {
        toast('Titre requis', 'error');
        setStatus('Le titre est obligatoire', 'error');
        return;
      }

      setBusy(true, 'Enregistrement...');
      setStatus('Enregistrement du set...', 'info');

      var first = await api('save_set', {}, cfg.toPayload(current));
      if (!first.ok) {
        setBusy(false);
        toast(first.error || 'Erreur enregistrement', 'error');
        setStatus('Echec enregistrement', 'error');
        return;
      }

      setCurrent(first.set);
      await uploadPendingFiles();

      var finalSave = await api('save_set', {}, cfg.toPayload(current));
      if (!finalSave.ok) {
        setBusy(false);
        toast(finalSave.error || 'Erreur finalisation', 'error');
        setStatus('Echec finalisation', 'error');
        return;
      }

      setCurrent(finalSave.set);
      await refreshList();
      setBusy(false);
      setDirty(false);
      setStatus('Enregistre', 'ok');
      toast('Set enregistre', 'success');
    }

    async function openShareModal(setId) {
      var res = await api('create_share_link', { id: setId });
      if (!res.ok) {
        toast(res.error || 'Erreur creation lien', 'error');
        return;
      }

      shareUrl.value = res.url;
      var exp = new Date(res.expiresAt);
      shareExpiry.textContent = 'Valable jusqu\'au ' + exp.toLocaleString('fr-FR');

      shareQr.innerHTML = '';
      var renderQr = function () {
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

    btnNew.addEventListener('click', function () {
      if (!ensureLeaveDirty()) return;
      clearAllPending();
      setCurrent(cfg.emptySet());
      if (searchInput) searchInput.value = '';
      renderList();
      toast('Nouveau set pret', 'info');
    });

    listEl.addEventListener('click', async function (e) {
      var btn = e.target.closest('button[data-action]');
      if (!btn) return;
      var id = btn.dataset.id || '';
      if (btn.dataset.action === 'edit') await loadSet(id);
      if (btn.dataset.action === 'delete') await deleteSetById(id);
      if (btn.dataset.action === 'share') await openShareModal(id);
    });

    itemsEditor.addEventListener('input', function (e) {
      var inp = e.target;
      if (!(inp instanceof HTMLInputElement)) return;
      var idx = Number(inp.dataset.idx);
      if (Number.isNaN(idx)) return;
      if (inp.dataset.kind === 'label') {
        current.items[idx].label = inp.value;
        setDirty(true);
      }
    });

    itemsEditor.addEventListener('click', function (e) {
      var btn = e.target instanceof HTMLElement ? e.target.closest('button[data-action]') : null;
      if (!btn) return;
      if (btn.getAttribute('data-action') === 'remove-item') {
        var idx = Number(btn.getAttribute('data-idx'));
        if (Number.isNaN(idx)) return;
        var removed = current.items[idx];
        current.items.splice(idx, 1);
        if (removed && removed.id) clearPendingImage(removed.id);
        renderItems();
        renderChecklist();
        setDirty(true);
      }
    });

    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      await saveCurrent();
    });

    form.addEventListener('input', function () {
      if (!busy) setDirty(true);
    });

    btnDelete.addEventListener('click', async function () {
      readFormToCurrent();
      if (!current.id) return;
      await deleteSetById(current.id);
    });

    if (btnShare) {
      btnShare.addEventListener('click', async function () {
        readFormToCurrent();
        if (!current.id) {
          toast('Enregistrez d\'abord le set', 'error');
          return;
        }
        await openShareModal(current.id);
      });
    }

    if (btnCopyShare) {
      btnCopyShare.addEventListener('click', async function () {
        try {
          await navigator.clipboard.writeText(shareUrl.value || '');
          toast('Lien copie', 'success');
        } catch (err) {
          shareUrl.select();
          document.execCommand('copy');
          toast('Lien copie', 'success');
        }
      });
    }

    var closeShare = function () { if (shareModal) shareModal.hidden = true; };
    if (btnCloseShare) btnCloseShare.addEventListener('click', closeShare);
    var backdrop = shareModal ? shareModal.querySelector('.modal-backdrop, .modal__backdrop') : null;
    if (backdrop) backdrop.addEventListener('click', closeShare);

    if (searchInput) searchInput.addEventListener('input', renderList);

    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        saveCurrent();
      }
    });

    window.addEventListener('beforeunload', function (e) {
      if (!dirty) return;
      e.preventDefault();
      e.returnValue = '';
    });

    return refreshList().then(function () {
      setCurrent(cfg.emptySet());
    });
  }

  global.SetAdminCore = { init: init };
})(window);
