<?php

/**
 * 1.11.0-beta.51, §6.3 — la guardia all'emissione dopo l'annullamento di un passaggio.
 *
 * Un piano senza rate a giornale che è stato generato o ricalcolato fra la registrazione di una vendita, di un usufrutto o di
 * una successione e il suo annullamento, con quote che l'annullamento cambierebbe, va ricalcolato prima di emettere o incassare,
 * come un passaggio ancora da seguire (`PianoRate::passaggiDaSeguire`). La locazione resta fuori (decisione 32). Il fatto si scrive
 * nel registro all'annullamento (`annullamento.quota_max_id`); per gli annullamenti di prima vale l'ora.
 * Fonte dell'atteso: decisione 78 (1), decisioni 35, 41 e 42, e il conto a mano scritto in ogni test
 * (oracoli O-63-1 … O-63-19).
 *
 * `AnnullaPassaggioAction::presiDaSeguire` eredita l'estensione senza codice suo: lo prova il test «O-63-8, presiDaSeguire» (il piano
 * è preso nel conguaglio di V2, fermo da un movimento, e deve ancora seguire V1 annullato).
 *
 * Cosa NON copre: le righe del laboratorio (O15 e O16 si adeguano dopo il commit, nel suo repository).
 *
 * `passaggiDaSeguire()` vede anche i passaggi annullati: senza, l'emissione e l'incasso su un piano ricalcolato con un passaggio poi annullato
 * passerebbero e manderebbero a giornale le quote di chi non è più titolare. I test che cominciano con «guardia —» proteggono ciò che la guardia
 * non deve rompere.
 *
 * Il passaggio dopo: un passaggio il cui conguaglio «Scrivi» prenderebbe un piano fermo che deve ancora seguire un passaggio annullato sulla sua
 * unità non si registra (`RegistraSubentroAction` lo rifiuta sul campo `passaggio`), e l'anteprima lo dice con un avviso bloccante
 * (`rate.piano_da_ricalcolare`) senza togliere il pannello: la casella della rinuncia e la scelta «Non scriverlo» restano raggiungibili dal
 * modulo, perché sono la via d'uscita (O-63-17). Con «Non scriverlo» o su un'altra unità del piano si registra.
 */

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Actions\Subentro\AnnullaPassaggioAction;
use App\Exceptions\Gestionale\PianoDaRicalcolareException;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RigaRiparto;
use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

// --- Gli scenari ------------------------------------------------------------------------------------------------------------

/** Unità A da 1000 millesimi, ordinaria € 1.200,00 in dodici rate dal 5/1 (10000 a rata), Ugo proprietario; Elsa esiste. Il piano non è generato. */
function b51pBase($test, string $metodo = 'prima_rata', bool $bozza = false): Beta51Scenario
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])
        ->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', metodo: $metodo, bozza: $bozza)->titolare('A', 'ugo');
    $s->persona('elsa');

    return $s;
}

/** Due unità da 500 millesimi (Ugo su A, Rita su B): A pesa 60000 sui 120000 del preventivo. Il piano non è generato. */
function b51pDue($test): Beta51Scenario
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 500, 'B' => 500])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026')
        ->titolare('A', 'ugo')->titolare('B', 'rita');
    $s->persona('elsa');
    $s->persona('carlo');

    return $s;
}

/** Il ciclo che lascia un piano stantio: genera, vende Ugo → Elsa il 1/5, ricalcola con la vendita, annulla la vendita. Restituisce il passaggio. */
function b51pStantio(Beta51Scenario $s, string $a = 'elsa', string $il = '2026-05-01'): Subentro
{
    $s->genera('Preventivo 2026');
    $v = $s->passaggio('vendita', 'A', 'ugo', $a, $il);
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    $s->annulla('A')->assertRedirect();

    return $v;
}

/** Il messaggio di rifiuto dell'emissione (O-63-1): il passaggio in elenco e le quote di chi andrebbe a giornale per un passaggio che non c'è più. */
function b51pRifiuto(string $passaggio, string $chi): string
{
    return "Le quote di questo piano sono state calcolate con questo passaggio, che poi è stato annullato:\n• " . $passaggio
        . "\n\nRicalcola il piano prima di emettere, perché torni alla titolarità di prima: emesse così, le quote di " . $chi . " andrebbero a giornale per un passaggio che non c'è più.";
}

function b51pMessaggio($risposta): array
{
    return $risposta->getSession()->get('message') ?? [];
}

/** Il movimento di una persona sulla sua prima quota del piano (un incasso, come lo legge `haIncassiRegistrati`). */
function b51pMovimento(Beta51Scenario $s, string $chi, string $piano = 'Preventivo 2026', int $cents = 1000): int
{
    $id = (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s->piani[$piano]['p']->id)
        ->where('rate_quote.anagrafica_id', $s->persona($chi)->id)->orderBy('rate_quote.id')->value('rate_quote.id');
    DB::table('rate_quote')->where('id', $id)->update(['importo_pagato' => $cents]);

    return $id;
}

// --- O-63-1: la vendita annullata dopo il ricalcolo -------------------------------------------------------------------------

it('O-63-1 — vendita annullata dopo il ricalcolo: l\'emissione fino al 30/6 si rifiuta con il motivo, e non va a giornale niente', function () {
    $s = b51pBase($this);
    $v = b51pStantio($s);
    // Dopo il ricalcolo la vendita ha diviso per giorni: Ugo 120/365 = 39452, Elsa 245/365 = 80548 (39452 = 12 × 3287 + 8; 80548 = 12 × 6712 + 4).
    // Oggi, annullata la vendita, l'emissione manda a giornale Ugo 19728 ed Elsa 40276 (€ 600,04), invece di Ugo 60000.
    $r = $s->emetti('Preventivo 2026', '2026-06-30');

    expect(b51pMessaggio($r))->toBe(['type' => 'error', 'message' => b51pRifiuto('Ugo → Elsa, Interno A, dal 1 maggio 2026', 'Elsa')])
        ->and([$s->aGiornale('ugo'), $s->aGiornale('elsa')])->toBe([0, 0])
        ->and($s->daSeguire('Preventivo 2026'))->toBe([$v->id . 'a']);
});

it('O-63-1, il percorso corretto — dopo il ricalcolo l\'emissione passa: Ugo 60000 a giornale, Elsa 0, nessun passaggio da seguire; il fatto dell\'annullamento sta nel registro', function () {
    $s = b51pBase($this);
    $v = b51pStantio($s);
    $chiave = (int) DB::table('rate_quote')->max('id');

    // Il dato si scrive. La chiave è il più alto id di quota al momento dell'annullamento (misurato: 36).
    expect(Subentro::conAnnullati()->findOrFail($v->id)->registro['annullamento']['quota_max_id'] ?? null)->toBe($chiave);

    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    $s->emetti('Preventivo 2026', '2026-06-30')->assertSessionHasNoErrors();
    // Conto a mano: sei rate da 120000/12 = 10000 a Ugo, tornato titolare dei 365 giorni.
    expect([$s->aGiornale('ugo'), $s->aGiornale('elsa')])->toBe([60000, 0])
        ->and($s->daSeguire('Preventivo 2026'))->toBe([]);
});

