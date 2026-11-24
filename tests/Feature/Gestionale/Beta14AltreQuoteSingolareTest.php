<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 664 (1.12.0-beta.14): le frasi del pannello «Cosa cambierà» sulle quote emesse che il passaggio non riguarda dicono «L'altra
 * quota emessa su questa unità resta a …» con una quota sola, e «Le altre 2 quote emesse …» con più d'una; mai «Le altre 1 quote».
 *
 * Il caso: l'Interno 1 è in comproprietà fra Ugo e Bianca al 50 %, il piano 2026 è generato e le rate di gennaio (e di febbraio) sono emesse,
 * una quota per ciascuno a ogni rata. Ugo cede: le quote di Bianca sono «le altre». I rami sono quelli di `AnteprimaPassaggio::blocco2()`:
 * la vendita senza coppie, l'usufrutto, la vendita con la riserva e l'unità senza rate intestate a chi esce.
 *
 * **Cosa NON copre.** Il pannello a video (`AnteprimaPassaggio.vue` mostra la frase com'è); i rami dell'estinzione con accrescimento e della
 * successione, che usano la stessa funzione.
 */

use App\Models\Anagrafica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Ugo e Bianca al 50 % dell'Interno 1, il piano generato e emesso fino al giorno dato. */
function b14aqScenario(string $emessoFino): array
{
    $s = ruScenario('tutte_rate', 0, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $bianca = Anagrafica::forceCreate(['nome' => 'Bianca Neri', 'email' => 'b14aq-bianca@test.it', 'indirizzo' => 'Via Roma 2', 'codice_fiscale' => 'B14AQBIANCA00001']);
    $bianca->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $bianca->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s, $emessoFino);
    $s['bianca'] = $bianca;

    return $s;
}

/** Le frasi del blocco «rate» dell'anteprima di un passaggio della griglia. */
function b14aqFrasi($test, array $s, array $dati): array
{
    return ruAnteprima($test, $s, $dati + ['ho_letto' => true])['rate']['frasi'];
}

it('1 — vendita con una sola quota emessa a un altro: «L\'altra quota emessa su questa unità resta a Bianca Neri», non «Le altre 1 quote»', function () {
    $s = b14aqScenario('2026-01-31');
    $frasi = b14aqFrasi($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-03-01', 50));

    expect($frasi)->toContain('L\'altra quota emessa su questa unità resta a Bianca Neri: questo passaggio non la riguarda.')
        ->and(implode(' ', $frasi))->not->toContain('altre 1 quote');
});

it('2 — vendita con due quote emesse a un altro: il plurale di sempre', function () {
    $s = b14aqScenario('2026-02-28');
    $frasi = b14aqFrasi($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-03-01', 50));

    expect($frasi)->toContain('Le altre 2 quote emesse su questa unità restano a Bianca Neri: questo passaggio non le riguarda.');
});

it('3 — costituzione d\'usufrutto con una sola quota emessa a un altro: il singolare', function () {
    $s = b14aqScenario('2026-01-31');
    $frasi = b14aqFrasi($this, $s, ruPassaggio('costituzione', $s['rigaV'], $s['a'], '2026-03-01', 50));

    expect($frasi)->toContain('L\'altra quota emessa su questa unità resta a Bianca Neri: questo passaggio non la riguarda.');
});

it('4 — vendita con riserva d\'usufrutto con una sola quota emessa a un altro: il singolare', function () {
    $s = b14aqScenario('2026-01-31');
    $frasi = b14aqFrasi($this, $s, ruRiserva($s, '2026-03-01', ['quota' => 50]));

    expect($frasi)->toContain('L\'altra quota emessa su questa unità resta a Bianca Neri: questo passaggio non la riguarda.');
});

it('5 — unità senza rate intestate a chi esce, con una quota emessa a un altro: «La quota dell\'unità resta a chi l\'ha ricevuta»', function () {
    $s = b14aqScenario('2026-01-31');
    // Ugo non ha quote emesse: le sue sono tolte, resta una sola quota, quella di Bianca.
    DB::table('rate_quote')->where('anagrafica_id', $s['v']->id)->delete();
    $frasi = b14aqFrasi($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-03-01', 50));

    expect(implode(' ', $frasi))->toContain('La quota dell\'unità resta a chi l\'ha ricevuta (Bianca Neri).')
        ->and(implode(' ', $frasi))->not->toContain('Le 1 quote');
});

it('6 — unità senza rate intestate a chi esce, con due quote a un altro: il plurale', function () {
    $s = b14aqScenario('2026-02-28');
    DB::table('rate_quote')->where('anagrafica_id', $s['v']->id)->delete();
    $frasi = b14aqFrasi($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-03-01', 50));

    expect(implode(' ', $frasi))->toContain('Le 2 quote dell\'unità restano a chi le ha ricevute (Bianca Neri).');
});
