<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role;
use App\Models\Condominio;
use App\Models\RegistroModifica;
use App\Models\Tabella;
use App\Models\User;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * 1.12.0-beta.13 (Coda 679, gesto 1 e §13 (3) del progetto): i millesimi si salvano tutti insieme dalla pagina delle quote. Una riga sola sulla tabella,
 * un campo per unità cambiata, tolta o aggiunta («Int. 7: 55,60 → 60,00»), anche per la riga tolta con la cancellazione su query, che gli eventi dei
 * modelli non vedevano (il rischio 1 del contesto). Salvare senza cambiare niente non scrive.
 *
 * Che cosa non copre: le quote scritte dall'importazione (gesto del livello) e dalla tabella creata con le quote (stessa pagina, altra strada).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa']);
    $this->andrea->assignRole(Role::AMMINISTRATORE->value);
    $this->c = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->tabella = Tabella::query()->create(['condominio_id' => $this->c->id, 'nome' => 'A — Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi',
        'numero_decimali' => 2, 'attiva' => true, 'data_inizio' => '2026-01-01']);
    $unita = fn (string $interno) => DB::table('immobili')->insertGetId(['condominio_id' => $this->c->id, 'codice_immobile' => 'U' . $interno,
        'nome' => 'Appartamento ' . $interno, 'interno' => $interno, 'attivo' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->int7 = $unita('7');
    $this->int8 = $unita('8');
    $this->int9 = $unita('9');
    $this->q7 = DB::table('quote_tabella')->insertGetId(['tabella_id' => $this->tabella->id, 'immobile_id' => $this->int7, 'valore' => 55.60,
        'created_at' => now(), 'updated_at' => now()]);
    $this->q8 = DB::table('quote_tabella')->insertGetId(['tabella_id' => $this->tabella->id, 'immobile_id' => $this->int8, 'valore' => 44.40,
        'created_at' => now(), 'updated_at' => now()]);
});

it('una riga sola sulla tabella, con un campo per unità cambiata, tolta o aggiunta', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($this->andrea)->put(route('admin.gestionale.tabelle.quote.update', [$this->c->id, $this->tabella->id]), [
        'quote' => [
            ['id' => $this->q7, 'immobile' => ['id' => $this->int7], 'valore' => '60'],
            ['id' => null, 'immobile' => ['id' => $this->int9], 'valore' => '40'],
        ],
    ])->assertSessionHasNoErrors();

    // L'esercizio che il programma crea alla prima apertura del gestionale ha la sua riga, firmata «Il programma».
    $righe = RegistroModifica::query()->where('id', '>', $prima)->where('autore_tipo', 'persona')->get();
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->area)->toBe('millesimi')
        ->and($righe[0]->oggetto_nome)->toBe('Tabella «A — Proprietà»')
        ->and($righe[0]->riepilogo)->toBe('Millesimi della tabella «A — Proprietà» salvati: 3 unità cambiate')
        ->and(collect($righe[0]->campi)->map(fn ($c) => $c['etichetta'] . ': ' . $c['prima'] . ' → ' . $c['dopo'])->sort()->values()->all())
        ->toBe(['Int. 7: 55,60 → 60,00', 'Int. 8: 44,40 → tolta', 'Int. 9: non c\'era → 40,00'])
        ->and($righe[0]->condomini())->toBe([$this->c->id => 'Via Roma 12'])
        ->and(ContestoDelRegistro::scrittureFuoriGesto())->toBe([]);
});

it('salvare i millesimi senza cambiarli non scrive righe', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($this->andrea)->put(route('admin.gestionale.tabelle.quote.update', [$this->c->id, $this->tabella->id]), [
        'quote' => [
            ['id' => $this->q7, 'immobile' => ['id' => $this->int7], 'valore' => '55.60'],
            ['id' => $this->q8, 'immobile' => ['id' => $this->int8], 'valore' => '44.40'],
        ],
    ])->assertSessionHasNoErrors();

    expect(RegistroModifica::query()->where('id', '>', $prima)->where('autore_tipo', 'persona')->count())->toBe(0)
        ->and(RegistroModifica::query()->where('id', '>', $prima)->where('oggetto_tipo', 'esercizio')->value('autore_nome'))->toBe('Il programma');
});
