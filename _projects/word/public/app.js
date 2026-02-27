/* Single JS for home + play + admin */
const BASE = (window.__BASE__ && window.__BASE__ !== "/") ? window.__BASE__ : "";
const API = (action, params = {}, body = null, isForm = false) => {
  const url = new URL(location.origin + BASE + "/?p=api");
  url.searchParams.set("action", action);
  Object.entries(params).forEach(([k,v]) => url.searchParams.set(k, v));
  return fetch(url.toString(), {
    method: "POST",
    headers: isForm ? {} : {"Content-Type":"application/json"},
    body: isForm ? body : (body ? JSON.stringify(body) : null)
  }).then(r => r.json());
};

const el = (sel, root=document) => root.querySelector(sel);
const els = (sel, root=document) => Array.from(root.querySelectorAll(sel));
const esc = (s) => (s ?? "").toString().replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));

function assetUrl(u){
  if(!u) return "";
  if(/^https?:\/\//i.test(u)) return u;
  if(/^(blob:|data:)/i.test(u)) return u;
  const BASE = (window.__BASE__ && window.__BASE__ !== "/") ? window.__BASE__ : "";
  if(BASE && u.startsWith(BASE)) return u;
  if(u.startsWith("/")) return BASE + u;
  return BASE + "/" + u;
}

function slugify(s){
  return (s||"").toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g,'')
    .replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'');
}

// ---------- HOME ----------
async function initHome(){
  const wrap = el("#homeSets");
  const res = await API("list_sets");
  if(!res.ok){ wrap.textContent = "Erreur chargement"; return; }
  const sets = res.sets || [];
  if(!sets.length){ wrap.innerHTML = `<div class="card">Aucun set. <a href="${BASE}/?p=admin">Créer un set</a></div>`; return; }
  wrap.innerHTML = sets.map(s => `
    <div class="card setcard">
      <div class="setcard__title">${esc(s.title)}</div>
      <div class="muted">ID: ${esc(s.id)} • BPM: ${esc(s.bpm ?? "")}</div>
      <div class="row gap">
        <a class="btn" href="${BASE}/?p=play&set=${encodeURIComponent(s.id)}">Jouer</a>
        <a class="btn btn--ghost" href="${BASE}/?p=admin">Éditer</a>
      </div>
    </div>
  `).join("");
}

// ---------- PLAY ----------
function preloadImage(url){
  return new Promise(resolve => {
    if(!url){ resolve(); return; }
    const im = new Image();
    im.onload = () => resolve();
    im.onerror = () => resolve();
    im.src = url;
  });
}

