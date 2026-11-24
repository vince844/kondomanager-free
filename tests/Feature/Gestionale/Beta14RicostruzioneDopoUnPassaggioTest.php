<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14 (Coda 681) — un passaggio su un'unità non cambia la parte personale delle quote delle altre unità di un piano della 1.9 o della 1.10.
 *
 * Un piano straordinario senza il registro del riparto (le stabili 1.9 e 1.10) legge la parte personale delle sue quote dalla ricostruzione
 * (`RataQuote::righeRicostruiteAdPersonam`), che rifà il riparto con le titolarità di oggi e si usa solo se torna con le quote per persona e unità.
 * Prima, un passaggio registrato dopo il piano su un'altra unità dava la spesa ricostruita di quella unità a chi entra, mentre le bozze passate a lui
 * restano, per il riparto, di chi esce (`riassegnazione.righe_di`): il confronto non tornava e la ricostruzione diventava nulla per tutto il piano.
 * Il controllo «CA» del rendiconto cambiava esito, e le guardie dello storno e delle note di credito non vedevano più la spesa che la rata emessa
 * dell'Interno 1 aveva chiesto. Ora la ricostruzione non conta le righe di titolarità aperte da un passaggio registrato dopo il piano: la spesa
 * ricostruita della loro unità torna alle righe che c'erano, anche chiuse, per le loro quote, come il giorno in cui il piano è nato; anche in
 * comproprietà (rilievo 2 dello scettico della tappa 2: con l'Interno 2 di Bice e Dario al 50 % la ricostruzione restava nulla). E il controllo
 * «CA» dice «non calcolabile», con il motivo, un'unità di un piano che non si ricostruisce per un'altra ragione, invece di «quadra» o «non quadra».
 *
 * Il caso: Mario Rossi sull'Interno 1, Bice Bianchi sull'Interno 2 (500 millesimi ciascuno); la fattura FT-313 con € 500,00 di spesa comune e
 * € 100,00 sull'Interno 1; il piano straordinario «Balcone interno 1» in due rate da € 300,00 (per rata: € 125,00 a testa di spesa comune, € 50,00
 * di spesa personale sull'Interno 1), la rata n. 1 emessa, senza il registro del riparto come i piani della 1.10. «CA»: chieste con rate non emesse
 * € 50,00 (la rata n. 2) = anticipazioni aperte € 50,00 (€ 100,00 della fattura − € 50,00 chiusi dalla rata n. 1).
 *
 * La catena: il piano della 1.10 → «CA» dalla pagina dei controlli → il passaggio sull'Interno 2 dalla rotta → «CA» e la nota di credito dalla rotta
 * → l'annullamento dalla rotta → «CA».
 *
 * Cosa NON copre: i piani generati dalla 1.11.0-beta.29, che hanno il registro e non si ricostruiscono.
 *
 * Le cifre sono in centesimi. Prefisso `b14rp`: Pest carica tutti i file nello stesso processo.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59313AdPersonamScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b59apAdmin();
});

/**
 * Il caso: il piano «Balcone interno 1» con la rata n. 1 emessa e senza il registro del riparto, come lo ha lasciato la 1.10; e Carla Neri, che non
 * è ancora di nessuna unità.
 */
function b14rpCaso($test): array
{
    $test->travelTo('2026-03-20 10:00:00');
    $s = b59apBase(['Mario Rossi' => 100], 'ordinaria');
    $s['fattura'] = b59apFattura($test, $s, [b59apRigaComune(500.00), b59apRigaAdPersonam($s, 100.00, 0)]);
    $s['piano'] = b59apPiano($test, $s, $s['fattura'], 2, '600,00');
    b59apEmetti($test, $s, $s['piano'], [1])->assertSessionHas('message.type', 'success');
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $s['carla'] = Anagrafica::forceCreate(['nome' => 'Carla Neri', 'email' => 'b14rp-carla@test.it', 'indirizzo' => 'Via Po 2', 'codice_fiscale' => 'B14RPCARLAXXXXXX']);
    $s['carla']->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $s;
}

