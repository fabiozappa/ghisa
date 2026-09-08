# CLAUDE.md — Web App Schede Allenamento

Istruzioni per lavorare su questo progetto. Leggi tutto prima di scrivere codice.

---

## 1. Cos'è

**Ghisa** — PWA per seguire le proprie schede di allenamento in palestra, dal telefono.
Filosofia KISS: nessuna email, nessun tracciamento, nessuna dipendenza esterna.

**Stato: v1 completa e in uso reale, utente singolo.**
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

Tutti i file stanno nella **root del progetto** (nessuna sottocartella, a parte le icone).

```
index.php          login + guardia di sessione + logout + shell dell'app
api.php            front controller: switch su $_POST['action'], risponde SEMPRE JSON
db.php             connessione PDO + helper di query
auth.php           sessione, login, logout, requireLogin()
config.php         credenziali DB — NON versionato (.gitignore), uno per ambiente
schema.sql         schema completo del database
app.js             player, coda offline, wake lock, alternative, editor, chiamate API
style.css
sw.js              service worker (solo cache asset statici)
manifest.json
/icons/            icone PWA
```

**Non creare altri file senza chiedere.** Questa struttura è deliberatamente piatta.
`seed.php` è esistito per creare il primo utente ed è stato cancellato dopo l'uso,
come previsto: per creare un utente su un ambiente nuovo si importa il DB.

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

Il valore proposto è quello dell'**ultima serie eseguita**, non della più pesante: è una
scelta voluta, non un bug. Il quadro completo lo dà la riga "Ultima volta" nel player,
che elenca tutte le serie della sessione precedente.

### Slot e alternative

Una riga della scheda è uno **slot**: un esercizio *titolare* più il pool delle alternative
già usate al suo posto.

- `exercises.alternative_of` è un auto-collegamento: se valorizzato, quella riga è
  un'alternativa dello slot il cui titolare è l'esercizio puntato.
- I titolari hanno `alternative_of IS NULL`. **Ogni query che elenca una scheda deve
  filtrarlo**, altrimenti le alternative compaiono come esercizi veri.
- Rendere definitiva un'alternativa (`promote_alternative`) **scambia i ruoli**: l'alternativa
  diventa titolare ed eredita la `position`, il vecchio titolare scala nel pool, e le altre
  alternative vengono ripuntate al nuovo titolare.
- Anche le sostituzioni "una tantum" restano nel pool: è ciò che alimenta le proposte future.

### Cestino a 30 giorni

Schede ed esercizi si cancellano in **soft delete** (`deleted_at`). Dopo 30 giorni la riga
sparisce davvero, via `purge_expired()`, chiamata al login e all'apertura dell'editor:
**niente cron**, pulizia pigra.

La cancellazione definitiva non intacca lo storico, per costruzione: FK in `SET NULL`,
nome e target denormalizzati dentro il log, e fallback per nome nel lookup dell'ultimo peso.

### Altro

- La colonna `share_code` esiste già in `workouts`, ma **non ha nessuna UI**. Non implementarla.
- `exercises.rest_seconds` c'è ed è usato: il timer di recupero è una funzione di v1.
- `exercises.url` è un link esplicativo (immagine, video, pagina). Accetta **solo http/https**:
  va validato sia lato server sia prima di diventare un `href`.
- `exercises.type` ENUM `('reps','time')`: **entrambi i rami sono implementati**. Per gli
  esercizi a tempo i secondi di tenuta stanno in `target_reps` (nessuna colonna in più) e
  l'unità finisce nel `target` denormalizzato (`3x45s`), così anche il log resta leggibile.
  I secondi effettivi si registrano in `reps_completed`; il recupero resta `rest_seconds`.
- `position` ordina sia le schede sia gli esercizi (riordino con frecce, non drag&drop).
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

**Le action sono 16. Non aggiungerne altre senza chiedere.**

*Sessione e allenamento*

| action | input | output |
|---|---|---|
| `login` | `word1`, `word2` | utente + schede (vive e in cestino) |
| `get_workout` | `workout_id` | **apre una sessione** e ritorna scheda, esercizi, ultimo peso e serie dell'ultima volta |
| `log_set` | `client_uid`, `workout_log_id`, `exercise_id`, `set_number`, `weight_kg`, `reps_completed` | esito (idempotente) |
| `delete_set` | `client_uid` | annulla una serie della **sessione in corso** (idempotente; su sessione chiusa non fa nulla) |
| `finish_workout` | `workout_log_id`, `notes` | riepilogo testuale generato, **nota inclusa** (finisce anche nel testo copiato) |
| `cancel_workout` | `workout_log_id` | scarta la sessione: cancellazione **fisica** di log e serie |
| `get_history` | `limit`, `offset` | lista sessioni con dettaglio |

*Alternative*

| action | input | output |
|---|---|---|
| `get_alternatives` | `exercise_id` (il titolare) | pool dello slot |
| `add_alternative` | `primary_exercise_id`, `name`, `target_sets`, `target_reps`, `rest_seconds`, `url` | l'alternativa creata |
| `promote_alternative` | `alternative_exercise_id` | scambio dei ruoli nello slot |