it('guardia — O-63-2: la vendita annullata senza ricalcolo non ferma niente: le quote c\'erano già e tornano com\'erano, Ugo 60000 a giornale', function () {
    $s = b51pBase($this);
    $s->genera('Preventivo 2026');
    $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
    $s->annulla('A')->assertRedirect();

    expect($s->daSeguire('Preventivo 2026'))->toBe([]);
    $s->emetti('Preventivo 2026', '2026-06-30')->assertSessionHasNoErrors();
    expect([$s->aGiornale('ugo'), $s->aGiornale('elsa')])->toBe([60000, 0]);
});

// --- O-63-3: la successione annullata dopo il ricalcolo (O16 del laboratorio) ----------------------------------------------

it('O-63-3 — successione annullata dopo il ricalcolo: l\'emissione si rifiuta nominando la successione e le quote di Anna e Bruno', function () {
    $s = b51pBase($this);
    $s->genera('Preventivo 2026');
    $sub = $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    // Conto a mano: dopo il ricalcolo Ugo 39452, Anna 48329, Bruno 32219. Sei rate: Ugo 19728; Anna 48329 = 12 × 4027 + 5 → 24167; Bruno 32219 = 12 × 2684 + 11 → 16110.
    // Oggi a giornale vanno Anna € 241,67, Bruno € 161,10 e Ugo € 197,28.
    $s->annulla('A')->assertRedirect();
    $r = $s->emetti('Preventivo 2026', '2026-06-30');

    expect(b51pMessaggio($r))->toBe(['type' => 'error', 'message' => b51pRifiuto('successione di Ugo, Interno A, dal 1 maggio 2026', 'Anna e Bruno')])
        ->and([$s->aGiornale('ugo'), $s->aGiornale('anna'), $s->aGiornale('bruno')])->toBe([0, 0, 0])
        ->and($s->daSeguire('Preventivo 2026'))->toBe([$sub->id . 'a']);

    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    $s->emetti('Preventivo 2026', '2026-06-30')->assertSessionHasNoErrors();
    expect([$s->aGiornale('ugo'), $s->aGiornale('anna'), $s->aGiornale('bruno')])->toBe([60000, 0, 0]);
});

it('O-63-3b — piano generato dopo la vendita e mai ricalcolato, poi la vendita si annulla: la quota è nata dopo il passaggio e prima dell\'annullamento, l\'emissione si rifiuta', function () {
    $s = b51pBase($this);
    $v = $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
    $s->genera('Preventivo 2026');
    // Conto a mano come O-63-1: il piano nasce con Elsa (80548) e Ugo (39452). Annullata la vendita, Ugo torna titolare di tutto: 120000.
    expect($s->somme('Preventivo 2026', ['ugo', 'elsa']))->toBe(['ugo' => 39452, 'elsa' => 80548]);
    $s->annulla('A')->assertRedirect();

    $r = $s->emetti('Preventivo 2026', '2026-06-30');
    expect(b51pMessaggio($r))->toBe(['type' => 'error', 'message' => b51pRifiuto('Ugo → Elsa, Interno A, dal 1 maggio 2026', 'Elsa')])
        ->and($s->daSeguire('Preventivo 2026'))->toBe([$v->id . 'a']);

    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'elsa']))->toBe(['ugo' => 120000, 'elsa' => 0]);
});

// --- O-63-4 e O-63-11: gli annullamenti di prima e gli id ripresi -----------------------------------------------------------

it('O-63-4 — annullamento di prima (senza la chiave): vale l\'ora, la guardia scatta prima del ricalcolo e non dopo quello fatto più tardi', function () {
    $s = b51pBase($this);
    $s->genera('Preventivo 2026');
    $v = $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
    $this->travel(2)->seconds();
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    $this->travel(2)->seconds();
    $s->annulla('A')->assertRedirect();
    // Come un annullamento di prima della .51: nel registro non c'è la chiave.
    $p = Subentro::conAnnullati()->findOrFail($v->id);
    $registro = $p->registro;
    unset($registro['annullamento']);
    $p->forceFill(['registro' => $registro])->save();

    // Misurato: 1 passaggio da seguire prima del ricalcolo, 0 dopo, con due secondi di scarto.
    expect($s->daSeguire('Preventivo 2026'))->toBe([$v->id . 'a']);
    $this->travel(2)->seconds();
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    expect($s->daSeguire('Preventivo 2026'))->toBe([]);
});

it('O-63-11 — id ripreso dopo un riavvio (MySQL 5.7): le quote del ricalcolo fatto dopo l\'annullamento hanno id piccoli ma nascono dopo, e non sono stantie', function () {
    $s = b51pBase($this);
    $v = b51pStantio($s);
    expect(Subentro::conAnnullati()->findOrFail($v->id)->registro['annullamento']['quota_max_id'] ?? null)->toBeInt();

    // La sequenza riparte da zero, come un MySQL 5.7 che si riprende gli id: le quote nuove avranno id ≤ chiave ma created_at dopo l'annullamento.
    DB::table('sqlite_sequence')->where('name', 'rate_quote')->update(['seq' => 0]);
    $this->travel(1)->seconds();
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();

    expect((int) DB::table('rate_quote')->max('id'))->toBeLessThanOrEqual((int) (Subentro::conAnnullati()->findOrFail($v->id)->registro['annullamento']['quota_max_id'] ?? 0))
        ->and($s->daSeguire('Preventivo 2026'))->toBe([]);
    $s->emetti('Preventivo 2026', '2026-06-30')->assertSessionHasNoErrors();
    expect($s->aGiornale('ugo'))->toBe(60000);
});

// --- O-63-5: incasso, compensazione e rimborso su un piano stantio ----------------------------------------------------------

/** Ugo ha un credito di € 150,00 nella rata zero; la vendita del 1/5 è registrata, il piano ricalcolato e la vendita annullata. */
function b51pStantioConCredito($test): Beta51Scenario
{
    $s = b51pBase($test, 'rata_zero');
    $s->saldo('ugo', 'A', 'Ordinaria 2026', -15000);
    b51pStantio($s);

    return $s;
}

function b51pFraseRicalcolaPrima(string $cosa, string $registra): string
{
    return 'Ricalcola prima il piano «Preventivo 2026»: le sue quote sono state calcolate con la titolarità del passaggio di Ugo su Interno A, dal 1 maggio 2026, che poi è stato annullato, e '
        . $cosa . ' su una sua quota lo fermerebbe prima che torni alla titolarità di prima. Dopo il ricalcolo registra ' . $registra . '.';
}

function b51pQuotaDi(Beta51Scenario $s, string $chi, int $numeroRata): int
{
    return (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s->piani['Preventivo 2026']['p']->id)
        ->where('rate.numero_rata', $numeroRata)->where('rate_quote.anagrafica_id', $s->persona($chi)->id)->value('rate_quote.id');
}

