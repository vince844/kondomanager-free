<?php

/**
 * Il condominio grande delle misure del conguaglio: 3.000 unità, 3.300 persone (un'unità su dieci passa di mano il 01/07), 30 voci con la spesa sul
 * giornale; le voci sulla stessa tabella, o una tabella per voce. Con `$fatture`, una gestione straordinaria con altrettante fatture, ognuna con
 * la sua competenza scritta (un giorno diverso), sulla stessa tabella.
 *
 * Scritto a blocchi: 3.000 unità fatte con i modelli costerebbero più della misura. Il condominio ha i conti di sistema e la cassa, così la
 * Sintesi e i controlli si leggono dalle loro rotte.
 *
 * NON contiene nessun it() o test — solo funzioni di supporto.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Services\CondominioService;
use Illuminate\Support\Facades\DB;

const CGM_UNITA = 3000;
const CGM_VOCI = 30;

/** @return array{c: Condominio, e: Esercizio, persone: int, unita: list<int>} */
function cgmCondominio(bool $tabellaPerVoce, int $fatture = 0, int $quanteUnita = CGM_UNITA, int $quanteVoci = CGM_VOCI): array
{
    $adesso = now()->format('Y-m-d H:i:s');
    $c = Condominio::factory()->create(['nome' => 'Condominio grande']);
    app(CondominioService::class)->ensureDefaultConti($c);
    $banca = ContoContabile::where('condominio_id', $c->id)->where('ruolo', 'cassa')->first()
        ?? ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 0]);
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($c, $e);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);

    $righe = [];
    for ($u = 1; $u <= $quanteUnita; $u++) {
        $righe[] = ['condominio_id' => $c->id, 'codice_immobile' => "CGM-{$c->id}-{$u}", 'nome' => "Unità {$u}", 'interno' => (string) $u, 'created_at' => $adesso, 'updated_at' => $adesso];
    }
    foreach (array_chunk($righe, 500) as $blocco) {
        DB::table('immobili')->insert($blocco);
    }
    $unita = DB::table('immobili')->where('condominio_id', $c->id)->orderBy('id')->pluck('id')->all();

    $persone = [];
    foreach ($unita as $i => $iid) {
        $persone[] = ['iid' => $iid, 'dal' => '2020-01-01', 'al' => $i % 10 === 0 ? '2026-06-30' : null];
        if ($i % 10 === 0) {
            $persone[] = ['iid' => $iid, 'dal' => '2026-07-01', 'al' => null];
        }
    }
    $anagrafiche = [];
    foreach ($persone as $n => $p) {
        $anagrafiche[] = ['nome' => 'Persona '.($n + 1), 'indirizzo' => 'Via Lunga 1', 'email' => "cgm{$c->id}-".($n + 1).'@test.it',
            'codice_fiscale' => 'CGM'.str_pad((string) $c->id, 3, '0', STR_PAD_LEFT).str_pad((string) ($n + 1), 10, '0', STR_PAD_LEFT), 'created_at' => $adesso, 'updated_at' => $adesso];
    }
    foreach (array_chunk($anagrafiche, 500) as $blocco) {
        DB::table('anagrafiche')->insert($blocco);
    }
    $ids = DB::table('anagrafiche')->where('email', 'like', "cgm{$c->id}-%")->orderBy('id')->pluck('id')->all();
    $titolarita = [];
    $legami = [];
    foreach ($persone as $k => $p) {
        $titolarita[] = ['anagrafica_id' => $ids[$k], 'immobile_id' => $p['iid'], 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => $p['dal'], 'data_fine' => $p['al'],
            'created_at' => $adesso, 'updated_at' => $adesso];
        $legami[] = ['anagrafica_id' => $ids[$k], 'condominio_id' => $c->id];
    }
    foreach (array_chunk($titolarita, 500) as $blocco) {
        DB::table('anagrafica_immobile')->insert($blocco);
    }
    foreach (array_chunk($legami, 500) as $blocco) {
        DB::table('anagrafica_condominio')->insert($blocco);
    }

    $tabelle = [];
    foreach (range(1, $tabellaPerVoce ? $quanteVoci : 1) as $t) {
        $tid = DB::table('tabelle')->insertGetId(['condominio_id' => $c->id, 'nome' => "Tabella {$t}", 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true, 'created_at' => $adesso, 'updated_at' => $adesso]);
        $quote = [];
        foreach ($unita as $i => $iid) {
            $quote[] = ['tabella_id' => $tid, 'immobile_id' => $iid, 'valore' => 1 + (($i * 7 + $t * 13) % 97) / 10, 'created_at' => $adesso, 'updated_at' => $adesso];
        }
        foreach (array_chunk($quote, 500) as $blocco) {
            DB::table('quote_tabella')->insert($blocco);
        }
        $tabelle[] = $tid;
    }

    $costi = DB::table('conti_contabili')->insertGetId(['condominio_id' => $c->id, 'ruolo' => null, 'codice' => 'CGM-COSTI', 'nome' => 'Costi', 'tipo' => 'costo', 'categoria' => 'costi',
        'created_at' => $adesso, 'updated_at' => $adesso]);
    $debiti = (int) (DB::table('conti_contabili')->where('condominio_id', $c->id)->where('ruolo', 'debiti_fornitori')->value('id')
        ?? DB::table('conti_contabili')->insertGetId(['condominio_id' => $c->id, 'ruolo' => 'debiti_fornitori', 'codice' => 'CGM-DEB', 'nome' => 'Debiti verso fornitori', 'tipo' => 'passivo',
            'categoria' => 'debiti', 'created_at' => $adesso, 'updated_at' => $adesso]));
    $voci = [];
    foreach (range(1, $quanteVoci) as $v) {
        $cid = cgmVoce($pc->id, "Voce {$v}", $tabelle[$tabellaPerVoce ? $v - 1 : 0], $adesso);
        cgmSpesa($c, $e, $g->id, $cid, 1234567 + $v * 98765, '2026-10-01', $costi, $debiti, null, $adesso);
        $voci[] = $cid;
    }

    if ($fatture > 0) {
        $gs = app(CondominioService::class)->createDefaultGestione($c, $e, 'straordinaria');
        $pcs = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $gs->id, 'nome' => 'PC straordinario']);
        $opera = cgmVoce($pcs->id, 'Facciata', $tabelle[0], $adesso);
        $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Impresa Facciate', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100,
            'perc_ritenuta' => 4, 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => $adesso, 'updated_at' => $adesso]);
        for ($f = 0; $f < $fatture; $f++) {
            $giorno = \Carbon\CarbonImmutable::parse('2026-02-01')->addDays($f * 3)->format('Y-m-d');
            cgmSpesa($c, $e, $gs->id, $opera, 100000 + $f * 101, $giorno, $costi, $debiti, ['fornitore' => $fornitore, 'competenza' => $giorno], $adesso);
        }
    }

    return ['c' => $c, 'e' => $e, 'g' => $g, 'persone' => count($ids), 'unita' => $unita, 'voci' => $voci];
}

