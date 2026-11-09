<?php

/**
 * La Coda 557 (decisione 240): il deliberato con le rate di un piano approvato senza le righe del riparto (generato prima della 1.11.0-beta.29) si
 * ricostruisce quando si legge il riepilogo, con il motore in sola lettura (`DettaglioRiparto::perPiano`), e si usa solo se per ogni unità le righe
 * ricostruite tornano al centesimo con la spesa delle sue quote, per tutti i piani della gestione. Nessuna riga si scrive.
 *
 * L'oracolo: un piano generato oggi ha le righe, e il riepilogo le legge; cancellate le righe, il riepilogo deve dire le stesse cifre, «ricostruito».
 * Il caso è «Via Roma» (`RendicontoViaRomaScenario.php`): due unità da 500 millesimi, il piano «lascia vuoto» da € 4.110,00. Centesimi.
 *
 * Non copre: lo spostamento fra due voci ripartite con la stessa tabella che lasci uguale il totale di ogni unità (il controllo per unità non lo vede: è
 * il limite scritto nell'analisi, § 2); i piani delle quote della 1.7.x, senza la fotografia (nessuno nei dati di prova).
 */

use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\DeliberatoRicostruito;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use App\Services\Gestionale\SintesiDelRendiconto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';
require_once __DIR__.'/LetturaDebitiProva.php';
require_once __DIR__.'/../Pdf/Support/CartaDelloStudio.php';

const DRC_FRASE_NON_DISPONIBILE = 'Il deliberato con le rate non è disponibile per i piani generati prima che il programma ne conservasse il dettaglio: è nel verbale dell\'assemblea che li ha approvati.';

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

function drcLeggi(array $s): array
{
    return app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']);
}

/** La riga (foglia o capitolo) del riepilogo, per id della voce. */
function drcVoce(array $r, int $voceId): ?array
{
    foreach ($r['blocchi'] as $b) {
        foreach ([...$b['spese'], ...$b['entrate']] as $v) {
            if ((int) $v['voce_id'] === $voceId) {
                return $v;
            }
            foreach ($v['voci'] ?? [] as $f) {
                if ((int) $f['voce_id'] === $voceId && empty($f['proprio'])) {
                    return $f;
                }
            }
        }
    }

    return null;
}

function drcBlocco(array $r, int $gestioneId): array
{
    foreach ($r['blocchi'] as $b) {
        if ((int) $b['gestione']['id'] === $gestioneId) {
            return $b;
        }
    }

    throw new RuntimeException("Nessun blocco per la gestione {$gestioneId}");
}

function drcApprova($test, array $s, PianoRate $piano): void
{
    $test->actingAs($test->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-02'])
        ->assertSessionHasNoErrors();
}

/** Una quota del piano che chiede un euro in più di quanto il riparto le dà: la ricostruzione di quel piano non torna più. */
function drcQuotaStorta(PianoRate $piano): void
{
    $q = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->orderBy('rate_quote.id')
        ->select('rate_quote.id', 'rate_quote.importo', 'rate_quote.regole_calcolo')->first();
    $regole = json_decode((string) $q->regole_calcolo, true) ?: [];
    if (isset($regole['importi']['quota_pura_gestione'])) {
        $regole['importi']['quota_pura_gestione'] += 100;
    }
    DB::table('rate_quote')->where('id', $q->id)->update(['importo' => (int) $q->importo + 100, 'regole_calcolo' => json_encode($regole)]);
}

/**
 * Il piano com'erano i piani più vecchi, che la Coda 557 ricostruisce: senza le righe del riparto e senza le voci in `piano_rate_capitoli` (il motore legge le
 * radici del piano dei conti con gli importi di oggi). Oggi la generazione scrive ogni voce con il suo importo (sondato l'08/11/2026, tappa A): un piano
 * di oggi a cui si tolgono le righe ha tutte le voci fissate.
 */
function drcPianoVecchioSenzaVoci(PianoRate $piano): void
{
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->delete();
}

/** Il piano vecchio con il solo capitolo fissato: una riga per il capitolo con il suo importo, nessuna per le voci sotto (com'era il piano 141 di Via Roma). */
function drcPianoVecchioConCapitolo(PianoRate $piano, \App\Models\Gestionale\Conto $capitolo, int $importo, array $voci): void
{
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->whereIn('conto_id', array_map(fn ($v) => $v->id, $voci))->delete();
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $capitolo->id, 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()]);
}