it('O-63-5 — un incasso su una quota di un piano stantio si rifiuta e dice di ricalcolare prima, nominando il passaggio annullato', function () {
    $s = b51pStantioConCredito($this);
    $quota = b51pQuotaDi($s, 'ugo', 1);
    $foto = [DB::table('scritture_contabili')->where('condominio_id', $s->c->id)->count(), DB::table('rate_quote')->sum('importo_pagato')];

    expect(fn () => app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $s->persona('ugo')->id, 'cassa_id' => $s->cassa()->id, 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id, 'data_pagamento' => '2026-05-10',
        'importo_totale' => 30.00, 'descrizione' => 'Rata 1', 'dettaglio_pagamenti' => [['rata_id' => $quota, 'importo' => 30.00]],
    ], $s->c, $s->e))->toThrow(PianoDaRicalcolareException::class, b51pFraseRicalcolaPrima('un incasso', 'l\'incasso'));
    // Nessun effetto parziale.
    expect([DB::table('scritture_contabili')->where('condominio_id', $s->c->id)->count(), DB::table('rate_quote')->sum('importo_pagato')])->toBe($foto);
});

it('O-63-5, compensazione — un credito usato su un piano stantio si rifiuta con la stessa frase («un incasso o un credito usato»)', function () {
    $s = b51pStantioConCredito($this);
    $quotaRata1 = b51pQuotaDi($s, 'ugo', 1);
    $quotaCredito = b51pQuotaDi($s, 'ugo', 0);
    expect((int) DB::table('rate_quote')->where('id', $quotaCredito)->value('importo'))->toBe(-15000);
    // La quota di Ugo nella rata 1 è quella del ricalcolo con la vendita (39452 / 12 = 3288), che l'annullamento non ha toccato: la si paga per intero,
    // con € 10,00 di credito e il resto in denaro.
    $importo = (int) DB::table('rate_quote')->where('id', $quotaRata1)->value('importo');
    expect($importo)->toBe(3288);

    expect(fn () => app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $s->persona('ugo')->id, 'cassa_id' => $s->cassa()->id, 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id, 'data_pagamento' => '2026-05-10',
        'importo_totale' => ($importo - 1000) / 100, 'descrizione' => 'Rata 1 con il credito',
        'dettaglio_pagamenti' => [['rata_id' => $quotaRata1, 'importo' => $importo / 100], ['rata_id' => $quotaCredito, 'importo' => -10.00]],
    ], $s->c, $s->e))->toThrow(PianoDaRicalcolareException::class, b51pFraseRicalcolaPrima('un incasso o un credito usato', 'l\'incasso'));
});

it('O-63-5, rimborso — il rimborso di un credito su un piano stantio si rifiuta sul campo della quota, con «un rimborso» e «il rimborso»', function () {
    $s = b51pStantioConCredito($this);
    $quotaCredito = b51pQuotaDi($s, 'ugo', 0);

    $r = $this->actingAs($s->admin)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s->c, $s->persona('ugo')]), [
        'rata_quote_id' => $quotaCredito, 'cassa_id' => $s->cassa()->id, 'data_rimborso' => '2026-05-10', 'importo' => '30,00', 'nota' => 'Bonifico di prova',
    ]);

    $r->assertSessionHasErrors('rata_quote_id');
    expect(session('errors')->first('rata_quote_id'))->toStartWith(b51pFraseRicalcolaPrima('un rimborso', 'il rimborso'));
});

// --- O-63-6: il piano stantio fermo da un movimento su un'altra unità ----------------------------------------------------------

it('O-63-6 — piano stantio con un incasso su un\'altra unità: il ricalcolo si rifiuta e dice che poi riporterà le quote alla titolarità di prima; l\'emissione si rifiuta con il fermo', function () {
    $s = b51pDue($this);
    $s->genera('Preventivo 2026');
    $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    // Rita paga una rata su B dopo il ricalcolo; poi la vendita su A si annulla (guarda le sole quote toccate: nessuna è pagata).
    b51pMovimento($s, 'rita');
    $s->annulla('A')->assertRedirect();

    $ricalcolo = $s->ricalcola('Preventivo 2026');
    expect(b51pMessaggio($ricalcolo))->toBe(['type' => 'error', 'message' => 'Impossibile ricalcolare: ci sono rate con incassi registrati, o crediti già usati in compensazione o rimborsati. Annulla prima quei movimenti. '
        . 'Poi il ricalcolo riporterà le quote alla titolarità di prima del passaggio di Ugo su Interno A, dal 1 maggio 2026, che è stato annullato.']);

    $emissione = b51pMessaggio($s->emetti('Preventivo 2026', '2026-06-30'));
    expect($emissione['type'])->toBe('error')
        ->and($emissione['message'])->toContain('Le quote di questo piano sono state calcolate con questo passaggio, che poi è stato annullato:')
        ->and($emissione['message'])->toContain('• Ugo → Elsa, Interno A, dal 1 maggio 2026')
        ->and($emissione['message'])->toContain('Oggi il ricalcolo si rifiuta perché il piano ha un incasso su una sua quota')
        ->and([$s->aGiornale('ugo'), $s->aGiornale('elsa'), $s->aGiornale('rita')])->toBe([0, 0, 0]);
});

// --- O-63-7: la vendita con il box -----------------------------------------------------------------------------------------

it('O-63-7 — vendita con il box annullata dopo il ricalcolo: il passaggio da seguire è uno solo (il padre), l\'emissione si rifiuta, dopo il ricalcolo Ugo 60000', function () {
    $s = Beta51Scenario::nuovo($this)->pertinenza('BX', 'A')->tabella('Proprietà', ['A' => 900, 'BX' => 100])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026')
        ->titolare('A', 'ugo')->titolare('BX', 'ugo');
    $s->persona('elsa');
    $s->genera('Preventivo 2026');
    $v = $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01', ['pertinenze' => ['BX']]);
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    $s->annulla('A')->assertRedirect();

    // Conto a mano: A 108000 / 12 = 9000 a rata, BX 12000 / 12 = 1000: sei rate a Ugo = 54000 + 6000 = 60000 dopo il ricalcolo corretto.
    expect($s->daSeguire('Preventivo 2026'))->toBe([$v->id . 'a']);
    $r = $s->emetti('Preventivo 2026', '2026-06-30');
    expect(b51pMessaggio($r)['type'] ?? null)->toBe('error')
        ->and([$s->aGiornale('ugo'), $s->aGiornale('elsa')])->toBe([0, 0]);
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    $s->emetti('Preventivo 2026', '2026-06-30')->assertSessionHasNoErrors();
    expect([$s->aGiornale('ugo'), $s->aGiornale('elsa')])->toBe([60000, 0]);
});

// --- O-63-8: un passaggio annullato stantio e uno attivo da seguire ---------------------------------------------------------

