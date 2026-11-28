<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14, tappa 4, il giro di correzioni: i difetti trovati rivedendo la voce a € 0 (Coda 742) e il ripianamento del debito ereditato (Coda 743).
 * La regola del giro: dove il programma non sa fare il calcolo giusto, si ferma e lo dice, e decide l'amministratore. Ogni prova è un caso della revisione,
 * dalle rotte, con i numeri rifatti a mano.
 *
 * I casi: il debito ereditato verso Idraulica Bianchi (€ 2.000,00), i pregressi di Ugo (€ 500,00) e Bice (€ 300,00), la pregressa con la «rata 0»; la
 * fattura di € 1.400,00 sulla voce «Manutenzione tetto», a € 0 nel preventivo, con «Pulizie» a € 1.000,00. Le tabelle: «Proprietà» 600/400, «Scale»
 * 500/500.
 *
 * 1. La fattura in un piano delle fatture non cambia composizione (una riga di un'unità aggiunta o tolta): prima il «Ricalcola» chiedeva a Ugo € 1.400,00.
 * 2. La voce a € 0 resta ferma anche con il piano delle fatture in bozza, e la frase dice di togliere la fattura dal piano: prima € 3.800,00 per € 2.400,00.
 * 3. Il massimo del ripianamento è lo scoperto di oggi, con i pregressi scritti dopo la pregressa: prima € 2.800,00 per un debito di € 2.000,00.
 * 4. Il ripianamento si prepara nell'esercizio della pregressa: altrove si ferma e lo dice (prima: «da pagare» anche dopo il pagamento).
 * 5. La voce a € 0 sotto un capitolo: «Dalle voci» con il capitolo e una cifra, nei due ordini.
 * 6. «Prepara il piano di ripianamento» apre l'esercizio della pregressa; se non è aperto, la pagina si ferma e lo dice.
 * 7. Con due debiti ereditati il massimo di ogni compito è vero, e il disavanzo che resta lo ricorda un compito.
 * 8. Il già versato sulla voce a € 0 messa a rate: il preventivo si ferma con la strada vera (scegliere le voci).
 * 10. La cifra mandata come elenco: 422, non 500.
 * 12. Il fondo che copre la voce a € 0 con la cassa eliminata con le versioni di prima: il riquadro lo dice.
 *
 * Dopo la seconda revisione (la regola è la stessa):
 *
 * 13. «Sposta spesa» non dà budget alla voce a € 0 messa a rate, né al capitolo sopra di lei, neppure con uno storno: prima la voce usciva dal piano delle
 *     fatture, e tornavano € 3.800,00 chiesti per € 2.400,00 e € 1.400,00 del tetto a Ugo.
 * 14. La cifra del compito (l'Action inbox, la tesoreria, l'ordine del giorno) è quella di oggi, come la pagina: prima € 2.000,00 invece di € 1.200,00.
 * 15. I pregressi scritti dopo il ripianamento: la scrittura del pregresso e il compito lo dicono con i numeri; il piano non cambia.
 * 16. Con due debiti, la seconda parte sul debito il cui compito è chiuso dal programma: la frase dice il compito che ricorda lo scoperto.
 * 17. La fattura con una riga di un'unità aggiunta: la frase non promette più «mettila di nuovo a rate», dice il conguaglio o «Dalle voci».
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
use App\Services\Gestionale\RipianamentoDelDeficit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

// --- Coda 743 ------------------------------------------------------------------------------------------------------------------------------------------

/**
 * Il condominio con il debito ereditato; `$saldiPrima` falso: i pregressi dei condòmini scritti dopo la pregressa. Con `$s` un secondo debito. Con `$conSaldi`
 * falso nessun pregresso: la prova li scrive dopo, dalla rotta.
 */
function b14t4cDebito($t, bool $saldiPrima = true, int $debito = 200000, string $fornitore = 'Idraulica Bianchi', ?Beta51Scenario $s = null, bool $conSaldi = true): array
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

function b14t4cCorpo(array $r, array $piu = [], array $ripianamento = []): array
{
    return array_merge([
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Ripianamento', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/11/2026, punto 3',
        'fatture_config' => [], 'genera_subito' => 1, 'accetta_destinatari' => 1, 'nota_destinatari' => 'Letto: il ripianamento del debito ereditato',
        'ripianamento' => array_merge(['compito_id' => $r['compito']->id, 'importo' => '1.200,00', 'tabella_id' => $r['s']->tabelle['Proprietà']->id], $ripianamento),
    ], $piu);
}

/** Quanto il piano chiede a ogni persona (nome => centesimi). */
function b14t4cPerPersona(Beta51Scenario $s, PianoRate $piano): array
{
    $esito = [];
    foreach (['ugo', 'bice'] as $chi) {
        $esito[$chi] = (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
            ->where('rate_quote.anagrafica_id', $s->persona($chi)->id)->sum('rate_quote.importo');
    }

    return $esito;
}

/** Il 2027 aperto; con `$gestioneNuova` la sua gestione ordinaria, altrimenti la stessa gestione del 2026. */
function b14t4c2027($t, array $r, bool $gestioneNuova): array
{
    $s = $r['s'];
    $e2 = \App\Models\Esercizio::factory()->create(['condominio_id' => $s->c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $g2 = $r['g'];
    if ($gestioneNuova) {
        $g2 = \App\Models\Gestione::factory()->create(['condominio_id' => $s->c->id, 'nome' => 'Ordinaria 2027', 'tipo' => 'ordinaria', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);
        \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s->c->id, 'gestione_id' => $g2->id, 'nome' => 'Piano dei conti Ordinaria 2027']);
    }
    DB::table('esercizio_gestione')->insert(['esercizio_id' => $e2->id, 'gestione_id' => $g2->id, 'attiva' => true, 'created_at' => now(), 'updated_at' => now()]);

    return [$e2, $g2];
}

it('3 (743) i pregressi scritti dopo la pregressa: il massimo è lo scoperto di oggi, € 1.200,00, e € 2.000,00 si rifiutano', function () {
    $r = b14t4cDebito($this, false);
    $s = $r['s'];
    // Il compito è nato prima dei pregressi: dice ancora € 2.000,00. A mano: debito € 2.000,00 − pregressi € 800,00 = € 1.200,00 scoperti oggi.
    expect((int) $r['compito']->fresh()->meta['importo_buco'])->toBe(200000);
    $proposta = RipianamentoDelDeficit::proposta((int) $s->c->id, (int) $r['compito']->id);
    expect([$proposta['scoperto'], $proposta['massimo'], $proposta['proposto']])->toBe([120000, 120000, 120000]);

    $risposta = $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), b14t4cCorpo($r, [], ['importo' => '2.000,00']));
    $risposta->assertUnprocessable()->assertJsonValidationErrors(['ripianamento.importo']);
    expect($risposta->json('errors')['ripianamento.importo'][0])->toContain('€ 1.200,00')->toContain('€ 800,00');
    expect(PianoRate::query()->count())->toBe(0);

    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), b14t4cCorpo($r))->assertSessionHasNoErrors();
    expect(array_sum(b14t4cPerPersona($s, PianoRate::query()->sole())))->toBe(120000)
        ->and((bool) $r['compito']->fresh()->is_completed)->toBeTrue();
});

