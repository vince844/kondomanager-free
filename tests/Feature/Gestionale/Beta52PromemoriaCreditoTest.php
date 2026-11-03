<?php

/**
 * Cosa resta scoperto: il portale montato (la finestra e l'elenco hanno i loro vitest); la lingua (le frasi del portale sono solo italiane,
 * come prima); il credito di una rata diversa o di un comproprietario che l'amministratore può usare comunque (da compensare conta il credito
 * della stessa rata, il solo che il netto del promemoria ha già sottratto); il rimborso parziale di un credito a più quote; MySQL.
 *
 * Il promemoria del portale per una quota a credito: un calcolo solo per i quattro scrittori.
 *
 * Lo stesso numero, `importo_restante` del promemoria, lo scrivevano quattro punti con due regole: la creazione, «segui le quote» di
 * un passaggio e la pubblicazione silenziosa sul netto della persona sulla rata; `allineaPagato`, dopo un incasso o uno storno, solo
 * sulle quote positive. Scenario K3: Bice ha l'unità C da € 50,00 e il box D da −€ 10,00 sulla stessa rata, il netto è € 40,00.
 * Versa € 40,00 su C e l'amministratore non usa «Usa credito»: il promemoria dice «restano € 10,00», e chiede un secondo bonifico.
 *
 * La regola (`EventiRataCondomino::stato`): originale = somma con il segno delle quote attive (non annullate) della persona sulla
 * rata; pagato = somma con il segno di quanto pagato; restante = originale meno pagato, mai sotto zero con il netto positivo; con il netto a
 * credito (zero o negativo) mai sopra zero finché il credito della persona sulla rata copre la parte da pagare, e quando non la copre più la parte
 * da pagare meno il credito (il caso è in `Beta52PromemoriaMistoTest`); da compensare = il minore fra il residuo delle quote positive e il credito
 * disponibile della persona sulla stessa rata. Lo stato è «paid» se ogni quota positiva è pagata, «partial» se la persona ha versato contante, se
 * qualcosa è pagato o se il netto è già coperto, altrimenti «pending» (il versato è in `Beta52PromemoriaVersatoTest`). «In attesa della
 * compensazione» è `partial` con restante zero (o credito) e da compensare maggiore di zero (decisione 87.4).
 *
 * Il credito speso o restituito cambia il netto della rata come un incasso: dopo «Usa credito» e dopo un rimborso il promemoria si riallinea
 * (`allineaPagato` gira anche per le quote a credito che la scrittura ha toccato), e lo stesso vale per lo storno dell'uno e dell'altro. Un
 * promemoria segnalato (`reported`) dalla persona resta segnalato: si aggiornano i numeri, non lo stato.
 *
 * Il testo di un credito puro salvato dalle versioni passate (la forma della 1.8.0 «… (es. avanzo esercizio precedente)» e quella con la rata) è
 * testo scritto dal sistema, con la frase che la migrazione ha messo al posto di quella di allora e con quella del credito già usato: quando il
 * credito si usa o si restituisce `applica()` lo riscrive, come riscrive la forma di adesso. La frase di allora non c'è nel codice dell'app (nessuna
 * frase del prodotto promette la compensazione automatica, `Beta52TotaliSorgenteTest`): la sostituisce la migrazione. Una descrizione ritoccata a mano
 * non si tocca mai.
 *
 * Le frasi (al presente, € prima della cifra) sono nello scenario, alla lettera: sono il prodotto. Quelle che dicono «la compensazione la registra
 * l'amministratore» si firmano con la clausola che la applica: l'unico punto che consuma un credito a fronte di una quota è `StoreIncassoRateAction`
 * (`attach` con `importo_pagato` negativo, `:620`, dentro `execute`, dietro la rotta dell'amministratore); il rimborso lo consuma restituendolo
 * (`RimborsaCreditoAction.php:155`). Le frasi dicono «può» e «la registra l'amministratore» e non promettono una scadenza né un «solo»: il credito di
 * un'altra rata o di un comproprietario l'amministratore lo può usare comunque, e il portale non lo conta.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 */

use App\Models\Anagrafica;
use App\Models\Evento;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
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

// ── La creazione ─────────────────────────────────────────────────────────────────────────────────────────────────

it('T2.1a un credito puro: «paid» e −2000 come prima, e la frase non promette più la compensazione automatica', function () {
    $s = b52tScenario($this, '1.200,00', '240,00', soloEntrata: true);
    $piano = b52tPiano($this, $s);
    b52tApprova($this, $s, $piano);

    $b = b52tBice($s, $piano);

    expect($b['descrizione'])->not->toContain('automaticamente')->not->toContain('verrà utilizzato');
    expect($b)->toBe(['status' => 'paid', 'originale' => -2000, 'pagato' => 0, 'restante' => -2000, 'da_compensare' => 0, 'descrizione' => B52T_CREDITO_PURO]);
});

