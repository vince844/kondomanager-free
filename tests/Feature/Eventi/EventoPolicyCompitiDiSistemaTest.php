<?php

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';

/**
 * Giro di sicurezza della 1.11.0-beta.39, secondo giro di revisione: il limite fuori dal pannello
 * di `EventoPolicy` escludeva gli eventi con un `tipo`, ma non tutti i compiti dell'amministratore
 * ce l'hanno. `SyncScadenziarioWithFattura` (pagamento fornitore, convocazione, sforo,
 * sopravvenienza, deficit) e `SyncF24WithPagamento` (F24) li creano con `tipo` null e `meta.type`
 * valorizzato, nascosti, agganciati al condominio e a nessuna anagrafica. Un condòmino con
 * `EDIT_EVENTS` o `DELETE_EVENTS` concessi senza pannello li riscriveva (diventandone l'autore) o
 * li cancellava, e l'amministratore perdeva il promemoria del versamento F24.
 *
 * I compiti qui nascono dai listener veri, invocati a mano come in `SyncF24WithPagamentoTest`: una
 * copia della loro forma potrebbe divergere dal codice.
 *
 * **Cosa resta scoperto**: convocazione, ratifica dello sforo, sopravvenienza e ripianamento del
 * deficit non sono generati qui; hanno la stessa forma (`meta.type`, nessun `tipo`) e cadono sotto
 * la stessa condizione.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TipoAllocazioneFattura;
use App\Events\Gestionale\FatturaRegistrata;
use App\Events\Gestionale\PagamentoRegistrato;
use App\Listeners\Gestionale\SyncF24WithPagamento;
use App\Listeners\Gestionale\SyncScadenziarioWithFattura;
use App\Models\Anagrafica;
use App\Models\CategoriaEvento;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\User;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\PagamentoFornitoreService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // L'utente 1 prima di tutti: i listener firmano i compiti con lui.
    if (! User::find(1)) {
        User::factory()->create(['id' => 1]);
    }
    $this->seed(RolesAndPermissionsSeeder::class);

    // I listener fanno firstOrFail() su questa categoria dentro un try/catch: senza, nessun compito
    // nasce e il test passerebbe per la ragione sbagliata.
    CategoriaEvento::firstOrCreate(
        ['name' => 'Scadenze amministrative'],
        ['description' => 'Test', 'color' => '#000000', 'icon' => 'test']
    );
});

function fatturaConRitenutaPerCompiti(array $ctx): FatturaPassiva
{
    [$condominio, $esercizio, $gestione, $fornitore, , $capitolo] = $ctx;

    $fornitore->update([
        'soggetto_ritenuta'        => true,
        'perc_ritenuta'            => 20,
        'perc_imponibile_ritenuta' => 100,
        'codice_tributo'           => '1040',
    ]);

    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'applica_ritenuta' => true,
            'righe'            => [[
                'descrizione'        => 'Prestazione con ritenuta',
                'importo_imponibile' => 1000,
                'aliquota_iva'       => 0,
                'conto_id'           => $capitolo->id,
                'is_sopravvenienza'  => false,
            ]],
        ]),
        $condominio->id
    );

    $fattura->update(['netto_a_pagare' => 100000, 'importo_ritenuta' => 20000]);

    return $fattura;
}

test('fuori dal pannello i compiti di fattura e F24 senza tipo non si modificano né si cancellano', function () {
    $ctx = setupPagamentiService();
    /** @var Condominio $condominio */
    $condominio = $ctx[0];
    $fattura = fatturaConRitenutaPerCompiti($ctx);

    (new SyncScadenziarioWithFattura())->handle(new FatturaRegistrata($fattura, 1));

    $pagamento = (new PagamentoFornitoreService())->registraPagamento(datiPagamento($ctx, $fattura, [
        'data_pagamento' => '2026-01-10',
        'allocazioni'    => [[
            'fattura_id'             => $fattura->id,
            'tipo'                   => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => 100000,
        ]],
    ]));
    (new SyncF24WithPagamento())->handle(new PagamentoRegistrato($pagamento->refresh()));

    $pagare = Evento::where('meta->type', 'pagamento_fornitore')->sole();
    $f24 = Evento::where('meta->type', 'versamento_ritenuta')->sole();

    // Precondizione: è proprio il caso che il filtro sul solo `tipo` lasciava passare.
    foreach ([$pagare, $f24] as $compito) {
        expect($compito->tipo)->toBeNull()
            ->and($compito->anagrafiche()->count())->toBe(0)
            ->and($compito->condomini()->pluck('condomini.id')->map(fn ($id) => (int) $id)->all())->toBe([$condominio->id]);
    }

    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);
    $user->givePermissionTo([Permission::EDIT_EVENTS->value, Permission::DELETE_EVENTS->value]);
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    $titoloPrima = $pagare->title;

    $this->actingAs($user)->put(route('user.eventi.update', $pagare), [
        'title'         => 'Titolo cambiato',
        'start_time'    => now()->addYear()->toDateTimeString(),
        'end_time'      => now()->addYear()->addHour()->toDateTimeString(),
        'category_id'   => $pagare->category_id,
        'condomini_ids' => [$condominio->id],
        'mode'          => 'all',
    ])->assertForbidden();

    $this->actingAs($user)->delete(route('user.eventi.destroy', $f24))->assertForbidden();

    expect($pagare->fresh()->title)->toBe($titoloPrima)
        ->and($pagare->fresh()->created_by)->toBe($pagare->created_by)
        ->and(Evento::whereKey($f24->id)->exists())->toBeTrue()
        ->and($user->can('update', $f24))->toBeFalse()
        ->and($user->can('delete', $pagare))->toBeFalse();
});
