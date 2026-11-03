<?php

/**
 * Controprova lato server della frase della guida della regolazione immediata: «Il capitolo non mostra il residuo, a differenza della fattura: la spesa
 * alza il consuntivo della voce.» (`RegolazioneImmediataGuide.test.ts`).
 *
 * La prima metà: il menu del capitolo della regolazione riceve solo id, nome, capitolo padre ed etichetta, nessun residuo; la fattura invece riceve per
 * ogni voce il residuo del budget. La seconda metà: una regolazione di € 16,68 porta il consuntivo del piano dei conti a € 16,68 e il residuo che la
 * fattura mostra per la voce scende di 1668 centesimi, da 500000 a 498332; lo storno lo riporta com'era.
 *
 * I test sono controprove: verdi prima e dopo la correzione, che tocca solo il testo della guida.
 *
 * Cosa resta scoperto: la pagina della regolazione montata (non nomina un residuo); la regolazione oltre il preventivo, che si registra senza chiedere
 * di motivare lo sforo come fa la fattura (la guida non lo dice, né prima né dopo); la regolazione su una gestione straordinaria; i database diversi da sqlite.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Il residuo del budget che la fattura mostra per la voce, e il consuntivo del piano dei conti. */
function b53rgLetture($test, $condominio, $esercizio, $capitolo): array
{
    $f = $test->actingAs($test->user)->get(route('admin.gestionale.fatture.create', $condominio->id));
    $f->assertOk();
    $voce = collect(json_decode(json_encode($f->viewData('page')['props']['conti']), true))->firstWhere('id', $capitolo->id);
    $p = $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.piani-conti.show', [$condominio->id, $esercizio->id, $capitolo->piano_conto_id]));
    $p->assertOk();

    return ['residuo_budget' => $voce['residuo_budget'] ?? null, 'totaleConsuntivo' => $p->viewData('page')['props']['totaleConsuntivo']];
}

it('controprova — il menu del capitolo della regolazione non porta un residuo, la fattura sì', function () {
    [$condominio, $esercizio, , , , $capitolo] = setupPagamentiService();

    $r = $this->actingAs($this->user)->get(route('admin.gestionale.regolazioni-immediate.create', $condominio));
    $r->assertOk();
    $capitoli = json_decode(json_encode($r->viewData('page')['props']['capitoli']), true);
    $voce = collect($capitoli)->firstWhere('id', $capitolo->id);

    expect($voce)->not->toBeNull()
        ->and(collect($capitoli)->map(fn ($c) => array_keys($c))->unique(fn ($k) => implode(',', $k))->values()->all())->toBe([['id', 'nome', 'parent_nome', 'label']])
        ->and(b53rgLetture($this, $condominio, $esercizio, $capitolo)['residuo_budget'])->toBe(500000);
});

it('controprova — una regolazione di € 16,68 alza il consuntivo della voce e il residuo della fattura scende di 1668; lo storno lo riporta', function () {
    [$condominio, $esercizio, $gestione, , , $capitolo] = setupPagamentiService();
    $cassaId = DB::table('casse')->where('condominio_id', $condominio->id)->value('id');

    $prima = b53rgLetture($this, $condominio, $esercizio, $capitolo);
    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', $condominio), [
        'gestione_id' => $gestione->id, 'esercizio_id' => $esercizio->id, 'conto_id' => $capitolo->id, 'cassa_id' => $cassaId, 'fornitore_id' => null,
        'data_operazione' => '2026-03-10', 'causale' => 'Imposta di bollo su estratto conto', 'importo' => 16.68,
    ])->assertSessionHasNoErrors();
    $dopo = b53rgLetture($this, $condominio, $esercizio, $capitolo);
    $scrittura = DB::table('scritture_contabili')->where('condominio_id', $condominio->id)->orderByDesc('id')->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.storno', [$condominio, $scrittura]), ['motivo' => 'Importo sbagliato nella registrazione'])
        ->assertSessionHasNoErrors();
    $stornata = b53rgLetture($this, $condominio, $esercizio, $capitolo);

    expect($prima)->toBe(['residuo_budget' => 500000, 'totaleConsuntivo' => 0])
        ->and($dopo)->toBe(['residuo_budget' => 498332, 'totaleConsuntivo' => 1668])
        ->and($stornata['residuo_budget'])->toBe(500000);
});
