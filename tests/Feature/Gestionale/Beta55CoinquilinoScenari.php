<?php

/**
 * 1.11.0-beta.55, Coda 245 — gli scenari condivisi dei test della regola del piano (`Beta55RegolaDelPianoTest`, `Beta55Coinquilino*Test`).
 *
 * Non è un test: il nome non finisce in `Test.php` e Pest non lo raccoglie. Le funzioni hanno il prefisso `rdp` (regola del piano): i nomi
 * delle funzioni di Pest sono globali. Lo scenario è quello di `ScenariFineLocazione` (`fclScenario`), generato e con le rate da gennaio a
 * giugno a giornale.
 */

use App\Services\CalcoloQuoteService;
use App\Services\Riparto\CompetenzaDelPiano;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/ScenariFineLocazione.php';
require_once __DIR__.'/Beta51PassaggiScenari.php';

/**
 * Lo scenario generato con le rate fino al 30/6 a giornale. Opzioni: `titolari` (le righe dell'Interno 1 oltre agli inquilini),
 * `importo`, `ripartizione`, `anno`, `box` come `fclScenario`; `capitoli` (voci in più, come `b51aCapitolo`); `fini` (nome → la data
 * dell'ultimo giorno della riga d'inquilino, già registrata prima della generazione).
 */
function rdpScenario($test, array $inquilini, array $opz = []): array
{
    $anno = (int) ($opz['anno'] ?? 2026);
    $s = fclScenario($opz['titolari'] ?? [['Ugo', 'proprietario', 100]], ['inquilini' => $inquilini] + array_intersect_key($opz, array_flip(['importo', 'ripartizione', 'anno', 'box'])));
    foreach ($opz['capitoli'] ?? [] as $c) {
        b51aCapitolo($s, ...$c);
    }
    foreach ($opz['fini'] ?? [] as $nome => $al) {
        DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone'][$nome]->id)->where('tipologia', 'inquilino')
            ->update(['data_fine' => $al]);
    }
    fclGenera($s);
    fclEmetti($test, $s, "{$anno}-06-30");
    expect(fclEmesse($s))->toBe(6);

    return $s;
}

/**
 * La coppia proposta dall'anteprima per persona, con il segno dei saldi (positivo = debito): ogni coppia porta la riga di chi riceve,
 * e chi esce ha la loro somma cambiata di segno. Solo sull'unità data (l'Interno 1 se non si dice), per nome, senza gli zeri.
 */
function rdpCoppia(array $anteprima, string $esce = 'Ines', ?int $unita = null): array
{
    $out = [];
    foreach ($anteprima['rate']['conguaglio']['coppie'] ?? [] as $c) {
        if ($unita !== null && (int) $c['immobile_id'] !== $unita) {
            continue;
        }
        $out[(string) $c['entrante_nome']] = ($out[(string) $c['entrante_nome']] ?? 0) + (int) $c['importo'];
        $out[$esce] = ($out[$esce] ?? 0) - (int) $c['importo'];
    }
    $out = array_filter($out, fn (int $c) => $c !== 0);
    ksort($out);

    return $out;
}

/** La coppia voce per voce dalla fotografia della regola del piano (`per_gestione[].piano.voci`): voce → [nome → dopo − prima]. */
function rdpPerVoce(array $anteprima, array $s): array
{
    $nomi = fclNomi($s);
    $out = [];
    foreach ($anteprima['rate']['conguaglio']['per_gestione'] ?? [] as $g) {
        foreach ($g['piano']['voci'] ?? [] as $v) {
            foreach (array_keys((array) $v['dopo'] + (array) $v['prima']) as $id) {
                $d = (int) ($v['dopo'][$id] ?? 0) - (int) ($v['prima'][$id] ?? 0);
                if ($d !== 0) {
                    $out[$v['conto']][$nomi[(int) $id] ?? "a{$id}"] = $d;
                }
            }
        }
    }
    foreach ($out as &$x) {
        ksort($x);
    }
    unset($x);
    ksort($out);

    return $out;
}

