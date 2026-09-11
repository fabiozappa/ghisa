<?php
// lang.php — lingua dell'interfaccia e traduzione dei testi.
//
// Le traduzioni stanno in lang-<codice>.php, un file per lingua, ognuno un
// array chiave => testo. L'italiano è la lingua di RISERVA: se in un'altra
// lingua manca una chiave, compare il testo italiano, mai un buco vuoto.
//
// Da dove arriva la lingua, in quest'ordine:
//   1. il cookie GHISALANG, scritto quando l'utente sceglie a mano (?lang=);
//   2. l'intestazione Accept-Language del browser;
//   3. l'italiano.
//
// Raccolta di funzioni, niente OOP. Non dipende da db.php né da auth.php, così
// la può usare anche public_workout.php, che non ha sessione.

// Lingue disponibili. La prima è quella di riserva.
const LANGS = ['it', 'en'];
const LANG_COOKIE = 'GHISALANG';
const LANG_COOKIE_DAYS = 365;

// true se la lingua è tra quelle disponibili. È anche la difesa del require in
// lang_strings(): nel percorso del file può finire solo un codice di LANGS.
function lang_is_valid(string $lang): bool
{
    return in_array($lang, LANGS, true);
}

// Sceglie la lingua dall'intestazione Accept-Language, rispettando i pesi q.
// Es. "en-GB,en;q=0.9,it;q=0.8" -> 'en'. null se nessuna è disponibile.
function lang_from_header(string $header): ?string
{
    $best = null;
    $best_q = 0.0;

    foreach (explode(',', $header) as $part) {
        $bits = explode(';', $part);

        // Conta solo la lingua principale: "en-GB" vale come "en".
        $code = strtolower(trim(explode('-', trim($bits[0]))[0]));

        $q = 1.0;
        foreach (array_slice($bits, 1) as $bit) {
            $bit = trim($bit);
            if (str_starts_with($bit, 'q=')) {
                $q = (float) substr($bit, 2);
            }
        }

        // A parità di peso vince la prima elencata: da qui il > stretto.
        if (lang_is_valid($code) && $q > $best_q) {
            $best = $code;
            $best_q = $q;
        }
    }

    return $best;
}

// Lingua della richiesta corrente. Calcolata una volta sola.
function lang_current(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }

    $cookie = $_COOKIE[LANG_COOKIE] ?? '';
    if (is_string($cookie) && lang_is_valid($cookie)) {
        $lang = $cookie;
        return $lang;
    }

    $from_header = lang_from_header((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    $lang = $from_header ?? LANGS[0];
    return $lang;
}

// Salva la scelta manuale nel cookie. È un cookie tecnico di preferenza:
// contiene solo il codice della lingua, niente che identifichi la persona, ed
// è dichiarato nella privacy. Se la lingua non è disponibile non scrive nulla.
function lang_remember(string $lang): bool
{
    if (!lang_is_valid($lang)) {
        return false;
    }

    // Stessa regola del cookie di sessione in session_boot(): Secure dietro HTTPS.
    $secure =
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    setcookie(LANG_COOKIE, $lang, [
        'expires'  => time() + LANG_COOKIE_DAYS * 86400,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,       // a JS non serve: la lingua la inietta index.php
        // Lax e non Strict: chi apre un link condiviso da una chat arriva da un
        // altro sito, e con Strict il cookie non partirebbe. Qui non protegge
        // niente di sensibile, quindi Lax basta.
        'samesite' => 'Lax',
    ]);

    return true;
}

// Dizionario completo della lingua corrente: l'italiano come base, sopra la
// traduzione. Così una chiave che manca in inglese resta in italiano.
function lang_strings(): array
{
    static $strings = null;
    if ($strings !== null) {
        return $strings;
    }

    $strings = require __DIR__ . '/lang-' . LANGS[0] . '.php';

    $lang = lang_current();
    if ($lang !== LANGS[0]) {
        $strings = array_merge($strings, require __DIR__ . '/lang-' . $lang . '.php');
    }

    return $strings;
}

// Traduce una chiave. I segnaposto {nome} vengono sostituiti con $params.
// Una chiave inesistente torna com'è: si vede subito, invece di un buco vuoto.
// Il testo NON è escapato: in HTML va comunque passato da htmlspecialchars.
function tr(string $key, array $params = []): string
{
    $strings = lang_strings();
    $text = $strings[$key] ?? $key;

    foreach ($params as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }

    return $text;
}
