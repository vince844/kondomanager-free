<?php

/**
 * `storage/app` è su un volume persistente, o dentro il contenitore?
 *
 * ## Il problema, posto da Vincenzo il 18/08/2026
 *
 * *«Mettiamo anche nel Dockerfile la cartella storage? altrimenti ogni volta che facciamo il rebuild
 * cancelliamo i documenti… su Coolify posso inserirla manualmente però mi salvo un passaggio che
 * potrei dimenticare.»*
 *
 * ## Perché la risposta non è una riga nel Dockerfile
 *
 * `VOLUME /var/www/storage/app` **non rende persistente niente**: dichiara un punto di innesto, e se
 * nessuno mappa un volume vero Docker ne crea uno **anonimo**, diverso a ogni contenitore. Alla
 * ricreazione i file precedenti restano in un volume che nessuno riaggancia — spariti, ai fini
 * pratici. In più impedisce ai livelli successivi dell'immagine di scrivere in quel percorso.
 *
 * Il volume va dichiarato **dove il contenitore viene eseguito**: in `docker-compose.yml` come volume
 * con un nome, o nel pannello di Coolify. È un passaggio manuale, ed è esattamente quello che si può
 * dimenticare — per questo il programma deve saperlo dire.
 *
 * ## Come si riconosce, senza indovinare
 *
 * Un volume montato è un **filesystem diverso** da quello del contenitore: `stat()` restituisce un
 * numero di dispositivo diverso da quello della radice. Se coincidono, la cartella vive nel livello
 * scrivibile del contenitore e sparisce alla sua ricreazione.
 */

use App\Support\PersistenzaStorage;

it('riconosce che una cartella qualsiasi del progetto NON è un volume separato', function () {
    // In sviluppo il progetto è tutto sullo stesso filesystem: è il caso «non persistente», e serve
    // come controprova che il rilevamento non risponda «sì» a tutto.
    expect(PersistenzaStorage::suVolumeSeparato(base_path('storage/app')))->toBeFalse();
});

it('il confronto è con la radice del contenitore, non con la cartella del progetto', function () {
    // ⚠️ Reperto della Fase 1-bis. La prima stesura confrontava `storage/app` con `base_path()`:
    // chi monta **l'intera cartella dell'applicazione** — configurazione comune su Coolify e in
    // qualunque compose con un bind mount — si sentiva dire «✗ NON è persistente… spariscono 412
    // file per 180 MB», che è falso: i suoi file sono al sicuro. E con l'uscita 1 nel comando
    // post-deploy avrebbe fatto risultare fallito un deploy perfettamente riuscito.
    //
    // Il confronto giusto è con **la radice del filesystem del contenitore**: se `storage/app` sta
    // su un dispositivo diverso da `/`, è su un volume — che sia suo o di una cartella superiore.
    $riflessione = new ReflectionMethod(PersistenzaStorage::class, 'dispositivoDiConfronto');
    $riflessione->setAccessible(true);

    expect($riflessione->invoke(null))->toBe(@stat('/')['dev'] ?? null);
});

it('una cartella inesistente non viene scambiata per persistente', function () {
    expect(PersistenzaStorage::suVolumeSeparato(base_path('storage/questa-non-esiste')))->toBeFalse();
});

it('il verdetto dice cosa si perde, non solo sì o no', function () {
    $esito = PersistenzaStorage::verdetto();

    expect($esito)->toHaveKeys(['persistente', 'percorso', 'cartelle'])
        ->and($esito['cartelle'])->toHaveKeys(['private', 'backups', 'public'])
        ->and($esito['percorso'])->toContain('storage/app');
});

it('conta quanto c\'è da perdere: un avviso senza numeri non convince nessuno', function () {
    $esito = PersistenzaStorage::verdetto();

    expect($esito['cartelle']['private'])->toHaveKeys(['file', 'byte']);
});
