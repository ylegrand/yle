// Colonnes de base affichées
const BASE_COLUMNS = [
    { key: 'name', label: 'Nom' },
    { key: 'form', label: 'Forme' },
    { key: 'rarity', label: 'Rareté' },
    { key: 'level', label: 'Niv.' },
    { key: 'health', label: 'PV' },
    { key: 'damage', label: 'Dégâts' },
    { key: 'dps', label: 'DPS' },
    { key: 'range', label: 'Portée' },
    { key: 'speed', label: 'Vitesse' },
    { key: 'time_between_attacks', label: 'Temps atk' },
    { key: 'recharge_time', label: 'Recharge' },
    { key: 'cost', label: 'Coût' },
    { key: 'target', label: 'Cible' },
    { key: 'enemy_types', label: 'Types ennemis' },
    { key: 'traits', label: 'Traits' },
    { key: 'abilities', label: 'Abilities' }
];

// Colonnes numériques (heatmap normale)
const NUMERIC_COLUMNS = new Set([
    'level',
    'health',
    'damage',
    'dps',
    'range',
    'speed',
    'time_between_attacks',
    'recharge_time'
]);

// Colonnes où "plus petit = mieux" (heatmap inversée)
const NEGATIVE_METRIC_COLUMNS = new Set([
    'time_between_attacks',
    'recharge_time',
	'cost'
]);


// --- Scoring Top 10 (Approche A : score pondéré statique) ---
// On calcule un score entre 0 et 1 à partir des stats normalisées.

const SCORE_FIELDS = [
    { key: 'range',                weight: 3.0, prefer: 'high' },  // ★ le facteur le plus déterminant
    { key: 'dps',                  weight: 2.5, prefer: 'high' },  // ★ puissance brute
    { key: 'time_between_attacks', weight: 1.6, prefer: 'low'  },  // réactivité
    { key: 'recharge_time',        weight: 1.3, prefer: 'low'  },  // disponibilité
    { key: 'health',               weight: 1.0, prefer: 'high' },  // tankiness
    { key: 'cost',                 weight: 0.7, prefer: 'low'  },  // économie
    { key: 'speed',                weight: 0.4, prefer: 'high' }   // utilité mais secondaire
];
function buildScoreStats() {
    const stats = {};
    SCORE_FIELDS.forEach(f => {
        stats[f.key] = { min: Infinity, max: -Infinity };
    });

    for (const unit of ALL_UNITS) {
        for (const field of SCORE_FIELDS) {
            const v = valueToNumber(unit[field.key]);
            if (v === null) continue;
            const s = stats[field.key];
            if (v < s.min) s.min = v;
            if (v > s.max) s.max = v;
        }
    }
    return stats;
}

const SCORE_STATS = buildScoreStats();

function getUnitScore(unit) {
    let total = 0;
    let totalWeight = 0;

    for (const field of SCORE_FIELDS) {
        const { key, weight, prefer } = field;
        const stat = SCORE_STATS[key];
        if (!stat || stat.min === Infinity || stat.max === -Infinity) continue;

        const raw = valueToNumber(unit[key]);
        if (raw === null) continue;

        let ratio;
        if (stat.max === stat.min) {
            ratio = 0.5; // tous pareil => neutre
        } else if (prefer === 'high') {
            ratio = (raw - stat.min) / (stat.max - stat.min);
        } else { // prefer === 'low'
            ratio = (stat.max - raw) / (stat.max - stat.min);
        }

        if (ratio < 0) ratio = 0;
        if (ratio > 1) ratio = 1;

        total += ratio * weight;
        totalWeight += weight;
    }

    if (!totalWeight) return 0;
    return total / totalWeight; // score ∈ [0,1]
}


let sortState = { column: 'name', dir: 'asc' };
let filters = {}; // clé = nom de colonne, valeur = texte de filtre

// --- Helpers de données ---

// ownedIds = [index dans ALL_UNITS] -> [{ id, unit }, ...]
function getOwnedUnits() {
    return ownedIds
        .map(id => ({ id, unit: ALL_UNITS[id] }))
        .filter(entry => !!entry.unit);
}

function formatEnemyTypes(types) {
    if (!Array.isArray(types) || types.length === 0) return '';
    return types.join(', ');
}

function formatAbilities(abilities) {
    if (!Array.isArray(abilities) || abilities.length === 0) return '';
    return abilities.join(', ');
}

