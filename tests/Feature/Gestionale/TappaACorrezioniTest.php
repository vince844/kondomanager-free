<?php

/**
 * Le correzioni della tappa A della 1.12.0-beta.6 dopo il suo scettico (`docs/riferimenti/beta6_1_12_lavori/tappaA/scettico.md`, SA1–SA11), e i casi che le
 * mutazioni sopravvissute hanno mostrato scoperti (SA9). Le sonde dello scettico (P1, P2, P3, P5, P6, P7, P8, P10) diventano test.
 *
 * - **Coda 557**: l'asterisco del capitolo non fissato (SA4) e della forma mista (SA5); la riga della tabella con l'importo nullo (M24); le frasi con il nome
 *   delle gestioni quando una è «non disponibile» e una «ricostruita» (SA2); la frase della stampa con «o nessuna voce» (SA1); tutto o niente con il piano che
 *   non torna per primo (P10); il pregresso nelle rate e una vendita dopo la generazione (P5); un'unità che oggi non ha più un titolare.
 * - **Coda 553**: lo storno nell'anno dopo (SA3, e PRE1 per l'ordinaria) con la partita doppia; la gestione spenta (SA10); la proposta del server (M19);
 *   «Assegna la voce» con la voce dell'ordinaria (P8, M21); il perché del rifiuto di due gestioni (SA11).
 *
 * Non copre: le misure delle pagine (la carta non cambia qui); i log del motore in sola lettura (SA8, una Coda). Centesimi.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Services\Gestionale\CommissioniSenzaVoce;
use App\Services\Gestionale\DeliberatoRicostruito;
use App\Services\Gestionale\ParoleDellaStampa;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use App\Services\Gestionale\SintesiDelRendiconto;
use App\Services\Gestionale\SpesaPerVoceService;
use App\Services\Gestionale\VoceDellaCommissione;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';
require_once __DIR__.'/LetturaDebitiProva.php';

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

function tacLeggi(array $s, ?Esercizio $e = null, ?string $oggi = null): array
{
    return app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $e ?? $s['e'], $oggi);
}

function tacVoce(array $r, int $voceId): ?array
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

function tacApprova($test, array $s, PianoRate $piano): void
{
    $test->actingAs($test->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-02'])
        ->assertSessionHasNoErrors();
}

/** Una quota del piano che chiede un euro in più di quanto il riparto le dà: la ricostruzione di quel piano non torna più. */
function tacQuotaStorta(PianoRate $piano): void
{
    $q = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->orderBy('rate_quote.id')
        ->select('rate_quote.id', 'rate_quote.importo', 'rate_quote.regole_calcolo')->first();
    $regole = json_decode((string) $q->regole_calcolo, true) ?: [];
    if (isset($regole['importi']['quota_pura_gestione'])) {
        $regole['importi']['quota_pura_gestione'] += 100;
    }
    DB::table('rate_quote')->where('id', $q->id)->update(['importo' => (int) $q->importo + 100, 'regole_calcolo' => json_encode($regole)]);
}

