<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\SpesaDaMettereARate;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/../Assemblee/Seguito/SeguitoDiViaRoma12.php';

/**
 * Coda 689, «Metti a rate questa spesa» (la scena 9 della bozza approvata con la decisione 623): il saldo del portone, la fattura n. 88 di € 5.500,00 sulla voce
 * «Sostituzione del portone» (€ 14.500,00), con il piano di gennaio che chiede € 9.000,00. Il riquadro «Le rate» della pagina della fattura dice «€ 5.500,00
 * ancora fuori dai piani della voce» con il pulsante; il pulsante (e la voce del menu della riga dell'elenco) apre il nuovo piano sulla scheda delle voci,
 * con la gestione, la voce e la delibera della gestione già scelte. Una fattura con una riga «Fuori preventivo» apre invece la scheda dello straordinario
 * con la fattura nel carrello. Quando la voce è tutta nei piani, «Nei piani della voce» con «Apri il piano», e nessun pulsante.
 *
 * Che cosa non copre: il riquadro a video (`LeRateDellaFattura.test.ts`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Il portone: la gestione straordinaria con la voce, legata alla delibera del 15/12/2026, e il piano di gennaio da € 9.000,00. @return array<string, mixed> */
function marPortone($t): array
{
    $s = ViaRoma12::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'anna')
        ->gestione('Portone 2026', 'straordinaria')->voce('Portone 2026', 'Sostituzione del portone', 1450000, ['Proprietà' => 100]);
    $g = $s->gestioni['Portone 2026']['g'];
    $voce = $s->voci['Sostituzione del portone']['conto'];
    $d = ViaRoma12::delibera($s, '2026-12-15', 'Portone: € 14.500,00 con Serramenti Marino');
    \App\Services\Assemblee\CollegamentiDelleDelibere::collega($g, 'delibera', $d, null, $s->admin);
    $piano = PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'nome' => 'Portone — rate', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 31, 'data_prima_scadenza' => '2026-12-31', 'metodo_distribuzione' => 'tutte_rate',
        'applica_saldi' => false, 'data_delibera_assemblea' => '2026-12-15']);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $voce->id, 'importo' => 900000, 'created_at' => now(), 'updated_at' => now()]);

    return ['s' => $s, 'g' => $g, 'voce' => $voce, 'd' => $d, 'piano' => $piano];
}

/** Una fattura di Serramenti Marino sulla gestione, con le righe date. @param list<array<string, mixed>> $righe */
function marFattura(array $x, string $numero, array $righe): FatturaPassiva
{
    $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Serramenti Marino', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(),
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $totale = (int) array_sum(array_column($righe, 'importo_imponibile'));
    $f = FatturaPassiva::create(['condominio_id' => $x['s']->c->id, 'fornitore_id' => $fornitore, 'esercizio_id' => $x['s']->e->id, 'gestione_id' => $x['g']->id,
        'tipo_documento' => 'fattura', 'numero_documento' => $numero, 'data_documento' => '2026-12-05', 'data_scadenza' => '2027-01-05', 'is_pregresso' => false,
        'importo_imponibile' => $totale, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => $totale, 'netto_a_pagare' => $totale,
        'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    foreach ($righe as $r) {
        DB::table('righe_fattura')->insert($r + ['fattura_passiva_id' => $f->id, 'immobile_id' => null, 'descrizione' => 'Saldo del portone', 'aliquota_iva' => 0,
            'importo_iva' => 0, 'is_sopravvenienza' => false, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $f->refresh();
}

it('catena · la fattura n. 88 → «Metti a rate questa spesa» → la scheda giusta, con la gestione, la voce e la delibera già scelte', function () {
    $x = marPortone($this);
    $s = $x['s'];
    $f = marFattura($x, '88', [['conto_id' => $x['voce']->id, 'importo_imponibile' => 550000]]);
    $attesa = route('admin.gestionale.esercizi.piani-rate.create', ['condominio' => $s->c->id, 'esercizio' => $s->e->id, 'gestione_id' => $x['g']->id,
        'conto_id' => $x['voce']->id, 'da_fattura' => $f->id, 'delibera_id' => $x['d']->id], false);

    $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.show', [$s->c, $f]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('mettere_a_rate.fuori', 550000)->where('mettere_a_rate.scheda', 'dalle_voci')
            ->where('mettere_a_rate.frase', '€ 5.500,00 ancora fuori dai piani della voce')->where('mettere_a_rate.url', $attesa)->where('mettere_a_rate.piano', null));
    // Dal menu della riga dell'elenco: la stessa scheda.
    $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.metti-a-rate', [$s->c, $f]))->assertRedirect($attesa);
    // La scheda giusta si apre con la delibera già scelta.
    $this->actingAs($s->admin)->get($attesa)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('deliberaProposta.id', $x['d']->id)
        // Vincenzo, 21/11/2026 sera: dal nuovo piano «indietro» tornava all'elenco, non alla fattura da cui si era partiti.
        ->where('ritorno.url', route('admin.gestionale.fatture.show', ['condominio' => $s->c->id, 'fattura' => $f->id], false))
        ->where('ritorno.testo', 'Torna alla fattura'));
});

it('una riga «Fuori preventivo» apre la scheda dello straordinario con la fattura nel carrello; la parte già in un piano non resta da mettere a rate', function () {
    $x = marPortone($this);
    $s = $x['s'];
    $f = marFattura($x, '91', [['conto_id' => $x['voce']->id, 'importo_imponibile' => 120000, 'is_sopravvenienza' => true]]);
    $spesa = SpesaDaMettereARate::perLaFattura($f, $s->admin);
    expect($spesa)->toMatchArray(['fuori' => 120000, 'scheda' => 'fuori_preventivo', 'frase' => '€ 1.200,00 ancora fuori dai piani'])
        ->and($spesa['url'])->toBe(route('admin.gestionale.esercizi.piani-rate.create', ['condominio' => $s->c->id, 'esercizio' => $s->e->id,
            'gestione_id' => $x['g']->id, 'tipo' => 'straordinario', 'fatture' => [$f->id], 'da_fattura' => $f->id, 'delibera_id' => $x['d']->id], false));

    // Collegata per intero a un piano: niente da mettere a rate, «Apri il piano».
    $straordinario = PianoRate::create(['gestione_id' => $x['g']->id, 'condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'nome' => 'Imprevisto del portone',
        'stato' => 'bozza', 'tipo' => 'straordinario', 'numero_rate' => 1, 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false]);
    DB::table('piano_rate_fatture')->insert(['piano_rate_id' => $straordinario->id, 'fattura_passiva_id' => $f->id, 'importo_collegato' => 120000,
        'created_at' => now(), 'updated_at' => now()]);
    $dopo = SpesaDaMettereARate::perLaFattura($f->fresh(), $s->admin);
    expect($dopo['fuori'])->toBe(0)->and($dopo['url'])->toBeNull()->and($dopo['piano']['nome'])->toBe('Imprevisto del portone');
});

it('con la voce tutta nei piani: «Nei piani della voce» con «Apri il piano», nessun pulsante; dal menu dell\'elenco la pagina della fattura lo dice', function () {
    $x = marPortone($this);
    $s = $x['s'];
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $x['piano']->id)->update(['importo' => 1450000]);
    $f = marFattura($x, '88', [['conto_id' => $x['voce']->id, 'importo_imponibile' => 550000]]);
    $spesa = SpesaDaMettereARate::perLaFattura($f, $s->admin);
    expect($spesa)->toMatchArray(['fuori' => 0, 'frase' => 'Nei piani della voce', 'url' => null])
        ->and($spesa['piano'])->toBe(['nome' => 'Portone — rate', 'url' => route('admin.gestionale.esercizi.piani-rate.show',
            ['condominio' => $s->c->id, 'esercizio' => $s->e->id, 'pianoRate' => $x['piano']->id], false)]);
    $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.metti-a-rate', [$s->c, $f]))
        ->assertRedirect(route('admin.gestionale.fatture.show', ['condominio' => $s->c->id, 'fattura' => $f->id]));

    // Una nota di credito non si mette a rate; senza «Gestisce le assemblee» la delibera non viaggia.
    $nota = marFattura($x, 'NC1', [['conto_id' => $x['voce']->id, 'importo_imponibile' => 10000]]);
    $nota->forceFill(['tipo_documento' => 'nota_credito'])->saveQuietly();
    expect(SpesaDaMettereARate::perLaFattura($nota->fresh(), $s->admin))->toBeNull();
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $x['piano']->id)->update(['importo' => 900000]);
    expect(SpesaDaMettereARate::perLaFattura($f->fresh(), ViaRoma12::utente([]))['url'])->not->toContain('delibera_id');
});

