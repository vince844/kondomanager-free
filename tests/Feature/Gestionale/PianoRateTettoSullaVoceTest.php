<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Una voce già chiesta per intero dai piani dell'esercizio non si chiede di nuovo da un piano nuovo: la creazione del piano mette un tetto su ogni voce, il
 * maggiore fra il preventivo e lo speso meno quello che chiedono gli altri piani attivi dello stesso esercizio. L'importo vuoto e la selezione rapida
 * prendono il residuo; una cifra oltre il residuo, o nessun residuo, si rifiutano con il residuo e i piani che chiedono già la voce.
 *
 * Il caso: «Pulizie» € 1.230,00, in «Acconto» per € 738,00 e in «Saldo» per € 492,00; la tabella «Proprietà» 600/400.
 */

use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

function pttvScenario($t, bool $dueAcconti = true): array
{
    $t->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
    $s = Beta51Scenario::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Pulizie', 123000, ['Proprietà' => 100]);
    app(CondominioService::class)->ensureDefaultConti($s->c);
    $s->cassa();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $r = ['s' => $s, 'g' => $s->gestioni['Ordinaria 2026']['g'], 'pulizie' => $s->voci['Pulizie']['conto']];
    if ($dueAcconti) {
        pttvPiano($t, $r, 'Acconto', ['capitoli_config' => [['id' => $r['pulizie']->id, 'importo' => '738,00']]])->assertSessionHas('success');
        pttvPiano($t, $r, 'Saldo', ['capitoli_config' => [['id' => $r['pulizie']->id, 'importo' => '492,00']]])->assertSessionHas('success');
    }

    return $r;
}

function pttvPiano($t, array $r, string $nome, array $voci)
{
    session()->forget(['message', 'errors']);

    return $t->actingAs($r['s']->admin)->from('/piani-rate/nuovo')->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), array_merge([
        'tipo' => 'ordinario', 'gestione_id' => $r['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'genera_subito' => 1, 'capitoli_ids' => [$r['pulizie']->id],
    ], $voci));
}

function pttvChiestoSullaVoce(array $r): int
{
    return (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')
        ->where('piani_rate.gestione_id', $r['g']->id)->sum('rate_quote.importo');
}

it('i due piani dell\'esercizio chiedono «Pulizie» per intero: € 1.230,00 per € 1.230,00', function () {
    $r = pttvScenario($this);
    expect(pttvChiestoSullaVoce($r))->toBe(123000);
});

it('un terzo piano con la voce chiesta per intero non nasce, nei tre modi della pagina, e il messaggio dice il residuo e i piani', function (array $voci) {
    $r = pttvScenario($this);
    $voci = json_decode(str_replace('"PULIZIE"', (string) $r['pulizie']->id, json_encode($voci)), true);
    pttvPiano($this, $r, 'Integrativo', $voci)->assertRedirect();
    $messaggio = (string) (session('message')['message'] ?? '');
    expect(PianoRate::query()->where('nome', 'Integrativo')->exists())->toBeFalse()
        ->and(session('message')['type'] ?? null)->toBe('error')
        ->and($messaggio)->toContain('«Pulizie»')->toContain('€ 0,00')->toContain('«Acconto»')->toContain('«Saldo»')
        // Il piano dei conti resta al 100 %: nessuno chiede la voce una seconda volta.
        ->and(pttvChiestoSullaVoce($r))->toBe(123000);
})->with([
    'l\'importo vuoto' => [['capitoli_config' => [['id' => 'PULIZIE', 'importo' => '']]]],
    'una cifra oltre il residuo' => [['capitoli_config' => [['id' => 'PULIZIE', 'importo' => '100,00']]]],
    'la selezione rapida' => [['capitoli_config' => []]],
]);

it('l\'importo vuoto prende il residuo, non la voce intera: «Acconto» € 738,00, poi «Saldo» con la voce chiede € 492,00', function () {
    $r = pttvScenario($this, false);
    pttvPiano($this, $r, 'Acconto', ['capitoli_config' => [['id' => $r['pulizie']->id, 'importo' => '738,00']]])->assertSessionHas('success');
    pttvPiano($this, $r, 'Saldo', ['capitoli_config' => [['id' => $r['pulizie']->id, 'importo' => '']]])->assertSessionHas('success');
    $saldo = PianoRate::query()->where('nome', 'Saldo')->sole();
    expect((int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $saldo->id)->value('importo'))->toBe(49200)
        ->and(pttvChiestoSullaVoce($r))->toBe(123000);
});

it('la controprova: lo sforo vero (€ 1.800,00 spesi su € 1.230,00) si chiede con un integrativo, € 570,00, e passa', function () {
    $r = pttvScenario($this);
    $s = $r['s'];
    $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Pulizie Rapide', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.store', [$s->c]), [
        'fornitore_id' => $fornitore, 'esercizio_id' => $s->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'PR01',
        'data_documento' => '2026-10-20', 'data_scadenza' => '2026-11-20', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => 'sforo_motivato',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => ['motivazione' => 'Pulizie straordinarie dopo i lavori',
            'importo_sforo' => 57000, 'strategia_rientro' => 'rata_integrativa', 'fondo_patrimoniale_id' => null]],
        'righe' => [['descrizione' => 'Pulizie', 'importo_imponibile' => 1800.00, 'aliquota_iva' => 0, 'conto_id' => $r['pulizie']->id, 'immobile_id' => null, 'is_sopravvenienza' => false]],
    ])->assertSessionHasNoErrors();
    expect(FatturaPassiva::query()->where('numero_documento', 'PR01')->exists())->toBeTrue();
    $voce = (int) $r['pulizie']->fresh()->importo;
    pttvPiano($this, $r, 'Integrativo', ['capitoli_config' => [['id' => $r['pulizie']->id, 'importo' => '']]])->assertSessionHas('success');
    $integrativo = PianoRate::query()->where('nome', 'Integrativo')->sole();
    // A mano: il maggiore fra la voce e lo speso (€ 1.800,00) meno € 1.230,00 già chiesti = € 570,00.
    expect(max($voce, 180000) - 123000)->toBe(57000)
        ->and((int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $integrativo->id)->value('importo'))->toBe(57000)
        ->and(pttvChiestoSullaVoce($r))->toBe(180000);
});

