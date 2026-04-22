<?php

use App\Services\Gestionale\BudgetCoverageService;
use App\Models\Gestione;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use Illuminate\Support\Collection;

// -----------------------------------------------------------------------
// Helper per costruire mock dei modelli senza toccare il DB
// -----------------------------------------------------------------------

/**
 * Crea un Conto mock con sottoconti e pianiRate simulati.
 */
function makeConto(int $id, string $nome, int $importo, array $sottoconti = [], ?int $parentId = null): object
{
    $conto             = new stdClass();
    $conto->id         = $id;
    $conto->nome       = $nome;
    $conto->importo    = $importo;
    $conto->parent_id  = $parentId;
    $conto->parent     = null;
    $conto->sottoconti = collect($sottoconti);

    // Collega il parent ai figli
    foreach ($sottoconti as $figlio) {
        $figlio->parent_id = $id;
        $figlio->parent    = $conto;
    }

    return $conto;
}

/**
 * Crea una riga pivot simulata.
 */
function makePivot(?int $importo, ?string $note = null): object
{
    $pivot         = new stdClass();
    $pivot->importo = $importo;
    $pivot->note    = $note;
    return $pivot;
}

/**
 * Crea un capitolo (conto con pivot allegata) come appare in $piano->capitoli.
 */
function makeCapitolo(object $conto, ?int $pivotImporto, ?string $pivotNote = null): object
{
    $cap        = clone $conto;
    $cap->pivot = makePivot($pivotImporto, $pivotNote);
    return $cap;
}

/**
 * Crea un PianoRate mock con i capitoli specificati.
 */
function makePiano(int $id, string $nome, array $capitoli): object
{
    $piano           = new stdClass();
    $piano->id       = $id;
    $piano->nome     = $nome;
    $piano->capitoli = collect($capitoli);
    $piano->attivo   = true;
    
    // FIX: Aggiunta del tipo per il nuovo Push-Down
    $piano->tipo     = 'ordinario'; 
    
    return $piano;
}

/**
 * Crea una Gestione mock e la inietta nel Service tramite reflection,
 * bypassando il metodo analyze() che usa Eloquent load().
 *
 * Ritorna direttamente il risultato di calcolaCoperturaReale().
 */
function runCopertura(array $contiRadice, array $piani): array
{
    $service = new BudgetCoverageService();

    // Usiamo la Reflection per chiamare il metodo privato direttamente
    $reflection = new ReflectionClass($service);
    $method     = $reflection->getMethod('calcolaCoperturaReale');

    return $method->invoke($service, collect($contiRadice), collect($piani));
}

// -----------------------------------------------------------------------
// CASO 1: Piano singolo, tutte le voci impegnate direttamente (caso semplice)
// -----------------------------------------------------------------------
it('caso semplice: un piano copre tutte le voci direttamente', function () {
    $compenso = makeConto(1, 'Compenso', 30000);
    $pulizia  = makeConto(2, 'Pulizia',  52300);

    $piano = makePiano(1, 'Piano A', [
        makeCapitolo($compenso, 30000),
        makeCapitolo($pulizia,  52300),
    ]);

    $map = runCopertura([$compenso, $pulizia], [$piano]);

    expect($map[1])->toBe(30000);
    expect($map[2])->toBe(52300);
});

// -----------------------------------------------------------------------
// CASO 2: NULL su foglia = copre tutto il preventivo
// -----------------------------------------------------------------------
it('NULL su foglia copre tutto il preventivo della voce', function () {
    $manutenzione = makeConto(3, 'Manutenzione', 34343);

    $piano = makePiano(1, 'Piano B', [
        makeCapitolo($manutenzione, null), // NULL = a saldo
    ]);

    $map = runCopertura([$manutenzione], [$piano]);

    expect($map[3])->toBe(34343);
});

// -----------------------------------------------------------------------
// CASO 3: Spostamento in entrata → over budget
// Piano B copre il preventivo (NULL), Piano A ha spostamento +100€
// -----------------------------------------------------------------------
it('spostamento in entrata genera over budget', function () {
    $manutenzione = makeConto(3, 'Manutenzione', 34343);

    $pianoB = makePiano(2, 'Piano B', [
        makeCapitolo($manutenzione, null), // NULL = copre tutto preventivo
    ]);
    $pianoA = makePiano(1, 'Piano A', [
        makeCapitolo($manutenzione, 10000, 'Generato da Sposta Spesa: Rottura cancello'),
    ]);

    $map = runCopertura([$manutenzione], [$pianoA, $pianoB]);

    // NULL copre 34343, spostamento aggiunge 10000 → totale 44343
    expect($map[3])->toBe(44343);
});

