<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.13: due catene delle lettere per PEC, ognuna percorsa da un capo all'altro.
 *
 * 5. La PEC non partita → il conto nell'avviso delle rate scadute → «Stampale per la posta» per una e la spedizione registrata, «Rimanda le PEC non partite»
 *    per l'altra → le righe nella corrispondenza della persona, e l'avviso che si spegne.
 * 6. L'avviso di mancata consegna trascinato sulla riga → la diffida non consegnata → nessun termine che corre (i sei mesi non propongono il fascicolo, ma
 *    di mandarla di nuovo) → la colonna «Ultima lettera» delle rate scadute la dice.
 *
 * Nessuna PEC vera: la casella e il trasporto sono finti ({@see LetterePec}).
 */

use App\Models\Gestionale\RegistroInvio;
use App\Services\Gestionale\Lettere\RegistroLettere;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/LetterePecScenario.php';
require_once __DIR__.'/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    Storage::fake('local');
    $this->withoutVite();
    $this->user = LetterePec::utente();
    LetterePec::conLaCasella();
});

function lcnRateScadute($test, array $s): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))->assertOk()->viewData('page')['props'];
}

it('catena 5: la PEC non partita → l\'avviso delle rate scadute → la stampa per la posta e la spedizione, il rinvio in blocco → la corrispondenza', function () {
    $s = LetterePec::scenario($this);
    $ugo = $s['persone']['ugo']->id;
    $bice = $s['persone']['bice']->id;
    LetterePec::$errori = ['Connection could not be established with host "ssl://smtps.pec.esempio.it:465": stream_socket_client(): Unable to connect'];
    $scelte = lttScelte($s, ['tipo' => 'diffida', 'testo' => 'Diffida a [nome].', 'canali' => [$ugo => 'pec', $bice => 'pec'], 'viste' => [$ugo, $bice]]);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), $scelte)->assertOk()->assertJsonPath('non_partite', 1)->assertJsonPath('restanti.persone', [$ugo]);
    LetterePec::$errori = ['550 5.1.1 Mailbox unavailable'];
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), array_merge($scelte, ['viste' => [$ugo]]))->assertOk()->assertJsonPath('non_partite', 1);

    // Il capo di partenza: l'avviso delle rate scadute conta le due PEC non partite.
    expect(lcnRateScadute($this, $s)['pec_da_rimandare'])->toBe(2);

    // Bice si stampa per la posta dalla sua riga; la spedizione si registra con le righe dell'intestazione.
    $riga = LetterePec::righePec($s, 'bice')->sole();
    expect(app(RegistroLettere::class)->perPersona($s['c'], $s['persone']['bice'])[0]['righe'][0]['pec'])->toMatchArray(['ripetibile' => true, 'stampabile' => true]);
    $pdf = $this->actingAs($this->user)->post(lttRotta($s, 'pec-non-partite.stampa'), ['invii' => [$riga->id]])->assertOk();
    [$nuova] = explode(':', (string) $pdf->headers->get('X-Lettere-Invii'));
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => [(int) $nuova], 'canale' => 'raccomandata', 'data' => '2026-03-20'])->assertOk();

    // Ugo si rimanda in blocco, dalla casella dello studio.
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec-non-partite'))->assertOk()->assertJsonPath('partite', 1);

    // L'altro capo: la corrispondenza di ognuno, e l'avviso delle rate scadute spento.
    $diBice = app(RegistroLettere::class)->perPersona($s['c'], $s['persone']['bice']);
    expect($diBice[0]['righe'][0])->toMatchArray(['canale' => 'stampa', 'stato' => RegistroInvio::STATO_SPEDITA])
        ->and($diBice[1]['righe'][0]['pec'])->toMatchArray(['badge' => 'Non partita', 'ripetibile' => false, 'stampabile' => false]);
    $diUgo = app(RegistroLettere::class)->perPersona($s['c'], $s['persone']['ugo']);
    expect($diUgo[0]['righe'][0]['pec'])->toMatchArray(['badge' => 'Partita'])
        ->and($diUgo[1]['righe'][0]['pec'])->toMatchArray(['badge' => 'Non partita', 'ripetibile' => false])
        ->and(lcnRateScadute($this, $s)['pec_da_rimandare'])->toBe(0);
    $ultime = collect(lcnRateScadute($this, $s)['righe']['data'])->keyBy('anagrafica_id');
    expect($ultime[$ugo]['ultima_lettera'])->toMatchArray(['tipo' => 'diffida', 'canale' => 'pec', 'pec' => 'partita'])
        ->and($ultime[$bice]['ultima_lettera'])->toMatchArray(['tipo' => 'diffida', 'canale' => 'stampa', 'stato' => RegistroInvio::STATO_SPEDITA]);
});