/** Pulizie € 3.000,00 e il capitolo «Manutenzione» (Ascensore € 600,00, Caldaia € 400,00), due unità 500/500, piano «lascia vuoto» approvato. */
function tacConCapitolo($test): array
{
    vrmGiorno($test, '2026-01-02');
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 500.0);
    be52Unita($s, 'C', 'bice', $s['tabella'], 500.0);
    $s['pc'] = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'nome' => 'Piano dei conti']);
    $s['pulizie'] = be52Voce($test, $s, $s['pc'], 'Pulizie', 'spesa', '3.000,00');
    $voce = fn (array $campi) => $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pc']]), array_merge([
        'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ], $campi))->assertSessionHasNoErrors();
    $voce(['nome' => 'Manutenzione', 'importo' => '0,00', 'isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    $s['capitolo'] = Conto::where('piano_conto_id', $s['pc']->id)->where('nome', 'Manutenzione')->firstOrFail();
    foreach (['Ascensore' => '600,00', 'Caldaia' => '400,00'] as $nome => $importo) {
        $voce(['nome' => $nome, 'importo' => $importo, 'parent_id' => $s['capitolo']->id, 'isSottoConto' => true]);
        $s[strtolower($nome)] = Conto::where('piano_conto_id', $s['pc']->id)->where('nome', $nome)->firstOrFail();
    }
    $s['piano'] = be52PianoDallaRotta($test, $s);
    tacApprova($test, $s, $s['piano']);

    return $s;
}

// ─── Coda 557: l'asterisco ──────────────────────────────────────────────────────────────────────────────────────────────

it('SA4: in un piano vecchio senza voci nella tabella anche il capitolo ha l\'asterisco, e uno spostamento fra due voci gli cambia la cifra', function () {
    $s = tacConCapitolo($this);
    $prima = tacLeggi($s);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->delete();
    // € 100,00 da Pulizie ad Ascensore, sulla stessa tabella: le quote di ogni unità non cambiano, e la ricostruzione torna.
    DB::table('conti')->where('id', $s['pulizie']->id)->update(['importo' => 290000]);
    DB::table('conti')->where('id', $s['ascensore']->id)->update(['importo' => 70000]);
    $dopo = tacLeggi($s);

    expect(tacVoce($prima, $s['capitolo']->id)['deliberato'])->toBe(100000)
        ->and([tacVoce($dopo, $s['capitolo']->id)['deliberato'], tacVoce($dopo, $s['capitolo']->id)['deliberato_stato']])->toBe([110000, 'ricostruito'])
        ->and(tacVoce($dopo, $s['capitolo']->id)['deliberato_diviso_oggi'])->toBeTrue()
        ->and(tacVoce($dopo, $s['ascensore']->id)['deliberato_diviso_oggi'])->toBeTrue();
});

it('SA5: nella forma mista (il capitolo e le sue voci nella tabella) le voci hanno l\'asterisco, perché il motore divide il capitolo con gli importi di oggi', function () {
    $s = tacConCapitolo($this);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $s['piano']->id, 'conto_id' => $s['capitolo']->id, 'importo' => 100000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conti')->where('id', $s['ascensore']->id)->update(['importo' => 70000]);
    DB::table('conti')->where('id', $s['caldaia']->id)->update(['importo' => 30000]);
    $r = tacLeggi($s);

    expect([tacVoce($r, $s['ascensore']->id)['deliberato'], tacVoce($r, $s['ascensore']->id)['deliberato_diviso_oggi']])->toBe([70000, true])
        ->and(tacVoce($r, $s['caldaia']->id)['deliberato_diviso_oggi'])->toBeTrue()
        // Il capitolo ha un importo suo: il suo totale è quello del piano, senza asterisco.
        ->and([tacVoce($r, $s['capitolo']->id)['deliberato'], tacVoce($r, $s['capitolo']->id)['deliberato_diviso_oggi']])->toBe([100000, false])
        ->and(tacVoce($r, $s['pulizie']->id)['deliberato_diviso_oggi'])->toBeFalse();
});

it('M24: una riga della tabella con l\'importo nullo non fissa la voce (il motore prende l\'importo di oggi): asterisco', function () {
    $s = vrmBase($this);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['pulizie']->id)->update(['importo' => null]);
    $r = tacLeggi($s);

    expect(tacVoce($r, $s['pulizie']->id)['deliberato_stato'])->toBe('ricostruito')
        ->and(tacVoce($r, $s['pulizie']->id)['deliberato_diviso_oggi'])->toBeTrue()
        ->and(tacVoce($r, $s['manutenzioni']->id)['deliberato_diviso_oggi'])->toBeFalse();
});

it('SA1: la frase dell\'asterisco nella stampa dice anche «o nessuna voce», il caso dei piani senza voci nella tabella', function () {
    $s = vrmBase($this);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->delete();
    $t = vrmTestiDelFascicolo($s, $s['e'], [], false);

    expect(ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_DIVISO))->toContain('ha fissato il capitolo, o nessuna voce, e non quanto chiedeva ogni voce')
        ->and($t['riepilogo']['testo'])->toContain('o nessuna voce');
});

// ─── Coda 557: le frasi con due gestioni in stati diversi (SA2), la frase della pagina (SA6) ─────────────────────────────────

it('SA2: una gestione «non disponibile» e una «ricostruita»: le due frasi dicono di quale gestione parlano, nel riepilogo, nella Sintesi e nel frontespizio', function () {
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
        tacApprova($this, $s, $piano);
    }
    DB::table('righe_riparto')->whereIn('piano_rate_id', [$piano->id, $s['piano']->id])->delete();
    tacQuotaStorta($s['piano']);
    $r = tacLeggi($s);
    $si = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e']);
    $t = vrmTestiDelFascicolo($s, $s['e'], [], false);
    // I nomi predefiniti delle gestioni cominciano con «Gestione»: la frase non lo ripete.
    expect([$s['g']->nome, $gs->nome])->toBe(['Gestione ordinaria', 'Gestione straordinaria']);
    $ordinaria = ' Vale per la «Gestione ordinaria».';
    $straordinaria = ' Vale per la «Gestione straordinaria».';

    expect($r['avvertenze']['deliberato'])->toBe(RiepilogoPerCompetenza::FRASE_DELIBERATO_NON_DISPONIBILE.$ordinaria)
        ->and($r['avvertenze']['deliberato_ricostruito'])->toBe(RiepilogoPerCompetenza::FRASE_DELIBERATO_RICOSTRUITO.$straordinaria)
        ->and($r['quote']['totale']['deliberate'])->toBeNull()
        ->and($si['prospetto']['avvertenza_deliberato'])->toBe($r['avvertenze']['deliberato'])
        ->and($si['prospetto']['avvertenza_deliberato_ricostruito'])->toBe($r['avvertenze']['deliberato_ricostruito'])
        ->and($t['frontespizio']['testo'])->toContain(ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_NON_DISPONIBILE).$ordinaria)
        ->and($t['frontespizio']['testo'])->toContain(ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_RICOSTRUITO).$straordinaria)
        ->and($t['frontespizio']['testo'].$r['avvertenze']['deliberato'])->not->toContain('Gestione «Gestione');

    // Una gestione con un nome che non comincia con «Gestione»: la parola si scrive.
    $s['g']->update(['nome' => 'Ordinaria 2026']);
    expect(tacLeggi($s)['avvertenze']['deliberato'])->toBe(RiepilogoPerCompetenza::FRASE_DELIBERATO_NON_DISPONIBILE.' Vale per la gestione «Ordinaria 2026».');
});

