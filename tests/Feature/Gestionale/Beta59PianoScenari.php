<?php

/**
 * Aiuti dei test sul piano rate della 1.11.0-beta.59: le quote non assegnate accettate (Coda 414) e la ricorrenza avanzata del piano
 * scadenze (Coda 419).
 *
 * Cosa resta scoperto: nessun test qui, solo gli scenari; i database diversi da sqlite non si provano.
 *
 * Gli scenari si costruiscono dalle rotte, come la pagina: le voci dal modale della nuova voce (`b52tVoce`), il piano dalla rotta di
 * creazione con i campi che manda `PianiRateNew.vue` (il `useForm` della pagina, con i suoi valori iniziali), il ricalcolo dalla rotta
 * `regenerate`, l'approvazione e l'emissione dalle rotte dell'amministratore.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\CondominioService;
use App\Services\PianoRateQuoteService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52TotaliScenari.php';

/**
 * Il condominio di base: esercizio e gestione dal 01/01/2026, la tabella «Proprietà» con A di Ugo e C di Bice (500 millesimi ciascuna)
 * e la voce «Spese generali» da € 1.200,00 su quella tabella, al 100 % al proprietario.
 */
function b59pScenarioBase($test, string $spese = '1.200,00'): array
{
    $s = b52tCondominio();
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e, 'ordinaria', '2026-01-01', '2026-12-31');
    b52tUnita($s, 'A', 'ugo', $s['tabella'], 500.0);
    b52tUnita($s, 'C', 'bice', $s['tabella'], 500.0);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $s += ['e' => $e, 'g' => $g, 'pc' => $pc];
    $s['spesa'] = b52tVoce($test, $s, 'Spese generali', 'spesa', $spese, $s['tabella']);

    return $s;
}

/**
 * Il corpo che manda `PianiRateNew.vue` (il suo `useForm`, con i valori iniziali della pagina), con le modifiche di `$extra`. La pagina
 * scrive «Intervallo» in un campo di testo senza `.number`: l'intervallo arriva come stringa.
 */
function b59pCorpoDellaPagina(array $s, array $extra = []): array
{
    return array_merge([
        'tipo' => 'ordinario', 'origine' => '', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2026', 'descrizione' => '',
        'metodo_distribuzione' => 'rata_zero', 'saldi_config' => [], 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => null,
        'note' => '', 'genera_subito' => true, 'recurrence_enabled' => false, 'recurrence_frequency' => 'MONTHLY', 'recurrence_interval' => 1,
        'recurrence_by_day' => [], 'capitoli_ids' => [], 'capitoli_config' => [], 'competenze_capitoli' => [], 'fatture_config' => [],
        'tipo_autorizzazione' => '', 'motivazione_autorizzazione' => '', 'data_delibera_assemblea' => '',
        'accetta_scoperti' => false, 'nota_scoperti' => '', 'accetta_destinatari' => false, 'nota_destinatari' => '',
    ], $extra);
}

/** Il piano creato dalla rotta della pagina; torna il piano riletto (o null se la rotta non l'ha creato). */
function b59pCreaDallaPagina($test, array $s, array $extra = []): ?PianoRate
{
    $corpo = b59pCorpoDellaPagina($s, $extra);
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), $corpo)->assertSessionHasNoErrors();

    return PianoRate::where('gestione_id', $s['g']->id)->where('nome', $corpo['nome'])->first();
}

/** Le scadenze delle rate del piano (senza la rata 0 dei saldi), come «Y-m-d», in ordine di numero. */
function b59pScadenze(PianoRate $piano): array
{
    return DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', '>', 0)->orderBy('numero_rata')
        ->pluck('data_scadenza')->map(fn ($d) => substr((string) $d, 0, 10))->all();
}

// ── Coda 414: la voce del riscaldamento del v34 ─────────────────────────────────────────────────────────────────

/**
 * Il caso del v34: la voce «Riscaldamento» preventivata a € 4.000,00 sulla tabella «Riscaldamento» (A di Ugo 500, C di Bice 470, la
 * Cantina 1 30 millesimi, senza proprietario). Il piano ne rateizza € 2.500,00: la Cantina 1 vale € 75,00 (2.500,00 × 30 / 1000), che
 * nessuno paga. Il piano non è ancora creato.
 */
