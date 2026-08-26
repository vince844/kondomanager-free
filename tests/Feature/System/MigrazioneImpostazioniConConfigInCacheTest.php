<?php

/**
 * La migrazione delle impostazioni deve sopravvivere alla configurazione in cache di *prima*.
 *
 * ## Il caso, misurato
 *
 * `config/pagination.php` nasce nella 1.10. Chi aggiorna da una versione precedente che aveva
 * ricevuto un `php artisan config:cache` si porta dietro `bootstrap/cache/config.php` della vecchia
 * — dove quella chiave non esiste. `config('pagination.consentite')` restituisce quindi `null`, e
 * `in_array($ago, null, true)` in PHP 8 non è un avviso: è un **TypeError fatale**. `migrate` si
 * ferma su questa migrazione e lascia il database a metà — misurato in laboratorio riproducendo
 * l'aggiornamento 1.9.1 → 1.10.0: **dieci migrazioni pendenti**.
 *
 * Il difetto stava nella guardia, non nel valore: il ripiego a 10 già c'era e difendeva dal caso
 * `DEFAULT_PER_PAGE=` vuoto nel `.env`. Non difendeva dal caso in cui il *file di configurazione*
 * non si vedesse ancora.
 *
 * ## Cosa questo test NON copre
 *
 * - **Non esegue la migrazione**: esercita il solo metodo che decide il valore, per riflessione.
 *   Che `up()` sia rieseguibile è protetto dalla guardia `migrator->exists()` e dal dataset di
 *   `UpgradeMigrationsRerunTest`, non da qui.
 * - **Non riproduce una cache di configurazione vera.** Simula il suo effetto — la chiave assente —
 *   che è l'unica cosa che il codice può osservare. Se un giorno il difetto tornasse per una via
 *   diversa dalla chiave mancante (per esempio una chiave presente ma di tipo sbagliato), il primo
 *   caso qui sotto non lo prenderebbe: per quello c'è il terzo.
 * - **Non protegge le altre migrazioni di `database/settings/`.** Protegge questa. La forma
 *   generale — `config()` usato come haystack senza ripiego — resta da presidiare altrove.
 */

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/** Il metodo privato che decide il valore iniziale, raggiunto per riflessione. */
function valoreInizialeDellaMigrazione(): int
{
    $migrazione = require database_path(
        'settings/2026_08_16_090000_add_default_per_page_to_general_settings.php'
    );

    expect($migrazione)->toBeInstanceOf(SettingsMigration::class);

    $metodo = new ReflectionMethod($migrazione, 'valoreIniziale');
    $metodo->setAccessible(true);

    return $metodo->invoke($migrazione);
}

it('non muore quando la configurazione in cache non conosce ancora le righe consentite', function () {
    // Lo stato esatto di chi aggiorna da una 1.9.x con `config:cache` fatto: il file
    // config/pagination.php esiste su disco, ma la cache è quella di prima e la chiave
    // non c'è affatto. Si svuota l'intero namespace per riprodurre l'assenza, non il null.
    config()->set('pagination', []);

    expect(valoreInizialeDellaMigrazione())->toBe(10);
});

it('non muore nemmeno se la chiave esiste ma vale null', function () {
    // Stato diverso dal precedente, e il secondo argomento di `config()` NON lo copre:
    // restituisce il ripiego solo quando la chiave manca. Una chiave presente e nulla lo
    // scavalca e arriva intatta a `in_array()`, che in PHP 8 è un fatale.
    // Questo caso ha fatto fallire la prima stesura della correzione.
    config(['pagination.consentite' => null, 'pagination.default_per_page' => null]);

    expect(valoreInizialeDellaMigrazione())->toBe(10);
});

it('conserva il valore configurato quando la configurazione si vede', function () {
    config(['pagination.consentite' => [10, 25, 50], 'pagination.default_per_page' => 25]);

    expect(valoreInizialeDellaMigrazione())->toBe(25);
});

it('ripiega a 10 su un valore configurato che non è fra quelli consentiti', function () {
    config(['pagination.consentite' => [10, 25, 50], 'pagination.default_per_page' => 999]);

    expect(valoreInizialeDellaMigrazione())->toBe(10);
});

it('ripiega a 10 sul DEFAULT_PER_PAGE lasciato vuoto nel .env', function () {
    // Il caso che il ripiego difendeva già prima di questa correzione: stringa vuota,
    // quindi 0 una volta castata, quindi paginate(0) e divisione per zero nel paginatore.
    config(['pagination.consentite' => [10, 25, 50], 'pagination.default_per_page' => '']);

    expect(valoreInizialeDellaMigrazione())->toBe(10);
});