/**
 * Il capitolo con la sua cifra: «Manutenzione», con sotto «Ascensore» € 600,00 e «Caldaia» € 400,00. Il motore passa la cifra del capitolo alle sottovoci in
 * proporzione al loro importo: € 1.000,00 danno € 600,00 all'Ascensore e € 400,00 alla Caldaia; € 500,00 danno € 300,00 e € 200,00.
 */
function pttvCapitolo($t): array
{
    $t->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
    $s = Beta51Scenario::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Manutenzione', 0, [])
        ->voce('Ordinaria 2026', 'Ascensore', 60000, ['Proprietà' => 100])
        ->voce('Ordinaria 2026', 'Caldaia', 40000, ['Proprietà' => 100]);
    app(CondominioService::class)->ensureDefaultConti($s->c);
    $s->cassa();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $capitolo = $s->voci['Manutenzione']['conto'];
    DB::table('conti')->whereIn('id', [$s->voci['Ascensore']['conto']->id, $s->voci['Caldaia']['conto']->id])->update(['parent_id' => $capitolo->id]);

    return ['s' => $s, 'g' => $s->gestioni['Ordinaria 2026']['g'], 'pulizie' => $s->voci['Ascensore']['conto'], 'capitolo' => $capitolo,
        'ascensore' => $s->voci['Ascensore']['conto'], 'caldaia' => $s->voci['Caldaia']['conto']];
}

it('il capitolo con la sua cifra guarda le sottovoci: «Ascensore» già chiesto per intero, il capitolo da € 1.000,00 non nasce', function (string $cifra, string $passa) {
    $r = pttvCapitolo($this);
    pttvPiano($this, $r, 'Solo ascensore', ['capitoli_config' => [['id' => $r['ascensore']->id, 'importo' => '']]])->assertSessionHas('success');
    pttvPiano($this, $r, 'Capitolo intero', ['capitoli_config' => [['id' => $r['capitolo']->id, 'importo' => $cifra]]])->assertRedirect();
    $messaggio = (string) (session('message')['message'] ?? '');
    expect(PianoRate::query()->where('nome', 'Capitolo intero')->exists())->toBeFalse()
        ->and(session('message')['type'] ?? null)->toBe('error')
        ->and($messaggio)->toContain('La voce «Ascensore» vale € 600,00 ed è già chiesta dal piano «Solo ascensore» (€ 600,00): restano € 0,00 da chiedere, e il capitolo «Manutenzione» di questo piano gliene passa ' . $passa . '.')
        // Le quote: solo quelle del primo piano, € 600,00.
        ->and(pttvChiestoSullaVoce($r))->toBe(60000);
})->with([
    'la cifra intera' => ['1.000,00', '€ 600,00'],
    'una cifra più bassa' => ['400,00', '€ 240,00'],
    'senza cifra' => ['', '€ 600,00'],
]);

it('controprova — il capitolo che passa all\'Ascensore solo il suo residuo nasce: € 300,00 nel primo piano, il capitolo da € 500,00 gliene passa € 300,00', function () {
    $r = pttvCapitolo($this);
    pttvPiano($this, $r, 'Metà ascensore', ['capitoli_config' => [['id' => $r['ascensore']->id, 'importo' => '300,00']]])->assertSessionHas('success');
    pttvPiano($this, $r, 'Capitolo', ['capitoli_config' => [['id' => $r['capitolo']->id, 'importo' => '500,00']]])->assertSessionHas('success');
    // A mano: 300 + 500 = € 800,00 chiesti, l'Ascensore € 600,00 per intero (300 + 300), la Caldaia € 200,00 su € 400,00.
    expect(pttvChiestoSullaVoce($r))->toBe(80000);
});