it('T2.1b K3 alla creazione: da versare € 40,00, e la frase dice che il netto tiene conto del credito e chi lo compensa', function () {
    [$s, $piano] = b52tK3($this, emetti: false);

    expect(b52tBice($s, $piano))->toBe(['status' => 'pending', 'originale' => 4000, 'pagato' => 0, 'restante' => 4000, 'da_compensare' => 1000, 'descrizione' => B52T_SCADENZA . B52T_NETTO_CON_CREDITO]);
});

it('T2.1c chi non ha crediti sulla rata riceve la frase di sempre e da compensare zero', function () {
    [$s, $piano] = b52tK3($this, emetti: false);

    $e = b52tEvento($s, $piano, 'ugo', 1);

    expect($e->meta['status'])->toBe('pending');
    expect([$e->meta['importo_restante'], $e->meta['da_compensare'] ?? null])->toBe([5000, 0]);
    expect($e->description)->toBe(str_replace('Bice', 'Ugo', B52T_SCADENZA));
});

// ── Dopo un incasso ──────────────────────────────────────────────────────────────────────────────────────────────

it('T2.2 K3 dopo € 40,00 su C senza «Usa credito»: nulla da versare, in attesa della compensazione di € 10,00', function () {
    [$s, $piano, $qC] = b52tK3($this);

    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);

    expect(b52tBice($s, $piano))->toBe(['status' => 'partial', 'originale' => 4000, 'pagato' => 4000, 'restante' => 0, 'da_compensare' => 1000,
        'descrizione' => "Gentile Bice, per la rata n. 1 hai versato quanto richiesto. Restano € 10,00 di una tua quota da compensare con un tuo credito su un'altra tua quota di questa rata: la compensazione la registra l'amministratore."]);
    expect(app(EventiRataCondomino::class)->rataSaldata(Rata::find(b52tRata($piano, 1))))->toBeFalse(); // la verifica incassi resta aperta: il portale è concorde
});

it('T2.2b dopo «Usa credito» la rata è pagata, e il pagato è il netto di € 40,00, non € 50,00', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);

    b52tIncasso($this, $s, 'bice', [$qD => -1000, $qC => 1000], 0);

    $b = b52tBice($s, $piano);
    expect($b)->toMatchArray(['status' => 'paid', 'originale' => 4000, 'pagato' => 4000, 'restante' => 0, 'da_compensare' => 0]);
    expect($b['descrizione'])->toBe(B52T_SCADENZA);
    expect(b52tQuote($s, $piano, 'bice', 1))->toBe([['C', 5000, 5000, 'pagata'], ['D', -1000, -1000, 'pagata']]);
});

it('T2.2c lo storno dell\'incasso da € 40,00 riporta il promemoria alla creazione: da versare € 40,00 e la frase di allora', function () {
    [$s, $piano, $qC] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);
    $incasso = App\Models\Gestionale\ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();

    $this->actingAs($this->user)->post("/admin/gestionale/{$s['c']->id}/movimenti-rate/{$incasso->id}/storno")->assertSessionHasNoErrors();

    expect(b52tBice($s, $piano))->toBe(['status' => 'pending', 'originale' => 4000, 'pagato' => 0, 'restante' => 4000, 'da_compensare' => 1000, 'descrizione' => B52T_SCADENZA . B52T_NETTO_CON_CREDITO]);
});

it('T2.2d il caso intermedio, pagato meno del netto: € 20,00 su C, restano € 20,00 da versare e € 10,00 da compensare', function () {
    [$s, $piano, $qC] = b52tK3($this);

    b52tIncasso($this, $s, 'bice', [$qC => 2000], 2000);

    expect(b52tBice($s, $piano))->toBe(['status' => 'partial', 'originale' => 4000, 'pagato' => 2000, 'restante' => 2000, 'da_compensare' => 1000,
        'descrizione' => "Gentile Bice, per la rata n. 1 hai versato € 20,00 e restano € 20,00 da versare. Altri € 10,00 di una tua quota aspettano la compensazione con un tuo credito su un'altra tua quota di questa rata: la registra l'amministratore."]);
});

