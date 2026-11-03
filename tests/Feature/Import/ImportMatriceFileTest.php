<?php

use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Import\ImportVerificaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * **Quali insiemi di file sanno dire da soli in quale condominio vanno, e quali no.**
 *
 * È la domanda da cui è nata la 1.11.0-beta.3, ed era rimasta senza una risposta scritta: il
 * condominio si legge dalla testata (`Condominio <nome> - C. Fisc. <cf>`), la testata la portano
 * solo le **stampe** esportate da Danea, e gli export «Import/Export tramite Excel» sono elenchi
 * puri che non ne hanno. Chi caricava solo quelli non aveva nessuna strada.
 *
 * Il difetto è stato corretto guardando **due** combinazioni. Questo file le prova tutte, perché
 * la risposta non si deduce dal nome del report ma da quale metodo di `ImportVerificaService` lo
 * legge — e solo `leggiRiparto` e `leggiSoloBanner` invocano il `BannerParser`:
 *
 * | report | metodo che lo legge | dichiara il condominio? |
 * | :--- | :--- | :--- |
 * | `riparto_consuntivo` | `leggiRiparto` | **sì** |
 * | `movimenti`, `rate_versate`, `bilancio_consuntivo` | `leggiSoloBanner` | **sì** |
 * | `elenco_unita` | `leggiElencoUnita` | no |
 * | `anagrafica_millesimi` | `leggiMillesimi` | no |
 *
 * Il file passa dal **caricamento vero**, non da righe costruite a mano: così esercita anche il
 * riconoscitore, che è chi decide quale di quei metodi verrà chiamato. Un test che scrivesse
 * `report_type` a mano proverebbe una catena più corta di quella che l'utente percorre.
 */
function utenteMatrice(): User
{
    foreach ([
        'Crea condomini',
        'Modifica condomini',
        'Visualizza condomini',
        'Accesso pannello amministratore',
    ] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = User::factory()->create();
    // ⚠️ **Dal 30/08/2026 serve «Importa dati», non più «Crea condomini».** L'amministratore lo ha
    // per costruzione; qui si concede esplicitamente perché il caso non passa da un ruolo.
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Importa dati', 'guard_name' => 'web']);
    $u->givePermissionTo(['Crea condomini', 'Modifica condomini']);
    $u->givePermissionTo('Importa dati');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

function caricaFixture(array $nomi): ImportBatch
{
    Storage::fake('local');

    test()->actingAs(utenteMatrice())->post(route('import.store'), [
        'file' => array_map(fn (string $n) => new UploadedFile(
            base_path('tests/Fixtures/import/danea/'.$n),
            $n,
            'application/vnd.ms-excel',
            test: true,
        ), $nomi),
    ]);

    return ImportBatch::latest()->first();
}

it('dice se i file bastano a stabilire il condominio', function (array $nomi, bool $atteso, string $perche) {
    $letto = app(ImportVerificaService::class)->verifica(caricaFixture($nomi));

    expect($letto['dichiaratoDaiFile'])->toBe($atteso, $perche);

    // `dichiaratoDaiFile` e la presenza del canonico devono raccontare la stessa storia: sono
    // due modi di dire la stessa cosa, e se divergono la schermata mostra una tendina che poi
    // verrebbe ignorata (o non la mostra quando serve).
    expect(isset($letto['canonici']['condominio']))->toBe($atteso);

    $codici = collect($letto['esiti'])->flatMap(fn ($e) => $e->rilievi)->pluck('codice');

    if ($atteso) {
        expect($codici)->not->toContain('condominio.nessun_file_lo_dichiara');
    } else {
        expect($codici)->toContain('condominio.nessun_file_lo_dichiara');
    }
})->with([
    // ── Le stampe: portano la testata, e con lei il condominio ──
    'riparto consuntivo da solo' => [
        ['riparto_consuntivo.xls'], true,
        'È la stampa su cui è nato l\'importatore: la testata la legge leggiRiparto.',
    ],
    'riparto col nome unito' => [
        ['riparto_nome_unito.xls'], true,
        'Stessa stampa, nomi in una cella sola: la testata non cambia.',
    ],
    'movimenti da solo' => [
        ['movimenti.xls'], true,
        'Passa da leggiSoloBanner: il suo contenuto non lo importiamo, la testata sì.',
    ],
    'rate versate da sole' => [
        ['rate_versate.xls'], true,
        'Come i movimenti: contenuto scartato, testata letta.',
    ],

    // ── Gli elenchi: nessuna testata, per costruzione ──
    'elenco unità da solo' => [
        ['elenco_unita.xls'], false,
        'leggiElencoUnita non invoca mai il BannerParser.',
    ],
    'anagrafica millesimi da sola' => [
        ['anagrafica_millesimi.xls'], false,
        'leggiMillesimi neppure.',
    ],
    'millesimi con tabelle miste' => [
        ['millesimi_tabelle_miste.xls'], false,
        'Stesso report, tabelle diverse: continua a non avere testata.',
    ],

    // ── Le combinazioni che si vedono davvero ──
    'i due elenchi insieme, come il secondo amministratore' => [
        ['elenco_unita.xls', 'anagrafica_millesimi.xls'], false,
        'È esattamente il caso segnalato il 28/08/2026: due file letti bene e nessun condominio.',
    ],
    'elenco unità più il riparto, come i file di giugno' => [
        ['elenco_unita.xls', 'riparto_consuntivo.xls'], true,
        'Basta una stampa nel lotto perché il condominio si sappia.',
    ],
    'tutti e tre i file del corpus di giugno' => [
        ['elenco_unita.xls', 'anagrafica_millesimi.xls', 'riparto_consuntivo.xls'], true,
        'Il caso completo: la testata di uno vale per tutti.',
    ],
    'due elenchi più i movimenti' => [
        ['elenco_unita.xls', 'anagrafica_millesimi.xls', 'movimenti.xls'], true,
        'Anche una stampa che non importiamo basta a dire di chi sono i dati.',
    ],
]);

it('un lotto senza nessun file chiede comunque il condominio invece di tacere', function () {
    // Il caso limite: fino al 28/08/2026 l'anteprima di un lotto senza condominio moriva con un
    // «Undefined array key "condominio"» invece di dire cosa mancava.
    $letto = app(ImportVerificaService::class)->verifica(ImportBatch::create(['sorgente' => 'danea']));

    expect($letto['dichiaratoDaiFile'])->toBeFalse();

    $codici = collect($letto['esiti'])->flatMap(fn ($e) => $e->rilievi)->pluck('codice');
    expect($codici)->toContain('condominio.nessun_file_lo_dichiara');
});