it('il «non disponibile» dice nella pagina quale piano, rifatto con i dati di oggi, non torna con le quote emesse: nel riepilogo e nella Sintesi, non nella stampa', function () {
    $s = vrmBase($this);
    $secondo = be52PianoConVoci($this, $s, [$s['pulizie']->id], 'Pulizie straordinarie dell\'ordinaria');
    tacApprova($this, $s, $secondo);
    DB::table('righe_riparto')->whereIn('piano_rate_id', [$s['piano']->id, $secondo->id])->delete();
    // Tutti e due tornano: il deliberato è ricostruito, e non c'è un piano da nominare.
    expect(tacLeggi($s)['avvertenze']['deliberato_piani'])->toBe([]);
    tacQuotaStorta($s['piano']);
    $r = tacLeggi($s);
    $si = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e']);
    $t = vrmTestiDelFascicolo($s, $s['e'], [], false);
    $frase = 'Il piano «'.$s['piano']->nome.'», rifatto con i dati di oggi, non torna con le quote emesse.';

    expect($r['avvertenze']['deliberato'])->toBe(RiepilogoPerCompetenza::FRASE_DELIBERATO_NON_DISPONIBILE)
        ->and($r['avvertenze']['deliberato_piani'])->toBe([$frase])
        ->and($si['prospetto']['avvertenza_deliberato_piani'])->toBe([$frase])
        ->and($t['frontespizio']['testo'])->toContain(ParoleDellaStampa::frase(RiepilogoPerCompetenza::FRASE_DELIBERATO_NON_DISPONIBILE))->not->toContain('non torna con le quote emesse')
        ->and($t['riepilogo']['testo'])->not->toContain('non torna con le quote emesse');
});

it('la Sintesi dell\'anno dopo, con il solo storno di una commissione che non si assegna (scritta senza voce, come prima): la fascia lo dice e non invita ad assegnare la voce, con la regola dell\'elenco', function () {
    $x = tacStornoNelDuemilaventisette($this, 'ordinaria', commissioneSenzaVoce: true);
    $elenco = app(CommissioniSenzaVoce::class)->perEsercizio($x['s']['c'], $x['e27']);
    $fascia = collect(app(SintesiDelRendiconto::class)->perEsercizio($x['s']['c'], $x['e27'], '2027-02-10')['fasce'])->firstWhere('tipo', 'commissioni');

    expect($elenco['assegnabili']['numero'])->toBe(0)
        ->and($fascia['importo'])->toBe(-250)
        ->and($fascia['da_assegnare'])->toBe(0)
        ->and($fascia['frase'])->toBe('€ -2,50 di commissioni senza voce, che qui non si assegnano: non stanno in nessun capitolo, e il conguaglio non le divide. L\'elenco dice perché.');
});

it('SA6: la frase della ricostruzione dice «le quote del piano di ogni unità» e «può tornare», con la virgola', function () {
    expect(RiepilogoPerCompetenza::FRASE_DELIBERATO_RICOSTRUITO)
        ->toStartWith('Per i piani generati prima che il programma ne conservasse il dettaglio, il deliberato con le rate è ricostruito:')
        ->toContain('si usa solo se torna al centesimo con le quote del piano di ogni unità')
        ->toContain('i millesimi, gli importi delle voci, i titolari o il già versato, può tornare «non disponibile»')
        ->not->toContain('quote emesse');
});

// ─── Coda 557: tutto o niente, le direzioni del controllo, il pregresso (SA9) ─────────────────────────────────────────────

it('P10 (M7): due piani senza righe, il primo non torna e il secondo sì: la gestione intera è «non disponibile»', function () {
    $s = vrmBase($this);
    $secondo = be52PianoConVoci($this, $s, [$s['pulizie']->id], 'Pulizie straordinarie dell\'ordinaria');
    tacApprova($this, $s, $secondo);
    DB::table('righe_riparto')->whereIn('piano_rate_id', [$s['piano']->id, $secondo->id])->delete();
    tacQuotaStorta($s['piano']);
    $r = tacLeggi($s);

    expect(app(DeliberatoRicostruito::class)->perPiano($s['piano']->fresh()))->toBeNull()
        ->and(app(DeliberatoRicostruito::class)->perPiano($secondo->fresh()))->not->toBeNull()
        ->and(tacVoce($r, $s['pulizie']->id)['deliberato_stato'])->toBe('non_conservato')
        ->and(tacVoce($r, $s['manutenzioni']->id)['deliberato'])->toBeNull();
});

it('M2: un\'unità che oggi non ha più un titolare: le sue quote non hanno righe con un soggetto, e la ricostruzione non torna', function () {
    $s = vrmBase($this);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']['A']->id)->delete();

    expect(app(DeliberatoRicostruito::class)->perPiano($s['piano']->fresh()))->toBeNull()
        ->and(tacVoce(tacLeggi($s), $s['pulizie']->id)['deliberato_stato'])->toBe('non_conservato');
});