it('4 (743) il ripianamento in un esercizio diverso da quello della pregressa si ferma e dice dove si prepara', function () {
    $r = b14t4cDebito($this);
    $s = $r['s'];
    // La gestione del 2026 usata anche nel 2027: il piano del ripianamento nel 2027 darebbe un rendiconto «da pagare» anche dopo il pagamento.
    [$e2] = b14t4c2027($this, $r, false);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2027-01-20 10:00:00'));

    $risposta = $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $e2]), b14t4cCorpo($r, ['data_prima_scadenza' => '2027-02-28']));
    $risposta->assertUnprocessable();
    $errori = collect($risposta->json('errors'))->flatten()->implode(' ');
    expect($errori)->toContain('«Esercizio 2026»');
    expect(PianoRate::query()->count())->toBe(0);

    // La pagina del nuovo piano nel 2027 lo dice: il riquadro non promette un piano che non si salva.
    $this->withoutVite();
    $this->actingAs($s->admin)->get(route('admin.gestionale.esercizi.piani-rate.create', [$s->c, $e2]) . '?tipo=straordinario&ripianamento=' . $r['compito']->id)
        ->assertOk()->assertInertia(fn ($p) => $p->where('ripianamento.altrove', fn ($frase) => is_string($frase) && str_contains($frase, '«Esercizio 2026»')));
});

it('6 (743) «Prepara il piano di ripianamento» apre l\'esercizio della pregressa; se è chiuso la pagina si ferma e lo dice', function () {
    $r = b14t4cDebito($this);
    $s = $r['s'];
    [$e2, $g2] = b14t4c2027($this, $r, true);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2027-02-10 10:00:00'));

    $url = \App\Services\Gestionale\Inbox\TipiDeiCompiti::azione('pianifica_ripianamento_deficit', $r['compito']->fresh())['url'];
    expect($url)->toContain('/esercizi/' . $s->e->id . '/')->toContain('gestione_id=' . $r['g']->id)->toContain('ripianamento=' . $r['compito']->id);
    // Nell'esercizio della pregressa il piano si salva.
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), b14t4cCorpo($r))->assertSessionHasNoErrors();
    expect(PianoRate::query()->count())->toBe(1);
});

it('6b (743) l\'esercizio della pregressa chiuso: il compito apre la pagina di oggi, che dice dove si prepara il ripianamento e perché non si può', function () {
    $r = b14t4cDebito($this);
    $s = $r['s'];
    [$e2] = b14t4c2027($this, $r, true);
    $s->e->update(['stato' => 'chiuso']);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2027-02-10 10:00:00'));

    $url = \App\Services\Gestionale\Inbox\TipiDeiCompiti::azione('pianifica_ripianamento_deficit', $r['compito']->fresh())['url'];
    expect($url)->toContain('/esercizi/' . $e2->id . '/')->toContain('ripianamento=' . $r['compito']->id);
    $this->withoutVite();
    $this->actingAs($s->admin)->get(route('admin.gestionale.esercizi.piani-rate.create', [$s->c, $e2]) . '?tipo=straordinario&ripianamento=' . $r['compito']->id)
        ->assertOk()->assertInertia(fn ($p) => $p->where('ripianamento.altrove', fn ($frase) => is_string($frase) && str_contains($frase, '«Esercizio 2026»')
            && str_contains($frase, 'chiuso')));
});

it('7 (743) due debiti ereditati: il massimo di ogni compito è vero, la cifra deliberata passa, e il disavanzo che resta lo ricorda un compito', function () {
    $r1 = b14t4cDebito($this, true, 200000, 'Idraulica Bianchi');
    $r2 = b14t4cDebito($this, true, 100000, 'Edile Verdi', $r1['s']);
    $s = $r1['s'];
    // A mano: debiti € 3.000,00, pregressi € 800,00: il disavanzo è € 2.200,00. Edile al massimo la sua rata 0, € 1.000,00.
    $p2 = RipianamentoDelDeficit::proposta((int) $s->c->id, (int) $r2['compito']->id);
    expect([$p2['scoperto'], $p2['massimo']])->toBe([220000, 100000]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]),
        b14t4cCorpo($r2, ['nome' => 'Edile'], ['importo' => '1.000,00']))->assertSessionHasNoErrors();
    // Dopo: restano € 1.200,00, tutti sul debito verso Idraulica Bianchi.
    $p1 = RipianamentoDelDeficit::proposta((int) $s->c->id, (int) $r1['compito']->id);
    expect([$p1['scoperto'], $p1['massimo']])->toBe([120000, 120000]);
    $troppo = $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]),
        b14t4cCorpo($r1, ['nome' => 'Idraulica'], ['importo' => '2.000,00']));
    $troppo->assertUnprocessable();
    $frase = $troppo->json('errors')['ripianamento.importo'][0];
    expect($frase)->toContain('€ 1.200,00')->toContain('€ 3.000,00')->toContain('€ 800,00');
    expect((bool) $r2['compito']->fresh()->is_completed)->toBeTrue()
        ->and((bool) $r1['compito']->fresh()->is_completed)->toBeFalse()
        ->and((int) $r1['compito']->fresh()->meta['importo_buco'])->toBe(120000);
});

it('7b (743) due debiti ripianati in parte (€ 1.200,00 e € 200,00): € 800,00 di disavanzo restano in un compito aperto, contati una volta', function () {
    $r1 = b14t4cDebito($this, true, 200000, 'Idraulica Bianchi');
    $r2 = b14t4cDebito($this, true, 100000, 'Edile Verdi', $r1['s']);
    $s = $r1['s'];
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]),
        b14t4cCorpo($r1, ['nome' => 'Idraulica'], ['importo' => '1.200,00']))->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]),
        b14t4cCorpo($r2, ['nome' => 'Edile'], ['importo' => '200,00']))->assertSessionHasNoErrors();
    // A mano: € 2.200,00 − € 1.400,00 = € 800,00 ancora da ripianare.
    $aperti = Evento::query()->where('meta->type', 'pianifica_ripianamento_deficit')->where('is_completed', false)->get();
    expect($aperti)->not->toBeEmpty()
        ->and($aperti->sum(fn ($c) => (int) $c->meta['importo_buco']))->toBe(80000);
});

