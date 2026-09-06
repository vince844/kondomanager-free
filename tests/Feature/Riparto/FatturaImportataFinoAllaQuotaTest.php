<?php

use App\Models\Gestionale\PianoRate;
use App\Services\CalcoloQuoteService;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/SforoConGiaVersatoTest.php';

/**
 * **Il centesimo distribuito arriva fino alla quota che il condòmino paga.**
 *
 * Chiude il reperto C della Fase 1-bis della beta.19. Gli altri test di quella beta si fermano
 * alla fattura: provano che il documento si registra per quello che dichiara. Nessuno faceva
 * la domanda successiva, che è l'unica che interessa a chi paga — **e quel numero, quanto
 * diventa?**
 *
 * ⚠️ **Perché la domanda non è oziosa.** Dalla beta.19, su una fattura importata,
 * `riga.importo_iva` non è più `round(riga.importo_imponibile × aliquota / 100)`: l'imposta
 * dichiarata dal fornitore viene distribuita fra le righe del gruppo e un centesimo di
 * compensazione finisce sulla riga col resto maggiore. Era un'invariante non scritta da
 * nessuna parte, ed è la più pericolosa da rompere: chi vi si appoggia non la nomina, quindi
 * non c'è niente da cercare con un grep.
 *
 * La perlustrazione del 06/09/2026 ha stabilito che **nessun consumatore a valle ricalcola
 * l'IVA** — la leggono e la propagano. Questo test prova la cosa diversa e più forte: che
 * propagandola si arriva al numero **giusto**, non solo a un numero coerente.
 *
 * La catena che percorre è quella vera, non una simulazione:
 *
 *     XML → registrazione → righe_fattura → SUM(imponibile + iva) → budget del capitolo
 *         → CalcoloQuoteService → quota del proprietario
 *
 * Il punto stretto è `CalcoloQuoteService.php:320-325`, che somma le righe con
 * `SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva)`: è da lì che il costo
 * reale di una spesa entra nel riparto.
 */

/**
 * Lo scenario del riparto, completato col piano dei mastri che la registrazione richiede.
 *
 * `sforoScenario()` nasce per provare il riparto e si ferma al capitolo: non ha i conti
 * contabili, perché i suoi test inseriscono le righe di fattura a mano. Qui la fattura la
 * registra il servizio vero — che scrive in partita doppia — e quindi i mastri servono.
 */
