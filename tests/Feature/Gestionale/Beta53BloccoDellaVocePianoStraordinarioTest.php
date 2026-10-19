<?php

/**
 * Il blocco dei piani approvati su una voce del piano dei conti, per i piani straordinari.
 *
 * Un piano straordinario non tiene le voci con le sue righe: addebita le fatture che gli sono collegate (`piano_rate_fatture`), ripartendo ogni riga sulle
 * tabelle della voce su cui è registrata. Finché un piano straordinario approvato (o emesso, o chiuso) finanzia una fattura con una riga di sopravvenienza sulla voce,
 * o con una copertura di sopravvenienza sulla voce, la voce non cambia importo, padre, tipo, tabella né percentuali, con la regola e la frase dei piani ordinari
 * (`Conto::pianiCheBloccanoLaVoce`, `BloccoDellaVoce`): le tabelle e le percentuali sono ciò che il motore straordinario legge per ripartire la riga. La strada della
 * frase è riportare il piano in bozza dalla sua pagina. Una riga ordinaria della stessa fattura, a preventivo, non blocca la sua voce: il piano straordinario
 * non la addebita.
 *
 * Lo scenario è quello di `Beta53BloccoDellaVoceTest`: «Pulizie» (capitolo) → «Scale» € 500,00 sulla tabella «Proprietà» (tutta al proprietario, Ugo e Bice a
 * metà); la tabella «Box» è tutta di Bice. Il piano «Facciata 2026» è straordinario, con una fattura di € 1.000,00 registrata sulla voce «Scale» come sopravvenienza.
 *
 * La prova indipendente è il motore straordinario riletto in sola lettura e le quote salvate del piano, per persona, dopo il ricalcolo dalla sua pagina:
 * un rifiuto lascia tutto com'era. A mano: € 1.000,00 × 500/1000 = € 500,00 a testa sulla tabella «Proprietà»; sulla tabella «Box» tutto € 1.000,00 a Bice.
 *
 * Fonte dell'atteso: la decisione di Vincenzo del 19/10/2026 (il blocco dei piani approvati vale per ogni modifica che cambia ciò che il piano addebita), letta
 * per i piani straordinari sulle righe che il loro motore addebita, e i conti a mano degli scenari.
 *
 * Cosa resta scoperto: la pagina del piano dei conti (la provano i vitest di `DettaglioConto` e di `ModalModificaConto`); i database diversi da sqlite.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta53SpesaNegativaScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Quello che il programma dice di non cambiare, nel rifiuto della modifica di una voce. */
const B53BS_COSA = 'l\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano';

/** La strada della bozza per un piano solo, in coda a ogni rifiuto. */
const B53BS_BOZZA = ': riporta prima il piano in bozza dalla sua pagina, poi modifica la voce. Riportandolo in bozza si azzerano il numero del verbale e la nota '
    .'dell\'approvazione: annotali prima; con delle rate emesse, prima vanno annullate le emissioni.';

/** Il rifiuto della modifica di «Scale» per il piano «Facciata 2026». */
const B53BS_RIFIUTO = 'Modifica inibita: '.B53BS_COSA.' finché la voce è nel piano «Facciata 2026», approvato'.B53BS_BOZZA;

/**
 * Una fattura di € 1.000,00 registrata sulla voce come sopravvenienza e un piano straordinario che la finanzia per intero, con le quote generate dalla sua pagina e
 * lo stato dato (`bozza` o `approvato`, dalla rotta). Con `$ordinaria` la fattura è mista: ha anche una riga ordinaria di € 200,00 su quella voce, a preventivo,
 * che il motore straordinario non legge.
 */