function valueToNumber(v) {
    if (v === null || v === undefined) return null;
    const n = Number(v);
    return Number.isNaN(n) ? null : n;
}

// Colonnes d’abilities détaillées à partir d’ABILITY_FLAGS,
// mais seulement celles qui apparaissent chez au moins une unité possédée
function getAbilityColumns() {
    const abilityCols = [];
    const owned = getOwnedUnits();
    const abilityFlagsInOwned = new Set();

    for (const { unit } of owned) {
        if (!Array.isArray(unit.abilities)) continue;
        for (const ab of unit.abilities) {
            abilityFlagsInOwned.add(ab);
        }
    }

    for (const flag of ABILITY_FLAGS) {
        if (abilityFlagsInOwned.has(flag)) {
            abilityCols.push({
                key: 'ability__' + flag,
                label: flag,
                abilityFlag: flag
            });
        }
    }

    return abilityCols;
}

function buildColumns() {
    const abilityCols = getAbilityColumns();
    return { cols: BASE_COLUMNS, abilityCols };
}

// --- Filtres : texte avec support de "|" comme OU ---

function applyFilters(rows, cols, abilityCols) {
    const allCols = cols.concat(abilityCols);

    return rows.filter(({ unit }) => {
        for (const col of allCols) {
            const key = col.key;
            const rawFilter = (filters[key] || '').toLowerCase();
            const parts = rawFilter
                .split('|')
                .map(s => s.trim())
                .filter(Boolean);

            if (!parts.length) continue;

            let text = '';

            if (key === 'enemy_types') {
                text = formatEnemyTypes(unit.enemy_types || []).toLowerCase();
            } else if (key === 'abilities') {
                text = formatAbilities(unit.abilities || []).toLowerCase();
            } else if (key.startsWith('ability__')) {
                const abilities = Array.isArray(unit.abilities) ? unit.abilities : [];
                const has = abilities.includes(col.abilityFlag) ? '1' : '0';
                text = has;
            } else {
                const raw = unit[key];
                text = (raw === null || raw === undefined ? '' : String(raw)).toLowerCase();
            }

            const match = parts.some(p => text.includes(p));
            if (!match) return false;
        }
        return true;
    });
}

// --- Tri ---

function applySort(rows, cols, abilityCols) {
    const colKey = sortState.column;
    const dir = sortState.dir;

    const colDef =
        cols.find(c => c.key === colKey) ||
        abilityCols.find(c => c.key === colKey) ||
        null;

    return rows.slice().sort((aEntry, bEntry) => {
        const a = aEntry.unit;
        const b = bEntry.unit;

        if (!colDef) {
            const sa = String(a.name || '').toLowerCase();
            const sb = String(b.name || '').toLowerCase();
            if (sa < sb) return dir === 'asc' ? -1 : 1;
            if (sa > sb) return dir === 'asc' ? 1 : -1;
            return 0;
        }

        const key = colDef.key;

        if (NUMERIC_COLUMNS.has(key) || key.startsWith('ability__')) {
            let va;
            let vb;

            if (key.startsWith('ability__')) {
                const abilName = colDef.abilityFlag;
                const aHas =
                    Array.isArray(a.abilities) &&
                    a.abilities.includes(abilName)
                        ? 1
                        : 0;
                const bHas =
                    Array.isArray(b.abilities) &&
                    b.abilities.includes(abilName)
                        ? 1
                        : 0;
                va = aHas;
                vb = bHas;
            } else {
                va = valueToNumber(a[key]);
                vb = valueToNumber(b[key]);
            }

            const na = va ?? -Infinity;
            const nb = vb ?? -Infinity;
            if (na < nb) return dir === 'asc' ? -1 : 1;
            if (na > nb) return dir === 'asc' ? 1 : -1;
            return 0;
        }

        const sa = (a[key] === null || a[key] === undefined
            ? ''
            : String(a[key])
        ).toLowerCase();
        const sb = (b[key] === null || b[key] === undefined
            ? ''
            : String(b[key])
        ).toLowerCase();

        if (sa < sb) return dir === 'asc' ? -1 : 1;
        if (sa > sb) return dir === 'asc' ? 1 : -1;
        return 0;
    });
}

// --- Heatmap ---

