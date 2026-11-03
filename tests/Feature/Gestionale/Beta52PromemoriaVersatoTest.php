<?php

/**
 * Cosa resta scoperto: il portale montato (la finestra ha il suo vitest, `EventDetailsDialogBeta52.test.ts`); la lingua (le frasi del portale sono solo
 * italiane); MySQL.
 *
 * Il «versato» del promemoria: il contante che la persona ha dato sulle sue quote da pagare, e solo quello.
 *
 * `pagato` è il netto con il segno: un credito usato su un'altra rata o restituito lo riduce. Letto come «versato» faceva dire al portale «hai versato
 * € 15,00» a chi aveva versato € 20,00, dopo che € 5,00 del suo credito erano stati usati altrove o restituiti. `EventiRataCondomino::stato()` porta
 * allora due numeri: `pagato` per i conti (restante, stato, da compensare) e `versato` per le frasi. Il versato è la somma di `quota_scrittura.importo_pagato`
 * sulle quote a importo positivo della persona su quella rata, senza le scritture `storno_credito` (la compensazione con un credito: un credito usato su
 * una quota non è contante) e senza le loro rettifiche (lo storno della compensazione e il riallineamento dei crediti); l'incasso, il suo storno e il
 * rimborso di uno strapagamento restano dentro. Non la somma delle quote positive: «Usa credito» scrive il suo importo positivo sulla quota da pagare, e
 * contarlo come versato direbbe «hai versato € 25,00» a chi ne ha versati € 20,00.
 *
 * Il meta porta `importo_versato`, e `applica()` lo ricostruisce nello stato «di prima»: il testo scritto dal sistema si riconosce ancora. Un promemoria
 * salvato prima non ha la chiave, e vale il pagato, che allora era l'unico numero e quello che la frase leggeva.
 *
 * Lo stato legge il versato: `partial` se la persona ha versato contante, anche quando il pagato netto è zero o negativo (un credito uscito dalla rata lo ha
 * ridotto); `pending` solo se non ha versato niente e il pagato non è positivo. Così l'etichetta del portale e la frase «hai versato» dicono la stessa cosa.
 *
 * Limite dichiarato: un versamento fatto da un altro per conto della persona («Versato da» nell'incasso) è contante sulle sue quote e resta contato come
 * suo; il portale non distingue chi ha messo i soldi.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 */

use App\Models\Anagrafica;
use App\Models\Evento;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\EventiRataCondomino;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

const B52V_COMPENSAZIONE = " di una tua quota aspettano la compensazione con un tuo credito su un'altra tua quota di questa rata: la registra l'amministratore.";

/** Il promemoria di Bice con il versato, oltre a ciò che dà `b52tBice()`. */
function b52vBice(array $s, $piano, int $n = 1): array
{
    return b52tBice($s, $piano, $n) + ['versato' => b52tEvento($s, $piano, 'bice', $n)->meta['importo_versato'] ?? null];
}

/** Il rimborso, dalla rotta, di una parte del credito di una quota di Bice. */
function b52vRimborsa($test, array $s, int $quotaId, string $euro): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['persone']['bice']]), [
        'rata_quote_id' => $quotaId, 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-03-06', 'importo' => $euro, 'nota' => '',
    ])->assertSessionHasNoErrors();
}

function b52vStorna($test, array $s, ScritturaContabile $incasso): void
{
    $test->actingAs($test->user)->post("/admin/gestionale/{$s['c']->id}/movimenti-rate/{$incasso->id}/storno")->assertSessionHasNoErrors();
}

// ── Il credito usato altrove o restituito prima del versamento ───────────────────────────────────────────────────

it('V1 K3, € 5,00 del credito di D usati sulla rata 2 e poi € 20,00 su C: «hai versato € 20,00», il pagato netto resta € 15,00', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52tEmetti($this, $s, $piano, 2);
    $qC2 = b52tQuotaId($s, $piano, 'bice', 'C', 2);
    b52tIncasso($this, $s, 'bice', [$qD => -500, $qC2 => 500], 0); // credito usato su un'altra rata: nessun contante torna a Bice
    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);

    $b = b52vBice($s, $piano);

    expect([$b['status'], $b['originale'], $b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe(['partial', 4000, 1500, 2000, 2500, 500]);
    expect($b['descrizione'])->toBe('Gentile Bice, per la rata n. 1 hai versato € 20,00 e restano € 25,00 da versare. Altri € 5,00' . B52V_COMPENSAZIONE);
});

