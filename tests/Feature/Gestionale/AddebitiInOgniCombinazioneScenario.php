<?php

/**
 * Gli aiuti della prova degli addebiti in ogni combinazione: la registrazione e l'annullamento dalle rotte della finestra, e la lettura di ogni vista dalle
 * rotte delle pagine (le righe della scrittura, l'estratto conto della persona, il riepilogo, la Sintesi, la situazione patrimoniale, il conguaglio, i
 * controlli, il registro di contabilità). Servono a Via Roma (`AddebitiInOgniCombinazioneTest.php`) e a Via Verdi
 * (`AddebitiInOgniCombinazioneViaVerdiTest.php`): le persone e le unità dello scenario sono in `$s['persone']` e `$s['unita']`, l'amministratore in
 * `$test->user`.
 *
 * NON contiene nessun it() o test — solo funzioni di supporto.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\RataQuote;
use App\Services\Gestionale\Accessori\AccessoriDaIncassare;
use Illuminate\Support\Facades\DB;

/** I centesimi di una cifra scritta come la pagina la scrive («€ 1.234,56»). */
function aocCentesimi(string $testo): int
{
    return (int) preg_replace('/[^0-9-]/', '', $testo);
}

/**
 * Registra gli addebiti dalla rotta della finestra, nella gestione e nell'esercizio dati (di serie l'ordinaria del 2026). Ogni voce: `chi`, `unita`,
 * `euro` (come lo scrive l'amministratore) e il resto come lo manda la finestra.
 */
function aocAddebita($test, array $s, array $voci, ?int $gestioneId = null, ?int $esercizioId = null, ?string $data = null)
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.addebiti.store', [$s['c']]), [
        'gestione_id' => $gestioneId ?? $s['g']->id, 'esercizio_id' => $esercizioId ?? $s['e']->id,
    ] + ($data !== null ? ['data' => $data] : []) + [
        'voci' => array_map(fn (array $v) => array_merge([
            'anagrafica_id' => $s['persone'][$v['chi']]->id, 'immobile_id' => $s['unita'][$v['unita']]->id, 'importo' => $v['euro'],
            'causale' => $v['causale'] ?? 'Contributo a una sola persona', 'scadenza' => $v['scadenza'] ?? \App\Helpers\DateHelper::oggiUtente(),
        ], array_diff_key($v, array_flip(['chi', 'unita', 'euro']))), $voci),
    ]);
}

/** L'annullamento dalla rotta dell'estratto conto. */
function aocAnnulla($test, array $s, int $rataId)
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], $rataId]));
}

/** La quota dell'addebito di una rata. */
function aocQuota(int $rataId): RataQuote
{
    return RataQuote::where('rata_id', $rataId)->firstOrFail();
}

/**
 * Le righe della scrittura di una quota: [ruolo, verso, importo, persona (nome o null), unità (sigla o null), con la rata?], e la gestione,
 * l'esercizio e il giorno della scrittura.
 */
function aocScrittura(array $s, int $scritturaId, ?int $rataId = null): array
{
    $nomi = collect($s['persone'])->mapWithKeys(fn ($p, $k) => [(int) $p->id => $k])->all();
    $sigle = collect($s['unita'])->mapWithKeys(fn ($u, $k) => [(int) $u->id => $k])->all();
    $sc = DB::table('scritture_contabili')->where('id', $scritturaId)->first();

    return [
        'righe' => DB::table('righe_scritture as r')->join('conti_contabili as c', 'c.id', '=', 'r.conto_contabile_id')->where('r.scrittura_id', $scritturaId)
            ->orderBy('r.id')->get(['c.ruolo', 'r.tipo_riga', 'r.importo', 'r.anagrafica_id', 'r.immobile_id', 'r.rata_id'])
            ->map(fn ($r) => [$r->ruolo, $r->tipo_riga, (int) $r->importo, $nomi[(int) $r->anagrafica_id] ?? null, $sigle[(int) $r->immobile_id] ?? null,
                $rataId !== null && (int) $r->rata_id === $rataId])->all(),
        'gestione' => (int) $sc->gestione_id, 'esercizio' => (int) $sc->esercizio_id, 'giorno' => substr((string) $sc->data_competenza, 0, 10),
    ];
}

