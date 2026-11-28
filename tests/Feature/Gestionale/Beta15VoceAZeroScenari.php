<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Gli aiuti delle prove della voce a € 0 messa a rate e del ripianamento del debito ereditato, dalle rotte delle pagine: lo scenario del tetto («Manutenzione
 * tetto», «Pulizie», le tabelle «Proprietà» 600/400 e «Scale» 500/500), quello del capitolo e quello del debito verso Idraulica Bianchi.
 */

use App\Events\Gestionale\FatturaRegistrata;
use App\Listeners\Gestionale\SyncScadenziarioWithFattura;
use App\Models\Evento;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use App\Services\CondominioService;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/Beta51PianoRateScenario.php';

// --- Gli aiuti -----------------------------------------------------------------------------------

function b15vzDebito($t, bool $saldiPrima = true, int $debito = 200000, string $fornitore = 'Idraulica Bianchi', ?Beta51Scenario $s = null, bool $conSaldi = true): array
{
    Event::fake([FatturaRegistrata::class]);
    if ($s === null) {
        $t->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
        $s = Beta51Scenario::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->tabella('Scale', ['A' => 500, 'B' => 500])
            ->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026');
        app(CondominioService::class)->ensureDefaultConti($s->c);
        $s->cassa();
        \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    }
    $g = $s->gestioni['Ordinaria 2026']['g'];
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => $fornitore, 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $saldo = Saldo::forceCreate(['condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'gestione_id' => $g->id, 'fornitore_id' => $fornitoreId,
        'saldo_iniziale' => -$debito, 'is_applicato' => false, 'descrizione' => 'Debito verso ' . $fornitore]);
    $saldi = function () use ($s) {
        if (! DB::table('saldi')->where('condominio_id', $s->c->id)->whereNull('fornitore_id')->exists()) {
            $s->saldo('ugo', 'A', 'Ordinaria 2026', 50000, true);
            $s->saldo('bice', 'B', 'Ordinaria 2026', 30000, true);
        }
    };
    if ($saldiPrima && $conSaldi) {
        $saldi();
    }
    $t->actingAs($s->admin);
    $fattura = (new FatturaPassivaService())->registraFattura([
        'fornitore_id' => $fornitoreId, 'esercizio_id' => $s->e->id, 'gestione_id' => $g->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'IB-' . $fornitoreId,
        'data_documento' => '2025-11-20', 'data_scadenza' => '2025-12-20', 'modalita_pagamento' => 'bonifico', 'is_pregresso' => true, 'imponibile_pregresso' => $debito / 100,
        'aliquota_iva_pregressa' => 0, 'saldo_patrimoniale_id' => $saldo->id, 'righe' => [],
        'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => $debito / 100, 'fonte_id' => $saldo->id]],
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
    ], $s->c->id);
    (new SyncScadenziarioWithFattura())->handle(new FatturaRegistrata($fattura, $s->admin->id));
    if (! $saldiPrima && $conSaldi) {
        $saldi();
    }
    $compito = Evento::query()->where('meta->type', 'pianifica_ripianamento_deficit')->where('meta->context->saldo_id', $saldo->id)->sole();

    return ['s' => $s, 'g' => $g, 'fattura' => $fattura, 'compito' => $compito, 'debito' => $saldo, 'fornitoreId' => $fornitoreId];
}

function b15vzCorpo(array $r, array $piu = [], array $ripianamento = []): array
{
    return array_merge([
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Ripianamento', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/11/2026, punto 3',
        'fatture_config' => [], 'genera_subito' => 1, 'accetta_destinatari' => 1, 'nota_destinatari' => 'Letto: il ripianamento del debito ereditato',
        'ripianamento' => array_merge(['compito_id' => $r['compito']->id, 'importo' => '1.200,00', 'tabella_id' => $r['s']->tabelle['Proprietà']->id], $ripianamento),
    ], $piu);
}

