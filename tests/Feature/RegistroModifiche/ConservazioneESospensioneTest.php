<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role;
use App\Models\Condominio;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use App\Services\RegistroModifiche\VerificaDelRegistro;
use App\Services\Restore\RestoreMode;
use App\Settings\RegistroModificheSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 1.12.0-beta.13 (Coda 679, §11 del progetto, P13, decisioni 518 (1), 549 e 550): per quanto tempo si tengono le righe del registro (di serie 10 anni, scelte
 * 10, 15 e 20, solo l'amministratore), il comando pianificato che toglie un **prefisso** per `sequenza` (mai un buco in mezzo) e scrive la riga
 * «conservazione» firmata «Il programma», la verifica che resta integra, e la sospensione della cancellazione per condominio (il motivo obbligatorio, solo
 * l'amministratore, la sospensione e la sua fine come righe del registro): il comando salta i condomini sospesi fermandosi alla loro prima riga.
 *
 * Che cosa non copre: il cron vero (il comando si lancia da qui; la pianificazione si legge dall'elenco degli eventi); le pagine che montano i componenti
 * (a video); un registro di milioni di righe (il comando ne toglie al più `ConservazioneDelRegistro::MASSIMO_PER_VOLTA` per volta).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $this->andrea->assignRole(Role::AMMINISTRATORE->value);
    $this->marco = User::factory()->create(['name' => 'Marco Galli', 'email_verified_at' => now()]);
    $this->marco->assignRole(Role::COLLABORATORE->value);
    $this->roma = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->italia = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    // Le righe della preparazione (i condomini creati) hanno la data di oggi e starebbero in testa alla catena: si parte da un registro vuoto, come una
    // catena nuova, così le righe di ogni prova sono le prime.
    foreach (['registro_modifiche_condomini', 'registro_modifiche_soggetti', 'registro_modifiche'] as $tabella) {
        DB::table($tabella)->delete();
    }
    DB::table('registro_modifiche_catena')->where('id', 1)->update(['ultima_sequenza' => 0, 'ultima_impronta' => null]);
});

afterEach(function () {
    app(RestoreMode::class)->exit();
});

/** Una riga del registro con il giorno del gesto e il condominio. */
function cesRiga(string $giorno, ?Condominio $condominio = null, string $nome = 'Anagrafica di Ida Romano'): RegistroModifica
{
    return RegistroModifiche::scrivi(['area' => 'persone', 'oggetto_tipo' => 'anagrafica', 'oggetto_id' => 7, 'oggetto_nome' => $nome,
        'azione' => 'modificata', 'avvenuta_il' => Carbon::parse($giorno . ' 10:00:00', 'Europe/Rome'),
        'campi' => [['chiave' => 'email', 'etichetta' => 'Indirizzo email primario', 'prima' => 'a@b.it', 'dopo' => 'c@d.it', 'valore_prima' => 'a@b.it',
            'valore_dopo' => 'c@d.it', 'segreto' => false]],
        'condomini' => $condominio === null ? [] : [$condominio->id => $condominio->nome], 'soggetti' => [['anagrafica', 7]]]);
}

it('di serie le righe si tengono 10 anni: lo stato dice «Adesso: 10 anni» e «le righe del 2026 si tolgono da sole nel 2036»', function () {
    $this->travelTo(Carbon::parse('2026-11-19 10:00:00', 'Europe/Rome'));

    $this->actingAs($this->andrea)->getJson(route('admin.registro-modifiche.conservazione.mostra'))
        ->assertOk()
        ->assertJson(['anni' => 10, 'scelte' => [10, 15, 20], 'adesso' => '10 anni', 'frase' => 'le righe del 2026 si tolgono da sole nel 2036',
            'puo_cambiare' => true]);
});

it('la durata la cambia solo l\'amministratore, solo 10, 15 o 20 anni, ed è una riga del registro con le parole della pagina', function () {
    $this->actingAs($this->marco)->putJson(route('admin.registro-modifiche.conservazione.cambia'), ['anni' => 15])->assertForbidden();
    $this->actingAs($this->andrea)->putJson(route('admin.registro-modifiche.conservazione.cambia'), ['anni' => 12])->assertUnprocessable()
        ->assertJsonValidationErrors('anni');
    $prima = (int) RegistroModifica::query()->max('id');

    $this->actingAs($this->andrea)->putJson(route('admin.registro-modifiche.conservazione.cambia'), ['anni' => 15])->assertOk()
        ->assertJson(['anni' => 15, 'adesso' => '15 anni']);

    $riga = RegistroModifica::query()->where('id', '>', $prima)->sole();
    expect(app(RegistroModificheSettings::class)->anni_di_conservazione)->toBe(15)
        ->and($riga->autore_nome)->toBe('Andrea Costa')
        ->and($riga->oggetto_nome)->toBe('Registro delle modifiche')
        ->and($riga->campi[0])->toMatchArray(['etichetta' => 'Per quanto tempo si tengono le righe', 'prima' => '10 anni', 'dopo' => '15 anni']);
});

it('il comando toglie le righe più vecchie della durata, scrive la riga «conservazione» firmata «Il programma», e la verifica resta integra', function () {
    $vecchie = [cesRiga('2015-03-01', $this->roma), cesRiga('2016-05-01', $this->italia), cesRiga('2026-05-30', $this->roma)];
    $giovane = cesRiga('2027-01-10', $this->roma);
    $this->travelTo(Carbon::parse('2036-06-01 03:30:00', 'Europe/Rome'));

    $this->artisan('kondomanager:conserva-registro')->assertSuccessful();

    $conservazione = RegistroModifica::query()->where('azione', 'conservazione')->sole();
    expect(RegistroModifica::query()->whereIn('id', collect($vecchie)->pluck('id'))->count())->toBe(0)
        ->and(RegistroModifica::query()->whereKey($giovane->id)->exists())->toBeTrue()
        ->and(DB::table('registro_modifiche_condomini')->whereIn('riga_id', collect($vecchie)->pluck('id'))->count())->toBe(0)
        ->and(DB::table('registro_modifiche_soggetti')->whereIn('riga_id', collect($vecchie)->pluck('id'))->count())->toBe(0)
        ->and($conservazione->autore_tipo)->toBe('programma')
        ->and($conservazione->autore_nome)->toBe('Il programma')
        ->and($conservazione->lotto)->toMatchArray(['righe_tolte' => 3, 'ultima_sequenza_tolta' => 3, 'anni' => 10,
            'ultima_impronta_tolta' => DB::table('registro_modifiche')->where('id', $giovane->id)->value('impronta_precedente')])
        ->and($conservazione->riepilogo)->toBe('Tolte 3 righe, fino a quella del 30/05/2026: più vecchie di 10 anni.')
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue();
});

it('non fa buchi: la prima riga dentro la durata ferma il prefisso, anche se dopo ce n\'è una più vecchia', function () {
    $prima = cesRiga('2015-03-01', $this->roma);
    $dentro = cesRiga('2030-01-01', $this->roma);
    $dopoMaVecchia = cesRiga('2016-01-01', $this->roma);
    $this->travelTo(Carbon::parse('2036-06-01 03:30:00', 'Europe/Rome'));

    $this->artisan('kondomanager:conserva-registro')->assertSuccessful();

    expect(RegistroModifica::query()->whereKey($prima->id)->exists())->toBeFalse()
        ->and(RegistroModifica::query()->whereKey($dentro->id)->exists())->toBeTrue()
        ->and(RegistroModifica::query()->whereKey($dopoMaVecchia->id)->exists())->toBeTrue()
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue();
});

it('senza niente da togliere non scrive nessuna riga', function () {
    cesRiga('2030-01-01', $this->roma);
    $this->travelTo(Carbon::parse('2036-06-01 03:30:00', 'Europe/Rome'));

    $this->artisan('kondomanager:conserva-registro')->assertSuccessful();

    expect(RegistroModifica::query()->where('azione', 'conservazione')->count())->toBe(0);
});

it('sospendere la cancellazione chiede il motivo, è solo dell\'amministratore, ed è una riga del registro del condominio; lo stato lo dice', function () {
    $this->travelTo(Carbon::parse('2035-03-12 10:00:00', 'Europe/Rome'));
    $rotta = route('admin.gestionale.registro-modifiche.sospensione.sospendi', $this->roma);

    $this->actingAs($this->marco)->postJson($rotta, ['motivo' => 'causa Rossi'])->assertForbidden();
    $this->actingAs($this->andrea)->postJson($rotta, ['motivo' => '  '])->assertUnprocessable()->assertJsonValidationErrors('motivo');
    $this->actingAs($this->andrea)->postJson($rotta, ['motivo' => 'causa Rossi, tribunale di Napoli'])->assertOk()
        ->assertJson(['sospesa' => true, 'dal' => '12/03/2035', 'motivo' => 'causa Rossi, tribunale di Napoli', 'da' => 'Andrea Costa']);
    $this->actingAs($this->andrea)->postJson($rotta, ['motivo' => 'di nuovo'])->assertUnprocessable();

    $riga = RegistroModifica::query()->where('oggetto_tipo', 'sospensione_cancellazione')->sole();
    expect($riga->condomini())->toBe([$this->roma->id => 'Via Roma 12'])
        ->and($riga->perche)->toBe('causa Rossi, tribunale di Napoli')
        ->and($riga->riepilogo)->toBe('Cancellazione automatica del registro sospesa per questo condominio.')
        ->and($riga->autore_nome)->toBe('Andrea Costa');
    $this->actingAs($this->andrea)->getJson(route('admin.gestionale.registro-modifiche.sospensione.mostra', $this->roma))
        ->assertOk()->assertJson(['sospesa' => true, 'dal' => '12/03/2035', 'puo_cambiare' => true]);
    $this->actingAs($this->andrea)->getJson(route('admin.gestionale.registro-modifiche.sospensione.mostra', $this->italia))
        ->assertOk()->assertJson(['sospesa' => false]);
});

it('togliere la sospensione chiede il motivo ed è una riga del registro; senza sospensione non si toglie niente', function () {
    $sospendi = route('admin.gestionale.registro-modifiche.sospensione.sospendi', $this->roma);
    $togli = route('admin.gestionale.registro-modifiche.sospensione.togli', $this->roma);
    $this->actingAs($this->andrea)->deleteJson($togli, ['motivo' => 'causa chiusa'])->assertUnprocessable();
    $this->actingAs($this->andrea)->postJson($sospendi, ['motivo' => 'causa Rossi'])->assertOk();

    $this->actingAs($this->marco)->deleteJson($togli, ['motivo' => 'causa chiusa'])->assertForbidden();
    $this->actingAs($this->andrea)->deleteJson($togli, ['motivo' => ''])->assertUnprocessable()->assertJsonValidationErrors('motivo');
    $this->actingAs($this->andrea)->deleteJson($togli, ['motivo' => 'causa chiusa con la sentenza del 10/01/2036'])->assertOk()
        ->assertJson(['sospesa' => false]);

    $righe = RegistroModifica::query()->where('oggetto_tipo', 'sospensione_cancellazione')->orderBy('id')->get();
    expect($righe)->toHaveCount(2)
        ->and($righe[1]->riepilogo)->toBe('Sospensione della cancellazione automatica tolta.')
        ->and($righe[1]->perche)->toBe('causa chiusa con la sentenza del 10/01/2036');
});

it('un condominio sospeso ferma il prefisso alla sua prima riga; tolta la sospensione, la volta dopo si toglie il resto', function () {
    $prima = cesRiga('2014-03-01', $this->italia);
    $diRoma = cesRiga('2015-03-01', $this->roma);
    $dopo = cesRiga('2016-03-01', $this->italia);
    $this->travelTo(Carbon::parse('2035-03-12 10:00:00', 'Europe/Rome'));
    $this->actingAs($this->andrea)->postJson(route('admin.gestionale.registro-modifiche.sospensione.sospendi', $this->roma), ['motivo' => 'causa Rossi'])
        ->assertOk();
    auth()->logout();
    $this->travelTo(Carbon::parse('2036-06-01 03:30:00', 'Europe/Rome'));

    $this->artisan('kondomanager:conserva-registro')->assertSuccessful();

    $conservazione = RegistroModifica::query()->where('azione', 'conservazione')->sole();
    expect(RegistroModifica::query()->whereKey($prima->id)->exists())->toBeFalse()
        ->and(RegistroModifica::query()->whereKey($diRoma->id)->exists())->toBeTrue()
        ->and(RegistroModifica::query()->whereKey($dopo->id)->exists())->toBeTrue()
        ->and($conservazione->riepilogo)->toContain('Via Roma 12 ha la cancellazione sospesa')
        ->and($conservazione->lotto['fermata_da'] ?? null)->toBe([$this->roma->id => 'Via Roma 12'])
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue();

    $this->actingAs($this->andrea)->deleteJson(route('admin.gestionale.registro-modifiche.sospensione.togli', $this->roma), ['motivo' => 'causa chiusa'])
        ->assertOk();
    auth()->logout();
    $this->travelTo(Carbon::parse('2036-06-02 03:30:00', 'Europe/Rome'));
    $this->artisan('kondomanager:conserva-registro')->assertSuccessful();

    expect(RegistroModifica::query()->whereKey($diRoma->id)->exists())->toBeFalse()
        ->and(RegistroModifica::query()->whereKey($dopo->id)->exists())->toBeFalse()
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue();
});

it('la sospensione e la durata non si leggono senza il ruolo dell\'amministratore', function () {
    $condomino = User::factory()->create(['email_verified_at' => now()]);
    $condomino->assignRole(Role::UTENTE->value);

    $this->actingAs($this->marco)->getJson(route('admin.gestionale.registro-modifiche.sospensione.mostra', $this->roma))->assertForbidden();
    $this->actingAs($this->marco)->getJson(route('admin.registro-modifiche.conservazione.mostra'))->assertForbidden();
    $this->actingAs($condomino)->getJson(route('admin.registro-modifiche.conservazione.mostra'))->assertForbidden();
});

it('il comando è pianificato una volta al giorno e, come gli altri, non gira durante un ripristino', function () {
    $evento = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'conserva-registro-modifiche');
    expect($evento)->not->toBeNull()
        ->and($evento->expression)->toBe('15 3 * * *');

    app(RestoreMode::class)->enter('r-in-corso', 'it');
    expect($evento->filtersPass(app()))->toBeFalse();
});

