<?php

/**
 * Il peso della proposta degli interessi con un condominio grande: 300 persone, un'unità ciascuna, dodici rate scadute al 31/12/2026, nessuna versata.
 * La proposta legge il giornale delle persone a gruppi di 250, come la lettura comune; il suggerimento «interessi da proporre» dell'elenco «Rate
 * scadute» la fa per tutte le persone dei filtri. Le soglie sono larghe sopra la misura.
 */

use App\Models\User;
use App\Services\Gestionale\Accessori\PropostaInteressi;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('300 persone × 12 rate scadute: la proposta di tutte al 31/12/2026 e il suggerimento restano nella misura', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $unita = [];
    foreach (range(1, 300) as $i) {
        $unita["U{$i}"] = ["persona{$i}", 1];
    }
    $s = lcdScenario($unita, 12, 'prima_rata', [], 1200 * 300 * 100);
    lcdEmetti($this, $s, range(1, 12), '2026-01-02');
    $this->travelTo('2027-01-20 10:00:00');
    $persone = array_values(array_map(fn ($p) => (int) $p->id, $s['persone']));

    gc_collect_cycles();
    $prima = memory_get_usage();
    memory_reset_peak_usage();
    $inizio = hrtime(true);
    $proposta = app(PropostaInteressi::class)->proponi($s['c'], '2026-12-31', $persone);
    $secondi = (hrtime(true) - $inizio) / 1e9;
    $picco = (memory_get_peak_usage() - $prima) / 1048576;

    $inizio = hrtime(true);
    $con = app(PropostaInteressi::class)->conInteressi($s['c'], '2026-12-31', $persone);
    $secondiConteggio = (hrtime(true) - $inizio) / 1e9;
    fwrite(STDERR, sprintf("\nMISURA proposta 300×12: %.2f s, picco %.1f MB; conteggio %.2f s; righe %d\n", $secondi, $picco,
        $secondiConteggio, array_sum(array_map(fn ($p) => count($p['righe']), $proposta['persone']))));

    expect($proposta['persone'])->toHaveCount(300)->and($con)->toHaveCount(300)
        ->and(array_sum(array_map(fn ($p) => count($p['righe']), $proposta['persone'])))->toBe(3600)
        ->and($picco)->toBeLessThan(40.0)->and($secondi)->toBeLessThan(10.0);
});