function b15vzPerPersona(Beta51Scenario $s, PianoRate $piano): array
{
    $esito = [];
    foreach (['ugo', 'bice'] as $chi) {
        $esito[$chi] = (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
            ->where('rate_quote.anagrafica_id', $s->persona($chi)->id)->sum('rate_quote.importo');
    }

    return $esito;
}

function b15vzErrori(): array
{
    return session('errors')?->getBag('default')->toArray() ?? [];
}

function b15vzFlash(): mixed
{
    return session('message');
}

function b15vzTetto($t, int $tetto = 0, array $altre = []): array
{
    $t->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
    $s = Beta51Scenario::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->tabella('Scale', ['A' => 500, 'B' => 500])
        ->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026');
    app(CondominioService::class)->ensureDefaultConti($s->c);
    foreach (['Manutenzione tetto' => [$tetto, 'Proprietà'], 'Pulizie' => [100000, 'Proprietà']] + $altre as $nome => [$importo, $tabella]) {
        $t->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s->c, $s->e, $s->gestioni['Ordinaria 2026']['pc']]), [
            'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => number_format($importo / 100, 2, ',', '.'),
            'descrizione' => '', 'note' => '', 'parent_id' => null, 'tabella_millesimale_id' => $s->tabelle[$tabella]->id, 'percentuale_proprietario' => 100,
            'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0, 'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
        ])->assertSessionHasNoErrors();
        $conto = Conto::query()->where('piano_conto_id', $s->gestioni['Ordinaria 2026']['pc']->id)->where('nome', $nome)->sole();
        $s->voci[$nome] = ['conto' => $conto, 'gestione' => 'Ordinaria 2026', 'importo' => $importo, 'tabella' => $tabella];
    }
    $s->cassa();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Coperture Rossi', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);

    return ['s' => $s, 'g' => $s->gestioni['Ordinaria 2026']['g'], 'pc' => $s->gestioni['Ordinaria 2026']['pc'], 'fornitoreId' => $fornitoreId];
}

function b15vzRighe(array $r, array $righe): array
{
    $s = $r['s'];

    return array_map(fn ($riga) => ['descrizione' => $riga[2] === null ? 'Lavoro su ' . $riga[0] : 'Sostituzione del rubinetto', 'importo_imponibile' => $riga[1],
        'aliquota_iva' => 0, 'conto_id' => $s->voci[$riga[0]]['conto']->id, 'immobile_id' => $riga[2] === null ? null : $s->unita[$riga[2]]->id,
        'is_sopravvenienza' => false], $righe);
}

function b15vzFattura($t, array $r, array $righe, string $numero = 'ZM0001', int $sforo = 0, string $strategia = 'conguaglio_fine_anno'): FatturaPassiva
{
    $s = $r['s'];
    $t->actingAs($s->admin)->post(route('admin.gestionale.fatture.store', [$s->c]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => $numero,
        'data_documento' => '2026-10-20', 'data_scadenza' => '2026-11-20', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => $sforo > 0 ? 'sforo_motivato' : 'approvata',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => $sforo > 0 ? ['motivazione' => 'Infiltrazione dal tetto, riparata subito',
            'importo_sforo' => $sforo, 'strategia_rientro' => $strategia, 'fondo_patrimoniale_id' => null] : null],
        'righe' => b15vzRighe($r, $righe),
    ])->assertSessionHasNoErrors();

    return FatturaPassiva::query()->where('condominio_id', $s->c->id)->where('numero_documento', $numero)->sole();
}

function b15vzModificaFattura($t, array $r, FatturaPassiva $f, array $righe)
{
    session()->forget('errors');
    $s = $r['s'];

    return $t->actingAs($s->admin)->from('/fattura')->put(route('admin.gestionale.fatture.update', [$s->c, $f->fresh()]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => $f->numero_documento,
        'data_documento' => '2026-10-20', 'data_scadenza' => '2026-11-20', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => 'approvata',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => b15vzRighe($r, $righe),
    ]);
}

function b15vzPianoFatture($t, array $r, FatturaPassiva $f, string $importo, string $nome = 'Tetto a rate')
{
    session()->forget('errors');

    return $t->actingAs($r['s']->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/11/2026, punto 2',
        'fatture_config' => [['id' => $f->id, 'importo' => $importo]], 'genera_subito' => 1,
    ]);
}

