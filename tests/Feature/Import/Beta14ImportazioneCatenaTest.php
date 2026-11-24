<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Models\Tabella;
use App\Models\User;
use App\Services\CondominioService;
use App\Services\Import\ImportVerificaService;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Gestionale/Beta52VoceEntrataScenari.php';

/**
 * La catena della tappa 5 della 1.12.0-beta.14: l'importazione → il condominio → il piano rate (§3 del progetto).
 *
 * Il condominio «Fixture» è già in archivio con l'esercizio 2023/2024 ancora aperto; si importa da Danea il 2024/2025, dalle rotte, con
 * l'elenco unità (l'interno 3 con due proprietari senza quota), il riparto, le tabelle e il bilancio consuntivo. Lungo la strada:
 * l'anteprima dice che resteranno aperti due esercizi (Coda 646, D3); l'interno 3 ha 50 e 50, e dalla scheda la quota si corregge
 * (Coda 599, pacchetto B); il piano del 2024/2025 creato e generato dalle rotte emette nel suo esercizio (Coda 646); la fattura su una
 * voce importata si registra (Coda 113, pacchetto B2). Nessuna pagina d'errore lungo la strada.
 */
function b14catUtente(): User
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    foreach (['Accesso pannello amministratore', 'Importa dati', 'Crea condomini', 'Modifica condomini'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo('Accesso pannello amministratore');
    $u = User::factory()->create();
    $u->assignRole($ruolo);
    $u->givePermissionTo(['Importa dati', 'Crea condomini', 'Modifica condomini']);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    return $u->fresh();
}

/** Il condominio della prova di Danea, già in archivio, con il 2023/2024 ancora aperto e la sua gestione. */
function b14catCondominioConIlVecchioAperto(): array
{
    $c = Condominio::factory()->create(['nome' => 'Condominio Fixture', 'codice_fiscale' => '00000000001']);
    app(CondominioService::class)->ensureDefaultConti($c);
    $vecchio = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => '2023/2024', 'data_inizio' => '2023-11-01', 'data_fine' => '2024-10-31', 'stato' => 'aperto']);
    app(CondominioService::class)->createDefaultGestione($c, $vecchio);

    return [$c, $vecchio];
}

function b14catFixture(string $nome): UploadedFile
{
    return new UploadedFile(base_path('tests/Fixtures/import/danea/'.$nome), $nome, 'application/vnd.ms-excel', test: true);
}

/** L'elenco unità della prova con l'interno 3 a due proprietari senza quota (Verdi passa da conduttrice a proprietaria). */
function b14catElencoConDueProprietari(): UploadedFile
{
    $libro = IOFactory::load(base_path('tests/Fixtures/import/danea/elenco_unita.xls'));
    $libro->getActiveSheet()->setCellValue('H4', 'Pr');
    $percorso = tempnam(sys_get_temp_dir(), 'b14cat_').'.xls';
    IOFactory::createWriter($libro, 'Xls')->save($percorso);

    return new UploadedFile($percorso, 'elenco_unita.xls', 'application/vnd.ms-excel', test: true);
}

/** @param list<UploadedFile> $file */
function b14catCarica(User $chi, array $file): ImportBatch
{
    test()->actingAs($chi)->post(route('import.store'), ['file' => $file])->assertSessionHasNoErrors()->assertRedirect();

    return ImportBatch::latest('id')->first();
}

beforeEach(function () {
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
    $this->withoutVite();
    $this->travelTo('2024-11-20 10:00:00');
});