it('catena 6: l\'avviso di mancata consegna → la diffida non consegnata → nessun termine che corre → la colonna «Ultima lettera»', function () {
    $s = smaViaVerdi($this);
    $s['persone']['bianchi']->update(['pec' => 'bianchi@pec.it', 'pec_comunicata_il' => '2026-02-01']);
    $bianchi = $s['persone']['bianchi']->id;
    $scelte = ['tipo' => 'diffida', 'testo' => 'Diffida a [nome] per [totale].', 'persone' => [$bianchi], 'termine' => 15, 'termine_forma' => 'giorni', 'indirizzi' => ['email']];
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), $scelte + ['viste' => [$bianchi], 'canali' => [$bianchi => 'pec']])->assertOk()->assertJsonPath('partite', 1);
    $riga = LetterePec::righePec($s, 'bianchi')->sole();

    // Il capo di partenza: l'avviso di mancata consegna del gestore, trascinato sulla riga il giorno dopo.
    $this->travelTo('2026-05-21 10:00:00');
    $this->actingAs($this->user)->post(lttRotta($s, 'ricevuta', ['invio' => $riga->id]), ['ricevuta' => UploadedFile::fake()->createWithContent('mancata.eml',
        str_replace('errore="nessuno"', 'errore="no-dest"', LetterePec::ricevuta('bianchi@pec.it', $riga->dati['oggetto'], '21/05/2026', '09:30', 'errore-consegna')))])
        ->assertSessionHasNoErrors();

    // La corrispondenza: «Non consegnata», nessun termine dalla consegna.
    $lettera = app(RegistroLettere::class)->perPersona($s['c'], $s['persone']['bianchi'])[0];
    expect($lettera['righe'][0]['pec']['badge'])->toBe('Non consegnata')->and($lettera['termine_dalla_consegna'])->toBeNull();

    // Venti giorni dopo: il termine di 15 giorni dall'invio sarebbe passato, ma la diffida non è arrivata; i sei mesi propongono di mandarla di nuovo.
    $this->travelTo('2026-06-10 10:00:00');
    $passo = smaPersona(smaRiga(smaRiquadro($s), 'Esercizio 2025'), $s['persone']['bianchi']->nome)['passo'];
    expect($passo['caso'])->toBe('R8n')
        ->and($passo['passo'])->toBe('diffida')
        ->and($passo['frase'])->toBe('Diffida non consegnata per PEC il 21/05/2026: l\'avviso di mancata consegna del gestore dice che non è arrivata nella sua casella, e '
            . 'il termine non corre. Mandala di nuovo, o stampala per la posta.');

    // L'altro capo: la colonna «Ultima lettera» delle rate scadute la dice fra quelle che non sono arrivate.
    $riga = collect(lcnRateScadute($this, $s)['righe']['data'])->firstWhere('anagrafica_id', $bianchi);
    expect($riga['lettera_in_sospeso'])->toMatchArray(['tipo' => 'diffida', 'canale' => 'pec', 'stato' => RegistroInvio::STATO_NON_PARTITA,
        'pec' => RegistroInvio::ESITO_NON_CONSEGNATA, 'mancata_consegna_il' => '2026-05-21']);
});

it('«PEC e carta», la PEC partita e la copia solo stampata: i sei mesi ricordano di registrare la spedizione della copia', function () {
    $s = smaViaVerdi($this);
    $s['persone']['bianchi']->update(['pec' => 'bianchi@pec.it', 'pec_comunicata_il' => '2026-02-01']);
    $bianchi = $s['persone']['bianchi']->id;
    $scelte = ['tipo' => 'diffida', 'testo' => 'Diffida a [nome] per [totale].', 'persone' => [$bianchi], 'termine' => 15, 'termine_forma' => 'giorni', 'indirizzi' => ['email'],
        'viste' => [$bianchi], 'canali' => [$bianchi => 'pec_e_carta']];
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), $scelte)->assertOk()->assertJsonPath('partite', 1);
    $copia = $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), $scelte)->assertOk();
    $passo = fn () => smaPersona(smaRiga(smaRiquadro($s), 'Esercizio 2025'), $s['persone']['bianchi']->nome)['passo'];

    expect($passo()['frase'])->toBe('Diffida partita per PEC il 20/05/2026: il termine è il 04/06/2026.')
        ->and($passo()['note'])->toContain('La copia su carta è stampata: la sua spedizione non è registrata.');

    [$riga] = explode(':', (string) $copia->headers->get('X-Lettere-Invii'));
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => [(int) $riga], 'canale' => 'raccomandata', 'data' => '2026-05-20'])->assertOk();
    expect($passo()['note'])->not->toContain('La copia su carta è stampata: la sua spedizione non è registrata.');
});
