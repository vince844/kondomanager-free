<?php

/**
 * Il versamento di una delega F24, e il suo storno.
 *
 * È il movimento che **chiude il conto 2202**. Prima di questo modulo quel conto accumulava
 * soltanto: verificato a database il 03/08/2026, `dare = 0` su tutti i condomìni. Le
 * ritenute entravano nel debito verso l'Erario e non ne uscivano mai — non per un errore di
 * calcolo, ma perché la riga che le versa non esisteva.
 *
 * ```
 *   DARE  2202 Debiti v/Erario per Ritenute
 *   AVERE Banca
 * ```
 *
 * Il primo test di questo file è quello che conta più di tutti: dopo il versamento il saldo
 * del 2202 deve tornare a zero.
 */

use App\Actions\Gestionale\Movimenti\ConfermaVersamentoF24Action;
use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Actions\Gestionale\Movimenti\StornaVersamentoF24Action;
use App\Enums\Fiscale\StatoDelegaF24;
use App\Enums\TipoMovimentoContabile;
use App\Models\Gestionale\ContoContabile;
use App\Services\Gestionale\CalendarioFiscaleService;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Services\Gestionale\PlafondRitenuteService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

function azioneGenera(): GeneraDelegheF24Action
{
    return new GeneraDelegheF24Action(new PlafondRitenuteService(new CalendarioFiscaleService));
}

/** Contesto con fornitore soggetto a ritenuta, una fattura pagata e la delega generata. */
function scenarioDelega(): array
{
    $ctx = setupPagamentiService();
    // ⚠️ `natura_percipiente` va dichiarata: dal 03/09/2026 `GeneraDelegheF24Action` non
    // ripiega più su persona fisica quando non la sa, e senza questa riga lo scenario non
    // arriva nemmeno a produrre la delega (Coda 119).
    $ctx[3]->update([
        'soggetto_ritenuta' => true,
        'perc_ritenuta' => 4,
        'perc_imponibile_ritenuta' => 100,
        'natura_percipiente' => 'persona_fisica_irpef',
    ]);
    $ctx[3]->refresh();

    [$condominio, $esercizio] = $ctx;

    $fattura = registraFatturaServiceTest($ctx, [
        'applica_ritenuta' => true,
        'righe' => [[
            'descrizione' => 'Manutenzione',
            'importo_imponibile' => 20_000,
            'aliquota_iva' => 22,
            'conto_id' => $ctx[5]->id,
            'is_sopravvenienza' => false,
        ]],
    ]);

    (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, ['data_pagamento' => '2026-03-10'])
    );

    $delega = azioneGenera()->esegui($condominio, $esercizio->id)->first();

    return [$ctx, $delega];
}

/** Il saldo del conto 2202: avere − dare. Positivo = debito verso l'Erario ancora aperto. */
function saldo2202(int $condominioId): int
{
    $conto = ContoContabile::where('condominio_id', $condominioId)
        ->where('ruolo', 'debiti_erario_ritenute')
        ->first();

    $dare = (int) DB::table('righe_scritture')->where('conto_contabile_id', $conto->id)->where('tipo_riga', 'dare')->sum('importo');
    $avere = (int) DB::table('righe_scritture')->where('conto_contabile_id', $conto->id)->where('tipo_riga', 'avere')->sum('importo');

    return $avere - $dare;
}

// ════════════════════════════════════════════════════════════════════════════
// Il conto 2202 si chiude
// ════════════════════════════════════════════════════════════════════════════

test('prima del versamento il 2202 ha solo accrediti, come è sempre stato', function () {
    [$ctx] = scenarioDelega();
    [$condominio] = $ctx;

    $conto = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'debiti_erario_ritenute')->first();
    $dare = (int) DB::table('righe_scritture')->where('conto_contabile_id', $conto->id)->where('tipo_riga', 'dare')->sum('importo');

    expect($dare)->toEqual(0)
        ->and(saldo2202($condominio->id))->toBeGreaterThan(0);
});

