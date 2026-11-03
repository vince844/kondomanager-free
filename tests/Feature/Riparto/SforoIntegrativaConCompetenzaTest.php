<?php

/**
 * B2 (1.11.0-beta.31) — la catena intera «fattura su una voce con competenza → sforo → rata integrativa con la
 * stessa competenza → riparto per giorni», chiesta a video da Vincenzo il 20/09/2026: «hai provato a registrare
 * una fattura su una voce con competenza? cosa succede? i calcoli tornano?».
 *
 * Cosa deve reggere: (1) il piano madre sulla stagione divide 100.000 in 105/78 giorni fra venditore e acquirente
 * (1/7); (2) una fattura di 130.000 sulla stessa voce porta lo speso oltre il preventivo e la copertura dice uno
 * sforo di 30.000 — la competenza dichiarata sulla fattura si registra e basta (decisione 19: sull'ordinario non
 * guida il riparto); (3) l'integrativa di 30.000 sulla voce, con la stagione dichiarata di nuovo, divide 17.213 /
 * 12.787: stessi giorni del piano madre, somma esatta, piano madre intatto. Se la competenza dichiarata sulla
 * fattura (1/10–31/12) guidasse l'ordinario, l'acquirente pagherebbe tutti i 30.000: non deve.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\CompetenzaCapitolo;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RigaRiparto;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Gestionale\BudgetCoverageService;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

require_once __DIR__ . '/../Gestionale/GestionaleTestHelpers.php';

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'collaboratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Le quote emesse di un piano per persona, in centesimi. */
function sicQuote(PianoRate $piano): array
{
    return RataQuote::query()->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
        ->join('anagrafiche', 'anagrafiche.id', '=', 'rate_quote.anagrafica_id')
        ->get(['anagrafiche.nome', 'rate_quote.importo'])->groupBy('nome')->map(fn ($g) => (int) $g->sum('importo'))->all();
}