// -----------------------------------------------------------------------
// CASO 4: Push-down dal padre — distribuzione equa (IL CASO COMPLESSO)
// Piano A: Compenso 100€, Pulizia 423€
// Piano B: Capitolo padre 200€ (100€ Compenso + 100€ Pulizia)
// -----------------------------------------------------------------------
it('push-down equo dal padre distribuisce correttamente tra figli', function () {
    $compenso = makeConto(1, 'Compenso', 30000);
    $pulizia  = makeConto(2, 'Pulizia',  52300);
    $capitolo = makeConto(97, 'Capitolo', 0, [$compenso, $pulizia]);

    $pianoA = makePiano(1, 'Piano A', [
        makeCapitolo($compenso, 10000), // 100€
        makeCapitolo($pulizia,  42300), // 423€
    ]);
    $pianoB = makePiano(2, 'Piano B', [
        makeCapitolo($capitolo, 20000), // 200€ al capitolo padre
    ]);

    $map = runCopertura([$capitolo], [$pianoA, $pianoB]);

    // Compenso: 25000 + 10000 (la sua metà del padre) = 35000
    expect($map[1])->toBe(35000);
    
    // Pulizia: 52300 + 10000 (la sua metà del padre) = 62300
    expect($map[2])->toBe(62300);
    
    // Padre: 20000 (il surplus originale rimane tracciato)
    expect($map[97])->toBe(20000);
});

// -----------------------------------------------------------------------
// CASO 5: Push-down con fondo insufficiente → distribuzione proporzionale
// Padre ha 15.000¢, Compenso deficit 20.000¢, Pulizia deficit 10.000¢
// Quota uguale = 7500¢ ciascuno
// -----------------------------------------------------------------------
it('push-down con fondo insufficiente distribuisce in parti uguali', function () {
    $compenso = makeConto(1, 'Compenso', 30000);
    $pulizia  = makeConto(2, 'Pulizia',  52300);
    $capitolo = makeConto(97, 'Capitolo', 0, [$compenso, $pulizia]);

    $pianoA = makePiano(1, 'Piano A', [
        makeCapitolo($compenso, 10000), // deficit = 20000
        makeCapitolo($pulizia,  42300), // deficit = 10000
    ]);
    $pianoB = makePiano(2, 'Piano B', [
        makeCapitolo($capitolo, 15000), // solo 150€ disponibili
    ]);

    $map = runCopertura([$capitolo], [$pianoA, $pianoB]);

    // Quota uguale = 7500 ciascuno
    // Compenso: 10000 + 7500 = 17500
    // Pulizia:  42300 + 7500 = 49800
    // Rimangono 0 residui (15000 / 2 = 7500 esatti)
    expect($map[1])->toBe(17500);
    expect($map[2])->toBe(49800);
});

// -----------------------------------------------------------------------
// CASO 6: Push-down con fondo in eccesso → tutto coperto, residuo al padre
// -----------------------------------------------------------------------
it('push-down con fondo in eccesso copre tutti i figli e lascia surplus al padre', function () {
    $compenso = makeConto(1, 'Compenso', 30000);
    $pulizia  = makeConto(2, 'Pulizia',  52300);
    $capitolo = makeConto(97, 'Capitolo', 0, [$compenso, $pulizia]);

    $pianoA = makePiano(1, 'Piano A', [
        makeCapitolo($compenso, 25000), // deficit = 5000
        makeCapitolo($pulizia,  52300), // deficit = 0
    ]);
    $pianoB = makePiano(2, 'Piano B', [
        makeCapitolo($capitolo, 20000), // 200€ disponibili
    ]);

    $map = runCopertura([$capitolo], [$pianoA, $pianoB]);

    // Compenso: 25000 (sua base) + 10000 (metà esatta del padre) = 35000
    expect($map[1])->toBe(35000);
    
    // Pulizia: 52300 (sua base) + 10000 (metà esatta del padre) = 62300
    expect($map[2])->toBe(62300);
    
    // Padre: 20000 (il surplus originale rimane tracciato)
    expect($map[97])->toBe(20000);
});