it('O-63-8 — due passaggi sullo stesso piano, uno annullato stantio e uno attivo da seguire: la lista li dà in quest\'ordine, e la frase dell\'emissione ha un paragrafo per ciascuno', function () {
    $s = b51pBase($this);
    $s->persona('carlo');
    $v1 = b51pStantio($s);
    $v2 = $s->passaggio('vendita', 'A', 'ugo', 'carlo', '2026-06-01');

    // Prima gli attivi (invariati), poi gli annullati.
    expect($s->daSeguire('Preventivo 2026'))->toBe([(string) $v2->id, $v1->id . 'a']);
    $messaggio = b51pMessaggio($s->emetti('Preventivo 2026', '2026-06-30'));
    expect($messaggio['type'])->toBe('error')
        ->and($messaggio['message'])->toContain('Le quote di questo piano sono state calcolate prima di questo passaggio:')
        ->and($messaggio['message'])->toContain('• Ugo → Carlo, Interno A, dal 1 giugno 2026')
        ->and($messaggio['message'])->toContain('Le quote di questo piano sono state calcolate con questo passaggio, che poi è stato annullato:')
        ->and($messaggio['message'])->toContain('• Ugo → Elsa, Interno A, dal 1 maggio 2026')
        ->and([$s->aGiornale('ugo'), $s->aGiornale('elsa'), $s->aGiornale('carlo')])->toBe([0, 0, 0]);
});

it('O-63-8, le frasi del rifiuto — con un passaggio attivo e uno annullato insieme, l\'incasso e il ricalcolo hanno una frase sola con due membri; con i soli annullati, una frase sola al plurale quando sono due', function () {
    $s = b51pBase($this);
    $s->persona('carlo');
    b51pStantio($s);
    $s->passaggio('vendita', 'A', 'ugo', 'carlo', '2026-06-01');
    $piano = $s->piano('Preventivo 2026');

    // Gli attivi hanno la loro frase di sempre; gli annullati la loro. Insieme, una frase sola.
    expect($piano->fraseRicalcolaPrima())->toBe('Ricalcola prima il piano «Preventivo 2026»: le sue quote sono state calcolate prima del passaggio di Ugo su Interno A, dal 1 giugno 2026, '
        . 'e con la titolarità del passaggio di Ugo su Interno A, dal 1 maggio 2026, che poi è stato annullato; un incasso su una sua quota lo fermerebbe prima che il ricalcolo ne tenga conto '
        . 'e le riporti alla titolarità di prima. Dopo il ricalcolo registra l\'incasso.');
    // Dopo il ricalcolo (che rifiuta con un incasso): terrà conto del passaggio attivo e riporterà le quote per quello annullato.
    expect(PianoRate::dopoIlRicalcolo($piano->passaggiDaSeguire()))->toBe('il ricalcolo terrà conto del passaggio di Ugo su Interno A, dal 1 giugno 2026, '
        . 'e riporterà le quote alla titolarità di prima del passaggio di Ugo su Interno A, dal 1 maggio 2026, che è stato annullato');
});

it('O-63-8, i soli annullati — due vendite di seguito (Ugo → Elsa, Elsa → Carlo), il ricalcolo con tutte e due, poi annullate in ordine inverso: la lista ne ha due e la frase dell\'emissione le mette in elenco al plurale', function () {
    $s = b51pBase($this);
    $s->persona('carlo');
    $s->genera('Preventivo 2026');
    $v1 = $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
    $v2 = $s->passaggio('vendita', 'A', 'elsa', 'carlo', '2026-06-01');
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    $s->annulla('A', $v2->id)->assertRedirect();
    $s->annulla('A', $v1->id)->assertRedirect();

    // Le quote del ricalcolo hanno la titolarità di tutte e due, e nascono prima dei due annullamenti: tutte e due da seguire, in ordine di registrazione.
    expect($s->daSeguire('Preventivo 2026'))->toBe([$v1->id . 'a', $v2->id . 'a']);
    $messaggio = b51pMessaggio($s->emetti('Preventivo 2026', '2026-06-30'));
    expect($messaggio['message'])->toBe("Le quote di questo piano sono state calcolate con questi passaggi, che poi sono stati annullati:\n• Ugo → Elsa, Interno A, dal 1 maggio 2026\n• Elsa → Carlo, Interno A, dal 1 giugno 2026"
        . "\n\nRicalcola il piano prima di emettere, perché torni alla titolarità di prima: emesse così, le quote di Elsa e Carlo andrebbero a giornale per passaggi che non ci sono più.");

    // Un solo ricalcolo li libera tutti e due, e le quote tornano a Ugo (120000 su dodici rate: sei rate a giornale fanno 60000).
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    expect($s->daSeguire('Preventivo 2026'))->toBe([]);
    $s->emetti('Preventivo 2026', '2026-06-30')->assertSessionHasNoErrors();
    expect([$s->aGiornale('ugo'), $s->aGiornale('elsa'), $s->aGiornale('carlo')])->toBe([60000, 0, 0]);
});

// --- O-63-9, O-63-10, O-63-15: dove la guardia non scatta -------------------------------------------------------------------

it('guardia — O-63-9: la locazione resta fuori (decisione 32): un inizio locazione annullato dopo il ricalcolo non ferma l\'emissione', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100], ['inquilino' => 30, 'proprietario' => 70])
        ->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026')->titolare('A', 'ugo');
    $s->persona('ines');
    $s->genera('Preventivo 2026');
    $s->passaggio('inizio_locazione', 'A', null, 'ines', '2026-04-01');
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    $s->annulla('A')->assertRedirect();

    expect($s->daSeguire('Preventivo 2026'))->toBe([]);
    $s->emetti('Preventivo 2026', '2026-06-30')->assertSessionHasNoErrors();
    expect(DB::table('rate')->where('piano_rate_id', $s->piani['Preventivo 2026']['p']->id)->where('stato', 'emessa')->count())->toBe(6);
});

it('guardia — O-63-10: con sei rate già a giornale dopo il ricalcolo la vendita non si annulla, e il piano non ha niente da seguire', function () {
    $s = b51pBase($this);
    $s->genera('Preventivo 2026');
    $v = $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    $s->emetti('Preventivo 2026', '2026-06-30')->assertSessionHasNoErrors();

    $r = $s->annulla('A');
    // Le quote toccate dopo il passaggio sono emesse: l'annullamento si rifiuta (quoteNonIntatte).
    expect(Subentro::find($v->id))->not->toBeNull()
        ->and(Subentro::find($v->id)->annullato())->toBeFalse()
        ->and($s->daSeguire('Preventivo 2026'))->toBe([]);
    expect($r->status())->not->toBe(500);
});

it('guardia — O-63-15: vendita datata dopo il piano (1/3/2027), poi annullata: la competenza 2026 finisce prima della decorrenza, nessuna quota cambia e l\'emissione passa', function () {
    $s = b51pBase($this);
    $s->genera('Preventivo 2026');
    $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2027-03-01');
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    $s->annulla('A')->assertRedirect();

    expect($s->daSeguire('Preventivo 2026'))->toBe([]);
    $s->emetti('Preventivo 2026', '2026-06-30')->assertSessionHasNoErrors();
    expect([$s->aGiornale('ugo'), $s->aGiornale('elsa')])->toBe([60000, 0]);
});