/** Un condominio con un'unità (o due) da 1.000 millesimi, una voce da € 1.200,00 e un piano generato dall'azione, con il saldo dell'anno prima nelle rate. */
function tacPianoConSaldo(string $metodo, int $saldo, bool $dueUnita = false): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $unita = [];
    foreach ($dueUnita ? ['1', '2'] : ['1'] as $interno) {
        $u = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "TAC-{$seq}-{$interno}", 'nome' => "Interno {$interno}", 'interno' => $interno]);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $u->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
        $p = Anagrafica::forceCreate(['nome' => "Persona {$interno}", 'email' => "tac-{$seq}-{$interno}@test.it", 'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => 'TACPRS'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT).$interno.'XXX']);
        $p->condomini()->syncWithoutDetaching([$c->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $u->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
            'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
        if ($saldo !== 0) {
            Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $p->id, 'immobile_id' => $u->id, 'gestione_id' => $g->id,
                'saldo_iniziale' => $saldo, 'origine' => 'manuale', 'is_applicato' => false]);
        }
        $unita[] = $u;
    }
    $piano = PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => $metodo, 'applica_saldi' => true]);
    app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);

    return ['c' => $c, 'e' => $e, 'g' => $g, 'conto' => $conto, 'piano' => $piano->fresh(), 'unita' => $unita];
}

it('P5 (M5): con il saldo dell\'anno prima nelle rate il controllo usa la spesa delle quote, non l\'importo: la ricostruzione dà le cifre delle righe', function (string $metodo, int $saldo, bool $dueUnita) {
    $s = tacPianoConSaldo($metodo, $saldo, $dueUnita);
    $registrato = DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->whereNotNull('conto_id')->groupBy('conto_id')
        ->selectRaw('conto_id, SUM(importo) as i')->pluck('i', 'conto_id')->map(fn ($i) => (int) $i)->all();
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $ricostruito = app(DeliberatoRicostruito::class)->perPiano($s['piano']);

    expect($ricostruito)->not->toBeNull()
        ->and($ricostruito['per_voce'])->toBe($registrato)
        ->and(array_sum($registrato))->toBe($dueUnita ? 120000 : 120000);
})->with([
    'nessun saldo' => ['prima_rata', 0, false],
    'spalmati a debito' => ['tutte_rate', 18000, false],
    'spalmati a credito' => ['tutte_rate', -6000, false],
    'rata zero a debito' => ['rata_zero', 18000, false],
    'due unità, spalmati a debito' => ['tutte_rate', 18000, true],
]);

// ─── Coda 553: lo storno nell'anno dopo (SA3, PRE1), con la partita doppia ─────────────────────────────────────────────

/**
 * Il SAL dei lavori (o una fattura dell'ordinaria) pagato il 10/04/2026 con € 2,50 di commissione sulla voce data; il 2026 si chiude, il 2027 ha un'ordinaria
 * sua e la straordinaria continua; lo storno il 10/02/2027 va nel 2027 (Variante B1).
 */
/**
 * Il SAL del 2026 pagato con la commissione di € 2,50, il 2026 chiuso, l'«Ordinaria 2027» separata, lo storno del pagamento il 10/02/2027. `$commissioneSenzaVoce`:
 * la commissione scritta come prima che avesse una voce (sulle «Spese bancarie» di sistema, senza voce).
 */
function tacStornoNelDuemilaventisette($test, string $voceCommissione, bool $fatturaOrdinaria = false, bool $commissioneSenzaVoce = false): array
{
    $test->travelTo(Carbon::parse('2026-11-07 10:00', 'Europe/Rome'));
    $s = be52Scenario($test);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $s['bancarie'] = be52Voce($test, $s, $s['pc'], 'Spese bancarie', 'spesa', '150,00');
    $s['fornitore'] = be52Fornitore();
    $tetto = be52GestioneStraordinaria($s);
    $lavori = be52Voce($test, $s, $tetto['pc'], 'Lavori tetto', 'spesa', '2.000,00');
    $bancarieTetto = be52Voce($test, $s, $tetto['pc'], 'Bancarie del tetto', 'spesa', '50,00');
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), $fatturaOrdinaria
        ? be52PayloadFattura($s, $s['fornitore'], $s['spesa']->id, 1000.00, [], 'SAL-1')
        : be52PayloadFattura(array_merge($s, ['g' => $tetto['g']]), $s['fornitore'], $lavori->id, 1000.00, [], 'SAL-1'))->assertSessionHasNoErrors();
    $fattura = \App\Models\Gestionale\FatturaPassiva::where('numero_documento', 'SAL-1')->firstOrFail();
    $test->actingAs($test->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), [
        'fornitore_id' => $s['fornitore']->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'data_pagamento' => '2026-04-10',
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 250, 'bonifico_parlante' => false,
        'voce_commissione_id' => $voceCommissione === 'ordinaria' ? $s['bancarie']->id : $bancarieTetto->id, 'allow_overdraft' => true, 'iban_confermato_manualmente' => true,
        'conferma_duplicato_verificato' => true,
        'allocazioni' => [['fattura_id' => $fattura->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => (int) $fattura->fresh()->netto_a_pagare]],
    ])->assertSessionHasNoErrors();
    $p = PagamentoFornitore::where('condominio_id', $s['c']->id)->whereNull('pagamento_padre_id')->latest('id')->firstOrFail();
    if ($commissioneSenzaVoce) {
        $sistemaDiAllora = (int) \App\Models\Gestionale\ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'spese_bancarie')->value('id');
        DB::table('righe_scritture')->where('scrittura_id', $p->scrittura_contabile_id)->whereNotNull('voce_spesa_id')
            ->update(['voce_spesa_id' => null, 'conto_contabile_id' => $sistemaDiAllora]);
    }

    $e27 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $o27 = Gestione::create(['condominio_id' => $s['c']->id, 'nome' => 'Ordinaria 2027', 'tipo' => 'ordinaria', 'attiva' => true, 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);
    legaAEsercizio($e27, $o27->id);
    legaAEsercizio($e27, $tetto['g']->id);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $test->travelTo(Carbon::parse('2027-02-10 10:00', 'Europe/Rome'));
    $test->actingAs($test->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $p->fresh()]), ['motivo' => 'Bonifico respinto dalla banca'])->assertSessionHasNoErrors();
    $storno = PagamentoFornitore::where('pagamento_padre_id', $p->id)->firstOrFail();
    $righe = DB::table('righe_scritture')->where('scrittura_id', $storno->scrittura_contabile_id)->get();
    $sistema = (int) \App\Models\Gestionale\ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'spese_bancarie')->value('id');

    return ['s' => $s, 'e27' => $e27->fresh(), 'storno' => $storno, 'righe' => $righe, 'sistema' => $sistema, 'bancarieTetto' => $bancarieTetto, 'pagamento' => $p,
        'o27' => $o27, 'tetto' => $tetto];
}