it('una voce che nel preventivo vale € 0: il riquadro non dice «Nei piani della voce»; dalla 1.12.0-beta.14 la spesa si mette a rate con «Spese fuori preventivo» (rilievo C24, Coda 742)', function () {
    $x = marPortone($this);
    $s = $x['s']->voce('Portone 2026', 'Manutenzione tetto', 0, ['Proprietà' => 100]);
    $tetto = $s->voci['Manutenzione tetto']['conto'];
    $f = marFattura($x, 'T1', [['conto_id' => $tetto->id, 'importo_imponibile' => 140000]]);
    $spesa = SpesaDaMettereARate::perLaFattura($f, $s->admin);
    // Nella beta.13 il riquadro diceva «questa spesa non è in nessun piano rate», senza pulsante; con la Coda 742 «Metti a rate» porta al carrello.
    expect($spesa)->toMatchArray(['fuori' => 140000, 'scheda' => 'fuori_preventivo', 'piano' => null,
        'frase' => 'La voce «Manutenzione tetto» non è nel preventivo: € 1.400,00 da mettere a rate'])
        ->and(urldecode((string) $spesa['url']))->toContain('tipo=straordinario')->toContain('fatture[0]=' . $f->id);
});

it('la fattura di un altro condominio: «Metti a rate questa spesa» risponde 404 e non porta a nessun piano', function () {
    $x = marPortone($this);
    $f = marFattura($x, '88', [['conto_id' => $x['voce']->id, 'importo_imponibile' => 550000]]);
    $altro = ViaRoma12::nuovo($this, 'Via Verdi 3');

    // Dalla rotta: le rotte annidate cercano la fattura dentro il condominio.
    $this->actingAs($altro->admin)->get(route('admin.gestionale.fatture.metti-a-rate', [$altro->c, $f]))->assertNotFound();
    // Il ritorno del nuovo piano porta solo a una fattura del suo condominio: una fattura di un altro condominio non dà nessun «Torna alla fattura».
    $this->actingAs($altro->admin)->get(route('admin.gestionale.esercizi.piani-rate.create', ['condominio' => $altro->c->id, 'esercizio' => $altro->e->id,
        'da_fattura' => $f->id]))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('ritorno', null));
    // E il controllore da solo, senza le rotte: la sua guardia.
    expect(fn () => app(\App\Http\Controllers\Gestionale\Movimenti\MettiARateController::class)(request(), $altro->c, $f))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});
