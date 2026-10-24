<?php

/**
 * 1.11.0-beta.55, Coda 245 — le fini con un coinquilino che resta registrate dalle beta .50–.53, con la fermata del coinquilino nel
 * registro (`fermata_coinquilino`): restano lette com'erano (nessuna migrazione dei dati).
 *
 * Il registro di quelle beta non ha la scelta del conguaglio né la regola del piano: ha la fermata (€ 90,74 dei giorni dopo l'uscita di Ines,
 * rimasti nelle sue rate, con la strada dei saldi del Wallet legati al passaggio). Lo storico la dice con la sua frase, la locazione continua
 * con Dora, e l'annullamento toglie le righe legate a quella fine e rimette i netti. Il registro si scrive qui a mano, come lo scriveva la
 * registrazione di quelle beta: la .55 non lo scrive più.
 *
 * I CONTI A MANO: la parte di Ines dopo l'uscita con la regola dei giorni, 18000 × 184/365 = 9073,97 → 9074; le righe legate Ines − 9074,
 * Dora + 9074.
 *
 * Il prospetto degli oneri accessori resta alla strada dei giorni con il coinquilino che resta (strada C): Ines ha nelle sue rate i suoi 181 giorni,
 * 8926, e i 184 dopo l'uscita, 9074, con la nota che chi li paga lo decidono le parti; Dora i suoi 18000. Con i saldi del Wallet legati a quella fine
 * (decisione 98.2) la nota della voce e la frase della fine dicono anche i loro fatti, con la persona e la cifra: le righe legate non cambiano le voci
 * del prospetto, che sono quelle del piano.
 *
 * Cosa NON copre: i passaggi dopo una fine con la fermata del coinquilino (la regola di oggi, decisione 111.2, in `Beta55CoinquilinoDopoLaCoppiaTest`);
 * MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Beta55CoinquilinoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/**
 * La fine di Ines con Dora che resta, registrata dalla rotta e poi con il registro riscritto come lo scrivevano le beta .50–.53: niente
 * scelta, niente regola del piano, i piani non presi, e la fermata del coinquilino.
 */
function rvFineVecchia($test, array $s): Subentro
{
    $fine = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere'])->fresh();
    $registro = $fine->registro;
    unset($registro['conguaglio'], $registro['regolato_fuori'], $registro['coinquilini_letti']);
    $registro['piani_presi'] = [];
    $registro['fermata_coinquilino'] = [[
        'immobile_id' => (int) $s['unita']->id, 'unita' => 'Interno 1', 'gestione_id' => (int) $s['g']->id, 'gestione' => 'Ordinaria 2026', 'esercizio_id' => (int) $s['e']->id,
        'cifra' => 9074, 'persona_id' => (int) $s['persone']['Ines']->id, 'persona' => 'Ines', 'restano' => ['Dora'], 'al_posto' => ['Ugo'], 'wallet' => true, 'strada' => 'legata',
    ]];
    $fine->registro = $registro;
    $fine->save();

    return $fine->fresh();
}

it('Coda 245 (registri vecchi) — lo storico di una fine con la fermata del coinquilino dice la sua frase, e la locazione che continua con Dora', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    $fine = rvFineVecchia($this, $s);

    $voce = collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $fine->id);

    expect($voce['conguaglio']['stato'])->toBe('nessuno')
        ->and(implode(' ', $voce['fermate']))->toContain('€ 90,74')
        ->and(implode(' ', $voce['fermate']))->toContain('Ines')
        ->and(implode(' ', $voce['obbligati']))->toContain('la locazione continua con Dora')
        ->and(\App\Services\Subentro\ChiPagaAlPostoDellInquilino::fineDelPiano($fine, (int) $s['unita']->id))->toBeNull();
});