it('O-63-15b — piano senza righe di riparto con le quote di chi compra e una vendita datata dopo l\'esercizio (1/3/2027), poi annullata: la decorrenza è oltre il 31/12/2026 e il piano non la segue; la stessa vendita datata 1/5/2026 sì', function () {
    // Il filtro «decorrenza entro la fine dell'esercizio» di `PianoRate::passaggiAnnullatiDaSeguire` conta solo dove la regola delle quote nate dopo non lo
    // dice già da sé: per una quota con il riparto, la competenza che finisce prima della decorrenza (O-63-15) basta; per una senza, come nei piani della 1.10,
    // l'unico segno è che la quota sia di chi è entrato (O-63-13). Qui le quote del piano 2026 sono di Elsa e senza riparto (lo stato che un piano della
    // 1.10 generato dopo la registrazione ha: il motore della 1.10 non leggeva le date e intestava a chi era titolare attivo): il solo dato che separa i due casi
    // è la data. Il 1/3/2027 è dopo la fine dell'esercizio (31/12/2026) e il piano non la segue; il 1/5/2026 è dentro, e la stessa catena la segue.
    foreach ([['2027-03-01', false], ['2026-05-01', true]] as [$il, $daSeguire]) {
        $s = b51pBase($this);
        $s->genera('Preventivo 2026');
        $v = $s->passaggio('vendita', 'A', 'ugo', 'elsa', $il);
        $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
        $pianoId = $s->piani['Preventivo 2026']['p']->id;
        RigaRiparto::where('piano_rate_id', $pianoId)->delete();
        DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $pianoId)->pluck('id'))->update(['anagrafica_id' => $s->persona('elsa')->id]);
        $s->annulla('A')->assertRedirect();

        expect($s->daSeguire('Preventivo 2026'))->toBe($daSeguire ? [$v->id . 'a'] : [], "Con la vendita datata {$il} il piano " . ($daSeguire ? 'dovrebbe' : 'non dovrebbe') . ' seguirla.');
    }
});

// --- O-63-12: la costituzione dell'usufrutto (misurato S4) ------------------------------------------------------------------

it('O-63-12 — costituzione dell\'usufrutto annullata dopo il ricalcolo: come la vendita, l\'emissione si rifiuta e dopo il ricalcolo Ugo torna titolare dei 120000', function () {
    // Piano in bozza, ordinaria all'usufruttuario: la voce si sposta sull'usufruttuario (31.5).
    $s = b51pBase($this, bozza: true);
    $s->genera('Preventivo 2026');
    $c = $s->passaggio('costituzione', 'A', 'ugo', 'elsa', '2026-05-01', ['ordinaria' => 'usufruttuario']);
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    // Conto a mano prima dell'annullamento: Ugo 39452 (ripiego del proprietario per i 120 giorni senza usufruttuario), Elsa 80548 (misurato S4).
    expect($s->somme('Preventivo 2026', ['ugo', 'elsa']))->toBe(['ugo' => 39452, 'elsa' => 80548]);
    $s->annulla('A')->assertRedirect();

    expect($s->daSeguire('Preventivo 2026'))->toBe([$c->id . 'a']);
    $s->emetti('Preventivo 2026', '2026-06-30');
    expect([$s->aGiornale('ugo'), $s->aGiornale('elsa')])->toBe([0, 0]);
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'elsa']))->toBe(['ugo' => 120000, 'elsa' => 0]);
    $s->emetti('Preventivo 2026', '2026-06-30')->assertSessionHasNoErrors();
    expect([$s->aGiornale('ugo'), $s->aGiornale('elsa')])->toBe([60000, 0]);
});

it('O-63-12b — estinzione dell\'usufrutto annullata dopo il ricalcolo (gemello di O-63-12): l\'emissione si rifiuta nominando l\'estinzione e le quote di Ugo, e dopo il ricalcolo Elsa torna usufruttuaria dei 120000', function () {
    // La voce è dell'usufruttuario (art. 1004 c.c.): finché c'è l'usufrutto paga Elsa, estinto passa al proprietario, cioè a Ugo che era nudo.
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100], ['usufruttuario' => 100])
        ->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026')->titolare('A', 'ugo', 'nuda_proprietario')->titolare('A', 'elsa', 'usufruttuario');
    $s->genera('Preventivo 2026');
    expect($s->somme('Preventivo 2026', ['ugo', 'elsa']))->toBe(['ugo' => 0, 'elsa' => 120000]);
    $e = $s->passaggio('estinzione', 'A', 'elsa', null, '2026-05-01');
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    // Conto a mano: Elsa usufruttuaria dal 1/1 al 30/4 = 120 giorni su 365 → 120000 × 120/365 = 39452,05 → 39452; il resto (245 giorni, 80548) va a Ugo, tornato pieno.
    expect($s->somme('Preventivo 2026', ['ugo', 'elsa']))->toBe(['ugo' => 80548, 'elsa' => 39452]);
    $s->annulla('A')->assertRedirect();

    expect($s->daSeguire('Preventivo 2026'))->toBe([$e->id . 'a']);
    $messaggio = b51pMessaggio($s->emetti('Preventivo 2026', '2026-06-30'));
    expect($messaggio['message'])->toBe(b51pRifiuto('estinzione dell\'usufrutto di Elsa, Interno A, dal 1 maggio 2026', 'Ugo'))
        ->and([$s->aGiornale('ugo'), $s->aGiornale('elsa')])->toBe([0, 0]);

    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'elsa']))->toBe(['ugo' => 0, 'elsa' => 120000]);
    $s->emetti('Preventivo 2026', '2026-06-30')->assertSessionHasNoErrors();
    expect([$s->aGiornale('ugo'), $s->aGiornale('elsa')])->toBe([0, 60000]);
});

// --- O-63-13: il piano senza righe di riparto -------------------------------------------------------------------------------

it('O-63-13 — piano senza righe di riparto (la forma SR dei piani 1.7–1.10): le quote di chi è entrato bastano a far scattare la guardia', function () {
    $s = b51pBase($this);
    $s->genera('Preventivo 2026');
    $v = $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    RigaRiparto::where('piano_rate_id', $s->piani['Preventivo 2026']['p']->id)->delete();
    $s->annulla('A')->assertRedirect();

    // Ripiego di quoteNateDopo: «è fra gli entrati» (le quote di Elsa sono di chi è entrato), senza guardare le righe di riparto.
    expect($s->daSeguire('Preventivo 2026'))->toBe([$v->id . 'a']);
    $r = $s->emetti('Preventivo 2026', '2026-06-30');
    expect(b51pMessaggio($r)['type'] ?? null)->toBe('error')
        ->and([$s->aGiornale('ugo'), $s->aGiornale('elsa')])->toBe([0, 0]);
});

// --- O-63-16, O-63-17: la seconda vendita sulla stessa unità (S3a, S3b, S3c) -----------------------------------------------

