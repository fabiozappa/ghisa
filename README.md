# Ghisa

PWA per seguire le proprie schede di allenamento in palestra, dal telefono.

Il valore dell'app sta nello **storico dei carichi**: è l'unico dato non ricostruibile,
e ogni scelta di progetto serve a proteggerlo.

Filosofia KISS: nessuna email, nessun tracciamento, nessuna dipendenza esterna.

---

## Cosa fa

- **Player offline-first.** In palestra spesso non c'è campo: ogni serie viene scritta
  subito in `localStorage` e la schermata avanza senza attese. Un worker in background
  svuota la coda quando la rete torna. Le serie non si perdono e non si duplicano
  (idempotenza su un identificativo generato dal client).
- **Storico dei carichi.** Il peso viene precompilato con l'ultimo usato, e il player
  mostra le serie dell'ultima volta che hai fatto quell'esercizio: sai cosa devi battere.
- **Alternative.** Se una macchina è occupata, sostituisci l'esercizio al volo. L'app
  ricorda le alternative già usate e te le ripropone; a fine allenamento decidi se quella
  sostituzione diventa definitiva.
- **Esercizi a ripetizioni e a tempo**, con timer di recupero e timer di lavoro.
- **Gestione schede** completa: creazione, modifica, riordino, cestino a 30 giorni.
- **Condivisione.** Attivandola ottieni un link pubblico di sola lettura; chi lo riceve può
  importare la scheda nel proprio account.
- **PWA installabile**, con schermo sempre acceso durante l'allenamento.
- **Multi-utente** con registrazione libera.

---

## Requisiti

- **PHP 8.x** con PDO MySQL
- **MySQL o MariaDB**
- **HTTPS** — non è un optional: senza, il browser disattiva service worker, Wake Lock e
  accesso agli appunti. In locale `localhost` e `127.0.0.1` valgono come contesto sicuro.

Nessuna dipendenza da installare: niente Composer, niente npm, niente framework.
I file vanno sul server e funzionano.

---

## Installazione

### 1. Scarica il codice

Clona il repository (o copia i file) nella document root del sito. La struttura è
volutamente piatta.

Se cloni direttamente nella document root, verifica che il web server **blocchi l'accesso
a `.git/`**: altrimenti la cartella si scarica dal browser.

### 2. Crea il database

```sql
CREATE DATABASE ghisa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. Importa lo schema

```bash
mysql -u UTENTE -p ghisa < schema.sql
```

> ⚠️ **`schema.sql` comincia con dei `DROP TABLE`.** Serve a creare il database da zero, ed
> è comodo in sviluppo perché si può rilanciare. **Su un'installazione con dati dentro
> cancella tutto.** Vedi *Aggiornare un'installazione esistente*.

### 4. Crea `config.php`

Copia l'esempio e mettici le credenziali vere:

```bash
cp config-example.php config.php
```

```php
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'ghisa',
    'db_user' => 'utente',
    'db_pass' => 'password',
];
```

`config.php` **non è versionato** (`.gitignore`): ogni ambiente ha il suo, e le credenziali
non finiscono mai nel repository.

### 5. Crea il primo utente

Apri il sito e usa **"Non hai un account? Registrati"**. Non servono script: la
registrazione è aperta e il primo account si crea dall'interfaccia.

Le credenziali sono **nome utente + due parole**. Non viene chiesta l'email, e quindi
**non esiste un recupero password**: se le perdi, l'account non è più raggiungibile da
nessuno. Annotale.

---

## Prima di aprirlo al pubblico

Termini d'uso e informativa privacy descrivono il comportamento reale del codice, ma
**alcuni campi sono lasciati vuoti di proposito**, segnati con *da indicare* /
*to be provided*. Chi pubblica un'istanza usata da altri **deve compilarli**: il titolare
del trattamento è chi gestisce il servizio, non l'autore del codice.

| Campo | File |
|---|---|
| Titolare del trattamento | `privacy-it.html`, `privacy-en.html` |
| Indirizzo di contatto | tutti e quattro i documenti |
| Durata di conservazione dei backup | `privacy-it.html`, `privacy-en.html` |

Per trovarli tutti:

```bash
grep -n "da indicare\|to be provided" terms-*.html privacy-*.html
```

La durata dei backup dipende dall'hosting: è l'unico punto in cui la cancellazione di un
account non è istantanea, e va dichiarata per quello che è davvero.

Se modifichi il comportamento dell'app, rileggi anche quei documenti: citano i 30 giorni
del cestino, i 15 minuti dei tentativi di accesso e l'assenza di chiamate a servizi esterni.

---

## Sviluppo in locale

Per una prova rapida basta il server integrato di PHP, dalla cartella del progetto:

```bash
php -S 127.0.0.1:8765
```

Poi apri <http://127.0.0.1:8765>. Va bene anche un vhost Apache/nginx.

Per ripartire da zero con il database, rilancia `schema.sql` e registra di nuovo l'utente.

---

## Aggiornare un'installazione esistente

1. ⚠️ **Non eseguire mai `schema.sql`** su un'installazione con dati: cancellerebbe tutto.
2. Le modifiche di schema si portano avanti con **`ALTER TABLE` scritte a mano**.
   Attenzione: `ADD COLUMN IF NOT EXISTS` è un'estensione **MariaDB** e su **MySQL** dà
   errore `#1064`. Quindi prima si verifica con `SHOW COLUMNS FROM tabella;` e poi si lancia
   la `ALTER` semplice.
