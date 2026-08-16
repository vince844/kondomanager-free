<?php

/**
 * L'ordinamento sugli elenchi serviti da un Service, che è dove si nascondeva.
 *
 * ## Il difetto che questo file fissa
 *
 * `OrdinamentoElenchiTest` copre gli immobili, che ordinano dentro il controller. Sei elenchi
 * centrali — comunicazioni, segnalazioni e documenti, ciascuno in versione amministratore e
 * portale — passano invece da un Service, e quei Service applicavano un `orderBy('created_at')`
 * **prima** che `ordina()` aggiungesse la colonna chiesta.
 *
 * Ne usciva `ORDER BY created_at DESC, subject ASC, id ASC`: con date distinte — cioè sempre — il
 * primo criterio decideva da solo e la colonna scelta dall'utente non aveva alcun effetto. A video
 * la freccetta si accendeva lo stesso, perché il server rimanda `sort` fra le props. La schermata
 * dichiarava un ordinamento che non aveva applicato: esattamente il difetto per cui la beta.54
 * esiste, in una forma che la beta.54 stessa aveva introdotto.
 *
 * ## Perché le date sono in ordine inverso ai titoli
 *
 * È la condizione che rende il test capace di distinguere. Se i titoli crescessero insieme alle
 * date, ordinare per data e ordinare per titolo darebbero la stessa sequenza, e il test passerebbe
 * anche con il codice rotto.
 */

use App\Models\Comunicazione;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (['Accesso pannello amministratore', 'Visualizza comunicazioni'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    // ⚠️ Titoli e date **in ordine opposto**: «Alfa» è la più vecchia, «Zeta» la più recente.
    // Ordinando per data decrescente esce Zeta per prima; ordinando per titolo crescente esce Alfa.
    // Le due sequenze non possono essere confuse, ed è tutto il senso di questa preparazione.
    foreach (['Alfa' => 30, 'Mika' => 20, 'Zeta' => 10] as $titolo => $giorniFa) {
        $c = Comunicazione::create([
            'subject'      => $titolo,
            'description'  => 'Comunicazione di prova',
            'created_by'   => $this->user->id,
            'priority'     => 'bassa',
            'is_published' => true,
            'is_approved'  => true,
            'slug'         => \Illuminate\Support\Str::slug($titolo).'-'.$giorniFa,
        ]);

        // ⚠️ **`created_at` non è in `$fillable`**, quindi passarla a `create()` non ha effetto:
        // le tre comunicazioni nascerebbero con lo stesso istante. Con date identiche il criterio
        // `created_at DESC` non discrimina, il titolo decide comunque, e **questo test passerebbe
        // anche con il difetto in piedi** — che è esattamente ciò che è successo la prima volta che
        // l'ho scritto. Va forzata dopo la creazione, saltando la protezione di massa.
        $c->forceFill([
            'created_at' => now()->subDays($giorniFa),
            'updated_at' => now()->subDays($giorniFa),
        ])->saveQuietly();
    }
});

function titoliComunicazioni(array $query = []): array
{
    $r = test()->actingAs(test()->user)
        ->get(route('admin.comunicazioni.index', $query));

    return collect($r->viewData('page')['props']['comunicazioni'])->pluck('subject')->all();
}

it('la colonna chiesta è il criterio principale, non uno spareggio', function () {
    // Se restasse in piedi l'`orderBy('created_at','desc')` del Service, qui uscirebbe
    // Zeta, Mika, Alfa — l'ordine delle date — e il titolo non conterebbe niente.
    expect(titoliComunicazioni(['sort' => 'subject', 'direction' => 'asc']))
        ->toBe(['Alfa', 'Mika', 'Zeta']);
});

it('e vale anche nel verso opposto', function () {
    // ⚠️ Questo caso da solo non basterebbe: coincide con l'ordine delle date, quindi passerebbe
    // anche con il codice rotto. Sta qui perché insieme al precedente dimostra che l'ordinamento
    // segue davvero la richiesta invece di essere una coincidenza.
    expect(titoliComunicazioni(['sort' => 'subject', 'direction' => 'desc']))
        ->toBe(['Zeta', 'Mika', 'Alfa']);
});

it('il server dichiara l\'ordinamento che ha davvero applicato', function () {
    $props = $this->actingAs($this->user)
        ->get(route('admin.comunicazioni.index', ['sort' => 'subject', 'direction' => 'asc']))
        ->viewData('page')['props'];

    expect($props['sort'])->toBe('subject')
        ->and($props['direction'])->toBe('asc')
        ->and(collect($props['comunicazioni'])->first()['subject'])->toBe('Alfa');
});