it('T2.3 controprova: una persona con una sola quota positiva, € 20,00 su € 50,00, è «partial» con restante € 30,00 e la frase di sempre, come prima', function () {
    [$s, $piano] = b52tK3($this);
    $qA = b52tQuotaId($s, $piano, 'ugo', 'A', 1);

    b52tIncasso($this, $s, 'ugo', [$qA => 2000], 2000);

    $e = b52tEvento($s, $piano, 'ugo', 1);
    expect([$e->meta['status'], $e->meta['importo_pagato'], $e->meta['importo_restante']])->toBe(['partial', 2000, 3000]); // invariato
    expect($e->description)->toBe(str_replace('Bice', 'Ugo', B52T_SCADENZA));                                           // nessun credito: nessuna frase nuova
});

it('T2.3b chi non ha crediti sulla rata ha comunque la chiave `da_compensare`, a zero, dopo un incasso parziale', function () {
    [$s, $piano] = b52tK3($this);
    $qA = b52tQuotaId($s, $piano, 'ugo', 'A', 1);

    b52tIncasso($this, $s, 'ugo', [$qA => 2000], 2000);

    expect(b52tEvento($s, $piano, 'ugo', 1)->meta['da_compensare'] ?? null)->toBe(0);
});

it('T2.4 controprova: l\'incasso di Bice non tocca il promemoria di Ugo, nemmeno una chiave del meta (nessun dato altrui)', function () {
    [$s, $piano, $qC] = b52tK3($this);
    $prima = b52tEvento($s, $piano, 'ugo', 1)->meta;

    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);

    expect(b52tEvento($s, $piano, 'ugo', 1)->meta)->toBe($prima);
});

// ── Il credito speso altrove, rimborsato, il caso misto ──────────────────────────────────────────────────────

it('T2.5 il rimborso del credito del box dopo il versamento del netto: restano € 10,00 da versare e nulla da compensare', function () {
    [$s, $piano, $qC] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);
    $quotaD = RataQuote::find(b52tQuotaId($s, $piano, 'bice', 'D', 1));

    $this->actingAs($this->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['persone']['bice']]), [
        'rata_quote_id' => $quotaD->id, 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-03-06', 'importo' => '10,00', 'nota' => '',
    ])->assertSessionHasNoErrors();

    $b = b52tBice($s, $piano);
    expect($b)->toMatchArray(['status' => 'partial', 'originale' => 4000, 'pagato' => 3000, 'restante' => 1000, 'da_compensare' => 0]);
    expect($b['descrizione'])->toBe(B52T_SCADENZA); // il credito non c'è più: la frase torna quella di una rata da pagare
});

it('T2.5b il credito del box speso su un\'altra rata: sulla prima restano da versare € 50,00 e nulla da compensare', function () {
    [$s, $piano, , $qD1] = b52tK3($this);
    b52tEmetti($this, $s, $piano, 2);
    $qC2 = b52tQuotaId($s, $piano, 'bice', 'C', 2);

    // l'amministratore usa il credito del box della rata 1 (€ 10,00) per pagare € 10,00 della quota C della rata 2
    b52tIncasso($this, $s, 'bice', [$qD1 => -1000, $qC2 => 1000], 0);

    expect(b52tBice($s, $piano, 1))->toMatchArray(['status' => 'pending', 'originale' => 4000, 'pagato' => -1000, 'restante' => 5000, 'da_compensare' => 0]);
});

it('T2.5c un promemoria segnalato dalla persona resta «reported» dopo il rimborso del suo credito, e i numeri si aggiornano: pagato 3000, restante 1000, nulla da compensare', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);
    $evento = b52tEvento($s, $piano, 'bice', 1);
    $evento->update(['meta' => array_merge($evento->meta, ['status' => 'reported', 'reported_at' => now()->toIso8601String()])]);

    $this->actingAs($this->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['persone']['bice']]), [
        'rata_quote_id' => $qD, 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-03-06', 'importo' => '10,00', 'nota' => '',
    ])->assertSessionHasNoErrors();

    $b = b52tBice($s, $piano);
    expect([$b['status'], $b['originale'], $b['pagato'], $b['restante'], $b['da_compensare']])->toBe(['reported', 4000, 3000, 1000, 0]);
});

it('T2.2e lo storno di «Usa credito» riporta il promemoria in attesa della compensazione: partial, pagato 4000, restante zero, da compensare € 10,00', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);
    b52tIncasso($this, $s, 'bice', [$qD => -1000, $qC => 1000], 0);
    expect(b52tBice($s, $piano)['status'])->toBe('paid');
    $usaCredito = App\Models\Gestionale\ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();

    $this->actingAs($this->user)->post("/admin/gestionale/{$s['c']->id}/movimenti-rate/{$usaCredito->id}/storno")->assertSessionHasNoErrors();

    $b = b52tBice($s, $piano);
    expect([$b['status'], $b['originale'], $b['pagato'], $b['restante'], $b['da_compensare']])->toBe(['partial', 4000, 4000, 0, 1000]);
});

