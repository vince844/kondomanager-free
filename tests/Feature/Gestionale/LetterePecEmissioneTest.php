<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.13 (decisioni 569 e 588): le lettere a chi è in ritardo per PEC dalla casella dello studio.
 *
 * - I canali di ogni tipo: il promemoria resta email, stampa, PDF per persona; il sollecito ha anche la PEC e «PEC e carta»; diffida e messa in mora mai
 *   l'email: per PEC, su carta, o tutti e due. La frase della diffida lo dice.
 * - La PEC non è un indirizzo dell'email: le lettere non la spuntano più, e chi ha solo la PEC ha la frase con la casella (o senza).
 * - Il canale proposto: dalla sua ultima lettera, anche per PEC; senza lettere, la PEC a chi l'ha comunicata e la stampa agli altri (con il segno «PEC non
 *   comunicata»). Senza la casella dello studio la PEC è spenta, con il perché e «Imposta» per chi apre le impostazioni.
 * - «Manda le PEC»: dalla casella, con il PDF della lettera di oggi e il testo dell'email; partite, non accettate, il collegamento che non parte ferma le
 *   altre; al più 20 per volta; un invio per volta per condominio.
 * - «PEC e carta»: le due righe della stessa lettera, con lo stesso PDF e la stessa impronta, qualunque azione venga prima.
 * - Le rotte nuove con l'id di un altro condominio: 404.
 *
 * Nessuna PEC vera: la casella e il trasporto sono finti ({@see LetterePec}).
 */

use App\Models\Gestionale\RegistroInvio;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Posta\PecDelloStudio;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/LetterePecScenario.php';

beforeEach(function () {
    Storage::fake('local');
    $this->user = LetterePec::utente();
});

function lpeDiffida(array $s, array $altro = []): array
{
    return lttScelte($s, array_merge(['tipo' => 'diffida', 'testo' => 'Il testo della diffida dello studio, [nome], [totale].'], $altro));
}

function lpePersone($test, array $s, array $scelte): \Illuminate\Support\Collection
{
    return collect($test->actingAs($test->user)->postJson(lttRotta($s, 'anteprima'), $scelte)->assertOk()->json('persone'))->keyBy('nome');
}

it('i canali di ogni tipo: il promemoria senza PEC, il sollecito con tutti, diffida e messa in mora mai per email; la frase della diffida', function () {
    LetterePec::conLaCasella();
    $s = LetterePec::scenario($this);

    expect(lpePersone($this, $s, lttScelte($s, ['tipo' => 'sollecito']))['Ugo']['canali'])->toBe(['email', 'pec', 'pec_e_carta', 'stampa', 'zip'])
        ->and(lpePersone($this, $s, lpeDiffida($s))['Ugo']['canali'])->toBe(['pec', 'pec_e_carta', 'stampa', 'zip'])
        ->and(lpePersone($this, $s, lpeDiffida($s, ['tipo' => 'messa_in_mora']))['Ugo']['canali'])->toBe(['pec', 'pec_e_carta', 'stampa', 'zip']);
    $this->travelTo('2026-03-20 10:00:00');
    expect(EmissioneLettere::CANALI_PER_TIPO['promemoria'])->toBe(['email', 'stampa', 'zip'])
        ->and(EmissioneLettere::SOLO_CARTA)->toBe('Diffida e messa in mora non partono per email: mandale per PEC dalla casella dello studio, o stampale o scarica '
            . 'un PDF per persona e registra la spedizione.');
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lpeDiffida($s))->assertStatus(422)->assertJsonPath('message', EmissioneLettere::SOLO_CARTA);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), lttScelte($s, ['tipo' => 'promemoria', 'testo' => 'Promemoria a [nome].', 'termine' => null]))->assertStatus(422)
        ->assertJsonPath('message', EmissioneLettere::PROMEMORIA_NON_PER_PEC);
});

