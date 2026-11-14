<?php

/**
 * Via Verdi con le rate, gli incassi e il passaggio fatti dal programma: il caso su cui si provano le quote dei piani, la posizione e il
 * saldo del consuntivo per persona.
 *
 * Esercizio 2026 (365 giorni), gestione ordinaria, tabella «Generali»: U1 di Neri (500), U2 di Bianchi (300), U3 di Verdi (200), che ha
 * dall'anno prima un credito di € 50,00. Il preventivo: Pulizie € 3.000,00, Manutenzioni € 1.000,00, in quattro rate; il credito di Verdi
 * entra nel piano con il metodo scelto. Il piano nasce a gennaio, quando U2 è tutta di Bianchi; il passaggio a Rossi dal 01/07 si
 * registra dopo, dalla pagina, con la sua coppia sulle quote emesse a Bianchi (o con la rinuncia). Il consuntivo: Pulizie € 3.300,00,
 * Manutenzioni € 900,00, scritte sul giornale come le scrive una fattura.
 *
 * NON contiene nessun it() o test — solo funzioni di supporto.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Gestionale\Subentro;
use App\Services\Gestionale\ConsuntivoPerPersona;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/ConsuntivoPerPersonaScenario.php';
require_once __DIR__ . '/../Gestionale/LetturaDebitiProva.php';

/**
 * Il condominio, le unità, le persone, le voci, il saldo di Verdi e il piano generato (non ancora emesso).
 *
 * Opzioni: `metodo` del pregresso (prima_rata), `saldo_verdi` (−5000), `gia_versato` [[unità, voce, centesimi, persona|null]] registrato
 * prima del piano, `inquilino` [unità, nome, percentuale dell'inquilino sulle voci, dal, al|null], `riscaldamento` (una seconda ordinaria,
 * «Riscaldamento 2026», creata prima dell'ordinaria e senza voci).
 */
function cpcViaVerdi($test, array $opz = []): array
{
    static $seq = 0;
    $seq++;
    $test->travelTo('2026-01-10 10:00:00');
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    // Una seconda ordinaria creata prima di quella dell'anno: il riscaldamento, senza voci.
    $riscaldamento = null;
    if ($opz['riscaldamento'] ?? false) {
        $riscaldamento = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Riscaldamento 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
        legaAEsercizio($e, $riscaldamento->id);
    }
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);

    $banca = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    $crediti = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 100000]);

    $unita = [];
    foreach (['U1', 'U2', 'U3'] as $n) {
        $unita[$n] = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "CPC{$seq}-{$n}", 'nome' => $n, 'interno' => $n]);
    }
    $persone = [];
    $righe = [];
    foreach ([['Neri', 'U1'], ['Bianchi', 'U2'], ['Rossi', null], ['Verdi', 'U3']] as [$nome, $u]) {
        $persone[$nome] = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . "-cpc{$seq}@test.it", 'indirizzo' => 'Via Verdi 1',
            'codice_fiscale' => 'CPC' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $seq, 10, '0', STR_PAD_LEFT)]);
        $persone[$nome]->condomini()->syncWithoutDetaching([$c->id]);
        if ($u !== null) {
            $righe[$nome] = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $persone[$nome]->id, 'immobile_id' => $unita[$u]->id, 'tipologia' => 'proprietario', 'quota' => 100,
                'attivo' => true, 'data_inizio' => '2020-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $s = ['c' => $c, 'e' => $e, 'g' => $g, 'condominio' => $c, 'esercizio' => $e, 'gestione' => $g, 'pianoConto' => $pc, 'cassa' => $cassa, 'crediti' => $crediti,
        'unita' => $unita, 'immobili' => $unita, 'persone' => $persone, 'righe' => $righe, 'riscaldamento' => $riscaldamento];

    if (isset($opz['inquilino'])) {
        [$u, $nome, , $dal, $al] = $opz['inquilino'];
        $persone[$nome] = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . "-cpc{$seq}@test.it", 'indirizzo' => 'Via Verdi 1',
            'codice_fiscale' => 'CPC' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $seq, 10, '1', STR_PAD_LEFT)]);
        $persone[$nome]->condomini()->syncWithoutDetaching([$c->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$nome]->id, 'immobile_id' => $unita[$u]->id, 'tipologia' => 'inquilino', 'quota' => 100,
            'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);
        $s['persone'] = $persone;
    }

    $s['generali'] = cpmTabella($s, 'Generali', ['U1' => 500, 'U2' => 300, 'U3' => 200]);
    $s['pulizie'] = cpmVoce($s, 'Pulizie', [[$s['generali'], 100]], 'spesa', 300000);
    $s['manutenzioni'] = cpmVoce($s, 'Manutenzioni', [[$s['generali'], 100]], 'spesa', 100000);
    if (isset($opz['inquilino'])) {
        // La ripartizione per ruolo: la parte data all'inquilino, il resto al proprietario, su tutte e due le voci.
        $percentuale = (float) $opz['inquilino'][2];
        foreach (['pulizie', 'manutenzioni'] as $v) {
            $ctm = DB::table('conto_tabella_millesimale')->where('conto_id', $s[$v]->id)->value('id');
            DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $ctm)->update(['percentuale' => 100 - $percentuale]);
            DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'inquilino', 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $s['giornale'] = cpmGiornale($s);

    if (($opz['saldo_verdi'] ?? -5000) !== 0) {
        Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $persone['Verdi']->id, 'immobile_id' => $unita['U3']->id, 'gestione_id' => $g->id,
            'saldo_iniziale' => $opz['saldo_verdi'] ?? -5000, 'origine' => 'manuale', 'is_applicato' => false]);
    }
    foreach ($opz['gia_versato'] ?? [] as [$u, $voce, $importo, $chi]) {
        \App\Models\Gestionale\ContributoVersato::create(['condominio_id' => $c->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $s[$voce]->id,
            'immobile_id' => $unita[$u]->id, 'anagrafica_id' => $chi === null ? null : $persone[$chi]->id, 'importo_cents' => $importo, 'natura' => 'fondo_vincolato',
            'origine' => 'migrazione', 'esercizio_id' => $e->id]);
    }

    $s['piano'] = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => 4, 'giorno_scadenza' => 15, 'data_prima_scadenza' => '2026-01-15', 'metodo_distribuzione' => $opz['metodo'] ?? 'prima_rata', 'applica_saldi' => true,
    ]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $e);

    return $s;
}