/** Il controllo «CA» dalla scheda «Controlli» del rendiconto, letto il 31/12/2026. */
function b14rpCa($test, array $s): array
{
    $test->travelTo('2026-12-31 12:00:00');
    $ca = collect($test->actingAs($test->user)->get(route('admin.gestionale.esercizi.rendiconto.controlli', [$s['c'], $s['e']]))
        ->assertOk()->viewData('page')['props']['controlli'])->firstWhere('id', 'CA');

    return ['esito' => $ca['esito'], 'sinistra' => (int) $ca['sinistra'], 'destra' => (int) $ca['destra'], 'nota' => (string) $ca['nota']];
}

/** La nota di credito del fornitore collegata alla fattura, con una riga sull'Interno 1, dal modulo della fattura: la risposta. */
function b14rpNota($test, array $s, float $imponibile = 20.00)
{
    session()->forget(['message', 'errors']);

    return $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', $s['c']->id), ['fattura_rettificata_id' => $s['fattura']->id, 'conferma_avviso_nota' => true]
        + datiBase([$s['c'], $s['e'], $s['g'], $s['fornitore']], [
            'tipo_documento' => 'nota_credito', 'numero_documento' => 'NC-681', 'data_documento' => '2026-06-10', 'data_scadenza' => '2026-07-10',
            'stato_approvazione' => 'approvata', 'applica_ritenuta' => false, 'righe' => [b59apRigaAdPersonam($s, $imponibile, 0, '1', 'Sconto riparazione')],
            'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'fuori_campo'], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => null],
        ]));
}

/** La vendita dell'Interno 2 da Bice Bianchi a Carla Neri dal 01/05/2026, registrata il 20/11 dalla rotta del passaggio. */
function b14rpVendita($test, array $s)
{
    $test->travelTo('2026-11-20 10:00:00');

    return $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['2']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['righeTitolarita']['bice'], 'anagrafica_entrante_id' => $s['carla']->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'atto notaio Verdi, rep. 681',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ]);
}

/** La successione di Bice Bianchi sull'Interno 2, morta il 01/05/2026, erede Carla Neri, registrata il 20/11 dalla rotta del passaggio. */
function b14rpSuccessione($test, array $s)
{
    $test->travelTo('2026-11-20 10:00:00');

    return $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['2']]), [
        'tipo' => 'successione', 'riga_uscente_id' => $s['righeTitolarita']['bice'], 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $s['carla']->id, 'quota' => 100]], 'arretrato' => Subentro::ARRETRATO_AL_DEFUNTO, 'conguaglio' => Subentro::SCRIVI_IL_CONGUAGLIO,
        'erede_di_riferimento' => $s['carla']->id, 'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione n. 681', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta',
    ]);
}

/** La quota della rata n. 2 sull'Interno 2: a chi è, e di chi è il riparto (`riassegnazione.righe_di`). */
function b14rpQuotaInterno2(array $s): array
{
    $q = RataQuote::where('rata_id', b59apRata($s['piano'], 2)->id)->where('immobile_id', $s['unita']['2']->id)->where('tipo', '!=', 'saldo_iniziale')->firstOrFail();

    return [(int) $q->anagrafica_id, $q->regole_calcolo['riassegnazione']['righe_di'] ?? null];
}

// ─── La vendita ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

test('Coda 681 — una vendita sull\'Interno 2: la bozza passa a chi compra con il riparto di chi vende, e la ricostruzione del piano resta', function () {
    // La rata n. 2 (scadenza 10/05) è in bozza: passa a Carla, con `righe_di` di Bice, come nella successione. La ricostruzione dà all'Interno 1
    // € 100,00 di spesa personale (riga FT-313) a Mario, prima e dopo.
    $s = b14rpCaso($this);
    $rossi = $s['persone']['mario'];
    $bice = $s['persone']['bice'];
    $attesa = [['anagrafica_id' => (int) $rossi->id, 'immobile_id' => (int) $s['unita']['1']->id, 'importo' => 10000]];
    $senzaRiga = fn (?array $righe) => $righe === null ? null : array_map(fn ($r) => array_diff_key($r, ['riga_fattura_id' => 0]), $righe);
    expect($senzaRiga(RataQuote::righeRicostruiteAdPersonam($s['piano']->fresh())))->toBe($attesa);

    b14rpVendita($this, $s)->assertSessionHasNoErrors();

    expect(b14rpQuotaInterno2($s))->toBe([(int) $s['carla']->id, (int) $bice->id])
        ->and($senzaRiga(RataQuote::righeRicostruiteAdPersonam($s['piano']->fresh())))->toBe($attesa);
});

