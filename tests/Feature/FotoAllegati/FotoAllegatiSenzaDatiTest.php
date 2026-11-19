<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # La foto dal telefono negli allegati: il server toglie i dati della foto (decisione 531)
 *
 * Il browser rimpicciolisce la foto e, ricodificandola, ne toglie i dati; quando non ci riesce (oltre 20 secondi, nessun canvas) o quando la foto è
 * piccola (un PNG o un WebP fino a 1.600 pixel parte com'è), arriva al server il file originale, con la posizione del luogo dove è stato scattato. Dalla
 * decisione 531 il server toglie da **ogni** foto degli allegati (JPG, PNG, WebP: i formati d'immagine che gli allegati accettano) i blocchi dei dati —
 * EXIF con la posizione, XMP, IPTC, i commenti, la data — **senza decodificare l'immagine**: i pixel restano byte per byte. Resta solo il verso della
 * foto (il valore «Orientation» dell'EXIF), che non dice niente di chi l'ha scattata e senza il quale una foto fatta col telefono in verticale si vedrebbe
 * coricata. Il peso salvato è quello del file pulito.
 *
 * Che cosa non copre: il telefono vero (quanto spesso la ricodifica del browser non riesce); le foto di altre parti del programma (l'archivio dei
 * documenti, gli accordi di rientro, la carta intestata, che ha già la sua pulizia); i PDF, che non sono foto; MySQL (sqlite in memoria); quanta memoria
 * usa la pulizia di un file grande (si legge il file intero, senza decodificarlo: circa tre volte il suo peso, letto nel codice, non misurato).
 */

use App\Services\Documenti\ArchivioDocumenti;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../Allegati/AllegatiScenario.php';
require_once __DIR__ . '/FotoConIDatiScenario.php';

beforeEach(function () {
    algPrepara($this);
    $this->withoutVite();
    $this->mario = algUtente([$this->aurora->id], 'Mario Rossi');
    $this->paolo = algUtente([$this->aurora->id], 'Paolo Neri');
    $this->segnalazione = algSegnalazione($this->aurora, $this->mario);
    // Senza moderazione, così l'allegato di Mario lo scarica subito anche Paolo, il vicino.
    DB::table('segnalazioni')->where('id', $this->segnalazione->id)->update(['allegati_moderati' => false]);
});

it('la foto JPG con la posizione, l\'XMP, l\'IPTC e un commento arriva al server così com\'è: il file salvato e quello scaricato dal vicino non li hanno più, i pixel sì', function () {
    $foto = fsdJpg();
    expect(str_contains($foto, FSD_LATITUDINE))->toBeTrue();

    algCarica($this, $this->mario, $this->segnalazione, [algFile('foto-20261118-101500.jpg', $foto)])->assertSessionHasNoErrors();

    $riga = algRighe($this->segnalazione)->sole();
    $salvato = (string) app(ArchivioDocumenti::class)->disco()->get($riga->path);
    $scaricato = fsdCorpo(algScarica($this, $this->paolo, (int) $riga->id)->assertOk());
    foreach ([$salvato, $scaricato] as $byte) {
        // Con il codice di prima i byte salvati erano quelli caricati: la latitudine, «Via Roma 1» e l'XMP c'erano tutti.
        expect(str_contains($byte, FSD_LATITUDINE))->toBeFalse()
            ->and(str_contains($byte, 'Via Roma'))->toBeFalse()
            ->and(str_contains($byte, 'GPSLatitude'))->toBeFalse()
            ->and(str_contains($byte, 'Photoshop 3.0'))->toBeFalse();
    }
    $dati = @exif_read_data('data://image/jpeg;base64,' . base64_encode($salvato));
    expect(is_array($dati) ? ($dati['GPSLatitude'] ?? null) : null)->toBeNull()
        ->and(fsdPixel($salvato))->toBe(fsdPixel($foto))
        ->and((int) $riga->file_size)->toBe(strlen($salvato))
        ->and((int) $riga->file_size)->toBeLessThan(strlen($foto))
        ->and($riga->mime_type)->toBe('image/jpeg')
        ->and($riga->name)->toBe('foto-20261118-101500.jpg');
});

it('anche il PNG e il WebP: niente `eXIf`, testi, XMP e data nel PNG, niente EXIF e XMP nel WebP; i pixel restano gli stessi', function () {
    algCarica($this, $this->mario, $this->segnalazione, [algFile('schermata.png', fsdPng()), algFile('ingresso.webp', fsdWebp())])->assertSessionHasNoErrors();

    [$png, $webp] = algRighe($this->segnalazione)->map(fn ($r) => (string) app(ArchivioDocumenti::class)->disco()->get($r->path))->all();
    foreach ([$png, $webp] as $byte) {
        expect(str_contains($byte, FSD_LATITUDINE))->toBeFalse()
            ->and(str_contains($byte, 'Via Roma'))->toBeFalse()
            ->and(str_contains($byte, 'GPSLatitude'))->toBeFalse();
    }
    foreach (['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'] as $blocco) {
        expect(str_contains($png, $blocco))->toBeFalse("Nel PNG è rimasto il blocco {$blocco}");
    }
    expect(str_contains($webp, 'EXIF'))->toBeFalse()->and(str_contains($webp, 'XMP '))->toBeFalse()
        // Il RIFF dice il peso giusto, e il VP8X non promette più i blocchi tolti.
        ->and(unpack('V', substr($webp, 4, 4))[1])->toBe(strlen($webp) - 8)
        ->and(ord($webp[20]) & 0x0C)->toBe(0);

    expect(fsdPixel($png))->toBe(fsdPixel(fsdPng()))
        ->and(fsdPixel($webp))->toBe(fsdPixel(fsdWebp()))
        ->and(getimagesizefromstring($webp)[0] ?? null)->toBe(16)
        ->and(algRighe($this->segnalazione)->map(fn ($r) => (int) $r->file_size)->all())->toBe([strlen($png), strlen($webp)]);
});

it('il verso della foto resta: una foto scattata in verticale (Orientation 6) non si vede coricata, e la posizione non c\'è più', function () {
    algCarica($this, $this->mario, $this->segnalazione, [
        algFile('verticale.jpg', fsdJpg(6)), algFile('verticale.png', fsdPng(6)), algFile('verticale.webp', fsdWebp(6)),
    ])->assertSessionHasNoErrors();

    [$jpg, $png, $webp] = algRighe($this->segnalazione)->map(fn ($r) => (string) app(ArchivioDocumenti::class)->disco()->get($r->path))->all();
    $dati = @exif_read_data('data://image/jpeg;base64,' . base64_encode($jpg));
    expect($dati['Orientation'] ?? null)->toBe(6)
        ->and($dati['GPSLatitude'] ?? null)->toBeNull()
        ->and(fsdPixel($jpg))->toBe(fsdPixel(fsdJpg(6)));
    // Nel PNG e nel WebP il blocco dell'EXIF c'è ancora, con il solo verso.
    $exifPng = substr($png, strpos($png, 'eXIf') + 4, unpack('N', substr($png, strpos($png, 'eXIf') - 4, 4))[1]);
    $exifWebp = substr($webp, strpos($webp, 'EXIF') + 8, unpack('V', substr($webp, strpos($webp, 'EXIF') + 4, 4))[1]);
    foreach ([$exifPng, $exifWebp] as $tiff) {
        expect(fsdVersoDelTiff($tiff))->toBe(6)->and(str_contains($tiff, FSD_LATITUDINE))->toBeFalse()->and(strlen($tiff))->toBeLessThan(40);
    }
    expect(ord($webp[20]) & 0x0C)->toBe(0x08);
});

it('una foto che non ha la forma del suo tipo (un JPG tagliato a metà) si rifiuta con una frase, e non resta niente', function () {
    $tagliata = substr(fsdJpg(), 0, -40);
    expect(finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $tagliata))->toBe('image/jpeg');

    $risposta = algCarica($this, $this->mario, $this->segnalazione, [algFile('cancello.jpg', fsdJpg()), algFile('tagliata.jpg', $tagliata)]);

    // Dalla decisione 535 la rifiuta l'ingresso della richiesta (`TogliIDatiDelleFoto`), sotto il suo campo: `allegati.1`, che le pagine leggono come
    // l'errore degli allegati (`erroreAllegati`, `erroreCaricamento`: `allegati` o `allegati.*`).
    expect(algErroriAllegati($risposta))->toBe(['allegati.1'])
        ->and(session('errors')->getBag('default')->first('allegati.1'))->toContain('«tagliata.jpg»')
        ->and(algRighe($this->segnalazione))->toHaveCount(0)
        ->and(app(ArchivioDocumenti::class)->disco()->allFiles('documenti/allegati'))->toBe([]);
});

it('controprova: un PDF resta byte per byte com\'è stato caricato', function () {
    algCarica($this, $this->mario, $this->segnalazione, [algPdf('preventivo.pdf')])->assertSessionHasNoErrors();

    $riga = algRighe($this->segnalazione)->sole();
    expect((string) app(ArchivioDocumenti::class)->disco()->get($riga->path))->toBe(ALG_PDF);
});
