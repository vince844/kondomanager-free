<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 742 (decisione 653 (2)): «Metti a rate» su una fattura la cui voce nel preventivo vale € 0. Il caso della prova a video: la fattura di € 1.400,00 sulla
 * voce «Manutenzione tetto», a € 0 nel preventivo di Via Roma 12, registrata in sforo. Fino alla beta.13 il riquadro «Le rate» diceva «Nei piani della voce»
 * (falso) e non mostrava il pulsante; il carrello di «Spese fuori preventivo» non la offriva.
 *
 * - il riquadro dice il vero e «Metti a rate» apre «Spese fuori preventivo» con la fattura nel carrello;
 * - la catena fattura → piano → incasso della prima rata, con le scritture e il conguaglio a zero;
 * - il doppio conto nei due sensi: messa a rate con le fatture, «Dalle voci» non la chiede più; chiesta dalle voci, il carrello non la offre;
 * - le controprove: una voce con il suo importo resta «Dalle voci»; la voce a zero non compare più fra quelle «sulle voci» del carrello vuoto.
 *
 * Cosa resta scoperto: la pagina della fattura (la frase arriva dal server, il riquadro la mostra com'è: `LeRateDellaFattura.test.ts`).
 */

use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\CondominioService;
use App\Services\Gestionale\SpesaDaMettereARate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

/** Via Roma 12: la tabella di proprietà a 600 e 400, la voce «Manutenzione tetto» a € 0 e «Pulizie» a € 1.000,00, e la fattura in sforo sul tetto. */
function vfpScenario($t, int $tetto = 0): array
{
    $t->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
    $s = Beta51Scenario::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026');
    app(CondominioService::class)->ensureDefaultConti($s->c);
    // Le voci dalla rotta vera, con il loro conto a giornale e la tabella.
    foreach (['Manutenzione tetto' => $tetto, 'Pulizie' => 100000] as $nome => $importo) {
        $t->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s->c, $s->e, $s->gestioni['Ordinaria 2026']['pc']]), [
            'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => number_format($importo / 100, 2, ',', '.'),
            'descrizione' => '', 'note' => '', 'parent_id' => null, 'tabella_millesimale_id' => $s->tabelle['Proprietà']->id, 'percentuale_proprietario' => 100,
            'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0, 'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
        ])->assertSessionHasNoErrors();
        $conto = \App\Models\Gestionale\Conto::query()->where('piano_conto_id', $s->gestioni['Ordinaria 2026']['pc']->id)->where('nome', $nome)->sole();
        $s->voci[$nome] = ['conto' => $conto, 'gestione' => 'Ordinaria 2026', 'importo' => $importo];
    }
    $s->cassa();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $g = $s->gestioni['Ordinaria 2026']['g'];
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Coperture Rossi', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);

    return compact('s', 'g', 'fornitoreId');
}

/** La fattura dalla rotta vera, sulla voce data; sulla voce a zero è uno sforo con «conguaglio di fine anno». */
function vfpFattura($t, array $r, string $voce, string $numero = 'ZM0001', float $importo = 1400.00): FatturaPassiva
{
    $s = $r['s'];
    $sforo = (int) $s->voci[$voce]['importo'] < (int) round($importo * 100);
    $t->actingAs($s->admin)->post(route('admin.gestionale.fatture.store', [$s->c]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => $numero,
        'data_documento' => '2026-10-20', 'data_scadenza' => '2026-11-20', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => $sforo ? 'sforo_motivato' : 'approvata',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => $sforo ? ['motivazione' => 'Infiltrazione dal tetto, riparata subito',
            'importo_sforo' => (int) round($importo * 100), 'strategia_rientro' => 'conguaglio_fine_anno', 'fondo_patrimoniale_id' => null] : null],
        'righe' => [['descrizione' => 'Riparazione del manto', 'conto_id' => $s->voci[$voce]['conto']->id, 'importo_imponibile' => $importo, 'aliquota_iva' => 0,
            'is_sopravvenienza' => false]],
    ])->assertSessionHasNoErrors();

    return FatturaPassiva::query()->where('condominio_id', $s->c->id)->where('numero_documento', $numero)->sole();
}

function vfpCarrello($t, array $r): \Illuminate\Support\Collection
{
    return collect($t->actingAs($r['s']->admin)->getJson(route('admin.gestionale.fetch-fatture-straordinarie', ['condominio' => $r['s']->c->id,
        'gestione_id' => $r['g']->id, 'esercizio_id' => $r['s']->e->id]))->assertOk()->json());
}