function b15vzModificaVoce($t, array $r, Conto $voce, array $campi = [])
{
    session()->forget('errors');
    $voce = $voce->fresh();
    $tabella = DB::table('conto_tabella_millesimale')->where('conto_id', $voce->id)->value('tabella_id');

    return $t->actingAs($r['s']->admin)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$r['s']->c, $r['s']->e, $r['pc'], $voce]), array_merge([
        'nome' => $voce->nome, 'codice' => '', 'tipo' => $voce->tipo, 'importo' => number_format($voce->importo / 100, 2, ',', '.'), 'descrizione' => '', 'note' => '',
        'parent_id' => $voce->parent_id, 'tabella_millesimale_id' => $tabella, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0,
        'percentuale_usufruttuario' => 0, 'isCapitolo' => false, 'isSottoConto' => $voce->parent_id !== null, 'richiedeGiaVersato' => false,
    ], $campi));
}

function b15vzPreventivo($t, array $r, string $nome = 'Preventivo 2026', array $piu = [])
{
    session()->forget('errors');

    return $t->actingAs($r['s']->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), array_merge([
        'tipo' => 'ordinario', 'gestione_id' => $r['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'genera_subito' => 1,
    ], $piu));
}

function b15vzStato($t, array $r, PianoRate $piano, bool $approvato)
{
    session()->forget('errors');

    return $t->actingAs($r['s']->admin)->put(route('admin.gestionale.piani-rate.update-stato', [$r['s']->c, $r['s']->e, $piano]),
        $approvato ? ['approvato' => true, 'data_delibera_assemblea' => '2026-11-15'] : ['approvato' => false]);
}

function b15vzSposta($t, array $r, PianoRate $piano, int $da, int $a, string $quanto = '100')
{
    session()->forget('errors');

    return $t->actingAs($r['s']->admin)->from('/piano')->post(route('admin.gestionale.piani-rate.move-budget', [$r['s']->c, $piano]), [
        'source_id' => $da, 'destination_id' => $a, 'amount' => $quanto, 'reason' => 'La spesa del tetto',
    ]);
}

function b15vzRicalcola($t, array $r, PianoRate $piano, array $piu = [])
{
    session()->forget('errors');
    session()->forget('message');

    return $t->actingAs($r['s']->admin)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$r['s']->c, $r['s']->e, $piano]),
        array_merge(['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: ricalcolo'], $piu));
}

function b15vzCopie(PianoRate $piano): array
{
    return DB::table('piano_rate_capitoli')->join('conti', 'conti.id', '=', 'piano_rate_capitoli.conto_id')->where('piano_rate_id', $piano->id)
        ->orderBy('conti.nome')->get(['conti.nome', 'piano_rate_capitoli.importo'])->map(fn ($r) => [$r->nome, $r->importo === null ? null : (int) $r->importo])->all();
}

