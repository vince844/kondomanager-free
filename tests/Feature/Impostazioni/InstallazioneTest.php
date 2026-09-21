<?php

/*
 * «Questa installazione» (1.11.0-beta.33): la pagina dei fatti neutri. Il server decide cosa
 * mostrare: lo spazio solo con un tetto, il collegamento al piano solo con un indirizzo, la mappa
 * delle funzioni condivisa a ogni pagina.
 */

use App\Enums\Permission;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\User;
use App\Support\PersistenzaStorage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission as SpatiePermission;

function amministratoreImpostazioni(): User
{
    $user = User::factory()->create();
    SpatiePermission::firstOrCreate(['name' => Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']);
    $user->givePermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value);

    return $user;
}

afterEach(fn () => PersistenzaStorage::fingiStato(null));

it('senza tetti mostra i condomini come illimitati, niente spazio e niente piano', function () {
    PersistenzaStorage::fingiStato('effimeri');
    Condominio::factory()->count(2)->create(['is_demo' => false]);
    Condominio::factory()->create(['is_demo' => true]);

    $this->actingAs(amministratoreImpostazioni())
        ->get(route('impostazioni.installazione'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('impostazioni/impostazioniInstallazione')
            ->where('condomini', 2)
            ->where('condomini_dimostrativi', 1)
            ->where('limite_condomini', 0)
            ->where('spazio_usato_byte', null)
            ->where('limite_spazio_byte', null)
            ->where('gestione_piano_url', null)
            ->where('documenti_stato', 'effimeri')
            ->where('funzioni.aggiornamenti_in_app', false)
            ->where('funzioni.backup', true)
            ->where('funzioni.archiviazione_esterna', false)
            ->has('versione'));
});

it('con i tetti e l\'indirizzo del piano li mostra, e lo spazio è quello della colonna', function () {
    config([
        'kondomanager.limite_condomini' => 5,
        'kondomanager.limite_spazio_mb' => 10,
        'kondomanager.gestione_piano_url' => 'https://esempio.test/piano',
        'kondomanager.disco_documenti' => 'documenti_s3',
    ]);
    $autore = User::factory()->create();
    Documento::create(['name' => 'd', 'path' => 'documenti/x.pdf', 'mime_type' => 'application/pdf', 'file_size' => 4096, 'is_published' => true, 'is_approved' => true, 'created_by' => $autore->id]);

    $this->actingAs(amministratoreImpostazioni())
        ->get(route('impostazioni.installazione'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('limite_condomini', 5)
            ->where('limite_spazio_byte', 10 * 1024 * 1024)
            ->where('spazio_usato_byte', 4096)
            ->where('gestione_piano_url', 'https://esempio.test/piano')
            ->where('documenti_stato', 'esterni')
            ->where('funzioni.archiviazione_esterna', true));
});

it('senza il permesso sulle impostazioni generali la pagina è negata', function () {
    // I permessi devono esistere a tabella (come dopo il seeder): il test è sull'utente che non li ha.
    foreach (Permission::cases() as $permesso) {
        SpatiePermission::firstOrCreate(['name' => $permesso->value, 'guard_name' => 'web']);
    }

    $this->actingAs(User::factory()->create())
        ->get(route('impostazioni.installazione'))
        ->assertForbidden();
});

it('la mappa delle funzioni è condivisa a ogni pagina Inertia', function () {
    $this->actingAs(amministratoreImpostazioni())
        ->get(route('impostazioni'))
        ->assertInertia(fn (Assert $p) => $p
            ->has('funzioni.aggiornamenti_in_app')
            ->has('funzioni.pianificatore_esterno')
            ->has('funzioni.backup')
            ->has('funzioni.archiviazione_esterna'));
});
