<?php

use App\Enums\Permission;

/**
 * # I permessi sono una lista sola, scritta in due posti
 *
 * ## Il difetto che questa guardia esiste per prendere
 *
 * `App\Enums\Permission` decide **chi può**; `resources/js/enums/Permission.ts` decide **chi
 * vede**. Sono la stessa lista, e nessun compilatore le confronta: divergono in silenzio, e il
 * sintomo non è un errore ma una **funzione visibile e rotta** — la voce di menu compare, l'utente
 * clicca, e riceve un 403.
 *
 * Non è ipotetico. Il 30/08/2026, aggiungendo «Importa dati», la rotta è passata al permesso nuovo
 * e la voce di menu è rimasta appesa a «Crea condomini»: chi aveva il vecchio e non il nuovo
 * avrebbe visto l'importazione nel menu e preso un 403 aprendola. Se n'è accorto un `grep`, non un
 * test — e il progetto quel difetto lo aveva già descritto per un'altra rotta, in
 * `routes/web.php`, con le parole *«il pulsante compariva a chi poi riceveva un 403: una funzione
 * visibile e rotta per un ruolo costruito a mano»*.
 *
 * ## Cosa questa guardia NON copre
 *
 * - **Non verifica che ogni voce di menu usi il permesso giusto.** Dice che le due liste
 *   contengono le stesse chiavi, non che siano usate bene: una voce appesa al permesso sbagliato
 *   ma esistente le passa. Quello lo prende solo chi guarda.
 * - **Non copre il verso opposto in modo utile**: una chiave in più nel TypeScript è innocua
 *   (nessuno la userà), ma la si segnala lo stesso, perché è quasi sempre il residuo di un
 *   permesso tolto dal PHP e dimenticato di là.
 */
it('la lista dei permessi del frontend è la stessa del backend', function () {
    $ts = file_get_contents(dirname(__DIR__, 3).'/resources/js/enums/Permission.ts');

    preg_match_all("#^\s{2}[A-Z_]+\s*=\s*'([^']+)',#m", $ts, $trovati);
    $nelFrontend = $trovati[1];

    // ⚠️ Senza questa riga la guardia diventa verde il giorno che l'espressione smette di
    // riconoscere il file — la forma di guasto che si presenta come un successo.
    expect(count($nelFrontend))->toBeGreaterThan(20,
        'Il riconoscitore non trova più i permessi in resources/js/enums/Permission.ts: '.
        'va aggiornata l\'espressione, non allentata questa soglia.'
    );

    $nelBackend = array_column(Permission::cases(), 'value');

    $mancanti = array_values(array_diff($nelBackend, $nelFrontend));
    $inPiu = array_values(array_diff($nelFrontend, $nelBackend));

    expect($mancanti)->toBe([],
        "Questi permessi esistono in `App\\Enums\\Permission` e non in `resources/js/enums/Permission.ts`:\n".
        implode(', ', $mancanti)."\n\n".
        'Finché manca di là, nessuna schermata può mostrare o nascondere ciò che quel permesso governa.'
    );

    expect($inPiu)->toBe([],
        "Questi permessi esistono solo nel frontend:\n".implode(', ', $inPiu)."\n\n".
        'Di solito è il residuo di un permesso tolto dal PHP: una schermata continua a nasconderne '.
        'qualcosa sulla base di una chiave che a database non esiste più, quindi nessuno ce l\'ha.'
    );
});
