<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.13 (decisioni 577 e 588): la ricevuta di consegna trascinata sulla riga di una lettera partita per PEC, nella corrispondenza della persona.
 *
 * - La ricevuta di consegna (inventata, un gestore di prova) dà «Consegnata il 20/03 alle 11:14» con il giorno e l'ora del gestore (la PEC parte alle 11:00:
 *   la ricevuta vale dall'ora della PEC, non dal giorno); il file resta sulla riga,
 *   si scarica e non si toglie; l'evento la dice.
 * - Una ricevuta di accettazione, di un altro indirizzo, di un'altra PEC, dopo oggi o prima dell'invio: la frase, e niente si scrive.
 * - La stessa ricevuta si allega soltanto; una diversa è una correzione con il perché.
 * - Quando il file non si legge (un PDF): la risposta lo dice, e con il giorno e l'ora (proposti dall'invio) la consegna è scritta a mano, con il file come
 *   prova: «scritta a mano, con la prova».
 * - Una PEC non partita: «Manda di nuovo per PEC, con le cifre di oggi».
 *
 * Che cosa non copre: le ricevute dei gestori veri (la fixture è scritta sulle regole tecniche, come quella della convocazione).
 */

use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Gestionale\Lettere\RegistroLettere;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/LetterePecScenario.php';

beforeEach(function () {
    Storage::fake('local');
    $this->user = LetterePec::utente();
    LetterePec::conLaCasella();
});

/** La diffida a Ugo per PEC, partita il 20/03/2026 alle 11:00 di Roma; la riga. */
function lprDiffidaPartita($test): array
{
    $s = LetterePec::scenario($test);
    $test->actingAs($test->user)->postJson(lttRotta($s, 'pec'), lttScelte($s, ['tipo' => 'diffida', 'testo' => 'Diffida a [nome].',
        'persone' => [$s['persone']['ugo']->id], 'canali' => [$s['persone']['ugo']->id => 'pec']]))->assertOk()->assertJsonPath('partite', 1);

    return [$s, LetterePec::righePec($s, 'ugo')->sole()];
}

function lprRicevuta(RegistroInvio $riga, string $giorno = '20/03/2026', string $ora = '11:14', array $altro = []): UploadedFile
{
    $a = $altro['a'] ?? $riga->destinatario;
    $oggetto = $altro['oggetto'] ?? $riga->dati['oggetto'];

    return UploadedFile::fake()->createWithContent($altro['nome'] ?? 'ricevuta-consegna.eml', LetterePec::ricevuta($a, $oggetto, $giorno, $ora, $altro['tipo'] ?? 'avvenuta-consegna'));
}

function lprCorrispondenza(array $s, string $chi = 'ugo'): array
{
    return app(RegistroLettere::class)->perPersona($s['c'], $s['persone'][$chi]);
}

