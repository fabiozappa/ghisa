<?php
// index.php — shell dell'app: guardia di sessione, login e contenitore.
//
// Se non loggato: mostra il form di login (word1 / word2).
// Se loggato: mostra il contenitore dell'app e inietta l'elenco schede in
// window.GHISA, così app.js apre il selettore senza una chiamata in più.
// Tutta la parte dinamica (player, storico) la costruisce app.js.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lang.php';
session_boot();

// Logout gestito qui, non come action API: la sessione è roba della shell.
// SameSite=Strict sul cookie mitiga il logout cross-site via GET.
if (isset($_GET['logout'])) {
    logout();
    header('Location: index.php');
    exit;
}

// Scelta manuale della lingua: si salva nel cookie e si torna all'URL pulito,
// come per il logout. Gli altri parametri (es. ?import=) restano dove sono.
if (isset($_GET['lang'])) {
    lang_remember(is_string($_GET['lang']) ? $_GET['lang'] : '');
    $query = $_GET;
    unset($query['lang']);
    header('Location: index.php' . ($query ? '?' . http_build_query($query) : ''));
    exit;
}

$logged = is_logged_in();

$workouts = [];
$deleted_workouts = [];
$username = '';
if ($logged) {
    $username = (string) db_value("SELECT username FROM users WHERE id = ?", [current_user_id()]);
    $workouts = db_all(
        "SELECT w.id, w.name, w.position,
                (SELECT MAX(wl.finished_at)
                   FROM workout_logs wl
                  WHERE wl.workout_id = w.id
                    AND wl.user_id = w.user_id
                    AND wl.finished_at IS NOT NULL) AS last_finished
           FROM workouts w
          WHERE w.user_id = ? AND w.deleted_at IS NULL
          ORDER BY w.position, w.id",
        [current_user_id()]
    );

    // Schede nel cestino, per il "Mostra eliminate / Ripristina".
    $deleted_workouts = db_all(
        "SELECT id, name, deleted_at
           FROM workouts
          WHERE user_id = ? AND deleted_at IS NOT NULL
          ORDER BY deleted_at DESC",
        [current_user_id()]
    );
}

// Versione degli asset presa dalla data del file: cambia da sola a ogni
// upload, quindi l'URL cambia e ne' il browser ne' il service worker possono
// servirti una copia vecchia. Niente numeri di versione da ricordare a mano.
$v_css = @filemtime(__DIR__ . '/style.css') ?: time();
$v_js  = @filemtime(__DIR__ . '/app.js') ?: time();

// Frase di accettazione dei documenti, con i link dentro. Si escapa prima il
// testo tradotto e poi si mettono i link al posto dei segnaposto: così
// l'unico HTML che finisce nella pagina è quello scritto qui.
$doc_link = function (string $url_key, string $label_key): string {
    return '<a href="' . trh($url_key) . '" target="_blank" rel="noopener">'
        . trh($label_key) . '</a>';
};
$accept_html = strtr(trh('reg.accept'), [
    '{terms}'   => $doc_link('doc.terms_url', 'doc.terms'),
    '{privacy}' => $doc_link('doc.privacy_url', 'doc.privacy'),
]);

// Selettore della lingua: i link passano da ?lang=, che salva il cookie.
$lang_links = [];
foreach (lang_names() as $code => $name) {
    $current = ($code === lang_current()) ? ' aria-current="true"' : '';
    $lang_links[] = '<a href="index.php?lang=' . urlencode($code) . '"' . $current . '>'
        . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>';
}

// Flag JSON-safe anche dentro <script> (niente breakout con </script>).
$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS
    | JSON_HEX_QUOT | JSON_HEX_AMP;
