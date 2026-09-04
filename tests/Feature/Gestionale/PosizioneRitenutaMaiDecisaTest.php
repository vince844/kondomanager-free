<?php

use App\Models\Fornitore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/GestionaleTestHelpers.php';

/**
 * «No» e «non gliel'ha mai chiesto nessuno» sono due cose diverse (Coda 116).
 *
 * ## Il difetto
 *
 * ⚠️ **L'assenza del blocco `<DatiRitenuta>` in una fattura non significa «nessuna ritenuta».**
 * L'obbligo è del condominio come sostituto d'imposta, non del fornitore che lo dichiara in
 * fattura. **Sei degli undici XML veri** non hanno quel blocco, e uno dei sei è un geometra —
 * cassa previdenziale TC03, cedente persona fisica — su cui il 20% è dovuto: quel documento si
 * registrava a netto pieno, in silenzio, e all'Erario non arrivava niente.
 *
 * È l'unico buco dell'importazione XML che **non si chiude leggendo meglio il file**: nessun
 * campo può rispondere. La domanda va fatta a chi amministra, **una volta sola**, e la risposta
 * va ricordata — anche quando è «no», che senza `ritenuta_decisa_il` sarebbe indistinguibile da
 * un silenzio.
 */
beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Un fornitore su cui nessuno si è mai pronunciato: nessun campo, nessuna data. */
function fornitoreMaiClassificato(): Fornitore
{
    return Fornitore::create([
        'ragione_sociale' => 'Bianchi Marco',
        'soggetto_ritenuta' => false,
        'perc_imponibile_ritenuta' => 100,
        'giorni_scadenza' => 30,
        'modalita_pagamento_default' => 'bonifico',
        'ritenuta_decisa_il' => null,
    ]);
}

function payloadFattura(array $ctx, Fornitore $fornitore, array $extra = []): array
{
    [$condominio, $esercizio, $gestione, , $capitolo] = $ctx;

    return array_merge([
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_documento' => 'fattura',
        'numero_documento' => 'FT-116-'.uniqid(),
        'data_documento' => '2026-05-10',
        'data_scadenza' => '2026-06-09',
        'modalita_pagamento' => 'bonifico',
        'stato_approvazione' => 'approvata',
        'righe' => [[
            'descrizione' => 'Prestazione professionale',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'importo_iva' => 220,
            'conto_id' => $capitolo->id,
        ]],
    ], $extra);
}

test('un fornitore mai classificato non passa senza una risposta', function () {
    $ctx = setupContabile();
    $fornitore = fornitoreMaiClassificato();

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.store', $ctx[0]), payloadFattura($ctx, $fornitore))
        ->assertSessionHasErrors('posizione_ritenuta.soggetto');
});

test('il rifiuto nomina il fornitore e dice perché la fattura non può rispondere', function () {
    // ⚠️ «Completa l'anagrafica» non basta: chi legge deve capire **perché** gli si chiede una
    // cosa che il documento sembrerebbe già dire. La frase deve contenere il fatto di dominio.
    $ctx = setupContabile();
    $fornitore = fornitoreMaiClassificato();

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.store', $ctx[0]), payloadFattura($ctx, $fornitore));

    $errori = session('errors')->get('posizione_ritenuta.soggetto');

    expect($errori[0])
        ->toContain('Bianchi Marco')
        ->toContain('una volta sola');
});

test('rispondendo «sì, lavoro autonomo» la ritenuta è trattenuta su QUESTA fattura', function () {
    // ⚠️ È il punto che rende utile la domanda invece che pedante. La risposta si applica
    // **prima** della registrazione: applicarla dopo vorrebbe dire salvare questo documento
    // con la posizione vecchia e correggere l'anagrafica per la prossima volta — cioè perdere
    // proprio la fattura per cui la domanda è stata fatta.
    $ctx = setupContabile();
    $fornitore = fornitoreMaiClassificato();

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.store', $ctx[0]), payloadFattura($ctx, $fornitore, [
            'posizione_ritenuta' => ['soggetto' => true, 'tipo' => 'lavoro_autonomo_20'],
        ]))
        ->assertSessionHasNoErrors();

    $fattura = App\Models\Gestionale\FatturaPassiva::where('fornitore_id', $fornitore->id)->firstOrFail();

    // 20% su 1.000,00 € di imponibile = 200,00 €, in centesimi.
    expect($fattura->importo_ritenuta)->toBe(20000);
});

test('la risposta resta sull\'anagrafica, così la domanda non torna', function () {
    $ctx = setupContabile();
    $fornitore = fornitoreMaiClassificato();

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.store', $ctx[0]), payloadFattura($ctx, $fornitore, [
            'posizione_ritenuta' => ['soggetto' => true, 'tipo' => 'lavoro_autonomo_20'],
        ]))->assertSessionHasNoErrors();

    $fornitore->refresh();

    expect($fornitore->soggetto_ritenuta)->toBeTrue()
        ->and($fornitore->tipo_ritenuta?->value)->toBe('lavoro_autonomo_20')
        ->and($fornitore->posizioneRitenutaMaiDecisa())->toBeFalse();
});

test('anche un «no» viene ricordato, ed è tutta la ragione della colonna', function () {
    // ⚠️ Senza questo, «no» resterebbe scritto come `soggetto_ritenuta = false` — cioè come il
    // silenzio da cui si voleva distinguerlo — e la domanda tornerebbe alla fattura dopo. Un
    // avviso che non si può chiudere è un avviso che si impara a saltare.
    $ctx = setupContabile();
    $fornitore = fornitoreMaiClassificato();

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.store', $ctx[0]), payloadFattura($ctx, $fornitore, [
            'posizione_ritenuta' => ['soggetto' => false, 'tipo' => null],
        ]))->assertSessionHasNoErrors();

    $fornitore->refresh();

    expect($fornitore->soggetto_ritenuta)->toBeFalse()
        ->and($fornitore->posizioneRitenutaMaiDecisa())->toBeFalse();
});

test('a un fornitore già classificato non si chiede niente', function () {
    // ⚠️ Il controesempio, e protegge la stragrande maggioranza dei casi: chi ha già una
    // posizione dichiarata non deve essere interrotto mai. Vale anche **senza la data** —
    // un fornitore nato da un seeder o da un'importazione con la ritenuta dichiarata è
    // evidentemente deciso, e chiederglielo di nuovo sarebbe rumore.
    $ctx = setupContabile();
    $fornitore = fornitoreMaiClassificato();
    $fornitore->update([
        'soggetto_ritenuta' => true,
        'tipo_ritenuta' => 'appalto_4',
        'natura_percipiente' => 'soggetto_ires',
        'perc_ritenuta' => 4,
        'ritenuta_decisa_il' => null,
    ]);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.store', $ctx[0]), payloadFattura($ctx, $fornitore))
        ->assertSessionHasNoErrors();
});
