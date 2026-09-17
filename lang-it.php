<?php
// lang-it.php — testi dell'interfaccia in italiano.
//
// È la lingua di RISERVA: ogni chiave deve esistere qui. Le altre lingue
// possono restare indietro, e dove manca una chiave compare questo testo.
//
// Chiavi in inglese e minuscolo, nella forma "schermata.cosa".
// Segnaposto tra graffe, es. {n} o {name}: l'ordine delle parole lo decide la
// frase, mai il codice che la compone.
// Plurali: due chiavi "<chiave>.one" e "<chiave>.other", lette da trn().
// Virgolette doppie ovunque, così "\n" va a capo davvero e gli apostrofi non
// vanno escapati. Dentro i testi niente virgolette doppie: si usano « ».

return [
    // --- Lingua ---------------------------------------------------------------
    // Nome della lingua scritto nella lingua stessa: serve ai selettori.
    "lang.name"   => "Italiano",
    // Per og:locale della pagina pubblica.
    "lang.locale" => "it_IT",

    // --- Documenti legali -----------------------------------------------------
    "doc.terms"       => "Termini d'uso",
    "doc.terms_url"   => "terms-it.html",
    "doc.privacy"     => "Privacy",
    "doc.privacy_url" => "privacy-it.html",

    // --- Comuni ---------------------------------------------------------------
    "common.error"           => "Errore.",
    "common.network_error"   => "Errore di rete. Riprova.",
    "common.need_network"    => "Serve la rete.",
    "common.need_name"       => "Serve il nome.",
    "common.loading"         => "Carico…",
    "common.home"            => "← Home",
    "common.copied"          => "✓ Copiato",
    "common.name"            => "Nome",
    "common.sets"            => "Serie",
    "common.reps"            => "Ripetizioni",
    "common.link_optional"   => "Link (opzionale)",
    "common.back_to_workout" => "Torna all'allenamento",
    "common.view_exercise"   => "Vedi esercizio ↗",
    "common.first_word"      => "Prima parola",
    "common.second_word"     => "Seconda parola",
    "common.account"         => "Account",
    "common.manage_workouts" => "Gestisci schede",
    "common.workout"         => "Scheda",
    "common.cancel"          => "Annulla",
    "common.save"            => "Salva",

    // --- Accesso e registrazione ----------------------------------------------
    "login.username"    => "Nome utente",
    "login.submit"      => "Entra",
    "login.to_register" => "Non hai un account? Registrati",
    "login.failed"      => "Accesso non riuscito",
    "reg.hint"          => "Due parole che ricordi facilmente, almeno 3 lettere ciascuna. Serviranno per entrare, insieme al nome utente.",
    // {terms} e {privacy} diventano i link ai documenti.
    "reg.accept"        => "Creando un account accetti i {terms} e la {privacy}. Il servizio è fornito così com'è: nessuna garanzia, nessun backup, nessuna assistenza.",
    "reg.submit"        => "Crea account",
    "reg.to_login"      => "Hai già un account? Entra",
    "reg.failed"        => "Registrazione non riuscita",
    "app.logout"        => "Esci",

    // --- Coda offline e annulla serie -----------------------------------------
    "queue.count.one"   => "{n} set in coda",
    "queue.count.other" => "{n} set in coda",
    "queue.rejected"    => "Un set non è stato accettato dal server.",
    "undo.none"         => "Nessuna serie da annullare.",
    "undo.confirm"      => "Annullare l'ultima serie registrata?",
    "undo.done"         => "Serie annullata",

    // --- Home -----------------------------------------------------------------
    "home.title"              => "Le tue schede",
    "home.never"              => "mai fatta",
    "home.today"              => "oggi",
    "home.yesterday"          => "ieri",
    "home.days_ago.one"       => "{n} giorno fa",
    "home.days_ago.other"     => "{n} giorni fa",
    "home.empty_edit"         => "Nessuna scheda. Creane una qui sotto.",
    "home.empty"              => "Nessuna scheda. Vai su «Gestisci schede» per crearne una.",
    "home.new_workout"        => "+ Nuova scheda",
    "home.import"             => "Importa da un link",
    "home.done"               => "Fine",
    "home.history"            => "Storico allenamenti",
    "home.hide_deleted"       => "Nascondi eliminate",
    "home.show_deleted"       => "Mostra eliminate ({n})",
    "home.trash_note"         => "Le eliminate spariscono per sempre dopo 30 giorni.",
    "home.new_name_prompt"    => "Nome della nuova scheda:",
    "home.created"            => "Scheda creata",
    "home.trash_confirm"      => "Spostare «{name}» nel cestino?\nSparirà per sempre dopo 30 giorni. Lo storico resta comunque.",
    "home.trashed"            => "Spostata nel cestino",
    "home.restored"           => "Ripristinata",
    "home.need_network_start" => "Serve la rete per iniziare un allenamento.",
    "home.open_failed"        => "Impossibile aprire la scheda.",

    // --- Player ---------------------------------------------------------------
    "player.progress"     => "Esercizio {n} di {total}",
    "player.target"       => "Obiettivo {target}",
    "player.set"          => "Serie {n} di {total}",
    "player.weight"       => "Peso (kg)",
    "player.seconds"      => "Secondi",
    "player.next"         => "Avanti",
    "player.add_set"      => "+ Serie",
    "player.skip"         => "Salta esercizio",
    "player.alternative"  => "Alternativa",
    "player.undo"         => "↶ Annulla serie",
    "player.rest"         => "Recupero",
    "player.rest_skip"    => "salta",
    "player.finish"       => "Termina allenamento",
    "player.cancel"       => "Annulla allenamento",
    "player.exlist_title" => "Esercizi della scheda",
    "player.exlist_hint"  => "Tocca un esercizio per farlo adesso.",
    "player.set_logged"   => "✓ Serie registrata",
    "player.set_added"    => "Serie aggiunta",
    "player.skipped"      => "✓ Saltato",
    "player.last_time"    => "Ultima volta: {sets}",
    "player.rest_over"    => "Recupero finito",
    "player.stopped_at"   => "Fermato a {s}s",
    "player.set_seconds"  => "Imposta i secondi nella scheda.",
    "player.time_up"      => "Tempo!",
    "player.work_stop"    => "■ Ferma — {s}s",
    "player.work_start_s" => "▶ Avvia {s}s",
    "player.work_start"   => "▶ Avvia",

    // --- Alternative ----------------------------------------------------------
    "alt.need_network"     => "Serve la rete per gestire le alternative.",
    "alt.instead_of"       => "Al posto di {name}",
    "alt.used"             => "Già usate",
    "alt.new"              => "Nuova alternativa",
    "alt.use"              => "Usa questa alternativa",
    "alt.need_network_add" => "Serve la rete per aggiungere un'alternativa.",
    "alt.replaced_with"    => "Sostituito con {name}",

    // --- Fine allenamento -----------------------------------------------------
    "finish.title"            => "Fine allenamento",
    "finish.substitutions"    => "Sostituzioni",
    "finish.make_permanent"   => "Rendi «{name}» definitivo al posto di «{primary}»",
    "finish.note"             => "Nota (opzionale)",
    "finish.note_placeholder" => "Come è andata, dolori, energia…",
    "finish.save"             => "Salva e chiudi",
    "finish.sub_not_saved"    => "Sostituzione non salvata (offline).",
    "finish.offline"          => "Sei offline: l'allenamento verrà chiuso alla riconnessione.",
    "finish.cancel_confirm"   => "Annullare l'allenamento? Non verrà salvato nulla.",
    "finish.cancelled"        => "Allenamento annullato.",
    "finish.saved"            => "Allenamento salvato",
    "finish.copy"             => "📋 Copia riepilogo",
    "finish.copy_failed"      => "Copia non riuscita: testo selezionato, copialo a mano.",
    "finish.home"             => "Torna alla home",
    // Riga della nota nel riepilogo generato dal server.
    "summary.note"            => "Nota: {text}",

    // --- Editor della scheda --------------------------------------------------
    "edit.need_network" => "Serve la rete per modificare la scheda.",
    "edit.title"        => "Modifica scheda",
    "edit.name"         => "Nome della scheda",
    "edit.save_name"    => "Salva nome",
    "edit.exercises"    => "Esercizi",
    "edit.add_exercise" => "+ Aggiungi esercizio",
    "edit.hide_deleted" => "Nascondi eliminati",
    "edit.show_deleted" => "Mostra eliminati ({n})",
    "edit.trash_note"   => "Gli eliminati spariscono per sempre dopo 30 giorni.",
    // Riga sotto il nome dell'esercizio: target · recupero · link · alternative.
    "edit.meta_rest"    => "rec {s}s",
    "edit.meta_link"    => "link",
    "edit.meta_alt"     => "{n} alt",

    // --- Esercizio ------------------------------------------------------------
    "ex.edit_title"         => "Modifica esercizio",
    "ex.new_title"          => "Nuovo esercizio",
    "ex.type"               => "Tipo",
    "ex.type_reps"          => "A ripetizioni",
    "ex.type_time"          => "A tempo (secondi)",
    "ex.rest"               => "Recupero (secondi)",
    "ex.hold_seconds"       => "Secondi di tenuta",
    "ex.saved"              => "Salvato",
    "ex.alternatives_title" => "Alternative di questo esercizio",
    "ex.alternatives_note"  => "Sono le sostituzioni già usate al posto di questo esercizio. Toglierne una la fa sparire dalle proposte.",
    "ex.name_empty"         => "Il nome non può essere vuoto.",
    "ex.name_updated"       => "Nome aggiornato",
    "ex.trash_confirm"      => "Eliminare «{name}»?\nResta nel cestino 30 giorni. Lo storico non viene toccato.",
    "ex.trashed"            => "Spostato nel cestino",
    "ex.alt_remove_confirm" => "Togliere «{name}» dalle alternative?",
    "ex.alt_removed"        => "Alternativa rimossa",
    "ex.restored"           => "Ripristinato",

    // --- Condivisione e import ------------------------------------------------
    "share.title"          => "Condivisione",
    "share.off_note"       => "Non condivisa. Attivandola ottieni un link da mandare a chi vuoi: chi ce l'ha vede la scheda e può importarla.",
    "share.enable"         => "Attiva condivisione",
    "share.copy_link"      => "📋 Copia link",
    "share.copy_failed"    => "Copia non riuscita.",
    "share.revoke"         => "Revoca condivisione",
    "share.revoke_confirm" => "Revocare la condivisione?\nIl link smetterà di funzionare. Chi l'ha già importata tiene la sua copia.",
    "share.enabled"        => "Condivisione attiva",
    "share.revoked"        => "Condivisione revocata",
    "import.prompt"        => "Incolla il link o il codice della scheda condivisa:",
    "import.need_code"     => "Serve il codice.",
    "import.done"          => "Scheda importata",
    "import.confirm"       => "Importare la scheda condivisa nel tuo account?",

    // --- Account --------------------------------------------------------------
    // {name} viene mostrato in grassetto.
    "account.signed_in_as"   => "Sei entrato come {name}",
    "account.warning"        => "Non chiediamo email né altri dati personali. Per questo le credenziali NON sono recuperabili: se dimentichi il nome utente o le due parole, l'account e i suoi dati non sono più raggiungibili da nessuno. Annotale in un posto sicuro.",
    "account.delete_title"   => "Elimina account",
    "account.delete_text"    => "Cancella definitivamente l'account e tutto ciò che contiene: schede, esercizi e storico degli allenamenti. Non è recuperabile. Le schede che altri hanno importato restano loro.",
    "account.delete_button"  => "Elimina definitivamente",
    "account.need_words"     => "Inserisci le due parole per confermare.",
    "account.delete_confirm" => "Eliminare definitivamente l'account?\n\nSpariscono schede, esercizi e tutto lo storico. Non si può annullare e non si può recuperare.",

    // --- Storico --------------------------------------------------------------
    "history.title"        => "Storico",
    "history.need_network" => "Serve la rete per vedere lo storico.",
    "history.load_failed"  => "Errore nel caricare lo storico.",
    "history.empty"        => "Ancora nessun allenamento registrato.",

    // --- Pagina pubblica ------------------------------------------------------
    "public.not_found_title" => "Scheda non disponibile — Ghisa",
    "public.not_found_desc"  => "Questa scheda di allenamento non esiste o la condivisione è stata revocata.",
    "public.not_found_text"  => "Questa scheda non esiste, oppure la condivisione è stata revocata da chi l'aveva pubblicata.",
    "public.title"           => "{name} — scheda di allenamento | Ghisa",
    "public.desc.one"        => "{n} esercizio: {list}",
    "public.desc.other"      => "{n} esercizi: {list}",
    "public.go"              => "Vai a Ghisa",
    "public.kicker"          => "Scheda di allenamento condivisa",
    "public.empty"           => "Questa scheda non ha ancora esercizi.",
    "public.rest"            => "recupero {s}s",
    "public.import"          => "Importa questa scheda in Ghisa",
    // {brand} diventa «Ghisa» in grassetto.
    "public.foot"            => "{brand} è un'app per seguire le proprie schede di allenamento dal telefono, e tenere lo storico dei carichi.",

    // --- Errori dal server ----------------------------------------------------
    "err.session_expired"          => "Sessione scaduta",
    "err.username_length"          => "Il nome utente deve avere fra 3 e 50 caratteri",
    "err.username_chars"           => "Il nome utente può contenere solo lettere, numeri, punto, trattino e underscore",
    "err.words_length"             => "Ognuna delle due parole deve avere almeno 3 caratteri",
    "err.username_taken"           => "Nome utente già in uso",
    "err.too_many_attempts"        => "Troppi tentativi. Riprova fra qualche minuto.",
    "err.invalid_credentials"      => "Credenziali non valide",
    "err.words_mismatch"           => "Le due parole non corrispondono",
    "err.workout_not_found"        => "Scheda non trovata",
    "err.session_not_found"        => "Sessione non trovata",
    "err.exercise_not_found"       => "Esercizio non trovato",
    "err.primary_not_found"        => "Esercizio titolare non trovato",
    "err.alternative_not_found"    => "Alternativa non trovata",
    "err.alternative_name_missing" => "Nome alternativa mancante",
    "err.client_uid_invalid"       => "client_uid mancante o non valido",
    "err.client_uid_missing"       => "client_uid mancante",
    "err.set_number_invalid"       => "set_number non valido",
    "err.url_invalid"              => "URL non valido (usa http:// o https://)",
    "err.name_empty"               => "Il nome non può essere vuoto",
    "err.type_invalid"             => "Tipo non valido",
    "err.nothing_to_reorder"       => "Nessun elemento da ordinare",
    "err.share_code_invalid"       => "Codice non valido o condivisione revocata",
    "err.unknown_action"           => "Azione sconosciuta",
    "err.internal"                 => "Errore interno",
];