function getHeatColor(value, min, max, inverted = false) {
    if (value === null || value === undefined) return '#f9fafb';
    const v = Number(value);
    if (Number.isNaN(v)) return '#f9fafb';

    let clamped;
    if (max === min) {
        // Tous les éléments ont la même valeur : couleur "moyenne"
        clamped = 0.5;
    } else {
        clamped = (v - min) / (max - min);
        if (clamped < 0) clamped = 0;
        if (clamped > 1) clamped = 1;
    }

    if (inverted) {
        clamped = 1 - clamped;
    }

    const hue = 210 - 210 * clamped;  // 210 (bleu) -> 0 (rouge)
    const light = 95 - 35 * clamped;  // 95% -> 60%
    return `hsl(${hue}, 85%, ${light}%)`;
}

// --- UI sélection / auto-complétion ---

const unitSelect = document.getElementById('unitSelect'); // pas utilisé, gardé pour compat
const searchInput = document.getElementById('searchInput');
const saveStatus = document.getElementById('saveStatus');
const tableContainer = document.getElementById('tableContainer');
const top10ListEl = document.getElementById('top10List');

let autocompleteContainer = null;
let autocompleteItems = [];
let autocompleteIndex = -1;

// Liste de base pour auto-complétion
const allOptions = ALL_UNITS.map((u, idx) => ({
    id: idx,
    label: `${u.name} (${u.form}, ${u.rarity}, Portée ${u.range ?? 'N/A'}, DPS ${u.dps ?? 'N/A'})`.trim(),
    searchText: (
        (u.name || '') + ' ' +
        (u.form || '') + ' ' +
        (u.rarity || '') + ' ' +
        (u.target || '') + ' ' +
        (formatEnemyTypes(u.enemy_types || []) || '')
    ).toLowerCase()
}));

function ensureAutocompleteContainer() {
    if (!autocompleteContainer) {
        autocompleteContainer = document.createElement('div');
        autocompleteContainer.id = 'autocompleteList';
        autocompleteContainer.style.position = 'absolute';
        autocompleteContainer.style.zIndex = '50';
        autocompleteContainer.style.background = 'white';
        autocompleteContainer.style.border = '1px solid #d1d5db';
        autocompleteContainer.style.borderRadius = '0.375rem';
        autocompleteContainer.style.boxShadow = '0 4px 8px rgba(15,23,42,0.15)';
        autocompleteContainer.style.maxHeight = '260px';
        autocompleteContainer.style.overflowY = 'auto';
        autocompleteContainer.style.fontSize = '0.85rem';
        autocompleteContainer.style.minWidth = searchInput.offsetWidth + 'px';

        const parent = searchInput.parentElement;
        parent.style.position = 'relative';
        parent.appendChild(autocompleteContainer);
    }
}

function closeAutocomplete() {
    if (autocompleteContainer) {
        autocompleteContainer.innerHTML = '';
        autocompleteContainer.style.display = 'none';
    }
    autocompleteItems = [];
    autocompleteIndex = -1;
}

function openAutocomplete() {
    ensureAutocompleteContainer();
    autocompleteContainer.style.display = 'block';
}

function updateAutocompletePosition() {
    if (!autocompleteContainer) return;
    const top = searchInput.offsetTop + searchInput.offsetHeight + 4;
    autocompleteContainer.style.top = top + 'px';
    autocompleteContainer.style.left = searchInput.offsetLeft + 'px';
    autocompleteContainer.style.minWidth = searchInput.offsetWidth + 'px';
}

function renderAutocomplete() {
    const query = searchInput.value.trim().toLowerCase();
    if (!query) {
        closeAutocomplete();
        return;
    }

    const matches = allOptions
        .filter(opt => opt.searchText.includes(query))
        .slice(0, 25);

    if (matches.length === 0) {
        closeAutocomplete();
        return;
    }

    ensureAutocompleteContainer();
    updateAutocompletePosition();
    autocompleteContainer.innerHTML = '';
    autocompleteItems = [];
    autocompleteIndex = -1;

    matches.forEach((opt, index) => {
        const item = document.createElement('div');
        item.className = 'autocomplete-item';
        item.dataset.id = String(opt.id);
        item.textContent = opt.label;
        item.style.padding = '0.25rem 0.5rem';
        item.style.cursor = 'pointer';
        item.style.whiteSpace = 'nowrap';
        item.style.overflow = 'hidden';
        item.style.textOverflow = 'ellipsis';

        item.addEventListener('mouseenter', () => {
            setAutocompleteActive(index);
        });

        item.addEventListener('mousedown', (e) => {
            e.preventDefault();
        });

        item.addEventListener('click', () => {
            selectAutocompleteItem(opt.id);
        });

        autocompleteContainer.appendChild(item);
        autocompleteItems.push(item);
    });

    openAutocomplete();
}

