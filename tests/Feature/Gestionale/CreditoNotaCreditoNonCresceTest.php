<?php

/**
 * # Il credito di una nota non cresce mentre lo si spende
 *
 * ## Il difetto, misurato il 22/08/2026
 *
 * `FatturaPassiva::getResiduoAttribute()` fa `netto_a_pagare − totale_allocato`. Su una fattura è
 * giusto. Su una **nota di credito** il netto è negativo — `FatturaPassivaService` moltiplica per
 * −1 tutto ciò che registra come `nota_credito` — e l'allocato è positivo, quindi ogni
 * compensazione **allontana** il residuo da zero invece di avvicinarlo:
 *
 *     nota da € 2.440,00, niente compensato   → residuo −244000, cioè € 2.440,00 disponibili  ✓
 *     dopo aver compensato € 1.220,00         → residuo −366000, cioè € 3.660,00 disponibili  ✗
 *
 * **Il credito cresce mentre lo spendi.** L'endpoint delle pendenze lo espone con `abs()`, e la
 * guardia sull'eccesso confronta magnitudo — `abs($allocatoProposto) > abs($residuo)` — quindi
 * legge la cifra gonfiata e accetta la terza compensazione: **€ 3.660,00 consumati da una nota che
 * ne vale € 2.440,00**, tre fatture chiuse a «pagata» e zero euro usciti di cassa.
 *
 * ## Perché la correzione sta nell'accessor e non nei chiamanti
 *
 * I chiamanti sono tre e **uno è la guardia stessa**: correggerli uno per uno vorrebbe dire
 * ricordarsi ogni volta che quel numero, per una nota, va letto al contrario. La convenzione giusta
 * ce l'ha già `PagamentoFornitoreService::ricalcolaStatoFattura()`, che confronta `abs($totale)`
 * con `abs($netto)` ed è corretto: il residuo deve dire **quanto resta**, con lo stesso segno per
 * fatture e note, e il negativo deve tornare a significare una cosa sola — allocato più del dovuto.
 *
 * ## Perché era latente, e perché smette di esserlo
 *
 * Dalla schermata la compensazione non si riusciva nemmeno a costruire: `PagamentoNew.vue` emetteva
 * un record per documento e la partita doppia non quadrava, quindi nessuna nota aveva allocazioni e
 * nessun residuo era davvero sbagliato. La beta.67 apre quella strada — ed è il motivo per cui le
 * due cose vanno fatte **insieme**: sistemare la schermata da sola trasformerebbe un errore visibile
 * in conti sbagliati silenziosi.
 */

require_once __DIR__.'/GestionaleTestHelpers.php';

use App\Enums\TipoAllocazioneFattura;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Registra una nota di credito passando dal service, come fa l'applicazione.
 *
 * ⚠️ Il contesto è quello di `setupPagamentiService()` e non di `setupContabile()`: i due helper
 * restituiscono gli stessi modelli **in ordine diverso** — nel primo l'indice 5 è il capitolo di
 * spesa, nel secondo è un conto contabile — e `registraFatturaServiceTest()` si aspetta il primo.
 */
function notaDiCredito(array $ctx, int $imponibileEuro = 2000): FatturaPassiva
{
    [, , , , , $capitolo] = $ctx;

    return registraFatturaServiceTest($ctx, [
        'tipo_documento'   => 'nota_credito',
        'numero_documento' => 'NC-'.$imponibileEuro,
        'righe'            => [[
            'descrizione'        => 'Storno servizio',
            'importo_imponibile' => $imponibileEuro,
            'aliquota_iva'       => 22,
            'conto_id'           => $capitolo->id,
            'is_sopravvenienza'  => false,
        ]],
    ]);
}

/** Consuma una parte del credito, come farebbe una compensazione su fattura. */
function compensa(array $ctx, FatturaPassiva $nota, int $centesimi): void
{
    [$condominio, $esercizio, $gestione] = $ctx;

    $scrittura = ScritturaContabile::create([
        'condominio_id'      => $condominio->id,
        'esercizio_id'       => $esercizio->id,
        'gestione_id'        => $gestione->id,
        'data_registrazione' => '2026-02-01',
        'data_competenza'    => '2026-02-01',
        'descrizione'        => 'compensazione di prova',
        'causale'            => 'compensazione di prova',
        'tipo_movimento'     => 'pagamento_fornitore',
    ]);

    $nota->scritture()->attach($scrittura->id, [
        'tipo'             => TipoAllocazioneFattura::COMPENSAZIONE->value,
        'importo_allocato' => $centesimi,
    ]);

    $nota->refresh();
}

it('lo scenario è quello vero: il service registra la nota con il netto negativo', function () {
    // ⚠️ Senza questo, il giorno che cambia la convenzione di segno questo file proverebbe uno
    // scenario che non esiste più — verde per sempre, e per la peggiore delle ragioni.
    $nota = notaDiCredito(setupPagamentiService());

    expect($nota->netto_a_pagare)->toBeLessThan(0,
        'Il netto di una nota di credito non è più negativo: la convenzione di segno è cambiata e '.
        'tutto questo file va riscritto, non aggiustato.'
    );
    expect(abs($nota->netto_a_pagare))->toBe(244000);
});

