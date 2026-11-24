<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * `kondomanager:verifica-importazioni` (Coda 599, 1.12.0-beta.14): le unità entrate da un'importazione con le quote di un ruolo oltre 100 in un giorno,
 * come le scriveva l'importazione fino alla beta.13 (due comproprietari senza quota a 100 ciascuno). **Sola lettura**: le quote sono diritti delle persone,
 * e il comando non le riscrive; dice per condominio quali unità e che cosa fare a mano dalla scheda.
 *
 * Che cosa non copre: le unità scritte a mano con le quote oltre 100 (non vengono da un'importazione: le elenca già `kondomanager:verifica-titolarita`).
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Un'unità entrata da un lotto d'importazione, con le persone e le quote date (ruolo, quota, dal, al). */
function b14viUnita(Condominio $condominio, string $nome, array $righe, bool $importata = true): Immobile
{
    $immobile = Immobile::forceCreate(['condominio_id' => $condominio->id, 'nome' => $nome, 'descrizione' => $nome, 'interno' => substr($nome, -1)]);
    foreach ($righe as [$persona, $ruolo, $quota, $dal, $al]) {
        $a = Anagrafica::forceCreate(['nome' => $persona, 'indirizzo' => 'Via Prova 1']);
        DB::table('anagrafica_immobile')->insert([
            'immobile_id' => $immobile->id, 'anagrafica_id' => $a->id, 'tipologia' => $ruolo, 'quota' => $quota, 'data_inizio' => $dal, 'data_fine' => $al,
            'attivo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    if ($importata) {
        $lotto = ImportBatch::create(['sorgente' => 'danea', 'condominio_id' => $condominio->id]);
        $lotto->items()->create(['livello' => 'unita', 'importabile_type' => Immobile::class, 'importabile_id' => $immobile->id, 'azione' => ImportBatchItem::AZIONE_CREATO]);
    }

    return $immobile;
}

it('elenca per condominio le unità importate con le quote di un ruolo oltre 100, dice che cosa fare a mano, e non scrive niente', function () {
    $roma = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $italia = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    // Il caso della Coda 599: Ugo ed Elsa a 100 e 100.
    b14viUnita($roma, 'Interno 3', [['Ugo Bianchi', 'proprietario', 100, '2026-01-01', null], ['Elsa Verdi', 'proprietario', 100, '2026-01-01', null]]);
    // A posto: 50 e 50; il proprietario e l'inquilino a 100 ciascuno; un passaggio con il venditore chiuso il giorno prima.
    b14viUnita($roma, 'Interno 4', [['Lia Neri', 'proprietario', 50, '2026-01-01', null], ['Pio Neri', 'proprietario', 50, '2026-01-01', null]]);
    b14viUnita($roma, 'Interno 5', [['Ada Russo', 'proprietario', 100, '2026-01-01', null], ['Ivo Conti', 'inquilino', 100, '2026-01-01', null]]);
    b14viUnita($roma, 'Interno 6', [['Rita Gallo', 'proprietario', 100, '2026-01-01', '2026-04-30'], ['Gino Costa', 'proprietario', 100, '2026-05-01', null]]);
    // Nell'altro condominio: tre comproprietari a 100.
    b14viUnita($italia, 'Interno 1', [['Sara Fabbri', 'proprietario', 100, '2026-01-01', null], ['Nico Fabbri', 'proprietario', 100, '2026-01-01', null],
        ['Teo Fabbri', 'proprietario', 100, '2026-01-01', null]]);
    // Scritta a mano, non importata: non è di questo comando.
    b14viUnita($italia, 'Interno 2', [['Ezio Riva', 'proprietario', 100, '2026-01-01', null], ['Olga Riva', 'proprietario', 100, '2026-01-01', null]], importata: false);

    $scritture = 0;
    DB::listen(function (QueryExecuted $q) use (&$scritture) {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q->sql) === 1) {
            $scritture++;
        }
    });

    expect(Artisan::call('kondomanager:verifica-importazioni'))->toBe(0);
    $uscita = Artisan::output();

    expect($scritture)->toBe(0)
        ->and($uscita)->toContain('sola lettura')
        ->and($uscita)->toContain('Via Roma 12')->and($uscita)->toContain('Corso Italia 3')
        ->and($uscita)->toContain('Interno 3')->and($uscita)->toContain('Ugo Bianchi')->and($uscita)->toContain('200')
        ->and($uscita)->toContain('Interno 1')->and($uscita)->toContain('300')
        ->and($uscita)->not->toContain('Interno 4')->and($uscita)->not->toContain('Interno 5')->and($uscita)->not->toContain('Interno 6')
        ->and($uscita)->not->toContain('Interno 2')
        // Il riepilogo per condominio e la frase di che cosa fare a mano.
        ->and($uscita)->toContain('Condominio '.$roma->id.' — Via Roma 12: 1 unità')
        ->and($uscita)->toContain('Condominio '.$italia->id.' — Corso Italia 3: 1 unità')
        ->and($uscita)->toContain('Niente è stato cambiato')
        ->and($uscita)->toContain('scheda dell\'unità');

    // Con --condominio guarda solo quel condominio.
    Artisan::call('kondomanager:verifica-importazioni', ['--condominio' => $italia->id]);
    $soloItalia = Artisan::output();
    expect($soloItalia)->toContain('Interno 1')->and($soloItalia)->not->toContain('Via Roma 12');
});

it('la catena: l\'unità elencata si corregge dalla scheda un passo alla volta, e il comando non la elenca più', function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $admin = \App\Models\User::factory()->create();
    $admin->assignRole($ruolo);

    $roma = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $interno3 = b14viUnita($roma, 'Interno 3', [['Ugo Bianchi', 'proprietario', 100, '2026-01-01', null], ['Elsa Verdi', 'proprietario', 100, '2026-01-01', null]]);
    $this->artisan('kondomanager:verifica-importazioni')->expectsOutputToContain('Ugo Bianchi')->assertExitCode(0);

    foreach (['Ugo Bianchi', 'Elsa Verdi'] as $nome) {
        $persona = Anagrafica::query()->where('nome', $nome)->sole();
        $riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $persona->id)->value('id');
        $this->actingAs($admin)->put(route('admin.gestionale.immobili.anagrafiche.update', [$roma, $interno3, $riga]), [
            'anagrafica_id' => $persona->id, 'tipologia' => 'proprietario', 'quota' => 50, 'data_inizio' => '2026-01-01', 'data_fine' => null,
        ])->assertSessionHasNoErrors();
    }

    $this->artisan('kondomanager:verifica-importazioni')
        ->expectsOutputToContain('Nessuna unità entrata da un\'importazione ha le quote di un ruolo oltre 100')
        ->assertExitCode(0);
});

it('senza unità da guardare lo dice', function () {
    $roma = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    b14viUnita($roma, 'Interno 4', [['Lia Neri', 'proprietario', 50, '2026-01-01', null], ['Pio Neri', 'proprietario', 50, '2026-01-01', null]]);

    $this->artisan('kondomanager:verifica-importazioni')
        ->expectsOutputToContain('Nessuna unità entrata da un\'importazione ha le quote di un ruolo oltre 100')
        ->assertExitCode(0);
});
