<?php

/**
 * Impostazioni dell'installazione che arrivano dall'ambiente: chi ospita KondoManager le decide,
 * chi lo usa le subisce. Nascono per l'immagine Docker (dove il codice non si ritocca), ma
 * valgono ovunque ci sia un `.env`.
 *
 * Chi legge queste chiavi lo fa sempre con un ripiego: un `config:cache` fatto prima che questo
 * file esistesse non le contiene (vedi `ChiaviDiConfigurazioneRecentiConRipiegoTest`).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Tetto al numero di condomini
    |--------------------------------------------------------------------------
    |
    | Quanti condomini può avere questa installazione. Assente, vuoto o 0 = nessun limite (ed è
    | il caso di ogni installazione autonoma). Lo applica `CondominioService` alla creazione di un
    | condominio, dimostrativo compreso; non lo applica l'importatore, che porta dentro un archivio
    | esistente e non lo crea. Chi ospita il servizio lo imposta in base al piano.
    */
    'limite_condomini' => (int) env('LIMITE_CONDOMINI', 0),

    /*
    |--------------------------------------------------------------------------
    | Tetto allo spazio dei documenti
    |--------------------------------------------------------------------------
    |
    | In megabyte. Assente, vuoto o 0 = nessun limite. Si **mostra** (pagina «Questa
    | installazione») e non blocca i caricamenti: chi ospita il servizio misura e avvisa da fuori.
    | L'uso si somma dalla colonna `documenti.file_size`, che dice il vero (roadmap, Coda 55).
    */
    'limite_spazio_mb' => (int) env('LIMITE_SPAZIO_MB', 0),

    /*
    |--------------------------------------------------------------------------
    | Dove vivono i documenti
    |--------------------------------------------------------------------------
    |
    | `DOCUMENTI_DISK`: `local` (predefinito) = i dischi `local` e `public` di sempre, stesse
    | cartelle (`storage/app/private`, `storage/app/public`); `s3` = i dischi `documenti_s3` e
    | `pubblici_s3` di config/filesystems.php, cioè un bucket S3-compatibile con le `AWS_*` e il
    | prefisso `DOCUMENTI_PREFIX`. Cambiare il valore NON sposta i file già caricati.
    |
    | `disco_documenti_richiesto` è il valore scritto nella variabile, com'è; i due dischi sotto
    | sono quelli effettivi. Un valore che non è né `local` né `s3` finisce su `local`, e
    | `kondomanager:verifica-persistenza` lo dice con un avviso (`/up` riporta solo dove stanno i
    | documenti: `esterni`, `su_volume`, `effimeri`): meglio di un file che sparisce.
    */
    'disco_documenti_richiesto' => (string) env('DOCUMENTI_DISK', 'local'),
    'disco_documenti' => env('DOCUMENTI_DISK', 'local') === 's3' ? 'documenti_s3' : 'local',
    'disco_pubblici' => env('DOCUMENTI_DISK', 'local') === 's3' ? 'pubblici_s3' : 'public',

    /*
    |--------------------------------------------------------------------------
    | Dove si gestisce il piano
    |--------------------------------------------------------------------------
    |
    | Un indirizzo, facoltativo. Se c'è, la pagina «Questa installazione» mostra il collegamento
    | «Gestisci il tuo piano» accanto ai limiti; se manca, la pagina non parla di piani. Chi ospita
    | il servizio lo punta alla sua area cliente; chi si autoospita lo lascia vuoto o lo punta
    | dove vuole.
    */
    'gestione_piano_url' => env('GESTIONE_PIANO_URL'),

    /*
    |--------------------------------------------------------------------------
    | Siamo nell'immagine Docker?
    |--------------------------------------------------------------------------
    |
    | `KM_CONTAINER=1` lo mette il Dockerfile dell'immagine canonica. Cambia una cosa sola: nel
    | container i documenti su disco locale senza un volume sono **effimeri** (spariscono alla
    | ricreazione) e `/up` lo dice rispondendo 503; fuori dal container «non su volume» è la
    | normalità di ogni server. Una variabile, non un'euristica su /.dockerenv.
    */
    'container' => filter_var(env('KM_CONTAINER', false), FILTER_VALIDATE_BOOL),

];