it('la ricevuta di consegna trascinata sulla riga: consegnata con il giorno e l\'ora del gestore, il file fra le prove che non si toglie, l\'evento', function () {
    [$s, $riga] = lprDiffidaPartita($this);
    $this->travelTo('2026-03-20 11:02:00');

    $this->actingAs($this->user)->post(lttRotta($s, 'ricevuta', ['invio' => $riga->id]), ['ricevuta' => lprRicevuta($riga)])->assertSessionHasNoErrors()
        ->assertSessionHas('message.message', 'Consegnata il 20/03 alle 11:14 a ugo@pec.it, dalla ricevuta del gestore (Gestore PEC di prova).');

    $riga->refresh();
    expect($riga->esito)->toBe(RegistroInvio::ESITO_CONSEGNATA)
        ->and($riga->ricevuta_il->format('Y-m-d'))->toBe('2026-03-20')
        ->and($riga->esito_il->format('H:i'))->toBe('11:14')
        ->and($riga->dati['consegna'])->toMatchArray(['giorno' => '2026-03-20', 'ora' => '11:14', 'a_mano' => false, 'gestore' => 'Gestore PEC di prova']);
    $evento = collect($riga->dati['eventi'])->last();
    expect($evento)->toMatchArray(['evento' => 'ricevuta', 'nome_file' => 'ricevuta-consegna.eml', 'parole' => 'consegnata il 20/03 alle 11:14', 'da_nome' => 'Andrea Costa']);

    $lettera = lprCorrispondenza($s)[0];
    $pec = $lettera['righe'][0]['pec'];
    expect($pec)->toMatchArray(['badge' => 'Consegnata', 'provata' => true, 'ricevibile' => true, 'ripetibile' => false])
        ->and($pec['frase'])->toBe('Consegnata il 20/03 alle 11:14 a ugo@pec.it, dalla ricevuta del gestore (Gestore PEC di prova).')
        ->and($lettera['prove'])->toHaveCount(1)
        ->and($lettera['prove'][0])->toMatchArray(['nome' => 'ricevuta-consegna.eml', 'della_consegna' => true, 'rimovibile' => false])
        ->and($lettera['termine_dalla_consegna'])->toBe(['entro' => '2026-04-04', 'giorni' => 15, 'consegna' => '2026-03-20', 'a_mano' => false]);
    $this->actingAs($this->user)->get(lttRotta($s, 'prove.scarica', ['invio' => $riga->id, 'documento' => $lettera['prove'][0]['id']]))->assertOk();

    // La prova della consegna non si toglie: la frase, e il file resta.
    $this->actingAs($this->user)->delete(lttRotta($s, 'prove.togli', ['invio' => $riga->id, 'documento' => $lettera['prove'][0]['id']]))
        ->assertSessionHasErrors(['prova' => RegistroLettere::PROVA_DELLA_CONSEGNA]);
    expect(Documento::find($lettera['prove'][0]['id']))->not->toBeNull();
});

it('una ricevuta che non è di consegna, di un altro indirizzo, di un\'altra PEC, dopo oggi o prima dell\'invio: la frase, e niente si scrive', function () {
    [$s, $riga] = lprDiffidaPartita($this);
    $rotta = lttRotta($s, 'ricevuta', ['invio' => $riga->id]);
    $documenti = Documento::count();

    $this->actingAs($this->user)->post($rotta, ['ricevuta' => lprRicevuta($riga, altro: ['tipo' => 'accettazione'])])
        ->assertSessionHasErrors(['ricevuta' => 'Il file è la ricevuta di accettazione (il gestore ha preso la PEC, non l\'ha ancora consegnata): trascina la ricevuta '
            . 'di consegna, o l\'avviso di mancata consegna, che arrivano dopo.']);
    $this->actingAs($this->user)->post($rotta, ['ricevuta' => lprRicevuta($riga, altro: ['a' => 'bice@pec.it'])])
        ->assertSessionHasErrors(['ricevuta' => 'La ricevuta è della consegna a bice@pec.it: non è di Ugo, a cui la PEC è andata a ugo@pec.it.']);
    $this->actingAs($this->user)->post($rotta, ['ricevuta' => lprRicevuta($riga, altro: ['oggetto' => 'Sollecito di pagamento'])])
        ->assertSessionHasErrors(['ricevuta' => 'La ricevuta è di un\'altra PEC a Ugo («Sollecito di pagamento»), non di questa diffida.']);
    $this->actingAs($this->user)->post($rotta, ['ricevuta' => lprRicevuta($riga, '21/03/2026')])
        ->assertSessionHasErrors(['ricevuta' => 'La ricevuta dice il 21/03/2026, dopo oggi: controlla il file.']);
    $this->actingAs($this->user)->post($rotta, ['ricevuta' => lprRicevuta($riga, '19/03/2026')])
        ->assertSessionHasErrors(['ricevuta' => 'La ricevuta dice il 19/03/2026, prima della PEC, partita il 20/03/2026: controlla il file.']);

    expect($riga->refresh()->esito)->toBeNull()->and(Documento::count())->toBe($documenti);
});

