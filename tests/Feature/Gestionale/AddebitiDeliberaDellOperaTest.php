<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\User;
use App\Services\Gestionale\DeliberaDellOpera;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * 1.12.0-beta.13 (§16 del progetto della tappa 1, P17; decisioni 322 e 11): gli addebiti propongono la persona titolare il giorno della delibera che ha
 * approvato l'opera, con la stessa regola del rendiconto per persona (`DeliberaDellOpera`, un posto solo): il primo piano attivo, quello con la delibera più
 * vecchia (a parità il numero più basso), non il piano con l'id più basso; un piano spento non conta; con più delibere diverse nell'esercizio del primo
 * piano non si propone nessuno e la finestra lo dice. «Straordinaria» è la natura della gestione, non il tipo del piano.
 *
 * Che cosa non copre: la finestra a video (la parola `nessuna_proposta` la mostra `NuovoAddebitoDialog.vue`, provata in `NuovoAddebitoDialog.test.ts`); le spese con la competenza scritta sulla
 * fattura (vale la competenza, non la delibera); `NotaSolidarieta.php:357-362` e `SeiMesi.php:228`, che usano la delibera del piano (fuori, nel verbale).
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $this->andrea->assignRole(Role::AMMINISTRATORE->value);
    $this->c = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->e2026 = Esercizio::factory()->create(['condominio_id' => $this->c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31', 'stato' => 'chiuso']);
    $this->e2027 = Esercizio::factory()->create(['condominio_id' => $this->c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01',
        'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $this->g = Gestione::factory()->create(['condominio_id' => $this->c->id, 'nome' => 'Rifacimento del tetto', 'tipo' => 'straordinaria',
        'data_inizio' => '2026-01-01', 'data_fine' => '2027-12-31']);
    foreach ([$this->e2026, $this->e2027] as $e) {
        DB::table('esercizio_gestione')->insertOrIgnore(['esercizio_id' => $e->id, 'gestione_id' => $this->g->id, 'attiva' => true]);
    }
    $this->unita = Immobile::query()->create(['condominio_id' => $this->c->id, 'nome' => 'Appartamento', 'interno' => '7', 'codice_immobile' => 'ADO-7']);
    $this->mario = Anagrafica::query()->create(['nome' => 'Mario Rossi', 'indirizzo' => 'Via Roma 12']);
    $this->ida = Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12']);
    foreach ([[$this->mario, '2019-01-01', '2026-03-31'], [$this->ida, '2026-04-01', null]] as [$p, $dal, $al]) {
        $p->condomini()->attach($this->c->id);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $this->unita->id, 'tipologia' => 'proprietario', 'quota' => 100,
            'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);
    }
});

function adoPiano($test, string $nome, Esercizio $e, ?string $delibera, bool $attivo = true): PianoRate
{
    return PianoRate::query()->forceCreate(['gestione_id' => $test->g->id, 'condominio_id' => $test->c->id, 'esercizio_id' => $e->id, 'nome' => $nome,
        'tipo' => 'straordinario', 'stato' => 'approvato', 'attivo' => $attivo, 'data_delibera_assemblea' => $delibera, 'numero_rate' => 1]);
}

function adoProposta($test, Esercizio $e): array
{
    return $test->actingAs($test->andrea)->getJson(route('admin.gestionale.addebiti.proposta', [$test->c]) . '?' . http_build_query([
        'gestione_id' => $test->g->id, 'esercizio_id' => $e->id, 'immobile_id' => $test->unita->id]))->assertOk()->json();
}

it('decisione 322: il primo piano è quello con la delibera più vecchia, non quello con l\'id più basso', function () {
    // Il piano del 2027 è scritto per primo (id più basso) con la delibera più recente; quello del 2026 approva l'opera, prima del passaggio a Ida.
    adoPiano($this, 'Saldo del tetto', $this->e2027, '2027-02-01');
    adoPiano($this, 'Tetto 2026', $this->e2026, '2026-02-01');

    $proposta = adoProposta($this, $this->e2027);

    // La prima proposta è il titolare il giorno della delibera dell'opera; dopo, la finestra mette gli altri titolari dell'esercizio.
    expect($proposta['persone'][0])->toMatchArray(['nome' => 'Mario Rossi', 'perche' => 'titolare il giorno della delibera, 01/02/2026'])
        ->and($proposta['nessuna_proposta'])->toBeNull()
        ->and(app(DeliberaDellOpera::class)->perEsercizio($this->g, $this->e2027->id))->toBe(['delibera' => '2026-02-01', 'motivo' => null]);
});

it('un piano spento non conta, anche se la sua delibera è la più vecchia', function () {
    adoPiano($this, 'Tetto, prima stesura', $this->e2026, '2026-01-10', attivo: false);
    adoPiano($this, 'Tetto 2026', $this->e2026, '2026-05-01');

    expect(adoProposta($this, $this->e2026)['persone'][0])->toMatchArray(['nome' => 'Ida Romano', 'perche' => 'titolare il giorno della delibera, 01/05/2026']);
});

it('due piani attivi nello stesso esercizio con delibere diverse: nessuna persona proposta per la delibera, il motivo, e i titolari fra cui scegliere', function () {
    adoPiano($this, 'Tetto, primo lotto', $this->e2026, '2026-02-01');
    adoPiano($this, 'Tetto, secondo lotto', $this->e2026, '2026-06-01');

    $proposta = adoProposta($this, $this->e2026);

    // Il programma non sceglie a chi addebitare (nessuna persona «titolare il giorno della delibera»); la scelta è dell'amministratore, fra i titolari
    // dell'unità, che la finestra mostra senza sceglierne uno (`NuovoAddebitoDialog.vue`).
    expect($proposta['nessuna_proposta'])->toBe(DeliberaDellOpera::FRASE_DELIBERE_DIVERSE)
        ->and(collect($proposta['persone'])->pluck('nome')->sort()->values()->all())->toBe(['Ida Romano', 'Mario Rossi'])
        ->and(collect($proposta['persone'])->pluck('perche')->filter(fn ($p) => str_starts_with($p, 'titolare il giorno della delibera'))->all())->toBe([]);
});