it('10 (743) la cifra del ripianamento mandata come elenco: 422 con la frase, non 500', function () {
    $r = b14t4cDebito($this);
    $s = $r['s'];
    $risposta = $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), b14t4cCorpo($r, [], ['importo' => ['1200']]));
    expect($risposta->status())->toBe(422);
    $risposta->assertJsonValidationErrors(['ripianamento.importo']);
    expect(PianoRate::query()->count())->toBe(0);
});

// --- Coda 742 ------------------------------------------------------------------------------------------------------------------------------------------

function b14t4cTetto($t, int $tetto = 0): array
{
    $t->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
    $s = Beta51Scenario::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026');
    app(CondominioService::class)->ensureDefaultConti($s->c);
    foreach (['Manutenzione tetto' => $tetto, 'Pulizie' => 100000] as $nome => $importo) {
        $t->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s->c, $s->e, $s->gestioni['Ordinaria 2026']['pc']]), [
            'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => number_format($importo / 100, 2, ',', '.'),
            'descrizione' => '', 'note' => '', 'parent_id' => null, 'tabella_millesimale_id' => $s->tabelle['Proprietà']->id, 'percentuale_proprietario' => 100,
            'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0, 'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
        ])->assertSessionHasNoErrors();
        $conto = Conto::query()->where('piano_conto_id', $s->gestioni['Ordinaria 2026']['pc']->id)->where('nome', $nome)->sole();
        $s->voci[$nome] = ['conto' => $conto, 'gestione' => 'Ordinaria 2026', 'importo' => $importo];
    }
    $s->cassa();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Coperture Rossi', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);

    return ['s' => $s, 'g' => $s->gestioni['Ordinaria 2026']['g'], 'pc' => $s->gestioni['Ordinaria 2026']['pc'], 'fornitoreId' => $fornitoreId];
}

/** Le righe: [voce, imponibile in euro, unità o null]; la riga di un'unità sulla voce «Pulizie». */
function b14t4cRighe(array $r, array $righe): array
{
    $s = $r['s'];

    return array_map(fn ($riga) => ['descrizione' => $riga[2] === null ? 'Riparazione del manto' : 'Sostituzione del rubinetto', 'importo_imponibile' => $riga[1],
        'aliquota_iva' => 0, 'conto_id' => $s->voci[$riga[0]]['conto']->id, 'immobile_id' => $riga[2] === null ? null : $s->unita[$riga[2]]->id,
        'is_sopravvenienza' => false], $righe);
}

function b14t4cFattura($t, array $r, array $righe, string $numero = 'ZM0001', int $sforo = 0): FatturaPassiva
{
    $s = $r['s'];
    $t->actingAs($s->admin)->post(route('admin.gestionale.fatture.store', [$s->c]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => $numero,
        'data_documento' => '2026-10-20', 'data_scadenza' => '2026-11-20', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => $sforo > 0 ? 'sforo_motivato' : 'approvata',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => $sforo > 0 ? ['motivazione' => 'Infiltrazione dal tetto, riparata subito',
            'importo_sforo' => $sforo, 'strategia_rientro' => 'conguaglio_fine_anno', 'fondo_patrimoniale_id' => null] : null],
        'righe' => b14t4cRighe($r, $righe),
    ])->assertSessionHasNoErrors();

    return FatturaPassiva::query()->where('condominio_id', $s->c->id)->where('numero_documento', $numero)->sole();
}

function b14t4cModificaFattura($t, array $r, FatturaPassiva $f, array $righe)
{
    session()->forget('errors');
    $s = $r['s'];

    return $t->actingAs($s->admin)->from('/fattura')->put(route('admin.gestionale.fatture.update', [$s->c, $f->fresh()]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => $f->numero_documento,
        'data_documento' => '2026-10-20', 'data_scadenza' => '2026-11-20', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => 'approvata',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => b14t4cRighe($r, $righe),
    ]);
}

function b14t4cPianoFatture($t, array $r, FatturaPassiva $f, string $importo, string $nome = 'Tetto a rate')
{
    return $t->actingAs($r['s']->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/11/2026, punto 2',
        'fatture_config' => [['id' => $f->id, 'importo' => $importo]], 'genera_subito' => 1,
    ]);
}

function b14t4cModificaVoce($t, array $r, Conto $voce, array $campi = [])
{
    session()->forget('errors');
    $voce = $voce->fresh();

    return $t->actingAs($r['s']->admin)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$r['s']->c, $r['s']->e, $r['pc'], $voce]), array_merge([
        'nome' => $voce->nome, 'codice' => '', 'tipo' => $voce->tipo, 'importo' => number_format($voce->importo / 100, 2, ',', '.'), 'descrizione' => '', 'note' => '',
        'parent_id' => $voce->parent_id, 'tabella_millesimale_id' => $r['s']->tabelle['Proprietà']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0,
        'percentuale_usufruttuario' => 0, 'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ], $campi));
}

function b14t4cPreventivo($t, array $r, string $nome = 'Preventivo 2026', array $piu = [])
{
    return $t->actingAs($r['s']->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), array_merge([
        'tipo' => 'ordinario', 'gestione_id' => $r['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'genera_subito' => 1,
    ], $piu));
}

function b14t4cInBozza($t, array $r, PianoRate $piano): void
{
    $t->actingAs($r['s']->admin)->put(route('admin.gestionale.piani-rate.update-stato', [$r['s']->c, $r['s']->e, $piano]), ['approvato' => false])->assertSessionHasNoErrors();
}

it('1 (742) la fattura nel piano delle fatture in bozza non diventa mista: la riga di un\'unità aggiunta si rifiuta, e il «Ricalcola» chiede ancora 840/560', function () {
    // «Manutenzione tetto» con € 2.000,00 nel preventivo: la fattura da € 1.400,00 è approvata, senza sforo; poi la voce scende a € 0 (nessun piano la tiene).
    $r = b14t4cTetto($this, 200000);
    $s = $r['s'];
    $f = b14t4cFattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0003');
    b14t4cModificaVoce($this, $r, $s->voci['Manutenzione tetto']['conto'], ['importo' => '0,00'])->assertSessionHasNoErrors();
    b14t4cPianoFatture($this, $r, $f, '1.400,00')->assertSessionHasNoErrors();
    $piano = PianoRate::query()->where('nome', 'Tetto a rate')->sole();
    expect(b14t4cPerPersona($s, $piano))->toBe(['ugo' => 84000, 'bice' => 56000]);

    b14t4cInBozza($this, $r, $piano);
    b14t4cModificaFattura($this, $r, $f, [['Manutenzione tetto', 1400.00, null], ['Pulizie', 100.00, 'A']])->assertSessionHasErrors('modifica_vietata');
    expect(session('errors')->first('modifica_vietata'))->toContain('«Tetto a rate»')->toContain('ogli prima la fattura dal piano')
        ->and(DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->count())->toBe(1);
    // Una correzione che lascia la composizione com'era passa: l'importo della riga, e di nuovo com'era.
    b14t4cModificaFattura($this, $r, $f, [['Manutenzione tetto', 1300.00, null]])->assertSessionHasNoErrors();
    b14t4cModificaFattura($this, $r, $f, [['Manutenzione tetto', 1400.00, null]])->assertSessionHasNoErrors();

    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $piano]),
        ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: ricalcolo'])->assertSessionHasNoErrors();
    // A mano: la riga del tetto, € 1.400,00 a 600/400 = € 840,00 e € 560,00; mai tutto a Ugo.
    expect(b14t4cPerPersona($s, $piano))->toBe(['ugo' => 84000, 'bice' => 56000]);
});

