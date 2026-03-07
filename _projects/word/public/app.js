/* Single JS for home + play + admin */
const BASE = (window.__BASE__ && window.__BASE__ !== "/") ? window.__BASE__ : "";
const CSRF = window.__CSRF__ || "";
const SHARE_TOKEN = window.__SHARE_TOKEN__ || "";
const SHARE_MODE = !!window.__SHARE_MODE__;

const API = async (action, params = {}, body = null, isForm = false) => {
  const url = new URL(location.origin + BASE + "/?p=api");
  url.searchParams.set("action", action);
  Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
  if (SHARE_TOKEN) url.searchParams.set("st", SHARE_TOKEN);

  const headers = {};
  if (!isForm) headers["Content-Type"] = "application/json";
  if (CSRF) headers["X-CSRF-Token"] = CSRF;

  const resp = await fetch(url.toString(), {
    method: "POST",
    headers,
    body: isForm ? body : (body ? JSON.stringify(body) : null)
  });

  const data = await resp.json().catch(() => ({ ok: false, error: "Invalid JSON" }));
  if (!resp.ok && data.ok !== false) {
    data.ok = false;
    data.error = data.error || ("HTTP " + resp.status);
  }
  return data;
};

const el = (sel, root=document) => root.querySelector(sel);
const els = (sel, root=document) => Array.from(root.querySelectorAll(sel));
const esc = (s) => (s ?? "").toString().replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));

function ensureToastStack() {
  let stack = el("#toastStack");
  if (stack) return stack;
  stack = document.createElement("div");
  stack.id = "toastStack";
  stack.className = "toast-stack";
  document.body.appendChild(stack);
  return stack;
}

function toast(message, kind = "info") {
  const stack = ensureToastStack();
  const node = document.createElement("div");
  node.className = `toast toast-${kind}`;
  node.textContent = message;
  stack.appendChild(node);
  window.setTimeout(() => {
    node.classList.add("hide");
    window.setTimeout(() => node.remove(), 220);
  }, 2200);
}

