<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I dati delle azioni consigliate al bordo della loro finestra di 30 giorni: una rata non emessa (C) e una ritenuta da versare (H) che scadono proprio il
 * trentesimo giorno ci sono, il giorno dopo no; la ritenuta prima e dopo la delega. La cifra del giroconto che arriva con l'indirizzo (G) va fino a un
 * miliardo di euro, non oltre.
 */

use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Models\Gestionale\DelegaF24;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Services\Treasury\DatiDelleAzioni;
use App\Services\Treasury\TreasuryGuardianService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';
require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__.'/TesoreriaScenario.php';

/** Quattro persone, 12 rate mensili dal 05/01/2026 (€ 1.000,00 a rata in tutto), emesse quelle date; oggi il giorno dato. */
function abfCondominio($test, array $emesse, string $oggi): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 400], 'B' => ['bianchi', 300], 'C' => ['verdi', 200], 'D' => ['neri', 100]], 12, 'prima_rata', [], 1200000);
    lcdEmetti($test, $s, $emesse, '2026-01-02');
    $test->travelTo($oggi . ' 10:00:00');

    return $s;
}

it('(C) una rata non emessa che scade proprio il trentesimo giorno è nella finestra; il giorno dopo la finestra non la prende', function () {
    $this->user = smaUtente();
    $this->withoutVite();
    // Oggi il 06/05/2026: la finestra arriva al 05/06, il giorno della rata 6.
    $s = abfCondominio($this, [1, 2, 3], '2026-05-06');
    $numeri = fn () => array_column(app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray()['datiDelleAzioni']['rateDaEmettere'][0]['rate'], 'numero');
    expect($numeri())->toBe([4, 5, 6]);

    // Il 05/05 la finestra arriva al 04/06: la rata 6 resta fuori.
    $this->travelTo('2026-05-05 10:00:00');
    expect($numeri())->toBe([4, 5]);
});

it('(H) una ritenuta che scade proprio il trentesimo giorno è nella finestra, prima e dopo la delega; il giorno prima no', function () {
    $this->user = smaUtente();
    $this->withoutVite();
    $ctx = setupPagamentiService();
    [$condominio, $esercizio] = $ctx;
    $ctx[3]->update(['soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100, 'natura_percipiente' => 'persona_fisica_irpef']);
    $ctx[3]->refresh();
    $this->travelTo('2026-03-10 10:00:00');
    $fattura = registraFatturaServiceTest($ctx, ['applica_ritenuta' => true,
        'righe' => [['descrizione' => 'Servizio', 'importo_imponibile' => 20000, 'aliquota_iva' => 22, 'conto_id' => $ctx[5]->id, 'is_sopravvenienza' => false]]]);
    (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura, ['data_pagamento' => '2026-03-10']));
    $leggi = function (string $oggi) use ($condominio) {
        $o = CarbonImmutable::parse($oggi);

        return app(DatiDelleAzioni::class)->perCondominio($condominio, null, $o, $o->addDays(30), ['righe' => [], 'esercizio_id' => null])['ritenute']['scadenze'];
    };

    // Il 17/03 la finestra arriva al 16/04, il giorno della scadenza; il 16/03 al 15/04.
    expect($leggi('2026-03-17'))->toBe([['giorno' => '2026-04-16', 'cifra' => 80000, 'delega' => null]])
        ->and($leggi('2026-03-16'))->toBe([]);
    app(GeneraDelegheF24Action::class)->esegui($condominio, $esercizio->id);
    $delega = (int) DelegaF24::where('condominio_id', $condominio->id)->value('id');
    expect($leggi('2026-03-17'))->toBe([['giorno' => '2026-04-16', 'cifra' => 80000, 'delega' => $delega]])
        ->and($leggi('2026-03-16'))->toBe([]);
});

it('(G) la cifra del giroconto dall\'indirizzo arriva fino a un miliardo di euro, non oltre', function () {
    $this->withoutVite();
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $utente = User::factory()->create();
    $utente->assignRole($ruolo);
    [$condominio] = setupContabile();
    $leggi = fn (int $importo) => $this->actingAs($utente)->get(route('admin.gestionale.giroconti.create', ['condominio' => $condominio->id, 'importo' => $importo]))
        ->assertOk()->viewData('page')['props']['preselezione']['importo_cents'];

    expect($leggi(100_000_000_000))->toBe(100_000_000_000)
        ->and($leggi(100_000_000_001))->toBeNull();
});
