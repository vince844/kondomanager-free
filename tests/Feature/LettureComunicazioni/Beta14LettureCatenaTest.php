<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # La catena della conferma di lettura, da un capo all'altro, dalle rotte (Coda 594, decisioni 310–313)
 *
 * La comunicazione → il portale → la lettura → la scheda e l'elenco del pannello → le impostazioni → il registro delle modifiche.
 *
 * 1. Lo studio scrive dal pannello una comunicazione al palazzo Aurora con «Chiedi la presa visione»; le aperture si registrano (impostazione accesa).
 * 2. Mario la apre dal portale: l'apertura è registrata, e lui legge che lo studio la vede; preme «Confermo di aver letto».
 * 3. Paolo la apre e non conferma.
 * 4. Nella scheda del pannello «Presa visione di 1 su 4», «Aperta da 2 su 4», chi manca; nell'elenco «Aperture: 2» e «Prese visione: 1».
 * 5. Le impostazioni generali dicono «Aperture registrate: 2».
 * 6. Lo studio toglie la spunta dalla modifica: il registro delle modifiche ha la riga con la parola della pagina; la conferma di Mario resta scritta, a
 *    lui e allo studio.
 * 7. «Cancella le aperture registrate» dalle impostazioni: le aperture vanno, la presa visione di Mario resta; il portale non gli dice più che lo studio
 *    vede l'apertura.
 *
 * Che cosa non copre: la comunicazione programmata (si conta da quando esce: la prova `LetturePannelloTest`), il condòmino sospeso (`LetturePannelloTest`).
 */

use App\Models\Comunicazione;
use App\Models\RegistroModifica;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/LettureScenario.php';

beforeEach(function () {
    ContestoDelRegistro::azzera();
    lctPrepara($this);
    $this->withoutVite();
});

it('dalla spunta del pannello alla conferma nel portale, ai numeri della scheda e dell\'elenco, al registro e alla cancellazione delle aperture', function () {
    lctImpostazione(true);
    $modulo = [
        'subject' => 'Chiusura del cortile', 'description' => 'Dal 1° dicembre il cortile resta chiuso per i lavori al tetto.', 'priority' => 'media',
        'can_comment' => false, 'is_featured' => false, 'is_published' => true, 'condomini_ids' => [$this->aurora->id],
    ];

    // 1. Dal pannello, con la spunta.
    $this->actingAs($this->admin)->post(route('admin.comunicazioni.store'), $modulo + [LCT_COLONNA => true])->assertSessionHasNoErrors();
    $c = Comunicazione::query()->where('subject', 'Chiusura del cortile')->firstOrFail();
    expect(lctChiesta($c->id))->toBeTrue();

    // 2. Mario la apre dal portale e conferma.
    $lettura = lctLettura($this, $this->mario, $c);
    expect($lettura['apertura'])->toBe(['il' => '12/11/2026', 'alle' => '11:00'])
        ->and($lettura['puo_confermare'])->toBeTrue()
        ->and($lettura['presa_visione'])->toBeNull();
    $this->travel(1)->minutes();
    lctConferma($this, $this->mario, $c)->assertRedirect();
    $lettura = lctLettura($this, $this->mario, $c);
    expect($lettura['presa_visione'])->toBe(['il' => '12/11/2026', 'alle' => '11:01'])
        ->and($lettura['puo_confermare'])->toBeFalse()
        ->and($lettura['apertura'])->toBe(['il' => '12/11/2026', 'alle' => '11:00']);

    // 3. Paolo la apre e non conferma.
    lctApri($this, $this->paolo, $c)->assertOk();

    // 4. La scheda del pannello e l'elenco.
    $letture = lctLetture($this, $this->admin, $c);
    expect($letture['riassunto'])->toBe(['destinatari' => 4, 'senza_accesso' => 1, 'aperte' => 2, 'prese_visione' => 1])
        ->and(collect($letture['righe'])->where('manca', true)->pluck('nome')->values()->all())
        ->toBe(['Anna Collaboratrice', 'Giulia Senzaccount', 'Paolo Neri']);
    $riga = lctElenco(lctProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.index'))))[$c->id];
    expect($riga['letture'])->toBe(['aperte' => 2, 'prese_visione' => 1])
        ->and($riga['chiedi_presa_visione'])->toBeTrue();

    // 5. Le impostazioni generali.
    expect(lctProps($this->actingAs($this->admin)->get(route('impostazioni.generali')))['aperture_registrate'])->toBe(2);

    // 6. La spunta tolta dalla modifica: la riga del registro, e la conferma che resta.
    $prima = (int) RegistroModifica::query()->max('id');
    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), $modulo + [LCT_COLONNA => false])->assertSessionHasNoErrors();
    expect(lctChiesta($c->id))->toBeFalse();
    $campo = RegistroModifica::query()->where('id', '>', $prima)->where('oggetto_tipo', 'comunicazione')->where('oggetto_id', $c->id)->get()
        ->flatMap(fn ($r) => $r->campi)->firstWhere('chiave', LCT_COLONNA);
    expect($campo['etichetta'] . ': ' . $campo['prima'] . ' → ' . $campo['dopo'])->toBe('Chiedi la presa visione: sì → no');
    expect(lctLettura($this, $this->mario, $c)['presa_visione'])->toBe(['il' => '12/11/2026', 'alle' => '11:01'])
        ->and(lctLetture($this, $this->admin, $c)['riassunto']['prese_visione'])->toBe(1);

    // 7. «Cancella le aperture registrate»: le aperture vanno, la presa visione resta, e nessuna riga del registro delle letture resta vuota.
    lctImpostazione(false);
    $this->actingAs($this->admin)->delete(route(LCT_ROTTA_CANCELLA))->assertRedirect();
    expect(lctProps($this->actingAs($this->admin)->get(route('impostazioni.generali')))['aperture_registrate'])->toBe(0)
        ->and(lctRighe($c))->toHaveCount(1)
        ->and(lctRiga($c, $this->mario)->presa_visione_il)->not->toBeNull();
    $lettura = lctLettura($this, $this->mario, $c);
    expect($lettura['apertura'])->toBeNull()
        ->and($lettura['presa_visione'])->toBe(['il' => '12/11/2026', 'alle' => '11:01']);
    $riga = lctElenco(lctProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.index'))))[$c->id];
    expect($riga['letture'])->toBe(['aperte' => 0, 'prese_visione' => 1]);
});