it('la PEC non è un indirizzo dell\'email delle lettere: spuntata non conta, l\'opzione ricordata la toglie, e chi ha solo la PEC ha la frase con la casella', function () {
    LetterePec::conLaCasella();
    $s = LetterePec::scenario($this);
    $s['persone']['bice']->update(['email' => null]);

    $persone = lpePersone($this, $s, lttScelte($s, ['indirizzi' => ['email', 'email_secondaria', 'pec']]));
    expect(collect($persone['Ugo']['indirizzi'])->pluck('tipo')->all())->toBe(['email'])
        ->and($persone['Bice'])->toMatchArray(['indirizzi' => [], 'solo_pec' => true, 'frase_solo_pec' => EmissioneLettere::SOLO_PEC_CON_LA_CASELLA]);

    // L'invio per email con la PEC spuntata: a Bice non parte niente per email.
    \Illuminate\Support\Facades\Queue::fake();
    config(['mail.default' => 'array']);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['indirizzi' => ['email', 'pec'], 'canali' => [$s['persone']['bice']->id => 'email']]))
        ->assertOk();
    expect(lttRighe($s, 'bice')->where('canale', RegistroInvio::CANALE_EMAIL))->toHaveCount(0);
    expect(app(EmissioneLettere::class)->sceltaRicordata($s['c'], $this->user)['indirizzi'])->toBe(['email']);
});

it('il canale proposto: la PEC a chi l\'ha comunicata, la stampa agli altri con il segno; senza la casella la PEC è spenta con il perché e «Imposta»', function () {
    LetterePec::conLaCasella();
    $s = LetterePec::scenario($this);

    $persone = lpePersone($this, $s, lpeDiffida($s));
    expect($persone['Ugo'])->toMatchArray(['canale' => 'pec', 'pec' => 'ugo@pec.it', 'pec_comunicata_il' => '2026-03-12', 'pec_non_comunicata' => false, 'canali_spenti' => []])
        ->and($persone['Bice'])->toMatchArray(['canale' => 'stampa', 'pec' => 'bice@pec.it', 'pec_comunicata_il' => null, 'pec_non_comunicata' => true]);
    // Il sollecito: l'email prima, a chi ce l'ha.
    expect(lpePersone($this, $s, lttScelte($s))->pluck('canale', 'nome')->all())->toBe(['Bice' => 'email', 'Ugo' => 'email']);

    LetterePec::senzaCasella();
    $a = $this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lpeDiffida($s))->assertOk()->json();
    $ugo = collect($a['persone'])->firstWhere('nome', 'Ugo');
    expect($ugo['canale'])->toBe('stampa')
        ->and($ugo['canali_spenti'])->toBe(['pec' => PecDelloStudio::NON_IMPOSTATA, 'pec_e_carta' => PecDelloStudio::NON_IMPOSTATA])
        ->and($a['casella_pec'])->toMatchArray(['impostata' => false, 'perche' => PecDelloStudio::NON_IMPOSTATA, 'dove' => route('impostazioni.mail', ['apri' => 'pec'])]);
    // La PEC scelta lo stesso senza la casella non vale: resta il canale proposto.
    $scelta = collect($this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lpeDiffida($s, ['canali' => [$s['persone']['ugo']->id => 'pec']]))
        ->json('persone'))->firstWhere('nome', 'Ugo');
    expect($scelta['canale'])->toBe('stampa');
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), lpeDiffida($s, ['canali' => [$s['persone']['ugo']->id => 'pec']]))->assertStatus(422)
        ->assertJsonPath('message', PecDelloStudio::NON_IMPOSTATA);
});

