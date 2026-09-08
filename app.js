// app.js — player, coda offline, wake lock, chiamate API.
// JavaScript vanilla, nessun build step. Va nel browser così com'è.
//
// Filosofia (CLAUDE.md punto 7): il player non dipende mai dalla rete.
// Ogni set viene scritto SUBITO in localStorage e la UI avanza; un worker
// in background prova a svuotare la coda.

'use strict';

// ===========================================================================
// Stato in memoria
// ===========================================================================

// Stato del player per la sessione corrente. Null fuori dall'allenamento.
let player = null;
// player = {
//   workoutLogId, workoutName,
//   exercises: [ {id, name, target, target_sets, rest_seconds, ...} ],
//   exIndex,          // indice esercizio corrente
//   setNumber,        // numero serie corrente (1..target_sets)
//   lastWeight        // ultimo peso digitato, per precompilare la serie dopo
// };

let wakeLock = null;      // sentinella WakeLock, o null
let restTimerId = null;   // interval del timer di recupero
let flushing = false;     // evita flush concorrenti della coda
let audioCtx = null;      // AudioContext per il beep di fine recupero
let workTimer = null;     // countdown di lavoro degli esercizi a tempo

// Chiavi localStorage.
const K_QUEUE = 'GHISA_QUEUE';                 // array di set in attesa di invio
const K_PENDING_FINISH = 'GHISA_PENDING_FINISH'; // chiusura sessione differita
const K_PENDING_CANCEL = 'GHISA_PENDING_CANCEL'; // annullamento sessione differito
const K_PENDING_DELETES = 'GHISA_PENDING_DELETES'; // serie annullate da cancellare


// ===========================================================================
// Riferimenti DOM (presenti in index.php)
// ===========================================================================

const $ = (sel) => document.querySelector(sel);

const els = {
    loginForm:   $('#login-form'),
    word1:       $('#login-word1'),
    word2:       $('#login-word2'),
    loginError:  $('#login-error'),
    loginSubmit: $('#login-submit'),
    logoutBtn:   $('#logout-btn'),
    queueInd:    $('#queue-indicator'),
    home:        $('#home-view'),
    playerView:  $('#player-view'),
    history:     $('#history-view'),
};

// Le schermate riepilogo, fine, alternative ed editor le crea app.js
// (index.php non le prevede).
let summaryView = null;
let finishView = null;
let altView = null;
let editView = null;

// Stato della home: modalità gestione e cestino schede aperto.
let homeEditMode = false;
let homeShowDeleted = false;


// ===========================================================================
// Utilità
// ===========================================================================

// UUID per il client_uid del set. randomUUID dove c'è, fallback altrimenti.
function uuid() {
    if (window.crypto && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

// Formatta un peso togliendo gli zeri decimali inutili: "70.00" -> "70".
function fmtWeight(w) {
    if (w === null || w === undefined || w === '') return '—';
    const n = parseFloat(w);
    if (Number.isNaN(n)) return '—';
    return String(parseFloat(n.toFixed(2)));
}

// Etichetta "quanti giorni fa" dell'ultima sessione completata di una scheda.
function daysAgoLabel(dt) {
    if (!dt) return 'mai fatta';
    const then = new Date(String(dt).replace(' ', 'T'));
    if (isNaN(then.getTime())) return '';
    const t0 = new Date(then.getFullYear(), then.getMonth(), then.getDate());
    const now = new Date();
    const n0 = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const days = Math.round((n0 - t0) / 86400000);
    if (days <= 0) return 'oggi';
    if (days === 1) return 'ieri';
    return days + ' giorni fa';
}

// Data e ora leggibili all'italiana: 31/08/2026 18:42
function formatStamp(d) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} `
        + `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

// Riepilogo pronto da incollare altrove: la data/ora finisce in coda alla
// prima riga (che è il nome della scheda), così resta compatto.
function summaryWithStamp(summary) {
    const stamp = formatStamp(new Date());
    const lines = String(summary || '').split('\n');
    if (lines.length && lines[0].trim() !== '') {
        lines[0] = lines[0] + ' — ' + stamp;
        return lines.join('\n');
    }
    return stamp + '\n' + summary;
}

// Copia negli appunti. La via moderna richiede un contesto sicuro (HTTPS o
// localhost): sul vhost in http:// non c'è, quindi si ripiega sulla textarea
// nascosta + execCommand, che lì funziona ancora.
async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (e) {
            // cade nel fallback qui sotto
        }
    }

    try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, ta.value.length);   // serve su iOS
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
    } catch (e) {
        return false;
    }
}

// Avviso effimero in alto.
function toast(msg) {
    let t = $('#toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'toast';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => t.classList.remove('show'), 3000);
}


// ===========================================================================
// Chiamate API
// ===========================================================================

// POST a api.php. Ritorna l'oggetto {ok, data} / {ok, error}.
// Lancia un'eccezione solo su errore di rete o 401 (sessione scaduta).
async function api(action, params = {}) {
    const body = new URLSearchParams();
    body.set('action', action);
    for (const [k, v] of Object.entries(params)) {
        // null/undefined -> stringa vuota, così il server li tratta come NULL.
        body.set(k, v === null || v === undefined ? '' : v);
    }

    const resp = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    });

    if (resp.status === 401) {
        // Sessione scaduta: torna al login ricaricando la shell.
        location.href = 'index.php';
        throw new Error('session-expired');
    }

    return resp.json();
}


// ===========================================================================
// Coda offline
// ===========================================================================

function queueGetAll() {
    try {
        return JSON.parse(localStorage.getItem(K_QUEUE) || '[]');
    } catch (e) {
        return [];
    }
}

function queueSave(list) {
    localStorage.setItem(K_QUEUE, JSON.stringify(list));
    updateQueueIndicator();
}

function queueAdd(set) {
    const list = queueGetAll();
    list.push(set);
    queueSave(list);
}

function queueRemove(clientUid) {
    const list = queueGetAll().filter((s) => s.client_uid !== clientUid);
    queueSave(list);
}

function updateQueueIndicator() {
    const n = queueGetAll().length;
    if (n > 0) {
        els.queueInd.textContent = n === 1 ? '1 set in coda' : n + ' set in coda';
        els.queueInd.hidden = false;
    } else {
        els.queueInd.hidden = true;
    }
}

// Prova a svuotare la coda. Si ferma al primo errore di rete e riproverà
// più tardi. Un duplicate:true dal server conta come successo (idempotenza).
async function flushQueue() {
    if (flushing) return;
    flushing = true;
    try {
        for (const set of queueGetAll()) {
            let res;
            try {
                res = await api('log_set', set);
            } catch (e) {
                break; // rete assente: ci si riprova dopo
            }
            if (res && res.ok) {
                queueRemove(set.client_uid); // include il caso duplicate:true
            } else {
                // Errore applicativo (es. dato non valido): non è recuperabile
                // riprovando all'infinito. Lo tolgo e lo segnalo.
                queueRemove(set.client_uid);
                toast('Un set non è stato accettato dal server.');
            }
        }
        await flushPendingDeletes();
        await maybeFinishPending();
        await tryCancelPending();
    } finally {
        flushing = false;
    }
}


// ===========================================================================
// Chiusura sessione differita (per l'offline)
// ===========================================================================

function savePendingFinish(workoutLogId, notes) {
    localStorage.setItem(K_PENDING_FINISH, JSON.stringify({
        workout_log_id: workoutLogId,
        notes: notes || '',
    }));
}

function getPendingFinish() {
    try {
        return JSON.parse(localStorage.getItem(K_PENDING_FINISH) || 'null');
    } catch (e) {
        return null;
    }
}

function clearPendingFinish() {
    localStorage.removeItem(K_PENDING_FINISH);
}

