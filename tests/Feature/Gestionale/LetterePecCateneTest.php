<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.13 (decisioni 569, 577 e 588): le catene delle lettere per PEC, ognuna percorsa da un capo all'altro.
 *
 * 1. La diffida scelta per PEC nella finestra → l'invio dalla casella dello studio → la riga nella corrispondenza della persona → la ricevuta trascinata e
 *    letta → il giorno della consegna → il termine della diffida contato da lì, nella corrispondenza, nella colonna «Ultima lettera» delle rate scadute e nei
 *    «Sei mesi per agire». Le lettere non scrivono nel registro delle modifiche: il registro degli invii è il registro di questi gesti.
 * 2. La PEC non comunicata dalla persona → la riga dell'anteprima che lo dice, con il collegamento → la scheda della persona, dove si scrive il giorno in cui
 *    l'ha comunicata → l'anteprima propone la PEC, senza il segno.
 * 3. La casella PEC tolta nelle impostazioni → «Manda per PEC» spento con il perché nelle lettere e nella convocazione, con «Imposta».
 * 4. La ricevuta della convocazione passa dagli stessi controlli delle lettere (`RicevutaDellInvio`) → l'esito → il compito «Ricevuta mancante» dell'Action
 *    inbox si chiude, e il registro delle modifiche ha la sua riga.
 */

use App\Enums\Role;
use App\Models\Evento;
use App\Models\Gestionale\RegistroInvio;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Posta\PecDelloStudio;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\PecSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/LetterePecScenario.php';
require_once __DIR__.'/SeiMesiPerAgireScenario.php';
require_once __DIR__.'/../Assemblee/Convocazione/ConvocazioneDiViaRoma12.php';

beforeEach(function () {
    Storage::fake('local');
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ContestoDelRegistro::azzera();
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('catena 1: la diffida scelta per PEC → partita dalla casella → la riga nella corrispondenza → la ricevuta → la consegna → il termine, fino ai sei mesi', function () {
    $this->user = LetterePec::utente();
    LetterePec::conLaCasella();
    $s = smaViaVerdi($this);
    $s['persone']['bianchi']->update(['pec' => 'bianchi@pec.it', 'pec_comunicata_il' => '2026-02-01']);
    $bianchi = $s['persone']['bianchi']->id;
    $scelte = ['tipo' => 'diffida', 'testo' => 'Diffida a [nome] per [totale].', 'persone' => [$bianchi], 'termine' => 15, 'termine_forma' => 'giorni', 'indirizzi' => ['email']];

    // Il capo di partenza: la finestra propone la PEC a chi l'ha comunicata, e «Manda le PEC» la manda dalla casella dello studio.
    $anteprima = $this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), $scelte)->assertOk()->json();
    expect($anteprima['persone'][0])->toMatchArray(['nome' => $s['persone']['bianchi']->nome, 'canale' => 'pec', 'pec_non_comunicata' => false])
        ->and($anteprima['conteggi']['pec'])->toBe(1);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), $scelte + ['viste' => [$bianchi], 'canali' => [$bianchi => 'pec']])->assertOk()
        ->assertJsonPath('frase', '1 PEC è partita: il gestore l\'ha accettata, la consegna la dice la ricevuta.');
    expect(LetterePec::$mandate[0]['messaggio']->getFrom()[0]->getAddress())->toBe('studio.costa@pec.it');
    $riga = LetterePec::righePec($s, 'bianchi')->sole();

    // La riga nella corrispondenza della persona: «Partita», il termine contato dall'invio.
    $pagina = fn () => $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone']['bianchi']]))->assertOk()
        ->viewData('page')['props'];
    $lettera = $pagina()['lettere'][0];
    expect($lettera['righe'][0]['pec'])->toMatchArray(['badge' => 'Partita', 'ricevibile' => true])
        ->and($lettera['termine_dalla_consegna'])->toBeNull();
    $riquadro = smaPersona(smaRiga(smaRiquadro($s), 'Esercizio 2025'), $s['persone']['bianchi']->nome)['passo'];
    expect($riquadro['frase'])->toBe('Diffida partita per PEC il 20/05/2026: il termine è il 04/06/2026.');

    // La ricevuta trascinata il giorno dopo: la consegna del 21/05 alle 09:02, dal gestore. Il registro delle modifiche non cambia.
    $this->travelTo('2026-05-22 10:00:00');
    $prima = (int) (RegistroModifica::query()->max('id') ?? 0);
    $this->actingAs($this->user)->post(lttRotta($s, 'ricevuta', ['invio' => $riga->id]), ['ricevuta' => UploadedFile::fake()->createWithContent('ricevuta.eml',
        LetterePec::ricevuta('bianchi@pec.it', $riga->dati['oggetto'], '21/05/2026', '09:02'))])->assertSessionHasNoErrors();
    expect(RegistroModifica::query()->where('id', '>', $prima)->count())->toBe(0);

    // L'altro capo: la corrispondenza dice «Consegnata» con il termine dalla consegna; la colonna «Ultima lettera» ha la consegna; i sei mesi contano da lì.
    $lettera = $pagina()['lettere'][0];
    expect($lettera['righe'][0]['pec']['badge'])->toBe('Consegnata')
        ->and($lettera['termine_dalla_consegna'])->toBe(['entro' => '2026-06-05', 'giorni' => 15, 'consegna' => '2026-05-21', 'a_mano' => false]);
    $elenco = $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))->assertOk()->viewData('page')['props'];
    $ultima = collect($elenco['righe']['data'])->firstWhere('anagrafica_id', $bianchi)['ultima_lettera'];
    expect($ultima)->toMatchArray(['tipo' => 'diffida', 'canale' => 'pec', 'stato' => 'inviata', 'consegna' => ['giorno' => '2026-05-21', 'a_mano' => false]]);
    $riquadro = smaPersona(smaRiga(smaRiquadro($s), 'Esercizio 2025'), $s['persone']['bianchi']->nome)['passo'];
    expect($riquadro['frase'])->toBe('Diffida consegnata per PEC il 21/05/2026: il termine è il 05/06/2026.')
        ->and($riquadro['termine'])->toBe('15 giorni dal ricevimento: contati dalla consegna del 21/05/2026, dalla ricevuta.');
});

