<?php
// config.php — configurazione e credenziali per QUESTO ambiente.
//
// NON versionato (vedi .gitignore). Ogni ambiente (locale, produzione) ha il suo.
// Contiene segreti: non finisce mai in git, non si stampa mai.
//
// Lo legge app_config() in db.php.

return [
    'db_host' => '127.0.0.1',
    'db_name' => 'DBNAME',
    'db_user' => 'DBUSER',
    'db_pass' => 'DBPASSWORD',

    // Autoregistrazione: true = chiunque può creare un account dalla schermata
    // di accesso; false = il form sparisce e l'API rifiuta le registrazioni.
    // Se la riga manca vale true.
    'allow_registration' => true,
];
