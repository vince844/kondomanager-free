<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un compito è aperto con la regola delle due Inbox (`Evento::scopeCompitiAperti`): `meta.requires_action` vero e `is_completed` falso. Chi altro legge lo
 * stato di un compito usa la stessa regola.
 *
 * - La pulizia notturna dell'agenda (`model:prune`, `Evento::prunable()`) non toglie un compito aperto, anche se il suo giorno è di più di un anno fa: una
 *   segnalazione aperta (o in lavorazione) da più di un anno ha ancora il suo compito il giorno dopo. Il resto si pota come prima.
 * - L'agenda non mostra il promemoria a parte («Ricordamelo») di un compito che il programma ha chiuso da solo, anche quando lo chiude con
 *   `meta.requires_action` falso e non con la colonna (la sopravvenienza chiusa da `GeneratePianoRateAction`).
 */

use App\Models\Evento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

it('una segnalazione aperta da più di un anno resta un compito dell\'Inbox dopo la pulizia notturna dell\'agenda', function () {
    $this->travelTo(Carbon::parse('2025-03-01 10:00', 'Europe/Rome')->utc());
    $s = \App\Models\Segnalazione::create(['subject' => 'Infiltrazione dal tetto', 'description' => 'Macchia sul soffitto del quinto piano.', 'created_by' => $this->admin->id,
        'condominio_id' => $this->condominio->id, 'priority' => 'alta', 'stato' => 'in lavorazione', 'is_published' => true, 'is_approved' => true, 'is_private' => false,
        'can_comment' => true]);
    $this->travelTo(Carbon::parse('2026-05-20 10:00', 'Europe/Rome')->utc());
    expect(icsCasella($this))->toContain('Infiltrazione dal tetto');

    Artisan::call('model:prune', ['--model' => [Evento::class]]);

    expect(\App\Models\Segnalazione::find($s->id)->stato)->toBe('in lavorazione')
        ->and(icsCasella($this))->toContain('Infiltrazione dal tetto');
});

it('il controllo: la pulizia notturna toglie come prima un evento finito da più di un anno, un compito chiuso e uno che non chiede niente', function () {
    $this->travelTo(Carbon::parse('2025-03-01 10:00', 'Europe/Rome')->utc());
    $chiuso = icsCompito($this, 'scadenza', [], 'Rinnovo assicurazione 2025');
    Evento::whereKey($chiuso->id)->update(['is_completed' => true]);
    $senzaAzione = icsCompito($this, 'scadenza', [], 'Promemoria vecchio');
    $meta = $senzaAzione->fresh()->meta;
    $meta['requires_action'] = false;
    Evento::whereKey($senzaAzione->id)->update(['meta' => json_encode($meta)]);
    $senzaMeta = icsCompito($this, 'scadenza', [], 'Evento senza dati');
    Evento::whereKey($senzaMeta->id)->update(['meta' => null]);
    $senzaChiave = icsCompito($this, 'scadenza', [], 'Evento senza la chiave');
    $meta = $senzaChiave->fresh()->meta;
    unset($meta['requires_action']);
    Evento::whereKey($senzaChiave->id)->update(['meta' => json_encode($meta)]);
    $aperto = icsCompito($this, 'scadenza', [], 'Compito ancora aperto');
    DB::table('eventi')->whereIn('id', [$chiuso->id, $senzaAzione->id, $senzaMeta->id, $senzaChiave->id, $aperto->id])
        ->update(['start_time' => '2025-03-01 09:00:00', 'end_time' => '2025-03-01 10:00:00']);
    $this->travelTo(Carbon::parse('2026-05-20 10:00', 'Europe/Rome')->utc());

    Artisan::call('model:prune', ['--model' => [Evento::class]]);

    expect(Evento::whereIn('id', [$chiuso->id, $senzaAzione->id, $senzaMeta->id, $senzaChiave->id])->count())->toBe(0)
        ->and(Evento::whereKey($aperto->id)->exists())->toBeTrue();
});

it('il promemoria a parte di un compito che il programma ha chiuso da solo non si mostra nell\'agenda, anche quando lo chiude con `meta.requires_action`', function () {
    $compito = icsCompito($this, 'emissione_rata_sopravvenienza', ['context' => ['fattura_id' => 77]], 'Emettere Rate: Sopravvenienza Passiva (Condominio Aurora)');
    icsRispondi($this, $compito, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-27'])->assertSessionHasNoErrors();
    $promemoria = Evento::where('tipo', 'promemoria')->sole();
    $this->actingAs($this->admin);
    $agenda = fn () => collect(app(\App\Services\RecurrenceService::class)->getEventsInNextDays(days: 90, filters: [], page: 1, perPage: 50)->items())->pluck('id')->all();
    // Il controllo: con il compito aperto il promemoria è nell'agenda; chiuso dalla colonna (come chiude ogni altro punto del programma), non c'è.
    expect($agenda())->toContain($promemoria->id);
    Evento::whereKey($compito->id)->update(['is_completed' => true]);
    expect($agenda())->not->toContain($promemoria->id);
    Evento::whereKey($compito->id)->update(['is_completed' => false]);

    // La chiusura della sopravvenienza come la scrive `GeneratePianoRateAction`: `requires_action` falso e `is_completed` nel solo `meta`.
    $evento = Evento::whereKey($compito->id)->firstOrFail();
    $meta = $evento->meta;
    $meta['requires_action'] = false;
    $meta['is_completed'] = true;
    $evento->meta = $meta;
    $evento->save();
    expect(icsCasella($this))->not->toContain('Emettere Rate: Sopravvenienza Passiva (Condominio Aurora)');

    expect($agenda())->not->toContain($promemoria->id);
});
