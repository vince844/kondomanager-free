<?php

/**
 * Che cosa apre «Dai accesso al layout amministratore» di un ruolo su misura (decisione 138.2).
 *
 * ## Perché questo file esiste
 *
 * Le pagine «Crea nuovo ruolo» e «Modifica ruolo» (`resources/js/pages/ruoli/NuovoRuolo.vue`, `ModificaRuolo.vue`)
 * spiegano accanto alla casella che cosa dà l'accesso al pannello: alcune pagine del pannello non hanno permessi
 * propri e si aprono con il solo accesso, fra queste il gestionale di ogni condominio (contabilità, unità, titolari e
 * i loro dati) e i fornitori; altre sezioni, come comunicazioni, segnalazioni, agenda e l'elenco delle anagrafiche,
 * chiedono anche i loro permessi. Questo file fissa dalla rotta ogni pezzo della frase, con un ruolo creato come lo
 * manda la pagina: se una di queste pagine cambia regola, la frase va cambiata con lei.
 *
 * ## Cosa questo file NON copre
 *
 * La frase nelle due pagine (`resources/js/pages/ruoli/AccessoAlPannello.test.ts`); i permessi delle anagrafiche
 * azione per azione (`AnagrafichePermessiDelPannelloTest.php`); chi crea e modifica i ruoli
 * (`ChiPuoToccareChiTest.php`).
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Fornitore;
use App\Models\Immobile;
use App\Models\TipologiaImmobile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);
});

/** «Crea ruolo» come lo manda `NuovoRuolo.vue`: la casella dell'accesso al pannello e i permessi scelti (id). */
function apdCreaRuolo($test, User $amministratore, string $nome, array $permessi = []): Role
{
    $test->actingAs($amministratore)->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('ruoli.create'))
        ->post(route('ruoli.store'), [
            'name'        => $nome,
            'description' => 'Ruolo su misura',
            'permissions' => Permission::whereIn('name', array_map(fn (PermissionEnum $p) => $p->value, $permessi))->pluck('id')->all(),
            'accessAdmin' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('ruoli.index'));

    return Role::findByName($nome, 'web');
}

/** Un condominio con un'unità e la sua titolarità, e una persona di un altro condominio: i dati delle schede. */
function apdCondominio(): array
{
    $tipologia = TipologiaImmobile::firstOrCreate(['nome' => 'Appartamento'], ['categoria' => 'unita_abitativa']);
    $aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $altro = Condominio::factory()->create(['nome' => 'Condominio Borea']);

    $titolare = Anagrafica::factory()->create(['nome' => "Maria D'Àngelo", 'codice_fiscale' => 'DNGMRA80A41H501X']);
    $titolare->condomini()->attach($aurora->id);
    $altrove = Anagrafica::factory()->create(['nome' => 'Luca Verdi', 'codice_fiscale' => 'VRDLCU70B02F205X']);
    $altrove->condomini()->attach($altro->id);

    $unita = Immobile::create(['condominio_id' => $aurora->id, 'nome' => 'Int. 1', 'interno' => '1', 'descrizione' => 'Unità', 'tipologia_id' => $tipologia->id]);
    $unita->anagrafiche()->attach($titolare->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01']);
    $riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $titolare->id)->value('id');

    return [$aurora, $unita, $riga];
}

it('decisione 138 — con il solo accesso al pannello si aprono il gestionale di ogni condominio (contabilità, unità, titolari e i loro dati) e i fornitori', function () {
    $ruolo = apdCreaRuolo($this, $this->amministratore, 'Segreteria «Aurora»');
    expect($ruolo->permissions->pluck('name')->all())->toBe([PermissionEnum::ACCESS_ADMIN_PANEL->value]);

    $socio = User::factory()->create(['email_verified_at' => now()]);
    $socio->assignRole($ruolo);
    [$aurora, $unita, $riga] = apdCondominio();
    $fornitore = Fornitore::create(['ragione_sociale' => 'Impresa Aurora', 'partita_iva' => '01234567890']);

    // Il gestionale di un condominio che l'account non ha fra i suoi: la contabilità, le unità, i titolari.
    foreach ([
        route('admin.gestionale.index', [$aurora]),
        route('admin.gestionale.movimenti-rate.index', [$aurora]),
        route('admin.gestionale.fatture.index', [$aurora]),
        route('admin.gestionale.immobili.index', [$aurora]),
        route('admin.gestionale.immobili.anagrafiche.index', [$aurora, $unita]),
    ] as $pagina) {
        $this->actingAs($socio)->get($pagina)->assertOk();
    }
    $this->actingAs($socio)->get(route('admin.gestionale.immobili.anagrafiche.edit', [$aurora, $unita, $riga]))
        ->assertOk()
        ->assertSee('DNGMRA80A41H501X');

    // I fornitori.
    $this->actingAs($socio)->get(route('admin.fornitori.index'))->assertOk();
    $this->actingAs($socio)->get(route('admin.fornitori.show', $fornitore))->assertOk();
});

it('decisione 138 — comunicazioni, segnalazioni, agenda e l\'elenco delle anagrafiche chiedono anche i loro permessi', function () {
    $ruolo = apdCreaRuolo($this, $this->amministratore, 'Segreteria «Aurora»');
    $socio = User::factory()->create(['email_verified_at' => now()]);
    $socio->assignRole($ruolo);

    $sezioni = [
        'admin.comunicazioni.index' => PermissionEnum::VIEW_COMUNICAZIONI,
        'admin.segnalazioni.index'  => PermissionEnum::VIEW_SEGNALAZIONI,
        'admin.eventi.index'        => PermissionEnum::VIEW_EVENTS,
        'admin.anagrafiche.index'   => PermissionEnum::VIEW_ANAGRAFICHE,
    ];

    // Con il solo accesso al pannello: 403.
    foreach (array_keys($sezioni) as $pagina) {
        $this->actingAs($socio->fresh())->get(route($pagina))->assertForbidden();
    }

    // Lo stesso ruolo con i quattro permessi scelti in «Permessi» (come li manda `ModificaRuolo.vue`): si aprono.
    $this->actingAs($this->amministratore)->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('ruoli.edit', ['ruoli' => $ruolo->id]))
        ->put(route('ruoli.update', ['ruoli' => $ruolo->id]), [
            'name'        => 'Segreteria «Aurora»',
            'description' => 'Ruolo su misura',
            'permissions' => Permission::whereIn('name', array_map(fn (PermissionEnum $p) => $p->value, $sezioni))->pluck('id')->all(),
            'accessAdmin' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('ruoli.index'));

    foreach (array_keys($sezioni) as $pagina) {
        $this->actingAs($socio->fresh())->get(route($pagina))->assertOk();
    }
});
