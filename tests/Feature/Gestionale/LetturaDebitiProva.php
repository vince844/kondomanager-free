<?php

/**
 * La prova per persona della lettura comune dei debiti, contro cifre calcolate in un altro modo, per D = oggi. La usano
 * `LetturaDebitiTest` (i casi scritti a mano) e `LetturaDebitiSequenzeTest` (le sequenze contabili generate da un seme); gli
 * scenari li usano anche i test delle letture che ne dipendono (la situazione debitoria, la pagina del piano, la previsione di cassa).
 *
 * NON contiene nessun it() o test — solo funzioni di supporto.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Services\Gestionale\CreditoService;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

/**
 * Per ogni persona ([nome => id]) le differenze trovate; vuoto se la lettura regge.
 *
 * (1) La posizione economica (rate emesse: importo − pagato, anche a credito; quote in bozza: il pregresso meno ciò che vi è già
 * stato pagato; i saldi che nessun piano ha assorbito) è il Saldo finale dell'estratto conto.
 * (2) L'estratto conto è il mastrino della persona sui tre mastri, più i saldi (i liberi e il pregresso delle quote fuori dal
 * giornale), meno il credito riportato già uscito da «Passate gestioni» delle quote ora a giornale. La lettura non dice i saldi,
 * il credito riportato già uscito né le righe su «Passate gestioni»: il suo netto è l'estratto meno queste tre parti.
 * (3) Le righe della lettura su «Crediti verso condomini» (rate emesse e non ancora emesse) contro le quote di oggi: Σ (importo −
 * pagato) delle quote a giornale, meno il pagato fuori dal giornale delle quote a debito e delle quote a credito senza riporto
 * (il credito delle entrate dell'anno, che si rimborsa da «Crediti verso condomini»).
 * (4) Il credito disponibile della lettura è quello di CreditoService.
 *
 * Il totale della persona non dice la divisione fra residuo, credito sulla rata e rate non emesse, né lo scaduto: la prova per chiave è
 * {@see lcdProvaChiavi()}.
 *
 * @param  array<string,int>  $persone
 * @return list<string>
 */
