<?php

/**
 * Cosa resta scoperto: la finestra del portale (ha il suo vitest, `EventDetailsDialogBeta52.test.ts`); la lingua (le frasi del portale sono solo italiane);
 * il rimborso di un credito più grande di quello che la quota ha (la rotta lo rifiuta); MySQL.
 *
 * Il restante del promemoria quando il netto della rata è un credito (zero o negativo) ma una quota è ancora da pagare.
 *
 * Rata 1 di Bice: la quota C è da pagare (+€ 50,00) e il box D è un credito (−€ 75,00), il netto è −€ 25,00. Il credito si può rimborsare per intero: il
 * rimborso non guarda le quote da pagare della stessa persona (`RimborsaCreditoAction`), e dopo il rimborso la quota C deve ancora € 50,00 senza nessun credito
 * a compensarla. Il netto con il segno non dice più che cosa la persona deve: se con il netto ≤ 0 il restante di `EventiRataCondomino::stato()` non
 * salisse mai sopra zero, il portale direbbe «non resta niente da versare né da ricevere» a chi deve ancora € 50,00, e «non devi versare altro» a chi,
 * dopo un rimborso di € 40,00, deve € 15,00.
 *
 * La regola: con il netto ≤ 0, se la parte da pagare supera il credito che la persona ha ancora sulla rata, il restante è la differenza (parte da pagare meno
 * credito) e la persona deve versarlo; altrimenti il restante resta com'era, zero o a credito. Il resto della regola non cambia: da compensare è il minore
 * fra la parte da pagare e il credito, e lo stato è `pending` finché non c'è niente versato né pagato.
 *
 * Un restante positivo riapre la segnalazione del versamento: la guardia di `PaymentReportingController` rifiuta solo ciò che non ha niente da versare.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 */

use App\Enums\EventoTipo;
use App\Models\Evento;
use App\Models\Gestionale\RataQuote;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

const B52M_COMPENSAZIONE_DI_35 = "\n\nIl netto da versare tiene conto di un credito di € 35,00 su un'altra tua quota di questa rata: la compensazione la registra l'amministratore.";

/** Rata 1 di Bice emessa: C +€ 50,00 e D −€ 75,00 (netto −€ 25,00). Torna lo scenario, il piano e gli id delle quote C e D. */
function b52mMisto($test, string $entrata = '900,00'): array
{
    $s = b52tScenario($test, '1.200,00', $entrata);
    $piano = b52tPiano($test, $s);
    b52tApprova($test, $s, $piano);
    b52tEmetti($test, $s, $piano, 1);

    return [$s, $piano, b52tQuotaId($s, $piano, 'bice', 'C', 1), b52tQuotaId($s, $piano, 'bice', 'D', 1)];
}

/** Il rimborso, dalla rotta, di una parte del credito di una quota di Bice. */
function b52mRimborsa($test, array $s, int $quotaId, string $euro): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['persone']['bice']]), [
        'rata_quote_id' => $quotaId, 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-03-06', 'importo' => $euro, 'nota' => '',
    ])->assertSessionHasNoErrors();
}

/** Il promemoria di Bice con il versato e le quote, oltre a ciò che dà `b52tBice()`. */
function b52mStato(array $s, $piano): array
{
    return b52tBice($s, $piano) + ['versato' => b52tEvento($s, $piano, 'bice', 1)->meta['importo_versato'] ?? null];
}

