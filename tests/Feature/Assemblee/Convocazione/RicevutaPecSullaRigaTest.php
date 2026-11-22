<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Posta\RicevutaPec;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (decisione 577 (1)): la ricevuta PEC trascinata sulla riga dell'invio si legge e si archivia.
 *
 * - La ricevuta di consegna (`tests/fixtures/pec/ricevuta_consegna_rossi.eml`, inventata: un gestore di prova, Rossi Mario di Via Roma 12) dà l'esito
 *   «Consegnata» con il giorno e l'ora del `daticert.xml` nel fuso di Roma; il file resta sulla riga, si scarica, e l'esito è una riga del registro.
 * - Un file che non è una ricevuta, una ricevuta di accettazione, una ricevuta di un'altra persona o di un'altra PEC: la pagina lo dice e niente si scrive.
 * - Una PEC rimasta «in coda» con la ricevuta di consegna è partita: diventa «inviata» e consegnata.
 * - Il giorno e l'ora scritti a mano restano possibili («Registra la consegna»).
 *
 * Che cosa non copre: la firma del gestore (il programma legge i dati, non verifica la firma; la ricevuta resta archiviata con la sua impronta); le ricevute
 * dei gestori veri (la fixture è scritta sulle regole tecniche, domanda nel rapporto).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function rpFixture(): string
{
    return (string) file_get_contents(base_path('tests/fixtures/pec/ricevuta_consegna_rossi.eml'));
}

/** La fixture con il daticert.xml cambiato: il tipo, l'indirizzo della consegna, l'oggetto. */
function rpVariante(array $sostituzioni): string
{
    $eml = rpFixture();
    preg_match('/filename="daticert\.xml"\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+?)\r?\n\r?\n/', $eml, $m);
    $xml = strtr((string) base64_decode($m[1]), $sostituzioni);

    return str_replace($m[1], chunk_split(base64_encode($xml), 76, "\r\n"), $eml);
}

function rpPecPartite($t): array
{
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($t);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();
    ConvocazioneDiViaRoma12::oggi('2026-12-04 09:00');

    return [$s, $a, ConvocazioneDiViaRoma12::invio($a, $s, 'rossi')];
}

it('la fixture si legge: ricevuta di consegna, il giorno e l\'ora nel fuso di Roma, l\'indirizzo della consegna e l\'oggetto', function () {
    $r = RicevutaPec::leggi(rpFixture());
    expect($r)->not->toBeNull()
        ->and($r->eDiConsegna())->toBeTrue()
        ->and($r->quando->format('Y-m-d H:i'))->toBe('2026-12-03 10:14')
        ->and($r->consegnataA)->toBe('mario.rossi@pec.it')
        ->and($r->oggetto)->toBe('Convocazione dell\'assemblea ordinaria — Condominio Via Roma 12, 15/12/2026')
        ->and($r->gestore)->toBe('Gestore PEC di prova');
    expect(RicevutaPec::leggi("From: a@b.it\r\nSubject: x\r\n\r\nniente"))->toBeNull();
});

it('la ricevuta di consegna trascinata sulla riga: consegnata il 03/12 alle 10:14, il file archiviato e scaricabile, una riga del registro', function () {
    [$s, $a, $rossi] = rpPecPartite($this);
    $prima = ViaRoma12::ultimaRiga();

    $this->post(route('admin.gestionale.assemblee.invii.ricevuta', [$s->c, $a, $rossi]), [
        'ricevuta' => UploadedFile::fake()->createWithContent('ricevuta-consegna.eml', rpFixture())])->assertSessionHasNoErrors();

    $rossi->refresh();
    expect($rossi->esito)->toBe('consegnata')
        ->and($rossi->ricevuta_il->format('Y-m-d'))->toBe('2026-12-03')
        ->and($rossi->esito_il->format('H:i'))->toBe('10:14')
        ->and(collect($rossi->dati['eventi'])->last()['ricevuta']['identificativo'])->toBe('opec0001.20261203101422.00042.01.1.1@pec.gestore-prova.it');
    $doc = Documento::query()->where('documentable_type', RegistroInvio::class)->where('documentable_id', $rossi->id)->where('name', 'ricevuta-consegna.eml')->first();
    expect($doc)->not->toBeNull();
    $this->get(route('admin.gestionale.assemblee.invii.prove.scarica', [$s->c, $a, $rossi, $doc->id]))->assertOk();
    $riga = ConvocazioneDiViaRoma12::righeDelRegistro($prima)->last();
    expect($riga->riepilogo)->toBe('PEC a Rossi Mario: consegnata il 03/12 alle 10:14, dalla ricevuta del gestore')
        ->and($riga->lotto['prova']['impronta'])->toBe(hash('sha256', rpFixture()));

    $scheda = collect(app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['convocati']['righe'])->firstWhere('nome', 'Rossi Mario');
    expect($scheda['stato_parola'])->toBe('Consegnata')->and($scheda['dettaglio'])->toBe('03/12 alle 10:14')->and($scheda['prova']['nome'])->toBe('Ricevuta');
});

