<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Gli aiuti delle prove degli interessi e delle spese per il ritardo, su Via Verdi (`ConsuntivoPerPersonaPosizioneScenario.php`) con un preventivo di
 * € 2.400,00 (Pulizie € 1.800,00, Manutenzioni € 600,00) in quattro rate: Neri, 500 millesimi su U1, ha € 300,00 a rata. La rata 1 scade il 31/03/2026
 * ed è emessa il 15/01; le altre scadono il 30/06, il 30/09 e il 31/12 e restano in bozza. Nel piano dei conti una voce di entrata «Interessi di mora»,
 * con il suo mastro, e una voce di spesa «Spese postali».
 *
 * NON contiene nessun it() o test — solo funzioni di supporto.
 */

use App\Models\Gestionale\ContoContabile;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AddebitiScenario.php';

function accViaVerdi($test, array $opz = []): array
{
    $s = cpcViaVerdi($test, $opz);
    DB::table('conti')->where('id', $s['pulizie']->id)->update(['importo' => 180000]);
    DB::table('conti')->where('id', $s['manutenzioni']->id)->update(['importo' => 60000]);
    $s['piano']->rate()->delete();
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']);
    $s['piano'] = $s['piano']->fresh();
    foreach ([1 => '2026-03-31', 2 => '2026-06-30', 3 => '2026-09-30', 4 => '2026-12-31'] as $n => $giorno) {
        $rata = lcdRata($s, $n);
        DB::table('rate')->where('id', $rata)->update(['data_scadenza' => $giorno]);
        DB::table('rate_quote')->where('rata_id', $rata)->update(['data_scadenza' => $giorno]);
    }
    $s['interessi'] = accVoce($s, 'Interessi di mora', 'entrata');
    $s['postali'] = accVoce($s, 'Spese postali', 'spesa');
    $test->travelTo('2026-01-15 10:00:00');
    lcdEmetti($test, $s, [1], '2026-01-15');

    return $s;
}

/** Una voce del piano dei conti di Via Verdi con il suo mastro di costo, sulla tabella generale. */
function accVoce(array $s, string $nome, string $tipo): \App\Models\Gestionale\Conto
{
    static $n = 0;
    $n++;
    $mastro = ContoContabile::create(['condominio_id' => $s['c']->id, 'codice' => 'ACC' . $n . '-' . $s['c']->id, 'nome' => $nome, 'tipo' => 'costo', 'categoria' => 'costi']);
    $voce = cpmVoce($s, $nome, [[$s['generali'], 100]], $tipo, 0);
    DB::table('conti')->where('id', $voce->id)->update(['conto_contabile_id' => $mastro->id]);

    return $voce->fresh();
}

/** La proposta degli interessi dalla rotta, alla data, per le persone date per nome. */
function accProposta($test, array $s, array $chi, ?string $alla = null)
{
    return $test->actingAs($test->user)->getJson(route('admin.gestionale.addebiti.interessi.proposta', [$s['c']]) . '?' . http_build_query(array_filter([
        'alla' => $alla, 'persone' => array_map(fn ($n) => $s['persone'][$n]->id, $chi),
    ])));
}

/** La proposta di una persona, dalla risposta. */
function accDi(array $proposta, array $s, string $chi): ?array
{
    return collect($proposta['persone'])->firstWhere('anagrafica_id', $s['persone'][$chi]->id);
}

/** Registra tutte le righe della proposta data, con la voce data per ogni gestione. */
function accRegistra($test, array $s, array $proposta, ?int $voce = null)
{
    // Le righe che la finestra tiene: senza fermo e con gli interessi (una riga a zero porta solo la frase del riallineo).
    $righe = collect($proposta['persone'])->flatMap(fn ($p) => array_column(array_filter($p['righe'], fn ($r) => $r['fermo'] === null && $r['interessi'] > 0), 'chiave'))->values()->all();

    return $test->actingAs($test->user)->postJson(route('admin.gestionale.addebiti.interessi.store', [$s['c']]), [
        'alla' => $proposta['alla'], 'righe' => $righe, 'atteso' => $proposta['totale'],
        'voci' => collect($proposta['gestioni'])->mapWithKeys(fn ($g) => [$g['gestione_id'] => $voce ?? $s['interessi']->id])->all(),
    ]);
}

/** Le righe della scrittura di emissione di un addebito: [ruolo, verso, importo, con persona?, con l'unità?, con la rata?]. */
function accEmissione(int $rataId): array
{
    $sc = (int) \App\Models\Gestionale\RataQuote::where('rata_id', $rataId)->value('scrittura_contabile_id');

    return DB::table('righe_scritture as r')->join('conti_contabili as c', 'c.id', '=', 'r.conto_contabile_id')->where('r.scrittura_id', $sc)->orderBy('r.id')
        ->get(['c.ruolo', 'r.tipo_riga', 'r.importo', 'r.anagrafica_id', 'r.immobile_id', 'r.rata_id'])
        ->map(fn ($r) => [$r->ruolo, $r->tipo_riga, (int) $r->importo, $r->anagrafica_id !== null, $r->immobile_id !== null, (int) $r->rata_id === $rataId])->all();
}
