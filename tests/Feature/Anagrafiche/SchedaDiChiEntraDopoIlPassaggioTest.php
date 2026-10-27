<?php

/**
 * # «Scheda di chi entra» dopo un passaggio: solo a chi può modificare le anagrafiche (decisioni 137 e 138.1)
 *
 * Registrato un passaggio, il riquadro verde dell'elenco titolari offre l'estratto conto di chi entra e la sua scheda:
 * il collegamento della scheda apre il modulo di modifica dell'anagrafica (`admin.anagrafiche.edit`), dove si
 * completano codice fiscale e recapiti per il registro dell'anagrafe condominiale. Il modulo chiede «Modifica
 * anagrafiche» (`AnagraficaPolicy::update`): il server manda il collegamento solo a chi lo ha, e la pagina, senza
 * collegamento, non mostra né il pulsante né la frase che lo accompagna.
 *
 * Cosa NON copre: il passaggio in sé (`RegistraSubentroTest`); le altre pagine del pannello.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as RuoloSpatie;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->condominio = Condominio::factory()->create();
    $esercizio = Esercizio::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $gestione = Gestione::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria']);
    legaAEsercizio($esercizio, $gestione->id);
    $this->immobile = Immobile::forceCreate(['condominio_id' => $this->condominio->id, 'nome' => 'Interno 3', 'descrizione' => 'Appartamento', 'interno' => '3']);

    $this->rossi = Anagrafica::forceCreate(['nome' => 'Rossi Mario', 'indirizzo' => 'Via Verdi 1', 'email' => 'rossi@example.com']);
    $this->bianchi = Anagrafica::forceCreate(['nome' => 'Bianchi Anna', 'indirizzo' => 'Via Verdi 2', 'email' => 'bianchi@example.com']);
    $this->rossi->condomini()->attach($this->condominio->id);
    $this->bianchi->condomini()->attach($this->condominio->id);
    $this->rigaRossi = DB::table('anagrafica_immobile')->insertGetId([
        'immobile_id' => $this->immobile->id, 'anagrafica_id' => $this->rossi->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
        'data_inizio' => '2019-03-03', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

/** Registra la vendita da Rossi a Bianchi come la manda la pagina, e restituisce i collegamenti del riquadro verde. */
function schedaDopoPassaggioAzioni(User $chi): array
{
    $risposta = test()->actingAs($chi)->post(route('admin.gestionale.immobili.passaggi.store', [test()->condominio, test()->immobile]), [
        'tipo' => 'vendita', 'riga_uscente_id' => test()->rigaRossi, 'anagrafica_entrante_id' => test()->bianchi->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06',
        'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => false, 'nota_cancello' => '',
    ]);
    $risposta->assertSessionHasNoErrors()->assertRedirect(route('admin.gestionale.immobili.anagrafiche.index', [test()->condominio, test()->immobile]));

    return $risposta->getSession()->get('passaggio_registrato')['azioni'];
}

function schedaDopoPassaggioUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

test('decisione 138 — dopo un passaggio l\'amministratore e il collaboratore di serie ricevono «Scheda di chi entra», che apre il modulo di modifica', function (string $ruolo) {
    $chi = schedaDopoPassaggioUtente($ruolo);
    $azioni = schedaDopoPassaggioAzioni($chi);

    expect($azioni['anagrafe'])->toBe(route('admin.anagrafiche.edit', ['anagrafica' => $this->bianchi->id]))
        ->and($azioni['estratto_conto'])->not->toBeNull();
    $this->actingAs($chi)->get($azioni['anagrafe'])->assertOk();
})->with([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value]);

test('decisione 138 — dopo un passaggio chi non ha «Modifica anagrafiche» non riceve il collegamento al modulo di modifica, e l\'estratto conto resta', function () {
    RuoloSpatie::findOrCreate('Contabilità', 'web')->syncPermissions([
        Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_ANAGRAFICHE->value,
    ]);
    $chi = schedaDopoPassaggioUtente('Contabilità');
    $azioni = schedaDopoPassaggioAzioni($chi);

    expect($azioni['anagrafe'])->toBeNull()
        ->and($azioni['estratto_conto'])->toBe(route('admin.gestionale.anagrafiche.estratto-conto', ['condominio' => $this->condominio->id, 'anagrafica' => $this->bianchi->id]));
    // Il modulo, aperto lo stesso, risponde ancora con il rifiuto della policy.
    $this->actingAs($chi)->get(route('admin.anagrafiche.edit', ['anagrafica' => $this->bianchi->id]))->assertForbidden();
});

