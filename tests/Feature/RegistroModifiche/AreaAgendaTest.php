<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\EventoTipo;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\RegistroModifica;
use App\Services\Gestionale\InboxService;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/../Dashboard/InboxCompitiScenario.php';

/**
 * 1.12.0-beta.13 (Coda 679, decisione 547, §5 del progetto): i gesti delle persone sull'agenda e sui compiti sono righe del registro, con il titolo copiato
 * nella riga, così la riga resta anche quando il programma pota il compito. Ciò che il programma crea o chiude da solo non si registra.
 *
 * Che cosa non copre: l'agenda personale del condòmino nel portale (fuori, decisione 555); i compiti del riquadro «Sei mesi per agire» e del rendiconto,
 * riapertura compresa (in `AreaAgendaRiquadroTest`); la potatura notturna (fuori per la decisione 547 (2)).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->withoutVite();
    icsInizia($this);
});

function aaDopo(int $prima)
{
    return RegistroModifica::query()->where('id', '>', $prima)->where('area', 'agenda')->orderBy('id')->get();
}

it('il compito creato dal programma non scrive; chiuso da una persona sì, con il titolo copiato', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;
    $compito = icsCompito($this, EventoTipo::CONTROLLO_INCASSI->value, [], 'Sollecitare Rossi');
    expect(aaDopo($prima))->toHaveCount(0);

    $this->actingAs($this->admin)->from(route('admin.inbox'))->patch(route('admin.inbox.complete', $compito))->assertSessionHas('success');

    $righe = aaDopo($prima);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->oggetto_nome)->toBe('Compito «Sollecitare Rossi»')
        ->and($righe[0]->riepilogo)->toBe('Compito chiuso: fatto')
        ->and($righe[0]->autore_nome)->toBe('Vincenzo')
        ->and($righe[0]->condomini())->toBe([$this->condominio->id => 'Condominio Aurora']);

    // Il programma pota il compito: la riga resta, con il titolo.
    Evento::query()->whereKey($compito->id)->delete();
    expect($righe[0]->fresh()->oggetto_nome)->toBe('Compito «Sollecitare Rossi»');
});

it('l\'appuntamento creato, spostato e cancellato da una persona: tre righe', function () {
    $categoria = CategoriaEvento::query()->firstOrFail();
    $prima = RegistroModifica::query()->max('id') ?? 0;
    $corpo = ['title' => 'Sopralluogo al tetto', 'description' => 'Con l\'impresa', 'start_time' => '2026-12-03 10:00:00', 'end_time' => '2026-12-03 11:00:00',
        'created_by' => $this->admin->id, 'category_id' => $categoria->id, 'condomini_ids' => [$this->condominio->id], 'visibility' => 'public',
        'is_approved' => true];

    $this->actingAs($this->admin)->post(route('admin.eventi.store'), $corpo)->assertSessionHasNoErrors();
    $evento = Evento::query()->where('title', 'Sopralluogo al tetto')->firstOrFail();
    $this->actingAs($this->admin)->put(route('admin.eventi.update', $evento), array_merge($corpo, ['mode' => 'all',
        'start_time' => '2026-12-05 10:00:00', 'end_time' => '2026-12-05 11:00:00']))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->delete(route('admin.eventi.destroy', $evento))->assertSessionHasNoErrors();

    $righe = aaDopo($prima);
    expect($righe->pluck('riepilogo')->all())->toBe(['Creato, per il 03/12/2026', 'Spostato: dal 03/12/2026 al 05/12/2026', 'Cancellato'])
        ->and($righe->pluck('oggetto_nome')->unique()->values()->all())->toBe(['Appuntamento «Sopralluogo al tetto»'])
        ->and(Evento::query()->whereKey($evento->id)->exists())->toBeFalse();
});
