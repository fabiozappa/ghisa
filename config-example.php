<?php
// config.php — configurazione e credenziali per QUESTO ambiente.
//
// NON versionato (vedi .gitignore). Ogni ambiente (locale, produzione) ha il suo.
// Contiene segreti: non finisce mai in git, non si stampa mai.
//
// db.php fa: $config = require __DIR__ . '/config.php';

return [
    'db_host' => '127.0.0.1',
    'db_name' => 'DBNAME',
    'db_user' => 'DBUSER',
    'db_pass' => 'DBPASSWORD',
];
