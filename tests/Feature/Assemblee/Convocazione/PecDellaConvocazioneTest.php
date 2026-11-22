<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\ConvocatoDellAssemblea;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Posta\PecDelloStudio;
use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Posta\DocumentoPerPec;
use App\Services\Posta\InvioPerPec;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (decisioni 564 e 569; §4.2 del progetto della tappa 2): «Manda le PEC» dalla casella dello studio, letta solo da `CasellaPec`. Senza la
 * casella il pulsante è spento con la frase; con la casella (finta, con un trasporto finto) un messaggio per persona con il PDF, una riga `pec` «inviata»
 * con il PDF conservato e l'impronta, il convocato fissato, una riga sola nel registro; se la connessione non parte, la frase semplice con la porta, e gli
 * altri non si provano. L'invio per PEC non è della convocazione: un documento qualsiasi a una persona ha la sua riga.
 *
 * Che cosa non copre: un server PEC vero (la porta 465 dove gira il programma); la lettura della ricevuta `daticert.xml`.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('senza la casella PEC dello studio «Manda le PEC» è spento con la frase, e la rotta lo dice', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin);
    $scheda = app(StatoDellaConvocazione::class)->perLaPagina($a);

    // La frase e il collegamento alla pagina delle caselle (decisione 572, proposta 11): `CasellaPecNellaConvocazioneTest`. Chi gestisce l'assemblea
    // qui non apre le impostazioni: niente collegamento.
    expect($scheda['pec'])->toBe(['quante' => 3, 'attivo' => false, 'perche' => PecDelloStudio::NON_IMPOSTATA, 'dove' => null, 'avviso' => null])
        ->and(PecDelloStudio::NON_IMPOSTATA)->toBe('Prima imposta la casella PEC dello studio.');
    $this->postJson(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertStatus(422)
        ->assertJsonPath('errors.convocazione.0', PecDelloStudio::NON_IMPOSTATA);
});

it('con la casella: un messaggio per persona con il PDF, la riga «inviata» conservata, il convocato fissato, una riga nel registro', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $prima = ViaRoma12::ultimaRiga();

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect()
        ->assertSessionHas('message.message', '3 PEC sono partite: il gestore le ha accettate, la consegna la dice la ricevuta.');

    expect(ConvocazioneDiViaRoma12::$pecMandate)->toHaveCount(3);
    $messaggio = ConvocazioneDiViaRoma12::$pecMandate[0]['messaggio'];
    expect($messaggio->getFrom()[0]->getAddress())->toBe('studio.costa@pec.it')
        ->and($messaggio->getSubject())->toBe('Convocazione dell\'assemblea ordinaria — Condominio Via Roma 12, 15/12/2026')
        ->and(count($messaggio->getAttachments()))->toBe(1);
    $rossi = ConvocazioneDiViaRoma12::invio($a, $s, 'rossi');
    expect($rossi->canale)->toBe(RegistroInvio::CANALE_PEC)->and($rossi->stato)->toBe(RegistroInvio::STATO_INVIATA)
        ->and($rossi->destinatario)->toBe('mario.rossi@pec.it')
        ->and(hash('sha256', (string) app(ArchivioDocumenti::class)->leggi(app(InvioPerPec::class)->conservato($rossi)->path)))->toBe($rossi->impronta)
        ->and(ConvocatoDellAssemblea::query()->where('assemblea_id', $a->id)->where('anagrafica_id', $s->persone['rossi']->id)->value('invio_id'))->toBe($rossi->id)
        ->and(ConvocazioneDiViaRoma12::righeDelRegistro($prima)->pluck('riepilogo')->all())->toContain('Convocazione spedita: 3 PEC');
});

it('se la connessione non parte: la frase semplice con il server e la porta, una riga «non partita», gli altri non provati, nessuna riga nel registro', function () {
    ConvocazioneDiViaRoma12::conLaCasella('Connection could not be established with host "ssl://smtps.pec.esempio.it:465": stream_socket_client(): Unable to connect (Connection timed out)');
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $prima = ViaRoma12::ultimaRiga();

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect()
        ->assertSessionHas('message.message', 'Il programma non riesce a collegarsi al server della PEC (smtps.pec.esempio.it, porta 465). '
            . 'Dove gira il programma la porta 465 in uscita può essere chiusa: chiedi a chi gestisce il server di aprirla, poi riprova. Nessuna PEC è partita.');
    expect(RegistroInvio::query()->where('documentabile_id', $a->id)->where('canale', 'pec')->count())->toBe(1)
        ->and(RegistroInvio::query()->where('documentabile_id', $a->id)->value('stato'))->toBe(RegistroInvio::STATO_NON_PARTITA)
        ->and(ConvocatoDellAssemblea::query()->where('assemblea_id', $a->id)->count())->toBe(0)
        ->and($a->refresh()->stato->value)->toBe('in_preparazione')
        ->and(ConvocazioneDiViaRoma12::righeDelRegistro($prima)->pluck('riepilogo')->filter(fn ($r) => str_starts_with((string) $r, 'Convocazione spedita'))->all())->toBe([]);
});

it('l\'invio per PEC non è della convocazione: un documento qualsiasi a una persona ha la sua riga, con il tipo e il documento che gli si dà', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $persona = $s->persone['rossi'];
    $riga = app(InvioPerPec::class)->invia(new DocumentoPerPec((int) $s->c->id, (int) $persona->id, 'mario.rossi@pec.it', 'diffida', $persona, '%PDF-1.4 prova',
        'diffida.pdf', 'Diffida', 'In allegato la diffida.', ['lettera' => 'abc']), $s->admin->id);

    expect($riga->tipo)->toBe('diffida')->and($riga->documentabile_type)->toBe($persona->getMorphClass())
        ->and($riga->stato)->toBe(RegistroInvio::STATO_INVIATA)->and($riga->dati['lettera'])->toBe('abc')
        ->and($riga->dati['dalla_casella'])->toBe('studio.costa@pec.it');
});
