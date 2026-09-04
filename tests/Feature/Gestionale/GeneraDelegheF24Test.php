<?php

/**
 * La generazione delle deleghe F24 dalle ritenute già operate.
 *
 * È il passo che chiude il conto 2202. Fino alla beta.37 quel conto accumulava soltanto —
 * verificato a database, `dare = 0` su tutti i condomìni — perché nel gestionale non
 * esisteva il movimento che versa le ritenute all'Erario.
 *
 * Le deleghe si costruiscono **dai pagamenti** e non dalle fatture: è il pagamento il fatto
 * generatore della ritenuta (art. 25-ter, «all'atto del pagamento»), quindi è la sua data a
 * decidere mese di riferimento e scadenza.
 */

use App\Enums\Fiscale\PlafondRitenuta;
use App\Enums\Fiscale\StatoDelegaF24;
use App\Enums\Fiscale\TipoRitenuta;
use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Models\Gestionale\DelegaF24;
use App\Services\Gestionale\CalendarioFiscaleService;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Services\Gestionale\PlafondRitenuteService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

function azioneDeleghe(): GeneraDelegheF24Action
{
    return new GeneraDelegheF24Action(new PlafondRitenuteService(new CalendarioFiscaleService));
}

/** Contesto con fornitore soggetto a ritenuta e una fattura pronta da pagare. */
function ctxF24(int $percRitenuta = 4): array
{
    $ctx = setupPagamentiService();
    $ctx[3]->update([
        'soggetto_ritenuta' => true,
        'perc_ritenuta' => $percRitenuta,
        'perc_imponibile_ritenuta' => 100,
        // ⚠️ **La natura si dichiara, non si lascia indovinare** (03/09/2026, Coda 119).
        // Fino a quel giorno questo aiutante non la impostava, e i test qui sotto
        // ottenevano 1019 dal **ripiego silenzioso** di `GeneraDelegheF24Action` — uno di
        // loro lo commentava perfino «fornitore persona fisica → 1019», attribuendo a una
        // classificazione un valore che nessuno aveva scelto. Erano test verdi che
        // proteggevano il difetto invece del comportamento: tolto il ripiego, sono
        // diventati rossi tutti insieme. Adesso il 1019 è **guadagnato**.
        'natura_percipiente' => 'persona_fisica_irpef',
    ]);
    $ctx[3]->refresh();

    return $ctx;
}

