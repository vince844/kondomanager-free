<?php

/**
 * Il tetto delle due stampe del conguaglio, misurato con le loro rotte: il conguaglio per persona (una riga per persona e unità) e il consuntivo per
 * tabella (una riga per unità e persona), su 1.000 e 3.000 unità (1.100 e 3.300 persone), 30 voci sulla stessa tabella. La memoria è il picco della
 * richiesta oltre la memoria di prima (il condominio di prova resta fuori); il tetto del controller la regge con il margine dell'85 %.
 */

use App\Http\Controllers\Gestionale\Movimenti\RendicontoConguaglioController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/ConguaglioMisuraScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    $this->travelTo('2027-01-10 10:00:00');
});

it('le due stampe del conguaglio stanno nel loro tetto', function (int $unita) {
    $s = cgmCondominio(false, 0, $unita, CGM_VOCI);
    // Il programma avviato prima della richiesta, misurato con le richieste vere del controller: la base misurata qui è solo la parte della stampa.
    $avviato = 36.5;
    foreach (['admin.gestionale.esercizi.rendiconto.conguaglio.stampa' => ['per persona', RendicontoConguaglioController::BASE_MB_PER_PERSONA + RendicontoConguaglioController::MB_PER_RIGA_PER_PERSONA * $s['persone']],
        'admin.gestionale.esercizi.rendiconto.conguaglio.per-tabella.stampa' => ['per tabella', RendicontoConguaglioController::BASE_MB_PER_TABELLA
            + RendicontoConguaglioController::MB_PER_CELLA_PER_TABELLA * $s['persone'] * RendicontoConguaglioController::celleDellaRiga(1)]] as $rotta => [$nome, $formula]) {
        [$r, $secondi, $mega] = cgmMisura($this, $rotta, $s, "stampa {$nome}, {$s['persone']} righe");
        expect($r->assertOk()->headers->get('Content-Type'))->toBe('application/pdf')
            // La formula del tetto sta sopra la misura: la stampa non parte se non ci sta.
            ->and($avviato + $mega)->toBeLessThan($formula);
    }
})->with([1000, 3000]);

it('la formula del tetto sta sopra ogni misura vera scritta nel controller, per righe e per tabelle; i tetti con 128M e 256M', function () {
    $formula = fn (string $stampa, int $righe, int $tabelle) => $stampa === 'per_persona'
        ? RendicontoConguaglioController::BASE_MB_PER_PERSONA + RendicontoConguaglioController::MB_PER_RIGA_PER_PERSONA * $righe
        : RendicontoConguaglioController::BASE_MB_PER_TABELLA + RendicontoConguaglioController::MB_PER_CELLA_PER_TABELLA * $righe * RendicontoConguaglioController::celleDellaRiga($tabelle);
    $sotto = [];
    foreach (RendicontoConguaglioController::MISURE as [$stampa, $righe, $tabelle, $mb]) {
        if ($formula($stampa, $righe, $tabelle) < $mb) {
            $sotto[] = "{$stampa}, {$righe} righe, {$tabelle} tabelle: formula ".round($formula($stampa, $righe, $tabelle), 1)." MB, misura {$mb} MB";
        }
    }

    expect($sotto)->toBe([])
        ->and(count(array_unique(array_column(array_filter(RendicontoConguaglioController::MISURE, fn ($m) => $m[0] === 'per_tabella'), 2))))->toBe(3)
        ->and([RendicontoConguaglioController::righePerPersonaStampabiliCon('128M'), RendicontoConguaglioController::righePerPersonaStampabiliCon('256M')])->toBe([2811, 9211])
        ->and([RendicontoConguaglioController::celleStampabiliCon('128M'), RendicontoConguaglioController::celleStampabiliCon('256M')])->toBe([25837, 84648])
        // 2.530 righe: con una tabella ci stanno (25.300 celle); con otto no (43.010), dove una richiesta vera con 128M moriva dentro mPDF.
        ->and(2530 * RendicontoConguaglioController::celleDellaRiga(1))->toBeLessThanOrEqual(25837)
        ->and(2530 * RendicontoConguaglioController::celleDellaRiga(8))->toBeGreaterThan(25837);
});

it('il consuntivo per tabella con otto tabelle oltre il tetto non parte: torna alla scheda con le righe e le tabelle', function () {
    $s = cgmCondominio(true, 0, 30, 8);
    // 33 righe con 8 tabelle: 561 celle; con 72M ne stanno 108.
    (new ReflectionProperty(RendicontoConguaglioController::class, 'limiteMemoria'))->setValue(null, '72M');
    try {
        $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.per-tabella.stampa', [$s['c'], $s['e']]));
    } finally {
        (new ReflectionProperty(RendicontoConguaglioController::class, 'limiteMemoria'))->setValue(null, null);
    }

    $r->assertRedirect(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c']->id, $s['e']->id]));
    expect(session('message')['message'] ?? '')->toContain('righe su 8 tabelle')->toContain('più di quante il server riesce a stampare');
});
