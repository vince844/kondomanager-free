<?php

/**
 * Lo .zip a pezzi rifiuta un nome già usato: prima sovrascriveva l'elenco e lasciava sul disco, nella cartella temporanea, il file di prima con i dati della
 * persona. Il confronto non guarda le maiuscole, come i dischi di Windows e del Mac. Il rifiuto toglie tutto: i pezzi già scritti e lo .zip.
 * I tre chiamanti di oggi (le ricevute, le lettere, i fogli del conguaglio) passano già i nomi da `InviiPerPersona::nomeLibero` e non cambiano: le loro prove
 * restano verdi.
 */

use App\Services\Gestionale\Invii\ArchivioAPezzi;
use App\Services\Gestionale\Invii\InviiPerPersona;

function aapTemporanei(): array
{
    return array_merge(glob(sys_get_temp_dir() . '/km_pezzo_*') ?: [], glob(sys_get_temp_dir() . '/km_archivio_*') ?: []);
}

it('un nome già usato si rifiuta, anche con le maiuscole diverse, e sul disco non resta niente', function () {
    $prima = aapTemporanei();
    $archivio = new ArchivioAPezzi(\RuntimeException::class);
    $archivio->aggiungi('03 Diffida 2026-10-02 1015 r482.pdf', 'la prima');
    $archivio->aggiungi('03 Diffida 2026-10-02 1015 r483.pdf', 'la seconda');

    expect(fn () => $archivio->aggiungi('03 diffida 2026-10-02 1015 R482.pdf', 'un\'altra'))
        ->toThrow(\RuntimeException::class, InviiPerPersona::ARCHIVIO_NON_FATTO);
    expect(array_values(array_diff(aapTemporanei(), $prima)))->toBe([]);
});

it('con nomi diversi lo .zip ha tutti i pezzi, e dopo lo .zip i pezzi non ci sono più', function () {
    $prima = aapTemporanei();
    $archivio = new ArchivioAPezzi(\RuntimeException::class);
    $archivio->aggiungi('a.pdf', 'uno');
    $archivio->aggiungi('b.pdf', 'due');
    $zip = $archivio->scrivi();
    $z = new ZipArchive;
    $z->open($zip);

    expect($z->numFiles)->toBe(2)->and($z->getFromName('a.pdf'))->toBe('uno')->and($z->getFromName('b.pdf'))->toBe('due');
    $z->close();
    expect(array_map('basename', array_values(array_diff(aapTemporanei(), $prima))))->toBe([basename($zip)]);
    $archivio->togli();
    expect(array_values(array_diff(aapTemporanei(), $prima)))->toBe([]);
});