it('O-63-16 — seconda vendita sulla stessa unità dopo un annullato stantio, piano libero: la lista passa da [V1 annullato] a [V2, V1 annullato] e si svuota col ricalcolo', function () {
    $s = b51pDue($this);
    $s->genera('Preventivo 2026');
    $v1 = $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    $s->annulla('A')->assertRedirect();
    expect($s->daSeguire('Preventivo 2026'))->toBe([$v1->id . 'a']);

    $v2 = $s->passaggio('vendita', 'A', 'ugo', 'carlo', '2026-06-01');
    expect($s->daSeguire('Preventivo 2026'))->toBe([(string) $v2->id, $v1->id . 'a']);

    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    // Conto a mano: A pesa 500/1000 di 120000 = 60000. Ugo dal 1/1 al 31/5 (151 giorni): 60000 × 151/365 = 24821,9 → 24822; Carlo 214 giorni: 35178. Rita 60000.
    expect($s->daSeguire('Preventivo 2026'))->toBe([])
        ->and($s->somme('Preventivo 2026', ['ugo', 'elsa', 'carlo', 'rita']))->toBe(['ugo' => 24822, 'elsa' => 0, 'carlo' => 35178, 'rita' => 60000]);
});

/** S3b: V1 registrata e ricalcolata, un movimento di Rita su B (il piano non si riscrive più), V1 annullata. Il piano è stantio e fermo. */
function b51pStantioEFermo($test): array
{
    $s = b51pDue($test);
    $s->genera('Preventivo 2026');
    $v1 = $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    $quota = b51pMovimento($s, 'rita');
    $s->annulla('A')->assertRedirect();

    return [$s, $v1, $quota];
}

/** La frase con cui un passaggio si rifiuta se il suo conguaglio prenderebbe il piano stantio e fermo di `b51pStantioEFermo`. */
function b51pRifiutoDelConguaglio(): string
{
    return 'Ricalcola prima il piano «Preventivo 2026», poi registra questo passaggio: le sue quote sono state calcolate con la titolarità del passaggio di Ugo su Interno A, dal 1 maggio 2026, '
        . 'che poi è stato annullato, e il conguaglio di questo passaggio le prenderebbe così come sono, intestate a chi non è più titolare. '
        . 'Oggi il ricalcolo si rifiuta perché il piano ha un incasso su una sua quota. Per procedere: annulla quel movimento; ricalcola il piano; registra di nuovo quel movimento.';
}

it('O-63-17 — annullo e rifaccio la vendita su un piano stantio e fermo da un movimento: la registrazione si rifiuta con «ricalcola prima il piano», l\'anteprima lo dice con un avviso e tiene il pannello, e non si scrive niente', function (string $a) {
    [$s, $v1] = b51pStantioEFermo($this);
    expect($s->daSeguire('Preventivo 2026'))->toBe([$v1->id . 'a']);

    // Senza il rifiuto il conguaglio avrebbe scritto la coppia sulla base stantia: dopo V1 e il ricalcolo Ugo ha 60000 × 120/365 = 19726 (12 rate da 1644, le prime dieci
    // con un centesimo in più), le rate 6–12 valgono 5 × 1644 + 2 × 1643 = 11506 e passerebbero a chi compra con la coppia Ugo +11506 / chi compra −11506. Con il piano
    // di prima della vendita annullata, 5000 a rata, le bozze 6–12 valgono 35000 e la coppia con Elsa è −178 / +178 (60000 × 214/365 = 35178 meno 35000).
    $dati = $s->modulo('vendita', 'A', 'ugo', $a, '2026-06-01');
    // L'anteprima risponde (200) con lo stesso testo del rifiuto e con il conguaglio proposto: il pannello deve poter mostrare la casella della rinuncia.
    $anteprima = $this->actingAs($s->admin)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s->c, $s->unita['A']]), $dati);
    $anteprima->assertOk();
    expect($anteprima->json('rate.piano_da_ricalcolare'))->toBe(b51pRifiutoDelConguaglio())
        ->and($anteprima->json('rate.stato'))->toBe('calcolato')
        ->and($anteprima->json('rate.conguaglio.coppie'))->not->toBeEmpty();

    $registrazione = $this->actingAs($s->admin)->postJson(route('admin.gestionale.immobili.passaggi.store', [$s->c, $s->unita['A']]), $dati + ['ho_letto' => true, 'nota_cancello' => 'Letto: il pannello è stato controllato nella prova']);
    $registrazione->assertStatus(422);
    expect($registrazione->json('errors.passaggio.0'))->toBe(b51pRifiutoDelConguaglio())
        ->and(Subentro::conAnnullati()->count())->toBe(1)
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->count())->toBe(0)
        ->and(DB::table('anagrafica_immobile')->where('immobile_id', $s->unita['A']->id)->where('anagrafica_id', $s->persona($a)->id)->exists())->toBeFalse();
})->with(['la stessa vendita con la data giusta' => ['elsa'], 'un\'altra vendita' => ['carlo']]);

it('O-63-17, «Non scriverlo» — senza la coppia il passaggio non prende niente di stantio: si registra, il piano resta da ricalcolare e la coppia non c\'è', function () {
    [$s, $v1] = b51pStantioEFermo($this);

    $v2 = $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-06-01', ['rinuncia' => 'Le parti hanno regolato il conguaglio fra loro']);

    expect(DB::table('saldi')->where('subentro_id', $v2->id)->count())->toBe(0)
        ->and($s->daSeguire('Preventivo 2026'))->toBe([$v1->id . 'a']);
});

it('O-63-17, la casella — il modulo la manda nell\'anteprima: senza la ragione l\'avviso resta, con la ragione sparisce, e la registrazione passa solo con la casella e senza coppia', function () {
    [$s, $v1] = b51pStantioEFermo($this);
    $dati = $s->modulo('vendita', 'A', 'ugo', 'elsa', '2026-06-01');
    $rotta = fn (string $nome) => route("admin.gestionale.immobili.passaggi.$nome", [$s->c, $s->unita['A']]);
    $rinuncia = ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato il conguaglio fra loro'];

    // La casella spenta, o spuntata senza la ragione che il server pretende: l'avviso c'è, la via d'uscita è ancora tutta da fare.
    expect($this->actingAs($s->admin)->postJson($rotta('anteprima'), $dati + ['rinuncia_conguaglio' => false])->assertOk()->json('rate.piano_da_ricalcolare'))->toBe(b51pRifiutoDelConguaglio());
    $this->actingAs($s->admin)->postJson($rotta('anteprima'), $dati + ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'corta'])->assertStatus(422)->assertJsonValidationErrors('nota_conguaglio');

    // Spuntata con la ragione: l'anteprima non ha più l\'avviso, il conguaglio proposto resta quello (la rinuncia non lo cambia) e il pannello lo mostra come non scritto.
    $libera = $this->actingAs($s->admin)->postJson($rotta('anteprima'), $dati + $rinuncia)->assertOk();
    expect($libera->json('rate.piano_da_ricalcolare'))->toBeNull()
        ->and($libera->json('rate.conguaglio.coppie'))->not->toBeEmpty();

    // La registrazione: senza la casella si rifiuta, con la casella passa e non scrive nessuna riga in saldi.
    $this->actingAs($s->admin)->postJson($rotta('store'), $dati + ['ho_letto' => true, 'nota_cancello' => 'Letto: il pannello è stato controllato nella prova'])->assertStatus(422)->assertJsonValidationErrors('passaggio');
    $scritta = $this->actingAs($s->admin)->postJson($rotta('store'), $dati + $rinuncia + ['ho_letto' => true, 'nota_cancello' => 'Letto: il pannello è stato controllato nella prova']);
    expect($scritta->status())->toBeLessThan(400);
    $v2 = Subentro::where('immobile_id', $s->unita['A']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
    expect($v2->id)->not->toBe($v1->id)
        ->and(DB::table('saldi')->where('subentro_id', $v2->id)->count())->toBe(0)
        ->and($v2->conguaglioRinunciato())->toBeTrue()
        ->and($s->daSeguire('Preventivo 2026'))->toBe([$v1->id . 'a']);
});