it('un file che non è una ricevuta, la ricevuta di accettazione, quella di un\'altra persona o di un\'altra PEC: la pagina lo dice e niente si scrive', function () {
    [$s, $a, $rossi] = rpPecPartite($this);
    $rotta = route('admin.gestionale.assemblee.invii.ricevuta', [$s->c, $a, $rossi]);
    $prima = ViaRoma12::ultimaRiga();
    $documenti = Documento::query()->count();

    $this->post($rotta, ['ricevuta' => UploadedFile::fake()->createWithContent('lettera.eml', "From: a@b.it\r\nSubject: x\r\n\r\nniente")])
        ->assertSessionHasErrors(['ricevuta' => RicevutaPec::NON_E_UNA_RICEVUTA]);
    $this->post($rotta, ['ricevuta' => UploadedFile::fake()->createWithContent('accettazione.eml', rpVariante(['tipo="avvenuta-consegna"' => 'tipo="accettazione"']))])
        ->assertSessionHasErrors(['ricevuta' => 'Il file è la ricevuta di accettazione (il gestore ha preso la PEC, non l\'ha ancora consegnata): trascina la ricevuta '
            . 'di consegna, o l\'avviso di mancata consegna, che arrivano dopo.']);
    $this->post($rotta, ['ricevuta' => UploadedFile::fake()->createWithContent('altra.eml', rpVariante(['<consegna>mario.rossi@pec.it' => '<consegna>paolo.neri@pec.it']))])
        ->assertSessionHasErrors(['ricevuta' => 'La ricevuta è della consegna a paolo.neri@pec.it: non è di Rossi Mario, a cui la PEC è andata a mario.rossi@pec.it.']);
    $this->post($rotta, ['ricevuta' => UploadedFile::fake()->createWithContent('sollecito.eml', rpVariante(['Convocazione dell&apos;assemblea ordinaria' => 'Sollecito di pagamento']))])
        ->assertSessionHasErrors(['ricevuta' => 'La ricevuta è di un\'altra PEC a Rossi Mario («Sollecito di pagamento — Condominio Via Roma 12, 15/12/2026»), non della convocazione.']);

    expect($rossi->refresh()->esito)->toBeNull()
        ->and(Documento::query()->count())->toBe($documenti)
        ->and(ConvocazioneDiViaRoma12::righeDelRegistro($prima))->toHaveCount(0);
});

it('una PEC rimasta «in coda» con la ricevuta di consegna è partita: diventa inviata e consegnata', function () {
    [$s, $a, $rossi] = rpPecPartite($this);
    $rossi->forceFill(['stato' => RegistroInvio::STATO_IN_CODA])->save();

    $this->post(route('admin.gestionale.assemblee.invii.ricevuta', [$s->c, $a, $rossi]), [
        'ricevuta' => UploadedFile::fake()->createWithContent('ricevuta-consegna.eml', rpFixture())])->assertSessionHasNoErrors();

    expect($rossi->refresh()->stato)->toBe(RegistroInvio::STATO_INVIATA)->and($rossi->esito)->toBe('consegnata');
});

it('l\'avviso di mancata consegna trascinato sulla riga: «Non consegnata» con il file come prova, e la persona si spedisce di nuovo', function () {
    [$s, $a, $rossi] = rpPecPartite($this);

    $this->post(route('admin.gestionale.assemblee.invii.ricevuta', [$s->c, $a, $rossi]), ['ricevuta' => UploadedFile::fake()->createWithContent('mancata-consegna.eml',
        rpVariante(['tipo="avvenuta-consegna"' => 'tipo="errore-consegna"', 'errore="nessuno"' => 'errore="no-dest"']))])->assertSessionHasNoErrors();

    $rossi->refresh();
    expect($rossi->esito)->toBe('non_consegnata')
        ->and($rossi->esito_il->format('Y-m-d'))->toBe('2026-12-03')
        ->and(Documento::query()->where('documentable_type', RegistroInvio::class)->where('documentable_id', $rossi->id)->where('name', 'mancata-consegna.eml')->exists())
        ->toBeTrue();
    $scheda = collect(app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['convocati']['righe'])->firstWhere('nome', 'Rossi Mario');
    expect($scheda['stato_parola'])->toBe('Non consegnata');
});
