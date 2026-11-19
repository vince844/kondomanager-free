<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Pubblicazione programmata delle comunicazioni: il database e le due regole sempre vere (Coda 589, decisione 290)
 *
 * - **Tre colonne** su `comunicazioni`: `programmata_per` (quando deve uscire, UTC), `programmata_da` (chi l'ha
 *   programmata), `pubblicata_il` (quando è diventata visibile ai condòmini, UTC).
 * - **Tre migrazioni**, con i nomi del compito, che si possono rilanciare.
 * - **Regola 1**: `pubblicata_il` è piena se e solo se la comunicazione è pubblicata e approvata. Prende l'ora del momento
 *   in cui lo diventa e si svuota quando smette di esserlo.
 * - **Regola 2**: `programmata_per` è piena solo se la comunicazione è approvata e non pubblicata. Si svuota, con
 *   `programmata_da`, quando la comunicazione diventa visibile e quando perde l'approvazione.
 *
 * Le due regole le tiene il model al salvataggio: valgono per ogni percorso, non solo per il modulo.
 */

use App\Models\Comunicazione;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/ProgrammateScenario.php';

beforeEach(function () {
    cprPrepara($this);
});

function cprMigrazione(string $file): object
{
    $percorso = database_path($file);
    expect(file_exists($percorso))->toBeTrue("Manca la migrazione {$file}");

    return require $percorso;
}

// --- Le migrazioni ------------------------------------------------------------------------------------

test('le tre colonne ci sono, vuote di serie', function () {
    expect(Schema::hasColumns('comunicazioni', ['programmata_per', 'programmata_da', 'pubblicata_il']))->toBeTrue();

    $c = ccmComunicazione([$this->aurora], $this->admin, ['is_published' => false]);
    $riga = cprRiga($c);

    expect($riga->programmata_per)->toBeNull()
        ->and($riga->programmata_da)->toBeNull()
        ->and($riga->pubblicata_il)->toBeNull();
});

test('la migrazione delle colonne si rilancia senza errori e senza perdere i dati', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2027-01-15 08:00:00');

    cprMigrazione(CPR_MIGRAZIONE_COLONNE)->up();
    cprMigrazione(CPR_MIGRAZIONE_COLONNE)->up();

    expect(Schema::hasColumns('comunicazioni', ['programmata_per', 'programmata_da', 'pubblicata_il']))->toBeTrue()
        ->and(cprRiga($c)->programmata_per)->toBe('2027-01-15 08:00:00');
});

test('il riempimento dà a chi è già visibile la data di creazione, e non tocca le altre', function () {
    $visibile = ccmComunicazione([$this->aurora], $this->admin);
    $bozza = ccmComunicazione([$this->aurora], $this->admin, ['is_published' => false]);
    $inAttesa = ccmComunicazione([$this->aurora], $this->admin, ['is_approved' => false]);
    $giaPiena = ccmComunicazione([$this->aurora], $this->admin);
    // Lo stato di prima della beta: visibili senza la data nuova.
    DB::table('comunicazioni')->where('id', $visibile->id)->update(['created_at' => '2026-10-01 08:30:00', 'pubblicata_il' => null]);
    DB::table('comunicazioni')->where('id', $giaPiena->id)->update(['created_at' => '2026-10-02 08:30:00', 'pubblicata_il' => '2026-10-05 12:00:00']);

    cprMigrazione(CPR_MIGRAZIONE_RIEMPIMENTO)->up();
    cprMigrazione(CPR_MIGRAZIONE_RIEMPIMENTO)->up();

    expect(cprRiga($visibile)->pubblicata_il)->toBe('2026-10-01 08:30:00')
        ->and(cprRiga($bozza)->pubblicata_il)->toBeNull()
        ->and(cprRiga($inAttesa)->pubblicata_il)->toBeNull()
        ->and(cprRiga($giaPiena)->pubblicata_il)->toBe('2026-10-05 12:00:00');
});

test('la preferenza della conferma nasce da «Comunicazione approvata», e la ripresa non la tocca', function () {
    DB::table('notification_preferences')->where('type', CPR_TIPO_CONFERMA)->delete();
    cprPreferenza($this->admin, 'approved_communication', true);
    cprPreferenza($this->collaboratrice, 'approved_communication', false);
    DB::table('notification_preferences')->where('user_id', $this->mario->id)->where('type', 'approved_communication')->delete();

    cprMigrazione(CPR_MIGRAZIONE_PREFERENZA)->up();

    $stato = fn ($user) => DB::table('notification_preferences')->where('user_id', $user->id)->where('type', CPR_TIPO_CONFERMA)->value('enabled');
    expect((bool) $stato($this->admin))->toBeTrue()
        ->and($stato($this->collaboratrice))->not->toBeNull()
        ->and((bool) $stato($this->collaboratrice))->toBeFalse()
        ->and($stato($this->mario))->toBeNull();

    // Chi l'ha cambiata dopo non la ritrova riscritta.
    cprPreferenza($this->admin, CPR_TIPO_CONFERMA, false);
    cprMigrazione(CPR_MIGRAZIONE_PREFERENZA)->up();

    expect((bool) $stato($this->admin))->toBeFalse()
        ->and(DB::table('notification_preferences')->where('user_id', $this->admin->id)->where('type', CPR_TIPO_CONFERMA)->count())->toBe(1);
});