function b53bsPianoStraordinario($test, array $s, Conto $voce, string $stato = 'approvato', string $nome = 'Facciata 2026', ?Conto $ordinaria = null): PianoRate
{
    $totale = $ordinaria === null ? 100000 : 120000;
    $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Ditta Prova '.$nome, 'soggetto_ritenuta' => false, 'modalita_pagamento_default' => 'bonifico',
        'created_at' => now(), 'updated_at' => now()]);
    $fattura = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'fornitore_id' => $fornitore, 'tipo_documento' => 'fattura', 'numero_documento' => 'F'.$voce->id.'-'.$nome,
        'data_documento' => '2026-03-10', 'data_scadenza' => '2026-04-10', 'importo_imponibile' => $totale, 'importo_iva' => 0, 'netto_a_pagare' => $totale,
        'totale_documento' => $totale, 'stato_approvazione' => 'approvata', 'stato_pagamento' => 'aperta', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura, 'conto_id' => $voce->id, 'descrizione' => 'Lavori', 'importo_imponibile' => 100000, 'aliquota_iva' => 0,
        'importo_iva' => 0, 'is_sopravvenienza' => true, 'created_at' => now(), 'updated_at' => now()]);
    if ($ordinaria !== null) {
        DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura, 'conto_id' => $ordinaria->id, 'descrizione' => 'Pulizia', 'importo_imponibile' => 20000,
            'aliquota_iva' => 0, 'importo_iva' => 0, 'is_sopravvenienza' => false, 'created_at' => now(), 'updated_at' => now()]);
    }
    $piano = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => $nome, 'stato' => 'bozza', 'tipo' => 'straordinario',
        'numero_rate' => 1, 'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-03-10', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false,
        'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera di prova', 'data_delibera_assemblea' => '2026-02-01']);
    $piano->fatture()->attach($fattura, ['importo_collegato' => 100000]);
    b53bsRicalcola($test, $s, $piano);
    if ($stato === 'approvato') {
        $test->actingAs($test->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => true, 'data_delibera_assemblea' => '2026-02-01'])
            ->assertSessionHasNoErrors();
    }

    return $piano->fresh();
}

/** Il ricalcolo del piano dalla sua pagina: le quote si riscrivono con le tabelle di adesso. */
function b53bsRicalcola($test, array $s, PianoRate $piano): void
{
    session()->forget('message');
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]), [
        'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ]);
}

/** Che cosa il piano straordinario chiede, per persona: il motore in sola lettura e le quote salvate (nome => centesimi). */
function b53bsPiano(PianoRate $piano): array
{
    $nomi = DB::table('anagrafiche')->pluck('nome', 'id')->all();
    $motore = [];
    foreach (app(\App\Services\CalcoloQuoteService::class)->calcolaDaFattureStraordinarie($piano->fresh(), null, true) as $anagrafica => $immobili) {
        $motore[$nomi[$anagrafica] ?? $anagrafica] = array_sum($immobili);
    }
    ksort($motore);
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
        ->selectRaw('rate_quote.anagrafica_id as chi, sum(rate_quote.importo) as tot')->groupBy('rate_quote.anagrafica_id')->get()
        ->mapWithKeys(fn ($r) => [$nomi[$r->chi] ?? $r->chi => (int) $r->tot])->all();
    ksort($quote);

    return ['motore' => $motore, 'quote' => $quote];
}

/** Le tabelle della voce nel database: tabella, coefficiente e ripartizioni, nell'ordine dei legami. */
function b53bsTabelle(Conto $voce): array
{
    return DB::table('conto_tabella_millesimale')->where('conto_id', $voce->id)->orderBy('id')->get()->map(fn ($legame) => [
        'tabella' => (int) $legame->tabella_id,
        'coefficiente' => (float) $legame->coefficiente,
        'ripartizioni' => DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $legame->id)->orderBy('soggetto')
            ->pluck('percentuale', 'soggetto')->map(fn ($p) => (float) $p)->all(),
    ])->all();
}

/** La modifica di una voce dal piano dei conti, come la manda la sua scheda. */
function b53bsModifica($test, array $s, Conto $voce, array $campi = [])
{
    session()->forget('errors');
    session()->forget('message');
    $voce = $voce->fresh();

    return $test->actingAs($test->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $voce]), array_merge([
        'nome' => $voce->nome, 'codice' => '', 'tipo' => $voce->tipo, 'importo' => number_format($voce->importo / 100, 2, ',', '.'), 'descrizione' => '', 'note' => '',
        'parent_id' => $voce->parent_id, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0,
        'percentuale_usufruttuario' => 0, 'isCapitolo' => (bool) $voce->is_capitolo, 'isSottoConto' => $voce->parent_id !== null, 'richiedeGiaVersato' => false,
    ], $campi));
}