/** Un capitolo dal modale (importo zero) e le sue voci sotto, su «Proprietà». */
function drcCapitolo($test, array $s, string $nome, array $figli): array
{
    $voce = fn (array $campi) => $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pc']]), array_merge([
        'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ], $campi))->assertSessionHasNoErrors();
    $voce(['nome' => $nome, 'importo' => '0,00', 'isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    $padre = \App\Models\Gestionale\Conto::where('piano_conto_id', $s['pc']->id)->where('nome', $nome)->firstOrFail();
    $esito = ['padre' => $padre];
    foreach ($figli as $figlio => $importo) {
        $voce(['nome' => $figlio, 'importo' => $importo, 'parent_id' => $padre->id, 'isSottoConto' => true]);
        $esito[$figlio] = \App\Models\Gestionale\Conto::where('piano_conto_id', $s['pc']->id)->where('nome', $figlio)->firstOrFail();
    }

    return $esito;
}

// ─── L'oracolo: le stesse cifre di quando le righe c'erano ─────────────────────────────────────────────────────────────────

it('cancellate le righe del riparto, il deliberato è «ricostruito» con le stesse cifre per voce, lo stesso scostamento e le stesse quote deliberate', function () {
    $s = vrmBase($this);
    $prima = drcLeggi($s);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $dopo = drcLeggi($s);

    foreach (['pulizie', 'manutenzioni', 'bancarie', 'antenna'] as $voce) {
        $p = drcVoce($prima, $s[$voce]->id);
        $d = drcVoce($dopo, $s[$voce]->id);
        expect([$p['deliberato_stato'], $d['deliberato_stato']])->toBe(['conservato', 'ricostruito'])
            ->and($d['deliberato'])->toBe($p['deliberato'])
            ->and($d['scostamento'] ?? null)->toBe($p['scostamento'] ?? null);
    }
    expect(drcVoce($dopo, $s['pulizie']->id)['deliberato'])->toBe(300000)
        ->and(drcVoce($dopo, $s['antenna']->id)['deliberato'])->toBe(24000)
        ->and($dopo['quote']['totale']['deliberate'])->toBe($prima['quote']['totale']['deliberate'])
        ->and($dopo['quote']['totale']['deliberate'])->toBe(411000)
        ->and(drcBlocco($dopo, $s['g']->id)['deliberato_stato'])->toBe('ricostruito')
        ->and(drcBlocco($prima, $s['g']->id)['deliberato_stato'])->toBe('conservato')
        // Nessuna riga scritta: il registro del riparto resta vuoto.
        ->and(DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->count())->toBe(0);
});

it('le frasi: con un piano ricostruito esce quella della ricostruzione e non quella del «non disponibile»; con le righe nessuna delle due', function () {
    $s = vrmBase($this);
    $con = drcLeggi($s)['avvertenze'];
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $senza = drcLeggi($s)['avvertenze'];

    expect([$con['deliberato'], $con['deliberato_ricostruito'], $con['deliberato_diviso']])->toBe([null, null, null])
        ->and($senza['deliberato'])->toBeNull()
        ->and($senza['deliberato_ricostruito'])->toBe(RiepilogoPerCompetenza::FRASE_DELIBERATO_RICOSTRUITO)
        ->and($senza['deliberato_ricostruito'])->toContain('torna al centesimo con le quote del piano di ogni unità');
});

// ─── Quando non torna ─────────────────────────────────────────────────────────────────────────────────────────────────────

it('cambiati i millesimi di un\'unità dopo l\'emissione, la ricostruzione non torna: «non disponibile», con la frase di oggi', function () {
    $s = vrmBase($this);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('quote_tabella')->where('tabella_id', $s['tabella']->id)->where('immobile_id', $s['unita']['A']->id)->update(['valore' => 600]);
    $r = drcLeggi($s);
    $v = drcVoce($r, $s['pulizie']->id);

    expect([$v['deliberato'], $v['deliberato_stato'], $v['scostamento']])->toBe([null, 'non_conservato', null])
        ->and($r['quote']['totale']['deliberate'])->toBeNull()
        ->and($r['avvertenze']['deliberato'])->toBe(DRC_FRASE_NON_DISPONIBILE)
        ->and($r['avvertenze']['deliberato_ricostruito'])->toBeNull()
        ->and(app(DeliberatoRicostruito::class)->perPiano($s['piano']))->toBeNull();
});

it('l\'importo di una voce cambiato dopo, in un piano vecchio che prende gli importi di oggi: la ricostruzione non torna', function () {
    $s = vrmBase($this);
    drcPianoVecchioSenzaVoci($s['piano']);
    $primaDelCambio = drcVoce(drcLeggi($s), $s['pulizie']->id);
    DB::table('conti')->where('id', $s['pulizie']->id)->update(['importo' => 330000]);

    expect([$primaDelCambio['deliberato'], $primaDelCambio['deliberato_stato']])->toBe([300000, 'ricostruito'])
        ->and(drcVoce(drcLeggi($s), $s['pulizie']->id)['deliberato_stato'])->toBe('non_conservato');
});

it('un piano approvato senza quote non ha un deliberato con le rate: resta «non disponibile»', function () {
    $s = vrmBase($this);
    PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Senza rate', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-02-05', 'metodo_distribuzione' => 'prima_rata']);

    expect(drcVoce(drcLeggi($s), $s['pulizie']->id)['deliberato_stato'])->toBe('non_conservato');
});