// --- Regola 1: `pubblicata_il` -------------------------------------------------------------------------

test('una comunicazione che nasce pubblicata e approvata prende l\'ora di adesso; una bozza no', function () {
    $visibile = ccmComunicazione([$this->aurora], $this->admin);
    $bozza = ccmComunicazione([$this->aurora], $this->admin, ['is_published' => false]);
    $inAttesa = ccmComunicazione([$this->aurora], $this->admin, ['is_approved' => false]);

    expect(cprRiga($visibile)->pubblicata_il)->toBe(CPR_ADESSO)
        ->and(cprRiga($bozza)->pubblicata_il)->toBeNull()
        ->and(cprRiga($inAttesa)->pubblicata_il)->toBeNull();
});

test('una bozza pubblicata dopo prende l\'ora della pubblicazione, non quella di creazione', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin, ['is_published' => false]);

    $this->travelTo(Carbon::parse('2026-11-15 07:30:00', 'UTC'));
    $c->update(['is_published' => true]);

    expect(cprRiga($c)->pubblicata_il)->toBe('2026-11-15 07:30:00')
        ->and($c->fresh()->pubblicata_il)->toBeInstanceOf(Carbon::class);
});

test('una modifica che non cambia la visibilità non sposta la data di pubblicazione', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin);

    $this->travelTo(Carbon::parse('2026-11-20 09:00:00', 'UTC'));
    $c->update(['subject' => 'Titolo corretto']);

    expect(cprRiga($c)->pubblicata_il)->toBe(CPR_ADESSO);
});

test('riportata in bozza, o tolta l\'approvazione, la data di pubblicazione si svuota; ripubblicata ne prende una nuova', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin);
    $c->update(['is_published' => false]);
    expect(cprRiga($c)->pubblicata_il)->toBeNull();

    $this->travelTo(Carbon::parse('2026-11-18 16:00:00', 'UTC'));
    $c->update(['is_published' => true]);
    expect(cprRiga($c)->pubblicata_il)->toBe('2026-11-18 16:00:00');

    // L'interruttore dell'approvazione, dalla sua rotta: toglie approvazione e pubblicazione insieme.
    $this->actingAs($this->admin)->put(route('admin.comunicazioni.toggle-approval', $c));
    expect((bool) cprRiga($c)->is_approved)->toBeFalse()
        ->and(cprRiga($c)->pubblicata_il)->toBeNull();

    $this->travelTo(Carbon::parse('2026-11-19 08:00:00', 'UTC'));
    $this->actingAs($this->admin)->put(route('admin.comunicazioni.toggle-approval', $c));
    expect((bool) cprRiga($c)->is_approved)->toBeTrue()
        ->and(cprRiga($c)->pubblicata_il)->toBe('2026-11-19 08:00:00');
});

// --- Regola 2: `programmata_per` -----------------------------------------------------------------------

test('una programmata che diventa visibile perde la programmazione', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2027-01-15 08:00:00');

    $c->update(['is_published' => true]);

    $riga = cprRiga($c);
    expect($riga->programmata_per)->toBeNull()
        ->and($riga->programmata_da)->toBeNull()
        ->and($riga->pubblicata_il)->toBe(CPR_ADESSO);
});

test('togliere l\'approvazione a una programmata toglie anche la programmazione', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2027-01-15 08:00:00');

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.toggle-approval', $c));

    $riga = cprRiga($c);
    expect((bool) $riga->is_approved)->toBeFalse()
        ->and($riga->programmata_per)->toBeNull()
        ->and($riga->programmata_da)->toBeNull()
        ->and($riga->pubblicata_il)->toBeNull();
});

test('il model legge le due date come date', function () {
    $c = cprProgrammata([$this->aurora], $this->admin, '2027-01-15 08:00:00');

    expect($c->programmata_per)->toBeInstanceOf(Carbon::class)
        ->and($c->programmata_per->utc()->format('Y-m-d H:i'))->toBe('2027-01-15 08:00')
        ->and(Comunicazione::find($c->id)->pubblicata_il)->toBeNull();
});
