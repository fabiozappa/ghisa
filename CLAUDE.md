# CLAUDE.md — Web App Schede Allenamento

Istruzioni per lavorare su questo progetto. Leggi tutto prima di scrivere codice.

---

## 1. Cos'è

PWA per seguire le proprie schede di allenamento in palestra, dal telefono.
Filosofia KISS: nessuna email, nessun tracciamento, nessuna dipendenza esterna.

**Versione attuale: v1, uso personale, utente singolo.**
L'apertura ad altri utenti verrà dopo. Lo schema DB è già multi-utente, l'interfaccia no.

Il valore dell'app sta nello **storico dei carichi**: è l'unico dato non ricostruibile.
Ogni decisione di design deve proteggere quello storico.

---

## 2. Stack e vincoli non negoziabili

- PHP 8.x puro. **Nessun framework, nessun Composer, nessuna libreria di terze parti.**
- MariaDB (in locale via Homebrew).
- JavaScript vanilla ES6+. **Nessun build step, nessun npm, nessun bundler.** I file `.js` vanno nel browser così come sono.
- HTML5 + CSS3 scritti a mano. Niente Tailwind, niente framework CSS.
- Mobile-first. Il desktop non è un target.

Se pensi che serva una dipendenza esterna, **fermati e chiedi**. La risposta è quasi sempre no.

---

## 3. Struttura dei file

```
/allenamenti/
  index.php          login + guardia di sessione + shell dell'app
  api.php            front controller: switch su $_POST['action'], risponde SEMPRE JSON
  db.php             connessione PDO + helper
  auth.php           sessione, login, logout, requireLogin()
  seed.php           script una tantum: crea utente e scheda iniziale (da cancellare dopo)
  schema.sql         schema completo del database
  app.js             player, coda offline, wake lock, chiamate API
  style.css
  sw.js              service worker (solo cache asset statici)
  manifest.json
  /icons/            icone PWA
```

**Non creare altri file senza chiedere.** Questa struttura è deliberatamente piatta.

---

## 4. Schema del database

Fonte di verità: `schema.sql`. Regole che lo governano:

### I log sono immutabili e autosufficienti

`workout_logs` e `exercise_logs` sono **fatti storici**. Non devono mai dipendere
dall'anagrafica corrente. Perciò:

- Le FK verso `workouts` ed `exercises` sono `ON DELETE SET NULL` e le colonne sono NULLABLE.
- **Mai `ON DELETE CASCADE` verso una tabella di log.**
- Nome scheda, nome esercizio e target sono **denormalizzati dentro il log** al momento
  della scrittura. Se domani cancello l'esercizio "Panca Piana", il log deve continuare a
  dire "Panca Piana, 3x10 @ 70kg".
- Gli esercizi si cancellano in soft delete (`deleted_at`), mai con DELETE fisico.

### Idempotenza dei set

`exercise_logs` ha una colonna `client_uid VARCHAR(36)` con **vincolo UNIQUE**.
Il client genera l'UID prima di inviare. Se la coda offline rispedisce lo stesso set,
l'INSERT fallisce sul vincolo e il server risponde comunque OK.
Senza questo, ogni riconnessione duplica le serie.

### Lookup dell'ultimo peso

Il precompilato del peso si cerca **prima per `exercise_id`, poi in fallback per nome
esercizio normalizzato** (LOWER + TRIM) sull'utente corrente. Serve perché importando o
ricreando una scheda gli ID cambiano, ma "Panca Piana" resta "Panca Piana".

### Altro

- La colonna `share_code` esiste già in `workouts`, ma in v1 **non ha nessuna UI**. Non implementarla.
- `exercises.rest_seconds` c'è ed è usato: il timer di recupero è una funzione di v1.
- `exercises.type` ha l'ENUM `('reps','time')`, ma in v1 **implementa solo il ramo `reps`**.
- Indice su `workout_logs(user_id, started_at)` per lo storico.

---

## 5. Regole di sicurezza

Non sono suggerimenti.

1. **Sempre PDO con prepared statement.** Mai concatenazione di variabili in SQL, in nessun
   caso, nemmeno per gli interi, nemmeno "tanto è un ID interno".
2. PDO configurato con `ERRMODE_EXCEPTION` e `ATTR_EMULATE_PREPARES => false`.
3. Password con `password_hash()` / `password_verify()`. Mai md5, mai sha1, mai sale fatto a mano.
4. Cookie di sessione: `HttpOnly`, `Secure`, `SameSite=Strict`. Impostati con
   `session_set_cookie_params()` **prima** di `session_start()`.
5. `session_regenerate_id(true)` al login.
6. Ogni action di `api.php` chiama `requireLogin()` come prima istruzione. **Unica eccezione: `login`.**
7. Ogni query filtra su `user_id` preso **dalla sessione**, mai da un parametro della richiesta.
   Un ID che arriva dal client non autorizza mai niente.