it('la stessa ricevuta di nuovo si allega soltanto; una diversa è una correzione con il perché, e quella di prima resta fra gli eventi', function () {
    [$s, $riga] = lprDiffidaPartita($this);
    $this->travelTo('2026-03-22 09:00:00');
    $rotta = lttRotta($s, 'ricevuta', ['invio' => $riga->id]);
    $this->actingAs($this->user)->post($rotta, ['ricevuta' => lprRicevuta($riga)])->assertSessionHasNoErrors();

    $this->actingAs($this->user)->post($rotta, ['ricevuta' => lprRicevuta($riga, altro: ['nome' => 'copia.eml'])])->assertSessionHasNoErrors();
    expect(collect($riga->refresh()->dati['eventi'])->pluck('evento')->all())->toBe(['ricevuta', 'prova_allegata']);

    $this->actingAs($this->user)->post($rotta, ['ricevuta' => lprRicevuta($riga, '21/03/2026', '08:30')])
        ->assertSessionHasErrors(['perche' => RegistroLettere::CONSEGNA_GIA_REGISTRATA]);
    $this->actingAs($this->user)->post($rotta, ['ricevuta' => lprRicevuta($riga, '21/03/2026', '08:30'), 'perche' => 'La prima era di un\'altra PEC.'])
        ->assertSessionHasNoErrors();
    $riga->refresh();
    $ultimo = collect($riga->dati['eventi'])->last();
    expect($riga->ricevuta_il->format('Y-m-d'))->toBe('2026-03-21')
        ->and($ultimo)->toMatchArray(['evento' => 'ricevuta', 'perche' => 'La prima era di un\'altra PEC.', 'prima' => ['giorno' => '2026-03-20', 'ora' => '11:14', 'a_mano' => false]]);
});

it('quando la ricevuta non si legge: la risposta lo dice; con il giorno e l\'ora proposti dall\'invio la consegna è scritta a mano, con il file come prova', function () {
    [$s, $riga] = lprDiffidaPartita($this);
    $this->travelTo('2026-03-21 09:00:00');
    $rotta = lttRotta($s, 'ricevuta', ['invio' => $riga->id]);
    $pdf = fn () => UploadedFile::fake()->createWithContent('ricevuta-consegna.pdf', '%PDF-1.4 la ricevuta del gestore in PDF');

    $this->actingAs($this->user)->post($rotta, ['ricevuta' => $pdf()])
        ->assertSessionHasErrors(['ricevuta' => RegistroLettere::RICEVUTA_NON_LETTA, 'ricevuta_a_mano' => 'proposta']);
    expect($riga->refresh()->esito)->toBeNull()
        ->and(lprCorrispondenza($s)[0]['righe'][0]['pec']['invio'])->toBe(['giorno' => '2026-03-20', 'ora' => '11:00']);

    $this->actingAs($this->user)->post($rotta, ['ricevuta' => $pdf(), 'giorno' => '2026-03-19', 'ora' => '10:00'])
        ->assertSessionHasErrors(['giorno' => 'La consegna non può essere prima della PEC, partita il 20/03/2026.']);
    $this->actingAs($this->user)->post($rotta, ['ricevuta' => $pdf(), 'giorno' => '2026-03-20', 'ora' => '11:14'])->assertSessionHasNoErrors();

    $riga->refresh();
    expect($riga->esito)->toBe(RegistroInvio::ESITO_CONSEGNATA)
        ->and($riga->dati['consegna'])->toMatchArray(['giorno' => '2026-03-20', 'ora' => '11:14', 'a_mano' => true])
        ->and(collect($riga->dati['eventi'])->last())->toMatchArray(['evento' => 'consegna_a_mano', 'parole' => 'consegnata il 20/03 alle 11:14, scritta a mano, con la prova']);
    $lettera = lprCorrispondenza($s)[0];
    expect($lettera['righe'][0]['pec']['frase'])->toBe('Consegnata il 20/03 alle 11:14 a ugo@pec.it, scritta a mano, con la prova.')
        ->and($lettera['prove'][0])->toMatchArray(['nome' => 'ricevuta-consegna.pdf', 'rimovibile' => false])
        ->and($lettera['termine_dalla_consegna'])->toMatchArray(['consegna' => '2026-03-20', 'a_mano' => true]);

    // Poi arriva la ricevuta del gestore in .eml, con lo stesso giorno e la stessa ora: la conferma, e la consegna viene da lei.
    $this->actingAs($this->user)->post($rotta, ['ricevuta' => lprRicevuta($riga)])->assertSessionHasNoErrors();
    $riga->refresh();
    expect($riga->dati['consegna'])->toMatchArray(['a_mano' => false, 'gestore' => 'Gestore PEC di prova'])
        ->and(collect($riga->dati['eventi'])->last())->toMatchArray(['evento' => 'ricevuta', 'perche' => RegistroLettere::CONFERMA_DELLA_RICEVUTA]);
});