// Se c'è una chiusura in sospeso e la coda dei set è vuota, chiude davvero.
// Silenzioso: la sessione è già finita per l'utente, i dati sono salvi.
async function maybeFinishPending() {
    const pend = getPendingFinish();
    if (!pend) return;
    if (queueGetAll().length > 0) return; // prima tutti i set

    try {
        const res = await api('finish_workout', {
            workout_log_id: pend.workout_log_id,
            notes: pend.notes,
        });
        if (res && res.ok) {
            clearPendingFinish();
        }
    } catch (e) {
        // Ancora offline: si riprova al prossimo flush.
    }
}

// --- Annulla ultima serie -------------------------------------------------
// Rete di sicurezza per la serie fantasma che sfugge al blocco anti doppio tap.
// Si può correggere solo la sessione in corso: lo storico chiuso resta immutabile.

async function undoLastSet() {
    if (!player || !player.history.length) {
        toast('Nessuna serie da annullare.');
        return;
    }
    if (!confirm('Annullare l\'ultima serie registrata?')) return;

    const last = player.history.pop();

    // Se non è ancora partita, basta toglierla dalla coda.
    const stillQueued = queueGetAll().some((x) => x.client_uid === last.client_uid);
    queueSave(queueGetAll().filter((x) => x.client_uid !== last.client_uid));

    // Se era già arrivata al server va cancellata lì (idempotente).
    if (!stillQueued) {
        try {
            const res = await api('delete_set', { client_uid: last.client_uid });
            if (!res || !res.ok) addPendingDelete(last.client_uid);
        } catch (e) {
            addPendingDelete(last.client_uid);   // offline: si riprova dopo
        }
    }

    // Riporta il player alla serie annullata.
    player.exIndex = last.exIndex;
    player.setNumber = last.setNumber;
    player.lastWeight = last.weight;
    stopRest();
    stopWorkTimer();
    renderCurrentSet();
    toast('Serie annullata');
}

function getPendingDeletes() {
    try {
        return JSON.parse(localStorage.getItem(K_PENDING_DELETES) || '[]');
    } catch (e) {
        return [];
    }
}

function addPendingDelete(clientUid) {
    const list = getPendingDeletes();
    list.push(clientUid);
    localStorage.setItem(K_PENDING_DELETES, JSON.stringify(list));
}

// Riprova le cancellazioni rimaste indietro (annullo fatto da offline).
async function flushPendingDeletes() {
    const list = getPendingDeletes();
    if (!list.length) return;
    const left = [];
    for (const uid of list) {
        try {
            const res = await api('delete_set', { client_uid: uid });
            if (!res || !res.ok) left.push(uid);
        } catch (e) {
            left.push(uid);
        }
    }
    localStorage.setItem(K_PENDING_DELETES, JSON.stringify(left));
}


// --- Annullamento sessione differito (per l'offline) ----------------------

function savePendingCancel(workoutLogId) {
    localStorage.setItem(K_PENDING_CANCEL, String(workoutLogId));
}

function getPendingCancel() {
    const v = localStorage.getItem(K_PENDING_CANCEL);
    return v ? parseInt(v, 10) : null;
}

function clearPendingCancel() {
    localStorage.removeItem(K_PENDING_CANCEL);
}

// Se c'è un annullamento in sospeso, prova a cancellare la sessione sul server.
async function tryCancelPending() {
    const id = getPendingCancel();
    if (!id) return;
    try {
        const res = await api('cancel_workout', { workout_log_id: id });
        if (res && res.ok) {
            clearPendingCancel();
        }
    } catch (e) {
        // Offline: si riprova al prossimo flush / evento online.
    }
}


// ===========================================================================
// Navigazione tra viste e schermate
// ===========================================================================

// Passa tra login e app agendo su body[data-logged-in] (lo stile fa il resto).
function showApp(logged) {
    document.body.dataset.loggedIn = logged ? '1' : '0';
}

// Mostra una sola schermata dentro l'app.
function showScreen(name) {
    els.home.hidden = name !== 'home';
    els.playerView.hidden = name !== 'player';
    els.history.hidden = name !== 'history';
    if (summaryView) summaryView.hidden = name !== 'summary';
    if (finishView) finishView.hidden = name !== 'finish';
    if (altView) altView.hidden = name !== 'alt';
    if (editView) editView.hidden = name !== 'edit';
}

// Riallinea l'elenco schede (vive + cestino) da una risposta del server.
function applyWorkoutsPayload(data) {
    if (!data) return;
    if (Array.isArray(data.workouts)) {
        window.GHISA.workouts = data.workouts;
    }
    if (Array.isArray(data.deleted_workouts)) {
        window.GHISA.deleted_workouts = data.deleted_workouts;
    }
}


// ===========================================================================
// Login / logout
// ===========================================================================

async function handleLogin(e) {
    e.preventDefault();
    els.loginError.hidden = true;
    els.loginSubmit.disabled = true;

    try {
        const res = await api('login', {
            word1: els.word1.value,
            word2: els.word2.value,
        });
        if (res.ok) {
            window.GHISA.loggedIn = true;
            applyWorkoutsPayload(res.data);
            showApp(true);
            renderHome();
            showScreen('home');
        } else {
            els.loginError.textContent = res.error || 'Accesso non riuscito';
            els.loginError.hidden = false;
        }
    } catch (err) {
        els.loginError.textContent = 'Errore di rete. Riprova.';
        els.loginError.hidden = false;
    } finally {
        els.loginSubmit.disabled = false;
    }
}


// ===========================================================================
// Home: selettore scheda + accesso allo storico
// ===========================================================================

function renderHome() {
    const workouts = window.GHISA.workouts || [];
    els.home.textContent = '';

    const h = document.createElement('h2');
    h.textContent = homeEditMode ? 'Gestisci schede' : 'Le tue schede';
    els.home.appendChild(h);

    if (workouts.length === 0) {
        const p = document.createElement('p');
        p.textContent = homeEditMode
            ? 'Nessuna scheda. Creane una qui sotto.'
            : 'Nessuna scheda. Vai su "Gestisci schede" per crearne una.';
        els.home.appendChild(p);
    }

    workouts.forEach((w, i) => {
        if (homeEditMode) {
            els.home.appendChild(renderWorkoutEditRow(w, i, workouts.length));
        } else {
            // Modalità allenamento: un tasto grande e pulito che avvia.
            const btn = document.createElement('button');
            btn.className = 'big wk';

            const name = document.createElement('span');
            name.className = 'wk-name';
            name.textContent = w.name;            // textContent: niente XSS
            btn.appendChild(name);

            const sub = document.createElement('span');
            sub.className = 'wk-sub';
            sub.textContent = daysAgoLabel(w.last_finished);
            btn.appendChild(sub);

            btn.addEventListener('click', () => startWorkout(w.id));
            els.home.appendChild(btn);
        }
    });

    if (homeEditMode) {
        const add = document.createElement('button');
        add.className = 'big primary';
        add.textContent = '+ Nuova scheda';
        add.addEventListener('click', createWorkout);
        els.home.appendChild(add);

        renderDeletedWorkouts();

        const done = document.createElement('button');
        done.className = 'link';
        done.textContent = 'Fine';
        done.addEventListener('click', () => {
            homeEditMode = false;
            homeShowDeleted = false;
            renderHome();
        });
        els.home.appendChild(done);
    } else {
        const histBtn = document.createElement('button');
        histBtn.className = 'link';
        histBtn.textContent = 'Storico allenamenti';
        histBtn.addEventListener('click', openHistory);
        els.home.appendChild(histBtn);

        const manage = document.createElement('button');
        manage.className = 'link';
        manage.textContent = 'Gestisci schede';
        manage.addEventListener('click', () => {
            homeEditMode = true;
            renderHome();
        });
        els.home.appendChild(manage);
    }
}

