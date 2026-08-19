<?php

/**
 * Il nome di un Comune si mostra come è, non «migliorato».
 *
 * ## Origine: revisione avversariale della beta.59
 *
 * La beta.59 aggiunge un pulsante che pesca il nome del Comune dall'elenco ISTAT e lo scrive nel
 * campo. Poi `CondominioResource` lo rimandava a video passandolo per `Str::title()`, che spezza le
 * preposizioni e le particelle:
 *
 * | ISTAT scrive | `Str::title()` restituiva |
 * | :--- | :--- |
 * | Reggio nell'Emilia | Reggio Nell'emilia |
 * | L'Aquila | L'aquila |
 * | Aci Sant'Antonio | Aci Sant'antonio |
 *
 * Sono **1.145 nomi su 7.894** — misurati sul file spedito. E il giro si chiude: la schermata di
 * modifica riceve il nome storpiato, lo mette nel campo e al primo salvataggio lo riscrive così a
 * database. Il programma fornisce il nome autorevole e poi lo distrugge da solo.
 *
 * È anche una violazione della regola di casa sulle maiuscole: si mettono a inizio frase, non a
 * ogni parola — il Title Case è una convenzione inglese.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre gli altri campi della risorsa: `codice_fiscale`, `provincia` e i codici catastali
 * restano in maiuscolo, ed è corretto — sono codici, non nomi propri.
 */

use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;

/** I nomi che `Str::title()` storpiava, presi dalla fonte ISTAT. */
dataset('comuni con le particelle', [
    ['Reggio nell\'Emilia'],
    ['L\'Aquila'],
    ['Aci Sant\'Antonio'],
    ['Cava de\' Tirreni'],
    ['San Giovanni in Persiceto'],
]);

it('il comune catastale esce dalla risorsa come sta a database', function (string $nome) {
    $risorsa = (new CondominioResource(new Condominio(['comune_catasto' => $nome])))
        ->toArray(request());

    expect($risorsa['comune_catasto'])->toBe($nome);
})->with('comuni con le particelle');

it('anche il comune dell\'indirizzo, che è lo stesso giro sullo stesso modulo', function (string $nome) {
    // ⚠️ Sta nello stesso modulo, arriva dalla stessa risorsa e torna indietro con lo stesso
    // salvataggio: correggere solo il campo catastale avrebbe lasciato il difetto vivo accanto,
    // con l'aria di averlo risolto.
    $risorsa = (new CondominioResource(new Condominio(['comune' => $nome])))
        ->toArray(request());

    expect($risorsa['comune'])->toBe($nome);
})->with('comuni con le particelle');

it('i codici restano in maiuscolo, perché sono codici e non nomi', function () {
    $risorsa = (new CondominioResource(new Condominio([
        'codice_catasto' => 'h501',
        'provincia'      => 'rm',
    ])))->toArray(request());

    expect($risorsa['codice_catasto'])->toBe('H501')
        ->and($risorsa['provincia'])->toBe('RM');
});