test('decisione 138 — con «Modifica anagrafiche» senza la consultazione il collegamento c\'è: porta al modulo, che chiede solo quel permesso', function () {
    RuoloSpatie::findOrCreate('Rubrica', 'web')->syncPermissions([
        Permission::ACCESS_ADMIN_PANEL->value, Permission::EDIT_ANAGRAFICHE->value,
    ]);
    $chi = schedaDopoPassaggioUtente('Rubrica');
    $azioni = schedaDopoPassaggioAzioni($chi);

    expect($azioni['anagrafe'])->toBe(route('admin.anagrafiche.edit', ['anagrafica' => $this->bianchi->id]));
    $this->actingAs($chi)->get($azioni['anagrafe'])->assertOk();
});

test('decisione 138 — la persona nuova creata dal modulo del passaggio segue la porta del gestionale: con il solo accesso al pannello si crea, come prima', function () {
    RuoloSpatie::findOrCreate('Solo pannello', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value]);
    $chi = schedaDopoPassaggioUtente('Solo pannello');

    $this->actingAs($chi)->postJson(route('admin.gestionale.immobili.passaggi.anagrafica', [$this->condominio, $this->immobile]), [
        'nome' => 'Verdi Luca', 'codice_fiscale' => null, 'indirizzo' => 'Via Verdi 3', 'email' => null,
    ])->assertCreated()->assertJson(['nome' => 'Verdi Luca']);

    expect(Anagrafica::where('nome', 'Verdi Luca')->first()?->condomini->pluck('id')->all())->toBe([$this->condominio->id]);
    // Le regole sono quelle dell'anagrafica del pannello: un codice fiscale già usato si ferma.
    Anagrafica::whereKey($this->rossi->id)->update(['codice_fiscale' => 'RSSMRA80A01H501U']);
    $this->actingAs($chi)->postJson(route('admin.gestionale.immobili.passaggi.anagrafica', [$this->condominio, $this->immobile]), [
        'nome' => 'Altro', 'codice_fiscale' => 'RSSMRA80A01H501U', 'indirizzo' => 'Via Verdi 4', 'email' => null,
    ])->assertUnprocessable()->assertJsonValidationErrors('codice_fiscale');
});

test('decisione 138 — i permessi delle anagrafiche che mancano dal database valgono «no»: il passaggio si registra, il collegamento non c\'è, le pagine rispondono 403', function () {
    // La domanda sul collegamento si fa dopo la scrittura del passaggio: non deve poter fermare la risposta.
    RuoloSpatie::findOrCreate('Solo pannello', 'web')->syncPermissions([Permission::ACCESS_ADMIN_PANEL->value]);
    \Spatie\Permission\Models\Permission::whereIn('name', [
        Permission::VIEW_ANAGRAFICHE->value, Permission::CREATE_ANAGRAFICHE->value, Permission::EDIT_ANAGRAFICHE->value, Permission::DELETE_ANAGRAFICHE->value,
    ])->delete();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $chi = schedaDopoPassaggioUtente('Solo pannello');

    $azioni = schedaDopoPassaggioAzioni($chi);

    expect($azioni['anagrafe'])->toBeNull()
        ->and(\App\Models\Gestionale\Subentro::count())->toBe(1);
    $eliminabile = Anagrafica::forceCreate(['nome' => 'Da eliminare', 'indirizzo' => 'Via Verdi 9']);
    $this->actingAs($chi)->get(route('admin.anagrafiche.index'))->assertForbidden();
    $this->actingAs($chi)->get(route('admin.anagrafiche.show', ['anagrafica' => $this->bianchi->id]))->assertForbidden();
    $this->actingAs($chi)->get(route('admin.anagrafiche.create'))->assertForbidden();
    $this->actingAs($chi)->get(route('admin.anagrafiche.edit', ['anagrafica' => $this->bianchi->id]))->assertForbidden();
    $this->actingAs($chi)->delete(route('admin.anagrafiche.destroy', ['anagrafica' => $eliminabile->id]))->assertForbidden();
    expect(Anagrafica::whereKey($eliminabile->id)->exists())->toBeTrue();
});