function lcdProvaPersone($test, $admin, int $condominioId, int $esercizioId, array $persone): array
{
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')
        ->where('piani_rate.condominio_id', $condominioId)
        ->get(['rate_quote.rata_id', 'rate_quote.anagrafica_id', 'rate_quote.immobile_id', 'rate_quote.importo', 'rate_quote.importo_pagato', 'rate_quote.scrittura_contabile_id', 'rate_quote.regole_calcolo', 'rate.stato as stato_rata'])
        ->map(function ($q) {
            $q->usato = (int) (json_decode((string) $q->regole_calcolo, true)['importi']['saldo_usato'] ?? 0);

            return $q;
        });
    $righe = DB::table('righe_scritture')->join('scritture_contabili', 'scritture_contabili.id', '=', 'righe_scritture.scrittura_id')
        ->join('conti_contabili', 'conti_contabili.id', '=', 'righe_scritture.conto_contabile_id')
        ->where('scritture_contabili.condominio_id', $condominioId)->whereNull('scritture_contabili.deleted_at')->whereNotNull('righe_scritture.anagrafica_id')
        ->get(['conti_contabili.ruolo', 'scritture_contabili.tipo_movimento', 'righe_scritture.rata_id', 'righe_scritture.anagrafica_id', 'righe_scritture.immobile_id', 'righe_scritture.tipo_riga', 'righe_scritture.importo']);
    $segno = fn ($r) => $r->tipo_riga === 'dare' ? (int) $r->importo : -(int) $r->importo;
    $uscito = [];
    foreach ($righe->where('ruolo', 'passate_gestioni')->where('tipo_movimento', '!=', 'emissione_rata') as $r) {
        $k = "{$r->rata_id}|{$r->anagrafica_id}|{$r->immobile_id}";
        $uscito[$k] = ($uscito[$k] ?? 0) + $segno($r);
    }

    $differenze = [];
    foreach ($persone as $chi => $id) {
        $sue = $quote->where('anagrafica_id', $id);
        $liberi = (int) DB::table('saldi')->where('condominio_id', $condominioId)->where('esercizio_id', $esercizioId)->where('anagrafica_id', $id)->where('is_applicato', false)->sum('saldo_iniziale');
        $pagina = $test->actingAs($admin)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$condominioId, $id]));
        $pagina->assertOk();
        $estratto = (int) $pagina->viewData('page')['props']['stats']['saldo_raw'];

        $posizione = (int) $sue->where('stato_rata', 'emessa')->sum(fn ($q) => (int) $q->importo - (int) $q->importo_pagato)
            + (int) $sue->where('stato_rata', '!=', 'emessa')->sum(fn ($q) => $q->usato - (int) $q->importo_pagato)
            + $liberi;
        $saldi = $liberi + (int) $sue->filter(fn ($q) => $q->scrittura_contabile_id === null)->sum('usato');
        $giaEmessi = (int) $sue->filter(fn ($q) => $q->scrittura_contabile_id !== null && (int) $q->importo <= 0)->sum(fn ($q) => $uscito["{$q->rata_id}|{$q->anagrafica_id}|{$q->immobile_id}"] ?? 0);
        $passate = (int) $righe->where('ruolo', 'passate_gestioni')->where('anagrafica_id', $id)->sum($segno);
        $deve = (int) $sue->filter(fn ($q) => $q->scrittura_contabile_id !== null)->sum(fn ($q) => (int) $q->importo - (int) $q->importo_pagato)
            - (int) $sue->filter(fn ($q) => $q->scrittura_contabile_id === null && ((int) $q->importo > 0 || $q->usato === 0))->sum('importo_pagato');

        $l = app(LetturaDebiti::class)->perPersona($condominioId, $id);
        $t = $l['totali'];
        $sulleRate = $t['emesso_non_pagato'] - $t['crediti'] + $t['non_emesse'];
        $credito = (int) app(CreditoService::class)->perAnagrafica($condominioId, $id)['totale_cents'];

        foreach ([
            'posizione = estratto' => [$posizione, $estratto],
            'netto = estratto − saldi + credito già emesso − «Passate gestioni»' => [$t['netto'], $estratto - $saldi + $giaEmessi - $passate],
            'righe su «Crediti verso condomini» = quote di oggi' => [$sulleRate, $deve],
            'credito disponibile = CreditoService' => [$l['credito_disponibile']['totale'] ?? null, $credito],
        ] as $cosa => [$a, $b]) {
            if ($a !== $b) {
                $differenze[] = sprintf('%s, %s: %s contro %s', $chi, $cosa, json_encode($a), json_encode($b));
            }
        }
    }

    return $differenze;
}

/**
 * La prova per chiave, per D = oggi; vuoto se la lettura regge.
 *
 * (a) Per (persona, unità, rata) con la quota emessa: dovuto − versato della lettura = Σ (importo − pagato) delle quote di quella chiave;
 * e nessuna chiave nella lettura come emessa senza una quota emessa.
 * (b) Lo scaduto per (persona, unità) della lettura = la morosità dell'anteprima del passaggio (`AnteprimaPassaggio::morosita`, la regola
 * comune già scritta), con le sue eccezioni dichiarate: la quota a credito sulla stessa (persona, unità, rata) di una a debito (una vendita
 * seguita da una ricompra, decisione 25: la morosità somma le quote a debito e non vede la gemella a credito, che il giornale netta); una
 * quota con la scrittura di emissione dopo oggi (per la morosità è a giornale, per la lettura non è ancora dovuta).
 * (c) Per (persona, rata) il residuo della situazione debitoria del modulo dell'incasso (le righe emesse) = il netto della lettura su
 * tutte le unità della persona.
 *
 * @param  array<string,int>  $persone  [nome => id]
 * @param  array<string,\App\Models\Immobile>  $unita
 * @return list<string>
 */
