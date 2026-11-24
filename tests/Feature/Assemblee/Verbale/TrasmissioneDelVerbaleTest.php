<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\ConvocatoDellAssemblea;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Verbale\DestinatariDelVerbale;
use App\Services\Assemblee\Verbale\PdfDellaCopia;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';
require_once __DIR__ . '/../../Pdf/Support/CartaDelloStudio.php';

/**
 * La trasmissione del verbale (V10, decisione 595 (3), scena 6): la lista proposta dalla sala (chi non è mai entrato; chi era fuori al voto di un punto,
 * uscito prima o entrato dopo il voto, per quei punti; il conduttore per i suoi punti); in videoconferenza o mista tutti; «Togli» e «Aggiungi» con il perché
 * nel registro delle modifiche; il mezzo della convocazione; la PEC con la copia sottoscritta com'è (il `.p7m` compreso); la lettera con la copia unita (dal
 * `.p7m` il PDF dentro); la spedizione e la consegna dalle rotte degli invii dell'assemblea; la «Corrispondenza» della persona e il Diario.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function tdvRegistrato($t, ?string $copia = null): array
{
    [$s, $a, $seduta, $v] = VerbaleDiViaRoma12::salaChiusa($t);
    $verbale = VerbaleDiViaRoma12::genera($t, $s, $a);
    VerbaleDiViaRoma12::registra($t, $s, $verbale, $copia === null ? [] : ['copia' => VerbaleDiViaRoma12::copia($copia)])->assertSessionHasNoErrors();
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:37');

    return [$s, $a->refresh(), $verbale->refresh(), $v];
}

function tdvPersona(array $d, string $nome): array
{
    return collect($d['persone'])->firstWhere('nome', $nome) ?? throw new \RuntimeException('Nessuno: ' . $nome);
}

/** I termini di una persona senza le delibere e le voci (per votazione: le prova `DueVotiNelloStessoPuntoTest`). @return list<array<string, mixed>> */
function tdvTermini(array $persona): array
{
    return array_map(fn ($t) => array_diff_key($t, ['delibere' => true, 'voci' => true]), $persona['termini']);
}

/**
 * La sala del 15/12/2026 con i Russo comproprietari dell'Int. 5 che entrano insieme all'ora data (Russo Marco rappresenta l'unità, Russo Anna presente
 * senza voto, rilievo C53): gli altri come la sala del verbale, i punti 1–5 tutti favorevoli (alle 19:00, 19:10, 19:25, 19:44, 20:05), le varie senza
 * voto, la chiusura, il verbale n. 42 registrato. @return array{0: Beta51Scenario, 1: \App\Models\Assemblee\Assemblea, 2: \App\Models\Assemblee\VerbaleDellAssemblea}
 */
function tdvRussoInsieme($t, string $alle): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
    SalaDiViaRoma12::entrano($t, $s, $a, ['rossi', 'rita', 'gallo', ...array_map(fn ($i) => 'c' . $i, range(30, 36))]);
    $entrano = fn () => SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id,
        'persona' => ['anagrafica_id' => $s->persone['marco']->id], 'come' => 'in_sala', 'con' => [$s->persone['anna']->id]], '2026-12-15 ' . $alle));
    $entrati = false;
    foreach ([1 => '19:00', 2 => '19:10', 3 => '19:25', 4 => '19:44', 5 => '20:05'] as $numero => $ora) {
        if (! $entrati && $alle < $ora) {
            $entrano();
            $entrati = true;
        }
        VerbaleDiViaRoma12::tuttiFavorevoli($t, $s, $a, $numero, '2026-12-15 ' . $ora);
    }
    if (! $entrati) {
        $entrano();
    }
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('trattato', ['seduta_id' => $seduta->id, 'punto_id' => VerbaleDiViaRoma12::punto($a, 6)], '2026-12-15 20:30'));
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));
    $verbale = VerbaleDiViaRoma12::genera($t, $s, $a->refresh());
    VerbaleDiViaRoma12::registra($t, $s, $verbale)->assertSessionHasNoErrors();

    return [$s, $a->refresh(), $verbale->refresh()];
}

