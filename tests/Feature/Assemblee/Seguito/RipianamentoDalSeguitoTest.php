<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Events\Gestionale\FatturaRegistrata;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Evento;
use App\Models\Gestionale\FatturaCopertura;
use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use App\Services\Assemblee\Seguito\CoseDaFare;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * Coda 743 (decisione 655), dal seguito della delibera (l'assemblea n. 8 di Via delle Acacie 12 della prova a video): il debito ereditato verso Idraulica
 * Bianchi, il compito «Deficit da recuperare», il punto dal programma, la delibera approvata, «Prepara il piano di ripianamento». Fino alla beta.13 il pulsante
 * apriva lo straordinario con il carrello vuoto («Scegli almeno una fattura per salvare»), e la cosa e il compito restavano aperti per sempre.
 *
 * Che cosa non copre: le scritture riga per riga, l'annullamento con lo storno e il doppio conto (`Gestionale/RipianamentoDelDeficitTest`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Il debito ereditato di € 1.200,00 verso Idraulica Bianchi, il compito del deficit, il punto dal programma, la delibera approvata e la cosa da fare. */
function rdsScenario($t): array
{
    Event::fake([FatturaRegistrata::class]);
    $s = ViaRoma12::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'anna')->gestione('Ordinaria 2026');
    app(\App\Services\CondominioService::class)->ensureDefaultConti($s->c);
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $g = $s->gestioni['Ordinaria 2026']['g'];
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Idraulica Bianchi', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $debito = Saldo::forceCreate(['condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'gestione_id' => $g->id, 'fornitore_id' => $fornitoreId,
        'saldo_iniziale' => -120000, 'is_applicato' => false]);
    $t->actingAs($s->admin);
    $fattura = (new \App\Services\Gestionale\FatturaPassivaService())->registraFattura([
        'fornitore_id' => $fornitoreId, 'esercizio_id' => $s->e->id, 'gestione_id' => $g->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'IB-12',
        'data_documento' => '2025-10-10', 'data_scadenza' => '2025-11-10', 'modalita_pagamento' => 'bonifico', 'is_pregresso' => true, 'imponibile_pregresso' => 1200.00,
        'aliquota_iva_pregressa' => 0, 'saldo_patrimoniale_id' => $debito->id, 'righe' => [],
        'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 1200.00, 'fonte_id' => $debito->id]],
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
    ], $s->c->id);
    (new \App\Listeners\Gestionale\SyncScadenziarioWithFattura())->handle(new FatturaRegistrata($fattura, $s->admin->id));
    $deficit = Evento::query()->where('meta->type', 'pianifica_ripianamento_deficit')->sole();

    $p = \App\Services\Assemblee\PropostaDelPunto::perLaScheda($deficit);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => $p['testo'], 'materia' => $p['materia'], 'oggetti' => [['compito', $deficit->id, 'origine']],
        'chiave_fonte' => 'compito:' . $deficit->id, 'fonte' => 'programma']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $chiave = $d->id . ':cosa:prepara_ripianamento';
    SeguitoDiViaRoma12::conferma($t, $s, $a, [$chiave], ['cose' => [$chiave => ['entro_il' => '2027-01-15']]])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->where('tipo', TipoCosaDaFare::PREPARA_RIPIANAMENTO->value)->firstOrFail();

    return compact('s', 'g', 'fattura', 'deficit', 'd', 'cosa');
}

