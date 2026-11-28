<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 743 (decisione 655): «Prepara il piano di ripianamento» porta a un piano che si salva, con la partita doppia giusta.
 *
 * Il caso di Idraulica Bianchi: il debito ereditato di € 2.000,00, i pregressi chiesti ai condòmini € 800,00 (già nella rata 0), la fattura pregressa sul debito
 * (la copertura «rata 0» di € 2.000,00). Il compito «Deficit da recuperare» dice € 1.200,00 scoperti. Il piano si salva con il debito da ripianare
 * (`ripianamento`): la voce nuova, la copertura «sopravvenienza» e la scrittura del ripianamento nascono con lui; eliminato il piano, si tolgono (lo storno della
 * scrittura). I difetti trovati rivedendo questo lavoro hanno le loro prove in `Beta14RipianamentoEVoceAZeroTest`.
 *
 * - la catena dal debito ereditato all'incasso della prima rata, con le scritture controllate riga per riga;
 * - l'annullamento: eliminato il piano, niente copertura orfana, il compito torna da fare, la pregressa si storna e quadra;
 * - il doppio conto: lo stesso deficit non si mette a rate due volte, la voce non torna in «Dalle voci», la pregressa non torna nel carrello;
 * - le regole della richiesta e i due ingressi (l'Action inbox e la pagina del nuovo piano).
 *
 * Cosa resta scoperto: il seguito della delibera (`Assemblee/Seguito/RipianamentoDalSeguitoTest`); la pagina (`PianiRateNewRipianamento.test.ts`).
 */

use App\Events\Gestionale\FatturaRegistrata;
use App\Listeners\Gestionale\SyncScadenziarioWithFattura;
use App\Models\Evento;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaCopertura;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Saldo;
use App\Services\CondominioService;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

/** Il condominio con il debito ereditato verso Idraulica Bianchi, la pregressa e il compito del deficit. */
function rddScenario($t): array
{
    Event::fake([FatturaRegistrata::class]);
    // Il giorno della prova: dentro l'esercizio 2026, dopo la prima scadenza (l'incasso non si registra nel futuro).
    $t->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
    $s = Beta51Scenario::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->tabella('Scale', ['A' => 500, 'B' => 500])
        ->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026');
    app(CondominioService::class)->ensureDefaultConti($s->c);
    $s->cassa();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $g = $s->gestioni['Ordinaria 2026']['g'];
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Idraulica Bianchi', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $debito = Saldo::forceCreate(['condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'gestione_id' => $g->id, 'fornitore_id' => $fornitoreId,
        'saldo_iniziale' => -200000, 'is_applicato' => false, 'descrizione' => 'Riparazione della colonna non pagata']);
    // I pregressi chiesti ai condòmini: € 800,00, già nella rata 0 del piano ordinario.
    $s->saldo('ugo', 'A', 'Ordinaria 2026', 50000, true);
    $s->saldo('bice', 'B', 'Ordinaria 2026', 30000, true);

    $t->actingAs($s->admin);
    $fattura = (new FatturaPassivaService())->registraFattura([
        'fornitore_id' => $fornitoreId, 'esercizio_id' => $s->e->id, 'gestione_id' => $g->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'IB-77',
        'data_documento' => '2025-11-20', 'data_scadenza' => '2025-12-20', 'modalita_pagamento' => 'bonifico', 'is_pregresso' => true, 'imponibile_pregresso' => 2000.00,
        'aliquota_iva_pregressa' => 0, 'saldo_patrimoniale_id' => $debito->id, 'righe' => [],
        'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 2000.00, 'fonte_id' => $debito->id]],
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
    ], $s->c->id);
    // Il listener è in coda e dopo il commit: lo si esegue a mano, come il lavoratore della coda.
    (new SyncScadenziarioWithFattura())->handle(new FatturaRegistrata($fattura, $s->admin->id));
    $compito = Evento::query()->where('meta->type', 'pianifica_ripianamento_deficit')->where('meta->context->saldo_id', $debito->id)->sole();

    return compact('s', 'g', 'fattura', 'compito', 'debito', 'fornitoreId');
}