/** Una voce di spesa di una gestione del 2027, dal modale (il suo piano dei conti, creato qui). */
function tacVoceDel2027($test, array $x, Gestione $gestione, string $nome): \App\Models\Gestionale\Conto
{
    $pc = PianoConto::where('gestione_id', $gestione->id)->first()
        ?? PianoConto::create(['condominio_id' => $x['s']['c']->id, 'gestione_id' => $gestione->id, 'nome' => 'Piano dei conti '.$gestione->nome]);

    return be52Voce($test, array_merge($x['s'], ['e' => $x['e27']]), $pc, $nome, 'spesa', '100,00');
}

it('SA3: lo storno nel 2027 di un pagamento dei lavori con la commissione sull\'ordinaria 2026: la riga va senza voce sulle spese bancarie di sistema, la partita doppia torna e il conto dei costi del 2027 torna', function () {
    $x = tacStornoNelDuemilaventisette($this, 'ordinaria');
    $commissione = $x['righe']->firstWhere('conto_contabile_id', $x['sistema']);
    $dare = (int) $x['righe']->where('tipo_riga', 'dare')->sum('importo');
    $avere = (int) $x['righe']->where('tipo_riga', 'avere')->sum('importo');
    $r = tacLeggi($x['s'], $x['e27'], '2027-02-10');
    $cr = collect(app(\App\Services\Gestionale\ControlliDelRendiconto::class)->perEsercizio($x['s']['c'], $x['e27'], '2027-02-10')['controlli'])->firstWhere('id', 'CR');

    expect((int) $x['storno']->scrittura->esercizio_id)->toBe((int) $x['e27']->id)
        // La partita doppia: DARE banca € 1.002,50 = AVERE debiti € 1.000,00 + AVERE commissione € 2,50.
        ->and([$dare, $avere])->toBe([100250, 100250])
        ->and([$commissione->tipo_riga, (int) $commissione->importo, $commissione->voce_spesa_id])->toBe(['avere', 250, null])
        ->and($commissione->note)->toBe('Storno della commissione che nell\'esercizio «Esercizio 2026» era sulla voce «Spese bancarie» della gestione «'
            .$x['s']['g']->nome.'», che l\'esercizio «Esercizio 2027» non ha.')
        // Il 2027: la commissione stornata sta nella riga «Commissioni senza voce», e il conto dei costi torna.
        ->and($r['fuori_capitoli']['commissioni_senza_voce']['consuntivo'])->toBe(-250)
        ->and($r['riconciliazione']['differenza'])->toBe(0)
        ->and($cr['esito'])->toBe('quadra')
        // Il 2026 chiuso non cambia: la commissione resta sulla voce dell'ordinaria.
        ->and((int) ((new SpesaPerVoceService)->perEsercizio($x['s']['e'])[$x['s']['bancarie']->id] ?? 0))->toBe(250);
});

it('l\'elenco delle commissioni senza voce del 2027 mostra lo storno con la voce di allora, e lo fa assegnare a una voce di un\'ordinaria del 2027', function () {
    $x = tacStornoNelDuemilaventisette($this, 'ordinaria');
    $elenco = app(CommissioniSenzaVoce::class)->perEsercizio($x['s']['c'], $x['e27']);
    $voce = collect($elenco['commissioni'])->firstWhere('id', (int) $x['righe']->firstWhere('conto_contabile_id', $x['sistema'])->id);

    expect($voce)->not->toBeNull()
        ->and($voce['assegnabile'])->toBeTrue()
        ->and($voce['solo_ordinarie'])->toBeTrue()
        ->and($voce['importo'])->toBe(-250)
        ->and($voce['motivo'])->toBe(CommissioniSenzaVoce::motivoStornoDiUnaVoceDiUnAltroEsercizio('Esercizio 2026', 'Spese bancarie', (string) $x['s']['g']->nome))
        ->and($voce['motivo'])->toContain('una voce di un\'ordinaria di questo esercizio')
        ->and($elenco['assegnabili'])->toBe(['numero' => 1, 'importo' => -250]);
});

