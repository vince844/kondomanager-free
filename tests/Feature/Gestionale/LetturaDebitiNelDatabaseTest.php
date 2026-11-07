<?php

/**
 * La lettura comune dei debiti sommata nel database (dalla 1.12.0-beta.4), contro la seconda fonte: la lettura di prima, che caricava tutte
 * le righe del giornale in memoria ({@see lcdLetturaInMemoria()}), sugli scenari della lettura e a date passate. Le chiavi si sommano per
 * (mastro, persona, unità, rata), e per gestione solo le righe senza rata: un incasso con righe di due gestioni sulla stessa rata non divide
 * la chiave. Le due differenze volute: una chiave a zero non c'è più, e una persona con tutte le chiavi a zero e nessun credito di oggi non
 * è più fra le persone del condominio.
 *
 * Centesimi.
 */

use App\Models\Gestione;
use App\Models\User;
use App\Services\Gestionale\Debiti\LetturaDebiti;
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

it('le tre persone con incassi registrati dopo, un credito e uno storno: la lettura nel database torna con la seconda fonte a ogni data', function () {
    $this->travelTo('2026-10-30 09:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000], 'B' => ['bianca', 1000], 'C' => ['carla', 1000]], 1, 'prima_rata', [], 90000, '2026-11-30', 30);
    $this->travelTo('2026-11-01 10:00:00');
    lcdEmetti($this, $s, [1], '2026-11-01');
    $this->travelTo('2026-12-10 10:00:00');
    $bianca = lcdIncassa($this, $s, 'bianca', [lcdQuota($s, 'bianca', 1) => 30000], '2026-12-10');
    $this->travelTo('2026-12-22 10:00:00');
    lcdIncassa($this, $s, 'carla', [lcdQuota($s, 'carla', 1) => 30000], '2026-12-22', 5000);
    $this->travelTo('2027-01-10 11:00:00');
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 20000], '2026-12-20');
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 5000], '2027-01-03');
    $this->travelTo('2027-01-15 11:00:00');
    lcdStorna($this, $s, $bianca);
    $this->travelTo('2027-01-20 09:00:00');

    foreach (['2026-10-31', '2026-11-30', '2026-12-01', '2026-12-15', '2026-12-31', '2027-01-03', '2027-01-15', '2027-01-20'] as $d) {
        expect(lcdProvaSecondaFonte((int) $s['c']->id, $d))->toBe([], "al {$d}")
            ->and(lcdProvaSecondaFonte((int) $s['c']->id, $d, (int) $s['g']->id))->toBe([], "al {$d}, per gestione");
    }
});

it('Rossi con la rata 0 a credito, una bozza scaduta e le rate emesse: torna con la seconda fonte a ogni data, anche alle 00:30 di Roma', function () {
    $s = lcdRossi($this);
    foreach (['2026-12-01', '2026-12-14', '2026-12-15', '2026-12-16', '2027-01-15'] as $d) {
        expect(lcdProvaSecondaFonte((int) $s['c']->id, $d))->toBe([], "al {$d}");
    }
    $this->travelTo(\Carbon\Carbon::parse('2026-12-15 00:30:00', 'Europe/Rome'));
    expect(lcdProvaSecondaFonte((int) $s['c']->id))->toBe([]);
});

it('un anticipo su una bozza e una parte in più senza quota: righe non emesse e anticipi tornano con la seconda fonte', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 600], 'G' => ['ugo', 200], 'B' => ['bice', 200]], 12, 'prima_rata', [['ugo', 'A', -7000]]);
    lcdEmetti($this, $s, [1, 2], '2026-02-28');
    lcdIncassa($this, $s, 'bice', [lcdQuota($s, 'bice', 1) => 2000], '2026-03-10', 500);
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 5) => 3000], '2026-03-12');

    foreach (['2026-02-27', '2026-02-28', '2026-03-10', '2026-03-20'] as $d) {
        expect(lcdProvaSecondaFonte((int) $s['c']->id, $d))->toBe([], "al {$d}");
    }
});

