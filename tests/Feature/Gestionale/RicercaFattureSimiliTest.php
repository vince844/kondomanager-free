<?php

use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\Duplicati\RicercaFattureSimili;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Il riconoscitore di fatture simili della decisione **D4** (1.11.0-beta.13).
 *
 * ## Cosa NON copre questo file
 *
 * - **Non copre il banner**: qui si prova la regola, non come viene mostrata. Che l'avviso non
 *   blocchi il salvataggio è una proprietà dell'interfaccia e va provata dove vive l'interfaccia.
 * - **Non copre la prestazione**: il database di prova ha una manciata di fatture, e misurare un
 *   tempo su questi numeri non dice niente. Se un giorno il costo diventa una domanda, la risposta
 *   è un `EXPLAIN`, non un cronometro.
 * - **Non copre la prima nota**, che al 02/09/2026 non esiste come codice pur essendo la maschera
 *   per cui D4 è stata scritta. Quando arriverà, il ramo «(o sottoconto)» andrà provato lì.
 */

/**
 * ⚠️ `registraFatturaServiceTest($ctx)` senza override di `righe` destruttura male il contesto al
 * suo interno e finisce sul conto Fondo Riserva invece che sul capitolo di spesa — difetto
 * preesistente, già aggirato allo stesso modo dagli altri test del modulo. Il capitolo vero è
 * l'indice **4** di `setupContabile()`.
 */