it('T2.5d lo storno del rimborso del credito del box riporta il promemoria in attesa della compensazione: dopo il rimborso restano € 10,00 da versare, dopo lo storno nulla da versare e € 10,00 da compensare', function () {
    [$s, $piano, $qC, $qD] = b52tK3($this);
    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);
    $this->actingAs($this->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['persone']['bice']]), [
        'rata_quote_id' => $qD, 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-03-06', 'importo' => '10,00', 'nota' => '',
    ])->assertSessionHasNoErrors();
    $dopoIlRimborso = b52tBice($s, $piano);
    expect([$dopoIlRimborso['status'], $dopoIlRimborso['pagato'], $dopoIlRimborso['restante'], $dopoIlRimborso['da_compensare']])->toBe(['partial', 3000, 1000, 0]);
    $rimborso = App\Models\Gestionale\ScritturaContabile::where('tipo_movimento', 'rimborso_condomino')->firstOrFail();

    $this->actingAs($this->user)->post("/admin/gestionale/{$s['c']->id}/movimenti-rate/{$rimborso->id}/storno")->assertSessionHasNoErrors();

    $b = b52tBice($s, $piano);
    expect([$b['status'], $b['originale'], $b['pagato'], $b['restante'], $b['da_compensare']])->toBe(['partial', 4000, 4000, 0, 1000]);
});

it('T2.6 il caso misto, credito oltre il debito: la quota C di € 50,00 resta da pagare, il portale non dice «rata saldata»', function () {
    $s = b52tScenario($this, '1.200,00', '1.200,00');
    $piano = b52tPiano($this, $s);
    b52tApprova($this, $s, $piano);

    expect(b52tQuote($s, $piano, 'bice', 1))->toBe([['C', 5000, 0, 'da_pagare'], ['D', -10000, 0, 'credito']]);
    expect(b52tBice($s, $piano))->toBe(['status' => 'partial', 'originale' => -5000, 'pagato' => 0, 'restante' => -5000, 'da_compensare' => 5000,
        'descrizione' => "Gentile Bice, per la rata n. 1 non devi versare altro. Restano € 50,00 di una tua quota da compensare con un tuo credito su un'altra tua quota di questa rata: la compensazione la registra l'amministratore."]);
});

it('T2.6b il saldo misto a netto zero (debito e credito della stessa rata che si annullano): non «rata saldata», ma in attesa della compensazione di € 50,00', function () {
    $s = b52tScenario($this, '1.200,00', '600,00');
    $piano = b52tPiano($this, $s);
    b52tApprova($this, $s, $piano);

    expect(b52tQuote($s, $piano, 'bice', 1))->toBe([['C', 5000, 0, 'da_pagare'], ['D', -5000, 0, 'credito']]);
    expect(b52tBice($s, $piano))->toMatchArray(['status' => 'partial', 'originale' => 0, 'pagato' => 0, 'restante' => 0, 'da_compensare' => 5000]);
});

it('T2.11 il rimborso di un credito puro per intero: restante zero, e la frase non dice più che il credito resta', function () {
    $s = b52tScenario($this, '1.200,00', '240,00', soloEntrata: true);
    $piano = b52tPiano($this, $s);
    b52tApprova($this, $s, $piano);
    $quota = RataQuote::find(b52tQuotaId($s, $piano, 'bice', 'D', 1));

    $this->actingAs($this->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['persone']['bice']]), [
        'rata_quote_id' => $quota->id, 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-03-06', 'importo' => '20,00', 'nota' => '',
    ])->assertSessionHasNoErrors();

    $b = b52tBice($s, $piano);
    expect([$b['status'], $b['originale'], $b['pagato'], $b['restante'], $b['da_compensare']])->toBe(['paid', -2000, -2000, 0, 0]);
    expect($b['descrizione'])->toBe("Gentile Bice, per la rata n. 1 non resta niente da versare né da ricevere: il credito è stato usato o restituito.");
});

// ── La pubblicazione silenziosa, «segui le quote» e le quote annullate ───────────────────────────────────────

it('T2.7 la pubblicazione silenziosa dopo il versamento del netto dà lo stesso stato dell\'incasso: partial, restante zero, da compensare € 10,00', function () {
    [$s, $piano, $qC] = b52tK3($this, notifiche: false);
    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.publish-silent', [$s['c'], $s['e'], $piano]))->assertSessionHasNoErrors();

    $b = b52tBice($s, $piano);
    expect([$b['status'], $b['pagato'], $b['restante'], $b['da_compensare']])->toBe(['partial', 4000, 0, 1000]);
});

