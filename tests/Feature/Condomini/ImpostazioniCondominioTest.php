<?php

/**
 * Le impostazioni del condominio, nella scheda «Impostazioni» dell'area «Condominio»: un posto solo, una riga per condominio, che oggi tiene due scelte del
 * recupero dei crediti («Dopo una lettera, quando scadono rate nuove» e «La prossima azione»). Senza una riga valgono le scelte di serie (la salita va avanti,
 * il programma suggerisce la prossima azione), e aprire la pagina non scrive niente. Le cambia chi modifica il condominio; valgono solo per quel condominio.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Condominio;
use App\Models\ImpostazioniCondominio;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role as RuoloSpatie;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->condominio = Condominio::factory()->create(['nome' => 'Via Verdi 12']);
    $this->altro = Condominio::factory()->create(['nome' => 'Corso Roma 3']);
    $this->admin = User::factory()->create(['name' => 'Vincenzo', 'email_verified_at' => now()]);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);
});

function icnPagina($test, Condominio $c, ?User $chi = null): array
{
    return $test->actingAs($chi ?? $test->admin)->get(route('admin.gestionale.impostazioni.index', $c))->assertOk()->viewData('page');
}

function icnSalva($test, Condominio $c, array $dati, ?User $chi = null)
{
    return $test->actingAs($chi ?? $test->admin)->from(route('admin.gestionale.impostazioni.index', $c))->post(route('admin.gestionale.impostazioni.update', $c), $dati);
}

it('la pagina si apre con le scelte di serie e non scrive niente', function () {
    $pagina = icnPagina($this, $this->condominio);

    expect($pagina['component'])->toBe('gestionale/struttura/ImpostazioniCondominio')
        ->and($pagina['props']['impostazioni'])->toBe(['recupero_salita' => 'avanti', 'recupero_suggerisce_azione' => true])
        ->and($pagina['props']['puo_modificare'])->toBeTrue()
        ->and(ImpostazioniCondominio::count())->toBe(0)
        ->and(ImpostazioniCondominio::delCondominio((int) $this->condominio->id)->recupero_salita)->toBe('avanti')
        ->and(ImpostazioniCondominio::delCondominio((int) $this->condominio->id)->recupero_suggerisce_azione)->toBeTrue();
});

it('«Salva» scrive le due scelte per questo condominio, con chi; l\'altro condominio resta con le sue', function () {
    icnSalva($this, $this->condominio, ['recupero_salita' => 'ricomincia', 'recupero_suggerisce_azione' => false])->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('message.message', 'Impostazioni salvate: valgono solo per Via Verdi 12.');

    $riga = ImpostazioniCondominio::where('condominio_id', $this->condominio->id)->first();
    expect($riga->recupero_salita)->toBe('ricomincia')
        ->and($riga->recupero_suggerisce_azione)->toBeFalse()
        ->and($riga->aggiornate_da)->toBe($this->admin->id)
        ->and(icnPagina($this, $this->condominio)['props']['impostazioni'])->toBe(['recupero_salita' => 'ricomincia', 'recupero_suggerisce_azione' => false])
        ->and(icnPagina($this, $this->altro)['props']['impostazioni'])->toBe(['recupero_salita' => 'avanti', 'recupero_suggerisce_azione' => true]);

    // Una seconda volta la stessa riga: una per condominio.
    icnSalva($this, $this->condominio, ['recupero_salita' => 'avanti', 'recupero_suggerisce_azione' => true])->assertSessionHasNoErrors();
    expect(ImpostazioniCondominio::where('condominio_id', $this->condominio->id)->count())->toBe(1)
        ->and(ImpostazioniCondominio::delCondominio((int) $this->condominio->id)->recupero_salita)->toBe('avanti');
});

it('una scelta che non c\'è è un rifiuto con la sua frase, e non scrive niente', function () {
    icnSalva($this, $this->condominio, ['recupero_salita' => 'forse', 'recupero_suggerisce_azione' => true])
        ->assertSessionHasErrors(['recupero_salita' => 'Scegli che cosa succede dopo una lettera, quando scadono rate nuove.']);
    icnSalva($this, $this->condominio, ['recupero_salita' => 'avanti', 'recupero_suggerisce_azione' => 'forse'])
        ->assertSessionHasErrors(['recupero_suggerisce_azione' => 'Scegli chi sceglie la prossima azione.']);

    expect(ImpostazioniCondominio::count())->toBe(0);
});

it('chi non modifica il condominio vede la pagina ma non salva: 403', function () {
    RuoloSpatie::findOrCreate('Consultazione', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_CONDOMINI->value]);
    $chi = User::factory()->create(['email_verified_at' => now()]);
    $chi->assignRole('Consultazione');

    expect(icnPagina($this, $this->condominio, $chi->fresh())['props']['puo_modificare'])->toBeFalse();
    icnSalva($this, $this->condominio, ['recupero_salita' => 'ricomincia', 'recupero_suggerisce_azione' => false], $chi->fresh())->assertForbidden();
    expect(ImpostazioniCondominio::count())->toBe(0);
});

it('la riga se ne va con il condominio', function () {
    icnSalva($this, $this->condominio, ['recupero_salita' => 'ricomincia', 'recupero_suggerisce_azione' => false])->assertSessionHasNoErrors();
    \Illuminate\Support\Facades\DB::table('condomini')->where('id', $this->condominio->id)->delete();

    expect(ImpostazioniCondominio::count())->toBe(0);
});