function scenarioConMastri(int $budget): object
{
    // Gli stessi eventi che `setupEcosistemaLifecycle()` neutralizza: lo scadenziario e la
    // cassa hanno un ecosistema loro, e qui si misura il riparto, non le loro reazioni.
    Illuminate\Support\Facades\Event::fake([
        \App\Events\Gestionale\FatturaRegistrata::class,
        \App\Events\Gestionale\PagamentoRegistrato::class,
    ]);

    $sc = sforoScenario($budget);

    $ruoli = [
        'debiti_fornitori' => ['passivo', 'debiti', 'DEB-FOR'],
        'debiti_erario_ritenute' => ['passivo', 'debiti', 'DEB-RIT'],
        'crediti_condomini' => ['attivo', 'crediti', 'CRED-COND'],
        'banca' => ['attivo', 'liquidita', 'BANCA'],
        'spese_bancarie' => ['attivo', 'crediti', 'SP-BANC'],
    ];
    foreach ($ruoli as $ruolo => $p) {
        DB::table('conti_contabili')->insert([
            'condominio_id' => $sc->condominio->id, 'ruolo' => $ruolo, 'codice' => $p[2],
            'nome' => ucfirst(str_replace('_', ' ', $ruolo)), 'tipo' => $p[0], 'categoria' => $p[1],
            'attivo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Il mastro di costo a cui il capitolo si appoggia.
    $costoId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $sc->condominio->id, 'ruolo' => null, 'codice' => 'GAS',
        'nome' => 'Riscaldamento e gas', 'tipo' => 'attivo', 'categoria' => 'crediti',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $sc->conto->forceFill(['conto_contabile_id' => $costoId])->save();

    return $sc;
}

/** I numeri del file 06 dei collaudi, quelli che il documento dichiara di sé. */
const IVA_DICHIARATA_FILE06 = [
    ['aliquota_iva' => 22.0, 'natura' => null, 'imponibile' => 45.74, 'imposta' => 10.06],
    ['aliquota_iva' => 0.0, 'natura' => 'N2.2', 'imponibile' => 44.35, 'imposta' => 0.0],
];

/**
 * Registra la bolletta del file 06 sul capitolo dello scenario, passando dal servizio vero.
 *
 * @param  bool  $conRiepiloghi  false = la stessa bolletta digitata a mano, che vale un centesimo in meno
 */
function registraBollettaImportata(object $sc, bool $conRiepiloghi = true)
{
    $corpo = [
        'fornitore_id' => $sc->fornitore->id,
        'esercizio_id' => $sc->esercizio->id,
        'gestione_id' => $sc->gestione->id,
        'tipo_documento' => 'fattura',
        'numero_documento' => '26G-'.($conRiepiloghi ? 'XML' : 'MANO'),
        'data_documento' => '2026-06-08',
        'data_scadenza' => '2026-06-29',
        'modalita_pagamento' => 'bonifico',
        'applica_ritenuta' => false,
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => [
            ['descrizione' => 'Altre partite ed oneri', 'importo_imponibile' => 44.35,
                'aliquota_iva' => 0, 'natura' => 'N2.2', 'conto_id' => $sc->conto->id, 'is_sopravvenienza' => false],
            ['descrizione' => 'Spesa per il trasporto e la gestione del contatore', 'importo_imponibile' => 40.61,
                'aliquota_iva' => 22, 'natura' => null, 'conto_id' => $sc->conto->id, 'is_sopravvenienza' => false],
            ['descrizione' => 'Spesa per la materia gas naturale', 'importo_imponibile' => 6.93,
                'aliquota_iva' => 22, 'natura' => null, 'conto_id' => $sc->conto->id, 'is_sopravvenienza' => false],
            ['descrizione' => 'Spesa per Oneri di sistema', 'importo_imponibile' => -1.80,
                'aliquota_iva' => 22, 'natura' => null, 'conto_id' => $sc->conto->id, 'is_sopravvenienza' => false],
        ],
    ];

    if ($conRiepiloghi) {
        $corpo['riepiloghi'] = IVA_DICHIARATA_FILE06;
    }

    return app(FatturaPassivaService::class)->registraFattura($corpo, $sc->condominio->id);
}

/** La somma che il motore di riparto usa come costo reale del capitolo. Query identica al servizio. */
function spesoRealeDelCapitolo(object $sc): int
{
    return (int) DB::table('righe_fattura')
        ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
        ->where('righe_fattura.conto_id', $sc->conto->id)
        ->where('fatture_passive.is_pregresso', false)
        ->selectRaw('COALESCE(SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva), 0) as tot')
        ->value('tot');
}

test('il costo reale che entra nel riparto è quello dichiarato dal documento, non la somma degli arrotondamenti', function () {
    $sc = scenarioConMastri(20_000);
    registraBollettaImportata($sc);

    // 10015, non 10014: è il numero scritto sulla bolletta.
    expect(spesoRealeDelCapitolo($sc))->toBe(10_015);
})->group('riparto', 'beta19');

test('la stessa bolletta digitata a mano vale un centesimo in meno, ed è giusto così', function () {
    // Il controesempio che dà senso al test sopra: se anche questa facesse 10015, il primo
    // test passerebbe senza che la distribuzione c'entri niente.
    $sc = scenarioConMastri(20_000);
    registraBollettaImportata($sc, conRiepiloghi: false);

    expect(spesoRealeDelCapitolo($sc))->toBe(10_014);
})->group('riparto', 'beta19');

test('la quota del proprietario è esattamente il totale della fattura, centesimo compreso', function () {
    // Lo scenario ha un solo immobile con 1.000 millesimi su 1.000: la quota deve essere
    // l'intero costo, senza residui di arrotondamento che possano nascondere il centesimo.
    $sc = scenarioConMastri(20_000);
    $fattura = registraBollettaImportata($sc);

    expect($fattura->totale_documento)->toBe(10_015);

    // L'amministratore allinea il capitolo al costo reale, come prescrive la procedura.
    $sc->conto->forceFill(['importo' => 10_015])->save();

    $piano = PianoRate::create([
        'gestione_id' => $sc->gestione->id, 'condominio_id' => $sc->condominio->id,
        'nome' => 'Rata bolletta gas', 'attivo' => true,
    ]);
    $piano->capitoli()->attach($sc->conto->id, ['importo' => 10_015]);

    $quote = (new CalcoloQuoteService)->calcolaPerGestione($sc->gestione, $piano);
    $totale = collect($quote)->flatMap(fn ($i) => array_values($i))->sum();

    // ⚠️ **Asserzione ASSOLUTA.** Confrontarla con `$fattura->totale_documento` sarebbe
    // tautologico: senza la distribuzione sarebbero sbagliati tutti e due allo stesso modo
    // (10014 contro 10014) e il test passerebbe per la ragione sbagliata. Il metro è il numero
    // che il fornitore ha scritto sul documento.
    expect($totale)->toBe(10_015);
})->group('riparto', 'beta19');

test('la scrittura in partita doppia della fattura importata quadra', function () {
    // Se l'IVA distribuita non tornasse con la testata, DoubleEntryValidator respingerebbe la
    // fattura in registrazione. Qui si verifica il risultato a database, non l'intenzione.
    $sc = scenarioConMastri(20_000);
    $fattura = registraBollettaImportata($sc);

    // `righe_scritture` non ha due colonne dare/avere: ha un `importo` e un `tipo_riga`.
    $perTipo = DB::table('righe_scritture')
        ->join('scritture_contabili', 'righe_scritture.scrittura_id', '=', 'scritture_contabili.id')
        ->where('scritture_contabili.condominio_id', $sc->condominio->id)
        ->groupBy('righe_scritture.tipo_riga')
        ->selectRaw('righe_scritture.tipo_riga, COALESCE(SUM(righe_scritture.importo),0) as tot')
        ->pluck('tot', 'tipo_riga');

    $dare = (int) ($perTipo['dare'] ?? 0);
    $avere = (int) ($perTipo['avere'] ?? 0);

    expect($dare)->toBe($avere);

    // ⚠️ Il totale lordo NON è € 100,15: la riga negativa (−€ 2,20) non si compensa dentro il
    // costo, entra come scrittura di segno opposto e gonfia entrambi i lati. Il numero che
    // corrisponde alla fattura è il **debito verso il fornitore**, ed è quello da pretendere.
    $debitoFornitore = (int) DB::table('righe_scritture')
        ->join('scritture_contabili', 'righe_scritture.scrittura_id', '=', 'scritture_contabili.id')
        ->join('conti_contabili', 'righe_scritture.conto_contabile_id', '=', 'conti_contabili.id')
        ->where('scritture_contabili.condominio_id', $sc->condominio->id)
        ->where('conti_contabili.ruolo', 'debiti_fornitori')
        ->selectRaw("COALESCE(SUM(CASE WHEN righe_scritture.tipo_riga = 'avere' THEN righe_scritture.importo ELSE -righe_scritture.importo END), 0) as tot")
        ->value('tot');

    expect($debitoFornitore)->toBe(10_015);   // il totale del documento, non 10_014

    // E l'IVA delle righe somma esattamente quella di testata.
    expect($fattura->righe->sum('importo_iva'))->toBe($fattura->importo_iva)
        ->and($fattura->importo_iva)->toBe(1_006);
})->group('riparto', 'beta19');
