<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14 (Coda 711) — una riga del Wallet legata a un passaggio non sparisce più dall'estratto conto.
 *
 * `PianoRate::saldoAssorbito`, il criterio dei saldi della 1.7 e della 1.8 (un saldo libero, senza piano, dello stesso esercizio del piano e nato prima
 * delle sue quote è «già nelle quote»), escludeva le coppie dei passaggi (`subentro_id`) ma non le righe legate (`passaggio_id`, dalla 1.11.0-beta.53: la
 * coppia del versamento di un erede, le righe scritte dal Wallet per un passaggio). Il flusso vero: Elena versa € 150,00 sul debito ereditato il 25/11/2026
 * (Elena − € 150,00, Ettore + € 150,00, legate alla successione); il 26/11 un integrativo della stessa gestione e dello stesso esercizio la assorbe; il 27/11
 * dal Wallet un saldo di Elena di € 20,00; il 28/11 un secondo integrativo lo assorbe; il 29/11 il primo si elimina dalla sua pagina e la coppia torna
 * libera. L'estratto conto di Elena del 2026 partiva da − € 280,00 invece che da − € 430,00: il credito del suo versamento non c'era più. Lo stesso criterio
 * lo legge il conguaglio di un passaggio sulle quote senza composizione (`ConguaglioPassaggio::senzaComposizione`), che legge `saldi` per conto suo: anche
 * lì mai le righe legate.
 *
 * Le cifre: le righe della successione di Elena (− € 826,02 di conguaglio e + € 526,02 di arretrato, la prova N1 della decisione 529) contano € − 300,00; la
 * coppia − € 150,00; il saldo di € 20,00 è nelle quote del secondo integrativo, con il riporto: − 30000 − 15000 + 2000 = − 43000.
 *
 * Che cosa NON copre: lo stesso effetto con un saldo scritto a mano, non legato, liberato da un piano eliminato (il criterio non distingue un saldo della
 * 1.8 da uno di oggi rimasto senza piano: proposta di una Coda nuova per la .15); MySQL (qui sqlite).
 */

use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use App\Services\Gestionale\EstrattoContoDellaPersona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Un integrativo della gestione di Via Verdi nell'esercizio 2026, generato come `idePianoNuovo`: una voce sulla tabella generale, una rata al 31/12/2026. */
function b14rlIntegrativo(array $s, string $nome, int $cents): PianoRate
{
    $voce = cpmVoce($s, 'Voce ' . $nome, [[$s['generali'], 100]], 'spesa', $cents);
    $piano = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => $nome,
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 31, 'data_prima_scadenza' => '2026-12-31',
        'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
    $piano->capitoli()->attach($voce->id, ['importo' => $cents, 'note' => 'La voce dell\'integrativo']);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']);

    return $piano->fresh();
}

/**
 * Il flusso della Coda 711, dalle rotte: la successione, il versamento di Elena il 25/11, il primo integrativo il 26/11, il saldo di Elena dal Wallet il 27/11
 * (se `$saldoDiElena`), il secondo integrativo il 28/11, l'eliminazione del primo dalla sua pagina il 29/11.
 *
 * @return array{0: array, 1: \App\Models\Gestionale\Subentro, 2: PianoRate}
 */
function b14rlFlusso($test, bool $saldoDiElena = true): array
{
    $s = ideCaso($test);
    $p = ideRegistra($test, $s, ideSuccessione($s));
    $test->travelTo('2026-11-25 11:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['Elena']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-11-25', 'importo_totale' => '150.00',
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [],
        'debito_ereditato' => [['subentro_id' => $p->id, 'importo' => '150.00']],
    ])->assertSessionHasNoErrors();
    $test->travelTo('2026-11-26 10:00:00');
    $primo = b14rlIntegrativo($s, 'Integrativo uno', 10000);
    expect(Saldo::where('passaggio_id', $p->id)->pluck('piano_rate_id')->unique()->values()->all())->toBe([$primo->id]);
    if ($saldoDiElena) {
        $test->travelTo('2026-11-27 10:00:00');
        $test->actingAs($test->user)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s['c']]), [
            'anagrafica_id' => $s['persone']['Elena']->id, 'immobile_id' => $s['unita']['U1']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 2000,
        ])->assertSessionHasNoErrors();
    }
    $test->travelTo('2026-11-28 10:00:00');
    $secondo = b14rlIntegrativo($s, 'Integrativo due', 10000);
    $test->travelTo('2026-11-29 10:00:00');
    // Dalla sua pagina: riportato in bozza, poi eliminato.
    $test->actingAs($test->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $primo]), ['approvato' => false])
        ->assertSessionHasNoErrors();
    $test->actingAs($test->user)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $primo]))->assertSessionHasNoErrors();
    expect(PianoRate::whereKey($primo->id)->exists())->toBeFalse()
        // La coppia è tornata libera, senza piano.
        ->and(Saldo::where('passaggio_id', $p->id)->get(['is_applicato', 'piano_rate_id'])->map(fn ($x) => [(bool) $x->is_applicato, $x->piano_rate_id])->unique()->values()->all())
        ->toBe([[false, null]]);

    return [$s, $p, $secondo];
}