test('Coda 681 — una vendita sull\'Interno 2: «CA» uguale prima e dopo, e la nota di credito sulla riga dell\'Interno 1 si rifiuta prima e dopo', function () {
    $s = b14rpCaso($this);
    $prima = b14rpCa($this, $s);
    expect($prima['esito'])->toBe('quadra')->and([$prima['sinistra'], $prima['destra']])->toBe([5000, 5000]);
    $this->travelTo('2026-06-10 10:00:00');
    b14rpNota($this, $s)->assertSessionHasErrors('fattura_rettificata_id');

    b14rpVendita($this, $s)->assertSessionHasNoErrors();

    $dopo = b14rpCa($this, $s);
    expect($dopo)->toBe($prima);
    $this->travelTo('2026-11-21 10:00:00');
    b14rpNota($this, $s)->assertSessionHasErrors('fattura_rettificata_id');
    expect(FatturaPassiva::where('numero_documento', 'NC-681')->exists())->toBeFalse();
});

test('Coda 681 — due vendite sull\'Interno 2, da Bice a Carla e da Carla a Dario: la ricostruzione resta, con la spesa personale a Mario', function () {
    // Bice vende a Carla dal 01/05: la rata n. 2 (scadenza 10/05) passa a Carla, con il riparto di Bice. Carla vende a Dario dal 01/09: la rata n. 2
    // è scaduta prima e resta a Carla. Dario entra nella ricostruzione dell'Interno 2 senza quote: la sua spesa ricostruita va a chi il piano l'ha
    // chiesta, Bice, l'unico soggetto delle quote dell'unità. «CA» resta € 50,00 = € 50,00.
    $s = b14rpCaso($this);
    $prima = b14rpCa($this, $s);
    $dario = Anagrafica::forceCreate(['nome' => 'Dario Gialli', 'email' => 'b14rp-dario@test.it', 'indirizzo' => 'Via Po 4', 'codice_fiscale' => 'B14RPDARIOXXXXXX']);
    $dario->condomini()->syncWithoutDetaching([$s['c']->id]);
    b14rpVendita($this, $s)->assertSessionHasNoErrors();
    $rigaCarla = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['carla']->id)->where('immobile_id', $s['unita']['2']->id)->value('id');
    $this->travelTo('2026-11-20 11:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['2']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $rigaCarla, 'anagrafica_entrante_id' => $dario->id, 'decorrenza' => '2026-09-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-09-06', 'estremi_titolo' => 'atto notaio Verdi, rep. 682',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ])->assertSessionHasNoErrors();

    expect(b14rpQuotaInterno2($s))->toBe([(int) $s['carla']->id, (int) $s['persone']['bice']->id])
        ->and(RataQuote::registroRicostruito($s['piano']->fresh()))->toBe([$s['piano']->id.'|'.$s['persone']['mario']->id.'|'.$s['unita']['1']->id => 10000])
        ->and(b14rpCa($this, $s))->toBe($prima);
});

// ─── La successione ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

test('Coda 681 — la catena: «CA» e la nota di credito prima della successione sull\'Interno 2, dopo, e dopo l\'annullamento', function () {
    $s = b14rpCaso($this);
    $prima = b14rpCa($this, $s);
    expect($prima)->toBe(['esito' => 'quadra', 'sinistra' => 5000, 'destra' => 5000,
        'nota' => 'Per l\'unità con spese per singole unità: non ancora chieste € 0,00 + chieste con rate non emesse € 50,00 = anticipazioni aperte € 50,00.']);
    $this->travelTo('2026-06-10 10:00:00');
    b14rpNota($this, $s)->assertSessionHasErrors('fattura_rettificata_id');

    b14rpSuccessione($this, $s)->assertSessionHasNoErrors();
    $successione = Subentro::where('immobile_id', $s['unita']['2']->id)->where('tipo_passaggio', 'successione')->firstOrFail();

    expect(b14rpQuotaInterno2($s))->toBe([(int) $s['carla']->id, (int) $s['persone']['bice']->id])
        ->and(RataQuote::registroRicostruito($s['piano']->fresh()))->toBe([$s['piano']->id.'|'.$s['persone']['mario']->id.'|'.$s['unita']['1']->id => 10000])
        ->and(b14rpCa($this, $s))->toBe($prima);
    $this->travelTo('2026-11-21 10:00:00');
    b14rpNota($this, $s)->assertSessionHasErrors('fattura_rettificata_id');

    $this->travelTo('2026-11-22 10:00:00');
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['2'], $successione]), ['nota_annullamento' => 'Successione registrata sull\'unità sbagliata'])
        ->assertRedirect();
    expect($successione->fresh()->annullato_il)->not->toBeNull()
        ->and(b14rpQuotaInterno2($s))->toBe([(int) $s['persone']['bice']->id, null])
        ->and(b14rpCa($this, $s))->toBe($prima);
});