it('V2 K3, rimborso di € 5,00 del credito di D e poi € 20,00 su C: «hai versato € 20,00», non € 15,00', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52vRimborsa($this, $s, $qD, '5,00');
    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);

    $b = b52vBice($s, $piano);

    expect([$b['status'], $b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe(['partial', 1500, 2000, 2500, 500]);
    expect($b['descrizione'])->toBe('Gentile Bice, per la rata n. 1 hai versato € 20,00 e restano € 25,00 da versare. Altri € 5,00' . B52V_COMPENSAZIONE);
});

it('V3 il rimborso dopo il versamento del netto: versato € 40,00, pagato € 30,00, restano € 10,00 da versare', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);

    b52vRimborsa($this, $s, $qD, '10,00');

    $b = b52vBice($s, $piano);
    expect([$b['status'], $b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe(['partial', 3000, 4000, 1000, 0]);
});

it('V4 il credito di D usato su un\'altra rata, dopo il versamento di € 40,00: la rata 1 ha versato € 40,00 e il pagato scende a € 30,00', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52tEmetti($this, $s, $piano, 2);
    $qC2 = b52tQuotaId($s, $piano, 'bice', 'C', 2);
    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);

    b52tIncasso($this, $s, 'bice', [$qD => -1000, $qC2 => 1000], 0);

    $b = b52vBice($s, $piano);
    expect([$b['status'], $b['pagato'], $b['versato'], $b['restante']])->toBe(['partial', 3000, 4000, 1000]);
});

// ── «Usa credito» sulla stessa rata non è contante ─────────────────────────────────────────────────────────────────

it('V5 € 20,00 in contanti su C e poi «Usa credito» per € 5,00 sulla stessa quota: «hai versato € 20,00», non € 25,00', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);

    b52tIncasso($this, $s, 'bice', [$qD => -500, $qC => 500], 0);

    $b = b52vBice($s, $piano);
    // la quota C ha pagato € 25,00 (contante e credito), ma il contante è € 20,00: sommare le quote positive direbbe «hai versato € 25,00»
    expect((int) RataQuote::find($qC)->importo_pagato)->toBe(2500);
    expect([$b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe([2000, 2000, 2000, 500]);
    expect($b['descrizione'])->toBe('Gentile Bice, per la rata n. 1 hai versato € 20,00 e restano € 20,00 da versare. Altri € 5,00' . B52V_COMPENSAZIONE);
});

it('V6 lo storno di «Usa credito» non tocca il versato: € 20,00 prima e dopo (la rettifica della compensazione non è contante)', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);
    b52tIncasso($this, $s, 'bice', [$qD => -500, $qC => 500], 0);
    $usaCredito = ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();

    b52vStorna($this, $s, $usaCredito);

    $b = b52vBice($s, $piano);
    expect([$b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe([2000, 2000, 2000, 1000]);
    expect($b['descrizione'])->toBe('Gentile Bice, per la rata n. 1 hai versato € 20,00 e restano € 20,00 da versare. Altri € 10,00' . B52V_COMPENSAZIONE);
});

it('V7 lo storno del versamento riporta il versato a zero e il promemoria alla creazione', function () {
    [$s, $piano, $qC] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);
    $incasso = ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();

    b52vStorna($this, $s, $incasso);

    $b = b52vBice($s, $piano);
    expect([$b['status'], $b['pagato'], $b['versato'], $b['restante']])->toBe(['pending', 0, 0, 4000]);
    expect($b['descrizione'])->toBe(B52T_SCADENZA . B52T_NETTO_CON_CREDITO);
});

// ── Controprove: dove versato e pagato coincidono ────────────────────────────────────────────────────────────────

it('controprova V8: € 20,00 su C senza credito usato né rimborsato: versato e pagato sono € 20,00, e la frase è quella di sempre', function () {
    [$s, $piano, $qC] = b52tK3($this);

    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);

    $b = b52vBice($s, $piano);
    expect([$b['pagato'], $b['versato']])->toBe([2000, 2000]);
    expect($b['descrizione'])->toBe('Gentile Bice, per la rata n. 1 hai versato € 20,00 e restano € 20,00 da versare. Altri € 10,00' . B52V_COMPENSAZIONE);
});