it('la lista proposta: chi non è mai entrato, chi è entrato dopo il voto, chi è uscito prima, il conduttore per i suoi punti; chi c\'era non la riceve', function () {
    [, , $verbale] = tdvRegistrato($this);
    $d = app(DestinatariDelVerbale::class)->per($verbale);

    expect(tdvPersona($d, 'Condomino 37'))->toMatchArray(['gruppo' => 'mai_entrati', 'riceve' => true, 'perche' => 'assente a tutti i punti', 'punti_assente' => [1, 2, 3, 4, 5]])
        // Le parole senza genere (rilievo C59): «in sala dalle», «fuori dalle».
        ->and(tdvPersona($d, 'Bianchi Anna'))->toMatchArray(['gruppo' => 'fuori', 'riceve' => true, 'perche' => 'in sala dalle 19:20: assente ai punti 1 e 2'])
        ->and(tdvPersona($d, 'Condomino 36'))->toMatchArray(['gruppo' => 'fuori', 'perche' => 'fuori dalle 19:30: assente ai punti 4 e 5'])
        ->and(tdvPersona($d, 'Ferri Luca'))->toMatchArray(['gruppo' => 'mai_entrati', 'punti_assente' => [5]])
        ->and(tdvPersona($d, 'Colombo Rita'))->toMatchArray(['gruppo' => 'cera', 'riceve' => false])
        ->and(tdvTermini(tdvPersona($d, 'Colombo Rita')))->toBe([['punti' => [1, 4], 'perche' => 'contrario', 'dal' => '2026-12-15', 'fino_al' => '2027-01-14',
            'da_consegnare' => false, 'dal_come' => null]])
        ->and(tdvPersona($d, 'Russo Marco')['termini'][0])->toMatchArray(['punti' => [4], 'perche' => 'non_votato', 'fino_al' => '2027-01-14'])
        // Ai punti 3, 4 e 5 c'era ed era favorevole: niente termine. Ai punti 1 e 2 era fuori: dalla consegna, che non c'è ancora.
        ->and(tdvTermini(tdvPersona($d, 'Bianchi Anna')))->toBe([['punti' => [1, 2], 'perche' => 'assente', 'dal' => null, 'fino_al' => null, 'da_consegnare' => true, 'dal_come' => null]])
        ->and($d['quanti'])->toBe(18)->and($d['spediti'])->toBe(0);
});

it('in videoconferenza o mista il verbale va a tutti i convocati', function () {
    [, $a, $verbale] = tdvRegistrato($this);
    $a->forceFill(['modalita' => 'mista'])->saveQuietly();
    $d = app(DestinatariDelVerbale::class)->per($verbale->refresh());
    expect(collect($d['persone'])->pluck('gruppo')->unique()->values()->all())->toBe(['tutti'])->and(tdvPersona($d, 'Colombo Rita')['riceve'])->toBeTrue();
});

it('«Togli» con il perché: non riceve, e per i punti in cui era fuori conta come chi c\'era e non ha votato; «Aggiungi» fra chi c\'era: i trenta giorni dalla consegna', function () {
    [$s, $a, $verbale] = tdvRegistrato($this);
    $c36 = $s->persone['c36']->id;
    $prima = (int) \App\Models\RegistroModifica::query()->max('id');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.destinatari.togli', [$s->c, $a, $verbale]), ['anagrafica_id' => $c36,
        'perche' => 'È rientrato alle 19:40, prima del voto del punto 4, e in sala non è stato segnato.'])->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.destinatari.aggiungi', [$s->c, $a, $verbale]), ['anagrafica_id' => $s->persone['rita']->id,
        'punti' => [4], 'perche' => 'La sala la dice presente, ma era uscita.'])->assertSessionHasNoErrors();

    $d = app(DestinatariDelVerbale::class)->per($verbale->refresh());
    expect(tdvPersona($d, 'Condomino 36')['riceve'])->toBeFalse()
        ->and(tdvTermini(tdvPersona($d, 'Condomino 36')))->toBe([['punti' => [4, 5], 'perche' => 'tolto', 'dal' => '2026-12-15', 'fino_al' => '2027-01-14', 'da_consegnare' => false, 'dal_come' => null]])
        ->and(tdvPersona($d, 'Colombo Rita'))->toMatchArray(['riceve' => true, 'gruppo' => 'aggiunti', 'punti_assente' => [4]])
        ->and(\App\Models\RegistroModifica::query()->where('id', '>', $prima)->pluck('riepilogo')->all())
        ->toBe(['chi lo riceve: tolta dalla lista: Condomino 36 · i punti 4 e 5 contati dal 15/12', 'chi lo riceve: aggiunta a mano: Colombo Rita, come assente per il punto 4 · i trenta giorni dalla consegna']);
});