it('un incasso con righe di due gestioni sulla stessa rata non divide la chiave: saldata, la chiave non c\'è; prima, il residuo resta uno', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1], '2026-02-28');
    $altra = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Straordinaria', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01']);
    $crediti = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'crediti_condomini')->value('id');
    $banca = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'banca')->value('id');
    $rata = lcdRata($s, 1);
    $immobile = (int) $s['immobili']['A']->id;
    $ugo = (int) $s['persone']['ugo']->id;
    // Il versamento della rata 1, metà su una scrittura della gestione del piano e metà su una scrittura di un'altra gestione.
    foreach ([[(int) $s['g']->id, 4000, '2026-03-10'], [(int) $altra->id, 6000, '2026-03-15']] as $n => [$gestione, $importo, $data]) {
        $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $gestione, 'esercizio_id' => $s['e']->id,
            'data_registrazione' => $data, 'data_competenza' => $data, 'numero_protocollo' => "INC-LDB-{$n}", 'causale' => 'Bonifico',
            'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('righe_scritture')->insert([
            ['scrittura_id' => $id, 'conto_contabile_id' => $banca, 'anagrafica_id' => null, 'rata_id' => null, 'immobile_id' => null, 'tipo_riga' => 'dare', 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
            ['scrittura_id' => $id, 'conto_contabile_id' => $crediti, 'anagrafica_id' => $ugo, 'rata_id' => $rata, 'immobile_id' => $immobile, 'tipo_riga' => 'avere', 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    // Al 12/03: dovuto 10000, versato 4000; una riga sola.
    $al12 = lcdLeggi($s, 'ugo', '2026-03-12');
    expect(collect($al12['rate'])->where('rata_id', $rata)->values()->all())->toHaveCount(1)
        ->and(lcdRiga($al12, $s, 'A', 1))->toMatchArray(['dovuto' => 10000, 'versato' => 4000, 'residuo' => 6000, 'credito' => 0]);
    // Oggi la rata è saldata: con la gestione nella chiave sarebbero due righe, una a debito di 10000 e una a credito di 6000.
    $oggi = lcdLeggi($s, 'ugo');
    expect(lcdRiga($oggi, $s, 'A', 1))->toBeNull()
        ->and($oggi['totali'])->toMatchArray(['emesso_non_pagato' => 0, 'crediti' => 0, 'scaduto' => 0, 'netto' => 0])
        ->and(lcdProvaSecondaFonte((int) $s['c']->id))->toBe([])
        ->and(lcdProvaSecondaFonte((int) $s['c']->id, '2026-03-12'))->toBe([]);
});

it('le due differenze volute: la chiave saldata non c\'è più, e chi ha solo chiavi a zero e nessun credito di oggi non è fra le persone', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1], '2026-02-28');
    lcdIncassa($this, $s, 'bice', [lcdQuota($s, 'bice', 1) => 5000], '2026-03-10');

    $tutte = app(LetturaDebiti::class)->perCondominio((int) $s['c']->id);
    expect(array_keys($tutte))->toBe([(int) $s['persone']['ugo']->id])
        // La seconda fonte ha ancora Bice, con la sua chiave a zero.
        ->and(array_keys(lcdLetturaInMemoria((int) $s['c']->id, '2026-03-20')))->toEqualCanonicalizing([(int) $s['persone']['ugo']->id, (int) $s['persone']['bice']->id])
        ->and(lcdProvaSecondaFonte((int) $s['c']->id))->toBe([])
        // La lettura di una persona sola la dà comunque, vuota.
        ->and(lcdLeggi($s, 'bice')['rate'])->toBe([])
        ->and(lcdLeggi($s, 'bice')['totali'])->toMatchArray(['emesso_non_pagato' => 0, 'netto' => 0]);
});
