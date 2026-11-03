<?php

/**
 * Gli scenari dei test sulla voce di spesa con un importo negativo scritta prima della 1.11.0-beta.52 (la porta da cui entrava è chiusa:
 * qui l'importo si riscrive a mano, come lo lasciavano le versioni precedenti).
 *
 * Il condominio è quello di `Beta52TotaliScenari.php`: le unità A di Ugo e C di Bice sulla tabella «Proprietà» (500 millesimi ciascuna) e il box D
 * di Bice sulla tabella «Box» (1000 millesimi). Le voci: «Spese generali» € 1.200,00 (spesa, «Proprietà»), «Affitto antenna» € 240,00 (entrata,
 * «Box») e «Rimborso vecchio», una spesa nata a € 240,00 e riscritta a −24000 centesimi.
 *
 * Il motore addebita la spesa negativa in positivo (valore assoluto): spese 120000 + 24000 = 144000, entrate 24000, da ripartire 120000. Sono
 * le cifre attese di ogni totale; il motore qui non cambia.
 *
 * Gli stati:
 * - V0: solo il piano dei conti, nessun piano rate.
 * - V2: il piano «Ordinario 2026» creato con la voce ancora positiva («lascia vuoto», 12 rate), poi voce e copia nel piano riscritte a −24000: le quote
 *   restano 120000, perché il motore prende la copia in valore assoluto. Il piano può essere in bozza, approvato, o approvato con la prima rata emessa.
 * - V3: come V2, più il piano «Integrativo 2026» con la sola voce a € 240,00 (copia +24000, quote 24000): la voce scritta −€ 240,00 è addebitata
 *   due volte, € 480,00 in tutto. È il dato che lasciava la creazione prima della correzione.
 * - Il capitolo nel piano (`b53snCapitoloNelPiano`): il capitolo «Manutenzione» scelto come capitolo, con il sottoconto «Rimborso» ancora positivo,
 *   poi «Rimborso» riscritto a −10000; il piano tiene la riga del capitolo, non quella del sottoconto.
 * - Il blocco dei piani approvati (`b53bvScenario`): «Manutenzione» → «Impianti» → «Rimborso» (€ 100,00, poi −10000) e «Pulizie» → «Scale» € 500,00,
 *   con il piano «Preventivo B» che tiene le righe date, approvato dalle rotte.
 *
 * Non è un test: il nome non finisce in `Test.php` e Pest non lo raccoglie.
 *
 * Cosa resta scoperto: niente da solo; i database diversi da sqlite non si provano; i tipi di voce diversi da «spesa» ed «entrata» non si
 * costruiscono (la colonna `conti.tipo` è un enum).
 */

use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Tabella;
use App\Services\CondominioService;
use App\Services\Gestionale\BudgetCoverageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

require_once __DIR__.'/Beta52TotaliScenari.php';

// ─── I testi ────────────────────────────────────────────────────────────────────────────────────────────────────

/** La voce fuori da ogni piano: correggerla è l'unica strada. È la nota del selettore della creazione e della voce scoperta del cruscotto. */
const B53SN_NOTA_FUORI_DAI_PIANI = 'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00. '
    .'Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.';

/** La voce già in un piano: si nomina il piano e si rimanda alla sua pagina, che dice le strade secondo il suo stato. */
const B53SN_NOTA_NEL_PIANO = 'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) ed è nel piano «Ordinario 2026», che la addebita in positivo: '
    .'la pagina del piano dice che cosa fare.';

/** Il rifiuto di una riga delle voci scelte con un importo su una voce di spesa negativa fuori da ogni piano. */
const B53SN_RIFIUTO_FUORI_DAI_PIANI = 'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo. '
    .'Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.';

/** Lo stesso rifiuto con la voce già in un piano: un piano nuovo la addebiterebbe una seconda volta. */
const B53SN_RIFIUTO_NEL_PIANO = 'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) ed è già nel piano «Ordinario 2026», che la addebita in positivo: '
    .'un piano nuovo la addebiterebbe una seconda volta. La pagina di «Ordinario 2026» dice che cosa fare.';

