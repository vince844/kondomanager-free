<?php

/**
 * Le due bonifiche del §0 di `calendario_rate.md`.
 *
 * Sono indipendenti fra loro e dal resto della funzione, e valgono a prescindere: la
 * specifica le mette per prime proprio perché non aspettano nessuna decisione.
 *
 *  1. **Il giorno di scadenza nasce a 1 invece che a 5.** `PianoRateCreatorService` scrive
 *     `$data['giorno_scadenza'] ?? 1`, mentre lo schema dichiara `default('5')` — verificato a
 *     database. Il `?? 1` non lascia mai parlare il default: chi non indica il giorno ottiene
 *     un piano che scade il primo del mese invece che il cinque.
 *
 *  2. **Il controllo anti-duplicato degli eventi chiude sulla data.** In
 *     `SyncScadenziarioWithPianoRate` l'esistenza di un evento si verifica su
 *     `start_time` **più** `rata_id` **più** anagrafica. Il `rata_id` da solo identifica già
 *     la rata: aggiungere la data significa che, il giorno in cui una scadenza si sposta, il
 *     controllo non riconosce più l'evento che c'era e ne crea un secondo. Oggi è latente
 *     perché le date non si spostano; questa beta è la beta che le fa spostare.
 */

use App\Services\PianoRateCreatorService;

require_once __DIR__.'/GestionaleTestHelpers.php';

// ════════════════════════════════════════════════════════════════════════════
// 1. Il giorno di scadenza: parla il default del database
// ════════════════════════════════════════════════════════════════════════════

test('senza giorno indicato il piano nasce al giorno dichiarato dallo schema, non a 1', function () {
    [$condominio, $esercizio, $gestione] = setupContabile();

    $piano = (new PianoRateCreatorService)->creaPianoRate([
        'gestione_id' => $gestione->id,
        'nome' => 'Piano senza giorno',
        'numero_rate' => 4,
        // `giorno_scadenza` volutamente assente: deve decidere il database.
    ], $condominio);

    // Il 5 è quello che la migrazione dichiara — `integer('giorno_scadenza')->default(5)`.
    // Volevo leggerlo dallo schema per tenere il test agganciato alla migrazione, ma
    // l'introspezione del default non è portabile: su MySQL torna `'5'`, sulla suite che gira
    // su SQLite torna vuota. Meglio un numero scritto qui, che è vero e si vede, di
    // un'introspezione che sul motore dei test non prova niente.
    expect((int) $piano->giorno_scadenza)->toEqual(5);
});

test('il giorno indicato dall utente vince comunque sul default', function () {
    [$condominio, $esercizio, $gestione] = setupContabile();

    $piano = (new PianoRateCreatorService)->creaPianoRate([
        'gestione_id' => $gestione->id,
        'nome' => 'Piano con giorno scelto',
        'numero_rate' => 4,
        'giorno_scadenza' => 20,
    ], $condominio);

    expect((int) $piano->giorno_scadenza)->toEqual(20);
});

/**
 * Il caso che il `?? 1` nascondeva: chiedere esplicitamente il giorno 1 è legittimo, e non
 * deve diventare indistinguibile dal «non l'ho indicato».
 */
test('chiedere esplicitamente il giorno 1 resta possibile', function () {
    [$condominio, $esercizio, $gestione] = setupContabile();

    $piano = (new PianoRateCreatorService)->creaPianoRate([
        'gestione_id' => $gestione->id,
        'nome' => 'Piano al primo',
        'numero_rate' => 4,
        'giorno_scadenza' => 1,
    ], $condominio);

    expect((int) $piano->giorno_scadenza)->toEqual(1);
});

// ════════════════════════════════════════════════════════════════════════════
// 2. Il controllo anti-duplicato non deve dipendere dalla data
// ════════════════════════════════════════════════════════════════════════════

/**
 * Il difetto si dimostra senza far girare il listener: basta leggere la query. Se fra le
 * condizioni c'è `start_time`, spostare la scadenza rende l'evento esistente invisibile al
 * controllo — e il listener ne crea un altro per la stessa rata e la stessa persona.
 *
 * È un test sul **codice** e non sul comportamento, ed è voluto: riprodurre il duplicato
 * richiederebbe la Fase 3, che non esiste ancora. Questo test tiene il posto fino ad allora,
 * e si accende se qualcuno rimette la data nella condizione.
 */
test('l esistenza di un evento si verifica su rata e persona, non sulla data', function () {
    $sorgente = file_get_contents(app_path('Listeners/Gestionale/SyncScadenziarioWithPianoRate.php'));

    // La query di controllo: deve restare ancorata al rata_id e all'anagrafica.
    expect($sorgente)->toContain("whereJsonContains('meta->context->rata_id'")
        ->and($sorgente)->toContain("where('anagrafica_id', \$anagraficaId)");

    // E non deve più filtrare per start_time: è ciò che la rende cieca a una data spostata.
    expect($sorgente)->not->toContain("Evento::where('start_time'");
});
