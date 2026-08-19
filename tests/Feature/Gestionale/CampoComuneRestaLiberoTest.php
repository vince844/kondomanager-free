<?php

/**
 * Il campo del Comune resta libero: l'elenco è un aiuto, non un obbligo.
 *
 * ## Perché questo file esiste
 *
 * È il presidio della decisione presa con Vincenzo il 18/08/2026, e la ragione è scritta nella coda
 * ㊹: l'elenco ISTAT **non è una tabella immobile**. I Comuni si fondono, cambiano nome, qualcuno
 * cambia codice. Caricato una volta e dimenticato, fra due anni suggerisce codici di comuni che non
 * esistono più — e chi si trovasse il campo bloccato su una tendina non potrebbe nemmeno scrivere
 * quello giusto.
 *
 * Un difetto della beta.58 dice la stessa cosa dall'altro lato: là un campo pretendeva un dato che
 * non serviva; qui il rischio opposto è che un campo **rifiuti** un dato vero perché non sta in un
 * elenco. Il primo è stato corretto; il secondo non va introdotto.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la ricerca (è in `RicercaComuniTest`), né il caricamento dell'elenco (in
 * `ElencoComuniTest`), né la resa a video del pulsante.
 */

use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    // `EDIT_CONDOMINI` serve al `Gate::authorize('update', ...)` della schermata del condominio.
    // Senza, la richiesta muore con un 403 — e `assertSessionHasNoErrors()` passerebbe lo stesso,
    // perché un 403 non è un errore di validazione. È il falso verde della beta.58.
    // `VIEW_CONDOMINI` è il filtro del **middleware di rotta**, `EDIT_CONDOMINI` quello del Gate nel
    // controller: sono due sbarramenti distinti e servono entrambi.
    foreach ([
        'Accesso pannello amministratore',
        \App\Enums\Permission::VIEW_CONDOMINI->value,
        \App\Enums\Permission::EDIT_CONDOMINI->value,
    ] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    // La factory lascia il codice fiscale nullo e la request lo pretende: senza, il test
    // fallirebbe per una ragione che non c'entra con quello che prova.
    $this->condominio = Condominio::factory()->create(['codice_fiscale' => '97123456789']);
    \App\Models\Esercizio::factory()->create(['condominio_id' => $this->condominio->id]);
    \App\Models\Gestionale\PianoConto::factory()->create(['condominio_id' => $this->condominio->id]);
});

it('un\'unità si salva con un comune che non sta nell\'elenco', function () {
    // Il caso vero: un comune fuso da poco, o scritto come lo scrive il catasto. Se il programma lo
    // rifiutasse, l'aiuto sarebbe diventato un ostacolo.
    $tipologiaId = DB::table('tipologie_immobili')->value('id')
        ?? DB::table('tipologie_immobili')->insertGetId([
            'nome' => 'Appartamento', 'categoria' => 'A/2',
            'created_at' => now(), 'updated_at' => now(),
        ]);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]), [
            'nome'           => 'Appartamento 1',
            'condominio_id'  => $this->condominio->id,
            'tipologia_id'   => $tipologiaId,
            'created_by'     => $this->user->id,
            'comune_catasto' => 'Comune Che Non Esiste In Elenco',
            'codice_catasto' => 'Z999',
        ])
        ->assertSessionHasNoErrors();

    expect(Immobile::where('condominio_id', $this->condominio->id)->value('comune_catasto'))
        ->toBe('Comune Che Non Esiste In Elenco');
});

it('il codice catastale non diventa obbligatorio perché adesso c\'è un elenco da cui pescarlo', function () {
    $tipologiaId = DB::table('tipologie_immobili')->value('id')
        ?? DB::table('tipologie_immobili')->insertGetId([
            'nome' => 'Appartamento', 'categoria' => 'A/2',
            'created_at' => now(), 'updated_at' => now(),
        ]);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.immobili.store', ['condominio' => $this->condominio->id]), [
            'nome'          => 'Appartamento 2',
            'condominio_id' => $this->condominio->id,
            'tipologia_id'  => $tipologiaId,
            'created_by'    => $this->user->id,
        ])
        ->assertSessionHasNoErrors();
});

it('il condominio si salva con un comune fuori elenco allo stesso modo', function () {
    $this->actingAs($this->user)
        ->patch(route('condomini.update', ['condominio' => $this->condominio->id]), [
            'nome'           => $this->condominio->nome,
            'codice_fiscale' => $this->condominio->codice_fiscale,
            'comune_catasto' => 'Comune Fuso Nel 2027',
            'codice_catasto' => 'Z998',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('condomini.index'));

    expect($this->condominio->fresh()->comune_catasto)->toBe('Comune Fuso Nel 2027');
});