function fatturaSimileTest(array $ctx, array $override = []): FatturaPassiva
{
    [, , , , $capitolo] = $ctx;

    return registraFatturaServiceTest($ctx, array_merge([
        'righe' => [[
            'descrizione' => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ], $override));
}

/** Chiama il servizio con i dati di una fattura esistente, come farebbe chi la sta riscrivendo. */
function cercaComeFosse(FatturaPassiva $f, array $override = [])
{
    $dati = array_merge([
        'condominioId' => $f->condominio_id,
        'esercizioId' => $f->esercizio_id,
        'fornitoreId' => $f->fornitore_id,
        'numeroDocumento' => $f->numero_documento,
        'totaleDocumentoCents' => (int) $f->totale_documento,
        'dataDocumento' => $f->data_documento->toDateString(),
        'tipoDocumento' => 'fattura',
        'escludiFatturaId' => null,
    ], $override);

    return (new RicercaFattureSimili())->cerca(...$dati);
}

it('livello forte: stesso fornitore e stesso numero nello stesso esercizio', function () {
    $ctx = setupContabile();
    $prima = fatturaSimileTest($ctx, ['numero_documento' => 'FT-100']);

    $simili = cercaComeFosse($prima, ['totaleDocumentoCents' => 999999, 'dataDocumento' => '2020-01-01']);

    expect($simili)->toHaveCount(1)
        ->and($simili->first()->id)->toBe($prima->id)
        ->and($simili->first()->motivo)->toBe(RicercaFattureSimili::FORTE);
});

it('livello forte: il numero si confronta senza badare a maiuscole e spazi', function () {
    $ctx = setupContabile();
    $prima = fatturaSimileTest($ctx, ['numero_documento' => 'ft-100']);

    $simili = cercaComeFosse($prima, [
        'numeroDocumento' => '  FT-100 ',
        'totaleDocumentoCents' => 999999,
        'dataDocumento' => '2020-01-01',
    ]);

    expect($simili)->toHaveCount(1)
        ->and($simili->first()->motivo)->toBe(RicercaFattureSimili::FORTE);
});

it('livello forte: un numero vuoto non segnala niente, perche il modulo e in compilazione', function () {
    $ctx = setupContabile();
    $prima = fatturaSimileTest($ctx, ['numero_documento' => 'FT-100']);

    $simili = cercaComeFosse($prima, [
        'numeroDocumento' => '   ',
        'totaleDocumentoCents' => 999999,
        'dataDocumento' => '2020-01-01',
    ]);

    expect($simili)->toHaveCount(0);
});

it('livello standard: stesso importo al centesimo e data entro sette giorni', function () {
    $ctx = setupContabile();
    $prima = fatturaSimileTest($ctx, [
        'numero_documento' => 'FT-200',
        'data_documento' => '2026-06-10',
    ]);

    $simili = cercaComeFosse($prima, [
        'numeroDocumento' => 'TUTT-ALTRO-NUMERO',
        'dataDocumento' => '2026-06-17',
    ]);

    expect($simili)->toHaveCount(1)
        ->and($simili->first()->motivo)->toBe(RicercaFattureSimili::STANDARD);
});

it('livello standard: fuori dalla finestra di sette giorni non segnala', function () {
    $ctx = setupContabile();
    $prima = fatturaSimileTest($ctx, [
        'numero_documento' => 'FT-201',
        'data_documento' => '2026-06-10',
    ]);

    $simili = cercaComeFosse($prima, [
        'numeroDocumento' => 'TUTT-ALTRO-NUMERO',
        'dataDocumento' => '2026-06-18',
    ]);

    expect($simili)->toHaveCount(0);
});

it('livello standard: un solo centesimo di differenza basta a non segnalare — zero tolleranza', function () {
    $ctx = setupContabile();
    $prima = fatturaSimileTest($ctx, [
        'numero_documento' => 'FT-202',
        'data_documento' => '2026-06-10',
    ]);

    $simili = cercaComeFosse($prima, [
        'numeroDocumento' => 'TUTT-ALTRO-NUMERO',
        'totaleDocumentoCents' => (int) $prima->totale_documento + 1,
    ]);

    expect($simili)->toHaveCount(0);
});

it('la ricorrenza mensile a importo fisso non e un duplicato — e la ragione della finestra stretta', function () {
    $ctx = setupContabile();
    $gennaio = fatturaSimileTest($ctx, [
        'numero_documento' => 'CANONE-01',
        'data_documento' => '2026-01-31',
    ]);

    // Stesso canone, stesso importo, mese dopo: è la vita normale di un condominio.
    $simili = cercaComeFosse($gennaio, [
        'numeroDocumento' => 'CANONE-02',
        'dataDocumento' => '2026-02-28',
    ]);

    expect($simili)->toHaveCount(0);
});

it('una fattura stornata non conta come duplicato', function () {
    $ctx = setupContabile();
    $prima = fatturaSimileTest($ctx, ['numero_documento' => 'FT-300']);

    $prima->dati_extra = array_merge($prima->dati_extra ?? [], ['is_stornata' => true]);
    $prima->save();

    expect(cercaComeFosse($prima))->toHaveCount(0);
});

it('⚠️ CONTROPROVA: il filtro ovvio sulle stornate azzererebbe la ricerca invece di restringerla', function () {
    $ctx = setupContabile();
    $viva = fatturaSimileTest($ctx, ['numero_documento' => 'FT-301']);

    // Nessuna fattura è stornata: `dati_extra->is_stornata` è una chiave ASSENTE, non `false`.
    expect($viva->dati_extra['is_stornata'] ?? null)->toBeNull();

    // La forma ingenua — quella che verrebbe scritta per prima — non restringe: **spegne**.
    // In SQL `NULL != true` non è vero, è sconosciuto, quindi la riga non passa il filtro.
    $conFiltroIngenuo = FatturaPassiva::query()
        ->where('condominio_id', $viva->condominio_id)
        ->where('dati_extra->is_stornata', '!=', true)
        ->count();

    expect($conFiltroIngenuo)->toBe(0);

    // Il servizio usa la forma corretta, e infatti la fattura viva la trova.
    expect(cercaComeFosse($viva))->toHaveCount(1);
});

it('in modifica la fattura aperta non si segnala da sola', function () {
    $ctx = setupContabile();
    $f = fatturaSimileTest($ctx, ['numero_documento' => 'FT-400']);

    expect(cercaComeFosse($f))->toHaveCount(1)
        ->and(cercaComeFosse($f, ['escludiFatturaId' => $f->id]))->toHaveCount(0);
});

it('non guarda mai le fatture di un altro condominio, benche il fornitore sia lo stesso', function () {
    // ⚠️ **Questo test è stato riscritto perché la prima stesura era verde per la ragione
    // sbagliata**, e l'ha scoperto la controprova: togliendo lo scoping su `condominio_id` dal
    // servizio, il test continuava a passare. Il motivo è che `setupContabile()` crea un
    // **fornitore diverso** per ogni contesto, quindi il filtro su `fornitore_id` bastava già a
    // separare i due condomìni: si stava provando quello, non lo scoping.
    //
    // Lo scenario vero è l'opposto ed è misurato: `fornitori` non ha `condominio_id`, e lo stesso
    // fornitore serve più palazzi dello stesso studio (in archivio il fornitore 10 su quattro
    // condomìni). Quindi si cerca nel condominio B **con il fornitore di A**.
    $ctxA = setupContabile();
    $ctxB = setupContabile();
    [, , , $fornitoreA] = $ctxA;
    [$condominioB, $esercizioB] = $ctxB;

    $inA = fatturaSimileTest($ctxA, ['numero_documento' => 'FT-500']);

    $simili = (new RicercaFattureSimili())->cerca(
        condominioId: $condominioB->id,
        esercizioId: $esercizioB->id,
        fornitoreId: $fornitoreA->id,
        numeroDocumento: $inA->numero_documento,
        totaleDocumentoCents: (int) $inA->totale_documento,
        dataDocumento: $inA->data_documento->toDateString(),
    );

    // Non deve trovarla, e non deve nemmeno lasciar trapelare che esiste: numero, data e importo
    // di un altro condominio non sono affari di questa schermata.
    expect($simili)->toHaveCount(0);
});

it('una nota di credito non e il duplicato di una fattura con lo stesso numero', function () {
    $ctx = setupContabile();
    $fattura = fatturaSimileTest($ctx, ['numero_documento' => 'FT-600']);

    $simili = cercaComeFosse($fattura, ['tipoDocumento' => 'nota_credito']);

    expect($simili)->toHaveCount(0);
});

it('livello standard: funziona anche fra note di credito, non solo fra fatture', function () {
    // ⚠️ **A2, trovato dalla revisione avversariale della beta.13.** A database una NC è
    // salvata negativa (`FatturaPassivaService::registraFattura()` applica il moltiplicatore
    // -1 in riga 176), ma chi chiama questo servizio manda sempre il valore ASSOLUTO — cosi'
    // digita l'amministratore, cosi' calcola `totali.ts`. Senza normalizzare il segno nel
    // confronto, `where('totale_documento', $positivo)` non trova mai una NC, il cui
    // `totale_documento` a database e' negativo: il livello standard era muto su ogni nota
    // di credito, silenziosamente, da quando esiste.
    $ctx = setupContabile();
    $prima = fatturaSimileTest($ctx, [
        'numero_documento' => 'NC-100',
        'tipo_documento' => 'nota_credito',
        'data_documento' => '2026-06-10',
    ]);

    expect((int) $prima->totale_documento)->toBeLessThan(0);

    // Stesso importo ASSOLUTO (quello che il modulo manda davvero), numero diverso, data
    // entro la finestra: e' esattamente lo scenario "livello standard" gia' provato sopra
    // per le fatture, qui ripetuto sulle note di credito.
    $simili = cercaComeFosse($prima, [
        'numeroDocumento' => 'TUTT-ALTRO-NUMERO',
        'totaleDocumentoCents' => abs((int) $prima->totale_documento),
        'dataDocumento' => '2026-06-15',
        'tipoDocumento' => 'nota_credito',
    ]);

    expect($simili)->toHaveCount(1)
        ->and($simili->first()->motivo)->toBe(RicercaFattureSimili::STANDARD);
});

it('una fattura che casca in tutti e due i livelli compare una volta sola, col motivo forte', function () {
    $ctx = setupContabile();
    $prima = fatturaSimileTest($ctx, [
        'numero_documento' => 'FT-700',
        'data_documento' => '2026-06-10',
    ]);

    // Stesso numero (forte) E stesso importo a stessa data (standard).
    $simili = cercaComeFosse($prima);

    expect($simili)->toHaveCount(1)
        ->and($simili->first()->motivo)->toBe(RicercaFattureSimili::FORTE);
});

it('le fatture pregresse si segnalano, perche sono il caso a piu alto rischio', function () {
    $ctx = setupContabile();
    $pregressa = fatturaSimileTest($ctx, [
        'numero_documento' => 'FT-800',
        'is_pregresso' => true,
    ]);

    $simili = cercaComeFosse($pregressa);

    expect($simili)->toHaveCount(1)
        ->and($simili->first()->isPregresso)->toBeTrue();
});

it('una data malformata non solleva: il modulo e in compilazione, non in errore', function () {
    // ⚠️ **`'2026-06'` non è una data malformata, e il test era verde per il motivo sbagliato**
    // (trovato dalla revisione avversariale della beta.13). `Carbon::parse('2026-06')`
    // restituisce silenziosamente 2026-06-01: non solleva mai, e lo 0 atteso arrivava dal
    // calendario (la fattura è datata `now()`, fuori dalla finestra di sette giorni), non dal
    // `try/catch` che il test dice di provare. Rimosso il `try/catch` il test restava verde.
    // `'31/02/2026'` solleva davvero `InvalidFormatException` — verificato in isolamento.
    $ctx = setupContabile();
    $f = fatturaSimileTest($ctx, ['numero_documento' => 'FT-900']);

    $simili = cercaComeFosse($f, [
        'numeroDocumento' => 'ALTRO',
        'dataDocumento' => '31/02/2026',
    ]);

    expect($simili)->toHaveCount(0);
});
