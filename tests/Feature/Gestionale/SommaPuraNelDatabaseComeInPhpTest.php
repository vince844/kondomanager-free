<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La copertura del bilancio sommata nel database (`PianoRateQuoteService::totalePuroGeneratoCents()`, Coda 520) contro la stessa somma fatta in PHP quota
 * per quota, com'era prima di passare al database (la funzione `ldcPrima` qui sotto): le due devono dare la stessa cifra su forme diverse.
 *
 * Forme: le quote con il centesimo (un preventivo di € 10.000,01 su 333/333/334 millesimi), i tre metodi di distribuzione con un pregresso a debito e uno a
 * credito, due gestioni nello stesso condominio (una ordinaria e una straordinaria con la gestione chiusa), e gli snapshot scritti a mano con la cifra in forme
 * strane (testo con i decimali, negativa, un elenco, un vero/falso, il testo «abc», zero).
 */

use App\Models\Gestionale\PianoRate;
use App\Services\PianoRateQuoteService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/LetturaDebitiProva.php';
require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

/** La somma fatta in PHP quota per quota, com'era prima di passare al database. */
function ldcPrima(PianoRate $pianoRate): int
{
    $pianoRate = PianoRate::with('rate.rateQuote')->find($pianoRate->id);
    $totale = 0;
    foreach ($pianoRate->rate as $rata) {
        foreach ($rata->rateQuote as $quota) {
            $regole = $quota->regole_calcolo;
            if (is_array($regole) && isset($regole['importi']['quota_pura_gestione'])) {
                $totale += (int) $regole['importi']['quota_pura_gestione'];

                continue;
            }
            if ($rata->numero_rata !== 0 && $quota->tipo !== 'saldo_iniziale') {
                $totale += (int) $quota->importo;
            }
        }
    }

    return $totale;
}

function ldcOggi(PianoRate $p): int
{
    return app(PianoRateQuoteService::class)->totalePuroGeneratoCents(PianoRate::find($p->id));
}

dataset('metodi', ['prima_rata', 'tutte_rate', 'rata_zero']);

it('le quote con il centesimo, con un pregresso a debito e uno a credito: la stessa somma di prima', function (string $metodo) {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 333], 'B' => ['bianchi', 333], 'C' => ['verdi', 334]], 12, $metodo, [['rossi', 'A', 12345], ['bianchi', 'B', -6789]], 1000001);
    $prima = ldcPrima($s['piano']);

    expect(ldcOggi($s['piano']))->toBe($prima)
        // E la somma ha davvero il centesimo: la parte pura delle quote è il preventivo, € 10.000,01.
        ->and($prima)->toBe(1000001);
})->with('metodi');

it('due gestioni nello stesso condominio, una straordinaria chiusa: ogni piano la stessa somma di prima', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 500], 'B' => ['bianchi', 500]], 4, 'prima_rata', [], 333333);
    // Un secondo piano nella stessa gestione, chiusa e straordinaria: lo genera la stessa azione.
    $altro = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Tetto', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 3, 'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-02-10', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($altro, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']);
    // Dopo la generazione la gestione diventa straordinaria e chiusa (la somma non dipende da lei: si prova che resta uguale).
    DB::table('gestioni')->where('id', $s['g']->id)->update(['tipo' => 'straordinaria', 'data_fine' => '2026-06-30']);

    foreach ([$s['piano'], $altro] as $p) {
        expect(ldcOggi($p))->toBe(ldcPrima($p));
    }
});

dataset('cifre strane', [
    'testo con i decimali' => ['1234.56'],
    'negativa con i decimali' => [-1234.99],
    'testo negativo' => ['-77.5'],
    'zero' => [0],
    'il testo abc' => ['abc'],
    'un numero con i decimali' => [12.99],
]);

it('lo snapshot scritto a mano con una cifra strana: la stessa somma di prima', function ($cifra) {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 500], 'B' => ['bianchi', 500]], 4, 'prima_rata', [], 400000);
    $q = DB::table('rate_quote')->where('id', lcdQuota($s, 'rossi', 2))->first();
    $regole = json_decode((string) $q->regole_calcolo, true) ?: [];
    $regole['importi']['quota_pura_gestione'] = $cifra;
    DB::table('rate_quote')->where('id', $q->id)->update(['regole_calcolo' => json_encode($regole)]);

    expect(ldcOggi($s['piano']))->toBe(ldcPrima($s['piano']));
})->with('cifre strane');

it('divergenza teorica, documentata: una cifra scritta come elenco conta 1 centesimo prima ((int) di un array) e 0 oggi; la generazione non la scrive mai', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 500], 'B' => ['bianchi', 500]], 4, 'prima_rata', [], 400000);
    $q = DB::table('rate_quote')->where('id', lcdQuota($s, 'rossi', 2))->first();
    $regole = json_decode((string) $q->regole_calcolo, true) ?: [];
    $regole['importi']['quota_pura_gestione'] = [1, 2];
    DB::table('rate_quote')->where('id', $q->id)->update(['regole_calcolo' => json_encode($regole)]);

    expect(ldcPrima($s['piano']) - ldcOggi($s['piano']))->toBe(1);
});