// Riga scheda in modalità gestione: nome (apre l'editor) + riordino + cestino.
function renderWorkoutEditRow(w, i, total) {
    const row = document.createElement('div');
    row.className = 'edit-row';

    const name = document.createElement('button');
    name.className = 'edit-name';
    name.textContent = w.name;
    name.addEventListener('click', () => openEditor(w.id));
    row.appendChild(name);

    const tools = document.createElement('div');
    tools.className = 'edit-tools';
    tools.appendChild(toolBtn('↑', i === 0, () => moveWorkout(i, -1)));
    tools.appendChild(toolBtn('↓', i === total - 1, () => moveWorkout(i, 1)));
    tools.appendChild(toolBtn('🗑', false, () => trashWorkout(w)));
    row.appendChild(tools);

    return row;
}

function renderDeletedWorkouts() {
    const del = window.GHISA.deleted_workouts || [];
    if (!del.length) return;

    const tog = document.createElement('button');
    tog.className = 'link';
    tog.textContent = homeShowDeleted
        ? 'Nascondi eliminate'
        : `Mostra eliminate (${del.length})`;
    tog.addEventListener('click', () => {
        homeShowDeleted = !homeShowDeleted;
        renderHome();
    });
    els.home.appendChild(tog);

    if (!homeShowDeleted) return;

    del.forEach((w) => {
        const row = document.createElement('div');
        row.className = 'edit-row deleted';
        const nm = document.createElement('span');
        nm.className = 'edit-name';
        nm.textContent = w.name;
        row.appendChild(nm);
        const b = toolBtn('↩', false, () => restoreWorkout(w));
        row.appendChild(b);
        els.home.appendChild(row);
    });

    const note = document.createElement('p');
    note.className = 'alt-for';
    note.textContent = 'Le eliminate spariscono per sempre dopo 30 giorni.';
    els.home.appendChild(note);
}

async function createWorkout() {
    const name = prompt('Nome della nuova scheda:');
    if (name === null) return;
    if (!name.trim()) {
        toast('Serve il nome.');
        return;
    }
    try {
        const res = await api('save_workout', { name: name.trim() });
        if (res.ok) {
            applyWorkoutsPayload(res.data);
            renderHome();
            toast('Scheda creata');
        } else {
            toast(res.error || 'Errore.');
        }
    } catch (e) {
        toast('Serve la rete.');
    }
}

async function moveWorkout(i, dir) {
    const list = (window.GHISA.workouts || []).slice();
    const j = i + dir;
    if (j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j], list[i]];

    try {
        const res = await api('reorder', {
            type: 'workout',
            ids: list.map((w) => w.id).join(','),
        });
        if (res.ok) {
            applyWorkoutsPayload(res.data);
            renderHome();
        } else {
            toast(res.error || 'Errore.');
        }
    } catch (e) {
        toast('Serve la rete.');
    }
}

async function trashWorkout(w) {
    const ok = confirm(`Spostare «${w.name}» nel cestino?\n`
        + 'Sparirà per sempre dopo 30 giorni. Lo storico resta comunque.');
    if (!ok) return;
    try {
        const res = await api('delete_workout', { workout_id: w.id });
        if (res.ok) {
            applyWorkoutsPayload(res.data);
            renderHome();
            toast('Spostata nel cestino');
        } else {
            toast(res.error || 'Errore.');
        }
    } catch (e) {
        toast('Serve la rete.');
    }
}

async function restoreWorkout(w) {
    try {
        const res = await api('delete_workout', { workout_id: w.id, restore: '1' });
        if (res.ok) {
            applyWorkoutsPayload(res.data);
            renderHome();
            toast('Ripristinata');
        } else {
            toast(res.error || 'Errore.');
        }
    } catch (e) {
        toast('Serve la rete.');
    }
}

// Tastino quadrato per riordino/cestino/ripristino.
function toolBtn(label, disabled, fn) {
    const b = document.createElement('button');
    b.className = 'tool';
    b.type = 'button';
    b.textContent = label;
    b.disabled = !!disabled;
    if (!disabled) b.addEventListener('click', fn);
    return b;
}


// ===========================================================================
// Player
// ===========================================================================

async function startWorkout(workoutId) {
    // Richiesto qui, non dopo l'await: il wake lock vuole un gesto utente
    // "fresco" e Safari rifiuta la richiesta se l'attivazione è già scaduta.
    acquireWakeLock();

    let res;
    try {
        res = await api('get_workout', { workout_id: workoutId });
    } catch (e) {
        releaseWakeLock();
        toast('Serve la rete per iniziare un allenamento.');
        return;
    }
    if (!res.ok) {
        releaseWakeLock();
        toast(res.error || 'Impossibile aprire la scheda.');
        return;
    }

    const d = res.data;
    player = {
        workoutLogId: d.workout_log_id,
        workoutId: d.workout.id,
        workoutName: d.workout.name,
        exercises: d.exercises,
        exIndex: 0,
        setNumber: 1,
        lastWeight: null,
        history: [],          // serie registrate, per l'annulla
    };

    buildPlayerSkeleton();
    renderCurrentSet();
    showScreen('player');
}

// Costruisce una volta la struttura statica del player; i singoli campi
// vengono aggiornati serie per serie.
function buildPlayerSkeleton() {
    els.playerView.textContent = '';
    els.playerView.innerHTML = `
        <div class="player">
            <div class="p-progress" id="p-progress"></div>
            <h2 class="p-exercise" id="p-exercise"></h2>
            <div class="p-target" id="p-target"></div>
            <a id="p-url" class="p-url" target="_blank" rel="noopener noreferrer" hidden>Vedi esercizio ↗</a>
            <div class="p-set" id="p-set"></div>
            <div class="p-last" id="p-last" hidden></div>

            <label class="p-field">
                Peso (kg)
                <input id="p-weight" type="number" inputmode="decimal"
                       step="0.5" min="0">
            </label>
            <label class="p-field">
                <span id="p-reps-label">Ripetizioni</span>
                <input id="p-reps" type="number" inputmode="numeric"
                       step="1" min="0">
            </label>

            <button id="p-work" class="big work" type="button" hidden></button>

            <div class="p-bar" id="p-bar" aria-hidden="true"></div>

            <button id="p-next" class="big primary">Avanti</button>

            <div class="p-extra">
                <button id="p-add-set" class="link" type="button">+ Serie</button>
                <button id="p-skip" class="link" type="button">Salta esercizio</button>
                <button id="p-alt" class="link" type="button">Alternativa</button>
                <button id="p-undo" class="link" type="button">↶ Annulla serie</button>
            </div>

            <div id="p-rest" class="p-rest" hidden>
                Recupero <span id="p-rest-count"></span>s
                <button id="p-rest-skip" class="link">salta</button>
            </div>

            <button id="p-finish" class="link">Termina allenamento</button>
            <button id="p-cancel" class="link danger">Annulla allenamento</button>

            <div class="exlist-wrap">
                <h3 class="exlist-title">Esercizi della scheda</h3>
                <ol id="p-exlist" class="exlist"></ol>
            </div>
        </div>
    `;

    $('#p-next').addEventListener('click', onNextSet);
    $('#p-add-set').addEventListener('click', addExtraSet);
    $('#p-skip').addEventListener('click', skipExercise);
    $('#p-alt').addEventListener('click', openAlternatives);
    $('#p-undo').addEventListener('click', undoLastSet);
    $('#p-work').addEventListener('click', toggleWorkTimer);
    $('#p-finish').addEventListener('click', () => openFinish(false));
    $('#p-cancel').addEventListener('click', cancelSession);
    $('#p-rest-skip').addEventListener('click', stopRest);
}

function currentExercise() {
    return player.exercises[player.exIndex];
}