test('dopo il versamento il saldo del 2202 torna a zero', function () {
    [$ctx, $delega] = scenarioDelega();
    [$condominio, , , , $contoCorrenteId] = $ctx;

    expect(saldo2202($condominio->id))->toEqual((int) $delega->totale_debito);

    (new ConfermaVersamentoF24Action)->esegui($delega, '2026-04-16', $contoCorrenteId);

    expect(saldo2202($condominio->id))->toEqual(0);
});

test('la scrittura del versamento è DARE 2202 / AVERE banca e quadra', function () {
    [$ctx, $delega] = scenarioDelega();
    [$condominio, , , , $contoCorrenteId] = $ctx;

    $versata = (new ConfermaVersamentoF24Action)->esegui($delega, '2026-04-16', $contoCorrenteId);
    $scrittura = $versata->scrittura;

    expect($scrittura)->not->toBeNull()
        ->and($scrittura->tipo_movimento)->toEqual(TipoMovimentoContabile::PAGAMENTO_F24);

    assertQuadraturaPerfetta($scrittura->id);

    $contoErario = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'debiti_erario_ritenute')->first();

    expect(
        DB::table('righe_scritture')->where('scrittura_id', $scrittura->id)
            ->where('tipo_riga', 'dare')->where('conto_contabile_id', $contoErario->id)
            ->where('importo', $versata->totale_debito)->exists()
    )->toBeTrue('Il debito verso l\'Erario deve essere addebitato.');

    expect(
        DB::table('righe_scritture')->where('scrittura_id', $scrittura->id)
            ->where('tipo_riga', 'avere')->where('conto_contabile_id', $contoCorrenteId)
            ->where('importo', $versata->totale_debito)->exists()
    )->toBeTrue('Il denaro deve uscire dalla banca.');
});

test('il versamento porta la delega nello stato versata, con data e scrittura', function () {
    [$ctx, $delega] = scenarioDelega();
    [, , , , $contoCorrenteId] = $ctx;

    $versata = (new ConfermaVersamentoF24Action)->esegui($delega, '2026-04-16', $contoCorrenteId);

    expect($versata->stato)->toEqual(StatoDelegaF24::VERSATA)
        ->and($versata->data_versamento->format('Y-m-d'))->toEqual('2026-04-16')
        ->and($versata->scrittura_contabile_id)->not->toBeNull()
        ->and($versata->saldo)->toEqual(0);
});

test('la scrittura del versamento porta la gestione dei pagamenti che copre', function () {
    [$ctx, $delega] = scenarioDelega();
    [, , $gestione, , $contoCorrenteId] = $ctx;

    $versata = (new ConfermaVersamentoF24Action)->esegui($delega, '2026-04-16', $contoCorrenteId);

    // Senza, la scrittura compare nel Libro Giornale con la colonna «Gestione» vuota
    // mentre tutte le altre ce l'hanno, e resta fuori dai report per gestione.
    expect((int) $versata->scrittura->gestione_id)->toEqual($gestione->id);
});

// ════════════════════════════════════════════════════════════════════════════
// Il sigillo: versata non si rettifica
// ════════════════════════════════════════════════════════════════════════════

test('una delega versata non si versa una seconda volta', function () {
    [$ctx, $delega] = scenarioDelega();
    [, , , , $contoCorrenteId] = $ctx;

    $azione = new ConfermaVersamentoF24Action;
    $versata = $azione->esegui($delega, '2026-04-16', $contoCorrenteId);

    expect(fn () => $azione->esegui($versata, '2026-04-17', $contoCorrenteId))
        ->toThrow(\DomainException::class, 'già stata versata');
});

test('una delega versata non è più modificabile, e lo dice', function () {
    [$ctx, $delega] = scenarioDelega();
    [, , , , $contoCorrenteId] = $ctx;

    $versata = (new ConfermaVersamentoF24Action)->esegui($delega, '2026-04-16', $contoCorrenteId);

    expect($versata->isModificabile())->toBeFalse()
        ->and($versata->motivoBloccoModifica())->toContain('stornata');
});

