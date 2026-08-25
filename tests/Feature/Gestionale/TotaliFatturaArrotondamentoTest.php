<?php

use App\Enums\Fiscale\NaturaPercipiente;
use App\Enums\Fiscale\TipoRitenuta;
use App\Services\Gestionale\FatturaPassivaService;

require_once __DIR__.'/GestionaleTestHelpers.php';

/**
 * Riferimento degli arrotondamenti sui totali di una fattura passiva.
 *
 * Nasce da una segnalazione dal forum: registrando una fattura con ritenuta d'acconto, il
 * form mostrava «netto da pagare 373,12» e l'elenco fatture, dopo il salvataggio, 373,11.
 * Il difetto era nell'anteprima lato client, che sommava float invece di centesimi interi;
 * il backend era già corretto.
 *
 * Questi test non coprono quel difetto — lo copre `resources/js/lib/gestionale/fatture/
 * totali.test.ts`. Coprono l'altro lato del patto: **fissano i numeri che l'anteprima
 * ricalca**. Se un domani qualcuno cambia l'arrotondamento qui, questi test si accendono e
 * ricordano che c'è un secondo calcolo, in un altro linguaggio, che deve seguirlo.
 */
it('il caso del forum: 316,20 + IVA 22% − ritenuta 4% fa 373,11, non 373,12', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $fornitore->update([
        'soggetto_ritenuta' => true,
        'tipo_ritenuta' => TipoRitenuta::APPALTO_4,
        'natura_percipiente' => NaturaPercipiente::PERSONA_FISICA_IRPEF,
    ]);

    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Appalto manutenzione',
                'importo_imponibile' => 316.20,
                'aliquota_iva' => 22,
                'conto_id' => $capitolo->id,
                'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    // I tre componenti che l'amministratore legge sopra il totale…
    expect((int) $fattura->importo_imponibile)->toBe(31620)
        ->and((int) $fattura->importo_iva)->toBe(6956)     // round(31620 × 22/100) = round(6956,4)
        ->and((int) $fattura->importo_ritenuta)->toBe(1265) // round(31620 ×  4/100) = round(1264,8)
        // …e il netto, che deve quadrare con quelli e non con i grezzi 69,564 e 12,648.
        ->and((int) $fattura->netto_a_pagare)->toBe(37311);
});

it('l\'IVA si arrotonda riga per riga, non sul totale del documento', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();

    // 1,15 × 22% = 25,3 centesimi per riga → 25 + 25 = 50.
    // Arrotondando invece la somma dei grezzi (0,506 €) verrebbero 51.
    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [
                ['descrizione' => 'Riga A', 'importo_imponibile' => 1.15, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
                ['descrizione' => 'Riga B', 'importo_imponibile' => 1.15, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
            ],
        ]),
        $condominio->id
    );

    expect((int) $fattura->importo_iva)->toBe(50)
        ->and((int) $fattura->totale_documento)->toBe(280);
});

it('una base imponibile configurata a zero non produce ritenuta', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $fornitore->update([
        'soggetto_ritenuta' => true,
        'perc_ritenuta' => 4,
        'perc_imponibile_ritenuta' => 0,
    ]);

    // `(float) ($fornitore->perc_imponibile_ritenuta ?? 100)`: lo zero è un valore, non un vuoto.
    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Prestazione con base azzerata',
                'importo_imponibile' => 1000,
                'aliquota_iva' => 22,
                'conto_id' => $capitolo->id,
                'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    expect((int) $fattura->importo_ritenuta)->toBe(0)
        ->and((int) $fattura->netto_a_pagare)->toBe(122000);
});

it('la ritenuta a base ridotta arrotonda due volte: prima la base, poi la trattenuta', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $fornitore->update([
        'soggetto_ritenuta' => true,
        'tipo_ritenuta' => TipoRitenuta::PROVVIGIONI_BASE_50,
        'natura_percipiente' => NaturaPercipiente::PERSONA_FISICA_IRPEF,
    ]);

    // round(100013 × 50/100) = 50007  →  round(50007 × 23/100) = 11502.
    // In un passo solo — round(100013 × 0,115) — verrebbe 11501.
    $fattura = (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Provvigioni con riduzione',
                'importo_imponibile' => 1000.13,
                'aliquota_iva' => 22,
                'conto_id' => $capitolo->id,
                'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    expect((int) $fattura->importo_ritenuta)->toBe(11502)
        ->and((int) $fattura->importo_iva)->toBe(22003)
        ->and((int) $fattura->netto_a_pagare)->toBe(100013 + 22003 - 11502);
});