it('catena 2: la PEC non comunicata → la riga dell\'anteprima lo dice, con la scheda → il giorno scritto nella scheda → la PEC proposta, senza il segno', function () {
    $this->user = LetterePec::utente();
    $this->user->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => \App\Enums\Permission::EDIT_ANAGRAFICHE->value, 'guard_name' => 'web']));
    LetterePec::conLaCasella();
    $s = LetterePec::scenario($this);
    $scelte = lttScelte($s, ['tipo' => 'diffida', 'testo' => 'Diffida a [nome].']);

    $bice = collect($this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), $scelte)->json('persone'))->firstWhere('nome', 'Bice');
    expect($bice)->toMatchArray(['canale' => 'stampa', 'pec_non_comunicata' => true, 'scheda' => route('admin.anagrafiche.edit', $s['persone']['bice']->id)]);

    // La scheda della persona ha il campo «PEC comunicata dalla persona il»; il giorno scritto lì cambia la proposta della finestra.
    $this->actingAs($this->user)->get($bice['scheda'])->assertOk();
    $s['persone']['bice']->update(['pec_comunicata_il' => '2026-03-18']);
    $bice = collect($this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), $scelte)->json('persone'))->firstWhere('nome', 'Bice');
    expect($bice)->toMatchArray(['canale' => 'pec', 'pec_non_comunicata' => false, 'pec_comunicata_il' => '2026-03-18']);
});

