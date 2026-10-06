<?php

/**
 * 1.11.0-beta.42, decisione 37 — il passaggio porta tutta la quota di chi esce.
 *
 * «La quota cambia» sbloccava la quota del passaggio, ma nessun numero diverso da quella di chi esce aveva un esito giusto: la
 * registrazione chiudeva tutta la riga di chi esce e apriva chi entra alla quota scritta, e il resto non era di nessuno (Elsa
 * al 100 % che «vende 50» a Carlo: il riparto addebitava a Carlo anche l'altra metà). Ora la richiesta lo rifiuta, come già
 * per la riserva (decisione 28), e il modulo non offre più la casella.
 *
 * Cosa NON copre: la vendita di una parte della propria quota, con chi vende che resta sulla parte che tiene (Coda 175, da
 * costruire prima della stabile); l'estinzione, dove la quota del modulo non conta (tornano pieni i nudi, ciascuno alla sua).
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    $this->c = Condominio::factory()->create();
    Esercizio::factory()->create(['condominio_id' => $this->c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $this->unita = Immobile::create(['condominio_id' => $this->c->id, 'tipo' => 'appartamento', 'codice_immobile' => 'QP-1', 'nome' => 'Interno 1', 'interno' => '1']);
    $this->p = [];
    foreach (['Elsa Prima', 'Carlo Secondo', 'Ines Terza', 'Dino Quarto'] as $i => $nome) {
        $this->p[$i] = Anagrafica::forceCreate(['nome' => $nome, 'email' => "qp{$i}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'QPQUOTA' . str_pad((string) $i, 9, '0', STR_PAD_LEFT)]);
        $this->p[$i]->condomini()->syncWithoutDetaching([$this->c->id]);
    }
    $this->rigaElsa = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $this->p[0]->id, 'immobile_id' => $this->unita->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $this->rigaInes = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $this->p[2]->id, 'immobile_id' => $this->unita->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2025-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
});

function qpRegistra($test, array $dati)
{
    $dati += ['decorrenza' => '2026-05-01', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Atto letto, quota controllata'];

    return [
        $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$test->c, $test->unita]), $dati),
        $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$test->c, $test->unita]), $dati),
    ];
}

it('decisione 37 — la vendita di 50 su 100 si rifiuta, nell\'anteprima e nella registrazione, e le righe non cambiano', function () {
    [$anteprima, $registrazione] = qpRegistra($this, ['tipo' => 'vendita', 'riga_uscente_id' => $this->rigaElsa, 'anagrafica_entrante_id' => $this->p[1]->id, 'quota' => 50, 'tipologia' => 'proprietario']);

    $frase = 'Il passaggio porta tutta la quota di Elsa Prima (100 %): passarne solo una parte non è ancora previsto.';
    expect($anteprima->status())->toBe(422)->and($anteprima->json('errors.quota.0'))->toStartWith($frase)
        // Rilievo A9 della Fase 1-bis: il rifiuto dice anche la via a mano, con l'avvertenza sul riparto.
        ->and($anteprima->json('errors.quota.0'))->toContain('registralo a mano')->toContain('dal giorno dell\'atto')->toContain('vale da sempre');
    $registrazione->assertSessionHasErrors('quota');
    expect(session('errors')->first('quota'))->toStartWith($frase);
    // Prima: Elsa chiusa al 30/4 e Carlo al 50 % dal 1/5; l'altra metà di nessuno.
    expect(DB::table('anagrafica_immobile')->where('immobile_id', $this->unita->id)->whereNotNull('data_fine')->count())->toBe(0)
        ->and(DB::table('anagrafica_immobile')->where('anagrafica_id', $this->p[1]->id)->exists())->toBeFalse();
});

it('decisione 37 — lo stesso per la costituzione dell\'usufrutto su una parte e per il cambio d\'inquilino a una quota diversa', function () {
    [$a1] = qpRegistra($this, ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $this->rigaElsa,
        'anagrafica_entrante_id' => $this->p[1]->id, 'quota' => 50, 'tipologia' => 'usufruttuario']);
    [$a2] = qpRegistra($this, ['tipo' => 'fine_locazione', 'riga_uscente_id' => $this->rigaInes, 'anagrafica_entrante_id' => $this->p[3]->id, 'quota' => 60, 'tipologia' => 'inquilino']);

    expect($a1->json('errors.quota.0'))->toStartWith('Il passaggio porta tutta la quota di Elsa Prima (100 %): passarne solo una parte non è ancora previsto.')
        ->and($a2->json('errors.quota.0'))->toStartWith('Il passaggio porta tutta la quota di Ines Terza (100 %): passarne solo una parte non è ancora previsto.');
});

it('decisione 37, controllo — con la quota di chi esce la vendita passa', function () {
    [$anteprima, $registrazione] = qpRegistra($this, ['tipo' => 'vendita', 'riga_uscente_id' => $this->rigaElsa, 'anagrafica_entrante_id' => $this->p[1]->id, 'quota' => 100, 'tipologia' => 'proprietario']);

    $anteprima->assertOk();
    $registrazione->assertSessionHasNoErrors()->assertRedirect();
    expect((float) DB::table('anagrafica_immobile')->where('anagrafica_id', $this->p[1]->id)->value('quota'))->toBe(100.0);
});