it('743, dal seguito: «Prepara il piano di ripianamento» apre lo straordinario con il debito, il piano si salva con la delibera, la cosa e il compito si chiudono; eliminato il piano, tornano da fare', function () {
    Event::fake([FatturaRegistrata::class]);
    $s = ViaRoma12::nuovo($this)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'anna')->gestione('Ordinaria 2026');
    app(\App\Services\CondominioService::class)->ensureDefaultConti($s->c);
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $g = $s->gestioni['Ordinaria 2026']['g'];
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Idraulica Bianchi', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $debito = Saldo::forceCreate(['condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'gestione_id' => $g->id, 'fornitore_id' => $fornitoreId,
        'saldo_iniziale' => -120000, 'is_applicato' => false]);
    $this->actingAs($s->admin);
    $fattura = (new \App\Services\Gestionale\FatturaPassivaService())->registraFattura([
        'fornitore_id' => $fornitoreId, 'esercizio_id' => $s->e->id, 'gestione_id' => $g->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'IB-12',
        'data_documento' => '2025-10-10', 'data_scadenza' => '2025-11-10', 'modalita_pagamento' => 'bonifico', 'is_pregresso' => true, 'imponibile_pregresso' => 1200.00,
        'aliquota_iva_pregressa' => 0, 'saldo_patrimoniale_id' => $debito->id, 'righe' => [],
        'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 1200.00, 'fonte_id' => $debito->id]],
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
    ], $s->c->id);
    (new \App\Listeners\Gestionale\SyncScadenziarioWithFattura())->handle(new FatturaRegistrata($fattura, $s->admin->id));
    $deficit = Evento::query()->where('meta->type', 'pianifica_ripianamento_deficit')->sole();

    // Il punto dal programma, la delibera approvata, la cosa «Prepara il piano di ripianamento».
    $p = \App\Services\Assemblee\PropostaDelPunto::perLaScheda($deficit);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => $p['testo'], 'materia' => $p['materia'], 'oggetti' => [['compito', $deficit->id, 'origine']],
        'chiave_fonte' => 'compito:' . $deficit->id, 'fonte' => 'programma']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $chiave = $d->id . ':cosa:prepara_ripianamento';
    SeguitoDiViaRoma12::conferma($this, $s, $a, [$chiave], ['cose' => [$chiave => ['entro_il' => '2027-01-15']]])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->where('tipo', TipoCosaDaFare::PREPARA_RIPIANAMENTO->value)->firstOrFail();

    // Il pulsante porta la delibera, la gestione della pregressa e il compito: la pagina mette il debito nel piano.
    $url = CoseDaFare::azione($cosa)['url'];
    expect($url)->toContain('tipo=straordinario')->toContain('delibera_id=' . $d->id)->toContain('gestione_id=' . $g->id)->toContain('ripianamento=' . $deficit->id);

    // Il piano si salva con la delibera e il debito, senza fatture nel carrello.
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $g->id, 'nome' => 'Ripianamento Idraulica Bianchi', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-12-30', 'tipo_autorizzazione' => 'delibera', 'delibera_id' => $d->id, 'fatture_config' => [],
        'genera_subito' => 0, 'ripianamento' => ['compito_id' => $deficit->id, 'importo' => '1.200,00', 'tabella_id' => $s->tabelle['Proprietà']->id],
    ])->assertSessionHasNoErrors();
    $piano = PianoRate::query()->where('condominio_id', $s->c->id)->sole();
    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and((bool) $deficit->fresh()->is_completed)->toBeTrue()
        ->and((bool) Evento::query()->findOrFail($cosa->evento_id)->is_completed)->toBeTrue()
        ->and(FatturaCopertura::query()->where('fattura_passiva_id', $fattura->id)->orderBy('tipo_copertura')->pluck('importo', 'tipo_copertura')->map(fn ($v) => (int) $v)->all())
        ->toBe(['sopravvenienza' => 120000])
        ->and(\App\Services\Assemblee\CollegamentiDelleDelibere::delibera($piano, 'delibera')?->id)->toBe($d->id);

    // Eliminato il piano (prima tolta l'approvazione), il debito torna alle passate gestioni, e la cosa e il compito tornano da fare.
    $this->actingAs($s->admin)->put(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $piano]), ['approvato' => false])->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $s->e, $piano]))->assertSessionHasNoErrors();
    expect(FatturaCopertura::query()->where('fattura_passiva_id', $fattura->id)->pluck('importo', 'tipo_copertura')->map(fn ($v) => (int) $v)->all())->toBe(['rata_0' => 120000])
        ->and($cosa->fresh()->stato)->toBe(StatoCosaDaFare::DA_FARE)
        ->and((bool) $deficit->fresh()->is_completed)->toBeFalse()
        ->and(CoseDaFare::azione($cosa->fresh())['url'])->toContain('ripianamento=' . $deficit->id);
});


it('743, dal seguito con una parte sola: la cosa della delibera è fatta, ma il compito del deficit resta aperto con il resto', function () {
    $r = rdsScenario($this);
    $s = $r['s'];

    // Il piano con la delibera mette a rate € 500,00 dei € 1.200,00: il seguito chiude la cosa e il compito, e il programma riapre il compito con il resto.
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Prima parte del debito', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-12-30', 'tipo_autorizzazione' => 'delibera', 'delibera_id' => $r['d']->id, 'fatture_config' => [],
        'genera_subito' => 0, 'ripianamento' => ['compito_id' => $r['deficit']->id, 'importo' => '500,00', 'tabella_id' => $s->tabelle['Proprietà']->id],
    ])->assertSessionHasNoErrors();

    expect($r['cosa']->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and((bool) $r['deficit']->fresh()->is_completed)->toBeFalse()
        ->and((int) $r['deficit']->fresh()->meta['importo_buco'])->toBe(70000)
        ->and(\App\Services\Gestionale\RipianamentoDelDeficit::proposta((int) $s->c->id, (int) $r['deficit']->id)['massimo'])->toBe(70000);
});
