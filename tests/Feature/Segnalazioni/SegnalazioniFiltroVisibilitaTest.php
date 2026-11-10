<?php

use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('amministratore');

    $this->condominio = Condominio::factory()->create();

    // 1. Pubblicata e approvata
    $this->pubblicata = Segnalazione::create([
        'condominio_id' => $this->condominio->id,
        'created_by' => $this->admin->id,
        'subject' => 'Segnalazione Pubblicata',
        'description' => 'Descrizione 1',
        'priority' => 'media',
        'stato' => 'aperta',
        'is_approved' => true,
        'is_published' => true,
        'is_private' => false,
    ]);

    // 2. Bozza (approvata ma non pubblicata)
    $this->bozza = Segnalazione::create([
        'condominio_id' => $this->condominio->id,
        'created_by' => $this->admin->id,
        'subject' => 'Segnalazione in Bozza',
        'description' => 'Descrizione 2',
        'priority' => 'media',
        'stato' => 'aperta',
        'is_approved' => true,
        'is_published' => false,
        'is_private' => false,
    ]);

    // 3. Da approvare (non approvata)
    $this->inAttesa = Segnalazione::create([
        'condominio_id' => $this->condominio->id,
        'created_by' => $this->admin->id,
        'subject' => 'Segnalazione Da Approvare',
        'description' => 'Descrizione 3',
        'priority' => 'media',
        'stato' => 'aperta',
        'is_approved' => false,
        'is_published' => true,
        'is_private' => false,
    ]);

    // 4. Privata
    $this->privata = Segnalazione::create([
        'condominio_id' => $this->condominio->id,
        'created_by' => $this->admin->id,
        'subject' => 'Segnalazione Privata',
        'description' => 'Descrizione 4',
        'priority' => 'media',
        'stato' => 'aperta',
        'is_approved' => true,
        'is_published' => true,
        'is_private' => true,
    ]);
});

test('il filtro visibilità filtra correttamente per da approvare, pubblicata, bozza e privata', function () {
    // Solo da approvare (pending)
    $resPending = $this->actingAs($this->admin)->get(route('admin.segnalazioni.index', [
        'visibility' => ['pending'],
    ]))->assertOk();
    $segnalazioni = collect($resPending->viewData('page')['props']['segnalazioni']);
    expect($segnalazioni->pluck('id'))->toContain($this->inAttesa->id)
        ->and($segnalazioni->pluck('id'))->not->toContain($this->pubblicata->id)
        ->and($segnalazioni->pluck('id'))->not->toContain($this->bozza->id);

    // Solo pubblicate (published)
    $resPublished = $this->actingAs($this->admin)->get(route('admin.segnalazioni.index', [
        'visibility' => ['published'],
    ]))->assertOk();
    $segnalazioniPub = collect($resPublished->viewData('page')['props']['segnalazioni']);
    expect($segnalazioniPub->pluck('id'))->toContain($this->pubblicata->id)
        ->and($segnalazioniPub->pluck('id'))->not->toContain($this->inAttesa->id)
        ->and($segnalazioniPub->pluck('id'))->not->toContain($this->bozza->id);

    // Solo bozza (draft)
    $resDraft = $this->actingAs($this->admin)->get(route('admin.segnalazioni.index', [
        'visibility' => ['draft'],
    ]))->assertOk();
    $segnalazioniDraft = collect($resDraft->viewData('page')['props']['segnalazioni']);
    expect($segnalazioniDraft->pluck('id'))->toContain($this->bozza->id)
        ->and($segnalazioniDraft->pluck('id'))->not->toContain($this->pubblicata->id)
        ->and($segnalazioniDraft->pluck('id'))->not->toContain($this->inAttesa->id);

    // Solo privata (private)
    $resPrivate = $this->actingAs($this->admin)->get(route('admin.segnalazioni.index', [
        'visibility' => ['private'],
    ]))->assertOk();
    $segnalazioniPriv = collect($resPrivate->viewData('page')['props']['segnalazioni']);
    expect($segnalazioniPriv->pluck('id'))->toContain($this->privata->id);
});