function setAutocompleteActive(index) {
    autocompleteIndex = index;
    autocompleteItems.forEach((item, i) => {
        item.style.background = i === index ? '#e5f2ff' : 'white';
    });
}

function moveAutocomplete(delta) {
    if (autocompleteItems.length === 0) return;
    let newIndex = autocompleteIndex + delta;
    if (newIndex < 0) newIndex = autocompleteItems.length - 1;
    if (newIndex >= autocompleteItems.length) newIndex = 0;
    setAutocompleteActive(newIndex);
}

function selectAutocompleteItem(id) {
    const unitId = parseInt(id, 10);
    if (!Number.isNaN(unitId)) {
        if (!ownedIds.includes(unitId)) {
            ownedIds.push(unitId);
            renderTable();
            scheduleSave();
        }
    }
    searchInput.value = '';
    closeAutocomplete();
    searchInput.focus();
}

// --- Sauvegarde automatique côté serveur ---

let saveTimeout = null;

function scheduleSave() {
    if (saveTimeout) clearTimeout(saveTimeout);
    saveTimeout = setTimeout(() => {
        saveOwnedIds();
    }, 400);
}

function saveOwnedIds() {
    saveStatus.textContent = 'Sauvegarde...';
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ownedIds })
    })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                saveStatus.textContent = 'Sauvegardé ✓';
                setTimeout(() => {
                    saveStatus.textContent = '';
                }, 1500);
            } else {
                saveStatus.textContent = 'Erreur de sauvegarde';
            }
        })
        .catch(() => {
            saveStatus.textContent = 'Erreur réseau';
        });
}

// --- Rendu du tableau principal ---

