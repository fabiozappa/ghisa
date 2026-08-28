<?php
// index.php — shell dell'app: guardia di sessione, login e contenitore.
//
// Se non loggato: mostra il form di login (word1 / word2).
// Se loggato: mostra il contenitore dell'app e inietta l'elenco schede in
// window.GHISA, così app.js apre il selettore senza una chiamata in più.
// Tutta la parte dinamica (player, storico) la costruisce app.js.

require_once __DIR__ . '/auth.php';
session_boot();

$logged = is_logged_in();

$workouts = [];
if ($logged) {
    $workouts = db_all(
        "SELECT id, name FROM workouts WHERE user_id = ? ORDER BY id",
        [current_user_id()]
    );
}

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
    <link rel="stylesheet" href="style.css">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
</head>
<body data-logged-in="<?= $logged ? '1' : '0' ?>">

    <!-- Vista login: visibile solo da sloggati (CSS su data-logged-in). -->
    <main id="login-view" class="view">
        <h1 class="brand">Ghisa</h1>
        <form id="login-form" autocomplete="off">
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
            workouts: <?= json_encode($workouts, $json_flags) ?>
        };
    </script>
    <script src="app.js" defer></script>
</body>
</html>
