<?php

/**
 * # La scadenza del documento si controlla solo quando cambia (Coda 403)
 *
 * Nel modulo di modifica di un'anagrafica del pannello la scadenza del documento d'identità deve essere dopo oggi solo
 * quando chi salva la cambia, o la scrive dove non c'era. La data salvata, anche passata, si conserva com'è: una
 * persona con il documento scaduto, aperta e salvata senza toccare niente o cambiando soltanto il telefono, si salva.
 * Togliere la scadenza si può sempre. La creazione chiede una scadenza dopo oggi.
 *
 * La pagina (`resources/js/pages/anagrafiche/AnagraficheEdit.vue`) rimanda ogni campo come lo ha ricevuto, la data
 * compresa, se non la si tocca.
 *
 * Cosa NON copre: una data che non è una data (Coda 403, punto 2, nella 1.12); il portale (`user.anagrafiche.*`).
 */

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->mario = Anagrafica::create([
        'nome' => "Mario D'Àngelo", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'DNGMRA80A01H501U',
        'telefono' => '0612345678', 'cellulare' => '3331234567', 'email' => 'mario.dangelo@example.com',
        'tipologia_documento' => 'id_card', 'numero_documento' => 'CA12345XY', 'scadenza_documento' => '2024-03-31',
        'luogo_nascita' => 'Roma', 'data_nascita' => '1980-01-01', 'note' => "Nota con «virgolette» e l'apostrofo",
    ]);
    $this->mario->condomini()->attach($this->aurora->id);

    $this->chi = User::factory()->create(['email_verified_at' => now()]);
    $this->chi->assignRole(Role::COLLABORATORE->value);
});

/** Il corpo che manda `AnagraficheEdit.vue`: ogni campo del modulo dai dati che la pagina riceve, con le modifiche date. */
function scadenzaModulo($test, Anagrafica $anagrafica, array $modifiche = []): array
{
    $a = $test->actingAs($test->chi)->get(route('admin.anagrafiche.edit', $anagrafica))->assertOk()->viewData('page')['props']['anagrafica'];

    return array_merge([
        'nome' => $a['nome'], 'indirizzo' => $a['indirizzo'], 'codice_fiscale' => $a['codice_fiscale'],
        'luogo_nascita' => $a['luogo_nascita'], 'data_nascita' => $a['data_nascita'],
        'numero_documento' => $a['numero_documento'], 'tipologia_documento' => $a['tipologia_documento'],
        'scadenza_documento' => $a['scadenza_documento'],
        'email' => $a['email'], 'email_secondaria' => $a['email_secondaria'], 'pec' => $a['pec'],
        'telefono' => $a['telefono'], 'cellulare' => $a['cellulare'], 'note' => $a['note'],
        'condomini' => collect($a['condomini'] ?? [])->pluck('id')->all(),
    ], $modifiche);
}

/** La frase di `UpdateAnagraficaRequest` e `CreateAnagraficaRequest` per una scadenza che non è dopo oggi. */
function scadenzaMessaggio(): string
{
    return __('validation.custom.anagrafica.after:today', ['attribute' => __('validation.attributes.anagrafica.scadenza_documento')]);
}

function scadenzaSalva($test, Anagrafica $anagrafica, array $corpo)
{
    return $test->actingAs($test->chi)->from(route('admin.anagrafiche.edit', $anagrafica))->put(route('admin.anagrafiche.update', $anagrafica), $corpo);
}

test('Coda 403 — una persona con il documento scaduto, aperta e salvata senza toccare niente, si salva', function () {
    $corpo = scadenzaModulo($this, $this->mario);
    expect($corpo['scadenza_documento'])->toBe('2024-03-31');

    scadenzaSalva($this, $this->mario, $corpo)->assertSessionHasNoErrors()->assertRedirect(route('admin.anagrafiche.index'));

    $dopo = $this->mario->fresh();
    expect($dopo->scadenza_documento)->toBe('2024-03-31')
        ->and($dopo->note)->toBe("Nota con «virgolette» e l'apostrofo")
        ->and($dopo->data_nascita)->toBe('1980-01-01');
});

test('Coda 403 — una persona con il documento scaduto si salva cambiando soltanto il telefono', function () {
    scadenzaSalva($this, $this->mario, scadenzaModulo($this, $this->mario, ['telefono' => '0699999999']))
        ->assertSessionHasNoErrors();

    expect($this->mario->fresh()->telefono)->toBe('0699999999')
        ->and($this->mario->fresh()->scadenza_documento)->toBe('2024-03-31');
});

test('Coda 403 — la stessa data scritta in un\'altra forma non è una data cambiata', function () {
    scadenzaSalva($this, $this->mario, scadenzaModulo($this, $this->mario, ['scadenza_documento' => '2024-03-31T00:00:00.000000Z']))
        ->assertSessionHasNoErrors();

    expect($this->mario->fresh()->scadenza_documento)->toBe('2024-03-31');
});

test('Coda 403 — una scadenza cambiata deve essere dopo oggi: un\'altra data passata e oggi no, una data futura sì', function () {
    foreach (['un\'altra data passata' => '2025-01-15', 'oggi' => now()->toDateString()] as $caso => $data) {
        scadenzaSalva($this, $this->mario, scadenzaModulo($this, $this->mario, ['scadenza_documento' => $data]))
            ->assertRedirect(route('admin.anagrafiche.edit', $this->mario))
            ->assertSessionHasErrors(['scadenza_documento' => scadenzaMessaggio()]);
        expect($this->mario->fresh()->scadenza_documento, $caso)->toBe('2024-03-31');
    }

    $futura = now()->addYear()->toDateString();
    scadenzaSalva($this, $this->mario, scadenzaModulo($this, $this->mario, ['scadenza_documento' => $futura]))->assertSessionHasNoErrors();
    expect($this->mario->fresh()->scadenza_documento)->toBe($futura);
});

test('Coda 403 — una scadenza scritta per chi non l\'aveva deve essere dopo oggi', function () {
    $senza = Anagrafica::create(['nome' => 'Luca Verdi', 'indirizzo' => 'Via Po 2']);
    $senza->condomini()->attach($this->aurora->id);

    scadenzaSalva($this, $senza, scadenzaModulo($this, $senza, ['scadenza_documento' => '2024-03-31']))
        ->assertSessionHasErrors(['scadenza_documento' => scadenzaMessaggio()]);
    expect($senza->fresh()->scadenza_documento)->toBeNull();

    // Lasciata vuota com'era, si salva.
    scadenzaSalva($this, $senza, scadenzaModulo($this, $senza))->assertSessionHasNoErrors();
});

test('Coda 403 — togliere la scadenza del documento si può sempre', function () {
    scadenzaSalva($this, $this->mario, scadenzaModulo($this, $this->mario, ['scadenza_documento' => null]))->assertSessionHasNoErrors();

    expect($this->mario->fresh()->scadenza_documento)->toBeNull();
});

test('Coda 403 — la creazione chiede una scadenza dopo oggi', function () {
    $this->actingAs($this->chi)->from(route('admin.anagrafiche.create'))->post(route('admin.anagrafiche.store'), [
        'nome' => 'Nuova persona', 'indirizzo' => 'Via Nuova 3', 'scadenza_documento' => '2024-03-31', 'condomini' => [$this->aurora->id],
    ])->assertSessionHasErrors(['scadenza_documento' => scadenzaMessaggio()]);

    expect(Anagrafica::where('nome', 'Nuova persona')->exists())->toBeFalse();
});