async function initPlay(){
  const setId = (window.__SET_ID__ || "").trim();

  const setTitle = el("#setTitle");
  const grid = el("#grid");
  const btnStart = el("#btnStart");
  const btnStop  = el("#btnStop");
  const audioEl = el("#beatAudio");
  const loadingOverlay = el("#loadingOverlay");
  const loadingMsg = el("#loadingMsg");
  const loadingDetail = el("#loadingDetail");
  const btnRetryLoad = el("#btnRetryLoad");

  const hudRound = el("#hudRound");
  const hudStep  = el("#hudStep");
  const hudBeat  = el("#hudBeat");

  const results = el("#results");
  const resScore = el("#resScore");
  const resPerfect = el("#resPerfect");
  const resGood = el("#resGood");
  const resMiss = el("#resMiss");

  const got = await API("get_set", {id:setId});
  if(!got.ok){ setTitle.textContent = "Set introuvable"; return; }
  const set = got.set;
  const library = Array.isArray(set.items) ? set.items.filter(Boolean) : [];
  const N = library.length;

  setTitle.textContent = set.title || setId;

  // --- Loading / preloading overlay ---
  const showLoading = (msg = "Chargement…", detail = "") => {
    if(loadingMsg) loadingMsg.textContent = msg;
    if(loadingDetail) loadingDetail.textContent = detail || "";
    if(btnRetryLoad) btnRetryLoad.hidden = true;
    if(loadingOverlay) loadingOverlay.hidden = false;
    if(btnStart) btnStart.disabled = true;
    if(btnStop) btnStop.disabled = true;
  };

  const showLoadError = (msg, detail = "") => {
    if(loadingMsg) loadingMsg.textContent = msg || "Ressources manquantes";
    if(loadingDetail) loadingDetail.textContent = detail || "";
    if(btnRetryLoad) btnRetryLoad.hidden = false;
    if(loadingOverlay) loadingOverlay.hidden = false;
    if(btnStart) btnStart.disabled = true;
    if(btnStop) btnStop.disabled = true;
  };

  const hideLoading = () => {
    if(loadingOverlay) loadingOverlay.hidden = true;
    if(btnStart) btnStart.disabled = false;
    if(btnStop) btnStop.disabled = true;
  };

  // 8 slots displayed, reading order fill/highlight
  const SLOTS = 8;
  grid.innerHTML = Array.from({length:SLOTS}).map((_,idx)=>`
    <div class="cell" data-idx="${idx}">
      <div class="cell__img"><div class="placeholder">—</div></div>
      <div class="cell__label"></div>
    </div>
  `).join("");
  const cellEls = els(".cell", grid);

  // Timing
  const BPM = Number(set.bpm || 85);
  const BEAT = 60 / BPM;
  const BEAT_OFFSET = Number(set.beatOffset ?? 0);

  // Optional per-set audio (if provided in set JSON)
  if(set.beatUrl){
    audioEl.src = assetUrl(set.beatUrl);
  } else if(set.beat){
    audioEl.src = assetUrl(set.beat);
  }

  const collectAssetList = () => {
    const imgs = library.map(it => assetUrl(it?.img || "")).filter(Boolean);
    const audio = getBeatUrl();
    return { imgs, audio };
  };

  const warmFetch = async (url) => {
    // Best-effort cache warmup (no throw on failure here)
    try { await fetch(url, { cache: "force-cache" }); } catch {}
  };

  const preloadAssets = async () => {
    const { imgs, audio } = collectAssetList();
    showLoading("Chargement du set…", "Préparation des images et de la musique");

    // 1) prefetch audio + decode once (guarantee playable on Start)
    try{
      await ensureAudio();
      await warmFetch(audio);
      await loadBeatBuffer(); // will fetch+decode, or throw if missing/corrupt
    } catch (e){
      throw new Error("Audio: " + (e?.message || e));
    }

    // 2) preload all images (best-effort: if any missing, fail with list)
    const missing = [];
    let done = 0;
    const total = imgs.length;

    const update = () => {
      if(loadingDetail){
        loadingDetail.textContent = total
          ? `Images: ${done}/${total}`
          : "Images: aucune";
      }
    };
    update();

    for(const u of imgs){
      try{
        const ok = await new Promise(resolve => {
          const im = new Image();
          im.onload = () => resolve(true);
          im.onerror = () => resolve(false);
          im.src = u;
        });
        if(!ok) missing.push(u);
      } catch {
        missing.push(u);
      }
      done++;
      update();
    }

    if(missing.length){
      throw new Error("Images manquantes (" + missing.length + ")");
    }

    hideLoading();
  };

  // 5 rounds, each round = 16 beats (8 reveal + 8 highlight)
  const ROUNDS_TOTAL = 5;
  const BEATS_PER_ROUND = 16;
  const TOTAL_BEATS = ROUNDS_TOTAL * BEATS_PER_ROUND;

  let ac = null;
  let beatBuffer = null;
  let beatBufferUrl = "";
  let beatSrc = null;
  let masterGain = null;
  let startTime = null;
  let raf = null;
  let state = "idle"; // idle | playing | finished
  let assetsReady = false;

  // Per-round mapping (slotItems) decided at round start, random only affects mapping
  let slotItems = Array(SLOTS).fill(null);
  let currentRound = 0;
  let lastRoundStarted = -1;

  // --- WebAudio beat engine (more stable across devices, incl. Android) ---
  const getBeatUrl = () => audioEl.currentSrc || audioEl.src || "";
  const stopBeat = () => {
    if(beatSrc){
      try { beatSrc.stop(); } catch {}
      try { beatSrc.disconnect(); } catch {}
      beatSrc = null;
    }
    if(masterGain){
      try { masterGain.disconnect(); } catch {}
      masterGain = null;
    }
  };

const fadeOutBeat = (durationSec) => {
    if(!masterGain || !ac) { stopBeat(); return; }

    const now = ac.currentTime;
    try {
      masterGain.gain.cancelScheduledValues(now);
      masterGain.gain.setValueAtTime(masterGain.gain.value, now);
      masterGain.gain.linearRampToValueAtTime(0, now + durationSec);
    } catch {}

    setTimeout(stopBeat, durationSec * 1000 + 50);
  };

  const ensureAudio = async () => {
    if(!ac){
      ac = new (window.AudioContext || window.webkitAudioContext)({ latencyHint: "interactive" });
    }
    if(ac.state === "suspended") await ac.resume();
  };

  const loadBeatBuffer = async () => {
    if(beatBuffer) return beatBuffer;
    const url = getBeatUrl();
    if(!url) throw new Error("Audio URL missing");
    const resp = await fetch(url, { cache: "force-cache" });
    const arr = await resp.arrayBuffer();
    beatBuffer = await ac.decodeAudioData(arr);
    return beatBuffer;
  };

  const startBeat = async () => {
    await ensureAudio();
    await loadBeatBuffer();
    stopBeat();
    beatSrc = ac.createBufferSource();
    beatSrc.buffer = beatBuffer;
    beatSrc.loop = true;
    masterGain = ac.createGain();
    masterGain.gain.value = 1;

    beatSrc.connect(masterGain);
    masterGain.connect(ac.destination);

    const SCHEDULE_AHEAD = 0.15; // margin to avoid jitter on mobile
    const when = ac.currentTime + SCHEDULE_AHEAD;
    beatSrc.start(when);

    const outLat = (typeof ac.outputLatency === "number" ? ac.outputLatency : 0);
    const baseLat = (typeof ac.baseLatency === "number" ? ac.baseLatency : 0);
    const AUDIO_LAT = outLat || baseLat || 0;

    // If your MP3 starts with the first beat at t=0, set beatOffset to 0.
    const TRACK_OFFSET = Number(set.beatOffset ?? 0);

    return { when, audioLatency: AUDIO_LAT, trackOffset: TRACK_OFFSET };
  };

  const shuffle = (arr) => {
    const a = arr.slice();
    for(let i=a.length-1;i>0;i--){
      const j = Math.floor(Math.random()*(i+1));
      [a[i],a[j]] = [a[j],a[i]];
    }
    return a;
  };

  const sampleDistinct = (arr, k) => {
    if(k <= 0) return [];
    if(k >= arr.length) return shuffle(arr);
    return shuffle(arr).slice(0,k);
  };

  const setAllEmpty = () => {
    for(const c of cellEls){
      c.classList.remove("is-revealed","reveal-pop","is-active");
      el(".cell__img", c).innerHTML = `<div class="placeholder">—</div>`;
      el(".cell__label", c).textContent = "";
    }
  };

  const clearActive = () => {
    for(const c of cellEls) c.classList.remove("is-active");
  };

  const setPhase = (mode) => {
    // mode: "show" | "recall"
    grid.classList.toggle("phase-recall", mode === "recall");
  };


  const revealAt = (pos, item) => {
    const c = cellEls[pos];
    if(!c) return;
    const imgWrap = el(".cell__img", c);
    const labelEl = el(".cell__label", c);

    const imgUrl = assetUrl(item?.img || "");
    imgWrap.innerHTML = imgUrl
      ? `<img src="${esc(imgUrl)}" alt="">`
      : `<div class="placeholder">?</div>`;

    const word = item?.label || "";

    labelEl.innerHTML = `<div class="cell__word">${esc(word)}</div>`;
    c.classList.add("is-revealed");
    c.classList.remove("reveal-pop");
    void c.offsetWidth;
    c.classList.add("reveal-pop");
  };

  const setActive = (pos) => {
    clearActive();
    const c = cellEls[pos];
    if(c) c.classList.add("is-active");
  };

  const buildBalancedSlots = (items) => {
    const n = items.length;
    const base = Math.floor(8 / n);
    const rem = 8 % n;
    const order = shuffle(items);
    const seq = [];
    for(let i=0;i<n;i++){
      const count = base + (i < rem ? 1 : 0);
      for(let c=0;c<count;c++) seq.push(order[i]);
    }
    return shuffle(seq);
  };

  const avoidConsecutive = (seq, maxTries=80) => {
    const key = (it)=> (it?.id || it?.label || "");
    for(let t=0;t<maxTries;t++){
      const s = shuffle(seq);
      let bad = 0;
      for(let i=1;i<s.length;i++){
        if(key(s[i-1]) && key(s[i-1]) === key(s[i])) bad++;
      }
      if(bad === 0) return s;
      if(bad <= 1) return s;
    }
    return seq;
  };

  const buildSlotItemsForRound = (rIdx) => {
    if(N === 0) return Array.from({length:8}).map((_,i)=>({id:`x${i}`,label:"",img:""}));

    // Round 1: structured 4x2 if possible
    if(rIdx === 0 && N >= 4){
      const four = sampleDistinct(library, 4);
      const patterns = [
        [0,0,1,1,2,2,3,3],
        [0,1,2,3,0,1,2,3],
        [0,1,0,1,2,3,2,3],
        [0,0,1,2,2,3,3,1],
      ];
      const p = patterns[Math.floor(Math.random()*patterns.length)];
      return avoidConsecutive(p.map(i => four[i]));
    }

    // Round 2: 6 uniques + 2 duplicates if possible
    if(rIdx === 1 && N >= 6){
      const six = sampleDistinct(library, 6);
      const dupA = six[Math.floor(Math.random()*six.length)];
      const dupB = six[Math.floor(Math.random()*six.length)];
      return avoidConsecutive([...six, dupA, dupB]);
    }

    // Variant A: 8 uniques if possible, else balanced
    if(N >= 8) return shuffle(sampleDistinct(library, 8));
    return buildBalancedSlots(library);
  };

  const startRound = (rIdx) => {
    currentRound = rIdx;
    slotItems = buildSlotItemsForRound(rIdx);

    setAllEmpty();
    clearActive();

    
    setPhase("show");
if(hudRound) hudRound.textContent = String(rIdx + 1);
    if(hudStep) hudStep.textContent = "0";
  };

  const endGame = () => {
    setPhase("show");
    state = "finished";
    clearActive();
    fadeOutBeat(BEAT * 4);
    results.hidden = false;
btnStart.disabled = false;
    btnStop.disabled = true;
    btnStart.textContent = "Restart";
  };

  const hardReset = async (opts = {}) => {
    // opts.closeAudio: true to fully close AudioContext and drop decoded buffers
    setPhase("show");
    state = "idle";
    clearActive();
    cancelAnimationFrame(raf);
    raf = null;

    stopBeat();
    beatBuffer = null;
    beatBufferUrl = "";

    // Reset timeline state
    startTime = null;
    lastBeatProcessed = -1;
    lastRoundStarted = -1;
    currentRound = 0;

    // UI reset
    results.hidden = true;
    btnStart.textContent = "Start";
    if(hudBeat) hudBeat.textContent = "0";
    if(hudStep) hudStep.textContent = "0";
    if(hudRound) hudRound.textContent = "1";
    setAllEmpty();

    btnStop.disabled = true;

    if(opts.closeAudio && ac){
      try { await ac.close(); } catch {}
      ac = null;
    }
  };

  const stopNow = () => {
    hardReset({ closeAudio: false });
    btnStart.disabled = false;
  };
  let lastBeatProcessed = -1;

  const applyBeat = (beatIndex) => {
    if (beatIndex < 0) return;
    if (beatIndex >= TOTAL_BEATS) { endGame(); return; }

    const roundIdx = Math.floor(beatIndex / BEATS_PER_ROUND);
    const inRoundBeat = beatIndex % BEATS_PER_ROUND;

    if (roundIdx !== lastRoundStarted) {
      lastRoundStarted = roundIdx;
      startRound(roundIdx);
    }

    if (inRoundBeat <= 7) {
      setPhase("show");
      const pos = inRoundBeat;
      if (!cellEls[pos].classList.contains("is-revealed")) {
        revealAt(pos, slotItems[pos]);
      }
      clearActive();
      if (hudStep) hudStep.textContent = String(inRoundBeat + 1);
    } else {
      setPhase("recall");
      const pos = inRoundBeat - 8;
      setActive(pos);
      if (hudStep) hudStep.textContent = String(pos + 1);
    }
  };

  const loop = () => {
    if (state !== "playing") return;

    const t = ac.currentTime;
    const beatIndex = Math.floor((t - startTime) / BEAT);

    if (hudBeat) hudBeat.textContent = String(Math.max(0, beatIndex));

    if (beatIndex < 0) {
      raf = requestAnimationFrame(loop);
      return;
    }

    // catch-up si on a sauté des beats (frames perdues / charge CPU / onglet)
    if (beatIndex > lastBeatProcessed) {
      for (let b = lastBeatProcessed + 1; b <= beatIndex; b++) {
        applyBeat(b);
        if (state !== "playing") break;
      }
      lastBeatProcessed = beatIndex;
    }

    raf = requestAnimationFrame(loop);
  };

  async function start(){
    if(!assetsReady){
      showLoading("Chargement…", "Les ressources ne sont pas encore prêtes");
      return;
    }
    if(loadingOverlay && !loadingOverlay.hidden) hideLoading();
    results.hidden = true;
    btnStart.disabled = true;
    btnStop.disabled = false;

    try{
      const { when, audioLatency, trackOffset } = await startBeat();

      // Timeline aligned to when audio becomes audible (best effort across devices)
      startTime = when + audioLatency + trackOffset;

      lastRoundStarted = -1;
      state = "playing";
      raf = requestAnimationFrame(loop);
    } catch (err){
      console.error(err);
      alert("Erreur audio (chargement/lecture). Vérifie le fichier MP3 et le réseau.");
      btnStart.disabled = false;
      btnStop.disabled = true;
      btnStart.textContent = "Start";
      results.hidden = true;
      state = "idle";
    }
  }


  const runPreload = async () => {
    assetsReady = false;
    try{
      await preloadAssets();
      assetsReady = true;
      btnStart.disabled = false;
      btnStop.disabled = true;
    } catch (e){
      console.warn(e);
      const detail = (e?.message || String(e));
      showLoadError("Chargement impossible", detail);
    }
  };

  if(btnRetryLoad){
    btnRetryLoad.addEventListener("click", (ev) => {
      ev.preventDefault();
      runPreload();
    });
  }

  // Initial preload on page open (before Start)
  runPreload();

  // Handle page interruptions / bfcache restores (mobile Safari)
  const onPageHide = () => { hardReset({ closeAudio: true }); };
  window.addEventListener("pagehide", onPageHide);
  window.addEventListener("beforeunload", onPageHide);

  document.addEventListener("visibilitychange", () => {
    if(document.hidden){
      // Stop immediately to avoid desync when returning
      hardReset({ closeAudio: false });
      showLoading("Pause", "Reviens et relance la partie");
    }
  });

  window.addEventListener("pageshow", (e) => {
    // If restored from bfcache, ensure we reset and preload again
    if(e && e.persisted){
      hardReset({ closeAudio: true });
      runPreload();
    }
  });
  btnStart.addEventListener("click", start);
  btnStop?.addEventListener("click", stopNow);
}