it('1b (742) la fattura mista nel piano in bozza: togliere la riga dell\'unità si rifiuta (la riga sulla voce a € 0 entrerebbe nel piano)', function () {
    $r = b14t4cTetto($this, 200000);
    $s = $r['s'];
    $f = b14t4cFattura($this, $r, [['Manutenzione tetto', 70.00, null], ['Pulizie', 100.00, 'A']], 'ZM0004');
    b14t4cModificaVoce($this, $r, $s->voci['Manutenzione tetto']['conto'], ['importo' => '0,00'])->assertSessionHasNoErrors();
    b14t4cPianoFatture($this, $r, $f, '100,00', 'Rubinetto di Ugo')->assertSessionHasNoErrors();
    $piano = PianoRate::query()->where('nome', 'Rubinetto di Ugo')->sole();
    expect(b14t4cPerPersona($s, $piano))->toBe(['ugo' => 10000, 'bice' => 0]);

    b14t4cInBozza($this, $r, $piano);
    b14t4cModificaFattura($this, $r, $f, [['Manutenzione tetto', 70.00, null]])->assertSessionHasErrors('modifica_vietata');
    expect(session('errors')->first('modifica_vietata'))->toContain('«Rubinetto di Ugo»')->toContain('ogli prima la fattura dal piano')
        ->and(DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->count())->toBe(2);
});

it('2 (742) il piano delle fatture in bozza tiene ferma la voce a € 0, e la frase dice di togliere la fattura dal piano: mai € 3.800,00 per € 2.400,00', function () {
    $r = b14t4cTetto($this);
    $s = $r['s'];
    $f = b14t4cFattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0001', 140000);
    b14t4cPianoFatture($this, $r, $f, '1.400,00')->assertSessionHasNoErrors();
    $tetto = PianoRate::query()->where('nome', 'Tetto a rate')->sole();
    expect($tetto->stato->value)->toBe('approvato');

    // Con il piano approvato: la frase non manda più alla bozza per poi modificare la voce.
    b14t4cModificaVoce($this, $r, $s->voci['Manutenzione tetto']['conto'], ['importo' => '1.400,00'])->assertSessionHasErrors('importo');
    $approvato = session('errors')->first('importo');
    expect($approvato)->toContain('ogli prima la fattura')->not->toContain('riporta prima il piano in bozza dalla sua pagina, poi modifica la voce');

    // In bozza la voce resta ferma.
    b14t4cInBozza($this, $r, $tetto);
    b14t4cModificaVoce($this, $r, $s->voci['Manutenzione tetto']['conto'], ['importo' => '1.400,00'])->assertSessionHasErrors('importo');
    expect(session('errors')->first('importo'))->toContain('«Tetto a rate»')->toContain('in bozza')->toContain('ogli prima la fattura');
    expect((int) $s->voci['Manutenzione tetto']['conto']->fresh()->importo)->toBe(0);

    b14t4cPreventivo($this, $r)->assertSessionHasNoErrors();
    $preventivo = PianoRate::query()->where('nome', 'Preventivo 2026')->sole();
    $this->actingAs($s->admin)->put(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $tetto]), ['approvato' => true, 'data_delibera_assemblea' => '2026-11-15']);
    // A mano: € 1.000,00 di pulizie e € 1.400,00 di tetto = € 2.400,00 chiesti in tutto.
    expect(array_sum(b14t4cPerPersona($s, $preventivo)) + array_sum(b14t4cPerPersona($s, $tetto->fresh())))->toBe(240000);
});

/** La voce a € 0 «Manutenzione tetto» sotto il capitolo «Manutenzioni straordinarie», e la fattura da € 1.400,00 in sforo. */
function b14t4cCapitolo($t): array
{
    $t->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
    $s = Beta51Scenario::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026');
    app(CondominioService::class)->ensureDefaultConti($s->c);
    $pc = $s->gestioni['Ordinaria 2026']['pc'];
    $base = ['codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'descrizione' => '', 'note' => '',
        'tabella_millesimale_id' => $s->tabelle['Proprietà']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'richiedeGiaVersato' => false];
    $t->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s->c, $s->e, $pc]), $base + ['nome' => 'Manutenzioni straordinarie',
        'importo' => '0,00', 'parent_id' => null, 'isCapitolo' => true, 'isSottoConto' => false])->assertSessionHasNoErrors();
    $capitolo = Conto::query()->where('piano_conto_id', $pc->id)->where('nome', 'Manutenzioni straordinarie')->sole();
    $t->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s->c, $s->e, $pc]), $base + ['nome' => 'Manutenzione tetto',
        'importo' => '0,00', 'parent_id' => $capitolo->id, 'isCapitolo' => false, 'isSottoConto' => true])->assertSessionHasNoErrors();
    $tetto = Conto::query()->where('piano_conto_id', $pc->id)->where('nome', 'Manutenzione tetto')->sole();
    $s->voci['Manutenzione tetto'] = ['conto' => $tetto, 'gestione' => 'Ordinaria 2026', 'importo' => 0];
    $s->cassa();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Coperture Rossi', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $r = ['s' => $s, 'g' => $s->gestioni['Ordinaria 2026']['g'], 'pc' => $pc, 'fornitoreId' => $fornitoreId, 'capitolo' => $capitolo];
    $r['fattura'] = b14t4cFattura($t, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0001', 140000);

    return $r;
}

function b14t4cDalleVociSulCapitolo($t, array $r)
{
    return $t->actingAs($r['s']->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Tetto dalle voci', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'capitoli_ids' => [$r['capitolo']->id],
        'capitoli_config' => [['id' => $r['capitolo']->id, 'importo' => '1.400,00']], 'genera_subito' => 1,
    ]);
}

