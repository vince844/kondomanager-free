<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Scala;
use App\Models\Tabella;
use App\Services\Assemblee\Sala\FotografiaDellaSeduta;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * La fotografia della seduta (R1): le unità del perimetro con i titolari attivi **al giorno della seduta**, i millesimi della tabella dell'assemblea e di
 * ogni tabella dei punti in centomillesimi interi, le teste, per ogni punto chi vota e i totali; il JSON canonico con l'impronta. Prima dell'apertura la
 * stessa lettura serve provvisoria (il foglio della sala, il quinto delle deleghe ricevute).
 *
 * Che cosa non copre: le teste di ogni caso (`TesteDellaSalaTest`); la fotografia scritta all'apertura (`StatiDellaSalaTest`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la seconda convocazione: 26 teste, 1.000,00 millesimi, i comproprietari una testa, Rossi una testa con l\'Int. 3 e il box; l\'impronta del JSON canonico', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);

    $f = app(FotografiaDellaSeduta::class)->leggi($a, SalaDiViaRoma12::seduta($a));
    $teste = collect($f['costituzione']['teste'])->keyBy('nome');
    expect($f['costituzione']['totale_teste'])->toBe(26)
        ->and($f['costituzione']['totale_valore'])->toBe(100000000)
        ->and($f['giorno'])->toBe('2026-12-15')
        ->and($f['convocazione'])->toBe('seconda')
        ->and($f['provvisoria'])->toBeFalse()
        ->and($teste['Rossi Mario']['millesimi'])->toBe(6500000)
        ->and($teste['Russo Anna e Russo Marco']['millesimi'])->toBe(7000000)
        ->and($teste['Neri Carla e Neri Paolo']['millesimi'])->toBe(8000000)
        ->and($teste->has('Ferri Luca'))->toBeFalse()
        ->and(FotografiaDellaSeduta::impronta($f))->toBe(hash('sha256', FotografiaDellaSeduta::canonico($f)))
        ->and(FotografiaDellaSeduta::canonico($f))->toBe(FotografiaDellaSeduta::canonico(json_decode(FotografiaDellaSeduta::canonico($f), true)));
});

it('per ogni punto chi vota: l\'Int. 9 con Neri Carla sul rendiconto e Neri Paolo sul portone; l\'Int. 12 con il conduttore sul riscaldamento', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $f = app(FotografiaDellaSeduta::class)->leggi($a, SalaDiViaRoma12::seduta($a));
    $punto = fn (int $n) => collect($f['punti'])->firstWhere('numero', $n);
    $testaDi = fn (array $p, string $chi) => collect($p['teste'])->first(fn ($t) => in_array($s->persone[$chi]->id, $t['persone'], true));

    expect($testaDi($punto(1), 'carla')['millesimi'])->toBe(8000000)->and($testaDi($punto(1), 'paolo'))->toBeNull()
        ->and($testaDi($punto(4), 'paolo')['millesimi'])->toBe(8000000)->and($testaDi($punto(4), 'carla'))->toBeNull()
        ->and($testaDi($punto(5), 'luca')['millesimi'])->toBe(9000000)->and($testaDi($punto(5), 'rita'))->toBeNull()
        ->and($testaDi($punto(1), 'rita')['millesimi'])->toBe(9000000)
        ->and($punto(4)['amministrazione'])->toBe('straordinaria')
        ->and($punto(1)['totale_teste'])->toBe(26)->and($punto(1)['totale_valore'])->toBe(100000000);
});

it('il passaggio fra la prima e la seconda: chi entra il 15/12 è nella fotografia della seconda, chi esce in quella della prima', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    DB::table('anagrafica_immobile')->where('immobile_id', $s->unita['7']->id)->where('anagrafica_id', $s->persone['gallo']->id)
        ->update(['data_fine' => '2026-12-14']);
    $s->titolare('7', 'verdi', 'proprietario', 100, '2026-12-15');
    $s->persone['verdi']->forceFill(['nome' => 'Verdi Sara'])->saveQuietly();

    $nomi = fn ($f) => collect($f['costituzione']['teste'])->pluck('nome')->all();
    $prima = app(FotografiaDellaSeduta::class)->leggi($a, SalaDiViaRoma12::seduta($a, 'prima'));
    $seconda = app(FotografiaDellaSeduta::class)->leggi($a, SalaDiViaRoma12::seduta($a, 'seconda'));
    expect($nomi($prima))->toContain('Gallo Enzo')->not->toContain('Verdi Sara')
        ->and($nomi($seconda))->toContain('Verdi Sara')->not->toContain('Gallo Enzo')
        ->and(FotografiaDellaSeduta::impronta($prima))->not->toBe(FotografiaDellaSeduta::impronta($seconda));
});

it('provvisoria prima dell\'apertura, e la tabella che non fa 1.000 avvisa: il totale è la somma delle quote', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    DB::table('quote_tabella')->where('tabella_id', $s->tabelle['Proprietà']->id)->where('immobile_id', $s->unita['30']->id)->update(['valore' => 26.15]);

    $f = app(FotografiaDellaSeduta::class)->provvisoria($a);
    expect($f['provvisoria'])->toBeTrue()
        ->and($f['giorno'])->toBe('2026-12-14')
        ->and($f['costituzione']['totale_valore'])->toBe(99840000)
        ->and($f['avvisi'])->toContain('La tabella del valore fa 998,40 millesimi, non 1.000: i conti usano la somma delle quote.');
});

it('il punto della sola scala con la sua tabella: le teste e il valore sono quelli della scala', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $scala = Scala::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    $ascensore = Tabella::create(['condominio_id' => $s->c->id, 'nome' => 'Ascensore B', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    foreach (range(30, 39) as $i) {
        $s->unita[(string) $i]->forceFill(['scala_id' => $scala->id])->saveQuietly();
        DB::table('quote_tabella')->insert(['tabella_id' => $ascensore->id, 'immobile_id' => $s->unita[(string) $i]->id, 'valore' => 100, 'created_at' => now(),
            'updated_at' => now()]);
    }
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s, [
        ['testo' => 'Rendiconto 2025', 'materia' => 'rendiconto'],
        ['testo' => 'Funi dell\'ascensore della scala B, € 8.200,00', 'materia' => 'lavori', 'ambito' => 'scala', 'scala_id' => $scala->id,
            'tabella_id' => $ascensore->id],
    ]), $s);

    $p = collect(app(FotografiaDellaSeduta::class)->leggi($a, SalaDiViaRoma12::seduta($a))['punti'])->firstWhere('numero', 2);
    expect($p['totale_teste'])->toBe(10)
        ->and($p['totale_valore'])->toBe(100000000)
        ->and($p['perimetro'])->toBe('Solo la scala B')
        ->and($p['tabella'])->toBe(['id' => $ascensore->id, 'nome' => 'Ascensore B'])
        ->and(collect($p['teste'])->pluck('millesimi')->unique()->all())->toBe([10000000]);
});
