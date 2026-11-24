<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 756, il giro di correzioni della tappa 6: la ripartizione spese di un esercizio non porta i piani rate di un altro esercizio.
 *
 * Una gestione usata in più esercizi ha i piani di tutti. Il foglio «Ripartizione spese» dell'esercizio 2026 con un «Preventivo 2027» sulla
 * stessa gestione portava la rata del 05/01/2027 e il totale di € 500,00 in più. Dalla rotta `print-riparto`.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\User;
use Database\Seeders\CondominioStampeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function b14r2Admin(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $u = User::factory()->create();
    $u->assignRole($ruolo);

    return $u;
}

function b14r2Testo(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 'b14r2');
    file_put_contents($file, $pdf);
    $t = (string) shell_exec('pdftotext -layout '.escapeshellarg($file).' -');
    @unlink($file);

    return $t;
}

function b14r2Scenario(): array
{
    $seeder = new CondominioStampeSeeder();
    $seeder->run();
    $condominio = $seeder->condominio();
    $piano = $seeder->pianoRate();
    $pianoConto = PianoConto::where('gestione_id', $piano->gestione_id)->first();
    $esercizio = $condominio->esercizi()->latest('id')->first();
    $e27 = Esercizio::create(['condominio_id' => $condominio->id, 'nome' => 'Esercizio 2027', 'stato' => 'aperto', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);
    $p27 = PianoRate::create(['gestione_id' => $piano->gestione_id, 'condominio_id' => $condominio->id, 'esercizio_id' => $e27->id, 'nome' => 'Preventivo 2027',
        'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2027-01-05',
        'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    $rata = Rata::create(['piano_rate_id' => $p27->id, 'numero_rata' => 1, 'data_scadenza' => '2027-01-05', 'importo_totale' => 50000, 'stato' => 'bozza']);
    $quota = RataQuote::whereIn('rata_id', $piano->rate()->pluck('id'))->first();
    RataQuote::create(['rata_id' => $rata->id, 'anagrafica_id' => $quota->anagrafica_id, 'immobile_id' => $quota->immobile_id, 'importo' => 50000,
        'importo_pagato' => 0, 'stato' => 'da_pagare']);

    return [$condominio, $esercizio, $e27, $pianoConto, $piano];
}

it('la ripartizione del 2026 non porta il piano del 2027 della stessa gestione, né il suo totale', function () {
    [$condominio, $esercizio, , $pianoConto] = b14r2Scenario();

    $testo = b14r2Testo($this->actingAs(b14r2Admin())->get(route('admin.gestionale.esercizi.piani-conti.print-riparto', [$condominio->id, $esercizio->id, $pianoConto->id]))->getContent());

    expect($testo)->not->toContain('05/01/2027')->and($testo)->not->toContain('Preventivo 2027')
        ->and($testo)->toContain('18.350,00')->and($testo)->not->toContain('18.850,00');
});

it('un piano vecchio senza l\'esercizio salvato resta nel foglio dell\'esercizio che il programma gli attribuisce', function () {
    [$condominio, $esercizio, , $pianoConto, $piano] = b14r2Scenario();
    DB::table('piani_rate')->where('id', $piano->id)->update(['esercizio_id' => null]);

    $testo = b14r2Testo($this->actingAs(b14r2Admin())->get(route('admin.gestionale.esercizi.piani-conti.print-riparto', [$condominio->id, $esercizio->id, $pianoConto->id]))->getContent());

    expect($testo)->toContain('18.350,00');
});