// ─── Una eccezione basta: la regola è per tutti i piani della gestione ─────────────────────────────────────────────────────

it('due piani nella gestione: se uno solo dei piani senza righe non torna, la gestione intera è «non disponibile»; con le righe dell\'altro, «ricostruito» e la somma', function () {
    $s = vrmBase($this);
    $secondo = be52PianoConVoci($this, $s, [$s['pulizie']->id], 'Pulizie straordinarie dell\'ordinaria');
    drcApprova($this, $s, $secondo);
    $chiestoDalSecondo = (int) DB::table('righe_riparto')->where('piano_rate_id', $secondo->id)->where('conto_id', $s['pulizie']->id)->sum('importo');

    // Il primo senza righe (torna), il secondo con le righe: ricostruito, e la somma.
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $v = drcVoce(drcLeggi($s), $s['pulizie']->id);
    expect($chiestoDalSecondo)->toBe(300000)
        ->and([$v['deliberato'], $v['deliberato_stato']])->toBe([600000, 'ricostruito']);

    // Anche il secondo senza righe, e storto: la gestione intera non è disponibile, anche per le voci che il secondo non chiede.
    DB::table('righe_riparto')->where('piano_rate_id', $secondo->id)->delete();
    drcQuotaStorta($secondo);
    $r = drcLeggi($s);
    expect(drcVoce($r, $s['pulizie']->id)['deliberato_stato'])->toBe('non_conservato')
        ->and(drcVoce($r, $s['manutenzioni']->id)['deliberato_stato'])->toBe('non_conservato')
        ->and(app(DeliberatoRicostruito::class)->perPiano($s['piano']->fresh()))->not->toBeNull()
        ->and(app(DeliberatoRicostruito::class)->perPiano($secondo->fresh()))->toBeNull();
});

// ─── D3: le voci che il piano non ha fissato ──────────────────────────────────────────────────────────────────────────────

it('un piano vecchio senza voci prende gli importi delle voci di oggi: ogni voce ricostruita porta l\'asterisco, con le cifre di prima, e la frase esce', function () {
    $s = vrmBase($this);
    $prima = drcLeggi($s);
    drcPianoVecchioSenzaVoci($s['piano']);
    $r = drcLeggi($s);

    expect(drcVoce($r, $s['pulizie']->id)['deliberato'])->toBe(drcVoce($prima, $s['pulizie']->id)['deliberato'])
        ->and(drcVoce($r, $s['pulizie']->id)['deliberato_diviso_oggi'])->toBeTrue()
        ->and(drcVoce($r, $s['antenna']->id)['deliberato_diviso_oggi'])->toBeTrue()
        ->and($r['avvertenze']['deliberato_diviso'])->toBe(RiepilogoPerCompetenza::FRASE_DELIBERATO_DIVISO);
});