it('catena 3: la casella PEC tolta nelle impostazioni → la PEC spenta con il perché nelle lettere e nella convocazione, con «Imposta»', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $andrea = User::factory()->create(['name' => 'Andrea Costa', 'email' => 'andrea.costa@studiocosta.it', 'email_verified_at' => now()]);
    $andrea->assignRole(Role::AMMINISTRATORE->value);
    $this->user = $andrea;
    $pec = app(PecSettings::class);
    $pec->indirizzo = 'andrea.costa@pec.studiocosta.it';
    $pec->server = 'smtps.pec.ilgestore.it';
    $pec->porta = 465;
    $pec->cifratura = 'ssl';
    $pec->utente = 'andrea.costa@pec.studiocosta.it';
    $pec->password = Crypt::encryptString('segreto-pec');
    $pec->impostata_il = now()->toIso8601String();
    $pec->save();

    $l = LetterePec::scenario($this);
    $c = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($c);
    $lettere = fn () => $this->actingAs($andrea)->postJson(lttRotta($l, 'anteprima'), lttScelte($l, ['tipo' => 'diffida', 'testo' => 'Diffida a [nome].']))->assertOk()->json();
    $convocazione = function () use ($andrea, $a) {
        $this->actingAs($andrea);

        return app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['pec'];
    };

    expect($lettere()['casella_pec'])->toMatchArray(['impostata' => true, 'indirizzo' => 'andrea.costa@pec.studiocosta.it'])
        ->and(collect($lettere()['persone'])->firstWhere('nome', 'Ugo')['canale'])->toBe('pec')
        ->and($convocazione()['attivo'])->toBeTrue();

    $this->actingAs($andrea)->withSession(['auth.password_confirmed_at' => time()])->delete(route('admin.settings.pec.destroy'))->assertRedirect()->assertSessionHasNoErrors();

    $dopo = $lettere();
    $ugo = collect($dopo['persone'])->firstWhere('nome', 'Ugo');
    expect($dopo['casella_pec'])->toMatchArray(['impostata' => false, 'perche' => PecDelloStudio::NON_IMPOSTATA, 'dove' => route('impostazioni.mail', ['apri' => 'pec'])])
        ->and($ugo['canale'])->toBe('stampa')
        ->and($ugo['canali_spenti']['pec'])->toBe(PecDelloStudio::NON_IMPOSTATA)
        ->and($convocazione())->toMatchArray(['attivo' => false, 'perche' => PecDelloStudio::NON_IMPOSTATA, 'dove' => route('impostazioni.mail', ['apri' => 'pec'])]);
    $this->actingAs($andrea)->postJson(lttRotta($l, 'pec'), lttScelte($l, ['tipo' => 'diffida', 'testo' => 'Diffida a [nome].']))->assertStatus(422)
        ->assertJsonPath('message', PecDelloStudio::NON_IMPOSTATA);
});

it('catena 4: la ricevuta della convocazione passa dagli stessi controlli → l\'esito → il compito «Ricevuta mancante» si chiude, con la riga del registro', function () {
    ConvocazioneDiViaRoma12::oggi();
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();
    $rossi = ConvocazioneDiViaRoma12::invio($a, $s, 'rossi');
    $compito = Evento::query()->where('meta->type', 'ricevute_mancanti')->where('meta->context->assemblea_id', $a->id)->orderBy('id')->get()
        ->first(fn (Evento $e) => str_contains((string) $e->title, 'Rossi Mario'));
    expect($compito)->not->toBeNull()->and($compito->is_completed)->toBeFalse();

    ConvocazioneDiViaRoma12::oggi('2026-12-04 09:00');
    $prima = (int) (RegistroModifica::query()->max('id') ?? 0);
    $this->post(route('admin.gestionale.assemblee.invii.ricevuta', [$s->c, $a, $rossi]), [
        'ricevuta' => UploadedFile::fake()->createWithContent('ricevuta-consegna.eml', (string) file_get_contents(base_path('tests/fixtures/pec/ricevuta_consegna_rossi.eml')))])
        ->assertSessionHasNoErrors();

    expect($rossi->refresh()->esito)->toBe(RegistroInvio::ESITO_CONSEGNATA)
        ->and($compito->refresh()->is_completed)->toBeTrue()
        ->and(RegistroModifica::query()->where('id', '>', $prima)->orderBy('id')->get()->last()->riepilogo)
        ->toBe('PEC a Rossi Mario: consegnata il 03/12 alle 10:14, dalla ricevuta del gestore');
});