it('O-63-17, la via d\'uscita — annulla il movimento, ricalcola e registra di nuovo il movimento: il passaggio si registra con la coppia del piano di prima, Ugo −178 ed Elsa +178', function () {
    [$s, , $quota] = b51pStantioEFermo($this);

    DB::table('rate_quote')->where('id', $quota)->update(['importo_pagato' => 0]);
    $s->ricalcola('Preventivo 2026')->assertSessionHasNoErrors();
    b51pMovimento($s, 'rita');
    expect($s->daSeguire('Preventivo 2026'))->toBe([])
        ->and($s->somme('Preventivo 2026', ['ugo', 'elsa', 'rita']))->toBe(['ugo' => 60000, 'elsa' => 0, 'rita' => 60000]);

    $v2 = $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-06-01');

    // Conto a mano: A vale 60000, 12 rate da 5000. Ugo fino al 31/5 (151 giorni): 60000 × 151/365 = 24822; Elsa dal 1/6 (214 giorni): 35178. Le bozze 6–12 (7 × 5000 = 35000)
    // passano a Elsa, che deve 35178: la coppia è +178 a lei e −178 a Ugo.
    $coppia = DB::table('saldi')->where('subentro_id', $v2->id)->pluck('saldo_iniziale', 'anagrafica_id')->map(fn ($x) => (int) $x)->all();
    expect($coppia)->toBe([$s->persona('ugo')->id => -178, $s->persona('elsa')->id => 178]);
});

it('O-63-17, la successione — dove il conguaglio si sceglie, «Scrivi» ha l\'avviso e non si registra, «Non scriverlo» toglie l\'avviso e si registra senza coppia, e finché la scelta manca l\'avviso non c\'è (il modulo mostra le due voci)', function () {
    [$s, $v1] = b51pStantioEFermo($this);
    $dati = $s->modulo('successione', 'A', 'ugo', null, '2026-06-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => Subentro::ARRETRATO_AL_DEFUNTO, 'riferimento' => 'anna']);
    $anteprima = fn (array $d) => $this->actingAs($s->admin)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s->c, $s->unita['A']]), $d);
    $letto = ['ho_letto' => true, 'nota_cancello' => 'Letto: il pannello è stato controllato nella prova'];

    // «Scrivi»: la coppia del riferimento sarebbe divisa sulla base stantia. L'anteprima lo dice e porta con sé la scelta, perché «Non scriverlo» si raggiunge da lì.
    $scrivi = $anteprima($dati)->assertOk();
    expect($scrivi->json('rate.piano_da_ricalcolare'))->toBe(b51pRifiutoDelConguaglio())
        ->and($scrivi->json('rate.scelta_del_conguaglio'))->toBeTrue()
        ->and($scrivi->json('rate.conguaglio.coppie'))->not->toBeEmpty();
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.immobili.passaggi.store', [$s->c, $s->unita['A']]), $dati + $letto)
        ->assertStatus(422)->assertJsonValidationErrors('passaggio');
    expect(Subentro::conAnnullati()->count())->toBe(1);

    // La scelta non è ancora fatta: nessun avviso, il pannello c'è con le due voci.
    $scelta = $anteprima(array_diff_key($dati, ['conguaglio' => 1]))->assertOk();
    expect($scelta->json('rate.piano_da_ricalcolare'))->toBeNull()
        ->and($scelta->json('rate.scelta_del_conguaglio'))->toBeTrue();

    // «Non scriverlo»: nessuna coppia, nessun avviso, e la registrazione passa.
    $nonScrivere = ['conguaglio' => Subentro::NON_SCRIVERE_IL_CONGUAGLIO] + $dati;
    expect($anteprima($nonScrivere)->assertOk()->json('rate.piano_da_ricalcolare'))->toBeNull();
    $scritta = $this->actingAs($s->admin)->postJson(route('admin.gestionale.immobili.passaggi.store', [$s->c, $s->unita['A']]), $nonScrivere + $letto);
    expect($scritta->status())->toBeLessThan(400);
    $v2 = Subentro::where('immobile_id', $s->unita['A']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
    expect($v2->id)->not->toBe($v1->id)
        ->and(DB::table('saldi')->where('subentro_id', $v2->id)->count())->toBe(0)
        ->and($v2->conguaglioNonScritto())->toBeTrue()
        ->and($s->daSeguire('Preventivo 2026'))->toBe([$v1->id . 'a']);
});

it('guardia — O-63-17: il passaggio di un\'altra unità dello stesso piano non è fermato dal passaggio annullato su A: il suo conguaglio non prende le quote di A', function () {
    [$s, $v1] = b51pStantioEFermo($this);

    $v3 = $s->passaggio('vendita', 'B', 'rita', 'carlo', '2026-06-01');

    expect(DB::table('saldi')->where('subentro_id', $v3->id)->count())->toBe(2)
        ->and($s->daSeguire('Preventivo 2026'))->toBe([$v1->id . 'a']);
});

it('O-63-8, presiDaSeguire — il piano preso dal conguaglio del passaggio su B e ancora da seguire V1 annullato su A: annullare il passaggio su B, la cui coppia è assorbita da un secondo piano fermo, dice che non può restare perché il primo piano non si emette senza ricalcolo', function () {
    // S3b: V1 su A registrata, piano ricalcolato, un movimento di Rita (il piano non si riscrive più), V1 annullata. Il passaggio su B ha preso il piano nel conguaglio,
    // e quel piano deve ancora seguire V1. Un secondo piano della stessa gestione assorbe la coppia del passaggio su B e ha un movimento.
    [$s] = b51pStantioEFermo($this);
    $v3 = $s->passaggio('vendita', 'B', 'rita', 'carlo', '2026-06-01');
    expect($s->piano('Preventivo 2026')->presoNelConguaglioDa($v3))->toBeTrue();
    $s->voce('Ordinaria 2026', 'Pulizia', 24000, ['Proprietà' => 100])->pianoOrdinario('Pulizia 2026', 'Ordinaria 2026', 6, '2026-07-05', capitoli: true);
    $s->piani['Pulizia 2026']['p']->capitoli()->detach($s->voci['Spese generali']['conto']->id);
    $s->genera('Pulizia 2026');
    expect(DB::table('saldi')->where('subentro_id', $v3->id)->where('is_applicato', true)->count())->toBe(2);
    b51pMovimento($s, 'ugo', 'Pulizia 2026');

    $r = $s->annulla('B', $v3->id);

    // Prima della .51 la lista dei passaggi da seguire non vedeva V1 annullato, e la frase diceva «il passaggio può restare»: falso, perché
    // quel piano non si emette senza ricalcolo e il ricalcolo è fermo dal movimento. Ora il piano è nominato.
    $r->assertStatus(422);
    expect($r->json('errors.passaggio.0'))->toContain('Il passaggio non può restare così: il piano «Preventivo 2026», preso da questo passaggio, deve ancora seguire un passaggio e non si emette senza ricalcolo.')
        ->not->toContain('Il passaggio può restare');
});

