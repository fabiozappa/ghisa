<?php
// public_workout.php — pagina pubblica di una scheda condivisa.
//
// NESSUN LOGIN: chiunque abbia il codice vede questa pagina. Perciò mostra
// SOLO l'anagrafica della scheda: mai lo storico, mai i pesi usati, mai
// niente che riguardi l'utente. Il codice è un UUID: non si indovina, ma
// chi ce l'ha vede, quindi va trattato come una password.
//
// La query è rifatta qui invece di riusare shared_workout_by_code() di
// api.php: quel file è un front controller e includerlo eseguirebbe l'API.
// È una duplicazione voluta e segnalata, non una svista.

require_once __DIR__ . '/db.php';

$code = trim((string) ($_GET['s'] ?? ''));

$workout = null;
$exercises = [];

// Si accetta solo la forma esatta di un UUID: così una stringa qualsiasi non
// arriva nemmeno al database.
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $code)) {
    $workout = db_one(
        "SELECT id, name FROM workouts WHERE share_code = ? AND deleted_at IS NULL",
        [strtolower($code)]
    );
}

if ($workout !== null) {
    $exercises = db_all(
        "SELECT name, type, target_sets, target_reps, rest_seconds, url
           FROM exercises
          WHERE workout_id = ? AND alternative_of IS NULL AND deleted_at IS NULL
          ORDER BY position, id",
        [$workout['id']]
    );
}

// Stessa regola di build_target() in api.php: per gli esercizi a tempo il
// valore sono secondi e il target finisce in "s".
function public_target(array $e): string
{
    $sets = ($e['target_sets'] === null || $e['target_sets'] === '')
        ? null : (int) $e['target_sets'];
    $reps = ($e['target_reps'] === null || $e['target_reps'] === '')
        ? null : trim((string) $e['target_reps']);
    if ($sets === null && $reps === null) {
        return '';
    }
    return ($sets ?? '?') . 'x' . ($reps ?? '?') . ($e['type'] === 'time' ? 's' : '');
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// URL assoluto della pagina, per canonical e anteprime social.
$scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$page_url = $scheme . '://' . $host . strtok($_SERVER['REQUEST_URI'] ?? '', '#');
$site_url = $scheme . '://' . $host;

if ($workout === null) {
    http_response_code(404);
    $title = 'Scheda non disponibile — Ghisa';
    $description = 'Questa scheda di allenamento non esiste o la condivisione è stata revocata.';
} else {
    $names = array_slice(array_column($exercises, 'name'), 0, 5);
    $title = $workout['name'] . ' — scheda di allenamento | Ghisa';
    $description = count($exercises) . ' esercizi: ' . implode(', ', $names)
        . (count($exercises) > 5 ? '…' : '.');
}

$v_css = @filemtime(__DIR__ . '/style.css') ?: time();
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#111111">

    <title><?= h($title) ?></title>
    <meta name="description" content="<?= h($description) ?>">
    <link rel="canonical" href="<?= h($page_url) ?>">

    <!-- La pagina si raggiunge con un codice segreto: se venisse indicizzata,
         basterebbe un link pubblico per rendere cercabili gli allenamenti.
         Per indicizzare davvero, togliere questa riga (e valutare il JSON-LD). -->
    <meta name="robots" content="noindex, nofollow">

    <!-- Anteprima quando il link viene incollato in chat o sui social. -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Ghisa">
    <meta property="og:title" content="<?= h($title) ?>">
    <meta property="og:description" content="<?= h($description) ?>">
    <meta property="og:url" content="<?= h($page_url) ?>">
    <meta property="og:image" content="<?= h($site_url) ?>/icons/icon-512.png">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= h($title) ?>">
    <meta name="twitter:description" content="<?= h($description) ?>">

    <link rel="stylesheet" href="style.css?v=<?= $v_css ?>">
    <link rel="icon" href="icons/icon-192.png">
</head>
<body class="public">

<main class="screen">
<?php if ($workout === null): ?>

    <h1 class="brand">Ghisa</h1>
    <p>Questa scheda non esiste, oppure la condivisione è stata revocata da chi
       l'aveva pubblicata.</p>
    <a class="big" href="index.php">Vai a Ghisa</a>

<?php else: ?>

    <p class="public-kicker">Scheda di allenamento condivisa</p>
    <h1 class="public-title"><?= h($workout['name']) ?></h1>

    <?php if (!$exercises): ?>
        <p>Questa scheda non ha ancora esercizi.</p>
    <?php else: ?>
        <ol class="public-list">
        <?php foreach ($exercises as $e): ?>
            <li class="public-ex">
                <span class="public-ex-name"><?= h($e['name']) ?></span>
                <span class="public-ex-meta">
                    <?php $t = public_target($e); ?>
                    <?= $t !== '' ? h($t) : '—' ?>
                    · recupero <?= (int) $e['rest_seconds'] ?>s
                </span>
                <?php if ($e['url'] && preg_match('#^https?://#i', $e['url'])): ?>
                    <a class="public-ex-link" href="<?= h($e['url']) ?>"
                       target="_blank" rel="noopener noreferrer nofollow">
                        Vedi esercizio ↗
                    </a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <a class="big primary" href="index.php?import=<?= h(strtolower($code)) ?>">
        Importa questa scheda in Ghisa
    </a>
    <p class="public-foot">
        <strong>Ghisa</strong> è un'app per seguire le proprie schede di
        allenamento dal telefono, e tenere lo storico dei carichi.
    </p>

<?php endif; ?>
</main>

</body>
</html>