function vfpSelettore($t, array $r): \Illuminate\Support\Collection
{
    return collect($t->actingAs($r['s']->admin)->getJson(route('admin.gestionale.fetch-capitoli-gestione', ['condominio' => $r['s']->c->id,
        'gestione_id' => $r['g']->id, 'esercizio_id' => $r['s']->e->id]))->assertOk()->json());
}

function vfpPianoDelleFatture($t, array $r, FatturaPassiva $f, string $importo = '1.400,00')
{
    return $t->actingAs($r['s']->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Tetto a rate', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/11/2026, punto 2',
        'fatture_config' => [['id' => $f->id, 'importo' => $importo]], 'genera_subito' => 1,
    ]);
}

/** Le righe di una scrittura: [ruolo del conto, dare|avere, centesimi]. @return list<array{0: string, 1: string, 2: int}> */
function vfpRighe(int $scritturaId): array
{
    return DB::table('righe_scritture as rs')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')->where('rs.scrittura_id', $scritturaId)
        ->orderBy('rs.id')->get(['cc.ruolo', 'rs.tipo_riga', 'rs.importo'])->map(fn ($x) => [(string) $x->ruolo, (string) $x->tipo_riga, (int) $x->importo])->all();
}

it('742, il riquadro «Le rate» dice il vero e «Metti a rate» apre «Spese fuori preventivo» con la fattura nel carrello', function () {
    $r = vfpScenario($this);
    $f = vfpFattura($this, $r, 'Manutenzione tetto');

    $rate = SpesaDaMettereARate::perLaFattura($f->fresh(), $r['s']->admin);
    expect($rate['frase'])->toBe('La voce «Manutenzione tetto» non è nel preventivo: € 1.400,00 da mettere a rate')
        ->and($rate['scheda'])->toBe('fuori_preventivo')->and($rate['fuori'])->toBe(140000)
        ->and(urldecode((string) $rate['url']))->toContain('tipo=straordinario')->toContain('fatture[0]=' . $f->id)->toContain('da_fattura=' . $f->id)
        ->toContain('gestione_id=' . $r['g']->id);
    // Il carrello la offre per intero; ciò che il carrello non mostra non la elenca fra quelle «sulle voci».
    $riga = vfpCarrello($this, $r)->firstWhere('id', $f->id);
    expect($riga)->not->toBeNull()->and((float) $riga['residuo_da_finanziare'])->toBe(1400.0);
    $fuori = $this->actingAs($r['s']->admin)->getJson(route('admin.gestionale.fetch-fatture-fuori-dal-carrello', ['condominio' => $r['s']->c->id,
        'gestione_id' => $r['g']->id, 'esercizio_id' => $r['s']->e->id]))->assertOk()->json();
    expect(collect($fuori['sulle_voci']['fatture'])->pluck('id')->all())->not->toContain($f->id);
});