/** La nota in fondo alla distinta per la voce scritta col meno, che la riga mostra al valore del calcolo. */
const B53SN_NOTA_DISTINTA = 'La voce «Rimborso vecchio» ha nel piano dei conti un importo negativo (meno € 240,00): il calcolo delle quote la addebita in positivo, '
    .'e così la conta questa distinta.';

/** Il fermo della creazione della 1.11.0-beta.52, alla lettera: resta com'era. */
const B53SN_FERMO_CREAZIONE = 'Il piano non si crea: la voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00. '
    .'Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno. Poi rimanda il modulo.';

/** La testa del fermo della creazione, comune a ogni strada che lo raggiunge. */
const B53SN_FERMO_CREAZIONE_TESTA = 'Il piano non si crea: la voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00)';

// ─── Gli scenari ────────────────────────────────────────────────────────────────────────────────────────────────

/** Il condominio con le tre unità, l'esercizio 2026, la gestione ordinaria, «Spese generali» € 1.200,00 e «Affitto antenna» € 240,00. */
function b53snBase($test): array
{
    $s = b52tCondominio();
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e);
    $box = Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Box', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    b52tUnita($s, 'A', 'ugo', $s['tabella'], 500.0);
    b52tUnita($s, 'C', 'bice', $s['tabella'], 500.0);
    b52tUnita($s, 'D', 'bice', $box, 1000.0);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti 2026']);
    $s += ['e' => $e, 'g' => $g, 'pc' => $pc, 'box' => $box];
    $s['generali'] = b52tVoce($test, $s, 'Spese generali', 'spesa', '1.200,00', $s['tabella']);
    $s['antenna'] = b52tVoce($test, $s, 'Affitto antenna', 'entrata', '240,00', $box);

    return $s;
}

/** Riscrive l'importo della voce, e la copia che ne tengono i piani dati, come li lasciavano le versioni che accettavano il meno. */
function b53snRiscrivi(Conto $voce, int $cents, array $piani = []): void
{
    DB::table('conti')->where('id', $voce->id)->update(['importo' => $cents]);
    foreach ($piani as $piano) {
        DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $voce->id)->update(['importo' => $cents]);
    }
}

/** V0: la voce «Rimborso vecchio» a −24000, nessun piano rate. */
function b53snV0($test): array
{
    $s = b53snBase($test);
    $s['rimborso'] = b52tVoce($test, $s, 'Rimborso vecchio', 'spesa', '240,00', $s['tabella']);
    b53snRiscrivi($s['rimborso'], -24000);
    $s['rimborso'] = $s['rimborso']->fresh();

    return $s;
}

/**
 * V2: il piano «Ordinario 2026» nato con la voce positiva, poi voce e copia a −24000. `$stato`: «bozza», «approvato», o «emessa» (approvato con la
 * prima rata emessa, senza notifiche).
 */
function b53snV2($test, string $stato = 'bozza'): array
{
    $s = b53snBase($test);
    $s['rimborso'] = b52tVoce($test, $s, 'Rimborso vecchio', 'spesa', '240,00', $s['tabella']);
    $s['piano'] = b52tPiano($test, $s, [], 'Ordinario 2026');
    b53snRiscrivi($s['rimborso'], -24000, [$s['piano']]);
    if ($stato !== 'bozza') {
        b52tApprova($test, $s, $s['piano']);
    }
    if ($stato === 'emessa') {
        b52tEmetti($test, $s, $s['piano'], 1, false);
    }
    $s['rimborso'] = $s['rimborso']->fresh();
    $s['piano'] = $s['piano']->fresh();

    return $s;
}

