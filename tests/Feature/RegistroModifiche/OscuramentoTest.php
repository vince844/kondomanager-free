<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Fornitore;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\RegistroModifiche\Campo;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use App\Services\RegistroModifiche\VerificaDelRegistro;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 1.12.0-beta.13 (Coda 679, §10 del progetto, P6, P9, P14, decisione 518 (2), bozza scena 4): «Oscura i suoi dati nel registro», solo per l'amministratore,
 * con il perché obbligatorio. Le righe sono quelle con la persona come oggetto o fra i soggetti, senza quelle annullate da un ripristino. L'oscuramento
 * sostituisce **solo i dati della persona** dichiarati dalla scheda e, nel riepilogo, nel perché e negli altri campi, **solo i testi uguali ai suoi dati**:
 * restano chi, quando e su che cosa, gli importi, le date e le altre persone. Le righe di altre schede che contengono i suoi dati si trovano con una
 * ricerca e si oscurano solo se l'amministratore le sceglie. L'oscuramento è a sua volta una riga, e la catena resta integra.
 *
 * Che cosa non copre: la finestra a video (i suoi test in `components/registroModifiche/`); un testo dei suoi dati scritto in un altro modo (con un errore
 * di battitura, in maiuscolo dove MySQL confronta le maiuscole: la ricerca trova i testi uguali); i dati nell'intestazione della riga («Anagrafica di Ida
 * Romano»), che restano con la catena, come nella bozza.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-12-16 09:12:00', 'Europe/Rome'));
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $this->andrea->assignRole(Role::AMMINISTRATORE->value);
    $this->marco = User::factory()->create(['name' => 'Marco Galli', 'email_verified_at' => now()]);
    $this->marco->assignRole(Role::COLLABORATORE->value);
    $this->roma = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->ida = Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12', 'email' => 'ida.romano@libero.it',
        'telefono' => '333 120 4455']);
    $this->ida->condomini()->attach($this->roma->id);
    // 0. La scheda di Ida nata: «Creata», con i suoi dati.
    $this->rigaCreata = RegistroModifica::query()->where('oggetto_tipo', 'anagrafica')->where('oggetto_id', $this->ida->id)->where('azione', 'creata')->sole();
    $this->mario = Anagrafica::query()->create(['nome' => 'Mario Rossi', 'indirizzo' => 'Via Roma 12']);

    // 1. La scheda di Ida, cambiata dalla pagina: email e telefono.
    $this->actingAs($this->marco);
    $this->ida->update(['email' => 'ida.romano@gmail.com', 'telefono' => '347 880 1290']);
    $this->rigaScheda = RegistroModifica::query()->where('oggetto_tipo', 'anagrafica')->where('oggetto_id', $this->ida->id)->where('azione', 'modificata')
        ->latest('id')->firstOrFail();
    // 2. Un gesto con due persone: il passaggio.
    $this->rigaPassaggio = RegistroModifiche::scrivi(['area' => 'condominio', 'oggetto_tipo' => 'passaggio', 'oggetto_id' => 3, 'oggetto_nome' => 'Passaggio dell\'unità Int. 7',
        'azione' => 'gesto', 'riepilogo' => 'Passaggio registrato: Int. 7 da Mario Rossi a Ida Romano dal 01/03/2026', 'lotto' => ['conteggi' => ['titolarita.creata' => 1]],
        'condomini' => [$this->roma->id => 'Via Roma 12'], 'soggetti' => [['anagrafica', $this->mario->id], ['anagrafica', $this->ida->id]]]);
    // 3. Un legame: i destinatari di una comunicazione, con due persone.
    $this->rigaDestinatari = RegistroModifiche::scrivi(['area' => 'comunicazioni', 'oggetto_tipo' => 'comunicazione', 'oggetto_id' => 9,
        'oggetto_nome' => 'Comunicazione «Lavori al portone»', 'azione' => 'modificata',
        'campi' => [Campo::nuovo('anagrafiche', 'Destinatari', 'Mario Rossi', 'Mario Rossi, Ida Romano', [$this->mario->id], [$this->mario->id, $this->ida->id])],
        'condomini' => [$this->roma->id => 'Via Roma 12'], 'soggetti' => [['anagrafica', $this->mario->id], ['anagrafica', $this->ida->id]]]);
    // 4. La scheda di un'altra persona che nelle note ha l'email di Ida: non è fra le sue righe, si trova con la ricerca.
    $this->mario->update(['note' => 'Per le chiavi chiedere a ida.romano@libero.it, la vicina']);
    $this->rigaAltra = RegistroModifica::query()->where('oggetto_tipo', 'anagrafica')->where('oggetto_id', $this->mario->id)->where('azione', 'modificata')
        ->latest('id')->firstOrFail();
    // 5. Una riga che non c'entra.
    $this->rigaEstranea = RegistroModifiche::scrivi(['area' => 'contabilita', 'oggetto_tipo' => 'fattura_passiva', 'oggetto_id' => 88,
        'oggetto_nome' => 'Fattura n. 88 di Serramenti Marino', 'azione' => 'creata', 'riepilogo' => 'Registrata, € 14.500,00']);
    auth()->logout();
    $this->travelTo(Carbon::parse('2027-03-12 10:00:00', 'Europe/Rome'));
});

