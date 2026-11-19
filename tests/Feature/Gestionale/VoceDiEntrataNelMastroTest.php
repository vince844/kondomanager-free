<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 680 (decisione 493 (15)): una voce di entrata creata dal piano dei conti va nel mastro delle entrate, «Entrate e proventi» (fra i costi, in
 * diminuzione, come il rendiconto conta le entrate), che nasce
 * alla prima richiesta; fino alla 1.12.0-beta.11 finiva in «Costi per Servizi». Per le voci già create un controllo in sola lettura (la pagina del piano dei
 * conti e `kondomanager:verifica-voci-di-entrata`) le elenca con le scritture che le hanno usate; la correzione la comanda l'amministratore e sposta solo il
 * mastro della voce, per le scritture che verranno: le righe già scritte restano dove sono.
 *
 * Cosa NON copre: il versamento degli interessi su una voce corretta (la scrittura del versamento legge il mastro della voce, `AccessoriDaIncassare`, e non
 * è cambiata); il rendiconto dopo la correzione; MySQL.
 */

use App\Models\Gestionale\ContoContabile;
use App\Services\Gestionale\VociDiEntrataNelMastro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Crea una voce dalla rotta della pagina del piano dei conti, con il tipo dato. */
function vemCrea($test, array $s, string $nome, string $tipo): \App\Models\Gestionale\Conto
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pianoConto']]), [
        'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => $tipo, 'importo' => '0,00', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $s['generali']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();

    return \App\Models\Gestionale\Conto::where('nome', $nome)->where('piano_conto_id', $s['pianoConto']->id)->firstOrFail();
}

it('una voce di entrata nuova va nel mastro «Entrate e proventi», fra i ricavi; una voce di spesa resta in «Costi per Servizi»', function () {
    $s = ideCaso($this);
    $costi = ContoContabile::create(['condominio_id' => $s['c']->id, 'codice' => '6001', 'nome' => 'Costi per Servizi', 'tipo' => 'costo', 'categoria' => 'costi', 'ruolo' => 'costi_servizi']);
    $entrata = vemCrea($this, $s, 'Affitto antenna', 'entrata');
    $spesa = vemCrea($this, $s, 'Giardiniere', 'spesa');
    $mastro = ContoContabile::find($entrata->conto_contabile_id);
    // Con il codice di prima: la voce di entrata in «Costi per Servizi».
    // Fra i costi, in diminuzione, come le entrate nel rendiconto (il risultato di gestione è «quote − costi»).
    expect([$mastro->ruolo, $mastro->tipo->value, $mastro->categoria->value, $mastro->codice, $mastro->nome])->toBe([VociDiEntrataNelMastro::RUOLO, 'costo', 'costi', '7001', 'Entrate e proventi'])
        ->and((int) $spesa->conto_contabile_id)->toBe((int) $costi->id);
    // Una seconda voce di entrata usa lo stesso mastro.
    expect((int) vemCrea($this, $s, 'Rimborso assicurazione', 'entrata')->conto_contabile_id)->toBe((int) $mastro->id);
});

it('il controllo elenca le voci di entrata con un mastro di costo e le scritture che le hanno usate; la correzione sposta solo il mastro della voce', function () {
    $s = ideCaso($this);
    // «Interessi di mora» dello scenario è una voce di entrata con un mastro di costo, come quelle create prima della correzione; una scrittura l'ha usata.
    $voce = $s['interessi'];
    $vecchio = (int) $voce->conto_contabile_id;
    $sc = \App\Models\Gestionale\ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id,
        'data_registrazione' => '2026-06-01', 'data_competenza' => '2026-06-01', 'causale' => 'Interessi versati', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata']);
    $riga = $sc->righe()->create(['conto_contabile_id' => $vecchio, 'voce_spesa_id' => $voce->id, 'tipo_riga' => 'avere', 'importo' => 329]);
    $sc->righe()->create(['conto_contabile_id' => $s['crediti']->id, 'tipo_riga' => 'dare', 'importo' => 329]);

    $elenco = app(VociDiEntrataNelMastro::class)->perCondominio((int) $s['c']->id);
    $v = collect($elenco)->firstWhere('id', $voce->id);
    expect($v['scritture'])->toHaveCount(1)->and($v['totale'])->toBe(329)
        ->and($v['frase'])->toContain('«Interessi di mora» è una voce di entrata nel mastro')->toContain('una scrittura l\'ha usata, per € 3,29');
    // Una voce nel mastro delle entrate è a posto: il controllo non la elenca.
    $nuova = vemCrea($this, $s, 'Affitto antenna', 'entrata');
    expect(collect(app(VociDiEntrataNelMastro::class)->perCondominio((int) $s['c']->id))->pluck('id')->all())->not->toContain($nuova->id);
    // La pagina del piano dei conti lo mostra.
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-conti.show', [$s['c'], $s['e'], $s['pianoConto']]))->assertOk()->viewData('page')['props'];
    expect(collect($pagina['vociDiEntrataNeiCosti'])->pluck('id')->all())->toContain($voce->id);
    // Leggerlo non cambia niente.
    expect((int) $voce->fresh()->conto_contabile_id)->toBe($vecchio);

    // Il comando dell'amministratore, dalla pagina: solo il mastro della voce, per le scritture che verranno.
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-conti.voci-di-entrata', [$s['c'], $s['e'], $s['pianoConto']]), ['voci' => [$voce->id]])
        ->assertSessionHasNoErrors()->assertSessionHas('message');
    $mastro = VociDiEntrataNelMastro::mastro((int) $s['c']->id);
    expect((int) $voce->fresh()->conto_contabile_id)->toBe((int) $mastro->id)
        // La riga già scritta resta sul mastro di prima.
        ->and((int) DB::table('righe_scritture')->where('id', $riga->id)->value('conto_contabile_id'))->toBe($vecchio)
        ->and(collect(app(VociDiEntrataNelMastro::class)->perCondominio((int) $s['c']->id))->pluck('id')->all())->not->toContain($voce->id);
});

it('una voce di entrata già creata, modificata, tiene il suo mastro: lo corregge solo il comando', function () {
    $s = ideCaso($this);
    $voce = $s['interessi'];
    $vecchio = (int) $voce->conto_contabile_id;
    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pianoConto'], $voce]), [
        'nome' => 'Interessi di mora versati', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'entrata', 'importo' => '0,00',
        'descrizione' => '', 'note' => '', 'parent_id' => null, 'tabella_millesimale_id' => $s['generali']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0,
        'percentuale_usufruttuario' => 0, 'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ]);
    expect((int) $voce->fresh()->conto_contabile_id)->toBe($vecchio);
});

it('il comando kondomanager:verifica-voci-di-entrata elenca in sola lettura, e con --correggi sposta il mastro delle voci', function () {
    $s = ideCaso($this);
    $vecchio = (int) $s['interessi']->conto_contabile_id;
    $this->artisan('kondomanager:verifica-voci-di-entrata', ['--condominio' => $s['c']->id])->assertSuccessful()->expectsOutputToContain('Interessi di mora');
    expect((int) $s['interessi']->fresh()->conto_contabile_id)->toBe($vecchio);
    $this->artisan('kondomanager:verifica-voci-di-entrata', ['--condominio' => $s['c']->id, '--correggi' => true])->assertSuccessful()->expectsOutputToContain('le scritture già fatte restano');
    expect((int) $s['interessi']->fresh()->conto_contabile_id)->toBe((int) VociDiEntrataNelMastro::mastro((int) $s['c']->id)->id);
});