test('Coda 681 — una successione con due eredi sull\'Interno 2: l\'erede che non riceve le bozze non rompe la ricostruzione', function () {
    // Eredi Carla (di riferimento, riceve la rata n. 2) e Dario, al 50 %: Dario entra nella ricostruzione dell'Interno 2 ma non ha quote; la sua
    // spesa ricostruita va a chi il piano l'ha chiesta, Bice, l'unico soggetto delle quote dell'unità.
    $s = b14rpCaso($this);
    $prima = b14rpCa($this, $s);
    $dario = Anagrafica::forceCreate(['nome' => 'Dario Gialli', 'email' => 'b14rp-dario@test.it', 'indirizzo' => 'Via Po 4', 'codice_fiscale' => 'B14RPDARIOXXXXXX']);
    $dario->condomini()->syncWithoutDetaching([$s['c']->id]);
    $this->travelTo('2026-11-20 10:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['2']]), [
        'tipo' => 'successione', 'riga_uscente_id' => $s['righeTitolarita']['bice'], 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $s['carla']->id, 'quota' => 50], ['anagrafica_id' => $dario->id, 'quota' => 50]], 'arretrato' => Subentro::ARRETRATO_AL_DEFUNTO,
        'conguaglio' => Subentro::SCRIVI_IL_CONGUAGLIO, 'erede_di_riferimento' => $s['carla']->id, 'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione n. 683',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta',
    ])->assertSessionHasNoErrors();

    expect(b14rpQuotaInterno2($s))->toBe([(int) $s['carla']->id, (int) $s['persone']['bice']->id])
        ->and(RataQuote::registroRicostruito($s['piano']->fresh()))->toBe([$s['piano']->id.'|'.$s['persone']['mario']->id.'|'.$s['unita']['1']->id => 10000])
        ->and(b14rpCa($this, $s))->toBe($prima);
});

test('Coda 681 — le due rate emesse prima della successione sull\'Interno 2: nessuna bozza passa, e la nota di credito sull\'Interno 1 si rifiuta ancora', function () {
    // Le due rate hanno chiesto i € 100,00 dell'Interno 1 a Mario. La successione non passa bozze a Carla, che entra nella ricostruzione senza quote:
    // senza la correzione la ricostruzione era nulla, la parte personale delle rate emesse zero, e la nota toglieva dall'unità una spesa già chiesta.
    $s = b14rpCaso($this);
    $this->travelTo('2026-04-02 10:00:00');
    b59apEmetti($this, $s, $s['piano'], [2], '2026-04-02')->assertSessionHas('message.type', 'success');
    $prima = b14rpCa($this, $s);
    expect($prima['esito'])->toBe('quadra');
    $this->travelTo('2026-06-10 10:00:00');
    $rifiuto = b14rpNota($this, $s)->assertSessionHasErrors('fattura_rettificata_id');

    b14rpSuccessione($this, $s)->assertSessionHasNoErrors();

    expect(RataQuote::registroRicostruito($s['piano']->fresh()))->toBe([$s['piano']->id.'|'.$s['persone']['mario']->id.'|'.$s['unita']['1']->id => 10000])
        ->and(RataQuote::rateEmesseConAdPersonam([(int) $s['unita']['1']->id]))->toBe([['piano_id' => (int) $s['piano']->id, 'piano' => 'Balcone interno 1', 'rate' => [1, 2], 'unita' => [(int) $s['unita']['1']->id]]])
        ->and(b14rpCa($this, $s))->toBe($prima);
    $this->travelTo('2026-11-21 10:00:00');
    b14rpNota($this, $s)->assertSessionHasErrors('fattura_rettificata_id');
    expect(FatturaPassiva::where('numero_documento', 'NC-681')->exists())->toBeFalse();
});

