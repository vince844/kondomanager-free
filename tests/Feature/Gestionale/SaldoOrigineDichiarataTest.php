<?php

/**
 * L'origine del pregresso dichiarata sul saldo: nel modulo del saldo, facoltativa e senza una risposta già scelta; con «Dichiara l'origine»,
 * uno o in blocco, anche su un saldo fermo in un piano emesso e su una riga di un passaggio, perché non tocca l'importo; con chi e quando. Un
 * importo diverso su un saldo fermo resta rifiutato come prima. I saldi di un altro condominio fanno 404 per tutta la richiesta.
 */

use App\Models\Esercizio;
use App\Models\Saldo;
use App\Models\User;
use App\Services\Gestionale\Debiti\OriginePregresso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

function sodDichiara($test, array $s, array $saldi, string $origine, ?int $esercizio = null)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.saldi.origine', ['condominio' => $s['c']->id]),
        array_filter(['saldi' => $saldi, 'origine' => $origine, 'esercizio_origine_id' => $esercizio], fn ($v) => $v !== null));
}

it('nel modulo del saldo l\'origine è facoltativa e non ha una risposta già scelta; dichiarata, porta chi e quando', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata');
    $e2025 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $rotta = route('admin.gestionale.saldi.store', ['condominio' => $s['c']->id]);
    $campi = fn (string $chi, string $unita) => ['anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['immobili'][$unita]->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 20000];

    $this->actingAs($this->user)->post($rotta, $campi('ugo', 'A'))->assertSessionHasNoErrors();
    $ugo = Saldo::where('anagrafica_id', $s['persone']['ugo']->id)->where('saldo_iniziale', 20000)->firstOrFail();
    expect($ugo->origine_dichiarata)->toBeNull()->and($ugo->origine_dichiarata_da)->toBeNull();

    $this->actingAs($this->user)->post($rotta, $campi('bice', 'B') + ['origine_dichiarata' => 'esercizio'])->assertSessionHasErrors('esercizio_origine_id');
    $this->actingAs($this->user)->post($rotta, $campi('bice', 'B') + ['origine_dichiarata' => 'ieri'])->assertSessionHasErrors('origine_dichiarata');
    $this->actingAs($this->user)->post($rotta, $campi('bice', 'B') + ['origine_dichiarata' => 'esercizio', 'esercizio_origine_id' => $e2025->id])->assertSessionHasNoErrors();
    $bice = Saldo::where('anagrafica_id', $s['persone']['bice']->id)->where('saldo_iniziale', 20000)->firstOrFail();
    expect($bice->origine_dichiarata)->toBe('esercizio')
        ->and((int) $bice->esercizio_origine_id)->toBe((int) $e2025->id)
        ->and((int) $bice->origine_dichiarata_da)->toBe((int) $this->user->id)
        ->and($bice->origine_dichiarata_il)->not->toBeNull();

    // La pagina dei saldi porta gli esercizi e la frase accanto al campo: un saldo con debiti di più esercizi si dichiara con il più vecchio.
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', ['condominio' => $s['c']->id]))->assertOk()->viewData('page')['props'];
    expect($pagina['frase_origine'])->toBe('Se il saldo contiene debiti di più esercizi, dichiara il più vecchio: l\'elenco conta da quello.')
        ->and(collect($pagina['esercizi'])->pluck('nome')->all())->toContain('Esercizio 2025');
});

it('«Dichiara l\'origine» su un saldo fermo in un piano emesso passa e non tocca l\'importo; un importo diverso resta rifiutato', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', 50000]]);
    lcdEmetti($this, $s, [0, 1], '2026-01-02');
    $saldo = Saldo::where('anagrafica_id', $s['persone']['ugo']->id)->firstOrFail();
    expect($saldo->fresh()->load('pianoRate')->eBloccato())->toBeTrue();
    $this->travelTo('2026-02-10 10:00:00');

    sodDichiara($this, $s, [$saldo->id], OriginePregresso::PRIMA_DEL_PRIMO)->assertSessionHasNoErrors();
    expect($saldo->fresh()->origine_dichiarata)->toBe('prima_del_primo')
        ->and((int) $saldo->fresh()->saldo_iniziale)->toBe(50000)
        ->and((int) $saldo->fresh()->origine_dichiarata_da)->toBe((int) $this->user->id);

    $this->actingAs($this->user)->patch(route('admin.gestionale.saldi.update', ['condominio' => $s['c']->id, 'saldo' => $saldo->id]), [
        'saldo_iniziale' => 40000, 'gestione_id' => $s['g']->id,
    ])->assertSessionHasErrors('saldo');
    expect((int) $saldo->fresh()->saldo_iniziale)->toBe(50000);

    // Di nuovo «non dichiarata»: la voce torna nulla, e resta chi l'ha tolta.
    sodDichiara($this, $s, [$saldo->id], OriginePregresso::NON_DICHIARATA)->assertSessionHasNoErrors();
    expect($saldo->fresh()->origine_dichiarata)->toBeNull()->and($saldo->fresh()->esercizio_origine_id)->toBeNull()
        ->and($saldo->fresh()->origine_dichiarata_il)->not->toBeNull();
});

