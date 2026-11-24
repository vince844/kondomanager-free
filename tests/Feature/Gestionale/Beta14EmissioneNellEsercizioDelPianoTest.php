<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\EventoTipo;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Services\Import\ImportVerificaService;
use App\Services\Subentro\FermataDopoScrivi;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';
require_once __DIR__.'/GestionaleTestHelpers.php';

/**
 * Coda 646 (1.12.0-beta.14, tappa 5), con la risposta di Vincenzo a D3 (decisione 707 (4)): con due esercizi aperti.
 *
 * Il caso. Il condominio ha il 2025 ancora aperto e il 2026 aperto con il suo piano, come lo lascia un'importazione (l'esercizio
 * importato nasce aperto; le pagine non lasciano aprirne un secondo). Il 20/01/2026 emetti la prima rata: la scrittura andava nel
 * 2025, il primo esercizio aperto trovato senza nessun ordine, e il registro e lo stato patrimoniale del 2026 non la vedevano.
 *
 * Adesso l'emissione e il suo annullamento prendono l'esercizio del piano, se è aperto; con l'esercizio del piano chiuso la rata emessa dopo
 * va nell'esercizio aperto, come prima (le «emesse altrove» del riepilogo per competenza).
 * Incassi, pagamenti e saldi registrati dopo vanno nell'esercizio aperto più recente (D3): una regola sola, quella di `HasEsercizio`,
 * che usano anche la pagina dei saldi (`FermataDopoScrivi`) e l'importazione (`ImportVerificaService::esercizioApertoDi()`).
 */

/** Il condominio con il 2025 aperto creato **prima** (l'id più basso) e il 2026 aperto con il suo piano generato, come `lcdScenario`. */
function b14eeScenario(): array
{
    $c = Condominio::factory()->create();
    $e2025 = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'aperto']);
    $g2025 = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2025', 'tipo' => 'ordinaria', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31']);
    legaAEsercizio($e2025, $g2025->id);

    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);

    $banca = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 100000]);

    $persone = [];
    $immobili = [];
    foreach (['A' => ['ugo', 600], 'B' => ['elsa', 400]] as $nome => [$chi, $millesimi]) {
        $immobili[$nome] = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "B14EE-{$nome}", 'nome' => "Interno {$nome}", 'interno' => $nome]);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $immobili[$nome]->id, 'valore' => (float) $millesimi, 'created_at' => now(), 'updated_at' => now()]);
        $persone[$chi] = tap(Anagrafica::forceCreate(['nome' => ucfirst($chi), 'email' => "b14ee-{$chi}@test.it", 'indirizzo' => 'Via Roma 12',
            'codice_fiscale' => strtoupper(str_pad($chi, 6, 'X')).'B14EE00001']), fn ($a) => $a->condomini()->syncWithoutDetaching([$c->id]));
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$chi]->id, 'immobile_id' => $immobili[$nome]->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
            'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => 4, 'giorno_scadenza' => 20, 'data_prima_scadenza' => '2026-01-20', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
    ]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $e);

    return compact('c', 'e', 'g', 'e2025', 'g2025', 'immobili', 'persone', 'piano', 'cassa');
}

function b14eeEmetti($test, array $s, array $numeri, string $data)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => array_map(fn ($n) => lcdRata($s, $n), $numeri), 'data_emissione' => $data, 'invia_notifiche' => false,
    ]);
}

function b14eeScritture(array $s, string $tipo): array
{
    return DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', $tipo)->pluck('esercizio_id')->map(fn ($id) => (int) $id)->all();
}

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
    $this->travelTo('2026-01-20 10:00:00');
});

it('l\'emissione va nell\'esercizio del piano, anche con un esercizio più vecchio ancora aperto', function () {
    $s = b14eeScenario();

    b14eeEmetti($this, $s, [1], '2026-01-20')->assertSessionHasNoErrors()->assertRedirect();

    expect(b14eeScritture($s, 'emissione_rata'))->not->toBe([])
        ->and(array_unique(b14eeScritture($s, 'emissione_rata')))->toBe([(int) $s['e']->id]);
});

it('controprova — con l\'esercizio del piano chiuso la rata emessa dopo va nell\'esercizio aperto, come prima: le «emesse altrove» del rendiconto', function () {
    // Il caso voluto di `RiepilogoPerCompetenzaTest`: il 2026 chiuso, il 2027 aperto, la rata del piano 2026 emessa a gennaio sta nel 2027.
    $s = b14eeScenario();
    $s['e2025']->update(['stato' => 'chiuso']);
    $s['e']->update(['stato' => 'chiuso']);
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($e2027, $s['g']->id);
    $this->travelTo('2027-01-05 10:00:00');

    b14eeEmetti($this, $s, [1], '2027-01-05')->assertSessionHasNoErrors()->assertRedirect();

    expect(array_unique(b14eeScritture($s, 'emissione_rata')))->toBe([(int) $e2027->id]);
});

it('l\'annullamento dell\'emissione rimanda al piano dentro il suo esercizio', function () {
    $s = b14eeScenario();
    b14eeEmetti($this, $s, [1], '2026-01-20')->assertSessionHasNoErrors();

    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s['c'], $s['piano'], lcdRata($s, 1)]))
        ->assertRedirect()
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'success');

    $compito = DB::table('eventi')->where('tipo', EventoTipo::EMISSIONE_RATA->value)->orderByDesc('id')->first();
    $indirizzo = json_decode((string) $compito->meta, true)['action_url'];
    expect($indirizzo)->toContain('/esercizi/'.$s['e']->id.'/')
        ->and($indirizzo)->not->toContain('/esercizi/'.$s['e2025']->id.'/');
});

it('l\'incasso registrato dopo va nell\'esercizio aperto più recente, il 2026', function () {
    $s = b14eeScenario();
    b14eeEmetti($this, $s, [1], '2026-01-20')->assertSessionHasNoErrors();

    $quota = (int) DB::table('rate_quote')->where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $s['persone']['ugo']->id)->value('id');
    $importo = (int) DB::table('rate_quote')->where('id', $quota)->value('importo');
    lcdIncassa($this, $s, 'ugo', [$quota => $importo], '2026-01-20');

    expect(b14eeScritture($s, 'incasso_rata'))->toBe([(int) $s['e']->id]);
});

it('la regola è una sola: l\'esercizio aperto più recente, per la pagina dei saldi e per l\'importazione', function () {
    $s = b14eeScenario();

    expect(ImportVerificaService::esercizioApertoDi($s['c']->id)?->id)->toBe($s['e']->id);

    // La pagina dei saldi mostra l'esercizio aperto: una riga del 2025 non c'è, una del 2026 sì.
    $vecchio = Saldo::create(['esercizio_id' => $s['e2025']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['ugo']->id,
        'immobile_id' => $s['immobili']['A']->id, 'gestione_id' => $s['g2025']->id, 'saldo_iniziale' => 1000, 'origine' => 'manuale', 'is_applicato' => false]);
    $nuovo = Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['elsa']->id,
        'immobile_id' => $s['immobili']['B']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 2000, 'origine' => 'manuale', 'is_applicato' => false]);

    expect(FermataDopoScrivi::fuoriDalWallet(collect([$vecchio, $nuovo]))->pluck('id')->all())->toBe([$vecchio->id]);
});