it('T2.7b la pubblicazione silenziosa non cancella il credito di un credito puro: restante −2000 come alla creazione', function () {
    $s = b52tScenario($this, '1.200,00', '240,00', soloEntrata: true);
    $piano = b52tPiano($this, $s);
    b52tApprova($this, $s, $piano);
    b52tEmetti($this, $s, $piano, 1, false);

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.publish-silent', [$s['c'], $s['e'], $piano]))->assertSessionHasNoErrors();

    $b = b52tBice($s, $piano);
    expect([$b['status'], $b['originale'], $b['restante']])->toBe(['paid', -2000, -2000]);
});

it('T2.8 una quota annullata non conta: il promemoria si calcola sulle quote attive, anche dopo un incasso', function () {
    [$s, $piano, $qC] = b52tK3($this);
    // una quota di Bice sulla stessa rata, annullata da un passaggio: non è più sua e non va né pagata né contata
    RataQuote::create(['rata_id' => b52tRata($piano, 1), 'anagrafica_id' => $s['persone']['bice']->id, 'immobile_id' => $s['unita']['C']->id,
        'importo' => 3000, 'importo_pagato' => 0, 'stato' => 'annullata', 'data_scadenza' => '2026-01-05']);

    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);

    $b = b52tBice($s, $piano);
    expect([$b['status'], $b['pagato'], $b['restante'], $b['da_compensare']])->toBe(['partial', 4000, 0, 1000]); // con la quota annullata contata, restante sarebbe 3000
});

it('T2.12 controprova: un testo ritoccato a mano non si riscrive, anche se lo stato cambia', function () {
    [$s, $piano, $qC] = b52tK3($this);
    $evento = b52tEvento($s, $piano, 'bice', 1);
    $evento->update(['description' => "Gentile Bice, la rata 1 è prorogata al 10 febbraio (nota dell'amministratore)."]);

    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);

    expect(b52tBice($s, $piano)['descrizione'])->toBe("Gentile Bice, la rata 1 è prorogata al 10 febbraio (nota dell'amministratore).");
});

it('T2.12b con il testo ritoccato a mano lo stato nel meta si riscrive lo stesso: partial, pagato 4000, restante zero, da compensare 1000', function () {
    [$s, $piano, $qC] = b52tK3($this);
    b52tEvento($s, $piano, 'bice', 1)->update(['description' => "Gentile Bice, la rata 1 è prorogata al 10 febbraio (nota dell'amministratore)."]);

    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);

    $b = b52tBice($s, $piano);
    expect([$b['status'], $b['pagato'], $b['restante'], $b['da_compensare']])->toBe(['partial', 4000, 0, 1000]);
});

// ── Il passaggio successivo sulla stessa unità, e il suo annullamento ────────────────────────────────────

/** K3 con le prime quattro rate emesse, € 40,00 versati su C e la vendita di C a Elsa il 1° maggio: le bozze da maggio passano a Elsa. */
function b52tK3ConVenditaDiC($test): array
{
    $s = b52tScenario($test, '1.200,00', '120,00');
    $piano = b52tPiano($test, $s);
    b52tApprova($test, $s, $piano);
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), ['rate_ids' => array_map(fn ($n) => b52tRata($piano, $n), [1, 2, 3, 4]), 'data_emissione' => '2026-01-31', 'invia_notifiche' => true])->assertSessionHasNoErrors();
    b52tIncasso($test, $s, 'bice', [b52tQuotaId($s, $piano, 'bice', 'C', 1) => 4000], 4000);
    $s['persone']['elsa'] = tap(App\Models\Anagrafica::forceCreate(['nome' => 'Elsa', 'email' => 'b52t-elsa@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'ELSAXX0000000001']),
        fn ($a) => $a->condomini()->syncWithoutDetaching([$s['c']->id]));
    $riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['bice']->id)->where('immobile_id', $s['unita']['C']->id)->value('id');
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['C']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $s['persone']['elsa']->id, 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ])->assertSessionHasNoErrors();

    return [$s, $piano];
}

it('T2.10 la vendita di C a Elsa: la rata emessa di Bice resta in attesa della compensazione, le bozze di Bice diventano un credito con la frase nuova, quelle di Elsa un debito', function () {
    [$s, $piano] = b52tK3ConVenditaDiC($this);

    expect(b52tBice($s, $piano, 1))->toMatchArray(['status' => 'partial', 'pagato' => 4000, 'restante' => 0, 'da_compensare' => 1000]);   // la rata emessa non cambia mano
    expect(b52tBice($s, $piano, 5))->toBe(['status' => 'paid', 'originale' => -1000, 'pagato' => 0, 'restante' => -1000, 'da_compensare' => 0,
        'descrizione' => str_replace('n. 1', 'n. 5', B52T_CREDITO_PURO)]);
    $elsa = b52tEvento($s, $piano, 'elsa', 5);
    expect([$elsa->meta['status'], $elsa->meta['importo_originale'], $elsa->meta['importo_restante'], $elsa->meta['da_compensare'] ?? null])->toBe(['pending', 5000, 5000, 0]);
    expect($elsa->description)->toBe(str_replace(['Bice', 'n. 1'], ['Elsa', 'n. 5'], B52T_SCADENZA));
});