/** Il saldo iniziale della pagina dell'estratto conto di Elena del 2026. */
function b14rlIniziale(array $s): int
{
    return (int) app(EstrattoContoDellaPersona::class)->ledger($s['c'], $s['e']->fresh(), $s['persone']['Elena']->fresh())[2];
}

it('Coda 711: la coppia del versamento di Elena, liberata dal piano eliminato, resta nell\'estratto conto: il saldo iniziale è − € 430,00', function () {
    [$s] = b14rlFlusso($this);

    // Con il codice di prima − 28000: la coppia (− € 150,00) era «già nelle quote» del secondo integrativo, nato dopo di lei.
    expect(b14rlIniziale($s))->toBe(-43000);
});

it('Coda 711, la controprova: un saldo della 1.8 (senza piano, nato prima delle quote, con il riporto nelle quote) resta «nelle quote» come prima', function () {
    [$s, , $secondo] = b14rlFlusso($this);
    $venti = Saldo::where('anagrafica_id', $s['persone']['Elena']->id)->whereNull('subentro_id')->whereNull('passaggio_id')->firstOrFail();
    expect((int) $venti->piano_rate_id)->toBe($secondo->id);
    // Come la migrazione del 01/03/2026 ha lasciato i saldi della 1.7 e della 1.8: nessun piano, lucchetto aperto.
    Saldo::whereKey($venti->id)->update(['piano_rate_id' => null, 'is_applicato' => false]);

    // Contato una volta sola, con il riporto delle quote del secondo integrativo: se il criterio non lo riconoscesse sarebbe − 41000.
    expect(PianoRate::saldoAssorbito($venti->fresh(), $secondo->id, (int) $s['e']->id, (string) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
        ->where('rate.piano_rate_id', $secondo->id)->min('rate_quote.created_at')))->toBeTrue()
        ->and(b14rlIniziale($s))->toBe(-43000);
});

it('Coda 711: il criterio non dice mai «già nelle quote» una riga legata a un passaggio, né il conguaglio la legge così sulle quote senza composizione', function () {
    [$s, $p, $secondo] = b14rlFlusso($this, saldoDiElena: false);
    $nate = (string) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $secondo->id)->min('rate_quote.created_at');
    $diElena = Saldo::where('passaggio_id', $p->id)->where('anagrafica_id', $s['persone']['Elena']->id)->firstOrFail();
    expect((string) $diElena->created_at < $nate)->toBeTrue();

    // Le quote di Elena del secondo integrativo lette come le scriveva la 1.7.x, senza composizione: con il codice di prima la coppia era «un saldo assorbito
    // da quel piano», e il conguaglio di un passaggio su U1 si fermava su quelle quote dicendo che non separa la spesa dal pregresso.
    $righe = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $secondo->id)
        ->where('rate_quote.anagrafica_id', $s['persone']['Elena']->id)
        ->get(['rate.piano_rate_id', 'rate_quote.immobile_id', 'rate_quote.anagrafica_id', DB::raw('NULL as righe_di'), DB::raw('NULL as regole_calcolo')]);
    expect($righe)->not->toBeEmpty();
    $conguaglio = app(\App\Services\Subentro\ConguaglioPassaggio::class);
    $senza = (new ReflectionMethod($conguaglio, 'senzaComposizione'))
        ->invoke($conguaglio, $righe, fn ($r) => $r->piano_rate_id . '|' . $r->immobile_id . '|' . $r->anagrafica_id, [$secondo->id => (int) $s['e']->id]);

    expect(PianoRate::saldoAssorbito($diElena, $secondo->id, (int) $s['e']->id, $nate))->toBeFalse()
        ->and($senza)->toBe([])
        ->and(b14rlIniziale($s))->toBe(-45000);
});
