<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Subentro;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\RegistroModifica;
use App\Models\TitolaritaImmobile;
use App\Models\User;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * 1.12.0-beta.13 (Coda 679, §13 (3) del progetto, gesti 3 e 4, P11): l'area «condominio», con il gesto vero dalla rotta. La titolarità collegata, cambiata
 * e scollegata passa dal suo modello (prima l'aggiornamento e lo scollegamento erano su query); il passaggio è una riga sola con il rimando e le due
 * persone; il suo annullamento una riga con il motivo; la copia autentica un gesto; l'eliminazione del condominio dice che cosa sparisce, letto prima.
 *
 * Che cosa non copre: i passaggi con pertinenze, successioni e conguagli (stesso gesto, altre strade dentro l'azione); il condominio di prova dalla sua
 * pagina (stesso gesto dell'eliminazione, lo prova `CondominioDimostrativoTest` per i dati); le importazioni.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $this->andrea->assignRole(Role::AMMINISTRATORE->value);
    $this->c = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->e = Esercizio::factory()->create(['condominio_id' => $this->c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $this->g = Gestione::factory()->create(['condominio_id' => $this->c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    DB::table('esercizio_gestione')->insertOrIgnore(['esercizio_id' => $this->e->id, 'gestione_id' => $this->g->id, 'attiva' => true]);
    $this->unita = Immobile::query()->create(['condominio_id' => $this->c->id, 'nome' => 'Appartamento', 'interno' => '7', 'codice_immobile' => 'AC-7']);
    $this->mario = Anagrafica::query()->create(['nome' => 'Mario Rossi', 'indirizzo' => 'Via Roma 12', 'codice_fiscale' => 'RSSMRA70A01H501U']);
    $this->ida = Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12', 'codice_fiscale' => 'RMNDIA80A41H501U']);
    $this->mario->condomini()->attach($this->c->id);
    $this->ida->condomini()->attach($this->c->id);
});

function acDopo(int $prima)
{
    return RegistroModifica::query()->where('id', '>', $prima)->orderBy('id')->get();
}

it('la titolarità dalla pagina dell\'unità: collegata, cambiata e scollegata, sempre nella «Storia» dell\'unità', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;
    $this->actingAs($this->andrea)->post(route('admin.gestionale.immobili.anagrafiche.store', [$this->c, $this->unita]), [
        'anagrafica_id' => $this->ida->id, 'tipologia' => 'proprietario', 'quota' => 100, 'data_inizio' => '2019-01-01',
    ])->assertSessionHasNoErrors();
    $titolarita = TitolaritaImmobile::query()->where('anagrafica_id', $this->ida->id)->firstOrFail();

    $collegata = acDopo($prima)->firstWhere('oggetto_tipo', 'titolarita');
    expect($collegata->azione)->toBe('collegata')
        ->and($collegata->riepilogo)->toBe('All\'unità Int. 7: proprietario al 100 % dal 01/01/2019.')
        ->and([$collegata->scheda_tipo, $collegata->scheda_id])->toBe(['immobile', $this->unita->id]);

    $prima = RegistroModifica::query()->max('id');
    $this->actingAs($this->andrea)->put(route('admin.gestionale.immobili.anagrafiche.update', [$this->c, $this->unita, $titolarita]), [
        'anagrafica_id' => $this->ida->id, 'tipologia' => 'proprietario', 'quota' => 50, 'data_inizio' => '2019-01-01',
    ])->assertSessionHasNoErrors();
    $cambiata = acDopo($prima)->firstWhere('oggetto_tipo', 'titolarita');
    expect($cambiata->azione)->toBe('modificata')
        ->and($cambiata->campi[0])->toMatchArray(['etichetta' => 'Quota competenza (%)', 'prima' => '100 %', 'dopo' => '50 %']);

    $prima = RegistroModifica::query()->max('id');
    $this->actingAs($this->andrea)->delete(route('admin.gestionale.immobili.anagrafiche.destroy', [$this->c, $this->unita, $titolarita]))
        ->assertSessionHasNoErrors();
    $scollegata = acDopo($prima)->firstWhere('oggetto_tipo', 'titolarita');
    expect($scollegata->azione)->toBe('scollegata')
        ->and($scollegata->soggetti())->toBe([['anagrafica', $this->ida->id]])
        ->and(TitolaritaImmobile::query()->whereKey($titolarita->id)->exists())->toBeFalse();
});

it('il passaggio è una riga sola, con il rimando e le due persone; l\'annullamento una riga con il motivo', function () {
    $riga = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $this->mario->id, 'immobile_id' => $this->unita->id,
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($this->andrea)->post(route('admin.gestionale.immobili.passaggi.store', [$this->c, $this->unita]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $this->ida->id, 'decorrenza' => '2026-03-01', 'quota' => 100,
        'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-03-01', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ])->assertSessionHasNoErrors();
    $subentro = Subentro::query()->whereNull('subentro_padre_id')->latest('id')->firstOrFail();

    $righe = acDopo($prima);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->azione)->toBe('gesto')
        ->and($righe[0]->riepilogo)->toBe('Passaggio registrato: Int. 7 da Mario Rossi a Ida Romano dal 01/03/2026')
        ->and($righe[0]->rimando)->toMatchArray(['tipo' => 'passaggio', 'id' => $subentro->id])
        ->and($righe[0]->soggetti())->toBe([['anagrafica', $this->mario->id], ['anagrafica', $this->ida->id]])
        ->and($righe[0]->lotto['conteggi'] ?? [])->toHaveKey('passaggio.creata')
        ->and(ContestoDelRegistro::scrittureFuoriGesto())->toBe([]);

    $prima = RegistroModifica::query()->max('id');
    $this->actingAs($this->andrea)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$this->c, $this->unita, $subentro]),
        ['nota_annullamento' => 'Registrato con la data sbagliata, lo rifaccio']);
    $annullato = acDopo($prima);
    expect($annullato)->toHaveCount(1)
        ->and($annullato[0]->riepilogo)->toStartWith('Passaggio annullato: Int. 7 da Mario Rossi a Ida Romano')
        ->and($annullato[0]->perche)->toBe('Registrato con la data sbagliata, lo rifaccio')
        ->and(ContestoDelRegistro::scrittureFuoriGesto())->toBe([]);
});

