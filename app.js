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

// Chiavi localStorage.
const K_QUEUE = 'GHISA_QUEUE';                 // array di set in attesa di invio
const K_PENDING_FINISH = 'GHISA_PENDING_FINISH'; // chiusura sessione differita
const K_PENDING_CANCEL = 'GHISA_PENDING_CANCEL'; // annullamento sessione differito


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

// Le schermate riepilogo e fine le crea app.js (index.php non le prevede).
let summaryView = null;
let finishView = null;


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
            window.GHISA.workouts = res.data.workouts || [];
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
    h.textContent = 'Le tue schede';
    els.home.appendChild(h);

    if (workouts.length === 0) {
        const p = document.createElement('p');
        p.textContent = 'Nessuna scheda. Creane una in phpMyAdmin.';
        els.home.appendChild(p);
    }

    workouts.forEach((w) => {
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
    });

    const histBtn = document.createElement('button');
    histBtn.className = 'link';
    histBtn.textContent = 'Storico allenamenti';
    histBtn.addEventListener('click', openHistory);
    els.home.appendChild(histBtn);
}


// ===========================================================================
// Player
// ===========================================================================

async function startWorkout(workoutId) {
    let res;
    try {
        res = await api('get_workout', { workout_id: workoutId });
    } catch (e) {
        toast('Serve la rete per iniziare un allenamento.');
        return;
    }
    if (!res.ok) {
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
    };

    buildPlayerSkeleton();
    renderCurrentSet();
    showScreen('player');
    acquireWakeLock();
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
            <div class="p-set" id="p-set"></div>
            <div class="p-last" id="p-last" hidden></div>

            <label class="p-field">
                Peso (kg)
                <input id="p-weight" type="number" inputmode="decimal"
                       step="0.5" min="0">
            </label>
            <label class="p-field">
                Ripetizioni
                <input id="p-reps" type="number" inputmode="numeric"
                       step="1" min="0">
            </label>

            <button id="p-next" class="big primary">Avanti</button>

            <div class="p-extra">
                <button id="p-add-set" class="link" type="button">+ Serie</button>
                <button id="p-skip" class="link" type="button">Salta esercizio</button>
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
    renderLastSets(ex);

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

    // Precompilazione reps: il target se è un numero pulito, altrimenti vuoto.
    const repsInput = $('#p-reps');
    repsInput.value = /^\d+$/.test(ex.target_reps || '') ? ex.target_reps : '';

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

// "+ Serie": aggiunge una serie all'esercizio corrente, senza toccare la scheda.
function addExtraSet() {
    const ex = currentExercise();
    ex._extra = (ex._extra || 0) + 1;
    renderCurrentSet();
    toast('Serie aggiunta');
}

// "Salta esercizio": passa al prossimo senza registrare nulla per questo.
function skipExercise() {
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
        return fmtWeight(s.weight_kg) + '×' + reps;
    });
    box.textContent = 'Ultima volta: ' + parts.join(', ');
    box.hidden = false;
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
        row.textContent =
            `${set.exercise_name}: ${fmtWeight(set.weight_kg)}kg × ${reps}`;
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
    if (!('wakeLock' in navigator)) {
        toast('Schermo sempre acceso non supportato su questo browser.');
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