/** Il corpo del nuovo piano come lo manda la pagina, con il debito da ripianare. */
function rddCorpo(array $r, array $piu = [], array $ripianamento = []): array
{
    return array_merge([
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Ripianamento Idraulica Bianchi', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale del 15/11/2026, punto 3',
        'fatture_config' => [], 'genera_subito' => 1, 'accetta_destinatari' => 1, 'nota_destinatari' => 'Letto: il ripianamento del debito ereditato',
        'ripianamento' => array_merge(['compito_id' => $r['compito']->id, 'importo' => '1.200,00', 'tabella_id' => $r['s']->tabelle['Proprietà']->id], $ripianamento),
    ], $piu);
}

function rddSalva($t, array $r, array $piu = [], array $ripianamento = []): PianoRate
{
    $t->actingAs($r['s']->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), rddCorpo($r, $piu, $ripianamento))
        ->assertSessionHasNoErrors();

    return PianoRate::query()->where('condominio_id', $r['s']->c->id)->where('nome', $piu['nome'] ?? 'Ripianamento Idraulica Bianchi')->sole();
}

/** Le righe di una scrittura: [ruolo del conto, dare|avere, centesimi, nome della voce o null], nell'ordine. @return list<array{0: string, 1: string, 2: int, 3: ?string}> */
function rddRighe(int $scritturaId): array
{
    return DB::table('righe_scritture as rs')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')->leftJoin('conti as v', 'v.id', '=', 'rs.voce_spesa_id')
        ->where('rs.scrittura_id', $scritturaId)->orderBy('rs.id')->get(['cc.ruolo', 'rs.tipo_riga', 'rs.importo', 'v.nome'])
        ->map(fn ($x) => [(string) $x->ruolo, (string) $x->tipo_riga, (int) $x->importo, $x->nome === null ? null : (string) $x->nome])->all();
}

/** Il saldo di un conto di sistema, AVERE − DARE, su tutto il giornale del condominio. */
function rddSaldo(array $r, string $ruolo): int
{
    return (int) DB::table('righe_scritture as rs')->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
        ->where('sc.condominio_id', $r['s']->c->id)->whereNull('sc.deleted_at')->where('cc.ruolo', $ruolo)
        ->selectRaw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE -rs.importo END), 0) as s")->value('s');
}

/** Le coperture della pregressa: [tipo, centesimi], nell'ordine. @return list<array{0: string, 1: int}> */
function rddCoperture(FatturaPassiva $f): array
{
    return FatturaCopertura::query()->where('fattura_passiva_id', $f->id)->orderBy('tipo_copertura')->orderBy('id')->get()
        ->map(fn ($c) => [(string) $c->tipo_copertura, (int) $c->importo])->all();
}