it('«Manda le PEC»: la lettera e la copia sottoscritta com\'è, il .p7m compreso; la riga del registro degli invii è del tipo del verbale', function () {
    [$s, $a, $verbale] = tdvRegistrato($this, 'verbale_firmato.pdf.p7m');
    ConvocatoDellAssemblea::query()->where('assemblea_id', $a->id)->where('anagrafica_id', $s->persone['c37']->id)->update(['mezzo' => 'pec', 'pec' => 'c37@pec.it']);
    ConvocazioneDiViaRoma12::conLaCasella();

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.pec', [$s->c, $a, $verbale]))->assertSessionHasNoErrors();

    $riga = RegistroInvio::query()->where('tipo', 'verbale')->firstOrFail();
    $messaggio = ConvocazioneDiViaRoma12::$pecMandate[0]['messaggio'];
    $allegati = collect($messaggio->getAttachments())->map(fn ($x) => $x->getFilename())->all();
    expect(count(ConvocazioneDiViaRoma12::$pecMandate))->toBe(1)->and($riga->stato)->toBe('inviata')->and($riga->dati['verbale_id'])->toBe($verbale->id)
        ->and($riga->destinatario)->toBe('c37@pec.it')->and($allegati)->toBe(['verbale-42-condomino-37.pdf', 'verbale-42-sottoscritto.p7m'])
        ->and(collect($messaggio->getAttachments())->last()->getBody())->toStartWith("\x30");
});

it('«Stampa le lettere»: per persona la lettera e la copia unita (dal .p7m il PDF dentro); poi spedizione e consegna dalle rotte degli invii', function () {
    [$s, $a, $verbale] = tdvRegistrato($this, 'verbale_firmato.pdf.p7m');
    $r = $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.lettere', [$s->c, $a, $verbale]), ['anagrafiche' => [$s->persone['bianchi']->id]]);
    expect($r->status())->toBe(200)->and($r->headers->get('X-Lettere'))->toBe('1')->and(cdsNumeroPagine((string) $r->getContent()))->toBe(2);
    $riga = RegistroInvio::query()->where('tipo', 'verbale')->where('anagrafica_id', $s->persone['bianchi']->id)->firstOrFail();
    expect($riga->stato)->toBe('stampata')->and($riga->dati['mezzo'])->toBe('raccomandata');

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.spedizione', [$s->c, $a, $verbale]), ['invii' => [$riga->id], 'canale' => 'raccomandata',
        'data' => '2026-12-15'])->assertSessionHasNoErrors();
    ConvocazioneDiViaRoma12::oggi('2026-12-18 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $riga]), ['esito' => 'consegnata', 'giorno' => '2026-12-18'])
        ->assertSessionHasNoErrors();
    $d = app(DestinatariDelVerbale::class)->per($verbale);
    expect($riga->refresh()->ricevuta_il->format('Y-m-d'))->toBe('2026-12-18')
        ->and(tdvPersona($d, 'Bianchi Anna')['termini'][0])->toMatchArray(['perche' => 'assente', 'dal' => '2026-12-18', 'fino_al' => '2027-01-18'])
        ->and(collect(app(\App\Services\Assemblee\Convocazione\DiarioDegliInvii::class)->righe($a))->pluck('frase')->all())
        ->toContain('Verbale n. 42, raccomandata a Bianchi Anna: spedita il 15/12');
    // La «Corrispondenza» della persona.
    $voci = app(\App\Services\Gestionale\Lettere\CorrispondenzaDellaPersona::class)->documenti($s->c, $s->persone['bianchi']);
    expect(collect($voci)->firstWhere('tipo', 'verbale'))->toMatchArray(['numero' => 42, 'assemblea_del' => '2026-12-15'])
        ->and(collect($voci)->firstWhere('tipo', 'verbale')['righe'][0]['esito'])->toBe('consegnata');
});