function osUrl(string $tipo, int $id): string
{
    return route('admin.registro-modifiche.oscuramento.anteprima', ['soggetto_tipo' => $tipo, 'soggetto_id' => $id]);
}

it('l\'anteprima: chi è, quante righe con i suoi dati, dal … al …, le righe, e le altre righe che contengono i suoi dati', function () {
    $risposta = $this->actingAs($this->andrea)->getJson(osUrl('anagrafica', $this->ida->id))->assertOk();

    expect($risposta->json('soggetto'))->toMatchArray(['tipo' => 'anagrafica', 'id' => $this->ida->id, 'nome' => 'Ida Romano', 'parola' => 'Anagrafica'])
        ->and($risposta->json('righe.quante'))->toBe(4)
        ->and($risposta->json('righe.dal'))->toBe('16/12/2026')
        ->and($risposta->json('righe.al'))->toBe('16/12/2026')
        ->and(collect($risposta->json('righe.elenco'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->rigaCreata->id, $this->rigaScheda->id, $this->rigaPassaggio->id, $this->rigaDestinatari->id])->sort()->values()->all())
        ->and($risposta->json('altre.quante'))->toBe(1)
        ->and($risposta->json('altre.elenco.0'))->toMatchArray(['id' => $this->rigaAltra->id, 'contiene' => 'ida.romano@libero.it'])
        ->and($risposta->json('sostituzione'))->toBe('oscurato il 12/03/2027');
});

it('solo l\'amministratore, e il perché è obbligatorio', function () {
    $this->actingAs($this->marco)->getJson(osUrl('anagrafica', $this->ida->id))->assertForbidden();
    $this->actingAs($this->marco)->postJson(osUrl('anagrafica', $this->ida->id), ['perche' => 'Richiesta scritta'])->assertForbidden();
    $this->actingAs($this->andrea)->postJson(osUrl('anagrafica', $this->ida->id), ['perche' => ''])->assertUnprocessable()->assertJsonValidationErrors('perche');
    $this->actingAs($this->andrea)->getJson(osUrl('condominio', $this->roma->id))->assertNotFound();

    expect(RegistroModifica::query()->where('azione', 'oscuramento')->count())->toBe(0);
});

