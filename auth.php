<?php
// auth.php — sessione sicura, login/logout, guardia per l'API.
// Raccolta di funzioni, niente OOP.

require_once __DIR__ . '/db.php';

// Configura i cookie di sessione e avvia la sessione. Idempotente: se la
// sessione è già attiva non fa nulla, così può essere chiamata liberamente.
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Secure condizionale (scelta b): sempre attivo dietro HTTPS (produzione),
    // spento in locale su HTTP così la sessione parte comunque.
    $secure =
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name('GHISASESS');

    // Parametri cookie impostati PRIMA di session_start (CLAUDE.md punto 5.4).
    session_set_cookie_params([
        'lifetime' => 0,          // cookie di sessione, muore col browser
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,       // niente accesso da JavaScript
        'samesite' => 'Strict',   // niente invio cross-site
    ]);

    session_start();
}

// Id dell'utente loggato, oppure null.
function current_user_id(): ?int
{
    session_boot();
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

// Comodità per la shell dell'app (index.php).
function is_logged_in(): bool
{
    return current_user_id() !== null;
}

// Normalizza la passphrase esattamente come faceva seed.php: le due parole
// unite da uno spazio, trim + lowercase. Deve restare identica al seed,
// altrimenti password_verify fallisce sempre.
function normalize_passphrase(string $word1, string $word2): string
{
    return strtolower(trim($word1)) . ' ' . strtolower(trim($word2));
}

// Normalizza lo username: minuscolo e senza spazi ai bordi. Serve perché
// "Fabio" e "fabio" devono essere la stessa persona, non due account.
function normalize_username(string $username): string
{
    return strtolower(trim($username));
}

// Regole sulle credenziali, in un posto solo: le usano sia login sia
// registrazione, così non possono divergere.
// Ritorna il messaggio d'errore, oppure null se va tutto bene.
function credentials_error(string $username, string $word1, string $word2): ?string
{
    $u = normalize_username($username);
    if (strlen($u) < 3 || strlen($u) > 50) {
        return 'Il nome utente deve avere fra 3 e 50 caratteri';
    }
    if (!preg_match('/^[a-z0-9._-]+$/', $u)) {
        return 'Il nome utente può contenere solo lettere, numeri, punto, trattino e underscore';
    }
    if (strlen(trim($word1)) < 3 || strlen(trim($word2)) < 3) {
        return 'Ognuna delle due parole deve avere almeno 3 caratteri';
    }
    return null;
}

// --- Freno ai tentativi di accesso ---------------------------------------
// Il contatore sta nel database, non in sessione: chi attacca non manda i
// cookie, quindi un contatore di sessione rallenterebbe solo chi sbaglia a
// digitare, e nessun altro.

const LOGIN_WINDOW_MINUTES = 15;   // dopo tanto silenzio si riparte da zero
const LOGIN_MAX_DELAY      = 5;    // tetto al ritardo: uno sleep lungo tiene
                                   // occupato un worker PHP, e senza tetto
                                   // diventa il modo più comodo per mettere
                                   // giù il server con poche richieste
const LOGIN_HARD_LIMIT     = 10;   // oltre: si rifiuta subito, senza dormire

// Solo REMOTE_ADDR: X-Forwarded-For lo scrive il client, quindi fidarsene
// renderebbe il freno aggirabile cambiando un'intestazione.
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

// Pulizia pigra: i tentativi vecchi non contano più. Senza questo, un errore
// di battitura di oggi costerebbe un secondo in più per sempre.
function purge_login_attempts(): void
{
    // Il confronto sta tutto dentro il database: PHP e MySQL possono avere
    // fusi diversi (qui PHP è su UTC e MySQL sull'ora locale), quindi un
    // "adesso" calcolato in PHP non è confrontabile con un NOW() scritto dal
    // database. I minuti restano un parametro, non concatenati nella query.
    db_run(
        "DELETE FROM login_attempts
          WHERE TIMESTAMPDIFF(MINUTE, last_failed_at, NOW()) >= ?",
        [LOGIN_WINDOW_MINUTES]
    );
}

function login_failures(string $ip, string $username): int
{
    $n = db_value(
        "SELECT failures FROM login_attempts WHERE ip = ? AND username = ?",
        [$ip, normalize_username($username)]
    );
    return $n === null ? 0 : (int) $n;
}

function record_login_failure(string $ip, string $username): void
{
    db_run(
        "INSERT INTO login_attempts (ip, username, failures, last_failed_at)
         VALUES (?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE failures = failures + 1, last_failed_at = NOW()",
        [$ip, normalize_username($username)]
    );
}

// Al login riuscito il contatore si azzera.
function clear_login_failures(string $ip, string $username): void
{
    db_run(
        "DELETE FROM login_attempts WHERE ip = ? AND username = ?",
        [$ip, normalize_username($username)]
    );
}

// Verifica username + due parole e, se combaciano, apre la sessione.
// Ritorna l'id utente o null.
//
// Lo username serve: senza, la passphrase sarebbe l'identità e due utenti con
// le stesse due parole si scambierebbero l'account. Inoltre si fa un solo
// password_verify invece di uno per ogni utente esistente.
function login(string $username, string $word1, string $word2): ?int
{
    session_boot();

    $user = db_one(
        "SELECT id, password_hash FROM users WHERE username = ?",
        [normalize_username($username)]
    );

    // password_verify anche a utente inesistente costerebbe di meno e farebbe
    // trapelare quali username esistono dai tempi di risposta: il ritardo sul
    // fallimento (in api.php) livella le due strade.
    if ($user === null || !password_verify(normalize_passphrase($word1, $word2), $user['password_hash'])) {
        return null;
    }

    session_regenerate_id(true);              // nuovo id al login (punto 5.5)
    $_SESSION['user_id'] = (int) $user['id'];
    return (int) $user['id'];
}

// Crea un utente e lo lascia loggato. Ritorna l'id, oppure il messaggio
// d'errore in caso di problema (username già preso, credenziali non valide).
function register_user(string $username, string $word1, string $word2)
{
    session_boot();

    $error = credentials_error($username, $word1, $word2);
    if ($error !== null) {
        return $error;
    }

    $u = normalize_username($username);
    if (db_one("SELECT id FROM users WHERE username = ?", [$u]) !== null) {
        return 'Nome utente già in uso';
    }

    $hash = password_hash(normalize_passphrase($word1, $word2), PASSWORD_DEFAULT);

    try {
        $user_id = db_insert(
            "INSERT INTO users (username, password_hash) VALUES (?, ?)",
            [$u, $hash]
        );
    } catch (PDOException $e) {
        // Due registrazioni simultanee sullo stesso nome: decide il vincolo UNIQUE.
        if ($e->getCode() === '23000') {
            return 'Nome utente già in uso';
        }
        throw $e;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user_id;
    return $user_id;
}

// Chiude la sessione e cancella il cookie.
function logout(): void
{
    session_boot();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Strict',
        ]);
    }

    session_destroy();
}

// Guardia dell'API: da chiamare come prima istruzione di ogni action tranne
// `login`. Se non c'è sessione risponde 401 JSON ed esce, così il client sa
// che deve tornare al login. Se c'è, ritorna l'id utente da usare nelle query.
function requireLogin(): int
{
    session_boot();

    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Sessione scaduta']);
        exit;
    }

    return (int) $_SESSION['user_id'];
}