it('T2.10b l\'annullamento della vendita riporta le bozze di Bice al netto con il credito: € 40,00 da versare e € 10,00 da compensare', function () {
    [$s, $piano] = b52tK3ConVenditaDiC($this);
    $subentro = App\Models\Gestionale\Subentro::whereNull('subentro_padre_id')->latest('id')->firstOrFail();

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['C'], $subentro]), ['nota_annullamento' => 'Registrato con la data sbagliata, lo rifaccio'])->assertRedirect()->assertSessionHasNoErrors();

    expect(b52tBice($s, $piano, 5))->toBe(['status' => 'pending', 'originale' => 4000, 'pagato' => 0, 'restante' => 4000, 'da_compensare' => 1000,
        'descrizione' => str_replace('n. 1', 'n. 5', B52T_SCADENZA . B52T_NETTO_CON_CREDITO)]);
    expect(b52tEvento($s, $piano, 'elsa', 5))->toBeNull();
});

// ── Una sola regola: lo stato del promemoria e la verifica incassi ───────────────────────────────────────────────

it('T2.21 una quota annullata e non pagata non tiene aperta la rata: il promemoria è «paid» e la verifica incassi la dà per saldata, come lo stato del promemoria', function () {
    $s = b52tScenario($this, '1.200,00', '0,00');
    $piano = b52tPiano($this, $s);
    b52tApprova($this, $s, $piano);
    b52tEmetti($this, $s, $piano, 1);
    b52tIncasso($this, $s, 'ugo', [b52tQuotaId($s, $piano, 'ugo', 'A', 1) => 5000], 5000);
    b52tIncasso($this, $s, 'bice', [b52tQuotaId($s, $piano, 'bice', 'C', 1) => 5000], 5000);
    $rata = Rata::find(b52tRata($piano, 1));
    $servizio = app(EventiRataCondomino::class);
    expect([b52tEvento($s, $piano, 'ugo', 1)->meta['status'], $servizio->rataSaldata($rata)])->toBe(['paid', true]);

    // una quota di Ugo sulla stessa rata, annullata e mai pagata: non è dovuta, e né il promemoria né la verifica incassi la contano
    RataQuote::create(['rata_id' => $rata->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['unita']['A']->id,
        'importo' => 3000, 'importo_pagato' => 0, 'stato' => 'annullata', 'data_scadenza' => '2026-01-05']);
    $servizio->allineaPagato(collect([[$rata->id, $s['persone']['ugo']->id, true]]));

    $ugo = b52tEvento($s, $piano, 'ugo', 1)->meta;
    expect([$ugo['status'], $ugo['importo_restante']])->toBe(['paid', 0]);
    expect($servizio->rataSaldata($rata))->toBeTrue();
});

it('T2.21b controprova: una quota positiva attiva ancora da pagare tiene aperta la rata, e il promemoria non è «paid»', function () {
    $s = b52tScenario($this, '1.200,00', '0,00');
    $piano = b52tPiano($this, $s);
    b52tApprova($this, $s, $piano);
    b52tEmetti($this, $s, $piano, 1);
    b52tIncasso($this, $s, 'ugo', [b52tQuotaId($s, $piano, 'ugo', 'A', 1) => 5000], 5000);
    $rata = Rata::find(b52tRata($piano, 1));

    expect(app(EventiRataCondomino::class)->rataSaldata($rata))->toBeFalse(); // Bice non ha ancora versato
    expect(b52tEvento($s, $piano, 'bice', 1)->meta['status'])->not->toBe('paid');
});

// ── Lo stato con più quote positive, e la pubblicazione silenziosa ───────────────────────────────────────────────

it('T2.22 due quote positive della stessa persona, la prima pagata in più: il credito è l\'eccedenza e si compensa con la seconda (restante 2000, da compensare 1000)', function () {
    // C € 50,00 pagata € 60,00 (eccedenza € 10,00) ed E € 30,00 da pagare: netto € 80,00, pagato € 60,00, restano € 20,00; il credito che la persona ha
    // sulla stessa rata è l\'eccedenza della prima quota, non solo quello di una quota negativa
    $quote = collect([
        (new RataQuote)->forceFill(['importo' => 5000, 'importo_pagato' => 6000, 'stato' => 'pagata']),
        (new RataQuote)->forceFill(['importo' => 3000, 'importo_pagato' => 0, 'stato' => 'da_pagare']),
    ]);

    $stato = app(EventiRataCondomino::class)->stato($quote);

    // `versato` legge i movimenti delle quote, e queste non ne hanno: zero. Lo prova `Beta52PromemoriaVersatoTest`.
    expect($stato)->toBe(['originale' => 8000, 'pagato' => 6000, 'versato' => 0, 'restante' => 2000, 'da_compensare' => 1000, 'status' => 'partial']);
});

