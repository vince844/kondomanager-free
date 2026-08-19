<?php

/**
 * Nelle stampe di riparto, un'unità senza interno mostra un trattino e non una cella vuota.
 *
 * ## Perché questo file esiste
 *
 * La beta.58 rende l'interno facoltativo, su segnalazione dal forum: *«nel caso di un posto auto
 * esterno non collegato a un immobile non credo abbia senso riportare questo dato»*. Da lì in poi le
 * stampe possono ricevere un valore assente — cosa che prima non poteva succedere, perché la colonna
 * era `NOT NULL` e il modulo lo pretendeva.
 *
 * ## Il dettaglio che rende necessario un test e non uno sguardo
 *
 * `RipartoCapitoliService` e `RipartoTabelleService` normalizzano con `$immobile->interno ?? ''`,
 * quindi alle stampe l'assenza arriva come **stringa vuota**, non come `null`. La differenza conta:
 *
 * - `?? '—'` **non scatta** su stringa vuota → cella vuota;
 * - `?: '—'` scatta → trattino.
 *
 * La stampa per tabelle usava già `?:`, quella per capitoli `??`: le due si sarebbero comportate in
 * modo diverso davanti alla stessa unità. È il tipo di differenza che a occhio non si nota — una
 * cella vuota in una tabella di cento righe sembra un dato mancante, non un difetto — e che invece
 * un test riconosce sempre.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre l'impaginazione né i totali: rende il solo frammento che decide quella cella. Il resto
 * della stampa è coperto dai test del riparto.
 */

use Illuminate\Support\Facades\Blade;

/** Una riga di matrice come la costruisce `RipartoCapitoliService`, con l'interno che si vuole provare. */
function rigaImmobileConInterno(string $interno): array
{
    return [
        'interno'         => $interno,
        'piano'           => '',
        'soggetti'        => [],
        'totale_immobile' => 0,
    ];
}

it('con l\'interno assente la stampa per capitoli scrive il nome dell\'unità', function () {
    // ⚠️ Il titolo diceva «scrive il trattino» ed era rimasto indietro: dopo il reperto «alta»
    // della prima revisione la cella ripiega sul **nome dell'unità**, e il trattino resta solo
    // come ultima rete quando manca anche quello. Un test verde che descrive il comportamento
    // vecchio è la forma di documentazione falsa che questa beta corregge altrove.
    $conNome = Blade::render(
        '{{ $rigaImmobile[\'interno\'] ?: ($rigaImmobile[\'nome_immobile\'] ?? \'—\') }}',
        ['rigaImmobile' => rigaImmobileConInterno('') + ['nome_immobile' => 'Posto auto 3']],
    );

    $senzaNiente = Blade::render(
        '{{ $rigaImmobile[\'interno\'] ?: ($rigaImmobile[\'nome_immobile\'] ?? \'—\') }}',
        ['rigaImmobile' => rigaImmobileConInterno('')],
    );

    expect(trim($conNome))->toBe('Posto auto 3')
        ->and(trim($senzaNiente))->toBe('—');
});

it('con l\'interno valorizzato lo stampa così com\'è', function () {
    $reso = Blade::render(
        '{{ $rigaImmobile[\'interno\'] ?: \'—\' }}',
        ['rigaImmobile' => rigaImmobileConInterno('4B')],
    );

    expect(trim($reso))->toBe('4B');
});

it('la riga del template è davvero quella provata: se torna `??` questo test se ne accorge', function () {
    // ⚠️ I due test qui sopra provano l'espressione, non il file. Senza questa verifica resterebbero
    // verdi anche se qualcuno rimettesse `??` nel template — ed è esattamente la regressione che
    // questo file esiste per impedire.
    $template = file_get_contents(resource_path('views/pdf/gestionale/riparto_capitoli.blade.php'));

    expect($template)->toContain("\$rigaImmobile['interno'] ?: (\$rigaImmobile['nome_immobile'] ?? '—')")
        ->and($template)->not->toContain("\$rigaImmobile['interno'] ?? '—'");
});

it('anche la stampa per tabelle usa la forma giusta, ed è la ragione per cui le due concordano', function () {
    $template = file_get_contents(resource_path('views/pdf/gestionale/riparto_tabelle.blade.php'));

    expect($template)->toContain("\$rigaImmobile['interno'] ?: '—'");
});