it('5 (742) la voce a € 0 sotto un capitolo già nel piano delle fatture: «Dalle voci» con il capitolo e una cifra si rifiuta', function () {
    $r = b14t4cCapitolo($this);
    $s = $r['s'];
    b14t4cPianoFatture($this, $r, $r['fattura'], '1.400,00')->assertSessionHasNoErrors();
    expect(b14t4cPerPersona($s, PianoRate::query()->where('nome', 'Tetto a rate')->sole()))->toBe(['ugo' => 84000, 'bice' => 56000]);

    $risposta = b14t4cDalleVociSulCapitolo($this, $r);
    $risposta->assertUnprocessable()->assertJsonValidationErrors(['capitoli_config.0.importo']);
    expect($risposta->json('errors')['capitoli_config.0.importo'][0])->toContain('«Manutenzioni straordinarie»')->toContain('«Manutenzione tetto»')->toContain('«Tetto a rate»');
    expect(PianoRate::query()->where('nome', 'Tetto dalle voci')->exists())->toBeFalse();
});

it('5b (742) l\'altro ordine: il capitolo chiesto «Dalle voci» con una cifra, il carrello non offre la fattura della voce a € 0 sotto di lui', function () {
    $r = b14t4cCapitolo($this);
    $s = $r['s'];
    b14t4cDalleVociSulCapitolo($this, $r)->assertSessionHasNoErrors();
    $carrello = collect($this->actingAs($s->admin)->getJson(route('admin.gestionale.fetch-fatture-straordinarie', ['condominio' => $s->c->id,
        'gestione_id' => $r['g']->id, 'esercizio_id' => $s->e->id]))->assertOk()->json());
    expect($carrello->firstWhere('id', $r['fattura']->id))->toBeNull();
    // E la richiesta fatta a mano si rifiuta.
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Tetto a rate', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/11/2026, punto 2',
        'fatture_config' => [['id' => $r['fattura']->id, 'importo' => '1.400,00']], 'genera_subito' => 1,
    ])->assertUnprocessable();
});

it('8 (742 e tappa 1) il già versato sulla voce a € 0 messa a rate: il preventivo si ferma con la strada vera, e scegliendo le voci si genera', function () {
    $r = b14t4cTetto($this);
    $s = $r['s'];
    // Ugo ha già versato € 200,00 per il tetto all'amministratore di prima (per questo esercizio).
    $this->actingAs($s->admin)->from('/gia-versato')->put(route('admin.gestionale.contributi.update', [$s->c, $s->voci['Manutenzione tetto']['conto']]), [
        'natura' => 'fondo_vincolato', 'vale_per' => $s->e->id,
        'righe' => [['immobile_id' => $s->unita['A']->id, 'anagrafica_id' => $s->persona('ugo')->id, 'gia_versato' => 20000],
            ['immobile_id' => $s->unita['B']->id, 'anagrafica_id' => $s->persona('bice')->id, 'gia_versato' => 0]],
        'descrizione' => 'Acconto tetto', 'liquidita_stato' => \App\Models\Gestionale\ContributoVersato::LIQUIDITA_GIA_IN_APERTURA, 'cassa_id' => null,
    ])->assertSessionHasNoErrors();
    $f = b14t4cFattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0001', 140000);
    b14t4cPianoFatture($this, $r, $f, '1.400,00')->assertSessionHasNoErrors();
    expect(b14t4cPerPersona($s, PianoRate::query()->where('nome', 'Tetto a rate')->sole()))->toBe(['ugo' => 64000, 'bice' => 56000]);

    session()->forget('flash');
    b14t4cPreventivo($this, $r);
    expect(PianoRate::query()->where('nome', 'Preventivo 2026')->exists())->toBeFalse();
    $frase = (string) json_encode(session()->all(), JSON_UNESCAPED_UNICODE);
    expect($frase)->toContain('Tetto a rate')->not->toContain('Aggiorna prima l\'importo della voce');

    // La strada della frase: il preventivo con le voci scelte, senza il tetto. A mano: € 1.000,00 di pulizie a 600/400.
    b14t4cPreventivo($this, $r, 'Preventivo 2026', ['capitoli_ids' => [$s->voci['Pulizie']['conto']->id],
        'capitoli_config' => [['id' => $s->voci['Pulizie']['conto']->id, 'importo' => '']]])->assertSessionHasNoErrors();
    expect(b14t4cPerPersona($s, PianoRate::query()->where('nome', 'Preventivo 2026')->sole()))->toBe(['ugo' => 60000, 'bice' => 40000]);
});

it('12 (742 e tappa 6) il fondo che copre la voce a € 0 con la cassa eliminata dalle versioni di prima: il riquadro dice che il fondo non c\'è più', function () {
    $r = b14t4cTetto($this);
    $s = $r['s'];
    $conto = DB::table('conti_contabili')->insertGetId(['condominio_id' => $s->c->id, 'ruolo' => 'fondo_riserva', 'codice' => '1010.T4', 'nome' => 'Fondo di riserva',
        'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now()]);
    $cassa = \App\Models\Gestionale\Cassa::create(['condominio_id' => $s->c->id, 'nome' => 'Fondo di riserva', 'tipo' => 'fondo', 'sottotipo_fondo' => 'generico',
        'conto_contabile_id' => $conto, 'saldo_iniziale' => 500000, 'attiva' => true]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.store', [$s->c]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'ZM0009',
        'data_documento' => '2026-10-20', 'data_scadenza' => '2026-11-20', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => 'sforo_motivato',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => ['motivazione' => 'Infiltrazione', 'importo_sforo' => 140000,
            'strategia_rientro' => 'fondo_riserva', 'fondo_patrimoniale_id' => $conto]],
        'righe' => [['descrizione' => 'Riparazione del manto', 'importo_imponibile' => 1400.00, 'aliquota_iva' => 0, 'conto_id' => $s->voci['Manutenzione tetto']['conto']->id,
            'immobile_id' => null, 'is_sopravvenienza' => false]],
    ])->assertSessionHasNoErrors();
    $f = FatturaPassiva::query()->where('numero_documento', 'ZM0009')->sole();
    // Con la cassa il riquadro nomina il fondo.
    expect(\App\Services\Gestionale\SpesaDaMettereARate::perLaFattura($f->fresh(), $s->admin)['frase'])->toContain('«Fondo di riserva»');
    // La cassa eliminata con le versioni di prima (la guardia della tappa 6 non c'era): la copertura resta pianificata, il fondo non c'è più.
    DB::table('casse')->where('id', $cassa->id)->delete();
    $frase = \App\Services\Gestionale\SpesaDaMettereARate::perLaFattura($f->fresh(), $s->admin)['frase'];
    expect($frase)->toContain('non c\'è più')->toContain('Storna la fattura e registrala di nuovo')->not->toContain('la spesa la copre il fondo di riserva');
});