// Aggiorna i campi con l'esercizio e la serie correnti.
function renderCurrentSet() {
    const ex = currentExercise();
    const total = setsPlanned(ex);

    $('#p-progress').textContent =
        `Esercizio ${player.exIndex + 1} di ${player.exercises.length}`;
    $('#p-exercise').textContent = ex.name;
    $('#p-target').textContent = ex.target ? 'Obiettivo ' + ex.target : '';
    $('#p-set').textContent = `Serie ${player.setNumber} di ${total}`;
    renderSetBar(ex);
    renderLastSets(ex);

    // Link esplicativo dell'esercizio, se presente e sicuro (http/https).
    const urlEl = $('#p-url');
    if (ex.url && /^https?:\/\//i.test(ex.url)) {
        urlEl.href = ex.url;
        urlEl.hidden = false;
    } else {
        urlEl.removeAttribute('href');
        urlEl.hidden = true;
    }

    // Precompilazione peso: se sto continuando lo stesso esercizio uso l'ultimo
    // peso digitato; altrimenti l'ultimo peso storico dell'esercizio.
    const weightInput = $('#p-weight');
    if (player.lastWeight !== null) {
        weightInput.value = player.lastWeight;
    } else if (ex.last_weight_kg !== null && ex.last_weight_kg !== undefined) {
        weightInput.value = ex.last_weight_kg;
    } else {
        weightInput.value = '';
    }

    // Precompilazione reps/secondi: il target se è un numero pulito.
    const repsInput = $('#p-reps');
    repsInput.value = /^\d+$/.test(ex.target_reps || '') ? ex.target_reps : '';

    // Esercizio a tempo: cambia l'etichetta e compare il timer di lavoro.
    // I secondi di tenuta finiscono in reps_completed, il recupero resta
    // rest_seconds come per gli altri esercizi.
    const isTime = ex.type === 'time';
    $('#p-reps-label').textContent = isTime ? 'Secondi' : 'Ripetizioni';
    stopWorkTimer();
    $('#p-work').hidden = !isTime;
    if (isTime) renderWorkButton();

    renderExerciseList();
    stopRest();
}

// Lista completa degli esercizi della scheda, con evidenziato quello in corso
// e barrati quelli già completati. Ridisegnata a ogni serie.
function renderExerciseList() {
    const ol = $('#p-exlist');
    if (!ol) return;
    ol.textContent = '';

    player.exercises.forEach((ex, i) => {
        const li = document.createElement('li');
        li.className = 'exlist-item';
        if (i === player.exIndex) li.classList.add('current');
        else if (i < player.exIndex) li.classList.add('done');

        const name = document.createElement('span');
        name.className = 'exlist-name';
        name.textContent = ex.name;             // textContent: niente XSS
        li.appendChild(name);

        if (ex.target) {
            const t = document.createElement('span');
            t.className = 'exlist-target';
            t.textContent = ex.target;
            li.appendChild(t);
        }

        ol.appendChild(li);
    });
}

// Tap su "Avanti": registra il set in locale, avanza subito, avvia il recupero.
function onNextSet() {
    ensureAudio();                 // sblocca l'audio col gesto dell'utente
    tapBuzz();
    confirmTap($('#p-next'), '✓ Serie registrata');

    const ex = currentExercise();
    const weightRaw = $('#p-weight').value.trim();
    const repsRaw = $('#p-reps').value.trim();

    // Il set finisce SUBITO in coda (localStorage). Nessuna attesa di rete.
    const set = {
        client_uid: uuid(),
        workout_log_id: player.workoutLogId,
        exercise_id: ex.id,
        set_number: player.setNumber,
        weight_kg: weightRaw === '' ? null : weightRaw,
        reps_completed: repsRaw === '' ? null : repsRaw,
    };
    queueAdd(set);
    player.history.push({
        client_uid: set.client_uid,
        exIndex: player.exIndex,
        setNumber: player.setNumber,
        weight: player.lastWeight,
    });
    player.lastWeight = weightRaw === '' ? player.lastWeight : weightRaw;

    // Prova a inviare in background, senza bloccare la UI.
    flushQueue();

    // Avanza lo stato e avvia il recupero.
    const totalSets = setsPlanned(ex);
    const rest = parseInt(ex.rest_seconds, 10) || 0;

    if (player.setNumber < totalSets) {
        player.setNumber += 1;
        renderCurrentSet();
        startRest(rest);
    } else {
        // Esercizio finito: passa al prossimo.
        player.exIndex += 1;
        player.setNumber = 1;
        player.lastWeight = null;
        if (player.exIndex >= player.exercises.length) {
            openFinish(true); // era l'ultima serie dell'ultimo esercizio
        } else {
            renderCurrentSet();
            startRest(rest);
        }
    }
}

// Numero di serie previste per un esercizio: target + eventuali serie extra
// aggiunte al volo in palestra (feature "+ Serie").
function setsPlanned(ex) {
    return (parseInt(ex.target_sets, 10) || 1) + (ex._extra || 0);
}

// Barra delle serie sopra "Avanti": una casella per serie prevista.
// Piena = fatta, contornata = corrente, vuota = da fare. Si adatta al "+ Serie".
function renderSetBar(ex) {
    const bar = $('#p-bar');
    if (!bar) return;
    bar.textContent = '';

    const total = setsPlanned(ex);
    for (let i = 1; i <= total; i++) {
        const block = document.createElement('span');
        block.className = 'p-block';
        if (i < player.setNumber) {
            block.classList.add('done');
        } else if (i === player.setNumber) {
            block.classList.add('current');
        }
        bar.appendChild(block);
    }
}

// Quanto resta bloccato un tasto dopo il tap.
const TAP_LOCK_MS = 1200;

// Blocca il tasto per un istante mostrando una conferma. Serve a due cose:
// rendere evidente che il tap è stato registrato, e impedire il doppio tap
// accidentale (che creerebbe una serie fantasma nello storico: l'idempotenza
// su client_uid protegge dai reinvii di rete, non da due tap umani).
function confirmTap(btn, confirmLabel) {
    if (!btn || btn.disabled) return;

    const original = btn.textContent;
    btn.disabled = true;
    btn.classList.add('confirming');
    btn.textContent = confirmLabel;

    setTimeout(() => {
        btn.textContent = original;
        btn.classList.remove('confirming');
        btn.disabled = false;
    }, TAP_LOCK_MS);
}

// Conferma tattile immediata: col telefono in mano e lo sguardo altrove
// è il segnale che arriva prima di tutti. Su iOS non c'è, pazienza.
function tapBuzz() {
    if (navigator.vibrate) {
        navigator.vibrate(30);
    }
}

// "+ Serie": aggiunge una serie all'esercizio corrente, senza toccare la scheda.
function addExtraSet() {
    const ex = currentExercise();
    ex._extra = (ex._extra || 0) + 1;
    renderCurrentSet();
    toast('Serie aggiunta');
}

// "Salta esercizio": passa al prossimo senza registrare nulla per questo.
function skipExercise() {
    tapBuzz();
    confirmTap($('#p-skip'), '✓ Saltato');

    player.exIndex += 1;
    player.setNumber = 1;
    player.lastWeight = null;
    if (player.exIndex >= player.exercises.length) {
        openFinish(true);
    } else {
        renderCurrentSet();
        stopRest();
    }
}

// Riferimento "l'ultima volta": serie eseguite l'ultima sessione completata.
function renderLastSets(ex) {
    const box = $('#p-last');
    if (!box) return;
    const sets = ex.last_sets || [];
    if (sets.length === 0) {
        box.hidden = true;
        box.textContent = '';
        return;
    }
    const parts = sets.map((s) => {
        const reps = (s.reps_completed === null || s.reps_completed === '')
            ? '—' : s.reps_completed;
        // Senza peso (corpo libero o esercizio a tempo) basta il valore.
        return (s.weight_kg === null || s.weight_kg === '')
            ? String(reps)
            : fmtWeight(s.weight_kg) + '×' + reps;
    });
    box.textContent = 'Ultima volta: ' + parts.join(', ');
    box.hidden = false;
}