?>
<!doctype html>
<html lang="<?= htmlspecialchars(lang_current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#111111">
    <title>Ghisa</title>
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="style.css?v=<?= $v_css ?>">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
</head>
<body data-logged-in="<?= $logged ? '1' : '0' ?>">

    <!-- Vista login: visibile solo da sloggati (CSS su data-logged-in). -->
    <main id="login-view" class="view">
        <h1 class="brand">Ghisa</h1>
        <form id="login-form" autocomplete="off">
            <label>
                <?= trh('login.username') ?>
                <input id="login-username" name="username" type="text"
                       inputmode="text" autocapitalize="none"
                       autocomplete="username" required>
            </label>
            <label>
                <?= trh('common.first_word') ?>
                <input id="login-word1" name="word1" type="text"
                       inputmode="text" autocapitalize="none"
                       autocomplete="off" required>
            </label>
            <label>
                <?= trh('common.second_word') ?>
                <input id="login-word2" name="word2" type="text"
                       inputmode="text" autocapitalize="none"
                       autocomplete="off" required>
            </label>
            <button type="submit" id="login-submit"><?= trh('login.submit') ?></button>
            <p id="login-error" class="error" role="alert" hidden></p>
            <button type="button" id="show-register" class="link">
                <?= trh('login.to_register') ?>
            </button>
        </form>

        <!-- Registrazione: stessa forma del login, con in piu' la creazione. -->
        <form id="register-form" autocomplete="off" hidden>
            <label>
                <?= trh('login.username') ?>
                <input id="reg-username" name="username" type="text"
                       inputmode="text" autocapitalize="none"
                       autocomplete="username" required>
            </label>
            <label>
                <?= trh('common.first_word') ?>
                <input id="reg-word1" name="word1" type="text"
                       inputmode="text" autocapitalize="none"
                       autocomplete="off" required>
            </label>
            <label>
                <?= trh('common.second_word') ?>
                <input id="reg-word2" name="word2" type="text"
                       inputmode="text" autocapitalize="none"
                       autocomplete="off" required>
            </label>
            <p class="reg-hint"><?= trh('reg.hint') ?></p>
            <p class="reg-hint"><?= $accept_html ?></p>
            <button type="submit" id="reg-submit"><?= trh('reg.submit') ?></button>
            <p id="reg-error" class="error" role="alert" hidden></p>
            <button type="button" id="show-login" class="link">
                <?= trh('reg.to_login') ?>
            </button>
        </form>

        <p class="lang-switch"><?= implode(' · ', $lang_links) ?></p>

    </main>

    <!-- Vista app: contenitore riempito da app.js. -->
    <div id="app-view" class="view">
        <header class="topbar">
            <span class="brand-small">Ghisa</span>
            <!-- Indicatore discreto dei set ancora in coda offline. -->
            <span id="queue-indicator" class="queue" hidden></span>
            <button id="logout-btn" class="link" type="button"><?= trh('app.logout') ?></button>
        </header>

        <!-- Selettore scheda / home. -->
        <section id="home-view" class="screen"></section>

        <!-- Player dell'allenamento. -->
        <section id="player-view" class="screen" hidden></section>

        <!-- Storico. -->
        <section id="history-view" class="screen" hidden></section>
    </div>

    <script>
        // Stato iniziale iniettato dal server. Le schede ci sono solo se loggato.
        window.GHISA = {
            loggedIn: <?= $logged ? 'true' : 'false' ?>,
            username: <?= json_encode($username, $json_flags) ?>,
            workouts: <?= json_encode($workouts, $json_flags) ?>,
            deleted_workouts: <?= json_encode($deleted_workouts, $json_flags) ?>,
            // Lingua e testi dell'interfaccia, già completi: dove manca una
            // traduzione c'è l'italiano. Li legge tr() in app.js.
            lang: <?= json_encode(lang_current(), $json_flags) ?>,
            langs: <?= json_encode(lang_names(), $json_flags | JSON_FORCE_OBJECT) ?>,
            strings: <?= json_encode(lang_strings(), $json_flags | JSON_FORCE_OBJECT) ?>
        };
    </script>
    <script src="app.js?v=<?= $v_js ?>" defer></script>
</body>
</html>