function lcdProvaChiavi($test, $admin, int $condominioId, array $persone, array $unita): array
{
    $oggi = \App\Helpers\DateHelper::oggiUtente();
    $quote = DB::table('rate_quote as q')->join('rate', 'rate.id', '=', 'q.rata_id')->join('piani_rate as p', 'p.id', '=', 'rate.piano_rate_id')
        ->leftJoin('scritture_contabili as em', fn ($j) => $j->on('em.id', '=', 'q.scrittura_contabile_id')->whereNull('em.deleted_at')->where('em.tipo_movimento', 'emissione_rata'))
        ->where('p.condominio_id', $condominioId)->whereNotNull('q.anagrafica_id')
        ->get(['q.rata_id', 'q.anagrafica_id', 'q.immobile_id', 'q.importo', 'q.importo_pagato', 'rate.numero_rata', 'em.id as emissione', 'em.data_competenza as emessa_il']);

    $perChiave = [];
    $eccezioni = [];
    foreach ($quote as $q) {
        $k = "{$q->anagrafica_id}|{$q->immobile_id}|{$q->rata_id}";
        $perChiave[$k] ??= ['quote' => 0, 'emessa' => false, 'debito' => false, 'credito' => false, 'n' => (int) $q->numero_rata];
        $perChiave[$k]['quote'] += (int) $q->importo - (int) $q->importo_pagato;
        $perChiave[$k]['emessa'] = $perChiave[$k]['emessa'] || $q->emissione !== null;
        $perChiave[$k][(int) $q->importo > 0 ? 'debito' : 'credito'] = true;
        if ($q->emissione !== null && substr((string) $q->emessa_il, 0, 10) > $oggi) {
            $eccezioni["{$q->anagrafica_id}|{$q->immobile_id}"] = 'emissione dopo oggi';
        }
    }
    foreach ($perChiave as $k => $g) {
        if ($g['debito'] && $g['credito']) {
            [$persona, $immobile] = explode('|', $k);
            $eccezioni["{$persona}|{$immobile}"] = 'gemelle a debito e a credito';
        }
    }

    $letture = app(LetturaDebiti::class)->perCondominio($condominioId);
    $differenze = [];
    $lette = [];
    foreach ($letture as $persona => $l) {
        foreach ($l['rate'] as $r) {
            $lette["{$persona}|{$r['immobile_id']}|{$r['rata_id']}"] = $r['dovuto'] - $r['versato'];
        }
    }
    // (a)
    foreach ($perChiave as $k => $g) {
        if ($g['emessa'] && ($lette[$k] ?? null) !== $g['quote']) {
            $differenze[] = "chiave {$k} (rata {$g['n']}): lettura " . json_encode($lette[$k] ?? null) . " contro quote {$g['quote']}";
        }
    }
    foreach ($lette as $k => $v) {
        if (! ($perChiave[$k]['emessa'] ?? false)) {
            $differenze[] = "chiave {$k}: nella lettura come emessa ({$v}) senza una quota emessa";
        }
    }

    // (b)
    $morosita = new \ReflectionMethod(\App\Services\Subentro\AnteprimaPassaggio::class, 'morosita');
    foreach ($persone as $chi => $id) {
        foreach ($unita as $nome => $immobile) {
            if (isset($eccezioni["{$id}|{$immobile->id}"])) {
                continue;
            }
            $mor = (int) ($morosita->invoke(app(\App\Services\Subentro\AnteprimaPassaggio::class), $immobile, $id, [(int) $immobile->id])['importo'] ?? 0);
            $scaduto = (int) collect($letture[$id]['rate'] ?? [])->filter(fn ($r) => (int) $r['immobile_id'] === (int) $immobile->id && $r['scaduta'])->sum('residuo');
            if ($mor !== $scaduto) {
                $differenze[] = "{$chi}, unità {$nome}: morosità del passaggio {$mor} contro scaduto della lettura {$scaduto}";
            }
        }
    }

    // (c)
    foreach ($persone as $chi => $id) {
        $righe = $test->actingAs($admin)->getJson(route('admin.gestionale.situazione-debitoria', ['condominio' => $condominioId, 'anagrafica_id' => $id]))->assertOk()->json('rate');
        $situazione = [];
        foreach ($righe as $r) {
            if ($r['is_emitted']) {
                $situazione[(int) $r['rata_padre_id']] = (int) round($r['residuo'] * 100);
            }
        }
        $netto = [];
        foreach ($letture[$id]['rate'] ?? [] as $r) {
            $netto[(int) $r['rata_id']] = ($netto[(int) $r['rata_id']] ?? 0) + $r['residuo'] - $r['credito'];
        }
        foreach ($situazione as $rata => $v) {
            if (($netto[$rata] ?? 0) !== $v) {
                $differenze[] = "{$chi}, rata_id {$rata}: situazione debitoria {$v} contro netto della lettura " . json_encode($netto[$rata] ?? null);
            }
        }
        foreach ($netto as $rata => $v) {
            if (! isset($situazione[$rata]) && $v !== 0) {
                $differenze[] = "{$chi}, rata_id {$rata}: netto della lettura {$v}, nessuna riga emessa nella situazione debitoria";
            }
        }
    }

    return $differenze;
}