it('controprova V9: dopo «Usa credito» sul netto di € 40,00 la rata è pagata, e il versato è € 40,00', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);

    b52tIncasso($this, $s, 'bice', [$qD => -1000, $qC => 1000], 0);

    $b = b52vBice($s, $piano);
    expect([$b['status'], $b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe(['paid', 4000, 4000, 0, 0]);
});

it('controprova V10: l\'incasso di Bice non cambia il promemoria di Ugo, versato compreso', function () {
    [$s, $piano, $qC] = b52tK3($this);
    $ugoPrima = b52tEvento($s, $piano, 'ugo', 1)->meta;

    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);

    expect(b52tEvento($s, $piano, 'ugo', 1)->meta)->toBe($ugoPrima);
    expect(b52vBice($s, $piano)['versato'])->toBe(2000);
});

it('V11 la pubblicazione silenziosa scrive il versato come l\'incasso', function () {
    [$s, $piano, $qC] = b52tK3($this, notifiche: false);
    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);
    $evento = b52tEvento($s, $piano, 'bice', 1);
    $meta = $evento->meta;
    unset($meta['importo_versato']);
    $evento->update(['meta' => array_merge($meta, ['status' => 'pending', 'importo_pagato' => 0, 'importo_restante' => 4000])]);

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.publish-silent', [$s['c'], $s['e'], $piano]))->assertSessionHasNoErrors();

    expect(b52vBice($s, $piano)['versato'])->toBe(2000);
});

it('V12 un promemoria nuovo nasce con versato zero, e un piano approvato lo scrive in ogni promemoria', function () {
    [$s, $piano] = b52tK3($this, emetti: false);

    expect(b52tEvento($s, $piano, 'bice', 1)->meta['importo_versato'])->toBe(0)
        ->and(b52tEvento($s, $piano, 'ugo', 1)->meta['importo_versato'])->toBe(0);
});

// ── Il limite dichiarato: il versamento di un altro per conto della persona ──────────────────────────────────

it('limite V13 un versamento fatto da Ugo per conto di Bice («Versato da») resta contato come versato di Bice: il portale non distingue chi ha messo i soldi', function () {
    [$s, $piano, $qC] = b52tK3($this);

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['bice']->id, 'versato_da_id' => $s['persone']['ugo']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-05',
        'importo_totale' => b52tEuro(2000), 'descrizione' => 'Bonifico di Ugo', 'eccedenza' => b52tEuro(0),
        'dettaglio_pagamenti' => [['rata_id' => $qC, 'importo' => b52tEuro(2000)]],
    ])->assertSessionHasNoErrors();

    $b = b52vBice($s, $piano);
    expect($b['versato'])->toBe(2000);
    expect($b['descrizione'])->toContain('hai versato € 20,00');
});

// ── applica(): il testo scritto dal sistema si riconosce con il versato ricostruito ──────────────────────────────

it('applica() ricostruisce lo stato di prima con il versato del meta: la frase che dice «hai versato € 20,00» si riconosce e si riscrive', function () {
    $servizio = app(EventiRataCondomino::class);
    $persona = (new Anagrafica)->forceFill(['nome' => 'Bice']);
    $rata = (new Rata)->forceFill(['numero_rata' => 1, 'note' => null]);
    $scrittaDalSistema = 'Gentile Bice, per la rata n. 1 hai versato € 20,00 e restano € 25,00 da versare. Altri € 5,00' . B52V_COMPENSAZIONE;
    $evento = (new Evento)->forceFill([
        'description' => $scrittaDalSistema,
        'meta' => ['importo_originale' => 4000, 'importo_pagato' => 1500, 'importo_versato' => 2000, 'importo_restante' => 2500, 'da_compensare' => 500, 'status' => 'partial'],
    ]);

    // lo storno del rimborso: il credito torna, il pagato sale a € 20,00 e il versato resta € 20,00
    $servizio->applica($evento, ['originale' => 4000, 'pagato' => 2000, 'versato' => 2000, 'restante' => 2000, 'da_compensare' => 1000, 'status' => 'partial'], $persona, $rata);

    expect($evento->description)->toBe('Gentile Bice, per la rata n. 1 hai versato € 20,00 e restano € 20,00 da versare. Altri € 10,00' . B52V_COMPENSAZIONE);
    expect($evento->meta['importo_versato'])->toBe(2000);
});