it('la copia autentica del titolo è un gesto sul passaggio', function () {
    $subentro = Subentro::query()->forceCreate(['condominio_id' => $this->c->id, 'immobile_id' => $this->unita->id, 'tipologia' => 'proprietario',
        'tipo_passaggio' => 'vendita', 'decorrenza' => '2026-03-01', 'anagrafica_uscente_id' => $this->mario->id, 'anagrafica_entrante_id' => $this->ida->id]);
    ContestoDelRegistro::svuotaScrittureFuoriGesto();
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($this->andrea)->patch(route('admin.gestionale.immobili.passaggi.copia-autentica', [$this->c, $this->unita, $subentro]),
        ['copia_autentica_il' => '2026-03-10'])->assertSessionHasNoErrors();

    expect(acDopo($prima)->pluck('riepilogo')->all())->toBe(['Copia autentica del titolo registrata, del 10/03/2026'])
        ->and(ContestoDelRegistro::scrittureFuoriGesto())->toBe([]);
});

it('l\'eliminazione del condominio dice che cosa sparisce, letto prima; la riga resta con il nome copiato', function () {
    $prima = RegistroModifica::query()->max('id') ?? 0;
    $id = $this->c->id;

    $this->actingAs($this->andrea)->delete(route('condomini.destroy', $this->c))->assertSessionHasNoErrors();

    expect(Condominio::query()->whereKey($id)->exists())->toBeFalse();
    $riga = acDopo($prima)->firstWhere('oggetto_tipo', 'condominio');
    expect($riga->azione)->toBe('eliminata')
        ->and($riga->oggetto_nome)->toBe('Condominio Via Roma 12')
        ->and($riga->riepilogo)->toBe('Condominio eliminato: 1 unità, 2 persone collegate')
        ->and($riga->condomini())->toBe([$id => 'Via Roma 12'])
        ->and(acDopo($prima))->toHaveCount(1);
});
