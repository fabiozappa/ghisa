<?php
// db.php — connessione PDO condivisa e piccoli helper di query.
//
// Raccolta di funzioni, niente OOP: qui non serve.
// Regola ferrea (CLAUDE.md punto 5): ogni query passa da prepared statement.
// Gli helper esistono apposta perché scrivere SQL a mano resti sempre parametrico.

// Ritorna la connessione PDO, creata una volta sola (singleton via static).
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $config = require __DIR__ . '/config.php';

        $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,      // prepared statement veri
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], $options);
    }

    return $pdo;
}

// Prepara ed esegue una query parametrica. Ritorna lo statement.
// $params è sempre un array: nessuna variabile finisce mai concatenata nell'SQL.
function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// Ritorna tutte le righe (array di array associativi). [] se nessuna.
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

// Ritorna la prima riga (array associativo), oppure null se nessuna.
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

// Ritorna il primo valore della prima riga (scalare), oppure null se nessuna.
// Comodo per SELECT di un singolo campo, es. l'ultimo peso di un esercizio.
function db_value(string $sql, array $params = [])
{
    $value = db_run($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

// Esegue un INSERT e ritorna l'id generato.
function db_insert(string $sql, array $params = []): int
{
    db_run($sql, $params);
    return (int) db()->lastInsertId();
}