8. Output HTML sempre attraverso `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')`.
9. In produzione `display_errors = Off`. Gli errori si loggano, non si stampano.
10. Nessuna chiamata a domini esterni. Nessun CDN, nessun font remoto, nessun analytics.

---

## 6. Contratto dell'API

`api.php` è l'unico endpoint. Riceve POST, legge `$_POST['action']`, risponde JSON.

**Risposta sempre in questa forma:**

```json
{ "ok": true,  "data": { } }
{ "ok": false, "error": "messaggio leggibile" }
```

Header `Content-Type: application/json; charset=utf-8`. Codice HTTP 200 anche sugli errori
applicativi (il client legge `ok`); 401 solo per sessione scaduta, così il client sa che deve
rimandare al login.

**Le action di v1 sono cinque. Non aggiungerne altre senza chiedere:**

| action | input | output |
|---|---|---|
| `login` | `word1`, `word2` | esito |
| `get_workout` | `workout_id` | scheda + esercizi + ultimo peso per esercizio |
| `log_set` | `client_uid`, `workout_log_id`, `exercise_id`, `set_number`, `weight_kg`, `reps_completed` | esito |
| `finish_workout` | `workout_log_id`, `notes` | riepilogo testuale generato |
| `get_history` | `limit`, `offset` | lista sessioni con dettaglio |

Il CRUD delle schede in v1 **non esiste**: si fa in phpMyAdmin o via `seed.php`.

---

## 7. Comportamento del client

### Coda offline (obbligatoria)

La palestra è un seminterrato senza campo. Il player **non deve mai dipendere dalla rete**.

1. Al tap su "Avanti" il set viene scritto **subito in `localStorage`** con un `client_uid` generato al volo, e la UI avanza immediatamente. Nessuno spinner, nessuna attesa.
2. Un worker in background prova a inviare la coda; a ogni successo rimuove l'elemento.
3. Riprova all'evento `online` e a fine allenamento.
4. Un indicatore discreto mostra quanti set sono ancora in coda.

Se le specifiche dicono "nessun dato salvato in locale", si riferiscono al tracciamento:
questi sono dati dell'utente sul dispositivo dell'utente, e vanno salvati.

### Wake Lock

`navigator.wakeLock.request('screen')` all'avvio del player.
**Va ri-richiesto su `visibilitychange`**, perché il lock si perde quando la tab va in background.
Serve HTTPS. Se l'API non c'è (Safari < 16.4), mostra un avviso e prosegui senza crashare.

### Service worker

Cache dei soli asset statici (`style.css`, `app.js`, icone, `manifest.json`).
**Mai cachare le risposte di `api.php`.** Versiona il nome della cache e pulisci le vecchie in `activate`.

### Interfaccia

Tasti grandi, alto contrasto, pensati per mani sudate. L'input del peso è `type="number"`
con `inputmode="decimal"` e `step="0.5"`. Il target di tap minimo è 48px.

---

## 8. Fuori scope in v1

Non implementare, non proporre, non "predisporre" con codice morto:

- Registrazione automatica di nuovi utenti dal login
- Share code, import e clonazione schede
- CRUD delle schede da interfaccia
- Vista a calendario (lo storico è una lista cronologica inversa)
- Esercizi a tempo e countdown
- Grafici, statistiche, PR, badge, gamification

Le colonne DB che servono a queste funzioni ci sono già. Basta quello.

---

## 9. Stile del codice

- Nomi di variabili e funzioni in inglese, commenti in italiano.
- Funzioni piatte e leggibili. Niente OOP se non serve davvero: `db.php` e `auth.php` possono
  essere raccolte di funzioni.
- Indentazione 4 spazi. Parentesi graffe sulla stessa riga.
- Niente `?>` di chiusura nei file solo-PHP.
- Codice esplicito e noioso è preferibile a codice compatto e furbo. Questo progetto va
  riletto tra due anni.

---

## 10. Come lavorare su questo progetto

- **Un passo alla volta.** Mostra il codice di un file, aspetta conferma, poi passa al successivo.
- **Diff piccoli.** Non riscrivere file interi per cambiare tre righe.
- **Niente refactoring non richiesto.** Se noti qualcosa da migliorare, dillo a parole e aspetta.
- **Niente file nuovi senza chiedere.** Vedi la struttura al punto 3.
- Se una richiesta è ambigua o le specifiche si contraddicono, **chiedi prima di scrivere**.
- Alla fine di ogni passo, elenca in due righe cosa va provato a mano per verificare che funzioni.

---

## 11. Ambiente locale

Stack Homebrew su macOS Apple Silicon: httpd + php 8 + MariaDB.
Vhost locale per hostname, convenzione già in uso: `local.allenamenti.fabiozappa.it`.
Editor: Nova.

> Verifica e correggi hostname e percorsi alla prima sessione: qui sono un'ipotesi.