3. **Non sovrascrivere `config.php`**: sul server ce n'è uno con credenziali diverse.
   Con `git pull` non succede, perché non è versionato; copiando i file a mano sì.
4. Gli asset si invalidano da soli: `index.php` aggiunge a `style.css` e `app.js` un
   `?v=<data del file>`, e il service worker usa una strategia rete-per-prima. Non c'è
   nessun numero di versione da aggiornare a mano.

---

## Struttura dei file

| File | Ruolo |
|---|---|
| `index.php` | accesso, registrazione, logout e contenitore dell'app |
| `api.php` | unico endpoint: riceve POST, risponde sempre JSON |
| `db.php` | connessione PDO e helper di query |
| `auth.php` | sessione, credenziali, freno ai tentativi di accesso |
| `lang.php` | lingua della richiesta e funzione di traduzione `tr()` |
| `lang-it.php`, `lang-en.php` | testi dell'interfaccia, un file per lingua |
| `config.php` | credenziali del database — **non versionato** |
| `config-example.php` | modello da copiare |
| `schema.sql` | schema completo del database |
| `app.js` | player, coda offline, editor, chiamate all'API |
| `style.css` | tutto lo stile, scritto a mano |
| `public_workout.php` | pagina pubblica di una scheda condivisa, senza login |
| `sw.js` | service worker (solo asset statici) |
| `manifest.json`, `icons/` | installazione come app |
| `terms-*.html`, `privacy-*.html` | termini d'uso e informativa privacy |
| `CLAUDE.md` | regole di progetto e decisioni prese, con le ragioni |

Il database ha sei tabelle: `users`, `workouts`, `exercises`, `workout_logs`,
`exercise_logs`, `login_attempts`.

---

## Come funziona, in breve

**Un solo endpoint.** `api.php` legge `$_POST['action']` e risponde sempre nella forma
`{"ok": true, "data": …}` oppure `{"ok": false, "error": "…"}`. HTTP 200 anche sugli errori
applicativi; 401 solo a sessione scaduta.

**I log sono fatti storici.** `workout_logs` ed `exercise_logs` non dipendono
dall'anagrafica: nome scheda, nome esercizio e obiettivo sono copiati dentro il log al
momento della scrittura, e le chiavi esterne verso schede ed esercizi sono in
`ON DELETE SET NULL`. Se cancelli "Panca Piana", lo storico continua a dire "Panca Piana".

**Il cestino.** Schede ed esercizi cancellati restano recuperabili 30 giorni, poi
spariscono davvero. La pulizia è pigra, all'accesso: niente cron.

---

## Sicurezza

- Tutte le query passano da **prepared statement**, senza eccezioni.
- Password con `password_hash()` / `password_verify()`; le due parole non vengono salvate.
- Cookie di sessione `HttpOnly`, `SameSite=Strict`, `Secure` dietro HTTPS.
- Ogni azione richiede l'accesso (tranne registrazione e login) e **ogni scrittura verifica
  la proprietà della riga**: un id che arriva dal client non autorizza mai niente.
- Freno progressivo sui tentativi di accesso falliti, con un tetto: uno `sleep` senza
  limite sarebbe a sua volta un modo per mettere giù il server.
- Nessuna chiamata a domini esterni: niente CDN, niente font remoti, niente analytics.

---

## Documenti

- [Termini d'uso](terms-it.html) ([English](terms-en.html))
- [Informativa privacy](privacy-it.html) ([English](privacy-en.html))

Chi contribuisce o modifica il codice dovrebbe leggere **`CLAUDE.md`**: contiene le regole
del progetto e, soprattutto, il *perché* delle decisioni prese.

---

## Licenza

[MIT](LICENSE). Il software è fornito così com'è, senza garanzie.

La licenza riguarda **il codice sorgente** di questo repository. L'uso del servizio ospitato
su ghisa.fabiozappa.it è regolato dai [termini d'uso](terms-it.html), che sono un'altra cosa.
