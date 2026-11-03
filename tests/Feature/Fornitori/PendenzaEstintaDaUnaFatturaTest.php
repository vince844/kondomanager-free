<?php

/**
 * # Una pendenza del fornitore che una fattura pregressa estingue non si rimuove (Coda 409)
 *
 * La pagina dei debiti pregressi di un fornitore (`FornitoreSituazioneDebitoria.vue`) toglie una pendenza con «Elimina»
 * (`admin.fornitori.situazione-debitoria.destroy`). Una fattura pregressa registrata per estinguere quella pendenza la
 * nomina (`fatture_passive.saldo_patrimoniale_id`), e finché la fattura c'è la pendenza resta: la pagina dice quale
 * fattura la usa, con il messaggio d'errore della pagina, e niente cambia. Una pendenza che nessuna fattura usa si toglie.
 *
 * Cosa NON copre: i permessi della pagina (`FornitoriPermessiDelPannelloTest.php`); il pulsante, che manda il parametro
 * della rotta (`resources/js/pages/fornitori/FornitoreSituazioneDebitoria.test.ts`).
 */

use App\Enums\Role;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->condominio = Condominio::factory()->create();
    $this->esercizio = Esercizio::factory()->create([
        'condominio_id' => $this->condominio->id, 'stato' => 'aperto', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31',
    ]);
    $gestione = Gestione::create(['condominio_id' => $this->condominio->id, 'nome' => 'Ordinaria', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01']);
    $gestione->esercizi()->attach($this->esercizio->id, ['attiva' => true]);

    $this->fornitore = Fornitore::create([
        'ragione_sociale' => "Idraulica D'Àngelo S.r.l.", 'soggetto_ritenuta' => false, 'perc_imponibile_ritenuta' => 100,
        'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico',
    ]);

    $this->pendenza = Saldo::create([
        'condominio_id' => $this->condominio->id, 'esercizio_id' => $this->esercizio->id, 'gestione_id' => $gestione->id,
        'fornitore_id' => $this->fornitore->id, 'anagrafica_id' => null, 'immobile_id' => null, 'saldo_iniziale' => -38000,
        'descrizione' => 'Fattura idraulico 2025', 'origine' => 'manuale', 'is_applicato' => true,
    ]);
});

function pendenzaUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

/** Una fattura pregressa del fornitore che estingue la pendenza. */
function pendenzaFatturaCheLaEstingue($test, string $numero): void
{
    DB::table('fatture_passive')->insert([
        'condominio_id' => $test->condominio->id, 'fornitore_id' => $test->fornitore->id, 'esercizio_id' => $test->esercizio->id,
        'tipo_documento' => 'fattura', 'numero_documento' => $numero, 'data_documento' => '2025-11-30', 'data_scadenza' => '2025-12-31',
        'importo_imponibile' => 31148, 'importo_iva' => 6852, 'totale_documento' => 38000, 'netto_a_pagare' => 38000,
        'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'is_pregresso' => true,
        'saldo_patrimoniale_id' => $test->pendenza->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function pendenzaElimina($test, User $chi)
{
    return $test->actingAs($chi)
        ->from(route('admin.fornitori.situazione-debitoria.index', $test->fornitore))
        ->delete(route('admin.fornitori.situazione-debitoria.destroy', ['fornitore' => $test->fornitore->id, 'saldo' => $test->pendenza->id]));
}

test('Coda 409 — una pendenza che una fattura pregressa estingue resta, e la pagina dice quale fattura la usa', function (string $ruolo) {
    pendenzaFatturaCheLaEstingue($this, 'FT-2025/7');

    pendenzaElimina($this, pendenzaUtente($ruolo))
        ->assertRedirect(route('admin.fornitori.situazione-debitoria.index', $this->fornitore))
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'error'
            && $m['message'] === "Questa pendenza non si può rimuovere: la usa la fattura pregressa FT-2025/7, registrata per estinguerla. Finché la fattura c'è, la pendenza resta.");

    expect(Saldo::find($this->pendenza->id)?->saldo_iniziale)->toBe(-38000);
})->with([
    'l\'amministratore' => [Role::AMMINISTRATORE->value],
    'il collaboratore' => [Role::COLLABORATORE->value],
]);

test('Coda 409 — con due fatture pregresse sulla stessa pendenza la pagina le nomina tutte e due', function () {
    pendenzaFatturaCheLaEstingue($this, 'FT-2025/7');
    pendenzaFatturaCheLaEstingue($this, 'FT-2026/1');

    pendenzaElimina($this, pendenzaUtente(Role::AMMINISTRATORE->value))
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'error'
            && $m['message'] === 'Questa pendenza non si può rimuovere: la usano le fatture pregresse FT-2025/7, FT-2026/1, registrate per estinguerla. Finché le fatture ci sono, la pendenza resta.');

    expect(Saldo::whereKey($this->pendenza->id)->exists())->toBeTrue();
});

test('Coda 409 — una pendenza che nessuna fattura usa si toglie, anche se il fornitore ha altre fatture', function () {
    // Una fattura dello stesso fornitore che non estingue la pendenza.
    pendenzaFatturaCheLaEstingue($this, 'FT-2026/3');
    DB::table('fatture_passive')->where('numero_documento', 'FT-2026/3')->update(['saldo_patrimoniale_id' => null, 'is_pregresso' => false]);

    pendenzaElimina($this, pendenzaUtente(Role::COLLABORATORE->value))
        ->assertRedirect(route('admin.fornitori.situazione-debitoria.index', $this->fornitore))
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'success');

    expect(Saldo::whereKey($this->pendenza->id)->exists())->toBeFalse();
});
