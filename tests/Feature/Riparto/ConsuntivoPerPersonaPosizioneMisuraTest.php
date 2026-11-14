<?php

/**
 * La misura delle letture del consuntivo per persona che crescono con il condominio: le quote di tutti i piani per persona e unità, e la
 * posizione per gestione e unità, a 3.000 persone con 12 rate emesse e non pagate (36.000 quote). La memoria è quella in più del picco sulla
 * partenza, su SQLite in memoria; le soglie sono scritte nel test.
 */

use App\Models\User;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/ConsuntivoPerPersonaPosizioneScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Via Verdi emessa, poi N unità e persone in più con R rate emesse e non pagate, copiando le righe vere della prima quota di Neri. */
function cpcmGrande($test, int $unita, int $rate): array
{
    $s = cpcViaVerdi($test);
    $test->travelTo('2026-01-15 10:00:00');
    lcdEmetti($test, $s, [1, 2, 3, 4], '2026-01-15');
    $adesso = now()->format('Y-m-d H:i:s');
    $rata1 = (array) DB::table('rate')->where('id', lcdRata($s, 1))->first();
    $quota = (array) DB::table('rate_quote')->where('id', lcdQuota($s, 'Neri', 1))->first();
    $emissione = (array) DB::table('scritture_contabili')->where('id', $quota['scrittura_contabile_id'])->first();
    $rigaDare = (array) DB::table('righe_scritture')->where('scrittura_id', $emissione['id'])->where('tipo_riga', 'dare')->first();
    $rigaAvere = (array) DB::table('righe_scritture')->where('scrittura_id', $emissione['id'])->where('tipo_riga', 'avere')->first();

    $righe = [];
    for ($u = 1; $u <= $unita; $u++) {
        $righe[] = ['condominio_id' => $s['c']->id, 'codice_immobile' => "CPCM-{$u}", 'nome' => "M{$u}", 'interno' => (string) $u, 'created_at' => $adesso, 'updated_at' => $adesso];
    }
    foreach (array_chunk($righe, 500) as $b) {
        DB::table('immobili')->insert($b);
    }
    $immobili = DB::table('immobili')->where('codice_immobile', 'like', 'CPCM-%')->orderBy('id')->pluck('id')->all();
    $righe = [];
    foreach ($immobili as $i => $iid) {
        $righe[] = ['nome' => "P{$i}", 'email' => "cpcm{$i}@t.it", 'indirizzo' => 'x', 'codice_fiscale' => 'CPCM' . str_pad((string) $i, 13, '0', STR_PAD_LEFT), 'created_at' => $adesso, 'updated_at' => $adesso];
    }
    foreach (array_chunk($righe, 500) as $b) {
        DB::table('anagrafiche')->insert($b);
    }
    $persone = DB::table('anagrafiche')->where('email', 'like', 'cpcm%@t.it')->orderBy('id')->pluck('id')->all();
    $righe = [];
    foreach ($immobili as $i => $iid) {
        $righe[] = ['anagrafica_id' => $persone[$i], 'immobile_id' => $iid, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01', 'created_at' => $adesso, 'updated_at' => $adesso];
    }
    foreach (array_chunk($righe, 500) as $b) {
        DB::table('anagrafica_immobile')->insert($b);
    }

    for ($n = 5; $n <= $rate; $n++) {
        $r = $rata1;
        unset($r['id']);
        $r['numero_rata'] = $n;
        DB::table('rate')->insert($r);
    }
    $rateIds = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', '>=', 1)->orderBy('numero_rata')->pluck('id')->all();
    foreach ($rateIds as $rid) {
        $e = $emissione;
        unset($e['id']);
        $e['numero_protocollo'] = 'CPCM-' . $rid;
        $eid = DB::table('scritture_contabili')->insertGetId($e);
        $q = [];
        $rs = [];
        foreach ($immobili as $i => $iid) {
            $x = $quota;
            unset($x['id']);
            $x['rata_id'] = $rid;
            $x['anagrafica_id'] = $persone[$i];
            $x['immobile_id'] = $iid;
            $x['scrittura_contabile_id'] = $eid;
            $x['importo_pagato'] = 0;
            $q[] = $x;
            $d = $rigaDare;
            unset($d['id']);
            $d['scrittura_id'] = $eid;
            $d['anagrafica_id'] = $persone[$i];
            $d['immobile_id'] = $iid;
            $d['rata_id'] = $rid;
            $rs[] = $d;
        }
        foreach (array_chunk($q, 200) as $b) {
            DB::table('rate_quote')->insert($b);
        }
        foreach (array_chunk($rs, 300) as $b) {
            DB::table('righe_scritture')->insert($b);
        }
        $a = $rigaAvere;
        unset($a['id']);
        $a['scrittura_id'] = $eid;
        $a['importo'] = (int) $quota['importo'] * count($immobili);
        DB::table('righe_scritture')->insert($a);
    }

    return $s;
}

function cpcmMisura(string $nome, callable $fn): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $t = microtime(true);
    $esito = $fn();
    $secondi = microtime(true) - $t;
    $mb = (memory_get_peak_usage() - $base) / 1048576;
    fwrite(STDERR, sprintf("\nMISURA %s: %.1f MB in più, %.2f s\n", $nome, $mb, $secondi));

    return [$mb, $secondi];
}

it('a 3.000 persone per 12 rate le quote dei piani e le posizioni per unità stanno sotto i 16 MB in più ciascuna, con le cifre della lettura intera', function () {
    $s = cpcmGrande($this, 3000, 12);
    $this->travelTo('2026-12-31 12:00:00');
    $cid = (int) $s['c']->id;
    $l = app(LetturaDebiti::class);

    [$mbQuote, $sQuote] = cpcmMisura('quoteDeiPianiPerUnita', fn () => $l->quoteDeiPianiPerUnita($cid, [(int) $s['piano']->id], '2026-12-31'));
    [$mbPosizioni, $sPosizioni] = cpcmMisura('posizioniPerUnita', fn () => $l->posizioniPerUnita($cid, '2026-12-31'));

    // Le cifre: ogni persona nuova ha 12 quote come la prima di Neri (500,00), tutte emesse e non pagate.
    $quote = $l->quoteDeiPianiPerUnita($cid, [(int) $s['piano']->id], '2026-12-31');
    $posizioni = $l->posizioniPerUnita($cid, '2026-12-31');
    $netto = 0;
    // L'oracolo a gruppi di persone (le stesse persone e cifre della lettura intera), perché il file regga anche con 128 MB.
    foreach ($l->perCondominioABlocchi($cid, '2026-12-31') as $blocco) {
        foreach ($blocco as $persona) {
            $netto += $persona['totali']['netto'];
        }
    }
    $somma = 0;
    foreach ($posizioni as $perGestione) {
        foreach ($perGestione as $perUnita) {
            $somma += array_sum($perUnita);
        }
    }
    $spesa = 0;
    foreach ($quote as $perGestione) {
        foreach ($perGestione as $perUnita) {
            foreach ($perUnita as $q) {
                $spesa += $q['spesa'];
            }
        }
    }
    expect(count($quote))->toBe(3003)->and($somma)->toBe($netto)->and($spesa)->toBe(400000 + 3000 * 12 * 50000)
        ->and($mbQuote)->toBeLessThan(16.0)->and($mbPosizioni)->toBeLessThan(16.0)
        ->and($sQuote)->toBeLessThan(10.0)->and($sPosizioni)->toBeLessThan(10.0);
});