function b15vzCapitolo($t, bool $conFattura = true, string $tettoImporto = '0,00'): array
{
    $t->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
    $s = Beta51Scenario::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->tabella('Scale', ['A' => 500, 'B' => 500])
        ->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026');
    app(CondominioService::class)->ensureDefaultConti($s->c);
    $pc = $s->gestioni['Ordinaria 2026']['pc'];
    $base = ['codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'descrizione' => '', 'note' => '',
        'tabella_millesimale_id' => $s->tabelle['Proprietà']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'richiedeGiaVersato' => false];
    $crea = function (array $campi) use ($t, $s, $pc, $base) {
        $t->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s->c, $s->e, $pc]), array_merge($base, $campi))->assertSessionHasNoErrors();

        return Conto::query()->where('piano_conto_id', $pc->id)->where('nome', $campi['nome'])->sole();
    };
    $capitolo = $crea(['nome' => 'Manutenzioni straordinarie', 'importo' => '0,00', 'parent_id' => null, 'isCapitolo' => true, 'isSottoConto' => false]);
    $tetto = $crea(['nome' => 'Manutenzione tetto', 'importo' => $tettoImporto, 'parent_id' => $capitolo->id, 'isCapitolo' => false, 'isSottoConto' => true]);
    $grondaie = $crea(['nome' => 'Grondaie', 'importo' => '500,00', 'parent_id' => $capitolo->id, 'isCapitolo' => false, 'isSottoConto' => true,
        'tabella_millesimale_id' => $s->tabelle['Scale']->id]);
    $pulizie = $crea(['nome' => 'Pulizie', 'importo' => '1.000,00', 'parent_id' => null, 'isCapitolo' => false, 'isSottoConto' => false]);
    foreach (['Manutenzione tetto' => $tetto, 'Grondaie' => $grondaie, 'Pulizie' => $pulizie] as $nome => $conto) {
        $s->voci[$nome] = ['conto' => $conto, 'gestione' => 'Ordinaria 2026', 'importo' => (int) $conto->importo];
    }
    $s->cassa();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Coperture Rossi', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $r = ['s' => $s, 'g' => $s->gestioni['Ordinaria 2026']['g'], 'pc' => $pc, 'fornitoreId' => $fornitoreId, 'capitolo' => $capitolo];
    if ($conFattura) {
        $r['fattura'] = b15vzFattura($t, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0001', $tettoImporto === '0,00' ? 140000 : 0);
        if ($tettoImporto !== '0,00') {
            // La voce scende a € 0 dopo la fattura (approvata con il budget di prima).
            b15vzModificaVoce($t, $r, $tetto, ['importo' => '0,00', 'parent_id' => $capitolo->id, 'isSottoConto' => true])->assertSessionHasNoErrors();
        }
    }

    return $r;
}

function b15vzOggi(): \Carbon\CarbonImmutable
{
    return \Carbon\CarbonImmutable::parse('2026-12-05');
}

function b15vzTesoreria(Beta51Scenario $s): array
{
    return app(\App\Services\Treasury\DatiDelleAzioni::class)->perCondominio($s->c, null, b15vzOggi(), b15vzOggi()->addDays(30), ['righe' => [], 'esercizio_id' => null])['deficit'];
}

function b15vzRiga(Evento $compito): array
{
    $riga = (new \App\Services\Gestionale\Inbox\ElencoDellInbox(\App\Services\Gestionale\Inbox\FasceDeiCompiti::oggi()))
        ->righe(\App\Models\Evento::query()->whereKey($compito->id)->get())->first();

    return ['sotto' => $riga['sotto'] ?? null, 'description' => $riga['description'] ?? null];
}

function b15vzSaldo($t, array $r, string $chi, string $unita, int $cents): mixed
{
    session()->forget('message');
    session()->forget('errors');
    $s = $r['s'];
    $t->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita[$unita]->id, 'gestione_id' => $r['g']->id, 'saldo_iniziale' => $cents,
    ])->assertSessionHasNoErrors();

    return session('message');
}

function b15vzCiSonoErrori(): bool
{
    return b15vzErrori() !== [];
}

/** Il tetto da € 2.000,00, la fattura ZM0003 da € 1.400,00 approvata, la voce scesa a € 0 e il piano delle fatture «Tetto a rate» che ne chiede la cifra data. */
function b15vzTettoARate($t, string $importo = '1.000,00'): array
{
    $r = b15vzTetto($t, 200000);
    $s = $r['s'];
    $r['f'] = b15vzFattura($t, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0003');
    b15vzModificaVoce($t, $r, $s->voci['Manutenzione tetto']['conto'], ['importo' => '0,00'])->assertSessionHasNoErrors();
    b15vzPianoFatture($t, $r, $r['f'], $importo)->assertSessionHasNoErrors();
    $r['piano'] = PianoRate::query()->where('nome', 'Tetto a rate')->sole();

    return $r;
}

function b15vzRateizzata(FatturaPassiva $f): array
{
    return DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->orderBy('id')->pluck('is_rateizzata')->map(fn ($v) => (bool) $v)->all();
}