/** V3: V2 in bozza più «Integrativo 2026», una rata, con la sola voce a € 240,00 (copia +24000): la voce è addebitata due volte. */
function b53snV3($test): array
{
    $s = b53snBase($test);
    $s['rimborso'] = b52tVoce($test, $s, 'Rimborso vecchio', 'spesa', '240,00', $s['tabella']);
    $s['piano'] = b52tPiano($test, $s, [], 'Ordinario 2026');
    $s['secondo'] = b52tPiano($test, $s, [
        'capitoli_ids' => [$s['rimborso']->id], 'capitoli_config' => [['id' => $s['rimborso']->id, 'importo' => '240,00', 'note' => '']],
        'numero_rate' => 1, 'data_prima_scadenza' => '2026-06-05', 'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova',
    ], 'Integrativo 2026');
    b53snRiscrivi($s['rimborso'], -24000, [$s['piano']]);
    $s['rimborso'] = $s['rimborso']->fresh();
    $s['piano'] = $s['piano']->fresh();
    $s['secondo'] = $s['secondo']->fresh();

    return $s;
}

/**
 * Un capitolo «Manutenzione» con due sottoconti di spesa sulla tabella «Proprietà»: «Ascensore» € 600,00 e «Rimborso», nato a € 100,00 e riscritto
 * a −10000. Nessun'altra voce, nessun piano rate. Il motore conta i sottoconti in valore assoluto: preventivo 60000 + 10000 = 70000.
 */
function b53snCapitolo($test): array
{
    $s = b52tCondominio();
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e);
    b52tUnita($s, 'A', 'ugo', $s['tabella'], 500.0);
    b52tUnita($s, 'C', 'bice', $s['tabella'], 500.0);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti 2026']);
    $s += ['e' => $e, 'g' => $g, 'pc' => $pc];
    $s['capitolo'] = b52tVoce($test, $s, 'Manutenzione', 'spesa', '0,00', $s['tabella'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null,
        'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    $s['ascensore'] = b52tVoce($test, $s, 'Ascensore', 'spesa', '600,00', $s['tabella'], $s['capitolo']->id, ['isSottoConto' => true]);
    $s['rimborso'] = b52tVoce($test, $s, 'Rimborso', 'spesa', '100,00', $s['tabella'], $s['capitolo']->id, ['isSottoConto' => true]);
    b53snRiscrivi($s['rimborso'], -10000);
    $s['rimborso'] = $s['rimborso']->fresh();

    return $s;
}

/**
 * Il capitolo «Manutenzione» di `b53snCapitolo` scelto come capitolo nel piano «Ordinario 2026», con «Rimborso» ancora a € 100,00; poi «Rimborso»
 * riscritto a −10000. Il piano tiene la riga del capitolo e nessuna riga del sottoconto.
 *
 * `$copia`: la cifra scritta per il capitolo alla creazione; vuota, la copia è nulla e il motore legge l'importo del capitolo e dei sottoconti.
 * `$stato`: «bozza» o «approvato». `$tabellaSulCapitolo`: il capitolo ha la sua tabella («Proprietà», tutta al proprietario). `$natoDopo`:
 * «Rimborso» risulta nato il giorno dopo il piano.
 */
function b53snCapitoloNelPiano($test, string $copia = '', string $stato = 'bozza', bool $tabellaSulCapitolo = false, bool $natoDopo = false): array
{
    $s = b53snCapitolo($test);
    DB::table('conti')->where('id', $s['rimborso']->id)->update(['importo' => 10000]);
    if ($tabellaSulCapitolo) {
        $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $s['capitolo']->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }
    $s['piano'] = b52tPiano($test, $s, ['capitoli_ids' => [$s['capitolo']->id], 'capitoli_config' => [['id' => $s['capitolo']->id, 'importo' => $copia, 'note' => '']]], 'Ordinario 2026');
    b53snRiscrivi($s['rimborso'], -10000);
    if ($natoDopo) {
        DB::table('conti')->where('id', $s['rimborso']->id)->update(['created_at' => $s['piano']->created_at->copy()->addDay()]);
    }
    if ($stato === 'approvato') {
        b52tApprova($test, $s, $s['piano']);
    }
    $s['rimborso'] = $s['rimborso']->fresh();
    $s['piano'] = $s['piano']->fresh();

    return $s;
}

/**
 * La voce «Rimborso» al terzo livello, come la lasciavano le versioni fino alla 1.9.1: «Manutenzione» → «Impianti» → «Rimborso», nata a € 100,00 sulla
 * tabella «Proprietà» e riscritta a −10000, accanto alle voci di `b53snBase`. Il piano «Ordinario 2026», in bozza, tiene «Spese generali» e basta:
 * nessun piano addebita «Rimborso». La modifica della voce si salva solo con un capitolo padre di primo livello (`UpdateContoRequest`).
 */
function b53snTerzoLivello($test): array
{
    $s = b53snBase($test);
    $voce = fn (string $nome, int $importo, ?int $padre, bool $capitolo) => Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => $nome, 'tipo' => 'spesa',
        'natura_spesa' => 'ordinaria', 'importo' => $importo, 'parent_id' => $padre, 'is_capitolo' => $capitolo]);
    $s['capitolo'] = $voce('Manutenzione', 0, null, true);
    $s['impianti'] = $voce('Impianti', 0, $s['capitolo']->id, true);
    $s['rimborso'] = $voce('Rimborso', 10000, $s['impianti']->id, false);
    $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $s['rimborso']->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $s['piano'] = b52tPiano($test, $s, ['capitoli_ids' => [$s['generali']->id], 'capitoli_config' => [['id' => $s['generali']->id, 'importo' => '', 'note' => '']]], 'Ordinario 2026');
    b53snRiscrivi($s['rimborso'], -10000);
    $s['rimborso'] = $s['rimborso']->fresh();
    $s['piano'] = $s['piano']->fresh();

    return $s;
}

