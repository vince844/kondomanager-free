<?php

/**
 * Coda 455 (punto 6b) — il messaggio del limite dei contanti dice il vero anche a € 5.000,00 esatti.
 *
 * Il divieto vale da € 5.000,00 compresi (D.Lgs. 231/2007, soglia dal 01/01/2023; decisione 183.5): il servizio blocca con `>= 500000`
 * centesimi e la pagina del pagamento dice «pari o superiore a 5.000€». Il messaggio che il servizio mandava alla pagina diceva «supera il
 * limite»: a € 5.000,00 esatti dava del superamento a un importo che il limite non supera. La soglia non cambia, cambia la parola.
 *
 * Si prova dalla rotta che la pagina chiama (nuovo pagamento), con le cifre della regola: 499999 centesimi passano, 500000 e 500001 no, e il
 * messaggio dice la cifra scritta e «è pari o superiore al limite».
 */

use App\Enums\MetodoPagamento;
use App\Enums\TipoAllocazioneFattura;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\User;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruoloAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo($permessoAdmin);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);
});

/** Una fattura da € 7.320,00 (6.000,00 più IVA al 22 %): capiente per ogni importo che si prova, pagato in parte. */
function fatturaGrandeDeiContanti(array $ctx)
{
    [$condominio, $esercizio, $gestione, $fornitore, , $capitolo] = $ctx;

    return (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Lavori',
                'importo_imponibile' => 6000,
                'aliquota_iva' => 22,
                'conto_id' => $capitolo->id,
                'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );
}

/** Il corpo come lo manda la pagina del pagamento: contanti, l'importo allocato in centesimi. */
function corpoPagamentoContanti(array $ctx, $fattura, int $centesimi): array
{
    [$condominio, $esercizio, , $fornitore, $contoCorrenteId] = $ctx;

    return [
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->toDateString(),
        'metodo_pagamento' => MetodoPagamento::CONTANTI->value,
        'allocazioni' => [[
            'fattura_id' => $fattura->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => $centesimi,
        ]],
        'bonifico_parlante' => false,
        'allow_overdraft' => true,
    ];
}

it('Coda 455 — contanti a € 5.000,00 esatti: il blocco c\'è e il messaggio dice «è pari o superiore al limite», non «supera»', function () {
    $ctx = setupPagamentiHttp();
    [$condominio] = $ctx;
    $fattura = fatturaGrandeDeiContanti($ctx);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), corpoPagamentoContanti($ctx, $fattura, 500000))
        ->assertSessionHasErrors('illegal_cash');

    $messaggio = session('errors')->first('illegal_cash');
    expect($messaggio)
        ->toContain('€ 5.000,00 non consentito')
        ->toContain('è pari o superiore al limite di € 5.000,00')
        ->not->toContain('supera')
        ->and(PagamentoFornitore::count())->toBe(0);
});

it('Coda 455 — contanti a € 5.000,01: bloccati, con la stessa frase e la cifra scritta', function () {
    $ctx = setupPagamentiHttp();
    [$condominio] = $ctx;
    $fattura = fatturaGrandeDeiContanti($ctx);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), corpoPagamentoContanti($ctx, $fattura, 500001))
        ->assertSessionHasErrors('illegal_cash');

    expect(session('errors')->first('illegal_cash'))
        ->toContain('€ 5.000,01 non consentito')
        ->toContain('è pari o superiore al limite di € 5.000,00')
        ->and(PagamentoFornitore::count())->toBe(0);
});

it('Coda 455 — controprova: contanti a € 4.999,99 passano, la soglia non si è mossa', function () {
    $ctx = setupPagamentiHttp();
    [$condominio] = $ctx;
    $fattura = fatturaGrandeDeiContanti($ctx);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', [$condominio]), corpoPagamentoContanti($ctx, $fattura, 499999))
        ->assertSessionHasNoErrors();

    expect(PagamentoFornitore::count())->toBe(1);
});
