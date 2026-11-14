<?php

/**
 * La misura del conguaglio sulle pagine, a 3.000 unità, 3.300 persone e 30 voci: il tempo e la memoria della Sintesi (con il riquadro), dei
 * controlli e della scheda «Conguaglio», ognuna con la sua richiesta e una lettura sola. Le soglie sono scritte qui, accanto alle misure del 13/11/2026
 * su un Apple M1 Pro, SQLite in memoria (il condominio di prova resta fuori dalla memoria misurata).
 *
 * - Le voci sulla stessa tabella: il peso è 3.000 (unità × una tabella), sotto la soglia del riquadro; la Sintesi legge il conguaglio.
 * - Una tabella per voce: il peso è 90.000, sopra la soglia; la Sintesi non legge, e il riquadro dice di aprire la scheda. La scheda legge e dice i
 *   secondi.
 *
 * Misure: la Sintesi con il riquadro letto 1,64 s e 50,9 MB in più; i controlli 0,02 s e 0,5 MB senza il conguaglio, 1,50 s e 46,8 MB da quando lo leggono
 * (i controlli del conguaglio, allora tre, misura del 13/11/2026 sera), e con una tabella per voce 14,5 s e 48,0 MB; la scheda 1,98 s
 * e 45,5 MB (tabella comune), 14,91 s e 48,7 MB (una tabella per voce); la Sintesi senza la lettura 0,03 s. Senza piani e senza rate: le letture delle
 * quote e delle posizioni, a 3.000 persone × 12 rate, costano in più 2,2 s e 8,1 MB e 0,5 s e 9,0 MB (la misura della lettura delle quote).
 */

use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/ConguaglioMisuraScenario.php';

uses(RefreshDatabase::class);

/** Le soglie: i MB in più del picco (il limite di una richiesta è 128 MB, e il resto serve al programma) e i secondi. */
const CGM_MEGA = 64;
const CGM_SECONDI_SINTESI_COMUNE = 8.0;
const CGM_SECONDI_SINTESI_PER_VOCE = 6.0;
const CGM_SECONDI_CONTROLLI = 6.0;
const CGM_SECONDI_SCHEDA_COMUNE = 8.0;
const CGM_SECONDI_SCHEDA_PER_VOCE = 30.0;

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    $this->travelTo('2027-01-10 10:00:00');
});

it('30 voci sulla stessa tabella: la Sintesi con il riquadro letto, i controlli e la scheda stanno nel tempo e nella memoria', function () {
    $s = cgmCondominio(false);
    expect(app(ConguaglioDelRendiconto::class)->peso($s['e']))->toBe(CGM_UNITA)->toBeLessThanOrEqual(ConguaglioDelRendiconto::SOGLIA_RIQUADRO);

    [$r, $secondi, $mega] = cgmMisura($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s, 'Sintesi, tabella comune');
    $riquadro = $r->assertOk()->viewData('page')['props']['sintesi']['riquadri']['conguaglio'];
    expect($riquadro['cifre'])->not->toBeNull()
        ->and($riquadro['cifre']['a'])->toBe((int) array_sum(array_map(fn ($v) => 1234567 + $v * 98765, range(1, CGM_VOCI))))
        ->and($secondi)->toBeLessThan(CGM_SECONDI_SINTESI_COMUNE)->and($mega)->toBeLessThan(CGM_MEGA);

    [$r, $secondi, $mega] = cgmMisura($this, 'admin.gestionale.esercizi.rendiconto.controlli', $s, 'Controlli, tabella comune');
    $r->assertOk();
    expect($secondi)->toBeLessThan(CGM_SECONDI_CONTROLLI)->and($mega)->toBeLessThan(CGM_MEGA);

    [$r, $secondi, $mega] = cgmMisura($this, 'admin.gestionale.esercizi.rendiconto.conguaglio', $s, 'Scheda, tabella comune');
    $p = $r->assertOk()->viewData('page')['props']['conguaglio'];
    expect($p['pagine']['righe'])->toBe($s['persone'])
        ->and(count($p['righe']))->toBe(ConguaglioDelRendiconto::RIGHE_PER_PAGINA)
        ->and($p['pagine']['dopo'])->toBe($s['persone'] - ConguaglioDelRendiconto::RIGHE_PER_PAGINA)
        ->and($secondi)->toBeLessThan(CGM_SECONDI_SCHEDA_COMUNE)->and($mega)->toBeLessThan(CGM_MEGA);
});

it('una tabella per voce: la Sintesi non legge e rimanda alla scheda, la scheda legge e dice i secondi; tutte nella memoria', function () {
    $s = cgmCondominio(true);
    expect(app(ConguaglioDelRendiconto::class)->peso($s['e']))->toBe(CGM_UNITA * CGM_VOCI)->toBeGreaterThan(ConguaglioDelRendiconto::SOGLIA_RIQUADRO);

    [$r, $secondi, $mega] = cgmMisura($this, 'admin.gestionale.esercizi.rendiconto.sintesi', $s, 'Sintesi, una tabella per voce');
    $riquadro = $r->assertOk()->viewData('page')['props']['sintesi']['riquadri']['conguaglio'];
    expect($riquadro['cifre'])->toBeNull()->and($riquadro['frase'])->toBe(ConguaglioDelRendiconto::FRASE_RIMANDA)
        ->and($secondi)->toBeLessThan(CGM_SECONDI_SINTESI_PER_VOCE)->and($mega)->toBeLessThan(CGM_MEGA);

    [$r, $secondi, $mega] = cgmMisura($this, 'admin.gestionale.esercizi.rendiconto.conguaglio', $s, 'Scheda, una tabella per voce');
    $p = $r->assertOk()->viewData('page')['props']['conguaglio'];
    expect($p['secondi'])->toBeGreaterThan(0.0)
        ->and($secondi)->toBeLessThan(CGM_SECONDI_SCHEDA_PER_VOCE)->and($mega)->toBeLessThan(CGM_MEGA);

    // I controlli leggono il conguaglio sempre, come la scheda: quelli del conguaglio hanno le cifre anche sopra la soglia della Sintesi.
    [$r, $secondi, $mega] = cgmMisura($this, 'admin.gestionale.esercizi.rendiconto.controlli', $s, 'Controlli, una tabella per voce');
    $controlli = collect($r->assertOk()->viewData('page')['props']['controlli'])->keyBy('id');
    expect($controlli['CU']['esito'])->toBe('quadra')->and($controlli['CU']['destra'])->toBe(CGM_UNITA)
        ->and($secondi)->toBeLessThan(CGM_SECONDI_SCHEDA_PER_VOCE)->and($mega)->toBeLessThan(CGM_MEGA);
});