*CRUD schede*

| action | input | output |
|---|---|---|
| `get_workout_edit` | `workout_id` | scheda + esercizi **senza aprire una sessione** |
| `save_workout` | `workout_id` (opz.), `name` | crea o rinomina + elenco schede |
| `delete_workout` | `workout_id`, `restore` | cestino o ripristino + elenco schede |
| `save_exercise` | `exercise_id` (opz.) **oppure** `workout_id`, `name`, `target_sets`, `target_reps`, `rest_seconds`, `url` | crea o modifica |
| `delete_exercise` | `exercise_id`, `restore` | soft delete o ripristino |
| `reorder` | `type` (`workout`\|`exercise`), `ids` ordinati e separati da virgola | riscrive le `position` |

⚠️ **`get_workout` apre una sessione a ogni chiamata.** Per leggere una scheda senza
registrare un allenamento esiste `get_workout_edit`. Non confonderli, o l'editor
genererebbe un log fantasma a ogni apertura.

Il **logout non è un'action**: è gestito da `index.php?logout=1`, perché la sessione è
roba della shell, non un dato dell'app.

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
**Va richiesto dentro il gesto utente**, non dopo un `await`: Safari rifiuta la richiesta
se l'attivazione è già scaduta.
**Va ri-richiesto su `visibilitychange`**, perché il lock si perde quando la tab va in background.
Serve HTTPS: su `http://` l'API non esiste proprio. Se manca, si prosegue **in silenzio** —
avvisare a ogni allenamento di una cosa non risolvibile è solo rumore.

### Service worker

Cache dei soli asset statici (`style.css`, `app.js`, icone, `manifest.json`).
**Mai cachare le risposte di `api.php`.** Versiona il nome della cache e pulisci le vecchie in `activate`.

### Interfaccia

Tasti grandi, alto contrasto, pensati per mani sudate. L'input del peso è `type="number"`
con `inputmode="decimal"` e `step="0.5"`. Il target di tap minimo è 48px.

Gli esercizi a tempo hanno un **timer di lavoro**: un tap avvia la tenuta, un secondo tap
la ferma prima, e in entrambi i casi i secondi effettivi finiscono nel campo. La serie si
registra poi con "Avanti" come tutte le altre, quindi coda offline e idempotenza valgono
identiche.

Ogni azione che **scrive dati** (Avanti, Salta esercizio) si blocca ~1,2s dopo il tap e
mostra una conferma verde. Non è vezzo estetico: un doppio tap accidentale creerebbe una
serie fantasma nello storico, e l'idempotenza su `client_uid` protegge dai reinvii di
rete, non da due tap umani.

---

## 8. Fuori scope

Non implementare, non proporre, non "predisporre" con codice morto:

- Registrazione automatica di nuovi utenti dal login
- Share code, import e clonazione schede
- Vista a calendario (lo storico è una lista cronologica inversa)
- Grafici, statistiche, PR, badge, gamification

Le colonne DB che servono a queste funzioni ci sono già. Basta quello.

> **CRUD delle schede**, **alternative agli esercizi** ed **esercizi a tempo** erano fuori
> scope in v1: sono stati implementati dopo, su richiesta esplicita e in quest'ordine —
> prima le alternative (nate da un problema emerso in palestra), poi il CRUD, infine il
> ramo `time` insieme all'annulla-serie e alla gestione del pool alternative.

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

## 11. Ambienti

### Locale (sviluppo)

Stack Homebrew su macOS Apple Silicon: httpd + php 8 + MariaDB.
Vhost: **`local.g3.fabiozappa.it`**, database **`ghisa`**. Editor: Nova.
Per una prova rapida va bene anche `php -S 127.0.0.1:8765` dalla root del progetto.

### Produzione

**`https://ghisa.fabiozappa.it`**, aggiornata a mano **via FTP**. Il passaggio a git è
previsto ma non ancora fatto.

### Regole di deploy — leggile prima di toccare il server

1. ⚠️ **Non eseguire mai `schema.sql` in produzione.** Ha i `DROP TABLE` in testa:
   cancellerebbe tutto lo storico. Serve solo a ricreare il DB da zero in locale.
2. Le modifiche di schema si portano in produzione con **`ALTER TABLE` scritte a mano**.
   ⚠️ In locale c'è **MariaDB**, in produzione **MySQL**: `ADD COLUMN IF NOT EXISTS` è
   un'estensione MariaDB e sul server risponde `#1064`. Quindi si verifica prima con
   `SHOW COLUMNS FROM <tabella>;` e poi si lancia la ALTER semplice, che non è
   rilanciabile (`#1060` se la colonna c'è già). Ogni volta che cambia `schema.sql`,
   serve la ALTER corrispondente.
3. **Non sovrascrivere `config.php`**: il server ha il suo, con credenziali diverse.
4. Se cambi `sw.js`, **cambia anche il nome della cache**, altrimenti i telefoni continuano
   a servire la versione vecchia.
5. `display_errors = Off`. Gli errori si loggano, non si stampano.
6. Lo storico vive lì: verifica che ci sia un **backup del database**.