/** Le spese del consuntivo sul giornale: Pulizie € 3.300,00, Manutenzioni € 900,00 (o le cifre date). */
function cpcSpese(array $s, int $pulizie = 330000, int $manutenzioni = 90000): void
{
    cpmSpesa($s, $s['giornale'], $s['pulizie'], $pulizie);
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], $manutenzioni);
}

/**
 * Una spesa per una sola unità, come la scrive la fattura: la riga di fattura con l'unità e senza voce, sul giornale DARE «Crediti verso
 * condomini» con l'unità e senza persona, AVERE i debiti verso i fornitori. Restituisce l'id della riga di fattura.
 */
function cpcSpesaPersonale(array $s, string $unita, int $importo, string $data, ?Esercizio $esercizio = null, ?string $competenzaDal = null): int
{
    static $n = 0;
    $n++;
    $esercizio ??= $s['e'];
    $fattura = DB::table('fatture_passive')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $esercizio->id, 'fornitore_id' => $s['giornale']['fornitore'],
        'tipo_documento' => 'fattura', 'numero_documento' => "CPC-P{$n}", 'data_documento' => $data, 'data_scadenza' => $data, 'importo_imponibile' => $importo, 'importo_iva' => 0,
        'totale_documento' => $importo, 'netto_a_pagare' => $importo, 'stato_approvazione' => 'approvata', 'competenza_dal' => $competenzaDal, 'competenza_al' => $competenzaDal,
        'created_at' => now(), 'updated_at' => now()]);
    $riga = DB::table('righe_fattura')->insertGetId(['fattura_passiva_id' => $fattura, 'conto_id' => null, 'immobile_id' => $s['unita'][$unita]->id, 'descrizione' => 'Citofono',
        'importo_imponibile' => $importo, 'aliquota_iva' => 0, 'importo_iva' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $scrittura = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $esercizio->id,
        'data_registrazione' => $data, 'data_competenza' => $data, 'numero_protocollo' => "CPC-P{$n}", 'causale' => 'fattura', 'tipo_movimento' => 'fattura_acquisto',
        'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $scrittura, 'conto_contabile_id' => $s['crediti']->id, 'voce_spesa_id' => null, 'immobile_id' => $s['unita'][$unita]->id, 'tipo_riga' => 'dare', 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $scrittura, 'conto_contabile_id' => $s['giornale']['debiti_fornitori'], 'voce_spesa_id' => null, 'immobile_id' => null, 'tipo_riga' => 'avere', 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('fattura_scrittura')->insert(['fattura_passiva_id' => $fattura, 'scrittura_contabile_id' => $scrittura, 'importo_allocato' => $importo, 'tipo' => 'competenza', 'created_at' => now(), 'updated_at' => now()]);

    return $riga;
}

/** Il passaggio di U2 da Bianchi a Rossi dal 01/07/2026, dalla pagina; `$extra` per la rinuncia. */
function cpcPassaggio($test, array $s, array $extra = []): Subentro
{
    $test->travelTo('2026-07-05 10:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U2']]), array_merge([
        'tipo' => 'vendita', 'riga_uscente_id' => $s['righe']['Bianchi'], 'anagrafica_entrante_id' => $s['persone']['Rossi']->id, 'decorrenza' => '2026-07-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-07-03',
        'estremi_titolo' => 'atto notaio Gialli, rep. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito del 01/07, letto il pannello',
    ], $extra))->assertSessionHasNoErrors()->assertRedirect();

    return Subentro::where('immobile_id', $s['unita']['U2']->id)->latest('id')->firstOrFail();
}

/**
 * Le rate e gli incassi del caso: le quattro rate emesse il 15/01; Neri versa € 1.700,00 su € 2.000,00 (le prime tre e € 200,00 della
 * quarta), Bianchi € 1.200,00, Verdi € 750,00. Con `$emesse` le sole rate da emettere il 15/01.
 */
