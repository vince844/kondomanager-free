<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 286 — il cancello del defunto e il Wallet guardano lo stesso esercizio.
 *
 * La voce del defunto dice chi ha già un saldo non legato sulla gestione e sull'unità (`con_un_saldo`, e chi è «incerto» quando il piano non
 * applica i saldi): lo cercava nell'esercizio del piano, mentre il Wallet scrive e controlla il posto nell'esercizio aperto del condominio
 * (`CreateSaldoRequest::fraseDelPostoOccupato`, `HasEsercizio::getEsercizioCorrente`). Con una gestione su due esercizi le due letture divergevano.
 *
 * Il caso: gestione ordinaria sul 2026 e sul 2027, piano del 2026 (che non applica i saldi), esercizio aperto il 2027. Ugo, proprietario al 100 % di
 * Interno A, muore il 1/3/2026 con eredi Anna e Bruno al 50 %, l'arretrato agli eredi.
 * - Anna ha un saldo a mano sul 2027: il Wallet le rifiuta un secondo saldo non legato (il posto è occupato), e la voce la dice fra chi ha un saldo.
 * - Anna ha un saldo sul 2026 (scritto quando era aperto): il Wallet le accetta un saldo non legato sul 2027, e la voce non la dice fra chi ha un saldo.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Saldo;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2027, 6, 15)->setTime(10, 0));
});

/** @return array{0: Beta51Scenario, 1: array<int, Esercizio>} */
function dewScenario($test): array
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000]);
    $es = [2026 => $s->e];
    $gestioni = [];
    foreach ([2026, 2027] as $anno) {
        $es[$anno] ??= Esercizio::create(['condominio_id' => $s->c->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'aperto']);
        $gestioni[] = app(CondominioService::class)->createDefaultGestione($s->c, $es[$anno])->id;
    }
    expect(array_unique($gestioni))->toHaveCount(1);
    $g = \App\Models\Gestione::findOrFail($gestioni[0]);
    $s->gestioni['Ordinaria'] = ['g' => $g, 'tipo' => 'ordinaria', 'pc' => PianoConto::create(['condominio_id' => $s->c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti'])];
    $s->voce('Ordinaria', 'Spese generali', 365000, ['Proprietà' => 100]);
    $s->titolare('A', 'ugo', 'proprietario', 100);
    $s->persona('anna');
    $s->persona('bruno');

    return [$s, $es];
}

/** La successione di Ugo e il piano del 2026 che non applica i saldi, con il 2026 chiuso. */
function dewSuccessione(Beta51Scenario $s, array $es): void
{
    $es[2026]->update(['stato' => 'chiuso']);
    $s->e = $es[2026];
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria', primaScadenza: '2026-01-05', applicaSaldi: false);
    $s->passaggio('successione', 'A', 'ugo', null, '2026-03-01', ['eredi' => ['anna' => 50, 'bruno' => 50], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
}

/** Un saldo non legato dalla porta del Wallet (scrive nell'esercizio aperto). */
function dewWallet($test, Beta51Scenario $s, string $chi, int $cents)
{
    return $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => $cents,
    ]);
}

function dewVoce(Beta51Scenario $s): array
{
    $voce = Beta51Scenario::voceDelDefunto($s->cancello('Preventivo 2026'));
    expect($voce)->not->toBeNull();

    return $voce;
}

it('Coda 286 — il saldo a mano di Anna sul 2027 (l\'esercizio aperto): il Wallet le rifiuta un secondo saldo non legato e la voce del cancello la dice fra chi ha già un saldo', function () {
    [$s, $es] = dewScenario($this);
    dewSuccessione($s, $es);
    dewWallet($this, $s, 'anna', 5000)->assertSessionHasNoErrors();
    expect(Saldo::where('anagrafica_id', $s->persona('anna')->id)->value('esercizio_id'))->toBe($es[2027]->id);

    $voce = dewVoce($s);

    dewWallet($this, $s, 'anna', 7000)->assertSessionHasErrors('anagrafica_id');
    expect($voce['con_un_saldo'])->toBe(['Anna'])
        ->and($voce['wallet'])->toBeFalse()
        ->and($voce['frase'])->toContain('Anna ha su quella gestione e quell\'unità saldi non legati');
});

it('Coda 286 — il saldo di Anna sul 2026 (chiuso): il Wallet le accetta un saldo non legato sul 2027, e la voce non la dice fra chi occupa il posto', function () {
    [$s, $es] = dewScenario($this);
    // Il saldo scritto quando il 2026 era l'esercizio aperto; il piano del 2026 non applica i saldi e non lo legge.
    Saldo::create(['esercizio_id' => $es[2026]->id, 'condominio_id' => $s->c->id, 'anagrafica_id' => $s->persona('anna')->id, 'immobile_id' => $s->unita['A']->id,
        'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => 5000, 'origine' => 'manuale', 'is_applicato' => false]);
    dewSuccessione($s, $es);

    $voce = dewVoce($s);

    expect($voce['con_un_saldo'])->toBe([])
        ->and($voce['wallet'])->toBeTrue()
        ->and($voce['frase'])->not->toContain('saldi non legati');
    dewWallet($this, $s, 'anna', 7000)->assertSessionHasNoErrors();
    expect(DB::table('saldi')->where('anagrafica_id', $s->persona('anna')->id)->where('esercizio_id', $es[2027]->id)->count())->toBe(1);
});