it('M1 il credito di D rimborsato per intero (€ 75,00) mentre C (€ 50,00) è da pagare: restano € 50,00 da versare, il promemoria è «pending» e la frase è quella della scadenza', function () {
    [$s, $piano, $qC, $qD] = b52mMisto($this);

    b52mRimborsa($this, $s, $qD, '75,00');

    expect(b52tQuote($s, $piano, 'bice', 1))->toBe([['C', 5000, 0, 'da_pagare'], ['D', -7500, -7500, 'pagata']]); // la quota C deve ancora € 50,00
    $b = b52mStato($s, $piano);
    expect([$b['status'], $b['originale'], $b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe(['pending', -2500, -7500, 0, 5000, 0]);
    expect($b['descrizione'])->toBe(B52T_SCADENZA)->not->toContain('niente da versare né da ricevere');
});

it('M2 un rimborso di € 40,00 dei € 75,00 di D: C deve € 50,00, il credito è € 35,00, restano € 15,00 da versare e € 35,00 da compensare', function () {
    [$s, $piano, , $qD] = b52mMisto($this);

    b52mRimborsa($this, $s, $qD, '40,00');

    $b = b52mStato($s, $piano);
    expect([$b['status'], $b['originale'], $b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe(['pending', -2500, -4000, 0, 1500, 3500]);
    expect($b['descrizione'])->toBe(B52T_SCADENZA . B52M_COMPENSAZIONE_DI_35)->not->toContain('non devi versare altro');
});

it('M3 controprova: dopo il rimborso per intero l\'incasso di C passa e chiude la rata: «paid», restante zero, versato € 50,00', function () {
    [$s, $piano, $qC, $qD] = b52mMisto($this);
    b52mRimborsa($this, $s, $qD, '75,00');

    b52tIncasso($this, $s, 'bice', [$qC => 5000], 5000);

    expect((int) RataQuote::find($qC)->importo_pagato)->toBe(5000);
    $b = b52mStato($s, $piano);
    expect([$b['status'], $b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe(['paid', -2500, 5000, 0, 0]);
});

it('M3b un versamento parziale dopo il rimborso: € 20,00 su C, restano € 30,00 da versare, «partial» e la frase della scadenza', function () {
    [$s, $piano, $qC, $qD] = b52mMisto($this);
    b52mRimborsa($this, $s, $qD, '75,00');

    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);

    $b = b52mStato($s, $piano);
    expect([$b['status'], $b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe(['partial', -5500, 2000, 3000, 0]);
    expect($b['descrizione'])->toBe(B52T_SCADENZA);
});

it('M4 netto zero: C +€ 50,00 e D −€ 50,00, il credito di D rimborsato per intero: C deve € 50,00, restano € 50,00 da versare', function () {
    [$s, $piano, , $qD] = b52mMisto($this, '600,00');
    expect(b52tQuote($s, $piano, 'bice', 1))->toBe([['C', 5000, 0, 'da_pagare'], ['D', -5000, 0, 'credito']]);

    b52mRimborsa($this, $s, $qD, '50,00');

    expect(b52tQuote($s, $piano, 'bice', 1))->toBe([['C', 5000, 0, 'da_pagare'], ['D', -5000, -5000, 'pagata']]);
    $b = b52mStato($s, $piano);
    expect([$b['status'], $b['originale'], $b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe(['pending', 0, -5000, 0, 5000, 0]);
});

it('controprova M5: il misto appena emesso, senza rimborsi, resta a credito di € 25,00 con € 50,00 in attesa della compensazione', function () {
    [$s, $piano] = b52mMisto($this);

    $b = b52mStato($s, $piano);

    expect([$b['status'], $b['originale'], $b['pagato'], $b['restante'], $b['da_compensare']])->toBe(['partial', -2500, 0, -2500, 5000]);
});

it('controprova M6: un rimborso che lascia il credito sopra la parte da pagare (€ 20,00 di € 75,00) non cambia il restante: a credito di € 5,00, € 50,00 da compensare', function () {
    [$s, $piano, , $qD] = b52mMisto($this);

    b52mRimborsa($this, $s, $qD, '20,00');

    $b = b52mStato($s, $piano);
    expect([$b['status'], $b['originale'], $b['pagato'], $b['restante'], $b['da_compensare']])->toBe(['partial', -2500, -2000, -500, 5000]);
});

it('controprova M7: il netto zero senza rimborsi (C +€ 50,00 e D −€ 50,00) resta a restante zero, in attesa della compensazione di € 50,00', function () {
    [$s, $piano] = b52mMisto($this, '600,00');

    $b = b52mStato($s, $piano);

    expect([$b['status'], $b['originale'], $b['pagato'], $b['restante'], $b['da_compensare']])->toBe(['partial', 0, 0, 0, 5000]);
});

it('M8 chi deve ancora € 50,00 dopo il rimborso può segnalare il versamento: la guardia non lo rifiuta e il compito dell\'amministratore è per € 50,00', function () {
    [$s, $piano, , $qD] = b52mMisto($this);
    b52mRimborsa($this, $s, $qD, '75,00');
    $utente = b52tUtentePortale($s, 'bice');
    $evento = b52tEvento($s, $piano, 'bice', 1);

    $this->actingAs($utente)->post(route('user.eventi.report_payment', $evento))->assertRedirect()->assertSessionHasNoErrors();

    expect($evento->fresh()->meta['status'])->toBe('reported');
    $compito = Evento::where('tipo', EventoTipo::VERIFICA_PAGAMENTO->value)->firstOrFail();
    expect($compito->meta['importo_dichiarato'])->toBe(5000);
});
