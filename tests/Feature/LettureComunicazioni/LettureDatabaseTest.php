<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Conferma di lettura delle comunicazioni: il database (Coda 594, decisioni 310 e 311)
 *
 * - **Una tabella nuova**, `comunicazione_letture`: una riga per persona e per comunicazione, con il giorno e l'ora
 *   della prima apertura (`aperta_il`) e della presa visione (`presa_visione_il`). Tutte e due possono mancare.
 * - **Una colonna** su `comunicazioni`, `chiedi_presa_visione`, spenta di serie.
 * - **Un'impostazione generale**, `comunicazioni_registra_aperture`, spenta di serie.
 * - **Tre migrazioni**, con i nomi del compito, che si possono rilanciare senza errori e senza perdere i dati.
 * - Le letture si cancellano con la comunicazione e con l'utente.
 */

use App\Models\Comunicazione;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/LettureScenario.php';

beforeEach(function () {
    lctPrepara($this);
});

function lctMigrazione(string $file): object
{
    $percorso = database_path($file);
    expect(file_exists($percorso))->toBeTrue("Manca la migrazione {$file}");

    return require $percorso;
}

// --- La tabella delle letture ---------------------------------------------------------------------------

test('la tabella delle letture c\'è, con le sue colonne', function () {
    expect(Schema::hasTable(LCT_TABELLA))->toBeTrue('Manca la tabella ' . LCT_TABELLA)
        ->and(Schema::hasColumns(LCT_TABELLA, ['id', 'comunicazione_id', 'user_id', 'aperta_il', 'presa_visione_il', 'created_at', 'updated_at']))->toBeTrue();
});

test('una persona ha una riga sola per comunicazione', function () {
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');

    expect(fn () => lctScrivi($this->c, $this->mario, '2026-11-11 10:00:00'))->toThrow(QueryException::class);
    expect(lctRighe($this->c))->toHaveCount(1);
});

test('le due date possono mancare tutte e due, e si leggono come sono scritte', function () {
    lctScrivi($this->c, $this->mario, null, '2026-11-11 09:00:00');
    lctScrivi($this->c, $this->paolo, '2026-11-11 08:00:00', null);

    expect(lctRiga($this->c, $this->mario)->aperta_il)->toBeNull()
        ->and(lctRiga($this->c, $this->mario)->presa_visione_il)->toBe('2026-11-11 09:00:00')
        ->and(lctRiga($this->c, $this->paolo)->aperta_il)->toBe('2026-11-11 08:00:00')
        ->and(lctRiga($this->c, $this->paolo)->presa_visione_il)->toBeNull();
});

test('le letture si cancellano con la comunicazione', function () {
    $altra = ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Pulizia delle scale']);
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');
    lctScrivi($this->c, $this->paolo, '2026-11-11 09:00:00', '2026-11-11 09:05:00');
    lctScrivi($altra, $this->mario, '2026-11-11 09:00:00');

    $this->actingAs($this->admin)->delete(route('admin.comunicazioni.destroy', $this->c));

    expect(Comunicazione::find($this->c->id))->toBeNull()
        ->and(lctRighe())->toHaveCount(1)
        ->and((int) lctRighe()->first()->comunicazione_id)->toBe($altra->id);
});

test('le letture si cancellano con l\'utente', function () {
    $ospite = User::factory()->create(['name' => 'Ospite Di Passaggio']);
    lctScrivi($this->c, $ospite, '2026-11-11 09:00:00');
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');

    DB::table('users')->where('id', $ospite->id)->delete();

    expect(lctRighe())->toHaveCount(1)
        ->and((int) lctRighe()->first()->user_id)->toBe($this->mario->id);
});

test('la migrazione della tabella si rilancia senza errori e senza perdere le letture', function () {
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00', '2026-11-11 09:05:00');

    lctMigrazione(LCT_MIGRAZIONE_TABELLA)->up();
    lctMigrazione(LCT_MIGRAZIONE_TABELLA)->up();

    expect(lctRighe())->toHaveCount(1)
        ->and(lctRiga($this->c, $this->mario)->presa_visione_il)->toBe('2026-11-11 09:05:00');

    // Dopo la ripresa la riga sola per persona e la cancellazione con la comunicazione valgono ancora.
    expect(fn () => lctScrivi($this->c, $this->mario, null))->toThrow(QueryException::class);
    DB::table('comunicazioni')->where('id', $this->c->id)->delete();
    expect(lctRighe())->toHaveCount(0);
});

test('il model della comunicazione arriva alle sue letture, con le date lette come date', function () {
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00', null);

    expect(method_exists(Comunicazione::class, 'letture'))->toBeTrue('Manca la relazione Comunicazione::letture()');
    $letture = Comunicazione::findOrFail($this->c->id)->letture;

    expect($letture)->toHaveCount(1)
        ->and($letture->first())->toBeInstanceOf('App\\Models\\ComunicazioneLettura')
        ->and($letture->first()->aperta_il)->toBeInstanceOf(Carbon::class)
        ->and($letture->first()->aperta_il->utc()->format('Y-m-d H:i'))->toBe('2026-11-11 09:00')
        ->and($letture->first()->presa_visione_il)->toBeNull();
});

// --- La colonna sulla comunicazione ---------------------------------------------------------------------

test('la colonna «chiedi la presa visione» c\'è, spenta di serie', function () {
    expect(Schema::hasColumn('comunicazioni', LCT_COLONNA))->toBeTrue('Manca la colonna comunicazioni.' . LCT_COLONNA);

    $nuova = ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Pulizia delle scale']);

    expect(lctChiesta($nuova->id))->toBeFalse()
        ->and(Comunicazione::findOrFail($nuova->id)->{LCT_COLONNA})->toBeFalse();
});

test('il model la legge come vero o falso', function () {
    lctChiedi($this->c);

    expect(Comunicazione::findOrFail($this->c->id)->{LCT_COLONNA})->toBeTrue();
});

test('la migrazione della colonna si rilancia senza errori e senza perdere la scelta', function () {
    lctChiedi($this->c);

    lctMigrazione(LCT_MIGRAZIONE_COLONNA)->up();
    lctMigrazione(LCT_MIGRAZIONE_COLONNA)->up();

    expect(Schema::hasColumn('comunicazioni', LCT_COLONNA))->toBeTrue()
        ->and(lctChiesta($this->c->id))->toBeTrue();
});

// --- L'impostazione generale ----------------------------------------------------------------------------

test('l\'impostazione generale nasce spenta', function () {
    expect(property_exists(GeneralSettings::class, LCT_IMPOSTAZIONE))->toBeTrue("Manca l'impostazione generale " . LCT_IMPOSTAZIONE)
        ->and(lctImpostazioneAccesa())->toBeFalse()
        ->and(DB::table('settings')->where('group', 'general')->where('name', LCT_IMPOSTAZIONE)->count())->toBe(1);
});

test('la migrazione dell\'impostazione si rilancia senza toccare la scelta dell\'amministratore', function () {
    lctImpostazione(true);

    lctMigrazione(LCT_MIGRAZIONE_IMPOSTAZIONE)->up();
    lctMigrazione(LCT_MIGRAZIONE_IMPOSTAZIONE)->up();

    expect(lctImpostazioneAccesa())->toBeTrue()
        ->and(DB::table('settings')->where('group', 'general')->where('name', LCT_IMPOSTAZIONE)->count())->toBe(1);
});