function renderTable(focusCol = null, caretPos = null) {
    const ownedEntries = getOwnedUnits();

    if (ownedEntries.length === 0) {
        tableContainer.innerHTML = '<p class="muted">Aucun chat dans ton set pour l’instant. Ajoute-en via la recherche ci-dessus.</p>';
		renderTop10();
        return;
    }

    const { cols, abilityCols } = buildColumns();

    // Filtres + tri
    let filtered = applyFilters(ownedEntries, cols, abilityCols);
    filtered = applySort(filtered, cols, abilityCols);

    // Min/max pour heatmap (SUR LES LIGNES FILTRÉES UNIQUEMENT)
    const minMax = {};
    const allCols = cols.concat(abilityCols);

    for (const col of allCols) {
        const key = col.key;

        if (!NUMERIC_COLUMNS.has(key) && !key.startsWith('ability__')) continue;

        let min = Infinity;
        let max = -Infinity;

        for (const { unit } of filtered) {
            let v = null;

            if (key.startsWith('ability__')) {
                const abilities = Array.isArray(unit.abilities) ? unit.abilities : [];
                v = abilities.includes(col.abilityFlag) ? 1 : 0;
            } else {
                v = valueToNumber(unit[key]);
            }

            if (v === null || v === undefined) continue;
            if (v < min) min = v;
            if (v > max) max = v;
        }

        // On garde même si min === max, pour avoir au moins une couleur neutre
        if (min < Infinity && max > -Infinity) {
            minMax[key] = { min, max };
        }
    }

    // Construction HTML
    let html = '<div style="overflow:auto; max-height:calc(100vh - 280px); border-radius:0.5rem; border:1px solid #e5e7eb;">';
    html += '<table><thead><tr>';

    // Colonne de retrait à gauche (pas de tri)
    html += '<th class="remove-cell"></th>';

    // En-têtes base
    for (const col of cols) {
        const isCurrent = sortState.column === col.key;
        const indicator = isCurrent ? (sortState.dir === 'asc' ? '▲' : '▼') : '↕';
        html += `<th data-col="${col.key}">
                    <span>${col.label.replace(/_/g, " ")}</span>
                    <span class="sort-indicator">${indicator}</span>
                 </th>`;
    }

    // En-têtes abilities détaillées
    for (const col of abilityCols) {
        const key = col.key;
        const isCurrent = sortState.column === key;
        const indicator = isCurrent ? (sortState.dir === 'asc' ? '▲' : '▼') : '↕';
        html += `<th data-col="${key}">
                    <span>${col.abilityFlag.replace(/_/g, " ")}</span>
                    <span class="sort-indicator">${indicator}</span>
                 </th>`;
    }

    html += '</tr>';

    // Ligne de filtres
    html += '<tr>';
    html += '<th class="remove-cell"></th>';

    for (const col of cols) {
        const fVal = filters[col.key] || '';
        html += `<th data-col="${col.key}">
                    <input class="filter-input"
                           data-filter-col="${col.key}"
                           type="text"
                           placeholder="filtre"
                           value="${fVal.replace(/"/g, '&quot;')}">
                 </th>`;
    }
    for (const col of abilityCols) {
        const fVal = filters[col.key] || '';
        html += `<th data-col="${col.key}">
                    <input class="filter-input"
                           data-filter-col="${col.key}"
                           type="text"
                           placeholder="0/1"
                           value="${fVal.replace(/"/g, '&quot;')}">
                 </th>`;
    }
    html += '</tr>';

    html += '</thead><tbody>';

    // Lignes de données
    for (const { id, unit } of filtered) {
        html += '<tr>';

        // Cellule retrait à gauche
        html += `<td class="remove-cell">
                    <button data-remove-id="${id}" class="remove-btn" title="Retirer ce personnage">✕</button>
                 </td>`;

        // Colonnes de base
        for (const col of cols) {
            const key = col.key;
            let val = unit[key];

            if (key === 'enemy_types') {
                val = formatEnemyTypes(unit.enemy_types || []);
            } else if (key === 'time_between_attacks' || key === 'recharge_time') {
                if (val !== null && val !== undefined) {
                    val = Number(val).toFixed(2);
                }
            }

            let style = '';
            if (NUMERIC_COLUMNS.has(key)) {
                const mm = minMax[key];
                if (mm) {
                    const num = valueToNumber(unit[key]);
                    if (num !== null) {
                        const inverted = NEGATIVE_METRIC_COLUMNS.has(key);
                        style = `background:${getHeatColor(num, mm.min, mm.max, inverted)};`;
                    }
                }
            }

            html += `<td data-col="${key}"${style ? ` style="${style}"` : ''}>${val ?? ''}</td>`;
        }

        // Colonnes abilities détaillées
        for (const col of abilityCols) {
            const key = col.key;
            const abilities = Array.isArray(unit.abilities) ? unit.abilities : [];
            const v = abilities.includes(col.abilityFlag) ? 1 : 0;

            const mm = minMax[key];
            let style = '';
            if (mm) {
                style = `background:${getHeatColor(v, mm.min, mm.max, false)};`;
            }

            html += `<td data-col="${key}"${style ? ` style="${style}"` : ''}>${v}</td>`;
        }

        html += '</tr>';
    }

    html += '</tbody></table></div>';

    tableContainer.innerHTML = html;

    // Tri : uniquement sur la première ligne d’en-têtes
    const headerThs = tableContainer.querySelectorAll('thead > tr:first-child th[data-col]');
    headerThs.forEach(th => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', (event) => {
            if (event.target.closest('.filter-input')) return;
            const col = th.getAttribute('data-col');
            if (!col) return;

            if (sortState.column === col) {
                sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
            } else {
                sortState.column = col;
                sortState.dir = 'asc';
            }
            renderTable();
        });
    });

    // Filtres : conserver focus
    tableContainer.querySelectorAll('.filter-input').forEach(input => {
        input.addEventListener('input', () => {
            const col = input.getAttribute('data-filter-col');
            filters[col] = input.value;
            const caret = input.selectionStart;
            renderTable(col, caret);
        });
    });

    if (focusCol) {
        const focusedInput = tableContainer.querySelector(`.filter-input[data-filter-col="${focusCol}"]`);
        if (focusedInput) {
            focusedInput.focus();
            if (caretPos !== null && caretPos !== undefined) {
                focusedInput.selectionStart = focusedInput.selectionEnd = caretPos;
            }
        }
    }

    // Suppression : clic sur bouton de la colonne de gauche
    tableContainer.querySelectorAll('button[data-remove-id]').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.getAttribute('data-remove-id'), 10);
            ownedIds = ownedIds.filter(x => x !== id);
            renderTable();
            scheduleSave();
        });
    });
	
	renderTop10();
}