it('l\'anteprima dice che resteranno aperti due esercizi, dove andranno incassi e rate, e quale chiudere', function () {
    $utente = b14catUtente();
    b14catCondominioConIlVecchioAperto();
    $batch = b14catCarica($utente, [b14catFixture('elenco_unita.xls'), b14catFixture('riparto_consuntivo.xls'), b14catFixture('millesimi_tabelle_miste.xls')]);

    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->assertOk()->viewData('page')['props'];

    expect($props['esercizi_aperti'])->toBe([
        'nomi' => ['2023/2024', '2024/2025'],
        'piu_recente' => '2024/2025',
        'titolo' => 'Resteranno aperti due esercizi: «2023/2024» e «2024/2025».',
        'testo' => 'Incassi, pagamenti e saldi che registri da qui in poi vanno nel «2024/2025»; le rate di ogni piano vanno nell\'esercizio del suo '
            .'piano. Quando hai finito con il «2023/2024», chiudilo.',
    ]);
});

it('un condominio nuovo nasce con l\'esercizio del file e basta: nessun avviso', function () {
    $utente = b14catUtente();
    $batch = b14catCarica($utente, [b14catFixture('elenco_unita.xls'), b14catFixture('riparto_consuntivo.xls'), b14catFixture('millesimi_tabelle_miste.xls')]);

    expect($this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props']['esercizi_aperti'])->toBeNull();
});

it('la catena: l\'importazione del 2024/2025 accanto al 2023/2024 aperto, le quote a 50 e 50, il piano che emette nel suo esercizio, la fattura su una voce importata', function () {
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    $this->user = b14catUtente();
    [$c, $vecchio] = b14catCondominioConIlVecchioAperto();

    // 1. L'importazione, dalle rotte.
    $batch = b14catCarica($this->user, [b14catElencoConDueProprietari(), b14catFixture('riparto_consuntivo.xls'),
        b14catFixture('millesimi_tabelle_miste.xls'), b14catFixture('bilancio_consuntivo.xls')]);
    $this->actingAs($this->user)->get(route('import.riconoscimento', $batch->uuid))->assertOk();
    $this->actingAs($this->user)->get(route('import.verifica', $batch->uuid))->assertOk();
    $props = $this->actingAs($this->user)->get(route('import.anteprima', $batch->uuid))->assertOk()->viewData('page')['props'];
    expect($props['esercizi_aperti']['piu_recente'])->toBe('2024/2025');

    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        $this->actingAs($this->user)->put(route('import.decisione', $batch->uuid), ['chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'lascia'])->assertRedirect();
    }
    foreach ($props['anteprima']['collisioni']['voci'] as $v) {
        $this->actingAs($this->user)->put(route('import.decisione', $batch->uuid), ['chiave' => $v['chiave_decisione'], 'scelta' => 'salta'])->assertRedirect();
    }
    expect($this->actingAs($this->user)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props']['confermabile'])->toBeTrue();

    $this->actingAs($this->user)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));
    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Condominio::count())->toBe(1);
    $this->actingAs($this->user)->get(route('import.esito', $batch->uuid))->assertOk();

    // 2. Due esercizi aperti: la regola sola dà il più recente, quello importato.
    $nuovo = Esercizio::where('condominio_id', $c->id)->where('nome', '2024/2025')->sole();
    expect($nuovo->stato)->toBe('aperto')
        ->and($vecchio->fresh()->stato)->toBe('aperto')
        ->and(ImportVerificaService::esercizioApertoDi($c->id)?->id)->toBe($nuovo->id);

    // 3. L'interno 3: due proprietari senza quota, 50 e 50; dalla scheda Verdi passa a 50 (era già 50) e Bianchi a 50.
    $interno3 = Immobile::where('condominio_id', $c->id)->where('interno', '3')->sole();
    $quote = DB::table('anagrafica_immobile as ai')->join('anagrafiche as a', 'a.id', '=', 'ai.anagrafica_id')
        ->where('ai.immobile_id', $interno3->id)->where('ai.tipologia', 'proprietario')->pluck('ai.quota', 'a.nome')->map(fn ($q) => (float) $q)->all();
    expect($quote)->toEqual(['BIANCHI PAOLO' => 50.0, 'VERDI ANNA' => 50.0]);

    $riga = (int) DB::table('anagrafica_immobile as ai')->join('anagrafiche as a', 'a.id', '=', 'ai.anagrafica_id')
        ->where('ai.immobile_id', $interno3->id)->where('a.nome', 'VERDI ANNA')->value('ai.id');
    $this->actingAs($this->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$c, $interno3, $riga]), [
        'anagrafica_id' => (int) DB::table('anagrafica_immobile')->where('id', $riga)->value('anagrafica_id'), 'tipologia' => 'proprietario', 'quota' => 50,
        'data_inizio' => (string) DB::table('anagrafica_immobile')->where('id', $riga)->value('data_inizio'), 'data_fine' => null,
    ])->assertSessionHasNoErrors()->assertRedirect();

    // 4. Il piano del 2024/2025, dalle rotte: una voce sulla prima tabella importata, il piano generato e approvato, la prima rata emessa.
    $gestione = $nuovo->gestioni()->where('gestioni.tipo', 'ordinaria')->firstOrFail();
    $pianoConti = $gestione->pianoConto;
    $tabella = Tabella::where('condominio_id', $c->id)->orderBy('id')->firstOrFail();
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$c, $nuovo, $pianoConti]), [
        'nome' => 'Preventivo della catena', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => '1.200,00',
        'descrizione' => '', 'note' => '', 'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 100,
        'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0, 'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors()->assertRedirect();
    $voce = Conto::where('piano_conto_id', $pianoConti->id)->where('nome', 'Preventivo della catena')->sole();

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$c, $nuovo]), [
        'tipo' => 'ordinario', 'gestione_id' => $gestione->id, 'nome' => 'Preventivo 2024/2025', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 4,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2024-12-05', 'genera_subito' => 1, 'capitoli_ids' => [$voce->id],
        // Le unità della tabella senza un proprietario nel file (le quote non assegnate) e i destinatari: le prese d'atto della pagina.
        'accetta_destinatari' => true, 'nota_destinatari' => 'Prova della catena', 'accetta_scoperti' => true, 'nota_scoperti' => 'Prova della catena: lette',
    ])->assertSessionHasNoErrors()->assertRedirect()->assertSessionMissing('scoperti_warning');
    $piano = PianoRate::where('condominio_id', $c->id)->where('nome', 'Preventivo 2024/2025')->sole();
    expect((int) $piano->esercizio_id)->toBe($nuovo->id);

    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$c, $nuovo, $piano]), ['approvato' => true, 'data_delibera_assemblea' => '2024-11-15'])
        ->assertSessionHasNoErrors()->assertRedirect();

    $rata = (int) DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', 1)->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$c, $piano]), [
        'rate_ids' => [$rata], 'data_emissione' => '2024-11-20', 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors()->assertRedirect()->assertSessionHas('message', fn ($m) => $m['type'] !== 'error');

    $esercizi = DB::table('scritture_contabili')->where('condominio_id', $c->id)->where('tipo_movimento', 'emissione_rata')->pluck('esercizio_id')->unique()->values()->all();
    expect($esercizi)->toEqual([$nuovo->id]);

    // 5. La fattura su una voce importata dal bilancio consuntivo, dalla rotta.
    $importata = Conto::where('piano_conto_id', $pianoConti->id)->where('nome', 'Manutenzione estintori')->sole();
    $s = ['c' => $c, 'e' => $nuovo, 'g' => $gestione];
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$c]), be52PayloadFattura($s, be52Fornitore(), $importata->id, 45.03, [
        'data_documento' => '2024-11-15', 'data_scadenza' => '2024-12-15',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => [
            'motivazione' => 'Voce importata', 'importo_sforo' => 4503, 'strategia_rientro' => 'rata_integrativa',
        ]],
    ], 'FT-ESTINTORI'))->assertSessionHasNoErrors()->assertRedirect();

    expect(DB::table('fatture_passive')->where('numero_documento', 'FT-ESTINTORI')->count())->toBe(1)
        ->and(DB::table('righe_scritture')->where('voce_spesa_id', $importata->id)->count())->toBeGreaterThan(0);
});