/**
 * Un preventivo ordinario ripartito a millesimi sulle unità date (['A' => ['ugo', 1000]]), in `$numeroRate` rate mensili dalla
 * prima scadenza; i saldi pregressi [[persona|null, unità, centesimi]]; una voce di entrata facoltativa; i conti per emettere.
 */
function lcdScenario(array $unita, int $numeroRate, string $metodo, array $saldi = [], int $preventivo = 120000, string $primaScadenza = '2026-01-05', int $giorno = 5, int $entrata = 0): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => $preventivo]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    if ($entrata > 0) {
        $contoE = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Affitto antenna', 'tipo' => 'entrata', 'natura_spesa' => 'ordinaria', 'importo' => $entrata]);
        $ctmE = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $contoE->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctmE, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }

    $banca = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 100000]);

    $persone = [];
    $immobili = [];
    foreach ($unita as $nome => [$chi, $millesimi]) {
        $immobili[$nome] = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "LCD-{$seq}-{$nome}", 'nome' => "Interno {$nome}", 'interno' => $nome]);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $immobili[$nome]->id, 'valore' => (float) $millesimi, 'created_at' => now(), 'updated_at' => now()]);
        $persone[$chi] ??= tap(Anagrafica::forceCreate(['nome' => ucfirst($chi), 'email' => "lcd-{$chi}{$seq}@test.it", 'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => strtoupper(str_pad($chi, 6, 'X')) . str_pad((string) $seq, 10, '0', STR_PAD_LEFT)]), fn ($a) => $a->condomini()->syncWithoutDetaching([$c->id]));
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$chi]->id, 'immobile_id' => $immobili[$nome]->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
            'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ($saldi as [$chi, $nome, $importo]) {
        Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $chi === null ? null : $persone[$chi]->id, 'immobile_id' => $immobili[$nome]->id,
            'gestione_id' => $g->id, 'saldo_iniziale' => $importo, 'origine' => 'manuale', 'is_applicato' => false]);
    }
    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => $numeroRate, 'giorno_scadenza' => $giorno, 'data_prima_scadenza' => $primaScadenza, 'metodo_distribuzione' => $metodo, 'applica_saldi' => true,
    ]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $e);

    return compact('c', 'e', 'g', 'immobili', 'persone', 'piano', 'cassa');
}

