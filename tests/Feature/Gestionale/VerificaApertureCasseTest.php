<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 546 (decisione 685 (5)): il controllo in sola lettura delle aperture già scritte nel primo esercizio da una cassa creata dopo. Elenca, non sposta:
 * le casse portate dall'importazione hanno la data di creazione del giorno dell'importazione e l'apertura giusta nel primo esercizio, e non devono comparire
 * come errori per questo solo; l'amministratore decide cassa per cassa.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/** Una cassa con la sua apertura nel primo esercizio (come il codice di prima), creata il $creataIl. */
function cassaConAperturaNelPrimoEsercizio(int $condominioId, string $creataIl, int $cents = 100000): Cassa
{
    $contoId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId, 'ruolo' => 'conto_bancario', 'codice' => '1010.'.uniqid(), 'nome' => 'Banca '.uniqid(),
        'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $cassa = Cassa::create(['condominio_id' => $condominioId, 'nome' => 'Cassa '.uniqid(), 'tipo' => 'banca', 'conto_contabile_id' => $contoId, 'saldo_iniziale' => $cents, 'attiva' => true]);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($cassa, '2026-01-01');
    DB::table('casse')->where('id', $cassa->id)->update(['created_at' => $creataIl.' 10:00:00']);

    return $cassa->fresh();
}

test('elenca la cassa creata nel 2027 con l\'apertura nel 2026 e non tocca nessun dato', function () {
    [$condominio] = setupContabile();
    Esercizio::create(['condominio_id' => $condominio->id, 'nome' => 'Esercizio 2027', 'stato' => 'aperto', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);
    $tardiva = cassaConAperturaNelPrimoEsercizio($condominio->id, '2027-06-01');

    $prima = [DB::table('scritture_contabili')->count(), DB::table('righe_scritture')->count(), DB::table('casse')->count(), DB::table('casse')->value('saldo_iniziale')];

    expect(Artisan::call('kondomanager:verifica-aperture-casse'))->toBe(0);
    $uscita = Artisan::output();
    expect($uscita)->toContain($tardiva->nome)->toContain('Esercizio 2026')->toContain('2026-01-01')->toContain('2027-06-01')->toContain('1.000,00');

    expect([DB::table('scritture_contabili')->count(), DB::table('righe_scritture')->count(), DB::table('casse')->count(), DB::table('casse')->value('saldo_iniziale')])->toBe($prima);
});

test('una cassa creata nel primo esercizio con l\'apertura nel primo esercizio non compare', function () {
    [$condominio] = setupContabile();
    Esercizio::create(['condominio_id' => $condominio->id, 'nome' => 'Esercizio 2027', 'stato' => 'aperto', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);
    cassaConAperturaNelPrimoEsercizio($condominio->id, '2026-03-01');

    $this->artisan('kondomanager:verifica-aperture-casse')
        ->expectsOutputToContain('Nessuna cassa')
        ->assertExitCode(0);
});

test('con --condominio guarda solo quel condominio', function () {
    [$condominio] = setupContabile();
    Esercizio::create(['condominio_id' => $condominio->id, 'nome' => 'Esercizio 2027', 'stato' => 'aperto', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);
    cassaConAperturaNelPrimoEsercizio($condominio->id, '2027-06-01');

    $this->artisan('kondomanager:verifica-aperture-casse', ['--condominio' => $condominio->id + 999])
        ->expectsOutputToContain('Nessuna cassa')
        ->assertExitCode(0);
});

/**
 * Coda 754 (1): le casse eliminate con l'apertura ancora a giornale, da una versione che non la stornava. Il conto archiviato ha un saldo e nessuna cassa:
 * lo Stato patrimoniale lo conta nella liquidità e «Liquidità e cassa» resta rosso. Il comando lo elenca, con il saldo e le aperture; non scrive niente.
 */
test('elenca il conto di liquidità con un saldo e senza cassa (la cassa eliminata senza lo storno dell\'apertura), e non tocca nessun dato', function () {
    [$condominio] = setupContabile();
    $vecchia = cassaConAperturaNelPrimoEsercizio($condominio->id, '2026-03-01', 215000);
    $nomeConto = DB::table('conti_contabili')->where('id', $vecchia->conto_contabile_id)->value('codice');
    // Eliminata come faceva il programma prima: la cassa va via, l'apertura resta sul conto.
    DB::table('casse')->where('id', $vecchia->id)->delete();
    $prima = [DB::table('scritture_contabili')->count(), DB::table('righe_scritture')->count(), DB::table('conti_contabili')->count()];

    expect(Artisan::call('kondomanager:verifica-aperture-casse'))->toBe(0);
    $uscita = Artisan::output();
    expect($uscita)->toContain('senza cassa')->toContain($nomeConto)->toContain('2.150,00')->toContain('Fondo Passate Gestioni');

    expect([DB::table('scritture_contabili')->count(), DB::table('righe_scritture')->count(), DB::table('conti_contabili')->count()])->toBe($prima);
});

test('la cassa eliminata dalla pagina, con l\'apertura stornata, non compare fra i conti senza cassa', function () {
    [$condominio] = setupContabile();
    $cassa = cassaConAperturaNelPrimoEsercizio($condominio->id, '2026-03-01', 215000);
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $u = \App\Models\User::factory()->create();
    $u->givePermissionTo('Accesso pannello amministratore');

    $this->actingAs($u)->delete(route('admin.gestionale.casse.destroy', [$condominio->id, $cassa->id]))->assertRedirect();
    expect(Cassa::find($cassa->id))->toBeNull();

    $this->artisan('kondomanager:verifica-aperture-casse')
        ->expectsOutputToContain('Nessun conto di liquidità con un saldo e senza cassa')
        ->assertExitCode(0);
});
