<?php

/**
 * Una nota di credito riaperta in modifica e risalvata senza toccare niente.
 *
 * L'ipotesi da verificare: la registrazione salva gli importi di una NC **già negativi**
 * (`FatturaPassivaService` applica `$moltiplicatore = -1` sia alle righe sia alla testata),
 * mentre `aggiornaFattura()` si aspetta in ingresso importi **positivi** e riapplica il
 * moltiplicatore per conto suo. Se il form di modifica ripresenta al server i negativi che
 * ha letto dal database, il segno si ribalta: −1.000,00 € tornano +1.000,00 €.
 *
 * Il test lavora sul **contratto del service**, non sul form: è il service a decidere il
 * segno, ed è lì che l'invariante deve valere. Il lato TypeScript è fissato a parte.
 *
 * Perché conta più di un errore di visualizzazione: una NC che torna positiva smette di
 * essere un accredito e diventa un costo, quindi il debito verso il fornitore cresce
 * invece di calare. E la scrittura contabile resta **quadrata**, perché il filtro
 * invertitore DARE/AVERE si applica comunque — quindi nessun validatore se ne accorge.
 * È lo stesso schema della beta.36: un difetto simmetrico è invisibile alla partita doppia.
 */

use App\Enums\StatoPagamentoFattura;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

/**
 * Registra una NC da 1.000,00 € + IVA 22% e restituisce [nc, payload di modifica].
 *
 * Il payload è quello che un form di modifica corretto deve mandare: importi **positivi**,
 * cioè le stesse cifre digitate alla registrazione. Se il difetto è nel frontend, questo
 * test resta verde e a fallire è quello TypeScript; se invece il service stesso non regge
 * il giro, fallisce qui.
 */
function scenarioNotaCredito(): array
{
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    $righe = [[
        'descrizione' => 'Storno pulizie',
        'importo_imponibile' => 1000,
        'aliquota_iva' => 22,
        'conto_id' => $capitolo->id,
        'is_sopravvenienza' => false,
    ]];

    $nc = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'tipo_documento' => 'nota_credito',
            'applica_ritenuta' => false,
            'righe' => $righe,
        ]),
        $condominio->id
    );

    $payload = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'tipo_documento' => 'nota_credito',
        'applica_ritenuta' => false,
        'numero_documento' => $nc->numero_documento,
        'righe' => $righe,
    ]);

    return [$nc, $payload, $ctx];
}

// ════════════════════════════════════════════════════════════════════════════
// Il punto di partenza: com'è fatta a database una NC appena registrata
// ════════════════════════════════════════════════════════════════════════════

test('una nota di credito nasce con testata e righe negative', function () {
    [$nc] = scenarioNotaCredito();

    expect($nc->importo_imponibile)->toEqual(-100000)
        ->and($nc->importo_iva)->toEqual(-22000)
        ->and($nc->totale_documento)->toEqual(-122000)
        ->and($nc->netto_a_pagare)->toEqual(-122000);

    $riga = DB::table('righe_fattura')->where('fattura_passiva_id', $nc->id)->first();

    expect((int) $riga->importo_imponibile)->toEqual(-100000)
        ->and((int) $riga->importo_iva)->toEqual(-22000);
});

test('la nota di credito è davvero modificabile: nessuna guardia la ferma', function () {
    [$nc] = scenarioNotaCredito();

    // Se questa asserzione cadesse, il difetto sarebbe irraggiungibile e l'ipotesi
    // non reggerebbe: vale la pena che il test lo dica esplicitamente.
    expect($nc->stato_pagamento)->toEqual(StatoPagamentoFattura::APERTA);
    expect((new FatturaPassivaService)->motivoBloccoModifica($nc))->toBeNull();
});

// ════════════════════════════════════════════════════════════════════════════
// Il giro: apro, non tocco niente, salvo
// ════════════════════════════════════════════════════════════════════════════

test('risalvare senza modifiche lascia la testata negativa', function () {
    [$nc, $payload] = scenarioNotaCredito();

    (new FatturaPassivaService)->aggiornaFattura($nc, $payload);

    $fresh = $nc->fresh();

    expect($fresh->importo_imponibile)->toEqual(-100000)
        ->and($fresh->importo_iva)->toEqual(-22000)
        ->and($fresh->totale_documento)->toEqual(-122000)
        ->and($fresh->netto_a_pagare)->toEqual(-122000);
});

test('risalvare senza modifiche lascia le righe negative', function () {
    [$nc, $payload] = scenarioNotaCredito();

    (new FatturaPassivaService)->aggiornaFattura($nc, $payload);

    $riga = DB::table('righe_fattura')->where('fattura_passiva_id', $nc->id)->first();

    expect((int) $riga->importo_imponibile)->toEqual(-100000)
        ->and((int) $riga->importo_iva)->toEqual(-22000);
});

test('due giri di apri-e-salva non fanno oscillare il segno', function () {
    [$nc, $payload] = scenarioNotaCredito();

    foreach ([1, 2] as $giro) {
        (new FatturaPassivaService)->aggiornaFattura($nc->fresh(), $payload);

        expect($nc->fresh()->totale_documento)
            ->toEqual(-122000, "Il segno è cambiato al giro {$giro}.");
    }
});

// ════════════════════════════════════════════════════════════════════════════
// Ciò che la quadratura non vedrebbe
// ════════════════════════════════════════════════════════════════════════════

test('dopo la modifica il costo resta in AVERE e il fornitore in DARE', function () {
    [$nc, $payload] = scenarioNotaCredito();

    (new FatturaPassivaService)->aggiornaFattura($nc, $payload);

    $scritturaId = $nc->fresh()->scritture->first()->id;

    // La scrittura quadra anche col segno ribaltato: il filtro invertitore si applica
    // comunque. Serve guardare da che parte stanno i conti, non che i totali coincidano.
    assertQuadraturaPerfetta($scritturaId);

    expect(
        DB::table('righe_scritture')->where('scrittura_id', $scritturaId)
            ->where('tipo_riga', 'avere')->where('importo', 122000)->exists()
    )->toBeTrue('Su una nota di credito il costo deve stare in AVERE.');

    expect(
        DB::table('righe_scritture')->where('scrittura_id', $scritturaId)
            ->where('tipo_riga', 'dare')->where('importo', 122000)->exists()
    )->toBeTrue('Su una nota di credito il fornitore deve stare in DARE.');
});

// ════════════════════════════════════════════════════════════════════════════
// Il controllo gemello: una fattura ordinaria non deve cambiare comportamento
// ════════════════════════════════════════════════════════════════════════════

test('una fattura ordinaria resta positiva dopo lo stesso giro', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    $righe = [[
        'descrizione' => 'Pulizie',
        'importo_imponibile' => 1000,
        'aliquota_iva' => 22,
        'conto_id' => $capitolo->id,
        'is_sopravvenienza' => false,
    ]];

    $dati = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'applica_ritenuta' => false,
        'righe' => $righe,
    ]);

    $fattura = (new FatturaPassivaService)->registraFattura($dati, $condominio->id);

    expect($fattura->totale_documento)->toEqual(122000);

    (new FatturaPassivaService)->aggiornaFattura($fattura, $dati);

    expect($fattura->fresh()->totale_documento)->toEqual(122000)
        ->and($fattura->fresh()->importo_imponibile)->toEqual(100000);
});