it('oscura solo i suoi dati: i campi della persona interi, e negli altri testi solo i suoi; restano chi, quando, su che cosa, gli importi e le altre persone', function () {
    $this->actingAs($this->andrea)->postJson(osUrl('anagrafica', $this->ida->id), [
        'perche' => 'Richiesta scritta del 10/03/2027, protocollo 41', 'altre' => [$this->rigaAltra->id],
    ])->assertOk()->assertJson(['righe' => 5, 'esito' => 'Fatto: 5 righe oscurate il 12/03/2027.']);

    $scheda = $this->rigaScheda->refresh();
    $email = collect($scheda->campi)->firstWhere('chiave', 'email');
    $telefono = collect($scheda->campi)->firstWhere('chiave', 'telefono');
    expect($email)->toMatchArray(['etichetta' => 'Indirizzo email primario', 'prima' => 'oscurato il 12/03/2027', 'dopo' => 'oscurato il 12/03/2027',
        'valore_prima' => null, 'valore_dopo' => null])
        ->and($telefono['prima'])->toBe('oscurato il 12/03/2027')
        ->and($scheda->autore_nome)->toBe('Marco Galli')
        ->and($scheda->oggetto_nome)->toBe('Anagrafica di Ida Romano')
        ->and($scheda->oscurata_il)->not->toBeNull();
    expect($this->rigaPassaggio->refresh()->riepilogo)->toBe('Passaggio registrato: Int. 7 da Mario Rossi a oscurato il 12/03/2027 dal 01/03/2026')
        ->and($this->rigaDestinatari->refresh()->campi[0])->toMatchArray(['prima' => 'Mario Rossi', 'dopo' => 'Mario Rossi, oscurato il 12/03/2027'])
        ->and($this->rigaAltra->refresh()->campi[0]['dopo'])->toBe('Per le chiavi chiedere a oscurato il 12/03/2027, la vicina')
        ->and($this->rigaEstranea->refresh()->riepilogo)->toBe('Registrata, € 14.500,00')
        ->and($this->rigaEstranea->oscurata_il)->toBeNull();

    $oscuramento = RegistroModifica::query()->where('azione', 'oscuramento')->sole();
    expect($oscuramento->perche)->toBe('Richiesta scritta del 10/03/2027, protocollo 41')
        ->and($oscuramento->autore_nome)->toBe('Andrea Costa')
        ->and($oscuramento->oggetto_nome)->toBe('Anagrafica di Ida Romano')
        ->and(collect($oscuramento->lotto['righe'])->sort()->values()->all())
        ->toBe(collect([$this->rigaCreata->id, $scheda->id, $this->rigaPassaggio->id, $this->rigaDestinatari->id, $this->rigaAltra->id])->sort()->values()->all())
        ->and($oscuramento->lotto['campi'][$scheda->id] ?? null)->toContain('email')
        ->and($scheda->oscurata_da)->toBe($oscuramento->id)
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue();
});

it('una seconda volta non trova più niente da oscurare, e non scrive una riga vuota', function () {
    $this->actingAs($this->andrea)->postJson(osUrl('anagrafica', $this->ida->id), ['perche' => 'Richiesta'])->assertOk();

    $this->actingAs($this->andrea)->getJson(osUrl('anagrafica', $this->ida->id))->assertOk()->assertJsonPath('righe.quante', 0);
    $this->actingAs($this->andrea)->postJson(osUrl('anagrafica', $this->ida->id), ['perche' => 'Di nuovo'])->assertUnprocessable();

    expect(RegistroModifica::query()->where('azione', 'oscuramento')->count())->toBe(1);
});

it('le righe annullate da un ripristino si saltano: l\'anagrafica con lo stesso numero, dopo, non è la stessa persona (P14)', function () {
    $prima = (int) RegistroModifica::query()->max('sequenza');
    $annullata = RegistroModifiche::scrivi(['area' => 'persone', 'oggetto_tipo' => 'anagrafica', 'oggetto_id' => $this->ida->id,
        'oggetto_nome' => 'Anagrafica di Ida Romano', 'azione' => 'modificata', 'soggetti' => [['anagrafica', $this->ida->id]],
        'campi' => [Campo::nuovo('telefono', 'Telefono', '347 880 1290', '348 000 0000', '347 880 1290', '348 000 0000')]]);
    RegistroModifiche::scrivi(['area' => 'impostazioni', 'oggetto_tipo' => 'copia', 'oggetto_nome' => 'Copia del 01/03/2027', 'azione' => 'ripristino_copia',
        'lotto' => ['sequenza_comune' => $prima, 'copia_del' => '2027-03-01']]);

    $risposta = $this->actingAs($this->andrea)->getJson(osUrl('anagrafica', $this->ida->id))->assertOk();

    expect(collect($risposta->json('righe.elenco'))->pluck('id')->all())->not->toContain($annullata->id)
        ->and($risposta->json('righe.quante'))->toBe(4);
});