/** Le tre rotte del pannello delle tabelle: associa, aggiorna, dissocia. */
function b53bsPannello($test, array $s, Conto $voce, string $azione)
{
    $parametri = [$s['c'], $s['e'], $s['pc'], $voce];
    session()->forget('message');

    return match ($azione) {
        'associa' => $test->actingAs($test->user)->from('/piano-dei-conti')->post(route('admin.gestionale.esercizi.piani-conti.conti.associa-tabella', $parametri), [
            'tabella_millesimale_id' => $s['box']->id, 'coefficiente' => 40, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        ]),
        'aggiorna' => $test->actingAs($test->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.aggiorna-tabella', [...$parametri, $s['tabella']]), [
            'coefficiente' => 50, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        ]),
        'dissocia' => $test->actingAs($test->user)->from('/piano-dei-conti')->delete(route('admin.gestionale.esercizi.piani-conti.conti.dissocia-tabella', [...$parametri, $s['tabella']])),
    };
}

/**
 * Lo scenario: il piano straordinario nello stato dato, con la fattura sulla voce «Scale». Col coefficiente di «Proprietà» sotto il 100 la voce ha un posto per
 * un'altra tabella: la somma dei coefficienti non è la ragione del rifiuto di «Associa».
 */
function b53bsScenario($test, string $stato = 'approvato', int $coefficiente = 100): array
{
    $s = b53bvScenario($test, ['generali' => ''], false);
    DB::table('conto_tabella_millesimale')->where('conto_id', $s['scale']->id)->update(['coefficiente' => $coefficiente]);
    $s['f'] = b53bsPianoStraordinario($test, $s, $s['scale'], $stato);

    return $s;
}

// ─── La voce che un piano straordinario approvato finanzia ─────────────────────────────────────────────────────────

it('il piano straordinario approvato parte con le quote che i conti a mano dicono: € 500,00 a testa', function () {
    $s = b53bsScenario($this);

    expect(b53bsPiano($s['f']))->toBe(['motore' => ['Bice' => 50000, 'Ugo' => 50000], 'quote' => ['Bice' => 50000, 'Ugo' => 50000]])
        ->and($s['f']->stato->value)->toBe('approvato');
});

it('la tabella, le percentuali, l\'importo, il tipo o il padre di una voce che un piano straordinario approvato finanzia non si cambiano: le quote del piano restano, e la strada della bozza passa dalle rotte', function (array $campi, string $errore, Closure $dopo) {
    $s = b53bsScenario($this);
    $campi = ($campi['tabella'] ?? false) ? ['tabella_millesimale_id' => $s['box']->id] : $campi;
    $campi = ($campi['padre'] ?? false) ? ['parent_id' => $s['capitolo']->id] : $campi;
    $prima = b53bsPiano($s['f']);
    $voce = fn () => [(array) DB::table('conti')->where('id', $s['scale']->id)->first(['tipo', 'importo', 'parent_id']), b53bsTabelle($s['scale'])];
    $comEra = $voce();

    b53bsModifica($this, $s, $s['scale'], $campi);

    expect(b53snErrori())->toBe([$errore => [B53BS_RIFIUTO]])
        ->and($voce())->toBe($comEra);

    // Il ricalcolo del piano dalla sua pagina non porta niente di nuovo: la voce è com'era.
    b53bsRicalcola($this, $s, $s['f']);
    expect(b53bsPiano($s['f']))->toBe($prima);

    // La strada della frase: il piano in bozza dalla sua pagina, poi la modifica passa.
    b53bvInBozza($this, $s, $s['f']);
    b53bsModifica($this, $s, $s['scale'], $campi)->assertSessionHasNoErrors();

    expect($dopo($s))->toBeTrue();
})->with([
    'la tabella' => [['tabella' => true], 'tabella_millesimale_id', fn (array $s) => array_column(b53bsTabelle($s['scale']), 'tabella') === [(int) $s['box']->id]],
    'le percentuali' => [['percentuale_proprietario' => 60, 'percentuale_inquilino' => 40], 'percentuale_proprietario',
        fn (array $s) => b53bsTabelle($s['scale'])[0]['ripartizioni'] === ['inquilino' => 40.0, 'proprietario' => 60.0]],
    'l\'importo' => [['importo' => '1.300,00'], 'importo', fn (array $s) => (int) DB::table('conti')->where('id', $s['scale']->id)->value('importo') === 130000],
    'il tipo' => [['tipo' => 'entrata'], 'tipo', fn (array $s) => DB::table('conti')->where('id', $s['scale']->id)->value('tipo') === 'entrata'],
    'il padre' => [['padre' => true], 'parent_id', fn (array $s) => (int) DB::table('conti')->where('id', $s['scale']->id)->value('parent_id') === (int) $s['capitolo']->id],
]);