function cgmVoce(int $pianoConto, string $nome, int $tabella, string $adesso): int
{
    $cid = DB::table('conti')->insertGetId(['piano_conto_id' => $pianoConto, 'nome' => $nome, 'tipo' => 'spesa', 'importo' => 0, 'created_at' => $adesso, 'updated_at' => $adesso]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $cid, 'tabella_id' => $tabella, 'coefficiente' => 100, 'created_at' => $adesso, 'updated_at' => $adesso]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => $adesso, 'updated_at' => $adesso]);

    return $cid;
}

/** Una spesa sul giornale: DARE costi con la voce, AVERE debiti; con `$fattura`, la fattura con la sua competenza scritta. */
function cgmSpesa(Condominio $c, Esercizio $e, int $gestione, int $voce, int $importo, string $giorno, int $costi, int $debiti, ?array $fattura, string $adesso): void
{
    static $n = 0;
    $n++;
    $sc = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $c->id, 'gestione_id' => $gestione, 'esercizio_id' => $e->id, 'data_registrazione' => $giorno,
        'data_competenza' => $giorno, 'numero_protocollo' => "CGM-{$n}", 'causale' => 'fattura', 'tipo_movimento' => 'fattura_acquisto', 'stato' => 'registrata',
        'created_at' => $adesso, 'updated_at' => $adesso]);
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $sc, 'conto_contabile_id' => $costi, 'voce_spesa_id' => $voce, 'tipo_riga' => 'dare', 'importo' => $importo, 'created_at' => $adesso, 'updated_at' => $adesso],
        ['scrittura_id' => $sc, 'conto_contabile_id' => $debiti, 'voce_spesa_id' => null, 'tipo_riga' => 'avere', 'importo' => $importo, 'created_at' => $adesso, 'updated_at' => $adesso],
    ]);
    if ($fattura !== null) {
        $fid = DB::table('fatture_passive')->insertGetId(['condominio_id' => $c->id, 'esercizio_id' => $e->id, 'fornitore_id' => $fattura['fornitore'], 'tipo_documento' => 'fattura',
            'numero_documento' => "CGM-F{$n}", 'data_documento' => $giorno, 'data_scadenza' => $giorno, 'importo_imponibile' => $importo, 'importo_iva' => 0, 'totale_documento' => $importo,
            'netto_a_pagare' => $importo, 'stato_approvazione' => 'approvata', 'competenza_dal' => $fattura['competenza'], 'competenza_al' => $fattura['competenza'],
            'created_at' => $adesso, 'updated_at' => $adesso]);
        DB::table('fattura_scrittura')->insert(['fattura_passiva_id' => $fid, 'scrittura_contabile_id' => $sc, 'importo_allocato' => $importo, 'tipo' => 'competenza',
            'created_at' => $adesso, 'updated_at' => $adesso]);
    }
}

/**
 * Una richiesta a una rotta del rendiconto, misurata: i secondi e i MB in più del picco del processo (il condominio di prova resta fuori).
 *
 * @return array{0: \Illuminate\Testing\TestResponse, 1: float, 2: float}
 */
function cgmMisura($test, string $rotta, array $s, string $etichetta): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $prima = memory_get_usage();
    $inizio = hrtime(true);
    $risposta = $test->actingAs($test->user)->get(route($rotta, [$s['c'], $s['e']]));
    $secondi = (hrtime(true) - $inizio) / 1e9;
    $mega = (memory_get_peak_usage() - $prima) / 1048576;
    fwrite(STDERR, sprintf("\n[misura] %s: %.2f s, %.1f MB in più\n", $etichetta, $secondi, $mega));

    return [$risposta, $secondi, $mega];
}
