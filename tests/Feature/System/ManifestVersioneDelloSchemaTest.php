<?php

/**
 * Il manifest deve dichiarare la versione dello **schema che contiene**, non quella dei file.
 *
 * ## Il caso, misurato il 27/08/2026 sull'artefatto vero
 *
 * Il backup di sicurezza pre-aggiornamento gira quando i file della versione nuova hanno già
 * sostituito i vecchi, ma le migrazioni non sono ancora partite. Con `config('app.version')` il
 * manifest si timbrava `1.11.0-beta.2` su un archivio che conteneva 158 migrazioni, cioè uno
 * schema `1.10.0`.
 *
 * La conseguenza non è estetica. `RestorePreflight` rifiuta ogni archivio «proveniente da una
 * versione più recente» di quella installata. L'amministratore a cui l'aggiornamento va male
 * rimette i file `1.10.0` via FTP — la reazione naturale, ed è l'unico scenario per cui quel
 * backup esiste — e il prodotto **rifiuta la propria rete di sicurezza**. Verificato eseguendolo:
 * con `current_version = 1.10.0` l'archivio veniva respinto.
 *
 * ## Perché non si è alzato `manifest_format`
 *
 * Nello scenario del rollback l'archivio viene letto dal codice **vecchio**. Un campo nuovo quello
 * non lo guarda, e `SUPPORTED_MANIFEST_FORMATS` è una lista chiusa: un formato `2` verrebbe
 * rifiutato *prima* di leggere la versione, peggiorando esattamente il caso da curare. La
 * correzione cambia quindi il significato di `app.version` — che per un backup è sempre stato
 * «cosa c'è dentro», non «chi l'ha prodotto» — e aggiunge `app.files_version` come informazione
 * additiva, che i lettori precedenti ignorano.
 *
 * ## Cosa questo test NON copre
 *
 * - **Non costruisce un archivio vero.** Esercita `ManifestBuilder` e `RestorePreflight`, cioè chi
 *   scrive il campo e chi lo legge. Che i due si incontrino dentro uno zip è coperto da
 *   `MySqlDumperRoundTripTest` e dai test del ripristino.
 * - **Non prova il comportamento del codice 1.10.0.** Assume che legga `app.version` come fa
 *   quello attuale, cioè `RestorePreflight:59`.
 * - **Non copre il secondo lettore del campo.** *(La prima stesura di questo blocco diceva che
 *   `RestorePreflight` era «l'unico lettore in tutto il progetto». Era falso, e l'ha trovato la
 *   revisione avversariale.)* Lo legge anche `RestoreResult.vue:24`, che lo mostra all'utente a
 *   ripristino concluso — «ripristinato dalla versione X». Lì il cambiamento **migliora** il
 *   messaggio, perché dopo un backup pre-aggiornamento la schermata dirà la versione dello schema
 *   davvero rimesso dentro invece di quella dei file che l'avevano prodotto. Ma è un lettore, e
 *   questo test non lo esercita.
 * - **Non copre il ripiego** su un'installazione dove le impostazioni non sono leggibili: lì le
 *   due versioni coincidono comunque, e non c'è niente da distinguere.
 */

use App\Models\Backup;
use App\Services\Backup\ManifestBuilder;
use App\Services\Restore\Exceptions\IncompatibleBackupException;
use App\Services\Restore\RestorePreflight;
use App\Settings\GeneralSettings;

function manifestConVersioni(string $versioneRegistrata, string $versioneDeiFile): array
{
    config(['app.version' => $versioneDeiFile]);

    $impostazioni = app(GeneralSettings::class);
    $impostazioni->version = $versioneRegistrata;

    $backup = new Backup(['uuid' => 'prova', 'type' => Backup::TYPE_DB_ONLY, 'encrypted' => false]);

    return app(ManifestBuilder::class)->build($backup, 'database.sql', null, [], ['count' => 0, 'bytes' => 0]);
}

it('dichiara la versione dello schema, non quella dei file, quando le due divergono', function () {
    // È lo stato esatto del backup di sicurezza: file già nuovi, database ancora vecchio.
    $manifest = manifestConVersioni('1.10.0', '1.11.0-beta.2');

    expect($manifest['app']['version'])->toBe('1.10.0')
        ->and($manifest['app']['files_version'])->toBe('1.11.0-beta.2');
});

it('non cambia niente nel caso normale, dove le due versioni coincidono', function () {
    $manifest = manifestConVersioni('1.11.0-beta.2', '1.11.0-beta.2');

    expect($manifest['app']['version'])->toBe('1.11.0-beta.2')
        ->and($manifest['app']['files_version'])->toBe('1.11.0-beta.2');
});

it('lascia il formato del manifest invariato, così i lettori precedenti accettano l archivio', function () {
    // Alzarlo romperebbe proprio lo scenario del rollback, dove a leggere è il codice vecchio.
    expect(manifestConVersioni('1.10.0', '1.11.0-beta.2')['manifest_format'])->toBe(1);
});

it('il ripristino accetta il backup di sicurezza dopo che i file sono tornati indietro', function () {
    $manifest = manifestConVersioni('1.10.0', '1.11.0-beta.2');

    // L'amministratore ha rimesso i file 1.10.0 e prova a ripristinare.
    config(['app.version' => '1.10.0']);

    $esito = app(RestorePreflight::class)->inspect($manifest, 1024);

    expect($esito['source_version'])->toBe('1.10.0')
        ->and($esito['current_version'])->toBe('1.10.0');
});

it('e continua a rifiutare un backup che viene davvero da una versione più recente', function () {
    // La guardia non deve essere stata smontata: un archivio di una 1.12 su codice 1.11 va respinto.
    $manifest = manifestConVersioni('1.12.0', '1.12.0');

    config(['app.version' => '1.11.0-beta.2']);

    expect(fn () => app(RestorePreflight::class)->inspect($manifest, 1024))
        ->toThrow(IncompatibleBackupException::class);
});
