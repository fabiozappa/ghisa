<?php
// index.php — shell dell'app: guardia di sessione, login e contenitore.
//
// Se non loggato: mostra il form di login (word1 / word2).
// Se loggato: mostra il contenitore dell'app e inietta l'elenco schede in
// window.GHISA, così app.js apre il selettore senza una chiamata in più.
// Tutta la parte dinamica (player, storico) la costruisce app.js.

require_once __DIR__ . '/auth.php';
session_boot();

// Logout gestito qui, non come action API: la sessione è roba della shell.
// SameSite=Strict sul cookie mitiga il logout cross-site via GET.
if (isset($_GET['logout'])) {
    logout();
    header('Location: index.php');
    exit;
}

$logged = is_logged_in();

$workouts = [];
$deleted_workouts = [];
if ($logged) {
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

// Flag JSON-safe anche dentro <script> (niente breakout con </script>).
$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS
    | JSON_HEX_QUOT | JSON_HEX_AMP;
?>
<!doctype html>
<html lang="it">
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
                Nome utente
                <input id="login-username" name="username" type="text"
                       inputmode="text" autocapitalize="none"
                       autocomplete="username" required>
            </label>
            <label>
                Prima parola
                <input id="login-word1" name="word1" type="text"
                       inputmode="text" autocapitalize="none"
                       autocomplete="off" required>
            </label>
            <label>
                Seconda parola
                <input id="login-word2" name="word2" type="text"
                       inputmode="text" autocapitalize="none"
                       autocomplete="off" required>
            </label>
            <button type="submit" id="login-submit">Entra</button>
            <p id="login-error" class="error" role="alert" hidden></p>
            <button type="button" id="show-register" class="link">
                Non hai un account? Registrati
            </button>
        </form>

        <!-- Registrazione: stessa forma del login, con in piu' la creazione. -->
        <form id="register-form" autocomplete="off" hidden>
            <label>
                Nome utente
                <input id="reg-username" name="username" type="text"
                       inputmode="text" autocapitalize="none"
                       autocomplete="username" required>
            </label>
            <label>
                Prima parola
                <input id="reg-word1" name="word1" type="text"
                       inputmode="text" autocapitalize="none"
                       autocomplete="off" required>
            </label>
            <label>
                Seconda parola
                <input id="reg-word2" name="word2" type="text"
                       inputmode="text" autocapitalize="none"
                       autocomplete="off" required>
            </label>
            <p class="reg-hint">
                Due parole che ricordi facilmente, almeno 3 lettere ciascuna.
                Serviranno per entrare, insieme al nome utente.
            </p>
            <button type="submit" id="reg-submit">Crea account</button>
            <p id="reg-error" class="error" role="alert" hidden></p>
            <button type="button" id="show-login" class="link">
                Hai gia' un account? Entra
            </button>
        </form>

    </main>

    <!-- Vista app: contenitore riempito da app.js. -->
    <div id="app-view" class="view">
        <header class="topbar">
            <span class="brand-small">Ghisa</span>
            <!-- Indicatore discreto dei set ancora in coda offline. -->
            <span id="queue-indicator" class="queue" hidden></span>
            <button id="logout-btn" class="link" type="button">Esci</button>
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
            workouts: <?= json_encode($workouts, $json_flags) ?>,
            deleted_workouts: <?= json_encode($deleted_workouts, $json_flags) ?>
        };
    </script>
    <script src="app.js?v=<?= $v_js ?>" defer></script>
</body>
</html>
