<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Convocazione\ConsegneDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Lettere\RegistroLettere;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §4.3 e §4.4 del progetto della tappa 2; §13 rilievo 14; legge §1): la spedizione della raccomandata con il numero; l'esito dalla
 * rotta con il giorno che conta (la giacenza conta, il ritiro dopo è un fatto nuovo che non lo sposta, «rifiutata» no); ogni esito e ogni correzione è una riga del registro
 * sull'assemblea con la persona fra i soggetti, e la correzione tiene il fatto di prima e chiede il perché; la prova non si toglie; il foglio delle consegne a
 * mano caricato una volta vale per tutte le sue righe.
 *
 * Che cosa non copre: la lettura della ricevuta PEC dal file; la pagina.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-12-02 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function convStampate($t): array
{
    $s = ConvocazioneDiViaRoma12::nuovo($t);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();

    return [$s, $a];
}

it('la raccomandata spedita con il numero, poi in giacenza: il giorno che conta è l\'avviso; il ritiro dopo non lo sposta; ogni esito è una riga del registro', function () {
    [$s, $a] = convStampate($this);
    $carla = ConvocazioneDiViaRoma12::invio($a, $s, 'carla');
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [$carla->id], 'canale' => 'raccomandata',
        'data' => '2026-12-02', 'numeri' => [$carla->id => '61 2345 6789']])->assertRedirect()->assertSessionHasNoErrors();
    expect($carla->refresh()->stato)->toBe(RegistroInvio::STATO_SPEDITA)->and($carla->dati['spedizione']['numero'])->toBe('61 2345 6789');

    ConvocazioneDiViaRoma12::oggi('2026-12-08 10:00');
    $prima = ViaRoma12::ultimaRiga();
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $carla]), ['esito' => 'in_giacenza', 'giorno' => '2026-12-07',
        'prova' => UploadedFile::fake()->create('avviso-giacenza.pdf', 20, 'application/pdf')])->assertRedirect()->assertSessionHasNoErrors();
    $carla->refresh();
    expect($carla->esito)->toBe('in_giacenza')->and($carla->ricevuta_il->format('Y-m-d'))->toBe('2026-12-07');
    $riga = ConvocazioneDiViaRoma12::righeDelRegistro($prima)->last();
    expect($riga->riepilogo)->toBe('Raccomandata a Neri Carla: in giacenza, avviso del 07/12')
        ->and($riga->oggetto_tipo)->toBe('assemblea')->and($riga->oggetto_id)->toBe($a->id)
        ->and($riga->lotto['invio_id'])->toBe($carla->id)->and($riga->lotto['ricevuta_il'])->toBe('2026-12-07');

    // Un altro esito su una riga con l'esito è una correzione, con il perché.
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $carla]), ['esito' => 'consegnata', 'giorno' => '2026-12-08'])
        ->assertSessionHasErrors(['perche' => ConsegneDellaConvocazione::GIA_REGISTRATO]);
    // Ritirata in posta l'08/12: un fatto nuovo, non una correzione, senza il perché; il giorno che conta resta l'avviso del 07/12.
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $carla]), ['esito' => 'ritirata', 'giorno' => '2026-12-06'])
        ->assertSessionHasErrors(['giorno' => 'Il ritiro non può essere prima dell\'avviso di giacenza, del 07/12/2026.']);
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $carla]), ['esito' => 'ritirata', 'giorno' => '2026-12-08'])
        ->assertSessionHasNoErrors();
    $carla->refresh();
    expect($carla->esito)->toBe('consegnata')->and($carla->ricevuta_il->format('Y-m-d'))->toBe('2026-12-07')
        ->and(collect($carla->dati['eventi'])->pluck('evento')->all())->toBe(['spedizione', 'prova_allegata', 'esito', 'ritiro']);
    $ritiro = ConvocazioneDiViaRoma12::righeDelRegistro($prima)->last();
    expect($ritiro->riepilogo)->toBe('Raccomandata a Neri Carla: ritirata l\'08/12; conta l\'avviso del 07/12')
        ->and($ritiro->perche)->toBeNull();
});