it('anche per un fornitore persona fisica', function () {
    $fornitore = Fornitore::query()->create(['ragione_sociale' => 'Carlo Fontana impianti', 'codice_fiscale' => 'FNTCRL70A01F839X',
        'email' => 'carlo.fontana@example.it']);
    $fornitore->update(['email' => 'fontana.impianti@example.it']);

    $risposta = $this->actingAs($this->andrea)->getJson(osUrl('fornitore', $fornitore->id))->assertOk();
    expect($risposta->json('soggetto.parola'))->toBe('Fornitore')
        ->and($risposta->json('righe.quante'))->toBeGreaterThanOrEqual(2);

    $this->actingAs($this->andrea)->postJson(osUrl('fornitore', $fornitore->id), ['perche' => 'Richiesta del 10/03/2027'])->assertOk();
    $riga = RegistroModifica::query()->where('oggetto_tipo', 'fornitore')->where('oggetto_id', $fornitore->id)->where('azione', 'modificata')->latest('id')->first();
    expect(collect($riga->campi)->firstWhere('chiave', 'email')['prima'])->toBe('oscurato il 12/03/2027');
});

// ── La riga sotto il titolo della finestra, composta dal programma con ciò che sa. ─────────────────────

function osDescrizione($test, string $tipo, int $id): string
{
    return (string) $test->actingAs($test->andrea)->getJson(osUrl($tipo, $id))->assertOk()->json('soggetto.descrizione');
}

it('la riga sotto il titolo: «Condòmina di Via Roma 12, Int. 7» per chi è titolare (il genere dal codice fiscale)', function () {
    $this->ida->update(['codice_fiscale' => 'RMNDIA80A41H501U']);
    $unita = \App\Models\Immobile::query()->create(['condominio_id' => $this->roma->id, 'nome' => 'Appartamento', 'interno' => '7', 'codice_immobile' => 'VR-7']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $this->ida->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);

    expect(osDescrizione($this, 'anagrafica', $this->ida->id))->toBe('Condòmina di Via Roma 12, Int. 7');
});

it('la riga sotto il titolo: «Ex condòmino: ha venduto l\'Int. 11 il 15/01/2027» dall\'ultima titolarità chiusa da una vendita', function () {
    $unita = \App\Models\Immobile::query()->create(['condominio_id' => $this->roma->id, 'nome' => 'Appartamento', 'interno' => '11', 'codice_immobile' => 'VR-11']);
    $riga = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $this->mario->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario',
        'quota' => 100, 'attivo' => false, 'data_inizio' => '2019-01-01', 'data_fine' => '2027-01-14', 'created_at' => now(), 'updated_at' => now()]);
    \App\Models\Gestionale\Subentro::query()->forceCreate(['condominio_id' => $this->roma->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario',
        'tipo_passaggio' => 'vendita', 'decorrenza' => '2027-01-15', 'anagrafica_uscente_id' => $this->mario->id, 'riga_uscente_id' => $riga]);

    expect(osDescrizione($this, 'anagrafica', $this->mario->id))->toBe('Ex condòmino: ha venduto l\'Int. 11 il 15/01/2027');
});

it('la riga sotto il titolo: senza unità, i condomini a cui la persona è collegata; per un fornitore, i condomini dove ha fatture', function () {
    $this->mario->condomini()->attach($this->roma->id);
    $fornitore = Fornitore::query()->create(['ragione_sociale' => 'Serramenti Marino']);

    expect(osDescrizione($this, 'anagrafica', $this->mario->id))->toBe('Persona collegata a Via Roma 12')
        ->and(osDescrizione($this, 'fornitore', $fornitore->id))->toBe('Fornitore senza fatture nei condomini');
});