it('11 (742) la fattura mista nel piano delle fatture non tiene ferma la voce a € 0 della riga rimasta al conguaglio: la voce prende un importo', function () {
    $r = b14t4cTetto($this);
    $s = $r['s'];
    $f = b14t4cFattura($this, $r, [['Manutenzione tetto', 70.00, null], ['Pulizie', 100.00, 'A']], 'ZM0002', 7000);
    b14t4cPianoFatture($this, $r, $f, '100,00', 'Rubinetto di Ugo')->assertSessionHasNoErrors();
    expect(PianoRate::query()->where('nome', 'Rubinetto di Ugo')->sole()->stato->value)->toBe('approvato');
    // Il piano chiede la sola riga dell'unità; la riga del tetto è al conguaglio, e la voce può entrare nel preventivo.
    b14t4cModificaVoce($this, $r, $s->voci['Manutenzione tetto']['conto'], ['importo' => '70,00'])->assertSessionHasNoErrors();
    expect((int) $s->voci['Manutenzione tetto']['conto']->fresh()->importo)->toBe(7000);
});

// --- Il secondo giro di revisione -------------------------------------------------------------------------------------------------------------------------

/** «Sposta spesa» nel piano, come lo manda il modale della pagina. */
function b14t4cSposta($t, array $r, PianoRate $piano, int $da, int $a, string $quanto = '100')
{
    session()->forget('errors');

    return $t->actingAs($r['s']->admin)->from('/piano')->post(route('admin.gestionale.piani-rate.move-budget', [$r['s']->c, $piano]), [
        'source_id' => $da, 'destination_id' => $a, 'amount' => $quanto, 'reason' => 'La spesa del tetto',
    ]);
}

/** Le voci che la pagina del piano offre in «Sposta su» (la prova chiama prima `withoutVite()`). */
function b14t4cDestinazioni($t, array $r, PianoRate $piano): array
{
    $offerte = [];
    $t->actingAs($r['s']->admin)->get(route('admin.gestionale.esercizi.piani-rate.show', [$r['s']->c, $r['s']->e, $piano]))->assertOk()
        ->assertInertia(function ($p) use (&$offerte) {
            $offerte = collect($p->toArray()['props']['destinations'] ?? [])->pluck('nome')->all();
        });

    return $offerte;
}

it('13 (742) «Sposta spesa» non dà budget alla voce a € 0 messa a rate: la pagina non la offre, la richiesta scritta a mano si rifiuta con la frase, e il preventivo rifatto chiede € 2.400,00 per € 2.400,00', function () {
    $r = b14t4cTetto($this);
    $s = $r['s'];
    $f = b14t4cFattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0001', 140000);
    b14t4cPianoFatture($this, $r, $f, '1.400,00')->assertSessionHasNoErrors();
    $tetto = PianoRate::query()->where('nome', 'Tetto a rate')->sole();
    b14t4cPreventivo($this, $r)->assertSessionHasNoErrors();
    $preventivo = PianoRate::query()->where('nome', 'Preventivo 2026')->sole();
    $voce = $s->voci['Manutenzione tetto']['conto'];

    // La pagina del preventivo: «Sposta su» non offre la voce a € 0 la cui spesa è a rate nel piano delle fatture.
    $this->withoutVite();
    $offerte = b14t4cDestinazioni($this, $r, $preventivo);
    expect($offerte)->toContain('Pulizie')->not->toContain('Manutenzione tetto');

    // La richiesta scritta a mano, come la manda il modale: si ferma e lo dice, e niente si sposta.
    b14t4cSposta($this, $r, $preventivo, $s->voci['Pulizie']['conto']->id, $voce->id, '400')->assertSessionHasErrors('destination_id');
    expect(session('errors')->first('destination_id'))->toContain('«Manutenzione tetto»')->toContain('«Tetto a rate»')->toContain('ZM0001')
        ->toContain('togli prima la fattura da quel piano');
    expect(DB::table('budget_movements')->count())->toBe(0)
        ->and(\App\Services\Gestionale\VoceFuoriDalPreventivo::idDellaFattura((int) $f->id))->not->toBe([]);

    // La voce resta ferma, e il preventivo rifatto con tutte le voci. A mano: € 1.000,00 di pulizie + € 1.400,00 di tetto = € 2.400,00 chiesti in tutto.
    b14t4cModificaVoce($this, $r, $voce, ['importo' => '1.400,00'])->assertSessionHasErrors('importo');
    $this->actingAs($s->admin)->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $s->e, $preventivo]));
    expect(PianoRate::query()->whereKey($preventivo->id)->exists())->toBeFalse();
    b14t4cPreventivo($this, $r, 'Preventivo 2026 bis')->assertSessionHasNoErrors();
    $bis = PianoRate::query()->where('nome', 'Preventivo 2026 bis')->sole();
    expect(array_sum(b14t4cPerPersona($s, $bis)) + array_sum(b14t4cPerPersona($s, $tetto->fresh())))->toBe(240000);
});

it('13b (742) «Sposta spesa» rifiutato, la fattura del piano in bozza non diventa mista: il «Ricalcola» chiede ancora 840/560, mai € 1.400,00 a Ugo', function () {
    $r = b14t4cTetto($this, 200000);
    $s = $r['s'];
    $f = b14t4cFattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0003');
    b14t4cModificaVoce($this, $r, $s->voci['Manutenzione tetto']['conto'], ['importo' => '0,00'])->assertSessionHasNoErrors();
    b14t4cPianoFatture($this, $r, $f, '1.400,00')->assertSessionHasNoErrors();
    $piano = PianoRate::query()->where('nome', 'Tetto a rate')->sole();
    b14t4cPreventivo($this, $r)->assertSessionHasNoErrors();
    $preventivo = PianoRate::query()->where('nome', 'Preventivo 2026')->sole();

    b14t4cSposta($this, $r, $preventivo, $s->voci['Pulizie']['conto']->id, $s->voci['Manutenzione tetto']['conto']->id)->assertSessionHasErrors('destination_id');
    b14t4cInBozza($this, $r, $piano);
    b14t4cModificaFattura($this, $r, $f, [['Manutenzione tetto', 1400.00, null], ['Pulizie', 100.00, 'A']])->assertSessionHasErrors('modifica_vietata');
    expect(DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->count())->toBe(1);
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $piano]),
        ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: ricalcolo'])->assertSessionHasNoErrors();
    // A mano: il tetto € 1.400,00 a 600/400.
    expect(b14t4cPerPersona($s, $piano))->toBe(['ugo' => 84000, 'bice' => 56000]);
});