// ─── Il piano che non si ricostruisce per un'altra ragione ──────────────────────────────────────────────────────────────────────

test('Coda 681 — un piano che non si ricostruisce per un\'altra ragione (una quota cambiata a mano): «CA» dice l\'unità non calcolabile, con il motivo', function () {
    // La quota di Bice della rata n. 2 portata a mano da € 125,00 a € 135,00: la spesa delle quote dell'Interno 2 non è più quella del riparto, e il
    // piano non si ricostruisce. La parte personale delle sue quote non si sa: «CA» non dice più «non torna» per l'Interno 1 (spese € 0,00 contro
    // anticipazioni aperte € 50,00), dice che lì non si calcola, e perché.
    $s = b14rpCaso($this);
    $q = b59apQuota($s['piano'], 2, $s['persone']['bice']);
    $regole = $q->regole_calcolo;
    $regole['importi']['quota_pura_gestione'] = 13500;
    $regole['importi']['totale_calcolato'] = 13500;
    DB::table('rate_quote')->where('id', $q->id)->update(['importo' => 13500, 'regole_calcolo' => json_encode($regole)]);
    expect(RataQuote::righeRicostruiteAdPersonam($s['piano']->fresh()))->toBeNull();

    expect(b14rpCa($this, $s))->toBe(['esito' => 'segnalazione', 'sinistra' => 0, 'destra' => 0,
        'nota' => 'Int. 1: non calcolabile. Il piano «Balcone interno 1» non ha il dettaglio del riparto e non si ricostruisce: la parte personale delle sue quote non si sa.']);
});

// ─── In comproprietà ────────────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Il caso in comproprietà: l'Interno 2 di Bice Bianchi e Dario Gialli al 50 % quando il piano nasce. Le quote dell'Interno 2: € 125,00 di spesa
 * comune a Bice e € 125,00 a Dario (€ 62,50 a rata). La ricostruzione con le titolarità di oggi divideva l'unità anche con chi è entrato dopo
 * il piano (la riga chiusa di Bice, Dario e Carla: € 83,34, € 83,33, € 83,33), e con due soggetti delle quote nessuna regola la riportava: era nulla per
 * tutto il piano. Con `$adPersonamInterno2` la fattura ha anche € 40,00 sull'Interno 2.
 */
function b14rpComproprieta($test, bool $dueEmesse = false, bool $adPersonamInterno2 = false): array
{
    $test->travelTo('2026-03-20 10:00:00');
    $s = b59apBase(['Mario Rossi' => 100], 'ordinaria');
    DB::table('anagrafica_immobile')->where('id', $s['righeTitolarita']['bice'])->update(['quota' => 50]);
    $s['dario'] = Anagrafica::forceCreate(['nome' => 'Dario Gialli', 'email' => 'b14rp-dario-cp@test.it', 'indirizzo' => 'Via Po 4', 'codice_fiscale' => 'B14RPDARIOCPXXXX']);
    $s['dario']->condomini()->syncWithoutDetaching([$s['c']->id]);
    $s['righeTitolarita']['dario'] = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $s['dario']->id, 'immobile_id' => $s['unita']['2']->id,
        'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);
    $righe = [b59apRigaComune(500.00), b59apRigaAdPersonam($s, 100.00, 0)];
    if ($adPersonamInterno2) {
        $righe[] = b59apRigaAdPersonam($s, 40.00, 0, '2', 'Riparazione balcone interno 2');
    }
    $s['fattura'] = b59apFattura($test, $s, $righe);
    $s['piano'] = b59apPiano($test, $s, $s['fattura'], 2, $adPersonamInterno2 ? '640,00' : '600,00');
    b59apEmetti($test, $s, $s['piano'], $dueEmesse ? [1, 2] : [1])->assertSessionHas('message.type', 'success');
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $s['carla'] = Anagrafica::forceCreate(['nome' => 'Carla Neri', 'email' => 'b14rp-carla-cp@test.it', 'indirizzo' => 'Via Po 2', 'codice_fiscale' => 'B14RPCARLACPXXXX']);
    $s['carla']->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $s;
}