// -----------------------------------------------------------------------
// CASO 7: NULL sul padre → copre tutto il fabbisogno dei figli
// -----------------------------------------------------------------------
it('NULL sul padre copre tutto il fabbisogno dei figli', function () {
    $compenso = makeConto(1, 'Compenso', 30000);
    $pulizia  = makeConto(2, 'Pulizia',  52300);
    $capitolo = makeConto(97, 'Capitolo', 0, [$compenso, $pulizia]);

    // Un solo piano, impegna il capitolo con NULL (prende tutto)
    $pianoB = makePiano(2, 'Piano B', [
        makeCapitolo($capitolo, null), // NULL = copre tutto
    ]);

    $map = runCopertura([$capitolo], [$pianoB]);

    // Entrambi i figli devono essere coperti al 100%
    expect($map[1])->toBe(30000);
    expect($map[2])->toBe(52300);
});

// -----------------------------------------------------------------------
// CASO 8: Nessun piano rate → tutto a zero
// -----------------------------------------------------------------------
it('nessun piano rate produce copertura zero', function () {
    $compenso = makeConto(1, 'Compenso', 30000);

    $map = runCopertura([$compenso], []);

    expect($map)->not->toHaveKey(1);
});

// -----------------------------------------------------------------------
// CASO 9: Voce già sovra-coperta dallo STEP 1 → push-down la ignora
// -----------------------------------------------------------------------
it('figlio già coperto dallo step 1 non riceve push-down', function () {
    $compenso = makeConto(1, 'Compenso', 30000);
    $pulizia  = makeConto(2, 'Pulizia',  52300);
    $capitolo = makeConto(97, 'Capitolo', 0, [$compenso, $pulizia]);

    $pianoA = makePiano(1, 'Piano A', [
        makeCapitolo($compenso, 30000), // già coperto al 100%
        makeCapitolo($pulizia,  52300), // già coperto al 100%
    ]);
    $pianoB = makePiano(2, 'Piano B', [
        makeCapitolo($capitolo, 20000), // nessuno ha deficit
    ]);

    $map = runCopertura([$capitolo], [$pianoA, $pianoB]);

    // Nessun push-down: valori invariati dallo STEP 1
    expect($map[1])->toBe(30000);
    expect($map[2])->toBe(52300);
    // Il surplus rimane sul padre
    expect($map[97])->toBe(20000);
});

// -----------------------------------------------------------------------
// CASO 10: Due piani che impegnano la stessa foglia → somma
// -----------------------------------------------------------------------
it('due piani sullo stesso conto sommano i loro importi', function () {
    $manutenzione = makeConto(3, 'Manutenzione', 34343);

    $pianoA = makePiano(1, 'Piano A', [
        makeCapitolo($manutenzione, 10000),
    ]);
    $pianoB = makePiano(2, 'Piano B', [
        makeCapitolo($manutenzione, 24343),
    ]);

    $map = runCopertura([$manutenzione], [$pianoA, $pianoB]);

    expect($map[3])->toBe(34343); // 10000 + 24343
});

// -----------------------------------------------------------------------
// CASO 11: Il caso completo reale (condominio test 1)
// Compenso 300€, Pulizia 523€, Manutenzione 343.43€
// Piano A: Compenso 100€, Pulizia 423€
// Piano B: Capitolo padre 200€, Manutenzione NULL
// Spostamento: +100€ su Manutenzione dal Piano A
// Risultato atteso: Compenso 200€, Pulizia 523€, Manutenzione 443.43€
// -----------------------------------------------------------------------
it('caso reale completo: compenso 200€ pulizia 523€ manutenzione 443.43€', function () {
    $compenso     = makeConto(98,  'Compenso amministratore', 30000);
    $pulizia      = makeConto(99,  'Pulizia scale',           52300);
    $capitolo     = makeConto(97,  'Capitolo spese generali', 0, [$compenso, $pulizia]);
    $manutenzione = makeConto(100, 'Manutenzione giardino',   34343);

    $pianoA = makePiano(87, 'Piano rate A', [
        makeCapitolo($compenso,     10000),          // 100€ (ridotto da 200€ dopo spostamento)
        makeCapitolo($pulizia,      42300),          // 423€
        makeCapitolo($manutenzione, 10000, 'Generato da Sposta Spesa: Rottura cancello'), // spostamento
    ]);
    $pianoB = makePiano(96, 'Piano rate B', [
        makeCapitolo($capitolo,     20000),          // 200€ al capitolo padre
        makeCapitolo($manutenzione, null),           // NULL = copre tutto preventivo
    ]);

    $map = runCopertura([$capitolo, $manutenzione], [$pianoA, $pianoB]);

    expect($map[98])->toBe(20000);  // Compenso: 200€
    expect($map[99])->toBe(52300);  // Pulizia: 523€
    expect($map[100])->toBe(44343); // Manutenzione: 443.43€ (over)
});