function cpcRateEIncassi($test, array $s, array $emesse = [1, 2, 3, 4], array $versa = ['Neri' => [1 => null, 2 => null, 3 => null, 4 => 20000], 'Bianchi' => [1 => null, 2 => null, 3 => null, 4 => null], 'Verdi' => [1 => null, 2 => null, 3 => null, 4 => null]]): void
{
    $test->travelTo('2026-01-15 10:00:00');
    lcdEmetti($test, $s, $emesse, '2026-01-15');
    $test->travelTo('2026-03-01 10:00:00');
    foreach ($versa as $chi => $rate) {
        $righe = [];
        foreach ($rate as $n => $importo) {
            if (! in_array($n, $emesse, true)) {
                continue;
            }
            $quota = lcdQuota($s, $chi, $n);
            $importo ??= (int) DB::table('rate_quote')->where('id', $quota)->value('importo');
            if ($importo > 0) {
                $righe[$quota] = $importo;
            }
        }
        if ($righe !== []) {
            lcdIncassa($test, $s, $chi, $righe, '2026-03-01');
        }
    }
}

/** Il consuntivo per persona dell'esercizio, letto al 31/12 (il giorno dopo la fine, così l'estratto conto è quello del 31/12). */
function cpcLeggi($test, array $s, ?string $oggi = '2026-12-31 12:00:00'): array
{
    if ($oggi !== null) {
        $test->travelTo($oggi);
    }

    return (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
}

/** La riga di una persona su un'unità nella gestione del caso. */
function cpcDi(array $consuntivo, array $s, string $chi, string $unita): ?array
{
    return $consuntivo['persone'][$s['g']->id][$s['persone'][$chi]->id][$s['unita'][$unita]->id] ?? null;
}

/** L'esercizio 2025 del condominio, con la sua gestione «Ordinaria 2025». */
function cpcAnnoPrima(array $s): array
{
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $g = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Ordinaria 2025', 'tipo' => 'ordinaria', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31']);
    legaAEsercizio($e, $g->id);

    return [$e, $g];
}

/**
 * Una rata del 2025 di una persona, emessa il 01/10/2025 e non pagata, sulla gestione del 2025: piano, rata, quota, scrittura di emissione e
 * righe copiate da quelle vere della sua seconda rata del 2026, con la gestione e l'esercizio del 2025.
 */
function cpcRataNonPagata(array $s, Esercizio $e2025, Gestione $g2025, string $chi): void
{
    $p = (array) DB::table('piani_rate')->where('id', $s['piano']->id)->first();
    unset($p['id']);
    $pid = DB::table('piani_rate')->insertGetId(['gestione_id' => $g2025->id, 'esercizio_id' => $e2025->id, 'nome' => 'Preventivo 2025'] + $p);
    $r = (array) DB::table('rate')->where('id', lcdRata($s, 2))->first();
    unset($r['id']);
    $rid = DB::table('rate')->insertGetId(['piano_rate_id' => $pid, 'data_scadenza' => '2025-10-15'] + $r);
    $q = (array) DB::table('rate_quote')->where('id', lcdQuota($s, $chi, 2))->first();
    $em = (array) DB::table('scritture_contabili')->where('id', $q['scrittura_contabile_id'])->first();
    $dare = (array) DB::table('righe_scritture')->where('scrittura_id', $em['id'])->where('anagrafica_id', $s['persone'][$chi]->id)->first();
    unset($em['id'], $q['id'], $dare['id']);
    $eid = DB::table('scritture_contabili')->insertGetId(['gestione_id' => $g2025->id, 'esercizio_id' => $e2025->id, 'data_registrazione' => '2025-10-01',
        'data_competenza' => '2025-10-01', 'numero_protocollo' => 'CPC-2025'] + $em);
    DB::table('rate_quote')->insert(['rata_id' => $rid, 'scrittura_contabile_id' => $eid, 'importo_pagato' => 0, 'stato' => 'da_pagare'] + $q);
    DB::table('righe_scritture')->insert(['scrittura_id' => $eid, 'rata_id' => $rid] + $dare);
    DB::table('righe_scritture')->insert(['scrittura_id' => $eid, 'conto_contabile_id' => DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'gestione_rate')->value('id'),
        'tipo_riga' => 'avere', 'importo' => $dare['importo'], 'created_at' => now(), 'updated_at' => now()]);
}

/** Un saldo dell'esercizio 2026 scritto a mano, non applicato, con l'origine dichiarata nell'esercizio dato. */
function cpcSaldoDaAnnoPrima(array $s, Esercizio $origine, string $chi, string $unita, int $importo): void
{
    DB::table('saldi')->insert(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'anagrafica_id' => $s['persone'][$chi]->id,
        'immobile_id' => $s['unita'][$unita]->id, 'saldo_iniziale' => $importo, 'is_applicato' => false, 'origine' => 'manuale', 'origine_dichiarata' => 'esercizio',
        'esercizio_origine_id' => $origine->id, 'created_at' => now(), 'updated_at' => now()]);
}