it('in un piano vecchio le voci fissate non hanno l\'asterisco; le voci sotto un capitolo fissato sì, il capitolo no', function () {
    $s = vrmBase($this);
    $man = drcCapitolo($this, $s, 'Manutenzione', ['Ascensore' => '600,00', 'Caldaia' => '400,00']);
    DB::table('piani_rate')->where('id', $s['piano']->id)->delete();
    $piano = be52PianoConVoci($this, $s, [$s['pulizie']->id, $man['padre']->id], 'Pulizie e manutenzione');
    drcApprova($this, $s, $piano);
    $prima = drcLeggi($s);
    drcPianoVecchioConCapitolo($piano, $man['padre'], 100000, [$man['Ascensore'], $man['Caldaia']]);
    $r = drcLeggi($s);

    expect(drcVoce($r, $s['pulizie']->id)['deliberato_stato'])->toBe('ricostruito')
        ->and(drcVoce($r, $s['pulizie']->id)['deliberato_diviso_oggi'])->toBeFalse()
        ->and(drcVoce($r, $man['Ascensore']->id)['deliberato_diviso_oggi'])->toBeTrue()
        ->and(drcVoce($r, $man['Caldaia']->id)['deliberato_diviso_oggi'])->toBeTrue()
        ->and(drcVoce($r, $man['padre']->id)['deliberato_diviso_oggi'])->toBeFalse()
        // Le cifre restano quelle di quando le righe c'erano.
        ->and(drcVoce($r, $man['Ascensore']->id)['deliberato'])->toBe(drcVoce($prima, $man['Ascensore']->id)['deliberato'])
        ->and(drcVoce($r, $man['padre']->id)['deliberato'])->toBe(drcVoce($prima, $man['padre']->id)['deliberato'])
        ->and(drcVoce($r, $man['padre']->id)['deliberato'])->toBe(100000);
});

it('un piano di oggi a cui mancano le righe ha tutte le voci fissate: nessun asterisco, e la frase dell\'asterisco non esce', function () {
    $s = vrmBase($this);
    DB::table('piani_rate')->where('id', $s['piano']->id)->delete();
    $piano = be52PianoConVoci($this, $s, [$s['pulizie']->id, $s['manutenzioni']->id], 'Solo pulizie e manutenzioni');
    drcApprova($this, $s, $piano);
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
    $r = drcLeggi($s);

    expect(drcVoce($r, $s['pulizie']->id)['deliberato_stato'])->toBe('ricostruito')
        ->and($r['avvertenze']['deliberato_ricostruito'])->not->toBeNull()
        ->and($r['avvertenze']['deliberato_diviso'])->toBeNull();
});

// ─── Una gestione straordinaria con le fatture ───────────────────────────────────────────────────────────────────────────

it('un piano straordinario con le fatture: ricostruito con le cifre delle fatture, senza asterisco', function () {
    $s = vrmBase($this);
    $gs = app(\App\Services\CondominioService::class)->createDefaultGestione($s['c'], $s['e'], 'straordinaria');
    $pcs = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $gs->id, 'nome' => 'Piano dei conti straordinario']);
    $facciata = be52Voce($this, $s, $pcs, 'Facciata', 'spesa', '1.000,00');
    $fattura = \App\Models\Gestionale\FatturaPassiva::create(['condominio_id' => $s['c']->id, 'fornitore_id' => $s['fornitore']->id, 'esercizio_id' => $s['e']->id,
        'tipo_documento' => 'fattura', 'numero_documento' => 'FT-FACCIATA', 'data_documento' => '2026-03-10', 'data_scadenza' => '2026-04-10', 'is_pregresso' => false,
        'importo_imponibile' => 100000, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 100000, 'netto_a_pagare' => 100000, 'stato_pagamento' => 'aperta',
        'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura->id, 'conto_id' => $facciata->id, 'immobile_id' => null, 'descrizione' => 'Facciata',
        'aliquota_iva' => 0, 'importo_imponibile' => 100000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'straordinario', 'gestione_id' => $gs->id, 'nome' => 'Facciata 2026', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 2,
        'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-03-10', 'genera_subito' => 1,
        'fatture_config' => [['id' => $fattura->id, 'importo' => '1.000,00']],
        'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera di prova', 'data_delibera_assemblea' => '2026-02-01',
    ])->assertSessionHasNoErrors();
    $piano = PianoRate::where('nome', 'Facciata 2026')->where('condominio_id', $s['c']->id)->firstOrFail();
    if ((string) ($piano->stato instanceof \BackedEnum ? $piano->stato->value : $piano->stato) !== 'approvato') {
        drcApprova($this, $s, $piano);
    }
    $prima = drcVoce(drcLeggi($s), $facciata->id);
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
    $dopo = drcVoce(drcLeggi($s), $facciata->id);

    expect([$prima['deliberato'], $prima['deliberato_stato']])->toBe([100000, 'conservato'])
        ->and([$dopo['deliberato'], $dopo['deliberato_stato'], $dopo['deliberato_diviso_oggi']])->toBe([100000, 'ricostruito', false])
        ->and(drcBlocco(drcLeggi($s), $gs->id)['deliberato_stato'])->toBe('ricostruito');
});

