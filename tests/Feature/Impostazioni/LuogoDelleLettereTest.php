<?php

/**
 * Il luogo in testa alle lettere e agli avvisi di compensazione («Milano, 20/03/2026»): il comune dello studio scritto nelle impostazioni di
 * stampa; senza, quello del condominio. Lo sceglie l'amministratore, e si vede nelle impostazioni. La ripresa della migrazione
 * dell'impostazione è in `UpgradeMigrationsRerunTest`.
 */

use App\Models\User;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Settings\PrintSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Gestionale/LettereScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']));
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Il luogo e la data in testa alla lettera stampata per Ugo. */
function ldlLuogoDellaLettera($test, array $s): string
{
    $dati = null;
    View::composer('pdf.gestionale.lettera', function ($vista) use (&$dati) {
        $dati = $vista->getData();
    });
    $test->actingAs($test->user)->post(lttRotta($s, 'pdf-anteprima'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id]]))->assertOk();

    return (string) $dati['lettere'][0]['luogo'];
}

it('il comune dello studio si salva nelle impostazioni di stampa, e la pagina lo rilegge', function () {
    $this->actingAs($this->user)->post(route('impostazioni.stampe.store'), ['nota_legale_stampe' => 'Nota', 'studio_comune' => 'Milano'])->assertSessionHasNoErrors();
    expect(app(PrintSettings::class)->refresh()->studio_comune)->toBe('Milano')
        ->and($this->actingAs($this->user)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props']['studio']['comune'])->toBe('Milano');
});

it('la lettera porta il comune dello studio; senza, quello del condominio', function () {
    $s = lttScenario($this);
    $s['c']->update(['comune' => 'Bergamo']);
    expect(ldlLuogoDellaLettera($this, $s))->toBe('Bergamo');

    $impostazioni = app(PrintSettings::class);
    $impostazioni->studio_comune = 'Milano';
    $impostazioni->save();
    expect(ldlLuogoDellaLettera($this, $s))->toBe('Milano')
        ->and(EmissioneLettere::studio()['comune'])->toBe('Milano');
});