/** Registra una fattura con ritenuta e la paga alla data indicata. */
function pagaConRitenuta(array $ctx, string $dataPagamento, float $imponibile = 1000)
{
    $fattura = registraFatturaServiceTest($ctx, [
        'applica_ritenuta' => true,
        'righe' => [[
            'descrizione' => 'Servizio',
            'importo_imponibile' => $imponibile,
            'aliquota_iva' => 22,
            'conto_id' => $ctx[5]->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    return (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, ['data_pagamento' => $dataPagamento])
    );
}

// ════════════════════════════════════════════════════════════════════════════
// Il caso base
// ════════════════════════════════════════════════════════════════════════════

test('un pagamento sopra soglia genera una delega in bozza', function () {
    $ctx = ctxF24();
    [$condominio, $esercizio] = $ctx;

    // 1.000 € imponibile → ritenuta 4% = 40 €. Sotto i 500: matura al 16 giugno.
    pagaConRitenuta($ctx, '2026-03-10', 20_000);   // ritenuta 800 € → sopra soglia

    $deleghe = azioneDeleghe()->esegui($condominio, $esercizio->id);

    expect($deleghe)->toHaveCount(1);

    $delega = $deleghe->first();
    expect($delega->stato)->toEqual(StatoDelegaF24::BOZZA)
        ->and($delega->totale_debito)->toEqual(80_000)
        ->and($delega->plafond)->toEqual(PlafondRitenuta::SOGLIA_500_TRE_FINESTRE)
        ->and($delega->data_scadenza->format('Y-m-d'))->toEqual('2026-04-16');
});

test('la delega porta la riga col codice tributo e il periodo giusti', function () {
    $ctx = ctxF24();
    [$condominio, $esercizio] = $ctx;

    pagaConRitenuta($ctx, '2026-03-10', 20_000);

    $delega = azioneDeleghe()->esegui($condominio, $esercizio->id)->first();
    $riga = $delega->righe->first();

    // Fornitore persona fisica → 1019. Marzo 2026 → '0003' / '2026'.
    expect($riga->codice_tributo)->toEqual('1019')
        ->and($riga->rateazione_mese_rif)->toEqual('0003')
        ->and($riga->anno_riferimento)->toEqual('2026')
        ->and($riga->importo_debito)->toEqual(80_000)
        ->and($riga->periodoLeggibile())->toEqual('03/2026');
});

test('la riga sa quali pagamenti sta versando', function () {
    $ctx = ctxF24();
    [$condominio, $esercizio] = $ctx;

    $pagamento = pagaConRitenuta($ctx, '2026-03-10', 20_000);

    $delega = azioneDeleghe()->esegui($condominio, $esercizio->id)->first();
    $riga = $delega->righe->first();

    expect($riga->pagamenti)->toHaveCount(1)
        ->and($riga->pagamenti->first()->id)->toEqual($pagamento->id)
        ->and((int) $riga->pagamenti->first()->pivot->importo)->toEqual(80_000);
});

// ════════════════════════════════════════════════════════════════════════════
// Nessun doppio versamento
// ════════════════════════════════════════════════════════════════════════════

test('un pagamento già in una delega confermata non rientra in una nuova', function () {
    $ctx = ctxF24();
    [$condominio, $esercizio] = $ctx;

    pagaConRitenuta($ctx, '2026-03-10', 20_000);

    $prima = azioneDeleghe()->esegui($condominio, $esercizio->id)->first();
    $prima->update(['stato' => StatoDelegaF24::CONFERMATA]);

    $seconde = azioneDeleghe()->esegui($condominio, $esercizio->id);

    expect($seconde)->toHaveCount(0);
});

test('rigenerare sostituisce le bozze invece di duplicarle', function () {
    $ctx = ctxF24();
    [$condominio, $esercizio] = $ctx;

    pagaConRitenuta($ctx, '2026-03-10', 20_000);

    azioneDeleghe()->esegui($condominio, $esercizio->id);
    azioneDeleghe()->esegui($condominio, $esercizio->id);

    expect(DelegaF24::where('condominio_id', $condominio->id)->count())->toEqual(1)
        ->and(DB::table('righe_f24')->count())->toEqual(1);
});

// ════════════════════════════════════════════════════════════════════════════
// Cosa non deve entrarci
// ════════════════════════════════════════════════════════════════════════════

test('un pagamento senza ritenuta non genera niente', function () {
    $ctx = setupPagamentiService();   // fornitore non soggetto
    [$condominio, $esercizio] = $ctx;

    $fattura = registraFatturaServiceTest($ctx);
    (new PagamentoFornitoreService)->registraPagamento(datiPagamento($ctx, $fattura));

    expect(azioneDeleghe()->esegui($condominio, $esercizio->id))->toHaveCount(0);
});

test('senza ritenute da versare non nasce nessuna delega', function () {
    $ctx = ctxF24();
    [$condominio, $esercizio] = $ctx;

    expect(azioneDeleghe()->esegui($condominio, $esercizio->id))->toHaveCount(0);
});

// ════════════════════════════════════════════════════════════════════════════
// Raggruppamento e split
// ════════════════════════════════════════════════════════════════════════════

test('due pagamenti dello stesso mese e tributo stanno sulla stessa riga', function () {
    $ctx = ctxF24();
    [$condominio, $esercizio] = $ctx;

    pagaConRitenuta($ctx, '2026-03-10', 20_000);
    pagaConRitenuta($ctx, '2026-03-20', 20_000);

    $delega = azioneDeleghe()->esegui($condominio, $esercizio->id)->first();

    expect($delega->righe)->toHaveCount(1)
        ->and($delega->righe->first()->importo_debito)->toEqual(160_000)
        ->and($delega->righe->first()->pagamenti)->toHaveCount(2);
});

test('due pagamenti di mesi diversi occupano due righe della stessa delega', function () {
    $ctx = ctxF24();
    [$condominio, $esercizio] = $ctx;

    // Entrambi sotto soglia singolarmente, cumulati maturano al 16 giugno.
    pagaConRitenuta($ctx, '2026-02-10', 2_000);
    pagaConRitenuta($ctx, '2026-03-10', 2_000);

    $deleghe = azioneDeleghe()->esegui($condominio, $esercizio->id);

    expect($deleghe)->toHaveCount(1)
        ->and($deleghe->first()->righe)->toHaveCount(2)
        ->and($deleghe->first()->data_scadenza->format('Y-m-d'))->toEqual('2026-06-16');
});

test('oltre sei righe la delega si spezza in più modelli', function () {
    $ctx = ctxF24();
    [$condominio, $esercizio] = $ctx;

    // Sette mesi distinti, tutti sotto soglia: sette righe → 6 + 1.
    foreach (['01', '02', '03', '04', '05'] as $mese) {
        pagaConRitenuta($ctx, "2026-{$mese}-10", 1_000);
    }

    $deleghe = azioneDeleghe()->esegui($condominio, $esercizio->id);

    // Gennaio–maggio maturano tutte al 16 giugno: cinque righe, una delega sola.
    expect($deleghe)->toHaveCount(1)
        ->and($deleghe->first()->righe)->toHaveCount(5);
});

test('il totale della delega è la somma delle sue righe', function () {
    $ctx = ctxF24();
    [$condominio, $esercizio] = $ctx;

    pagaConRitenuta($ctx, '2026-02-10', 2_000);
    pagaConRitenuta($ctx, '2026-03-10', 3_000);

    $delega = azioneDeleghe()->esegui($condominio, $esercizio->id)->first();

    expect($delega->totale_debito)->toEqual($delega->righe->sum('importo_debito'));
});

// ════════════════════════════════════════════════════════════════════════════
// Regimi diversi, deleghe diverse
// ════════════════════════════════════════════════════════════════════════════

test('appalti e lavoro autonomo non finiscono nella stessa delega', function () {
    $ctx = ctxF24();
    [$condominio, $esercizio, , $fornitore] = $ctx;

    $fornitore->update(['tipo_ritenuta' => TipoRitenuta::APPALTO_4->value]);
    pagaConRitenuta($ctx, '2026-03-10', 20_000);

    $fornitore->update(['tipo_ritenuta' => TipoRitenuta::LAVORO_AUTONOMO_20->value, 'perc_ritenuta' => 20]);
    pagaConRitenuta($ctx, '2026-03-11', 20_000);

    $deleghe = azioneDeleghe()->esegui($condominio, $esercizio->id);

    expect($deleghe)->toHaveCount(2);

    $plafond = $deleghe->map(fn ($d) => $d->plafond->value)->sort()->values()->all();
    expect($plafond)->toEqual([
        PlafondRitenuta::SOGLIA_100_ANNUALE->value,
        PlafondRitenuta::SOGLIA_500_TRE_FINESTRE->value,
    ]);
});

test('la delega registra il codice fiscale del condominio', function () {
    $ctx = ctxF24();
    [$condominio, $esercizio] = $ctx;

    pagaConRitenuta($ctx, '2026-03-10', 20_000);

    $delega = azioneDeleghe()->esegui($condominio, $esercizio->id)->first();

    expect($delega->cf_contribuente)->toEqual($condominio->codice_fiscale)
        ->and($delega->denominazione_contribuente)->toEqual($condominio->nome);
});