it('«Manda le PEC»: dalla casella dello studio, il PDF della lettera di oggi e il testo dell\'email; una riga «inviata» per persona; poi si propone la PEC', function () {
    LetterePec::conLaCasella();
    $s = LetterePec::scenario($this);
    $ugo = $s['persone']['ugo']->id;
    $bice = $s['persone']['bice']->id;

    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), lpeDiffida($s, ['canali' => [$ugo => 'pec', $bice => 'pec'], 'viste' => [$ugo, $bice]]))
        ->assertOk()->json();

    expect($r)->toMatchArray(['partite' => 2, 'non_partite' => 0, 'non_provate' => 0, 'restanti' => null,
        'frase' => '2 PEC sono partite: il gestore le ha accettate, la consegna la dice la ricevuta.'])
        ->and(LetterePec::$mandate)->toHaveCount(2);
    $messaggio = collect(LetterePec::$mandate)->first(fn ($m) => $m['messaggio']->getTo()[0]->getAddress() === 'ugo@pec.it')['messaggio'];
    expect($messaggio->getFrom()[0]->getAddress())->toBe('studio.costa@pec.it')
        ->and($messaggio->getSubject())->toBe($s['c']->nome . ' — Diffida')
        ->and($messaggio->getTextBody())->toContain('Gentile Ugo,')
        ->and($messaggio->getAttachments())->toHaveCount(1);
    $riga = LetterePec::righePec($s, 'ugo')->sole();
    expect($riga)->toMatchArray(['tipo' => 'diffida', 'stato' => RegistroInvio::STATO_INVIATA, 'destinatario' => 'ugo@pec.it'])
        ->and($riga->dati['testo'])->toBe('Il testo della diffida dello studio, Ugo, € 180,00.')
        ->and($riga->dati['testo_del_messaggio'])->toContain('Gentile Ugo,')
        ->and($riga->dati['oggetto'])->toBe($s['c']->nome . ' — Diffida')
        ->and($riga->dati['totale'])->toBe(18000)
        ->and($riga->impronta)->toBe(hash('sha256', $messaggio->getAttachments()[0]->getBody()))
        ->and($riga->documentabile_type)->toBe(\App\Models\Anagrafica::class);

    // La prossima diffida a Bice, che non l'ha comunicata: si propone la PEC, dall'ultima lettera.
    expect(lpePersone($this, $s, lpeDiffida($s))['Bice']['canale'])->toBe('pec');
});

it('una PEC non accettata resta non partita con la frase; il collegamento che non parte ferma le altre', function () {
    LetterePec::conLaCasella();
    $s = LetterePec::scenario($this);
    $canali = ['canali' => [$s['persone']['ugo']->id => 'pec', $s['persone']['bice']->id => 'pec']];

    // Bice viene prima (l'ordine dei nomi): il gestore la rifiuta, Ugo parte.
    LetterePec::$errori = ['550 5.1.1 Mailbox unavailable'];
    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), lpeDiffida($s, $canali))->assertOk()->json();
    expect($r)->toMatchArray(['partite' => 1, 'non_partite' => 1,
        'frase' => '1 PEC è partita: il gestore l\'ha accettata, la consegna la dice la ricevuta. L\'altra non è partita: il server della PEC non ha accettato il messaggio (codice 550).'])
        ->and(LetterePec::righePec($s, 'bice')->sole()->stato)->toBe(RegistroInvio::STATO_NON_PARTITA);

    $this->travelTo('2026-03-21 10:00:00');
    LetterePec::$errori = ['Connection could not be established with host "smtps.pec.esempio.it:465"'];
    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), lpeDiffida($s, $canali))->assertOk()->json();
    expect($r)->toMatchArray(['partite' => 0, 'non_partite' => 1, 'non_provate' => 1])
        ->and($r['frase'])->toStartWith('Il programma non riesce a collegarsi al server della PEC (smtps.pec.esempio.it, porta 465).')
        ->and($r['frase'])->toEndWith('Nessuna PEC è partita.')
        ->and(LetterePec::righePec($s, 'ugo'))->toHaveCount(1);
});

it('al più 20 PEC per volta: le altre tornano in «restanti»; un invio per volta per condominio', function () {
    // Venti, finché il tempo di una PEC non è misurato su una casella vera: cinquanta potevano superare il tempo che il server web aspetta.
    $persone = array_fill_keys(range(1, 23), ['nome' => 'x']);
    [$dentro, $fuori] = EmissioneLettere::dentroIlTettoDellaPec($persone);
    expect(EmissioneLettere::TETTO_PEC)->toBe(20)
        ->and(array_keys($dentro))->toBe(range(1, 20))
        ->and($fuori)->toBe([21, 22, 23]);

    LetterePec::conLaCasella();
    $s = LetterePec::scenario($this);
    $blocco = Cache::lock(EmissioneLettere::chiaveDelBlocco($s['c']), 60);
    expect($blocco->get())->toBeTrue();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), lpeDiffida($s, ['canali' => [$s['persone']['ugo']->id => 'pec']]))->assertStatus(422)
        ->assertJsonPath('message', EmissioneLettere::PEC_GIA_IN_CORSO);
    $blocco->release();
    expect(LetterePec::righePec($s, 'ugo'))->toHaveCount(0);
});