it('743, la catena: dal debito ereditato all\'incasso della prima rata, con le scritture riga per riga e il compito del deficit chiuso', function () {
    $r = rddScenario($this);
    $s = $r['s'];
    // Prima: la pregressa chiusa tutta sulle passate gestioni; il compito dice € 1.200,00; il Fondo Passate Gestioni −€ 2.000,00.
    $pregressa = ScritturaContabile::query()->where('tipo_movimento', 'fattura_acquisto')->where('condominio_id', $s->c->id)->sole();
    expect(rddRighe($pregressa->id))->toBe([['passate_gestioni', 'dare', 200000, null], ['debiti_fornitori', 'avere', 200000, null]])
        ->and((int) $r['compito']->meta['importo_buco'])->toBe(120000)
        ->and(rddCoperture($r['fattura']))->toBe([['rata_0', 200000]]);

    $piano = rddSalva($this, $r);

    // La voce nuova, tecnica, con la tabella scelta e il preventivo della cifra.
    $voce = Conto::query()->where('nome', 'Ripianamento del debito verso Idraulica Bianchi')->sole();
    expect((bool) $voce->is_tecnico)->toBeTrue()->and((int) $voce->importo)->toBe(120000)
        ->and(DB::table('conto_tabella_millesimale')->where('conto_id', $voce->id)->value('tabella_id'))->toEqual($s->tabelle['Proprietà']->id);
    // Le coperture sommano il totale: la rata 0 scende della cifra, la sopravvenienza la prende.
    expect(rddCoperture($r['fattura']))->toBe([['rata_0', 80000], ['sopravvenienza', 120000]]);
    // Il ripianamento: DARE Sopravvenienze passive (con la voce) / AVERE Fondo Passate Gestioni, legato alla fattura con il legame `rettifica`.
    $rettifica = ScritturaContabile::query()->where('tipo_movimento', 'ripianamento_debito')->where('condominio_id', $s->c->id)->sole();
    expect(rddRighe($rettifica->id))->toBe([['sopravvenienze_passive', 'dare', 120000, 'Ripianamento del debito verso Idraulica Bianchi'], ['passate_gestioni', 'avere', 120000, null]])
        ->and((int) $rettifica->gestione_id)->toBe($r['g']->id)->and((int) $rettifica->esercizio_id)->toBe($s->e->id)
        ->and(DB::table('fattura_scrittura')->where('scrittura_contabile_id', $rettifica->id)->where('fattura_passiva_id', $r['fattura']->id)->value('tipo'))->toBe('rettifica');
    // Il piano mette a rate la pregressa per la cifra, divisa a 600 e 400 millesimi; il compito del deficit è chiuso.
    expect((int) DB::table('piano_rate_fatture')->where('piano_rate_id', $piano->id)->where('fattura_passiva_id', $r['fattura']->id)->value('importo_collegato'))->toBe(120000);
    $perPersona = fn (string $chi) => (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
        ->where('rate_quote.anagrafica_id', $s->persona($chi)->id)->sum('rate_quote.importo');
    expect($perPersona('ugo'))->toBe(72000)->and($perPersona('bice'))->toBe(48000)
        ->and((bool) $r['compito']->fresh()->is_completed)->toBeTrue()
        ->and(DB::table('risposte_promemoria')->where('evento_id', $r['compito']->id)->value('nota'))->toStartWith('Chiuso: piano «Ripianamento Idraulica Bianchi» creato il ');

    // Il saldo patrimoniale: il Fondo Passate Gestioni dice −€ 800,00, i soli pregressi dei condòmini che la rata 0 chiede; lo scoperto non c'è più. La spesa
    // dell'anno sulla voce è € 1.200,00, quanto chiede il piano.
    expect(rddSaldo($r, 'passate_gestioni'))->toBe(-80000)
        ->and(app(\App\Services\Gestionale\SpesaPerVoceService::class)->perEsercizio($s->e)[$voce->id] ?? null)->toBe(120000);

    // L'emissione della prima rata: DARE Crediti verso condòmini a nome di ognuno, AVERE Gestione rate.
    $rata1 = DB::table('rate')->where('piano_rate_id', $piano->id)->orderBy('numero_rata')->first();
    $this->actingAs($s->admin)->post(route('admin.gestionale.piani-rate.emetti', [$s->c, $piano]), ['rate_ids' => [$rata1->id], 'data_emissione' => '2026-11-30',
        'invia_notifiche' => false])->assertSessionHasNoErrors();
    $emissione = ScritturaContabile::query()->where('tipo_movimento', 'emissione_rata')->where('condominio_id', $s->c->id)->sole();
    expect(rddRighe($emissione->id))->toEqualCanonicalizing([['crediti_condomini', 'dare', 36000, null], ['crediti_condomini', 'dare', 24000, null],
        ['gestione_rate', 'avere', 60000, null]]);

    // L'incasso della prima rata di Ugo: DARE la banca, AVERE Crediti verso condòmini.
    $quota = DB::table('rate_quote')->where('rata_id', $rata1->id)->where('anagrafica_id', $s->persona('ugo')->id)->first();
    $this->actingAs($s->admin)->post(route('admin.gestionale.movimenti-rate.store', [$s->c]), [
        'pagante_id' => $s->persona('ugo')->id, 'cassa_id' => $s->cassa->id, 'data_pagamento' => '2026-12-01', 'importo_totale' => '360.00', 'descrizione' => 'Bonifico',
        'eccedenza' => '0.00', 'gestione_id' => $r['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => '360.00']],
    ])->assertSessionHasNoErrors();
    $incasso = ScritturaContabile::query()->where('tipo_movimento', 'incasso_rata')->where('condominio_id', $s->c->id)->sole();
    expect(rddRighe($incasso->id))->toBe([['conto_bancario', 'dare', 36000, null], ['crediti_condomini', 'avere', 36000, null]])
        ->and(rddSaldo($r, 'gestione_rate'))->toBe(60000)
        ->and(rddSaldo($r, 'crediti_condomini'))->toBe(-24000);

    // Il registro delle modifiche: una riga sulla fattura dice che cosa è successo.
    expect(DB::table('registro_modifiche')->where('oggetto_tipo', 'fattura_passiva')->where('oggetto_id', $r['fattura']->id)->pluck('riepilogo')->implode(' | '))
        ->toContain('€ 1.200,00 del debito ereditato messi a rate nel piano «Ripianamento Idraulica Bianchi»');
});

it('743, l\'annullamento: eliminato il piano, la copertura si toglie (lo storno del ripianamento, rata 0 rimessa, voce a zero), il compito torna da fare e la pregressa si storna e quadra', function () {
    $r = rddScenario($this);
    $s = $r['s'];
    $piano = rddSalva($this, $r, ['genera_subito' => 0]);
    $this->actingAs($s->admin)->put(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $piano]), ['approvato' => false])->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $s->e, $piano]))->assertSessionHasNoErrors();

    $voce = Conto::query()->where('nome', 'Ripianamento del debito verso Idraulica Bianchi')->sole();
    $rettifiche = ScritturaContabile::query()->whereIn('tipo_movimento', ['ripianamento_debito', 'storno_ripianamento_debito'])->where('condominio_id', $s->c->id)
        ->orderBy('id')->get();
    expect(PianoRate::query()->whereKey($piano->id)->exists())->toBeFalse()
        ->and(rddCoperture($r['fattura']))->toBe([['rata_0', 200000]])
        ->and($rettifiche)->toHaveCount(2)
        ->and(rddRighe($rettifiche[1]->id))->toBe([['passate_gestioni', 'dare', 120000, null], ['sopravvenienze_passive', 'avere', 120000, 'Ripianamento del debito verso Idraulica Bianchi']])
        ->and((int) $rettifiche[1]->scrittura_padre_id)->toBe($rettifiche[0]->id)
        ->and((int) $voce->importo)->toBe(0)
        ->and(app(\App\Services\Gestionale\SpesaPerVoceService::class)->perEsercizio($s->e)[$voce->id] ?? 0)->toBe(0)
        ->and(rddSaldo($r, 'passate_gestioni'))->toBe(-200000)
        ->and((bool) $r['compito']->fresh()->is_completed)->toBeFalse();

    // La pregressa si storna e il giornale torna a zero su tutti e tre i conti: niente resta orfano.
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.storno', [$s->c, $r['fattura']]))->assertSessionHasNoErrors();
    expect($r['fattura']->fresh()->stato_pagamento->value ?? $r['fattura']->fresh()->stato_pagamento)->toBe('stornata')
        ->and(rddSaldo($r, 'passate_gestioni'))->toBe(0)
        ->and(rddSaldo($r, 'sopravvenienze_passive'))->toBe(0)
        ->and(rddSaldo($r, 'debiti_fornitori'))->toBe(0);
});