it('controprova: un testo ritoccato a mano non si riscrive nemmeno con il versato, ma il versato nel meta si aggiorna', function () {
    $servizio = app(EventiRataCondomino::class);
    $persona = (new Anagrafica)->forceFill(['nome' => 'Bice']);
    $rata = (new Rata)->forceFill(['numero_rata' => 1, 'note' => null]);
    $ritoccato = "Gentile Bice, per la rata n. 1 hai versato € 20,00: grazie (nota dell'amministratore).";
    $evento = (new Evento)->forceFill([
        'description' => $ritoccato,
        'meta' => ['importo_originale' => 4000, 'importo_pagato' => 1500, 'importo_versato' => 2000, 'importo_restante' => 2500, 'da_compensare' => 500, 'status' => 'partial'],
    ]);

    $servizio->applica($evento, ['originale' => 4000, 'pagato' => 2000, 'versato' => 2500, 'restante' => 2000, 'da_compensare' => 1000, 'status' => 'partial'], $persona, $rata);

    expect($evento->description)->toBe($ritoccato);
    expect($evento->meta['importo_versato'])->toBe(2500);
});

it('un promemoria salvato prima, senza `importo_versato`, ha come versato di prima il pagato: la frase di allora si riconosce e si riscrive con il versato nuovo', function () {
    $servizio = app(EventiRataCondomino::class);
    $persona = (new Anagrafica)->forceFill(['nome' => 'Bice']);
    $rata = (new Rata)->forceFill(['numero_rata' => 1, 'note' => null]);
    // la frase come la scriveva il sistema quando il versato era il pagato: € 20,00 pagati, restano € 20,00
    $dellEpoca = 'Gentile Bice, per la rata n. 1 hai versato € 20,00 e restano € 20,00 da versare. Altri € 10,00' . B52V_COMPENSAZIONE;
    $evento = (new Evento)->forceFill([
        'description' => $dellEpoca,
        'meta' => ['importo_originale' => 4000, 'importo_pagato' => 2000, 'importo_restante' => 2000, 'da_compensare' => 1000, 'status' => 'partial'],
    ]);

    $servizio->applica($evento, ['originale' => 4000, 'pagato' => 1500, 'versato' => 2000, 'restante' => 2500, 'da_compensare' => 500, 'status' => 'partial'], $persona, $rata);

    expect($evento->description)->toBe('Gentile Bice, per la rata n. 1 hai versato € 20,00 e restano € 25,00 da versare. Altri € 5,00' . B52V_COMPENSAZIONE);
    expect($evento->meta['importo_versato'])->toBe(2000);
});

it('la frase non dice «hai versato» a chi non ha versato contante: il credito di un\'altra rata usato qui è pagato, non versato', function () {
    $servizio = app(EventiRataCondomino::class);
    $persona = (new Anagrafica)->forceFill(['nome' => 'Bice']);
    $rata = (new Rata)->forceFill(['numero_rata' => 2, 'note' => null]);

    $testo = $servizio->descrizione($persona, $rata, ['originale' => 5000, 'pagato' => 1000, 'versato' => 0, 'restante' => 4000, 'da_compensare' => 500, 'status' => 'partial']);

    expect($testo)->not->toContain('hai versato')->toContain('Il netto da versare tiene conto di un credito di € 5,00');
});

// ── Lo stato legge il versato ────────────────────────────────────────────────────────────────────────────────────

