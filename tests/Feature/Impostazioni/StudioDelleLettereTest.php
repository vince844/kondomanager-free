<?php

/**
 * I dati dello studio nelle impostazioni di stampa: il nome, l'indirizzo, l'email, la PEC e il telefono di chi scrive le lettere ai condòmini.
 * Si salvano dalla pagina delle impostazioni di stampa insieme alla nota legale; un'email che non è un'email si rifiuta; un campo svuotato
 * torna vuoto; una richiesta che non porta i campi dello studio li lascia com'erano.
 */

use App\Models\User;
use App\Settings\PrintSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']));
    $this->admin = User::factory()->create();
    $this->admin->assignRole($ruolo);
});

it('i dati dello studio si salvano con la nota legale, e la pagina li rilegge', function () {
    $this->actingAs($this->admin)->post(route('impostazioni.stampe.store'), [
        'nota_legale_stampe' => 'Nota', 'studio_nome' => 'Studio Bianchi', 'studio_indirizzo' => 'Via Verdi 3, Milano', 'studio_email' => 'studio@prova.it',
        'studio_pec' => 'studio@pec.it', 'studio_telefono' => '02 1234567',
    ])->assertSessionHasNoErrors();

    $s = app(PrintSettings::class)->refresh();
    expect([$s->studio_nome, $s->studio_indirizzo, $s->studio_email, $s->studio_pec, $s->studio_telefono])
        ->toBe(['Studio Bianchi', 'Via Verdi 3, Milano', 'studio@prova.it', 'studio@pec.it', '02 1234567']);
    $p = $this->actingAs($this->admin)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props'];
    expect($p['studio'])->toBe(['nome' => 'Studio Bianchi', 'indirizzo' => 'Via Verdi 3, Milano', 'email' => 'studio@prova.it', 'pec' => 'studio@pec.it', 'telefono' => '02 1234567', 'comune' => '',
        'partita_iva' => '', 'codice_fiscale' => '', 'sito' => '']);

    // Svuotato, il nome torna vuoto; senza i campi dello studio nella richiesta, gli altri restano.
    $this->actingAs($this->admin)->post(route('impostazioni.stampe.store'), ['nota_legale_stampe' => 'Nota', 'studio_nome' => ''])->assertSessionHasNoErrors();
    $s = app(PrintSettings::class)->refresh();
    expect($s->studio_nome)->toBeNull()->and($s->studio_pec)->toBe('studio@pec.it');
});

it('un\'email dello studio che non è un\'email si rifiuta', function () {
    $this->actingAs($this->admin)->post(route('impostazioni.stampe.store'), ['nota_legale_stampe' => 'Nota', 'studio_email' => 'non è un indirizzo'])
        ->assertSessionHasErrors('studio_email');
    expect(app(PrintSettings::class)->refresh()->studio_email)->toBeNull();
});