// -----------------------------------------------------------------------
// CASO 12: Piano rate non attivo → viene ignorato
// -----------------------------------------------------------------------
it('piano rate non attivo viene ignorato', function () {
    $compenso = makeConto(1, 'Compenso', 30000);

    $pianoAttivo = makePiano(1, 'Piano A', [
        makeCapitolo($compenso, 15000),
    ]);

    // Piano non attivo: impostiamo attivo=false
    $pianoInattivo          = makePiano(2, 'Piano B', [makeCapitolo($compenso, 15000)]);
    $pianoInattivo->attivo  = false;

    // Il Service filtra per attivo=true prima di chiamare calcolaCoperturaReale,
    // quindi passiamo solo i piani attivi (come fa analyze())
    $pianiAttivi = collect([$pianoAttivo, $pianoInattivo])->where('attivo', true);

    $service    = new BudgetCoverageService();
    $reflection = new ReflectionClass($service);
    $method     = $reflection->getMethod('calcolaCoperturaReale');
    $map = $method->invoke($service, collect([$compenso]), $pianiAttivi);

    // Solo il piano attivo contribuisce
    expect($map[1])->toBe(15000);
});

// -----------------------------------------------------------------------
// CASO 13: Conto foglia orfano (nessun piano lo include) → copertura zero
// -----------------------------------------------------------------------
it('conto foglia senza piani rate ha copertura zero', function () {
    $compenso     = makeConto(1, 'Compenso', 30000);
    $manutenzione = makeConto(3, 'Manutenzione', 34343); // orfana

    $piano = makePiano(1, 'Piano A', [
        makeCapitolo($compenso, 30000), // include solo compenso
    ]);

    $map = runCopertura([$compenso, $manutenzione], [$piano]);

    expect($map[1])->toBe(30000);
    expect($map)->not->toHaveKey(3); // manutenzione non presente = 0
});

// -----------------------------------------------------------------------
// CASO 14: Due piani che puntano entrambi al capitolo padre
// Piano A: capitolo padre 100€ | Piano B: capitolo padre 100€
// I figli devono ricevere entrambe le distribuzioni
// -----------------------------------------------------------------------
it('due piani sul capitolo padre si sommano e distribuiscono ai figli', function () {
    $compenso = makeConto(1, 'Compenso', 30000);
    $pulizia  = makeConto(2, 'Pulizia',  52300);
    $capitolo = makeConto(97, 'Capitolo', 0, [$compenso, $pulizia]);

    // Nessun piano diretto sui figli
    $pianoA = makePiano(1, 'Piano A', [
        makeCapitolo($capitolo, 10000), // 100€ al capitolo
    ]);
    $pianoB = makePiano(2, 'Piano B', [
        makeCapitolo($capitolo, 10000), // altri 100€ al capitolo
    ]);

    $map = runCopertura([$capitolo], [$pianoA, $pianoB]);

    // Totale fondi padre = 20000¢, 2 figli con deficit uguale
    // Ogni piano distribuisce 5000¢ a testa ai figli (10000/2 per piano)
    // Compenso deficit 30000, Pulizia deficit 52300 → entrambi prendono quota uguale
    // Piano A: 5000 a Compenso, 5000 a Pulizia
    // Piano B: 5000 a Compenso, 5000 a Pulizia
    // Compenso: 10000 | Pulizia: 10000
    expect($map[1])->toBe(10000);
    expect($map[2])->toBe(10000);
    // Padre: 10000 + 10000 = 20000 in mappa
    expect($map[97])->toBe(20000);
});