it('13c (742) «Sposta spesa» non dà budget al capitolo sopra la voce a € 0 messa a rate: la pagina non lo offre, la richiesta si rifiuta con la frase del capitolo', function () {
    $r = b14t4cCapitolo($this);
    $s = $r['s'];
    // «Pulizie», fuori dal capitolo: la voce da cui spostare.
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s->c, $s->e, $r['pc']]), [
        'nome' => 'Pulizie', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => '1.000,00',
        'descrizione' => '', 'note' => '', 'parent_id' => null, 'tabella_millesimale_id' => $s->tabelle['Proprietà']->id, 'percentuale_proprietario' => 100,
        'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0, 'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $pulizie = Conto::query()->where('piano_conto_id', $r['pc']->id)->where('nome', 'Pulizie')->sole();
    b14t4cPianoFatture($this, $r, $r['fattura'], '1.400,00')->assertSessionHasNoErrors();
    // Il preventivo «Dalle voci» con il capitolo senza una cifra (chiede le sue voci come sono: il tetto a € 0) e «Pulizie».
    b14t4cPreventivo($this, $r, 'Preventivo 2026', ['capitoli_ids' => [$r['capitolo']->id, $pulizie->id],
        'capitoli_config' => [['id' => $r['capitolo']->id, 'importo' => ''], ['id' => $pulizie->id, 'importo' => '']]])->assertSessionHasNoErrors();
    $preventivo = PianoRate::query()->where('nome', 'Preventivo 2026')->sole();

    $this->withoutVite();
    expect(b14t4cDestinazioni($this, $r, $preventivo))->toContain('Pulizie')->not->toContain('Manutenzioni straordinarie')->not->toContain('Manutenzione tetto');
    b14t4cSposta($this, $r, $preventivo, $pulizie->id, $r['capitolo']->id)->assertSessionHasErrors('destination_id');
    expect(session('errors')->first('destination_id'))->toContain('«Manutenzioni straordinarie»')->toContain('«Manutenzione tetto»')->toContain('«Tetto a rate»');
    expect(DB::table('budget_movements')->count())->toBe(0)
        ->and(\App\Services\Gestionale\VoceFuoriDalPreventivo::idDellaFattura((int) $r['fattura']->id))->not->toBe([]);
});

it('13d (742) lo storno di uno spostamento non rimette budget sulla voce diventata a € 0 e messa a rate dopo: si ferma con la stessa frase', function () {
    $r = b14t4cTetto($this);
    $s = $r['s'];
    b14t4cPreventivo($this, $r)->assertSessionHasNoErrors();
    $preventivo = PianoRate::query()->where('nome', 'Preventivo 2026')->sole();
    $tetto = $s->voci['Manutenzione tetto']['conto'];
    $pulizie = $s->voci['Pulizie']['conto'];
    // Prima della fattura: € 100,00 sul tetto e poi di nuovo via. Il tetto torna a € 0 nel preventivo.
    b14t4cSposta($this, $r, $preventivo, $pulizie->id, $tetto->id)->assertSessionHasNoErrors();
    b14t4cSposta($this, $r, $preventivo, $tetto->id, $pulizie->id)->assertSessionHasNoErrors();
    $via = \App\Models\Gestionale\BudgetMovement::query()->where('source_conto_id', $tetto->id)->sole();
    // La fattura del tetto messa a rate con «Spese fuori preventivo».
    $f = b14t4cFattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0001', 140000);
    b14t4cPianoFatture($this, $r, $f, '1.400,00')->assertSessionHasNoErrors();
    expect(\App\Services\Gestionale\VoceFuoriDalPreventivo::idDellaFattura((int) $f->id))->not->toBe([]);

    session()->forget('errors');
    $this->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [$s->c, $preventivo, $via]))
        ->assertSessionHasErrors('movement');
    expect(session('errors')->first('movement'))->toContain('«Manutenzione tetto»')->toContain('«Tetto a rate»');
    expect(\App\Services\Gestionale\VoceFuoriDalPreventivo::idDellaFattura((int) $f->id))->not->toBe([])
        ->and(\App\Models\Gestionale\BudgetMovement::query()->where('reverses_movement_id', $via->id)->exists())->toBeFalse();
});

it('14 (743) la cifra del compito è quella di oggi, come la pagina: con i pregressi scritti dopo la pregressa l\'Action inbox, la tesoreria e l\'ordine del giorno dicono € 1.200,00', function () {
    $r = b14t4cDebito($this, false);
    $s = $r['s'];
    $compito = $r['compito']->fresh();
    // La fotografia della registrazione resta nel compito; chi lo legge dice lo scoperto di oggi. A mano: € 2.000,00 − € 800,00 = € 1.200,00.
    expect((int) $compito->meta['importo_buco'])->toBe(200000);
    expect(\App\Services\Gestionale\Inbox\TipiDeiCompiti::sottoDaiDati('pianifica_ripianamento_deficit', $compito, null))->toBe('Scoperto di € 1.200,00');
    $punto = \App\Services\Assemblee\PropostaDelPunto::perLaScheda($compito);
    expect($punto['testo'])->toBe('Ripianamento del deficit di € 1.200,00 (debito ereditato verso Idraulica Bianchi)')
        ->and($punto['testo_pubblico'])->toBe('Ripianamento del deficit di € 1.200,00');
    $oggi = \Carbon\CarbonImmutable::parse('2026-12-05');
    expect(app(\App\Services\Treasury\DatiDelleAzioni::class)->perCondominio($s->c, null, $oggi, $oggi->addDays(30), ['righe' => [], 'esercizio_id' => null])['deficit'])
        ->toBe(['quanti' => 1, 'cifra' => 120000]);
});

