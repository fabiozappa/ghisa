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
function build_target($sets, $reps): ?string
{
    $sets = ($sets === null || $sets === '') ? null : (int) $sets;
    $reps = ($reps === null || $reps === '') ? null : trim((string) $reps);
    if ($sets === null && $reps === null) {
        return null;
    }
    return ($sets ?? '?') . 'x' . ($reps ?? '?');
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
function build_summary(array $log, array $rows): string
{
    $lines = [$log['workout_name']];

    $by_ex = [];
    foreach ($rows as $r) {
        $name = $r['exercise_name'];
        if (!isset($by_ex[$name])) {
            $by_ex[$name] = ['target' => $r['target'], 'sets' => []];
        }
        $reps = ($r['reps_completed'] === null || $r['reps_completed'] === '')
            ? '—' : (int) $r['reps_completed'];
        $by_ex[$name]['sets'][] = fmt_weight($r['weight_kg']) . '×' . $reps;
    }

    foreach ($by_ex as $name => $info) {
        $target = $info['target'] ? " ({$info['target']})" : '';
        $lines[] = $name . $target . ': ' . implode(', ', $info['sets']);
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

            // Senza CRUD, il client deve sapere quali schede può aprire.
            // Riporta anche l'ultima sessione COMPLETATA per scheda (per i
            // "giorni fa" in home): solo finished_at valorizzato.
            $workouts = db_all(
                "SELECT w.id, w.name,
                        (SELECT MAX(wl.finished_at)
                           FROM workout_logs wl
                          WHERE wl.workout_id = w.id
                            AND wl.user_id = w.user_id
                            AND wl.finished_at IS NOT NULL) AS last_finished
                   FROM workouts w
                  WHERE w.user_id = ?
                  ORDER BY w.id",
                [$user_id]
            );

            respond_ok(['user_id' => $user_id, 'workouts' => $workouts]);
            break;
        }

        // -- get_workout: workout_id -> apre la sessione + scheda + ultimi pesi
        case 'get_workout': {
            $user_id = requireLogin();
            $workout_id = (int) ($_POST['workout_id'] ?? 0);

            $workout = db_one(
                "SELECT id, name FROM workouts WHERE id = ? AND user_id = ?",
                [$workout_id, $user_id]
            );
            if ($workout === null) {
                respond_err('Scheda non trovata');
            }

            // Esercizi vivi (soft delete escluso), in ordine di scheda.
            $exercises = db_all(
                "SELECT id, name, type, target_sets, target_reps, rest_seconds, position
                   FROM exercises
                  WHERE workout_id = ? AND deleted_at IS NULL
                  ORDER BY position, id",
                [$workout_id]
            );

            foreach ($exercises as &$ex) {
                $ex['target'] = build_target($ex['target_sets'], $ex['target_reps']);
                $ex['last_weight_kg'] = last_weight($user_id, (int) $ex['id'], $ex['name']);
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
                "SELECT e.name, e.target_sets, e.target_reps
                   FROM exercises e
                   JOIN workouts w ON w.id = e.workout_id
                  WHERE e.id = ? AND w.user_id = ?",
                [$exercise_id, $user_id]
            );
            if ($ex === null) {
                respond_err('Esercizio non trovato');
            }
            $target = build_target($ex['target_sets'], $ex['target_reps']);

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

            $summary = build_summary($log, $rows);

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
