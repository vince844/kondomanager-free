<?php

use App\Models\Commento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Models\Evento;
use App\Models\Condominio;
use App\Services\CommentoEventoService;
use App\Enums\Permission;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseCount;

it('creates an admin inbox event when a new comment is posted', function () {
    $user = User::factory()->create();
    $condominio = Condominio::factory()->create();
    
    $segnalazione = Segnalazione::factory()->create([
        'priority' => 'urgente',
        'condominio_id' => $condominio->id
    ]);

    $commento = Commento::factory()->create([
        'commentable_id' => $segnalazione->id,
        'commentable_type' => $segnalazione->getMorphClass(),
        'user_id' => $user->id,
        'corpo' => 'Questo è un test commento',
        'stato' => 'pubblicato',
        'condominio_id' => $condominio->id
    ]);

    $service = new CommentoEventoService();
    $service->sincronizza($commento);

    assertDatabaseHas('eventi', [
        'tipo' => 'commento',
        'eventable_type' => $segnalazione->getMorphClass(),
        'eventable_id' => $segnalazione->id,
        'priorita' => 'alta',
        'is_completed' => false,
    ]);

    $evento = Evento::where('tipo', 'commento')->first();
    expect($evento->meta['contatore'])->toBe(1);
    expect($evento->meta['ultima_anteprima'])->toBe('Questo è un test commento');
});

it('aggregates multiple comments into the same admin inbox event', function () {
    $user = User::factory()->create();
    $condominio = Condominio::factory()->create();

    $segnalazione = Segnalazione::factory()->create([
        'priority' => 'media',
        'condominio_id' => $condominio->id
    ]);
    
    $service = new CommentoEventoService();

    // Commento 1
    $commento1 = Commento::factory()->create([
        'commentable_id' => $segnalazione->id,
        'commentable_type' => $segnalazione->getMorphClass(),
        'user_id' => $user->id,
        'corpo' => 'Primo commento',
        'condominio_id' => $condominio->id
    ]);
    $service->sincronizza($commento1);

    // Commento 2
    $commento2 = Commento::factory()->create([
        'commentable_id' => $segnalazione->id,
        'commentable_type' => $segnalazione->getMorphClass(),
        'user_id' => $user->id,
        'corpo' => 'Secondo commento',
        'condominio_id' => $condominio->id
    ]);
    $service->sincronizza($commento2);

    // Filter by type to exclude other potential events created by factories
    $eventiCount = Evento::where('tipo', 'commento')->count();
    expect($eventiCount)->toBe(1);

    $evento = Evento::where('tipo', 'commento')->first();
    expect($evento->meta['contatore'])->toBe(2);
    expect($evento->meta['ultima_anteprima'])->toBe('Secondo commento');
});