it('il ritiro si registra solo su una raccomandata in giacenza', function () {
    [$s, $a] = convStampate($this);
    $gallo = ConvocazioneDiViaRoma12::invio($a, $s, 'gallo');
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [$gallo->id], 'canale' => 'raccomandata', 'data' => '2026-12-02']);
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $gallo]), ['esito' => 'ritirata', 'giorno' => '2026-12-02'])
        ->assertSessionHasErrors(['esito' => 'Il ritiro si registra su una raccomandata in giacenza.']);
});

it('«rifiutata» non dà il giorno di ricezione; la correzione del giorno dice «Ricevuta … corretta: 10/12 → 09/12»', function () {
    [$s, $a] = convStampate($this);
    $gallo = ConvocazioneDiViaRoma12::invio($a, $s, 'gallo');
    $marco = ConvocazioneDiViaRoma12::invio($a, $s, 'marco');
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [$gallo->id, $marco->id], 'canale' => 'raccomandata', 'data' => '2026-12-02']);
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $gallo]), ['esito' => 'rifiutata', 'giorno' => '2026-12-05'])->assertSessionHasNoErrors();
    expect($gallo->refresh()->ricevuta_il)->toBeNull()->and($gallo->esito)->toBe('rifiutata');

    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $marco]), ['esito' => 'consegnata', 'giorno' => '2026-12-10']);
    $prima = ViaRoma12::ultimaRiga();
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $marco]), ['esito' => 'consegnata', 'giorno' => '2026-12-09',
        'perche' => 'La cartolina dice il 9'])->assertSessionHasNoErrors();
    expect(ConvocazioneDiViaRoma12::righeDelRegistro($prima)->last()->riepilogo)->toBe('Ricevuta di Russo Marco corretta: 10/12 → 09/12')
        ->and($marco->refresh()->ricevuta_il->format('Y-m-d'))->toBe('2026-12-09');
});

it('la prova di una convocazione non si toglie, anche con l\'esercizio aperto', function () {
    [$s, $a] = convStampate($this);
    $carla = ConvocazioneDiViaRoma12::invio($a, $s, 'carla');
    expect(app(RegistroLettere::class)->provaRimovibile($carla))->toBeFalse();
});

it('il foglio delle consegne a mano: si stampa, e la scansione caricata una volta vale per le sue righe, ognuna con il suo giorno; una riga nel registro', function () {
    [$s, $a] = convStampate($this);
    $foglio = $this->post(route('admin.gestionale.assemblee.convocazione.foglio', [$s->c, $a]));
    $foglio->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $anna = ConvocazioneDiViaRoma12::invio($a, $s, 'anna');
    $luca = ConvocazioneDiViaRoma12::invio($a, $s, 'luca');
    ConvocazioneDiViaRoma12::oggi('2026-12-05 10:00');
    $prima = ViaRoma12::ultimaRiga();

    $this->post(route('admin.gestionale.assemblee.convocazione.foglio-firmato', [$s->c, $a]), [
        'foglio' => UploadedFile::fake()->create('foglio-firmato.pdf', 50, 'application/pdf'),
        'consegne' => [['invio_id' => $anna->id, 'giorno' => '2026-12-04'], ['invio_id' => $luca->id, 'giorno' => '2026-12-05']],
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($anna->refresh()->ricevuta_il->format('Y-m-d'))->toBe('2026-12-04')->and($luca->refresh()->ricevuta_il->format('Y-m-d'))->toBe('2026-12-05')
        ->and($anna->dati['foglio_documento_id'])->toBe($luca->dati['foglio_documento_id']);
    // La consegna a mano registrata è la prima spedizione (la stampa non spedisce): la seconda riga è l'assemblea convocata.
    $righe = ConvocazioneDiViaRoma12::righeDelRegistro($prima);
    expect($righe)->toHaveCount(2)->and($righe[0]->riepilogo)->toBe('Consegna a mano registrata: 2 persone, con il foglio firmato')
        ->and($righe[1]->riepilogo)->toBe('Assemblea ordinaria del 15/12/2026 convocata');

    // Un invio di un'altra assemblea nel corpo: 404.
    $altra = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->post(route('admin.gestionale.assemblee.convocazione.foglio-firmato', [$s->c, $altra]), [
        'foglio' => UploadedFile::fake()->create('foglio.pdf', 5, 'application/pdf'), 'consegne' => [['invio_id' => $anna->id, 'giorno' => '2026-12-04']]])->assertNotFound();
});