// --- Alternative -----------------------------------------------------------
// Il "titolare" dello slot resta il previsto anche dopo una sostituzione a
// video: le alternative si chiedono sempre per il titolare originale.
function currentSlotPrimaryId() {
    const ex = currentExercise();
    return ex._slotPrimaryId || ex.id;
}
function currentSlotPrimaryName() {
    const ex = currentExercise();
    return ex._primaryName || ex.name;
}

function ensureAltView() {
    if (!altView) {
        altView = document.createElement('section');
        altView.id = 'alt-view';
        altView.className = 'screen';
        $('#app-view').appendChild(altView);
    }
}

// Apre la schermata alternativa per lo slot corrente.
async function openAlternatives() {
    const primaryId = currentSlotPrimaryId();
    const primaryName = currentSlotPrimaryName();

    ensureAltView();
    renderAltView({ primaryId, primaryName, loading: true });
    showScreen('alt');

    try {
        const res = await api('get_alternatives', { exercise_id: primaryId });
        if (res.ok) {
            renderAltView({ primaryId, primaryName, alternatives: res.data.alternatives || [] });
        } else {
            renderAltView({ primaryId, primaryName, alternatives: [], error: res.error });
        }
    } catch (e) {
        renderAltView({
            primaryId, primaryName, alternatives: [],
            error: 'Serve la rete per gestire le alternative.',
        });
    }
}

function renderAltView(state) {
    altView.textContent = '';

    const h = document.createElement('h2');
    h.textContent = 'Alternativa';
    altView.appendChild(h);

    const forLbl = document.createElement('p');
    forLbl.className = 'alt-for';
    forLbl.textContent = 'Al posto di ' + state.primaryName;
    altView.appendChild(forLbl);

    if (state.loading) {
        const l = document.createElement('p');
        l.textContent = 'Carico…';
        altView.appendChild(l);
        return;
    }

    if (state.error) {
        const e = document.createElement('p');
        e.className = 'error';
        e.textContent = state.error;
        altView.appendChild(e);
    }

    // Alternative già usate per questo slot.
    const alts = state.alternatives || [];
    if (alts.length) {
        const t = document.createElement('h3');
        t.className = 'exlist-title';
        t.textContent = 'Già usate';
        altView.appendChild(t);
        alts.forEach((a) => {
            const btn = document.createElement('button');
            btn.className = 'big wk';
            const n = document.createElement('span');
            n.className = 'wk-name';
            n.textContent = a.name;
            btn.appendChild(n);
            const s = document.createElement('span');
            s.className = 'wk-sub';
            s.textContent = a.target || '';
            btn.appendChild(s);
            btn.addEventListener('click', () => applyAlternative(a));
            altView.appendChild(btn);
        });
    }

    // Form per una nuova alternativa (target precompilato dal corrente).
    const t2 = document.createElement('h3');
    t2.className = 'exlist-title';
    t2.textContent = 'Nuova alternativa';
    altView.appendChild(t2);

    const curEx = currentExercise();
    const name = altField('Nome', 'text');
    const sets = altField('Serie', 'number');
    const reps = altField('Ripetizioni', 'text');
    const url = altField('Link (opzionale)', 'url');
    sets.input.value = curEx.target_sets != null ? curEx.target_sets : '';
    reps.input.value = curEx.target_reps != null ? curEx.target_reps : '';
    altView.appendChild(name.label);
    altView.appendChild(sets.label);
    altView.appendChild(reps.label);
    altView.appendChild(url.label);

    const add = document.createElement('button');
    add.className = 'big primary';
    add.textContent = 'Usa questa alternativa';
    add.addEventListener('click', async () => {
        const nm = name.input.value.trim();
        if (!nm) { toast('Serve il nome.'); return; }
        add.disabled = true;
        try {
            const res = await api('add_alternative', {
                primary_exercise_id: state.primaryId,
                name: nm,
                target_sets: sets.input.value.trim(),
                target_reps: reps.input.value.trim(),
                url: url.input.value.trim(),
            });
            if (res.ok) {
                applyAlternative(res.data.exercise);
            } else {
                toast(res.error || 'Errore.');
                add.disabled = false;
            }
        } catch (e) {
            toast('Serve la rete per aggiungere un\'alternativa.');
            add.disabled = false;
        }
    });
    altView.appendChild(add);

    const back = document.createElement('button');
    back.className = 'link';
    back.textContent = 'Torna all\'allenamento';
    back.addEventListener('click', () => showScreen('player'));
    altView.appendChild(back);
}

// Piccola factory per un campo etichettato.
function altField(text, type) {
    const label = document.createElement('label');
    label.className = 'p-field';
    const caption = document.createElement('span');
    caption.textContent = text;
    label.appendChild(caption);
    const input = document.createElement('input');
    input.type = type === 'number' ? 'number' : (type === 'url' ? 'url' : 'text');
    if (type === 'number') {
        input.inputMode = 'numeric';
        input.min = '0';
        input.step = '1';
    }
    label.appendChild(input);
    return { label, input, caption };
}

// Campo con menu a tendina (usato per il tipo di esercizio).
function altSelect(text, options, value) {
    const label = document.createElement('label');
    label.className = 'p-field';
    const caption = document.createElement('span');
    caption.textContent = text;
    label.appendChild(caption);

    const input = document.createElement('select');
    options.forEach(([v, t]) => {
        const o = document.createElement('option');
        o.value = v;
        o.textContent = t;
        if (v === value) o.selected = true;
        input.appendChild(o);
    });
    label.appendChild(input);
    return { label, input, caption };
}

// Sostituisce a video l'esercizio dello slot con l'alternativa scelta.
// Il titolare originale resta memorizzato per la scelta di fine allenamento.
function applyAlternative(alt) {
    const cur = currentExercise();
    alt._slotPrimaryId = cur._slotPrimaryId || cur.id;
    alt._primaryName = cur._primaryName || cur.name;
    alt._substituted = true;

    player.exercises[player.exIndex] = alt;
    player.setNumber = 1;
    player.lastWeight = null;

    stopRest();
    renderCurrentSet();
    showScreen('player');
    toast('Sostituito con ' + alt.name);
}


// ===========================================================================
// Timer di recupero (scelta A: automatico, con tasto "salta")
// ===========================================================================

function startRest(seconds) {
    stopRest();
    if (!seconds || seconds <= 0) return;

    let remaining = seconds;
    const box = $('#p-rest');
    const count = $('#p-rest-count');
    count.textContent = remaining;
    box.hidden = false;

    restTimerId = setInterval(() => {
        remaining -= 1;
        if (remaining <= 0) {
            stopRest();
            restEndCue();
            toast('Recupero finito');
        } else {
            count.textContent = remaining;
        }
    }, 1000);
}

// --- Timer di lavoro (esercizi a tempo) ----------------------------------
// Un tap avvia la tenuta, un secondo tap la ferma prima. In entrambi i casi i
// secondi effettivi finiscono nel campo, poi si registra la serie con "Avanti".

function toggleWorkTimer() {
    if (!player) return;

    if (workTimer) {                       // già in corso: fermo prima
        const done = workTimer.total - workTimer.remaining;
        stopWorkTimer();
        $('#p-reps').value = done > 0 ? done : '';
        toast('Fermato a ' + done + 's');
        return;
    }

    const ex = currentExercise();
    const total = parseInt(ex.target_reps, 10) || 0;
    if (total <= 0) {
        toast('Imposta i secondi nella scheda.');
        return;
    }

    ensureAudio();
    tapBuzz();
    workTimer = { total, remaining: total, id: null };
    renderWorkButton();

    workTimer.id = setInterval(() => {
        workTimer.remaining -= 1;
        if (workTimer.remaining <= 0) {
            const t = workTimer.total;
            stopWorkTimer();
            $('#p-reps').value = t;        // tenuta completa
            restEndCue();
            toast('Tempo!');
        } else {
            renderWorkButton();
        }
    }, 1000);
}