it('Coda 245 (registri vecchi) — l\'annullamento di una fine con la fermata del coinquilino toglie le righe legate dal Wallet, e i netti tornano com\'erano', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    $fine = rvFineVecchia($this, $s);
    foreach (['Ines' => -9074, 'Dora' => 9074] as $chi => $centesimi) {
        $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
            'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $centesimi, 'passaggio_id' => $fine->id,
        ])->assertSessionHasNoErrors();
    }
    expect(DB::table('saldi')->where('passaggio_id', $fine->id)->pluck('saldo_iniziale', 'anagrafica_id')->map(fn ($c) => (int) $c)->all())
        ->toBe([(int) $s['persone']['Ines']->id => -9074, (int) $s['persone']['Dora']->id => 9074]);

    fclAnnulla($this, $s, $fine)->assertStatus(302);

    expect(DB::table('saldi')->where('passaggio_id', $fine->id)->count())->toBe(0)
        ->and(count($fine->fresh()->registro['annullamento']['saldi_legati_tolti'] ?? []))->toBe(2)
        ->and(fclNetti($s))->toBe(['Dora' => 18000, 'Ines' => 18000, 'Ugo' => 84000]);
});

it('Coda 245 (registri vecchi) — il prospetto di una fine con la fermata del coinquilino resta alla strada dei giorni: i € 90,74 nelle rate di Ines, e chi li paga lo decidono le parti', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    rvFineVecchia($this, $s);
    $p = app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $ines = collect($p['conduttori'])->firstWhere('nome', 'Ines');

    expect([(int) $ines['totale'], (int) $ines['nelle_sue_rate'], (int) $ines['da_rimborsare']])->toBe([18000, 18000, 0])
        ->and(collect($ines['voci'])->firstWhere('strada', 'C')['importo'] ?? null)->toBe(9074)
        ->and(collect($ines['voci'])->firstWhere('strada', 'C')['nota'] ?? null)->toBe('giorni dopo l\'uscita, dal 01/07/2026: resta un coinquilino, e chi li paga lo decidono le parti')
        ->and($ines['regola_del_piano'] ?? null)->toBeNull()
        ->and(implode(' | ', $p['intestazione']))->toContain('Alla fine della locazione del 01/07/2026 resta un coinquilino: il conguaglio dei giorni dopo l\'uscita non c\'è, quei giorni restano nelle rate di chi è uscito, e chi li paga lo decidono le parti.')
        ->and(implode(' | ', $p['intestazione']))->not->toContain('saldi del Wallet');
});

it('Coda 245 (registri vecchi) — con i saldi del Wallet legati a quella fine il prospetto dice i loro fatti, con la persona e la cifra, nella nota della voce e nella frase della fine', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    $fine = rvFineVecchia($this, $s);
    foreach (['Ines' => -9074, 'Dora' => 9074] as $chi => $centesimi) {
        $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
            'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $centesimi, 'passaggio_id' => $fine->id,
        ])->assertSessionHasNoErrors();
    }
    $p = app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $ines = collect($p['conduttori'])->firstWhere('nome', 'Ines');

    // Le voci non cambiano: sono quelle del piano (decisione 100.2, le righe legate non entrano nei conti del piano).
    expect([(int) $ines['totale'], (int) $ines['nelle_sue_rate'], (int) $ines['da_rimborsare']])->toBe([18000, 18000, 0])
        ->and(collect($ines['voci'])->firstWhere('strada', 'C')['nota'] ?? null)->toBe('giorni dopo l\'uscita, dal 01/07/2026: resta un coinquilino, e chi li paga lo decidono le parti; saldi del Wallet legati a quella fine: € 90,74 a credito di Ines e € 90,74 a debito di Dora')
        ->and(implode(' | ', $p['intestazione']))->toContain('Alla fine della locazione del 01/07/2026 resta un coinquilino: il conguaglio dei giorni dopo l\'uscita non c\'è, quei giorni restano nelle rate di chi è uscito, e chi li paga lo decidono le parti. Ci sono saldi del Wallet legati a quella fine: € 90,74 a credito di Ines e € 90,74 a debito di Dora.');
    expect(fclStampa($s))->toContain('saldi del Wallet legati a quella fine: € 90,74 a credito di Ines e € 90,74 a debito di Dora');
});
