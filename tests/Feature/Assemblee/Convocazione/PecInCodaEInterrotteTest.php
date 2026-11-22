<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\OggettoDelPunto;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Convocazione\ElencoDeiConvocati;
use App\Services\Assemblee\Convocazione\EmissioneDelleConvocazioni;
use App\Services\Assemblee\Convocazione\ProvaDellaConvocazione;
use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Posta\CasellaPec;
use App\Services\Posta\ConfigurazionePec;
use App\Services\Posta\TrasportoPec;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679): le PEC che non si sa se sono partite, quelle che si fermano a metà, il doppio clic, e che cosa porta la PEC.
 *
 * - Una riga rimasta «in coda» (il lavoro si è fermato durante il collegamento) è «Da verificare»: non vale come spedita, si può rimandare, e la prova
 *   della convocazione dice che non si sa se è partita.
 * - La connessione che cade dopo la prima PEC: «1 PEC è partita…» e «Le altre 2 non sono partite: …», mai «Nessuna PEC è partita» con una partita.
 * - Un secondo «Manda le PEC» (o «Stampa le lettere») mentre il primo lavora risponde che sta già partendo: un blocco per assemblea.
 * - Con i documenti dei punti la PEC porta l'avviso e i documenti dei punti di quella persona, e la riga dell'invio li elenca con l'impronta.
 *
 * Che cosa non copre: un server PEC vero; il tetto della grandezza di un messaggio dei gestori (domanda nel rapporto).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('una PEC rimasta «in coda» è «Da verificare»: non vale come spedita, si può rimandare, e la prova dice che non si sa se è partita', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    RegistroInvio::create(['condominio_id' => $s->c->id, 'anagrafica_id' => $s->persone['rossi']->id, 'tipo' => RegistroInvio::TIPO_CONVOCAZIONE,
        'canale' => RegistroInvio::CANALE_PEC, 'documentabile_type' => $a->getMorphClass(), 'documentabile_id' => $a->id, 'destinatario' => 'mario.rossi@pec.it',
        'dati' => ['mezzo' => 'pec', 'persona' => ['nome' => 'Rossi Mario']], 'impronta' => str_repeat('a', 64), 'stato' => RegistroInvio::STATO_IN_CODA,
        'emesso_da' => $s->admin->id, 'emesso_il' => now()]);
    $this->actingAs($s->admin);

    $riga = collect(app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['convocati']['righe'])->firstWhere('nome', 'Rossi Mario');
    expect($riga['stato'])->toBe('da_verificare')
        ->and($riga['stato_parola'])->toBe('Da verificare')
        ->and($riga['dettaglio'])->toBe('guarda nella casella PEC se c\'è la ricevuta di accettazione; se non c\'è, manda di nuovo');
    expect(collect(app(ElencoDeiConvocati::class)->daSpedire($a, ['pec']))->pluck('nome')->all())->toContain('Rossi Mario');
    $prova = collect(app(ProvaDellaConvocazione::class)->dati($a)['righe'])->first(fn ($r) => str_starts_with($r['persona'], 'Rossi Mario'));
    expect($prova['spedizione'])->toStartWith('non si sa se è partita: il ');
});

it('la connessione che cade dopo la prima PEC: il messaggio dice quante sono partite e che le altre no, senza «Nessuna PEC è partita»', function () {
    app()->instance(CasellaPec::class, new class extends CasellaPec {
        public function configurazione(): ?ConfigurazionePec
        {
            return new ConfigurazionePec('studio.costa@pec.it', 'smtps.pec.esempio.it', 465, 'studio.costa@pec.it', 'segreta');
        }
    });
    app()->instance(TrasportoPec::class, new class implements TrasportoPec {
        private int $n = 0;

        public function manda(ConfigurazionePec $casella, Email $messaggio): void
        {
            if (++$this->n >= 2) {
                throw new TransportException('Connection could not be established with host "ssl://smtps.pec.esempio.it:465": stream_socket_client(): Unable to connect (Connection timed out)');
            }
        }
    });
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]));

    expect(session('message.message'))->toBe('1 PEC è partita: il gestore l\'ha accettata, la consegna la dice la ricevuta. Le altre 2 non sono partite: '
        . 'il programma non riesce a collegarsi al server della PEC (smtps.pec.esempio.it, porta 465). Dove gira il programma la porta 465 in uscita può '
        . 'essere chiusa: chiedi a chi gestisce il server di aprirla, poi riprova.');
});

it('«Manda le PEC» e «Stampa le lettere» mentre la stessa assemblea sta già spedendo rispondono che stanno partendo, e niente parte due volte', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $blocco = Cache::lock(EmissioneDelleConvocazioni::chiaveDelBlocco($a), 60);
    expect($blocco->get())->toBeTrue();

    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))
        ->assertStatus(422)->assertJsonPath('errors.convocazione.0', EmissioneDelleConvocazioni::GIA_IN_CORSO);
    $this->postJson(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])
        ->assertStatus(422)->assertJsonPath('errors.convocazione.0', EmissioneDelleConvocazioni::GIA_IN_CORSO);
    expect(ConvocazioneDiViaRoma12::$pecMandate)->toBe([])
        ->and(RegistroInvio::query()->where('documentabile_id', $a->id)->count())->toBe(0);

    $blocco->release();
    $this->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();
    expect(ConvocazioneDiViaRoma12::$pecMandate)->toHaveCount(3);
});

it('la PEC porta l\'avviso e i documenti dei punti di quella persona; la riga li elenca con l\'impronta', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $archivio = app(ArchivioDocumenti::class);
    $mpdf = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir()]);
    $mpdf->WriteHTML('<p>Tre preventivi del portone</p>');
    $contenuto = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    $doc = Documento::create(['name' => 'preventivi-portone.pdf', 'description' => 'Tre preventivi del portone', 'path' => $archivio->salvaContenuto($contenuto, 'pdf', 'documenti/prova'),
        'mime_type' => 'application/pdf', 'file_size' => strlen($contenuto), 'is_published' => false, 'is_approved' => true]);
    OggettoDelPunto::create(['punto_id' => $a->punti()->where('numero', 4)->value('id'), 'oggetto_tipo' => 'documento', 'oggetto_id' => $doc->id, 'ruolo' => 'documento']);

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();

    $m = collect(ConvocazioneDiViaRoma12::$pecMandate)->first(fn ($p) => $p['messaggio']->getTo()[0]->getAddress() === 'mario.rossi@pec.it')['messaggio'];
    expect(array_map(fn ($p) => $p->getFilename(), $m->getAttachments()))->toBe(['convocazione-2026-12-15-rossi-mario.pdf', 'punto-4-preventivi-portone.pdf']);
    $rossi = ConvocazioneDiViaRoma12::invio($a, $s, 'rossi');
    expect($rossi->dati['allegati'])->toBe([['punto' => 4, 'nome' => 'Tre preventivi del portone', 'file' => 'punto-4-preventivi-portone.pdf',
        'impronta' => hash('sha256', $contenuto)]]);
});