function renderTop10() {
    if (!top10ListEl) return;

    const owned = getOwnedUnits();
    if (!owned.length) {
        top10ListEl.innerHTML = '<div class="top10-empty muted">Ajoute des personnages pour voir le Top 10.</div>';
        return;
    }

    const scored = owned.map(({ id, unit }) => ({
        id,
        unit,
        score: getUnitScore(unit)
    }));

    scored.sort((a, b) => b.score - a.score);

    const top10 = scored.slice(0, 10);

    let html = '';
    top10.forEach((entry, index) => {
        const u = entry.unit;
        const scorePercent = Math.round(entry.score * 100);
        const label = `${u.name} (${u.form})`;

        html += `
            <div class="top10-item">
                <div class="top10-rank">${index + 1}.</div>
                <div class="top10-main">
                    <div class="top10-name">${label}</div>
                    <div class="top10-sub">Score&nbsp;: ${scorePercent}</div>
                </div>
            </div>
        `;
    });

    top10ListEl.innerHTML = html;
}


// --- Export IA ---

const exportBtn = document.getElementById('exportBtn');
const exportText = document.getElementById('exportText');
const exportArea = document.getElementById('exportArea');
const copyExportBtn = document.getElementById('copyExportBtn');
const downloadExportBtn = document.getElementById('downloadExportBtn');

function generateExportText() {
    const owned = getOwnedUnits();
    if (owned.length === 0) {
        return 'Aucun chat dans mon set.';
    }

    const lines = [];
    lines.push('Voici mon set actuel de chats dans Battle Cats :');
    lines.push('');

    for (const { unit } of owned) {
        lines.push(`- ${unit.name} (${unit.form}, ${unit.rarity})`);
        lines.push(`  • Niv: ${unit.level}, PV: ${unit.health}, Dégâts: ${unit.damage}, DPS: ${unit.dps}, Portée: ${unit.range}, Vitesse: ${unit.speed}`);
        lines.push(`  • Temps attaque: ${unit.time_between_attacks}, Recharge: ${unit.recharge_time}, Coût: ${unit.cost}`);
        lines.push(`  • Cible: ${unit.target || 'N/A'}, Types ennemis: ${formatEnemyTypes(unit.enemy_types || []) || 'N/A'}`);
        lines.push(`  • Traits: ${Array.isArray(unit.traits) && unit.traits.length ? unit.traits.join(', ') : 'N/A'}`);
        lines.push(`  • Abilities: ${formatAbilities(unit.abilities || []) || 'aucune/standard'}`);
        lines.push('');
    }

    return lines.join('\n');
}

exportBtn.addEventListener('click', () => {
    const txt = generateExportText();
    exportText.value = txt;
    exportArea.style.display = 'block';
});

copyExportBtn.addEventListener('click', () => {
    exportText.select();
    document.execCommand('copy');
});

downloadExportBtn.addEventListener('click', () => {
    const blob = new Blob([exportText.value], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'battle_cats_export.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
});

// --- Init auto-complétion + tableau ---

searchInput.addEventListener('input', () => {
    renderAutocomplete();
});

// navigation clavier dans la liste
searchInput.addEventListener('keydown', (e) => {
    if (!autocompleteContainer || autocompleteContainer.style.display === 'none') return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        moveAutocomplete(1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        moveAutocomplete(-1);
    } else if (e.key === 'Enter') {
        if (autocompleteItems.length > 0) {
            e.preventDefault();
            if (autocompleteIndex >= 0 && autocompleteIndex < autocompleteItems.length) {
                const item = autocompleteItems[autocompleteIndex];
                selectAutocompleteItem(item.dataset.id);
            } else {
                const item = autocompleteItems[0];
                selectAutocompleteItem(item.dataset.id);
            }
        }
    } else if (e.key === 'Escape') {
        closeAutocomplete();
    }
});

// fermer l’auto-complétion quand on clique à l’extérieur
document.addEventListener('click', (e) => {
    if (!autocompleteContainer) return;
    if (
        e.target !== searchInput &&
        !autocompleteContainer.contains(e.target)
    ) {
        closeAutocomplete();
    }
});

// repositionner la liste sur resize
window.addEventListener('resize', () => {
    updateAutocompletePosition();
});

renderTable();