it('fattura su una voce con la stagione dichiarata → sforo → integrativa con la stessa stagione: 105/78 giorni sul piano madre e sull\'integrativa, somme esatte, la competenza della fattura si registra e non guida l\'ordinario', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, , $conto, , $immobileId] = $ctx;
    $conto->update(['nome' => 'Riscaldamento', 'importo' => 100000]);
    $gestione->update(['data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);

    // La tabella e i due titolari: venditore fino al 30/6, acquirente dal 1/7 (D7: predecessore chiuso il giorno prima).
    $tabella = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $immobileId, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $venditore = Anagrafica::forceCreate(['nome' => 'Venditore Ugo', 'email' => 'sic-v@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'SICVENDITORE0001']);
    $acquirente = Anagrafica::forceCreate(['nome' => 'Acquirente Elsa', 'email' => 'sic-a@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'SICACQUIRENTE001']);
    DB::table('anagrafica_immobile')->insert([
        ['anagrafica_id' => $venditore->id, 'immobile_id' => $immobileId, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => '2026-06-30', 'created_at' => now(), 'updated_at' => now()],
        ['anagrafica_id' => $acquirente->id, 'immobile_id' => $immobileId, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2026-07-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $stagione = [['dal' => '2026-01-01', 'al' => '2026-04-15'], ['dal' => '2026-10-15', 'al' => '2026-12-31']];

    // 1. Il piano madre: 100.000 sulla stagione (183 giorni: 105 al venditore, 78 all'acquirente).
    $madre = PianoRate::create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 1]);
    $madre->capitoli()->attach($conto->id, ['importo' => 100000]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $madre->id)->value('id');
    foreach ($stagione as $i => $t) {
        CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => $t['dal'], 'al' => $t['al'], 'ordine' => $i]);
    }
    app(GeneratePianoRateAction::class)->execute($madre, accettaDestinatari: true, notaDestinatari: 'Rogito del 1/7, letto', esercizio: $esercizio);
    DB::table('rate')->where('piano_rate_id', $madre->id)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-10']);
    expect(sicQuote($madre))->toBe(['Venditore Ugo' => 57377, 'Acquirente Elsa' => 42623]);

    // 2. La fattura: 130.000 sul riscaldamento, con «costo maturato» 1/10–31/12 dichiarato.
    $fattura = registraFatturaServiceTest($ctx, [
        'righe' => [['descrizione' => 'Gas stagione', 'importo_imponibile' => 1300, 'aliquota_iva' => 0, 'conto_id' => $conto->id, 'is_sopravvenienza' => false]],
        'competenza_dal' => '2026-10-01', 'competenza_al' => '2026-12-31',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => ['strategia_rientro' => 'rata_integrativa', 'motivazione' => 'consumi oltre il preventivo']],
    ]);
    expect(FatturaPassiva::find($fattura->id)->competenza_dal?->toDateString())->toBe('2026-10-01');

    // La copertura vede lo speso (130.000) sopra il preventivo (100.000): sforo di 30.000 sulla voce, finanziabile.
    $fatturato = app(SpesaPerVoceService::class)->perEsercizio($esercizio->fresh());
    expect((int) $fatturato[$conto->id])->toBe(130000);
    $copertura = app(BudgetCoverageService::class);
    $finanziabili = collect($copertura->getCapitoliFinanziabili($copertura->analyze($gestione->fresh(), $fatturato)))->keyBy('id');
    expect((int) $finanziabili[$conto->id]['importo_suggerito'])->toBe(30000);

    // 3. L'integrativa, come la crea la pagina arrivando da «Gestisci sforo»: la voce, l'importo dello sforo, la stagione.
    $risposta = $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$condominio, $esercizio]), [
        'nome' => 'Integrativa riscaldamento', 'tipo' => 'ordinario', 'gestione_id' => $gestione->id, 'metodo_distribuzione' => 'rata_zero',
        'numero_rate' => 1, 'giorno_scadenza' => 10, 'genera_subito' => false, 'recurrence_enabled' => false, 'origine' => 'dashboard',
        'capitoli_ids' => [$conto->id], 'capitoli_config' => [['id' => $conto->id, 'importo' => '300,00', 'note' => 'sforo dalla dashboard']],
        'competenze_capitoli' => [['conto_id' => $conto->id, 'tratti' => $stagione]],
    ]);
    $risposta->assertRedirect()->assertSessionHasNoErrors();
    expect(session('message.type'))->not->toBe('error', (string) session('message.message'));
    $integrativa = PianoRate::where('nome', 'Integrativa riscaldamento')->firstOrFail();
    expect((int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $integrativa->id)->value('importo'))->toBe(30000)
        ->and(CompetenzaCapitolo::whereIn('piano_rate_capitolo_id', DB::table('piano_rate_capitoli')->where('piano_rate_id', $integrativa->id)->select('id'))->count())->toBe(2);

    $integrativa->update(['stato' => 'approvato']);
    app(GeneratePianoRateAction::class)->execute($integrativa, accettaDestinatari: true, notaDestinatari: 'Rogito del 1/7, letto', esercizio: $esercizio);

    // 30.000 × 105/183 = 17.213,1 → 17.213 al venditore; 12.787 all'acquirente. Somma 30.000. Se la competenza della
    // fattura (1/10–31/12) avesse guidato il riparto, l'acquirente avrebbe tutti i 30.000.
    expect(sicQuote($integrativa))->toBe(['Venditore Ugo' => 17213, 'Acquirente Elsa' => 12787]);
    $righe = RigaRiparto::where('piano_rate_id', $integrativa->id)->where('tipo', 'riparto')->get();
    expect($righe->firstWhere('anagrafica_id', $venditore->id))->toMatchArray(['importo' => 17213, 'giorni_titolarita' => 105, 'titolarita_dal' => '2026-01-01', 'titolarita_al' => '2026-06-30'])
        ->and($righe->firstWhere('anagrafica_id', $acquirente->id))->toMatchArray(['importo' => 12787, 'giorni_titolarita' => 78, 'titolarita_dal' => '2026-07-01', 'titolarita_al' => '2026-12-31'])
        ->and((int) $righe->sum('importo'))->toBe(30000);

    // Il piano madre non si è mosso.
    expect(sicQuote($madre))->toBe(['Venditore Ugo' => 57377, 'Acquirente Elsa' => 42623]);
});