/** La modifica di una voce dal piano dei conti, come la manda la sua scheda: il tipo, l'importo e il capitolo padre scelti. */
function b53snModificaVoce($test, array $s, Conto $voce, string $tipo, string $importo, ?int $padre)
{
    session()->forget('errors');

    return $test->actingAs($test->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $voce]), [
        'nome' => $voce->nome, 'codice' => '', 'tipo' => $tipo, 'importo' => $importo, 'descrizione' => '', 'note' => '', 'parent_id' => $padre,
        'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => $padre !== null, 'richiedeGiaVersato' => false,
    ]);
}

/** Quanto il motore ripartisce per voce sul piano dato, rileggendolo in sola lettura: conto => centesimi. */
function b53snRipartito(array $s, PianoRate $piano): array
{
    $motore = app(\App\Services\CalcoloQuoteService::class);
    $motore->calcolaPerGestione($s['g']->fresh(), $piano->fresh(), true);

    return array_map('intval', $motore->getImportiPerConto());
}

// ─── Che cosa vede l'amministratore ─────────────────────────────────────────────────────────────────────────────

/** Le proprietà di una pagina Inertia (la risposta deve essere 200). */
function b53snProps($test, string $rotta, array $parametri): array
{
    $r = $test->actingAs($test->user)->get(route($rotta, $parametri));
    $r->assertOk();

    return $r->viewData('page')['props'];
}

/** La pagina del piano dei conti. */
function b53snPianoDeiConti($test, array $s): array
{
    return b53snProps($test, 'admin.gestionale.esercizi.piani-conti.show', [$s['c'], $s['e'], $s['pc']]);
}

/** La riga del piano dei conti nell'elenco dei piani dei conti dell'esercizio. */
function b53snElenco($test, array $s): array
{
    return json_decode(json_encode(b53snProps($test, 'admin.gestionale.esercizi.piani-conti.index', [$s['c'], $s['e']])['pianiDeiConti'][0]), true);
}

