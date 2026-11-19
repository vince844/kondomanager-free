<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Assegnazione delle segnalazioni: il database (decisione 294)
 *
 * - **Due colonne nuove** su `segnalazioni`, accanto ad `assigned_to` che c'è dalla nascita: `assegnata_da` (chi ha
 *   assegnato) e `assegnata_il` (quando, in UTC, colonna `dateTime`).
 * - **Due impostazioni generali**, spente di serie: se il condòmino vede chi segue la sua segnalazione, e se
 *   l'assegnazione porta a «in lavorazione» una segnalazione aperta.
 * - **Tre migrazioni**, con i nomi del compito, che si possono rilanciare.
 */

use App\Models\Segnalazione;
use App\Settings\GeneralSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/AssegnazioneScenario.php';

beforeEach(function () {
    asgPrepara($this);
});

function asgMigrazione(string $file): object
{
    $percorso = database_path($file);
    expect(file_exists($percorso))->toBeTrue("Manca la migrazione {$file}");

    return require $percorso;
}

test('le due colonne ci sono, vuote di serie, e la migrazione le crea come `dateTime` e intero senza chiave', function () {
    expect(Schema::hasColumns('segnalazioni', ['assigned_to', 'assegnata_da', 'assegnata_il']))->toBeTrue();

    $riga = asgRiga($this->s);
    expect($riga->assigned_to)->toBeNull()
        ->and($riga->assegnata_da)->toBeNull()
        ->and($riga->assegnata_il)->toBeNull();

    $sorgente = file_get_contents(database_path(ASG_MIGRAZIONE_COLONNE));
    expect($sorgente)->toMatch('/->dateTime\(\s*[\'"]assegnata_il[\'"]\s*\)/')
        ->and($sorgente)->toMatch('/->unsignedBigInteger\(\s*[\'"]assegnata_da[\'"]\s*\)/')
        ->and($sorgente)->not->toMatch('/->timestamp\(/')
        ->and($sorgente)->not->toMatch('/foreign|constrained/i');
});

test('la migrazione delle colonne si rilancia senza errori e senza perdere i dati', function () {
    $s = asgAssegnata($this, $this->collaboratrice);

    asgMigrazione(ASG_MIGRAZIONE_COLONNE)->up();
    asgMigrazione(ASG_MIGRAZIONE_COLONNE)->up();

    expect(Schema::hasColumns('segnalazioni', ['assegnata_da', 'assegnata_il']))->toBeTrue()
        ->and(asgRiga($s)->assegnata_il)->toBe('2026-11-10 08:30:00')
        ->and((int) asgRiga($s)->assigned_to)->toBe($this->collaboratrice->id);
});

test('la prima volta la migrazione svuota le assegnazioni nate prima della funzione', function () {
    // Prima di questa funzione nessuna pagina assegnava: ciò che c'è in `assigned_to` viene dai dati di prova.
    asgMigrazione(ASG_MIGRAZIONE_COLONNE)->down();
    expect(Schema::hasColumn('segnalazioni', 'assegnata_il'))->toBeFalse()
        ->and(Schema::hasColumn('segnalazioni', 'assegnata_da'))->toBeFalse()
        ->and(Schema::hasColumn('segnalazioni', 'assigned_to'))->toBeTrue();
    DB::table('segnalazioni')->where('id', $this->s->id)->update(['assigned_to' => $this->mario->id]);

    asgMigrazione(ASG_MIGRAZIONE_COLONNE)->up();

    expect(Schema::hasColumns('segnalazioni', ['assegnata_da', 'assegnata_il']))->toBeTrue()
        ->and(asgRiga($this->s)->assigned_to)->toBeNull();
});

test('la fabbrica dei dati di prova non assegna più le segnalazioni a caso', function () {
    $assegnate = Segnalazione::factory()->count(25)->make()->pluck('assigned_to')->filter()->count();

    expect($assegnate)->toBe(0);
});

test('il model legge la data dell\'assegnazione come data', function () {
    $s = asgAssegnata($this, $this->collaboratrice);

    expect(Segnalazione::find($s->id)->assegnata_il)->toBeInstanceOf(Carbon::class)
        ->and(Segnalazione::find($this->s->id)->assegnata_il)->toBeNull();
});

test('le due impostazioni generali ci sono, spente di serie', function () {
    $impostazioni = app(GeneralSettings::class);

    expect(property_exists($impostazioni, ASG_IMP_PORTALE))->toBeTrue('Manca ' . ASG_IMP_PORTALE)
        ->and(property_exists($impostazioni, ASG_IMP_STATO))->toBeTrue('Manca ' . ASG_IMP_STATO)
        ->and($impostazioni->{ASG_IMP_PORTALE})->toBeFalse()
        ->and($impostazioni->{ASG_IMP_STATO})->toBeFalse()
        ->and(DB::table('settings')->where('group', 'general')->whereIn('name', [ASG_IMP_PORTALE, ASG_IMP_STATO])->count())->toBe(2);
});

test('la migrazione delle impostazioni si rilancia senza riscrivere una scelta già fatta', function () {
    asgImpostazione(ASG_IMP_PORTALE, true);

    asgMigrazione(ASG_MIGRAZIONE_IMPOSTAZIONI)->up();
    asgMigrazione(ASG_MIGRAZIONE_IMPOSTAZIONI)->up();

    app()->forgetInstance(GeneralSettings::class);
    expect(app(GeneralSettings::class)->{ASG_IMP_PORTALE})->toBeTrue()
        ->and(app(GeneralSettings::class)->{ASG_IMP_STATO})->toBeFalse()
        ->and(DB::table('settings')->where('group', 'general')->whereIn('name', [ASG_IMP_PORTALE, ASG_IMP_STATO])->count())->toBe(2);
});