it('lo storno nel 2027 di una commissione che era su una voce dell\'ordinaria 2026 si assegna a una voce dell\'«Ordinaria 2027»: la riga prende la voce e il suo mastro, il consuntivo della voce è −€ 2,50, il controllo dei costi del 2027 quadra, la partita doppia non cambia, il 2026 resta com\'era', function () {
    $x = tacStornoNelDuemilaventisette($this, 'ordinaria');
    $bancarie27 = tacVoceDel2027($this, $x, $x['o27'], 'Spese bancarie 2027');
    $riga = $x['righe']->firstWhere('conto_contabile_id', $x['sistema']);

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$x['s']['c'], $x['e27']]),
        ['commissioni' => [(int) $riga->id], 'voce_id' => $bancarie27->id])->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'success');
    $dopo = DB::table('righe_scritture')->where('id', $riga->id)->first();
    $r = tacLeggi($x['s'], $x['e27'], '2027-02-10');
    $cr = collect(app(\App\Services\Gestionale\ControlliDelRendiconto::class)->perEsercizio($x['s']['c'], $x['e27'], '2027-02-10')['controlli'])->firstWhere('id', 'CR');
    $righe = DB::table('righe_scritture')->where('scrittura_id', $x['storno']->scrittura_contabile_id)->get();

    expect([(int) $dopo->voce_spesa_id, (int) $dopo->conto_contabile_id, $dopo->tipo_riga, (int) $dopo->importo])
        ->toBe([(int) $bancarie27->id, (int) $bancarie27->conto_contabile_id, 'avere', 250])
        // Il consuntivo della voce dell'Ordinaria 2027: il rimborso della commissione, −€ 2,50.
        ->and((int) ((new SpesaPerVoceService)->perEsercizio($x['e27'])[$bancarie27->id] ?? 0))->toBe(-250)
        ->and(tacVoce($r, $bancarie27->id)['consuntivo'])->toBe(-250)
        ->and($r['fuori_capitoli']['commissioni_senza_voce']['consuntivo'])->toBe(0)
        ->and($r['riconciliazione']['differenza'])->toBe(0)
        ->and($cr['esito'])->toBe('quadra')
        // La partita doppia dello storno: DARE banca € 1.002,50 = AVERE debiti € 1.000,00 + AVERE commissione € 2,50.
        ->and([(int) $righe->where('tipo_riga', 'dare')->sum('importo'), (int) $righe->where('tipo_riga', 'avere')->sum('importo')])->toBe([100250, 100250])
        // Il 2026 chiuso tiene la commissione sulla bancaria dell'ordinaria.
        ->and((int) ((new SpesaPerVoceService)->perEsercizio($x['s']['e'])[$x['s']['bancarie']->id] ?? 0))->toBe(250);
    // Nessuna commissione resta da assegnare: la fascia della Sintesi non c'è più.
    expect(collect(app(SintesiDelRendiconto::class)->perEsercizio($x['s']['c'], $x['e27'], '2027-02-10')['fasce'])->firstWhere('tipo', 'commissioni'))->toBeNull();
});

it('lo storno della commissione dell\'ordinaria 2026 non si assegna a una voce che non è di un\'ordinaria del 2027: né a una voce del tetto (la gestione del pagamento), né a una dell\'ordinaria 2026', function (string $quale) {
    $x = tacStornoNelDuemilaventisette($this, 'ordinaria');
    $voce = $quale === 'tetto' ? $x['bancarieTetto'] : $x['s']['bancarie'];
    $riga = $x['righe']->firstWhere('conto_contabile_id', $x['sistema']);

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$x['s']['c'], $x['e27']]),
        ['commissioni' => [(int) $riga->id], 'voce_id' => $voce->id])->assertSessionHasErrors('voce_id');
    expect(DB::table('righe_scritture')->where('id', $riga->id)->value('voce_spesa_id'))->toBeNull();
})->with(['la voce del tetto' => ['tetto'], 'la bancaria dell\'ordinaria 2026' => ['ordinaria_2026']]);

it('lo storno nel 2027 della commissione che era sull\'ordinaria 2026, con il 2027 chiuso: l\'elenco lo mostra senza offrirlo, e il motivo dice che l\'esercizio è chiuso, non che si assegna', function () {
    $x = tacStornoNelDuemilaventisette($this, 'ordinaria');
    DB::table('esercizi')->where('id', $x['e27']->id)->update(['stato' => 'chiuso']);
    $elenco = app(CommissioniSenzaVoce::class)->perEsercizio($x['s']['c'], $x['e27']->fresh());
    $voce = collect($elenco['commissioni'])->firstWhere('id', (int) $x['righe']->firstWhere('conto_contabile_id', $x['sistema'])->id);

    expect($voce['assegnabile'])->toBeFalse()
        ->and($voce['motivo'])->toBe('È lo storno di una commissione dell\'esercizio «Esercizio 2026», che era sulla voce «Spese bancarie» della gestione «'.$x['s']['g']->nome
            .'»: questo esercizio non ha quella gestione. L\'esercizio è chiuso: lo storno resta senza voce, in una riga sua del rendiconto.')
        ->and($elenco['assegnabili'])->toBe(['numero' => 0, 'importo' => 0]);
});