function b59pScenarioV34($test, bool $conCantinaSenzaProprietario = true): array
{
    $s = b52tCondominio();
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e, 'ordinaria', '2026-01-01', '2026-12-31');
    $risc = Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    b52tUnita($s, 'A', 'ugo', $risc, 500.0);
    b52tUnita($s, 'C', 'bice', $risc, 470.0);
    // La Cantina 1: nella tabella con i suoi millesimi, nessuna riga di titolarità.
    $s['unita']['Cantina'] = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'cantina', 'codice_immobile' => "B59P-{$s['seq']}-CANT", 'nome' => 'Cantina 1', 'interno' => 'C1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $risc->id, 'immobile_id' => $s['unita']['Cantina']->id, 'valore' => 30.0, 'created_at' => now(), 'updated_at' => now()]);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $s += ['e' => $e, 'g' => $g, 'pc' => $pc, 'risc' => $risc];
    $s['riscaldamento'] = b52tVoce($test, $s, 'Riscaldamento', 'spesa', '4.000,00', $risc);
    if (! $conCantinaSenzaProprietario) {
        b59pCensisci($s, 'Cantina', 'ugo');
    }

    return $s;
}

/** Il proprietario censito sull'unità (la riga di titolarità come la scrive l'anagrafe), dal 2019. */
function b59pCensisci(array $s, string $unita, string $chi): void
{
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita'][$unita]->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * Il piano del v34 dalla pagina: la sola voce del riscaldamento per € 2.500,00. Con `$procedi` la seconda richiesta è quella di
 * «Procedi comunque» (`handleProcedi`: `accetta_scoperti` e la nota, il resto del form uguale).
 */
function b59pPianoV34($test, array $s, bool $procedi = true, string $nota = 'Cantina 1 senza proprietario al rogito', array $extra = []): ?PianoRate
{
    $base = array_merge([
        'nome' => 'Riscaldamento 2026', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 4, 'data_prima_scadenza' => '2026-01-05',
        'capitoli_ids' => [$s['riscaldamento']->id], 'capitoli_config' => [['id' => $s['riscaldamento']->id, 'importo' => '2.500,00', 'note' => '']],
    ], $extra);
    if (! $procedi) {
        return b59pCreaDallaPagina($test, $s, $base);
    }

    return b59pCreaDallaPagina($test, $s, $base + ['accetta_scoperti' => true, 'nota_scoperti' => $nota]);
}

/** «Ricalcola» dalla pagina del piano (`PianoRateGenerationController`), con o senza la presa d'atto degli scoperti. */
function b59pRicalcola($test, array $s, PianoRate $piano, ?string $nota = null): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]),
        $nota === null ? [] : ['accetta_scoperti' => true, 'nota_scoperti' => $nota]);
}

/** Atteso, generato e se il piano è disallineato, riletto dal database (`PianoRateQuoteService`). */
function b59pMisura(PianoRate $piano): array
{
    $svc = app(PianoRateQuoteService::class);
    $piano = PianoRate::find($piano->id);

    return ['atteso' => $svc->totaleAttesoCents($piano), 'generato' => $svc->totalePuroGeneratoCents($piano), 'disallineato' => $svc->eDisallineato($piano)];
}

/** Le props Inertia di una pagina dell'amministratore. */
function b59pProps($test, string $rotta, array $parametri): array
{
    $r = $test->actingAs($test->user)->get(route($rotta, $parametri));
    $r->assertOk();

    return $r->viewData('page')['props'];
}

/** Le props della pagina del piano (`PianoRateController::show`). */
function b59pPagina($test, array $s, PianoRate $piano): array
{
    return b59pProps($test, 'admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $piano]);
}

/** Le props del cruscotto del condominio (`DashboardController`). */
function b59pCruscotto($test, array $s): array
{
    return b59pProps($test, 'admin.gestionale.index', [$s['c']->id]);
}

/**
 * La seconda voce dello scenario del v34: «Pulizie» da € 1.000,00 sulla tabella «Proprietà» (A di Ugo e C di Bice, 500 millesimi ciascuna, la
 * Cantina 1 non c'è): nessuna quota non assegnata.
 */
function b59pPulizie($test, array &$s): void
{
    foreach (['A', 'C'] as $sigla) {
        DB::table('quote_tabella')->insert(['tabella_id' => $s['tabella']->id, 'immobile_id' => $s['unita'][$sigla]->id, 'valore' => 500.0, 'created_at' => now(), 'updated_at' => now()]);
    }
    $s['pulizie'] = b52tVoce($test, $s, 'Pulizie', 'spesa', '1.000,00', $s['tabella']);
}

/** Le due voci del v34 nel piano: il riscaldamento per € 2.500,00 e le pulizie per intero. */
function b59pConPulizie(array $s): array
{
    return ['capitoli_ids' => [$s['riscaldamento']->id, $s['pulizie']->id], 'capitoli_config' => [
        ['id' => $s['riscaldamento']->id, 'importo' => '2.500,00', 'note' => ''],
        ['id' => $s['pulizie']->id, 'importo' => '1.000,00', 'note' => ''],
    ]];
}

/** La colonna delle quote non assegnate, riletta (array o null). */
function b59pColonna(PianoRate $piano): ?array
{
    return PianoRate::find($piano->id)->quote_non_assegnate;
}