/** L'estratto conto di una persona dalla rotta della pagina (l'esercizio aperto): le tre cifre in testa e la lista «Addebiti personali». */
function aocEstratto($test, array $s, string $chi): array
{
    $p = $test->actingAs($test->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone'][$chi]]))->assertOk()->viewData('page')['props'];

    return ['addebiti' => aocCentesimi($p['stats']['totale_addebiti']), 'versato' => aocCentesimi($p['stats']['totale_versamenti']), 'saldo' => (int) $p['stats']['saldo_raw'],
        'lista' => array_sum(array_column($p['addebiti'], 'importo')), 'lista_versato' => array_sum(array_column($p['addebiti'], 'versato'))];
}

/**
 * Le viste del rendiconto dell'esercizio dalle rotte delle pagine: il riepilogo (le righe delle quote degli addebiti per gestione, il raccordo, il
 * risultato, il versato per interessi e spese), la Sintesi, la situazione patrimoniale, il conguaglio (per persona e gestione, e i totali), i controlli, il registro.
 */
function aocRendiconto($test, array $s, Esercizio $e): array
{
    $u = $test->actingAs($test->user);
    $nomi = collect($s['persone'])->mapWithKeys(fn ($p, $k) => [(int) $p->id => $k])->all();

    $r = $u->get(route('admin.gestionale.esercizi.rendiconto.riepilogo', [$s['c'], $e]))->assertOk()->viewData('page')['props']['riepilogo'];
    $righe = [];
    $accessori = 0;
    foreach ($r['blocchi'] as $b) {
        foreach ($b['quote'] as $q) {
            if (in_array($q['riga'], ['addebiti', 'spese_addebitate'], true)) {
                $righe[(int) $b['gestione']['id']][$q['riga']] = [$q['emesse'], $q['non_pagate']];
            }
        }
        $accessori += (int) array_sum(array_column($b['versato_accessori'] ?? [], 'importo'));
    }

    $sp = $u->get(route('admin.gestionale.esercizi.stato-patrimoniale.index', [$s['c'], $e]))->assertOk()->viewData('page')['props']['pagina'];
    $passivo = collect($sp['situazione']['passivo']['gruppi'])->flatMap(fn ($g) => $g['voci']);

    $cg = $u->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $e]))->assertOk()->viewData('page')['props']['conguaglio'];
    $perPersona = [];
    foreach ($cg['righe'] as $riga) {
        if (! $riga['subtotale']) {
            $perPersona[$nomi[(int) $riga['anagrafica_id']] ?? (string) $riga['anagrafica_id']][(int) $riga['gestione_id']] = [$riga['a'], $riga['personali'], $riga['b'], $riga['conguaglio'], $riga['posizione'], $riga['saldo']];
        }
    }

    $controlli = $u->get(route('admin.gestionale.esercizi.rendiconto.controlli', [$s['c'], $e]))->assertOk()->viewData('page')['props']['controlli'];
    // La Sintesi e il frontespizio leggono i contributi, le quote a giornale e il risultato dalla stessa fonte del riepilogo: ogni lettura lo riprova.
    $sintesi = $u->get(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $e]))->assertOk()->viewData('page')['props']['sintesi']['prospetto'];
    expect(['contributi' => $sintesi['contributi_addebitati'], 'quote' => $sintesi['quote_giornale'], 'risultato' => $sintesi['risultato']])
        ->toBe(['contributi' => $r['raccordo_quote']['contributi_addebitati'], 'quote' => $r['risultato']['quote_emesse'], 'risultato' => $r['risultato']['risultato']]);
    $reg = $u->get(route('admin.gestionale.esercizi.registro-contabilita.index', [$s['c'], $e]))->assertOk()->viewData('page')['props'];

    return [
        'riepilogo' => ['righe' => $righe, 'contributi' => $r['raccordo_quote']['contributi_addebitati'], 'quote' => $r['risultato']['quote_emesse'],
            'costi' => $r['risultato']['costi'], 'risultato' => $r['risultato']['risultato'], 'accessori' => $accessori],
        'situazione' => ['crediti' => (int) $sp['sintesi']['crediti_condomini'], 'banca' => (int) $sp['sintesi']['liquidita'],
            'gestione_rate' => (int) $passivo->where('ruolo', 'gestione_rate')->sum('saldo'), 'accessori' => (int) $passivo->where('ruolo', AccessoriDaIncassare::RUOLO)->sum('saldo')],
        'conguaglio' => $perPersona,
        'a_di_tutti' => (int) $cg['condominio']['a'],
        'controlli' => collect($controlli)->mapWithKeys(fn ($c) => [$c['id'] => $c['esito']])->all(),
        'registro' => ['entrate' => (int) $reg['riepilogo']['totale_entrate'], 'uscite' => (int) $reg['riepilogo']['totale_uscite'],
            'saldo' => (int) $reg['riepilogo']['saldo_finale'], 'righe' => (int) $reg['righe']['meta']['total']],
    ];
}
