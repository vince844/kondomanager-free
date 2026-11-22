<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Delibera;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Verbale\TerminiDellImpugnazione;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * I trenta giorni (V11; art. 1137 c. 2 c.c.): chi c'era (contrari, astenuti, chi non ha votato) dal giorno della delibera, ogni assente dal giorno in cui
 * riceve il verbale; per tutti, quando ognuno ha la sua data. Il conto nella lettura più prudente, finché l'art. 155 c.p.c. e la sospensione feriale non sono
 * riletti: il giorno dopo, trenta giorni senza agosto, e l'ultimo che cade di sabato, di domenica o in una festa passa al primo giorno lavorativo. «Segna
 * impugnata» non ferma niente.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('il conto prudente: trenta giorni dal giorno dopo; la domenica passa al lunedì; agosto non conta; la festa passa al giorno dopo', function () {
    $t = app(TerminiDellImpugnazione::class);
    expect($t->finoAl('2026-12-15'))->toBe('2027-01-14')      // giovedì
        ->and($t->finoAl('2026-12-18'))->toBe('2027-01-18')   // il 17/01/2027 è domenica: lunedì 18
        ->and($t->finoAl('2026-12-21'))->toBe('2027-01-20')
        ->and($t->finoAl('2027-07-20'))->toBe('2027-09-20')   // dal 21/07: 11 giorni a luglio, agosto fermo, 19 a settembre → domenica 19 → lunedì 20
        ->and($t->finoAl('2027-03-28'))->toBe('2027-04-27');  // il 27/04/2027: martedì; il 25/04 (festa) non c'entra
});

it('per ogni delibera chi può impugnare e fino a quando; «per tutti» solo quando ogni assente ha ricevuto il verbale', function () {
    [$s, $a, , $v] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $t = app(TerminiDellImpugnazione::class);
    $punto4 = collect($t->perIlVerbale($verbale->refresh()))->firstWhere('punto', 4);
    expect($punto4['per_tutti'])->toBeNull()
        ->and(collect($punto4['chi'])->firstWhere('nome', 'Colombo Rita'))->toMatchArray(['perche' => 'contrario', 'dal' => '2026-12-15', 'fino_al' => '2027-01-14'])
        ->and(collect($punto4['chi'])->firstWhere('nome', 'Condomino 35'))->toMatchArray(['perche' => 'astenuto', 'fino_al' => '2027-01-14'])
        ->and(collect($punto4['chi'])->firstWhere('nome', 'Condomino 36'))->toMatchArray(['perche' => 'assente', 'dal' => null, 'fino_al' => null])
        ->and(collect($punto4['chi'])->pluck('nome')->all())->not->toContain('Rossi Mario');

    // Ognuno riceve il verbale: la consegna più tarda il 21/12; i trenta giorni per tutti il 20/01/2027.
    foreach (app(\App\Services\Assemblee\Verbale\DestinatariDelVerbale::class)->per($verbale)['persone'] as $i => $p) {
        if ($p['riceve']) {
            RegistroInvio::create(['condominio_id' => $s->c->id, 'anagrafica_id' => $p['anagrafica_id'], 'tipo' => 'verbale', 'canale' => 'stampa',
                'documentabile_type' => $a->getMorphClass(), 'documentabile_id' => $a->id, 'dati' => ['mezzo' => 'raccomandata', 'verbale_id' => $verbale->id],
                'stato' => 'spedita', 'emesso_il' => now(), 'esito' => 'consegnata', 'ricevuta_il' => $i === 0 ? '2026-12-21' : '2026-12-17']);
        }
    }
    expect($t->perTuttiIlVerbale($verbale))->toBe('2027-01-20')
        ->and($t->perLaDelibera(Delibera::query()->findOrFail(\App\Models\Assemblee\VotazioneDelPunto::query()->findOrFail($v[4])->delibera_id))['per_tutti'])
        ->toBe('2027-01-20');
});

it('«Segna impugnata»: il giorno e la nota sulla delibera, l\'atto fra i documenti dell\'assemblea; non ferma niente', function () {
    [$s, $a, , $v] = VerbaleDiViaRoma12::salaChiusa($this);
    $delibera = Delibera::query()->findOrFail(\App\Models\Assemblee\VotazioneDelPunto::query()->findOrFail($v[4])->delibera_id);
    ConvocazioneDiViaRoma12::oggi('2027-01-10 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.delibere.impugnata', [$s->c, $a, $delibera]), ['giorno' => '2027-01-09',
        'nota' => 'Colombo Rita, citazione davanti al Tribunale di Milano.', 'atto' => VerbaleDiViaRoma12::copia('citazione.pdf')])->assertSessionHasNoErrors();
    expect($delibera->refresh()->impugnata_il->format('Y-m-d'))->toBe('2027-01-09')->and($delibera->impugnata_nota)->toContain('Tribunale di Milano')
        ->and($a->documenti()->where('name', 'citazione.pdf')->exists())->toBeTrue()->and($delibera->esito->value)->toBe('respinta');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.delibere.impugnata', [$s->c, $a, $delibera]), ['giorno' => '2026-12-01', 'nota' => 'x'])
        ->assertSessionHasErrors(['giorno']);
});
