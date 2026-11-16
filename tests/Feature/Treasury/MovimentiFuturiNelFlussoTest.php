<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I movimenti già registrati con una data dopo oggi: non sono nel disponibile di oggi; entro i 30 giorni entrano nel saldo previsto al
 * loro giorno, in tutti e due gli scenari; dopo i 30 giorni no; le aperture mai.
 */

use App\Services\Treasury\TreasuryGuardianService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/TesoreriaScenario.php';

function mffTesoreria(array $s): array
{
    return app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray();
}

/** Il giorno del saldo previsto, per data. */
function mffGiorno(array $t, string $giorno): array
{
    return collect($t['saldoPrevisto']['giorni'])->firstWhere('giorno', $giorno);
}

it('un movimento fra 10 giorni entra nel flusso a quel giorno; uno fra 40 no', function () {
    $s = tsrBase($this);
    $banca = tsrCassa($s['c']->id, 'banca', 'Banca Intesa', '1010');
    tsrScrittura($s, '2026-01-01', 'apertura', $banca->conto_contabile_id, $s['passate'], 100000);
    tsrScrittura($s, '2026-11-25', 'pagamento_fornitore', $s['debiti'], $banca->conto_contabile_id, 30000, 'Pagamento Edil Rossi');
    tsrScrittura($s, '2026-12-25', 'incasso_rata', $banca->conto_contabile_id, $s['crediti'], 9000);
    $t = mffTesoreria($s);

    expect($t['liquiditaDisponibileCents'])->toBe(100000)
        ->and($t['saldoPrevisto']['giorni'])->toHaveCount(31)
        ->and($t['saldoPrevisto']['giorni'][0]['giorno'])->toBe('2026-11-15')
        ->and(mffGiorno($t, '2026-11-24')['saldo'])->toBe(100000)
        ->and(mffGiorno($t, '2026-11-25'))->toMatchArray(['saldo' => 70000, 'con_entrate' => 70000, 'registrati' => -30000, 'quanti_registrati' => 1])
        ->and(mffGiorno($t, '2026-11-25')['voci_registrati'])->toBe([['voce' => 'Pagamento Edil Rossi', 'importo' => -30000]])
        ->and(collect($t['saldoPrevisto']['giorni'])->sum('registrati'))->toBe(-30000)
        ->and($t['saldoPrevisto']['finaleCents'])->toBe(70000)
        ->and($t['scenarioPessimisticoCents'])->toBe(70000)
        // Il movimento fra 40 giorni c'è fra quelli dopo oggi, non nel flusso.
        ->and($t['disponibile']['futuri'])->toMatchArray(['quanti' => 2, 'cifra' => -21000, 'nel_flusso_quanti' => 1, 'nel_flusso_cifra' => -30000]);
});

it('l\'apertura con una data dopo oggi non entra mai nel flusso: è già nella cifra di oggi', function () {
    $s = tsrBase($this);
    $banca = tsrCassa($s['c']->id, 'banca', 'Banca Intesa', '1010');
    tsrScrittura($s, '2026-11-20', 'apertura', $banca->conto_contabile_id, $s['passate'], 250000);
    $t = mffTesoreria($s);

    expect($t['liquiditaDisponibileCents'])->toBe(250000)
        ->and(mffGiorno($t, '2026-11-20')['registrati'])->toBe(0)
        ->and($t['saldoPrevisto']['finaleCents'])->toBe(250000)
        ->and($t['disponibile']['futuri']['quanti'])->toBe(0);
});

it('il saldo previsto sale e scende con le fatture e i registrati; il primo giorno sotto zero è quello della scritta, e il semaforo lo vede anche se l\'ultimo giorno torna a zero', function () {
    $s = tsrBase($this);
    $banca = tsrCassa($s['c']->id, 'banca', 'Banca Intesa', '1010');
    tsrScrittura($s, '2026-01-01', 'apertura', $banca->conto_contabile_id, $s['passate'], 50000);
    $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Edil Rossi srl', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([['45/2026', '2026-11-18', 80000], ['46/2026', '2026-11-30', 10000]] as [$numero, $scadenza, $importo]) {
        DB::table('fatture_passive')->insert(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'fornitore_id' => $fornitore, 'tipo_documento' => 'fattura',
            'numero_documento' => $numero, 'netto_a_pagare' => $importo, 'totale_documento' => $importo, 'importo_imponibile' => $importo, 'importo_iva' => 0,
            'data_scadenza' => $scadenza, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'is_pregresso' => false, 'data_documento' => '2026-11-01',
            'created_at' => now(), 'updated_at' => now()]);
    }
    tsrScrittura($s, '2026-11-25', 'incasso_rata', $banca->conto_contabile_id, $s['crediti'], 40000);
    $t = mffTesoreria($s);

    expect(mffGiorno($t, '2026-11-17')['saldo'])->toBe(50000)
        ->and(mffGiorno($t, '2026-11-18'))->toMatchArray(['saldo' => -30000, 'uscite' => 80000, 'quante_uscite' => 1])
        ->and(mffGiorno($t, '2026-11-18')['voci_uscite'])->toBe([['voce' => 'Edil Rossi srl — n. 45/2026', 'importo' => 80000]])
        ->and(mffGiorno($t, '2026-11-25')['saldo'])->toBe(10000)
        ->and(mffGiorno($t, '2026-11-30')['saldo'])->toBe(0)
        ->and($t['saldoPrevisto']['primoSottoZero'])->toBe('2026-11-18')
        ->and($t['giornoScopertoPrevisto'])->toBe('2026-11-18')
        ->and($t['saldoPrevisto']['minimoCents'])->toBe(-30000)
        ->and($t['saldoPrevisto']['finaleCents'])->toBe(0)
        // Il semaforo guarda il giorno più basso, non solo l'ultimo: senza entrate attese il 18/11 la fattura non è coperta.
        ->and($t['livello'])->toBe('rosso');
});