it('V14 K3, rimborso di € 5,00 del credito di D e poi € 5,00 in contanti su C: il pagato netto è zero ma la persona ha versato, quindi «partial» e «hai versato € 5,00»', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52vRimborsa($this, $s, $qD, '5,00');
    b52tIncasso($this, $s, 'bice', [$qC => 500], 500);

    $b = b52vBice($s, $piano);

    expect([$b['status'], $b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe(['partial', 0, 500, 4000, 500]);
    expect($b['descrizione'])->toBe('Gentile Bice, per la rata n. 1 hai versato € 5,00 e restano € 40,00 da versare. Altri € 5,00' . B52V_COMPENSAZIONE);
});

it('controprova V15: con il credito rimborsato e niente versato il promemoria resta «pending», e la frase non dice «hai versato»', function () {
    [$s, $piano, , $qD] = b52tK3($this);

    b52vRimborsa($this, $s, $qD, '5,00');

    $b = b52vBice($s, $piano);
    expect([$b['status'], $b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe(['pending', -500, 0, 4500, 500]);
    expect($b['descrizione'])->not->toContain('hai versato');
});

it('V16 rimborso di € 5,00 e poi € 1,00 su C: pagato −400, versato 100, restante 4400', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52vRimborsa($this, $s, $qD, '5,00');

    b52tIncasso($this, $s, 'bice', [$qC => 100], 100);

    $b = b52vBice($s, $piano);
    expect([$b['status'], $b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe(['partial', -400, 100, 4400, 500]);
});

it('V17 lo storno del rimborso dopo € 40,00 versati riporta pagato e versato a € 40,00: restante zero e € 10,00 da compensare', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);
    b52vRimborsa($this, $s, $qD, '10,00');
    $rimborso = ScritturaContabile::where('tipo_movimento', 'rimborso_condomino')->latest('id')->firstOrFail();

    b52vStorna($this, $s, $rimborso);

    $b = b52vBice($s, $piano);
    expect([$b['status'], $b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe(['partial', 4000, 4000, 0, 1000]);
});

it('V18 uno strapagamento di € 10,00 su C e il rimborso dello strapagamento: il versato scende come il pagato, da € 60,00 a € 50,00', function () {
    [$s, $piano, $qC] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 5000], 6000, 1000);
    $prima = b52vBice($s, $piano);

    b52vRimborsa($this, $s, $qC, '10,00');

    $dopo = b52vBice($s, $piano);
    expect([$prima['pagato'], $prima['versato']])->toBe([6000, 6000]);
    expect([$dopo['pagato'], $dopo['versato'], $dopo['restante']])->toBe([5000, 5000, 0]);
});

// ── Il credito che resta, e il credito di un comproprietario ───────────────────────────────────────────────────

it('V19 Bice versa € 50,00 su C senza usare il credito di D: la rata è pagata, restante zero, e a D restano € 10,00 di credito (la finestra li mostra come «Credito ancora a tuo favore»)', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);

    b52tIncasso($this, $s, 'bice', [$qC => 5000], 5000);

    $b = b52vBice($s, $piano);
    expect([$b['status'], $b['originale'], $b['pagato'], $b['versato'], $b['restante'], $b['da_compensare']])->toBe(['paid', 4000, 5000, 5000, 0, 0]);
    expect((int) RataQuote::find($qD)->credito_disponibile)->toBe(1000);
    expect($b['pagato'] - $b['originale'])->toBe(1000); // quanto il pagato supera il netto: ciò che la riga del credito aggiunge alla colonna
});

it('V20 il credito di Ugo (uno strapagamento su A) usato da Bice, che condivide A, su C della stessa rata: per Bice il pagato supera il versato, per Ugo il versato supera il pagato', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    $qA = b52tQuotaId($s, $piano, 'ugo', 'A', 1);
    b52tIncasso($this, $s, 'ugo', [$qA => 5000], 6000, 1000);                 // Ugo versa € 60,00 su A (€ 50,00): € 10,00 di strapagamento
    b52tIncasso($this, $s, 'bice', [$qD => -1000, $qC => 1000], 0);            // il credito di Bice sulla rata 1 è finito
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['bice']->id, 'immobile_id' => $s['unita']['A']->id, 'tipologia' => 'proprietario', 'quota' => 50,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    // Bice, che condivide A, usa il credito di Ugo su C: la stessa rata, il credito è di un altro
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['bice']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-05', 'importo_totale' => b52tEuro(0),
        'descrizione' => 'Credito del comproprietario', 'eccedenza' => b52tEuro(0),
        'dettaglio_pagamenti' => [['rata_id' => $qA, 'importo' => b52tEuro(-1000)], ['rata_id' => $qC, 'importo' => b52tEuro(1000)]],
    ])->assertSessionHasNoErrors();

    $bice = b52tEvento($s, $piano, 'bice', 1)->meta;
    $ugo = b52tEvento($s, $piano, 'ugo', 1)->meta;
    expect($bice['importo_pagato'] - $bice['importo_versato'])->toBe(1000);
    expect($ugo['importo_versato'] - $ugo['importo_pagato'])->toBe(1000);
});
