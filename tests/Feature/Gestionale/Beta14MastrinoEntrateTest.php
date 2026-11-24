<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 754 (8), decisione 696 (D5, «€ 100,00 come entrata»): il mastrino di «Entrate e proventi».
 *
 * Il mastro delle voci di entrata è di tipo costo, in diminuzione dei costi (`VociDiEntrataNelMastro`), e il mastrino leggeva il verso dal tipo: dare meno
 * avere. L'affitto dell'antenna, € 100,00 incassati da terzi, è in avere: il saldo usciva −€ 100,00, in rosso, con «un importo negativo è contro natura».
 *
 * Ora il mastrino legge quel mastro come un'entrata, avere meno dare: € 100,00, senza rosso, sulla pagina, nella stampa e nel libro mastro, con la riga che
 * spiega il meno della situazione patrimoniale. Il risultato di gestione non cambia: lo Stato patrimoniale non si tocca.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\ContoContabile;
use App\Services\Gestionale\RegistroContabilitaService;
use App\Services\Gestionale\StatoPatrimonialeService;
use App\Services\Gestionale\VociDiEntrataNelMastro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

const B14ME_RIGA = 'Entrate: il saldo si legge in avere. Nella situazione patrimoniale la stessa cifra sta fra i costi con il meno, perché le entrate li riducono.';

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/** «Affitto antenna» € 100,00 incassato da terzi il 15/05/2026, dalla rotta. Restituisce lo scenario e il mastro delle entrate. */
function b14meAntenna($test): array
{
    $test->travelTo(Carbon::parse('2026-06-30 10:00', 'Europe/Rome'));
    $s = be52Scenario($test);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $gestore = tap(Anagrafica::forceCreate(['nome' => 'Torri del Nord Spa', 'email' => 'torri@test.it', 'indirizzo' => 'Via Po 1', 'codice_fiscale' => 'TORRIT00000001']),
        fn ($a) => $a->condomini()->syncWithoutDetaching([$s['c']->id]));
    $test->actingAs($test->user)->post(route('admin.gestionale.incassi-da-terzi.store', [$s['c']]), [
        'esercizio_id' => $s['e']->id, 'conto_id' => $s['entrata']->id, 'cassa_id' => $s['cassa']->id, 'data_operazione' => '2026-05-15',
        'importo' => 100.00, 'anagrafica_id' => $gestore->id, 'causale' => 'Affitto antenna',
    ])->assertSessionHasNoErrors();

    return [$s, ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', VociDiEntrataNelMastro::RUOLO)->firstOrFail()];
}

test('il mastrino di «Entrate e proventi» chiude a € 100,00, letto come un\'entrata; il libro mastro dice lo stesso', function () {
    [$s, $mastro] = b14meAntenna($this);

    $m = app(RegistroContabilitaService::class)->mastrino($s['e'], $mastro);
    expect($m['saldo_finale'])->toBe(10000)
        ->and($m['saldo_alla_data'])->toBe(10000)
        ->and($m['conto']['natura_dare'])->toBeFalse()
        ->and($m['conto']['entrate'])->toBeTrue()
        ->and(collect($m['righe'])->pluck('saldo_progressivo')->all())->toBe([10000]);

    $nelLibro = collect(app(RegistroContabilitaService::class)->libroMastro($s['e'])['mastrini'])->firstWhere('conto.id', $mastro->id);
    expect($nelLibro['saldo_finale'])->toBe(10000)
        ->and($nelLibro['conto']['entrate'])->toBeTrue();

    // Un conto di costo che non è il mastro delle entrate resta nel suo verso: dare meno avere.
    $altro = ContoContabile::where('condominio_id', $s['c']->id)->where('tipo', 'costo')->where('id', '!=', $mastro->id)->firstOrFail();
    expect(app(RegistroContabilitaService::class)->mastrino($s['e'], $altro)['conto'])->toMatchArray(['natura_dare' => true, 'entrate' => false]);
});

test('il risultato di gestione dello Stato patrimoniale non cambia: le entrate restano fra i costi con il meno', function () {
    [$s, $mastro] = b14meAntenna($this);

    $foto = app(StatoPatrimonialeService::class)->calcola($s['c']->fresh(), allaData: '2026-06-30');
    // A mano: nessun costo, nessuna quota; l'antenna in avere sul mastro di costo: costi −€ 100,00, risultato +€ 100,00.
    expect($foto['costi'])->toBe(-10000)
        ->and($foto['risultato_esercizio'])->toBe(10000)
        ->and((int) collect($foto['costi_voci'])->firstWhere('id', $mastro->id)['saldo'])->toBe(-10000)
        ->and($foto['quadra'])->toBeTrue();
});

test('la pagina e la stampa: € 100,00 con la riga che spiega il meno della situazione patrimoniale', function () {
    [$s, $mastro] = b14meAntenna($this);

    $this->actingAs($this->user)
        ->get(route('admin.gestionale.esercizi.conti.movimenti', [$s['c']->id, $s['e']->id, $mastro->id]))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('gestionale/movimenti/mastrino/Show')
            ->where('conto.natura_dare', false)->where('conto.entrate', true)
            ->where('riepilogo.saldo_finale', 10000));

    // Il blocco del conto della stampa, uguale nel mastrino e nel libro mastro: la riga c'è anche senza la nota «Come leggere».
    $m = app(RegistroContabilitaService::class)->mastrino($s['e'], $mastro);
    foreach ([true, false] as $conNota) {
        $html = view('pdf.gestionale._mastrino_conto', $m + ['parziale' => false, 'con_nota' => $conNota])->render();
        expect(preg_replace('/\s+/', ' ', strip_tags($html)))->toContain(B14ME_RIGA)->toContain('€ 100,00')->not->toContain('€ -100,00');
    }

    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.conti.movimenti.print', [$s['c']->id, $s['e']->id, $mastro->id]))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
});