it('T2.23 la pubblicazione silenziosa riscrive il testo di un promemoria nascosto che ha ancora quello della creazione: dopo il versamento del netto dice «hai versato quanto richiesto»', function () {
    [$s, $piano, $qC] = b52tK3($this, notifiche: false);
    b52tIncasso($this, $s, 'bice', [$qC => 4000], 4000);
    // il promemoria nascosto come l\'aveva lasciato la creazione, con le quote già pagate: lo stato e il testo di prima dell\'incasso
    $creazione = B52T_SCADENZA . B52T_NETTO_CON_CREDITO;
    $evento = b52tEvento($s, $piano, 'bice', 1);
    $evento->update(['description' => $creazione, 'meta' => array_merge($evento->meta, ['status' => 'pending', 'importo_pagato' => 0, 'importo_versato' => 0, 'importo_restante' => 4000, 'da_compensare' => 1000])]);
    expect($evento->fresh()->visibility)->toBe(App\Enums\VisibilityStatus::HIDDEN->value);

    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.publish-silent', [$s['c'], $s['e'], $piano]))->assertSessionHasNoErrors();

    $b = b52tBice($s, $piano);
    expect([$b['status'], $b['pagato'], $b['restante'], $b['da_compensare']])->toBe(['partial', 4000, 0, 1000]);
    expect($b['descrizione'])->toContain('hai versato quanto richiesto')->not->toBe($creazione);
});

// ── I testi di un credito puro salvati dalle versioni passate ────────────────────────────────────────────────────

const B52T_MIGRAZIONE_CREDITO = '2026_10_17_100000_riscrivi_descrizioni_promemoria_credito';
const B52T_DOPO_IL_DUE_PUNTI = 'Non è richiesto alcun pagamento: ';
const B52T_FRASE_VECCHIA = "l'importo verrà utilizzato automaticamente per compensare le rate successive.";
const B52T_FRASE_CREDITO_RESTA = "il credito resta a tuo favore. L'amministratore può usarlo per compensare una tua quota da pagare o restituirtelo.";
const B52T_FRASE_CREDITO_USATO = 'il credito è stato usato o restituito.';

/** Le teste dei testi di un credito puro: la 1.8.0 (rilasciata con due a capo, con uno in una versione intermedia) e la forma con la rata (dalla 1.9.0). */
function b52tTesteDelCredito(string $nome, int $rata): array
{
    return [
        '1.8.0' => "Gentile {$nome}, questa voce rappresenta un credito a tuo favore (es. avanzo esercizio precedente).\n\n",
        '1.8.0 con un solo a capo' => "Gentile {$nome}, questa voce rappresenta un credito a tuo favore (es. avanzo esercizio precedente).\n",
        'con la rata' => "Gentile {$nome}, questa voce rappresenta un credito a tuo favore registrato nella rata n. {$rata}.\n\n",
    ];
}

it('T2.20 un credito puro salvato nella forma 1.8.0, migrato e poi rimborsato per intero: la descrizione dice che il credito è stato usato o restituito', function (string $testa) {
    $s = b52tScenario($this, '1.200,00', '240,00', soloEntrata: true);
    $piano = b52tPiano($this, $s);
    b52tApprova($this, $s, $piano);
    $evento = b52tEvento($s, $piano, 'bice', 1);
    $evento->update(['description' => $testa . B52T_DOPO_IL_DUE_PUNTI . B52T_FRASE_VECCHIA]);
    (require database_path('migrations/'.B52T_MIGRAZIONE_CREDITO.'.php'))->up();
    expect($evento->fresh()->description)->toBe($testa . B52T_DOPO_IL_DUE_PUNTI . B52T_FRASE_CREDITO_RESTA); // la migrazione ha tolto la promessa falsa

    $this->actingAs($this->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['persone']['bice']]), [
        'rata_quote_id' => b52tQuotaId($s, $piano, 'bice', 'D', 1), 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-03-06', 'importo' => '20,00', 'nota' => '',
    ])->assertSessionHasNoErrors();

    $b = b52tBice($s, $piano);
    expect([$b['status'], $b['restante']])->toBe(['paid', 0]);
    expect($b['descrizione'])->toBe("Gentile Bice, per la rata n. 1 non resta niente da versare né da ricevere: il credito è stato usato o restituito.");
})->with([
    'la forma della 1.8.0' => ["Gentile Bice, questa voce rappresenta un credito a tuo favore (es. avanzo esercizio precedente).\n\n"],
    'la forma della 1.8.0 con un solo a capo' => ["Gentile Bice, questa voce rappresenta un credito a tuo favore (es. avanzo esercizio precedente).\n"],
    'la forma con la rata' => ["Gentile Bice, questa voce rappresenta un credito a tuo favore registrato nella rata n. 1.\n\n"],
]);