// ─── La sintesi e le stampe ─────────────────────────────────────────────────────────────────────────────────────────────

it('la sintesi prende il deliberato ricostruito e porta la frase della ricostruzione', function () {
    $s = vrmBase($this);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $sintesi = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e']);

    expect($sintesi['riquadri']['deliberato']['deliberato'])->toBe(411000)
        ->and($sintesi['prospetto']['deliberato'])->toBe(411000)
        ->and($sintesi['prospetto']['avvertenza_deliberato'])->toBeNull()
        ->and($sintesi['prospetto']['avvertenza_deliberato_ricostruito'])->toBe(RiepilogoPerCompetenza::FRASE_DELIBERATO_RICOSTRUITO);
});

it('le stampe del fascicolo dicono «ricostruito» e l\'asterisco con le parole per i condòmini, non quelle della pagina', function () {
    $s = vrmBase($this);
    drcPianoVecchioSenzaVoci($s['piano']);
    $t = vrmTestiDelFascicolo($s, $s['e'], [], false);
    $riepilogo = $t['riepilogo']['testo'];
    $frontespizio = $t['frontespizio']['testo'];

    expect($riepilogo)->toContain('Deliberato con le rate (ricostruito)')
        ->and($riepilogo)->toContain('€ 3.000,00 *')
        ->and($riepilogo)->toContain(\App\Services\Gestionale\ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_RICOSTRUITO))
        ->and($riepilogo)->toContain(\App\Services\Gestionale\ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_DIVISO))
        ->and($riepilogo)->not->toContain('torna «non disponibile»')
        ->and($riepilogo)->not->toContain(RiepilogoPerCompetenza::FRASE_DELIBERATO_RICOSTRUITO)
        ->and($frontespizio)->toContain(\App\Services\Gestionale\ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_RICOSTRUITO))
        ->and(\App\Services\Gestionale\ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_RICOSTRUITO))->not->toBe(RiepilogoPerCompetenza::FRASE_DELIBERATO_RICOSTRUITO);
});

it('nell\'HTML della stampa la frase del deliberato è subito dopo la riga del «Totale spese», prima delle entrate e delle quote della gestione', function () {
    $s = vrmBase($this);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $ricostruito = \App\Services\Gestionale\ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_RICOSTRUITO);
    $html = vrmTestiDelFascicolo($s, $s['e'], [], false)['riepilogo']['html'];
    $testo = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $totale = mb_strpos($testo, 'Totale spese');

    expect($totale)->not->toBeFalse()
        ->and(mb_strpos($testo, $ricostruito))->toBeGreaterThan($totale)
        ->and(mb_strpos($testo, $ricostruito))->toBeLessThan(mb_strpos($testo, 'Entrate da terzi'))
        ->and(mb_strpos($testo, $ricostruito))->toBeLessThan(mb_strpos($testo, 'Quote dei condòmini'));
});

/** Il paragrafo della stampa che contiene la frase: [i suoi attributi, il suo testo], o null. */
function drcParagrafo(string $html, string $frase): ?array
{
    preg_match_all('#<p\b([^>]*)>(.*?)</p>#s', $html, $m, PREG_SET_ORDER);
    foreach ($m as [, $attributi, $dentro]) {
        $testo = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($dentro), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (str_contains($testo, $frase)) {
            return [$attributi, $testo];
        }
    }

    return null;
}

