<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La migrazione dei dati del compito «da moderare» (decisioni 445 e 449): chiude i compiti di prima («Nuovo commento — …», «Allegati da approvare — …») e scrive
 * il compito unico di ogni elemento che ha qualcosa in attesa. Solo dati; rieseguita non cambia niente.
 */

use App\Enums\EventoTipo;
use App\Models\Evento;
use App\Services\Gestionale\InboxService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InboxCompitiScenario.php';
require_once __DIR__.'/ModerazioneScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

function mmMigrazione()
{
    return require database_path('migrations/2026_11_17_120000_crea_i_compiti_da_moderare.php');
}

it('chiude i compiti di prima e scrive il compito unico per ciò che aspetta, e rieseguita non cambia niente', function () {
    $s = mdSegnalazione($this, 'Ascensore fermo');
    $com = mdComunicazione($this, 'Avviso acqua');
    // Ciò che c'era prima di questa versione: commenti e allegati in attesa, e i compiti generici che dicevano «c'è qualcosa».
    $vecchi = [];
    foreach ([$s, $com] as $elemento) {
        // Nella discussione del palazzo, come li scrive il programma: un commento di una comunicazione senza palazzo né persona non sta in nessuna
        // discussione, e la scheda non lo mostra.
        DB::table('commenti')->insert(['commentable_type' => $elemento->getMorphClass(), 'commentable_id' => $elemento->getKey(), 'user_id' => $this->admin->id,
            'condominio_id' => $this->condominio->id, 'corpo' => 'Ciao', 'stato' => 'in_attesa', 'created_at' => '2026-05-10 09:00:00', 'updated_at' => '2026-05-10 09:00:00']);
        $vecchi[] = InboxService::createTask(tipo: EventoTipo::COMMENTO, title: 'Nuovo commento — ' . $elemento->subject, description: 'x', scadenza: now(),
            createdByUserId: $this->admin->id, condominioId: $this->condominio->id, eventableType: $elemento->getMorphClass(), eventableId: $elemento->getKey());
    }
    $vecchi[] = InboxService::createTask(tipo: EventoTipo::ALLEGATO, title: 'Allegati da approvare — Ascensore fermo', description: 'x', scadenza: now(),
        createdByUserId: $this->admin->id, condominioId: $this->condominio->id, eventableType: $s->getMorphClass(), eventableId: $s->getKey());
    DB::table('documenti')->insert(['name' => 'foto.jpg', 'path' => 'allegati/x.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 1, 'created_by' => $this->admin->id,
        'is_published' => 1, 'is_approved' => 0, 'documentable_type' => $s->getMorphClass(), 'documentable_id' => $s->getKey(), 'created_at' => '2026-05-12 09:00:00',
        'updated_at' => '2026-05-12 09:00:00']);
    $senzaAttese = mdSegnalazione($this, 'Cancello');
    $inutile = InboxService::createTask(tipo: EventoTipo::ALLEGATO, title: 'Allegati da approvare — Cancello', description: 'x', scadenza: now(),
        createdByUserId: $this->admin->id, eventableType: $senzaAttese->getMorphClass(), eventableId: $senzaAttese->getKey());
    expect(Evento::where('tipo', 'da_moderare')->count())->toBe(0);

    mmMigrazione()->up();

    $aperti = mdAperti();
    expect(collect($vecchi)->every(fn ($e) => $e->fresh()->is_completed))->toBeTrue()
        ->and($inutile->fresh()->is_completed)->toBeTrue()
        ->and($aperti)->toHaveCount(2)
        ->and($aperti->pluck('title')->sort()->values()->all())->toBe(['1 commento e 1 allegato in attesa', '1 commento in attesa'])
        ->and($aperti->first()->start_time->format('Y-m-d'))->toBe('2026-05-10');

    $prima = Evento::orderBy('id')->get(['id', 'title', 'is_completed', 'meta'])->toArray();
    mmMigrazione()->up();

    expect(Evento::orderBy('id')->get(['id', 'title', 'is_completed', 'meta'])->toArray())->toBe($prima);
});

it('chiude anche il compito di un elemento che non c\'è più', function () {
    $s = mdSegnalazione($this, 'Ascensore fermo');
    mdCommento($s, $this->admin);
    $compito = mdAperti()->sole();
    DB::table('segnalazioni')->where('id', $s->id)->delete();

    mmMigrazione()->up();

    expect($compito->fresh()->is_completed)->toBeTrue();
});
