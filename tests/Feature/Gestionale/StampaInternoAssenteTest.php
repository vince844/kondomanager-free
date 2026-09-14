<?php

/**
 * Nelle stampe di riparto, un'unità senza interno mostra il nome e nessuna riga «int.»: né una
 * cella vuota, né un «int. » stampato a vuoto.
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
 * `??` e `isset` **non scattano** su stringa vuota, `?:` e il ternario di verità sì. La stampa per
 * tabelle usava `?:`, quella per capitoli `??`: le due si sarebbero comportate in modo diverso
 * davanti alla stessa unità — una cella vuota in una tabella di cento righe sembra un dato
 * mancante, non un difetto, e a occhio non si nota.
 *
 * Dalla 1.11.0-beta.27 la riga che decide è `$dettagliUnita` in entrambi i template
 * (`riparto_tabelle`, `riparto_capitoli`): un ternario di verità sull'interno, per cui la stringa
 * vuota non produce nulla; `??` o `isset` produrrebbero «int. » a vuoto. Il ramo `@else` con
 * `?: '—'` resta come rete per dati fuori dal modello ed è irraggiungibile con un'unità salvata:
 * `nome_immobile` ripiega su `codice_immobile`, che è NOT NULL e generato dal modello.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre l'impaginazione né i totali: rende il template vero con una riga sola. Il resto della
 * stampa è coperto dai test del riparto.
 */

/**
 * Rende il template per tabelle con una sola unità. Nome proprio, non `rendiStampa`: le funzioni
 * dei test di Pest sono globali e una collisione con `StampaRipartoLeggibilitaTest` rompe la suite.
 */
function stampaTabelleConUnita(string $nome, string $interno, string $piano): string
{
    $righe = [1 => [
        'codice_immobile' => 'C1', 'interno' => $interno, 'piano' => $piano, 'nome_immobile' => $nome,
        'soggetti' => [11 => ['nome' => 'Soggetto 1', 'ruolo' => 'P', 'ruolo_raw' => 'proprietario', 'quota_sogg' => 100, 'per_tabella' => [1 => ['quota' => 500.0, 'importo' => 5000]], 'totale' => 5000]],
        'totale_immobile' => 5000,
    ]];
    $matrice = [
        'tabelle' => [1 => ['nome' => 'Colonna 1', 'quota_label' => 'mill. ‰', 'quota_tipo' => 'millesimi', 'decimali' => 2, 'tot_quota' => 1000.0, 'tot_importo' => 10000]],
        'righe' => $righe, 'gran_totale' => 5000, 'tot_per_tabella' => [1 => 5000], 'tot_per_capitolo' => [1 => 5000], 'tot_quota_per_tabella' => [1 => 1000.0],
    ];

    return view('pdf.gestionale.riparto_tabelle', [
        'condominio' => new \App\Models\Condominio(['nome' => 'Condominio di prova', 'indirizzo' => 'Via di prova 1', 'codice_fiscale' => '91000000009']),
        'esercizio'  => new \App\Models\Esercizio(['nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']),
        'pianoRate'  => new \App\Models\Gestionale\PianoRate(['nome' => 'Piano rate 2026', 'stato' => 'approvato']),
        'matrice' => $matrice, 'nTabelle' => 1, 'nota_legale_stampe' => '', 'firma_stampe_absolute_path' => null,
    ])->render();
}

it('nella cella dell\'unità il nome sta in testa e la riga «int. · piano» c\'è solo quando c\'è qualcosa da scrivere', function () {
    // Il caso «nome e interno vuoti → trattino» non si rende: con un'unità salvata non può darsi.
    expect(stampaTabelleConUnita('Posto auto 3', '', ''))->toContain('Posto auto 3')->not->toContain('int. ');
    expect(stampaTabelleConUnita('Interno 4', '4B', 'Primo piano'))->toContain('int. 4B · Primo piano');
    expect(stampaTabelleConUnita('Interno 5', '5', ''))->toContain('int. 5')->not->toContain(' · ');
});

it('la riga del template è davvero quella provata: se tornano `??` o `isset` sull\'interno questo test se ne accorge', function () {
    // ⚠️ Il test qui sopra rende il template per tabelle. Questa verifica guarda entrambi i file,
    // e la riga che decide — `$dettagliUnita` — non il ramo di riserva: una guardia sul solo
    // `?: '—'` resterebbe verde anche con `isset(...) ? 'int. '…` nella riga vera, che stamperebbe
    // «int. » a vuoto per ogni box. È esattamente la regressione che questo file esiste per impedire.
    foreach (['riparto_capitoli', 'riparto_tabelle'] as $vista) {
        $template = file_get_contents(resource_path("views/pdf/gestionale/{$vista}.blade.php"));

        expect($template)->toContain("!empty(\$rigaImmobile['nome_immobile'])")
            ->and($template)->toContain("\$rigaImmobile['interno'] ? 'int. '.\$rigaImmobile['interno'] : null")
            ->and($template)->not->toContain("\$rigaImmobile['interno'] ?? ")
            ->and($template)->not->toContain("isset(\$rigaImmobile['interno'])");
    }
});
