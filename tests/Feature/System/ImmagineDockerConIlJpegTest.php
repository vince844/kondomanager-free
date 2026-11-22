<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Le immagini JPG nell'installazione Docker (Coda 677)
 *
 * Nei sorgenti di PHP il JPEG di GD è spento di serie: senza la libreria JPEG e senza `docker-php-ext-configure gd --with-jpeg`, l'immagine installa `gd`
 * che non legge i JPG. `ImmagineDiStampa` riscrive il logo e la firma dello studio con `imagecreatefromstring`, che su un JPG fallisce: la firma non si
 * carica, e l'avviso di convocazione, che la stampa, esce senza.
 *
 * Questa è la prova **statica** dei due Dockerfile che compilano `gd` (quello della radice, che `.github/workflows/immagine.yml` costruisce e pubblica, e
 * quello dello stack di sviluppo): la libreria JPEG di sviluppo per la compilazione, il `configure` con il JPEG prima dell'installazione di `gd`, la
 * libreria di runtime che resta nell'immagine finale; e nel collaudo dell'immagine la riga che si ferma se GD non ha il JPEG. Il PNG non chiede niente:
 * con la GD dei sorgenti di PHP è sempre acceso, con `libpng-dev` che c'era già.
 *
 * Che cosa non copre: l'immagine costruita. La prova vera è `php -r 'var_dump(gd_info()["JPEG Support"]);'` sull'immagine pubblicata, alla pubblicazione.
 * L'immagine FrankenPHP compila già `gd` con il JPEG, e non si tocca.
 */
function ijDockerfile(string $percorso): string
{
    // Le righe continuate con la barra rovescia diventano una: le istruzioni si leggono intere.
    return preg_replace('/\\\\\n\s*/', ' ', (string) file_get_contents(base_path($percorso)));
}

/** L'istruzione RUN che installa `gd`. */
function ijRunDiGd(string $dockerfile): string
{
    preg_match_all('/^RUN .*$/m', $dockerfile, $run);
    $conGd = array_values(array_filter($run[0], fn (string $r) => preg_match('/docker-php-ext-install\b[^&]*\bgd\b/', $r) === 1));
    expect($conGd)->toHaveCount(1);

    return $conGd[0];
}

it('Coda 677 — il Dockerfile compila gd con il JPEG: la libreria di sviluppo, poi il configure, poi l\'installazione', function (string $percorso) {
    $run = ijRunDiGd(ijDockerfile($percorso));

    expect($run)->toMatch('/apt-get install[^&]*\blibjpeg62-turbo-dev\b/')
        ->and($run)->toContain('docker-php-ext-configure gd --with-jpeg')
        ->and(strpos($run, 'docker-php-ext-configure gd --with-jpeg'))->toBeLessThan(strpos($run, 'docker-php-ext-install'))
        // Il PNG resta com'era: la libreria c'è, e nessuna opzione lo spegne.
        ->and($run)->toMatch('/apt-get install[^&]*\blibpng-dev\b/');
})->with([
    'la radice' => 'Dockerfile',
    'lo stack di sviluppo' => 'docker/standard/Dockerfile',
]);

it('Coda 677 — nell\'immagine finale della radice la libreria JPEG di runtime resta, anche se i pacchetti di sviluppo se ne vanno', function () {
    $run = ijRunDiGd(ijDockerfile('Dockerfile'));

    // I -dev se ne vanno con `--auto-remove`: la libreria di runtime resta perché è segnata a mano, per nome, prima della pulizia.
    expect($run)->toContain('apt-get purge -y --auto-remove')
        ->and($run)->toMatch('/apt-mark manual [^&]*\blibjpeg62-turbo\b(?!-dev)/')
        ->and(strpos($run, 'libjpeg62-turbo '))->toBeLessThan(strpos($run, 'apt-get purge'));
});

it('Coda 677 — il collaudo dell\'immagine si ferma se GD non legge i JPG', function () {
    $collaudo = (string) file_get_contents(base_path('.github/workflows/immagine.yml'));

    expect($collaudo)->toContain('var_dump(gd_info()["JPEG Support"]);')
        ->and($collaudo)->toContain('exit(gd_info()["JPEG Support"] ? 0 : 1);');
    // Prima del push: il collaudo viene prima della pubblicazione.
    expect(strpos($collaudo, 'gd_info()'))->toBeLessThan(strpos($collaudo, '- name: Push'));
});