/** Una gestione straordinaria con un piano approvato e le sue righe del riparto (deliberato «conservato»): la facciata, una fattura da € 1.000,00. */
function drcConStraordinaria($test, array $s): \App\Models\Gestione
{
    $gs = app(\App\Services\CondominioService::class)->createDefaultGestione($s['c'], $s['e'], 'straordinaria');
    $pcs = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $gs->id, 'nome' => 'Piano dei conti straordinario']);
    $facciata = be52Voce($test, $s, $pcs, 'Facciata', 'spesa', '1.000,00');
    $fattura = \App\Models\Gestionale\FatturaPassiva::create(['condominio_id' => $s['c']->id, 'fornitore_id' => $s['fornitore']->id, 'esercizio_id' => $s['e']->id,
        'tipo_documento' => 'fattura', 'numero_documento' => 'FT-FACCIATA', 'data_documento' => '2026-03-10', 'data_scadenza' => '2026-04-10', 'is_pregresso' => false,
        'importo_imponibile' => 100000, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 100000, 'netto_a_pagare' => 100000, 'stato_pagamento' => 'aperta',
        'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura->id, 'conto_id' => $facciata->id, 'immobile_id' => null, 'descrizione' => 'Facciata',
        'aliquota_iva' => 0, 'importo_imponibile' => 100000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'straordinario', 'gestione_id' => $gs->id, 'nome' => 'Facciata 2026', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 2,
        'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-03-10', 'genera_subito' => 1,
        'fatture_config' => [['id' => $fattura->id, 'importo' => '1.000,00']],
        'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera di prova', 'data_delibera_assemblea' => '2026-02-01',
    ])->assertSessionHasNoErrors();
    $piano = PianoRate::where('nome', 'Facciata 2026')->where('condominio_id', $s['c']->id)->firstOrFail();
    if ((string) ($piano->stato instanceof \BackedEnum ? $piano->stato->value : $piano->stato) !== 'approvato') {
        drcApprova($test, $s, $piano);
    }

    return $gs;
}

it('nella stampa del riepilogo le due frasi del deliberato ricostruito stanno sotto la tabella della sua gestione, sulla stessa pagina, prima della gestione dopo, al corpo del testo; nel frontespizio la frase della ricostruzione è sotto il prospetto, allo stesso corpo', function () {
    $s = vrmBase($this);
    $gs = drcConStraordinaria($this, $s);
    drcPianoVecchioSenzaVoci($s['piano']);
    $ricostruito = \App\Services\Gestionale\ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_RICOSTRUITO);
    $diviso = \App\Services\Gestionale\ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_DIVISO);
    $r = drcLeggi($s);
    expect([drcBlocco($r, $s['g']->id)['deliberato_stato'], drcBlocco($r, $gs->id)['deliberato_stato']])->toBe(['ricostruito', 'conservato'])
        ->and(array_map(fn ($b) => (int) $b['gestione']['id'], $r['blocchi']))->toBe([(int) $s['g']->id, (int) $gs->id]);

    // La pagina con la fine della tabella dell'ordinaria (il primo «Totale spese») ha le due frasi.
    $pdf = (string) $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.riepilogo.stampa', [$s['c'], $s['e']]))->assertOk()->getContent();
    $pagina = null;
    foreach (range(1, cdsNumeroPagine($pdf)) as $p) {
        $pagina ??= str_contains(cdsTesto($pdf, $p), 'Totale spese') ? $p : null;
    }
    $testoDellaPagina = cdsTesto($pdf, (int) $pagina);
    expect($pagina)->not->toBeNull()
        ->and($testoDellaPagina)->toContain($ricostruito)->toContain($diviso)
        ->and(mb_strpos($testoDellaPagina, $ricostruito))->toBeGreaterThan(mb_strpos($testoDellaPagina, 'Totale spese'));

    $t = vrmTestiDelFascicolo($s, $s['e'], [], false);
    $testo = $t['riepilogo']['testo'];
    // Dopo il totale delle spese dell'ordinaria, prima della gestione straordinaria; una volta sola.
    $straordinaria = mb_strpos($testo, 'Gestione straordinaria');
    expect(mb_strpos($testo, 'Totale spese'))->toBeLessThan(mb_strpos($testo, $ricostruito))
        ->and(mb_strpos($testo, $ricostruito))->toBeLessThan($straordinaria)
        ->and(mb_strpos($testo, $diviso))->toBeLessThan($straordinaria)
        ->and(mb_substr_count($testo, $ricostruito))->toBe(1);
    // Il corpo: 8 punti, come il testo della stampa (non i 6,5 delle note piccole).
    foreach ([$ricostruito, $diviso] as $frase) {
        expect(drcParagrafo($t['riepilogo']['html'], $frase)[0] ?? '')->toContain('font-size: 8pt');
    }
    expect(drcParagrafo($t['frontespizio']['html'], $ricostruito)[0] ?? '')->toContain('font-size: 8pt');
});

// ─── Dopo «Sposta spesa» ────────────────────────────────────────────────────────────────────────────────────────────────────

