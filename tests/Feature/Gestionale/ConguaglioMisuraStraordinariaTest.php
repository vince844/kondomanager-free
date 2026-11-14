<?php

/**
 * La misura del conguaglio con una gestione straordinaria di molte fatture a competenze diverse: 3.000 unità, 3.300 persone, le 30 voci
 * dell'ordinaria sulla stessa tabella e 100 fatture straordinarie, ognuna con la sua competenza scritta (un giorno diverso). Ogni competenza diversa
 * avrebbe i suoi pesi; i giorni senza un passaggio in mezzo li riusano (qui i passaggi sono tutti al 01/07: due gruppi). Il peso della Sintesi conta
 * le competenze diverse, 3.000 × (1 + 100), sopra la soglia del riquadro: la Sintesi non legge; la scheda legge, e dice i secondi.
 *
 * Misure del 13/11/2026 (Apple M1 Pro, SQLite in memoria): la Sintesi 0,1–0,2 s e 23,4 MB in più; la scheda, prima del riuso fra i giorni, 83,7 s e
 * 231,0 MB in più (i titolari e i giorni riusati di ogni competenza restavano in memoria); dopo, 4,2 s e 49,0 MB.
 */

use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/ConguaglioMisuraScenario.php';

uses(RefreshDatabase::class);

const CGMS_FATTURE = 100;
const CGMS_MEGA = 64;
const CGMS_SECONDI_SCHEDA = 15.0;
const CGMS_SECONDI_SINTESI = 6.0;

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    $this->travelTo('2027-01-10 10:00:00');
});

it('100 fatture straordinarie a competenze diverse su 3.000 unità: la Sintesi rimanda alla scheda senza leggere, la scheda legge nella memoria e dice i secondi', function () {
    $s = cgmCondominio(false, CGMS_FATTURE);
    expect(app(ConguaglioDelRendiconto::class)->peso($s['e']))->toBe(CGM_UNITA * (1 + CGMS_FATTURE))->toBeGreaterThan(ConguaglioDelRendiconto::SOGLIA_RIQUADRO);

    [$r, $secondi, $mega] = cgmMisura($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s, 'Sintesi, 100 fatture straordinarie');
    expect($r->assertOk()->viewData('page')['props']['sintesi']['riquadri']['conguaglio']['cifre'])->toBeNull()
        ->and($secondi)->toBeLessThan(CGMS_SECONDI_SINTESI)->and($mega)->toBeLessThan(CGMS_MEGA);

    [$r, $secondi, $mega] = cgmMisura($this, 'admin.gestionale.esercizi.rendiconto.conguaglio', $s, 'Scheda, 100 fatture straordinarie');
    $p = $r->assertOk()->viewData('page')['props']['conguaglio'];
    // La spesa straordinaria è tutta divisa: le uscite della gestione sono la somma delle fatture.
    $straordinaria = collect($p['gestioni'])->firstWhere('tipo', 'straordinaria');
    expect($straordinaria['totali']['a'])->toBe((int) array_sum(array_map(fn ($f) => 100000 + $f * 101, range(0, CGMS_FATTURE - 1))))
        ->and($p['secondi'])->toBeGreaterThan(0.0)
        ->and($secondi)->toBeLessThan(CGMS_SECONDI_SCHEDA)->and($mega)->toBeLessThan(CGMS_MEGA);
});