// --- O-63-18, O-63-19: la straordinaria prima e dopo la decorrenza (S5) -----------------------------------------------------

/** Straordinaria Lavori € 1.000,00 deliberata il giorno dato, quattro rate dal 5/6; vendita Ugo → Elsa il 1/5, ricalcolo, annullamento. */
function b51pStraordinariaStantia($test, string $delibera): array
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000])->gestione('Straordinaria', 'straordinaria')
        ->voce('Straordinaria', 'Lavori', 100000, ['Proprietà' => 100])
        ->pianoStraordinario('Lavori 2026', 'Straordinaria', ['Lavori' => 100000], $delibera, 4, '2026-06-05')->titolare('A', 'ugo');
    $s->persona('elsa');
    $s->genera('Lavori 2026');
    $v = $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
    $s->ricalcola('Lavori 2026')->assertSessionHasNoErrors();
    $dopoIlRicalcolo = $s->somme('Lavori 2026', ['ugo', 'elsa']);
    $s->annulla('A')->assertRedirect();

    return [$s, $v, $dopoIlRicalcolo];
}

it('guardia — O-63-18: straordinaria deliberata il 10/2, prima della decorrenza: la competenza è di Ugo con o senza la vendita, nessuna quota cambia e la guardia non scatta', function () {
    [$s, , $dopoIlRicalcolo] = b51pStraordinariaStantia($this, '2026-02-10');

    expect($dopoIlRicalcolo)->toBe(['ugo' => 100000, 'elsa' => 0])
        ->and($s->daSeguire('Lavori 2026'))->toBe([]);
    $s->emetti('Lavori 2026', '2026-06-30')->assertSessionHasNoErrors();
});

it('O-63-19 — straordinaria deliberata il 20/5, dopo la decorrenza: la competenza è di Elsa, annullata la vendita le quote tornano a Ugo e la guardia scatta', function () {
    [$s, $v, $dopoIlRicalcolo] = b51pStraordinariaStantia($this, '2026-05-20');

    // Conto a mano: delibera del 20/5 → titolare Elsa: Elsa 100000, Ugo 0 dopo il ricalcolo con la vendita (misurato S5).
    expect($dopoIlRicalcolo)->toBe(['ugo' => 0, 'elsa' => 100000])
        ->and($s->daSeguire('Lavori 2026'))->toBe([$v->id . 'a']);
    $s->emetti('Lavori 2026', '2026-06-30');
    expect([$s->aGiornale('ugo'), $s->aGiornale('elsa')])->toBe([0, 0]);

    $s->ricalcola('Lavori 2026')->assertSessionHasNoErrors();
    expect($s->somme('Lavori 2026', ['ugo', 'elsa']))->toBe(['ugo' => 100000, 'elsa' => 0]);
});

// --- DT1 e il conto delle query ---------------------------------------------------------------------------------------------

it('DT1 — i piani che l\'annullamento nomina negli avvisi sono gli stessi per cui, dopo l\'annullamento, il piano deve seguire il passaggio annullato (vendita, successione, box, senza righe di riparto, due piani)', function () {
    $storie = [
        'vendita' => function (Beta51Scenario $s) {
            return $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
        },
        'successione' => function (Beta51Scenario $s) {
            return $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
        },
        'vendita con il box' => function (Beta51Scenario $s) {
            return $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01', ['pertinenze' => ['BX']]);
        },
        'senza righe di riparto' => function (Beta51Scenario $s) {
            $v = $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
            $s->ricalcola('Preventivo 2026');
            RigaRiparto::where('piano_rate_id', $s->piani['Preventivo 2026']['p']->id)->delete();

            return $v;
        },
        'due piani, uno solo ricalcolato' => function (Beta51Scenario $s) {
            $v = $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
            $s->ricalcola('Preventivo 2026');

            return $v;
        },
    ];
    foreach ($storie as $nome => $storia) {
        $s = Beta51Scenario::nuovo($this)->pertinenza('BX', 'A')->tabella('Proprietà', ['A' => 900, 'BX' => 100])->gestione('Ordinaria 2026')
            ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026')
            ->gestione('Riscaldamento')->voce('Riscaldamento', 'Caldaia', 60000, ['Proprietà' => 100])->pianoOrdinario('Caldaia 2026', 'Riscaldamento')
            ->titolare('A', 'ugo')->titolare('BX', 'ugo');
        $s->persona('elsa');
        $s->persona('anna');
        $s->persona('bruno');
        $s->genera('Preventivo 2026');
        $s->genera('Caldaia 2026');
        $passaggio = $storia($s);
        // Il secondo piano (Caldaia) resta com'è prima del passaggio: solo Preventivo è stato ricalcolato con Elsa/gli eredi, nei casi che lo ricalcolano.
        if (in_array($nome, ['vendita', 'successione', 'vendita con il box'], true)) {
            $s->ricalcola('Preventivo 2026');
        }

        $avvisi = implode("\n", app(AnnullaPassaggioAction::class)->avvisi($passaggio));
        preg_match_all('/«([^»]+)»/u', $avvisi, $m);
        $nominati = collect($m[1])->unique()->sort()->values()->all();
        $s->annulla('A')->assertRedirect();
        $daSeguire = collect(array_keys($s->piani))->filter(fn (string $p) => in_array($passaggio->id . 'a', $s->daSeguire($p), true))->sort()->values()->all();

        // Per i piani senza rate a giornale: con una rata emessa passaggiDaSeguire è vuoto per costruzione e l'annullamento si rifiuta.
        $this->assertNotSame([], $nominati, "storia «{$nome}»: il confronto non deve essere vuoto");
        $this->assertSame($nominati, $daSeguire, "storia «{$nome}»");
    }
});

it('guardia — le query per sapere cosa deve seguire il piano crescono col numero degli annullati, non raddoppiano: cinque vendite registrate e annullate di fila', function () {
    $s = b51pBase($this);
    $s->genera('Preventivo 2026');
    $misure = [];
    for ($i = 1; $i <= 5; $i++) {
        $s->persona("compratore{$i}");
        $s->passaggio('vendita', 'A', 'ugo', "compratore{$i}", '2026-05-01');
        $s->annulla('A')->assertRedirect();
        if (in_array($i, [1, 3, 5], true)) {
            $piano = $s->piano('Preventivo 2026');
            DB::flushQueryLog();
            DB::enableQueryLog();
            $piano->passaggiDaSeguire();
            $misure[$i] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
    }

    // Cinque query per ogni annullato che passa il filtro, più quella sugli annullati: lineare. Mai raddoppi (V6 della .42).
    expect(Subentro::conAnnullati()->count())->toBe(5)
        ->and($misure[5] - $misure[3])->toBeLessThanOrEqual($misure[3] - $misure[1] + 2)
        ->and($misure[5])->toBeLessThan(100);
});