it('la fascia della Sintesi del 2027 con lo storno da assegnare invita ad assegnare la voce, con la regola dell\'elenco', function () {
    $x = tacStornoNelDuemilaventisette($this, 'ordinaria');
    $fascia = collect(app(SintesiDelRendiconto::class)->perEsercizio($x['s']['c'], $x['e27'], '2027-02-10')['fasce'])->firstWhere('tipo', 'commissioni');

    expect($fascia['da_assegnare'])->toBe(1)
        ->and($fascia['frase'])->toBe('€ -2,50 di commissioni senza voce: non stanno in nessun capitolo, e il conguaglio non le divide.');
});

it('«Assegna la voce» sullo storno nel 2027 di una commissione dell\'ordinaria 2026 lo chiama storno, nel messaggio e nella riga del registro', function () {
    $x = tacStornoNelDuemilaventisette($this, 'ordinaria');
    $bancarie27 = tacVoceDel2027($this, $x, $x['o27'], 'Spese bancarie 2027');
    \Illuminate\Support\Facades\Log::spy();

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$x['s']['c'], $x['e27']]),
        ['commissioni' => [(int) $x['righe']->firstWhere('conto_contabile_id', $x['sistema'])->id], 'voce_id' => $bancarie27->id])->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'success'
            && $m['message'] === 'Voce «Spese bancarie 2027» assegnata a 1 storno: il consuntivo della voce cambia di € -2,50.');
    \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
        ->with(\Mockery::on(fn ($riga) => is_string($riga) && str_starts_with($riga, 'Voce assegnata a 1 storno senza voce da utente #')))->once();
});

it('la pagina del riepilogo finanziario del 2027 riceve quante commissioni senza voce si assegnano, con la regola dell\'elenco: 1 con lo storno della commissione (che la riga non conta fra le commissioni), 0 con il 2027 chiuso anche se la riga c\'è', function () {
    $x = tacStornoNelDuemilaventisette($this, 'ordinaria');
    $pagina = fn () => $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.riepilogo', [$x['s']['c'], $x['e27']]))->assertOk()->viewData('page')['props'];
    $riga = fn (array $p) => [$p['riepilogo']['fuori_capitoli']['commissioni_senza_voce']['numero'], $p['riepilogo']['fuori_capitoli']['commissioni_senza_voce']['consuntivo']];
    $p = $pagina();
    expect($p['commissioni_assegnabili'])->toBe(1)->and($riga($p))->toBe([0, -250]);

    DB::table('esercizi')->where('id', $x['e27']->id)->update(['stato' => 'chiuso']);
    $p = $pagina();
    expect($p['commissioni_assegnabili'])->toBe(0)->and($riga($p))->toBe([0, -250]);
});

it('PRE1: lo stesso per un pagamento dell\'ordinaria 2026 con la commissione sulla sua bancaria (preesistente dalla 1.12.0-beta.5)', function () {
    $x = tacStornoNelDuemilaventisette($this, 'ordinaria', fatturaOrdinaria: true);
    $r = tacLeggi($x['s'], $x['e27'], '2027-02-10');

    expect($x['righe']->firstWhere('conto_contabile_id', $x['sistema'])?->voce_spesa_id)->toBeNull()
        ->and($r['riconciliazione']['differenza'])->toBe(0);
});

it('una commissione su una voce di una gestione che il 2027 ha ancora (la straordinaria che continua): lo storno tiene la voce, come prima', function () {
    $x = tacStornoNelDuemilaventisette($this, 'straordinaria');
    $commissione = $x['righe']->firstWhere('voce_spesa_id', $x['bancarieTetto']->id);

    expect($commissione)->not->toBeNull()
        ->and([$commissione->tipo_riga, (int) $commissione->importo, $commissione->note])->toBe(['avere', 250, null])
        ->and(tacLeggi($x['s'], $x['e27'], '2027-02-10')['riconciliazione']['differenza'])->toBe(0);
});

// ─── Coda 553: la gestione spenta (SA10), la proposta (M19), «Assegna la voce» (P8, M21), il perché (SA11) ─────────────────

/** Il condominio dell'antenna con «Spese bancarie» dell'ordinaria e la straordinaria del tetto con la fattura del SAL. */
function tacTetto($test): array
{
    $test->travelTo(Carbon::parse('2026-11-07 10:00', 'Europe/Rome'));
    $s = be52Scenario($test);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $s['bancarie'] = be52Voce($test, $s, $s['pc'], 'Spese bancarie', 'spesa', '150,00');
    $s['fornitore'] = be52Fornitore();
    $s['tetto'] = be52GestioneStraordinaria($s);
    $s['lavori'] = be52Voce($test, $s, $s['tetto']['pc'], 'Lavori tetto', 'spesa', '5.000,00');
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura(array_merge($s, ['g' => $s['tetto']['g']]), $s['fornitore'], $s['lavori']->id, 2000.00, [], 'SAL-1'))
        ->assertSessionHasNoErrors();
    $s['fattura'] = \App\Models\Gestionale\FatturaPassiva::where('numero_documento', 'SAL-1')->where('condominio_id', $s['c']->id)->firstOrFail();

    return $s;
}