it('«PEC e carta»: la PEC e la copia su carta sono la stessa lettera, con lo stesso PDF e la stessa impronta, prima la PEC o prima la stampa', function () {
    LetterePec::conLaCasella();
    $s = LetterePec::scenario($this);
    $ugo = $s['persone']['ugo']->id;
    $bice = $s['persone']['bice']->id;
    $scelte = lpeDiffida($s, ['canali' => [$ugo => 'pec_e_carta', $bice => 'pec_e_carta'], 'viste' => [$ugo, $bice]]);

    // Ugo: prima la PEC, poi la stampa. Bice: le stesse azioni, ma l'anteprima dice lo stesso canale per tutti e due.
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), $scelte)->assertOk()->assertJsonPath('partite', 2);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), $scelte)->assertOk();

    $righe = lttRighe($s, 'ugo');
    expect($righe->pluck('canale')->all())->toBe(['pec', 'stampa'])
        ->and($righe->pluck('dati.lettera')->unique())->toHaveCount(1)
        ->and($righe[0]->impronta)->toBe($righe[1]->impronta)
        ->and($righe[1]->dati['documento_id'])->toBe($righe[0]->dati['documento_id'])
        ->and($righe->every(fn ($r) => ($r->dati['pec_e_carta'] ?? false) === true))->toBeTrue()
        ->and($righe[1]->stato)->toBe(RegistroInvio::STATO_STAMPATA);
    $pdfDellaPec = collect(LetterePec::$mandate)->first(fn ($m) => $m['messaggio']->getTo()[0]->getAddress() === 'ugo@pec.it')['messaggio']->getAttachments()[0]->getBody();
    expect(hash('sha256', $pdfDellaPec))->toBe($righe[1]->impronta);

    // Il giorno dopo, prima la stampa e poi la PEC: la PEC manda il PDF già stampato.
    $this->travelTo('2026-03-21 10:00:00');
    LetterePec::$mandate = [];
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), $scelte)->assertOk();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), $scelte)->assertOk();
    $nuove = lttRighe($s, 'bice')->filter(fn ($r) => $r->emesso_il->format('Y-m-d') === '2026-03-21')->values();
    expect($nuove->pluck('canale')->all())->toBe(['stampa', 'pec'])
        ->and($nuove->pluck('dati.lettera')->unique())->toHaveCount(1)
        ->and($nuove[0]->impronta)->toBe($nuove[1]->impronta)
        ->and(hash('sha256', LetterePec::$mandate[0]['messaggio']->getAttachments()[0]->getBody()))->toBe($nuove[0]->impronta);

    // L'anteprima propone «PEC e carta» dall'ultima lettera; il tetto del PDF conta anche le copie su carta.
    expect(lpePersone($this, $s, lpeDiffida($s))['Ugo']['canale'])->toBe('pec_e_carta');
});

it('le rotte nuove con una riga di un altro condominio: 404', function () {
    LetterePec::conLaCasella();
    $s = LetterePec::scenario($this);
    $altro = \App\Models\Condominio::factory()->create();
    $riga = RegistroInvio::create(['condominio_id' => $altro->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'tipo' => 'diffida', 'canale' => RegistroInvio::CANALE_PEC,
        'documentabile_type' => \App\Models\Anagrafica::class, 'documentabile_id' => $s['persone']['ugo']->id, 'destinatario' => 'ugo@pec.it', 'dati' => ['lettera' => 'x'],
        'stato' => RegistroInvio::STATO_NON_PARTITA, 'emesso_il' => now()]);

    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec-di-nuovo', ['invio' => $riga->id]))->assertNotFound();
    $this->actingAs($this->user)->post(lttRotta($s, 'ricevuta', ['invio' => $riga->id]), [
        'ricevuta' => \Illuminate\Http\UploadedFile::fake()->createWithContent('ricevuta.eml', LetterePec::ricevuta('ugo@pec.it', 'x', '20/03/2026', '10:14'))])
        ->assertNotFound();
    // Una persona di un altro condominio nella lista: 404 per tutta la richiesta, e nessuna PEC.
    $estranea = \App\Models\Anagrafica::factory()->create(['pec' => 'estranea@pec.it']);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'pec'), lpeDiffida($s, ['persone' => [$estranea->id]]))->assertNotFound();
    expect(LetterePec::$mandate)->toBe([]);
});