/** La riga di una voce dell'albero del piano dei conti (`ContoResource`), cercata per nome anche fra i sottoconti. */
function b53snRiga($test, array $s, string $nome): array
{
    $conti = json_decode(json_encode(b53snPianoDeiConti($test, $s)['conti']), true);
    $cerca = function (array $righe) use (&$cerca, $nome): ?array {
        foreach ($righe as $riga) {
            if (($riga['nome'] ?? null) === $nome) {
                return $riga;
            }
            if (($trovata = $cerca($riga['sottoconti'] ?? [])) !== null) {
                return $trovata;
            }
        }

        return null;
    };

    return $cerca($conti) ?? [];
}

/** Le righe di copertura di una riga dell'albero, ridotte a [piano, id del piano, importo] e ordinate per piano. */
function b53snDettaglio(array $riga): array
{
    return collect($riga['dettaglio_copertura'] ?? [])->map(fn ($d) => [$d['piano'], $d['piano_rate_id'], (int) $d['importo']])->sortBy(1)->values()->all();
}

/** Il testo reso della distinta delle spese (spazi normalizzati, senza gli stili) e i dati con cui è stata resa. */
function b53snDistinta($test, array $s): array
{
    $dati = null;
    View::composer('pdf.gestionale.distinta_spese', function ($v) use (&$dati) {
        $dati ??= $v->getData();
    });
    $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.piani-conti.print-distinta', [$s['c'], $s['e'], $s['pc']]))->assertOk();
    $html = preg_replace('#<style.*?</style>#si', '', view('pdf.gestionale.distinta_spese', $dati)->render());

    return [preg_replace('/\s+/u', ' ', trim(strip_tags($html))), $dati];
}

/** Il selettore della creazione del piano, come lo chiama la pagina: le voci per id. */
function b53snSelettore($test, array $s): \Illuminate\Support\Collection
{
    $r = $test->actingAs($test->user)->getJson(route('admin.gestionale.fetch-capitoli-gestione', ['condominio' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id]));
    $r->assertOk();

    return collect($r->json())->keyBy('id');
}

/** La copertura del cruscotto. */
function b53snCruscotto($test, array $s): array
{
    return json_decode(json_encode(b53snProps($test, 'admin.gestionale.index', [$s['c']->id])['copertura']), true);
}

/** La pagina del piano rate: il piano, la copertura («voci non coperte») e l'avviso della spesa negativa. */
function b53snPaginaPiano($test, array $s, PianoRate $piano): array
{
    $p = b53snProps($test, 'admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $piano]);

    return json_decode(json_encode(['pianoRate' => $p['pianoRate'], 'copertura' => $p['copertura'] ?? null, 'avviso_spesa_negativa' => $p['avviso_spesa_negativa'] ?? null]), true);
}

/** La copertura di una voce secondo `BudgetCoverageService`, cercata per nome. */
function b53snCopertura(array $s, string $nome): array
{
    return collect(app(BudgetCoverageService::class)->analyze($s['g']->fresh())['items'])->firstWhere('nome', $nome) ?? [];
}

/** La creazione di un piano dalla rotta, senza pretendere che riesca: la risposta. */
function b53snCrea($test, array $s, array $extra, string $nome = 'Integrativo 2026')
{
    return $test->actingAs($test->user)->from('/pagina-di-creazione')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), array_merge([
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-06-05', 'genera_subito' => true,
        'capitoli_ids' => [], 'capitoli_config' => [], 'competenze_capitoli' => [], 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
        'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova',
    ], $extra));
}

/** Gli errori di validazione della sessione, per campo. */
function b53snErrori(): array
{
    return session('errors')?->getBag('default')->toArray() ?? [];
}

/** Il messaggio di sessione di un rifiuto o di un successo («type», «message»). */
function b53snFlash(): ?array
{
    return session('message');
}

/** Le copie che i piani della gestione tengono per la voce: id del piano => importo in centesimi. */
function b53snCopie(array $s, Conto $voce): array
{
    return DB::table('piano_rate_capitoli')->where('conto_id', $voce->id)->orderBy('piano_rate_id')->pluck('importo', 'piano_rate_id')
        ->map(fn ($v) => $v === null ? null : (int) $v)->all();
}

