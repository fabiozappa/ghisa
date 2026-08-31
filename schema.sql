-- schema.sql — Web App Schede Allenamento
--
-- Schema completo del database. Fonte di verità.
-- Rilanciabile in sviluppo: i DROP in testa ripuliscono tutto.
--
-- Regole chiave (vedi CLAUDE.md punto 4):
--  - I log (workout_logs, exercise_logs) sono fatti storici immutabili e
--    autosufficienti: FK verso l'anagrafica in ON DELETE SET NULL + colonne
--    nullable, e nome scheda / nome esercizio / target denormalizzati dentro il log.
--  - client_uid UNIQUE su exercise_logs per l'idempotenza della coda offline.
--  - exercises: soft delete (deleted_at) e rest_seconds usato dal timer.
--  - Indice su workout_logs(user_id, started_at) per lo storico.

-- I DROP vanno dal figlio al padre per non violare le foreign key.
DROP TABLE IF EXISTS exercise_logs;
DROP TABLE IF EXISTS workout_logs;
DROP TABLE IF EXISTS exercises;
DROP TABLE IF EXISTS workouts;
DROP TABLE IF EXISTS users;


-- ---------------------------------------------------------------------------
-- users — anagrafica utenti. Schema multi-utente, ma in v1 utente singolo.
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(50)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,          -- password_hash() della passphrase
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- workouts — le schede (anagrafica). Appartengono a un utente.
-- ---------------------------------------------------------------------------
CREATE TABLE workouts (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    name       VARCHAR(120) NOT NULL,
    share_code VARCHAR(36)  DEFAULT NULL,          -- esiste ma senza UI in v1
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_workouts_user (user_id),
    -- CASCADE è ammesso: workouts è anagrafica, non un log.
    CONSTRAINT fk_workouts_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- exercises — esercizi di una scheda (anagrafica). Soft delete.
--   target_sets + target_reps prescrivono il volume; da qui si compone
--   la stringa "3x10" che viene denormalizzata nel log al momento della serie.
-- ---------------------------------------------------------------------------
CREATE TABLE exercises (
    id             INT UNSIGNED         NOT NULL AUTO_INCREMENT,
    workout_id     INT UNSIGNED         NOT NULL,
    name           VARCHAR(120)         NOT NULL,
    type           ENUM('reps','time')  NOT NULL DEFAULT 'reps',  -- in v1 solo 'reps'
    target_sets    TINYINT UNSIGNED     DEFAULT NULL,             -- es. 3
    target_reps    VARCHAR(20)          DEFAULT NULL,             -- stringa: "10", "8-12", "max"
    rest_seconds   SMALLINT UNSIGNED    NOT NULL DEFAULT 90,      -- timer di recupero
    url            VARCHAR(500)         DEFAULT NULL,             -- link esplicativo (immagine/video/pagina)
    position       SMALLINT UNSIGNED    NOT NULL DEFAULT 0,       -- ordine nella scheda
    alternative_of INT UNSIGNED         DEFAULT NULL,             -- se valorizzato: alternativa dello slot di questo esercizio
    deleted_at     DATETIME             DEFAULT NULL,             -- soft delete, mai DELETE fisico
    created_at     DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_exercises_workout (workout_id),
    KEY idx_exercises_alt (alternative_of),
    -- CASCADE ammesso: exercises è anagrafica. La cancellazione vera è soft (deleted_at).
    CONSTRAINT fk_exercises_workout FOREIGN KEY (workout_id)
        REFERENCES workouts (id) ON DELETE CASCADE,
    -- Auto-collegamento slot: un'alternativa punta al suo titolare. Se il titolare
    -- sparisse fisicamente sparirebbero le alternative (ma qui si usa soft delete).
    CONSTRAINT fk_exercises_alt FOREIGN KEY (alternative_of)
        REFERENCES exercises (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- workout_logs — una sessione di allenamento (FATTO STORICO, immutabile).
--   workout_id è NULLABLE e in ON DELETE SET NULL: se cancello la scheda,
--   il log resta e continua a raccontare la sessione grazie a workout_name.
-- ---------------------------------------------------------------------------
CREATE TABLE workout_logs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,               -- il log appartiene sempre a un utente
    workout_id   INT UNSIGNED DEFAULT NULL,           -- FK debole verso l'anagrafica
    workout_name VARCHAR(120) NOT NULL,               -- snapshot denormalizzato del nome scheda
    started_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at  DATETIME     DEFAULT NULL,
    notes        TEXT         DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_workout_logs_user_started (user_id, started_at),  -- hot path dello storico
    -- RESTRICT: non si cancella un utente che ha storico. I log non spariscono.
    CONSTRAINT fk_workout_logs_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE RESTRICT,
    -- SET NULL: la scheda può sparire, il log no.
    CONSTRAINT fk_workout_logs_workout FOREIGN KEY (workout_id)
        REFERENCES workouts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- exercise_logs — una singola serie eseguita (FATTO STORICO, immutabile).
--   Distingue due cose:
--     * cosa la scheda prescriveva  -> exercise_name, target  (denormalizzati)
--     * cosa ho davvero fatto       -> weight_kg, reps_completed, set_number
--   client_uid UNIQUE rende idempotente il reinvio della coda offline.
-- ---------------------------------------------------------------------------
CREATE TABLE exercise_logs (
    id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    workout_log_id INT UNSIGNED      NOT NULL,            -- a quale sessione appartiene
    exercise_id    INT UNSIGNED      DEFAULT NULL,        -- FK debole verso l'anagrafica
    exercise_name  VARCHAR(120)      NOT NULL,            -- snapshot del nome esercizio
    target         VARCHAR(40)       DEFAULT NULL,        -- snapshot della prescrizione, es. "3x10"
    set_number     TINYINT UNSIGNED  NOT NULL,            -- numero della serie (1, 2, 3, ...)
    weight_kg      DECIMAL(5,2)      DEFAULT NULL,        -- peso realmente usato
    reps_completed SMALLINT UNSIGNED DEFAULT NULL,        -- reps realmente eseguite
    client_uid     VARCHAR(36)       NOT NULL,            -- generato dal client, idempotenza
    logged_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exercise_logs_client_uid (client_uid),  -- il reinvio duplicato fallisce qui
    KEY idx_exercise_logs_wlog (workout_log_id),
    KEY idx_exercise_logs_exercise (exercise_id),
    -- RESTRICT: una sessione con serie dentro non si cancella. Log su log, entrambi protetti.
    CONSTRAINT fk_exercise_logs_wlog FOREIGN KEY (workout_log_id)
        REFERENCES workout_logs (id) ON DELETE RESTRICT,
    -- SET NULL: l'esercizio può sparire, la serie registrata no.
    CONSTRAINT fk_exercise_logs_exercise FOREIGN KEY (exercise_id)
        REFERENCES exercises (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
