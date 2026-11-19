<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Lo scenario delle Code 686 e 687 (la prova della domanda del forum del 18/11/2026): una gestione straordinaria nuova con il suo piano dei conti, la tabella
 * di proprietà con due appartamenti a 600 e 400 millesimi, il fornitore, e le funzioni per la voce, la fattura (registrata e pagata dalle rotte vere), il
 * carrello, ciò che il carrello non mostra e il corpo della modifica come lo manda la pagina. Lo usano `SpesaNonPrevistaARateTest`,
 * `FattureToccateDallaModificaTest` e `FatturaRicordaLaGestioneTest`. Non è un test: non prova niente da solo.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Services\CondominioService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PianoRateScenario.php';

function snpScenario($t): array
{
    $s = Beta51Scenario::nuovo($t)
        ->tabella('Proprietà', ['A' => 600, 'B' => 400])
        ->titolare('A', 'ugo')->titolare('B', 'bice')
        ->gestione('Ordinaria 2026');
    app(CondominioService::class)->ensureDefaultConti($s->c);
    $s->cassa();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);

    $t->actingAs($s->admin)->post(route('admin.gestionale.esercizi.gestioni.store', [$s->c, $s->e]), [
        'nome' => 'Spesa fuori bilancio', 'descrizione' => 'Riparazione non prevista', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31',
        'tipo' => 'straordinaria', 'attiva' => true, 'condominio_id' => $s->c->id,
    ])->assertSessionHasNoErrors();
    $g = Gestione::where('condominio_id', $s->c->id)->where('nome', 'Spesa fuori bilancio')->sole();

    $t->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-conti.store', [$s->c, $s->e]), [
        'nome' => 'Piano spesa fuori bilancio', 'descrizione' => 'Piano', 'condominio_id' => $s->c->id, 'gestione_id' => $g->id,
    ])->assertSessionHasNoErrors();
    $pc = PianoConto::where('gestione_id', $g->id)->sole();

    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Idraulica Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100,
        'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);

    return compact('s', 'g', 'pc', 'fornitoreId');
}

function snpVoce($t, array $r, string $importo): Conto
{
    $s = $r['s'];
    $t->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s->c, $s->e, $r['pc']]), [
        'nome' => 'Riparazione colonna', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => $importo, 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $s->tabelle['Proprietà']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();

    return Conto::where('piano_conto_id', $r['pc']->id)->where('nome', 'Riparazione colonna')->sole();
}

function snpLogLegale(array $r): array
{
    return ['log_legale_sopravvenienza' => [
        'nome_voce' => 'Riparazione colonna', 'origine_decisionale' => 'delibera_assembleare', 'data_assemblea' => '2026-10-15', 'tipo_ripartizione' => 'millesimale',
        'is_ordinario' => false, 'richiede_copertura' => true, 'motivazione_sforo' => 'Perdita improvvisa', 'tabella_millesimale_id' => $r['s']->tabelle['Proprietà']->id,
        'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
    ]];
}

function snpFattura($t, array $r, array $riga, array $datiExtra = [], bool $paga = true, ?int $esercizioId = null, string $numero = 'FT-ROB'): FatturaPassiva
{
    $s = $r['s'];
    $t->actingAs($s->admin)->post(route('admin.gestionale.fatture.store', [$s->c]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $esercizioId ?? $s->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => $numero,
        'data_documento' => '2026-10-20', 'data_scadenza' => '2026-11-20', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => 'approvata',
        'dati_extra' => array_merge(['fiscal' => [], 'competenza' => null, 'override_budget' => null], $datiExtra),
        'righe' => [array_merge(['descrizione' => 'Riparazione colonna', 'importo_imponibile' => 1000.00, 'aliquota_iva' => 22], $riga)],
    ])->assertSessionHasNoErrors();
    $f = FatturaPassiva::where('condominio_id', $s->c->id)->where('numero_documento', $numero)->sole();
    if (! $paga) {
        return $f->fresh();
    }

    $t->actingAs($s->admin)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s->c]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'conto_corrente_id' => $s->cassa->conto_contabile_id, 'data_pagamento' => '2026-10-25', 'metodo_pagamento' => 'bonifico',
        'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 0, 'bonifico_parlante' => false, 'allow_overdraft' => true, 'iban_confermato_manualmente' => true,
        'conferma_duplicato_verificato' => true, 'allocazioni' => [['fattura_id' => $f->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => (int) $f->fresh()->netto_a_pagare]],
    ])->assertSessionHasNoErrors();

    return $f->fresh();
}

function snpCarrello($t, array $r, ?int $gestioneId = null, ?int $esercizioId = null): array
{
    $s = $r['s'];

    return $t->actingAs($s->admin)->getJson(route('admin.gestionale.fetch-fatture-straordinarie', ['condominio' => $s->c->id, 'gestione_id' => $gestioneId ?? $r['g']->id,
        'esercizio_id' => $esercizioId ?? $s->e->id]))->assertOk()->json();
}

function snpFuori($t, array $r, ?int $gestioneId = null): array
{
    $s = $r['s'];

    return $t->actingAs($s->admin)->getJson(route('admin.gestionale.fetch-fatture-fuori-dal-carrello', ['condominio' => $s->c->id, 'gestione_id' => $gestioneId ?? $r['g']->id,
        'esercizio_id' => $s->e->id]))->assertOk()->json();
}

function snpSelettore($t, array $r): array
{
    $s = $r['s'];

    return $t->actingAs($s->admin)->getJson(route('admin.gestionale.fetch-capitoli-gestione', ['condominio' => $s->c->id, 'gestione_id' => $r['g']->id, 'esercizio_id' => $s->e->id]))
        ->assertOk()->json();
}

function snpPerPersona(array $r, PianoRate $p): array
{
    $s = $r['s'];
    $out = [];
    foreach (['ugo', 'bice'] as $chi) {
        $out[$chi] = (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $p->id)
            ->where('rate_quote.anagrafica_id', $s->persona($chi)->id)->sum('rate_quote.importo');
    }

    return $out;
}

/** Il corpo della modifica come lo manda oggi `FatturaRegisterEdit.vue`: la gestione della fattura e il segno di ogni riga come è salvato. */
function snpCorpoModifica(FatturaPassiva $f, array $cambia = [], ?callable $riga = null): array
{
    $righe = DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->get();

    return array_merge([
        'fornitore_id' => $f->fornitore_id, 'esercizio_id' => $f->esercizio_id, 'gestione_id' => $f->fresh()->gestione_id, 'tipo_documento' => 'fattura', 'is_pregresso' => false,
        'numero_documento' => $f->numero_documento, 'data_documento' => '2026-10-20', 'data_scadenza' => '2026-11-30', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => $f->stato_approvazione,
        'competenza_dal' => '', 'competenza_al' => '', 'dati_extra' => ['fiscal' => [], 'override_budget' => null, 'log_legale_sopravvenienza' => null],
        'righe' => $righe->map(fn ($x) => array_merge(['descrizione' => $x->descrizione, 'conto_id' => $x->conto_id, 'immobile_id' => $x->immobile_id, 'importo_imponibile' => $x->importo_imponibile / 100,
            'aliquota_iva' => $x->aliquota_iva, 'is_sopravvenienza' => (bool) $x->is_sopravvenienza, 'concorre_base_ritenuta' => true], $riga ? $riga($x) : []))->all(),
    ], $cambia);
}

function snpGestioniDelleScritture(array $r): array
{
    return DB::table('scritture_contabili')->where('condominio_id', $r['s']->c->id)->where('tipo_movimento', 'fattura_acquisto')->pluck('gestione_id')->unique()->values()->all();
}