function tacPaga($test, array $s, int $voceId, int $allocato = 1000)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), [
        'fornitore_id' => $s['fornitore']->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'data_pagamento' => '2026-04-10',
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 250, 'bonifico_parlante' => false,
        'voce_commissione_id' => $voceId, 'allow_overdraft' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => [['fattura_id' => $s['fattura']->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => $allocato]],
    ]);
}

it('SA10: la voce di un\'ordinaria spenta non si offre e il server la rifiuta, con il perché', function () {
    $s = tacTetto($this);
    DB::table('gestioni')->where('id', $s['g']->id)->update(['attiva' => false]);
    $elenco = collect(app(VoceDellaCommissione::class)->elenco($s['c']->id))->keyBy('id');
    tacPaga($this, $s, $s['bancarie']->id)->assertSessionHasErrors('voce_commissione_id');

    expect($elenco[$s['bancarie']->id]['esercizi'])->toBe([])
        ->and(session('errors')->first('voce_commissione_id'))->toContain('che non è attiva')
        ->and(PagamentoFornitore::where('condominio_id', $s['c']->id)->count())->toBe(0);
});

it('SA10: un legame spento fra l\'ordinaria e l\'esercizio vale come nessun legame', function () {
    $s = tacTetto($this);
    DB::table('esercizio_gestione')->where('gestione_id', $s['g']->id)->update(['attiva' => false]);
    tacPaga($this, $s, $s['bancarie']->id)->assertSessionHasErrors('voce_commissione_id');

    expect(session('errors')->first('voce_commissione_id'))->toContain('che non è in questo esercizio');
});

it('M19: la proposta del server per la gestione dei lavori è la voce dell\'ordinaria dell\'ultima commissione', function () {
    $s = tacTetto($this);
    tacPaga($this, $s, $s['bancarie']->id)->assertSessionHasNoErrors();

    expect(app(VoceDellaCommissione::class)->proposta($s['c']->id, (int) $s['tetto']['g']->id))->toBe((int) $s['bancarie']->id);
});

it('P8 (M21): «Assegna la voce» dà la voce dell\'ordinaria alle commissioni senza voce dei lavori, e la voce sale', function () {
    $s = tacTetto($this);
    $bancarieTetto = be52Voce($this, $s, $s['tetto']['pc'], 'Bancarie del tetto', 'spesa', '50,00');
    tacPaga($this, $s, $bancarieTetto->id)->assertSessionHasNoErrors();
    $sistema = (int) \App\Models\Gestionale\ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'spese_bancarie')->value('id');
    // Com'era scritta prima della 1.12.0-beta.5: senza voce.
    DB::table('righe_scritture')->where('voce_spesa_id', $bancarieTetto->id)->update(['conto_contabile_id' => $sistema, 'voce_spesa_id' => null]);
    $ids = collect(app(CommissioniSenzaVoce::class)->perEsercizio($s['c'], $s['e'])['commissioni'])->pluck('id')->all();
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$s['c'], $s['e']]), ['commissioni' => $ids, 'voce_id' => $s['bancarie']->id])
        ->assertSessionHasNoErrors();

    expect((int) ((new SpesaPerVoceService)->perEsercizio($s['e'])[$s['bancarie']->id] ?? 0))->toBe(250);
});

it('SA11: il rifiuto delle commissioni di due gestioni dice che si assegnano una gestione alla volta, anche con la stessa voce', function () {
    $s = tacTetto($this);
    $bancarieTetto = be52Voce($this, $s, $s['tetto']['pc'], 'Bancarie del tetto', 'spesa', '50,00');
    $sistema = (int) \App\Models\Gestionale\ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'spese_bancarie')->value('id');
    tacPaga($this, $s, $bancarieTetto->id)->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, $s['fornitore'], $s['spesa']->id, 1000.00, [], 'FT-ORD'))->assertSessionHasNoErrors();
    $ordinaria = \App\Models\Gestionale\FatturaPassiva::where('numero_documento', 'FT-ORD')->where('condominio_id', $s['c']->id)->firstOrFail();
    tacPaga($this, array_merge($s, ['fattura' => $ordinaria]), $s['bancarie']->id)->assertSessionHasNoErrors();
    DB::table('righe_scritture')->whereIn('voce_spesa_id', [$bancarieTetto->id, $s['bancarie']->id])->update(['conto_contabile_id' => $sistema, 'voce_spesa_id' => null]);
    $ids = collect(app(CommissioniSenzaVoce::class)->perEsercizio($s['c'], $s['e'])['commissioni'])->pluck('id')->all();

    expect(fn () => app(CommissioniSenzaVoce::class)->assegna($s['c'], $s['e'], $ids, null, (int) $s['bancarie']->id))
        ->toThrow(\DomainException::class, 'Le commissioni scelte sono di gestioni diverse: si assegnano una gestione alla volta, anche con la stessa voce.');
});