/** Il passaggio della quota di Bice (50 %) sull'Interno 2 dal 01/05/2026, registrato il 20/11 dalla rotta: `$dati` dice il tipo e chi entra. */
function b14rpPassaggioDiBice($test, array $s, array $dati)
{
    $test->travelTo('2026-11-20 10:00:00');

    return $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['2']]), $dati + [
        'riga_uscente_id' => $s['righeTitolarita']['bice'], 'decorrenza' => '2026-05-01', 'quota' => 50, 'tipologia' => 'proprietario',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Atto letto, bozze a chi entra',
    ]);
}

/** Il conguaglio di Mario Rossi sull'Interno 1, letto il 31/12/2026: la cifra e il perché, se non è disponibile. */
function b14rpConguaglioDiMario($test, array $s): array
{
    $test->travelTo('2026-12-31 12:00:00');
    $riga = app(\App\Services\Gestionale\ConsuntivoPerPersona::class)->perEsercizio($s['e']->fresh())['persone'][$s['g']->id][$s['persone']['mario']->id][$s['unita']['1']->id];

    return [$riga['conguaglio'], $riga['non_disponibile']];
}

test('Coda 681 — in comproprietà, la vendita della quota di Bice a Carla: la ricostruzione resta, «CA» e il conguaglio di Mario non cambiano', function () {
    $s = b14rpComproprieta($this);
    $prima = b14rpCa($this, $s);
    $marioPrima = b14rpConguaglioDiMario($this, $s);
    expect($prima)->toMatchArray(['esito' => 'quadra', 'sinistra' => 5000, 'destra' => 5000])->and($marioPrima)->toBe([0, null]);
    $this->travelTo('2026-06-10 10:00:00');
    b14rpNota($this, $s)->assertSessionHasErrors('fattura_rettificata_id');

    b14rpPassaggioDiBice($this, $s, ['tipo' => 'vendita', 'anagrafica_entrante_id' => $s['carla']->id, 'copia_autentica' => true,
        'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'atto notaio Verdi, rep. 684'])->assertSessionHasNoErrors();

    $q2 = RataQuote::where('rata_id', b59apRata($s['piano'], 2)->id)->where('immobile_id', $s['unita']['2']->id)->orderBy('id')->get()
        ->map(fn ($q) => [(int) $q->anagrafica_id, $q->regole_calcolo['riassegnazione']['righe_di'] ?? null])->all();
    expect($q2)->toContain([(int) $s['carla']->id, (int) $s['persone']['bice']->id])
        ->and(RataQuote::registroRicostruito($s['piano']->fresh()))->toBe([$s['piano']->id.'|'.$s['persone']['mario']->id.'|'.$s['unita']['1']->id => 10000])
        ->and(b14rpCa($this, $s))->toBe($prima)
        ->and(b14rpConguaglioDiMario($this, $s))->toBe($marioPrima);
    $this->travelTo('2026-11-21 10:00:00');
    b14rpNota($this, $s)->assertSessionHasErrors('fattura_rettificata_id');
    expect(FatturaPassiva::where('numero_documento', 'NC-681')->exists())->toBeFalse();
});

test('Coda 681 — in comproprietà, la successione di Bice a Dario (già titolare) e a Carla: la ricostruzione resta, «CA» e il conguaglio di Mario non cambiano', function () {
    // Dario somma la sua parte da erede alla sua riga (la sua riga del 2019 si chiude al 30/04 e se ne apre una al 75 % dal 01/05): la riga nuova è
    // entrata con il passaggio e non conta, quella di prima sì.
    $s = b14rpComproprieta($this);
    $prima = b14rpCa($this, $s);
    $marioPrima = b14rpConguaglioDiMario($this, $s);

    b14rpPassaggioDiBice($this, $s, ['tipo' => 'successione', 'eredi' => [['anagrafica_id' => $s['dario']->id, 'quota' => 25], ['anagrafica_id' => $s['carla']->id, 'quota' => 25]],
        'arretrato' => Subentro::ARRETRATO_AL_DEFUNTO, 'conguaglio' => Subentro::SCRIVI_IL_CONGUAGLIO, 'erede_di_riferimento' => $s['dario']->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione n. 684'])->assertSessionHasNoErrors();

    expect(DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']['2']->id)->where('anagrafica_id', $s['dario']->id)->orderBy('id')->pluck('quota')->map(fn ($q) => (float) $q)->all())
        ->toBe([50.0, 75.0])
        ->and(RataQuote::registroRicostruito($s['piano']->fresh()))->toBe([$s['piano']->id.'|'.$s['persone']['mario']->id.'|'.$s['unita']['1']->id => 10000])
        ->and(b14rpCa($this, $s))->toBe($prima)
        ->and(b14rpConguaglioDiMario($this, $s))->toBe($marioPrima);
});

test('Coda 681 — in comproprietà, le due rate emesse e la successione di Bice a Carla: la ricostruzione resta e la nota di credito sull\'Interno 1 si rifiuta ancora', function () {
    $s = b14rpComproprieta($this, dueEmesse: true);
    $prima = b14rpCa($this, $s);
    $this->travelTo('2026-06-10 10:00:00');
    b14rpNota($this, $s)->assertSessionHasErrors('fattura_rettificata_id');

    b14rpPassaggioDiBice($this, $s, ['tipo' => 'successione', 'eredi' => [['anagrafica_id' => $s['carla']->id, 'quota' => 50]],
        'arretrato' => Subentro::ARRETRATO_AL_DEFUNTO, 'conguaglio' => Subentro::SCRIVI_IL_CONGUAGLIO, 'erede_di_riferimento' => $s['carla']->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione n. 685'])->assertSessionHasNoErrors();

    expect(RataQuote::registroRicostruito($s['piano']->fresh()))->toBe([$s['piano']->id.'|'.$s['persone']['mario']->id.'|'.$s['unita']['1']->id => 10000])
        ->and(b14rpCa($this, $s))->toBe($prima);
    $this->travelTo('2026-11-21 10:00:00');
    b14rpNota($this, $s)->assertSessionHasErrors('fattura_rettificata_id');
    expect(FatturaPassiva::where('numero_documento', 'NC-681')->exists())->toBeFalse();
});

test('Coda 681 — in comproprietà, la spesa personale dell\'Interno 2 resta a chi il piano l\'ha chiesta, Bice e Dario, anche dopo la vendita a Carla', function () {
    // La fattura ha anche € 40,00 sull'Interno 2: il piano li ha chiesti a Bice e a Dario, € 20,00 ciascuno. Dopo la vendita della quota di Bice la
    // ricostruzione li dice ancora loro, non di Carla.
    $s = b14rpComproprieta($this, adPersonamInterno2: true);
    $p = $s['piano']->id;
    $attesa = [$p.'|'.$s['persone']['mario']->id.'|'.$s['unita']['1']->id => 10000, $p.'|'.$s['persone']['bice']->id.'|'.$s['unita']['2']->id => 2000,
        $p.'|'.$s['dario']->id.'|'.$s['unita']['2']->id => 2000];
    $ricostruito = function () use ($s) {
        $r = RataQuote::registroRicostruito($s['piano']->fresh());
        if ($r !== null) {
            ksort($r);
        }

        return $r;
    };
    ksort($attesa);
    expect($ricostruito())->toBe($attesa);
    // Con due unità la nota di «CA» resta al plurale (con una: «Per l'unità con spese per singole unità», nella catena qui sopra).
    $prima = b14rpCa($this, $s);
    expect($prima['esito'])->toBe('quadra')->and($prima['nota'])->toStartWith('Per ognuna delle 2 unità con spese per singole unità: ');

    b14rpPassaggioDiBice($this, $s, ['tipo' => 'vendita', 'anagrafica_entrante_id' => $s['carla']->id, 'copia_autentica' => true,
        'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'atto notaio Verdi, rep. 686'])->assertSessionHasNoErrors();

    expect($ricostruito())->toBe($attesa)->and(b14rpCa($this, $s))->toBe($prima);
});