function assetUrl(u){
  if(!u) return "";
  if(/^https?:\/\//i.test(u)) return u;
  if(/^(blob:|data:)/i.test(u)) return u;

  let out = "";
  if (BASE && u.startsWith(BASE)) out = u;
  else if (u.startsWith("/")) out = BASE + u;
  else out = BASE + "/" + u;

  if (!SHARE_MODE || !SHARE_TOKEN) return out;
  const sep = out.includes("?") ? "&" : "?";
  return out + sep + "st=" + encodeURIComponent(SHARE_TOKEN);
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
      <div class="muted">BPM: ${esc(s.bpm ?? "")} • ${esc(String(s.itemCount || 0))} item(s)</div>
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
  const beatCandidates = Array.isArray(window.__BEAT_CANDIDATES__) ? window.__BEAT_CANDIDATES__.filter(Boolean) : [];

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
  const pickPlayableBeat = (urls) => {
    const probe = document.createElement("audio");
    const types = {
      ".m4a": "audio/mp4",
      ".ogg": "audio/ogg",
      ".wav": "audio/wav"
    };
    for(const u of urls){
      const ext = (u.match(/\.[a-z0-9]+(?:$|\?)/i)?.[0] || "").replace("?", "").toLowerCase();
      const mime = types[ext] || "";
      if(!mime) return u;
      const ok = probe.canPlayType(mime);
      if(ok === "probably" || ok === "maybe") return u;
    }
    return urls[0] || "";
  };

  if(set.beatUrl){
    audioEl.src = assetUrl(set.beatUrl);
  } else if(set.beat){
    audioEl.src = assetUrl(set.beat);
  } else {
    const chosen = pickPlayableBeat(beatCandidates);
    if(chosen) audioEl.src = chosen;
  }

  const collectAssetList = () => {
    const imgs = library.map(it => assetUrl(it?.img || "")).filter(Boolean);
    return { imgs };
  };

  const preloadAssets = async () => {
    const { imgs } = collectAssetList();
    showLoading("Chargement du set…", "Préparation des images et de la musique");

    // 1) warm audio file in HTTP cache without touching AudioContext.
    //    On refresh, trying to resume WebAudio here can hang until user gesture.
    try{
      const beatUrl = getBeatUrl();
      if(!beatUrl) throw new Error("Audio URL missing");

      const ctrl = new AbortController();
      const timeoutId = setTimeout(() => ctrl.abort(), 8000);
      try{
        const resp = await fetch(beatUrl, { cache: "force-cache", signal: ctrl.signal });
        if(!resp.ok) throw new Error("Audio HTTP " + resp.status);
        await resp.arrayBuffer();
      } finally {
        clearTimeout(timeoutId);
      }
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
          let settled = false;
          const finish = (val) => {
            if(settled) return;
            settled = true;
            clearTimeout(timer);
            im.onload = null;
            im.onerror = null;
            resolve(val);
          };
          const timer = setTimeout(() => finish(false), 8000);
          im.onload = () => finish(true);
          im.onerror = () => finish(false);
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
  let hasLoadError = false;

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
    const url = getBeatUrl();
    if(!url) throw new Error("Audio URL missing");
    if(beatBuffer && beatBufferUrl === url) return beatBuffer;
    const resp = await fetch(url, { cache: "force-cache" });
    if(!resp.ok) throw new Error("Audio HTTP " + resp.status);
    const arr = await resp.arrayBuffer();
    beatBuffer = await ac.decodeAudioData(arr);
    beatBufferUrl = url;
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
    if(opts.closeAudio){
      beatBuffer = null;
      beatBufferUrl = "";
    }

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
    hasLoadError = false;
    try{
      await preloadAssets();
      assetsReady = true;
      btnStart.disabled = false;
      btnStop.disabled = true;
    } catch (e){
      console.warn(e);
      hasLoadError = true;
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
      return;
    }

    // On some refresh/restore paths, visibility can briefly toggle and leave
    // the pause overlay stuck while assets are already loaded.
    if(hasLoadError){
      return;
    }

    if(assetsReady){
      hideLoading();
    } else {
      runPreload();
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
  if (!window.SetAdminCore || typeof window.SetAdminCore.init !== 'function') {
    toast('Module admin commun introuvable', 'error');
    return;
  }

  await window.SetAdminCore.init({
    api: API,
    assetUrl,
    esc,
    previewSelector: '#btnPreview',
    uploadExt: '.webp',
    emptySet: () => ({ id: '', title: '', bpm: 185, beatsPerGame: 64, items: [] }),
    fromSet: (set) => ({
      id: set.id || '',
      title: set.title || '',
      bpm: Number(set.bpm || 185),
      beatsPerGame: Number(set.beatsPerGame || 64),
      items: Array.isArray(set.items) ? set.items : []
    }),
    writeForm: (form, current) => {
      form.id.value = current.id || '';
      form.title.value = current.title || '';
      form.bpm.value = current.bpm ?? 185;
      form.beatsPerGame.value = current.beatsPerGame ?? 64;
    },
    readForm: (form, current) => {
      current.id = (form.id.value || '').trim();
      current.title = (form.title.value || '').trim();
      current.bpm = Number(form.bpm.value || 185);
      current.beatsPerGame = Number(form.beatsPerGame.value || 64);
    },
    toPayload: (current) => ({
      id: current.id,
      title: current.title,
      bpm: current.bpm,
      beatsPerGame: current.beatsPerGame,
      items: current.items
    }),
    listMeta: (set, escFn) => `BPM: ${escFn(set.bpm ?? '')} • ${escFn(String(set.itemCount || 0))} item(s)`,
    previewHref: (id) => `${BASE}/?p=play&set=${encodeURIComponent(id)}`
  });
}

// ---------- BOOT ----------
document.addEventListener("DOMContentLoaded", ()=>{
  const p = window.__PAGE__;
  if(p === "home") initHome();
  if(p === "play") initPlay();
  if(p === "admin") initAdmin();
});