it('743, il doppio conto: lo stesso deficit non si mette a rate due volte; la voce non torna in «Dalle voci»; la pregressa nel carrello è già tutta nel piano', function () {
    $r = rddScenario($this);
    $s = $r['s'];
    rddSalva($this, $r);

    // Il compito è chiuso: un secondo piano con lo stesso deficit si rifiuta, e niente si scrive.
    $resp = $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), rddCorpo($r, ['nome' => 'Di nuovo']));
    $resp->assertUnprocessable();
    expect($resp->json('errors')['ripianamento.compito_id'][0])->toContain('non è più da ripianare')
        ->and(ScritturaContabile::query()->where('tipo_movimento', 'ripianamento_debito')->count())->toBe(1)
        ->and(rddCoperture($r['fattura']))->toBe([['rata_0', 80000], ['sopravvenienza', 120000]]);

    // «Dalle voci del preventivo» non offre la voce del ripianamento (tecnica).
    $selettore = $this->actingAs($s->admin)->getJson(route('admin.gestionale.fetch-capitoli-gestione', ['condominio' => $s->c->id, 'gestione_id' => $r['g']->id,
        'esercizio_id' => $s->e->id]))->assertOk()->json();
    expect(collect($selettore)->pluck('nome')->implode(' | '))->not->toContain('Ripianamento');

    // Il carrello vede la pregressa con la sopravvenienza, già tutta nel piano: niente da mettere a rate.
    $carrello = collect($this->actingAs($s->admin)->getJson(route('admin.gestionale.fetch-fatture-straordinarie', ['condominio' => $s->c->id,
        'gestione_id' => $r['g']->id, 'esercizio_id' => $s->e->id]))->assertOk()->json());
    expect($carrello->firstWhere('id', $r['fattura']->id))->toBeNull();

    // La pregressa nel piano non si storna finché il piano c'è.
    expect($r['fattura']->fresh()->motivoBloccoStorno())->not->toBeNull();
});