// ════════════════════════════════════════════════════════════════════════════
// Storno
// ════════════════════════════════════════════════════════════════════════════

test('lo storno riapre il debito verso l Erario', function () {
    [$ctx, $delega] = scenarioDelega();
    [$condominio, , , , $contoCorrenteId] = $ctx;

    $versata = (new ConfermaVersamentoF24Action)->esegui($delega, '2026-04-16', $contoCorrenteId);
    expect(saldo2202($condominio->id))->toEqual(0);

    $stornata = (new StornaVersamentoF24Action)->esegui($versata, 'Conto sbagliato', '2026-04-20');

    expect($stornata->stato)->toEqual(StatoDelegaF24::STORNATA)
        ->and(saldo2202($condominio->id))->toEqual((int) $delega->totale_debito);
});

test('la scrittura di storno è uguale e contraria, e quadra', function () {
    [$ctx, $delega] = scenarioDelega();
    [$condominio, , , , $contoCorrenteId] = $ctx;

    $versata = (new ConfermaVersamentoF24Action)->esegui($delega, '2026-04-16', $contoCorrenteId);
    (new StornaVersamentoF24Action)->esegui($versata, 'Errore', '2026-04-20');

    $storno = DB::table('scritture_contabili')
        ->where('condominio_id', $condominio->id)
        ->where('tipo_movimento', TipoMovimentoContabile::STORNO_PAGAMENTO_F24->value)
        ->first();

    expect($storno)->not->toBeNull();
    assertQuadraturaPerfetta($storno->id);
});

test('lo storno pretende una motivazione', function () {
    [$ctx, $delega] = scenarioDelega();
    [, , , , $contoCorrenteId] = $ctx;

    $versata = (new ConfermaVersamentoF24Action)->esegui($delega, '2026-04-16', $contoCorrenteId);

    expect(fn () => (new StornaVersamentoF24Action)->esegui($versata, '   '))
        ->toThrow(\DomainException::class, 'motivazione');
});

test('una delega in bozza non si storna: si annulla', function () {
    [, $delega] = scenarioDelega();

    expect(fn () => (new StornaVersamentoF24Action)->esegui($delega, 'Ripensamento'))
        ->toThrow(\DomainException::class, 'solo una delega versata');
});

/**
 * Il giro completo: dopo uno storno le ritenute tornano da versare. Se restassero
 * agganciate alla delega stornata sparirebbero per sempre dal plafond, e il 2202 non si
 * chiuderebbe mai più — cioè si tornerebbe al punto di partenza, ma in silenzio.
 */
test('dopo lo storno le ritenute rientrano nel calcolo', function () {
    [$ctx, $delega] = scenarioDelega();
    [$condominio, $esercizio, , , $contoCorrenteId] = $ctx;

    $versata = (new ConfermaVersamentoF24Action)->esegui($delega, '2026-04-16', $contoCorrenteId);

    // Finché è versata, non c'è più niente da versare.
    expect(azioneGenera()->esegui($condominio, $esercizio->id))->toHaveCount(0);

    (new StornaVersamentoF24Action)->esegui($versata, 'Da rifare');

    $nuove = azioneGenera()->esegui($condominio, $esercizio->id);

    expect($nuove)->toHaveCount(1)
        ->and($nuove->first()->totale_debito)->toEqual((int) $delega->totale_debito);
});

// ════════════════════════════════════════════════════════════════════════════
// Guardie
// ════════════════════════════════════════════════════════════════════════════

test('senza il conto 2202 nel piano dei conti il versamento si ferma e lo spiega', function () {
    [$ctx, $delega] = scenarioDelega();
    [$condominio, , , , $contoCorrenteId] = $ctx;

    ContoContabile::where('condominio_id', $condominio->id)
        ->where('ruolo', 'debiti_erario_ritenute')
        ->update(['ruolo' => null]);

    expect(fn () => (new ConfermaVersamentoF24Action)->esegui($delega, '2026-04-16', $contoCorrenteId))
        ->toThrow(\DomainException::class, 'Debiti v/Erario');
});