// ---------- ADMIN ----------
async function initAdmin(){
  const listEl = el("#setList");
  const btnNew = el("#btnNewSet");
  const form = el("#setForm");
  const itemsEditor = el("#itemsEditor");
  const btnDelete = el("#btnDeleteSet");
  const btnPreview = el("#btnPreview");

  const cropModal = el("#cropModal");
  const cropImage = el("#cropImage");
  const btnCropClose = el("#btnCropClose");
  const btnCropSave = el("#btnCropSave");
  let cropper = null;
  let cropTarget = null;

  const defaultItems = (count = 4) => Array.from({length:count}).map((_,i)=>({id:`i${i+1}`,label:"",img:""}));

  let current = {id:"",title:"",bpm:185,beatsPerGame:64,items: defaultItems()};

// Images en attente quand le set n'est pas encore persisté (pas d'ID serveur)
const pendingUploads = new Map();   // itemId -> Blob (webp)
const pendingPreview = new Map();   // itemId -> objectURL (blob:)

function hasPending(itemId){ return pendingUploads.has(itemId); }
function pendingUrl(itemId){ return pendingPreview.get(itemId) || ""; }

function setPendingImage(itemId, blob){
  // Remplace l'image en attente pour cet item
  const old = pendingPreview.get(itemId);
  if(old) URL.revokeObjectURL(old);

  const url = URL.createObjectURL(blob);
  pendingUploads.set(itemId, blob);
  pendingPreview.set(itemId, url);
}

function clearPendingImage(itemId){
  const old = pendingPreview.get(itemId);
  if(old) URL.revokeObjectURL(old);
  pendingPreview.delete(itemId);
  pendingUploads.delete(itemId);
}

function clearAllPending(){
  for(const id of Array.from(pendingPreview.keys())) clearPendingImage(id);
}



function renderItems(){
  const rows = current.items.map((it, idx)=>{
    const preview = pendingUrl(it.id) || it.img;
    const pending = hasPending(it.id);

    return `
    <div class="itemrow" data-idx="${idx}">
      <div class="itemrow__img">
        ${preview ? `<img src="${esc(assetUrl(preview))}" alt="">` : `<div class="placeholder">+</div>`}
      </div>
      <div class="itemrow__fields">
        <div class="row gap">
          <label class="field grow">
            <div class="field__label">Label ${idx+1}</div>
            <input data-kind="label" data-idx="${idx}" value="${esc(it.label)}" placeholder="Mot / texte">
          </label>
          <label class="field">
            <div class="field__label">Image</div>
            <input data-kind="file" data-idx="${idx}" type="file" accept="image/*">
          </label>
          <div class="field">
            <div class="field__label">&nbsp;</div>
            <button type="button" class="btn btn--danger" data-action="remove-item" data-idx="${idx}" ${current.items.length<=1?'disabled':''}>Suppr.</button>
          </div>
        </div>
        <div class="muted small">
          ${pending ? "Image en attente (sera uploadée à l'enregistrement)" : esc(it.img || "")}
        </div>
      </div>
    </div>
  `;
  }).join("");

  itemsEditor.innerHTML = `
    <div class="row between center" style="gap:10px; margin-bottom:10px;">
      <div class="strong">Entrées (${current.items.length})</div>
      <div class="row gap" style="flex-wrap:wrap;">
        <button type="button" class="btn" data-action="add-item">+ Ajouter</button>
      </div>
    </div>
    <div class="items">${rows || `<div class="muted">Aucune entrée. Ajoute-en une.</div>`}</div>
  `;
}


function fillForm(){

    form.id.value = current.id || "";
    form.title.value = current.title || "";
    form.bpm.value = current.bpm ?? 185;
    form.beatsPerGame.value = current.beatsPerGame ?? 64;
if(current.id){
  btnPreview.href = `${BASE}/?p=play&set=${encodeURIComponent(current.id)}`;
  btnPreview.classList.remove("is-disabled");
  btnPreview.setAttribute("aria-disabled", "false");
} else {
  btnPreview.href = "#";
  btnPreview.classList.add("is-disabled");
  btnPreview.setAttribute("aria-disabled", "true");
}
    renderItems();
  }

  function readFormToCurrent(){
    current.id = (form.id.value || "").trim();
    current.title = form.title.value.trim();
    current.bpm = Number(form.bpm.value || 185);
    current.beatsPerGame = Number(form.beatsPerGame.value || 64);
  }

  async function refreshList(){
    listEl.textContent = "Chargement…";
    const res = await API("list_sets");
    if(!res.ok){ listEl.textContent = "Erreur"; return; }
    const sets = res.sets || [];
    listEl.innerHTML = sets.map(s => `
      <div class="list__row">
        <div>
          <div class="strong">${esc(s.title)}</div>
          <div class="muted small">ID: ${esc(s.id)} • BPM: ${esc(s.bpm ?? "")}</div>
        </div>
        <div class="row gap wrap">
          <button class="btn btn--ghost" data-act="edit" data-id="${esc(s.id)}">Éditer</button>
          <a class="btn btn--ghost" href="${BASE}/?p=play&set=${encodeURIComponent(s.id)}">Jouer</a>
          <button class="btn btn--danger" data-act="delete" data-id="${esc(s.id)}">Supprimer</button>
        </div>
      </div>
    `).join("") || `<div class="muted">Aucun set.</div>`;
  }

  async function loadSet(id){
    const res = await API("get_set", {id});
    if(!res.ok){ alert("Set introuvable"); return; }
    current = res.set;
    clearAllPending();
    if(!Array.isArray(current.items) || current.items.length === 0) current.items = defaultItems();
    fillForm();
  }

  function newSet(){
    current = {id:"",title:"",bpm:185,beatsPerGame:64,items:defaultItems()};
    clearAllPending();
    fillForm();
  }

  function openCrop(file, itemIdx){
    const url = URL.createObjectURL(file);
    cropImage.src = url;
    cropModal.hidden = false;
    setTimeout(()=>{
      if(cropper) cropper.destroy();
      cropper = new Cropper(cropImage, { aspectRatio: 1, viewMode: 1, autoCropArea: 1, background: false });
    }, 0);
    cropTarget = { itemIdx, objectURL: url };
  }

  function closeCrop(){
    if(cropper){ cropper.destroy(); cropper = null; }
    if(cropTarget?.objectURL) URL.revokeObjectURL(cropTarget.objectURL);
    cropTarget = null;
    cropModal.hidden = true;
  }


async function saveCrop(){
  if(!cropper) return;

  // Note: l'ID du set peut être vide tant qu'on n'a pas "Enregistré".
  readFormToCurrent();

  const canvas = cropper.getCroppedCanvas({ width: 512, height: 512 });
  const blob = await new Promise(resolve => canvas.toBlob(resolve, "image/webp", 0.9));
  if(!blob){ alert("Erreur crop"); return; }

  const item = current.items[cropTarget.itemIdx];
  const itemId = item.id || `i${cropTarget.itemIdx+1}`;
  current.items[cropTarget.itemIdx].id = itemId;

  // Si le set n'a pas encore d'ID (pas persisté), on stocke l'image en attente
  if(!current.id){
    setPendingImage(itemId, blob);
    renderItems();
    closeCrop();
    return;
  }

  const fd = new FormData();
  fd.append("file", blob, `${itemId}.webp`);

  const up = await API("upload_image", {set: current.id, item: itemId}, fd, true);
  if(!up.ok){ alert("Upload error"); return; }

  // Image persistée immédiatement
  clearPendingImage(itemId);
  current.items[cropTarget.itemIdx].img = up.url;
  fillForm();
  closeCrop();
}

// events

  btnNew.addEventListener("click", newSet);

  listEl.addEventListener("click", async (e)=>{
    const b = e.target.closest("button[data-act]");
    if(!b) return;
if(b.dataset.act === "edit") loadSet(b.dataset.id);
if(b.dataset.act === "delete"){
  const id = b.dataset.id || "";
  if(!id) return;
  if(!confirm(`Supprimer le set "${id}" ?`)) return;
  const res = await API("delete_set", {id});
  if(!res.ok){ alert("Erreur suppression"); return; }
  if(current.id === id) newSet();
  await refreshList();
}
  });

  itemsEditor.addEventListener("input", (e)=>{
    const inp = e.target;
    if(!(inp instanceof HTMLInputElement)) return;
    const idx = Number(inp.dataset.idx);
    if(Number.isNaN(idx)) return;
    if(inp.dataset.kind === "label"){
      current.items[idx].label = inp.value;
    }
  });

  itemsEditor.addEventListener("change", (e)=>{
    const inp = e.target;
    if(!(inp instanceof HTMLInputElement)) return;
    const idx = Number(inp.dataset.idx);
    if(Number.isNaN(idx)) return;
    if(inp.dataset.kind === "file"){
      const file = inp.files?.[0];
      if(!file) return;
      openCrop(file, idx);
      inp.value = "";
    }
  });

  // Add / remove entries
  itemsEditor.addEventListener("click", (e)=>{
    const btn = e.target instanceof HTMLElement ? e.target.closest("[data-action]") : null;
    if(!btn) return;
    const action = btn.getAttribute("data-action");
    if(action === "add-item"){
      const nid = "i" + Date.now().toString(36);
      current.items.push({id:nid, label:"", img:""});
      renderItems();
    }

if(action === "remove-item"){
  const idx = Number(btn.getAttribute("data-idx"));
  if(Number.isNaN(idx)) return;
  if(current.items.length <= 1) return;

  const removed = current.items[idx];
  current.items.splice(idx, 1);

  if(removed?.id) clearPendingImage(removed.id);
  renderItems();
}
  });


  btnCropClose.addEventListener("click", closeCrop);
  el(".modal__backdrop").addEventListener("click", closeCrop);
  btnCropSave.addEventListener("click", saveCrop);


form.addEventListener("submit", async (e)=>{
  e.preventDefault();
  readFormToCurrent();

  // Normalise item IDs
  current.items = current.items.map((it, i) => ({...it, id: it.id || `i${i+1}`}));

  // 1) Save set (ID can be empty: server will generate it)
  const res = await API("save_set", {}, current);
  if(!res.ok){ alert(res.error || "Erreur save"); return; }
  current = res.set;

  fillForm();
  await refreshList();

  // 2) If there are pending images (set was not persisted earlier), upload them now
  let uploaded = 0;
  const failed = [];

  if(pendingUploads.size > 0){
    if(!current.id){
      alert("Erreur: le set n'a pas d'ID après enregistrement.");
      return;
    }

    for(const [itemId, blob] of Array.from(pendingUploads.entries())){
      const fd = new FormData();
      fd.append("file", blob, `${itemId}.webp`);

      const up = await API("upload_image", {set: current.id, item: itemId}, fd, true);
      if(!up.ok){
        failed.push({itemId, error: up.error || "Upload error"});
        continue;
      }

      // Apply URL to item
      const it = current.items.find(x => x.id === itemId);
      if(it) it.img = up.url;

      clearPendingImage(itemId);
      uploaded++;
    }

    // 3) Persist the uploaded image URLs
    if(uploaded > 0){
      const res2 = await API("save_set", {}, current);
      if(!res2.ok){ alert(res2.error || "Erreur save (post-upload)"); return; }
      current = res2.set;
      fillForm();
      await refreshList();
    }
  }

  if(failed.length){
    alert(`Enregistré, mais ${failed.length} image(s) n'ont pas pu être uploadées.`);
  } else {
    alert("Enregistré");
  }
});

  btnDelete.addEventListener("click", async ()=>{
    readFormToCurrent();
    if(!current.id) return;
    if(!confirm(`Supprimer le set "${current.id}" ?`)) return;
    const res = await API("delete_set", {id: current.id});
    if(!res.ok){ alert("Erreur suppression"); return; }
    newSet();
    await refreshList();
  });

  await refreshList();
  // Ne pas forcer le chargement d'un set "demo" (peut ne pas exister)
  newSet();
}

// ---------- BOOT ----------
document.addEventListener("DOMContentLoaded", ()=>{
  const p = window.__PAGE__;
  if(p === "home") initHome();
  if(p === "play") initPlay();
  if(p === "admin") initAdmin();
});
