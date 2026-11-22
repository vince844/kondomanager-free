<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Evento;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679): i compiti della convocazione hanno l'assemblea in `meta.context.assemblea_id`, non come `eventable`, quindi eliminando
 * un'assemblea in preparazione si chiudono con la frase: nell'Action inbox non resta «Spedire la convocazione entro il 03/12» di un'assemblea che non c'è più.
 *
 * Che cosa non copre: l'assemblea convocata, che non si elimina ma si annulla (lo prova `RiconvocazioneTest`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('eliminando un\'assemblea in preparazione il compito «Spedire la convocazione» si chiude con la frase', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $compito = Evento::query()->where('meta->type', 'spedire_convocazione')->where('meta->context->assemblea_id', $a->id)->first();
    expect($compito)->not->toBeNull()->and($compito->is_completed)->toBeFalse();

    $this->actingAs($s->admin)->delete(route('admin.gestionale.assemblee.destroy', [$s->c, $a]))->assertRedirect();

    $compito->refresh();
    expect($compito->is_completed)->toBeTrue()
        ->and(Evento::query()->where('meta->type', 'spedire_convocazione')->where('meta->context->assemblea_id', $a->id)->where('is_completed', false)->count())->toBe(0);
});