it('743, le regole: la cifra fra zero e lo scoperto, la gestione della pregressa, la scheda delle fatture, una tabella del condominio, la pregressa non anche nel carrello', function () {
    $r = rddScenario($this);
    $s = $r['s'];
    $prova = fn (array $piu, array $rip = []) => $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), rddCorpo($r, $piu, $rip));

    expect($prova([], ['importo' => '2.500,00'])->assertUnprocessable()->json('errors')['ripianamento.importo'][0])->toContain('al massimo per € 1.200,00');
    expect($prova([], ['importo' => '0,00'])->assertUnprocessable()->json('errors')['ripianamento.importo'][0])->toBe('Scrivi quanto del debito ereditato mettere a rate.');
    $s->gestione('Facciata 2026', 'straordinaria');
    expect($prova(['gestione_id' => $s->gestioni['Facciata 2026']['g']->id, 'data_delibera_assemblea' => '2026-11-15'])->assertUnprocessable()->json('errors')['gestione_id'][0])
        ->toBe('Il debito verso Idraulica Bianchi è sulla gestione «Ordinaria 2026»: il piano del ripianamento si fa su quella gestione.');
    expect($prova(['tipo' => 'ordinario'])->assertUnprocessable()->json('errors')['ripianamento'][0])->toBe('Il debito ereditato si mette a rate con «Spese fuori preventivo».');
    $altra = \App\Models\Tabella::create(['condominio_id' => \App\Models\Condominio::factory()->create()->id, 'nome' => 'Di un altro', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    expect($prova([], ['tabella_id' => $altra->id])->assertUnprocessable()->json('errors')['ripianamento.tabella_id'][0])->toBe('Scegli una tabella attiva di questo condominio.');
    expect($prova(['fatture_config' => [['id' => $r['fattura']->id, 'importo' => '100,00']]])->assertUnprocessable()->json('errors')['ripianamento'][0])
        ->toBe('La fattura n. IB-77 è già nel ripianamento del debito ereditato: toglila dal carrello.');

    // Niente si è scritto: la pregressa è come prima, il compito aperto.
    expect(rddCoperture($r['fattura']))->toBe([['rata_0', 200000]])->and((bool) $r['compito']->fresh()->is_completed)->toBeFalse()
        ->and(PianoRate::query()->where('condominio_id', $s->c->id)->count())->toBe(0);
});

it('743, i due ingressi: l\'Action inbox porta il compito e la gestione nell\'indirizzo; la pagina del nuovo piano propone la cifra, il massimo, la gestione e le tabelle', function () {
    $r = rddScenario($this);
    $s = $r['s'];
    $azione = \App\Services\Gestionale\Inbox\TipiDeiCompiti::azione('pianifica_ripianamento_deficit', $r['compito']->fresh());
    expect($azione['testo'])->toBe('Prepara il piano di ripianamento')
        ->and($azione['url'])->toContain('tipo=straordinario')->toContain('gestione_id=' . $r['g']->id)->toContain('ripianamento=' . $r['compito']->id);

    $this->actingAs($s->admin)->get(route('admin.gestionale.esercizi.piani-rate.create', [$s->c, $s->e]) . '?tipo=straordinario&ripianamento=' . $r['compito']->id)
        ->assertOk()->assertInertia(fn ($p) => $p->where('ripianamento.compito_id', $r['compito']->id)->where('ripianamento.scoperto', 120000)
            ->where('ripianamento.massimo', 120000)->where('ripianamento.proposto', 120000)->where('ripianamento.gestione_id', $r['g']->id)
            ->where('ripianamento.voce', 'Ripianamento del debito verso Idraulica Bianchi')->where('ripianamento.fatture.0.numero', 'IB-77')
            ->has('ripianamento.tabelle', 2)->etc());

    // Un compito di un altro condominio, o già chiuso, non propone niente: la pagina si apre come prima.
    $this->actingAs($s->admin)->get(route('admin.gestionale.esercizi.piani-rate.create', [$s->c, $s->e]) . '?ripianamento=999999')
        ->assertOk()->assertInertia(fn ($p) => $p->where('ripianamento', null)->etc());
});