it('«Dichiara l\'origine» vale anche sulle righe del conguaglio di un passaggio, che non si modificano nell\'importo', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    $subentro = DB::table('subentri')->insertGetId(['condominio_id' => $s['c']->id, 'immobile_id' => $s['immobili']['A']->id, 'tipologia' => 'proprietario',
        'tipo_passaggio' => 'vendita', 'decorrenza' => '2026-03-01', 'created_at' => now(), 'updated_at' => now()]);
    $riga = Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['immobili']['A']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 3000, 'origine' => 'automatico', 'is_applicato' => false, 'subentro_id' => $subentro]);

    $this->actingAs($this->user)->patch(route('admin.gestionale.saldi.update', ['condominio' => $s['c']->id, 'saldo' => $riga->id]), [
        'saldo_iniziale' => 1000, 'gestione_id' => $s['g']->id,
    ])->assertSessionHasErrors('saldo');
    sodDichiara($this, $s, [$riga->id], OriginePregresso::ESERCIZIO, (int) $s['e']->id)->assertSessionHasNoErrors();
    expect($riga->fresh()->origine_dichiarata)->toBe('esercizio')->and((int) $riga->fresh()->saldo_iniziale)->toBe(3000);
});

it('in blocco: tutti i saldi spuntati in una richiesta; uno di un altro condominio, o di un fornitore, fa 404 e niente si scrive', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata', [['ugo', 'A', 1000], ['bice', 'B', 2000]]);
    $altro = lcdScenario(['A' => ['zeno', 1000]], 12, 'prima_rata', [['zeno', 'A', 500]]);
    $nostri = Saldo::where('condominio_id', $s['c']->id)->pluck('id')->all();
    $suo = (int) Saldo::where('condominio_id', $altro['c']->id)->value('id');
    expect($nostri)->toHaveCount(2);

    sodDichiara($this, $s, [...$nostri, $suo], OriginePregresso::CON_IL_PIANO)->assertNotFound();
    expect(Saldo::whereIn('id', $nostri)->whereNotNull('origine_dichiarata')->count())->toBe(0);

    // Un debito verso un fornitore sta nella stessa tabella: non è un pregresso di un condòmino.
    $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Enel', 'created_at' => now(), 'updated_at' => now()]);
    $delFornitore = (int) DB::table('saldi')->insertGetId(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'fornitore_id' => $fornitore, 'gestione_id' => $s['g']->id,
        'saldo_iniziale' => 9000, 'origine' => 'manuale', 'is_applicato' => true, 'created_at' => now(), 'updated_at' => now()]);
    sodDichiara($this, $s, [...$nostri, $delFornitore], OriginePregresso::CON_IL_PIANO)->assertNotFound();
    expect(Saldo::whereIn('id', $nostri)->whereNotNull('origine_dichiarata')->count())->toBe(0);

    sodDichiara($this, $s, $nostri, OriginePregresso::CON_IL_PIANO)->assertSessionHasNoErrors();
    expect(Saldo::whereIn('id', $nostri)->pluck('origine_dichiarata')->unique()->all())->toBe(['con_il_piano']);

    // Senza la voce, o con un esercizio di un altro condominio: rifiutato.
    sodDichiara($this, $s, $nostri, '')->assertSessionHasErrors('origine');
    sodDichiara($this, $s, $nostri, OriginePregresso::ESERCIZIO, (int) $altro['e']->id)->assertSessionHasErrors('esercizio_origine_id');
    sodDichiara($this, $s, $nostri, OriginePregresso::ESERCIZIO)->assertSessionHasErrors('esercizio_origine_id');
});