it('15 (743) i pregressi scritti dopo il ripianamento lo rendono più grande del debito: la scrittura del pregresso lo dice con i numeri, il compito torna aperto e lo dice, la pagina e la richiesta si fermano; il piano non cambia', function () {
    $r = b14t4cDebito($this, true, 200000, 'Idraulica Bianchi', null, false);
    $s = $r['s'];
    expect(RipianamentoDelDeficit::proposta((int) $s->c->id, (int) $r['compito']->id)['massimo'])->toBe(200000);
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), b14t4cCorpo($r, [], ['importo' => '2.000,00']))->assertSessionHasNoErrors();
    $piano = PianoRate::query()->sole();
    $chiesto = b14t4cPerPersona($s, $piano);
    expect(array_sum($chiesto))->toBe(200000)->and((bool) $r['compito']->fresh()->is_completed)->toBeTrue();

    // Dopo, i pregressi di Ugo (€ 500,00) e Bice (€ 300,00) dal Wallet. A mano: debito € 2.000,00 − pregressi € 800,00 = € 1.200,00 da ripianare;
    // a rate ce ne sono € 2.000,00, e i condòmini pagherebbero € 800,00 due volte.
    $frasi = [];
    foreach ([['ugo', 'A', 50000], ['bice', 'B', 30000]] as [$chi, $unita, $cents]) {
        session()->forget('message');
        $this->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
            'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita[$unita]->id, 'gestione_id' => $r['g']->id, 'saldo_iniziale' => $cents,
        ])->assertSessionHasNoErrors();
        $frasi[$chi] = session('message');
    }
    expect($frasi['bice']['type'] ?? null)->toBe('warning');
    expect($frasi['bice']['message'] ?? '')->toContain('Idraulica Bianchi')->toContain('€ 2.000,00')->toContain('€ 1.200,00')->toContain('€ 800,00');
    expect($frasi['ugo']['message'] ?? '')->toContain('€ 500,00');

    // Il piano non cambia da solo.
    expect(b14t4cPerPersona($s, $piano->fresh()))->toBe($chiesto);
    // Il compito torna aperto e lo dice; la tesoreria non conta un deficit da ripianare.
    $compito = $r['compito']->fresh();
    expect((bool) $compito->is_completed)->toBeFalse()
        ->and(\App\Services\Gestionale\Inbox\TipiDeiCompiti::sottoDaiDati('pianifica_ripianamento_deficit', $compito, null))->toContain('€ 800,00');
    $oggi = \Carbon\CarbonImmutable::parse('2026-12-05');
    expect(app(\App\Services\Treasury\DatiDelleAzioni::class)->perCondominio($s->c, null, $oggi, $oggi->addDays(30), ['righe' => [], 'esercizio_id' => null])['deficit'])
        ->toBe(['quanti' => 0, 'cifra' => 0]);
    // La pagina che il compito apre lo dice, senza i campi; la richiesta si ferma con la stessa frase.
    $url = \App\Services\Gestionale\Inbox\TipiDeiCompiti::azione('pianifica_ripianamento_deficit', $compito)['url'];
    expect($url)->toContain('ripianamento=' . $compito->id);
    $this->withoutVite();
    $this->actingAs($s->admin)->get(route('admin.gestionale.esercizi.piani-rate.create', [$s->c, $s->e]) . '?tipo=straordinario&ripianamento=' . $compito->id)
        ->assertOk()->assertInertia(fn ($p) => $p->where('ripianamento.altrove', fn ($frase) => is_string($frase) && str_contains($frase, '€ 800,00')));
    $ancora = $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), b14t4cCorpo($r, ['nome' => 'Ancora'], ['importo' => '100,00']));
    $ancora->assertUnprocessable();
    expect(collect($ancora->json('errors'))->flatten()->implode(' '))->toContain('€ 800,00');
    expect(PianoRate::query()->count())->toBe(1);
});

it('16 (743) due debiti: la seconda parte sullo stesso debito, con il suo compito chiuso dal programma, si rifiuta con la strada vera, il compito dell\'altro debito', function () {
    $r1 = b14t4cDebito($this, true, 200000, 'Idraulica Bianchi');
    $r2 = b14t4cDebito($this, true, 100000, 'Edile Verdi', $r1['s']);
    $s = $r1['s'];
    // A mano: debiti € 3.000,00 − pregressi € 800,00 = € 2.200,00; l'assemblea delibera € 600,00 su Edile, restano € 1.600,00.
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]),
        b14t4cCorpo($r2, ['nome' => 'Edile 1'], ['importo' => '600,00']))->assertSessionHasNoErrors();
    expect((bool) $r2['compito']->fresh()->is_completed)->toBeTrue()
        ->and((bool) $r1['compito']->fresh()->is_completed)->toBeFalse();

    $seconda = $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]),
        b14t4cCorpo($r2, ['nome' => 'Edile 2'], ['importo' => '400,00']));
    $seconda->assertUnprocessable();
    $frase = collect($seconda->json('errors'))->flatten()->implode(' ');
    expect($frase)->not->toContain('non è più da ripianare')->toContain('€ 1.600,00')->toContain('Idraulica Bianchi');
    // La strada della frase: dal compito dell'altro debito.
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]),
        b14t4cCorpo($r1, ['nome' => 'Idraulica'], ['importo' => '400,00']))->assertSessionHasNoErrors();
    // A mano: € 2.200,00 − € 600,00 − € 400,00 = € 1.200,00 ancora da ripianare, in un compito aperto.
    expect(Evento::query()->where('meta->type', 'pianifica_ripianamento_deficit')->where('is_completed', false)->get()->sum(fn ($c) => (int) $c->meta['importo_buco']))->toBe(120000);
});

it('17 (742) la fattura del piano con una riga di un\'unità aggiunta: la frase dice che la riga sulla voce a € 0 non torna nel piano delle fatture, e la strada «Dalle voci» è vera', function () {
    $r = b14t4cTetto($this, 200000);
    $s = $r['s'];
    $f = b14t4cFattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0003');
    b14t4cModificaVoce($this, $r, $s->voci['Manutenzione tetto']['conto'], ['importo' => '0,00'])->assertSessionHasNoErrors();
    b14t4cPianoFatture($this, $r, $f, '1.400,00')->assertSessionHasNoErrors();
    $piano = PianoRate::query()->where('nome', 'Tetto a rate')->sole();
    b14t4cInBozza($this, $r, $piano);

    b14t4cModificaFattura($this, $r, $f, [['Manutenzione tetto', 1400.00, null], ['Pulizie', 100.00, 'A']])->assertSessionHasErrors('modifica_vietata');
    $frase = session('errors')->first('modifica_vietata');
    expect($frase)->toContain('ogli prima la fattura dal piano')->toContain('non torna nel piano delle fatture')->toContain('conguaglio')->toContain('«Dalle voci»')
        ->not->toContain('mettila di nuovo a rate');

    // La strada della frase, fino in fondo: eliminato il piano, la modifica passa; il tetto si chiede «Dalle voci» con la voce e la cifra.
    $this->actingAs($s->admin)->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $s->e, $piano]));
    expect(PianoRate::query()->whereKey($piano->id)->exists())->toBeFalse();
    b14t4cModificaFattura($this, $r, $f, [['Manutenzione tetto', 1400.00, null], ['Pulizie', 100.00, 'A']])->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Tetto dalle voci', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'capitoli_ids' => [$s->voci['Manutenzione tetto']['conto']->id],
        'capitoli_config' => [['id' => $s->voci['Manutenzione tetto']['conto']->id, 'importo' => '1.400,00']], 'genera_subito' => 1,
    ])->assertSessionHasNoErrors();
    // A mano: il tetto € 1.400,00 a 600/400.
    expect(b14t4cPerPersona($s, PianoRate::query()->where('nome', 'Tetto dalle voci')->sole()))->toBe(['ugo' => 84000, 'bice' => 56000]);
});
