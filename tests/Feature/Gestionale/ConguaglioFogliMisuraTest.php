<?php

/**
 * I fogli della persona in un archivio, a pieno tetto: 201 unità e 222 persone (un'unità su dieci passa di mano il 01/07), una voce. Tutte le persone
 * sono più di 200: l'archivio non parte, e dice di scegliere le persone di una pagina dell'elenco. Le persone della prima pagina della scheda sono 200:
 * l'archivio ha 200 fogli e l'elenco degli indirizzi, dentro il tempo e la memoria scritti qui.
 *
 * Misura del 13/11/2026 (Apple M1 Pro, SQLite in memoria): 15,9 s e 19,8 MB in più per i 200 fogli (una voce ciascuno; i PDF vanno sul disco uno alla
 * volta, e la memoria tiene un foglio).
 */

use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/ConguaglioMisuraScenario.php';

uses(RefreshDatabase::class);

const CGF_SECONDI = 45.0;
const CGF_MEGA = 64;

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    $this->travelTo('2027-01-10 10:00:00');
});

it('oltre 200 persone l\'archivio dice di scegliere una pagina; le 200 persone della prima pagina stanno in un archivio, nel tempo e nella memoria', function () {
    $s = cgmCondominio(false, 0, 201, 1);
    expect($s['persone'])->toBe(222);

    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.fogli', [$s['c'], $s['e']]).'?chi=tutte')
        ->assertRedirect(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))
        ->assertSessionHas('message', fn ($m) => $m['message'] === 'Le persone scelte sono 222: in un archivio ne stanno al massimo 200. Scegli le persone di una pagina dell\'elenco.');

    gc_collect_cycles();
    memory_reset_peak_usage();
    $prima = memory_get_usage();
    $inizio = hrtime(true);
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.fogli', [$s['c'], $s['e']]).'?chi=tutte&pagina=1')->assertOk();
    $secondi = (hrtime(true) - $inizio) / 1e9;
    $mega = (memory_get_peak_usage() - $prima) / 1048576;
    fwrite(STDERR, sprintf("\n[misura] 200 fogli in un archivio: %.2f s, %.1f MB in più\n", $secondi, $mega));

    $zip = new ZipArchive();
    $zip->open($r->baseResponse->getFile()->getPathname());
    $pdf = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $pdf += str_ends_with($zip->getNameIndex($i), '.pdf') ? 1 : 0;
    }
    $indirizzi = $zip->getFromName('Indirizzi email.txt');
    $zip->close();

    expect($pdf)->toBe(ConguaglioDelRendiconto::FOGLI_PER_RICHIESTA)
        ->and(substr_count((string) $indirizzi, ' — 1 foglio — '))->toBe(200)
        ->and($secondi)->toBeLessThan(CGF_SECONDI)->and($mega)->toBeLessThan(CGF_MEGA);
});