it('la riga sotto il titolo per un fornitore: «Fornitore di Via Roma 12 e Corso Italia 3», i condomini dove ha fatture', function () {
    $fornitore = Fornitore::query()->create(['ragione_sociale' => 'Serramenti Marino']);
    $italia = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    foreach ([$this->roma, $italia] as $i => $condominio) {
        $esercizio = \App\Models\Esercizio::factory()->create(['condominio_id' => $condominio->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01',
            'data_fine' => '2026-12-31', 'stato' => 'aperto']);
        \App\Models\Gestionale\FatturaPassiva::create(['condominio_id' => $condominio->id, 'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id,
            'tipo_documento' => 'fattura', 'numero_documento' => 'FT-' . $i, 'data_documento' => '2026-03-10', 'data_scadenza' => '2026-04-10', 'is_pregresso' => false,
            'importo_imponibile' => 1000, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 1000, 'netto_a_pagare' => 1000,
            'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    }

    expect(osDescrizione($this, 'fornitore', $fornitore->id))->toBe('Fornitore di Via Roma 12 e Corso Italia 3');
});

it('la ricerca delle altre righe legge i ripristini una volta sola, non una per riga candidata', function () {
    foreach (range(1, 6) as $i) {
        RegistroModifiche::scrivi(['area' => 'persone', 'oggetto_tipo' => 'anagrafica', 'oggetto_id' => 500 + $i, 'oggetto_nome' => "Anagrafica {$i}",
            'azione' => 'modificata', 'riepilogo' => 'Chiedere a ida.romano@libero.it per le chiavi']);
    }
    DB::enableQueryLog();
    $this->actingAs($this->andrea)->getJson(osUrl('anagrafica', $this->ida->id))->assertOk()->assertJsonPath('altre.quante', 7);
    $letture = collect(DB::getQueryLog())->filter(fn ($q) => in_array('ripristino_copia', $q['bindings'], true))->count();
    DB::disableQueryLog();

    expect($letture)->toBe(1);
});

it('la finestra dice quando c\'è un file di un ripristino rimasto con le righe di prima, e il giorno in cui si toglie da solo', function () {
    $cartella = storage_path('framework/testing/oscuramento-file-' . (\Illuminate\Support\Facades\ParallelTesting::token() ?: 'serial'));
    \Illuminate\Support\Facades\File::deleteDirectory($cartella);
    \Illuminate\Support\Facades\File::ensureDirectoryExists($cartella);
    config(['filesystems.disks.backups.root' => $cartella, 'backup.disk' => 'backups']);
    try {
        expect($this->actingAs($this->andrea)->getJson(osUrl('anagrafica', $this->ida->id))->assertOk()->json('file_rimasti'))->toBe([]);

        // Un ripristino del 03/03/2027 finito con un errore anche nel PDF: il file con le righe di prima è rimasto.
        $file = $cartella . '/.registro-modifiche-r-03-marzo.ndjson';
        \Illuminate\Support\Facades\File::put($file, "{}\n");
        touch($file, Carbon::parse('2027-03-03 10:40:00', 'Europe/Rome')->getTimestamp());

        expect($this->actingAs($this->andrea)->getJson(osUrl('anagrafica', $this->ida->id))->assertOk()->json('file_rimasti'))
            ->toBe(['C\'è un file del ripristino del 03/03/2027 con le righe di prima: si toglie da solo il 02/04/2027.']);

        // E si toglie davvero quel giorno, con la conservazione di quella notte.
        $this->travelTo(Carbon::parse('2027-04-01 03:15:00', 'Europe/Rome'));
        $this->artisan('kondomanager:conserva-registro')->assertSuccessful();
        expect(is_file($file))->toBeTrue();
        $this->travelTo(Carbon::parse('2027-04-02 03:15:00', 'Europe/Rome'));
        $this->artisan('kondomanager:conserva-registro')->assertSuccessful();
        expect(is_file($file))->toBeFalse();
    } finally {
        \Illuminate\Support\Facades\File::deleteDirectory($cartella);
    }
});