/**
 * Ines 50 e Dora 50, Ugo proprietario: il «Preventivo 2026» con la sola «Spese generali» (come lo crea la pagina senza voci scelte, quando nel piano
 * dei conti c'è solo lei), generato e con le rate da gennaio a giugno a giornale; la fine di Ines il 1/7 con «Scrivi» dalla rotta. Poi, dalle pagine,
 * una voce nuova «Pulizie scale» (€ 400,00 sulla tabella «Proprietà», 30 % all'inquilino) e un piano della stessa gestione, «Integrativa pulizie 2026»,
 * creato senza voci scelte (prende le voci libere: solo la nuova), generato subito, approvato e con le sue due rate a giornale. Con `$dopo` falso il
 * piano integrativo viene prima della fine di Ines, che allora lo prende. Con `$preventivoDopo` il piano integrativo viene prima della fine di Ines, e il
 * preventivo è generato (con le rate da gennaio a giugno a giornale) dopo: la fine di Ines prende solo il piano integrativo. Con `$emetti` falso il piano
 * integrativo resta con le rate in bozza (si ricalcola ancora). Il piano integrativo è in `$s['integrativa']`, la fine in `$s['fine_ines']`.
 */
function rdpConIntegrativa($test, bool $dopo = true, bool $preventivoDopo = false, bool $emetti = true): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$spese->id => ['importo' => 120000]]);
    $preventivo = function () use ($test, $s): void {
        fclGenera($s);
        fclEmetti($test, $s, '2026-06-30');
        expect(fclEmesse($s))->toBe(6);
    };
    if (! $preventivoDopo) {
        $preventivo();
    }
    foreach (['Luca', 'Elsa'] as $n) {
        fclPersona($s, $n);
    }
    $integrativa = function () use ($test, $s, $pc, $emetti): \App\Models\Gestionale\PianoRate {
        $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
        $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
            'nome' => 'Pulizie scale', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => '400,00', 'descrizione' => '', 'note' => '',
            'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 70, 'percentuale_inquilino' => 30, 'percentuale_usufruttuario' => 0,
            'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
        ])->assertSessionHasNoErrors();
        $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
            'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Integrativa pulizie 2026', 'metodo_distribuzione' => 'prima_rata',
            'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-08-05', 'genera_subito' => true,
            'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: generazione del piano integrativo',
        ])->assertSessionHasNoErrors();
        $piano = \App\Models\Gestionale\PianoRate::where('gestione_id', $s['g']->id)->where('nome', 'Integrativa pulizie 2026')->firstOrFail();
        $test->actingAs($test->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => true, 'data_delibera_assemblea' => '2026-07-15'])
            ->assertSessionHasNoErrors();
        if ($emetti) {
            fds50Emetti($test, $s, '2026-09-30', $piano);
        }
        expect($piano->capitoli()->pluck('nome')->all())->toBe(['Pulizie scale'])
            ->and(DB::table('rate')->where('piano_rate_id', $piano->id)->where('stato', 'emessa')->count())->toBe($emetti ? 2 : 0);

        return $piano->fresh();
    };
    if (! $dopo) {
        $s['integrativa'] = $integrativa();
    }
    $s['fine_ines'] = fclRegistra($test, $s, ['quota' => 50] + fclModulo($s) + ['conguaglio' => 'scrivi'])->fresh();
    if ($dopo) {
        $s['integrativa'] = $integrativa();
    }
    if ($preventivoDopo) {
        $preventivo();
    }

    return $s;
}

/** Il piano ricalcolato adesso dal motore, in sola lettura, sull'Interno 1: nome → centesimi, arrotondati come l'invariante RIC. */
function rdpMotore(array $s): array
{
    $p = $s['piano']->fresh();
    $r = app(CalcoloQuoteService::class)->calcolaPerGestione($p->gestione, $p, soloLettura: true, periodo: app(CompetenzaDelPiano::class)->perPiano($p));
    $nomi = fclNomi($s);
    $out = [];
    foreach ($r as $anagraficaId => $perUnita) {
        foreach ($perUnita as $immobileId => $v) {
            if ((int) $immobileId === (int) $s['unita']->id && (int) round((float) $v) !== 0) {
                $out[$nomi[(int) $anagraficaId] ?? "a{$anagraficaId}"] = (int) round((float) $v);
            }
        }
    }
    ksort($out);

    return $out;
}
