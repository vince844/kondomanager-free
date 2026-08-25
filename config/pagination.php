<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Righe per pagina
    |--------------------------------------------------------------------------
    |
    | Il ripiego finale, quando né l'utente né l'amministratore hanno scelto.
    | La catena completa, risolta in App\Traits\PaginaElenco:
    |
    |   1. il `per_page` esplicito nella richiesta   (l'utente ha appena scelto)
    |   2. la scelta salvata dall'utente per quella tabella
    |   3. GeneralSettings::$default_per_page        (impostazioni generali)
    |   4. questo valore
    |
    */
    'default_per_page' => env('DEFAULT_PER_PAGE', 10),

    /*
    |--------------------------------------------------------------------------
    | I valori ammessi
    |--------------------------------------------------------------------------
    |
    | ⚠️ **Non è una comodità: è il tetto.** Fino alla beta.54 la regola era
    | `'per_page' => ['sometimes', 'integer']`, senza massimo: un `?per_page=1000000`
    | passava la validazione e finiva dritto in un `LIMIT`, con il server che prova a
    | costruire un milione di righe e il browser che le riceve. Non serviva malizia —
    | bastava un dito su uno zero.
    |
    | È anche la lista che il selettore mostra a video. Tenerla in un posto solo
    | evita la divergenza che c'era: il selettore offriva `[15,20,30,40,50]` mentre il
    | valore predefinito era 10, così chi si spostava a 50 non aveva più modo di
    | tornare indietro — l'opzione non esisteva nel menu.
    |
    */
    'consentite' => [10, 15, 20, 30, 40, 50, 100],
];