// ─── Il blocco dei piani approvati ──────────────────────────────────────────────────────────────────────────────

/** Lega la voce alla tabella «Proprietà», tutta al proprietario. */
function b53bvLega(array $s, int $contoId): void
{
    $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $contoId, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * Lo scenario del blocco dei piani approvati: `$righe` sono le righe di «Preventivo B» (chiave dello scenario => copia, '' per la copia nulla), creato e approvato dalle rotte se
 * `$approva`; `$altri` altri piani approvati, [nome => righe]. `$secondoLivello`: «Impianti» è di primo livello, e «Rimborso» il suo sottoconto.
 */
function b53bvScenario($test, array $righe, bool $approva = true, array $altri = [], bool $secondoLivello = false): array
{
    $s = b53snBase($test);
    $voce = fn (string $nome, int $importo, ?int $padre, bool $capitolo) => Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => $nome, 'tipo' => 'spesa',
        'natura_spesa' => 'ordinaria', 'importo' => $importo, 'parent_id' => $padre, 'is_capitolo' => $capitolo]);
    $s['capitolo'] = $voce('Manutenzione', 0, null, true);
    $s['impianti'] = $voce('Impianti', 0, $secondoLivello ? null : $s['capitolo']->id, true);
    $s['rimborso'] = $voce('Rimborso', 10000, $s['impianti']->id, false);
    b53bvLega($s, (int) $s['rimborso']->id);
    $s['pulizie'] = $voce('Pulizie', 0, null, true);
    $s['scale'] = $voce('Scale', 50000, $s['pulizie']->id, false);
    b53bvLega($s, (int) $s['scale']->id);
    DB::table('conti')->where('piano_conto_id', $s['pc']->id)->update(['created_at' => now()->subDay()]);

    $crea = function (string $nome, array $righe) use ($test, $s) {
        $ids = array_map(fn ($k) => (int) $s[$k]->id, array_keys($righe));

        return b52tPiano($test, $s, ['capitoli_ids' => $ids, 'capitoli_config' => array_map(fn ($id, $copia) => ['id' => $id, 'importo' => $copia, 'note' => ''], $ids, array_values($righe))], $nome);
    };
    $s['b'] = $crea('Preventivo B', $righe);
    if ($approva) {
        b52tApprova($test, $s, $s['b']);
    }
    foreach ($altri as $nome => $r) {
        $s['altri'][$nome] = $crea($nome, $r);
        b52tApprova($test, $s, $s['altri'][$nome]);
    }
    $s['piano'] = b52tPiano($test, $s, ['capitoli_ids' => [$s['generali']->id], 'capitoli_config' => [['id' => $s['generali']->id, 'importo' => '', 'note' => '']]], 'Ordinario 2026');
    b53snRiscrivi($s['rimborso'], -10000);
    $s['rimborso'] = $s['rimborso']->fresh();
    $s['b'] = $s['b']->fresh();

    return $s;
}

/** Il tipo, l'importo e il padre della voce, letti dal database. */
function b53bvVoce(Conto $voce): array
{
    return (array) DB::table('conti')->where('id', $voce->id)->first(['tipo', 'importo', 'parent_id']);
}

/** Che cosa il piano chiede: il motore per voce e il totale della sua pagina. */
function b53bvPiano($test, array $s, PianoRate $piano): array
{
    return ['motore' => b53snRipartito($s, $piano), 'totale' => b53snPaginaPiano($test, $s, $piano)['pianoRate']['totale_spese'] ?? null];
}

/** Il piano riportato in bozza dalla sua pagina. */
function b53bvInBozza($test, array $s, PianoRate $piano): void
{
    $test->actingAs($test->user)->from('/pagina-del-piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => false])
        ->assertSessionHasNoErrors();
}