function renderWorkButton() {
    const btn = $('#p-work');
    if (!btn || !player) return;
    if (workTimer) {
        btn.textContent = '■ Ferma — ' + workTimer.remaining + 's';
        btn.classList.add('running');
    } else {
        const total = parseInt(currentExercise().target_reps, 10) || 0;
        btn.textContent = total ? '▶ Avvia ' + total + 's' : '▶ Avvia';
        btn.classList.remove('running');
    }
}

function stopWorkTimer() {
    if (workTimer && workTimer.id) {
        clearInterval(workTimer.id);
    }
    workTimer = null;
    const btn = $('#p-work');
    if (btn) btn.classList.remove('running');
    renderWorkButton();   // riporta l'etichetta a "Avvia"
}

// Segnale di fine recupero: vibrazione (dove supportata) + beep. In una sala
// rumorosa col telefono in tasca il solo toast non basta.
function restEndCue() {
    if (navigator.vibrate) {
        navigator.vibrate([200, 100, 200]);
    }
    beep();
}

// Prepara l'AudioContext. Va chiamata da un gesto utente (tap "Avanti") per
// aggirare l'autoplay policy; il beep vero arriva più tardi allo scadere.
function ensureAudio() {
    if (audioCtx) return;
    try {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (AC) audioCtx = new AC();
    } catch (e) {
        audioCtx = null;
    }
}

function beep() {
    if (!audioCtx) return;
    try {
        if (audioCtx.state === 'suspended') audioCtx.resume();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.type = 'sine';
        osc.frequency.value = 880;
        gain.gain.value = 0.15;
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.25);
    } catch (e) {
        // Audio non disponibile: pazienza, restano vibrazione e toast.
    }
}

function stopRest() {
    if (restTimerId) {
        clearInterval(restTimerId);
        restTimerId = null;
    }
    const box = $('#p-rest');
    if (box) box.hidden = true;
}


// ===========================================================================
// Fine allenamento
// ===========================================================================

// Apre la schermata di fine: nota rapida opzionale + salva. Tiene vivo il
// player così, se non hai davvero finito, puoi tornare indietro.
// `completed` = true quando sono terminate tutte le serie.
function openFinish(completed) {
    if (!player) return;
    stopRest();

    if (!finishView) {
        finishView = document.createElement('section');
        finishView.id = 'finish-view';
        finishView.className = 'screen';
        $('#app-view').appendChild(finishView);
    }
    finishView.textContent = '';

    const h = document.createElement('h2');
    h.textContent = 'Fine allenamento';
    finishView.appendChild(h);

    // Sostituzioni fatte in questa sessione: scegli se renderle definitive.
    const subs = player.exercises.filter((e) => e._substituted);
    if (subs.length) {
        const st = document.createElement('h3');
        st.className = 'exlist-title';
        st.textContent = 'Sostituzioni';
        finishView.appendChild(st);

        subs.forEach((s) => {
            const row = document.createElement('label');
            row.className = 'sub-row';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'sub-check';
            cb.dataset.altId = s.id;
            row.appendChild(cb);
            const txt = document.createElement('span');
            txt.textContent = `Rendi «${s.name}» definitivo al posto di «${s._primaryName}»`;
            row.appendChild(txt);
            finishView.appendChild(row);
        });
    }

    const label = document.createElement('label');
    label.className = 'p-field';
    label.textContent = 'Nota (opzionale)';
    const ta = document.createElement('textarea');
    ta.id = 'finish-notes';
    ta.rows = 3;
    ta.placeholder = 'Come è andata, dolori, energia…';
    label.appendChild(ta);
    finishView.appendChild(label);

    const save = document.createElement('button');
    save.className = 'big primary';
    save.textContent = 'Salva e chiudi';
    save.addEventListener('click', () => doFinish(ta.value.trim()));
    finishView.appendChild(save);

    if (!completed) {
        const back = document.createElement('button');
        back.className = 'link';
        back.textContent = 'Torna all\'allenamento';
        back.addEventListener('click', () => showScreen('player'));
        finishView.appendChild(back);
    }

    showScreen('finish');
}

// Salva davvero: segna la chiusura come "in sospeso", svuota la coda e, se ci
// riesce subito, mostra il riepilogo. Offline: si chiude alla riconnessione
// (maybeFinishPending), l'utente torna comunque alla home.
async function doFinish(notes) {
    if (!player) return;

    // Promuove le alternative segnate come definitive (richiede rete).
    if (finishView) {
        const checks = finishView.querySelectorAll('.sub-check:checked');
        for (const c of checks) {
            try {
                await api('promote_alternative', { alternative_exercise_id: c.dataset.altId });
            } catch (e) {
                toast('Sostituzione non salvata (offline).');
            }
        }
    }

    const workoutLogId = player.workoutLogId;
    const workoutId = player.workoutId;
    releaseWakeLock();
    stopRest();

    savePendingFinish(workoutLogId, notes || '');
    await flushQueue(); // prima manda i set

    if (queueGetAll().length === 0) {
        try {
            const res = await api('finish_workout', {
                workout_log_id: workoutLogId,
                notes: notes || '',
            });
            if (res && res.ok) {
                clearPendingFinish();
                markWorkoutDoneNow(workoutId);
                player = null;
                showSummary(res.data.summary);
                return;
            }
        } catch (e) {
            // offline: cade nel ramo differito qui sotto
        }
    }

    // Chiusura differita.
    player = null;
    toast('Sei offline: l\'allenamento verrà chiuso alla riconnessione.');
    renderHome();
    showScreen('home');
}

// Annulla l'allenamento: non salva nulla. Butta via i set non inviati,
// cancella la sessione sul server (con retry se offline) e torna alla home.
async function cancelSession() {
    if (!player) return;
    if (!confirm('Annullare l\'allenamento? Non verrà salvato nulla.')) return;

    const workoutLogId = player.workoutLogId;

    // Scarta subito i set di questa sessione ancora in coda.
    const kept = queueGetAll().filter((s) => s.workout_log_id !== workoutLogId);
    queueSave(kept);

    clearPendingFinish();               // non deve chiudersi come "finita"
    savePendingCancel(workoutLogId);    // se offline, si cancella dopo

    releaseWakeLock();
    stopRest();
    player = null;

    await tryCancelPending();

    renderHome();
    showScreen('home');
    toast('Allenamento annullato.');
}

// Aggiorna in memoria la data "ultima completata" della scheda appena finita,
// così il "giorni fa" in home è subito coerente senza ricaricare.
function markWorkoutDoneNow(workoutId) {
    if (!window.GHISA || !Array.isArray(window.GHISA.workouts)) return;
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const stamp = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} `
        + `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
    const w = window.GHISA.workouts.find((x) => Number(x.id) === Number(workoutId));
    if (w) w.last_finished = stamp;
}

function showSummary(summary) {
    if (!summaryView) {
        summaryView = document.createElement('section');
        summaryView.id = 'summary-view';
        summaryView.className = 'screen';
        $('#app-view').appendChild(summaryView);
    }
    summaryView.textContent = '';

    const h = document.createElement('h2');
    h.textContent = 'Allenamento salvato';
    summaryView.appendChild(h);

    const pre = document.createElement('pre');
    pre.className = 'summary';
    pre.textContent = summary || '';       // testo generato dal server
    summaryView.appendChild(pre);

    // Copia il riepilogo (con data e ora) per incollarlo in un'altra app.
    const copy = document.createElement('button');
    copy.className = 'big primary';
    copy.textContent = '📋 Copia riepilogo';
    copy.addEventListener('click', async () => {
        const ok = await copyText(summaryWithStamp(summary));
        if (ok) {
            tapBuzz();
            confirmTap(copy, '✓ Copiato');
        } else {
            // Ripiego finale: seleziono il testo, così lo copi a mano.
            try {
                const range = document.createRange();
                range.selectNodeContents(pre);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            } catch (e) {
                // niente selezione: resta comunque il testo a schermo
            }
            toast('Copia non riuscita: testo selezionato, copialo a mano.');
        }
    });
    summaryView.appendChild(copy);

    const back = document.createElement('button');
    back.className = 'big';
    back.textContent = 'Torna alla home';
    back.addEventListener('click', () => {
        renderHome();
        showScreen('home');
    });
    summaryView.appendChild(back);

    showScreen('summary');
}