it('742, la catena fattura → piano → incasso: il piano «Spese fuori preventivo» divide € 1.400,00 sulle tabelle della voce, la prima rata si emette e si incassa, il conguaglio è zero', function () {
    $r = vfpScenario($this);
    $s = $r['s'];
    $f = vfpFattura($this, $r, 'Manutenzione tetto');
    vfpPianoDelleFatture($this, $r, $f)->assertSessionHasNoErrors();
    $piano = PianoRate::query()->where('condominio_id', $s->c->id)->sole();
    $perPersona = fn (string $chi) => (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
        ->where('rate_quote.anagrafica_id', $s->persona($chi)->id)->sum('rate_quote.importo');
    expect($perPersona('ugo'))->toBe(84000)->and($perPersona('bice'))->toBe(56000)
        ->and(SpesaDaMettereARate::perLaFattura($f->fresh(), $s->admin)['frase'])->toBe('Nei piani: la fattura è già tutta in un piano rate');

    $rata1 = DB::table('rate')->where('piano_rate_id', $piano->id)->orderBy('numero_rata')->first();
    $this->actingAs($s->admin)->post(route('admin.gestionale.piani-rate.emetti', [$s->c, $piano]), ['rate_ids' => [$rata1->id], 'data_emissione' => '2026-11-30',
        'invia_notifiche' => false])->assertSessionHasNoErrors();
    $emissione = ScritturaContabile::query()->where('tipo_movimento', 'emissione_rata')->where('condominio_id', $s->c->id)->sole();
    expect(vfpRighe($emissione->id))->toEqualCanonicalizing([['crediti_condomini', 'dare', 42000], ['crediti_condomini', 'dare', 28000], ['gestione_rate', 'avere', 70000]]);
    $quota = DB::table('rate_quote')->where('rata_id', $rata1->id)->where('anagrafica_id', $s->persona('ugo')->id)->first();
    $this->actingAs($s->admin)->post(route('admin.gestionale.movimenti-rate.store', [$s->c]), [
        'pagante_id' => $s->persona('ugo')->id, 'cassa_id' => $s->cassa->id, 'data_pagamento' => '2026-12-01', 'importo_totale' => '420.00', 'descrizione' => 'Bonifico',
        'eccedenza' => '0.00', 'gestione_id' => $r['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => '420.00']],
    ])->assertSessionHasNoErrors();
    $incasso = ScritturaContabile::query()->where('tipo_movimento', 'incasso_rata')->where('condominio_id', $s->c->id)->sole();
    expect(vfpRighe($incasso->id))->toBe([['conto_bancario', 'dare', 42000], ['crediti_condomini', 'avere', 42000]]);

    // Il conguaglio della voce: la spesa di Ugo sul tetto (€ 840,00) e quanto il piano gli chiede si pareggiano; niente si chiede due volte.
    $letto = (new \App\Services\Gestionale\ConsuntivoPerPersona())->perEsercizio($s->e->fresh());
    $ugo = $letto['persone'][$r['g']->id][$s->persona('ugo')->id][$s->unita['A']->id];
    expect((int) ($ugo['voci'][$s->voci['Manutenzione tetto']['conto']->id] ?? 0))->toBe(84000)
        ->and((int) $ugo['conguaglio'])->toBe(0);
});

it('742, il doppio conto: messa a rate con le fatture, «Dalle voci» non la chiede più e il carrello non la offre di nuovo; chiesta dalle voci, il carrello non la offre', function () {
    $r = vfpScenario($this);
    $s = $r['s'];
    $f = vfpFattura($this, $r, 'Manutenzione tetto');
    vfpPianoDelleFatture($this, $r, $f)->assertSessionHasNoErrors();
    $tetto = vfpSelettore($this, $r)->firstWhere('id', $s->voci['Manutenzione tetto']['conto']->id);
    expect($tetto['disabled'])->toBeTrue()->and((float) $tetto['residuo'])->toBe(0.0)
        ->and(vfpCarrello($this, $r)->firstWhere('id', $f->id))->toBeNull();
    // La fattura è tutta nel piano: la parte da mettere a rate (la riga sul tetto) è quanto i piani chiedono, e il riquadro lo dice.
    expect($f->fresh()->load(['righe', 'coperture', 'noteCollegate'])->partePianoLorda())->toBe(140000)
        ->and($f->fresh()->load(['righe', 'coperture', 'noteCollegate', 'pianiRate'])->chiestoDaiPiani())->toBe(140000);

    // L'altro senso: un'altra fattura sul tetto, con la voce chiesta dalle voci con la sua cifra in un piano ordinario: il carrello non la offre.
    $r2 = vfpScenario($this);
    $f2 = vfpFattura($this, $r2, 'Manutenzione tetto', 'ZM0002');
    $this->actingAs($r2['s']->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$r2['s']->c, $r2['s']->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $r2['g']->id, 'nome' => 'Tetto dalle voci', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2, 'giorno_scadenza' => 30,
        'data_prima_scadenza' => '2026-11-30', 'capitoli_ids' => [$r2['s']->voci['Manutenzione tetto']['conto']->id],
        'capitoli_config' => [['id' => $r2['s']->voci['Manutenzione tetto']['conto']->id, 'importo' => '1.400,00']], 'genera_subito' => 0,
    ])->assertSessionHasNoErrors();
    expect(vfpCarrello($this, $r2)->firstWhere('id', $f2->id))->toBeNull()
        ->and(SpesaDaMettereARate::perLaFattura($f2->fresh(), $r2['s']->admin)['scheda'])->toBe('dalle_voci');
});

it('742, le controprove: la fattura su una voce con il suo importo resta «Dalle voci» e fuori dal carrello', function () {
    $r = vfpScenario($this);
    $f = vfpFattura($this, $r, 'Pulizie', 'PU-1', 300.00);

    $rate = SpesaDaMettereARate::perLaFattura($f->fresh(), $r['s']->admin);
    expect($rate['scheda'])->toBe('dalle_voci')->and($rate['frase'])->toBe('€ 1.000,00 ancora fuori dai piani della voce')
        ->and(vfpCarrello($this, $r)->firstWhere('id', $f->id))->toBeNull();
});
