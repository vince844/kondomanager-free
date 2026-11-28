<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\Conto;
use App\Models\User;
use App\Services\Gestionale\SpesaPerVoceService;
use App\Services\Gestionale\VoceDellaGestione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * La regolazione immediata confronta la gestione, la voce, l'esercizio e la data. Il caso: Condominio con «Gestione Ordinaria» e
 * «Riscaldamento» (dal 15/10/2026 al 15/04/2027); una regolazione di € 85,00 per il bruciatore sulla voce «Manutenzione caldaia» del
 * riscaldamento. Con la gestione ordinaria il giornale direbbe «Ordinaria» e il consuntivo per voce metterebbe la spesa sul riscaldamento.
 */

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    $this->travelTo(\Carbon\Carbon::parse('2026-11-27 10:00:00'));
});

/** Il condominio con le due gestioni; il riscaldamento agganciato all'esercizio 2026 dal 15/10 al 31/12 (se $aggancia). */
function b15RegContesto(bool $aggancia = true): array
{
    [$condominio, $esercizio, $ordinaria, , , $voceOrdinaria] = setupPagamentiService();

    $riscaldamento = DB::table('gestioni')->insertGetId([
        'condominio_id' => $condominio->id, 'nome' => 'Riscaldamento 2026/27', 'tipo' => 'ordinaria', 'attiva' => true,
        'data_inizio' => '2026-10-15', 'data_fine' => '2027-04-15', 'created_at' => now(), 'updated_at' => now(),
    ]);
    if ($aggancia) {
        DB::table('esercizio_gestione')->insert([
            'esercizio_id' => $esercizio->id, 'gestione_id' => $riscaldamento, 'attiva' => true,
            'data_inizio' => '2026-10-15', 'data_fine' => '2026-12-31', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $piano = DB::table('piani_conti')->insertGetId([
        'gestione_id' => $riscaldamento, 'condominio_id' => $condominio->id, 'nome' => 'Piano del riscaldamento',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $caldaia = DB::table('conti')->insertGetId([
        'piano_conto_id' => $piano, 'conto_contabile_id' => $voceOrdinaria->conto_contabile_id, 'nome' => 'Manutenzione caldaia',
        'tipo' => 'spesa', 'importo' => 100000, 'is_tecnico' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [
        'condominio' => $condominio, 'esercizio' => $esercizio, 'ordinaria' => $ordinaria->id, 'riscaldamento' => $riscaldamento,
        'caldaia' => $caldaia, 'voce_ordinaria' => $voceOrdinaria->id,
        'cassa' => DB::table('casse')->where('condominio_id', $condominio->id)->value('id'),
    ];
}

function b15RegDati(array $c, array $extra = []): array
{
    return array_merge([
        'gestione_id' => $c['riscaldamento'], 'esercizio_id' => $c['esercizio']->id, 'conto_id' => $c['caldaia'], 'cassa_id' => $c['cassa'],
        'fornitore_id' => null, 'data_operazione' => '2026-11-02', 'causale' => 'Riparazione del bruciatore', 'importo' => 85,
    ], $extra);
}

function b15RegConta(): int
{
    return DB::table('scritture_contabili')->where('tipo_movimento', 'regolazione_immediata')->count();
}

test('(1) la voce del riscaldamento con la gestione ordinaria è rifiutata sul campo della voce; con la gestione giusta va nel consuntivo del riscaldamento', function () {
    $c = b15RegContesto();
    $rotta = route('admin.gestionale.regolazioni-immediate.store', $c['condominio']);

    $this->actingAs($this->user)->post($rotta, b15RegDati($c, ['gestione_id' => $c['ordinaria']]))
        ->assertRedirect()
        ->assertSessionHasErrors(['conto_id' => VoceDellaGestione::rifiutoPerLaVoce($c['ordinaria'], $c['caldaia'])]);
    expect(b15RegConta())->toBe(0)
        ->and(session('errors')->first('conto_id'))->toContain('Manutenzione caldaia')->toContain('Riscaldamento 2026/27');

    // La catena: la stessa regolazione con la gestione della voce passa, il giornale dice «Riscaldamento» e il consuntivo per voce la mette lì.
    $this->actingAs($this->user)->post($rotta, b15RegDati($c))->assertSessionHasNoErrors()->assertRedirect();
    $scrittura = DB::table('scritture_contabili')->where('tipo_movimento', 'regolazione_immediata')->first();
    expect((int) $scrittura->gestione_id)->toBe($c['riscaldamento']);

    $perVoce = app(SpesaPerVoceService::class)->perEsercizio($c['esercizio']);
    expect((int) ($perVoce[$c['caldaia']] ?? 0))->toBe(8500)
        ->and((int) ($perVoce[$c['voce_ordinaria']] ?? 0))->toBe(0);
});

test('(2) una gestione non agganciata all\'esercizio è rifiutata sul campo della gestione', function () {
    $c = b15RegContesto(aggancia: false);

    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', $c['condominio']), b15RegDati($c))
        ->assertRedirect()
        ->assertSessionHasErrors('gestione_id');
    expect(b15RegConta())->toBe(0)
        ->and(session('errors')->first('gestione_id'))->toContain("non è agganciata all'esercizio");
});

test('(3) la data fuori dal periodo della gestione chiede la conferma; confermata, la regolazione si registra', function () {
    $c = b15RegContesto();
    $rotta = route('admin.gestionale.regolazioni-immediate.store', $c['condominio']);

    $this->actingAs($this->user)->post($rotta, b15RegDati($c, ['data_operazione' => '2026-06-10']))
        ->assertRedirect()
        ->assertSessionHasErrors('avviso_data');
    expect(b15RegConta())->toBe(0)
        ->and(session('errors')->first('avviso_data'))
        ->toStartWith('La data è fuori dalla stagione: confermi?')
        ->toContain('dal 15/10/2026 al 31/12/2026')
        ->toContain('10/06/2026');

    // Decide l'amministratore: con la conferma la registra, alla data scritta.
    $this->actingAs($this->user)->post($rotta, b15RegDati($c, ['data_operazione' => '2026-06-10', 'conferma_avviso_data' => true]))
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(b15RegConta())->toBe(1)
        ->and((string) DB::table('scritture_contabili')->where('tipo_movimento', 'regolazione_immediata')->value('data_competenza'))->toStartWith('2026-06-10');
});

test('(3) la data dentro il periodo non chiede niente; senza date della gestione non si avvisa', function () {
    $c = b15RegContesto();
    $rotta = route('admin.gestionale.regolazioni-immediate.store', $c['condominio']);

    $this->actingAs($this->user)->post($rotta, b15RegDati($c, ['data_operazione' => '2026-10-15']))->assertSessionHasNoErrors()->assertRedirect();

    // La gestione ordinaria del contesto non ha date, né sue né nell'esercizio: nessun periodo da confrontare.
    $this->actingAs($this->user)->post($rotta, b15RegDati($c, ['gestione_id' => $c['ordinaria'], 'conto_id' => $c['voce_ordinaria'], 'data_operazione' => '2026-02-01']))
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(b15RegConta())->toBe(2);
});

test('il modulo manda la gestione di ogni voce, con il nome della gestione nell\'etichetta', function () {
    $c = b15RegContesto();

    $this->actingAs($this->user)->get(route('admin.gestionale.regolazioni-immediate.create', $c['condominio']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('capitoli', function ($capitoli) use ($c) {
            $caldaia = collect($capitoli)->firstWhere('id', $c['caldaia']);

            return $caldaia !== null && (int) $caldaia['gestione_id'] === $c['riscaldamento']
                && $caldaia['label'] === 'Riscaldamento 2026/27 · Manutenzione caldaia';
        }));
});

test('la regola sta in un posto solo e si legge anche senza la richiesta', function () {
    $c = b15RegContesto();

    expect(VoceDellaGestione::rifiutoPerLaVoce($c['riscaldamento'], $c['caldaia']))->toBeNull()
        ->and(VoceDellaGestione::rifiutoPerLaVoce($c['ordinaria'], $c['caldaia']))->not->toBeNull()
        ->and(VoceDellaGestione::rifiutoPerLEsercizio($c['riscaldamento'], $c['esercizio']->id))->toBeNull()
        ->and(VoceDellaGestione::avvisoPerLaData($c['riscaldamento'], $c['esercizio']->id, '2026-12-31'))->toBeNull()
        ->and(VoceDellaGestione::avvisoPerLaData($c['riscaldamento'], $c['esercizio']->id, '2026-10-14'))->toStartWith(VoceDellaGestione::AVVISO_DATA)
        ->and(Conto::find($c['caldaia']))->not->toBeNull();
});
