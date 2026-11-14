<?php

/**
 * La misura del conguaglio con le fatture dell'ordinaria che dicono la competenza: 3.000 unità, 3.300 persone (un'unità su dieci passa di mano, ognuna in
 * un giorno diverso dell'anno), le 30 voci dell'ordinaria sulla stessa tabella e 100 fatture sulla prima voce, ognuna con la sua competenza scritta (un
 * giorno diverso). Ogni competenza scritta avrebbe i suoi pesi; i pesi di un'unità si riusano nei giorni in cui i suoi titolari sono gli stessi, e si
 * rifanno solo per le unità con un passaggio in mezzo. Il peso della Sintesi conta le competenze scritte, 3.000 × (1 + 100), sopra la soglia del
 * riquadro: la Sintesi non legge; la scheda e i controlli leggono.
 *
 * Misure del 14/11/2026 (Apple M1 Pro, SQLite in memoria): la scheda 7,0 s e 55,6 MB in più, i controlli 6,6 s e 51,0 MB. Quando i pesi di un giorno si
 * riusavano solo fra i giorni senza nessun passaggio nel condominio, la scheda chiedeva 74,9 s. La prima forma della spesa con la competenza scritta, una
 * voce per unità e per fattura, chiedeva alla scheda 369 MB: ora una per unità e voce, sommata.
 */

use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/ConguaglioMisuraScenario.php';

uses(RefreshDatabase::class);

const CGMC_FATTURE = 100;
const CGMC_MEGA = 64;
const CGMC_SECONDI_SCHEDA = 15.0;
const CGMC_SECONDI_CONTROLLI = 20.0;

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    $this->travelTo('2027-01-10 10:00:00');
});

it('100 fatture dell\'ordinaria con la competenza scritta su 3.000 unità, con i passaggi sparsi nell\'anno: la Sintesi rimanda alla scheda, la scheda e i controlli leggono nel tempo e nella memoria', function () {
    $s = cgmCondominio(false);
    // I passaggi in giorni diversi, come in un condominio vero: chi esce il giorno g (dal 10/01), chi entra il giorno dopo. Tutti al 01/07, i pesi di un giorno
    // si riusavano fra due soli gruppi di giorni; sparsi, ogni fattura datata li rifaceva per 3.300 persone (76 s).
    foreach (DB::table('anagrafica_immobile')->where('data_fine', '2026-06-30')->orderBy('immobile_id')->get(['id', 'immobile_id']) as $k => $r) {
        $g = \Carbon\CarbonImmutable::parse('2026-01-10')->addDays($k);
        DB::table('anagrafica_immobile')->where('id', $r->id)->update(['data_fine' => $g->format('Y-m-d')]);
        DB::table('anagrafica_immobile')->where('immobile_id', $r->immobile_id)->where('data_inizio', '2026-07-01')->update(['data_inizio' => $g->addDay()->format('Y-m-d')]);
    }
    $adesso = now()->format('Y-m-d H:i:s');
    $costi = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('codice', 'CGM-COSTI')->value('id');
    $debiti = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'debiti_fornitori')->value('id');
    $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Impresa Manutenzioni', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100,
        'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => $adesso, 'updated_at' => $adesso]);
    for ($f = 0; $f < CGMC_FATTURE; $f++) {
        $giorno = \Carbon\CarbonImmutable::parse('2026-02-01')->addDays($f * 3)->format('Y-m-d');
        cgmSpesa($s['c'], $s['e'], $s['g']->id, $s['voci'][0], 100000 + $f * 101, $giorno, $costi, $debiti, ['fornitore' => $fornitore, 'competenza' => $giorno], $adesso);
    }
    $fatture = (int) array_sum(array_map(fn ($f) => 100000 + $f * 101, range(0, CGMC_FATTURE - 1)));
    $voci = (int) array_sum(array_map(fn ($v) => 1234567 + $v * 98765, range(1, CGM_VOCI)));

    expect(app(ConguaglioDelRendiconto::class)->peso($s['e']))->toBe(CGM_UNITA * (1 + CGMC_FATTURE))->toBeGreaterThan(ConguaglioDelRendiconto::SOGLIA_RIQUADRO);

    [$r, $secondi, $mega] = cgmMisura($this, 'admin.gestionale.esercizi.rendiconto.conguaglio', $s, 'Scheda, 100 fatture con la competenza scritta');
    $p = $r->assertOk()->viewData('page')['props']['conguaglio'];
    // La spesa è tutta divisa: le voci più le fatture, nessuna uscita che nessuno paga.
    expect($p['condominio']['a'])->toBe($voci + $fatture)->and($p['condominio']['non_attribuite'])->toBe(0)
        ->and($secondi)->toBeLessThan(CGMC_SECONDI_SCHEDA)->and($mega)->toBeLessThan(CGMC_MEGA);

    [$r, $secondi, $mega] = cgmMisura($this, 'admin.gestionale.esercizi.rendiconto.controlli', $s, 'Controlli, 100 fatture con la competenza scritta');
    $controlli = collect($r->assertOk()->viewData('page')['props']['controlli'])->keyBy('id');
    expect($controlli['CG']['sinistra'])->toBe($voci + $fatture)->and($controlli['CU']['esito'])->toBe('quadra')
        ->and($secondi)->toBeLessThan(CGMC_SECONDI_CONTROLLI)->and($mega)->toBeLessThan(CGMC_MEGA);
});