it('una PEC non partita: «Manda di nuovo per PEC» la rifà con le cifre di oggi in una riga nuova; poi non si rifà più', function () {
    $s = LetterePec::scenario($this);
    LetterePec::$errori = ['550 5.1.1 Mailbox unavailable'];
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), lttScelte($s, ['tipo' => 'diffida', 'testo' => 'Diffida a [nome], [totale].',
        'persone' => [$s['persone']['ugo']->id], 'canali' => [$s['persone']['ugo']->id => 'pec']]))->assertOk()->assertJsonPath('non_partite', 1);
    $riga = LetterePec::righePec($s, 'ugo')->sole();
    $pec = lprCorrispondenza($s)[0]['righe'][0]['pec'];
    expect($pec)->toMatchArray(['badge' => 'Non partita', 'ripetibile' => true, 'ricevibile' => false])
        ->and($pec['frase'])->toBe('Non partita il 20/03 alle 11:00, per ugo@pec.it: Il server della PEC non ha accettato il messaggio (codice 550).');

    // Ugo versa la rata 1 prima di rifarla: la lettera nuova ha le cifre di oggi.
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 6000], '2026-03-20');
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec-di-nuovo', ['invio' => $riga->id]))->assertOk()
        ->assertJsonPath('frase', '1 PEC è partita: il gestore l\'ha accettata, la consegna la dice la ricevuta.');
    $nuova = LetterePec::righePec($s, 'ugo')->last();
    expect($nuova->id)->not->toBe($riga->id)
        ->and($nuova->stato)->toBe(RegistroInvio::STATO_INVIATA)
        ->and($nuova->dati['totale'])->toBe(12000)
        ->and($nuova->dati['rifatta_da'])->toBe($riga->id)
        ->and(lprCorrispondenza($s)[1]['righe'][0]['pec']['ripetibile'])->toBeFalse();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec-di-nuovo', ['invio' => $riga->id]))->assertStatus(422);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec-di-nuovo', ['invio' => $nuova->id]))->assertStatus(422)
        ->assertJsonPath('message', \App\Services\Gestionale\Lettere\EmissioneLettere::SOLO_PEC_NON_PARTITE);
});

it('una lettera di prima andata per email alla PEC non si rifà per email: oggi la PEC parte dalla casella dello studio', function () {
    $s = LetterePec::scenario($this);
    $riga = RegistroInvio::create(['condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'tipo' => 'sollecito', 'canale' => RegistroInvio::CANALE_EMAIL,
        'documentabile_type' => \App\Models\Anagrafica::class, 'documentabile_id' => $s['persone']['ugo']->id, 'destinatario' => 'ugo@pec.it',
        'dati' => ['lettera' => 'l-vecchia', 'tipo_indirizzo' => 'pec', 'persona' => ['id' => $s['persone']['ugo']->id, 'nome' => 'Ugo'], 'righe' => [], 'totale' => 0],
        'stato' => RegistroInvio::STATO_NON_PARTITA, 'emesso_il' => now()->subDay()]);

    expect(app(\App\Services\Gestionale\Lettere\EmissioneLettere::class)->lettereDaRiprovare($s['c']))->toBe(0)
        ->and(lprCorrispondenza($s)[0]['righe'][0]['rimandabile'])->toBeFalse();
    config(['mail.default' => 'array']);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia-di-nuovo', ['invio' => $riga->id]))->assertStatus(422)
        ->assertJsonPath('message', \App\Services\Gestionale\Lettere\EmissioneLettere::ERA_PER_EMAIL_ALLA_PEC);
});