function lcdRata(array $s, int $numero): int
{
    return (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', $numero)->value('id');
}

function lcdQuota(array $s, string $chi, int $numero): int
{
    return (int) DB::table('rate_quote')->where('rata_id', lcdRata($s, $numero))->where('anagrafica_id', $s['persone'][$chi]->id)->value('id');
}

function lcdEmetti($test, array $s, array $numeri, string $data): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => array_map(fn ($n) => lcdRata($s, $n), $numeri), 'data_emissione' => $data, 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors();
}

/** L'incasso dalla rotta della pagina: [id quota => centesimi], la parte in più in centesimi. Restituisce l'id della scrittura. */
function lcdIncassa($test, array $s, string $chi, array $righe, string $data, int $parteInPiu = 0): int
{
    $totale = array_sum($righe) + $parteInPiu;
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data, 'importo_totale' => number_format($totale / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => number_format($parteInPiu / 100, 2, '.', ''), 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => array_map(fn ($id, $cent) => ['rata_id' => (int) $id, 'importo' => number_format($cent / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
}

/** Lo storno dalla rotta della pagina; senza un'altra natura, «versamento tornato indietro». */
function lcdStorna($test, array $s, int $scritturaId, array $dati = []): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $scritturaId]), $dati + stornoVersamentoTornato())->assertSessionHasNoErrors();
}

function lcdLeggi(array $s, string $chi, ?string $alla = null): array
{
    return app(LetturaDebiti::class)->perPersona((int) $s['c']->id, (int) $s['persone'][$chi]->id, $alla);
}

/** La riga della lettura per (unità, rata), o null. */
function lcdRiga(array $lettura, array $s, string $unita, int $numero): ?array
{
    return collect($lettura['rate'])->first(fn ($r) => (int) $r['rata_id'] === lcdRata($s, $numero) && (int) $r['immobile_id'] === (int) $s['immobili'][$unita]->id);
}

function lcdEstratto($test, array $s, string $chi): int
{
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone'][$chi]]));
    $r->assertOk();

    return (int) $r->viewData('page')['props']['stats']['saldo_raw'];
}

/** La voce «Crediti verso condomini» dello stato patrimoniale alla data, e le righe di quel conto senza persona. */
function lcdStatoPatrimoniale(array $s, string $d): array
{
    $p = app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e'], $d);
    $senzaPersona = (int) DB::table('righe_scritture as rs')->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
        ->whereNull('sc.deleted_at')->where('sc.condominio_id', $s['c']->id)->where('cc.ruolo', 'crediti_condomini')->whereNull('rs.anagrafica_id')
        ->where('sc.data_competenza', '<', CarbonImmutable::parse($d)->addDay()->format('Y-m-d'))
        ->sum(DB::raw("CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END"));

    return ['voce' => (int) $p['sintesi']['crediti_condomini'], 'senza_persona' => $senzaPersona];
}

/**
 * Rossi, un'unità: la rata 0 in bozza a credito di 3000; le rate 1, 2, 3 emesse con scadenza 14/12, 15/12, 16/12; la rata 4 in
 * bozza scaduta il 10/12; la rata 5 emessa con scadenza 14/01/2027. Rate da 10000, emesse il 01/12/2026.
 */
function lcdRossi($test): array
{
    $test->travelTo('2026-12-01 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 1000]], 12, 'rata_zero', [['rossi', 'A', -3000]]);
    lcdEmetti($test, $s, [1, 2, 3, 5], '2026-12-01');
    foreach ([1 => '2026-12-14', 2 => '2026-12-15', 3 => '2026-12-16', 4 => '2026-12-10', 5 => '2027-01-14'] as $n => $data) {
        DB::table('rate')->where('id', lcdRata($s, $n))->update(['data_scadenza' => $data]);
        DB::table('rate_quote')->where('rata_id', lcdRata($s, $n))->update(['data_scadenza' => $data]);
    }

    return $s;
}