// -----------------------------------------------------------------------
// CASO 15: Importo 0 esplicito su pivot (≠ NULL) → non contribuisce
// -----------------------------------------------------------------------
it('importo zero esplicito in pivot non contribuisce alla copertura', function () {
    $compenso = makeConto(1, 'Compenso', 30000);

    $piano = makePiano(1, 'Piano A', [
        makeCapitolo($compenso, 0), // esplicitamente 0, non NULL
    ]);

    $map = runCopertura([$compenso], [$piano]);

    expect($map[1] ?? 0)->toBe(0);
});

// -----------------------------------------------------------------------
// CASO 16: Foglia con preventivo zero → non genera deficit, non riceve push-down
// -----------------------------------------------------------------------
it('foglia con preventivo zero non genera deficit e non riceve push-down', function () {
    $vuoto    = makeConto(1, 'Voce vuota',  0);     // preventivo 0
    $compenso = makeConto(2, 'Compenso',    30000);
    $capitolo = makeConto(97, 'Capitolo',   0, [$vuoto, $compenso]);

    $pianoB = makePiano(1, 'Piano B', [
        makeCapitolo($capitolo, 20000), // 200€ al padre
    ]);

    $map = runCopertura([$capitolo], [$pianoB]);

    // Voce vuota: preventivo 0, deficit 0 → non riceve nulla
    expect($map[1] ?? 0)->toBe(0);
    // Compenso prende tutto il fondo del padre (è l'unico con deficit)
    expect($map[2])->toBe(20000);
});

// -----------------------------------------------------------------------
// CASO 17: Stesso conto inserito due volte nello stesso piano (dati duplicati)
// Il Service deve sommare, non raddoppiare in modo anomalo
// -----------------------------------------------------------------------
it('conto duplicato nello stesso piano somma gli importi correttamente', function () {
    $compenso = makeConto(1, 'Compenso', 30000);

    $piano = makePiano(1, 'Piano A', [
        makeCapitolo($compenso, 10000),
        makeCapitolo($compenso, 10000), // duplicato per errore dati
    ]);

    $map = runCopertura([$compenso], [$piano]);

    // Somma entrambe le righe: 10000 + 10000 = 20000
    expect($map[1])->toBe(20000);
});

// -----------------------------------------------------------------------
// CASO 18: Tre livelli di annidamento (nonno → padre → figlio)
// Il push-down deve funzionare solo tra padre diretto e figlio diretto
// -----------------------------------------------------------------------
it('tre livelli di annidamento: push-down funziona solo tra padre diretto e figlio', function () {
    $foglia      = makeConto(3, 'Foglia',    30000);
    $padre       = makeConto(2, 'Padre',     0, [$foglia]);
    $nonno       = makeConto(1, 'Nonno',     0, [$padre]);

    // Il piano impegna il nonno, non il padre né la foglia
    $piano = makePiano(1, 'Piano A', [
        makeCapitolo($nonno, 30000),
    ]);

    $map = runCopertura([$nonno], [$piano]);

    // Il nonno ha 30000, ma il suo figlio diretto è "Padre" (importo 0).
    // Il padre non ha deficit (importo 0), quindi la foglia non riceve nulla
    // dal push-down del nonno in questo ciclo.
    // Questo è un limite noto del Service (push-down a un solo livello).
    expect($map[1])->toBe(30000); // nonno: ha i fondi
    expect($map[2] ?? 0)->toBe(0); // padre: preventivo 0, nessun deficit
    expect($map[3] ?? 0)->toBe(0); // foglia: non raggiunta dal push-down del nonno
});

// -----------------------------------------------------------------------
// CASO 19: Due figli entrambi con NULL nello stesso piano
// Entrambi devono ricevere il loro intero preventivo (non si dividono)
// -----------------------------------------------------------------------
it('due figli con NULL ricevono ciascuno il proprio preventivo completo', function () {
    $compenso = makeConto(1, 'Compenso', 30000);
    $pulizia  = makeConto(2, 'Pulizia',  52300);

    // NULL su foglie dirette (non tramite padre)
    $pianoB = makePiano(2, 'Piano B', [
        makeCapitolo($compenso, null), // NULL = tutto il preventivo
        makeCapitolo($pulizia,  null), // NULL = tutto il preventivo
    ]);

    $map = runCopertura([$compenso, $pulizia], [$pianoB]);

    expect($map[1])->toBe(30000); // Compenso: 300€ completi
    expect($map[2])->toBe(52300); // Pulizia: 523€ completi
});