// ===========================================================================
// Editor scheda (CRUD)
// ===========================================================================

let editState = null;

function ensureEditView() {
    if (!editView) {
        editView = document.createElement('section');
        editView.id = 'edit-view';
        editView.className = 'screen';
        $('#app-view').appendChild(editView);
    }
}

// Apre l'editor. Usa get_workout_edit, che NON apre una sessione di
// allenamento (get_workout invece sì: sarebbe un log fantasma a ogni modifica).
async function openEditor(workoutId) {
    ensureEditView();
    editState = { workoutId, loading: true, mode: 'list', showDeleted: false };
    renderEditor();
    showScreen('edit');

    try {
        const res = await api('get_workout_edit', { workout_id: workoutId });
        if (res.ok) {
            editState = {
                workoutId,
                workout: res.data.workout,
                exercises: res.data.exercises || [],
                deleted: res.data.deleted || [],
                mode: 'list',
                showDeleted: false,
            };
        } else {
            editState = { workoutId, error: res.error || 'Errore.', mode: 'list' };
        }
    } catch (e) {
        editState = {
            workoutId,
            error: 'Serve la rete per modificare la scheda.',
            mode: 'list',
        };
    }
    renderEditor();
}

function reloadEditor() {
    return openEditor(editState.workoutId);
}

function renderEditor() {
    editView.textContent = '';

    if (editState.loading) {
        const p = document.createElement('p');
        p.textContent = 'Carico…';
        editView.appendChild(p);
        return;
    }
    if (editState.error) {
        const p = document.createElement('p');
        p.className = 'error';
        p.textContent = editState.error;
        editView.appendChild(p);
        editView.appendChild(editBackButton());
        return;
    }
    if (editState.mode === 'form') {
        renderExerciseForm();
        return;
    }

    const h = document.createElement('h2');
    h.textContent = 'Modifica scheda';
    editView.appendChild(h);

    // Nome della scheda.
    const nameField = altField('Nome della scheda', 'text');
    nameField.input.value = editState.workout.name;
    editView.appendChild(nameField.label);

    const saveName = document.createElement('button');
    saveName.className = 'link';
    saveName.textContent = 'Salva nome';
    saveName.addEventListener('click', () => renameWorkout(nameField.input.value));
    editView.appendChild(saveName);

    // Esercizi.
    const t = document.createElement('h3');
    t.className = 'exlist-title';
    t.textContent = 'Esercizi';
    editView.appendChild(t);

    editState.exercises.forEach((ex, i) => {
        editView.appendChild(
            renderExerciseEditRow(ex, i, editState.exercises.length)
        );
    });

    const add = document.createElement('button');
    add.className = 'big primary';
    add.textContent = '+ Aggiungi esercizio';
    add.addEventListener('click', () => {
        editState.mode = 'form';
        editState.editing = null;
        renderEditor();
    });
    editView.appendChild(add);

    // Cestino esercizi.
    if (editState.deleted.length) {
        const tog = document.createElement('button');
        tog.className = 'link';
        tog.textContent = editState.showDeleted
            ? 'Nascondi eliminati'
            : `Mostra eliminati (${editState.deleted.length})`;
        tog.addEventListener('click', () => {
            editState.showDeleted = !editState.showDeleted;
            renderEditor();
        });
        editView.appendChild(tog);

        if (editState.showDeleted) {
            editState.deleted.forEach((ex) => {
                const row = document.createElement('div');
                row.className = 'edit-row deleted';
                const nm = document.createElement('span');
                nm.className = 'edit-name';
                nm.textContent = ex.name;
                row.appendChild(nm);
                row.appendChild(toolBtn('↩', false, () => restoreExercise(ex.id)));
                editView.appendChild(row);
            });
            const note = document.createElement('p');
            note.className = 'alt-for';
            note.textContent = 'Gli eliminati spariscono per sempre dopo 30 giorni.';
            editView.appendChild(note);
        }
    }

    editView.appendChild(editBackButton());
}

function editBackButton() {
    const back = document.createElement('button');
    back.className = 'link';
    back.textContent = '← Home';
    back.addEventListener('click', () => {
        renderHome();
        showScreen('home');
    });
    return back;
}

function renderExerciseEditRow(ex, i, total) {
    const row = document.createElement('div');
    row.className = 'edit-row';

    const name = document.createElement('button');
    name.className = 'edit-name';
    const line1 = document.createElement('span');
    line1.className = 'wk-name';
    line1.textContent = ex.name;
    name.appendChild(line1);
    const line2 = document.createElement('span');
    line2.className = 'wk-sub';
    const nAlt = (ex.alternatives || []).length;
    line2.textContent = (ex.target || '—') + ' · rec ' + ex.rest_seconds + 's'
        + (ex.url ? ' · link' : '')
        + (nAlt ? ' · ' + nAlt + ' alt' : '');
    name.appendChild(line2);
    name.addEventListener('click', () => {
        editState.mode = 'form';
        editState.editing = ex;
        renderEditor();
    });
    row.appendChild(name);

    const tools = document.createElement('div');
    tools.className = 'edit-tools';
    tools.appendChild(toolBtn('↑', i === 0, () => moveExercise(i, -1)));
    tools.appendChild(toolBtn('↓', i === total - 1, () => moveExercise(i, 1)));
    tools.appendChild(toolBtn('🗑', false, () => trashExercise(ex)));
    row.appendChild(tools);

    return row;
}

function renderExerciseForm() {
    const ex = editState.editing;

    const h = document.createElement('h2');
    h.textContent = ex ? 'Modifica esercizio' : 'Nuovo esercizio';
    editView.appendChild(h);

    const name = altField('Nome', 'text');
    const type = altSelect('Tipo', [
        ['reps', 'A ripetizioni'],
        ['time', 'A tempo (secondi)'],
    ], ex && ex.type === 'time' ? 'time' : 'reps');
    const sets = altField('Serie', 'number');
    const reps = altField('Ripetizioni', 'text');
    const rest = altField('Recupero (secondi)', 'number');
    const url = altField('Link (opzionale)', 'url');

    // Per gli esercizi a tempo il campo non sono ripetizioni ma secondi.
    const syncRepsCaption = () => {
        reps.caption.textContent = type.input.value === 'time'
            ? 'Secondi di tenuta' : 'Ripetizioni';
    };
    type.input.addEventListener('change', syncRepsCaption);
    syncRepsCaption();

    if (ex) {
        name.input.value = ex.name || '';
        sets.input.value = ex.target_sets != null ? ex.target_sets : '';
        reps.input.value = ex.target_reps != null ? ex.target_reps : '';
        rest.input.value = ex.rest_seconds != null ? ex.rest_seconds : '';
        url.input.value = ex.url || '';
    } else {
        rest.input.value = '90';
    }

    [name, type, sets, reps, rest, url].forEach((f) => editView.appendChild(f.label));

    const save = document.createElement('button');
    save.className = 'big primary';
    save.textContent = 'Salva';
    save.addEventListener('click', async () => {
        if (!name.input.value.trim()) {
            toast('Serve il nome.');
            return;
        }
        save.disabled = true;

        const params = {
            name: name.input.value.trim(),
            type: type.input.value,
            target_sets: sets.input.value.trim(),
            target_reps: reps.input.value.trim(),
            rest_seconds: rest.input.value.trim(),
            url: url.input.value.trim(),
        };
        if (ex) {
            params.exercise_id = ex.id;
        } else {
            params.workout_id = editState.workoutId;
        }

        try {
            const res = await api('save_exercise', params);
            if (res.ok) {
                toast('Salvato');
                await reloadEditor();
            } else {
                toast(res.error || 'Errore.');
                save.disabled = false;
            }
        } catch (e) {
            toast('Serve la rete.');
            save.disabled = false;
        }
    });
    editView.appendChild(save);

    // Pool delle alternative già usate al posto di questo esercizio.
    // delete_exercise funziona anche su di esse: qui c'è l'interfaccia.
    if (ex && ex.alternatives && ex.alternatives.length) {
        const at = document.createElement('h3');
        at.className = 'exlist-title';
        at.textContent = 'Alternative di questo esercizio';
        editView.appendChild(at);

        ex.alternatives.forEach((a) => {
            const row = document.createElement('div');
            row.className = 'edit-row';
            const nm = document.createElement('span');
            nm.className = 'edit-name';
            const l1 = document.createElement('span');
            l1.className = 'wk-name';
            l1.textContent = a.name;
            nm.appendChild(l1);
            const l2 = document.createElement('span');
            l2.className = 'wk-sub';
            l2.textContent = a.target || '';
            nm.appendChild(l2);
            row.appendChild(nm);
            row.appendChild(toolBtn('🗑', false, () => trashAlternative(a)));
            editView.appendChild(row);
        });

        const note = document.createElement('p');
        note.className = 'alt-for';
        note.textContent = 'Sono le sostituzioni già usate al posto di questo '
            + 'esercizio. Toglierne una la fa sparire dalle proposte.';
        editView.appendChild(note);
    }

    const cancel = document.createElement('button');
    cancel.className = 'link';
    cancel.textContent = 'Annulla';
    cancel.addEventListener('click', () => {
        editState.mode = 'list';
        editState.editing = null;
        renderEditor();
    });
    editView.appendChild(cancel);
}