it('il PDF dentro il .p7m si toglie dalla busta; un file che non è una busta non dà niente', function () {
    $pdf = VerbaleDiViaRoma12::pdfVero('Verbale firmato');
    expect(PdfDellaCopia::dalP7m(VerbaleDiViaRoma12::p7m($pdf)))->toBe($pdf)->and(PdfDellaCopia::dalP7m('non è una busta'))->toBeNull();
});

it('un PDF che la libreria non legge si rifiuta alla registrazione e alla sostituzione, e la stampa delle lettere dà la frase, non un errore (decisione 658)', function () {
    $frase = 'Questo PDF non si unisce alle lettere: caricalo come immagine (JPG o PNG) o salvalo di nuovo come PDF semplice.';
    $finto = fn () => UploadedFile::fake()->createWithContent('scansione.pdf', "%PDF-1.4\nun PDF compresso che non si legge\n%%EOF");

    [$s, $a, $seduta, $v] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['copia' => $finto()])->assertSessionHasErrors(['copia' => $frase]);
    expect($verbale->refresh()->documento_id)->toBeNull();
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['copia' => UploadedFile::fake()->createWithContent('verbale.pdf.p7m', VerbaleDiViaRoma12::p7m("%PDF-1.4\nrovinato"))])
        ->assertSessionHasErrors(['copia' => $frase]);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:37');
    $a->refresh();
    $verbale->refresh();

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.copia', [$s->c, $a, $verbale]), ['copia' => $finto(), 'perche' => 'Prova.'])
        ->assertSessionHasErrors(['copia' => $frase]);

    // Una copia arrivata prima del controllo, scritta direttamente: la stampa delle lettere dice la frase.
    $doc = \App\Models\Documento::query()->findOrFail($verbale->documento_id);
    Storage::disk(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco())->put($doc->path, "%PDF-1.4\nun PDF compresso che non si legge\n%%EOF");
    $r = $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.lettere', [$s->c, $a, $verbale]), ['anagrafiche' => [$s->persone['bianchi']->id]]);
    $r->assertSessionHasErrors(['trasmissione' => $frase]);
    expect($r->status())->toBe(302)
        ->and(RegistroInvio::query()->where('tipo', 'verbale')->where('anagrafica_id', $s->persone['bianchi']->id)->count())->toBe(0);
});

it('il comproprietario entrato «insieme» dopo il rendiconto è fuori come chi rappresenta l\'unità, con la stessa frase (Coda 750 B3)', function () {
    [, , $verbale] = tdvRussoInsieme($this, '19:20');
    $d = app(DestinatariDelVerbale::class)->per($verbale);

    $frase = 'in sala dalle 19:20: assente ai punti 1 e 2';
    expect(tdvPersona($d, 'Russo Anna'))->toMatchArray(['gruppo' => 'fuori', 'riceve' => true, 'perche' => $frase, 'punti_assente' => [1, 2]])
        ->and(tdvPersona($d, 'Russo Marco'))->toMatchArray(['gruppo' => 'fuori', 'riceve' => true, 'perche' => $frase, 'punti_assente' => [1, 2]]);
});

it('entrati «insieme» dopo tutti i voti: chi era in sala senza voto non è fra chi non è mai entrato (Coda 750 B3)', function () {
    [, , $verbale] = tdvRussoInsieme($this, '20:10');
    $d = app(DestinatariDelVerbale::class)->per($verbale);

    $frase = 'in sala dalle 20:10: assente ai punti 1, 2, 3, 4 e 5';
    expect(tdvPersona($d, 'Russo Anna'))->toMatchArray(['gruppo' => 'fuori', 'perche' => $frase])
        ->and(tdvPersona($d, 'Russo Marco'))->toMatchArray(['gruppo' => 'fuori', 'perche' => $frase]);
});
