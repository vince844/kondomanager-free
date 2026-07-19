<?php

/**
 * Tenancy: nessun identificativo di un altro condominio deve attraversare le
 * FormRequest.
 *
 * Il progetto scopava `gestione_id` ma lasciava aperti quasi tutti gli altri id:
 * il codice a valle (service e action) risolve quegli id con find()/findOrFail()
 * senza mai verificare l'appartenenza, quindi la validazione è l'unico presidio.
 * Ogni test qui usa un SECONDO condominio completo e verifica che l'id altrui
 * venga respinto dalla validazione, non più a valle o per niente.
 */

use App\Enums\MetodoPagamento;
use App\Enums\TipoAllocazioneFattura;
use App\Models\Condominio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/**
 * Secondo condominio minimale: setupPagamentiService() non è richiamabile due
 * volte (immobili.codice_immobile ha un unique globale), quindi si costruisce a
 * mano solo ciò che serve a fabbricare un id "altrui".
 */
function condominioEstraneo(): object
{
    $condominio = Condominio::factory()->create();

    $contoContabile = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id,
        'ruolo' => 'conto_bancario',
        'codice' => 'BANCA-X-'.uniqid(),
        'nome' => 'Banca del condominio estraneo',
        'tipo' => 'attivo',
        'categoria' => 'crediti',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $pianoConto = DB::table('piani_conti')->insertGetId([
        'condominio_id' => $condominio->id,
        'gestione_id' => DB::table('gestioni')->insertGetId([
            'condominio_id' => $condominio->id,
            'nome' => 'Gestione estranea', 'tipo' => 'ordinaria', 'attiva' => true,
            'data_inizio' => '2026-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]),
        'nome' => 'Piano estraneo',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $capitolo = DB::table('conti')->insertGetId([
        'piano_conto_id' => $pianoConto,
        'nome' => 'Capitolo estraneo',
        'tipo' => 'spesa',
        'importo' => 100000,
        'is_tecnico' => false,
        'conto_contabile_id' => $contoContabile,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $immobile = DB::table('immobili')->insertGetId([
        'condominio_id' => $condominio->id,
        'nome' => 'Immobile estraneo',
        'descrizione' => 'Immobile di un altro condominio',
        'interno' => '99',
        'codice_immobile' => 'EXT-'.uniqid(),
        'attivo' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return (object) compact('condominio', 'contoContabile', 'pianoConto', 'capitolo', 'immobile');
}

// ─── Fatture: capitolo di spesa e immobile ──────────────────────────────────

test('una riga fattura non può puntare al capitolo di spesa di un altro condominio', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, $gestione, $fornitore] = $ctx;
    $estraneo = condominioEstraneo();

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.store', $condominio), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'gestione_id' => $gestione->id,
            'tipo_documento' => 'fattura',
            'numero_documento' => 'FT-X-'.uniqid(),
            'data_documento' => now()->toDateString(),
            'data_scadenza' => now()->addDays(30)->toDateString(),
            'modalita_pagamento' => 'bonifico',
            'stato_approvazione' => 'approvata',
            'righe' => [[
                'descrizione' => 'Riga con capitolo altrui',
                'importo_imponibile' => 100,
                'aliquota_iva' => 0,
                'importo_iva' => 0,
                'conto_id' => $estraneo->capitolo,   // ← id di un altro condominio
            ]],
        ])
        ->assertSessionHasErrors('righe.0.conto_id');

    // Nessuna scrittura agganciata al mastro altrui.
    expect(DB::table('scritture_contabili')->where('condominio_id', $condominio->id)->count())->toBe(0);
});

test('una riga fattura non può puntare a un immobile di un altro condominio', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, $gestione, $fornitore, , $capitolo] = $ctx;
    $estraneo = condominioEstraneo();

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.fatture.store', $condominio), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'gestione_id' => $gestione->id,
            'tipo_documento' => 'fattura',
            'numero_documento' => 'FT-Y-'.uniqid(),
            'data_documento' => now()->toDateString(),
            'data_scadenza' => now()->addDays(30)->toDateString(),
            'modalita_pagamento' => 'bonifico',
            'stato_approvazione' => 'approvata',
            'righe' => [[
                'descrizione' => 'Spesa ad personam su immobile altrui',
                'importo_imponibile' => 100,
                'aliquota_iva' => 0,
                'importo_iva' => 0,
                'conto_id' => $capitolo->id,
                'immobile_id' => $estraneo->immobile,
            ]],
        ])
        ->assertSessionHasErrors('righe.0.immobile_id');
});

// ─── Pagamenti fornitori: conto di addebito e fatture allocate ──────────────

test('non si può pagare addebitando il conto corrente di un altro condominio', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, , $fornitore] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);
    $estraneo = condominioEstraneo();

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', $condominio), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $estraneo->contoContabile,   // ← banca altrui
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [[
                'fattura_id' => $fattura->id,
                'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => $fattura->netto_a_pagare,
            ]],
            'allow_overdraft' => true,
        ])
        ->assertSessionHasErrors('conto_corrente_id');
});

test('non si può allocare un pagamento su una fattura di un altro condominio', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, , $fornitore, $contoCorrenteId] = $ctx;
    $estraneo = condominioEstraneo();

    // Fattura intestata al condominio estraneo, stesso fornitore (caso reale:
    // la stessa impresa lavora per più stabili).
    $fatturaAltrui = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $estraneo->condominio->id,
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'tipo_documento' => 'fattura',
        'numero_documento' => 'FT-ALTRUI-'.uniqid(),
        'data_documento' => now()->toDateString(),
        'data_scadenza' => now()->toDateString(),
        'importo_imponibile' => 10000,
        'importo_iva' => 0,
        'totale_documento' => 10000,
        'netto_a_pagare' => 10000,
        'stato_pagamento' => 'aperta',
        'stato_approvazione' => 'approvata',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.pagamenti-fornitori.store', $condominio), [
            'fornitore_id' => $fornitore->id,
            'esercizio_id' => $esercizio->id,
            'conto_corrente_id' => $contoCorrenteId,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
            'allocazioni' => [[
                'fattura_id' => $fatturaAltrui,
                'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => 10000,
            ]],
            'allow_overdraft' => true,
        ])
        ->assertSessionHasErrors('allocazioni.0.fattura_id');
});

// ─── Piano dei conti: il padre deve stare nello stesso piano ───────────────

test('un sottoconto non può avere come padre un capitolo di un altro condominio', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, , , , $capitolo] = $ctx;
    $estraneo = condominioEstraneo();

    $pianoConto = DB::table('conti')->where('id', $capitolo->id)->value('piano_conto_id');

    $this->actingAs($this->user)
        ->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [
            $condominio, $esercizio, $pianoConto,
        ]), [
            'nome' => 'Sottoconto con padre altrui',
            'tipo' => 'spesa',
            'isCapitolo' => false,
            'isSottoConto' => true,
            'parent_id' => $estraneo->capitolo,     // ← capitolo di un altro piano
            'importo' => '100',
            'tabella_millesimale_id' => null,
            'percentuale_proprietario' => 100,
            'percentuale_inquilino' => 0,
            'percentuale_usufruttuario' => 0,
        ])
        ->assertSessionHasErrors('parent_id');
});