async function renameWorkout(newName) {
    const name = (newName || '').trim();
    if (!name) {
        toast('Il nome non può essere vuoto.');
        return;
    }
    try {
        const res = await api('save_workout', {
            workout_id: editState.workoutId,
            name,
        });
        if (res.ok) {
            applyWorkoutsPayload(res.data);
            editState.workout.name = name;
            toast('Nome aggiornato');
        } else {
            toast(res.error || 'Errore.');
        }
    } catch (e) {
        toast('Serve la rete.');
    }
}

async function moveExercise(i, dir) {
    const list = editState.exercises.slice();
    const j = i + dir;
    if (j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j], list[i]];

    try {
        const res = await api('reorder', {
            type: 'exercise',
            ids: list.map((e) => e.id).join(','),
        });
        if (res.ok) {
            editState.exercises = list;
            renderEditor();
        } else {
            toast(res.error || 'Errore.');
        }
    } catch (e) {
        toast('Serve la rete.');
    }
}

async function trashExercise(ex) {
    const ok = confirm(`Eliminare «${ex.name}»?\n`
        + 'Resta nel cestino 30 giorni. Lo storico non viene toccato.');
    if (!ok) return;
    try {
        const res = await api('delete_exercise', { exercise_id: ex.id });
        if (res.ok) {
            toast('Spostato nel cestino');
            await reloadEditor();
        } else {
            toast(res.error || 'Errore.');
        }
    } catch (e) {
        toast('Serve la rete.');
    }
}

async function trashAlternative(a) {
    if (!confirm(`Togliere «${a.name}» dalle alternative?`)) return;
    try {
        const res = await api('delete_exercise', { exercise_id: a.id });
        if (res.ok) {
            toast('Alternativa rimossa');
            await reloadEditor();
        } else {
            toast(res.error || 'Errore.');
        }
    } catch (e) {
        toast('Serve la rete.');
    }
}

async function restoreExercise(id) {
    try {
        const res = await api('delete_exercise', { exercise_id: id, restore: '1' });
        if (res.ok) {
            toast('Ripristinato');
            await reloadEditor();
        } else {
            toast(res.error || 'Errore.');
        }
    } catch (e) {
        toast('Serve la rete.');
    }
}


// ===========================================================================
// Storico
// ===========================================================================

async function openHistory() {
    els.history.textContent = '';
    const h = document.createElement('h2');
    h.textContent = 'Storico';
    els.history.appendChild(h);

    const back = document.createElement('button');
    back.className = 'link';
    back.textContent = '← Home';
    back.addEventListener('click', () => showScreen('home'));
    els.history.appendChild(back);

    showScreen('history');

    let res;
    try {
        res = await api('get_history', { limit: 30, offset: 0 });
    } catch (e) {
        const p = document.createElement('p');
        p.textContent = 'Serve la rete per vedere lo storico.';
        els.history.appendChild(p);
        return;
    }
    if (!res.ok) {
        toast(res.error || 'Errore nel caricare lo storico.');
        return;
    }

    const sessions = res.data.sessions || [];
    if (sessions.length === 0) {
        const p = document.createElement('p');
        p.textContent = 'Ancora nessun allenamento registrato.';
        els.history.appendChild(p);
        return;
    }

    sessions.forEach((s) => els.history.appendChild(renderSession(s)));
}

function renderSession(s) {
    const card = document.createElement('div');
    card.className = 'session';

    const head = document.createElement('div');
    head.className = 'session-head';
    const day = (s.started_at || '').slice(0, 10);
    head.textContent = `${s.workout_name || 'Scheda'} — ${day}`;
    card.appendChild(head);

    (s.sets || []).forEach((set) => {
        const row = document.createElement('div');
        row.className = 'set-row';
        const reps = (set.reps_completed === null || set.reps_completed === '')
            ? '—' : set.reps_completed;
        const noWeight = set.weight_kg === null || set.weight_kg === '';
        row.textContent = noWeight
            ? `${set.exercise_name}: ${reps}`
            : `${set.exercise_name}: ${fmtWeight(set.weight_kg)}kg × ${reps}`;
        card.appendChild(row);
    });

    if (s.notes) {
        const notes = document.createElement('div');
        notes.className = 'session-notes';
        notes.textContent = s.notes;
        card.appendChild(notes);
    }

    return card;
}


// ===========================================================================
// Wake Lock (CLAUDE.md punto 7)
// ===========================================================================

async function acquireWakeLock() {
    // Non supportato (Safari < 16.4, o contesto non sicuro): si prosegue in
    // silenzio. Avvisare a ogni allenamento di una cosa non risolvibile
    // sarebbe solo rumore.
    if (!('wakeLock' in navigator)) {
        return;
    }
    try {
        wakeLock = await navigator.wakeLock.request('screen');
        wakeLock.addEventListener('release', () => { wakeLock = null; });
    } catch (e) {
        // Permesso negato o non-HTTPS: si prosegue senza crashare.
    }
}

function releaseWakeLock() {
    if (wakeLock) {
        wakeLock.release().catch(() => {});
        wakeLock = null;
    }
}

// Il lock si perde quando la tab va in background: va ri-richiesto.
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && player && !wakeLock) {
        acquireWakeLock();
    }
});


// ===========================================================================
// Service worker
// ===========================================================================

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js').catch(() => {});
    });
}


// ===========================================================================
// Avvio
// ===========================================================================

function init() {
    updateQueueIndicator();

    if (els.loginForm) els.loginForm.addEventListener('submit', handleLogin);
    if (els.logoutBtn) {
        els.logoutBtn.addEventListener('click', () => {
            location.href = 'index.php?logout=1';
        });
    }

    // Riprova a svuotare la coda quando torna la rete.
    window.addEventListener('online', flushQueue);

    if (window.GHISA && window.GHISA.loggedIn) {
        showApp(true);
        renderHome();
        showScreen('home');
        // Alla ripresa, prova a smaltire code e chiusure rimaste in sospeso.
        flushQueue();
    } else {
        showApp(false);
    }
}

init();