/** «Sposta spesa» dalla rotta della pagina del piano: `$euro` dalla voce `$da` alla voce `$a`. */
function drcSposta($test, array $s, string $da, string $a, int $euro): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.move-budget', [$s['c'], $s['piano']]), [
        'source_id' => $s[$da]->id, 'destination_id' => $s[$a]->id, 'amount' => $euro, 'reason' => 'Sforo delle manutenzioni',
    ])->assertSessionHasNoErrors();
}

it('un piano senza le righe del riparto con uno spostamento di «Sposta spesa» che non si compensa non si ricostruisce: la gestione è «non disponibile», nella pagina e nella Sintesi, con la frase che dice perché; con le righe il deliberato resta quello della generazione', function () {
    $s = vrmBase($this);
    drcSposta($this, $s, 'pulizie', 'manutenzioni', 100);
    $con = drcLeggi($s);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $senza = drcLeggi($s);

    // Con le righe: le cifre della generazione, € 3.000,00 e € 1.200,00; le quote deliberate € 4.110,00.
    expect([drcVoce($con, $s['pulizie']->id)['deliberato'], drcVoce($con, $s['manutenzioni']->id)['deliberato'], $con['quote']['totale']['deliberate']])
        ->toBe([300000, 120000, 411000])
        ->and(drcBlocco($con, $s['g']->id)['deliberato_stato'])->toBe('conservato');
    // Senza le righe: lo spostamento lascia Pulizie a −€ 100,00 e Manutenzioni a +€ 100,00, e il deliberato non si rifà.
    foreach (['pulizie', 'manutenzioni', 'bancarie', 'antenna'] as $voce) {
        expect(drcVoce($senza, $s[$voce]->id)['deliberato_stato'])->toBe('non_conservato', $voce)
            ->and(drcVoce($senza, $s[$voce]->id)['deliberato'])->toBeNull();
    }
    expect(drcBlocco($senza, $s['g']->id)['deliberato_stato'])->toBe('non_conservato')
        ->and($senza['quote']['totale']['deliberate'])->toBeNull()
        ->and($senza['avvertenze']['deliberato'])->toBe(RiepilogoPerCompetenza::FRASE_DELIBERATO_NON_DISPONIBILE.' '.RiepilogoPerCompetenza::FRASE_DELIBERATO_SPOSTATO)
        ->and($senza['avvertenze']['deliberato_ricostruito'])->toBeNull()
        ->and($senza['avvertenze']['deliberato_piani'])->toBe(["Il piano «{$s['piano']->nome}» ha una spesa spostata da una voce a un'altra con «Sposta spesa»."]);
    $sintesi = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e']);
    expect($sintesi['riquadri']['deliberato']['deliberato'])->toBeNull()
        ->and($sintesi['prospetto']['deliberato'])->toBeNull()
        ->and($sintesi['prospetto']['avvertenza_deliberato'])->toBe(RiepilogoPerCompetenza::FRASE_DELIBERATO_NON_DISPONIBILE.' '.RiepilogoPerCompetenza::FRASE_DELIBERATO_SPOSTATO);
});

it('dopo «Sposta spesa» la stampa del riepilogo e il frontespizio dicono «non disponibile» e perché, con le parole per i condòmini, sotto la tabella della gestione', function () {
    $s = vrmBase($this);
    drcSposta($this, $s, 'pulizie', 'manutenzioni', 100);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $t = vrmTestiDelFascicolo($s, $s['e'], [], false);
    $variante = \App\Services\Gestionale\ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_SPOSTATO);
    $riepilogo = $t['riepilogo']['testo'];

    expect($variante)->not->toBe(RiepilogoPerCompetenza::FRASE_DELIBERATO_SPOSTATO)->not->toContain('Sposta spesa')
        ->and($riepilogo)->toContain($variante)->not->toContain('Sposta spesa')->not->toContain('(ricostruito)')
        ->and(mb_strpos($riepilogo, 'Totale spese'))->toBeLessThan(mb_strpos($riepilogo, $variante))
        ->and(mb_strpos($riepilogo, $variante))->toBeLessThan(mb_strpos($riepilogo, 'Fuori dai capitoli'))
        ->and($t['frontespizio']['testo'])->toContain($variante)->not->toContain('Sposta spesa');
});

