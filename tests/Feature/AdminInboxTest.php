<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Commento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Models\Evento;
use App\Models\Condominio;
use App\Services\CommentoEventoService;
use App\Enums\Permission;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseCount;

it('creates a single admin inbox event for a comment waiting for moderation, with the true numbers', function () {
    $user = User::factory()->create();
    $condominio = Condominio::factory()->create();

    $segnalazione = Segnalazione::factory()->create([
        'priority' => 'urgente',
        'stato' => 'chiusa',
        'condominio_id' => $condominio->id
    ]);

    $commento = Commento::factory()->create([
        'commentable_id' => $segnalazione->id,
        'commentable_type' => $segnalazione->getMorphClass(),
        'user_id' => $user->id,
        'corpo' => 'Questo è un test commento',
        'stato' => 'in_attesa',
        'condominio_id' => $condominio->id
    ]);

    $service = new CommentoEventoService();
    $service->sincronizza($commento);

    assertDatabaseHas('eventi', [
        'tipo' => 'da_moderare',
        'eventable_type' => $segnalazione->getMorphClass(),
        'eventable_id' => $segnalazione->id,
        'is_completed' => false,
    ]);

    $evento = Evento::where('tipo', 'da_moderare')->first();
    expect($evento->meta['commenti_in_attesa'])->toBe(1);
    expect($evento->title)->toBe('1 commento in attesa');
});

it('a comment published without moderation does not create an admin inbox event', function () {
    $user = User::factory()->create();
    $condominio = Condominio::factory()->create();
    $segnalazione = Segnalazione::factory()->create(['priority' => 'media', 'stato' => 'chiusa', 'condominio_id' => $condominio->id]);

    $commento = Commento::factory()->create([
        'commentable_id' => $segnalazione->id,
        'commentable_type' => $segnalazione->getMorphClass(),
        'user_id' => $user->id,
        'corpo' => 'Pubblicato subito',
        'stato' => 'pubblicato',
        'condominio_id' => $condominio->id
    ]);
    (new CommentoEventoService())->sincronizza($commento);

    expect(Evento::whereIn('tipo', ['commento', 'da_moderare'])->count())->toBe(0);
});

it('aggregates multiple comments into the same admin inbox event', function () {
    $user = User::factory()->create();
    $condominio = Condominio::factory()->create();

    $segnalazione = Segnalazione::factory()->create([
        'priority' => 'media',
        'stato' => 'chiusa',
        'condominio_id' => $condominio->id
    ]);

    $service = new CommentoEventoService();
    foreach (['Primo commento', 'Secondo commento'] as $corpo) {
        $commento = Commento::factory()->create([
            'commentable_id' => $segnalazione->id,
            'commentable_type' => $segnalazione->getMorphClass(),
            'user_id' => $user->id,
            'corpo' => $corpo,
            'stato' => 'in_attesa',
            'condominio_id' => $condominio->id
        ]);
        $service->sincronizza($commento);
    }

    expect(Evento::where('tipo', 'da_moderare')->count())->toBe(1);

    $evento = Evento::where('tipo', 'da_moderare')->first();
    expect($evento->meta['commenti_in_attesa'])->toBe(2);
    expect($evento->meta['contatore'])->toBe(2);
    expect($evento->title)->toBe('2 commenti in attesa');
});