// ── La cancellazione ferma per tutto lo studio (decisione 558) e la sospensione di un condominio eliminato (scelta del 19/11). ──────────────────────────────────────────────────────────

it('lo stato della durata elenca le sospensioni attive: la cancellazione è ferma per tutto lo studio (decisione 558)', function () {
    $this->travelTo(Carbon::parse('2035-03-03 10:00:00', 'Europe/Rome'));
    $this->actingAs($this->andrea)->postJson(route('admin.gestionale.registro-modifiche.sospensione.sospendi', $this->roma), ['motivo' => 'causa Rossi'])
        ->assertOk();

    $this->actingAs($this->andrea)->getJson(route('admin.registro-modifiche.conservazione.mostra'))->assertOk()
        ->assertJsonPath('sospensioni', [['condominio_id' => $this->roma->id, 'nome' => 'Via Roma 12', 'dal' => '03/03/2035', 'motivo' => 'causa Rossi',
            'da' => 'Andrea Costa', 'eliminato' => false]]);
});

it('la sospensione di un condominio eliminato si toglie dallo studio: l\'elenco con il nome copiato e il DELETE per numero, con il motivo e la riga', function () {
    $this->travelTo(Carbon::parse('2035-03-03 10:00:00', 'Europe/Rome'));
    $this->actingAs($this->andrea)->postJson(route('admin.gestionale.registro-modifiche.sospensione.sospendi', $this->roma), ['motivo' => 'causa Rossi'])
        ->assertOk();
    $id = $this->roma->id;
    $this->roma->delete();
    $elenco = route('admin.registro-modifiche.sospensioni.eliminati');
    $togli = route('admin.registro-modifiche.sospensioni.togli', ['condominio_id' => $id]);

    $this->actingAs($this->marco)->getJson($elenco)->assertForbidden();
    $this->actingAs($this->andrea)->getJson($elenco)->assertOk()
        ->assertJsonPath('sospensioni', [['condominio_id' => $id, 'nome' => 'Via Roma 12', 'dal' => '03/03/2035', 'motivo' => 'causa Rossi',
            'da' => 'Andrea Costa', 'eliminato' => true]]);
    $this->actingAs($this->marco)->deleteJson($togli, ['motivo' => 'causa chiusa'])->assertForbidden();
    $this->actingAs($this->andrea)->deleteJson($togli, ['motivo' => ''])->assertUnprocessable()->assertJsonValidationErrors('motivo');
    $this->actingAs($this->andrea)->deleteJson($togli, ['motivo' => 'causa chiusa con la sentenza del 10/01/2036'])->assertOk()
        ->assertJsonPath('sospensioni', []);

    $riga = RegistroModifica::query()->where('oggetto_tipo', 'sospensione_cancellazione')->orderByDesc('id')->first();
    expect($riga->riepilogo)->toBe('Sospensione della cancellazione automatica tolta.')
        ->and($riga->perche)->toBe('causa chiusa con la sentenza del 10/01/2036')
        ->and($riga->condomini())->toBe([$id => 'Via Roma 12'])
        ->and(\App\Services\RegistroModifiche\SospensioneDellaCancellazione::condominiSospesi())->toBe([]);
    $this->actingAs($this->andrea)->deleteJson($togli, ['motivo' => 'di nuovo'])->assertUnprocessable();
});
