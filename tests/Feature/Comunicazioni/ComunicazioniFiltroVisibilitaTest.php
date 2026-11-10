<?php

use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('amministratore');

    $this->condominio = Condominio::factory()->create();

    // 1. Pubblicata e approvata
    $this->pubblicata = Comunicazione::factory()->create([
        'created_by' => $this->admin->id,
        'subject' => 'Comunicazione Pubblicata',
        'priority' => 'media',
        'is_approved' => true,
        'is_published' => true,
        'is_private' => false,
    ]);
    $this->pubblicata->condomini()->attach($this->condominio->id);

    // 2. Bozza (approvata ma non pubblicata)
    $this->bozza = Comunicazione::factory()->create([
        'created_by' => $this->admin->id,
        'subject' => 'Comunicazione in Bozza',
        'priority' => 'media',
        'is_approved' => true,
        'is_published' => false,
        'is_private' => false,
    ]);
    $this->bozza->condomini()->attach($this->condominio->id);

    // 3. Da approvare (non approvata)
    $this->inAttesa = Comunicazione::factory()->create([
        'created_by' => $this->admin->id,
        'subject' => 'Comunicazione Da Approvare',
        'priority' => 'media',
        'is_approved' => false,
        'is_published' => true,
        'is_private' => false,
    ]);
    $this->inAttesa->condomini()->attach($this->condominio->id);

    // 4. Privata
    $this->privata = Comunicazione::factory()->create([
        'created_by' => $this->admin->id,
        'subject' => 'Comunicazione Privata',
        'priority' => 'media',
        'is_approved' => true,
        'is_published' => true,
        'is_private' => true,
    ]);
    $this->privata->condomini()->attach($this->condominio->id);
});

test('il filtro visibilità filtra correttamente per da approvare, pubblicata, bozza e privata', function () {
    // Solo da approvare (pending)
    $resPending = $this->actingAs($this->admin)->get(route('admin.comunicazioni.index', [
        'visibility' => ['pending'],
    ]))->assertOk();
    $comunicazioni = collect($resPending->viewData('page')['props']['comunicazioni']);
    expect($comunicazioni->pluck('id'))->toContain($this->inAttesa->id)
        ->and($comunicazioni->pluck('id'))->not->toContain($this->pubblicata->id)
        ->and($comunicazioni->pluck('id'))->not->toContain($this->bozza->id);

    // Solo pubblicate (published)
    $resPublished = $this->actingAs($this->admin)->get(route('admin.comunicazioni.index', [
        'visibility' => ['published'],
    ]))->assertOk();
    $comunicazioniPub = collect($resPublished->viewData('page')['props']['comunicazioni']);
    expect($comunicazioniPub->pluck('id'))->toContain($this->pubblicata->id)
        ->and($comunicazioniPub->pluck('id'))->not->toContain($this->inAttesa->id)
        ->and($comunicazioniPub->pluck('id'))->not->toContain($this->bozza->id);

    // Solo bozza (draft)
    $resDraft = $this->actingAs($this->admin)->get(route('admin.comunicazioni.index', [
        'visibility' => ['draft'],
    ]))->assertOk();
    $comunicazioniDraft = collect($resDraft->viewData('page')['props']['comunicazioni']);
    expect($comunicazioniDraft->pluck('id'))->toContain($this->bozza->id)
        ->and($comunicazioniDraft->pluck('id'))->not->toContain($this->pubblicata->id)
        ->and($comunicazioniDraft->pluck('id'))->not->toContain($this->inAttesa->id);

    // Solo privata (private)
    $resPrivate = $this->actingAs($this->admin)->get(route('admin.comunicazioni.index', [
        'visibility' => ['private'],
    ]))->assertOk();
    $comunicazioniPriv = collect($resPrivate->viewData('page')['props']['comunicazioni']);
    expect($comunicazioniPriv->pluck('id'))->toContain($this->privata->id);
});