it('T2.20b applica() riconosce come testo del sistema ogni forma di un credito puro di prima con le frasi della migrazione, con o senza la nota della rata, e riscrive solo quelle', function () {
    $servizio = app(EventiRataCondomino::class);
    $persona = (new Anagrafica)->forceFill(['nome' => 'Bice']);
    $creditoDisponibile = ['originale' => -2000, 'pagato' => 0, 'restante' => -2000, 'da_compensare' => 0, 'status' => 'paid'];
    $creditoRestituito = ['originale' => -2000, 'pagato' => -2000, 'restante' => 0, 'da_compensare' => 0, 'status' => 'paid'];
    $nuovoTesto = fn (?string $nota) => "Gentile Bice, per la rata n. 1 non resta niente da versare né da ricevere: il credito è stato usato o restituito." . ($nota ? "\n\nNote: {$nota}" : '');

    foreach ([null, 'scadenza prorogata al 10 maggio'] as $nota) {
        $rata = (new Rata)->forceFill(['numero_rata' => 1, 'note' => $nota]);
        $coda = $nota ? "\n\nNote: {$nota}" : '';
        foreach (b52tTesteDelCredito('Bice', 1) as $forma => $testa) {
            foreach (['la frase nuova' => B52T_FRASE_CREDITO_RESTA, 'la frase del credito usato' => B52T_FRASE_CREDITO_USATO] as $quale => $frase) {
                $evento = (new Evento)->forceFill(['description' => $testa . B52T_DOPO_IL_DUE_PUNTI . $frase . $coda, 'meta' => ['importo_originale' => -2000, 'importo_pagato' => 0, 'importo_restante' => -2000, 'da_compensare' => 0, 'status' => 'paid']]);

                $servizio->applica($evento, $creditoRestituito, $persona, $rata);

                expect($evento->description)->toBe($nuovoTesto($nota), "{$forma}, {$quale}, nota " . var_export($nota, true));
            }
        }
    }

    // un testo ritoccato a mano, anche di una sola parola, non si tocca
    $rata = (new Rata)->forceFill(['numero_rata' => 1, 'note' => null]);
    foreach (b52tTesteDelCredito('Bice', 1) as $forma => $testa) {
        $ritoccato = $testa . B52T_DOPO_IL_DUE_PUNTI . str_replace('resta', 'rimane', B52T_FRASE_CREDITO_RESTA);
        $evento = (new Evento)->forceFill(['description' => $ritoccato, 'meta' => ['importo_originale' => -2000, 'importo_pagato' => 0, 'importo_restante' => -2000, 'da_compensare' => 0, 'status' => 'paid']]);

        $servizio->applica($evento, $creditoRestituito, $persona, $rata);

        expect($evento->description)->toBe($ritoccato, $forma);
        expect($evento->meta['importo_restante'])->toBe(0); // i numeri si aggiornano lo stesso
    }
});

it('T2.20c il credito che torna dopo lo storno del rimborso: la frase «è stato usato o restituito» della migrazione si riscrive in «il credito resta a tuo favore»', function () {
    $servizio = app(EventiRataCondomino::class);
    $persona = (new Anagrafica)->forceFill(['nome' => 'Bice']);
    $rata = (new Rata)->forceFill(['numero_rata' => 1, 'note' => null]);
    $evento = (new Evento)->forceFill([
        'description' => "Gentile Bice, questa voce rappresenta un credito a tuo favore (es. avanzo esercizio precedente).\n\n" . B52T_DOPO_IL_DUE_PUNTI . B52T_FRASE_CREDITO_USATO,
        'meta' => ['importo_originale' => -2000, 'importo_pagato' => -2000, 'importo_restante' => 0, 'da_compensare' => 0, 'status' => 'paid'],
    ]);

    $servizio->applica($evento, ['originale' => -2000, 'pagato' => 0, 'restante' => -2000, 'da_compensare' => 0, 'status' => 'paid'], $persona, $rata);

    expect($evento->description)->toBe(B52T_CREDITO_PURO);
});
