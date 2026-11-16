<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il compito di una segnalazione che non c'è più. Eliminato il condominio, le sue segnalazioni se ne vanno con la chiave esterna, senza gli eventi del modello:
 * i loro compiti si chiudono, e non restano aperti per sempre con un collegamento che porta a una pagina che non c'è («Fatto» e «Non serve» non valgono per
 * una segnalazione). E alla lettura, comunque sia sparita la segnalazione, il suo compito non conta e non si mostra: né nell'Action inbox, né nel numero del
 * menu, né nell'Inbox operativa del condominio.
 */

use App\Models\Condominio;
use App\Models\Evento;
use App\Models\Segnalazione;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

function cnpSegnalazione($test, int $condominioId, string $titolo): Segnalazione
{
    return Segnalazione::create([
        'subject' => $titolo, 'description' => 'Da sistemare.', 'created_by' => $test->admin->id, 'condominio_id' => $condominioId,
        'priority' => 'media', 'stato' => 'aperta', 'is_published' => true, 'is_approved' => true, 'is_private' => false, 'can_comment' => true,
    ]);
}

function cnpCompito(Segnalazione $s): ?Evento
{
    return Evento::where('tipo', 'segnalazione_guasto')->where('eventable_id', $s->id)->first();
}

it('eliminato il condominio, il compito della sua segnalazione si chiude e lascia le due Inbox e il menu', function () {
    $altro = Condominio::factory()->create(['nome' => 'Condominio da togliere']);
    $s = cnpSegnalazione($this, $altro->id, 'Cancello rotto');
    cnpSegnalazione($this, $this->condominio->id, 'Citofono muto');
    expect(cnpCompito($s)->is_completed)->toBeFalse();

    $this->actingAs($this->admin)->delete(route('condomini.destroy', $altro))->assertSessionHasNoErrors();
    expect(Condominio::find($altro->id))->toBeNull()->and(Segnalazione::find($s->id))->toBeNull();

    $compito = cnpCompito($s);
    expect($compito)->not->toBeNull()
        ->and($compito->is_completed)->toBeTrue()
        ->and($compito->completed_at)->not->toBeNull();
    Cache::flush();
    $p = $this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props'];
    expect(collect($p['tasks']['data'])->pluck('title')->all())->toBe(['Citofono muto'])
        ->and($p['counts']['segnalazioni'])->toBe(1)
        ->and(icsBarra($this))->toBe(1)
        // L'altra segnalazione, di un condominio che c'è, resta com'era.
        ->and(icsInboxDelCondominio($this))->toBe(['Citofono muto']);
});

it('un compito di una segnalazione che non c\'è più non conta e non si mostra, anche se nessuno l\'ha chiuso', function () {
    $sparita = cnpSegnalazione($this, $this->condominio->id, 'Ascensore fermo');
    cnpSegnalazione($this, $this->condominio->id, 'Citofono muto');
    icsCompito($this, 'controllo_incassi', [], 'Verifica incassi - Rata 3');
    // Tolta senza passare dal modello, come fa una chiave esterna: il compito resta aperto nel database.
    DB::table('segnalazioni')->where('id', $sparita->id)->delete();
    expect(cnpCompito($sparita)->is_completed)->toBeFalse();
    Cache::flush();

    $p = $this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props'];
    expect(collect($p['tasks']['data'])->pluck('title')->sort()->values()->all())->toBe(['Citofono muto', 'Verifica incassi - Rata 3'])
        ->and($p['counts'])->toMatchArray(['all' => 2, 'segnalazioni' => 1])
        ->and(collect($p['dove'])->sum('valore'))->toBe(2)
        ->and(icsBarra($this))->toBe(2)
        ->and(icsInboxDelCondominio($this))->not->toContain('Ascensore fermo');
});
