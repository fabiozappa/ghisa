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

// Verifica le due parole e, se combaciano, apre la sessione. Ritorna l'id
// utente o null. In v1 l'utente è uno solo e la login non ha username: quindi
// verifichiamo la passphrase contro gli utenti esistenti (in v1, uno).
function login(string $word1, string $word2): ?int
{
    session_boot();

    $passphrase = normalize_passphrase($word1, $word2);

    $users = db_all("SELECT id, password_hash FROM users");
    foreach ($users as $u) {
        if (password_verify($passphrase, $u['password_hash'])) {
            session_regenerate_id(true);          // nuovo id al login (punto 5.5)
            $_SESSION['user_id'] = (int) $u['id'];
            return (int) $u['id'];
        }
    }

    return null;
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
