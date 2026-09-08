<?php
// api.php — unico endpoint dell'app. Riceve POST, legge action, risponde JSON.
//
// Forma della risposta (CLAUDE.md punto 6):
//   { "ok": true,  "data": { ... } }
//   { "ok": false, "error": "messaggio" }
// HTTP 200 anche sugli errori applicativi; 401 solo per sessione scaduta
// (lo emette requireLogin).

require_once __DIR__ . '/auth.php';   // che a sua volta include db.php

header('Content-Type: application/json; charset=utf-8');

// --- Helper di risposta. Chiudono sempre la richiesta con exit. -------------
function respond_ok($data = null): void
{
    echo json_encode(['ok' => true, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respond_err(string $message): void
{
    echo json_encode(['ok' => false, 'error' => $message],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Helper di dominio. ------------------------------------------------------

// Compone la stringa target "3x10" da sets e reps dell'anagrafica.
// Ritorna null se non c'è alcun target.
function build_target($sets, $reps, string $type = 'reps'): ?string
{
    $sets = ($sets === null || $sets === '') ? null : (int) $sets;
    $reps = ($reps === null || $reps === '') ? null : trim((string) $reps);
    if ($sets === null && $reps === null) {
        return null;
    }
    // Per gli esercizi a tempo il valore sono secondi. L'unità finisce dentro
    // il target, che è denormalizzato nel log: così anche lo storico resta
    // leggibile senza dover sapere il `type` dell'anagrafica.
    $unit = ($type === 'time') ? 's' : '';
    return ($sets ?? '?') . 'x' . ($reps ?? '?') . $unit;
}

// Ultimo peso usato per un esercizio, per precompilare il campo.
// Prima per exercise_id, poi in fallback per nome normalizzato (LOWER+TRIM),
// sempre e solo sull'utente corrente. Ritorna float o null.
function last_weight(int $user_id, int $exercise_id, string $exercise_name): ?float
{
    // 1) match forte sull'id dell'esercizio
    $w = db_value(
        "SELECT el.weight_kg
           FROM exercise_logs el
           JOIN workout_logs wl ON wl.id = el.workout_log_id
          WHERE wl.user_id = ?
            AND el.exercise_id = ?
            AND el.weight_kg IS NOT NULL
          ORDER BY el.logged_at DESC, el.id DESC
          LIMIT 1",
        [$user_id, $exercise_id]
    );
    if ($w !== null) {
        return (float) $w;
    }

    // 2) fallback sul nome: gli id cambiano reimportando la scheda, il nome no
    $w = db_value(
        "SELECT el.weight_kg
           FROM exercise_logs el
           JOIN workout_logs wl ON wl.id = el.workout_log_id
          WHERE wl.user_id = ?
            AND LOWER(TRIM(el.exercise_name)) = ?
            AND el.weight_kg IS NOT NULL
          ORDER BY el.logged_at DESC, el.id DESC
          LIMIT 1",
        [$user_id, strtolower(trim($exercise_name))]
    );
    return $w !== null ? (float) $w : null;
}

// Serie eseguite l'ULTIMA volta che questo esercizio è stato fatto in una
// sessione completata. Serve da riferimento in palestra (cosa battere).
// Come per l'ultimo peso: prima per exercise_id, poi in fallback per nome.
function last_session_sets(int $user_id, int $exercise_id, string $exercise_name): array
{
    // 1) ultimo workout_log completato che contiene questo exercise_id
    $log_id = db_value(
        "SELECT el.workout_log_id
           FROM exercise_logs el
           JOIN workout_logs wl ON wl.id = el.workout_log_id
          WHERE wl.user_id = ?
            AND el.exercise_id = ?
            AND wl.finished_at IS NOT NULL
          ORDER BY wl.started_at DESC, wl.id DESC
          LIMIT 1",
        [$user_id, $exercise_id]
    );
    if ($log_id !== null) {
        return db_all(
            "SELECT set_number, weight_kg, reps_completed
               FROM exercise_logs
              WHERE workout_log_id = ? AND exercise_id = ?
              ORDER BY set_number, id",
            [$log_id, $exercise_id]
        );
    }

    // 2) fallback per nome normalizzato
    $name = strtolower(trim($exercise_name));
    $log_id = db_value(
        "SELECT el.workout_log_id
           FROM exercise_logs el
           JOIN workout_logs wl ON wl.id = el.workout_log_id
          WHERE wl.user_id = ?
            AND LOWER(TRIM(el.exercise_name)) = ?
            AND wl.finished_at IS NOT NULL
          ORDER BY wl.started_at DESC, wl.id DESC
          LIMIT 1",
        [$user_id, $name]
    );
    if ($log_id === null) {
        return [];
    }
    return db_all(
        "SELECT set_number, weight_kg, reps_completed
           FROM exercise_logs
          WHERE workout_log_id = ? AND LOWER(TRIM(exercise_name)) = ?
          ORDER BY set_number, id",
        [$log_id, $name]
    );
}

// Arricchisce una riga esercizio coi dati derivati usati dal player:
// target composto, ultimo peso e serie dell'ultima volta. Condiviso da
// get_workout e get_alternatives così un'alternativa si renderizza come un
// esercizio qualsiasi.
function enrich_exercise(array $ex, int $user_id): array
{
    $ex['target'] = build_target($ex['target_sets'], $ex['target_reps'],
        $ex['type'] ?? 'reps');
    $ex['last_weight_kg'] = last_weight($user_id, (int) $ex['id'], $ex['name']);
    $ex['last_sets'] = last_session_sets($user_id, (int) $ex['id'], $ex['name']);
    return $ex;
}

// Valida un URL fornito dall'utente: solo http/https, niente javascript: & co.
function valid_url(string $url): bool
{
    return (bool) preg_match('#^https?://#i', $url);
}

// Elenco schede: vive (ordinate) e in cestino. Lo restituiscono login e tutte
// le action che modificano le schede, così il client si riallinea da solo.
function workouts_payload(int $user_id): array
{
    $alive = db_all(
        "SELECT w.id, w.name, w.position,
                (SELECT MAX(wl.finished_at)
                   FROM workout_logs wl
                  WHERE wl.workout_id = w.id
                    AND wl.user_id = w.user_id
                    AND wl.finished_at IS NOT NULL) AS last_finished
           FROM workouts w
          WHERE w.user_id = ? AND w.deleted_at IS NULL
          ORDER BY w.position, w.id",
        [$user_id]
    );
    $deleted = db_all(
        "SELECT id, name, deleted_at
           FROM workouts
          WHERE user_id = ? AND deleted_at IS NOT NULL
          ORDER BY deleted_at DESC",
        [$user_id]
    );
    return ['workouts' => $alive, 'deleted_workouts' => $deleted];
}

// Pulizia pigra del cestino: quello che è lì da più di 30 giorni sparisce
// davvero. Niente cron, gira all'accesso e all'apertura dell'editor.
// Lo storico non ne risente: i log hanno le FK in SET NULL e nome/target
// denormalizzati dentro, e il lookup dell'ultimo peso ha il fallback per nome.
function purge_expired(int $user_id): void
{
    // Esercizi scaduti (le loro alternative seguono in CASCADE).
    db_run(
        "DELETE e FROM exercises e
           JOIN workouts w ON w.id = e.workout_id
          WHERE w.user_id = ?
            AND e.deleted_at IS NOT NULL
            AND e.deleted_at < (NOW() - INTERVAL 30 DAY)",
        [$user_id]
    );
    // Schede scadute (i loro esercizi seguono in CASCADE).
    db_run(
        "DELETE FROM workouts
          WHERE user_id = ?
            AND deleted_at IS NOT NULL
            AND deleted_at < (NOW() - INTERVAL 30 DAY)",
        [$user_id]
    );
}

// Formatta un peso togliendo gli zeri decimali inutili: 70.00 -> "70kg".
function fmt_weight($w): string
{
    if ($w === null || $w === '') {
        return '—';
    }
    $s = rtrim(rtrim(number_format((float) $w, 2, '.', ''), '0'), '.');
    return $s . 'kg';
}

// Genera il riepilogo testuale di una sessione, raggruppando per esercizio
// nell'ordine di esecuzione.
function build_summary(array $log, array $rows, ?string $notes = null): string
{
    $lines = [$log['workout_name']];

    $by_ex = [];
    foreach ($rows as $r) {
        $name = $r['exercise_name'];
        if (!isset($by_ex[$name])) {
            $by_ex[$name] = ['target' => $r['target'], 'sets' => []];
        }
        $done = ($r['reps_completed'] === null || $r['reps_completed'] === '')
            ? '—' : (int) $r['reps_completed'];
        // Senza peso (corpo libero o esercizio a tempo) si mostra il solo
        // valore: "45" invece di un poco leggibile "—×45".
        $by_ex[$name]['sets'][] =
            ($r['weight_kg'] === null || $r['weight_kg'] === '')
                ? (string) $done
                : fmt_weight($r['weight_kg']) . '×' . $done;
    }

    foreach ($by_ex as $name => $info) {
        $target = $info['target'] ? " ({$info['target']})" : '';
        $lines[] = $name . $target . ': ' . implode(', ', $info['sets']);
    }

    // La nota chiude il riepilogo: così finisce anche nel testo copiato.
    if ($notes !== null && trim($notes) !== '') {
        $lines[] = '';
        $lines[] = 'Nota: ' . trim($notes);
    }

    return implode("\n", $lines);
}

// --- Front controller. -------------------------------------------------------
$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        // -- login: word1, word2 -> esito + schede dell'utente ---------------
        case 'login': {
            $word1 = $_POST['word1'] ?? '';
            $word2 = $_POST['word2'] ?? '';

            $user_id = login($word1, $word2);
            if ($user_id === null) {
                respond_err('Credenziali non valide');
            }

            // Occasione buona per svuotare il cestino scaduto.
            purge_expired($user_id);

            respond_ok(array_merge(
                ['user_id' => $user_id],
                workouts_payload($user_id)
            ));
            break;
        }

        // -- get_workout: workout_id -> apre la sessione + scheda + ultimi pesi
        case 'get_workout': {
            $user_id = requireLogin();
            $workout_id = (int) ($_POST['workout_id'] ?? 0);

            $workout = db_one(
                "SELECT id, name FROM workouts
                  WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
                [$workout_id, $user_id]
            );
            if ($workout === null) {
                respond_err('Scheda non trovata');
            }

            // Solo i titolari dello slot (alternative escluse), vivi, in ordine.
            $exercises = db_all(
                "SELECT id, name, type, target_sets, target_reps, rest_seconds, url, position
                   FROM exercises
                  WHERE workout_id = ? AND deleted_at IS NULL AND alternative_of IS NULL
                  ORDER BY position, id",
                [$workout_id]
            );

            foreach ($exercises as &$ex) {
                $ex = enrich_exercise($ex, $user_id);
            }
            unset($ex);

            // Crea la sessione ora (scelta di design: get_workout la apre).
            $workout_log_id = db_insert(
                "INSERT INTO workout_logs (user_id, workout_id, workout_name, started_at)
                 VALUES (?, ?, ?, NOW())",
                [$user_id, $workout['id'], $workout['name']]
            );

            respond_ok([
                'workout_log_id' => $workout_log_id,
                'workout'        => $workout,
                'exercises'      => $exercises,
            ]);
            break;
        }

        // -- log_set: registra una serie, idempotente su client_uid ----------
        case 'log_set': {
            $user_id = requireLogin();

            $client_uid     = trim((string) ($_POST['client_uid'] ?? ''));
            $workout_log_id = (int) ($_POST['workout_log_id'] ?? 0);
            $exercise_id    = (int) ($_POST['exercise_id'] ?? 0);
            $set_number     = (int) ($_POST['set_number'] ?? 0);

            $weight_kg = (isset($_POST['weight_kg']) && $_POST['weight_kg'] !== '')
                ? (float) $_POST['weight_kg'] : null;
            $reps_completed = (isset($_POST['reps_completed']) && $_POST['reps_completed'] !== '')
                ? (int) $_POST['reps_completed'] : null;

            if ($client_uid === '' || strlen($client_uid) > 36) {
                respond_err('client_uid mancante o non valido');
            }
            if ($set_number < 1) {
                respond_err('set_number non valido');
            }

            // La sessione deve essere dell'utente corrente.
            $log = db_one(
                "SELECT id FROM workout_logs WHERE id = ? AND user_id = ?",
                [$workout_log_id, $user_id]
            );
            if ($log === null) {
                respond_err('Sessione non trovata');
            }

            // Snapshot dell'anagrafica: nome e target dell'esercizio, verificando
            // che appartenga a una scheda dell'utente. Soft-deleted incluso: si
            // può finire di loggare un esercizio cancellato a metà sessione.
            $ex = db_one(
                "SELECT e.name, e.type, e.target_sets, e.target_reps
                   FROM exercises e
                   JOIN workouts w ON w.id = e.workout_id
                  WHERE e.id = ? AND w.user_id = ?",
                [$exercise_id, $user_id]
            );
            if ($ex === null) {
                respond_err('Esercizio non trovato');
            }
            $target = build_target($ex['target_sets'], $ex['target_reps'], $ex['type']);

            // INSERT idempotente: se lo stesso set torna dalla coda offline,
            // il vincolo UNIQUE su client_uid scatta e rispondiamo comunque ok.
            try {
                db_insert(
                    "INSERT INTO exercise_logs
                        (workout_log_id, exercise_id, exercise_name, target,
                         set_number, weight_kg, reps_completed, client_uid)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$workout_log_id, $exercise_id, $ex['name'], $target,
                     $set_number, $weight_kg, $reps_completed, $client_uid]
                );
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    respond_ok(['duplicate' => true]);   // già registrato: ok
                }
                throw $e;
            }

            respond_ok(['duplicate' => false]);
            break;
        }

        // -- delete_set: annulla una serie della sessione IN CORSO -----------
        case 'delete_set': {
            $user_id = requireLogin();
            $client_uid = trim((string) ($_POST['client_uid'] ?? ''));
            if ($client_uid === '') {
                respond_err('client_uid mancante');
            }

            // Si può correggere solo la sessione che stai facendo: su una
            // sessione già chiusa lo storico resta immutabile.
            db_run(
                "DELETE el FROM exercise_logs el
                   JOIN workout_logs wl ON wl.id = el.workout_log_id
                  WHERE el.client_uid = ?
                    AND wl.user_id = ?
                    AND wl.finished_at IS NULL",
                [$client_uid, $user_id]
            );

            // Idempotente: se la serie non era mai arrivata al server va bene
            // lo stesso, il client l'ha già tolta dalla sua coda.
            respond_ok();
            break;
        }

        // -- finish_workout: chiude la sessione e genera il riepilogo --------
        case 'finish_workout': {
            $user_id = requireLogin();
            $workout_log_id = (int) ($_POST['workout_log_id'] ?? 0);
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $notes = $notes === '' ? null : $notes;

            $log = db_one(
                "SELECT id, workout_name, started_at, finished_at
                   FROM workout_logs
                  WHERE id = ? AND user_id = ?",
                [$workout_log_id, $user_id]
            );
            if ($log === null) {
                respond_err('Sessione non trovata');
            }

            // Chiude la sessione. Rieseguibile: aggiorna finished_at e note.
            db_run(
                "UPDATE workout_logs SET finished_at = NOW(), notes = ?
                  WHERE id = ? AND user_id = ?",
                [$notes, $workout_log_id, $user_id]
            );

            $rows = db_all(
                "SELECT exercise_name, target, set_number, weight_kg, reps_completed
                   FROM exercise_logs
                  WHERE workout_log_id = ?
                  ORDER BY id",
                [$workout_log_id]
            );

            $summary = build_summary($log, $rows, $notes);

            respond_ok([
                'workout_log_id' => $workout_log_id,
                'summary'        => $summary,
                'sets_count'     => count($rows),
            ]);
            break;
        }

        // -- cancel_workout: scarta una sessione senza salvare nulla ---------
        case 'cancel_workout': {
            $user_id = requireLogin();
            $workout_log_id = (int) ($_POST['workout_log_id'] ?? 0);

            $log = db_one(
                "SELECT id FROM workout_logs WHERE id = ? AND user_id = ?",
                [$workout_log_id, $user_id]
            );
            if ($log === null) {
                respond_err('Sessione non trovata');
            }

            // Cancellazione FISICA (log + serie): è una sessione che l'utente
            // dichiara di voler buttare via, non storico da proteggere.
            db()->beginTransaction();
            try {
                db_run("DELETE FROM exercise_logs WHERE workout_log_id = ?",
                    [$workout_log_id]);
                db_run("DELETE FROM workout_logs WHERE id = ? AND user_id = ?",
                    [$workout_log_id, $user_id]);
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                throw $e;
            }

            respond_ok();
            break;
        }

        // -- get_alternatives: pool di alternative già usate per uno slot ----
        case 'get_alternatives': {
            $user_id = requireLogin();
            $exercise_id = (int) ($_POST['exercise_id'] ?? 0);

            // Il titolare deve essere dell'utente.
            $primary = db_one(
                "SELECT e.id FROM exercises e
                   JOIN workouts w ON w.id = e.workout_id
                  WHERE e.id = ? AND w.user_id = ?",
                [$exercise_id, $user_id]
            );
            if ($primary === null) {
                respond_err('Esercizio non trovato');
            }

            $alts = db_all(
                "SELECT id, name, type, target_sets, target_reps, rest_seconds, url, position
                   FROM exercises
                  WHERE alternative_of = ? AND deleted_at IS NULL
                  ORDER BY name",
                [$exercise_id]
            );
            foreach ($alts as &$a) {
                $a = enrich_exercise($a, $user_id);
            }
            unset($a);

            respond_ok(['alternatives' => $alts]);
            break;
        }

        // -- add_alternative: crea un'alternativa per uno slot ---------------
        case 'add_alternative': {
            $user_id = requireLogin();
            $primary_id = (int) ($_POST['primary_exercise_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));

            if ($name === '') {
                respond_err('Nome alternativa mancante');
            }

            // Titolare dell'utente (e davvero un titolare): da qui eredito i default.
            $primary = db_one(
                "SELECT e.id, e.workout_id, e.type, e.target_sets, e.target_reps, e.rest_seconds
                   FROM exercises e
                   JOIN workouts w ON w.id = e.workout_id
                  WHERE e.id = ? AND w.user_id = ? AND e.alternative_of IS NULL",
                [$primary_id, $user_id]
            );
            if ($primary === null) {
                respond_err('Esercizio titolare non trovato');
            }

            $target_sets = ($_POST['target_sets'] ?? '') === ''
                ? $primary['target_sets'] : (int) $_POST['target_sets'];
            $target_reps = ($_POST['target_reps'] ?? '') === ''
                ? $primary['target_reps'] : trim((string) $_POST['target_reps']);
            $rest = ($_POST['rest_seconds'] ?? '') === ''
                ? (int) $primary['rest_seconds'] : (int) $_POST['rest_seconds'];

            $url = trim((string) ($_POST['url'] ?? ''));
            if ($url !== '' && !valid_url($url)) {
                respond_err('URL non valido (usa http:// o https://)');
            }
            $url = $url === '' ? null : $url;

            $new_id = db_insert(
                "INSERT INTO exercises
                    (workout_id, name, type, target_sets, target_reps,
                     rest_seconds, url, position, alternative_of)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)",
                [$primary['workout_id'], $name, $primary['type'], $target_sets,
                 $target_reps, $rest, $url, $primary_id]
            );

            $row = db_one(
                "SELECT id, name, type, target_sets, target_reps, rest_seconds, url, position
                   FROM exercises WHERE id = ?",
                [$new_id]
            );
            respond_ok(['exercise' => enrich_exercise($row, $user_id)]);
            break;
        }

        // -- promote_alternative: l'alternativa diventa titolare dello slot --
        case 'promote_alternative': {
            $user_id = requireLogin();
            $alt_id = (int) ($_POST['alternative_exercise_id'] ?? 0);

            // Dev'essere un'alternativa dell'utente.
            $alt = db_one(
                "SELECT e.id, e.alternative_of
                   FROM exercises e
                   JOIN workouts w ON w.id = e.workout_id
                  WHERE e.id = ? AND w.user_id = ? AND e.alternative_of IS NOT NULL",
                [$alt_id, $user_id]
            );
            if ($alt === null) {
                respond_err('Alternativa non trovata');
            }
            $primary_id = (int) $alt['alternative_of'];
            $primary = db_one("SELECT id, position FROM exercises WHERE id = ?", [$primary_id]);
            if ($primary === null) {
                respond_err('Titolare non trovato');
            }

            // Scambio dei ruoli nello slot, in transazione.
            db()->beginTransaction();
            try {
                // 1) le altre alternative dello slot puntano al nuovo titolare
                db_run("UPDATE exercises SET alternative_of = ? WHERE alternative_of = ? AND id <> ?",
                    [$alt_id, $primary_id, $alt_id]);
                // 2) il vecchio titolare scala ad alternativa del nuovo
                db_run("UPDATE exercises SET alternative_of = ?, position = 0 WHERE id = ?",
                    [$alt_id, $primary_id]);
                // 3) l'alternativa diventa titolare ed eredita la posizione
                db_run("UPDATE exercises SET alternative_of = NULL, position = ? WHERE id = ?",
                    [(int) $primary['position'], $alt_id]);
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                throw $e;
            }

            respond_ok();
            break;
        }

        // -- get_workout_edit: scheda + esercizi SENZA aprire una sessione ---
        case 'get_workout_edit': {
            $user_id = requireLogin();
            purge_expired($user_id);

            $workout_id = (int) ($_POST['workout_id'] ?? 0);
            $workout = db_one(
                "SELECT id, name, position FROM workouts
                  WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
                [$workout_id, $user_id]
            );
            if ($workout === null) {
                respond_err('Scheda non trovata');
            }

            $exercises = db_all(
                "SELECT id, name, type, target_sets, target_reps, rest_seconds, url, position
                   FROM exercises
                  WHERE workout_id = ? AND alternative_of IS NULL AND deleted_at IS NULL
                  ORDER BY position, id",
                [$workout_id]
            );

            // Pool alternative di tutta la scheda in una query sola, poi
            // raggruppate per titolare: niente N+1.
            $alts = db_all(
                "SELECT id, name, type, target_sets, target_reps, alternative_of
                   FROM exercises
                  WHERE workout_id = ? AND alternative_of IS NOT NULL AND deleted_at IS NULL
                  ORDER BY name",
                [$workout_id]
            );
            $by_primary = [];
            foreach ($alts as $a) {
                $a['target'] = build_target($a['target_sets'], $a['target_reps'], $a['type']);
                $by_primary[(int) $a['alternative_of']][] = $a;
            }

            foreach ($exercises as &$e) {
                $e['target'] = build_target($e['target_sets'], $e['target_reps'], $e['type']);
                $e['alternatives'] = $by_primary[(int) $e['id']] ?? [];
            }
            unset($e);

            $deleted = db_all(
                "SELECT id, name, target_sets, target_reps, deleted_at
                   FROM exercises
                  WHERE workout_id = ? AND alternative_of IS NULL AND deleted_at IS NOT NULL
                  ORDER BY deleted_at DESC",
                [$workout_id]
            );

            respond_ok([
                'workout'   => $workout,
                'exercises' => $exercises,
                'deleted'   => $deleted,
            ]);
            break;
        }

        // -- save_workout: crea (senza id) o rinomina (con id) ---------------
        case 'save_workout': {
            $user_id = requireLogin();
            $workout_id = (int) ($_POST['workout_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));

            if ($name === '') {
                respond_err('Il nome non può essere vuoto');
            }

            if ($workout_id > 0) {
                $own = db_one("SELECT id FROM workouts WHERE id = ? AND user_id = ?",
                    [$workout_id, $user_id]);
                if ($own === null) {
                    respond_err('Scheda non trovata');
                }
                db_run("UPDATE workouts SET name = ? WHERE id = ? AND user_id = ?",
                    [$name, $workout_id, $user_id]);
            } else {
                $pos = (int) db_value(
                    "SELECT COALESCE(MAX(position), 0) + 1 FROM workouts WHERE user_id = ?",
                    [$user_id]
                );
                $workout_id = db_insert(
                    "INSERT INTO workouts (user_id, name, position) VALUES (?, ?, ?)",
                    [$user_id, $name, $pos]
                );
            }

            respond_ok(array_merge(
                ['workout_id' => $workout_id],
                workouts_payload($user_id)
            ));
            break;
        }

        // -- delete_workout: cestino (o ripristino con restore=1) ------------
        case 'delete_workout': {
            $user_id = requireLogin();
            $workout_id = (int) ($_POST['workout_id'] ?? 0);
            $restore = ($_POST['restore'] ?? '') === '1';

            $own = db_one("SELECT id FROM workouts WHERE id = ? AND user_id = ?",
                [$workout_id, $user_id]);
            if ($own === null) {
                respond_err('Scheda non trovata');
            }

            if ($restore) {
                db_run("UPDATE workouts SET deleted_at = NULL WHERE id = ? AND user_id = ?",
                    [$workout_id, $user_id]);
            } else {
                db_run("UPDATE workouts SET deleted_at = NOW() WHERE id = ? AND user_id = ?",
                    [$workout_id, $user_id]);
            }

            respond_ok(workouts_payload($user_id));
            break;
        }

        // -- save_exercise: crea (senza id) o modifica (con id) --------------
        case 'save_exercise': {
            $user_id = requireLogin();
            $exercise_id = (int) ($_POST['exercise_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));

            if ($name === '') {
                respond_err('Il nome non può essere vuoto');
            }

            $sets = ($_POST['target_sets'] ?? '') === '' ? null : (int) $_POST['target_sets'];
            $reps = trim((string) ($_POST['target_reps'] ?? ''));
            $reps = $reps === '' ? null : $reps;
            $rest = ($_POST['rest_seconds'] ?? '') === '' ? 90 : (int) $_POST['rest_seconds'];

            // 'time' = esercizio a tempo: target_reps sono secondi di tenuta.
            $type = ($_POST['type'] ?? '') === 'time' ? 'time' : 'reps';

            $url = trim((string) ($_POST['url'] ?? ''));
            if ($url !== '' && !valid_url($url)) {
                respond_err('URL non valido (usa http:// o https://)');
            }
            $url = $url === '' ? null : $url;

            if ($exercise_id > 0) {
                $own = db_one(
                    "SELECT e.id FROM exercises e
                       JOIN workouts w ON w.id = e.workout_id
                      WHERE e.id = ? AND w.user_id = ?",
                    [$exercise_id, $user_id]
                );
                if ($own === null) {
                    respond_err('Esercizio non trovato');
                }
                db_run(
                    "UPDATE exercises
                        SET name = ?, type = ?, target_sets = ?, target_reps = ?,
                            rest_seconds = ?, url = ?
                      WHERE id = ?",
                    [$name, $type, $sets, $reps, $rest, $url, $exercise_id]
                );
            } else {
                $workout_id = (int) ($_POST['workout_id'] ?? 0);
                $own = db_one(
                    "SELECT id FROM workouts WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
                    [$workout_id, $user_id]
                );
                if ($own === null) {
                    respond_err('Scheda non trovata');
                }
                $pos = (int) db_value(
                    "SELECT COALESCE(MAX(position), 0) + 1 FROM exercises
                      WHERE workout_id = ? AND alternative_of IS NULL",
                    [$workout_id]
                );
                $exercise_id = db_insert(
                    "INSERT INTO exercises
                        (workout_id, name, type, target_sets, target_reps,
                         rest_seconds, url, position)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$workout_id, $name, $type, $sets, $reps, $rest, $url, $pos]
                );
            }

            respond_ok(['exercise_id' => $exercise_id]);
            break;
        }

        // -- delete_exercise: soft delete (o ripristino con restore=1) -------
        case 'delete_exercise': {
            $user_id = requireLogin();
            $exercise_id = (int) ($_POST['exercise_id'] ?? 0);
            $restore = ($_POST['restore'] ?? '') === '1';

            $own = db_one(
                "SELECT e.id FROM exercises e
                   JOIN workouts w ON w.id = e.workout_id
                  WHERE e.id = ? AND w.user_id = ?",
                [$exercise_id, $user_id]
            );
            if ($own === null) {
                respond_err('Esercizio non trovato');
            }

            if ($restore) {
                db_run("UPDATE exercises SET deleted_at = NULL WHERE id = ?", [$exercise_id]);
            } else {
                db_run("UPDATE exercises SET deleted_at = NOW() WHERE id = ?", [$exercise_id]);
            }

            respond_ok();
            break;
        }

        // -- reorder: riscrive le position (type = workout | exercise) -------
        case 'reorder': {
            $user_id = requireLogin();
            $type = (string) ($_POST['type'] ?? '');
            if ($type !== 'workout' && $type !== 'exercise') {
                respond_err('Tipo non valido');
            }

            $ids = array_values(array_filter(
                array_map('intval', explode(',', (string) ($_POST['ids'] ?? '')))
            ));
            if (!$ids) {
                respond_err('Nessun elemento da ordinare');
            }

            db()->beginTransaction();
            try {
                $pos = 1;
                foreach ($ids as $id) {
                    if ($type === 'workout') {
                        db_run("UPDATE workouts SET position = ? WHERE id = ? AND user_id = ?",
                            [$pos, $id, $user_id]);
                    } else {
                        db_run(
                            "UPDATE exercises e
                               JOIN workouts w ON w.id = e.workout_id
                                SET e.position = ?
                              WHERE e.id = ? AND w.user_id = ?",
                            [$pos, $id, $user_id]
                        );
                    }
                    $pos++;
                }
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                throw $e;
            }

            respond_ok($type === 'workout' ? workouts_payload($user_id) : null);
            break;
        }

        // -- get_history: limit, offset -> sessioni con dettaglio ------------
        case 'get_history': {
            $user_id = requireLogin();

            $limit  = (int) ($_POST['limit'] ?? 20);
            $offset = (int) ($_POST['offset'] ?? 0);
            $limit  = max(1, min(100, $limit));   // clamp difensivo
            $offset = max(0, $offset);

            // LIMIT/OFFSET vanno bindati come interi (emulate off li rifiuta
            // come stringa) — comunque parametrici, niente concatenazione.
            $stmt = db()->prepare(
                "SELECT id, workout_name, started_at, finished_at, notes
                   FROM workout_logs
                  WHERE user_id = :uid
                  ORDER BY started_at DESC, id DESC
                  LIMIT :limit OFFSET :offset"
            );
            $stmt->bindValue(':uid', $user_id, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $sessions = $stmt->fetchAll();

            // Dettaglio serie per ogni sessione della pagina.
            foreach ($sessions as &$s) {
                $s['sets'] = db_all(
                    "SELECT exercise_name, target, set_number, weight_kg,
                            reps_completed, logged_at
                       FROM exercise_logs
                      WHERE workout_log_id = ?
                      ORDER BY id",
                    [$s['id']]
                );
            }
            unset($s);

            respond_ok(['sessions' => $sessions]);
            break;
        }

        default:
            respond_err('Azione sconosciuta');
    }
} catch (Throwable $e) {
    // Nessun dettaglio verso il client (punto 5.9): logga e rispondi generico.
    error_log('api.php: ' . $e->getMessage());
    respond_err('Errore interno');
}
