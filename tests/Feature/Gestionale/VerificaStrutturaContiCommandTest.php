<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/**
 * Il comando di diagnosi della struttura del piano dei conti.
 *
 * Due segnali, e il secondo è quello che costa denaro. Ma la prova che conta davvero in questo
 * file è la **terza**: che lo zero legittimo non venga segnalato. Un comando che grida al lupo
 * su ogni capitolo vuoto verrebbe ignorato entro la seconda esecuzione, e a quel punto tanto
 * varrebbe non averlo scritto.
 *
 * Il discrimine non è il valore in pivot — zero — ma cosa c'è sotto: zero perché non c'è niente
 * da ripartire è la normalità (la migrazione di popolamento ne scrive uno per ogni capitolo
 * vuoto, con la nota «Capitolo vuoto»); zero benché ci sia un preventivo è la perdita.
 */
function scenarioStrutturaConti(): array
{
    $condominio = Condominio::factory()->create();

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome'          => '2026',
        'data_inizio'   => '2026-01-01',
        'data_fine'     => '2026-12-31',
        'stato'         => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'esercizio_id'  => $esercizio->id,
        'nome'          => 'Gestione Ordinaria',
        'tipo'          => 'ordinaria',
        'descrizione'   => 'Gestione Ordinaria',
        'data_inizio'   => '2026-01-01',
        'data_fine'     => '2026-12-31',
    ]);

    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id,
        'gestione_id'   => $gestione->id,
        'nome'          => 'PC 2026',
    ]);

    return compact('condominio', 'esercizio', 'gestione', 'pianoConto');
}

function contoDiProva(PianoConto $pc, string $nome, int $importo = 0, ?int $parentId = null, bool $capitolo = false): Conto
{
    return Conto::create([
        'piano_conto_id' => $pc->id,
        'parent_id'      => $parentId,
        'nome'           => $nome,
        'tipo'           => 'spesa',
        'natura_spesa'   => 'ordinaria',
        'is_capitolo'    => $capitolo,
        'importo'        => $importo,
    ]);
}

/** Il comando è di sola lettura: si interroga l'output, non lo stato. */
function eseguiVerificaStruttura(int $condominioId): string
{
    Artisan::call('kondomanager:verifica-struttura-conti', ['--condominio' => $condominioId]);

    return Artisan::output();
}

test('elenca le voci oltre il secondo livello con il ramo in cui si trovano', function () {
    $s = scenarioStrutturaConti();

    $capitolo = contoDiProva($s['pianoConto'], 'Spese ordinarie', 0, null, true);
    $contenitore = contoDiProva($s['pianoConto'], 'Spese amministrative', 0, $capitolo->id, true);
    contoDiProva($s['pianoConto'], 'Compenso amministratore', 240000, $contenitore->id);

    $out = eseguiVerificaStruttura($s['condominio']->id);

    // Non basta nominare la voce: senza il ramo in cui si trova, su un piano dei conti vero
    // l'amministratore non sa dove andare a cercarla.
    expect($out)->toContain('Voci fuori struttura (1)')
        ->and($out)->toContain('Compenso amministratore')
        ->and($out)->toContain('Spese amministrative')
        ->and($out)->toContain('Spese ordinarie');
});

test('segnala un ramo congelato a zero che ha invece un preventivo sotto', function () {
    $s = scenarioStrutturaConti();

    $capitolo = contoDiProva($s['pianoConto'], 'Spese ordinarie', 0, null, true);
    $contenitore = contoDiProva($s['pianoConto'], 'Spese amministrative', 0, $capitolo->id, true);
    contoDiProva($s['pianoConto'], 'Compenso amministratore', 240000, $contenitore->id);

    $piano = PianoRate::create([
        'gestione_id'   => $s['gestione']->id,
        'condominio_id' => $s['condominio']->id,
        'nome'          => 'Piano 2026',
        'stato'         => 'bozza',
        'tipo'          => 'ordinario',
        'numero_rate'   => 1,
    ]);
    $piano->capitoli()->attach($contenitore->id, ['importo' => 0, 'note' => 'Inclusione automatica orfani']);

    $out = eseguiVerificaStruttura($s['condominio']->id);

    expect($out)->toContain('Voci finanziate a zero (1)')
        ->and($out)->toContain('Piano 2026')
        // L'importo non addebitato è il dato azionabile: senza, l'amministratore sa che c'è un
        // problema ma non quanto grande.
        ->and($out)->toContain('€ 2.400,00')
        // La nota distingue le due origini dello zero: la migrazione di aggiornamento scrive
        // sul capitolo radice, il controller sul sottoconto.
        ->and($out)->toContain('Inclusione automatica orfani');
});

test('non segnala lo zero legittimo di un capitolo davvero vuoto', function () {
    $s = scenarioStrutturaConti();

    // È quello che la migrazione di popolamento scrive su ogni installazione aggiornata da
    // 1.9.x: un capitolo senza budget e senza figli, in pivot a 0 con la nota «Capitolo vuoto».
    // Se questo test diventasse rosso, il comando griderebbe al lupo su ogni installazione e
    // verrebbe ignorato entro la seconda esecuzione.
    $vuoto = contoDiProva($s['pianoConto'], 'Capitolo senza budget', 0, null, true);

    $piano = PianoRate::create([
        'gestione_id'   => $s['gestione']->id,
        'condominio_id' => $s['condominio']->id,
        'nome'          => 'Piano 2026',
        'stato'         => 'bozza',
        'tipo'          => 'ordinario',
        'numero_rate'   => 1,
    ]);
    $piano->capitoli()->attach($vuoto->id, ['importo' => 0, 'note' => 'Capitolo vuoto']);

    expect(eseguiVerificaStruttura($s['condominio']->id))->toContain('Nessun segnale');
});

test('un piano dei conti sano a due livelli non produce segnali', function () {
    $s = scenarioStrutturaConti();

    $capitolo = contoDiProva($s['pianoConto'], 'Spese ordinarie', 0, null, true);
    contoDiProva($s['pianoConto'], 'Compenso amministratore', 240000, $capitolo->id);

    expect(eseguiVerificaStruttura($s['condominio']->id))->toContain('Nessun segnale');
});