it('associare, aggiornare o rimuovere la tabella di una voce che un piano straordinario approvato finanzia si rifiuta con la stessa frase, e le quote restano; in bozza passa', function (string $azione) {
    $s = b53bsScenario($this, 'approvato', 60);
    $prima = b53bsPiano($s['f']);
    $tabelle = b53bsTabelle($s['scale']);

    b53bsPannello($this, $s, $s['scale'], $azione);

    expect(b53snFlash())->toBe(['message' => B53BS_RIFIUTO, 'type' => 'error'])
        ->and(b53bsTabelle($s['scale']))->toBe($tabelle);

    b53bsRicalcola($this, $s, $s['f']);
    expect(b53bsPiano($s['f']))->toBe($prima);

    // La strada della frase: il piano in bozza dalla sua pagina, poi la richiesta passa.
    b53bvInBozza($this, $s, $s['f']);
    b53bsPannello($this, $s, $s['scale'], $azione);

    expect(b53snFlash()['type'] ?? null)->toBe('success')
        ->and(b53bsTabelle($s['scale']))->not->toBe($tabelle);
})->with(['associa' => ['associa'], 'aggiorna' => ['aggiorna'], 'dissocia' => ['dissocia']]);

it('la voce che il piano straordinario finanzia con una fattura pregressa, cioè con una copertura di sopravvenienza, non cambia tabella', function () {
    $s = b53bvScenario($this, ['generali' => ''], false);
    $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Ditta Pregressa', 'soggetto_ritenuta' => false, 'modalita_pagamento_default' => 'bonifico',
        'created_at' => now(), 'updated_at' => now()]);
    $fattura = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'fornitore_id' => $fornitore, 'tipo_documento' => 'fattura', 'numero_documento' => 'P-1', 'is_pregresso' => true,
        'data_documento' => '2025-03-10', 'data_scadenza' => '2025-04-10', 'importo_imponibile' => 100000, 'importo_iva' => 0, 'netto_a_pagare' => 100000,
        'totale_documento' => 100000, 'stato_approvazione' => 'approvata', 'stato_pagamento' => 'aperta', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('fattura_coperture')->insert(['fattura_passiva_id' => $fattura, 'tipo_copertura' => 'sopravvenienza', 'importo' => 100000, 'stato' => 'pianificata', 'conto_id' => $s['scale']->id,
        'created_at' => now(), 'updated_at' => now()]);
    $piano = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Facciata 2026', 'stato' => 'approvato',
        'tipo' => 'straordinario', 'numero_rate' => 1, 'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-03-10', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false,
        'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera di prova', 'data_delibera_assemblea' => '2026-02-01']);
    $piano->fatture()->attach($fattura, ['importo_collegato' => 100000]);
    $tabelle = b53bsTabelle($s['scale']);

    b53bsModifica($this, $s, $s['scale'], ['tabella_millesimale_id' => $s['box']->id]);

    expect(b53snErrori())->toBe(['tabella_millesimale_id' => [B53BS_RIFIUTO]])
        ->and(b53bsTabelle($s['scale']))->toBe($tabelle);

    b53bvInBozza($this, $s, $piano);
    b53bsModifica($this, $s, $s['scale'], ['tabella_millesimale_id' => $s['box']->id])->assertSessionHasNoErrors();

    expect(b53bsTabelle($s['scale']))->not->toBe($tabelle);
});

it('un piano straordinario emesso o chiuso tiene la voce come uno approvato', function (string $stato) {
    $s = b53bsScenario($this);
    DB::table('piani_rate')->where('id', $s['f']->id)->update(['stato' => $stato]);

    expect(Conto::pianiCheBloccanoLaVoce([(int) $s['scale']->id]))->toBe([(int) $s['scale']->id => [['id' => (int) $s['f']->id, 'nome' => 'Facciata 2026', 'capitolo' => null,
        'movimenti' => []]]]);
})->with(['emesso' => ['emesso'], 'chiuso' => ['chiuso']]);

// ─── La pagina del piano dei conti, e i piani insieme ──────────────────────────────────────────────────────────────

