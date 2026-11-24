<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 113 (decisione 707 (3)): le voci di spesa importate senza il loro conto di partita doppia.
 *
 * L'importazione del bilancio consuntivo scriveva il capitolo e le sue voci senza `conto_contabile_id`: la prima fattura su una di
 * quelle voci si fermava su «Integrità compromessa». La migrazione di dati dà alle voci di spesa senza conto il mastro che darebbe il
 * salvataggio a mano (`CreazioneDellaVoce::contoContabile()`): «Costi per Servizi», o «Compensi Professionisti» per una voce di
 * tipo «professionista». Le voci di entrata, e quelle che un conto già lo hanno, non si toccano.
 */

use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Services\Gestionale\CreazioneDellaVoce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../Gestionale/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

const B14_RIPARA_MIGRAZIONE = '2026_11_23_100000_assegna_il_conto_alle_voci_di_spesa_importate';

/** Un piano dei conti di un condominio con un capitolo e due voci di spesa nate senza il conto, come le scriveva l'importazione. */
function b14RiparaCondominio(): array
{
    $s = be52Condominio();
    $piano = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'nome' => 'Piano dei conti']);
    $nuovo = fn (string $nome, string $tipo, bool $capitolo = false, ?int $padre = null, string $tipoSpesa = 'standard') => DB::table('conti')->insertGetId([
        'piano_conto_id' => $piano->id, 'parent_id' => $padre, 'is_capitolo' => $capitolo, 'nome' => $nome, 'tipo' => $tipo, 'tipo_spesa' => $tipoSpesa,
        'importo' => 0, 'conto_contabile_id' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $s['capitolo'] = $nuovo('Spese generali', 'spesa', true);
    $s['voce'] = $nuovo('Manutenzione verde', 'spesa', false, $s['capitolo']);
    $s['pulizie'] = $nuovo('Pulizie', 'spesa', false, $s['capitolo'], 'standard');
    $s['legale'] = $nuovo('Parcella legale', 'spesa', false, $s['capitolo'], 'professionista');
    $s['entrata'] = $nuovo('Affitto antenna', 'entrata');
    $s['costi'] = (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'costi_servizi')->value('id');
    $s['compensi'] = (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'compensi_professionisti')->value('id');

    return $s;
}

function b14RiparaConto(int $id): ?int
{
    $v = DB::table('conti')->where('id', $id)->value('conto_contabile_id');

    return $v === null ? null : (int) $v;
}

function b14RiparaMigrazione()
{
    return require database_path('migrations/'.B14_RIPARA_MIGRAZIONE.'.php');
}

it('il capitolo e le sue voci di spesa senza conto prendono «Costi per Servizi», quelle dei professionisti i «Compensi», le entrate restano', function () {
    $s = b14RiparaCondominio();
    expect($s['costi'])->toBeGreaterThan(0)->and($s['compensi'])->toBeGreaterThan(0);

    b14RiparaMigrazione()->up();

    expect(b14RiparaConto($s['capitolo']))->toBe($s['costi'])
        ->and(b14RiparaConto($s['voce']))->toBe($s['costi'])
        ->and(b14RiparaConto($s['pulizie']))->toBe($s['costi'])
        ->and(b14RiparaConto($s['legale']))->toBe($s['compensi'])
        ->and(b14RiparaConto($s['entrata']))->toBeNull();
    // Lo stesso mastro che darebbe il salvataggio a mano.
    expect(CreazioneDellaVoce::contoContabile($s['c']->id, 'standard', 'spesa'))->toBe($s['costi']);
});

it('rieseguita non cambia niente, e una voce che il suo conto lo ha già non si sposta', function () {
    $s = b14RiparaCondominio();
    $altro = (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'spese_bancarie')->value('id');
    expect($altro)->toBeGreaterThan(0);
    DB::table('conti')->where('id', $s['pulizie'])->update(['conto_contabile_id' => $altro]);

    $migrazione = b14RiparaMigrazione();
    $migrazione->up();
    $dopoLaPrima = DB::table('conti')->orderBy('id')->get()->toArray();
    expect(b14RiparaConto($s['pulizie']))->toBe($altro);

    $migrazione->up();
    expect(DB::table('conti')->orderBy('id')->get()->toArray())->toEqual($dopoLaPrima);
});

it('un condominio senza il mastro «Costi per Servizi» si ferma lì e va nel registro, e l\'aggiornamento prosegue con gli altri', function () {
    $senza = b14RiparaCondominio();
    $con = b14RiparaCondominio();
    ContoContabile::where('condominio_id', $senza['c']->id)->where('ruolo', 'costi_servizi')->forceDelete();

    Log::spy();
    b14RiparaMigrazione()->up();

    expect(b14RiparaConto($senza['voce']))->toBeNull()
        ->and(b14RiparaConto($senza['capitolo']))->toBeNull()
        // I compensi ce li ha: la voce del professionista si ancora comunque.
        ->and(b14RiparaConto($senza['legale']))->toBe($senza['compensi'])
        ->and(b14RiparaConto($con['voce']))->toBe($con['costi'])
        ->and(b14RiparaConto($con['capitolo']))->toBe($con['costi']);
    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, (string) $senza['c']->id) && str_contains($m, 'Costi per Servizi'))->once();
});

it('un errore su un condominio va nel registro e non ferma l\'aggiornamento', function () {
    $uno = b14RiparaCondominio();
    $due = b14RiparaCondominio();
    // Il primo condominio non ha più il mastro come riga leggibile: la lettura del mastro fallisce per lui.
    DB::statement('CREATE TRIGGER b14_ripara_fallisce BEFORE UPDATE ON conti FOR EACH ROW WHEN OLD.id = '.$uno['voce'].' BEGIN SELECT RAISE(ABORT, \'prova\'); END');

    Log::spy();
    b14RiparaMigrazione()->up();

    expect(b14RiparaConto($due['voce']))->toBe($due['costi']);
    Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, (string) $uno['c']->id))->once();
});