it('uno «Sposta spesa» fatto prima di rifare le rate con «Ricalcola» è già nelle quote, ma il piano senza righe resta «non disponibile» (niente confronto di date): le frasi non dicono «dopo l\'approvazione», e dicono che le voci di oggi possono non dire quanto chiedevano le rate', function () {
    vrmGiorno($this, '2026-01-02');
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 500.0);
    be52Unita($s, 'C', 'bice', $s['tabella'], 500.0);
    $s['pc'] = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'nome' => 'Piano dei conti']);
    $s['pulizie'] = be52Voce($this, $s, $s['pc'], 'Pulizie', 'spesa', '3.000,00');
    $s['manutenzioni'] = be52Voce($this, $s, $s['pc'], 'Manutenzioni', 'spesa', '1.200,00');
    $s['piano'] = be52PianoDallaRotta($this, $s);
    // «Sposta spesa» di € 100,00 da Pulizie a Manutenzioni, poi «Ricalcola»: le quote si rifanno con gli importi spostati; poi l'approvazione.
    drcSposta($this, $s, 'pulizie', 'manutenzioni', 100);
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']]), [])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-02'])
        ->assertSessionHasNoErrors();
    $con = drcLeggi($s);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $senza = drcLeggi($s);
    $sintesi = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e']);
    $stampa = \App\Services\Gestionale\ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_SPOSTATO);
    $frasi = implode(' ', [$senza['avvertenze']['deliberato'], ...$senza['avvertenze']['deliberato_piani'], $sintesi['prospetto']['avvertenza_deliberato'], $stampa]);

    // Le quote sommano € 4.200,00: Pulizie 3.000 − 100 = 2.900, Manutenzioni 1.200 + 100 = 1.300; con le righe il deliberato è quello.
    expect((int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->sum('rate_quote.importo'))->toBe(420000)
        ->and([drcVoce($con, $s['pulizie']->id)['deliberato'], drcVoce($con, $s['manutenzioni']->id)['deliberato']])->toBe([290000, 130000])
        ->and(drcBlocco($con, $s['g']->id)['deliberato_stato'])->toBe('conservato')
        // Senza le righe la regola resta (decisione 255, D2): «non disponibile».
        ->and(drcBlocco($senza, $s['g']->id)['deliberato_stato'])->toBe('non_conservato')
        ->and([drcVoce($senza, $s['pulizie']->id)['deliberato'], drcVoce($senza, $s['manutenzioni']->id)['deliberato']])->toBe([null, null])
        ->and($senza['avvertenze']['deliberato'])->toBe(RiepilogoPerCompetenza::FRASE_DELIBERATO_NON_DISPONIBILE.' Un piano cambiato con «Sposta spesa» non si rifà: le sue voci di oggi possono non dire quanto chiedevano le rate.')
        ->and($senza['avvertenze']['deliberato_piani'])->toBe(['Il piano «Preventivo 2026» ha una spesa spostata da una voce a un\'altra con «Sposta spesa».'])
        ->and($sintesi['prospetto']['avvertenza_deliberato'])->toBe($senza['avvertenze']['deliberato'])
        ->and($stampa)->toBe('Un piano delle rate cambiato spostando una spesa da una voce a un\'altra non si ricalcola: le sue voci di oggi possono non dire quanto chiedevano le rate.')
        ->and($frasi)->not->toContain('dopo l\'approvazione')->not->toContain('non dicono più');
});

it('uno spostamento di «Sposta spesa» stornato si compensa: il piano senza righe si ricostruisce con le cifre della generazione', function () {
    $s = vrmBase($this);
    drcSposta($this, $s, 'pulizie', 'manutenzioni', 100);
    $movimento = \App\Models\Gestionale\BudgetMovement::where('piano_rate_id', $s['piano']->id)->sole();
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [$s['c'], $s['piano'], $movimento]))->assertSessionHasNoErrors();
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $r = drcLeggi($s);

    expect(DB::table('budget_movements')->where('piano_rate_id', $s['piano']->id)->count())->toBe(2)
        ->and(drcBlocco($r, $s['g']->id)['deliberato_stato'])->toBe('ricostruito')
        ->and([drcVoce($r, $s['pulizie']->id)['deliberato'], drcVoce($r, $s['manutenzioni']->id)['deliberato'], $r['quote']['totale']['deliberate']])
        ->toBe([300000, 120000, 411000])
        ->and($r['avvertenze']['deliberato'])->toBeNull();
});