it('⚠️ il credito residuo cala quando lo si compensa, invece di crescere', function () {
    $ctx = setupPagamentiService();
    $nota = notaDiCredito($ctx);

    expect($nota->residuo)->toBe(244000,
        "Una nota da € 2.440,00 mai compensata deve avere € 2.440,00 di credito residuo."
    );

    compensa($ctx, $nota, 122000);

    expect($nota->residuo)->toBe(122000,
        "Dopo aver compensato € 1.220,00 di una nota da € 2.440,00 il residuo deve essere\n".
        "€ 1.220,00. Se è € 3.660,00, il residuo si sta calcolando come `netto − allocato` con il\n".
        "netto negativo: **il credito cresce mentre lo si spende**, e la guardia sull'eccesso —\n".
        "che confronta magnitudo — lascia consumare una volta e mezza il valore della nota."
    );

    compensa($ctx, $nota, 122000);

    expect($nota->residuo)->toBe(0, 'Una nota compensata per intero non ha più credito residuo.');
});

it('e su una fattura il residuo non cambia comportamento', function () {
    // La controprova che serve: la correzione riguarda le note, e non deve spostare di un centesimo
    // il caso normale. Senza, si soddisfa il test sopra rompendo tutto il resto.
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx);

    expect($fattura->residuo)->toBe($fattura->netto_a_pagare)
        ->and($fattura->residuo)->toBeGreaterThan(0);

    compensa($ctx, $fattura, 50000);

    expect($fattura->residuo)->toBe($fattura->netto_a_pagare - 50000);
});

it('il negativo torna a significare una cosa sola: allocato più del dovuto', function () {
    // Il docblock dell'accessor dice «Negativo = overpayment → anomalia». Sulle note quella frase
    // era falsa — il negativo era la condizione normale — e una frase falsa in un accessor di
    // denaro è il modo in cui il chiamante successivo sbaglia in buona fede.
    $ctx = setupPagamentiService();
    $nota = notaDiCredito($ctx);

    compensa($ctx, $nota, 300000); // € 3.000,00 su una nota che ne vale € 2.440,00

    // ⚠️ Il valore esatto, non `toBeLessThan(0)`: prima della correzione il residuo era **anche
    // più** negativo (−544000, cioè netto −244000 meno l'allocato), quindi una soglia a zero
    // sarebbe stata verde prima e dopo — un test che non distingue lo stato rotto da quello sano.
    expect($nota->residuo)->toBe(-56000,
        "Una nota da € 2.440,00 consumata per € 3.000,00 deve avere residuo −€ 560,00: l'eccesso,\n".
        'e nient\'altro. Il negativo significa allocato più del dovuto, come su una fattura strapagata.'
    );
});

/*
|--------------------------------------------------------------------------
| Il percorso vero: la terza compensazione viene rifiutata
|--------------------------------------------------------------------------
*/

it('⚠️ una nota da € 2.440,00 non ne lascia consumare € 3.660,00', function () {
    // È lo scenario esatto della coda ㉘, sul percorso vero e non con un attach a mano: tre
    // fatture da € 1.220,00 e una nota da € 2.440,00. Le prime due compensazioni sono legittime;
    // la terza deve essere rifiutata, perché il credito è finito.
    //
    // Prima della beta.67 passava: il residuo della nota cresceva a ogni compensazione e la guardia
    // ne leggeva il valore assoluto. Risultato: **tre fatture chiuse a «pagata» e zero euro usciti
    // di cassa**, con un credito inventato di € 1.220,00.
    $ctx = setupPagamentiService();
    $nota = notaDiCredito($ctx);
    $service = app(\App\Services\Gestionale\PagamentoFornitoreService::class);

    $fatture = collect(range(1, 3))->map(fn ($i) => registraFatturaServiceTest($ctx, [
        'numero_documento' => "FT-{$i}",
        'righe' => [[
            'descrizione'        => "Servizio {$i}",
            'importo_imponibile' => 1000,
            'aliquota_iva'       => 22,
            'conto_id'           => $ctx[5]->id,
            'is_sopravvenienza'  => false,
        ]],
    ]));

    // Compensazione pura: nessun euro esce di cassa, il debito si chiude con il credito.
    $compensazionePura = fn (\App\Models\Gestionale\FatturaPassiva $ft) => datiPagamento($ctx, $ft, [
        'allocazioni' => [
            ['fattura_id' => $ft->id,   'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value, 'importo_allocato_cents' => 122000],
            ['fattura_id' => $nota->id, 'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value, 'importo_allocato_cents' => 122000],
        ],
    ]);

    $service->registraPagamento($compensazionePura($fatture[0]), $ctx[0]->id);
    expect($nota->fresh()->residuo)->toBe(122000, 'Dopo la prima compensazione resta metà credito.');

    $service->registraPagamento($compensazionePura($fatture[1]), $ctx[0]->id);
    expect($nota->fresh()->residuo)->toBe(0, 'Dopo la seconda il credito è esaurito.');

    expect(fn () => $service->registraPagamento($compensazionePura($fatture[2]), $ctx[0]->id))
        ->toThrow(\App\Exceptions\Pagamenti\OverpaymentException::class);

    // ⚠️ La controprova che conta davvero: non basta che l'eccezione sia stata sollevata, deve
    // **non essere successo niente**. Una guardia che solleva dopo aver scritto è peggio di una
    // che non c'è, perché lascia il database in uno stato che nessuno si aspetta.
    expect($fatture[2]->fresh()->stato_pagamento->value)->toBe('aperta',
        'La terza fattura risulta toccata: la guardia ha sollevato **dopo** aver scritto.'
    );
    expect($nota->fresh()->residuo)->toBe(0, 'Il credito della nota è stato intaccato dal tentativo rifiutato.');
});