it('la riga della voce porta il blocco del piano straordinario approvato, con la frase della scheda; in bozza la riga è libera', function () {
    $s = b53bsScenario($this);

    $riga = b53snRiga($this, $s, 'Scale');

    expect($riga['blocco'])->toBe(['piani' => [['id' => (int) $s['f']->id, 'nome' => 'Facciata 2026']], 'frase' => 'L\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano '
        .'finché la voce è nel piano «Facciata 2026», approvato'.B53BS_BOZZA])
        ->and($riga['has_rate_emesse'])->toBeTrue();

    b53bvInBozza($this, $s, $s['f']);

    expect(b53snRiga($this, $s, 'Scale')['blocco'])->toBeNull()
        ->and(b53snRiga($this, $s, 'Scale')['has_rate_emesse'])->toBeFalse();
});

it('un piano ordinario e uno straordinario approvati tengono la voce: la frase li nomina tutti e due, e dopo la bozza di uno nomina l\'altro', function () {
    $s = b53bvScenario($this, ['scale' => '500,00']);
    $s['f'] = b53bsPianoStraordinario($this, $s, $s['scale']);

    b53bsModifica($this, $s, $s['scale'], ['tabella_millesimale_id' => $s['box']->id]);

    expect(b53snErrori()['tabella_millesimale_id'][0])->toStartWith('Modifica inibita: '.B53BS_COSA.' finché la voce è nei piani «Preventivo B» e «Facciata 2026», approvati: '
        .'riporta prima i piani in bozza dalle loro pagine, poi modifica la voce. Riportandoli in bozza');

    b53bvInBozza($this, $s, $s['b']);
    b53bsModifica($this, $s, $s['scale'], ['tabella_millesimale_id' => $s['box']->id]);

    expect(b53snErrori()['tabella_millesimale_id'][0])->toStartWith('Modifica inibita: '.B53BS_COSA.' finché la voce è nel piano «Facciata 2026», approvato: ');
});

it('controprova — il piano straordinario in bozza, o che finanzia un\'altra voce, non blocca: le tabelle si cambiano come prima', function () {
    $s = b53bsScenario($this, 'bozza');

    b53bsModifica($this, $s, $s['scale'], ['tabella_millesimale_id' => $s['box']->id])->assertSessionHasNoErrors();
    expect(array_column(b53bsTabelle($s['scale']), 'tabella'))->toBe([(int) $s['box']->id]);

    // Approvato, il piano blocca la sua voce e lascia libere le altre.
    $s['f'] = b53bsPianoStraordinario($this, $s, $s['scale'], 'approvato', 'Tetto 2026');
    b53bsModifica($this, $s, $s['generali'], ['tabella_millesimale_id' => $s['box']->id, 'percentuale_proprietario' => 60, 'percentuale_inquilino' => 40])->assertSessionHasNoErrors();

    expect(b53bsTabelle($s['generali']))->toBe([['tabella' => (int) $s['box']->id, 'coefficiente' => 100.0, 'ripartizioni' => ['inquilino' => 40.0, 'proprietario' => 60.0]]]);
});

it('controprova — la fattura mista: la riga ordinaria su un\'altra voce non la blocca, perché il motore straordinario legge solo le righe di sopravvenienza', function () {
    $s = b53bvScenario($this, ['generali' => ''], false);
    $s['f'] = b53bsPianoStraordinario($this, $s, $s['scale'], 'approvato', 'Facciata 2026', $s['generali']);
    $prima = b53bsPiano($s['f']);

    // Il piano chiede la sola sopravvenienza su «Scale»: € 500,00 a testa, la riga di € 200,00 su «Spese generali» non c'è.
    expect($prima)->toBe(['motore' => ['Bice' => 50000, 'Ugo' => 50000], 'quote' => ['Bice' => 50000, 'Ugo' => 50000]])
        ->and(Conto::pianiCheBloccanoLaVoce([(int) $s['generali']->id]))->toBe([])
        ->and(b53snRiga($this, $s, 'Spese generali')['blocco'])->toBeNull();

    b53bsModifica($this, $s, $s['generali'], ['tabella_millesimale_id' => $s['box']->id])->assertSessionHasNoErrors();
    b53bsModifica($this, $s, $s['scale'], ['tabella_millesimale_id' => $s['box']->id]);

    expect(array_column(b53bsTabelle($s['generali']), 'tabella'))->toBe([(int) $s['box']->id])
        ->and(b53snErrori())->toBe(['tabella_millesimale_id' => [B53BS_RIFIUTO]]);

    // Il ricalcolo del piano dalla sua pagina non cambia niente: «Spese generali» non è nel piano.
    b53bsRicalcola($this, $s, $s['f']);
    expect(b53bsPiano($s['f']))->toBe($prima);
});
