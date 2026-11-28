<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La pagina del piano generato dice, senza fermare niente, quali unità in usufrutto hanno delle voci dell'ordinaria sul «Proprietario»: in quel piano
 * le paga il nudo proprietario, e per darle all'usufruttuario la voce va messa su «Usufruttuario» nella sua tabella. È la riga C della bozza delle righe
 * chiuse e delle anagrafiche (l'unità col nome che le dà il programma, «Int. 3»): «Interno 3 è in usufrutto: le voci «Ascensore» e «Pulizie scale» sono sul «Proprietario» e in questo piano vanno a Laura
 * Rossi, nuda proprietaria. Per darle a Paolo Rossi, usufruttuario, mettile su «Usufruttuario» nella tabella della voce e ricalcola.»
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
    DB::connection()->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($x) => $x);
});

/**
 * Una gestione ordinaria 2026 con le voci date (`nome => soggetto`) sulla tabella «Generali», Interno 3 (1000 millesimi) con le righe date
 * (`nome => ruolo`), da sempre; il piano generato. Restituisce lo scenario.
 */
function uvpCaso(array $voci, array $titolari, string $tipo = 'ordinaria'): array
{
    $c = Condominio::factory()->create();
    $e = Esercizio::create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'stato' => 'aperto', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $g = Gestione::create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => $tipo, 'descrizione' => 'Ordinaria']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $t = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Generali', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    foreach ($voci as $nome => $soggetto) {
        $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => $nome, 'tipo' => 'spesa', 'natura_spesa' => $tipo, 'importo' => 60000]);
        $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $t->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }
    $u = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => 'UVP-'.$c->id, 'nome' => 'Interno 3', 'interno' => '3']);
    DB::table('quote_tabella')->insert(['tabella_id' => $t->id, 'immobile_id' => $u->id, 'valore' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($titolari as $nome => $ruolo) {
        $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower(strtok($nome, ' ')).'-uvp'.$c->id.'@test.it', 'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => strtoupper(substr(md5($nome.$c->id), 0, 16))]);
        $p->condomini()->syncWithoutDetaching([$c->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $u->id, 'tipologia' => $ruolo, 'quota' => 100, 'attivo' => true,
            'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $piano = PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'bozza',
        'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 15, 'data_prima_scadenza' => '2026-01-15', 'data_delibera_assemblea' => $tipo === 'ordinaria' ? null : '2026-01-10']);
    app(GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $e);

    return compact('c', 'e', 'g', 'u', 'piano');
}

/** Le righe della pagina del piano sulle unità in usufrutto con l'ordinaria al nudo proprietario. */
function uvpRighe($test, array $s): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']]))
        ->assertOk()->viewData('page')['props']['usufrutti_con_l_ordinaria_al_nudo'];
}

it('l\'unità in usufrutto con due voci sul «Proprietario»: la pagina del piano lo dice con i nomi, e come darle all\'usufruttuario', function () {
    $s = uvpCaso(['Ascensore' => 'proprietario', 'Pulizie scale' => 'proprietario'], ['Laura Rossi' => 'nuda_proprietario', 'Paolo Rossi' => 'usufruttuario']);

    expect(uvpRighe($this, $s))->toBe([[
        'immobile_id' => $s['u']->id,
        'frase' => 'Int. 3 è in usufrutto: le voci «Ascensore» e «Pulizie scale» sono sul «Proprietario» e in questo piano vanno a Laura Rossi, nudo proprietario. '
            .'Per darle a Paolo Rossi, usufruttuario, mettile su «Usufruttuario» nella tabella della voce e ricalcola.',
    ]]);
});

it('una voce sola: la frase al singolare', function () {
    $s = uvpCaso(['Ascensore' => 'proprietario', 'Portierato' => 'usufruttuario'], ['Laura Rossi' => 'nuda_proprietario', 'Paolo Rossi' => 'usufruttuario']);

    expect(uvpRighe($this, $s)[0]['frase'])->toBe('Int. 3 è in usufrutto: la voce «Ascensore» è sul «Proprietario» e in questo piano va a Laura Rossi, nudo proprietario. '
        .'Per darla a Paolo Rossi, usufruttuario, mettila su «Usufruttuario» nella tabella della voce e ricalcola.');
});

it('senza usufrutto, con le voci su «Usufruttuario» o su una gestione straordinaria la riga non c\'è', function (array $voci, array $titolari, string $tipo) {
    expect(uvpRighe($this, uvpCaso($voci, $titolari, $tipo)))->toBe([]);
})->with([
    'nessun usufrutto' => [['Ascensore' => 'proprietario'], ['Laura Rossi' => 'proprietario'], 'ordinaria'],
    'la voce sull\'«Usufruttuario»' => [['Ascensore' => 'usufruttuario'], ['Laura Rossi' => 'nuda_proprietario', 'Paolo Rossi' => 'usufruttuario'], 'ordinaria'],
    'la gestione straordinaria' => [['Facciata' => 'proprietario'], ['Laura Rossi' => 'nuda_proprietario', 'Paolo Rossi' => 'usufruttuario'], 'straordinaria'],
]);

it('sul piano con le rate emesse la frase non promette un ricalcolo: la voce messa su «Usufruttuario» vale per i piani che verranno, questo resta così', function () {
    $s = uvpCaso(['Ascensore' => 'proprietario', 'Portierato' => 'usufruttuario'], ['Laura Rossi' => 'nuda_proprietario', 'Paolo Rossi' => 'usufruttuario']);
    $s['piano']->update(['stato' => 'approvato']);
    $scrittura = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-01-05', 'data_competenza' => '2026-01-05',
        'numero_protocollo' => 'EMI-UVP-'.$s['piano']->id, 'causale' => 'emissione rate', 'descrizione' => 'emissione', 'tipo_movimento' => 'emissione_rate', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id');
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-05']);
    DB::table('rate_quote')->whereIn('rata_id', $rate)->update(['scrittura_contabile_id' => $scrittura]);
    expect($s['piano']->fresh()->eImmutabile())->toBeTrue();

    expect(uvpRighe($this, $s)[0]['frase'])->toBe('Int. 3 è in usufrutto: la voce «Ascensore» è sul «Proprietario» e in questo piano va a Laura Rossi, nudo proprietario. '
        .'Questo piano non si ricalcola più e resta così: per darla a Paolo Rossi, usufruttuario, nei piani che verranno, mettila su «Usufruttuario» nella tabella della voce.');
});

it('la voce che il passaggio ha tenuto sul «Proprietario» non chiede di spostarla: la frase dice che è la scelta del passaggio; le altre voci restano come prima', function () {
    $s = uvpCaso(['Ascensore' => 'proprietario', 'Pulizie scale' => 'proprietario'], ['Laura Rossi' => 'nuda_proprietario', 'Paolo Rossi' => 'usufruttuario']);
    $ascensore = (int) DB::table('conto_tabella_millesimale')->join('conti', 'conti.id', '=', 'conto_tabella_millesimale.conto_id')
        ->where('conti.nome', 'Ascensore')->where('conti.piano_conto_id', PianoConto::where('gestione_id', $s['g']->id)->value('id'))->value('conto_tabella_millesimale.id');
    DB::table('subentri')->insert(['condominio_id' => $s['c']->id, 'immobile_id' => $s['u']->id, 'tipologia' => 'usufruttuario', 'tipo_passaggio' => 'usufrutto',
        'decorrenza' => '2026-05-01', 'registro' => json_encode(['ordinaria_dopo_atto' => 'usufruttuario', 'voci_spostate' => [], 'voci_tenute' => [$ascensore]]),
        'created_at' => now(), 'updated_at' => now()]);

    expect(uvpRighe($this, $s)[0]['frase'])->toBe('Int. 3 è in usufrutto: la voce «Pulizie scale» è sul «Proprietario» e in questo piano va a Laura Rossi, nudo proprietario. '
        .'Per darla a Paolo Rossi, usufruttuario, mettila su «Usufruttuario» nella tabella della voce e ricalcola. '
        .'La voce «Ascensore» resta sul «Proprietario» e va a Laura Rossi, nudo proprietario: è la scelta del passaggio del 01/05/2026, che l\'ha tenuta lì.');

    // Con la sola voce tenuta la frase dice solo la scelta del passaggio.
    DB::table('subentri')->where('immobile_id', $s['u']->id)->update(['registro' => json_encode(['ordinaria_dopo_atto' => 'usufruttuario', 'voci_spostate' => [],
        'voci_tenute' => DB::table('conto_tabella_millesimale')->join('conti', 'conti.id', '=', 'conto_tabella_millesimale.conto_id')
            ->where('conti.piano_conto_id', PianoConto::where('gestione_id', $s['g']->id)->value('id'))->pluck('conto_tabella_millesimale.id')->map(fn ($id) => (int) $id)->all()])]);
    expect(uvpRighe($this, $s)[0]['frase'])->toBe('Int. 3 è in usufrutto: le voci «Ascensore» e «Pulizie scale» restano sul «Proprietario» e vanno a Laura Rossi, nudo proprietario: '
        .'è la scelta del passaggio del 01/05/2026, che le ha tenute lì.');
});
